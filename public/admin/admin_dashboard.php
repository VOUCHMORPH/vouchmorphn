<?php
/**
 * admin_dashboard.php - VouchMorph Enhanced Admin Dashboard
 * Features: Role-based access, Transaction Search, Full Tracking, Reports, Debug Mode
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

// Role definitions with permissions
$roleDefinitions = [
    999 => [
        'name' => 'Super Admin',
        'permissions' => ['all'],
        'level' => 100
    ],
    3 => [
        'name' => 'Regulator',
        'permissions' => ['view_dashboard', 'view_reports', 'audit_logs', 'compliance_checks', 'search_transactions'],
        'level' => 80
    ],
    4 => [
        'name' => 'Compliance Officer',
        'permissions' => ['view_dashboard', 'view_reports', 'review_transactions', 'kyc_verification', 'search_transactions'],
        'level' => 70
    ],
    5 => [
        'name' => 'Auditor',
        'permissions' => ['view_dashboard', 'view_reports', 'audit_logs', 'read_only', 'search_transactions'],
        'level' => 60
    ],
    6 => [
        'name' => 'Support',
        'permissions' => ['view_dashboard', 'search_transactions'],
        'level' => 50
    ]
];

$roleName = $roleDefinitions[$adminRoleId]['name'] ?? 'Administrator';
$userPermissions = $roleDefinitions[$adminRoleId]['permissions'] ?? [];

// Check permission helper
function hasPermission($permission) {
    global $userPermissions;
    return in_array('all', $userPermissions) || in_array($permission, $userPermissions);
}

// Database connection
try {
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->query("SELECT 1");
    error_log("[ADMIN DASHBOARD] Database connected successfully");
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] DB Error: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// Get view and parameters
$view = $_GET['view'] ?? 'dashboard';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$search = $_GET['search'] ?? '';
$transactionId = $_GET['id'] ?? null;
$reportType = $_GET['report'] ?? '';

// ============================================================
// TABLE SCHEMA CHECK (for debug mode)
// ============================================================
$tables = [
    'audit_logs', 'hold_transactions', 'swap_requests', 'users', 'admins',
    'cashout_authorizations', 'identity_swap_holds', 'fee_invoices',
    'settlement_queue', 'settlement_outbox', 'swap_ledgers',
    'swap_fee_collections', 'net_positions', 'deposit_transactions',
    'swap_vouchers', 'cross_border_messages', 'admin_actions',
    'organization_audit_logs', 'regulatory_reports', 'participants'
];

$tableStatus = [];
$totalRecords = 0;

if ($debug) {
    foreach ($tables as $table) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table");
            $stmt->execute([':table' => $table]);
            $exists = (int)$stmt->fetchColumn() > 0;
            
            if ($exists) {
                $countStmt = $db->query("SELECT COUNT(*) FROM " . $table);
                $count = (int)$countStmt->fetchColumn();
                $totalRecords += $count;
                $tableStatus[$table] = ['exists' => true, 'count' => $count];
            } else {
                $tableStatus[$table] = ['exists' => false, 'count' => 0];
            }
        } catch (Throwable $e) {
            $tableStatus[$table] = ['exists' => false, 'count' => 0, 'error' => $e->getMessage()];
        }
    }
}

// ============================================================
// TRANSACTION SEARCH
// ============================================================
$searchResults = [];
$searchPerformed = false;

if (!empty($search) && hasPermission('search_transactions')) {
    $searchPerformed = true;
    try {
        $stmt = $db->prepare("
            SELECT 
                sr.swap_id,
                sr.swap_uuid,
                sr.user_id,
                sr.amount,
                sr.status,
                sr.created_at,
                sr.from_currency,
                sr.to_currency,
                sr.source_country,
                sr.destination_country,
                u.full_name as user_name,
                u.phone as user_phone,
                u.email as user_email,
                u.national_id,
                ht.status as hold_status,
                ht.hold_reference,
                sq.status as settlement_status,
                ca.status as cashout_status,
                fi.status as invoice_status,
                idh.status as identity_status
            FROM swap_requests sr
            LEFT JOIN users u ON sr.user_id = u.user_id
            LEFT JOIN hold_transactions ht ON sr.swap_id = ht.swap_reference::int
            LEFT JOIN settlement_queue sq ON sr.swap_id = sq.reference::int
            LEFT JOIN cashout_authorizations ca ON sr.swap_id = ca.swap_reference::int
            LEFT JOIN fee_invoices fi ON sr.swap_id = fi.swap_reference::int
            LEFT JOIN identity_swap_holds idh ON sr.swap_id = idh.swap_reference::int
            WHERE 
                sr.swap_id::text ILIKE :search
                OR sr.swap_uuid ILIKE :search
                OR u.full_name ILIKE :search
                OR u.phone ILIKE :search
                OR u.email ILIKE :search
                OR u.national_id ILIKE :search
                OR sr.status ILIKE :search
                OR sr.from_currency ILIKE :search
                OR sr.to_currency ILIKE :search
                OR sr.source_country ILIKE :search
                OR sr.destination_country ILIKE :search
            ORDER BY sr.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([':search' => '%' . $search . '%']);
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] Search error: " . $e->getMessage());
        $searchResults = [];
    }
}

// ============================================================
// TRANSACTION DETAIL (Full lifecycle)
// ============================================================
$transactionDetail = null;
$transactionTimeline = [];

if ($transactionId && hasPermission('review_transactions')) {
    try {
        // Main transaction
        $stmt = $db->prepare("
            SELECT 
                sr.*,
                u.full_name as user_name,
                u.email as user_email,
                u.phone as user_phone,
                u.national_id,
                u.kyc_verified,
                u.aml_score,
                u.verified as user_verified,
                u.date_of_birth,
                u.role_id as user_role_id,
                p.name as participant_name,
                p.type as participant_type
            FROM swap_requests sr
            LEFT JOIN users u ON sr.user_id = u.user_id
            LEFT JOIN participants p ON sr.user_id = p.system_user_id
            WHERE sr.swap_id = :id OR sr.swap_uuid = :uuid
        ");
        $stmt->execute([':id' => $transactionId, ':uuid' => $transactionId]);
        $transactionDetail = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($transactionDetail) {
            // Build timeline
            $timeline = [];
            
            // 1. Transaction Created
            $timeline[] = [
                'stage' => 'CREATED',
                'timestamp' => $transactionDetail['created_at'] ?? 'now',
                'description' => 'Transaction created',
                'details' => ['amount' => $transactionDetail['amount'], 'currency' => $transactionDetail['from_currency'] ?? 'BWP']
            ];
            
            // 2. Hold
            $stmt = $db->prepare("
                SELECT * FROM hold_transactions 
                WHERE swap_reference::text = :ref OR swap_reference::text = :id
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([':ref' => $transactionDetail['swap_uuid'] ?? '', ':id' => $transactionId]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($hold) {
                $timeline[] = [
                    'stage' => 'HOLD',
                    'timestamp' => $hold['created_at'] ?? 'now',
                    'description' => 'Hold placed on source funds',
                    'details' => [
                        'hold_reference' => $hold['hold_reference'],
                        'amount' => $hold['amount'],
                        'status' => $hold['status'],
                        'source_institution' => $hold['source_institution'] ?? 'N/A'
                    ]
                ];
            }
            
            // 3. Settlement
            $stmt = $db->prepare("
                SELECT * FROM settlement_queue 
                WHERE reference::text = :ref OR reference::text = :id
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([':ref' => $transactionDetail['swap_uuid'] ?? '', ':id' => $transactionId]);
            $settlement = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($settlement) {
                $timeline[] = [
                    'stage' => 'SETTLEMENT',
                    'timestamp' => $settlement['created_at'] ?? 'now',
                    'description' => 'Settlement processed',
                    'details' => [
                        'debtor' => $settlement['debtor'],
                        'creditor' => $settlement['creditor'],
                        'amount' => $settlement['amount'],
                        'status' => $settlement['status']
                    ]
                ];
            }
            
            // 4. Cashout
            $stmt = $db->prepare("
                SELECT * FROM cashout_authorizations 
                WHERE swap_reference::text = :ref OR swap_reference::text = :id
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([':ref' => $transactionDetail['swap_uuid'] ?? '', ':id' => $transactionId]);
            $cashout = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cashout) {
                $timeline[] = [
                    'stage' => 'CASHOUT',
                    'timestamp' => $cashout['created_at'] ?? 'now',
                    'description' => 'Cashout code generated',
                    'details' => [
                        'amount' => $cashout['amount'],
                        'point' => $cashout['cashout_point'],
                        'provider' => $cashout['cashout_provider'],
                        'status' => $cashout['status']
                    ]
                ];
            }
            
            // 5. Identity Hold (if applicable)
            $stmt = $db->prepare("
                SELECT * FROM identity_swap_holds 
                WHERE swap_reference::text = :ref OR swap_reference::text = :id
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([':ref' => $transactionDetail['swap_uuid'] ?? '', ':id' => $transactionId]);
            $identity = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($identity) {
                $timeline[] = [
                    'stage' => 'IDENTITY',
                    'timestamp' => $identity['created_at'] ?? 'now',
                    'description' => 'Identity verification hold',
                    'details' => [
                        'identity_type' => $identity['identity_type'],
                        'identity_value' => $identity['identity_value'],
                        'status' => $identity['status']
                    ]
                ];
            }
            
            // 6. Fee Invoice
            $stmt = $db->prepare("
                SELECT * FROM fee_invoices 
                WHERE swap_reference::text = :ref OR swap_reference::text = :id
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([':ref' => $transactionDetail['swap_uuid'] ?? '', ':id' => $transactionId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invoice) {
                $timeline[] = [
                    'stage' => 'BILLING',
                    'timestamp' => $invoice['created_at'] ?? 'now',
                    'description' => 'Fee invoice generated',
                    'details' => [
                        'fee_type' => $invoice['fee_type'],
                        'fee_amount' => $invoice['fee_amount'],
                        'total_amount' => $invoice['total_amount'],
                        'status' => $invoice['status']
                    ]
                ];
            }
            
            // 7. Settlement Outbox
            $stmt = $db->prepare("
                SELECT * FROM settlement_outbox 
                WHERE swap_reference::text = :ref OR swap_reference::text = :id
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([':ref' => $transactionDetail['swap_uuid'] ?? '', ':id' => $transactionId]);
            $outbox = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($outbox) {
                $timeline[] = [
                    'stage' => 'OUTBOX',
                    'timestamp' => $outbox['created_at'] ?? 'now',
                    'description' => 'Settlement message sent',
                    'details' => [
                        'message_type' => $outbox['message_type'],
                        'status' => $outbox['status'],
                        'sent_at' => $outbox['sent_at']
                    ]
                ];
            }
            
            // Sort by timestamp
            usort($timeline, function($a, $b) {
                return strtotime($a['timestamp']) - strtotime($b['timestamp']);
            });
            
            $transactionTimeline = $timeline;
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] Transaction detail error: " . $e->getMessage());
    }
}

// ============================================================
// METRICS
// ============================================================
$metrics = [];
try {
    // Total users
    $stmt = $db->query("SELECT COUNT(*) FROM users");
    $metrics['total_users'] = (int)$stmt->fetchColumn();
    
    // Total swaps
    $stmt = $db->query("SELECT COUNT(*) FROM swap_requests");
    $metrics['total_swaps'] = (int)$stmt->fetchColumn();
    
    // Completed swaps
    $stmt = $db->query("SELECT COUNT(*) FROM swap_requests WHERE status IN ('COMPLETED', 'success')");
    $metrics['completed_swaps'] = (int)$stmt->fetchColumn();
    
    // Pending swaps
    $stmt = $db->query("SELECT COUNT(*) FROM swap_requests WHERE status IN ('PENDING', 'pending')");
    $metrics['pending_swaps'] = (int)$stmt->fetchColumn();
    
    // Total volume
    $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) FROM swap_requests");
    $metrics['total_volume'] = (float)$stmt->fetchColumn();
    
    // Today's volume
    $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $metrics['today_volume'] = (float)$stmt->fetchColumn();
    
    // Active holds
    $stmt = $db->query("SELECT COUNT(*) FROM hold_transactions WHERE status IN ('ACTIVE', 'HELD')");
    $metrics['active_holds'] = (int)$stmt->fetchColumn();
    
    // Pending settlements
    $stmt = $db->query("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'");
    $metrics['pending_settlements'] = (int)$stmt->fetchColumn();
    
    // Total fee invoices
    $stmt = $db->query("SELECT COUNT(*) FROM fee_invoices");
    $metrics['total_invoices'] = (int)$stmt->fetchColumn();
    
    // Unpaid invoices
    $stmt = $db->query("SELECT COUNT(*) FROM fee_invoices WHERE status = 'SENT'");
    $metrics['unpaid_invoices'] = (int)$stmt->fetchColumn();
    
    // Total cashouts
    $stmt = $db->query("SELECT COUNT(*) FROM cashout_authorizations");
    $metrics['total_cashouts'] = (int)$stmt->fetchColumn();
    
    // Pending cashouts
    $stmt = $db->query("SELECT COUNT(*) FROM cashout_authorizations WHERE status = 'PENDING'");
    $metrics['pending_cashouts'] = (int)$stmt->fetchColumn();
    
    // Identity holds
    $stmt = $db->query("SELECT COUNT(*) FROM identity_swap_holds WHERE status = 'pending'");
    $metrics['pending_identity_holds'] = (int)$stmt->fetchColumn();
    
    // Total audit logs
    $stmt = $db->query("SELECT COUNT(*) FROM audit_logs");
    $metrics['total_audit_logs'] = (int)$stmt->fetchColumn();
    
    // Total admin actions
    $stmt = $db->query("SELECT COUNT(*) FROM admin_actions");
    $metrics['total_admin_actions'] = (int)$stmt->fetchColumn();
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = array_fill_keys([
        'total_users', 'total_swaps', 'completed_swaps', 'pending_swaps',
        'total_volume', 'today_volume', 'active_holds', 'pending_settlements',
        'total_invoices', 'unpaid_invoices', 'total_cashouts', 'pending_cashouts',
        'pending_identity_holds', 'total_audit_logs', 'total_admin_actions'
    ], 0);
}

// ============================================================
// RECENT TRANSACTIONS
// ============================================================
$recentTransactions = [];
try {
    $stmt = $db->query("
        SELECT 
            sr.swap_id,
            sr.swap_uuid,
            sr.user_id,
            sr.amount,
            sr.status,
            sr.created_at,
            sr.from_currency,
            sr.to_currency,
            sr.source_country,
            sr.destination_country,
            u.full_name as user_name,
            u.phone as user_phone,
            u.email as user_email,
            ht.status as hold_status,
            sq.status as settlement_status
        FROM swap_requests sr
        LEFT JOIN users u ON sr.user_id = u.user_id
        LEFT JOIN hold_transactions ht ON sr.swap_id = ht.swap_reference::int
        LEFT JOIN settlement_queue sq ON sr.swap_id = sq.reference::int
        ORDER BY sr.created_at DESC 
        LIMIT 30
    ");
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Recent transactions error: " . $e->getMessage());
}

// ============================================================
// AUDIT LOGS
// ============================================================
$recentAuditLogs = [];
try {
    $stmt = $db->query("
        SELECT 
            audit_id,
            audit_uuid,
            entity_type,
            entity_id,
            action,
            category,
            severity,
            performed_at,
            ip_address,
            performed_by_type,
            performed_by_id,
            endpoint,
            duration_ms
        FROM audit_logs 
        ORDER BY performed_at DESC 
        LIMIT 30
    ");
    $recentAuditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Audit logs error: " . $e->getMessage());
}

// ============================================================
// RECENT HOLDS
// ============================================================
$recentHolds = [];
try {
    $stmt = $db->query("
        SELECT 
            hold_id,
            hold_reference,
            swap_reference,
            participant_name,
            asset_type,
            amount,
            currency,
            status,
            source_institution,
            destination_institution,
            placed_at,
            created_at
        FROM hold_transactions 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $recentHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Holds error: " . $e->getMessage());
}

// ============================================================
// RECENT INVOICES
// ============================================================
$recentInvoices = [];
try {
    $stmt = $db->query("
        SELECT 
            invoice_uuid,
            swap_reference,
            source_institution,
            fee_type,
            fee_amount,
            currency,
            total_amount,
            status,
            created_at,
            paid_at
        FROM fee_invoices 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Invoices error: " . $e->getMessage());
}

// ============================================================
// REPORTS DATA
// ============================================================
$reportData = null;
$reportSummary = [];

if ($reportType && hasPermission('view_reports')) {
    try {
        switch ($reportType) {
            case 'daily':
                $stmt = $db->query("
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as total,
                        SUM(amount) as volume,
                        COUNT(CASE WHEN status IN ('COMPLETED', 'success') THEN 1 END) as completed,
                        COUNT(CASE WHEN status IN ('PENDING', 'pending') THEN 1 END) as pending,
                        COUNT(CASE WHEN status IN ('FAILED', 'failed') THEN 1 END) as failed
                    FROM swap_requests 
                    WHERE created_at >= NOW() - INTERVAL '30 days'
                    GROUP BY DATE(created_at)
                    ORDER BY date DESC
                ");
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'settlements':
                $stmt = $db->query("
                    SELECT 
                        status,
                        COUNT(*) as count,
                        SUM(amount) as total
                    FROM settlement_queue 
                    GROUP BY status
                ");
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'fees':
                $stmt = $db->query("
                    SELECT 
                        fee_type,
                        COUNT(*) as count,
                        SUM(fee_amount) as total_fee,
                        SUM(total_amount) as total_with_vat,
                        status
                    FROM fee_invoices 
                    GROUP BY fee_type, status
                    ORDER BY created_at DESC
                ");
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'cashouts':
                $stmt = $db->query("
                    SELECT 
                        status,
                        COUNT(*) as count,
                        SUM(amount) as total,
                        cashout_point,
                        cashout_provider
                    FROM cashout_authorizations 
                    GROUP BY status, cashout_point, cashout_provider
                ");
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            default:
                $reportData = [];
        }
        
        // Calculate summary
        if ($reportData) {
            $reportSummary = [
                'total_records' => count($reportData),
                'total_amount' => array_sum(array_column($reportData, 'volume') ?: array_column($reportData, 'total') ?: [])
            ];
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] Report error: " . $e->getMessage());
        $reportData = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · ADMIN DASHBOARD</title>
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
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }
        .logo {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 2px;
        }
        .logo span { color: #FFDA63; margin-left: 10px; font-size: 0.8rem; }
        .country-badge {
            padding: 5px 15px;
            background: rgba(255,218,99,0.2);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .debug-badge {
            padding: 5px 15px;
            background: rgba(255,0,0,0.2);
            border: 1px solid #ff4444;
            color: #ff4444;
            font-size: 0.8rem;
            text-transform: uppercase;
            font-weight: 700;
        }
        .role-badge {
            padding: 5px 15px;
            background: rgba(255,218,99,0.15);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; color: #FFDA63; }
        .user-role { font-size: 0.7rem; color: #A1B5D8; text-transform: uppercase; }
        .logout-btn {
            padding: 8px 16px;
            background: transparent;
            border: 2px solid #FFDA63;
            color: #FFDA63;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .logout-btn:hover { background: #FFDA63; color: #001B44; }
        
        .admin-nav {
            background: #fff;
            border-bottom: 2px solid #001B44;
            padding: 0 30px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: 15px 0;
            color: #666;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .nav-item:hover { color: #001B44; }
        .nav-item.active {
            color: #001B44;
            border-bottom-color: #FFDA63;
        }
        .nav-item.debug-link {
            color: #ff4444 !important;
            border-bottom-color: #ff4444 !important;
        }
        
        .admin-content { padding: 30px; max-width: 1600px; margin: 0 auto; }
        .content-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .content-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #001B44;
        }
        .content-header .timestamp {
            color: #666;
            font-size: 0.8rem;
        }
        
        /* Search Bar */
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .search-bar input {
            flex: 1;
            min-width: 200px;
            padding: 12px 16px;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.9rem;
            background: #fff;
        }
        .search-bar input:focus {
            outline: none;
            border-color: #FFDA63;
        }
        .search-bar button {
            padding: 12px 24px;
            background: #001B44;
            color: #fff;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .search-bar button:hover {
            background: #FFDA63;
            color: #001B44;
            border-color: #FFDA63;
        }
        
        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 15px 20px;
            box-shadow: 4px 4px 0 #A1B5D8;
            transition: transform 0.2s;
        }
        .metric-card:hover { transform: translateY(-2px); }
        .metric-label {
            font-size: 0.6rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        .metric-value {
            font-size: 1.8rem;
            font-weight: 600;
            color: #001B44;
            line-height: 1.2;
        }
        .metric-value .sub {
            font-size: 0.8rem;
            color: #666;
        }
        
        /* Cards */
        .card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #001B44;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .card-badge {
            padding: 3px 10px;
            background: #001B44;
            color: #fff;
            font-size: 0.7rem;
        }
        
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        th {
            background: #001B44;
            color: #fff;
            padding: 8px 12px;
            font-weight: 600;
            text-align: left;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            white-space: nowrap;
        }
        td {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            font-size: 0.75rem;
        }
        tr:hover { background: #f5f5f5; }
        
        .status {
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
            border-radius: 3px;
        }
        .status-success { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .status-pending { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .status-failed { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .status-info { background: #cce5ff; color: #004085; border-color: #b8daff; }
        
        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #001B44;
        }
        .timeline-item {
            position: relative;
            padding: 10px 0 10px 20px;
            border-left: 2px solid #001B44;
            margin-left: -2px;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 14px;
            width: 10px;
            height: 10px;
            background: #FFDA63;
            border: 2px solid #001B44;
            border-radius: 50%;
        }
        .timeline-item .stage {
            font-weight: 700;
            color: #001B44;
            font-size: 0.8rem;
        }
        .timeline-item .time {
            font-size: 0.7rem;
            color: #666;
        }
        .timeline-item .details {
            font-size: 0.75rem;
            color: #444;
            margin-top: 5px;
        }
        .timeline-item .details .label {
            font-weight: 600;
            color: #001B44;
        }
        
        /* Debug Grid */
        .debug-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .debug-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 15px;
            box-shadow: 4px 4px 0 #A1B5D8;
        }
        .debug-card .table-name {
            font-weight: 700;
            font-size: 0.8rem;
        }
        .debug-card .count {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .empty-state .icon { font-size: 3rem; margin-bottom: 10px; }
        
        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 20px 30px;
            font-size: 0.7rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
            margin-top: 30px;
        }
        
        .report-filter {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .report-filter select {
            padding: 10px 16px;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            background: #fff;
        }
        .report-filter button {
            padding: 10px 20px;
            background: #001B44;
            color: #fff;
            border: 2px solid #001B44;
            cursor: pointer;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 600;
        }
        .report-filter button:hover {
            background: #FFDA63;
            color: #001B44;
            border-color: #FFDA63;
        }
        
        @media (max-width: 768px) {
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .debug-grid { grid-template-columns: 1fr; }
            .admin-nav { padding: 0 15px; gap: 10px; }
            .admin-content { padding: 15px; }
            .admin-header { padding: 15px; }
            .metric-value { font-size: 1.2rem; }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="country-badge">BOTSWANA</div>
            <div class="role-badge">👤 <?php echo htmlspecialchars($roleName); ?></div>
            <?php if ($debug): ?>
            <div class="debug-badge">🔍 DEBUG MODE</div>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($adminFullName ?: $adminUsername); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($roleName); ?></div>
            </div>
            <a href="admin_logout.php" class="logout-btn">LOGOUT</a>
        </div>
    </header>

    <nav class="admin-nav">
        <a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">📊 DASHBOARD</a>
        <a href="?view=transactions" class="nav-item <?php echo $view === 'transactions' ? 'active' : ''; ?>">📋 TRANSACTIONS</a>
        <a href="?view=search" class="nav-item <?php echo $view === 'search' ? 'active' : ''; ?>">🔍 SEARCH</a>
        <a href="?view=holds" class="nav-item <?php echo $view === 'holds' ? 'active' : ''; ?>">🔒 HOLDS</a>
        <a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">📝 AUDIT</a>
        <a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">💰 INVOICES</a>
        <a href="?view=reports" class="nav-item <?php echo $view === 'reports' ? 'active' : ''; ?>">📈 REPORTS</a>
        <?php if ($debug): ?>
        <a href="?view=debug&debug=1" class="nav-item active debug-link">🔍 DEBUG</a>
        <?php else: ?>
        <a href="?view=dashboard&debug=1" class="nav-item debug-link">🔍 DEBUG</a>
        <?php endif; ?>
    </nav>

    <main class="admin-content">
        <!-- ============================================================ -->
        <!-- DEBUG VIEW -->
        <!-- ============================================================ -->
        <?php if ($debug): ?>
        <div class="content-header">
            <h1>🔍 DATABASE DEBUG</h1>
            <div class="timestamp">Table status and record counts</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>
        <div class="debug-grid">
            <?php foreach ($tableStatus as $table => $status): ?>
            <div class="debug-card">
                <div class="table-name">
                    <?php echo htmlspecialchars($table); ?>
                    <?php if ($status['exists']): ?>
                    <span class="status status-success">EXISTS</span>
                    <?php else: ?>
                    <span class="status status-failed">MISSING</span>
                    <?php endif; ?>
                </div>
                <div class="count">
                    <?php echo $status['exists'] ? number_format($status['count']) : '—'; ?>
                </div>
                <div style="font-size: 0.7rem; color: #666;">
                    <?php if ($status['exists']): ?>
                    <?php echo $status['count'] > 0 ? 'records found' : 'empty'; ?>
                    <?php else: ?>
                    table does not exist
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- DASHBOARD VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'dashboard' && !$debug): ?>
        <div class="content-header">
            <h1>📊 EXECUTIVE DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></div>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total Users</div>
                <div class="metric-value"><?php echo number_format($metrics['total_users']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['total_swaps']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Completed</div>
                <div class="metric-value"><?php echo number_format($metrics['completed_swaps']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Pending</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_swaps']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Volume</div>
                <div class="metric-value"><?php echo number_format($metrics['total_volume'], 0); ?> <span class="sub">BWP</span></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Today's Volume</div>
                <div class="metric-value"><?php echo number_format($metrics['today_volume'], 0); ?> <span class="sub">BWP</span></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Active Holds</div>
                <div class="metric-value"><?php echo number_format($metrics['active_holds']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Pending Settlements</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_settlements']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Unpaid Invoices</div>
                <div class="metric-value"><?php echo number_format($metrics['unpaid_invoices']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Pending Cashouts</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_cashouts']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Identity Holds</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_identity_holds']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Audit Logs</div>
                <div class="metric-value"><?php echo number_format($metrics['total_audit_logs']); ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Recent Transactions</span>
                <span class="card-badge">Last 30</span>
                <a href="?view=transactions" style="color: #001B44; font-size: 0.7rem; text-transform: uppercase;">View All →</a>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Currencies</th>
                            <th>Status</th>
                            <th>Hold</th>
                            <th>Settlement</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTransactions as $tx): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($tx['swap_uuid'] ?? $tx['swap_id'] ?? 'N/A', 0, 8)); ?></td>
                            <td><?php echo htmlspecialchars($tx['user_name'] ?? $tx['user_id'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($tx['amount'] ?? 0), 2); ?></td>
                            <td><?php echo htmlspecialchars($tx['from_currency'] ?? 'BWP'); ?> → <?php echo htmlspecialchars($tx['to_currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($tx['status'] ?? 'pending');
                                $class = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo htmlspecialchars($tx['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo $tx['hold_status'] ? '<span class="status status-info">HELD</span>' : '—'; ?></td>
                            <td><?php echo $tx['settlement_status'] ? '<span class="status status-success">SETTLED</span>' : '—'; ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                            <td>
                                <a href="?view=track&id=<?php echo $tx['swap_id'] ?? $tx['swap_uuid'] ?? ''; ?>" 
                                   style="color: #001B44; font-weight: 600; font-size: 0.65rem; text-transform: uppercase;">Track →</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- TRANSACTIONS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'transactions' && !$debug): ?>
        <div class="content-header">
            <h1>📋 TRANSACTIONS</h1>
            <div class="timestamp">All swap transactions</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>
        
        <!-- Quick Search -->
        <div class="search-bar">
            <form method="GET" style="display: flex; gap: 10px; flex: 1; flex-wrap: wrap;">
                <input type="hidden" name="view" value="search">
                <input type="text" name="search" placeholder="Search by ID, User, Phone, Email, National ID, Status, Currency..." 
                       style="flex: 1; min-width: 200px; padding: 12px 16px; border: 2px solid #001B44; font-family: 'IBM Plex Mono', monospace;">
                <button type="submit">🔍 SEARCH</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">All Transactions</span>
                <span class="card-badge"><?php echo count($recentTransactions); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>From/To</th>
                            <th>Status</th>
                            <th>Source Country</th>
                            <th>Dest Country</th>
                            <th>Hold</th>
                            <th>Settlement</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTransactions as $tx): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($tx['swap_uuid'] ?? $tx['swap_id'] ?? 'N/A', 0, 8)); ?></td>
                            <td><?php echo htmlspecialchars($tx['user_name'] ?? $tx['user_id'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($tx['amount'] ?? 0), 2); ?></td>
                            <td><?php echo htmlspecialchars($tx['from_currency'] ?? 'BWP'); ?> → <?php echo htmlspecialchars($tx['to_currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($tx['status'] ?? 'pending');
                                $class = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo htmlspecialchars($tx['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($tx['source_country'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($tx['destination_country'] ?? 'N/A'); ?></td>
                            <td><?php echo $tx['hold_status'] ? '<span class="status status-info">HELD</span>' : '—'; ?></td>
                            <td><?php echo $tx['settlement_status'] ? '<span class="status status-success">SETTLED</span>' : '—'; ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                            <td>
                                <a href="?view=track&id=<?php echo $tx['swap_id'] ?? $tx['swap_uuid'] ?? ''; ?>" 
                                   style="color: #001B44; font-weight: 600; font-size: 0.65rem; text-transform: uppercase;">Track →</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- SEARCH VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'search' && !$debug): ?>
        <div class="content-header">
            <h1>🔍 SEARCH TRANSACTIONS</h1>
            <div class="timestamp">Search by any field</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>

        <div class="search-bar">
            <form method="GET" style="display: flex; gap: 10px; flex: 1; flex-wrap: wrap;">
                <input type="hidden" name="view" value="search">
                <input type="text" name="search" placeholder="Search by ID, User, Phone, Email, National ID, Status, Currency..." 
                       value="<?php echo htmlspecialchars($search); ?>"
                       style="flex: 1; min-width: 200px; padding: 12px 16px; border: 2px solid #001B44; font-family: 'IBM Plex Mono', monospace;">
                <button type="submit">🔍 SEARCH</button>
                <?php if ($search): ?>
                <a href="?view=search" style="padding: 12px 20px; border: 2px solid #999; color: #666; text-decoration: none;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($searchPerformed): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title">Search Results for: "<?php echo htmlspecialchars($search); ?>"</span>
                <span class="card-badge"><?php echo count($searchResults); ?> FOUND</span>
            </div>
            <?php if (empty($searchResults)): ?>
            <div class="empty-state">
                <div class="icon">🔍</div>
                <p>No results found for "<?php echo htmlspecialchars($search); ?>"</p>
                <p style="font-size: 0.8rem; color: #666; margin-top: 5px;">Try searching by ID, User Name, Phone, Email, National ID, Status, or Currency</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Phone</th>
                            <th>Amount</th>
                            <th>Currencies</th>
                            <th>Status</th>
                            <th>Hold</th>
                            <th>Settlement</th>
                            <th>Cashout</th>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchResults as $result): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($result['swap_uuid'] ?? $result['swap_id'] ?? 'N/A', 0, 8)); ?></td>
                            <td><?php echo htmlspecialchars($result['user_name'] ?? $result['user_id'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($result['user_phone'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($result['amount'] ?? 0), 2); ?></td>
                            <td><?php echo htmlspecialchars($result['from_currency'] ?? 'BWP'); ?> → <?php echo htmlspecialchars($result['to_currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($result['status'] ?? 'pending');
                                $class = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo htmlspecialchars($result['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo $result['hold_status'] ? '<span class="status status-info">HELD</span>' : '—'; ?></td>
                            <td><?php echo $result['settlement_status'] ? '<span class="status status-success">SETTLED</span>' : '—'; ?></td>
                            <td><?php echo $result['cashout_status'] ? '<span class="status status-info">CASHOUT</span>' : '—'; ?></td>
                            <td><?php echo $result['invoice_status'] ? '<span class="status status-info">INVOICED</span>' : '—'; ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($result['created_at'] ?? 'now')); ?></td>
                            <td>
                                <a href="?view=track&id=<?php echo $result['swap_id'] ?? $result['swap_uuid'] ?? ''; ?>" 
                                   style="color: #001B44; font-weight: 600; font-size: 0.65rem; text-transform: uppercase;">Track →</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- TRACK TRANSACTION VIEW (Full Lifecycle) -->
        <!-- ============================================================ -->
        <?php if ($view === 'track' && $transactionDetail && !$debug): ?>
        <div class="content-header">
            <div>
                <h1>🔍 Transaction Tracking</h1>
                <div class="timestamp">
                    Transaction #<?php echo htmlspecialchars($transactionDetail['swap_id'] ?? $transactionDetail['swap_uuid'] ?? 'N/A'); ?>
                    <?php if (!empty($transactionDetail['status'])): ?>
                    <span class="status status-<?php 
                        $status = strtolower($transactionDetail['status'] ?? 'pending');
                        echo $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                    ?>"><?php echo htmlspecialchars($transactionDetail['status']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <a href="?view=transactions" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Transactions</a>
                <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Dashboard</a>
            </div>
        </div>

        <!-- Transaction Summary -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
            <div class="metric-card">
                <div class="metric-label">Status</div>
                <div class="metric-value" style="font-size: 1.2rem;">
                    <span class="status status-<?php 
                        $status = strtolower($transactionDetail['status'] ?? 'pending');
                        echo $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                    ?>"><?php echo htmlspecialchars($transactionDetail['status'] ?? 'pending'); ?></span>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Amount</div>
                <div class="metric-value" style="font-size: 1.2rem;">
                    <?php echo number_format((float)($transactionDetail['amount'] ?? 0), 2); ?> BWP
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">User</div>
                <div class="metric-value" style="font-size: 0.9rem;">
                    <?php echo htmlspecialchars($transactionDetail['user_name'] ?? $transactionDetail['user_id'] ?? 'N/A'); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Created</div>
                <div class="metric-value" style="font-size: 0.9rem;">
                    <?php echo date('Y-m-d H:i', strtotime($transactionDetail['created_at'] ?? 'now')); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Currencies</div>
                <div class="metric-value" style="font-size: 0.9rem;">
                    <?php echo htmlspecialchars($transactionDetail['from_currency'] ?? 'BWP'); ?> → <?php echo htmlspecialchars($transactionDetail['to_currency'] ?? 'BWP'); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Countries</div>
                <div class="metric-value" style="font-size: 0.9rem;">
                    <?php echo htmlspecialchars($transactionDetail['source_country'] ?? 'N/A'); ?> → <?php echo htmlspecialchars($transactionDetail['destination_country'] ?? 'N/A'); ?>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Transaction Lifecycle</span>
                <span class="card-badge">FROM CREATION TO SETTLEMENT</span>
            </div>
            <div class="timeline">
                <?php foreach ($transactionTimeline as $item): ?>
                <div class="timeline-item">
                    <div class="stage">
                        <?php echo htmlspecialchars($item['stage']); ?>
                        <span class="time"><?php echo date('Y-m-d H:i:s', strtotime($item['timestamp'])); ?></span>
                    </div>
                    <div class="details">
                        <span class="label">Description:</span> <?php echo htmlspecialchars($item['description']); ?>
                        <?php if (!empty($item['details'])): ?>
                        <br>
                        <?php foreach ($item['details'] as $key => $value): ?>
                        <span class="label"><?php echo htmlspecialchars($key); ?>:</span> <?php echo htmlspecialchars((string)$value); ?> &nbsp;
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Full Transaction Details -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📄 Full Transaction Details</span>
                <span class="card-badge">COMPLETE DATA</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 0.8rem;">
                <?php foreach ($transactionDetail as $key => $value): ?>
                <div style="background: #f8f9fa; padding: 8px 12px; border-radius: 4px;">
                    <div style="font-weight: 600; color: #666; font-size: 0.6rem; text-transform: uppercase;"><?php echo htmlspecialchars($key); ?></div>
                    <div style="word-break: break-all;"><?php echo htmlspecialchars((string)$value); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- AUDIT LOGS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'audit' && !$debug): ?>
        <div class="content-header">
            <h1>📝 AUDIT LOGS</h1>
            <div class="timestamp">System audit trail</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Audit Entries</span>
                <span class="card-badge"><?php echo count($recentAuditLogs); ?> RECORDS</span>
            </div>
            <?php if (empty($recentAuditLogs)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No audit logs found</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Category</th>
                            <th>Severity</th>
                            <th>Performed By</th>
                            <th>IP</th>
                            <th>Endpoint</th>
                            <th>Duration</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAuditLogs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($log['audit_id'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['action'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['entity_type'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['category'] ?? 'N/A')); ?></td>
                            <td>
                                <span class="status status-<?php 
                                    $severity = strtolower($log['severity'] ?? '');
                                    echo $severity === 'critical' ? 'failed' : 'info';
                                ?>"><?php echo htmlspecialchars($log['severity'] ?? 'N/A'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars((string)($log['performed_by_type'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['ip_address'] ?? 'N/A')); ?></td>
                            <td style="font-size: 0.65rem;"><?php echo htmlspecialchars(substr($log['endpoint'] ?? '', 0, 30)); ?></td>
                            <td><?php echo $log['duration_ms'] ? number_format((float)$log['duration_ms'], 0) . 'ms' : '—'; ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($log['performed_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- HOLDS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'holds' && !$debug): ?>
        <div class="content-header">
            <h1>🔒 HOLD TRANSACTIONS</h1>
            <div class="timestamp">Active and historical holds</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <span class="card-title">Hold Records</span>
                <span class="card-badge"><?php echo count($recentHolds); ?> RECORDS</span>
            </div>
            <?php if (empty($recentHolds)): ?>
            <div class="empty-state">
                <div class="icon">🔒</div>
                <p>No hold transactions found</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Hold Ref</th>
                            <th>Swap Ref</th>
                            <th>Participant</th>
                            <th>Asset</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentHolds as $hold): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($hold['hold_reference'] ?? '', 0, 12)); ?></td>
                            <td><?php echo htmlspecialchars(substr($hold['swap_reference'] ?? '', 0, 12)); ?></td>
                            <td><?php echo htmlspecialchars($hold['participant_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($hold['asset_type'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($hold['amount'] ?? 0), 2); ?> <?php echo htmlspecialchars($hold['currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($hold['status'] ?? '');
                                $class = $status === 'active' || $status === 'held' ? 'success' : ($status === 'released' ? 'info' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo htmlspecialchars($hold['status'] ?? 'N/A'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($hold['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($hold['destination_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($hold['created_at'] ?? $hold['placed_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- INVOICES VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'invoices' && !$debug): ?>
        <div class="content-header">
            <h1>💰 FEE INVOICES</h1>
            <div class="timestamp">VouchMorph fee invoices</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <span class="card-title">All Invoices</span>
                <span class="card-badge"><?php echo count($recentInvoices); ?> RECORDS</span>
            </div>
            <?php if (empty($recentInvoices)): ?>
            <div class="empty-state">
                <div class="icon">💰</div>
                <p>No invoices found</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Swap Ref</th>
                            <th>Source</th>
                            <th>Type</th>
                            <th>Fee</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentInvoices as $inv): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($inv['invoice_uuid'] ?? '', 0, 8)); ?></td>
                            <td><?php echo htmlspecialchars(substr($inv['swap_reference'] ?? '', 0, 8)); ?></td>
                            <td><?php echo htmlspecialchars($inv['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($inv['fee_type'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($inv['fee_amount'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($inv['total_amount'] ?? 0), 2); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($inv['status'] ?? 'pending');
                                $class = $status === 'paid' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo htmlspecialchars($inv['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($inv['created_at'] ?? 'now')); ?></td>
                            <td><?php echo $inv['paid_at'] ? date('Y-m-d H:i', strtotime($inv['paid_at'])) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- REPORTS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'reports' && !$debug): ?>
        <div class="content-header">
            <h1>📈 REPORTS</h1>
            <div class="timestamp">Generate and view reports</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Report Generator</span>
                <span class="card-badge">SELECT TYPE</span>
            </div>
            <div class="report-filter">
                <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <input type="hidden" name="view" value="reports">
                    <select name="report" style="padding: 10px 16px; border: 2px solid #001B44; font-family: 'IBM Plex Mono', monospace; background: #fff;">
                        <option value="">Select Report Type</option>
                        <option value="daily" <?php echo $reportType === 'daily' ? 'selected' : ''; ?>>Daily Transaction Summary</option>
                        <option value="settlements" <?php echo $reportType === 'settlements' ? 'selected' : ''; ?>>Settlement Status</option>
                        <option value="fees" <?php echo $reportType === 'fees' ? 'selected' : ''; ?>>Fee Collection Report</option>
                        <option value="cashouts" <?php echo $reportType === 'cashouts' ? 'selected' : ''; ?>>Cashout Report</option>
                    </select>
                    <button type="submit">📊 Generate Report</button>
                </form>
            </div>
        </div>

        <?php if ($reportData): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?php echo ucfirst($reportType); ?> Report</span>
                <span class="card-badge"><?php echo $reportSummary['total_records'] ?? 0; ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <?php if (!empty($reportData)): ?>
                            <?php foreach (array_keys($reportData[0]) as $col): ?>
                            <th><?php echo htmlspecialchars($col); ?></th>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData as $row): ?>
                        <tr>
                            <?php foreach ($row as $value): ?>
                            <td><?php echo htmlspecialchars((string)$value); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($reportSummary['total_amount'] ?? 0): ?>
            <div style="padding: 15px; background: #f8f9fa; margin-top: 15px; border-top: 2px solid #001B44;">
                <strong>Total Amount:</strong> <?php echo number_format($reportSummary['total_amount'], 2); ?> BWP
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · Botswana · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>
</body>
</html>
