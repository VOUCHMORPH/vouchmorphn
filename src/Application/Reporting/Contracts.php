<?php
declare(strict_types=1);

namespace Application\Reporting;

use PDO;
use PragmaRX\Google2FA\Google2FA;

// =====================================================================
// Implementations of the two interfaces, against the existing schema
// =====================================================================

interface RoleResolver { public function holds(int $adminId, string $role): bool; }
interface MfaVerifier  { public function verify(int $adminId, string $code): bool; }

final class DbRoleResolver implements RoleResolver
{
    public function __construct(private PDO $db) {}

    public function holds(int $adminId, string $role): bool
    {
        $s = $this->db->prepare(
            'SELECT 1 FROM admin_functional_roles WHERE admin_id = :a AND role = :r AND revoked_at IS NULL');
        $s->execute([':a' => $adminId, ':r' => $role]);
        return (bool)$s->fetchColumn();
    }
}

/**
 * Signing requires MFA. An admin who has not enrolled cannot sign — which
 * also closes finding F4 for everyone in the signing chain.
 */
final class TotpMfaVerifier implements MfaVerifier
{
    public function __construct(private PDO $db) {}

    public function verify(int $adminId, string $code): bool
    {
        $s = $this->db->prepare('SELECT mfa_secret, mfa_enabled FROM admins WHERE admin_id = :a AND deleted_at IS NULL');
        $s->execute([':a' => $adminId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);

        $enabled = $row && in_array($row['mfa_enabled'], [true, 't', 1, '1'], true);
        if (!$enabled || empty($row['mfa_secret']) || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        return (new Google2FA())->verifyKey($row['mfa_secret'], $code, 1);  // ±30 s window
    }
}
