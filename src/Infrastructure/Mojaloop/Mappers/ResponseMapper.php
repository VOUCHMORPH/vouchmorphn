<?php


namespace DFSP_ADAPTER_LAYER\mapper;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

class ResponseMapper
{
    public static function mapPartyLookup($result): array
    {
        return [
            'status' => 'success',
            'data' => [
                'party' => [
                    'partyIdInfo' => [
                        'partyIdType' => $result['partyIdType'] ?? 'MSISDN',
                        'partyIdentifier' => $result['partyIdentifier'] ?? '',
                        'fspId' => $result['fspId'] ?? 'VOUCHMORPHN'
                    ],
                    'name' => $result['name'] ?? '',
                    'personalInfo' => [
                        'complexName' => [
                            'firstName' => $result['firstName'] ?? '',
                            'lastName' => $result['lastName'] ?? ''
                        ]
                    ]
                ]
            ]
        ];
    }

    public static function mapQuote($result): array
    {
        return [
            'status' => 'success',
            'data' => [
                'quoteId' => $result['quoteId'] ?? uniqid(),
                'transferAmount' => $result['amount'] ?? ['amount' => '100', 'currency' => 'BWP'],
                'expiration' => date('c', strtotime('+1 hour')),
                'ilpPacket' => $result['ilpPacket'] ?? 'AYIBwgQAAAAAAAASAwBYZXhhbXBsZSB0ZXN0IHZhbHVlIG9ubHk',
                'condition' => $result['condition'] ?? 'ILPcZXy5K8q_7Ff6QtUABnX5Hn3RXq4l8YqR6b8Ff6Q'
            ]
        ];
    }

    public static function mapTransfer($result): array
    {
        return [
            'status' => 'success',
            'data' => [
                'transferId' => $result['transferId'] ?? uniqid(),
                'transferState' => 'COMMITTED',
                'completedTimestamp' => date('c'),
                'fulfilment' => $result['fulfilment'] ?? 'ILPf4q8q_7Ff6QtUABnX5Hn3RXq4l8YqR6b8Ff6Q'
            ]
        ];
    }
}
