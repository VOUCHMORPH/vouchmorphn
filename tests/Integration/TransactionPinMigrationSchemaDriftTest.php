<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Infrastructure\Credentials\TransactionPinMigrationRunner;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * The checked-in schema never declared the transaction_pin_attempts /
 * transaction_pin_locked_until / transaction_pin_set_at columns the app
 * uses, so the live `users` table may not have them. The migration must
 * still copy the hash, as "no failed attempts, not locked".
 *
 * Its own class, in its own process, because
 * CredentialsMigrationRunner::hasColumn() caches per process — it would
 * otherwise answer with whatever TransactionPinMigrationTest's full-shape
 * table told it first. Same setup and env var as that test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TransactionPinMigrationSchemaDriftTest extends TestCase
{
    private const MAIN_SCHEMA = 'tpin_drift_main';
    private const CRED_SCHEMA = 'tpin_drift_cred';

    public function testCopiesJustTheHashWhenTheLockoutColumnsAreMissing(): void
    {
        $url = getenv('CREDENTIALS_MIGRATION_TEST_DATABASE_URL');
        if (!$url) {
            $this->markTestSkipped('Set CREDENTIALS_MIGRATION_TEST_DATABASE_URL to a throwaway PostgreSQL database to run this.');
        }
        $main = self::connect($url);
        $cred = self::connect($url);

        try {
            foreach ([self::MAIN_SCHEMA, self::CRED_SCHEMA] as $schema) {
                $main->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
                $main->exec("CREATE SCHEMA {$schema}");
            }
            $main->exec('SET search_path TO ' . self::MAIN_SCHEMA);
            $cred->exec('SET search_path TO ' . self::CRED_SCHEMA);
            $cred->exec(file_get_contents(__DIR__ . '/../../scripts/credentials_db/schema.sql'));

            $main->exec('CREATE TABLE users (user_id BIGSERIAL PRIMARY KEY, username VARCHAR(100) NOT NULL, transaction_pin_hash VARCHAR(255))');
            $hash = password_hash('111111', PASSWORD_DEFAULT);
            $main->prepare("INSERT INTO users (username, transaction_pin_hash) VALUES ('a', :h)")->execute([':h' => $hash]);

            $lines = [];
            $out = function (string $line) use (&$lines): void {
                $lines[] = $line;
            };
            TransactionPinMigrationRunner::migrate($main, $cred, true, 500, $out);

            $row = $cred->query('SELECT * FROM user_transaction_pins')->fetch();
            $this->assertSame($hash, $row['pin_hash']);
            $this->assertSame(0, (int)$row['failed_attempts']);
            $this->assertNull($row['locked_until']);
            $this->assertNull($row['pin_set_at']);
            $this->assertTrue(TransactionPinMigrationRunner::verify($main, $cred, 500, $out));
        } finally {
            foreach ([self::MAIN_SCHEMA, self::CRED_SCHEMA] as $schema) {
                $main->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
            }
        }
    }

    private static function connect(string $url): PDO
    {
        $parts = parse_url($url);
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $parts['host'] ?? 'localhost',
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '', '/')
        );
        return new PDO($dsn, $parts['user'] ?? 'postgres', $parts['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
