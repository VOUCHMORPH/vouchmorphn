<?php
/**
 * enterprise/batches/export.php - Detailed batch export (PDF)
 *
 * Streams a branded PDF record of one batch: full details, purpose,
 * the complete approval trail (who created/submitted/approved/rejected/
 * executed it and when), and the recipient manifest.
 *
 * Same PDF architecture already used by public/admin/admin_dashboard.php
 * (Dompdf, a branded page shell, small metrics/table section helpers) —
 * kept self-contained here rather than sharing code with that file,
 * since it's a completely separate auth surface (platform-admin, not
 * this org-level enterprise dashboard).
 *
 * NOTE: disbursement_batches has no dedicated "purpose"/"description"
 * column — the closest real field is batch_name (the free-text label
 * given at creation), so that's what "Purpose" below is built from,
 * labeled honestly rather than implying a separate field exists.
 */
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
requirePermission('export_filings');   // added: this endpoint had no permission check
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
use Core\Database\DBConnection;
use Dompdf\Dompdf;
use Dompdf\Options;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$batchId = $_GET['id'] ?? 0;

function safeHtmlExp($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function formatCurrencyExp($amount, $currency = null) {
    $formatted = number_format((float)$amount, 2);
    return $currency ? $formatted . ' ' . safeHtmlExp($currency) : $formatted . ' (currency not set)';
}

// Same batch query as batches/view.php — reused, not reinvented.
$stmt = $db->prepare("
    SELECT
        b.*,
        u1.full_name as created_by_name,
        u2.full_name as submitted_by_name,
        u3.full_name as reviewed_by_name,
        u4.full_name as approved_by_name,
        u5.full_name as executed_by_name
    FROM disbursement_batches b
    LEFT JOIN users u1 ON b.created_by = u1.user_id
    LEFT JOIN users u2 ON b.submitted_by = u2.user_id
    LEFT JOIN users u3 ON b.reviewed_by = u3.user_id
    LEFT JOIN users u4 ON b.approved_by = u4.user_id
    LEFT JOIN users u5 ON b.executed_by = u5.user_id
    WHERE b.id = :id AND b.organization_id = :org_id
");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

$stmt = $db->prepare("SELECT * FROM disbursement_destinations WHERE batch_id = :batch_id ORDER BY destination_index");
$stmt->execute([':batch_id' => $batchId]);
$destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$departmentName = null;
if (!empty($batch['department_id'])) {
    $stmt = $db->prepare("SELECT name FROM departments WHERE id = :id");
    $stmt->execute([':id' => $batch['department_id']]);
    $departmentName = $stmt->fetchColumn() ?: null;
}

$orgName = $user['organization_name'] ?? 'Organization';
$preparedBy = $user['full_name'] ?? $user['username'] ?? 'User';
$batchCurrency = $batch['currency'] ?? null;

// ============================================================
// PDF SECTION BUILDERS
// ============================================================
function metricsBlock(array $pairs): string {
    $html = '<table class="pdf-metrics"><tr>';
    $i = 0;
    foreach ($pairs as $label => $value) {
        if ($i > 0 && $i % 3 === 0) { $html .= '</tr><tr>'; }
        $html .= '<td class="pdf-metric-cell"><div class="pdf-metric-label">' . safeHtmlExp($label) . '</div><div class="pdf-metric-value">' . safeHtmlExp($value) . '</div></td>';
        $i++;
    }
    while ($i % 3 !== 0) { $html .= '<td class="pdf-metric-cell"></td>'; $i++; }
    $html .= '</tr></table>';
    return $html;
}

function tableBlock(array $headers, array $rows, ?string $note = null): string {
    if (empty($rows)) {
        return '<p class="pdf-empty">No records.</p>';
    }
    $html = '<table class="pdf-data"><thead><tr>';
    foreach ($headers as $h) { $html .= '<th>' . safeHtmlExp($h) . '</th>'; }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . safeHtmlExp((string)$cell) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    if ($note) { $html .= '<p class="pdf-note">' . safeHtmlExp($note) . '</p>'; }
    return $html;
}

// ---- Overview ----
$bodyHtml = '<div class="pdf-section-title">Batch Overview</div>';
$bodyHtml .= metricsBlock([
    'Reference' => $batch['batch_reference'],
    'Status' => strtoupper(str_replace('_', ' ', $batch['status'] ?? 'draft')),
    'Department' => $departmentName ?? 'Not assigned',
    'Source Institution' => $batch['source_institution'] ?? '—',
    'Source Account' => $batch['source_identifier'] ?? '—',
    'Source Asset Type' => $batch['source_asset_type'] ?? '—',
    'Currency' => $batchCurrency ?? 'Not set',
    'Total Amount' => formatCurrencyExp($batch['total_amount'] ?? 0, $batchCurrency),
    'Total Recipients' => $batch['total_destinations'] ?? count($destinations),
]);

// ---- Purpose (mapped from batch_name — see file header note) ----
$bodyHtml .= '<div class="pdf-section-title">Purpose</div>';
$bodyHtml .= '<p class="pdf-purpose">' . safeHtmlExp($batch['batch_name'] ?: 'No description recorded for this batch.') . '</p>';

if (!empty($batch['rejection_reason'])) {
    $bodyHtml .= '<div class="pdf-section-title">Rejection Reason</div>';
    $bodyHtml .= '<p class="pdf-purpose">' . safeHtmlExp($batch['rejection_reason']) . '</p>';
}

// ---- Approval trail: who added it, who approved it, and everything
// else in between, straight from the same columns batches/view.php and
// review_batch.php already display — nothing new computed here. ----
$approvalRows = [];
if (!empty($batch['created_by_name'])) {
    $approvalRows[] = ['Created', $batch['created_by_name'], !empty($batch['created_at']) ? date('Y-m-d H:i', strtotime($batch['created_at'])) : '—'];
}
if (!empty($batch['submitted_by_name'])) {
    $approvalRows[] = ['Submitted for Approval', $batch['submitted_by_name'], !empty($batch['submitted_at']) ? date('Y-m-d H:i', strtotime($batch['submitted_at'])) : '—'];
}
if (!empty($batch['approved_by_name'])) {
    $approvalRows[] = ['Approved', $batch['approved_by_name'], !empty($batch['approved_at']) ? date('Y-m-d H:i', strtotime($batch['approved_at'])) : '—'];
}
if (!empty($batch['reviewed_by_name']) && strtolower($batch['status'] ?? '') === 'rejected') {
    $approvalRows[] = ['Rejected', $batch['reviewed_by_name'], !empty($batch['reviewed_at']) ? date('Y-m-d H:i', strtotime($batch['reviewed_at'])) : '—'];
}
if (!empty($batch['executed_by_name'])) {
    $approvalRows[] = ['Executed', $batch['executed_by_name'], !empty($batch['executed_at']) ? date('Y-m-d H:i', strtotime($batch['executed_at'])) : '—'];
}
$bodyHtml .= '<div class="pdf-section-title">Approval Trail</div>';
$bodyHtml .= tableBlock(['Stage', 'By', 'When'], $approvalRows, empty($approvalRows) ? null : 'Stages this batch has not yet reached are omitted, not blank.');

// ---- Recipients ----
$destRows = [];
foreach ($destinations as $d) {
    $identifierDisplay = ($d['is_identity_recipient'] ?? false)
        ? (($d['identity_type'] ?? 'identity') . ': ' . ($d['identity_value'] ?? ''))
        : ($d['identifier'] ?? '');
    $destRows[] = [
        $d['destination_index'],
        $d['beneficiary_name'] ?? 'N/A',
        $identifierDisplay,
        formatCurrencyExp($d['amount'] ?? 0, $d['currency'] ?? $batchCurrency),
        $d['status'] ?? 'PENDING',
    ];
}
$bodyHtml .= '<div class="pdf-section-title">Recipients (' . count($destinations) . ')</div>';
$bodyHtml .= tableBlock(['#', 'Beneficiary', 'Identifier', 'Amount', 'Status'], $destRows);

// ============================================================
// BRANDED PAGE SHELL + STREAM
// ============================================================
$generated = date('Y-m-d H:i:s');
$reportTitle = 'Disbursement Batch Record';
$reportSubtitle = safeHtmlExp($batch['batch_reference']) . ' &middot; ' . safeHtmlExp($orgName);

$html = <<<PDF_HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: 'Helvetica', 'Arial', sans-serif; color: #0F2138; font-size: 11px; }
    .pdf-header { border-bottom: 3px solid #8A6D3B; padding-bottom: 10px; margin-bottom: 18px; }
    .pdf-header .brand { font-size: 18px; font-weight: bold; }
    .pdf-header .brand span { color: #8A6D3B; font-weight: normal; }
    .pdf-header .report-title { font-size: 15px; font-weight: bold; margin-top: 8px; }
    .pdf-header .report-subtitle { font-size: 11px; color: #555; margin-top: 2px; }
    .pdf-header .report-meta { font-size: 9px; color: #888; margin-top: 6px; }
    .pdf-section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #8A6D3B; margin: 16px 0 6px; border-bottom: 1px solid #D3DAD6; padding-bottom: 3px; }
    table.pdf-metrics { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.pdf-metrics td.pdf-metric-cell { border: 1px solid #D3DAD6; padding: 8px; width: 33%; }
    .pdf-metric-label { font-size: 8px; text-transform: uppercase; color: #8A96A3; }
    .pdf-metric-value { font-size: 13px; font-weight: bold; margin-top: 3px; }
    table.pdf-data { width: 100%; border-collapse: collapse; font-size: 9px; }
    table.pdf-data th { background: #EEF1EF; text-align: left; padding: 5px 6px; border-bottom: 2px solid #0F2138; font-size: 8px; text-transform: uppercase; }
    table.pdf-data td { padding: 5px 6px; border-bottom: 1px solid #D3DAD6; }
    .pdf-purpose { font-size: 11px; padding: 8px 0; }
    .pdf-empty { color: #8A96A3; font-style: italic; }
    .pdf-note { font-size: 8px; color: #8A96A3; margin-top: 6px; }
    .pdf-footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 8px; color: #8A96A3; text-align: center; border-top: 1px solid #D3DAD6; padding-top: 4px; }
</style>
</head>
<body>
    <div class="pdf-header">
        <div class="brand">VOUCHMORPH <span>Enterprise</span></div>
        <div class="report-title">{$reportTitle}</div>
        <div class="report-subtitle">{$reportSubtitle}</div>
        <div class="report-meta">Prepared by {$preparedBy} &middot; Generated {$generated}</div>
    </div>
    {$bodyHtml}
    <div class="pdf-footer">VOUCHMORPH &middot; {$orgName} &middot; Generated {$generated}</div>
</body>
</html>
PDF_HTML;

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$filename = 'batch-' . preg_replace('/[^A-Za-z0-9_-]/', '', $batch['batch_reference'] ?? (string)$batchId) . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
