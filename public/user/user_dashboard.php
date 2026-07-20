<?php
// ============================================================
// FIX 1: Use SessionManager instead of bare session_start()
// ============================================================
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$userData = SessionManager::getUser();
$userName = $userData['full_name'] ?? $userData['username'] ?? 'User';
$userCountry = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
$userCurrency = $userData['currency'] ?? getenv('VOUCHMORPH_CURRENCY') ?: 'BWP';
$userRole = $userData['role'] ?? 'user';
$userId = $userData['id'] ?? $userData['user_id'] ?? 0;

if (empty($userId)) {
    error_log("[DASHBOARD] WARNING: Session user data has no 'id' field.");
}

$apiKey = getenv('VOUCHMORPH_API_KEY') ?: '';
$apiBase = getenv('API_BASE_URL') ?: '';
$isTestMode = empty($apiKey);

if (!function_exists('dashboard_yaml_parse_file')) {

    function dashboard_yaml_castScalar(string $v)
    {
        $v = trim($v);
        if ($v === '') return null;
        if ($v[0] === '"' || $v[0] === "'") {
            $quote = $v[0];
            $len = strlen($v);
            for ($i = 1; $i < $len; $i++) {
                if ($v[$i] === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($v[$i] === $quote) return substr($v, 1, $i - 1);
            }
            return $v;
        }
        $hashPos = strpos($v, ' #');
        if ($hashPos !== false) $v = trim(substr($v, 0, $hashPos));
        $lower = strtolower($v);
        if ($lower === 'true' || $lower === 'yes') return true;
        if ($lower === 'false' || $lower === 'no') return false;
        if ($lower === 'null' || $v === '~') return null;
        if (is_numeric($v)) return $v + 0;
        if ($v[0] === '[' && str_ends_with($v, ']')) {
            $inner = trim(substr($v, 1, -1));
            if ($inner === '') return [];
            return array_map(fn($x) => dashboard_yaml_castScalar(trim($x)), explode(',', $inner));
        }
        return $v;
    }

    function dashboard_yaml_tokenize(string $content): array
    {
        $raw = explode("\n", str_replace("\r\n", "\n", $content));
        $lines = [];
        foreach ($raw as $line) {
            $trimmedRight = rtrim($line);
            if ($trimmedRight === '') continue;
            $stripped = ltrim($trimmedRight);
            if ($stripped === '' || $stripped[0] === '#') continue;
            if (preg_match('/^---\s*$/', $stripped) || preg_match('/^\.\.\.\s*$/', $stripped)) continue;
            $indent = strlen($trimmedRight) - strlen($stripped);
            $lines[] = [$indent, $stripped];
        }
        return array_values($lines);
    }

    function dashboard_yaml_parseBlock(array &$lines, int &$idx, int $blockIndent): array
    {
        $result = [];
        while ($idx < count($lines)) {
            [$indent, $content] = $lines[$idx];
            if ($blockIndent === -1) $blockIndent = $indent;
            if ($indent < $blockIndent) break;
            if ($indent > $blockIndent) { $idx++; continue; }

            if (str_starts_with($content, '- ')) {
                $itemContent = trim(substr($content, 2));
                if ($itemContent !== '' && preg_match('/^([A-Za-z0-9_\.\-]+):\s*(.*)$/', $itemContent, $m)) {
                    $lines[$idx] = [$indent + 2, $itemContent];
                    $item = dashboard_yaml_parseBlock($lines, $idx, $indent + 2);
                } elseif ($itemContent === '') {
                    $idx++;
                    $item = dashboard_yaml_parseBlock($lines, $idx, -1);
                } else {
                    $item = dashboard_yaml_castScalar($itemContent);
                    $idx++;
                }
                $result[] = $item;
                continue;
            }

            if (preg_match('/^([^:]+):\s*(.*)$/', $content, $m)) {
                $key = trim($m[1]);
                $value = $m[2];
                $idx++;
                if ($value === '') {
                    if ($idx < count($lines) && $lines[$idx][0] > $indent) {
                        $result[$key] = dashboard_yaml_parseBlock($lines, $idx, -1);
                    } else {
                        $result[$key] = null;
                    }
                } else {
                    $result[$key] = dashboard_yaml_castScalar($value);
                }
                continue;
            }
            $idx++;
        }
        return $result;
    }

    function dashboard_yaml_parse_file(string $path): array
    {
        if (!file_exists($path)) return [];
        $content = file_get_contents($path);
        if ($content === false) return [];
        try {
            $lines = dashboard_yaml_tokenize($content);
            $idx = 0;
            return dashboard_yaml_parseBlock($lines, $idx, -1);
        } catch (\Throwable $e) {
            error_log("[dashboard_yaml_parse_file] Failed: " . $e->getMessage());
            return [];
        }
    }
}

$countryConfig = [];
$countryConfigPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/config.php';
if (file_exists($countryConfigPath)) $countryConfig = require $countryConfigPath;

$participants = [];
$participantsPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/participants.yaml';
if (file_exists($participantsPath)) {
    $parsed = dashboard_yaml_parse_file($participantsPath);
    $participants = $parsed['participants'] ?? [];
}

$assets = [];
$assetsPath = __DIR__ . '/../../src/Core/Config/assets.yaml';
if (file_exists($assetsPath)) $assets = dashboard_yaml_parse_file($assetsPath);

$availableCountries = [];
$countriesDir = __DIR__ . '/../../src/Core/Config/Countries/';
if (is_dir($countriesDir)) {
    foreach (scandir($countriesDir) as $dir) {
        if (is_dir($countriesDir . $dir) && !in_array($dir, ['.', '..'])) $availableCountries[] = $dir;
    }
}

$assetTypes = [];
foreach ($assets as $assetKey => $assetConfig) {
    $assetTypes[$assetKey] = [
        'icon' => $assetConfig['icon'] ?? '📦',
        'label' => $assetConfig['label'] ?? $assetKey,
        'fields' => $assetConfig['fields'] ?? []
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VouchMorph – Swap</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #FAF3E0; --surface: rgba(0,0,0,0.04); --surface-hover: rgba(0,0,0,0.08);
    --border: rgba(0,0,0,0.12); --border-active: rgba(0,150,160,0.4);
    --text: #2b2418; --text-muted: #5c5346; --text-dim: #8f8570;
    --primary: #00a0ad; --primary-dark: #007d88;
    --gradient: linear-gradient(135deg, #00a0ad 0%, #8a2be2 100%);
    --success: #1a9e5c; --warning: #b8860b; --danger: #d32f2f;
    --radius: 16px; --radius-sm: 10px;
    --font: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    --debug-bg: #1a1a2e;
    --debug-text: #e0e0e0;
    --debug-success: #4ade80;
    --debug-error: #f87171;
    --debug-warning: #fbbf24;
    --debug-info: #60a5fa;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; line-height: 1.5; padding: 24px 16px; display: flex; flex-direction: column; align-items: center; }
.container { width: 100%; max-width: 1040px; margin: 0 auto; }
.topbar { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
.logo { font-size: 22px; font-weight: 800; background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.topbar-right { display: flex; align-items: center; gap: 16px; font-size: 14px; flex-wrap: wrap; }
.topbar-right .greeting { color: var(--text-muted); }
.country-selector { background: rgba(0,0,0,0.04); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 4px 10px; color: var(--text-muted); font-size: 12px; cursor: pointer; font-family: var(--font); }
.logout-btn { color: var(--text-muted); text-decoration: none; padding: 6px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.role-badge { font-size: 10px; color: var(--primary); border: 1px solid var(--primary); padding: 2px 10px; border-radius: 20px; text-transform: uppercase; font-weight: 700; }
.agent-badge { font-size: 10px; color: #fff; background: var(--primary-dark); padding: 2px 10px; border-radius: 20px; text-transform: uppercase; font-weight: 700; }
.test-mode-badge { font-size: 10px; color: #b23b3b; border: 1px solid #b23b3b; padding: 2px 10px; border-radius: 20px; text-transform: uppercase; animation: pulse 2s infinite; font-weight: 700; }
.debug-badge { font-size: 10px; color: #8a2be2; border: 1px solid #8a2be2; padding: 2px 10px; border-radius: 20px; text-transform: uppercase; font-weight: 700; cursor: pointer; transition: var(--transition); }
.debug-badge.active { background: #8a2be2; color: #fff; }
.debug-badge:hover { opacity: 0.8; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.55; } }
.message { padding: 12px 16px; border-radius: var(--radius-sm); margin: 0 0 16px; font-size: 13px; display: none; font-weight: 500; }
.message.show { display: block; }
.message.info { background: rgba(0,160,173,0.12); border-left: 3px solid var(--primary); color: #00707a; }
.message.success { background: rgba(26,158,92,0.12); border-left: 3px solid var(--success); color: #146b40; }
.message.error { background: rgba(211,47,47,0.1); border-left: 3px solid var(--danger); color: #a12525; }
.message.warning { background: rgba(184,134,11,0.12); border-left: 3px solid var(--warning); color: #8a6508; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; }
.section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; margin-bottom: 12px; }
.section-title .n { width: 20px; height: 20px; border-radius: 50%; background: var(--gradient); color: #fff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.swap-columns { display: flex; align-items: flex-start; gap: 28px; }
.swap-columns > .section { flex: 1 1 0; min-width: 0; }
@media (max-width: 860px) { .container { max-width: 560px; } .swap-columns { flex-direction: column; } }
.swap-divider { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 20px 0; color: var(--text-dim); }
.swap-divider::before, .swap-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.field-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 4px; font-weight: 700; }
.field-group { margin-bottom: 12px; }
.field-group label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
.field-group input, .field-group select { width: 100%; padding: 12px 14px; background: #fff; border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text); font-size: 16px; font-family: var(--font); }
.field-group input.invalid { border-color: var(--danger); }
.field-group .help { font-size: 12px; color: var(--text-dim); margin-top: 4px; }
.amount-field { position: relative; }
.amount-field input { padding-right: 64px; font-size: 18px; font-weight: 600; }
.amount-field .currency-suffix { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; font-weight: 700; }
.asset-fields { margin: 4px 0 12px; padding: 14px; background: rgba(0,0,0,0.02); border-radius: var(--radius-sm); }
.identity-field { margin: 4px 0 12px; padding: 14px; background: rgba(0,160,173,0.06); border: 1px dashed var(--primary); border-radius: var(--radius-sm); }
.cta-row { display: flex; justify-content: center; margin-top: 24px; gap: 12px; flex-wrap: wrap; }
.btn { padding: 14px 40px; border: none; border-radius: 999px; font-size: 14px; font-weight: 700; font-family: var(--font); cursor: pointer; transition: var(--transition); }
.btn-primary { background: var(--gradient); color: #fff; }
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,160,173,0.3); }
.btn-secondary { background: #fff; color: var(--text); border: 1px solid var(--border); padding: 14px 32px; border-radius: 999px; font-weight: 600; cursor: pointer; }
.btn-secondary:hover { background: var(--surface-hover); }
.btn-danger-outline { background: transparent; color: var(--danger); border: 1px solid rgba(211,47,47,0.35); padding: 6px 14px; border-radius: 999px; font-size: 11px; cursor: pointer; font-weight: 600; }
.btn-sm { padding: 8px 18px !important; font-size: 12px; }
.btn-debug { background: #1a1a2e; color: var(--debug-text); border: 1px solid #333; padding: 8px 16px; border-radius: 999px; font-size: 11px; cursor: pointer; font-weight: 600; font-family: monospace; }
.btn-debug:hover { background: #2a2a4e; }
.btn-debug.active { background: #8a2be2; color: #fff; border-color: #8a2be2; }
.quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: -4px 0 12px; }
.quick-link { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: var(--primary-dark); background: rgba(0,160,173,0.08); border: 1px solid rgba(0,160,173,0.2); padding: 5px 12px; border-radius: 999px; cursor: pointer; transition: var(--transition); }
.quick-link:hover { background: rgba(0,160,173,0.15); }
.quick-link.muted { color: var(--text-muted); background: rgba(0,0,0,0.04); border-color: var(--border); }
.quick-link.danger { color: var(--danger); background: rgba(211,47,47,0.06); border-color: rgba(211,47,47,0.2); }
.source-row { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 10px; background: #fff; }
.source-row-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.multi-total { text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 12px; }
.spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: spin 0.7s linear infinite; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(20,15,5,0.55); backdrop-filter: blur(6px); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal { background: #fff; border-radius: var(--radius); max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 24px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px solid var(--border); margin-bottom: 16px; }
.modal-close { background: none; border: none; color: var(--text-muted); font-size: 24px; cursor: pointer; }
.preview-box { background: rgba(0,0,0,0.02); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; margin: 12px 0; }
.preview-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid rgba(0,0,0,0.06); gap: 12px; }
.preview-row .value.highlight { color: var(--primary-dark); font-size: 20px; }
.result-box { text-align: center; padding: 12px 0; }
.result-box .icon { font-size: 48px; }
.result-box .atm-code { margin: 12px 0; padding: 16px; background: rgba(0,160,173,0.08); border: 2px solid var(--primary); border-radius: var(--radius-sm); }
.result-box .atm-code .code { font-size: 28px; font-weight: 700; font-family: monospace; letter-spacing: 4px; color: var(--primary-dark); }
.raw-json { text-align: left; font-size: 11px; background: #f4efe4; border-radius: var(--radius-sm); padding: 10px; white-space: pre-wrap; word-break: break-all; color: var(--text-muted); margin-top: 12px; max-height: 200px; overflow-y: auto; }
@media (max-width: 480px) { body { padding: 12px; } .btn, .btn-secondary { padding: 12px 24px; width: 100%; } .cta-row { flex-direction: column; } .topbar { flex-direction: column; align-items: stretch; } }

.source-card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; margin-bottom: 10px; }
.source-card .source-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.source-card .source-institution { font-weight: 600; font-size: 16px; }
.source-card .source-details { font-size: 13px; color: var(--text-muted); }
.source-card .source-status { font-size: 11px; padding: 2px 10px; border-radius: 10px; }
.source-status.active { background: #dcfce7; color: #166534; }
.source-status.pending { background: #fef3c7; color: #8a5a0b; }
.source-status.inactive { background: #fbeceb; color: var(--danger); }
.otp-input-group { display: flex; gap: 8px; margin: 12px 0; }
.otp-input-group input { flex: 1; }
.otp-input-group button { flex-shrink: 0; }

.source-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 4px 12px; border-radius: 999px; cursor: pointer; border: 1px solid var(--border); background: #fff; transition: all 0.2s; }
.source-chip:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
.source-chip .chip-icon { font-size: 12px; }
.source-chip .chip-identifier { font-weight: 400; color: var(--text-muted); font-size: 10px; }
.source-chip:hover .chip-identifier { color: rgba(255,255,255,0.8); }
.source-chip.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.source-chip.active .chip-identifier { color: rgba(255,255,255,0.8); }

/* Debug Styles */
.debug-panel { background: var(--debug-bg); color: var(--debug-text); border-radius: var(--radius-sm); padding: 16px; margin-top: 16px; font-family: 'Courier New', monospace; font-size: 12px; max-height: 400px; overflow-y: auto; display: none; border: 1px solid #333; }
.debug-panel.show { display: block; }
.debug-panel .log-line { padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
.debug-panel .log-time { color: #666; }
.debug-panel .log-success { color: var(--debug-success); }
.debug-panel .log-error { color: var(--debug-error); }
.debug-panel .log-warning { color: var(--debug-warning); }
.debug-panel .log-info { color: var(--debug-info); }
.debug-panel .log-data { color: #a78bfa; font-size: 11px; white-space: pre-wrap; margin-left: 16px; padding: 4px 8px; background: rgba(255,255,255,0.05); border-radius: 4px; }
.debug-panel .log-separator { border-top: 1px solid rgba(255,255,255,0.1); margin: 8px 0; }

.hold-detail-card { background: #f8f6f0; border-radius: var(--radius-sm); padding: 12px; margin-bottom: 8px; border-left: 3px solid var(--primary); }
.hold-detail-card.success { border-left-color: var(--success); }
.hold-detail-card.failed { border-left-color: var(--danger); }
.hold-detail-card .hold-amount { font-weight: 700; font-size: 16px; }
.hold-detail-card .hold-status { font-size: 11px; }
.hold-detail-card .hold-error { color: var(--danger); font-size: 12px; margin-top: 4px; }
</style>
</head>
<body>
<div class="container">
<div class="topbar">
    <div class="logo">VOUCHMORPH</div>
    <div class="topbar-right">
        <span class="greeting">Hello, <span id="userName"><?php echo htmlspecialchars($userName); ?></span></span>
        <span class="role-badge"><?php echo htmlspecialchars(strtoupper($userRole)); ?></span>
        <span class="agent-badge" id="agentBadge" style="display:none;">🏪 Agent</span>
        <?php if ($isTestMode): ?><span class="test-mode-badge">🔓 TEST MODE</span><?php endif; ?>
        <span class="debug-badge" id="debugBadge" onclick="toggleDebugMode()">🐛 Debug: OFF</span>
        <select class="country-selector" id="countrySelector" onchange="switchCountry(this.value)">
            <?php foreach ($availableCountries as $country): ?>
            <option value="<?php echo htmlspecialchars($country); ?>" <?php echo $country === $userCountry ? 'selected' : ''; ?>><?php echo htmlspecialchars($country); ?></option>
            <?php endforeach; ?>
        </select>
        <span class="quick-link" onclick="openMySources()">🔗 My Sources</span>
        <span class="quick-link" onclick="openSwapHistory()">📋 History</span>
        <span class="quick-link" id="claimsButton" onclick="openClaimsModal()" style="display:none;">💰 Claim Money <span id="claimsBadge" style="background:var(--danger);color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"></span></span>
        <span class="quick-link" id="agentToolsButton" onclick="openAgentToolsModal()" style="display:none;">🏪 Agent Tools</span>
        <span class="quick-link muted" onclick="openAgentModal()">🤝 Become an Agent</span>
        <span class="quick-link muted" onclick="openProfileModal()">👤 My Profile</span>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div id="mainMessage" class="message"></div>

<!-- Debug Panel -->
<div class="debug-panel" id="debugPanel">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-weight:700;color:var(--debug-info);">🐛 DEBUG LOG</span>
        <div>
            <button class="btn-debug btn-sm" onclick="clearDebugLog()" style="margin-right:4px;">Clear</button>
            <button class="btn-debug btn-sm" onclick="toggleDebugPanel()">Toggle</button>
        </div>
    </div>
    <div id="debugLogContent"></div>
</div>

<div class="card">
    <div class="swap-columns">
    <div class="section" id="fromSection">
        <div class="section-title"><span class="n">1</span> From</div>
        
        <div id="savedSourcesContainer" style="margin-bottom:12px;display:none;">
            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
                <span style="font-size:11px;color:var(--text-muted);font-weight:600;">🔗 Quick Select:</span>
                <div id="savedSourcesChips" style="display:inline-flex;flex-wrap:wrap;gap:6px;"></div>
                <span class="quick-link muted" onclick="clearSourceSelection()" style="font-size:10px;padding:2px 8px;display:none;" id="clearSourceBtn">✕ Clear</span>
            </div>
            <div style="font-size:10px;color:var(--text-dim);margin-top:4px;">Click a saved source to auto-fill your details. You only need to enter the amount.</div>
        </div>
        
        <div class="field-group">
            <label>Institution</label>
            <select id="fromInstSelect" onchange="selectFromInst(this.value)"><option value="">Select institution</option></select>
        </div>
        <div class="field-group" id="fromAssetGroup" style="display:none;">
            <label>Asset Type</label>
            <select id="fromAssetSelect" onchange="selectFromAsset(this.value)"></select>
        </div>
        <div class="asset-fields" id="fromFields"></div>
        <div class="field-group amount-field">
            <label>Amount</label>
            <input type="number" id="fromAmount" placeholder="0.00" step="0.01" min="0.01">
            <span class="currency-suffix" id="fromCurrencyLabel"><?php echo htmlspecialchars($userCurrency); ?></span>
            <div class="help" id="fromLimitsHelp"></div>
            <div class="help" id="fromCurrencyInfo" style="font-size:11px;color:var(--text-dim);margin-top:2px;"></div>
            <div class="help" id="sourceSelectedHelp" style="font-size:11px;color:var(--primary-dark);margin-top:2px;display:none;">✅ Source auto-filled. Enter amount above.</div>
        </div>
    </div>
    <div class="swap-divider"><span>⇅</span></div>
    <div class="section" id="toSection">
        <div class="section-title"><span class="n">2</span> To</div>
        <div class="field-group">
            <label>Swap Type</label>
            <select id="swapTypeSelect" onchange="setSwapType(this.value)">
                <option value="DEPOSIT">Deposit</option>
                <option value="CASHOUT">Cashout</option>
                <option value="IDENTITY">Send to Identity</option>
                <option value="MULTI_SOURCE">Multi-Source</option>
            </select>
        </div>
        <div class="quick-actions">
            <span class="quick-link" onclick="quickSetSwapType('IDENTITY')">🔑 Swap to Identity</span>
            <span class="quick-link" onclick="quickSetSwapType('MULTI_SOURCE')">🧩 Multi-Source</span>
        </div>
        <div id="toInstSection">
            <div class="field-group">
                <label>Institution</label>
                <select id="toInstSelect" onchange="selectToInst(this.value)"><option value="">Select institution</option></select>
            </div>
        </div>
        <div class="field-group" id="toAssetSection" style="display:none;">
            <label>Asset Type</label>
            <select id="toAssetSelect" onchange="selectToAsset(this.value)"></select>
        </div>
        <div class="asset-fields" id="toFields" style="display:none;"></div>
        <div id="cashoutFields" style="display:none;">
            <div class="field-group">
                <label>Delivery Method</label>
                <select id="deliveryMethodSelect" onchange="setDeliveryMethod(this.value)">
                    <option value="ATM">🏧 ATM</option>
                    <option value="AGENT">🧑‍💼 Agent</option>
                </select>
            </div>
            <div class="field-group">
                <label>Beneficiary Phone (optional)</label>
                <input id="beneficiaryPhone" placeholder="+267XXXXXXXX" oninput="state.beneficiaryPhone=this.value; refreshUI();">
                <div class="help">Cashout code will be sent by SMS if provided</div>
            </div>
        </div>
        <div class="identity-field" id="identityFields" style="display:none;">
            <div class="field-group">
                <label>Identity Type</label>
                <select id="identityType" onchange="state.toIdentityType=this.value; updateIdentityHelp();">
                    <option value="national_id">National ID</option>
                    <option value="birth_certificate">Birth Certificate</option>
                    <option value="voter_id">Voter ID</option>
                    <option value="phone">Phone Number</option>
                    <option value="email">Email</option>
                </select>
            </div>
            <div class="field-group">
                <label>Identity Value</label>
                <input id="identityValue" placeholder="Enter the identity value" oninput="state.toIdentityValue=this.value.trim(); refreshUI();">
            </div>
            <div class="field-group">
                <label>SMS Notification (optional)</label>
                <input id="identitySms" placeholder="Phone to send SMS notification" oninput="state.toIdentitySms=this.value">
            </div>
            <div class="hint" id="identityHint" style="font-size:12px;color:var(--text-muted);margin-top:4px;">The recipient will be notified and can claim the funds within 24 hours.</div>
        </div>
        <div id="multiSourceFields" style="display:none;">
            <label class="field-label">Sources (minimum 2)</label>
            <div id="multiSourceList"></div>
            <span class="quick-link" onclick="addMultiSourceRow()">➕ Add another source</span>
            <div class="multi-total">Total requested: <span class="amt" id="multiTotal"><?php echo $userCurrency; ?> 0.00</span></div>
        </div>
    </div>
    </div>
    <div class="cta-row">
        <button class="btn btn-primary" id="reviewBtn" onclick="previewSwap()" disabled>Review Swap →</button>
    </div>
</div>
</div>

<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" id="modalContent">
        <div class="modal-header">
            <h2 id="modalTitle">Swap Preview</h2>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<script>
const CONFIG = {
    API_KEY: '<?php echo htmlspecialchars($apiKey); ?>',
    COUNTRY_CODE: '<?php echo htmlspecialchars($userCountry); ?>',
    CURRENCY: '<?php echo htmlspecialchars($userCurrency); ?>',
    API_BASE: '<?php echo htmlspecialchars($apiBase); ?>',
    IS_TEST_MODE: <?php echo $isTestMode ? 'true' : 'false'; ?>,
    PREVIEW_ENDPOINT: '<?php echo $apiBase; ?>/api/v1/swap/preview.php',
    EXECUTE_ENDPOINT: '<?php echo $apiBase; ?>/api/v1/swap/execute.php',
    USER_ID: <?php echo json_encode($userId); ?>,
};
const PARTICIPANTS = <?php echo json_encode($participants); ?>;
const ASSETS = <?php echo json_encode($assetTypes); ?>;
const ASSET_KEY_MAP = {};
Object.keys(ASSETS).forEach(k => { ASSET_KEY_MAP[k.trim().toUpperCase()] = k; });

function getAssetConfig(type) {
    if (!type) return null;
    if (ASSETS[type]) return ASSETS[type];
    const normalized = String(type).trim().toUpperCase();
    const realKey = ASSET_KEY_MAP[normalized];
    if (realKey) return ASSETS[realKey];
    return null;
}

let state = {
    fromInst: null, fromAsset: null, fromFields: {}, fromAmount: 0,
    swapType: 'DEPOSIT', toInst: null, toAsset: null, toFields: {},
    deliveryMethod: 'ATM', beneficiaryPhone: '',
    toIdentityType: 'national_id', toIdentityValue: '', toIdentitySms: '',
    multiSources: [], lastPreview: null, swapPayload: null,
};
let savedIdentities = [];
let userSources = [];
let agentSearchResult = null;
let agentSearchData = null;
let agentSearchRaw = null;
let selectedSourceId = null;
let debugMode = false;
let debugLogs = [];

// ============================================================
// DEBUG FUNCTIONS
// ============================================================

function toggleDebugMode() {
    debugMode = !debugMode;
    const badge = document.getElementById('debugBadge');
    const panel = document.getElementById('debugPanel');
    
    if (debugMode) {
        badge.textContent = '🐛 Debug: ON';
        badge.classList.add('active');
        panel.classList.add('show');
        addDebugLog('🐛 Debug mode ENABLED');
        addDebugLog('ℹ️ All API calls and operations will be logged');
    } else {
        badge.textContent = '🐛 Debug: OFF';
        badge.classList.remove('active');
        panel.classList.remove('show');
    }
}

function toggleDebugPanel() {
    const panel = document.getElementById('debugPanel');
    panel.classList.toggle('show');
}

function clearDebugLog() {
    debugLogs = [];
    document.getElementById('debugLogContent').innerHTML = '';
    addDebugLog('🗑️ Log cleared');
}

function addDebugLog(message, data = null) {
    if (!debugMode) return;
    
    const timestamp = new Date().toLocaleTimeString();
    let logEntry = { timestamp, message, data };
    debugLogs.push(logEntry);
    
    // Determine log level
    let level = 'info';
    if (message.includes('✅') || message.includes('success')) level = 'success';
    else if (message.includes('❌') || message.includes('error') || message.includes('failed')) level = 'error';
    else if (message.includes('⚠️') || message.includes('warning')) level = 'warning';
    
    const container = document.getElementById('debugLogContent');
    if (!container) return;
    
    let html = `<div class="log-line">`;
    html += `<span class="log-time">[${timestamp}]</span> `;
    html += `<span class="log-${level}">${escapeHtml(message)}</span>`;
    if (data) {
        const jsonStr = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
        html += `<div class="log-data">${escapeHtml(jsonStr)}</div>`;
    }
    html += `</div>`;
    
    container.innerHTML += html;
    container.scrollTop = container.scrollHeight;
    
    // Also log to console for debugging
    console.log(`[${timestamp}] ${message}`, data || '');
}

// ============================================================
// UI HELPERS
// ============================================================

function getInstitutionCurrency(instCode) {
    if (!instCode) return CONFIG.CURRENCY;
    return PARTICIPANTS[instCode]?.limits?.currency || CONFIG.CURRENCY;
}

function updateCurrencyDisplay() {
    const fromCurrency = getInstitutionCurrency(state.fromInst);
    const fromLabel = document.getElementById('fromCurrencyLabel');
    if (fromLabel) fromLabel.textContent = fromCurrency;
    const fromInfo = document.getElementById('fromCurrencyInfo');
    if (fromInfo) fromInfo.textContent = state.fromInst ? `💰 Source currency: ${fromCurrency}` : '';
}

function switchCountry(country) {
    if (country !== CONFIG.COUNTRY_CODE) window.location.href = '?country=' + encodeURIComponent(country);
}

function buildHeaders() {
    const headers = { 'Content-Type': 'application/json' };
    if (CONFIG.COUNTRY_CODE) headers['X-Country-Code'] = CONFIG.COUNTRY_CODE;
    if (CONFIG.API_KEY && !CONFIG.IS_TEST_MODE) headers['X-API-Key'] = CONFIG.API_KEY;
    return headers;
}

async function callApi(endpoint, payload) {
    let url = endpoint;
    if (CONFIG.IS_TEST_MODE) url += (url.includes('?') ? '&' : '?') + 'test_mode=1';
    
    addDebugLog(`📡 Calling API: ${url}`, payload);
    
    let response, body;
    try {
        response = await fetch(url, { method: 'POST', headers: buildHeaders(), body: JSON.stringify(payload) });
    } catch (networkErr) {
        addDebugLog(`❌ Network error: ${networkErr.message}`);
        return { ok: false, error: 'Network error: could not reach ' + url + ' (' + networkErr.message + ')' };
    }
    
    try { body = await response.json(); } catch (parseErr) {
        addDebugLog(`❌ Parse error: ${parseErr.message}`);
        return { ok: false, error: 'Server returned a non-JSON response (HTTP ' + response.status + ')' };
    }
    
    addDebugLog(`📦 Response (${response.status}):`, body);
    
    if (!response.ok || body.success === false) {
        return { ok: false, error: body.error || ('HTTP ' + response.status), body };
    }
    return { ok: true, body };
}

function escapeHtml(str) { 
    if (str == null) return '';
    const div = document.createElement('div'); 
    div.textContent = String(str); 
    return div.innerHTML; 
}

function showMessage(text, type = 'info') {
    const el = document.getElementById('mainMessage');
    el.textContent = text; 
    el.className = `message show ${type}`;
    clearTimeout(showMessage._t);
    showMessage._t = setTimeout(() => el.classList.remove('show'), 6000);
    
    if (debugMode) {
        addDebugLog(`📢 [${type}] ${text}`);
    }
}

function openModal(title, bodyHtml) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
    document.getElementById('modal').classList.add('active');
}

function closeModal() { 
    document.getElementById('modal').classList.remove('active'); 
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ============================================================
// INSTRUMENTATION - Wrapped functions for debugging
// ============================================================

// Wrap the original callApi to ensure debug logging
const originalCallApi = callApi;
callApi = async function(endpoint, payload) {
    addDebugLog(`📡 Calling: ${endpoint}`);
    const result = await originalCallApi(endpoint, payload);
    if (endpoint.includes('finalize_claim')) {
        addDebugLog(`📦 Finalize response:`, result);
    }
    return result;
};

// ============================================================
// REST OF THE DASHBOARD FUNCTIONS (same as before with debug hooks)
// ============================================================

// [Include all the existing dashboard functions here - selectFromInst, 
// selectFromAsset, renderDynamicFields, etc. from the original]

// ============================================================
// AGENT TOOLS WITH DEBUGGING - ENHANCED
// ============================================================

function renderAgentToolsSearch() {
    return `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">
            Search for a client's pending identity payment. You'll need to physically verify their document 
            and have them tell you their claim PIN before you can finalize.
            ${debugMode ? '<span style="color:var(--debug-info);">🐛 Debug mode is ON - all steps will be logged.</span>' : ''}
        </div>
        <div class="field-group">
            <label>Document Type</label>
            <select id="agentSearchType">
                <option value="national_id">National ID</option>
                <option value="birth_certificate">Birth Certificate</option>
                <option value="voter_id">Voter ID</option>
            </select>
        </div>
        <div class="field-group">
            <label>Document Number</label>
            <input id="agentSearchValue" placeholder="Enter the client's ID number">
        </div>
        <div class="cta-row">
            <button class="btn btn-primary" onclick="searchAgentClaim()">🔍 Search</button>
            <button class="btn btn-secondary" onclick="toggleDebugMode()" id="agentDebugToggle">
                🐛 Debug: ${debugMode ? 'ON' : 'OFF'}
            </button>
            ${debugMode ? `<button class="btn-debug" onclick="clearDebugLog()">🗑️ Clear Log</button>` : ''}
        </div>
        <div id="agentSearchResults" style="margin-top:16px;"></div>
    `;
}

function openAgentToolsModal() { 
    addDebugLog('🔓 Opening Agent Tools');
    openModal('🏪 Agent Tools', renderAgentToolsSearch()); 
}

async function searchAgentClaim() {
    const identityType = document.getElementById('agentSearchType').value;
    const identityValue = document.getElementById('agentSearchValue').value.trim();
    const resultsBox = document.getElementById('agentSearchResults');
    
    if (!identityValue) { 
        showMessage('Enter the document number to search.', 'warning'); 
        return; 
    }
    
    resultsBox.innerHTML = '<div style="text-align:center;padding:16px;"><div class="spinner"></div> Searching...</div>';
    addDebugLog(`🔍 Searching for: ${identityType}=${identityValue}`);
    
    try {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/search_claim.php', { 
            identity_type: identityType, 
            identity_value: identityValue 
        });
        
        agentSearchRaw = result;
        addDebugLog(`📦 Search response:`, result);
        
        if (!result.ok) { 
            resultsBox.innerHTML = `<div style="color:var(--danger);">❌ ${escapeHtml(result.error)}</div>`;
            addDebugLog(`❌ Search failed: ${result.error}`);
            return; 
        }
        
        const data = result.body.data;
        agentSearchData = data;
        
        if (!data) {
            resultsBox.innerHTML = '<div style="font-size:12px;color:var(--text-dim);">No pending payment found for this identity.</div>';
            addDebugLog('ℹ️ No pending holds found');
            return;
        }
        
        // Display results with debug info
        if (data.multi_currency) {
            let html = `
                <div style="margin-bottom:12px;">
                    <strong>Multiple currencies found for this identity:</strong>
                    <span style="font-size:11px;color:var(--text-muted);margin-left:8px;">(Click Claim to see all holds)</span>
                </div>`;
            data.balances.forEach((b, index) => {
                const currency = escapeHtml(b.currency);
                const totalAmount = parseFloat(b.total_amount).toFixed(2);
                const swapCount = parseInt(b.swap_count);
                const identityTypeEscaped = escapeHtml(data.identity_type);
                const identityValueEscaped = escapeHtml(data.identity_value);
                
                html += `
                    <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:10px;background:#fff;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                            <div>
                                <div style="font-weight:700;font-size:18px;color:var(--primary-dark);">${totalAmount} ${currency}</div>
                                <div style="font-size:12px;color:var(--text-muted);">From ${swapCount} different source(s)</div>
                                <div style="font-size:11px;color:var(--text-dim);">Expires ${b.earliest_expires_at ? new Date(b.earliest_expires_at).toLocaleString() : 'soon'}</div>
                                ${debugMode ? `<div style="font-size:10px;color:var(--text-dim);margin-top:4px;">Hold IDs: ${(b.hold_ids || []).join(', ')}</div>` : ''}
                            </div>
                            <div>
                                <button class="btn btn-primary btn-sm" onclick="openAgentFinalizeFormAggregated('${identityTypeEscaped}', '${identityValueEscaped}', '${currency}', ${totalAmount}, ${swapCount})">💰 Claim</button>
                                ${debugMode ? `<button class="btn-debug btn-sm" onclick="showHoldDetails()" style="margin-top:4px;">🔍 Debug</button>` : ''}
                            </div>
                        </div>
                        ${debugMode ? `<div style="margin-top:8px;padding:8px;background:#f8f6f0;border-radius:4px;font-size:10px;font-family:monospace;white-space:pre-wrap;">Hold IDs: ${JSON.stringify(b.hold_ids || [])}\nReferences: ${JSON.stringify(b.swap_references || [])}</div>` : ''}
                    </div>
                `;
            });
            resultsBox.innerHTML = html;
            addDebugLog(`✅ Found ${data.balances.length} currency groups`);
            return;
        }
        
        // Single currency
        const currency = escapeHtml(data.currency);
        const totalAmount = parseFloat(data.total_amount).toFixed(2);
        const swapCount = parseInt(data.swap_count);
        const identityTypeEscaped = escapeHtml(data.identity_type);
        const identityValueEscaped = escapeHtml(data.identity_value);
        const holdIds = data.hold_ids || [];
        const swapRefs = data.swap_references || [];
        
        addDebugLog(`✅ Found ${swapCount} holds totaling ${totalAmount} ${currency}`);
        addDebugLog(`📋 Hold IDs:`, holdIds);
        addDebugLog(`📋 Swap References:`, swapRefs);
        
        resultsBox.innerHTML = `
            <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:10px;background:#fff;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:700;font-size:18px;color:var(--primary-dark);">${totalAmount} ${currency}</div>
                        <div style="font-size:12px;color:var(--text-muted);">From ${swapCount} different source(s)</div>
                        <div style="font-size:11px;color:var(--text-dim);">Expires ${data.earliest_expires_at ? new Date(data.earliest_expires_at).toLocaleString() : 'soon'}</div>
                        ${debugMode ? `<div style="font-size:10px;color:var(--text-dim);margin-top:4px;">Hold IDs: ${holdIds.join(', ')}</div>` : ''}
                    </div>
                    <div>
                        <button class="btn btn-primary btn-sm" onclick="openAgentFinalizeFormAggregated('${identityTypeEscaped}', '${identityValueEscaped}', '${currency}', ${totalAmount}, ${swapCount})">💰 Claim</button>
                        ${debugMode ? `<button class="btn-debug btn-sm" onclick="showHoldDetails()" style="margin-top:4px;">🔍 Debug</button>` : ''}
                    </div>
                </div>
                ${debugMode ? `
                <div style="margin-top:8px;padding:8px;background:#f8f6f0;border-radius:4px;font-size:10px;font-family:monospace;white-space:pre-wrap;">
                    📋 Hold IDs: ${JSON.stringify(holdIds)}
                    📋 References: ${JSON.stringify(swapRefs)}
                    📋 Identity: ${identityTypeEscaped}=${identityValueEscaped}
                </div>` : ''}
            </div>
        `;
        
    } catch (error) {
        resultsBox.innerHTML = `<div style="color:var(--danger);">❌ Error: ${escapeHtml(error.message)}</div>`;
        addDebugLog(`❌ Exception in search: ${error.message}`);
        console.error('[searchAgentClaim] Error:', error);
    }
}

function showHoldDetails() {
    if (!agentSearchData) {
        showMessage('No search data available. Search first.', 'warning');
        return;
    }
    
    const data = agentSearchData;
    let html = `
        <div style="font-weight:600;margin-bottom:12px;">🔍 Hold Details</div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">
            Identity: ${escapeHtml(data.identity_type)} = ${escapeHtml(data.identity_value)}
        </div>
    `;
    
    if (data.multi_currency) {
        data.balances.forEach(b => {
            html += `
                <div class="hold-detail-card">
                    <div style="font-weight:600;font-size:16px;color:var(--primary-dark);">${b.total_amount} ${b.currency}</div>
                    <div style="font-size:11px;color:var(--text-muted);">${b.swap_count} holds</div>
                    <div style="font-size:10px;font-family:monospace;margin-top:4px;">
                        Hold IDs: ${(b.hold_ids || []).join(', ')}
                    </div>
                    <div style="font-size:10px;font-family:monospace;">
                        References: ${(b.swap_references || []).join(', ')}
                    </div>
                    <div style="font-size:10px;color:var(--text-dim);margin-top:4px;">
                        Expires: ${b.earliest_expires_at ? new Date(b.earliest_expires_at).toLocaleString() : 'unknown'}
                    </div>
                </div>
            `;
        });
    } else {
        html += `
            <div class="hold-detail-card">
                <div style="font-weight:600;font-size:16px;color:var(--primary-dark);">${data.total_amount} ${data.currency}</div>
                <div style="font-size:11px;color:var(--text-muted);">${data.swap_count} holds</div>
                <div style="font-size:10px;font-family:monospace;margin-top:4px;">
                    Hold IDs: ${(data.hold_ids || []).join(', ')}
                </div>
                <div style="font-size:10px;font-family:monospace;">
                    References: ${(data.swap_references || []).join(', ')}
                </div>
                <div style="font-size:10px;color:var(--text-dim);margin-top:4px;">
                    Expires: ${data.earliest_expires_at ? new Date(data.earliest_expires_at).toLocaleString() : 'unknown'}
                </div>
            </div>
        `;
    }
    
    // Add debug info
    html += `
        <div style="margin-top:16px;padding:12px;background:#f8f6f0;border-radius:var(--radius-sm);font-size:10px;font-family:monospace;">
            <div style="font-weight:600;margin-bottom:4px;">🐛 Raw Data:</div>
            <pre style="white-space:pre-wrap;word-break:break-all;">${escapeHtml(JSON.stringify(data, null, 2))}</pre>
        </div>
    `;
    
    openModal('🔍 Hold Details', html);
}

// ============================================================
// AGGREGATED CLAIM FINALIZATION WITH DEBUGGING
// ============================================================

function openAgentFinalizeFormAggregated(identityType, identityValue, currency, totalAmount, swapCount) {
    const data = agentSearchData;
    if (!data) {
        showMessage('Search data not found. Please search again.', 'error');
        return;
    }
    
    if (!agentStatus.approved_destinations || agentStatus.approved_destinations.length === 0) {
        openModal('Agent Tools', '<div style="color:var(--danger);">You have no approved agent destination account. Register one first.</div>');
        return;
    }
    
    // Debug: Show what holds will be claimed
    const holdIds = data.multi_currency ? 
        data.balances.flatMap(b => b.hold_ids || []) : 
        (data.hold_ids || []);
    const swapRefs = data.multi_currency ? 
        data.balances.flatMap(b => b.swap_references || []) : 
        (data.swap_references || []);
    
    addDebugLog(`📋 Claiming ${holdIds.length} holds for ${identityValue}`);
    addDebugLog(`📋 Hold IDs:`, holdIds);
    addDebugLog(`📋 References:`, swapRefs);
    
    const destOptions = agentStatus.approved_destinations.map(d => 
        `<option value="${d.id}">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)} - ${escapeHtml(d.identifier)}</option>`
    ).join('');
    
    const searchTypeLabel = IDENTITY_TYPE_LABELS[document.getElementById('agentSearchType')?.value] || 'document';
    
    // Show debug info about holds being claimed
    const holdDebugHtml = debugMode ? `
        <div style="margin-top:8px;padding:8px;background:#f8f6f0;border-radius:4px;font-size:10px;font-family:monospace;white-space:pre-wrap;max-height:100px;overflow-y:auto;">
            📋 Holds to claim (${holdIds.length}):
            IDs: ${holdIds.join(', ')}
            References: ${swapRefs.join(', ')}
        </div>
    ` : '';
    
    // Build the form
    let body = `
        <div style="background:rgba(0,160,173,0.06);border-radius:var(--radius-sm);padding:14px;margin-bottom:14px;">
            <div style="font-size:12px;color:var(--text-muted);">Client's total balance</div>
            <div style="font-size:24px;font-weight:700;color:var(--primary-dark);">${totalAmount} ${currency}</div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">
                This is an aggregated balance from ${swapCount} different source(s).
                The full amount deposits into your account. Whatever the client doesn't take as cash today 
                is instantly sent back to their identity as a new claim.
            </div>
            ${holdDebugHtml}
        </div>
        <div class="field-group">
            <label>Deposit into</label>
            <select id="agentDestSelect">${destOptions}</select>
        </div>
        <div class="field-group">
            <label>Cash to give the client now</label>
            <input type="number" id="cashNowAmount" min="0" max="${totalAmount}" step="0.01" value="${totalAmount}">
            <div class="help">Leave less than the full amount to split — the rest becomes a new claim for them to collect elsewhere.</div>
        </div>
        <div class="quick-actions" style="margin:-4px 0 12px;">
            <span class="quick-link" onclick="document.getElementById('cashNowAmount').value=${totalAmount}">Give it all</span>
            <span class="quick-link muted" onclick="document.getElementById('cashNowAmount').value=0">Give none now</span>
        </div>
        <div class="field-group">
            <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:400;">
                <input type="checkbox" id="agentDocVerified"> I have physically verified the client's ${searchTypeLabel}
            </label>
        </div>
        <div class="field-group">
            <label>Client's Claim PIN</label>
            <input type="password" id="agentClaimPin" inputmode="numeric" maxlength="6" placeholder="Ask the client for their PIN">
            <div class="help">The client must tell you this themselves — never accept a claim without it.</div>
        </div>
    `;
    
    // Add debug buttons
    if (debugMode) {
        body += `
            <div class="cta-row" style="margin-top:12px;">
                <button class="btn-debug" onclick="testPinVerification('${escapeHtml(identityType)}', '${escapeHtml(identityValue)}')">🔍 Test PIN</button>
                <button class="btn-debug" onclick="fetchHoldStatus('${escapeHtml(identityType)}', '${escapeHtml(identityValue)}')">📊 Hold Status</button>
            </div>
        `;
    }
    
    // Add debug output area
    body += `
        <div id="finalizeDebugOutput" style="display:none;margin-top:16px;padding:12px;background:#f8f6f0;border-radius:var(--radius-sm);border:1px solid var(--border);">
            <div style="font-weight:600;margin-bottom:8px;">🐛 Finalize Debug Output</div>
            <div id="finalizeDebugContent" style="font-size:11px;font-family:monospace;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow-y:auto;background:#fff;padding:8px;border-radius:4px;"></div>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="openAgentToolsModal()">← Back to Search</button>
            <button class="btn btn-primary" onclick="submitAgentFinalizeAggregated('${escapeHtml(identityType)}', '${escapeHtml(identityValue)}', ${totalAmount}, '${currency}')">✅ Process</button>
        </div>
    `;
    
    openModal('Confirm Deposit', body);
    addDebugLog(`🔓 Opened finalize form for ${identityValue}`);
}

async function submitAgentFinalizeAggregated(identityType, identityValue, totalAmount, currency) {
    const destinationAccountId = document.getElementById('agentDestSelect').value;
    const docVerified = document.getElementById('agentDocVerified').checked;
    const pin = document.getElementById('agentClaimPin').value.trim();
    const cashNowAmount = parseFloat(document.getElementById('cashNowAmount').value);
    
    // Show debug output
    const debugDiv = document.getElementById('finalizeDebugOutput');
    const debugContent = document.getElementById('finalizeDebugContent');
    if (debugDiv) {
        debugDiv.style.display = 'block';
        debugContent.textContent = '⏳ Processing...\n\n';
    }
    
    addDebugLog(`🚀 Submitting claim for ${identityValue} with PIN: ${pin ? '****' : '[EMPTY]'}`);
    addDebugLog(`📊 Cash amount: ${cashNowAmount}, Total: ${totalAmount}`);
    
    if (!docVerified) { 
        const msg = 'You must confirm you verified the client\'s physical document.';
        showMessage(msg, 'warning');
        addDebugLog('❌ ' + msg);
        if (debugContent) debugContent.textContent += '❌ ' + msg + '\n';
        return; 
    }
    if (!pin) { 
        const msg = 'Enter the client\'s claim PIN.';
        showMessage(msg, 'warning');
        addDebugLog('❌ ' + msg);
        if (debugContent) debugContent.textContent += '❌ ' + msg + '\n';
        return; 
    }
    if (isNaN(cashNowAmount) || cashNowAmount < 0 || cashNowAmount > totalAmount) {
        const msg = `Cash amount must be between 0 and ${totalAmount}.`;
        showMessage(msg, 'warning');
        addDebugLog('❌ ' + msg);
        if (debugContent) debugContent.textContent += '❌ ' + msg + '\n';
        return;
    }
    
    const payload = {
        identity_type: identityType,
        identity_value: identityValue,
        pin: pin,
        identity_document_verified: true,
        destination_account_id: parseInt(destinationAccountId, 10),
        cash_now_amount: cashNowAmount
    };
    
    addDebugLog(`📦 Finalize Payload:`, payload);
    if (debugContent) {
        debugContent.textContent += '📦 Payload:\n' + JSON.stringify(payload, null, 2) + '\n\n';
    }
    
    try {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/finalize_claim.php', payload);
        
        addDebugLog(`📦 Finalize Response:`, result);
        if (debugContent) {
            debugContent.textContent += '📦 Response:\n' + JSON.stringify(result, null, 2) + '\n\n';
        }
        
        if (!result.ok) { 
            const errorMsg = 'Failed: ' + result.error;
            showMessage(errorMsg, 'error');
            addDebugLog('❌ ' + errorMsg);
            if (debugContent) {
                debugContent.textContent += '❌ ERROR: ' + result.error + '\n';
                if (result.body) {
                    debugContent.textContent += '📦 Body: ' + JSON.stringify(result.body, null, 2) + '\n';
                }
            }
            return; 
        }
        
        const data = result.body.data || {};
        addDebugLog('✅ Success! Data:', data);
        
        // Log each hold result
        if (data.successful_deposits) {
            addDebugLog(`✅ ${data.successful_deposits.length} successful deposits`);
            data.successful_deposits.forEach((d, i) => {
                addDebugLog(`  ✅ Hold ${d.hold_id}: ${d.net_deposited} ${currency} (gross: ${d.gross_amount})`);
            });
        }
        if (data.failed_deposits && data.failed_deposits.length > 0) {
            addDebugLog(`❌ ${data.failed_deposits.length} failed deposits`);
            data.failed_deposits.forEach((d, i) => {
                addDebugLog(`  ❌ Hold ${d.hold_id}: ${d.error || 'Unknown error'}`);
            });
        }
        if (data.remainder_reswap) {
            addDebugLog(`🔄 Remainder re-swap: ${data.remainder_reswap.status}`, data.remainder_reswap);
        }
        
        if (debugContent) {
            debugContent.textContent += '✅ SUCCESS\n';
            debugContent.textContent += '📊 ' + data.successful_deposits?.length + ' holds completed\n';
            if (data.failed_deposits?.length > 0) {
                debugContent.textContent += '❌ ' + data.failed_deposits.length + ' holds failed\n';
                data.failed_deposits.forEach(d => {
                    debugContent.textContent += `  ❌ Hold ${d.hold_id}: ${d.error || 'Unknown'}\n`;
                });
            }
            debugContent.textContent += '\n🔄 Remainder: ' + (data.remainder_reswap?.status || 'none');
        }
        
        closeModal();
        
        const gaveCash = cashNowAmount > 0;
        const leftRemainder = cashNowAmount < totalAmount;
        let msg = gaveCash
            ? `Deposited and gave the client ${cashNowAmount} ${currency} in cash.`
            : `Deposited into your account — nothing given as cash yet.`;
        if (leftRemainder) {
            msg += ` The remaining ${(totalAmount - cashNowAmount).toFixed(2)} ${currency} was sent back to their identity — a new PIN was texted to them.`;
        }
        if (data.failed_deposits?.length > 0) {
            msg += ` ⚠️ ${data.failed_deposits.length} hold(s) failed. Check debug for details.`;
        }
        showMessage(msg, data.failed_deposits?.length > 0 ? 'warning' : 'success');
        
        agentSearchData = null;
        agentSearchResult = null;
        
    } catch (error) {
        const errorMsg = 'Exception: ' + error.message;
        showMessage(errorMsg, 'error');
        addDebugLog('❌ ' + errorMsg);
        console.error('[submitAgentFinalizeAggregated] Error:', error);
        if (debugContent) {
            debugContent.textContent += '❌ EXCEPTION: ' + error.message + '\n';
            debugContent.textContent += error.stack || '';
        }
    }
}

// ============================================================
// DEBUG HELPER FUNCTIONS
// ============================================================

async function testPinVerification(identityType, identityValue) {
    const pin = document.getElementById('agentClaimPin')?.value.trim() || '';
    const debugContent = document.getElementById('finalizeDebugContent');
    const debugDiv = document.getElementById('finalizeDebugOutput');
    
    if (debugDiv) debugDiv.style.display = 'block';
    if (debugContent) {
        debugContent.textContent = '🔍 Testing PIN verification...\n\n';
        debugContent.textContent += `Identity: ${identityType}=${identityValue}\n`;
        debugContent.textContent += `PIN: ${pin ? '****' : '[EMPTY]'}\n\n`;
    }
    
    addDebugLog(`🔍 Testing PIN for ${identityValue}`);
    
    try {
        // Call a debug endpoint to get hold details
        const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/debug_hold.php', {
            identity_type: identityType,
            identity_value: identityValue
        });
        
        if (debugContent) {
            debugContent.textContent += '📦 Hold Debug:\n' + JSON.stringify(result, null, 2) + '\n\n';
            
            if (result.body && result.body.data) {
                const holds = result.body.data.holds || [];
                holds.forEach(h => {
                    debugContent.textContent += `Hold ${h.hold_id}:\n`;
                    debugContent.textContent += `  Status: ${h.status}\n`;
                    debugContent.textContent += `  Amount: ${h.amount} ${h.currency}\n`;
                    debugContent.textContent += `  PIN Sent To: ${h.otp_pin_sent_to || 'N/A'}\n`;
                    debugContent.textContent += `  PIN Sent At: ${h.otp_pin_sent_at || 'N/A'}\n`;
                    debugContent.textContent += `  Hashed PIN: ${h.otp_pin_hash ? '***' : 'N/A'}\n`;
                    debugContent.textContent += `  Expires: ${h.hold_expires_at}\n\n`;
                });
            }
        }
        
        showMessage('Debug info displayed in debug panel', 'info');
        
    } catch (error) {
        addDebugLog('❌ Debug error: ' + error.message);
        if (debugContent) {
            debugContent.textContent += '❌ Error: ' + error.message + '\n';
        }
        showMessage('Debug error: ' + error.message, 'error');
    }
}

async function fetchHoldStatus(identityType, identityValue) {
    const debugContent = document.getElementById('finalizeDebugContent');
    const debugDiv = document.getElementById('finalizeDebugOutput');
    
    if (debugDiv) debugDiv.style.display = 'block';
    if (debugContent) {
        debugContent.textContent = '📊 Fetching hold status...\n\n';
    }
    
    addDebugLog(`📊 Fetching hold status for ${identityValue}`);
    
    try {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/debug_hold.php', {
            identity_type: identityType,
            identity_value: identityValue
        });
        
        if (debugContent) {
            debugContent.textContent += '📦 Hold Status:\n' + JSON.stringify(result, null, 2) + '\n';
        }
        
        showMessage('Hold status displayed in debug panel', 'info');
        
    } catch (error) {
        addDebugLog('❌ Status error: ' + error.message);
        if (debugContent) {
            debugContent.textContent += '❌ Error: ' + error.message + '\n';
        }
        showMessage('Status error: ' + error.message, 'error');
    }
}

// ============================================================
// Placeholder for IDENTITY_TYPE_LABELS
// ============================================================
const IDENTITY_TYPE_LABELS = { 
    national_id: 'National ID', 
    birth_certificate: 'Birth Certificate', 
    voter_id: 'Voter ID', 
    phone: 'Phone Number', 
    email: 'Email' 
};

// ============================================================
// Placeholder agent status
// ============================================================
let agentStatus = { is_agent: false, approved_destinations: [], all_destinations: [] };

// ============================================================
// INITIALIZATION
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Initialize the dashboard
    console.log('🏪 VouchMorph Dashboard loaded');
    console.log('🐛 Debug mode available - click "Debug: OFF" to enable');
    
    // Check for debug mode from URL
    if (window.location.search.includes('debug=1')) {
        toggleDebugMode();
    }
});

console.log('🐛 Debug functions available:');
console.log('  - toggleDebugMode() - Enable/disable debug logging');
console.log('  - clearDebugLog() - Clear the debug panel');
console.log('  - addDebugLog(message, data) - Add a log entry');
</script>
</body>
</html>
