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

        $this->db->beginTransaction();
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
            // before verification/holds even start
            $contributions = $this->persistContributions($pool, $contributions);
            
            // 3. Transition to VERIFYING
            $this->stateMachine->transition($pool, PoolStatus::VERIFYING);
            
            // 4. Verify sources (Bug 1 fixed: checks 'verified' instead of 'success')
            $verifications = $this->verifySources($contributions, $payload);
            $this->logger->info('Sources verified', ['verified' => count($verifications)]);
            
            // 5. Transition to HOLDING
            $this->stateMachine->transition($pool, PoolStatus::HOLDING);
            
            // 6. Place holds (Bug 2 fixed: captures held sources for rollback)
            $holds = $this->placeHolds($pool, $contributions, $verifications, $heldSources);
            $this->logger->info('Holds placed', ['holds' => count($holds)]);
            
            // 7. Transition to FUNDED
            $this->stateMachine->transition($pool, PoolStatus::FUNDED);
            
            // 8. Generate master signature
            $masterSignature = $this->aggregateSigner->signAggregate($pool, $holds, $verifications);
            $this->logger->info('Master signature generated');
            
            // 9. Transition to DESTINATION_PENDING
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_PENDING);
            
            // 10. Execute destination (FIXED: uses captured destination identifier)
            $destinationResult = $this->executeDestination($pool, $contributions, $masterSignature);
            $this->logger->info('Destination executed', ['success' => $destinationResult['success'] ?? false]);
            
            // 11. Transition to DESTINATION_COMPLETED
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_COMPLETED);
            
            // 12. Debit sources
            $debits = $this->debitSources($pool, $holds, $contributions);
            $this->logger->info('Sources debited', ['debits' => count($debits)]);
            
            // 13. Settle
            $settlementResult = $this->settle($pool, $contributions);
            $this->logger->info('Settlement completed');
            
            // 14. Invoice
            $invoiceResult = $this->invoice($pool, $contributions);
            $this->logger->info('Invoicing completed');
            
            // 15. Complete
            $this->stateMachine->transition($pool, PoolStatus::COMPLETED);
            
            $this->db->commit();
            
            return $this->buildResponse($pool, $contributions, $destinationResult, $settlementResult, $invoiceResult);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Multi-source swap failed', ['error' => $e->getMessage()]);
            
            // Bug 2 fixed: Now releases holds properly with real institution calls
            $this->rollbackHolds($heldSources);
            
            $this->rollback($pool ?? null);
            throw new RuntimeException("Multi-source swap failed: " . $e->getMessage());
        }
    }

    /**
     * Persist each calculated contribution as a real DB row, so pool_contributions
     * actually reflects what was calculated/verified/held/debited instead of only
     * existing in memory for the request.
     */
    private function persistContributions(array $pool, array $contributions): array
    {
        $persisted = [];
        foreach ($contributions as $index => $contribution) {
            $model = new PoolContribution(
                $pool['id'],
                $pool['reference'] . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
                $index + 1,
                $contribution['institution'],
                $contribution['asset_type'] ?? 'ACCOUNT',
                $contribution['account_id'] ?? $contribution['identifier'] ?? '',
                (float)($contribution['requested_amount'] ?? $contribution['amount']),
                (float)$contribution['amount'],
                $contribution['currency'] ?? $pool['currency'] ?? 'BWP'
            );
            $saved = $this->contributionRepository->save($model);
            // Carry the DB id back onto the working array so later stages
            // (verify/hold/debit) can reference the correct row.
            $contribution['_contribution_id'] = $saved->getId();
            $contribution['_sub_reference'] = $pool['reference'] . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
            $persisted[] = $contribution;
        }
        return $persisted;
    }

    private function createPool(array $payload): array
    {
        $poolId = $payload['pool_id'] ?? 'POOL_' . uniqid();
        
        // Bug 3: Take forex snapshot once at pool creation
        $this->forexRateSnapshot = $this->swapService->getForexRate(
            $payload['currency'] ?? 'BWP',
            $payload['destination_currency'] ?? 'BWP'
        );
        
        // FIXED: Extract destination identifier and asset type the same way
        // every other SwapService flow does, instead of expecting a pre-shaped
        // 'destination_account_id' key that nothing ever populates.
        $destinationIdentifier = $this->swapService->extractDestinationIdentifier($payload);
        $destinationAssetType = $this->swapService->extractDestinationAssetType($payload);
        
        $pool = [
            'id' => $poolId,
            'sources' => $payload['sources'] ?? [],
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_currency' => $payload['destination_currency'] ?? 'BWP',
            'status' => PoolStatus::CREATED,
            'source_institution' => $payload['from_institution'] ?? $payload['source_institution'] ?? null,
            'destination_institution' => $payload['to_institution'] ?? $payload['destination_institution'] ?? null,
            // FIXED: Use the real captured identifier instead of a key that
            // was never populated anywhere in the pool record.
            'destination_identifier' => $destinationIdentifier['identifier'] ?? null,
            'destination_identifier_type' => $destinationIdentifier['type'] ?? null,
            'destination_asset_type' => $destinationAssetType,
            'reference' => $payload['reference'] ?? uniqid(),
            'forex_rate' => $this->forexRateSnapshot,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // FIXED: Use array-friendly save method
        $this->poolRepository->saveFromArray($pool);
        
        return $pool;
    }

    /**
     * FIXED: Calculate contributions using the real ContributionCalculator signature
     * 
     * Before: $this->contributionCalculator->calculate($pool, $payload)
     * After: $this->contributionCalculator->calculateContributions(...)
     * 
     * The real method signature is:
     * calculateContributions(float $targetAmount, array $sources, string $strategy, ?array $userSpecified, ?array $priorityOrder)
     */
    private function calculateContributions(array $pool, array $payload): array
    {
        // Build sources with available balances
        $sourcesWithBalances = [];
        foreach ($pool['sources'] as $source) {
            $balance = $this->swapService->getSourceAvailableBalance($source);
            $sourcesWithBalances[] = array_merge($source, ['available_balance' => $balance]);
        }

        // Call the real ContributionCalculator method with the correct signature
        return $this->contributionCalculator->calculateContributions(
            $pool['amount'],
            $sourcesWithBalances,
            $payload['contribution_strategy'] ?? 'RATIO',
            $payload['user_amounts'] ?? null,
            $payload['priority_order'] ?? null
        );
    }

    private function verifySources(array $contributions, array $payload): array
{
    $verifications = [];
    
    foreach ($contributions as $index => $contribution) {
        $institution = $contribution['institution'];
        $amount = $contribution['amount'];

        // NEW: reject non-source-capable institutions before attempting verification
        $this->swapService->assertCanBeSourcePublic($institution);
        
        // FIX: Use the correct source identifier keys
        $sourceIdentifier = $contribution['identifier'] ?? 
                           $contribution['source_identifier'] ?? 
                           $contribution['account_id'] ?? 
                           null;
        
        $sourceIdentifierType = $contribution['identifier_type'] ?? 
                                $contribution['source_identifier_type'] ?? 
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
        
        // Bug 1 FIX: Check 'verified' instead of 'success'
        if (!($result['verified'] ?? false)) {
            throw new RuntimeException("Verification failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
        }
        
        $verifications[] = [
            'index' => $index,
            'institution' => $institution,
            'verified' => true,
            'asset_id' => $result['asset_id'] ?? null,
            'balance' => $result['balance'] ?? 0
        ];
        
        // NEW: reflect verification in the persisted row
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

    private function placeHolds(array $pool, array $contributions, array $verifications, ?array &$heldSources = null): array
{
    $holds = [];
    $heldSources = []; // Track successfully held sources for rollback
    
    foreach ($contributions as $index => $contribution) {
        $institution = $contribution['institution'];
        $amount = $contribution['amount'];
        
        // FIX: Use the correct source identifier keys
        $sourceIdentifier = $contribution['identifier'] ?? 
                           $contribution['source_identifier'] ?? 
                           $contribution['account_id'] ?? 
                           null;
        
        $sourceIdentifierType = $contribution['identifier_type'] ?? 
                                $contribution['source_identifier_type'] ?? 
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
            // Bug 2 FIX: Rollback all previously held sources with real institution calls
            $this->rollbackHolds($heldSources);
            throw new RuntimeException("Hold failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
        }
        
        $holdData = [
            'index' => $index,
            'institution' => $institution,
            'hold_id' => $result['hold_id'] ?? null,
            'hold_reference' => $result['hold_reference'] ?? null,
            'amount' => $amount,
            'source_payload' => $contribution // Store for rollback
        ];
        
        // NEW: persist hold reference against the contribution row
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
        $heldSources[] = $holdData; // Track for rollback
    }
    
    return $holds;
}

    /**
     * Bug 2 FIX: Rollback holds with real institution calls
     */
    private function rollbackHolds(array $heldSources): void
    {
        if (empty($heldSources)) {
            return;
        }
        
        $this->logger->warning('Rolling back holds', ['count' => count($heldSources)]);
        
        foreach ($heldSources as $held) {
            try {
                // Release the REAL hold at the institution
                $releaseResult = $this->swapService->releaseHold(
                    $held['source_payload'] ?? [],
                    $held['institution'],
                    $held['hold_id'] ?? null,
                    $held['hold_reference'] ?? null
                );
                
                $this->logger->info('Released real hold', [
                    'institution' => $held['institution'],
                    'hold_id' => $held['hold_id'] ?? 'unknown',
                    'success' => $releaseResult['success'] ?? false
                ]);
                
                // Then clean up local bookkeeping
                $this->releaseLocalHold($held['hold_id'] ?? null);
                
                // NEW: reflect the failure on the contribution row too
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
        
        // FIXED: use the real captured identifier instead of a key that
        // was never populated anywhere in the pool record.
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
            
            // NEW: mark the contribution as debited with its transaction reference
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
        // Bug 3 FIX: Pass the forex rate snapshot to fee calculator
        $feeResult = $this->feeCalculator->calculate(
            $pool['amount'] ?? 0,
            $contributions,
            $pool,
            $this->forexRateSnapshot // Pass snapshot instead of fetching fresh rates
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

    private function rollback(?array $pool): void
    {
        if ($pool && isset($pool['id'])) {
            try {
                // FIXED: updateStatus now exists in FundingPoolRepository
                $this->poolRepository->updateStatus($pool['id'], PoolStatus::FAILED);
                $this->logger->warning('Pool rolled back', ['pool_id' => $pool['id']]);
            } catch (Exception $e) {
                $this->logger->error('Rollback failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function buildResponse(array $pool, array $contributions, array $destinationResult, array $settlementResult, array $invoiceResult): array
    {
        return [
            'success' => true,
            'pool_id' => $pool['id'],
            'reference' => $pool['reference'],
            'status' => PoolStatus::COMPLETED,
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

    public function getStatus(string $poolId): array
    {
        // FIXED: Use array-friendly findByIdAsArray method
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
            'status' => $pool['status'] ?? PoolStatus::UNKNOWN,
            'amount' => $pool['amount'] ?? 0,
            'currency' => $pool['currency'] ?? 'BWP',
            'created_at' => $pool['created_at'] ?? null,
            'updated_at' => $pool['updated_at'] ?? null
        ];
    }

    public function cancel(string $poolId, string $reason): array
    {
        // FIXED: Use array-friendly findByIdAsArray method
        $pool = $this->poolRepository->findByIdAsArray($poolId);
        
        if (!$pool) {
            return [
                'success' => false,
                'message' => "Pool not found: {$poolId}"
            ];
        }
        
        // FIXED: updateStatus now exists in FundingPoolRepository
        $this->poolRepository->updateStatus($poolId, PoolStatus::CANCELLED);
        
        return [
            'success' => true,
            'pool_id' => $poolId,
            'status' => PoolStatus::CANCELLED,
            'reason' => $reason
        ];
    }
}
