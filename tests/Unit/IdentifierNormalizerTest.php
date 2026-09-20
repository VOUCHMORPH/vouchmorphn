<?php

use PHPUnit\Framework\TestCase;
use Domain\Identity\IdentifierNormalizer;
use Domain\Identity\UserIdentifierLookup;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * The rule behind "User not found although they made the account": the
 * same person, typing the same number two different ways, used to become
 * two different strings — one written at sign-up, another looked up at
 * sign-in. These tests pin the canonical shape, and pin that a lookup
 * still reaches rows written in the older shapes.
 */
class IdentifierNormalizerTest extends TestCase
{
    private const DIAL = '+267';
    private const LOCAL_LENGTH = 8;

    /**
     * @dataProvider phoneShapes
     */
    public function testEveryWayOfTypingOneNumberCanonicalisesTheSame(string $typed): void
    {
        $this->assertSame(
            '+26771234567',
            IdentifierNormalizer::canonicalPhone($typed, self::DIAL, self::LOCAL_LENGTH),
            "'{$typed}' is the same phone number and must normalise to one value"
        );
    }

    public static function phoneShapes(): array
    {
        return [
            'local'                  => ['71234567'],
            'local with trunk zero'  => ['071234567'],
            'spaced'                 => ['71 234 567'],
            'e164'                   => ['+26771234567'],
            'e164 spaced'            => ['+267 71 234 567'],
            'country code no plus'   => ['26771234567'],
            'international prefix'   => ['0026771234567'],
            'country code twice'     => ['+26726771234567'],
            'dashes and brackets'    => ['(+267) 71-234-567'],
        ];
    }

    public function testRegisteringOneWayAndSigningInAnotherFindsTheSameAccount(): void
    {
        // The exact reported failure: typed the full number at sign-up,
        // the local part at sign-in. The old normalizePhone() stored
        // "+26726771234567" and then looked up "+26771234567".
        $stored = IdentifierNormalizer::canonicalPhone('26771234567', self::DIAL, self::LOCAL_LENGTH);
        $typedAtLogin = IdentifierNormalizer::canonicalPhone('71234567', self::DIAL, self::LOCAL_LENGTH);

        $this->assertSame($stored, $typedAtLogin);
    }

    public function testALocalNumberBeginningWithTheCountryCodeIsNotEatenIntoIt(): void
    {
        // 26712345 is a valid 8-digit local number that happens to start
        // with 267. Stripping it as a country code would corrupt it.
        $this->assertSame(
            '+26726712345',
            IdentifierNormalizer::canonicalPhone('26712345', self::DIAL, self::LOCAL_LENGTH)
        );
    }

    public function testCanonicalisingTwiceChangesNothing(): void
    {
        $once = IdentifierNormalizer::canonicalPhone('26771234567', self::DIAL, self::LOCAL_LENGTH);
        $twice = IdentifierNormalizer::canonicalPhone($once, self::DIAL, self::LOCAL_LENGTH);

        $this->assertSame($once, $twice, 'normalisation must be idempotent');
    }

    public function testEmptyAndJunkInputsYieldNoNumber(): void
    {
        $this->assertSame('', IdentifierNormalizer::canonicalPhone('', self::DIAL, self::LOCAL_LENGTH));
        $this->assertSame('', IdentifierNormalizer::canonicalPhone('   ', self::DIAL, self::LOCAL_LENGTH));
        $this->assertSame('', IdentifierNormalizer::canonicalPhone('0', self::DIAL, self::LOCAL_LENGTH));
        $this->assertSame('', IdentifierNormalizer::canonicalPhone('not a phone', self::DIAL, self::LOCAL_LENGTH));
    }

    public function testVariantsCoverTheShapesAlreadyInTheTable(): void
    {
        $variants = IdentifierNormalizer::phoneVariants('71234567', self::DIAL, self::LOCAL_LENGTH);

        // What each historical writer could have left behind.
        $this->assertContains('+26771234567', $variants, 'canonical E.164');
        $this->assertContains('26771234567', $variants, 'no plus, as in the legacy dumps');
        $this->assertContains('71234567', $variants, 'bare local');
        $this->assertContains('071234567', $variants, 'local with trunk zero');
        $this->assertContains('+26726771234567', $variants, 'country code applied twice by the old normalizePhone');
        $this->assertSame(array_unique($variants), $variants, 'no duplicate bound values');
        $this->assertSame('+26771234567', $variants[0], 'canonical form is offered first');
    }

    public function testVariantsAreTheSameSetWhicheverShapeIsTyped(): void
    {
        $typedFull = '+267 71 234 567';

        $fromLocal = IdentifierNormalizer::phoneVariants('71234567', self::DIAL, self::LOCAL_LENGTH);
        // The list also carries the literal typed string, which only
        // differs because of the spacing; compare the generated shapes.
        $fromFull = array_diff(
            IdentifierNormalizer::phoneVariants($typedFull, self::DIAL, self::LOCAL_LENGTH),
            [$typedFull]
        );

        sort($fromLocal);
        $fromFull = array_values($fromFull);
        sort($fromFull);

        $this->assertSame(
            $fromLocal,
            $fromFull,
            'the same number typed either way must look in the same places'
        );
    }

    public function testNationalPartDropsCountryCodeAndTrunkZero(): void
    {
        $this->assertSame('71234567', IdentifierNormalizer::nationalPhonePart('+26771234567', self::DIAL, self::LOCAL_LENGTH));
        $this->assertSame('71234567', IdentifierNormalizer::nationalPhonePart('071234567', self::DIAL, self::LOCAL_LENGTH));
    }

    public function testEmailIsCaseFoldedAndTrimmed(): void
    {
        // What a phone keyboard autocapitalises must still match the
        // lowercase address registration stored.
        $this->assertSame('jane@example.com', IdentifierNormalizer::canonicalEmail('  Jane@Example.com '));
    }

    public function testDocumentNumbersIgnoreCaseAndPunctuation(): void
    {
        $this->assertSame('CM123456', IdentifierNormalizer::canonicalDocument('cm-123 456'));
        $this->assertSame('CM123456', IdentifierNormalizer::canonicalDocument('CM123456'));
        $this->assertSame('CM123456', IdentifierNormalizer::canonicalDocument(' cm.123.456 '));
    }

    public function testPhoneMatchingIsSkippedForThingsThatAreNotPhones(): void
    {
        $this->assertTrue(IdentifierNormalizer::looksLikePhone('+267 71 234 567'));
        $this->assertTrue(IdentifierNormalizer::looksLikePhone('071234567'));
        $this->assertFalse(IdentifierNormalizer::looksLikePhone('jane@example.com'));
        $this->assertFalse(IdentifierNormalizer::looksLikePhone('CM123456'));
        $this->assertFalse(IdentifierNormalizer::looksLikePhone(''));
    }

    public function testCanonicaliseByIdentifierType(): void
    {
        $this->assertSame(
            '+26771234567',
            IdentifierNormalizer::canonicalize('phone', '26771234567', self::DIAL, self::LOCAL_LENGTH)
        );
        $this->assertSame(
            'jane@example.com',
            IdentifierNormalizer::canonicalize('email', 'Jane@Example.com', self::DIAL, self::LOCAL_LENGTH)
        );
        $this->assertSame(
            'CM123456',
            IdentifierNormalizer::canonicalize('national_id', ' CM123456 ', self::DIAL, self::LOCAL_LENGTH)
        );
    }

    public function testLookupMatchesPhoneVariantsEmailCaseInsensitivelyAndDocuments(): void
    {
        [$where, $params] = UserIdentifierLookup::buildMatch('71234567', self::DIAL, self::LOCAL_LENGTH);
        $sql = implode(' OR ', $where);

        $this->assertStringContainsString('phone IN (', $sql);
        $this->assertStringContainsString('phone2 IN (', $sql);
        $this->assertStringContainsString('phone3 IN (', $sql);
        $this->assertStringContainsString('lower(email) =', $sql);
        $this->assertStringContainsString("upper(regexp_replace(national_id, '[^A-Za-z0-9]', '', 'g'))", $sql);
        $this->assertContains('+26771234567', $params, 'the canonical number is among the bound values');
        $this->assertContains('26771234567', $params, 'so is the no-plus shape already in the table');
    }

    public function testEveryBoundPlaceholderIsUniqueAndAppearsOnceInTheSql(): void
    {
        // This connection runs with prepare emulation off, where reusing
        // one named placeholder in several places is not allowed.
        [$where, $params] = UserIdentifierLookup::buildMatch('71234567', self::DIAL, self::LOCAL_LENGTH);
        $sql = implode(' OR ', $where);

        $this->assertSame(count($params), count(array_unique(array_keys($params))));
        foreach (array_keys($params) as $placeholder) {
            $this->assertSame(
                1,
                preg_match_all('/' . preg_quote($placeholder, '/') . '\b/', $sql),
                "{$placeholder} must appear exactly once"
            );
        }
    }

    public function testSeparatePrefixesKeepTwoIdentifiersFromColliding(): void
    {
        // The sign-up duplicate check folds several identifiers into one
        // query; their placeholders must not overwrite each other.
        [, $first]  = UserIdentifierLookup::buildMatch('71234567', self::DIAL, self::LOCAL_LENGTH, 'c0');
        [, $second] = UserIdentifierLookup::buildMatch('jane@example.com', self::DIAL, self::LOCAL_LENGTH, 'c1');

        $this->assertSame([], array_intersect_key($first, $second));
    }

    public function testAnEmptyIdentifierMatchesNothing(): void
    {
        [$where, $params] = UserIdentifierLookup::buildMatch('   ', self::DIAL, self::LOCAL_LENGTH);

        $this->assertSame([], $where);
        $this->assertSame([], $params);
    }
}
