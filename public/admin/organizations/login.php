<?php
require_once __DIR__ . '/../auth.php';

if (!empty($_SESSION['platform_admin_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if (!empty($_GET['locked'])) {
    $error = 'This account is temporarily locked after repeated failed login attempts. Try again in a few minutes.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $admin = attemptPlatformAdminLogin($_POST['identifier'] ?? '', $_POST['password'] ?? '');
    if ($admin) {
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username/email or password, or this account is locked.';
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Platform Admin · Sign In</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #EEF1EF; --panel: #FFFFFF; --ink-900: #0F2138; --ink-500: #4A5A6E;
            --line: #D3DAD6; --brass: #8A6D3B; --seal-red: #7A2118; --danger: #b3261e; --danger-bg: #fbeceb;
            --f-body: 'IBM Plex Sans', sans-serif; --f-cond: 'IBM Plex Sans Condensed', sans-serif;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .login-box { width:100%; max-width:380px; padding:0 20px; }
        .brand { text-align:center; margin-bottom:24px; }
        .brand h1 { font-family:var(--f-cond); font-size:16px; font-weight:700; letter-spacing:.04em; }
        .brand .sub { font-size:11px; color:var(--seal-red); font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin-top:4px; font-family:var(--f-cond); }
        .card { background:var(--panel); border:1px solid var(--line); border-top:3px solid var(--seal-red); padding:28px 24px; }
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; margin-bottom:6px; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-family:var(--f-cond); color:var(--ink-500); }
        .form-group input { width:100%; padding:10px 12px; border:1.5px solid var(--line); font-size:14px; background:var(--paper); }
        .form-group input:focus { outline:none; border-color:var(--brass); background:var(--panel); }
        .btn { width:100%; padding:11px; border:none; font-weight:600; font-size:12px; cursor:pointer; font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em; background:var(--ink-900); color:#fff; margin-top:6px; }
        .btn:hover { background:var(--brass); color:var(--ink-900); }
        .error-msg { background:var(--danger-bg); color:var(--danger); padding:10px 14px; margin-bottom:16px; border-left:3px solid var(--danger); font-size:13px; }
        @media (prefers-color-scheme:dark) {
            :root { --paper:#1B2733; --panel:#1B2733; --ink-900:#ECEFF2; --ink-500:#93A2AC; --line:#2C3A45; }
            .form-group input { background:#22303A; color:#ECEFF2; border-color:#2C3A45; }
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="brand">
            <h1>VOUCHMORPH</h1>
            <div class="sub">🔒 Platform Admin</div>
        </div>
        <div class="card">
            <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label>Username or Email</label>
                    <input type="text" name="identifier" required autofocus>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="btn">Sign In</button>
            </form>
        </div>
    </div>
</body>
</html>
