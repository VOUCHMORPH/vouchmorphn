<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Increment 2 of the swap-to-identity algorithm v2 build-out: locks in
 * SwapService::classifySourceAccountType()'s raw-bank-vocabulary mapping,
 * which the approved plan flags as a placeholder pending real sandbox
 * verifyAccount() responses for source-side accounts. A test here means a
 * later correction to the mapping is a deliberate, visible diff instead of
 * a silent behavior change.
 *
 * classifySourceAccountType() is stateless (no $this usage) and marked
 * `private static` specifically so it's reachable via reflection without
 * constructing the full SwapService dependency graph (DB, adapters,
 * settlement strategy, forex, ...) just to test a string-in/string-out
 * mapping.
 */
class SourceAccountClassificationTest extends TestCase
{
    private function classify(string $rawAccountType): string
    {
        $method = new \ReflectionMethod(SwapService::class, 'classifySourceAccountType');
        $method->setAccessible(true);
        return $method->invoke(null, $rawAccountType);
    }

    /** @dataProvider governmentTypesProvider */
    public function testGovernmentVariantsClassifyAsGovernment(string $raw): void
    {
        $this->assertSame('GOVERNMENT', $this->classify($raw));
    }

    public static function governmentTypesProvider(): array
    {
        return [
            ['GOVERNMENT'], ['government'], ['  Government  '],
            ['GOV'], ['STATE'], ['MUNICIPAL'], ['PARASTATAL'],
        ];
    }

    /** @dataProvider businessOrTrustTypesProvider */
    public function testBusinessAndTrustVariantsClassifyAsBusinessOrTrust(string $raw): void
    {
        $this->assertSame('BUSINESS_OR_TRUST', $this->classify($raw));
    }

    public static function businessOrTrustTypesProvider(): array
    {
        return [
            ['BUSINESS'], ['business'], ['TRUST'], ['CORPORATE'],
            ['COMPANY'], ['NGO'], ['NON_PROFIT'],
        ];
    }

    /** @dataProvider personalOrUnknownTypesProvider */
    public function testPersonalAndUnrecognizedTypesDefaultToPersonal(string $raw): void
    {
        $this->assertSame('PERSONAL', $this->classify($raw));
    }

    public static function personalOrUnknownTypesProvider(): array
    {
        return [
            ['PERSONAL'], ['INDIVIDUAL'], [''], ['SOMETHING_UNEXPECTED'], ['AGENT'], ['MERCHANT'],
        ];
    }
}
