<?php
declare(strict_types=1);
// api/v1/callbacks/reservation_account_confirmed.php
//
// Destination institutions call this once a reservation account requested
// via createReservationAccount() (see GenericBankClient) has actually been
// opened, for institutions whose account-opening flow is asynchronous
// (back-office/KYC review) rather than returning the account_identifier
// synchronously. Flips the matching reservation_accounts row to 'active'
// and immediately sweeps in any remainder already parked in the pooled
// identity holding account while this account was still pending.

require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../../src/Domain/Services/ReservationAccountService.php';
require_once __DIR__ . '/../../../src/Infrastructure/Adapters/InstitutionAdapterFactory.php';

use Core\Config\LoadCountry;
use Domain\Services\ReservationAccountService;
use Infrastructure\Adapters\InstitutionAdapterFactory;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception('Invalid JSON payload');

    $bankReference = $input['bank_reference'] ?? $input['reference'] ?? null;
    $accountIdentifier = $input['account_identifier'] ?? $input['identifier'] ?? null;
    $institution = $input['institution'] ?? null; // whichever institution is calling — verified below

    if (!$bankReference || !$accountIdentifier || !$institution) {
        throw new Exception('bank_reference, account_identifier, and institution are required');
    }

    // Auth: same per-institution API key pattern as settlement_confirmed.php.
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expectedKey = getenv(strtoupper($institution) . '_VOUCHMORPH_API_KEY') ?: '';
    if (!$expectedKey || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid authentication']);
        exit;
    }

    $db = \Core\Database\DBConnection::getConnection();
    $countryConfig = LoadCountry::getConfig();
    $participants = $countryConfig['participants'] ?? [];

    $logger = new class {
        public function info($m, $c = []) { error_log("[ReservationAccountConfirmed] INFO: {$m} " . json_encode($c)); }
        public function error($m, $c = []) { error_log("[ReservationAccountConfirmed] ERROR: {$m} " . json_encode($c)); }
        public function warning($m, $c = []) { error_log("[ReservationAccountConfirmed] WARNING: {$m} " . json_encode($c)); }
        public function debug($m, $c = []) {}
        public function log($l, $m, $c = []) {}
    };

    $adapterFactory = new InstitutionAdapterFactory($participants, $logger);
    $reservationAccountService = new ReservationAccountService($db, $participants, $adapterFactory, $logger);

    $accountId = $reservationAccountService->confirmActivation(
        $bankReference,
        $accountIdentifier,
        $input['account_identifier_type'] ?? 'account_number',
        $input
    );

    if ($accountId === null) {
        // Idempotent no-op rather than an error — a real institution's
        // retry logic might call this more than once, or bank_reference
        // simply doesn't match anything we know about.
        echo json_encode(['success' => true, 'message' => 'No matching pending reservation account found — already resolved or unknown reference']);
        exit;
    }

    $sweepResult = $reservationAccountService->sweepOpenPositionsFor($accountId);

    echo json_encode([
        'success' => true,
        'message' => 'Reservation account activated',
        'reservation_account_id' => $accountId,
        'sweep' => $sweepResult,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    error_log("[ReservationAccountConfirmed] " . $e->getMessage());
}
