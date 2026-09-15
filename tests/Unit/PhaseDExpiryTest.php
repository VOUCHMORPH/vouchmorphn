<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Increment 4 of the swap-to-identity algorithm v2 build-out: locks in
 * the two pure decision helpers cancelExpiredIdentitySwaps()'s rewrite
 * (expireIdentitySwap()) depends on. Both are `private static` (no $this
 * usage) specifically so they're reachable via reflection without
 * constructing the full SwapService dependency graph.
 */
class PhaseDExpiryTest extends TestCase
{
    private function invokeStatic(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod(SwapService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $args);
    }

    // ------------------------------------------------------------
    // extractHoldFeeFromMetadata()
    // ------------------------------------------------------------

    public function testExtractsHoldFeeFromRealMetadata(): void
    {
        $metadata = json_encode(['hold_fee' => ['total_fee' => 1.00, 'swap_levy' => 1.00]]);
        $this->assertSame(1.00, $this->invokeStatic('extractHoldFeeFromMetadata', [$metadata]));
    }

    public function testWaivedHoldFeeReadsAsZeroRegardlessOfStoredAmount(): void
    {
        // A hold fee can be nonzero in total_fee but flagged waived by
        // waiveIdentityHoldFee() (VouchMorph system failure case) -- must
        // never be re-withheld on expiry.
        $metadata = json_encode(['hold_fee' => ['total_fee' => 1.00, 'waived' => true, 'waived_reason' => 'system failure']]);
        $this->assertSame(0.0, $this->invokeStatic('extractHoldFeeFromMetadata', [$metadata]));
    }

    public function testMissingHoldFeeKeyReadsAsZero(): void
    {
        $this->assertSame(0.0, $this->invokeStatic('extractHoldFeeFromMetadata', [json_encode(['signed_payloads' => []])]));
    }

    public function testNullOrEmptyMetadataReadsAsZero(): void
    {
        $this->assertSame(0.0, $this->invokeStatic('extractHoldFeeFromMetadata', [null]));
        $this->assertSame(0.0, $this->invokeStatic('extractHoldFeeFromMetadata', ['']));
        $this->assertSame(0.0, $this->invokeStatic('extractHoldFeeFromMetadata', ['not valid json']));
    }

    // ------------------------------------------------------------
    // isSourceMoneyOwedToIdentity()
    // ------------------------------------------------------------

    public function testGovernmentAndBusinessOrTrustAreOwedToIdentity(): void
    {
        $this->assertTrue($this->invokeStatic('isSourceMoneyOwedToIdentity', ['GOVERNMENT']));
        $this->assertTrue($this->invokeStatic('isSourceMoneyOwedToIdentity', ['BUSINESS_OR_TRUST']));
    }

    /** @dataProvider notOwedProvider */
    public function testPersonalAndUnrecognizedAreNotOwedToIdentity(?string $sourceAccountType): void
    {
        $this->assertFalse($this->invokeStatic('isSourceMoneyOwedToIdentity', [$sourceAccountType]));
    }

    public static function notOwedProvider(): array
    {
        return [
            ['PERSONAL'],
            [null], // legacy rows created before this column existed
            [''],
            ['government'], // classifySourceAccountType() output is always uppercase; a raw lowercase value here is not that method's output
            ['SOMETHING_UNEXPECTED'],
        ];
    }
}
