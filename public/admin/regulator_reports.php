<?php
/**
 * public/admin/regulator_reports.php
 * Issued reports only — the Bank's view. Each download is logged, and the
 * page shows the fingerprint so anyone can verify the file is untouched.
 */
declare(strict_types=1);
require __DIR__ . '/_signing_bootstrap.php';

// Who may open this page: the regulator role, and the people who sign.
$allowed = $roleId === 3 || $roleId === 999
    || $roles->holds($adminId, 'COMPLIANCE_OFFICER') || $roles->holds($adminId, 'MANAGING_DIRECTOR');
if (!$allowed) {
    http_response_code(403);
    vm_audit('ACCESS_DENIED', ['page' => 'regulator_reports']);
    exit('Not permitted.');
}

function log_supervisory(PDO $db, int $adminId, string $view, array $params): void
{
    $db->prepare('INSERT INTO supervisory_access_log (admin_id, view, parameters, ip_address) VALUES (:a,:v,:p,:ip)')
       ->execute([':a' => $adminId, ':v' => $view, ':p' => json_encode($params), ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
}

// Download of one issued PDF
if (isset($_GET['download'])) {
    $r = $workflow->fetch((int)$_GET['download']);
    if ($r['status'] !== 'SUBMITTED' || empty($r['issued_document_path'])) {
        http_response_code(404);
        exit('Not issued.');
    }
    $bytes = file_get_contents($r['issued_document_path']);
    if ($bytes === false || hash('sha256', $bytes) !== $r['issued_sha256']) {
        http_response_code(409);
        vm_audit('ISSUED_REPORT_INTEGRITY_FAILURE', ['report_id' => $r['id']]);
        exit('The stored file does not match its recorded fingerprint. The Compliance Officer has been alerted.');
    }
    if ($roleId === 3) {
        log_supervisory($db, $adminId, 'download_report', ['report_id' => (int)$r['id']]);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $r['report_type'] . '_v' . $r['version'] . '.pdf"');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

if ($roleId === 3) {
    log_supervisory($db, $adminId, 'list_reports', []);
}

$rows = $db->query(
    "SELECT r.id, r.report_type, r.period_start, r.period_end, r.version, r.issued_at, r.due_at,
            r.issued_sha256, (r.due_at IS NOT NULL AND r.issued_at > r.due_at) AS late,
            (SELECT COUNT(*) FROM report_signatures s WHERE s.report_id = r.id) AS signatures
       FROM regulatory_reports r
       JOIN report_signoff_matrix m ON m.report_type = r.report_type AND m.regulator_visible
      WHERE r.status = 'SUBMITTED' AND r.issued_at IS NOT NULL
      ORDER BY r.issued_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Issued reports · VouchMorph</title>
<style><?= VM_PAGE_CSS ?> code{font-size:11px;word-break:break-all}</style></head>
<body><div class="wrap">
<?= $roleId === 3 ? '' : vm_nav() ?>
<h1>Issued reports</h1>
<p class="sub">Every report here was signed in full inside the platform before it appeared. Each PDF ends with a signature certificate.</p>
<div class="card">
<?php if (!$rows): ?><p>No reports issued yet.</p><?php else: ?>
<table><tr><th>Report</th><th>Period</th><th>Issued</th><th>Signatures</th><th>Fingerprint (SHA-256)</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= h(str_replace('_', ' ', $r['report_type'])) ?> <small>v<?= (int)$r['version'] ?></small></td>
  <td><?= h(($r['period_start'] ?? '') . ' – ' . ($r['period_end'] ?? '')) ?></td>
  <td><?= h(date('d M Y H:i', strtotime($r['issued_at']))) ?> <?= $r['late'] ? '<span class="pill late">late</span>' : '' ?></td>
  <td><?= (int)$r['signatures'] ?></td>
  <td><code><?= h($r['issued_sha256']) ?></code></td>
  <td><a class="btn ghost" href="?download=<?= (int)$r['id'] ?>">Download</a></td>
</tr>
<?php endforeach; ?></table>
<?php endif; ?>
</div></div></body></html>
