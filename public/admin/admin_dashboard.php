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

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;

if (!SessionManager::isAdminLoggedIn()) {
    header('Location: admin_login.php');
    exit();
}

$adminId = SessionManager::getAdminId();
$adminUsername = SessionManager::getAdminUsername();
$adminFullName = SessionManager::get('admin_full_name');
$adminRoleId = SessionManager::getAdminRoleId();
$adminCountry = SessionManager::getAdminCountry();

$roleDefinitions = [
    999 => ['name' => 'Super Admin', 'label' => 'SUPER ADMIN', 'view' => ['dashboard', 'live_transactions', 'audit', 'invoices', 'regulatory', 'all_tables', 'recent_swaps', 'multi_destination', 'alerts', 'institution_health', 'client_lookup']],
    3 => ['name' => 'Central Bank Regulator', 'label' => 'REGULATOR', 'view' => ['dashboard', 'regulatory', 'audit', 'recent_swaps', 'multi_destination', 'alerts', 'institution_health']],
    4 => ['name' => 'Compliance Officer', 'label' => 'COMPLIANCE', 'view' => ['dashboard', 'audit', 'recent_swaps', 'alerts', 'client_lookup']],
    5 => ['name' => 'Auditor', 'label' => 'AUDITOR', 'view' => ['dashboard', 'audit', 'recent_swaps', 'institution_health']],
    10 => ['name' => 'Finance Manager', 'label' => 'FINANCE', 'view' => ['dashboard', 'invoices', 'recent_swaps', 'alerts', 'institution_health']],
    11 => ['name' => 'Settlement Officer', 'label' => 'SETTLEMENT', 'view' => ['dashboard', 'recent_swaps', 'alerts', 'institution_health']],
    20 => ['name' => 'Customer Support', 'label' => 'SUPPORT', 'view' => ['dashboard', 'client_lookup']]
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

try {
    $db = DBConnection::getConnection();
    if (!$db) throw new Exception("Database connection failed");
    $db->query("SELECT 1");
} catch (Throwable $e) {
    die("Database connection failed: " . $e->getMessage());
}

$view = $_GET['view'] ?? 'dashboard';
$search = trim($_GET['search'] ?? '');
$lookup = trim($_GET['lookup'] ?? '');

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
            $ajaxRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        $liveTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $statStmt = $db->query("
            SELECT COUNT(*) as total,
                   COUNT(CASE WHEN status ILIKE '%completed%' OR status ILIKE '%success%' OR status ILIKE '%debited%' THEN 1 END) as completed,
                   COUNT(CASE WHEN status ILIKE '%pending%' OR status ILIKE '%processing%' THEN 1 END) as pending,
                   COUNT(CASE WHEN status ILIKE '%failed%' OR status ILIKE '%error%' THEN 1 END) as failed,
                   COALESCE(SUM(amount), 0) as total_amount
            FROM vw_all_swaps WHERE created_at >= NOW() - INTERVAL '24 hours'
        ");
        $liveStats = $statStmt->fetch(PDO::FETCH_ASSOC);
        $liveStats['total'] = (int)($liveStats['total'] ?? 0);
        $liveStats['completed'] = (int)($liveStats['completed'] ?? 0);
        $liveStats['pending'] = (int)($liveStats['pending'] ?? 0);
        $liveStats['failed'] = (int)($liveStats['failed'] ?? 0);
        $liveStats['total_amount'] = (float)($liveStats['total_amount'] ?? 0);
    }
} catch (Throwable $e) {}

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
        $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

$multiDestinationSwaps = [];
try {
    $stmt = $db->query("
        SELECT id, reference, source_institution, total_destinations, successful_count,
               failed_count, total_amount, total_fees, status, created_at,
               destinations_payload, results_payload
        FROM multi_destination_swaps ORDER BY created_at DESC
    ");
    $multiDestinationSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$alerts = ['stuck_holds' => [], 'expired_identity_swaps' => [], 'stuck_cashouts' => []];
$totalAlerts = 0;
try {
    $stmt = $db->query("
        SELECT hold_id, hold_reference, swap_reference, participant_name AS institution,
               amount, currency, status, created_at
        FROM hold_transactions
        WHERE status IN ('ACTIVE','HELD','PENDING_CASHOUT','PENDING_IDENTITY')
          AND created_at < NOW() - INTERVAL '24 hours'
        ORDER BY created_at ASC LIMIT 300
    ");
    $alerts['stuck_holds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalAlerts += count($alerts['stuck_holds']);
} catch (Throwable $e) {}
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
} catch (Throwable $e) {}
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
} catch (Throwable $e) {}

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
} catch (Throwable $e) {}

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
    } catch (Throwable $e) {}
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
    } catch (Throwable $e) {}
}

$auditRows = [];
if ($view === 'audit' && canView('audit')) {
    try { $auditRows = $db->query("SELECT * FROM audit_logs ORDER BY audit_id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}

$netPositions = [];
$pendingSettlements = [];
if ($view === 'regulatory' && canView('regulatory')) {
    try { $netPositions = $db->query("SELECT * FROM net_positions ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try { $pendingSettlements = $db->query("SELECT * FROM settlement_queue WHERE status = 'PENDING' ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}

$invoiceMessages = [];
if ($view === 'invoices' && canView('invoices')) {
    try { $invoiceMessages = $db->query("SELECT * FROM settlement_outbox WHERE message_type = 'FEE_INVOICE' ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
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
    'regulatory' => ['side' => 'left', 'eyebrow' => 'Regulatory Oversight', 'blurb' => "Net positions between institutions and pending settlements — the numbers a regulator needs, not the raw transaction feed."],
    'audit' => ['side' => 'right', 'eyebrow' => 'Audit Trail', 'blurb' => "Every recorded action, most recent first. This is the trail — who did what, and when."],
    'invoices' => ['side' => 'right', 'eyebrow' => 'Invoicing', 'blurb' => "Fee invoices generated automatically through settlement — the paper trail for what's owed to whom."],
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
            --ink-500:      #4A5A6E;
            --ink-300:      #8A96A3;
            --line:         #D3DAD6;
            --line-strong:  #AEB8B2;
            --brass:        #9C7A3C;
            --brass-deep:   #6E5326;
            --brass-tint:   #F4EFE3;

            --f-display: 'Source Serif 4', 'IBM Plex Sans', serif;
            --f-body:    'IBM Plex Sans', sans-serif;
            --f-cond:    'IBM Plex Sans Condensed', sans-serif;
            --f-mono:    'IBM Plex Mono', monospace;

            --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px; --sp-4: 16px;
            --sp-5: 20px; --sp-6: 24px; --sp-7: 32px; --sp-8: 40px;
            --sp-9: 48px; --sp-10: 64px;

            --content-max: 1440px;
            --header-h: 38px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            min-height: 100vh;
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .app-shell { display: flex; min-height: 100vh; }

        /* ============================================================
           SIDEBAR — fixed, permanent, carries nav + brand blurb.
           Replaces the old ribbon + header + top nav + alternating
           dark panel with one persistent left rail, matching the
           standard admin-dashboard pattern (fixed sidebar + card
           content) while keeping the login page's dark/brass identity
           always on screen instead of appearing only per-page.
           ============================================================ */
        .sidebar {
            flex: 0 0 280px;
            min-width: 0;
            background:
                radial-gradient(700px 500px at 15% 0%, rgba(156,122,60,.12), transparent 60%),
                var(--ink-900);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            border-right: 3px solid var(--brass);
        }
        .sidebar-brand {
            padding: var(--sp-6) var(--sp-5) var(--sp-5);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-brand .logo { font-family: var(--f-display); font-weight: 600; font-size: 19px; line-height: 1.2; }
        .sidebar-brand .logo span { color: var(--brass); font-weight: 400; }
        .sidebar-brand .division {
            font-family: var(--f-cond); font-size: 9.5px; font-weight: 600;
            letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-300);
            margin-top: 4px;
        }
        .sidebar-brand .role-badge {
            display: inline-block; margin-top: var(--sp-3);
            padding: 3px var(--sp-3); border: 1px solid var(--brass); color: var(--brass);
            font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em;
            font-family: var(--f-cond);
        }

        .sidebar-nav { flex: 1; overflow-y: auto; padding: var(--sp-4) 0; }
        .sidebar-nav a {
            display: flex; align-items: center; justify-content: space-between;
            padding: var(--sp-3) var(--sp-5);
            color: rgba(255,255,255,0.65); text-decoration: none;
            font-size: 12.5px; font-weight: 600; font-family: var(--f-cond);
            text-transform: uppercase; letter-spacing: 0.04em;
            border-left: 3px solid transparent; transition: all 0.15s;
        }
        .sidebar-nav a:hover { color: #fff; background: rgba(255,255,255,0.04); }
        .sidebar-nav a.active { color: #fff; background: rgba(156,122,60,0.14); border-left-color: var(--brass); }
        .sidebar-nav .nav-badge {
            background: var(--danger, #b3261e); color: #fff; font-size: 9.5px;
            padding: 1px 7px; font-family: var(--f-mono); font-weight: 700;
        }

        /* Brand blurb — same magazine typography as the login page,
           narrower, always visible instead of appearing per-page. */
        .sidebar-blurb { padding: var(--sp-5); border-top: 1px solid rgba(255,255,255,0.1); }
        .sidebar-blurb .eyebrow {
            font-family: var(--f-cond); font-size: 9.5px; font-weight: 600;
            letter-spacing: 0.16em; text-transform: uppercase; color: var(--brass);
            margin-bottom: var(--sp-3);
        }
        .sidebar-blurb p { font-family: var(--f-display); font-size: 13px; line-height: 1.65; color: rgba(255,255,255,0.82); }
        .sidebar-blurb .mark { margin-top: var(--sp-4); width: 28px; height: 2px; background: var(--brass); }

        .sidebar-user {
            padding: var(--sp-4) var(--sp-5); border-top: 1px solid rgba(255,255,255,0.1);
            display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3);
        }
        .sidebar-user .name { font-family: var(--f-display); font-size: 13px; font-weight: 600; }
        .sidebar-user .role { font-family: var(--f-cond); font-size: 9.5px; color: var(--ink-300); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px; }
        .sidebar-user .sign-out {
            font-family: var(--f-cond); font-size: 10px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.05em; color: rgba(255,255,255,0.6); text-decoration: none;
            border: 1px solid rgba(255,255,255,0.25); padding: 4px 10px; transition: all .15s;
        }
        .sidebar-user .sign-out:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }

        /* ============================================================
           MAIN — the working area. Card/metric/table/status/btn
           styles below this point are unchanged from before.
           ============================================================ */
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .top-ribbon {
            background: var(--panel); border-bottom: 1px solid var(--line);
            padding: var(--sp-2) var(--sp-7); font-family: var(--f-mono); font-size: 10.5px;
            color: var(--ink-300); display: flex; justify-content: flex-end; align-items: center; gap: var(--sp-3);
        }
        .top-ribbon strong { color: var(--brass-deep); font-weight: 600; }
        .admin-content { flex: 1; padding: var(--sp-7); display: block; }
        .admin-content-inner { width: 100%; max-width: var(--content-max); margin: 0 auto; }

        @media (max-width: 1024px) {
            .app-shell { flex-direction: column; }
            .sidebar { flex: 0 0 auto; height: auto; position: static; }
            .sidebar-nav { max-height: 260px; }
            .admin-content { padding: var(--sp-5); }
        }
        @media (max-width: 768px) {
            .top-ribbon { padding: var(--sp-1) var(--sp-4); }
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .content-header h1 { font-size: 18px; width: 100%; }
            .content-header { row-gap: var(--sp-3); }
            .admin-content { padding: var(--sp-4); }
        }
        @media (max-width: 480px) {
            .metrics-grid { grid-template-columns: 1fr; }
        }


        .content-header {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            row-gap: var(--sp-2);
            column-gap: var(--sp-5);
            padding-bottom: var(--sp-4);
            margin-bottom: var(--sp-6);
            border-bottom: 2px solid var(--ink-900);
            position: relative;
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
            font-size: 22px;
            font-weight: 600;
            letter-spacing: 0.01em;
            color: var(--ink-900);
            line-height: 1.2;
            margin-right: auto;
            display: flex;
            align-items: center;
        }
        .content-header .timestamp {
            font-family: var(--f-mono);
            font-size: 10px;
            color: var(--ink-300);
            line-height: 1;
            white-space: nowrap;
        }
        .content-header .back-link {
            font-family: var(--f-cond);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--ink-500);
            text-decoration: none;
            padding: var(--sp-2) var(--sp-3);
            border: 1px solid var(--line-strong);
            transition: all 0.15s;
            white-space: nowrap;
            line-height: 1;
        }
        .content-header .back-link:hover {
            border-color: var(--brass);
            color: var(--ink-900);
            background: var(--brass-tint);
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
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
            border-top: 2px solid var(--brass-tint);
            transition: border-color 0.15s, background-color .15s;
        }
        .metric-card:hover { border-top-color: var(--brass); background: #FCFBF8; }
        .metric-card .metric-label {
            font-size: 10px;
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
            font-size: 25px;
            font-weight: 600;
            color: var(--ink-900);
            font-variant-numeric: tabular-nums;
            line-height: 1.25;
            margin-top: var(--sp-2);
            display: block;
        }
        .metric-card .metric-sub {
            font-size: 10px;
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
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--sp-4);
            padding-bottom: var(--sp-3);
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
            gap: var(--sp-3);
        }
        .card-title {
            font-size: 16px;
            font-weight: 600;
            font-family: var(--f-display);
            letter-spacing: 0.01em;
            line-height: 1;
        }
        .card-badge {
            padding: var(--sp-1) var(--sp-3);
            background: var(--ink-900);
            color: #fff;
            font-size: 10px;
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
            height: 40px;
            border: 1.5px solid var(--line);
            font-size: 13px;
            background: #fdfcf9;
            color: var(--ink-900);
            min-width: 250px;
            flex: 1;
            transition: border-color .15s, background .15s;
        }
        .search-box .btn { height: 40px; }
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
            padding: var(--sp-2) var(--sp-5);
            font-size: 11px;
            font-weight: 700;
            border: 1.5px solid var(--ink-900);
            background: transparent;
            color: var(--ink-900);
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: var(--sp-2);
            font-family: var(--f-cond);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            line-height: 1;
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
        .btn-sm { padding: var(--sp-1) var(--sp-4); font-size: 10px; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; font-variant-numeric: tabular-nums; }
        th {
            background: var(--paper);
            color: var(--ink-500);
            padding: var(--sp-2) var(--sp-4);
            text-align: left;
            font-size: 10px;
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
            font-size: 12.5px;
            vertical-align: middle;
        }
        tr:hover { background: var(--brass-tint); }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px var(--sp-3) 2px 6px;
            font-size: 10px;
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
        .status-pending { background: #fef3c7; color: #8A6D00; border-color: #e0c375; }
        .status-pending::before { background: #8A6D00; }
        .status-failed { background: #fbeceb; color: #b3261e; border-color: #e3b3ae; }
        .status-failed::before { background: #b3261e; }
        .status-info { background: var(--paper); color: var(--ink-500); border-color: var(--line-strong); }
        .status-info::before { background: var(--ink-300); }
        .status-identity { background: #EDE8F5; color: #5C3D7A; border-color: #D4C0E8; }
        .status-identity::before { background: #5C3D7A; }

        .empty-state {
            text-align: center;
            padding: var(--sp-8) var(--sp-4);
            color: var(--ink-300);
        }
        .empty-state .icon { font-size: 28px; display: block; margin-bottom: var(--sp-3); }
        .empty-state p { font-size: 13px; }

        .live-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #b3261e;
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
        .health-bar-fill.warn { background: #b3261e; }
        .health-bar-fill.bad { background: #b3261e; }

        .lookup-card { border-left: 3px solid var(--brass); margin-bottom: var(--sp-4); }
        .lookup-next-action {
            background: var(--brass-tint);
            color: var(--ink-700);
            padding: var(--sp-3);
            margin-top: var(--sp-2);
            font-size: 12px;
            font-weight: 600;
            border-left: 3px solid var(--brass);
        }

        .admin-footer {
            background: var(--ink-900);
            color: var(--ink-300);
            padding: var(--sp-4) var(--sp-7);
            text-align: center;
            font-size: 10px;
            border-top: 2px solid var(--brass);
            margin-top: var(--sp-4);
            font-family: var(--f-mono);
            line-height: 1.8;
        }
        .admin-footer span { color: var(--brass); }
    </style>
</head>
<body>

    <div class="app-shell">
    <!-- SIDEBAR — fixed, permanent: nav + brand blurb + user -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="logo">VOUCHMORPH <span>Admin</span></div>
            <div class="division"><?php echo safeHtml($roleName); ?></div>
            <span class="role-badge"><?php echo safeHtml($roleInfo['label'] ?? $roleName); ?></span>
        </div>

        <nav class="sidebar-nav">
            <?php if (canView('dashboard')): ?><a href="?view=dashboard" class="<?php echo $view === 'dashboard' ? 'active' : ''; ?>">Dashboard</a><?php endif; ?>
            <?php if (canView('client_lookup')): ?><a href="?view=client_lookup" class="<?php echo $view === 'client_lookup' ? 'active' : ''; ?>">Client Lookup</a><?php endif; ?>
            <?php if (canView('alerts')): ?>
            <a href="?view=alerts" class="<?php echo $view === 'alerts' ? 'active' : ''; ?>">
                Alerts <?php if ($totalAlerts > 0): ?><span class="nav-badge"><?php echo $totalAlerts; ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (canView('live_transactions')): ?><a href="?view=live_transactions" class="<?php echo $view === 'live_transactions' ? 'active' : ''; ?>">Live Transactions</a><?php endif; ?>
            <?php if (canView('multi_destination')): ?><a href="?view=multi_destination" class="<?php echo $view === 'multi_destination' ? 'active' : ''; ?>">Multi-Destination</a><?php endif; ?>
            <?php if (canView('recent_swaps')): ?><a href="?view=recent_swaps" class="<?php echo $view === 'recent_swaps' ? 'active' : ''; ?>">Swaps</a><?php endif; ?>
            <?php if (canView('institution_health')): ?><a href="?view=institution_health" class="<?php echo $view === 'institution_health' ? 'active' : ''; ?>">Institutions</a><?php endif; ?>
            <?php if (canView('regulatory')): ?><a href="?view=regulatory" class="<?php echo $view === 'regulatory' ? 'active' : ''; ?>">Regulatory</a><?php endif; ?>
            <?php if (canView('audit')): ?><a href="?view=audit" class="<?php echo $view === 'audit' ? 'active' : ''; ?>">Audit</a><?php endif; ?>
            <?php if (canView('invoices')): ?><a href="?view=invoices" class="<?php echo $view === 'invoices' ? 'active' : ''; ?>">Invoices</a><?php endif; ?>
            <?php if (canView('all_tables') && $isSuperAdmin): ?><a href="?view=all_tables" class="<?php echo $view === 'all_tables' ? 'active' : ''; ?>">Tables</a><?php endif; ?>
        </nav>

        <div class="sidebar-blurb">
            <div class="eyebrow"><?php echo safeHtml($currentMeta['eyebrow']); ?></div>
            <p><?php echo safeHtml($currentMeta['blurb']); ?></p>
            <div class="mark"></div>
        </div>

        <div class="sidebar-user">
            <div>
                <div class="name"><?php echo safeHtml($adminFullName ?: $adminUsername); ?></div>
                <div class="role"><?php echo safeHtml($roleName); ?></div>
            </div>
            <a href="admin_logout.php" class="sign-out">Sign Out</a>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <div class="top-ribbon">
            <span>VouchMorph Internal Systems · Administrator Access Only</span>
            <span>&nbsp;·&nbsp;</span>
            <strong><?php echo date('Y-m-d H:i:s'); ?></strong>
        </div>
        <div class="admin-content"><div class="admin-content-inner">

            <!-- DASHBOARD -->
            <?php if ($view === 'dashboard'): ?>
            <div class="content-header">
                <h1>Dashboard</h1>
                <span class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></span>
            </div>

            <?php if ($totalAlerts > 0 && canView('alerts')): ?>
            <div class="card" style="border-color:var(--line-strong);">
                <div class="card-header">
                    <span class="card-title" style="color:#b3261e;">⚠️ <?php echo $totalAlerts; ?> item<?php echo $totalAlerts === 1 ? '' : 's'; ?> require attention</span>
                    <a href="?view=alerts" class="btn btn-primary btn-sm">View Alerts</a>
                </div>
                <div style="font-size:12px; color:var(--ink-500);">
                    <?php echo count($alerts['stuck_holds']); ?> stuck holds · <?php echo count($alerts['expired_identity_swaps']); ?> expired ·
                    <?php echo count($alerts['stuck_cashouts']); ?> stuck cashouts
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
                <div style="display:flex; gap:var(--sp-3); flex-wrap:wrap;">
                    <?php if (canView('invoices')): ?><a href="?view=invoices" class="btn btn-primary">Invoices</a><?php endif; ?>
                    <?php if (canView('alerts')): ?><a href="?view=alerts" class="btn">Alerts</a><?php endif; ?>
                    <?php if (canView('institution_health')): ?><a href="?view=institution_health" class="btn">Institution Health</a><?php endif; ?>
                    <?php if (canView('client_lookup')): ?><a href="?view=client_lookup" class="btn">Client Lookup</a><?php endif; ?>
                </div>
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
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px,1fr)); gap:var(--sp-2); font-size:12px;">
                    <div><strong>Amount:</strong> <?php echo number_format((float)$r['amount'], 2); ?> <?php echo safeHtml($r['currency']); ?></div>
                    <div><strong>Route:</strong> <?php echo safeHtml($r['institution']); ?></div>
                    <?php if (!empty($r['identity'])): ?><div><strong>Identity:</strong> <?php echo safeHtml($r['identity']); ?></div><?php endif; ?>
                    <div><strong>Date:</strong> <?php echo safeHtml(date('Y-m-d H:i', strtotime($r['created_at'] ?? 'now'))); ?></div>
                </div>
                <div class="lookup-next-action">→ <?php echo safeHtml($r['next_action']); ?></div>
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
            <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
                <div class="metric-card"><span class="metric-label">Stuck Holds</span><span class="metric-value"><?php echo number_format(count($alerts['stuck_holds'])); ?></span><span class="metric-sub">&gt;24h</span></div>
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
                <tr><td><?php echo safeHtml(substr($h['hold_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml(substr($h['swap_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml($h['institution'] ?? 'N/A'); ?></td><td><?php echo number_format((float)($h['amount'] ?? 0), 2); ?></td><td><span class="status status-pending"><?php echo safeHtml($h['status'] ?? ''); ?></span></td><td><?php echo round((time() - strtotime($h['created_at'] ?? 'now')) / 3600, 1); ?>h</td></tr>
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
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap:var(--sp-2); margin-bottom:var(--sp-2); font-size:12px;">
                    <div><strong>Total:</strong> <?php echo number_format($inst['total']); ?></div>
                    <div style="color:var(--ink-500);"><strong>Success:</strong> <?php echo number_format($inst['successful']); ?></div>
                    <div><strong>Volume:</strong> <?php echo number_format((float)$inst['volume'], 2); ?></div>
                </div>
                <div class="health-bar-track"><div class="health-bar-fill <?php echo $barClass; ?>" style="width: <?php echo min(100, $rate); ?>%;"></div></div>
            </div>
            <?php endforeach; endif; ?>
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
            <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));">
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
                    <td><?php echo date('Y-m-d H:i:s', strtotime($row['created_at'] ?? 'now')); ?></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>
            <script>
                let autoRefresh = true; let refreshInterval = null;
                function startAutoRefresh() { clearInterval(refreshInterval); refreshInterval = setInterval(function() { fetch(window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'ajax=1').then(r => r.json()).then(data => { if (data.transactions) { const tbody = document.getElementById('liveTransactionsBody'); let html = ''; data.transactions.forEach((row, i) => { const status = (row.status || 'pending').toLowerCase(); let cls = 'info'; if (status.includes('complet') || status.includes('success')) cls = 'success'; else if (status.includes('pending') || status.includes('processing')) cls = 'pending'; else if (status.includes('fail') || status.includes('error')) cls = 'failed'; html += `<tr><td>${i+1}</td><td>${(row.swap_reference || row.reference || 'N/A').substring(0,14)}</td><td><span class="status status-info">${row.swap_type || 'STANDARD'}</span></td><td><strong>${Number(row.amount || 0).toFixed(2)}</strong></td><td><span class="status status-${cls}">${row.status || 'pending'}</span></td><td>${row.source_institution || 'N/A'}</td><td>${row.destination_institution || 'N/A'}</td><td>${new Date(row.created_at).toLocaleString()}</td></tr>`; }); tbody.innerHTML = html; document.getElementById('liveCount').textContent = data.transactions.length; } }).catch(e => console.error('Refresh failed:', e)); }, 5000); }
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
                <div class="table-responsive"><table><thead><tr><th>Reference</th><th>Type</th><th>Amount</th><th>Status</th><th>Source</th><th>Destination</th><th>Created</th></tr></thead><tbody>
                <?php if (empty($recentSwaps)): ?>
                <tr><td colspan="7" class="empty-state">No swaps<?php echo $search !== '' ? ' for "' . safeHtml($search) . '"' : ''; ?></td></tr>
                <?php else: foreach ($recentSwaps as $row): $status = strtolower($row['status'] ?? 'pending'); $class = match(true) { str_contains($status, 'complet') || str_contains($status, 'success') => 'success', str_contains($status, 'pending') || str_contains($status, 'processing') => 'pending', str_contains($status, 'fail') || str_contains($status, 'error') => 'failed', default => 'info' }; ?>
                <tr>
                    <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['reference'] ?? 'N/A', 0, 16)); ?></td>
                    <td><span class="status status-info"><?php echo safeHtml($row['swap_type'] ?? 'STANDARD'); ?></span></td>
                    <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                    <td><span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span></td>
                    <td><?php echo safeHtml($row['source_institution'] ?? 'N/A'); ?></td>
                    <td><?php echo safeHtml($row['destination_institution'] ?? 'N/A'); ?></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($row['created_at'] ?? 'now')); ?></td>
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
                    <span class="card-title"><?php echo safeHtml($swap['reference']); ?> <span style="font-weight:400;color:var(--ink-300);font-size:10px;"><?php echo date('Y-m-d H:i', strtotime($swap['created_at'])); ?></span></span>
                    <span class="card-badge <?php echo $swap['status'] === 'completed' ? 'brass' : ''; ?>"><?php echo strtoupper($swap['status'] ?? 'UNKNOWN'); ?></span>
                </div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap:var(--sp-2); margin-bottom:var(--sp-3); font-size:11px; background:var(--paper); padding:var(--sp-3);">
                    <div><strong>Source:</strong> <?php echo safeHtml($swap['source_institution']); ?></div>
                    <div><strong>Total:</strong> <?php echo number_format((float)($swap['total_amount'] ?? 0), 2); ?></div>
                    <div><strong>✅</strong> <?php echo $swap['successful_count'] ?? 0; ?></div>
                    <div><strong>❌</strong> <?php echo $swap['failed_count'] ?? 0; ?></div>
                    <div><strong>📦</strong> <?php echo $swap['total_destinations']; ?></div>
                </div>
                <?php if (!empty($destinations)): ?>
                <div class="table-responsive"><table><thead><tr><th>#</th><th>Type</th><th>Institution</th><th>Identifier</th><th>Amount</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($destinations as $idx => $dest): $result = $results[$idx] ?? []; $status = $result['status'] ?? 'pending'; $isIdentity = isset($dest['identity_type']) || isset($dest['identity_value']); $isCashout = isset($dest['delivery_method']) && $dest['delivery_method'] === 'ATM'; ?>
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
                </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
            <?php endif; ?>

            <!-- AUDIT -->
            <?php if ($view === 'audit' && canView('audit')): ?>
            <div class="content-header">
                <h1>Audit Log</h1>
                <span class="timestamp">Most recent 200 entries</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
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

            <!-- INVOICES -->
            <?php if ($view === 'invoices' && canView('invoices')): ?>
            <div class="content-header">
                <h1>Invoices</h1>
                <span class="timestamp">Fee invoices from settlement</span>
                <a href="?view=dashboard" class="back-link">← Back</a>
            </div>
            <div class="card">
                <div class="card-header"><span class="card-title">Fee Invoices</span><span class="card-badge"><?php echo count($invoiceMessages); ?></span></div>
                <?php if (empty($invoiceMessages)): ?><div class="empty-state"><span class="icon">📭</span><p>No invoices</p></div>
                <?php else: ?>
                <div class="table-responsive"><table><thead><tr><th>Message ID</th><th>Swap Ref</th><th>Source</th><th>Destination</th><th>Created</th></tr></thead><tbody>
                <?php foreach ($invoiceMessages as $inv): ?>
                <tr>
                    <td><?php echo safeHtml(substr($inv['message_id'] ?? '', 0, 16)); ?></td>
                    <td><?php echo safeHtml(substr($inv['swap_reference'] ?? 'N/A', 0, 16)); ?></td>
                    <td><?php echo safeHtml($inv['source_institution'] ?? 'N/A'); ?></td>
                    <td><?php echo safeHtml($inv['destination_institution'] ?? 'N/A'); ?></td>
                    <td><?php echo safeHtml(date('Y-m-d H:i', strtotime($inv['created_at'] ?? 'now'))); ?></td>
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

            <!-- ACCESS DENIED -->
            <?php
            $knownViews = ['dashboard', 'client_lookup', 'alerts', 'live_transactions', 'multi_destination', 'recent_swaps', 'institution_health', 'regulatory', 'audit', 'invoices', 'all_tables'];
            if (!canView($view) && !in_array($view, $knownViews)):
            ?>
            <div class="card"><div class="empty-state"><span class="icon">🚫</span><h2 style="font-family:var(--f-cond);text-transform:uppercase;font-size:18px;margin-bottom:var(--sp-2);">Access Denied</h2><p>You do not have permission to view this page.</p><a href="?view=dashboard" class="btn btn-primary" style="margin-top:var(--sp-4);">Return to Dashboard</a></div></div>
            <?php endif; ?>

        </div></div>
    </main>
    </div>

    <!-- FOOTER -->
    <footer class="admin-footer">
        VOUCHMORPH · <?php echo safeHtml($roleName); ?> · <?php echo date('Y'); ?>
        <span style="display:block;margin-top:2px;font-size:9px;color:var(--ink-500);">Bank of Botswana Regulatory Sandbox Participant</span>
    </footer>

</body>
</html>
