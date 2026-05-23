<?php
namespace VouchMorph\Infrastructure\MessageAdapters;

use VouchMorph\Core\Transaction\InternalTransaction;

class MobileMoneyAdapter implements MessageAdapterInterface {
    private string $countryCode;
    private array $countryConfig;
    private string $version;
    private array $providerFormats;
    
    public function __construct(string $countryCode = null) {
        $this->countryCode = strtolower($countryCode ?? 'bw');
        
        // Load country-specific mobile money configuration
        $configPath = __DIR__ . "/../../Config/countries/{$this->countryCode}/mobile_money.php";
        
        if (file_exists($configPath)) {
            $this->countryConfig = require $configPath;
        } else {
            // Default mobile money format (JSON-based)
            $this->countryConfig = [
                'version' => '1.0',
                'encoding' => 'JSON',
                'required_fields' => ['msisdn', 'amount', 'currency', 'reference'],
                'provider_mappings' => [
                    'default' => [
                        'format' => 'json',
                        'endpoint_pattern' => '/api/momo/v1/transfer'
                    ]
                ]
            ];
        }
        
        $this->version = $this->countryConfig['version'] ?? '1.0';
        $this->providerFormats = $this->countryConfig['provider_mappings'] ?? [];
    }
    
    public function toExternal(InternalTransaction $transaction): string {
        // Detect mobile money provider from bank code or metadata
        $provider = $this->detectProvider($transaction);
        $format = $this->getProviderFormat($provider);
        
        switch ($format['type'] ?? 'json') {
            case 'json':
                return $this->buildJsonMessage($transaction, $provider);
            case 'xml':
                return $this->buildXmlMessage($transaction, $provider);
            case 'usSD':
                return $this->buildUssdMessage($transaction, $provider);
            default:
                return $this->buildJsonMessage($transaction, $provider);
        }
    }
    
    public function toInternal(string $message): InternalTransaction {
        // Try to detect format from message content
        $format = $this->detectMessageFormat($message);
        
        $data = [];
        
        if ($format === 'json') {
            $data = $this->parseJsonMessage($message);
        } elseif ($format === 'xml') {
            $data = $this->parseXmlMessage($message);
        } else {
            $data = $this->parseUssdMessage($message);
        }
        
        // Map mobile money fields to internal transaction
        $transactionData = $this->mapToInternalFormat($data);
        $transactionData['messageFormat'] = 'mobile_money';
        $transactionData['messageVersion'] = $this->version;
        $transactionData['countryCode'] = $this->countryCode;
        
        return new InternalTransaction($transactionData);
    }
    
    public function validate(string $message): bool {
        // Basic validation
        if (empty(trim($message))) {
            return false;
        }
        
        // Try JSON validation
        if ($this->isJson($message)) {
            $data = json_decode($message, true);
            return $this->validateRequiredFields($data);
        }
        
        // Try XML validation
        if ($this->isXml($message)) {
            return $this->validateXmlStructure($message);
        }
        
        // Try USSD format validation
        if ($this->isUssdFormat($message)) {
            return $this->validateUssdFormat($message);
        }
        
        return false;
    }
    
    public function getVersion(): string {
        return $this->version;
    }
    
    private function detectProvider(InternalTransaction $transaction): string {
        // Check metadata first
        $metadata = $transaction->getMetadata();
        if (isset($metadata['mobile_money_provider'])) {
            return $metadata['mobile_money_provider'];
        }
        
        // Check bank code
        $bankCode = $transaction->getReceiverBankCode();
        foreach ($this->providerFormats as $provider => $config) {
            if (isset($config['bank_codes']) && in_array($bankCode, $config['bank_codes'])) {
                return $provider;
            }
            if (stripos($bankCode, $provider) !== false) {
                return $provider;
            }
        }
        
        return 'default';
    }
    
    private function getProviderFormat(string $provider): array {
        return $this->providerFormats[$provider] ?? $this->providerFormats['default'] ?? [
            'type' => 'json',
            'version' => '1.0'
        ];
    }
    
    private function buildJsonMessage(InternalTransaction $transaction, string $provider): string {
        $format = $this->getProviderFormat($provider);
        $fieldMap = $format['field_mappings'] ?? $this->getDefaultFieldMappings();
        
        $payload = [];
        
        foreach ($fieldMap as $externalField => $internalField) {
            $method = 'get' . ucfirst($internalField);
            if (method_exists($transaction, $method)) {
                $payload[$externalField] = $transaction->$method();
            }
        }
        
        // Add provider-specific fields
        if (isset($format['static_fields'])) {
            $payload = array_merge($payload, $format['static_fields']);
        }
        
        // Add timestamp
        $payload['timestamp'] = date('Y-m-d\TH:i:sP');
        $payload['provider'] = $provider;
        
        // Wrap in provider-specific structure
        if (isset($format['wrapper'])) {
            $payload = [$format['wrapper'] => $payload];
        }
        
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    private function buildXmlMessage(InternalTransaction $transaction, string $provider): string {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        
        $root = $xml->appendChild($xml->createElement('MobileMoneyTransfer'));
        $root->setAttribute('provider', $provider);
        $root->setAttribute('version', $this->version);
        
        // Add fields
        $fields = [
            'transactionId' => $transaction->getTransactionId(),
            'msisdn' => $transaction->getReceiverAccount(),
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'reference' => $transaction->getReference(),
            'senderName' => $transaction->getSenderName(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        foreach ($fields as $tag => $value) {
            $root->appendChild($xml->createElement($tag, htmlspecialchars((string)$value)));
        }
        
        return $xml->saveXML();
    }
    
    private function buildUssdMessage(InternalTransaction $transaction, string $provider): string {
        // USSD format: *provider*amount*recipient*reference#
        $format = $this->getProviderFormat($provider);
        $pattern = $format['ussd_pattern'] ?? '*{provider}*{amount}*{recipient}*{reference}#';
        
        $replacements = [
            '{provider}' => $provider,
            '{amount}' => $transaction->getAmount(),
            '{recipient}' => $transaction->getReceiverAccount(),
            '{reference}' => substr($transaction->getReference(), 0, 20),
            '{currency}' => $transaction->getCurrency(),
            '{sender}' => $transaction->getSenderAccount()
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $pattern);
    }
    
    private function detectMessageFormat(string $message): string {
        if ($this->isJson($message)) {
            return 'json';
        }
        if ($this->isXml($message)) {
            return 'xml';
        }
        if ($this->isUssdFormat($message)) {
            return 'ussd';
        }
        return 'json';
    }
    
    private function parseJsonMessage(string $message): array {
        $data = json_decode($message, true);
        
        // Unwrap if needed
        if (isset($data['data'])) {
            $data = $data['data'];
        }
        if (isset($data['transfer'])) {
            $data = $data['transfer'];
        }
        
        return $data;
    }
    
    private function parseXmlMessage(string $message): array {
        $xml = simplexml_load_string($message);
        $json = json_encode($xml);
        return json_decode($json, true);
    }
    
    private function parseUssdMessage(string $message): array {
        // Parse USSD format: *provider*amount*recipient*reference#
        $cleaned = trim($message, '*#');
        $parts = explode('*', $cleaned);
        
        return [
            'provider' => $parts[0] ?? '',
            'amount' => $parts[1] ?? 0,
            'recipient' => $parts[2] ?? '',
            'reference' => $parts[3] ?? ''
        ];
    }
    
    private function mapToInternalFormat(array $data): array {
        $map = [
            'transactionId' => ['transactionId', 'id', 'txn_id'],
            'amount' => ['amount', 'value', 'amount_value'],
            'currency' => ['currency', 'ccy', 'currency_code'],
            'reference' => ['reference', 'ref', 'description'],
            'senderAccount' => ['sender', 'from', 'source_msisdn'],
            'receiverAccount' => ['recipient', 'to', 'destination_msisdn', 'msisdn']
        ];
        
        $result = [];
        foreach ($map as $internalField => $possibleKeys) {
            foreach ($possibleKeys as $key) {
                if (isset($data[$key])) {
                    $result[$internalField] = $data[$key];
                    break;
                }
            }
        }
        
        return $result;
    }
    
    private function validateRequiredFields(array $data): bool {
        $required = $this->countryConfig['required_fields'] ?? ['msisdn', 'amount', 'currency'];
        
        foreach ($required as $field) {
            $found = false;
            foreach ($data as $key => $value) {
                if (stripos($key, $field) !== false && !empty($value)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        
        return true;
    }
    
    private function validateXmlStructure(string $message): bool {
        try {
            $xml = simplexml_load_string($message);
            return $xml !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function validateUssdFormat(string $message): bool {
        return preg_match('/^\*.*\*.*\*.*\*.*#$/', $message) === 1;
    }
    
    private function getDefaultFieldMappings(): array {
        return [
            'transactionId' => 'transactionId',
            'msisdn' => 'receiverAccount',
            'amount' => 'amount',
            'currency' => 'currency',
            'reference' => 'reference',
            'senderMsisdn' => 'senderAccount'
        ];
    }
    
    private function isJson(string $string): bool {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    private function isXml(string $string): bool {
        return preg_match('/<[^>]+>/', $string) === 1;
    }
    
    private function isUssdFormat(string $string): bool {
        return strpos($string, '*') !== false && substr($string, -1) === '#';
    }
}
