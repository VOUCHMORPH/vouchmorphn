<?php
declare(strict_types=1);

namespace Infrastructure\USSD\Contracts;

use Infrastructure\USSD\Contracts\UssdGatewayAdapterInterface;
use Infrastructure\USSD\Contracts\UssdSessionRequest;
use Infrastructure\USSD\Contracts\UssdSessionResponse;
use RuntimeException;

/**
 * Handles the wire formats of common USSD gateway families via config,
 * the same way SmsGatewayClient handles any SMS aggregator via config.
 *
 * Config shape (see Core/Config/Countries/{Country}/ussd_gateways.yaml):
 *
 *   AFRICASTALKING_STYLE:
 *     request_fields:
 *       session_id: [sessionId, SESSION_ID, session_id]   # tries each key in order
 *       phone:      [phoneNumber, MSISDN, phone_number]
 *       text:       [text, INPUT]
 *     response_format: "prefix"       # CON/END prefix, plain text body
 *     content_type: "text/plain; charset=UTF-8"
 *
 *   TELCO_BINARY_STYLE:
 *     request_fields:
 *       session_id: [SessionID]
 *       phone:      [Msisdn]
 *       text:       [UserInput]
 *     response_format: "xml"          # some telcos want XML envelopes
 *     content_type: "application/xml"
 *     xml_template: "<response><Type>{type}</Type><Message>{message}</Message></response>"
 *
 *   JSON_ENVELOPE_STYLE:
 *     request_fields:
 *       session_id: [session_id]
 *       phone:      [msisdn]
 *       text:       [ussd_string]
 *     response_format: "json"
 *     content_type: "application/json"
 */
class UssdGatewayAdapter implements UssdGatewayAdapterInterface
{
    private array $config;
    private string $gatewayName;

    public function __construct(array $config, string $gatewayName)
    {
        $this->config = $config;
        $this->gatewayName = $gatewayName;
        $this->validateConfiguration();
    }

    private function validateConfiguration(): void
    {
        foreach (['request_fields', 'response_format'] as $field) {
            if (empty($this->config[$field])) {
                throw new RuntimeException("USSD gateway '{$this->gatewayName}' missing config: {$field}");
            }
        }
        foreach (['session_id', 'phone', 'text'] as $field) {
            if (empty($this->config['request_fields'][$field])) {
                throw new RuntimeException("USSD gateway '{$this->gatewayName}' missing request_fields.{$field}");
            }
        }
    }

    public function parseRequest(array $rawRequest): UssdSessionRequest
    {
        $sessionId = $this->extractField($rawRequest, 'session_id') ?? '';
        $phone = $this->extractField($rawRequest, 'phone') ?? '';
        $text = $this->extractField($rawRequest, 'text') ?? '';

        return new UssdSessionRequest(
            sessionId: trim($sessionId),
            phoneNumber: trim($phone),
            text: trim($text),
            rawPayload: $rawRequest
        );
    }

    private function extractField(array $rawRequest, string $logicalField): ?string
    {
        $candidateKeys = $this->config['request_fields'][$logicalField] ?? [];

        foreach ($candidateKeys as $key) {
            if (isset($rawRequest[$key]) && $rawRequest[$key] !== '') {
                return (string)$rawRequest[$key];
            }
        }
        return null;
    }

    public function formatResponse(UssdSessionResponse $response): string
    {
        $format = $this->config['response_format'];

        return match ($format) {
            'prefix' => $this->formatPrefixStyle($response),
            'xml' => $this->formatXmlStyle($response),
            'json' => $this->formatJsonStyle($response),
            default => throw new RuntimeException("Unknown USSD response_format: {$format}")
        };
    }

    /** AfricasTalking-style: "CON <msg>" or "END <msg>" plain text */
    private function formatPrefixStyle(UssdSessionResponse $response): string
    {
        $prefix = $response->continueSession ? 'CON' : 'END';
        return "{$prefix} {$response->message}";
    }

    /** Some telcos want an XML envelope with an explicit type field */
    private function formatXmlStyle(UssdSessionResponse $response): string
    {
        $template = $this->config['xml_template']
            ?? '<response><Type>{type}</Type><Message>{message}</Message></response>';

        $type = $response->continueSession ? 'response' : 'release';

        return str_replace(
            ['{type}', '{message}'],
            [$type, htmlspecialchars($response->message, ENT_XML1)],
            $template
        );
    }

    /** JSON envelope style gateways */
    private function formatJsonStyle(UssdSessionResponse $response): string
    {
        return json_encode([
            'message' => $response->message,
            'continueSession' => $response->continueSession,
        ]);
    }

    public function getResponseContentType(): string
    {
        return $this->config['content_type'] ?? 'text/plain; charset=UTF-8';
    }

    public function getGatewayName(): string
    {
        return $this->gatewayName;
    }
}
