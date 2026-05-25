<?php
// APP_LAYER/api/verify_otp.php — API endpoint to verify OTP (supports Phone + ID documents)

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

/**
 * 1️⃣ Load Country & Config (The New Standard)
 * This automatically maps to /countries/[CODE]/.env_[CODE]
 */
try {
    // Fix paths based on your file structure
    $possiblePaths = [
        __DIR__ . '/../../src/Core/Config/LoadCountry.php',
        __DIR__ . '/../../src/CORE_CONFIG/load_country.php',
        __DIR__ . '/../../src/Core/Config/load_country.php'
    ];
    
    $configPath = null;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $configPath = $path;
            break;
        }
    }
    
    if (!$configPath) {
        throw new Exception("LoadCountry.php not found in any expected location");
    }
    
    // Load config and set SYSTEM_COUNTRY
    $config = require $configPath;
    
    // Define SYSTEM_COUNTRY if not already defined
    if (!defined('SYSTEM_COUNTRY')) {
        define('SYSTEM_COUNTRY', $config['country'] ?? 'Botswana');
    }
    
    $country = SYSTEM_COUNTRY;
    $dbConfig = $config['db'] ?? [];
    
    if (empty($dbConfig)) {
        throw new Exception("Database configuration not found for {$country}.");
    }
    
} catch (Throwable $e) {
    error_log("Verify OTP Bootstrap Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System configuration error.']);
    exit;
}

// Load required classes
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Factories/CommunicationFactory.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Core\Factories\CommunicationFactory;

SessionManager::start();

/**
 * 2️⃣ DB Connections
 */
try {
    $swapSystemDB = DBConnection::getInstance($dbConfig['swap']);
    $swapSystemDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sourceKey = $config['db']['source_client_key'] ?? 'cazacom';
    $sourceClientDB = DBConnection::getInstance($dbConfig[$sourceKey]);
    $sourceClientDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (Throwable $e) {
    error_log("Verify OTP DB Error [{$country}]: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

/**
 * 3️⃣ Input validation - Supports both phone and ID-based verification
 */
$action = $_POST['action'] ?? 'verify';
$inputType = $_POST['input_type'] ?? 'phone';
$identifier = trim($_POST['identifier'] ?? $_POST['phone'] ?? '');
$otp = trim($_POST['otp'] ?? '');

if ($identifier === '' || $otp === '') {
    echo json_encode(['success' => false, 'message' => 'Identifier and verification code are required.']);
    exit;
}

// Get temp registration data from session
$tempData = $_SESSION['temp_registration'] ?? [];

/**
 * 4️⃣ Verify OTP against database (supports phone OR national_id)
 */
try {
    // Build query based on identifier type
    $query = "
        SELECT id, code, expires_at, used, phone, national_id, identifier_type 
        FROM otp_codes 
        WHERE (phone = :identifier OR national_id = :identifier) 
        AND code = :code 
        AND used = 0 
        AND expires_at > NOW() 
        ORDER BY id DESC 
        LIMIT 1
    ";
    
    $stmt = $swapSystemDB->prepare($query);
    $stmt->execute([
        ':identifier' => $identifier,
        ':code' => $otp
    ]);
    
    $otpRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$otpRow) {
        // Check if OTP exists but might be expired
        $checkStmt = $swapSystemDB->prepare("
            SELECT id, used, expires_at 
            FROM otp_codes 
            WHERE (phone = :identifier OR national_id = :identifier) 
            AND code = :code 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $checkStmt->execute([':identifier' => $identifier, ':code' => $otp]);
        $existingOtp = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingOtp) {
            if ($existingOtp['used']) {
                echo json_encode(['success' => false, 'message' => 'This verification code has already been used.']);
                exit;
            }
            if (strtotime($existingOtp['expires_at']) < time()) {
                echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new one.']);
                exit;
            }
        }
        
        echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
        exit;
    }
    
    // Mark OTP as used
    $updateStmt = $swapSystemDB->prepare("UPDATE otp_codes SET used = 1 WHERE id = ?");
    $updateStmt->execute([$otpRow['id']]);
    
    // Determine which identifier to use for user lookup
    $userPhone = $otpRow['phone'];
    $userNationalId = $otpRow['national_id'];
    $identifierType = $otpRow['identifier_type'] ?? $inputType;
    
    /**
     * 5️⃣ Check if user already exists in SWAP database
     */
    $userCheckQuery = "SELECT user_id, phone, national_id, drivers_license, passport FROM users WHERE ";
    $userCheckParams = [];
    
    if ($userPhone) {
        $userCheckQuery .= "phone = :phone";
        $userCheckParams[':phone'] = $userPhone;
    } else if ($userNationalId) {
        $userCheckQuery .= "national_id = :national_id";
        $userCheckParams[':national_id'] = $userNationalId;
    } else {
        $userCheckQuery .= "phone = :identifier OR national_id = :identifier";
        $userCheckParams[':identifier'] = $identifier;
    }
    
    $userCheckQuery .= " LIMIT 1";
    
    $stmt = $swapSystemDB->prepare($userCheckQuery);
    $stmt->execute($userCheckParams);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get source client data if available
    $sourceUserData = [];
    if ($userPhone) {
        try {
            $stmt = $sourceClientDB->prepare("
                SELECT id, phone_number, full_name, email, national_id, drivers_license, passport, date_of_birth
                FROM users 
                WHERE phone_number = :phone 
                LIMIT 1
            ");
            $stmt->execute([':phone' => $userPhone]);
            $sourceUserData = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Source DB lookup warning: " . $e->getMessage());
        }
    }
    
    /**
     * 6️⃣ Create or update user in SWAP database
     */
    if (!$existingUser) {
        // Get data from temp session or source DB
        $fullName = $tempData['full_name'] ?? ($sourceUserData['full_name'] ?? null);
        $email = $sourceUserData['email'] ?? null;
        $dateOfBirth = $tempData['date_of_birth'] ?? ($sourceUserData['date_of_birth'] ?? null);
        $nationalId = $tempData['identifier_type'] === 'national_id' ? $tempData['identifier_value'] : ($sourceUserData['national_id'] ?? $userNationalId);
        $driversLicense = $tempData['identifier_type'] === 'drivers_license' ? $tempData['identifier_value'] : ($sourceUserData['drivers_license'] ?? null);
        $passport = $tempData['identifier_type'] === 'passport' ? $tempData['identifier_value'] : ($sourceUserData['passport'] ?? null);
        
        // Generate temporary password for future login
        $tempPassword = bin2hex(random_bytes(4));
        $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Insert new user
        $insertQuery = "
            INSERT INTO users 
            (phone, national_id, drivers_license, passport, id_type, full_name, email, date_of_birth, 
             is_verified, password_hash, created_at, updated_at, status)
            VALUES 
            (:phone, :national_id, :drivers_license, :passport, :id_type, :full_name, :email, :date_of_birth,
             1, :password_hash, NOW(), NOW(), 'active')
        ";
        
        $insertParams = [
            ':phone' => $userPhone,
            ':national_id' => $nationalId,
            ':drivers_license' => $driversLicense,
            ':passport' => $passport,
            ':id_type' => $identifierType,
            ':full_name' => $fullName,
            ':email' => $email,
            ':date_of_birth' => $dateOfBirth,
            ':password_hash' => $passwordHash
        ];
        
        $stmt = $swapSystemDB->prepare($insertQuery);
        $stmt->execute($insertParams);
        
        $userId = $swapSystemDB->lastInsertId();
        $isNewUser = true;
        
        error_log("New user registered via {$identifierType}: {$identifier} (ID: {$userId})");
        
    } else {
        // Update existing user - mark as verified
        $updateQuery = "
            UPDATE users 
            SET is_verified = 1, 
                updated_at = NOW(),
                phone = COALESCE(:phone, phone),
                national_id = COALESCE(:national_id, national_id),
                drivers_license = COALESCE(:drivers_license, drivers_license),
                passport = COALESCE(:passport, passport)
            WHERE user_id = :user_id
        ";
        
        $updateParams = [
            ':user_id' => $existingUser['user_id'],
            ':phone' => $userPhone,
            ':national_id' => $userNationalId,
            ':drivers_license' => $tempData['identifier_type'] === 'drivers_license' ? $tempData['identifier_value'] : null,
            ':passport' => $tempData['identifier_type'] === 'passport' ? $tempData['identifier_value'] : null
        ];
        
        $stmt = $swapSystemDB->prepare($updateQuery);
        $stmt->execute($updateParams);
        
        $userId = $existingUser['user_id'];
        $isNewUser = false;
        
        error_log("Existing user verified: {$identifier} (ID: {$userId})");
    }
    
    /**
     * 7️⃣ Log the user in
     */
    SessionManager::set('user_id', $userId);
    SessionManager::set('user_phone', $userPhone);
    SessionManager::set('user_identifier_type', $identifierType);
    SessionManager::set('user_identifier', $identifier);
    SessionManager::set('logged_in', true);
    SessionManager::set('login_time', time());
    
    // Clear temp registration data
    unset($_SESSION['temp_registration']);
    
    /**
     * 8️⃣ Send confirmation message (SMS if phone available)
     */
    try {
        if ($userPhone) {
            $comm = CommunicationFactory::create('CAZACOM');
            $confirmationMessage = $isNewUser 
                ? "Welcome to VouchMorph {$country}! Your account has been successfully verified. You can now access your dashboard."
                : "Your VouchMorph account has been successfully verified. Welcome back!";
            
            $comm->sendSMS($userPhone, $confirmationMessage);
            error_log("Confirmation SMS sent to {$userPhone}");
        }
    } catch (Throwable $e) {
        error_log("Confirmation SMS failed: " . $e->getMessage());
        // Don't fail the verification if SMS fails
    }
    
    /**
     * 9️⃣ Return success response
     */
    echo json_encode([
        'success' => true,
        'message' => $isNewUser 
            ? 'Registration successful! Redirecting to dashboard...' 
            : 'Verification successful! Welcome back!',
        'is_new_user' => $isNewUser,
        'user_id' => $userId,
        'identifier_type' => $identifierType
    ]);
    
} catch (Throwable $e) {
    error_log('Verify OTP Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred while verifying. Please try again.'
    ]);
}
