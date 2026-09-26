<?php

use PHPUnit\Framework\TestCase;
use Domain\Identity\UsernameGenerator;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Usernames for email sign-ups. verify_otp.php used to build one from the
 * digits in the email ("jane@example.com" -> "user_"); it now uses the part
 * before the @, numbered when taken.
 */
class UsernameGeneratorTest extends TestCase
{
    public function testUsesThePartBeforeTheAt(): void
    {
        $this->assertSame('jane', UsernameGenerator::baseFromEmail('jane@example.com'));
    }

    public function testLowercasesAndKeepsOnlyLettersDigitsDotsAndUnderscores(): void
    {
        $this->assertSame('j.doex', UsernameGenerator::baseFromEmail('J.Doe+x@mail.com'));
        $this->assertSame('thabo_m', UsernameGenerator::baseFromEmail('  Thabo_M@Example.COM '));
    }

    public function testDropsLeadingAndTrailingDotsAndUnderscores(): void
    {
        $this->assertSame('jane', UsernameGenerator::baseFromEmail('._jane_.@example.com'));
    }

    public function testFallsBackWhenNothingUsableIsLeft(): void
    {
        $this->assertSame('user', UsernameGenerator::baseFromEmail('+++@example.com'));
        $this->assertSame('user', UsernameGenerator::baseFromEmail('@example.com'));
    }

    public function testCapsTheLength(): void
    {
        $base = UsernameGenerator::baseFromEmail(str_repeat('a', 80) . '@example.com');
        $this->assertSame(UsernameGenerator::MAX_BASE_LENGTH, strlen($base));
    }

    public function testFirstFreeNumbersFromTwo(): void
    {
        $this->assertSame('jane', UsernameGenerator::firstFree('jane', []));
        $this->assertSame('jane2', UsernameGenerator::firstFree('jane', ['jane']));
        $this->assertSame('jane3', UsernameGenerator::firstFree('jane', ['jane', 'jane2', 'janet']));
        $this->assertSame('jane2', UsernameGenerator::firstFree('jane', ['JANE']), 'taken is case-insensitive');
    }

    public function testForEmailChecksExistingUsernames(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
        $db->exec("INSERT INTO users (username) VALUES ('jane'), ('jane2'), ('janet'), ('jxdoe')");

        $this->assertSame('jane3', UsernameGenerator::forEmail($db, 'jane@example.com'));
        $this->assertSame('bob', UsernameGenerator::forEmail($db, 'bob@example.com'));
        // "_" is a LIKE wildcard: "jxdoe" must not count as "j_doe".
        $this->assertSame('j_doe', UsernameGenerator::forEmail($db, 'j_doe@example.com'));
    }
}
