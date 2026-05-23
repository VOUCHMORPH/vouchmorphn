<?php
namespace Infrastructure\MessageAdapters;

use Core\Transaction\InternalTransaction;

class LegacyAdapter implements MessageAdapterInterface {
    private string $countryCode;
    private array $countryConfig;
    private string $version;
    private string $delimiter;
    private array $fieldOrder;
    
    public function __construct(string $countryCode = null) {
        $this->countryCode = strtolower($countryCode ?? 'bw');
        
        // Load country-specific legacy format configuration
        $configPath = __DIR__ . "/../../config/countries/{$this->countryCode}/legacy_format.php";
        
        if (file_exists($configPath)) {
            $this->countryConfig = require $configPath;
        } else {
            // Use default legacy format
            $this->countryConfig = [
                'delimiter' => '|',
                'field_order' => ['transactionId', 'senderAccount', 'receiverAccount', 'amount', 'currency', 'reference'],
                'version' => '1.0',
                'encoding' => 'UTF-8',
                'date_format' => 'YmdHis'
            ];
        }
        
        $this->delimiter = $this->countryConfig['delimiter'];
        $this->fieldOrder = $this->countryConfig['field_order'];
        $this->version = $this->countryConfig['version'] ?? '1.0';
    }
    
    public function toExternal(InternalTransaction $transaction): string {
        $fields = [];
        
        foreach ($this->fieldOrder as $fieldName) {
            $value = $this->getFieldValue($transaction, $fieldName);
            $fields[] = $this->escapeValue($value);
        }
        
        $message = implode($this->delimiter, $fields);
        
        // Add header if configured
        if (isset($this->countryConfig['add_header']) && $this->countryConfig['add_header']) {
            $header = $this->buildHeader($transaction);
            $message = $header . "\n" . $message;
        }
        
        // Add trailer if configured
        if (isset($this->countryConfig['add_trailer']) && $this->countryConfig['add_trailer']) {
            $trailer = $this->buildTrailer($message);
            $message = $message . "\n" . $trailer;
        }
        
        return $message;
    }
    
    public function toInternal(string $message): InternalTransaction {
        // Remove header and trailer if present
        $lines = explode("\n", trim($message));
        $dataLine = $lines[0];
        
        if (count($lines) > 1) {
            // Has header/trailer structure
            $dataLine = $lines[1] ?? $lines[0];
        }
        
        $parts = explode($this->delimiter, $dataLine);
        $data = [];
        
        foreach ($this->fieldOrder as $index => $fieldName) {
            if (isset($parts[$index])) {
                $data[$fieldName] = $this->unescapeValue($parts[$index]);
            }
        }
        
        // Add metadata from header if present
        if (count($lines) > 2 && isset($this->countryConfig['header_format'])) {
            $data['metadata'] = $this->parseHeader($lines[0]);
        }
        
        // Ensure required fields exist
        $data['messageFormat'] = 'legacy';
        $data['messageVersion'] = $this->version;
        $data['countryCode'] = $this->countryCode;
        
        return new InternalTransaction($data);
    }
    
    public function validate(string $message): bool {
        $lines = explode("\n", trim($message));
        $dataLine = $lines[0];
        
        if (count($lines) > 1) {
            $dataLine = $lines[1] ?? $lines[0];
        }
        
        $parts = explode($this->delimiter, $dataLine);
        
        // Check minimum field count
        if (count($parts) < count($this->fieldOrder)) {
            return false;
        }
        
        // Validate amount is numeric
        $amountIndex = array_search('amount', $this->fieldOrder);
        if ($amountIndex !== false && isset($parts[$amountIndex])) {
            if (!is_numeric($parts[$amountIndex])) {
                return false;
            }
        }
        
        // Country-specific validation
        if (isset($this->countryConfig['validation_pattern'])) {
            $pattern = $this->countryConfig['validation_pattern'];
            if (!preg_match($pattern, $dataLine)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function getVersion(): string {
        return $this->version;
    }
    
    private function getFieldValue(InternalTransaction $transaction, string $fieldName): string {
        $methodMap = [
            'transactionId' => 'getTransactionId',
            'senderAccount' => 'getSenderAccount',
            'receiverAccount' => 'getReceiverAccount',
            'amount' => 'getAmount',
            'currency' => 'getCurrency',
            'reference' => 'getReference',
            'purpose' => 'getPurpose',
            'senderName' => 'getSenderName',
            'receiverName' => 'getReceiverName',
            'timestamp' => 'getTimestamp',
            'senderBankCode' => 'getSenderBankCode',
            'receiverBankCode' => 'getReceiverBankCode'
        ];
        
        if (isset($methodMap[$fieldName])) {
            $method = $methodMap[$fieldName];
            $value = $transaction->$method();
            
            // Format amount without trailing zeros
            if ($fieldName === 'amount') {
                $value = rtrim(rtrim(number_format($value, 2), '0'), '.');
            }
            
            // Format timestamp
            if ($fieldName === 'timestamp' && isset($this->countryConfig['date_format'])) {
                $value = date($this->countryConfig['date_format'], strtotime($value));
            }
            
            return (string)$value;
        }
        
        return '';
    }
    
    private function escapeValue(string $value): string {
        // Escape delimiter if present in value
        if (strpos($value, $this->delimiter) !== false) {
            $value = str_replace($this->delimiter, '\\' . $this->delimiter, $value);
        }
        
        // Escape newlines
        $value = str_replace("\n", "\\n", $value);
        
        return $value;
    }
    
    private function unescapeValue(string $value): string {
        $value = str_replace('\\' . $this->delimiter, $this->delimiter, $value);
        $value = str_replace("\\n", "\n", $value);
        return $value;
    }
    
    private function buildHeader(InternalTransaction $transaction): string {
        $headerFormat = $this->countryConfig['header_format'] ?? 'HDR|{date}|{version}';
        
        $replacements = [
            '{date}' => date('Ymd'),
            '{version}' => $this->version,
            '{country}' => strtoupper($this->countryCode),
            '{bank}' => $transaction->getSenderBankCode()
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $headerFormat);
    }
    
    private function buildTrailer(string $message): string {
        $recordCount = substr_count($message, "\n") + 1;
        $trailerFormat = $this->countryConfig['trailer_format'] ?? 'TRL|{count}';
        
        return str_replace('{count}', (string)$recordCount, $trailerFormat);
    }
    
    private function parseHeader(string $header): array {
        // Simple header parsing - can be enhanced per country
        return ['raw_header' => $header];
    }
}
