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
            'alerts', 'client_lookup'
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
        'label' => '📞 Customer Support',
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

// ============================================================
// DESCRIPTION PANEL — one per view, alternating sides. Purely
// presentational; touches nothing above or below it.
// ============================================================
$viewMeta = [
    'dashboard' => [
        'side' => 'left', 'eyebrow' => 'Operating Picture',
        'blurb' => "This is the operating picture — total swap volume, pending settlements, and the handful of numbers that tell you whether the system is healthy right now, without opening a single table."
    ],
    'client_lookup' => [
        'side' => 'right', 'eyebrow' => 'Customer Support',
        'blurb' => "Search by phone number, national ID, or any reference a customer can read off their own confirmation message. Every match comes with a plain next step, not just a status code."
    ],
    'alerts' => [
        'side' => 'left', 'eyebrow' => 'Exceptions',
        'blurb' => "Holds, cashouts, and identity swaps that have sat in a non-terminal state longer than expected. These need a human decision — everything else is just monitoring."
    ],
    'live_transactions' => [
        'side' => 'right', 'eyebrow' => 'Real-Time Feed',
        'blurb' => "A rolling view of the last twenty-four hours, refreshing on its own. Watch volume move without reloading the page."
    ],
    'multi_destination' => [
        'side' => 'left', 'eyebrow' => 'Batch Settlement',
        'blurb' => "One instruction, many destinations. A single batch can reach bank accounts, wallets, and identity-linked beneficiaries at once — this is where each leg settles independently."
    ],
    'recent_swaps' => [
        'side' => 'right', 'eyebrow' => 'Transaction Ledger',
        'blurb' => "The complete transaction ledger, searchable by reference, institution, or status. Nothing here is paginated away."
    ],
    'institution_health' => [
        'side' => 'left', 'eyebrow' => 'Institution Health',
        'blurb' => "Volume, success rate, and average time-to-debit, broken down per institution. The bar tells you at a glance who's having a bad day."
    ],
    'settlements' => [
        'side' => 'right', 'eyebrow' => 'Settlement Queue',
        'blurb' => "Net positions and settlements still waiting to clear — the accounting layer underneath every swap."
    ],
    'regulatory' => [
        'side' => 'left', 'eyebrow' => 'Regulatory Oversight',
        'blurb' => "Net positions between institutions and pending settlements — the numbers a regulator needs, not the raw transaction feed."
    ],
    'audit' => [
        'side' => 'right', 'eyebrow' => 'Audit Trail',
        'blurb' => "Every recorded action, most recent first. This is the trail — who did what, and when."
    ],
    'fee_breakdown' => [
        'side' => 'left', 'eyebrow' => 'Fee Breakdown',
        'blurb' => "Where the fee on every transaction actually goes, broken down by type and by participant."
    ],
    'invoices' => [
        'side' => 'right', 'eyebrow' => 'Invoicing',
        'blurb' => "Fee invoices generated automatically through settlement — the paper trail for what's owed to whom."
    ],
    'all_tables' => [
        'side' => 'left', 'eyebrow' => 'Raw Tables',
        'blurb' => "Direct access to the underlying tables, for the rare moment a dashboard view isn't enough."
    ],
];
$currentMeta = $viewMeta[$view] ?? [
    'side' => 'right', 'eyebrow' => 'VouchMorph Admin',
    'blurb' => "Administrative tools for VouchMorph's enterprise disbursement network."
];
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
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           VOUCHMORPH ADMIN — Black, White, Red.
           Red is the one accent doing double duty: it's the
           structural rule (header border, corner ticks, active tab,
           table rule) AND the sole color for genuine alerts/danger —
           so when red appears on a status badge, it means the same
           thing it means everywhere else on the page: attention.
           Type stepped up +2px across the board. Every label — nav,
           badges, buttons, table headers, section titles — set in
           caps, the way an inscription would be cut in stone.
           ============================================================ */
        :root {
            --paper:        #F2F2F0;
            --panel:        #FFFFFF;
            --parchment:    #F7F7F5;
            --black:        #0A0A0A;
            --ink-500:      #4A4A4A;
            --ink-300:      #8A8A8A;
            --line:         #DCDCDA;
            --line-strong:  #B8B8B4;
            --red:          #A31E17;
            --red-tint:     #F5E3E1;

            --f-display: 'Source Serif 4', 'IBM Plex Sans', serif;
            --f-body:    'IBM Plex Sans', sans-serif;
            --f-cond:    'IBM Plex Sans Condensed', sans-serif;
            --f-mono:    'IBM Plex Mono', monospace;

            --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px; --sp-4: 16px;
            --sp-5: 20px; --sp-6: 24px; --sp-7: 32px; --sp-8: 40px; --sp-9: 48px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--black); min-height: 100vh; font-size: 16.5px; line-height: 1.6; -webkit-font-smoothing: antialiased; }
        :focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }

        /* ============================================================
           RIBBON
           ============================================================ */
        .admin-ribbon {
            background: var(--black); color: var(--ink-300);
            font-family: var(--f-mono); font-size: 12.5px; letter-spacing: 0.09em;
            text-transform: uppercase; padding: var(--sp-2) var(--sp-7);
            display: flex; align-items: center; justify-content: space-between;
            gap: var(--sp-3); border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .admin-ribbon strong { color: var(--red); font-weight: 700; letter-spacing: 0; }

        /* ============================================================
           HEADER
           ============================================================ */
        .admin-header {
            background: var(--black);
            border-bottom: 3px solid var(--red);
            padding: var(--sp-6) var(--sp-8);
            display: flex; justify-content: space-between; align-items: center;
            color: #fff; flex-wrap: wrap; gap: var(--sp-4);
        }
        .header-left { display: flex; align-items: center; gap: var(--sp-6); flex-wrap: wrap; }
        .logo { font-family: var(--f-display); font-weight: 600; font-size: 28px; letter-spacing: 0.02em; text-transform: uppercase; }
        .logo span { color: var(--red); font-weight: 500; font-family: var(--f-cond); font-size: 13.5px; letter-spacing: 0.16em; text-transform: uppercase; margin-left: var(--sp-3); vertical-align: middle; border-left: 1px solid rgba(255,255,255,0.3); padding-left: var(--sp-3); }
        .role-badge { padding: 5px var(--sp-4); background: transparent; color: #fff; font-size: 12.5px; text-transform: uppercase; letter-spacing: 0.08em; border-radius: 0; font-weight: 600; font-family: var(--f-cond); border: 1px solid rgba(255,255,255,0.5); }
        .readonly-badge { padding: 5px var(--sp-4); background: transparent; color: var(--red); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; border-radius: 0; border: 1px solid var(--red); font-family: var(--f-cond); }
        .user-info { display: flex; align-items: center; gap: var(--sp-5); flex-wrap: wrap; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; color: #fff; font-size: 16.5px; font-family: var(--f-display); line-height: 1.3; }
        .user-role { font-size: 12px; color: var(--ink-300); text-transform: uppercase; letter-spacing: 0.07em; font-family: var(--f-cond); }
        .logout-btn { padding: var(--sp-2) var(--sp-5); background: transparent; border: 1px solid rgba(255,255,255,0.3); color: rgba(255,255,255,0.9); text-decoration: none; font-size: 12.5px; font-weight: 600; letter-spacing: 0.07em; transition: all 0.15s; border-radius: 0; font-family: var(--f-cond); text-transform: uppercase; }
        .logout-btn:hover { background: var(--red); border-color: var(--red); color: #fff; }

        /* ============================================================
           NAV
           ============================================================ */
        .admin-nav { background: var(--panel); border-bottom: 1px solid var(--line-strong); padding: 0 var(--sp-8); display: flex; gap: var(--sp-7); flex-wrap: wrap; align-items: center; overflow-x: auto; }
        .nav-item { padding: var(--sp-4) 0; color: var(--ink-500); text-decoration: none; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid transparent; transition: all 0.15s; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; font-family: var(--f-cond); }
        .nav-item:hover { color: var(--black); }
        .nav-item.active { color: var(--black); border-bottom-color: var(--red); }
        .nav-item.finance, .nav-item.regulator, .nav-item.alerts, .nav-item.support { color: var(--ink-500); }
        .nav-item.finance:hover, .nav-item.regulator:hover, .nav-item.alerts:hover, .nav-item.support:hover { color: var(--black); }
        .nav-item.finance.active, .nav-item.regulator.active, .nav-item.alerts.active, .nav-item.support.active { color: var(--black); border-bottom-color: var(--red); }
        .nav-badge { background: var(--red); color: #fff; font-size: 11.5px; padding: 1px 7px; border-radius: 0; font-weight: 700; font-family: var(--f-mono); }

        /* ============================================================
           CONTENT
           ============================================================ */
        .admin-content { padding: var(--sp-8) var(--sp-8); max-width: 1720px; margin: 0 auto; }
        .content-header { margin-bottom: var(--sp-7); padding-bottom: var(--sp-5); border-bottom: 1px solid var(--line-strong); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--sp-3); }
        .content-header h1 { font-size: 27px; font-weight: 600; color: var(--black); font-family: var(--f-display); letter-spacing: 0.03em; text-transform: uppercase; }
        .content-header .timestamp { color: var(--ink-300); font-size: 13px; font-family: var(--f-mono); }

        /* ============================================================
           METRICS
           ============================================================ */
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1px; background: var(--line); border: 1px solid var(--line); margin-bottom: var(--sp-7); }
        .metric-card { background: var(--panel); border-top: 3px solid var(--red); padding: var(--sp-5); }
        .metric-label { font-size: 12px; text-transform: uppercase; color: var(--ink-300); letter-spacing: 0.07em; margin-bottom: var(--sp-2); font-family: var(--f-cond); font-weight: 600; }
        .metric-value { font-size: 31px; font-weight: 600; color: var(--black); font-family: var(--f-display); font-variant-numeric: tabular-nums; }

        /* ============================================================
           CARDS
           ============================================================ */
        .card { position: relative; background: var(--panel); border: 1px solid var(--line-strong); padding: var(--sp-6); margin-bottom: var(--sp-5); }
        .card::before, .card::after { content: ""; position: absolute; width: 11px; height: 11px; pointer-events: none; }
        .card::before { top: -1px; left: -1px; border-top: 2px solid var(--red); border-left: 2px solid var(--red); }
        .card::after { bottom: -1px; right: -1px; border-bottom: 2px solid var(--red); border-right: 2px solid var(--red); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--sp-5); padding-bottom: var(--sp-4); border-bottom: 1px solid var(--line); flex-wrap: wrap; gap: var(--sp-3); }
        .card-title { font-size: 20px; font-weight: 600; text-transform: uppercase; font-family: var(--f-display); letter-spacing: 0.025em; }
        .card-badge { padding: 3px var(--sp-3); background: var(--black); color: #fff; font-size: 12px; letter-spacing: 0.05em; border-radius: 0; font-family: var(--f-mono); font-weight: 600; text-transform: uppercase; }
        .card-badge.success, .card-badge.warning, .card-badge.info { background: var(--black); }
        .card-badge.danger { background: var(--red); }

        /* ============================================================
           SEARCH / FORM CONTROLS
           ============================================================ */
        .search-box { display: flex; gap: var(--sp-3); align-items: center; flex-wrap: wrap; }
        .search-box input[type=text] { font-family: var(--f-body); padding: var(--sp-3) var(--sp-4); border: 1.5px solid var(--line-strong); border-radius: 0; font-size: 15px; min-width: 290px; background: var(--paper); color: var(--black); transition: border-color .15s, background .15s; }
        .search-box input[type=text]:focus { outline: none; border-color: var(--red); background: #fff; }

        /* ============================================================
           TABLES
           ============================================================ */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14.5px; font-family: var(--f-mono); font-variant-numeric: tabular-nums; }
        th { background: var(--paper); color: var(--ink-500); padding: var(--sp-3) var(--sp-4); font-weight: 700; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; position: sticky; top: 0; z-index: 10; border-bottom: 2px solid var(--red); font-family: var(--f-cond); }
        td { padding: var(--sp-2) var(--sp-4); border-bottom: 1px solid var(--line); font-size: 14px; }
        tr:hover { background: var(--red-tint); }
        tr:nth-child(even) { background: var(--parchment); }

        /* ============================================================
           STATUS BADGES — black outline = neutral, red = attention.
           That's the whole vocabulary now, and it's unambiguous.
           ============================================================ */
        .status { display: inline-flex; align-items: center; gap: 5px; padding: 3px var(--sp-3) 3px 7px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; border: 1px solid; border-radius: 0; font-family: var(--f-cond); }
        .status::before { content: ""; width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
        .status-success { background: var(--panel); color: var(--black); border-color: var(--black); } .status-success::before { background: var(--black); }
        .status-pending { background: var(--black); color: #fff; border-color: var(--black); } .status-pending::before { background: #fff; }
        .status-failed { background: var(--red-tint); color: var(--red); border-color: var(--red); } .status-failed::before { background: var(--red); }
        .status-info { background: var(--panel); color: var(--black); border-color: var(--black); } .status-info::before { background: var(--black); }
        .status-warning { background: var(--red-tint); color: var(--red); border-color: var(--red); } .status-warning::before { background: var(--red); }
        .status-processing { background: var(--black); color: #fff; border-color: var(--black); } .status-processing::before { background: #fff; }
        .status-identity { background: var(--panel); color: var(--black); border-color: var(--black); border-style: dashed; } .status-identity::before { background: var(--black); }

        /* ============================================================
           BUTTONS — black is default, red is the only other option
           ============================================================ */
        .btn { padding: var(--sp-2) var(--sp-5); font-size: 13px; border: 1px solid var(--black); background: #fff; color: var(--black); cursor: pointer; font-family: var(--f-cond); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.15s; border-radius: 0; text-decoration: none; display: inline-block; }
        .btn:hover { background: var(--black); color: #fff; }
        .btn-primary { background: var(--black); color: #fff; }
        .btn-primary:hover { background: var(--red); color: #fff; border-color: var(--red); }
        .btn-success, .btn-support { border-color: var(--black); color: var(--black); }
        .btn-success:hover, .btn-support:hover { background: var(--black); color: #fff; }
        .btn-danger { border-color: var(--red); color: var(--red); }
        .btn-danger:hover { background: var(--red); color: #fff; border-color: var(--red); }
        .btn-sm { padding: 4px var(--sp-3); font-size: 12px; }

        /* ============================================================
           EMPTY STATE / FOOTER / MISC
           ============================================================ */
        .empty-state { text-align: center; padding: var(--sp-9) var(--sp-5); color: var(--ink-300); }
        .empty-state .icon { font-size: 2.1rem; margin-bottom: var(--sp-3); }
        .admin-footer { background: var(--black); color: var(--ink-300); padding: var(--sp-5) var(--sp-7); font-size: 13px; text-align: center; border-top: 2px solid var(--red); margin-top: var(--sp-7); font-family: var(--f-mono); }
        .live-indicator { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--red); animation: pulse 1.5s ease-in-out infinite; margin-right: var(--sp-2); }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
        .auto-refresh-toggle { cursor: pointer; padding: var(--sp-1) var(--sp-3); border-radius: 0; border: 1px solid var(--black); font-size: 12.5px; font-weight: 600; background: #fff; color: var(--black); transition: all 0.15s; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: 0.04em; }
        .auto-refresh-toggle.active { background: var(--black); color: #fff; border-color: var(--black); }
        .health-bar-track { background: var(--line); border-radius: 0; height: 8px; width: 100%; overflow: hidden; }
        .health-bar-fill { height: 100%; background: var(--black); }
        .health-bar-fill.warn { background: var(--red); }
        .health-bar-fill.bad { background: var(--red); }
        .lookup-card { border-left: 3px solid var(--red); margin-bottom: var(--sp-3); }
        .lookup-next-action { background: var(--paper); color: var(--black); padding: var(--sp-3); border-radius: 0; font-size: 14.5px; margin-top: var(--sp-3); font-weight: 600; font-family: var(--f-body); border: 1px solid var(--line-strong); }

        @media (max-width: 900px) {
            .admin-content { padding: var(--sp-6) var(--sp-5); }
            .admin-header { padding: var(--sp-4) var(--sp-5); }
            .admin-ribbon { padding: var(--sp-2) var(--sp-4); flex-direction: column; gap: 3px; }
            .admin-nav { padding: 0 var(--sp-5); }
        }
        @media (max-width: 768px) {
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            th, td { font-size: 12.5px; padding: var(--sp-2) var(--sp-2); }
            .content-header h1 { font-size: 22px; }
        }
        /* ============================================================
           WORKSPACE SPLIT — the login page's dark/light split,
           continued past the front door. One side is always the
           30–40% dark description panel; the other is the working
           dashboard. Which side is dark alternates per view, driven
           by $currentMeta['side'] in PHP — nothing here decides that,
           it only lays out whichever side it's told.
           ============================================================ */
        .workspace { display: flex; align-items: stretch; }
        .workspace.dark-left { flex-direction: row; }
        .workspace.dark-right { flex-direction: row-reverse; }

        .panel-dark {
            flex: 0 0 36%;
            min-width: 0;
            background: var(--black);
            color: rgba(255,255,255,0.92);
            padding: var(--sp-8) var(--sp-7);
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .panel-dark .eyebrow {
            font-family: var(--f-cond);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--red);
            margin-bottom: var(--sp-5);
        }
        .panel-dark p {
            font-family: var(--f-display);
            font-size: 19px;
            font-weight: 400;
            line-height: 1.8;
            max-width: 400px;
        }
        .panel-dark p::first-letter {
            font-size: 46px;
            font-weight: 600;
            color: var(--red);
            float: left;
            line-height: 0.8;
            padding-right: var(--sp-2);
            padding-top: 4px;
        }
        .panel-dark .mark { margin-top: var(--sp-6); width: 40px; height: 2px; background: var(--red); }

        .workspace .admin-content { max-width: none; margin: 0; flex: 1 1 64%; min-width: 0; }

        @media (max-width: 1000px) {
            .workspace, .workspace.dark-left, .workspace.dark-right { flex-direction: column; }
            .panel-dark { flex: 0 0 auto; position: static; height: auto; padding: var(--sp-6) var(--sp-5); }
            .panel-dark p { max-width: none; }
        }
    </style>
</head>
<body>
    <div class="admin-ribbon">
        <span>VouchMorph Internal Systems &nbsp;·&nbsp; Administrator Access Only</span>
        <span><strong><?php echo safeHtml($roleName); ?></strong> &nbsp;·&nbsp; <?php echo date('Y-m-d H:i:s'); ?></span>
    </div>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH<span>Admin</span></div>
            <div class="role-badge"><?php echo safeHtml($roleInfo['label'] ?? $roleName); ?></div>
            <?php if ($isReadOnly): ?><span class="readonly-badge">READ ONLY</span><?php endif; ?>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo safeHtml($adminFullName ?: $adminUsername); ?></div>
                <div class="user-role"><?php echo safeHtml($roleName); ?></div>
            </div>
            <a href="admin_logout.php" class="logout-btn">Logout</a>
        </div>
    </header>

    <nav class="admin-nav">
        <?php if (canView('dashboard')): ?><a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">📊 DASHBOARD</a><?php endif; ?>
        <?php if (canView('client_lookup')): ?><a href="?view=client_lookup" class="nav-item support <?php echo $view === 'client_lookup' ? 'active' : ''; ?>">📞 CLIENT LOOKUP</a><?php endif; ?>
        <?php if (canView('alerts')): ?>
        <a href="?view=alerts" class="nav-item alerts <?php echo $view === 'alerts' ? 'active' : ''; ?>">
            🚨 ALERTS <?php if ($totalAlerts > 0): ?><span class="nav-badge"><?php echo $totalAlerts; ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (canView('live_transactions')): ?><a href="?view=live_transactions" class="nav-item <?php echo $view === 'live_transactions' ? 'active' : ''; ?>">🔴 LIVE TXNS</a><?php endif; ?>
        <?php if (canView('multi_destination')): ?><a href="?view=multi_destination" class="nav-item <?php echo $view === 'multi_destination' ? 'active' : ''; ?>">🎯 MULTI-DEST</a><?php endif; ?>
        <?php if (canView('recent_swaps')): ?><a href="?view=recent_swaps" class="nav-item <?php echo $view === 'recent_swaps' ? 'active' : ''; ?>">🔄 SWAPS</a><?php endif; ?>
        <?php if (canView('institution_health')): ?><a href="?view=institution_health" class="nav-item <?php echo $view === 'institution_health' ? 'active' : ''; ?>">🏦 INSTITUTIONS</a><?php endif; ?>
        <?php if (canView('settlements')): ?><a href="?view=settlements" class="nav-item <?php echo $view === 'settlements' ? 'active' : ''; ?>">📤 SETTLEMENTS</a><?php endif; ?>
        <?php if (canView('regulatory')): ?><a href="?view=regulatory" class="nav-item regulator <?php echo $view === 'regulatory' ? 'active' : ''; ?>">🏛️ REGULATORY</a><?php endif; ?>
        <?php if (canView('audit')): ?><a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">📝 AUDIT</a><?php endif; ?>
        <?php if (canView('fee_breakdown') && hasFinancialAccess()): ?><a href="?view=fee_breakdown" class="nav-item finance <?php echo $view === 'fee_breakdown' ? 'active' : ''; ?>">📊 FEES</a><?php endif; ?>
        <?php if (canView('invoices')): ?><a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">💰 INVOICES</a><?php endif; ?>
        <?php if (canView('all_tables') && $isSuperAdmin): ?><a href="?view=all_tables" class="nav-item <?php echo $view === 'all_tables' ? 'active' : ''; ?>">📋 TABLES</a><?php endif; ?>
    </nav>

    <div class="workspace dark-<?php echo safeHtml($currentMeta['side']); ?>">
        <div class="panel-dark">
            <div class="eyebrow"><?php echo safeHtml($currentMeta['eyebrow']); ?></div>
            <p><?php echo safeHtml($currentMeta['blurb']); ?></p>
            <div class="mark"></div>
        </div>

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
        <div class="card" style="border-color:#A31E17;">
            <div class="card-header">
                <span class="card-title" style="color:#A31E17;">🚨 <?php echo $totalAlerts; ?> item<?php echo $totalAlerts === 1 ? '' : 's'; ?> need attention</span>
                <a href="?view=alerts" class="btn btn-danger btn-sm">VIEW ALERTS</a>
            </div>
            <div style="font-size:0.65rem; color:#4A4A4A;">
                <?php echo $alertCounts['stuck_holds']; ?> stuck holds · <?php echo $alertCounts['expired_identity_swaps']; ?> expired identity swaps ·
                <?php echo $alertCounts['stuck_cashouts']; ?> stuck cashouts · <?php echo $alertCounts['failed_destinations']; ?> failed destinations
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isCustomerSupport): ?>
        <div class="card" style="border-color:#0A0A0A;">
            <div class="card-header"><span class="card-title" style="color:#0A0A0A;">📞 Quick client lookup</span></div>
            <p style="font-size:0.75rem; margin-bottom:10px;">Search by the customer's phone number, national ID, or reference number.</p>
            <a href="?view=client_lookup" class="btn btn-support">GO TO CLIENT LOOKUP</a>
        </div>
        <?php else: ?>
        <div class="metrics-grid">
            <div class="metric-card"><div class="metric-label">Total Swaps</div><div class="metric-value"><?php echo number_format($metrics['total_swaps'] ?? 0); ?></div></div>
            <div class="metric-card"><div class="metric-label">Total Users</div><div class="metric-value"><?php echo number_format($metrics['total_users'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color: #A31E17;"><div class="metric-label">Pending Settlements</div><div class="metric-value"><?php echo number_format($metrics['pending_settlements'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color: #0A0A0A;"><div class="metric-label">Total Fees</div><div class="metric-value"><?php echo number_format($metrics['total_fees'] ?? 0, 2); ?></div></div>
            <div class="metric-card"><div class="metric-label">24h Swaps</div><div class="metric-value"><?php echo number_format($metrics['recent_swaps_24h'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color: #0A0A0A;"><div class="metric-label">Multi-Destination</div><div class="metric-value"><?php echo number_format($metrics['multi_destination_count'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color: #0A0A0A;"><div class="metric-label">Multi-Source</div><div class="metric-value"><?php echo number_format($metrics['multi_source_count'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color: #A31E17;"><div class="metric-label">Pending Identity</div><div class="metric-value"><?php echo number_format($metrics['identity_swaps_pending'] ?? 0); ?></div></div>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">⚡ Quick Actions</span></div>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <?php if (canView('invoices')): ?><a href="?view=invoices" class="btn btn-success">💰 View Invoices</a><?php endif; ?>
                <?php if (canView('all_tables') && $isSuperAdmin): ?><a href="?view=all_tables" class="btn">📋 View All Tables</a><?php endif; ?>
                <?php if (canView('alerts')): ?><a href="?view=alerts" class="btn btn-danger">🚨 View Alerts</a><?php endif; ?>
                <?php if (canView('institution_health')): ?><a href="?view=institution_health" class="btn">🏦 Institution Health</a><?php endif; ?>
                <?php if (canView('client_lookup')): ?><a href="?view=client_lookup" class="btn btn-support">📞 Client Lookup</a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- CLIENT LOOKUP VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'client_lookup' && canView('client_lookup')): ?>
        <div class="content-header">
            <h1>📞 CLIENT LOOKUP</h1>
            <div class="timestamp">Search by the customer's phone number, national ID, or any reference they give you</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#0A0A0A;">← Back</a>
        </div>

        <form method="get" class="search-box" style="margin-bottom:20px;">
            <input type="hidden" name="view" value="client_lookup">
            <input type="text" name="lookup" placeholder="Phone number, national ID, or reference..." value="<?php echo safeHtml($lookup); ?>" autofocus>
            <button type="submit" class="btn btn-support">SEARCH</button>
        </form>

        <?php if ($lookup === ''): ?>
        <div class="card"><div class="empty-state"><div class="icon">📞</div><p>Enter what the customer gave you - a phone number, national ID, or a reference code from a message they received.</p></div></div>
        <?php elseif (empty($lookupResults)): ?>
        <div class="card"><div class="empty-state"><div class="icon">🔍</div><p>No matches found for "<?php echo safeHtml($lookup); ?>". Double-check the number, or ask the customer for the reference from their confirmation SMS.</p></div></div>
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
            <div style="font-size:0.75rem; display:grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap:8px;">
                <div><strong>Amount:</strong> <?php echo number_format((float)$r['amount'], 2); ?> <?php echo safeHtml($r['currency']); ?></div>
                <div><strong>Route:</strong> <?php echo safeHtml($r['institution']); ?></div>
                <?php if (!empty($r['identity'])): ?><div><strong>Identity:</strong> <?php echo safeHtml($r['identity']); ?></div><?php endif; ?>
                <div><strong>Date:</strong> <?php echo safeHtml(date('Y-m-d H:i', strtotime($r['created_at'] ?? 'now'))); ?></div>
            </div>
            <div class="lookup-next-action">👉 <?php echo safeHtml($r['next_action']); ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- ALERTS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'alerts' && canView('alerts')): ?>
        <div class="content-header">
            <h1>🚨 ALERTS &amp; EXCEPTIONS</h1>
            <div class="timestamp">Things that need a human to look at them, not just raw rows</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#0A0A0A;">← Back</a>
        </div>
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
            <div class="metric-card" style="border-color:#A31E17;"><div class="metric-label">Stuck Holds (&gt;24h)</div><div class="metric-value" style="color:#A31E17;"><?php echo number_format($alertCounts['stuck_holds']); ?></div></div>
            <div class="metric-card" style="border-color:#A31E17;"><div class="metric-label">Expired Identity Swaps</div><div class="metric-value" style="color:#A31E17;"><?php echo number_format($alertCounts['expired_identity_swaps']); ?></div></div>
            <div class="metric-card" style="border-color:#A31E17;"><div class="metric-label">Stuck Cashouts</div><div class="metric-value" style="color:#A31E17;"><?php echo number_format($alertCounts['stuck_cashouts']); ?></div></div>
            <div class="metric-card" style="border-color:#A31E17;"><div class="metric-label">Failed Destinations</div><div class="metric-value" style="color:#A31E17;"><?php echo number_format($alertCounts['failed_destinations']); ?></div></div>
        </div>
        <?php if ($totalAlerts === 0): ?>
        <div class="card"><div class="empty-state"><div class="icon">✅</div><p>Nothing needs attention right now.</p></div></div>
        <?php endif; ?>
        <?php if (!empty($alerts['stuck_holds'])): ?>
        <div class="card"><div class="card-header"><span class="card-title" style="color:#A31E17;">🔒 Stuck Holds (non-terminal &gt;24h)</span><span class="card-badge danger"><?php echo count($alerts['stuck_holds']); ?></span></div>
        <div class="table-responsive"><table><thead><tr><th>Hold Ref</th><th>Swap Ref</th><th>Institution</th><th>Asset</th><th>Amount</th><th>Status</th><th>Age</th></tr></thead><tbody>
        <?php foreach ($alerts['stuck_holds'] as $h): ?>
        <tr><td><?php echo safeHtml(substr($h['hold_reference'] ?? '', 0, 20)); ?></td><td><?php echo safeHtml(substr($h['swap_reference'] ?? '', 0, 20)); ?></td><td><?php echo safeHtml($h['institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($h['asset_type'] ?? ''); ?></td><td><?php echo number_format((float)($h['amount'] ?? 0), 2); ?> <?php echo safeHtml($h['currency'] ?? 'BWP'); ?></td><td><span class="status status-warning"><?php echo safeHtml($h['status'] ?? ''); ?></span></td><td><?php echo round((time() - strtotime($h['created_at'] ?? 'now')) / 3600, 1); ?>h</td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
        <?php endif; ?>
        <?php if (!empty($alerts['expired_identity_swaps'])): ?>
        <div class="card"><div class="card-header"><span class="card-title" style="color:#A31E17;">🪪 Expired Identity Swaps (not yet cancelled)</span><span class="card-badge warning"><?php echo count($alerts['expired_identity_swaps']); ?></span></div>
        <div class="table-responsive"><table><thead><tr><th>Swap Ref</th><th>Institution</th><th>Identity</th><th>Amount</th><th>Expired At</th></tr></thead><tbody>
        <?php foreach ($alerts['expired_identity_swaps'] as $s): ?>
        <tr><td><?php echo safeHtml(substr($s['swap_reference'] ?? '', 0, 20)); ?></td><td><?php echo safeHtml($s['source_institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($s['identity_type'] ?? ''); ?>: <?php echo safeHtml($s['identity_value'] ?? ''); ?></td><td><?php echo number_format((float)($s['amount'] ?? 0), 2); ?> <?php echo safeHtml($s['currency'] ?? 'BWP'); ?></td><td><?php echo safeHtml($s['hold_expires_at'] ?? ''); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
        <?php endif; ?>
        <?php if (!empty($alerts['stuck_cashouts'])): ?>
        <div class="card"><div class="card-header"><span class="card-title" style="color:#A31E17;">💵 Expired, Unclaimed Cashouts</span><span class="card-badge warning"><?php echo count($alerts['stuck_cashouts']); ?></span></div>
        <div class="table-responsive"><table><thead><tr><th>Swap Ref</th><th>Source</th><th>Provider</th><th>Phone</th><th>Amount</th><th>Expired</th></tr></thead><tbody>
        <?php foreach ($alerts['stuck_cashouts'] as $c): ?>
        <tr><td><?php echo safeHtml(substr($c['swap_reference'] ?? '', 0, 20)); ?></td><td><?php echo safeHtml($c['source_institution'] ?? 'N/A'); ?></td><td><?php echo safeHtml($c['cashout_provider'] ?? 'N/A'); ?></td><td><?php echo safeHtml($c['client_phone'] ?? 'N/A'); ?></td><td><?php echo number_format((float)($c['amount'] ?? 0), 2); ?> <?php echo safeHtml($c['currency'] ?? 'BWP'); ?></td><td><?php echo safeHtml($c['code_expiry'] ?? ''); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
        <?php endif; ?>
        <?php if (!empty($alerts['failed_destinations'])): ?>
        <div class="card"><div class="card-header"><span class="card-title" style="color:#A31E17;">❌ Failed Destinations</span><span class="card-badge danger"><?php echo count($alerts['failed_destinations']); ?></span></div>
        <div class="table-responsive"><table><thead><tr><th>Parent Ref</th><th>Source</th><th>Type</th><th>Target</th><th>Amount</th><th>Error</th><th>When</th></tr></thead><tbody>
        <?php foreach ($alerts['failed_destinations'] as $f): ?>
        <tr><td><?php echo safeHtml(substr($f['reference'] ?? '', 0, 20)); ?></td><td><?php echo safeHtml($f['source_institution'] ?? 'N/A'); ?></td><td><span class="status <?php echo $f['type'] === 'identity' ? 'status-identity' : 'status-info'; ?>"><?php echo safeHtml(strtoupper($f['type'])); ?></span></td><td><?php echo safeHtml($f['identity_value'] ?? $f['destination_institution'] ?? 'N/A'); ?></td><td><?php echo number_format((float)($f['amount'] ?? 0), 2); ?></td><td style="color:#A31E17; max-width:280px;"><?php echo safeHtml($f['error']); ?></td><td><?php echo safeHtml(date('Y-m-d H:i', strtotime($f['created_at'] ?? 'now'))); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- INSTITUTION HEALTH VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'institution_health' && canView('institution_health')): ?>
        <div class="content-header"><h1>🏦 INSTITUTION HEALTH</h1><div class="timestamp">Volume, success rate, and average time-to-debit per institution</div><a href="?view=dashboard" style="font-size:0.7rem; color:#0A0A0A;">← Back</a></div>
        <?php if (empty($institutionHealth)): ?>
        <div class="card"><div class="empty-state"><div class="icon">📭</div><p>No institution data yet</p></div></div>
        <?php else: ?>
        <?php foreach ($institutionHealth as $inst): $rate = (float)$inst['success_rate']; $barClass = $rate >= 90 ? '' : ($rate >= 70 ? 'warn' : 'bad'); ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?php echo safeHtml($inst['institution']); ?></span><span class="card-badge <?php echo $rate >= 90 ? 'success' : ($rate >= 70 ? 'warning' : 'danger'); ?>"><?php echo $rate; ?>% SUCCESS</span></div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:12px; font-size:0.7rem;">
                <div><strong>Total Txns:</strong> <?php echo number_format($inst['total']); ?></div>
                <div style="color:#0A0A0A;"><strong>Successful:</strong> <?php echo number_format($inst['successful']); ?></div>
                <div style="color:#A31E17;"><strong>Pending:</strong> <?php echo number_format($inst['pending']); ?></div>
                <div style="color:#A31E17;"><strong>Failed:</strong> <?php echo number_format($inst['failed']); ?></div>
                <div><strong>Volume:</strong> <?php echo number_format((float)$inst['volume'], 2); ?></div>
                <div><strong>Avg time-to-debit:</strong> <?php if ($inst['avg_latency_seconds'] !== null) { $secs = $inst['avg_latency_seconds']; echo $secs < 60 ? round($secs, 1) . 's' : round($secs / 60, 1) . 'm'; echo ' (n=' . $inst['debited_sample_size'] . ')'; } else { echo 'n/a'; } ?></div>
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
        <div class="content-header"><h1>🏛️ REGULATORY OVERSIGHT</h1><div class="timestamp">Net positions between institutions and pending settlements</div><a href="?view=dashboard" style="font-size:0.7rem; color:#0A0A0A;">← Back</a></div>

        <div class="card">
            <div class="card-header"><span class="card-title">Institution Success Rates (Summary)</span></div>
            <?php if (empty($institutionHealth)): ?>
            <div class="empty-state">No data yet</div>
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
            <div class="empty-state">No net positions recorded</div>
            <?php else: ?>
            <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($netPositions[0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach ($netPositions as $row): ?>
            <tr><?php foreach ($row as $val): ?><td><?php echo safeHtml(is_array($val) ? json_encode($val) : $val); ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">Pending Settlements</span><span class="card-badge warning"><?php echo count($pendingSettlements); ?> RECORDS</span></div>
            <?php if (empty($pendingSettlements)): ?>
            <div class="empty-state">No pending settlements</div>
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
        <div class="content-header"><h1>📝 AUDIT LOG</h1><div class="timestamp">Most recent 200 entries, ordered by internal ID</div><a href="?view=dashboard" style="font-size:0.7rem; color:#0A0A0A;">← Back</a></div>
        <div class="card">
            <div class="card-header"><span class="card-title">Audit Trail</span><span class="card-badge"><?php echo count($auditRows); ?> RECORDS</span></div>
            <?php if (empty($auditRows)): ?>
            <div class="empty-state"><div class="icon">📭</div><p>No audit records found</p></div>
            <?php else: ?>
            <div class="table-responsive"><table><thead><tr><?php foreach (array_keys($auditRows[0]) as $col): ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach ($auditRows as $row): ?>
            <tr><?php foreach ($row as $val): $s = is_array($val) ? json_encode($val) : (string)$val; ?><td title="<?php echo safeHtml($s); ?>"><?php echo safeHtml(strlen($s) > 60 ? substr($s, 0, 60) . '…' : $s); ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- INVOICES VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'invoices' && canView('invoices')): ?>
        <div class="content-header"><h1>💰 INVOICES</h1><div class="timestamp">Fee invoices generated through settlement</div><a href="?view=dashboard" style="font-size:0.7rem; color:#0A0A0A;">← Back</a></div>
        <div class="card">
            <div class="card-header"><span class="card-title">Fee Invoice Messages</span><span class="card-badge success"><?php echo count($invoiceMessages); ?> RECORDS</span></div>
            <?php if (empty($invoiceMessages)): ?>
            <div class="empty-state"><div class="icon">📭</div><p>No invoice messages found.</p></div>
            <?php else: ?>
            <div class="table-responsive"><table><thead><tr><th>Message ID</th><th>Swap Reference</th><th>Source</th><th>Destination</th><th>Created</th></tr></thead><tbody>
            <?php foreach ($invoiceMessages as $inv): ?>
            <tr>
                <td><?php echo safeHtml(substr($inv['message_id'] ?? '', 0, 16)); ?></td>
                <td><?php echo safeHtml(substr($inv['swap_reference'] ?? 'N/A', 0, 20)); ?></td>
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
            <h1><span class="live-indicator"></span> 🔴 LIVE TRANSACTIONS</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?><span style="margin-left:16px; font-size:0.65rem; color:#4A4A4A;"><?php echo count($liveTransactions); ?> transactions</span><button class="auto-refresh-toggle active" onclick="toggleAutoRefresh()" id="refreshToggle">🔄 AUTO-REFRESH ON</button></div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#0A0A0A;">← Back</a>
        </div>
        <form method="get" class="search-box" style="margin-bottom:16px;">
            <input type="hidden" name="view" value="live_transactions">
            <input type="text" name="search" placeholder="Search reference, institution, status..." value="<?php echo safeHtml($search); ?>">
            <button type="submit" class="btn btn-primary btn-sm">SEARCH</button>
            <?php if ($search !== ''): ?><a href="?view=live_transactions" class="btn btn-sm">CLEAR</a><?php endif; ?>
        </form>
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));">
            <div class="metric-card"><div class="metric-label">Total (24h)</div><div class="metric-value"><?php echo number_format($liveStats['total'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color:#0A0A0A;"><div class="metric-label">✅ Completed</div><div class="metric-value" style="color:#0A0A0A;"><?php echo number_format($liveStats['completed'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color:#A31E17;"><div class="metric-label">⏳ Pending</div><div class="metric-value" style="color:#A31E17;"><?php echo number_format($liveStats['pending'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color:#A31E17;"><div class="metric-label">❌ Failed</div><div class="metric-value" style="color:#A31E17;"><?php echo number_format($liveStats['failed'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color:#0A0A0A;"><div class="metric-label">💰 Volume</div><div class="metric-value"><?php echo number_format($liveStats['total_amount'] ?? 0, 2); ?></div></div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">📋 Live Transaction Feed</span><span class="card-badge" id="liveCount"><?php echo count($liveTransactions); ?> RECORDS</span></div>
            <div class="table-responsive"><table><thead><tr><th>#</th><th>Reference</th><th>Type</th><th>Amount</th><th>Currency</th><th>Status</th><th>Source</th><th>Destination</th><th>Fee</th><th>Created</th></tr></thead><tbody id="liveTransactionsBody">
            <?php if (empty($liveTransactions)): ?>
            <tr><td colspan="10" class="empty-state">No live transactions found<?php echo $search !== '' ? ' for "' . safeHtml($search) . '"' : ''; ?></td></tr>
            <?php else: foreach ($liveTransactions as $index => $row): $type = $row['swap_type'] ?? 'STANDARD'; $typeClass = match($type) { 'MULTI_DESTINATION' => 'status-info', 'MULTI_SOURCE' => 'status-processing', 'IDENTITY' => 'status-identity', 'CASHOUT' => 'status-warning', default => 'status-info' }; $status = strtolower($row['status'] ?? 'pending'); $class = match(true) { str_contains($status, 'complet') || str_contains($status, 'success') || str_contains($status, 'debited') => 'success', str_contains($status, 'pending') || str_contains($status, 'sent') || str_contains($status, 'processing') => 'pending', str_contains($status, 'fail') || str_contains($status, 'error') || str_contains($status, 'expired') => 'failed', default => 'info' }; ?>
            <tr>
                <td><?php echo $index + 1; ?></td>
                <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['reference'] ?? 'N/A', 0, 12)); ?></td>
                <td><span class="status <?php echo $typeClass; ?>"><?php echo safeHtml($type); ?></span></td>
                <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                <td><?php echo safeHtml($row['currency'] ?? 'BWP'); ?></td>
                <td><span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span></td>
                <td><?php echo safeHtml($row['source_institution'] ?? 'N/A'); ?></td>
                <td><?php echo safeHtml($row['destination_institution'] ?? 'N/A'); ?></td>
                <td><?php echo number_format((float)($row['fee_amount'] ?? 0), 2); ?></td>
                <td><?php echo date('Y-m-d H:i:s', strtotime($row['created_at'] ?? 'now')); ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody></table></div>
        </div>
        <script>
            let autoRefresh = true; let refreshInterval = null;
            function toggleAutoRefresh() { autoRefresh = !autoRefresh; const toggle = document.getElementById('refreshToggle'); toggle.textContent = autoRefresh ? '🔄 AUTO-REFRESH ON' : '🔄 AUTO-REFRESH OFF'; toggle.classList.toggle('active'); if (autoRefresh) { startAutoRefresh(); } else { clearInterval(refreshInterval); } }
            function startAutoRefresh() { clearInterval(refreshInterval); refreshInterval = setInterval(function() { fetch(window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'ajax=1').then(r => r.json()).then(data => { if (data.transactions) { const tbody = document.getElementById('liveTransactionsBody'); let html = ''; data.transactions.forEach((row, i) => { const status = (row.status || 'pending').toLowerCase(); let cls = 'info'; if (status.includes('complet') || status.includes('success') || status.includes('debited')) cls = 'success'; else if (status.includes('pending') || status.includes('processing')) cls = 'pending'; else if (status.includes('fail') || status.includes('error')) cls = 'failed'; const type = row.swap_type || 'STANDARD'; let typeClass = 'status-info'; if (type === 'MULTI_DESTINATION') typeClass = 'status-info'; else if (type === 'MULTI_SOURCE') typeClass = 'status-processing'; else if (type === 'IDENTITY') typeClass = 'status-identity'; else if (type === 'CASHOUT') typeClass = 'status-warning'; html += `<tr><td>${i+1}</td><td>${(row.swap_reference || row.reference || 'N/A').substring(0,12)}</td><td><span class="status ${typeClass}">${type}</span></td><td><strong>${Number(row.amount || 0).toFixed(2)}</strong></td><td>${row.currency || 'BWP'}</td><td><span class="status status-${cls}">${row.status || 'pending'}</span></td><td>${row.source_institution || 'N/A'}</td><td>${row.destination_institution || 'N/A'}</td><td>${Number(row.fee_amount || 0).toFixed(2)}</td><td>${new Date(row.created_at).toLocaleString()}</td></tr>`; }); tbody.innerHTML = html; document.getElementById('liveCount').textContent = data.transactions.length; } }).catch(e => console.error('Refresh failed:', e)); }, 5000); }
            startAutoRefresh();
        </script>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- RECENT SWAPS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'recent_swaps' && canView('recent_swaps')): ?>
        <div class="content-header"><h1>🔄 RECENT SWAPS</h1><div class="timestamp">All swap transactions - complete history</div><a href="?view=dashboard" style="font-size:0.7rem; color:#0A0A0A;">← Back</a></div>
        <form method="get" class="search-box" style="margin-bottom:16px;">
            <input type="hidden" name="view" value="recent_swaps">
            <input type="text" name="search" placeholder="Search reference, institution, status..." value="<?php echo safeHtml($search); ?>">
            <button type="submit" class="btn btn-primary btn-sm">SEARCH</button>
            <?php if ($search !== ''): ?><a href="?view=recent_swaps" class="btn btn-sm">CLEAR</a><?php endif; ?>
        </form>
        <div class="card">
            <div class="card-header"><span class="card-title">All Swaps</span><span class="card-badge"><?php echo count($recentSwaps); ?> TOTAL RECORDS</span></div>
            <div class="table-responsive"><table><thead><tr><th>Reference</th><th>Type</th><th>Amount</th><th>Currency</th><th>Status</th><th>Source</th><th>Destination</th><th>Fee</th><th>Created</th></tr></thead><tbody>
            <?php if (empty($recentSwaps)): ?>
            <tr><td colspan="9" class="empty-state">No swaps found<?php echo $search !== '' ? ' for "' . safeHtml($search) . '"' : ''; ?></td></tr>
            <?php else: foreach ($recentSwaps as $row): $type = $row['swap_type'] ?? 'STANDARD'; $typeClass = match($type) { 'MULTI_DESTINATION' => 'status-info', 'MULTI_SOURCE' => 'status-processing', 'IDENTITY' => 'status-identity', 'CASHOUT' => 'status-warning', default => 'status-info' }; $status = strtolower($row['status'] ?? 'pending'); $class = match(true) { str_contains($status, 'complet') || str_contains($status, 'success') => 'success', str_contains($status, 'pending') || str_contains($status, 'processing') || str_contains($status, 'confirmation') => 'pending', str_contains($status, 'fail') || str_contains($status, 'error') || str_contains($status, 'expired') => 'failed', default => 'info' }; ?>
            <tr>
                <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['reference'] ?? 'N/A', 0, 16)); ?></td>
                <td><span class="status <?php echo $typeClass; ?>"><?php echo safeHtml($type); ?></span></td>
                <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                <td><?php echo safeHtml($row['currency'] ?? 'BWP'); ?></td>
                <td><span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span></td>
                <td><?php echo safeHtml($row['source_institution'] ?? 'N/A'); ?></td>
                <td><?php echo safeHtml($row['destination_institution'] ?? 'N/A'); ?></td>
                <td><?php echo number_format((float)($row['fee_amount'] ?? 0), 2); ?></td>
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
        <div class="content-header"><h1>🎯 MULTI-DESTINATION SWAPS</h1><div class="timestamp">All multi-destination swaps - complete history</div><a href="?view=dashboard" style="font-size:0.7rem; color:#0A0A0A;">← Back</a></div>
        <?php if (empty($multiDestinationSwaps)): ?>
        <div class="card"><div class="empty-state"><div class="icon">📭</div><p>No multi-destination swaps found</p></div></div>
        <?php else: foreach ($multiDestinationSwaps as $swap): $destinations = json_decode($swap['destinations_payload'] ?? '[]', true); $results = json_decode($swap['results_payload'] ?? '[]', true); ?>
        <div class="card" style="border-left: 3px solid <?php echo $swap['status'] === 'completed' ? '#0A0A0A' : '#A31E17'; ?>;">
            <div class="card-header">
                <span class="card-title"><?php echo safeHtml($swap['reference']); ?> <span style="font-size:0.55rem; font-weight:400; color:#4A4A4A;"><?php echo date('Y-m-d H:i', strtotime($swap['created_at'])); ?></span></span>
                <span class="card-badge <?php echo $swap['status'] === 'completed' ? 'success' : ($swap['status'] === 'partial' ? 'warning' : 'danger'); ?>"><?php echo strtoupper($swap['status'] ?? 'UNKNOWN'); ?></span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:8px; margin-bottom:12px; font-size:0.65rem; background:#F2F2F0; padding:10px; border-radius:4px;">
                <div><strong>Source:</strong> <?php echo safeHtml($swap['source_institution']); ?></div>
                <div><strong>Total:</strong> <?php echo number_format((float)($swap['total_amount'] ?? 0), 2); ?> BWP</div>
                <div><strong>Fees:</strong> <?php echo number_format((float)($swap['total_fees'] ?? 0), 2); ?> BWP</div>
                <div><strong>Delivered:</strong> <?php echo number_format((float)($swap['total_delivered'] ?? 0), 2); ?> BWP</div>
                <div><strong>✅ Success:</strong> <?php echo $swap['successful_count'] ?? 0; ?></div>
                <div><strong>❌ Failed:</strong> <?php echo $swap['failed_count'] ?? 0; ?></div>
                <div><strong>📦 Destinations:</strong> <?php echo $swap['total_destinations']; ?></div>
            </div>
            <?php if (!empty($destinations)): ?>
            <div class="table-responsive"><table><thead><tr><th>#</th><th>Type</th><th>Institution</th><th>Identifier</th><th>Amount</th><th>Fee</th><th>Net</th><th>Status</th><th>Hold Ref</th></tr></thead><tbody>
            <?php foreach ($destinations as $idx => $dest): $result = $results[$idx] ?? []; $status = $result['status'] ?? 'pending'; $error = $result['error'] ?? null; $isIdentity = isset($dest['identity_type']) || isset($dest['identity_value']); $isCashout = isset($dest['delivery_method']) && $dest['delivery_method'] === 'ATM'; $fee = (float)($result['fee'] ?? 0); $net = (float)($result['net_amount'] ?? $dest['amount'] ?? 0); ?>
            <tr>
                <td><?php echo $idx + 1; ?></td>
                <td><?php if ($isIdentity): ?><span class="status status-identity">IDENTITY</span><?php elseif ($isCashout): ?><span class="status status-warning">CASHOUT</span><?php else: ?><span class="status status-info">DEPOSIT</span><?php endif; ?></td>
                <td><?php echo safeHtml($dest['to_institution'] ?? $dest['destination_institution'] ?? ($isIdentity ? 'IDENTITY' : 'N/A')); ?></td>
                <td><?php if ($isIdentity) { echo safeHtml($dest['identity_type'] ?? 'national_id') . ': ' . safeHtml($dest['identity_value'] ?? 'N/A'); } elseif ($isCashout) { echo safeHtml($dest['beneficiary_phone'] ?? 'N/A'); } else { echo safeHtml($dest['destination_identifier'] ?? 'N/A'); } ?></td>
                <td><strong><?php echo number_format((float)($dest['amount'] ?? 0), 2); ?></strong></td>
                <td style="color:#A31E17;"><?php echo number_format($fee, 2); ?></td>
                <td style="color:#0A0A0A;"><?php echo number_format($net, 2); ?></td>
                <td>
                    <?php $statusClass = match($status) { 'success', 'completed' => 'success', 'failed' => 'failed', 'pending', 'pending_identity_confirmation' => 'pending', default => 'info' }; $statusLabel = $status === 'pending_identity_confirmation' ? 'PENDING_ID' : ($status ?: 'PENDING'); ?>
                    <span class="status status-<?php echo $statusClass; ?>"><?php echo safeHtml(strtoupper($statusLabel)); ?></span>
                    <?php if ($error): ?><span style="color:#A31E17; font-size:0.55rem; display:block;" title="<?php echo safeHtml($error); ?>">⚠️ <?php echo safeHtml(substr($error, 0, 30)); ?></span><?php endif; ?>
                </td>
                <td><?php echo safeHtml(substr($result['hold_reference'] ?? 'N/A', 0, 10)); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <details style="margin-top:12px;"><summary style="cursor:pointer; font-size:0.6rem; color:#4A4A4A;">📄 Raw JSON</summary><pre style="background:#0A0A0A; color:#FFFFFF; padding:12px; font-size:0.55rem; overflow-x:auto; max-height:300px; overflow-y:auto; margin-top:8px;"><?php echo safeHtml(json_encode(['summary' => ['reference' => $swap['reference'], 'source_institution' => $swap['source_institution'], 'status' => $swap['status'], 'total_amount' => $swap['total_amount'], 'total_fees' => $swap['total_fees']], 'destinations' => $destinations, 'results' => $results], JSON_PRETTY_PRINT)); ?></pre></details>
        </div>
        <?php endforeach; endif; ?>
        <?php endif; ?>

        <!-- ============================================================ --> 
        <!-- ACCESS DENIED -->
        <!-- ============================================================ -->
        <?php
        $knownViews = ['dashboard', 'all_tables', 'multi_destination', 'live_transactions', 'recent_swaps', 'alerts', 'institution_health', 'client_lookup', 'regulatory', 'audit', 'invoices'];
        if (!canView($view) && !in_array($view, $knownViews)):
        ?>
        <div class="card"><div class="empty-state"><div class="icon">🚫</div><h2>Access Denied</h2><p>You do not have permission to view this page.</p><a href="?view=dashboard" class="btn" style="margin-top:16px;">Return to Dashboard</a></div></div>
        <?php endif; ?>
    </main>
    </div>

    <footer class="admin-footer">
        <p>VOUCHMORPH · <?php echo safeHtml($roleName); ?> · <?php echo date('Y'); ?></p>
        <p style="margin-top:4px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>
</body>
</html>
