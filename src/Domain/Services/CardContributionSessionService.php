<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\ContributionCalculator;
use Domain\Services\MultiSource\PoolCoordinator;

/**
 * Owns the "owner decides, hookers follow" contribution flow for a
 * VouchMorph Card.
 *
 * Lifecycle:
 *   1. Card owner creates a session against their card's active hook,
 *      naming a destination and target amount, and picks a strategy
 *      (EQUAL / RATIO / SMART / MANUAL). Session status = OPEN.
 *   2. Every contributor currently hooked to that hook can read the
 *      live preview. Under MANUAL, each contributor can set/update
 *      ONLY their own entry.
 *   3. On every change, the preview is recomputed server-side. The
 *      moment total contributions cover the target (within tolerance),
 *      status flips to READY automatically — nobody has to "agree",
 *      the numbers either add up or they don't.
 *   4. Only the card owner can execute a READY session. Execution
 *      hands the resolved contributions straight to
 *      PoolCoordinator::executeFromCardHook(), which debits the
 *      ALREADY-HELD hook sources directly — no new holds are placed.
 *
 * Authorization model:
 *   - "Owner" actions (create, change strategy, execute, cancel):
 *     must be card_pool_hooks.user_id for the session's hook_reference.
 *   - "Contributor" actions (view preview, set own manual amount):
 *     must own (owner_user_id) one of the HELD rows in
 *     card_pool_hook_sources for that hook.
 */
class CardContributionSessionService
{
    private PDO $db;
    private ContributionCalculator $contributionCalculator;
    private float $defaultMinContribution;
    private float $coverageToleranceAbs;
    private int $defaultSessionTtlSeconds;
    private $logger;

    public function __construct(
        PDO $db,
        ContributionCalculator $contributionCalculator,
        float $defaultMinContribution = 10.0,
        float $coverageToleranceAbs = 0.01,
        int $defaultSessionTtlSeconds = 1800,
        $logger = null
    ) {
        $this->db = $db;
        $this->contributionCalculator = $contributionCalculator;
        $this->defaultMinContribution = $defaultMinContribution;
        $this->coverageToleranceAbs = $coverageToleranceAbs;
        $this->defaultSessionTtlSeconds = $defaultSessionTtlSeconds;
        $this->logger = $logger ?? new class {
            public function info($m, array $c = []) { error_log("[CCS] INFO: {$m} " . json_encode($c)); }
            public function warning($m, array $c = []) { error_log("[CCS] WARNING: {$m} " . json_encode($c)); }
            public function error($m, array $c = []) { error_log("[CCS] ERROR: {$m} " . json_encode($c)); }
        };
    }

    // ============================================================
    // CREATE / CONFIGURE (owner only)
    // ============================================================

    public function createSession(
        string $cardSuffix,
        int $ownerUserId,
        array $destinationPayload,
        float $targetAmount,
        string $currency,
        string $strategy,
        ?float $minContributionAmount = null,
        ?int $ttlSeconds = null
    ): array {
        if ($targetAmount <= 0) {
            throw new RuntimeException("target_amount must be greater than 0");
        }
        $this->assertValidStrategy($strategy);

        $hook = $this->getActiveHookOrThrow($cardSuffix);
        $this->assertIsHookOwner($hook, $ownerUserId);

        // Only one OPEN/READY session per hook at a time - a card owner
        // must resolve (execute or cancel) the current one before
        // starting another, so contributors are never looking at two
        // simultaneous, conflicting targets for the same hook.
        $existing = $this->getActiveSessionForHook($hook['hook_reference']);
        if ($existing) {
            throw new RuntimeException(
                "This card already has an active contribution session (ref: {$existing['session_reference']}, status: {$existing['status']}). " .
                "Cancel or execute it first."
            );
        }

        $sessionRef = 'CCS_' . bin2hex(random_bytes(8));
        $ttl = $ttlSeconds ?? $this->defaultSessionTtlSeconds;
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
        $floor = $minContributionAmount ?? $this->defaultMinContribution;

        $stmt = $this->db->prepare("
            INSERT INTO card_contribution_sessions (
                session_reference, card_suffix, hook_reference, initiated_by_user_id,
                destination_payload, target_amount, currency, strategy,
                min_contribution_amount, status, created_at, updated_at, expires_at
            ) VALUES (
                :ref, :card_suffix, :hook_ref, :owner_id,
                :dest::jsonb, :target, :currency, :strategy,
                :floor, 'OPEN', NOW(), NOW(), :expires_at
            ) RETURNING id
        ");
        $stmt->execute([
            ':ref' => $sessionRef,
            ':card_suffix' => $cardSuffix,
            ':hook_ref' => $hook['hook_reference'],
            ':owner_id' => $ownerUserId,
            ':dest' => json_encode($destinationPayload),
            ':target' => $targetAmount,
            ':currency' => $currency,
            ':strategy' => $strategy,
            ':floor' => $floor,
            ':expires_at' => $expiresAt,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $sessionId = $row ? (int)$row['id'] : 0;

        $this->logger->info('Contribution session created', [
            'session_id' => $sessionId, 'session_ref' => $sessionRef,
            'card_suffix' => $cardSuffix, 'strategy' => $strategy, 'target' => $targetAmount,
        ]);

        $this->recomputePreview($sessionId);

        return $this->getStatus($sessionId, $ownerUserId);
    }

    /**
     * Owner changes strategy (and/or target amount) mid-session. Any
     * previously-entered MANUAL amounts are kept on record but only
     * matter again if the owner switches back to MANUAL.
     */
    public function setStrategy(int $sessionId, int $ownerUserId, string $strategy, ?float $targetAmount = null): array
    {
        $this->assertValidStrategy($strategy);
        $session = $this->getSessionOrThrow($sessionId);
        $this->assertOwnerOfSession($session, $ownerUserId);
        $this->assertSessionOpen($session);

        $params = [':id' => $sessionId, ':strategy' => $strategy];
        $sql = "UPDATE card_contribution_sessions SET strategy = :strategy, updated_at = NOW()";
        if ($targetAmount !== null) {
            if ($targetAmount <= 0) {
                throw new RuntimeException("target_amount must be greater than 0");
            }
            $sql .= ", target_amount = :target";
            $params[':target'] = $targetAmount;
        }
        $sql .= " WHERE id = :id";
        $this->db->prepare($sql)->execute($params);

        $this->recomputePreview($sessionId);
        return $this->getStatus($sessionId, $ownerUserId);
    }

    public function cancel(int $sessionId, int $actorUserId, string $reason): array
    {
        $session = $this->getSessionOrThrow($sessionId);
        $this->assertOwnerOfSession($session, $actorUserId);

        if (in_array($session['status'], ['COMPLETED', 'CANCELLED', 'FAILED'], true)) {
            return $this->rowToStatusArray($session);
        }

        $stmt = $this->db->prepare("
            UPDATE card_contribution_sessions
            SET status = 'CANCELLED', failure_reason = :reason, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':reason' => $reason, ':id' => $sessionId]);

        return $this->getStatus($sessionId, $actorUserId);
    }

    // ============================================================
    // CONTRIBUTOR ACTIONS
    // ============================================================

    /**
     * A hooked contributor sets/updates their OWN manual amount. Only
     * meaningful (and only accepted) while strategy = MANUAL — under
     * any other strategy the amount is computed automatically and this
     * throws, so a stray client can't silently corrupt an EQUAL/RATIO
     * split.
     */
    public function setManualAmount(int $sessionId, int $contributorUserId, float $amount): array
    {
        $session = $this->getSessionOrThrow($sessionId);
        $this->assertSessionOpen($session);

        if ($session['strategy'] !== 'MANUAL') {
            throw new RuntimeException("This session is using {$session['strategy']} — amounts are computed automatically, not entered manually.");
        }
        if ($amount < 0) {
            throw new RuntimeException("Amount cannot be negative");
        }

        $hookSource = $this->getHookSourceForContributor($session['hook_reference'], $contributorUserId);
        if (!$hookSource) {
            throw new RuntimeException("You don't have a currently-held source on this card's hook.");
        }

        if ($amount > 0 && $amount < (float)$session['min_contribution_amount']) {
            throw new RuntimeException(
                "Minimum contribution is " . number_format((float)$session['min_contribution_amount'], 2) . " {$session['currency']}. " .
                "Enter 0 to contribute nothing instead."
            );
        }
        if ($amount > (float)$hookSource['held_amount']) {
            throw new RuntimeException(
                "Amount exceeds your held balance of " . number_format((float)$hookSource['held_amount'], 2) . " {$session['currency']}."
            );
        }

        $stmt = $this->db->prepare("
            INSERT INTO card_contribution_entries (session_id, hook_source_id, contributor_user_id, manual_amount, updated_at)
            VALUES (:sid, :hsid, :uid, :amount, NOW())
            ON CONFLICT (session_id, hook_source_id)
            DO UPDATE SET manual_amount = EXCLUDED.manual_amount, updated_at = NOW()
        ");
        $stmt->execute([
            ':sid' => $sessionId,
            ':hsid' => $hookSource['id'],
            ':uid' => $contributorUserId,
            ':amount' => $amount,
        ]);

        $this->recomputePreview($sessionId);
        return $this->getStatus($sessionId, $contributorUserId);
    }

    // ============================================================
    // READ (owner or any current contributor)
    // ============================================================

    public function getStatus(int $sessionId, int $requestingUserId): array
    {
        $session = $this->getSessionOrThrow($sessionId);
        $this->assertOwnerOrContributor($session, $requestingUserId);
        return $this->rowToStatusArray($session);
    }

    // ============================================================
    // EXECUTE (owner only, session must be READY)
    // ============================================================

    public function execute(int $sessionId, int $ownerUserId, PoolCoordinator $poolCoordinator): array
    {
        $session = $this->getSessionOrThrow($sessionId);
        $this->assertOwnerOfSession($session, $ownerUserId);

        // Recompute one last time immediately before executing, so a
        // stale READY (e.g. a contributor's hold expired seconds ago)
        // can't slip through.
        $this->recomputePreview($sessionId);
        $session = $this->getSessionOrThrow($sessionId);

        if ($session['status'] !== 'READY') {
            throw new RuntimeException("Session is not ready to execute (status: {$session['status']}). Contributions must cover the full target amount first.");
        }

        $this->db->prepare("UPDATE card_contribution_sessions SET status = 'EXECUTING', updated_at = NOW() WHERE id = :id")
            ->execute([':id' => $sessionId]);

        $preview = json_decode($session['contributions_preview'], true) ?? [];
        $destination = json_decode($session['destination_payload'], true) ?? [];

        // NOTE: use _full_source_identifier here, NOT source_identifier —
        // the latter is the MASKED value meant for dashboard display.
        // $session['contributions_preview'] was read directly from the
        // DB row above (not through getStatus()/rowToStatusArray(),
        // which strips this field), so the unmasked value is present.
        $preHeldSources = array_map(function ($c) {
            return [
                'institution' => $c['institution'],
                'asset_type' => $c['asset_type'],
                'source_identifier' => $c['_full_source_identifier'] ?? $c['source_identifier'],
                'source_identifier_type' => $c['source_identifier_type'] ?? 'auto',
                'amount' => $c['amount'],
                'hold_reference' => $c['hold_reference'],
            ];
        }, array_filter($preview['contributors'] ?? [], fn($c) => ($c['amount'] ?? 0) > 0));

        $payload = array_merge($destination, [
            // FIX: $session comes straight from a PDO fetch of a numeric
            // DB column, which PHP's pgsql driver returns as a string —
            // PoolCoordinator/MultiSourceFeeCalculator declare this as a
            // strict `float` parameter downstream, so an uncast string
            // here threw a TypeError deep inside pool execution.
            'amount' => (float)$session['target_amount'],
            'currency' => $session['currency'],
            'reference' => $session['session_reference'],
        ]);

        try {
            $result = $poolCoordinator->executeFromCardHook($payload, $preHeldSources);

            $stmt = $this->db->prepare("
                UPDATE card_contribution_sessions
                SET status = 'COMPLETED', swap_reference = :swap_ref, pool_id = :pool_id,
                    executed_at = NOW(), updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':swap_ref' => $result['reference'] ?? null,
                ':pool_id' => $result['pool_id'] ?? null,
                ':id' => $sessionId,
            ]);

            // ============================================================
            // FIX: mark the card's OWN hook bookkeeping as spent.
            //
            // executeFromCardHook() genuinely debits each source and
            // credits the destination — both fail closed, a real bank
            // failure throws before this point — but it only updates
            // PoolCoordinator's own pool/contribution tables; it has no
            // knowledge of card_pool_hooks/card_pool_hook_sources at
            // all. Every dashboard read (My.php, GetCardSources.php)
            // filters those tables for status='HOOKED'/'HELD', so a
            // completed swap kept showing every contributor at their
            // full original held_amount as if nothing had been spent —
            // and the same already-spent funds stayed eligible to be
            // pulled into a brand-new swap. Reuses the exact
            // 'DEBITED'/'SETTLED' convention CardService::
            // finalizePooledSwipe() already established for card
            // swipes, so every existing 'HELD'/'HOOKED' filter
            // elsewhere excludes these rows correctly with no other
            // changes needed.
            //
            // The money already genuinely moved and the session is
            // already COMPLETED above; this is bookkeeping cleanup on
            // the card's own side, not the transaction itself, so a
            // failure here is logged rather than turned into a false
            // "swap failed" for the caller.
            // ============================================================
            try {
                $spentContributors = array_filter($preview['contributors'] ?? [], fn($c) => ($c['amount'] ?? 0) > 0);
                if (!empty($spentContributors)) {
                    $hookStmt = $this->db->prepare("SELECT id FROM card_pool_hooks WHERE hook_reference = :ref");
                    $hookStmt->execute([':ref' => $session['hook_reference']]);
                    $hookId = $hookStmt->fetchColumn();

                    if ($hookId) {
                        foreach ($spentContributors as $c) {
                            $this->db->prepare("
                                UPDATE card_pool_hook_sources
                                SET status = 'DEBITED', debited_amount = :amount, debit_reference = :ref
                                WHERE id = :id AND status = 'HELD'
                            ")->execute([
                                ':amount' => $c['amount'],
                                ':ref' => $result['reference'] ?? $session['session_reference'],
                                ':id' => $c['hook_source_id'],
                            ]);
                        }

                        // Recompute straight from whatever HELD sources
                        // remain (a partial swap leaves some hooked
                        // sources untouched) — self-correcting, same
                        // pattern releaseHookSource() already uses.
                        $remainingStmt = $this->db->prepare("
                            SELECT COALESCE(SUM(held_amount), 0) AS total, COUNT(*) AS cnt
                            FROM card_pool_hook_sources WHERE hook_id = :hook_id AND status = 'HELD'
                        ");
                        $remainingStmt->execute([':hook_id' => $hookId]);
                        $remaining = $remainingStmt->fetch(PDO::FETCH_ASSOC);

                        if ((int)$remaining['cnt'] > 0) {
                            $this->db->prepare("
                                UPDATE card_pool_hooks SET total_held_amount = :total WHERE id = :hook_id
                            ")->execute([':total' => round((float)$remaining['total'], 2), ':hook_id' => $hookId]);
                        } else {
                            $this->db->prepare("
                                UPDATE card_pool_hooks
                                SET status = 'SETTLED', total_held_amount = 0, settlement_reference = :ref, finalized_at = NOW()
                                WHERE id = :hook_id
                            ")->execute([':ref' => $result['reference'] ?? $session['session_reference'], ':hook_id' => $hookId]);
                        }
                    }
                }
            } catch (\Throwable $bookkeepingErr) {
                error_log("[CardContributionSessionService] execute(): session {$sessionId} completed but card hook bookkeeping update failed: " . $bookkeepingErr->getMessage());
            }

            $this->logger->info('Contribution session executed successfully', [
                'session_id' => $sessionId, 'pool_id' => $result['pool_id'] ?? null,
            ]);

            return array_merge($this->getStatus($sessionId, $ownerUserId), ['execution_result' => $result]);

        } catch (\Throwable $e) {
            // FIX: was `catch (Exception $e)` — a PHP engine error
            // (TypeError, etc.) is a \Throwable but NOT an \Exception, so
            // it fell through this catch entirely, left the session
            // stuck at EXECUTING forever (never marked FAILED), and
            // crashed the whole request before it could return JSON —
            // exactly what surfaced to users as a generic "hiccup"
            // instead of a real, readable error.
            $stmt = $this->db->prepare("
                UPDATE card_contribution_sessions
                SET status = 'FAILED', failure_reason = :reason, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':reason' => $e->getMessage(), ':id' => $sessionId]);

            $this->logger->error('Contribution session execution failed', [
                'session_id' => $sessionId, 'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // ============================================================
    // MAINTENANCE
    // ============================================================

    public function expireStaleSessions(): array
    {
        $stmt = $this->db->prepare("
            UPDATE card_contribution_sessions
            SET status = 'EXPIRED', updated_at = NOW()
            WHERE status IN ('OPEN', 'READY') AND expires_at < NOW()
            RETURNING id, session_reference
        ");
        $stmt->execute();
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($expired)) {
            $this->logger->info('Expired stale contribution sessions', ['count' => count($expired)]);
        }
        return $expired;
    }

    // ============================================================
    // INTERNAL: preview computation
    // ============================================================

    /**
     * Recomputes contributions_preview from the current strategy +
     * currently-held hook sources (+ manual entries, if MANUAL), and
     * flips status OPEN <-> READY based on coverage. Never touches
     * status if it's not currently OPEN or READY (i.e. leaves
     * EXECUTING/COMPLETED/CANCELLED/EXPIRED/FAILED alone).
     */
    private function recomputePreview(int $sessionId): void
    {
        $session = $this->getSessionOrThrow($sessionId);
        if (!in_array($session['status'], ['OPEN', 'READY'], true)) {
            return;
        }

        $hookSources = $this->getHeldSourcesForHook($session['hook_reference']);
        $target = (float)$session['target_amount'];
        $floor = (float)$session['min_contribution_amount'];
        $strategy = $session['strategy'];

        if (empty($hookSources)) {
            $this->savePreview($sessionId, [
                'total_target' => $target,
                'total_covered' => 0.0,
                'remaining' => $target,
                'contributors' => [],
            ], 'OPEN');
            return;
        }

        $sourcesWithBalances = array_map(fn($s) => [
            'institution' => $s['institution'],
            'asset_type' => $s['asset_type'],
            'identifier' => $s['source_identifier'],
            'available_balance' => (float)$s['held_amount'],
        ], $hookSources);

        $userAmounts = null;
        if ($strategy === 'MANUAL') {
            $entries = $this->getManualEntries($sessionId);
            $userAmounts = [];
            foreach ($hookSources as $s) {
                // FIX: composite key, matching what
                // ContributionCalculator::calculateUserSpecifiedFlexible()
                // looks up first. Keying by institution alone collided
                // whenever a card has more than one hooked source from
                // the same institution (e.g. two accounts at the same
                // bank), silently dropping all but the last one's entry.
                $key = $s['institution'] . '|' . $s['source_identifier'];
                $userAmounts[$key] = (float)($entries[$s['id']] ?? 0);
            }
        }

        $failureReason = null;
        try {
            $computed = $this->contributionCalculator->calculateContributions(
                $target,
                $sourcesWithBalances,
                $strategy,
                $userAmounts,
                null
            );
        } catch (Exception $e) {
            // Calculator can legitimately throw (e.g. available balances
            // don't cover the target under SMART/RATIO) — treat as "not
            // covered yet" rather than propagating, so the dashboard can
            // show a clear remaining-amount instead of an error screen.
            // The real reason is kept (via failure_reason below) instead
            // of being swallowed, so a genuine shortfall is never
            // indistinguishable from the matching bug this replaces.
            $computed = array_map(fn($s) => ['source' => $s, 'actual_amount' => 0.0], $sourcesWithBalances);
            $failureReason = $e->getMessage();
        }

        $contributors = [];
        $totalCovered = 0.0;

        foreach ($hookSources as $s) {
            $match = null;
            foreach ($computed as $c) {
                // FIX: calculateContributions() nests the originating
                // source one level down as $c['source'] — it never
                // returns a top-level 'institution' key, so this always
                // missed before. Matching on institution alone would
                // also be wrong by itself: a card can have more than one
                // hooked source from the same institution (institution +
                // identifier together is what's unique).
                $cSource = $c['source'] ?? [];
                if (($cSource['institution'] ?? null) === $s['institution']
                    && ($cSource['identifier'] ?? null) === $s['source_identifier']) {
                    $match = $c;
                    break;
                }
            }
            $amount = round((float)($match['actual_amount'] ?? $match['amount'] ?? 0), 2);
            $belowMinimum = $amount > 0 && $amount < $floor;
            if ($belowMinimum) {
                // Same rule PoolCoordinator::execute() applies to
                // normally-discovered contributions — a sub-floor
                // amount contributes nothing rather than being sent
                // as a doomed micro-transfer.
                $amount = 0.0;
            }

            $contributors[] = [
                'hook_source_id' => (int)$s['id'],
                'contributor_user_id' => (int)$s['owner_user_id'],
                'institution' => $s['institution'],
                'asset_type' => $s['asset_type'],
                'source_identifier' => $this->maskIdentifier($s['source_identifier']),
                'held_amount' => (float)$s['held_amount'],
                'amount' => $amount,
                'below_minimum' => $belowMinimum,
                'hold_reference' => $s['hold_reference'],
                // internal fields the UI should NOT render but execute()
                // needs — kept out of any response that leaves this class
                // unfiltered; see maskPreviewForResponse().
                '_full_source_identifier' => $s['source_identifier'],
            ];
            $totalCovered += $amount;
        }

        $totalCovered = round($totalCovered, 2);
        $remaining = round($target - $totalCovered, 2);
        $status = ($remaining <= $this->coverageToleranceAbs) ? 'READY' : 'OPEN';

        $this->savePreview($sessionId, [
            'total_target' => $target,
            'total_covered' => $totalCovered,
            'remaining' => max(0, $remaining),
            'contributors' => $contributors,
        ], $status, $failureReason);
    }

    private function savePreview(int $sessionId, array $preview, string $status, ?string $failureReason = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE card_contribution_sessions
            SET contributions_preview = :preview::jsonb, status = :status, failure_reason = :reason, updated_at = NOW()
            WHERE id = :id AND status IN ('OPEN', 'READY')
        ");
        $stmt->execute([
            ':preview' => json_encode($preview),
            ':status' => $status,
            ':reason' => $failureReason,
            ':id' => $sessionId,
        ]);
    }

    private function getManualEntries(int $sessionId): array
    {
        $stmt = $this->db->prepare("SELECT hook_source_id, manual_amount FROM card_contribution_entries WHERE session_id = :sid");
        $stmt->execute([':sid' => $sessionId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['hook_source_id']] = (float)$row['manual_amount'];
        }
        return $out;
    }

    // ============================================================
    // INTERNAL: lookups & guards
    // ============================================================

    private function getActiveHookOrThrow(string $cardSuffix): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM card_pool_hooks
            WHERE card_suffix = :suffix AND status = 'HOOKED' AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':suffix' => $cardSuffix]);
        $hook = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hook) {
            throw new RuntimeException("No active hook for this card — hook at least one source first.");
        }
        return $hook;
    }

    private function getActiveSessionForHook(string $hookReference): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM card_contribution_sessions
            WHERE hook_reference = :ref AND status IN ('OPEN', 'READY', 'EXECUTING')
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':ref' => $hookReference]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getHeldSourcesForHook(string $hookReference): array
    {
        $stmt = $this->db->prepare("
            SELECT s.* FROM card_pool_hook_sources s
            JOIN card_pool_hooks h ON h.id = s.hook_id
            WHERE h.hook_reference = :ref AND h.status = 'HOOKED' AND h.expires_at > NOW()
            AND s.status = 'HELD'
            ORDER BY s.id
        ");
        $stmt->execute([':ref' => $hookReference]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getHookSourceForContributor(string $hookReference, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.* FROM card_pool_hook_sources s
            JOIN card_pool_hooks h ON h.id = s.hook_id
            WHERE h.hook_reference = :ref AND h.status = 'HOOKED' AND h.expires_at > NOW()
            AND s.status = 'HELD' AND s.owner_user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':ref' => $hookReference, ':uid' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getSessionOrThrow(int $sessionId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM card_contribution_sessions WHERE id = :id");
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new RuntimeException("Contribution session not found: {$sessionId}");
        }
        return $session;
    }

    private function assertIsHookOwner(array $hook, int $userId): void
    {
        if ((int)$hook['user_id'] !== $userId) {
            throw new RuntimeException("Only the card owner can do this.");
        }
    }

    private function assertOwnerOfSession(array $session, int $userId): void
    {
        if ((int)$session['initiated_by_user_id'] !== $userId) {
            throw new RuntimeException("Only the card owner can do this.");
        }
    }

    private function assertOwnerOrContributor(array $session, int $userId): void
    {
        if ((int)$session['initiated_by_user_id'] === $userId) {
            return;
        }
        $contributor = $this->getHookSourceForContributor($session['hook_reference'], $userId);
        if (!$contributor) {
            throw new RuntimeException("You're not the card owner or a currently-hooked contributor on this session.");
        }
    }

    private function assertSessionOpen(array $session): void
    {
        if (!in_array($session['status'], ['OPEN', 'READY'], true)) {
            throw new RuntimeException("Session is no longer open (status: {$session['status']}).");
        }
    }

    private function assertValidStrategy(string $strategy): void
    {
        if (!in_array($strategy, ['EQUAL', 'RATIO', 'SMART', 'MANUAL'], true)) {
            throw new RuntimeException("Invalid strategy: {$strategy}. Must be EQUAL, RATIO, SMART, or MANUAL.");
        }
    }

    private function maskIdentifier(?string $value): string
    {
        if (!$value) return '';
        $str = (string)$value;
        if (str_contains($str, '@')) {
            [$local, $domain] = explode('@', $str, 2);
            return substr($local, 0, 2) . '•••@' . $domain;
        }
        if (strlen($str) <= 4) return str_repeat('•', strlen($str));
        return substr($str, 0, 3) . str_repeat('•', max(0, strlen($str) - 6)) . substr($str, -3);
    }

    private function rowToStatusArray(array $session): array
    {
        $preview = json_decode($session['contributions_preview'] ?? 'null', true);

        // Strip the internal-only field before it ever leaves this
        // class — _full_source_identifier exists purely for execute()
        // to read the real identifier when building preHeldSources;
        // it must never reach a dashboard response.
        if (is_array($preview['contributors'] ?? null)) {
            foreach ($preview['contributors'] as &$c) {
                unset($c['_full_source_identifier']);
            }
            unset($c);
        }

        return [
            'session_id' => (int)$session['id'],
            'session_reference' => $session['session_reference'],
            'card_suffix' => $session['card_suffix'],
            'status' => $session['status'],
            'strategy' => $session['strategy'],
            'target_amount' => (float)$session['target_amount'],
            'currency' => $session['currency'],
            'min_contribution_amount' => (float)$session['min_contribution_amount'],
            'preview' => $preview,
            'can_execute' => $session['status'] === 'READY',
            'expires_at' => $session['expires_at'],
            'swap_reference' => $session['swap_reference'],
            'failure_reason' => $session['failure_reason'],
        ];
    }
}
