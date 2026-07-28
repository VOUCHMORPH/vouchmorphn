<?php

declare(strict_types=1);

namespace Infrastructure\Mojaloop\Dto;

class TransferRequest
{
    public string $transferId;
    public string $payerFsp;
    public string $payeeFsp;
    public float $amount;
    public string $currency;
    public string $transactionType;
    public array $metadata;

    public function __construct(array $data)
    {
        $this->transferId = $data['transferId'] ?? bin2hex(random_bytes(16));
        $this->payerFsp = strtoupper($data['payerFsp'] ?? '');
        $this->payeeFsp = strtoupper($data['payeeFsp'] ?? '');
        $this->amount = (float)($data['amount'] ?? 0);
        $this->currency = $data['currency'] ?? 'BWP';
        $this->transactionType = $data['transactionType'] ?? 'TRANSFER';
        $this->metadata = $data['metadata'] ?? [];
    }
}
