<?php
// public/user/login.php
// Supports: phone, phone2, phone3, email, national_id, drivers_license, passport
//
// ⚡ SUPER TEST MODE: NO PIN REQUIRED ⚡
// - No rate limiting
// - No account locking
// - No PIN verification (ANY PIN works, or no PIN at all)
// - OTP completely disabled
// - Just needs a valid identifier
//
// ⚠️ SECURITY NOTE: This mode authenticates any user by identifier alone.
// Flip SKIP_PIN_VERIFICATION / TEST_MODE back to false before going live.
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Infrastructure/SMS/Contracts/ProviderInterface.php';
require_once __DIR__ . '/../../src/Core/Factories/CommunicationFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Email/Contracts/EmailProviderInterface.php';
require_once __DIR__ . '/../../src/Infrastructure/Email/EmailGatewayClient.php';
require_once __DIR__ . '/../../src/Core/Database/CredentialsDBConnection.php';
require_once __DIR__ . '/../../src/Infrastructure/Credentials/CredentialsRepository.php';
require_once __DIR__ . '/../../src/Domain/Identity/IdentifierNormalizer.php';
require_once __DIR__ . '/../../src/Domain/Identity/UserIdentifierLookup.php';
use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Core\Factories\CommunicationFactory;
use Infrastructure\Email\EmailGatewayClient;
use Infrastructure\Credentials\CredentialsRepository;
use Domain\Identity\AccountEmail;
use Domain\Identity\IdentifierNormalizer;
use Domain\Identity\SignInSchema;
use Domain\Identity\UserIdentifierLookup;
use Security\Auth\LoginPinVerifier;
// ============================================================
// SUPER TEST MODE: NO PIN REQUIRED
// ============================================================
// ============================================================
// SECURITY: test-mode PIN bypass is now gated behind APP_ENV.
// Defaults to PRODUCTION (secure) behavior if APP_ENV is unset —
// fail-closed, never fail-open. Confirmed live 21 Aug 2026: this was
// previously hardcoded true with no environment check at all, meaning
// ANY PIN (or none) authenticated any known identifier, in production.
//
// SECOND BUG FOUND AND FIXED 21 Aug 2026: even after the APP_ENV gate
// above was added, $pinValid was initialized to `true` and no failure
// branch ever set it back to `false` — so a wrong/no PIN in production
// still logged the user in (an $error string was set, but never
// actually checked before granting the session). $pinValid now
// defaults to `false` and every grant path sets it explicitly.
// ============================================================
$appEnv = getenv('APP_ENV') ?: 'production';
$isTestEnvironment = in_array($appEnv, ['test', 'dev', 'development', 'staging'], true);

define('ENABLE_OTP', !$isTestEnvironment);
define('TEST_MODE', $isTestEnvironment);
define('SKIP_PIN_VERIFICATION', $isTestEnvironment);
define('ALLOW_EMPTY_PIN', $isTestEnvironment);

if ($isTestEnvironment) {
    error_log("[USER LOGIN] WARNING: running in TEST MODE (APP_ENV={$appEnv}) — PIN verification is bypassed. This must never run with APP_ENV unset or 'production'.");
}

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
if (!defined('LOCAL_PHONE_LENGTH')) {
    define('LOCAL_PHONE_LENGTH', $localLength);
}
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
    // Same canonical shape as registration writes — see
    // Domain\Identity\IdentifierNormalizer.
    return IdentifierNormalizer::canonicalPhone(
        $phoneInput,
        $dialCode,
        defined('LOCAL_PHONE_LENGTH') ? LOCAL_PHONE_LENGTH : null
    );
}
function getLocalPhonePart(string $fullPhone, string $dialCode): string
{
    if (str_starts_with($fullPhone, $dialCode)) {
        return substr($fullPhone, strlen($dialCode));
    }
    return ltrim($fullPhone, '0');
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
$identifierType = $_POST['identifier_type'] ?? 'phone';
$inputValueRaw = trim($_POST['identifier'] ?? '');
// One answer for "no such account" and "wrong PIN", so the form can't be
// used to find out which phone numbers and emails have accounts.
$detailsDontMatch = "Those details don't match our records. Please check and try again.";
// ================================================================
// LOGIN: SUPER TEST MODE - NO PIN REQUIRED
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Normalize based on identifier type. This is only for display and
    // logging now — the lookup itself no longer depends on the typed
    // shape matching the stored shape (see below).
    if ($identifierType === 'phone') {
        $formattedValue = normalizePhone($inputValueRaw, $countryDialCode);
        $inputValue = getLocalPhonePart($formattedValue, $countryDialCode);
    } else {
        $formattedValue = $inputValueRaw;
        $inputValue = $inputValueRaw;
    }
    error_log("[USER LOGIN SUPER TEST] Input: {$inputValueRaw}, Formatted: {$formattedValue}, Type: {$identifierType}");
    $pin = trim($_POST['pin'] ?? '');
    if ($inputValueRaw === '') {
        $error = "Please enter your identifier.";
    } else {
        try {
            // ========================================================
            // IDENTIFIER LOOKUP
            //
            // This used to be a single `= :identifier` match against
            // every column, which meant a real account holder was told
            // "User not found" whenever the shape they typed today was
            // not byte-for-byte the shape stored at sign-up:
            //
            //   registered typing "26771234567" -> stored +26726771234567
            //   signing in typing "71234567"    -> looked up +26771234567
            //
            // (both forms come out of the same normalizePhone(), which
            // never removed a country code the person typed themselves)
            // and, for email, registration lowercases while the lookup
            // did not — so "Jane@Example.com", which is what a phone
            // keyboard autocapitalises, never matched the stored
            // "jane@example.com" under a case-sensitive Postgres `=`.
            //
            // UserIdentifierLookup matches every shape the number could
            // have been stored in, email case-insensitively, and ID
            // numbers ignoring case and punctuation. Nothing in the
            // table is rewritten — the read is what widened.
            // ========================================================
            $hasEmailVerifiedAt = SignInSchema::hasEmailVerifiedAt($db);
            $user = UserIdentifierLookup::find(
                $db,
                $inputValueRaw,
                $countryDialCode,
                $localLength,
                'user_id, phone, phone2, phone3, email,
                 national_id, drivers_license, passport,
                 username, full_name, verified,
                 created_at, has_transaction_pin,
                 role_id' . ($hasEmailVerifiedAt ? ', email_verified_at' : '')
            );
            // An email signs in only once it has been verified with a
            // code — never the made-up address a phone-only sign-up is
            // given. Otherwise it is treated exactly like no account.
            if ($user && AccountEmail::blocksSignIn($user, $inputValueRaw, $hasEmailVerifiedAt)) {
                error_log("[USER LOGIN] Unverified email typed for user_id={$user['user_id']} — treated as no match");
                $user = null;
            }
            error_log("[USER LOGIN SUPER TEST] User found: " . ($user ? 'YES' : 'NO'));
            if (!$user) {
                $error = $detailsDontMatch;
                error_log("[USER LOGIN SUPER TEST] User not found: {$formattedValue} (raw: {$inputValueRaw})");
            } elseif ((int)$user['verified'] !== 1) {
                $error = "Account not verified. Please contact support.";
                error_log("[USER LOGIN SUPER TEST] User not verified: {$formattedValue}");
            } else {
                // ========================================================
                // PIN CHECK
                // Defaults to CLOSED. Every branch that should grant
                // access sets $pinValid = true explicitly — nothing
                // falls through to a granted session by default.
                // ========================================================

                $pinValid = false; // default to CLOSED

                if (SKIP_PIN_VERIFICATION) {
                    // Test/dev/staging only (gated by APP_ENV above) —
                    // any PIN, or none, is accepted.
                    $pinValid = true;
                    error_log("[USER LOGIN SUPER TEST] PIN SKIPPED - any PIN accepted (or no PIN)");
                } elseif ($pin === '') {
                    // Not counted as a wrong PIN: pressing Enter in the
                    // identifier box submits the form before a PIN is typed.
                    $error = "Please enter your 6-digit PIN.";
                } else {
                    // Login secrets live in the separate credentials
                    // database. LoginPinVerifier checks the PIN there and
                    // keeps the lockout: 5 wrong PINs pause sign-in for
                    // 30 minutes; a correct one resets the count.
                    try {
                        $pinCheck = (new LoginPinVerifier(CredentialsRepository::fromEnvironment()))
                            ->check((int)$user['user_id'], $pin);
                    } catch (\Throwable $e) {
                        error_log("[USER LOGIN] Credentials DB error: " . $e->getMessage());
                        $pinCheck = null;
                    }

                    if ($pinCheck === null) {
                        $error = "Sign-in isn't available right now. Please try again shortly.";
                    } elseif ($pinCheck['result'] === LoginPinVerifier::OK) {
                        $pinValid = true;
                        error_log("[USER LOGIN SUPER TEST] PIN verified successfully");
                    } elseif ($pinCheck['result'] === LoginPinVerifier::LOCKED) {
                        $error = "Too many wrong PINs. For your safety, sign-in is paused for "
                            . LoginPinVerifier::LOCK_MINUTES . " minutes. Please try again later.";
                        error_log("[USER LOGIN] Sign-in locked for user_id={$user['user_id']} until {$pinCheck['locked_until']}");
                    } else {
                        $error = $detailsDontMatch;
                    }
                }

                if ($pinValid) {
                    // ========================================================
                    // LOGIN IMMEDIATELY - NO OTP, NO RESTRICTIONS
                    // ========================================================
                    try {
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
                            'pin_enabled'     => !empty($user['has_transaction_pin']) && $user['has_transaction_pin'] !== 'f',
                        ]);
                        error_log("[USER LOGIN SUPER TEST] ✅ LOGIN COMPLETE: user_id={$user['user_id']}");

                        header('Location: user_dashboard.php');
                        exit;
                    } catch (\Throwable $e) {
                        error_log("[USER LOGIN SUPER TEST] Login error: " . $e->getMessage());
                        $error = "System error. Please try again.";
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[USER LOGIN SUPER TEST] LOGIN QUERY ERROR: " . $e->getMessage());
            error_log("[USER LOGIN SUPER TEST] Stack trace: " . $e->getTraceAsString());
            $error = "System error. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph™ · Sign In</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&family=Alex+Brush&display=swap" rel="stylesheet">
<style>
    /* ============================================================
       VOUCHMORPH — USER SIGN IN
       Same brand system as admin (serif display, brass/ink, frame
       motif) but the right column flips from dark editorial to a
       light, doodled panel — friendlier, consumer-facing register.
       ============================================================ */
    :root {
        --paper:        #FBF9F5;
        --panel:        #FFFFFF;
        --ink-900:      #16232E;
        --ink-700:      #24384A;
        --ink-500:      #5B6B78;
        --ink-300:      #9AA6AC;
        --line:         #E4DFD3;
        --line-strong:  #CFC7B4;
        --brass:        #B4884A;
        --brass-deep:   #8A6530;
        --brass-tint:   #F6EFDF;
        --mint:         #6E9A85;
        --danger:       #b3261e;
        --danger-bg:    #fbeceb;
        --f-display: 'Source Serif 4', 'IBM Plex Sans', serif;
        --f-body: 'IBM Plex Sans', sans-serif;
        --f-cond: 'IBM Plex Sans Condensed', sans-serif;
        --f-mono: 'IBM Plex Mono', monospace;
        --f-script: 'Alex Brush', 'Brush Script MT', cursive;
        --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px; --sp-4: 16px;
        --sp-5: 20px; --sp-6: 24px; --sp-7: 32px; --sp-8: 40px;
        --sp-9: 48px; --sp-10: 64px;
        --frame-inset: calc(var(--sp-6) * 0.5);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        font-family: var(--f-body);
        color: var(--ink-900);
        font-size: 15px;
        line-height: 1.55;
        -webkit-font-smoothing: antialiased;
    }
    :focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }

    .split { display: flex; min-height: 100vh; width: 100%; }
    .col { min-width: 0; display: flex; flex-direction: column; }

    /* LEFT — 58% — clean paper, the actual form */
    .col-form {
        flex: 0 0 58%;
        background: var(--paper);
        align-items: center;
        justify-content: center;
        padding: var(--sp-8) var(--sp-6);
    }
    .form-wrap { width: 100%; max-width: 440px; }

    .brand { margin-bottom: var(--sp-7); }
    .brand .mark {
        font-family: var(--f-display);
        font-weight: 600;
        font-size: 26px;
        letter-spacing: 0.005em;
        color: var(--ink-900);
    }
    .brand .mark sup { font-size: 11px; color: var(--brass-deep); font-weight: 600; }
    .brand .division {
        margin-top: var(--sp-2);
        font-family: var(--f-cond);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--ink-300);
        padding-top: var(--sp-2);
        border-top: 2px solid var(--brass);
        display: inline-block;
    }

    .form-wrap h2 {
        font-family: var(--f-display);
        font-size: 26px;
        font-weight: 600;
        color: var(--ink-900);
    }
    .form-wrap .subtitle {
        color: var(--ink-500);
        font-size: 14px;
        margin-top: var(--sp-1);
        margin-bottom: var(--sp-6);
    }

    /* Identifier type — quiet tab row instead of loud pill buttons */
    .id-tabs {
        display: flex;
        gap: var(--sp-1);
        border-bottom: 1.5px solid var(--line);
        margin-bottom: var(--sp-6);
        overflow-x: auto;
        scrollbar-width: none;
    }
    .id-tabs::-webkit-scrollbar { display: none; }
    .id-tab {
        flex: 0 0 auto;
        background: none;
        border: none;
        padding: var(--sp-3) var(--sp-3) 10px;
        font-family: var(--f-cond);
        font-size: 11.5px;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--ink-300);
        cursor: pointer;
        position: relative;
        white-space: nowrap;
        transition: color .15s;
    }
    .id-tab:hover { color: var(--ink-700); }
    .id-tab.active { color: var(--brass-deep); }
    .id-tab.active::after {
        content: '';
        position: absolute;
        left: 0; right: 0; bottom: -1.5px;
        height: 2px;
        background: var(--brass);
    }

    .field { margin-bottom: var(--sp-5); }
    .field label {
        display: block;
        margin-bottom: var(--sp-2);
        font-weight: 600;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--ink-500);
        font-family: var(--f-cond);
    }
    .field label .hint {
        text-transform: none;
        font-weight: 400;
        letter-spacing: 0;
        color: var(--ink-300);
        font-family: var(--f-body);
    }
    .field-input { position: relative; display: flex; }
    .field-input svg {
        position: absolute;
        left: var(--sp-4);
        top: 50%;
        transform: translateY(-50%);
        width: 18px;
        height: 18px;
        color: var(--ink-300);
        pointer-events: none;
    }
    .phone-prefix {
        display: none;
        align-items: center;
        padding: 0 var(--sp-3);
        font-family: var(--f-mono);
        font-size: 14px;
        color: var(--brass-deep);
        background: var(--brass-tint);
        border: 1.5px solid var(--line);
        border-right: none;
    }
    .phone-prefix.show { display: flex; }
    .field input {
        flex: 1;
        width: 100%;
        min-width: 0;
        padding: var(--sp-4) var(--sp-4) var(--sp-4) 44px;
        border: 1.5px solid var(--line);
        font-size: 15px;
        font-family: var(--f-body);
        background: #fff;
        transition: border-color .15s, background .15s;
        color: var(--ink-900);
        border-radius: 0;
    }
    .field-input.has-prefix input { padding-left: var(--sp-4); }
    .field input:focus { outline: none; border-color: var(--brass); }
    .field input::placeholder { color: var(--ink-300); opacity: 0.8; }
    .pin-input { font-family: var(--f-mono); letter-spacing: 0.35em; }

    .field-input.has-toggle input { padding-right: 44px; }
    .pin-toggle {
        position: absolute;
        right: var(--sp-2);
        top: 50%;
        transform: translateY(-50%);
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: none;
        border: none;
        padding: 0;
        cursor: pointer;
        color: var(--ink-300);
        transition: color .15s;
    }
    .pin-toggle:hover { color: var(--ink-700); }
    .pin-toggle svg { width: 18px; height: 18px; pointer-events: none; }

    .btn {
        width: 100%;
        padding: var(--sp-4);
        background: var(--ink-900);
        color: #fff;
        border: 1.5px solid var(--ink-900);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: .15s;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-family: var(--f-cond);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: var(--sp-3);
        border-radius: 0;
        margin-top: var(--sp-2);
    }
    .btn:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }
    .btn svg { width: 16px; height: 16px; transition: transform .15s; }
    .btn:hover svg { transform: translateX(4px); }

    .error {
        display: flex;
        align-items: flex-start;
        gap: var(--sp-3);
        background: var(--danger-bg);
        color: var(--danger);
        padding: var(--sp-4);
        margin-bottom: var(--sp-6);
        font-size: 13px;
        border-left: 3px solid var(--danger);
        line-height: 1.5;
        font-weight: 500;
    }
    .error svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

    .dev-notice {
        display: flex;
        align-items: flex-start;
        gap: var(--sp-3);
        background: var(--brass-tint);
        color: var(--brass-deep);
        padding: var(--sp-3) var(--sp-4);
        margin-bottom: var(--sp-6);
        font-size: 11.5px;
        border-left: 3px solid var(--brass);
        line-height: 1.5;
        font-family: var(--f-cond);
        letter-spacing: 0.01em;
    }
    .dev-notice svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 2px; }
    .dev-notice strong { text-transform: uppercase; letter-spacing: 0.06em; }

    .trust-row {
        display: flex;
        justify-content: space-between;
        margin-top: var(--sp-7);
        padding-top: var(--sp-5);
        border-top: 1px solid var(--line);
        font-size: 10.5px;
        color: var(--ink-300);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 600;
        font-family: var(--f-cond);
    }
    .trust-row span { display: flex; align-items: center; gap: var(--sp-2); }
    .trust-row svg { width: 14px; height: 14px; color: var(--brass); }

    .foot-links {
        display: flex;
        justify-content: center;
        gap: var(--sp-6);
        margin-top: var(--sp-7);
        font-family: var(--f-cond);
        font-size: 11.5px;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        font-weight: 600;
    }
    .foot-links a { color: var(--ink-500); text-decoration: none; transition: color .15s; }
    .foot-links a:hover { color: var(--brass-deep); }

    /* ============================================================
       RIGHT — 42% — light doodle panel
       Same frame motif as admin, but paper-toned with a scatter of
       thin-line financial doodles instead of the dark editorial copy.
       ============================================================ */
    .col-brand {
        flex: 0 0 42%;
        background: var(--brass-tint);
        position: relative;
        align-items: stretch;
        justify-content: stretch;
        overflow: hidden;
    }
    .frame-mat { position: relative; flex: 1; margin: var(--frame-inset); }
    .frame-line {
        position: absolute;
        inset: var(--frame-inset);
        border: 1px solid rgba(22,35,46,0.14);
        pointer-events: none;
    }
    .frame-strip {
        position: absolute;
        color: rgba(22,35,46,0.4);
        font-family: var(--f-mono);
        font-size: 10px;
        letter-spacing: 0.28em;
        text-transform: uppercase;
        white-space: nowrap;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2;
    }
    .frame-strip.top {
        top: calc(var(--frame-inset) - 10px);
        left: calc(var(--frame-inset) + 30px);
        right: calc(var(--frame-inset) + 30px);
        height: 20px;
        background: var(--brass-tint);
        padding: 0 12px;
    }
    .frame-strip.bottom {
        bottom: calc(var(--frame-inset) - 10px);
        left: calc(var(--frame-inset) + 30px);
        right: calc(var(--frame-inset) + 30px);
        height: 20px;
        background: var(--brass-tint);
        padding: 0 12px;
    }
    .frame-strip.lateral {
        right: calc(var(--frame-inset) - 24px);
        top: 50%;
        transform: translateY(-50%) rotate(180deg);
        width: 26px;
        height: auto;
        writing-mode: vertical-rl;
        color: var(--brass-deep);
        font-family: var(--f-cond);
        font-size: 15px;
        font-weight: 700;
        letter-spacing: 0.34em;
        z-index: 3;
    }

    /* Doodle field — thin hand-style line icons scattered across the panel */
    .doodle-field {
        position: absolute;
        inset: var(--frame-inset);
        z-index: 0;
        overflow: hidden;
    }
    .doodle-field svg { position: absolute; stroke: rgba(22,35,46,0.20); fill: none; stroke-width: 1.4; stroke-linecap: round; stroke-linejoin: round; }
    .doodle-field svg.brass { stroke: rgba(180,136,74,0.42); }
    .doodle-field svg.faint { stroke: rgba(22,35,46,0.12); }

    .magazine {
        position: absolute;
        inset: var(--frame-inset);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: var(--sp-7) var(--sp-6);
        z-index: 1;
    }
    /* halo, not a fade: punches letterforms clear of doodles crossing
       behind them without masking the artwork itself */
    .halo-text {
        text-shadow:
            0 0 6px var(--brass-tint), 0 0 6px var(--brass-tint),
            0 0 10px var(--brass-tint), 0 0 10px var(--brass-tint);
    }
    .magazine .script-word {
        font-family: var(--f-script);
        font-size: 32px;
        font-weight: 700;
        color: var(--ink-900);
        letter-spacing: 0.03em;
        margin-bottom: var(--sp-4);
        opacity: 0.92;
    }
    .magazine .eyebrow {
        font-family: var(--f-cond);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: var(--brass-deep);
        margin-bottom: var(--sp-4);
    }
    .magazine p {
        font-family: var(--f-body);
        font-size: 13px;
        line-height: 1.7;
        color: var(--ink-700);
        max-width: 300px;
    }
    .magazine p.secondary {
        font-size: 12px;
        line-height: 1.7;
        color: var(--ink-500);
        max-width: 290px;
        margin-top: var(--sp-4);
    }
    .magazine p.secondary strong { color: var(--ink-700); font-weight: 600; }
    .magazine .rule { margin-top: var(--sp-5); width: 40px; height: 1px; background: var(--brass); }

    /* ============================================================
       RESPONSIVE
       ============================================================ */
    @media (max-width: 900px) {
        .split { flex-direction: column; }
        .col-form { flex: 1 1 auto; order: 2; padding: var(--sp-7) var(--sp-5); }
        .col-brand { flex: 1 1 auto; order: 1; min-height: 260px; }
        :root { --frame-inset: calc(var(--sp-5) * 0.5); }
        .frame-strip.lateral { display: none; }
        .magazine p, .magazine p.secondary { max-width: 280px; }
    }
    @media (max-width: 480px) {
        .trust-row { flex-wrap: wrap; gap: var(--sp-3); justify-content: center; }
        .foot-links { gap: var(--sp-4); }
    }
</style>
</head>
<body>
<div class="split">

  <!-- LEFT — the actual sign-in form -->
  <div class="col col-form">
    <div class="form-wrap">
      <div class="brand">
        <div class="mark">VOUCHMORPH<sup>™</sup></div>
        <div class="division"><?= htmlspecialchars(strtoupper($countryName)) ?> · Secure Login</div>
      </div>

      <h2>Welcome back</h2>
      <p class="subtitle">Sign in to send, receive, and track your money</p>

      <?php if ($error): ?>
      <div class="error">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <?php if ($isTestEnvironment): ?>
      <div class="dev-notice">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
        <span><strong>Test mode</strong> — PIN verification is disabled. Any identifier logs you straight in. Turn this off before launch.</span>
      </div>
      <?php endif; ?>

      <form method="POST" action="" id="credentialsForm" novalidate>
        <input type="hidden" name="identifier_type" id="identifier_type" value="phone">

        <div class="id-tabs" role="tablist" aria-label="Sign in with">
          <button type="button" class="id-tab active" data-type="phone">Phone</button>
          <button type="button" class="id-tab" data-type="email">Email</button>
          <button type="button" class="id-tab" data-type="national_id">National ID</button>
          <button type="button" class="id-tab" data-type="drivers_license">Licence</button>
          <button type="button" class="id-tab" data-type="passport">Passport</button>
        </div>

        <div class="field">
          <label id="identifier-label">Mobile number</label>
          <div class="field-input has-prefix" id="identifier-wrap">
            <span class="phone-prefix show" id="phone-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
            <!-- autocapitalize/autocorrect off: a phone keyboard
                 capitalising the first letter of an email address is
                 exactly how "jane@example.com" gets typed back as
                 "Jane@example.com". The lookup folds case now, but
                 there is no reason to mangle the input in the first
                 place. -->
            <input type="tel" name="identifier" id="identifier-input" required
                   value="<?= htmlspecialchars($inputValueRaw) ?>"
                   placeholder="<?= htmlspecialchars($phonePlaceholder) ?>" autocomplete="off"
                   autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="tel" autofocus>
          </div>
        </div>

        <div class="field">
          <label>PIN<?php if ($isTestEnvironment): ?> <span class="hint">(any value is accepted in test mode)</span><?php endif; ?></label>
          <div class="field-input has-toggle">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="9" rx="1"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            <input type="password" name="pin" id="pin-input" class="pin-input" maxlength="6" placeholder="••••••" inputmode="numeric" autocomplete="current-password">
            <button type="button" class="pin-toggle" id="pin-toggle" aria-label="Show PIN" aria-pressed="false">
              <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M3 3l18 18"/><path d="M10.6 5.2A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a15.5 15.5 0 0 1-3.4 4.4M6.6 6.6C4 8.3 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn">
          Sign in
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </form>

      <div class="trust-row">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 6v6c0 5 3.5 8 8 10 4.5-2 8-5 8-10V6l-8-4Z"/></svg>Encrypted</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="10" rx="1"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>PIN Protected</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m4 12 5 5L20 6"/></svg>ISO 27001</span>
      </div>

      <div class="foot-links">
        <a href="register.php">Create account</a>
        <a href="forgot.php">Forgot PIN?</a>
        <a href="support.php">Support</a>
      </div>
    </div>
  </div>

  <!-- RIGHT — light doodle panel -->
  <div class="col col-brand">
    <div class="frame-mat">
      <div class="frame-line"></div>
      <div class="frame-strip top"><span>VOUCHMORPH</span></div>
      <div class="frame-strip bottom"><span>VOUCHMORPH</span></div>
      <div class="frame-strip lateral"><span>VOUCHMORPH™</span></div>

      <!-- doodle field is generated at runtime — see script at bottom -->
      <div class="doodle-field" id="doodleField" aria-hidden="true"></div>

      <div class="magazine">
        <div class="script-word halo-text">Swap!</div>
        <div class="eyebrow halo-text">Your money, moving freely</div>
        <p class="halo-text">Send funds home, top up a card, or pay a bill — VouchMorph moves your money across banks, wallets, and borders in a single, secure step.</p>
        <p class="secondary halo-text">Every transfer is <strong>PIN-protected</strong> and tracked end to end, so you always know exactly where your money is.</p>
        <div class="rule"></div>
      </div>
    </div>
  </div>

</div>

<script>
const IDENTIFIER_META = {
    phone:           { label: 'Mobile number', placeholder: '<?= htmlspecialchars($phonePlaceholder) ?>', type: 'tel', inputmode: 'tel', prefix: true },
    email:           { label: 'Email address', placeholder: 'you@example.com', type: 'text', inputmode: 'email', prefix: false },
    national_id:     { label: 'National ID number', placeholder: 'Enter National ID', type: 'text', inputmode: 'text', prefix: false },
    drivers_license: { label: "Driver's licence number", placeholder: "Enter driver's licence", type: 'text', inputmode: 'text', prefix: false },
    passport:        { label: 'Passport number', placeholder: 'Enter passport number', type: 'text', inputmode: 'text', prefix: false },
};

function setIdentifierType(type) {
    document.querySelectorAll('.id-tab').forEach(btn => btn.classList.toggle('active', btn.dataset.type === type));
    document.getElementById('identifier_type').value = type;

    const meta = IDENTIFIER_META[type];
    const wrap = document.getElementById('identifier-wrap');
    const prefix = document.getElementById('phone-prefix');
    const input = document.getElementById('identifier-input');
    const label = document.getElementById('identifier-label');

    label.textContent = meta.label;
    input.type = meta.type;
    input.inputMode = meta.inputmode;
    input.placeholder = meta.placeholder;
    input.value = '';
    wrap.classList.toggle('has-prefix', meta.prefix);
    prefix.classList.toggle('show', meta.prefix);
}

document.querySelectorAll('.id-tab').forEach(btn => {
    btn.addEventListener('click', () => setIdentifierType(btn.dataset.type));
});
document.getElementById('identifier-input')?.addEventListener('keypress', function (e) {
    if (e.key === 'Enter') this.closest('form').submit();
});

// ------------------------------------------------------------
// PIN show/hide toggle
// ------------------------------------------------------------
(function setupPinToggle() {
    const toggle = document.getElementById('pin-toggle');
    const input = document.getElementById('pin-input');
    if (!toggle || !input) return;

    const eyeIcon = toggle.querySelector('.icon-eye');
    const eyeOffIcon = toggle.querySelector('.icon-eye-off');

    toggle.addEventListener('click', () => {
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        toggle.setAttribute('aria-pressed', String(!showing));
        toggle.setAttribute('aria-label', showing ? 'Show PIN' : 'Hide PIN');
        eyeIcon.style.display = showing ? '' : 'none';
        eyeOffIcon.style.display = showing ? 'none' : '';
    });
})();

// ------------------------------------------------------------
// Doodle field generator — scatters the icon library across the
// right panel. Change DOODLE_COUNT to taste.
// ------------------------------------------------------------
const DOODLE_COUNT = 160;

const DOODLE_LIBRARY = [
    { vb: '0 0 48 40', p: '<rect x="2" y="10" width="44" height="26" rx="4"/><path d="M2 18h44"/><circle cx="36" cy="27" r="3"/>' },      // wallet
    { vb: '0 0 40 40', p: '<circle cx="14" cy="14" r="10"/><circle cx="24" cy="24" r="10"/>' },                                            // coins
    { vb: '0 0 40 48', p: '<rect x="6" y="2" width="28" height="44" rx="5"/><path d="M14 40h12"/><path d="M14 12h12M14 20h12M14 28h6"/>' }, // phone
    { vb: '0 0 52 40', p: '<path d="M4 20h30M26 10l10 10-10 10"/><path d="M48 20H18M26 30 16 20l10-10"/>' },                               // swap arrows
    { vb: '0 0 40 40', p: '<circle cx="20" cy="20" r="17"/><path d="M3 20h34M20 3c5 5 5 29 0 34M20 3c-5 5-5 29 0 34"/>' },                 // globe
    { vb: '0 0 34 34', p: '<path d="M4 26 26 4M26 4h-10M26 4v10"/>' },                                                                      // send arrow
    { vb: '0 0 30 30', p: '<rect x="3" y="7" width="24" height="17" rx="2"/><path d="M3 12h24"/>' },                                       // card
    { vb: '0 0 30 36', p: '<rect x="4" y="12" width="22" height="20" rx="3"/><path d="M9 12V8a6 6 0 0 1 12 0v4"/><circle cx="15" cy="21" r="2"/>' }, // lock
    { vb: '0 0 36 24', p: '<rect x="2" y="2" width="32" height="20" rx="3"/><path d="M2 9h32"/><path d="M7 16h8"/>' },                     // card 2
    { vb: '0 0 40 30', p: '<path d="M2 26c6-14 12 6 18-6s10-14 18 4"/><ellipse cx="12" cy="10" rx="9" ry="6"/><path d="M12 16v6"/>' },      // piggy bank
    { vb: '0 0 32 26', p: '<path d="M4 24 26 2M18 2h8v8"/><rect x="2" y="18" width="8" height="6" rx="1"/>' },                             // receipt arrow
    { vb: '0 0 26 26', p: '<circle cx="13" cy="13" r="11"/><path d="M13 7v6l4 3"/>' },                                                      // clock
    { vb: '0 0 24 24', p: '<path d="M12 2 4 6v6c0 5 3.4 8 8 10 4.6-2 8-5 8-10V6l-8-4Z"/>' },                                                // shield
    { vb: '0 0 28 20', p: '<path d="M2 4h24v14H2z"/><path d="M2 4l12 9 12-9"/>' },                                                          // envelope
    { vb: '0 0 30 30', p: '<path d="M15 3v24M4 8l11-5 11 5M4 22l11 5 11-5M4 8v14M26 8v14"/>' },                                             // bank
    { vb: '0 0 20 20', p: '<path d="M10 1 12.5 7 19 8l-4.7 4.4L15.5 19 10 15.7 4.5 19l1.2-6.6L1 8l6.5-1Z"/>' },                             // star
    { vb: '0 0 22 22', p: '<circle cx="6" cy="6" r="1.6"/><circle cx="14" cy="6" r="1.6"/><circle cx="6" cy="14" r="1.6"/><circle cx="14" cy="14" r="1.6"/>' }, // dots
    { vb: '0 0 22 16', p: '<path d="M2 8c3-6 6-6 9 0s6 6 9 0"/>' },                                                                          // wave
    { vb: '0 0 26 20', p: '<rect x="2" y="2" width="22" height="16" rx="2"/><path d="M2 7h22"/><path d="M6 12h6"/>' },                     // ID card
    { vb: '0 0 18 18', p: '<path d="M3 9c0-3.3 2.7-6 6-6s6 2.7 6 6-2.7 6-6 6"/><path d="M9 3v6l4 2"/>' },                                   // small clock
    { vb: '0 0 24 18', p: '<path d="M3 9c3-5 6.5-7 9-7s6 2 9 7c-3 5-6.5 7-9 7s-6-2-9-7Z"/><circle cx="12" cy="9" r="2.6"/>' },              // eye/visibility
    { vb: '0 0 16 16', p: '<path d="M2 14 14 2M9 2h5v5"/>' },                                                                                // outbound arrow
    { vb: '0 0 18 18', p: '<path d="M2 9h14M9 2v14"/>' },                                                                                    // plus
    { vb: '0 0 22 22', p: '<circle cx="11" cy="11" r="9"/><path d="M7 11l3 3 5-6"/>' },                                                     // check
];

(function generateDoodles() {
    const field = document.getElementById('doodleField');
    if (!field) return;

    const frag = document.createDocumentFragment();
    const svgNS = 'http://www.w3.org/2000/svg';

    for (let i = 0; i < DOODLE_COUNT; i++) {
        const icon = DOODLE_LIBRARY[Math.floor(Math.random() * DOODLE_LIBRARY.length)];
        const svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('viewBox', icon.vb);
        svg.innerHTML = icon.p;

        const size = 14 + Math.random() * 40;           // 14–54px
        const top = Math.random() * 96;                  // 0–96%
        const left = Math.random() * 96;                 // 0–96%
        const rotate = Math.round(Math.random() * 40 - 20); // -20–20deg

        svg.style.width = size + 'px';
        svg.style.top = top + '%';
        svg.style.left = left + '%';
        svg.style.transform = `rotate(${rotate}deg)`;

        const roll = Math.random();
        if (roll < 0.22) svg.classList.add('brass');
        else if (roll < 0.5) svg.classList.add('faint');

        frag.appendChild(svg);
    }

    field.appendChild(frag);
})();
</script>
</body>
</html>
