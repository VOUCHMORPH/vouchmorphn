<?php
declare(strict_types=1);

namespace Domain\Models;

use Domain\ValueObjects\ContributionStatus;

class PoolContribution
{
    private string $poolId;
    private string $subReference;
    private int $sourceOrder;
    private string $institution;
    private string $assetType;
    private string $sourceIdentifier;
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
        float $requestedAmount,
        float $contributionAmount,
        string $currency
    ) {
        $this->poolId = $poolId;
        $this->subReference = $subReference;
        $this->sourceOrder = $sourceOrder;
        $this->institution = $institution;
        $this->assetType = $assetType;
        $this->sourceIdentifier = $sourceIdentifier;
        $this->requestedAmount = $requestedAmount;
        $this->contributionAmount = $contributionAmount;
        $this->currency = $currency;
        $this->status = ContributionStatus::PENDING;
        $this->metadata = [];
        $this->createdAt = new \DateTime();
    }

    // Getters and setters...
    public function getPoolId(): string { return $this->poolId; }
    public function getSubReference(): string { return $this->subReference; }
    public function getInstitution(): string { return $this->institution; }
    public function getContributionAmount(): float { return $this->contributionAmount; }
    public function getStatus(): ContributionStatus { return $this->status; }
    public function setStatus(ContributionStatus $status): void { $this->status = $status; }
    public function getHoldReference(): ?string { return $this->holdReference; }
    public function setHoldReference(?string $holdReference): void { $this->holdReference = $holdReference; }
    public function getDebitReference(): ?string { return $this->debitReference; }
    public function setDebitReference(?string $debitReference): void { $this->debitReference = $debitReference; }
    public function getSourceSignature(): ?string { return $this->sourceSignature; }
    public function setSourceSignature(?string $signature): void { $this->sourceSignature = $signature; }
    public function getSourceCertificate(): ?string { return $this->sourceCertificate; }
    public function setSourceCertificate(?string $certificate): void { $this->sourceCertificate = $certificate; }
    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $metadata): void { $this->metadata = $metadata; }
    
    public function getSource(): array {
        return [
            'institution' => $this->institution,
            'asset_type' => $this->assetType,
            'identifier' => $this->sourceIdentifier
        ];
    }
}
