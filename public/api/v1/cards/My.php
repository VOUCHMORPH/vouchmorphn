<?php
declare(strict_types=1);

/**
 * VouchMorph Card — My Card
 *
 * Auto-provisions a zero-cost INACTIVE card on first call if the user
 * doesn't have one yet. Everything after auth is wrapped in a single
 * try/catch so a failure ANYWHERE (missing config, a throwing
 * constructor, a DB error) always comes back as real JSON with the
 * actual message and a proper status code — never a corrupted 200
 * response, which is what a stray uncaught exception produces when
 * headers were already sent before it was thrown.
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
require_once ROOT_PATH . '/src/Domain/Services/CardContributionSessionService.php';
require_once ROOT_PATH . '/src/Domain/Services/ContributionCalculator.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/QrCodeService.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/Adapters/VouchMorphHookQrAdapter.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Domain\Services\CardService;
use Domain\Services\CardContributionSessionService;
use Domain\Services\ContributionCalculator;
use Infrastructure\QRcodes\QrCodeService;
use Infrastructure\QRcodes\Adapters\VouchMorphHookQrAdapter;
use Infrastructure\QRcodes\Contracts\QrPayload;
use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
$userData = SessionManager::getUser();
$userId = (int)($userData['id'] ?? $userData['user_id'] ?? 0);
$userName = $userData['full_name'] ?? $userData['username'] ?? 'VouchMorph User';

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not resolve user from session']);
    exit();
}

// ============================================================
// Everything below can legitimately throw (missing PAN_HMAC_KEY,
// missing container bindings, DB errors, etc.) — catch ALL of it here
// so the response is always well-formed JSON, never a corrupted 200.
// ============================================================
try {
    $db = $container->get(PDO::class);
    $cardConfig = $container->get('countryConfig') ?? [];
    $countryCode = $container->get('countryCode');

    $cardService = new CardService($db, $countryCode, $cardConfig);

    // Auto-provision: idempotent, zero-cost, zero-hold. Every user ends
    // up with exactly one card, INACTIVE until they pay to activate it.
    $provision = $cardService->provisionUserCard($userId, $userName);
    if (!($provision['success'] ?? false)) {
        throw new RuntimeException('provisionUserCard did not return success');
    }

    $cardSuffix = $provision['card_suffix'];

    $stmt = $db->prepare("
        SELECT card_suffix, cardholder_name, status, funding_mode, currency
        FROM message_cards WHERE card_suffix = :suffix
    ");
    $stmt->execute([':suffix' => $cardSuffix]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        throw new RuntimeException("Provisioned card {$cardSuffix} not found immediately after insert.");
    }

    $isActive = ($card['status'] === 'ACTIVE');
    $activationFee = (float)($cardConfig['activation_fee'] ?? 5.00);

    $qrPayload = null;
    $hook = null;
    $contributors = [];
    $activeSession = null;

    if ($isActive) {
        try {
            $qrService = new QrCodeService();
            $qrService->registerAdapter(new VouchMorphHookQrAdapter());
            $qrPayload = $qrService->encode(new QrPayload('hook', ['card_suffix' => $cardSuffix]), 'VOUCHMORPH_HOOK_V1');
        } catch (\Throwable $e) {
            // QR generation failing shouldn't take down the whole
            // endpoint — the card view still works without it.
            error_log("[My.php] QR encode failed: " . $e->getMessage());
        }

        $stmt = $db->prepare("
            SELECT id, hook_reference, total_held_amount, currency, status, expires_at
            FROM card_pool_hooks
            WHERE card_suffix = :suffix AND status = 'HOOKED' AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':suffix' => $cardSuffix]);
        $hookRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($hookRow) {
            $stmt = $db->prepare("
                SELECT institution, asset_type, source_identifier, owner_user_id, held_amount, status
                FROM card_pool_hook_sources
                WHERE hook_id = :hook_id AND status = 'HELD'
                ORDER BY id
            ");
            $stmt->execute([':hook_id' => $hookRow['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $identifier = (string)$s['source_identifier'];
                $masked = strlen($identifier) <= 4
                    ? str_repeat('•', strlen($identifier))
                    : substr($identifier, 0, 3) . str_repeat('•', max(0, strlen($identifier) - 6)) . substr($identifier, -3);

                $contributors[] = [
                    'institution' => $s['institution'],
                    'asset_type' => $s['asset_type'],
                    'source_identifier' => $masked,
                    'held_amount' => (float)$s['held_amount'],
                    'is_me' => ((int)$s['owner_user_id'] === $userId),
                ];
            }

            $hook = [
                'hook_reference' => $hookRow['hook_reference'],
                'total_held' => (float)$hookRow['total_held_amount'],
                'currency' => $hookRow['currency'],
                'expires_at' => $hookRow['expires_at'],
                'contributors' => $contributors,
                'is_owner' => true,
            ];

            $sessionService = new CardContributionSessionService($db, new ContributionCalculator());
            $sessionStmt = $db->prepare("
                SELECT id FROM card_contribution_sessions
                WHERE hook_reference = :ref AND status IN ('OPEN', 'READY', 'EXECUTING')
                ORDER BY created_at DESC LIMIT 1
            ");
            $sessionStmt->execute([':ref' => $hookRow['hook_reference']]);
            $sessionRow = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if ($sessionRow) {
                try {
                    $activeSession = $sessionService->getStatus((int)$sessionRow['id'], $userId);
                } catch (\Throwable $e) {
                    // Not owner/contributor on this session — leave null.
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'has_card' => true,
            'card_suffix' => $cardSuffix,
            'cardholder_name' => $card['cardholder_name'],
            'status' => $card['status'],
            'is_active' => $isActive,
            'funding_mode' => $card['funding_mode'] ?? 'HOOKED',
            'currency' => $card['currency'] ?? 'BWP',
            'activation_fee' => $activationFee,
            'qr_payload' => $qrPayload,
            'hook' => $hook,
            'active_session' => $activeSession,
        ],
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[My.php] FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        // Included so this is diagnosable from the dashboard's error
        // display without needing Railway log access — remove once
        // this is stable if you don't want file/line exposed to clients.
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ],
    ]);
}
