<?php
/**
 * settings.php - Organization Settings
 * Placeholder - Will be implemented later
 */
require_once 'auth.php';
$user = requireEnterpriseAuth();
$orgName = htmlspecialchars($user['organization_name'] ?? 'Organization');

$roleDisplay = strtoupper($user['role'] ?? 'USER');
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$canManageUsers = in_array($user['role'] ?? '', ['owner', 'it_manager_enterprise', 'it_officer_enterprise']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings · VOUCHMORPH</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            min-height: 100vh;
        }
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
        .header-left { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .logo { font-weight: 700; font-size: 18px; letter-spacing: 0.08em; text-transform: uppercase; }
        .logo span { color: #8A6D3B; }
        .role-badge { padding: 4px 14px; background: #8A6D3B; color: #0f172a; font-size: 10px; font-weight: 700; text-transform: uppercase; border-radius: 20px; }
        .user-info { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .user-name { font-weight: 600; color: #8A6D3B; font-size: 13px; }
        .user-role { font-size: 10px; color: #94a3b8; text-transform: uppercase; }
        .logout-btn { padding: 6px 16px; border: 2px solid #8A6D3B; color: #8A6D3B; text-decoration: none; font-size: 11px; font-weight: 600; text-transform: uppercase; border-radius: 20px; transition: all 0.15s; }
        .logout-btn:hover { background: #8A6D3B; color: #0f172a; }
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
        .nav-item.active { color: #0f172a; border-bottom-color: #8A6D3B; }
        .content { max-width: 1400px; margin: 0 auto; padding: 24px 32px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .page-header h1 { font-size: 24px; font-weight: 700; }
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
        }
        .setting-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            transition: all 0.15s;
        }
        .setting-card:hover { border-color: #8A6D3B; }
        .setting-card .icon { font-size: 32px; margin-bottom: 12px; }
        .setting-card h3 { font-size: 16px; font-weight: 600; margin-bottom: 4px; }
        .setting-card p { color: #64748b; font-size: 13px; }
        .setting-card .btn {
            margin-top: 12px;
            padding: 8px 20px;
            background: #0f172a;
            color: #fff;
            border: none;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-block;
        }
        .setting-card .btn:hover { background: #8A6D3B; }
        .footer {
            background: #0f172a;
            color: #94a3b8;
            padding: 16px 32px;
            text-align: center;
            font-size: 11px;
            border-top: 2px solid #8A6D3B;
            margin-top: 24px;
        }
        @media (max-width: 768px) {
            .header { padding: 12px 16px; }
            .nav { padding: 0 16px; gap: 16px; }
            .content { padding: 16px; }
            .settings-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>·</span> <?php echo htmlspecialchars($orgName); ?></div>
            <span class="role-badge"><?php echo $roleDisplay; ?></span>
        </div>
        <div class="user-info">
            <div>
                <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                <div class="user-role"><?php echo $roleDisplay; ?></div>
            </div>
            <a href="logout.php" class="logout-btn">Sign Out</a>
        </div>
    </header>

    <nav class="nav">
        <a href="index.php" class="nav-item">📊 Dashboard</a>
        <a href="imports/review_batch.php?status=all" class="nav-item">📋 Batches</a>
        <a href="beneficiaries.php" class="nav-item">👥 Beneficiaries</a>
        <a href="reports.php" class="nav-item">📈 Reports</a>
        <a href="settings.php" class="nav-item active">⚙️ Settings</a>
    </nav>

    <main class="content">
        <div class="page-header">
            <h1>⚙️ Settings</h1>
        </div>

        <div class="settings-grid">
            <?php if ($canManageUsers): ?>
            <div class="setting-card">
                <div class="icon">👤</div>
                <h3>User Management</h3>
                <p>Manage organization users, roles, and permissions.</p>
                <a href="settings/users.php" class="btn">Manage Users</a>
            </div>
            <?php endif; ?>

            <div class="setting-card">
                <div class="icon">🏦</div>
                <h3>Source Accounts</h3>
                <p>Manage your organization's source accounts for disbursements.</p>
                <a href="imports/add_source.php" class="btn">Manage Sources</a>
            </div>

            <div class="setting-card">
                <div class="icon">📋</div>
                <h3>Organization Profile</h3>
                <p>View and update organization details and preferences.</p>
                <a href="#" class="btn" style="opacity:0.5; pointer-events:none;">Coming Soon</a>
            </div>

            <div class="setting-card">
                <div class="icon">🔐</div>
                <h3>Security Settings</h3>
                <p>Configure security preferences and audit logs.</p>
                <a href="#" class="btn" style="opacity:0.5; pointer-events:none;">Coming Soon</a>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div>VOUCHMORPH · Enterprise Disbursement Platform · <?php echo date('Y'); ?></div>
    </footer>
</body>
</html>
