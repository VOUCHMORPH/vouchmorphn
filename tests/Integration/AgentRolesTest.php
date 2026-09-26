<?php

use PHPUnit\Framework\TestCase;
use Application\Admin\AgentRoles;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Giving customers the agent role from the admin dashboard's Agents tab,
 * run against a real PostgreSQL server: the customer search, the role
 * change and its audit row, and database/migrations/2026_09_26_agent_role.sql,
 * which adds the role to a database built from the tracked schema.
 *
 * Needs a database. Like the other integration tests it deliberately does
 * NOT fall back to DATABASE_URL, because it creates and drops a schema.
 * Point it at a throwaway server explicitly:
 *
 *     AGENT_ROLES_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/AgentRolesTest.php
 *
 * Everything happens in a throwaway schema, rebuilt for every test.
 */
class AgentRolesTest extends TestCase
{
    private const DIAL = '+267';
    private const LOCAL_LENGTH = 8;
    private const SCHEMA = 'agent_roles_test';
    private const ADMIN_ID = 7;

    private static ?PDO $db = null;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('AGENT_ROLES_TEST_DATABASE_URL');
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

        // Same options as DBConnection.
        self::$db = new PDO($dsn, $parts['user'] ?? 'postgres', $parts['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
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
            $this->markTestSkipped('Set AGENT_ROLES_TEST_DATABASE_URL to a throwaway PostgreSQL database to run this.');
        }

        $schema = self::SCHEMA;
        self::$db->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        self::$db->exec("CREATE SCHEMA {$schema}");
        self::$db->exec("SET search_path TO {$schema}");

        // roles as the tracked schema (swap_system_bw.sql) builds it: no
        // 'agent' row, and a CHECK that refuses one.
        self::$db->exec("
            CREATE TABLE roles (
                role_id     BIGSERIAL PRIMARY KEY,
                role_name   VARCHAR(50) NOT NULL UNIQUE,
                description TEXT,
                permissions JSONB DEFAULT '[]'::jsonb,
                CONSTRAINT role_name_check CHECK (role_name IN ('user', 'admin', 'compliance', 'auditor', 'super_admin'))
            )
        ");
        self::$db->exec("
            INSERT INTO roles (role_id, role_name, permissions) VALUES
                (1, 'user', '[\"basic_access\", \"create_swaps\", \"view_own_transactions\"]'),
                (2, 'admin', '[\"full_access\"]'),
                (3, 'compliance', '[]'),
                (4, 'auditor', '[]'),
                (5, 'super_admin', '[]')
        ");

        // users with the identifier columns register.php adds, and no
        // full_name column, which not every database has.
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
                role_id         BIGINT DEFAULT 1 REFERENCES roles (role_id),
                updated_at      TIMESTAMPTZ DEFAULT NOW()
            )
        ");
        self::$db->exec("
            INSERT INTO users (user_id, username, email, phone, national_id, role_id) VALUES
                -- a plain customer
                (101, 'thabo',  'Thabo@Example.com',  '+26771234567', NULL,       1),
                -- no role at all, which whoami.php reads as 'user'; legacy
                -- phone shape without the plus
                (102, 'naledi', 'naledi@example.com', '26771234568',  'CM123456', NULL),
                -- an admin, who must never be turned into an agent here
                (103, 'kagiso', 'kagiso@example.com', '+26771234569', NULL,       2)
        ");

        // The columns SwapService::isApprovedAgent() reads.
        self::$db->exec("
            CREATE TABLE agent_destination_accounts (
                id         BIGSERIAL PRIMARY KEY,
                user_id    BIGINT NOT NULL,
                status     VARCHAR(30) NOT NULL,
                deleted_at TIMESTAMPTZ
            )
        ");

        // The columns AdminAudit::record() writes.
        self::$db->exec("
            CREATE TABLE audit_logs (
                audit_id          BIGSERIAL PRIMARY KEY,
                entity_type       VARCHAR(50),
                entity_id         VARCHAR(255),
                action            VARCHAR(50),
                category          VARCHAR(50),
                severity          VARCHAR(20),
                old_value         JSONB,
                new_value         JSONB,
                performed_by_type VARCHAR(20),
                performed_by_id   BIGINT,
                ip_address        INET,
                user_agent        TEXT,
                request_id        VARCHAR(100)
            )
        ");
    }

    public function testGrantPointsAtTheMigrationWhenThereIsNoAgentRole(): void
    {
        $this->assertNull(AgentRoles::roleId(self::$db, AgentRoles::AGENT));

        $this->assertRefused(fn () => AgentRoles::grant(self::$db, self::ADMIN_ID, 101), AgentRoles::MIGRATION);
        $this->assertSame('user', $this->roleOf(101));
    }

    public function testMigrationAddsTheAgentRoleAndIsSafeToRerun(): void
    {
        $this->applyMigration();
        $this->applyMigration();

        $rows = self::$db->query("SELECT role_id, permissions FROM roles WHERE role_name = 'agent'")->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(6, (int)$rows[0]['role_id'], 'One past the highest role_id in use.');
        $this->assertSame(
            ['basic_access', 'create_swaps', 'view_own_transactions'],
            json_decode($rows[0]['permissions'], true),
            "An agent keeps the plain 'user' role's permissions."
        );

        // The widened check still refuses names nobody meant to allow.
        $refused = null;
        try {
            self::$db->exec("INSERT INTO roles (role_id, role_name) VALUES (50, 'hacker')");
        } catch (PDOException $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused);
        $this->assertStringContainsString('role_name_check', $refused->getMessage());
    }

    public function testSearchFindsACustomerTheWaySignInDoes(): void
    {
        $this->assertSame([101], $this->searchIds('71234567'), 'Local number, stored with the country code.');
        $this->assertSame([102], $this->searchIds('+267 7123 4568'), 'Stored in the legacy shape, without the plus.');
        $this->assertSame([101], $this->searchIds('thabo@example.com'), 'Email, whatever its case.');
        $this->assertSame([102], $this->searchIds('cm-123 456'), 'ID number, ignoring punctuation.');
        $this->assertSame([103], $this->searchIds('103'), 'User ID.');
        $this->assertSame([103], $this->searchIds('#103'), 'User ID written with a hash.');
        $this->assertSame([], $this->searchIds('nobody@example.com'));
        $this->assertSame([], $this->searchIds('   '));
    }

    public function testSearchShowsEachCustomersRole(): void
    {
        $this->applyMigration();
        AgentRoles::grant(self::$db, self::ADMIN_ID, 101);

        $this->assertSame('agent', $this->search('101')[0]['role_name']);
        $this->assertSame('user', $this->search('102')[0]['role_name'], 'No role reads as a plain customer.');
        $this->assertSame('admin', $this->search('103')[0]['role_name']);
    }

    public function testGrantMakesAPlainCustomerAnAgentAndRecordsIt(): void
    {
        $this->applyMigration();

        $result = AgentRoles::grant(self::$db, self::ADMIN_ID, 101);

        $this->assertSame(['user_id' => 101, 'name' => 'thabo (#101)', 'role' => 'agent'], $result);
        $this->assertSame('agent', $this->roleOf(101));

        $audit = $this->auditRows();
        $this->assertCount(1, $audit);
        $this->assertSame('AGENT_ROLE_GRANTED', $audit[0]['action']);
        $this->assertSame('user', $audit[0]['entity_type']);
        $this->assertSame('101', $audit[0]['entity_id']);
        $this->assertSame('info', $audit[0]['severity']);
        $this->assertSame(['role' => 'user'], json_decode($audit[0]['old_value'], true));
        $this->assertSame(['role' => 'agent', 'reason' => null], json_decode($audit[0]['new_value'], true));
        $this->assertSame('admin', $audit[0]['performed_by_type']);
        $this->assertSame(self::ADMIN_ID, (int)$audit[0]['performed_by_id']);
    }

    public function testACustomerWithNoRoleCanBeMadeAnAgent(): void
    {
        $this->applyMigration();

        AgentRoles::grant(self::$db, self::ADMIN_ID, 102);

        $this->assertSame('agent', $this->roleOf(102));
        $this->assertSame(['role' => 'user'], json_decode($this->auditRows()[0]['old_value'], true));
    }

    public function testGrantLeavesEveryOtherRoleAlone(): void
    {
        $this->applyMigration();

        $this->assertRefused(fn () => AgentRoles::grant(self::$db, self::ADMIN_ID, 103), "has the 'admin' role");
        $this->assertSame('admin', $this->roleOf(103));

        AgentRoles::grant(self::$db, self::ADMIN_ID, 101);
        $this->assertRefused(fn () => AgentRoles::grant(self::$db, self::ADMIN_ID, 101), 'is already an agent');
        $this->assertRefused(fn () => AgentRoles::grant(self::$db, self::ADMIN_ID, 999), 'no customer #999');

        $this->assertCount(1, $this->auditRows(), 'A refused change writes no audit row.');
    }

    public function testRevokeNeedsAReasonAndOnlyTakesTheRoleFromAgents(): void
    {
        $this->applyMigration();
        AgentRoles::grant(self::$db, self::ADMIN_ID, 101);

        $this->assertRefused(fn () => AgentRoles::revoke(self::$db, self::ADMIN_ID, 101, ' no '), 'reason of at least 5 characters');
        $this->assertRefused(fn () => AgentRoles::revoke(self::$db, self::ADMIN_ID, 103, 'Left the programme'), 'does not have the agent role');
        $this->assertSame('agent', $this->roleOf(101));
        $this->assertSame('admin', $this->roleOf(103));

        AgentRoles::revoke(self::$db, self::ADMIN_ID, 101, '  Left the programme ');

        $this->assertSame('user', $this->roleOf(101));
        $audit = $this->auditRows();
        $this->assertCount(2, $audit);
        $this->assertSame('AGENT_ROLE_REVOKED', $audit[1]['action']);
        $this->assertSame('warning', $audit[1]['severity']);
        $this->assertSame(['role' => 'agent'], json_decode($audit[1]['old_value'], true));
        $this->assertSame(['role' => 'user', 'reason' => 'Left the programme'], json_decode($audit[1]['new_value'], true));
    }

    public function testTheRoleDoesNotChangeWhenItsAuditRowCannotBeWritten(): void
    {
        $this->applyMigration();
        // Any failure writing the audit row will do.
        self::$db->exec('ALTER TABLE audit_logs ADD CONSTRAINT refuse_all CHECK (false) NOT VALID');

        $failure = null;
        try {
            AgentRoles::grant(self::$db, self::ADMIN_ID, 101);
        } catch (PDOException $e) {
            $failure = $e;
        }

        $this->assertNotNull($failure, 'The grant fails along with its audit row.');
        $this->assertSame('user', $this->roleOf(101));
        $this->assertFalse(self::$db->inTransaction());
    }

    public function testListAgentsCountsOnlyApprovedAccounts(): void
    {
        $this->applyMigration();
        AgentRoles::grant(self::$db, self::ADMIN_ID, 101);
        AgentRoles::grant(self::$db, self::ADMIN_ID, 102);
        self::$db->exec("
            INSERT INTO agent_destination_accounts (user_id, status, deleted_at) VALUES
                (101, 'active', NULL),
                (101, 'active', NULL),
                (101, 'pending_confirmation', NULL),
                (101, 'active', NOW()),
                (102, 'rejected', NULL),
                (103, 'active', NULL)
        ");

        $agents = AgentRoles::listAgents(self::$db);

        $this->assertSame(
            [101 => 2, 102 => 0],
            array_map('intval', array_column($agents, 'approved_accounts', 'user_id')),
            'Only active, undeleted accounts count, and only agents are listed.'
        );
    }

    public function testShowsTheFullNameWhereTheColumnExists(): void
    {
        $this->assertSame('thabo', AgentRoles::displayName($this->search('101')[0]));

        self::$db->exec('ALTER TABLE users ADD COLUMN full_name VARCHAR(200)');
        self::$db->exec("UPDATE users SET full_name = 'Thabo Mokoena' WHERE user_id = 101");

        $this->assertSame('Thabo Mokoena', AgentRoles::displayName($this->search('101')[0]));
    }

    private function applyMigration(): void
    {
        self::$db->exec(file_get_contents(__DIR__ . '/../../database/migrations/2026_09_26_agent_role.sql'));
    }

    private function search(string $query): array
    {
        return AgentRoles::search(self::$db, $query, self::DIAL, self::LOCAL_LENGTH);
    }

    /** @return int[] */
    private function searchIds(string $query): array
    {
        return array_map('intval', array_column($this->search($query), 'user_id'));
    }

    private function roleOf(int $userId): ?string
    {
        $stmt = self::$db->prepare('SELECT r.role_name FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :id');
        $stmt->execute([':id' => $userId]);
        $role = $stmt->fetchColumn();
        return $role === false || $role === null ? null : (string)$role;
    }

    private function auditRows(): array
    {
        return self::$db->query('SELECT * FROM audit_logs ORDER BY audit_id')->fetchAll();
    }

    /**
     * The change is refused with a message for the admin — not a database
     * error — and the message says why.
     */
    private function assertRefused(callable $change, string $expected): void
    {
        $refusal = null;
        try {
            $change();
        } catch (RuntimeException $e) {
            $refusal = $e;
        }

        $this->assertNotNull($refusal, "Expected a refusal saying: {$expected}");
        $this->assertNotInstanceOf(PDOException::class, $refusal, 'Got a database error: ' . $refusal->getMessage());
        $this->assertStringContainsString($expected, $refusal->getMessage());
    }
}
