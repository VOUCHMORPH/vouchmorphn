<?php
// user/verify_otp.php - OTP Verification Handler

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);

define('PROJECT_ROOT', dirname(__DIR__, 2));

// ============================================================
// Load required files
// ============================================================
require_once PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Core/Database/CredentialsDBConnection.php';
require_once PROJECT_ROOT . '/src/Infrastructure/Credentials/CredentialsRepository.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;

// Start session
SessionManager::start();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');

// ============================================================
// Load country configuration - FIXED: Use proper config loading
// ============================================================
try {
    $config = \Core\Config\LoadCountry::getConfig();
} catch (Throwable $e) {
    error_log("VERIFY OTP: Config error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System configuration error. Please try again.']);
    exit;
}

$systemCountry = $config['country'] ?? getenv('VM_COUNTRY') ?? 'BW';
$countryConfig = $config['country_settings'][$systemCountry] ?? [];
$countryName = $countryConfig['name'] ?? $systemCountry;
$countryDialCode = $countryConfig['dial_code'] ?? '+267';

// ============================================================
// Helper: Normalize phone number (matches register.php)
// ============================================================
function normalizePhone(string $phoneInput, string $dialCode): string
{
    $phoneInput = preg_replace('/[^\d+]/', '', trim($phoneInput));
    if ($phoneInput === '') return '';
    if (str_starts_with($phoneInput, '+')) {
        return '+' . preg_replace('/[^0-9]/', '', substr($phoneInput, 1));
    }
    return $dialCode . ltrim($phoneInput, '0');
}

// ============================================================
// Database connection
// ============================================================
try {
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Failed to connect to database");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log("VERIFY OTP DB ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// ============================================================
// Get and validate input
// ============================================================
$inputType = $_POST['input_type'] ?? 'phone';
$rawIdentifier = trim($_POST['identifier'] ?? '');
$otp = trim($_POST['otp'] ?? '');

error_log("VERIFY OTP: Input - Type: {$inputType}, Raw Identifier: {$rawIdentifier}, OTP: {$otp}");

// ============================================================
// FIX: Normalize the identifier to match what's stored in session
// ============================================================
if ($inputType === 'phone') {
    $identifier = normalizePhone($rawIdentifier, $countryDialCode);
    error_log("VERIFY OTP: Normalized phone: {$identifier}");
} else {
    $identifier = $rawIdentifier;
}

if (empty($identifier)) {
    error_log("VERIFY OTP: Empty identifier after normalization");
    echo json_encode(['success' => false, 'message' => 'Identifier is required']);
    exit;
}

if (empty($otp) || strlen($otp) !== 6 || !preg_match('/^\d{6}$/', $otp)) {
    error_log("VERIFY OTP: Invalid OTP format: {$otp}");
    echo json_encode(['success' => false, 'message' => 'Please enter a valid 6-digit verification code']);
    exit;
}

// ============================================================
// Check if temp registration data exists in session
// ============================================================
if (!isset($_SESSION['temp_registration'])) {
    error_log("VERIFY OTP: No temp registration in session");
    echo json_encode(['success' => false, 'message' => 'Registration session expired. Please try again.']);
    exit;
}

$tempData = $_SESSION['temp_registration'];
error_log("VERIFY OTP: Temp data identifier: " . ($tempData['identifier_value'] ?? 'NULL'));

// Verify the identifier matches (both are now normalized)
if ($tempData['identifier_value'] !== $identifier) {
    error_log("VERIFY OTP: Identifier mismatch - Expected: {$tempData['identifier_value']}, Got: {$identifier}");
    echo json_encode(['success' => false, 'message' => 'Identifier mismatch. Please try again.']);
    exit;
}

// ============================================================
// Verify OTP from database - FIXED: Use 'verification' not 'registration'
// ============================================================
try {
    // Get the OTP record from database
    $stmt = $db->prepare("
        SELECT otp_id, code_hash, expires_at, attempts, used_at 
        FROM otp_logs 
        WHERE identifier = :identifier 
          AND purpose = 'verification'
          AND used_at IS NULL
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([':identifier' => $tempData['otp_destination']]);
    $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("VERIFY OTP: OTP Record found: " . ($otpRecord ? 'YES' : 'NO'));

    if (!$otpRecord) {
        error_log("VERIFY OTP: No OTP record found for: " . $tempData['otp_destination']);
        echo json_encode(['success' => false, 'message' => 'Verification code not found. Please request a new code.']);
        exit;
    }

    // Check if expired
    if (strtotime($otpRecord['expires_at']) < time()) {
        error_log("VERIFY OTP: OTP expired - Expires: {$otpRecord['expires_at']}, Now: " . date('Y-m-d H:i:s'));
        echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new code.']);
        exit;
    }

    // Check attempts
    if ($otpRecord['attempts'] >= 5) {
        error_log("VERIFY OTP: Too many attempts - Attempts: {$otpRecord['attempts']}");
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new code.']);
        exit;
    }

    // Verify the OTP
    error_log("VERIFY OTP: Verifying OTP...");
    if (!password_verify($otp, $otpRecord['code_hash'])) {
        // Increment attempts
        $stmt = $db->prepare("UPDATE otp_logs SET attempts = attempts + 1 WHERE otp_id = :otp_id");
        $stmt->execute([':otp_id' => $otpRecord['otp_id']]);
        
        error_log("VERIFY OTP: OTP verification FAILED");
        echo json_encode(['success' => false, 'message' => 'Invalid verification code. Please try again.']);
        exit;
    }

    error_log("VERIFY OTP: OTP verification SUCCESS");

    // ============================================================
    // OTP is valid - Mark as used and create the user
    // ============================================================
    
    // Mark OTP as used
    $stmt = $db->prepare("UPDATE otp_logs SET used_at = NOW() WHERE otp_id = :otp_id");
    $stmt->execute([':otp_id' => $otpRecord['otp_id']]);

    // Begin transaction for user creation
    $db->beginTransaction();

    try {
        error_log("VERIFY OTP: Creating user...");

        // Check if user already exists based on phone only
        $stmt = $db->prepare("
            SELECT user_id FROM users 
            WHERE phone = :phone
            LIMIT 1
        ");
        $stmt->execute([
            ':phone' => $tempData['phone_number'] ?? null
        ]);
        
        if ($stmt->fetch()) {
            $db->rollBack();
            error_log("VERIFY OTP: User already exists with phone: " . ($tempData['phone_number'] ?? 'null'));
            echo json_encode(['success' => false, 'message' => 'User already exists. Please login.']);
            exit;
        }

        // ============================================================
        // FIX: Handle date_of_birth properly - fallback to NULL or default
        // ============================================================
        $dateOfBirth = $tempData['date_of_birth'] ?? null;
        
        // If date_of_birth is provided, validate it
        if ($dateOfBirth !== null && $dateOfBirth !== '') {
            // Try to parse the date, if invalid, set to null
            $timestamp = strtotime($dateOfBirth);
            if ($timestamp !== false) {
                $dateOfBirth = date('Y-m-d', $timestamp);
            } else {
                $dateOfBirth = null;
                error_log("VERIFY OTP: Invalid date_of_birth format, setting to NULL");
            }
        } else {
            // If no date_of_birth provided, use NULL
            $dateOfBirth = null;
            error_log("VERIFY OTP: No date_of_birth provided, using NULL");
        }

        // ============================================================
        // FIX: Generate username if not provided
        // ============================================================
        $username = $tempData['username'] ?? null;
        $fullName = $tempData['full_name'] ?? null;
        $phoneNumber = $tempData['phone_number'] ?? $identifier;
        
        // If no username, generate from full name or phone
        if (empty($username)) {
            if (!empty($fullName)) {
                // Generate username from full name (remove spaces, lowercase)
                $username = strtolower(preg_replace('/\s+/', '', $fullName));
                // Add random numbers if too common
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
                $stmt->execute([':username' => $username]);
                if ($stmt->fetchColumn() > 0) {
                    $username .= rand(100, 999);
                }
            } else {
                // Use phone as fallback
                $username = 'user_' . preg_replace('/[^0-9]/', '', $phoneNumber);
                // Ensure uniqueness
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
                $stmt->execute([':username' => $username]);
                if ($stmt->fetchColumn() > 0) {
                    $username .= rand(100, 999);
                }
            }
            error_log("VERIFY OTP: Generated username: {$username}");
        }

        // ============================================================
        // FIX: Handle email - generate if not provided
        // ============================================================
        $email = $tempData['email'] ?? null;
        
        // If no email provided, generate one from phone or username
        if (empty($email)) {
            $email = $username . '@' . strtolower($countryName) . '.vouchmorphn.com';
            // Make it unique
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetchColumn() > 0) {
                $email = $username . rand(100, 999) . '@' . strtolower($countryName) . '.vouchmorphn.com';
            }
            error_log("VERIFY OTP: Generated email: {$email}");
        }

        // ============================================================
        // Create the user with correct column names matching your table
        // ============================================================
        $stmt = $db->prepare("
            INSERT INTO users (
                username,
                email,
                phone,
                verified,
                created_at,
                national_id,
                drivers_license,
                passport,
                date_of_birth,
                full_name,
                phone2,
                phone3,
                registration_channel
            ) VALUES (
                :username,
                :email,
                :phone,
                true,
                NOW(),
                :national_id,
                :drivers_license,
                :passport,
                :date_of_birth,
                :full_name,
                :phone2,
                :phone3,
                'self'
            )
        ");

        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':phone' => $tempData['phone_number'] ?? null,
            ':national_id' => ($tempData['identifier_type'] === 'national_id') ? $tempData['identifier_value'] : null,
            ':drivers_license' => ($tempData['identifier_type'] === 'drivers_license') ? $tempData['identifier_value'] : null,
            ':passport' => ($tempData['identifier_type'] === 'passport') ? $tempData['identifier_value'] : null,
            ':date_of_birth' => $dateOfBirth,
            ':full_name' => $fullName,
            ':phone2' => $tempData['phone2'] ?? null,
            ':phone3' => $tempData['phone3'] ?? null
        ]);
        $userId = $db->lastInsertId();
        error_log("VERIFY OTP: User created with ID: {$userId}");

        // Login secret and transaction PIN both go to the separate
        // credentials database, not this `users` row. At signup they're
        // the same value (the PIN the user just entered doubles as their
        // login password), but they're stored as two independent
        // credentials since a user can later change just the transaction
        // PIN via set_pin.php without touching their login password.
        // Written before commit so a failure here rolls the whole
        // registration back via the existing catch block below, rather
        // than leaving a user with no way to log in.
        $credentials = \Infrastructure\Credentials\CredentialsRepository::fromEnvironment();
        $credentials->createUserCredential((int)$userId, $tempData['pin_hash']);
        $credentials->setUserTransactionPin((int)$userId, $tempData['pin_hash']);

        // Commit transaction
        $db->commit();
        error_log("VERIFY OTP: Transaction committed successfully");

        // Store user in session
        $stmt = $db->prepare("
            SELECT user_id, username, email, phone, full_name, created_at 
            FROM users WHERE user_id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        SessionManager::setUser([
            'user_id' => $user['user_id'],
            'username' => $user['username'] ?? '',
            'email' => $user['email'],
            'phone' => $user['phone'],
            'full_name' => $user['full_name'] ?? '',
            'created_at' => $user['created_at']
        ]);

        // Clear temp registration data
        unset($_SESSION['temp_registration']);
        unset($_SESSION['otp_verification']);
        unset($_SESSION['otp_verification_expires']);

        error_log("REGISTER: User {$userId} registered successfully via OTP verification");

        echo json_encode([
            'success' => true, 
            'message' => 'Registration successful!',
            'redirect' => 'user_dashboard.php'
        ]);

    } catch (Throwable $e) {
        $db->rollBack();
        error_log("VERIFY OTP USER CREATION ERROR: " . $e->getMessage());
        error_log("VERIFY OTP USER CREATION ERROR Trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Failed to create account: ' . $e->getMessage()]);
    }

} catch (Throwable $e) {
    error_log("VERIFY OTP ERROR: " . $e->getMessage());
    error_log("VERIFY OTP ERROR Trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
