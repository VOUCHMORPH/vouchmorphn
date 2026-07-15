<?php
// public/user/login.php
// Supports: phone, phone2, phone3, email, national_id, drivers_license, passport
//
// MODIFIED: OTP is now SUSPENDED/DISABLED - PIN-only login for testing
// OTP can be re-enabled by setting ENABLE_OTP to true

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../vendor/autoload.php';

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Security/Monitoring/ApiRateLimiter.php';
require_once __DIR__ . '/../../src/Infrastructure/SMS/Contracts/ProviderInterface.php';
require_once __DIR__ . '/../../src/Core/Factories/CommunicationFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Email/Contracts/EmailProviderInterface.php';
require_once __DIR__ . '/../../src/Infrastructure/Email/EmailGatewayClient.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Security\Monitoring\ApiRateLimiter;
use Core\Factories\CommunicationFactory;
use Infrastructure\Email\EmailGatewayClient;

// ============================================================
// OTP SUSPENDED - Set to true to re-enable OTP
// ============================================================
define('ENABLE_OTP', false);  // Change to true to enable OTP again

SessionManager::start();

if (SessionManager::isLoggedIn()) {
    header('Location: user_dashboard.php');
    exit();
}

// --------------------------------------------------
// Load Country & Config
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

$countryDialCode  = $countryConfig['dial_code'] ?? '+267';
$localLength      = (int)($countryConfig['local_phone_length'] ?? 8);
$phonePlaceholder = $countryConfig['phone_placeholder'] ?? str_repeat('0', $localLength);
$countryName      = $countryConfig['name'] ?? $systemCountry;
$phonePattern     = '[0-9]{' . $localLength . '}';

// --------------------------------------------------
// DB Bootstrap
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
} catch (\Throwable $e) {
    error_log("[USER LOGIN] DB ERROR: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// --------------------------------------------------
// Helpers
// --------------------------------------------------
function normalizePhone(string $phoneInput, string $dialCode): string
{
    $phoneInput = preg_replace('/[^\d+]/', '', trim($phoneInput));
    if ($phoneInput === '') return '';
    if (str_starts_with($phoneInput, '+')) return $phoneInput;
    return $dialCode . ltrim($phoneInput, '0');
}

function getLocalPhonePart(string $fullPhone, string $dialCode): string
{
    if (str_starts_with($fullPhone, $dialCode)) {
        return substr($fullPhone, strlen($dialCode));
    }
    return ltrim($fullPhone, '0');
}

function generateOTP(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function getClientIp(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return $ip;
}

function maskPhone(string $phone): string
{
    $len = strlen($phone);
    if ($len <= 4) return str_repeat('•', $len);
    return substr($phone, 0, 5) . str_repeat('•', max(0, $len - 8)) . substr($phone, -3);
}

function maskEmail(string $email): string
{
    if (!str_contains($email, '@')) return '•••';
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 1) . str_repeat('•', max(1, strlen($local) - 1)) . '@' . $domain;
}

// --------------------------------------------------
// STATE
// --------------------------------------------------
$error = '';
$mfaRequired = false;
$mfaHint = '';
$identifierType = $_POST['identifier_type'] ?? 'phone';
$inputValueRaw = trim($_POST['identifier'] ?? '');

// ================================================================
// STEP 2: Verify the login OTP (ONLY if ENABLE_OTP is true)
// ================================================================
if (ENABLE_OTP && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_login_otp') {
    header('Content-Type: application/json; charset=utf-8');

    $submittedOtp = trim($_POST['otp'] ?? '');
    $pending = $_SESSION['login_otp_pending'] ?? null;

    if (!$pending) {
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please log in again.']);
        exit;
    }

    if (time() > $pending['expires_at']) {
        unset($_SESSION['login_otp_pending']);
        echo json_encode(['success' => false, 'message' => 'Verification code expired. Please log in again.']);
        exit;
    }

    $pending['attempts'] = ($pending['attempts'] ?? 0) + 1;
    $_SESSION['login_otp_pending'] = $pending;

    if ($pending['attempts'] > 5) {
        unset($_SESSION['login_otp_pending']);
        echo json_encode(['success' => false, 'message' => 'Too many attempts. Please log in again.']);
        exit;
    }

    if (!password_verify($submittedOtp, $pending['code_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Incorrect code. Please try again.']);
        exit;
    }

    // OTP correct — now commit the session.
    try {
        $stmt = $db->prepare("
            SELECT user_id, phone, phone2, phone3, email,
                   national_id, drivers_license, passport,
                   username, full_name, role_id, created_at,
                   has_transaction_pin as pin_enabled
            FROM users WHERE user_id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $pending['user_id']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            unset($_SESSION['login_otp_pending']);
            echo json_encode(['success' => false, 'message' => 'Account not found. Please contact support.']);
            exit;
        }

        $resetStmt = $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id");
        $resetStmt->execute([':id' => $user['user_id']]);

        session_regenerate_id(true);

        SessionManager::login([
            'user_id'         => $user['user_id'],
            'username'        => $user['username'] ?? '',
            'full_name'       => $user['full_name'] ?? $user['username'],
            'phone'           => $user['phone'],
            'phone2'          => $user['phone2'] ?? null,
            'phone3'          => $user['phone3'] ?? null,
            'email'           => $user['email'] ?? null,
            'national_id'     => $user['national_id'] ?? null,
            'drivers_license' => $user['drivers_license'] ?? null,
            'passport'        => $user['passport'] ?? null,
            'role_id'         => $user['role_id'] ?? null,
            'country'         => $systemCountry,
            'created_at'      => $user['created_at'] ?? null,
            'pin_enabled'     => (int)($user['pin_enabled'] ?? 0) === 1,
        ]);

        unset($_SESSION['login_otp_pending']);
        error_log("[USER LOGIN] LOGIN COMPLETE (PIN + OTP): user_id={$user['user_id']}");
        echo json_encode(['success' => true, 'redirect' => 'user_dashboard.php']);
        exit;

    } catch (\Throwable $e) {
        error_log("[USER LOGIN] OTP verify error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'System error. Please try again.']);
        exit;
    }
}

// ================================================================
// STEP 1: Identifier + PIN (OTP is SUSPENDED)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'verify_login_otp') {

    // Normalize based on identifier type
    if ($identifierType === 'phone') {
        $formattedValue = normalizePhone($inputValueRaw, $countryDialCode);
        $inputValue = getLocalPhonePart($formattedValue, $countryDialCode);
    } else {
        $formattedValue = $inputValueRaw;
        $inputValue = $inputValueRaw;
    }

    error_log("[USER LOGIN] Input: {$inputValueRaw}, Formatted: {$formattedValue}, Type: {$identifierType}");

    $clientIp = getClientIp();
    $rateLimited = false;

    // Rate limiter
    try {
        if (class_exists('Redis')) {
            $ipLimiter = new ApiRateLimiter(15, 600);
            $identifierLimiter = new ApiRateLimiter(6, 600);

            $ipOk = $ipLimiter->check('user_login_ip:' . $clientIp);
            $identifierOk = $inputValueRaw !== ''
                ? $identifierLimiter->check('user_login_id:' . strtolower($inputValueRaw))
                : true;

            if (!$ipOk || !$identifierOk) {
                $rateLimited = true;
                error_log("[USER LOGIN] Rate limit exceeded - IP: {$clientIp}, Identifier: {$inputValueRaw}");
            }
        } else {
            error_log("[USER LOGIN] Redis not available - rate limiting disabled");
        }
    } catch (\Throwable $e) {
        error_log("[USER LOGIN] Rate limiter unavailable: " . $e->getMessage() . " - continuing without rate limiting");
    }

    if ($rateLimited) {
        $error = 'Too many login attempts. Please try again later.';
    } else {
        $pin = trim($_POST['pin'] ?? '');

        if ($formattedValue === '' || $pin === '') {
            $error = "Please enter your identifier and PIN.";
        } else {
            try {
                $stmt = $db->prepare("
                    SELECT user_id, phone, phone2, phone3, email,
                           national_id, drivers_license, passport,
                           username, full_name, password_hash, verified,
                           created_at, has_transaction_pin as pin_enabled,
                           failed_login_attempts, locked_until
                    FROM users
                    WHERE phone = :identifier
                       OR phone2 = :identifier
                       OR phone3 = :identifier
                       OR email = :identifier
                       OR national_id = :identifier
                       OR drivers_license = :identifier
                       OR passport = :identifier
                    LIMIT 1
                ");
                $stmt->execute([':identifier' => $formattedValue]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC);

                error_log("[USER LOGIN] User found: " . ($user ? 'YES' : 'NO'));

                if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                    $unlockAt = date('H:i:s', strtotime($user['locked_until']));
                    error_log("[USER LOGIN] Account locked: {$formattedValue} until {$user['locked_until']}");
                    $error = "Account temporarily locked due to repeated failed attempts. Try again after {$unlockAt}.";
                } elseif (!$user || (int)$user['verified'] !== 1) {
                    $error = "Invalid login credentials.";
                    error_log("[USER LOGIN] User not found or not verified");
                } elseif (empty($user['password_hash']) || !password_verify($pin, $user['password_hash'])) {
                    $error = "Invalid login credentials.";
                    error_log("[USER LOGIN] PIN verification failed for {$formattedValue}");

                    $newCount = (int)($user['failed_login_attempts'] ?? 0) + 1;
                    $maxAttempts = 5;
                    $lockMinutes = 15;
                    try {
                        if ($newCount >= $maxAttempts) {
                            $lockStmt = $db->prepare("
                                UPDATE users SET failed_login_attempts = :c,
                                    locked_until = NOW() + (:m || ' minutes')::interval
                                WHERE user_id = :id
                            ");
                            $lockStmt->execute([':c' => $newCount, ':m' => $lockMinutes, ':id' => $user['user_id']]);
                            error_log("[USER LOGIN] Account locked for {$formattedValue} for {$lockMinutes} minutes");
                        } else {
                            $incStmt = $db->prepare("UPDATE users SET failed_login_attempts = :c WHERE user_id = :id");
                            $incStmt->execute([':c' => $newCount, ':id' => $user['user_id']]);
                        }
                    } catch (\Throwable $e) {
                        error_log("[USER LOGIN] Failed to record PIN attempt: " . $e->getMessage());
                    }
                } else {
                    // ========================================================
                    // PIN CORRECT 
                    // If OTP is ENABLED: send OTP and require it
                    // If OTP is SUSPENDED: log in immediately
                    // ========================================================
                    
                    if (ENABLE_OTP) {
                        // OTP ENABLED - Send OTP and require verification
                        $otpChannel = !empty($user['phone']) ? 'sms' : (!empty($user['email']) ? 'email' : null);
                        $otpDestination = $otpChannel === 'sms' ? $user['phone'] : ($user['email'] ?? null);

                        if (!$otpChannel || !$otpDestination) {
                            $error = "Your account has no verified contact method on file. Please contact support.";
                            error_log("[USER LOGIN] User {$user['user_id']} has neither phone nor email — cannot send login OTP");
                        } else {
                            $otpPlain = generateOTP();
                            $otpHash = password_hash($otpPlain, PASSWORD_DEFAULT);

                            $_SESSION['login_otp_pending'] = [
                                'user_id' => $user['user_id'],
                                'code_hash' => $otpHash,
                                'expires_at' => time() + 300,
                                'attempts' => 0,
                            ];

                            $sent = false;
                            if ($otpChannel === 'sms') {
                                try {
                                    $comm = CommunicationFactory::createForPhone('sms', $otpDestination);
                                    $result = $comm->send($otpDestination, "Your VouchMorph login code: {$otpPlain}");
                                    $sent = (bool)($result['success'] ?? false);
                                    $mfaHint = maskPhone($otpDestination);
                                    
                                    if ($sent) {
                                        $providerName = $comm->getProviderName();
                                        error_log("[USER LOGIN] Login OTP sent via {$providerName} to {$otpDestination}");
                                    } else {
                                        $errorMsg = $result['error'] ?? 'Unknown error';
                                        error_log("[USER LOGIN] SMS OTP send FAILED: {$errorMsg}");
                                        $_SESSION['login_otp_error'] = "We couldn't send your verification code. Please try again.";
                                    }
                                } catch (Throwable $e) {
                                    error_log("[USER LOGIN] SMS OTP send exception: " . $e->getMessage());
                                    $sent = false;
                                    $_SESSION['login_otp_error'] = "System error sending verification code. Please try again.";
                                }
                            } else {
                                try {
                                    $emailClient = new EmailGatewayClient($config['email'] ?? []);
                                    $subject = "Your VouchMorph Login Code";
                                    $body = "<p>Your login verification code is: <strong style='font-size:22px;'>{$otpPlain}</strong></p><p>This code expires in 5 minutes. Never share it with anyone.</p>";
                                    $result = $emailClient->sendEmail($otpDestination, $subject, $body);
                                    $sent = (bool)($result['success'] ?? false);
                                    $mfaHint = maskEmail($otpDestination);
                                    
                                    if (!$sent) {
                                        $errorMsg = $result['error'] ?? 'Unknown email error';
                                        error_log("[USER LOGIN] Email OTP send FAILED: {$errorMsg}");
                                    }
                                } catch (Throwable $e) {
                                    error_log("[USER LOGIN] Email OTP send failed: " . $e->getMessage());
                                    $sent = false;
                                }
                            }

                            if ($sent) {
                                $mfaRequired = true;
                                error_log("[USER LOGIN] PIN OK, OTP sent via {$otpChannel} to user_id={$user['user_id']}");
                            } else {
                                unset($_SESSION['login_otp_pending']);
                                $error = "We couldn't send your verification code right now. Please try again shortly.";
                            }
                        }
                    } else {
                        // ========================================================
                        // OTP SUSPENDED - Log in immediately after PIN verification
                        // ========================================================
                        try {
                            $resetStmt = $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id");
                            $resetStmt->execute([':id' => $user['user_id']]);

                            session_regenerate_id(true);

                            SessionManager::login([
                                'user_id'         => $user['user_id'],
                                'username'        => $user['username'] ?? '',
                                'full_name'       => $user['full_name'] ?? $user['username'],
                                'phone'           => $user['phone'],
                                'phone2'          => $user['phone2'] ?? null,
                                'phone3'          => $user['phone3'] ?? null,
                                'email'           => $user['email'] ?? null,
                                'national_id'     => $user['national_id'] ?? null,
                                'drivers_license' => $user['drivers_license'] ?? null,
                                'passport'        => $user['passport'] ?? null,
                                'role_id'         => $user['role_id'] ?? null,
                                'country'         => $systemCountry,
                                'created_at'      => $user['created_at'] ?? null,
                                'pin_enabled'     => (int)($user['pin_enabled'] ?? 0) === 1,
                            ]);

                            error_log("[USER LOGIN] LOGIN COMPLETE (PIN ONLY - OTP SUSPENDED): user_id={$user['user_id']}");
                            
                            // Redirect to dashboard
                            header('Location: user_dashboard.php');
                            exit;

                        } catch (\Throwable $e) {
                            error_log("[USER LOGIN] Login error: " . $e->getMessage());
                            $error = "System error. Please try again.";
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("[USER LOGIN] LOGIN QUERY ERROR: " . $e->getMessage());
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
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background-image:
            linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
        background-size: 50px 50px;
        pointer-events: none; z-index: 0;
    }
    .login-container {
        position: relative; z-index: 2; width: 100%; max-width: 520px;
        background: rgba(5, 5, 5, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(10px);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        border-radius: 16px;
    }
    .login-header { padding: 2rem 2rem 1.5rem; text-align: center; border-bottom: 1px solid rgba(255, 255, 255, 0.08); }
    .login-header h1 { font-family: 'Clash Display', sans-serif; font-size: 2rem; font-weight: 700; letter-spacing: -0.02em; background: linear-gradient(135deg, #FFFFFF 0%, #00F0FF 40%, #B000FF 100%); -webkit-background-clip: text; background-clip: text; color: transparent; margin-bottom: 0.5rem; }
    .subtitle { font-size: 0.875rem; color: #A0A0B0; margin-bottom: 1rem; }
    .system-badge { display: inline-block; padding: 0.25rem 0.75rem; background: rgba(0, 240, 255, 0.1); border: 1px solid rgba(0, 240, 255, 0.3); font-size: 0.7rem; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase; color: #00F0FF; border-radius: 20px; }
    .otp-suspended-badge { display: inline-block; padding: 0.25rem 0.75rem; background: rgba(255, 193, 7, 0.15); border: 1px solid rgba(255, 193, 7, 0.3); font-size: 0.65rem; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase; color: #FFC107; border-radius: 20px; margin-left: 8px; }
    .login-form { padding: 2rem; }
    .step-pane { display: none; }
    .step-pane.active { display: block; animation: fadeInUp 0.4s ease; }
    .form-group { margin-bottom: 1.5rem; }
    .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #C0C0D0; }
    .identifier-type-selector { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
    .id-type-btn { padding: 0.4rem 0.8rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #808090; font-size: 0.7rem; font-weight: 500; text-transform: uppercase; cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif; border-radius: 6px; }
    .id-type-btn.active { border-color: #00F0FF; color: #00F0FF; background: rgba(0, 240, 255, 0.1); }
    .id-type-btn:hover { color: #FFFFFF; }
    .phone-input-container { display: flex; border: 1px solid rgba(255, 255, 255, 0.15); background: rgba(0, 0, 0, 0.5); transition: all 0.2s ease; border-radius: 8px; overflow: hidden; }
    .phone-input-container:focus-within { border-color: #00F0FF; box-shadow: 0 0 0 1px rgba(0, 240, 255, 0.2); }
    .phone-prefix { padding: 0.875rem 1rem; font-family: 'Space Grotesk', monospace; font-weight: 500; color: #00F0FF; background: rgba(0, 240, 255, 0.05); border-right: 1px solid rgba(255, 255, 255, 0.1); letter-spacing: 0.5px; display: none; }
    .phone-prefix.show { display: flex; }
    .form-control { flex: 1; border: none; padding: 0.875rem 1rem; font-size: 1rem; font-family: 'Inter', sans-serif; background: transparent; color: #FFFFFF; outline: none; width: 100%; }
    .form-control::placeholder { color: #505060; }
    .pin-input, .otp-input { font-family: 'Space Grotesk', monospace; font-size: 1.25rem; letter-spacing: 0.5rem; text-align: center; }
    .login-btn { width: 100%; padding: 1rem; background: linear-gradient(135deg, #00F0FF 0%, #B000FF 100%); color: #050505; border: none; font-family: 'General Sans', sans-serif; font-weight: 700; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.1em; cursor: pointer; transition: all 0.2s ease; margin-top: 0.5rem; border-radius: 8px; }
    .login-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(0, 240, 255, 0.4); }
    .login-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    .btn-secondary { background: transparent; border: 1px solid rgba(255, 255, 255, 0.3); color: #FFFFFF; margin-top: 0.75rem; }
    .error-message { background: rgba(255, 48, 48, 0.1); border-left: 3px solid #FF3030; padding: 0.875rem; margin-bottom: 1.5rem; font-size: 0.8125rem; color: #FF6060; border-radius: 4px; }
    .mfa-info { background: rgba(0, 240, 255, 0.08); border-left: 3px solid #00F0FF; padding: 0.875rem; margin-bottom: 1.5rem; font-size: 0.8125rem; color: #A0E0FF; border-radius: 4px; }
    .security-notice { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid rgba(255, 255, 255, 0.05); font-size: 0.7rem; color: #606070; text-align: center; }
    .login-footer { padding: 1.25rem 2rem; border-top: 1px solid rgba(255, 255, 255, 0.05); background: rgba(10, 10, 20, 0.3); border-radius: 0 0 16px 16px; }
    .login-links { display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap; }
    .login-links a { color: #808090; text-decoration: none; font-size: 0.75rem; font-weight: 500; transition: color 0.2s; }
    .login-links a:hover { color: #00F0FF; }
    .otp-status { text-align: center; padding: 0.5rem; background: rgba(255, 193, 7, 0.05); border: 1px dashed rgba(255, 193, 7, 0.2); border-radius: 8px; margin-bottom: 1rem; font-size: 0.75rem; color: #FFC107; }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 640px) {
        .login-container { margin: 1rem; border-radius: 12px; }
        .login-header { padding: 1.5rem 1.5rem 1rem; }
        .login-header h1 { font-size: 1.5rem; }
        .login-form { padding: 1.5rem; }
        .login-footer { padding: 1rem 1.5rem; }
        .login-links { gap: 1rem; }
        .identifier-type-selector { gap: 0.25rem; }
        .id-type-btn { font-size: 0.6rem; padding: 0.3rem 0.6rem; }
    }
</style>
</head>
<body>

<div class="login-container">
    <div class="login-header">
        <h1>VOUCHMORPH<sup style="font-size: 0.7rem;">™</sup></h1>
        <div class="subtitle">Interoperability Platform</div>
        <div>
            <span class="system-badge"><?= htmlspecialchars(strtoupper($countryName)) ?> • SECURE LOGIN</span>
            <?php if (!ENABLE_OTP): ?>
            <span class="otp-suspended-badge">⚡ OTP SUSPENDED</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="login-form">
        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!ENABLE_OTP): ?>
        <div class="otp-status">⚡ Two-factor authentication is currently suspended. PIN-only login is active.</div>
        <?php endif; ?>

        <?php if ($mfaRequired && ENABLE_OTP): ?>
            <div class="mfa-info">🔐 We've sent a verification code to <strong><?= htmlspecialchars($mfaHint) ?></strong>. Enter it below to finish logging in.</div>
        <?php endif; ?>

        <!-- STEP 1: Identifier + PIN -->
        <div id="step-credentials" class="step-pane <?= ($mfaRequired && ENABLE_OTP) ? '' : 'active' ?>">
            <form method="POST" novalidate id="credentialsForm">
                <div class="form-group">
                    <label>IDENTIFIER TYPE</label>
                    <div class="identifier-type-selector">
                        <button type="button" class="id-type-btn active" data-type="phone" onclick="setIdentifierType('phone')">📱 Phone</button>
                        <button type="button" class="id-type-btn" data-type="email" onclick="setIdentifierType('email')">✉️ Email</button>
                        <button type="button" class="id-type-btn" data-type="national_id" onclick="setIdentifierType('national_id')">🆔 National ID</button>
                        <button type="button" class="id-type-btn" data-type="drivers_license" onclick="setIdentifierType('drivers_license')">🚗 License</button>
                        <button type="button" class="id-type-btn" data-type="passport" onclick="setIdentifierType('passport')">📖 Passport</button>
                    </div>
                    <input type="hidden" name="identifier_type" id="identifier_type" value="phone">
                </div>
                <div class="form-group">
                    <label id="identifier-label">MOBILE NUMBER</label>
                    <div class="phone-input-container">
                        <span class="phone-prefix show" id="phone-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                        <input type="text" name="identifier" id="identifier-input" class="form-control" required
                               value="<?= htmlspecialchars($inputValueRaw) ?>"
                               placeholder="<?= htmlspecialchars($phonePlaceholder) ?>" autocomplete="off">
                    </div>
                    <div style="font-size: 0.7rem; color: #606070; margin-top: 0.5rem;" id="identifier-help">Enter your primary phone number</div>
                </div>
                <div class="form-group">
                    <label>PIN</label>
                    <input type="password" name="pin" class="form-control pin-input" required maxlength="6"
                           placeholder="••••••" inputmode="numeric" autocomplete="current-password">
                </div>
                <button type="submit" class="login-btn">
                    <?= ENABLE_OTP ? 'CONTINUE →' : 'LOGIN →' ?>
                </button>
            </form>
        </div>

        <!-- STEP 2: OTP (only shown if ENABLE_OTP is true) -->
        <?php if (ENABLE_OTP): ?>
        <div id="step-otp" class="step-pane <?= $mfaRequired ? 'active' : '' ?>">
            <form id="otpForm" novalidate>
                <div class="form-group">
                    <label>ENTER VERIFICATION CODE</label>
                    <input type="text" id="otp-input" class="form-control otp-input" maxlength="6" placeholder="••••••" inputmode="numeric" autocomplete="off">
                </div>
                <button type="submit" class="login-btn" id="verifyOtpBtn">VERIFY & CONTINUE →</button>
                <button type="button" class="login-btn btn-secondary" onclick="backToCredentials()">← BACK</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="security-notice">
            <?php if (ENABLE_OTP): ?>
            We never ask where to send your code — it always goes to the phone or email already on your account.
            <?php else: ?>
            PIN-only login is active. Two-factor authentication is temporarily suspended.
            <?php endif; ?>
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
const countryDialCode = '<?= $countryDialCode ?>';
const enableOtp = <?= ENABLE_OTP ? 'true' : 'false' ?>;

function setIdentifierType(type) {
    document.querySelectorAll('#step-credentials .id-type-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`#step-credentials .id-type-btn[data-type="${type}"]`)?.classList.add('active');
    document.getElementById('identifier_type').value = type;

    const isPhone = type === 'phone';
    const prefix = document.getElementById('phone-prefix');
    const input = document.getElementById('identifier-input');
    const label = document.getElementById('identifier-label');
    const help = document.getElementById('identifier-help');

    if (isPhone) {
        prefix.classList.add('show');
        input.type = 'tel';
        input.placeholder = '<?= htmlspecialchars($phonePlaceholder) ?>';
        label.textContent = 'MOBILE NUMBER';
        help.textContent = 'Enter your primary phone number';
    } else {
        prefix.classList.remove('show');
        input.type = 'text';
        const labels = { 'email': 'EMAIL ADDRESS', 'national_id': 'NATIONAL ID NUMBER', 'drivers_license': "DRIVER'S LICENSE NUMBER", 'passport': 'PASSPORT NUMBER' };
        const helps = { 'email': 'Enter your email address', 'national_id': 'Enter your National ID number', 'drivers_license': "Enter your Driver's License number", 'passport': 'Enter your Passport number' };
        const placeholders = { 'email': 'you@example.com', 'national_id': 'Enter National ID', 'drivers_license': "Enter Driver's License", 'passport': 'Enter Passport number' };
        label.textContent = labels[type] || 'IDENTIFIER';
        input.placeholder = placeholders[type] || 'Enter your identifier';
        help.textContent = helps[type] || 'Enter your identifier';
    }
    input.value = '';
}

function backToCredentials() {
    document.getElementById('step-otp').classList.remove('active');
    document.getElementById('step-credentials').classList.add('active');
}

// OTP form submission (only if ENABLE_OTP)
<?php if (ENABLE_OTP): ?>
document.getElementById('otpForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const otp = document.getElementById('otp-input').value.trim();
    if (!/^\d{6}$/.test(otp)) return;

    const btn = document.getElementById('verifyOtpBtn');
    btn.disabled = true;
    btn.textContent = 'VERIFYING...';

    const formData = new URLSearchParams();
    formData.append('action', 'verify_login_otp');
    formData.append('otp', otp);

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = 'VERIFY & CONTINUE →';
        if (data.success) {
            window.location.href = data.redirect || 'user_dashboard.php';
        } else {
            alert(data.message || 'Incorrect code.');
            document.getElementById('otp-input').value = '';
            document.getElementById('otp-input').focus();
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = 'VERIFY & CONTINUE →';
        alert('Network error. Please try again.');
    });
});
<?php endif; ?>

document.getElementById('identifier-input')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') this.closest('form').submit();
});
</script>
</body>
</html>
