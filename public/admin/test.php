<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

define('PROJECT_ROOT', dirname(__DIR__, 2));

echo "<h1>🔐 Complete Admin Login Debug</h1>";

// Step 1: Load configuration
echo "<h2>Step 1: Loading Configuration</h2>";
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
echo "Config path: " . $configPath . "<br>";
echo "File exists: " . (file_exists($configPath) ? 'YES' : 'NO') . "<br>";

if (!file_exists($configPath)) {
    die("Configuration not found!");
}

require_once $configPath;

try {
    $config = \Core\Config\LoadCountry::getConfig();
    echo "<span style='color:green'>✓ Configuration loaded</span><br>";
} catch (Throwable $e) {
    die("Config error: " . $e->getMessage());
}

// Step 2: Database connection
echo "<h2>Step 2: Database Connection</h2>";
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// Get database config
if (isset($config['db']['swap'])) {
    $dbConfig = $config['db']['swap'];
    echo "Using config['db']['swap']<br>";
} else {
    $databaseUrl = getenv('DATABASE_URL');
    if ($databaseUrl) {
        $db = parse_url($databaseUrl);
        $dbConfig = [
            'host' => $db['host'] ?? 'localhost',
            'port' => (int)($db['port'] ?? 5432),
            'database' => ltrim($db['path'] ?? '', '/'),
            'username' => $db['user'] ?? 'postgres',
            'password' => $db['pass'] ?? '',
        ];
        echo "Using DATABASE_URL from environment<br>";
    } else {
        $dbConfig = [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'port' => (int)(getenv('DB_PORT') ?: 5432),
            'database' => getenv('DB_NAME') ?: 'swap_system_bw',
            'username' => getenv('DB_USER') ?: 'postgres',
            'password' => getenv('DB_PASSWORD') ?: '',
        ];
        echo "Using individual DB environment variables<br>";
    }
}

try {
    $dbConfig['type'] = 'pgsql';
    $db = DBConnection::getInstance($dbConfig);
    
    // Test connection
    $stmt = $db->query("SELECT 1 as test");
    $result = $stmt->fetch();
    echo "<span style='color:green'>✓ Database connected successfully</span><br>";
} catch (Throwable $e) {
    die("<span style='color:red'>✗ Database connection failed: " . $e->getMessage() . "</span>");
}

// Step 3: Check admins table
echo "<h2>Step 3: Admin Table Check</h2>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM admins");
    $count = $stmt->fetchColumn();
    echo "Total admins in database: <strong>{$count}</strong><br>";
} catch (Throwable $e) {
    echo "<span style='color:red'>Error: " . $e->getMessage() . "</span><br>";
}

// Step 4: Display all admins with hash details
echo "<h2>Step 4: Admin Accounts</h2>";
echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
echo "<tr style='background: #001B44; color: white;'>";
echo "<th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Country</th><th>Hash Length</th><th>Starts With</th><th>Status</th>";
echo "</tr>";

$stmt = $db->query("SELECT admin_id, username, email, role_id, country_code, password_hash FROM admins WHERE deleted_at IS NULL ORDER BY admin_id");
$admins = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $admins[] = $row;
    $hashLen = strlen($row['password_hash']);
    $startsWith = substr($row['password_hash'], 0, 7);
    $isValid = ($hashLen === 60 && $startsWith === '$2y$12$');
    
    echo "<tr>";
    echo "<td>{$row['admin_id']}</td>";
    echo "<td><strong>{$row['username']}</strong></td>";
    echo "<td>{$row['email']}</td>";
    echo "<td>{$row['role_id']}</td>";
    echo "<td>{$row['country_code']}</td>";
    echo "<td style='color: " . ($hashLen === 60 ? 'green' : 'red') . ";'>{$hashLen}</td>";
    echo "<td>{$startsWith}</td>";
    echo "<td style='color: " . ($isValid ? 'green' : 'red') . ";'>" . ($isValid ? 'VALID' : 'INVALID') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Step 5: Test login with provided credentials
echo "<h2>Step 5: Test Login Function</h2>";

// Load AdminAuth class
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';
use Application\Admin\Auth\AdminAuth;

$auth = new AdminAuth($db);

// Test each admin
foreach ($admins as $admin) {
    // Try common passwords
    $testPasswords = [
        'Admin@123456',
        'Regulator@123', 
        'Compliance@123',
        'Auditor@123',
        'password123',
        'admin123'
    ];
    
    echo "<h3>Testing: {$admin['username']}</h3>";
    echo "Stored hash length: " . strlen($admin['password_hash']) . "<br>";
    
    foreach ($testPasswords as $testPassword) {
        $result = password_verify($testPassword, $admin['password_hash']);
        if ($result) {
            echo "<span style='color:green'>✓ MATCH FOUND! Password '{$testPassword}' works for {$admin['username']}</span><br>";
            
            // Test full login
            $loginResult = $auth->login($admin['username'], $testPassword, 'BW');
            echo "Full login result: " . ($loginResult['success'] ? 'SUCCESS' : 'FAILED') . "<br>";
            echo "Message: " . $loginResult['message'] . "<br>";
            break;
        }
    }
    
    // Also test if the stored hash itself is corrupt
    if (strlen($admin['password_hash']) !== 60) {
        echo "<span style='color:orange'>⚠ Hash length is " . strlen($admin['password_hash']) . " (should be 60) - Hash is truncated!</span><br>";
        
        // Generate correct hash for this admin
        if ($admin['username'] === 'global_admin') {
            $correctPassword = 'Admin@123456';
            $newHash = password_hash($correctPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            echo "Correct hash for {$admin['username']} should be: <code>" . htmlspecialchars($newHash) . "</code><br>";
            echo "<form method='POST' style='display:inline;'>";
            echo "<input type='hidden' name='fix_username' value='{$admin['username']}'>";
            echo "<input type='hidden' name='fix_hash' value='{$newHash}'>";
            echo "<button type='submit' name='fix_password' value='1'>Fix {$admin['username']} Password</button>";
            echo "</form><br>";
        }
    }
}

// Step 6: Handle password fix
if (isset($_POST['fix_password']) && isset($_POST['fix_username']) && isset($_POST['fix_hash'])) {
    echo "<h2>Step 6: Fixing Password</h2>";
    $fixUsername = $_POST['fix_username'];
    $fixHash = $_POST['fix_hash'];
    
    $stmt = $db->prepare("UPDATE admins SET password_hash = :hash, updated_at = NOW() WHERE username = :username");
    $result = $stmt->execute([':hash' => $fixHash, ':username' => $fixUsername]);
    
    if ($result) {
        echo "<span style='color:green'>✓ Password hash updated for {$fixUsername}</span><br>";
        
        // Verify
        $verifyStmt = $db->prepare("SELECT password_hash FROM admins WHERE username = :username");
        $verifyStmt->execute([':username' => $fixUsername]);
        $newStoredHash = $verifyStmt->fetchColumn();
        
        if (strlen($newStoredHash) === 60) {
            echo "<span style='color:green'>✓ New hash length is correct (60 characters)</span><br>";
        }
    } else {
        echo "<span style='color:red'>✗ Failed to update</span><br>";
    }
}

// Step 7: Direct SQL update option
echo "<h2>Step 7: Direct SQL Fix (Run this in Railway Console)</h2>";
echo "<pre style='background: #f5f5f5; padding: 15px; overflow-x: auto;'>";
echo "-- Run these SQL commands to fix all passwords:\n\n";
foreach ($admins as $admin) {
    if ($admin['username'] === 'global_admin') {
        $newHash = password_hash('Admin@123456', PASSWORD_BCRYPT, ['cost' => 12]);
        echo "UPDATE admins SET password_hash = '{$newHash}' WHERE username = '{$admin['username']}';\n";
    } elseif ($admin['username'] === 'regulator_bob') {
        $newHash = password_hash('Regulator@123', PASSWORD_BCRYPT, ['cost' => 12]);
        echo "UPDATE admins SET password_hash = '{$newHash}' WHERE username = '{$admin['username']}';\n";
    } elseif ($admin['username'] === 'compliance_officer') {
        $newHash = password_hash('Compliance@123', PASSWORD_BCRYPT, ['cost' => 12]);
        echo "UPDATE admins SET password_hash = '{$newHash}' WHERE username = '{$admin['username']}';\n";
    } elseif ($admin['username'] === 'auditor') {
        $newHash = password_hash('Auditor@123', PASSWORD_BCRYPT, ['cost' => 12]);
        echo "UPDATE admins SET password_hash = '{$newHash}' WHERE username = '{$admin['username']}';\n";
    }
}
echo "\n-- Then verify:\n";
echo "SELECT username, LENGTH(password_hash) FROM admins;\n";
echo "</pre>";

// Step 8: Test form
echo "<h2>Step 8: Manual Login Test</h2>";
echo "<form method='POST'>";
echo "<input type='text' name='test_username' placeholder='Username' style='padding: 8px; margin: 5px; width: 200px;'>";
echo "<input type='password' name='test_password' placeholder='Password' style='padding: 8px; margin: 5px; width: 200px;'>";
echo "<button type='submit' name='test_login' style='padding: 8px 16px; background: #001B44; color: white; border: none; cursor: pointer;'>Test Login</button>";
echo "</form>";

if (isset($_POST['test_login'])) {
    $testUser = $_POST['test_username'];
    $testPass = $_POST['test_password'];
    
    echo "<h3>Test Result for {$testUser}:</h3>";
    
    $result = $auth->login($testUser, $testPass, 'BW');
    echo "<pre>";
    print_r($result);
    echo "</pre>";
}

// Step 9: Session debug
echo "<h2>Step 9: Session Data</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";
