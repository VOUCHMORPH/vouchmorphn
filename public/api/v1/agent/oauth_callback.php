<?php
// api/v1/agent/oauth_callback.php - Bank redirects here after direct login.
// This is a browser-facing PAGE, not a JSON API - the user's browser
// physically lands here mid-flow, so it renders simple HTML rather
// than returning JSON.
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

SessionManager::start();

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$errorParam = $_GET['error'] ?? null;

function renderResultPage(bool $success, string $title, string $message): void
{
    $icon = $success ? '✅' : '❌';
    $color = $success ? '#1a9e5c' : '#d32f2f';
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #FAF3E0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
.card { background: #fff; border-radius: 16px; padding: 32px; max-width: 400px; text-align: center; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
.icon { font-size: 48px; margin-bottom: 12px; }
h1 { font-size: 18px; color: {$color}; margin-bottom: 8px; }
p { color: #5c5346; font-size: 14px; line-height: 1.5; }
a { display: inline-block; margin-top: 20px; padding: 12px 28px; background: {$color}; color: #fff; text-decoration: none; border-radius: 999px; font-weight: 600; font-size: 14px; }
</style></head>
<body>
<div class="card">
    <div class="icon">{$icon}</div>
    <h1>{$title}</h1>
    <p>{$message}</p>
    <a href="/user/user_dashboard.php">Return to Dashboard</a>
</div>
</body></html>
HTML;
    exit;
}

if ($errorParam) {
    renderResultPage(false, 'Login Cancelled', 'The bank login was cancelled or denied. You can try registering again from your dashboard.');
}

if (!$code || !$state) {
    renderResultPage(false, 'Missing Information', 'The bank did not return the expected login confirmation. Try registering again.');
}

require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

try {
    $db = DBConnection::getConnection();
    $country = getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    $result = $swapService->completeAgentDestinationRegistrationByState($state, $code);

    renderResultPage(
        true,
        'Account Verified',
        $result['message'] ?? 'Your account was verified and registered. It now awaits approval.'
    );
} catch (\Throwable $e) {
    error_log("[oauth_callback] Error: " . $e->getMessage());
    renderResultPage(false, 'Verification Failed', $e->getMessage());
}
