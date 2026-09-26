<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Database/CredentialsDBConnection.php';
require_once __DIR__ . '/../../src/Infrastructure/Credentials/CredentialsRepository.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Domain\Identity\AccountEmail;
use Domain\Identity\ContactVerificationService;
use Domain\Identity\SignInSchema;
use Infrastructure\Credentials\CredentialsRepository;

header('Content-Type: application/json');

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = SessionManager::getUser();
$userId = (int)($user['id'] ?? $user['user_id'] ?? 0);

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session has no user id']);
    exit;
}

try {
    $db = DBConnection::getConnection();

    $stmt = $db->prepare("
        SELECT r.role_name, r.permissions
        FROM users u
        JOIN roles r ON r.role_id = u.role_id
        WHERE u.user_id = :id
    ");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $roleName = $row['role_name'] ?? 'user';
    $permissions = $row['permissions'] ? json_decode($row['permissions'], true) : [];

    // What the dashboard's "add your email" / "add your phone" banners go
    // by. A phone sign-up's made-up address never counts as verified
    // (Domain\Identity\AccountEmail).
    $hasEmailVerifiedAt = SignInSchema::hasEmailVerifiedAt($db);
    $stmt = $db->prepare(
        "SELECT email, phone" . ($hasEmailVerifiedAt ? ", email_verified_at" : "") . " FROM users WHERE user_id = :id"
    );
    $stmt->execute([':id' => $userId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $emailVerified = AccountEmail::isVerified($contact, $hasEmailVerifiedAt);
    $hasPhone = trim((string)($contact['phone'] ?? '')) !== '';

    // The transaction PIN lives in the separate credentials database. If
    // that's unreachable, report "unknown" (null) instead of failing the
    // whole response: role and permissions still drive the dashboard.
    $hasPin = null;
    try {
        $hasPin = CredentialsRepository::fromEnvironment()->hasUserTransactionPin($userId);
    } catch (\Throwable $e) {
        error_log("whoami.php credentials DB error: " . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'role' => $roleName,
        'is_agent' => $roleName === 'agent',
        'is_admin' => in_array($roleName, ['admin', 'super_admin'], true),
        'has_pin' => $hasPin,
        'permissions' => $permissions,
        'email_verified' => $emailVerified,
        'email_masked' => $emailVerified ? AccountEmail::mask((string)$contact['email']) : null,
        'has_phone' => $hasPhone,
        'phone_masked' => $hasPhone ? ContactVerificationService::maskPhone((string)$contact['phone']) : null,
    ]);

} catch (Exception $e) {
    error_log("whoami.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to determine user role']);
}
