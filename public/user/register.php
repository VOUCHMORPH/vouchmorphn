<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// ============================================================
// DYNAMIC COUNTRY CONFIGURATION LOADER - MULTI-COUNTRY SUPPORT
// ============================================================

// Define root path
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load configuration with better error handling
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
if (!file_exists($configPath)) {
    die("Configuration loader not found at: " . $configPath);
}

try {
    $config = require $configPath;
    if (!is_array($config)) {
        die("Configuration file did not return an array");
    }
} catch (Throwable $e) {
    die("Failed to load configuration: " . $e->getMessage());
}

// Set system country
$systemCountry = $config['country'] ?? 'BW';
define('SYSTEM_COUNTRY', $systemCountry);

// Validate required configuration
if (!isset($config['db']['swap']) || !is_array($config['db']['swap'])) {
    error_log("REGISTER ERROR: Swap database configuration missing for {$systemCountry}");
    error_log("Available DB configs: " . print_r(array_keys($config['db'] ?? []), true));
    die("System initialisation error: Swap database configuration missing. Please check your configuration.");
}

$sourceKey = $config['db']['source_client_key'] ?? 'cazacom';
if (!isset($config['db'][$sourceKey]) || !is_array($config['db'][$sourceKey])) {
    error_log("REGISTER ERROR: Source database configuration missing for key: {$sourceKey}");
    die("System initialisation error: Source database configuration missing for {$sourceKey}.");
}

// Load required files with error checking
$requiredFiles = [
    'SessionManager' => PROJECT_ROOT . '/src/Application/Utils/SessionManager.php',
    'DBConnection' => PROJECT_ROOT . '/src/Core/Database/DBConnection.php',
    'ProviderInterface' => PROJECT_ROOT . '/src/Infrastructure/SMS/Contracts/ProviderInterface.php',
    'SmsGatewayClient' => PROJECT_ROOT . '/src/Infrastructure/SMS/SmsGatewayClient.php',
    'CommunicationFactory' => PROJECT_ROOT . '/src/Core/Factories/CommunicationFactory.php'
];

foreach ($requiredFiles as $name => $path) {
    if (!file_exists($path)) {
        die("Required file not found: {$name} at {$path}");
    }
    require_once $path;
}

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Factories\CommunicationFactory;

SessionManager::start();

// Redirect if already logged in
if (SessionManager::isLoggedIn()) {
    header('Location: user_dashboard.php');
    exit();
}

// ----------------------------------------
// Country configuration bootstrap
// ----------------------------------------
$countryConfig = $config['country_settings'][$systemCountry] ?? [];
$countryDialCode = $countryConfig['dial_code'] ?? '+267';
$localLength = (int)($countryConfig['local_phone_length'] ?? 8);
$phonePlaceholder = $countryConfig['phone_placeholder'] ?? str_repeat('0', $localLength);
$countryName = $countryConfig['name'] ?? $systemCountry;
$countryCurrency = $countryConfig['currency'] ?? 'BWP';
$countryTimeZone = $countryConfig['timezone'] ?? 'Africa/Gaborone';

// Set timezone
date_default_timezone_set($countryTimeZone);

// ----------------------------------------
// Database configuration bootstrap
// ----------------------------------------
$allDbConfig = $config['db'];
$swapDbConfig = $allDbConfig['swap'];
$sourceDbConfig = $allDbConfig[$sourceKey];

// Add connection pool settings
$swapDbConfig['options'] = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 30,
    PDO::ATTR_PERSISTENT => false
];

$sourceDbConfig['options'] = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 30,
    PDO::ATTR_PERSISTENT => false
];

// ----------------------------------------
// Database connections with retry logic
// ----------------------------------------
$maxRetries = 3;
$retryDelay = 1; // seconds

function connectWithRetry($config, $maxRetries, $retryDelay) {
    $lastException = null;
    
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            $db = DBConnection::getInstance($config);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Test connection
            $db->query("SELECT 1");
            return $db;
        } catch (Throwable $e) {
            $lastException = $e;
            error_log("Database connection attempt " . ($i + 1) . " failed: " . $e->getMessage());
            if ($i < $maxRetries - 1) {
                sleep($retryDelay);
            }
        }
    }
    
    throw $lastException;
}

try {
    $swapDb = connectWithRetry($swapDbConfig, $maxRetries, $retryDelay);
    $sourceDb = connectWithRetry($sourceDbConfig, $maxRetries, $retryDelay);
} catch (Throwable $e) {
    error_log("REGISTER DB ERROR [{$systemCountry}]: " . $e->getMessage());
    error_log("Swap Config (hidden password): " . print_r(array_merge($swapDbConfig, ['password' => '***']), true));
    error_log("Source Config (hidden password): " . print_r(array_merge($sourceDbConfig, ['password' => '***']), true));
    
    // Show user-friendly message
    if (strpos($e->getMessage(), 'Unknown database') !== false) {
        die("System initialisation failed: Database not found. Please contact support.");
    } elseif (strpos($e->getMessage(), 'Access denied') !== false) {
        die("System initialisation failed: Database access denied. Please contact support.");
    } else {
        die("System initialisation failed: Unable to connect to database. Please try again later.");
    }
}

// ----------------------------------------
// Check and create required tables if missing
// ----------------------------------------
try {
    // Check if otp_codes table exists
    $tableCheck = $swapDb->query("SHOW TABLES LIKE 'otp_codes'");
    if ($tableCheck->rowCount() === 0) {
        // Create OTP codes table
        $swapDb->exec("
            CREATE TABLE IF NOT EXISTS `otp_codes` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `phone` varchar(20) NOT NULL,
                `code` varchar(10) NOT NULL,
                `expires_at` datetime NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `used` tinyint(1) DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `phone` (`phone`),
                KEY `expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        error_log("Created otp_codes table for {$systemCountry}");
    }
    
    // Check if users table exists in swap DB
    $tableCheck = $swapDb->query("SHOW TABLES LIKE 'users'");
    if ($tableCheck->rowCount() === 0) {
        // Create users table
        $swapDb->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `user_id` int(11) NOT NULL AUTO_INCREMENT,
                `phone` varchar(20) NOT NULL,
                `email` varchar(255) DEFAULT NULL,
                `full_name` varchar(255) DEFAULT NULL,
                `pin_code` varchar(255) DEFAULT NULL,
                `is_verified` tinyint(1) DEFAULT '0',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                `last_login` timestamp NULL DEFAULT NULL,
                `status` enum('active','suspended','deleted') DEFAULT 'active',
                PRIMARY KEY (`user_id`),
                UNIQUE KEY `phone` (`phone`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        error_log("Created users table for {$systemCountry}");
    }
} catch (Throwable $e) {
    error_log("Table creation error: " . $e->getMessage());
    // Don't die here - tables might already exist with different structure
}

// ----------------------------------------
// Communication configuration
// ----------------------------------------
$clientPartnerKey = $config['participants'][$sourceKey]['communication_key'] ?? 'CAZACOM';
$smsProvider = $config['communication']['providers'][$clientPartnerKey] ?? null;

if (!$smsProvider) {
    error_log("SMS provider not configured for {$clientPartnerKey}");
}

// ----------------------------------------
// Helper functions
// ----------------------------------------
function normalizePhone(string $phoneInput, string $dialCode): string
{
    // Remove all non-digit characters except +
    $phoneInput = preg_replace('/[^\d+]/', '', trim($phoneInput));
    
    if ($phoneInput === '') {
        return '';
    }
    
    // If already has +, return as is (but validate format)
    if (str_starts_with($phoneInput, '+')) {
        // Remove any extra + signs
        $phoneInput = '+' . preg_replace('/[^0-9]/', '', substr($phoneInput, 1));
        return $phoneInput;
    }
    
    // Remove leading zeros
    $phoneInput = ltrim($phoneInput, '0');
    
    // Add dial code
    return $dialCode . $phoneInput;
}

function getLocalPhonePart(string $fullPhone, string $dialCode): string
{
    if (str_starts_with($fullPhone, $dialCode)) {
        return substr($fullPhone, strlen($dialCode));
    }
    
    return ltrim($fullPhone, '0');
}

function validatePhoneFormat(string $phone, string $dialCode, int $localLength): bool
{
    // Remove + if present for validation
    $phoneClean = ltrim($phone, '+');
    
    // Check if starts with dial code (without +)
    if (str_starts_with($phoneClean, ltrim($dialCode, '+'))) {
        $localPart = substr($phoneClean, strlen(ltrim($dialCode, '+')));
        return (strlen($localPart) === $localLength && ctype_digit($localPart));
    }
    
    return false;
}

function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// ----------------------------------------
// AJAX POST: Send OTP
// ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // Get and validate input
        $phoneInput = trim($_POST['phone'] ?? '');
        if (empty($phoneInput)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
            exit;
        }
        
        $phone = normalizePhone($phoneInput, $countryDialCode);
        
        if ($phone === '') {
            echo json_encode(['success' => false, 'message' => 'Invalid phone number format.']);
            exit;
        }
        
        // Validate phone format
        if (!validatePhoneFormat($phone, $countryDialCode, $localLength)) {
            echo json_encode(['success' => false, 'message' => "Please enter a valid {$localLength}-digit phone number."]);
            exit;
        }
        
        // Check if exists in source client DB
        try {
            $stmt = $sourceDb->prepare("SELECT id, phone_number, full_name FROM users WHERE phone_number = :phone LIMIT 1");
            $stmt->execute([':phone' => $phone]);
            $sourceUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sourceUser) {
                echo json_encode(['success' => false, 'message' => "Phone number not found in our records. Please ensure you're registered with {$clientPartnerKey}."]);
                exit;
            }
        } catch (PDOException $e) {
            error_log("Source DB query error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Unable to verify phone number. Please try again.']);
            exit;
        }
        
        // Check if already registered in swap
        try {
            $stmt = $swapDb->prepare("SELECT user_id, phone FROM users WHERE phone = :phone LIMIT 1");
            $stmt->execute([':phone' => $phone]);
            
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                echo json_encode(['success' => false, 'message' => 'This phone number is already registered. Please login instead.']);
                exit;
            }
        } catch (PDOException $e) {
            error_log("Swap DB check error: " . $e->getMessage());
            // Continue anyway - table might not exist yet
        }
        
        // Generate OTP
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes expiry
        
        // Store OTP in database
        try {
            // Delete any existing OTP for this phone
            $swapDb->prepare("DELETE FROM otp_codes WHERE phone = :phone")->execute([':phone' => $phone]);
            
            // Insert new OTP
            $stmt = $swapDb->prepare("INSERT INTO otp_codes (phone, code, expires_at) VALUES (:phone, :code, :expires_at)");
            $stmt->execute([
                ':phone' => $phone,
                ':code' => $otp,
                ':expires_at' => $expiresAt
            ]);
        } catch (PDOException $e) {
            error_log("OTP storage error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Unable to process request. Please try again.']);
            exit;
        }
        
        // Send SMS with OTP
        try {
            $comm = CommunicationFactory::create($clientPartnerKey);
            $message = "Your {$countryName} SWAP registration OTP is: {$otp}. Valid for 5 minutes. Do not share with anyone.";
            
            // Add transaction ID for tracking
            $transactionId = uniqid('OTP_', true);
            $message .= " Ref: {$transactionId}";
            
            $result = $comm->sendSMS($phone, $message);
            
            if (!($result['success'] ?? false)) {
                // Log but don't fail - maybe email fallback?
                error_log("SMS sending failed for {$phone}: " . ($result['message'] ?? 'Unknown error'));
                
                // For development, still return success
                if (getenv('APP_ENV') === 'development') {
                    echo json_encode(['success' => true, 'message' => "OTP sent: {$otp} (Development mode - SMS not actually sent)"]);
                    exit;
                }
                
                throw new Exception($result['message'] ?? 'SMS provider failed to send message');
            }
            
            // Log successful OTP send
            error_log("OTP sent successfully to {$phone} with ID: {$transactionId}");
            
            echo json_encode(['success' => true, 'message' => 'OTP sent successfully! Check your phone.']);
            exit;
            
        } catch (Exception $e) {
            error_log("SMS sending error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Unable to send OTP at this time. Please try again later.']);
            exit;
        }
        
    } catch (Throwable $e) {
        error_log("REGISTER POST ERROR [{$systemCountry}]: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'System error occurred. Please try again.']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>VouchMorph™ – Register <?= htmlspecialchars($countryName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&f[]=general-sans@400,500,600&f[]=space-grotesk@400,500,600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        .cursor {
            width: 8px;
            height: 8px;
            background: #00F0FF;
            position: fixed;
            pointer-events: none;
            z-index: 9999;
            mix-blend-mode: difference;
            transition: transform 0.1s ease;
        }

        .cursor-follower {
            width: 40px;
            height: 40px;
            border: 1px solid rgba(0, 240, 255, 0.5);
            position: fixed;
            pointer-events: none;
            z-index: 9998;
            transition: 0.15s ease;
        }

        @media (max-width: 768px) {
            .cursor, .cursor-follower { display: none; }
        }

        .register-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 480px;
            background: rgba(5, 5, 5, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border-radius: 0px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .register-header {
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .register-header h1 {
            font-family: 'Clash Display', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, #FFFFFF 0%, #00F0FF 40%, #B000FF 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            font-size: 0.875rem;
            color: #A0A0B0;
            margin-bottom: 1rem;
        }

        .system-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(0, 240, 255, 0.1);
            border: 1px solid rgba(0, 240, 255, 0.3);
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            border-radius: 0px;
            color: #00F0FF;
        }

        .country-flag {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #00F0FF, #B000FF);
            margin-right: 8px;
            vertical-align: middle;
        }

        .register-form {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #C0C0D0;
        }

        .phone-input-container {
            display: flex;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(0, 0, 0, 0.5);
            transition: all 0.2s ease;
            border-radius: 0px;
        }

        .phone-input-container:focus-within {
            border-color: #00F0FF;
            box-shadow: 0 0 0 1px rgba(0, 240, 255, 0.2);
        }

        .phone-prefix {
            padding: 0.875rem 1rem;
            font-family: 'Space Grotesk', monospace;
            font-weight: 500;
            color: #00F0FF;
            background: rgba(0, 240, 255, 0.05);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            letter-spacing: 0.5px;
        }

        .form-control {
            flex: 1;
            border: none;
            padding: 0.875rem 1rem;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            background: transparent;
            color: #FFFFFF;
            outline: none;
        }

        .form-control::placeholder {
            color: #505060;
        }

        .form-control.otp-input {
            font-family: 'Space Grotesk', monospace;
            font-size: 1.25rem;
            letter-spacing: 0.5rem;
            text-align: center;
        }

        .btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #00F0FF 0%, #B000FF 100%);
            color: #050505;
            border: none;
            font-family: 'General Sans', sans-serif;
            font-weight: 700;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 0.5rem;
            border-radius: 0px;
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(0, 240, 255, 0.4);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn.loading {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid rgba(0, 0, 0, 0.3);
            border-top-color: #000;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .btn-secondary {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #FFFFFF;
            margin-top: 0;
        }

        .btn-secondary:hover {
            border-color: #00F0FF;
            background: rgba(0, 240, 255, 0.05);
            transform: translateY(-2px);
            box-shadow: none;
        }

        .message {
            margin-top: 1rem;
            padding: 0.75rem;
            font-size: 0.8125rem;
            text-align: center;
            background: rgba(0, 240, 255, 0.05);
            border-left: 3px solid #00F0FF;
            color: #A0A0B0;
            min-height: 50px;
            border-radius: 0px;
        }

        .message.error {
            background: rgba(255, 48, 48, 0.1);
            border-left-color: #FF3030;
            color: #FF6060;
        }

        .message.success {
            background: rgba(0, 240, 255, 0.1);
            border-left-color: #00F0FF;
            color: #00F0FF;
        }

        .message.info {
            background: rgba(255, 193, 7, 0.1);
            border-left-color: #FFC107;
            color: #FFC107;
        }

        .otp-section {
            display: none;
            margin-top: 0;
        }

        .resend-timer {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.75rem;
            color: #808090;
        }

        .resend-timer a {
            color: #00F0FF;
            text-decoration: none;
            cursor: pointer;
        }

        .resend-timer a.disabled {
            color: #505060;
            cursor: not-allowed;
        }

        .register-footer {
            padding: 1.25rem 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(10, 10, 20, 0.3);
            text-align: center;
        }

        .register-footer a {
            color: #808090;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: color 0.2s;
        }

        .register-footer a:hover {
            color: #00F0FF;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.4s ease;
        }

        @media (max-width: 640px) {
            .register-container {
                margin: 1rem;
            }
            .register-header {
                padding: 1.5rem 1.5rem 1rem;
            }
            .register-header h1 {
                font-size: 1.5rem;
            }
            .register-form {
                padding: 1.5rem;
            }
            .register-footer {
                padding: 1rem 1.5rem;
            }
        }
    </style>
</head>
<body>

<div class="cursor"></div>
<div class="cursor-follower"></div>

<div class="register-container">
    <div class="register-header">
        <h1>VOUCHMORPH<sup style="font-size: 0.7rem;">™</sup></h1>
        <div class="subtitle">Join the Financial Revolution</div>
        <div class="system-badge">
            <span class="country-flag"></span>
            <?= htmlspecialchars($countryName) ?> • <?= htmlspecialchars($countryCurrency) ?>
        </div>
    </div>

    <div class="register-form">
        <div id="register-step">
            <div class="form-group">
                <label>MOBILE NUMBER</label>
                <div class="phone-input-container">
                    <span class="phone-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                    <input type="tel" id="phone" class="form-control" placeholder="<?= htmlspecialchars($phonePlaceholder) ?>" autocomplete="off" maxlength="<?= $localLength ?>">
                </div>
                <div style="font-size: 0.7rem; color: #606070; margin-top: 0.5rem;">
                    Enter <?= $localLength ?>-digit number without <?= htmlspecialchars($countryDialCode) ?>
                </div>
            </div>
            <button class="btn" id="sendOtpBtn" onclick="sendOTP()">SEND OTP →</button>
        </div>

        <div id="otp-section" class="otp-section">
            <div class="form-group">
                <label>ENTER 6-DIGIT OTP</label>
                <input type="text" id="otp" class="form-control otp-input" maxlength="6" placeholder="••••••" autocomplete="off" pattern="[0-9]{6}" inputmode="numeric">
            </div>
            <button class="btn" id="verifyBtn" onclick="verifyOTP()">VERIFY & REGISTER →</button>
            <button class="btn btn-secondary" onclick="backToPhone()">← BACK</button>
            <div class="resend-timer" id="resendTimer"></div>
        </div>

        <div id="message" class="message"></div>
    </div>

    <div class="register-footer">
        <a href="login.php">ALREADY REGISTERED? LOGIN →</a>
    </div>
</div>

<script>
// Custom cursor effect
const cursor = document.querySelector('.cursor');
const follower = document.querySelector('.cursor-follower');

if (cursor && follower) {
    document.addEventListener('mousemove', (e) => {
        cursor.style.left = e.clientX + 'px';
        cursor.style.top = e.clientY + 'px';
        setTimeout(() => {
            follower.style.left = e.clientX - 16 + 'px';
            follower.style.top = e.clientY - 16 + 'px';
        }, 50);
    });
}

// Message display function
let messageTimeout = null;

function showMessage(text, type = 'info') {
    const msgEl = document.getElementById('message');
    msgEl.textContent = text;
    msgEl.className = 'message ' + type;
    msgEl.style.display = 'block';
    
    if (messageTimeout) {
        clearTimeout(messageTimeout);
    }
    
    if (type !== 'error') {
        messageTimeout = setTimeout(() => {
            if (document.getElementById('message').textContent === text) {
                document.getElementById('message').textContent = '';
                document.getElementById('message').className = 'message';
                msgEl.style.display = 'none';
            }
        }, 5000);
    }
}

// Loading state for buttons
function setButtonLoading(buttonId, isLoading, originalText = null) {
    const btn = document.getElementById(buttonId);
    if (!btn) return;
    
    if (isLoading) {
        btn.dataset.originalText = btn.textContent;
        btn.textContent = '';
        btn.classList.add('loading');
        btn.disabled = true;
    } else {
        btn.textContent = btn.dataset.originalText || (originalText || btn.textContent);
        btn.classList.remove('loading');
        btn.disabled = false;
    }
}

// Phone number validation
function validatePhone(phone) {
    const localLength = <?= $localLength ?>;
    const phoneClean = phone.replace(/\D/g, '');
    return phoneClean.length === localLength;
}

// Send OTP function
function sendOTP() {
    const phoneInput = document.getElementById('phone').value.trim();
    const dialCode = '<?= $countryDialCode ?>';
    const localLength = <?= $localLength ?>;
    
    if (!phoneInput) {
        showMessage('Please enter your phone number.', 'error');
        document.getElementById('phone').focus();
        return;
    }

    const phoneClean = phoneInput.replace(/\D/g, '');
    if (phoneClean.length !== localLength) {
        showMessage(`Please enter a valid ${localLength}-digit phone number.`, 'error');
        document.getElementById('phone').focus();
        return;
    }

    const fullPhone = dialCode + phoneClean;
    
    // Set loading state
    setButtonLoading('sendOtpBtn', true);
    showMessage('Sending OTP...', 'info');

    const formData = new URLSearchParams();
    formData.append('phone', fullPhone);

    fetch(window.location.href, {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData.toString()
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        setButtonLoading('sendOtpBtn', false);
        
        if (data.success) {
            showMessage(data.message, 'success');
            
            // Show OTP section with animation
            document.getElementById('register-step').style.display = 'none';
            const otpSection = document.getElementById('otp-section');
            otpSection.style.display = 'block';
            otpSection.classList.add('fade-in');
            
            // Focus on OTP input
            document.getElementById('otp').focus();
            
            // Start resend timer
            startResendTimer(60);
            
            // Store phone for verification
            sessionStorage.setItem('register_phone', fullPhone);
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        setButtonLoading('sendOtpBtn', false);
        console.error('Error:', error);
        showMessage('Network error. Please check your connection and try again.', 'error');
    });
}

// Resend timer
let resendTimerInterval = null;
let resendSecondsLeft = 0;

function startResendTimer(seconds) {
    resendSecondsLeft = seconds;
    updateResendTimerDisplay();
    
    if (resendTimerInterval) {
        clearInterval(resendTimerInterval);
    }
    
    resendTimerInterval = setInterval(() => {
        if (resendSecondsLeft <= 0) {
            clearInterval(resendTimerInterval);
            updateResendTimerDisplay(true);
        } else {
            resendSecondsLeft--;
            updateResendTimerDisplay();
        }
    }, 1000);
}

function updateResendTimerDisplay(isExpired = false) {
    const timerDiv = document.getElementById('resendTimer');
    if (!timerDiv) return;
    
    if (isExpired) {
        timerDiv.innerHTML = '<a onclick="resendOTP()" style="cursor: pointer;">Didn\'t receive OTP? Resend →</a>';
    } else {
        timerDiv.innerHTML = `Resend available in ${resendSecondsLeft} seconds`;
    }
}

function resendOTP() {
    const phoneInput = document.getElementById('phone').value.trim();
    const dialCode = '<?= $countryDialCode ?>';
    const phoneClean = phoneInput.replace(/\D/g, '');
    const fullPhone = dialCode + phoneClean;
    
    showMessage('Resending OTP...', 'info');
    
    const formData = new URLSearchParams();
    formData.append('phone', fullPhone);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('OTP resent successfully!', 'success');
            startResendTimer(60);
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Failed to resend OTP. Please try again.', 'error');
    });
}

// Verify OTP function
function verifyOTP() {
    const otp = document.getElementById('otp').value.trim();
    const phone = sessionStorage.getItem('register_phone');
    
    if (!phone) {
        showMessage('Session expired. Please go back and try again.', 'error');
        backToPhone();
        return;
    }
    
    if (!otp || otp.length !== 6 || !/^\d+$/.test(otp)) {
        showMessage('Please enter a valid 6-digit OTP.', 'error');
        document.getElementById('otp').focus();
        return;
    }
    
    setButtonLoading('verifyBtn', true);
    showMessage('Verifying OTP...', 'info');

    const formData = new URLSearchParams();
    formData.append('phone', phone);
    formData.append('otp', otp);

    fetch('verify_otp.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData.toString()
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        setButtonLoading('verifyBtn', false);
        
        if (data.success) {
            showMessage('Registration successful! Redirecting to dashboard...', 'success');
            setTimeout(() => {
                window.location.href = 'user_dashboard.php';
            }, 1500);
        } else {
            showMessage(data.message, 'error');
            document.getElementById('otp').value = '';
            document.getElementById('otp').focus();
        }
    })
    .catch(error => {
        setButtonLoading('verifyBtn', false);
        console.error('Error:', error);
        showMessage('Verification error. Please try again.', 'error');
    });
}

// Back to phone input
function backToPhone() {
    const otpSection = document.getElementById('otp-section');
    const registerStep = document.getElementById('register-step');
    
    otpSection.style.display = 'none';
    registerStep.style.display = 'block';
    
    // Clear OTP input
    document.getElementById('otp').value = '';
    
    // Clear message
    document.getElementById('message').textContent = '';
    document.getElementById('message').className = 'message';
    
    // Clear resend timer
    if (resendTimerInterval) {
        clearInterval(resendTimerInterval);
        resendTimerInterval = null;
    }
    
    // Clear stored phone
    sessionStorage.removeItem('register_phone');
    
    // Focus on phone input
    document.getElementById('phone').focus();
}

// Enter key handlers
document.getElementById('phone')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        sendOTP();
    }
});

document.getElementById('otp')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        verifyOTP();
    }
});

// Auto-format phone input (digits only)
document.getElementById('phone')?.addEventListener('input', function(e) {
    this.value = this.value.replace(/\D/g, '').slice(0, <?= $localLength ?>);
});

// OTP input - numbers only
document.getElementById('otp')?.addEventListener('input', function(e) {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
});

// Prevent form submission on enter in OTP field
document.getElementById('otp')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        verifyOTP();
    }
});

// Check if returning from OTP verification
window.addEventListener('load', function() {
    // Focus on phone input by default
    document.getElementById('phone')?.focus();
    
    // Clear any stored data on page load
    sessionStorage.removeItem('register_phone');
});

// Add rate limiting for button clicks
let lastOTPSend = 0;
const originalSendOTP = sendOTP;
window.sendOTP = function() {
    const now = Date.now();
    if (now - lastOTPSend < 30000) { // 30 second cooldown
        const secondsLeft = Math.ceil((30000 - (now - lastOTPSend)) / 1000);
        showMessage(`Please wait ${secondsLeft} seconds before requesting another OTP.`, 'error');
        return;
    }
    lastOTPSend = now;
    originalSendOTP();
};
</script>
</body>
</html>
