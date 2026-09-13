<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Domain\Services\SwapService;

header('Content-Type: application/json');

SessionManager::start();
if (!SessionManager::isAdminLoggedIn()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Admin login required']);
    exit;
}

try {
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $countryConfig = \Core\Config\LoadCountry::getConfig();
    $country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'Botswana';

    $swapService = new SwapService($db, $countryConfig, $country);

    // Each category is run independently: a failure in one (e.g. a bug
    // deeper in the multi-source pool subsystem) must not prevent the
    // others from running or being reported.
    $sections = [
        'cashouts' => fn() => $swapService->cancelExpiredCashouts(),
        'identity_swaps' => fn() => $swapService->cancelExpiredIdentitySwaps(),
        'pool_cashouts' => fn() => $swapService->cancelExpiredPoolCashouts(),
        'pool_identity_claims' => fn() => $swapService->cancelExpiredPoolIdentityClaims(),
    ];

    $report = [];
    $totalProcessed = 0;
    $sectionErrors = 0;
    foreach ($sections as $name => $run) {
        try {
            $report[$name] = $run();
            $totalProcessed += (int)($report[$name]['total_expired'] ?? 0);
        } catch (\Throwable $e) {
            error_log("[process_expired_swaps] section '{$name}' failed: " . $e->getMessage());
            $report[$name] = ['error' => $e->getMessage()];
            $sectionErrors++;
        }
    }

    echo json_encode([
        'status' => $sectionErrors > 0 ? 'partial_success' : 'success',
        'total_processed' => $totalProcessed,
        'section_errors' => $sectionErrors,
        'report' => $report,
    ]);

} catch (Throwable $e) {
    error_log('[process_expired_swaps] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Processing failed: ' . $e->getMessage(),
    ]);
}
