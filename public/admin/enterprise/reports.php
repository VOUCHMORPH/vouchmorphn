<?php
/**
 * reports.php - Reports
 * Placeholder - Will be implemented later
 */
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
$orgName = htmlspecialchars($user['organization_name'] ?? 'Organization');

$roleDisplay = strtoupper($user['role'] ?? 'USER');
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports · VOUCHMORPH</title>
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
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
        }
        .card .icon { font-size: 64px; margin-bottom: 16px; }
        .card h2 { font-size: 20px; margin-bottom: 8px; }
        .card p { color: #64748b; max-width: 500px; margin: 0 auto; }
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
        <a href="reports.php" class="nav-item active">📈 Reports</a>
        <a href="settings.php" class="nav-item">⚙️ Settings</a>
    </nav>

    <main class="content">
        <div class="page-header">
            <h1>📈 Reports</h1>
        </div>

        <div class="card">
            <div class="icon">📈</div>
            <h2>Reports & Analytics</h2>
            <p>This module is under development. Reports will be available soon.</p>
            <p style="margin-top: 8px; font-size: 13px; color: #94a3b8;">
                Check back later for disbursement reports, audit trails, and analytics.
            </p>
        </div>
    </main>

    <footer class="footer">
        <div>VOUCHMORPH · Enterprise Disbursement Platform · <?php echo date('Y'); ?></div>
    </footer>
</body>
</html>
