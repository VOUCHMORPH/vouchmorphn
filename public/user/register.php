<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// ============================================================
// DYNAMIC COUNTRY CONFIGURATION LOADER - MULTI-COUNTRY SUPPORT
// ============================================================
//
// FIXED IN THIS VERSION:
//   1. REMOVED hardcoded CAZACOM database dependency - Cazacom is ONLY for SMS
//   2. SMS now routes to the correct network based on phone number prefix
//   3. All user data stored in YOUR database (swap) via Railway DATABASE_URL
//   4. Cazacom API called ONLY for sending SMS OTP
//   5. Proper duplicate user checking before sending OTP
//   6. Enhanced error handling with user-friendly messages
//   7. FIXED: otp_logs check constraints - identifier_type uses 'phone' not 'sms'
//   8. FIXED: otp_logs purpose uses 'verification' instead of 'registration'
// ============================================================

define('PROJECT_ROOT', dirname(__DIR__, 2));

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

$systemCountry = $config['country'] ?? getenv('VM_COUNTRY') ?? 'BW';
if (!defined('SYSTEM_COUNTRY')) {
    define('SYSTEM_COUNTRY', $systemCountry);
}
if (!defined('SYSTEM_COUNTRY_CODE')) {
    $countryCode = $config['country_code'] ?? 'BW';
    define('SYSTEM_COUNTRY_CODE', $countryCode);
}

$requiredFiles = [
    'SessionManager'       => PROJECT_ROOT . '/src/Application/Utils/SessionManager.php',
    'DBConnection'         => PROJECT_ROOT . '/src/Core/Database/DBConnection.php',
    'ProviderInterface'    => PROJECT_ROOT . '/src/Infrastructure/SMS/Contracts/ProviderInterface.php',
    'SmsGatewayClient'     => PROJECT_ROOT . '/src/Infrastructure/SMS/SmsGatewayClient.php',
    'CommunicationFactory' => PROJECT_ROOT . '/src/Core/Factories/CommunicationFactory.php',
    'EmailProviderInterface' => PROJECT_ROOT . '/src/Infrastructure/Email/Contracts/EmailProviderInterface.php',
    'EmailGatewayClient'     => PROJECT_ROOT . '/src/Infrastructure/Email/EmailGatewayClient.php',
    'IdentifierNormalizer'   => PROJECT_ROOT . '/src/Domain/Identity/IdentifierNormalizer.php',
    'UserIdentifierLookup'   => PROJECT_ROOT . '/src/Domain/Identity/UserIdentifierLookup.php',
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
use Infrastructure\Email\EmailGatewayClient;
use Domain\Identity\IdentifierNormalizer;
use Domain\Identity\UserIdentifierLookup;

SessionManager::start();

if (SessionManager::isLoggedIn()) {
    header('Location: user_dashboard.php');
    exit();
}

// ----------------------------------------
// Country configuration
// ----------------------------------------
$countryConfig    = $config['country_settings'][$systemCountry] ?? [];
$countryDialCode  = $countryConfig['dial_code'] ?? '+267';
$localLength      = (int)($countryConfig['local_phone_length'] ?? 8);
if (!defined('LOCAL_PHONE_LENGTH')) {
    // Read by normalizePhone() below, so a number that is already a
    // plain local number is never mistaken for one carrying a country
    // code (26712345 is a valid 8-digit Botswana number, not 267 + 12345).
    define('LOCAL_PHONE_LENGTH', $localLength);
}
$phonePlaceholder = $countryConfig['phone_placeholder'] ?? str_repeat('0', $localLength);
$countryName      = $countryConfig['name'] ?? $systemCountry;
$countryCurrency  = $countryConfig['currency'] ?? 'BWP';
$countryTimeZone  = $countryConfig['timezone'] ?? 'Africa/Gaborone';

date_default_timezone_set($countryTimeZone);

$minimumAdultAge = (int)($config['minimum_adult_age'] ?? getenv('VM_MINIMUM_ADULT_AGE') ?: 18);

// ============================================================
// Database connection - ONLY YOUR database via Railway DATABASE_URL
// ============================================================
try {
    $swapDb = DBConnection::getConnection();
    if (!$swapDb) {
        throw new Exception("Failed to connect to database");
    }
    $swapDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log("REGISTER DB ERROR: " . $e->getMessage());
    die("System initialisation failed: Unable to connect to database.");
}

// ----------------------------------------
// Create/update tables with ALL identifier columns
// ----------------------------------------
try {
    $columns = [];
    $colsResult = $swapDb->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'users'
    ");
    while ($row = $colsResult->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['column_name'];
    }

    $idColumns = [
        'phone2'          => 'VARCHAR(50) DEFAULT NULL',
        'phone3'          => 'VARCHAR(50) DEFAULT NULL',
        'national_id'     => 'VARCHAR(100) DEFAULT NULL',
        'drivers_license' => 'VARCHAR(100) DEFAULT NULL',
        'passport'        => 'VARCHAR(100) DEFAULT NULL',
        'id_type'         => 'VARCHAR(50) DEFAULT NULL',
        'date_of_birth'   => 'DATE DEFAULT NULL',
        'email'           => 'VARCHAR(255) DEFAULT NULL',
    ];

    foreach ($idColumns as $col => $definition) {
        if (!in_array($col, $columns)) {
            $swapDb->exec("ALTER TABLE users ADD COLUMN {$col} {$definition}");
        }
    }

    // Create indexes
    $indexes = ['phone2', 'phone3', 'national_id', 'drivers_license', 'passport', 'email'];
    foreach ($indexes as $index) {
        try {
            $swapDb->exec("CREATE INDEX IF NOT EXISTS idx_users_{$index} ON users({$index})");
        } catch (Throwable $e) {
            // Index might already exist
        }
    }

    // Create user_identifiers table
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

    // Create otp_logs table - FIXED: Added proper check constraints
    $swapDb->exec("
        CREATE TABLE IF NOT EXISTS otp_logs (
            otp_id SERIAL PRIMARY KEY,
            identifier VARCHAR(100) NOT NULL,
            identifier_type VARCHAR(20) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            purpose VARCHAR(50) DEFAULT 'verification',
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            attempts INT DEFAULT 0,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT otp_logs_identifier_type_check 
                CHECK (identifier_type IN ('phone', 'email', 'sms')),
            CONSTRAINT otp_logs_purpose_check 
                CHECK (purpose IN ('verification', 'registration', 'login', 'password_reset', 'withdrawal'))
        )
    ");

} catch (Throwable $e) {
    error_log("Table creation warning: " . $e->getMessage());
}

// ----------------------------------------
// Helper functions
// ----------------------------------------
function normalizePhone(string $phoneInput, string $dialCode): string
{
    // Was: `$dialCode . ltrim($phoneInput, '0')`, which stacked the dial
    // code on top of a country code the person had typed themselves —
    // "26771234567" was stored as "+26726771234567". Since the sign-in
    // page normalised the same number to "+26771234567", those accounts
    // could not be found again at login. Now one canonical E.164 shape,
    // produced by the same code at both ends.
    return IdentifierNormalizer::canonicalPhone(
        $phoneInput,
        $dialCode,
        defined('LOCAL_PHONE_LENGTH') ? LOCAL_PHONE_LENGTH : null
    );
}

function generateOTP(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function maskDestination(string $value, string $type): string
{
    if ($type === 'email' && str_contains($value, '@')) {
        [$local, $domain] = explode('@', $value, 2);
        $visible = substr($local, 0, 1);
        return $visible . str_repeat('•', max(1, strlen($local) - 1)) . '@' . $domain;
    }
    $len = strlen($value);
    if ($len <= 4) return str_repeat('•', $len);
    return substr($value, 0, max(0, $len - 4) > 3 ? 3 : 0) . str_repeat('•', max(0, $len - 6)) . substr($value, -3);
}

function sendEmailOTP(EmailGatewayClient $emailClient, string $to, string $otp, string $countryName): bool
{
    $subject = "Your VouchMorph Verification Code";
    $message = "
        <html>
        <head><title>Verification Code</title></head>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Welcome to VouchMorph {$countryName}!</h2>
            <p>Your verification code is: <strong style='font-size: 24px; color: #00636e;'>{$otp}</strong></p>
            <p>This code expires in 5 minutes.</p>
            <p><strong>Never share this code with anyone, including VouchMorph staff.</strong></p>
            <hr>
            <small>VouchMorph</small>
        </body>
        </html>
    ";
    $result = $emailClient->sendEmail($to, $subject, $message);
    return $result['success'] ?? false;
}

// ============================================================
// Check if user already exists in YOUR database
// Checks ALL identifiers: phone, email, national_id, etc.
//
// Matching goes through UserIdentifierLookup so this sees an existing
// account the same way the sign-in page now does: any stored shape of a
// phone number, email regardless of case, ID numbers regardless of
// punctuation. Before, a returning user whose number had been stored in
// a different shape looked brand new here, and got a SECOND account —
// which is how one person ends up with two rows and a UNIQUE constraint
// they keep tripping over.
// ============================================================
function userExistsInDatabase($pdo, array $identifiers, string $dialCode, ?int $localLength = null) {
    $conditions = [];
    $params = [];
    $slot = 0;

    foreach ($identifiers as $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }

        [$clauses, $bound] = UserIdentifierLookup::buildMatch($value, $dialCode, $localLength, 'c' . $slot++);
        if ($clauses === []) {
            continue;
        }

        $conditions[] = '(' . implode(' OR ', $clauses) . ')';
        $params += $bound;
    }

    if (empty($conditions)) {
        return false;
    }

    $query = "SELECT user_id, full_name, phone, email, national_id FROM users WHERE "
           . implode(" OR ", $conditions)
           . " ORDER BY user_id ASC LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ----------------------------------------
// AJAX POST: Send OTP
// ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $inputType         = trim($_POST['input_type'] ?? '');
        $inputValue        = trim($_POST['identifier'] ?? '');
        $fullName          = trim($_POST['full_name'] ?? '');
        $dateOfBirth       = trim($_POST['date_of_birth'] ?? '');
        // Extra numbers are a Phone-tab option only (they are never sent a
        // code); an email sign-up ignores them even if a client sends them.
        $phone2            = $inputType === 'phone' ? trim($_POST['phone2'] ?? '') : '';
        $phone3            = $inputType === 'phone' ? trim($_POST['phone3'] ?? '') : '';
        $pin               = trim($_POST['pin'] ?? '');
        $pinConfirm        = trim($_POST['pin_confirm'] ?? '');
        $contactChannel    = $_POST['contact_channel'] ?? null;
        $contactValue      = trim($_POST['contact_value'] ?? '');

        // ============================================================
        // FIX: Self-service registration can ONLY use phone or email as
        // the seed identity. Government-issued IDs (national_id,
        // voters_id, birth_certificate, drivers_license, passport) can
        // NEVER be self-registered — VouchMorph has no way to verify
        // authenticity without a human agent/organization checking the
        // physical document. These identities must be added later, in
        // person, by an approved agent via addVerifiedIdentityAsAgent().
        // ============================================================
        $selfServiceIdentityTypes = ['phone', 'email'];

        if (!in_array($inputType, $selfServiceIdentityTypes, true)) {
            echo json_encode([
                'success' => false,
                'message' => 'Government-issued IDs can only be added with help from a VouchMorph agent or government official. Please register with your phone number or email — you can add a verified ID later at any agent.'
            ]);
            exit();
        }

        // Validate input
        if (empty($inputValue)) {
            echo json_encode(['success' => false, 'message' => 'Please provide your identifier.']);
            exit;
        }

        if (empty($pin) || !preg_match('/^\d{6}$/', $pin)) {
            echo json_encode(['success' => false, 'message' => 'Please choose a 6-digit PIN.']);
            exit;
        }
        if ($pin !== $pinConfirm) {
            echo json_encode(['success' => false, 'message' => 'PINs do not match.']);
            exit;
        }
        
        $weakPins = ['000000','111111','222222','333333','444444','555555','666666','777777','888888','999999','123456','654321'];
        if (in_array($pin, $weakPins, true)) {
            echo json_encode(['success' => false, 'message' => 'That PIN is too easy to guess. Please choose a different one.']);
            exit;
        }

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

        // Normalize values
        $phoneNumber = null;
        $emailAddress = null;
        $identifierValue = null;

        if ($inputType === 'phone') {
            $phoneNumber = normalizePhone($inputValue, $countryDialCode);
            $identifierValue = $phoneNumber;
        } elseif ($inputType === 'email') {
            $emailAddress = IdentifierNormalizer::canonicalEmail($inputValue);
            $identifierValue = $emailAddress;
        } else {
            $identifierValue = $inputValue;
        }

        // Normalize additional phones
        if (!empty($phone2)) {
            $phone2 = normalizePhone($phone2, $countryDialCode);
        }
        if (!empty($phone3)) {
            $phone3 = normalizePhone($phone3, $countryDialCode);
        }

        // ============================================================
        // CRITICAL: Check if user already exists before sending OTP
        // This prevents duplicate registrations
        // ============================================================
        $existingUser = userExistsInDatabase(
            $swapDb,
            [$identifierValue, $phoneNumber, $emailAddress, $phone2, $phone3, $contactValue],
            $countryDialCode,
            $localLength
        );
        
        if ($existingUser) {
            // User already exists - return helpful message with masked details
            $maskedPhone = $existingUser['phone'] ? maskDestination($existingUser['phone'], 'phone') : null;
            $maskedEmail = $existingUser['email'] ? maskDestination($existingUser['email'], 'email') : null;
            
            $message = "This account is already registered";
            if ($maskedPhone) {
                $message .= " with phone {$maskedPhone}";
            }
            if ($maskedEmail) {
                $message .= " and email {$maskedEmail}";
            }
            $message .= ". Please login instead.";
            
            echo json_encode([
                'success' => false, 
                'message' => $message,
                'exists' => true,
                'login_url' => 'login.php'
            ]);
            exit;
        }

        // ============================================================
        // User doesn't exist - proceed with registration
        // ============================================================
        
        // Determine OTP delivery channel
        $otpChannel = null;
        $otpDestination = null;

        if ($inputType === 'phone') {
            $otpChannel = 'sms';
            $otpDestination = $identifierValue;
        } elseif ($inputType === 'email') {
            $otpChannel = 'email';
            $otpDestination = $identifierValue;
        } else {
            // For ID types (national_id, drivers_license, passport)
            if (empty($contactChannel) || empty($contactValue)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'A phone number or email is required so we can send your verification code.'
                ]);
                exit;
            }
            if ($contactChannel === 'phone') {
                $otpChannel = 'sms';
                $otpDestination = normalizePhone($contactValue, $countryDialCode);
                // Keep the contact we are about to verify by OTP on the
                // account itself. It used to be used for delivery and
                // then thrown away, so someone who registered with their
                // national ID had no phone on their row at all and got
                // "User not found" the moment they tried to sign in with
                // the number they had just proved they control. The
                // agent registration flow has always stored it; this
                // brings self-registration in line.
                $phoneNumber = $otpDestination;
            } elseif ($contactChannel === 'email') {
                if (!filter_var($contactValue, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
                    exit;
                }
                $otpChannel = 'email';
                $otpDestination = IdentifierNormalizer::canonicalEmail($contactValue);
                $emailAddress = $otpDestination;
            } else {
                echo json_encode(['success' => false, 'message' => 'Please choose phone or email to receive your code.']);
                exit;
            }
        }

        // Generate OTP
        $otpPlain = generateOTP();
        $otpHash  = password_hash($otpPlain, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300);

        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (str_contains($ipAddress, ',')) {
            $ipAddress = trim(explode(',', $ipAddress)[0]);
        }
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        // Invalidate any existing OTPs for this destination
        $stmt = $swapDb->prepare("UPDATE otp_logs SET used_at = NOW() WHERE identifier = :identifier AND used_at IS NULL");
        $stmt->execute([':identifier' => $otpDestination]);

        // ============================================================
        // FIXED: Store OTP in database with correct values for check constraints
        // - identifier_type: 'phone' for SMS (not 'sms')
        // - purpose: 'verification' (not 'registration')
        // ============================================================
        $dbIdentifierType = ($otpChannel === 'sms') ? 'phone' : $otpChannel;
        
        $stmt = $swapDb->prepare("
            INSERT INTO otp_logs
            (identifier, identifier_type, code_hash, purpose, expires_at, attempts, ip_address, user_agent, created_at)
            VALUES
            (:identifier, :identifier_type, :code_hash, :purpose, :expires_at, 0, :ip_address, :user_agent, NOW())
        ");
        $stmt->execute([
            ':identifier' => $otpDestination,
            ':identifier_type' => $dbIdentifierType,  // 'phone' for SMS, 'email' for email
            ':code_hash' => $otpHash,
            ':purpose' => 'verification',  // Changed from 'registration' to 'verification'
            ':expires_at' => $expiresAt,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);

        // Store in session for verification
        $_SESSION['otp_verification'][$otpDestination] = $otpPlain;
        $_SESSION['otp_verification_expires'][$otpDestination] = time() + 300;

        $_SESSION['temp_registration'] = [
            'identifier_type'   => $inputType,
            'identifier_value'  => $identifierValue,
            'full_name'         => $fullName,
            'date_of_birth'     => $dateOfBirth,
            'phone_number'      => $phoneNumber,
            'phone2'            => $phone2,
            'phone3'            => $phone3,
            'email'             => $emailAddress,
            'otp_channel'       => $otpChannel,
            'otp_destination'   => $otpDestination,
            'pin_hash'          => password_hash($pin, PASSWORD_DEFAULT),
        ];

        // ============================================================
        // Send OTP via CommunicationFactory (uses Cazacom API for SMS)
        // No database connection to Cazacom - just API call!
        // ============================================================
        $otpSent = false;
        if ($otpChannel === 'sms') {
            try {
                // Use createForPhone to auto-detect the correct network
                $comm = CommunicationFactory::createForPhone('sms', $otpDestination);
                $result = $comm->send($otpDestination, "Your {$countryName} VouchMorph verification code: {$otpPlain}");
                $otpSent = (bool)($result['success'] ?? false);
                
                // Log which network was used, and whether it took the message
                $providerName = $comm->getProviderName();
                if ($otpSent) {
                    error_log("REGISTER: OTP sent via {$providerName} to " . maskDestination($otpDestination, 'phone'));
                } else {
                    error_log("REGISTER: OTP send via {$providerName} FAILED for " . maskDestination($otpDestination, 'phone') . ": " . ($result['error'] ?? 'no error given'));
                }
                
            } catch (Exception $e) {
                error_log("REGISTER: SMS failed for {$otpDestination}: " . $e->getMessage());
            }
        } elseif ($otpChannel === 'email') {
            try {
                $emailClient = new EmailGatewayClient($config['email'] ?? []);
                $otpSent = sendEmailOTP($emailClient, $otpDestination, $otpPlain, $countryName);
            } catch (Throwable $e) {
                error_log("REGISTER: Email failed: " . $e->getMessage());
            }
        }

        // Return response
        if ($otpSent) {
            $masked = maskDestination($otpDestination, $otpChannel === 'sms' ? 'phone' : 'email');
            $channelLabel = $otpChannel === 'sms' ? 'SMS' : 'email';
            echo json_encode([
                'success' => true,
                'message' => "Verification code sent via {$channelLabel} to {$masked}",
                'has_contact' => true
            ]);
        } else {
            if (getenv('APP_ENV') === 'development') {
                echo json_encode([
                    'success' => true, 
                    'message' => "DEV MODE: Your code is {$otpPlain}", 
                    'show_otp' => true, 
                    'otp' => $otpPlain
                ]);
            } else {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Unable to send verification code right now. Please try again shortly.'
                ]);
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
            top: 0; left: 0; right: 0; bottom: 0;
            background-image:
                linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
            z-index: 0;
        }
        .register-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 560px;
            background: rgba(5, 5, 5, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
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
        }
        .selector-tab.active { color: #00F0FF; border-bottom: 2px solid #00F0FF; }
        .selector-tab:hover { color: #00F0FF; }
        .input-container {
            display: flex;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(0, 0, 0, 0.5);
            transition: all 0.2s ease;
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
        .form-control.otp-input, .form-control.pin-input {
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
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(0, 240, 255, 0.4); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-secondary {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #FFFFFF;
            margin-top: 0;
        }
        .btn-login {
            background: transparent;
            border: 1px solid #00F0FF;
            color: #00F0FF;
            margin-top: 0.5rem;
        }
        .btn-login:hover {
            background: rgba(0, 240, 255, 0.1);
            transform: translateY(-2px);
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
        .message.warning { background: rgba(255, 165, 0, 0.1); border-left-color: #FFA500; color: #FFA500; }
        .otp-section { display: none; }
        .contact-channel-group { display: none; margin-top: 0.75rem; padding: 0.75rem; background: rgba(255,48,48,0.05); border: 1px dashed rgba(255,48,48,0.3); }
        .contact-channel-group.show { display: block; }
        .contact-channel-choice { display: flex; gap: 1rem; margin-bottom: 0.5rem; font-size: 0.8rem; color: #C0C0D0; }
        .register-footer {
            padding: 1.25rem 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(10, 10, 20, 0.3);
            text-align: center;
        }
        .register-footer a { color: #808090; text-decoration: none; font-size: 0.75rem; font-weight: 500; transition: color 0.2s; }
        .register-footer a:hover { color: #00F0FF; }
        .help-text { font-size: 0.7rem; color: #606070; margin-top: 0.5rem; }
        .trust-line { font-size: 0.7rem; color: #606070; text-align: center; margin-top: 1rem; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeInUp 0.4s ease; }
        .additional-phones { display: none; margin-top: 0.5rem; padding: 0.75rem; background: rgba(0, 240, 255, 0.03); border: 1px dashed rgba(0, 240, 255, 0.2); }
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
<div class="register-container">
    <div class="register-header">
        <h1>VOUCHMORPH<sup style="font-size: 0.7rem;">™</sup></h1>
        <div class="subtitle">Join the Financial Revolution</div>
        <div class="system-badge"><?= htmlspecialchars($systemCountry) ?> • <?= htmlspecialchars($countryCurrency) ?></div>
    </div>

    <div class="register-form">
        <div class="selector-tabs">
            <button class="selector-tab active" data-type="phone">📱 Phone</button>
            <button class="selector-tab" data-type="email">✉️ Email</button>
            <button class="selector-tab" data-type="national_id" style="opacity:0.5;cursor:not-allowed;">🆔 National ID</button>
            <button class="selector-tab" data-type="drivers_license" style="opacity:0.5;cursor:not-allowed;">🚗 Driver's License</button>
            <button class="selector-tab" data-type="passport" style="opacity:0.5;cursor:not-allowed;">📖 Passport</button>
        </div>

        <div id="register-step">
            <div class="form-group" id="identifier-group">
                <label id="identifier-label">MOBILE NUMBER</label>
                <div class="input-container" id="input-container">
                    <span class="input-prefix" id="input-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                    <input type="tel" id="identifier" class="form-control" placeholder="<?= htmlspecialchars($phonePlaceholder) ?>" autocomplete="off"
                           autocapitalize="none" autocorrect="off" spellcheck="false">
                </div>
                <div class="help-text" id="help-text">Enter your phone number (e.g., 71 234 567)</div>

                <div class="contact-channel-group" id="contactChannelGroup">
                    <div class="help-text" style="color:#FF8888; margin-top:0;">A phone or email is required so we can send your verification code.</div>
                    <div class="contact-channel-choice">
                        <label><input type="radio" name="contact_channel" value="phone" checked> 📱 Phone</label>
                        <label><input type="radio" name="contact_channel" value="email"> ✉️ Email</label>
                    </div>
                    <input type="text" id="contact_value" class="form-control" placeholder="Enter phone or email" autocapitalize="none" autocorrect="off" spellcheck="false" style="border:1px solid rgba(255,255,255,0.15); background:rgba(0,0,0,0.4);">
                </div>
            </div>

            <div class="form-group" id="fullname-group" style="display: none;">
                <label>FULL NAME (Optional)</label>
                <input type="text" id="full_name" class="form-control" placeholder="Enter your full name" autocomplete="off">
            </div>

            <div class="form-group" id="dob-group" style="display: none;">
                <label>DATE OF BIRTH (Optional)</label>
                <input type="date" id="date_of_birth" class="form-control">
            </div>

            <div class="form-group">
                <label>CHOOSE A 6-DIGIT PIN</label>
                <input type="password" id="pin" class="form-control pin-input" maxlength="6" inputmode="numeric" placeholder="••••••" autocomplete="new-password">
                <div class="help-text">You'll use this PIN every time you log in. Don't share it with anyone.</div>
            </div>
            <div class="form-group">
                <label>CONFIRM PIN</label>
                <input type="password" id="pin_confirm" class="form-control pin-input" maxlength="6" inputmode="numeric" placeholder="••••••" autocomplete="new-password">
            </div>

            <!-- Phone tab only: these numbers are never sent a code, so an
                 email sign-up (which should end with only verified ways
                 in) doesn't offer them. -->
            <div class="form-group" id="additional-phones-group">
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
        <div class="trust-line">We'll only ever send your code to the phone or email you gave us — never anywhere else.</div>
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
        // Only allow phone and email for self-registration
        const type = this.dataset.type;
        if (type === 'national_id' || type === 'drivers_license' || type === 'passport') {
            showMessage('Government-issued IDs can only be added with help from a VouchMorph agent or government official. Please register with your phone number or email — you can add a verified ID later at any agent.', 'warning');
            return;
        }
        
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
    const contactGroup = document.getElementById('contactChannelGroup');

    document.getElementById('additional-phones-group').style.display = type === 'email' ? 'none' : '';

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
        contactGroup.classList.remove('show');
    } else if (type === 'email') {
        labelEl.textContent = 'EMAIL ADDRESS';
        prefixEl.style.display = 'none';
        inputEl.placeholder = 'you@example.com';
        helpTextEl.textContent = 'Enter your email address';
        inputEl.maxLength = 100;
        inputEl.type = 'email';
        fullnameGroup.style.display = 'none';
        dobGroup.style.display = 'none';
        contactGroup.classList.remove('show');
    } else {
        // This shouldn't be reached now, but keep as fallback
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
        contactGroup.classList.add('show');
    }

    inputEl.value = '';
}

function showMessage(text, type = 'info') {
    const msgEl = document.getElementById('message');
    msgEl.textContent = text;
    msgEl.className = 'message ' + type;
    // Clear any extra buttons
    const extraBtns = msgEl.querySelectorAll('.btn-login');
    extraBtns.forEach(btn => btn.remove());
    setTimeout(() => {
        if (document.getElementById('message').textContent === text) {
            msgEl.textContent = '';
            msgEl.className = 'message';
        }
    }, 8000);
}

function sendOTP() {
    const identifier = document.getElementById('identifier').value.trim();
    const fullName = document.getElementById('full_name')?.value.trim() || '';
    const dateOfBirth = document.getElementById('date_of_birth')?.value || '';
    const phone2 = document.getElementById('phone2')?.value.trim() || '';
    const phone3 = document.getElementById('phone3')?.value.trim() || '';
    const pin = document.getElementById('pin').value.trim();
    const pinConfirm = document.getElementById('pin_confirm').value.trim();

    // Double-check identifier type on the client side too
    if (currentIdentifierType === 'national_id' || currentIdentifierType === 'drivers_license' || currentIdentifierType === 'passport') {
        showMessage('Government-issued IDs can only be added with help from a VouchMorph agent or government official. Please register with your phone number or email.', 'warning');
        return;
    }

    if (!identifier) {
        showMessage('Please enter your identifier.', 'error');
        document.getElementById('identifier').focus();
        return;
    }

    if (!/^\d{6}$/.test(pin)) {
        showMessage('Please choose a 6-digit PIN.', 'error');
        document.getElementById('pin').focus();
        return;
    }
    if (pin !== pinConfirm) {
        showMessage('PINs do not match.', 'error');
        document.getElementById('pin_confirm').focus();
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

    const formData = new URLSearchParams();
    formData.append('input_type', currentIdentifierType);
    formData.append('identifier', identifier);
    formData.append('full_name', fullName);
    formData.append('date_of_birth', dateOfBirth);
    formData.append('pin', pin);
    formData.append('pin_confirm', pinConfirm);
    if (currentIdentifierType === 'phone') {
        if (phone2) formData.append('phone2', phone2);
        if (phone3) formData.append('phone3', phone3);
    }

    // Only for ID types (now blocked, but keep for completeness)
    if (!['phone', 'email'].includes(currentIdentifierType)) {
        const contactChannel = document.querySelector('input[name="contact_channel"]:checked')?.value;
        const contactValue = document.getElementById('contact_value').value.trim();
        if (!contactValue) {
            showMessage('Please provide a phone number or email to receive your verification code.', 'error');
            document.getElementById('contact_value').focus();
            return;
        }
        formData.append('contact_channel', contactChannel);
        formData.append('contact_value', contactValue);
    }

    const btn = document.getElementById('sendOtpBtn');
    const originalText = btn.textContent;
    btn.textContent = 'CHECKING...';
    btn.disabled = true;

    showMessage('Checking if you already have an account...', 'info');

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
            // User doesn't exist - send OTP
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
        } else if (data.exists) {
            // User already exists - show login link
            const msgEl = document.getElementById('message');
            msgEl.textContent = data.message;
            msgEl.className = 'message warning';
            
            // Add login button
            const loginBtn = document.createElement('button');
            loginBtn.className = 'btn btn-login';
            loginBtn.textContent = 'GO TO LOGIN →';
            loginBtn.onclick = function() {
                window.location.href = data.login_url || 'login.php';
            };
            msgEl.appendChild(loginBtn);
        } else {
            // Other error
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
    formData.append('pin', document.getElementById('pin').value.trim());
    formData.append('pin_confirm', document.getElementById('pin_confirm').value.trim());
    if (!['phone', 'email'].includes(currentIdentifierType)) {
        formData.append('contact_channel', document.querySelector('input[name="contact_channel"]:checked')?.value || '');
        formData.append('contact_value', document.getElementById('contact_value').value.trim());
    }

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
