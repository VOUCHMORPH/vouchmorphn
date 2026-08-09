<?php
declare(strict_types=1);

/**
 * VouchMorph Card — My Card
 *
 * Returns everything the "My VouchMorph Card" dashboard panel needs in
 * one call: the card_suffix, the printable QR payload string (render
 * client-side with a QR JS lib — this endpoint returns TEXT, not an
 * image), current hook status + masked contributor list, and any
 * active contribution session.
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
require_once ROOT_PATH . '/src/Domain/Services/CardContributionSessionService.php';
require_once ROOT_PATH . '/src/Domain/Services/ContributionCalculator.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/QrCodeService.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/Adapters/VouchMorphHookQrAdapter.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

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
$userId = (int)(SessionManager::getUser()['user_id'] ?? 0);

$db = $container->get(PDO::class);

// A user's own VouchMorph Card. Adjust this query if your schema
// links card ownership differently (e.g. via card_applications rather
// than message_cards.user_id directly).
$stmt = $db->prepare("
    SELECT card_suffix, cardholder_name, status, funding_mode
    FROM message_cards
    WHERE user_id = :uid AND status = 'ACTIVE'
    ORDER BY issued_at DESC LIMIT 1
");
$stmt->execute([':uid' => $userId]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$card) {
    echo json_encode(['success' => true, 'data' => ['has_card' => false]], JSON_PRETTY_PRINT);
    exit();
}

$cardSuffix = $card['card_suffix'];

// QR payload for printing/display.
$qrPayload = null;
try {
    $qrService = new QrCodeService();
    $qrService->registerAdapter(new VouchMorphHookQrAdapter());
    $qrPayload = $qrService->encode(new QrPayload('hook', ['card_suffix' => $cardSuffix]), 'VOUCHMORPH_HOOK_V1');
} catch (Exception $e) {
    error_log("[My.php] QR encode failed: " . $e->getMessage());
}

// Active hook + masked contributor list.
$stmt = $db->prepare("
    SELECT id, hook_reference, total_held_amount, currency, status, expires_at
    FROM card_pool_hooks
    WHERE card_suffix = :suffix AND status = 'HOOKED' AND expires_at > NOW()
    ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([':suffix' => $cardSuffix]);
$hook = $stmt->fetch(PDO::FETCH_ASSOC);

$contributors = [];
$activeSession = null;

if ($hook) {
    $stmt = $db->prepare("
        SELECT institution, asset_type, source_identifier, owner_user_id, held_amount, status
        FROM card_pool_hook_sources
        WHERE hook_id = :hook_id AND status = 'HELD'
        ORDER BY id
    ");
    $stmt->execute([':hook_id' => $hook['id']]);
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

    $sessionService = new CardContributionSessionService($db, new ContributionCalculator());
    $sessionStmt = $db->prepare("
        SELECT id FROM card_contribution_sessions
        WHERE hook_reference = :ref AND status IN ('OPEN', 'READY', 'EXECUTING')
        ORDER BY created_at DESC LIMIT 1
    ");
    $sessionStmt->execute([':ref' => $hook['hook_reference']]);
    $sessionRow = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    if ($sessionRow) {
        try {
            $activeSession = $sessionService->getStatus((int)$sessionRow['id'], $userId);
        } catch (Exception $e) {
            // User isn't owner/contributor on this session — leave null.
        }
    }
}

echo json_encode([
    'success' => true,
    'data' => [
        'has_card' => true,
        'card_suffix' => $cardSuffix,
        'cardholder_name' => $card['cardholder_name'],
        'funding_mode' => $card['funding_mode'] ?? 'HOOKED',
        'qr_payload' => $qrPayload,
        'hook' => $hook ? [
            'hook_reference' => $hook['hook_reference'],
            'total_held' => (float)$hook['total_held_amount'],
            'currency' => $hook['currency'],
            'expires_at' => $hook['expires_at'],
            'contributors' => $contributors,
            'is_owner' => true,
        ] : null,
        'active_session' => $activeSession,
    ],
], JSON_PRETTY_PRINT);
