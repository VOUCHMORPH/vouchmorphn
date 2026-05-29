<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Banks\GenericBankClient;

class MultiSourceSwapExecutor
{
    private PDO $db;
    private SwapService $swapService;
    private HybridSettlementStrategy $settlement;
    private ContributionCalculator $calculator;
    private MultiSourceFeeCalculator $feeCalculator;
    private array $participants;
    private string $countryCode;
    
    public function __construct(
        PDO $db,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        array $config,
        string $countryCode
    ) {
        $this->db = $db;
        $this->swapService = $swapService;
        $this->settlement = $settlement;
        $this->calculator = new ContributionCalculator();
        $this->feeCalculator = new MultiSourceFeeCalculator($config, $countryCode);
        $this->participants = $config['participants'] ?? [];
        $this->countryCode = $countryCode;
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
            
            // 5. PLACE ALL HOLDS FIRST
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
                throw new RuntimeException("Failed to place holds on: " . json_encode($failedHolds));
            }
            
            // 7. Update master status
            $this->updateMasterStatus($masterReference, 'holds_placed');
            
            // 8. Process each source debit
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
                
                $contributionHash = $this->generateContributionHash(
                    $masterReference,
                    $contribution,
                    $debitResult
                );
                $contributionHashes[] = $contributionHash;
                $totalNetAmount += $netContribution;
                
                $this->updateContributionStatus(
                    $masterReference,
                    $contribution['source']['institution'],
                    'debited',
                    $debitResult
                );
            }
            
            // 9. Build master signature
            $masterSignature = $this->buildMasterSignature($masterReference, $contributionHashes);
            $this->storeMasterSignature($masterReference, $masterSignature, $contributionHashes);
            
            // 10. Process destination
            $destinationResult = $this->processDestination(
                $masterReference,
                $payload['destination'],
                $totalNetAmount,
                $masterSignature,
                $contributions
            );
            
            // 11. Queue settlement obligations
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
                'message' => $e->getMessage(),
                'master_reference' => $masterReference
            ];
        }
    }
    
    private function generateMasterReference(): string
    {
        return 'MS-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
    }
    
    private function fetchSourceBalances(array $sources): array
    {
        $sourcesWithBalances = [];
        foreach ($sources as $source) {
            $balance = $this->swapService->getSourceAvailableBalance($source);
            $sourcesWithBalances[] = array_merge($source, ['available_balance' => $balance]);
        }
        return $sourcesWithBalances;
    }
    
    private function placeHoldOnSource(string $masterRef, array $contribution, int $index): array
    {
        $source = $contribution['source'];
        $participant = $this->swapService->getParticipant($source['institution']);
        
        $bankClient = new GenericBankClient($participant);
        $holdPayload = [
            'reference' => $masterRef . '-' . $index,
            'asset_type' => $source['asset_type'],
            'amount' => $contribution['actual_amount'],
            'expiry_hours' => 24
        ];
        
        if ($source['asset_type'] === 'ACCOUNT') {
            $holdPayload['account_number'] = $source['identifier'];
        } elseif ($source['asset_type'] === 'WALLET' || $source['asset_type'] === 'E-WALLET') {
            $holdPayload['phone'] = $source['identifier'];
        } elseif ($source['asset_type'] === 'CARD') {
            $holdPayload['card_number'] = $source['identifier'];
        }
        
        $result = $bankClient->placeHold($holdPayload);
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Hold failed: " . ($result['message'] ?? 'Unknown error'));
        }
        
        $data = $result['data'] ?? [];
        return [
            'hold_placed' => true,
            'hold_reference' => $data['hold_reference'] ?? $masterRef . '-' . $index . '-HOLD'
        ];
    }
    
    private function releaseAllHolds(array $holds): void
    {
        foreach ($holds as $hold) {
            // Log but don't throw - best effort
            error_log("Would release hold: " . ($hold['hold_reference'] ?? 'unknown'));
        }
    }
    
    private function createMasterRecord(string $masterRef, array $destination, float $amount, array $feeCalc, string $strategy): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO multi_source_swaps 
            (master_reference, destination_institution, target_amount, destination_currency, 
             distribution_strategy, total_fees, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $masterRef,
            $destination['institution'],
            $amount,
            $destination['currency'] ?? 'BWP',
            $strategy,
            $feeCalc['total_fees']
        ]);
    }
    
    private function recordContribution(string $masterRef, array $contribution, array $holdResult, int $index): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO multi_source_contributions 
            (master_reference, sub_reference, source_order, institution, asset_type, 
             source_identifier, requested_amount, actual_amount, hold_reference, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'hold_placed')
        ");
        $stmt->execute([
            $masterRef,
            $masterRef . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
            $index + 1,
            $contribution['source']['institution'],
            $contribution['source']['asset_type'],
            $contribution['source']['identifier'],
            $contribution['requested_amount'],
            $contribution['actual_amount'],
            $holdResult['hold_reference']
        ]);
    }
    
    private function debitSource(string $masterRef, array $contribution, float $netAmount): array
    {
        // Simplified - in production, call bank API
        return [
            'debited' => true,
            'net_amount' => $netAmount,
            'hold_reference' => $masterRef . '-DEBIT'
        ];
    }
    
    private function generateContributionHash(string $masterRef, array $contribution, array $debitResult): string
    {
        $data = [
            'master_reference' => $masterRef,
            'institution' => $contribution['source']['institution'],
            'amount' => $contribution['actual_amount'],
            'net_contribution' => $debitResult['net_amount']
        ];
        return hash('sha256', json_encode($data));
    }
    
    private function buildMasterSignature(string $masterRef, array $hashes): string
    {
        $combined = implode('', $hashes);
        return hash('sha256', $masterRef . $combined);
    }
    
    private function storeMasterSignature(string $masterRef, string $signature, array $hashes): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO master_settlement_signatures 
            (master_reference, master_signature, contribution_hashes, constructed_at)
            VALUES (?, ?, ?::jsonb, NOW())
        ");
        $stmt->execute([$masterRef, $signature, json_encode($hashes)]);
    }
    
    private function processDestination(string $masterRef, array $destination, float $amount, string $signature, array $contributions): array
    {
        // Simplified - in production, call destination institution
        return [
            'status' => 'success',
            'total_amount' => $amount,
            'source_count' => count($contributions)
        ];
    }
    
    private function queueSettlementObligations(string $masterRef, array $contributions, array $destination): void
    {
        $destInstitution = $destination['institution'];
        $currency = $destination['currency'] ?? 'BWP';
        
        foreach ($contributions as $contribution) {
            $this->settlement->updateNetPosition(
                $masterRef . '-' . $contribution['source']['institution'],
                $contribution['source']['institution'],
                $destInstitution,
                $contribution['actual_amount'],
                'multi_source_contribution',
                $currency
            );
        }
    }
    
    private function queueFeeSettlements(string $masterRef, array $feeCalc, array $contributions): void
    {
        $currency = 'BWP';
        foreach ($feeCalc['per_source_fees'] as $index => $fee) {
            if ($fee > 0 && isset($contributions[$index])) {
                $this->settlement->invoiceFee(
                    $masterRef,
                    $contributions[$index]['source']['institution'],
                    0,
                    'MULTI_SOURCE_FEE',
                    $fee,
                    $currency
                );
            }
        }
    }
    
    private function updateMasterStatus(string $masterRef, string $status, array $result = []): void
    {
        $stmt = $this->db->prepare("
            UPDATE multi_source_swaps 
            SET status = :status, completed_at = NOW(), metadata = :metadata
            WHERE master_reference = :ref
        ");
        $stmt->execute([
            ':status' => $status,
            ':metadata' => json_encode($result),
            ':ref' => $masterRef
        ]);
    }
    
    private function updateContributionStatus(string $masterRef, string $institution, string $status, array $data): void
    {
        $stmt = $this->db->prepare("
            UPDATE multi_source_contributions 
            SET status = :status, completed_at = NOW(), metadata = :metadata
            WHERE master_reference = :ref AND institution = :inst
        ");
        $stmt->execute([
            ':status' => $status,
            ':metadata' => json_encode($data),
            ':ref' => $masterRef,
            ':inst' => $institution
        ]);
    }
    
    private function markTransactionFailed(string $masterRef, string $error): void
    {
        $stmt = $this->db->prepare("
            UPDATE multi_source_swaps 
            SET status = 'failed', error_message = :error, completed_at = NOW()
            WHERE master_reference = :ref
        ");
        $stmt->execute([':error' => $error, ':ref' => $masterRef]);
    }
    
    private function buildResponse(string $masterRef, array $contributions, array $feeCalc, array $destResult): array
    {
        $response = [
            'status' => 'success',
            'master_reference' => $masterRef,
            'total_amount' => array_sum(array_column($contributions, 'actual_amount')),
            'total_fees' => $feeCalc['total_fees'],
            'source_count' => count($contributions),
            'contributions' => []
        ];
        
        foreach ($contributions as $index => $contribution) {
            $response['contributions'][] = [
                'sub_reference' => $masterRef . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
                'institution' => $contribution['source']['institution'],
                'amount' => $contribution['actual_amount'],
                'fee' => $feeCalc['per_source_fees'][$index] ?? 0
            ];
        }
        
        if (isset($destResult['generated_code'])) {
            $response['withdrawal_code'] = $destResult['generated_code'];
        }
        
        return $response;
    }
}
