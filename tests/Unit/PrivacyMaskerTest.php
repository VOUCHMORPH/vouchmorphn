<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\PrivacyMasker;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Privacy Level Validation rules for the account preview: enough of the
 * holder's name to catch a wrong account, not enough to harvest a stranger's.
 */
class PrivacyMaskerTest extends TestCase
{
    public function testEachNamePartKeepsOnlyItsInitial(): void
    {
        $result = PrivacyMasker::maskHolderName('John Michael Doe');

        $this->assertTrue($result['valid']);
        $this->assertSame('J*** M****** D**', $result['masked']);
        $this->assertSame('John', $result['first']);
        $this->assertSame(['Michael'], $result['middle']);
        $this->assertSame('Doe', $result['last']);
    }

    public function testMiddleNameIsOptional(): void
    {
        $result = PrivacyMasker::maskHolderName('John Doe');

        $this->assertTrue($result['valid']);
        $this->assertSame('J*** D**', $result['masked']);
        $this->assertSame([], $result['middle'], 'no middle name is valid, not an error');
    }

    public function testSeveralMiddleNamesAreAllMasked(): void
    {
        $result = PrivacyMasker::maskHolderName('Ada Mary Grace King Lovelace');

        $this->assertTrue($result['valid']);
        $this->assertSame('A** M*** G**** K*** L*******', $result['masked']);
        $this->assertSame(['Mary', 'Grace', 'King'], $result['middle']);
        $this->assertSame('Lovelace', $result['last']);
    }

    public function testFirstAndLastNameAreRequired(): void
    {
        foreach (['Madonna', '   ', '', null] as $input) {
            $result = PrivacyMasker::maskHolderName($input);

            $this->assertFalse($result['valid'], 'a single name cannot be previewed');
            $this->assertNotNull($result['error']);
            $this->assertNull($result['masked'], 'nothing is shown when the name fails validation');
        }
    }

    public function testIrregularSpacingIsNormalisedNotRejected(): void
    {
        $result = PrivacyMasker::maskHolderName("  John\t Michael   Doe  ");

        $this->assertTrue($result['valid']);
        $this->assertSame('J*** M****** D**', $result['masked']);
    }

    public function testSingleLetterPartIsLeftAsIs(): void
    {
        $result = PrivacyMasker::maskHolderName('J Doe');

        $this->assertTrue($result['valid']);
        $this->assertSame('J D**', $result['masked'], 'there is nothing to mask in a one-letter part');
    }

    public function testNamesMatchOnFirstAndLastIgnoringCaseAndMiddle(): void
    {
        $this->assertTrue(PrivacyMasker::namesMatch('John Michael Doe', 'john doe'));
        $this->assertTrue(PrivacyMasker::namesMatch('JOHN DOE', 'John Michael Doe'));
    }

    public function testNamesMatchIgnoresAccentsAndPunctuation(): void
    {
        $this->assertTrue(PrivacyMasker::namesMatch("Renée O'Brien", 'Renee OBrien'));
    }

    public function testDifferentPeopleDoNotMatch(): void
    {
        $this->assertFalse(PrivacyMasker::namesMatch('John Doe', 'Jane Doe'));
        $this->assertFalse(PrivacyMasker::namesMatch('John Doe', 'John Smith'));
    }

    public function testAnUnverifiableNameNeverMatches(): void
    {
        $this->assertFalse(PrivacyMasker::namesMatch('Madonna', 'Madonna'));
        $this->assertFalse(PrivacyMasker::namesMatch(null, null));
    }
}
