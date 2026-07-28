<?php

declare(strict_types=1);

namespace Infrastructure\Mojaloop;

/**
 * Sends outbound FSPIOP-style async callbacks (PUT /parties/.., PUT
 * /quotes/.., PUT /transfers/..) to a counterparty FSP or the switch.
 *
 * Two bugs fixed from the previous version:
 *   1. FSPIOP-Destination was hardcoded to a single literal value for
 *      every call ("VOUCHMORPHN" - clearly hacked in to satisfy a
 *      Testing Toolkit assertion). It is now a real parameter, since
 *      the actual destination differs per call and a wrong header
 *      here would misroute the callback in a real multi-FSP network.
 *   2. curl_exec()'s result was previously discarded entirely
 *      (`curl_exec($ch); curl_close($ch);` with nothing captured),
 *      so there was no way to know whether a callback actually
 *      reached its destination. Every call now returns the HTTP
 *      status and body so the caller can log/retry on failure.
 */
class MojaloopHttpClient
{
    private string $baseUrl;
    private string $fspId;

    public function __construct(array $config)
    {
        $this->baseUrl = "{$config['scheme']}://{$config['host']}:{$config['port']}";
        $this->fspId = $config['fspid'];
    }

    private function send(string $method, string $path, array $body, string $resourceType, string $destinationFspId): array
    {
        $url = $this->baseUrl . $path;
        $contentType = "application/vnd.interoperability.{$resourceType}+json;version=1.0";

        $headers = [
            "Content-Type: {$contentType}",
            "Accept: {$contentType}",
            "FSPIOP-Source: {$this->fspId}",
            "FSPIOP-Destination: {$destinationFspId}",
            'Date: ' . gmdate('D, d M Y H:i:s') . ' GMT',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            // NOTE: SSL verification is disabled below for sandbox/TTK
            // use only. This must be re-enabled (remove these two
            // lines, or set both to true) before this touches any
            // real counterparty endpoint - shipping with verification
            // off is a live man-in-the-middle risk in production.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'success' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body' => $rawResponse !== false ? json_decode($rawResponse, true) : null,
            'curl_error' => $curlError ?: null,
        ];
    }

    public function putParties(string $type, string $id, array $body, string $destinationFspId): array
    {
        return $this->send('PUT', "/parties/{$type}/{$id}", $body, 'parties', $destinationFspId);
    }

    public function putQuotes(string $quoteId, array $body, string $destinationFspId): array
    {
        return $this->send('PUT', "/quotes/{$quoteId}", $body, 'quotes', $destinationFspId);
    }

    public function putTransfers(string $transferId, array $body, string $destinationFspId): array
    {
        return $this->send('PUT', "/transfers/{$transferId}", $body, 'transfers', $destinationFspId);
    }
}
