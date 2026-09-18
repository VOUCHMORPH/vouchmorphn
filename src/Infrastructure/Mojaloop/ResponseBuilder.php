<?php


declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

class MojaloopResponseBuilder
{
    public static function buildTransferSuccess(array $swapResult): array
    {
        return [
            'transferState' => 'COMMITTED',
            'completedTimestamp' => date('c'),
            'settlementAmount' => $swapResult['sttlm_amt'] ?? [
                'amount' => 0,
                'currency' => 'BWP'
            ]
        ];
    }

    public static function buildQuoteResponse(array $quoteData): array
    {
        return [
            'transferAmount' => [
                'amount' => $quoteData['transferAmount']['amount'] ?? 0,
                'currency' => $quoteData['transferAmount']['currency'] ?? 'BWP'
            ],
            'expiration' => date('c', strtotime('+30 seconds'))
        ];
    }

    public static function buildPartyResponse(string $fspId): array
    {
        return [
            'party' => [
                'partyIdInfo' => [
                    'fspId' => strtoupper($fspId)
                ]
            ]
        ];
    }
}

