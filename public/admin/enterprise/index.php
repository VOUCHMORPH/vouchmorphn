<?php
/**
 * enterprise/index.php - VouchMorph Enterprise Client Dashboard
 * 
 * This is the main dashboard for organizations using VouchMorph.
 * Different roles see different views based on their permissions.
 */

require_once 'auth.php';
$user = requireEnterpriseAuth();
$pdo = getDBConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization';
$departmentId = $user['department_id'] ?? null;

// ============================================================
// ROLE PERMISSIONS
// ============================================================
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head']);
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise']);
$canDisburse = in_array($userRole, ['owner', 'supervisor', 'it_manager_enterprise']);
$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise']);
$canViewAll = in_array($userRole, ['owner', 'auditor', 'it_manager_enterprise', 'it_officer_enterprise']);
$isReadOnly = in_array($userRole, ['auditor', 'viewer', 'it_support']);
$isApprover = in_array($userRole, ['approver', 'senior_approver']);
$isSupervisor = in_array($userRole, ['supervisor']);
$isLoader = in_array($userRole, ['program_officer', 'department_head']);

// ============================================================
// HELPER: Check if user can edit a batch
// ============================================================
function canEditBatch($batchCreatedBy, $currentUserId, $userRole) {
    // Owner can edit everything
    if ($userRole === 'owner') return true;
    // Loaders can only edit their own batches
    if (in_array($userRole, ['program_officer', 'department_head'])) {
        return $batchCreatedBy == $currentUserId;
    }
    // Everyone else cannot edit
    return false;
}

// ============================================================
// FETCH DASHBOARD DATA
// ============================================================

// Organization details
$orgData = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, name, tax_id, registration_number, country_code, 
               default_currency, status, logo_url, created_at
        FROM organizations 
        WHERE id = :org_id
    ");
    $stmt->execute([':org_id' => $orgId]);
    $orgData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] Org fetch error: " . $e->getMessage());
}

// Dashboard metrics
$metrics = [];
$recentBatches = [];

try {
    // Base query filters
    $deptFilter = ($userRole === 'department_head' && $departmentId) ? "AND department_id = :dept_id" : "";
    $params = [':org_id' => $orgId];
    if ($deptFilter) {
        $params[':dept_id'] = $departmentId;
    }

    // Total batches
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM disbursement_batches 
        WHERE organization_id = :org_id $deptFilter
    ");
    $stmt->execute($params);
    $metrics['total_batches'] = (int)$stmt->fetchColumn();

    // Batches by status
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM disbursement_batches 
        WHERE organization_id = :org_id $deptFilter
        GROUP BY status
    ");
    $stmt->execute($params);
    $batchStatus = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = strtolower($row['status']);
        $batchStatus[$status] = $row['count'];
    }
    $metrics['pending_batches'] = $batchStatus['pending'] ?? $batchStatus['pending_approval'] ?? 0;
    $metrics['approved_batches'] = $batchStatus['approved'] ?? 0;
    $metrics['executed_batches'] = $batchStatus['executed'] ?? $batchStatus['completed'] ?? 0;
    $metrics['rejected_batches'] = $batchStatus['rejected'] ?? 0;
    
    // Total disbursed amount
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM disbursement_batches 
        WHERE organization_id = :org_id $deptFilter
        AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')
    ");
    $stmt->execute($params);
    $metrics['total_disbursed'] = (float)$stmt->fetchColumn();

    // Total beneficiaries
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM organization_beneficiaries 
        WHERE organization_id = :org_id AND is_active = true
    ");
    $stmt->execute([':org_id' => $orgId]);
    $metrics['total_beneficiaries'] = (int)$stmt->fetchColumn();

    // Total users
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM organization_users 
        WHERE organization_id = :org_id AND is_active = true
    ");
    $stmt->execute([':org_id' => $orgId]);
    $metrics['total_users'] = (int)$stmt->fetchColumn();

    // For supervisors - approved batches ready for disbursement
    if ($canDisburse) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM disbursement_batches 
            WHERE organization_id = :org_id 
            AND status = 'approved'
        ");
        $stmt->execute([':org_id' => $orgId]);
        $metrics['approved_for_disbursement'] = (int)$stmt->fetchColumn();
    }

    // For approvers - pending approvals (show all pending)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM disbursement_batches 
        WHERE organization_id = :org_id 
        AND status IN ('pending', 'pending_approval', 'PENDING', 'PENDING_APPROVAL')
    ");
    $stmt->execute([':org_id' => $orgId]);
    $metrics['pending_approvals'] = (int)$stmt->fetchColumn();
    
    // ============================================================
    // Recent batches with status-based filtering
    // ============================================================
    $statusFilter = "";
    $statusParams = [':org_id' => $orgId];

    // Different roles see different batches
    if ($isReadOnly) {
        // Auditors/viewers see completed only
        $statusFilter = "AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')";
    } elseif ($isApprover) {
        // Approvers see pending, approved, and draft
        $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'draft', 'PENDING', 'PENDING_APPROVAL', 'APPROVED')";
    } elseif ($isSupervisor) {
        // Supervisors see approved and completed
        $statusFilter = "AND status IN ('approved', 'completed', 'executed', 'APPROVED', 'COMPLETED', 'EXECUTED')";
    } elseif ($isLoader) {
        // Loaders see ALL their batches + pending + approved + draft
        $statusFilter = "AND (created_by = :user_id OR status IN ('pending', 'pending_approval', 'approved', 'draft'))";
        $statusParams[':user_id'] = $userId;
    }

    $stmt = $pdo->prepare("
        SELECT 
            id, batch_reference, batch_name, source_institution,
            total_amount, total_destinations, status, created_at,
            updated_at, created_by, department_id
        FROM disbursement_batches 
        WHERE organization_id = :org_id $statusFilter
        ORDER BY 
            CASE 
                WHEN status IN ('pending', 'pending_approval') THEN 1
                WHEN status = 'approved' THEN 2
                WHEN status = 'draft' THEN 3
                ELSE 4
            END,
            created_at DESC 
        LIMIT 15
    ");
    $stmt->execute($statusParams);
    $recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = array_fill_keys([
        'total_batches', 'pending_batches', 'approved_batches', 
        'executed_batches', 'total_disbursed', 'total_beneficiaries',
        'total_users', 'pending_approvals'
    ], 0);
    $recentBatches = [];
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount, $currency = 'BWP') {
    return number_format((float)$amount, 2) . ' ' . $currency;
}

function getStatusClass($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => 'draft',
        'pending', 'pending_approval' => 'pending',
        'approved' => 'approved',
        'completed', 'executed' => 'completed',
        'rejected' => 'rejected',
        'cancelled' => 'rejected',
        default => 'draft'
    };
}

function getStatusLabel($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => '📝 Draft',
        'pending', 'pending_approval' => '⏳ Pending',
        'approved' => '✅ Approved',
        'completed' => '✔️ Completed',
        'executed' => '🚀 Executed',
        'rejected' => '❌ Rejected',
        'cancelled' => '🚫 Cancelled',
        default => ucfirst($status)
    };
}

function getStatusAction($status) {
    $status = strtolower($status);
    return match($status) {
        'pending', 'pending_approval' => 'Approve',
        'approved' => 'Disburse',
        'draft' => 'Submit',
        default => 'View'
    };
}

function getRoleLabel($role) {
    $labels = [
        'owner' => 'Owner',
        'it_manager_enterprise' => 'IT Manager',
        'it_officer_enterprise' => 'IT Officer',
        'it_support' => 'IT Support',
        'department_head' => 'Department Head',
        'program_officer' => 'Program Officer',
        'approver' => 'Approver',
        'senior_approver' => 'Senior Approver',
        'supervisor' => 'Supervisor',
        'beneficiary_registrar' => 'Beneficiary Registrar',
        'auditor' => 'Auditor',
        'viewer' => 'Viewer'
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Enterprise Dashboard · <?php echo safeHtml($orgName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            min-height: 100vh;
        }

        /* ===== HEADER ===== */
        .header {
            background: #0f172a;
            color: #fff;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 3px solid #8A6D3B;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .logo {
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .logo span { color: #8A6D3B; }
        .org-name {
            font-size: 13px;
            color: #94a3b8;
            padding-left: 16px;
            border-left: 1px solid rgba(255,255,255,0.1);
        }
        .role-badge {
            padding: 4px 14px;
            background: #8A6D3B;
            color: #0f172a;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-radius: 20px;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .user-details {
            text-align: right;
        }
        .user-name {
            font-weight: 600;
            color: #8A6D3B;
            font-size: 13px;
        }
        .user-role {
            font-size: 10px;
            color: #94a3b8;
            text-transform: uppercase;
        }
        .logout-btn {
            padding: 6px 16px;
            border: 2px solid #8A6D3B;
            color: #8A6D3B;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 20px;
            transition: all 0.15s;
        }
        .logout-btn:hover {
            background: #8A6D3B;
            color: #0f172a;
        }

        /* ===== READ ONLY BADGE ===== */
        .readonly-badge {
            display: inline-block;
            padding: 1px 8px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 10px;
            font-size: 8px;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid #f59e0b;
            margin-left: 4px;
            vertical-align: middle;
        }

        /* ===== NAVIGATION ===== */
        .nav {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 32px;
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: 12px 0;
            color: #64748b;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid transparent;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .nav-item:hover { color: #0f172a; }
        .nav-item.active {
            color: #0f172a;
            border-bottom-color: #8A6D3B;
        }
        .nav-item.primary { color: #0f172a; }
        .nav-item.primary:hover { color: #8A6D3B; }
        .nav-item.primary.active {
            color: #8A6D3B;
            border-bottom-color: #8A6D3B;
        }
        .nav-item .badge {
            background: #ef4444;
            color: #fff;
            font-size: 9px;
            padding: 1px 8px;
            border-radius: 12px;
            margin-left: 4px;
        }
        .nav-item .badge-gold {
            background: #8A6D3B;
            color: #fff;
            font-size: 9px;
            padding: 1px 8px;
            border-radius: 12px;
            margin-left: 4px;
        }

        /* ===== CONTENT ===== */
        .content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px 32px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
        }
        .page-header .sub {
            color: #64748b;
            font-size: 14px;
        }
        .page-header .timestamp {
            color: #94a3b8;
            font-size: 12px;
        }

        /* ===== METRICS ===== */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .metric-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            transition: border-color 0.15s;
        }
        .metric-card:hover {
            border-color: #8A6D3B;
        }
        .metric-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #94a3b8;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .metric-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 4px;
        }
        .metric-value .currency {
            font-size: 14px;
            color: #94a3b8;
            font-weight: 400;
        }
        .metric-sub {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 2px;
        }

        /* ===== CARDS ===== */
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 16px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 8px;
        }
        .card-title {
            font-size: 15px;
            font-weight: 700;
        }
        .card-badge {
            padding: 2px 12px;
            background: #0f172a;
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            border-radius: 20px;
        }
        .card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* ===== TABLES ===== */
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: #f8fafc;
            color: #64748b;
            padding: 10px 14px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }
        td {
            padding: 10px 14px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        tr:hover { background: #f8fafc; }

        /* ===== STATUS BADGES ===== */
        .status {
            display: inline-block;
            padding: 2px 10px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 20px;
            letter-spacing: 0.04em;
        }
        .status-draft { background: #f1f5f9; color: #64748b; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fee2e2; color: #991b1b; }

        /* ===== BUTTONS ===== */
        .btn {
            padding: 6px 16px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { opacity: 0.85; transform: translateY(-1px); }
        .btn-primary {
            background: #0f172a;
            color: #fff;
        }
        .btn-primary:hover {
            background: #8A6D3B;
        }
        .btn-success {
            background: #166534;
            color: #fff;
        }
        .btn-success:hover {
            background: #14532d;
        }
        .btn-warning {
            background: #92400e;
            color: #fff;
        }
        .btn-warning:hover {
            background: #78350f;
        }
        .btn-outline {
            background: transparent;
            border: 1px solid #e2e8f0;
            color: #64748b;
        }
        .btn-outline:hover {
            border-color: #0f172a;
            color: #0f172a;
        }
        .btn-sm { padding: 4px 12px; font-size: 11px; }
        .btn-disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* ===== QUICK ACTIONS ===== */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .quick-action {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            text-decoration: none;
            color: #0f172a;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .quick-action:hover {
            border-color: #8A6D3B;
            background: #f8fafc;
            transform: translateY(-2px);
        }
        .quick-action .icon { font-size: 24px; }
        .quick-action .label {
            font-size: 13px;
            font-weight: 600;
        }
        .quick-action .desc {
            font-size: 11px;
            color: #94a3b8;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        .empty-state .icon { font-size: 40px; margin-bottom: 8px; }
        .empty-state p { font-size: 14px; }

        /* ===== FOOTER ===== */
        .footer {
            background: #0f172a;
            color: #94a3b8;
            padding: 16px 32px;
            text-align: center;
            font-size: 11px;
            border-top: 2px solid #8A6D3B;
            margin-top: 24px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .header { padding: 12px 16px; }
            .nav { padding: 0 16px; gap: 16px; }
            .content { padding: 16px; }
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .quick-actions { grid-template-columns: 1fr; }
            .table-responsive { font-size: 11px; }
            th, td { padding: 6px 8px; }
        }
    </style>
</head>
<body>
    <!-- ============================================================ -->
    <!-- HEADER -->
    <!-- ============================================================ -->
    <header class="header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>·</span> <?php echo safeHtml($orgName); ?></div>
            <span class="role-badge"><?php echo safeHtml(getRoleLabel($userRole)); ?></span>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo safeHtml($fullName); ?></div>
                <div class="user-role"><?php echo safeHtml(getRoleLabel($userRole)); ?> · <?php echo safeHtml($orgName); ?></div>
            </div>
            <a href="logout.php" class="logout-btn">Sign Out</a>
        </div>
    </header>

    <!-- ============================================================ -->
    <!-- NAVIGATION -->
    <!-- ============================================================ -->
    <nav class="nav">
        <a href="index.php" class="nav-item active">📊 Dashboard</a>
        
        <?php if ($canCreate): ?>
        <a href="imports/source_input.php" class="nav-item primary">💰 New Disbursement</a>
        <?php endif; ?>
        
        <a href="imports/review_batch.php?status=all" class="nav-item">
            📋 Batches
            <?php if ($canApprove && ($metrics['pending_approvals'] ?? 0) > 0): ?>
            <span class="badge"><?php echo $metrics['pending_approvals']; ?></span>
            <?php endif; ?>
            <?php if ($canDisburse && ($metrics['approved_for_disbursement'] ?? 0) > 0): ?>
            <span class="badge-gold"><?php echo $metrics['approved_for_disbursement']; ?></span>
            <?php endif; ?>
        </a>
        
        <?php if ($canApprove): ?>
        <a href="imports/review_batch.php?status=pending_approval" class="nav-item">⏳ Pending Approvals
            <?php if (($metrics['pending_approvals'] ?? 0) > 0): ?>
            <span class="badge"><?php echo $metrics['pending_approvals']; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        
        <?php if ($canDisburse): ?>
        <a href="imports/review_batch.php?status=approved" class="nav-item">🚀 Disburse Funds
            <?php if (($metrics['approved_for_disbursement'] ?? 0) > 0): ?>
            <span class="badge-gold"><?php echo $metrics['approved_for_disbursement']; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        
        <a href="beneficiaries.php" class="nav-item">👥 Beneficiaries</a>
        
        <?php if ($canCreate || $userRole === 'beneficiary_registrar'): ?>
        <a href="imports/add_destinations.php" class="nav-item">📝 Add Destinations</a>
        <?php endif; ?>
        
        <a href="reports.php" class="nav-item">📈 Reports</a>
        
        <?php if ($canManageUsers): ?>
        <a href="settings/users.php" class="nav-item">👤 Manage Users</a>
        <?php endif; ?>
        
        <a href="settings.php" class="nav-item">⚙️ Settings</a>
    </nav>

    <!-- ============================================================ -->
    <!-- CONTENT -->
    <!-- ============================================================ -->
    <main class="content">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Dashboard</h1>
                <div class="sub">Welcome back, <?php echo safeHtml($fullName); ?></div>
            </div>
            <div class="timestamp"><?php echo date('l, F j, Y · H:i'); ?></div>
        </div>

        <!-- Quick Actions - Role Specific -->
        <div class="quick-actions">
            <?php if ($canCreate): ?>
            <a href="imports/source_input.php" class="quick-action">
                <span class="icon">💰</span>
                <div>
                    <div class="label">New Disbursement</div>
                    <div class="desc">Create a payment batch</div>
                </div>
            </a>
            <?php endif; ?>
            
            <?php if ($isApprover): ?>
            <a href="imports/review_batch.php?status=pending_approval" class="quick-action" style="border-color: #92400e;">
                <span class="icon">✅</span>
                <div>
                    <div class="label">Review & Approve</div>
                    <div class="desc"><?php echo ($metrics['pending_approvals'] ?? 0) . ' batches pending'; ?></div>
                </div>
            </a>
            <?php endif; ?>
            
            <?php if ($isSupervisor): ?>
            <a href="imports/review_batch.php?status=approved" class="quick-action" style="border-color: #166534;">
                <span class="icon">💸</span>
                <div>
                    <div class="label">Disburse Funds</div>
                    <div class="desc"><?php echo ($metrics['approved_for_disbursement'] ?? 0) . ' batches ready'; ?></div>
                </div>
            </a>
            <?php endif; ?>
            
            <?php if ($canCreate || $userRole === 'beneficiary_registrar'): ?>
            <a href="imports/add_destinations.php" class="quick-action">
                <span class="icon">👤</span>
                <div>
                    <div class="label">Add Beneficiaries</div>
                    <div class="desc">Import or add recipients</div>
                </div>
            </a>
            <?php endif; ?>
            
            <a href="beneficiaries.php" class="quick-action">
                <span class="icon">📋</span>
                <div>
                    <div class="label">View Beneficiaries</div>
                    <div class="desc"><?php echo number_format($metrics['total_beneficiaries'] ?? 0); ?> active records</div>
                </div>
            </a>
            
            <?php if ($canManageUsers): ?>
            <a href="settings/users.php" class="quick-action">
                <span class="icon">👥</span>
                <div>
                    <div class="label">Manage Users</div>
                    <div class="desc"><?php echo number_format($metrics['total_users'] ?? 0); ?> team members</div>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <!-- Metrics - Role Specific -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total Disbursed</div>
                <div class="metric-value">
                    <?php echo formatCurrency($metrics['total_disbursed'] ?? 0); ?>
                </div>
                <div class="metric-sub">Lifetime disbursements</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-label">Total Batches</div>
                <div class="metric-value"><?php echo number_format($metrics['total_batches'] ?? 0); ?></div>
                <div class="metric-sub">All time</div>
            </div>
            
            <?php if (($metrics['pending_batches'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: #92400e;">
                <div class="metric-label">Pending Batches</div>
                <div class="metric-value" style="color: #92400e;"><?php echo number_format($metrics['pending_batches'] ?? 0); ?></div>
                <div class="metric-sub">Waiting for approval</div>
            </div>
            <?php endif; ?>
            
            <?php if (($metrics['approved_batches'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: #1e40af;">
                <div class="metric-label">Approved</div>
                <div class="metric-value" style="color: #1e40af;"><?php echo number_format($metrics['approved_batches'] ?? 0); ?></div>
                <div class="metric-sub">Ready for disbursement</div>
            </div>
            <?php endif; ?>
            
            <?php if (($metrics['executed_batches'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: #166534;">
                <div class="metric-label">Completed</div>
                <div class="metric-value" style="color: #166534;"><?php echo number_format($metrics['executed_batches'] ?? 0); ?></div>
                <div class="metric-sub">Successfully executed</div>
            </div>
            <?php endif; ?>
            
            <?php if ($canApprove && ($metrics['pending_approvals'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: #dc2626; background: #fef2f2;">
                <div class="metric-label">Pending Approvals</div>
                <div class="metric-value" style="color: #dc2626;"><?php echo number_format($metrics['pending_approvals'] ?? 0); ?></div>
                <div class="metric-sub">Needs your review</div>
            </div>
            <?php endif; ?>
            
            <?php if ($canDisburse && ($metrics['approved_for_disbursement'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: #8A6D3B; background: #fdf6ed;">
                <div class="metric-label">Ready for Disbursement</div>
                <div class="metric-value" style="color: #8A6D3B;"><?php echo number_format($metrics['approved_for_disbursement'] ?? 0); ?></div>
                <div class="metric-sub">Approved batches</div>
            </div>
            <?php endif; ?>
            
            <div class="metric-card">
                <div class="metric-label">Beneficiaries</div>
                <div class="metric-value"><?php echo number_format($metrics['total_beneficiaries'] ?? 0); ?></div>
                <div class="metric-sub">Active recipients</div>
            </div>
        </div>

        <!-- Recent Batches -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Recent Batches</span>
                <span class="card-badge"><?php echo count($recentBatches); ?> RECENT</span>
                <div class="card-actions">
                    <a href="imports/review_batch.php?status=all" class="btn btn-outline btn-sm">View All</a>
                    <?php if ($canCreate): ?>
                    <a href="imports/source_input.php" class="btn btn-primary btn-sm">➕ New Batch</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (empty($recentBatches)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No batches found. Create your first disbursement batch to get started.</p>
                <?php if ($canCreate): ?>
                <a href="imports/source_input.php" class="btn btn-primary" style="margin-top:12px;">Create First Batch</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Name</th>
                            <th>Source</th>
                            <th>Amount</th>
                            <th>Destinations</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentBatches as $batch): 
                            $isOwnBatch = ($batch['created_by'] == $userId);
                            $canEdit = canEditBatch($batch['created_by'], $userId, $userRole);
                            $isReadOnlyBatch = $isLoader && !$isOwnBatch;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo safeHtml($batch['batch_reference']); ?></strong>
                                <?php if ($isReadOnlyBatch): ?>
                                <span class="readonly-badge">🔒 READ ONLY</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo safeHtml($batch['batch_name'] ?? '—'); ?></td>
                            <td><?php echo safeHtml($batch['source_institution'] ?? '—'); ?></td>
                            <td><strong><?php echo formatCurrency($batch['total_amount'] ?? 0); ?></strong></td>
                            <td><?php echo number_format($batch['total_destinations'] ?? 0); ?></td>
                            <td>
                                <span class="status status-<?php echo getStatusClass($batch['status']); ?>">
                                    <?php echo getStatusLabel($batch['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($batch['created_at'] ?? 'now')); ?></td>
                            <td><?php echo $isOwnBatch ? '👤 You' : 'Other'; ?></td>
                            <td>
                                <!-- View button - Everyone can view -->
                                <a href="imports/review_batch.php?batch_id=<?php echo $batch['id']; ?>" class="btn btn-outline btn-sm">View</a>
                                
                                <!-- Edit button - Only for OWN draft batches -->
                                <?php if ($canEdit && strtolower($batch['status']) === 'draft'): ?>
                                <a href="imports/add_destinations.php?batch_id=<?php echo $batch['id']; ?>" class="btn btn-primary btn-sm">✏️ Edit</a>
                                <?php endif; ?>
                                
                                <!-- Approve button - Only for Approvers -->
                                <?php if ($canApprove && in_array(strtolower($batch['status']), ['pending', 'pending_approval'])): ?>
                                <a href="imports/review_batch.php?batch_id=<?php echo $batch['id']; ?>&action=approve" class="btn btn-warning btn-sm">Approve</a>
                                <?php endif; ?>
                                
                                <!-- Disburse button - Only for Supervisors -->
                                <?php if ($canDisburse && strtolower($batch['status']) === 'approved'): ?>
                                <a href="imports/review_batch.php?batch_id=<?php echo $batch['id']; ?>&action=disburse" class="btn btn-success btn-sm">Disburse</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Role-specific info panels -->
        <?php if ($isReadOnly): ?>
        <div class="card" style="border-left: 3px solid #8A6D3B;">
            <div class="card-header">
                <span class="card-title">🔍 Read-Only Access</span>
            </div>
            <p style="color: #64748b; font-size: 14px;">
                You have <?php echo $userRole === 'auditor' ? 'auditor' : 'read-only'; ?> access. 
                You can view and export data but cannot create or modify any records.
                <?php if ($userRole === 'auditor'): ?>
                This is for compliance and audit purposes.
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if ($isLoader): ?>
        <div class="card" style="border-left: 3px solid #3b82f6;">
            <div class="card-header">
                <span class="card-title">📤 Loader Access</span>
            </div>
            <p style="color: #64748b; font-size: 14px;">
                You can view all batches in the system. 
                <strong>READ ONLY</strong> badges appear on batches you don't own.
                You can <strong>edit</strong> only the batches you created.
                <a href="imports/source_input.php" class="btn btn-primary btn-sm" style="margin-left:12px;">Create New Batch</a>
            </p>
        </div>
        <?php endif; ?>

        <?php if ($isApprover): ?>
        <div class="card" style="border-left: 3px solid #92400e;">
            <div class="card-header">
                <span class="card-title">✅ Approver Access</span>
            </div>
            <p style="color: #64748b; font-size: 14px;">
                You can review and approve pending disbursement batches.
                <?php if (($metrics['pending_approvals'] ?? 0) > 0): ?>
                <strong><?php echo $metrics['pending_approvals']; ?> batches awaiting your review.</strong>
                <?php endif; ?>
                <a href="imports/review_batch.php?status=pending_approval" class="btn btn-warning btn-sm" style="margin-left:12px;">Review Now</a>
            </p>
        </div>
        <?php endif; ?>

        <?php if ($isSupervisor): ?>
        <div class="card" style="border-left: 3px solid #166534;">
            <div class="card-header">
                <span class="card-title">💸 Supervisor Access</span>
            </div>
            <p style="color: #64748b; font-size: 14px;">
                You can disburse funds for approved batches.
                <?php if (($metrics['approved_for_disbursement'] ?? 0) > 0): ?>
                <strong><?php echo $metrics['approved_for_disbursement']; ?> batches ready for disbursement.</strong>
                <?php endif; ?>
                <a href="imports/review_batch.php?status=approved" class="btn btn-success btn-sm" style="margin-left:12px;">Disburse Funds</a>
            </p>
        </div>
        <?php endif; ?>

        <?php if ($userRole === 'beneficiary_registrar'): ?>
        <div class="card" style="border-left: 3px solid #166534;">
            <div class="card-header">
                <span class="card-title">👤 Beneficiary Registrar</span>
            </div>
            <p style="color: #64748b; font-size: 14px;">
                You can add and manage beneficiaries for disbursement batches.
                <a href="imports/add_destinations.php" class="btn btn-primary btn-sm" style="margin-left:12px;">Add Beneficiaries</a>
            </p>
        </div>
        <?php endif; ?>
    </main>

    <!-- ============================================================ -->
    <!-- FOOTER -->
    <!-- ============================================================ -->
    <footer class="footer">
        <div>VOUCHMORPH · Enterprise Disbursement Platform · <?php echo date('Y'); ?></div>
        <div style="margin-top:4px; color: rgba(255,255,255,0.2); font-size: 10px;">
            <?php echo safeHtml($orgName); ?> · Role: <?php echo safeHtml(getRoleLabel($userRole)); ?>
        </div>
    </footer>
</body>
</html>
