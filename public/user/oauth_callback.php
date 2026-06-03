<?php
// user/oauth_callback.php - Handles OAuth callback from bank

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Infrastructure/Banks/GenericBankClient.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Infrastructure\Banks\GenericBankClient;

SessionManager::start();

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

// Verify state to prevent CSRF
$savedState = $_SESSION['oauth_state'] ?? '';
if ($state !== $savedState) {
    die('Invalid state parameter');
}

if ($error) {
    die('Authorization failed: ' . $error);
}

if (!$code) {
    die('No authorization code received');
}

$institution = $_SESSION['oauth_institution'] ?? $_GET['institution'] ?? '';
if (!$institution) {
    die('No institution specified');
}

// Load participant config
$config = LoadCountry::getConfig();
$participants = $config['participants'] ?? [];
$participant = $participants[$institution] ?? null;

if (!$participant) {
    die('Institution not found: ' . $institution);
}

try {
    $bankClient = new GenericBankClient($participant);
    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/user/oauth_callback.php';
    
    // Exchange code for tokens
    $tokenData = $bankClient->exchangeCodeForToken($code, $redirectUri);
    
    // Get user info and accounts
    $userInfo = $bankClient->getUserInfo($tokenData['access_token']);
    
    // Store bank connection in database
    $dbConfig = $config['db']['swap'] ?? null;
    $db = DBConnection::getInstance($dbConfig);
    
    $userId = SessionManager::getUserId();
    
    $stmt = $db->prepare("
        INSERT INTO user_bank_connections 
        (user_id, institution_code, institution_name, access_token, refresh_token, token_expires_at, 
         bank_user_id, bank_accounts, status)
        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?, 'ACTIVE')
    ");
    
    $stmt->execute([
        $userId,
        $institution,
        $participant['name'] ?? $institution,
        $tokenData['access_token'],
        $tokenData['refresh_token'],
        $tokenData['expires_in'],
        $userInfo['user_id'] ?? null,
        json_encode($userInfo['accounts'] ?? [])
    ]);
    
    // Redirect back to dashboard with success
    header('Location: user_dashboard.php?source_linked=' . urlencode($institution));
    
} catch (Exception $e) {
    error_log("OAuth callback error: " . $e->getMessage());
    header('Location: user_dashboard.php?error=' . urlencode($e->getMessage()));
}
