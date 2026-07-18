<?php
declare(strict_types=1);

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off display errors in production

// ============================================================
// ADMIN LOGIN - Using DBConnection + RoleManager (Single Source of Truth)
// ============================================================

// Define project root (goes up 2 levels: public/admin/ -> project root)
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Debug logging
error_log("[ADMIN LOGIN] Starting login process");

// Load Composer autoloader first
require_once PROJECT_ROOT . '/vendor/autoload.php';

// Load required classes
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';
require_once PROJECT_ROOT . '/src/Security/Monitoring/ApiRateLimiter.php';
require_once PROJECT_ROOT . '/src/Domain/Services/AuditTrailService.php';
require_once __DIR__ . '/roles.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;
use Security\Monitoring\ApiRateLimiter;
use Domain\Services\AuditTrailService;

// Load configuration for country data only (not database)
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
if (file_exists($configPath)) {
    require_once $configPath;
    try {
        $config = \Core\Config\LoadCountry::getConfig();
        error_log("[ADMIN LOGIN] Configuration loaded");
    } catch (Throwable $e) {
        error_log("[ADMIN LOGIN] Config warning: " . $e->getMessage());
        $config = [];
    }
} else {
    $config = [];
}

// Get country
$countryCode = $_GET['country'] ?? $_POST['country'] ?? $_SESSION['admin_country'] ?? 'BW';
$systemCountry = strtoupper($countryCode);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_country'] = $systemCountry;

// Initialize database connection using DBConnection (Single Source of Truth)
$db = null;
$auth = null;
$dbError = null;
$auditService = null;
$roleManager = null;

try {
    $db = DBConnection::getConnection();
    
    if (!$db) {
        throw new Exception("Database connection failed - DATABASE_URL not set or invalid");
    }
    
    // Test connection
    $stmt = $db->query("SELECT 1");
    $stmt->fetch();
    error_log("[ADMIN LOGIN] Database connected successfully via DBConnection");
    
    // Initialize AdminAuth
    $auth = new AdminAuth($db);
    error_log("[ADMIN LOGIN] AdminAuth initialized");
    
    // Initialize AuditTrailService - pass null for logger (it will use fallback)
    $auditService = new AuditTrailService(
        $db,
        $config ?? [],
        null,  // Logger will use fallback
        $systemCountry
    );
    error_log("[ADMIN LOGIN] AuditTrailService initialized");
    
    // Initialize RoleManager
    $roleManager = new RoleManager($db);
    error_log("[ADMIN LOGIN] RoleManager initialized");
    
} catch (Throwable $e) {
    error_log("[ADMIN LOGIN] DB Error: " . $e->getMessage());
    $dbError = $e->getMessage();
}

$error = '';
$mfaRequired = false;
$adminId = null;
$username = '';
$loginResult = null;

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Get single IP from forwarded headers
 */
function getClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = 'unknown';
    }
    
    return $ip;
}

/**
 * Validate role exists in database - handles both role_name and role_id
 */
function validateRole($roleManager, $role): bool {
    // Empty role is invalid
    if (empty($role)) {
        error_log("[ROLE VALIDATION] Empty role provided - treating as invalid");
        return false;
    }
    
    try {
        if (is_numeric($role)) {
            $roleInfo = $roleManager->getRoleById((int)$role);
            if ($roleInfo) {
                error_log("[ROLE VALIDATION] Validated role ID: {$role} -> {$roleInfo['role_name']}");
                return true;
            }
            error_log("[ROLE VALIDATION] Role ID {$role} not found");
            return false;
        }
        
        $roleInfo = $roleManager->getRoleByName($role);
        if ($roleInfo) {
            error_log("[ROLE VALIDATION] Validated role name: {$role} -> ID: {$roleInfo['role_id']}");
            return true;
        }
        error_log("[ROLE VALIDATION] Role name '{$role}' not found");
        return false;
        
    } catch (Throwable $e) {
        error_log("[ROLE VALIDATION] Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get role info from database - handles both role_name and role_id
 */
function getRoleInfo($roleManager, $role): ?array {
    if (empty($role)) {
        return null;
    }
    
    try {
        if (is_numeric($role)) {
            return $roleManager->getRoleById((int)$role);
        }
        return $roleManager->getRoleByName($role);
    } catch (Throwable $e) {
        error_log("[ROLE VALIDATION] Error getting role info: " . $e->getMessage());
        return null;
    }
}

/**
 * Destroy session completely
 */
function destroySessionCompletely(): void {
    // Clear all session variables
    $_SESSION = [];
    
    // Destroy the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
    
    // Also clear the global session array
    session_unset();
    
    // Start a new session to ensure clean state
    session_start();
    session_regenerate_id(true);
    
    error_log("[SESSION] Session destroyed completely");
}

// ============================================================
// HANDLE LOGIN POST
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($auth) && isset($auditService) && isset($roleManager)) {
    $clientIp = getClientIp();
    $rateLimitKey = 'admin_login:' . $clientIp;
    $rateLimited = false;

    // --- RATE LIMITING ---
    try {
        $limiter = new ApiRateLimiter(8, 300);
        if (!$limiter->check($rateLimitKey)) {
            $rateLimited = true;
            error_log("[ADMIN LOGIN] Rate limit exceeded for IP: {$clientIp}");
        }
    } catch (\Throwable $e) {
        error_log("[ADMIN LOGIN] Rate limiter unavailable: " . $e->getMessage());
    }

    if ($rateLimited) {
        $error = 'Too many login attempts. Please try again in a few minutes.';
        
        try {
            $auditService->recordLog(
                'admin_login',
                null,
                'RATE_LIMIT_EXCEEDED',
                'security',
                'WARNING',
                json_encode(['ip' => $clientIp]),
                null,
                null,
                $clientIp,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
        } catch (Throwable $e) {
            error_log("[ADMIN LOGIN] Failed to audit rate limit: " . $e->getMessage());
        }
    } else {
        try {
            if (isset($_POST['mfa_code'])) {
                // MFA verification
                $loginResult = $auth->verifyMfa($_POST['mfa_code'], $systemCountry);
                if ($loginResult['success']) {
                    try {
                        $auditService->recordLog(
                            'admin_login',
                            SessionManager::get('admin_id'),
                            'MFA_VERIFIED',
                            'security',
                            'INFO',
                            null,
                            json_encode(['method' => 'totp']),
                            SessionManager::get('admin_id'),
                            $clientIp,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        );
                    } catch (Throwable $e) {
                        error_log("[ADMIN LOGIN] Failed to audit MFA: " . $e->getMessage());
                    }
                    
                    header('Location: admin_dashboard.php?country=' . $systemCountry);
                    exit;
                } else {
                    $error = $loginResult['message'];
                    
                    try {
                        $auditService->recordLog(
                            'admin_login',
                            SessionManager::get('admin_id'),
                            'MFA_FAILED',
                            'security',
                            'WARNING',
                            json_encode(['reason' => $error]),
                            null,
                            SessionManager::get('admin_id'),
                            $clientIp,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        );
                    } catch (Throwable $e) {
                        error_log("[ADMIN LOGIN] Failed to audit MFA failure: " . $e->getMessage());
                    }
                }
            } else {
                // Initial login
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';

                if (empty($username) || empty($password)) {
                    $error = 'Username and password are required';
                } else {
                    // ============================================================
                    // STEP 1: Authenticate the user
                    // ============================================================
                    $loginResult = $auth->login($username, $password, $systemCountry);
                    
                    if ($loginResult['success']) {
                        // ============================================================
                        // STEP 2: ROLE VALIDATION - Check BEFORE session is fully committed
                        // ============================================================
                        
                        // Get role from login result - try multiple possible keys
                        $userRole = $loginResult['role'] ?? 
                                   $loginResult['role_name'] ?? 
                                   $loginResult['role_id'] ?? 
                                   '';
                        
                        $adminId = $loginResult['admin_id'] ?? null;
                        
                        // Log what we found for debugging
                        error_log("[ADMIN LOGIN] Role from login result: " . json_encode([
                            'role' => $loginResult['role'] ?? 'null',
                            'role_name' => $loginResult['role_name'] ?? 'null',
                            'role_id' => $loginResult['role_id'] ?? 'null',
                            'userRole_final' => $userRole
                        ]));
                        
                        // Validate the role
                        $roleValid = validateRole($roleManager, $userRole);
                        
                        // Get role info for logging
                        $roleInfo = getRoleInfo($roleManager, $userRole);
                        $roleLevel = $roleInfo['role_level'] ?? 'N/A';
                        
                        error_log("[ADMIN LOGIN] Role validation result: " . ($roleValid ? 'VALID' : 'INVALID') . 
                                 " - Role: {$userRole}, Admin ID: {$adminId}");
                        
                        if (!$roleValid) {
                            // ============================================================
                            // INVALID ROLE - Security incident - DESTROY SESSION IMMEDIATELY
                            // ============================================================
                            $error = 'Access denied: Invalid account permissions. Please contact system administrator.';
                            
                            error_log("[SECURITY] Invalid role detected during login: {$userRole} for user: {$username}");
                            error_log("[SECURITY] Admin ID: {$adminId}, IP: {$clientIp}");
                            
                            // Log the security incident
                            try {
                                $auditService->recordLog(
                                    'admin_login',
                                    $adminId,
                                    'INVALID_ROLE_DETECTED',
                                    'security',
                                    'CRITICAL',
                                    json_encode([
                                        'username' => $username,
                                        'role' => $userRole,
                                        'role_type' => is_numeric($userRole) ? 'numeric' : 'string'
                                    ]),
                                    null,
                                    $adminId,
                                    $clientIp,
                                    $_SERVER['HTTP_USER_AGENT'] ?? null
                                );
                            } catch (Throwable $e) {
                                error_log("[ADMIN LOGIN] Failed to audit invalid role: " . $e->getMessage());
                            }
                            
                            // ============================================================
                            // CRITICAL: DESTROY SESSION COMPLETELY
                            // ============================================================
                            destroySessionCompletely();
                            
                            // Clear the login result to prevent further processing
                            $loginResult['success'] = false;
                            
                            // Ensure we don't proceed
                            $error = 'Access denied: Invalid account permissions. Please contact system administrator.';
                            
                            // IMPORTANT: Do NOT redirect or proceed - stay on login page
                            // The error will be displayed and the user will need to re-authenticate
                            
                        } else {
                            // ============================================================
                            // ROLE VALID - Proceed with login
                            // ============================================================
                            
                            // Log successful login with role info
                            try {
                                $auditService->recordLog(
                                    'admin_login',
                                    $adminId,
                                    'LOGIN_SUCCESS',
                                    'security',
                                    'INFO',
                                    null,
                                    json_encode([
                                        'username' => $username,
                                        'role' => $userRole,
                                        'role_level' => $roleLevel,
                                        'role_validated' => true,
                                        'role_id' => $roleInfo['role_id'] ?? null
                                    ]),
                                    $adminId,
                                    $clientIp,
                                    $_SERVER['HTTP_USER_AGENT'] ?? null
                                );
                            } catch (Throwable $e) {
                                error_log("[ADMIN LOGIN] Failed to audit login success: " . $e->getMessage());
                            }
                            
                            // Check if MFA is required
                            if (isset($loginResult['mfa_required']) && $loginResult['mfa_required'] === true) {
                                $mfaRequired = true;
                                $adminId = $loginResult['admin_id'];
                            } else {
                                // Session is already set by AdminAuth, but ensure it's complete
                                if (!SessionManager::isLoggedIn()) {
                                    SessionManager::set('admin_id', $adminId);
                                    SessionManager::set('admin_username', $username);
                                    SessionManager::set('admin_role', $userRole);
                                    SessionManager::set('admin_country', $systemCountry);
                                    SessionManager::set('logged_in', true);
                                    
                                    if ($roleInfo) {
                                        SessionManager::set('admin_role_id', $roleInfo['role_id'] ?? null);
                                        SessionManager::set('admin_role_level', $roleInfo['role_level'] ?? null);
                                    }
                                }
                                
                                header('Location: admin_dashboard.php?country=' . $systemCountry);
                                exit;
                            }
                        }
                    } else {
                        $error = $loginResult['message'];
                        
                        // Log failed login
                        try {
                            $auditService->recordLog(
                                'admin_login',
                                null,
                                'LOGIN_FAILED',
                                'security',
                                'WARNING',
                                json_encode(['username' => $username, 'reason' => $error]),
                                null,
                                null,
                                $clientIp,
                                $_SERVER['HTTP_USER_AGENT'] ?? null
                            );
                        } catch (Throwable $e) {
                            error_log("[ADMIN LOGIN] Failed to audit login failure: " . $e->getMessage());
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("[ADMIN LOGIN] Exception: " . $e->getMessage());
            $error = "Authentication error occurred.";
            
            try {
                $auditService->recordLog(
                    'admin_login',
                    null,
                    'LOGIN_EXCEPTION',
                    'security',
                    'ERROR',
                    json_encode(['exception' => $e->getMessage()]),
                    null,
                    null,
                    $clientIp,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                );
            } catch (Throwable $auditErr) {
                error_log("[ADMIN LOGIN] Failed to audit exception: " . $auditErr->getMessage());
            }
        }
    }
}

// Get available countries
$availableCountries = [];
$countriesDir = PROJECT_ROOT . '/src/Core/Config/Countries/';
if (is_dir($countriesDir)) {
    $items = scandir($countriesDir);
    foreach ($items as $item) {
        if (is_dir($countriesDir . $item) && !in_array($item, ['.', '..'])) {
            $availableCountries[] = $item;
        }
    }
}
if (empty($availableCountries)) {
    $availableCountries = ['Botswana'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VOUCHMORPH · ADMIN SIGN IN</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* ============================================================
       VOUCHMORPH — ADMIN LOGIN
       Full-bleed 60/40 split matching main login style.
       Left: white/greyish, functional admin login form.
       Right: dark, magazine-set brand statement.
       ============================================================ */
    :root {
      --paper:        #EEF1EF;
      --panel:        #FFFFFF;
      --ink-900:      #0B1B2B;
      --ink-700:      #1D3557;
      --ink-500:      #4A5A6E;
      --ink-300:      #8A96A3;
      --line:         #D3DAD6;
      --line-strong:  #AEB8B2;
      --brass:        #9C7A3C;
      --brass-deep:   #6E5326;
      --brass-tint:   #F4EFE3;
      --danger:       #b3261e;
      --danger-bg:    #fbeceb;

      --f-display: 'Source Serif 4', 'IBM Plex Sans', serif;
      --f-body: 'IBM Plex Sans', sans-serif;
      --f-cond: 'IBM Plex Sans Condensed', sans-serif;
      --f-mono: 'IBM Plex Mono', monospace;

      --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px; --sp-4: 16px;
      --sp-5: 20px; --sp-6: 24px; --sp-7: 32px; --sp-8: 40px;
      --sp-9: 48px; --sp-10: 64px;
      
      /* Frame position: moved outward by 50% (closer to edges) */
      --frame-inset: calc(var(--sp-6) * 0.5);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { height: 100%; }

    body {
      font-family: var(--f-body);
      color: var(--ink-900);
      font-size: 15px;
      line-height: 1.55;
      -webkit-font-smoothing: antialiased;
    }

    :focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }

    /* ============================================================
       SPLIT — LEFT 60% | RIGHT 40%
       ============================================================ */
    .split {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }
    .col {
      min-width: 0;
      display: flex;
      flex-direction: column;
    }

    /* LEFT — 60% — Greyish background */
    .col-form {
      flex: 0 0 60%;
      background: #F2F0ED;
      align-items: center;
      justify-content: center;
      padding: var(--sp-8) var(--sp-6);
    }
    .form-wrap {
      width: 100%;
      max-width: 440px;
    }

    /* RIGHT — 40% — Dark magazine style */
    .col-brand {
      flex: 0 0 40%;
      background:
        radial-gradient(900px 600px at 85% 0%, rgba(156,122,60,.12), transparent 60%),
        #0A1420;
      position: relative;
      align-items: stretch;
      justify-content: stretch;
      overflow: hidden;
    }

    .brand {
      margin-bottom: var(--sp-8);
    }
    .brand .mark {
      font-family: var(--f-display);
      font-weight: 600;
      font-size: 26px;
      letter-spacing: 0.005em;
      color: var(--ink-900);
    }
    .brand .mark sup { font-size: 11px; color: var(--brass-deep); font-weight: 600; }
    .brand .division {
      margin-top: var(--sp-2);
      font-family: var(--f-cond);
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--ink-300);
      padding-top: var(--sp-2);
      border-top: 2px solid var(--brass);
      display: inline-block;
    }

    .form-wrap h2 {
      font-family: var(--f-display);
      font-size: 24px;
      font-weight: 600;
      color: var(--ink-900);
    }
    .form-wrap .subtitle {
      color: var(--ink-500);
      font-size: 14px;
      margin-top: var(--sp-1);
      margin-bottom: var(--sp-7);
    }

    .field { margin-bottom: var(--sp-5); }
    .field label {
      display: block;
      margin-bottom: var(--sp-2);
      font-weight: 600;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--ink-500);
      font-family: var(--f-cond);
    }
    .field-input { position: relative; }
    .field-input svg {
      position: absolute;
      left: var(--sp-4);
      top: 50%;
      transform: translateY(-50%);
      width: 18px;
      height: 18px;
      color: var(--ink-300);
      pointer-events: none;
    }
    .field input,
    .field select {
      width: 100%;
      padding: var(--sp-4) var(--sp-4) var(--sp-4) 44px;
      border: 1.5px solid var(--line);
      font-size: 15px;
      font-family: var(--f-body);
      background: #fdfcf9;
      transition: border-color .15s, background .15s;
      color: var(--ink-900);
      border-radius: 0;
      appearance: none;
      -webkit-appearance: none;
    }
    .field input:focus,
    .field select:focus {
      outline: none;
      border-color: var(--brass);
      background: #fff;
    }
    .field input::placeholder,
    .field select::placeholder { color: var(--ink-300); opacity: 0.8; }
    
    /* Custom select arrow */
    .field-input select {
      padding-right: 40px;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%234A5A6E' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 16px center;
    }

    .btn {
      width: 100%;
      padding: var(--sp-4);
      background: var(--ink-900);
      color: #fff;
      border: 1.5px solid var(--ink-900);
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: .15s;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-family: var(--f-cond);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--sp-3);
      border-radius: 0;
      margin-top: var(--sp-2);
    }
    .btn:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }
    .btn svg { width: 16px; height: 16px; transition: transform .15s; }
    .btn:hover svg { transform: translateX(4px); }

    .error {
      display: flex;
      align-items: flex-start;
      gap: var(--sp-3);
      background: var(--danger-bg);
      color: var(--danger);
      padding: var(--sp-4);
      margin-bottom: var(--sp-6);
      font-size: 13px;
      border-left: 3px solid var(--danger);
      line-height: 1.5;
      font-weight: 500;
    }
    .error svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

    .mfa-info {
      display: flex;
      align-items: flex-start;
      gap: var(--sp-3);
      background: #e3f2fd;
      color: #1976d2;
      padding: var(--sp-4);
      margin-bottom: var(--sp-6);
      font-size: 13px;
      border-left: 3px solid #1976d2;
      line-height: 1.5;
      font-weight: 500;
    }
    .mfa-info svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

    .trust-row {
      display: flex;
      justify-content: space-between;
      margin-top: var(--sp-7);
      padding-top: var(--sp-5);
      border-top: 1px solid var(--line);
      font-size: 10.5px;
      color: var(--ink-300);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      font-weight: 600;
      font-family: var(--f-cond);
    }
    .trust-row span { display: flex; align-items: center; gap: var(--sp-2); }
    .trust-row svg { width: 14px; height: 14px; color: var(--brass); }

    .legal {
      margin-top: var(--sp-8);
      font-size: 9.5px;
      letter-spacing: 0.05em;
      font-family: var(--f-mono);
      text-transform: uppercase;
      line-height: 1.9;
      color: var(--ink-300);
    }
    .legal .line2 { color: var(--line-strong); font-size: 9px; }

    /* ============================================================
       RIGHT — dark, magazine statement inside a mat frame
       Frame moved outward by 50% (closer to edges)
       VOUCHMORPH™ on lateral side between frame and outer edge
       ============================================================ */
    .frame-mat {
      position: relative;
      flex: 1;
      margin: var(--frame-inset);
    }
    .frame-line {
      position: absolute;
      inset: var(--frame-inset);
      border: 1px solid rgba(255,255,255,0.16);
      pointer-events: none;
    }

    .frame-strip {
      position: absolute;
      color: rgba(255,255,255,0.3);
      font-family: var(--f-mono);
      font-size: 10px;
      letter-spacing: 0.28em;
      text-transform: uppercase;
      white-space: nowrap;
      overflow: hidden;
      display: flex;
      align-items: center;
      z-index: 2;
    }
    .frame-strip span { display: inline-block; }
    .frame-strip.top {
      top: calc(var(--frame-inset) - 10px);
      left: calc(var(--frame-inset) + 30px);
      right: calc(var(--frame-inset) + 30px);
      height: 20px;
      justify-content: center;
      background: #0A1420;
      padding: 0 12px;
    }
    .frame-strip.bottom {
      bottom: calc(var(--frame-inset) - 10px);
      left: calc(var(--frame-inset) + 30px);
      right: calc(var(--frame-inset) + 30px);
      height: 20px;
      justify-content: center;
      background: #0A1420;
      padding: 0 12px;
    }

    .frame-strip.lateral {
      right: calc(var(--frame-inset) - 24px);
      top: 50%;
      transform: translateY(-50%) rotate(180deg);
      transform-origin: center;
      width: 26px;
      height: auto;
      writing-mode: vertical-rl;
      justify-content: center;
      color: var(--brass);
      font-family: var(--f-cond);
      font-size: 15px;
      font-weight: 700;
      letter-spacing: 0.34em;
      opacity: 1;
      background: transparent;
      padding: 0;
      z-index: 3;
      background: none;
    }
    .frame-strip.lateral span {
      display: inline-block;
      padding: 8px 0;
      background: transparent;
    }

    .magazine {
      position: absolute;
      inset: var(--frame-inset);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: var(--sp-7) var(--sp-6);
      z-index: 1;
    }
    .magazine .eyebrow {
      font-family: var(--f-cond);
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: var(--brass);
      margin-bottom: var(--sp-4);
    }

    .magazine p {
      font-family: var(--f-body);
      font-size: 10px;
      font-weight: 400;
      line-height: 1.85;
      color: rgba(255,255,255,0.92);
      max-width: 300px;
      text-align: justify;
      text-justify: inter-word;
      hyphens: auto;
    }

    .magazine p::first-letter {
      font-family: var(--f-cond);
      font-size: 28px;
      font-weight: 700;
      color: var(--brass);
      float: left;
      line-height: 0.8;
      padding-right: var(--sp-2);
      padding-top: 4px;
    }

    .magazine p.secondary {
      font-family: var(--f-body);
      font-size: 10px;
      font-weight: 400;
      line-height: 1.75;
      color: rgba(255,255,255,0.62);
      max-width: 300px;
      margin-top: var(--sp-4);
      letter-spacing: 0.005em;
      text-align: justify;
      text-justify: inter-word;
      hyphens: auto;
    }
    .magazine p.secondary strong {
      color: rgba(255,255,255,0.85);
      font-weight: 600;
    }
    .magazine .mark {
      margin-top: var(--sp-5);
      width: 40px;
      height: 1px;
      background: var(--brass);
    }

    /* ============================================================
       RESPONSIVE — stack on narrow screens
       ============================================================ */
    @media (max-width: 900px) {
      .split { flex-direction: column; }
      .col-form { flex: 1 1 auto; }
      .col-brand { flex: 1 1 auto; min-height: 400px; }
      .col-form { padding: var(--sp-7) var(--sp-5); }
      :root { --frame-inset: calc(var(--sp-5) * 0.5); }
      .frame-strip.top { top: calc(var(--frame-inset) - 8px); left: calc(var(--frame-inset) + 20px); right: calc(var(--frame-inset) + 20px); }
      .frame-strip.bottom { bottom: calc(var(--frame-inset) - 8px); left: calc(var(--frame-inset) + 20px); right: calc(var(--frame-inset) + 20px); }
      .frame-strip.lateral { right: calc(var(--frame-inset) - 20px); }
      .magazine { inset: var(--frame-inset); padding: var(--sp-6) var(--sp-4); }
      .magazine p { font-size: 10px; max-width: 280px; }
      .magazine p.secondary { font-size: 10px; max-width: 280px; }
    }
    @media (max-width: 480px) {
      .frame-strip.lateral { display: none; }
      .trust-row { flex-wrap: wrap; gap: var(--sp-3); justify-content: center; }
      .magazine p { font-size: 10px; max-width: 260px; }
      .magazine p.secondary { font-size: 10px; max-width: 260px; }
    }

    @media (prefers-color-scheme: dark) {
      .col-form { background: #1A1F26; }
      .brand .mark { color: #ECEFF2; }
      .form-wrap h2 { color: #ECEFF2; }
      .form-wrap .subtitle { color: #93A2AC; }
      .field label { color: #93A2AC; }
      .field input,
      .field select { background: #0F1B24; border-color: #2C3A45; color: #ECEFF2; }
      .field input:focus,
      .field select:focus { background: #16232E; border-color: var(--brass); }
      .field input::placeholder,
      .field select::placeholder { color: #6B7A85; }
      .trust-row { border-color: #2C3A45; color: #6B7A85; }
      .legal { color: #6B7A85; }
      .legal .line2 { color: #3A4A56; }
      .btn { background: #2C3A45; border-color: #2C3A45; color: #ECEFF2; }
      .btn:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }
      .field-input select {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2393A2AC' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      }
    }
  </style>
</head>
<body>
<div class="split">

  <!-- LEFT — greyish, functional admin login (60%) -->
  <div class="col col-form">
    <div class="form-wrap">
      <div class="brand">
        <div class="mark">VOUCHMORPH<sup>™</sup></div>
        <div class="division">Administrative Access</div>
      </div>

      <h2>Sign in</h2>
      <p class="subtitle">Access the administrative command center</p>

      <?php if (isset($dbError)): ?>
      <div class="error">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
        <span><strong>SYSTEM UNAVAILABLE</strong><br><?php echo htmlspecialchars($dbError); ?></span>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
      <div class="error">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
        <span><?php echo htmlspecialchars($error); ?></span>
      </div>
      <?php endif; ?>

      <?php if ($mfaRequired): ?>
      <div class="mfa-info">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span><strong>Two-Factor Authentication</strong><br>Please enter the authentication code from your authenticator app.</span>
      </div>
      <?php endif; ?>

      <?php if (!isset($dbError)): ?>
      <form method="POST" action="">
        <input type="hidden" name="country" value="<?php echo htmlspecialchars($systemCountry); ?>">

        <?php if ($mfaRequired): ?>
          <div class="field">
            <label>Authentication Code</label>
            <div class="field-input">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="text" name="mfa_code" placeholder="000000" maxlength="6" autofocus required>
            </div>
          </div>
        <?php else: ?>
          <div class="field">
            <label>System Country</label>
            <div class="field-input">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 0 20 15.3 15.3 0 0 1 0-20z"/></svg>
              <select name="country" onchange="this.form.submit()">
                <?php foreach ($availableCountries as $country): ?>
                  <option value="<?php echo htmlspecialchars($country); ?>" <?php echo $country === $systemCountry ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($country); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="field">
            <label>Username / Email</label>
            <div class="field-input">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 7l9 6 9-6"/></svg>
              <input type="text" name="username" placeholder="Enter username or email" autofocus required>
            </div>
          </div>

          <div class="field">
            <label>Password</label>
            <div class="field-input">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="9" rx="1"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
              <input type="password" name="password" placeholder="Enter your password" required>
            </div>
          </div>
        <?php endif; ?>

        <button type="submit" class="btn">
          <?php echo $mfaRequired ? 'Verify Code' : 'Sign in'; ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </form>
      <?php endif; ?>

      <div class="trust-row">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 6v6c0 5 3.5 8 8 10 4.5-2 8-5 8-10V6l-8-4Z"/></svg>Secure</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="10" rx="1"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>2FA Ready</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m4 12 5 5L20 6"/></svg>ISO 27001</span>
      </div>

      <div class="legal">
        <div>Secure administrative access · distribution restricted · ISO 27001 · © 2026 VouchMorph</div>
        <div class="line2">VM/2026/0708-000</div>
      </div>
    </div>
  </div>

  <!-- RIGHT — dark, magazine statement (40%) -->
  <div class="col col-brand">
    <div class="frame-mat">
      <!-- Outer frame line — moved outward by 50% -->
      <div class="frame-line"></div>

      <!-- Top and bottom frame strips -->
      <div class="frame-strip top"><span>VOUCHMORPH ADMIN</span></div>
      <div class="frame-strip bottom"><span>VOUCHMORPH ADMIN</span></div>

      <!-- VOUCHMORPH™ on lateral side (between frame and outer edge) -->
      <div class="frame-strip lateral"><span>VOUCHMORPH™</span></div>

      <!-- Magazine content -->
      <div class="magazine">
        <div class="eyebrow">Administrative Command Center</div>
        <p>VouchMorph administrative access provides complete oversight of multi-asset payment orchestration, beneficiary management, and transaction auditing across all institutions and destinations.</p>
        <p class="secondary">Administrators have full visibility into <strong>every transaction</strong>, from source funding to final settlement. Role-based access controls ensure that only authorized personnel can approve, disburse, or audit payment flows.</p>
        <div class="mark"></div>
      </div>
    </div>
  </div>

</div>
</body>
</html>
