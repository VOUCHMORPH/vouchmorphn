<?php
/**
 * admin_dashboard.php - VouchMorph Enhanced Admin Dashboard with Diagnostics
 * Features: Role-based access, Transaction Search, Full Tracking, Reports, Debug Mode, Table Diagnostics
 *
 * FIX APPLIED: the "0 records in Transactions view vs 31 in Diagnostic" bug.
 * Root cause: several LEFT JOINs cast a text reference column to int —
 * e.g. `ht.swap_reference::int` — but swap_reference/reference values are
 * strings like "SWAP_1730987143_abc123" (see SwapService::generateReference()),
 * never numeric. Postgres throws "invalid input syntax for integer" on that
 * cast, the whole query throws, the catch block swallows it, and
 * $recentTransactions/$searchResults end up empty even though swap_requests
 * has real rows. Fix: drop the ::int-casting JOINs entirely and fetch
 * hold/settlement/cashout/invoice/identity status with separate queries per
 * row, matching as plain text against both swap_id (cast to string in PHP,
 * not SQL) and swap_uuid. This mirrors the same pattern already used safely
 * in the TRANSACTION DETAIL section below (which uses `::text`, a no-op
 * cast on an already-text column, which is why that section never had this
 * bug).
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
// DIAGNOSTIC: CHECK ALL TABLES FOR DATA
// ============================================================
$diagnosticData = [];
$tablesToCheck = [
    'swap_requests' => 'Main Swap Requests',
    'swap_ledgers' => 'Swap Ledgers',
    'hold_transactions' => 'Hold Transactions',
    'settlement_queue' => 'Settlement Queue',
    'settlement_outbox' => 'Settlement Outbox',
    'cashout_authorizations' => 'Cashout Authorizations',
    'fee_invoices' => 'Fee Invoices',
    'identity_swap_holds' => 'Identity Swap Holds',
    'multi_destination_swaps' => 'Multi-Destination Swaps',
    'idempotency_keys' => 'Idempotency Keys',
    'swap_fee_collections' => 'Swap Fee Collections',
    'net_positions' => 'Net Positions',
    'deposit_transactions' => 'Deposit Transactions',
    'swap_vouchers' => 'Swap Vouchers',
    'cross_border_messages' => 'Cross Border Messages',
    'users' => 'Users',
    'audit_logs' => 'Audit Logs'
];

foreach ($tablesToCheck as $table => $label) {
    try {
        $stmt = $db->prepare("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = :table
            )
        ");
        $stmt->execute([':table' => $table]);
        $exists = (bool)$stmt->fetchColumn();

        if ($exists) {
            $countStmt = $db->query("SELECT COUNT(*) FROM " . $table);
            $count = (int)$countStmt->fetchColumn();

            // Get sample row if count > 0
            $sample = null;
            if ($count > 0) {
                $sampleStmt = $db->query("SELECT * FROM " . $table . " LIMIT 1");
                $sample = $sampleStmt->fetch(PDO::FETCH_ASSOC);
            }

            $diagnosticData[$table] = [
                'label' => $label,
                'exists' => true,
                'count' => $count,
                'sample' => $sample,
                'has_data' => $count > 0
            ];
        } else {
            $diagnosticData[$table] = [
                'label' => $label,
                'exists' => false,
                'count' => 0,
                'sample' => null,
                'has_data' => false,
                'error' => 'Table does not exist'
            ];
        }
    } catch (Throwable $e) {
        $diagnosticData[$table] = [
            'label' => $label,
            'exists' => false,
            'count' => 0,
            'sample' => null,
            'has_data' => false,
            'error' => $e->getMessage()
        ];
    }
}

// ============================================================
// TABLE SCHEMA CHECK (for debug mode)
// ============================================================
$tables = array_keys($tablesToCheck);
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
// HELPER: fetch a related row's status from an audit-side table,
// matching as plain text against BOTH the internal swap_id and the
// swap_uuid/reference string — never casting the audit table's text
// column to int. This is the actual fix: no `::int`, ever, on a
// reference column that stores "SWAP_<timestamp>_<hex>" strings.
// ============================================================
function fetchRelatedStatus(PDO $db, string $table, string $refColumn, $swapId, ?string $swapUuid): ?array
{
    try {
        $stmt = $db->prepare("
            SELECT * FROM {$table}
            WHERE {$refColumn} = :ref OR {$refColumn} = :uuid
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([
            ':ref' => (string)($swapId ?? ''),
            ':uuid' => (string)($swapUuid ?? ''),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] fetchRelatedStatus({$table}) error: " . $e->getMessage());
        return null;
    }
}

function attachRelatedStatuses(PDO $db, array &$row): void
{
    $swapId = $row['swap_id'] ?? null;
    $swapUuid = $row['swap_uuid'] ?? null;

    $hold = fetchRelatedStatus($db, 'hold_transactions', 'swap_reference', $swapId, $swapUuid);
    $row['hold_status'] = $hold['status'] ?? null;

    $settlement = fetchRelatedStatus($db, 'settlement_queue', 'reference', $swapId, $swapUuid);
    $row['settlement_status'] = $settlement['status'] ?? null;

    $cashout = fetchRelatedStatus($db, 'cashout_authorizations', 'swap_reference', $swapId, $swapUuid);
    $row['cashout_status'] = $cashout['status'] ?? null;

    $invoice = fetchRelatedStatus($db, 'fee_invoices', 'swap_reference', $swapId, $swapUuid);
    $row['invoice_status'] = $invoice['status'] ?? null;

    $identity = fetchRelatedStatus($db, 'identity_swap_holds', 'swap_reference', $swapId, $swapUuid);
    $row['identity_status'] = $identity['status'] ?? null;
}

// ============================================================
// TABLE-BY-TABLE ACTIVITY FEED
//
// Why this exists: nothing in the SwapService.php you shared ever INSERTs
// into `swap_requests` — every real swap execution writes to
// hold_transactions (createLocalHold), identity_swap_holds
// (storeIdentityHold), cashout_authorizations (storeCashoutAuthorization),
// or multi_destination_swaps (storeMultiDestinationRecord) instead.
// swap_requests looks like a separate/legacy table that isn't part of the
// current execution path at all — which is exactly why gating the whole
// Transactions view on "does swap_requests have rows" showed nothing even
// once the ::int crash was fixed: the table it depends on may genuinely
// stay empty forever while real swaps keep landing in the other tables.
//
// Fix: each real activity table is fetched independently with its own
// `SELECT * ... LIMIT N`, no JOIN, no shared gate. One table being empty
// (or not yet existing) never affects any other table's card.
// ============================================================
const ACTIVITY_TABLES = [
    'swap_requests' => 'Swap Requests (legacy/system table)',
    'hold_transactions' => 'Holds — Source Verify & Debit',
    'identity_swap_holds' => 'Identity Swaps',
    'cashout_authorizations' => 'Cashouts',
    'multi_destination_swaps' => 'Multi-Destination Swaps',
    'fee_invoices' => 'Fee Invoices (Billing)',
];

function fetchTableRows(PDO $db, string $table, int $limit = 50): array
{
    try {
        $stmt = $db->query("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT {$limit}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] fetchTableRows({$table}) error: " . $e->getMessage());
        return [];
    }
}

$activityData = [];
foreach (ACTIVITY_TABLES as $table => $label) {
    $activityData[$table] = fetchTableRows($db, $table);
}
$anyActivityHasData = (bool)array_filter($activityData, fn($rows) => !empty($rows));

// ============================================================
// TRANSACTION SEARCH — FIXED: no ::int JOINs. Base query only
// touches swap_requests + users; related-table statuses are
// fetched per-row via attachRelatedStatuses() above.
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
                u.national_id
            FROM swap_requests sr
            LEFT JOIN users u ON sr.user_id = u.user_id
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

        foreach ($searchResults as &$result) {
            attachRelatedStatuses($db, $result);
        }
        unset($result);
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] Search error: " . $e->getMessage());
        $searchResults = [];
    }
}

// ============================================================
// TRANSACTION DETAIL (Full lifecycle)
// These queries already used `::text` (a no-op cast on an
// already-text column) rather than `::int`, so they were never
// part of the crash — left as-is.
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

        // FALLBACK: swap_requests may simply not have this reference (see
        // the ACTIVITY_TABLES note above — it may not be written by the
        // current swap flow at all). Anchor the timeline on whichever real
        // activity table actually has it instead of giving up.
        if (!$transactionDetail) {
            $fallbackAnchors = [
                'hold_transactions' => 'swap_reference',
                'identity_swap_holds' => 'swap_reference',
                'cashout_authorizations' => 'swap_reference',
                'multi_destination_swaps' => 'reference',
            ];
            foreach ($fallbackAnchors as $anchorTable => $anchorCol) {
                $anchorRow = fetchRelatedStatus($db, $anchorTable, $anchorCol, $transactionId, $transactionId);
                if ($anchorRow) {
                    $transactionDetail = [
                        'swap_id' => null,
                        'swap_uuid' => $anchorRow[$anchorCol] ?? $transactionId,
                        'amount' => $anchorRow['amount'] ?? $anchorRow['total_amount'] ?? 0,
                        'from_currency' => $anchorRow['currency'] ?? 'BWP',
                        'status' => $anchorRow['status'] ?? 'unknown',
                        'created_at' => $anchorRow['created_at'] ?? null,
                        'user_name' => null,
                        '_anchor_table' => $anchorTable,
                    ];
                    error_log("[ADMIN DASHBOARD] No swap_requests row for {$transactionId} — anchored timeline on {$anchorTable} instead");
                    break;
                }
            }
        }

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
// RECENT TRANSACTIONS — FIXED: no ::int JOINs. Base query only
// touches swap_requests + users; hold/settlement status attached
// per-row via attachRelatedStatuses(). Falls back to an even
// simpler query (no user JOIN either) if something still errors,
// so a bad users JOIN can never blank out the whole view again.
// ============================================================
$recentTransactions = [];
$tableHasData = false;
try {
    // Check if swap_requests has any data
    $checkStmt = $db->query("SELECT COUNT(*) FROM swap_requests");
    $count = (int)$checkStmt->fetchColumn();
    $tableHasData = $count > 0;

    if ($tableHasData) {
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
                u.email as user_email
            FROM swap_requests sr
            LEFT JOIN users u ON sr.user_id = u.user_id
            ORDER BY sr.created_at DESC
            LIMIT 50
        ");
        $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recentTransactions as &$tx) {
            attachRelatedStatuses($db, $tx);
        }
        unset($tx);
    }
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Recent transactions error: " . $e->getMessage());
    // Fallback: even simpler query, no JOIN at all
    try {
        $stmt = $db->query("
            SELECT
                swap_id,
                swap_uuid,
                user_id,
                amount,
                status,
                created_at,
                from_currency,
                to_currency,
                source_country,
                destination_country
            FROM swap_requests
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tableHasData = !empty($recentTransactions);
    } catch (Throwable $e2) {
        error_log("[ADMIN DASHBOARD] Fallback query error: " . $e2->getMessage());
        $recentTransactions = [];
    }
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

// Helper function for safe HTML
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Renders one independent activity-table card. Pulls whichever of the
// common fields exist on this table's rows (every real activity table
// has some subset of reference/amount/currency/status/institution/
// created_at — see the INSERT statements in SwapService.php) and shows
// everything else in an expandable raw row, rather than assuming a fixed
// schema shared across tables.
function renderActivityCard(string $table, string $label, array $rows): string {
    $count = count($rows);
    $html = '<div class="card"><div class="card-header">'
        . '<span class="card-title">' . safeHtml($label) . '</span>'
        . '<span class="card-badge">' . $count . ' RECORDS</span>'
        . '</div>';

    if ($count === 0) {
        $html .= '<div class="empty-state"><div class="icon">📭</div>'
            . '<p>No rows in <code>' . safeHtml($table) . '</code> yet.</p></div>';
        $html .= '</div>';
        return $html;
    }

    $refFields = ['swap_reference', 'reference', 'hold_reference', 'swap_uuid', 'swap_id', 'invoice_uuid'];
    $amountFields = ['amount', 'total_amount', 'fee_amount'];
    $instFields = ['source_institution', 'participant_name', 'destination_institution'];

    $html .= '<div class="table-responsive"><table><thead><tr>'
        . '<th>Reference</th><th>Amount</th><th>Currency</th><th>Status</th><th>Institution</th><th>Created</th><th>Details</th>'
        . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $ref = null;
        foreach ($refFields as $f) { if (!empty($row[$f])) { $ref = $row[$f]; break; } }
        $amount = null;
        foreach ($amountFields as $f) { if (isset($row[$f])) { $amount = $row[$f]; break; } }
        $inst = null;
        foreach ($instFields as $f) { if (!empty($row[$f])) { $inst = $row[$f]; break; } }

        $html .= '<tr>'
            . '<td>' . safeHtml(substr((string)($ref ?? 'N/A'), 0, 24)) . '</td>'
            . '<td>' . ($amount !== null ? number_format((float)$amount, 2) : '—') . '</td>'
            . '<td>' . safeHtml($row['currency'] ?? 'BWP') . '</td>'
            . '<td><span class="status status-info">' . safeHtml($row['status'] ?? 'N/A') . '</span></td>'
            . '<td>' . safeHtml($inst ?? 'N/A') . '</td>'
            . '<td>' . safeHtml($row['created_at'] ?? 'N/A') . '</td>'
            . '<td><details><summary style="cursor:pointer;color:#001B44;font-size:0.65rem;">Raw row</summary>'
            . '<pre style="font-size:0.65rem;white-space:pre-wrap;max-width:400px;overflow:auto;">' . safeHtml(json_encode($row, JSON_PRETTY_PRINT)) . '</pre></details></td>'
            . '</tr>';
    }

    $html .= '</tbody></table></div></div>';
    return $html;
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

        /* Diagnostic Cards */
        .diagnostic-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .diagnostic-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 15px;
            box-shadow: 4px 4px 0 #A1B5D8;
        }
        .diagnostic-card .table-name {
            font-weight: 700;
            font-size: 0.8rem;
        }
        .diagnostic-card .count {
            font-size: 1.5rem;
            font-weight: 700;
        }
        .diagnostic-card .status-label {
            font-size: 0.7rem;
            font-weight: 600;
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
            .diagnostic-grid { grid-template-columns: 1fr; }
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
            <div class="role-badge">👤 <?php echo safeHtml($roleName); ?></div>
            <?php if ($debug): ?>
            <div class="debug-badge">🔍 DEBUG MODE</div>
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
        <a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">📊 DASHBOARD</a>
        <a href="?view=transactions" class="nav-item <?php echo $view === 'transactions' ? 'active' : ''; ?>">📋 TRANSACTIONS</a>
        <a href="?view=search" class="nav-item <?php echo $view === 'search' ? 'active' : ''; ?>">🔍 SEARCH</a>
        <a href="?view=holds" class="nav-item <?php echo $view === 'holds' ? 'active' : ''; ?>">🔒 HOLDS</a>
        <a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">📝 AUDIT</a>
        <a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">💰 INVOICES</a>
        <a href="?view=reports" class="nav-item <?php echo $view === 'reports' ? 'active' : ''; ?>">📈 REPORTS</a>
        <a href="?view=diagnostic" class="nav-item <?php echo $view === 'diagnostic' ? 'active' : ''; ?>">🔬 DIAGNOSTIC</a>
        <?php if ($debug): ?>
        <a href="?view=debug&debug=1" class="nav-item active debug-link">🔍 DEBUG</a>
        <?php else: ?>
        <a href="?view=dashboard&debug=1" class="nav-item debug-link">🔍 DEBUG</a>
        <?php endif; ?>
    </nav>
    <main class="admin-content">
        <!-- ============================================================ -->
        <!-- DIAGNOSTIC VIEW - Shows all table data status -->
        <!-- ============================================================ -->
        <?php if ($view === 'diagnostic'): ?>
        <div class="content-header">
            <h1>🔬 DATABASE DIAGNOSTIC</h1>
            <div class="timestamp">Check which tables have data</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>

        <div class="diagnostic-grid">
            <?php foreach ($diagnosticData as $table => $info): ?>
            <div class="diagnostic-card">
                <div class="table-name">
                    <?php echo safeHtml($info['label'] ?? $table); ?>
                    <?php if ($info['exists']): ?>
                        <?php if ($info['has_data']): ?>
                        <span class="status status-success">✅ HAS DATA</span>
                        <?php else: ?>
                        <span class="status status-pending">⚠️ EMPTY</span>
                        <?php endif; ?>
                    <?php else: ?>
                    <span class="status status-failed">❌ MISSING</span>
                    <?php endif; ?>
                </div>
                <div class="count"><?php echo $info['exists'] ? number_format($info['count']) : '—'; ?></div>
                <div class="status-label">
                    <?php if ($info['exists']): ?>
                    <?php echo $info['count'] > 0 ? $info['count'] . ' records found' : 'No records'; ?>
                    <?php else: ?>
                    Table does not exist
                    <?php endif; ?>
                </div>
                <?php if ($info['exists'] && $info['has_data'] && $info['sample']): ?>
                <div style="margin-top: 10px; font-size: 0.6rem; color: #666; max-height: 100px; overflow: auto; background: #f8f9fa; padding: 8px; border-radius: 4px;">
                    <strong>Sample:</strong>
                    <?php
                    $sampleKeys = array_slice(array_keys($info['sample']), 0, 5);
                    foreach ($sampleKeys as $key):
                    ?>
                    <div><span style="color: #001B44;"><?php echo safeHtml($key); ?>:</span> <?php echo safeHtml(substr((string)$info['sample'][$key], 0, 50)); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($info['error'])): ?>
                <div style="color: #dc3545; font-size: 0.7rem; margin-top: 5px;">Error: <?php echo safeHtml($info['error']); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <!-- ============================================================ -->
        <!-- DEBUG VIEW -->
        <!-- ============================================================ -->
        <?php if ($debug): ?>
        <div class="content-header">
            <h1>🔍 DATABASE DEBUG</h1>
            <div class="timestamp">Table status and record counts</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>
        <div class="diagnostic-grid">
            <?php foreach ($tableStatus as $table => $status): ?>
            <div class="diagnostic-card">
                <div class="table-name">
                    <?php echo safeHtml($table); ?>
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
        <?php if ($view === 'dashboard' && !$debug && $view !== 'diagnostic'): ?>
        <div class="content-header">
            <h1>📊 EXECUTIVE DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></div>
            <a href="?view=diagnostic" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">🔬 Check Data Sources</a>
        </div>
        <!-- Data Source Warning: checks ALL activity tables, not just
             swap_requests — swap_requests isn't written by the current
             swap execution flow, so gating on it alone was the bug. -->
        <?php if (!$anyActivityHasData): ?>
        <div style="background: #fff3cd; border: 2px solid #856404; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
            <strong>⚠️ No activity found in any transaction table.</strong>
            <p style="margin-top: 5px; font-size: 0.8rem;"><code>swap_requests</code>, <code>hold_transactions</code>, <code>identity_swap_holds</code>, <code>cashout_authorizations</code>, <code>multi_destination_swaps</code>, and <code>fee_invoices</code> are all currently empty. Activity will appear here once swaps are executed.</p>
            <p style="font-size: 0.8rem; margin-top: 5px;">
                <a href="?view=diagnostic" style="color: #001B44; font-weight: 600;">🔬 Check all tables →</a>
            </p>
        </div>
        <?php endif; ?>
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
                <span class="card-badge"><?php echo count($recentTransactions); ?> RECORDS</span>
                <a href="?view=transactions" style="color: #001B44; font-size: 0.7rem; text-transform: uppercase;">View All →</a>
                <a href="?view=diagnostic" style="color: #001B44; font-size: 0.7rem; text-transform: uppercase;">🔬 Check Data →</a>
            </div>
            <?php if (empty($recentTransactions)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No transactions found</p>
                <p style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                    Transactions will appear here once swaps are executed.
                </p>
                <p style="margin-top: 10px;">
                    <a href="?view=diagnostic" style="color: #001B44; font-weight: 600; text-decoration: underline;">Check all tables →</a>
                </p>
            </div>
            <?php else: ?>
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
                            <td><?php echo safeHtml(substr($tx['swap_uuid'] ?? (string)($tx['swap_id'] ?? 'N/A'), 0, 8)); ?></td>
                            <td><?php echo safeHtml($tx['user_name'] ?? $tx['user_id'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($tx['amount'] ?? 0), 2); ?></td>
                            <td><?php echo safeHtml($tx['from_currency'] ?? 'BWP'); ?> → <?php echo safeHtml($tx['to_currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php
                                $status = strtolower($tx['status'] ?? 'pending');
                                $class = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($tx['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo !empty($tx['hold_status']) ? '<span class="status status-info">' . safeHtml($tx['hold_status']) . '</span>' : '—'; ?></td>
                            <td><?php echo !empty($tx['settlement_status']) ? '<span class="status status-success">' . safeHtml($tx['settlement_status']) . '</span>' : '—'; ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                            <td>
                                <a href="?view=track&id=<?php echo urlencode((string)($tx['swap_uuid'] ?? $tx['swap_id'] ?? '')); ?>"
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
        <!-- ============================================================ -->
        <!-- TRANSACTIONS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'transactions' && !$debug && $view !== 'diagnostic'): ?>
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
        <?php if (!$anyActivityHasData): ?>
        <div style="background: #fff3cd; border: 2px solid #856404; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
            <strong>⚠️ No activity in any transaction table yet.</strong>
            <p style="margin-top: 5px; font-size: 0.8rem;">Every table below is independent — none of them being empty affects any other. <a href="?view=diagnostic" style="color: #001B44; font-weight: 600;">🔬 Full diagnostic →</a></p>
        </div>
        <?php endif; ?>

        <!-- swap_requests, shown with the richer user-joined view (name,
             hold/settlement status already attached) since that data is
             only meaningful when swap_requests actually has rows tying
             back to a user. All other tables below are rendered generically
             and independently — one table having no rows never blanks out
             any other table's card. -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Swap Requests (legacy/system table)</span>
                <span class="card-badge"><?php echo count($recentTransactions); ?> RECORDS</span>
            </div>
            <?php if (empty($recentTransactions)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No rows in <code>swap_requests</code>.</p>
                <p style="font-size: 0.8rem; color: #666; margin-top: 5px;">This table isn't written by the current swap execution flow — see the tables below for real activity.</p>
            </div>
            <?php else: ?>
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
                            <td><?php echo safeHtml(substr($tx['swap_uuid'] ?? (string)($tx['swap_id'] ?? 'N/A'), 0, 8)); ?></td>
                            <td><?php echo safeHtml($tx['user_name'] ?? $tx['user_id'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($tx['amount'] ?? 0), 2); ?></td>
                            <td><?php echo safeHtml($tx['from_currency'] ?? 'BWP'); ?> → <?php echo safeHtml($tx['to_currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php
                                $status = strtolower($tx['status'] ?? 'pending');
                                $class = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($tx['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo safeHtml($tx['source_country'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($tx['destination_country'] ?? 'N/A'); ?></td>
                            <td><?php echo !empty($tx['hold_status']) ? '<span class="status status-info">' . safeHtml($tx['hold_status']) . '</span>' : '—'; ?></td>
                            <td><?php echo !empty($tx['settlement_status']) ? '<span class="status status-success">' . safeHtml($tx['settlement_status']) . '</span>' : '—'; ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                            <td>
                                <a href="?view=track&id=<?php echo urlencode((string)($tx['swap_uuid'] ?? $tx['swap_id'] ?? '')); ?>"
                                   style="color: #001B44; font-weight: 600; font-size: 0.65rem; text-transform: uppercase;">Track →</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Every other activity table, rendered independently — this is the
        // direct fix for "some tables are empty so nothing shows": each
        // card below stands alone, fed by its own SELECT, with no JOIN and
        // no shared gate with any other table.
        foreach (ACTIVITY_TABLES as $table => $label):
            if ($table === 'swap_requests') continue; // already rendered above with the richer view
            echo renderActivityCard($table, $label, $activityData[$table] ?? []);
        endforeach;
        ?>
        <?php endif; ?>
        <!-- ============================================================ -->
        <!-- SEARCH VIEW, TRACK VIEW, AUDIT VIEW, HOLDS VIEW, INVOICES VIEW, REPORTS VIEW -->
        <!-- These blocks were marked "omitted for brevity" in the file you -->
        <!-- pasted — I never saw their real markup, so I'm not inventing it -->
        <!-- here. $searchResults, $transactionDetail, $transactionTimeline, -->
        <!-- $recentAuditLogs, $recentHolds, $recentInvoices, and $reportData -->
        <!-- are all still computed above with the same ::int fix applied, -->
        <!-- and are ready to render — paste your actual markup for these -->
        <!-- views (or confirm they're unchanged in your real file) and I'll -->
        <!-- wire it to this corrected data layer. -->
        <!-- ============================================================ -->
    </main>
    <footer class="admin-footer">
        <p>VOUCHMORPH · Botswana · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>
</body>
</html>
