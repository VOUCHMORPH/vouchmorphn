<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Resolve Scanned QR
 *
 * Takes the raw string a camera scanner read off a card's QR side and
 * turns it into "here's whose card this is, hook to it?" for a
 * confirmation screen — BEFORE any source gets touched. Hooking itself
 * still goes through hook.php's normal consent/verify/hold pipeline.
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
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/QrCodeService.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/Adapters/VouchMorphHookQrAdapter.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Infrastructure\QRcodes\QrCodeService;
use Infrastructure\QRcodes\Adapters\VouchMorphHookQrAdapter;
use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || empty($input['raw'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'raw (the scanned QR string) is required']);
    exit();
}

$db = $container->get(PDO::class);

try {
    $qrService = new QrCodeService();
    $qrService->registerAdapter(new VouchMorphHookQrAdapter());
    $payload = $qrService->decode($input['raw']);

    // decode() succeeding here only means "this is structurally a hook
    // QR" — the adapter reports signature/expiry failures via
    // $payload->data rather than throwing (see VouchMorphHookQrAdapter's
    // class doc for why: QrCodeService swallows adapter exceptions).
    if (($payload->data['valid'] ?? false) !== true) {
        $reason = $payload->data['reason'] ?? 'invalid';
        $message = match ($reason) {
            'expired' => 'This card QR code has expired — ask the card owner to reissue it.',
            'signature_mismatch' => 'This code could not be verified — it may be damaged or forged.',
            default => 'This code is not valid.',
        };
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $message]);
        exit();
    }

    $cardSuffix = $payload->data['card_suffix'] ?? null;
    if (!$cardSuffix) {
        throw new RuntimeException('QR did not resolve to a card.');
    }

    $stmt = $db->prepare("
        SELECT card_suffix, cardholder_name, status
        FROM message_cards WHERE card_suffix = :suffix
    ");
    $stmt->execute([':suffix' => $cardSuffix]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card || $card['status'] !== 'ACTIVE') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'This card is not active or does not exist.']);
        exit();
    }

    // Display first name + last initial only — this is a stranger's
    // card being confirmed, not a full identity reveal.
    $nameParts = preg_split('/\s+/', trim((string)$card['cardholder_name']));
    $displayName = count($nameParts) >= 2
        ? $nameParts[0] . ' ' . mb_substr(end($nameParts), 0, 1) . '.'
        : ($nameParts[0] ?? 'VouchMorph user');

    echo json_encode([
        'success' => true,
        'data' => [
            'card_suffix' => $card['card_suffix'],
            'display_name' => $displayName,
        ],
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
