<?php
/**
 * public/admin/_signing_bootstrap.php
 * Shared setup for the signing pages. Place the four src files in
 * src/Application/Reporting/.
 */
declare(strict_types=1);

define('VM_ROOT', dirname(__DIR__, 2));

require_once VM_ROOT . '/vendor/autoload.php';
require_once VM_ROOT . '/src/Application/Utils/SessionManager.php';
require_once VM_ROOT . '/src/Core/Database/DBConnection.php';
require_once VM_ROOT . '/src/Application/Admin/AdminAudit.php';
require_once VM_ROOT . '/src/Application/Reporting/Contracts.php';
require_once VM_ROOT . '/src/Application/Reporting/SignatureSpecimenService.php';
require_once VM_ROOT . '/src/Application/Reporting/SigningWorkflow.php';

use Application\Utils\SessionManager;
use Application\Admin\AdminAudit;
use Application\Reporting\{DbRoleResolver, TotpMfaVerifier, SignatureSpecimenService, SigningWorkflow};
use Core\Database\DBConnection;

SessionManager::start();
if (!SessionManager::isAdminLoggedIn()) {
    header('Location: admin_login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function vm_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

function vm_check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid form token. Reload the page and try again.');
    }
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$db       = DBConnection::getConnection();
$adminId  = (int)SessionManager::getAdminId();
$roleId   = (int)SessionManager::getAdminRoleId();
$storage  = VM_ROOT . '/storage/secure';   // outside public/ — never web-served
$version  = getenv('RAILWAY_GIT_COMMIT_SHA') ?: 'dev';

$roles     = new DbRoleResolver($db);
$mfa       = new TotpMfaVerifier($db);
$specimens = new SignatureSpecimenService($db, $mfa, $storage . '/signatures');
$workflow  = new SigningWorkflow($db, $roles, $mfa, $specimens, $version, $storage);

function vm_audit(string $action, array $detail = []): void
{
    global $db, $adminId;
    try {
        AdminAudit::recordOrLog($db, $adminId, $action, 'signing', $action, $detail, AdminAudit::CATEGORY_SECURITY);
    } catch (Throwable $e) {
        error_log('[SIGNING] audit failed: ' . $e->getMessage());
    }
}

const VM_PAGE_CSS = <<<CSS
body{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#f4f6f9;color:#1e2833;margin:0}
.wrap{max-width:1100px;margin:0 auto;padding:24px}
h1{color:#1F3A5F;font-size:22px;margin:0 0 4px}
.sub{color:#5f6b7a;margin:0 0 20px}
.card{background:#fff;border:1px solid #d6dde6;border-radius:6px;padding:18px;margin-bottom:16px}
.btn{display:inline-block;padding:9px 16px;border-radius:4px;border:1px solid #1F3A5F;background:#1F3A5F;color:#fff;cursor:pointer;font-size:14px;text-decoration:none}
.btn.ghost{background:#fff;color:#1F3A5F}
.btn.warn{background:#fff;color:#a93226;border-color:#a93226}
.msg{padding:10px 14px;border-radius:4px;margin-bottom:14px}
.ok{background:#e6f4ee;color:#0f6b4f}.err{background:#fbeaea;color:#a93226}
table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #e3e8ee;text-align:left;font-size:14px}
th{background:#1F3A5F;color:#fff;font-weight:600}
.pill{padding:2px 8px;border-radius:10px;font-size:12px;background:#eaf0f6;color:#1F3A5F}
.late{background:#fbeaea;color:#a93226}
input[type=text],input[type=password],textarea{padding:8px;border:1px solid #c4cdd8;border-radius:4px;font-size:14px}
nav a{margin-right:14px;color:#1F3A5F}
CSS;

function vm_nav(): string
{
    return '<nav style="margin-bottom:16px"><a href="admin_dashboard.php">Dashboard</a>'
         . '<a href="signing_inbox.php">Signing inbox</a><a href="my_signature.php">My signature</a>'
         . '<a href="regulator_reports.php">Issued reports</a></nav>';
}
