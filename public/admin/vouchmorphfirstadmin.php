<?php
/**
 * platform-admin/seed_first_admin.php
 *
 * Run once per NEW country database, from the command line, right after
 * 004_admins_roles_migration.sql has created and seeded roles/admins:
 *
 *   php seed_first_admin.php "Your Name" "you@vouchmorph.com" "your_username" [country_code]
 *
 * country_code is optional and only for record-keeping on this specific
 * login — role 999 (super_admin) is always treated as global regardless
 * of what's stored there (see isCountryInAdminScope() in auth.php).
 *
 * Deliberately CLI-only, not a web route — there is no HTTP-reachable
 * "create yourself an admin" endpoint, in this database or any country's.
 * Refuses to run if this database already has any admin at all, so it
 * can never be reused as a backdoor after initial setup.
 *
 * SAME LOGIN, EVERY COUNTRY: run this with the same email/username in
 * every new country's database as you provision it, so your own team
 * doesn't have to track a different identity per country. Passwords
 * should NOT be identical across countries though, even for the same
 * person — these are independently-secured systems; if one country's
 * database is ever compromised, an identical password lets that breach
 * walk straight into every other country too. Use a password manager
 * and generate a distinct one per country, same email is fine to share.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script only runs from the command line, not over HTTP.\n");
}

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Database/AuthDBConnection.php';
require_once __DIR__ . '/../../src/Core/Database/CredentialsRepository.php';
use Core\Database\DBConnection;
use Core\Database\CredentialsRepository;

$fullName = trim($argv[1] ?? '');
$email = trim(strtolower($argv[2] ?? ''));
$username = trim($argv[3] ?? '');
$countryCode = isset($argv[4]) ? strtoupper(trim($argv[4])) : null;
$fixedPassword = isset($argv[5]) ? trim($argv[5]) : null;

if ($fullName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $username === '') {
    fwrite(STDERR, "Usage: php seed_first_admin.php \"Full Name\" \"email@vouchmorph.com\" \"username\" [country_code] [fixed_password]\n");
    exit(1);
}

$db = DBConnection::getConnection();

$stmt = $db->query("SELECT COUNT(*) FROM admins WHERE deleted_at IS NULL");
$existingCount = (int)$stmt->fetchColumn();

if ($existingCount > 0) {
    fwrite(STDERR, "Refusing to run: {$existingCount} admin(s) already exist in this database. Create additional admins through the normal platform-admin flow once you can log in, not this script.\n");
    exit(1);
}

$stmt = $db->prepare("SELECT 1 FROM roles WHERE role_id = 999");
$stmt->execute();
if (!$stmt->fetchColumn()) {
    fwrite(STDERR, "role_id 999 (super_admin) not found — run 004_admins_roles_migration.sql against this database first.\n");
    exit(1);
}

$chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
$tempPassword = $fixedPassword ?: '';
if ($tempPassword === '') {
    for ($i = 0; $i < 14; $i++) {
        $tempPassword .= $chars[random_int(0, strlen($chars) - 1)];
    }
}
$hash = password_hash($tempPassword, PASSWORD_DEFAULT);

// username/password_hash/mfa_enabled/failed_login_attempts live in the
// isolated auth DB now (see CredentialsRepository).
$stmt = $db->prepare("
    INSERT INTO admins (
        email, role_id, full_name, country_code, created_at, updated_at
    ) VALUES (
        :email, 999, :name, :country, NOW(), NOW()
    ) RETURNING admin_id
");
$stmt->execute([
    ':email' => $email, ':name' => $fullName, ':country' => $countryCode,
]);
$newId = (int)$stmt->fetchColumn();

CredentialsRepository::createAdminCredentials($newId, $username, $hash, false);

echo "super_admin created in this database.\n";
echo "  admin_id:      {$newId}\n";
echo "  username:      {$username}\n";
echo "  email:         {$email}\n";
echo "  country_code:  " . ($countryCode ?? '(none — irrelevant for super_admin anyway)') . "\n";
echo "  temp password: {$tempPassword}\n";
echo "\nShown once, not recoverable — store it now (password manager, distinct per country) and change it at first login.\n";
echo "\nNOTE: mfa_enabled is set to false. This login flow does not currently enforce MFA even when the column is true — see the note in platform-admin/auth.php.\n";
