<?php
namespace Infrastructure\MessageAdapters;

use Core\Transaction\InternalTransaction;

class MobileMoneyAdapter implements MessageAdapterInterface {
    private string $countryCode;
    private array $countryConfig;
    private string $version;
    private array $providerFormats;
    
    public function __construct(string $countryCode = null) {
        $this->countryCode = strtolower($countryCode ?? 'bw');
        
        // Load country-specific mobile money configuration
        $configPath = __DIR__ . "/../../config/countries/{$this->countryCode}/mobile_money.php";
        
        if (file_exists($configPath)) {
            $this->countryConfig = require $configPath;
        } else {
            // NO DEFAULTS WITH HARDCODED NAMES
            // Throw exception to force proper configuration
            throw new \Exception("No mobile money configuration found for country: {$countryCode}. Please create config/countries/{$countryCode}/mobile_money.php");
        }
        
        $this->version = $this->countryConfig['version'] ?? '1.0';
        $this->providerFormats = $this->countryConfig['provider_mappings'] ?? [];
        
        // Validate that we have provider mappings
        if (empty($this->providerFormats)) {
            throw new \Exception("No provider_mappings defined for mobile money in country: {$countryCode}");
        }
    }
    
    public function toExternal(InternalTransaction $transaction): string {
        // Detect provider from transaction metadata or bank code
        $provider = $this->detectProvider($transaction);
        
        if (!isset($this->providerFormats[$provider])) {
            throw new \Exception("No format configuration for provider: {$provider} in country: {$this->countryCode}");
        }
        
        $format = $this->providerFormats[$provider];
        $formatType = $format['type'] ?? 'json';
        
        switch ($formatType) {
            case 'json':
                return $this->buildJsonMessage($transaction, $provider, $format);
            case 'xml':
                return $this->buildXmlMessage($transaction, $provider, $format);
            case 'delimited':
                return $this->buildDelimitedMessage($transaction, $provider, $format);
            default:
                throw new \Exception("Unsupported format type: {$formatType} for provider: {$provider}");
        }
    }
    
    public function toInternal(string $message): InternalTransaction {
        // Detect format from message structure
        $formatType = $this->detectFormatType($message);
        
        $data = [];
        if ($formatType === 'json') {
            $data = $this->parseJsonMessage($message);
        } elseif ($formatType === 'xml') {
            $data = $this->parseXmlMessage($message);
        } else {
            $data = $this->parseDelimitedMessage($message);
        }
        
        // Map using field mappings from config
        $transactionData = $this->mapToInternalFormat($data);
        $transactionData['messageFormat'] = 'mobile_money';
        $transactionData['messageVersion'] = $this->version;
        $transactionData['countryCode'] = $this->countryCode;
        
        return new InternalTransaction($transactionData);
    }
    
    public function validate(string $message): bool {
        if (empty(trim($message))) {
            return false;
        }
        
        $formatType = $this->detectFormatType($message);
        
        if ($formatType === 'json') {
            return $this->validateJson($message);
        } elseif ($formatType === 'xml') {
            return $this->validateXml($message);
        } else {
            return $this->validateDelimited($message);
        }
    }
    
    public function getVersion(): string {
        return $this->version;
    }
    
    private function detectProvider(InternalTransaction $transaction): string {
        // Check metadata first (dynamic, no hardcoding)
        $metadata = $transaction->getMetadata();
        if (isset($metadata['mobile_money_provider'])) {
            return $metadata['mobile_money_provider'];
        }
        
        // Check if bank code matches any provider pattern from config
        $bankCode = $transaction->getReceiverBankCode();
        foreach ($this->providerFormats as $provider => $config) {
            if (isset($config['bank_codes'])) {
                foreach ($config['bank_codes'] as $configuredBankCode) {
                    if ($bankCode === $configuredBankCode) {
                        return $provider;
                    }
                }
            }
            
            // Check for regex pattern matching if configured
            if (isset($config['bank_code_pattern'])) {
                if (preg_match($config['bank_code_pattern'], $bankCode)) {
                    return $provider;
                }
            }
        }
        
        // Use default provider if configured (with dynamic name from config)
        if (isset($this->countryConfig['default_provider'])) {
            return $this->countryConfig['default_provider'];
        }
        
        throw new \Exception("Cannot detect mobile money provider for bank code: {$bankCode}");
    }
    
    private function buildJsonMessage(InternalTransaction $transaction, string $provider, array $format): string {
        $fieldMappings = $format['field_mappings'] ?? [];
        $payload = [];
        
        foreach ($fieldMappings as $externalField => $internalField) {
            $method = 'get' . ucfirst($internalField);
            if (method_exists($transaction, $method)) {
                $value = $transaction->$method();
                
                // Apply any transformations from config
                if (isset($format['transformations'][$externalField])) {
                    $value = $this->applyTransformation($value, $format['transformations'][$externalField]);
                }
                
                $payload[$externalField] = $value;
            }
        }
        
        // Add static fields from config (dynamic, not hardcoded)
        if (isset($format['static_fields'])) {
            $payload = array_merge($payload, $format['static_fields']);
        }
        
        // Add timestamp using configured format
        $timestampFormat = $format['timestamp_format'] ?? 'Y-m-d\TH:i:sP';
        $payload[$format['timestamp_field'] ?? 'timestamp'] = date($timestampFormat);
        
        // Add provider identifier if configured
        if (isset($format['include_provider']) && $format['include_provider']) {
            $payload[$format['provider_field'] ?? 'provider'] = $provider;
        }
        
        // Wrap in container if configured
        if (isset($format['wrapper'])) {
            $payload = [$format['wrapper'] => $payload];
        }
        
        $jsonOptions = $format['json_options'] ?? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        return json_encode($payload, $jsonOptions);
    }
    
    private function buildXmlMessage(InternalTransaction $transaction, string $provider, array $format): string {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = $format['pretty_print'] ?? true;
        
        $rootName = $format['root_element'] ?? 'MobileMoneyTransfer';
        $root = $xml->appendChild($xml->createElement($rootName));
        
        // Add attributes from config
        if (isset($format['attributes'])) {
            foreach ($format['attributes'] as $attrName => $attrValue) {
                if ($attrValue === '{provider}') {
                    $attrValue = $provider;
                } elseif ($attrValue === '{version}') {
                    $attrValue = $this->version;
                }
                $root->setAttribute($attrName, $attrValue);
            }
        }
        
        // Add fields from mappings
        $fieldMappings = $format['field_mappings'] ?? [];
        foreach ($fieldMappings as $xmlTag => $internalField) {
            $method = 'get' . ucfirst($internalField);
            if (method_exists($transaction, $method)) {
                $value = $transaction->$method();
                
                // Apply transformations
                if (isset($format['transformations'][$xmlTag])) {
                    $value = $this->applyTransformation($value, $format['transformations'][$xmlTag]);
                }
                
                $root->appendChild($xml->createElement($xmlTag, htmlspecialchars((string)$value)));
            }
        }
        
        return $xml->saveXML();
    }
    
    private function buildDelimitedMessage(InternalTransaction $transaction, string $provider, array $format): string {
        $delimiter = $format['delimiter'] ?? '|';
        $fieldOrder = $format['field_order'] ?? [];
        $fields = [];
        
        foreach ($fieldOrder as $fieldName) {
            $method = 'get' . ucfirst($fieldName);
            if (method_exists($transaction, $method)) {
                $value = $transaction->$method();
                
                // Apply transformations
                if (isset($format['transformations'][$fieldName])) {
                    $value = $this->applyTransformation($value, $format['transformations'][$fieldName]);
                }
                
                $fields[] = $this->escapeForDelimiter((string)$value, $delimiter);
            } else {
                $fields[] = '';
            }
        }
        
        $message = implode($delimiter, $fields);
        
        // Add prefix if configured
        if (isset($format['prefix'])) {
            $prefix = str_replace('{provider}', $provider, $format['prefix']);
            $prefix = str_replace('{version}', $this->version, $prefix);
            $message = $prefix . $message;
        }
        
        // Add suffix if configured
        if (isset($format['suffix'])) {
            $suffix = str_replace('{provider}', $provider, $format['suffix']);
            $message = $message . $suffix;
        }
        
        return $message;
    }
    
    private function parseJsonMessage(string $message): array {
        $data = json_decode($message, true);
        
        // Unwrap if wrapper exists (configured in country config)
        if (isset($this->countryConfig['unwrap_path'])) {
            $path = explode('.', $this->countryConfig['unwrap_path']);
            foreach ($path as $key) {
                if (isset($data[$key])) {
                    $data = $data[$key];
                } else {
                    break;
                }
            }
        }
        
        return $data;
    }
    
    private function parseXmlMessage(string $message): array {
        $xml = simplexml_load_string($message);
        $json = json_encode($xml);
        return json_decode($json, true);
    }
    
    private function parseDelimitedMessage(string $message): array {
        // Remove prefix/suffix if configured
        if (isset($this->countryConfig['strip_prefix'])) {
            $message = preg_replace($this->countryConfig['strip_prefix'], '', $message);
        }
        if (isset($this->countryConfig['strip_suffix'])) {
            $message = preg_replace($this->countryConfig['strip_suffix'], '', $message);
        }
        
        // Need to know which provider format to use for parsing
        // Try to detect by pattern matching
        $detectedProvider = null;
        foreach ($this->providerFormats as $provider => $format) {
            if (isset($format['parse_pattern'])) {
                if (preg_match($format['parse_pattern'], $message)) {
                    $detectedProvider = $provider;
                    break;
                }
            }
        }
        
        if (!$detectedProvider) {
            throw new \Exception("Cannot detect provider for delimited message");
        }
        
        $format = $this->providerFormats[$detectedProvider];
        $delimiter = $format['delimiter'] ?? '|';
        $fieldOrder = $format['field_order'] ?? [];
        $parts = explode($delimiter, $message);
        
        $data = [];
        foreach ($fieldOrder as $index => $fieldName) {
            if (isset($parts[$index])) {
                $data[$fieldName] = $this->unescapeForDelimiter($parts[$index], $delimiter);
            }
        }
        
        return $data;
    }
    
    private function mapToInternalFormat(array $data): array {
        // Use reverse mapping from provider configs
        // Try to find which provider's mappings match this data
        $reverseMappings = $this->countryConfig['reverse_mappings'] ?? [];
        
        if (empty($reverseMappings)) {
            // Build reverse mappings from provider configs
            foreach ($this->providerFormats as $provider => $format) {
                if (isset($format['field_mappings'])) {
                    foreach ($format['field_mappings'] as $external => $internal) {
                        $reverseMappings[$external] = $internal;
                    }
                }
            }
        }
        
        $result = [];
        foreach ($data as $key => $value) {
            if (isset($reverseMappings[$key])) {
                $result[$reverseMappings[$key]] = $value;
            } else {
                // Store unmapped fields in metadata
                $result['metadata'][$key] = $value;
            }
        }
        
        return $result;
    }
    
    private function detectFormatType(string $message): string {
        if ($this->isJson($message)) {
            return 'json';
        }
        if ($this->isXml($message)) {
            return 'xml';
        }
        return 'delimited';
    }
    
    private function validateJson(string $message): bool {
        json_decode($message);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        
        // Apply schema validation if configured
        if (isset($this->countryConfig['json_schema'])) {
            // Would implement JSON schema validation here
            return true;
        }
        
        return true;
    }
    
    private function validateXml(string $message): bool {
        try {
            $xml = simplexml_load_string($message);
            return $xml !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function validateDelimited(string $message): bool {
        // Check against configured patterns for each provider
        foreach ($this->providerFormats as $provider => $format) {
            if (isset($format['validation_pattern'])) {
                if (preg_match($format['validation_pattern'], $message)) {
                    return true;
                }
            }
        }
        return false;
    }
    
    private function applyTransformation($value, string $transformation) {
        switch ($transformation) {
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'string':
                return (string)$value;
            case 'date_ymd':
                return date('Ymd', strtotime($value));
            case 'date_dmy':
                return date('dmY', strtotime($value));
            default:
                // Custom transformation function from config
                if (strpos($transformation, 'format:') === 0) {
                    $format = substr($transformation, 7);
                    return date($format, strtotime($value));
                }
                return $value;
        }
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
    
    private function isJson(string $string): bool {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    private function isXml(string $string): bool {
        return preg_match('/<[^>]+>/', $string) === 1;
    }
}
