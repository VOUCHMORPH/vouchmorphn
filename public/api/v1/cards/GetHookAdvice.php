<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Hook Advice
 *
 * Advisory-only, no side effects: given a source, tells the user what
 * they CAN authorize before they commit — their live available balance
 * and VouchMorph's country-wide per-transaction ceiling — so the hook
 * confirmation screen can show a real, pre-computed cap instead of the
 * user guessing a number. hook.php independently re-validates the same
 * cap server-side; this endpoint never places a hold itself.
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit();
}

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn() || !SessionManager::isUser()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
foreach (['institution', 'asset_type', 'identifier'] as $field) {
    if (!is_array($input) || empty($input[$field]) || !is_scalar($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "{$field} is required"]);
        exit();
    }
}

// A live balance is only ever shown for the signed-in user's own verified
// source. This used to look up whatever account number it was given, which
// let anyone read a stranger's balance one account number at a time.
try {
    $owned = \Domain\Services\SourceOwnershipGuard::forCountry($container->get(PDO::class), $container->get('countryConfig'))
        ->assertOwned((int)(SessionManager::getUser()['user_id'] ?? 0), [
            'institution' => (string)$input['institution'],
            'asset_type' => (string)$input['asset_type'],
            'identifier' => (string)$input['identifier'],
        ] + array_intersect_key($input, array_flip(['pin', 'wallet_pin', 'voucher_pin'])));
} catch (\Domain\Services\SourceOwnershipException $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit();
}

try {
    $swapService = $container->get('Domain\Services\SwapService');
    $feeService = $container->get('Domain\Services\FeeService');

    $balance = $swapService->getSourceAvailableBalance([
        'institution' => $owned['institution'],
        'asset_type' => (string)$input['asset_type'],
        'identifier' => $owned['identifier'],
    ]);

    $cap = $feeService->getMaxTransactionLimit();
    $recommendedMax = min($balance, $cap['amount']);

    echo json_encode([
        'success' => true,
        'data' => [
            'available_balance' => $balance,
            'vouchmorph_max_transaction' => $cap['amount'],
            'currency' => $cap['currency'],
            'recommended_cap' => round($recommendedMax, 2),
        ],
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[GetHookAdvice] Failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not check balance right now.']);
}
