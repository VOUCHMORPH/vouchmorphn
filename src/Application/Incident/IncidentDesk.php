<?php
declare(strict_types=1);

namespace Application\Incident;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Incident Command engine (VM-GOV-001).
 *
 * Rules enforced here, not just shown on screen:
 *  - Freezing (service, institution, flow, agent, client) takes ONE authorised
 *    admin and is immediate: nobody waits for approval to stop money moving.
 *  - Lifting a freeze takes TWO different admins: one requests, another
 *    approves (four-eyes). The database refuses the same person for both.
 *  - A customer notice is drafted by one admin and approved by another; only
 *    the Incident Commander role (Super Admin) approves.
 *  - Every alarm states what to do now and who to call; every incident carries
 *    its playbook steps with owner, deadline and the remedy recorded.
 *  - SEV1/SEV2 incidents get a 2-hour Bank notice deadline and a 48-hour
 *    written report deadline; the monitor raises alarms when they slip.
 *  - Every action is written to the tamper-evident audit trail.
 */
final class IncidentDesk
{
    /** Admin role ids (admin_dashboard.php) -> VM-GOV-001 roles. */
    public const ROLE_SUPER = 999;      // Managing Director / Incident Commander
    public const ROLE_COMPLIANCE = 4;   // Compliance Officer
    public const ROLE_FINANCE = 10;     // Accountant
    public const ROLE_SUPPORT = 20;     // Operations
    public const ROLE_AUDITOR = 5;
    public const ROLE_REGULATOR = 3;

    public const FREEZE_ROLES = [
        'SERVICE' => [999], 'INSTITUTION' => [999], 'FLOW' => [999],
        'AGENT' => [999, 4], 'CLIENT' => [999, 4],
    ];
    public const RESUME_REQUEST_ROLES = [999, 4, 20];
    public const RESUME_APPROVE_ROLES = [999, 4];
    public const BROADCAST_DRAFT_ROLES = [999, 4, 20];
    public const BROADCAST_APPROVE_ROLES = [999];
    public const INCIDENT_OPEN_ROLES = [999, 4, 10, 20];
    public const INCIDENT_CLOSE_ROLES = [999];
    public const ACTION_ROLES = [999, 4, 10, 11, 20];
    public const REPORT_ROLES = [999, 4, 10];
    public const REPORT_SEND_ROLES = [999, 4];

    public function __construct(private PDO $db) {}

    // ------------------------------------------------------------------
    // Authority
    // ------------------------------------------------------------------
    public static function allowed(int $role, array $roles): bool
    {
        return in_array($role, $roles, true);
    }

    private function must(int $role, array $roles, string $what): void
    {
        if (!self::allowed($role, $roles)) {
            throw new RuntimeException("Your role is not authorised to {$what}.");
        }
    }

    // ------------------------------------------------------------------
    // Alarms
    // ------------------------------------------------------------------
    /** Raises an alarm once per dedupe key while it is unresolved. Returns the alert id, or null if already open. */
    public function raiseAlert(string $rule, string $dedupeKey, string $title, array $detail = []): ?int
    {
        $r = Playbooks::rule($rule);
        $stmt = $this->db->prepare("
            INSERT INTO ic_alerts (rule_code, severity, title, detail, playbook, dedupe_key)
            VALUES (?, ?, ?, ?::jsonb, ?, ?)
            ON CONFLICT (dedupe_key) WHERE status <> 'RESOLVED' DO NOTHING
            RETURNING alert_id
        ");
        $stmt->execute([$rule, $r['severity'], mb_substr($title, 0, 200), json_encode($detail + ['what_to_do_now' => $r['now']]), $r['playbook'], mb_substr($dedupeKey, 0, 160)]);
        $alertId = $stmt->fetchColumn();
        if ($alertId === false) return null;
        $alertId = (int)$alertId;

        $incidentId = null;
        if ($r['open_incident'] && $r['playbook']) {
            // One failure of one control is one incident: further alarms of the same
            // rule join the open incident (one Bank notice, one report) instead of
            // opening another.
            $existing = $this->db->prepare("SELECT incident_id FROM ic_incidents WHERE status <> 'CLOSED' AND trigger_ref LIKE ? ORDER BY opened_at LIMIT 1");
            $existing->execute([$rule . ':%']);
            $incidentId = $existing->fetchColumn() ?: null;
            if ($incidentId) {
                $this->log($incidentId, null, "Further alarm joined this incident: {$title}");
            } else {
                $incidentId = $this->openIncident($r['severity'], $r['playbook'], $title, $r['now'], 'AUTO', $rule . ':' . $dedupeKey, null);
            }
            $this->db->prepare("UPDATE ic_alerts SET incident_id = ? WHERE alert_id = ?")->execute([$incidentId, $alertId]);
        }
        if ($r['notify']) {
            $this->notify($r['notify'], "[{$r['severity']}] {$title}",
                "{$title}\n\nWhat to do now: {$r['now']}\n" . ($incidentId ? "Incident {$incidentId} is open with its playbook steps.\n" : '')
                . "Open Incident Command in the admin dashboard.", $alertId, $incidentId);
        }
        return $alertId;
    }

    public function ackAlert(int $alertId, int $adminId): void
    {
        $this->db->prepare("UPDATE ic_alerts SET status = 'ACKED', acked_by = ?, acked_at = NOW() WHERE alert_id = ? AND status = 'OPEN'")
            ->execute([$adminId, $alertId]);
        $this->audit($adminId, 'ALERT_ACKNOWLEDGED', 'alert', (string)$alertId, []);
    }

    public function resolveAlert(int $alertId, int $adminId, string $resolution): void
    {
        if (mb_strlen(trim($resolution)) < 5) throw new RuntimeException('Record what was done to resolve the alarm (at least 5 characters).');
        $this->db->prepare("UPDATE ic_alerts SET status = 'RESOLVED', resolved_by = ?, resolved_at = NOW(), resolution = ? WHERE alert_id = ? AND status <> 'RESOLVED'")
            ->execute([$adminId, $resolution, $alertId]);
        $this->audit($adminId, 'ALERT_RESOLVED', 'alert', (string)$alertId, ['resolution' => $resolution]);
    }

    /** Auto-resolves alarms of a rule whose condition has cleared (called by the monitor). */
    public function autoResolve(string $rule, array $stillActiveKeys): int
    {
        $stmt = $this->db->prepare("SELECT alert_id, dedupe_key FROM ic_alerts WHERE rule_code = ? AND status <> 'RESOLVED'");
        $stmt->execute([$rule]);
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!in_array($a['dedupe_key'], $stillActiveKeys, true)) {
                $this->db->prepare("UPDATE ic_alerts SET status = 'RESOLVED', resolved_at = NOW(), resolution = 'Condition cleared (detected by monitor)' WHERE alert_id = ?")
                    ->execute([$a['alert_id']]);
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Incidents
    // ------------------------------------------------------------------
    public function openIncident(string $severity, string $playbook, string $title, ?string $summary, string $source, ?string $ref, ?int $adminId, ?int $role = null): string
    {
        if ($role !== null) $this->must($role, self::INCIDENT_OPEN_ROLES, 'open incidents');
        $pb = Playbooks::get($playbook);
        $day = gmdate('Ymd');
        $this->db->query("SELECT pg_advisory_xact_lock(7743010)");
        $n = (int)$this->db->query("SELECT COUNT(*) FROM ic_incidents WHERE incident_id LIKE 'INC-{$day}-%'")->fetchColumn() + 1;
        $id = sprintf('INC-%s-%03d', $day, $n);
        $bankMin = Playbooks::BANK_NOTICE_MINUTES[$severity] ?? null;

        $this->db->prepare("
            INSERT INTO ic_incidents (incident_id, severity, playbook, title, summary, trigger_source, trigger_ref, opened_by,
                                      bank_notify_due, report_48h_due, closure_due)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?,
                    CASE WHEN ?::int IS NULL THEN NULL ELSE NOW() + (?::int * INTERVAL '1 minute') END,
                    CASE WHEN ?::int IS NULL THEN NULL ELSE NOW() + INTERVAL '48 hours' END,
                    NOW() + INTERVAL '7 days')
        ")->execute([$id, $severity, $playbook, mb_substr($title, 0, 200), $summary, $source, $ref, $adminId, $bankMin, $bankMin, $bankMin]);

        $ins = $this->db->prepare("INSERT INTO ic_incident_actions (incident_id, step_no, owner_role, action, hands_to, due_at) VALUES (?, ?, ?, ?, ?, NOW() + (? * INTERVAL '1 minute'))");
        foreach ($pb['steps'] as $i => [$owner, $action, $handsTo, $mins]) {
            $ins->execute([$id, $i + 1, $owner, $action, $handsTo, (int)$mins]);
        }
        $this->log($id, $adminId, "Incident opened ({$severity}, playbook {$playbook}: {$pb['title']})" . ($source === 'AUTO' ? ' automatically by the monitor' : ''));
        $this->audit($adminId, 'INCIDENT_OPENED', 'incident', $id, ['severity' => $severity, 'playbook' => $playbook, 'title' => $title]);
        if ($bankMin) {
            $this->notify(['INCIDENT_COMMANDER', 'COMPLIANCE_OFFICER'], "[{$severity}] Incident {$id} opened: {$title}",
                "Incident {$id} ({$severity}) is open under playbook {$playbook}.\nThe Bank of Botswana must be notified within 2 hours.\nOpen Incident Command for the steps and owners.", null, $id);
        }
        return $id;
    }

    public function completeAction(int $actionId, int $adminId, int $role, string $remedy, bool $skipped = false): void
    {
        $this->must($role, self::ACTION_ROLES, 'record playbook steps');
        if (mb_strlen(trim($remedy)) < 5) throw new RuntimeException('Record what was done (at least 5 characters).');
        $stmt = $this->db->prepare("
            UPDATE ic_incident_actions SET status = ?, remedy = ?, done_by = ?, done_at = NOW()
            WHERE action_id = ? AND status = 'PENDING' RETURNING incident_id, step_no, owner_role
        ");
        $stmt->execute([$skipped ? 'SKIPPED' : 'DONE', $remedy, $adminId, $actionId]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new RuntimeException('That step is already recorded.');
        $this->log($a['incident_id'], $adminId, "Step {$a['step_no']} ({$a['owner_role']}) " . ($skipped ? 'skipped' : 'done') . ": {$remedy}");
        $this->audit($adminId, $skipped ? 'INCIDENT_STEP_SKIPPED' : 'INCIDENT_STEP_DONE', 'incident', $a['incident_id'], ['step' => (int)$a['step_no'], 'remedy' => $remedy]);
    }

    public function updateIncident(string $id, int $adminId, int $role, array $f): void
    {
        $this->must($role, self::INCIDENT_OPEN_ROLES, 'update incidents');
        $allowed = ['summary', 'root_cause', 'customers_affected', 'money_at_risk', 'severity', 'status'];
        $set = []; $vals = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $f) || $f[$k] === '' || $f[$k] === null) continue;
            if ($k === 'status') {
                if (!in_array($f[$k], ['OPEN', 'CONTAINED', 'RESOLVED', 'CLOSED'], true)) continue;
                if ($f[$k] === 'CLOSED') {
                    $this->must($role, self::INCIDENT_CLOSE_ROLES, 'close incidents');
                    $open = $this->db->prepare("SELECT COUNT(*) FROM ic_incident_actions WHERE incident_id = ? AND status = 'PENDING'");
                    $open->execute([$id]);
                    if ((int)$open->fetchColumn() > 0) throw new RuntimeException('Every playbook step must be done or skipped with a reason before closing.');
                    $set[] = 'closed_at = NOW()'; $set[] = 'closed_by = ' . (int)$adminId;
                }
                if ($f[$k] === 'RESOLVED') $set[] = 'resolved_at = COALESCE(resolved_at, NOW())';
            }
            if ($k === 'severity' && !in_array($f[$k], ['SEV1', 'SEV2', 'SEV3', 'SEV4'], true)) continue;
            $set[] = "{$k} = ?"; $vals[] = $f[$k];
        }
        if (!$set) return;
        $vals[] = $id;
        $this->db->prepare("UPDATE ic_incidents SET " . implode(', ', $set) . " WHERE incident_id = ?")->execute($vals);
        $this->log($id, $adminId, 'Updated: ' . implode(', ', array_keys(array_intersect_key($f, array_flip($allowed)))));
        $this->audit($adminId, 'INCIDENT_UPDATED', 'incident', $id, array_intersect_key($f, array_flip($allowed)));
    }

    public function log(string $incidentId, ?int $adminId, string $entry): void
    {
        $this->db->prepare("INSERT INTO ic_incident_log (incident_id, admin_id, entry) VALUES (?, ?, ?)")->execute([$incidentId, $adminId, $entry]);
    }

    // ------------------------------------------------------------------
    // Controls: freeze now (one person), lift with four-eyes (two people)
    // ------------------------------------------------------------------
    public function freeze(string $scope, string $target, string $reason, ?string $customerMessage, int $adminId, int $role, ?string $incidentId): int
    {
        $scope = strtoupper($scope);
        if (!isset(self::FREEZE_ROLES[$scope])) throw new RuntimeException('Unknown scope.');
        $this->must($role, self::FREEZE_ROLES[$scope], 'freeze at this scope');
        $target = $scope === 'SERVICE' ? '*' : strtoupper(trim($target));
        if ($target === '') throw new RuntimeException('Say what to freeze.');
        if (in_array($scope, ['AGENT', 'CLIENT'], true) && !ctype_digit($target)) throw new RuntimeException('Agents and clients are frozen by their user id.');
        if (mb_strlen(trim($reason)) < 10) throw new RuntimeException('Give the reason (at least 10 characters); it goes in the audit trail and the Bank notice.');

        $stmt = $this->db->prepare("
            INSERT INTO ic_controls (scope, target, reason, customer_message, incident_id, frozen_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT (scope, target) WHERE resumed_at IS NULL DO NOTHING
            RETURNING control_id
        ");
        $stmt->execute([$scope, $target, $reason, $customerMessage ?: null, $incidentId ?: null, $adminId]);
        $id = $stmt->fetchColumn();
        if ($id === false) throw new RuntimeException("{$scope} {$target} is already frozen.");
        $id = (int)$id;
        $this->audit($adminId, 'CONTROL_FROZEN', 'control', (string)$id, ['scope' => $scope, 'target' => $target, 'reason' => $reason, 'incident' => $incidentId], 'critical');
        if ($incidentId) $this->log($incidentId, $adminId, "FROZE {$scope} {$target}: {$reason}");
        $this->raiseAlert('CONTROL_FROZEN', "CONTROL_FROZEN:{$id}", "{$scope} {$target} frozen: " . mb_substr($reason, 0, 120),
            ['control_id' => $id, 'scope' => $scope, 'target' => $target, 'reason' => $reason, 'incident' => $incidentId]);
        return $id;
    }

    public function requestResume(int $controlId, int $adminId, int $role, string $reason): void
    {
        $this->must($role, self::RESUME_REQUEST_ROLES, 'request lifting a freeze');
        if (mb_strlen(trim($reason)) < 10) throw new RuntimeException('Say why it is safe to lift (at least 10 characters).');
        $stmt = $this->db->prepare("
            UPDATE ic_controls SET resume_requested_by = ?, resume_requested_at = NOW(), resume_reason = ?
            WHERE control_id = ? AND resumed_at IS NULL AND resume_requested_by IS NULL
            RETURNING scope, target, incident_id
        ");
        $stmt->execute([$adminId, $reason, $controlId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new RuntimeException('That freeze is not active, or lifting it is already requested.');
        $this->audit($adminId, 'CONTROL_RESUME_REQUESTED', 'control', (string)$controlId, ['reason' => $reason]);
        if ($c['incident_id']) $this->log($c['incident_id'], $adminId, "Requested lifting {$c['scope']} {$c['target']}: {$reason}");
        $this->raiseAlert('RESUME_AWAITING', "RESUME_AWAITING:{$controlId}", "Lifting {$c['scope']} {$c['target']} awaits a second admin",
            ['control_id' => $controlId, 'requested_by' => $adminId, 'reason' => $reason]);
    }

    public function approveResume(int $controlId, int $adminId, int $role): void
    {
        $this->must($role, self::RESUME_APPROVE_ROLES, 'approve lifting a freeze');
        $c = $this->db->prepare("SELECT * FROM ic_controls WHERE control_id = ? AND resumed_at IS NULL FOR UPDATE");
        $this->db->beginTransaction();
        try {
            $c->execute([$controlId]);
            $row = $c->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('That freeze is not active.');
            if (!$row['resume_requested_by']) throw new RuntimeException('Lifting has not been requested yet.');
            if ((int)$row['resume_requested_by'] === $adminId) throw new RuntimeException('Four-eyes rule: a different admin must approve the request you made.');
            $this->db->prepare("UPDATE ic_controls SET resumed_by = ?, resumed_at = NOW() WHERE control_id = ?")->execute([$adminId, $controlId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        $this->audit($adminId, 'CONTROL_RESUMED', 'control', (string)$controlId, ['scope' => $row['scope'], 'target' => $row['target'], 'requested_by' => (int)$row['resume_requested_by']], 'warning');
        if ($row['incident_id']) $this->log($row['incident_id'], $adminId, "LIFTED {$row['scope']} {$row['target']} (requested by admin {$row['resume_requested_by']}, approved by admin {$adminId})");
        foreach (["CONTROL_FROZEN:{$controlId}", "RESUME_AWAITING:{$controlId}"] as $k) {
            $this->db->prepare("UPDATE ic_alerts SET status = 'RESOLVED', resolved_by = ?, resolved_at = NOW(), resolution = 'Freeze lifted with four-eyes approval' WHERE dedupe_key = ? AND status <> 'RESOLVED'")
                ->execute([$adminId, $k]);
        }
    }

    public function activeControls(): array
    {
        return $this->db->query("SELECT * FROM ic_controls WHERE resumed_at IS NULL ORDER BY frozen_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Customer notices: drafted by one, approved by another
    // ------------------------------------------------------------------
    public function draftBroadcast(array $f, int $adminId, int $role): int
    {
        $this->must($role, self::BROADCAST_DRAFT_ROLES, 'draft customer notices');
        $title = trim((string)($f['title'] ?? ''));
        $body = trim((string)($f['body'] ?? ''));
        if (mb_strlen($title) < 5 || mb_strlen($body) < 10) throw new RuntimeException('A notice needs a title and a message.');
        $level = in_array($f['level'] ?? '', ['INFO', 'WARNING', 'CRITICAL'], true) ? $f['level'] : 'INFO';
        $audience = preg_match('/^(ALL|AGENTS|INSTITUTION:[A-Z0-9_]+|FLOW:[A-Z0-9_]+)$/', (string)($f['audience'] ?? 'ALL')) ? $f['audience'] : 'ALL';
        $hours = max(1, min(168, (int)($f['hours'] ?? 24)));
        $stmt = $this->db->prepare("
            INSERT INTO ic_broadcasts (audience, title, body, level, send_sms, incident_id, drafted_by, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW() + (? * INTERVAL '1 hour')) RETURNING broadcast_id
        ");
        $stmt->execute([$audience, mb_substr($title, 0, 120), $body, $level, !empty($f['send_sms']) ? 'true' : 'false', ($f['incident_id'] ?? '') ?: null, $adminId, $hours]);
        $id = (int)$stmt->fetchColumn();
        $this->audit($adminId, 'BROADCAST_DRAFTED', 'broadcast', (string)$id, ['title' => $title, 'audience' => $audience]);
        $this->notify(['INCIDENT_COMMANDER'], "Customer notice awaiting your approval: {$title}", "A customer notice was drafted and needs the Incident Commander's approval before it is published:\n\n{$title}\n{$body}", null, ($f['incident_id'] ?? '') ?: null);
        return $id;
    }

    public function decideBroadcast(int $id, int $adminId, int $role, bool $approve): array
    {
        $this->must($role, self::BROADCAST_APPROVE_ROLES, 'approve customer notices');
        $row = $this->db->prepare("SELECT * FROM ic_broadcasts WHERE broadcast_id = ? AND status = 'DRAFT'");
        $row->execute([$id]);
        $b = $row->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new RuntimeException('That notice is not waiting for approval.');
        if ((int)$b['drafted_by'] === $adminId) throw new RuntimeException('Four-eyes rule: the person who drafted a notice cannot approve it.');
        if (!$approve) {
            $this->db->prepare("UPDATE ic_broadcasts SET status = 'REJECTED', approved_by = ?, approved_at = NOW() WHERE broadcast_id = ?")->execute([$adminId, $id]);
            $this->audit($adminId, 'BROADCAST_REJECTED', 'broadcast', (string)$id, []);
            return ['published' => false];
        }
        $this->db->prepare("UPDATE ic_broadcasts SET status = 'PUBLISHED', approved_by = ?, approved_at = NOW() WHERE broadcast_id = ?")->execute([$adminId, $id]);
        $sms = $b['send_sms'] === true || $b['send_sms'] === 't' || $b['send_sms'] === 1 ? $this->smsBroadcast($b) : 0;
        $this->db->prepare("UPDATE ic_broadcasts SET sms_sent = ? WHERE broadcast_id = ?")->execute([$sms, $id]);
        $this->audit($adminId, 'BROADCAST_PUBLISHED', 'broadcast', (string)$id, ['title' => $b['title'], 'audience' => $b['audience'], 'sms_sent' => $sms], 'warning');
        if ($b['incident_id']) $this->log($b['incident_id'], $adminId, "Customer notice published: {$b['title']} (SMS sent: {$sms})");
        return ['published' => true, 'sms_sent' => $sms];
    }

    public function withdrawBroadcast(int $id, int $adminId, int $role): void
    {
        $this->must($role, self::BROADCAST_DRAFT_ROLES, 'withdraw customer notices');
        $this->db->prepare("UPDATE ic_broadcasts SET status = 'WITHDRAWN', expires_at = NOW() WHERE broadcast_id = ? AND status = 'PUBLISHED'")->execute([$id]);
        $this->audit($adminId, 'BROADCAST_WITHDRAWN', 'broadcast', (string)$id, []);
    }

    /** SMS to customers in the audience, through the configured gateway. Returns how many were accepted. */
    private function smsBroadcast(array $b): int
    {
        if (!class_exists('\\Infrastructure\\SMS\\SmsNotificationService')) return 0;
        try {
            $cfgFile = dirname(__DIR__, 2) . '/Core/Config/Countries/Botswana/communication.json';
            $cfg = is_file($cfgFile) ? (json_decode((string)file_get_contents($cfgFile), true) ?: []) : [];
            $sms = new \Infrastructure\SMS\SmsNotificationService($this->db, $cfg);
            $phones = $this->db->query("SELECT DISTINCT phone FROM users WHERE phone IS NOT NULL AND phone <> '' LIMIT 5000")->fetchAll(PDO::FETCH_COLUMN);
            $ok = 0;
            $text = mb_substr("VouchMorph: {$b['title']}. {$b['body']}", 0, 300);
            foreach ($phones as $p) {
                $r = $sms->sendSms((string)$p, $text, ['type' => 'broadcast']);
                if (!empty($r['success'])) $ok++;
            }
            return $ok;
        } catch (Throwable $e) {
            error_log('[IncidentDesk] broadcast SMS failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Notifications to people
    // ------------------------------------------------------------------
    public function contacts(): array
    {
        $rows = $this->db->query("SELECT * FROM ic_contacts ORDER BY external, role_title")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[$r['role_code']] = $r;
        return $out;
    }

    public function notify(array $roles, string $subject, string $body, ?int $alertId, ?string $incidentId): void
    {
        if (!$roles) return;
        $contacts = $this->contacts();
        $email = null;
        if (class_exists('\\Infrastructure\\Email\\EmailGatewayClient') && class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            try { $c = new \Infrastructure\Email\EmailGatewayClient(); if ($c->isConfigured()) $email = $c; } catch (Throwable $e) { $email = null; }
        }
        $ins = $this->db->prepare("INSERT INTO ic_notifications (alert_id, incident_id, role_code, channel, address, subject, body, status, error, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? = 'SENT' THEN NOW() END)");
        foreach (array_unique($roles) as $roleCode) {
            $c = $contacts[$roleCode] ?? null;
            $addr = $c['email'] ?? null;
            if (!$addr) {
                $ins->execute([$alertId, $incidentId, $roleCode, 'EMAIL', null, $subject, $body, 'NO_ADDRESS', 'No email on the Contacts tab for this role', 'NO_ADDRESS']);
                continue;
            }
            if (!$email) {
                $ins->execute([$alertId, $incidentId, $roleCode, 'EMAIL', $addr, $subject, $body, 'PENDING', 'Email not configured (SMTP settings and PHPMailer needed)', 'PENDING']);
                continue;
            }
            try {
                $r = $email->sendEmail($addr, $subject, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
                $ok = !empty($r['success']);
                $ins->execute([$alertId, $incidentId, $roleCode, 'EMAIL', $addr, $subject, $body, $ok ? 'SENT' : 'FAILED', $ok ? null : (string)($r['error'] ?? 'send failed'), $ok ? 'SENT' : 'FAILED']);
            } catch (Throwable $e) {
                $ins->execute([$alertId, $incidentId, $roleCode, 'EMAIL', $addr, $subject, $body, 'FAILED', $e->getMessage(), 'FAILED']);
            }
        }
    }

    public function updateContact(string $roleCode, array $f, int $adminId, int $role): void
    {
        $this->must($role, [999, 4], 'edit contacts');
        $this->db->prepare("UPDATE ic_contacts SET holder_name = ?, phone = ?, email = ?, updated_at = NOW(), updated_by = ? WHERE role_code = ?")
            ->execute([trim((string)($f['holder_name'] ?? '')) ?: null, trim((string)($f['phone'] ?? '')) ?: null, trim((string)($f['email'] ?? '')) ?: null, $adminId, $roleCode]);
        $this->audit($adminId, 'CONTACT_UPDATED', 'contact', $roleCode, ['holder_name' => $f['holder_name'] ?? null]);
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------
    public function generateIncidentReport(string $type, string $incidentId, int $adminId, int $role): int
    {
        $this->must($role, self::REPORT_ROLES, 'generate reports');
        if (!in_array($type, ['BANK_NOTICE', 'REPORT_48H', 'CLOSURE'], true)) throw new RuntimeException('Unknown report type.');
        $inc = $this->incident($incidentId);
        if (!$inc) throw new RuntimeException('Incident not found.');
        $html = ReportBuilder::incident($type, $inc, $this->actions($incidentId), $this->incidentLog($incidentId), $this->controlsFor($incidentId), $this->contacts());
        $titles = ['BANK_NOTICE' => 'Notification to the Bank of Botswana', 'REPORT_48H' => 'Written incident report (48 hours)', 'CLOSURE' => 'Incident closure report'];
        $recipients = $type === 'BANK_NOTICE' || $type === 'REPORT_48H' || $type === 'CLOSURE' ? 'BANK_SANDBOX_OPERATOR,INCIDENT_COMMANDER,BOARD_CHAIR' : '';
        return $this->storeReport($type, $incidentId, null, "{$titles[$type]} - {$incidentId}", $html, $recipients, $adminId);
    }

    public function storeReport(string $type, ?string $incidentId, ?string $period, string $title, string $html, string $recipients, int $adminId): int
    {
        $stmt = $this->db->prepare("INSERT INTO ic_reports (report_type, incident_id, period, title, html, recipients, generated_by) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING report_id");
        $stmt->execute([$type, $incidentId, $period, $title, $html, $recipients, $adminId]);
        $id = (int)$stmt->fetchColumn();
        if ($incidentId) $this->log($incidentId, $adminId, "Generated report {$id}: {$title}");
        $this->audit($adminId, 'REPORT_GENERATED', 'report', (string)$id, ['type' => $type, 'incident' => $incidentId, 'period' => $period]);
        return $id;
    }

    /** Emails the report to its recipients if email works; otherwise leaves it READY for manual sending. */
    public function sendReport(int $reportId, int $adminId, int $role): array
    {
        $this->must($role, self::REPORT_SEND_ROLES, 'send reports');
        $r = $this->report($reportId);
        if (!$r) throw new RuntimeException('Report not found.');
        $contacts = $this->contacts();
        $addrs = [];
        foreach (array_filter(explode(',', (string)$r['recipients'])) as $code) {
            if (!empty($contacts[$code]['email'])) $addrs[$code] = $contacts[$code]['email'];
        }
        $email = null;
        if (class_exists('\\Infrastructure\\Email\\EmailGatewayClient') && class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            try { $c = new \Infrastructure\Email\EmailGatewayClient(); if ($c->isConfigured()) $email = $c; } catch (Throwable $e) {}
        }
        if (!$email || !$addrs) {
            $why = !$email ? 'Email is not configured (SMTP settings and PHPMailer)' : 'No recipient email addresses on the Contacts tab';
            $this->db->prepare("UPDATE ic_reports SET sent_status = 'EMAIL_FAILED', send_note = ? WHERE report_id = ?")->execute([$why . '. Download it, send it, then mark it sent.', $reportId]);
            return ['emailed' => false, 'reason' => $why];
        }
        $fails = [];
        foreach ($addrs as $code => $to) {
            try { $res = $email->sendEmail($to, $r['title'], $r['html']); if (empty($res['success'])) $fails[] = $code; } catch (Throwable $e) { $fails[] = $code; }
        }
        $status = $fails ? 'EMAIL_FAILED' : 'EMAILED';
        $this->db->prepare("UPDATE ic_reports SET sent_status = ?, sent_at = CASE WHEN ? = 'EMAILED' THEN NOW() END, sent_by = ?, send_note = ? WHERE report_id = ?")
            ->execute([$status, $status, $adminId, $fails ? 'Failed for: ' . implode(', ', $fails) : 'Emailed to ' . implode(', ', array_keys($addrs)), $reportId]);
        if (!$fails) $this->stampIncident($r, $adminId, 'emailed');
        return ['emailed' => !$fails, 'failed' => $fails];
    }

    public function markReportSent(int $reportId, int $adminId, int $role, string $note): void
    {
        $this->must($role, self::REPORT_SEND_ROLES, 'record reports as sent');
        if (mb_strlen(trim($note)) < 5) throw new RuntimeException('Say how and to whom it was sent.');
        $r = $this->report($reportId);
        if (!$r) throw new RuntimeException('Report not found.');
        $this->db->prepare("UPDATE ic_reports SET sent_status = 'MARKED_SENT', sent_at = NOW(), sent_by = ?, send_note = ? WHERE report_id = ?")->execute([$adminId, $note, $reportId]);
        $this->stampIncident($r, $adminId, $note);
    }

    private function stampIncident(array $r, int $adminId, string $how): void
    {
        if (!$r['incident_id']) return;
        $col = ['BANK_NOTICE' => 'bank_notified_at', 'REPORT_48H' => 'report_48h_sent_at', 'CLOSURE' => 'closure_sent_at'][$r['report_type']] ?? null;
        if ($col) $this->db->prepare("UPDATE ic_incidents SET {$col} = COALESCE({$col}, NOW()) WHERE incident_id = ?")->execute([$r['incident_id']]);
        $this->log($r['incident_id'], $adminId, "Report {$r['report_id']} sent ({$r['report_type']}): {$how}");
        $this->audit($adminId, 'REPORT_SENT', 'report', (string)$r['report_id'], ['type' => $r['report_type'], 'how' => $how]);
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------
    public function incident(string $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM ic_incidents WHERE incident_id = ?"); $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function actions(string $id): array
    {
        $s = $this->db->prepare("SELECT * FROM ic_incident_actions WHERE incident_id = ? ORDER BY step_no"); $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function incidentLog(string $id): array
    {
        $s = $this->db->prepare("SELECT * FROM ic_incident_log WHERE incident_id = ? ORDER BY at, log_id"); $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function controlsFor(string $id): array
    {
        $s = $this->db->prepare("SELECT * FROM ic_controls WHERE incident_id = ? ORDER BY frozen_at"); $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function report(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM ic_reports WHERE report_id = ?"); $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ------------------------------------------------------------------
    private function audit(?int $adminId, string $action, string $type, string $id, array $detail, string $severity = 'info'): void
    {
        if (!class_exists('\\Application\\Admin\\AdminAudit')) return;
        \Application\Admin\AdminAudit::recordOrLog($this->db, $adminId, $action, $type, $id, $detail, 'INCIDENT', $severity);
    }
}
