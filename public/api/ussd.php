<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Application/Controllers/USSDController.php';

use Application\Controllers\USSDController;

// Only the USSD gateway may call this: it is what vouches for phoneNumber,
// the caller's identity on this channel. Configure the gateway's callback
// URL with ?key=<USSD_GATEWAY_SECRET>. Without the secret configured,
// nothing is accepted (see USSDController::isFromGateway()).
if (!USSDController::isFromGateway($_SERVER, $_GET, getenv('USSD_GATEWAY_SECRET') ?: null)) {
    error_log('[USSD] Refused a request that did not carry the USSD gateway secret'
        . (getenv('USSD_GATEWAY_SECRET') ? '' : ' (USSD_GATEWAY_SECRET is not configured)')
        . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    header('Content-Type: text/plain; charset=UTF-8');
    echo "END Service unavailable.";
    exit;
}

try {
    $config = require __DIR__ . '/../../src/Core/Config/config_loader.php';
    $ussdController = new USSDController($config, $db);

    $request = array_merge($_GET, $_POST);

    $input = file_get_contents('php://input');
    if (!empty($input) && str_starts_with(trim($input), '{')) {
        $jsonData = json_decode($input, true);
        if (is_array($jsonData)) {
            $request = array_merge($request, $jsonData);
        }
    }

    if (
        empty($request['sessionId']) &&
        empty($request['SESSION_ID']) &&
        empty($request['session_id'])
    ) {
        header('Content-Type: text/plain');
        echo "END Invalid USSD request.";
        exit;
    }

    $response = $ussdController->handleUSSDRequest($request);

    header('Content-Type: text/plain; charset=UTF-8');
    echo trim($response);

} catch (Throwable $e) {
    error_log("USSD ERROR: " . $e->getMessage());
    header('Content-Type: text/plain; charset=UTF-8');
    echo "END System error. Please try again later.";
}
