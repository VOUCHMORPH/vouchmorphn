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

    /**
     * FIX: Added ::varchar casts on every occurrence of :status.
     *
     * ROOT CAUSE: PDO named parameters are bound once but referenced
     * multiple times in this query (once in SET, once inside the CASE
     * expression). When the same placeholder appears in contexts where
     * Postgres infers different types for it (a plain column assignment
     * vs. a comparison inside CASE), it can throw:
     *   SQLSTATE[42P08]: Ambiguous parameter: inconsistent types deduced
     *   for parameter $1 - text versus character varying
     * Explicitly casting every occurrence to the same type removes the
     * ambiguity without changing any behavior - the value bound is
     * always a plain string already.
     */
    public function update(FundingPool $pool): void
    {
        $sql = "
            UPDATE virtual_funding_pools 
            SET status = :status::varchar,
                funded_amount = :funded,
                metadata = :metadata,
                updated_at = NOW(),
                completed_at = CASE 
                    WHEN :status::varchar = 'COMPLETED' THEN NOW() 
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

    // ============================================================
    // ARRAY-FRIENDLY METHODS FOR POOLCOORDINATOR
    // ============================================================

    /**
     * Array-based save, for callers (like PoolCoordinator) that work with
     * plain pool arrays rather than hydrated FundingPool objects.
     * Mirrors save() but skips the object requirement entirely.
     */
    public function saveFromArray(array $pool): void
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
            ':pool_id' => $pool['id'],
            ':swap_ref' => $pool['reference'] ?? null,
            ':dest_institution' => $pool['destination_institution'] ?? null,
            ':dest_identifier' => $pool['destination_identifier'] ?? null,
            ':dest_type' => $pool['destination_asset_type'] ?? 'WALLET',
            ':requested' => $pool['amount'] ?? 0,
            ':funded' => $pool['funded_amount'] ?? 0,
            ':currency' => $pool['currency'] ?? 'BWP',
            ':strategy' => $pool['contribution_strategy'] ?? 'RATIO',
            ':status' => is_object($pool['status'] ?? null) ? $pool['status']->value : ($pool['status'] ?? 'CREATED'),
            ':source_count' => count($pool['sources'] ?? []),
            ':metadata' => json_encode($pool['metadata'] ?? [])
        ]);
    }

    /**
     * Array-based lookup, for callers that want a raw row rather than
     * a hydrated FundingPool object.
     */
    public function findByIdAsArray(string $poolId): ?array
    {
        $sql = "SELECT * FROM virtual_funding_pools WHERE pool_id = :pool_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':pool_id' => $poolId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    /**
     * Direct status update by pool ID — did not previously exist.
     * PoolCoordinator::rollback() and ::cancel() call this.
     *
     * FIX: Added ::varchar casts on every occurrence of :status.
     *
     * ROOT CAUSE: this method binds :status once but references it FOUR
     * times in the SQL (once in SET, three times inside the CASE
     * expression's WHEN clauses). PDO/Postgres's type inference for a
     * repeated named placeholder can end up deducing conflicting types
     * across those occurrences, throwing:
     *   SQLSTATE[42P08]: Ambiguous parameter: inconsistent types deduced
     *   for parameter $1 - text versus character varying
     * This was firing on every PoolCoordinator rollback, silently
     * failing to mark the pool FAILED/CANCELLED/COMPLETED even though
     * the calling code logged "Pool rolled back" as if it succeeded.
     * Casting every occurrence to the same type (::varchar) removes the
     * ambiguity - purely additive, no behavior change, since the bound
     * value is always a plain string already.
     */
    public function updateStatus(string $poolId, $status, array $additionalMetadata = []): void
    {
        $statusValue = is_object($status) ? $status->value : $status;

        $sql = "
            UPDATE virtual_funding_pools 
            SET status = :status::varchar,
                metadata = COALESCE(metadata, '{}'::jsonb) || :metadata::jsonb,
                updated_at = NOW(),
                completed_at = CASE 
                    WHEN :status::varchar = 'COMPLETED' THEN NOW() 
                    WHEN :status::varchar = 'FAILED' THEN NOW()
                    WHEN :status::varchar = 'CANCELLED' THEN NOW()
                    ELSE completed_at 
                END
            WHERE pool_id = :pool_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $statusValue,
            ':metadata' => json_encode($additionalMetadata),
            ':pool_id' => $poolId
        ]);
    }

    /**
     * Get pools by status - useful for monitoring/cleanup
     */
    public function findByStatus($status): array
    {
        $statusValue = is_object($status) ? $status->value : $status;
        $sql = "SELECT * FROM virtual_funding_pools WHERE status = :status ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':status' => $statusValue]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pools = [];
        foreach ($results as $data) {
            $pools[] = $this->hydrate($data);
        }
        return $pools;
    }

    /**
     * Get pools by status as arrays (not objects)
     */
    public function findByStatusAsArray($status): array
    {
        $statusValue = is_object($status) ? $status->value : $status;
        $sql = "SELECT * FROM virtual_funding_pools WHERE status = :status ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':status' => $statusValue]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
