<?php
declare(strict_types=1);

namespace Application\Admin;

use Domain\Identity\UserIdentifierLookup;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Gives a customer the 'agent' role, or takes it back, from the admin
 * dashboard's Agents tab.
 *
 * The role is what whoami.php reports as is_agent, and what makes the
 * customer dashboard show the Agent menu, where an agent registers the
 * business account they pay out from. The agent endpoints do not check
 * the role: they ask SwapService::isApprovedAgent(), which looks for an
 * active agent_destination_accounts row. So a new agent still needs one of
 * those accounts approved before they can act as one, and taking the role
 * away does not touch accounts that are already approved.
 *
 * Only a plain customer (the 'user' role, or no role at all, which
 * whoami.php also reads as 'user') is made an agent, and only an agent is
 * made a plain customer again. Any other role is left alone, so this can
 * never quietly take away someone's admin or compliance role.
 *
 * Each change is one transaction with its AdminAudit row, as the
 * destination approvals are: if the audit row cannot be written, the role
 * does not change.
 */
final class AgentRoles
{
    public const AGENT = 'agent';
    public const USER = 'user';

    /** Adds the 'agent' role to a database that does not have one yet. */
    public const MIGRATION = 'database/migrations/2026_09_26_agent_role.sql';

    private const SEARCH_LIMIT = 10;
    private const LIST_LIMIT = 500;

    // full_name is read out of to_jsonb(u) because not every database has
    // the column (register.php does not add it), and naming a missing
    // column would fail the whole query.
    private const USER_COLUMNS = "u.user_id, u.username, to_jsonb(u) ->> 'full_name' AS full_name, u.phone, u.email";

    public static function roleId(PDO $db, string $roleName): ?int
    {
        $stmt = $db->prepare("SELECT role_id FROM roles WHERE role_name = :name LIMIT 1");
        $stmt->execute([':name' => $roleName]);
        $roleId = $stmt->fetchColumn();
        return $roleId === false ? null : (int)$roleId;
    }

    /**
     * The customers an admin's search could mean: a user ID, or a phone
     * number, email or ID number matched the way sign-in matches them.
     * Each row's role_name is 'user' when the customer has no role.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(PDO $db, string $query, string $dialCode, ?int $localLength = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        [$where, $params] = UserIdentifierLookup::buildMatch($query, $dialCode, $localLength, 'q');

        // A bare number could be a user ID as well as a phone number, so
        // both are matched and the admin picks the right person. Eighteen
        // digits always fits in a bigint.
        $digits = ltrim($query, '#');
        if (ctype_digit($digits) && strlen($digits) <= 18) {
            $where[] = "u.user_id = :q_user_id";
            $params[':q_user_id'] = (int)$digits;
        }

        if ($where === []) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT " . self::USER_COLUMNS . ", COALESCE(r.role_name, :no_role) AS role_name
            FROM users u
            LEFT JOIN roles r ON r.role_id = u.role_id
            WHERE " . implode(' OR ', $where) . "
            ORDER BY u.user_id
            LIMIT " . self::SEARCH_LIMIT
        );
        $stmt->execute($params + [':no_role' => self::USER]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Everyone who has the agent role, with how many of their payout
     * accounts are approved. An agent with none cannot act as one yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAgents(PDO $db): array
    {
        $stmt = $db->prepare("
            SELECT " . self::USER_COLUMNS . ",
                   (SELECT COUNT(*) FROM agent_destination_accounts a
                    WHERE a.user_id = u.user_id AND a.status = 'active' AND a.deleted_at IS NULL) AS approved_accounts
            FROM users u
            JOIN roles r ON r.role_id = u.role_id
            WHERE r.role_name = :agent
            ORDER BY u.user_id
            LIMIT " . self::LIST_LIMIT
        );
        $stmt->execute([':agent' => self::AGENT]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{user_id: int, name: string, role: string} */
    public static function grant(PDO $db, ?int $adminId, int $userId): array
    {
        return self::change($db, $adminId, $userId, self::USER, self::AGENT, null);
    }

    /** @return array{user_id: int, name: string, role: string} */
    public static function revoke(PDO $db, ?int $adminId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Removing the agent role needs a reason of at least 5 characters.');
        }
        return self::change($db, $adminId, $userId, self::AGENT, self::USER, $reason);
    }

    /** The customer's full name if they gave one, otherwise their username. */
    public static function displayName(array $user): string
    {
        foreach (['full_name', 'username'] as $column) {
            $name = trim((string)($user[$column] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return 'Customer';
    }

    /** @return array{user_id: int, name: string, role: string} */
    private static function change(PDO $db, ?int $adminId, int $userId, string $from, string $to, ?string $reason): array
    {
        $toRoleId = self::roleId($db, $to);
        if ($toRoleId === null) {
            throw new RuntimeException($to === self::AGENT
                ? "There is no 'agent' role in the roles table yet. Apply " . self::MIGRATION . ", then try again."
                : "There is no '{$to}' role in the roles table.");
        }

        $db->beginTransaction();
        try {
            // FOR UPDATE OF u: only the customer's row is locked. Postgres
            // refuses to lock the nullable side of an outer join.
            $stmt = $db->prepare("
                SELECT u.user_id, u.username, to_jsonb(u) ->> 'full_name' AS full_name, r.role_name
                FROM users u
                LEFT JOIN roles r ON r.role_id = u.role_id
                WHERE u.user_id = :id
                FOR UPDATE OF u
            ");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                throw new RuntimeException("There is no customer #{$userId}.");
            }

            $name = self::displayName($user) . " (#{$userId})";
            $current = $user['role_name'] ?? self::USER;
            if ($current !== $from) {
                throw new RuntimeException(match (true) {
                    $to === self::USER => "{$name} does not have the agent role.",
                    $current === self::AGENT => "{$name} is already an agent.",
                    default => "{$name} has the '{$current}' role. Only a customer with the plain 'user' role can be made an agent; other roles are left alone.",
                });
            }

            $upd = $db->prepare("UPDATE users SET role_id = :role_id, updated_at = NOW() WHERE user_id = :id");
            $upd->execute([':role_id' => $toRoleId, ':id' => $userId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException("The role of {$name} could not be changed.");
            }

            AdminAudit::record(
                $db, $adminId,
                $to === self::AGENT ? 'AGENT_ROLE_GRANTED' : 'AGENT_ROLE_REVOKED',
                'user', (string)$userId,
                ['role' => $current],
                ['role' => $to, 'reason' => $reason],
                $to === self::AGENT ? 'info' : 'warning'
            );

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return ['user_id' => $userId, 'name' => $name, 'role' => $to];
    }
}
