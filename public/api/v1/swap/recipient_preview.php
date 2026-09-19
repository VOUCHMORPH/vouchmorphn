<?php
declare(strict_types=1);

/**
 * VouchMorph - Recipient Name Preview API
 *
 * Answers one question for the review screen: who is about to receive this
 * money? Read-only — nothing is held, debited or recorded against a swap by
 * calling this.
 *
 * Works for every kind of destination the wizard offers:
 *   - an account / wallet / card at an institution (name enquiry at that
 *     institution, via the same adapter the swap itself uses);
 *   - a VouchMorph identity such as a national ID (the verified owner of
 *     that identity);
 *   - a cashout (the owner of the phone the code is texted to).
 *
 * The name comes back masked ("J*** M****** D**") and never in full — see
 * PrivacyMasker. Enough for the sender to recognise the person they meant to
 * pay, not enough to turn this endpoint into a way of putting names to
 * account numbers.
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Domain\Services\RecipientPreviewService;
use Infrastructure\Adapters\InstitutionAdapterFactory;

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Country-Code, X-Country');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * A name lookup is cheap for us and valuable to someone fishing for the
 * holder of an account number, so the number of lookups one logged-in
 * session can make is capped. The cap is generous enough that a person
 * correcting a mistyped account number several times never meets it.
 */
const RECIPIENT_PREVIEW_WINDOW_SECONDS = 300;
const RECIPIENT_PREVIEW_MAX_PER_WINDOW = 40;

function recipientPreviewThrottleExceeded(): bool
{
    $window = (int)floor(time() / RECIPIENT_PREVIEW_WINDOW_SECONDS);
    $state = SessionManager::get('_recipient_preview_throttle', null);

    if (!is_array($state) || ($state['window'] ?? null) !== $window) {
        $state = ['window' => $window, 'count' => 0];
    }

    $state['count']++;
    SessionManager::set('_recipient_preview_throttle', $state);

    return $state['count'] > RECIPIENT_PREVIEW_MAX_PER_WINDOW;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
        exit();
    }

    SessionManager::start();

    if (!SessionManager::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }

    $sessionUser = SessionManager::getUser();
    $sessionUserId = (int)($sessionUser['id'] ?? $sessionUser['user_id'] ?? 0);

    if (!$sessionUserId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Could not resolve user from session']);
        exit();
    }

    if (recipientPreviewThrottleExceeded()) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Too many recipient checks in a short time. Wait a few minutes and try again.',
        ]);
        exit();
    }

    $input = json_decode((string)file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        exit();
    }

    $db = DBConnection::getConnection();

    if (!$db) {
        throw new RuntimeException('Database connection failed');
    }

    $countryConfig = \Core\Config\LoadCountry::getConfig();
    $participants = $countryConfig['participants'] ?? [];

    $logger = new class {
        public function __call($name, $args)
        {
            $message = $args[0] ?? '';
            error_log("[RECIPIENT_PREVIEW] " . strtoupper((string)$name) . ": " . (is_string($message) ? $message : json_encode($message)));
        }
    };

    $service = new RecipientPreviewService($db, new InstitutionAdapterFactory($participants, $logger));

    $destinationType = strtoupper(trim((string)($input['destination_type'] ?? '')));

    if ($destinationType === 'IDENTITY') {
        $recipient = $service->previewIdentityRecipient(
            (string)($input['identity_type'] ?? ''),
            (string)($input['identity_value'] ?? '')
        );
    } elseif ($destinationType === 'CASHOUT') {
        $recipient = $service->previewCashoutRecipient(
            (string)($input['destination_identifier'] ?? $input['beneficiary_phone'] ?? '')
        );
    } else {
        $recipient = $service->previewAccountRecipient(
            (string)($input['destination_institution'] ?? $input['to_institution'] ?? ''),
            (string)($input['destination_identifier'] ?? ''),
            (string)($input['destination_identifier_type'] ?? 'account'),
            (string)($input['destination_asset_type'] ?? 'ACCOUNT'),
            isset($input['source_institution']) ? (string)$input['source_institution'] : null
        );
    }

    echo json_encode(['success' => true, 'recipient' => $recipient]);

} catch (Throwable $e) {
    error_log("[RECIPIENT_PREVIEW] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => "We couldn't check the recipient's name right now.",
    ]);
}
