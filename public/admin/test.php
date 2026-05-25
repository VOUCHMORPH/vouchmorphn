<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load configuration
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
require_once $configPath;
$config = \Core\Config\LoadCountry::getConfig();

// Load database connection
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

echo "<h1>Reset All Admin Passwords</h1>";

// Define new passwords
$credentials = [
    'global_admin' => [
        'password' => 'Admin@123456',
        'email' => 'admin@vouchmorph.co.bw',
        'full_name' => 'Global System Owner',
        'role_id' => 999,
        'country_code' => 'BW'
    ],
    'regulator_bob' => [
        'password' => 'Regulator@123',
        'email' => 'regulator@bankofbotswana.bw',
        'full_name' => 'Bank of Botswana Regulator',
        'role_id' => 3,
        'country_code' => 'BW'
    ],
    'compliance_officer' => [
        'password' => 'Compliance@123',
        'email' => 'compliance@vouchmorph.co.bw',
        'full_name' => 'Compliance Officer',
        'role_id' => 4,
        'country_code' => 'BW'
    ],
    'auditor' => [
        'password' => 'Auditor@123',
        'email' => 'auditor@vouchmorph.co.bw',
        'full_name' => 'Audit Officer',
        'role_id' => 5,
        'country_code' => 'BW'
    ]
];

echo "<pre>";

foreach ($credentials as $username => $data) {
    // Generate proper bcrypt hash
    $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    
    echo "\n========================================\n";
    echo "Username: {$username}\n";
    echo "Password: {$data['password']}\n";
    echo "New Hash: {$hash}\n";
    echo "Hash Length: " . strlen($hash) . " characters\n";
    
    // Check if user exists
    $checkStmt = $db->prepare("SELECT admin_id FROM admins WHERE username = :username");
    $checkStmt->execute([':username' => $username]);
    $exists = $checkStmt->fetch();
    
    if ($exists) {
        // Update existing
        $stmt = $db->prepare("
            UPDATE admins 
            SET password_hash = :hash, 
                email = :email, 
                full_name = :full_name,
                updated_at = NOW()
            WHERE username = :username
        ");
        $stmt->execute([
            ':hash' => $hash,
            ':email' => $data['email'],
            ':full_name' => $data['full_name'],
            ':username' => $username
        ]);
        echo "✓ Updated existing user: {$username}\n";
    } else {
        // Insert new
        $stmt = $db->prepare("
            INSERT INTO admins (username, email, password_hash, role_id, full_name, country_code, created_at, updated_at)
            VALUES (:username, :email, :hash, :role_id, :full_name, :country_code, NOW(), NOW())
        ");
        $stmt->execute([
            ':username' => $username,
            ':email' => $data['email'],
            ':hash' => $hash,
            ':role_id' => $data['role_id'],
            ':full_name' => $data['full_name'],
            ':country_code' => $data['country_code']
        ]);
        echo "✓ Inserted new user: {$username}\n";
    }
    
    // Verify the update
    $verifyStmt = $db->prepare("SELECT password_hash FROM admins WHERE username = :username");
    $verifyStmt->execute([':username' => $username]);
    $storedHash = $verifyStmt->fetchColumn();
    
    if (password_verify($data['password'], $storedHash)) {
        echo "✓ VERIFICATION PASSED for {$username}\n";
    } else {
        echo "✗ VERIFICATION FAILED for {$username}\n";
    }
}

echo "\n========================================\n";
echo "All passwords have been reset!\n";
echo "========================================\n";

echo "</pre>";

echo "<h2>✅ Login Credentials (use these):</h2>";
echo "<ul>";
foreach ($credentials as $username => $data) {
    echo "<li><strong>{$username}</strong> / <code>{$data['password']}</code></li>";
}
echo "</ul>";

echo "<p><a href='admin_login.php'>Go to Login Page →</a></p>";
