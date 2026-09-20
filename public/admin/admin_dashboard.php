<?php
/**
 * admin_dashboard.php - VouchMorph Admin Dashboard
 * Designed to perfectly complement the login page with tight alignment
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
session_start();

define('PROJECT_ROOT', dirname(__DIR__, 2));

require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';
require_once PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
require_once PROJECT_ROOT . '/src/Application/Admin/AdminAudit.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;
use Core\Config\LoadCountry;
use Application\Admin\AdminAudit;
use Dompdf\Dompdf;
use Dompdf\Options;

if (!SessionManager::isAdminLoggedIn()) {
    header('Location: admin_login.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$adminId = SessionManager::getAdminId();
$adminUsername = SessionManager::getAdminUsername();
$adminFullName = SessionManager::get('admin_full_name');
$adminRoleId = SessionManager::getAdminRoleId();
$adminCountry = SessionManager::getAdminCountry();

$roleDefinitions = [
    999 => ['name' => 'Super Admin', 'label' => 'SUPER ADMIN', 'view' => ['dashboard', 'live_transactions', 'audit', 'invoices', 'regulatory', 'all_tables', 'recent_swaps', 'multi_destination', 'alerts', 'institution_health', 'client_lookup', 'agent_approvals', 'reports', 'participants']],
    3 => ['name' => 'Central Bank Regulator', 'label' => 'REGULATOR', 'view' => ['dashboard', 'regulatory', 'audit', 'ledger', 'recent_swaps', 'multi_destination', 'alerts', 'institution_health', 'reports', 'participants', 'invoices']],
    4 => ['name' => 'Compliance Officer', 'label' => 'COMPLIANCE', 'view' => ['dashboard', 'audit', 'ledger', 'recent_swaps', 'alerts', 'client_lookup', 'agent_approvals', 'reports']],
    5 => ['name' => 'Auditor', 'label' => 'AUDITOR', 'view' => ['dashboard', 'audit', 'ledger', 'recent_swaps', 'institution_health', 'reports', 'participants']],
    10 => ['name' => 'Finance Manager', 'label' => 'FINANCE', 'view' => ['dashboard', 'invoices', 'recent_swaps', 'alerts', 'institution_health', 'reports']],
    11 => ['name' => 'Settlement Officer', 'label' => 'SETTLEMENT', 'view' => ['dashboard', 'recent_swaps', 'alerts', 'institution_health', 'participants', 'invoices']],
    20 => ['name' => 'Customer Support', 'label' => 'SUPPORT', 'view' => ['dashboard', 'client_lookup', 'agent_approvals']]
];

$roleInfo = $roleDefinitions[$adminRoleId] ?? $roleDefinitions[5];
$roleName = $roleInfo['name'] ?? 'Auditor';
$availableViews = $roleInfo['view'] ?? ['dashboard'];
$isSuperAdmin = ($adminRoleId === 999);

function canView($view) {
    global $availableViews, $isSuperAdmin;
    return $isSuperAdmin || in_array($view, $availableViews);
}

function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Every data query on this page used to swallow its own errors, so a
// broken query rendered as "no data" and nobody could tell the
// difference. Failures are now collected here, logged, and shown to the
// roles that can act on them (see the banner under the page header).
$dashboardErrors = [];
function dashError(string $where, Throwable $e): void {
    global $dashboardErrors;
    $dashboardErrors[] = ['where' => $where, 'message' => $e->getMessage()];
    error_log("[ADMIN DASHBOARD] {$where}: " . $e->getMessage());
}

// Splits a raw DB timestamp (which may carry fractional seconds and a UTC
// offset, e.g. "2026-09-12 11:55:17.95014+00") into its date and
// time-with-microseconds parts, dropping only the trailing offset. Parsed
// directly off the string (not strtotime/date, which round to whole
// seconds) so microsecond precision survives. Returns null for empty input.
// VouchMorph is a Botswana operation; the database stores/returns these
// timestamps in UTC (the "+00" suffix on every raw value), so every
// display helper below shifts by this fixed offset before formatting.
// Africa/Gaborone has never observed DST, so a constant +2h is correct
// year-round — no DateTimeZone table lookup needed.
const BOTSWANA_UTC_OFFSET_SECONDS = 7200;

function splitTs($value) {
    if ($value === null || $value === '') {
        return null;
    }
    $value = trim((string)$value);
    // Grab the original fractional-second digits, if any, before doing
    // anything else — they're unaffected by the whole-hour timezone shift
    // below and strtotime() would otherwise just discard them.
    $microFrac = '';
    if (preg_match('/\.(\d+)/', $value, $fracMatch)) {
        $microFrac = $fracMatch[1];
    }
    // strtotime() first, not the raw digits: it's what actually resolves
    // the string's own UTC offset into an absolute instant, which is what
    // lets us shift it to Botswana time correctly below.
    $ts = strtotime($value);
    if ($ts !== false) {
        $botswanaTs = $ts + BOTSWANA_UTC_OFFSET_SECONDS;
        $time = gmdate('H:i:s', $botswanaTs) . ($microFrac !== '' ? '.' . $microFrac : '');
        return ['date' => gmdate('Y-m-d', $botswanaTs), 'time' => $time];
    }
    // Last resort for a string strtotime() can't parse at all: show its
    // digits as written, un-shifted, rather than nothing.
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2}(?:\.\d+)?)/', $value, $m)) {
        return ['date' => $m[1], 'time' => $m[2]];
    }
    return ['date' => $value, 'time' => ''];
}

// Renders a timestamp as safe HTML with the date and time (microseconds
// included) stacked on separate lines, instead of one long string that
// wraps mid-value inside a narrow table cell.
function tsHtml($value, $fallback = '—') {
    $parts = splitTs($value);
    if ($parts === null) {
        return safeHtml($fallback);
    }
    $html = '<div class="ts"><span class="ts-date">' . safeHtml($parts['date']) . '</span>';
    if ($parts['time'] !== '') {
        $html .= '<span class="ts-time">' . safeHtml($parts['time']) . '</span>';
    }
    return $html . '</div>';
}

// Two timestamps side by side with an arrow between them — the "amount
// added" start and "swap finished" end, for a table cell that needs to
// show the whole span rather than a single instant. Falls back to a plain
// single tsHtml() when there's no end (or no start) to pair it with.
function tsRangeHtml($startValue, $endValue, $fallback = '—') {
    if (empty($startValue) && empty($endValue)) {
        return safeHtml($fallback);
    }
    if (empty($startValue) || empty($endValue)) {
        return tsHtml($startValue ?: $endValue, $fallback);
    }
    return '<div class="ts-range">' . tsHtml($startValue, $fallback)
        . '<span class="ts-range-arrow">&rarr;</span>' . tsHtml($endValue, $fallback) . '</div>';
}

// Plain-text form (date + time, microseconds included, offset dropped) for
// non-HTML outputs like the PDF and CSV certificate exports.
function fmtTs($value, $fallback = '') {
    $parts = splitTs($value);
    if ($parts === null) {
        return $fallback;
    }
    return trim($parts['date'] . ' ' . $parts['time']);
}

// Unix epoch as a float, preserving fractional seconds that strtotime()
// alone discards (it truncates to whole seconds), so short swap durations
// aren't reported as "0s" just because they completed within the same
// second.
function tsToEpoch($value) {
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime((string)$value);
    if ($ts === false) {
        return null;
    }
    $frac = 0.0;
    if (preg_match('/\.(\d+)/', (string)$value, $m)) {
        $frac = (float)('0.' . $m[1]);
    }
    return $ts + $frac;
}

// Inverse of tsToEpoch(): turns a float epoch back into a "Y-m-d H:i:s.u"
// string that splitTs()/tsHtml()/fmtTs() can render, so a resolved
// start/end instant (which may come from whichever timestamp column
// actually won, not always the same one) still displays consistently.
function epochToTs($epoch) {
    if ($epoch === null) {
        return null;
    }
    $whole = (int)floor($epoch);
    $frac = round($epoch - $whole, 6);
    if ($frac >= 1) { // rounding carried into the next second
        $whole += 1;
        $frac = 0.0;
    }
    // Deliberately UTC (via gmdate(), not date()), with an explicit "+00"
    // suffix — matching the raw strings straight out of the DB — because
    // this string gets fed back into splitTs()/tsHtml()/fmtTs() for
    // display, and THAT is where the single Botswana-time shift happens.
    // Shifting here too would double-apply it.
    return gmdate('Y-m-d H:i:s', $whole) . substr(sprintf('%.6f', $frac), 1) . '+00';
}

// Formats the gap between two raw DB timestamps as a compact human string
// (e.g. "384ms" or "1.240s"), so the fact that two events happened at
// different real moments is obvious at a glance rather than hidden in
// small microsecond text two readers have to compare by eye.
function fmtElapsed($fromValue, $toValue) {
    $from = tsToEpoch($fromValue);
    $to = tsToEpoch($toValue);
    if ($from === null || $to === null) {
        return '—';
    }
    $seconds = $to - $from;
    if (abs($seconds) < 1) {
        return round($seconds * 1000) . 'ms';
    }
    return number_format($seconds, 3) . 's';
}

// vw_all_swaps unions several event tables (hold placement, identity
// verification, etc.), so one swap reference can surface as more than one
// row — e.g. a HOLD/PENDING_IDENTITY row and a separate IDENTITY/PENDING
// row for the exact same swap. Collapse those down to a single row per
// reference for the activity feeds, keeping whichever row represents the
// furthest-along status (falling back to whichever happened most recently
// when two rows are equally "advanced").
function dedupeSwapRows(array $rows) {
    static $statusRank = [
        'completed' => 5, 'success' => 5, 'debited' => 5,
        'failed' => 4, 'error' => 4, 'cancelled' => 4,
        'pending_cashout' => 3, 'pending_identity' => 3, 'pending' => 3, 'processing' => 3,
        'active' => 2, 'held' => 2,
        'released' => 1, 'partially_released' => 1,
    ];
    $best = [];
    $fallbackIndex = 0;
    foreach ($rows as $row) {
        $ref = $row['swap_reference'] ?? $row['reference'] ?? '';
        $key = $ref !== '' ? $ref : ('__row_' . $fallbackIndex++);
        if (!isset($best[$key])) {
            $best[$key] = $row;
            continue;
        }
        $current = $best[$key];
        $currentRank = $statusRank[strtolower($current['status'] ?? '')] ?? 2;
        $rowRank = $statusRank[strtolower($row['status'] ?? '')] ?? 2;
        $currentEpoch = tsToEpoch($current['created_at'] ?? '') ?? -INF;
        $rowEpoch = tsToEpoch($row['created_at'] ?? '') ?? -INF;
        if ($rowRank > $currentRank || ($rowRank === $currentRank && $rowEpoch > $currentEpoch)) {
            $best[$key] = $row;
        }
    }
    return array_values($best);
}

function csvEscape($value) {
    $value = (string)$value;
    if (preg_match('/[",\n]/', $value)) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

// ============================================================
// PDF GENERATION HELPERS
// ============================================================
// Reports are built as an array of "sections" - each is either a
// key/value metrics block or a table - then rendered into a single
// branded HTML document and converted with Dompdf. This is separate
// from the on-screen HTML (which is richer/interactive); PDFs stay
// deliberately simple since they're the artifact someone downloads,
// archives, or emails to a regulator - it needs to render identically
// every time, which "print to PDF" in a browser cannot guarantee.

function pdf_metrics_section(string $title, array $pairs): string {
    $html = '<div class="pdf-section-title">' . safeHtml($title) . '</div><table class="pdf-metrics"><tr>';
    $i = 0;
    foreach ($pairs as $label => $value) {
        if ($i > 0 && $i % 4 === 0) { $html .= '</tr><tr>'; }
        $html .= '<td class="pdf-metric-cell"><div class="pdf-metric-label">' . safeHtml($label) . '</div><div class="pdf-metric-value">' . safeHtml($value) . '</div></td>';
        $i++;
    }
    while ($i % 4 !== 0) { $html .= '<td class="pdf-metric-cell"></td>'; $i++; }
    $html .= '</tr></table>';
    return $html;
}

function pdf_table_section(string $title, array $headers, array $rows, ?string $note = null): string {
    $html = '<div class="pdf-section-title">' . safeHtml($title) . '</div>';
    if (empty($rows)) {
        $html .= '<p class="pdf-empty">No records found.</p>';
        return $html;
    }
    $html .= '<table class="pdf-data"><thead><tr>';
    foreach ($headers as $h) { $html .= '<th>' . safeHtml($h) . '</th>'; }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $cellStr = is_array($cell) ? json_encode($cell) : (string)$cell;
            $html .= '<td>' . safeHtml(strlen($cellStr) > 60 ? substr($cellStr, 0, 60) . '…' : $cellStr) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    if ($note) { $html .= '<p class="pdf-note">' . safeHtml($note) . '</p>'; }
    return $html;
}

function pdf_page_shell(string $title, string $subtitle, string $preparedBy, string $bodyHtml): string {
    $generated = date('Y-m-d H:i:s');
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: 'Helvetica', 'Arial', sans-serif; color: #0B1B2B; font-size: 11px; }
    .pdf-header { border-bottom: 3px solid #C9A227; padding-bottom: 10px; margin-bottom: 18px; }
    .pdf-header .brand { font-size: 18px; font-weight: bold; }
    .pdf-header .brand span { color: #C9A227; font-weight: normal; }
    .pdf-header .report-title { font-size: 15px; font-weight: bold; margin-top: 8px; }
    .pdf-header .report-subtitle { font-size: 11px; color: #555; margin-top: 2px; }
    .pdf-header .report-meta { font-size: 9px; color: #888; margin-top: 6px; }
    .pdf-section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #9A7B1E; margin: 16px 0 6px; border-bottom: 1px solid #D3DAD6; padding-bottom: 3px; }
    table.pdf-metrics { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.pdf-metrics td.pdf-metric-cell { border: 1px solid #D3DAD6; padding: 8px; width: 25%; }
    .pdf-metric-label { font-size: 8px; text-transform: uppercase; color: #8A96A3; }
    .pdf-metric-value { font-size: 15px; font-weight: bold; margin-top: 3px; }
    table.pdf-data { width: 100%; border-collapse: collapse; font-size: 9px; }
    table.pdf-data th { background: #EEF1EF; text-align: left; padding: 5px 6px; border-bottom: 2px solid #0B1B2B; font-size: 8px; text-transform: uppercase; }
    table.pdf-data td { padding: 5px 6px; border-bottom: 1px solid #D3DAD6; }
    .pdf-empty { color: #8A96A3; font-style: italic; }
    .pdf-note { font-size: 8px; color: #8A96A3; margin-top: 6px; }
    .pdf-footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 8px; color: #8A96A3; text-align: center; border-top: 1px solid #D3DAD6; padding-top: 4px; }
</style>
</head>
<body>
    <div class="pdf-header">
        <div class="brand">VOUCHMORPH <span>Admin</span></div>
        <div class="report-title">{$title}</div>
        <div class="report-subtitle">{$subtitle}</div>
        <div class="report-meta">Prepared by {$preparedBy} &middot; Generated {$generated}</div>
    </div>
    {$bodyHtml}
    <div class="pdf-footer">VouchMorph &middot; Bank of Botswana Regulatory Sandbox Participant &middot; Generated {$generated}</div>
</body>
</html>
HTML;
}

function pdf_stream(string $html, string $filename): void {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

try {
    $db = DBConnection::getConnection();
    if (!$db) throw new Exception("Database connection failed");
    $db->query("SELECT 1");
} catch (Throwable $e) {
    error_log('[ADMIN DASHBOARD] database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('The admin dashboard cannot reach its database right now. This has been logged.');
}

$view = $_GET['view'] ?? 'dashboard';
$search = trim($_GET['search'] ?? '');
$lookup = trim($_GET['lookup'] ?? '');
$reportKey = $_GET['report'] ?? '';
$reportFormat = $_GET['format'] ?? '';

// One-shot message carried across the POST/Redirect/GET below.
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

// Views this page knows about. A known view the role may not open is
// refused and recorded; see the ACCESS DENIED block at the bottom.
$knownViews = ['dashboard', 'client_lookup', 'alerts', 'live_transactions', 'multi_destination', 'recent_swaps', 'institution_health', 'regulatory', 'audit', 'ledger', 'invoices', 'all_tables', 'agent_approvals', 'reports', 'participants'];
$accessDenied = !in_array($view, $knownViews, true) || !canView($view) || ($view === 'all_tables' && !$isSuperAdmin);
if ($accessDenied && in_array($view, $knownViews, true)) {
    AdminAudit::recordOrLog($db, $adminId, 'ACCESS_DENIED', 'admin_view', $view,
        ['role_id' => $adminRoleId], AdminAudit::CATEGORY_SECURITY, 'warning');
}

// ============================================================
// AGENT DESTINATION APPROVALS - POST only, CSRF-checked.
//
// Agents are users with the 'agent' role; what needs an admin decision
// is an agent's destination account that the institution could not
// verify automatically (agent_destination_accounts, status
// 'pending_confirmation'). The old code updated an `agents` table that
// no migration creates, and wrote its audit row to columns audit_logs
// does not have (admin_id, target_type...) inside an empty catch, so
// every decision went unrecorded.
//
// Now: the status change and its audit row are one transaction. If the
// audit row cannot be written, the decision is rolled back - an
// unrecorded approval never happens. Rejections need a written reason.
// POST/Redirect/GET so a refresh never re-submits.
// ============================================================
if ($view === 'agent_approvals' && canView('agent_approvals') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $destinationId = (int)($_POST['agent_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $csrfOk = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);

    if (!$csrfOk) {
        AdminAudit::recordOrLog($db, $adminId, 'CSRF_REJECTED', 'agent_destination', (string)$destinationId,
            ['attempted_action' => $action], AdminAudit::CATEGORY_SECURITY, 'warning');
        $_SESSION['admin_flash'] = ['type' => 'bad', 'text' => 'Your session form expired. Nothing was changed; please try again.'];
    } elseif ($destinationId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $_SESSION['admin_flash'] = ['type' => 'bad', 'text' => 'Invalid request. Nothing was changed.'];
    } elseif ($action === 'reject' && mb_strlen($reason) < 5) {
        $_SESSION['admin_flash'] = ['type' => 'bad', 'text' => 'A rejection needs a reason of at least 5 characters. Nothing was changed.'];
    } else {
        $newStatus = $action === 'approve' ? 'active' : 'rejected';
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("
                SELECT id, user_id, institution, asset_type, identifier, status
                FROM agent_destination_accounts
                WHERE id = :id AND deleted_at IS NULL
                FOR UPDATE
            ");
            $stmt->execute([':id' => $destinationId]);
            $dest = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$dest) {
                throw new RuntimeException('That application no longer exists.');
            }
            if ($dest['status'] !== 'pending_confirmation') {
                throw new RuntimeException("That application was already decided (status: {$dest['status']}).");
            }
            $upd = $db->prepare("
                UPDATE agent_destination_accounts
                SET status = :status,
                    confirmed_at = CASE WHEN :status2 = 'active' THEN NOW() ELSE confirmed_at END,
                    updated_at = NOW()
                WHERE id = :id AND status = 'pending_confirmation'
            ");
            $upd->execute([':status' => $newStatus, ':status2' => $newStatus, ':id' => $destinationId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('The application changed while you were deciding. Nothing was changed.');
            }
            AdminAudit::record(
                $db, $adminId,
                $newStatus === 'active' ? 'AGENT_DESTINATION_APPROVED' : 'AGENT_DESTINATION_REJECTED',
                'agent_destination', (string)$destinationId,
                ['status' => $dest['status']],
                [
                    'status' => $newStatus,
                    'reason' => $reason !== '' ? $reason : null,
                    'agent_user_id' => (int)$dest['user_id'],
                    'institution' => $dest['institution'],
                    'asset_type' => $dest['asset_type'],
                    'identifier' => AdminAudit::mask((string)$dest['identifier']),
                ],
                $newStatus === 'rejected' ? 'warning' : 'info'
            );
            $db->commit();
            $_SESSION['admin_flash'] = ['type' => 'good', 'text' => $newStatus === 'active'
                ? 'Approved and recorded in the audit log.'
                : 'Rejected and recorded in the audit log.'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[ADMIN DASHBOARD] agent decision failed: ' . $e->getMessage());
            $_SESSION['admin_flash'] = ['type' => 'bad', 'text' => 'Nothing was changed: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'the decision or its audit record could not be saved.')];
        }
    }
    header('Location: ?view=agent_approvals');
    exit;
}

// ============================================================
// AJAX HANDLER — the live-transactions auto-refresh fetches
// ?ajax=1 and expects JSON back. Must run before any HTML is
// emitted, and must exit before reaching the <!DOCTYPE> below.
// ============================================================
if (isset($_GET['ajax']) && $view === 'live_transactions' && canView('live_transactions')) {
    $ajaxRows = [];
    try {
        $checkStmt = $db->query("SELECT to_regclass('vw_all_swaps')");
        if ($checkStmt->fetchColumn()) {
            $stmt = $db->prepare("
                SELECT swap_reference, reference, swap_type, source_institution,
                       destination_institution, amount, currency, status, fee_amount, created_at
                FROM vw_all_swaps
                WHERE swap_reference ILIKE :search1 OR reference ILIKE :search2
                   OR source_institution ILIKE :search3 OR destination_institution ILIKE :search4
                   OR status ILIKE :search5
                ORDER BY created_at DESC
                LIMIT 200
            ");
            $likeSearch = '%' . $search . '%';
            $stmt->execute([
                ':search1' => $likeSearch, ':search2' => $likeSearch,
                ':search3' => $likeSearch, ':search4' => $likeSearch,
                ':search5' => $likeSearch,
            ]);
            $ajaxRows = dedupeSwapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
            foreach ($ajaxRows as &$ajaxRow) {
                $ajaxRow['amount'] = (float)($ajaxRow['amount'] ?? 0);
                $ajaxRow['fee_amount'] = (float)($ajaxRow['fee_amount'] ?? 0);
            }
            unset($ajaxRow);
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] AJAX live_transactions error: " . $e->getMessage());
    }
    header('Content-Type: application/json');
    echo json_encode(['transactions' => $ajaxRows]);
    exit;
}

// ============================================================
// FETCH DATA
// ============================================================

$liveTransactions = [];
$liveStats = ['total' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0, 'total_amount' => 0];
try {
    $checkStmt = $db->query("SELECT to_regclass('vw_all_swaps')");
    if ($checkStmt->fetchColumn()) {
        $stmt = $db->prepare("
            SELECT swap_reference, reference, swap_type, source_institution,
                   destination_institution, amount, currency, status, fee_amount, created_at
            FROM vw_all_swaps
            WHERE swap_reference ILIKE :search1 OR reference ILIKE :search2
               OR source_institution ILIKE :search3 OR destination_institution ILIKE :search4
               OR status ILIKE :search5
            ORDER BY created_at DESC
        ");
        $likeSearch = '%' . $search . '%';
        $stmt->execute([
            ':search1' => $likeSearch, ':search2' => $likeSearch,
            ':search3' => $likeSearch, ':search4' => $likeSearch,
            ':search5' => $likeSearch,
        ]);
        $liveTransactions = dedupeSwapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
        // vw_all_swaps has one row per event (hold placed, identity check,
        // etc.), not one per transaction, so COUNT(*) here would overcount
        // relative to the deduped feed above — count distinct references
        // instead, and take the latest row per reference for the status
        // breakdown so a transaction isn't double-counted across an
        // in-progress and a since-resolved status.
        $statStmt = $db->query("
            SELECT COUNT(*) as total,
                   COUNT(CASE WHEN status ILIKE '%completed%' OR status ILIKE '%success%' OR status ILIKE '%debited%' THEN 1 END) as completed,
                   COUNT(CASE WHEN status ILIKE '%pending%' OR status ILIKE '%processing%' THEN 1 END) as pending,
                   COUNT(CASE WHEN status ILIKE '%failed%' OR status ILIKE '%error%' THEN 1 END) as failed,
                   COALESCE(SUM(amount), 0) as total_amount
            FROM (
                SELECT DISTINCT ON (COALESCE(swap_reference, reference))
                    COALESCE(swap_reference, reference) AS dedup_ref, status, amount
                FROM vw_all_swaps
                WHERE created_at >= NOW() - INTERVAL '24 hours'
                ORDER BY COALESCE(swap_reference, reference), created_at DESC
            ) latest_per_reference
        ");
        $liveStats = $statStmt->fetch(PDO::FETCH_ASSOC);
        $liveStats['total'] = (int)($liveStats['total'] ?? 0);
        $liveStats['completed'] = (int)($liveStats['completed'] ?? 0);
        $liveStats['pending'] = (int)($liveStats['pending'] ?? 0);
        $liveStats['failed'] = (int)($liveStats['failed'] ?? 0);
        $liveStats['total_amount'] = (float)($liveStats['total_amount'] ?? 0);
    }
} catch (Throwable $e) { dashError('vw_all_swaps', $e); }

$recentSwaps = [];
try {
    $checkStmt = $db->query("SELECT to_regclass('vw_all_swaps')");
    if ($checkStmt->fetchColumn()) {
        $stmt = $db->prepare("
            SELECT swap_reference, reference, swap_type, source_institution,
                   destination_institution, amount, currency, status, fee_amount, created_at
            FROM vw_all_swaps
            WHERE swap_reference ILIKE :search1 OR reference ILIKE :search2
               OR source_institution ILIKE :search3 OR destination_institution ILIKE :search4
               OR status ILIKE :search5
            ORDER BY created_at DESC
        ");
        $likeSearch = '%' . $search . '%';
        $stmt->execute([
            ':search1' => $likeSearch, ':search2' => $likeSearch,
            ':search3' => $likeSearch, ':search4' => $likeSearch,
            ':search5' => $likeSearch,
        ]);
        $recentSwaps = dedupeSwapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Throwable $e) { dashError('vw_all_swaps', $e); }

$multiDestinationSwaps = [];
try {
    $stmt = $db->query("
        SELECT id, reference, source_institution, total_destinations, successful_count,
               failed_count, total_amount, total_fees, status, created_at,
               destinations_payload, results_payload
        FROM multi_destination_swaps ORDER BY created_at DESC
    ");
    $multiDestinationSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { dashError('multi_destination_swaps', $e); }

$alerts = ['stuck_holds' => [], 'expired_identity_swaps' => [], 'stuck_cashouts' => []];
$totalAlerts = 0;
try {
    $stmt = $db->query("
        SELECT hold_id, hold_reference, swap_reference, participant_name AS institution,
               amount, currency, status, created_at
        FROM hold_transactions
        WHERE status IN ('ACTIVE','HELD','PENDING_CASHOUT','PENDING_IDENTITY')
          AND created_at < NOW() - INTERVAL '20 hours'
        ORDER BY created_at ASC LIMIT 300
    ");
    $alerts['stuck_holds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalAlerts += count($alerts['stuck_holds']);
} catch (Throwable $e) { dashError('hold_transactions', $e); }
try {
    $stmt = $db->query("
        SELECT swap_reference, source_institution, identity_type, identity_value,
               amount, currency, hold_expires_at, status
        FROM identity_swap_holds
        WHERE status = 'pending' AND hold_expires_at < NOW()
        ORDER BY hold_expires_at ASC LIMIT 300
    ");
    $alerts['expired_identity_swaps'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalAlerts += count($alerts['expired_identity_swaps']);
} catch (Throwable $e) { dashError('identity_swap_holds', $e); }
try {
    $stmt = $db->query("
        SELECT swap_reference, client_phone, source_institution, cashout_provider,
               amount, currency, code_expiry, status
        FROM cashout_authorizations
        WHERE status = 'PENDING' AND code_expiry < NOW()
        ORDER BY code_expiry ASC LIMIT 300
    ");
    $alerts['stuck_cashouts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalAlerts += count($alerts['stuck_cashouts']);
} catch (Throwable $e) { dashError('cashout_authorizations', $e); }

$institutionHealth = [];
try {
    $stmt = $db->query("
        SELECT inst AS institution, COUNT(*) AS total,
               COUNT(*) FILTER (WHERE status ILIKE '%completed%' OR status ILIKE '%success%') AS successful,
               COALESCE(SUM(amount), 0) AS volume
        FROM (
            SELECT source_institution AS inst, status, amount FROM vw_all_swaps
            UNION ALL
            SELECT destination_institution AS inst, status, amount FROM vw_all_swaps
        ) combined GROUP BY inst ORDER BY total DESC
    ");
    $institutionHealth = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($institutionHealth as &$row) {
        $row['total'] = (int)($row['total'] ?? 0);
        $row['successful'] = (int)($row['successful'] ?? 0);
        $row['volume'] = (float)($row['volume'] ?? 0);
        $row['success_rate'] = $row['total'] > 0 ? round(($row['successful'] / $row['total']) * 100, 1) : 0.0;
    }
    unset($row);
} catch (Throwable $e) { dashError('vw_all_swaps', $e); }

$metrics = [];
try {
    $metrics['total_users'] = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $metrics['total_swaps'] = (int)$db->query("SELECT COUNT(*) FROM vw_all_swaps")->fetchColumn();
    $metrics['pending_settlements'] = (int)$db->query("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'")->fetchColumn();
    $metrics['total_fees'] = (float)$db->query("SELECT COALESCE(SUM((message_payload->>'fee_amount')::numeric), 0) FROM settlement_outbox WHERE message_type = 'FEE_INVOICE'")->fetchColumn();
    $metrics['recent_swaps_24h'] = (int)$db->query("SELECT COUNT(*) FROM vw_all_swaps WHERE created_at >= NOW() - INTERVAL '24 hours'")->fetchColumn();
    $metrics['multi_destination_count'] = (int)$db->query("SELECT COUNT(*) FROM multi_destination_swaps")->fetchColumn();
} catch (Throwable $e) {
    $metrics = array_fill_keys(['total_users', 'total_swaps', 'pending_settlements', 'total_fees', 'recent_swaps_24h', 'multi_destination_count'], 0);
}

$lookupResults = [];
if ($view === 'client_lookup' && $lookup !== '') {
    $likeLookup = '%' . $lookup . '%';
    try {
        $stmt = $db->prepare("
            SELECT swap_reference, source_institution, identity_type, identity_value,
                   amount, currency, hold_expires_at, status, created_at
            FROM identity_swap_holds
            WHERE identity_value ILIKE :l OR swap_reference ILIKE :l
            ORDER BY created_at DESC LIMIT 25
        ");
        $stmt->execute([':l' => $likeLookup]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isExpired = strtotime($row['hold_expires_at'] ?? 'now') < time();
            $lookupResults[] = [
                'kind' => 'Identity Swap',
                'reference' => $row['swap_reference'],
                'institution' => $row['source_institution'],
                'amount' => $row['amount'],
                'currency' => $row['currency'] ?? 'BWP',
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'next_action' => $row['status'] === 'pending' ? ($isExpired ? 'EXPIRED - Needs cancellation.' : 'Awaiting confirmation.') : 'Completed.',
                'identity' => ($row['identity_type'] ?? '') . ': ' . ($row['identity_value'] ?? ''),
            ];
        }
    } catch (Throwable $e) { dashError('client lookup: identity_swap_holds', $e); }
    try {
        $stmt = $db->prepare("
            SELECT swap_reference, client_phone, source_institution, cashout_provider,
                   amount, currency, code_expiry, status, created_at
            FROM cashout_authorizations
            WHERE client_phone ILIKE :l OR swap_reference ILIKE :l
            ORDER BY created_at DESC LIMIT 25
        ");
        $stmt->execute([':l' => $likeLookup]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isExpired = strtotime($row['code_expiry'] ?? 'now') < time();
            $lookupResults[] = [
                'kind' => 'Cashout',
                'reference' => $row['swap_reference'],
                'institution' => $row['source_institution'] . ' → ' . $row['cashout_provider'],
                'amount' => $row['amount'],
                'currency' => $row['currency'] ?? 'BWP',
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'next_action' => $row['status'] === 'PENDING' ? ($isExpired ? 'EXPIRED - New swap needed.' : 'Active - Client must cash out.') : 'Completed.',
                'identity' => 'Phone: ' . ($row['client_phone'] ?? 'N/A'),
            ];
        }
    } catch (Throwable $e) { dashError('client lookup: cashout_authorizations', $e); }
}

if ($view === 'client_lookup' && $lookup !== '' && canView('client_lookup')) {
    AdminAudit::recordOrLog($db, $adminId, 'CLIENT_LOOKUP', 'client', AdminAudit::mask($lookup),
        ['results' => count($lookupResults)]);
}

$auditRows = [];
if ($view === 'audit' && canView('audit')) {
    try { $auditRows = $db->query("SELECT * FROM audit_logs ORDER BY audit_id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { dashError('audit_logs', $e); }
}

// --- AUDIT CHAIN INTEGRITY CHECK ---
// $chainState is 'intact' | 'broken' | 'unknown'. The chain is built and
// checked by the database itself (2026_09_19_audit_chain_enforced.sql),
// covering every column of every row, so every writer is included and
// the result does not depend on PHP time zones. 'unknown' means the check
// could not run, which is never shown as a pass.
$chainState = 'unknown';
$chainError = null;
$chainInfo = null;
if ($view === 'audit' && canView('audit')) {
    $chainInfo = AdminAudit::verifyChain($db);
    $chainState = $chainInfo['state'];
    $chainError = $chainInfo['error'];
    AdminAudit::recordOrLog($db, $adminId, 'AUDIT_CHAIN_VERIFIED', 'audit_chain', 'audit_logs',
        ['result' => $chainState, 'rows' => $chainInfo['total'], 'head_seq' => $chainInfo['head_seq'], 'head_hash' => $chainInfo['head_hash']],
        AdminAudit::CATEGORY_SECURITY);
}

$netPositions = [];
$pendingSettlements = [];
if ($view === 'regulatory' && canView('regulatory')) {
    try { $netPositions = $db->query("SELECT * FROM net_positions ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { dashError('net_positions', $e); }
    try { $pendingSettlements = $db->query("SELECT * FROM settlement_queue WHERE status = 'PENDING' ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { dashError('settlement_queue', $e); }
}

// ============================================================
// INVOICES AND DESTINATION SETTLEMENT
// Each fee invoice is paired with the settlement confirmation for the
// same swap, so one row answers both questions: has the fee been paid
// to VouchMorph, and has the destination received its money from the
// source? Labels:
//   Invoiced | Fee paid                               (settlement_outbox.status)
//   Destination not settled | ... overdue | Destination settled | Settlement failed | Not tracked
// "Overdue" comes from settlement_confirmations.overdue_at, set by
// poll_settlement_confirmations.php after the next business day at noon.
// ============================================================
$invoiceMessages = [];
$invoiceSummary = ['count' => 0, 'fees_total' => 0.0, 'fees_paid' => 0.0, 'not_settled' => 0, 'overdue' => 0, 'settled' => 0, 'failed' => 0, 'untracked' => 0];
$settleFilter = $_GET['settle'] ?? 'all';
function settlementLabel(array $r): array {
    $st = strtoupper((string)($r['settle_status'] ?? ''));
    if ($st === '') return ['Not tracked', 'info', 'untracked'];
    if ($st === 'CONFIRMED') return ['Destination settled', 'success', 'settled'];
    if ($st === 'FAILED') return ['Settlement failed', 'failed', 'failed'];
    if (!empty($r['overdue_at'])) return ['Destination not settled · overdue', 'failed', 'overdue'];
    return ['Destination not settled', 'pending', 'not_settled'];
}
function invoiceLabel(array $r): array {
    return strtoupper((string)($r['invoice_status'] ?? '')) === 'ACKNOWLEDGED' ? ['Fee paid', 'success'] : ['Invoiced', 'pending'];
}
if ($view === 'invoices' && canView('invoices')) {
    try {
        $invoiceMessages = $db->query("
            SELECT so.message_uuid, so.swap_reference, so.source_institution AS invoiced_institution,
                   so.amount, so.currency, so.status AS invoice_status, so.created_at, so.acknowledged_at,
                   (so.message_payload->>'fee_type') AS fee_type,
                   sc.destination_institution, sc.status AS settle_status, sc.overdue_at, sc.confirmed_at,
                   sc.confirmation_mode, sc.last_attempt_at, sc.last_error
            FROM settlement_outbox so
            LEFT JOIN settlement_confirmations sc ON sc.swap_reference = so.swap_reference
            WHERE so.message_type = 'FEE_INVOICE'
            ORDER BY so.created_at DESC
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        dashError('invoices with settlement status', $e);
        try {
            $invoiceMessages = $db->query("
                SELECT message_uuid, swap_reference, source_institution AS invoiced_institution, amount, currency,
                       status AS invoice_status, created_at, acknowledged_at, (message_payload->>'fee_type') AS fee_type
                FROM settlement_outbox WHERE message_type = 'FEE_INVOICE' ORDER BY created_at DESC LIMIT 500
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) { dashError('settlement_outbox', $e2); }
    }
    foreach ($invoiceMessages as $r) {
        $invoiceSummary['count']++;
        $invoiceSummary['fees_total'] += (float)$r['amount'];
        if (invoiceLabel($r)[0] === 'Fee paid') $invoiceSummary['fees_paid'] += (float)$r['amount'];
        $invoiceSummary[settlementLabel($r)[2]]++;
    }
    if ($settleFilter !== 'all') {
        $invoiceMessages = array_values(array_filter($invoiceMessages, fn($r) => settlementLabel($r)[2] === $settleFilter));
    }
    if (($_GET['format'] ?? '') === 'csv') {
        AdminAudit::recordOrLog($db, $adminId, 'REPORT_EXPORTED', 'report', 'invoices_settlement', ['format' => 'csv', 'filter' => $settleFilter]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="vouchmorph_invoices_settlement_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Invoice', 'Swap reference', 'Invoiced institution', 'Fee type', 'Amount', 'Currency', 'Fee status', 'Destination', 'Destination settlement', 'Invoiced at', 'Fee paid at', 'Settled at', 'Overdue since']);
        foreach ($invoiceMessages as $r) {
            fputcsv($out, [$r['message_uuid'], $r['swap_reference'], $r['invoiced_institution'], $r['fee_type'] ?? '', $r['amount'], $r['currency'],
                invoiceLabel($r)[0], $r['destination_institution'] ?? '', settlementLabel($r)[0], $r['created_at'], $r['acknowledged_at'] ?? '', $r['confirmed_at'] ?? '', $r['overdue_at'] ?? '']);
        }
        fclose($out);
        exit;
    }
}

// ============================================================
// LEDGER RECONCILIATION — fetch only when on the ledger view
// ============================================================
$ledgerReconciliation = [];
if ($view === 'ledger' && canView('ledger')) {
    try {
        $ledgerReconciliation = $db->query("
            SELECT swap_reference,
                   COALESCE(SUM(amount) FILTER (WHERE leg = 'SOURCE_DEBIT'), 0) AS debited,
                   COALESCE(SUM(amount) FILTER (WHERE leg = 'DEST_CREDIT'), 0) AS credited,
                   COALESCE(SUM(amount) FILTER (WHERE leg = 'FEE_RECEIVABLE'), 0) AS fees,
                   MAX(posted_at) AS last_posted
            FROM ledger_entries
            GROUP BY swap_reference
            HAVING COALESCE(SUM(amount) FILTER (WHERE leg = 'SOURCE_DEBIT'), 0)
                <> COALESCE(SUM(amount) FILTER (WHERE leg = 'DEST_CREDIT'), 0) + COALESCE(SUM(amount) FILTER (WHERE leg = 'FEE_RECEIVABLE'), 0)
            ORDER BY last_posted DESC LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { dashError('ledger_entries', $e); }
}

// ============================================================
// AGENT DESTINATION APPROVALS DATA - agent_destination_accounts joined to
// the agent's user record. Shapes rows the way the view below expects.
// ============================================================
$pendingAgents = [];
$allAgents = [];
$agentCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
if (canView('agent_approvals')) {
    $agentSelect = "
        SELECT a.id, a.user_id, a.institution, a.asset_type, a.identifier, a.account_name,
               a.account_type, a.status, a.proposed_at AS created_at, a.confirmed_at AS approved_at,
               u.username AS full_name, u.phone, u.email
        FROM agent_destination_accounts a
        LEFT JOIN users u ON u.user_id = a.user_id
        WHERE a.deleted_at IS NULL
    ";
    try {
        $pendingAgents = $db->query($agentSelect . " AND a.status = 'pending_confirmation' ORDER BY a.proposed_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { dashError('agent approvals: pending list', $e); }
    if ($view === 'agent_approvals') {
        try {
            $allAgents = $db->query($agentSelect . " ORDER BY a.proposed_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('agent approvals: registry', $e); }
        try {
            $statusMap = ['pending_confirmation' => 'pending', 'active' => 'approved', 'rejected' => 'rejected'];
            $countRows = $db->query("SELECT status, COUNT(*) AS c FROM agent_destination_accounts WHERE deleted_at IS NULL GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($countRows as $cr) {
                $key = $statusMap[strtolower($cr['status'] ?? '')] ?? null;
                if ($key !== null) $agentCounts[$key] = (int)$cr['c'];
            }
        } catch (Throwable $e) { dashError('agent approvals: counts', $e); }
    }
}
$agentApprovalCount = count($pendingAgents);

// ============================================================
// PARTICIPANTS — read-only view of every configured institution's
// onboarding status, plus an inline validator (same checks as the
// standalone onboarding_validator.php CLI script) runnable with one
// click. Deliberately does NOT write to participants.yaml from this
// page: promoting an institution's status controls literal money
// routing (see resolveSettlementRoute() in switch_settlement_architecture.md),
// so that stays a reviewed config change, not a button in a web UI.
// ============================================================
$participantsList = [];
$participantsCountry = $adminCountry ?: 'Botswana';
$validateInstitution = trim($_GET['validate'] ?? '');
$validationResult = null;

if (canView('participants')) {
    try {
        $countryConfigForParticipants = LoadCountry::getConfig($participantsCountry);
        $participantsList = $countryConfigForParticipants['participants'] ?? $countryConfigForParticipants ?? [];
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] participants load error: " . $e->getMessage());
    }

    if ($view === 'participants' && $validateInstitution !== '' && isset($participantsList[$validateInstitution])) {
        $validationResult = validateParticipantConfig($validateInstitution, $participantsList[$validateInstitution], $participantsCountry);
    }
}

/**
 * Same checks as onboarding_validator.php, ported inline so ops/
 * compliance can run them from the dashboard without a terminal.
 * Read-only: inspects config + env vars only, never writes anything.
 */
function validateParticipantConfig(string $code, array $participant, string $country): array
{
    $errors = [];
    $warnings = [];
    $passed = [];

    $add = function (bool $ok, string $label) use (&$errors, &$passed) {
        if ($ok) { $passed[] = $label; } else { $errors[] = $label; }
    };

    $add(!empty($participant['name'] ?? ''), "'name' present");
    $add(in_array($participant['type'] ?? null, ['BANK', 'MNO', 'SWITCH'], true), "'type' is BANK/MNO/SWITCH");
    $add(in_array($participant['status'] ?? null, ['sandbox', 'staging', 'live'], true), "'status' is sandbox/staging/live");
    $add(!empty($participant['asset_types'] ?? []), "'asset_types' non-empty");
    $add(!empty($participant['adapter'] ?? ''), "'adapter' specified");
    $add(!empty($participant['credentials_env_prefix'] ?? ''), "'credentials_env_prefix' specified");

    if (($participant['type'] ?? null) === 'SWITCH') {
        $add(($participant['adapter'] ?? '') !== 'generic_bank', "SWITCH type does not use the plain generic_bank adapter");
    }

    $prefix = $participant['credentials_env_prefix'] ?? null;
    if ($prefix) {
        $foundAny = false;
        $checked = [];
        foreach (['API_KEY', 'SUBSCRIPTION_KEY', 'API_USER'] as $suffix) {
            $envName = "{$prefix}_{$suffix}";
            $checked[] = $envName;
            if (getenv($envName) !== false) $foundAny = true;
        }
        $add($foundAny, "At least one credential env var is set");
        if (!$foundAny) {
            $warnings[] = "Checked: " . implode(', ', $checked) . " — none found. Confirm the real auth scheme's env var names if different.";
        }
    } else {
        $errors[] = "Cannot check credentials — credentials_env_prefix missing";
    }

    return [
        'institution' => $code,
        'country' => $country,
        'current_status' => $participant['status'] ?? 'sandbox',
        'passed' => $passed,
        'warnings' => $warnings,
        'errors' => $errors,
        'ready' => empty($errors),
    ];
}

// ============================================================
// REPORTS — each report is self-contained: it fetches only what
// it needs, fails gracefully to an empty state, and (aside from
// the executive summary, which reuses $metrics/$institutionHealth
// already fetched above) is scoped to when it's actually requested.
// CSV export short-circuits before any HTML is emitted.
// ============================================================

$reportCatalog = [
    'transaction_certificate' => ['group' => 'Trust & Integrity', 'title' => 'Transaction Certificate',              'blurb' => 'The complete, signed timeline for one transaction — proof for the client, the bank, and the regulator.'],
    'double_spend_check'      => ['group' => 'Trust & Integrity', 'title' => 'Double-Spend & Duplicate-Debit Check',  'blurb' => 'Verifies every hold was debited at most once and every debit maps to exactly one hold.'],
    'bank_statement'          => ['group' => 'Trust & Integrity', 'title' => 'Partner Bank Statement',                'blurb' => 'Per-institution transaction list, fees, and net settlement position — a bank\'s own statement.'],
    'executive_summary'  => ['group' => 'Executive',  'title' => 'Executive Summary',            'blurb' => 'One-page snapshot of volume, revenue, and network health.'],
    'trust_scorecard'     => ['group' => 'Executive',  'title' => 'Institutional Trust Scorecard', 'blurb' => 'Success rate ranking and tiering across every connected institution.'],
    'net_settlement'      => ['group' => 'Regulatory', 'title' => 'Net Settlement Position',       'blurb' => 'Net obligations between institutions, for regulatory review.'],
    'fee_revenue'         => ['group' => 'Finance',    'title' => 'Fee Revenue Summary',           'blurb' => 'Fee income by institution, sourced from settlement invoicing.'],
    'audit_export'        => ['group' => 'Audit',      'title' => 'Audit Trail Export',            'blurb' => 'Full audit log, exportable to CSV for external review.'],
    'suspicious_activity' => ['group' => 'Compliance', 'title' => 'Suspicious Activity (AML/KYC)', 'blurb' => 'Flagged transactions, high-risk users, and stale holds — restricted to compliance-facing roles.'],
    'flow_type_breakdown'    => ['group' => 'Reconciliation', 'title' => 'Flow Type Breakdown',    'blurb' => 'Count, success rate, and average duration per swap type — account/wallet/e-wallet/card/voucher origins to account/wallet/e-wallet/card/cashout destinations.'],
    'institution_settlement_summary' => ['group' => 'Reconciliation', 'title' => 'Institution Settlement Summary', 'blurb' => 'Per institution, per period: amount issued, amount received, net owed, and fees earned — the end-of-day/week/month settlement picture.'],
    'daily_reconciliation'   => ['group' => 'Reconciliation', 'title' => 'Daily Reconciliation',   'blurb' => 'Transaction totals by day for the last 30 days — volume, fees, and outcome counts.'],
    'weekly_reconciliation'  => ['group' => 'Reconciliation', 'title' => 'Weekly Reconciliation',  'blurb' => 'Transaction totals by week for the last 12 weeks.'],
    'monthly_reconciliation' => ['group' => 'Reconciliation', 'title' => 'Monthly Reconciliation', 'blurb' => 'Transaction totals by month for the last 12 months.'],
];

$reportNetPositions = [];
$reportFeeRevenue = [];
$reportAuditRows = [];
$reportReconciliation = [];
$reportFlowBreakdown = [];
$reportInstitutionSettlement = [];
$settlementPeriod = $_GET['period'] ?? 'daily'; // daily | weekly | monthly
$certData = null;
$certRef = trim($_GET['ref'] ?? '');
$integrityIssues = [];
$integrityFailed = [];
$integrityTotalIssues = 0;
$bankStatement = null;
$bankInstitution = trim($_GET['institution'] ?? '');

// Maps a report key to the SQL date_trunc unit and lookback window used
// for the three reconciliation reports below.
$reconciliationConfig = [
    'daily_reconciliation'   => ['unit' => 'day',   'window' => '30 days',  'label' => 'Day'],
    'weekly_reconciliation'  => ['unit' => 'week',  'window' => '12 weeks', 'label' => 'Week'],
    'monthly_reconciliation' => ['unit' => 'month', 'window' => '12 months', 'label' => 'Month'],
];

// Same time buckets, reused by the Institution Settlement Summary report
// (which needs a period switcher rather than three separate report keys).
$settlementPeriodConfig = [
    'daily'   => ['unit' => 'day',   'window' => '30 days',  'label' => 'Day'],
    'weekly'  => ['unit' => 'week',  'window' => '12 weeks', 'label' => 'Week'],
    'monthly' => ['unit' => 'month', 'window' => '12 months', 'label' => 'Month'],
];

if ($view === 'reports' && canView('reports') && $reportKey !== '' && isset($reportCatalog[$reportKey])) {
    // Who opened or exported which report, with what filters. Recorded
    // before any export branch below streams a file and exits.
    AdminAudit::recordOrLog($db, $adminId,
        in_array($reportFormat, ['csv', 'pdf'], true) ? 'REPORT_EXPORTED' : 'REPORT_VIEWED',
        'report', $reportKey,
        array_filter([
            'format' => $reportFormat ?: 'html',
            'reference' => $certRef ?: null,
            'institution' => $bankInstitution ?: null,
            'period' => $reportKey === 'institution_settlement_summary' ? $settlementPeriod : null,
        ], fn($v) => $v !== null));

    // ============================================================
    // TRANSACTION CERTIFICATE — the full signed chain for one swap.
    // This is the artifact a bank, a regulator, or a client uses to
    // independently verify a single transaction end to end: what was
    // held, what was generated, what was debited/credited, what was
    // logged, and what was notified — with timestamps at every step.
    // ============================================================
    if ($reportKey === 'transaction_certificate' && $certRef !== '') {
        $certData = ['reference' => $certRef];
        try {
            $stmt = $db->prepare("SELECT * FROM swap_requests WHERE swap_uuid = :ref");
            $stmt->execute([':ref' => $certRef]);
            $certData['swap_request'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { dashError('swap_requests', $e); $certData['swap_request'] = null; }
        try {
            $stmt = $db->prepare("SELECT * FROM hold_transactions WHERE swap_reference = :ref ORDER BY placed_at ASC");
            $stmt->execute([':ref' => $certRef]);
            $certData['holds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('hold_transactions', $e); $certData['holds'] = []; }
        try {
            $stmt = $db->prepare("SELECT * FROM cashout_authorizations WHERE swap_reference = :ref");
            $stmt->execute([':ref' => $certRef]);
            $certData['cashout'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { dashError('cashout_authorizations', $e); $certData['cashout'] = null; }
        try {
            $stmt = $db->prepare("
                SELECT st.* FROM swap_transactions st
                JOIN swap_requests sr ON st.swap_id = sr.swap_id
                WHERE sr.swap_uuid = :ref
            ");
            $stmt->execute([':ref' => $certRef]);
            $certData['swap_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('swap_transactions', $e); $certData['swap_transactions'] = []; }
        // audit_logs.entity_id holds the swap reference. It was declared
        // bigint until 2026_09_16_transaction_audit_integrity.sql widened
        // it, so this comparison used to raise 22P02, get swallowed here,
        // and render "No audit entries recorded" for every transaction the
        // system had ever issued. The LIKE arm additionally picks up the
        // _DEST_n / _ID_n children of a multi-destination batch, which
        // record themselves under sub-references derived from this one.
        try {
            $stmt = $db->prepare("
                SELECT * FROM audit_logs
                WHERE entity_id = :ref OR entity_id LIKE :ref_children
                ORDER BY performed_at ASC
            ");
            $stmt->execute([':ref' => $certRef, ':ref_children' => $certRef . '\_%']);
            $certData['audit'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $certData['audit_query_failed'] = false;
        } catch (Throwable $e) {
            $certData['audit'] = [];
            // Distinguish "nothing to show" from "could not look": the
            // certificate renders these differently.
            $certData['audit_query_failed'] = true;
            error_log('[admin_dashboard] certificate audit lookup failed for ' . $certRef . ': ' . $e->getMessage());
        }
        try {
            $stmt = $db->prepare("SELECT * FROM message_outbox WHERE payload->>'swap_reference' = :ref ORDER BY created_at ASC");
            $stmt->execute([':ref' => $certRef]);
            $certData['messages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('message_outbox', $e); $certData['messages'] = []; }
        try {
            $stmt = $db->prepare("SELECT * FROM settlement_outbox WHERE swap_reference = :ref ORDER BY created_at ASC");
            $stmt->execute([':ref' => $certRef]);
            $certData['settlement'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('settlement_outbox', $e); $certData['settlement'] = []; }

        // Transaction Certificate spec: created_at is the moment the
        // account clicked "Swap" (captured client-side and sent with the
        // request — see SwapService::executeAtomicSwap()'s
        // currentClientInitiatedAt handling — falling back to the
        // server's own beginAtomicSwap() timestamp for callers that don't
        // send one), through to debited_at: the moment the hold was
        // actually debited, not whatever came after it (ledger posting,
        // notifications, etc. all still run after the debit and would
        // otherwise push "Ended" later than the debit itself).
        // hold_transactions.placed_at is checked too only as a safety net
        // for a batch child with no standalone swap_requests row — in the
        // normal case created_at is already the earliest point by
        // construction, so this never overrides it with something later.
        $certData['duration'] = null;
        if (!empty($certData['swap_request'])) {
            $sr = $certData['swap_request'];
            $startEpoch = tsToEpoch($sr['created_at'] ?? '');
            $endEpoch = null;
            foreach ($certData['holds'] as $h) {
                $placedEpoch = tsToEpoch($h['placed_at'] ?? '');
                if ($placedEpoch !== null && ($startEpoch === null || $placedEpoch < $startEpoch)) {
                    $startEpoch = $placedEpoch;
                }
                $debitedEpoch = tsToEpoch($h['debited_at'] ?? '');
                if ($debitedEpoch !== null && ($endEpoch === null || $debitedEpoch > $endEpoch)) {
                    $endEpoch = $debitedEpoch;
                }
            }
            // Only a swap with no hold ever debited (still pending, or a
            // flow with no hold at all) falls back to swap_requests'
            // own completed_at as the end point.
            if ($endEpoch === null) {
                $endEpoch = tsToEpoch($sr['completed_at'] ?? '');
            }
            if ($startEpoch !== null && $endEpoch !== null) {
                $seconds = round($endEpoch - $startEpoch, 3);
                $swapType = strtolower($sr['swap_type'] ?? '');
                $isDeposit = str_contains($swapType, 'deposit');
                $threshold = $isDeposit ? 60 : 90; // Experiment 2 vs Experiment 1 / H1
                $certData['duration'] = [
                    'seconds' => $seconds,
                    'threshold' => $threshold,
                    'pass' => $seconds >= 0 && $seconds <= $threshold,
                    'start' => epochToTs($startEpoch),
                    'end' => epochToTs($endEpoch),
                ];
            }
        }

        // ------------------------------------------------------------
        // BATCH-CHILD FALLBACK — a reference like MIXED_SWAP_1784534859_ID_1
        // will never match swap_requests.swap_uuid, because multi-destination
        // batches parent their children in multi_destination_swaps instead
        // (see destinations_payload/results_payload there, and the
        // Double-Spend & Duplicate-Debit Check's "Debited Holds With No
        // Matching Swap Record" list). Rather than showing a blank
        // 0.00/UNKNOWN/N/A summary for every batch-child certificate,
        // reconstruct a synthetic summary from the parent batch + the
        // specific destination this reference points to, whenever the
        // direct swap_requests lookup came back empty but a hold exists.
        // ------------------------------------------------------------
        $certData['batch_context'] = null;
        if (empty($certData['swap_request']) && !empty($certData['holds'])
            && preg_match('/^(.+)_(DEST|ID)_(\d+)$/', $certRef, $m)) {
            $batchRef = $m[1];
            $destIndex = (int)$m[3];
            try {
                $stmt = $db->prepare("SELECT * FROM multi_destination_swaps WHERE reference = :ref");
                $stmt->execute([':ref' => $batchRef]);
                $batch = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) { dashError('multi_destination_swaps', $e); $batch = null; }

            if ($batch) {
                $destinations = json_decode($batch['destinations_payload'] ?? '[]', true) ?: [];
                $results = json_decode($batch['results_payload'] ?? '[]', true) ?: [];
                $dest = $destinations[$destIndex] ?? null;
                $result = $results[$destIndex] ?? [];

                if ($dest) {
                    $certData['batch_context'] = [
                        'batch_reference' => $batchRef,
                        'destination_index' => $destIndex,
                        'destination_count' => count($destinations),
                    ];
                    // Populate a swap_request-shaped array so the existing
                    // Summary rendering below works unmodified.
                    $certData['swap_request'] = [
                        'amount' => $dest['amount'] ?? 0,
                        'from_currency' => $batch['currency'] ?? 'BWP',
                        'status' => $result['status'] ?? 'pending',
                        'created_at' => $batch['created_at'] ?? null,
                        'completed_at' => null, // batch children don't currently record a per-destination completion timestamp
                        'swap_type' => isset($dest['identity_type']) || isset($dest['identity_value']) ? 'IDENTITY' : 'DEPOSIT',
                    ];
                }
            }
        }

        if ($reportFormat === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vouchmorph_certificate_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $certRef) . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Section', 'Field', 'Value']);
            $flatten = function ($label, $row) use ($out) {
                if (!$row) return;
                foreach ($row as $k => $v) {
                    fputcsv($out, [$label, $k, is_array($v) ? json_encode($v) : $v]);
                }
            };
            $flatten('swap_request', $certData['swap_request']);
            foreach ($certData['holds'] as $i => $h) { $flatten("hold_{$i}", $h); }
            $flatten('cashout', $certData['cashout']);
            foreach ($certData['swap_transactions'] as $i => $t) { $flatten("ledger_entry_{$i}", $t); }
            foreach ($certData['audit'] as $i => $a) { $flatten("audit_{$i}", $a); }
            foreach ($certData['messages'] as $i => $m) { $flatten("notification_{$i}", $m); }
            foreach ($certData['settlement'] as $i => $s) { $flatten("settlement_{$i}", $s); }
            if ($certData['duration']) {
                fputcsv($out, ['duration', 'seconds', $certData['duration']['seconds']]);
                fputcsv($out, ['duration', 'threshold_seconds', $certData['duration']['threshold']]);
                fputcsv($out, ['duration', 'pass', $certData['duration']['pass'] ? 'PASS' : 'FAIL']);
                fputcsv($out, ['duration', 'started_at', fmtTs($certData['duration']['start'])]);
                fputcsv($out, ['duration', 'ended_at', fmtTs($certData['duration']['end'])]);
            }
            fclose($out);
            exit;
        }
    }

    // ============================================================
    // DOUBLE-SPEND & DUPLICATE-DEBIT CHECK — an automated scan of
    // the actual ledger state, independent of what any application
    // code path claims happened. This is the standing answer to a
    // partner bank's or regulator's biggest fear.
    // ============================================================
    if ($reportKey === 'double_spend_check') {
        try {
            $stmt = $db->query("
                SELECT swap_reference, COUNT(*) AS debited_hold_count,
                       array_agg(hold_id) AS hold_ids, array_agg(amount) AS amounts
                FROM hold_transactions
                WHERE status = 'DEBITED'
                GROUP BY swap_reference
                HAVING COUNT(*) > 1
                ORDER BY debited_hold_count DESC
                LIMIT 500
            ");
            $integrityIssues['duplicate_debited_holds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('hold_transactions', $e); $integrityIssues['duplicate_debited_holds'] = []; $integrityFailed['duplicate_debited_holds'] = $e->getMessage(); }

        try {
            $stmt = $db->query("
                SELECT swap_reference, COUNT(*) AS auth_count
                FROM cashout_authorizations
                WHERE status = 'COMPLETED'
                GROUP BY swap_reference
                HAVING COUNT(*) > 1
                LIMIT 500
            ");
            $integrityIssues['duplicate_completed_cashouts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('cashout_authorizations', $e); $integrityIssues['duplicate_completed_cashouts'] = []; $integrityFailed['duplicate_completed_cashouts'] = $e->getMessage(); }

        try {
            $stmt = $db->query("
                SELECT key, COUNT(DISTINCT result::jsonb->>'reference') AS distinct_refs
                FROM idempotency_keys
                GROUP BY key
                HAVING COUNT(DISTINCT result::jsonb->>'reference') > 1
                LIMIT 200
            ");
            $integrityIssues['idempotency_key_conflicts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('idempotency_keys', $e); $integrityIssues['idempotency_key_conflicts'] = []; $integrityFailed['idempotency_key_conflicts'] = $e->getMessage(); }

        try {
            $stmt = $db->query("
                SELECT ht.hold_id, ht.swap_reference, ht.amount, ht.currency, ht.source_institution, ht.placed_at
                FROM hold_transactions ht
                LEFT JOIN swap_requests sr ON sr.swap_uuid = ht.swap_reference
                WHERE ht.status = 'DEBITED' AND sr.swap_id IS NULL
                ORDER BY ht.placed_at DESC LIMIT 500
            ");
            $integrityIssues['debited_holds_missing_swap_request'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('hold_transactions', $e); $integrityIssues['debited_holds_missing_swap_request'] = []; $integrityFailed['debited_holds_missing_swap_request'] = $e->getMessage(); }

        // Completed transactions with no audit record. Every check above
        // keys off hold_transactions.status = 'DEBITED' -- a write that is
        // itself rolled back in the failure modes where nothing got
        // tracked at all. This one keys off the completed swap instead, so
        // it sees the case the others are blind to: the money moved, the
        // reference exists, and the record of who moved it does not.
        try {
            $stmt = $db->query("
                SELECT sr.swap_uuid, sr.amount, sr.from_currency, sr.status, sr.created_at
                FROM swap_requests sr
                LEFT JOIN audit_logs al ON al.entity_id = sr.swap_uuid
                WHERE LOWER(sr.status) = 'completed' AND al.audit_id IS NULL
                ORDER BY sr.created_at DESC LIMIT 500
            ");
            $integrityIssues['completed_swaps_missing_audit'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('swap_requests', $e); $integrityIssues['completed_swaps_missing_audit'] = []; $integrityFailed['completed_swaps_missing_audit'] = $e->getMessage(); }

        // The audit dead letter itself. SwapService::writeAuditFallback()
        // has always written here when a normal audit write could not
        // happen, and nothing has ever read it.
        try {
            $stmt = $db->query("
                SELECT swap_reference, swap_type, reason, created_at
                FROM audit_log_failures
                WHERE resolved_at IS NULL
                ORDER BY created_at DESC LIMIT 500
            ");
            $integrityIssues['audit_write_failures'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('audit_log_failures', $e); $integrityIssues['audit_write_failures'] = []; $integrityFailed['audit_write_failures'] = $e->getMessage(); }

        $integrityTotalIssues = array_sum(array_map('count', $integrityIssues));

        if ($reportFormat === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vouchmorph_integrity_check_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Check', 'Result']);
            foreach ($integrityIssues as $checkName => $rows) {
                if (isset($integrityFailed[$checkName])) {
                    fputcsv($out, [$checkName, 'COULD NOT RUN - result unknown']);
                } elseif (empty($rows)) {
                    fputcsv($out, [$checkName, 'CLEAN - no issues found']);
                } else {
                    foreach ($rows as $row) {
                        fputcsv($out, [$checkName, json_encode($row)]);
                    }
                }
            }
            fclose($out);
            exit;
        }
    }

    // ============================================================
    // PARTNER BANK STATEMENT — one institution's own reconcilable
    // record: every transaction they touched, fees invoiced to
    // them, and their net position. NOTE: assumes net_positions has
    // institution_a/institution_b columns — adjust if your schema
    // names these differently, same caveat as the agents table above.
    // ============================================================
    if ($reportKey === 'bank_statement' && $bankInstitution !== '') {
        $bankStatement = ['institution' => $bankInstitution, 'transactions' => [], 'fees_charged_to_them' => ['fees' => 0, 'invoice_count' => 0], 'net_positions' => []];
        try {
            $checkStmt = $db->query("SELECT to_regclass('vw_all_swaps')");
            if ($checkStmt->fetchColumn()) {
                $stmt = $db->prepare("
                    SELECT swap_reference, reference, swap_type, source_institution, destination_institution,
                           amount, currency, status, fee_amount, created_at
                    FROM vw_all_swaps
                    WHERE source_institution = :inst OR destination_institution = :inst
                    ORDER BY created_at DESC LIMIT 1000
                ");
                $stmt->execute([':inst' => $bankInstitution]);
                $bankStatement['transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) { dashError('vw_all_swaps', $e); }
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM((message_payload->>'fee_amount')::numeric), 0) AS fees, COUNT(*) AS invoice_count
                FROM settlement_outbox
                WHERE message_type = 'FEE_INVOICE' AND source_institution = :inst
            ");
            $stmt->execute([':inst' => $bankInstitution]);
            $bankStatement['fees_charged_to_them'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['fees' => 0, 'invoice_count' => 0];
        } catch (Throwable $e) { dashError('settlement_outbox', $e); }
        try {
            $stmt = $db->prepare("SELECT * FROM net_positions WHERE institution_a = :inst OR institution_b = :inst ORDER BY id DESC LIMIT 50");
            $stmt->execute([':inst' => $bankInstitution]);
            $bankStatement['net_positions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('net_positions', $e); }

        if ($reportFormat === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vouchmorph_statement_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $bankInstitution) . '_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Type', 'Role', 'Counterparty', 'Amount', 'Currency', 'Fee', 'Status', 'Created At']);
            foreach ($bankStatement['transactions'] as $t) {
                $role = ($t['source_institution'] ?? '') === $bankInstitution ? 'SOURCE' : 'DESTINATION';
                $counterparty = $role === 'SOURCE' ? ($t['destination_institution'] ?? 'N/A') : ($t['source_institution'] ?? 'N/A');
                fputcsv($out, [$t['swap_reference'] ?? $t['reference'], $t['swap_type'], $role, $counterparty, $t['amount'], $t['currency'], $t['fee_amount'], $t['status'], $t['created_at']]);
            }
            fclose($out);
            exit;
        }
    }

    if ($reportKey === 'net_settlement') {
        try { $reportNetPositions = $db->query("SELECT * FROM net_positions ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { dashError('net_positions', $e); }
    }
    if ($reportKey === 'fee_revenue') {
        try {
            $reportFeeRevenue = $db->query("
                SELECT source_institution, destination_institution,
                       COALESCE(SUM((message_payload->>'fee_amount')::numeric), 0) AS fees,
                       COUNT(*) AS invoice_count
                FROM settlement_outbox
                WHERE message_type = 'FEE_INVOICE'
                GROUP BY source_institution, destination_institution
                ORDER BY fees DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('settlement_outbox', $e); }
    }
    if ($reportKey === 'audit_export') {
        try { $reportAuditRows = $db->query("SELECT * FROM audit_logs ORDER BY audit_id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { dashError('audit_logs', $e); }

        if ($reportFormat === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vouchmorph_audit_export_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            if (!empty($reportAuditRows)) {
                fputcsv($out, array_keys($reportAuditRows[0]));
                foreach ($reportAuditRows as $row) {
                    fputcsv($out, array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $row));
                }
            } else {
                fputcsv($out, ['No audit records']);
            }
            fclose($out);
            exit;
        }
    }
    // ============================================================
    // SUSPICIOUS ACTIVITY (AML/KYC) — deliberately gated beyond the
    // generic canView('reports') check used by every other report.
    // A prior version of this report (deployed elsewhere in this
    // codebase as a standalone page) only checked whether the user
    // was logged in at all, exposing AML risk scores and KYC PII to
    // every admin role including Customer Support. Here it requires
    // audit or regulatory view rights — the roles already trusted
    // with compliance-adjacent data — before any query even runs.
    // ============================================================
    $canViewSuspicious = canView('audit') || canView('regulatory');
    $suspiciousData = [];
    $suspiciousSummary = ['total' => 0, 'pending' => 0, 'failed' => 0, 'high_value' => 0, 'stale_holds' => 0];
    if ($reportKey === 'suspicious_activity' && $canViewSuspicious) {
        try {
            $stmt = $db->prepare("
                SELECT s.swap_id, s.user_id, s.amount, s.currency, s.status, s.created_at,
                       s.source_institution, s.destination_institution, s.asset_type, s.swap_type
                FROM swap_requests s
                WHERE s.status IN ('pending', 'failed', 'cancelled')
                   OR s.amount > :threshold
                   OR s.currency != :baseCurrency
                ORDER BY s.created_at DESC
                LIMIT 200
            ");
            $stmt->execute([':threshold' => 100000, ':baseCurrency' => 'BWP']);
            $suspiciousData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('swap_requests', $e); $suspiciousData = []; }

        try {
            $stmt = $db->query("
                SELECT hold_id, hold_reference, swap_reference, participant_name AS institution,
                       amount, currency, status, placed_at
                FROM hold_transactions
                WHERE status IN ('ACTIVE','HELD','PENDING_CASHOUT')
                  AND placed_at < NOW() - INTERVAL '1 hour'
                ORDER BY placed_at ASC LIMIT 100
            ");
            $suspiciousStaleHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { dashError('hold_transactions', $e); $suspiciousStaleHolds = []; }

        foreach ($suspiciousData as $s) {
            $suspiciousSummary['total']++;
            if (($s['status'] ?? '') === 'pending') $suspiciousSummary['pending']++;
            if (($s['status'] ?? '') === 'failed') $suspiciousSummary['failed']++;
            if ((float)($s['amount'] ?? 0) > 100000) $suspiciousSummary['high_value']++;
        }
        $suspiciousSummary['stale_holds'] = count($suspiciousStaleHolds ?? []);

        if ($reportFormat === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vouchmorph_suspicious_activity_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Swap ID', 'User ID', 'Amount', 'Currency', 'Status', 'Source', 'Destination', 'Type', 'Created At']);
            foreach ($suspiciousData as $row) {
                fputcsv($out, [$row['swap_id'], $row['user_id'], $row['amount'], $row['currency'], $row['status'], $row['source_institution'], $row['destination_institution'], $row['swap_type'], $row['created_at']]);
            }
            fclose($out);
            exit;
        }
    }

    if ($reportKey === 'fee_revenue' && $reportFormat === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="vouchmorph_fee_revenue_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Source Institution', 'Destination Institution', 'Fees Collected', 'Invoice Count']);
        foreach ($reportFeeRevenue as $row) {
            fputcsv($out, [$row['source_institution'], $row['destination_institution'], $row['fees'], $row['invoice_count']]);
        }
        fclose($out);
        exit;
    }
    if ($reportKey === 'net_settlement' && $reportFormat === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="vouchmorph_net_settlement_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        if (!empty($reportNetPositions)) {
            fputcsv($out, array_keys($reportNetPositions[0]));
            foreach ($reportNetPositions as $row) {
                fputcsv($out, array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $row));
            }
        } else {
            fputcsv($out, ['No net position records']);
        }
        fclose($out);
        exit;
    }
    if (isset($reconciliationConfig[$reportKey])) {
        $cfg = $reconciliationConfig[$reportKey];
        try {
            $checkStmt = $db->query("SELECT to_regclass('vw_all_swaps')");
            if ($checkStmt->fetchColumn()) {
                $stmt = $db->prepare("
                    SELECT date_trunc(:unit, created_at) AS period,
                           COUNT(*) AS txn_count,
                           COALESCE(SUM(amount), 0) AS volume,
                           COALESCE(SUM(fee_amount), 0) AS fees,
                           COUNT(*) FILTER (WHERE status ILIKE '%completed%' OR status ILIKE '%success%') AS completed,
                           COUNT(*) FILTER (WHERE status ILIKE '%pending%' OR status ILIKE '%processing%') AS pending,
                           COUNT(*) FILTER (WHERE status ILIKE '%fail%' OR status ILIKE '%error%') AS failed
                    FROM vw_all_swaps
                    WHERE created_at >= NOW() - :windowInterval::interval
                    GROUP BY period ORDER BY period DESC
                ");
                $stmt->execute([':unit' => $cfg['unit'], ':windowInterval' => $cfg['window']]);
                $reportReconciliation = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($reportReconciliation as &$rrow) {
                    $rrow['txn_count'] = (int)($rrow['txn_count'] ?? 0);
                    $rrow['volume'] = (float)($rrow['volume'] ?? 0);
                    $rrow['fees'] = (float)($rrow['fees'] ?? 0);
                    $rrow['completed'] = (int)($rrow['completed'] ?? 0);
                    $rrow['pending'] = (int)($rrow['pending'] ?? 0);
                    $rrow['failed'] = (int)($rrow['failed'] ?? 0);
                }
                unset($rrow);
            }
        } catch (Throwable $e) {
            error_log("[ADMIN DASHBOARD] reconciliation report error: " . $e->getMessage());
        }

        if ($reportFormat === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vouchmorph_' . $reportKey . '_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, [$cfg['label'], 'Transactions', 'Volume', 'Fees', 'Completed', 'Pending', 'Failed']);
            foreach ($reportReconciliation as $row) {
                fputcsv($out, [$row['period'], $row['txn_count'], $row['volume'], $row['fees'], $row['completed'], $row['pending'], $row['failed']]);
            }
            fclose($out);
            exit;
        }
    }

    // ============================================================
    // FLOW TYPE BREAKDOWN — per swap_type: count, success rate,
    // and average duration (swap_requests.created_at to completed_at),
    // measured against the same H1 (90s) / Experiment 2 (60s deposit)
    // thresholds used by the Transaction Certificate above.
    // ============================================================
    if ($reportKey === 'flow_type_breakdown') {
        try {
            $stmt = $db->query("
                SELECT swap_type,
                       COUNT(*) AS total,
                       COUNT(*) FILTER (WHERE status ILIKE '%completed%' OR status ILIKE '%success%') AS successful,
                       COUNT(*) FILTER (WHERE status ILIKE '%fail%' OR status ILIKE '%error%') AS failed,
                       ROUND(AVG(EXTRACT(EPOCH FROM (completed_at - created_at))) FILTER (WHERE completed_at IS NOT NULL)::numeric, 1) AS avg_duration_seconds
                FROM swap_requests
                GROUP BY swap_type
                ORDER BY total DESC
            ");
            $reportFlowBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reportFlowBreakdown as &$fbRow) {
                $fbRow['total'] = (int)($fbRow['total'] ?? 0);
                $fbRow['successful'] = (int)($fbRow['successful'] ?? 0);
                $fbRow['failed'] = (int)($fbRow['failed'] ?? 0);
                $fbRow['success_rate'] = $fbRow['total'] > 0 ? round(($fbRow['successful'] / $fbRow['total']) * 100, 1) : 0.0;
                $fbRow['avg_duration_seconds'] = $fbRow['avg_duration_seconds'] !== null ? (float)$fbRow['avg_duration_seconds'] : null;
            }
            unset($fbRow);
        } catch (Throwable $e) {
            $reportFlowBreakdown = [];
            error_log("[ADMIN DASHBOARD] flow_type_breakdown error: " . $e->getMessage());
        }

        if ($reportFormat === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vouchmorph_flow_type_breakdown_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Swap Type', 'Total', 'Successful', 'Failed', 'Success Rate %', 'Avg Duration (s)']);
            foreach ($reportFlowBreakdown as $row) {
                fputcsv($out, [$row['swap_type'], $row['total'], $row['successful'], $row['failed'], $row['success_rate'], $row['avg_duration_seconds'] ?? 'N/A']);
            }
            fclose($out);
            exit;
        }
    }

    // ============================================================
    // INSTITUTION SETTLEMENT SUMMARY — end-of-day/week/month view of
    // what each institution issued, received, its net position, and
    // fees charged to it. Net position here is computed independently
    // from raw swap_requests volume, as a deliberate cross-check
    // against the net_positions table used elsewhere — if the two
    // disagree, that's worth investigating before either goes to a
    // partner bank or BoB.
    // ============================================================
    if ($reportKey === 'institution_settlement_summary') {
        $spCfg = $settlementPeriodConfig[$settlementPeriod] ?? $settlementPeriodConfig['daily'];
        try {
            $stmt = $db->prepare("
                WITH sent AS (
                    SELECT source_institution AS institution,
                           date_trunc(:unit1, created_at) AS period,
                           COALESCE(SUM(amount), 0) AS issued,
                           COUNT(*) AS issued_count
                    FROM swap_requests
                    WHERE created_at >= NOW() - :window1::interval
                    GROUP BY source_institution, period
                ),
                received AS (
                    SELECT destination_institution AS institution,
                           date_trunc(:unit2, created_at) AS period,
                           COALESCE(SUM(amount), 0) AS received,
                           COUNT(*) AS received_count
                    FROM swap_requests
                    WHERE created_at >= NOW() - :window2::interval
                    GROUP BY destination_institution, period
                ),
                fees AS (
                    SELECT source_institution AS institution,
                           date_trunc(:unit3, created_at) AS period,
                           COALESCE(SUM((message_payload->>'fee_amount')::numeric), 0) AS fees_charged
                    FROM settlement_outbox
                    WHERE message_type = 'FEE_INVOICE' AND created_at >= NOW() - :window3::interval
                    GROUP BY source_institution, period
                )
                SELECT
                    COALESCE(sent.institution, received.institution, fees.institution) AS institution,
                    COALESCE(sent.period, received.period, fees.period) AS period,
                    COALESCE(sent.issued, 0) AS issued,
                    COALESCE(sent.issued_count, 0) AS issued_count,
                    COALESCE(received.received, 0) AS received,
                    COALESCE(received.received_count, 0) AS received_count,
                    COALESCE(received.received, 0) - COALESCE(sent.issued, 0) AS net_position,
                    COALESCE(fees.fees_charged, 0) AS fees_charged
                FROM sent
                FULL OUTER JOIN received ON sent.institution = received.institution AND sent.period = received.period
                FULL OUTER JOIN fees ON COALESCE(sent.institution, received.institution) = fees.institution
                                      AND COALESCE(sent.period, received.period) = fees.period
                ORDER BY period DESC, institution ASC
            ");
            $stmt->execute([
                ':unit1' => $spCfg['unit'], ':window1' => $spCfg['window'],
                ':unit2' => $spCfg['unit'], ':window2' => $spCfg['window'],
                ':unit3' => $spCfg['unit'], ':window3' => $spCfg['window'],
            ]);
            $reportInstitutionSettlement = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reportInstitutionSettlement as &$isRow) {
                $isRow['issued'] = (float)$isRow['issued'];
                $isRow['received'] = (float)$isRow['received'];
                $isRow['net_position'] = (float)$isRow['net_position'];
                $isRow['fees_charged'] = (float)$isRow['fees_charged'];
            }
            unset($isRow);
        } catch (Throwable $e) {
            $reportInstitutionSettlement = [];
            error_log("[ADMIN DASHBOARD] institution_settlement_summary error: " . $e->getMessage());
        }

        if ($reportFormat === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vouchmorph_institution_settlement_' . $settlementPeriod . '_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, [$spCfg['label'], 'Institution', 'Issued', 'Issued Count', 'Received', 'Received Count', 'Net Position (Owed To Them If +)', 'Fees Charged To Them']);
            foreach ($reportInstitutionSettlement as $row) {
                fputcsv($out, [$row['period'], $row['institution'], $row['issued'], $row['issued_count'], $row['received'], $row['received_count'], $row['net_position'], $row['fees_charged']]);
            }
            fclose($out);
            exit;
        }
    }

    // ============================================================
    // PDF EXPORT — one dispatcher covering every report, so nothing
    // in the catalog is CSV/print-only. Uses the same data already
    // fetched above for the on-screen view and CSV export, just
    // reshaped into pdf_metrics_section()/pdf_table_section() calls.
    // ============================================================
    if ($reportFormat === 'pdf') {
        $preparedBy = safeHtml($adminFullName ?: $adminUsername);
        $body = '';

        if ($reportKey === 'transaction_certificate' && $certRef !== '' && (!empty($certData['swap_request']) || !empty($certData['holds']))) {
            $sr = $certData['swap_request'];
            $body .= pdf_metrics_section('Summary', [
                'Amount' => number_format((float)($sr['amount'] ?? 0), 2) . ' ' . ($sr['from_currency'] ?? ''),
                'Status' => strtoupper($sr['status'] ?? 'unknown'),
                'Started' => !empty($certData['duration']) ? fmtTs($certData['duration']['start'], 'N/A') : fmtTs($sr['created_at'] ?? '', 'N/A'),
                'Duration' => !empty($certData['duration'])
                    ? $certData['duration']['seconds'] . 's (' . ($certData['duration']['pass'] ? 'PASS' : 'FAIL') . ' — threshold ' . $certData['duration']['threshold'] . 's) — '
                        . fmtTs($certData['duration']['start']) . ' to ' . fmtTs($certData['duration']['end'])
                    : 'N/A',
            ]);
            // This hold's own real placed_at/debited_at — distinct
            // checkpoints from the Duration line's created_at (client
            // clicked "Swap") per the Transaction Certificate's
            // three-timestamp spec — see the matching comment in the
            // HTML render below for why.
            $body .= pdf_table_section('1 · Hold Placed', ['Hold ID', 'Hold Reference', 'Institution', 'Amount', 'Status', 'Placed At', 'Debited At', 'Elapsed'],
                array_map(function ($h) {
                    $isDebited = $h['status'] === 'DEBITED';
                    $placedDisplay = $h['placed_at'] ?? '';
                    $debitedDisplay = $isDebited ? ($h['debited_at'] ?? '') : '';
                    return [$h['hold_id'], $h['hold_reference'], $h['source_institution'] ?? $h['participant_name'] ?? 'N/A', number_format((float)$h['amount'], 2), $h['status'], fmtTs($placedDisplay), fmtTs($debitedDisplay, '—'), $isDebited ? fmtElapsed($placedDisplay, $debitedDisplay) : '—'];
                }, $certData['holds']));
            if (!empty($certData['cashout'])) {
                $co = $certData['cashout'];
                $body .= pdf_table_section('2 · Destination Code Generated', ['Provider', 'Amount', 'Fee', 'Code Expiry', 'Status'],
                    [[$co['cashout_provider'] ?? 'N/A', number_format((float)$co['amount'], 2), number_format((float)($co['fee_amount'] ?? 0), 2), $co['code_expiry'] ?? '', $co['status']]]);
            }
            $body .= pdf_table_section('3 · Ledger Entries', ['From', 'To', 'Amount', 'Status', 'Transaction Ref', 'Created (Amount Added to Swap Finished)'],
                array_map(function ($t) use ($certData) {
                    $from = json_decode($t['from_account_details'] ?? '{}', true) ?: [];
                    $to = json_decode($t['to_account_details'] ?? '{}', true) ?: [];
                    $created = !empty($certData['duration'])
                        ? fmtTs($certData['duration']['start']) . ' to ' . fmtTs($certData['duration']['end'])
                        : fmtTs($t['created_at'] ?? '');
                    return [$from['institution'] ?? 'N/A', $to['institution'] ?? 'N/A', number_format((float)$t['amount'], 2), $t['status'], $t['transaction_id'] ?? '—', $created];
                }, $certData['swap_transactions']));
            $body .= pdf_table_section('4 · Audit Trail', ['Action', 'Category', 'Performed By', 'At'],
                array_map(fn($a) => [$a['action'] ?? '', $a['category'] ?? '', $a['performed_by'] ?? $a['performed_by_id'] ?? 'SYSTEM', fmtTs($a['performed_at'] ?? '')], $certData['audit']),
                'Cryptographic signatures for each step are recorded in application logs, not yet in a queryable table — see engineering note on the on-screen certificate. An empty section here on a completed transaction is itself a finding: check audit_log_failures and the Double-Spend & Duplicate-Debit report.');
            pdf_stream(pdf_page_shell('Transaction Certificate', 'Reference: ' . $certRef, $preparedBy, $body), 'vouchmorph_certificate_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $certRef) . '.pdf');
        }

        if ($reportKey === 'double_spend_check') {
            $body .= pdf_metrics_section('Result', ['Total Issues Found' => $integrityTotalIssues, 'Checks That Could Not Run' => count($integrityFailed)]);
            if (!empty($integrityFailed)) {
                $body .= '<p style="color:#D32F2F;font-weight:bold;">Incomplete: these checks could not run and prove nothing: ' . safeHtml(implode(', ', array_keys($integrityFailed))) . '</p>';
            }
            $body .= pdf_table_section('Holds Debited More Than Once', ['Swap Reference', 'Debited Count', 'Hold IDs', 'Amounts'],
                array_map(fn($r) => [$r['swap_reference'], $r['debited_hold_count'], trim($r['hold_ids'], '{}'), trim($r['amounts'], '{}')], $integrityIssues['duplicate_debited_holds'] ?? []));
            $body .= pdf_table_section('Cashouts Completed More Than Once', ['Swap Reference', 'Completed Count'],
                array_map(fn($r) => [$r['swap_reference'], $r['auth_count']], $integrityIssues['duplicate_completed_cashouts'] ?? []));
            $body .= pdf_table_section('Idempotency Key Conflicts', ['Idempotency Key', 'Distinct References'],
                array_map(fn($r) => [$r['key'], $r['distinct_refs']], $integrityIssues['idempotency_key_conflicts'] ?? []));
            $body .= pdf_table_section('Debited Holds Missing Swap Record', ['Hold ID', 'Swap Reference', 'Amount', 'Institution', 'Placed At'],
                array_map(fn($r) => [$r['hold_id'], $r['swap_reference'], number_format((float)$r['amount'], 2), $r['source_institution'], $r['placed_at']], $integrityIssues['debited_holds_missing_swap_request'] ?? []));
            $body .= pdf_table_section('Completed Transactions With No Audit Record', ['Reference', 'Amount', 'Currency', 'Status', 'Created'],
                array_map(fn($r) => [$r['swap_uuid'], number_format((float)$r['amount'], 2), $r['from_currency'] ?? '', $r['status'], $r['created_at']], $integrityIssues['completed_swaps_missing_audit'] ?? []));
            $body .= pdf_table_section('Audit Write Failures (Unresolved)', ['Reference', 'Type', 'Reason', 'Recorded'],
                array_map(fn($r) => [$r['swap_reference'], $r['swap_type'] ?? '', $r['reason'], $r['created_at']], $integrityIssues['audit_write_failures'] ?? []),
                'Each row is a transaction whose audit entry could not be written. The money movement already happened; these need a human to reconstruct the record.');
            pdf_stream(pdf_page_shell('Double-Spend & Duplicate-Debit Check', 'Automated integrity scan across the full ledger', $preparedBy, $body), 'vouchmorph_integrity_check_' . date('Ymd_His') . '.pdf');
        }

        if ($reportKey === 'bank_statement' && $bankInstitution !== '' && !empty($bankStatement['transactions'])) {
            $volumeSent = 0; $volumeReceived = 0;
            foreach ($bankStatement['transactions'] as $t) {
                if (($t['source_institution'] ?? '') === $bankInstitution) $volumeSent += (float)($t['amount'] ?? 0);
                if (($t['destination_institution'] ?? '') === $bankInstitution) $volumeReceived += (float)($t['amount'] ?? 0);
            }
            $body .= pdf_metrics_section('Summary', [
                'Total Transactions' => count($bankStatement['transactions']),
                'Sent (as source)' => number_format($volumeSent, 2),
                'Received (as destination)' => number_format($volumeReceived, 2),
                'Fees Invoiced' => number_format((float)($bankStatement['fees_charged_to_them']['fees'] ?? 0), 2),
            ]);
            $body .= pdf_table_section('Transaction Detail', ['Reference', 'Type', 'Role', 'Counterparty', 'Amount', 'Fee', 'Status', 'Date'],
                array_map(function ($t) use ($bankInstitution) {
                    $role = ($t['source_institution'] ?? '') === $bankInstitution ? 'SOURCE' : 'DESTINATION';
                    $counterparty = $role === 'SOURCE' ? ($t['destination_institution'] ?? 'N/A') : ($t['source_institution'] ?? 'N/A');
                    return [$t['swap_reference'] ?? $t['reference'] ?? '', $t['swap_type'] ?? '', $role, $counterparty, number_format((float)($t['amount'] ?? 0), 2), number_format((float)($t['fee_amount'] ?? 0), 2), $t['status'] ?? '', $t['created_at'] ?? ''];
                }, $bankStatement['transactions']));
            pdf_stream(pdf_page_shell('Partner Bank Statement', 'Institution: ' . $bankInstitution, $preparedBy, $body), 'vouchmorph_statement_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $bankInstitution) . '.pdf');
        }

        if ($reportKey === 'executive_summary') {
            $body .= pdf_metrics_section('Network Volume', [
                'Total Swaps' => number_format($metrics['total_swaps'] ?? 0),
                'Total Users' => number_format($metrics['total_users'] ?? 0),
                '24h Swaps' => number_format($metrics['recent_swaps_24h'] ?? 0),
                'Fees Collected' => number_format($metrics['total_fees'] ?? 0, 2),
                'Pending Settlements' => number_format($metrics['pending_settlements'] ?? 0),
                'Multi-Dest Batches' => number_format($metrics['multi_destination_count'] ?? 0),
            ]);
            $body .= pdf_table_section('Institution Snapshot', ['Institution', 'Volume', 'Success Rate'],
                array_map(fn($i) => [$i['institution'], number_format((float)$i['volume'], 2), $i['success_rate'] . '%'], array_slice($institutionHealth, 0, 10)));
            $body .= '<div class="pdf-section-title">Open Items</div><p>' . $totalAlerts . ' alert(s) outstanding &middot; ' . $agentApprovalCount . ' agent application(s) awaiting approval.</p>';
            pdf_stream(pdf_page_shell('Executive Summary', 'Bank of Botswana Regulatory Sandbox Participant', $preparedBy, $body), 'vouchmorph_executive_summary_' . date('Ymd_His') . '.pdf');
        }

        if ($reportKey === 'trust_scorecard') {
            $ranked = $institutionHealth; usort($ranked, fn($a, $b) => $b['success_rate'] <=> $a['success_rate']);
            $body .= pdf_table_section('Institutional Trust Scorecard', ['#', 'Institution', 'Total Txns', 'Success Rate', 'Volume', 'Tier'],
                array_map(function ($i, $idx) {
                    $rate = (float)$i['success_rate'];
                    $tier = $rate >= 95 ? 'Gold' : ($rate >= 80 ? 'Silver' : 'Needs Review');
                    return [$idx + 1, $i['institution'], number_format($i['total']), $rate . '%', number_format((float)$i['volume'], 2), $tier];
                }, $ranked, array_keys($ranked)));
            pdf_stream(pdf_page_shell('Institutional Trust Scorecard', 'Success rate ranking across the network', $preparedBy, $body), 'vouchmorph_trust_scorecard_' . date('Ymd_His') . '.pdf');
        }

        if ($reportKey === 'net_settlement') {
            $rows = array_map(fn($r) => array_values(array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $r)), $reportNetPositions);
            $headers = !empty($reportNetPositions) ? array_keys($reportNetPositions[0]) : [];
            $body .= pdf_table_section('Net Settlement Position', $headers, $rows);
            pdf_stream(pdf_page_shell('Net Settlement Position', 'For regulatory review', $preparedBy, $body), 'vouchmorph_net_settlement_' . date('Ymd_His') . '.pdf');
        }

        if ($reportKey === 'fee_revenue') {
            $grandTotal = array_sum(array_column($reportFeeRevenue, 'fees'));
            $body .= pdf_metrics_section('Summary', ['Total Fee Revenue' => number_format($grandTotal, 2), 'Institution Pairs' => count($reportFeeRevenue)]);
            $body .= pdf_table_section('By Institution Pair', ['Source', 'Destination', 'Fees Collected', 'Invoices'],
                array_map(fn($r) => [$r['source_institution'] ?? 'N/A', $r['destination_institution'] ?? 'N/A', number_format((float)$r['fees'], 2), number_format($r['invoice_count'])], $reportFeeRevenue),
                'Gross fee income only — a full P&L also needs operating costs and settlement charges.');
            pdf_stream(pdf_page_shell('Fee Revenue Summary', 'By institution pair, sourced from settlement invoicing', $preparedBy, $body), 'vouchmorph_fee_revenue_' . date('Ymd_His') . '.pdf');
        }

        if ($reportKey === 'audit_export') {
            $headers = !empty($reportAuditRows) ? array_keys($reportAuditRows[0]) : [];
            $rows = array_map(fn($r) => array_values(array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $r)), array_slice($reportAuditRows, 0, 500));
            $body .= pdf_table_section('Audit Trail (first 500 of ' . count($reportAuditRows) . ')', $headers, $rows);
            pdf_stream(pdf_page_shell('Audit Trail Export', 'Most recent entries', $preparedBy, $body), 'vouchmorph_audit_export_' . date('Ymd_His') . '.pdf');
        }

        if ($reportKey === 'suspicious_activity' && $canViewSuspicious) {
            $body .= pdf_metrics_section('Summary', [
                'Flagged Total' => $suspiciousSummary['total'],
                'Pending' => $suspiciousSummary['pending'],
                'Failed' => $suspiciousSummary['failed'],
                'High Value (>100k)' => $suspiciousSummary['high_value'],
                'Stale Holds (>1h)' => $suspiciousSummary['stale_holds'],
            ]);
            $body .= pdf_table_section('Flagged Transactions', ['Swap ID', 'User', 'Amount', 'Currency', 'Status', 'Source', 'Destination', 'Created'],
                array_map(fn($s) => [$s['swap_id'] ?? '', $s['user_id'] ?? '', number_format((float)($s['amount'] ?? 0), 2), $s['currency'] ?? 'BWP', strtoupper($s['status'] ?? ''), $s['source_institution'] ?? 'N/A', $s['destination_institution'] ?? 'N/A', $s['created_at'] ?? ''], $suspiciousData),
                'Status/amount-based flags only — does not yet include AML risk scoring or KYC status.');
            pdf_stream(pdf_page_shell('Suspicious Activity (AML/KYC)', 'Flagged transactions and stale holds requiring review', $preparedBy, $body), 'vouchmorph_suspicious_activity_' . date('Ymd_His') . '.pdf');
        }

        if ($reportKey === 'flow_type_breakdown') {
            $body .= pdf_table_section('Flow Type Breakdown', ['Swap Type', 'Total', 'Successful', 'Failed', 'Success Rate', 'Avg Duration'],
                array_map(fn($r) => [$r['swap_type'], $r['total'], $r['successful'], $r['failed'], $r['success_rate'] . '%', $r['avg_duration_seconds'] !== null ? $r['avg_duration_seconds'] . 's' : 'N/A'], $reportFlowBreakdown),
                'Duration measured swap_requests.created_at to swap_requests.completed_at, per KPI H1/Experiment methodology.');
            pdf_stream(pdf_page_shell('Flow Type Breakdown', 'Per swap-type count, success rate, and duration', $preparedBy, $body), 'vouchmorph_flow_type_breakdown_' . date('Ymd_His') . '.pdf');
        }

        if ($reportKey === 'institution_settlement_summary') {
            $spCfg = $settlementPeriodConfig[$settlementPeriod] ?? $settlementPeriodConfig['daily'];
            $body .= pdf_table_section('Institution Settlement Summary (' . $spCfg['label'] . ')', ['Period', 'Institution', 'Issued', 'Received', 'Net Position', 'Fees Charged'],
                array_map(fn($r) => [
                    date($spCfg['unit'] === 'month' ? 'Y-m' : 'Y-m-d', strtotime($r['period'])),
                    $r['institution'] ?? 'UNKNOWN',
                    number_format($r['issued'], 2) . ' (' . $r['issued_count'] . ')',
                    number_format($r['received'], 2) . ' (' . $r['received_count'] . ')',
                    ($r['net_position'] >= 0 ? '+' : '') . number_format($r['net_position'], 2),
                    number_format($r['fees_charged'], 2),
                ], $reportInstitutionSettlement),
                'Net position is computed independently from swap_requests volume, as a cross-check against the net_positions table.');
            pdf_stream(pdf_page_shell('Institution Settlement Summary', 'Issued, received, net position, and fees — by ' . $spCfg['label'], $preparedBy, $body), 'vouchmorph_institution_settlement_' . $settlementPeriod . '_' . date('Ymd_His') . '.pdf');
        }

        if (isset($reconciliationConfig[$reportKey])) {
            $cfg = $reconciliationConfig[$reportKey];
            $totalTxn = array_sum(array_column($reportReconciliation, 'txn_count'));
            $totalVol = array_sum(array_column($reportReconciliation, 'volume'));
            $totalFees = array_sum(array_column($reportReconciliation, 'fees'));
            $body .= pdf_metrics_section('Summary', ['Total Transactions' => number_format($totalTxn), 'Total Volume' => number_format($totalVol, 2), 'Total Fees' => number_format($totalFees, 2)]);
            $body .= pdf_table_section('Breakdown by ' . $cfg['label'], [$cfg['label'], 'Transactions', 'Volume', 'Fees', 'Completed', 'Pending', 'Failed'],
                array_map(fn($r) => [date($cfg['unit'] === 'month' ? 'Y-m' : 'Y-m-d', strtotime($r['period'])), number_format($r['txn_count']), number_format($r['volume'], 2), number_format($r['fees'], 2), number_format($r['completed']), number_format($r['pending']), number_format($r['failed'])], $reportReconciliation),
                'Reconciles internal ledger totals only (what VouchMorph recorded) — not yet cross-checked against bank/settlement statements.');
            pdf_stream(pdf_page_shell($reportCatalog[$reportKey]['title'], 'Grouped by ' . $cfg['label'] . ', last ' . $cfg['window'], $preparedBy, $body), 'vouchmorph_' . $reportKey . '_' . date('Ymd_His') . '.pdf');
        }

        // If we reach here, the requested report/format combination had
        // nothing to render (e.g. no ref/institution supplied yet) -
        // fall through to the normal HTML page rather than a blank PDF.
    }
}

// ============================================================
// SCHEMA HEALTH - tables/views this page reads. Several (vw_all_swaps,
// multi_destination_swaps, identity_swap_holds, agent_destination_accounts)
// are not created by any migration in the repo; if production has them,
// they were made by hand and a rebuild from the repo would lose them.
// Missing ones are listed so an empty panel is never mistaken for "no data".
// ============================================================
$missingRelations = [];
if ($isSuperAdmin || canView('audit')) {
    $requiredRelations = ['vw_all_swaps', 'swap_requests', 'hold_transactions', 'cashout_authorizations',
        'identity_swap_holds', 'multi_destination_swaps', 'settlement_outbox', 'settlement_queue', 'net_positions',
        'ledger_entries', 'message_outbox', 'idempotency_keys', 'audit_logs', 'audit_log_failures',
        'agent_destination_accounts', 'users'];
    try {
        $chk = $db->prepare("SELECT to_regclass(:r) IS NOT NULL");
        foreach ($requiredRelations as $rel) {
            $chk->execute([':r' => $rel]);
            if (!$chk->fetchColumn()) $missingRelations[] = $rel;
        }
        $fn = $db->query("SELECT to_regprocedure('audit_chain_verify()') IS NOT NULL")->fetchColumn();
        if (!$fn) $missingRelations[] = 'audit_chain_verify() - apply 2026_09_19_audit_chain_enforced.sql';
    } catch (Throwable $e) { dashError('schema health check', $e); }
}

// View meta for description panel
$viewMeta = [
    'dashboard' => ['side' => 'left', 'eyebrow' => 'Operating Picture', 'blurb' => "Total swap volume, pending settlements, and the numbers that tell you whether the system is healthy right now — without opening a single table."],
    'client_lookup' => ['side' => 'right', 'eyebrow' => 'Customer Support', 'blurb' => "Search by phone number, national ID, or any reference. Every match comes with a plain next step, not just a status code."],
    'alerts' => ['side' => 'left', 'eyebrow' => 'Exceptions', 'blurb' => "Holds, cashouts, and identity swaps that have sat in a non-terminal state longer than expected. These need a human decision."],
    'live_transactions' => ['side' => 'right', 'eyebrow' => 'Real-Time Feed', 'blurb' => "A rolling view of the last twenty-four hours, refreshing on its own. Watch volume move without reloading the page."],
    'multi_destination' => ['side' => 'left', 'eyebrow' => 'Batch Settlement', 'blurb' => "One instruction, many destinations. A single batch can reach bank accounts, wallets, and identity-linked beneficiaries at once."],
    'recent_swaps' => ['side' => 'right', 'eyebrow' => 'Transaction Ledger', 'blurb' => "The complete transaction ledger, searchable by reference, institution, or status. Nothing here is paginated away."],
    'institution_health' => ['side' => 'left', 'eyebrow' => 'Institution Health', 'blurb' => "Volume, success rate, and average time-to-debit, broken down per institution. The bar tells you at a glance who's having a bad day."],
    'participants' => ['side' => 'right', 'eyebrow' => 'Institution Onboarding', 'blurb' => "Every configured bank, MNO, and switch, its onboarding status, and a one-click config validation — before it ever touches a real swap."],
    'regulatory' => ['side' => 'left', 'eyebrow' => 'Regulatory Oversight', 'blurb' => "Net positions between institutions and pending settlements — the numbers a regulator needs, not the raw transaction feed."],
    'audit' => ['side' => 'right', 'eyebrow' => 'Audit Trail', 'blurb' => "Every recorded action, most recent first. This is the trail — who did what, and when."],
    'ledger' => ['side' => 'left', 'eyebrow' => 'Ledger Reconciliation', 'blurb' => "Variances between debits, credits, and fees across the general ledger — the standing cross-check that shows whether every swap balances perfectly."],
    'invoices' => ['side' => 'right', 'eyebrow' => 'Invoices & Settlement', 'blurb' => "Every fee invoice beside its destination settlement: invoiced or paid, and whether the destination has been paid by the source."],
    'agent_approvals' => ['side' => 'left', 'eyebrow' => 'Agent Onboarding', 'blurb' => "Agents can't touch a client's money until an admin has approved them. Review, approve, or reject every applicant here."],
    'reports' => ['side' => 'right', 'eyebrow' => 'Reporting Suite', 'blurb' => "Executive, regulatory, finance, and audit reports — built for the people who never see the raw tables."],
];
$currentMeta = $viewMeta[$view] ?? ['side' => 'right', 'eyebrow' => 'VouchMorph Admin', 'blurb' => "Administrative tools for VouchMorph's enterprise disbursement network."];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · <?php echo safeHtml($roleName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper:        #EEF1EF;
            --panel:        #FFFFFF;
            --ink-900:      #0B1B2B;
            --ink-700:      #1D3557;
            --ink-500:      #33445A;
            --ink-300:      #8A96A3;
            --line:         #D3DAD6;
            --line-strong:  #AEB8B2;
            --brass:        #C9A227;
            --brass-deep:   #9A7B1E;
            --brass-tint:   #FBF3D9;
            --good:         #1E7A4C;
            --good-tint:    #E4F3EA;
            --bad:          #D32F2F;

            --f-display: 'Source Serif 4', 'IBM Plex Sans', serif;
            --f-body:    'IBM Plex Sans', sans-serif;
            --f-cond:    'IBM Plex Sans Condensed', sans-serif;
            --f-mono:    'IBM Plex Mono', monospace;

            --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px; --sp-4: 16px;
            --sp-5: 20px; --sp-6: 24px; --sp-7: 32px; --sp-8: 40px;
            --sp-9: 48px; --sp-10: 64px;

            --content-max: 1440px;
            --header-h: 38px;
            --btn-h: 40px;
            --btn-h-sm: 32px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            min-height: 100vh;
            font-size: 17px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            display: flex;
            flex-direction: column;
        }

        .admin-ribbon {
            background: var(--ink-900);
            padding: var(--sp-1) var(--sp-7);
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .admin-ribbon-inner {
            max-width: var(--content-max);
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--sp-6);
            color: rgba(255,255,255,0.65);
            font-family: var(--f-mono);
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            line-height: 1.8;
        }
        .admin-ribbon strong { color: var(--brass); font-weight: 600; }

        .admin-header {
            position: relative;
            background:
                radial-gradient(ellipse at top left, rgba(156,122,60,0.10), transparent 55%),
                var(--ink-900);
            color: #fff;
            padding: var(--sp-4) var(--sp-7);
            border-bottom: 3px solid var(--brass);
            overflow: hidden;
        }
        .admin-header-inner {
            position: relative;
            max-width: var(--content-max);
            margin: 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: var(--sp-8);
        }
        .header-left { position: relative; display: flex; align-items: center; gap: var(--sp-4); flex-wrap: wrap; }
        .logo { font-family: var(--f-display); font-weight: 600; font-size: 21px; letter-spacing: 0.01em; line-height: 1; }
        .logo span { color: var(--brass); font-weight: 400; }
        .logo-sub { font-family: var(--f-cond); font-size: 12px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(255,255,255,0.6); line-height: 1; }
        .role-badge { padding: 4px var(--sp-3); background: transparent; border: 1px solid var(--brass); color: var(--brass); font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; font-family: var(--f-cond); line-height: 1; }
        .user-area { position: relative; display: flex; align-items: center; gap: var(--sp-4); flex-wrap: wrap; }
        .user-details { text-align: right; display: flex; flex-direction: column; gap: 2px; }
        .user-name { font-weight: 600; color: #fff; font-size: 15px; font-family: var(--f-display); line-height: 1; }
        .user-role { font-size: 11.5px; color: rgba(255,255,255,0.6); text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.06em; line-height: 1; }
        .logout-btn { padding: 6px var(--sp-4); border: 1px solid rgba(255,255,255,0.25); color: #fff; text-decoration: none; font-size: 12.5px; font-weight: 600; text-transform: uppercase; font-family: var(--f-cond); transition: all 0.15s; letter-spacing: 0.06em; line-height: 1; background: transparent; }
        .logout-btn:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }

        .admin-nav {
            background: var(--panel);
            border-bottom: 1px solid var(--line);
            padding: 0 var(--sp-7);
        }
        .admin-nav-inner {
            max-width: var(--content-max);
            margin: 0 auto;
            display: flex;
            justify-content: center;
            gap: var(--sp-5);
            flex-wrap: wrap;
            align-items: center;
        }
        .nav-item {
            padding: var(--sp-3) 0;
            color: var(--ink-500);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid transparent;
            transition: all 0.15s;
            white-space: nowrap;
            font-family: var(--f-cond);
            line-height: 1;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .nav-item:hover { color: var(--ink-900); }
        .nav-item.active { color: var(--ink-900); border-bottom-color: var(--brass); }
        .nav-badge { background: var(--brass); color: #fff; font-size: 11px; padding: 1px 7px; font-family: var(--f-mono); font-weight: 700; }

        .admin-content { padding: var(--sp-8) var(--sp-7) var(--sp-7); flex: 1 0 auto; }
        .admin-content-inner { width: 100%; max-width: var(--content-max); margin: 0 auto; }

        .page-description {
            background: var(--parchment, #FBF9F4);
            border-bottom: 1px solid var(--line);
            padding: var(--sp-5) var(--sp-7);
            text-align: center;
        }
        .page-description-inner {
            max-width: 680px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: var(--sp-2);
        }
        .page-description .eyebrow {
            font-family: var(--f-cond);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--brass-deep);
        }
        .page-description p {
            font-family: var(--f-display);
            font-style: italic;
            font-size: 17px;
            color: var(--ink-500);
            line-height: 1.6;
        }

        @media (max-width: 768px) {
            .admin-header { padding: var(--sp-3) var(--sp-5); text-align: center; }
            .admin-header-inner { flex-direction: column; align-items: stretch; }
            .header-left { justify-content: center; }
            .user-area { justify-content: center; }
            .admin-nav { padding: 0 var(--sp-4); gap: var(--sp-4); }
            .admin-ribbon { padding: var(--sp-1) var(--sp-4); flex-direction: column; gap: 2px; }
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .content-header h1 { font-size: 20px; width: 100%; }
            .content-header { row-gap: var(--sp-2); }
            .admin-content { padding: var(--sp-4); }
            .report-catalog-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .metrics-grid { grid-template-columns: 1fr; }
            .admin-header .logo { font-size: 18px; }
        }

        .content-header {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            row-gap: var(--sp-2);
            column-gap: var(--sp-5);
            padding-bottom: var(--sp-4);
            margin-bottom: var(--sp-6);
            border-bottom: 2px solid var(--ink-900);
            position: relative;
            text-align: center;
        }
        .content-header::after {
            content: "";
            position: absolute;
            left: 0; right: 0; bottom: -4px;
            height: 1px;
            background: var(--line-strong);
        }
        .content-header h1 {
            font-family: var(--f-display);
            font-size: 27px;
            font-weight: 600;
            letter-spacing: 0.01em;
            color: var(--ink-900);
            line-height: 1.2;
            display: flex;
            align-items: center;
        }
        .content-header .timestamp {
            font-family: var(--f-mono);
            font-size: 12px;
            color: var(--ink-300);
            line-height: 1;
            white-space: nowrap;
        }
        .content-header .back-link {
            height: var(--btn-h-sm);
            display: inline-flex;
            align-items: center;
            font-family: var(--f-cond);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--ink-500);
            text-decoration: none;
            padding: 0 var(--sp-4);
            border: 1px solid var(--line-strong);
            transition: all 0.15s;
            white-space: nowrap;
            line-height: 1;
            box-sizing: border-box;
        }
        .content-header .back-link:hover {
            border-color: var(--brass);
            color: var(--ink-900);
            background: var(--brass-tint);
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1px;
            background: var(--line);
            border: 1px solid var(--line);
            margin-bottom: var(--sp-6);
        }
        .metric-card {
            background: var(--panel);
            padding: var(--sp-5) var(--sp-5) var(--sp-4);
            min-height: 96px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            border-top: 2px solid var(--brass-tint);
            transition: border-color 0.15s, background-color .15s;
        }
        .metric-card:hover { border-top-color: var(--brass); background: #FCFBF8; }
        .metric-card .metric-label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--ink-300);
            letter-spacing: 0.07em;
            font-weight: 600;
            font-family: var(--f-cond);
            line-height: 1.4;
            display: block;
        }
        .metric-card .metric-value {
            font-family: var(--f-display);
            font-size: 29px;
            font-weight: 600;
            color: var(--ink-900);
            font-variant-numeric: tabular-nums;
            line-height: 1.25;
            margin-top: var(--sp-2);
            display: block;
        }
        .duration-track {
            width: 100%;
            height: 6px;
            border-radius: 3px;
            background: var(--line);
            overflow: hidden;
            margin-top: var(--sp-2);
        }
        .duration-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.2s;
        }
        .metric-card .metric-sub {
            font-size: 12px;
            color: var(--ink-300);
            font-family: var(--f-mono);
            line-height: 1.4;
            margin-top: var(--sp-1);
            display: block;
        }

        .card {
            position: relative;
            background: var(--panel);
            border: 1px solid var(--line);
            padding: var(--sp-5) var(--sp-5) var(--sp-4);
            margin-bottom: var(--sp-5);
        }
        .card::before, .card::after {
            content: "";
            position: absolute;
            width: 8px;
            height: 8px;
            pointer-events: none;
        }
        .card::before {
            top: -1px; left: -1px;
            border-top: 2px solid var(--brass);
            border-left: 2px solid var(--brass);
        }
        .card::after {
            bottom: -1px; right: -1px;
            border-bottom: 2px solid var(--brass);
            border-right: 2px solid var(--brass);
        }
        .card-header {
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            margin-bottom: var(--sp-4);
            padding-bottom: var(--sp-3);
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
            gap: var(--sp-3);
        }
        .card-title {
            font-size: 18px;
            font-weight: 600;
            font-family: var(--f-display);
            letter-spacing: 0.01em;
            line-height: 1;
        }
        .card-badge {
            padding: var(--sp-1) var(--sp-3);
            background: var(--ink-900);
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            font-family: var(--f-mono);
            letter-spacing: 0.04em;
            line-height: 1.4;
        }
        .card-badge.brass { background: var(--brass); }

        .search-box {
            display: flex;
            gap: var(--sp-3);
            align-items: stretch;
            flex-wrap: wrap;
            margin-bottom: var(--sp-5);
        }
        .search-box input[type="text"] {
            font-family: var(--f-body);
            padding: 0 var(--sp-4);
            height: var(--btn-h);
            border: 1.5px solid var(--line);
            font-size: 15px;
            background: #fdfcf9;
            color: var(--ink-900);
            min-width: 250px;
            flex: 1;
            transition: border-color .15s, background .15s;
        }
        .search-box .btn { height: var(--btn-h); }
        .search-box input[type="text"]:focus {
            outline: none;
            border-color: var(--brass);
            background: #fff;
        }
        .search-box input[type="text"]::placeholder {
            color: var(--ink-300);
            opacity: 0.7;
        }

        .btn {
            height: var(--btn-h);
            padding: 0 var(--sp-5);
            font-size: 13px;
            font-weight: 700;
            border: 1.5px solid var(--ink-900);
            background: transparent;
            color: var(--ink-900);
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--sp-2);
            font-family: var(--f-cond);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            line-height: 1;
            box-sizing: border-box;
        }
        .btn:hover {
            background: var(--ink-900);
            color: #fff;
        }
        .btn-primary {
            background: var(--ink-900);
            color: #fff;
            border-color: var(--ink-900);
        }
        .btn-primary:hover {
            background: var(--brass);
            border-color: var(--brass);
            color: var(--ink-900);
        }
        .btn-sm { height: var(--btn-h-sm); padding: 0 var(--sp-4); font-size: 12px; }
        .btn-good { border-color: var(--good); color: var(--good); }
        .btn-good:hover { background: var(--good); border-color: var(--good); color: #fff; }
        .btn-bad { border-color: var(--bad); color: var(--bad); }
        .btn-bad:hover { background: var(--bad); border-color: var(--bad); color: #fff; }
        .inline-form { display: inline-block; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 15px; font-variant-numeric: tabular-nums; }
        th {
            background: var(--paper);
            color: var(--ink-500);
            padding: var(--sp-2) var(--sp-4);
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
            border-bottom: 2px solid var(--ink-900);
            font-family: var(--f-cond);
            white-space: nowrap;
        }
        td {
            padding: var(--sp-2) var(--sp-4);
            border-bottom: 1px solid var(--line);
            font-size: 14.5px;
            vertical-align: middle;
        }
        tr:hover { background: var(--brass-tint); }

        .ts {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
            white-space: nowrap;
        }
        .ts .ts-date {
            font-weight: 600;
        }
        .ts .ts-time {
            font-size: 0.85em;
            color: var(--ink-500);
            font-variant-numeric: tabular-nums;
        }
        .ts-range {
            display: flex;
            align-items: center;
            gap: var(--sp-2);
        }
        .ts-range-arrow {
            color: var(--ink-300);
            font-size: 13px;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px var(--sp-3) 2px 6px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border: 1px solid transparent;
            font-family: var(--f-cond);
            line-height: 1.6;
        }
        .status::before { content: ""; width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
        .status-success { background: var(--paper); color: var(--ink-500); border-color: var(--line-strong); }
        .status-success::before { background: var(--ink-300); }
        .status-pending { background: #fef3c7; color: #B8830A; border-color: #e0c375; }
        .status-pending::before { background: #B8830A; }
        .status-failed { background: #fbeceb; color: #D32F2F; border-color: #e3b3ae; }
        .status-failed::before { background: #D32F2F; }
        .status-info { background: var(--paper); color: var(--ink-500); border-color: var(--line-strong); }
        .status-info::before { background: var(--ink-300); }
        .status-identity { background: #EDE8F5; color: #7C3FBE; border-color: #D4C0E8; }
        .status-identity::before { background: #7C3FBE; }

        .empty-state {
            text-align: center;
            padding: var(--sp-8) var(--sp-4);
            color: var(--ink-300);
        }
        .empty-state .icon { font-size: 30px; display: block; margin-bottom: var(--sp-3); }
        .empty-state p { font-size: 15px; }

        .live-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #D32F2F;
            animation: pulse 1.5s ease-in-out infinite;
            margin-right: var(--sp-2);
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .health-bar-track {
            background: var(--line);
            height: 6px;
            overflow: hidden;
            margin-top: var(--sp-2);
        }
        .health-bar-fill { height: 100%; background: var(--brass); }
        .health-bar-fill.warn { background: #D32F2F; }
        .health-bar-fill.bad { background: #D32F2F; }

        .lookup-card { border-left: 3px solid var(--brass); margin-bottom: var(--sp-4); }
        .lookup-next-action {
            background: var(--brass-tint);
            color: var(--ink-700);
            padding: var(--sp-3);
            margin-top: var(--sp-2);
            font-size: 14px;
            font-weight: 600;
            border-left: 3px solid var(--brass);
        }

        .agent-row { border-left: 3px solid var(--brass); }
        .agent-actions { display: flex; gap: var(--sp-2); justify-content: center; margin-top: var(--sp-3); }

        .report-catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: var(--sp-4);
            margin-bottom: var(--sp-6);
        }
        .report-tile {
            background: var(--panel);
            border: 1px solid var(--line);
            padding: var(--sp-5);
            display: flex;
            flex-direction: column;
            gap: var(--sp-2);
        }
        .report-tile .report-group {
            font-family: var(--f-cond);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--brass-deep);
        }
        .report-tile h3 { font-family: var(--f-display); font-size: 18px; font-weight: 600; }
        .report-tile p { font-size: 13.5px; color: var(--ink-500); flex: 1; }
        .report-tile.pending { opacity: 0.6; }
        .report-tile .report-actions { display: flex; gap: var(--sp-2); margin-top: var(--sp-2); }

        .scorecard-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px var(--sp-3);
            font-family: var(--f-cond);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .scorecard-badge.gold { background: var(--brass-tint); color: var(--brass-deep); border: 1px solid var(--brass); }
        .scorecard-badge.silver { background: var(--paper); color: var(--ink-500); border: 1px solid var(--line-strong); }
        .scorecard-badge.watch { background: #fbeceb; color: var(--bad); border: 1px solid #e3b3ae; }

        .report-page {
            background: var(--panel);
            border: 1px solid var(--line);
            padding: var(--sp-7);
        }
        .report-page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--ink-900);
            padding-bottom: var(--sp-4);
            margin-bottom: var(--sp-6);
            flex-wrap: wrap;
            gap: var(--sp-3);
        }
        .report-page-header .report-title { font-family: var(--f-display); font-size: 24px; font-weight: 600; }
        .report-page-header .report-meta { font-family: var(--f-mono); font-size: 12px; color: var(--ink-300); text-align: right; }
        .report-section-title {
            font-family: var(--f-cond);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--brass-deep);
            margin: var(--sp-6) 0 var(--sp-3);
        }
        .report-section-title:first-child { margin-top: 0; }

        @media print {
            .admin-ribbon, .admin-header, .admin-nav, .page-description, .content-header .back-link, .report-tile .report-actions, .agent-actions, .admin-footer { display: none !important; }
            .admin-content { padding: 0; }
            .card, .report-page { border: 1px solid #999; }
        }

        .admin-footer {
            background: var(--ink-900);
            color: var(--ink-300);
            padding: var(--sp-4) var(--sp-7);
            text-align: center;
            font-size: 12px;
            border-top: 2px solid var(--brass);
            margin-top: var(--sp-4);
            font-family: var(--f-mono);
            line-height: 1.8;
        }
        .admin-footer span { color: var(--brass); }
    </style>
</head>
<body>

    <!-- RIBBON -->
    <div class="admin-ribbon">
        <div class="admin-ribbon-inner">
            <span>VouchMorph Internal Systems &nbsp;·&nbsp; Administrator Access Only</span>
            <span><strong><?php echo safeHtml($roleName); ?></strong> &nbsp;·&nbsp; <?php echo date('Y-m-d H:i:s'); ?></span>
        </div>
    </div>

    <!-- HEADER -->
    <header class="admin-header">
        <div class="admin-header-inner">
            <div class="header-left">
                <div class="logo">VOUCHMORPH <span>Admin</span></div>
                <span class="logo-sub">· <?php echo safeHtml($roleName); ?></span>
                <span class="role-badge"><?php echo safeHtml($roleInfo['label'] ?? $roleName); ?></span>
            </div>
            <div class="user-area">
                <div class="user-details">
                    <div class="user-name"><?php echo safeHtml($adminFullName ?: $adminUsername); ?></div>
                    <div class="user-role"><?php echo safeHtml($roleName); ?></div>
                </div>
                <a href="admin_logout.php" class="logout-btn">Sign Out</a>
            </div>
        </div>
    </header>

    <!-- NAV -->
    <nav class="admin-nav">
        <div class="admin-nav-inner">
        <?php if (canView('dashboard')): ?><a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">Dashboard</a><?php endif; ?>
        <?php if (canView('client_lookup')): ?><a href="?view=client_lookup" class="nav-item <?php echo $view === 'client_lookup' ? 'active' : ''; ?>">Client Lookup</a><?php endif; ?>
        <?php if (canView('agent_approvals')): ?>
        <a href="?view=agent_approvals" class="nav-item <?php echo $view === 'agent_approvals' ? 'active' : ''; ?>">
            Agents <?php if ($agentApprovalCount > 0): ?><span class="nav-badge"><?php echo $agentApprovalCount; ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (canView('alerts')): ?>
        <a href="?view=alerts" class="nav-item <?php echo $view === 'alerts' ? 'active' : ''; ?>">
            Alerts <?php if ($totalAlerts > 0): ?><span class="nav-badge"><?php echo $totalAlerts; ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (canView('live_transactions')): ?><a href="?view=live_transactions" class="nav-item <?php echo $view === 'live_transactions' ? 'active' : ''; ?>">Live Txns</a><?php endif; ?>
        <?php if (canView('multi_destination')): ?><a href="?view=multi_destination" class="nav-item <?php echo $view === 'multi_destination' ? 'active' : ''; ?>">Multi-Dest</a><?php endif; ?>
        <?php if (canView('recent_swaps')): ?><a href="?view=recent_swaps" class="nav-item <?php echo $view === 'recent_swaps' ? 'active' : ''; ?>">Swaps</a><?php endif; ?>
        <?php if (canView('institution_health')): ?><a href="?view=institution_health" class="nav-item <?php echo $view === 'institution_health' ? 'active' : ''; ?>">Institutions</a><?php endif; ?>
        <?php if (canView('participants')): ?><a href="?view=participants" class="nav-item <?php echo $view === 'participants' ? 'active' : ''; ?>">Participants</a><?php endif; ?>
        <?php if (canView('regulatory')): ?><a href="?view=regulatory" class="nav-item <?php echo $view === 'regulatory' ? 'active' : ''; ?>">Regulatory</a><?php endif; ?>
        <?php if (canView('reports')): ?><a href="?view=reports" class="nav-item <?php echo $view === 'reports' ? 'active' : ''; ?>">Reports</a><?php endif; ?>
        <?php if (canView('audit')): ?><a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">Audit</a><?php endif; ?>
        <?php if (canView('ledger')): ?><a href="?view=ledger" class="nav-item <?php echo $view === 'ledger' ? 'active' : ''; ?>">Ledger</a><?php endif; ?>
        <?php if (canView('invoices')): ?><a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">Invoices &amp; Settlement</a><?php endif; ?>
        <?php if (canView('all_tables') && $isSuperAdmin): ?><a href="?view=all_tables" class="nav-item <?php echo $view === 'all_tables' ? 'active' : ''; ?>">Tables</a><?php endif; ?>
        </div>
    </nav>


    <!-- DESCRIPTION BAR -->
    <div class="page-description">
        <div class="page-description-inner">
            <span class="eyebrow"><?php echo safeHtml($currentMeta['eyebrow']); ?></span>
            <p><?php echo safeHtml($currentMeta['blurb']); ?></p>
        </div>
    </div>

    <main class="admin-content"><div class="admin-content-inner">

            <?php if (!empty($flash)): ?>
            <div class="card" role="status" style="border-left:3px solid <?php echo $flash['type'] === 'good' ? 'var(--good)' : 'var(--bad)'; ?>;">
                <div style="text-align:center;font-size:15px;font-weight:600;color:<?php echo $flash['type'] === 'good' ? 'var(--good)' : 'var(--bad)'; ?>;"><?php echo safeHtml($flash['text']); ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($missingRelations)): ?>
            <div class="card" style="border-left:3px solid var(--bad);">
                <div class="card-header"><span class="card-title" style="color:var(--bad);">Database objects missing</span><span class="card-badge"><?php echo count($missingRelations); ?></span></div>
                <p style="font-size:14px;text-align:center;">Panels that read these will be empty because the data cannot be read, not because there is none:
                    <code><?php echo safeHtml(implode(', ', $missingRelations)); ?></code></p>
            </div>
            <?php endif; ?>

            <!-- DASHBOARD -->
            <?php if ($view === 'dashboard'): ?>
            <div class="content-header">
                <h1>Dashboard</h1>
                <span class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></span>
            </div>

            <?php if ($totalAlerts > 0 && canView('alerts')): ?>
            <div class="card" style="border-color:var(--line-strong);">
                <div class="card-header">
                    <span class="card-title" style="color:#D32F2F;">⚠️ <?php echo $totalAlerts; ?> item<?php echo $totalAlerts === 1 ? '' : 's'; ?> require attention</span>
                    <a href="?view=alerts" class="btn btn-primary btn-sm">View Alerts</a>
                </div>
                <div style="font-size:14px; color:var(--ink-500); text-align:center;">
                    <?php echo count($alerts['stuck_holds']); ?> stuck holds · <?php echo count($alerts['expired_identity_swaps']); ?> expired ·
                    <?php echo count($alerts['stuck_cashouts']); ?> stuck cashouts
                </div>
            </div>
            <?php endif; ?>

            <?php if ($agentApprovalCount > 0 && canView('agent_approvals')): ?>
            <div class="card" style="border-color:var(--line-strong);">
                <div class="card-header">
                    <span class="card-title" style="color:var(--brass-deep);">🧑‍💼 <?php echo $agentApprovalCount; ?> agent<?php echo $agentApprovalCount === 1 ? '' : 's'; ?> awaiting approval</span>
                    <a href="?view=agent_approvals" class="btn btn-primary btn-sm">Review Agents</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="metrics-grid">
                <div class="metric-card"><span class="metric-label">Total Swaps</span><span class="metric-value"><?php echo number_format($metrics['total_swaps'] ?? 0); ?></span><span class="metric-sub">Lifetime</span></div>
                <div class="metric-card"><span class="metric-label">Total Users</span><span class="metric-value"><?php echo number_format($metrics['total_users'] ?? 0); ?></span><span class="metric-sub">Registered</span></div>
                <div class="metric-card"><span class="metric-label">Pending Settlements</span><span class="metric-value"><?php echo number_format($metrics['pending_settlements'] ?? 0); ?></span><span class="metric-sub">Awaiting</span></div>
                <div class="metric-card"><span class="metric-label">Total Fees</span><span class="metric-value"><?php echo number_format($metrics['total_fees'] ?? 0, 2); ?></span><span class="metric-sub">Collected</span></div>
                <div class="metric-card"><span class="metric-label">24h Swaps</span><span class="metric-value"><?php echo number_format($metrics['recent_swaps_24h'] ?? 0); ?></span><span class="metric-sub">Last 24h</span></div>
                <div class="metric-card"><span class="metric-label">Multi-Destination</span><span class="metric-value"><?php echo number_format($metrics['multi_destination_count'] ?? 0); ?></span><span class="metric-sub">Batches</span></div>
            </div>

            <div class="card">
                <div class="card-header"><span class="card-title">Quick Actions</span></div>
                <div style="display:flex; justify-content:center; gap:var(--sp-3); flex-wrap:wrap;">
                    <?php if (canView('reports')): ?><a href="?view=reports" class="btn btn-primary">Reports</a><?php endif; ?>
                    <?php if (canView('invoices')): ?><a href="?view=invoices" class="btn">Invoices</a><?php endif; ?>
                    <?php if (canView('alerts')): ?><a href="?view=alerts" class="btn">Alerts</a><?php endif; ?>
                    <?php if (canView('agent_approvals')): ?><a href="?view=agent_approvals" class="btn">Agents</a><?php endif; ?>
                    <?php if (canView('institution_health')): ?><a href="?view=institution_health" class="btn">Institution Health</a><?php endif; ?>
                    <?php if (canView('participants')): ?><a href="?view=participants" class="btn">Participants</a><?php endif; ?>
                    <?php if (canView('client_lookup')): ?><a href="?view=client_lookup" class="btn">Client Lookup</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- AGENT APPROVALS -->
            <?php if ($view === 'agent_approvals' && canView('agent_approvals')): ?>
            <div class="content-header">
                <h1>Agent Approvals</h1>
                <span class="timestamp">Agent destination accounts awaiting manual verification</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));">
                <div class="metric-card"><span class="metric-label">Pending</span><span class="metric-value"><?php echo number_format($agentCounts['pending']); ?></span></div>
                <div class="metric-card"><span class="metric-label">Approved</span><span class="metric-value" style="color:var(--good);"><?php echo number_format($agentCounts['approved']); ?></span></div>
                <div class="metric-card"><span class="metric-label">Rejected</span><span class="metric-value" style="color:var(--bad);"><?php echo number_format($agentCounts['rejected']); ?></span></div>
            </div>

            <div class="card">
                <div class="card-header"><span class="card-title">Pending Approval</span><span class="card-badge brass"><?php echo count($pendingAgents); ?></span></div>
                <p style="font-size:13px;color:var(--ink-500);text-align:center;margin-bottom:var(--sp-4);">
                    These institutions could not confirm account ownership automatically. Confirm with the institution that
                    the account belongs to this agent before approving. Every decision is written to the audit log with your name;
                    if that record cannot be written, the decision is not made.
                </p>
                <?php if (empty($pendingAgents)): ?>
                <div class="empty-state"><span class="icon">✅</span><p>No agent accounts waiting on approval.</p></div>
                <?php else: foreach ($pendingAgents as $agent): ?>
                <div class="card agent-row">
                    <div class="card-header">
                        <span class="card-title"><?php echo safeHtml($agent['full_name'] ?? ('User #' . $agent['user_id'])); ?></span>
                        <span class="status status-pending">PENDING</span>
                    </div>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap:var(--sp-2); font-size:14px;">
                        <div><strong>Phone:</strong> <?php echo safeHtml($agent['phone'] ?? 'N/A'); ?></div>
                        <div><strong>Email:</strong> <?php echo safeHtml($agent['email'] ?? 'N/A'); ?></div>
                        <div><strong>Institution:</strong> <?php echo safeHtml($agent['institution'] ?? 'N/A'); ?></div>
                        <div><strong>Account:</strong> <?php echo safeHtml(($agent['asset_type'] ?? '') . ' ' . ($agent['identifier'] ?? '')); ?></div>
                        <div><strong>Account name:</strong> <?php echo safeHtml($agent['account_name'] ?? '—'); ?></div>
                        <div><strong>Applied:</strong> <?php echo tsHtml($agent['created_at'] ?? ''); ?></div>
                    </div>
                    <div class="agent-actions" style="flex-wrap:wrap;align-items:flex-start;">
                        <form class="inline-form" method="post" action="?view=agent_approvals" onsubmit="return confirm('Approve this agent account? This is recorded in the audit log.');">
                            <input type="hidden" name="csrf_token" value="<?php echo safeHtml($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="agent_id" value="<?php echo safeHtml($agent['id']); ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-good btn-sm">Approve</button>
                        </form>
                        <form class="inline-form search-box" style="margin:0;" method="post" action="?view=agent_approvals">
                            <input type="hidden" name="csrf_token" value="<?php echo safeHtml($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="agent_id" value="<?php echo safeHtml($agent['id']); ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="text" name="reason" required minlength="5" maxlength="500" placeholder="Reason for rejecting (required)" style="min-width:220px;height:var(--btn-h-sm);">
                            <button type="submit" class="btn btn-bad btn-sm">Reject</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="card">
                <div class="card-header"><span class="card-title">Agent Account Registry</span><span class="card-badge"><?php echo count($allAgents); ?></span></div>
                <?php if (empty($allAgents)): ?>
                <div class="empty-state"><span class="icon">📭</span><p>No agent destination accounts registered yet.</p></div>
                <?php else: ?>
                <div class="table-responsive"><table><thead><tr><th>Agent</th><th>Phone</th><th>Institution</th><th>Account</th><th>Status</th><th>Applied</th><th>Confirmed</th></tr></thead><tbody>
                <?php foreach ($allAgents as $agent): $st = strtolower($agent['status'] ?? ''); $cls = match($st) { 'active' => 'success', 'pending_confirmation' => 'pending', 'rejected', 'cancelled' => 'failed', default => 'info' }; ?>
                <tr>
                    <td><?php echo safeHtml($agent['full_name'] ?? ('User #' . $agent['user_id'])); ?></td>
                    <td><?php echo safeHtml($agent['phone'] ?? 'N/A'); ?></td>
                    <td><?php echo safeHtml($agent['institution'] ?? 'N/A'); ?></td>
                    <td><?php echo safeHtml(($agent['asset_type'] ?? '') . ' ' . ($agent['identifier'] ?? '')); ?></td>
                    <td><span class="status status-<?php echo $cls; ?>"><?php echo safeHtml(strtoupper(str_replace('_', ' ', $agent['status'] ?? ''))); ?></span></td>
                    <td><?php echo tsHtml($agent['created_at'] ?? ''); ?></td>
                    <td><?php echo tsHtml($agent['approved_at'] ?? '', '—'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- CLIENT LOOKUP -->
            <?php if ($view === 'client_lookup' && canView('client_lookup')): ?>
            <div class="content-header">
                <h1>Client Lookup</h1>
                <span class="timestamp">Search by phone, national ID, or reference</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <form method="get" class="search-box">
                <input type="hidden" name="view" value="client_lookup">
                <input type="text" name="lookup" placeholder="Enter phone, national ID, or reference..." value="<?php echo safeHtml($lookup); ?>" autofocus>
                <button type="submit" class="btn btn-primary">Search</button>
            </form>
            <?php if ($lookup === ''): ?>
            <div class="card"><div class="empty-state"><span class="icon">📞</span><p>Enter what the client gave you — a phone number, national ID, or reference code.</p></div></div>
            <?php elseif (empty($lookupResults)): ?>
            <div class="card"><div class="empty-state"><span class="icon">🔍</span><p>No matches found for "<?php echo safeHtml($lookup); ?>".</p></div></div>
            <?php else: foreach ($lookupResults as $r): ?>
            <div class="card lookup-card">
                <div class="card-header">
                    <span class="card-title"><?php echo safeHtml($r['kind']); ?> — <?php echo safeHtml($r['reference']); ?></span>
                    <?php $st = strtolower($r['status'] ?? ''); $cls = match(true) { str_contains($st, 'complet') || str_contains($st, 'success') => 'success', str_contains($st, 'pending') || str_contains($st, 'verified') => 'pending', str_contains($st, 'fail') || str_contains($st, 'expired') => 'failed', default => 'info' }; ?>
                    <span class="status status-<?php echo $cls; ?>"><?php echo safeHtml($r['status']); ?></span>
                </div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px,1fr)); gap:var(--sp-2); font-size:14px;">
                    <div><strong>Amount:</strong> <?php echo number_format((float)$r['amount'], 2); ?> <?php echo safeHtml($r['currency']); ?></div>
                    <div><strong>Route:</strong> <?php echo safeHtml($r['institution']); ?></div>
                    <?php if (!empty($r['identity'])): ?><div><strong>Identity:</strong> <?php echo safeHtml($r['identity']); ?></div><?php endif; ?>
                    <div><strong>Date:</strong> <?php echo safeHtml(date('Y-m-d H:i', strtotime($r['created_at'] ?? 'now'))); ?></div>
                </div>
                <div class="lookup-next-action">→ <?php echo safeHtml($r['next_action']); ?></div>
                <div style="text-align:right;margin-top:var(--sp-2);">
                    <a href="?view=reports&report=transaction_certificate&ref=<?php echo urlencode($r['reference']); ?>" class="btn btn-sm">View Full Certificate</a>
                </div>
            </div>
            <?php endforeach; endif; ?>
            <?php endif; ?>

            <!-- ALERTS -->
            <?php if ($view === 'alerts' && canView('alerts')): ?>
            <div class="content-header">
                <h1>Alerts</h1>
                <span class="timestamp">Items requiring attention</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));">
                <div class="metric-card"><span class="metric-label">Stuck Holds</span><span class="metric-value"><?php echo number_format(count($alerts['stuck_holds'])); ?></span><span class="metric-sub">&ge;20h (24h limit)</span></div>
                <div class="metric-card"><span class="metric-label">Expired Identity</span><span class="metric-value"><?php echo number_format(count($alerts['expired_identity_swaps'])); ?></span><span class="metric-sub">Unconfirmed</span></div>
                <div class="metric-card"><span class="metric-label">Stuck Cashouts</span><span class="metric-value"><?php echo number_format(count($alerts['stuck_cashouts'])); ?></span><span class="metric-sub">Expired</span></div>
            </div>
            <?php if ($totalAlerts === 0): ?>
            <div class="card"><div class="empty-state"><span class="icon">✅</span><p>All systems clear.</p></div></div>
            <?php endif; ?>
            <?php if (!empty($alerts['stuck_holds'])): ?>
            <div class="card">
                <div class="card-header"><span class="card-title">🔒 Stuck Holds</span><span class="card-badge"><?php echo count($alerts['stuck_holds']); ?></span></div>
                <div class="table-responsive"><table><thead><tr><th>Hold Ref</th><th>Swap Ref</th><th>Institution</th><th>Amount</th><th>Status</th><th>Age</th></tr></thead><tbody>
                <?php foreach ($alerts['stuck_holds'] as $h): ?>
                <tr><td><?php echo safeHtml(substr($h['hold_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml(substr($h['swap_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml($h['institution'] ?? 'N/A'); ?></td><td><?php echo number_format((float)($h['amount'] ?? 0), 2); ?></td><td><span class="status status-pending"><?php echo safeHtml($h['status'] ?? ''); ?></span></td><?php $ageH = round((time() - strtotime($h['created_at'] ?? 'now')) / 3600, 1); ?><td style="font-weight:700;color:<?php echo $ageH >= 24 ? 'var(--bad)' : '#B8830A'; ?>;"><?php echo $ageH; ?>h<?php echo $ageH >= 24 ? ' &middot; OVER LIMIT' : ''; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($alerts['expired_identity_swaps'])): ?>
            <div class="card">
                <div class="card-header"><span class="card-title">🪪 Expired Identity</span><span class="card-badge"><?php echo count($alerts['expired_identity_swaps']); ?></span></div>
                <div class="table-responsive"><table><thead><tr><th>Swap Ref</th><th>Institution</th><th>Identity</th><th>Amount</th><th>Expired</th></tr></thead><tbody>
                <?php foreach ($alerts['expired_identity_swaps'] as $s): ?>
                <tr><td><?php echo safeHtml(substr($s['swap_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml($s['source_institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($s['identity_type'] ?? ''); ?>: <?php echo safeHtml($s['identity_value'] ?? ''); ?></td><td><?php echo number_format((float)($s['amount'] ?? 0), 2); ?></td><td><?php echo safeHtml($s['hold_expires_at'] ?? ''); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($alerts['stuck_cashouts'])): ?>
            <div class="card">
                <div class="card-header"><span class="card-title">💵 Expired Cashouts</span><span class="card-badge"><?php echo count($alerts['stuck_cashouts']); ?></span></div>
                <div class="table-responsive"><table><thead><tr><th>Swap Ref</th><th>Source</th><th>Provider</th><th>Phone</th><th>Amount</th><th>Expired</th></tr></thead><tbody>
                <?php foreach ($alerts['stuck_cashouts'] as $c): ?>
                <tr><td><?php echo safeHtml(substr($c['swap_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml($c['source_institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($c['cashout_provider'] ?? 'N/A'); ?></td><td><?php echo safeHtml($c['client_phone'] ?? 'N/A'); ?></td><td><?php echo number_format((float)($c['amount'] ?? 0), 2); ?></td><td><?php echo safeHtml($c['code_expiry'] ?? ''); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- INSTITUTION HEALTH -->
            <?php if ($view === 'institution_health' && canView('institution_health')): ?>
            <div class="content-header">
                <h1>Institution Health</h1>
                <span class="timestamp">Volume · Success Rate</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <?php if (empty($institutionHealth)): ?>
            <div class="card"><div class="empty-state"><span class="icon">📭</span><p>No institution data available</p></div></div>
            <?php else: foreach ($institutionHealth as $inst): $rate = (float)$inst['success_rate']; $barClass = $rate >= 90 ? '' : ($rate >= 70 ? 'warn' : 'bad'); ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><?php echo safeHtml($inst['institution']); ?></span>
                    <span class="card-badge brass"><?php echo $rate; ?>% SUCCESS</span>
                </div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap:var(--sp-2); margin-bottom:var(--sp-2); font-size:14px;">
                    <div><strong>Total:</strong> <?php echo number_format($inst['total']); ?></div>
                    <div style="color:var(--ink-500);"><strong>Success:</strong> <?php echo number_format($inst['successful']); ?></div>
                    <div><strong>Volume:</strong> <?php echo number_format((float)$inst['volume'], 2); ?></div>
                </div>
                <div class="health-bar-track"><div class="health-bar-fill <?php echo $barClass; ?>" style="width: <?php echo min(100, $rate); ?>%;"></div></div>
                <div style="text-align:right;margin-top:var(--sp-3);">
                    <a href="?view=reports&report=bank_statement&institution=<?php echo urlencode($inst['institution']); ?>" class="btn btn-sm">View Statement</a>
                </div>
            </div>
            <?php endforeach; endif; ?>
            <?php endif; ?>

            <!-- PARTICIPANTS -->
            <?php if ($view === 'participants' && canView('participants')): ?>
            <div class="content-header">
                <h1>Participants</h1>
                <span class="timestamp"><?php echo safeHtml($participantsCountry); ?> · <?php echo count($participantsList); ?> configured</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>

            <?php if ($validationResult): ?>
            <div class="card" style="border-left: 3px solid <?php echo $validationResult['ready'] ? 'var(--good)' : 'var(--bad)'; ?>;">
                <div class="card-header">
                    <span class="card-title"><?php echo $validationResult['ready'] ? '✅' : '🚫'; ?> Validation: <?php echo safeHtml($validationResult['institution']); ?></span>
                    <span class="card-badge <?php echo $validationResult['ready'] ? '' : 'brass'; ?>">Currently: <?php echo strtoupper($validationResult['current_status']); ?></span>
                </div>
                <?php if (!empty($validationResult['passed'])): ?>
                <div style="margin-bottom:var(--sp-3);">
                    <?php foreach ($validationResult['passed'] as $p): ?>
                    <div style="font-size:13.5px; color:var(--good);">✓ <?php echo safeHtml($p); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($validationResult['warnings'])): ?>
                <div style="margin-bottom:var(--sp-3);">
                    <?php foreach ($validationResult['warnings'] as $w): ?>
                    <div style="font-size:13.5px; color:var(--brass-deep);">⚠️ <?php echo safeHtml($w); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($validationResult['errors'])): ?>
                <div>
                    <?php foreach ($validationResult['errors'] as $e): ?>
                    <div style="font-size:13.5px; color:var(--bad); font-weight:600;">✗ <?php echo safeHtml($e); ?></div>
                    <?php endforeach; ?>
                </div>
                <p style="font-size:12px; color:var(--ink-300); margin-top:var(--sp-3);">Not ready for promotion beyond <code>sandbox</code>. Fix the above, then re-run the standalone test harness for this institution before promoting status in <code>participants.yaml</code> directly.</p>
                <?php else: ?>
                <p style="font-size:12px; color:var(--ink-300); margin-top:var(--sp-3);">Config shape is complete. This does not confirm the institution's real API responds correctly — run the per-institution test harness against their sandbox before promoting status.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (empty($participantsList)): ?>
            <div class="card"><div class="empty-state"><span class="icon">📭</span><p>No participants configured for <?php echo safeHtml($participantsCountry); ?>. Add entries to <code>participants.yaml</code> — see the Onboarding Playbook.</p></div></div>
            <?php else: ?>
            <div class="card">
                <div class="card-header"><span class="card-title">Configured Institutions</span><span class="card-badge"><?php echo count($participantsList); ?></span></div>
                <div class="table-responsive"><table><thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Status</th><th>Adapter</th><th>Switches</th><th></th></tr></thead><tbody>
                <?php foreach ($participantsList as $code => $p): $status = strtolower($p['status'] ?? 'sandbox'); $statusClass = match($status) { 'live' => 'success', 'staging' => 'pending', default => 'info' }; $switches = $p['settlement']['switches'] ?? []; ?>
                <tr>
                    <td><strong><?php echo safeHtml($code); ?></strong></td>
                    <td><?php echo safeHtml($p['name'] ?? 'N/A'); ?></td>
                    <td><span class="status status-identity"><?php echo safeHtml($p['type'] ?? 'N/A'); ?></span></td>
                    <td><span class="status status-<?php echo $statusClass; ?>"><?php echo strtoupper($status); ?></span></td>
                    <td><?php echo safeHtml($p['adapter'] ?? 'N/A'); ?></td>
                    <td><?php echo empty($switches) ? '<span style="color:var(--ink-300);">direct only</span>' : safeHtml(implode(', ', $switches)); ?></td>
                    <td><a href="?view=participants&validate=<?php echo urlencode($code); ?>" class="btn btn-sm">Validate</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>
            <p style="font-size:12px; color:var(--ink-300); text-align:center;">
                Status changes are made directly in <code>participants.yaml</code> as a reviewed config change, not from this page —
                routing config controls literal money movement, so promotion stays out of a web-clickable action.
                See the Onboarding Playbook for the full checklist.
            </p>
            <?php endif; ?>
            <?php endif; ?>

            <!-- LIVE TRANSACTIONS -->
            <?php if ($view === 'live_transactions' && canView('live_transactions')): ?>
            <div class="content-header">
                <h1><span class="live-indicator"></span> Live Transactions</h1>
                <span class="timestamp"><?php echo date('Y-m-d H:i:s'); ?> · <?php echo count($liveTransactions); ?> transactions</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <form method="get" class="search-box">
                <input type="hidden" name="view" value="live_transactions">
                <input type="text" name="search" placeholder="Search reference, institution, status..." value="<?php echo safeHtml($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search !== ''): ?><a href="?view=live_transactions" class="btn btn-sm">Clear</a><?php endif; ?>
            </form>
            <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));">
                <div class="metric-card"><span class="metric-label">24h Total</span><span class="metric-value"><?php echo number_format($liveStats['total'] ?? 0); ?></span></div>
                <div class="metric-card"><span class="metric-label">Completed</span><span class="metric-value" style="color:var(--ink-500);"><?php echo number_format($liveStats['completed'] ?? 0); ?></span></div>
                <div class="metric-card"><span class="metric-label">Pending</span><span class="metric-value"><?php echo number_format($liveStats['pending'] ?? 0); ?></span></div>
                <div class="metric-card"><span class="metric-label">Failed</span><span class="metric-value"><?php echo number_format($liveStats['failed'] ?? 0); ?></span></div>
                <div class="metric-card"><span class="metric-label">Volume</span><span class="metric-value"><?php echo number_format($liveStats['total_amount'] ?? 0, 2); ?></span></div>
            </div>
            <div class="card">
                <div class="card-header"><span class="card-title">Live Feed</span><span class="card-badge" id="liveCount"><?php echo count($liveTransactions); ?></span></div>
                <div class="table-responsive"><table><thead><tr><th>#</th><th>Reference</th><th>Type</th><th>Amount</th><th>Status</th><th>Source</th><th>Destination</th><th>Created</th></tr></thead><tbody id="liveTransactionsBody">
                <?php if (empty($liveTransactions)): ?>
                <tr><td colspan="8" class="empty-state">No transactions<?php echo $search !== '' ? ' for "' . safeHtml($search) . '"' : ''; ?></td></tr>
                <?php else: foreach ($liveTransactions as $index => $row): $type = $row['swap_type'] ?? 'STANDARD'; $status = strtolower($row['status'] ?? 'pending'); $class = match(true) { str_contains($status, 'complet') || str_contains($status, 'success') => 'success', str_contains($status, 'pending') || str_contains($status, 'processing') => 'pending', str_contains($status, 'fail') || str_contains($status, 'error') => 'failed', default => 'info' }; ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['reference'] ?? 'N/A', 0, 14)); ?></td>
                    <td><span class="status status-info"><?php echo safeHtml($type); ?></span></td>
                    <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                    <td><span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span></td>
                    <td><?php echo safeHtml($row['source_institution'] ?? 'N/A'); ?></td>
                    <td><?php echo safeHtml($row['destination_institution'] ?? 'N/A'); ?></td>
                    <td><?php echo tsHtml($row['created_at'] ?? ''); ?></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>
            <script>
                let autoRefresh = true; let refreshInterval = null;
                function startAutoRefresh() { clearInterval(refreshInterval); refreshInterval = setInterval(function() { fetch(window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'ajax=1').then(r => r.json()).then(data => { if (data.transactions) { const tbody = document.getElementById('liveTransactionsBody'); let html = ''; data.transactions.forEach((row, i) => { const status = (row.status || 'pending').toLowerCase(); let cls = 'info'; if (status.includes('complet') || status.includes('success')) cls = 'success'; else if (status.includes('pending') || status.includes('processing')) cls = 'pending'; else if (status.includes('fail') || status.includes('error')) cls = 'failed'; html += `<tr><td>${i+1}</td><td>${(row.swap_reference || row.reference || 'N/A').substring(0,14)}</td><td><span class="status status-info">${row.swap_type || 'STANDARD'}</span></td><td><strong>${Number(row.amount || 0).toFixed(2)}</strong></td><td><span class="status status-${cls}">${row.status || 'pending'}</span></td><td>${row.source_institution || 'N/A'}</td><td>${row.destination_institution || 'N/A'}</td><td>${new Date(row.created_at).toLocaleString('en-GB', {timeZone: 'Africa/Gaborone', hour12: false})}</td></tr>`; }); tbody.innerHTML = html; document.getElementById('liveCount').textContent = data.transactions.length; } }).catch(e => console.error('Refresh failed:', e)); }, 5000); }
                startAutoRefresh();
            </script>
            <?php endif; ?>

            <!-- RECENT SWAPS -->
            <?php if ($view === 'recent_swaps' && canView('recent_swaps')): ?>
            <div class="content-header">
                <h1>Recent Swaps</h1>
                <span class="timestamp">Complete transaction history</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <form method="get" class="search-box">
                <input type="hidden" name="view" value="recent_swaps">
                <input type="text" name="search" placeholder="Search reference, institution, status..." value="<?php echo safeHtml($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search !== ''): ?><a href="?view=recent_swaps" class="btn btn-sm">Clear</a><?php endif; ?>
            </form>
            <div class="card">
                <div class="card-header"><span class="card-title">All Swaps</span><span class="card-badge"><?php echo count($recentSwaps); ?></span></div>
                <div class="table-responsive"><table><thead><tr><th>Reference</th><th>Type</th><th>Amount</th><th>Status</th><th>Source</th><th>Destination</th><th>Created</th><th></th></tr></thead><tbody>
                <?php if (empty($recentSwaps)): ?>
                <tr><td colspan="8" class="empty-state">No swaps<?php echo $search !== '' ? ' for "' . safeHtml($search) . '"' : ''; ?></td></tr>
                <?php else: foreach ($recentSwaps as $row): $status = strtolower($row['status'] ?? 'pending'); $class = match(true) { str_contains($status, 'complet') || str_contains($status, 'success') => 'success', str_contains($status, 'pending') || str_contains($status, 'processing') => 'pending', str_contains($status, 'fail') || str_contains($status, 'error') => 'failed', default => 'info' }; $ref = $row['swap_reference'] ?? $row['reference'] ?? ''; ?>
                <tr>
                    <td><?php echo safeHtml(substr($ref, 0, 16)); ?></td>
                    <td><span class="status status-info"><?php echo safeHtml($row['swap_type'] ?? 'STANDARD'); ?></span></td>
                    <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                    <td><span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span></td>
                    <td><?php echo safeHtml($row['source_institution'] ?? 'N/A'); ?></td>
                    <td><?php echo safeHtml($row['destination_institution'] ?? 'N/A'); ?></td>
                    <td><?php echo tsHtml($row['created_at'] ?? ''); ?></td>
                    <td><a href="?view=reports&report=transaction_certificate&ref=<?php echo urlencode($ref); ?>" class="btn btn-sm">Certificate</a></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>
            <?php endif; ?>

            <!-- MULTI-DESTINATION -->
            <?php if ($view === 'multi_destination' && canView('multi_destination')): ?>
            <div class="content-header">
                <h1>Multi-Destination Swaps</h1>
                <span class="timestamp">Batch disbursements</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <?php if (empty($multiDestinationSwaps)): ?>
            <div class="card"><div class="empty-state"><span class="icon">📭</span><p>No multi-destination swaps found</p></div></div>
            <?php else: foreach ($multiDestinationSwaps as $swap): $destinations = json_decode($swap['destinations_payload'] ?? '[]', true); $results = json_decode($swap['results_payload'] ?? '[]', true) ?: []; ?>
            <div class="card" style="border-left: 3px solid <?php echo $swap['status'] === 'completed' ? 'var(--brass)' : 'var(--ink-300)'; ?>;">
                <div class="card-header">
                    <span class="card-title"><?php echo safeHtml($swap['reference']); ?> <span style="font-weight:400;color:var(--ink-300);font-size:12px;"><?php echo date('Y-m-d H:i', strtotime($swap['created_at'])); ?></span></span>
                    <span class="card-badge <?php echo $swap['status'] === 'completed' ? 'brass' : ''; ?>"><?php echo strtoupper($swap['status'] ?? 'UNKNOWN'); ?></span>
                </div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap:var(--sp-2); margin-bottom:var(--sp-3); font-size:13px; background:var(--paper); padding:var(--sp-3);">
                    <div><strong>Source:</strong> <?php echo safeHtml($swap['source_institution']); ?></div>
                    <div><strong>Total:</strong> <?php echo number_format((float)($swap['total_amount'] ?? 0), 2); ?></div>
                    <div><strong>✅</strong> <?php echo $swap['successful_count'] ?? 0; ?></div>
                    <div><strong>❌</strong> <?php echo $swap['failed_count'] ?? 0; ?></div>
                    <div><strong>📦</strong> <?php echo $swap['total_destinations']; ?></div>
                </div>
                <?php if (!empty($destinations)): ?>
                <div class="table-responsive"><table><thead><tr><th>#</th><th>Type</th><th>Institution</th><th>Identifier</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>
                <?php foreach ($destinations as $idx => $dest): $result = $results[$idx] ?? []; $status = $result['status'] ?? 'pending'; $isIdentity = isset($dest['identity_type']) || isset($dest['identity_value']); $isCashout = isset($dest['delivery_method']) && $dest['delivery_method'] === 'ATM';
                    // Child hold references follow {batch_reference}_{DEST|ID}_{index}, confirmed
                    // against the Double-Spend & Duplicate-Debit Check output (e.g. MIXED_SWAP_1784534859_ID_1
                    // for the identity-type destination at index 1). This lets each row link straight to
                    // its own Transaction Certificate rather than sitting inert.
                    $destRef = ($swap['reference'] ?? '') . '_' . ($isIdentity ? 'ID' : 'DEST') . '_' . $idx;
                ?>
                <tr>
                    <td><?php echo $idx + 1; ?></td>
                    <td><?php if ($isIdentity): ?><span class="status status-identity">IDENTITY</span><?php elseif ($isCashout): ?><span class="status status-pending">CASHOUT</span><?php else: ?><span class="status status-info">DEPOSIT</span><?php endif; ?></td>
                    <td><?php echo safeHtml($dest['to_institution'] ?? $dest['destination_institution'] ?? ($isIdentity ? 'IDENTITY' : 'N/A')); ?></td>
                    <td><?php if ($isIdentity) { echo safeHtml($dest['identity_type'] ?? 'national_id') . ': ' . safeHtml($dest['identity_value'] ?? 'N/A'); } elseif ($isCashout) { echo safeHtml($dest['beneficiary_phone'] ?? 'N/A'); } else { echo safeHtml($dest['destination_identifier'] ?? 'N/A'); } ?></td>
                    <td><strong><?php echo number_format((float)($dest['amount'] ?? 0), 2); ?></strong></td>
                    <td>
                        <?php $statusClass = match($status) { 'success', 'completed' => 'success', 'failed' => 'failed', 'pending' => 'pending', default => 'info' }; ?>
                        <span class="status status-<?php echo $statusClass; ?>"><?php echo safeHtml(strtoupper($status ?: 'PENDING')); ?></span>
                    </td>
                    <td><a href="?view=reports&report=transaction_certificate&ref=<?php echo urlencode($destRef); ?>" class="btn btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
            <?php endif; ?>

            <!-- REPORTS HUB -->
            <?php if ($view === 'reports' && canView('reports')): ?>
                <?php if ($reportKey === ''): ?>
                <div class="content-header">
                    <h1>Reporting Suite</h1>
                    <span class="timestamp">Choose a report to generate</span>
                    <a href="?view=dashboard" class="back-link">← Back</a>
                </div>
                <?php
                $groupsOrder = ['Trust & Integrity', 'Executive', 'Regulatory', 'Compliance', 'Finance', 'Audit', 'Reconciliation'];
                $canViewCompliance = canView('audit') || canView('regulatory');
                foreach ($groupsOrder as $grp):
                    if ($grp === 'Compliance' && !$canViewCompliance) continue;
                    $tiles = array_filter($reportCatalog, fn($r) => $r['group'] === $grp);
                    if (empty($tiles)) continue;
                ?>
                <div class="report-section-title"><?php echo safeHtml($grp); ?></div>
                <div class="report-catalog-grid">
                    <?php foreach ($tiles as $key => $r): $isPending = !empty($r['pending']); ?>
                    <div class="report-tile <?php echo $isPending ? 'pending' : ''; ?>">
                        <span class="report-group"><?php echo safeHtml($r['group']); ?></span>
                        <h3><?php echo safeHtml($r['title']); ?></h3>
                        <p><?php echo safeHtml($r['blurb']); ?></p>
                        <div class="report-actions">
                            <?php if ($isPending): ?>
                            <span class="btn btn-sm" style="cursor:default;">Awaiting Data</span>
                            <?php else: ?>
                            <a href="?view=reports&report=<?php echo urlencode($key); ?>" class="btn btn-primary btn-sm">Generate</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php else: /* ---------- INDIVIDUAL REPORT RENDER ---------- */ ?>
                <div class="content-header">
                    <h1><?php echo safeHtml($reportCatalog[$reportKey]['title'] ?? 'Report'); ?></h1>
                    <span class="timestamp">Generated <?php echo date('Y-m-d H:i:s'); ?></span>
                    <a href="?view=reports" class="back-link">← All Reports</a>
                </div>

                <?php if ($reportKey === 'transaction_certificate'): ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div><div class="report-title">Transaction Certificate</div><div style="color:var(--ink-500); font-size:13px;">The complete signed record for one transaction</div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <form method="get" class="search-box">
                        <input type="hidden" name="view" value="reports">
                        <input type="hidden" name="report" value="transaction_certificate">
                        <input type="text" name="ref" placeholder="Enter swap reference..." value="<?php echo safeHtml($certRef); ?>" autofocus>
                        <button type="submit" class="btn btn-primary">Look Up</button>
                    </form>

                    <?php if ($certRef === ''): ?>
                    <div class="empty-state"><span class="icon">🔖</span><p>Enter a swap reference to generate its certificate.</p></div>
                    <?php elseif (empty($certData['swap_request']) && empty($certData['holds'])): ?>
                    <div class="empty-state"><span class="icon">🔍</span><p>No transaction found for reference "<?php echo safeHtml($certRef); ?>".</p></div>
                    <?php else: $sr = $certData['swap_request']; ?>

                    <?php if (!empty($certData['batch_context'])): $bc = $certData['batch_context']; ?>
                    <div class="card" style="border-left: 3px solid var(--brass); margin-bottom: var(--sp-5);">
                        <div style="font-size:14px; color:var(--ink-700);">
                            📦 This reference is destination <strong>#<?php echo $bc['destination_index'] + 1; ?> of <?php echo $bc['destination_count']; ?></strong>
                            in multi-destination batch <a href="?view=multi_destination"><strong><?php echo safeHtml($bc['batch_reference']); ?></strong></a>.
                            Amount, status, and creation time below are reconstructed from the batch record and
                            <code>hold_transactions</code> — there is no <code>swap_requests</code> row for individual batch
                            destinations, so ledger entries, audit trail, and notifications for this specific destination
                            are not separately tracked yet (see the note on batch reconciliation gaps at the bottom of this page).
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="report-section-title">Summary</div>
                    <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));">
                        <div class="metric-card"><span class="metric-label">Amount</span><span class="metric-value"><?php echo number_format((float)($sr['amount'] ?? 0), 2); ?></span><span class="metric-sub"><?php echo safeHtml($sr['from_currency'] ?? ''); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Status</span><span class="metric-value" style="font-size:18px;"><?php echo safeHtml(strtoupper($sr['status'] ?? 'unknown')); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Started</span><span class="metric-value" style="font-size:16px;"><?php echo !empty($certData['duration']) ? tsHtml($certData['duration']['start'], 'N/A') : tsHtml($sr['created_at'] ?? '', 'N/A'); ?></span></div>
                        <?php if (!empty($certData['duration'])): $d = $certData['duration']; $durColor = $d['pass'] ? 'var(--good)' : 'var(--bad)'; $durPct = $d['threshold'] > 0 ? min(100, max(0, ($d['seconds'] / $d['threshold']) * 100)) : 0; ?>
                        <div class="metric-card" style="grid-column: 4 / -1; align-items:stretch; text-align:left; border-top-color: <?php echo $durColor; ?>;">
                            <div style="display:flex; justify-content:space-between; align-items:baseline; gap:var(--sp-3);">
                                <span class="metric-label">Duration — Start to Finish</span>
                                <span class="metric-sub" style="color: <?php echo $durColor; ?>; font-weight:600;">
                                    <?php echo $d['pass'] ? '✓ PASS' : '✗ FAIL'; ?> (≤<?php echo $d['threshold']; ?>s)
                                </span>
                            </div>
                            <span class="metric-value" style="font-size:18px; text-align:left; margin-top:var(--sp-1);"><?php echo number_format($d['seconds'], 3); ?>s</span>
                            <div class="duration-track">
                                <div class="duration-fill" style="width:<?php echo $durPct; ?>%; background: <?php echo $durColor; ?>;"></div>
                            </div>
                            <div style="display:flex; justify-content:space-between; margin-top:var(--sp-2);">
                                <span class="metric-sub">Started <?php echo safeHtml(fmtTs($d['start'])); ?></span>
                                <span class="metric-sub">Ended <?php echo safeHtml(fmtTs($d['end'])); ?></span>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="metric-card" style="grid-column: 4 / -1; align-items:stretch; text-align:left;">
                            <span class="metric-label">Duration</span>
                            <span class="metric-sub" style="margin-top:var(--sp-2);">No completion timestamp recorded for this swap yet.</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="report-section-title">1 · Hold Placed — Source Institution Reserves Funds</div>
                    <?php if (empty($certData['holds'])): ?><p style="font-size:14px;color:var(--ink-300);">No hold record found.</p><?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Hold ID</th><th>Hold Reference</th><th>Institution</th><th>Amount</th><th>Status</th><th>Placed At</th><th>Debited At</th><th>Elapsed</th></tr></thead><tbody>
                    <?php foreach ($certData['holds'] as $h):
                        // This hold's own real checkpoints, per the
                        // Transaction Certificate's three-timestamp spec:
                        // created_at (client clicked "Swap", shown as
                        // Started above) is distinct from placed_at (this
                        // hold reserved, once the backend validated the
                        // order and submitted it for processing) and from
                        // debited_at (funds actually taken). Elapsed here
                        // is this hold's own placed->debited gap, not the
                        // full created_at->debited_at duration shown above.
                        $isDebited = $h['status'] === 'DEBITED';
                        $placedDisplay = $h['placed_at'] ?? '';
                        $debitedDisplay = $isDebited ? ($h['debited_at'] ?? '') : '';
                    ?>
                    <tr><td><?php echo safeHtml($h['hold_id']); ?></td><td><?php echo safeHtml($h['hold_reference']); ?></td><td><?php echo safeHtml($h['source_institution'] ?? $h['participant_name'] ?? 'N/A'); ?></td><td><?php echo number_format((float)$h['amount'], 2); ?></td><td><span class="status status-<?php echo $isDebited ? 'success' : 'pending'; ?>"><?php echo safeHtml($h['status']); ?></span></td><td><?php echo tsHtml($placedDisplay); ?></td><td><?php echo tsHtml($debitedDisplay, '—'); ?></td><td style="white-space:nowrap;font-weight:600;"><?php echo safeHtml($isDebited ? fmtElapsed($placedDisplay, $debitedDisplay) : '—'); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <?php if (!empty($certData['cashout'])): $co = $certData['cashout']; ?>
                    <div class="report-section-title">2 · Destination Code Generated</div>
                    <div class="table-responsive"><table><thead><tr><th>Provider</th><th>Amount</th><th>Fee</th><th>Code Expiry</th><th>Status</th></tr></thead><tbody>
                    <tr><td><?php echo safeHtml($co['cashout_provider'] ?? 'N/A'); ?></td><td><?php echo number_format((float)$co['amount'], 2); ?></td><td><?php echo number_format((float)($co['fee_amount'] ?? 0), 2); ?></td><td><?php echo safeHtml($co['code_expiry'] ?? ''); ?></td><td><span class="status status-<?php echo $co['status'] === 'COMPLETED' ? 'success' : 'pending'; ?>"><?php echo safeHtml($co['status']); ?></span></td></tr>
                    </tbody></table></div>
                    <?php endif; ?>

                    <div class="report-section-title">3 · Ledger Entries</div>
                    <?php if (empty($certData['swap_transactions'])): ?><p style="font-size:14px;color:var(--ink-300);">No ledger entries found.</p><?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>From</th><th>To</th><th>Amount</th><th>Status</th><th>Transaction Ref</th><th>Created — Amount Added to Swap Finished</th></tr></thead><tbody>
                    <?php foreach ($certData['swap_transactions'] as $t): $from = json_decode($t['from_account_details'] ?? '{}', true) ?: []; $to = json_decode($t['to_account_details'] ?? '{}', true) ?: [];
                        // The full span, not just when this ledger row was
                        // posted: from the moment the account added an
                        // amount and requested the swap to the moment it
                        // was actually debited/finished — the same
                        // start/end the Duration bar and Hold table use.
                        // Falls back to this row's own created_at when
                        // there's no resolved swap duration to draw from.
                        $ledgerCreated = !empty($certData['duration'])
                            ? tsRangeHtml($certData['duration']['start'], $certData['duration']['end'])
                            : tsHtml($t['created_at'] ?? '');
                    ?>
                    <tr><td><?php echo safeHtml($from['institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($to['institution'] ?? 'N/A'); ?></td><td><?php echo number_format((float)$t['amount'], 2); ?></td><td><?php echo safeHtml($t['status']); ?></td><td><?php echo safeHtml($t['transaction_id'] ?? '—'); ?></td><td><?php echo $ledgerCreated; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <div class="report-section-title">4 · Audit Trail</div>
                    <?php if (!empty($certData['audit_query_failed'])): ?>
                    <p style="font-size:14px;color:var(--bad);font-weight:600;">
                        Could not read the audit trail for this reference &mdash; the query itself failed.
                        This is not the same as "no activity": treat it as an unverified certificate and
                        check the application log. If <code>audit_logs.entity_id</code> is still
                        <code>bigint</code>, apply <code>2026_09_16_transaction_audit_integrity.sql</code>.
                    </p>
                    <?php elseif (empty($certData['audit'])): ?><p style="font-size:14px;color:var(--bad);">No audit entries recorded for this reference &mdash; a completed transaction should always have at least one. Check <code>audit_log_failures</code>.</p><?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Action</th><th>Category</th><th>Performed By</th><th>At</th></tr></thead><tbody>
                    <?php foreach ($certData['audit'] as $a): ?>
                    <tr><td><?php echo safeHtml($a['action'] ?? ''); ?></td><td><?php echo safeHtml($a['category'] ?? ''); ?></td><td><?php echo safeHtml($a['performed_by'] ?? $a['performed_by_id'] ?? 'SYSTEM'); ?></td><td><?php echo tsHtml($a['performed_at'] ?? ''); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <div class="report-section-title">5 · Notifications Sent</div>
                    <?php if (empty($certData['messages'])): ?><p style="font-size:14px;color:var(--ink-300);">No messages recorded.</p><?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Channel</th><th>Destination</th><th>Status</th><th>Sent At</th></tr></thead><tbody>
                    <?php foreach ($certData['messages'] as $m): ?>
                    <tr><td><?php echo safeHtml($m['channel'] ?? ''); ?></td><td><?php echo safeHtml($m['destination'] ?? ''); ?></td><td><?php echo safeHtml($m['status'] ?? ''); ?></td><td><?php echo tsHtml($m['sent_at'] ?? '', '—'); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <p style="font-size:12px; color:var(--ink-300); margin-top:var(--sp-5); border-top:1px solid var(--line); padding-top:var(--sp-3);">
                        <strong>Note on cryptographic proof:</strong> each verification/hold/debit step above is digitally signed at execution time,
                        but those signatures are currently written only to the application log, not to a queryable table. For a certificate that
                        can stand on its own in a dispute without pulling raw server logs, those signatures should be persisted to a dedicated
                        <code>transaction_signatures</code> table at the moment each step executes — flag this to engineering as a follow-up.
                    </p>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4); display:flex; justify-content:center; gap:var(--sp-3);">
                    <?php if ($certRef !== ''): ?><a href="?view=reports&report=transaction_certificate&ref=<?php echo urlencode($certRef); ?>&format=pdf" class="btn btn-primary">Download PDF</a><a href="?view=reports&report=transaction_certificate&ref=<?php echo urlencode($certRef); ?>&format=csv" class="btn">Download CSV</a><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($reportKey === 'double_spend_check'): ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div><div class="report-title">Double-Spend &amp; Duplicate-Debit Check</div><div style="color:var(--ink-500); font-size:13px;">Automated integrity scan across the full ledger</div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>

                    <?php if (!empty($integrityFailed)): ?>
                    <div class="card" style="border-left:3px solid var(--bad);">
                        <div class="empty-state"><span class="icon">⚠️</span><p style="color:var(--bad); font-weight:600;"><?php echo count($integrityFailed); ?> of 6 checks could not run. This report is incomplete and must not be treated as clean.</p></div>
                    </div>
                    <?php elseif ($integrityTotalIssues === 0): ?>
                    <div class="card" style="border-left:3px solid var(--good);">
                        <div class="empty-state"><span class="icon">✅</span><p style="color:var(--good); font-weight:600;">No double-spend, duplicate-debit, or tracking-gap issues found.</p></div>
                    </div>
                    <?php else: ?>
                    <div class="card" style="border-left:3px solid var(--bad);">
                        <div class="empty-state"><span class="icon">⚠️</span><p style="color:var(--bad); font-weight:600;"><?php echo $integrityTotalIssues; ?> issue<?php echo $integrityTotalIssues === 1 ? '' : 's'; ?> found — review below.</p></div>
                    </div>
                    <?php endif; ?>

                    <div class="report-section-title">Holds Debited More Than Once</div>
                    <?php if (isset($integrityFailed['duplicate_debited_holds'])): ?><p style="font-size:14px;color:var(--bad);font-weight:600;">✗ This check could not run, so it proves nothing: <?php echo $isSuperAdmin ? safeHtml(mb_substr($integrityFailed['duplicate_debited_holds'], 0, 200)) : 'see the server error log'; ?></p>
                    <?php elseif (empty($integrityIssues['duplicate_debited_holds'])): ?><p style="font-size:14px;color:var(--good);">✓ Clean — every hold was debited at most once.</p>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Swap Reference</th><th>Debited Count</th><th>Hold IDs</th><th>Amounts</th></tr></thead><tbody>
                    <?php foreach ($integrityIssues['duplicate_debited_holds'] as $row): ?>
                    <tr><td><?php echo safeHtml($row['swap_reference']); ?></td><td><span class="status status-failed"><?php echo $row['debited_hold_count']; ?></span></td><td><?php echo safeHtml(trim($row['hold_ids'], '{}')); ?></td><td><?php echo safeHtml(trim($row['amounts'], '{}')); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <div class="report-section-title">Cashouts Completed More Than Once</div>
                    <?php if (isset($integrityFailed['duplicate_completed_cashouts'])): ?><p style="font-size:14px;color:var(--bad);font-weight:600;">✗ This check could not run, so it proves nothing: <?php echo $isSuperAdmin ? safeHtml(mb_substr($integrityFailed['duplicate_completed_cashouts'], 0, 200)) : 'see the server error log'; ?></p>
                    <?php elseif (empty($integrityIssues['duplicate_completed_cashouts'])): ?><p style="font-size:14px;color:var(--good);">✓ Clean — no cashout was marked COMPLETED more than once.</p>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Swap Reference</th><th>Completed Count</th></tr></thead><tbody>
                    <?php foreach ($integrityIssues['duplicate_completed_cashouts'] as $row): ?>
                    <tr><td><?php echo safeHtml($row['swap_reference']); ?></td><td><span class="status status-failed"><?php echo $row['auth_count']; ?></span></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <div class="report-section-title">Idempotency Key Conflicts</div>
                    <?php if (isset($integrityFailed['idempotency_key_conflicts'])): ?><p style="font-size:14px;color:var(--bad);font-weight:600;">✗ This check could not run, so it proves nothing: <?php echo $isSuperAdmin ? safeHtml(mb_substr($integrityFailed['idempotency_key_conflicts'], 0, 200)) : 'see the server error log'; ?></p>
                    <?php elseif (empty($integrityIssues['idempotency_key_conflicts'])): ?><p style="font-size:14px;color:var(--good);">✓ Clean — no idempotency key ever resolved to more than one transaction reference.</p>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Idempotency Key</th><th>Distinct References Returned</th></tr></thead><tbody>
                    <?php foreach ($integrityIssues['idempotency_key_conflicts'] as $row): ?>
                    <tr><td><?php echo safeHtml($row['key']); ?></td><td><span class="status status-failed"><?php echo $row['distinct_refs']; ?></span></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <div class="report-section-title">Debited Holds With No Matching Swap Record</div>
                    <?php if (isset($integrityFailed['debited_holds_missing_swap_request'])): ?><p style="font-size:14px;color:var(--bad);font-weight:600;">✗ This check could not run, so it proves nothing: <?php echo $isSuperAdmin ? safeHtml(mb_substr($integrityFailed['debited_holds_missing_swap_request'], 0, 200)) : 'see the server error log'; ?></p>
                    <?php elseif (empty($integrityIssues['debited_holds_missing_swap_request'])): ?><p style="font-size:14px;color:var(--good);">✓ Clean — every debited hold has a matching swap_requests row.</p>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Hold ID</th><th>Swap Reference</th><th>Amount</th><th>Institution</th><th>Placed At</th></tr></thead><tbody>
                    <?php foreach ($integrityIssues['debited_holds_missing_swap_request'] as $row): ?>
                    <tr><td><?php echo safeHtml($row['hold_id']); ?></td><td><?php echo safeHtml($row['swap_reference']); ?></td><td><?php echo number_format((float)$row['amount'], 2); ?></td><td><?php echo safeHtml($row['source_institution']); ?></td><td><?php echo safeHtml($row['placed_at']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <div class="report-section-title">Completed Transactions With No Audit Record</div>
                    <?php if (isset($integrityFailed['completed_swaps_missing_audit'])): ?><p style="font-size:14px;color:var(--bad);font-weight:600;">✗ This check could not run, so it proves nothing: <?php echo $isSuperAdmin ? safeHtml(mb_substr($integrityFailed['completed_swaps_missing_audit'], 0, 200)) : 'see the server error log'; ?></p>
                    <?php elseif (empty($integrityIssues['completed_swaps_missing_audit'])): ?><p style="font-size:14px;color:var(--good);">✓ Clean — every completed transaction has an audit entry naming who moved the money, when, and where it went.</p>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Reference</th><th>Amount</th><th>Currency</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
                    <?php foreach ($integrityIssues['completed_swaps_missing_audit'] as $row): ?>
                    <tr><td><strong><?php echo safeHtml($row['swap_uuid']); ?></strong></td><td><?php echo number_format((float)$row['amount'], 2); ?></td><td><?php echo safeHtml($row['from_currency'] ?? ''); ?></td><td><span class="status status-failed"><?php echo safeHtml($row['status']); ?></span></td><td><?php echo safeHtml($row['created_at']); ?></td><td><a href="?view=reports&report=transaction_certificate&ref=<?php echo urlencode($row['swap_uuid']); ?>" class="btn btn-sm">Certificate</a></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <div class="report-section-title">Audit Write Failures (Unresolved)</div>
                    <?php if (isset($integrityFailed['audit_write_failures'])): ?><p style="font-size:14px;color:var(--bad);font-weight:600;">✗ This check could not run, so it proves nothing: <?php echo $isSuperAdmin ? safeHtml(mb_substr($integrityFailed['audit_write_failures'], 0, 200)) : 'see the server error log'; ?></p>
                    <?php elseif (empty($integrityIssues['audit_write_failures'])): ?><p style="font-size:14px;color:var(--good);">✓ Clean — no transaction has failed to record its audit trail.</p>
                    <?php else: ?>
                    <p style="font-size:13px;color:var(--ink-500);margin-bottom:var(--sp-3);">
                        Each row is a transaction whose audit entry could not be written. The money movement itself
                        already happened — these need a human to reconstruct the record and mark them resolved.
                    </p>
                    <div class="table-responsive"><table><thead><tr><th>Reference</th><th>Type</th><th>Reason</th><th>Recorded</th><th></th></tr></thead><tbody>
                    <?php foreach ($integrityIssues['audit_write_failures'] as $row): ?>
                    <tr><td><strong><?php echo safeHtml($row['swap_reference']); ?></strong></td><td><?php echo safeHtml($row['swap_type'] ?? ''); ?></td><td style="font-size:12px;"><?php echo safeHtml($row['reason']); ?></td><td><?php echo safeHtml($row['created_at']); ?></td><td><a href="?view=reports&report=transaction_certificate&ref=<?php echo urlencode($row['swap_reference']); ?>" class="btn btn-sm">Certificate</a></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4); display:flex; justify-content:center; gap:var(--sp-3);">
                    <a href="?view=reports&report=double_spend_check&format=pdf" class="btn btn-primary">Download PDF</a>
                    <a href="?view=reports&report=double_spend_check&format=csv" class="btn">Download CSV</a>
                </div>
                <?php endif; ?>

                <?php if ($reportKey === 'bank_statement'): ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div><div class="report-title">Partner Bank Statement</div><div style="color:var(--ink-500); font-size:13px;">Per-institution transaction record and net position</div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <form method="get" class="search-box">
                        <input type="hidden" name="view" value="reports">
                        <input type="hidden" name="report" value="bank_statement">
                        <input type="text" name="institution" placeholder="Enter institution code (e.g. ZURUBANK)..." value="<?php echo safeHtml($bankInstitution); ?>" autofocus>
                        <button type="submit" class="btn btn-primary">Generate Statement</button>
                    </form>

                    <?php if ($bankInstitution === ''): ?>
                    <div class="empty-state"><span class="icon">🏦</span><p>Enter an institution code to generate its statement.</p></div>
                    <?php elseif (empty($bankStatement['transactions'])): ?>
                    <div class="empty-state"><span class="icon">📭</span><p>No transactions found involving "<?php echo safeHtml($bankInstitution); ?>".</p></div>
                    <?php else:
                        $volumeSent = 0; $volumeReceived = 0;
                        foreach ($bankStatement['transactions'] as $t) {
                            if (($t['source_institution'] ?? '') === $bankInstitution) $volumeSent += (float)($t['amount'] ?? 0);
                            if (($t['destination_institution'] ?? '') === $bankInstitution) $volumeReceived += (float)($t['amount'] ?? 0);
                        }
                    ?>
                    <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));">
                        <div class="metric-card"><span class="metric-label">Total Transactions</span><span class="metric-value"><?php echo count($bankStatement['transactions']); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Sent (as source)</span><span class="metric-value"><?php echo number_format($volumeSent, 2); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Received (as destination)</span><span class="metric-value"><?php echo number_format($volumeReceived, 2); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Fees Invoiced To Them</span><span class="metric-value"><?php echo number_format((float)($bankStatement['fees_charged_to_them']['fees'] ?? 0), 2); ?></span></div>
                    </div>

                    <div class="report-section-title">Transaction Detail</div>
                    <div class="table-responsive"><table><thead><tr><th>Reference</th><th>Type</th><th>Role</th><th>Counterparty</th><th>Amount</th><th>Fee</th><th>Status</th><th>Date</th></tr></thead><tbody>
                    <?php foreach ($bankStatement['transactions'] as $t): $role = ($t['source_institution'] ?? '') === $bankInstitution ? 'SOURCE' : 'DESTINATION'; $counterparty = $role === 'SOURCE' ? ($t['destination_institution'] ?? 'N/A') : ($t['source_institution'] ?? 'N/A'); ?>
                    <tr>
                        <td><?php echo safeHtml(substr($t['swap_reference'] ?? $t['reference'] ?? '', 0, 16)); ?></td>
                        <td><span class="status status-info"><?php echo safeHtml($t['swap_type'] ?? 'STANDARD'); ?></span></td>
                        <td><?php echo $role; ?></td>
                        <td><?php echo safeHtml($counterparty); ?></td>
                        <td><strong><?php echo number_format((float)($t['amount'] ?? 0), 2); ?></strong></td>
                        <td><?php echo number_format((float)($t['fee_amount'] ?? 0), 2); ?></td>
                        <td><?php echo safeHtml($t['status'] ?? ''); ?></td>
                        <td><?php echo safeHtml(date('Y-m-d H:i', strtotime($t['created_at'] ?? 'now'))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>

                    <?php if (!empty($bankStatement['net_positions'])): ?>
                    <div class="report-section-title">Net Position</div>
                    <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($bankStatement['net_positions'][0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
                    <?php foreach ($bankStatement['net_positions'] as $row): ?>
                    <tr><?php foreach ($row as $val): ?><td><?php echo safeHtml(is_array($val) ? json_encode($val) : $val); ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p style="font-size:12px;color:var(--ink-300);margin-top:var(--sp-2);">Net position query assumes <code>net_positions</code> has <code>institution_a</code>/<code>institution_b</code> columns — adjust in code if your schema names these differently.</p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4); display:flex; justify-content:center; gap:var(--sp-3);">
                    <?php if ($bankInstitution !== ''): ?><a href="?view=reports&report=bank_statement&institution=<?php echo urlencode($bankInstitution); ?>&format=pdf" class="btn btn-primary">Download PDF</a><a href="?view=reports&report=bank_statement&institution=<?php echo urlencode($bankInstitution); ?>&format=csv" class="btn">Download CSV</a><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($reportKey === 'executive_summary'): ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div>
                            <div class="report-title">VouchMorph — Executive Summary</div>
                            <div style="color:var(--ink-500); font-size:13px;">Bank of Botswana Regulatory Sandbox Participant</div>
                        </div>
                        <div class="report-meta">Prepared by <?php echo safeHtml($adminFullName ?: $adminUsername); ?><br><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <div class="report-section-title">Network Volume</div>
                    <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));">
                        <div class="metric-card"><span class="metric-label">Total Swaps</span><span class="metric-value"><?php echo number_format($metrics['total_swaps'] ?? 0); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Total Users</span><span class="metric-value"><?php echo number_format($metrics['total_users'] ?? 0); ?></span></div>
                        <div class="metric-card"><span class="metric-label">24h Swaps</span><span class="metric-value"><?php echo number_format($metrics['recent_swaps_24h'] ?? 0); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Fees Collected</span><span class="metric-value"><?php echo number_format($metrics['total_fees'] ?? 0, 2); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Pending Settlements</span><span class="metric-value"><?php echo number_format($metrics['pending_settlements'] ?? 0); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Multi-Dest Batches</span><span class="metric-value"><?php echo number_format($metrics['multi_destination_count'] ?? 0); ?></span></div>
                    </div>
                    <div class="report-section-title">Institution Snapshot</div>
                    <?php if (empty($institutionHealth)): ?>
                    <div class="empty-state"><p>No institution data available.</p></div>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Institution</th><th>Volume</th><th>Success Rate</th></tr></thead><tbody>
                    <?php foreach (array_slice($institutionHealth, 0, 10) as $inst): ?>
                    <tr><td><?php echo safeHtml($inst['institution']); ?></td><td><?php echo number_format((float)$inst['volume'], 2); ?></td><td><?php echo $inst['success_rate']; ?>%</td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                    <div class="report-section-title">Open Items</div>
                    <p style="font-size:14px;"><?php echo $totalAlerts; ?> alert<?php echo $totalAlerts === 1 ? '' : 's'; ?> outstanding · <?php echo $agentApprovalCount; ?> agent application<?php echo $agentApprovalCount === 1 ? '' : 's'; ?> awaiting approval.</p>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4);"><a href="?view=reports&report=executive_summary&format=pdf" class="btn btn-primary">Download PDF</a></div>
                <?php endif; ?>

                <?php if ($reportKey === 'trust_scorecard'): ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div><div class="report-title">Institutional Trust Scorecard</div><div style="color:var(--ink-500); font-size:13px;">Success rate ranking across the network</div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <?php if (empty($institutionHealth)): ?>
                    <div class="empty-state"><p>No institution data available.</p></div>
                    <?php else: $ranked = $institutionHealth; usort($ranked, fn($a, $b) => $b['success_rate'] <=> $a['success_rate']); ?>
                    <div class="table-responsive"><table><thead><tr><th>#</th><th>Institution</th><th>Total Txns</th><th>Success Rate</th><th>Volume</th><th>Tier</th></tr></thead><tbody>
                    <?php foreach ($ranked as $i => $inst): $rate = (float)$inst['success_rate']; $tier = $rate >= 95 ? ['gold','Gold'] : ($rate >= 80 ? ['silver','Silver'] : ['watch','Needs Review']); ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo safeHtml($inst['institution']); ?></td>
                        <td><?php echo number_format($inst['total']); ?></td>
                        <td><?php echo $rate; ?>%</td>
                        <td><?php echo number_format((float)$inst['volume'], 2); ?></td>
                        <td><span class="scorecard-badge <?php echo $tier[0]; ?>"><?php echo $tier[1]; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4);"><a href="?view=reports&report=trust_scorecard&format=pdf" class="btn btn-primary">Download PDF</a></div>
                <?php endif; ?>

                <?php if ($reportKey === 'net_settlement'): ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div><div class="report-title">Net Settlement Position</div><div style="color:var(--ink-500); font-size:13px;">For regulatory review</div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <?php if (empty($reportNetPositions)): ?>
                    <div class="empty-state"><span class="icon">📭</span><p>No net position records found in <code>net_positions</code>.</p></div>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($reportNetPositions[0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
                    <?php foreach ($reportNetPositions as $row): ?>
                    <tr><?php foreach ($row as $val): ?><td><?php echo safeHtml(is_array($val) ? json_encode($val) : $val); ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4); display:flex; justify-content:center; gap:var(--sp-3);">
                    <a href="?view=reports&report=net_settlement&format=pdf" class="btn btn-primary">Download PDF</a>
                    <a href="?view=reports&report=net_settlement&format=csv" class="btn">Download CSV</a>
                </div>
                <?php endif; ?>

                <?php if ($reportKey === 'fee_revenue'): ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div><div class="report-title">Fee Revenue Summary</div><div style="color:var(--ink-500); font-size:13px;">By institution pair, sourced from settlement invoicing</div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <?php if (empty($reportFeeRevenue)): ?>
                    <div class="empty-state"><span class="icon">📭</span><p>No fee invoice records found.</p></div>
                    <?php else: $grandTotal = array_sum(array_column($reportFeeRevenue, 'fees')); ?>
                    <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));">
                        <div class="metric-card"><span class="metric-label">Total Fee Revenue</span><span class="metric-value"><?php echo number_format($grandTotal, 2); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Institution Pairs</span><span class="metric-value"><?php echo count($reportFeeRevenue); ?></span></div>
                    </div>
                    <div class="table-responsive"><table><thead><tr><th>Source</th><th>Destination</th><th>Fees Collected</th><th>Invoices</th></tr></thead><tbody>
                    <?php foreach ($reportFeeRevenue as $row): ?>
                    <tr><td><?php echo safeHtml($row['source_institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($row['destination_institution'] ?? 'N/A'); ?></td><td><strong><?php echo number_format((float)$row['fees'], 2); ?></strong></td><td><?php echo number_format($row['invoice_count']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p style="font-size:12px; color:var(--ink-300); margin-top:var(--sp-3);">Note: this reflects gross fee income only. A full profit &amp; loss statement also needs operating costs, settlement charges, and overhead — send over your chart of accounts / expense export and this can be extended into a true P&amp;L.</p>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4); display:flex; justify-content:center; gap:var(--sp-3);">
                    <a href="?view=reports&report=fee_revenue&format=pdf" class="btn btn-primary">Download PDF</a>
                    <a href="?view=reports&report=fee_revenue&format=csv" class="btn">Download CSV</a>
                </div>
                <?php endif; ?>

                <?php if ($reportKey === 'audit_export'): ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div><div class="report-title">Audit Trail Export</div><div style="color:var(--ink-500); font-size:13px;">Most recent 1,000 entries</div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <?php if (empty($reportAuditRows)): ?>
                    <div class="empty-state"><span class="icon">📭</span><p>No audit records.</p></div>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($reportAuditRows[0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
                    <?php foreach (array_slice($reportAuditRows, 0, 200) as $row): ?>
                    <tr><?php foreach ($row as $val): $s = is_array($val) ? json_encode($val) : (string)$val; ?><td><?php echo safeHtml(strlen($s) > 50 ? substr($s, 0, 50) . '…' : $s); ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p style="font-size:12px; color:var(--ink-300); margin-top:var(--sp-3);">Showing first 200 of <?php echo count($reportAuditRows); ?> rows on screen — the CSV download includes all of them.</p>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4);">
                    <a href="?view=reports&report=audit_export&format=pdf" class="btn btn-primary">Download PDF (first 500)</a>
                    <a href="?view=reports&report=audit_export&format=csv" class="btn">Download Full CSV</a>
                </div>
                <?php endif; ?>

                <?php if ($reportKey === 'suspicious_activity'): ?>
                <div class="report-page">
                    <?php if (!$canViewSuspicious): ?>
                    <div class="report-page-header">
                        <div><div class="report-title">Suspicious Activity (AML/KYC)</div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <div class="empty-state"><span class="icon">🚫</span><p>Your role does not have access to compliance-restricted data. This report is limited to audit and regulatory roles.</p></div>
                    <?php else: ?>
                    <div class="report-page-header">
                        <div><div class="report-title">Suspicious Activity (AML/KYC)</div><div style="color:var(--ink-500); font-size:13px;">Flagged transactions and stale holds requiring review</div></div>
                        <div class="report-meta">Prepared by <?php echo safeHtml($adminFullName ?: $adminUsername); ?><br><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));">
                        <div class="metric-card"><span class="metric-label">Flagged Total</span><span class="metric-value"><?php echo number_format($suspiciousSummary['total']); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Pending</span><span class="metric-value"><?php echo number_format($suspiciousSummary['pending']); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Failed</span><span class="metric-value"><?php echo number_format($suspiciousSummary['failed']); ?></span></div>
                        <div class="metric-card"><span class="metric-label">High Value (&gt;100k)</span><span class="metric-value"><?php echo number_format($suspiciousSummary['high_value']); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Stale Holds (&gt;1h)</span><span class="metric-value"><?php echo number_format($suspiciousSummary['stale_holds']); ?></span></div>
                    </div>

                    <div class="report-section-title">Flagged Transactions</div>
                    <?php if (empty($suspiciousData)): ?>
                    <div class="empty-state"><span class="icon">✅</span><p>No suspicious activity detected in the current window.</p></div>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Swap ID</th><th>User</th><th>Amount</th><th>Currency</th><th>Status</th><th>Source</th><th>Destination</th><th>Created</th></tr></thead><tbody>
                    <?php foreach ($suspiciousData as $s): ?>
                    <tr>
                        <td><?php echo safeHtml($s['swap_id'] ?? 'N/A'); ?></td>
                        <td><?php echo safeHtml($s['user_id'] ?? 'N/A'); ?></td>
                        <td><strong><?php echo number_format((float)($s['amount'] ?? 0), 2); ?></strong></td>
                        <td><?php echo safeHtml($s['currency'] ?? 'BWP'); ?></td>
                        <td><span class="status status-<?php echo ($s['status'] ?? '') === 'failed' ? 'failed' : 'pending'; ?>"><?php echo safeHtml(strtoupper($s['status'] ?? '')); ?></span></td>
                        <td><?php echo safeHtml($s['source_institution'] ?? 'N/A'); ?></td>
                        <td><?php echo safeHtml($s['destination_institution'] ?? 'N/A'); ?></td>
                        <td><?php echo safeHtml(date('Y-m-d H:i', strtotime($s['created_at'] ?? 'now'))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>

                    <p style="font-size:12px; color:var(--ink-300); margin-top:var(--sp-5); border-top:1px solid var(--line); padding-top:var(--sp-3);">
                        This surfaces status- and amount-based flags only (pending/failed/cancelled, amounts over 100,000, non-BWP currency).
                        It does not yet join AML risk scores or KYC document status — that requires confirming <code>aml_checks</code> and
                        <code>kyc_documents</code> tables exist with the expected schema before wiring them in, to avoid the silent-empty-section
                        problem seen elsewhere with unverified tables.
                    </p>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4); display:flex; justify-content:center; gap:var(--sp-3);">
                    <?php if ($canViewSuspicious): ?><a href="?view=reports&report=suspicious_activity&format=pdf" class="btn btn-primary">Download PDF</a><a href="?view=reports&report=suspicious_activity&format=csv" class="btn">Download CSV</a><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($reportKey === 'flow_type_breakdown'): ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div><div class="report-title">Flow Type Breakdown</div><div style="color:var(--ink-500); font-size:13px;">Per swap-type count, success rate, and average duration</div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <?php if (empty($reportFlowBreakdown)): ?>
                    <div class="empty-state"><span class="icon">📭</span><p>No swap data available.</p></div>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th>Swap Type</th><th>Total</th><th>Successful</th><th>Failed</th><th>Success Rate</th><th>Avg Duration</th></tr></thead><tbody>
                    <?php foreach ($reportFlowBreakdown as $row): $dur = $row['avg_duration_seconds']; $isDeposit = str_contains(strtolower($row['swap_type'] ?? ''), 'deposit'); $threshold = $isDeposit ? 60 : 90; $durOk = $dur !== null && $dur <= $threshold; ?>
                    <tr>
                        <td><strong><?php echo safeHtml($row['swap_type']); ?></strong></td>
                        <td><?php echo number_format($row['total']); ?></td>
                        <td><span class="status status-success"><?php echo number_format($row['successful']); ?></span></td>
                        <td><span class="status status-failed"><?php echo number_format($row['failed']); ?></span></td>
                        <td><?php echo $row['success_rate']; ?>%</td>
                        <td><?php if ($dur !== null): ?><span class="status status-<?php echo $durOk ? 'success' : 'failed'; ?>"><?php echo $dur; ?>s</span><?php else: ?>—<?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p style="font-size:12px; color:var(--ink-300); margin-top:var(--sp-3);">Duration thresholds: 90s for standard swaps (H1), 60s for deposit-type swaps (Experiment 2). Rows with no completed transactions show "—" for duration.</p>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4); display:flex; justify-content:center; gap:var(--sp-3);">
                    <a href="?view=reports&report=flow_type_breakdown&format=pdf" class="btn btn-primary">Download PDF</a>
                    <a href="?view=reports&report=flow_type_breakdown&format=csv" class="btn">Download CSV</a>
                </div>
                <?php endif; ?>

                <?php if ($reportKey === 'institution_settlement_summary'): $spCfg = $settlementPeriodConfig[$settlementPeriod] ?? $settlementPeriodConfig['daily']; ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div><div class="report-title">Institution Settlement Summary</div><div style="color:var(--ink-500); font-size:13px;">Issued, received, net position, and fees — by <?php echo safeHtml($spCfg['label']); ?></div></div>
                        <div class="report-meta"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <div style="display:flex; justify-content:center; gap:var(--sp-2); margin-bottom:var(--sp-5);">
                        <a href="?view=reports&report=institution_settlement_summary&period=daily" class="btn btn-sm <?php echo $settlementPeriod === 'daily' ? 'btn-primary' : ''; ?>">Daily</a>
                        <a href="?view=reports&report=institution_settlement_summary&period=weekly" class="btn btn-sm <?php echo $settlementPeriod === 'weekly' ? 'btn-primary' : ''; ?>">Weekly</a>
                        <a href="?view=reports&report=institution_settlement_summary&period=monthly" class="btn btn-sm <?php echo $settlementPeriod === 'monthly' ? 'btn-primary' : ''; ?>">Monthly</a>
                    </div>
                    <?php if (empty($reportInstitutionSettlement)): ?>
                    <div class="empty-state"><span class="icon">📭</span><p>No settlement data available for this period.</p></div>
                    <?php else: ?>
                    <div class="table-responsive"><table><thead><tr><th><?php echo safeHtml($spCfg['label']); ?></th><th>Institution</th><th>Issued</th><th>Received</th><th>Net Position</th><th>Fees Charged</th></tr></thead><tbody>
                    <?php foreach ($reportInstitutionSettlement as $row): $net = $row['net_position']; ?>
                    <tr>
                        <td><?php echo safeHtml(date($spCfg['unit'] === 'month' ? 'Y-m' : 'Y-m-d', strtotime($row['period']))); ?></td>
                        <td><strong><?php echo safeHtml($row['institution'] ?? 'UNKNOWN'); ?></strong></td>
                        <td><?php echo number_format($row['issued'], 2); ?> <span style="color:var(--ink-300);font-size:11px;">(<?php echo $row['issued_count']; ?>)</span></td>
                        <td><?php echo number_format($row['received'], 2); ?> <span style="color:var(--ink-300);font-size:11px;">(<?php echo $row['received_count']; ?>)</span></td>
                        <td><span class="status status-<?php echo $net >= 0 ? 'success' : 'failed'; ?>"><?php echo ($net >= 0 ? '+' : '') . number_format($net, 2); ?></span></td>
                        <td><?php echo number_format($row['fees_charged'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p style="font-size:12px; color:var(--ink-300); margin-top:var(--sp-3);">Net position: positive = this institution received more than it issued (owed to them by the network); negative = owed by them. Cross-check against your existing <code>net_positions</code> table and BISS settlement instructions before treating this as authoritative — this report derives net position independently from transaction volume, as a second, reconcilable source.</p>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4); display:flex; justify-content:center; gap:var(--sp-3);">
                    <a href="?view=reports&report=institution_settlement_summary&period=<?php echo $settlementPeriod; ?>&format=csv" class="btn">Download CSV</a>
                </div>
                <?php endif; ?>

                <?php if (isset($reconciliationConfig[$reportKey])): $cfg = $reconciliationConfig[$reportKey]; ?>
                <div class="report-page">
                    <div class="report-page-header">
                        <div>
                            <div class="report-title"><?php echo safeHtml($reportCatalog[$reportKey]['title']); ?></div>
                            <div style="color:var(--ink-500); font-size:13px;">Grouped by <?php echo safeHtml($cfg['label']); ?>, last <?php echo safeHtml($cfg['window']); ?></div>
                        </div>
                        <div class="report-meta">Prepared by <?php echo safeHtml($adminFullName ?: $adminUsername); ?><br><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <?php if (empty($reportReconciliation)): ?>
                    <div class="empty-state"><span class="icon">📭</span><p>No transaction data available for this period.</p></div>
                    <?php else: $totalTxn = array_sum(array_column($reportReconciliation, 'txn_count')); $totalVol = array_sum(array_column($reportReconciliation, 'volume')); $totalFees = array_sum(array_column($reportReconciliation, 'fees')); ?>
                    <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));">
                        <div class="metric-card"><span class="metric-label">Total Transactions</span><span class="metric-value"><?php echo number_format($totalTxn); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Total Volume</span><span class="metric-value"><?php echo number_format($totalVol, 2); ?></span></div>
                        <div class="metric-card"><span class="metric-label">Total Fees</span><span class="metric-value"><?php echo number_format($totalFees, 2); ?></span></div>
                    </div>
                    <div class="table-responsive"><table><thead><tr><th><?php echo safeHtml($cfg['label']); ?></th><th>Transactions</th><th>Volume</th><th>Fees</th><th>Completed</th><th>Pending</th><th>Failed</th></tr></thead><tbody>
                    <?php foreach ($reportReconciliation as $row): ?>
                    <tr>
                        <td><?php echo safeHtml(date($cfg['unit'] === 'month' ? 'Y-m' : 'Y-m-d', strtotime($row['period']))); ?></td>
                        <td><?php echo number_format($row['txn_count']); ?></td>
                        <td><?php echo number_format($row['volume'], 2); ?></td>
                        <td><?php echo number_format($row['fees'], 2); ?></td>
                        <td><span class="status status-success"><?php echo number_format($row['completed']); ?></span></td>
                        <td><span class="status status-pending"><?php echo number_format($row['pending']); ?></span></td>
                        <td><span class="status status-failed"><?php echo number_format($row['failed']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p style="font-size:12px; color:var(--ink-300); margin-top:var(--sp-3);">This reconciles internal ledger totals only (what VouchMorph recorded). Once you share your existing reconciliation files, this can be extended to compare against bank/settlement statements and flag variances line by line.</p>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:var(--sp-4); display:flex; justify-content:center; gap:var(--sp-3);">
                    <a href="?view=reports&report=<?php echo urlencode($reportKey); ?>&format=pdf" class="btn btn-primary">Download PDF</a>
                    <a href="?view=reports&report=<?php echo urlencode($reportKey); ?>&format=csv" class="btn">Download CSV</a>
                </div>
                <?php endif; ?>

                <?php endif; ?>
            <?php endif; ?>

            <!-- LEDGER RECONCILIATION -->
            <?php if ($view === 'ledger' && canView('ledger')): ?>
            <div class="content-header">
                <h1>Ledger Reconciliation</h1>
                <span class="timestamp">Variances between debits, credits, and fees</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>

            <?php if (empty($ledgerReconciliation)): ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">✅ No Variances Found</span>
                    <span class="card-badge brass">0</span>
                </div>
                <div class="empty-state">
                    <span class="icon">✅</span>
                    <p style="color:var(--good); font-weight:600;">Every swap balances perfectly — debits = credits + fees across all ledger entries.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title" style="color:var(--bad);">⚠️ <?php echo count($ledgerReconciliation); ?> Variances Found</span>
                    <span class="card-badge brass"><?php echo count($ledgerReconciliation); ?></span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Swap Reference</th>
                                <th>Debited (Source)</th>
                                <th>Credited (Dest)</th>
                                <th>Fees</th>
                                <th>Variance</th>
                                <th>Last Posted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ledgerReconciliation as $row): 
                                $variance = (float)($row['debited'] ?? 0) - ((float)($row['credited'] ?? 0) + (float)($row['fees'] ?? 0));
                            ?>
                            <tr>
                                <td><strong><?php echo safeHtml($row['swap_reference']); ?></strong></td>
                                <td><?php echo number_format((float)$row['debited'], 2); ?></td>
                                <td><?php echo number_format((float)$row['credited'], 2); ?></td>
                                <td><?php echo number_format((float)$row['fees'], 2); ?></td>
                                <td><span class="status status-failed"><?php echo number_format($variance, 2); ?></span></td>
                                <td><?php echo safeHtml($row['last_posted'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- AUDIT -->
            <?php if ($view === 'audit' && canView('audit')): ?>
            <div class="content-header">
                <h1>Audit Log</h1>
                <span class="timestamp">Most recent 200 entries</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>

            <!-- Chain Integrity Status -->
            <?php
            // Three states, never two: 'unknown' means the check itself did
            // not run and is never shown as a pass.
            $chainColor = ['intact' => 'var(--good)', 'broken' => 'var(--bad)', 'unknown' => 'var(--brass)'][$chainState] ?? 'var(--brass)';
            $chainBadge = ['intact' => '✅ INTACT', 'broken' => '⚠️ BROKEN', 'unknown' => '❔ NOT VERIFIED'][$chainState] ?? '❔ NOT VERIFIED';
            ?>
            <div class="card" style="border-left: 3px solid <?php echo $chainColor; ?>;">
                <div class="card-header">
                    <span class="card-title">🔗 Chain Integrity</span>
                    <span class="card-badge <?php echo $chainState === 'intact' ? 'brass' : ''; ?>"><?php echo $chainBadge; ?></span>
                </div>
                <div style="text-align:center; font-size:14px; color:<?php echo $chainColor; ?>;">
                    <?php if ($chainState === 'broken'): ?>
                        <strong>The audit trail fails verification at position <?php echo safeHtml((string)($chainInfo['first_bad_seq'] ?? '?')); ?>:</strong>
                        <?php echo safeHtml($chainInfo['reason'] ?? 'unknown reason'); ?>.
                        <div style="margin-top:var(--sp-2); font-size:13px; color:var(--ink-500);">
                            Treat this as a security incident: notify the Compliance Officer, do not modify the database, and compare against the last anchored head hash.
                        </div>
                    <?php elseif ($chainState === 'unknown'): ?>
                        <strong>The chain could not be verified.</strong> This is not a pass.
                        <div style="margin-top:var(--sp-3); font-size:12px; color:var(--ink-300);">
                            Most likely <code>2026_09_19_audit_chain_enforced.sql</code> has not been applied.
                            <?php if ($chainError !== null && $isSuperAdmin): ?><div style="margin-top:var(--sp-2);"><code><?php echo safeHtml($chainError); ?></code></div><?php endif; ?>
                        </div>
                    <?php else: ?>
                        All <?php echo number_format((int)$chainInfo['total']); ?> audit entries verified: every row matches its hash and links to the one before it, with no gaps.
                    <?php endif; ?>
                </div>
                <?php if (!empty($chainInfo['head_hash'])): ?>
                <div style="margin-top:var(--sp-4); padding-top:var(--sp-3); border-top:1px solid var(--line); font-size:12px; text-align:center; color:var(--ink-500);">
                    <strong>Anchor for today:</strong> position <?php echo safeHtml((string)$chainInfo['head_seq']); ?> &middot;
                    <code style="word-break:break-all;"><?php echo safeHtml($chainInfo['head_hash']); ?></code>
                    <div style="margin-top:var(--sp-1);">Copy this line into the daily anchor record outside this system (for example, the Compliance Officer's daily email). If the database were ever rewritten, the chain would no longer lead to this hash.</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header"><span class="card-title">Audit Trail</span><span class="card-badge"><?php echo count($auditRows); ?></span></div>
                <?php if (empty($auditRows)): ?><div class="empty-state"><span class="icon">📭</span><p>No audit records</p></div>
                <?php else: ?>
                <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($auditRows[0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($auditRows as $row): ?>
                <tr><?php foreach ($row as $val): $s = is_array($val) ? json_encode($val) : (string)$val; ?><td><?php echo safeHtml(strlen($s) > 50 ? substr($s, 0, 50) . '…' : $s); ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- INVOICES AND DESTINATION SETTLEMENT -->
            <?php if ($view === 'invoices' && canView('invoices')): ?>
            <div class="content-header">
                <h1>Invoices &amp; Settlement</h1>
                <span class="timestamp">Fee invoices and whether each destination has been paid</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <div class="metrics-grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));">
                <div class="metric-card"><span class="metric-label">Fees invoiced</span><span class="metric-value"><?php echo number_format($invoiceSummary['fees_total'], 2); ?></span><span class="metric-sub"><?php echo number_format($invoiceSummary['count']); ?> invoices</span></div>
                <div class="metric-card"><span class="metric-label">Fees paid</span><span class="metric-value"><?php echo number_format($invoiceSummary['fees_paid'], 2); ?></span><span class="metric-sub">outstanding <?php echo number_format($invoiceSummary['fees_total'] - $invoiceSummary['fees_paid'], 2); ?></span></div>
                <div class="metric-card"><span class="metric-label">Destination not settled</span><span class="metric-value"><?php echo number_format($invoiceSummary['not_settled']); ?></span><span class="metric-sub">within deadline</span></div>
                <div class="metric-card" style="<?php echo $invoiceSummary['overdue'] > 0 ? 'border-top-color:var(--bad);' : ''; ?>"><span class="metric-label">Overdue</span><span class="metric-value" style="<?php echo $invoiceSummary['overdue'] > 0 ? 'color:var(--bad);' : ''; ?>"><?php echo number_format($invoiceSummary['overdue']); ?></span><span class="metric-sub">past next business day noon</span></div>
                <div class="metric-card"><span class="metric-label">Destination settled</span><span class="metric-value" style="color:var(--good);"><?php echo number_format($invoiceSummary['settled']); ?></span></div>
                <div class="metric-card"><span class="metric-label">Settlement failed</span><span class="metric-value"><?php echo number_format($invoiceSummary['failed']); ?></span><span class="metric-sub">institution said not paid</span></div>
            </div>
            <div style="display:flex; justify-content:center; gap:var(--sp-2); flex-wrap:wrap; margin-bottom:var(--sp-5);">
                <?php foreach (['all' => 'All', 'not_settled' => 'Not settled', 'overdue' => 'Overdue', 'settled' => 'Settled', 'failed' => 'Failed', 'untracked' => 'Not tracked'] as $k => $lbl): ?>
                <a href="?view=invoices&settle=<?php echo $k; ?>" class="btn btn-sm <?php echo $settleFilter === $k ? 'btn-primary' : ''; ?>"><?php echo $lbl; ?></a>
                <?php endforeach; ?>
                <a href="?view=invoices&settle=<?php echo safeHtml($settleFilter); ?>&format=csv" class="btn btn-sm">Download CSV</a>
            </div>
            <div class="card">
                <div class="card-header"><span class="card-title">Invoices</span><span class="card-badge"><?php echo count($invoiceMessages); ?></span></div>
                <?php if (empty($invoiceMessages)): ?><div class="empty-state"><span class="icon">📭</span><p>No invoices<?php echo $settleFilter !== 'all' ? ' with this status' : ''; ?>.</p></div>
                <?php else: ?>
                <div class="table-responsive"><table><thead><tr><th>Swap</th><th>Invoiced to</th><th>Fee</th><th>Status</th><th>Destination</th><th>Invoiced</th><th>Settled / last check</th></tr></thead><tbody>
                <?php foreach ($invoiceMessages as $inv): [$feeLbl, $feeCls] = invoiceLabel($inv); [$setLbl, $setCls] = settlementLabel($inv); ?>
                <tr>
                    <td><a href="?view=reports&report=transaction_certificate&ref=<?php echo urlencode($inv['swap_reference'] ?? ''); ?>"><?php echo safeHtml(substr($inv['swap_reference'] ?? 'N/A', 0, 22)); ?></a></td>
                    <td><?php echo safeHtml($inv['invoiced_institution'] ?? 'N/A'); ?></td>
                    <td><strong><?php echo number_format((float)$inv['amount'], 2); ?></strong> <?php echo safeHtml($inv['currency'] ?? ''); ?><?php if (!empty($inv['fee_type'])): ?><div style="font-size:12px;color:var(--ink-300);"><?php echo safeHtml($inv['fee_type']); ?></div><?php endif; ?></td>
                    <td><span class="status status-<?php echo $feeCls; ?>"><?php echo $feeLbl; ?></span> <span class="status status-<?php echo $setCls; ?>"><?php echo safeHtml($setLbl); ?></span></td>
                    <td><?php echo safeHtml($inv['destination_institution'] ?? '—'); ?><?php if (!empty($inv['confirmation_mode'])): ?><div style="font-size:12px;color:var(--ink-300);"><?php echo safeHtml(strtolower($inv['confirmation_mode'])); ?></div><?php endif; ?></td>
                    <td><?php echo tsHtml($inv['created_at'] ?? ''); ?></td>
                    <td><?php echo !empty($inv['confirmed_at']) ? tsHtml($inv['confirmed_at']) : tsHtml($inv['last_attempt_at'] ?? '', 'not checked yet'); ?><?php if (!empty($inv['last_error']) && empty($inv['confirmed_at'])): ?><div style="font-size:12px;color:var(--bad);"><?php echo safeHtml(mb_substr($inv['last_error'], 0, 90)); ?></div><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- REGULATORY -->
            <?php if ($view === 'regulatory' && canView('regulatory')): ?>
            <div class="content-header">
                <h1>Regulatory Oversight</h1>
                <span class="timestamp">Net positions · Pending settlements</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <div class="card" style="border-left: 3px solid var(--brass);">
                <div class="card-header"><span class="card-title">🛡️ Platform Integrity</span></div>
                <div style="text-align:center; font-size:14px; color:var(--ink-500);">
                    Run the automated double-spend and duplicate-debit check across the full ledger at any time.
                </div>
                <div style="text-align:center; margin-top:var(--sp-3);">
                    <a href="?view=reports&report=double_spend_check" class="btn btn-primary btn-sm">Run Integrity Check</a>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><span class="card-title">Institution Success Rates</span></div>
                <?php if (empty($institutionHealth)): ?><div class="empty-state"><p>No data</p></div>
                <?php else: ?>
                <div class="table-responsive"><table><thead><tr><th>Institution</th><th>Total</th><th>Success Rate</th><th>Volume</th></tr></thead><tbody>
                <?php foreach ($institutionHealth as $inst): ?>
                <tr><td><?php echo safeHtml($inst['institution']); ?></td><td><?php echo number_format($inst['total']); ?></td><td><?php echo $inst['success_rate']; ?>%</td><td><?php echo number_format((float)$inst['volume'], 2); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <?php if (!empty($netPositions)): ?>
            <div class="card">
                <div class="card-header"><span class="card-title">Net Positions</span><span class="card-badge"><?php echo count($netPositions); ?></span></div>
                <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($netPositions[0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($netPositions as $row): ?>
                <tr><?php foreach ($row as $val): ?><td><?php echo safeHtml(is_array($val) ? json_encode($val) : $val); ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($pendingSettlements)): ?>
            <div class="card">
                <div class="card-header"><span class="card-title">Pending Settlements</span><span class="card-badge"><?php echo count($pendingSettlements); ?></span></div>
                <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($pendingSettlements[0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($pendingSettlements as $row): ?>
                <tr><?php foreach ($row as $val): ?><td><?php echo safeHtml(is_array($val) ? json_encode($val) : $val); ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ALL TABLES (placeholder — nav linked here but no view existed) -->
            <?php if ($view === 'all_tables' && $isSuperAdmin): ?>
            <div class="content-header">
                <h1>Raw Tables</h1>
                <span class="timestamp">Direct table access</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <div class="card"><div class="empty-state"><span class="icon">🛠️</span><p>Raw table browsing isn't built yet — the nav link existed before the view did. Let me know which tables you want exposed here and I'll wire it up (with appropriate read-only guards).</p></div></div>
            <?php endif; ?>

            <?php if (!empty($dashboardErrors) && ($isSuperAdmin || canView('audit'))): ?>
            <div class="card" style="border-left:3px solid var(--bad);">
                <div class="card-header"><span class="card-title" style="color:var(--bad);">Some data on this page failed to load</span><span class="card-badge"><?php echo count($dashboardErrors); ?></span></div>
                <p style="font-size:14px;text-align:center;margin-bottom:var(--sp-3);">Empty or zero figures above may be wrong. Each failure is also in the server error log.</p>
                <?php if ($isSuperAdmin): ?>
                <div class="table-responsive"><table><thead><tr><th>Source</th><th>Error</th></tr></thead><tbody>
                <?php foreach ($dashboardErrors as $de): ?>
                <tr><td><?php echo safeHtml($de['where']); ?></td><td style="font-size:12px;"><?php echo safeHtml(mb_substr($de['message'], 0, 300)); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ACCESS DENIED -->
            <?php if ($accessDenied): ?>
            <div class="card"><div class="empty-state"><span class="icon">🚫</span><h2 style="font-family:var(--f-cond);text-transform:uppercase;font-size:20px;margin-bottom:var(--sp-2);">Access Denied</h2><p>You do not have permission to view this page.</p><a href="?view=dashboard" class="btn btn-primary" style="margin-top:var(--sp-4);">Return to Dashboard</a></div></div>
            <?php endif; ?>

        </div></main>

    <!-- FOOTER -->
    <footer class="admin-footer">
        VOUCHMORPH · <?php echo safeHtml($roleName); ?> · <?php echo date('Y'); ?>
        <span style="display:block;margin-top:2px;font-size:11px;color:rgba(255,255,255,0.4);">Bank of Botswana Regulatory Sandbox Participant</span>
    </footer>

</body>
</html>
