<?php
/**
 * admin_dashboard.php - VouchMorph Admin Dashboard
 * TWO-COLOR SCHEME: DARK NAVY + BRASS/GOLD
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
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
               failed_count, total_amount, total_fees, status, created_at
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

$invoiceMessages = [];
if ($view === 'invoices' && canView('invoices')) {
    try { $invoiceMessages = $db->query("SELECT * FROM settlement_outbox WHERE message_type = 'FEE_INVOICE' ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · <?php echo safeHtml($roleName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           TWO COLORS ONLY: DARK NAVY + BRASS/GOLD
           White (#FFFFFF) used ONLY for text readability on dark bg
           ============================================================ */
        :root {
            --navy:   #0B1B2B;
            --brass:  #9C7A3C;
            --white:  #FFFFFF;
            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;
            --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px; --sp-4: 16px;
            --sp-5: 20px; --sp-6: 24px; --sp-7: 32px; --sp-8: 40px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--f-body); background: var(--white); color: var(--navy); font-size: 14px; line-height: 1.55; min-height: 100vh; }

        /* ============================================================
           TOP BAR — DARK NAVY + BRASS
           ============================================================ */
        .top-bar {
            background: var(--navy);
            padding: var(--sp-4) var(--sp-7);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: var(--sp-3);
            border-bottom: 3px solid var(--brass);
            position: sticky; top: 0; z-index: 100;
        }
        .top-bar .logo {
            font-family: var(--f-body);
            font-size: 18px;
            font-weight: 700;
            color: var(--white);
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }
        .top-bar .logo span { color: var(--brass); font-weight: 400; }
        .top-bar .logo-sub {
            font-family: var(--f-cond);
            font-size: 8px;
            font-weight: 600;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--brass);
            margin-left: var(--sp-3);
        }
        .top-bar .user-area { display: flex; align-items: center; gap: var(--sp-5); }
        .top-bar .user-area .name {
            font-family: var(--f-cond);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--white);
        }
        .top-bar .user-area .role {
            font-family: var(--f-mono);
            font-size: 9px;
            color: var(--brass);
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        .top-bar .logout-btn {
            padding: var(--sp-2) var(--sp-5);
            border: 1.5px solid var(--brass);
            color: var(--brass);
            background: transparent;
            text-decoration: none;
            font-family: var(--f-cond);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            transition: all 0.2s;
            cursor: pointer;
        }
        .top-bar .logout-btn:hover { background: var(--brass); color: var(--navy); }

        /* ============================================================
           NAVIGATION
           ============================================================ */
        .admin-nav {
            background: var(--white);
            border-bottom: 2px solid var(--navy);
            padding: 0 var(--sp-7);
            display: flex;
            gap: var(--sp-5);
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: var(--sp-4) 0;
            color: var(--navy);
            text-decoration: none;
            font-family: var(--f-cond);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border-bottom: 3px solid transparent;
            transition: all 0.15s;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .nav-item:hover { color: var(--brass); }
        .nav-item.active { border-bottom-color: var(--brass); }
        .nav-badge {
            background: var(--brass);
            color: var(--white);
            font-size: 8px;
            padding: 1px 7px;
            font-weight: 700;
            font-family: var(--f-mono);
        }

        /* ============================================================
           CONTENT
           ============================================================ */
        .admin-content { padding: var(--sp-6) var(--sp-7); max-width: 1600px; margin: 0 auto; }
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: var(--sp-3);
            padding-bottom: var(--sp-4);
            border-bottom: 2px solid var(--navy);
            margin-bottom: var(--sp-6);
        }
        .content-header h1 {
            font-family: var(--f-cond);
            font-size: 22px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--navy);
            line-height: 1.1;
        }
        .content-header .timestamp {
            font-family: var(--f-mono);
            font-size: 10px;
            color: var(--navy);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* ============================================================
           METRICS
           ============================================================ */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: var(--sp-3);
            margin-bottom: var(--sp-6);
        }
        .metric-card {
            background: var(--white);
            border: 2px solid var(--navy);
            padding: var(--sp-4) var(--sp-4) var(--sp-3);
            border-top: 4px solid var(--brass);
        }
        .metric-card .label {
            font-family: var(--f-cond);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--navy);
            display: block;
            margin-bottom: var(--sp-1);
        }
        .metric-card .value {
            font-family: var(--f-body);
            font-size: 26px;
            font-weight: 700;
            color: var(--navy);
            line-height: 1.1;
        }
        .metric-card .sub {
            font-family: var(--f-mono);
            font-size: 9px;
            color: var(--navy);
            margin-top: var(--sp-1);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* ============================================================
           CARDS
           ============================================================ */
        .card {
            background: var(--white);
            border: 2px solid var(--navy);
            padding: var(--sp-5) var(--sp-5) var(--sp-4);
            margin-bottom: var(--sp-5);
            position: relative;
        }
        .card::before {
            content: "";
            position: absolute;
            top: -1px; left: -1px;
            width: 10px; height: 10px;
            border-top: 3px solid var(--brass);
            border-left: 3px solid var(--brass);
            pointer-events: none;
        }
        .card::after {
            content: "";
            position: absolute;
            bottom: -1px; right: -1px;
            width: 10px; height: 10px;
            border-bottom: 3px solid var(--brass);
            border-right: 3px solid var(--brass);
            pointer-events: none;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: var(--sp-3);
            border-bottom: 2px solid var(--navy);
            margin-bottom: var(--sp-4);
            flex-wrap: wrap;
            gap: var(--sp-3);
        }
        .card-title {
            font-family: var(--f-cond);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--navy);
        }
        .card-badge {
            font-family: var(--f-cond);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: var(--sp-1) var(--sp-3);
            background: var(--navy);
            color: var(--white);
        }

        /* ============================================================
           SEARCH BOX
           ============================================================ */
        .search-box {
            display: flex;
            gap: var(--sp-2);
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: var(--sp-4);
        }
        .search-box input[type="text"] {
            font-family: var(--f-body);
            padding: var(--sp-3) var(--sp-4);
            border: 2px solid var(--navy);
            font-size: 13px;
            background: var(--white);
            color: var(--navy);
            min-width: 260px;
            flex: 1;
        }
        .search-box input[type="text"]:focus { outline: none; border-color: var(--brass); }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn {
            padding: var(--sp-2) var(--sp-5);
            font-family: var(--f-cond);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border: 2px solid var(--navy);
            background: transparent;
            color: var(--navy);
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: var(--sp-2);
        }
        .btn:hover { background: var(--navy); color: var(--white); }
        .btn-primary { background: var(--navy); color: var(--white); border-color: var(--navy); }
        .btn-primary:hover { background: var(--brass); border-color: var(--brass); color: var(--navy); }
        .btn-sm { padding: var(--sp-1) var(--sp-4); font-size: 9px; }

        /* ============================================================
           TABLES
           ============================================================ */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th {
            font-family: var(--f-cond);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: var(--navy);
            color: var(--white);
            padding: var(--sp-2) var(--sp-3);
            text-align: left;
            white-space: nowrap;
            position: sticky; top: 0; z-index: 10;
        }
        td {
            padding: var(--sp-2) var(--sp-3);
            border-bottom: 1px solid var(--navy);
            vertical-align: middle;
            font-family: var(--f-body);
            font-size: 12px;
            color: var(--navy);
        }
        tr:hover { background: #F5F0E8; }

        /* ============================================================
           STATUS BADGES — NAVY + BRASS ONLY
           ============================================================ */
        .status {
            display: inline-block;
            padding: 2px 10px;
            font-family: var(--f-cond);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border: 1.5px solid transparent;
        }
        .status-success { background: var(--brass); color: var(--white); border-color: var(--brass); }
        .status-pending { background: transparent; color: var(--navy); border-color: var(--navy); }
        .status-failed { background: var(--navy); color: var(--white); border-color: var(--navy); }
        .status-info { background: transparent; color: var(--navy); border-color: var(--navy); }
        .status-identity { background: var(--brass); color: var(--white); border-color: var(--brass); }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state { text-align: center; padding: var(--sp-7) var(--sp-4); color: var(--navy); }
        .empty-state .icon { font-size: 32px; display: block; margin-bottom: var(--sp-3); }
        .empty-state p { font-size: 13px; }

        /* ============================================================
           LIVE INDICATOR
           ============================================================ */
        .live-indicator {
            display: inline-block;
            width: 10px; height: 10px;
            background: var(--brass);
            animation: pulse 1.2s ease-in-out infinite;
            margin-right: 8px;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        /* ============================================================
           HEALTH BAR
           ============================================================ */
        .health-bar-track { background: var(--navy); height: 8px; overflow: hidden; }
        .health-bar-fill { height: 100%; background: var(--brass); }
        .health-bar-fill.warn { background: var(--brass); opacity: 0.6; }
        .health-bar-fill.bad { background: var(--navy); }

        /* ============================================================
           LOOKUP CARD
           ============================================================ */
        .lookup-card { border-left: 6px solid var(--brass); margin-bottom: var(--sp-4); }
        .lookup-next-action {
            background: #F5F0E8;
            color: var(--navy);
            padding: var(--sp-3);
            margin-top: var(--sp-2);
            font-size: 12px;
            font-weight: 600;
            border-left: 3px solid var(--brass);
        }

        /* ============================================================
           FOOTER
           ============================================================ */
        .admin-footer {
            background: var(--navy);
            color: var(--brass);
            padding: var(--sp-4) var(--sp-7);
            text-align: center;
            font-family: var(--f-mono);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-top: 3px solid var(--brass);
            margin-top: var(--sp-6);
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            .top-bar { padding: var(--sp-3) var(--sp-4); flex-direction: column; align-items: stretch; text-align: center; }
            .top-bar .user-area { justify-content: center; }
            .admin-nav { padding: 0 var(--sp-4); gap: var(--sp-3); }
            .admin-content { padding: var(--sp-4); }
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .content-header { flex-direction: column; align-items: flex-start; }
            .content-header h1 { font-size: 18px; }
            .search-box input[type="text"] { min-width: 180px; }
        }
        @media (max-width: 480px) {
            .metrics-grid { grid-template-columns: 1fr; }
            .top-bar .logo { font-size: 15px; }
            .content-header h1 { font-size: 16px; }
        }
    </style>
</head>
<body>

    <!-- TOP BAR -->
    <header class="top-bar">
        <div class="logo">VOUCHMORPH<span>ADMIN</span><span class="logo-sub">· <?php echo safeHtml($roleName); ?></span></div>
        <div class="user-area">
            <span class="name"><?php echo safeHtml($adminFullName ?: $adminUsername); ?></span>
            <span class="role"><?php echo safeHtml($roleName); ?></span>
            <a href="admin_logout.php" class="logout-btn">Sign Out</a>
        </div>
    </header>

    <!-- NAVIGATION -->
    <nav class="admin-nav">
        <?php if (canView('dashboard')): ?><a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">Dashboard</a><?php endif; ?>
        <?php if (canView('client_lookup')): ?><a href="?view=client_lookup" class="nav-item <?php echo $view === 'client_lookup' ? 'active' : ''; ?>">Client Lookup</a><?php endif; ?>
        <?php if (canView('alerts')): ?>
        <a href="?view=alerts" class="nav-item <?php echo $view === 'alerts' ? 'active' : ''; ?>">Alerts <?php if ($totalAlerts > 0): ?><span class="nav-badge"><?php echo $totalAlerts; ?></span><?php endif; ?></a>
        <?php endif; ?>
        <?php if (canView('live_transactions')): ?><a href="?view=live_transactions" class="nav-item <?php echo $view === 'live_transactions' ? 'active' : ''; ?>">Live Txns</a><?php endif; ?>
        <?php if (canView('multi_destination')): ?><a href="?view=multi_destination" class="nav-item <?php echo $view === 'multi_destination' ? 'active' : ''; ?>">Multi-Dest</a><?php endif; ?>
        <?php if (canView('recent_swaps')): ?><a href="?view=recent_swaps" class="nav-item <?php echo $view === 'recent_swaps' ? 'active' : ''; ?>">Swaps</a><?php endif; ?>
        <?php if (canView('institution_health')): ?><a href="?view=institution_health" class="nav-item <?php echo $view === 'institution_health' ? 'active' : ''; ?>">Institutions</a><?php endif; ?>
        <?php if (canView('regulatory')): ?><a href="?view=regulatory" class="nav-item <?php echo $view === 'regulatory' ? 'active' : ''; ?>">Regulatory</a><?php endif; ?>
        <?php if (canView('audit')): ?><a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">Audit</a><?php endif; ?>
        <?php if (canView('invoices')): ?><a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">Invoices</a><?php endif; ?>
        <?php if (canView('all_tables') && $isSuperAdmin): ?><a href="?view=all_tables" class="nav-item <?php echo $view === 'all_tables' ? 'active' : ''; ?>">Tables</a><?php endif; ?>
    </nav>

    <!-- CONTENT -->
    <main class="admin-content">

        <!-- DASHBOARD -->
        <?php if ($view === 'dashboard'): ?>
        <div class="content-header">
            <h1>Dashboard</h1>
            <span class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></span>
        </div>

        <?php if ($totalAlerts > 0 && canView('alerts')): ?>
        <div class="card" style="border-color:var(--brass);">
            <div class="card-header">
                <span class="card-title">⚠️ <?php echo $totalAlerts; ?> item<?php echo $totalAlerts === 1 ? '' : 's'; ?> require attention</span>
                <a href="?view=alerts" class="btn btn-primary btn-sm">View Alerts</a>
            </div>
            <div style="font-size:11px; color:var(--navy);">
                <?php echo count($alerts['stuck_holds']); ?> stuck holds · <?php echo count($alerts['expired_identity_swaps']); ?> expired ·
                <?php echo count($alerts['stuck_cashouts']); ?> stuck cashouts
            </div>
        </div>
        <?php endif; ?>

        <div class="metrics-grid">
            <div class="metric-card"><span class="label">Total Swaps</span><span class="value"><?php echo number_format($metrics['total_swaps'] ?? 0); ?></span><span class="sub">Lifetime</span></div>
            <div class="metric-card"><span class="label">Total Users</span><span class="value"><?php echo number_format($metrics['total_users'] ?? 0); ?></span><span class="sub">Registered</span></div>
            <div class="metric-card"><span class="label">Pending Settlements</span><span class="value"><?php echo number_format($metrics['pending_settlements'] ?? 0); ?></span><span class="sub">Awaiting</span></div>
            <div class="metric-card"><span class="label">Total Fees</span><span class="value"><?php echo number_format($metrics['total_fees'] ?? 0, 2); ?></span><span class="sub">Collected</span></div>
            <div class="metric-card"><span class="label">24h Swaps</span><span class="value"><?php echo number_format($metrics['recent_swaps_24h'] ?? 0); ?></span><span class="sub">Last 24h</span></div>
            <div class="metric-card"><span class="label">Multi-Destination</span><span class="value"><?php echo number_format($metrics['multi_destination_count'] ?? 0); ?></span><span class="sub">Batches</span></div>
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
            <a href="?view=dashboard" style="font-family:var(--f-cond);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--navy);text-decoration:none;">← Back</a>
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
            <a href="?view=dashboard" style="font-family:var(--f-cond);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--navy);text-decoration:none;">← Back</a>
        </div>
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
            <div class="metric-card"><span class="label">Stuck Holds</span><span class="value"><?php echo number_format(count($alerts['stuck_holds'])); ?></span><span class="sub">&gt;24h</span></div>
            <div class="metric-card"><span class="label">Expired Identity</span><span class="value"><?php echo number_format(count($alerts['expired_identity_swaps'])); ?></span><span class="sub">Unconfirmed</span></div>
            <div class="metric-card"><span class="label">Stuck Cashouts</span><span class="value"><?php echo number_format(count($alerts['stuck_cashouts'])); ?></span><span class="sub">Expired</span></div>
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
            <a href="?view=dashboard" style="font-family:var(--f-cond);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--navy);text-decoration:none;">← Back</a>
        </div>
        <?php if (empty($institutionHealth)): ?>
        <div class="card"><div class="empty-state"><span class="icon">📭</span><p>No institution data available</p></div></div>
        <?php else: foreach ($institutionHealth as $inst): $rate = (float)$inst['success_rate']; $barClass = $rate >= 90 ? '' : ($rate >= 70 ? 'warn' : 'bad'); ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?php echo safeHtml($inst['institution']); ?></span>
                <span class="card-badge"><?php echo $rate; ?>% SUCCESS</span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap:var(--sp-2); margin-bottom:var(--sp-3); font-size:12px;">
                <div><strong>Total:</strong> <?php echo number_format($inst['total']); ?></div>
                <div style="color:var(--brass);"><strong>Success:</strong> <?php echo number_format($inst['successful']); ?></div>
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
            <a href="?view=dashboard" style="font-family:var(--f-cond);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--navy);text-decoration:none;">← Back</a>
        </div>
        <form method="get" class="search-box">
            <input type="hidden" name="view" value="live_transactions">
            <input type="text" name="search" placeholder="Search reference, institution, status..." value="<?php echo safeHtml($search); ?>">
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <?php if ($search !== ''): ?><a href="?view=live_transactions" class="btn btn-sm">Clear</a><?php endif; ?>
        </form>
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));">
            <div class="metric-card"><span class="label">24h Total</span><span class="value"><?php echo number_format($liveStats['total'] ?? 0); ?></span></div>
            <div class="metric-card"><span class="label">Completed</span><span class="value" style="color:var(--brass);"><?php echo number_format($liveStats['completed'] ?? 0); ?></span></div>
            <div class="metric-card"><span class="label">Pending</span><span class="value"><?php echo number_format($liveStats['pending'] ?? 0); ?></span></div>
            <div class="metric-card"><span class="label">Failed</span><span class="value"><?php echo number_format($liveStats['failed'] ?? 0); ?></span></div>
            <div class="metric-card"><span class="label">Volume</span><span class="value"><?php echo number_format($liveStats['total_amount'] ?? 0, 2); ?></span></div>
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
            <a href="?view=dashboard" style="font-family:var(--f-cond);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--navy);text-decoration:none;">← Back</a>
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
            <a href="?view=dashboard" style="font-family:var(--f-cond);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--navy);text-decoration:none;">← Back</a>
        </div>
        <?php if (empty($multiDestinationSwaps)): ?>
        <div class="card"><div class="empty-state"><span class="icon">📭</span><p>No multi-destination swaps found</p></div></div>
        <?php else: foreach ($multiDestinationSwaps as $swap): $destinations = json_decode($swap['destinations_payload'] ?? '[]', true); ?>
        <div class="card" style="border-left: 6px solid <?php echo $swap['status'] === 'completed' ? 'var(--brass)' : 'var(--navy)'; ?>;">
            <div class="card-header">
                <span class="card-title"><?php echo safeHtml($swap['reference']); ?> <span style="font-weight:400;font-size:10px;"><?php echo date('Y-m-d H:i', strtotime($swap['created_at'])); ?></span></span>
                <span class="card-badge"><?php echo strtoupper($swap['status'] ?? 'UNKNOWN'); ?></span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap:var(--sp-2); margin-bottom:var(--sp-3); font-size:11px; background:#F5F0E8; padding:var(--sp-3);">
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
            <a href="?view=dashboard" style="font-family:var(--f-cond);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--navy);text-decoration:none;">← Back</a>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">Audit Trail</span><span class="card-badge"><?php echo count($auditRows); ?></span></div>
            <?php if (empty($auditRows)): ?>
            <div class="empty-state"><span class="icon">📭</span><p>No audit records</p></div>
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
            <a href="?view=dashboard" style="font-family:var(--f-cond);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--navy);text-decoration:none;">← Back</a>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">Fee Invoices</span><span class="card-badge"><?php echo count($invoiceMessages); ?></span></div>
            <?php if (empty($invoiceMessages)): ?>
            <div class="empty-state"><span class="icon">📭</span><p>No invoices</p></div>
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

        <!-- ACCESS DENIED -->
        <?php
        $knownViews = ['dashboard', 'client_lookup', 'alerts', 'live_transactions', 'multi_destination', 'recent_swaps', 'institution_health', 'regulatory', 'audit', 'invoices', 'all_tables'];
        if (!canView($view) && !in_array($view, $knownViews)):
        ?>
        <div class="card"><div class="empty-state"><span class="icon">🚫</span><h2 style="font-family:var(--f-cond);text-transform:uppercase;font-size:18px;margin-bottom:var(--sp-2);">Access Denied</h2><p>You do not have permission to view this page.</p><a href="?view=dashboard" class="btn btn-primary" style="margin-top:var(--sp-4);">Return to Dashboard</a></div></div>
        <?php endif; ?>

    </main>

    <!-- FOOTER -->
    <footer class="admin-footer">
        VOUCHMORPH · <?php echo safeHtml($roleName); ?> · <?php echo date('Y'); ?>
    </footer>

</body>
</html>
