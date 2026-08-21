<?php
declare(strict_types=1);

/**
 * TEST-ONLY endpoint — wraps Iso8583AuthorizationBridge over HTTP so
 * ATM/POS message flows can be exercised in automated tests without a
 * real terminal/switch connection. Uses FakeTestHsmPinVerification,
 * which itself refuses to run outside APP_ENV=test/dev — this file
 * adds its own independent guard on top, so there are two separate
 * checks standing between this test path and any production traffic.
 *
 * NEVER expose this endpoint outside a test/dev deployment. It accepts
 * a JSON description of ISO 8583 fields (easier to drive from a test
 * harness than raw wire bytes) and returns both the parsed response
 * fields and the raw wire bytes for inspection.
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Content-Type: application/json; charset=UTF-8");

if ((getenv('APP_ENV') ?: '') !== 'test' && (getenv('APP_ENV') ?: '') !== 'dev') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'This test endpoint is disabled outside test/dev environments.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit();
}

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
require_once ROOT_PATH . '/src/Infrastructure/Iso8583/Iso8583Message.php';
require_once ROOT_PATH . '/src/Infrastructure/Iso8583/Iso8583AuthorizationBridge.php';
require_once ROOT_PATH . '/src/Infrastructure/Hsm/PinVerificationInterface.php';
require_once ROOT_PATH . '/src/Infrastructure/Hsm/FakeTestHsmPinVerification.php';

use Domain\Services\CardService;
use Infrastructure\Iso8583\Iso8583Message;
use Infrastructure\Iso8583\Iso8583AuthorizationBridge;
use Infrastructure\Hsm\FakeTestHsmPinVerification;

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || empty($input['mti']) || empty($input['fields'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'mti and fields are required']);
    exit();
}

try {
    $db = $container->get(PDO::class);
    $swapService = $container->get('Domain\Services\SwapService');
    $cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));
    $pinVerifier = new FakeTestHsmPinVerification();

    $bridge = new Iso8583AuthorizationBridge($db, $cardService, $swapService, $pinVerifier);

    // Build the request from JSON-described fields, matching field
    // numbers to Iso8583Message::FIELD_SPECS. Field 4 (amount) is
    // accepted as a decimal string for test convenience and converted
    // to ISO 8583's minor-units integer format here.
    $fields = $input['fields'];
    if (isset($fields[4]) && str_contains((string)$fields[4], '.')) {
        $fields[4] = (string)(int)round(((float)$fields[4]) * 100);
    }

    $requestMessage = Iso8583Message::build($input['mti'], $fields);
    $wireRequest = $requestMessage->toWire();

    $wireResponse = $bridge->handle($wireRequest);
    $parsedResponse = Iso8583Message::fromWire($wireResponse);

    echo json_encode([
        'success' => true,
        'request_wire_hex' => bin2hex($wireRequest),
        'response_wire_hex' => bin2hex($wireResponse),
        'response_mti' => $parsedResponse->mti,
        'response_fields' => $parsedResponse->fields,
        'response_code' => $parsedResponse->fields[39] ?? null,
        'approved' => ($parsedResponse->fields[39] ?? null) === '00',
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => explode("\n", $e->getTraceAsString()),
    ]);
}
