<?php

namespace Domain\Identity;

use PDO;

/**
 * Finds the `users` row behind a typed login identifier, whatever shape
 * that identifier was stored in.
 *
 * The old lookup was a straight equality match:
 *
 *     WHERE phone = :identifier OR phone2 = :identifier OR ... OR passport = :identifier
 *
 * which only ever found a user whose stored value was byte-for-byte what
 * they typed this time. See IdentifierNormalizer for how the same person
 * ends up with several different stored shapes; the practical result was
 * real account holders being told "User not found".
 *
 * Matching here is widened per column type instead:
 *
 *   phone/phone2/phone3  - against every historical shape of the number
 *                          (IdentifierNormalizer::phoneVariants()).
 *   email                - case-insensitively, via lower().
 *   national_id,
 *   drivers_license,
 *   passport             - ignoring case and punctuation, so "cm-123 456"
 *                          finds "CM123456".
 *
 * As before, every column is searched whichever tab the person signed in
 * on, so someone who types their email under "Phone" is still found. A
 * match only identifies which row to check — the PIN is still verified
 * against that user's own credential before any session is granted.
 *
 * Existing rows are left exactly as they are: this widens the read, it
 * does not rewrite anyone's data. The functional indexes in
 * database/migrations/2026_09_20_identifier_lookup_indexes.sql keep the
 * lower()/regexp_replace() comparisons off a sequential scan, and the
 * expressions below are written to match those index expressions
 * verbatim.
 */
final class UserIdentifierLookup
{
    /** Ceiling on rows pulled back when duplicate accounts exist. */
    private const MAX_CANDIDATES = 5;

    /**
     * The one match for this identifier, or null.
     *
     * When more than one row matches — which happens where the same
     * number was written twice in different shapes before this fix — the
     * row matching the canonical form wins, and the ambiguity is logged
     * so it can be merged by hand.
     */
    public static function find(
        PDO $db,
        string $rawIdentifier,
        string $dialCode,
        ?int $localLength = null,
        string $columns = 'user_id'
    ): ?array {
        $rows = self::findCandidates($db, $rawIdentifier, $dialCode, $localLength, $columns);
        if ($rows === []) {
            return null;
        }

        if (count($rows) > 1) {
            $ids = implode(', ', array_map(static fn ($r) => (string) ($r['user_id'] ?? '?'), $rows));
            error_log(
                "[UserIdentifierLookup] Identifier matched " . count($rows) .
                " user rows (ids: {$ids}) — duplicate accounts from pre-normalisation sign-ups. " .
                "Preferring the canonical match; these rows need merging."
            );
        }

        return self::preferCanonical($rows, $rawIdentifier, $dialCode, $localLength);
    }

    /**
     * Every user row this identifier could refer to.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findCandidates(
        PDO $db,
        string $rawIdentifier,
        string $dialCode,
        ?int $localLength = null,
        string $columns = 'user_id'
    ): array {
        $raw = trim($rawIdentifier);
        if ($raw === '') {
            return [];
        }

        [$where, $params] = self::buildMatch($raw, $dialCode, $localLength);
        if ($where === []) {
            return [];
        }

        $sql = "SELECT {$columns} FROM users WHERE " . implode(' OR ', $where)
             . ' ORDER BY user_id ASC LIMIT ' . self::MAX_CANDIDATES;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * The WHERE fragments and bound values for one typed identifier, for
     * callers that need to fold this into a larger query (the sign-up
     * duplicate check builds its own).
     *
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    public static function buildMatch(
        string $rawIdentifier,
        string $dialCode,
        ?int $localLength = null,
        string $prefix = 'id'
    ): array {
        $raw = trim($rawIdentifier);
        if ($raw === '') {
            return [[], []];
        }

        $where  = [];
        $params = [];

        // --- phone columns -------------------------------------------
        if (IdentifierNormalizer::looksLikePhone($raw)) {
            $variants = IdentifierNormalizer::phoneVariants($raw, $dialCode, $localLength);
            if ($variants !== []) {
                // A separate placeholder per column per variant: this
                // connection uses native prepares, where reusing one
                // named placeholder in several places is not portable.
                foreach (['phone', 'phone2', 'phone3'] as $colIndex => $column) {
                    $placeholders = [];
                    foreach ($variants as $i => $variant) {
                        $name = ":{$prefix}_p{$colIndex}_{$i}";
                        $placeholders[]     = $name;
                        $params[$name]      = $variant;
                    }
                    $where[] = "{$column} IN (" . implode(', ', $placeholders) . ')';
                }
            }
        }

        // --- email ----------------------------------------------------
        $email = IdentifierNormalizer::canonicalEmail($raw);
        if ($email !== '') {
            $where[] = "lower(email) = :{$prefix}_email";
            $params[":{$prefix}_email"] = $email;
        }

        // --- ID documents ---------------------------------------------
        $document = IdentifierNormalizer::canonicalDocument($raw);
        if ($document !== '') {
            foreach (['national_id', 'drivers_license', 'passport'] as $column) {
                $name = ":{$prefix}_doc_{$column}";
                $where[] = "({$column} IS NOT NULL AND upper(regexp_replace({$column}, '[^A-Za-z0-9]', '', 'g')) = {$name})";
                $params[$name] = $document;
            }
        }

        return [$where, $params];
    }

    /**
     * Of several matching rows, the one whose stored value is already in
     * canonical form — the account the person has most likely been using
     * — falling back to the lowest user_id.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private static function preferCanonical(
        array $rows,
        string $rawIdentifier,
        string $dialCode,
        ?int $localLength
    ): array {
        $canonicalPhone = IdentifierNormalizer::canonicalPhone($rawIdentifier, $dialCode, $localLength);
        $canonicalEmail = IdentifierNormalizer::canonicalEmail($rawIdentifier);

        foreach ($rows as $row) {
            if ($canonicalPhone !== '') {
                foreach (['phone', 'phone2', 'phone3'] as $column) {
                    if (isset($row[$column]) && $row[$column] === $canonicalPhone) {
                        return $row;
                    }
                }
            }
            if ($canonicalEmail !== '' && isset($row['email']) && strtolower((string) $row['email']) === $canonicalEmail) {
                return $row;
            }
        }

        return $rows[0];
    }
}
