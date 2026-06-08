<?php
// src/Infrastructure/SMS/SmsGatewayClient.php

namespace Infrastructure\SMS;

use Infrastructure\SMS\Contracts\ProviderInterface;
use Security\Encryption\KeyVault;

class SmsGatewayClient implements ProviderInterface
{
    private $config;
    private $keyVault;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->keyVault = KeyVault::getInstance();
    }
    
    public function send(string $to, string $message, ?string $from = null): array
    {
        $baseUrl = rtrim($this->config['base_url'], '/');
        $endpoint = $this->config['sms_endpoint'] ?? '/api.php?path=sms/send';
        $apiKey = $this->config['api_key'] ?? $this->keyVault->get($this->config['api_key_env'] ?? 'CAZACOM_API_KEY');
        $apiKeyHeader = $this->config['api_key_header'] ?? 'X-API-Key';
        
        $payload = [
            'recipient_number' => $to,
            'message' => $message,
            'sender' => $from ?? $this->config['default_sender'] ?? 'VOUCHMORPH',
            'reference' => uniqid('sms_')
        ];
        
        $ch = curl_init($baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            $apiKeyHeader . ': ' . $apiKey,
            'X-Correlation-ID: ' . uniqid(),
            'X-Timestamp: ' . time()
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout'] ?? 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        
        $result = json_decode($response, true);
        
        return [
            'success' => ($httpCode === 200 || $httpCode === 201) && ($result['status'] ?? '') === 'success',
            'response' => $result,
            'provider' => $this->config['provider'] ?? 'unknown',
            'reference' => $payload['reference']
        ];
    }
    
    public function getStatus(string $reference): array
    {
        // Implement status check if endpoint exists
        return ['success' => true, 'status' => 'delivered'];
    }
}
