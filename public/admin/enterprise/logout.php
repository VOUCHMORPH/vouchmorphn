<?php
// logout.php - Enterprise Logout
require_once __DIR__ . '/../auth.php';

// Ensure user is logged in before logging out
if (!isAuthenticated()) {
    header('Location: login.php');
    exit;
}

// Log the logout action before destroying session
try {
    $user = getCurrentUser();
    if ($user) {
        $pdo = getDBConnection();
        $logStmt = $pdo->prepare("
            INSERT INTO organization_audit_logs
            (organization_id, user_id, action, entity_type, ip_address, user_agent, created_at)
            VALUES (:org_id, :user_id, 'LOGOUT', 'user', :ip, :ua, NOW())
        ");
        $logStmt->execute([
            ':org_id' => $user['organization_id'] ?? null,
            ':user_id' => $user['user_id'] ?? null,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
} catch (PDOException $e) {
    error_log("Failed to create logout audit log: " . $e->getMessage());
}

// Clear session and logout
logout();

// logout() function in auth.php handles redirect
// If for some reason it doesn't, redirect here
header('Location: login.php?message=You+have+been+logged+out');
exit;
