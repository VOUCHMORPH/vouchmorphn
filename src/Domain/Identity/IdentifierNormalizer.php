<?php

namespace Domain\Identity;

/**
 * One canonical shape per login identifier — and every shape the older
 * writers could have stored it in.
 *
 * WHY THIS EXISTS
 * ---------------
 * Registration and login each had their own copy of normalizePhone():
 *
 *     if (str_starts_with($phone, '+')) return $phone;
 *     return $dialCode . ltrim($phone, '0');
 *
 * That function is not idempotent and it never removes a country code the
 * person typed themselves. Both the sign-up and the sign-in form show a
 * "+267" prefix next to a free-text box, so what actually landed in
 * `users.phone` depended entirely on how the person typed it that day:
 *
 *     typed "71234567"      -> +26771234567
 *     typed "071234567"     -> +26771234567
 *     typed "26771234567"   -> +26726771234567   <- country code twice
 *     typed "+26771234567"  -> +26771234567
 *     typed "00267 7123..." -> +26726771234567   <- country code twice
 *
 * `users.phone` is UNIQUE and the login lookup was an exact `=` match, so
 * anyone who typed their number one way at registration and the other way
 * at login was told "User not found. Please check your identifier." even
 * though their account existed. Same class of bug for email: registration
 * lowercases it, login compared the raw input, and Postgres `=` on
 * varchar is case-sensitive — so "Jane@Example.com" (what a phone
 * keyboard autocapitalises) never matched the stored "jane@example.com".
 *
 * So there are two jobs here, and they are deliberately separate:
 *
 *   canonical*()  - the single shape NEW data is written in.
 *   phoneVariants() - every shape EXISTING rows may already hold, so the
 *                     login lookup can still find users who registered
 *                     before this fix. Those rows are not rewritten;
 *                     matching is widened to reach them.
 */
final class IdentifierNormalizer
{
    /**
     * When the country's local number length is unknown, never strip a
     * country code that would leave fewer than this many digits behind —
     * that is a sign we are eating into the subscriber number itself.
     */
    private const MIN_NATIONAL_DIGITS = 5;

    /** Guards against a pathological input looping forever. */
    private const MAX_DIAL_CODE_STRIPS = 5;

    /**
     * E.164, with any repeated country code and trunk prefix collapsed.
     *
     * $localLength is the country's local subscriber length (8 for
     * Botswana). When supplied, a number that is already exactly that
     * long is left alone even if it happens to start with the country's
     * own digits — 26712345 is a valid local Botswana number, not a
     * country code followed by 12345.
     */
    public static function canonicalPhone(string $raw, string $dialCode, ?int $localLength = null): string
    {
        $cleaned = preg_replace('/[^0-9+]/', '', trim($raw)) ?? '';
        if ($cleaned === '') {
            return '';
        }

        $hadPlus = str_starts_with($cleaned, '+');
        $digits  = preg_replace('/[^0-9]/', '', $cleaned) ?? '';
        if ($digits === '') {
            return '';
        }

        // "00267..." is the same thing as "+267..." — the international
        // access prefix, not a trunk zero.
        if (!$hadPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $dial     = ltrim($dialCode, '+');
        $national = ltrim($digits, '0');
        if ($national === '') {
            return '';
        }

        $strips = 0;
        while ($dial !== '' && $strips < self::MAX_DIAL_CODE_STRIPS && str_starts_with($national, $dial)) {
            $candidate = ltrim(substr($national, strlen($dial)), '0');
            if ($candidate === '') {
                break;
            }
            if ($localLength !== null && $localLength > 0) {
                // Already a plain local number — the leading digits are
                // part of the subscriber number, not a country code.
                if (strlen($national) <= $localLength) {
                    break;
                }
            } elseif (strlen($candidate) < self::MIN_NATIONAL_DIGITS) {
                break;
            }
            $national = $candidate;
            $strips++;
        }

        return '+' . $dial . $national;
    }

    /**
     * The local/subscriber part of a number, with no country code and no
     * trunk zero: "+26771234567" -> "71234567".
     */
    public static function nationalPhonePart(string $raw, string $dialCode, ?int $localLength = null): string
    {
        $canonical = self::canonicalPhone($raw, $dialCode, $localLength);
        if ($canonical === '') {
            return '';
        }
        $dial = ltrim($dialCode, '+');

        return $dial !== '' && str_starts_with(substr($canonical, 1), $dial)
            ? substr($canonical, 1 + strlen($dial))
            : substr($canonical, 1);
    }

    /**
     * Every stored shape of this number that a lookup should still match.
     *
     * Ordered most-canonical first; the caller uses that order to pick a
     * winner when a number somehow exists on more than one row. The list
     * covers what the old normalizePhone() wrote (including the
     * double-country-code rows), the bare local forms, and the
     * no-plus form seen in the legacy dumps ("26771000000").
     */
    public static function phoneVariants(string $raw, string $dialCode, ?int $localLength = null): array
    {
        $national = self::nationalPhonePart($raw, $dialCode, $localLength);
        if ($national === '') {
            return [];
        }

        $dial = ltrim($dialCode, '+');

        $variants = [
            '+' . $dial . $national,          // canonical E.164
            $dial . $national,                // no plus (legacy dumps)
            $national,                        // bare local
            '0' . $national,                  // local with trunk zero
            '+' . $dial . '0' . $national,    // "+267 071234567"
            $dial . '0' . $national,
            '00' . $dial . $national,         // international access prefix
            '+' . $dial . $dial . $national,  // old normalizer, code typed twice
            $dial . $dial . $national,
        ];

        // Whatever the person literally typed, in case some other writer
        // stored it verbatim.
        $typed = trim($raw);
        if ($typed !== '') {
            $variants[] = $typed;
        }

        return array_values(array_unique(array_filter($variants, static fn ($v) => $v !== '')));
    }

    /**
     * Email is matched case-insensitively at the database end; this is
     * the shape it is written in.
     */
    public static function canonicalEmail(string $raw): string
    {
        return strtolower(trim($raw));
    }

    /**
     * ID document numbers: case and punctuation carry no meaning, and
     * people type them with spaces, dots and dashes in different places
     * each time. "cm-123 456" and "CM123456" are the same document.
     */
    public static function canonicalDocument(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($raw)) ?? '');
    }

    /**
     * Whether an input can sensibly be read as a phone number at all —
     * used to skip phone matching for something that is plainly an email
     * or a document number.
     */
    public static function looksLikePhone(string $raw): bool
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || str_contains($trimmed, '@')) {
            return false;
        }

        return (bool) preg_match('/^\+?[0-9][0-9\s().\-]*$/', $trimmed);
    }

    /**
     * The canonical form for a given identifier type, for writers that
     * hold the type as a string ('phone', 'email', 'national_id', ...).
     */
    public static function canonicalize(string $identifierType, string $raw, string $dialCode, ?int $localLength = null): string
    {
        return match ($identifierType) {
            'phone', 'phone2', 'phone3', 'sms' => self::canonicalPhone($raw, $dialCode, $localLength),
            'email'                            => self::canonicalEmail($raw),
            'national_id', 'drivers_license', 'passport' => trim($raw),
            default                            => trim($raw),
        };
    }
}
