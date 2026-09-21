<?php
declare(strict_types=1);

namespace Application\Incident;

/**
 * Builds the reports as self-contained HTML (printable, and turned into PDF
 * with Dompdf on download). Everything in a report comes from the incident's
 * own record - its steps, remedies, controls and timeline - so the report can
 * never say something the record does not.
 */
final class ReportBuilder
{
    private static function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

    private static function t(?string $ts): string
    {
        if (!$ts) return '-';
        return (new \DateTimeImmutable($ts, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Gaborone'))->format('d M Y, H:i');
    }

    public static function shell(string $title, string $ref, string $body): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Gaborone')))->format('d M Y, H:i');
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . self::h($title) . '</title><style>
body{font-family:DejaVu Sans,Arial,sans-serif;color:#1b2430;font-size:11.5px;line-height:1.45;margin:28px}
h1{font-size:18px;color:#1F3A5F;margin:0 0 2px}h2{font-size:13px;color:#1F3A5F;margin:18px 0 6px;border-bottom:1px solid #c9d3de;padding-bottom:3px}
.meta{color:#5b6775;font-size:10.5px;margin-bottom:14px}table{width:100%;border-collapse:collapse;margin:6px 0}
th,td{border:1px solid #c9d3de;padding:5px 7px;text-align:left;vertical-align:top}th{background:#1F3A5F;color:#fff;font-weight:bold}
.k{width:32%;background:#f2f6fa;font-weight:bold}.sev{display:inline-block;padding:1px 7px;border:1px solid #1F3A5F;font-weight:bold}
.muted{color:#6b7684}.box{border:1px solid #c9d3de;padding:8px 10px;background:#fbfcfd}.sig td{height:34px}
</style></head><body><div style="font-weight:bold;color:#1F3A5F;letter-spacing:1px">VOUCHMORPH (PTY) LTD</div>
<div class="meta">CIPA Reg. No. BW00009655259 &middot; Bank of Botswana Regulatory Sandbox participant</div>
<h1>' . self::h($title) . '</h1><div class="meta">Reference ' . self::h($ref) . ' &middot; generated ' . $now . ' (Botswana time)</div>' . $body . '</body></html>';
    }

    public static function incident(string $type, array $inc, array $actions, array $log, array $controls, array $contacts): string
    {
        $h = fn($v) => self::h($v);
        $who = fn(string $code) => $h(($contacts[$code]['holder_name'] ?? $code) . ' (' . ($contacts[$code]['role_title'] ?? $code) . ')');
        $pb = Playbooks::get($inc['playbook']);

        $facts = '<table>'
            . '<tr><td class="k">Incident</td><td>' . $h($inc['incident_id']) . ' &middot; <span class="sev">' . $h($inc['severity']) . '</span> &middot; status ' . $h($inc['status']) . '</td></tr>'
            . '<tr><td class="k">What happened</td><td>' . $h($inc['title']) . ($inc['summary'] ? '<br><span class="muted">' . nl2br($h($inc['summary'])) . '</span>' : '') . '</td></tr>'
            . '<tr><td class="k">Scenario (VM-GOV-001)</td><td>' . $h($inc['playbook'] . ' - ' . $pb['title']) . '</td></tr>'
            . '<tr><td class="k">Opened</td><td>' . self::t($inc['opened_at']) . ($inc['trigger_source'] === 'AUTO' ? ' (raised automatically by monitoring)' : '') . '</td></tr>'
            . '<tr><td class="k">Customers affected</td><td>' . ($inc['customers_affected'] === null ? 'Being established' : (int)$inc['customers_affected']) . '</td></tr>'
            . '<tr><td class="k">Money at risk</td><td>' . ($inc['money_at_risk'] === null ? 'Being established' : 'BWP ' . number_format((float)$inc['money_at_risk'], 2)) . ' <span class="muted">(VouchMorph never holds customer funds; value is held by the participating institutions)</span></td></tr>'
            . '<tr><td class="k">Incident Commander</td><td>' . $who('INCIDENT_COMMANDER') . '</td></tr>'
            . '<tr><td class="k">Regulatory contact</td><td>' . $who('COMPLIANCE_OFFICER') . ($contacts['COMPLIANCE_OFFICER']['email'] ?? '' ? ', ' . $h($contacts['COMPLIANCE_OFFICER']['email']) : '') . ($contacts['COMPLIANCE_OFFICER']['phone'] ?? '' ? ', ' . $h($contacts['COMPLIANCE_OFFICER']['phone']) : '') . '</td></tr>'
            . '</table>';

        $ctrl = '';
        if ($controls) {
            $ctrl = '<h2>Protective measures (freezes)</h2><table><tr><th>Scope</th><th>Target</th><th>Frozen</th><th>Reason</th><th>Lifted</th></tr>';
            foreach ($controls as $c) {
                $ctrl .= '<tr><td>' . $h($c['scope']) . '</td><td>' . $h($c['target']) . '</td><td>' . self::t($c['frozen_at']) . '</td><td>' . $h($c['reason']) . '</td><td>'
                    . ($c['resumed_at'] ? self::t($c['resumed_at']) . ' <span class="muted">(requested and approved by two different admins)</span>' : '<b>Still in force</b>') . '</td></tr>';
            }
            $ctrl .= '</table>';
        }

        $steps = '<table><tr><th>#</th><th>Owner</th><th>Action</th><th>Deadline</th><th>Status</th><th>What was done</th></tr>';
        foreach ($actions as $a) {
            $steps .= '<tr><td>' . (int)$a['step_no'] . '</td><td>' . $h($contacts[$a['owner_role']]['holder_name'] ?? $a['owner_role']) . '<br><span class="muted">' . $h($a['owner_role']) . '</span></td><td>' . $h($a['action'])
                . '</td><td>' . self::t($a['due_at']) . '</td><td>' . $h($a['status']) . ($a['done_at'] ? '<br><span class="muted">' . self::t($a['done_at']) . '</span>' : '') . '</td><td>' . nl2br($h($a['remedy'] ?? '')) . '</td></tr>';
        }
        $steps .= '</table>';

        $timeline = '<table><tr><th style="width:24%">When</th><th>Entry</th></tr>';
        foreach ($log as $l) $timeline .= '<tr><td>' . self::t($l['at']) . '</td><td>' . $h($l['entry']) . '</td></tr>';
        $timeline .= '</table>';

        $pending = array_filter($actions, fn($a) => $a['status'] === 'PENDING');
        $nextUpdate = (new \DateTimeImmutable('now +4 hours', new \DateTimeZone('Africa/Gaborone')))->format('d M Y, H:i');

        if ($type === 'BANK_NOTICE') {
            $body = '<p>This notifies the Bank of Botswana of an incident under the sandbox stop-and-restart rules and VouchMorph\'s Business Continuity Plan.</p>'
                . '<h2>1. The incident</h2>' . $facts . $ctrl
                . '<h2>2. Actions taken and in progress</h2>' . $steps
                . '<h2>3. Next update</h2><p class="box">The next update will be sent by ' . $nextUpdate . ' (every 4 hours until closed), and a written report within 48 hours.</p>'
                . '<h2>Sent by</h2><table class="sig"><tr><td class="k">Compliance Officer</td><td></td></tr><tr><td class="k">Date and time sent</td><td></td></tr></table>';
            return self::shell('Notification to the Bank of Botswana', $inc['incident_id'], $body);
        }
        if ($type === 'REPORT_48H') {
            $body = '<h2>1. Summary</h2>' . $facts
                . '<h2>2. Root cause</h2><p class="box">' . ($inc['root_cause'] ? nl2br($h($inc['root_cause'])) : '<i>Root cause still under investigation - record it on the incident before sending.</i>') . '</p>'
                . $ctrl . '<h2>3. Actions and remedies</h2>' . $steps
                . '<h2>4. Open items</h2>' . ($pending ? '<ul>' . implode('', array_map(fn($a) => '<li>Step ' . (int)$a['step_no'] . ' (' . $h($a['owner_role']) . '): ' . $h($a['action']) . ' - due ' . self::t($a['due_at']) . '</li>', $pending)) . '</ul>' : '<p>None.</p>')
                . '<h2>5. Timeline</h2>' . $timeline
                . '<h2>Approval</h2><table class="sig"><tr><td class="k">Compliance Officer</td><td></td></tr><tr><td class="k">Managing Director</td><td></td></tr></table>';
            return self::shell('Written incident report (48 hours)', $inc['incident_id'], $body);
        }
        $body = '<h2>1. Summary</h2>' . $facts
            . '<h2>2. Root cause</h2><p class="box">' . ($inc['root_cause'] ? nl2br($h($inc['root_cause'])) : '<i>Not recorded.</i>') . '</p>'
            . $ctrl . '<h2>3. Every action and its outcome</h2>' . $steps
            . '<h2>4. Customer and financial outcome</h2><p class="box">Resolved ' . self::t($inc['resolved_at']) . '. ' . ($pending ? '<b>' . count($pending) . ' step(s) still open - the incident should not be closed.</b>' : 'All playbook steps completed or skipped with a recorded reason.') . ' Zero-loss sign-off by the Accountant is recorded in the steps above where the playbook requires it.</p>'
            . '<h2>5. Full timeline</h2>' . $timeline
            . '<h2>Approval</h2><table class="sig"><tr><td class="k">Incident Commander</td><td></td></tr><tr><td class="k">Compliance Officer</td><td></td></tr><tr><td class="k">Chair of the Board (noted)</td><td></td></tr></table>';
        return self::shell('Incident closure report', $inc['incident_id'], $body);
    }

    /** Accountant's daily sign-off sheet (VM-GOV-001 S2). */
    public static function dailySignoff(string $date, array $fig, ?string $exceptions, string $signedBy, string $signedAt): string
    {
        $h = fn($v) => self::h($v);
        $rows = '';
        foreach ($fig as $label => $value) $rows .= '<tr><td class="k">' . $h($label) . '</td><td>' . $h($value) . '</td></tr>';
        $body = '<h2>Figures for ' . $h($date) . '</h2><table>' . $rows . '</table>'
            . '<h2>Exceptions and follow-up</h2><p class="box">' . ($exceptions ? nl2br($h($exceptions)) : 'None.') . '</p>'
            . '<h2>Sign-off</h2><table class="sig"><tr><td class="k">Signed by</td><td>' . $h($signedBy) . '</td></tr><tr><td class="k">Signed at</td><td>' . self::t($signedAt) . '</td></tr></table>';
        return self::shell('Daily reconciliation sign-off', 'SIGNOFF-' . $date, $body);
    }

    /** Month-end pack (VM-GOV-001 S12): settlement, fees, exceptions, incidents. */
    public static function monthEnd(string $month, array $sections): string
    {
        $h = fn($v) => self::h($v);
        $body = '';
        foreach ($sections as $title => $table) {
            $body .= '<h2>' . $h($title) . '</h2>';
            if (!$table) { $body .= '<p class="muted">Nothing to report.</p>'; continue; }
            $body .= '<table><tr>' . implode('', array_map(fn($k) => '<th>' . $h($k) . '</th>', array_keys($table[0]))) . '</tr>';
            foreach ($table as $row) $body .= '<tr>' . implode('', array_map(fn($v) => '<td>' . $h($v) . '</td>', $row)) . '</tr>';
            $body .= '</table>';
        }
        $body .= '<h2>Verification</h2><table class="sig"><tr><td class="k">Accountant (verified)</td><td></td></tr><tr><td class="k">Head of Products</td><td></td></tr><tr><td class="k">Managing Director (approved)</td><td></td></tr></table>';
        return self::shell('Month-end accounting pack', 'MONTH-' . $month, $body);
    }
}
