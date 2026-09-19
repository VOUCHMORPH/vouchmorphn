<?php

use PHPUnit\Framework\TestCase;
use Core\Config\LoadCountry;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

/**
 * The fallback YAML parser, exercised directly.
 *
 * parseYamlFile() prefers ext-yaml and only reaches the built-in parser when
 * that is missing, so on a machine with ext-yaml installed the fallback would
 * never run under test -- which is exactly how it stayed broken. These call
 * it by reflection so it is always the code under test.
 *
 * What it used to do: match /^    ([a-z_]+): (.+)$/ and nothing else, so
 * every nested block and every list in participants.yaml was silently
 * dropped. An institution's settlement_account, identity_accounts,
 * capabilities and switch_participant_ids all disappeared, leaving nine
 * scalar fields and no sign anything was missing.
 */
class LoadCountryYamlParserTest extends TestCase
{
    private const BOTSWANA = __DIR__ . '/../../src/Core/Config/Countries/Botswana/participants.yaml';

    private function parse(string $path): array
    {
        $m = new \ReflectionMethod(LoadCountry::class, 'parseYamlManually');
        $m->setAccessible(true);
        return $m->invoke(null, $path);
    }

    private function parseString(string $yaml): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'yml');
        file_put_contents($tmp, $yaml);
        try {
            return $this->parse($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testTopLevelScalarsAndTheParticipantsMapArePresent(): void
    {
        $parsed = $this->parse(self::BOTSWANA);

        $this->assertSame('3.3.0', $parsed['version'] ?? null);
        $this->assertArrayHasKey('participants', $parsed);
        $this->assertArrayHasKey('ZURUBANK', $parsed['participants']);
    }

    /**
     * The regression that produced this test: these are the two blocks the
     * claim path reads, and the old parser dropped both.
     */
    public function testSettlementAndIdentityAccountBlocksSurvive(): void
    {
        $zuru = $this->parse(self::BOTSWANA)['participants']['ZURUBANK'];

        $this->assertSame('IDENTITY-SETTLEMENT', $zuru['settlement_account']['BWP']['identifier']);
        $this->assertSame('IDENTITY-RECEIVING', $zuru['identity_accounts']['BWP']['receiving_identifier']);
        $this->assertSame('IDENTITY-HOLDING', $zuru['identity_accounts']['BWP']['holding_identifier']);
    }

    public function testCapabilitiesParseAsBooleansNotStrings(): void
    {
        $caps = $this->parse(self::BOTSWANA)['participants']['ZURUBANK']['capabilities'];

        // getIdentityHoldingAccounts() does a truthiness check on this, and
        // the string "false" is truthy -- so the type matters.
        $this->assertTrue($caps['identity_holding']);
        $this->assertIsBool($caps['identity_holding']);
    }

    public function testOnboardingPlaceholdersArePreservedVerbatim(): void
    {
        // The placeholder guard matches on the literal text, so the parser
        // must not trim or transform it. ABSA is still unonboarded; the
        // in-scope institutions are configured.
        $absa = $this->parse(self::BOTSWANA)['participants']['ABSA'];

        $this->assertSame(
            'REPLACE_WITH_REAL_SETTLEMENT_ACCOUNT_NUMBER',
            $absa['settlement_account']['BWP']['identifier']
        );
    }

    /**
     * SACCUSSALIS's identifiers are its settlement_accounts.account_number
     * values, which are all digits. Quoted in the YAML precisely so they
     * stay strings -- an identifier silently becoming int 10000001 is the
     * kind of thing that survives every test until it reaches a bank.
     */
    public function testSaccussalisIdentifiersParseAsStringsNotIntegers(): void
    {
        $saccus = $this->parse(self::BOTSWANA)['participants']['SACCUSSALIS'];

        $settlement = $saccus['settlement_account']['BWP']['identifier'];
        $receiving = $saccus['identity_accounts']['BWP']['receiving_identifier'];
        $holding = $saccus['identity_accounts']['BWP']['holding_identifier'];

        $this->assertSame('10000001', $settlement);
        $this->assertSame('10000002', $receiving);
        $this->assertSame('10000003', $holding);

        foreach ([$settlement, $receiving, $holding] as $identifier) {
            $this->assertIsString($identifier);
        }
    }

    public function testBlockListsAndInlineListsBothParse(): void
    {
        $zuru = $this->parse(self::BOTSWANA)['participants']['ZURUBANK'];

        $this->assertSame(['ACCOUNT', 'VOUCHER', 'CARD'], $zuru['asset_types']);
        $this->assertSame(['DEBIT', 'CREDIT'], $zuru['card_config']['supported_card_types']);
    }

    public function testInlineMapsParse(): void
    {
        $saccus = $this->parse(self::BOTSWANA)['participants']['SACCUSSALIS'];

        $this->assertSame(
            ['BWP' => 'T+0', 'ZAR' => 'T+1', 'EUR' => 'T+2'],
            $saccus['cross_border']['settlement_cycles']
        );

        // The inline list on the line above it, for good measure -- the two
        // inline forms are parsed by different branches.
        $this->assertSame(
            ['BWP', 'ZAR', 'EUR', 'USD'],
            $saccus['cross_border']['supported_currencies']
        );
    }

    public function testCommentsAreStrippedWithoutEatingTheLineBelow(): void
    {
        // capabilities.claim_algorithm_v2 sits directly under a three-line
        // comment inside the block -- the old parser's line filter is the
        // kind of thing that swallows it.
        $caps = $this->parse(self::BOTSWANA)['participants']['ZURUBANK']['capabilities'];

        $this->assertTrue($caps['claim_algorithm_v2']);
    }

    public function testEveryCountryFileParsesToParticipants(): void
    {
        foreach (glob(__DIR__ . '/../../src/Core/Config/Countries/*/participants.yaml') as $file) {
            $parsed = $this->parse($file);
            $this->assertNotEmpty(
                $parsed['participants'] ?? [],
                basename(dirname($file)) . ' parsed to no participants'
            );
        }
    }

    public function testAHashInsideAValueIsNotTreatedAsAComment(): void
    {
        $parsed = $this->parseString(<<<YAML
            root:
              url: "https://example.test/path#fragment"
              bare: value#notacomment
              trailing: keep me   # but drop this
            YAML);

        $this->assertSame('https://example.test/path#fragment', $parsed['root']['url']);
        $this->assertSame('value#notacomment', $parsed['root']['bare']);
        $this->assertSame('keep me', $parsed['root']['trailing']);
    }

    public function testQuotedNumericStringsKeepTheirLeadingZeros(): void
    {
        // Account numbers are the whole point of this file. Losing a
        // leading zero silently changes where money goes.
        $parsed = $this->parseString(<<<YAML
            acct:
              quoted: "007123"
              unquoted: 7123
            YAML);

        $this->assertSame('007123', $parsed['acct']['quoted']);
        $this->assertSame(7123, $parsed['acct']['unquoted']);
    }

    public function testDeeperNestingIsNotFlattened(): void
    {
        $parsed = $this->parseString(<<<YAML
            a:
              b:
                c:
                  d: deep
            YAML);

        $this->assertSame('deep', $parsed['a']['b']['c']['d']);
    }

    /**
     * Where ext-yaml is available, the fallback must agree with it. Skipped
     * rather than failed where it is not, so the suite still runs.
     */
    public function testTheFallbackAgreesWithExtYamlWhereAvailable(): void
    {
        if (!function_exists('yaml_parse_file')) {
            $this->markTestSkipped('ext-yaml not installed; nothing to compare against');
        }

        $this->assertEquals(
            yaml_parse_file(self::BOTSWANA),
            $this->parse(self::BOTSWANA),
            'the fallback parser disagrees with ext-yaml'
        );
    }
}
