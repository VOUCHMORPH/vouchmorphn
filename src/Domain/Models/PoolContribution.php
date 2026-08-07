<?php
declare(strict_types=1);

namespace Domain\Models;

use Domain\ValueObjects\ContributionStatus;

class PoolContribution
{
    private ?int $id;
    private string $poolId;
    private string $subReference;
    private int $sourceOrder;
    private string $institution;
    private string $assetType;
    private string $sourceIdentifier;
    private string $sourceIdentifierType;  // NEW: Store identifier type
    private float $requestedAmount;
    private float $contributionAmount;
    private string $currency;
    private ?string $holdReference;
    private ?string $debitReference;
    private ?string $sourceSignature;
    private ?string $sourceCertificate;
    private ContributionStatus $status;
    private array $metadata;
    private ?\DateTime $createdAt;
    private ?\DateTime $verifiedAt;
    private ?\DateTime $heldAt;
    private ?\DateTime $debitedAt;

    public function __construct(
        string $poolId,
        string $subReference,
        int $sourceOrder,
        string $institution,
        string $assetType,
        string $sourceIdentifier,
        string $sourceIdentifierType,  // NEW parameter
        float $requestedAmount,
        float $contributionAmount,
        string $currency
    ) {
        $this->id = null;
        $this->poolId = $poolId;
        $this->subReference = $subReference;
        $this->sourceOrder = $sourceOrder;
        $this->institution = $institution;
        $this->assetType = $assetType;
        $this->sourceIdentifier = $sourceIdentifier;
        $this->sourceIdentifierType = $sourceIdentifierType;  // NEW
        $this->requestedAmount = $requestedAmount;
        $this->contributionAmount = $contributionAmount;
        $this->currency = $currency;
        $this->holdReference = null;
        $this->debitReference = null;
        $this->sourceSignature = null;
        $this->sourceCertificate = null;
        $this->status = ContributionStatus::PENDING;
        $this->metadata = [];
        $this->createdAt = new \DateTime();
        $this->verifiedAt = null;
        $this->heldAt = null;
        $this->debitedAt = null;
    }

    // ============================================================
    // FACTORY METHOD - Create from array (for repository)
    // ============================================================

    public static function fromArray(array $data): self
    {
        $contribution = new self(
            $data['pool_id'] ?? '',
            $data['sub_reference'] ?? $data['subReference'] ?? '',
            (int)($data['source_order'] ?? $data['sourceOrder'] ?? 0),
            $data['institution'] ?? $data['source_institution'] ?? '',
            $data['asset_type'] ?? $data['assetType'] ?? 'ACCOUNT',
            $data['source_identifier'] ?? $data['sourceIdentifier'] ?? '',
            $data['source_identifier_type'] ?? $data['sourceIdentifierType'] ?? 'auto',  // NEW
            (float)($data['requested_amount'] ?? $data['requestedAmount'] ?? 0),
            (float)($data['contribution_amount'] ?? $data['contributionAmount'] ?? 0),
            $data['currency'] ?? 'BWP'
        );

        if (isset($data['id'])) {
            $contribution->setId((int)$data['id']);
        }

        if (isset($data['hold_reference']) || isset($data['holdReference'])) {
            $contribution->setHoldReference($data['hold_reference'] ?? $data['holdReference'] ?? null);
        }

        if (isset($data['debit_reference']) || isset($data['debitReference'])) {
            $contribution->setDebitReference($data['debit_reference'] ?? $data['debitReference'] ?? null);
        }

        if (isset($data['source_signature']) || isset($data['sourceSignature'])) {
            $contribution->setSourceSignature($data['source_signature'] ?? $data['sourceSignature'] ?? null);
        }

        if (isset($data['source_certificate']) || isset($data['sourceCertificate'])) {
            $contribution->setSourceCertificate($data['source_certificate'] ?? $data['sourceCertificate'] ?? null);
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
            $contribution->setVerifiedAt(new \DateTime($data['verified_at']));
        }

        if (isset($data['held_at'])) {
            $contribution->setHeldAt(new \DateTime($data['held_at']));
        }

        if (isset($data['debited_at'])) {
            $contribution->setDebitedAt(new \DateTime($data['debited_at']));
        }

        return $contribution;
    }

    // ============================================================
    // TO ARRAY (for database storage)
    // ============================================================

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'pool_id' => $this->poolId,
            'sub_reference' => $this->subReference,
            'source_order' => $this->sourceOrder,
            'institution' => $this->institution,
            'asset_type' => $this->assetType,
            'source_identifier' => $this->sourceIdentifier,
            'source_identifier_type' => $this->sourceIdentifierType,  // NEW
            'requested_amount' => $this->requestedAmount,
            'contribution_amount' => $this->contributionAmount,
            'currency' => $this->currency,
            'hold_reference' => $this->holdReference,
            'debit_reference' => $this->debitReference,
            'source_signature' => $this->sourceSignature,
            'source_certificate' => $this->sourceCertificate,
            'status' => $this->status->value,
            'metadata' => json_encode($this->metadata),
            'created_at' => $this->createdAt ? $this->createdAt->format('Y-m-d H:i:s') : null,
            'verified_at' => $this->verifiedAt ? $this->verifiedAt->format('Y-m-d H:i:s') : null,
            'held_at' => $this->heldAt ? $this->heldAt->format('Y-m-d H:i:s') : null,
            'debited_at' => $this->debitedAt ? $this->debitedAt->format('Y-m-d H:i:s') : null
        ];
    }

    // ============================================================
    // STATUS HELPERS
    // ============================================================

    public function isPending(): bool
    {
        return $this->status === ContributionStatus::PENDING;
    }

    public function isVerified(): bool
    {
        return $this->status === ContributionStatus::VERIFIED;
    }

    public function isHeld(): bool
    {
        return $this->status === ContributionStatus::HELD;
    }

    public function isDebited(): bool
    {
        return $this->status === ContributionStatus::DEBITED;
    }

    public function isCompleted(): bool
    {
        return $this->status === ContributionStatus::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === ContributionStatus::FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === ContributionStatus::CANCELLED;
    }

    // ============================================================
    // TRANSITION METHODS
    // ============================================================

    public function markVerified(): void
    {
        $this->status = ContributionStatus::VERIFIED;
        $this->verifiedAt = new \DateTime();
    }

    public function markHeld(): void
    {
        $this->status = ContributionStatus::HELD;
        $this->heldAt = new \DateTime();
    }

    public function markDebited(): void
    {
        $this->status = ContributionStatus::DEBITED;
        $this->debitedAt = new \DateTime();
    }

    public function markCompleted(): void
    {
        $this->status = ContributionStatus::COMPLETED;
    }

    public function markFailed(): void
    {
        $this->status = ContributionStatus::FAILED;
    }

    public function markCancelled(): void
    {
        $this->status = ContributionStatus::CANCELLED;
    }

    // ============================================================
    // GETTERS & SETTERS
    // ============================================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getPoolId(): string
    {
        return $this->poolId;
    }

    public function getSubReference(): string
    {
        return $this->subReference;
    }

    public function getSourceOrder(): int
    {
        return $this->sourceOrder;
    }

    public function getInstitution(): string
    {
        return $this->institution;
    }

    public function getAssetType(): string
    {
        return $this->assetType;
    }

    public function getSourceIdentifier(): string
    {
        return $this->sourceIdentifier;
    }

    public function getSourceIdentifierType(): string  // NEW
    {
        return $this->sourceIdentifierType;
    }

    public function getRequestedAmount(): float
    {
        return $this->requestedAmount;
    }

    public function getContributionAmount(): float
    {
        return $this->contributionAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): ContributionStatus
    {
        return $this->status;
    }

    public function setStatus(ContributionStatus $status): void
    {
        $this->status = $status;
    }

    public function getHoldReference(): ?string
    {
        return $this->holdReference;
    }

    public function setHoldReference(?string $holdReference): void
    {
        $this->holdReference = $holdReference;
    }

    public function getDebitReference(): ?string
    {
        return $this->debitReference;
    }

    public function setDebitReference(?string $debitReference): void
    {
        $this->debitReference = $debitReference;
    }

    public function getSourceSignature(): ?string
    {
        return $this->sourceSignature;
    }

    public function setSourceSignature(?string $sourceSignature): void
    {
        $this->sourceSignature = $sourceSignature;
    }

    public function getSourceCertificate(): ?string
    {
        return $this->sourceCertificate;
    }

    public function setSourceCertificate(?string $sourceCertificate): void
    {
        $this->sourceCertificate = $sourceCertificate;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getSource(): array
    {
        return [
            'institution' => $this->institution,
            'asset_type' => $this->assetType,
            'identifier' => $this->sourceIdentifier,
            'identifier_type' => $this->sourceIdentifierType,
        ];
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getVerifiedAt(): ?\DateTime
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTime $verifiedAt): void
    {
        $this->verifiedAt = $verifiedAt;
    }

    public function getHeldAt(): ?\DateTime
    {
        return $this->heldAt;
    }

    public function setHeldAt(?\DateTime $heldAt): void
    {
        $this->heldAt = $heldAt;
    }

    public function getDebitedAt(): ?\DateTime
    {
        return $this->debitedAt;
    }

    public function setDebitedAt(?\DateTime $debitedAt): void
    {
        $this->debitedAt = $debitedAt;
    }
}
