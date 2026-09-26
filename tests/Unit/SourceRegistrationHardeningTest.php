<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;
use Domain\Services\SourceOwnershipException;
use Infrastructure\Adapters\GenericInstitutionAdapter;
use Infrastructure\Adapters\InstitutionAdapterFactory;
use Infrastructure\Adapters\InstitutionAdapterInterface;
use Infrastructure\Banks\Contracts\BankAPIInterface;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * A source becomes spendable once it is verified, so how a source gets
 * verified is part of who can spend what. These pin the checks on that path:
 * what can be registered at all, how many wrong codes an attempt survives,
 * that a bank login completes only the attempt its own customer started,
 * and that an institution saying "no such account" is heard.
 *
 * SwapService runs its real registration code, built without its
 * constructor and wired by reflection (as in IdentityOwnerMatchingTest),
 * over an in-memory SQLite database; the institution is a mock adapter.
 */
class SourceRegistrationHardeningTest extends TestCase
{
    private PDO $db;
    private SwapService $service;
    /** @var GenericInstitutionAdapter&\PHPUnit\Framework\MockObject\MockObject */
    private GenericInstitutionAdapter $institution;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', fn () => gmdate('Y-m-d H:i:s'), 0);
        $this->createTables(true);

        // The concrete adapter: source linking (initiateSourceLink,
        // verifySourceLink) is not on InstitutionAdapterInterface.
        $this->institution = $this->createMock(GenericInstitutionAdapter::class);
        $factory = new class ([], null, $this->institution) extends InstitutionAdapterFactory {
            public function __construct(array $participants, $logger, private InstitutionAdapterInterface $adapter)
            {
                parent::__construct($participants, $logger);
            }
            public function getAdapter(string $institution): InstitutionAdapterInterface
            {
                return $this->adapter;
            }
        };

        $reflection = new ReflectionClass(SwapService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'swapDB' => $this->db,
            'config' => ['country' => 'Botswana', 'country_code' => 'BW'],
            'participants' => [
                'ZURUBANK' => ['capabilities' => ['source' => true]],
                'SACCUSSALIS' => ['capabilities' => ['source' => true]],
                'CENTRALSWITCH' => ['capabilities' => ['source' => false]],
            ],
            'adapterFactory' => $factory,
            'logger' => new class {
                public function __call($name, $arguments) {}
            },
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($this->service, $value);
        }
    }

    private function createTables(bool $withOtpCounter): void
    {
        $this->db->exec('DROP TABLE IF EXISTS user_source_registration_attempts');
        $this->db->exec('
            CREATE TABLE user_source_registration_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, institution TEXT, asset_type TEXT, identifier TEXT,
                identifier_type TEXT, account_name TEXT, oauth_state TEXT, bank_auth_id TEXT, otp_method TEXT,
                otp_expires_at TEXT, otp_supported INTEGER, status TEXT, created_at TEXT, completed_at TEXT, cancelled_at TEXT'
                . ($withOtpCounter ? ', otp_failed_attempts INTEGER NOT NULL DEFAULT 0' : '') . '
            )
        ');
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS user_source_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, institution TEXT, asset_type TEXT, identifier TEXT,
                identifier_type TEXT, account_name TEXT, currency TEXT, is_hooked TEXT, access_token TEXT, refresh_token TEXT,
                token_expires_at TEXT, source_reference TEXT, status TEXT, proposed_at TEXT, confirmed_at TEXT, updated_at TEXT,
                deleted_at TEXT
            )
        ');
    }

    private function addOtpAttempt(int $userId, int $failuresSoFar = 0): int
    {
        $expires = date('Y-m-d H:i:s', time() + 600);
        $this->db->exec("INSERT INTO user_source_registration_attempts (user_id, institution, asset_type, identifier, identifier_type, bank_auth_id, otp_expires_at, status)
            VALUES ({$userId}, 'SACCUSSALIS', 'ACCOUNT', '30000001', 'account_number', 'AUTH_OLD', '{$expires}', 'otp_pending')");
        $id = (int)$this->db->lastInsertId();
        if ($failuresSoFar > 0) {
            $this->db->exec("UPDATE user_source_registration_attempts SET otp_failed_attempts = {$failuresSoFar} WHERE id = {$id}");
        }
        return $id;
    }

    private function attempt(int $id): array
    {
        return $this->db->query("SELECT * FROM user_source_registration_attempts WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    }

    private function completeWith(int $userId, int $attemptId, string $otp): string
    {
        try {
            $this->service->completeUserSourceRegistration($userId, $attemptId, $otp);
            $this->fail('A wrong code must not verify a source');
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    // ------------------------------------------------------------
    // What can be registered
    // ------------------------------------------------------------

    public function testAnInstitutionVouchMorphDoesNotWorkWithIsRefused(): void
    {
        $this->expectExceptionMessage("'FOOBANK' is not an institution VouchMorph works with.");
        $this->service->initiateUserSourceRegistration(1, 'FOOBANK', 'ACCOUNT', '10000001', 'account_number');
    }

    public function testAnInstitutionThatCannotBeDebitedIsRefused(): void
    {
        $this->expectExceptionMessage('cannot be used as a source');
        $this->service->initiateUserSourceRegistration(1, 'CENTRALSWITCH', 'ACCOUNT', '10000001', 'account_number');
    }

    public function testRandomCharactersCannotBeRegisteredAsAnAccount(): void
    {
        $this->institution->expects($this->never())->method('initiateSourceLink');
        $this->expectException(SourceOwnershipException::class);
        $this->service->initiateUserSourceRegistration(1, 'ZURUBANK', 'ACCOUNT', 'asdf!!qwer', 'account_number');
    }

    public function testARealAccountStartsOwnershipVerificationAtItsInstitution(): void
    {
        $this->institution->expects($this->once())->method('initiateSourceLink')
            ->willReturn(['success' => true, 'auth_type' => 'otp', 'auth_id' => 'AUTH_1', 'expires_in' => 300]);

        $result = $this->service->initiateUserSourceRegistration(1, 'zurubank', 'ACCOUNT', ' 10000001 ', 'account_number');

        $this->assertTrue($result['requires_otp']);
        $row = $this->attempt($result['attempt_id']);
        $this->assertSame('ZURUBANK', $row['institution'], 'stored under the participant\'s own code');
        $this->assertSame('10000001', $row['identifier']);
    }

    // ------------------------------------------------------------
    // Wrong codes
    // ------------------------------------------------------------

    public function testFiveWrongCodesStopTheAttempt(): void
    {
        $this->institution->method('verifySourceLink')->willReturn(['success' => false, 'message' => 'Invalid OTP']);
        $id = $this->addOtpAttempt(1);

        $this->assertSame('Invalid OTP. 4 tries left.', $this->completeWith(1, $id, '000001'));
        $this->completeWith(1, $id, '000002');
        $this->completeWith(1, $id, '000003');
        $this->assertSame('Invalid OTP. 1 try left.', $this->completeWith(1, $id, '000004'));
        $this->assertStringContainsString('This verification has been stopped', $this->completeWith(1, $id, '000005'));

        $this->assertSame('failed', $this->attempt($id)['status']);
        $this->assertSame('Verification attempt not found.', $this->completeWith(1, $id, '000006'), 'a stopped attempt takes no more guesses');
    }

    public function testBeforeTheMigrationTheFirstWrongCodeStopsTheAttempt(): void
    {
        $this->createTables(false);
        $this->institution->method('verifySourceLink')->willReturn(['success' => false, 'message' => 'Invalid OTP']);
        $id = $this->addOtpAttemptWithoutCounter(1);

        $this->assertStringContainsString('This verification has been stopped', $this->completeWith(1, $id, '000001'));
        $this->assertSame('failed', $this->attempt($id)['status']);
    }

    private function addOtpAttemptWithoutCounter(int $userId): int
    {
        $expires = date('Y-m-d H:i:s', time() + 600);
        $this->db->exec("INSERT INTO user_source_registration_attempts (user_id, institution, asset_type, identifier, identifier_type, bank_auth_id, otp_expires_at, status)
            VALUES ({$userId}, 'SACCUSSALIS', 'ACCOUNT', '30000001', 'account_number', 'AUTH_OLD', '{$expires}', 'otp_pending')");
        return (int)$this->db->lastInsertId();
    }

    public function testSomeoneElsesAttemptCannotBeGuessedAt(): void
    {
        $this->institution->expects($this->never())->method('verifySourceLink');
        $id = $this->addOtpAttempt(2);

        $this->assertSame('Verification attempt not found.', $this->completeWith(1, $id, '123456'));
        $this->assertSame('0', (string)$this->attempt($id)['otp_failed_attempts']);
    }

    public function testANewCodeComesFromTheInstitutionAndMissesStillCount(): void
    {
        $this->institution->expects($this->once())->method('initiateSourceLink')
            ->willReturn(['success' => true, 'auth_type' => 'otp', 'auth_id' => 'AUTH_NEW', 'expires_in' => 300, 'message' => 'Code sent']);
        $id = $this->addOtpAttempt(1, 3);

        $result = $this->service->resendOtpForAttempt(1, $id);

        $this->assertTrue($result['success']);
        $row = $this->attempt($id);
        $this->assertSame('AUTH_NEW', $row['bank_auth_id'], 'the institution\'s new code is the one that will be checked');
        $this->assertSame('3', (string)$row['otp_failed_attempts'], 'a resend never resets the count');
    }

    // ------------------------------------------------------------
    // Bank logins (OAuth)
    // ------------------------------------------------------------

    public function testABankLoginCompletesOnlyTheAttemptItsOwnCustomerStarted(): void
    {
        $this->db->exec("INSERT INTO user_source_registration_attempts (user_id, institution, asset_type, identifier, identifier_type, oauth_state, status)
            VALUES (2, 'ZURUBANK', 'ACCOUNT', '10000002', 'account_number', 'STATE_OF_USER_2', 'oauth_pending')");
        $this->institution->expects($this->never())->method('verifySourceLink');

        try {
            $this->service->completeUserSourceRegistrationByState('STATE_OF_USER_2', 'code', 1);
            $this->fail('Another customer\'s bank login attempt must not complete');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('started from another VouchMorph account', $e->getMessage());
        }
        $this->assertSame('oauth_pending', $this->db->query("SELECT status FROM user_source_registration_attempts WHERE oauth_state = 'STATE_OF_USER_2'")->fetchColumn());
    }

    public function testABankLoginThatCannotUseTheTypedAccountVerifiesNothing(): void
    {
        $this->db->exec("INSERT INTO user_source_registration_attempts (user_id, institution, asset_type, identifier, identifier_type, oauth_state, status)
            VALUES (1, 'ZURUBANK', 'ACCOUNT', '10000002', 'account_number', 'STATE_1', 'oauth_pending')");
        $this->institution->method('verifySourceLink')->willReturn(['success' => true, 'authorized' => true, 'access_token' => 'TOKEN_OF_USER_1']);
        $this->institution->expects($this->once())->method('verifyAsset')
            ->with($this->callback(fn (array $p) => $p['access_token'] === 'TOKEN_OF_USER_1' && $p['source_identifier'] === '10000002'))
            ->willReturn(['verified' => false, 'message' => 'Account not accessible with this login']);

        try {
            $this->service->completeUserSourceRegistrationByState('STATE_1', 'code', 1);
            $this->fail('An account the bank login cannot use must not be verified');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not confirm that account for this bank login', $e->getMessage());
        }
        $this->assertSame('failed', $this->db->query("SELECT status FROM user_source_registration_attempts WHERE oauth_state = 'STATE_1'")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM user_source_accounts')->fetchColumn());
    }

    // ------------------------------------------------------------
    // Retrying
    // ------------------------------------------------------------

    public function testARejectedSourceCannotBeSentBackForReview(): void
    {
        $this->db->exec("INSERT INTO user_source_accounts (user_id, institution, asset_type, identifier, identifier_type, status) VALUES (1, 'ZURUBANK', 'ACCOUNT', '10000009', 'account_number', 'rejected')");
        $id = (int)$this->db->lastInsertId();

        try {
            $this->service->retryPendingSource(1, 'user_source', $id);
            $this->fail('A rejected source must not return to the review queue');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rejected after an ownership check', $e->getMessage());
        }
        $this->assertSame('rejected', $this->db->query("SELECT status FROM user_source_accounts WHERE id = {$id}")->fetchColumn());
    }

    // ------------------------------------------------------------
    // What an institution's "verified" means
    // ------------------------------------------------------------

    /** @dataProvider institutionAnswers */
    public function testAnInstitutionSayingNoSuchAccountIsHeard(array $answer, bool $confirmed): void
    {
        $this->assertSame($confirmed, GenericInstitutionAdapter::assetConfirmed($answer));
    }

    public static function institutionAnswers(): array
    {
        return [
            'plain success' => [['success' => true, 'data' => ['balance' => 12.5]], true],
            'active account' => [['success' => true, 'status' => 'ACTIVE'], true],
            'verified false at the top' => [['success' => true, 'verified' => false], false],
            'verified false one level down' => [['success' => true, 'data' => ['verified' => false]], false],
            'exists 0' => [['success' => true, 'exists' => 0], false],
            'not found status' => [['success' => true, 'status' => 'not_found'], false],
            'closed account one level down' => [['success' => true, 'data' => ['account_status' => 'Closed']], false],
            'nested failure' => [['success' => true, 'data' => ['success' => false, 'message' => 'Unknown account']], false],
        ];
    }

    public function testTheAdapterReportsANestedRefusalAsNotVerified(): void
    {
        $bank = $this->createMock(BankAPIInterface::class);
        $bank->method('verifyAssetSigned')->willReturn([
            'success' => true, 'verified' => true, 'status_code' => 200,
            'data' => ['success' => true, 'data' => ['verified' => false, 'message' => 'No such account']],
        ]);

        $result = (new GenericInstitutionAdapter($bank, null, 'ZURUBANK', []))->verifyAsset(['source_identifier' => 'asdf'], []);

        $this->assertFalse($result['verified']);
        $this->assertSame('No such account', $result['message']);
    }
}
