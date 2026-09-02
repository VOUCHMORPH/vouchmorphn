<?php
declare(strict_types=1);

/**
 * VouchMorph - Swap Details API
 * Returns complete detailed information for a specific swap
 *
 * FIXED: previous version SELECTed ht.requester, which does not exist
 * as a column on hold_transactions. createLocalHold() in SwapService.php
 * never writes a "requester" column — it only ever writes:
 *   hold_reference, swap_reference, participant_name, asset_type, amount,
 *   currency, status, source_details, destination_institution, metadata,
 *   placed_at, created_at, updated_at, source_institution
 * "requester" also isn't a key inside source_details JSON (see
 * createLocalHold's $sourceDetails array — it has user_id,
 * source_identifier, source_identifier_type, source_institution,
 * asset_type, original_payload, is_hooked, but no requester).
 *
 * This version drops the ht.requester column reference and instead
 * looks for a requester value inside source_details.original_payload
 * (where the raw incoming swap payload is stashed), falling back to
 * null rather than crashing if it's not there.
 *
 * FEATURES:
 *  - Complete swap details including source/destination
 *  - Full fee breakdown with distribution
 *  - Forex information
 *  - Cashout authorization details
 *  - Hold transaction details
 *  - User information
 *  - Transaction status history
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/Infrastructure/Crypto/SourceSecretCipher.php';

use Core\Database\DBConnection;
use Infrastructure\Crypto\SourceSecretCipher;

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Country-Code, X-Country");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function isValidApiKey(?string $providedKey): bool {
    $validKey = getenv('VOUCHMORPH_API_KEY') ?: '';

    if ($validKey === '') {
        error_log("[DETAILS] CRITICAL: VOUCHMORPH_API_KEY is not configured in this environment");
        return false;
    }

    if ($providedKey === null || $providedKey === '') {
        return false;
    }

    return hash_equals($validKey, $providedKey);
}

function getApiKeyFromRequest(): ?string {
    $headers = getallheaders();
    if ($headers) {
        $headersLower = array_change_key_case($headers, CASE_LOWER);

        if (isset($headersLower['x-api-key']) && !empty($headersLower['x-api-key'])) {
            return $headersLower['x-api-key'];
        }

        if (isset($headersLower['authorization']) && !empty($headersLower['authorization'])) {
            $auth = $headersLower['authorization'];
            if (strpos($auth, 'Bearer ') === 0) {
                return substr($auth, 7);
            }
            return $auth;
        }
    }

    if (isset($_SERVER['HTTP_X_API_KEY']) && !empty($_SERVER['HTTP_X_API_KEY'])) {
        return $_SERVER['HTTP_X_API_KEY'];
    }

    if (isset($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'Bearer ') === 0) {
            return substr($auth, 7);
        }
        return $auth;
    }

    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
        exit();
    }

    // ============================================================
    // AUTH: either a partner/institution X-API-Key (record-keeping,
    // certification suite) OR a logged-in user's own session — the
    // user dashboard calls this endpoint straight from the browser
    // with credentials:'include' and never had a partner key to send,
    // so it was always rejected with "Invalid API key" until this
    // session path was added.
    // ============================================================
    require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';
    \Application\Utils\SessionManager::start();
    $sessionUserId = \Application\Utils\SessionManager::isLoggedIn()
        ? (int)(\Application\Utils\SessionManager::getUser()['id']
            ?? \Application\Utils\SessionManager::getUser()['user_id'] ?? 0)
        : 0;

    $providedKey = getApiKeyFromRequest();
    $isPartnerAuth = isValidApiKey($providedKey);

    if (!$isPartnerAuth && !$sessionUserId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid API key']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON payload', 400);
    }

    $reference = $input['reference'] ?? null;

    if (!$reference) {
        throw new Exception('reference required', 400);
    }

    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $countryConfig = \Core\Config\LoadCountry::getConfig();
    $countryDefaultCurrency = $countryConfig['currency'] ?? null;

    // Get complete swap details from all related tables
    $sql = "
        SELECT
            -- Hold Transaction Details
            ht.hold_id,
            ht.swap_reference,
            ht.participant_name as source_institution,
            ht.destination_institution,
            ht.amount as hold_amount,
            ht.currency,
            ht.status as hold_status,
            ht.created_at as hold_created_at,
            ht.updated_at as hold_updated_at,
            ht.debited_at,
            ht.released_at,
            ht.source_details,
            ht.metadata,
            ht.asset_type,
            ht.source_institution as source_institution_name,
            
            -- Swap Request Details
            sr.swap_id,
            sr.swap_uuid,
            sr.from_currency,
            sr.to_currency,
            sr.amount as swap_amount,
            sr.status as swap_status,
            sr.created_at as swap_created_at,
            sr.source_country,
            sr.destination_country,
            sr.fee_breakdown,
            sr.forex_rate,
            sr.forex_fee_percent,
            sr.forex_fee_amount,
            sr.total_forex_fee,
            sr.expected_to_amount,
            sr.trade_metadata,
            sr.original_swap_ref,
            sr.user_id,
            sr.retry_count,
            
            -- Cashout Authorization Details
            ca.auth_id,
            ca.swap_code as voucher_number,
            ca.pin_code as atm_pin,
            ca.code_expiry as voucher_expiry,
            ca.status as cashout_status,
            ca.amount as cashout_amount,
            ca.fee_amount as cashout_fee,
            ca.completed_at as cashout_completed_at,
            ca.cashout_point,
            ca.cashout_provider,
            ca.client_phone,
            ca.source_wallet,
            ca.updated_at as cashout_updated_at,
            ca.user_id as cashout_user_id,

            -- Swap Transaction Details
            st.swap_transaction_id,
            st.amount as transaction_amount,
            st.status as transaction_status,
            st.from_account_details,
            st.to_account_details,
            st.created_at as transaction_created_at,
            st.updated_at as transaction_updated_at,
            st.transaction_id,
            st.ledger_entry_id,
            st.settlement_batch_id,
            st.error_message,
            st.retry_count as transaction_retry_count,
            
            -- Deposit Transaction Details (if deposit)
            dt.deposit_id,
            dt.transaction_reference as deposit_reference,
            dt.source_type,
            dt.source_account,
            dt.destination_type,
            dt.destination_account,
            dt.status as deposit_status,
            dt.completed_at as deposit_completed_at,
            dt.created_at as deposit_created_at,
            dt.updated_at as deposit_updated_at,
            dt.fee_amount as deposit_fee_amount,
            dt.user_id as deposit_user_id,

            -- Identity Swap Hold Details
            ish.identity_type,
            ish.identity_value,
            ish.otp_pin_encrypted,
            ish.status as identity_hold_status,
            ish.hold_expires_at as identity_expires_at,
            ish.claim_type,
            ish.created_by as identity_created_by
        FROM hold_transactions ht
        LEFT JOIN swap_requests sr ON ht.swap_reference = sr.swap_uuid
        LEFT JOIN cashout_authorizations ca ON ht.swap_reference = ca.swap_reference
        LEFT JOIN swap_transactions st ON sr.swap_id = st.swap_id
        LEFT JOIN deposit_transactions dt ON ht.swap_reference = dt.transaction_reference
        LEFT JOIN identity_swap_holds ish ON ht.swap_reference = ish.swap_reference
        WHERE ht.swap_reference = :reference
        ORDER BY ht.created_at DESC
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':reference' => $reference]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Swap not found: {$reference}", 404);
    }

    // Decode JSON fields
    $metadata = json_decode($row['metadata'] ?? '{}', true);
    $sourceDetails = json_decode($row['source_details'] ?? '{}', true);

    // A session-authenticated dashboard user may only see their own
    // swaps. Partner/institution API-key access is unrestricted (that's
    // the existing certified record-keeping behavior).
    if (!$isPartnerAuth) {
        $rowUserId = $row['user_id'] ?? $row['cashout_user_id'] ?? $row['deposit_user_id']
            ?? $sourceDetails['user_id'] ?? $row['identity_created_by'] ?? null;
        if ((int)$rowUserId !== $sessionUserId) {
            throw new Exception("Swap not found: {$reference}", 404);
        }
    }
    $feeBreakdown = json_decode($row['fee_breakdown'] ?? '{}', true);
    $fromAccountDetails = json_decode($row['from_account_details'] ?? '{}', true);
    $toAccountDetails = json_decode($row['to_account_details'] ?? '{}', true);
    $tradeMetadata = json_decode($row['trade_metadata'] ?? '{}', true);

    // Decrypt OTP pin if present
    $claimPin = null;
    if (!empty($row['identity_type']) && !empty($row['otp_pin_encrypted'])) {
        $claimPin = SourceSecretCipher::decrypt($row['otp_pin_encrypted']);
    }

    // requester isn't a hold_transactions column and isn't a top-level
    // source_details key — the closest thing available is whatever the
    // original inbound payload carried (stashed under original_payload
    // by createLocalHold()). Fall back to null rather than assume a
    // field exists.
    $originalPayload = $sourceDetails['original_payload'] ?? [];
    $holdRequester = $sourceDetails['requester']
        ?? $originalPayload['requester']
        ?? $originalPayload['performed_by']
        ?? null;

    // Determine currency
    $rowCurrency = $row['currency'] ?? null;
    if (!$rowCurrency) {
        $rowCurrency = $countryDefaultCurrency ?? 'UNKNOWN';
    }

    // Build complete swap details
    $swap = [
        // ============================================================
        // CORE SWAP INFORMATION
        // ============================================================
        'reference' => $row['swap_reference'],
        'swap_id' => $row['swap_id'],
        'swap_uuid' => $row['swap_uuid'],
        'swap_type' => $metadata['swap_type'] ?? 'STANDARD',
        'status' => $row['swap_status'] ?? $row['hold_status'] ?? 'unknown',
        'user_id' => $row['user_id'] ?? $sourceDetails['user_id'] ?? null,
        'retry_count' => (int)($row['retry_count'] ?? 0),
        
        // ============================================================
        // AMOUNTS & CURRENCY
        // ============================================================
        'amount' => (float)($row['swap_amount'] ?? $row['hold_amount'] ?? 0),
        'currency' => $rowCurrency,
        'from_currency' => $row['from_currency'] ?? $rowCurrency,
        'to_currency' => $row['to_currency'] ?? $rowCurrency,
        'expected_to_amount' => $row['expected_to_amount'] ? (float)$row['expected_to_amount'] : null,
        
        // ============================================================
        // INSTITUTIONS
        // ============================================================
        'source_institution' => $row['source_institution'] ?? $row['source_institution_name'],
        'destination_institution' => $row['destination_institution'],
        'source_identifier' => $sourceDetails['source_identifier'] ?? null,
        'destination_identifier' => $metadata['destination_identifier'] ?? null,
        'source_asset_type' => $sourceDetails['asset_type'] ?? $row['asset_type'] ?? null,
        'destination_asset_type' => $metadata['destination_asset_type'] ?? null,
        
        // ============================================================
        // COUNTRIES
        // ============================================================
        'source_country' => $row['source_country'] ?? 'BW',
        'destination_country' => $row['destination_country'] ?? 'BW',
        
        // ============================================================
        // DATES
        // ============================================================
        'created_at' => $row['swap_created_at'] ?? $row['hold_created_at'],
        'updated_at' => $row['hold_updated_at'] ?? $row['swap_created_at'],
        'completed_at' => $row['cashout_completed_at'] ?? $row['deposit_completed_at'] ?? $row['debited_at'],
        
        // ============================================================
        // FEES & FOREX
        // ============================================================
        'fee' => (float)($row['cashout_fee'] ?? $row['deposit_fee_amount'] ?? 0),
        'fee_breakdown' => $feeBreakdown ?: null,
        'forex_rate' => $row['forex_rate'] ? (float)$row['forex_rate'] : null,
        'forex_fee_percent' => $row['forex_fee_percent'] ? (float)$row['forex_fee_percent'] : null,
        'forex_fee_amount' => $row['forex_fee_amount'] ? (float)$row['forex_fee_amount'] : null,
        'total_forex_fee' => $row['total_forex_fee'] ? (float)$row['total_forex_fee'] : null,
        
        // ============================================================
        // HOLD TRANSACTION DETAILS
        // ============================================================
        'hold' => [
            'hold_id' => $row['hold_id'],
            'hold_reference' => $row['swap_reference'],
            'amount' => (float)$row['hold_amount'],
            'currency' => $rowCurrency,
            'status' => $row['hold_status'],
            'asset_type' => $row['asset_type'] ?? $sourceDetails['asset_type'],
            'created_at' => $row['hold_created_at'],
            'updated_at' => $row['hold_updated_at'],
            'debited_at' => $row['debited_at'],
            'released_at' => $row['released_at'],
            'requester' => $holdRequester,
        ],
        
        // ============================================================
        // CASHOUT AUTHORIZATION DETAILS
        // ============================================================
        'cashout' => $row['voucher_number'] ? [
            'auth_id' => $row['auth_id'],
            'voucher_number' => $row['voucher_number'],
            'atm_pin' => $row['atm_pin'],
            'expiry' => $row['voucher_expiry'],
            'status' => $row['cashout_status'],
            'amount' => (float)($row['cashout_amount'] ?? 0),
            'fee' => (float)($row['cashout_fee'] ?? 0),
            'cashout_point' => $row['cashout_point'],
            'cashout_provider' => $row['cashout_provider'],
            'client_phone' => $row['client_phone'],
            'source_wallet' => $row['source_wallet'],
            'created_at' => $row['hold_created_at'],
            'updated_at' => $row['cashout_updated_at'],
            'completed_at' => $row['cashout_completed_at'],
        ] : null,
        
        // ============================================================
        // SWAP TRANSACTION DETAILS
        // ============================================================
        'transaction' => $row['swap_transaction_id'] ? [
            'swap_transaction_id' => $row['swap_transaction_id'],
            'amount' => (float)($row['transaction_amount'] ?? 0),
            'status' => $row['transaction_status'],
            'from_account' => $fromAccountDetails,
            'to_account' => $toAccountDetails,
            'created_at' => $row['transaction_created_at'],
            'updated_at' => $row['transaction_updated_at'],
            'transaction_id' => $row['transaction_id'],
            'ledger_entry_id' => $row['ledger_entry_id'],
            'settlement_batch_id' => $row['settlement_batch_id'],
            'error_message' => $row['error_message'],
            'retry_count' => (int)($row['transaction_retry_count'] ?? 0),
        ] : null,
        
        // ============================================================
        // DEPOSIT DETAILS (if applicable)
        // ============================================================
        'deposit' => $row['deposit_id'] ? [
            'deposit_id' => $row['deposit_id'],
            'reference' => $row['deposit_reference'],
            'source_type' => $row['source_type'],
            'source_account' => $row['source_account'],
            'destination_type' => $row['destination_type'],
            'destination_account' => $row['destination_account'],
            'status' => $row['deposit_status'],
            'fee' => (float)($row['deposit_fee_amount'] ?? 0),
            'created_at' => $row['deposit_created_at'],
            'updated_at' => $row['deposit_updated_at'],
            'completed_at' => $row['deposit_completed_at'],
        ] : null,

        // ============================================================
        // IDENTITY SWAP HOLD DETAILS (if applicable)
        // ============================================================
        'identity' => $row['identity_type'] ? [
            'identity_type' => $row['identity_type'],
            'identity_value' => $row['identity_value'],
            'claim_pin' => $claimPin,
            'status' => $row['identity_hold_status'],
            'expires_at' => $row['identity_expires_at'],
            'claim_type' => $row['claim_type'],
        ] : null,
        
        // ============================================================
        // REVENUE DISTRIBUTION
        // ============================================================
        'distribution' => $feeBreakdown['revenue_split'] ?? $metadata['distribution'] ?? null,
        'destination_split' => $feeBreakdown['destination_split'] ?? $metadata['destination_split'] ?? null,
        
        // ============================================================
        // SOURCE DETAILS
        // ============================================================
        'source_details' => $sourceDetails,
        
        // ============================================================
        // METADATA
        // ============================================================
        'metadata' => $metadata,
        'trade_metadata' => $tradeMetadata,
        'original_swap_ref' => $row['original_swap_ref'],

        // ============================================================
        // FLAT COPY FOR DASHBOARD COMPATIBILITY
        // ============================================================
        'claim_pin' => $claimPin,
    ];

    // Remove null values for cleaner output
    $swap = array_filter($swap, function($value) {
        return $value !== null;
    });

    echo json_encode([
        'success' => true,
        'swap' => $swap
    ]);

} catch (\Throwable $e) {
    // Broadened from catch(Exception) — see SwapService_throwable_fix.php
    // for why a plain \Error (e.g. another undefined-column typo like
    // this one, or a missing method elsewhere) should still produce a
    // clean JSON error response instead of a raw 500.
    $code = ($e instanceof Exception && $e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

    error_log("[DETAILS] Error (" . get_class($e) . "): " . $e->getMessage());
}
