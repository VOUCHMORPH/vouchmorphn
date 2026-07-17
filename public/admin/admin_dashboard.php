<?php
/**
 * admin_dashboard.php - VouchMorph Enhanced Role-Based Admin Dashboard
 * Features: Role-specific views, Report Generation, Live Transactions,
 *           Alerts/Exceptions, Institution Health, Transaction Search
 * Role IDs: 999=Super Admin, 3=Regulator, 4=Compliance, 5=Auditor
 *           10=Finance Manager, 11=Settlement Officer, 12=Revenue Officer
 *
 * CHANGES IN THIS VERSION:
 * 1. Wired up the previously-declared-but-unused $search variable into the
 *    Live Transactions and Recent Swaps queries, plus a search box in the UI.
 * 2. Added an Alerts/Exceptions panel: stuck holds (>24h non-terminal),
 *    expired-but-not-cancelled identity swaps, expired pending cashouts,
 *    and failed destinations inside multi-destination swaps.
 * 3. Added an Institution Health panel: per-institution volume, success
 *    rate, and average time-to-debit (latency), computed from
 *    hold_transactions/vw_all_swaps.
 * 4. Added 'alerts' and 'institution_health' to the relevant roles' view
 *    lists so every role that should see exceptions/health can.
 *
 * NOTE: vw_all_swaps currently appears to be capped (reported as exactly
 * 100 rows regardless of underlying table growth). That cap lives in the
 * VIEW DEFINITION itself, not in this file - none of the queries below add
 * a LIMIT. Run `SELECT pg_get_viewdef('vw_all_swaps', true);` and recreate
 * the view without the cap; this file will pick up the full history
 * automatically once that's fixed.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Define project root
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load required classes
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;

// Check if admin is logged in
if (!SessionManager::isAdminLoggedIn()) {
    header('Location: admin_login.php');
    exit();
}

// Get admin info
$adminId = SessionManager::getAdminId();
$adminUsername = SessionManager::getAdminUsername();
$adminFullName = SessionManager::get('admin_full_name');
$adminRoleId = SessionManager::getAdminRoleId();
$adminCountry = SessionManager::getAdminCountry();

// ============================================================
// ROLE DEFINITIONS
// ============================================================

$roleDefinitions = [
    999 => [
        'name' => 'Super Admin',
        'level' => 100,
        'permissions' => ['all'],
        'view' => [
            'dashboard', 'live_transactions', 'transactions', 'holds', 'audit',
            'invoices', 'reports', 'regulatory', 'users', 'all_tables', 'fee_breakdown',
            'revenue_split', 'participant_fees', 'financial_dashboard',
            'settlement_analysis', 'forex_fees', 'corridor_fees',
            'recent_swaps', 'swap_transactions', 'cross_border',
            'settlements', 'net_positions', 'regulatory_reports',
            'generate_reports', 'role_reports', 'multi_destination',
            'alerts', 'institution_health'
        ],
        'actions' => [
            'create', 'edit', 'delete', 'export', 'approve', 'reject',
            'generate_invoice', 'manage_users', 'view_fee_breakdown',
            'view_revenue_split', 'generate_financial_report', 'manage_fees',
            'generate_all_reports'
        ],
        'report_types' => ['all', 'financial', 'regulatory', 'compliance', 'audit', 'settlement', 'revenue'],
        'label' => '🔴 Super Admin',
        'badge_color' => '#dc3545'
    ],
    3 => [
        'name' => 'Central Bank Regulator',
        'level' => 90,
        'permissions' => ['view_all', 'audit_logs', 'regulatory_oversight', 'export_reports'],
        'view' => [
            'dashboard', 'regulatory', 'audit', 'reports', 'transactions_readonly',
            'fee_breakdown', 'revenue_split', 'financial_dashboard',
            'recent_swaps', 'cross_border', 'net_positions',
            'regulatory_reports', 'generate_reports', 'multi_destination',
            'live_transactions', 'alerts', 'institution_health'
        ],
        'actions' => ['view', 'export', 'approve_regulatory', 'generate_regulatory_report'],
        'report_types' => ['regulatory', 'compliance', 'audit', 'net_positions', 'cross_border'],
        'label' => '🏛️ Central Bank Regulator',
        'badge_color' => '#8B0000'
    ],
    4 => [
        'name' => 'Compliance Officer',
        'level' => 80,
        'permissions' => ['review_transactions', 'kyc_verification', 'compliance_checks'],
        'view' => [
            'dashboard', 'transactions', 'audit', 'reports', 'compliance',
            'fee_breakdown', 'recent_swaps', 'generate_reports', 'live_transactions',
            'alerts'
        ],
        'actions' => ['view', 'review', 'approve', 'reject', 'export', 'generate_compliance_report'],
        'report_types' => ['compliance', 'audit', 'transaction', 'aml'],
        'label' => '📋 Compliance Officer',
        'badge_color' => '#0056b3'
    ],
    5 => [
        'name' => 'Auditor',
        'level' => 70,
        'permissions' => ['read_only', 'audit_logs', 'view_reports'],
        'view' => [
            'dashboard', 'audit', 'reports', 'transactions_readonly',
            'fee_breakdown', 'revenue_split', 'recent_swaps',
            'net_positions', 'generate_reports', 'live_transactions',
            'alerts', 'institution_health'
        ],
        'actions' => ['view', 'export', 'generate_audit_report'],
        'report_types' => ['audit', 'transaction', 'fee', 'compliance'],
        'label' => '🔍 Auditor',
        'badge_color' => '#6c757d'
    ],
    10 => [
        'name' => 'Finance Manager',
        'level' => 85,
        'permissions' => [
            'view_financials', 'view_fees', 'view_invoices',
            'generate_invoices', 'export_financial_reports'
        ],
        'view' => [
            'dashboard', 'invoices', 'fee_breakdown', 'participant_fees',
            'revenue_split', 'financial_dashboard', 'settlement_analysis',
            'reports', 'forex_fees', 'recent_swaps', 'swap_transactions',
            'settlements', 'net_positions', 'generate_reports', 'live_transactions',
            'alerts', 'institution_health'
        ],
        'actions' => ['view', 'export', 'generate_invoice', 'generate_financial_report'],
        'report_types' => ['financial', 'fee', 'revenue', 'settlement', 'forex', 'invoice'],
        'label' => '💰 Finance Manager',
        'badge_color' => '#28a745'
    ],
    11 => [
        'name' => 'Settlement Officer',
        'level' => 75,
        'permissions' => [
            'view_settlements', 'process_settlements',
            'view_net_positions', 'view_corridor_fees'
        ],
        'view' => [
            'dashboard', 'settlements', 'net_positions',
            'corridor_fees', 'reports', 'settlement_analysis',
            'recent_swaps', 'cross_border', 'generate_reports', 'live_transactions',
            'alerts', 'institution_health'
        ],
        'actions' => ['view', 'process', 'export', 'acknowledge_settlement', 'generate_settlement_report'],
        'report_types' => ['settlement', 'net_positions', 'corridor', 'cross_border'],
        'label' => '🏦 Settlement Officer',
        'badge_color' => '#17a2b8'
    ],
    12 => [
        'name' => 'Revenue Officer',
        'level' => 80,
        'permissions' => [
            'view_revenue', 'view_fee_collections',
            'view_participant_revenue', 'generate_revenue_reports'
        ],
        'view' => [
            'dashboard', 'revenue', 'fee_collections',
            'participant_revenue', 'forex_fees', 'reports',
            'revenue_breakdown', 'recent_swaps', 'generate_reports', 'live_transactions',
            'institution_health'
        ],
        'actions' => ['view', 'export', 'generate_report', 'generate_revenue_report'],
        'report_types' => ['revenue', 'fee', 'participant', 'forex'],
        'label' => '📊 Revenue Officer',
        'badge_color' => '#ffc107'
    ],
    13 => [
        'name' => 'Compliance Auditor',
        'level' => 78,
        'permissions' => [
            'view_compliance', 'audit_transactions',
            'view_aml_reports', 'generate_compliance_reports'
        ],
        'view' => [
            'dashboard', 'compliance', 'audit', 'transactions_readonly',
            'reports', 'aml_monitoring', 'fee_compliance', 'recent_swaps',
            'generate_reports', 'live_transactions', 'alerts'
        ],
        'actions' => ['view', 'export', 'generate_compliance_report', 'flag_suspicious'],
        'report_types' => ['compliance', 'aml', 'audit', 'fee_compliance'],
        'label' => '🔐 Compliance Auditor',
        'badge_color' => '#6f42c1'
    ]
];

// Get role info
$roleInfo = $roleDefinitions[$adminRoleId] ?? $roleDefinitions[5];
$roleName = $roleInfo['name'] ?? 'Auditor';
$userPermissions = $roleInfo['permissions'] ?? ['read_only'];
$availableViews = $roleInfo['view'] ?? ['dashboard'];
$availableActions = $roleInfo['actions'] ?? ['view'];
$reportTypes = $roleInfo['report_types'] ?? [];

// Role type flags
$isSuperAdmin = ($adminRoleId === 999);
$isRegulator = ($adminRoleId === 3);
$isCompliance = ($adminRoleId === 4);
$isAuditor = ($adminRoleId === 5);
$isFinanceManager = ($adminRoleId === 10);
$isSettlementOfficer = ($adminRoleId === 11);
$isRevenueOfficer = ($adminRoleId === 12);
$isComplianceAuditor = ($adminRoleId === 13);
$isReadOnly = in_array('read_only', $userPermissions) || $isAuditor || $isRegulator;

// Permission helpers
function hasPermission($permission) {
    global $userPermissions, $roleInfo;
    return in_array('all', $userPermissions) || in_array($permission, $userPermissions) || in_array($permission, $roleInfo['actions'] ?? []);
}

function canView($view) {
    global $availableViews, $isSuperAdmin;
    return $isSuperAdmin || in_array($view, $availableViews);
}

function canGenerateReport($reportType) {
    global $reportTypes, $isSuperAdmin;
    return $isSuperAdmin || in_array($reportType, $reportTypes);
}

function isReadOnly() {
    global $isReadOnly;
    return $isReadOnly;
}

function hasFinancialAccess() {
    global $isFinanceManager, $isRevenueOfficer, $isSuperAdmin;
    return $isFinanceManager || $isRevenueOfficer || $isSuperAdmin;
}

// Database connection
try {
    $db = DBConnection::getConnection();
    if (!$db) throw new Exception("Database connection failed");
    $db->query("SELECT 1");
    error_log("[ADMIN DASHBOARD] Database connected successfully");
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] DB Error: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// Get view and parameters
$view = $_GET['view'] ?? 'dashboard';
$search = trim($_GET['search'] ?? '');
$exportTable = $_GET['export'] ?? '';
$action = $_GET['action'] ?? '';
$reportType = $_GET['report_type'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'html';

// Helper for safe HTML
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// LIVE TRANSACTIONS - NO LIMIT, SEARCHABLE
// ============================================================
$liveTransactions = [];
$liveStats = ['total' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0, 'total_amount' => 0];

try {
    $checkStmt = $db->query("SELECT to_regclass('vw_all_swaps')");
    $viewExists = $checkStmt->fetchColumn();

    if ($viewExists) {
        // Search box filters reference/institution/status. An empty $search
        // produces '%%' which matches every row, so the WHERE clause is
        // always safe to apply.
        $stmt = $db->prepare("
            SELECT
                swap_reference,
                reference,
                swap_type,
                source_institution,
                destination_institution,
                amount,
                currency,
                status,
                fee_amount,
                created_at
            FROM vw_all_swaps
            WHERE swap_reference ILIKE :search1
               OR reference ILIKE :search2
               OR source_institution ILIKE :search3
               OR destination_institution ILIKE :search4
               OR status ILIKE :search5
            ORDER BY created_at DESC
        ");
        $likeSearch = '%' . $search . '%';
        $stmt->execute([
            ':search1' => $likeSearch,
            ':search2' => $likeSearch,
            ':search3' => $likeSearch,
            ':search4' => $likeSearch,
            ':search5' => $likeSearch,
        ]);
        $liveTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $statStmt = $db->query("
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN status ILIKE '%completed%' OR status ILIKE '%success%' OR status ILIKE '%debited%' THEN 1 END) as completed,
                COUNT(CASE WHEN status ILIKE '%pending%' OR status ILIKE '%processing%' OR status ILIKE '%confirmation%' THEN 1 END) as pending,
                COUNT(CASE WHEN status ILIKE '%failed%' OR status ILIKE '%error%' THEN 1 END) as failed,
                COALESCE(SUM(amount), 0) as total_amount
            FROM vw_all_swaps
            WHERE created_at >= NOW() - INTERVAL '24 hours'
        ");
        $liveStats = $statStmt->fetch(PDO::FETCH_ASSOC);

        if ($liveStats) {
            $liveStats['total'] = (int)($liveStats['total'] ?? 0);
            $liveStats['completed'] = (int)($liveStats['completed'] ?? 0);
            $liveStats['pending'] = (int)($liveStats['pending'] ?? 0);
            $liveStats['failed'] = (int)($liveStats['failed'] ?? 0);
            $liveStats['total_amount'] = (float)($liveStats['total_amount'] ?? 0);
        }
    }
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Live transactions error: " . $e->getMessage());
}

// ============================================================
// RECENT SWAPS - NO LIMIT, SEARCHABLE
// ============================================================
$recentSwaps = [];
try {
    $checkStmt = $db->query("SELECT to_regclass('vw_all_swaps')");
    $viewExists = $checkStmt->fetchColumn();

    if ($viewExists) {
        $stmt = $db->prepare("
            SELECT
                swap_reference,
                reference,
                swap_type,
                source_institution,
                destination_institution,
                amount,
                currency,
                status,
                fee_amount,
                created_at
            FROM vw_all_swaps
            WHERE swap_reference ILIKE :search1
               OR reference ILIKE :search2
               OR source_institution ILIKE :search3
               OR destination_institution ILIKE :search4
               OR status ILIKE :search5
            ORDER BY created_at DESC
        ");
        $likeSearch = '%' . $search . '%';
        $stmt->execute([
            ':search1' => $likeSearch,
            ':search2' => $likeSearch,
            ':search3' => $likeSearch,
            ':search4' => $likeSearch,
            ':search5' => $likeSearch,
        ]);
        $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Recent swaps error: " . $e->getMessage());
}

// ============================================================
// MULTI-DESTINATION SWAPS - NO LIMIT
// ============================================================
$multiDestinationSwaps = [];
try {
    $stmt = $db->query("
        SELECT
            id,
            reference,
            source_institution,
            total_destinations,
            successful_count,
            failed_count,
            total_amount,
            total_fees,
            total_delivered,
            status,
            destinations_payload,
            results_payload,
            created_at,
            updated_at
        FROM multi_destination_swaps
        ORDER BY created_at DESC
    ");
    $multiDestinationSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Multi-destination fetch error: " . $e->getMessage());
}

// ============================================================
// ALERTS / EXCEPTIONS
// Surfaces the things ops/compliance actually need to act on,
// rather than making everyone scroll raw tables to spot them.
// ============================================================
$alerts = [
    'stuck_holds' => [],
    'expired_identity_swaps' => [],
    'stuck_cashouts' => [],
    'failed_destinations' => [],
];

try {
    // Holds sitting in a non-terminal state for more than 24h.
    // Terminal states are DEBITED / RELEASED / CANCELLED / FAILED.
    $stmt = $db->query("
        SELECT hold_id, hold_reference, swap_reference, participant_name AS institution,
               asset_type, amount, currency, status, created_at
        FROM hold_transactions
        WHERE status IN ('ACTIVE','HELD','PENDING_CASHOUT','PENDING_IDENTITY')
          AND created_at < NOW() - INTERVAL '24 hours'
        ORDER BY created_at ASC
        LIMIT 300
    ");
    $alerts['stuck_holds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] stuck_holds alert error: " . $e->getMessage());
}

try {
    // Identity swaps whose 24h confirmation window has passed but that
    // were never picked up by the expiry-cancellation job.
    $stmt = $db->query("
        SELECT hold_id, swap_reference, source_institution, identity_type, identity_value,
               amount, currency, hold_expires_at, status, created_at
        FROM identity_swap_holds
        WHERE status = 'pending'
          AND hold_expires_at < NOW()
        ORDER BY hold_expires_at ASC
        LIMIT 300
    ");
    $alerts['expired_identity_swaps'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] expired_identity_swaps alert error: " . $e->getMessage());
}

try {
    // Cashout codes that expired without the recipient ever cashing out.
    $stmt = $db->query("
        SELECT auth_id, swap_reference, client_phone, source_institution, cashout_provider,
               amount, currency, code_expiry, status, created_at
        FROM cashout_authorizations
        WHERE status = 'PENDING'
          AND code_expiry < NOW()
        ORDER BY code_expiry ASC
        LIMIT 300
    ");
    $alerts['stuck_cashouts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] stuck_cashouts alert error: " . $e->getMessage());
}

try {
    // Individual failed legs inside otherwise-partial-success multi-destination
    // swaps - these are easy to miss because the parent swap still shows
    // "partial_success" rather than a hard failure.
    $stmt = $db->query("
        SELECT id, reference, source_institution, created_at, results_payload
        FROM multi_destination_swaps
        WHERE failed_count > 0
        ORDER BY created_at DESC
        LIMIT 150
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $results = json_decode($row['results_payload'] ?? '[]', true) ?: [];
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'failed') {
                $alerts['failed_destinations'][] = [
                    'reference' => $row['reference'],
                    'source_institution' => $row['source_institution'],
                    'created_at' => $row['created_at'],
                    'type' => $r['type'] ?? 'bank',
                    'identity_value' => $r['identity_value'] ?? null,
                    'destination_institution' => $r['destination_institution'] ?? null,
                    'amount' => $r['amount'] ?? $r['requested_amount'] ?? 0,
                    'error' => $r['error'] ?? 'Unknown error',
                ];
            }
        }
    }
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] failed_destinations alert error: " . $e->getMessage());
}

$alertCounts = [
    'stuck_holds' => count($alerts['stuck_holds']),
    'expired_identity_swaps' => count($alerts['expired_identity_swaps']),
    'stuck_cashouts' => count($alerts['stuck_cashouts']),
    'failed_destinations' => count($alerts['failed_destinations']),
];
$totalAlerts = array_sum($alertCounts);

// ============================================================
// INSTITUTION HEALTH
// Volume + success rate from vw_all_swaps (institution appears as
// either source or destination). Latency is a separate query against
// hold_transactions since that's the only table with both a start
// (created_at) and completion (debited_at) timestamp.
// ============================================================
$institutionHealth = [];
try {
    $stmt = $db->query("
        SELECT
            inst AS institution,
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status ILIKE '%debited%' OR status ILIKE '%completed%' OR status ILIKE '%success%') AS successful,
            COUNT(*) FILTER (WHERE status ILIKE '%fail%' OR status ILIKE '%error%') AS failed,
            COUNT(*) FILTER (WHERE status ILIKE '%pending%' OR status ILIKE '%confirmation%') AS pending,
            COALESCE(SUM(amount), 0) AS volume
        FROM (
            SELECT source_institution AS inst, status, amount
            FROM vw_all_swaps
            WHERE source_institution IS NOT NULL AND source_institution <> 'N/A'
            UNION ALL
            SELECT destination_institution AS inst, status, amount
            FROM vw_all_swaps
            WHERE destination_institution IS NOT NULL AND destination_institution <> 'N/A'
        ) combined
        GROUP BY inst
        ORDER BY total DESC
    ");
    $institutionHealth = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($institutionHealth as &$row) {
        $row['success_rate'] = $row['total'] > 0 ? round(($row['successful'] / $row['total']) * 100, 1) : 0.0;
        $row['avg_latency_seconds'] = null;
        $row['debited_sample_size'] = 0;
    }
    unset($row);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] institution health error: " . $e->getMessage());
}

try {
    $stmt = $db->query("
        SELECT source_institution AS institution,
               AVG(EXTRACT(EPOCH FROM (debited_at - created_at))) AS avg_seconds,
               COUNT(*) AS debited_count
        FROM hold_transactions
        WHERE debited_at IS NOT NULL
          AND source_institution IS NOT NULL
        GROUP BY source_institution
    ");
    $latencyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $latencyByInstitution = [];
    foreach ($latencyRows as $lr) {
        $latencyByInstitution[$lr['institution']] = $lr;
    }
    foreach ($institutionHealth as &$row) {
        $inst = $row['institution'];
        if (isset($latencyByInstitution[$inst])) {
            $row['avg_latency_seconds'] = round((float)$latencyByInstitution[$inst]['avg_seconds'], 1);
            $row['debited_sample_size'] = (int)$latencyByInstitution[$inst]['debited_count'];
        }
    }
    unset($row);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] institution latency error: " . $e->getMessage());
}

// ============================================================
// METRICS
// ============================================================
$metrics = [];
try {
    $metrics['total_users'] = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $metrics['total_swaps'] = (int)$db->query("SELECT COUNT(*) FROM vw_all_swaps")->fetchColumn();
    $metrics['pending_settlements'] = (int)$db->query("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'")->fetchColumn();
    $metrics['total_fees'] = (float)$db->query("SELECT COALESCE(SUM((message_payload->>'fee_amount')::numeric), 0) FROM settlement_outbox WHERE message_type = 'FEE_INVOICE'")->fetchColumn();
    $metrics['recent_swaps_24h'] = (int)$db->query("SELECT COUNT(*) FROM vw_all_swaps WHERE created_at >= NOW() - INTERVAL '24 hours'")->fetchColumn();
    $metrics['multi_destination_count'] = (int)$db->query("SELECT COUNT(*) FROM multi_destination_swaps")->fetchColumn();
    $metrics['multi_source_count'] = (int)$db->query("SELECT COUNT(*) FROM multi_source_swaps")->fetchColumn();
    $metrics['identity_swaps_pending'] = (int)$db->query("SELECT COUNT(*) FROM identity_swap_holds WHERE status = 'pending'")->fetchColumn();
} catch (Throwable $e) {
    $metrics = array_fill_keys(['total_users', 'total_swaps', 'pending_settlements', 'total_fees', 'recent_swaps_24h', 'multi_destination_count', 'multi_source_count', 'identity_swaps_pending'], 0);
}

// Ensure variables exist
if (!isset($liveTransactions)) $liveTransactions = [];
if (!isset($liveStats)) $liveStats = ['total' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0, 'total_amount' => 0];
if (!isset($recentSwaps)) $recentSwaps = [];
if (!isset($multiDestinationSwaps)) $multiDestinationSwaps = [];
if (!isset($metrics)) $metrics = [];
if (!isset($alerts)) $alerts = ['stuck_holds' => [], 'expired_identity_swaps' => [], 'stuck_cashouts' => [], 'failed_destinations' => []];
if (!isset($alertCounts)) $alertCounts = ['stuck_holds' => 0, 'expired_identity_swaps' => 0, 'stuck_cashouts' => 0, 'failed_destinations' => 0];
if (!isset($totalAlerts)) $totalAlerts = 0;
if (!isset($institutionHealth)) $institutionHealth = [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · <?php echo safeHtml($roleName); ?> DASHBOARD</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #f7f9fc;
            color: #001B44;
            min-height: 100vh;
        }
        .admin-header {
            background: #001B44;
            border-bottom: 5px solid #FFDA63;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
            flex-wrap: wrap;
            gap: 10px;
        }
        .header-left { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .logo { font-size: 1.1rem; font-weight: 700; letter-spacing: 2px; }
        .logo span { color: #FFDA63; }
        .role-badge {
            padding: 4px 12px;
            background: <?php echo $roleInfo['badge_color'] ?? '#FFDA63'; ?>;
            color: #fff;
            font-size: 0.7rem;
            text-transform: uppercase;
            border-radius: 4px;
            font-weight: 700;
        }
        .readonly-badge {
            padding: 4px 12px;
            background: #f8d7da;
            color: #721c24;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 4px;
            border: 1px solid #f5c6cb;
        }
        .user-info { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; color: #FFDA63; font-size: 0.9rem; }
        .user-role { font-size: 0.65rem; color: #A1B5D8; text-transform: uppercase; }
        .logout-btn {
            padding: 6px 14px;
            background: transparent;
            border: 2px solid #FFDA63;
            color: #FFDA63;
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 600;
            transition: all 0.2s;
            border-radius: 4px;
        }
        .logout-btn:hover { background: #FFDA63; color: #001B44; }

        .admin-nav {
            background: #fff;
            border-bottom: 2px solid #001B44;
            padding: 0 24px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: 12px 0;
            color: #666;
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .nav-item:hover { color: #001B44; }
        .nav-item.active { color: #001B44; border-bottom-color: #FFDA63; }
        .nav-item.finance { color: #28a745; }
        .nav-item.finance.active { border-bottom-color: #28a745; }
        .nav-item.regulator { color: #8B0000; }
        .nav-item.regulator.active { border-bottom-color: #8B0000; }
        .nav-item.alerts { color: #dc3545; }
        .nav-item.alerts.active { border-bottom-color: #dc3545; }
        .nav-badge {
            background: #dc3545;
            color: #fff;
            font-size: 0.55rem;
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 700;
        }

        .admin-content { padding: 24px; max-width: 1600px; margin: 0 auto; }
        .content-header {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .content-header h1 { font-size: 1.3rem; font-weight: 600; color: #001B44; }
        .content-header .timestamp { color: #666; font-size: 0.7rem; }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .metric-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 12px 16px;
            box-shadow: 3px 3px 0 #A1B5D8;
        }
        .metric-label {
            font-size: 0.55rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .metric-value { font-size: 1.5rem; font-weight: 600; color: #001B44; }
        .metric-value .sub { font-size: 0.8rem; color: #666; }
        .metric-value .small { font-size: 0.9rem; }

        .card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 3px 3px 0 #A1B5D8;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #001B44;
            flex-wrap: wrap;
            gap: 8px;
        }
        .card-title { font-size: 0.9rem; font-weight: 700; text-transform: uppercase; }
        .card-badge {
            padding: 2px 10px;
            background: #001B44;
            color: #fff;
            font-size: 0.6rem;
            border-radius: 12px;
        }
        .card-badge.success { background: #28a745; }
        .card-badge.warning { background: #856404; }
        .card-badge.danger { background: #dc3545; }
        .card-badge.info { background: #17a2b8; }

        .search-box {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .search-box input[type=text] {
            font-family: 'IBM Plex Mono', monospace;
            padding: 6px 10px;
            border: 2px solid #001B44;
            border-radius: 4px;
            font-size: 0.7rem;
            min-width: 220px;
        }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.7rem; }
        th {
            background: #001B44;
            color: #fff;
            padding: 6px 10px;
            font-weight: 600;
            text-align: left;
            font-size: 0.55rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        td { padding: 5px 10px; border-bottom: 1px solid #eee; font-size: 0.65rem; }
        tr:hover { background: #f5f5f5; }
        tr:nth-child(even) { background: #fafafa; }

        .status {
            display: inline-block;
            padding: 1px 8px;
            font-size: 0.55rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
            border-radius: 3px;
        }
        .status-success { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .status-pending { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .status-failed { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .status-info { background: #cce5ff; color: #004085; border-color: #b8daff; }
        .status-warning { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .status-processing { background: #cce5ff; color: #004085; border-color: #b8daff; }
        .status-identity { background: #e8d5f5; color: #6f42c1; border-color: #d4b8e8; }
        .status-danger { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }

        .btn {
            padding: 6px 14px;
            font-size: 0.65rem;
            border: 2px solid #001B44;
            background: #fff;
            color: #001B44;
            cursor: pointer;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 600;
            text-transform: uppercase;
            transition: all 0.2s;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: #001B44; color: #fff; }
        .btn-primary { background: #001B44; color: #fff; }
        .btn-primary:hover { background: #FFDA63; color: #001B44; border-color: #FFDA63; }
        .btn-success { border-color: #28a745; color: #28a745; }
        .btn-success:hover { background: #28a745; color: #fff; }
        .btn-danger { border-color: #dc3545; color: #dc3545; }
        .btn-danger:hover { background: #dc3545; color: #fff; }
        .btn-regulator { border-color: #8B0000; color: #8B0000; }
        .btn-regulator:hover { background: #8B0000; color: #fff; }
        .btn-finance { border-color: #28a745; color: #28a745; }
        .btn-finance:hover { background: #28a745; color: #fff; }
        .btn-sm { padding: 2px 8px; font-size: 0.55rem; }

        .empty-state { text-align: center; padding: 30px; color: #999; }
        .empty-state .icon { font-size: 2rem; margin-bottom: 8px; }

        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 16px 24px;
            font-size: 0.65rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
            margin-top: 24px;
        }

        .live-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #28a745;
            animation: pulse 1.5s ease-in-out infinite;
            margin-right: 8px;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        .auto-refresh-toggle {
            cursor: pointer;
            padding: 4px 12px;
            border-radius: 4px;
            border: 2px solid #001B44;
            font-size: 0.65rem;
            font-weight: 600;
            background: #fff;
            color: #001B44;
            transition: all 0.2s;
        }
        .auto-refresh-toggle.active {
            background: #28a745;
            color: #fff;
            border-color: #28a745;
        }

        .alert-section { margin-bottom: 20px; }
        .alert-section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .health-bar-track {
            background: #eee;
            border-radius: 4px;
            height: 10px;
            width: 100%;
            overflow: hidden;
        }
        .health-bar-fill {
            height: 100%;
            background: #28a745;
        }
        .health-bar-fill.warn { background: #856404; }
        .health-bar-fill.bad { background: #dc3545; }

        @media (max-width: 768px) {
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .admin-nav { padding: 0 12px; gap: 10px; }
            .admin-content { padding: 12px; }
            .admin-header { padding: 12px; }
            th, td { font-size: 0.55rem; padding: 4px 6px; }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="role-badge"><?php echo safeHtml($roleInfo['label'] ?? $roleName); ?></div>
            <?php if ($isReadOnly): ?>
            <span class="readonly-badge">🔒 READ ONLY</span>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo safeHtml($adminFullName ?: $adminUsername); ?></div>
                <div class="user-role"><?php echo safeHtml($roleName); ?></div>
            </div>
            <a href="admin_logout.php" class="logout-btn">LOGOUT</a>
        </div>
    </header>

    <nav class="admin-nav">
        <?php if (canView('dashboard')): ?>
        <a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">📊 DASHBOARD</a>
        <?php endif; ?>

        <?php if (canView('alerts')): ?>
        <a href="?view=alerts" class="nav-item alerts <?php echo $view === 'alerts' ? 'active' : ''; ?>">
            🚨 ALERTS
            <?php if ($totalAlerts > 0): ?><span class="nav-badge"><?php echo $totalAlerts; ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canView('live_transactions')): ?>
        <a href="?view=live_transactions" class="nav-item <?php echo $view === 'live_transactions' ? 'active' : ''; ?>">🔴 LIVE TXNS</a>
        <?php endif; ?>

        <?php if (canView('multi_destination')): ?>
        <a href="?view=multi_destination" class="nav-item <?php echo $view === 'multi_destination' ? 'active' : ''; ?>">🎯 MULTI-DEST</a>
        <?php endif; ?>

        <?php if (canView('recent_swaps')): ?>
        <a href="?view=recent_swaps" class="nav-item <?php echo $view === 'recent_swaps' ? 'active' : ''; ?>">🔄 SWAPS</a>
        <?php endif; ?>

        <?php if (canView('institution_health')): ?>
        <a href="?view=institution_health" class="nav-item <?php echo $view === 'institution_health' ? 'active' : ''; ?>">🏦 INSTITUTIONS</a>
        <?php endif; ?>

        <?php if (canView('settlements')): ?>
        <a href="?view=settlements" class="nav-item <?php echo $view === 'settlements' ? 'active' : ''; ?>">📤 SETTLEMENTS</a>
        <?php endif; ?>

        <?php if (canView('regulatory')): ?>
        <a href="?view=regulatory" class="nav-item regulator <?php echo $view === 'regulatory' ? 'active' : ''; ?>">🏛️ REGULATORY</a>
        <?php endif; ?>

        <?php if (canView('audit')): ?>
        <a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">📝 AUDIT</a>
        <?php endif; ?>

        <?php if (canView('fee_breakdown') && hasFinancialAccess()): ?>
        <a href="?view=fee_breakdown" class="nav-item finance <?php echo $view === 'fee_breakdown' ? 'active' : ''; ?>">📊 FEES</a>
        <?php endif; ?>

        <?php if (canView('invoices') && hasPermission('generate_invoice')): ?>
        <a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">💰 INVOICES</a>
        <?php endif; ?>

        <?php if (canView('all_tables') && $isSuperAdmin): ?>
        <a href="?view=all_tables" class="nav-item <?php echo $view === 'all_tables' ? 'active' : ''; ?>">📋 TABLES</a>
        <?php endif; ?>
    </nav>

    <main class="admin-content">
        <!-- ============================================================ -->
        <!-- DASHBOARD VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'dashboard'): ?>
        <div class="content-header">
            <h1>📊 <?php echo safeHtml($roleName); ?> DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></div>
        </div>

        <?php if ($totalAlerts > 0 && canView('alerts')): ?>
        <div class="card" style="border-color:#dc3545;">
            <div class="card-header">
                <span class="card-title" style="color:#dc3545;">🚨 <?php echo $totalAlerts; ?> item<?php echo $totalAlerts === 1 ? '' : 's'; ?> need attention</span>
                <a href="?view=alerts" class="btn btn-danger btn-sm">VIEW ALERTS</a>
            </div>
            <div style="font-size:0.65rem; color:#666;">
                <?php echo $alertCounts['stuck_holds']; ?> stuck holds ·
                <?php echo $alertCounts['expired_identity_swaps']; ?> expired identity swaps ·
                <?php echo $alertCounts['stuck_cashouts']; ?> stuck cashouts ·
                <?php echo $alertCounts['failed_destinations']; ?> failed destinations
            </div>
        </div>
        <?php endif; ?>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['total_swaps'] ?? 0); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Users</div>
                <div class="metric-value"><?php echo number_format($metrics['total_users'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #856404;">
                <div class="metric-label">Pending Settlements</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_settlements'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #28a745;">
                <div class="metric-label">Total Fees</div>
                <div class="metric-value"><?php echo number_format($metrics['total_fees'] ?? 0, 2); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">24h Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['recent_swaps_24h'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #6f42c1;">
                <div class="metric-label">Multi-Destination</div>
                <div class="metric-value"><?php echo number_format($metrics['multi_destination_count'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #17a2b8;">
                <div class="metric-label">Multi-Source</div>
                <div class="metric-value"><?php echo number_format($metrics['multi_source_count'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #8B0000;">
                <div class="metric-label">Pending Identity</div>
                <div class="metric-value"><?php echo number_format($metrics['identity_swaps_pending'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">⚡ Quick Actions</span>
            </div>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <?php if (hasPermission('generate_invoice')): ?>
                <a href="?action=generate_invoice" class="btn btn-finance">💰 Generate Invoice</a>
                <?php endif; ?>
                <?php if (canView('all_tables') && $isSuperAdmin): ?>
                <a href="?view=all_tables" class="btn">📋 View All Tables</a>
                <?php endif; ?>
                <?php if (canView('alerts')): ?>
                <a href="?view=alerts" class="btn btn-danger">🚨 View Alerts</a>
                <?php endif; ?>
                <?php if (canView('institution_health')): ?>
                <a href="?view=institution_health" class="btn">🏦 Institution Health</a>
                <?php endif; ?>
            </div>
            <?php if (!empty($success)): ?>
            <div style="margin-top:12px; padding:12px; background:#d4edda; color:#155724; border:2px solid #c3e6cb; border-radius:4px;">
                ✅ <?php echo safeHtml($success); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- ALERTS / EXCEPTIONS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'alerts' && canView('alerts')): ?>
        <div class="content-header">
            <h1>🚨 ALERTS &amp; EXCEPTIONS</h1>
            <div class="timestamp">Things that need a human to look at them, not just raw rows</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
            <div class="metric-card" style="border-color:#dc3545;">
                <div class="metric-label">Stuck Holds (&gt;24h)</div>
                <div class="metric-value" style="color:#dc3545;"><?php echo number_format($alertCounts['stuck_holds']); ?></div>
            </div>
            <div class="metric-card" style="border-color:#856404;">
                <div class="metric-label">Expired Identity Swaps</div>
                <div class="metric-value" style="color:#856404;"><?php echo number_format($alertCounts['expired_identity_swaps']); ?></div>
            </div>
            <div class="metric-card" style="border-color:#856404;">
                <div class="metric-label">Stuck Cashouts</div>
                <div class="metric-value" style="color:#856404;"><?php echo number_format($alertCounts['stuck_cashouts']); ?></div>
            </div>
            <div class="metric-card" style="border-color:#dc3545;">
                <div class="metric-label">Failed Destinations</div>
                <div class="metric-value" style="color:#dc3545;"><?php echo number_format($alertCounts['failed_destinations']); ?></div>
            </div>
        </div>

        <?php if ($totalAlerts === 0): ?>
        <div class="card">
            <div class="empty-state">
                <div class="icon">✅</div>
                <p>Nothing needs attention right now.</p>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($alerts['stuck_holds'])): ?>
        <div class="card alert-section">
            <div class="card-header">
                <span class="card-title" style="color:#dc3545;">🔒 Stuck Holds (non-terminal &gt;24h)</span>
                <span class="card-badge danger"><?php echo count($alerts['stuck_holds']); ?></span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Hold Ref</th><th>Swap Ref</th><th>Institution</th><th>Asset</th><th>Amount</th><th>Status</th><th>Age</th></tr></thead>
                    <tbody>
                    <?php foreach ($alerts['stuck_holds'] as $h): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($h['hold_reference'] ?? '', 0, 20)); ?></td>
                            <td><?php echo safeHtml(substr($h['swap_reference'] ?? '', 0, 20)); ?></td>
                            <td><?php echo safeHtml($h['institution'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($h['asset_type'] ?? ''); ?></td>
                            <td><?php echo number_format((float)($h['amount'] ?? 0), 2); ?> <?php echo safeHtml($h['currency'] ?? 'BWP'); ?></td>
                            <td><span class="status status-warning"><?php echo safeHtml($h['status'] ?? ''); ?></span></td>
                            <td><?php
                                $ageHours = (time() - strtotime($h['created_at'] ?? 'now')) / 3600;
                                echo round($ageHours, 1) . 'h';
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($alerts['expired_identity_swaps'])): ?>
        <div class="card alert-section">
            <div class="card-header">
                <span class="card-title" style="color:#856404;">🪪 Expired Identity Swaps (not yet cancelled)</span>
                <span class="card-badge warning"><?php echo count($alerts['expired_identity_swaps']); ?></span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Swap Ref</th><th>Institution</th><th>Identity</th><th>Amount</th><th>Expired At</th></tr></thead>
                    <tbody>
                    <?php foreach ($alerts['expired_identity_swaps'] as $s): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($s['swap_reference'] ?? '', 0, 20)); ?></td>
                            <td><?php echo safeHtml($s['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($s['identity_type'] ?? ''); ?>: <?php echo safeHtml($s['identity_value'] ?? ''); ?></td>
                            <td><?php echo number_format((float)($s['amount'] ?? 0), 2); ?> <?php echo safeHtml($s['currency'] ?? 'BWP'); ?></td>
                            <td><?php echo safeHtml($s['hold_expires_at'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($alerts['stuck_cashouts'])): ?>
        <div class="card alert-section">
            <div class="card-header">
                <span class="card-title" style="color:#856404;">💵 Expired, Unclaimed Cashouts</span>
                <span class="card-badge warning"><?php echo count($alerts['stuck_cashouts']); ?></span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Swap Ref</th><th>Source</th><th>Provider</th><th>Phone</th><th>Amount</th><th>Expired</th></tr></thead>
                    <tbody>
                    <?php foreach ($alerts['stuck_cashouts'] as $c): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($c['swap_reference'] ?? '', 0, 20)); ?></td>
                            <td><?php echo safeHtml($c['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($c['cashout_provider'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($c['client_phone'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($c['amount'] ?? 0), 2); ?> <?php echo safeHtml($c['currency'] ?? 'BWP'); ?></td>
                            <td><?php echo safeHtml($c['code_expiry'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($alerts['failed_destinations'])): ?>
        <div class="card alert-section">
            <div class="card-header">
                <span class="card-title" style="color:#dc3545;">❌ Failed Destinations (inside multi-destination swaps)</span>
                <span class="card-badge danger"><?php echo count($alerts['failed_destinations']); ?></span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Parent Ref</th><th>Source</th><th>Type</th><th>Target</th><th>Amount</th><th>Error</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($alerts['failed_destinations'] as $f): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($f['reference'] ?? '', 0, 20)); ?></td>
                            <td><?php echo safeHtml($f['source_institution'] ?? 'N/A'); ?></td>
                            <td><span class="status <?php echo $f['type'] === 'identity' ? 'status-identity' : 'status-info'; ?>"><?php echo safeHtml(strtoupper($f['type'])); ?></span></td>
                            <td><?php echo safeHtml($f['identity_value'] ?? $f['destination_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($f['amount'] ?? 0), 2); ?></td>
                            <td style="color:#dc3545; max-width:280px;"><?php echo safeHtml($f['error']); ?></td>
                            <td><?php echo safeHtml(date('Y-m-d H:i', strtotime($f['created_at'] ?? 'now'))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- INSTITUTION HEALTH VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'institution_health' && canView('institution_health')): ?>
        <div class="content-header">
            <h1>🏦 INSTITUTION HEALTH</h1>
            <div class="timestamp">Volume, success rate, and average time-to-debit per institution</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <?php if (empty($institutionHealth)): ?>
        <div class="card"><div class="empty-state"><div class="icon">📭</div><p>No institution data yet</p></div></div>
        <?php else: ?>
        <?php foreach ($institutionHealth as $inst):
            $rate = (float)$inst['success_rate'];
            $barClass = $rate >= 90 ? '' : ($rate >= 70 ? 'warn' : 'bad');
        ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?php echo safeHtml($inst['institution']); ?></span>
                <span class="card-badge <?php echo $rate >= 90 ? 'success' : ($rate >= 70 ? 'warning' : 'danger'); ?>"><?php echo $rate; ?>% SUCCESS</span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:12px; font-size:0.7rem;">
                <div><strong>Total Txns:</strong> <?php echo number_format($inst['total']); ?></div>
                <div style="color:#28a745;"><strong>Successful:</strong> <?php echo number_format($inst['successful']); ?></div>
                <div style="color:#856404;"><strong>Pending:</strong> <?php echo number_format($inst['pending']); ?></div>
                <div style="color:#dc3545;"><strong>Failed:</strong> <?php echo number_format($inst['failed']); ?></div>
                <div><strong>Volume:</strong> <?php echo number_format((float)$inst['volume'], 2); ?></div>
                <div>
                    <strong>Avg time-to-debit:</strong>
                    <?php
                        if ($inst['avg_latency_seconds'] !== null) {
                            $secs = $inst['avg_latency_seconds'];
                            echo $secs < 60 ? round($secs, 1) . 's' : round($secs / 60, 1) . 'm';
                            echo ' (n=' . $inst['debited_sample_size'] . ')';
                        } else {
                            echo 'n/a';
                        }
                    ?>
                </div>
            </div>
            <div class="health-bar-track">
                <div class="health-bar-fill <?php echo $barClass; ?>" style="width: <?php echo min(100, $rate); ?>%;"></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- LIVE TRANSACTIONS VIEW - NO LIMIT, SEARCHABLE -->
        <!-- ============================================================ -->
        <?php if ($view === 'live_transactions' && canView('live_transactions')): ?>
        <div class="content-header">
            <h1><span class="live-indicator"></span> 🔴 LIVE TRANSACTIONS</h1>
            <div class="timestamp">
                <?php echo date('Y-m-d H:i:s'); ?>
                <span style="margin-left:16px; font-size:0.65rem; color:#666;">
                    <?php echo count($liveTransactions); ?> transactions
                </span>
                <button class="auto-refresh-toggle active" onclick="toggleAutoRefresh()" id="refreshToggle">🔄 AUTO-REFRESH ON</button>
            </div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <form method="get" class="search-box" style="margin-bottom:16px;">
            <input type="hidden" name="view" value="live_transactions">
            <input type="text" name="search" placeholder="Search reference, institution, status..." value="<?php echo safeHtml($search); ?>">
            <button type="submit" class="btn btn-primary btn-sm">SEARCH</button>
            <?php if ($search !== ''): ?>
            <a href="?view=live_transactions" class="btn btn-sm">CLEAR</a>
            <?php endif; ?>
        </form>

        <!-- Live Stats -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));">
            <div class="metric-card">
                <div class="metric-label">Total (24h)</div>
                <div class="metric-value"><?php echo number_format($liveStats['total'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color:#28a745;">
                <div class="metric-label">✅ Completed</div>
                <div class="metric-value" style="color:#28a745;"><?php echo number_format($liveStats['completed'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color:#856404;">
                <div class="metric-label">⏳ Pending</div>
                <div class="metric-value" style="color:#856404;"><?php echo number_format($liveStats['pending'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color:#dc3545;">
                <div class="metric-label">❌ Failed</div>
                <div class="metric-value" style="color:#dc3545;"><?php echo number_format($liveStats['failed'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color:#17a2b8;">
                <div class="metric-label">💰 Volume</div>
                <div class="metric-value"><?php echo number_format($liveStats['total_amount'] ?? 0, 2); ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Live Transaction Feed</span>
                <span class="card-badge" id="liveCount"><?php echo count($liveTransactions); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Fee</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody id="liveTransactionsBody">
                        <?php if (empty($liveTransactions)): ?>
                        <tr><td colspan="10" class="empty-state">No live transactions found<?php echo $search !== '' ? ' for "' . safeHtml($search) . '"' : ''; ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($liveTransactions as $index => $row): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['reference'] ?? 'N/A', 0, 12)); ?></td>
                            <td>
                                <?php
                                $type = $row['swap_type'] ?? 'STANDARD';
                                $typeClass = match($type) {
                                    'MULTI_DESTINATION' => 'status-info',
                                    'MULTI_SOURCE' => 'status-processing',
                                    'IDENTITY' => 'status-identity',
                                    'CASHOUT' => 'status-warning',
                                    default => 'status-info'
                                };
                                ?>
                                <span class="status <?php echo $typeClass; ?>"><?php echo safeHtml($type); ?></span>
                            </td>
                            <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                            <td><?php echo safeHtml($row['currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = match(true) {
                                    str_contains($status, 'complet') || str_contains($status, 'success') || str_contains($status, 'debited') => 'success',
                                    str_contains($status, 'pending') || str_contains($status, 'sent') || str_contains($status, 'processing') => 'pending',
                                    str_contains($status, 'fail') || str_contains($status, 'error') || str_contains($status, 'expired') => 'failed',
                                    default => 'info'
                                };
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo safeHtml($row['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['destination_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['fee_amount'] ?? 0), 2); ?></td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($row['created_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            let autoRefresh = true;
            let refreshInterval = null;

            function toggleAutoRefresh() {
                autoRefresh = !autoRefresh;
                const toggle = document.getElementById('refreshToggle');
                toggle.textContent = autoRefresh ? '🔄 AUTO-REFRESH ON' : '🔄 AUTO-REFRESH OFF';
                toggle.classList.toggle('active');
                if (autoRefresh) { startAutoRefresh(); }
                else { clearInterval(refreshInterval); }
            }

            function startAutoRefresh() {
                clearInterval(refreshInterval);
                refreshInterval = setInterval(function() {
                    fetch(window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'ajax=1')
                        .then(response => response.json())
                        .then(data => {
                            if (data.transactions) {
                                const tbody = document.getElementById('liveTransactionsBody');
                                let html = '';
                                data.transactions.forEach((row, i) => {
                                    const status = (row.status || 'pending').toLowerCase();
                                    let cls = 'info';
                                    if (status.includes('complet') || status.includes('success') || status.includes('debited')) cls = 'success';
                                    else if (status.includes('pending') || status.includes('processing')) cls = 'pending';
                                    else if (status.includes('fail') || status.includes('error')) cls = 'failed';
                                    const type = row.swap_type || 'STANDARD';
                                    let typeClass = 'status-info';
                                    if (type === 'MULTI_DESTINATION') typeClass = 'status-info';
                                    else if (type === 'MULTI_SOURCE') typeClass = 'status-processing';
                                    else if (type === 'IDENTITY') typeClass = 'status-identity';
                                    else if (type === 'CASHOUT') typeClass = 'status-warning';
                                    html += `<tr>
                                        <td>${i+1}</td>
                                        <td>${(row.swap_reference || row.reference || 'N/A').substring(0,12)}</td>
                                        <td><span class="status ${typeClass}">${type}</span></td>
                                        <td><strong>${Number(row.amount || 0).toFixed(2)}</strong></td>
                                        <td>${row.currency || 'BWP'}</td>
                                        <td><span class="status status-${cls}">${row.status || 'pending'}</span></td>
                                        <td>${row.source_institution || 'N/A'}</td>
                                        <td>${row.destination_institution || 'N/A'}</td>
                                        <td>${Number(row.fee_amount || 0).toFixed(2)}</td>
                                        <td>${new Date(row.created_at).toLocaleString()}</td>
                                    </tr>`;
                                });
                                tbody.innerHTML = html;
                                document.getElementById('liveCount').textContent = data.transactions.length;
                            }
                        })
                        .catch(e => console.error('Refresh failed:', e));
                }, 5000);
            }
            startAutoRefresh();
        </script>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- RECENT SWAPS VIEW - NO LIMIT, SEARCHABLE -->
        <!-- ============================================================ -->
        <?php if ($view === 'recent_swaps' && canView('recent_swaps')): ?>
        <div class="content-header">
            <h1>🔄 RECENT SWAPS</h1>
            <div class="timestamp">All swap transactions - complete history</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <form method="get" class="search-box" style="margin-bottom:16px;">
            <input type="hidden" name="view" value="recent_swaps">
            <input type="text" name="search" placeholder="Search reference, institution, status..." value="<?php echo safeHtml($search); ?>">
            <button type="submit" class="btn btn-primary btn-sm">SEARCH</button>
            <?php if ($search !== ''): ?>
            <a href="?view=recent_swaps" class="btn btn-sm">CLEAR</a>
            <?php endif; ?>
        </form>

        <div class="card">
            <div class="card-header">
                <span class="card-title">All Swaps</span>
                <span class="card-badge"><?php echo count($recentSwaps); ?> TOTAL RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Fee</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSwaps)): ?>
                        <tr><td colspan="9" class="empty-state">No swaps found<?php echo $search !== '' ? ' for "' . safeHtml($search) . '"' : ''; ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($recentSwaps as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['reference'] ?? 'N/A', 0, 16)); ?></td>
                            <td>
                                <?php
                                $type = $row['swap_type'] ?? 'STANDARD';
                                $typeClass = match($type) {
                                    'MULTI_DESTINATION' => 'status-info',
                                    'MULTI_SOURCE' => 'status-processing',
                                    'IDENTITY' => 'status-identity',
                                    'CASHOUT' => 'status-warning',
                                    default => 'status-info'
                                };
                                ?>
                                <span class="status <?php echo $typeClass; ?>"><?php echo safeHtml($type); ?></span>
                            </td>
                            <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                            <td><?php echo safeHtml($row['currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = match(true) {
                                    str_contains($status, 'complet') || str_contains($status, 'success') => 'success',
                                    str_contains($status, 'pending') || str_contains($status, 'processing') || str_contains($status, 'confirmation') => 'pending',
                                    str_contains($status, 'fail') || str_contains($status, 'error') || str_contains($status, 'expired') => 'failed',
                                    default => 'info'
                                };
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo safeHtml($row['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['destination_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['fee_amount'] ?? 0), 2); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($row['created_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- MULTI-DESTINATION VIEW - NO LIMIT -->
        <!-- ============================================================ -->
        <?php if ($view === 'multi_destination' && canView('multi_destination')): ?>
        <div class="content-header">
            <h1>🎯 MULTI-DESTINATION SWAPS</h1>
            <div class="timestamp">All multi-destination swaps - complete history</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <?php if (empty($multiDestinationSwaps)): ?>
        <div class="card">
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No multi-destination swaps found</p>
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($multiDestinationSwaps as $swap):
            $destinations = json_decode($swap['destinations_payload'] ?? '[]', true);
            $results = json_decode($swap['results_payload'] ?? '[]', true);
        ?>
        <div class="card" style="border-left: 6px solid <?php echo $swap['status'] === 'completed' ? '#28a745' : ($swap['status'] === 'partial' ? '#856404' : '#dc3545'); ?>;">
            <div class="card-header">
                <span class="card-title">
                    <?php echo safeHtml($swap['reference']); ?>
                    <span style="font-size:0.55rem; font-weight:400; color:#666;">
                        <?php echo date('Y-m-d H:i', strtotime($swap['created_at'])); ?>
                    </span>
                </span>
                <span class="card-badge <?php echo $swap['status'] === 'completed' ? 'success' : ($swap['status'] === 'partial' ? 'warning' : 'danger'); ?>">
                    <?php echo strtoupper($swap['status'] ?? 'UNKNOWN'); ?>
                </span>
            </div>

            <!-- Summary -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:8px; margin-bottom:12px; font-size:0.65rem; background:#f8f9fa; padding:10px; border-radius:4px;">
                <div><strong>Source:</strong> <?php echo safeHtml($swap['source_institution']); ?></div>
                <div><strong>Total:</strong> <?php echo number_format((float)($swap['total_amount'] ?? 0), 2); ?> BWP</div>
                <div><strong>Fees:</strong> <?php echo number_format((float)($swap['total_fees'] ?? 0), 2); ?> BWP</div>
                <div><strong>Delivered:</strong> <?php echo number_format((float)($swap['total_delivered'] ?? 0), 2); ?> BWP</div>
                <div><strong>✅ Success:</strong> <?php echo $swap['successful_count'] ?? 0; ?></div>
                <div><strong>❌ Failed:</strong> <?php echo $swap['failed_count'] ?? 0; ?></div>
                <div><strong>📦 Destinations:</strong> <?php echo $swap['total_destinations']; ?></div>
            </div>

            <!-- Destinations Table -->
            <?php if (!empty($destinations)): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Type</th>
                            <th>Institution</th>
                            <th>Identifier</th>
                            <th>Amount</th>
                            <th>Fee</th>
                            <th>Net</th>
                            <th>Status</th>
                            <th>Hold Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($destinations as $idx => $dest):
                            $result = $results[$idx] ?? [];
                            $status = $result['status'] ?? 'pending';
                            $error = $result['error'] ?? null;
                            $isIdentity = isset($dest['identity_type']) || isset($dest['identity_value']);
                            $isCashout = isset($dest['delivery_method']) && $dest['delivery_method'] === 'ATM';
                            $fee = (float)($result['fee'] ?? 0);
                            $net = (float)($result['net_amount'] ?? $dest['amount'] ?? 0);
                        ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td>
                                <?php if ($isIdentity): ?>
                                <span class="status status-identity">IDENTITY</span>
                                <?php elseif ($isCashout): ?>
                                <span class="status status-warning">CASHOUT</span>
                                <?php else: ?>
                                <span class="status status-info">DEPOSIT</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo safeHtml($dest['to_institution'] ?? $dest['destination_institution'] ?? ($isIdentity ? 'IDENTITY' : 'N/A')); ?></td>
                            <td>
                                <?php
                                if ($isIdentity) {
                                    echo safeHtml($dest['identity_type'] ?? 'national_id') . ': ' . safeHtml($dest['identity_value'] ?? 'N/A');
                                } elseif ($isCashout) {
                                    echo safeHtml($dest['beneficiary_phone'] ?? 'N/A');
                                } else {
                                    echo safeHtml($dest['destination_identifier'] ?? 'N/A');
                                }
                                ?>
                            </td>
                            <td><strong><?php echo number_format((float)($dest['amount'] ?? 0), 2); ?></strong></td>
                            <td style="color:#dc3545;"><?php echo number_format($fee, 2); ?></td>
                            <td style="color:#28a745;"><?php echo number_format($net, 2); ?></td>
                            <td>
                                <?php
                                $statusClass = match($status) {
                                    'success', 'completed' => 'success',
                                    'failed' => 'failed',
                                    'pending', 'pending_identity_confirmation' => 'pending',
                                    default => 'info'
                                };
                                $statusLabel = $status === 'pending_identity_confirmation' ? 'PENDING_ID' : ($status ?: 'PENDING');
                                ?>
                                <span class="status status-<?php echo $statusClass; ?>"><?php echo safeHtml(strtoupper($statusLabel)); ?></span>
                                <?php if ($error): ?>
                                <span style="color:#dc3545; font-size:0.55rem; display:block;" title="<?php echo safeHtml($error); ?>">⚠️ <?php echo safeHtml(substr($error, 0, 30)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo safeHtml(substr($result['hold_reference'] ?? 'N/A', 0, 10)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Raw JSON -->
            <details style="margin-top:12px;">
                <summary style="cursor:pointer; font-size:0.6rem; color:#666;">📄 Raw JSON</summary>
                <pre style="background:#1e293b; color:#4ade80; padding:12px; font-size:0.55rem; overflow-x:auto; max-height:300px; overflow-y:auto; margin-top:8px;"><?php
                    $fullData = [
                        'summary' => [
                            'reference' => $swap['reference'],
                            'source_institution' => $swap['source_institution'],
                            'status' => $swap['status'],
                            'total_amount' => $swap['total_amount'],
                            'total_fees' => $swap['total_fees']
                        ],
                        'destinations' => $destinations,
                        'results' => $results
                    ];
                    echo safeHtml(json_encode($fullData, JSON_PRETTY_PRINT));
                ?></pre>
            </details>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- ACCESS DENIED -->
        <!-- ============================================================ -->
        <?php
        $knownViews = ['dashboard', 'all_tables', 'multi_destination', 'live_transactions', 'recent_swaps', 'alerts', 'institution_health'];
        if (!canView($view) && !in_array($view, $knownViews)):
        ?>
        <div class="card">
            <div class="empty-state">
                <div class="icon">🚫</div>
                <h2>Access Denied</h2>
                <p>You do not have permission to view this page.</p>
                <a href="?view=dashboard" class="btn" style="margin-top:16px;">Return to Dashboard</a>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · <?php echo safeHtml($roleName); ?> · <?php echo date('Y'); ?></p>
        <p style="margin-top:4px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>
</body>
</html>
