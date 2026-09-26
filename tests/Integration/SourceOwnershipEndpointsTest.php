<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * "When users hook to card, VouchMorph doesn't verify the account being
 * used - I can type random characters as the account number and it goes
 * through." Every customer endpoint that moves money or reveals a balance
 * now refuses a source the signed-in customer has not proved is theirs.
 *
 * Sends real requests to the real endpoints on PHP's built-in server, signed
 * in through real session files, against a real PostgreSQL database in a
 * throwaway schema. Every refusal here comes before any institution is
 * contacted, so no bank is needed; the one request that is allowed through
 * uses an institution with no participant config, so it stops at the first
 * bank call instead of reaching the network.
 *
 *     SOURCE_OWNERSHIP_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/SourceOwnershipEndpointsTest.php
 *
 * The server connects the way production does (DBConnection, sslmode=require),
 * so the PostgreSQL server must accept SSL; the schema is chosen with
 * PGOPTIONS.
 */
class SourceOwnershipEndpointsTest extends TestCase
{
    private const SCHEMA = 'source_ownership_e2e';
    private const CUSTOMER_SESSION = 'vmsourceownershipcustomer01';
    private const STRANGER_SESSION = 'vmsourceownershipstranger01';
    private const ADMIN_SESSION = 'vmsourceownershipadmin00001';
    private const NOT_YOURS = 'is not one of your verified sources';

    private static ?PDO $db = null;
    /** @var resource|null */
    private static $server = null;
    private static ?string $baseUrl = null;
    private static ?string $dir = null;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('SOURCE_OWNERSHIP_TEST_DATABASE_URL');
        if (!$url) {
            return;
        }
        $parts = parse_url($url);
        self::$db = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $parts['host'] ?? 'localhost', $parts['port'] ?? 5432, ltrim($parts['path'] ?? '', '/')),
            $parts['user'] ?? 'postgres',
            $parts['pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        self::$db->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        self::$db->exec('CREATE SCHEMA ' . self::SCHEMA);
        self::$db->exec('SET search_path TO ' . self::SCHEMA);
        self::createTables();

        self::$dir = sys_get_temp_dir() . '/vm_source_ownership_' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        // Signed-in sessions in PHP's default format, as SessionManager leaves them.
        $session = fn (array $user, string $type) => implode('', [
            '_logged_in|' . serialize(true),
            '_last_activity|' . serialize(time()),
            '_user_type|' . serialize($type),
            'user|' . serialize($user),
        ]);
        file_put_contents(self::$dir . '/sess_' . self::CUSTOMER_SESSION, $session(['id' => 1, 'username' => 'customer', 'phone' => '+26771234567'], 'user'));
        file_put_contents(self::$dir . '/sess_' . self::STRANGER_SESSION, $session(['id' => 3, 'username' => 'stranger'], 'user'));
        // Admin 1 shares user 1's numeric id: an admin session must not act as that customer.
        file_put_contents(self::$dir . '/sess_' . self::ADMIN_SESSION, $session(['id' => 1, 'username' => 'an_admin'], 'admin'));

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return;
        }
        $port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $env = getenv();
        $env['DATABASE_URL'] = $url;
        $env['PGOPTIONS'] = '-c search_path=' . self::SCHEMA;
        $env['PAN_HMAC_KEY'] = str_repeat('k', 48);
        unset($env['CREDENTIALS_DATABASE_URL'], $env['APP_ENV']);
        $log = self::$dir . '/server.log';
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__, 2) . '/public', '-d', 'session.save_path=' . self::$dir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname(__DIR__, 2),
            $env
        ) ?: null;

        for ($i = 0; self::$server !== null && $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);
                self::$baseUrl = "http://127.0.0.1:{$port}";
                return;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        if (self::$dir !== null) {
            array_map('unlink', glob(self::$dir . '/*') ?: []);
            @rmdir(self::$dir);
            self::$dir = null;
        }
        if (self::$db !== null) {
            self::$db->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('Set SOURCE_OWNERSHIP_TEST_DATABASE_URL to a throwaway PostgreSQL database (with SSL) to run this.');
        }
        if (self::$baseUrl === null) {
            $this->markTestSkipped("Could not start PHP's built-in server on 127.0.0.1.");
        }
    }

    // None of these tables has a tracked CREATE TABLE in this repository;
    // these are the columns the endpoints under test read and write.
    private static function createTables(): void
    {
        self::$db->exec("
            CREATE TABLE users (
                user_id SERIAL PRIMARY KEY, username TEXT, full_name TEXT, phone TEXT, phone2 TEXT, phone3 TEXT,
                email TEXT, national_id TEXT, verified BOOLEAN NOT NULL DEFAULT TRUE
            );
            CREATE TABLE user_source_accounts (
                id SERIAL PRIMARY KEY, user_id INT NOT NULL, institution TEXT NOT NULL, asset_type TEXT NOT NULL,
                identifier TEXT NOT NULL, identifier_type TEXT, account_name TEXT, currency TEXT DEFAULT 'BWP',
                is_hooked BOOLEAN DEFAULT FALSE, access_token TEXT, refresh_token TEXT, token_expires_at TIMESTAMP,
                source_reference TEXT, status TEXT NOT NULL, proposed_at TIMESTAMP DEFAULT NOW(), confirmed_at TIMESTAMP,
                updated_at TIMESTAMP, last_used_at TIMESTAMP, deleted_at TIMESTAMP
            );
            CREATE TABLE user_source_registration_attempts (
                id SERIAL PRIMARY KEY, user_id INT NOT NULL, institution TEXT, asset_type TEXT, identifier TEXT,
                identifier_type TEXT, account_name TEXT, oauth_state TEXT, bank_auth_id TEXT, otp_method TEXT,
                otp_expires_at TIMESTAMP, otp_supported BOOLEAN, status TEXT, created_at TIMESTAMP DEFAULT NOW(),
                completed_at TIMESTAMP, cancelled_at TIMESTAMP, otp_failed_attempts INT NOT NULL DEFAULT 0
            );
            CREATE TABLE message_cards (
                card_id SERIAL PRIMARY KEY, card_suffix TEXT NOT NULL, user_id INT NOT NULL, lifecycle_status TEXT NOT NULL,
                currency TEXT DEFAULT 'BWP', card_scheme TEXT, cardholder_name TEXT
            );
            CREATE TABLE card_pool_hooks (
                id SERIAL PRIMARY KEY, hook_reference TEXT NOT NULL, card_suffix TEXT NOT NULL, user_id INT NOT NULL,
                total_held_amount NUMERIC(14,2) NOT NULL, currency TEXT NOT NULL DEFAULT 'BWP', status TEXT NOT NULL,
                expires_at TIMESTAMP NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT NOW()
            );
            CREATE TABLE card_pool_hook_sources (
                id SERIAL PRIMARY KEY, hook_id INT NOT NULL, owner_user_id INT NOT NULL, institution TEXT NOT NULL,
                asset_type TEXT NOT NULL, source_identifier TEXT NOT NULL, held_amount NUMERIC(14,2) NOT NULL,
                hold_reference TEXT, status TEXT NOT NULL
            );
            CREATE TABLE payment_requests (
                id SERIAL PRIMARY KEY, status TEXT NOT NULL, expires_at TIMESTAMP NOT NULL, net_amount NUMERIC(14,2),
                currency TEXT, destination_institution TEXT, destination_identifier TEXT, destination_asset_type TEXT,
                swap_reference TEXT, completed_at TIMESTAMP
            );

            INSERT INTO users (user_id, username, phone) VALUES
                (1, 'customer', '+26771234567'), (2, 'victim', '+26772222222'), (3, 'stranger', '+26773333333');
            INSERT INTO user_source_accounts (user_id, institution, asset_type, identifier, identifier_type, status) VALUES
                (1, 'ZURUBANK', 'ACCOUNT', '10000001', 'account_number', 'active'),
                (1, 'NOBANK',   'ACCOUNT', '55500001', 'account_number', 'active'),
                (1, 'ABSA',     'ACCOUNT', '20000001', 'account_number', 'pending_confirmation'),
                (2, 'ZURUBANK', 'ACCOUNT', '10000002', 'account_number', 'active');
            INSERT INTO user_source_registration_attempts (user_id, institution, asset_type, identifier, identifier_type, bank_auth_id, otp_expires_at, status)
                VALUES (2, 'SACCUSSALIS', 'ACCOUNT', '30000002', 'account_number', 'AUTH_X', NOW() + INTERVAL '1 day', 'otp_pending');
            INSERT INTO message_cards (card_suffix, user_id, lifecycle_status) VALUES ('4821', 1, 'ACTIVE');
            INSERT INTO card_pool_hooks (hook_reference, card_suffix, user_id, total_held_amount, status, expires_at)
                VALUES ('HOOK_E2E', '4821', 1, 100, 'HOOKED', NOW() + INTERVAL '1 day');
            INSERT INTO card_pool_hook_sources (hook_id, owner_user_id, institution, asset_type, source_identifier, held_amount, hold_reference, status)
                VALUES (1, 1, 'ZURUBANK', 'ACCOUNT', '10000001', 100, 'HOLD_E2E', 'HELD');
            INSERT INTO payment_requests (status, expires_at, net_amount, currency, destination_institution, destination_identifier, destination_asset_type)
                VALUES ('PENDING', NOW() + INTERVAL '1 day', 25, 'BWP', 'ZURUBANK', '99999999', 'ACCOUNT');
        ");
    }

    /** @return array{0: int, 1: array} HTTP status and the decoded JSON body */
    private function post(string $path, array $body, ?string $session = self::CUSTOMER_SESSION): array
    {
        $headers = ['Content-Type: application/json', 'X-Country-Code: BW'];
        if ($session !== null) {
            $headers[] = 'Cookie: PHPSESSID=' . $session;
        }
        $response = file_get_contents(self::$baseUrl . $path, false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($body),
            'ignore_errors' => true,
            'timeout' => 60,
        ]]));
        preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $status);
        return [(int)($status[1] ?? 0), json_decode((string)$response, true) ?? ['raw' => $response]];
    }

    private function hook(array $source, ?string $session = self::CUSTOMER_SESSION): array
    {
        return $this->post('/api/v1/cards/hook.php', [
            'card_suffix' => '4821',
            'sources' => [$source + ['authorized_amount' => 50]],
        ], $session);
    }

    private function heldSourceCount(): int
    {
        return (int)self::$db->query('SELECT COUNT(*) FROM card_pool_hook_sources')->fetchColumn();
    }

    // ------------------------------------------------------------
    // Hooking to a card
    // ------------------------------------------------------------

    public function testHookingRandomCharactersIsRefusedAndNothingIsHeld(): void
    {
        [$status, $json] = $this->hook(['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => 'asdfghjkl']);

        $this->assertSame(422, $status, json_encode($json));
        $this->assertFalse($json['success']);
        $this->assertStringContainsString(self::NOT_YOURS, $json['error']);
        $this->assertSame(1, $this->heldSourceCount());
    }

    public function testHookingSomeoneElsesAccountIsRefused(): void
    {
        [$status, $json] = $this->hook(['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '10000002']);

        $this->assertSame(422, $status, json_encode($json));
        $this->assertStringContainsString(self::NOT_YOURS, $json['error']);
        $this->assertSame(1, $this->heldSourceCount());
    }

    public function testNamingSomeoneElseAsTheSourceOwnerIsRefused(): void
    {
        [$status, $json] = $this->hook(['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '10000002', 'owner_user_id' => 2]);

        $this->assertSame(403, $status, json_encode($json));
        $this->assertStringContainsString('You can only hook your own sources', $json['error']);
    }

    public function testASourceAwaitingItsOwnershipCheckCannotBeHooked(): void
    {
        [$status, $json] = $this->hook(['institution' => 'ABSA', 'asset_type' => 'ACCOUNT', 'identifier' => '20000001']);

        $this->assertSame(422, $status, json_encode($json));
        $this->assertStringContainsString('still waiting for its ownership check', $json['error']);
    }

    public function testAnAdminSessionCannotHookACustomersSource(): void
    {
        [$status, $json] = $this->hook(['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '10000001'], self::ADMIN_SESSION);

        $this->assertSame(403, $status, json_encode($json));
        $this->assertSame(1, $this->heldSourceCount());
    }

    public function testYourOwnVerifiedSourceGetsPastTheOwnershipCheck(): void
    {
        // NOBANK has no participant config, so the hook stops at the first
        // institution call (the balance check) instead of reaching a bank.
        [$status, $json] = $this->hook(['institution' => 'NOBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '555-00001']);

        $this->assertSame(422, $status, json_encode($json));
        $this->assertStringNotContainsString(self::NOT_YOURS, $json['error']);
        $this->assertStringContainsString('NOBANK has no available balance', $json['error']);
    }

    // ------------------------------------------------------------
    // Swapping, previewing, paying
    // ------------------------------------------------------------

    public function testASwapFromSomeoneElsesAccountIsRefusedBeforeRouting(): void
    {
        [$status, $json] = $this->post('/api/v1/swap/execute.php', [
            'swap_type' => 'DEPOSIT', 'from_institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'source_identifier' => '10000002',
            'amount' => 50, 'currency' => 'BWP', 'to_institution' => 'ZURUBANK', 'destination_identifier' => '99999999',
        ]);

        $this->assertSame(403, $status, json_encode($json));
        $this->assertStringContainsString(self::NOT_YOURS, $json['error']);
    }

    public function testSomeoneElsesAccountInsideAnEnvelopeIsRefused(): void
    {
        [$status, $json] = $this->post('/api/v1/swap/execute.php', [
            'swap_type' => 'DEPOSIT', 'from_institution' => 'ZURUBANK', 'source_identifier' => '10000001', 'amount' => 1,
            'original_payload' => [
                'swap_type' => 'DEPOSIT', 'from_institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'source_identifier' => '10000002',
                'amount' => 6000, 'currency' => 'BWP', 'to_institution' => 'ZURUBANK', 'destination_identifier' => '99999999',
            ],
        ]);

        $this->assertSame(403, $status, json_encode($json));
        $this->assertStringContainsString('ZURUBANK account ending 0002', $json['error']);
    }

    public function testCardIssuanceCannotBeStartedFromTheApp(): void
    {
        [$status, $json] = $this->post('/api/v1/swap/execute.php', ['swap_type' => 'card_issue', 'hold_reference' => 'HOLD_E2E']);

        $this->assertSame(400, $status, json_encode($json));
        $this->assertStringContainsString("can't be started from the app", $json['error']);
    }

    public function testYourOwnVerifiedSourceGetsPastTheOwnershipCheckOnASwap(): void
    {
        [$status, $json] = $this->post('/api/v1/swap/execute.php', [
            'swap_type' => 'DEPOSIT', 'from_institution' => 'NOBANK', 'asset_type' => 'ACCOUNT', 'source_identifier' => '55500001',
            'amount' => 50, 'currency' => 'BWP', 'to_institution' => 'ZURUBANK', 'destination_identifier' => '99999999',
        ]);

        $this->assertNotSame(200, $status, json_encode($json));
        $this->assertStringNotContainsString(self::NOT_YOURS, $json['error'] ?? '');
        $this->assertStringContainsString('NOBANK', $json['error'] ?? '');
    }

    public function testAPreviewDoesNotRevealSomeoneElsesBalance(): void
    {
        [$status, $json] = $this->post('/api/v1/swap/preview.php', [
            'swap_type' => 'DEPOSIT', 'from_institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'source_identifier' => '10000002',
            'amount' => 50, 'to_institution' => 'ZURUBANK',
        ]);

        $this->assertSame(403, $status, json_encode($json));
        $this->assertStringContainsString(self::NOT_YOURS, $json['error']);
        $this->assertArrayNotHasKey('preview', $json);
    }

    public function testHookAdviceDoesNotRevealSomeoneElsesBalance(): void
    {
        [$status, $json] = $this->post('/api/v1/cards/GetHookAdvice.php', ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '10000002']);

        $this->assertSame(403, $status, json_encode($json));
        $this->assertArrayNotHasKey('data', $json);
    }

    public function testAPaymentRequestCannotBePaidFromSomeoneElsesAccount(): void
    {
        [$status, $json] = $this->post('/api/v1/payments/execute.php', [
            'request_id' => 1, 'source' => ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '10000002'],
        ]);

        $this->assertSame(403, $status, json_encode($json));
        $this->assertStringContainsString(self::NOT_YOURS, $json['error']);
        $this->assertSame('PENDING', self::$db->query('SELECT status FROM payment_requests WHERE id = 1')->fetchColumn());
    }

    // ------------------------------------------------------------
    // Reading a card's sources; verifying a source
    // ------------------------------------------------------------

    public function testAStrangerCannotListTheSourcesOnYourCard(): void
    {
        [$status, $json] = $this->post('/api/v1/cards/GetCardSources.php', ['card_suffix' => '4821'], self::STRANGER_SESSION);

        $this->assertSame(403, $status, json_encode($json));
        $this->assertStringNotContainsString('10000001', json_encode($json));
    }

    public function testTheCardOwnerStillSeesTheirCardsSources(): void
    {
        [$status, $json] = $this->post('/api/v1/cards/GetCardSources.php', ['card_suffix' => '4821']);

        $this->assertSame(200, $status, json_encode($json));
        $this->assertSame('10000001', $json['data']['sources'][0]['identifier']);
    }

    public function testVerifyingASourceNeedsASignedInCustomer(): void
    {
        [$status, $json] = $this->post('/user/verify_source.php', ['attempt_id' => 1, 'otp' => '123456', 'user_id' => 2], null);

        $this->assertSame(401, $status, json_encode($json));
    }

    public function testYouCannotCompleteSomeoneElsesSourceVerification(): void
    {
        // The attempt is user 2's; user_id in the body used to be believed.
        [$status, $json] = $this->post('/user/verify_source.php', ['attempt_id' => 1, 'otp' => '123456', 'user_id' => 2]);

        $this->assertSame(400, $status, json_encode($json));
        $this->assertSame('Verification attempt not found.', $json['error']);
        $this->assertSame('otp_pending', self::$db->query('SELECT status FROM user_source_registration_attempts WHERE id = 1')->fetchColumn());
    }
}
