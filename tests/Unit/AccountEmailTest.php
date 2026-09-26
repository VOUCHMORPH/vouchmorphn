<?php

use PHPUnit\Framework\TestCase;
use Domain\Identity\AccountEmail;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Which email on a users row may sign in: only a verified one, never the
 * made-up address a phone-only sign-up is given.
 */
class AccountEmailTest extends TestCase
{
    public function testRecognisesTheMadeUpAddresses(): void
    {
        $this->assertTrue(AccountEmail::isPlaceholder('thabo@botswana.vouchmorphn.com'));
        $this->assertTrue(AccountEmail::isPlaceholder(' Thabo123@Botswana.VouchMorphn.com '));
        $this->assertFalse(AccountEmail::isPlaceholder('support@vouchmorphn.com'));
        $this->assertFalse(AccountEmail::isPlaceholder('jane@example.com'));
        $this->assertFalse(AccountEmail::isPlaceholder(''));
        $this->assertFalse(AccountEmail::isPlaceholder(null));
    }

    public function testVerifiedNeedsTheColumnSetOnceTheColumnExists(): void
    {
        $this->assertTrue(AccountEmail::isVerified(['email' => 'jane@example.com', 'email_verified_at' => '2026-09-27 10:00:00+02'], true));
        $this->assertFalse(AccountEmail::isVerified(['email' => 'jane@example.com', 'email_verified_at' => null], true));
        $this->assertFalse(AccountEmail::isVerified(['email' => 'jane@example.com'], true));
    }

    public function testAMadeUpAddressIsNeverVerified(): void
    {
        $row = ['email' => 'thabo@botswana.vouchmorphn.com', 'email_verified_at' => '2026-09-27 10:00:00+02'];
        $this->assertFalse(AccountEmail::isVerified($row, true));
        $this->assertFalse(AccountEmail::isVerified($row, false));
    }

    public function testBeforeTheMigrationAnyRealAddressCounts(): void
    {
        $this->assertTrue(AccountEmail::isVerified(['email' => 'jane@example.com'], false));
        $this->assertFalse(AccountEmail::isVerified(['email' => ''], false));
    }

    public function testTypingAnUnverifiedEmailBlocksSignIn(): void
    {
        $user = ['email' => 'jane@example.com', 'email_verified_at' => null, 'phone' => '+26771234567'];

        $this->assertTrue(AccountEmail::blocksSignIn($user, 'jane@example.com', true));
        $this->assertTrue(AccountEmail::blocksSignIn($user, ' Jane@Example.COM ', true), 'matched the way the lookup matches');
    }

    public function testTypingAVerifiedEmailOrThePhoneDoesNot(): void
    {
        $verified = ['email' => 'jane@example.com', 'email_verified_at' => '2026-09-27 10:00:00+02', 'phone' => '+26771234567'];
        $this->assertFalse(AccountEmail::blocksSignIn($verified, 'jane@example.com', true));

        $unverified = ['email' => 'jane@example.com', 'email_verified_at' => null, 'phone' => '+26771234567'];
        $this->assertFalse(AccountEmail::blocksSignIn($unverified, '71234567', true), 'phone sign-in is untouched');
        $this->assertFalse(AccountEmail::blocksSignIn($unverified, 'CM-123 456', true), 'ID sign-in is untouched');
    }

    public function testTheMadeUpAddressCanNeverSignIn(): void
    {
        $user = ['email' => 'thabo@botswana.vouchmorphn.com', 'phone' => '+26771234567'];

        $this->assertTrue(AccountEmail::blocksSignIn($user, 'thabo@botswana.vouchmorphn.com', false));
        $this->assertTrue(AccountEmail::blocksSignIn($user + ['email_verified_at' => null], 'thabo@botswana.vouchmorphn.com', true));
    }

    public function testMask(): void
    {
        $this->assertSame('j•••@example.com', AccountEmail::mask('jane@example.com'));
        $this->assertSame('a•@x.com', AccountEmail::mask('a@x.com'));
    }
}
