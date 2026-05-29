<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;

class MultiSourceSwapExecutor
{
    private PDO $db;
    private SwapService $swapService;
    private ContributionCalculator $calculator;
    private MultiSourceFeeCalculator $feeCalculator;
    private HybridSettlementStrategy $settlement;
    
    public function __construct(
        PDO $db,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        array $config
    ) {
        $this->db = $db;
        $this->swapService = $swapService;
        $this->settlement = $settlement;
        $this->calculator = new ContributionCalculator();
        $this->feeCalculator = new MultiSourceFeeCalculator();
    }
    
    /**
     * Execute multi-source to single destination swap
     */
    public function execute(array $payload): array
    {
        $this->db->beginTransaction();
        
        $masterReference = $payload['master_reference'] ?? $this->generateMasterReference();
        $targetAmount = $payload['destination']['target_amount'];
        $deliveryMode = $payload['destination']['delivery_mode'] ?? 'deposit';
        
        try {
            // 1. Fetch available balances for each source
            $sourcesWithBalances = $this->fetchSourceBalances($payload['sources']);
            
            // 2. Calculate contributions based on strategy
            $strategy = $payload['distribution_strategy'] ?? 'drain_smallest';
            $contributions = $this->calculator->calculateContributions(
                $targetAmount,
                $sourcesWithBalances,
                $strategy,
                $payload['user_specified_amounts'] ?? null
            );
            
            // 3. Calculate fees
            $sourceCount = count($contributions);
            $feeCalculation = $this->feeCalculator->calculateFees(
                $sourceCount,
                $deliveryMode,
                $targetAmount
            );
            
            // 4. Create master record
            $this->createMasterRecord($masterReference, $payload['destination'], $targetAmount, $feeCalculation, $strategy);
            
            // 5. PLACE ALL HOLDS FIRST (Atomic requirement)
            $holds = [];
            $failedHolds = [];
            
            foreach ($contributions as $index => $contribution) {
                try {
                    $holdResult = $this->placeHoldOnSource(
                        $masterReference,
                        $contribution,
                        $index
                    );
                    $holds[] = $holdResult;
                    
                    // Record contribution with hold
                    $this->recordContribution($masterReference, $contribution, $holdResult, $index);
                    
                } catch (Exception $e) {
                    $failedHolds[] = [
                        'source' => $contribution['source']['institution'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // 6. If ANY hold fails, release ALL holds and abort
            if (!empty($failedHolds)) {
                $this->releaseAllHolds($holds);
                throw new RuntimeException(
                    "Failed to place holds on: " . json_encode($failedHolds)
                );
            }
            
            // 7. Update master status
            $this->updateMasterStatus($masterReference, 'holds_placed');
            
            // 8. Process each source debit (with individual fees)
            $debits = [];
            $totalNetAmount = 0;
            $contributionHashes = [];
            
            foreach ($contributions as $index => $contribution) {
                $sourceFee = $feeCalculation['per_source_fees'][$index] ?? 0;
                $netContribution = $contribution['actual_amount'] - $sourceFee;
                
                $debitResult = $this->debitSource(
                    $masterReference,
                    $contribution,
                    $netContribution
                );
                
                // Generate contribution hash for signature
                $contributionHash = $this->generateContributionHash(
                    $masterReference,
                    $contribution,
                    $debitResult
                );
                $contributionHashes[] = $contributionHash;
                
                $debits[] = $debitResult;
                $totalNetAmount += $netContribution;
                
                $this->updateContributionStatus(
                    $masterReference,
                    $contribution['source']['institution'],
                    'debited',
                    $debitResult
                );
            }
            
            // 9. Build master settlement signature from all contribution hashes
            $masterSignature = $this->buildMasterSignature($masterReference, $contributionHashes);
            $this->storeMasterSignature($masterReference, $masterSignature, $contributionHashes);
            
            // 10. Process destination with aggregated funds
            $destinationResult = $this->processDestination(
                $masterReference,
                $payload['destination'],
                $totalNetAmount,
                $masterSignature,
                $contributions
            );
            
            // 11. Queue settlement obligations (each source owes destination)
            $this->queueSettlementObligations($masterReference, $contributions, $payload['destination']);
            
            // 12. Queue fee settlements
            $this->queueFeeSettlements($masterReference, $feeCalculation, $contributions);
            
            $this->updateMasterStatus($masterReference, 'completed', $destinationResult);
            
            $this->db->commit();
            
            return $this->buildResponse($masterReference, $contributions, $feeCalculation, $destinationResult);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->markTransactionFailed($masterReference, $e->getMessage());
            
            return [
                'status' => 'error',
                'master_reference' => $masterReference,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate contribution hash for individual source
     */
    private function generateContributionHash(
        string $masterReference,
        array $contribution,
        array $debitResult
    ): string {
        $data = [
            'master_reference' => $masterReference,
            'institution' => $contribution['source']['institution'],
            'amount' => $contribution['actual_amount'],
            'net_contribution' => $debitResult['net_amount'],
            'timestamp' => date('c'),
            'hold_reference' => $debitResult['hold_reference']
        ];
        
        return hash('sha256', json_encode($data));
    }
    
    /**
     * Build master signature from all contribution hashes
     * MASTER_SIG = HASH(HASH1 + HASH2 + HASH3 + ...)
     */
    private function buildMasterSignature(string $masterReference, array $contributionHashes): string
    {
        $combinedHashes = implode('', $contributionHashes);
        $masterHash = hash('sha256', $combinedHashes);
        
        // Add master reference for uniqueness
        return hash('sha256', $masterReference . $masterHash);
    }
    
    /**
     * Process destination with composite signature
     */
    private function processDestination(
        string $masterReference,
        array $destination,
        float $totalNetAmount,
        string $masterSignature,
        array $contributions
    ): array {
        $destInstitution = $destination['institution'];
        $deliveryMode = $destination['delivery_mode'] ?? 'deposit';
        
        // Build contribution manifest for destination institution
        $contributionManifest = $this->buildContributionManifest($contributions);
        
        $payload = [
            'reference' => $masterReference,
            'amount' => $totalNetAmount,
            'currency' => $destination['currency'] ?? 'BWP',
            'master_signature' => $masterSignature,
            'contribution_manifest' => $contributionManifest,
            'source_institutions' => array_column($contributions, 'source', 'institution'),
            'is_multi_source' => true
        ];
        
        // Add destination-specific fields
        if ($deliveryMode === 'cashout') {
            $payload['beneficiary_phone'] = $destination['cashout']['beneficiary_phone'] ?? null;
            $payload['action'] = 'GENERATE_ATM_TOKEN';
        } else {
            $payload['destination_account'] = $destination['beneficiary_account'] ?? null;
            $payload['action'] = 'PROCESS_DEPOSIT';
        }
        
        // Send to destination institution with composite signature
        $destParticipant = $this->getParticipant($destInstitution);
        $bankClient = new GenericBankClient($destParticipant);
        
        $result = $bankClient->transfer($payload, $deliveryMode === 'cashout' ? 'generate_atm_code' : 'deposit_direct');
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Destination processing failed: " . ($result['message'] ?? 'Unknown error'));
        }
        
        return [
            'status' => 'success',
            'total_amount' => $totalNetAmount,
            'source_count' => count($contributions),
            'destination_reference' => $result['reference'] ?? null,
            'generated_code' => $result['data']['pin'] ?? $result['data']['atm_pin'] ?? null
        ];
    }
    
    /**
     * Build contribution manifest for destination audit trail
     */
    private function buildContributionManifest(array $contributions): array
    {
        $manifest = [
            'total_contributors' => count($contributions),
            'total_amount' => array_sum(array_column($contributions, 'actual_amount')),
            'contributors' => []
        ];
        
        foreach ($contributions as $contribution) {
            $manifest['contributors'][] = [
                'institution' => $contribution['source']['institution'],
                'amount' => $contribution['actual_amount'],
                'asset_type' => $contribution['source']['asset_type']
            ];
        }
        
        return $manifest;
    }
    
    /**
     * Queue settlement obligations for all sources
     * Each source owes the destination institution
     */
    private function queueSettlementObligations(
        string $masterReference,
        array $contributions,
        array $destination
    ): void {
        $destinationInstitution = $destination['institution'];
        $currency = $destination['currency'] ?? 'BWP';
        
        foreach ($contributions as $contribution) {
            $this->settlement->updateNetPosition(
                $masterReference . '-' . $contribution['source']['institution'],
                $contribution['source']['institution'],
                $destinationInstitution,
                $contribution['actual_amount'],
                'multi_source_contribution',
                $currency
            );
        }
    }
    
    /**
     * Queue fee settlements
     */
    private function queueFeeSettlements(
        string $masterReference,
        array $feeCalculation,
        array $contributions
    ): void {
        $currency = 'BWP';
        
        foreach ($feeCalculation['per_source_fees'] as $index => $fee) {
            if ($fee > 0) {
                $this->settlement->invoiceFee(
                    $masterReference,
                    $contributions[$index]['source']['institution'],
                    0,
                    'MULTI_SOURCE_FEE',
                    $fee,
                    $currency
                );
            }
        }
    }
    
    /**
     * Release all holds if any fail (atomic rollback)
     */
    private function releaseAllHolds(array $holds): void
    {
        foreach ($holds as $hold) {
            try {
                // Call release hold API for each
                $this->releaseHold($hold['hold_reference']);
            } catch (Exception $e) {
                // Log but continue - best effort release
                error_log("Failed to release hold {$hold['hold_reference']}: " . $e->getMessage());
            }
        }
    }
    
    private function generateMasterReference(): string
    {
        return 'VM-MIX-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
    }
    
    private function fetchSourceBalances(array $sources): array { /* Implementation */ }
    private function placeHoldOnSource(string $masterRef, array $contribution, int $index): array { /* Implementation */ }
    private function debitSource(string $masterRef, array $contribution, float $netAmount): array { /* Implementation */ }
    private function createMasterRecord(...): void { /* Implementation */ }
    private function recordContribution(...): void { /* Implementation */ }
    private function updateMasterStatus(...): void { /* Implementation */ }
    private function updateContributionStatus(...): void { /* Implementation */ }
    private function storeMasterSignature(...): void { /* Implementation */ }
    private function markTransactionFailed(...): void { /* Implementation */ }
    private function getParticipant(string $institution): array { /* Implementation */ }
    private function releaseHold(string $holdReference): void { /* Implementation */ }
    private function buildResponse(...): array { /* Implementation */ }
}
