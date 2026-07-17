<?php
declare(strict_types=1);

/**
 * VouchMorph - Swap History API
 * Returns swap history for a user
 *
 * PATCHED:
 *  - Exact API key comparison via hash_equals() instead of scraping
 *    every env var 32+ chars long as a "valid" key.
 *  - Added ht.source_details to the SELECT list — it was being read
 *    from $row after the query but was never actually selected, so
 *    source_identifier was always null.
 *  - No more silent '?? "BWP"' currency fallback — if a swap row
 *    somehow has no currency and the country config has none either,
 *    that's an error worth surfacing, not a silent BWP default.
 */
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use Core\Database\DBConnection;

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

/**
 * Validates the provided key against the single configured
 * VOUCHMORPH_API_KEY using a constant-time comparison.
 */
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

    $providedKey = getApiKeyFromRequest();

    if (!isValidApiKey($providedKey)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid API key']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON payload', 400);
    }

    $userId = $input['user_id'] ?? 0;
    $limit = min((int)($input['limit'] ?? 50), 100);

    if (!$userId) {
        throw new Exception('user_id required', 400);
    }

    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get country config for currency (used only as a last-resort
    // display fallback if an individual row somehow has no currency
    // of its own — see the per-row check below).
    $countryConfig = \Core\Config\LoadCountry::getConfig();
    $countryDefaultCurrency = $countryConfig['currency'] ?? null;

    // Query swap history from hold_transactions and related tables
    // PATCHED: added ht.source_details — this was read from $row
    // further down but was never actually part of the SELECT list,
    // so source_identifier was always coming back null.
    $sql = "
        SELECT
            ht.hold_id,
            ht.swap_reference,
            ht.participant_name as source_institution,
            ht.destination_institution,
            ht.amount,
            ht.currency,
            ht.status,
            ht.created_at,
            ht.updated_at,
            ht.source_details,
            ht.metadata,
            ca.swap_code,
            ca.pin_code as atm_code,
            ca.code_expiry,
            ca.status as cashout_status,
            ca.amount as cashout_amount,
            ca.fee_amount,
            ca.completed_at
        FROM hold_transactions ht
        LEFT JOIN cashout_authorizations ca ON ht.swap_reference = ca.swap_reference
        WHERE ht.source_details::text LIKE :user_search
        ORDER BY ht.created_at DESC
        LIMIT :limit
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_search', '%"user_id":' . $userId . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $swaps = [];
    foreach ($results as $row) {
        $metadata = json_decode($row['metadata'] ?? '{}', true);
        $sourceDetails = json_decode($row['source_details'] ?? '{}', true);

        // PATCHED: previously '?? $currency' where $currency was
        // unconditionally '$countryConfig["currency"] ?? "BWP"'.
        // Now: prefer the row's own currency; if that's missing,
        // fall back to the country's currency ONLY if the country
        // config actually has one; otherwise flag it rather than
        // silently stamping "BWP" on a swap that may not be BWP.
        $rowCurrency = $row['currency'] ?? null;
        if (!$rowCurrency) {
            if ($countryDefaultCurrency) {
                error_log("[HISTORY] Warning: swap {$row['swap_reference']} has no currency on the row, using country default {$countryDefaultCurrency}");
                $rowCurrency = $countryDefaultCurrency;
            } else {
                error_log("[HISTORY] Warning: swap {$row['swap_reference']} has no currency on the row AND no country default is configured");
                $rowCurrency = 'UNKNOWN';
            }
        }

        $swap = [
            'reference' => $row['swap_reference'],
            'source_institution' => $row['source_institution'],
            'destination_institution' => $row['destination_institution'],
            'amount' => (float)$row['amount'],
            'currency' => $rowCurrency,
            'status' => $row['status'] ?? 'unknown',
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'fee' => (float)($row['fee_amount'] ?? 0),
            'swap_code' => $row['swap_code'] ?? null,
            'atm_code' => $row['atm_code'] ?? null,
            'code_expiry' => $row['code_expiry'] ?? null,
        ];

        // Add destination currency if available
        if (isset($metadata['destination_currency'])) {
            $swap['destination_currency'] = $metadata['destination_currency'];
        }

        // Add swap type from metadata
        if (isset($metadata['swap_type'])) {
            $swap['swap_type'] = $metadata['swap_type'];
        }

        // Add source identifier
        if (isset($sourceDetails['source_identifier'])) {
            $swap['source_identifier'] = $sourceDetails['source_identifier'];
        }

        // Add destination identifier from metadata
        if (isset($metadata['destination_identifier'])) {
            $swap['destination_identifier'] = $metadata['destination_identifier'];
        }

        // Add fee breakdown if available
        if (isset($metadata['fee_breakdown'])) {
            $swap['fee_breakdown'] = $metadata['fee_breakdown'];
        }

        // Add exchange rate if forex was applied
        if (isset($metadata['exchange_rate'])) {
            $swap['exchange_rate'] = $metadata['exchange_rate'];
        }
        if (isset($metadata['net_amount_destination'])) {
            $swap['net_amount_destination'] = $metadata['net_amount_destination'];
        }

        $swaps[] = $swap;
    }

    echo json_encode([
        'success' => true,
        'data' => $swaps,
        'total' => count($swaps),
        'user_id' => $userId
    ]);

} catch (Exception $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    http_response_code($code);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

    error_log("[HISTORY] Error: " . $e->getMessage());
}
