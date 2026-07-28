<?php

declare(strict_types=1);

namespace Infrastructure\Mojaloop\Dto;

class QuoteRequest
{
    public string $transactionId;
    public string $payerFsp;
    public string $payeeFsp;
    public float $amount;
    public string $currency;
    public array $metadata;

    public function __construct(array $data)
    {
        // NOTE: the previous version used $data['transactionId'] etc.
        // directly, which throws an uncaught error on any missing key
        // instead of a controlled 400 response. Using ?? defaults so a
        // malformed request fails predictably further up the call
        // chain rather than with a raw PHP fatal.
        $this->transactionId = $data['transactionId'] ?? bin2hex(random_bytes(12));
        $this->payerFsp = strtoupper($data['payerFsp'] ?? '');
        $this->payeeFsp = strtoupper($data['payeeFsp'] ?? '');
        $this->amount = (float)($data['amount'] ?? 0);
        $this->currency = $data['currency'] ?? 'BWP';
        $this->metadata = $data['metadata'] ?? [];
    }
}
