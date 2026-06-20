<?php
declare(strict_types=1);

namespace Domain\Models;

use Domain\ValueObjects\PoolStatus;
use Domain\ValueObjects\ContributionStrategy;

class FundingPool
{
    private string $poolId;
    private string $swapReference;
    private string $destinationInstitution;
    private ?string $destinationIdentifier;
    private string $destinationType;
    private float $requestedAmount;
    private float $fundedAmount;
    private string $currency;
    private ContributionStrategy $strategy;
    private PoolStatus $status;
    private int $sourceCount;
    private array $metadata;
    private ?\DateTime $createdAt;
    private ?\DateTime $updatedAt;
    private ?\DateTime $completedAt;

    public function __construct(
        string $poolId,
        string $swapReference,
        string $destinationInstitution,
        ?string $destinationIdentifier,
        string $destinationType,
        float $requestedAmount,
        string $currency,
        string $strategy,
        int $sourceCount
    ) {
        $this->poolId = $poolId;
        $this->swapReference = $swapReference;
        $this->destinationInstitution = $destinationInstitution;
        $this->destinationIdentifier = $destinationIdentifier;
        $this->destinationType = $destinationType;
        $this->requestedAmount = $requestedAmount;
        $this->fundedAmount = 0;
        $this->currency = $currency;
        $this->strategy = ContributionStrategy::from($strategy);
        $this->status = PoolStatus::CREATED;
        $this->sourceCount = $sourceCount;
        $this->metadata = [];
        $this->createdAt = new \DateTime();
    }

    // Getters and setters...
    public function getPoolId(): string { return $this->poolId; }
    public function getSwapReference(): string { return $this->swapReference; }
    public function getDestinationInstitution(): string { return $this->destinationInstitution; }
    public function getDestinationIdentifier(): ?string { return $this->destinationIdentifier; }
    public function getDestinationType(): string { return $this->destinationType; }
    public function getRequestedAmount(): float { return $this->requestedAmount; }
    public function getFundedAmount(): float { return $this->fundedAmount; }
    public function setFundedAmount(float $amount): void { $this->fundedAmount = $amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getContributionStrategy(): string { return $this->strategy->value; }
    public function getStatus(): PoolStatus { return $this->status; }
    public function setStatus(PoolStatus $status): void { $this->status = $status; }
    public function getSourceCount(): int { return $this->sourceCount; }
    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $metadata): void { $this->metadata = $metadata; }
    
    public function getDestination(): array {
        return [
            'institution' => $this->destinationInstitution,
            'identifier' => $this->destinationIdentifier,
            'type' => $this->destinationType
        ];
    }
}
