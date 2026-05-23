<?php
namespace VouchMorph\Infrastructure\MessageAdapters;

use VouchMorph\Core\Transaction\InternalTransaction;

class Iso8583Adapter implements MessageAdapterInterface {
    private string $countryCode;
    private array $countryConfig;
    private array $defaultConfig;
    private string $version;
    private array $fieldDefinitions;
    private array $mtiDefinitions;
    private array $responseCodes;
    
    public function __construct(string $countryCode = null) {
        $this->countryCode = strtolower($countryCode ?? 'bw');
        
        // Load country-specific ISO 8583 configuration
        $configPath = __DIR__ . "/../../Config/countries/{$this->countryCode}/iso8583.php";
        
        if (file_exists($configPath)) {
            $this->countryConfig = require $configPath;
        } else {
            // Load default configuration (created below)
            $defaultPath = __DIR__ . '/../../Config/iso8583_default.php';
            
            if (!file_exists($defaultPath)) {
                throw new \Exception("Default ISO8583 config not found at: {$defaultPath}");
            }
            
            $this->countryConfig = require $defaultPath;
        }
        
        $this->version = $this->countryConfig['version'] ?? '1987';
        $this->fieldDefinitions = $this->countryConfig['field_definitions'] ?? [];
        $this->mtiDefinitions = $this->countryConfig['mti_definitions'] ?? [];
        $this->responseCodes = $this->countryConfig['response_codes'] ?? [];
    }
    
    public function toExternal(InternalTransaction $transaction): string {
        $messageType = $this->getMti($transaction);
        $bitmap = $this->initializeBitmap();
        $dataElements = [];
        
        // Build data elements from config mappings
        foreach ($this->countryConfig['field_mappings'] ?? [] as $fieldNum => $mapping) {
            $value = $this->getFieldValue($transaction, $mapping);
            
            if ($value !== null && $value !== '') {
                // Mark bitmap bit
                $this->setBitmapBit($bitmap, $fieldNum);
                
                // Format field according to config
                $fieldDef = $this->fieldDefinitions[$fieldNum] ?? [];
                $dataElements[$fieldNum] = $this->formatField($fieldNum, $value, $fieldDef);
            }
        }
        
        // Build the message
        $message = $messageType;
        $message .= $this->buildBitmapString($bitmap);
        
        foreach ($dataElements as $fieldNum => $formattedValue) {
            $message .= $formattedValue;
        }
        
        // Add length header if configured
        if ($this->countryConfig['add_length_header'] ?? true) {
            $length = strlen($message);
            $message = sprintf("%04d", $length) . $message;
        }
        
        return $message;
    }
    
    public function toInternal(string $message): InternalTransaction {
        // Remove length header if present
        $offset = 0;
        if ($this->countryConfig['add_length_header'] ?? true) {
            $length = (int)substr($message, 0, 4);
            $offset = 4;
        }
        
        // Extract MTI (positions 0-3 from remaining message)
        $mti = substr($message, $offset, 4);
        $offset += 4;
        
        // Parse bitmap(s)
        $primaryBitmap = substr($message, $offset, 16);
        $offset += 16;
        
        $bitmap = $this->parseBitmapHex($primaryBitmap);
        
        // Check for secondary bitmap (bit 1 indicates secondary present)
        $hasSecondary = $bitmap[1] ?? false;
        if ($hasSecondary) {
            $secondaryBitmap = substr($message, $offset, 16);
            $offset += 16;
            $secondaryBits = $this->parseBitmapHex($secondaryBitmap);
            $bitmap = array_merge($bitmap, $secondaryBits);
        }
        
        // Extract data elements
        $data = ['mti' => $mti];
        
        foreach ($bitmap as $fieldNum => $isPresent) {
            if (!$isPresent) continue;
            
            $fieldDef = $this->fieldDefinitions[$fieldNum] ?? [];
            $value = $this->parseField($message, $offset, $fieldDef);
            $data[$fieldNum] = $value;
        }
        
        // Map to internal transaction format
        return $this->mapToInternalTransaction($data);
    }
    
    public function validate(string $message): bool {
        try {
            // Check minimum length
            if (strlen($message) < 20) {
                return false;
            }
            
            // Parse and validate MTI
            $offset = ($this->countryConfig['add_length_header'] ?? true) ? 4 : 0;
            $mti = substr($message, $offset, 4);
            
            if (!preg_match('/^[0-9]{4}$/', $mti)) {
                return false;
            }
            
            // Validate against country-specific rules
            $validationRules = $this->countryConfig['validation_rules'] ?? [];
            
            foreach ($validationRules as $rule) {
                if (!$this->applyValidationRule($message, $rule)) {
                    return false;
                }
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getVersion(): string {
        return $this->version;
    }
    
    private function getMti(InternalTransaction $transaction): string {
        $purpose = $transaction->getPurpose();
        
        // Find MTI from config based on purpose
        foreach ($this->mtiDefinitions as $mti => $config) {
            if (isset($config['purposes']) && in_array($purpose, $config['purposes'])) {
                return $mti;
            }
            if (isset($config['default']) && $config['default'] === true) {
                $defaultMti = $mti;
            }
        }
        
        return $defaultMti ?? '0200'; // Default financial request
    }
    
    private function getFieldValue(InternalTransaction $transaction, array $mapping): ?string {
        $source = $mapping['source'] ?? null;
        $method = 'get' . ucfirst($source);
        
        if ($source && method_exists($transaction, $method)) {
            $value = $transaction->$method();
            
            // Apply transformations
            if (isset($mapping['transformations'])) {
                foreach ($mapping['transformations'] as $transformation) {
                    $value = $this->applyTransformation($value, $transformation);
                }
            }
            
            return (string)$value;
        }
        
        // Static value
        if (isset($mapping['static_value'])) {
            return $mapping['static_value'];
        }
        
        // Composite value
        if (isset($mapping['composite'])) {
            return $this->buildCompositeValue($transaction, $mapping['composite']);
        }
        
        return null;
    }
    
    private function formatField(int $fieldNum, string $value, array $fieldDef): string {
        $type = $fieldDef['type'] ?? 'n';
        $length = $fieldDef['length'] ?? 0;
        $encoding = $fieldDef['encoding'] ?? 'ascii';
        
        switch ($type) {
            case 'n': // Numeric
                $value = preg_replace('/[^0-9]/', '', $value);
                $value = str_pad($value, $length, '0', STR_PAD_LEFT);
                break;
                
            case 'an': // Alphanumeric
                $value = substr($value, 0, $length);
                $value = str_pad($value, $length, ' ');
                break;
                
            case 'ans': // Alphanumeric with special chars
                $value = substr($value, 0, $length);
                $value = str_pad($value, $length, ' ');
                break;
                
            case 'n..': // LLVAR numeric
                $len = strlen($value);
                $lenPadded = str_pad($len, 2, '0', STR_PAD_LEFT);
                $value = $lenPadded . $value;
                break;
                
            case 'an..': // LLVAR alphanumeric
                $len = strlen($value);
                $lenPadded = str_pad($len, 2, '0', STR_PAD_LEFT);
                $value = $lenPadded . $value;
                break;
                
            case 'n...': // LLLVAR numeric
                $len = strlen($value);
                $lenPadded = str_pad($len, 3, '0', STR_PAD_LEFT);
                $value = $lenPadded . $value;
                break;
        }
        
        return $value;
    }
    
    private function parseField(string $message, int &$offset, array $fieldDef): string {
        $type = $fieldDef['type'] ?? 'n';
        $length = $fieldDef['length'] ?? 0;
        
        switch ($type) {
            case 'n':
            case 'an':
            case 'ans':
                $value = substr($message, $offset, $length);
                $offset += $length;
                return trim($value);
                
            case 'n..':
                $len = (int)substr($message, $offset, 2);
                $offset += 2;
                $value = substr($message, $offset, $len);
                $offset += $len;
                return $value;
                
            case 'an..':
                $len = (int)substr($message, $offset, 2);
                $offset += 2;
                $value = substr($message, $offset, $len);
                $offset += $len;
                return $value;
                
            case 'n...':
                $len = (int)substr($message, $offset, 3);
                $offset += 3;
                $value = substr($message, $offset, $len);
                $offset += $len;
                return $value;
                
            default:
                return '';
        }
    }
    
    private function initializeBitmap(): array {
        return array_fill(1, 128, false);
    }
    
    private function setBitmapBit(array &$bitmap, int $fieldNum): void {
        if ($fieldNum >= 1 && $fieldNum <= 128) {
            $bitmap[$fieldNum] = true;
        }
    }
    
    private function buildBitmapString(array $bitmap): string {
        $hex = '';
        
        // Primary bitmap (fields 1-64)
        $binary = '';
        for ($i = 1; $i <= 64; $i++) {
            $binary .= $bitmap[$i] ? '1' : '0';
        }
        $hex .= $this->binaryToHex($binary);
        
        // Check if secondary bitmap needed (any field 65-128 is true)
        $hasSecondary = false;
        for ($i = 65; $i <= 128; $i++) {
            if ($bitmap[$i]) {
                $hasSecondary = true;
                break;
            }
        }
        
        if ($hasSecondary) {
            // Set bit 1 of primary bitmap to indicate secondary present
            $primaryBits = str_split($binary);
            $primaryBits[0] = '1';
            $binary = implode('', $primaryBits);
            $hex = $this->binaryToHex($binary);
            
            // Secondary bitmap (fields 65-128)
            $secondaryBinary = '';
            for ($i = 65; $i <= 128; $i++) {
                $secondaryBinary .= $bitmap[$i] ? '1' : '0';
            }
            $hex .= $this->binaryToHex($secondaryBinary);
        }
        
        return $hex;
    }
    
    private function parseBitmapHex(string $hex): array {
        $binary = $this->hexToBinary($hex);
        $bitmap = [];
        
        for ($i = 0; $i < strlen($binary); $i++) {
            $bitmap[$i + 1] = $binary[$i] === '1';
        }
        
        return $bitmap;
    }
    
    private function binaryToHex(string $binary): string {
        return bin2hex(pack('B*', $binary));
    }
    
    private function hexToBinary(string $hex): string {
        $binary = '';
        $bytes = str_split($hex, 2);
        foreach ($bytes as $byte) {
            $binary .= str_pad(decbin(hexdec($byte)), 8, '0', STR_PAD_LEFT);
        }
        return $binary;
    }
    
    private function mapToInternalTransaction(array $data): InternalTransaction {
        $mappings = $this->countryConfig['internal_mappings'] ?? [];
        $result = ['metadata' => []];
        
        foreach ($data as $fieldNum => $value) {
            if (isset($mappings[$fieldNum])) {
                $internalField = $mappings[$fieldNum];
                $result[$internalField] = $value;
            } elseif ($fieldNum !== 'mti') {
                $result['metadata']['iso8583_field_' . $fieldNum] = $value;
            }
        }
        
        // Map MTI to purpose
        $mti = $data['mti'] ?? '0200';
        foreach ($this->mtiDefinitions as $mtiValue => $config) {
            if ($mtiValue === $mti && isset($config['purpose'])) {
                $result['purpose'] = $config['purpose'];
                break;
            }
        }
        
        // Map response code if present
        if (isset($data['39']) && isset($this->responseCodes[$data['39']])) {
            $result['response_code'] = $data['39'];
            $result['response_message'] = $this->responseCodes[$data['39']];
        }
        
        $result['messageFormat'] = 'iso8583';
        $result['messageVersion'] = $this->version;
        $result['countryCode'] = $this->countryCode;
        
        return new InternalTransaction($result);
    }
    
    private function buildCompositeValue(InternalTransaction $transaction, array $composite): string {
        $parts = [];
        
        foreach ($composite['fields'] as $field) {
            $method = 'get' . ucfirst($field);
            if (method_exists($transaction, $method)) {
                $parts[] = $transaction->$method();
            }
        }
        
        $separator = $composite['separator'] ?? '';
        $value = implode($separator, $parts);
        
        // Apply formatting
        if (isset($composite['format'])) {
            $value = $this->applyTransformation($value, $composite['format']);
        }
        
        return $value;
    }
    
    private function applyTransformation($value, string $transformation): string {
        switch ($transformation) {
            case 'strip_non_numeric':
                return preg_replace('/[^0-9]/', '', $value);
            case 'pad_left_zeros':
                return str_pad($value, 19, '0', STR_PAD_LEFT);
            case 'yyyymmdd_to_mmdd':
                return substr($value, 4, 4);
            case 'yyyymmdd_to_yymm':
                return substr($value, 2, 4);
            default:
                return $value;
        }
    }
    
    private function applyValidationRule(string $message, array $rule): bool {
        $type = $rule['type'] ?? 'length';
        
        if ($type === 'length') {
            $min = $rule['min'] ?? 0;
            $max = $rule['max'] ?? PHP_INT_MAX;
            $len = strlen($message);
            return $len >= $min && $len <= $max;
        }
        
        if ($type === 'mti_valid') {
            $offset = ($this->countryConfig['add_length_header'] ?? true) ? 4 : 0;
            $mti = substr($message, $offset, 4);
            return isset($this->mtiDefinitions[$mti]);
        }
        
        return true;
    }
}
