<?php

use PHPUnit\Framework\TestCase;
use Application\Controllers\USSDController;
use Domain\Services\SwapService;
use Infrastructure\USSD\Contracts\UssdSessionRequest;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * On USSD the caller's phone number is their identity, and the source is
 * typed in. The webhook used to accept any request (so anyone could post any
 * phoneNumber), stored the raw phone string as the "user id", and sent
 * whatever account number was typed straight to the bank.
 *
 * These walk the real menu state machine (USSDController::processSession())
 * over an in-memory SQLite database, with SwapService replaced by a stub that
 * records what it would have executed.
 */
class UssdSourceOwnershipTest extends TestCase
{
    private const CALLER = '+26771234567';

    private PDO $db;
    private USSDController $controller;
    /** @var array<int, array> payloads the stub SwapService was asked to execute */
    public static array $executed = [];

    protected function setUp(): void
    {
        self::$executed = [];

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', fn () => gmdate('Y-m-d H:i:s'), 0);
        $this->db->exec('CREATE TABLE ussd_sessions (session_id TEXT, session_key TEXT, session_value TEXT, updated_at TEXT, PRIMARY KEY (session_id, session_key))');
        $this->db->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, phone TEXT, verified INTEGER)');
        $this->db->exec('CREATE TABLE user_source_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, institution TEXT, asset_type TEXT, identifier TEXT, identifier_type TEXT, status TEXT, deleted_at TEXT)');
        $this->db->exec("INSERT INTO users VALUES (1, '+26771234567', 1), (2, '+26772222222', 1)");
        $this->db->exec("INSERT INTO user_source_accounts (user_id, institution, asset_type, identifier, identifier_type, status) VALUES
            (1, 'ZURUBANK', 'ACCOUNT', '10000001', 'account_number', 'active'),
            (2, 'ZURUBANK', 'ACCOUNT', '10000002', 'account_number', 'active')");

        $swapStub = new class extends SwapService {
            public function __construct() {}
            public function executeAtomicSwap(array $payload): array
            {
                UssdSourceOwnershipTest::$executed[] = $payload;
                return ['status' => 'success', 'reference' => 'USSD_TEST_REF'];
            }
        };

        $reflection = new ReflectionClass(USSDController::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'db' => $this->db,
            'swapService' => $swapStub,
            'config' => ['country' => 'Botswana', 'country_code' => 'BW'],
            'participants' => ['ZURUBANK' => ['name' => 'ZuruBank', 'status' => 'ACTIVE'], 'MTN' => ['name' => 'MTN', 'status' => 'ACTIVE']],
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($this->controller, $value);
        }
    }

    /** Dial in and walk the menu: each entry is what the caller types at that step. */
    private function dial(string $phone, array $steps, string $sessionId = 'ussd-session-1'): array
    {
        $process = new ReflectionMethod(USSDController::class, 'processSession');
        $responses = [$process->invoke($this->controller, new UssdSessionRequest($sessionId, $phone, ''))];
        $typed = [];
        foreach ($steps as $step) {
            if (!$responses[count($responses) - 1]->continueSession) {
                break;
            }
            $typed[] = $step;
            $responses[] = $process->invoke($this->controller, new UssdSessionRequest($sessionId, $phone, implode('*', $typed)));
        }
        return $responses;
    }

    /** Cash swap, ZuruBank, bank account, <account>, P50, deposit to ZuruBank 99999999, PIN. */
    private static function depositFromAccount(string $account): array
    {
        return ['1', '1', '1', $account, '50', '2', '1', '99999999', '1234'];
    }

    public function testAnUnregisteredNumberCannotSwap(): void
    {
        $responses = $this->dial('+26770000000', self::depositFromAccount('10000001'));

        $this->assertFalse($responses[0]->continueSession);
        $this->assertStringContainsString('not registered with VouchMorph', $responses[0]->message);
        $this->assertSame([], self::$executed);
    }

    public function testSomeoneElsesAccountIsRefusedBeforeAnythingIsSent(): void
    {
        $last = $this->dial(self::CALLER, self::depositFromAccount('10000002'));
        $final = end($last);

        $this->assertFalse($final->continueSession);
        $this->assertStringContainsString('not one of your verified sources', $final->message);
        $this->assertSame([], self::$executed, 'nothing may reach SwapService for an account that is not the caller\'s');
    }

    public function testRandomCharactersAsTheAccountAreRefused(): void
    {
        $last = $this->dial(self::CALLER, self::depositFromAccount('ABC123XYZ'));

        $this->assertStringContainsString('not one of your verified sources', end($last)->message);
        $this->assertSame([], self::$executed);
    }

    public function testTheCallersOwnVerifiedAccountGoesThroughAsTheCaller(): void
    {
        $last = $this->dial(self::CALLER, self::depositFromAccount('1000-0001'));

        $this->assertStringContainsString('Swap successful', end($last)->message);
        $this->assertCount(1, self::$executed);
        $this->assertSame(1, self::$executed[0]['user_id'], 'SwapService gets the caller\'s user id, so it checks ownership too');
        $this->assertSame('10000001', self::$executed[0]['source_identifier'], 'the verified identifier is sent, not the typed one');
        $this->assertSame('ACCOUNT', self::$executed[0]['asset_type']);
    }

    public function testAWalletOnTheCallersOwnNumberIsTheirs(): void
    {
        // Cash swap, MTN, mobile wallet, own number, P20, deposit to ZuruBank 99999999, PIN.
        $last = $this->dial(self::CALLER, ['1', '2', '2', '71234567', '20', '2', '1', '99999999', '4321']);

        $this->assertStringContainsString('Swap successful', end($last)->message);
        $this->assertSame('WALLET', self::$executed[0]['asset_type']);
    }

    public function testAWalletOnSomeoneElsesNumberIsRefused(): void
    {
        $last = $this->dial(self::CALLER, ['1', '2', '2', '72222222', '20', '2', '1', '99999999', '4321']);

        $this->assertStringContainsString('not one of your verified sources', end($last)->message);
        $this->assertSame([], self::$executed);
    }

    public function testAVoucherGoesOutAsAVoucherWithItsPin(): void
    {
        // Cash swap, ZuruBank, voucher, number, P30, deposit to ZuruBank 99999999, voucher PIN.
        $this->dial(self::CALLER, ['1', '1', '3', 'VCH12345678', '30', '2', '1', '99999999', '5555']);

        $this->assertSame('VOUCHER', self::$executed[0]['asset_type'], 'vouchers used to be sent as WALLET');
        $this->assertSame('5555', self::$executed[0]['voucher_pin']);
    }

    public function testAnotherPhoneCannotContinueSomeoneElsesSession(): void
    {
        $this->dial(self::CALLER, ['1', '1', '1'], 'shared-session');

        $process = new ReflectionMethod(USSDController::class, 'processSession');
        $response = $process->invoke($this->controller, new UssdSessionRequest('shared-session', '+26772222222', '1*1*1*10000002'));

        $this->assertFalse($response->continueSession);
        $this->assertStringContainsString('Session expired', $response->message);
    }

    public function testOnlyTheGatewayMayCallTheWebhook(): void
    {
        $this->assertFalse(USSDController::isFromGateway([], ['key' => 'anything'], null), 'no secret configured: nothing is accepted');
        $this->assertFalse(USSDController::isFromGateway([], ['key' => 'anything'], ''), 'an empty secret is no secret');
        $this->assertFalse(USSDController::isFromGateway([], [], 's3cret-value'), 'no key sent');
        $this->assertFalse(USSDController::isFromGateway([], ['key' => 's3cret-valuX'], 's3cret-value'));
        $this->assertFalse(USSDController::isFromGateway([], ['key' => ['s3cret-value']], 's3cret-value'), 'an array is not a key');
        $this->assertTrue(USSDController::isFromGateway([], ['key' => 's3cret-value'], 's3cret-value'));
        $this->assertTrue(USSDController::isFromGateway(['HTTP_X_USSD_GATEWAY_SECRET' => 's3cret-value'], [], 's3cret-value'));
    }
}
