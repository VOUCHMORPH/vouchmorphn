<?php
// logout.php - Enterprise Logout
//
// Two ways in:
//  - POST with Accept: application/json, from the sign-out sequence
//    (partials/cinema.php) while it plays over the page. Returns the
//    session summary as JSON; the sequence shows it.
//  - Plain GET (the ordinary link, or JavaScript unavailable). Renders
//    the same sequence as a page of its own.
// Both record the sign-out, compute the summary, then end the session.
require_once __DIR__ . '/auth.php';

$wantsJson = $_SERVER['REQUEST_METHOD'] === 'POST'
    && str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function vmLogoutJson(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
}

if (!isAuthenticated()) {
    if ($wantsJson) { vmLogoutJson(401, ['ok' => false, 'error' => 'not_signed_in']); }
    header('Location: /admin/enterprise/login.php');
    exit;
}

// The sequence's POST carries a per-session token so another site cannot
// sign someone out silently. The plain link keeps working as before.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = (string)($_POST['token'] ?? '');
    $expected = (string)($_SESSION['vmc_logout_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $sent)) {
        if ($wantsJson) { vmLogoutJson(403, ['ok' => false, 'error' => 'bad_token']); }
        header('Location: index.php');
        exit;
    }
}

$user = getCurrentUser() ?: [];
$orgId = $user['organization_id'] ?? null;
$orgUserId = $user['org_user_id'] ?? null;
$now = time();
$signedInAt = (int)($_SESSION['vm_signed_in_at'] ?? 0);
$pdo = null;
try { $pdo = getDBConnection(); } catch (Throwable $e) { error_log('[LOGOUT] no database: ' . $e->getMessage()); }

// organization_audit_logs.user_id references organization_users(id), the
// membership row - not the global users.user_id. This file used to pass
// the global id, which violated that foreign key on every sign-out; the
// error was caught and logged, so no sign-out was ever recorded. login.php
// had the same bug and was fixed the same way.
if ($pdo && !$orgUserId && $orgId && !empty($user['user_id'])) {
    try {
        $q = $pdo->prepare("SELECT id FROM organization_users WHERE organization_id = :org AND user_id = :uid LIMIT 1");
        $q->execute([':org' => $orgId, ':uid' => $user['user_id']]);
        $orgUserId = $q->fetchColumn() ?: null;
    } catch (Throwable $e) { error_log('[LOGOUT] membership lookup failed: ' . $e->getMessage()); }
}

// Actions this person recorded during this session (sign-in and sign-out
// themselves are not counted as work).
$actions = 0;
if ($pdo && $orgId && $orgUserId) {
    try {
        $q = $pdo->prepare("
            SELECT COUNT(*) FROM organization_audit_logs
            WHERE organization_id = :org AND user_id = :ou
              AND action NOT IN ('LOGIN', 'LOGOUT')
              AND created_at >= to_timestamp(:since)
        ");
        $q->execute([':org' => $orgId, ':ou' => $orgUserId, ':since' => $signedInAt ?: $now]);
        $actions = (int)$q->fetchColumn();
    } catch (Throwable $e) { error_log('[LOGOUT] action count failed: ' . $e->getMessage()); }
}

// What is still waiting on this person, for the roles that decide
// something. null means "not applicable to this role", shown as n/a.
$waiting = null;
$role = (string)($user['role'] ?? '');
$waitingStatuses = [
    'approver' => ['pending', 'pending_approval'],
    'senior_approver' => ['pending', 'pending_approval'],
    'owner' => ['pending', 'pending_approval'],
    'supervisor' => ['approved'],
];
if ($pdo && $orgId && isset($waitingStatuses[$role])) {
    try {
        $params = [':org' => $orgId];
        $in = [];
        foreach ($waitingStatuses[$role] as $i => $st) { $in[] = ":s{$i}"; $params[":s{$i}"] = $st; }
        $scope = '';
        if (($user['role_scope'] ?? '') === 'department' && !empty($user['department_id'])) {
            $scope = ' AND department_id = :dept';
            $params[':dept'] = $user['department_id'];
        }
        $q = $pdo->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org AND LOWER(status) IN (" . implode(',', $in) . ")" . $scope);
        $q->execute($params);
        $waiting = (int)$q->fetchColumn();
    } catch (Throwable $e) { error_log('[LOGOUT] waiting count failed: ' . $e->getMessage()); }
}

// Record the sign-out.
$sealed = false;
if ($pdo && $orgId && $orgUserId) {
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO organization_audit_logs
            (organization_id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
            VALUES (:org_id, :user_id, 'LOGOUT', 'user', :entity_id, NULL, :new_values, :ip, :ua, NOW())
        ");
        $logStmt->execute([
            ':org_id' => $orgId,
            ':user_id' => $orgUserId,
            ':entity_id' => $orgUserId,
            ':new_values' => json_encode([
                'session_minutes' => $signedInAt ? (int)round(($now - $signedInAt) / 60) : null,
                'actions' => $actions,
                'method' => $wantsJson ? 'sequence' : 'link',
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        $sealed = true;
    } catch (PDOException $e) {
        error_log("Failed to create logout audit log: " . $e->getMessage());
    }
}

// Build the summary before the session is gone.
$name = (string)($user['full_name'] ?? $user['username'] ?? '');
$parts = preg_split('/\s+/', trim($name));
$minutes = $signedInAt ? max(1, (int)round(($now - $signedInAt) / 60)) : null;
$summary = [
    'name' => $name,
    'first' => $parts[0] ?? $name,
    'initial' => count($parts) > 1 ? (preg_match('/^./u', $parts[0], $vmcM) ? $vmcM[0] : '') . '. ' . end($parts) : $name,
    'org' => (string)($user['organization_name'] ?? ''),
    'date' => date('d/m/Y', $now),
    'signed_in' => $signedInAt ? date('H:i', $signedInAt) : null,
    'signed_out' => date('H:i', $now),
    'duration' => $minutes === null ? null : (($minutes >= 60 ? intdiv($minutes, 60) . ' h ' : '') . ($minutes % 60) . ' min'),
    'actions' => $actions,
    'waiting' => $waiting,
    'sealed' => $sealed,
    'login_url' => '/admin/enterprise/login.php',
];

// End the session here rather than through auth.php's logout(), which
// redirects - that would cut off the JSON reply the sequence waits for.
// If logout() does more than clear the session (revoking a remember-me
// token, for example), move that part into a helper and call it here.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
        'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

if ($wantsJson) {
    vmLogoutJson(200, ['ok' => true, 'summary' => $summary]);
}

// Standalone page: the same sequence, starting from the tape stop.
header('Cache-Control: no-store');
$cinemaMode = 'outro-page';
$cinemaSummary = $summary;
$cinemaLoginUrl = '/admin/enterprise/login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>VOUCHMORPH · Signed out</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>body{margin:0;background:#0A1420;color:#fff;font-family:'IBM Plex Sans',system-ui,sans-serif}
.nojs{max-width:520px;margin:18vh auto;padding:24px;text-align:center}.nojs a{color:#C9A227}</style>
</head>
<body>
<noscript><div class="nojs"><h1>You're signed out.</h1><p>Your session closed at <?php echo htmlspecialchars($summary['signed_out']); ?>.</p><p>Using a shared computer? Close this browser window too.</p><p><a href="/admin/enterprise/login.php">Sign in again</a></p></div></noscript>
<?php require __DIR__ . '/partials/cinema.php'; ?>
</body>
</html>
