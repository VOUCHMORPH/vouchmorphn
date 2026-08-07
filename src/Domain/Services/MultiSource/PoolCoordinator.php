<?php
declare(strict_types=1);

namespace Domain\Services\MultiSource;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\ContributionCalculator;
use Domain\Services\MultiSourceFeeCalculator;
use Domain\Repositories\FundingPoolRepository;
use Domain\Repositories\PoolContributionRepository;
use Domain\ValueObjects\PoolStatus;
use Domain\ValueObjects\ContributionStatus;
use Domain\Models\PoolContribution;
use Infrastructure\Crypto\AggregateSigner;
use Infrastructure\Banks\GenericBankClient;

class PoolCoordinator
{
    private PDO $db;
    private SwapService $swapService;
    private HybridSettlementStrategy $settlement;
    private AggregateSigner $aggregateSigner;
    private ContributionCalculator $contributionCalculator;
    private MultiSourceFeeCalculator $feeCalculator;
    private FundingPoolRepository $poolRepository;
    private PoolContributionRepository $contributionRepository;
    private PoolStateMachine $stateMachine;
    private $logger;
    private array $config;
    private string $countryCode;
    private ?array $forexRateSnapshot = null;

    public function __construct(
        PDO $db,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        AggregateSigner $aggregateSigner,
        array $config,
        string $countryCode,
        $logger = null
    ) {
        $this->db = $db;
        $this->swapService = $swapService;
        $this->settlement = $settlement;
        $this->aggregateSigner = $aggregateSigner;
        $this->config = $config;
        $this->countryCode = $countryCode;
        
        // Use default logger if none provided
        if ($logger === null) {
            $this->logger = new class {
                public function info($message, array $context = []) {
                    error_log("[POOL] INFO: " . $message . " " . json_encode($context));
                }
                public function error($message, array $context = []) {
                    error_log("[POOL] ERROR: " . $message . " " . json_encode($context));
                }
                public function warning($message, array $context = []) {
                    error_log("[POOL] WARNING: " . $message . " " . json_encode($context));
                }
                public function debug($message, array $context = []) {
                    error_log("[POOL] DEBUG: " . $message . " " . json_encode($context));
                }
                public function log($level, $message, array $context = []) {
                    error_log("[POOL] {$level}: " . $message . " " . json_encode($context));
                }
            };
        } else {
            $this->logger = $logger;
        }
        
        // Initialize dependencies
        $this->contributionCalculator = new ContributionCalculator();
        $this->feeCalculator = new MultiSourceFeeCalculator($config, $countryCode);
        $this->poolRepository = new FundingPoolRepository($db);
        $this->contributionRepository = new PoolContributionRepository($db);
        $this->stateMachine = new PoolStateMachine();
    }

    public function execute(array $payload): array
    {
        $this->logger->info('PoolCoordinator executing multi-source swap', [
            'sources' => count($payload['sources'] ?? []),
            'amount' => $payload['amount'] ?? 0
        ]);

        // FIX: Check if a transaction is already open before starting one
        $transactionStartedHere = !$this->db->inTransaction();
        
        if ($transactionStartedHere) {
            $this->db->beginTransaction();
            $this->logger->debug('Started new transaction in PoolCoordinator');
        } else {
            $this->logger->debug('Using existing transaction from caller');
        }
        
        $pool = null;
        $heldSources = [];
        
        try {
            // 1. Create pool
            $pool = $this->createPool($payload);
            $this->logger->info('Pool created', ['pool_id' => $pool['id']]);
            
            // 2. Calculate contributions
            $contributions = $this->calculateContributions($pool, $payload);
            $this->logger->info('Contributions calculated', ['count' => count($contributions)]);
            
            // NEW: persist contributions immediately so they exist in the DB
            $contributions = $this->persistContributions($pool, $contributions);
            
            // 3. Transition to VERIFYING - FIX 4: use ->value
            $this->stateMachine->transition($pool, PoolStatus::VERIFYING->value);
            
            // 4. Verify sources
            $verifications = $this->verifySources($contributions, $payload);
            $this->logger->info('Sources verified', ['verified' => count($verifications)]);
            
            // 5. Transition to HOLDING - FIX 4: use ->value
            $this->stateMachine->transition($pool, PoolStatus::HOLDING->value);
            
            // 6. Place holds
            $holds = $this->placeHolds($pool, $contributions, $verifications, $heldSources);
            $this->logger->info('Holds placed', ['holds' => count($holds)]);
            
            // 7. Transition to FUNDED - FIX 4: use ->value
            $this->stateMachine->transition($pool, PoolStatus::FUNDED->value);
            
            // 8. Generate master signature
            $masterSignature = $this->aggregateSigner->signAggregate($pool, $holds, $verifications);
            $this->logger->info('Master signature generated');
            
            // 9. Transition to DESTINATION_PENDING - FIX 4: use ->value
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_PENDING->value);
            
            // 10. Execute destination
            $destinationResult = $this->executeDestination($pool, $contributions, $masterSignature);
            $this->logger->info('Destination executed', ['success' => $destinationResult['success'] ?? false]);
            
            // 11. Transition to DESTINATION_COMPLETED - FIX 4: use ->value
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_COMPLETED->value);
            
            // 12. Debit sources
            $debits = $this->debitSources($pool, $holds, $contributions);
            $this->logger->info('Sources debited', ['debits' => count($debits)]);
            
            // 13. Settle
            $settlementResult = $this->settle($pool, $contributions);
            $this->logger->info('Settlement completed');
            
            // 14. Invoice
            $invoiceResult = $this->invoice($pool, $contributions);
            $this->logger->info('Invoicing completed');
            
            // 15. Complete - FIX 4: use ->value
            $this->stateMachine->transition($pool, PoolStatus::COMPLETED->value);
            
            // FIX: Only commit if we started the transaction
            if ($transactionStartedHere) {
                $this->db->commit();
                $this->logger->debug('Committed transaction in PoolCoordinator');
            } else {
                $this->logger->debug('Leaving transaction open for caller to commit');
            }
            
            return $this->buildResponse($pool, $contributions, $destinationResult, $settlementResult, $invoiceResult);
            
        } catch (Exception $e) {
            // FIX: Only rollback if we started the transaction
            if ($transactionStartedHere && $this->db->inTransaction()) {
                $this->db->rollBack();
                $this->logger->debug('Rolled back transaction in PoolCoordinator');
            } else {
                $this->logger->debug('Not rolling back - caller owns the transaction');
            }
            
            $this->logger->error('Multi-source swap failed', ['error' => $e->getMessage()]);
            
            // Bug 2 fixed: Now releases holds properly with real institution calls
            $this->rollbackHolds($heldSources);
            
            $this->rollback($pool ?? null);
            throw new RuntimeException("Multi-source swap failed: " . $e->getMessage());
        }
    }

    /**
     * Persist each calculated contribution as a real DB row
     * FIX: Extracts identifier from the nested 'source' array that ContributionCalculator returns.
     */
    private function persistContributions(array $pool, array $contributions): array
    {
        $persisted = [];
        foreach ($contributions as $index => $contribution) {
            // FIX: Extract identifier from the nested 'source' array
            $source = $contribution['source'] ?? [];
            
            // Try multiple possible locations for the identifier
            $identifier = $source['source_identifier'] ?? 
                          $source['identifier'] ?? 
                          $source['account_id'] ?? 
                          $contribution['source_identifier'] ?? 
                          $contribution['identifier'] ?? 
                          $contribution['account_id'] ?? 
                          '';
            
            $identifierType = $source['source_identifier_type'] ?? 
                              $source['identifier_type'] ?? 
                              $contribution['source_identifier_type'] ?? 
                              $contribution['identifier_type'] ?? 
                              'auto';
            
            $institution = $source['institution'] ?? $contribution['institution'] ?? '';
            $assetType = $contribution['asset_type'] ?? $source['asset_type'] ?? 'ACCOUNT';
            $amount = (float)($contribution['actual_amount'] ?? $contribution['amount'] ?? 0);
            $requestedAmount = (float)($contribution['requested_amount'] ?? $contribution['amount'] ?? 0);
            $currency = $contribution['currency'] ?? $pool['currency'] ?? 'BWP';
            
            // Log for debugging
            $this->logger->debug('Persisting contribution', [
                'index' => $index,
                'institution' => $institution,
                'identifier' => $identifier,
                'identifier_type' => $identifierType,
                'amount' => $amount,
                'asset_type' => $assetType
            ]);
            
            $model = new PoolContribution(
                $pool['id'],
                $pool['reference'] . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
                $index + 1,
                $institution,
                $assetType,
                $identifier,
                $identifierType,  // NEW: pass identifier type
                $requestedAmount,
                $amount,
                $currency
            );
            
            $saved = $this->contributionRepository->save($model);
            
            // Carry the DB id back onto the working array
            $contribution['_contribution_id'] = $saved->getId();
            $contribution['_sub_reference'] = $pool['reference'] . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
            
            // Preserve the source identifier for later use
            $contribution['source_identifier'] = $identifier;
            $contribution['source_identifier_type'] = $identifierType;
            $contribution['institution'] = $institution;
            $contribution['asset_type'] = $assetType;
            $contribution['amount'] = $amount;
            
            $persisted[] = $contribution;
        }
        return $persisted;
    }

    /**
     * Create a funding pool - FIX 4: use ->value for status
     */
    private function createPool(array $payload): array
    {
        $poolId = $payload['pool_id'] ?? 'POOL_' . uniqid();
        
        // Take forex snapshot once at pool creation
        try {
            if (isset($this->swapService->forexService)) {
                $forexService = $this->swapService->forexService;
                $rate = $forexService->getExchangeRate(
                    $payload['currency'] ?? 'BWP',
                    $payload['destination_currency'] ?? 'BWP',
                    'internal'
                );
                $this->forexRateSnapshot = [
                    'rate' => $rate,
                    'from' => $payload['currency'] ?? 'BWP',
                    'to' => $payload['destination_currency'] ?? 'BWP',
                    'applied' => true,
                    'timestamp' => time()
                ];
            } else {
                $this->forexRateSnapshot = [
                    'rate' => 1.0,
                    'from' => $payload['currency'] ?? 'BWP',
                    'to' => $payload['destination_currency'] ?? 'BWP',
                    'applied' => false,
                    'timestamp' => time()
                ];
                $this->logger->info('ForexService not available, using default rate 1.0');
            }
        } catch (Exception $e) {
            $this->logger->warning('Forex rate not available, using default', [
                'error' => $e->getMessage()
            ]);
            $this->forexRateSnapshot = [
                'rate' => 1.0,
                'from' => $payload['currency'] ?? 'BWP',
                'to' => $payload['destination_currency'] ?? 'BWP',
                'applied' => false,
                'timestamp' => time()
            ];
        }
        
        // Extract destination identifier and asset type
        $destinationIdentifier = $this->swapService->extractDestinationIdentifier($payload);
        $destinationAssetType = $this->swapService->extractDestinationAssetType($payload);
        
        // For IDENTITY swaps, destination_institution can be null
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'] ?? null;
        
        $pool = [
            'id' => $poolId,
            'sources' => $payload['sources'] ?? [],
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_currency' => $payload['destination_currency'] ?? 'BWP',
            'status' => PoolStatus::CREATED->value,  // FIX 4: use ->value
            'source_institution' => $payload['from_institution'] ?? $payload['source_institution'] ?? null,
            'destination_institution' => $destinationInstitution,
            'destination_identifier' => $destinationIdentifier['identifier'] ?? null,
            'destination_identifier_type' => $destinationIdentifier['type'] ?? null,
            'destination_asset_type' => $destinationAssetType,
            'reference' => $payload['reference'] ?? uniqid(),
            'forex_rate' => $this->forexRateSnapshot,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Use array-friendly save method
        $this->poolRepository->saveFromArray($pool);
        
        return $pool;
    }

    /**
     * Calculate contributions using the real ContributionCalculator signature
     */
    private function calculateContributions(array $pool, array $payload): array
    {
        // Build sources with available balances
        $sourcesWithBalances = [];
        foreach ($pool['sources'] as $source) {
            $balance = $this->swapService->getSourceAvailableBalance($source);
            $sourcesWithBalances[] = array_merge($source, ['available_balance' => $balance]);
        }

        // Call the real ContributionCalculator method
        return $this->contributionCalculator->calculateContributions(
            $pool['amount'],
            $sourcesWithBalances,
            $payload['contribution_strategy'] ?? 'RATIO',
            $payload['user_amounts'] ?? null,
            $payload['priority_order'] ?? null
        );
    }

    /**
     * FIX 7: verifySources() - carry payload into $verifications
     */
    private function verifySources(array $contributions, array $payload): array
    {
        $verifications = [];
        
        foreach ($contributions as $index => $contribution) {
            $institution = $contribution['institution'];
            $amount = $contribution['amount'];

            // Reject non-source-capable institutions before attempting verification
            $this->swapService->assertCanBeSourcePublic($institution);
            
            // Use the correct source identifier keys
            $sourceIdentifier = $contribution['source_identifier'] ?? 
                               $contribution['identifier'] ?? 
                               $contribution['account_id'] ?? 
                               null;
            
            $sourceIdentifierType = $contribution['source_identifier_type'] ?? 
                                    $contribution['identifier_type'] ?? 
                                    'auto';
            
            // Create verification payload with the correct fields
            $verifyPayload = [
                'action' => 'VERIFY_ASSET',
                'reference' => $payload['reference'] ?? uniqid(),
                'asset_type' => $contribution['asset_type'] ?? 'ACCOUNT',
                'amount' => $amount,
                'currency' => $contribution['currency'] ?? 'BWP',
                'institution' => $institution,
                'timestamp' => time(),
                'swap_type' => 'MULTI_SOURCE',
                'requester' => 'VOUCHMORPH',
                'from_institution' => $institution,
                'source_institution' => $institution,
                'source_identifier' => $sourceIdentifier,
                'source_identifier_type' => $sourceIdentifierType,
            ];
            
            // Call SwapService to verify asset
            $result = $this->swapService->verifyAssetSigned($verifyPayload, $institution);
            
            if (!($result['verified'] ?? false)) {
                throw new RuntimeException("Verification failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            // FIX 7: Carry payload into verification record
            $verifications[] = [
                'index' => $index,
                'institution' => $institution,
                'verified' => true,
                'asset_id' => $result['asset_id'] ?? null,
                'balance' => $result['balance'] ?? 0,
                'payload' => $result['original_payload'] ?? $verifyPayload ?? null,   // ADD: carry payload
            ];
            
            // Reflect verification in the persisted row
            if (isset($contribution['_contribution_id'])) {
                try {
                    $this->contributionRepository->updateStatus(
                        $contribution['_contribution_id'],
                        ContributionStatus::VERIFIED
                    );
                } catch (Exception $e) {
                    $this->logger->warning('Failed to update contribution verification status', [
                        'contribution_id' => $contribution['_contribution_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        return $verifications;
    }

    /**
     * FIX 6: placeHolds() - carry signature/certificate into $holdData
     */
    private function placeHolds(array $pool, array $contributions, array $verifications, ?array &$heldSources = null): array
    {
        $holds = [];
        $heldSources = [];
        
        foreach ($contributions as $index => $contribution) {
            $institution = $contribution['institution'];
            $amount = $contribution['amount'];
            
            // Use the correct source identifier keys
            $sourceIdentifier = $contribution['source_identifier'] ?? 
                               $contribution['identifier'] ?? 
                               $contribution['account_id'] ?? 
                               null;
            
            $sourceIdentifierType = $contribution['source_identifier_type'] ?? 
                                    $contribution['identifier_type'] ?? 
                                    'auto';
            
            $holdPayload = [
                'action' => 'PLACE_HOLD',
                'reference' => $pool['reference'] ?? uniqid(),
                'asset_type' => $contribution['asset_type'] ?? 'ACCOUNT',
                'amount' => $amount,
                'currency' => $contribution['currency'] ?? 'BWP',
                'hold_reason' => 'MULTI_SOURCE_SWAP',
                'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                'timestamp' => time(),
                'from_institution' => $institution,
                'source_institution' => $institution,
                'source_identifier' => $sourceIdentifier,
                'source_identifier_type' => $sourceIdentifierType,
                'user_id' => $pool['user_id'] ?? 0,
                'destination_institution' => $pool['destination_institution'] ?? null,
            ];
            
            $verificationResult = $verifications[$index] ?? [];
            $result = $this->swapService->placeHoldSigned($holdPayload, $institution, $verificationResult);
            
            if (!$result['hold_placed']) {
                $this->rollbackHolds($heldSources);
                throw new RuntimeException("Hold failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            // FIX 6: Carry signature/certificate into hold data
            $holdData = [
                'index' => $index,
                'institution' => $institution,
                'hold_id' => $result['hold_id'] ?? null,
                'hold_reference' => $result['hold_reference'] ?? null,
                'amount' => $amount,
                'signature' => $result['signature'] ?? null,        // ADD: signature
                'certificate' => $result['certificate'] ?? null,    // ADD: certificate
                'source_payload' => $contribution
            ];
            
            // Persist hold reference against the contribution row
            if (isset($contribution['_contribution_id']) && !empty($holdData['hold_reference'])) {
                try {
                    $this->contributionRepository->updateHoldReference(
                        $contribution['_contribution_id'],
                        $holdData['hold_reference']
                    );
                    $this->contributionRepository->updateStatus(
                        $contribution['_contribution_id'],
                        ContributionStatus::HELD
                    );
                } catch (Exception $e) {
                    $this->logger->warning('Failed to update contribution hold status', [
                        'contribution_id' => $contribution['_contribution_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $holds[] = $holdData;
            $heldSources[] = $holdData;
        }
        
        return $holds;
    }

    /**
     * FIX 5: rollbackHolds() - cast hold_id to string before calling releaseHold()
     */
    private function rollbackHolds(array $heldSources): void
    {
        if (empty($heldSources)) {
            return;
        }
        
        $this->logger->warning('Rolling back holds', ['count' => count($heldSources)]);
        
        foreach ($heldSources as $held) {
            try {
                // FIX 5: Cast hold_id to string to avoid type errors
                $releaseResult = $this->swapService->releaseHold(
                    $held['source_payload'] ?? [],
                    $held['institution'],
                    isset($held['hold_id']) ? (string)$held['hold_id'] : null,   // FIX: cast int -> string
                    $held['hold_reference'] ?? null
                );
                
                $this->logger->info('Released real hold', [
                    'institution' => $held['institution'],
                    'hold_id' => $held['hold_id'] ?? 'unknown',
                    'success' => $releaseResult['success'] ?? false
                ]);
                
                // Then clean up local bookkeeping
                $this->releaseLocalHold($held['hold_id'] ?? null);
                
                // Reflect the failure on the contribution row too
                $contribution = $held['source_payload'] ?? null;
                if ($contribution && isset($contribution['_contribution_id'])) {
                    try {
                        $this->contributionRepository->updateStatus(
                            $contribution['_contribution_id'],
                            ContributionStatus::FAILED
                        );
                    } catch (Exception $e) {
                        $this->logger->warning('Failed to update contribution rollback status', [
                            'contribution_id' => $contribution['_contribution_id'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
            } catch (Exception $e) {
                $this->logger->error('Failed to release hold', [
                    'institution' => $held['institution'],
                    'hold_id' => $held['hold_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                // Continue trying to release other holds even if one fails
            }
        }
    }

    private function releaseLocalHold(?string $holdId): void
    {
        if ($holdId) {
            try {
                // Clean up local hold_transactions bookkeeping
                $stmt = $this->db->prepare("
                    UPDATE hold_transactions 
                    SET status = 'RELEASED', 
                        released_at = NOW(),
                        updated_at = NOW()
                    WHERE hold_id = ? AND status = 'HELD'
                ");
                $stmt->execute([$holdId]);
            } catch (Exception $e) {
                $this->logger->error('Failed to release local hold', [
                    'hold_id' => $holdId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function executeDestination(array $pool, array $contributions, string $masterSignature): array
    {
        $destinationInstitution = $pool['destination_institution'];
        $totalAmount = $pool['amount'];
        $currency = $pool['currency'] ?? 'BWP';
        
        $destinationPayload = [
            'destination_identifier' => $pool['destination_identifier'] ?? null,
            'destination_identifier_type' => $pool['destination_identifier_type'] ?? 'account',
            'destination_asset_type' => $pool['destination_asset_type'] ?? 'WALLET',
            'destination_institution' => $destinationInstitution,
            'to_institution' => $destinationInstitution,
            'amount' => $totalAmount,
            'currency' => $currency,
            'reference' => $pool['reference'] ?? uniqid(),
            'master_signature' => $masterSignature,
            'pool_id' => $pool['id'],
            'sources' => $contributions
        ];
        
        return $this->swapService->creditDestination($destinationPayload, $destinationInstitution);
    }

    private function debitSources(array $pool, array $holds, array $contributions): array
    {
        $debits = [];
        
        foreach ($holds as $hold) {
            $institution = $hold['institution'];
            $holdReference = $hold['hold_reference'];
            $amount = $hold['amount'];
            
            $debitPayload = [
                'hold_reference' => $holdReference,
                'amount' => $amount,
                'reason' => 'Multi-source swap completed',
                'reference' => $pool['reference'] ?? uniqid(),
                'from_institution' => $institution,
                'source_institution' => $institution
            ];
            
            $result = $this->swapService->debitSource($debitPayload, $institution);
            
            if (!$result['debited']) {
                throw new RuntimeException("Debit failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            $debits[] = [
                'institution' => $institution,
                'debited' => true,
                'transaction_reference' => $result['transaction_reference'] ?? null
            ];
            
            // Mark the contribution as debited
            $matchingContribution = $hold['source_payload'] ?? null;
            if ($matchingContribution && isset($matchingContribution['_contribution_id']) && !empty($result['transaction_reference'])) {
                try {
                    $this->contributionRepository->updateDebitReference(
                        $matchingContribution['_contribution_id'],
                        $result['transaction_reference']
                    );
                    $this->contributionRepository->updateStatus(
                        $matchingContribution['_contribution_id'],
                        ContributionStatus::DEBITED
                    );
                } catch (Exception $e) {
                    $this->logger->warning('Failed to update contribution debit status', [
                        'contribution_id' => $matchingContribution['_contribution_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        return $debits;
    }

    private function settle(array $pool, array $contributions): array
    {
        $sourceInstitutions = array_column($contributions, 'institution');
        $destinationInstitution = $pool['destination_institution'];
        $totalAmount = $pool['amount'];
        $currency = $pool['currency'] ?? 'BWP';
        
        return $this->settlement->updateNetPosition(
            $pool['reference'] ?? uniqid(),
            implode(',', $sourceInstitutions),
            $destinationInstitution,
            $totalAmount,
            'MULTI_SOURCE_COMPLETED',
            $currency
        );
    }

    private function invoice(array $pool, array $contributions): array
    {
        $feeResult = $this->feeCalculator->calculate(
            $pool['amount'] ?? 0,
            $contributions,
            $pool,
            $this->forexRateSnapshot
        );
        
        $invoiceResults = [];
        
        // Invoice platform fee
        if (isset($feeResult['platform_fee']) && $feeResult['platform_fee'] > 0) {
            $result = $this->settlement->invoiceFee(
                $pool['reference'] ?? uniqid(),
                'VOUCHMORPH',
                1,
                'PLATFORM_FEE',
                $feeResult['platform_fee'],
                $pool['currency'] ?? 'BWP'
            );
            $invoiceResults[] = $result;
        }
        
        // Invoice each source
        foreach ($feeResult['source_fees'] ?? [] as $sourceFee) {
            $result = $this->settlement->invoiceFee(
                $pool['reference'] ?? uniqid(),
                $sourceFee['institution'],
                0,
                'SOURCE_FEE',
                $sourceFee['amount'],
                $pool['currency'] ?? 'BWP'
            );
            $invoiceResults[] = $result;
        }
        
        return $invoiceResults;
    }

    /**
     * FIX 4: rollback() - use ->value for PoolStatus::FAILED
     */
    private function rollback(?array $pool): void
    {
        if ($pool && isset($pool['id'])) {
            try {
                $this->poolRepository->updateStatus($pool['id'], PoolStatus::FAILED->value);  // FIX 4: use ->value
                $this->logger->warning('Pool rolled back', ['pool_id' => $pool['id']]);
            } catch (Exception $e) {
                $this->logger->error('Rollback failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * FIX 4: buildResponse() - use ->value for PoolStatus::COMPLETED
     */
    private function buildResponse(array $pool, array $contributions, array $destinationResult, array $settlementResult, array $invoiceResult): array
    {
        return [
            'success' => true,
            'pool_id' => $pool['id'],
            'reference' => $pool['reference'],
            'status' => PoolStatus::COMPLETED->value,  // FIX 4: use ->value
            'total_amount' => $pool['amount'],
            'currency' => $pool['currency'] ?? 'BWP',
            'forex_rate_used' => $this->forexRateSnapshot,
            'source_count' => count($contributions),
            'destination_result' => $destinationResult,
            'settlement' => $settlementResult,
            'invoices' => $invoiceResult,
            'completed_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * FIX 4: getStatus() - use ->value for PoolStatus::UNKNOWN
     */
    public function getStatus(string $poolId): array
    {
        $pool = $this->poolRepository->findByIdAsArray($poolId);
        
        if (!$pool) {
            return [
                'success' => false,
                'message' => "Pool not found: {$poolId}"
            ];
        }
        
        return [
            'success' => true,
            'pool_id' => $pool['id'] ?? $poolId,
            'status' => $pool['status'] ?? PoolStatus::UNKNOWN->value,  // FIX 4: use ->value
            'amount' => $pool['amount'] ?? 0,
            'currency' => $pool['currency'] ?? 'BWP',
            'created_at' => $pool['created_at'] ?? null,
            'updated_at' => $pool['updated_at'] ?? null
        ];
    }

    /**
     * FIX 4: cancel() - use ->value for PoolStatus::CANCELLED
     */
    public function cancel(string $poolId, string $reason): array
    {
        $pool = $this->poolRepository->findByIdAsArray($poolId);
        
        if (!$pool) {
            return [
                'success' => false,
                'message' => "Pool not found: {$poolId}"
            ];
        }
        
        $this->poolRepository->updateStatus($poolId, PoolStatus::CANCELLED->value);  // FIX 4: use ->value
        
        return [
            'success' => true,
            'pool_id' => $poolId,
            'status' => PoolStatus::CANCELLED->value,  // FIX 4: use ->value
            'reason' => $reason
        ];
    }
}
