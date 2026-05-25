<?php
declare(strict_types=1);

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================================
// ADMIN LOGIN - Fixed for working database connection
// ============================================================

// Define project root (goes up 2 levels: public/admin/ -> project root)
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Debug logging
error_log("[ADMIN LOGIN] Starting login process");

// Load configuration
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';

if (!file_exists($configPath)) {
    die("Configuration system not found.");
}

require_once $configPath;

try {
    $config = \Core\Config\LoadCountry::getConfig();
    if (!is_array($config)) {
        die("Configuration failed to load.");
    }
    error_log("[ADMIN LOGIN] Configuration loaded");
} catch (Throwable $e) {
    die("Config error: " . $e->getMessage());
}

// Get country
$countryCode = $_GET['country'] ?? $_POST['country'] ?? $_SESSION['admin_country'] ?? 'BW';
$systemCountry = strtoupper($countryCode);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_country'] = $systemCountry;

// Load required classes
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;

// Initialize database connection
try {
    // Get database config from loaded config
    if (isset($config['db']['swap']) && is_array($config['db']['swap'])) {
        $dbConfig = $config['db']['swap'];
        error_log("[ADMIN LOGIN] Using db.swap config");
    } else {
        // Fallback - use environment
        error_log("[ADMIN LOGIN] Using fallback config");
        $databaseUrl = getenv('DATABASE_URL');
        if ($databaseUrl) {
            $db = parse_url($databaseUrl);
            $dbConfig = [
                'host' => $db['host'] ?? 'localhost',
                'port' => (int)($db['port'] ?? 5432),
                'database' => ltrim($db['path'] ?? '', '/'),
                'username' => $db['user'] ?? 'postgres',
                'password' => $db['pass'] ?? '',
            ];
        } else {
            $dbConfig = [
                'host' => getenv('DB_HOST') ?: 'localhost',
                'port' => (int)(getenv('DB_PORT') ?: 5432),
                'database' => getenv('DB_NAME') ?: 'swap_system_bw',
                'username' => getenv('DB_USER') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
            ];
        }
    }
    
    // Ensure we have all required keys
    $dbConfig['type'] = 'pgsql';
    $dbConfig['options'] = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    $db = DBConnection::getInstance($dbConfig);
    
    // Test connection
    $stmt = $db->query("SELECT 1");
    $stmt->fetch();
    error_log("[ADMIN LOGIN] Database connected");
    
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
