<?php

use PHPUnit\Framework\TestCase;
use Domain\Identity\ContactVerificationService;
use Domain\Identity\SignInSchema;
use Domain\Identity\UsernameGenerator;
use Infrastructure\Credentials\CredentialsRepository;
use Infrastructure\Email\Contracts\EmailProviderInterface;
use Security\Auth\LoginPinVerifier;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Email sign-up and verified-email sign-in against a real PostgreSQL
 * server: the two migration files as written (applied twice), and the
 * Postgres-only SQL the unit tests can't reach on SQLite — the lockout
 * UPDATE ... RETURNING with its typed CASE, the otp_logs insert into
 * timestamptz / inet columns, lastInsertId() on a sequence, the LIKE
 * ESCAPE in the username lookup and the information_schema checks.
 *
 * Like the other integration tests it never falls back to DATABASE_URL —
 * it creates and drops schemas. Point it at a throwaway server:
 *
 *     SIGN_IN_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/EmailSignInPostgresTest.php
 */
class EmailSignInPostgresTest extends TestCase
{
    private const MAIN = 'sign_in_test_main';
    private const CREDS = 'sign_in_test_creds';
    private const PIN = '482913';

    private static ?PDO $db = null;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('SIGN_IN_TEST_DATABASE_URL');
        if (!$url) {
            return;
        }
        $parts = parse_url($url);
        self::$db = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $parts['host'] ?? 'localhost', $parts['port'] ?? 5432, ltrim($parts['path'] ?? '', '/')),
            $parts['user'] ?? 'postgres',
            $parts['pass'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->exec('DROP SCHEMA IF EXISTS ' . self::MAIN . ' CASCADE');
            self::$db->exec('DROP SCHEMA IF EXISTS ' . self::CREDS . ' CASCADE');
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('Set SIGN_IN_TEST_DATABASE_URL to a throwaway PostgreSQL database to run this.');
        }
        SignInSchema::forget();

        foreach ([self::MAIN, self::CREDS] as $schema) {
            self::$db->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
            self::$db->exec("CREATE SCHEMA {$schema}");
        }

        // Main database as the canonical dump has it (phone and email
        // NOT NULL, both UNIQUE) plus the columns register.php adds at
        // runtime, and otp_logs with register.php's constraints.
        $this->useSchema(self::MAIN);
        self::$db->exec("
            CREATE TABLE users (
                user_id              BIGSERIAL PRIMARY KEY,
                username             VARCHAR(100) NOT NULL,
                email                VARCHAR(150) NOT NULL,
                phone                VARCHAR(20) NOT NULL,
                phone2               VARCHAR(50),
                phone3               VARCHAR(50),
                verified             BOOLEAN DEFAULT FALSE,
                registration_channel VARCHAR(20),
                created_at           TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT users_email_key UNIQUE (email),
                CONSTRAINT users_phone_key UNIQUE (phone)
            )
        ");
        self::$db->exec("
            CREATE TABLE otp_logs (
                otp_id BIGSERIAL PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                identifier_type VARCHAR(20),
                code_hash VARCHAR(255) NOT NULL,
                purpose VARCHAR(50),
                expires_at TIMESTAMPTZ NOT NULL,
                used_at TIMESTAMPTZ,
                attempts INT DEFAULT 0,
                ip_address INET,
                user_agent TEXT,
                created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT otp_logs_identifier_type_check CHECK (identifier_type IN ('phone', 'email', 'sms')),
                CONSTRAINT otp_logs_purpose_check CHECK (purpose IN ('verification', 'registration', 'login', 'password_reset', 'withdrawal'))
            )
        ");
        self::$db->exec("
            INSERT INTO users (username, email, phone, registration_channel, created_at) VALUES
                ('jane',      'jane@example.com',              '+26771000001', 'self',  '2026-09-01 10:00+02'),
                ('thabo',     'thabo@botswana.vouchmorphn.com','+26771000002', 'self',  NOW()),
                ('agentmade', 'agent.made@example.com',        '+26771000003', 'agent', NOW()),
                ('dup1',      'Dup@example.com',               '+26771000004', 'self',  NOW()),
                ('dup2',      'dup@example.com',               '+26771000005', 'self',  NOW())
        ");

        // Credentials database from before the lockout columns.
        $this->useSchema(self::CREDS);
        self::$db->exec("
            CREATE TABLE user_credentials (
                user_id BIGINT PRIMARY KEY,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
            )
        ");
        self::$db->exec("INSERT INTO user_credentials (user_id, password_hash) SELECT generate_series(1, 5), '" . password_hash(self::PIN, PASSWORD_BCRYPT, ['cost' => 4]) . "'");
    }

    public function testMainMigrationAppliesTwiceAndBackfillsOnlyRealSelfSignUpEmails(): void
    {
        $this->useSchema(self::MAIN);
        $this->applyMainMigration();
        $this->applyMainMigration();

        $this->assertTrue(SignInSchema::hasEmailVerifiedAt(self::$db));
        $verified = self::$db->query("SELECT username FROM users WHERE email_verified_at IS NOT NULL ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['jane'], $verified, 'no made-up, agent-typed or duplicated address is marked verified');

        $janeVerifiedAt = self::$db->query("SELECT email_verified_at = created_at FROM users WHERE username = 'jane'")->fetchColumn();
        $this->assertTrue((bool)$janeVerifiedAt);
    }

    public function testEmailOnlyAccountsCanBeSavedAfterTheMigration(): void
    {
        $this->useSchema(self::MAIN);
        try {
            self::$db->exec("INSERT INTO users (username, email, phone) VALUES ('before', 'before@example.com', NULL)");
            $this->fail('phone should still be required before the migration');
        } catch (PDOException $e) {
            $this->assertSame('23502', $e->getCode(), 'the not-null violation verify_otp.php turns into a friendly message');
        }

        $this->applyMainMigration();
        self::$db->exec("INSERT INTO users (username, email, phone) VALUES ('a', 'a@example.com', NULL), ('b', 'b@example.com', NULL)");
        $this->assertSame(2, (int)self::$db->query("SELECT COUNT(*) FROM users WHERE phone IS NULL")->fetchColumn());
    }

    public function testTwoAccountsCannotShareAVerifiedEmail(): void
    {
        $this->useSchema(self::MAIN);
        $this->applyMainMigration();

        self::$db->exec("UPDATE users SET email_verified_at = NOW() WHERE username = 'dup1'");
        $this->expectException(PDOException::class);
        self::$db->exec("UPDATE users SET email_verified_at = NOW() WHERE username = 'dup2'");
    }

    public function testLockoutMigrationAndTheLockoutSqlOnPostgres(): void
    {
        $this->useSchema(self::CREDS);
        $repo = new CredentialsRepository(self::$db);
        $this->assertFalse($repo->supportsUserLoginLockout());

        $sql = file_get_contents(__DIR__ . '/../../scripts/credentials_db/2026_09_27_user_login_lockout.sql');
        self::$db->exec($sql);
        self::$db->exec($sql);
        SignInSchema::forget();
        $this->assertTrue($repo->supportsUserLoginLockout());

        $verifier = new LoginPinVerifier($repo);
        for ($i = 1; $i < LoginPinVerifier::MAX_ATTEMPTS; $i++) {
            $this->assertSame(LoginPinVerifier::WRONG_PIN, $verifier->check(1, '000000')['result']);
        }
        $locked = $verifier->check(1, '000000');
        $this->assertSame(LoginPinVerifier::LOCKED, $locked['result']);
        $this->assertTrue(LoginPinVerifier::isLocked($locked['locked_until']));
        $this->assertSame(LoginPinVerifier::LOCKED, $verifier->check(1, self::PIN)['result']);

        self::$db->exec("UPDATE user_credentials SET locked_until = NOW() - INTERVAL '1 minute' WHERE user_id = 1");
        $this->assertSame(LoginPinVerifier::OK, $verifier->check(1, self::PIN)['result']);
        $this->assertSame(0, (int)self::$db->query('SELECT failed_login_attempts FROM user_credentials WHERE user_id = 1')->fetchColumn());
    }

    public function testAddingAnEmailEndToEndOnPostgres(): void
    {
        $this->useSchema(self::MAIN);
        $this->applyMainMigration();
        $this->useSchema(self::CREDS);
        $credentials = new CredentialsRepository(self::$db);
        $this->useSchema(self::MAIN);

        $mailer = new EmailSignInPostgresTestMailer();
        $texts = [];
        $service = new ContactVerificationService(
            self::$db,
            new LoginPinVerifier($credentials),
            $mailer,
            function (string $phone, string $message) use (&$texts): bool {
                $texts[] = [$phone, $message];
                return true;
            }
        );

        // Credentials and main live in different schemas here, as they
        // live in different databases in production: switch per call.
        $thabo = (int)self::$db->query("SELECT user_id FROM users WHERE username = 'thabo'")->fetchColumn();
        $started = $this->withCredentialsSchemaForPin(fn() => $service->start($thabo, 'email', 'Thabo@Example.com', self::PIN, null, ['ip' => 'not-an-ip']));
        $this->assertGreaterThan(0, $started['pending']['otp_id']);

        $row = self::$db->query('SELECT ip_address, purpose, identifier_type FROM otp_logs WHERE otp_id = ' . (int)$started['pending']['otp_id'])->fetch();
        $this->assertNull($row['ip_address'], 'a non-IP never reaches the inet column');
        $this->assertSame(['verification', 'email'], [$row['purpose'], $row['identifier_type']]);

        $this->assertSame(1, preg_match('/<strong[^>]*>(\d{6})<\/strong>/', $mailer->sent[0]['body'], $m));
        $service->confirm($thabo, $started['pending'], $m[1]);

        $user = self::$db->query("SELECT email, email_verified_at FROM users WHERE user_id = {$thabo}")->fetch();
        $this->assertSame('thabo@example.com', $user['email']);
        $this->assertNotNull($user['email_verified_at']);
        $this->assertSame('+26771000002', $texts[0][0], 'the account phone is told');
    }

    public function testUsernameFromEmailOnPostgres(): void
    {
        $this->useSchema(self::MAIN);
        self::$db->exec("INSERT INTO users (username, email, phone) VALUES ('j_doe', 'x@example.com', '+26771999999'), ('jxdoe2', 'y@example.com', '+26771999998')");

        $this->assertSame('jane2', UsernameGenerator::forEmail(self::$db, 'jane@elsewhere.com'));
        $this->assertSame('j_doe2', UsernameGenerator::forEmail(self::$db, 'J_Doe@example.com'));
        $this->assertSame('bob', UsernameGenerator::forEmail(self::$db, 'bob@example.com'));
    }

    // ------------------------------------------------------------------

    private function applyMainMigration(): void
    {
        self::$db->exec(file_get_contents(__DIR__ . '/../../database/migrations/2026_09_27_email_sign_in.sql'));
        SignInSchema::forget();
    }

    private function useSchema(string $schema): void
    {
        self::$db->exec("SET search_path TO {$schema}");
    }

    /**
     * One connection stands in for both databases: the PIN check reads
     * user_credentials, everything else reads users/otp_logs. Putting the
     * credentials schema second on the path lets both resolve.
     */
    private function withCredentialsSchemaForPin(callable $step)
    {
        self::$db->exec('SET search_path TO ' . self::MAIN . ', ' . self::CREDS);
        try {
            return $step();
        } finally {
            $this->useSchema(self::MAIN);
        }
    }
}

final class EmailSignInPostgresTestMailer implements EmailProviderInterface
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function sendEmail(string $to, string $subject, string $htmlBody): array
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $htmlBody];
        return ['success' => true, 'message' => 'Email sent'];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
