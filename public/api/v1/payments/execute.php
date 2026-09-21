<?php
declare(strict_types=1);

/**
 * VouchMorph — Execute a QR Payment Request
 *
 * Pays a previously-created payment_requests row. Destination
 * (institution/identifier/asset_type) and amount are read ONLY from
 * that row, locked at creation time — nothing here is taken from the
 * client for those fields, regardless of what the payer's device sends.
 *
 * Supports single-source and MULTI_SOURCE (Combine) payer funding.
 * VMCARD-funded payments go through cards/Create.php instead — see
 * that endpoint for the equivalent payment_request_id-based lock.
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Country-Code");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit();
}

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';
require_once ROOT_PATH . '/src/Core/Config/LoadCountry.php';
require_once ROOT_PATH . '/src/Domain/Services/SwapService.php';

use Application\Utils\SessionManager;
use Domain\Services\SwapService;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$sessionUser = SessionManager::getUser();
$payerUserId = (int)($sessionUser['id'] ?? $sessionUser['user_id'] ?? 0);
if (!$payerUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not resolve user from session']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit();
}

if (empty($input['request_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'request_id is required']);
    exit();
}
$requestId = (int)$input['request_id'];

$db = $container->get(PDO::class);

// Incident Command gate: sandbox cap and freezes, before any money moves.
require_once dirname(__DIR__, 4) . '/src/Application/Incident/IncidentDesk.php';
require_once dirname(__DIR__, 4) . '/src/Application/Incident/Playbooks.php';
require_once dirname(__DIR__, 4) . '/src/Application/Incident/ServiceControls.php';
$preGate = \Application\Incident\ServiceControls::check($db, ['amount' => 0, 'flow' => 'PAYMENT_REQUEST', 'user_id' => $payerUserId]);
if ($preGate !== null) {
    http_response_code($preGate['http']);
    echo json_encode(['success' => false, 'error' => $preGate['message'], 'code' => $preGate['code'], 'funds_moved' => false]);
    exit();
}

try {
    $db->beginTransaction();

    // ------------------------------------------------------------
    // Lock and re-validate the payment request. This is the single
    // source of truth for destination + amount — the client's own
    // copy of these (if any) is never consulted below.
    // ------------------------------------------------------------
    $stmt = $db->prepare("
        SELECT * FROM payment_requests WHERE id = :id FOR UPDATE
    ");
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Payment request not found.']);
        exit();
    }
    if ($request['status'] !== 'PENDING') {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This payment request has already been used or cancelled.']);
        exit();
    }
    if (strtotime($request['expires_at']) < time()) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This payment request has expired.']);
        exit();
    }

    // ------------------------------------------------------------
    // Build the swap payload. Source comes from the payer's client
    // (single source or Combine sources[]) — destination/amount are
    // locked from the DB row above.
    // ------------------------------------------------------------
    $isMultiSource = !empty($input['sources']) && is_array($input['sources']) && count($input['sources']) > 1;

    $reference = 'PAYREQ_' . $requestId . '_' . time();
    $idempotencyKey = 'PAYREQ_IDEMP_' . $requestId . '_' . $payerUserId;

    $destinationType = $request['destination_asset_type'] === 'ATM' ? 'CASHOUT' : 'DEPOSIT'; // adjust if your destination_asset_type vocabulary differs

    $basePayload = [
        'reference' => $reference,
        'idempotency_key' => $idempotencyKey,
        'user_id' => $payerUserId,
        'amount' => (float)$request['net_amount'],
        'currency' => $request['currency'],
        'destination_currency' => $request['currency'],
        'to_institution' => $request['destination_institution'],
        'destination_institution' => $request['destination_institution'],
        'destination_identifier' => $request['destination_identifier'],
        'destination_identifier_type' => $request['destination_asset_type'] === 'ACCOUNT' ? 'account_number' : 'phone',
        'destination_asset_type' => $request['destination_asset_type'],
    ];

    if ($isMultiSource) {
        $sources = [];
        foreach ($input['sources'] as $idx => $src) {
            foreach (['institution', 'asset_type', 'identifier', 'amount'] as $field) {
                if (empty($src[$field]) && $src[$field] !== 0) {
                    http_response_code(400);
                    $db->rollBack();
                    echo json_encode(['success' => false, 'error' => "sources[{$idx}].{$field} is required"]);
                    exit();
                }
            }
            $sources[] = [
                'institution' => $src['institution'],
                'asset_type' => $src['asset_type'],
                'identifier' => $src['identifier'],
                'amount' => (float)$src['amount'],
                'wallet_pin' => $src['wallet_pin'] ?? $src['pin'] ?? null,
                'pin' => $src['wallet_pin'] ?? $src['pin'] ?? null,
                'source_identifier_type' => $src['identifier_type'] ?? 'auto',
            ];
        }

        $swapPayload = array_merge($basePayload, [
            'swap_type' => 'MULTI_SOURCE',
            'sources' => $sources,
            'contribution_strategy' => $input['contribution_strategy'] ?? 'SMART',
            'source_currency' => $request['currency'],
        ]);

    } else {
        $src = $input['source'] ?? $input; // allow either nested or flat single-source input
        foreach (['institution', 'asset_type', 'identifier'] as $field) {
            if (empty($src[$field])) {
                http_response_code(400);
                $db->rollBack();
                echo json_encode(['success' => false, 'error' => "source.{$field} is required"]);
                exit();
            }
        }

        $swapPayload = array_merge($basePayload, [
            'swap_type' => $destinationType,
            'from_institution' => $src['institution'],
            'source_institution' => $src['institution'],
            'asset_type' => $src['asset_type'],
            'source_identifier' => $src['identifier'],
            'source_identifier_type' => $src['identifier_type'] ?? 'auto',
            'wallet_pin' => $src['wallet_pin'] ?? $src['pin'] ?? null,
            'pin' => $src['wallet_pin'] ?? $src['pin'] ?? null,
        ]);

        if ($destinationType === 'CASHOUT') {
            $swapPayload['delivery_method'] = $request['destination_asset_type']; // e.g. ATM/AGENT
            $swapPayload['beneficiary_phone'] = $input['beneficiary_phone'] ?? null;
        }
    }

    // Commit the row lock's transaction before calling into SwapService,
    // which manages its own atomic transaction internally via
    // beginAtomicSwap()/commitAtomicSwap() — holding this outer
    // transaction open across that call would nest transactions
    // unnecessarily and hold the payment_requests row locked far longer
    // than needed.
    $db->commit();

    // ------------------------------------------------------------
    // Execute through the same SwapService path swap/execute.php uses.
    // ------------------------------------------------------------
    $countryConfig = \Core\Config\LoadCountry::getConfig();
    $countryName = $countryConfig['country'] ?? 'Botswana';
    $swapService = new SwapService($db, $countryConfig, $countryName);

    $result = $swapService->executeAtomicSwap($swapPayload);

    // ------------------------------------------------------------
    // Mark the request completed. Separate transaction — the swap
    // itself already committed above; this is bookkeeping only.
    // ------------------------------------------------------------
    $stmt = $db->prepare("
        UPDATE payment_requests
        SET status = 'COMPLETED', swap_reference = :ref, completed_at = NOW()
        WHERE id = :id AND status = 'PENDING'
    ");
    $stmt->execute([':ref' => $result['reference'] ?? $reference, ':id' => $requestId]);

    echo json_encode([
        'success' => true,
        'data' => [
            'request_id' => $requestId,
            'swap_reference' => $result['reference'] ?? $reference,
            'status' => $result['status'] ?? 'completed',
            'amount' => $request['net_amount'],
            'currency' => $request['currency'],
        ],
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[PaymentExecute] Failed for request_id={$requestId}: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
