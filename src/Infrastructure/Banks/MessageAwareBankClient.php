<?php
namespace Infrastructure\Banks;

use Infrastructure\MessageAdapters\MessageAdapterInterface;

class MessageAwareBankClient extends GenericBankClient
{
    private MessageAdapterInterface $adapter;
    private string $messageFormat;
    
    public function __construct(array $config, MessageAdapterInterface $adapter, string $messageFormat)
    {
        parent::__construct($config);
        $this->adapter = $adapter;
        $this->messageFormat = $messageFormat;
    }
    
    /**
     * Override send to use message adapter
     */
    protected function send(string $action, array $payload): array
    {
        // Create internal transaction from payload
        $transaction = new InternalTransaction($payload);
        
        // Convert to bank's expected format using adapter
        $message = $this->adapter->toExternal($transaction);
        
        // Determine endpoint based on message format
        $endpoint = $this->getEndpointForFormat($action);
        
        // Send the message (could be JSON, XML, or binary)
        return $this->sendMessage($message, $endpoint);
    }
    
    private function sendMessage(string $message, string $endpoint): array
    {
        $url = rtrim($this->config['base_url'], '/') . '/' . ltrim($endpoint, '/');
        
        $headers = $this->buildHeaders([]);
        
        // Add content-type based on message format
        if ($this->messageFormat === 'iso8583') {
            $headers[] = 'Content-Type: application/octet-stream';
        } elseif ($this->messageFormat === 'iso20022') {
            $headers[] = 'Content-Type: application/xml';
        } else {
            $headers[] = 'Content-Type: application/json';
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $message,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Parse response using adapter
        if ($response && $httpCode >= 200 && $httpCode < 300) {
            $resultTx = $this->adapter->toInternal($response);
            return [
                'success' => true,
                'data' => $resultTx->toArray()
            ];
        }
        
        return [
            'success' => false,
            'status_code' => $httpCode,
            'message' => $response
        ];
    }
}
