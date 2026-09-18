<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * Privacy Level Validation (PLV) for account previews.
 *
 * When a sender is shown who they are about to pay, they need enough of the
 * account holder's name to recognise a mistake, and not enough to harvest
 * the name of a stranger's account. Masking every part down to its initial
 * does both: "John Michael Doe" previews as "J*** M****** D**", which the
 * real John recognises instantly and which tells a fisher nothing they
 * didn't already type.
 *
 * A name is valid for preview when it has a first and a last part. A middle
 * name is optional -- plenty of people have none, and requiring one would
 * block legitimate accounts.
 *
 * Pure and static: no state, no I/O, so the rules are directly testable.
 */
final class PrivacyMasker
{
    /**
     * @return array{
     *     valid: bool,
     *     masked: ?string,
     *     parts: array<int, string>,
     *     first: ?string,
     *     middle: array<int, string>,
     *     last: ?string,
     *     error: ?string
     * }
     */
    public static function maskHolderName(?string $fullName): array
    {
        $empty = [
            'valid' => false,
            'masked' => null,
            'parts' => [],
            'first' => null,
            'middle' => [],
            'last' => null,
            'error' => null,
        ];

        $normalised = trim(preg_replace('/\s+/u', ' ', (string)$fullName) ?? '');

        if ($normalised === '') {
            return ['error' => 'No account holder name was returned to check against.'] + $empty;
        }

        $parts = explode(' ', $normalised);

        if (count($parts) < 2) {
            return [
                'error' => 'Account holder name must include a first and a last name.',
                'parts' => $parts,
            ] + $empty;
        }

        return [
            'valid' => true,
            'masked' => implode(' ', array_map([self::class, 'maskPart'], $parts)),
            'parts' => $parts,
            'first' => $parts[0],
            'middle' => array_slice($parts, 1, -1), // may be empty -- middle names are optional
            'last' => $parts[count($parts) - 1],
            'error' => null,
        ];
    }

    /**
     * Do two names refer to the same person? Compared on the first and last
     * parts only, case- and accent-insensitively: middle names come and go
     * between a bank's records and what someone types, and rejecting on that
     * would fail real people far more often than it would catch a wrong
     * account.
     */
    public static function namesMatch(?string $a, ?string $b): bool
    {
        $left = self::maskHolderName($a);
        $right = self::maskHolderName($b);

        if (!$left['valid'] || !$right['valid']) {
            return false;
        }

        return self::normalisePart($left['first']) === self::normalisePart($right['first'])
            && self::normalisePart($left['last']) === self::normalisePart($right['last']);
    }

    private static function maskPart(string $part): string
    {
        $length = mb_strlen($part);
        if ($length <= 1) {
            return $part;
        }

        return mb_substr($part, 0, 1) . str_repeat('*', $length - 1);
    }

    private static function normalisePart(string $part): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $part);
        if ($ascii !== false && $ascii !== '') {
            $part = $ascii;
        }

        return mb_strtolower(preg_replace('/[^A-Za-z]/', '', $part) ?? '');
    }
}
