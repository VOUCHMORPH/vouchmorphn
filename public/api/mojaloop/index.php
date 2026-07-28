<?php

/**
 * public/api/mojaloop/index.php
 * ============================================================
 * Single Mojaloop-style entry point. Replaces two byte-for-byte
 * duplicate router files that both pointed at
 * `use DFSP_ADAPTER_LAYER\MojaloopRouter;` - a class in a namespace
 * that was never registered in composer.json's PSR-4 map and could
 * not have been autoloaded even if it existed. This file routes
 * directly to the real, fixed handlers under Application\Handlers\Mojaloop
 * and Infrastructure\Mojaloop instead of a dead intermediate router class.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';
require_once dirname(__DIR__, 3) . '/src/Core/Database/DBConnection.php';
require_once dirname(__DIR__, 3) . '/src/Core/Config/LoadCountry.php';
require_once dirname(__DIR__, 3) . '/src/Domain/Services/SwapService.php';
require_once dirname(__DIR__, 3) . '/src/Infrastructure/Mojaloop/FspiopHeaderValidator.php';
require_once dirname(__DIR__, 3) . '/src/Infrastructure/Mojaloop/MojaloopErrorMapper.php';
require_once dirname(__DIR__, 3) . '/src/Infrastructure/Mojaloop/IdempotencyService.php';
require_once dirname(__DIR__, 3) . '/src/Infrastructure/Mojaloop/Dto/PartyLookupRequest.php';
require_once dirname(__DIR__, 3) . '/src/Infrastructure/Mojaloop/Dto/QuoteRequest.php';
require_once dirname(__DIR__, 3) . '/src/Infrastructure/Mojaloop/Dto/TransferRequest.php';
require_once dirname(__DIR__, 3) . '/src/Application/Handlers/Mojaloop/PartiesHandler.php';
require_once dirname(__DIR__, 3) . '/src/Application/Handlers/Mojaloop/QuotesHandler.php';
require_once dirname(__DIR__, 3) . '/src/Application/Handlers/Mojaloop/TransfersHandler.php';
require_once dirname(__DIR__, 3) . '/src/Application/Handlers/Mojaloop/ParticipantsHandler.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;
use Infrastructure\Mojaloop\FspiopHeaderValidator;
use Infrastructure\Mojaloop\Dto\PartyLookupRequest;
use Infrastructure\Mojaloop\Dto\QuoteRequest;
use Infrastructure\Mojaloop\Dto\TransferRequest;
use Application\Handlers\Mojaloop\PartiesHandler;
use Application\Handlers\Mojaloop\QuotesHandler;
use Application\Handlers\Mojaloop\TransfersHandler;
use Application\Handlers\Mojaloop\ParticipantsHandler;

// ============================================================
// PATH PARSING
// ============================================================
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$scriptName = $_SERVER['SCRIPT_NAME'];
if (strpos($path, $scriptName) === 0) {
    $path = substr($path, strlen($scriptName));
}
$path = '/' . ltrim($path, '/');

// ============================================================
// CORS
// ============================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, FSPIOP-Source, FSPIOP-Destination, FSPIOP-Signature, Date');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// HEALTH CHECK — no auth, no body needed
// ============================================================
if ($path === '/health' || $path === '/health/') {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'status' => 'OK',
        'service' => 'vouchmorph-mojaloop-adapter',
        'timestamp' => date('c'),
    ]);
    exit;
}

// ============================================================
// INBOUND CALLBACK — kept as a stub that logs and acknowledges,
// same as before. NOTE: this does NOT yet route into
// confirmSwitchSettlement() (see switch_settlement_architecture.md)
// because that method doesn't exist on SwapService yet. Once it
// does, this block should look up the swap by the incoming
// transferId/switch reference and call it, rather than just logging.
// ============================================================
if (strpos($path, '/callback') === 0) {
    $rawBody = file_get_contents('php://input');
    $callbackBody = json_decode($rawBody, true) ?: [];
    error_log('[Mojaloop Callback] Received: ' . json_encode($callbackBody));

    // TODO: route to SwapService::confirmSwitchSettlement() once that
    // method exists - see switch_settlement_architecture.md section 6.

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'received' => true]);
    exit;
}

// ============================================================
// BODY + HEADERS
// ============================================================
$body = [];
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)) {
    $raw = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?: []) : [];
}
$headers = array_change_key_case(getallheaders() ?: [], CASE_UPPER);

// FSPIOP header validation applies to real protocol endpoints only,
// not health/callback (already handled above and exited).
FspiopHeaderValidator::validate();

// ============================================================
// BOOTSTRAP SwapService — mirrors the construction pattern already
// used elsewhere in this codebase (see the diagnostic test scripts
// earlier this session): $pdo, $countryConfig, country name.
// ============================================================
$country = $_GET['country'] ?? 'Botswana';
$db = DBConnection::getConnection();
if (!$db) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
    exit;
}
$countryConfig = LoadCountry::getConfig($country);
$swapService = new SwapService($db, $countryConfig, $country, null);

// ============================================================
// ROUTE
// ============================================================
$result = ['status' => 'error', 'message' => 'Unknown endpoint'];
$contentType = 'application/json';

if (preg_match('#^/parties/([^/]+)/([^/]+)$#', $path, $m)) {
    $contentType = 'application/vnd.interoperability.parties+json;version=1.0';
    $handler = new PartiesHandler($country);
    $result = $handler->lookup(['type' => $m[1], 'id' => $m[2]], $headers);

} elseif ($path === '/quotes') {
    $contentType = 'application/vnd.interoperability.quotes+json;version=1.0';
    $handler = new QuotesHandler($swapService);
    $result = $handler->createQuote(new QuoteRequest($body));

} elseif ($path === '/transfers') {
    $contentType = 'application/vnd.interoperability.transfers+json;version=1.0';
    $handler = new TransfersHandler($swapService, $db);
    $result = $handler->executeTransfer(new TransferRequest($body));

} elseif ($path === '/participants') {
    $handler = new ParticipantsHandler($country);
    $result = $handler->lookup(new PartyLookupRequest($body));
}

// ============================================================
// RESPONSE — Mojaloop's real protocol is async (202 Accepted with
// an empty body, real result delivered on a later PUT callback).
// Kept for protocol accuracy, same as the original file's intent.
// ============================================================
header("Content-Type: {$contentType}");
http_response_code(202);
echo '';
