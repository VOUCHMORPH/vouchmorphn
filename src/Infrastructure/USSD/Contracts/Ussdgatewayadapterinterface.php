<?php
declare(strict_types=1);

namespace Infrastructure\USSD\Contracts;

/**
 * Normalizes USSD gateway wire formats (AfricasTalking-style, telco-direct,
 * Mojaloop USSD proxy, etc.) into one shape the menu state machine can use,
 * and formats the reply back into whatever that gateway expects.
 *
 * This adapter NEVER contains menu/business logic. It only translates.
 */
interface UssdGatewayAdapterInterface
{
    /**
     * Parse an inbound HTTP request body/query into a normalized session request.
     *
     * @param array $rawRequest Raw $_GET/$_POST/decoded-JSON merge
     * @return UssdSessionRequest
     */
    public function parseRequest(array $rawRequest): UssdSessionRequest;

    /**
     * Format a normalized response back into this gateway's expected wire format.
     *
     * @param UssdSessionResponse $response
     * @return string Raw response body to echo back to the gateway
     */
    public function formatResponse(UssdSessionResponse $response): string;

    /**
     * Content-Type header this gateway expects on the response.
     */
    public function getResponseContentType(): string;

    public function getGatewayName(): string;
}
