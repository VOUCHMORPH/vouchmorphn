<?php
/**
 * admin_dashboard.php - VouchMorph Enhanced Role-Based Admin Dashboard
 * 
 * FEATURES:
 * 1. Role-based views (Super Admin, Regulator, Compliance, Auditor, etc.)
 * 2. Customer Support role (role_id 20) with Client Lookup by phone/national ID
 * 3. Live Transactions with search and auto-refresh
 * 4. Alerts/Exceptions panel (stuck holds, expired identities, stuck cashouts)
 * 5. Institution Health (volume, success rate, latency)
 * 6. Regulatory view with net positions and pending settlements
 * 7. Audit view with real audit_logs data
 * 8. Invoices view with FEE_INVOICE messages
 * 9. Multi-destination swaps with detailed breakdown
 * 10. Recent swaps with search
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
            'alerts', 'institution_health', 'client_lookup'
        ],
        'actions' => [
            'create', 'edit', 'delete', 'export', 'approve', 'reject',
            'generate_invoice', 'manage_users', 'view_fee_breakdown',
            'view_revenue_split', 'generate_financial_report', 'manage_fees',
            'generate_all_reports'
        ],
        'report_types' => ['all', 'financial', 'regulatory', 'compliance', 'audit', 'settlement', 'revenue'],
        'label' => 'SUPER ADMIN',
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
        'label' => 'REGULATOR',
        'badge_color' => '#8B0000'
    ],
    4 => [
        'name' => 'Compliance Officer',
        'level' => 80,
        'permissions' => ['review_transactions', 'kyc_verification', 'compliance_checks'],
        'view' => [
            'dashboard', 'transactions', 'audit', 'reports', 'compliance',
            'fee_breakdown', 'recent_swaps', 'generate_reports', 'live_transactions',
            'alerts', 'client_lookup'
        ],
        'actions' => ['view', 'review', 'approve', 'reject', 'export', 'generate_compliance_report'],
        'report_types' => ['compliance', 'audit', 'transaction', 'aml'],
        'label' => 'COMPLIANCE',
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
        'label' => 'AUDITOR',
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
        'label' => 'FINANCE',
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
        'label' => 'SETTLEMENT',
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
        'label' => 'REVENUE',
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
        'label' => 'COMPLIANCE AUDIT',
        'badge_color' => '#6f42c1'
    ],
    20 => [
        'name' => 'Customer Support',
        'level' => 40,
        'permissions' => ['view_own_lookup', 'read_only'],
        'view' => [
            'dashboard', 'client_lookup'
        ],
        'actions' => ['view', 'lookup'],
        'report_types' => [],
        'label' => 'SUPPORT',
        'badge_color' => '#20c997'
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
$isCustomerSupport = ($adminRoleId === 20);
$isReadOnly = in_array('read_only', $userPermissions) || $isAuditor || $isRegulator || $isCustomerSupport;

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
$lookup = trim($_GET['lookup'] ?? '');
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
        $stmt = $db->prepare("
            SELECT
                swap_reference, reference, swap_type, source_institution,
                destination_institution, amount, currency, status, fee_amount, created_at
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
            ':search1' => $likeSearch, ':search2' => $likeSearch, ':search3' => $likeSearch,
            ':search4' => $likeSearch, ':search5' => $likeSearch,
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
                swap_reference, reference, swap_type, source_institution,
                destination_institution, amount, currency, status, fee_amount, created_at
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
            ':search1' => $likeSearch, ':search2' => $likeSearch, ':search3' => $likeSearch,
            ':search4' => $likeSearch, ':search5' => $likeSearch,
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
        SELECT id, reference, source_institution, total_destinations, successful_count,
               failed_count, total_amount, total_fees, total_delivered, status,
               destinations_payload, results_payload, created_at, updated_at
        FROM multi_destination_swaps
        ORDER BY created_at DESC
    ");
    $multiDestinationSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Multi-destination fetch error: " . $e->getMessage());
}

// ============================================================
// ALERTS / EXCEPTIONS
// ============================================================
$alerts = ['stuck_holds' => [], 'expired_identity_swaps' => [], 'stuck_cashouts' => [], 'failed_destinations' => []];

try {
    $stmt = $db->query("
        SELECT hold_id, hold_reference, swap_reference, participant_name AS institution,
               asset_type, amount, currency, status, created_at
        FROM hold_transactions
        WHERE status IN ('ACTIVE','HELD','PENDING_CASHOUT','PENDING_IDENTITY')
          AND created_at < NOW() - INTERVAL '24 hours'
        ORDER BY created_at ASC LIMIT 300
    ");
    $alerts['stuck_holds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] stuck_holds alert error: " . $e->getMessage());
}

try {
    $stmt = $db->query("
        SELECT hold_id, swap_reference, source_institution, identity_type, identity_value,
               amount, currency, hold_expires_at, status, created_at
        FROM identity_swap_holds
        WHERE status = 'pending' AND hold_expires_at < NOW()
        ORDER BY hold_expires_at ASC LIMIT 300
    ");
    $alerts['expired_identity_swaps'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] expired_identity_swaps alert error: " . $e->getMessage());
}

try {
    $stmt = $db->query("
        SELECT auth_id, swap_reference, client_phone, source_institution, cashout_provider,
               amount, currency, code_expiry, status, created_at
        FROM cashout_authorizations
        WHERE status = 'PENDING' AND code_expiry < NOW()
        ORDER BY code_expiry ASC LIMIT 300
    ");
    $alerts['stuck_cashouts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] stuck_cashouts alert error: " . $e->getMessage());
}

try {
    $stmt = $db->query("
        SELECT id, reference, source_institution, created_at, results_payload
        FROM multi_destination_swaps WHERE failed_count > 0
        ORDER BY created_at DESC LIMIT 150
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $results = json_decode($row['results_payload'] ?? '[]', true) ?: [];
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'failed') {
                $alerts['failed_destinations'][] = [
                    'reference' => $row['reference'], 'source_institution' => $row['source_institution'],
                    'created_at' => $row['created_at'], 'type' => $r['type'] ?? 'bank',
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
            SELECT source_institution AS inst, status, amount FROM vw_all_swaps
            WHERE source_institution IS NOT NULL AND source_institution <> 'N/A'
            UNION ALL
            SELECT destination_institution AS inst, status, amount FROM vw_all_swaps
            WHERE destination_institution IS NOT NULL AND destination_institution <> 'N/A'
        ) combined
        GROUP BY inst ORDER BY total DESC
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
        WHERE debited_at IS NOT NULL AND source_institution IS NOT NULL
        GROUP BY source_institution
    ");
    $latencyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $latencyByInstitution = [];
    foreach ($latencyRows as $lr) { $latencyByInstitution[$lr['institution']] = $lr; }
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
// CLIENT LOOKUP - for Customer Support / Compliance / Super Admin
// ============================================================
$lookupResults = [];
if ($view === 'client_lookup' && $lookup !== '') {
    $likeLookup = '%' . $lookup . '%';

    try {
        $stmt = $db->prepare("
            SELECT swap_reference, source_institution, identity_type, identity_value,
                   amount, currency, hold_expires_at, status, created_at
            FROM identity_swap_holds
            WHERE identity_value ILIKE :l OR swap_reference ILIKE :l OR source_identifier ILIKE :l
            ORDER BY created_at DESC LIMIT 25
        ");
        $stmt->execute([':l' => $likeLookup]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isExpired = strtotime($row['hold_expires_at'] ?? 'now') < time();
            $nextAction = 'No action needed - completed.';
            if ($row['status'] === 'pending') {
                $nextAction = $isExpired
                    ? 'Confirmation window expired. This swap needs to be cancelled and re-initiated.'
                    : 'Recipient must confirm their identity (' . $row['identity_type'] . ') before ' . $row['hold_expires_at'] . ' to receive funds.';
            } elseif ($row['status'] === 'expired' || $row['status'] === 'cancelled') {
                $nextAction = 'This swap expired or was cancelled. Funds were returned to sender - a new swap is needed to try again.';
            }
            $lookupResults[] = [
                'kind' => 'Identity Swap', 'reference' => $row['swap_reference'],
                'institution' => $row['source_institution'], 'amount' => $row['amount'],
                'currency' => $row['currency'] ?? 'BWP', 'status' => $row['status'],
                'created_at' => $row['created_at'], 'next_action' => $nextAction,
                'identity' => ($row['identity_type'] ?? '') . ': ' . ($row['identity_value'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] client lookup identity_swap_holds error: " . $e->getMessage());
    }

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
            $nextAction = 'Completed - cash collected.';
            if ($row['status'] === 'PENDING') {
                $nextAction = $isExpired
                    ? 'Cashout code expired without being used. Client needs a new swap initiated.'
                    : 'Client must visit an ATM/Agent at ' . $row['cashout_provider'] . ' with their code before ' . $row['code_expiry'] . '.';
            } elseif ($row['status'] === 'VERIFIED') {
                $nextAction = 'Code was verified at the cashout point - completion should follow shortly. If it has been a while, escalate.';
            }
            $lookupResults[] = [
                'kind' => 'Cashout', 'reference' => $row['swap_reference'],
                'institution' => $row['source_institution'] . ' → ' . $row['cashout_provider'],
                'amount' => $row['amount'], 'currency' => $row['currency'] ?? 'BWP',
                'status' => $row['status'], 'created_at' => $row['created_at'],
                'next_action' => $nextAction, 'identity' => 'Phone: ' . ($row['client_phone'] ?? 'N/A'),
            ];
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] client lookup cashout_authorizations error: " . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("
            SELECT reference, source_institution, status, total_amount, total_destinations,
                   successful_count, failed_count, created_at
            FROM multi_destination_swaps
            WHERE reference ILIKE :l
               OR destinations_payload::text ILIKE :l
               OR results_payload::text ILIKE :l
            ORDER BY created_at DESC LIMIT 25
        ");
        $stmt->execute([':l' => $likeLookup]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nextAction = $row['failed_count'] > 0
                ? "{$row['failed_count']} of {$row['total_destinations']} destination(s) failed - needs manual review/retry."
                : 'All destinations processed.';
            $lookupResults[] = [
                'kind' => 'Multi-Destination', 'reference' => $row['reference'],
                'institution' => $row['source_institution'], 'amount' => $row['total_amount'],
                'currency' => 'BWP', 'status' => $row['status'], 'created_at' => $row['created_at'],
                'next_action' => $nextAction,
                'identity' => "{$row['successful_count']}/{$row['total_destinations']} succeeded",
            ];
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] client lookup multi_destination error: " . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("
            SELECT swap_reference, reference, swap_type, source_institution,
                   destination_institution, amount, currency, status, created_at
            FROM vw_all_swaps
            WHERE swap_reference ILIKE :l OR reference ILIKE :l
            ORDER BY created_at DESC LIMIT 25
        ");
        $stmt->execute([':l' => $likeLookup]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = strtolower($row['status'] ?? '');
            $nextAction = 'No action needed.';
            if (str_contains($status, 'pending')) $nextAction = 'Still processing - check back shortly, or escalate if older than a few minutes.';
            if (str_contains($status, 'fail')) $nextAction = 'This attempt failed. Escalate to ops for the reason before advising the client to retry.';
            $lookupResults[] = [
                'kind' => $row['swap_type'] ?? 'Swap', 'reference' => $row['swap_reference'] ?: $row['reference'],
                'institution' => $row['source_institution'] . ' → ' . ($row['destination_institution'] ?? 'N/A'),
                'amount' => $row['amount'], 'currency' => $row['currency'] ?? 'BWP',
                'status' => $row['status'], 'created_at' => $row['created_at'],
                'next_action' => $nextAction, 'identity' => '',
            ];
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] client lookup vw_all_swaps error: " . $e->getMessage());
    }

    // De-duplicate by reference+kind
    $seen = [];
    $deduped = [];
    foreach ($lookupResults as $r) {
        $key = $r['kind'] . '|' . $r['reference'];
        if (!isset($seen[$key])) { $seen[$key] = true; $deduped[] = $r; }
    }
    $lookupResults = $deduped;
}

// ============================================================
// REGULATORY VIEW DATA
// ============================================================
$netPositions = [];
$pendingSettlements = [];
if ($view === 'regulatory' && canView('regulatory')) {
    try {
        $netPositions = $db->query("SELECT * FROM net_positions ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] net_positions fetch error: " . $e->getMessage());
    }
    try {
        $pendingSettlements = $db->query("SELECT * FROM settlement_queue WHERE status = 'PENDING' ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] pending settlements fetch error: " . $e->getMessage());
    }
}

// ============================================================
// AUDIT VIEW DATA
// ============================================================
$auditRows = [];
if ($view === 'audit' && canView('audit')) {
    try {
        $auditRows = $db->query("SELECT * FROM audit_logs ORDER BY audit_id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] audit_logs fetch error: " . $e->getMessage());
    }
}

// ============================================================
// INVOICES VIEW DATA
// ============================================================
$invoiceMessages = [];
if ($view === 'invoices' && canView('invoices')) {
    try {
        $invoiceMessages = $db->query("
            SELECT * FROM settlement_outbox
            WHERE message_type = 'FEE_INVOICE'
            ORDER BY created_at DESC LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] invoices fetch error: " . $e->getMessage());
    }
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
if (!isset($lookupResults)) $lookupResults = [];
if (!isset($netPositions)) $netPositions = [];
if (!isset($pendingSettlements)) $pendingSettlements = [];
if (!isset($auditRows)) $auditRows = [];
if (!isset($invoiceMessages)) $invoiceMessages = [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · <?php echo safeHtml($roleName); ?> DASHBOARD</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           VOUCHMORPH — ADMIN DASHBOARD
           Two-colour scheme: Dark Navy + Brass/Gold
           Bold caps, clean lines, solid form elements
           ============================================================ */
        :root {
            --ink-900:      #0B1B2B;
            --ink-700:      #1D3557;
            --ink-500:      #4A5A6E;
            --ink-300:      #8A96A3;
            --panel:        #FFFFFF;
            --brass:        #9C7A3C;
            --brass-light:  #D4B87A;
            --brass-tint:   #F4EFE3;
            --grey-bg:      #F2F0ED;
            --danger:       #b3261e;
            --success:      #24513A;

            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;

            --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px; --sp-4: 16px;
            --sp-5: 20px; --sp-6: 24px; --sp-7: 32px; --sp-8: 40px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--f-body);
            background: var(--grey-bg);
            color: var(--ink-900);
            font-size: 14px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ============================================================
           TOP BAR — Solid dark navy with brass accents
           ============================================================ */
        .top-bar {
            background: var(--ink-900);
            padding: var(--sp-4) var(--sp-7);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: var(--sp-3);
            border-bottom: 3px solid var(--brass);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .top-bar .logo {
            font-family: var(--f-body);
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }
        .top-bar .logo span {
            color: var(--brass);
            font-weight: 400;
        }
        .top-bar .logo-sub {
            font-family: var(--f-cond);
            font-size: 8px;
            font-weight: 600;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--ink-300);
            margin-left: var(--sp-3);
        }
        .top-bar .user-area {
            display: flex;
            align-items: center;
            gap: var(--sp-5);
        }
        .top-bar .user-area .name {
            font-family: var(--f-cond);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #fff;
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
        .top-bar .logout-btn:hover {
            background: var(--brass);
            color: var(--ink-900);
        }

        /* ============================================================
           NAVIGATION — Bold caps, brass active state
           ============================================================ */
        .admin-nav {
            background: var(--panel);
            border-bottom: 2px solid var(--ink-900);
            padding: 0 var(--sp-7);
            display: flex;
            gap: var(--sp-5);
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: var(--sp-4) 0;
            color: var(--ink-500);
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
        .nav-item:hover { color: var(--ink-900); }
        .nav-item.active {
            color: var(--ink-900);
            border-bottom-color: var(--brass);
        }
        .nav-badge {
            background: var(--danger);
            color: #fff;
            font-size: 8px;
            padding: 1px 7px;
            border-radius: 0;
            font-weight: 700;
            font-family: var(--f-mono);
        }
        .nav-item .brass-badge {
            background: var(--brass);
            color: #fff;
            font-size: 8px;
            padding: 1px 7px;
            border-radius: 0;
            font-weight: 700;
            font-family: var(--f-mono);
        }

        /* ============================================================
           CONTENT AREA
           ============================================================ */
        .admin-content {
            padding: var(--sp-6) var(--sp-7);
            max-width: 1600px;
            margin: 0 auto;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: var(--sp-3);
            padding-bottom: var(--sp-4);
            border-bottom: 2px solid var(--ink-900);
            margin-bottom: var(--sp-6);
        }
        .content-header h1 {
            font-family: var(--f-cond);
            font-size: 22px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--ink-900);
            line-height: 1.1;
        }
        .content-header .timestamp {
            font-family: var(--f-mono);
            font-size: 10px;
            color: var(--ink-300);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .content-header .back-link {
            font-family: var(--f-cond);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--ink-500);
            text-decoration: none;
        }
        .content-header .back-link:hover {
            color: var(--brass);
        }

        /* ============================================================
           METRICS GRID — Solid blocks with brass accents
           ============================================================ */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: var(--sp-3);
            margin-bottom: var(--sp-6);
        }
        .metric-card {
            background: var(--panel);
            border: 2px solid var(--ink-900);
            padding: var(--sp-4) var(--sp-4) var(--sp-3);
            position: relative;
        }
        .metric-card .label {
            font-family: var(--f-cond);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--ink-500);
            display: block;
            margin-bottom: var(--sp-1);
        }
        .metric-card .value {
            font-family: var(--f-body);
            font-size: 26px;
            font-weight: 700;
            color: var(--ink-900);
            line-height: 1.1;
        }
        .metric-card .value .currency {
            font-family: var(--f-mono);
            font-size: 14px;
            color: var(--ink-300);
            font-weight: 400;
            margin-left: 4px;
        }
        .metric-card .sub {
            font-family: var(--f-mono);
            font-size: 9px;
            color: var(--ink-300);
            margin-top: var(--sp-1);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .metric-card.brass-border {
            border-top: 4px solid var(--brass);
        }
        .metric-card.danger-border {
            border-top: 4px solid var(--danger);
        }
        .metric-card.success-border {
            border-top: 4px solid var(--success);
        }

        /* ============================================================
           CARDS — Clean, sharp, brass accents
           ============================================================ */
        .card {
            background: var(--panel);
            border: 2px solid var(--ink-900);
            padding: var(--sp-5) var(--sp-5) var(--sp-4);
            margin-bottom: var(--sp-5);
            position: relative;
        }
        .card::before {
            content: "";
            position: absolute;
            top: -1px;
            left: -1px;
            width: 10px;
            height: 10px;
            border-top: 3px solid var(--brass);
            border-left: 3px solid var(--brass);
            pointer-events: none;
        }
        .card::after {
            content: "";
            position: absolute;
            bottom: -1px;
            right: -1px;
            width: 10px;
            height: 10px;
            border-bottom: 3px solid var(--brass);
            border-right: 3px solid var(--brass);
            pointer-events: none;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: var(--sp-3);
            border-bottom: 2px solid var(--ink-900);
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
            color: var(--ink-900);
        }
        .card-badge {
            font-family: var(--f-cond);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: var(--sp-1) var(--sp-3);
            background: var(--ink-900);
            color: #fff;
        }
        .card-badge.success { background: var(--success); }
        .card-badge.danger { background: var(--danger); }
        .card-badge.brass { background: var(--brass); }

        /* ============================================================
           SEARCH BOX — Solid, bold, brass focus
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
            border: 2px solid var(--ink-900);
            font-size: 13px;
            background: #fff;
            color: var(--ink-900);
            min-width: 260px;
            flex: 1;
        }
        .search-box input[type="text"]:focus {
            outline: none;
            border-color: var(--brass);
            background: #fff;
        }
        .search-box input[type="text"]::placeholder {
            color: var(--ink-300);
            font-family: var(--f-body);
        }

        /* ============================================================
           BUTTONS — Bold caps, solid
           ============================================================ */
        .btn {
            padding: var(--sp-2) var(--sp-5);
            font-family: var(--f-cond);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border: 2px solid var(--ink-900);
            background: transparent;
            color: var(--ink-900);
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: var(--sp-2);
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
        .btn-sm {
            padding: var(--sp-1) var(--sp-4);
            font-size: 9px;
        }

        /* ============================================================
           TABLES — Clean, bold headers
           ============================================================ */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th {
            font-family: var(--f-cond);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: var(--ink-900);
            color: #fff;
            padding: var(--sp-2) var(--sp-3);
            text-align: left;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        td {
            padding: var(--sp-2) var(--sp-3);
            border-bottom: 1px solid var(--ink-100);
            vertical-align: middle;
            font-family: var(--f-body);
            font-size: 12px;
            color: var(--ink-700);
        }
        tr:hover { background: var(--brass-tint); }

        /* ============================================================
           STATUS BADGES
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
        .status-success {
            background: #E5EEE7;
            color: var(--success);
            border-color: #B8D0C4;
        }
        .status-pending {
            background: #FDF6E3;
            color: #8A6D00;
            border-color: #E8D5A3;
        }
        .status-failed {
            background: #FCE8E8;
            color: var(--danger);
            border-color: #E8C8C8;
        }
        .status-info {
            background: #E8EEF4;
            color: #1A3A5C;
            border-color: #B8C9D8;
        }
        .status-warning {
            background: #FDF6E3;
            color: #8A6D00;
            border-color: #E8D5A3;
        }
        .status-identity {
            background: #EDE8F5;
            color: #5C3D7A;
            border-color: #D4C0E8;
        }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state {
            text-align: center;
            padding: var(--sp-7) var(--sp-4);
            color: var(--ink-300);
        }
        .empty-state .icon { font-size: 32px; display: block; margin-bottom: var(--sp-3); }
        .empty-state p { font-size: 13px; }

        /* ============================================================
           LIVE INDICATOR
           ============================================================ */
        .live-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 0;
            background: var(--danger);
            animation: pulse 1.2s ease-in-out infinite;
            margin-right: 8px;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        /* ============================================================
           HEALTH BAR
           ============================================================ */
        .health-bar-track {
            background: var(--ink-100);
            height: 8px;
            overflow: hidden;
        }
        .health-bar-fill { height: 100%; background: var(--success); }
        .health-bar-fill.warn { background: #8A6D00; }
        .health-bar-fill.bad { background: var(--danger); }

        /* ============================================================
           LOOKUP CARD
           ============================================================ */
        .lookup-card {
            border-left: 6px solid var(--brass);
            margin-bottom: var(--sp-4);
        }
        .lookup-next-action {
            background: var(--brass-tint);
            color: var(--ink-700);
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
            background: var(--ink-900);
            color: var(--ink-300);
            padding: var(--sp-4) var(--sp-7);
            text-align: center;
            font-family: var(--f-mono);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-top: 3px solid var(--brass);
            margin-top: var(--sp-6);
        }
        .admin-footer span { color: var(--brass); }

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

    <!-- ============================================================
       TOP BAR — Dark Navy + Brass
       ============================================================ -->
    <header class="top-bar">
        <div class="logo">
            VOUCHMORPH<span>ADMIN</span>
            <span class="logo-sub">· <?php echo safeHtml($roleName); ?></span>
        </div>
        <div class="user-area">
            <span class="name"><?php echo safeHtml($adminFullName ?: $adminUsername); ?></span>
            <span class="role"><?php echo safeHtml($roleName); ?></span>
            <?php if ($isReadOnly): ?><span style="color:var(--ink-300); font-family:var(--f-mono); font-size:9px;">🔒 READ</span><?php endif; ?>
            <a href="admin_logout.php" class="logout-btn">Sign Out</a>
        </div>
    </header>

    <!-- ============================================================
       NAVIGATION — Bold caps
       ============================================================ -->
    <nav class="admin-nav">
        <?php if (canView('dashboard')): ?><a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">Dashboard</a><?php endif; ?>
        <?php if (canView('client_lookup')): ?><a href="?view=client_lookup" class="nav-item <?php echo $view === 'client_lookup' ? 'active' : ''; ?>">Client Lookup</a><?php endif; ?>
        <?php if (canView('alerts')): ?>
        <a href="?view=alerts" class="nav-item <?php echo $view === 'alerts' ? 'active' : ''; ?>">
            Alerts <?php if ($totalAlerts > 0): ?><span class="nav-badge"><?php echo $totalAlerts; ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (canView('live_transactions')): ?><a href="?view=live_transactions" class="nav-item <?php echo $view === 'live_transactions' ? 'active' : ''; ?>">Live Txns</a><?php endif; ?>
        <?php if (canView('multi_destination')): ?><a href="?view=multi_destination" class="nav-item <?php echo $view === 'multi_destination' ? 'active' : ''; ?>">Multi-Dest</a><?php endif; ?>
        <?php if (canView('recent_swaps')): ?><a href="?view=recent_swaps" class="nav-item <?php echo $view === 'recent_swaps' ? 'active' : ''; ?>">Swaps</a><?php endif; ?>
        <?php if (canView('institution_health')): ?><a href="?view=institution_health" class="nav-item <?php echo $view === 'institution_health' ? 'active' : ''; ?>">Institutions</a><?php endif; ?>
        <?php if (canView('settlements')): ?><a href="?view=settlements" class="nav-item <?php echo $view === 'settlements' ? 'active' : ''; ?>">Settlements</a><?php endif; ?>
        <?php if (canView('regulatory')): ?><a href="?view=regulatory" class="nav-item <?php echo $view === 'regulatory' ? 'active' : ''; ?>">Regulatory</a><?php endif; ?>
        <?php if (canView('audit')): ?><a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">Audit</a><?php endif; ?>
        <?php if (canView('fee_breakdown') && hasFinancialAccess()): ?><a href="?view=fee_breakdown" class="nav-item <?php echo $view === 'fee_breakdown' ? 'active' : ''; ?>">Fees</a><?php endif; ?>
        <?php if (canView('invoices')): ?><a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">Invoices</a><?php endif; ?>
        <?php if (canView('all_tables') && $isSuperAdmin): ?><a href="?view=all_tables" class="nav-item <?php echo $view === 'all_tables' ? 'active' : ''; ?>">Tables</a><?php endif; ?>
    </nav>

    <!-- ============================================================
       CONTENT
       ============================================================ -->
    <main class="admin-content">

        <!-- ============================================================ -->
        <!-- DASHBOARD VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'dashboard'): ?>
        <div class="content-header">
            <h1>Dashboard</h1>
            <span class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></span>
        </div>

        <?php if ($totalAlerts > 0 && canView('alerts')): ?>
        <div class="card" style="border-color:var(--danger);">
            <div class="card-header">
                <span class="card-title" style="color:var(--danger);">⚠️ <?php echo $totalAlerts; ?> item<?php echo $totalAlerts === 1 ? '' : 's'; ?> require attention</span>
                <a href="?view=alerts" class="btn btn-primary btn-sm">View Alerts</a>
            </div>
            <div style="font-size:11px; color:var(--ink-500);">
                <?php echo $alertCounts['stuck_holds']; ?> stuck holds · <?php echo $alertCounts['expired_identity_swaps']; ?> expired identity ·
                <?php echo $alertCounts['stuck_cashouts']; ?> stuck cashouts · <?php echo $alertCounts['failed_destinations']; ?> failed
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isCustomerSupport): ?>
        <div class="card" style="border-color:var(--brass);">
            <div class="card-header"><span class="card-title">Quick Client Lookup</span></div>
            <p style="font-size:13px; margin-bottom:var(--sp-3);">Search by phone number, national ID, or reference.</p>
            <a href="?view=client_lookup" class="btn btn-primary">Go to Client Lookup</a>
        </div>
        <?php else: ?>
        <div class="metrics-grid">
            <div class="metric-card brass-border"><span class="label">Total Swaps</span><span class="value"><?php echo number_format($metrics['total_swaps'] ?? 0); ?></span><span class="sub">Lifetime</span></div>
            <div class="metric-card"><span class="label">Total Users</span><span class="value"><?php echo number_format($metrics['total_users'] ?? 0); ?></span><span class="sub">Registered</span></div>
            <div class="metric-card danger-border"><span class="label">Pending Settlements</span><span class="value"><?php echo number_format($metrics['pending_settlements'] ?? 0); ?></span><span class="sub">Awaiting</span></div>
            <div class="metric-card success-border"><span class="label">Total Fees</span><span class="value"><?php echo number_format($metrics['total_fees'] ?? 0, 2); ?><span class="currency">BWP</span></span><span class="sub">Collected</span></div>
            <div class="metric-card"><span class="label">24h Swaps</span><span class="value"><?php echo number_format($metrics['recent_swaps_24h'] ?? 0); ?></span><span class="sub">Last 24 hours</span></div>
            <div class="metric-card"><span class="label">Multi-Destination</span><span class="value"><?php echo number_format($metrics['multi_destination_count'] ?? 0); ?></span><span class="sub">Batches</span></div>
            <div class="metric-card"><span class="label">Multi-Source</span><span class="value"><?php echo number_format($metrics['multi_source_count'] ?? 0); ?></span><span class="sub">Batches</span></div>
            <div class="metric-card danger-border"><span class="label">Pending Identity</span><span class="value"><?php echo number_format($metrics['identity_swaps_pending'] ?? 0); ?></span><span class="sub">Awaiting confirmation</span></div>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">Quick Actions</span></div>
            <div style="display:flex; gap:var(--sp-3); flex-wrap:wrap;">
                <?php if (canView('invoices')): ?><a href="?view=invoices" class="btn btn-primary">Invoices</a><?php endif; ?>
                <?php if (canView('all_tables') && $isSuperAdmin): ?><a href="?view=all_tables" class="btn">All Tables</a><?php endif; ?>
                <?php if (canView('alerts')): ?><a href="?view=alerts" class="btn" style="border-color:var(--danger);color:var(--danger);">Alerts</a><?php endif; ?>
                <?php if (canView('institution_health')): ?><a href="?view=institution_health" class="btn">Institution Health</a><?php endif; ?>
                <?php if (canView('client_lookup')): ?><a href="?view=client_lookup" class="btn">Client Lookup</a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- CLIENT LOOKUP VIEW -->
        <!-- ============================================================ -->
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
        <?php else: ?>
        <?php foreach ($lookupResults as $r): ?>
        <div class="card lookup-card">
            <div class="card-header">
                <span class="card-title"><?php echo safeHtml($r['kind']); ?> — <?php echo safeHtml($r['reference']); ?></span>
                <?php
                $st = strtolower($r['status'] ?? '');
                $cls = match(true) {
                    str_contains($st, 'complet') || str_contains($st, 'success') || str_contains($st, 'debited') => 'success',
                    str_contains($st, 'pending') || str_contains($st, 'verified') => 'pending',
                    str_contains($st, 'fail') || str_contains($st, 'expired') || str_contains($st, 'cancel') => 'failed',
                    default => 'info'
                };
                ?>
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
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- ALERTS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'alerts' && canView('alerts')): ?>
        <div class="content-header">
            <h1>Alerts &amp; Exceptions</h1>
            <span class="timestamp">Items requiring human attention</span>
            <a href="?view=dashboard" class="back-link">← Back</a>
        </div>

        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
            <div class="metric-card danger-border"><span class="label">Stuck Holds</span><span class="value"><?php echo number_format($alertCounts['stuck_holds']); ?></span><span class="sub">&gt;24 hours</span></div>
            <div class="metric-card" style="border-top-color:#8A6D00;"><span class="label">Expired Identity</span><span class="value"><?php echo number_format($alertCounts['expired_identity_swaps']); ?></span><span class="sub">Unconfirmed</span></div>
            <div class="metric-card" style="border-top-color:#8A6D00;"><span class="label">Stuck Cashouts</span><span class="value"><?php echo number_format($alertCounts['stuck_cashouts']); ?></span><span class="sub">Expired</span></div>
            <div class="metric-card danger-border"><span class="label">Failed Destinations</span><span class="value"><?php echo number_format($alertCounts['failed_destinations']); ?></span><span class="sub">Need review</span></div>
        </div>

        <?php if ($totalAlerts === 0): ?>
        <div class="card"><div class="empty-state"><span class="icon">✅</span><p>All systems clear.</p></div></div>
        <?php endif; ?>

        <?php if (!empty($alerts['stuck_holds'])): ?>
        <div class="card">
            <div class="card-header"><span class="card-title" style="color:var(--danger);">🔒 Stuck Holds</span><span class="card-badge danger"><?php echo count($alerts['stuck_holds']); ?></span></div>
            <div class="table-responsive"><table><thead><tr><th>Hold Ref</th><th>Swap Ref</th><th>Institution</th><th>Asset</th><th>Amount</th><th>Status</th><th>Age</th></tr></thead><tbody>
            <?php foreach ($alerts['stuck_holds'] as $h): ?>
            <tr><td><?php echo safeHtml(substr($h['hold_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml(substr($h['swap_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml($h['institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($h['asset_type'] ?? ''); ?></td><td><?php echo number_format((float)($h['amount'] ?? 0), 2); ?> <?php echo safeHtml($h['currency'] ?? 'BWP'); ?></td><td><span class="status status-pending"><?php echo safeHtml($h['status'] ?? ''); ?></span></td><td><?php echo round((time() - strtotime($h['created_at'] ?? 'now')) / 3600, 1); ?>h</td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($alerts['expired_identity_swaps'])): ?>
        <div class="card">
            <div class="card-header"><span class="card-title" style="color:#8A6D00;">🪪 Expired Identity Swaps</span><span class="card-badge" style="background:#8A6D00;"><?php echo count($alerts['expired_identity_swaps']); ?></span></div>
            <div class="table-responsive"><table><thead><tr><th>Swap Ref</th><th>Institution</th><th>Identity</th><th>Amount</th><th>Expired At</th></tr></thead><tbody>
            <?php foreach ($alerts['expired_identity_swaps'] as $s): ?>
            <tr><td><?php echo safeHtml(substr($s['swap_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml($s['source_institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($s['identity_type'] ?? ''); ?>: <?php echo safeHtml($s['identity_value'] ?? ''); ?></td><td><?php echo number_format((float)($s['amount'] ?? 0), 2); ?> <?php echo safeHtml($s['currency'] ?? 'BWP'); ?></td><td><?php echo safeHtml($s['hold_expires_at'] ?? ''); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($alerts['stuck_cashouts'])): ?>
        <div class="card">
            <div class="card-header"><span class="card-title" style="color:#8A6D00;">💵 Expired Cashouts</span><span class="card-badge" style="background:#8A6D00;"><?php echo count($alerts['stuck_cashouts']); ?></span></div>
            <div class="table-responsive"><table><thead><tr><th>Swap Ref</th><th>Source</th><th>Provider</th><th>Phone</th><th>Amount</th><th>Expired</th></tr></thead><tbody>
            <?php foreach ($alerts['stuck_cashouts'] as $c): ?>
            <tr><td><?php echo safeHtml(substr($c['swap_reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml($c['source_institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($c['cashout_provider'] ?? 'N/A'); ?></td><td><?php echo safeHtml($c['client_phone'] ?? 'N/A'); ?></td><td><?php echo number_format((float)($c['amount'] ?? 0), 2); ?> <?php echo safeHtml($c['currency'] ?? 'BWP'); ?></td><td><?php echo safeHtml($c['code_expiry'] ?? ''); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($alerts['failed_destinations'])): ?>
        <div class="card">
            <div class="card-header"><span class="card-title" style="color:var(--danger);">❌ Failed Destinations</span><span class="card-badge danger"><?php echo count($alerts['failed_destinations']); ?></span></div>
            <div class="table-responsive"><table><thead><tr><th>Parent Ref</th><th>Source</th><th>Type</th><th>Target</th><th>Amount</th><th>Error</th></tr></thead><tbody>
            <?php foreach ($alerts['failed_destinations'] as $f): ?>
            <tr><td><?php echo safeHtml(substr($f['reference'] ?? '', 0, 16)); ?></td><td><?php echo safeHtml($f['source_institution'] ?? 'N/A'); ?></td><td><span class="status <?php echo $f['type'] === 'identity' ? 'status-identity' : 'status-info'; ?>"><?php echo safeHtml(strtoupper($f['type'])); ?></span></td><td><?php echo safeHtml($f['identity_value'] ?? $f['destination_institution'] ?? 'N/A'); ?></td><td><?php echo number_format((float)($f['amount'] ?? 0), 2); ?></td><td style="color:var(--danger); max-width:260px;"><?php echo safeHtml($f['error']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- INSTITUTION HEALTH VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'institution_health' && canView('institution_health')): ?>
        <div class="content-header">
            <h1>Institution Health</h1>
            <span class="timestamp">Volume · Success Rate · Latency</span>
            <a href="?view=dashboard" class="back-link">← Back</a>
        </div>

        <?php if (empty($institutionHealth)): ?>
        <div class="card"><div class="empty-state"><span class="icon">📭</span><p>No institution data available</p></div></div>
        <?php else: ?>
        <?php foreach ($institutionHealth as $inst): $rate = (float)$inst['success_rate']; $barClass = $rate >= 90 ? '' : ($rate >= 70 ? 'warn' : 'bad'); ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?php echo safeHtml($inst['institution']); ?></span>
                <span class="card-badge <?php echo $rate >= 90 ? 'success' : ($rate >= 70 ? 'brass' : 'danger'); ?>"><?php echo $rate; ?>% SUCCESS</span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:var(--sp-2); margin-bottom:var(--sp-3); font-size:12px;">
                <div><strong>Total:</strong> <?php echo number_format($inst['total']); ?></div>
                <div style="color:var(--success);"><strong>Successful:</strong> <?php echo number_format($inst['successful']); ?></div>
                <div style="color:#8A6D00;"><strong>Pending:</strong> <?php echo number_format($inst['pending']); ?></div>
                <div style="color:var(--danger);"><strong>Failed:</strong> <?php echo number_format($inst['failed']); ?></div>
                <div><strong>Volume:</strong> <?php echo number_format((float)$inst['volume'], 2); ?></div>
                <div><strong>Latency:</strong> <?php if ($inst['avg_latency_seconds'] !== null) { $secs = $inst['avg_latency_seconds']; echo $secs < 60 ? round($secs, 1) . 's' : round($secs / 60, 1) . 'm'; echo ' (n=' . $inst['debited_sample_size'] . ')'; } else { echo '—'; } ?></div>
            </div>
            <div class="health-bar-track"><div class="health-bar-fill <?php echo $barClass; ?>" style="width: <?php echo min(100, $rate); ?>%;"></div></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- REGULATORY VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'regulatory' && canView('regulatory')): ?>
        <div class="content-header">
            <h1>Regulatory Oversight</h1>
            <span class="timestamp">Net positions · Pending settlements</span>
            <a href="?view=dashboard" class="back-link">← Back</a>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">Institution Success Rates</span></div>
            <?php if (empty($institutionHealth)): ?>
            <div class="empty-state"><p>No data</p></div>
            <?php else: ?>
            <div class="table-responsive"><table><thead><tr><th>Institution</th><th>Total</th><th>Success Rate</th><th>Volume</th></tr></thead><tbody>
            <?php foreach ($institutionHealth as $inst): ?>
            <tr><td><?php echo safeHtml($inst['institution']); ?></td><td><?php echo number_format($inst['total']); ?></td><td><?php echo $inst['success_rate']; ?>%</td><td><?php echo number_format((float)$inst['volume'], 2); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">Net Positions</span><span class="card-badge"><?php echo count($netPositions); ?> RECORDS</span></div>
            <?php if (empty($netPositions)): ?>
            <div class="empty-state"><p>No net positions</p></div>
            <?php else: ?>
            <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($netPositions[0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach ($netPositions as $row): ?>
            <tr><?php foreach ($row as $val): ?><td><?php echo safeHtml(is_array($val) ? json_encode($val) : $val); ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">Pending Settlements</span><span class="card-badge brass"><?php echo count($pendingSettlements); ?> RECORDS</span></div>
            <?php if (empty($pendingSettlements)): ?>
            <div class="empty-state"><p>No pending settlements</p></div>
            <?php else: ?>
            <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($pendingSettlements[0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach ($pendingSettlements as $row): ?>
            <tr><?php foreach ($row as $val): ?><td><?php echo safeHtml(is_array($val) ? json_encode($val) : $val); ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- AUDIT VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'audit' && canView('audit')): ?>
        <div class="content-header">
            <h1>Audit Log</h1>
            <span class="timestamp">Most recent 200 entries</span>
            <a href="?view=dashboard" class="back-link">← Back</a>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">Audit Trail</span><span class="card-badge"><?php echo count($auditRows); ?> RECORDS</span></div>
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

        <!-- ============================================================ -->
        <!-- INVOICES VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'invoices' && canView('invoices')): ?>
        <div class="content-header">
            <h1>Invoices</h1>
            <span class="timestamp">Fee invoices from settlement</span>
            <a href="?view=dashboard" class="back-link">← Back</a>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">Fee Invoices</span><span class="card-badge success"><?php echo count($invoiceMessages); ?> RECORDS</span></div>
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

        <!-- ============================================================ -->
        <!-- LIVE TRANSACTIONS VIEW -->
        <!-- ============================================================ -->
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

        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));">
            <div class="metric-card"><span class="label">24h Total</span><span class="value"><?php echo number_format($liveStats['total'] ?? 0); ?></span></div>
            <div class="metric-card success-border"><span class="label">Completed</span><span class="value" style="color:var(--success);"><?php echo number_format($liveStats['completed'] ?? 0); ?></span></div>
            <div class="metric-card" style="border-top-color:#8A6D00;"><span class="label">Pending</span><span class="value" style="color:#8A6D00;"><?php echo number_format($liveStats['pending'] ?? 0); ?></span></div>
            <div class="metric-card danger-border"><span class="label">Failed</span><span class="value" style="color:var(--danger);"><?php echo number_format($liveStats['failed'] ?? 0); ?></span></div>
            <div class="metric-card"><span class="label">Volume</span><span class="value"><?php echo number_format($liveStats['total_amount'] ?? 0, 2); ?><span class="currency">BWP</span></span></div>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">Live Feed</span><span class="card-badge" id="liveCount"><?php echo count($liveTransactions); ?> RECORDS</span></div>
            <div class="table-responsive"><table><thead><tr><th>#</th><th>Reference</th><th>Type</th><th>Amount</th><th>Status</th><th>Source</th><th>Destination</th><th>Created</th></tr></thead><tbody id="liveTransactionsBody">
            <?php if (empty($liveTransactions)): ?>
            <tr><td colspan="8" class="empty-state">No transactions<?php echo $search !== '' ? ' for "' . safeHtml($search) . '"' : ''; ?></td></tr>
            <?php else: foreach ($liveTransactions as $index => $row): $type = $row['swap_type'] ?? 'STANDARD'; $typeClass = match($type) { 'MULTI_DESTINATION' => 'status-info', 'MULTI_SOURCE' => 'status-warning', 'IDENTITY' => 'status-identity', 'CASHOUT' => 'status-warning', default => 'status-info' }; $status = strtolower($row['status'] ?? 'pending'); $class = match(true) { str_contains($status, 'complet') || str_contains($status, 'success') || str_contains($status, 'debited') => 'success', str_contains($status, 'pending') || str_contains($status, 'processing') => 'pending', str_contains($status, 'fail') || str_contains($status, 'error') || str_contains($status, 'expired') => 'failed', default => 'info' }; ?>
            <tr>
                <td><?php echo $index + 1; ?></td>
                <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['reference'] ?? 'N/A', 0, 14)); ?></td>
                <td><span class="status <?php echo $typeClass; ?>"><?php echo safeHtml($type); ?></span></td>
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
            function toggleAutoRefresh() { autoRefresh = !autoRefresh; const toggle = document.getElementById('refreshToggle'); toggle.textContent = autoRefresh ? '🔄 AUTO ON' : '🔄 AUTO OFF'; toggle.classList.toggle('active'); if (autoRefresh) { startAutoRefresh(); } else { clearInterval(refreshInterval); } }
            function startAutoRefresh() { clearInterval(refreshInterval); refreshInterval = setInterval(function() { fetch(window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'ajax=1').then(r => r.json()).then(data => { if (data.transactions) { const tbody = document.getElementById('liveTransactionsBody'); let html = ''; data.transactions.forEach((row, i) => { const status = (row.status || 'pending').toLowerCase(); let cls = 'info'; if (status.includes('complet') || status.includes('success') || status.includes('debited')) cls = 'success'; else if (status.includes('pending') || status.includes('processing')) cls = 'pending'; else if (status.includes('fail') || status.includes('error')) cls = 'failed'; const type = row.swap_type || 'STANDARD'; let typeClass = 'status-info'; if (type === 'MULTI_DESTINATION') typeClass = 'status-info'; else if (type === 'MULTI_SOURCE') typeClass = 'status-warning'; else if (type === 'IDENTITY') typeClass = 'status-identity'; else if (type === 'CASHOUT') typeClass = 'status-warning'; html += `<tr><td>${i+1}</td><td>${(row.swap_reference || row.reference || 'N/A').substring(0,14)}</td><td><span class="status ${typeClass}">${type}</span></td><td><strong>${Number(row.amount || 0).toFixed(2)}</strong></td><td><span class="status status-${cls}">${row.status || 'pending'}</span></td><td>${row.source_institution || 'N/A'}</td><td>${row.destination_institution || 'N/A'}</td><td>${new Date(row.created_at).toLocaleString()}</td></tr>`; }); tbody.innerHTML = html; document.getElementById('liveCount').textContent = data.transactions.length; } }).catch(e => console.error('Refresh failed:', e)); }, 5000); }
            startAutoRefresh();
        </script>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- RECENT SWAPS VIEW -->
        <!-- ============================================================ -->
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
            <div class="card-header"><span class="card-title">All Swaps</span><span class="card-badge"><?php echo count($recentSwaps); ?> RECORDS</span></div>
            <div class="table-responsive"><table><thead><tr><th>Reference</th><th>Type</th><th>Amount</th><th>Status</th><th>Source</th><th>Destination</th><th>Created</th></tr></thead><tbody>
            <?php if (empty($recentSwaps)): ?>
            <tr><td colspan="7" class="empty-state">No swaps<?php echo $search !== '' ? ' for "' . safeHtml($search) . '"' : ''; ?></td></tr>
            <?php else: foreach ($recentSwaps as $row): $type = $row['swap_type'] ?? 'STANDARD'; $typeClass = match($type) { 'MULTI_DESTINATION' => 'status-info', 'MULTI_SOURCE' => 'status-warning', 'IDENTITY' => 'status-identity', 'CASHOUT' => 'status-warning', default => 'status-info' }; $status = strtolower($row['status'] ?? 'pending'); $class = match(true) { str_contains($status, 'complet') || str_contains($status, 'success') => 'success', str_contains($status, 'pending') || str_contains($status, 'processing') => 'pending', str_contains($status, 'fail') || str_contains($status, 'error') || str_contains($status, 'expired') => 'failed', default => 'info' }; ?>
            <tr>
                <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['reference'] ?? 'N/A', 0, 16)); ?></td>
                <td><span class="status <?php echo $typeClass; ?>"><?php echo safeHtml($type); ?></span></td>
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

        <!-- ============================================================ -->
        <!-- MULTI-DESTINATION VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'multi_destination' && canView('multi_destination')): ?>
        <div class="content-header">
            <h1>Multi-Destination Swaps</h1>
            <span class="timestamp">Batch disbursements with multiple destinations</span>
            <a href="?view=dashboard" class="back-link">← Back</a>
        </div>

        <?php if (empty($multiDestinationSwaps)): ?>
        <div class="card"><div class="empty-state"><span class="icon">📭</span><p>No multi-destination swaps found</p></div></div>
        <?php else: foreach ($multiDestinationSwaps as $swap): $destinations = json_decode($swap['destinations_payload'] ?? '[]', true); $results = json_decode($swap['results_payload'] ?? '[]', true); ?>
        <div class="card" style="border-left: 6px solid <?php echo $swap['status'] === 'completed' ? 'var(--success)' : ($swap['status'] === 'partial' ? '#8A6D00' : 'var(--danger)'); ?>;">
            <div class="card-header">
                <span class="card-title"><?php echo safeHtml($swap['reference']); ?> <span style="font-weight:400;color:var(--ink-300);font-size:10px;"><?php echo date('Y-m-d H:i', strtotime($swap['created_at'])); ?></span></span>
                <span class="card-badge <?php echo $swap['status'] === 'completed' ? 'success' : ($swap['status'] === 'partial' ? 'brass' : 'danger'); ?>"><?php echo strtoupper($swap['status'] ?? 'UNKNOWN'); ?></span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap:var(--sp-2); margin-bottom:var(--sp-3); font-size:11px; background:var(--grey-bg); padding:var(--sp-3);">
                <div><strong>Source:</strong> <?php echo safeHtml($swap['source_institution']); ?></div>
                <div><strong>Total:</strong> <?php echo number_format((float)($swap['total_amount'] ?? 0), 2); ?></div>
                <div><strong>✅ Success:</strong> <?php echo $swap['successful_count'] ?? 0; ?></div>
                <div><strong>❌ Failed:</strong> <?php echo $swap['failed_count'] ?? 0; ?></div>
                <div><strong>📦 Dest:</strong> <?php echo $swap['total_destinations']; ?></div>
            </div>
            <?php if (!empty($destinations)): ?>
            <div class="table-responsive"><table><thead><tr><th>#</th><th>Type</th><th>Institution</th><th>Identifier</th><th>Amount</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($destinations as $idx => $dest): $result = $results[$idx] ?? []; $status = $result['status'] ?? 'pending'; $isIdentity = isset($dest['identity_type']) || isset($dest['identity_value']); $isCashout = isset($dest['delivery_method']) && $dest['delivery_method'] === 'ATM'; ?>
            <tr>
                <td><?php echo $idx + 1; ?></td>
                <td><?php if ($isIdentity): ?><span class="status status-identity">IDENTITY</span><?php elseif ($isCashout): ?><span class="status status-warning">CASHOUT</span><?php else: ?><span class="status status-info">DEPOSIT</span><?php endif; ?></td>
                <td><?php echo safeHtml($dest['to_institution'] ?? $dest['destination_institution'] ?? ($isIdentity ? 'IDENTITY' : 'N/A')); ?></td>
                <td><?php if ($isIdentity) { echo safeHtml($dest['identity_type'] ?? 'national_id') . ': ' . safeHtml($dest['identity_value'] ?? 'N/A'); } elseif ($isCashout) { echo safeHtml($dest['beneficiary_phone'] ?? 'N/A'); } else { echo safeHtml($dest['destination_identifier'] ?? 'N/A'); } ?></td>
                <td><strong><?php echo number_format((float)($dest['amount'] ?? 0), 2); ?></strong></td>
                <td>
                    <?php $statusClass = match($status) { 'success', 'completed' => 'success', 'failed' => 'failed', 'pending', 'pending_identity_confirmation' => 'pending', default => 'info' }; ?>
                    <span class="status status-<?php echo $statusClass; ?>"><?php echo safeHtml(strtoupper($status === 'pending_identity_confirmation' ? 'PENDING_ID' : ($status ?: 'PENDING'))); ?></span>
                    <?php if ($result['error'] ?? null): ?><span style="color:var(--danger);font-size:9px;display:block;"><?php echo safeHtml(substr($result['error'], 0, 30)); ?></span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <details style="margin-top:var(--sp-3);"><summary style="cursor:pointer;font-size:10px;color:var(--ink-300);">📄 Show Raw</summary><pre style="background:var(--ink-900);color:#4ade80;padding:var(--sp-3);font-size:10px;overflow-x:auto;max-height:300px;overflow-y:auto;margin-top:var(--sp-2);"><?php echo safeHtml(json_encode(['reference' => $swap['reference'], 'status' => $swap['status'], 'destinations' => $destinations, 'results' => $results], JSON_PRETTY_PRINT)); ?></pre></details>
        </div>
        <?php endforeach; endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- ACCESS DENIED -->
        <!-- ============================================================ -->
        <?php
        $knownViews = ['dashboard', 'client_lookup', 'alerts', 'live_transactions', 'multi_destination', 'recent_swaps', 'institution_health', 'regulatory', 'audit', 'invoices', 'fee_breakdown', 'all_tables', 'settlements'];
        if (!canView($view) && !in_array($view, $knownViews)):
        ?>
        <div class="card"><div class="empty-state"><span class="icon">🚫</span><h2 style="color:var(--danger);font-family:var(--f-cond);text-transform:uppercase;font-size:18px;margin-bottom:var(--sp-2);">Access Denied</h2><p>You do not have permission to view this page.</p><a href="?view=dashboard" class="btn btn-primary" style="margin-top:var(--sp-4);">Return to Dashboard</a></div></div>
        <?php endif; ?>

    </main>

    <!-- ============================================================
       FOOTER
       ============================================================ -->
    <footer class="admin-footer">
        VOUCHMORPH · <?php echo safeHtml($roleName); ?> · <?php echo date('Y'); ?>
        <span style="display:block;margin-top:2px;font-size:8px;color:var(--ink-500);">Bank of Botswana Regulatory Sandbox Participant</span>
    </footer>

</body>
</html>
