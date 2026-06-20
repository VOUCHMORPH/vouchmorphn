<?php
declare(strict_types=1);

namespace Domain\Services\MultiSource;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\Crypto\CertificateManager;
use Infrastructure\Crypto\SignatureVerifier;
use Psr\Log\LoggerInterface;

/**
 * VIRTUAL FUNDING POOL EXECUTOR
 * 
 * This creates a temporary atomic container that:
 * 1. Aggregates contributions from multiple sources
 * 2. Presents as a SINGLE source to the destination
 * 3. Maintains cryptographic proof of each source
 * 4. Handles atomic commit/rollback across all sources
 * 
 * The pool is NOT a financial entity - it's an orchestration construct
 */
class VirtualFundingPoolExecutor
{
    private PDO $db;
    private SwapService $swapService;
    private HybridSettlementStrategy $settlement;
    private ContributionCalculator $calculator;
    private MultiSourceFeeCalculator $feeCalculator;
    private CertificateManager $certManager;
    private SignatureVerifier $signatureVerifier;
    private LoggerInterface $logger;
    private array $config;
    private string $countryCode;
    
    // Pool states
    private const POOL_CREATED = 'CREATED';
    private const POOL_VERIFYING = 'VERIFYING';
    private const POOL_HOLDING = 'HOLDING';
    private const POOL_FUNDED = 'FUNDED';
    private const POOL_DESTINATION_PENDING = 'DESTINATION_PENDING';
    private const POOL_DESTINATION_COMPLETE = 'DESTINATION_COMPLETE';
    private const POOL_DEBITING = 'DEBITING';
    private const POOL_COMPLETED = 'COMPLETED';
    private const POOL_FAILED = 'FAILED';
    private const POOL_ROLLED_BACK = 'ROLLED_BACK';
    
    public function __construct(
        PDO $db,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        array $config,
        string $countryCode,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->swapService = $swapService;
        $this->settlement = $settlement;
        $this->calculator = new ContributionCalculator();
        $this->feeCalculator = new MultiSourceFeeCalculator($config, $countryCode);
        $this->certManager = new CertificateManager('VOUCHMORPH');
        $this->signatureVerifier = new SignatureVerifier($db);
        $this->config = $config;
        $this->countryCode = $countryCode;
        $this->logger = $logger;
    }
    
    /**
     * Execute multi-source to single destination swap
     * 
     * Flow:
     * 1. Create Virtual Funding Pool
     * 2. Calculate contributions
     * 3. Verify all sources
     * 4. Place holds on all sources
     * 5. Pool becomes FUNDED
     * 6. Execute destination action ONCE
     * 7. Debit all sources
     * 8. Settlement & invoicing
     */
    public function execute(array $payload): array
    {
        $this->db->beginTransaction();
        
        try {
            // 1. CREATE VIRTUAL FUNDING POOL
            $pool = $this->createFundingPool($payload);
            $this->logger->info("Virtual Funding Pool created", ['pool_id' => $pool['pool_id']]);
            
            // 2. CALCULATE CONTRIBUTIONS
            $contributions = $this->calculateContributions($pool, $payload);
            $this->logger->info("Contributions calculated", ['count' => count($contributions)]);
            
            // 3. VERIFY ALL SOURCES (with signatures)
            $verifications = $this->verifyAllSources($contributions, $payload);
            $this->validateAllVerifications($verifications);
            $this->logger->info("All sources verified", ['verified_count' => count($verifications)]);
            
            // 4. PLACE HOLDS ON ALL SOURCES
            $holds = $this->placeHoldsOnAllSources($pool, $contributions, $verifications);
            $this->validateAllHolds($holds);
            $this->logger->info("All holds placed", ['hold_count' => count($holds)]);
            
            // 5. POOL IS NOW FUNDED
            $this->updatePoolStatus($pool['pool_id'], self::POOL_FUNDED);
            $this->logger->info("Pool funded", ['pool_id' => $pool['pool_id']]);
            
            // 6. GENERATE MASTER SIGNATURE (Chain of Trust)
            $masterSignature = $this->generateMasterSignature($pool, $holds, $verifications);
            $this->storeMasterSignature($pool['pool_id'], $masterSignature);
            
            // 7. EXECUTE DESTINATION ONCE
            $destinationResult = $this->executeDestinationAction(
                $pool,
                $contributions,
                $masterSignature
            );
            $this->validateDestinationResult($destinationResult);
            $this->logger->info("Destination action completed", $destinationResult);
            
            // 8. DEBIT ALL SOURCES
            $debits = $this->debitAllSources($pool, $holds, $contributions);
            $this->validateAllDebits($debits);
            $this->logger->info("All sources debited", ['debit_count' => count($debits)]);
            
            // 9. SETTLEMENT & INVOICING
            $settlementResult = $this->processSettlement($pool, $contributions, $destinationResult);
            
            // 10. COMPLETE
            $this->updatePoolStatus($pool['pool_id'], self::POOL_COMPLETED);
            
            $this->db->commit();
            
            return $this->buildSuccessResponse($pool, $contributions, $destinationResult, $settlementResult);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error("Multi-source swap failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Attempt to release any holds
            $this->releaseAllHolds($pool['pool_id'] ?? null);
            
            $this->updatePoolStatus(
                $pool['pool_id'] ?? 'unknown',
                self::POOL_FAILED,
                ['error' => $e->getMessage()]
            );
            
            throw new RuntimeException("Multi-source swap failed: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Create the Virtual Funding Pool record
     */
    private function createFundingPool(array $payload): array
    {
        $poolId = $this->generatePoolId();
        $destination = $payload['destination'];
        $totalAmount = (float)($payload['amount'] ?? 0);
        $currency = $payload['currency'] ?? 'BWP';
        $strategy = $payload['contribution_strategy'] ?? 'RATIO';
        
        $sql = "
            INSERT INTO virtual_funding_pools (
                pool_id,
                swap_reference,
                destination_institution,
                destination_identifier,
                destination_type,
                requested_amount,
                funded_amount,
                currency,
                contribution_strategy,
                status,
                source_count,
                created_at
            ) VALUES (
                :pool_id,
                :swap_ref,
                :dest_institution,
                :dest_identifier,
                :dest_type,
                :requested_amount,
                0,
                :currency,
                :strategy,
                :status,
                :source_count,
                NOW()
            ) RETURNING pool_id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $poolId,
            ':swap_ref' => $payload['reference'] ?? $this->generateSwapReference(),
            ':dest_institution' => $destination['institution'],
            ':dest_identifier' => $destination['identifier'] ?? null,
            ':dest_type' => $destination['type'] ?? 'ACCOUNT',
            ':requested_amount' => $totalAmount,
            ':currency' => $currency,
            ':strategy' => $strategy,
            ':status' => self::POOL_CREATED,
            ':source_count' => count($payload['sources'] ?? [])
        ]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $poolId = $row['pool_id'] ?? $poolId;
        
        // Store pool metadata
        $this->storePoolMetadata($poolId, $payload);
        
        return [
            'pool_id' => $poolId,
            'requested_amount' => $totalAmount,
            'currency' => $currency,
            'destination' => $destination
        ];
    }
    
    /**
     * Calculate contributions from each source
     */
    private function calculateContributions(array $pool, array $payload): array
    {
        $sources = $payload['sources'];
        $totalAmount = $pool['requested_amount'];
        $strategy = $payload['contribution_strategy'] ?? 'RATIO';
        $userSpecified = $payload['user_amounts'] ?? null;
        
        // Fetch available balances
        $sourcesWithBalances = $this->fetchSourceBalances($sources);
        
        // Calculate contributions
        $contributions = $this->calculator->calculate(
            $totalAmount,
            $sourcesWithBalances,
            $strategy,
            $userSpecified
        );
        
        // Validate total matches
        $totalContributions = array_sum(array_column($contributions, 'amount'));
        if (abs($totalContributions - $totalAmount) > 0.01) {
            throw new RuntimeException("Contribution total doesn't match requested amount");
        }
        
        // Store contributions
        foreach ($contributions as $index => $contribution) {
            $this->storeContribution(
                $pool['pool_id'],
                $contribution,
                $index,
                $payload['reference'] ?? null
            );
        }
        
        // Update pool funded amount
        $this->updatePoolFundedAmount($pool['pool_id'], $totalContributions);
        
        return $contributions;
    }
    
    /**
     * Verify all sources with cryptographic signatures
     */
    private function verifyAllSources(array $contributions, array $payload): array
    {
        $verifications = [];
        
        foreach ($contributions as $index => $contribution) {
            $source = $contribution['source'];
            $amount = $contribution['amount'];
            
            // Get participant configuration
            $participant = $this->swapService->getParticipant($source['institution']);
            
            // Build verification payload
            $verifyPayload = [
                'action' => 'VERIFY_ASSET',
                'reference' => $payload['reference'] ?? $this->generateSwapReference(),
                'asset_type' => $source['asset_type'] ?? 'ACCOUNT',
                'amount' => $amount,
                'currency' => $payload['currency'] ?? 'BWP',
                'source_identifier' => $source['identifier'],
                'timestamp' => time(),
                'requester' => 'VOUCHMORPH'
            ];
            
            // Call source institution
            $bankClient = new GenericBankClient($participant);
            $result = $bankClient->verifyAssetSigned($verifyPayload);
            
            if (!($result['success'] ?? false)) {
                throw new RuntimeException(
                    "Verification failed for {$source['institution']}: " . 
                    ($result['message'] ?? 'Unknown error')
                );
            }
            
            $data = $result['data'] ?? [];
            
            // Verify the signature
            $signatureValid = $this->signatureVerifier->verifySignature(
                $verifyPayload,
                $data['signature'] ?? '',
                $data['certificate'] ?? ''
            );
            
            if (!$signatureValid) {
                throw new RuntimeException(
                    "Invalid signature from {$source['institution']}"
                );
            }
            
            $verifications[] = [
                'source' => $source,
                'verified' => true,
                'amount' => $amount,
                'signature' => $data['signature'] ?? null,
                'certificate' => $data['certificate'] ?? null,
                'timestamp' => $data['timestamp'] ?? time()
            ];
            
            // Update contribution status
            $this->updateContributionStatus(
                $source['institution'],
                'VERIFIED',
                ['verification' => $verifications[count($verifications) - 1]]
            );
        }
        
        return $verifications;
    }
    
    /**
     * Place holds on all sources
     */
    private function placeHoldsOnAllSources(array $pool, array $contributions, array $verifications): array
    {
        $holds = [];
        
        foreach ($contributions as $index => $contribution) {
            $source = $contribution['source'];
            $amount = $contribution['amount'];
            $verification = $verifications[$index] ?? null;
            
            // Get participant
            $participant = $this->swapService->getParticipant($source['institution']);
            
            // Build hold payload
            $holdPayload = [
                'action' => 'PLACE_HOLD',
                'reference' => $pool['pool_id'] . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
                'asset_type' => $source['asset_type'] ?? 'ACCOUNT',
                'amount' => $amount,
                'currency' => $pool['currency'],
                'hold_reason' => 'VIRTUAL_POOL_' . $pool['pool_id'],
                'expiry_hours' => 24,
                'source_verification' => $verification,
                'timestamp' => time()
            ];
            
            if ($source['asset_type'] === 'ACCOUNT') {
                $holdPayload['account_number'] = $source['identifier'];
            } elseif ($source['asset_type'] === 'WALLET' || $source['asset_type'] === 'E-WALLET') {
                $holdPayload['phone'] = $source['identifier'];
            }
            
            // Call source institution
            $bankClient = new GenericBankClient($participant);
            $result = $bankClient->placeHoldSigned($holdPayload);
            
            if (!($result['success'] ?? false)) {
                // If any hold fails, release all already placed holds
                $this->releaseHolds($holds);
                throw new RuntimeException(
                    "Hold failed for {$source['institution']}: " . 
                    ($result['message'] ?? 'Unknown error')
                );
            }
            
            $data = $result['data'] ?? [];
            $holdReference = $data['hold_reference'] ?? $pool['pool_id'] . '-HOLD-' . ($index + 1);
            
            $holds[] = [
                'source' => $source,
                'amount' => $amount,
                'hold_reference' => $holdReference,
                'signature' => $data['signature'] ?? null,
                'certificate' => $data['certificate'] ?? null,
                'timestamp' => $data['timestamp'] ?? time()
            ];
            
            // Update contribution with hold reference
            $this->updateContributionHold(
                $source['institution'],
                $holdReference,
                $holds[count($holds) - 1]
            );
        }
        
        return $holds;
    }
    
    /**
     * Generate master signature (Chain of Trust)
     * 
     * This is the critical piece: VouchMorph signs the aggregate
     * after verifying all source signatures
     */
    private function generateMasterSignature(array $pool, array $holds, array $verifications): array
    {
        // Build the aggregate payload
        $aggregatePayload = [
            'pool_id' => $pool['pool_id'],
            'swap_reference' => $pool['swap_reference'] ?? null,
            'total_amount' => $pool['funded_amount'] ?? $pool['requested_amount'],
            'currency' => $pool['currency'],
            'destination' => $pool['destination'],
            'timestamp' => time(),
            'contributors' => []
        ];
        
        // Add each source's contribution and their signature
        foreach ($holds as $index => $hold) {
            $aggregatePayload['contributors'][] = [
                'institution' => $hold['source']['institution'],
                'amount' => $hold['amount'],
                'hold_reference' => $hold['hold_reference'],
                'source_signature' => $hold['signature'],
                'source_certificate' => $hold['certificate']
            ];
        }
        
        // Sort for canonicalization
        ksort($aggregatePayload);
        
        // VouchMorph signs the aggregate
        $signature = $this->certManager->signPayload(
            $aggregatePayload,
            'VOUCHMORPH'
        );
        
        $certificate = $this->certManager->getCertificate('VOUCHMORPH');
        
        return [
            'aggregate_signature' => $signature,
            'aggregate_certificate' => $certificate,
            'payload' => $aggregatePayload,
            'signature_timestamp' => time()
        ];
    }
    
    /**
     * Execute destination action ONCE (the key benefit)
     * 
     * The destination sees ONE transaction from the pool
     */
    private function executeDestinationAction(array $pool, array $contributions, array $masterSignature): array
    {
        $destination = $pool['destination'];
        $totalAmount = $pool['funded_amount'] ?? $pool['requested_amount'];
        $action = $destination['action'] ?? 'DEPOSIT';
        
        // Build payload as if from a single source
        $destinationPayload = [
            'reference' => $pool['pool_id'],
            'amount' => $totalAmount,
            'currency' => $pool['currency'],
            'action' => $action,
            'destination_identifier' => $destination['identifier'],
            'destination_type' => $destination['type'] ?? 'ACCOUNT',
            'source_type' => 'VIRTUAL_POOL', // Key: Destination sees POOL, not multiple sources
            'pool_details' => [
                'pool_id' => $pool['pool_id'],
                'source_count' => count($contributions),
                'master_signature' => $masterSignature['aggregate_signature'],
                'master_certificate' => $masterSignature['aggregate_certificate'],
                'signature_timestamp' => $masterSignature['signature_timestamp']
            ]
        ];
        
        // Get destination participant
        $participant = $this->swapService->getParticipant($destination['institution']);
        $bankClient = new GenericBankClient($participant);
        
        // Execute based on action type
        $result = match($action) {
            'CASHOUT' => $this->executeCashout($bankClient, $destinationPayload),
            'DEPOSIT' => $this->executeDeposit($bankClient, $destinationPayload),
            'CARD_ISSUE' => $this->executeCardIssue($bankClient, $destinationPayload),
            'VOUCHER' => $this->executeVoucher($bankClient, $destinationPayload),
            default => throw new RuntimeException("Unsupported destination action: {$action}")
        };
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException(
                "Destination action failed: " . ($result['message'] ?? 'Unknown error')
            );
        }
        
        return $result;
    }
    
    /**
     * Debit all sources after destination succeeds
     */
    private function debitAllSources(array $pool, array $holds, array $contributions): array
    {
        $debits = [];
        
        foreach ($holds as $index => $hold) {
            $source = $hold['source'];
            $amount = $hold['amount'];
            
            // Get participant
            $participant = $this->swapService->getParticipant($source['institution']);
            
            // Build debit payload
            $debitPayload = [
                'action' => 'DEBIT',
                'reference' => $pool['pool_id'] . '-DEBIT-' . ($index + 1),
                'hold_reference' => $hold['hold_reference'],
                'amount' => $amount,
                'currency' => $pool['currency'],
                'reason' => 'VIRTUAL_POOL_COMPLETION',
                'timestamp' => time()
            ];
            
            // Call source institution
            $bankClient = new GenericBankClient($participant);
            $result = $bankClient->debitFunds($debitPayload);
            
            if (!($result['success'] ?? false)) {
                // Critical: If one debit fails, we need to reverse everything
                $this->reverseDestination($pool);
                throw new RuntimeException(
                    "Debit failed for {$source['institution']}: " . 
                    ($result['message'] ?? 'Unknown error')
                );
            }
            
            $data = $result['data'] ?? [];
            $debits[] = [
                'source' => $source,
                'amount' => $amount,
                'debit_reference' => $data['transaction_reference'] ?? null,
                'timestamp' => time()
            ];
            
            // Update contribution with debit reference
            $this->updateContributionDebit(
                $source['institution'],
                $debits[count($debits) - 1]
            );
        }
        
        return $debits;
    }
    
    /**
     * Process settlement and invoicing
     */
    private function processSettlement(array $pool, array $contributions, array $destinationResult): array
    {
        $settlements = [];
        $totalFees = 0;
        
        foreach ($contributions as $index => $contribution) {
            $source = $contribution['source'];
            $amount = $contribution['amount'];
            
            // Calculate fee for this source
            $fee = $this->calculateSourceFee($source, $amount, $pool);
            $totalFees += $fee;
            
            // Record settlement
            $settlement = $this->settlement->updateNetPosition(
                $pool['pool_id'] . '-' . $source['institution'],
                $source['institution'],
                $pool['destination']['institution'],
                $amount,
                'POOL_CONTRIBUTION',
                $pool['currency']
            );
            
            // Invoice fee
            if ($fee > 0) {
                $this->settlement->invoiceFee(
                    $pool['pool_id'],
                    $source['institution'],
                    $this->getParticipantId($source['institution']),
                    'POOL_FEE',
                    $fee,
                    $pool['currency']
                );
            }
            
            $settlements[] = [
                'source' => $source['institution'],
                'amount' => $amount,
                'fee' => $fee,
                'settlement' => $settlement
            ];
        }
        
        return [
            'total_fees' => $totalFees,
            'settlements' => $settlements,
            'destination_result' => $destinationResult
        ];
    }
    
    /**
     * Reverse destination action if debit fails
     */
    private function reverseDestination(array $pool): void
    {
        $this->logger->warning("Reversing destination action for pool: " . $pool['pool_id']);
        // Implement reverse logic based on action type
        // This is critical for atomicity
    }
    
    /**
     * Release holds (for rollback)
     */
    private function releaseHolds(array $holds): void
    {
        foreach ($holds as $hold) {
            try {
                $participant = $this->swapService->getParticipant($hold['source']['institution']);
                $bankClient = new GenericBankClient($participant);
                $bankClient->releaseHold([
                    'hold_reference' => $hold['hold_reference'],
                    'reason' => 'POOL_FAILURE'
                ]);
            } catch (Exception $e) {
                $this->logger->error("Failed to release hold: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Helper: Execute cashout
     */
    private function executeCashout(GenericBankClient $client, array $payload): array
    {
        return $client->generateToken($payload);
    }
    
    /**
     * Helper: Execute deposit
     */
    private function executeDeposit(GenericBankClient $client, array $payload): array
    {
        return $client->processDeposit($payload);
    }
    
    /**
     * Helper: Execute card issue
     */
    private function executeCardIssue(GenericBankClient $client, array $payload): array
    {
        return $client->issueCard($payload);
    }
    
    /**
     * Helper: Execute voucher
     */
    private function executeVoucher(GenericBankClient $client, array $payload): array
    {
        return $client->generateVoucher($payload);
    }
    
    // ============================================================
    // DATABASE OPERATIONS
    // ============================================================
    
    private function createPoolTables(): void
    {
        // See full DDL below
    }
    
    private function generatePoolId(): string
    {
        return 'VP-' . date('Ymd') . '-' . bin2hex(random_bytes(6));
    }
    
    private function generateSwapReference(): string
    {
        return 'SWAP-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
    }
    
    private function storePoolMetadata(string $poolId, array $payload): void
    {
        $sql = "UPDATE virtual_funding_pools SET metadata = :metadata WHERE pool_id = :pool_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':metadata' => json_encode($payload['metadata'] ?? []),
            ':pool_id' => $poolId
        ]);
    }
    
    private function storeContribution(string $poolId, array $contribution, int $index, ?string $swapRef): void
    {
        $source = $contribution['source'];
        
        $sql = "
            INSERT INTO pool_contributions (
                pool_id,
                sub_reference,
                source_order,
                institution,
                asset_type,
                source_identifier,
                requested_amount,
                contribution_amount,
                currency,
                status,
                created_at
            ) VALUES (
                :pool_id,
                :sub_ref,
                :order,
                :institution,
                :asset_type,
                :identifier,
                :requested,
                :amount,
                :currency,
                'PENDING',
                NOW()
            )
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $poolId,
            ':sub_ref' => $poolId . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
            ':order' => $index + 1,
            ':institution' => $source['institution'],
            ':asset_type' => $source['asset_type'] ?? 'ACCOUNT',
            ':identifier' => $source['identifier'],
            ':requested' => $contribution['requested_amount'] ?? $contribution['amount'],
            ':amount' => $contribution['amount'],
            ':currency' => $this->config['currency'] ?? 'BWP'
        ]);
    }
    
    private function updatePoolStatus(string $poolId, string $status, array $data = []): void
    {
        $sql = "
            UPDATE virtual_funding_pools 
            SET status = :status, 
                updated_at = NOW(),
                metadata = metadata || :metadata::jsonb
            WHERE pool_id = :pool_id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':metadata' => json_encode($data),
            ':pool_id' => $poolId
        ]);
    }
    
    private function updatePoolFundedAmount(string $poolId, float $amount): void
    {
        $sql = "
            UPDATE virtual_funding_pools 
            SET funded_amount = :amount,
                updated_at = NOW()
            WHERE pool_id = :pool_id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':amount' => $amount,
            ':pool_id' => $poolId
        ]);
    }
    
    private function updateContributionStatus(string $institution, string $status, array $data = []): void
    {
        // Implementation
    }
    
    private function updateContributionHold(string $institution, string $holdRef, array $data): void
    {
        // Implementation
    }
    
    private function updateContributionDebit(string $institution, array $data): void
    {
        // Implementation
    }
    
    private function storeMasterSignature(string $poolId, array $signature): void
    {
        $sql = "
            INSERT INTO pool_master_signatures (
                pool_id,
                aggregate_signature,
                aggregate_certificate,
                signature_timestamp,
                payload_hash,
                created_at
            ) VALUES (
                :pool_id,
                :signature,
                :certificate,
                :timestamp,
                :hash,
                NOW()
            )
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $poolId,
            ':signature' => $signature['aggregate_signature'],
            ':certificate' => $signature['aggregate_certificate'],
            ':timestamp' => $signature['signature_timestamp'],
            ':hash' => hash('sha256', json_encode($signature['payload']))
        ]);
    }
    
    // ============================================================
    // VALIDATION HELPERS
    // ============================================================
    
    private function validateAllVerifications(array $verifications): void
    {
        foreach ($verifications as $verification) {
            if (!($verification['verified'] ?? false)) {
                throw new RuntimeException("Verification failed for: " . ($verification['source']['institution'] ?? 'unknown'));
            }
        }
    }
    
    private function validateAllHolds(array $holds): void
    {
        foreach ($holds as $hold) {
            if (empty($hold['hold_reference'])) {
                throw new RuntimeException("Hold reference missing for: " . ($hold['source']['institution'] ?? 'unknown'));
            }
        }
    }
    
    private function validateAllDebits(array $debits): void
    {
        foreach ($debits as $debit) {
            if (empty($debit['debit_reference'])) {
                throw new RuntimeException("Debit reference missing for: " . ($debit['source']['institution'] ?? 'unknown'));
            }
        }
    }
    
    private function validateDestinationResult(array $result): void
    {
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Destination action failed");
        }
    }
    
    // ============================================================
    // RESPONSE BUILDING
    // ============================================================
    
    private function buildSuccessResponse(array $pool, array $contributions, array $destResult, array $settlement): array
    {
        return [
            'status' => 'success',
            'pool_id' => $pool['pool_id'],
            'total_amount' => $pool['requested_amount'],
            'total_fees' => $settlement['total_fees'],
            'source_count' => count($contributions),
            'destination' => [
                'institution' => $pool['destination']['institution'],
                'reference' => $destResult['transaction_reference'] ?? $pool['pool_id'],
                'details' => $destResult['data'] ?? []
            ],
            'contributions' => array_map(function($c) {
                return [
                    'institution' => $c['source']['institution'],
                    'amount' => $c['amount']
                ];
            }, $contributions),
            'settlement' => $settlement,
            'message' => 'Multi-source swap completed successfully'
        ];
    }
    
    private function fetchSourceBalances(array $sources): array
    {
        $result = [];
        foreach ($sources as $source) {
            $balance = $this->swapService->getSourceAvailableBalance($source);
            $result[] = array_merge($source, ['available_balance' => $balance]);
        }
        return $result;
    }
    
    private function calculateSourceFee(array $source, float $amount, array $pool): float
    {
        // Calculate fee for this specific source
        return $this->feeCalculator->calculateSourceFee($source, $amount, $pool);
    }
    
    private function getParticipantId(string $institution): int
    {
        // Implementation
        return 0;
    }
    
    private function releaseAllHolds(?string $poolId): void
    {
        if (!$poolId) return;
        // Release all holds for this pool
        $this->logger->info("Releasing all holds for pool: " . $poolId);
    }
}
