<?php
// index.php - ENTERPRISE COMMAND CENTER DASHBOARD
// ROLE-BASED VIEWS: owner, program_officer, approver, auditor, viewer
require_once 'auth.php';
$user = requireEnterpriseAuth();

$pdo = getDBConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';
$departmentId = $user['department_id'] ?? null;

// ============================================================
// ROLE-BASED DATA FETCHING
// ============================================================

// Base query conditions based on role
$roleFilter = '';
$roleParams = [':org_id' => $orgId];

// Department scoping for non-owner roles
if (!in_array($userRole, ['owner', 'auditor'])) {
    $roleFilter = ' AND department_id = :dept_id ';
    $roleParams[':dept_id'] = $departmentId;
}

try {
    // This month's disbursements (role-scoped)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(total_amount), 0) as amount,
            COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) as completed_amount
        FROM import_batches 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute($roleParams);
    $currentMonth = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'amount' => 0, 'completed_amount' => 0];

    // Pending approvals (role-scoped)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as amount 
        FROM import_batches 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        AND status = 'READY_FOR_APPROVAL'
    ");
    $stmt->execute($roleParams);
    $pendingApproval = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'amount' => 0];

    // Recent batches (role-scoped)
    $stmt = $pdo->prepare("
        SELECT * FROM import_batches 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        ORDER BY created_at DESC LIMIT 8
    ");
    $stmt->execute($roleParams);
    $recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Beneficiaries (role-scoped)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM organization_beneficiaries 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        AND is_active = true
    ");
    $stmt->execute($roleParams);
    $beneficiaryCount = $stmt->fetchColumn() ?: 0;

    // Success rate
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END), 0) as completed
        FROM import_batches 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute($roleParams);
    $successData = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'completed' => 0];
    $successRate = $successData['total'] > 0 ? round(($successData['completed'] / $successData['total']) * 100, 2) : 0;

    $stats = [
        'disbursed' => $currentMonth['amount'],
        'success_rate' => $successRate,
        'pending' => $pendingApproval['count'],
        'beneficiaries' => $beneficiaryCount,
        'successful_payments' => $successData['completed']
    ];
    
} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats = ['disbursed' => 0, 'success_rate' => 0, 'pending' => 0, 'beneficiaries' => 0, 'successful_payments' => 0];
    $recentBatches = [];
}

// ============================================================
// ROLE-BASED UI CONFIGURATION
// ============================================================

$roleConfigs = [
    'owner' => [
        'title' => '🏛 Executive Command Center',
        'subtitle' => 'Full organizational oversight',
        'show_actions' => true,
        'show_beneficiaries' => true,
        'show_templates' => true,
        'show_reports' => true,
        'show_settings' => true,
        'show_department_filter' => true,
        'show_all_batches' => true,
        'badge_color' => 'gold'
    ],
    'auditor' => [
        'title' => '📋 Audit & Compliance Dashboard',
        'subtitle' => 'Read-only oversight of all departments',
        'show_actions' => false,
        'show_beneficiaries' => true,
        'show_templates' => false,
        'show_reports' => true,
        'show_settings' => false,
        'show_department_filter' => true,
        'show_all_batches' => true,
        'badge_color' => 'blue'
    ],
    'approver' => [
        'title' => '✅ Approval Dashboard',
        'subtitle' => 'Review and approve pending disbursements',
        'show_actions' => false,
        'show_beneficiaries' => false,
        'show_templates' => false,
        'show_reports' => true,
        'show_settings' => false,
        'show_department_filter' => true,
        'show_all_batches' => true,
        'badge_color' => 'green',
        'pending_count' => $stats['pending']
    ],
    'senior_approver' => [
        'title' => '🔒 Senior Approval Dashboard',
        'subtitle' => 'High-value disbursement approvals',
        'show_actions' => false,
        'show_beneficiaries' => false,
        'show_templates' => false,
        'show_reports' => true,
        'show_settings' => false,
        'show_department_filter' => true,
        'show_all_batches' => true,
        'badge_color' => 'purple',
        'pending_count' => $stats['pending']
    ],
    'program_officer' => [
        'title' => '📤 Disbursement Officer Dashboard',
        'subtitle' => 'Create and manage disbursements',
        'show_actions' => true,
        'show_beneficiaries' => true,
        'show_templates' => true,
        'show_reports' => true,
        'show_settings' => false,
        'show_department_filter' => true,
        'show_all_batches' => true,
        'badge_color' => 'orange'
    ],
    'beneficiary_registrar' => [
        'title' => '👥 Beneficiary Management',
        'subtitle' => 'Manage beneficiary records',
        'show_actions' => false,
        'show_beneficiaries' => true,
        'show_templates' => false,
        'show_reports' => false,
        'show_settings' => false,
        'show_department_filter' => true,
        'show_all_batches' => false,
        'badge_color' => 'teal'
    ],
    'viewer' => [
        'title' => '📊 View-Only Dashboard',
        'subtitle' => 'Read-only access to departmental data',
        'show_actions' => false,
        'show_beneficiaries' => true,
        'show_templates' => false,
        'show_reports' => true,
        'show_settings' => false,
        'show_department_filter' => true,
        'show_all_batches' => true,
        'badge_color' => 'gray'
    ],
    'department_head' => [
        'title' => '📋 Department Dashboard',
        'subtitle' => 'Oversee departmental disbursements',
        'show_actions' => true,
        'show_beneficiaries' => true,
        'show_templates' => true,
        'show_reports' => true,
        'show_settings' => false,
        'show_department_filter' => true,
        'show_all_batches' => true,
        'badge_color' => 'indigo'
    ]
];

// Get config for current role
$config = $roleConfigs[$userRole] ?? $roleConfigs['viewer'];

// Department name for display
$departmentName = '';
if ($departmentId) {
    $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = :id AND organization_id = :org_id");
    $stmt->execute([':id' => $departmentId, ':org_id' => $orgId]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    $departmentName = $dept['name'] ?? '';
}

// Role display name
$roleDisplay = ucwords(str_replace('_', ' ', $userRole));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $config['title']; ?> - VouchMorph</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ... (same styles as before) ... */
        /* Add role-specific styles */
        .role-badge-owner { background: var(--accent-500); color: var(--primary-900); }
        .role-badge-auditor { background: var(--info); color: white; }
        .role-badge-approver { background: var(--success); color: white; }
        .role-badge-program_officer { background: #f97316; color: white; }
        .role-badge-viewer { background: var(--surface-500); color: white; }
        .role-badge-department_head { background: #6366f1; color: white; }
        .role-badge-beneficiary_registrar { background: #14b8a6; color: white; }
        .role-badge-senior_approver { background: #8b5cf6; color: white; }
        
        .role-badge {
            padding: 2px 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 0;
        }
        
        .dept-tag {
            display: inline-block;
            padding: 2px 10px;
            background: var(--surface-200);
            color: var(--surface-600);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-radius: 0;
            margin-left: 8px;
        }
    </style>
</head>
<body>
<div class="app">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">VM</div>
                <div>
                    <h2>VouchMorph <span>Enterprise</span></h2>
                    <div class="org-badge"><?php echo htmlspecialchars($user['organization_name'] ?? 'Government'); ?></div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="index.php" class="nav-item active">
                <span class="icon">⬛</span>
                <span>Command Center</span>
            </a>
            
            <?php if ($config['show_actions']): ?>
            <a href="imports/upload.php" class="nav-item">
                <span class="icon">▣</span>
                <span>New Disbursement</span>
            </a>
            <?php endif; ?>
            
            <a href="batches/index.php" class="nav-item">
                <span class="icon">▦</span>
                <span>Batches</span>
                <?php if ($stats['pending'] > 0 && in_array($userRole, ['approver', 'senior_approver', 'owner'])): ?>
                    <span class="badge"><?php echo $stats['pending']; ?></span>
                <?php endif; ?>
            </a>
            
            <?php if ($config['show_beneficiaries']): ?>
            <a href="beneficiaries/index.php" class="nav-item">
                <span class="icon">◈</span>
                <span>Beneficiaries</span>
            </a>
            <?php endif; ?>

            <?php if ($config['show_templates']): ?>
            <div class="nav-label" style="margin-top: 16px;">Management</div>
            <a href="templates/index.php" class="nav-item">
                <span class="icon">▤</span>
                <span>Templates</span>
            </a>
            <?php endif; ?>
            
            <?php if ($config['show_reports']): ?>
            <a href="reports/index.php" class="nav-item">
                <span class="icon">▥</span>
                <span>Reports</span>
            </a>
            <?php endif; ?>
            
            <?php if ($config['show_settings']): ?>
            <a href="settings/index.php" class="nav-item">
                <span class="icon">◆</span>
                <span>Settings</span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 1)); ?></div>
                <div class="user-info">
                    <div class="name"><?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></div>
                    <div class="role">
                        <span class="role-badge role-badge-<?php echo str_replace('_', '-', $userRole); ?>"><?php echo $roleDisplay; ?></span>
                        <?php if ($departmentName): ?>
                        <span class="dept-tag"><?php echo htmlspecialchars($departmentName); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="logout.php" style="color: var(--surface-400); text-decoration: none; font-size: 18px; border-left: 2px solid var(--surface-400); padding-left: 12px;">↗</a>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="greeting">
                <h1><?php echo $config['title']; ?></h1>
                <p>
                    <?php echo htmlspecialchars($user['organization_name']); ?>
                    <?php if ($departmentName): ?>
                    • <?php echo htmlspecialchars($departmentName); ?>
                    <?php endif; ?>
                    • <?php echo date('l, F j, Y'); ?>
                    <span style="color: var(--surface-400); font-size: 12px; margin-left: 12px;">
                        <?php echo $config['subtitle']; ?>
                    </span>
                </p>
            </div>
            <div class="actions">
                <span class="datetime">⏱ <?php echo date('H:i T'); ?></span>
                <button class="btn-icon" title="Notifications" onclick="alert('No new notifications')">◉</button>
                <button class="btn-icon" title="Refresh" onclick="location.reload()">⟳</button>
            </div>
        </div>

        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon blue">⧫</div>
                    </div>
                    <div class="stat-value">BWP <?php echo number_format($stats['disbursed'], 2); ?></div>
                    <div class="stat-label">Disbursed This Month</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon green">◈</div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['success_rate'], 2); ?>%</div>
                    <div class="stat-label">Success Rate • <?php echo number_format($stats['successful_payments']); ?> payments</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon yellow">◉</div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
                    <div class="stat-label">Pending Approval • Awaiting review</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon purple">◊</div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['beneficiaries']); ?></div>
                    <div class="stat-label">Active Beneficiaries</div>
                </div>
            </div>

            <!-- Quick Actions - Only show for roles that can create -->
            <?php if ($config['show_actions']): ?>
            <div class="quick-actions">
                <a href="imports/upload.php" class="action-btn gold">▣ New Disbursement</a>
                <a href="beneficiaries/import.php" class="action-btn secondary">◈ Import Beneficiaries</a>
                <a href="templates/index.php" class="action-btn secondary">▤ Templates</a>
                <a href="reports/index.php" class="action-btn secondary">▥ Reports</a>
            </div>
            <?php endif; ?>

            <!-- Recent Batches -->
            <div class="card">
                <div class="card-header">
                    <h3>▦ Recent Disbursements</h3>
                    <?php if ($config['show_all_batches']): ?>
                    <a href="batches/index.php">View All ▸</a>
                    <?php endif; ?>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentBatches)): ?>
                                <?php foreach ($recentBatches as $batch): 
                                    $status = strtolower($batch['status'] ?? 'draft');
                                    $statusDisplay = ucwords(str_replace('_', ' ', $batch['status'] ?? 'Draft'));
                                ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($batch['batch_reference'] ?? '#' . $batch['id']); ?></code></td>
                                    <td><?php echo htmlspecialchars($batch['batch_name'] ?? 'Batch #' . $batch['id']); ?></td>
                                    <td><strong>BWP <?php echo number_format($batch['total_amount'] ?? 0, 2); ?></strong></td>
                                    <td>
                                        <span class="status <?php echo $status; ?>">
                                            <span class="dot"></span>
                                            <?php echo htmlspecialchars($statusDisplay); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($batch['created_at'] ?? 'now')); ?></td>
                                    <td>
                                        <a href="batches/view.php?id=<?php echo $batch['id']; ?>" class="view-link">
                                            <?php if ($userRole === 'approver' || $userRole === 'senior_approver'): ?>
                                                Approve ▸
                                            <?php elseif ($userRole === 'auditor'): ?>
                                                Audit ▸
                                            <?php else: ?>
                                                Review ▸
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <span class="icon">▢</span>
                                            <h4>No disbursements yet</h4>
                                            <p>Start by creating your first payment batch.</p>
                                            <?php if ($config['show_actions']): ?>
                                            <a href="imports/upload.php">Create New Disbursement ▸</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Role-Specific Widgets -->
            <?php if ($userRole === 'approver' || $userRole === 'senior_approver'): ?>
            <div class="card" style="border-color: var(--warning);">
                <div class="card-header" style="background: var(--warning-bg); border-bottom-color: var(--warning);">
                    <h3 style="color: var(--warning);">⏳ Pending Approval Queue</h3>
                    <span style="font-size: 14px; font-weight: 700;"><?php echo $stats['pending']; ?> batches waiting</span>
                </div>
                <div style="padding: 16px 24px;">
                    <p style="color: var(--surface-500); font-size: 14px;">
                        <?php if ($stats['pending'] > 0): ?>
                            You have <strong><?php echo $stats['pending']; ?></strong> batches awaiting your review.
                            Please review each batch carefully before approval.
                        <?php else: ?>
                            ✅ All batches have been reviewed. No pending approvals.
                        <?php endif; ?>
                    </p>
                    <?php if ($stats['pending'] > 0): ?>
                    <a href="batches/index.php?filter=pending" class="action-btn gold" style="margin-top: 12px; display: inline-block;">
                        Review Pending Batches ▸
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($userRole === 'auditor'): ?>
            <div class="card" style="border-color: var(--info);">
                <div class="card-header" style="background: var(--info-bg); border-bottom-color: var(--info);">
                    <h3 style="color: var(--info);">📋 Audit Summary</h3>
                    <span style="font-size: 14px; font-weight: 700;"><?php echo date('Y-m-d'); ?></span>
                </div>
                <div style="padding: 16px 24px;">
                    <p style="color: var(--surface-500); font-size: 14px;">
                        🔍 All departmental disbursements are visible for audit purposes.
                        <br>Total disbursements this month: <strong>BWP <?php echo number_format($stats['disbursed'], 2); ?></strong>
                    </p>
                    <a href="reports/audit_trail.php" class="action-btn secondary" style="margin-top: 12px; display: inline-block;">
                        View Audit Trail ▸
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div style="text-align: center; padding: 16px 0; color: var(--surface-400); font-size: 11px; border-top: 2px solid var(--surface-200); letter-spacing: 0.5px; text-transform: uppercase;">
                <span>■ SECURE ENTERPRISE PLATFORM • ISO 27001 CERTIFIED</span>
                <span style="margin: 0 12px;">■</span>
                <span>© <?php echo date('Y'); ?> VOUCHMORPH • GOVERNMENT OF BOTSWANA</span>
                <br>
                <span style="font-size: 10px; letter-spacing: 1px;">
                    ROLE: <?php echo strtoupper($userRole); ?> 
                    <?php if ($departmentName): ?>
                    • DEPT: <?php echo strtoupper($departmentName); ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </main>
</div>
</body>
</html>
