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

/**
 * Validates the provided key against the single configured
 * VOUCHMORPH_API_KEY using a constant-time comparison.
 *
 * Previously this compared against *every* environment variable
 * whose name matched /KEY|API|TOKEN|SECRET/i OR whose value was
 * >= 32 characters — which meant DB passwords, session secrets,
 * JWT signing keys, etc. were all silently accepted as valid API
 * keys. That is not acceptable in a regulated environment.
 */
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

    error_log("[PREVIEW] Input payload: " . json_encode($input));

    $countryConfig = \Core\Config\LoadCountry::getConfig();

    if (!$countryConfig) {
        throw new Exception('Country configuration not found', 500);
    }

    // PATCHED: previously '?? "BWP"' — if a country's config is
    // missing a currency, that's a data problem in that country's
    // config file and should surface as an error, not silently
    // charge/display everything in Botswana Pula.
    if (empty($countryConfig['currency'])) {
        error_log("[PREVIEW] CRITICAL: Resolved country config has no 'currency' field: " . json_encode($countryConfig));
        throw new Exception('Country configuration is missing a currency field', 500);
    }
    $currency = $countryConfig['currency'];
    $participants = $countryConfig['participants'] ?? [];

    // ============================================================
    // DATABASE CONNECTION
    // ============================================================
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ============================================================
    // DETECT MULTI-SOURCE
    // ============================================================
    $isMultiSource = isset($input['sources']) && is_array($input['sources']) && count($input['sources']) > 1;
    $swapType = $input['swap_type'] ?? 'CASHOUT';
    $amount = (float)($input['amount'] ?? 0);
    $sourceInst = $input['from_institution'] ?? $input['source_institution'] ?? null;
    $destInst = $input['to_institution'] ?? $input['destination_institution'] ?? null;
    $sourceCurrency = $input['currency'] ?? $currency;
    $destinationCurrency = $input['destination_currency'] ?? $sourceCurrency;
    $strategy = $input['contribution_strategy'] ?? 'RATIO';

    // ============================================================
    // MULTI-SOURCE: GET BALANCES & CALCULATE CONTRIBUTIONS
    // ============================================================
    $sourceContributions = [];
    $sourceBalances = [];
    $totalAvailableBalance = 0;
    $multiSourceBreakdown = null;

    if ($isMultiSource) {
        error_log("[PREVIEW] Multi-Source detected: " . count($input['sources']) . " sources");

        $sources = $input['sources'];
        $totalRequested = $amount;

        // Step 1: Get balances for each source
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

            // Get participant config
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

            // Get balance from source institution
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

        // Step 2: Calculate contributions using ContributionCalculator
        $calculator = new ContributionCalculator();

        try {
            // Build sources array for calculator
            $calculatorSources = [];
            foreach ($sourceBalances as $sb) {
                $calculatorSources[] = [
                    'institution' => $sb['institution'],
                    'asset_type' => $sb['asset_type'],
                    'identifier' => $sb['identifier'],
                    'available_balance' => $sb['available_balance']
                ];
            }

            // Calculate contributions
            $userSpecified = null;
            if ($strategy === 'USER_SPECIFIED') {
                $userSpecified = [];
                foreach ($sources as $idx => $source) {
                    $userSpecified[$source['institution']] = (float)($source['amount'] ?? 0);
                }
            }

            $contributions = $calculator->calculateContributions(
                $totalRequested,
                $calculatorSources,
                $strategy,
                $userSpecified
            );

            // Build contribution breakdown
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

            // Verify total matches
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

            // Build multi-source breakdown
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

            // Update amount to total contributions for fee calculation
            $amount = $totalContributions;

            error_log("[PREVIEW] Multi-Source contributions calculated: " . json_encode($sourceContributions));

        } catch (Exception $e) {
            error_log("[PREVIEW] Contribution calculation error: " . $e->getMessage());
            throw new Exception("Contribution calculation failed: " . $e->getMessage());
        }
    }

    // ============================================================
    // FEE CALCULATION
    // ============================================================
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

    // ============================================================
    // BUILD PREVIEW RESPONSE
    // ============================================================
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

    // ============================================================
    // ADD MULTI-SOURCE DETAILS TO PREVIEW
    // ============================================================
    if ($isMultiSource && $multiSourceBreakdown) {
        $preview['preview']['multi_source'] = $multiSourceBreakdown;

        // Add per-source fee breakdown
        $perSourceFees = [];
        $totalPerSourceFees = 0;

        foreach ($sourceContributions as $idx => $contrib) {
            $sourceAmount = $contrib['contribution_amount'];
            // Calculate fee proportionally for this source
            $sourceFee = ($amount > 0) ? ($sourceAmount / $amount) * $totalFee : 0;
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

        // Add contribution strategy description
        $strategyDescriptions = [
            'RATIO' => 'Contributions are proportional to each source\'s available balance',
            'DRAIN_SMALLEST' => 'Smallest balances are drained first, then next smallest',
            'USER_SPECIFIED' => 'User specified exact amounts for each source'
        ];
        $preview['preview']['multi_source']['strategy_description'] = $strategyDescriptions[$strategy] ?? 'Ratio-based distribution';

        // Add balance check results
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

        // Update summary with multi-source info
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

/**
 * Helper to get strategy description
 */
function getStrategyDescription(string $strategy): string {
    $descriptions = [
        'RATIO' => 'Contributions are proportional to each source\'s available balance',
        'DRAIN_SMALLEST' => 'Smallest balances are drained first, then next smallest',
        'USER_SPECIFIED' => 'User specified exact amounts for each source'
    ];
    return $descriptions[$strategy] ?? 'Ratio-based distribution';
}
