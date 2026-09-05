<?php
declare(strict_types=1);
/**
 * VouchMorph - Swap History API
 * Returns complete swap history for a user dashboard
 *
 * FIXED: the previous version filtered with
 *   WHERE ht.source_details::text LIKE '%"user_id":123%'
 * This NEVER matched anything, because Postgres re-serializes jsonb on
 * cast-to-text with a space after each colon ({"user_id": 123, ...}),
 * while the pattern assumed PHP's no-space json_encode() format
 * ({"user_id":123,...}). It also had a substring bug where user 1 would
 * match users 10, 11, 100, etc.
 *
 * This version filters on the real user_id COLUMNS in swap_requests /
 * cashout_authorizations / deposit_transactions (added when
 * populateSwapRequest / populateCashoutAuthorization / populateDepositTransaction
 * were fixed to bind :user_id directly), with a jsonb containment
 * fallback on hold_transactions.source_details for any hold-only rows
 * that never got a matching tracking-table row written.
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
        error_log("[HISTORY] CRITICAL: VOUCHMORPH_API_KEY is not configured in this environment");
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

    // A session-authenticated dashboard user can only ever see their
    // own history, regardless of what user_id the request body claims.
    $userId = $isPartnerAuth ? (int)($input['user_id'] ?? 0) : $sessionUserId;
    $limit = min((int)($input['limit'] ?? 50), 100);
    $offset = max((int)($input['offset'] ?? 0), 0);
    $swapType = $input['swap_type'] ?? null;
    $status = $input['status'] ?? null;

    if (!$userId) {
        throw new Exception('user_id required', 400);
    }

    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $countryConfig = \Core\Config\LoadCountry::getConfig();
    $countryDefaultCurrency = $countryConfig['currency'] ?? null;

    // ============================================================
    // FIXED WHERE CLAUSE:
    // Filter on real user_id columns first (fast, indexable, correct).
    // Fall back to jsonb containment (NOT text LIKE) on
    // hold_transactions.source_details for any hold-only rows that
    // never got a corresponding swap_requests/cashout_authorizations/
    // deposit_transactions row (e.g. failed before populateTrackingTables
    // ran, or a legacy record from before user_id columns existed).
    // ============================================================
    $sql = "
        SELECT
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
            sr.user_id,

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
            ca.user_id as cashout_user_id,

            dt.deposit_id,
            dt.transaction_reference as deposit_reference,
            dt.source_type,
            dt.source_account,
            dt.destination_type,
            dt.destination_account,
            dt.status as deposit_status,
            dt.completed_at as deposit_completed_at,
            dt.user_id as deposit_user_id,

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
        LEFT JOIN deposit_transactions dt ON ht.swap_reference = dt.transaction_reference
        LEFT JOIN identity_swap_holds ish ON ht.swap_reference = ish.swap_reference
        WHERE (
            sr.user_id = :user_id_1
            OR ca.user_id = :user_id_2
            OR dt.user_id = :user_id_3
            OR ht.source_details @> jsonb_build_object('user_id', :user_id_4::int)
            OR ish.created_by = :user_id_5
        )
    ";

    $params = [
        ':user_id_1' => $userId,
        ':user_id_2' => $userId,
        ':user_id_3' => $userId,
        ':user_id_4' => $userId,
        ':user_id_5' => $userId,
    ];

    if ($swapType) {
        $sql .= " AND ht.metadata->>'swap_type' = :swap_type";
        $params[':swap_type'] = $swapType;
    }

    if ($status) {
        $sql .= " AND ht.status = :status";
        $params[':status'] = $status;
    }

    $sql .= " ORDER BY ht.created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total count for pagination — same WHERE logic, no LIMIT/OFFSET
    $countSql = "
        SELECT COUNT(*) as total
        FROM hold_transactions ht
        LEFT JOIN swap_requests sr ON ht.swap_reference = sr.swap_uuid
        LEFT JOIN cashout_authorizations ca ON ht.swap_reference = ca.swap_reference
        LEFT JOIN deposit_transactions dt ON ht.swap_reference = dt.transaction_reference
        LEFT JOIN identity_swap_holds ish ON ht.swap_reference = ish.swap_reference
        WHERE (
            sr.user_id = :user_id_1
            OR ca.user_id = :user_id_2
            OR dt.user_id = :user_id_3
            OR ht.source_details @> jsonb_build_object('user_id', :user_id_4::int)
            OR ish.created_by = :user_id_5
        )
    ";
    $countParams = [
        ':user_id_1' => $userId,
        ':user_id_2' => $userId,
        ':user_id_3' => $userId,
        ':user_id_4' => $userId,
        ':user_id_5' => $userId,
    ];
    if ($swapType) {
        $countSql .= " AND ht.metadata->>'swap_type' = :swap_type";
        $countParams[':swap_type'] = $swapType;
    }
    if ($status) {
        $countSql .= " AND ht.status = :status";
        $countParams[':status'] = $status;
    }

    $countStmt = $db->prepare($countSql);
    foreach ($countParams as $key => $value) {
        $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalCount = (int)$countStmt->fetchColumn();

    $swaps = [];
    foreach ($results as $row) {
        $metadata = json_decode($row['metadata'] ?? '{}', true);
        $sourceDetails = json_decode($row['source_details'] ?? '{}', true);
        $feeBreakdown = json_decode($row['fee_breakdown'] ?? '{}', true);

        $claimPin = null;
        if (!empty($row['identity_type']) && !empty($row['otp_pin_encrypted'])) {
            $claimPin = SourceSecretCipher::decrypt($row['otp_pin_encrypted']);
        }

        $rowCurrency = $row['currency'] ?? null;
        if (!$rowCurrency) {
            $rowCurrency = $countryDefaultCurrency ?? 'UNKNOWN';
        }

        $swapTypeFromMeta = $metadata['swap_type'] ?? 'STANDARD';

        $statusDisplay = $row['hold_status'] ?? 'unknown';
        if ($row['swap_status'] === 'completed') {
            $statusDisplay = 'completed';
        } elseif ($row['cashout_status'] === 'COMPLETED') {
            $statusDisplay = 'completed';
        }

        $resolvedUserId = $row['user_id'] ?? $row['cashout_user_id'] ?? $row['deposit_user_id'] ?? $sourceDetails['user_id'] ?? $row['identity_created_by'] ?? null;

        $swap = [
            'reference' => $row['swap_reference'],
            'swap_id' => $row['swap_id'],
            'swap_uuid' => $row['swap_uuid'],
            'swap_type' => $swapTypeFromMeta,
            'status' => $statusDisplay,
            'hold_status' => $row['hold_status'],
            'swap_status' => $row['swap_status'],

            'amount' => (float)($row['swap_amount'] ?? $row['hold_amount'] ?? 0),
            'currency' => $rowCurrency,
            'from_currency' => $row['from_currency'] ?? $rowCurrency,
            'to_currency' => $row['to_currency'] ?? $rowCurrency,
            'fee' => (float)($row['cashout_fee'] ?? 0),

            'source_institution' => $row['source_institution'] ?? $row['source_institution_name'],
            'destination_institution' => $row['destination_institution'],
            'source_identifier' => $sourceDetails['source_identifier'] ?? null,
            'destination_identifier' => $metadata['destination_identifier'] ?? null,
            'source_asset_type' => $sourceDetails['asset_type'] ?? $row['asset_type'] ?? null,
            'destination_asset_type' => $metadata['destination_asset_type'] ?? null,

            'source_country' => $row['source_country'] ?? 'BW',
            'destination_country' => $row['destination_country'] ?? 'BW',

            'created_at' => $row['swap_created_at'] ?? $row['hold_created_at'],
            'updated_at' => $row['hold_updated_at'] ?? $row['swap_created_at'],
            'completed_at' => $row['cashout_completed_at'] ?? $row['deposit_completed_at'] ?? $row['debited_at'],

            'voucher_number' => $row['voucher_number'],
            'atm_pin' => $row['atm_pin'],
            'voucher_expiry' => $row['voucher_expiry'],
            'cashout_status' => $row['cashout_status'],
            'cashout_point' => $row['cashout_point'],
            'cashout_provider' => $row['cashout_provider'],
            'client_phone' => $row['client_phone'],

            'fee_breakdown' => $feeBreakdown ?: null,
            'cashout_fee' => (float)($row['cashout_fee'] ?? 0),

            'forex_rate' => $row['forex_rate'] ? (float)$row['forex_rate'] : null,
            'forex_fee_percent' => $row['forex_fee_percent'] ? (float)$row['forex_fee_percent'] : null,
            'forex_fee_amount' => $row['forex_fee_amount'] ? (float)$row['forex_fee_amount'] : null,
            'total_forex_fee' => $row['total_forex_fee'] ? (float)$row['total_forex_fee'] : null,
            'expected_to_amount' => $row['expected_to_amount'] ? (float)$row['expected_to_amount'] : null,

            'hold_id' => $row['hold_id'],
            'hold_amount' => (float)$row['hold_amount'],
            'hold_created_at' => $row['hold_created_at'],
            'debited_at' => $row['debited_at'],
            'released_at' => $row['released_at'],

            'deposit_reference' => $row['deposit_reference'],
            'deposit_status' => $row['deposit_status'],
            'source_type' => $row['source_type'],
            'source_account' => $row['source_account'],
            'destination_type' => $row['destination_type'],
            'destination_account' => $row['destination_account'],

            'metadata' => $metadata,
            'source_details' => $sourceDetails,

            'user_id' => $resolvedUserId,

            'identity_type' => $row['identity_type'] ?? null,
            'identity_value' => $row['identity_value'] ?? null,
            'claim_pin' => $claimPin,
            'claim_type' => $row['claim_type'] ?? null,
        ];

        $swap = array_filter($swap, function($value) {
            return $value !== null;
        });

        $swaps[] = $swap;
    }

    // ============================================================
    // FIX: multi-source/card-hook pool swaps never place a fresh hold
    // in THIS request — they draw on one or more holds placed earlier
    // — so they never get a row in hold_transactions, and the query
    // above (built entirely around exactly one hold per swap) could
    // never surface them no matter how its WHERE clause was written.
    // PoolCoordinator now writes a real swap_requests row for these
    // (see PoolCoordinator::completeDeferredPool()), so pull those in
    // separately here rather than reshape the older, more complex
    // query above — which also drives cashout/identity-claim
    // decryption for unrelated transaction types — into an untested
    // SQL UNION with no live database available to verify column
    // types against.
    //
    // Known approximation: this second source is paginated
    // independently (same limit/offset) before the two are merged and
    // re-sorted below, rather than through one globally correct
    // OFFSET across both — exact for the common first-page case,
    // an approximation on deeper pages mixing many pool swaps with
    // many regular ones.
    // ============================================================
    $poolSql = "
        SELECT swap_id, swap_uuid, from_currency, to_currency, amount, status,
               created_at, completed_at, source_country, destination_country,
               fee_breakdown, forex_rate, forex_fee_percent, forex_fee_amount,
               total_forex_fee, expected_to_amount, metadata, destination_details
        FROM swap_requests
        WHERE user_id = :user_id
          AND metadata->>'swap_type' = 'MULTI_SOURCE'
          AND NOT EXISTS (
              SELECT 1 FROM hold_transactions ht2 WHERE ht2.swap_reference = swap_requests.swap_uuid
          )
    ";
    $poolParams = [':user_id' => $userId];
    if ($swapType) {
        $poolSql .= " AND metadata->>'swap_type' = :swap_type";
        $poolParams[':swap_type'] = $swapType;
    }
    if ($status) {
        $poolSql .= " AND status = :status";
        $poolParams[':status'] = $status;
    }
    $poolSql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

    $poolStmt = $db->prepare($poolSql);
    foreach ($poolParams as $key => $value) {
        $poolStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $poolStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $poolStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $poolStmt->execute();
    $poolRows = $poolStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($poolRows as $prow) {
        $pMetadata = json_decode($prow['metadata'] ?? '{}', true) ?: [];
        $pDestDetails = json_decode($prow['destination_details'] ?? '{}', true) ?: [];
        $pFeeBreakdown = json_decode($prow['fee_breakdown'] ?? '{}', true) ?: [];
        $pCurrency = $prow['from_currency'] ?? $countryDefaultCurrency ?? 'UNKNOWN';

        $poolSwap = [
            'reference' => $prow['swap_uuid'],
            'swap_id' => $prow['swap_id'],
            'swap_uuid' => $prow['swap_uuid'],
            'swap_type' => $pMetadata['swap_type'] ?? 'MULTI_SOURCE',
            'status' => $prow['status'],
            'amount' => (float)($prow['amount'] ?? 0),
            'currency' => $pCurrency,
            'from_currency' => $prow['from_currency'] ?? $pCurrency,
            'to_currency' => $prow['to_currency'] ?? $pCurrency,
            'source_institution' => $pMetadata['source_institution'] ?? null,
            'destination_institution' => $pDestDetails['institution'] ?? null,
            'destination_identifier' => $pDestDetails['identifier'] ?? null,
            'destination_asset_type' => $pDestDetails['asset_type'] ?? null,
            'source_country' => $prow['source_country'] ?? 'BW',
            'destination_country' => $prow['destination_country'] ?? 'BW',
            'created_at' => $prow['created_at'],
            'updated_at' => $prow['created_at'],
            'completed_at' => $prow['completed_at'],
            'fee_breakdown' => $pFeeBreakdown ?: null,
            'forex_rate' => $prow['forex_rate'] ? (float)$prow['forex_rate'] : null,
            'forex_fee_percent' => $prow['forex_fee_percent'] ? (float)$prow['forex_fee_percent'] : null,
            'forex_fee_amount' => $prow['forex_fee_amount'] ? (float)$prow['forex_fee_amount'] : null,
            'total_forex_fee' => $prow['total_forex_fee'] ? (float)$prow['total_forex_fee'] : null,
            'expected_to_amount' => $prow['expected_to_amount'] ? (float)$prow['expected_to_amount'] : null,
            'metadata' => $pMetadata,
            'user_id' => $userId,
        ];

        $poolSwap = array_filter($poolSwap, function ($value) {
            return $value !== null;
        });

        $swaps[] = $poolSwap;
    }

    if (!empty($poolRows)) {
        // Merge newest-first and enforce the page size actually asked
        // for — each source was already capped/offset independently
        // above, so this can only ever trim the combined set, never
        // need to pad it back out.
        usort($swaps, fn($a, $b) => strtotime($b['created_at'] ?? 'now') <=> strtotime($a['created_at'] ?? 'now'));
        $swaps = array_slice($swaps, 0, $limit);

        $poolCountSql = "
            SELECT COUNT(*) as total
            FROM swap_requests
            WHERE user_id = :user_id
              AND metadata->>'swap_type' = 'MULTI_SOURCE'
              AND NOT EXISTS (
                  SELECT 1 FROM hold_transactions ht2 WHERE ht2.swap_reference = swap_requests.swap_uuid
              )
        ";
        $poolCountParams = [':user_id' => $userId];
        if ($swapType) {
            $poolCountSql .= " AND metadata->>'swap_type' = :swap_type";
            $poolCountParams[':swap_type'] = $swapType;
        }
        if ($status) {
            $poolCountSql .= " AND status = :status";
            $poolCountParams[':status'] = $status;
        }
        $poolCountStmt = $db->prepare($poolCountSql);
        foreach ($poolCountParams as $key => $value) {
            $poolCountStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $poolCountStmt->execute();
        $totalCount += (int)$poolCountStmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'data' => $swaps,
        'pagination' => [
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'returned' => count($swaps)
        ],
        'user_id' => $userId,
        'filters' => [
            'swap_type' => $swapType,
            'status' => $status
        ]
    ]);

} catch (\Throwable $e) {
    // Broadened from catch(Exception) so PHP Errors (e.g. undefined
    // method / TypeError) get a proper JSON error response instead of
    // a raw 500 with no body — see SwapService_throwable_fix.php for
    // why this matters for write-side consistency too.
    $code = ($e instanceof Exception && $e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    error_log("[HISTORY] Error (" . get_class($e) . "): " . $e->getMessage());
}
