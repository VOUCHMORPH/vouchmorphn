<?php
// public/api/v1/system/status.php
//
// What customers and agents need to know right now: freezes in force that
// affect them and customer notices approved by the Incident Commander.
// Read by the dashboards every minute (assets/vm-service-status.js). Shows
// only what is safe to publish: no internal reasons, no admin names.
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;

$out = ['service_paused' => false, 'message' => null, 'paused_flows' => [], 'paused_institutions' => [],
        'account_frozen' => false, 'agent_frozen' => false, 'notices' => []];
try {
    $db = DBConnection::getConnection();
    SessionManager::start();
    $u = SessionManager::isLoggedIn() ? SessionManager::getUser() : null;
    $uid = $u ? (string)($u['id'] ?? $u['user_id'] ?? '') : '';

    foreach ($db->query("SELECT scope, target, customer_message FROM ic_controls WHERE resumed_at IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        switch ($c['scope']) {
            case 'SERVICE':
                $out['service_paused'] = true;
                $out['message'] = $c['customer_message'] ?: 'VouchMorph is paused for maintenance. Your money is safe with your bank. Please try again later.';
                break;
            case 'FLOW': $out['paused_flows'][] = $c['target']; break;
            case 'INSTITUTION': $out['paused_institutions'][] = $c['target']; break;
            case 'CLIENT': if ($uid !== '' && $c['target'] === $uid) $out['account_frozen'] = true; break;
            case 'AGENT': if ($uid !== '' && $c['target'] === $uid) $out['agent_frozen'] = true; break;
        }
    }
    $isAgent = $u && (($u['is_agent'] ?? false) || strtolower((string)($u['role'] ?? $u['role_name'] ?? '')) === 'agent');
    $notices = $db->query("
        SELECT broadcast_id, audience, title, body, level, approved_at FROM ic_broadcasts
        WHERE status = 'PUBLISHED' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY approved_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notices as $n) {
        if ($n['audience'] === 'AGENTS' && !$isAgent) continue;
        $out['notices'][] = ['id' => (int)$n['broadcast_id'], 'title' => $n['title'], 'body' => $n['body'], 'level' => $n['level'], 'audience' => $n['audience']];
    }
} catch (Throwable $e) {
    error_log('[system/status] ' . $e->getMessage());   // no freeze table yet: nothing to show
}
echo json_encode($out);
