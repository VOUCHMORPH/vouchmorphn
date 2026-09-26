<?php

namespace Domain\Identity;

use PDO;

/**
 * Usernames for accounts that sign up with an email address.
 *
 * public/user/verify_otp.php used to build the fallback username from the
 * digits in the phone number — and for an email sign-up there is no phone,
 * so it took the digits out of the email instead: "jane@example.com"
 * became "user_". Now the part before the @ is used: "jane", then
 * "jane2", "jane3"... when that is taken.
 */
final class UsernameGenerator
{
    /** Used when nothing usable is left of the email's local part. */
    public const FALLBACK = 'user';

    /** Well under users.username's varchar(100), and still readable. */
    public const MAX_BASE_LENGTH = 30;

    /**
     * "Jane.Doe@example.com" -> "jane.doe". Lowercase, keeping only
     * a-z, 0-9, dots and underscores; leading and trailing dots or
     * underscores are dropped.
     */
    public static function baseFromEmail(string $email): string
    {
        $local = strtolower(trim(explode('@', trim($email), 2)[0]));
        $clean = preg_replace('/[^a-z0-9._]/', '', $local) ?? '';
        $clean = trim(substr($clean, 0, self::MAX_BASE_LENGTH), '._');

        return $clean !== '' ? $clean : self::FALLBACK;
    }

    /**
     * $base if it is free, otherwise $base2, $base3, ...
     *
     * @param array<int, string> $taken existing usernames that start with $base (any case)
     */
    public static function firstFree(string $base, array $taken): string
    {
        $taken = array_fill_keys(array_map('strtolower', $taken), true);
        $base = strtolower($base);

        if (!isset($taken[$base])) {
            return $base;
        }
        for ($n = 2; ; $n++) {
            if (!isset($taken[$base . $n])) {
                return $base . $n;
            }
        }
    }

    /** The username to give a new account signing up with $email. */
    public static function forEmail(PDO $db, string $email): string
    {
        $base = self::baseFromEmail($email);

        // One query for every username that could collide: the base
        // itself and anything starting with it. "_" is a LIKE wildcard,
        // so it (and the escape character) are escaped.
        $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base) . '%';
        $stmt = $db->prepare("SELECT username FROM users WHERE lower(username) LIKE :pattern ESCAPE '\\'");
        $stmt->execute([':pattern' => $pattern]);
        $taken = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return self::firstFree($base, array_map('strval', $taken));
    }
}
