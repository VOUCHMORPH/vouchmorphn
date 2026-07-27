<?php
require_once __DIR__ . '/auth.php';
$admin = requirePlatformAdminAuth();

require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();

function safeHtmlPA($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Same scoping rule as isCountryInAdminScope(): super_admin or a blank
// country_code sees everything; any other admin with a country_code set
// only sees organizations in that country. Applied here generally (not
// just to config-capable roles) so a REGULATOR/COMPLIANCE/AUDITOR account
// that later gets a country_code assigned is scoped the same way.
$adminCountryScope = ($admin['role_name'] === 'super_admin' || empty($admin['country_code']))
    ? null
    : strtoupper($admin['country_code']);

$sql = "
    SELECT o.id, o.name, o.country_code, o.default_currency, o.status, o.created_at,
           (SELECT COUNT(*) FROM organization_users u WHERE u.organization_id = o.id AND u.is_active = true) AS active_users,
           (SELECT COUNT(*) FROM departments d WHERE d.organization_id = o.id AND d.status = 'active') AS active_departments
    FROM organizations o
";
$params = [];
if ($adminCountryScope !== null) {
    $sql .= " WHERE o.country_code = :country_scope";
    $params[':country_scope'] = $adminCountryScope;
}
$sql .= " ORDER BY o.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Platform Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #EEF1EF; --panel: #FFFFFF; --ink-900: #0F2138; --ink-500: #4A5A6E; --ink-300: #8A96A3;
            --line: #D3DAD6; --brass: #8A6D3B; --seal-red: #7A2118; --ledger-green: #24513A; --green-tint: #E5EEE7;
            --danger-bg: #fbeceb; --danger: #b3261e;
            --f-body: 'IBM Plex Sans', sans-serif; --f-cond: 'IBM Plex Sans Condensed', sans-serif; --f-mono: 'IBM Plex Mono', monospace;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); min-height:100vh; font-size:14px; line-height:1.5; }
        .masthead { background: var(--seal-red); color:#fff; padding:14px 32px; border-bottom:3px solid var(--ink-900); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
        .masthead h1 { font-family:var(--f-cond); font-size:16px; font-weight:700; letter-spacing:.04em; }
        .masthead .sub { font-size:11px; color:rgba(255,255,255,0.75); margin-top:2px; }
        .masthead .nav-link { color:rgba(255,255,255,0.85); text-decoration:none; font-size:11px; font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em; }
        .masthead .nav-link:hover { color:#fff; text-decoration:underline; }
        .stage { max-width:1100px; margin:0 auto; padding:28px 20px; }
        .page-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
        .page-header h2 { font-family:var(--f-cond); font-size:20px; font-weight:700; }
        .btn { padding:9px 20px; border:none; font-weight:600; font-size:12px; cursor:pointer; font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em; background:var(--ink-900); color:#fff; text-decoration:none; display:inline-block; }
        .btn:hover { background:var(--brass); color:var(--ink-900); }
        .card { background:var(--panel); border:1px solid var(--line); padding:0; overflow:hidden; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th { background:var(--paper); color:var(--ink-500); padding:10px 16px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:600; border-bottom:2px solid var(--line); font-family:var(--f-cond); }
        td { padding:12px 16px; border-bottom:1px solid var(--line); vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        tr:hover td { background:var(--paper); }
        .org-name { font-weight:600; }
        .status-pill { display:inline-block; padding:2px 10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; font-family:var(--f-cond); background:var(--green-tint); color:var(--ledger-green); }
        .status-pill.inactive { background:var(--danger-bg); color:var(--danger); }
        .stat { font-family:var(--f-mono); }
        .empty-state { padding:50px 20px; text-align:center; color:var(--ink-300); }
        @media (prefers-color-scheme:dark) {
            :root { --paper:#1B2733; --panel:#1B2733; --ink-900:#ECEFF2; --ink-500:#93A2AC; --ink-300:#6B7A85; --line:#2C3A45; }
            .card { background:#1B2733; border-color:#2C3A45; }
            th { background:#1B2733; color:#93A2AC; border-color:#2C3A45; }
            td { border-color:#2C3A45; }
            tr:hover td { background:#22303A; }
        }
    </style>
</head>
<body>
    <div class="masthead">
        <div>
            <h1>🔒 VOUCHMORPH PLATFORM ADMIN</h1>
            <div class="sub">Client organizations — separate from, and outside the trust boundary of, every enterprise dashboard below</div>
        </div>
        <div>
            <span style="font-size:11px; color:rgba(255,255,255,0.75); margin-right:14px;">
                <?php echo safeHtmlPA($admin['full_name']); ?> · <?php echo safeHtmlPA($admin['role_name']); ?>
                · <?php echo $adminCountryScope ? 'Scoped to ' . safeHtmlPA($adminCountryScope) : 'Global (all countries)'; ?>
            </span>
            <a href="logout.php" class="nav-link">Sign Out</a>
        </div>
    </div>

    <div class="stage">
        <div class="page-header">
            <h2>Organizations (<?php echo count($organizations); ?>)</h2>
            <?php if (!empty($admin['can_edit_config'])): ?>
            <a href="organizations/create.php" class="btn">+ New Organization</a>
            <?php else: ?>
            <span style="font-size:11px; color:var(--ink-300);">Your role (<?php echo safeHtmlPA($admin['role_name']); ?>) has view access only.</span>
            <?php endif; ?>
        </div>

        <div class="card">
            <?php if (empty($organizations)): ?>
            <div class="empty-state">
                <p>No organizations yet.</p>
                <?php if (!empty($admin['can_edit_config'])): ?>
                <div style="margin-top:14px;"><a href="organizations/create.php" class="btn">Create the first one</a></div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Organization</th><th>Country</th><th>Currency</th>
                        <th>Departments</th><th>Active Users</th><th>Status</th><th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($organizations as $org): ?>
                    <tr>
                        <td class="org-name"><?php echo safeHtmlPA($org['name']); ?></td>
                        <td><?php echo safeHtmlPA($org['country_code'] ?? '—'); ?></td>
                        <td><?php echo safeHtmlPA($org['default_currency'] ?? '—'); ?></td>
                        <td class="stat"><?php echo (int)$org['active_departments']; ?></td>
                        <td class="stat"><?php echo (int)$org['active_users']; ?></td>
                        <td><span class="status-pill <?php echo $org['status'] === 'active' ? '' : 'inactive'; ?>"><?php echo safeHtmlPA(ucfirst($org['status'])); ?></span></td>
                        <td><?php echo date('Y-m-d', strtotime($org['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
