<?php
declare(strict_types=1);

namespace Domain\Repositories;

use PDO;
use Domain\Models\PoolContribution;

class PoolContributionRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?PoolContribution
    {
        $sql = "SELECT * FROM pool_contributions WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return new PoolContribution($data);
    }

    public function findByPoolId(string $poolId): array
    {
        $sql = "SELECT * FROM pool_contributions WHERE pool_id = :pool_id ORDER BY created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':pool_id' => $poolId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $contributions = [];
        foreach ($results as $data) {
            $contributions[] = new PoolContribution($data);
        }
        
        return $contributions;
    }

    public function findByPoolAndSource(string $poolId, string $sourceInstitution, string $sourceIdentifier): ?PoolContribution
    {
        $sql = "SELECT * FROM pool_contributions WHERE pool_id = :pool_id AND source_institution = :source_institution AND source_identifier = :source_identifier";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $poolId,
            ':source_institution' => $sourceInstitution,
            ':source_identifier' => $sourceIdentifier
        ]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return new PoolContribution($data);
    }

    public function create(array $data): PoolContribution
    {
        $sql = "
            INSERT INTO pool_contributions (
                pool_id, source_institution, source_identifier, source_asset_type,
                amount, currency, status, hold_reference, created_at, updated_at
            ) VALUES (
                :pool_id, :source_institution, :source_identifier, :source_asset_type,
                :amount, :currency, :status, :hold_reference, NOW(), NOW()
            ) RETURNING id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $data['pool_id'],
            ':source_institution' => $data['source_institution'],
            ':source_identifier' => $data['source_identifier'],
            ':source_asset_type' => $data['source_asset_type'] ?? 'ACCOUNT',
            ':amount' => $data['amount'],
            ':currency' => $data['currency'] ?? 'BWP',
            ':status' => $data['status'] ?? 'pending',
            ':hold_reference' => $data['hold_reference'] ?? null
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $row ? (int)$row['id'] : 0;
        
        return $this->findById($id);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $sql = "UPDATE pool_contributions SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }

    public function updateHoldReference(int $id, string $holdReference): bool
    {
        $sql = "UPDATE pool_contributions SET hold_reference = :hold_reference, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':hold_reference' => $holdReference, ':id' => $id]);
    }

    public function updateAmount(int $id, float $amount): bool
    {
        $sql = "UPDATE pool_contributions SET amount = :amount, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':amount' => $amount, ':id' => $id]);
    }

    public function deleteByPoolId(string $poolId): bool
    {
        $sql = "DELETE FROM pool_contributions WHERE pool_id = :pool_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':pool_id' => $poolId]);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM pool_contributions WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    public function getAllByStatus(string $status): array
    {
        $sql = "SELECT * FROM pool_contributions WHERE status = :status ORDER BY created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':status' => $status]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $contributions = [];
        foreach ($results as $data) {
            $contributions[] = new PoolContribution($data);
        }
        
        return $contributions;
    }
}
