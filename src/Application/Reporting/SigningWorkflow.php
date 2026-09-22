<?php
declare(strict_types=1);

namespace Application\Reporting;

use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * SigningWorkflow — the in-platform signing chain.
 *
 *   Preparer ──Sign──▶ Reviewer ──Sign──▶ Approver ──Sign──▶ Compliance Officer ──Publish──▶ Regulator
 *        ▲                  │                  │                     │
 *        └────────── Return with reason (from any step) ─────────────┘
 *
 * What a signature is here:
 *   the admin's stored signature image  +  a fresh MFA code  +  the hash of
 *   the exact content they saw  +  a written attestation  +  time and IP.
 *
 * The content is fixed when the report is generated. Signatures never alter
 * it. When the last person signs, the platform renders the final PDF: the
 * report, followed by a signature certificate page showing every signer's
 * image, name, role, time and the content hash they signed. Only that
 * issued PDF is ever shown to the regulator.
 */
final class SigningWorkflow
{
    private const STEPS = ['PREPARE', 'REVIEW', 'APPROVE', 'SUBMIT'];

    private const STATUS_AFTER = [
        'PREPARE' => 'PREPARER_SIGNED',
        'REVIEW'  => 'REVIEWED',
        'APPROVE' => 'APPROVED',
        'SUBMIT'  => 'SUBMITTED',
    ];

    private const ATTESTATION = [
        'PREPARE' => 'I prepared this report and it is complete and accurate to the best of my knowledge.',
        'REVIEW'  => 'I have reviewed this report and checked its figures against the underlying records.',
        'APPROVE' => 'I approve this report for submission to the Bank of Botswana.',
        'SUBMIT'  => 'I publish this approved report to the Bank of Botswana without alteration.',
    ];

    public function __construct(
        private PDO $db,
        private RoleResolver $roles,
        private MfaVerifier $mfa,
        private SignatureSpecimenService $specimens,
        private string $generatorVersion,
        private string $storageDir,          // outside the web root
    ) {}

    public static function attestationFor(string $step): string
    {
        return self::ATTESTATION[$step] ?? '';
    }

    // =================================================================
    // GENERATE — content is HTML; it never changes after this point
    // =================================================================
    public function generate(
        string $type, ?string $periodStart, ?string $periodEnd,
        array $data, callable $htmlBuilder, int $createdBy,
        ?int $incidentId = null, ?string $dueAt = null,
    ): int {
        $matrix = $this->matrix($type);

        $snapshot = json_encode($this->canonical($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $html = $htmlBuilder($data);
        $contentHash = hash('sha256', $html);

        $version = 1 + (int)$this->scalar(
            'SELECT COALESCE(MAX(version),0) FROM regulatory_reports
              WHERE report_type = :t AND period_start IS NOT DISTINCT FROM :s AND period_end IS NOT DISTINCT FROM :e',
            [':t' => $type, ':s' => $periodStart, ':e' => $periodEnd]);

        $supersedes = $version > 1 ? $this->scalar(
            'SELECT id FROM regulatory_reports
              WHERE report_type = :t AND period_start IS NOT DISTINCT FROM :s AND period_end IS NOT DISTINCT FROM :e
              ORDER BY version DESC LIMIT 1',
            [':t' => $type, ':s' => $periodStart, ':e' => $periodEnd]) : null;

        $dir = rtrim($this->storageDir, '/') . '/reports';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $base = sprintf('%s/%s_%s_v%d_%s', $dir, $type, $periodEnd ?? date('Ymd'), $version, substr($contentHash, 0, 12));
        file_put_contents("{$base}.html", $html);
        file_put_contents("{$base}.snapshot.json", $snapshot);

        $stmt = $this->db->prepare(
            'INSERT INTO regulatory_reports
                (report_type, period_start, period_end, version, supersedes_id, incident_id,
                 generator_version, data_snapshot_sha256, content_sha256, document_path, due_at,
                 current_step, awaiting_role)
             VALUES (:t,:s,:e,:v,:sup,:inc,:gv,:dh,:ch,:p,:due,\'PREPARE\',:role)
             RETURNING id');
        $stmt->execute([
            ':t' => $type, ':s' => $periodStart, ':e' => $periodEnd, ':v' => $version,
            ':sup' => $supersedes, ':inc' => $incidentId, ':gv' => $this->generatorVersion,
            ':dh' => hash('sha256', $snapshot), ':ch' => $contentHash, ':p' => "{$base}.html",
            ':due' => $dueAt, ':role' => $matrix['preparer_role'],
        ]);
        $id = (int)$stmt->fetchColumn();

        $this->notifyRole($matrix['preparer_role'], $id, 'SIGN_REQUEST',
            "{$type} v{$version} is ready for you to prepare and sign.");

        return $id;
    }

    // =================================================================
    // SIGN — the Sign button
    // =================================================================
    public function sign(int $reportId, int $adminId, string $mfaCode, bool $attested, ?string $ip = null): array
    {
        if (!$attested) {
            throw new RuntimeException('Tick the attestation before signing.');
        }

        $specimen = $this->specimens->active($adminId);
        if ($specimen === null) {
            throw new RuntimeException('Set up your signature under My Signature before signing documents.');
        }

        $this->db->beginTransaction();
        try {
            $r = $this->lock($reportId);
            $step = $r['current_step'];
            if ($step === null || in_array($r['status'], ['SUBMITTED', 'RETURNED', 'WITHDRAWN'], true)) {
                throw new RuntimeException("This report is {$r['status']} and cannot be signed.");
            }

            $matrix = $this->matrix($r['report_type']);
            $role = $this->roleFor($matrix, $step, $r);

            if (!$this->roles->holds($adminId, $role)) {
                throw new RuntimeException("This step must be signed by the {$this->label($role)}.");
            }

            // Separation of duties: no one signs two steps, unless the matrix
            // assigns both to the same role by design (AML/CFT template).
            foreach ($this->signatures($reportId) as $s) {
                $sameByDesign = $this->roleFor($matrix, $s['step'], $r) === $role;
                if ((int)$s['signer_admin_id'] === $adminId && !$sameByDesign) {
                    throw new RuntimeException("You already signed the {$s['step']} step of this report.");
                }
            }

            // The content must still be exactly what was generated.
            if (!is_file($r['document_path']) || hash_file('sha256', $r['document_path']) !== $r['content_sha256']) {
                throw new RuntimeException('The report content has changed since it was generated. It must be regenerated.');
            }

            if (!$this->mfa->verify($adminId, $mfaCode)) {
                throw new RuntimeException('MFA code incorrect. Nothing was signed.');
            }

            $this->db->prepare(
                'INSERT INTO report_signatures
                    (report_id, step, signer_admin_id, signer_role, signed_content_sha256,
                     mfa_verified_at, statement, ip_address, specimen_id, specimen_sha256)
                 VALUES (:r,:st,:a,:role,:h,now(),:stmt,:ip,:sp,:sph)'
            )->execute([
                ':r' => $reportId, ':st' => $step, ':a' => $adminId, ':role' => $role,
                ':h' => $r['content_sha256'], ':stmt' => self::ATTESTATION[$step], ':ip' => $ip,
                ':sp' => $specimen['id'], ':sph' => $specimen['image_sha256'],
            ]);

            // Route to the next step.
            $next = $this->nextStep($matrix, $step);
            $nextRole = $next ? $this->roleFor($matrix, $next, $r) : null;

            $this->db->prepare(
                'UPDATE regulatory_reports SET status = :st, current_step = :n, awaiting_role = :nr WHERE id = :id'
            )->execute([':st' => self::STATUS_AFTER[$step], ':n' => $next, ':nr' => $nextRole, ':id' => $reportId]);

            $this->markRead($adminId, $reportId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($next !== null) {
            $this->notifyRole($nextRole, $reportId, 'SIGN_REQUEST',
                "{$r['report_type']} v{$r['version']} is waiting for your {$next} signature.");
            return ['status' => self::STATUS_AFTER[$step], 'next_step' => $next, 'next_role' => $nextRole];
        }

        // Last signature: issue the final PDF and release it to the regulator.
        $issued = $this->issue($reportId);
        return ['status' => 'SUBMITTED', 'next_step' => null, 'issued_sha256' => $issued];
    }

    // =================================================================
    // RETURN — send it back with a reason
    // =================================================================
    public function returnForCorrection(int $reportId, int $adminId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new RuntimeException('Give a reason of at least 10 characters.');
        }

        $this->db->beginTransaction();
        try {
            $r = $this->lock($reportId);
            if ($r['current_step'] === null) {
                throw new RuntimeException('Only a report in progress can be returned.');
            }
            $matrix = $this->matrix($r['report_type']);
            if (!$this->roles->holds($adminId, $this->roleFor($matrix, $r['current_step'], $r))) {
                throw new RuntimeException('Only the person due to sign this step can return it.');
            }

            $this->db->prepare('INSERT INTO report_returns (report_id, at_step, returned_by, reason) VALUES (:r,:s,:a,:why)')
                     ->execute([':r' => $reportId, ':s' => $r['current_step'], ':a' => $adminId, ':why' => $reason]);

            $this->db->prepare("UPDATE regulatory_reports SET status = 'RETURNED', current_step = NULL, awaiting_role = NULL WHERE id = :id")
                     ->execute([':id' => $reportId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->notifyRole($matrix['preparer_role'], $reportId, 'RETURNED',
            "{$r['report_type']} v{$r['version']} was returned at {$r['current_step']}: {$reason} — regenerate a new version.");
    }

    // =================================================================
    // PREVIEW — what signers see while it is in progress
    // =================================================================
    public function previewPdf(int $reportId, int $viewerId): string
    {
        $r = $this->fetch($reportId);
        if (!$this->canSeeInternal($viewerId, $r)) {
            throw new RuntimeException('You are not part of this report\'s signing chain.');
        }
        $html = file_get_contents($r['document_path']);
        return $this->render($html . $this->certificatePage($r, false), 'DRAFT — AWAITING SIGNATURES');
    }

    // =================================================================
    // ISSUE — runs once, after the last signature
    // =================================================================
    private function issue(int $reportId): string
    {
        $r = $this->fetch($reportId);
        $html = file_get_contents($r['document_path']);
        if (hash('sha256', $html) !== $r['content_sha256']) {
            throw new RuntimeException('Content changed before issue; refusing.');
        }

        $pdf = $this->render($html . $this->certificatePage($r, true), null);
        $sha = hash('sha256', $pdf);
        $path = preg_replace('/\.html$/', '', $r['document_path']) . '_ISSUED.pdf';
        file_put_contents($path, $pdf);
        @chmod($path, 0600);

        $this->db->prepare(
            'UPDATE regulatory_reports
                SET issued_document_path = :p, issued_sha256 = :s, issued_at = now(),
                    submitted_at = now(), submission_channel = :c
              WHERE id = :id'
        )->execute([':p' => $path, ':s' => $sha, ':c' => 'VOUCHMORPH_SUPERVISORY_PORTAL', ':id' => $reportId]);

        if (!empty($r['incident_id']) && $r['report_type'] === 'INCIDENT_WRITTEN_REPORT') {
            $this->db->prepare('UPDATE incident_register SET written_report_id = :r WHERE id = :i')
                     ->execute([':r' => $reportId, ':i' => $r['incident_id']]);
        }

        // Tell the regulator users it is available.
        $regulators = $this->db->query('SELECT admin_id FROM admins WHERE role_id = 3 AND deleted_at IS NULL')
                               ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($regulators as $rid) {
            $this->notify((int)$rid, $reportId, 'ISSUED',
                "{$r['report_type']} for " . ($r['period_end'] ?? 'the period') . " has been issued.");
        }

        return $sha;
    }

    // =================================================================
    // Certificate page
    // =================================================================
    private function certificatePage(array $r, bool $final): string
    {
        $rows = '';
        $sigs = $this->db->prepare(
            'SELECT s.*, a.full_name FROM report_signatures s
               JOIN admins a ON a.admin_id = s.signer_admin_id
              WHERE s.report_id = :r ORDER BY s.signed_at');
        $sigs->execute([':r' => $r['id']]);

        foreach ($sigs->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $img = $s['specimen_id']
                ? '<img src="' . $this->specimens->dataUri((int)$s['specimen_id']) . '" style="max-height:48px;max-width:180px">'
                : '';
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td><small>%s</small></td></tr>',
                htmlspecialchars($s['step']),
                htmlspecialchars($s['full_name']),
                htmlspecialchars($this->label($s['signer_role'])),
                $img,
                htmlspecialchars(date('d M Y H:i', strtotime($s['signed_at']))) . ' CAT<br><small>MFA verified</small>',
                htmlspecialchars($s['statement'])
            );
        }

        $status = $final
            ? 'Issued ' . date('d M Y H:i') . ' CAT'
            : 'In progress — not valid for submission';

        return '<div style="page-break-before:always;font-family:DejaVu Sans,sans-serif;font-size:10px">'
            . '<h2 style="color:#1F3A5F;margin:0 0 6px">Signature certificate</h2>'
            . '<p>Report: <b>' . htmlspecialchars($r['report_type']) . '</b> · version ' . (int)$r['version']
            . ' · period ' . htmlspecialchars(($r['period_start'] ?? '—') . ' to ' . ($r['period_end'] ?? '—')) . '</p>'
            . '<p>Status: ' . $status . '</p>'
            . '<p>Content fingerprint (SHA-256) signed by every party:<br><code>' . $r['content_sha256'] . '</code></p>'
            . '<table width="100%" border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse">'
            . '<tr style="background:#1F3A5F;color:#fff"><th>Step</th><th>Signer</th><th>Signature</th><th>Signed</th><th>Attestation</th></tr>'
            . ($rows ?: '<tr><td colspan="5">No signatures yet</td></tr>')
            . '</table>'
            . '<p style="margin-top:8px;color:#555">Each signer confirmed their identity with a one-time MFA code at the moment of signing. '
            . 'Any change to the report content changes its fingerprint and invalidates every signature above.</p>'
            . '</div>';
    }

    private function render(string $html, ?string $watermark): string
    {
        $opt = new Options();
        $opt->set('isRemoteEnabled', false);   // data URIs only; no outbound fetches
        $opt->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opt);

        if ($watermark !== null) {
            $html = '<div style="position:fixed;top:40%;left:0;right:0;text-align:center;font-size:48px;'
                  . 'color:rgba(200,0,0,0.12);transform:rotate(-30deg)">' . htmlspecialchars($watermark) . '</div>' . $html;
        }

        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }

    // =================================================================
    // Helpers
    // =================================================================
    private function roleFor(array $m, string $step, array $r): string
    {
        $role = match ($step) {
            'PREPARE' => $m['preparer_role'],
            'REVIEW'  => $m['reviewer_role'],
            'APPROVE' => $m['approver_role'],
            'SUBMIT'  => $m['submitter_role'],
        };
        if ($step === 'APPROVE' && $role === 'MANAGING_DIRECTOR' && !empty($r['incident_id'])) {
            $ic = $this->scalar('SELECT incident_commander_id FROM incident_register WHERE id = :i', [':i' => $r['incident_id']]);
            if ($ic !== null && $this->roles->holds((int)$ic, 'MANAGING_DIRECTOR')) {
                return 'BOARD_CHAIR';
            }
        }
        return $role;
    }

    private function nextStep(array $m, string $current): ?string
    {
        $steps = array_values(array_filter(['PREPARE', $m['reviewer_role'] ? 'REVIEW' : null, 'APPROVE', 'SUBMIT']));
        $i = array_search($current, $steps, true);
        return $steps[$i + 1] ?? null;
    }

    private function canSeeInternal(int $adminId, array $r): bool
    {
        if ($this->roles->holds($adminId, 'MANAGING_DIRECTOR') || $this->roles->holds($adminId, 'COMPLIANCE_OFFICER')) {
            return true;
        }
        $m = $this->matrix($r['report_type']);
        foreach (['preparer_role', 'reviewer_role', 'approver_role', 'submitter_role'] as $k) {
            if ($m[$k] && $this->roles->holds($adminId, $m[$k])) {
                return true;
            }
        }
        return false;
    }

    private function notifyRole(string $role, int $reportId, string $kind, string $msg): void
    {
        $stmt = $this->db->prepare('SELECT admin_id FROM admin_functional_roles WHERE role = :r AND revoked_at IS NULL');
        $stmt->execute([':r' => $role]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $a) {
            $this->notify((int)$a, $reportId, $kind, $msg);
        }
    }

    private function notify(int $adminId, int $reportId, string $kind, string $msg): void
    {
        $this->db->prepare('INSERT INTO admin_notifications (admin_id, report_id, kind, message) VALUES (:a,:r,:k,:m)')
                 ->execute([':a' => $adminId, ':r' => $reportId, ':k' => $kind, ':m' => $msg]);
        // Hook email/SMS here if wanted; the in-platform inbox is the record.
    }

    private function markRead(int $adminId, int $reportId): void
    {
        $this->db->prepare('UPDATE admin_notifications SET read_at = now() WHERE admin_id = :a AND report_id = :r AND read_at IS NULL')
                 ->execute([':a' => $adminId, ':r' => $reportId]);
    }

    public function label(string $role): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $role)));
    }

    private function signatures(int $reportId): array
    {
        $s = $this->db->prepare('SELECT * FROM report_signatures WHERE report_id = :r');
        $s->execute([':r' => $reportId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function matrix(string $type): array
    {
        $s = $this->db->prepare('SELECT * FROM report_signoff_matrix WHERE report_type = :t');
        $s->execute([':t' => $type]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException("No sign-off rules for {$type}");
    }

    private function lock(int $id): array
    {
        $s = $this->db->prepare('SELECT * FROM regulatory_reports WHERE id = :id FOR UPDATE');
        $s->execute([':id' => $id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException("Report {$id} not found");
    }

    public function fetch(int $id): array
    {
        $s = $this->db->prepare('SELECT * FROM regulatory_reports WHERE id = :id');
        $s->execute([':id' => $id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException("Report {$id} not found");
    }

    private function scalar(string $sql, array $p): mixed
    {
        $s = $this->db->prepare($sql);
        $s->execute($p);
        $v = $s->fetchColumn();
        return $v === false ? null : $v;
    }

    private function canonical(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (!array_is_list($v)) {
            ksort($v);
        }
        return array_map([$this, 'canonical'], $v);
    }
}
