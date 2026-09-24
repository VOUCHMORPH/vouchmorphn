<?php

use PHPUnit\Framework\TestCase;
use Infrastructure\Credentials\CredentialsRepository;
use Infrastructure\Credentials\TransactionPinMigrationRunner;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * The users.transaction_pin_* -> credentials DB move, run against a real
 * PostgreSQL server: TransactionPinMigrationRunner reads information_schema
 * and relies on Postgres upsert/boolean/timestamptz behaviour, and
 * CredentialsRepository's PIN lockout SQL has Postgres-only typing rules —
 * none of which the SQLite unit tests (TransactionPinCredentialsTest) can
 * vouch for.
 *
 * Needs a database. Like UserIdentifierLookupTest it deliberately does NOT
 * fall back to DATABASE_URL or CREDENTIALS_DATABASE_URL: it creates and
 * drops schemas. Point it at a throwaway server explicitly:
 *
 *     CREDENTIALS_MIGRATION_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/TransactionPinMigrationTest.php
 *
 * Two throwaway schemas stand in for the two databases — one holding a
 * `users` table shaped like the main database's, one built from the real
 * scripts/credentials_db/schema.sql — each behind its own connection, so
 * the runner sees exactly what it would in production. Both are dropped
 * again in tearDownAfterClass. The drifted-schema case lives in
 * TransactionPinMigrationSchemaDriftTest.
 */
class TransactionPinMigrationTest extends TestCase
{
    private const MAIN_SCHEMA = 'tpin_migration_main';
    private const CRED_SCHEMA = 'tpin_migration_cred';

    private static ?PDO $main = null;
    private static ?PDO $cred = null;
    private static array $lines = [];

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
        // Deliberately different from the app's timezone (below), so a
        // lock time that isn't converted to an absolute instant shows up.
        self::$cred->exec("SET TIME ZONE 'UTC'");
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
        // The app's own timezone (php.ini date.timezone in docker/), which
        // is what wrote users.transaction_pin_locked_until.
        date_default_timezone_set('Africa/Gaborone');
        self::$lines = [];

        self::$main->exec('DROP TABLE IF EXISTS users');
        self::$main->exec("
            CREATE TABLE users (
                user_id                       BIGSERIAL PRIMARY KEY,
                username                      VARCHAR(100) NOT NULL,
                transaction_pin_hash          VARCHAR(255),
                transaction_pin_set_at        TIMESTAMP,
                transaction_pin_attempts      INTEGER DEFAULT 0,
                transaction_pin_locked_until  TIMESTAMP,
                has_transaction_pin           BOOLEAN DEFAULT FALSE
            )
        ");
        self::$cred->exec('DROP TABLE IF EXISTS user_credentials, admin_credentials, user_transaction_pins');
        self::$cred->exec(file_get_contents(__DIR__ . '/../../scripts/credentials_db/schema.sql'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
    }

    private static function out(): callable
    {
        return function (string $line): void {
            self::$lines[] = $line;
        };
    }

    private function addUser(string $username, ?string $pinHash, int $attempts = 0, ?string $lockedUntil = null): int
    {
        $stmt = self::$main->prepare("
            INSERT INTO users (username, transaction_pin_hash, transaction_pin_set_at, transaction_pin_attempts, transaction_pin_locked_until)
            VALUES (:u, :h, NOW(), :a, :l) RETURNING user_id
        ");
        $stmt->execute([':u' => $username, ':h' => $pinHash, ':a' => $attempts, ':l' => $lockedUntil]);
        return (int)$stmt->fetchColumn();
    }

    private function pinRow(int $userId): ?array
    {
        $stmt = self::$cred->prepare('SELECT * FROM user_transaction_pins WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch() ?: null;
    }

    private function migrate(bool $apply): array
    {
        return TransactionPinMigrationRunner::migrate(self::$main, self::$cred, $apply, 2, self::out());
    }

    private function verify(): bool
    {
        return TransactionPinMigrationRunner::verify(self::$main, self::$cred, 2, self::out());
    }

    // ------------------------------------------------------------

    public function testDryRunWritesNothing(): void
    {
        $this->addUser('a', password_hash('111111', PASSWORD_DEFAULT));
        $this->addUser('b', password_hash('222222', PASSWORD_DEFAULT));
        $this->addUser('c', password_hash('333333', PASSWORD_DEFAULT));

        $result = $this->migrate(false);

        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['migrated']);
        $this->assertSame(0, (int)self::$cred->query('SELECT COUNT(*) FROM user_transaction_pins')->fetchColumn());
    }

    public function testApplyCopiesEveryPinWithItsLockoutState(): void
    {
        $hashA = password_hash('111111', PASSWORD_DEFAULT);
        $hashB = password_hash('222222', PASSWORD_DEFAULT);
        // Written the way SwapService::recordFailedAccountPinAttempt() did:
        // date('Y-m-d H:i:s'), a bare local time in the app's timezone.
        $lockEndsAt = time() + 20 * 60;
        $a = $this->addUser('a', $hashA, 2);
        $b = $this->addUser('b', $hashB, 5, date('Y-m-d H:i:s', $lockEndsAt));
        $none = $this->addUser('no_pin', null);
        $blank = $this->addUser('blank_pin', '');

        $result = $this->migrate(true);

        $this->assertSame(['total' => 2, 'migrated' => 2, 'kept' => 0], $result);

        $rowA = $this->pinRow($a);
        $this->assertSame($hashA, $rowA['pin_hash']);
        $this->assertSame(2, (int)$rowA['failed_attempts']);
        $this->assertNull($rowA['locked_until']);
        $this->assertNotNull($rowA['pin_set_at']);
        $this->assertTrue($rowA['copied_from_main_db']);

        // Still locked until the same instant, although this session runs
        // in UTC and the app in Africa/Gaborone.
        $rowB = $this->pinRow($b);
        $this->assertSame(5, (int)$rowB['failed_attempts']);
        $this->assertSame($lockEndsAt, strtotime($rowB['locked_until']));

        $this->assertNull($this->pinRow($none));
        $this->assertNull($this->pinRow($blank));
        $this->assertTrue($this->verify());
    }

    public function testVerifyCatchesMissingAndOutOfDateRowsAndApplyFixesThem(): void
    {
        $a = $this->addUser('a', password_hash('111111', PASSWORD_DEFAULT));
        $this->addUser('b', password_hash('222222', PASSWORD_DEFAULT));
        $this->migrate(true);
        $this->assertTrue($this->verify());

        // The old code keeps running until the deploy: a PIN is changed and
        // a new user signs up, both only in the main database.
        self::$main->prepare('UPDATE users SET transaction_pin_hash = :h WHERE user_id = :id')
            ->execute([':h' => password_hash('999999', PASSWORD_DEFAULT), ':id' => $a]);
        $c = $this->addUser('c', password_hash('333333', PASSWORD_DEFAULT));

        self::$lines = [];
        $this->assertFalse($this->verify());
        $this->assertContains("[transaction_pins] Hash differs from the main database for user_id={$a}", self::$lines);
        $this->assertContains("[transaction_pins] Missing from the credentials database: user_id={$c}", self::$lines);

        $this->migrate(true);

        $this->assertTrue($this->verify());
        $this->assertTrue(password_verify('999999', $this->pinRow($a)['pin_hash']));
    }

    public function testReRunningAfterTheCutoverNeverOverwritesWhatTheAppWrote(): void
    {
        $a = $this->addUser('a', password_hash('111111', PASSWORD_DEFAULT));
        $b = $this->addUser('b', password_hash('222222', PASSWORD_DEFAULT));
        $c = $this->addUser('c', password_hash('333333', PASSWORD_DEFAULT));
        $this->migrate(true);

        // On the new code: a changes their PIN, b gets locked out.
        $repo = new CredentialsRepository(self::$cred);
        $repo->setUserTransactionPin($a, password_hash('444444', PASSWORD_DEFAULT));
        for ($i = 0; $i < 5; $i++) {
            $repo->recordFailedUserPinAttempt($b);
        }

        $result = $this->migrate(true);

        $this->assertSame(['total' => 3, 'migrated' => 1, 'kept' => 2], $result);
        $this->assertTrue(password_verify('444444', $this->pinRow($a)['pin_hash']));
        $this->assertSame(5, (int)$this->pinRow($b)['failed_attempts']);
        $this->assertNotNull($this->pinRow($b)['locked_until']);
        $this->assertTrue($this->pinRow($c)['copied_from_main_db']);

        self::$lines = [];
        $this->assertTrue($this->verify());
        $this->assertStringContainsString('1 identical to the main database, 2 changed by the app', end(self::$lines));
    }

    public function testPinLockoutSqlOnPostgres(): void
    {
        $repo = new CredentialsRepository(self::$cred);
        $hash = password_hash('482913', PASSWORD_DEFAULT);
        $repo->createUserCredentialWithTransactionPin(77, $hash, $hash);

        $this->assertSame($hash, $repo->findUserCredentialByUserId(77)['password_hash']);
        $this->assertTrue($repo->hasUserTransactionPin(77));
        $this->assertFalse($repo->hasUserTransactionPin(78));
        $this->assertFalse($this->pinRow(77)['copied_from_main_db']);

        for ($i = 1; $i <= 4; $i++) {
            $state = $repo->recordFailedUserPinAttempt(77);
            $this->assertSame($i, (int)$state['failed_attempts']);
            $this->assertNull($state['locked_until']);
        }
        $state = $repo->recordFailedUserPinAttempt(77);
        $this->assertSame(5, (int)$state['failed_attempts']);
        $lockedFor = strtotime($state['locked_until']) - time();
        $this->assertGreaterThan(29 * 60, $lockedFor);
        $this->assertLessThanOrEqual(30 * 60, $lockedFor);

        $repo->resetUserPinFailedAttempts(77);
        $row = $repo->findUserTransactionPin(77);
        $this->assertSame(0, (int)$row['failed_attempts']);
        $this->assertNull($row['locked_until']);

        $repo->setUserTransactionPin(77, password_hash('135790', PASSWORD_DEFAULT));
        $this->assertTrue(password_verify('135790', $repo->findUserTransactionPin(77)['pin_hash']));
    }
}
