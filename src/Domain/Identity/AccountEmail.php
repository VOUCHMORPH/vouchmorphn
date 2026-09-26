<?php

namespace Domain\Identity;

/**
 * What counts as a real, verified email on a `users` row.
 *
 * Phone-only sign-ups are given a made-up address to satisfy
 * `users.email NOT NULL` — <username>@<country>.vouchmorphn.com, written by
 * public/user/verify_otp.php and public/api/v1/agent/register_identity_owner.php.
 * Nobody can receive mail there, so it is never verified and never lets
 * anyone sign in.
 */
final class AccountEmail
{
    private const PLACEHOLDER_SUFFIX = '.vouchmorphn.com';

    public static function isPlaceholder(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' && str_ends_with($email, self::PLACEHOLDER_SUFFIX);
    }

    /**
     * Whether the row's email has been verified with a code.
     *
     * $schemaHasColumn is SignInSchema::hasEmailVerifiedAt(). Until the
     * migration adds the column, any real (non-placeholder) address counts
     * as verified — which is exactly what sign-in accepted before, minus
     * the made-up addresses.
     *
     * @param array<string, mixed> $user a users row with email (and email_verified_at when the column exists)
     */
    public static function isVerified(array $user, bool $schemaHasColumn): bool
    {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || self::isPlaceholder($email)) {
            return false;
        }

        if (!$schemaHasColumn) {
            return true;
        }

        return !empty($user['email_verified_at']);
    }

    /**
     * True when the person typed this row's email to sign in, and that
     * email is not verified — the sign-in must then be treated exactly
     * like an unknown account.
     *
     * UserIdentifierLookup matches every identifier column whichever tab
     * was used, so this looks at what was typed, not at the tab.
     *
     * @param array<string, mixed> $user
     */
    public static function blocksSignIn(array $user, string $typedIdentifier, bool $schemaHasColumn): bool
    {
        $typed = IdentifierNormalizer::canonicalEmail($typedIdentifier);
        if ($typed === '' || !str_contains($typed, '@')) {
            return false;
        }

        $stored = IdentifierNormalizer::canonicalEmail((string) ($user['email'] ?? ''));
        if ($stored !== $typed) {
            return false;
        }

        return !self::isVerified($user, $schemaHasColumn);
    }

    /** "jane@example.com" -> "j•••@example.com" */
    public static function mask(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '•••';
        }
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1) . str_repeat('•', max(1, strlen($local) - 1)) . '@' . $domain;
    }
}
