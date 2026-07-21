<?php
/**
 * OAuth callback handler for source registration
 * GET /api/v1/user/source_oauth_callback.php?state=xxx&code=xxx
 * 
 * Response: HTML page with success/error message
 */
require_once __DIR__ . '/../../../../vendor/autoload.php';

// Core
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../src/Core/Config/LoadCountry.php';

// Domain Services
require_once __DIR__ . '/../../../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/Settlement/HybridSettlementStrategy.php';
require_once __DIR__ . '/../../../../src/Domain/Services/FeeService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/ForexService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/CardService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/ContributionCalculator.php';
require_once __DIR__ . '/../../../../src/Domain/Services/MultiSourceFeeCalculator.php';
require_once __DIR__ . '/../../../../src/Domain/Services/MultiSource/MultiSourceSwapOrchestrator.php';

// Infrastructure
require_once __DIR__ . '/../../../../src/Infrastructure/Adapters/InstitutionAdapterFactory.php';
require_once __DIR__ . '/../../../../src/Infrastructure/Crypto/MessageSigner.php';
require_once __DIR__ . '/../../../../src/Infrastructure/Crypto/SignatureVerifier.php';
require_once __DIR__ . '/../../../../src/Infrastructure/Crypto/CertificateManager.php';
require_once __DIR__ . '/../../../../src/Infrastructure/Crypto/AggregateSigner.php';
require_once __DIR__ . '/../../../../src/Infrastructure/SMS/SmsNotificationService.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

try {
    // ============================================================
    // FIX: Use SessionManager for authentication
    // ============================================================
    SessionManager::start();
    
    if (!SessionManager::isLoggedIn()) {
        throw new RuntimeException("You must be logged in to complete source registration.");
    }
    
    $userData = SessionManager::getUser();
    $userId = $userData['id'] ?? $userData['user_id'] ?? null;
    
    if (empty($userId)) {
        throw new RuntimeException("Session has no user id.");
    }
    
    // Get query parameters
    $state = $_GET['state'] ?? null;
    $code = $_GET['code'] ?? null;
    $error = $_GET['error'] ?? null;
    
    if ($error) {
        throw new RuntimeException("Bank authorization failed: " . $error);
    }
    
    if (empty($state) || empty($code)) {
        throw new RuntimeException("Missing required parameters: state, code");
    }
    
    // ============================================================
    // FIX: Initialize SwapService with required parameters
    // ============================================================
    $db = DBConnection::getConnection();
    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $config = LoadCountry::getConfig();
    
    $swapService = new SwapService($db, $config, $country);
    
    // Complete registration
    $result = $swapService->completeUserSourceRegistrationByState($state, $code);
    
    // Return success page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Source Added Successfully</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .success { color: #28a745; }
            .container { max-width: 500px; margin: 0 auto; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1 class="success">✓ Source Verified!</h1>
            <p>Your <?php echo htmlspecialchars($result['institution'] ?? 'bank'); ?> account has been successfully added as a source.</p>
            <p>You can now use this source for swaps.</p>
            <p><a href="/../../user/user_dashboard.php">Return to Dashboard</a></p>
        </div>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    // Return error page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Source Verification Failed</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .error { color: #dc3545; }
            .container { max-width: 500px; margin: 0 auto; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1 class="error">✗ Verification Failed</h1>
            <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
            <p><a href="/../../user/user_dashboard.php">Return to Dashboard</a></p>
        </div>
    </body>
    </html>
    <?php
}
