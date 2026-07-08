<?php
// auth.php - Enterprise authentication helper

// Import the DBConnection class at the top level
require_once _DIR_. '/../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection helper
function getDBConnection() {
    $db = new DBConnection();
    $pdo = $db->getConnection();
    
    // Fallback if getConnection() doesn't exist
    if (!method_exists($db, 'getConnection')) {
        $host = 'localhost';
        $port = '5432';
        $dbname = 'vouchmorph';
        $username = 'postgres';
        $password = 'postgres';
        
        try {
            $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

function requireEnterpriseAuth() {
    if (!isset($_SESSION['enterprise_user'])) {
        // No output before header
        header('Location: login.php');
        exit;
    }
    
    // Verify user still exists and is active
    try {
        $pdo = getDBConnection();
        $user = $_SESSION['enterprise_user'];
        
        $stmt = $pdo->prepare("
            SELECT ou.is_active, o.status as org_status
            FROM organization_users ou
            INNER JOIN organizations o ON ou.organization_id = o.id
            WHERE ou.id = :org_user_id
        ");
        $stmt->execute([':org_user_id' => $user['org_user_id'] ?? $user['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || !$result['is_active'] || $result['org_status'] !== 'ACTIVE') {
            session_destroy();
            header('Location: login.php?error=Account+inactive');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Auth verification error: " . $e->getMessage());
    }
    
    return $_SESSION['enterprise_user'];
}

function hasPermission($permission) {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return false;
    
    // Admins and owners have all permissions
    if (in_array($user['role'] ?? '', ['owner', 'admin'])) return true;
    
    // Check specific permission
    $permissions = $user['permissions'] ?? [];
    return in_array($permission, $permissions) || in_array('*', $permissions);
}

function requirePermission($permission) {
    if (!hasPermission($permission)) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied. You need permission: ' . htmlspecialchars($permission));
    }
}

function getOrganizationId() {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return null;
    return $user['organization_id'] ?? null;
}

function getCurrentUser() {
    return $_SESSION['enterprise_user'] ?? null;
}

function getOrganizationName() {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return null;
    return $user['organization_name'] ?? null;
}

function isAuthenticated() {
    return isset($_SESSION['enterprise_user']);
}

function logout() {
    // Clear session
    $_SESSION = array();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy session
    session_destroy();
    
    // No output before header
    header('Location: login.php');
    exit;
}
