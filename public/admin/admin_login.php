<?php
declare(strict_types=1);

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================================
// ADMIN LOGIN - Using DBConnection (Single Source of Truth)
// ============================================================

// Define project root (goes up 2 levels: public/admin/ -> project root)
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Debug logging
error_log("[ADMIN LOGIN] Starting login process");

// Load required classes
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';
require_once PROJECT_ROOT . '/src/Security/Monitoring/ApiRateLimiter.php'; // ADDED

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;
use Security\Monitoring\ApiRateLimiter; // ADDED

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
    
} catch (Throwable $e) {
    error_log("[ADMIN LOGIN] DB Error: " . $e->getMessage());
    $dbError = $e->getMessage();
}

$error = '';
$mfaRequired = false;
$adminId = null;

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($auth)) {

    // --- RATE LIMITING (ADDED) ---
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateLimitKey = 'admin_login:' . $clientIp;
    $rateLimited = false;

    try {
        // 8 attempts per 5 minutes per IP — tune as needed
        $limiter = new ApiRateLimiter(8, 300);
        if (!$limiter->check($rateLimitKey)) {
            $rateLimited = true;
            error_log("[ADMIN LOGIN] Rate limit exceeded for IP: {$clientIp}");
        }
    } catch (\Throwable $e) {
        // Redis unreachable — log it, do NOT block login on infra failure
        error_log("[ADMIN LOGIN] Rate limiter unavailable: " . $e->getMessage());
    }

    if ($rateLimited) {
        $error = 'Too many login attempts. Please try again in a few minutes.';
    } else {
        try {
            if (isset($_POST['mfa_code'])) {
                // MFA verification
                $result = $auth->verifyMfa($_POST['mfa_code'], $systemCountry);
                if ($result['success']) {
                    header('Location: admin_dashboard.php?country=' . $systemCountry);
                    exit;
                } else {
                    $error = $result['message'];
                }
            } else {
                // Initial login
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';

                if (empty($username) || empty($password)) {
                    $error = 'Username and password are required';
                } else {
                    $result = $auth->login($username, $password, $systemCountry);
                    
                    if ($result['success']) {
                        if (isset($result['mfa_required']) && $result['mfa_required'] === true) {
                            $mfaRequired = true;
                            $adminId = $result['admin_id'];
                        } else {
                            header('Location: admin_dashboard.php?country=' . $systemCountry);
                            exit;
                        }
                    } else {
                        $error = $result['message'];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("[ADMIN LOGIN] Exception: " . $e->getMessage());
            $error = "Authentication error occurred.";
        }
    } // close rate-limit else
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
        body { font-family: 'IBM Plex Mono', monospace; background: linear-gradient(135deg, #001B44 0%, #002B6A 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-container { max-width: 420px; width: 100%; }
        .login-header { text-align: center; margin-bottom: 30px; }
        .login-header h1 { color: #FFDA63; font-size: 1.8rem; letter-spacing: 3px; font-weight: 700; margin-bottom: 10px; }
        .login-header p { color: #A1B5D8; font-size: 0.9rem; letter-spacing: 1px; }
        .login-card { background: #fff; border: 3px solid #001B44; border-radius: 0; padding: 40px 30px; box-shadow: 8px 8px 0 #FFDA63; }
        .country-selector { margin-bottom: 25px; }
        .country-selector label { display: block; font-size: 0.8rem; font-weight: 600; color: #001B44; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .country-selector select { width: 100%; padding: 12px; border: 2px solid #001B44; font-family: 'IBM Plex Mono', monospace; font-size: 0.9rem; background: #fff; cursor: pointer; }
        .country-selector select:focus { outline: none; border-color: #FFDA63; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: #001B44; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .form-group input { width: 100%; padding: 12px; border: 2px solid #001B44; font-family: 'IBM Plex Mono', monospace; font-size: 0.9rem; transition: border-color 0.2s; }
        .form-group input:focus { outline: none; border-color: #FFDA63; }
        .error-message { background: #ffebee; border: 2px solid #c62828; color: #c62828; padding: 12px; margin-bottom: 20px; font-size: 0.85rem; font-weight: 600; text-align: center; }
        .login-btn { width: 100%; padding: 14px; background: #001B44; border: none; color: #fff; font-family: 'IBM Plex Mono', monospace; font-size: 1rem; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; cursor: pointer; border: 2px solid #001B44; transition: all 0.2s; }
        .login-btn:hover { background: #FFDA63; color: #001B44; border-color: #FFDA63; }
        .login-footer { margin-top: 20px; text-align: center; color: #A1B5D8; font-size: 0.8rem; }
        .system-badge { display: inline-block; padding: 4px 12px; background: rgba(255, 218, 99, 0.1); border: 1px solid #FFDA63; color: #FFDA63; font-size: 0.7rem; text-transform: uppercase; margin-top: 20px; }
        .mfa-info { background: #e3f2fd; border: 2px solid #1976d2; color: #1976d2; padding: 12px; margin-bottom: 20px; font-size: 0.85rem; text-align: center; }
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
