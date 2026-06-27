<?php
declare(strict_types=1);

namespace Domain\Repositories;

use PDO;
use Domain\Models\PoolContribution;
use Domain\ValueObjects\ContributionStatus;

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
        
        return PoolContribution::fromArray($data);
    }

    public function findByPoolId(string $poolId): array
    {
        $sql = "SELECT * FROM pool_contributions WHERE pool_id = :pool_id ORDER BY source_order ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':pool_id' => $poolId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $contributions = [];
        foreach ($results as $data) {
            $contributions[] = PoolContribution::fromArray($data);
        }
        
        return $contributions;
    }

    public function findByPoolAndSource(string $poolId, string $institution, string $sourceIdentifier): ?PoolContribution
    {
        $sql = "SELECT * FROM pool_contributions WHERE pool_id = :pool_id AND institution = :institution AND source_identifier = :source_identifier";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $poolId,
            ':institution' => $institution,
            ':source_identifier' => $sourceIdentifier
        ]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        return PoolContribution::fromArray($data);
    }

    public function save(PoolContribution $contribution): PoolContribution
    {
        if ($contribution->getId()) {
            return $this->update($contribution);
        }
        return $this->insert($contribution);
    }

    private function insert(PoolContribution $contribution): PoolContribution
    {
        $data = $contribution->toArray();
        
        $sql = "
            INSERT INTO pool_contributions (
                pool_id, sub_reference, source_order, institution, asset_type,
                source_identifier, requested_amount, contribution_amount, currency,
                hold_reference, debit_reference, source_signature, source_certificate,
                status, metadata, created_at, verified_at, held_at, debited_at
            ) VALUES (
                :pool_id, :sub_reference, :source_order, :institution, :asset_type,
                :source_identifier, :requested_amount, :contribution_amount, :currency,
                :hold_reference, :debit_reference, :source_signature, :source_certificate,
                :status, :metadata::jsonb, :created_at, :verified_at, :held_at, :debited_at
            ) RETURNING id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $data['pool_id'],
            ':sub_reference' => $data['sub_reference'],
            ':source_order' => $data['source_order'],
            ':institution' => $data['institution'],
            ':asset_type' => $data['asset_type'],
            ':source_identifier' => $data['source_identifier'],
            ':requested_amount' => $data['requested_amount'],
            ':contribution_amount' => $data['contribution_amount'],
            ':currency' => $data['currency'],
            ':hold_reference' => $data['hold_reference'],
            ':debit_reference' => $data['debit_reference'],
            ':source_signature' => $data['source_signature'],
            ':source_certificate' => $data['source_certificate'],
            ':status' => $data['status'],
            ':metadata' => $data['metadata'],
            ':created_at' => $data['created_at'],
            ':verified_at' => $data['verified_at'],
            ':held_at' => $data['held_at'],
            ':debited_at' => $data['debited_at']
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $contribution->setId((int)$row['id']);
        }
        
        return $contribution;
    }

    private function update(PoolContribution $contribution): PoolContribution
    {
        $data = $contribution->toArray();
        
        $sql = "
            UPDATE pool_contributions SET
                pool_id = :pool_id,
                sub_reference = :sub_reference,
                source_order = :source_order,
                institution = :institution,
                asset_type = :asset_type,
                source_identifier = :source_identifier,
                requested_amount = :requested_amount,
                contribution_amount = :contribution_amount,
                currency = :currency,
                hold_reference = :hold_reference,
                debit_reference = :debit_reference,
                source_signature = :source_signature,
                source_certificate = :source_certificate,
                status = :status,
                metadata = :metadata::jsonb,
                verified_at = :verified_at,
                held_at = :held_at,
                debited_at = :debited_at,
                updated_at = NOW()
            WHERE id = :id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $data['id'],
            ':pool_id' => $data['pool_id'],
            ':sub_reference' => $data['sub_reference'],
            ':source_order' => $data['source_order'],
            ':institution' => $data['institution'],
            ':asset_type' => $data['asset_type'],
            ':source_identifier' => $data['source_identifier'],
            ':requested_amount' => $data['requested_amount'],
            ':contribution_amount' => $data['contribution_amount'],
            ':currency' => $data['currency'],
            ':hold_reference' => $data['hold_reference'],
            ':debit_reference' => $data['debit_reference'],
            ':source_signature' => $data['source_signature'],
            ':source_certificate' => $data['source_certificate'],
            ':status' => $data['status'],
            ':metadata' => $data['metadata'],
            ':verified_at' => $data['verified_at'],
            ':held_at' => $data['held_at'],
            ':debited_at' => $data['debited_at']
        ]);
        
        return $contribution;
    }

    public function updateStatus(int $id, ContributionStatus $status): bool
    {
        $sql = "UPDATE pool_contributions SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':status' => $status->value, ':id' => $id]);
    }

    public function updateHoldReference(int $id, string $holdReference): bool
    {
        $sql = "UPDATE pool_contributions SET hold_reference = :hold_reference, held_at = NOW(), updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':hold_reference' => $holdReference, ':id' => $id]);
    }

    public function updateDebitReference(int $id, string $debitReference): bool
    {
        $sql = "UPDATE pool_contributions SET debit_reference = :debit_reference, debited_at = NOW(), updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':debit_reference' => $debitReference, ':id' => $id]);
    }

    public function deleteByPoolId(string $poolId): bool
    {
        $sql = "DELETE FROM pool_contributions WHERE pool_id = :pool_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':pool_id' => $poolId]);
    }
}
