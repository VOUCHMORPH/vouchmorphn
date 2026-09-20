<?php
declare(strict_types=1);

namespace Tests\Sandbox\Security;

use Tests\Sandbox\Support\SandboxTestCase;

/**
 * Black-box security tests, run against a running deployment.
 *
 * These are READ-ONLY probes: they check authentication is enforced, that
 * debug surfaces are not exposed, and that standard security headers are
 * present. They never post a swap, move value, or write data.
 *
 * The target is taken from SANDBOX_TARGET_URL (e.g.
 * https://vouchmorphn-production.up.railway.app). With no target set every
 * test is skipped, so this file is safe to include in the default suite.
 *
 * They correspond to the Phase 3 penetration-test evidence (VM-TP-001
 * §8.4, VM-TD-001 Experiment 8) and to H6 (no data without authorisation).
 */
final class BlackBoxSecurityTest extends SandboxTestCase
{
    private function target(): string
    {
        $t = getenv('SANDBOX_TARGET_URL');
        if (!$t) {
            $this->markTestSkipped('SANDBOX_TARGET_URL not set; live security probe skipped.');
        }
        return rtrim($t, '/');
    }

    /** @return array{code:int, headers:array<string,string>, body:string} */
    private function http(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($this->target() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr((string) $raw, 0, $hsize);
        $bodyText = substr((string) $raw, $hsize);
        $headersOut = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headersOut[strtolower(trim($k))] = trim($v);
            }
        }
        return ['code' => $code, 'headers' => $headersOut, 'body' => $bodyText];
    }

    /** The swap execute endpoint must reject a request with no API key. */
    public function testSwapExecuteRejectsMissingApiKey(): void
    {
        $r = $this->http('POST', '/api/v1/swap/execute.php', ['Content-Type: application/json'], '{}');
        $this->assertContains($r['code'], [401, 403], 'execute.php must reject an unauthenticated POST');
    }

    /** It must also reject an obviously invalid API key (no env-var scraping). */
    public function testSwapExecuteRejectsInvalidApiKey(): void
    {
        $r = $this->http('POST', '/api/v1/swap/execute.php',
            ['Content-Type: application/json', 'X-API-Key: definitely-not-a-valid-key'], '{}');
        $this->assertContains($r['code'], [401, 403], 'execute.php must reject an invalid API key');
    }

    /** Admin JSON APIs must not serve data unauthenticated. */
    public function testAdminApisRequireAuth(): void
    {
        foreach (['/admin/api/get_swaps.php', '/admin/api/get_settlements.php', '/admin/api/get_metrics.php'] as $ep) {
            $r = $this->http('GET', $ep);
            $this->assertContains($r['code'], [401, 403, 302],
                "$ep must require authentication (got {$r['code']})");
        }
    }

    /**
     * FINDING F-005: unauthenticated debug/utility endpoints must not be
     * reachable in a regulated deployment. This test currently documents the
     * expected secure state; it will fail until these are removed or gated,
     * which is the point.
     */
    public function testDebugEndpointsAreNotPubliclyExposed(): void
    {
        $shouldNotBe200 = [
            '/admin/db_debug.php'          => 'leaks PHP environment inventory',
            '/admin/generate_pin_hash.php' => 'PIN-hash generation tool',
            '/api/test.php'                => 'card swipe test harness',
        ];
        $exposed = [];
        foreach ($shouldNotBe200 as $ep => $why) {
            $r = $this->http('GET', $ep);
            if ($r['code'] === 200) {
                $exposed[] = "$ep ($why)";
            }
        }
        $this->assertSame([], $exposed,
            "FINDING F-005: debug/utility endpoints reachable unauthenticated: " . implode('; ', $exposed));
    }

    /**
     * FINDING F-006: standard security headers should be present on
     * customer-facing pages. Documents the target state.
     */
    public function testSecurityHeadersPresent(): void
    {
        $r = $this->http('GET', '/user/login.php');
        $missing = [];
        foreach ([
            'strict-transport-security',
            'x-frame-options',
            'x-content-type-options',
            'content-security-policy',
        ] as $h) {
            if (!isset($r['headers'][$h])) {
                $missing[] = $h;
            }
        }
        $this->assertSame([], $missing,
            'FINDING F-006: missing security headers on /user/login.php: ' . implode(', ', $missing));
    }

    /** FINDING F-007: the exact PHP version should not be advertised. */
    public function testServerDoesNotLeakPhpVersion(): void
    {
        $r = $this->http('GET', '/user/login.php');
        $this->assertArrayNotHasKey('x-powered-by', $r['headers'],
            'FINDING F-007: X-Powered-By advertises the exact PHP version (' .
            ($r['headers']['x-powered-by'] ?? '') . ')');
    }

    /** Session cookies must be HttpOnly (they already are — this locks it in). */
    public function testSessionCookieIsHttpOnly(): void
    {
        $r = $this->http('GET', '/user/login.php');
        $cookie = $r['headers']['set-cookie'] ?? '';
        if ($cookie === '') {
            $this->markTestSkipped('No Set-Cookie on this response');
        }
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie,
            'Session cookie must be HttpOnly');
    }
}
