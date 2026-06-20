<?php
declare(strict_types=1);

namespace Domain\Repositories;

use PDO;
use Domain\Models\FundingPool;
use Domain\ValueObjects\PoolStatus;

class FundingPoolRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function save(FundingPool $pool): void
    {
        $sql = "
            INSERT INTO virtual_funding_pools (
                pool_id, swap_reference, destination_institution,
                destination_identifier, destination_type, requested_amount,
                funded_amount, currency, contribution_strategy,
                status, source_count, metadata, created_at
            ) VALUES (
                :pool_id, :swap_ref, :dest_institution,
                :dest_identifier, :dest_type, :requested,
                :funded, :currency, :strategy,
                :status, :source_count, :metadata, NOW()
            ) ON CONFLICT (pool_id) DO UPDATE SET
                funded_amount = EXCLUDED.funded_amount,
                status = EXCLUDED.status,
                metadata = EXCLUDED.metadata,
                updated_at = NOW()
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $pool->getPoolId(),
            ':swap_ref' => $pool->getSwapReference(),
            ':dest_institution' => $pool->getDestinationInstitution(),
            ':dest_identifier' => $pool->getDestinationIdentifier(),
            ':dest_type' => $pool->getDestinationType(),
            ':requested' => $pool->getRequestedAmount(),
            ':funded' => $pool->getFundedAmount(),
            ':currency' => $pool->getCurrency(),
            ':strategy' => $pool->getContributionStrategy(),
            ':status' => $pool->getStatus()->value,
            ':source_count' => $pool->getSourceCount(),
            ':metadata' => json_encode($pool->getMetadata())
        ]);
    }

    public function findById(string $poolId): ?FundingPool
    {
        $sql = "SELECT * FROM virtual_funding_pools WHERE pool_id = :pool_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':pool_id' => $poolId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            return null;
        }

        return $this->hydrate($data);
    }

    public function update(FundingPool $pool): void
    {
        $sql = "
            UPDATE virtual_funding_pools 
            SET status = :status,
                funded_amount = :funded,
                metadata = :metadata,
                updated_at = NOW(),
                completed_at = CASE 
                    WHEN :status = 'COMPLETED' THEN NOW() 
                    ELSE completed_at 
                END
            WHERE pool_id = :pool_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $pool->getStatus()->value,
            ':funded' => $pool->getFundedAmount(),
            ':metadata' => json_encode($pool->getMetadata()),
            ':pool_id' => $pool->getPoolId()
        ]);
    }

    public function storeSignature(string $poolId, string $signature, string $certificate, int $timestamp, string $hash): void
    {
        $sql = "
            INSERT INTO pool_master_signatures (
                pool_id, aggregate_signature, aggregate_certificate,
                signature_timestamp, payload_hash, created_at
            ) VALUES (
                :pool_id, :signature, :certificate,
                :timestamp, :hash, NOW()
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $poolId,
            ':signature' => $signature,
            ':certificate' => $certificate,
            ':timestamp' => $timestamp,
            ':hash' => $hash
        ]);
    }

    private function hydrate(array $data): FundingPool
    {
        $pool = new FundingPool(
            $data['pool_id'],
            $data['swap_reference'],
            $data['destination_institution'],
            $data['destination_identifier'],
            $data['destination_type'],
            (float)$data['requested_amount'],
            $data['currency'],
            $data['contribution_strategy'],
            (int)$data['source_count']
        );

        $pool->setFundedAmount((float)$data['funded_amount']);
        $pool->setStatus(PoolStatus::from($data['status']));
        $pool->setMetadata(json_decode($data['metadata'] ?? '{}', true));

        return $pool;
    }
}
