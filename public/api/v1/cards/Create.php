<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Create Contribution Session
 *
 * The card owner names a destination + target amount and picks a
 * strategy. Contributors currently hooked to this card then watch (and,
 * for MANUAL, enter their own amount) via status.php / contribute.php.
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
require_once ROOT_PATH . '/src/Domain/Services/CardContributionSessionService.php';
require_once ROOT_PATH . '/src/Domain/Services/ContributionCalculator.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Domain\Services\CardContributionSessionService;
use Domain\Services\ContributionCalculator;
use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
$userId = (int)(SessionManager::getUser()['user_id'] ?? 0);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not resolve user from session']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

foreach (['card_suffix', 'target_amount', 'currency', 'strategy'] as $field) {
    if (empty($input[$field]) && $input[$field] !== 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "{$field} is required"]);
        exit();
    }
}

// Destination: either an institution/asset destination or an identity
// destination, mirroring PoolCoordinator's own accepted shapes.
$destinationPayload = [];
if (!empty($input['identity_type']) && !empty($input['identity_value'])) {
    $destinationPayload['identity_type'] = $input['identity_type'];
    $destinationPayload['identity_value'] = $input['identity_value'];
    $destinationPayload['beneficiary_phone'] = $input['beneficiary_phone'] ?? null;
} else {
    foreach (['to_institution', 'destination_identifier'] as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "{$field} is required for a non-identity destination"]);
            exit();
        }
    }
    $destinationPayload['to_institution'] = $input['to_institution'];
    $destinationPayload['destination_institution'] = $input['to_institution'];
    $destinationPayload['destination_identifier'] = $input['destination_identifier'];
    $destinationPayload['destination_identifier_type'] = $input['destination_identifier_type'] ?? 'account';
    $destinationPayload['destination_asset_type'] = $input['destination_asset_type'] ?? 'WALLET';
    $destinationPayload['delivery_method'] = $input['delivery_method'] ?? 'DEPOSIT';
    if (!empty($input['beneficiary_phone'])) {
        $destinationPayload['beneficiary_phone'] = $input['beneficiary_phone'];
    }
}

$db = $container->get(PDO::class);
$service = new CardContributionSessionService($db, new ContributionCalculator());

try {
    $result = $service->createSession(
        $input['card_suffix'],
        $userId,
        $destinationPayload,
        (float)$input['target_amount'],
        strtoupper($input['currency']),
        strtoupper($input['strategy']),
        isset($input['min_contribution_amount']) ? (float)$input['min_contribution_amount'] : null,
        isset($input['ttl_seconds']) ? (int)$input['ttl_seconds'] : null
    );

    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $result], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
