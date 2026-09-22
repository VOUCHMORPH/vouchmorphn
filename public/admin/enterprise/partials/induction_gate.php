<?php
/**
 * partials/induction_gate.php
 *
 * Until a user has declared their role induction at its current version,
 * the only pages they can reach are their induction, their manual and
 * sign-out. Called from requireEnterpriseAuth(), so it covers every page
 * and every endpoint — including the ones that move money.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/src/Application/Enterprise/InductionCurriculum.php';

use Application\Enterprise\InductionCurriculum;

function enterpriseInductionGate(array $user): void
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($page, ['induction.php', 'manual.php', 'logout.php', 'login.php'], true)) {
        return;
    }

    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $orgId  = (int)($user['organization_id'] ?? 0);

    if (!isset(InductionCurriculum::ROLES[$role])) {
        // A role with no induction must never pass silently.
        enterpriseInductionBlock('Your role has no induction configured. Contact your IT Manager.');
    }

    $hash = InductionCurriculum::curriculumHash($role);
    $cacheKey = "{$userId}|{$orgId}|{$role}|{$hash}";
    if (($_SESSION['induction_ok'] ?? null) === $cacheKey) {
        return;
    }

    $stmt = getDBConnection()->prepare(
        'SELECT 1 FROM induction_declarations
          WHERE user_id = :u AND organization_id = :o AND role_code = :r AND curriculum_sha256 = :h'
    );
    $stmt->execute([':u' => $userId, ':o' => $orgId, ':r' => $role, ':h' => $hash]);

    if ($stmt->fetchColumn()) {
        $_SESSION['induction_ok'] = $cacheKey;
        return;
    }

    unset($_SESSION['induction_ok']);
    enterpriseInductionBlock(null);
}

function enterpriseInductionBlock(?string $message): void
{
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if ($wantsJson) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message ?? 'Complete your role induction before using the platform.',
                          'induction_required' => true]);
        exit;
    }

    if ($message !== null) {
        header('HTTP/1.1 403 Forbidden');
        echo '<!doctype html><meta charset="utf-8"><p style="font:16px/1.5 sans-serif;padding:32px">' . htmlspecialchars($message) . '</p>';
        exit;
    }

    header('Location: /admin/enterprise/induction.php');
    exit;
}
