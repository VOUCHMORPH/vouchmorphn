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

    /**
     * Classifies a raw exception message from SwapService::executeAtomicSwap()
     * into a Mojaloop-shaped error, since SwapService throws plain
     * RuntimeExceptions rather than returning a status/iso_error array.
     * Matched against the actual message vocabulary confirmed in
     * SwapService.php (e.g. "Insufficient funds", "...verification
     * failed...", "...not found...") - not speculative codes.
     *
     * Falls back to the existing generic 5000 bucket for anything
     * unrecognized, so this can never make error reporting WORSE than
     * it is today - only more specific when it can be.
     */
    public static function classifyException(string $message): array
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'insufficient')) {
            return self::build('5100', 'INSUFFICIENT_FUNDS', $message);
        }

        if (str_contains($lower, 'not found')
            || str_contains($lower, 'no compatible payment rail')
            || str_contains($lower, 'participant not found')) {
            return self::build('3204', 'PARTY_NOT_FOUND', $message);
        }

        if (str_contains($lower, 'blocked')
            || str_contains($lower, 'suspended')
            || str_contains($lower, 'regulatory')
            || str_contains($lower, 'sanctioned')
            || str_contains($lower, 'aml')) {
            return self::build('2001', 'AML_BLOCKED', $message);
        }

        if (str_contains($lower, 'expired')) {
            return self::build('3302', 'QUOTE_EXPIRED', $message);
        }

        return self::build('5000', 'INTERNAL_SERVER_ERROR', $message);
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
