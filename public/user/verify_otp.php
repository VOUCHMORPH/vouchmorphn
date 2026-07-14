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
// Load country configuration
// ============================================================
try {
    $config = \Core\Config\LoadCountry::getConfig();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Configuration error: ' . $e->getMessage()]);
    exit;
}

$systemCountry = $config['country'] ?? getenv('VM_COUNTRY') ?? 'BW';
$countryConfig = $config['country_settings'][$systemCountry] ?? [];
$countryName = $countryConfig['name'] ?? $systemCountry;

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
$identifier = trim($_POST['identifier'] ?? '');
$otp = trim($_POST['otp'] ?? '');

if (empty($identifier)) {
    echo json_encode(['success' => false, 'message' => 'Identifier is required']);
    exit;
}

if (empty($otp) || strlen($otp) !== 6 || !preg_match('/^\d{6}$/', $otp)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid 6-digit verification code']);
    exit;
}

// ============================================================
// Check if temp registration data exists in session
// ============================================================
if (!isset($_SESSION['temp_registration'])) {
    echo json_encode(['success' => false, 'message' => 'Registration session expired. Please try again.']);
    exit;
}

$tempData = $_SESSION['temp_registration'];

// Verify the identifier matches
if ($tempData['identifier_value'] !== $identifier) {
    echo json_encode(['success' => false, 'message' => 'Identifier mismatch. Please try again.']);
    exit;
}

// ============================================================
// Verify OTP from database
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

    if (!$otpRecord) {
        echo json_encode(['success' => false, 'message' => 'Verification code not found. Please request a new code.']);
        exit;
    }

    // Check if expired
    if (strtotime($otpRecord['expires_at']) < time()) {
        echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new code.']);
        exit;
    }

    // Check attempts
    if ($otpRecord['attempts'] >= 5) {
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new code.']);
        exit;
    }

    // Verify the OTP
    if (!password_verify($otp, $otpRecord['code_hash'])) {
        // Increment attempts
        $stmt = $db->prepare("UPDATE otp_logs SET attempts = attempts + 1 WHERE otp_id = :otp_id");
        $stmt->execute([':otp_id' => $otpRecord['otp_id']]);
        
        echo json_encode(['success' => false, 'message' => 'Invalid verification code. Please try again.']);
        exit;
    }

    // ============================================================
    // OTP is valid - Mark as used and create the user
    // ============================================================
    
    // Mark OTP as used
    $stmt = $db->prepare("UPDATE otp_logs SET used_at = NOW() WHERE otp_id = :otp_id");
    $stmt->execute([':otp_id' => $otpRecord['otp_id']]);

    // Begin transaction for user creation
    $db->beginTransaction();

    try {
        // Check if user already exists (double-check)
        $stmt = $db->prepare("
            SELECT user_id FROM users 
            WHERE phone = :phone OR email = :email 
               OR phone2 = :phone2 OR phone3 = :phone3
               OR national_id = :national_id 
               OR drivers_license = :drivers_license 
               OR passport = :passport
            LIMIT 1
        ");
        $stmt->execute([
            ':phone' => $tempData['phone_number'] ?? null,
            ':email' => $tempData['email'] ?? null,
            ':phone2' => $tempData['phone2'] ?? null,
            ':phone3' => $tempData['phone3'] ?? null,
            ':national_id' => ($tempData['identifier_type'] === 'national_id') ? $tempData['identifier_value'] : null,
            ':drivers_license' => ($tempData['identifier_type'] === 'drivers_license') ? $tempData['identifier_value'] : null,
            ':passport' => ($tempData['identifier_type'] === 'passport') ? $tempData['identifier_value'] : null
        ]);
        
        if ($stmt->fetch()) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'User already exists. Please login.']);
            exit;
        }

        // Create the user
        $stmt = $db->prepare("
            INSERT INTO users (
                phone, phone2, phone3, email, 
                national_id, drivers_license, passport,
                full_name, date_of_birth, 
                password_hash, created_at, verified
            ) VALUES (
                :phone, :phone2, :phone3, :email,
                :national_id, :drivers_license, :passport,
                :full_name, :date_of_birth,
                :pin_hash, NOW(), true
            )
        ");
        
        $stmt->execute([
            ':phone' => $tempData['phone_number'] ?? null,
            ':phone2' => $tempData['phone2'] ?? null,
            ':phone3' => $tempData['phone3'] ?? null,
            ':email' => $tempData['email'] ?? null,
            ':national_id' => ($tempData['identifier_type'] === 'national_id') ? $tempData['identifier_value'] : null,
            ':drivers_license' => ($tempData['identifier_type'] === 'drivers_license') ? $tempData['identifier_value'] : null,
            ':passport' => ($tempData['identifier_type'] === 'passport') ? $tempData['identifier_value'] : null,
            ':full_name' => $tempData['full_name'] ?? null,
            ':date_of_birth' => $tempData['date_of_birth'] ?? null,
            ':pin_hash' => $tempData['pin_hash']
        ]);

        $userId = $db->lastInsertId();

        // Create wallet for the user
        $stmt = $db->prepare("
            INSERT INTO wallets (user_id, balance, credit_balance, saccus_ewallet_balance, created_at)
            VALUES (:user_id, 0, 0, 0, NOW())
        ");
        $stmt->execute([':user_id' => $userId]);

        // Create mobile money account
        $stmt = $db->prepare("
            INSERT INTO mobile_money_accounts (user_id, balance, credit_balance, created_at)
            VALUES (:user_id, 0, 0, NOW())
        ");
        $stmt->execute([':user_id' => $userId]);

        // Commit transaction
        $db->commit();

        // Store user in session
        $stmt = $db->prepare("
            SELECT user_id, phone, email, full_name, created_at 
            FROM users WHERE user_id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        SessionManager::setUser([
            'user_id' => $user['user_id'],
            'phone' => $user['phone'],
            'email' => $user['email'],
            'full_name' => $user['full_name'] ?? '',
            'username' => $user['full_name'] ?? '',
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
        error_log("REGISTER USER CREATION ERROR: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to create account: ' . $e->getMessage()]);
    }

} catch (Throwable $e) {
    error_log("VERIFY OTP ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
