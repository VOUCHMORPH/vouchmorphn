<?php
declare(strict_types=1);

namespace Domain\Services\MultiSource;

use PDO;
use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\ContributionCalculator;
use Domain\Services\MultiSourceFeeCalculator;
use Domain\Repositories\FundingPoolRepository;
use Domain\Repositories\PoolContributionRepository;
use Infrastructure\Crypto\AggregateSigner;
use Infrastructure\Banks\GenericBankClient;
use Psr\Log\LoggerInterface;

class PoolCoordinator
{
    // Properties...
    
    public function execute(array $payload): array
    {
        $this->db->beginTransaction();
        
        try {
            // 1. Create pool
            $pool = $this->createPool($payload);
            
            // 2. Calculate contributions
            $contributions = $this->calculateContributions($pool, $payload);
            
            // 3. Transition to VERIFYING
            $this->stateMachine->transition($pool, PoolStatus::VERIFYING);
            
            // 4. Verify sources
            $verifications = $this->verifySources($contributions, $payload);
            
            // 5. Transition to HOLDING
            $this->stateMachine->transition($pool, PoolStatus::HOLDING);
            
            // 6. Place holds
            $holds = $this->placeHolds($pool, $contributions, $verifications);
            
            // 7. Transition to FUNDED
            $this->stateMachine->transition($pool, PoolStatus::FUNDED);
            
            // 8. Generate master signature
            $masterSignature = $this->aggregateSigner->signAggregate($pool, $holds, $verifications);
            
            // 9. Transition to DESTINATION_PENDING
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_PENDING);
            
            // 10. Execute destination
            $destinationResult = $this->executeDestination($pool, $contributions, $masterSignature);
            
            // 11. Transition to DESTINATION_COMPLETED
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_COMPLETED);
            
            // 12. Debit sources
            $debits = $this->debitSources($pool, $holds);
            
            // 13. Settle
            $settlementResult = $this->settle($pool, $contributions);
            
            // 14. Invoice
            $invoiceResult = $this->invoice($pool, $contributions);
            
            // 15. Complete
            $this->stateMachine->transition($pool, PoolStatus::COMPLETED);
            
            $this->db->commit();
            
            return $this->buildResponse($pool, $contributions, $destinationResult, $settlementResult, $invoiceResult);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->rollback($pool ?? null);
            throw new RuntimeException("Multi-source swap failed: " . $e->getMessage());
        }
    }
}
