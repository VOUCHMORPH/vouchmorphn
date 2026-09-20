<?php

use PHPUnit\Framework\TestCase;
use Domain\Identity\UserIdentifierLookup;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * The "User not found although they made the account" regression, run
 * against a real PostgreSQL server — the unit tests pin the string
 * handling, this pins that the SQL actually finds the row.
 *
 * Needs a database. It deliberately does NOT fall back to DATABASE_URL:
 * this test creates and drops a schema, and nothing here should ever be
 * able to do that to the live application database by accident. Point it
 * at a throwaway server explicitly:
 *
 *     IDENTIFIER_LOOKUP_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/UserIdentifierLookupTest.php
 *
 * It never touches a real `users` table: everything happens in a
 * throwaway schema that is dropped again in tearDownAfterClass.
 */
class UserIdentifierLookupTest extends TestCase
{
    private const DIAL = '+267';
    private const LOCAL_LENGTH = 8;
    private const SCHEMA = 'identifier_lookup_test';

    private static ?PDO $db = null;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('IDENTIFIER_LOOKUP_TEST_DATABASE_URL');
        if (!$url) {
            return;
        }

        $parts = parse_url($url);
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $parts['host'] ?? 'localhost',
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '', '/')
        );

        self::$db = new PDO($dsn, $parts['user'] ?? 'postgres', $parts['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // The application connects with emulation off; the lookup's
            // placeholder handling has to hold under the real thing.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $schema = self::SCHEMA;
        self::$db->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        self::$db->exec("CREATE SCHEMA {$schema}");
        self::$db->exec("SET search_path TO {$schema}");
        self::$db->exec("
            CREATE TABLE users (
                user_id         BIGSERIAL PRIMARY KEY,
                username        VARCHAR(100) NOT NULL,
                email           VARCHAR(150),
                phone           VARCHAR(30),
                phone2          VARCHAR(30),
                phone3          VARCHAR(30),
                national_id     VARCHAR(100),
                drivers_license VARCHAR(100),
                passport        VARCHAR(100),
                verified        BOOLEAN DEFAULT TRUE
            )
        ");

        // Rows exactly as the pre-fix writers left them.
        self::$db->exec("
            INSERT INTO users (username, email, phone, phone2, national_id, drivers_license, passport) VALUES
                -- typed her full number at sign-up: the old normalizePhone
                -- stacked the dial code on top of the country code.
                ('naledi', 'naledi@example.com', '+26726771234567', NULL, NULL, NULL, NULL),
                -- legacy import, no plus (as in backup.sql).
                ('thabo',  'thabo@example.com',  '26771234500',     NULL, NULL, NULL, NULL),
                -- registered by email; sign-up lowercased it.
                ('kefilwe','KEFILWE.Moeng@Example.com', '+26771234501', NULL, NULL, NULL, NULL),
                -- ID-document holder, number stored with punctuation.
                ('lesego', 'lesego@example.com', '+26771234502', '71234503', 'CM-123 456', 'DL/99-77', 'P 4455 66')
        ");

        // The functional indexes the lookup relies on.
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/2026_09_20_identifier_lookup_indexes.sql');
        self::$db->exec($migration);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('Set IDENTIFIER_LOOKUP_TEST_DATABASE_URL to a throwaway PostgreSQL database to run this.');
        }
        self::$db->exec('SET search_path TO ' . self::SCHEMA);
    }

    private function find(string $typed): ?array
    {
        return UserIdentifierLookup::find(
            self::$db,
            $typed,
            self::DIAL,
            self::LOCAL_LENGTH,
            'user_id, username, phone, phone2, phone3, email, national_id, drivers_license, passport'
        );
    }

    /**
     * @dataProvider typedIdentifiers
     */
    public function testAnAccountIsFoundHoweverItsIdentifierWasStored(string $typed, string $expectedUsername): void
    {
        $user = $this->find($typed);

        $this->assertNotNull($user, "'{$typed}' belongs to an existing account and must not come back 'User not found'");
        $this->assertSame($expectedUsername, $user['username']);
    }

    public static function typedIdentifiers(): array
    {
        return [
            // Stored '+26726771234567' — every way she might type it.
            'local part of a double-prefixed row'   => ['71234567', 'naledi'],
            'trunk zero of a double-prefixed row'   => ['071234567', 'naledi'],
            'e164 of a double-prefixed row'         => ['+26771234567', 'naledi'],
            'spaced e164'                           => ['+267 71 234 567', 'naledi'],
            'the shape actually stored'             => ['+26726771234567', 'naledi'],

            // Stored '26771234500' (no plus).
            'local part of a no-plus row'           => ['71234500', 'thabo'],
            'e164 of a no-plus row'                 => ['+26771234500', 'thabo'],

            // Stored 'KEFILWE.Moeng@Example.com'.
            'email as typed'                        => ['KEFILWE.Moeng@Example.com', 'kefilwe'],
            'email lowercased'                      => ['kefilwe.moeng@example.com', 'kefilwe'],
            'email autocapitalised by a phone'      => ['Kefilwe.moeng@example.com', 'kefilwe'],

            // Stored with punctuation.
            'national id without punctuation'       => ['CM123456', 'lesego'],
            'national id lowercase and spaced'      => ['cm-123 456', 'lesego'],
            'drivers licence without punctuation'   => ['DL9977', 'lesego'],
            'passport without spaces'               => ['P445566', 'lesego'],
            'secondary phone'                       => ['71234503', 'lesego'],
        ];
    }

    public function testAnIdentifierNobodyOwnsStillFindsNobody(): void
    {
        $this->assertNull($this->find('79999999'), 'a number with no account must not match someone else');
        $this->assertNull($this->find('nobody@example.com'));
        $this->assertNull($this->find('ZZ000000'));
    }

    public function testTheOldExactMatchIsWhatUsedToFail(): void
    {
        // Pins the bug itself: the pre-fix query, byte-for-byte, against
        // the same rows. If this ever starts finding her, the data has
        // changed and these fixtures no longer describe the regression.
        $stmt = self::$db->prepare('
            SELECT user_id FROM users
            WHERE phone = :identifier
               OR phone2 = :identifier2
               OR email = :identifier3
            LIMIT 1
        ');
        $stmt->execute([
            ':identifier'  => '+26771234567',
            ':identifier2' => '+26771234567',
            ':identifier3' => '+26771234567',
        ]);

        $this->assertFalse($stmt->fetch(), 'the old exact match is why she was told her account did not exist');
        $this->assertNotNull($this->find('71234567'), 'and the new lookup is what finds her again');
    }

    public function testDuplicateRowsResolveToTheCanonicalOne(): void
    {
        // Two rows for one number, the shape the old code could produce
        // on a re-registration: the canonical row is the one returned.
        self::$db->exec("
            INSERT INTO users (username, email, phone) VALUES
                ('dupe_legacy', 'dupe.legacy@example.com', '26771239999'),
                ('dupe_canonical', 'dupe.canonical@example.com', '+26771239999')
        ");

        try {
            $user = $this->find('71239999');
            $this->assertNotNull($user);
            $this->assertSame('dupe_canonical', $user['username']);
        } finally {
            self::$db->exec("DELETE FROM users WHERE username IN ('dupe_legacy', 'dupe_canonical')");
        }
    }
}
