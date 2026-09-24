<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * swap/execute.php must not run an identity claim (swap_type
 * CONFIRM_IDENTITY). That path, SwapService::confirmAndFinalizeIdentitySwap(),
 * takes the claimer's role and the agent's "document checked" flag from its
 * payload, which here is the request body: any logged-in user could finalize
 * a claim as an "agent", or try and lock an identity owner's transaction PIN.
 *
 * Sends real requests to the real endpoint on PHP's built-in server, logged
 * in through a real session file. The refusal comes before anything touches
 * a database, so none is needed, and the server is deliberately started
 * without DATABASE_URL. That is also how a request the endpoint does run
 * shows it got past the refusal: it fails at the database connection.
 */
class SwapExecuteClaimRefusalTest extends TestCase
{
    private const SESSION_ID = 'vmswapexecuteclaimrefusal01';
    private const REFUSED = 'Identity claims are finalized from the claim screen in the app, or by an agent';

    /** @var resource|null */
    private static $server = null;
    private static ?string $baseUrl = null;
    private static ?string $dir = null;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/vm_execute_refusal_' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        // A logged-in user, in PHP's default session format, as
        // SessionManager::loginUser() leaves one.
        file_put_contents(self::$dir . '/sess_' . self::SESSION_ID, implode('', [
            '_logged_in|' . serialize(true),
            '_last_activity|' . serialize(time()),
            'user|' . serialize(['id' => 7, 'username' => 'someone_else']),
        ]));

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return;
        }
        $port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $env = getenv();
        unset($env['DATABASE_URL'], $env['CREDENTIALS_DATABASE_URL']);
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
    }

    protected function setUp(): void
    {
        if (self::$baseUrl === null) {
            $this->markTestSkipped("Could not start PHP's built-in server on 127.0.0.1.");
        }
    }

    /** @return array{0: int, 1: array} HTTP status and the decoded JSON body */
    private function post(array $body, bool $loggedIn = true): array
    {
        $headers = ['Content-Type: application/json', 'X-Country-Code: BW'];
        if ($loggedIn) {
            $headers[] = 'Cookie: PHPSESSID=' . self::SESSION_ID;
        }
        $response = file_get_contents(self::$baseUrl . '/api/v1/swap/execute.php', false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($body),
            'ignore_errors' => true,
            'timeout' => 15,
        ]]));
        preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $status);
        return [(int)($status[1] ?? 0), json_decode((string)$response, true) ?? ['raw' => $response]];
    }

    private function assertRefusedAsAClaim(array $body): void
    {
        [$status, $json] = $this->post($body);
        $this->assertSame(400, $status, json_encode($json));
        $this->assertFalse($json['success']);
        $this->assertStringContainsString(self::REFUSED, $json['error']);
    }

    /** A claim on someone else's money, with everything the old path trusted. */
    private static function claim(array $overrides = []): array
    {
        return array_merge([
            'swap_type' => 'CONFIRM_IDENTITY',
            'swap_reference' => 'SWAP_123456789',
            'pin' => '111111',
            'confirmed_by_type' => 'agent',
            'confirmed_by_id' => 900,
            'identity_document_verified' => true,
            'destination_type' => 'DEPOSIT',
            'destination_institution' => 'ZURUBANK',
            'destination_identifier' => '1234567890',
        ], $overrides);
    }

    // ------------------------------------------------------------

    public function testTheSessionIsWhatGetsARequestThisFar(): void
    {
        [$status, $json] = $this->post(self::claim(), false);

        $this->assertSame(401, $status);
        $this->assertSame('Not logged in', $json['error']);
    }

    public function testAClaimAsAnAgentIsRefused(): void
    {
        $this->assertRefusedAsAClaim(self::claim());
    }

    public function testAClaimAsTheIdentitysOwnerIsRefused(): void
    {
        // As 'user', the old path checked the identity owner's transaction
        // PIN whoever sent the request, and counted a miss against it.
        $this->assertRefusedAsAClaim(self::claim([
            'confirmed_by_type' => 'user',
            'confirmed_by_id' => 42,
            'identity_document_verified' => null,
            'pin' => '482913',
        ]));
    }

    public function testAClaimIsRefusedWhateverTheCase(): void
    {
        $this->assertRefusedAsAClaim(self::claim(['swap_type' => 'confirm_identity']));
        $this->assertRefusedAsAClaim(self::claim(['swap_type' => ' Confirm_Identity ']));
    }

    public function testAClaimInsideAnEnvelopeIsRefused(): void
    {
        // executeAtomicSwap() runs original_payload in place of the request
        // when there is one, whatever the outer swap_type says.
        $this->assertRefusedAsAClaim(['swap_type' => 'DEPOSIT', 'original_payload' => self::claim()]);
    }

    public function testAnEnvelopeHasToBeAnObject(): void
    {
        [$status, $json] = $this->post(['swap_type' => 'DEPOSIT', 'original_payload' => 'CONFIRM_IDENTITY']);

        $this->assertSame(400, $status);
        $this->assertSame('original_payload must be an object', $json['error']);
    }

    public function testOtherSwapsStillGetPastTheRefusal(): void
    {
        // Refused later, at the database connection this server doesn't have.
        foreach (['DEPOSIT', 'IDENTITY'] as $swapType) {
            [$status, $json] = $this->post(['swap_type' => $swapType, 'original_payload' => ['swap_type' => $swapType]]);

            $this->assertSame(400, $status);
            $this->assertStringStartsWith('Database connection failed', $json['error']);
        }
    }
}
