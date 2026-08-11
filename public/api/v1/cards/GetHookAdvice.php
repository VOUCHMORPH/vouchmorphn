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
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
foreach (['institution', 'asset_type', 'identifier'] as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "{$field} is required"]);
        exit();
    }
}

try {
    $swapService = $container->get('Domain\Services\SwapService');
    $feeService = $container->get('Domain\Services\FeeService');

    $balance = $swapService->getSourceAvailableBalance([
        'institution' => $input['institution'],
        'asset_type' => $input['asset_type'],
        'identifier' => $input['identifier'],
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
