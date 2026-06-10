<?php
// public/user/login.php - COMPLETE FIXED VERSION
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;

SessionManager::start();

// Redirect if already logged in
if (SessionManager::isLoggedIn()) {
    header('Location: user_dashboard.php');
    exit();
}

// --------------------------------------------------
// 2️⃣ Load Country & Config
// --------------------------------------------------
try {
    $config = LoadCountry::getConfig();
    error_log("[USER LOGIN] Config loaded successfully");
} catch (Throwable $e) {
    error_log("[USER LOGIN] Config load error: " . $e->getMessage());
    die("Configuration error: " . $e->getMessage());
}

if (!defined('SYSTEM_COUNTRY')) {
    define('SYSTEM_COUNTRY', $config['country'] ?? 'BW');
}

$systemCountry = SYSTEM_COUNTRY;
$countryConfig = $config['country_settings'][$systemCountry] ?? [];

// Dynamic country phone settings
$countryDialCode   = $countryConfig['dial_code'] ?? '+267';
$localLength       = (int)($countryConfig['local_phone_length'] ?? 8);
$phonePlaceholder  = $countryConfig['phone_placeholder'] ?? str_repeat('0', $localLength);
$countryName       = $countryConfig['name'] ?? $systemCountry;

// Regex pattern for frontend validation
$phonePattern = '[0-9]{' . $localLength . '}';

// --------------------------------------------------
// 3️⃣ DB Bootstrap - Using DBConnection
// --------------------------------------------------
try {
    $db = DBConnection::getConnection();
    
    if (!$db) {
        throw new Exception("Database connection failed - DATABASE_URL not set or invalid");
    }
    
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    error_log("[USER LOGIN] Database connected successfully");
    
    $testStmt = $db->query("SELECT 1");
    $testStmt->fetch();
    error_log("[USER LOGIN] Database test query successful");
    
} catch (\Throwable $e) {
    error_log("[USER LOGIN] DB ERROR: " . $e->getMessage());
    $dbError = "Database connection failed: " . $e->getMessage();
    die($dbError);
}

// --------------------------------------------------
// 4️⃣ Helpers
// --------------------------------------------------
function normalizePhone(string $phoneInput, string $dialCode): string
{
    $phoneInput = preg_replace('/[^\d+]/', '', trim($phoneInput));

    if ($phoneInput === '') {
        return '';
    }

    if (str_starts_with($phoneInput, '+')) {
        return $phoneInput;
    }

    return $dialCode . ltrim($phoneInput, '0');
}

function getLocalPhonePart(string $fullPhone, string $dialCode): string
{
    if (str_starts_with($fullPhone, $dialCode)) {
        return substr($fullPhone, strlen($dialCode));
    }
    return ltrim($fullPhone, '0');
}

// -------------------------------------------------
// 5️⃣ Handle Login POST
// -------------------------------------------------
$error = '';
$phone = '';
$formattedPhone = '';
$loginMethod = $_POST['login_method'] ?? 'phone';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("[USER LOGIN] POST request received - Method: {$loginMethod}");
    
    $phoneInput = trim($_POST['phone'] ?? '');
    $formattedPhone = normalizePhone($phoneInput, $countryDialCode);
    $phone = getLocalPhonePart($formattedPhone, $countryDialCode);
    
    error_log("[USER LOGIN] Phone input: {$phoneInput}, Formatted: {$formattedPhone}");

    if ($loginMethod === 'pin') {
        // PIN LOGIN
        $pin = trim($_POST['pin'] ?? '');

        if ($phoneInput === '' || $pin === '') {
            $error = "Phone number and PIN are required.";
            error_log("[USER LOGIN] Missing phone or PIN");
        } else {
            try {
                $tableCheck = $db->query("SELECT 1 FROM users LIMIT 1");
                if (!$tableCheck) {
                    error_log("[USER LOGIN] Users table may not exist");
                    $error = "System configuration error. Please contact support.";
                } else {
                    // FIXED: Use correct column names from your table
                    $stmt = $db->prepare(
                        "SELECT user_id, phone, username, full_name, password_hash, verified, created_at, 
                                has_transaction_pin as pin_enabled
                         FROM users
                         WHERE phone = :phone
                         LIMIT 1"
                    );
                    $stmt->execute([':phone' => $formattedPhone]);
                    $user = $stmt->fetch(\PDO::FETCH_ASSOC);
                    
                    error_log("[USER LOGIN] User found: " . ($user ? 'YES' : 'NO'));

                    if (!$user || (int)$user['verified'] !== 1) {
                        $error = "Invalid login credentials.";
                        error_log("[USER LOGIN] User not found or not verified");
                    } elseif (!password_verify($pin, $user['password_hash'])) {
                        $error = "Invalid PIN.";
                        error_log("[USER LOGIN] PIN verification failed for {$formattedPhone}");
                    } else {
                        session_regenerate_id(true);

                        SessionManager::setUser([
                            'user_id'     => $user['user_id'],
                            'username'    => $user['username'] ?? '',
                            'full_name'   => $user['full_name'] ?? $user['username'],
                            'phone'       => $user['phone'],
                            'role'        => 'USER',
                            'country'     => $systemCountry,
                            'created_at'  => $user['created_at'] ?? null,
                            'pin_enabled' => (int)($user['pin_enabled'] ?? 0) === 1
                        ]);

                        error_log("[USER LOGIN] PIN LOGIN SUCCESS: {$formattedPhone}");
                        header('Location: user_dashboard.php');
                        exit();
                    }
                }
            } catch (\Throwable $e) {
                error_log("[USER LOGIN] PIN LOGIN QUERY ERROR: " . $e->getMessage());
                error_log("[USER LOGIN] Stack trace: " . $e->getTraceAsString());
                $error = "System error. Please try again.";
            }
        }
    } else {
        // PHONE LOGIN
        if ($phoneInput === '') {
            $error = "Phone number is required.";
            error_log("[USER LOGIN] Missing phone number");
        } else {
            try {
                $tableCheck = $db->query("SELECT 1 FROM users LIMIT 1");
                if (!$tableCheck) {
                    error_log("[USER LOGIN] Users table may not exist");
                    $error = "System configuration error. Please contact support.";
                } else {
                    // FIXED: Use correct column names from your table
                    $stmt = $db->prepare(
                        "SELECT user_id, phone, username, full_name, created_at, verified, 
                                has_transaction_pin as pin_enabled
                         FROM users
                         WHERE phone = :phone
                         LIMIT 1"
                    );
                    $stmt->execute([':phone' => $formattedPhone]);
                    $user = $stmt->fetch(\PDO::FETCH_ASSOC);
                    
                    error_log("[USER LOGIN] User found: " . ($user ? 'YES' : 'NO'));

                    if (!$user || (int)$user['verified'] !== 1) {
                        $error = "Invalid login credentials.";
                        error_log("[USER LOGIN] User not found or not verified");
                    } else {
                        session_regenerate_id(true);

                        SessionManager::setUser([
                            'user_id'     => $user['user_id'],
                            'username'    => $user['username'] ?? '',
                            'full_name'   => $user['full_name'] ?? $user['username'],
                            'phone'       => $user['phone'],
                            'role'        => 'USER',
                            'country'     => $systemCountry,
                            'created_at'  => $user['created_at'] ?? null,
                            'pin_enabled' => (int)($user['pin_enabled'] ?? 0) === 1
                        ]);

                        error_log("[USER LOGIN] PHONE LOGIN SUCCESS: {$formattedPhone}");
                        header('Location: user_dashboard.php');
                        exit();
                    }
                }
            } catch (\Throwable $e) {
                error_log("[USER LOGIN] PHONE LOGIN QUERY ERROR: " . $e->getMessage());
                error_log("[USER LOGIN] Stack trace: " . $e->getTraceAsString());
                $error = "System error. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph™ – Login</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
<link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&f[]=general-sans@400,500,600&f[]=space-grotesk@400,500,600&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: #050505;
        font-family: 'Inter', sans-serif;
        color: #FFFFFF;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        position: relative;
        overflow-x: hidden;
    }
    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: 
            linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
        background-size: 50px 50px;
        pointer-events: none;
        z-index: 0;
    }
    .cursor { width: 8px; height: 8px; background: #00F0FF; position: fixed; pointer-events: none; z-index: 9999; mix-blend-mode: difference; transition: transform 0.1s ease; }
    .cursor-follower { width: 40px; height: 40px; border: 1px solid rgba(0, 240, 255, 0.5); position: fixed; pointer-events: none; z-index: 9998; transition: 0.15s ease; }
    @media (max-width: 768px) { .cursor, .cursor-follower { display: none; } }
    .login-container {
        position: relative;
        z-index: 2;
        width: 100%;
        max-width: 520px;
        background: rgba(5, 5, 5, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(10px);
        border-radius: 0px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    }
    .login-header { padding: 2rem 2rem 1.5rem; text-align: center; border-bottom: 1px solid rgba(255, 255, 255, 0.08); }
    .login-header h1 { font-family: 'Clash Display', sans-serif; font-size: 2rem; font-weight: 700; letter-spacing: -0.02em; background: linear-gradient(135deg, #FFFFFF 0%, #00F0FF 40%, #B000FF 100%); -webkit-background-clip: text; background-clip: text; color: transparent; margin-bottom: 0.5rem; }
    .subtitle { font-size: 0.875rem; color: #A0A0B0; margin-bottom: 1rem; }
    .system-badge { display: inline-block; padding: 0.25rem 0.75rem; background: rgba(0, 240, 255, 0.1); border: 1px solid rgba(0, 240, 255, 0.3); font-size: 0.7rem; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase; border-radius: 0px; color: #00F0FF; }
    .login-tabs { display: flex; border-bottom: 1px solid rgba(255, 255, 255, 0.08); background: rgba(10, 10, 20, 0.5); }
    .tab-btn { flex: 1; padding: 1rem; background: none; border: none; font-family: 'General Sans', sans-serif; font-size: 0.875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.2s ease; color: #A0A0B0; border-bottom: 2px solid transparent; }
    .tab-btn.active { color: #00F0FF; border-bottom-color: #00F0FF; background: rgba(0, 240, 255, 0.05); }
    .tab-btn:hover:not(.active) { color: #FFFFFF; background: rgba(255, 255, 255, 0.03); }
    .login-form { padding: 2rem; }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; animation: fadeInUp 0.4s ease; }
    .form-group { margin-bottom: 1.5rem; }
    .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #C0C0D0; }
    .phone-input-container { display: flex; border: 1px solid rgba(255, 255, 255, 0.15); background: rgba(0, 0, 0, 0.5); transition: all 0.2s ease; border-radius: 0px; }
    .phone-input-container:focus-within { border-color: #00F0FF; box-shadow: 0 0 0 1px rgba(0, 240, 255, 0.2); }
    .phone-prefix { padding: 0.875rem 1rem; font-family: 'Space Grotesk', monospace; font-weight: 500; color: #00F0FF; background: rgba(0, 240, 255, 0.05); border-right: 1px solid rgba(255, 255, 255, 0.1); letter-spacing: 0.5px; }
    .form-control { flex: 1; border: none; padding: 0.875rem 1rem; font-size: 1rem; font-family: 'Inter', sans-serif; background: transparent; color: #FFFFFF; outline: none; }
    .form-control::placeholder { color: #505060; }
    .pin-input { font-family: 'Space Grotesk', monospace; font-size: 1.25rem; letter-spacing: 0.5rem; text-align: center; }
    .login-btn { width: 100%; padding: 1rem; background: linear-gradient(135deg, #00F0FF 0%, #B000FF 100%); color: #050505; border: none; font-family: 'General Sans', sans-serif; font-weight: 700; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.1em; cursor: pointer; transition: all 0.2s ease; margin-top: 0.5rem; border-radius: 0px; }
    .login-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(0, 240, 255, 0.4); }
    .error-message { background: rgba(255, 48, 48, 0.1); border-left: 3px solid #FF3030; padding: 0.875rem; margin-bottom: 1.5rem; font-size: 0.8125rem; color: #FF6060; }
    .security-notice { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid rgba(255, 255, 255, 0.05); font-size: 0.7rem; color: #606070; text-align: center; }
    .login-footer { padding: 1.25rem 2rem; border-top: 1px solid rgba(255, 255, 255, 0.05); background: rgba(10, 10, 20, 0.3); }
    .login-links { display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap; }
    .login-links a { color: #808090; text-decoration: none; font-size: 0.75rem; font-weight: 500; transition: color 0.2s; }
    .login-links a:hover { color: #00F0FF; }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 640px) {
        .login-container { margin: 1rem; }
        .login-header { padding: 1.5rem 1.5rem 1rem; }
        .login-header h1 { font-size: 1.5rem; }
        .login-form { padding: 1.5rem; }
        .login-footer { padding: 1rem 1.5rem; }
        .login-links { gap: 1rem; }
    }
</style>
</head>
<body>

<div class="cursor"></div>
<div class="cursor-follower"></div>

<div class="login-container">
    <div class="login-header">
        <h1>VOUCHMORPH<sup style="font-size: 0.7rem;">™</sup></h1>
        <div class="subtitle">Interoperability Platform</div>
        <div class="system-badge">
            <?= htmlspecialchars(strtoupper($countryName)) ?> • SECURE LOGIN
        </div>
    </div>

    <div class="login-tabs">
        <button type="button" class="tab-btn active" data-tab="phone">📱 PHONE LOGIN</button>
        <button type="button" class="tab-btn" data-tab="pin">🔐 PIN LOGIN</button>
    </div>

    <div class="login-form">
        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div id="phone-tab" class="tab-pane active">
            <form method="POST" novalidate>
                <input type="hidden" name="login_method" value="phone">
                <div class="form-group">
                    <label>MOBILE NUMBER</label>
                    <div class="phone-input-container">
                        <span class="phone-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                        <input
                            type="tel"
                            name="phone"
                            class="form-control"
                            required
                            value="<?= htmlspecialchars($phone) ?>"
                            placeholder="<?= htmlspecialchars($phonePlaceholder) ?>"
                            pattern="<?= htmlspecialchars($phonePattern) ?>"
                            inputmode="numeric"
                            autocomplete="tel-national"
                        >
                    </div>
                </div>
                <button type="submit" class="login-btn">ACCESS PLATFORM →</button>
            </form>
        </div>

        <div id="pin-tab" class="tab-pane">
            <form method="POST" novalidate>
                <input type="hidden" name="login_method" value="pin">
                <div class="form-group">
                    <label>MOBILE NUMBER</label>
                    <div class="phone-input-container">
                        <span class="phone-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                        <input
                            type="tel"
                            name="phone"
                            class="form-control"
                            required
                            value="<?= htmlspecialchars($phone) ?>"
                            placeholder="<?= htmlspecialchars($phonePlaceholder) ?>"
                            pattern="<?= htmlspecialchars($phonePattern) ?>"
                            inputmode="numeric"
                            autocomplete="tel-national"
                        >
                    </div>
                </div>
                <div class="form-group">
                    <label>PIN CODE</label>
                    <input
                        type="password"
                        name="pin"
                        class="form-control pin-input"
                        required
                        maxlength="6"
                        placeholder="••••••"
                        inputmode="numeric"
                        autocomplete="current-password"
                    >
                </div>
                <button type="submit" class="login-btn">LOGIN WITH PIN →</button>
            </form>
            <div class="security-notice">
                <strong>🔐 Forgot PIN?</strong> Use phone login to recover your account.
            </div>
        </div>
    </div>

    <div class="login-footer">
        <div class="login-links">
            <a href="register.php">REGISTER</a>
            <a href="forgot.php">RECOVER ACCOUNT</a>
            <a href="support.php">SUPPORT</a>
        </div>
    </div>
</div>

<script>
    const cursor = document.querySelector('.cursor');
    const follower = document.querySelector('.cursor-follower');
    
    if (cursor && follower) {
        document.addEventListener('mousemove', (e) => {
            cursor.style.left = e.clientX + 'px';
            cursor.style.top = e.clientY + 'px';
            follower.style.left = e.clientX - 16 + 'px';
            follower.style.top = e.clientY - 16 + 'px';
        });
    }
    
    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(button => {
        button.addEventListener('click', function () {
            const tab = this.dataset.tab;

            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });

            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            document.getElementById(tab + '-tab').classList.add('active');
            this.classList.add('active');
        });
    });
</script>
</body>
</html>
