<?php
namespace Infrastructure\MessageAdapters;

class MessageAdapterFactory {
    private array $globalConfig;
    private array $countryConfigs = [];
    private array $instances = [];
    private string $currentCountry;
    
    // Detection confidence levels
    private const CONFIDENCE_HIGH = 100;
    private const CONFIDENCE_MEDIUM = 70;
    private const CONFIDENCE_LOW = 40;
    
    public function __construct(string $countryCode = null) {
        $this->globalConfig = require __DIR__ . '/../../Config/message_adapters.php'; 
        
        if ($countryCode) {
            $this->setCountry($countryCode);
        }
    }
    
    public function setCountry(string $countryCode): self {
        $this->currentCountry = strtolower($countryCode);
        
        $configPath = __DIR__ . "/../../Config/Countries/{$this->currentCountry}/bank_formats.php";
        
        if (!file_exists($configPath)) {
            throw new \Exception("No bank format config for country: {$countryCode}");
        }
        
        $this->countryConfigs[$this->currentCountry] = require $configPath;
        
        return $this;
    }
    
    /**
     * SMART DETECTION - Analyzes content to determine message format
     * No participant config needed!
     */
    public static function detectFromContent(array $payload): string
    {
        // Check for ISO20022 indicators
        if (isset($payload['businessMessageId']) || 
            isset($payload['debtor']) || 
            isset($payload['creditor']) ||
            isset($payload['settlementMethod']) ||
            isset($payload['instructionId']) ||
            isset($payload['endToEndId'])) {
            return 'ISO20022';
        }
        
        // Check for ISO8583 indicators (bitmap, MTI, fields)
        if (isset($payload['mti']) || 
            isset($payload['bitmap']) || 
            (isset($payload['fields']) && is_array($payload['fields'])) ||
            isset($payload['MTI']) ||
            isset($payload['Bitmap'])) {
            return 'ISO8583';
        }
        
        // Check for Mobile Money (GSMA-MM) indicators
        if ((isset($payload['messageType']) && $payload['messageType'] === 'transfer') ||
            (isset($payload['message_type']) && $payload['message_type'] === 'transfer') ||
            (isset($payload['from']['type']) && $payload['from']['type'] === 'WALLET') ||
            (isset($payload['to']['type']) && $payload['to']['type'] === 'WALLET') ||
            isset($payload['walletId']) ||
            isset($payload['wallet_id'])) {
            return 'MOBILE_MONEY';
        }
        
        // Check for RTGS indicators
        if (isset($payload['debitParty']['bankCode']) ||
            isset($payload['creditParty']['bankCode']) ||
            isset($payload['settlementDate']) ||
            isset($payload['valueDate']) ||
            isset($payload['settlementAmount'])) {
            return 'RTGS';
        }
        
        // Check for SWIFT MT messages
        if ((isset($payload['messageType']) && strpos($payload['messageType'], 'MT') === 0) ||
            (isset($payload['message_type']) && strpos($payload['message_type'], 'MT') === 0) ||
            isset($payload['block4']) ||
            isset($payload['block5'])) {
            return 'SWIFT_MT';
        }
        
        // Check for simple pipe-delimited legacy format
        if (is_string($payload) && substr_count($payload, '|') > 0) {
            return 'LEGACY';
        }
        
        // Check for CSV format
        if (is_string($payload) && substr_count($payload, ',') > 0 && strpos($payload, "\n") !== false) {
            return 'CSV';
        }
        
        // Default fallback
        return 'LEGACY';
    }
    
    /**
     * Detect from HTTP headers
     */
    public static function detectFromHeaders(array $headers): ?string
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        
        // Check Content-Type header
        $contentType = $headers['content-type'] ?? '';
        
        if (strpos($contentType, 'application/vnd.interoperability.parties') !== false ||
            strpos($contentType, 'application/vnd.interoperability.quotes') !== false ||
            strpos($contentType, 'application/vnd.interoperability.transfers') !== false) {
            return 'ISO20022';
        }
        
        if (strpos($contentType, 'application/vnd.mobile-money') !== false ||
            strpos($contentType, 'application/x-mobile-money') !== false) {
            return 'MOBILE_MONEY';
        }
        
        if (strpos($contentType, 'application/iso8583') !== false ||
            strpos($contentType, 'application/x-iso8583') !== false) {
            return 'ISO8583';
        }
        
        if (strpos($contentType, 'application/rtgs') !== false ||
            strpos($contentType, 'application/x-rtgs') !== false) {
            return 'RTGS';
        }
        
        // Check custom headers
        if (isset($headers['x-message-standard'])) {
            return strtoupper($headers['x-message-standard']);
        }
        
        if (isset($headers['x-message-format'])) {
            return strtoupper($headers['x-message-format']);
        }
        
        // Check FSPIOP headers (Mojaloop)
        if (isset($headers['fspiop-source']) || 
            isset($headers['fspiop-destination']) ||
            isset($headers['fspiop-signature'])) {
            return 'ISO20022';
        }
        
        return null;
    }
    
    /**
     * Detect from endpoint URL
     */
    public static function detectFromEndpoint(string $endpoint): ?string
    {
        $endpointLower = strtolower($endpoint);
        
        // Mojaloop/ISO20022 endpoints
        if (strpos($endpointLower, '/parties') !== false ||
            strpos($endpointLower, '/quotes') !== false ||
            strpos($endpointLower, '/transfers') !== false ||
            strpos($endpointLower, '/mojaloop') !== false) {
            return 'ISO20022';
        }
        
        // ISO8583 endpoints
        if (strpos($endpointLower, '/iso8583') !== false ||
            strpos($endpointLower, '/atm') !== false ||
            strpos($endpointLower, '/pos') !== false ||
            strpos($endpointLower, '/authorize') !== false) {
            return 'ISO8583';
        }
        
        // Mobile Money endpoints
        if (strpos($endpointLower, '/mobile-money') !== false ||
            strpos($endpointLower, '/ewallet') !== false ||
            strpos($endpointLower, '/wallet') !== false ||
            strpos($endpointLower, '/gsma') !== false) {
            return 'MOBILE_MONEY';
        }
        
        // RTGS endpoints
        if (strpos($endpointLower, '/rtgs') !== false ||
            strpos($endpointLower, '/settlement') !== false ||
            strpos($endpointLower, '/gross') !== false) {
            return 'RTGS';
        }
        
        // SWIFT endpoints
        if (strpos($endpointLower, '/swift') !== false ||
            strpos($endpointLower, '/mt') !== false) {
            return 'SWIFT_MT';
        }
        
        return null;
    }
    
    /**
     * Smart detection - tries multiple methods
     */
    public static function smartDetect(
        array $payload = [], 
        array $headers = [], 
        ?string $endpoint = null,
        ?array $participant = null,
        ?string $bankCode = null
    ): array {
        $detections = [];
        
        // PRIORITY 1: Participant config (explicit override - highest confidence)
        if ($participant && isset($participant['message_profile']['standard'])) {
            $format = $participant['message_profile']['standard'];
            $detections[] = ['format' => $format, 'confidence' => 100, 'source' => 'participant_config'];
            return ['format' => $format, 'confidence' => 100, 'source' => 'participant_config', 'all_detections' => $detections];
        }
        
        // PRIORITY 2: Bank code mapping (from config)
        if ($bankCode && $participant && isset($participant['bank_formats'][$bankCode])) {
            $format = $participant['bank_formats'][$bankCode];
            $detections[] = ['format' => $format, 'confidence' => 95, 'source' => 'bank_code_mapping'];
            return ['format' => $format, 'confidence' => 95, 'source' => 'bank_code_mapping', 'all_detections' => $detections];
        }
        
        // PRIORITY 3: Headers detection
        if (!empty($headers)) {
            $headerFormat = self::detectFromHeaders($headers);
            if ($headerFormat) {
                $detections[] = ['format' => $headerFormat, 'confidence' => 85, 'source' => 'http_headers'];
                // Don't return yet - check content for higher confidence
            }
        }
        
        // PRIORITY 4: Endpoint detection
        if ($endpoint) {
            $endpointFormat = self::detectFromEndpoint($endpoint);
            if ($endpointFormat) {
                $detections[] = ['format' => $endpointFormat, 'confidence' => 75, 'source' => 'endpoint_url'];
            }
        }
        
        // PRIORITY 5: Content detection (most flexible)
        if (!empty($payload)) {
            $contentFormat = self::detectFromContent($payload);
            if ($contentFormat !== 'LEGACY') {
                $detections[] = ['format' => $contentFormat, 'confidence' => 80, 'source' => 'content_analysis'];
            }
        }
        
        // Choose highest confidence detection
        if (!empty($detections)) {
            usort($detections, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
            $best = $detections[0];
            return [
                'format' => $best['format'],
                'confidence' => $best['confidence'],
                'source' => $best['source'],
                'all_detections' => $detections
            ];
        }
        
        // FINAL: Default to ISO20022 for modern systems
        return ['format' => 'ISO20022', 'confidence' => 30, 'source' => 'default', 'all_detections' => $detections];
    }
    
    /**
     * Get adapter with smart detection
     */
    public function getAdapterSmart(
        array $payload = [], 
        array $headers = [], 
        ?string $endpoint = null,
        ?string $bankCode = null
    ): array {
        if (!$this->currentCountry) {
            throw new \Exception("Country not set. Call setCountry() first.");
        }
        
        $countryConfig = $this->countryConfigs[$this->currentCountry];
        $participant = $countryConfig['participant_config'] ?? null;
        
        // Smart detection
        $detection = self::smartDetect($payload, $headers, $endpoint, $participant, $bankCode);
        
        $formatType = $detection['format'];
        
        if (!isset($this->globalConfig['adapters'][$formatType])) {
            throw new \Exception("No adapter for format: {$formatType} in country: {$this->currentCountry}");
        }
        
        $adapter = $this->getAdapter($formatType);
        
        return [
            'adapter' => $adapter,
            'detected_format' => $formatType,
            'detection_confidence' => $detection['confidence'],
            'detection_source' => $detection['source'],
            'all_detections' => $detection['all_detections']
        ];
    }
    
    /**
     * Create smart adapter (static version)
     */
    public static function createSmart(
        array $payload = [], 
        array $headers = [], 
        ?string $endpoint = null,
        ?array $participant = null,
        ?string $bankCode = null
    ): MessageAdapterInterface {
        $detection = self::smartDetect($payload, $headers, $endpoint, $participant, $bankCode);
        
        $factory = new self();
        return $factory->getAdapter($detection['format']);
    }
    
    public function getAdapterForBank(string $bankCode): MessageAdapterInterface {
        if (!$this->currentCountry) {
            throw new \Exception("Country not set. Call setCountry() first.");
        }
        
        $countryConfig = $this->countryConfigs[$this->currentCountry];
        $bankFormats = $countryConfig['bank_formats'];
        
        $formatType = $bankFormats[$bankCode] ?? $countryConfig['default_format'];
        
        if (!isset($this->globalConfig['adapters'][$formatType])) {
            throw new \Exception("No adapter for format: {$formatType} in country: {$this->currentCountry}");
        }
        
        return $this->getAdapter($formatType);
    }
    
    public function getAdapter(string $formatType): MessageAdapterInterface {
        $cacheKey = $this->currentCountry . '_' . $formatType;
        
        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }
        
        $adapterClass = $this->globalConfig['adapters'][$formatType]['class'];
        $this->instances[$cacheKey] = new $adapterClass($this->currentCountry);
        
        return $this->instances[$cacheKey];
    }
    
    public function getSupportedBanks(): array {
        $countryConfig = $this->countryConfigs[$this->currentCountry];
        return array_keys($countryConfig['bank_formats']);
    }
    
    /**
     * Get detection statistics for debugging
     */
    public static function testDetection(array $testCases): array
    {
        $results = [];
        foreach ($testCases as $name => $testCase) {
            $detection = self::smartDetect(
                $testCase['payload'] ?? [],
                $testCase['headers'] ?? [],
                $testCase['endpoint'] ?? null,
                $testCase['participant'] ?? null,
                $testCase['bankCode'] ?? null
            );
            $results[$name] = $detection;
        }
        return $results;
    }
}
