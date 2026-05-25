<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load configuration
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
require_once $configPath;
$config = \Core\Config\LoadCountry::getConfig();

// Database connection
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// Get database config
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

echo "<h1>Reset Admin Passwords</h1>";

// Set new passwords
$passwords = [
    'global_admin' => 'Admin@123456',
    'regulator_bob' => 'Regulator@123',
    'compliance_officer' => 'Compliance@123',
    'auditor' => 'Auditor@123'
];

echo "<pre>";

foreach ($passwords as $username => $plainPassword) {
    // Generate proper bcrypt hash
    $newHash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    
    echo "Username: {$username}\n";
    echo "New Password: {$plainPassword}\n";
    echo "New Hash Length: " . strlen($newHash) . " characters\n";
    echo "New Hash: {$newHash}\n";
    echo "---\n";
    
    // Update database
    $stmt = $db->prepare("UPDATE admins SET password_hash = :hash WHERE username = :username");
    $stmt->execute([':hash' => $newHash, ':username' => $username]);
    
    // Verify the update
    $verifyStmt = $db->prepare("SELECT password_hash FROM admins WHERE username = :username");
    $verifyStmt->execute([':username' => $username]);
    $storedHash = $verifyStmt->fetchColumn();
    
    if (password_verify($plainPassword, $storedHash)) {
        echo "✓ Password for {$username} updated and verified successfully!\n\n";
    } else {
        echo "✗ Failed to update password for {$username}\n\n";
    }
}

echo "</pre>";

echo "<h2>Login Credentials</h2>";
echo "<ul>";
echo "<li><strong>global_admin</strong> - Password: Admin@123456</li>";
echo "<li><strong>regulator_bob</strong> - Password: Regulator@123</li>";
echo "<li><strong>compliance_officer</strong> - Password: Compliance@123</li>";
echo "<li><strong>auditor</strong> - Password: Auditor@123</li>";
echo "</ul>";

echo "<p><a href='admin_login.php'>Go to Login Page →</a></p>";
