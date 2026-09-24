<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;
use Infrastructure\Credentials\CredentialsRepository;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Transaction PINs moved from users.transaction_pin_* in the main database
 * to user_transaction_pins in the credentials database. These run the real
 * CredentialsRepository SQL (against the real
 * scripts/credentials_db/schema.sql) and the real SwapService claim-PIN
 * code, with two in-memory SQLite databases standing in for the main and
 * the credentials database — SwapService is built without its constructor
 * and wired up by reflection, the same pattern as IdentityOwnerMatchingTest
 * — so they pin down which database every PIN read and write goes to.
 */
class TransactionPinCredentialsTest extends TestCase
{
    private const OWNER_ID = 42;
    private const IDENTITY_TYPE = 'national_id';
    private const IDENTITY_VALUE = '123456789';

    private PDO $mainDb;
    private PDO $credDb;
    private CredentialsRepository $credentials;
    private SwapService $service;

    protected function setUp(): void
    {
        $this->credDb = self::sqlite();
        $this->credDb->exec(file_get_contents(__DIR__ . '/../../scripts/credentials_db/schema.sql'));
        $this->credentials = new CredentialsRepository($this->credDb);

        $this->mainDb = self::sqlite();
        // The main-DB columns the PIN used to live in, still present (as
        // they are until phase 2 drops them) so the tests can prove
        // nothing reads or writes them any more.
        $this->mainDb->exec("
            CREATE TABLE users (
                user_id INTEGER PRIMARY KEY,
                transaction_pin_hash TEXT,
                transaction_pin_attempts INTEGER DEFAULT 0,
                transaction_pin_locked_until TEXT
            )
        ");
        $this->mainDb->exec("
            CREATE TABLE user_identities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                identity_type TEXT NOT NULL,
                identity_value TEXT NOT NULL,
                status TEXT NOT NULL
            )
        ");
        $this->mainDb->exec("
            CREATE TABLE identity_swap_holds (
                hold_id INTEGER PRIMARY KEY AUTOINCREMENT,
                identity_type TEXT NOT NULL,
                identity_value TEXT NOT NULL,
                status TEXT DEFAULT 'pending',
                hold_expires_at TEXT,
                authorized_at TEXT,
                authorized_by TEXT,
                authorization_type TEXT,
                otp_pin_attempts INTEGER DEFAULT 0
            )
        ");

        $stmt = $this->mainDb->prepare("
            INSERT INTO user_identities (user_id, identity_type, identity_value, status)
            VALUES (:uid, :type, :value, 'verified')
        ");
        $stmt->execute([':uid' => self::OWNER_ID, ':type' => self::IDENTITY_TYPE, ':value' => self::IDENTITY_VALUE]);

        $stmt = $this->mainDb->prepare("
            INSERT INTO identity_swap_holds (identity_type, identity_value, status, hold_expires_at)
            VALUES (:type, :value, 'pending', datetime('now', '+1 day'))
        ");
        $stmt->execute([':type' => self::IDENTITY_TYPE, ':value' => self::IDENTITY_VALUE]);

        $reflection = new \ReflectionClass(SwapService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
        foreach (['swapDB' => $this->mainDb, 'credentialsRepository' => $this->credentials] as $name => $value) {
            $prop = $reflection->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($this->service, $value);
        }
    }

    private static function sqlite(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Production SQL uses Postgres's NOW(); register it as a UDF rather
        // than touching the SQL (same approach as the other unit tests).
        $db->sqliteCreateFunction('now', fn() => date('Y-m-d H:i:s'));
        return $db;
    }

    private function claimWithAccountPin(string $pin): void
    {
        $method = new \ReflectionMethod(SwapService::class, 'verifyIdentityClaimPin');
        $method->setAccessible(true);
        $method->invoke($this->service, [
            'claim_type' => 'account_pin',
            'hold_id' => 1,
            'identity_type' => self::IDENTITY_TYPE,
            'identity_value' => self::IDENTITY_VALUE,
        ], $pin, 'user');
    }

    private function assertClaimFails(string $pin, string $expectedMessage): void
    {
        try {
            $this->claimWithAccountPin($pin);
            $this->fail("Expected the claim with PIN {$pin} to be refused.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }

    private function pinRow(int $userId = self::OWNER_ID): array
    {
        $stmt = $this->credDb->prepare("SELECT * FROM user_transaction_pins WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch() ?: [];
    }

    private function holdIsAuthorized(): bool
    {
        return $this->mainDb->query("SELECT authorized_at FROM identity_swap_holds WHERE hold_id = 1")->fetchColumn() !== null;
    }

    // ------------------------------------------------------------
    // Claiming with the account's transaction PIN
    // ------------------------------------------------------------

    public function testTheClaimIsCheckedAgainstThePinInTheCredentialsDatabase(): void
    {
        $this->credentials->setUserTransactionPin(self::OWNER_ID, password_hash('482913', PASSWORD_DEFAULT));

        $this->claimWithAccountPin('482913');

        $this->assertTrue($this->holdIsAuthorized());
    }

    public function testAHashLeftBehindInTheMainDatabaseIsIgnored(): void
    {
        // Until phase 2 drops the column, the main database can still hold
        // an older PIN. It must no longer unlock anything.
        $this->mainDb->prepare("INSERT INTO users (user_id, transaction_pin_hash) VALUES (:id, :hash)")
            ->execute([':id' => self::OWNER_ID, ':hash' => password_hash('111111', PASSWORD_DEFAULT)]);
        $this->credentials->setUserTransactionPin(self::OWNER_ID, password_hash('482913', PASSWORD_DEFAULT));

        $this->assertClaimFails('111111', 'Incorrect transaction PIN.');
        $this->assertFalse($this->holdIsAuthorized());
    }

    public function testNoPinInTheCredentialsDatabaseMeansNoPinSet(): void
    {
        $this->mainDb->prepare("INSERT INTO users (user_id, transaction_pin_hash) VALUES (:id, :hash)")
            ->execute([':id' => self::OWNER_ID, ':hash' => password_hash('482913', PASSWORD_DEFAULT)]);

        $this->assertClaimFails('482913', 'No transaction PIN has been set on this account yet.');
    }

    public function testWrongPinsAreCountedInTheCredentialsDatabaseAndLockAfterFive(): void
    {
        $this->credentials->setUserTransactionPin(self::OWNER_ID, password_hash('482913', PASSWORD_DEFAULT));

        for ($i = 1; $i <= 4; $i++) {
            $this->assertClaimFails('000000', 'Incorrect transaction PIN.');
            $this->assertSame($i, (int)$this->pinRow()['failed_attempts']);
            $this->assertNull($this->pinRow()['locked_until']);
        }

        $this->assertClaimFails('000000', 'Incorrect transaction PIN.');
        $row = $this->pinRow();
        $this->assertSame(5, (int)$row['failed_attempts']);
        $lockedFor = strtotime($row['locked_until']) - time();
        $this->assertGreaterThan(29 * 60, $lockedFor);
        $this->assertLessThanOrEqual(30 * 60, $lockedFor);

        // Locked: even the right PIN is refused, and nothing is authorized.
        $this->assertClaimFails('482913', 'Too many incorrect attempts on the transaction PIN');
        $this->assertFalse($this->holdIsAuthorized());

        // The main database's old lockout columns were never touched.
        $this->assertSame(0, (int)$this->mainDb->query("SELECT COUNT(*) FROM users")->fetchColumn());
    }

    public function testEveryMissAfterAnExpiredLockLocksAgain(): void
    {
        $this->credentials->setUserTransactionPin(self::OWNER_ID, password_hash('482913', PASSWORD_DEFAULT));
        $this->credDb->exec("UPDATE user_transaction_pins SET failed_attempts = 5, locked_until = '2000-01-01T00:00:00+00:00'");

        $this->assertClaimFails('000000', 'Incorrect transaction PIN.');

        $row = $this->pinRow();
        $this->assertSame(6, (int)$row['failed_attempts']);
        $this->assertGreaterThan(time(), strtotime($row['locked_until']));
    }

    public function testTheRightPinResetsTheCount(): void
    {
        $this->credentials->setUserTransactionPin(self::OWNER_ID, password_hash('482913', PASSWORD_DEFAULT));
        $this->assertClaimFails('000000', 'Incorrect transaction PIN.');
        $this->assertClaimFails('000000', 'Incorrect transaction PIN.');

        $this->claimWithAccountPin('482913');

        $this->assertSame(0, (int)$this->pinRow()['failed_attempts']);
        $this->assertTrue($this->holdIsAuthorized());
    }

    public function testAMissStaysCountedWhenTheMainDatabaseTransactionRollsBack(): void
    {
        // Single-hold finalization checks the PIN inside the swap's
        // main-DB transaction, which is rolled back when the PIN is wrong.
        // The count used to live in that transaction and vanish with it.
        $this->credentials->setUserTransactionPin(self::OWNER_ID, password_hash('482913', PASSWORD_DEFAULT));

        $this->mainDb->beginTransaction();
        $this->assertClaimFails('000000', 'Incorrect transaction PIN.');
        $this->mainDb->rollBack();

        $this->assertSame(1, (int)$this->pinRow()['failed_attempts']);
    }

    // ------------------------------------------------------------
    // Setting a PIN from the profile
    // ------------------------------------------------------------

    public function testSettingAPinWritesOnlyTheCredentialsDatabase(): void
    {
        $this->mainDb->exec("INSERT INTO users (user_id) VALUES (" . self::OWNER_ID . ")");

        $this->service->setUserTransactionPin(self::OWNER_ID, '2468');

        $row = $this->pinRow();
        $this->assertTrue(password_verify('2468', $row['pin_hash']));
        $this->assertSame(0, (int)$row['failed_attempts']);
        $this->assertNull($row['locked_until']);
        $this->assertNotNull($row['pin_set_at']);
        $this->assertNull($this->mainDb->query("SELECT transaction_pin_hash FROM users")->fetchColumn());

        $this->claimWithAccountPin('2468');
        $this->assertTrue($this->holdIsAuthorized());
    }

    public function testSettingAPinStillRequiresFourToSixDigits(): void
    {
        foreach (['123', '1234567', '12a4', ''] as $bad) {
            try {
                $this->service->setUserTransactionPin(self::OWNER_ID, $bad);
                $this->fail("Accepted PIN '{$bad}'");
            } catch (RuntimeException $e) {
                $this->assertSame('PIN must be 4-6 digits.', $e->getMessage());
            }
        }
        $this->assertSame([], $this->pinRow());
    }

    public function testSettingANewPinClearsALockout(): void
    {
        $this->credentials->setUserTransactionPin(self::OWNER_ID, password_hash('482913', PASSWORD_DEFAULT));
        for ($i = 0; $i < 5; $i++) {
            $this->assertClaimFails('000000', 'Incorrect transaction PIN.');
        }

        $this->service->setUserTransactionPin(self::OWNER_ID, '135790');

        $row = $this->pinRow();
        $this->assertSame(0, (int)$row['failed_attempts']);
        $this->assertNull($row['locked_until']);
        $this->claimWithAccountPin('135790');
        $this->assertTrue($this->holdIsAuthorized());
    }

    // ------------------------------------------------------------
    // Repository: sign-up, "has a PIN", migration bookkeeping
    // ------------------------------------------------------------

    public function testSignUpWritesTheLoginCredentialAndThePinTogether(): void
    {
        $hash = password_hash('482913', PASSWORD_DEFAULT);

        $this->credentials->createUserCredentialWithTransactionPin(7, $hash, $hash);

        $this->assertSame($hash, $this->credentials->findUserCredentialByUserId(7)['password_hash']);
        $this->assertSame($hash, $this->credentials->findUserTransactionPin(7)['pin_hash']);
        $this->assertTrue($this->credentials->hasUserTransactionPin(7));
        $this->assertFalse($this->credentials->hasUserTransactionPin(8));
    }

    public function testSignUpLeavesNoLoginCredentialBehindIfThePinCannotBeWritten(): void
    {
        $this->credDb->exec("DROP TABLE user_transaction_pins");
        $hash = password_hash('482913', PASSWORD_DEFAULT);

        try {
            $this->credentials->createUserCredentialWithTransactionPin(7, $hash, $hash);
            $this->fail('Expected the PIN write to fail.');
        } catch (PDOException $e) {
            // expected
        }

        $this->assertNull($this->credentials->findUserCredentialByUserId(7));
        $this->assertFalse($this->credDb->inTransaction());
    }

    public function testEveryAppWriteClearsTheMigrationMark(): void
    {
        // A row as the one-time migration leaves it.
        $this->credDb->exec("
            INSERT INTO user_transaction_pins (user_id, pin_hash, copied_from_main_db)
            VALUES (" . self::OWNER_ID . ", 'x', TRUE)
        ");
        $mark = fn(): int => (int)$this->pinRow()['copied_from_main_db'];
        $remark = fn() => $this->credDb->exec("UPDATE user_transaction_pins SET copied_from_main_db = TRUE");

        $this->credentials->recordFailedUserPinAttempt(self::OWNER_ID);
        $this->assertSame(0, $mark());

        $remark();
        $this->credentials->resetUserPinFailedAttempts(self::OWNER_ID);
        $this->assertSame(0, $mark());

        $remark();
        $this->credentials->setUserTransactionPin(self::OWNER_ID, 'y');
        $this->assertSame(0, $mark());
    }
}
