<?php

namespace Infrastructure\Banks;

require_once __DIR__ . '/Contracts/BankAPIInterface.php';

use Infrastructure\Banks\Contracts\BankAPIInterface;
use Infrastructure\MessageAdapters\MessageAdapterFactory;

class GenericBankClient implements BankAPIInterface
{
    protected array $config;
    protected $httpClient;
    protected ?string $detectedFormat = null;
    protected ?int $detectionConfidence = null;
    protected ?string $detectionSource = null;
    protected array $detectionDetails = [];

    public function __construct(array $config, ?array $requestPayload = null, ?array $headers = null, ?string $endpoint = null)
    {
        $this->config = $config;
        
        // Smart detection - determine message format without being told
        $detection = MessageAdapterFactory::smartDetect(
            $requestPayload ?? [],
            $headers ?? [],
            $endpoint,
            $config,
            $config['provider_code'] ?? null
        );
        
        $this->detectedFormat = $detection['format'];
        $this->detectionConfidence = $detection['confidence'];
        $this->detectionSource = $detection['source'];
        $this->detectionDetails = $detection['all_detections'] ?? [];
        
        error_log("=== GENERIC BANK CLIENT SMART DETECTION ===");
        error_log("Bank: " . ($this->config['provider_code'] ?? 'unknown'));
        error_log("Detected Format: {$this->detectedFormat}");
        error_log("Confidence: {$this->detectionConfidence}%");
        error_log("Source: {$this->detectionSource}");
        error_log("All detections: " . json_encode($this->detectionDetails));
    }
    
    public function getDetectedFormat(): ?string
    {
        return $this->detectedFormat;
    }
    
    public function getDetectionConfidence(): ?int
    {
        return $this->detectionConfidence;
    }
    
    public function getDetectionSource(): ?string
    {
        return $this->detectionSource;
    }
    
    public function getDetectionDetails(): array
    {
        return $this->detectionDetails;
    }

    // ============================================================================
    // SOURCE ROLE METHODS
    // ============================================================================

    public function verifyAsset(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyAsset ===");
        error_log("Bank: " . ($this->config['provider_code'] ?? 'unknown'));
        error_log("Using message format: {$this->detectedFormat}");
        error_log("Original payload: " . json_encode($payload));
        return $this->send('verify_asset', $payload);
    }

    public function placeHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: placeHold ===");
        error_log("Using message format: {$this->detectedFormat}");
        return $this->send('place_hold', $payload);
    }

    public function releaseHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: releaseHold ===");
        return $this->send('release_hold', $payload);
    }

    public function debitFunds(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: debitFunds ===");
        return $this->send('debit_funds', $payload);
    }

    // ============================================================================
    // DEBIT HOLD METHOD
    // ============================================================================
    
    public function debitHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: debitHold (maps to debitFunds) ===");
        error_log("Using message format: {$this->detectedFormat}");
        
        if (!isset($payload['hold_reference'])) {
            error_log("ERROR: hold_reference is required for debitHold");
            return [
                'success' => false,
                'message' => 'hold_reference is required',
                'data' => []
            ];
        }
        
        $debitPayload = [
            'reference' => $payload['reference'] ?? $payload['hold_reference'],
            'hold_reference' => $payload['hold_reference'],
            'amount' => $payload['amount'] ?? null,
            'reason' => $payload['reason'] ?? 'Debit hold for completed swap',
            'action' => 'DEBIT_HOLD'
        ];
        
        return $this->debitFunds($debitPayload);
    }

    // ============================================================================
    // DESTINATION ROLE METHODS
    // ============================================================================

    public function generateToken(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: generateToken ===");
        return $this->send('generate_token', $payload);
    }

    public function verifyToken(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyToken ===");
        return $this->send('verify_token', $payload);
    }

    public function confirmCashout(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: confirmCashout ===");
        return $this->send('confirm_cashout', $payload);
    }

    public function processDeposit(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: processDeposit ===");
        return $this->send('process_deposit', $payload);
    }

    // ============================================================================
    // COMMON METHODS
    // ============================================================================

    public function checkStatus(string $reference): array
    {
        error_log("=== GENERIC BANK CLIENT: checkStatus ===");
        return $this->send('check_status', ['reference' => $reference]);
    }

    public function reverseTransaction(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: reverseTransaction ===");
        return $this->send('reverse_transaction', $payload);
    }

    // ============================================================================
    // LEGACY METHODS (used by SwapService)
    // ============================================================================

    public function authorize(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: authorize (maps to place_hold) ===");
        return $this->placeHold($payload);
    }

    public function transfer(array $payload, string $type = null): array
    {
        error_log("=== GENERIC BANK CLIENT: transfer called ===");
        error_log("Type: " . ($type ?? 'none'));
        error_log("Action: " . ($payload['action'] ?? 'none'));
        error_log("Using message format: {$this->detectedFormat}");
        
        $action = $payload['action'] ?? $type ?? '';
        
        switch ($action) {
            case 'GENERATE_ATM_TOKEN':
            case 'generate_atm_code':
                error_log("Mapping to generateToken");
                return $this->generateToken($payload);
                
            case 'PROCESS_DEPOSIT':
                error_log("Mapping to processDeposit");
                return $this->processDeposit($payload);
                
            case 'AUTHORIZE_CASHOUT':
                error_log("Mapping to authorize");
                return $this->authorize($payload);
                
            case 'DEBIT_HOLD':
                error_log("Mapping to debitHold");
                return $this->debitHold($payload);
                
            default:
                error_log("Unknown action: $action, defaulting to processDeposit");
                return $this->processDeposit($payload);
        }
    }

    public function reverse(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: reverse ===");
        return $this->reverseTransaction($payload);
    }

    // ============================================================================
    // HELPER METHODS
    // ============================================================================

    protected function send(string $action, array $payload): array
    {
        $endpoint = $this->getEndpoint($action);
        
        error_log("=== GENERIC BANK CLIENT SEND ===");
        error_log("Bank: " . ($this->config['provider_code'] ?? 'unknown'));
        error_log("Action: " . $action);
        error_log("Message Format: {$this->detectedFormat}");
        error_log("Endpoint: " . ($endpoint ?? 'null'));
        error_log("Full URL: " . (rtrim($this->config['base_url'] ?? '', '/') . '/' . ltrim($endpoint ?? '', '/')));

        error_log("🚨 FULL PAYLOAD BEING SENT TO BANK: " . json_encode($payload));
        
        if (!$endpoint) {
            error_log("ERROR: No endpoint found for action: " . $action);
            error_log("Available endpoints: " . json_encode($this->config['resource_endpoints'] ?? []));
            throw new \Exception("Endpoint {$action} not configured for " . ($this->config['provider_code'] ?? 'unknown bank'));
        }

        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $endpoint = ltrim($endpoint, '/');
        $url = $baseUrl . '/' . $endpoint;
        
        error_log("Base URL: " . ($this->config['base_url'] ?? 'NOT SET'));
        error_log("Endpoint path: " . $endpoint);
        error_log("Full URL: " . $url);
        error_log("Payload being sent: " . json_encode($payload));

        $headers = $this->buildHeaders($payload);
        error_log("Headers: " . json_encode($headers));
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_VERBOSE => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        if (curl_errno($ch)) {
            error_log("CURL Error: " . curl_error($ch));
        }
        
        curl_close($ch);

        error_log("HTTP Code: " . $httpCode);
        error_log("Response: " . ($response ?: 'EMPTY'));
        
        if ($curlError) {
            error_log("CURL Error: " . $curlError);
        }

        $decodedResponse = json_decode($response, true);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'status_code' => $httpCode,
            'data' => $decodedResponse ?? [],
            'raw_response' => $response,
            'curl_error' => $curlError,
            'detected_format' => $this->detectedFormat,
            'detection_confidence' => $this->detectionConfidence
        ];
    }

    protected function getEndpoint(string $action): ?string
    {
        $endpointMap = [
            'verify_asset' => 'verify_asset',
            'place_hold' => 'place_hold',
            'release_hold' => 'release_hold',
            'debit_funds' => 'debit_funds',
            'generate_token' => 'generate_token',
            'verify_token' => 'verify_token',
            'confirm_cashout' => 'confirm_cashout',
            'process_deposit' => 'process_deposit',
            'check_status' => 'check_status',
            'reverse_transaction' => 'reverse_transaction',
            'authorize' => 'place_hold',
            'transfer' => 'process_deposit',
            'reverse' => 'reverse_transaction',
            'debit_hold' => 'debit_funds'
        ];

        $endpointKey = $endpointMap[$action] ?? $action;
        
        error_log("getEndpoint() - Action: $action, Mapped to key: $endpointKey");
        error_log("Available endpoints: " . json_encode($this->config['resource_endpoints'] ?? []));
        
        $endpoint = $this->config['resource_endpoints'][$endpointKey] ?? 
                    $this->config['endpoints'][$endpointKey] ?? null;
        
        error_log("Found endpoint: " . ($endpoint ?? 'null'));
        
        return $endpoint;
    }

    protected function buildHeaders(array $payload): array
    {
        $headers = ['Content-Type: application/json'];
        
        // Add detected format header for transparency
        if ($this->detectedFormat) {
            $headers[] = 'X-Detected-Format: ' . $this->detectedFormat;
            $headers[] = 'X-Format-Confidence: ' . ($this->detectionConfidence ?? 0);
        }
        
        if (isset($payload['reference'])) {
            $headers[] = 'X-Correlation-ID: ' . $payload['reference'];
        }
        
        if (isset($this->config['security'])) {
            $security = $this->config['security'];
            
            if (isset($security['api_key'])) {
                $key = getenv($security['api_key']['value_env'] ?? '');
                if ($key) {
                    $headers[] = ($security['api_key']['header_name'] ?? 'X-API-Key') . ': ' . $key;
                    error_log("Added API Key header");
                } else {
                    error_log("WARNING: API Key env var not set: " . ($security['api_key']['value_env'] ?? 'unknown'));
                }
            }
        }
        
        return $headers;
    }
}
