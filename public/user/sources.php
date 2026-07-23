<?php
/**
 * Get user's source accounts
 * GET /user/sources.php
 *
 * FIX: Previously only returned getUserSourceAccounts() (the
 * manually-added/verified sources in user_source_accounts). This
 * silently omitted any OAuth-linked bank sources living in
 * source_accounts (returned by getHookedSources()), so a user who
 * linked a bank via OAuth would see it work during a swap but never
 * see it listed in "My Sources" on the dashboard. Now merges both,
 * tagged so the frontend can distinguish them if needed, and strips
 * sensitive OAuth token fields before returning to the client.
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';
use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;
header('Content-Type: application/json');
try {
    SessionManager::start();
    
    if (!SessionManager::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $userData = SessionManager::getUser();
    $userId = $userData['id'] ?? $userData['user_id'] ?? null;
    
    if (empty($userId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Session has no user id']);
        exit;
    }
    
    $db = DBConnection::getConnection();
    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $config = LoadCountry::getConfig();
    
    $swapService = new SwapService($db, $config, $country);

    // Manually-added / verified sources
    $manualSources = $swapService->getUserSourceAccounts($userId);
    foreach ($manualSources as &$src) {
        $src['link_type'] = 'manual';
        $src['id'] = 'manual_' . $src['id']; // namespace to avoid ID collisions across tables
    }
    unset($src);

    // OAuth-linked sources
    $hookedSources = [];
    try {
        $hooked = $swapService->getHookedSources($userId);
        foreach ($hooked as $h) {
            $hookedSources[] = [
                'id' => 'hooked_' . ($h['id'] ?? $h['source_reference'] ?? uniqid()),
                'institution' => $h['institution'] ?? $h['bank_name'] ?? 'Unknown',
                'asset_type' => $h['asset_type'] ?? 'BANK',
                'identifier' => $h['identifier'] ?? $h['account_number'] ?? null,
                'identifier_type' => $h['identifier_type'] ?? null,
                'account_name' => $h['holder_name'] ?? $h['account_name'] ?? null,
                'currency' => $h['currency'] ?? $config['currency'] ?? 'BWP',
                'status' => $h['status'] ?? 'active',
                'confirmed_at' => $h['confirmed_at'] ?? $h['created_at'] ?? null,
                'last_used_at' => $h['last_used_at'] ?? null,
                'link_type' => 'oauth',
                // NOTE: access_token/refresh_token intentionally excluded -
                // never send decrypted tokens to the client.
            ];
        }
    } catch (Exception $e) {
        error_log("[sources.php] getHookedSources failed (non-fatal): " . $e->getMessage());
    }

    $allSources = array_merge($manualSources, $hookedSources);
    
    echo json_encode([
        'success' => true,
        'data' => ['sources' => $allSources]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
