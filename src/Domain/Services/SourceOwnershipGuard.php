<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Throwable;
use Domain\Identity\IdentifierNormalizer;

/**
 * Decides whether the person asking may move money out of a source.
 *
 * WHY THIS EXISTS
 * ---------------
 * Hooking a source to a card, swapping, paying a QR request and activating
 * a card all took the source's account number straight from the request
 * body. The institution was asked whether that account exists; nobody
 * asked whether it was the requester's. An institution that answers
 * "verified" for any identifier (a card acquirer with pre-auth off, a
 * sandbox) let a made-up number through, and a real number belonging to
 * someone else was held and debited. hook.php also took the source's
 * owner_user_id from the request body, and skipped its consent check
 * whenever that claimed owner was the card's owner.
 *
 * A source is now used only when the requester has proved it is theirs:
 *
 *   1. an active row in user_source_accounts - the institution confirmed
 *      ownership by OTP or bank login, or an admin approved it after
 *      manual review (SwapService::initiateUserSourceRegistration(), the
 *      admin dashboard's source approvals);
 *   2. an active row in source_accounts linked to this user by OAuth;
 *   3. a mobile wallet on the user's own phone number. users.phone is only
 *      written after an OTP to that number (register.php,
 *      agent/register_identity_owner.php) - the same proof the network
 *      itself would ask for. phone2/phone3 are typed in unverified and do
 *      not count.
 *
 * Vouchers are bearer instruments: whoever holds the number and the PIN
 * owns the value, and the issuer checks both. A voucher needs a
 * well-formed number and its PIN; it is never looked up here.
 *
 * Every check runs before any verify, hold or debit, so a refusal costs
 * nothing. Matching ignores separators and, for phone numbers, the shape
 * the number was typed in (IdentifierNormalizer), and callers send on the
 * identifier that was verified rather than the one that was typed.
 */
final class SourceOwnershipGuard
{
    /** Swap types a customer may start from the app, USSD or a QR payment. */
    public const CUSTOMER_SWAP_TYPES = ['STANDARD', 'DEPOSIT', 'CASHOUT', 'IDENTITY', 'MULTI_SOURCE', 'MULTI_DESTINATION'];

    /**
     * Swap types that move no customer money out of a source (claims, ATM
     * confirmations, card issuance against an existing hold). They are
     * system and agent steps; the customer endpoints refuse them.
     */
    public const SOURCELESS_SWAP_TYPES = ['CONFIRM_IDENTITY', 'VERIFY_CASHOUT', 'CONFIRM_CASHOUT', 'CARD_ISSUE'];

    private const FAMILIES = [
        'ACCOUNT' => ['ACCOUNT', 'BANKACCOUNT', 'SAVINGSACCOUNT', 'CURRENTACCOUNT', 'BANK'],
        'WALLET'  => ['WALLET', 'MNOWALLET', 'BANKWALLET', 'MOBILEWALLET', 'EWALLET'],
        'CARD'    => ['CARD', 'VISAMASTERCARDCARD', 'DEBITCARD', 'CREDITCARD', 'PAYMENTCARD'],
        'VOUCHER' => ['VOUCHER', 'CASHOUTVOUCHER', 'GIFTVOUCHER'],
    ];

    // Where SwapService finds a swap's single source: extractSourceInstitution()
    // and extractSourceIdentifier(), same keys in the same order, so what is
    // checked here is what would be held.
    private const INSTITUTION_KEYS = ['from_institution', 'source_institution'];
    private const IDENTIFIER_KEYS = [
        'source_identifier', 'source_account', 'source_phone', 'source_wallet_phone', 'wallet_phone',
        'phone', 'source_national_id', 'national_id', 'source_email', 'email',
    ];
    // ...and where PoolCoordinator finds the identifier of each sources[] entry.
    private const POOL_IDENTIFIER_KEYS = ['source_identifier', 'identifier', 'account_id'];
    private const PIN_KEYS = ['voucher_pin', 'wallet_pin', 'pin'];

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(
        private readonly PDO $db,
        private readonly string $dialCode = '+267',
        private readonly ?int $localLength = 8
    ) {
    }

    /**
     * A guard using the phone rules sign-in uses (login.php): the country's
     * dial code and local number length from country_settings, or the
     * country registry's dial code, or Botswana's.
     */
    public static function forCountry(PDO $db, array $countryConfig = []): self
    {
        [$dialCode, $localLength] = self::phoneRules($countryConfig);
        return new self($db, $dialCode, $localLength);
    }

    /** @return array{0: string, 1: int} */
    public static function phoneRules(array $countryConfig = []): array
    {
        $keys = array_filter([
            $countryConfig['country_code'] ?? null,
            $countryConfig['country'] ?? null,
            getenv('VOUCHMORPH_COUNTRY') ?: null,
        ], 'is_string');

        $settings = [];
        foreach ($keys as $key) {
            if (is_array($countryConfig['country_settings'][$key] ?? null)) {
                $settings = $countryConfig['country_settings'][$key];
                break;
            }
        }

        $dialCode = $settings['dial_code'] ?? $countryConfig['dial_code'] ?? self::registryDialCode($keys) ?? '+267';
        $localLength = (int)($settings['local_phone_length'] ?? $countryConfig['local_phone_length'] ?? 8);

        return [(string)$dialCode, $localLength > 0 ? $localLength : 8];
    }

    /** @param list<string> $keys country names or codes */
    private static function registryDialCode(array $keys): ?string
    {
        static $countries = null;
        if ($countries === null) {
            $registry = @file_get_contents(__DIR__ . '/../../Core/Config/countries_registry.json');
            $countries = is_string($registry) ? (json_decode($registry, true)['countries'] ?? []) : [];
        }
        foreach ($keys as $key) {
            foreach ($countries as $name => $country) {
                if (strcasecmp((string)$name, $key) === 0 || strcasecmp((string)($country['code'] ?? ''), $key) === 0) {
                    return $country['dial_code'] ?? null;
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // The checks
    // ------------------------------------------------------------------

    /**
     * The verified source behind a request, or a refusal the customer can
     * act on.
     *
     * @param array $source institution, identifier, asset_type, and for a
     *                      voucher its pin / voucher_pin / wallet_pin
     * @return array{institution: string, identifier: string, asset_type: ?string, proof: string}
     *
     * @throws SourceOwnershipException
     */
    public function assertOwned(int $userId, array $source): array
    {
        $institution = trim((string)($source['institution'] ?? ''));
        $identifier = trim((string)($source['identifier'] ?? ''));
        $assetType = trim((string)($source['asset_type'] ?? '')) ?: null;

        if ($userId <= 0) {
            throw new SourceOwnershipException('We could not tell who is signed in. Sign in again and retry.');
        }
        if ($institution === '') {
            throw new SourceOwnershipException('Choose the institution this source is at.');
        }
        if ($identifier === '') {
            throw new SourceOwnershipException("Choose one of your verified sources at {$institution}.");
        }

        if (self::family($assetType) === 'VOUCHER') {
            self::assertIdentifierFormat('VOUCHER', $identifier, $this->dialCode, $this->localLength);
            if (self::firstFilled($source, self::PIN_KEYS) === null) {
                throw new SourceOwnershipException('Enter the voucher PIN. A voucher can only be spent by whoever holds both its number and its PIN.');
            }
            return ['institution' => $institution, 'identifier' => $identifier, 'asset_type' => $assetType, 'proof' => 'voucher_pin'];
        }

        $owned = $this->findOwnedSource($userId, $institution, $identifier, $assetType);
        if ($owned !== null) {
            return $owned;
        }

        error_log(sprintf(
            '[SECURITY] Source ownership refused: user %d asked to use %s %s (%s), which is not one of their verified sources',
            $userId, $institution, self::mask($identifier), $assetType ?? 'no asset type'
        ));
        throw new SourceOwnershipException($this->refusalReason($userId, $institution, $identifier, $assetType));
    }

    /**
     * The user's own verified source matching this institution and
     * identifier, or null. Never accepts a pending, rejected or deleted one.
     *
     * @return array{institution: string, identifier: string, asset_type: ?string, proof: string}|null
     */
    public function findOwnedSource(int $userId, string $institution, string $identifier, ?string $assetType = null): ?array
    {
        if ($userId <= 0 || trim($institution) === '' || trim($identifier) === '') {
            return null;
        }

        foreach ($this->linkedSources($userId, true) as $row) {
            if ($this->sameSource($row, $institution, $identifier, $assetType)) {
                return [
                    'institution' => $row['institution'],
                    'identifier' => $row['identifier'],
                    'asset_type' => $row['asset_type'] ?? $assetType,
                    'proof' => $row['proof'],
                ];
            }
        }

        if (self::family($assetType) === 'WALLET' && $this->isOwnVerifiedPhone($userId, $identifier)) {
            return ['institution' => trim($institution), 'identifier' => trim($identifier), 'asset_type' => $assetType, 'proof' => 'own_phone'];
        }

        return null;
    }

    /**
     * Checks every source a customer swap payload would debit and pins each
     * to the identifier that was verified, so SwapService and PoolCoordinator
     * hold exactly what was checked. A payload with no source is refused:
     * every customer swap pays from somewhere.
     *
     * @throws SourceOwnershipException
     */
    public function securePayload(int $userId, array $payload): array
    {
        $checked = 0;

        $single = self::singleSource($payload);
        if ($single['institution'] !== null) {
            $owned = $this->assertOwned($userId, $single);
            $payload['source_identifier'] = $owned['identifier'];
            $checked++;
        }

        if (array_key_exists('sources', $payload) && $payload['sources'] !== null) {
            if (!is_array($payload['sources'])) {
                throw new SourceOwnershipException('The sources for this swap were not sent as a list.');
            }
            foreach ($payload['sources'] as $index => $entry) {
                if (!is_array($entry)) {
                    throw new SourceOwnershipException('Source ' . ((int)$index + 1) . ' of this swap is not a valid source.');
                }
                $owned = $this->assertOwned($userId, [
                    'institution' => $entry['institution'] ?? null,
                    'identifier' => self::firstFilled($entry, self::POOL_IDENTIFIER_KEYS),
                    'asset_type' => $entry['asset_type'] ?? null,
                ] + self::pinsOf($entry));
                $payload['sources'][$index]['identifier'] = $owned['identifier'];
                $payload['sources'][$index]['source_identifier'] = $owned['identifier'];
                $checked++;
            }
        }

        if ($checked === 0) {
            throw new SourceOwnershipException('Choose one of your verified sources to pay from.');
        }

        return $payload;
    }

    /**
     * The one verified user whose own phone is this number, or null when no
     * user, or more than one, has it. For channels where the phone number
     * is the caller's identity (USSD); ambiguity is never resolved by
     * guessing who is paying.
     */
    public function userIdForPhone(string $msisdn): ?int
    {
        $variants = IdentifierNormalizer::phoneVariants($msisdn, $this->dialCode, $this->localLength);
        if ($variants === [] || !in_array('phone', $this->columnsOf('users'), true)) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($variants), '?'));
        $stmt = $this->db->prepare("SELECT user_id, phone, verified FROM users WHERE phone IN ({$placeholders})");
        $stmt->execute($variants);

        $matches = array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            fn (array $row) => self::truthy($row['verified'] ?? null) && $this->sameIdentifier((string)$row['phone'], $msisdn, true)
        ));
        if (count($matches) !== 1) {
            if (count($matches) > 1) {
                error_log('[SECURITY] Phone ' . self::mask($msisdn) . ' is the verified phone of ' . count($matches) . ' users - refusing to pick one');
            }
            return null;
        }

        return (int)$matches[0]['user_id'];
    }

    // ------------------------------------------------------------------
    // Formats
    // ------------------------------------------------------------------

    /**
     * Refuses an identifier that cannot be an account of this kind at all,
     * before anyone is asked to verify it: account numbers are 8 to 16
     * letters and digits (assets.yaml), wallets are phone numbers, cards
     * are card numbers that pass the Luhn check.
     *
     * @throws SourceOwnershipException
     */
    public static function assertIdentifierFormat(string $assetType, string $identifier, string $dialCode = '+267', ?int $localLength = 8): void
    {
        $compact = self::compact($identifier);

        switch (self::family($assetType)) {
            case 'WALLET':
                $national = IdentifierNormalizer::looksLikePhone($identifier)
                    ? IdentifierNormalizer::nationalPhonePart($identifier, $dialCode, $localLength)
                    : '';
                if (!preg_match('/^\d{7,12}$/', $national)) {
                    throw new SourceOwnershipException("That doesn't look like a mobile number. Enter the phone number the wallet is registered to.");
                }
                return;

            case 'CARD':
                if (!preg_match('/^\d{13,19}$/', $compact) || !self::passesLuhn($compact)) {
                    throw new SourceOwnershipException("That isn't a valid card number. Check the long number on the front of the card.");
                }
                return;

            case 'VOUCHER':
                if (!preg_match('/^[A-Z0-9]{6,24}$/', $compact)) {
                    throw new SourceOwnershipException("That isn't a valid voucher number. Enter it exactly as printed on the voucher.");
                }
                return;

            default:
                if (!preg_match('/^[A-Z0-9]{8,16}$/', $compact) || preg_match_all('/\d/', $compact) < 6) {
                    throw new SourceOwnershipException("That doesn't look like an account number. Enter the account number as it appears on your statement.");
                }
        }
    }

    public static function family(?string $assetType): ?string
    {
        $key = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$assetType) ?? '');
        foreach (self::FAMILIES as $family => $aliases) {
            if ($key !== '' && in_array($key, $aliases, true)) {
                return $family;
            }
        }
        return null;
    }

    /** An identifier with spaces, dashes, dots and slashes removed, upper-cased. */
    public static function compact(string $identifier): string
    {
        return strtoupper(preg_replace('/[\s\-.\/]+/', '', trim($identifier)) ?? '');
    }

    /** The last four characters only, for messages and logs. */
    public static function mask(string $identifier): string
    {
        $compact = self::compact($identifier);
        return strlen($compact) <= 4 ? '****' : '****' . substr($compact, -4);
    }

    /**
     * The payload with every key that starts with "_" removed, at any depth.
     * Those keys are SwapService's own internal markers and overrides
     * (_is_hooked skips the PIN, confirmCashout() honours _amount,
     * _hold_reference and _user_id, debitSource() reads _agent_float_debit);
     * none of them may come from a request body.
     */
    public static function withoutInternalKeys(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                unset($payload[$key]);
            } elseif (is_array($value)) {
                $payload[$key] = self::withoutInternalKeys($value);
            }
        }
        return $payload;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The user's linked sources from both tables, as one shape.
     *
     * @return list<array{institution: string, identifier: string, asset_type: ?string, identifier_type: ?string, status: string, proof: string}>
     */
    private function linkedSources(int $userId, bool $activeOnly): array
    {
        $rows = [];

        $cols = $this->columnsOf('user_source_accounts');
        if (array_diff(['user_id', 'institution', 'identifier', 'status'], $cols) === []) {
            $stmt = $this->db->prepare(
                'SELECT * FROM user_source_accounts WHERE user_id = :uid'
                . (in_array('deleted_at', $cols, true) ? ' AND deleted_at IS NULL' : '')
                . ($activeOnly ? " AND status = 'active'" : '')
            );
            $stmt->execute([':uid' => $userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = [
                    'institution' => (string)$row['institution'],
                    'identifier' => (string)$row['identifier'],
                    'asset_type' => $row['asset_type'] ?? null,
                    'identifier_type' => $row['identifier_type'] ?? null,
                    'status' => (string)$row['status'],
                    'proof' => 'verified_source',
                ];
            }
        }

        // OAuth-linked sources. source_accounts also holds enterprise sources,
        // which have an organization_id and no user; and the identifier column
        // has gone by more than one name, so read whichever is there.
        $cols = $this->columnsOf('source_accounts');
        $identifierColumn = array_values(array_intersect(['identifier', 'source_identifier', 'account_number'], $cols))[0] ?? null;
        if ($identifierColumn !== null && array_diff(['user_id', 'institution', 'status'], $cols) === []) {
            $stmt = $this->db->prepare(
                'SELECT * FROM source_accounts WHERE user_id = :uid'
                . (in_array('deleted_at', $cols, true) ? ' AND deleted_at IS NULL' : '')
                . ($activeOnly ? " AND status = 'active'" : '')
            );
            $stmt->execute([':uid' => $userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($activeOnly && array_key_exists('is_active', $row) && !self::truthy($row['is_active'])) {
                    continue;
                }
                $identifier = trim((string)($row[$identifierColumn] ?? ''));
                if ($identifier === '') {
                    continue;
                }
                $rows[] = [
                    'institution' => (string)$row['institution'],
                    'identifier' => $identifier,
                    'asset_type' => $row['asset_type'] ?? null,
                    'identifier_type' => $row['identifier_type'] ?? $row['source_identifier_type'] ?? null,
                    'status' => (string)$row['status'],
                    'proof' => 'linked_bank_login',
                ];
            }
        }

        return $rows;
    }

    private function sameSource(array $row, string $institution, string $identifier, ?string $assetType): bool
    {
        if (strcasecmp(trim($row['institution']), trim($institution)) !== 0) {
            return false;
        }

        // A number registered as an account is not also a card or a wallet.
        // An unknown type on either side is not held against the match.
        $rowFamily = self::family($row['asset_type'] ?? null);
        $askedFamily = self::family($assetType);
        if ($rowFamily !== null && $askedFamily !== null && $rowFamily !== $askedFamily) {
            return false;
        }

        $phoneLike = $rowFamily === 'WALLET' || $askedFamily === 'WALLET'
            || in_array(strtolower((string)($row['identifier_type'] ?? '')), ['phone', 'msisdn', 'mobile'], true);

        return $this->sameIdentifier($row['identifier'], $identifier, $phoneLike);
    }

    private function sameIdentifier(string $stored, string $asked, bool $phoneLike): bool
    {
        if ($phoneLike && IdentifierNormalizer::looksLikePhone($stored) && IdentifierNormalizer::looksLikePhone($asked)) {
            $storedPhone = IdentifierNormalizer::canonicalPhone($stored, $this->dialCode, $this->localLength);
            return $storedPhone !== ''
                && $storedPhone === IdentifierNormalizer::canonicalPhone($asked, $this->dialCode, $this->localLength);
        }

        $storedCompact = self::compact($stored);
        return $storedCompact !== '' && $storedCompact === self::compact($asked);
    }

    private function isOwnVerifiedPhone(int $userId, string $identifier): bool
    {
        if (!IdentifierNormalizer::looksLikePhone($identifier) || !in_array('phone', $this->columnsOf('users'), true)) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT phone FROM users WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $phone = $stmt->fetchColumn();

        return is_string($phone) && trim($phone) !== '' && $this->sameIdentifier($phone, $identifier, true);
    }

    private function refusalReason(int $userId, string $institution, string $identifier, ?string $assetType): string
    {
        $what = trim($institution) . ' ' . strtolower(self::family($assetType) ?? 'account') . ' ending ' . substr(self::compact($identifier), -4);

        foreach ($this->linkedSources($userId, false) as $row) {
            if (!$this->sameSource($row, $institution, $identifier, $assetType)) {
                continue;
            }
            $status = strtolower($row['status']);
            if (in_array($status, ['pending_confirmation', 'pending', 'proposed'], true)) {
                return "Your {$what} is still waiting for its ownership check. You can use it as soon as it is approved.";
            }
            if (in_array($status, ['rejected', 'failed', 'revoked', 'suspended'], true)) {
                return "Your {$what} did not pass its ownership check, so it can't be used. Contact support if this account is yours.";
            }
        }

        return "{$what} is not one of your verified sources, so it can't be used. Only accounts you have proved are yours can pay: "
            . 'add it under My Sources and confirm it with the code your institution sends you, then try again.';
    }

    /** Column names of a table, lower-cased; empty when the table isn't there. */
    private function columnsOf(string $table): array
    {
        if (!isset($this->columns[$table])) {
            $names = [];
            try {
                if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    // $table is always one of this class's own literals, never input.
                    $names = array_column($this->db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
                } else {
                    $stmt = $this->db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?');
                    $stmt->execute([$table]);
                    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
            } catch (Throwable $e) {
                error_log("[SourceOwnershipGuard] Could not read the columns of {$table}: " . $e->getMessage());
            }
            $this->columns[$table] = array_map('strtolower', array_map('strval', $names));
        }
        return $this->columns[$table];
    }

    /** @return array{institution: ?string, identifier: ?string, asset_type: mixed} */
    private static function singleSource(array $payload): array
    {
        $nested = static fn (string $key): array => is_array($payload[$key] ?? null) ? $payload[$key] : [];

        return [
            'institution' => self::firstFilled($payload, self::INSTITUTION_KEYS)
                ?? self::firstFilled($nested('source'), ['institution'])
                ?? self::firstFilled($nested('source_details'), ['institution'])
                ?? self::firstFilled($nested('participant'), ['source'])
                ?? self::firstFilled($payload, ['bank']),
            'identifier' => self::firstFilled($payload, self::IDENTIFIER_KEYS)
                ?? self::firstFilled($nested('source'), ['identifier', 'account']),
            'asset_type' => $payload['asset_type'] ?? null,
        ] + self::pinsOf($payload) + self::pinsOf($nested('asset_fields'));
    }

    /** @return array<string, string> */
    private static function pinsOf(array $bag): array
    {
        $pins = [];
        foreach (self::PIN_KEYS as $key) {
            $pin = self::firstFilled($bag, [$key]);
            if ($pin !== null) {
                $pins[$key] = $pin;
            }
        }
        return $pins;
    }

    /** The first of these keys holding a non-blank scalar, as a trimmed string. */
    private static function firstFilled(array $bag, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $bag[$key] ?? null;
            if ((is_string($value) || is_int($value) || is_float($value)) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        return null;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes'], true);
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int)$digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }
}
