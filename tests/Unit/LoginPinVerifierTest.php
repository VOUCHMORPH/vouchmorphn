<?php

use PHPUnit\Framework\TestCase;
use Domain\Identity\SignInSchema;
use Infrastructure\Credentials\CredentialsRepository;
use Security\Auth\LoginPinVerifier;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * The sign-in lockout: 5 wrong login PINs lock sign-in for 30 minutes,
 * the PIN is not even looked at while locked, and a correct PIN resets the
 * count. Runs the real CredentialsRepository SQL against the real
 * scripts/credentials_db/schema.sql on in-memory SQLite, like
 * TransactionPinCredentialsTest.
 */
class LoginPinVerifierTest extends TestCase
{
    private const USER_ID = 7;
    private const PIN = '482913';

    private PDO $credDb;
    private LoginPinVerifier $verifier;

    protected function setUp(): void
    {
        SignInSchema::forget();
        $this->credDb = self::sqlite();
        $this->credDb->exec(file_get_contents(__DIR__ . '/../../scripts/credentials_db/schema.sql'));
        (new CredentialsRepository($this->credDb))->createUserCredential(self::USER_ID, self::hash(self::PIN));
        $this->verifier = new LoginPinVerifier(new CredentialsRepository($this->credDb));
    }

    public function testCorrectPinSignsIn(): void
    {
        $this->assertSame(LoginPinVerifier::OK, $this->verifier->check(self::USER_ID, self::PIN)['result']);
    }

    public function testFifthWrongPinLocksSignIn(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(LoginPinVerifier::WRONG_PIN, $this->verifier->check(self::USER_ID, '000000')['result'], "miss {$i}");
        }
        $fifth = $this->verifier->check(self::USER_ID, '000000');

        $this->assertSame(LoginPinVerifier::LOCKED, $fifth['result']);
        $this->assertNotNull($fifth['locked_until']);
        $this->assertTrue(LoginPinVerifier::isLocked($fifth['locked_until']));
    }

    public function testTheRightPinDoesNotGetInWhileLocked(): void
    {
        $this->lockOut();

        $this->assertSame(LoginPinVerifier::LOCKED, $this->verifier->check(self::USER_ID, self::PIN)['result']);
    }

    public function testTheRightPinAfterTheLockEndsResetsTheCount(): void
    {
        $this->lockOut();
        $this->credDb->exec("UPDATE user_credentials SET locked_until = '" . date(DATE_ATOM, time() - 60) . "' WHERE user_id = " . self::USER_ID);

        $this->assertSame(LoginPinVerifier::OK, $this->verifier->check(self::USER_ID, self::PIN)['result']);

        $row = $this->credDb->query('SELECT failed_login_attempts, locked_until FROM user_credentials WHERE user_id = ' . self::USER_ID)->fetch();
        $this->assertSame(0, (int)$row['failed_login_attempts']);
        $this->assertNull($row['locked_until']);
    }

    public function testACorrectPinResetsEarlierMisses(): void
    {
        $this->verifier->check(self::USER_ID, '000000');
        $this->verifier->check(self::USER_ID, '000000');
        $this->verifier->check(self::USER_ID, self::PIN);

        // Four more misses are needed to lock it again, not three.
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(LoginPinVerifier::WRONG_PIN, $this->verifier->check(self::USER_ID, '000000')['result']);
        }
    }

    public function testNoCredentialIsNotASignIn(): void
    {
        $this->assertSame(LoginPinVerifier::NO_CREDENTIAL, $this->verifier->check(999, self::PIN)['result']);
    }

    public function testWithoutTheLockoutColumnsThePinIsStillChecked(): void
    {
        // A credentials database from before 2026_09_27_user_login_lockout.sql.
        SignInSchema::forget();
        $old = self::sqlite();
        $old->exec('CREATE TABLE user_credentials (user_id BIGINT PRIMARY KEY, password_hash VARCHAR(255) NOT NULL, created_at TEXT, updated_at TEXT)');
        $old->exec("INSERT INTO user_credentials (user_id, password_hash) VALUES (" . self::USER_ID . ", '" . self::hash(self::PIN) . "')");
        $verifier = new LoginPinVerifier(new CredentialsRepository($old));

        for ($i = 1; $i <= 6; $i++) {
            $this->assertSame(LoginPinVerifier::WRONG_PIN, $verifier->check(self::USER_ID, '000000')['result']);
        }
        $this->assertSame(LoginPinVerifier::OK, $verifier->check(self::USER_ID, self::PIN)['result']);
    }

    private function lockOut(): void
    {
        for ($i = 1; $i <= LoginPinVerifier::MAX_ATTEMPTS; $i++) {
            $this->verifier->check(self::USER_ID, '000000');
        }
    }

    private static function hash(string $pin): string
    {
        return password_hash($pin, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    private static function sqlite(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->sqliteCreateFunction('now', fn() => date('Y-m-d H:i:s'));
        return $db;
    }
}
