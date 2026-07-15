<?php
// ADMIN_LAYER/dashboards/admin_logout.php

// FIX: Correct path to session_manager.php
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';

// FIX: Use the correct namespace
use Application\Utils\SessionManager;

// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    SessionManager::start();
}

// Destroy session and log out
SessionManager::destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params["path"]);
}

// Redirect to login page
header('Location: admin_login.php');
exit();
?>
