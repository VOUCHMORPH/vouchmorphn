<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load configuration
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
require_once $configPath;
$config = \Core\Config\LoadCountry::getConfig();

// Load classes
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;

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
$auth = new AdminAuth($db);

$result = null;
$testPassword = 'Admin@123456';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $country = $_POST['country'] ?? 'BW';
    
    $result = $auth->login($username, $password, $country);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Admin Login</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #001B44; color: #fff; }
        .container { max-width: 500px; margin: 0 auto; background: #fff; color: #001B44; padding: 30px; }
        input, button { width: 100%; padding: 10px; margin: 10px 0; }
        button { background: #001B44; color: #fff; cursor: pointer; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Test Admin Login</h1>
        
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <input type="text" name="country" placeholder="Country (BW)" value="BW">
            <button type="submit">Login</button>
        </form>
        
        <?php if ($result !== null): ?>
            <hr>
            <h3>Result:</h3>
            <pre>
Success: <?php echo $result['success'] ? 'YES' : 'NO'; ?>
Message: <?php echo $result['message']; ?>
<?php if ($result['success'] && isset($result['admin_id'])): ?>
Admin ID: <?php echo $result['admin_id']; ?>
<?php endif; ?>
            </pre>
        <?php endif; ?>
        
        <hr>
        <h3>Default Credentials (after reset):</h3>
        <ul>
            <li><strong>global_admin</strong> / Admin@123456</li>
            <li><strong>regulator_bob</strong> / Regulator@123</li>
            <li><strong>compliance_officer</strong> / Compliance@123</li>
            <li><strong>auditor</strong> / Auditor@123</li>
        </ul>
        
        <p><a href="reset_password.php">Click here to reset passwords first</a></p>
    </div>
</body>
</html>
