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

// Load configuration
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
if (!file_exists($configPath)) {
    die("Configuration loader not found at: " . $configPath);
}

require_once $configPath;

try {
    $config = \Core\Config\LoadCountry::getConfig();
    if (!is_array($config)) {
        die("Configuration did not return an array");
    }
} catch (Throwable $e) {
    die("Failed to load configuration: " . $e->getMessage());
}

// Set system country
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
    die("System initialisation error: Swap database configuration missing.");
}

$sourceKey = $config['db']['source_client_key'] ?? 'cazacom';
if (!isset($config['db'][$sourceKey]) || !is_array($config['db'][$sourceKey])) {
    error_log("REGISTER ERROR: Source database configuration missing for key: {$sourceKey}");
    die("System initialisation error: Source database configuration missing.");
}

// Load required files
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
// Country configuration
// ----------------------------------------
$countryConfig = $config['country_settings'][$systemCountry] ?? [];
$countryDialCode = $countryConfig['dial_code'] ?? '+267';
$localLength = (int)($countryConfig['local_phone_length'] ?? 8);
$phonePlaceholder = $countryConfig['phone_placeholder'] ?? str_repeat('0', $localLength);
$countryName = $countryConfig['name'] ?? $systemCountry;
$countryCurrency = $countryConfig['currency'] ?? 'BWP';
$countryTimeZone = $countryConfig['timezone'] ?? 'Africa/Gaborone';

date_default_timezone_set($countryTimeZone);

// ----------------------------------------
// Database connections
// ----------------------------------------
$allDbConfig = $config['db'];
$swapDbConfig = $allDbConfig['swap'];
$sourceDbConfig = $allDbConfig[$sourceKey];

$dbDriver = $swapDbConfig['type'] ?? 'mysql';
$isPostgres = ($dbDriver === 'pgsql');

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

try {
    $swapDb = DBConnection::getInstance($swapDbConfig);
    $swapDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sourceDb = DBConnection::getInstance($sourceDbConfig);
    $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log("REGISTER DB ERROR: " . $e->getMessage());
    die("System initialisation failed: Unable to connect to database.");
}

// ----------------------------------------
// Create/update tables with ALL identifier columns
// ----------------------------------------
try {
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
    
    // All identifier columns - up to 3 phone numbers
    $idColumns = [
        'phone2' => 'VARCHAR(50) DEFAULT NULL',
        'phone3' => 'VARCHAR(50) DEFAULT NULL',
        'national_id' => 'VARCHAR(100) DEFAULT NULL',
        'drivers_license' => 'VARCHAR(100) DEFAULT NULL',
        'passport' => 'VARCHAR(100) DEFAULT NULL',
        'id_type' => 'VARCHAR(50) DEFAULT NULL',
        'date_of_birth' => 'DATE DEFAULT NULL',
        'email' => 'VARCHAR(255) DEFAULT NULL'
    ];
    
    foreach ($idColumns as $col => $definition) {
        if (!in_array($col, $columns)) {
            $swapDb->exec("ALTER TABLE users ADD COLUMN {$col} {$definition}");
        }
    }
    
    // Add indexes for faster lookups
    if ($isPostgres) {
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_phone2 ON users(phone2)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_phone3 ON users(phone3)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_national_id ON users(national_id)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_drivers_license ON users(drivers_license)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_passport ON users(passport)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
    } else {
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_phone2 ON users(phone2)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_phone3 ON users(phone3)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_national_id ON users(national_id)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_drivers_license ON users(drivers_license)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_passport ON users(passport)");
        $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
    }
    
    // Create user_identifiers table for additional identifiers
    if ($isPostgres) {
        $swapDb->exec("
            CREATE TABLE IF NOT EXISTS user_identifiers (
                id SERIAL PRIMARY KEY,
                user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
                identifier_type VARCHAR(50) NOT NULL,
                identifier_value VARCHAR(255) NOT NULL,
                is_verified BOOLEAN DEFAULT FALSE,
                verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(identifier_type, identifier_value)
            )
        ");
    } else {
        $swapDb->exec("
            CREATE TABLE IF NOT EXISTS user_identifiers (
                id INT(11) NOT NULL AUTO_INCREMENT,
                user_id INT(11) NOT NULL,
                identifier_type VARCHAR(50) NOT NULL,
                identifier_value VARCHAR(255) NOT NULL,
                is_verified TINYINT(1) DEFAULT 0,
                verified_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_identifier (identifier_type, identifier_value),
                KEY idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // Create otp_logs table
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
                PRIMARY KEY (`otp_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Throwable $e) {
    error_log("Table creation warning: " . $e->getMessage());
}

// ----------------------------------------
// Helper functions
// ----------------------------------------
$clientPartnerKey = 'CAZACOM';

function normalizePhone(string $phoneInput, string $dialCode): string
{
    $phoneInput = preg_replace('/[^\d+]/', '', trim($phoneInput));
    if ($phoneInput === '') return '';
    if (str_starts_with($phoneInput, '+')) {
        return '+' . preg_replace('/[^0-9]/', '', substr($phoneInput, 1));
    }
    return $dialCode . ltrim($phoneInput, '0');
}

function generateOTP(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendEmailOTP($to, $otp, $countryName)
{
    $subject = "Your VouchMorph Verification Code";
    $message = "
        <html>
        <head><title>Verification Code</title></head>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Welcome to VouchMorph {$countryName}!</h2>
            <p>Your verification code is: <strong style='font-size: 24px; color: #00F0FF;'>{$otp}</strong></p>
            <p>This code expires in 5 minutes.</p>
            <p><strong>Never share this code with anyone.</strong></p>
            <hr>
            <small>VouchMorph - Financial Revolution</small>
        </body>
        </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: VouchMorph <noreply@vouchmorph.com>" . "\r\n";
    
    return mail($to, $subject, $message, $headers);
}

function verifyIdentifierInSourceDB($sourceDb, $identifierType, $identifierValue, $countryDialCode)
{
    $userData = [];
    $phoneNumber = null;
    $emailAddress = null;
    
    switch ($identifierType) {
        case 'phone':
            $phoneNumber = normalizePhone($identifierValue, $countryDialCode);
            $stmt = $sourceDb->prepare("SELECT id, phone_number, full_name, email FROM users WHERE phone_number = :value LIMIT 1");
            $stmt->execute([':value' => $phoneNumber]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userData) {
                $phoneNumber = $userData['phone_number'];
                $emailAddress = $userData['email'] ?? null;
            }
            break;
            
        case 'email':
            $emailAddress = strtolower(trim($identifierValue));
            $stmt = $sourceDb->prepare("SELECT id, phone_number, full_name, email FROM users WHERE email = :value LIMIT 1");
            $stmt->execute([':value' => $emailAddress]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userData) {
                $phoneNumber = $userData['phone_number'] ?? null;
                $emailAddress = $userData['email'];
            }
            break;
            
        case 'national_id':
            $stmt = $sourceDb->prepare("SELECT id, phone_number, full_name, email, national_id FROM users WHERE national_id = :value LIMIT 1");
            $stmt->execute([':value' => $identifierValue]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userData) {
                $phoneNumber = $userData['phone_number'] ?? null;
                $emailAddress = $userData['email'] ?? null;
            }
            break;
            
        case 'drivers_license':
            $stmt = $sourceDb->prepare("SELECT id, phone_number, full_name, email, drivers_license FROM users WHERE drivers_license = :value LIMIT 1");
            $stmt->execute([':value' => $identifierValue]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userData) {
                $phoneNumber = $userData['phone_number'] ?? null;
                $emailAddress = $userData['email'] ?? null;
            }
            break;
            
        case 'passport':
            $stmt = $sourceDb->prepare("SELECT id, phone_number, full_name, email, passport FROM users WHERE passport = :value LIMIT 1");
            $stmt->execute([':value' => $identifierValue]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userData) {
                $phoneNumber = $userData['phone_number'] ?? null;
                $emailAddress = $userData['email'] ?? null;
            }
            break;
            
        default:
            return ['valid' => false, 'message' => 'Invalid identifier type'];
    }
    
    if (!$userData) {
        $labels = [
            'phone' => 'Phone number',
            'email' => 'Email address',
            'national_id' => 'National ID',
            'drivers_license' => "Driver's license",
            'passport' => 'Passport number'
        ];
        $label = $labels[$identifierType] ?? 'Identifier';
        return ['valid' => false, 'message' => "{$label} not found in our records."];
    }
    
    return [
        'valid' => true, 
        'userData' => $userData,
        'phoneNumber' => $phoneNumber,
        'emailAddress' => $emailAddress
    ];
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
        $verificationMethod = $_POST['verification_method'] ?? 'sms';
        $phone2 = trim($_POST['phone2'] ?? '');
        $phone3 = trim($_POST['phone3'] ?? '');
        
        if (empty($inputValue)) {
            echo json_encode(['success' => false, 'message' => 'Please provide your identifier.']);
            exit;
        }
        
        // Basic validation
        if ($inputType === 'email') {
            if (!filter_var($inputValue, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email address format.']);
                exit;
            }
        }
        
        if ($inputType === 'phone') {
            $inputValue = preg_replace('/[^\d+]/', '', $inputValue);
            if (empty($inputValue)) {
                echo json_encode(['success' => false, 'message' => 'Invalid phone number.']);
                exit;
            }
        }
        
        // Normalize additional phone numbers if provided
        if (!empty($phone2)) {
            $phone2 = normalizePhone($phone2, $countryDialCode);
        }
        if (!empty($phone3)) {
            $phone3 = normalizePhone($phone3, $countryDialCode);
        }
        
        // Verify identifier exists in source database
        $verification = verifyIdentifierInSourceDB($sourceDb, $inputType, $inputValue, $countryDialCode);
        
        if (!$verification['valid']) {
            echo json_encode(['success' => false, 'message' => $verification['message']]);
            exit;
        }
        
        $userData = $verification['userData'];
        $phoneNumber = $verification['phoneNumber'];
        $emailAddress = $verification['emailAddress'];
        
        // Map type to column
        $columnMap = [
            'phone' => 'phone',
            'email' => 'email',
            'national_id' => 'national_id',
            'drivers_license' => 'drivers_license',
            'passport' => 'passport'
        ];
        $identifierColumn = $columnMap[$inputType];
        $identifierValue = ($inputType === 'phone') ? normalizePhone($inputValue, $countryDialCode) : $inputValue;
        
        // Check if already registered in swap DB (check ALL identifiers)
        $checkQuery = "SELECT user_id FROM users WHERE ";
        $conditions = [];
        $checkParams = [];
        
        if ($phoneNumber) {
            $conditions[] = "phone = :phone";
            $checkParams[':phone'] = $phoneNumber;
        }
        if ($emailAddress) {
            $conditions[] = "email = :email";
            $checkParams[':email'] = $emailAddress;
        }
        if (!empty($phone2)) {
            $conditions[] = "phone2 = :phone2";
            $checkParams[':phone2'] = $phone2;
        }
        if (!empty($phone3)) {
            $conditions[] = "phone3 = :phone3";
            $checkParams[':phone3'] = $phone3;
        }
        if ($identifierColumn && $identifierValue && !in_array($inputType, ['phone', 'email'])) {
            $conditions[] = "{$identifierColumn} = :identifier";
            $checkParams[':identifier'] = $identifierValue;
        }
        
        if (!empty($conditions)) {
            $checkQuery .= implode(" OR ", $conditions) . " LIMIT 1";
            $stmt = $swapDb->prepare($checkQuery);
            $stmt->execute($checkParams);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'This identifier is already registered. Please login.']);
                exit;
            }
        }
        
        // Generate OTP
        $otpPlain = generateOTP();
        $otpHash = password_hash($otpPlain, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300);
        
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Invalidate old OTPs
        $stmt = $swapDb->prepare("UPDATE otp_logs SET used_at = NOW() WHERE identifier = :identifier AND used_at IS NULL");
        $stmt->execute([':identifier' => $identifierValue]);
        
        // Store OTP
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
        
        // Store OTP in session
        $_SESSION['otp_verification'][$identifierValue] = $otpPlain;
        $_SESSION['otp_verification_expires'][$identifierValue] = time() + 300;
        
        // Store registration data with multiple phones
        $_SESSION['temp_registration'] = [
            'identifier_type' => $inputType,
            'identifier_value' => $identifierValue,
            'identifier_column' => $identifierColumn,
            'full_name' => $fullName ?: ($userData['full_name'] ?? null),
            'date_of_birth' => $dateOfBirth,
            'source_user_id' => $userData['id'] ?? null,
            'phone_number' => $phoneNumber,
            'phone2' => $phone2,
            'phone3' => $phone3,
            'email' => $emailAddress,
            'verification_method' => $verificationMethod
        ];
        
        // Send OTP
        $sentVia = [];
        $otpSent = false;
        
        if ($verificationMethod === 'sms' || $verificationMethod === 'both') {
            if ($phoneNumber) {
                try {
                    $comm = CommunicationFactory::create($clientPartnerKey);
                    $result = $comm->sendSMS($phoneNumber, "Your {$countryName} SWAP verification code: {$otpPlain}");
                    if ($result['success'] ?? false) {
                        $sentVia[] = 'SMS';
                        $otpSent = true;
                    }
                } catch (Exception $e) {
                    error_log("SMS failed: " . $e->getMessage());
                }
            }
        }
        
        if ($verificationMethod === 'email' || $verificationMethod === 'both') {
            if ($emailAddress) {
                try {
                    if (sendEmailOTP($emailAddress, $otpPlain, $countryName)) {
                        $sentVia[] = 'Email';
                        $otpSent = true;
                    }
                } catch (Exception $e) {
                    error_log("Email failed: " . $e->getMessage());
                }
            }
        }
        
        if ($otpSent) {
            $message = "Verification code sent via " . implode(' & ', $sentVia) . "!";
            echo json_encode(['success' => true, 'message' => $message, 'has_contact' => true]);
        } else {
            if (getenv('APP_ENV') === 'development') {
                echo json_encode(['success' => true, 'message' => "DEV MODE: Your code is {$otpPlain}", 'show_otp' => true, 'otp' => $otpPlain]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Unable to send verification code. No valid contact method found.']);
            }
        }
        exit;
        
    } catch (Throwable $e) {
        error_log("REGISTER POST ERROR: " . $e->getMessage());
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
        .register-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 560px;
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
        .subtitle { font-size: 0.875rem; color: #A0A0B0; margin-bottom: 1rem; }
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
        .register-form { padding: 2rem; }
        .form-group { margin-bottom: 1.5rem; }
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
        .selector-tab.active { color: #00F0FF; border-bottom: 2px solid #00F0FF; }
        .selector-tab:hover { color: #00F0FF; }
        .input-container {
            display: flex;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(0, 0, 0, 0.5);
            transition: all 0.2s ease;
            border-radius: 0px;
        }
        .input-container:focus-within { border-color: #00F0FF; box-shadow: 0 0 0 1px rgba(0, 240, 255, 0.2); }
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
        .form-control::placeholder { color: #505060; }
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
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(0, 240, 255, 0.4); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-secondary {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #FFFFFF;
            margin-top: 0;
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
        .message.error { background: rgba(255, 48, 48, 0.1); border-left-color: #FF3030; color: #FF6060; }
        .message.success { background: rgba(0, 240, 255, 0.1); border-left-color: #00F0FF; color: #00F0FF; }
        .otp-section { display: none; }
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
        .register-footer a:hover { color: #00F0FF; }
        .help-text { font-size: 0.7rem; color: #606070; margin-top: 0.5rem; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in { animation: fadeInUp 0.4s ease; }
        .additional-phones {
            display: none;
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: rgba(0, 240, 255, 0.03);
            border: 1px dashed rgba(0, 240, 255, 0.2);
        }
        .additional-phones.show { display: block; }
        @media (max-width: 640px) {
            .register-container { margin: 1rem; }
            .register-header { padding: 1.5rem 1.5rem 1rem; }
            .register-header h1 { font-size: 1.5rem; }
            .register-form { padding: 1.5rem; }
            .selector-tabs { gap: 0.25rem; }
            .selector-tab { padding: 0.5rem 0.75rem; font-size: 0.7rem; }
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
        <div class="selector-tabs">
            <button class="selector-tab active" data-type="phone">📱 Phone</button>
            <button class="selector-tab" data-type="email">✉️ Email</button>
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
                <div class="help-text" id="help-text">Enter your phone number (e.g., 71 234 567)</div>
            </div>
            
            <div class="form-group" id="fullname-group" style="display: none;">
                <label>FULL NAME (Optional)</label>
                <input type="text" id="full_name" class="form-control" placeholder="Enter your full name" autocomplete="off">
            </div>
            
            <div class="form-group" id="dob-group" style="display: none;">
                <label>DATE OF BIRTH (Optional)</label>
                <input type="date" id="date_of_birth" class="form-control">
            </div>
            
            <!-- Additional Phone Numbers (up to 3 total) -->
            <div class="form-group">
                <label style="cursor:pointer;" onclick="toggleAdditionalPhones()">
                    📞 <span id="additionalPhonesToggle">Add Additional Phone Numbers (Optional)</span>
                </label>
                <div class="additional-phones" id="additionalPhones">
                    <div class="help-text">You can add up to 3 phone numbers total</div>
                    <div style="margin-top: 0.75rem;">
                        <div class="input-container" style="margin-bottom: 0.5rem;">
                            <span class="input-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                            <input type="tel" id="phone2" class="form-control" placeholder="Second phone number" autocomplete="off">
                        </div>
                        <div class="input-container">
                            <span class="input-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                            <input type="tel" id="phone3" class="form-control" placeholder="Third phone number" autocomplete="off">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-group" id="verification-method-group">
                <label>RECEIVE VERIFICATION CODE VIA</label>
                <div class="verification-options">
                    <label class="verification-option">
                        <input type="radio" name="verification_method" value="sms" checked> 📱 SMS
                    </label>
                    <label class="verification-option">
                        <input type="radio" name="verification_method" value="email"> ✉️ Email
                    </label>
                    <label class="verification-option">
                        <input type="radio" name="verification_method" value="both"> 📱+✉️ Both
                    </label>
                </div>
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
const countryDialCode = '<?= $countryDialCode ?>';
const localLength = <?= $localLength ?>;

let currentIdentifierType = 'phone';
let resendTimerInterval = null;
let resendSecondsLeft = 0;
let additionalPhonesVisible = false;

function toggleAdditionalPhones() {
    additionalPhonesVisible = !additionalPhonesVisible;
    document.getElementById('additionalPhones').classList.toggle('show');
    document.getElementById('additionalPhonesToggle').textContent = 
        additionalPhonesVisible ? 'Hide Additional Phone Numbers' : 'Add Additional Phone Numbers (Optional)';
}

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
        labelEl.textContent = 'PRIMARY PHONE NUMBER';
        prefixEl.style.display = 'flex';
        prefixEl.textContent = countryDialCode;
        inputEl.placeholder = '71 234 567';
        helpTextEl.textContent = 'Enter your primary phone number';
        inputEl.maxLength = 20;
        inputEl.type = 'tel';
        fullnameGroup.style.display = 'none';
        dobGroup.style.display = 'none';
    } else if (type === 'email') {
        labelEl.textContent = 'EMAIL ADDRESS';
        prefixEl.style.display = 'none';
        inputEl.placeholder = 'you@example.com';
        helpTextEl.textContent = 'Enter your email address';
        inputEl.maxLength = 100;
        inputEl.type = 'email';
        fullnameGroup.style.display = 'none';
        dobGroup.style.display = 'none';
    } else {
        const labels = {
            'national_id': 'NATIONAL ID NUMBER',
            'drivers_license': "DRIVER'S LICENSE NUMBER",
            'passport': 'PASSPORT NUMBER'
        };
        const placeholders = {
            'national_id': 'Enter your National ID number',
            'drivers_license': "Enter your Driver's License number",
            'passport': 'Enter your Passport number'
        };
        const helpTexts = {
            'national_id': 'Enter your National ID as it appears on your records',
            'drivers_license': "Enter your Driver's License number as it appears on your records",
            'passport': 'Enter your Passport number as it appears on your records'
        };
        
        labelEl.textContent = labels[type] || type.toUpperCase();
        prefixEl.style.display = 'none';
        inputEl.placeholder = placeholders[type] || 'Enter your identifier';
        helpTextEl.textContent = helpTexts[type] || 'We\'ll verify this exists in our records';
        inputEl.maxLength = 50;
        inputEl.type = 'text';
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
    const verificationMethod = document.querySelector('input[name="verification_method"]:checked')?.value || 'sms';
    const phone2 = document.getElementById('phone2')?.value.trim() || '';
    const phone3 = document.getElementById('phone3')?.value.trim() || '';
    
    if (!identifier) {
        showMessage('Please enter your identifier.', 'error');
        document.getElementById('identifier').focus();
        return;
    }
    
    if (currentIdentifierType === 'email') {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(identifier)) {
            showMessage('Please enter a valid email address.', 'error');
            return;
        }
    }
    
    if (currentIdentifierType === 'phone') {
        const phoneClean = identifier.replace(/\D/g, '');
        if (phoneClean.length < 5) {
            showMessage('Please enter a valid phone number.', 'error');
            return;
        }
    }
    
    const btn = document.getElementById('sendOtpBtn');
    const originalText = btn.textContent;
    btn.textContent = 'VERIFYING...';
    btn.disabled = true;
    
    showMessage('Verifying your details with our records...', 'info');
    
    const formData = new URLSearchParams();
    formData.append('input_type', currentIdentifierType);
    formData.append('identifier', identifier);
    formData.append('full_name', fullName);
    formData.append('date_of_birth', dateOfBirth);
    formData.append('verification_method', verificationMethod);
    if (phone2) formData.append('phone2', phone2);
    if (phone3) formData.append('phone3', phone3);
    
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
    const verificationMethod = document.querySelector('input[name="verification_method"]:checked')?.value || 'sms';
    
    showMessage('Resending verification code...', 'info');
    
    const formData = new URLSearchParams();
    formData.append('input_type', currentIdentifierType);
    formData.append('identifier', identifier);
    formData.append('verification_method', verificationMethod);
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

document.getElementById('identifier')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') sendOTP();
});
document.getElementById('otp')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') verifyOTP();
});

window.addEventListener('load', function() {
    document.getElementById('identifier')?.focus();
    updateFormForIdentifierType('phone');
});
</script>
</body>
</html>
