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
        
        return $this->hydrate($data);
    }

    public function findByPoolId(string $poolId): array
    {
        $sql = "SELECT * FROM pool_contributions WHERE pool_id = :pool_id ORDER BY source_order ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':pool_id' => $poolId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $contributions = [];
        foreach ($results as $data) {
            $contributions[] = $this->hydrate($data);
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
        
        return $this->hydrate($data);
    }

    public function save(PoolContribution $contribution): PoolContribution
    {
        $data = $contribution->toArray();
        
        if ($contribution->getId()) {
            return $this->update($contribution);
        }
        
        return $this->insert($contribution);
    }

    /**
     * Array-based save, for callers (like PoolCoordinator) that work with
     * plain contribution arrays rather than hydrated PoolContribution objects.
     * Mirrors save() but skips the object requirement entirely.
     */
    public function saveFromArray(array $contribution): array
    {
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
                :status, :metadata::jsonb, NOW(), NULL, NULL, NULL
            ) RETURNING id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pool_id' => $contribution['pool_id'] ?? '',
            ':sub_reference' => $contribution['sub_reference'] ?? '',
            ':source_order' => $contribution['source_order'] ?? 0,
            ':institution' => $contribution['institution'] ?? '',
            ':asset_type' => $contribution['asset_type'] ?? 'ACCOUNT',
            ':source_identifier' => $contribution['source_identifier'] ?? $contribution['account_id'] ?? $contribution['identifier'] ?? '',
            ':requested_amount' => $contribution['requested_amount'] ?? $contribution['amount'] ?? 0,
            ':contribution_amount' => $contribution['contribution_amount'] ?? $contribution['amount'] ?? 0,
            ':currency' => $contribution['currency'] ?? 'BWP',
            ':hold_reference' => $contribution['hold_reference'] ?? null,
            ':debit_reference' => $contribution['debit_reference'] ?? null,
            ':source_signature' => $contribution['source_signature'] ?? null,
            ':source_certificate' => $contribution['source_certificate'] ?? null,
            ':status' => $contribution['status'] ?? ContributionStatus::PENDING->value,
            ':metadata' => isset($contribution['metadata']) ? json_encode($contribution['metadata']) : '{}'
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $contribution['id'] = (int)$row['id'];
        }
        
        return $contribution;
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

    /**
     * Update status with optional metadata - called from PoolCoordinator
     */
    public function updateStatusWithMetadata(int $id, ContributionStatus $status, array $metadata = []): bool
    {
        if (empty($metadata)) {
            return $this->updateStatus($id, $status);
        }
        
        $sql = "
            UPDATE pool_contributions 
            SET status = :status, 
                metadata = COALESCE(metadata, '{}'::jsonb) || :metadata::jsonb,
                updated_at = NOW() 
            WHERE id = :id
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':status' => $status->value,
            ':metadata' => json_encode($metadata),
            ':id' => $id
        ]);
    }

    public function updateHoldReference(int $id, string $holdReference): bool
    {
        $sql = "UPDATE pool_contributions SET hold_reference = :hold_reference, held_at = NOW(), updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':hold_reference' => $holdReference, ':id' => $id]);
    }

    /**
     * Update hold reference with status update in one call
     */
    public function updateHoldAndStatus(int $id, string $holdReference, ContributionStatus $status): bool
    {
        $sql = "
            UPDATE pool_contributions 
            SET hold_reference = :hold_reference, 
                status = :status,
                held_at = NOW(), 
                updated_at = NOW() 
            WHERE id = :id
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':hold_reference' => $holdReference,
            ':status' => $status->value,
            ':id' => $id
        ]);
    }

    public function updateDebitReference(int $id, string $debitReference): bool
    {
        $sql = "UPDATE pool_contributions SET debit_reference = :debit_reference, debited_at = NOW(), updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':debit_reference' => $debitReference, ':id' => $id]);
    }

    /**
     * Update debit reference with status update in one call
     */
    public function updateDebitAndStatus(int $id, string $debitReference, ContributionStatus $status): bool
    {
        $sql = "
            UPDATE pool_contributions 
            SET debit_reference = :debit_reference,
                status = :status,
                debited_at = NOW(), 
                updated_at = NOW() 
            WHERE id = :id
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':debit_reference' => $debitReference,
            ':status' => $status->value,
            ':id' => $id
        ]);
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

    public function getAllByStatus(ContributionStatus $status): array
    {
        $sql = "SELECT * FROM pool_contributions WHERE status = :status ORDER BY created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':status' => $status->value]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $contributions = [];
        foreach ($results as $data) {
            $contributions[] = $this->hydrate($data);
        }
        
        return $contributions;
    }

    public function getAllByPoolIdAndStatus(string $poolId, ContributionStatus $status): array
    {
        $sql = "SELECT * FROM pool_contributions WHERE pool_id = :pool_id AND status = :status ORDER BY source_order ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':pool_id' => $poolId, ':status' => $status->value]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $contributions = [];
        foreach ($results as $data) {
            $contributions[] = $this->hydrate($data);
        }
        
        return $contributions;
    }

    /**
     * Get all contributions as arrays (not objects) for PoolCoordinator
     */
    public function getAllByPoolIdAsArray(string $poolId): array
    {
        $sql = "SELECT * FROM pool_contributions WHERE pool_id = :pool_id ORDER BY source_order ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':pool_id' => $poolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single contribution as array (not object) for PoolCoordinator
     */
    public function findByIdAsArray(int $id): ?array
    {
        $sql = "SELECT * FROM pool_contributions WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    private function hydrate(array $data): PoolContribution
    {
        $contribution = new PoolContribution(
            $data['pool_id'],
            $data['sub_reference'],
            (int)$data['source_order'],
            $data['institution'],
            $data['asset_type'] ?? 'ACCOUNT',
            $data['source_identifier'],
            (float)$data['requested_amount'],
            (float)$data['contribution_amount'],
            $data['currency'] ?? 'BWP'
        );

        if (isset($data['id'])) {
            $contribution->setId((int)$data['id']);
        }

        if (isset($data['hold_reference'])) {
            $contribution->setHoldReference($data['hold_reference']);
        }

        if (isset($data['debit_reference'])) {
            $contribution->setDebitReference($data['debit_reference']);
        }

        if (isset($data['source_signature'])) {
            $contribution->setSourceSignature($data['source_signature']);
        }

        if (isset($data['source_certificate'])) {
            $contribution->setSourceCertificate($data['source_certificate']);
        }

        if (isset($data['status'])) {
            $contribution->setStatus(ContributionStatus::from($data['status']));
        }

        if (isset($data['metadata'])) {
            $contribution->setMetadata(is_string($data['metadata']) ? json_decode($data['metadata'], true) : $data['metadata']);
        }

        if (isset($data['created_at'])) {
            $contribution->setCreatedAt(new \DateTime($data['created_at']));
        }

        if (isset($data['verified_at'])) {
            $contribution->setVerifiedAt($data['verified_at'] ? new \DateTime($data['verified_at']) : null);
        }

        if (isset($data['held_at'])) {
            $contribution->setHeldAt($data['held_at'] ? new \DateTime($data['held_at']) : null);
        }

        if (isset($data['debited_at'])) {
            $contribution->setDebitedAt($data['debited_at'] ? new \DateTime($data['debited_at']) : null);
        }

        return $contribution;
    }
}
