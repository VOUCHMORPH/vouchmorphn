<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

define('PROJECT_ROOT', dirname(__DIR__, 2));

echo "<h1>🔍 Pinpoint Login Issue</h1>";

// Load configuration
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
require_once $configPath;
$config = \Core\Config\LoadCountry::getConfig();

// Database connection
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

if (isset($config['db']['swap'])) {
    $dbConfig = $config['db']['swap'];
} else {
    $databaseUrl = getenv('DATABASE_URL');
    $db = parse_url($databaseUrl);
    $dbConfig = [
        'host' => $db['host'] ?? 'localhost',
        'port' => (int)($db['port'] ?? 5432),
        'database' => ltrim($db['path'] ?? '', '/'),
        'username' => $db['user'] ?? 'postgres',
        'password' => $db['pass'] ?? '',
    ];
}

$dbConfig['type'] = 'pgsql';
$db = DBConnection::getInstance($dbConfig);

// Load SessionManager
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

echo "<h2>Testing Login Step by Step</h2>";

$testUsername = 'global_admin';
$testPassword = 'Admin@123456';
$testCountry = 'BW';

echo "<h3>Test Credentials:</h3>";
echo "Username: {$testUsername}<br>";
echo "Password: {$testPassword}<br>";
echo "Country: {$testCountry}<br><br>";

// STEP 1: Find user
echo "<h3>STEP 1: Find user in database</h3>";
$stmt = $db->prepare("
    SELECT 
        admin_id, 
        username, 
        email, 
        password_hash, 
        role_id, 
        mfa_enabled,
        mfa_secret,
        full_name,
        country_code,
        deleted_at
    FROM admins 
    WHERE (username = :identifier OR email = :identifier)
        AND deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([':identifier' => $testUsername]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die("<span style='color:red'>✗ User not found!</span>");
}
echo "<span style='color:green'>✓ User found: {$admin['username']}</span><br>";
echo "Admin ID: {$admin['admin_id']}<br>";
echo "Role ID: {$admin['role_id']}<br>";
echo "Country Code: {$admin['country_code']}<br>";

// STEP 2: Verify password
echo "<h3>STEP 2: Password verification</h3>";
$passwordValid = password_verify($testPassword, $admin['password_hash']);
if (!$passwordValid) {
    die("<span style='color:red'>✗ Password verification failed!</span>");
}
echo "<span style='color:green'>✓ Password verified successfully</span><br>";

// STEP 3: Check deleted_at
echo "<h3>STEP 3: Check if account is deleted</h3>";
if ($admin['deleted_at'] !== null) {
    die("<span style='color:red'>✗ Account is deleted!</span>");
}
echo "<span style='color:green'>✓ Account is active</span><br>";

// STEP 4: Check country access
echo "<h3>STEP 4: Country access check</h3>";
$isSuperAdmin = ($admin['role_id'] == 999);
$hasCountryRestriction = !empty($admin['country_code']);
$countryMatches = ($admin['country_code'] === $testCountry);

echo "Is Super Admin: " . ($isSuperAdmin ? 'Yes' : 'No') . "<br>";
echo "Has Country Restriction: " . ($hasCountryRestriction ? 'Yes' : 'No') . "<br>";
echo "Country Matches: " . ($countryMatches ? 'Yes' : 'No') . "<br>";

if (!$isSuperAdmin && $hasCountryRestriction && !$countryMatches) {
    die("<span style='color:red'>✗ Country access denied!</span>");
}
echo "<span style='color:green'>✓ Country access granted</span><br>";

// STEP 5: Update last login (the suspected culprit)
echo "<h3>STEP 5: Update last login (TESTING - will be rolled back)</h3>";

// First, check if columns exist
$checkColumns = $db->query("
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'admins' 
    AND column_name IN ('last_login_at', 'last_login_ip')
");
$existingColumns = $checkColumns->fetchAll(PDO::FETCH_COLUMN);
echo "Existing columns: " . implode(', ', $existingColumns) . "<br>";

if (in_array('last_login_at', $existingColumns) && in_array('last_login_ip', $existingColumns)) {
    echo "Attempting to update last_login...<br>";
    
    // Start transaction to test without committing
    $db->beginTransaction();
    
    try {
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $updateStmt = $db->prepare("
            UPDATE admins 
            SET last_login_at = NOW(), 
                last_login_ip = :ip 
            WHERE admin_id = :admin_id
        ");
        $updateResult = $updateStmt->execute([
            ':ip' => $ipAddress,
            ':admin_id' => $admin['admin_id']
        ]);
        
        if ($updateResult) {
            echo "<span style='color:green'>✓ Last login update successful</span><br>";
        } else {
            echo "<span style='color:orange'>⚠ Last login update returned false but no exception</span><br>";
        }
        
        // Rollback to not affect real data
        $db->rollBack();
        echo "<span style='color:blue'>ℹ Changes rolled back (test only)</span><br>";
        
    } catch (Throwable $e) {
        $db->rollBack();
        echo "<span style='color:red'>✗ Last login update FAILED: " . $e->getMessage() . "</span><br>";
        echo "This is likely the problem! The login is failing because this update is throwing an exception.<br>";
    }
} else {
    echo "<span style='color:orange'>⚠ Columns 'last_login_at' and/or 'last_login_ip' don't exist</span><br>";
    echo "This would cause an error when trying to update them.<br>";
}

// STEP 6: Session storage
echo "<h3>STEP 6: Session storage test</h3>";
try {
    // Clear existing
    SessionManager::remove('admin_id');
    SessionManager::remove('admin_logged_in');
    
    // Set test values
    SessionManager::set('admin_id', (int)$admin['admin_id']);
    SessionManager::set('admin_username', $admin['username']);
    SessionManager::set('admin_role_id', (int)$admin['role_id']);
    SessionManager::set('admin_country', $testCountry);
    SessionManager::set('admin_logged_in', true);
    
    // Verify they were set
    $retrievedId = SessionManager::get('admin_id');
    $retrievedLoggedIn = SessionManager::get('admin_logged_in');
    
    if ($retrievedId == $admin['admin_id'] && $retrievedLoggedIn === true) {
        echo "<span style='color:green'>✓ Session storage working</span><br>";
    } else {
        echo "<span style='color:red'>✗ Session storage failed!</span><br>";
        echo "Retrieved ID: " . var_export($retrievedId, true) . "<br>";
        echo "Retrieved LoggedIn: " . var_export($retrievedLoggedIn, true) . "<br>";
    }
} catch (Throwable $e) {
    echo "<span style='color:red'>✗ Session error: " . $e->getMessage() . "</span><br>";
}

// STEP 7: Test MFA condition
echo "<h3>STEP 7: MFA check</h3>";
$mfaEnabled = ($admin['mfa_enabled'] === 't' || $admin['mfa_enabled'] === true || $admin['mfa_enabled'] === 1);
echo "MFA Enabled: " . ($mfaEnabled ? 'Yes' : 'No') . "<br>";
echo "MFA Secret: " . (empty($admin['mfa_secret']) ? 'Not set' : 'Set') . "<br>";

// FINAL: Attempt full login and capture any exception
echo "<h3>STEP 8: Full login attempt with detailed exception</h3>";

// Load AdminAuth
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';
use Application\Admin\Auth\AdminAuth;

$auth = new AdminAuth($db);

try {
    $result = $auth->login($testUsername, $testPassword, $testCountry);
    echo "<pre>";
    echo "Login result:\n";
    print_r($result);
    echo "</pre>";
    
    if ($result['success']) {
        echo "<span style='color:green'>✓ LOGIN SUCCESSFUL!</span><br>";
    } else {
        echo "<span style='color:red'>✗ LOGIN FAILED: " . $result['message'] . "</span><br>";
    }
} catch (Throwable $e) {
    echo "<span style='color:red'>✗ EXCEPTION CAUGHT: " . $e->getMessage() . "</span><br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Show current session
echo "<h3>Current Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Suggested fixes based on findings
echo "<h2>🔧 Suggested Fixes</h2>";

// Check if last_login columns exist
$missingColumns = [];
if (!in_array('last_login_at', $existingColumns)) $missingColumns[] = 'last_login_at';
if (!in_array('last_login_ip', $existingColumns)) $missingColumns[] = 'last_login_ip';

if (!empty($missingColumns)) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffc107; padding: 15px; margin: 10px 0;'>";
    echo "<strong>⚠ Missing columns detected!</strong><br>";
    echo "Run this SQL to add them:<br>";
    echo "<code style='background: #f5f5f5; display: block; padding: 10px; margin-top: 10px;'>";
    foreach ($missingColumns as $col) {
        if ($col === 'last_login_at') {
            echo "ALTER TABLE admins ADD COLUMN last_login_at TIMESTAMP NULL;\n";
        } elseif ($col === 'last_login_ip') {
            echo "ALTER TABLE admins ADD COLUMN last_login_ip VARCHAR(100) NULL;\n";
        }
    }
    echo "</code>";
    echo "</div>";
}

echo "<div style='background: #d4edda; border: 1px solid #28a745; padding: 15px; margin: 10px 0;'>";
echo "<strong>✅ Quick Fix for AdminAuth.php</strong><br>";
echo "Wrap the last_login update in a try-catch block to prevent it from breaking login:<br>";
echo "<code style='background: #f5f5f5; display: block; padding: 10px; margin-top: 10px;'>
try {
    \$updateStmt = \$this->db->prepare(\"
        UPDATE admins 
        SET last_login_at = NOW(), 
            last_login_ip = :ip 
        WHERE admin_id = :admin_id
    \");
    \$updateStmt->execute([
        ':ip' => \$ipAddress,
        ':admin_id' => \$admin['admin_id']
    ]);
} catch (\\Throwable \$e) {
    error_log(\"Last login update skipped: \" . \$e->getMessage());
    // Don't fail the login
}
</code>";
echo "</div>";
