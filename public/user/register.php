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

// Require the class definition
require_once $configPath;

// Use the static method to get config
try {
    $config = \Core\Config\LoadCountry::getConfig();
    if (!is_array($config)) {
        die("Configuration did not return an array");
    }
} catch (Throwable $e) {
    die("Failed to load configuration: " . $e->getMessage());
}

// Set system country - safely define constant only if not already defined
$systemCountry = $config['country'] ?? getenv('VM_COUNTRY') ?? 'BW';
if (!defined('SYSTEM_COUNTRY')) {
    define('SYSTEM_COUNTRY', $systemCountry);
}
if (!defined('SYSTEM_COUNTRY_CODE')) {
    $countryCode = $config['country_code'] ?? 'BW';
    define('SYSTEM_COUNTRY_CODE', $countryCode);
}

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

// ID validation rules per country
$idValidationRules = $countryConfig['id_validation'] ?? [
    'national_id' => ['pattern' => '/^[0-9]{9,12}$/', 'min_length' => 9, 'max_length' => 12, 'example' => '123456789', 'label' => 'National ID'],
    'drivers_license' => ['pattern' => '/^[A-Z0-9]{8,15}$/i', 'min_length' => 8, 'max_length' => 15, 'example' => 'BW12345678', 'label' => 'Driver\'s License'],
    'passport' => ['pattern' => '/^[A-Z0-9]{6,12}$/i', 'min_length' => 6, 'max_length' => 12, 'example' => 'BN123456', 'label' => 'Passport']
];

// Botswana-specific ID formats
if ($systemCountry === 'Botswana' || $systemCountry === 'BW') {
    $idValidationRules = [
        'national_id' => ['pattern' => '/^[0-9]{9}$/', 'min_length' => 9, 'max_length' => 9, 'example' => '123456789', 'label' => 'Omang (National ID)'],
        'drivers_license' => ['pattern' => '/^[A-Z0-9]{8,10}$/i', 'min_length' => 8, 'max_length' => 10, 'example' => 'BW12345678', 'label' => 'Driver\'s License'],
        'passport' => ['pattern' => '/^[A-Z0-9]{6,9}$/i', 'min_length' => 6, 'max_length' => 9, 'example' => 'BN123456', 'label' => 'Passport']
    ];
}

// Set timezone
date_default_timezone_set($countryTimeZone);

// ----------------------------------------
// Database configuration bootstrap
// ----------------------------------------
$allDbConfig = $config['db'];
$swapDbConfig = $allDbConfig['swap'];
$sourceDbConfig = $allDbConfig[$sourceKey];

// Detect database type
$dbDriver = $swapDbConfig['type'] ?? 'mysql';
$isPostgres = ($dbDriver === 'pgsql');

// Add connection options
$swapDbConfig['options'] = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 30
];

$sourceDbConfig['options'] = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 30
];

// ----------------------------------------
// Database connections with retry logic
// ----------------------------------------
$maxRetries = 3;
$retryDelay = 1;

function connectWithRetry($config, $maxRetries, $retryDelay) {
    $lastException = null;
    
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            $db = DBConnection::getInstance($config);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
    error_log("REGISTER DB ERROR: " . $e->getMessage());
    die("System initialisation failed: Unable to connect to database. Please try again later.");
}

// ----------------------------------------
// Create/update tables for multi-identifier support
// ----------------------------------------
try {
    // Get existing columns
    $columns = [];
    if ($isPostgres) {
        $colsResult = $swapDb->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'users'");
        while ($row = $colsResult->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['column_name'];
        }
    } else {
        $colsResult = $swapDb->query("SHOW COLUMNS FROM users");
        while ($row = $colsResult->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }
    }
    
    // Add missing columns for ID support
    if (!in_array('national_id', $columns)) {
        $swapDb->exec("ALTER TABLE users ADD COLUMN national_id VARCHAR(50) DEFAULT NULL");
        $swapDb->exec("CREATE INDEX idx_national_id ON users(national_id)");
    }
    if (!in_array('drivers_license', $columns)) {
        $swapDb->exec("ALTER TABLE users ADD COLUMN drivers_license VARCHAR(50) DEFAULT NULL");
        $swapDb->exec("CREATE INDEX idx_drivers_license ON users(drivers_license)");
    }
    if (!in_array('passport', $columns)) {
        $swapDb->exec("ALTER TABLE users ADD COLUMN passport VARCHAR(50) DEFAULT NULL");
        $swapDb->exec("CREATE INDEX idx_passport ON users(passport)");
    }
    if (!in_array('id_type', $columns)) {
        $swapDb->exec("ALTER TABLE users ADD COLUMN id_type VARCHAR(20) DEFAULT NULL");
    }
    if (!in_array('date_of_birth', $columns)) {
        $swapDb->exec("ALTER TABLE users ADD COLUMN date_of_birth DATE DEFAULT NULL");
    }
    
    // Create otp_logs table if not exists (based on your structure)
    if ($isPostgres) {
        $swapDb->exec("
            CREATE TABLE IF NOT EXISTS otp_logs (
                otp_id SERIAL PRIMARY KEY,
                identifier VARCHAR(100) NOT NULL,
                identifier_type VARCHAR(20) NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                purpose VARCHAR(50) DEFAULT 'registration',
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                attempts INT DEFAULT 0,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_otp_identifier ON otp_logs(identifier)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_otp_expires ON otp_logs(expires_at)");
    } else {
        $swapDb->exec("
            CREATE TABLE IF NOT EXISTS `otp_logs` (
                `otp_id` int(11) NOT NULL AUTO_INCREMENT,
                `identifier` varchar(100) NOT NULL,
                `identifier_type` varchar(20) NOT NULL,
                `code_hash` varchar(255) NOT NULL,
                `purpose` varchar(50) DEFAULT 'registration',
                `expires_at` datetime NOT NULL,
                `used_at` datetime DEFAULT NULL,
                `attempts` int(11) DEFAULT 0,
                `ip_address` varchar(45) DEFAULT NULL,
                `user_agent` text DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`otp_id`),
                KEY `idx_otp_identifier` (`identifier`),
                KEY `idx_otp_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    error_log("Database tables verified for {$systemCountry}");
} catch (Throwable $e) {
    error_log("Table creation/update warning: " . $e->getMessage());
}

// ----------------------------------------
// Communication configuration
// ----------------------------------------
$clientPartnerKey = 'CAZACOM';

// ----------------------------------------
// Helper functions
// ----------------------------------------
function normalizePhone(string $phoneInput, string $dialCode): string
{
    $phoneInput = preg_replace('/[^\d+]/', '', trim($phoneInput));
    
    if ($phoneInput === '') {
        return '';
    }
    
    if (str_starts_with($phoneInput, '+')) {
        return '+' . preg_replace('/[^0-9]/', '', substr($phoneInput, 1));
    }
    
    return $dialCode . ltrim($phoneInput, '0');
}

function validateIdentifier($value, $type, $rules): array
{
    $value = trim($value);
    if (empty($value)) {
        return ['valid' => false, 'message' => ucfirst(str_replace('_', ' ', $type)) . ' is required.'];
    }
    
    $rule = $rules[$type] ?? null;
    if (!$rule) {
        return ['valid' => false, 'message' => 'Invalid identifier type.'];
    }
    
    $length = strlen($value);
    if ($length < $rule['min_length'] || $length > $rule['max_length']) {
        return ['valid' => false, 'message' => sprintf('%s must be between %d and %d characters.', 
            ucfirst(str_replace('_', ' ', $type)), $rule['min_length'], $rule['max_length'])];
    }
    
    if (!preg_match($rule['pattern'], $value)) {
        return ['valid' => false, 'message' => sprintf('Invalid %s format. Example: %s', 
            ucfirst(str_replace('_', ' ', $type)), $rule['example'])];
    }
    
    return ['valid' => true, 'value' => $value];
}

function generateOTP(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// ----------------------------------------
// AJAX POST: Send OTP
// ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $inputType = $_POST['input_type'] ?? 'phone';
        $inputValue = trim($_POST['identifier'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
        
        if (empty($inputValue)) {
            echo json_encode(['success' => false, 'message' => 'Please provide your identifier.']);
            exit;
        }
        
        $userData = [];
        $identifierValue = null;
        $phoneNumber = null;
        
        // Handle phone number
        if ($inputType === 'phone') {
            $phoneNumber = normalizePhone($inputValue, $countryDialCode);
            if ($phoneNumber === '') {
                echo json_encode(['success' => false, 'message' => 'Invalid phone number format.']);
                exit;
            }
            
            // Check in source DB
            $stmt = $sourceDb->prepare("SELECT id, phone_number, full_name, email FROM users WHERE phone_number = :value LIMIT 1");
            $stmt->execute([':value' => $phoneNumber]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userData) {
                echo json_encode(['success' => false, 'message' => "Phone number not found in our records."]);
                exit;
            }
            
            // Check if already registered
            $stmt = $swapDb->prepare("SELECT user_id FROM users WHERE phone = :value LIMIT 1");
            $stmt->execute([':value' => $phoneNumber]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Phone number already registered. Please login.']);
                exit;
            }
            
            $identifierValue = $phoneNumber;
        } 
        // Handle ID documents
        else {
            $validation = validateIdentifier($inputValue, $inputType, $idValidationRules);
            if (!$validation['valid']) {
                echo json_encode(['success' => false, 'message' => $validation['message']]);
                exit;
            }
            
            $identifierValue = $validation['value'];
            
            // Map to column name
            $columnMap = [
                'national_id' => 'national_id',
                'drivers_license' => 'drivers_license',
                'passport' => 'passport'
            ];
            $identifierColumn = $columnMap[$inputType];
            
            // Check in source DB for this ID
            $stmt = $sourceDb->prepare("SELECT id, phone_number, full_name, email, {$identifierColumn} FROM users WHERE {$identifierColumn} = :value LIMIT 1");
            $stmt->execute([':value' => $identifierValue]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userData) {
                $label = $idValidationRules[$inputType]['label'] ?? ucfirst(str_replace('_', ' ', $inputType));
                echo json_encode(['success' => false, 'message' => "{$label} not found in our records."]);
                exit;
            }
            
            // Check if already registered in swap
            $stmt = $swapDb->prepare("SELECT user_id FROM users WHERE {$identifierColumn} = :value LIMIT 1");
            $stmt->execute([':value' => $identifierValue]);
            if ($stmt->fetch()) {
                $label = $idValidationRules[$inputType]['label'] ?? ucfirst(str_replace('_', ' ', $inputType));
                echo json_encode(['success' => false, 'message' => "{$label} already registered. Please login."]);
                exit;
            }
            
            $phoneNumber = $userData['phone_number'] ?? null;
            if ($phoneNumber) {
                $stmt = $swapDb->prepare("SELECT user_id FROM users WHERE phone = :phone LIMIT 1");
                $stmt->execute([':phone' => $phoneNumber]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Associated phone number already registered. Please login.']);
                    exit;
                }
            }
        }
        
        // Generate OTP
        $otpPlain = generateOTP();
        $otpHash = password_hash($otpPlain, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300);
        
        // Get IP and user agent
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Invalidate any existing unused OTPs for this identifier
        $stmt = $swapDb->prepare("
            UPDATE otp_logs 
            SET used_at = NOW() 
            WHERE identifier = :identifier AND used_at IS NULL
        ");
        $stmt->execute([':identifier' => $identifierValue]);
        
        // Insert new OTP
        $stmt = $swapDb->prepare("
            INSERT INTO otp_logs 
            (identifier, identifier_type, code_hash, purpose, expires_at, attempts, ip_address, user_agent, created_at) 
            VALUES 
            (:identifier, :identifier_type, :code_hash, :purpose, :expires_at, 0, :ip_address, :user_agent, NOW())
        ");
        
        $stmt->execute([
            ':identifier' => $identifierValue,
            ':identifier_type' => $inputType,
            ':code_hash' => $otpHash,
            ':purpose' => 'registration',
            ':expires_at' => $expiresAt,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
        
        $otpId = $swapDb->lastInsertId();
        
        // Store OTP in session for verification (since we need plain text to compare)
        $_SESSION['otp_verification'][$identifierValue] = $otpPlain;
        $_SESSION['otp_verification_expires'][$identifierValue] = time() + 300;
        
        // Store registration data in session
        $_SESSION['temp_registration'] = [
            'otp_id' => $otpId,
            'identifier_type' => $inputType,
            'identifier_value' => $identifierValue,
            'identifier_column' => $identifierColumn ?? null,
            'full_name' => $fullName ?: ($userData['full_name'] ?? null),
            'date_of_birth' => $dateOfBirth,
            'source_user_id' => $userData['id'] ?? null,
            'phone_number' => $phoneNumber,
            'email' => $userData['email'] ?? null
        ];
        
        // Send OTP via SMS if phone available
        $smsSent = false;
        
        if ($phoneNumber) {
            try {
                $comm = CommunicationFactory::create($clientPartnerKey);
                $message = "Your {$countryName} SWAP registration verification code is: {$otpPlain}. Valid for 5 minutes. Do not share this code with anyone.";
                $result = $comm->sendSMS($phoneNumber, $message);
                $smsSent = ($result['success'] ?? false);
                
                if ($smsSent) {
                    error_log("OTP sent via SMS to {$phoneNumber}");
                }
            } catch (Exception $e) {
                error_log("SMS sending error: " . $e->getMessage());
            }
        }
        
        // Return response
        if ($smsSent) {
            echo json_encode(['success' => true, 'message' => 'Verification code sent to your phone!', 'has_phone' => true]);
        } else if ($phoneNumber) {
            // For development, show OTP
            if (getenv('APP_ENV') === 'development') {
                echo json_encode(['success' => true, 'message' => "DEV MODE: Your code is {$otpPlain}", 'has_phone' => false, 'show_otp' => true, 'otp' => $otpPlain]);
            } else {
                echo json_encode(['success' => true, 'message' => "Please check your phone for the verification code.", 'has_phone' => true]);
            }
        } else {
            // No phone on file, show OTP on screen
            echo json_encode(['success' => true, 'message' => "Your verification code is: {$otpPlain}", 'show_otp' => true, 'otp' => $otpPlain, 'has_phone' => false]);
        }
        exit;
        
    } catch (Throwable $e) {
        error_log("REGISTER POST ERROR: " . $e->getMessage());
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
            max-width: 520px;
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

        .selector-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .selector-tab {
            padding: 0.5rem 1rem;
            background: transparent;
            border: none;
            color: #808090;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 0px;
        }

        .selector-tab.active {
            color: #00F0FF;
            border-bottom: 2px solid #00F0FF;
        }

        .selector-tab:hover {
            color: #00F0FF;
        }

        .input-container {
            display: flex;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(0, 0, 0, 0.5);
            transition: all 0.2s ease;
            border-radius: 0px;
        }

        .input-container:focus-within {
            border-color: #00F0FF;
            box-shadow: 0 0 0 1px rgba(0, 240, 255, 0.2);
        }

        .input-prefix {
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

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
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

        .otp-display {
            background: rgba(0, 240, 255, 0.2);
            padding: 1rem;
            text-align: center;
            font-size: 1.5rem;
            font-family: monospace;
            letter-spacing: 0.5rem;
            margin: 1rem 0;
            border: 1px solid rgba(0, 240, 255, 0.3);
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

        .help-text {
            font-size: 0.7rem;
            color: #606070;
            margin-top: 0.5rem;
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
            .selector-tabs {
                gap: 0.25rem;
            }
            .selector-tab {
                padding: 0.5rem 0.75rem;
                font-size: 0.7rem;
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
            <?= htmlspecialchars($systemCountry) ?> • <?= htmlspecialchars($countryCurrency) ?>
        </div>
    </div>

    <div class="register-form">
        <!-- Identifier Type Selector -->
        <div class="selector-tabs">
            <button class="selector-tab active" data-type="phone">📱 Phone</button>
            <button class="selector-tab" data-type="national_id">🆔 National ID</button>
            <button class="selector-tab" data-type="drivers_license">🚗 Driver's License</button>
            <button class="selector-tab" data-type="passport">📖 Passport</button>
        </div>

        <div id="register-step">
            <div class="form-group" id="identifier-group">
                <label id="identifier-label">MOBILE NUMBER</label>
                <div class="input-container" id="input-container">
                    <span class="input-prefix" id="input-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                    <input type="tel" id="identifier" class="form-control" placeholder="<?= htmlspecialchars($phonePlaceholder) ?>" autocomplete="off">
                </div>
                <div class="help-text" id="help-text">Enter <?= $localLength ?>-digit number without <?= htmlspecialchars($countryDialCode) ?></div>
            </div>
            
            <div class="form-group" id="fullname-group" style="display: none;">
                <label>FULL NAME (Optional)</label>
                <input type="text" id="full_name" class="form-control" placeholder="Enter your full name" autocomplete="off">
            </div>
            
            <div class="form-group" id="dob-group" style="display: none;">
                <label>DATE OF BIRTH (Optional)</label>
                <input type="date" id="date_of_birth" class="form-control">
            </div>
            
            <button class="btn" id="sendOtpBtn" onclick="sendOTP()">CONTINUE →</button>
        </div>

        <div id="otp-section" class="otp-section">
            <div class="form-group">
                <label>ENTER VERIFICATION CODE</label>
                <input type="text" id="otp" class="form-control otp-input" maxlength="6" placeholder="••••••" autocomplete="off" inputmode="numeric">
            </div>
            <div id="otp-display" class="otp-display" style="display: none;"></div>
            <button class="btn" id="verifyBtn" onclick="verifyOTP()">VERIFY & REGISTER →</button>
            <button class="btn btn-secondary" onclick="backToIdentifier()">← BACK</button>
            <div id="resendTimer" style="text-align: center; margin-top: 1rem; font-size: 0.75rem; color: #808090;"></div>
        </div>

        <div id="message" class="message"></div>
    </div>

    <div class="register-footer">
        <a href="login.php">ALREADY REGISTERED? LOGIN →</a>
    </div>
</div>

<script>
// Configuration from PHP
const countryDialCode = '<?= $countryDialCode ?>';
const localLength = <?= $localLength ?>;
const idValidationRules = <?= json_encode($idValidationRules) ?>;

let currentIdentifierType = 'phone';
let resendTimerInterval = null;
let resendSecondsLeft = 0;

// Tab switching
document.querySelectorAll('.selector-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.selector-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        currentIdentifierType = this.dataset.type;
        updateFormForIdentifierType(currentIdentifierType);
    });
});

function updateFormForIdentifierType(type) {
    const labelEl = document.getElementById('identifier-label');
    const prefixEl = document.getElementById('input-prefix');
    const inputEl = document.getElementById('identifier');
    const helpTextEl = document.getElementById('help-text');
    const fullnameGroup = document.getElementById('fullname-group');
    const dobGroup = document.getElementById('dob-group');
    
    if (type === 'phone') {
        labelEl.textContent = 'MOBILE NUMBER';
        prefixEl.style.display = 'flex';
        prefixEl.textContent = countryDialCode;
        inputEl.placeholder = '7' + '0'.repeat(localLength - 1);
        helpTextEl.textContent = `Enter ${localLength}-digit number without ${countryDialCode}`;
        inputEl.maxLength = localLength;
        inputEl.type = 'tel';
        fullnameGroup.style.display = 'none';
        dobGroup.style.display = 'none';
    } else {
        const rules = idValidationRules[type];
        const label = rules?.label || type.replace('_', ' ').toUpperCase();
        labelEl.textContent = label;
        prefixEl.style.display = 'none';
        inputEl.placeholder = rules?.example || `Enter your ${label}`;
        inputEl.maxLength = rules?.max_length || 50;
        inputEl.type = 'text';
        helpTextEl.textContent = `Format: ${rules?.example || 'Alphanumeric'}`;
        fullnameGroup.style.display = 'block';
        dobGroup.style.display = 'block';
    }
    
    inputEl.value = '';
}

function showMessage(text, type = 'info') {
    const msgEl = document.getElementById('message');
    msgEl.textContent = text;
    msgEl.className = 'message ' + type;
    
    setTimeout(() => {
        if (document.getElementById('message').textContent === text) {
            msgEl.textContent = '';
            msgEl.className = 'message';
        }
    }, 5000);
}

function sendOTP() {
    const identifier = document.getElementById('identifier').value.trim();
    const fullName = document.getElementById('full_name')?.value.trim() || '';
    const dateOfBirth = document.getElementById('date_of_birth')?.value || '';
    
    if (!identifier) {
        showMessage('Please enter your identifier.', 'error');
        document.getElementById('identifier').focus();
        return;
    }
    
    // Validate based on type
    if (currentIdentifierType === 'phone') {
        const phoneClean = identifier.replace(/\D/g, '');
        if (phoneClean.length !== localLength) {
            showMessage(`Please enter a valid ${localLength}-digit phone number.`, 'error');
            return;
        }
    } else {
        const rules = idValidationRules[currentIdentifierType];
        if (rules) {
            const value = identifier;
            if (value.length < rules.min_length || value.length > rules.max_length) {
                showMessage(`${rules.label || currentIdentifierType} must be between ${rules.min_length} and ${rules.max_length} characters.`, 'error');
                return;
            }
            if (!new RegExp(rules.pattern).test(value)) {
                showMessage(`Invalid format. Example: ${rules.example}`, 'error');
                return;
            }
        }
    }
    
    const btn = document.getElementById('sendOtpBtn');
    const originalText = btn.textContent;
    btn.textContent = 'SENDING...';
    btn.disabled = true;
    
    showMessage('Verifying your details...', 'info');
    
    const formData = new URLSearchParams();
    formData.append('input_type', currentIdentifierType);
    formData.append('identifier', identifier);
    formData.append('full_name', fullName);
    formData.append('date_of_birth', dateOfBirth);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        btn.textContent = originalText;
        btn.disabled = false;
        
        if (data.success) {
            if (data.show_otp && data.otp) {
                const otpDisplay = document.getElementById('otp-display');
                otpDisplay.style.display = 'block';
                otpDisplay.innerHTML = `Your verification code is: <strong>${data.otp}</strong><br><small>Please write this down</small>`;
            }
            
            showMessage(data.message, 'success');
            document.getElementById('register-step').style.display = 'none';
            const otpSection = document.getElementById('otp-section');
            otpSection.style.display = 'block';
            otpSection.classList.add('fade-in');
            document.getElementById('otp').focus();
            startResendTimer(60);
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        btn.textContent = originalText;
        btn.disabled = false;
        console.error('Error:', error);
        showMessage('Network error. Please try again.', 'error');
    });
}

function startResendTimer(seconds) {
    resendSecondsLeft = seconds;
    updateResendTimerDisplay();
    
    if (resendTimerInterval) clearInterval(resendTimerInterval);
    
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
        timerDiv.innerHTML = '<a onclick="resendOTP()" style="cursor: pointer; color: #00F0FF;">Didn\'t receive code? Resend →</a>';
    } else {
        timerDiv.innerHTML = `Resend available in ${resendSecondsLeft} seconds`;
    }
}

function resendOTP() {
    const identifier = document.getElementById('identifier').value.trim();
    
    showMessage('Resending verification code...', 'info');
    
    const formData = new URLSearchParams();
    formData.append('input_type', currentIdentifierType);
    formData.append('identifier', identifier);
    formData.append('resend', '1');
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.show_otp && data.otp) {
                const otpDisplay = document.getElementById('otp-display');
                otpDisplay.style.display = 'block';
                otpDisplay.innerHTML = `Your verification code is: <strong>${data.otp}</strong>`;
            }
            showMessage('Verification code resent!', 'success');
            startResendTimer(60);
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        showMessage('Failed to resend code.', 'error');
    });
}

function verifyOTP() {
    const otp = document.getElementById('otp').value.trim();
    const identifier = document.getElementById('identifier').value.trim();
    
    if (!otp || otp.length !== 6 || !/^\d+$/.test(otp)) {
        showMessage('Please enter a valid 6-digit verification code.', 'error');
        document.getElementById('otp').focus();
        return;
    }
    
    const btn = document.getElementById('verifyBtn');
    btn.textContent = 'VERIFYING...';
    btn.disabled = true;
    showMessage('Verifying...', 'info');
    
    const formData = new URLSearchParams();
    formData.append('action', 'verify');
    formData.append('input_type', currentIdentifierType);
    formData.append('identifier', identifier);
    formData.append('otp', otp);
    
    fetch('verify_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        btn.textContent = 'VERIFY & REGISTER →';
        btn.disabled = false;
        
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
        btn.textContent = 'VERIFY & REGISTER →';
        btn.disabled = false;
        console.error('Error:', error);
        showMessage('Verification error. Please try again.', 'error');
    });
}

function backToIdentifier() {
    document.getElementById('otp-section').style.display = 'none';
    document.getElementById('register-step').style.display = 'block';
    document.getElementById('otp').value = '';
    document.getElementById('otp-display').style.display = 'none';
    document.getElementById('message').textContent = '';
    document.getElementById('message').className = 'message';
    if (resendTimerInterval) clearInterval(resendTimerInterval);
}

// Enter key handlers
document.getElementById('identifier')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') sendOTP();
});
document.getElementById('otp')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') verifyOTP();
});

// Auto-format phone input
document.getElementById('identifier')?.addEventListener('input', function(e) {
    if (currentIdentifierType === 'phone') {
        this.value = this.value.replace(/\D/g, '').slice(0, localLength);
    }
});

// Focus on load
window.addEventListener('load', function() {
    document.getElementById('identifier')?.focus();
    updateFormForIdentifierType('phone');
});
</script>
</body>
</html>
