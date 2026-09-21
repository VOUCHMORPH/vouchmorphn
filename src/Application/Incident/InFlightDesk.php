<?php
declare(strict_types=1);

namespace Application\Incident;

use PDO;
use RuntimeException;

/**
 * In-flight transactions (VM-GOV-001 S6, S7, S8: "resolve every in-flight
 * transaction by its disposition").
 *
 *  Not yet completed (the money is still held at the source institution):
 *   - release it now: the institution returns the hold to the customer, and
 *     the leg's fees are reversed (the customer did not cause it).
 *     Cash-out code  -> SwapService::releaseCashoutHold()
 *     Identity swap  -> SwapService::cancelIdentitySwapNow()
 *     Card-pool hook -> CardService::releaseHook()
 *
 *  Already completed (the money is at the destination institution):
 *   - VouchMorph cannot take it back; only the destination institution can
 *     return it. VouchMorph raises a formal return request (the ISO 20022
 *     camt.056 recall pattern), sends the request letter, and records the
 *     institution's answer: RETURNED, PARTIAL or REFUSED.
 *
 * Who: Managing Director (Incident Commander) or Compliance Officer; every
 * action carries a reason and goes to the audit trail and, when linked, the
 * incident's timeline.
 */
final class InFlightDesk
{
    public const ACT_ROLES = [999, 4];

    public function __construct(private PDO $db) {}

    private function must(int $role): void
    {
        if (!in_array($role, self::ACT_ROLES, true)) throw new RuntimeException('Only the Managing Director or the Compliance Officer can act on in-flight transactions.');
    }

    private function audit(int $adminId, string $action, string $ref, array $d, string $sev = 'warning'): void
    {
        if (class_exists('\\Application\\Admin\\AdminAudit')) \Application\Admin\AdminAudit::recordOrLog($this->db, $adminId, $action, 'swap', $ref, $d, 'INCIDENT', $sev);
    }

    /** Everything still held at a source institution. */
    public function held(): array
    {
        return $this->db->query("
            SELECT h.hold_id, h.swap_reference, h.participant_name AS institution, h.amount, h.currency, h.status, h.created_at, h.hold_expiry,
                   ca.auth_id, (ca.status = 'PENDING') AS cashout_pending,
                   (SELECT 1 FROM identity_swap_holds i WHERE i.swap_reference = h.swap_reference AND i.status = 'pending' LIMIT 1) AS identity_pending
            FROM hold_transactions h
            LEFT JOIN cashout_authorizations ca ON ca.swap_reference = h.swap_reference
            WHERE UPPER(h.status) IN ('ACTIVE', 'PENDING_CASHOUT', 'PENDING_IDENTITY')
            ORDER BY h.created_at
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function hookedPools(): array
    {
        return $this->db->query("
            SELECT hook_reference, card_suffix, user_id, total_held_amount, currency, created_at, expires_at, (expires_at < NOW()) AS expired,
                   (SELECT string_agg(institution || ' P' || held_amount, ', ') FROM card_pool_hook_sources s WHERE s.hook_id = card_pool_hooks.id AND s.status = 'HELD') AS sources
            FROM card_pool_hooks WHERE status IN ('HOOKED', 'UNHOOK_PARTIAL') ORDER BY expires_at LIMIT 300
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Completed in the last 48 hours: the window in which a return can be requested (Section 23.5). */
    public function recallable(): array
    {
        return $this->db->query("
            SELECT s.swap_uuid AS swap_reference, s.amount, COALESCE(s.metadata->>'swap_type', '') AS swap_type,
                   COALESCE(s.metadata->>'source_institution', '') AS source_institution, sc.destination_institution, s.created_at,
                   (SELECT status FROM ic_return_requests r WHERE r.swap_reference = s.swap_uuid ORDER BY request_id DESC LIMIT 1) AS return_status
            FROM swap_requests s
            LEFT JOIN settlement_confirmations sc ON sc.swap_reference = s.swap_uuid
            WHERE LOWER(s.status) = 'completed' AND s.created_at > NOW() - INTERVAL '48 hours'
            ORDER BY s.created_at DESC LIMIT 300
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function returnRequests(): array
    {
        return $this->db->query("SELECT * FROM ic_return_requests ORDER BY (status IN ('REQUESTED','SENT')) DESC, requested_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Releases a held, not-yet-completed transaction now.
     * @param callable $services fn(): array{0: SwapService, 1: CardService}  (built only when needed)
     */
    public function releaseNow(string $kind, string $reference, string $reason, ?string $incidentId, int $adminId, int $role, callable $services): array
    {
        $this->must($role);
        if (mb_strlen(trim($reason)) < 10) throw new RuntimeException('Give the reason (at least 10 characters).');
        [$swapService, $cardService] = $services();
        switch ($kind) {
            case 'CASHOUT':
                $st = $this->db->prepare("SELECT auth_id FROM cashout_authorizations WHERE swap_reference = ? AND status = 'PENDING'");
                $st->execute([$reference]);
                $authId = $st->fetchColumn();
                if (!$authId) throw new RuntimeException("No pending cash-out for {$reference}.");
                $r = $swapService->releaseCashoutHold((int)$authId, 'Incident Command: ' . $reason);
                \Domain\Services\Fees\FeeLedger::fromCountryConfig($this->db)->reverse($reference, 'Released by Incident Command: ' . $reason);
                break;
            case 'IDENTITY':
                $r = $swapService->cancelIdentitySwapNow($reference, $reason);
                break;
            case 'CARD_HOOK':
                $st = $this->db->prepare("SELECT user_id FROM card_pool_hooks WHERE hook_reference = ? AND status = 'HOOKED'");
                $st->execute([$reference]);
                $owner = $st->fetchColumn();
                if ($owner === false) throw new RuntimeException("No active card hook {$reference}.");
                $r = $cardService->releaseHook($reference, (int)$owner, $swapService);
                break;
            default:
                throw new RuntimeException('Unknown kind.');
        }
        $this->audit($adminId, 'INFLIGHT_RELEASED', $reference, ['kind' => $kind, 'reason' => $reason, 'incident' => $incidentId, 'result' => $r['status'] ?? ($r['success'] ?? null)]);
        if ($incidentId) (new IncidentDesk($this->db))->log($incidentId, $adminId, "Released in-flight {$kind} {$reference}: {$reason}");
        return $r;
    }

    public function requestReturn(string $swapReference, string $reasonCode, string $reason, ?string $incidentId, int $adminId, int $role): int
    {
        $this->must($role);
        if (mb_strlen(trim($reason)) < 10) throw new RuntimeException('Give the reason (at least 10 characters).');
        $st = $this->db->prepare("
            SELECT s.swap_uuid, COALESCE(sc.amount, s.amount) AS amount, COALESCE(s.metadata->>'source_institution', '') AS src, sc.destination_institution AS dst,
                   COALESCE(s.metadata->>'destination_identifier', s.destination_details->>'identifier', '') AS dst_account
            FROM swap_requests s LEFT JOIN settlement_confirmations sc ON sc.swap_reference = s.swap_uuid
            WHERE s.swap_uuid = ? AND LOWER(s.status) = 'completed'
        ");
        $st->execute([$swapReference]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new RuntimeException("{$swapReference} is not a completed swap.");
        if (!$s['dst']) throw new RuntimeException('The destination institution of this swap is not recorded; raise the request manually.');
        $open = $this->db->prepare("SELECT request_id FROM ic_return_requests WHERE swap_reference = ? AND status IN ('REQUESTED', 'SENT')");
        $open->execute([$swapReference]);
        if ($existing = $open->fetchColumn()) throw new RuntimeException("Return request RTN-{$existing} is already open for this swap; record its outcome instead.");
        $ins = $this->db->prepare("
            INSERT INTO ic_return_requests (swap_reference, destination_institution, destination_account, source_institution, amount, reason_code, reason, incident_id, requested_by, respond_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL '48 hours') RETURNING request_id
        ");
        $ins->execute([$swapReference, $s['dst'], $s['dst_account'] ?: null, $s['src'] ?: null, $s['amount'], $reasonCode, $reason, $incidentId ?: null, $adminId]);
        $id = (int)$ins->fetchColumn();
        $this->audit($adminId, 'RETURN_REQUESTED', $swapReference, ['request_id' => $id, 'destination' => $s['dst'], 'amount' => $s['amount'], 'reason_code' => $reasonCode, 'reason' => $reason]);
        $desk = new IncidentDesk($this->db);
        if ($incidentId) $desk->log($incidentId, $adminId, "Return requested from {$s['dst']} for {$swapReference} (P{$s['amount']}, {$reasonCode})");
        $desk->notify(['OPERATIONS_MANAGER', 'COMPLIANCE_OFFICER'], "Return request {$id}: {$swapReference} from {$s['dst']}",
            "A return of P{$s['amount']} was requested from {$s['dst']} for swap {$swapReference} ({$reasonCode}: {$reason}).\nSend the request letter to {$s['dst']}'s settlement team and record their answer within 48 hours in Incident Command > In-flight.", null, $incidentId ?: null);
        return $id;
    }

    public function recordReturnOutcome(int $requestId, string $status, ?float $returned, string $bankReference, string $response, int $adminId, int $role): void
    {
        $this->must($role);
        if (!in_array($status, ['SENT', 'RETURNED', 'PARTIAL', 'REFUSED', 'WITHDRAWN'], true)) throw new RuntimeException('Unknown outcome.');
        $st = $this->db->prepare("SELECT * FROM ic_return_requests WHERE request_id = ?");
        $st->execute([$requestId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new RuntimeException('Request not found.');
        $closing = in_array($status, ['RETURNED', 'PARTIAL', 'REFUSED', 'WITHDRAWN'], true);
        $this->db->prepare("
            UPDATE ic_return_requests SET status = ?, returned_amount = COALESCE(?, returned_amount), bank_reference = COALESCE(NULLIF(?, ''), bank_reference),
                   bank_response = COALESCE(NULLIF(?, ''), bank_response), sent_at = CASE WHEN ? = 'SENT' THEN NOW() ELSE sent_at END,
                   closed_by = CASE WHEN ? THEN ? ELSE closed_by END, closed_at = CASE WHEN ? THEN NOW() ELSE closed_at END
            WHERE request_id = ?
        ")->execute([$status, $returned, $bankReference, $response, $status, $closing ? 1 : 0, $adminId, $closing ? 1 : 0, $requestId]);
        if ($status === 'RETURNED') {
            // Value came back: the customer is not charged for this swap (Section 23.4).
            \Domain\Services\Fees\FeeLedger::fromCountryConfig($this->db)->reverse($r['swap_reference'], "Returned by {$r['destination_institution']} on request {$requestId}");
        }
        $this->audit($adminId, 'RETURN_' . $status, $r['swap_reference'], ['request_id' => $requestId, 'returned' => $returned, 'bank_reference' => $bankReference]);
        if ($r['incident_id']) (new IncidentDesk($this->db))->log($r['incident_id'], $adminId, "Return request {$requestId} for {$r['swap_reference']}: {$status}" . ($returned !== null ? " (P{$returned})" : ''));
    }

    /** The request letter to the destination institution. */
    public function letter(int $requestId): string
    {
        $st = $this->db->prepare("SELECT * FROM ic_return_requests WHERE request_id = ?");
        $st->execute([$requestId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new RuntimeException('Request not found.');
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $bw = fn($t) => (new \DateTimeImmutable((string)$t, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Gaborone'))->format('d M Y, H:i');
        $reasons = ['SYSTEM_ERROR' => 'Processing error on the VouchMorph platform', 'DUPLICATE' => 'Duplicate transaction',
                    'FRAUD' => 'Verified fraud', 'CUSTOMER_ERROR' => 'Misdirected payment reported by the customer within 48 hours', 'SANCTIONS' => 'Sanctions match'];
        $body = '<p>To: Settlement / Operations, <b>' . $h($r['destination_institution']) . '</b></p>'
            . '<p>VouchMorph requests the return of the payment below, credited by your institution through a VouchMorph swap. '
            . 'Please return the funds through the settlement process quoting reference <b>RTN-' . (int)$r['request_id'] . '</b>, or tell us why you cannot, by '
            . $h($bw($r['respond_by'])) . '.</p>'
            . '<table><tr><td class="k">Swap reference</td><td>' . $h($r['swap_reference']) . '</td></tr>'
            . '<tr><td class="k">Amount credited</td><td>' . $h($r['currency']) . ' ' . number_format((float)$r['amount'], 2) . '</td></tr>'
            . '<tr><td class="k">Credited account</td><td>' . $h($r['destination_account'] ?: 'as per your records for this reference') . '</td></tr>'
            . '<tr><td class="k">Sending institution</td><td>' . $h($r['source_institution']) . '</td></tr>'
            . '<tr><td class="k">Reason</td><td>' . $h($reasons[$r['reason_code']] ?? $r['reason_code']) . ': ' . $h($r['reason']) . '</td></tr>'
            . '<tr><td class="k">Requested</td><td>' . $h($bw($r['requested_at'])) . '</td></tr></table>'
            . '<p class="muted">If the funds have already been withdrawn by the account holder, please tell us so we can pursue recovery through the sending institution.</p>'
            . '<h2>Authorised by</h2><table class="sig"><tr><td class="k">Name and role</td><td></td></tr><tr><td class="k">Date</td><td></td></tr></table>';
        return ReportBuilder::shell('Request for return of funds', 'RTN-' . (int)$r['request_id'], $body);
    }
}
