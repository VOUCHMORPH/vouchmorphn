<?php


declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

class MojaloopRequestParser
{
    public static function parseTransfer(array $payload): array
    {
        return [
            'transferId' => $payload['transferId'] ?? bin2hex(random_bytes(16)),
            'payerFsp'   => strtoupper($payload['payer']['partyIdInfo']['fspId'] ?? ''),
            'payeeFsp'   => strtoupper($payload['payee']['partyIdInfo']['fspId'] ?? ''),
            'amount'     => (float)($payload['amount']['amount'] ?? 0),
            'currency'   => $payload['amount']['currency'] ?? 'BWP',
            'transactionType' => $payload['transactionType'] ?? 'TRANSFER',
            'metadata'   => [
                'swap_reference' => $payload['transferId'] ?? null,
                'instruction_id' => $payload['quoteId'] ?? null,
                'currency' => $payload['amount']['currency'] ?? 'BWP',
                'transaction_id' => $payload['transferId'] ?? null,
                'source_account' => 'CUST',
                'recipient_account' => 'CUST'
            ]
        ];
    }

    public static function parseQuote(array $payload): array
    {
        return [
            'transactionId' => $payload['quoteId'] ?? bin2hex(random_bytes(12)),
            'payerFsp' => strtoupper($payload['payer']['partyIdInfo']['fspId'] ?? ''),
            'payeeFsp' => strtoupper($payload['payee']['partyIdInfo']['fspId'] ?? ''),
            'amount'   => (float)($payload['amount']['amount'] ?? 0),
            'currency' => $payload['amount']['currency'] ?? 'BWP',
            'metadata' => []
        ];
    }

    public static function parsePartyLookup(string $type, string $id): array
    {
        return [
            'partyIdType' => strtoupper($type),
            'partyIdentifier' => strtoupper($id)
        ];
    }
}

