<?php
namespace Infrastructure\MessageAdapters;

use Core\Transaction\InternalTransaction;

class RTGSAdapter implements MessageAdapterInterface {
    private string $countryCode;
    private array $countryConfig;
    private string $version;
    private array $messageTypes;
    private array $fieldDefinitions;
    private array $blockDefinitions;
    
    public function __construct(string $countryCode = null) {
        $this->countryCode = strtolower($countryCode ?? 'bw');
        
        // Load country-specific RTGS configuration
        $configPath = __DIR__ . "/../../config/countries/{$this->countryCode}/rtgs_format.php";
        
        if (!file_exists($configPath)) {
            throw new \Exception("No RTGS configuration found for country: {$countryCode}. Please create config/countries/{$countryCode}/rtgs_format.php");
        }
        
        $this->countryConfig = require $configPath;
        
        // Validate required configuration
        if (!isset($this->countryConfig['format_type'])) {
            throw new \Exception("RTGS config for {$countryCode} missing 'format_type'");
        }
        
        $this->version = $this->countryConfig['version'] ?? '1.0';
        $this->messageTypes = $this->countryConfig['message_types'] ?? [];
        $this->fieldDefinitions = $this->countryConfig['field_definitions'] ?? [];
        $this->blockDefinitions = $this->countryConfig['block_definitions'] ?? [];
    }
    
    public function toExternal(InternalTransaction $transaction): string {
        $messageType = $this->getMessageType($transaction);
        $formatType = $this->countryConfig['format_type'];
        
        switch ($formatType) {
            case 'swift':
                return $this->buildSwiftFormat($transaction, $messageType);
            case 'block':
                return $this->buildBlockFormat($transaction, $messageType);
            case 'delimited':
                return $this->buildDelimitedFormat($transaction, $messageType);
            case 'fixed_width':
                return $this->buildFixedWidthFormat($transaction, $messageType);
            default:
                throw new \Exception("Unsupported RTGS format type: {$formatType}");
        }
    }
    
    public function toInternal(string $message): InternalTransaction {
        $formatType = $this->detectFormatType($message);
        
        $data = [];
        switch ($formatType) {
            case 'swift':
                $data = $this->parseSwiftFormat($message);
                break;
            case 'block':
                $data = $this->parseBlockFormat($message);
                break;
            case 'delimited':
                $data = $this->parseDelimitedFormat($message);
                break;
            case 'fixed_width':
                $data = $this->parseFixedWidthFormat($message);
                break;
        }
        
        $transactionData = $this->mapToInternalFormat($data);
        $transactionData['messageFormat'] = 'rtgs';
        $transactionData['messageVersion'] = $this->version;
        $transactionData['countryCode'] = $this->countryCode;
        
        return new InternalTransaction($transactionData);
    }
    
    public function validate(string $message): bool {
        if (empty($message)) {
            return false;
        }
        
        $formatType = $this->detectFormatType($message);
        
        // Apply format-specific validation from config
        $validationRules = $this->countryConfig['validation_rules'][$formatType] ?? [];
        
        foreach ($validationRules as $rule) {
            if (!$this->applyValidationRule($message, $rule)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function getVersion(): string {
        return $this->version;
    }
    
    private function getMessageType(InternalTransaction $transaction): string {
        $purpose = $transaction->getPurpose();
        
        // Find message type from config based on purpose
        foreach ($this->messageTypes as $type => $config) {
            if (isset($config['purposes']) && in_array($purpose, $config['purposes'])) {
                return $type;
            }
            if (isset($config['default']) && $config['default'] === true) {
                $defaultType = $type;
            }
        }
        
        return $defaultType ?? key($this->messageTypes);
    }
    
    private function buildSwiftFormat(InternalTransaction $transaction, string $messageType): string {
        $swiftConfig = $this->countryConfig['swift_config'] ?? [];
        $blocks = $swiftConfig['blocks'] ?? [];
        
        $message = "";
        
        foreach ($blocks as $blockId => $blockConfig) {
            if (!$this->shouldIncludeBlock($blockConfig, $transaction)) {
                continue;
            }
            
            $blockContent = $this->buildBlock($blockId, $blockConfig, $transaction, $messageType);
            if (!empty($blockContent)) {
                $message .= "{" . $blockId . ":" . $blockContent . "}\n";
            }
        }
        
        return trim($message);
    }
    
    private function buildBlockFormat(InternalTransaction $transaction, string $messageType): string {
        $blockFormatConfig = $this->countryConfig['block_format_config'] ?? [];
        $blocks = $blockFormatConfig['blocks'] ?? $this->blockDefinitions;
        
        $message = "";
        
        foreach ($blocks as $blockId => $blockConfig) {
            if (!$this->shouldIncludeBlock($blockConfig, $transaction)) {
                continue;
            }
            
            $blockContent = $this->buildBlock($blockId, $blockConfig, $transaction, $messageType);
            if (!empty($blockContent)) {
                $message .= $blockFormatConfig['block_start'] ?? "{";
                $message .= $blockId;
                $message .= $blockFormatConfig['block_separator'] ?? ":";
                $message .= $blockContent;
                $message .= $blockFormatConfig['block_end'] ?? "}\n";
            }
        }
        
        return trim($message);
    }
    
    private function buildDelimitedFormat(InternalTransaction $transaction, string $messageType): string {
        $delimitedConfig = $this->countryConfig['delimited_config'] ?? [];
        $delimiter = $delimitedConfig['delimiter'] ?? '|';
        $fieldOrder = $delimitedConfig['field_order'] ?? [];
        
        $fields = [];
        foreach ($fieldOrder as $fieldName) {
            $fieldConfig = $this->fieldDefinitions[$fieldName] ?? [];
            $value = $this->getFieldValue($transaction, $fieldName, $fieldConfig);
            
            // Apply field-specific formatting
            if (isset($fieldConfig['formatting'])) {
                $value = $this->applyFormatting($value, $fieldConfig['formatting']);
            }
            
            $fields[] = $this->escapeForDelimiter((string)$value, $delimiter);
        }
        
        $message = implode($delimiter, $fields);
        
        // Add prefix if configured
        if (isset($delimitedConfig['prefix'])) {
            $message = $this->replacePlaceholders($delimitedConfig['prefix'], $transaction, $messageType) . $message;
        }
        
        // Add suffix if configured
        if (isset($delimitedConfig['suffix'])) {
            $message = $message . $this->replacePlaceholders($delimitedConfig['suffix'], $transaction, $messageType);
        }
        
        return $message;
    }
    
    private function buildFixedWidthFormat(InternalTransaction $transaction, string $messageType): string {
        $fixedWidthConfig = $this->countryConfig['fixed_width_config'] ?? [];
        $fieldDefinitions = $fixedWidthConfig['fields'] ?? [];
        
        $message = "";
        foreach ($fieldDefinitions as $fieldName => $fieldConfig) {
            $startPos = $fieldConfig['start'] ?? 0;
            $width = $fieldConfig['width'] ?? 0;
            $alignment = $fieldConfig['alignment'] ?? 'left';
            $padChar = $fieldConfig['pad_char'] ?? ' ';
            
            $value = $this->getFieldValue($transaction, $fieldName, $fieldConfig);
            
            // Truncate if too long
            if (strlen($value) > $width) {
                $value = substr($value, 0, $width);
            }
            
            // Pad to width
            if ($alignment === 'right') {
                $value = str_pad($value, $width, $padChar, STR_PAD_LEFT);
            } else {
                $value = str_pad($value, $width, $padChar, STR_PAD_RIGHT);
            }
            
            $message .= $value;
        }
        
        return $message;
    }
    
    private function buildBlock(string $blockId, array $blockConfig, InternalTransaction $transaction, string $messageType): string {
        $fields = $blockConfig['fields'] ?? [];
        $blockContent = "";
        
        foreach ($fields as $fieldName => $fieldConfig) {
            $value = $this->getFieldValue($transaction, $fieldName, $fieldConfig);
            
            // Apply formatting
            if (isset($fieldConfig['formatting'])) {
                $value = $this->applyFormatting($value, $fieldConfig['formatting']);
            }
            
            // Add field tag if configured
            if (isset($fieldConfig['tag'])) {
                $blockContent .= $fieldConfig['tag'] . $this->getTagSeparator($blockConfig) . $value;
            } else {
                $blockContent .= $value;
            }
            
            // Add field separator if configured
            if (isset($blockConfig['field_separator'])) {
                $blockContent .= $blockConfig['field_separator'];
            }
        }
        
        // Remove trailing separator if needed
        if (isset($blockConfig['field_separator']) && !($blockConfig['keep_trailing_separator'] ?? false)) {
            $blockContent = rtrim($blockContent, $blockConfig['field_separator']);
        }
        
        return $blockContent;
    }
    
    private function getFieldValue(InternalTransaction $transaction, string $fieldName, array $fieldConfig): string {
        // Check if field has a direct mapping
        if (isset($fieldConfig['source'])) {
            $method = 'get' . ucfirst($fieldConfig['source']);
            if (method_exists($transaction, $method)) {
                $value = $transaction->$method();
            } else {
                $value = '';
            }
        } elseif (isset($fieldConfig['static_value'])) {
            $value = $fieldConfig['static_value'];
        } elseif (isset($fieldConfig['composite'])) {
            $value = $this->buildCompositeField($transaction, $fieldConfig['composite']);
        } else {
            // Try to guess method name
            $method = 'get' . ucfirst($fieldName);
            $value = method_exists($transaction, $method) ? $transaction->$method() : '';
        }
        
        // Apply transformations
        if (isset($fieldConfig['transformations'])) {
            foreach ($fieldConfig['transformations'] as $transformation) {
                $value = $this->applyTransformation($value, $transformation);
            }
        }
        
        // Apply padding if configured
        if (isset($fieldConfig['pad_length'])) {
            $padChar = $fieldConfig['pad_char'] ?? ' ';
            $padSide = $fieldConfig['pad_side'] ?? 'left';
            
            if ($padSide === 'left') {
                $value = str_pad($value, $fieldConfig['pad_length'], $padChar, STR_PAD_LEFT);
            } else {
                $value = str_pad($value, $fieldConfig['pad_length'], $padChar, STR_PAD_RIGHT);
            }
        }
        
        return (string)$value;
    }
    
    private function buildCompositeField(InternalTransaction $transaction, array $compositeConfig): string {
        $parts = [];
        $separator = $compositeConfig['separator'] ?? '';
        
        foreach ($compositeConfig['fields'] as $subField) {
            $method = 'get' . ucfirst($subField);
            if (method_exists($transaction, $method)) {
                $parts[] = $transaction->$method();
            }
        }
        
        return implode($separator, $parts);
    }
    
    private function parseSwiftFormat(string $message): array {
        $data = [];
        $swiftConfig = $this->countryConfig['swift_config'] ?? [];
        
        // Parse each block
        if (preg_match_all('/\{([0-9]+):(.*?)\}(?:\n|$)/s', $message, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $blockId = $match[1];
                $blockContent = trim($match[2]);
                
                if (isset($this->blockDefinitions[$blockId])) {
                    $parsedBlock = $this->parseBlock($blockContent, $this->blockDefinitions[$blockId]);
                    $data = array_merge($data, $parsedBlock);
                }
            }
        }
        
        return $data;
    }
    
    private function parseBlockFormat(string $message): array {
        $data = [];
        $blockFormatConfig = $this->countryConfig['block_format_config'] ?? [];
        $blockStart = $blockFormatConfig['block_start'] ?? '{';
        $blockEnd = $blockFormatConfig['block_end'] ?? '}';
        $blockSeparator = $blockFormatConfig['block_separator'] ?? ':';
        
        $pattern = '/' . preg_quote($blockStart, '/') . '([^' . preg_quote($blockSeparator, '/') . ']+)' . 
                   preg_quote($blockSeparator, '/') . '(.*?)' . preg_quote($blockEnd, '/') . '/s';
        
        if (preg_match_all($pattern, $message, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $blockId = $match[1];
                $blockContent = trim($match[2]);
                
                if (isset($this->blockDefinitions[$blockId])) {
                    $parsedBlock = $this->parseBlock($blockContent, $this->blockDefinitions[$blockId]);
                    $data = array_merge($data, $parsedBlock);
                }
            }
        }
        
        return $data;
    }
    
    private function parseDelimitedFormat(string $message): array {
        $delimitedConfig = $this->countryConfig['delimited_config'] ?? [];
        
        // Remove prefix/suffix if configured
        if (isset($delimitedConfig['strip_prefix'])) {
            $message = preg_replace($delimitedConfig['strip_prefix'], '', $message);
        }
        if (isset($delimitedConfig['strip_suffix'])) {
            $message = preg_replace($delimitedConfig['strip_suffix'], '', $message);
        }
        
        $delimiter = $delimitedConfig['delimiter'] ?? '|';
        $fieldOrder = $delimitedConfig['field_order'] ?? [];
        $parts = explode($delimiter, $message);
        
        $data = [];
        foreach ($fieldOrder as $index => $fieldName) {
            if (isset($parts[$index])) {
                $value = $this->unescapeForDelimiter($parts[$index], $delimiter);
                
                // Apply parse transformations
                if (isset($this->fieldDefinitions[$fieldName]['parse_transformations'])) {
                    foreach ($this->fieldDefinitions[$fieldName]['parse_transformations'] as $transformation) {
                        $value = $this->applyTransformation($value, $transformation);
                    }
                }
                
                $data[$fieldName] = $value;
            }
        }
        
        return $data;
    }
    
    private function parseFixedWidthFormat(string $message): array {
        $fixedWidthConfig = $this->countryConfig['fixed_width_config'] ?? [];
        $fieldDefinitions = $fixedWidthConfig['fields'] ?? [];
        
        $data = [];
        foreach ($fieldDefinitions as $fieldName => $fieldConfig) {
            $startPos = $fieldConfig['start'] ?? 0;
            $width = $fieldConfig['width'] ?? 0;
            
            $value = trim(substr($message, $startPos, $width));
            
            // Apply parse transformations
            if (isset($fieldConfig['parse_transformations'])) {
                foreach ($fieldConfig['parse_transformations'] as $transformation) {
                    $value = $this->applyTransformation($value, $transformation);
                }
            }
            
            $data[$fieldName] = $value;
        }
        
        return $data;
    }
    
    private function parseBlock(string $blockContent, array $blockDefinition): array {
        $data = [];
        $fields = $blockDefinition['fields'] ?? [];
        $fieldSeparator = $blockDefinition['field_separator'] ?? null;
        
        if ($fieldSeparator) {
            // Parse delimited block
            $lines = explode("\n", $blockContent);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                foreach ($fields as $fieldName => $fieldConfig) {
                    if (isset($fieldConfig['tag']) && strpos($line, $fieldConfig['tag'] . $this->getTagSeparator($blockDefinition)) === 0) {
                        $value = substr($line, strlen($fieldConfig['tag'] . $this->getTagSeparator($blockDefinition)));
                        $data[$fieldName] = trim($value);
                        break;
                    }
                }
            }
        } else {
            // Parse simple block
            foreach ($fields as $fieldName => $fieldConfig) {
                $startPos = $fieldConfig['start'] ?? 0;
                $length = $fieldConfig['length'] ?? null;
                
                if ($length) {
                    $value = substr($blockContent, $startPos, $length);
                } else {
                    $value = substr($blockContent, $startPos);
                }
                
                $data[$fieldName] = trim($value);
            }
        }
        
        return $data;
    }
    
    private function mapToInternalFormat(array $data): array {
        $mappings = $this->countryConfig['internal_mappings'] ?? [];
        $result = ['metadata' => []];
        
        foreach ($data as $externalField => $value) {
            if (isset($mappings[$externalField])) {
                $internalField = $mappings[$externalField];
                $result[$internalField] = $value;
            } else {
                $result['metadata'][$externalField] = $value;
            }
        }
        
        return $result;
    }
    
    private function detectFormatType(string $message): string {
        // Check against format detection patterns from config
        $detectionRules = $this->countryConfig['format_detection'] ?? [];
        
        foreach ($detectionRules as $formatType => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $message)) {
                    return $formatType;
                }
            }
        }
        
        // Default detection logic
        if (preg_match('/\{[0-9]+:[^}]+\}/', $message)) {
            return 'swift';
        }
        if (strpos($message, '|') !== false) {
            return 'delimited';
        }
        if (preg_match('/^[A-Z0-9\s]{50,}$/', $message)) {
            return 'fixed_width';
        }
        
        return $this->countryConfig['format_type'] ?? 'delimited';
    }
    
    private function applyFormatting($value, string $formatting) {
        switch ($formatting) {
            case 'amount_decimal':
                return number_format((float)$value, 2, ',', '');
            case 'amount_no_decimal':
                return (string)((float)$value * 100);
            case 'date_swift':
                return date('Ymd', strtotime($value));
            case 'time_swift':
                return date('His', strtotime($value));
            default:
                if (strpos($formatting, 'date_format:') === 0) {
                    $format = substr($formatting, 12);
                    return date($format, strtotime($value));
                }
                return $value;
        }
    }
    
    private function applyTransformation($value, string $transformation) {
        switch ($transformation) {
            case 'trim':
                return trim($value);
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'upper':
                return strtoupper($value);
            case 'lower':
                return strtolower($value);
            default:
                if (strpos($transformation, 'regex_replace:') === 0) {
                    $parts = explode('|', substr($transformation, 14));
                    $pattern = $parts[0] ?? '';
                    $replacement = $parts[1] ?? '';
                    return preg_replace($pattern, $replacement, $value);
                }
                return $value;
        }
    }
    
    private function shouldIncludeBlock(array $blockConfig, InternalTransaction $transaction): bool {
        if (!isset($blockConfig['condition'])) {
            return true;
        }
        
        $condition = $blockConfig['condition'];
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;
        
        if (!$field) {
            return true;
        }
        
        $method = 'get' . ucfirst($field);
        if (!method_exists($transaction, $method)) {
            return false;
        }
        
        $fieldValue = $transaction->$method();
        
        switch ($operator) {
            case 'equals':
                return $fieldValue == $value;
            case 'not_equals':
                return $fieldValue != $value;
            case 'in':
                return in_array($fieldValue, (array)$value);
            case 'not_in':
                return !in_array($fieldValue, (array)$value);
            default:
                return true;
        }
    }
    
    private function getTagSeparator(array $blockConfig): string {
        return $blockConfig['tag_separator'] ?? $this->countryConfig['default_tag_separator'] ?? ':';
    }
    
    private function replacePlaceholders(string $string, InternalTransaction $transaction, string $messageType): string {
        $replacements = [
            '{message_type}' => $messageType,
            '{version}' => $this->version,
            '{country}' => strtoupper($this->countryCode),
            '{timestamp}' => date('YmdHis'),
            '{date}' => date('Ymd'),
            '{time}' => date('His')
        ];
        
        // Add transaction fields
        $methods = ['getTransactionId', 'getAmount', 'getCurrency', 'getReference'];
        foreach ($methods as $method) {
            if (method_exists($transaction, $method)) {
                $key = '{' . lcfirst(substr($method, 3)) . '}';
                $replacements[$key] = $transaction->$method();
            }
        }
        
        return str_replace(array_keys($replacements), array_values($replacements), $string);
    }
    
    private function escapeForDelimiter(string $value, string $delimiter): string {
        if (strpos($value, $delimiter) !== false) {
            $value = str_replace($delimiter, '\\' . $delimiter, $value);
        }
        return $value;
    }
    
    private function unescapeForDelimiter(string $value, string $delimiter): string {
        return str_replace('\\' . $delimiter, $delimiter, $value);
    }
    
    private function applyValidationRule(string $message, array $rule): bool {
        $type = $rule['type'] ?? 'regex';
        $pattern = $rule['pattern'] ?? null;
        
        if ($type === 'regex' && $pattern) {
            return preg_match($pattern, $message) === 1;
        }
        
        if ($type === 'length') {
            $min = $rule['min'] ?? 0;
            $max = $rule['max'] ?? PHP_INT_MAX;
            $length = strlen($message);
            return $length >= $min && $length <= $max;
        }
        
        if ($type === 'contains') {
            $needle = $rule['value'] ?? '';
            return strpos($message, $needle) !== false;
        }
        
        return true;
    }
}
