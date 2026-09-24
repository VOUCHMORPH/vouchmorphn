<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\ReservationAccountService;
use Domain\Services\SwapService;
use Infrastructure\Adapters\InstitutionAdapterFactory;
use Infrastructure\Credentials\CredentialsRepository;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Who can open an identity's claim pool (SwapService::prepareIdentityClaimPool(),
 * behind both the app's claim button and the agent portal), and which lockout
 * a wrong PIN counts against. Also who can confirm a single hold
 * (confirmAndFinalizeIdentitySwap()), the path swap/execute.php used to
 * expose to any logged-in user (see SwapExecuteClaimRefusalTest).
 *
 * Money sent to a registered, verified owner's identity is held with
 * claim_type 'account_pin' and a one-time SMS code: the owner claims it in the
 * app with their transaction PIN, and an agent finalizes it for them with the
 * code. Money sent to an unregistered recipient ('otp_pin') is claimed with
 * the code. The owner used to be able to do neither in the app: the
 * transaction PIN ended in "Incorrect claim PIN.", and the SMS code was checked
 * as a transaction PIN, failing and counting towards that PIN's lockout. A
 * reservation account's claim PIN, which also opens the pool, used to have no
 * attempt limit at all.
 *
 * Runs against a real PostgreSQL server: the pool is loaded with SELECT ...
 * FOR UPDATE, which SQLite can't parse. Same throwaway-database pattern and
 * variable as TransactionPinMigrationTest, and like it, deliberately no
 * fallback to DATABASE_URL, since it creates and drops schemas:
 *
 *     CREDENTIALS_MIGRATION_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/IdentityClaimPinTest.php
 *
 * Two throwaway schemas stand in for the main and the credentials database,
 * each behind its own connection, and are dropped again in
 * tearDownAfterClass. SwapService is built without running its constructor
 * and wired up by reflection (as in TransactionPinCredentialsTest), with the
 * real CredentialsRepository and ReservationAccountService. Only the bank is
 * stubbed, by the subclass that skips the constructor: it confirms a
 * reservation balance only when a test sets one, and rolling that balance
 * into a pool just adds a pending hold for it.
 */
class IdentityClaimPinTest extends TestCase
{
    private const MAIN_SCHEMA = 'identity_claim_pin_main';
    private const CRED_SCHEMA = 'identity_claim_pin_cred';

    private const OWNER_ID = 42;
    private const OTHER_USER_ID = 7;
    private const AGENT_ID = 900;
    private const TRANSACTION_PIN = '482913';

    // Registered to OWNER_ID and verified.
    private const OWNED = ['national_id', '123456789'];
    // Registered to nobody.
    private const UNREGISTERED = ['phone', '+26771234567'];

    private static ?PDO $main = null;
    private static ?PDO $cred = null;

    private SwapService $service;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('CREDENTIALS_MIGRATION_TEST_DATABASE_URL');
        if (!$url) {
            return;
        }
        self::$main = self::connect($url);
        self::$cred = self::connect($url);
        foreach ([self::MAIN_SCHEMA, self::CRED_SCHEMA] as $schema) {
            self::$main->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
            self::$main->exec("CREATE SCHEMA {$schema}");
        }
        self::$main->exec('SET search_path TO ' . self::MAIN_SCHEMA);
        self::$cred->exec('SET search_path TO ' . self::CRED_SCHEMA);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$main !== null) {
            foreach ([self::MAIN_SCHEMA, self::CRED_SCHEMA] as $schema) {
                self::$main->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
            }
            self::$main = null;
            self::$cred = null;
        }
    }

    private static function connect(string $url): PDO
    {
        $parts = parse_url($url);
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $parts['host'] ?? 'localhost',
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '', '/')
        );
        // Same options as DBConnection / CredentialsDBConnection.
        return new PDO($dsn, $parts['user'] ?? 'postgres', $parts['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    protected function setUp(): void
    {
        if (self::$main === null) {
            $this->markTestSkipped('Set CREDENTIALS_MIGRATION_TEST_DATABASE_URL to a throwaway PostgreSQL database to run this.');
        }

        // identity_swap_holds and the identity columns of reservation_accounts
        // have no tracked CREATE TABLE in this repository (see
        // database/migrations/2026_09_16_source_account_type.sql), so these are
        // the columns the claim code reads and writes.
        self::$main->exec('DROP TABLE IF EXISTS user_identities, identity_swap_holds, reservation_accounts, agent_destination_accounts');
        // What makes AGENT_ID an approved agent (SwapService::isApprovedAgent()).
        self::$main->exec("
            CREATE TABLE agent_destination_accounts (
                id          BIGSERIAL PRIMARY KEY,
                user_id     BIGINT NOT NULL,
                status      VARCHAR(20) NOT NULL,
                deleted_at  TIMESTAMPTZ
            )
        ");
        self::$main->exec("INSERT INTO agent_destination_accounts (user_id, status) VALUES (" . self::AGENT_ID . ", 'active')");
        self::$main->exec("
            CREATE TABLE user_identities (
                id              BIGSERIAL PRIMARY KEY,
                user_id         BIGINT NOT NULL,
                identity_type   VARCHAR(30) NOT NULL,
                identity_value  VARCHAR(100) NOT NULL,
                status          VARCHAR(20) NOT NULL
            )
        ");
        self::$main->exec("
            CREATE TABLE identity_swap_holds (
                hold_id               BIGSERIAL PRIMARY KEY,
                swap_reference        VARCHAR(100) NOT NULL,
                identity_type         VARCHAR(30) NOT NULL,
                identity_value        VARCHAR(100) NOT NULL,
                amount                NUMERIC(18,2) NOT NULL,
                currency              CHAR(3) NOT NULL DEFAULT 'BWP',
                status                VARCHAR(30) NOT NULL DEFAULT 'pending',
                hold_expires_at       TIMESTAMP NOT NULL,
                claim_reference       VARCHAR(100),
                claim_type            VARCHAR(30),
                otp_pin_hash          VARCHAR(255),
                otp_pin_sent_to       VARCHAR(100),
                otp_pin_attempts      INTEGER DEFAULT 0,
                otp_pin_locked_until  TIMESTAMP,
                otp_pin_verified_at   TIMESTAMP,
                authorized_at         TIMESTAMP,
                authorized_by         VARCHAR(50),
                authorization_type    VARCHAR(50),
                source_payload        JSONB,
                created_at            TIMESTAMP DEFAULT clock_timestamp()
            )
        ");
        self::$main->exec("
            CREATE TABLE reservation_accounts (
                id                       BIGSERIAL PRIMARY KEY,
                user_id                  BIGINT,
                identity_type            VARCHAR(30),
                identity_value           VARCHAR(100),
                institution              VARCHAR(100) NOT NULL,
                currency                 CHAR(3) NOT NULL,
                account_identifier       VARCHAR(100),
                account_identifier_type  VARCHAR(30) DEFAULT 'account_number',
                status                   VARCHAR(20) NOT NULL,
                claim_pin_hash           VARCHAR(255),
                last_rolled_at           TIMESTAMPTZ,
                updated_at               TIMESTAMPTZ DEFAULT now()
            )
        ");
        // The claim PIN's attempt limit, from the real migration.
        self::$main->exec(file_get_contents(__DIR__ . '/../../database/migrations/2026_09_24_reservation_account_claim_pin_lockout.sql'));
        self::$cred->exec('DROP TABLE IF EXISTS user_credentials, admin_credentials, user_transaction_pins');
        self::$cred->exec(file_get_contents(__DIR__ . '/../../scripts/credentials_db/schema.sql'));

        self::$main->prepare("
            INSERT INTO user_identities (user_id, identity_type, identity_value, status)
            VALUES (:uid, :type, :value, 'verified')
        ")->execute([':uid' => self::OWNER_ID, ':type' => self::OWNED[0], ':value' => self::OWNED[1]]);
        $credentials = new CredentialsRepository(self::$cred);
        $credentials->setUserTransactionPin(self::OWNER_ID, self::hash(self::TRANSACTION_PIN));

        $this->service = new class(self::$main) extends SwapService {
            // What the bank holds in each reservation account: nothing unless
            // a test says so, so by default no balance is rolled into a pool.
            public float $reservationBalance = 0.0;

            public function __construct(private PDO $testDb)
            {
            }

            public function verifyAssetSigned(array $payload, string $institution): array
            {
                return ['verified' => $this->reservationBalance > 0, 'balance' => $this->reservationBalance];
            }

            // Rolling a reservation balance into the pool: a pending hold for it.
            public function initiateSwapToIdentity(array $payload): array
            {
                $reference = 'RESROLL_' . bin2hex(random_bytes(6));
                $this->testDb->prepare("
                    INSERT INTO identity_swap_holds
                        (swap_reference, identity_type, identity_value, amount, hold_expires_at, source_payload)
                    VALUES (:ref, :type, :value, :amount, NOW() + INTERVAL '1 day', '{}'::jsonb)
                ")->execute([
                    ':ref' => $reference,
                    ':type' => $payload['identity_type'],
                    ':value' => $payload['identity_value'],
                    ':amount' => $payload['amount'],
                ]);
                return ['swap_reference' => $reference];
            }
        };
        $adapters = (new ReflectionClass(InstitutionAdapterFactory::class))->newInstanceWithoutConstructor();
        $wiring = [
            'swapDB' => self::$main,
            'credentialsRepository' => $credentials,
            'reservationAccountService' => new ReservationAccountService(self::$main, [], $adapters),
        ];
        foreach ($wiring as $name => $value) {
            (new ReflectionProperty(SwapService::class, $name))->setValue($this->service, $value);
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * The cheapest bcrypt cost: password_verify() reads the cost from the
     * hash, so the checks under test are the same, only fast enough for a
     * test that makes dozens of them.
     */
    private static function hash(string $pin): string
    {
        return password_hash($pin, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    /** A pending swap to the identity; $code is its one-time SMS code. */
    private function addHold(array $identity, string $claimType, ?string $code): int
    {
        $stmt = self::$main->prepare("
            INSERT INTO identity_swap_holds
                (swap_reference, identity_type, identity_value, amount, hold_expires_at,
                 claim_type, otp_pin_hash, otp_pin_sent_to, source_payload)
            VALUES (:ref, :type, :value, 100, NOW() + INTERVAL '1 day',
                    :claim_type, :hash, :sent_to, '{}'::jsonb)
            RETURNING hold_id
        ");
        $stmt->execute([
            ':ref' => 'SWAP_' . bin2hex(random_bytes(6)),
            ':type' => $identity[0],
            ':value' => $identity[1],
            ':claim_type' => $claimType,
            ':hash' => $code !== null ? self::hash($code) : null,
            ':sent_to' => $code !== null ? '+26770000001' : null,
        ]);
        return (int)$stmt->fetchColumn();
    }

    /** An open reservation account of the identity; $code is the claim PIN it remembers. */
    private function addReservation(array $identity, ?string $code, string $institution = 'ZURUBANK'): int
    {
        $stmt = self::$main->prepare("
            INSERT INTO reservation_accounts
                (identity_type, identity_value, institution, currency, account_identifier, status, claim_pin_hash)
            VALUES (:type, :value, :institution, 'BWP', 'RES-0001', 'active', :hash)
            RETURNING id
        ");
        $stmt->execute([
            ':type' => $identity[0],
            ':value' => $identity[1],
            ':institution' => $institution,
            ':hash' => $code !== null ? self::hash($code) : null,
        ]);
        return (int)$stmt->fetchColumn();
    }

    /** As five misses leave it: locked for the next 30 minutes. */
    private static function lockReservationPins(): void
    {
        self::$main->exec("UPDATE reservation_accounts SET claim_pin_attempts = 5, claim_pin_locked_until = NOW() + INTERVAL '30 minutes'");
    }

    /** prepareIdentityClaimPool() as the app ('user') or the agent portal ('agent') calls it. */
    private function claim(array $identity, string $pin, string $context, int $claimerId): array
    {
        return (new ReflectionMethod(SwapService::class, 'prepareIdentityClaimPool'))
            ->invoke($this->service, $identity[0], $identity[1], $pin, $context, $claimerId, $context);
    }

    private function claimAsOwner(string $pin): array
    {
        return $this->claim(self::OWNED, $pin, 'user', self::OWNER_ID);
    }

    private function claimAsAgent(string $pin): array
    {
        return $this->claim(self::OWNED, $pin, 'agent', self::AGENT_ID);
    }

    /** The single-hold path, confirmAndFinalizeIdentitySwap(), for this hold. */
    private function confirmSingleHold(int $holdId, string $pin, string $confirmedByType, int $confirmedById): array
    {
        return $this->service->confirmAndFinalizeIdentitySwap([
            'swap_reference' => $this->hold($holdId)['swap_reference'],
            'pin' => $pin,
            'confirmed_by_type' => $confirmedByType,
            'confirmed_by_id' => $confirmedById,
            'identity_document_verified' => true,
            'destination_type' => 'DEPOSIT',
        ]);
    }

    private function afterClaim(array $result, string $pin, array $pool): array
    {
        return (new ReflectionMethod(SwapService::class, 'afterIdentityClaim'))
            ->invoke($this->service, $result, $pin, $pool);
    }

    private function assertRefused(callable $claim, string $expectedMessage): void
    {
        try {
            $claim();
            $this->fail("Expected the claim to be refused with: {$expectedMessage}");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }

    private function transactionPin(): array
    {
        $stmt = self::$cred->prepare('SELECT * FROM user_transaction_pins WHERE user_id = :id');
        $stmt->execute([':id' => self::OWNER_ID]);
        return $stmt->fetch();
    }

    private function hold(int $holdId): array
    {
        $stmt = self::$main->prepare('SELECT * FROM identity_swap_holds WHERE hold_id = :id');
        $stmt->execute([':id' => $holdId]);
        return $stmt->fetch();
    }

    private function reservation(int $id): array
    {
        $stmt = self::$main->prepare('SELECT * FROM reservation_accounts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    private static function holdIds(array $pool): array
    {
        return array_map('intval', array_column($pool['holds'], 'hold_id'));
    }

    // ------------------------------------------------------------
    // The registered owner, claiming in the app
    // ------------------------------------------------------------

    public function testTheOwnersTransactionPinOpensThePool(): void
    {
        $first = $this->addHold(self::OWNED, 'account_pin', '111111');
        $second = $this->addHold(self::OWNED, 'account_pin', '222222');
        (new CredentialsRepository(self::$cred))->recordFailedUserPinAttempt(self::OWNER_ID);   // an earlier typo

        $pool = $this->claimAsOwner(self::TRANSACTION_PIN);

        $this->assertSame('transaction_pin', $pool['authenticated_by']);
        $this->assertSame([$first, $second], self::holdIds($pool));
        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
        foreach ([$first, $second] as $holdId) {
            $hold = $this->hold($holdId);
            $this->assertNotNull($hold['authorized_at']);
            // The agent's one-time codes are left as they were.
            $this->assertNotNull($hold['otp_pin_hash']);
            $this->assertSame(0, (int)$hold['otp_pin_attempts']);
        }
    }

    public function testTheSmsCodeIsForAgentsAndNeverCountsAgainstTheTransactionPin(): void
    {
        $holdId = $this->addHold(self::OWNED, 'account_pin', '111111');

        // More than the five misses that would lock the transaction PIN.
        for ($i = 0; $i < 6; $i++) {
            $this->assertRefused(fn() => $this->claimAsOwner('111111'), 'That code is for finalizing this claim through an agent.');
        }

        $pin = $this->transactionPin();
        $this->assertSame(0, (int)$pin['failed_attempts']);
        $this->assertNull($pin['locked_until']);
        $hold = $this->hold($holdId);
        $this->assertNull($hold['authorized_at']);
        $this->assertSame(0, (int)$hold['otp_pin_attempts']);

        // The code still works for the agent, and the PIN for the owner.
        $this->assertSame('claim_otp', $this->claimAsAgent('111111')['authenticated_by']);
        $this->assertSame('transaction_pin', $this->claimAsOwner(self::TRANSACTION_PIN)['authenticated_by']);
    }

    public function testAWrongPinCountsOnceAgainstTheTransactionPinNotOncePerHold(): void
    {
        $holds = [
            $this->addHold(self::OWNED, 'account_pin', '111111'),
            $this->addHold(self::OWNED, 'account_pin', '222222'),
            $this->addHold(self::OWNED, 'account_pin', '333333'),
        ];

        $this->assertRefused(fn() => $this->claimAsOwner('000000'), 'Incorrect transaction PIN.');

        $this->assertSame(1, (int)$this->transactionPin()['failed_attempts']);
        foreach ($holds as $holdId) {
            $this->assertSame(0, (int)$this->hold($holdId)['otp_pin_attempts']);
            $this->assertNull($this->hold($holdId)['authorized_at']);
        }
    }

    public function testFiveWrongPinsLockTheOwnerOutOfTheWholeClaim(): void
    {
        $this->addHold(self::OWNED, 'account_pin', '111111');
        // Sent before the identity was registered to them: claimable with its code.
        $this->addHold(self::OWNED, 'otp_pin', '333333');

        for ($i = 1; $i <= 5; $i++) {
            $this->assertRefused(fn() => $this->claimAsOwner('000000'), 'Incorrect transaction PIN.');
            $this->assertSame($i, (int)$this->transactionPin()['failed_attempts']);
        }
        $lockedFor = strtotime($this->transactionPin()['locked_until']) - time();
        $this->assertGreaterThan(29 * 60, $lockedFor);
        $this->assertLessThanOrEqual(30 * 60, $lockedFor);

        // While locked nothing opens the pool: not the right PIN, and not the
        // one-time code either, whose lockout the owner's misses don't reach.
        foreach ([self::TRANSACTION_PIN, '333333', '111111', '000000'] as $pin) {
            $this->assertRefused(fn() => $this->claimAsOwner($pin), 'Too many incorrect attempts on the transaction PIN');
        }
        $this->assertSame(5, (int)$this->transactionPin()['failed_attempts']);

        // Once the lock has run out, the right PIN works and resets the count.
        self::$cred->exec("UPDATE user_transaction_pins SET locked_until = NOW() - INTERVAL '1 minute'");
        $this->assertSame('transaction_pin', $this->claimAsOwner(self::TRANSACTION_PIN)['authenticated_by']);
        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
    }

    public function testTheOwnerCanStillUseTheCodeOfMoneySentBeforeTheyRegistered(): void
    {
        $holdId = $this->addHold(self::OWNED, 'otp_pin', '333333');

        $pool = $this->claimAsOwner('333333');

        $this->assertSame('claim_otp', $pool['authenticated_by']);
        $this->assertSame([$holdId], self::holdIds($pool));
        $this->assertNull($this->hold($holdId)['otp_pin_hash']);   // single-use
        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
    }

    public function testAnOwnerWithoutATransactionPinIsToldToSetOne(): void
    {
        self::$cred->exec('DELETE FROM user_transaction_pins');
        $holdId = $this->addHold(self::OWNED, 'account_pin', '111111');

        $this->assertRefused(fn() => $this->claimAsOwner(self::TRANSACTION_PIN), 'No transaction PIN has been set on this account yet.');
        // With no PIN to count against, the miss counts against the code.
        $this->assertSame(1, (int)$this->hold($holdId)['otp_pin_attempts']);

        $this->assertRefused(fn() => $this->claimAsOwner('111111'), 'That code is for finalizing this claim through an agent.');
    }

    // ------------------------------------------------------------
    // Anyone else, claiming in the app
    // ------------------------------------------------------------

    public function testSomeoneElseCanNeitherUseNorLockTheOwnersTransactionPin(): void
    {
        $holdId = $this->addHold(self::OWNED, 'account_pin', '111111');
        $asSomeoneElse = fn(string $pin) => $this->claim(self::OWNED, $pin, 'user', self::OTHER_USER_ID);

        $this->assertRefused(fn() => $asSomeoneElse(self::TRANSACTION_PIN), 'Incorrect claim PIN.');
        $this->assertRefused(fn() => $asSomeoneElse('111111'), 'That code is for finalizing this claim through an agent.');

        // Their misses count against the code, never the owner's PIN.
        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
        $this->assertSame(1, (int)$this->hold($holdId)['otp_pin_attempts']);

        for ($i = 2; $i <= 5; $i++) {
            $this->assertRefused(fn() => $asSomeoneElse('000000'), 'Incorrect claim PIN.');
        }
        // Once the code is locked, it no longer tells them which code is right.
        $this->assertRefused(fn() => $asSomeoneElse('111111'), 'Too many incorrect attempts on the claim PIN');

        // None of it stands in the owner's way.
        $this->assertSame('transaction_pin', $this->claimAsOwner(self::TRANSACTION_PIN)['authenticated_by']);
    }

    // ------------------------------------------------------------
    // An unregistered recipient (claim_type 'otp_pin')
    // ------------------------------------------------------------

    public function testAnUnregisteredRecipientClaimsWithTheSmsCode(): void
    {
        $holdId = $this->addHold(self::UNREGISTERED, 'otp_pin', '333333');

        $pool = $this->claim(self::UNREGISTERED, '333333', 'user', self::OTHER_USER_ID);

        $this->assertSame('claim_otp', $pool['authenticated_by']);
        $this->assertSame([$holdId], self::holdIds($pool));
        $hold = $this->hold($holdId);
        $this->assertNull($hold['otp_pin_hash']);   // single-use
        $this->assertNotNull($hold['authorized_at']);
    }

    public function testAnUnregisteredRecipientsWrongCodesLockTheCode(): void
    {
        $holdId = $this->addHold(self::UNREGISTERED, 'otp_pin', '333333');
        $claim = fn(string $pin) => $this->claim(self::UNREGISTERED, $pin, 'user', self::OTHER_USER_ID);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertRefused(fn() => $claim('000000'), 'Incorrect claim PIN.');
            $this->assertSame($i, (int)$this->hold($holdId)['otp_pin_attempts']);
        }

        $this->assertRefused(fn() => $claim('333333'), 'Too many incorrect attempts on the claim PIN');
        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
    }

    // ------------------------------------------------------------
    // An agent finalizing for the owner (pinContext 'agent')
    // ------------------------------------------------------------

    public function testAnAgentFinalizesWithTheCodeTheOwnerReadsOut(): void
    {
        $holdId = $this->addHold(self::OWNED, 'account_pin', '111111');

        $pool = $this->claimAsAgent('111111');

        $this->assertSame('claim_otp', $pool['authenticated_by']);
        $this->assertSame([$holdId], self::holdIds($pool));
        $hold = $this->hold($holdId);
        $this->assertNull($hold['otp_pin_hash']);   // single-use
        $this->assertNotNull($hold['authorized_at']);
        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
    }

    public function testAnAgentCannotUseTheTransactionPinAndTheirMissesLockOnlyTheCode(): void
    {
        $holdId = $this->addHold(self::OWNED, 'account_pin', '111111');

        $this->assertRefused(fn() => $this->claimAsAgent(self::TRANSACTION_PIN), 'Incorrect claim PIN.');
        for ($i = 2; $i <= 5; $i++) {
            $this->assertRefused(fn() => $this->claimAsAgent('000000'), 'Incorrect claim PIN.');
        }
        $this->assertSame(5, (int)$this->hold($holdId)['otp_pin_attempts']);
        $this->assertRefused(fn() => $this->claimAsAgent('111111'), 'Too many incorrect attempts on the claim PIN');

        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
    }

    // ------------------------------------------------------------
    // Reservation accounts
    // ------------------------------------------------------------

    public function testTheTransactionPinIsNeverRememberedOnAReservationAccount(): void
    {
        $this->addHold(self::OWNED, 'account_pin', '111111');
        $reservationId = $this->addReservation(self::OWNED, null);

        $pool = $this->claimAsOwner(self::TRANSACTION_PIN);
        $this->afterClaim(['reservation_account_id' => $reservationId], self::TRANSACTION_PIN, $pool);

        $this->assertNull($this->reservation($reservationId)['claim_pin_hash']);
    }

    public function testAClaimWithACodeStillLeavesThatCodeOnTheReservationAccount(): void
    {
        $this->addHold(self::OWNED, 'account_pin', '111111');
        $reservationId = $this->addReservation(self::OWNED, null);

        $pool = $this->claimAsAgent('111111');
        $this->afterClaim(['reservation_account_id' => $reservationId], '111111', $pool);

        $this->assertTrue(password_verify('111111', $this->reservation($reservationId)['claim_pin_hash']));
    }

    public function testAReservationsCodeStillOpensThePool(): void
    {
        $this->addHold(self::OWNED, 'account_pin', '111111');
        $this->addReservation(self::OWNED, '246810');

        $this->assertSame('reservation_pin', $this->claimAsAgent('246810')['authenticated_by']);
        $this->assertSame('reservation_pin', $this->claimAsOwner('246810')['authenticated_by']);
        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
    }

    // ------------------------------------------------------------
    // A reservation account's claim PIN: an attempt limit of its own
    // ------------------------------------------------------------

    public function testWrongPinsLockTheReservationPinOfAPoolWithOnlyReservationMoney(): void
    {
        // No pending swap, so no one-time code for a miss to count against.
        $reservationId = $this->addReservation(self::UNREGISTERED, '246810');
        $this->service->reservationBalance = 50.0;
        $claim = fn(string $pin) => $this->claim(self::UNREGISTERED, $pin, 'user', self::OTHER_USER_ID);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertRefused(fn() => $claim('000000'), 'Incorrect claim PIN.');
            $this->assertSame($i, (int)$this->reservation($reservationId)['claim_pin_attempts']);
        }
        $lockedFor = strtotime($this->reservation($reservationId)['claim_pin_locked_until']) - time();
        $this->assertGreaterThan(29 * 60, $lockedFor);
        $this->assertLessThanOrEqual(30 * 60, $lockedFor);

        // While locked the PIN isn't compared, so the right one gets the same
        // answer as a wrong one, and nothing more is counted.
        foreach (['246810', '000000'] as $pin) {
            $this->assertRefused(fn() => $claim($pin), 'Too many incorrect attempts on the claim PIN');
        }
        $this->assertSame(5, (int)$this->reservation($reservationId)['claim_pin_attempts']);

        // Once the lock has run out, every further miss locks it again...
        self::$main->exec("UPDATE reservation_accounts SET claim_pin_locked_until = NOW() - INTERVAL '1 minute'");
        $this->assertRefused(fn() => $claim('000000'), 'Incorrect claim PIN.');
        $this->assertRefused(fn() => $claim('246810'), 'Too many incorrect attempts on the claim PIN');

        // ...and the right PIN opens the pool and resets the count.
        self::$main->exec("UPDATE reservation_accounts SET claim_pin_locked_until = NOW() - INTERVAL '1 minute'");
        $pool = $claim('246810');
        $this->assertSame('reservation_pin', $pool['authenticated_by']);
        $this->assertCount(1, $pool['holds']);   // the reservation balance, rolled in
        $reservation = $this->reservation($reservationId);
        $this->assertSame(0, (int)$reservation['claim_pin_attempts']);
        $this->assertNull($reservation['claim_pin_locked_until']);
    }

    public function testAMissCountsOnceAgainstEveryCodeItWasComparedWith(): void
    {
        $holdId = $this->addHold(self::OWNED, 'account_pin', '111111');
        $first = $this->addReservation(self::OWNED, '246810');
        $second = $this->addReservation(self::OWNED, '135790', 'OTHERBANK');

        $this->assertRefused(fn() => $this->claimAsAgent('000000'), 'Incorrect claim PIN.');

        $this->assertSame(1, (int)$this->hold($holdId)['otp_pin_attempts']);
        $this->assertSame(1, (int)$this->reservation($first)['claim_pin_attempts']);
        $this->assertSame(1, (int)$this->reservation($second)['claim_pin_attempts']);
        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
    }

    public function testTheOwnersMissesStillCountOnlyAgainstTheirTransactionPin(): void
    {
        $holdId = $this->addHold(self::OWNED, 'account_pin', '111111');
        $reservationId = $this->addReservation(self::OWNED, '246810');

        $this->assertRefused(fn() => $this->claimAsOwner('000000'), 'Incorrect transaction PIN.');

        $this->assertSame(1, (int)$this->transactionPin()['failed_attempts']);
        $this->assertSame(0, (int)$this->reservation($reservationId)['claim_pin_attempts']);
        $this->assertSame(0, (int)$this->hold($holdId)['otp_pin_attempts']);

        // And while their transaction PIN is locked, the reservation PIN isn't tried either.
        self::$cred->exec("UPDATE user_transaction_pins SET failed_attempts = 5, locked_until = NOW() + INTERVAL '30 minutes'");
        $this->assertRefused(fn() => $this->claimAsOwner('246810'), 'Too many incorrect attempts on the transaction PIN');
    }

    public function testALockedReservationPinDoesNotStandInTheOwnersWay(): void
    {
        $this->addHold(self::OWNED, 'account_pin', '111111');
        $reservationId = $this->addReservation(self::OWNED, '246810');
        // Someone else's guessing, through an agent, locks it.
        for ($i = 0; $i < 5; $i++) {
            $this->assertRefused(fn() => $this->claimAsAgent('000000'), 'Incorrect claim PIN.');
        }
        $this->assertNotNull($this->reservation($reservationId)['claim_pin_locked_until']);

        // The owner is told it's locked, at no cost to their transaction PIN...
        $this->assertRefused(fn() => $this->claimAsOwner('246810'), 'Too many incorrect attempts on the claim PIN');
        $this->assertSame(0, (int)$this->transactionPin()['failed_attempts']);
        // ...and their transaction PIN opens the pool anyway.
        $this->assertSame('transaction_pin', $this->claimAsOwner(self::TRANSACTION_PIN)['authenticated_by']);
    }

    public function testTheSwapsOwnCodeStaysLimitedWhileAReservationPinIsLocked(): void
    {
        $reservationId = $this->addReservation(self::UNREGISTERED, '246810');
        self::lockReservationPins();
        // New money arrives, with a code of its own.
        $holdId = $this->addHold(self::UNREGISTERED, 'otp_pin', '333333');
        $claim = fn(string $pin) => $this->claim(self::UNREGISTERED, $pin, 'user', self::OTHER_USER_ID);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertRefused(fn() => $claim('000000'), 'Incorrect claim PIN.');
            $this->assertSame($i, (int)$this->hold($holdId)['otp_pin_attempts']);
        }
        $this->assertRefused(fn() => $claim('333333'), 'Too many incorrect attempts on the claim PIN');
        // The locked reservation PIN was never compared, so never counted.
        $this->assertSame(5, (int)$this->reservation($reservationId)['claim_pin_attempts']);
    }

    public function testANewClaimPinOnTheReservationAccountStartsWithACleanCount(): void
    {
        $this->addHold(self::OWNED, 'account_pin', '111111');
        $reservationId = $this->addReservation(self::OWNED, '246810');
        self::lockReservationPins();

        // The swap's own code still works, and the claim parks a remainder there.
        $pool = $this->claimAsAgent('111111');
        $this->afterClaim(['reservation_account_id' => $reservationId], '111111', $pool);

        $reservation = $this->reservation($reservationId);
        $this->assertTrue(password_verify('111111', $reservation['claim_pin_hash']));
        $this->assertSame(0, (int)$reservation['claim_pin_attempts']);
        $this->assertNull($reservation['claim_pin_locked_until']);
    }

    // ------------------------------------------------------------
    // The single-hold path (confirmAndFinalizeIdentitySwap()), which
    // swap/execute.php used to expose as swap_type CONFIRM_IDENTITY
    // ------------------------------------------------------------

    public function testOnlyAnApprovedAgentCanConfirmAHoldAsAnAgent(): void
    {
        $holdId = $this->addHold(self::OWNED, 'account_pin', '111111');

        // The right code and the "document checked" flag aren't enough from
        // someone who isn't an agent.
        $this->assertRefused(
            fn() => $this->confirmSingleHold($holdId, '111111', 'agent', self::OTHER_USER_ID),
            'Only an approved VouchMorph agent can confirm a claim as an agent.'
        );
        $hold = $this->hold($holdId);
        $this->assertSame('pending', $hold['status']);
        $this->assertTrue(password_verify('111111', $hold['otp_pin_hash']));   // not used up
        $this->assertSame(0, (int)$hold['otp_pin_attempts']);

        // An approved agent gets through to the code check.
        $this->assertRefused(fn() => $this->confirmSingleHold($holdId, '000000', 'agent', self::AGENT_ID), 'Incorrect claim PIN.');
        $this->assertSame(1, (int)$this->hold($holdId)['otp_pin_attempts']);
    }

    public function testTheSingleHoldPathNeverTriesTheOwnersTransactionPinForSomeoneElse(): void
    {
        $holdId = $this->addHold(self::OWNED, 'account_pin', '111111');

        // Guessing used to count against it, locking it after five...
        for ($i = 0; $i < 6; $i++) {
            $this->assertRefused(fn() => $this->confirmSingleHold($holdId, '000000', 'user', self::OTHER_USER_ID), 'Only they can claim it in the app.');
        }
        // ...and knowing it used to be enough.
        $this->assertRefused(fn() => $this->confirmSingleHold($holdId, self::TRANSACTION_PIN, 'user', self::OTHER_USER_ID), 'Only they can claim it in the app.');

        $pin = $this->transactionPin();
        $this->assertSame(0, (int)$pin['failed_attempts']);
        $this->assertNull($pin['locked_until']);
        $this->assertSame('pending', $this->hold($holdId)['status']);

        // The owner still gets through to their PIN.
        $this->assertRefused(fn() => $this->confirmSingleHold($holdId, '000000', 'user', self::OWNER_ID), 'Incorrect transaction PIN.');
        $this->assertSame(1, (int)$this->transactionPin()['failed_attempts']);
    }
}
