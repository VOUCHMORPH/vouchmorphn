<?php
namespace VouchMorph\Core\Transaction;

class InternalTransaction {
    private string $transactionId;
    private string $countryCode;  // Add country
    private string $senderBankCode;
    private string $receiverBankCode;
    private string $senderAccount;
    private string $receiverAccount;
    private float $amount;
    private string $currency;
    private string $reference;
    private string $purpose;
    private array $metadata;
    private string $timestamp;
    private string $messageFormat;
    private string $messageVersion;

    public function __construct(array $data) {
        $this->transactionId = $data['transactionId'] ?? $this->generateTransactionId();
        $this->countryCode = $data['countryCode'] ?? 'BW';  // Default Botswana
        $this->senderBankCode = $data['senderBankCode'] ?? '';
        $this->receiverBankCode = $data['receiverBankCode'] ?? '';
        $this->senderAccount = $data['senderAccount'] ?? '';
        $this->receiverAccount = $data['receiverAccount'] ?? '';
        $this->amount = (float)($data['amount'] ?? 0);
        $this->currency = $data['currency'] ?? $this->getDefaultCurrency();
        $this->reference = $data['reference'] ?? '';
        $this->purpose = $data['purpose'] ?? 'PAYMENT';
        $this->metadata = $data['metadata'] ?? [];
        $this->timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
        $this->messageFormat = $data['messageFormat'] ?? 'unknown';
        $this->messageVersion = $data['messageVersion'] ?? '1.0';
    }
    
    // Getters
    public function getTransactionId(): string { return $this->transactionId; }
    public function getCountryCode(): string { return $this->countryCode; }
    public function getSenderBankCode(): string { return $this->senderBankCode; }
    public function getReceiverBankCode(): string { return $this->receiverBankCode; }
    public function getSenderAccount(): string { return $this->senderAccount; }
    public function getReceiverAccount(): string { return $this->receiverAccount; }
    public function getAmount(): float { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getReference(): string { return $this->reference; }
    public function getPurpose(): string { return $this->purpose; }
    public function getMetadata(): array { return $this->metadata; }
    public function getTimestamp(): string { return $this->timestamp; }
    public function getMessageFormat(): string { return $this->messageFormat; }
    public function getMessageVersion(): string { return $this->messageVersion; }
    
    // Setters
    public function setMessageFormat(string $format): self {
        $this->messageFormat = $format;
        return $this;
    }
    
    public function setMessageVersion(string $version): self {
        $this->messageVersion = $version;
        return $this;
    }
    
    private function getDefaultCurrency(): string {
        $currencies = [
            'BW' => 'BWP',
            'NG' => 'NGN',
            'ZA' => 'ZAR',
            'KE' => 'KES',
            'GH' => 'GHS'
        ];
        return $currencies[$this->countryCode] ?? 'USD';
    }
    
    private function generateTransactionId(): string {
        return $this->countryCode . '_' . time() . '_' . bin2hex(random_bytes(8));
    }
    
    public function toArray(): array {
        return [
            'transactionId' => $this->transactionId,
            'countryCode' => $this->countryCode,
            'senderBankCode' => $this->senderBankCode,
            'receiverBankCode' => $this->receiverBankCode,
            'senderAccount' => $this->senderAccount,
            'receiverAccount' => $this->receiverAccount,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'purpose' => $this->purpose,
            'metadata' => $this->metadata,
            'timestamp' => $this->timestamp,
            'messageFormat' => $this->messageFormat,
            'messageVersion' => $this->messageVersion
        ];
    }
}
