<?php
declare(strict_types=1);

namespace Infrastructure\Transactions\Contracts;

/**
 * Normalized transaction record - what Domain\Models\Transaction::record()
 * and audit/reporting actually consume, regardless of which rail produced it.
 */
final class NormalizedTransaction
{
    public function __construct(
        public readonly string $type,            // TRANSFER|SWAP|CASHOUT|DEPOSIT
        public readonly string $fromAccount,
        public readonly string $toAccount,
        public readonly string $amount,          // string, preserves decimals
        public readonly string $currency,
        public readonly string $status,          // maps to your state machine: INITIATED/VERIFIED/HELD/SETTLING/SETTLED/FAILED/REVERSED/EXPIRED
        public readonly string $referenceId,
        public readonly float $fee = 0.0,
        public readonly ?string $rawStatusCode = null,   // e.g. ISO8583 field 39, or provider's raw status string
        public readonly array $rawPayload = []           // original message, for audit trail
    ) {}
}

interface TransactionAdapterInterface
{
    /**
     * Normalize a raw transaction/callback payload from a specific rail
     * (ISO8583 response, mobile money webhook, REST response, etc.)
     * into the shape Transaction::record() and reconciliation expect.
     */
    public function normalize(array $rawPayload): NormalizedTransaction;

    /**
     * Map this rail's status code/string to your internal state machine value.
     * (see Core/Config/flows.yaml `states:` block)
     */
    public function mapStatus(string $rawStatus): string;

    public function getRailName(): string; // 'ISO8583', 'MOBILE_MONEY', 'ISO20022', 'REST_GENERIC'
}
