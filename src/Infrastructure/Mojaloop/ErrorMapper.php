<?php

declare(strict_types=1);

namespace Infrastructure\Mojaloop;

class MojaloopErrorMapper
{
    public static function map(array $swapResult): array
    {
        $status = $swapResult['status'] ?? 'error';
        $iso = $swapResult['iso_error'] ?? null;
        $msg = $swapResult['message'] ?? 'Unknown error';

        if ($status === 'BLOCKED_REGULATORY') {
            return self::build('2001', 'AML_BLOCKED', $msg);
        }
        if ($iso === 'AC04') {
            return self::build('5100', 'INSUFFICIENT_FUNDS', $msg);
        }
        if ($iso === 'RR04') {
            return self::build('3204', 'PARTY_NOT_FOUND', $msg);
        }
        return self::build('5000', 'INTERNAL_SERVER_ERROR', $msg);
    }

    private static function build(string $code, string $type, string $description): array
    {
        return [
            'errorInformation' => [
                'errorCode' => $code,
                'errorDescription' => $description,
            ],
            'extensionList' => [
                [
                    'key' => 'errorType',
                    'value' => $type,
                ],
            ],
        ];
    }
}
