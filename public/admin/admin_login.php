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
    
    // Check for forwarded IPs but only take the first one
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]); // Take only the first IP
    } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    
    // Validate IP format
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
        // If role is numeric, check by role_id
        if (is_numeric($role)) {
            $roleInfo = $roleManager->getRoleById((int)$role);
            if ($roleInfo) {
                error_log("[ROLE VALIDATION] Validated role ID: {$role} -> {$roleInfo['role_name']}");
                return true;
            }
            error_log("[ROLE VALIDATION] Role ID {$role} not found");
            return false;
        }
        
        // If role is string, check by role_name
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
                        // STEP 2: ROLE VALIDATION - Check BEFORE setting session
                        // ============================================================
                        $userRole = $loginResult['role'] ?? '';
                        $adminId = $loginResult['admin_id'] ?? null;
                        
                        // Use the helper function to validate role
                        $roleValid = validateRole($roleManager, $userRole);
                        
                        // Get role info for logging
                        $roleInfo = getRoleInfo($roleManager, $userRole);
                        $roleLevel = $roleInfo['role_level'] ?? 'N/A';
                        
                        error_log("[ADMIN LOGIN] Role validation result: " . ($roleValid ? 'VALID' : 'INVALID') . 
                                 " - Role: {$userRole}, Admin ID: {$adminId}");
                        
                        if (!$roleValid) {
                            // ============================================================
                            // INVALID ROLE - Security incident
                            // ============================================================
                            $error = 'Invalid account configuration. Please contact support.';
                            
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
                            
                            // ALWAYS destroy session if role is invalid
                            SessionManager::destroy();
                            
                            // Clear the login result
                            $loginResult['success'] = false;
                            
                            // Set error message
                            $error = 'Access denied: Invalid account permissions. Please contact system administrator.';
                            
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
                                // Only set session AFTER all checks pass
                                // (Session should have been set by AdminAuth, but we ensure it)
                                if (!SessionManager::isLoggedIn()) {
                                    SessionManager::set('admin_id', $adminId);
                                    SessionManager::set('admin_username', $username);
                                    SessionManager::set('admin_role', $userRole);
                                    SessionManager::set('admin_country', $systemCountry);
                                    SessionManager::set('logged_in', true);
                                    
                                    // Set the role info in session
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
  <title>VOUCHMORPH · ADMIN LOGIN</title>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'IBM Plex Mono', monospace;
      background:
        radial-gradient(1100px 500px at 15% -10%, rgba(255, 218, 99, .08), transparent 60%),
        linear-gradient(160deg, #001B44 0%, #002B6A 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 32px;
    }

    .login-container {
      max-width: 520px;
      width: 100%;
    }

    .login-header {
      text-align: center;
      margin-bottom: 36px;
    }
    .login-header h1 {
      color: #FFDA63;
      font-size: 2.2rem;
      letter-spacing: 4px;
      font-weight: 700;
      margin-bottom: 8px;
    }
    .login-header p {
      color: #A1B5D8;
      font-size: 1.0rem;
      letter-spacing: 2px;
    }

    .login-card {
      background: #fff;
      border: 3px solid #001B44;
      border-radius: 0;
      padding: 48px 44px 40px;
      position: relative;
      box-shadow: 8px 8px 0 #FFDA63;
    }
    .login-card::before {
      content: "";
      position: absolute;
      top: -3px;
      left: -3px;
      width: 14px;
      height: 14px;
      border-top: 4px solid #FFDA63;
      border-left: 4px solid #FFDA63;
      pointer-events: none;
    }
    .login-card::after {
      content: "";
      position: absolute;
      bottom: -3px;
      right: -3px;
      width: 14px;
      height: 14px;
      border-bottom: 4px solid #FFDA63;
      border-right: 4px solid #FFDA63;
      pointer-events: none;
    }

    .country-selector,
    .form-group {
      margin-bottom: 24px;
    }
    .country-selector label,
    .form-group label {
      display: block;
      font-size: 0.85rem;
      font-weight: 700;
      color: #001B44;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      margin-bottom: 8px;
    }
    .country-selector select,
    .form-group input {
      width: 100%;
      padding: 14px 16px;
      border: 2px solid #001B44;
      font-family: 'IBM Plex Mono', monospace;
      font-size: 1.0rem;
      background: #fff;
      transition: border-color 0.2s;
      border-radius: 0;
    }
    .country-selector select:focus,
    .form-group input:focus {
      outline: none;
      border-color: #FFDA63;
    }

    .error-message {
      background: #ffebee;
      border: 2px solid #c62828;
      color: #c62828;
      padding: 14px 16px;
      margin-bottom: 24px;
      font-size: 0.95rem;
      font-weight: 600;
      text-align: center;
      border-radius: 0;
    }
    .mfa-info {
      background: #e3f2fd;
      border: 2px solid #1976d2;
      color: #1976d2;
      padding: 14px 16px;
      margin-bottom: 24px;
      font-size: 0.95rem;
      text-align: center;
      border-radius: 0;
    }

    .login-btn {
      width: 100%;
      padding: 16px;
      background: #001B44;
      border: 2px solid #001B44;
      color: #fff;
      font-family: 'IBM Plex Mono', monospace;
      font-size: 1.1rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 2.5px;
      cursor: pointer;
      transition: all 0.2s;
      border-radius: 0;
      margin-top: 4px;
    }
    .login-btn:hover {
      background: #FFDA63;
      color: #001B44;
      border-color: #FFDA63;
    }

    .login-footer {
      margin-top: 28px;
      text-align: center;
      color: #A1B5D8;
      font-size: 0.9rem;
    }
    .system-badge {
      display: inline-block;
      padding: 6px 16px;
      background: rgba(255, 218, 99, 0.1);
      border: 1px solid #FFDA63;
      color: #FFDA63;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    @media (max-width: 480px) {
      .login-container { max-width: 100%; padding: 0 12px; }
      .login-card { padding: 30px 20px 24px; }
      .login-header h1 { font-size: 1.8rem; }
      .login-header p { font-size: 0.9rem; }
    }
  </style>
</head>
<body>
<div class="login-container">
  <div class="login-header">
    <h1>VOUCHMORPH</h1>
    <p>ADMINISTRATIVE ACCESS</p>
  </div>

  <div class="login-card">
    <?php if (isset($dbError)): ?>
      <div class="error-message">
        <strong>🔐 SYSTEM UNAVAILABLE</strong><br>
        <?php echo htmlspecialchars($dbError); ?>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($mfaRequired): ?>
      <div class="mfa-info">
        <strong>🔐 Two-Factor Authentication</strong><br>
        Please enter the authentication code from your authenticator app.
      </div>
    <?php endif; ?>

    <?php if (!isset($dbError)): ?>
    <form method="POST" action="">
      <input type="hidden" name="country" value="<?php echo htmlspecialchars($systemCountry); ?>">

      <?php if ($mfaRequired): ?>
        <div class="form-group">
          <label>AUTHENTICATION CODE</label>
          <input type="text" name="mfa_code" placeholder="000000" maxlength="6" autofocus required>
        </div>
      <?php else: ?>
        <div class="country-selector">
          <label>SYSTEM COUNTRY</label>
          <select name="country" onchange="this.form.submit()">
            <?php foreach ($availableCountries as $country): ?>
              <option value="<?php echo htmlspecialchars($country); ?>" <?php echo $country === $systemCountry ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($country); ?> · VOUCHMORPH
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>USERNAME / EMAIL</label>
          <input type="text" name="username" placeholder="Enter username or email" autofocus required>
        </div>

        <div class="form-group">
          <label>PASSWORD</label>
          <input type="password" name="password" placeholder="Enter your password" required>
        </div>
      <?php endif; ?>

      <button type="submit" class="login-btn">
        <?php echo $mfaRequired ? 'VERIFY CODE' : 'SIGN IN →'; ?>
      </button>
    </form>
    <?php endif; ?>

    <div class="login-footer">
      <div class="system-badge">
        <?php echo htmlspecialchars($systemCountry); ?> · <?php echo date('Y'); ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
