<?php
declare(strict_types=1);

/**
 * VouchMorph - Swap Preview API
 * Calculates fees and returns preview WITHOUT executing
 * NOW WITH MULTI-SOURCE SUPPORT
 *
 * PATCHED:
 *  - Exact API key comparison via hash_equals() instead of scraping
 *    every env var 32+ chars long as a "valid" key.
 *  - No more silent '?? "BWP"' currency fallback — if the country
 *    config doesn't specify a currency, that's an error, not a
 *    quiet default that masks a config problem.
 *  - USER_SPECIFIED contribution strategy now keys per-source amounts
 *    by institution+identifier instead of institution alone. Keying
 *    by institution alone silently collapsed multiple sources at the
 *    same institution to a single value — confirmed live: two
 *    distinct ABSA sources with amounts 30 and 20 both resolved to
 *    the same (second, overwriting) value on lookup, producing a
 *    reported total of 40 instead of the requested 50. See matching
 *    fix in ContributionCalculator::calculateUserSpecifiedFlexible(),
 *    which must use the same composite key on the reading side.
 *  - NEW: single-source swaps (no 'sources' array — the DEPOSIT /
 *    CASHOUT / IDENTITY / MULTI_DESTINATION shape) now actually check
 *    the source's balance before returning a preview. Previously the
 *    ONLY place this file ever called GenericBankClient::getBalance()
 *    was inside the `if ($isMultiSource)` block — a single-source
 *    swap skips that block entirely and went straight to fee
 *    calculation, so a source with a real balance of 0.00 returned
 *    success:true with no rejection at all. Confirmed live with a
 *    dedicated zero-balance test account. This mirrors the same
 *    balance-fetch pattern already used for multi-source, and throws
 *    the same style of exception on insufficiency.
 *  - Multi-source swaps now use MultiSourceFeeCalculator instead of
 *    FeeService for accurate per-source fixed-cut fee calculation.
 */
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use Core\Database\DBConnection;
use Domain\Services\ContributionCalculator;
use Infrastructure\Banks\GenericBankClient;

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
        error_log("[PREVIEW] CRITICAL: VOUCHMORPH_API_KEY is not configured in this environment");
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

    // ============================================
    // SESSION VALIDATION
    // ============================================
    require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';
    \Application\Utils\SessionManager::start();
    if (!\Application\Utils\SessionManager::isLoggedIn() || !\Application\Utils\SessionManager::isUser()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }
    $sessionUserId = (int)(\Application\Utils\SessionManager::getUser()['id']
        ?? \Application\Utils\SessionManager::getUser()['user_id'] ?? 0);
    if (!$sessionUserId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Could not resolve user from session']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input)) {
        throw new Exception('Invalid JSON payload', 400);
    }

    // Keys only: the payload carries wallet and voucher PINs, which used to
    // be written to the log in full.
    error_log("[PREVIEW] Input keys: " . implode(', ', array_keys($input)));

    $countryConfig = \Core\Config\LoadCountry::getConfig();

    if (!$countryConfig) {
        throw new Exception('Country configuration not found', 500);
    }

    if (empty($countryConfig['currency'])) {
        error_log("[PREVIEW] CRITICAL: Resolved country config has no 'currency' field: " . json_encode($countryConfig));
        throw new Exception('Country configuration is missing a currency field', 500);
    }
    $currency = $countryConfig['currency'];
    $participants = $countryConfig['participants'] ?? [];

    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // A live balance is only ever looked up for the signed-in customer's own
    // verified sources (SourceOwnershipGuard). This used to fetch and return
    // the balance of whatever account number it was sent - a balance lookup
    // on anyone's account. A quote that names no source still prices.
    $namesASource = (!empty($input['sources']) && is_array($input['sources']))
        || (!empty($input['from_institution'] ?? $input['source_institution'] ?? null) && !empty($input['source_identifier']));
    if ($namesASource) {
        try {
            $input = \Domain\Services\SourceOwnershipGuard::forCountry($db, $countryConfig)->securePayload($sessionUserId, $input);
        } catch (\Domain\Services\SourceOwnershipException $e) {
            throw new Exception($e->getMessage(), 403);
        }
    }

    $isMultiSource = isset($input['sources']) && is_array($input['sources']) && count($input['sources']) > 1;
    $swapType = $input['swap_type'] ?? 'CASHOUT';
    $amount = (float)($input['amount'] ?? 0);
    $sourceInst = $input['from_institution'] ?? $input['source_institution'] ?? null;
    $destInst = $input['to_institution'] ?? $input['destination_institution'] ?? null;
    $sourceCurrency = $input['currency'] ?? $currency;
    $destinationCurrency = $input['destination_currency'] ?? $sourceCurrency;
    $strategy = $input['contribution_strategy'] ?? 'RATIO';

    $sourceContributions = [];
    $sourceBalances = [];
    $totalAvailableBalance = 0;
    $multiSourceBreakdown = null;

    // Populated by the single-source branch below when it runs, so the
    // same has_sufficient_balance data can be exposed on the response.
    $singleSourceBalanceCheck = null;

    if ($isMultiSource) {
        error_log("[PREVIEW] Multi-Source detected: " . count($input['sources']) . " sources");

        $sources = $input['sources'];
        $totalRequested = $amount;

        foreach ($sources as $idx => $source) {
            $inst = $source['institution'] ?? '';
            $identifier = $source['identifier'] ?? '';
            $assetType = $source['asset_type'] ?? 'ACCOUNT';
            $sourceAmount = (float)($source['amount'] ?? 0);

            if (empty($inst)) {
                throw new Exception("Source " . ($idx + 1) . " missing institution");
            }

            if (empty($identifier)) {
                throw new Exception("Source " . ($idx + 1) . " missing identifier");
            }

            $participant = null;
            foreach ($participants as $code => $p) {
                if (strtoupper($code) === strtoupper($inst)) {
                    $participant = $p;
                    break;
                }
            }

            if (!$participant) {
                throw new Exception("Participant not found: {$inst}");
            }

            $balance = 0;
            $balanceError = null;

            try {
                $bankClient = new GenericBankClient($participant);

                $balancePayload = [
                    'action' => 'GET_BALANCE',
                    'asset_type' => $assetType,
                    'source_identifier' => $identifier,
                    'currency' => $sourceCurrency,
                    'reference' => 'BALANCE_CHECK_' . time() . '_' . $idx
                ];

                $balanceResult = $bankClient->getBalance($balancePayload);

                if ($balanceResult['success'] ?? false) {
                    $balance = (float)($balanceResult['data']['balance'] ?? 0);
                    error_log("[PREVIEW] Source {$inst} balance: {$balance} {$sourceCurrency}");
                } else {
                    $balanceError = $balanceResult['message'] ?? 'Unknown error';
                    error_log("[PREVIEW] Failed to get balance for {$inst}: {$balanceError}");
                }
            } catch (Exception $e) {
                $balanceError = $e->getMessage();
                error_log("[PREVIEW] Balance check exception for {$inst}: " . $e->getMessage());
            }

            $sourceBalances[] = [
                'index' => $idx,
                'institution' => $inst,
                'identifier' => $identifier,
                'asset_type' => $assetType,
                'requested_amount' => $sourceAmount,
                'available_balance' => $balance,
                'balance_error' => $balanceError,
                'balance_checked' => $balanceError === null,
                'has_sufficient_balance' => $balance >= $sourceAmount
            ];

            $totalAvailableBalance += $balance;
        }

        $calculator = new ContributionCalculator();

        try {
            $calculatorSources = [];
            foreach ($sourceBalances as $sb) {
                $calculatorSources[] = [
                    'institution' => $sb['institution'],
                    'asset_type' => $sb['asset_type'],
                    'identifier' => $sb['identifier'],
                    'available_balance' => $sb['available_balance']
                ];
            }

            $userSpecified = null;
            if ($strategy === 'USER_SPECIFIED') {
                $userSpecified = [];
                foreach ($sources as $idx => $source) {
                    $compositeKey = ($source['institution'] ?? '') . '|' . ($source['identifier'] ?? '');
                    $userSpecified[$compositeKey] = (float)($source['amount'] ?? 0);
                }
            }

            $contributions = $calculator->calculateContributions(
                $totalRequested,
                $calculatorSources,
                $strategy,
                $userSpecified
            );

            $sourceContributions = [];
            foreach ($contributions as $idx => $contribution) {
                $source = $contribution['source'];
                $sourceBal = $sourceBalances[$idx] ?? [];

                $sourceContributions[] = [
                    'source_index' => $idx + 1,
                    'institution' => $source['institution'],
                    'asset_type' => $source['asset_type'],
                    'identifier' => $source['identifier'],
                    'available_balance' => $sourceBal['available_balance'] ?? 0,
                    'contribution_amount' => $contribution['actual_amount'],
                    'requested_amount' => $contribution['requested_amount'] ?? $contribution['actual_amount'],
                    'percentage_of_total' => ($totalRequested > 0) ? round(($contribution['actual_amount'] / $totalRequested) * 100, 2) : 0,
                    'percentage_of_balance' => ($sourceBal['available_balance'] > 0) ? round(($contribution['actual_amount'] / $sourceBal['available_balance']) * 100, 2) : 0,
                    'has_sufficient_balance' => $sourceBal['has_sufficient_balance'] ?? false,
                    'balance_error' => $sourceBal['balance_error'] ?? null
                ];
            }

            $totalContributions = array_sum(array_column($sourceContributions, 'contribution_amount'));

            if (abs($totalContributions - $totalRequested) > 0.01) {
                error_log("[PREVIEW] Warning: Contributions total ({$totalContributions}) doesn't match requested ({$totalRequested})");
                if (count($sourceContributions) > 0) {
                    $lastIdx = count($sourceContributions) - 1;
                    $adjustment = $totalRequested - $totalContributions;
                    $sourceContributions[$lastIdx]['contribution_amount'] += $adjustment;
                    $sourceContributions[$lastIdx]['percentage_of_total'] = round(($sourceContributions[$lastIdx]['contribution_amount'] / $totalRequested) * 100, 2);
                    $totalContributions = array_sum(array_column($sourceContributions, 'contribution_amount'));
                }
            }

            $multiSourceBreakdown = [
                'strategy' => $strategy,
                'total_requested' => $totalRequested,
                'total_available_balance' => $totalAvailableBalance,
                'total_contributions' => $totalContributions,
                'source_count' => count($sourceContributions),
                'sources' => $sourceContributions,
                'summary' => [
                    'total_contributions_formatted' => number_format($totalContributions, 2) . ' ' . $sourceCurrency,
                    'total_available_balance_formatted' => number_format($totalAvailableBalance, 2) . ' ' . $sourceCurrency,
                    'coverage_percentage' => ($totalRequested > 0) ? round(($totalContributions / $totalRequested) * 100, 2) : 0,
                    'strategy_description' => getStrategyDescription($strategy)
                ]
            ];

            $amount = $totalContributions;

            error_log("[PREVIEW] Multi-Source contributions calculated: " . json_encode($sourceContributions));

        } catch (Exception $e) {
            error_log("[PREVIEW] Contribution calculation error: " . $e->getMessage());
            throw new Exception("Contribution calculation failed: " . $e->getMessage());
        }
    } elseif ($sourceInst && !empty($input['source_identifier'])) {
        // ============================================================
        // SINGLE-SOURCE: GET BALANCE & VALIDATE SUFFICIENCY
        // ============================================================
        $singleSourceAssetType = $input['asset_type'] ?? 'ACCOUNT';
        $singleSourceIdentifier = $input['source_identifier'];

        $participant = null;
        foreach ($participants as $code => $p) {
            if (strtoupper($code) === strtoupper($sourceInst)) {
                $participant = $p;
                break;
            }
        }

        if (!$participant) {
            throw new Exception("Participant not found: {$sourceInst}");
        }

        $balance = 0;
        $balanceError = null;

        try {
            $bankClient = new GenericBankClient($participant);

            $balancePayload = [
                'action' => 'GET_BALANCE',
                'asset_type' => $singleSourceAssetType,
                'source_identifier' => $singleSourceIdentifier,
                'currency' => $sourceCurrency,
                'reference' => 'BALANCE_CHECK_' . time()
            ];

            $balanceResult = $bankClient->getBalance($balancePayload);

            if ($balanceResult['success'] ?? false) {
                $balance = (float)($balanceResult['data']['balance'] ?? 0);
                error_log("[PREVIEW] Single-source {$sourceInst} balance: {$balance} {$sourceCurrency}");
            } else {
                $balanceError = $balanceResult['message'] ?? 'Unknown error';
                error_log("[PREVIEW] Failed to get single-source balance for {$sourceInst}: {$balanceError}");
            }
        } catch (Exception $e) {
            $balanceError = $e->getMessage();
            error_log("[PREVIEW] Single-source balance check exception for {$sourceInst}: " . $e->getMessage());
        }

        $singleSourceBalanceCheck = [
            'institution' => $sourceInst,
            'identifier' => $singleSourceIdentifier,
            'asset_type' => $singleSourceAssetType,
            'available_balance' => $balance,
            'requested_amount' => $amount,
            'balance_checked' => $balanceError === null,
            'has_sufficient_balance' => $balance >= $amount,
            'balance_error' => $balanceError
        ];

        if ($balance < $amount - 0.01) {
            throw new Exception(
                sprintf(
                    "Insufficient balance for %s (%.2f) for requested amount (%.2f)%s",
                    $sourceInst,
                    $balance,
                    $amount,
                    $balanceError ? ": {$balanceError}" : ''
                )
            );
        }
    }

    $feePayload = [
        'amount' => $amount,
        'currency' => $sourceCurrency,
        'destination_currency' => $destinationCurrency,
        'from_institution' => $sourceInst,
        'to_institution' => $destInst,
        'source_institution' => $sourceInst,
        'destination_institution' => $destInst,
        'swap_type' => $swapType,
        'client_tier' => $input['client_tier'] ?? 'retail'
    ];

    if ($isMultiSource && $multiSourceBreakdown) {
        $feePayload['source_count'] = count($sourceContributions);
        $feePayload['is_multi_source'] = true;
        $feePayload['multi_source_breakdown'] = $multiSourceBreakdown;
    }

    $forexService = new \Domain\Services\ForexService(
        $db,
        $countryConfig,
        $participants
    );

    // ============================================================
    // FEE CALCULATION BRANCH: Multi-source vs Single-source
    // ============================================================
    $msFeeResult = null; // used below to fill multi_source per_source_fees correctly

    if ($isMultiSource && $multiSourceBreakdown) {
        $deliveryMode = match (strtoupper($swapType)) {
            'CASHOUT' => 'cashout',
            'CARD_LOAD', 'CARD' => 'card_load',
            default => 'deposit',
        };

        $msCalc = new \Domain\Services\MultiSourceFeeCalculator(
            $countryConfig['fees'] ?? [],
            $countryConfig['country_code'] ?? 'BW'
        );

        $msFeeResult = $msCalc->calculateFees(
            count($sourceContributions),
            $deliveryMode,
            $amount,
            $sourceCurrency,
            $destinationCurrency
        );

        $totalFee = $msFeeResult['total_fee'];
        $netAmountDestCurrency = round($amount - $totalFee, 2);
        $breakdown = $msFeeResult; // exposes pool/levy/per_source_cut/etc. directly
        $forexApplied = false;
        $exchangeRate = 1.0;
        $forexProfit = 0;
        $generateCodeFee = $msFeeResult['destination_immediate'] ?? 0;
        $cashoutCompletionFee = $msFeeResult['destination_deferred'] ?? 0;
        $destinationSplit = $generateCodeFee || $cashoutCompletionFee
            ? ['generate_code_fee' => $generateCodeFee, 'cashout_completion_fee' => $cashoutCompletionFee]
            : null;
    } else {
        $feeService = new \Domain\Services\FeeService(
            $countryConfig['fees'] ?? [],
            $countryConfig,
            $currency,
            $forexService
        );
        $feeService->setParticipants($participants);

        $feeResult = $feeService->calculateFees($swapType, $amount, $feePayload);

        $totalFee = $feeResult['total_fee'] ?? 0;
        $netAmountDestCurrency = $feeResult['net_amount_destination_currency'] ?? $amount;
        $breakdown = $feeResult['breakdown'] ?? [];
        $forexApplied = $feeResult['forex']['applied'] ?? false;
        $exchangeRate = $feeResult['forex']['rate'] ?? 1.0;
        $forexProfit = $feeResult['forex']['vouchmorph_profit'] ?? 0;

        $destinationSplit = $feeResult['destination_split'] ?? null;
        $generateCodeFee = $destinationSplit['generate_code_fee'] ?? 0;
        $cashoutCompletionFee = $destinationSplit['cashout_completion_fee'] ?? 0;
    }

    $preview = [
        'success' => true,
        'preview' => [
            'swap_type' => $swapType,
            'is_multi_source' => $isMultiSource,
            'source_institution' => $sourceInst,
            'destination_institution' => $destInst,
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'amount_requested' => $amount,
            'total_fee' => $totalFee,
            'net_amount' => $netAmountDestCurrency,
            'net_amount_source_currency' => $feeResult['net_amount_source_currency'] ?? $amount,
            'net_amount_destination_currency' => $netAmountDestCurrency,
            'forex_applied' => $forexApplied,
            'exchange_rate' => $exchangeRate,
            'forex_profit' => $forexProfit,
            'fee_breakdown' => $breakdown,
            'destination_split' => $destinationSplit ? [
                'generate_code_fee' => $generateCodeFee,
                'cashout_completion_fee' => $cashoutCompletionFee
            ] : null,
            'summary' => [
                'amount_requested_formatted' => number_format($amount, 2) . ' ' . $sourceCurrency,
                'total_fee_formatted' => number_format($totalFee, 2) . ' ' . $sourceCurrency,
                'net_amount_formatted' => number_format($netAmountDestCurrency, 2) . ' ' . $destinationCurrency,
                'exchange_rate_formatted' => $forexApplied ? "1 {$sourceCurrency} = {$exchangeRate} {$destinationCurrency}" : 'N/A'
            ]
        ]
    ];

    if ($singleSourceBalanceCheck) {
        $preview['preview']['balance_check'] = $singleSourceBalanceCheck;
    }

    if ($isMultiSource && $multiSourceBreakdown) {
        $preview['preview']['multi_source'] = $multiSourceBreakdown;

        // ============================================================
        // PER-SOURCE FEE SPLITTING — FIXED CUT FROM CALCULATOR
        // ============================================================
        $perSourceFees = [];
        $totalPerSourceFees = 0;

        // Use the fixed per-source cut from the calculator for multi-source
        $fixedSourceFee = $msFeeResult['per_source_cut'] ?? 0;

        foreach ($sourceContributions as $idx => $contrib) {
            $sourceAmount = $contrib['contribution_amount'];
            $sourceFee = $fixedSourceFee;
            $totalPerSourceFees += $sourceFee;

            $perSourceFees[] = [
                'source' => $idx + 1,
                'institution' => $contrib['institution'],
                'asset_type' => $contrib['asset_type'],
                'contribution_amount' => $sourceAmount,
                'contribution_percentage' => $contrib['percentage_of_total'],
                'fee_share' => round($sourceFee, 2),
                'fee_share_percentage' => $totalFee > 0 ? round(($sourceFee / $totalFee) * 100, 2) : 0,
                'net_contribution' => round($sourceAmount - $sourceFee, 2)
            ];
        }

        $preview['preview']['multi_source']['per_source_fees'] = $perSourceFees;
        $preview['preview']['multi_source']['total_per_source_fees'] = round($totalPerSourceFees, 2);

        // Surface additional MultiSourceFeeCalculator fields in the response
        if ($msFeeResult) {
            $preview['preview']['multi_source']['immediate_charge'] = $msFeeResult['immediate_charge'] ?? 0;
            $preview['preview']['multi_source']['deferred_charge'] = $msFeeResult['deferred_charge'] ?? 0;
            $preview['preview']['multi_source']['swap_levy_total'] = $msFeeResult['swap_levy_total'] ?? 0;
            $preview['preview']['multi_source']['pool_charge_total'] = $msFeeResult['pool_charge_total'] ?? 0;
            $preview['preview']['multi_source']['per_source_cut'] = $msFeeResult['per_source_cut'] ?? 0;
            $preview['preview']['multi_source']['pool_fee'] = $msFeeResult['pool_fee'] ?? 0;
            $preview['preview']['multi_source']['levy_fee'] = $msFeeResult['levy_fee'] ?? 0;
        }

        $strategyDescriptions = [
            'RATIO' => 'Contributions are proportional to each source\'s available balance',
            'DRAIN_SMALLEST' => 'Smallest balances are drained first, then next smallest',
            'USER_SPECIFIED' => 'User specified exact amounts for each source'
        ];
        $preview['preview']['multi_source']['strategy_description'] = $strategyDescriptions[$strategy] ?? 'Ratio-based distribution';

        $balanceCheckResults = [];
        foreach ($sourceBalances as $sb) {
            $balanceCheckResults[] = [
                'institution' => $sb['institution'],
                'identifier' => $sb['identifier'],
                'asset_type' => $sb['asset_type'],
                'available_balance' => $sb['available_balance'],
                'balance_checked' => $sb['balance_checked'],
                'has_sufficient_balance' => $sb['has_sufficient_balance'],
                'balance_error' => $sb['balance_error']
            ];
        }
        $preview['preview']['multi_source']['balance_checks'] = $balanceCheckResults;

        $preview['preview']['summary']['multi_source'] = [
            'source_count' => count($sourceContributions),
            'total_contributions_formatted' => number_format($multiSourceBreakdown['total_contributions'], 2) . ' ' . $sourceCurrency,
            'coverage_percentage' => $multiSourceBreakdown['summary']['coverage_percentage'] . '%',
            'strategy' => $strategy
        ];
    }

    error_log("[PREVIEW] Response: " . json_encode($preview));

    echo json_encode($preview);

} catch (Exception $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    http_response_code($code);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

    error_log("[PREVIEW] Error: " . $e->getMessage());
}

function getStrategyDescription(string $strategy): string {
    $descriptions = [
        'RATIO' => 'Contributions are proportional to each source\'s available balance',
        'DRAIN_SMALLEST' => 'Smallest balances are drained first, then next smallest',
        'USER_SPECIFIED' => 'User specified exact amounts for each source'
    ];
    return $descriptions[$strategy] ?? 'Ratio-based distribution';
}
