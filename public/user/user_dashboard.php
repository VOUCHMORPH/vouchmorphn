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
.cta-row { display: flex; justify-content: center; margin-top: 24px; gap: 12px; }
.btn { padding: 14px 40px; border: none; border-radius: 999px; font-size: 14px; font-weight: 700; font-family: var(--font); cursor: pointer; }
.btn-primary { background: var(--gradient); color: #fff; }
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-secondary { background: #fff; color: var(--text); border: 1px solid var(--border); padding: 14px 32px; border-radius: 999px; font-weight: 600; cursor: pointer; }
.btn-danger-outline { background: transparent; color: var(--danger); border: 1px solid rgba(211,47,47,0.35); padding: 6px 14px; border-radius: 999px; font-size: 11px; cursor: pointer; font-weight: 600; }
.btn-sm { padding: 8px 18px !important; font-size: 12px; }
.quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: -4px 0 12px; }
.quick-link { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: var(--primary-dark); background: rgba(0,160,173,0.08); border: 1px solid rgba(0,160,173,0.2); padding: 5px 12px; border-radius: 999px; cursor: pointer; }
.quick-link.muted { color: var(--text-muted); background: rgba(0,0,0,0.04); border-color: var(--border); }
.quick-link.danger { color: var(--danger); background: rgba(211,47,47,0.06); border-color: rgba(211,47,47,0.2); }
.source-row { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 10px; background: #fff; }
.source-row-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.multi-total { text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 12px; }
.spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: spin 0.7s linear infinite; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(20,15,5,0.55); backdrop-filter: blur(6px); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal { background: #fff; border-radius: var(--radius); max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 24px; }
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
        <select class="country-selector" id="countrySelector" onchange="switchCountry(this.value)">
            <?php foreach ($availableCountries as $country): ?>
            <option value="<?php echo htmlspecialchars($country); ?>" <?php echo $country === $userCountry ? 'selected' : ''; ?>><?php echo htmlspecialchars($country); ?></option>
            <?php endforeach; ?>
        </select>
        <span class="quick-link" onclick="openSwapHistory()">📋 History</span>
        <span class="quick-link" id="claimsButton" onclick="openClaimsModal()" style="display:none;">💰 Claim Money <span id="claimsBadge" style="background:var(--danger);color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"></span></span>
        <span class="quick-link" id="agentToolsButton" onclick="openAgentToolsModal()" style="display:none;">🏪 Agent Tools</span>
        <span class="quick-link muted" onclick="openAgentModal()">🤝 Become an Agent</span>
        <span class="quick-link muted" onclick="openProfileModal()">👤 My Profile</span>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div id="mainMessage" class="message"></div>

<div class="card">
    <div class="swap-columns">
    <div class="section" id="fromSection">
        <div class="section-title"><span class="n">1</span> From</div>
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

document.addEventListener('DOMContentLoaded', function() {
    const fromSelect = document.getElementById('fromInstSelect');
    const toSelect = document.getElementById('toInstSelect');
    if (!fromSelect || !toSelect) { showMessage('Dashboard initialization error. Please refresh.', 'error'); return; }
    const instOptions = Object.keys(PARTICIPANTS);
    if (instOptions.length === 0) {
        showMessage('No institutions found for this country.', 'error');
        return;
    }
    fromSelect.innerHTML = '<option value="">Select institution</option>';
    toSelect.innerHTML = '<option value="">Select institution</option>';
    instOptions.forEach(code => {
        const name = PARTICIPANTS[code]?.name || code;
        fromSelect.insertAdjacentHTML('beforeend', `<option value="${code}">${name}</option>`);
        toSelect.insertAdjacentHTML('beforeend', `<option value="${code}">${name}</option>`);
    });
    updateCurrencyDisplay();
    document.getElementById('fromAmount').addEventListener('input', function() {
        state.fromAmount = parseFloat(this.value) || 0;
        refreshUI();
    });
    refreshUI();
    checkPendingClaims();
    loadAgentStatus();
});

function buildHeaders() {
    const headers = { 'Content-Type': 'application/json' };
    if (CONFIG.COUNTRY_CODE) headers['X-Country-Code'] = CONFIG.COUNTRY_CODE;
    if (CONFIG.API_KEY && !CONFIG.IS_TEST_MODE) headers['X-API-Key'] = CONFIG.API_KEY;
    return headers;
}
async function callApi(endpoint, payload) {
    let url = endpoint;
    if (CONFIG.IS_TEST_MODE) url += (url.includes('?') ? '&' : '?') + 'test_mode=1';
    let response, body;
    try {
        response = await fetch(url, { method: 'POST', headers: buildHeaders(), body: JSON.stringify(payload) });
    } catch (networkErr) {
        return { ok: false, error: 'Network error: could not reach ' + url + ' (' + networkErr.message + ')' };
    }
    try { body = await response.json(); } catch (parseErr) {
        return { ok: false, error: 'Server returned a non-JSON response (HTTP ' + response.status + ')' };
    }
    if (!response.ok || body.success === false) return { ok: false, error: body.error || ('HTTP ' + response.status), body };
    return { ok: true, body };
}

function selectFromInst(code) {
    state.fromInst = code || null; state.fromAsset = null; state.fromFields = {};
    const assetGroup = document.getElementById('fromAssetGroup');
    if (!code) { assetGroup.style.display = 'none'; document.getElementById('fromFields').innerHTML = ''; updateCurrencyDisplay(); refreshUI(); return; }
    const inst = PARTICIPANTS[code];
    if (!inst) { showMessage('Institution not found: ' + code, 'error'); return; }
    const sel = document.getElementById('fromAssetSelect');
    const assetTypes = inst.asset_types || [];
    sel.innerHTML = '<option value="">Select asset type</option>' + assetTypes.map(t => `<option value="${t}">${getAssetConfig(t)?.icon || '📦'} ${getAssetConfig(t)?.label || t}</option>`).join('');
    assetGroup.style.display = 'block';
    const currency = inst.limits?.currency || CONFIG.CURRENCY;
    document.getElementById('fromCurrencyLabel').textContent = currency;
    document.getElementById('fromLimitsHelp').textContent = inst.limits ? `Limits: ${inst.limits.min_amount} – ${inst.limits.max_amount} ${inst.limits.currency}` : '';
    if (assetTypes.length === 1) { sel.value = assetTypes[0]; selectFromAsset(assetTypes[0]); } else { document.getElementById('fromFields').innerHTML = ''; }
    updateCurrencyDisplay(); refreshUI();
}
function selectFromAsset(type) {
    state.fromAsset = type || null; state.fromFields = {};
    const box = document.getElementById('fromFields');
    if (!type) { box.style.display = 'none'; box.innerHTML = ''; refreshUI(); return; }
    box.style.display = 'block';
    renderDynamicFields('fromFields', type, 'fromField_', updateFromField, true);
    refreshUI();
}
function updateFromField(name, value) { state.fromFields[name] = value; refreshUI(); }
function assetHasAmountField(assetType) { return (getAssetConfig(assetType)?.fields || []).some(f => f.name === 'amount'); }
function renderDynamicFields(containerId, assetType, prefix, onChange, includePin) {
    const container = document.getElementById(containerId);
    const fields = (getAssetConfig(assetType)?.fields || []).filter(f => includePin || f.vault_field !== 'pin').filter(f => f.name !== 'amount');
    if (!fields || fields.length === 0) { container.innerHTML = ''; return; }
    container.innerHTML = fields.map(f => {
        const attrs = [];
        if (f.pattern) attrs.push(`pattern="${f.pattern}"`);
        if (f.min_length) attrs.push(`minlength="${f.min_length}"`);
        if (f.max_length) attrs.push(`maxlength="${f.max_length}"`);
        if (f.required) attrs.push('required');
        if (f.type === 'select') {
            return `<div class="field-group"><label>${f.label} ${f.required ? '*' : ''}</label><select id="${prefix}${f.name}" onchange="window['${onChange.name}']('${f.name}', this.value)"><option value="">${f.placeholder || 'Select'}</option>${(f.options || []).map(o => `<option value="${o}">${o}</option>`).join('')}</select></div>`;
        }
        return `<div class="field-group"><label>${f.label} ${f.required ? '*' : ''}</label><input type="${f.type}" id="${prefix}${f.name}" placeholder="${f.placeholder || ''}" ${attrs.join(' ')} oninput="window['${onChange.name}']('${f.name}', this.value)"></div>`;
    }).join('');
}
function fieldsValidForAsset(assetType, values, includePin) {
    const fields = (getAssetConfig(assetType)?.fields || []).filter(f => includePin || f.vault_field !== 'pin').filter(f => f.name !== 'amount');
    return fields.every(f => {
        const val = values[f.name];
        if (!f.required) return true;
        if (!val || String(val).trim().length === 0) return false;
        if (val && f.pattern && !new RegExp(f.pattern).test(val)) return false;
        return true;
    });
}
function extractPinFromFields(assetType, values) {
    const pinField = (getAssetConfig(assetType)?.fields || []).find(f => f.vault_field === 'pin');
    return pinField ? (values[pinField.name] || '') : '';
}
function amountWithinLimits(instCode, amount) {
    const limits = PARTICIPANTS[instCode]?.limits;
    if (!limits) return true;
    return amount >= limits.min_amount && amount <= limits.max_amount;
}
function selectToInst(code) {
    state.toInst = code || null; state.toAsset = null; state.toFields = {};
    if (state.swapType === 'DEPOSIT' || state.swapType === 'MULTI_SOURCE') {
        const sel = document.getElementById('toAssetSelect');
        const group = document.getElementById('toAssetSection');
        if (!code) { group.style.display = 'none'; document.getElementById('toFields').style.display = 'none'; refreshUI(); return; }
        const inst = PARTICIPANTS[code];
        if (!inst) { showMessage('Institution not found: ' + code, 'error'); return; }
        const assetTypes = inst.asset_types || [];
        sel.innerHTML = '<option value="">Select asset type</option>' + assetTypes.map(t => `<option value="${t}">${getAssetConfig(t)?.icon || '📦'} ${getAssetConfig(t)?.label || t}</option>`).join('');
        group.style.display = 'block';
        if (assetTypes.length === 1) { sel.value = assetTypes[0]; selectToAsset(assetTypes[0]); } else { document.getElementById('toFields').style.display = 'none'; }
        updateCurrencyDisplay();
    }
    refreshUI();
}
function selectToAsset(type) {
    state.toAsset = type || null; state.toFields = {};
    const box = document.getElementById('toFields');
    if (!type) { box.style.display = 'none'; box.innerHTML = ''; refreshUI(); return; }
    box.style.display = 'block';
    renderDynamicFields('toFields', type, 'toField_', updateToField, false);
    refreshUI();
}
function updateToField(name, value) { state.toFields[name] = value; refreshUI(); }
function setDeliveryMethod(method) { state.deliveryMethod = method; refreshUI(); }
function setSwapType(type) {
    state.swapType = type;
    const isIdentity = type === 'IDENTITY', isMulti = type === 'MULTI_SOURCE', isDeposit = type === 'DEPOSIT', isCashout = type === 'CASHOUT';
    document.getElementById('identityFields').style.display = isIdentity ? 'block' : 'none';
    document.getElementById('multiSourceFields').style.display = isMulti ? 'block' : 'none';
    document.getElementById('cashoutFields').style.display = isCashout ? 'block' : 'none';
    document.getElementById('toInstSection').style.display = (isDeposit || isCashout || isMulti) ? 'block' : 'none';
    document.getElementById('toAssetSection').style.display = (isDeposit || isMulti) && state.toAsset ? 'block' : 'none';
    document.getElementById('toFields').style.display = (isDeposit || isMulti) && state.toAsset ? 'block' : 'none';
    document.getElementById('fromSection').style.display = isMulti ? 'none' : 'block';
    document.querySelector('.swap-divider').style.display = isMulti ? 'none' : 'flex';
    if (isIdentity) updateIdentityHelp();
    updateCurrencyDisplay();
    if (isMulti && state.multiSources.length === 0) { addMultiSourceRow(); addMultiSourceRow(); }
    refreshUI();
}
function updateIdentityHelp() {
    const type = document.getElementById('identityType').value;
    const hint = document.getElementById('identityHint');
    if (!hint) return;
    const documentTypes = ['national_id', 'birth_certificate', 'voter_id'];
    if (documentTypes.includes(type)) {
        hint.textContent = `This is a physical document that can be verified by an agent in person. The recipient will also receive a notification and can claim via dashboard within 24 hours.`;
    } else {
        hint.textContent = `The recipient will be notified via ${type === 'phone' ? 'SMS' : 'email'} and can claim the funds within 24 hours.`;
    }
}
function quickSetSwapType(type) {
    document.getElementById('swapTypeSelect').value = type;
    setSwapType(type);
    const target = type === 'IDENTITY' ? document.getElementById('identityFields') : document.getElementById('multiSourceFields');
    target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
let multiSourceSeq = 0;
function addMultiSourceRow() { state.multiSources.push({ id: ++multiSourceSeq, institution: null, assetType: null, fields: {}, amount: 0 }); renderMultiSourceRows(); }
function removeMultiSourceRow(id) { state.multiSources = state.multiSources.filter(s => s.id !== id); renderMultiSourceRows(); }
function renderMultiSourceRows() {
    const container = document.getElementById('multiSourceList');
    container.innerHTML = state.multiSources.map((src, idx) => `
        <div class="source-row">
            <div class="source-row-head"><span>Source ${idx + 1}</span>${state.multiSources.length > 2 ? `<button class="btn-danger-outline" onclick="removeMultiSourceRow(${src.id})">Remove</button>` : ''}</div>
            <div class="field-group"><label>Institution</label><select onchange="setMultiSourceInst(${src.id}, this.value)"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}" ${src.institution === code ? 'selected' : ''}>${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div>
            ${src.institution ? `<div class="field-group"><label>Asset Type</label><select onchange="setMultiSourceAsset(${src.id}, this.value)"><option value="">Select asset type</option>${(PARTICIPANTS[src.institution]?.asset_types || []).map(t => `<option value="${t}" ${src.assetType === t ? 'selected' : ''}>${getAssetConfig(t)?.label || t}</option>`).join('')}</select></div>` : ''}
            ${src.assetType ? (getAssetConfig(src.assetType)?.fields || []).filter(f => f.name !== 'amount').map(f => `<div class="field-group"><label>${f.label} ${f.required ? '*' : ''}</label><input type="${f.type === 'select' ? 'text' : f.type}" value="${src.fields[f.name] || ''}" placeholder="${f.placeholder || ''}" oninput="setMultiSourceField(${src.id}, '${f.name}', this.value)"></div>`).join('') : ''}
            <div class="field-group"><label>Amount to pull from this source</label><input type="number" min="0.01" step="0.01" value="${src.amount || ''}" placeholder="0.00" oninput="setMultiSourceAmount(${src.id}, this.value)"></div>
        </div>
    `).join('');
    updateMultiTotal(); refreshUI();
}
function setMultiSourceInst(id, code) { const src = state.multiSources.find(s => s.id === id); src.institution = code || null; src.assetType = null; src.fields = {}; renderMultiSourceRows(); }
function setMultiSourceAsset(id, type) { const src = state.multiSources.find(s => s.id === id); src.assetType = type || null; src.fields = {}; renderMultiSourceRows(); }
function setMultiSourceField(id, name, value) { state.multiSources.find(s => s.id === id).fields[name] = value; refreshUI(); }
function setMultiSourceAmount(id, value) { state.multiSources.find(s => s.id === id).amount = parseFloat(value) || 0; updateMultiTotal(); refreshUI(); }
function updateMultiTotal() { const total = state.multiSources.reduce((sum, s) => sum + (s.amount || 0), 0); document.getElementById('multiTotal').textContent = `${CONFIG.CURRENCY} ${total.toFixed(2)}`; }
function multiSourcesValid() {
    if (state.multiSources.length < 2) return false;
    return state.multiSources.every(s => {
        if (!s.institution || !s.assetType || !(s.amount > 0)) return false;
        const pin = extractPinFromFields(s.assetType, s.fields);
        const needsPin = getAssetConfig(s.assetType)?.fields?.some(f => f.vault_field === 'pin');
        if (needsPin && pin.length < 4) return false;
        return fieldsValidForAsset(s.assetType, s.fields, true);
    });
}
function refreshUI() { document.getElementById('reviewBtn').disabled = !isSwapReady(); }
function isSwapReady() {
    if (state.swapType === 'MULTI_SOURCE') return multiSourcesValid() && state.toInst && state.toAsset && fieldsValidForAsset(state.toAsset, state.toFields, false);
    const hasSource = state.fromInst && state.fromAsset;
    if (!hasSource) return false;
    if (!(state.fromAmount > 0) || !amountWithinLimits(state.fromInst, state.fromAmount)) return false;
    if (!fieldsValidForAsset(state.fromAsset, state.fromFields, true)) return false;
    if (state.swapType === 'IDENTITY') return !!state.toIdentityValue;
    if (state.swapType === 'CASHOUT') return !!state.toInst;
    return !!(state.toInst && state.toAsset && fieldsValidForAsset(state.toAsset, state.toFields, false));
}
function buildPayload() {
    const reference = 'SWAP_' + Date.now();
    const idempotencyKey = 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
    if (state.swapType === 'MULTI_SOURCE') {
        const sources = state.multiSources.map(s => {
            const pin = extractPinFromFields(s.assetType, s.fields);
            const identifierField = (ASSETS[s.assetType]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
            const assetFields = { ...s.fields };
            if (assetHasAmountField(s.assetType)) assetFields.amount = s.amount;
            return { institution: s.institution, asset_type: s.assetType, identifier: identifierField ? s.fields[identifierField.name] : null, amount: s.amount, wallet_pin: pin || undefined, pin: pin || undefined, asset_fields: assetFields };
        });
        const totalAmount = sources.reduce((sum, s) => sum + s.amount, 0);
        const destFields = { ...state.toFields };
        if (assetHasAmountField(state.toAsset)) destFields.amount = totalAmount;
        const destIdField = (ASSETS[state.toAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        const destCurrency = PARTICIPANTS[state.toInst]?.limits?.currency || CONFIG.CURRENCY;
        const payload = { swap_type: 'MULTI_SOURCE', reference, idempotency_key: idempotencyKey, user_id: CONFIG.USER_ID, amount: totalAmount, currency: CONFIG.CURRENCY, destination_currency: destCurrency, contribution_strategy: 'USER_SPECIFIED', sources, to_institution: state.toInst, destination_institution: state.toInst, destination_asset_type: state.toAsset, asset_type: state.toAsset, destination_asset_fields: destFields };
        for (const [key, value] of Object.entries(destFields)) payload[`destination_${key}`] = value;
        if (destIdField) payload.destination_identifier = state.toFields[destIdField.name];
        return payload;
    }
    const pin = extractPinFromFields(state.fromAsset, state.fromFields);
    const sourceAssetFields = { ...state.fromFields };
    if (assetHasAmountField(state.fromAsset)) sourceAssetFields.amount = state.fromAmount;
    const sourceCurrency = PARTICIPANTS[state.fromInst]?.limits?.currency || CONFIG.CURRENCY;
    const payload = { swap_type: state.swapType, reference, idempotency_key: idempotencyKey, user_id: CONFIG.USER_ID, from_institution: state.fromInst, source_institution: state.fromInst, asset_type: state.fromAsset, amount: state.fromAmount, currency: sourceCurrency, wallet_pin: pin || undefined, pin: pin || undefined, asset_fields: sourceAssetFields, ...sourceAssetFields };
    payload.amount = state.fromAmount;
    const idField = (ASSETS[state.fromAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    if (idField) payload.source_identifier = state.fromFields[idField.name];
    if (state.swapType === 'IDENTITY') {
        payload.identity_type = state.toIdentityType;
        payload.identity_value = state.toIdentityValue;
        if (state.toIdentitySms) payload.notification_phone = state.toIdentitySms;
        payload.destination_currency = sourceCurrency;
    } else if (state.swapType === 'CASHOUT') {
        payload.to_institution = state.toInst;
        payload.destination_institution = state.toInst;
        payload.delivery_method = state.deliveryMethod;
        if (state.beneficiaryPhone) { payload.beneficiary_phone = state.beneficiaryPhone; payload.client_phone = state.beneficiaryPhone; }
        payload.destination_currency = PARTICIPANTS[state.toInst]?.limits?.currency || CONFIG.CURRENCY;
    } else {
        payload.to_institution = state.toInst;
        payload.destination_institution = state.toInst;
        payload.destination_asset_type = state.toAsset;
        payload.destination_currency = PARTICIPANTS[state.toInst]?.limits?.currency || sourceCurrency;
        const destFields = { ...state.toFields };
        if (assetHasAmountField(state.toAsset)) destFields.amount = state.fromAmount;
        payload.destination_asset_fields = destFields;
        for (const [key, value] of Object.entries(destFields)) payload[`destination_${key}`] = value;
        payload.amount = state.fromAmount;
        const destIdField = (ASSETS[state.toAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        if (destIdField) payload.destination_identifier = state.toFields[destIdField.name];
    }
    return payload;
}
async function previewSwap() {
    if (!isSwapReady()) { showMessage('Please fill in all required fields.', 'warning'); return; }
    const payload = buildPayload();
    state.swapPayload = payload;
    const btn = document.getElementById('reviewBtn');
    const original = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>Calculating…';
    const result = await callApi(CONFIG.PREVIEW_ENDPOINT, payload);
    btn.disabled = false; btn.innerHTML = original; refreshUI();
    if (!result.ok) { showMessage('Preview failed: ' + result.error, 'error'); return; }
    showPreviewModal(result.body);
}
function showPreviewModal(previewData) {
    const data = previewData.preview || {};
    const swapType = state.swapPayload.swap_type;
    const bodyHtml = `
        <div class="preview-box">
            <div class="preview-row"><span>Swap Type</span><span class="value">${swapType}</span></div>
            <div class="preview-row"><span>Source</span><span class="value">${data.source_institution || '—'}</span></div>
            <div class="preview-row"><span>Destination</span><span class="value">${data.destination_institution || '—'}</span></div>
            <div class="preview-row"><span>Amount Requested</span><span class="value">${data.amount_requested} ${data.source_currency}</span></div>
            <div class="preview-row" style="border-top:2px solid var(--border);padding-top:8px;"><span style="font-weight:700;">Total Fee</span><span class="value" style="color:var(--danger);">${data.total_fee} ${data.source_currency}</span></div>
            <div class="preview-row" style="border-top:2px solid var(--primary);padding-top:8px;"><span style="font-weight:700;font-size:16px;">Net Amount</span><span class="value highlight">${data.net_amount_destination_currency || data.net_amount} ${data.destination_currency || data.source_currency}</span></div>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="closeModal()" style="flex:1;">Cancel</button>
            <button class="btn btn-primary" onclick="confirmSwap()" style="flex:1;">✅ Confirm & Execute</button>
        </div>`;
    state.lastPreview = previewData;
    openModal('Swap Preview', bodyHtml);
}
async function confirmSwap() {
    closeModal();
    const payload = state.swapPayload;
    if (!payload) { showMessage('No swap payload to execute', 'error'); return; }
    const btn = document.getElementById('reviewBtn');
    const original = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>Executing…';
    const result = await callApi(CONFIG.EXECUTE_ENDPOINT, payload);
    btn.disabled = false; btn.innerHTML = original; refreshUI();
    if (!result.ok) { showMessage('Swap failed: ' + result.error, 'error'); return; }
    showResultModal(result.body);
}
function showResultModal(response) {
    const data = response.data || {};
    const swapType = state.swapPayload.swap_type;
    const reference = response.swap_reference || data.reference || '—';
    let inner = '';
    if (swapType === 'CASHOUT') {
        inner = `<div class="icon">💰</div><div style="font-size:18px;font-weight:700;">Cashout Code Generated</div><div style="color:var(--text-muted);">Reference: ${escapeHtml(reference)}</div>${data.atm_code || data.voucher_number ? `<div class="atm-code"><div class="code">${escapeHtml(data.atm_code || data.voucher_number || '')}</div></div>` : ''}<div style="margin-top:12px;"><div style="font-size:24px;font-weight:700;">${data.amount ?? state.swapPayload.amount} ${state.swapPayload.currency || CONFIG.CURRENCY}</div></div>`;
    } else if (swapType === 'IDENTITY') {
        inner = `<div class="icon">🔑</div><div style="font-size:18px;font-weight:700;">Identity Swap Initiated</div><div style="color:var(--text-muted);">Reference: ${escapeHtml(reference)}</div><div style="margin:12px 0;"><strong>${escapeHtml(data.identity_type || state.swapPayload.identity_type)}: ${escapeHtml(data.identity_value || state.swapPayload.identity_value)}</strong></div><div style="font-size:24px;font-weight:700;">${data.amount ?? state.swapPayload.amount} ${data.currency || CONFIG.CURRENCY}</div>`;
    } else {
        inner = `<div class="icon">✅</div><div style="font-size:18px;font-weight:700;">Swap Completed</div><div style="color:var(--text-muted);">Reference: ${escapeHtml(reference)}</div><div style="font-size:24px;font-weight:700;margin-top:12px;">${data.amount ?? state.swapPayload.amount} ${CONFIG.CURRENCY}</div>`;
    }
    openModal('Swap Result', `<div class="result-box">${inner}<div class="cta-row"><button class="btn btn-primary" onclick="closeModal(); location.reload();">Done</button></div></div>`);
}

const IDENTITY_TYPE_LABELS = { national_id: 'National ID', birth_certificate: 'Birth Certificate', voter_id: 'Voter ID', phone: 'Phone Number', email: 'Email' };
function openProfileModal() { openModal('My Profile', renderProfileModal()); }
function renderProfileModal() {
    const rows = savedIdentities.length ? savedIdentities.map((id, i) => `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);">
            <div><div style="font-size:11px;color:var(--text-muted);">${escapeHtml(IDENTITY_TYPE_LABELS[id.type] || id.type)}</div><div style="font-size:14px;font-weight:600;">${escapeHtml(id.value)}</div></div>
            <div class="quick-actions" style="margin:0;"><span class="quick-link" onclick="useSavedIdentity(${i})">✓ Use</span><span class="quick-link danger" onclick="removeSavedIdentity(${i})">✕ Remove</span></div>
        </div>`).join('') : `<div style="font-size:12px;color:var(--text-dim);">No saved identities yet.</div>`;
    return `
        <div style="margin-bottom:12px;">${rows}</div>
        <div class="field-group"><label>Identity Type</label><select id="newIdentityType"><option value="national_id">National ID</option><option value="birth_certificate">Birth Certificate</option><option value="voter_id">Voter ID</option><option value="phone">Phone Number</option><option value="email">Email</option></select></div>
        <div class="field-group"><label>Identity Value</label><input id="newIdentityValue" placeholder="Enter the identity value"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="addSavedIdentity()">+ Add Identity</button></div>
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
            <div class="field-label" style="margin-bottom:8px;">🔒 Transaction PIN</div>
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Required to claim money sent to your verified identity — whether you finalize it yourself here, or relay it to an agent in person. Never share it over SMS.</div>
            <div class="field-group"><label>New PIN (4-6 digits)</label><input type="password" id="newPin" inputmode="numeric" maxlength="6" placeholder="••••"></div>
            <div class="field-group"><label>Confirm PIN</label><input type="password" id="confirmPin" inputmode="numeric" maxlength="6" placeholder="••••"></div>
            <div class="cta-row"><button class="btn btn-primary" onclick="setTransactionPin()">Set PIN</button></div>
        </div>`;
}
function addSavedIdentity() {
    const type = document.getElementById('newIdentityType').value;
    const value = document.getElementById('newIdentityValue').value.trim();
    if (!value) { showMessage('Enter an identity value first', 'error'); return; }
    savedIdentities.push({ type, value });
    document.getElementById('modalBody').innerHTML = renderProfileModal();
}
function removeSavedIdentity(idx) { savedIdentities.splice(idx, 1); document.getElementById('modalBody').innerHTML = renderProfileModal(); }
function useSavedIdentity(idx) {
    const id = savedIdentities[idx];
    if (!id) return;
    closeModal(); quickSetSwapType('IDENTITY');
    state.toIdentityType = id.type; state.toIdentityValue = id.value;
    document.getElementById('identityType').value = id.type;
    document.getElementById('identityValue').value = id.value;
    updateIdentityHelp(); refreshUI();
    showMessage(`Using saved ${IDENTITY_TYPE_LABELS[id.type] || id.type}: ${id.value}`, 'success');
}
async function setTransactionPin() {
    const pin = document.getElementById('newPin').value.trim();
    const confirmPin = document.getElementById('confirmPin').value.trim();
    if (!/^\d{4,6}$/.test(pin)) { showMessage('PIN must be 4-6 digits.', 'warning'); return; }
    if (pin !== confirmPin) { showMessage('PIN and confirmation do not match.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/set_pin.php', { pin, confirm_pin: confirmPin });
    if (!result.ok) { showMessage('Could not set PIN: ' + result.error, 'error'); return; }
    showMessage('Transaction PIN set. Keep it private.', 'success');
    closeModal();
}

let pendingClaims = [];
async function checkPendingClaims() {
    if (!CONFIG.USER_ID) return;
    try {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/pending_claims.php', {});
        if (!result.ok) return;
        pendingClaims = result.body.data || [];
        const btn = document.getElementById('claimsButton');
        const badge = document.getElementById('claimsBadge');
        if (pendingClaims.length > 0) { btn.style.display = 'inline-flex'; badge.textContent = pendingClaims.length; } else { btn.style.display = 'none'; }
    } catch (e) { console.error('[claims] Failed to check pending claims', e); }
}
function openClaimsModal() {
    if (pendingClaims.length === 0) { openModal('Claim Money', '<div style="font-size:12px;color:var(--text-dim);">No money currently waiting for your verified identities.</div>'); return; }
    const rows = pendingClaims.map((c, i) => `
        <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:10px;background:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                <div><div style="font-weight:700;font-size:16px;color:var(--primary-dark);">${escapeHtml(c.amount)} ${escapeHtml(c.currency)}</div><div style="font-size:12px;color:var(--text-muted);">From ${escapeHtml(c.source_institution || 'Unknown')}</div><div style="font-size:11px;color:var(--text-dim);">Expires ${c.hold_expires_at ? new Date(c.hold_expires_at).toLocaleString() : 'soon'}</div></div>
                <button class="btn btn-primary btn-sm" onclick="openClaimForm(${i})">Claim</button>
            </div>
        </div>`).join('');
    openModal('💰 Claim Money', `<div>${rows}</div>`);
}
function openClaimForm(idx) {
    const claim = pendingClaims[idx];
    if (!claim) return;
    const pinHint = claim.claim_type === 'otp_pin' ? 'Use the one-time PIN sent by SMS when this money was sent.' : 'Use your VouchMorph transaction PIN.';
    const body = `
        <div style="background:rgba(0,160,173,0.06);border-radius:var(--radius-sm);padding:14px;margin-bottom:14px;">
            <div style="font-size:20px;font-weight:700;color:var(--primary-dark);">${escapeHtml(claim.amount)} ${escapeHtml(claim.currency)}</div>
            <div style="font-size:12px;color:var(--text-muted);">From ${escapeHtml(claim.source_institution || 'Unknown')}</div>
        </div>
        <div class="field-group"><label>Claim PIN</label><input type="password" id="claimPin" inputmode="numeric" maxlength="6" placeholder="••••"><div class="help">${pinHint}</div></div>
        <div class="field-group"><label>Receive as</label><select id="claimDestType" onchange="toggleClaimDestFields(this.value)"><option value="CASHOUT">Cashout (ATM / Agent code)</option><option value="DEPOSIT">Deposit to an account/wallet</option></select></div>
        <div id="claimDepositFields" style="display:none;">
            <div class="field-group"><label>Destination Institution</label><select id="claimDestInst"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div>
            <div class="field-group"><label>Account / Wallet Number</label><input id="claimDestIdentifier" placeholder="Account number or phone"></div>
        </div>
        <div class="cta-row"><button class="btn btn-secondary" onclick="openClaimsModal()">← Back</button><button class="btn btn-primary" onclick="submitClaim('${claim.swap_reference}')">✅ Claim Funds</button></div>`;
    openModal('Claim Money', body);
}
function toggleClaimDestFields(type) { document.getElementById('claimDepositFields').style.display = type === 'DEPOSIT' ? 'block' : 'none'; }
async function submitClaim(swapReference) {
    const pin = document.getElementById('claimPin').value.trim();
    const destType = document.getElementById('claimDestType').value;
    if (!pin) { showMessage('Enter your claim PIN.', 'warning'); return; }
    const payload = { swap_reference: swapReference, pin, destination_type: destType };
    if (destType === 'DEPOSIT') {
        payload.destination_institution = document.getElementById('claimDestInst').value;
        payload.destination_identifier = document.getElementById('claimDestIdentifier').value.trim();
        if (!payload.destination_institution || !payload.destination_identifier) { showMessage('Select a destination institution and enter an account/wallet number.', 'warning'); return; }
    }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/claim_identity.php', payload);
    if (!result.ok) { showMessage('Claim failed: ' + result.error, 'error'); return; }
    closeModal(); showMessage('Funds claimed successfully!', 'success'); checkPendingClaims();
}

let agentStatus = { is_agent: false, approved_destinations: [], all_destinations: [] };
async function loadAgentStatus() {
    if (!CONFIG.USER_ID) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) return;
    agentStatus = result.body.data;
    document.getElementById('agentToolsButton').style.display = agentStatus.is_agent ? 'inline-flex' : 'none';
    document.getElementById('agentBadge').style.display = agentStatus.is_agent ? 'inline-block' : 'none';
}
async function openAgentModal() {
    openModal('Agent Account', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading...</div>');
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="color:var(--danger);">Failed to load agent status: ${escapeHtml(result.error)}</div>`; return; }
    agentStatus = result.body.data;
    document.getElementById('agentToolsButton').style.display = agentStatus.is_agent ? 'inline-flex' : 'none';
    document.getElementById('agentBadge').style.display = agentStatus.is_agent ? 'inline-block' : 'none';
    document.getElementById('modalBody').innerHTML = renderAgentModal();
}
function renderAgentModal() {
    const statusRows = agentStatus.all_destinations.length ? agentStatus.all_destinations.map(d => {
        const badge = d.status === 'active' ? '<span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;">Active</span>'
            : d.status === 'pending_confirmation' ? '<span style="background:#fef3c7;color:#8a5a0b;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;">⏳ Pending Approval</span>'
            : '<span style="background:#fbeceb;color:var(--danger);padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;">Rejected</span>';
        return `<div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px;margin-bottom:8px;background:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                <div><div style="font-weight:600;">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)}</div><div style="font-size:12px;color:var(--text-muted);">${escapeHtml(d.identifier)} · ${escapeHtml(d.account_type || d.asset_type)}</div></div>${badge}
            </div>${d.status === 'rejected' && d.rejection_reason ? `<div style="font-size:12px;color:var(--danger);margin-top:6px;">Reason: ${escapeHtml(d.rejection_reason)}</div>` : ''}
        </div>`;
    }).join('') : '<div style="font-size:12px;color:var(--text-dim);">You have no agent destination accounts registered yet.</div>';
    return `
        <div style="margin-bottom:16px;"><div class="field-label" style="margin-bottom:8px;">Your Agent Accounts</div>${statusRows}</div>
        <div style="border-top:1px solid var(--border);padding-top:16px;">
            <div class="field-label" style="margin-bottom:8px;">Register a New Agent Destination</div>
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Register a business/agent account you hold at a participating institution. Only business or agent-designated accounts are eligible. Approval required before activation.</div>
            <div class="field-group"><label>Institution</label><select id="agentInst"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div>
            <div class="field-group"><label>Account / Wallet Number</label><input id="agentIdentifier" placeholder="Your business account number"></div>
            <div class="field-group"><label>Account Name (optional)</label><input id="agentAccountName" placeholder="e.g. Thabo's General Store"></div>
            <div class="cta-row"><button class="btn btn-primary" onclick="submitAgentDestination()">Register & Verify</button></div>
        </div>`;
}
async function submitAgentDestination() {
    const institution = document.getElementById('agentInst').value;
    const identifier = document.getElementById('agentIdentifier').value.trim();
    const accountName = document.getElementById('agentAccountName').value.trim();
    if (!institution || !identifier) { showMessage('Select an institution and enter your account number.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/propose_destination.php', { institution, identifier, account_name: accountName || undefined });
    if (!result.ok) { showMessage('Could not register: ' + result.error, 'error'); return; }
    showMessage(result.body.data.message || 'Registered - awaiting approval.', 'success');
    openAgentModal();
}

let agentSearchResult = null;
function openAgentToolsModal() { openModal('🏪 Agent Tools', renderAgentToolsSearch()); }
function renderAgentToolsSearch() {
    return `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Search for a client's pending identity payment. You'll need to physically verify their document and have them tell you their claim PIN before you can finalize.</div>
        <div class="field-group"><label>Document Type</label><select id="agentSearchType"><option value="national_id">National ID</option><option value="birth_certificate">Birth Certificate</option><option value="voter_id">Voter ID</option></select></div>
        <div class="field-group"><label>Document Number</label><input id="agentSearchValue" placeholder="Enter the client's ID number"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="searchAgentClaim()">🔍 Search</button></div>
        <div id="agentSearchResults" style="margin-top:16px;"></div>`;
}
async function searchAgentClaim() {
    const identityType = document.getElementById('agentSearchType').value;
    const identityValue = document.getElementById('agentSearchValue').value.trim();
    const resultsBox = document.getElementById('agentSearchResults');
    if (!identityValue) { showMessage('Enter the document number to search.', 'warning'); return; }
    resultsBox.innerHTML = '<div style="text-align:center;padding:16px;"><div class="spinner"></div> Searching...</div>';
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/search_claim.php', { identity_type: identityType, identity_value: identityValue });
    if (!result.ok) { resultsBox.innerHTML = `<div style="color:var(--danger);">${escapeHtml(result.error)}</div>`; return; }
    const matches = result.body.data || [];
    if (matches.length === 0) { resultsBox.innerHTML = '<div style="font-size:12px;color:var(--text-dim);">No pending payment found for this identity.</div>'; return; }
    resultsBox.innerHTML = matches.map((m) => `
        <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:10px;background:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                <div><div style="font-weight:700;font-size:18px;color:var(--primary-dark);">${escapeHtml(m.amount)} ${escapeHtml(m.currency)}</div><div style="font-size:12px;color:var(--text-muted);">From ${escapeHtml(m.source_institution || 'Unknown')}</div><div style="font-size:11px;color:var(--text-dim);">Expires ${m.hold_expires_at ? new Date(m.hold_expires_at).toLocaleString() : 'soon'}</div></div>
                <button class="btn btn-primary btn-sm" onclick='openAgentFinalizeForm(${JSON.stringify(m).replace(/'/g, "&apos;")})'>Deposit to My Account</button>
            </div>
        </div>`).join('');
}
function openAgentFinalizeForm(claim) {
    agentSearchResult = claim;
    if (!agentStatus.approved_destinations || agentStatus.approved_destinations.length === 0) {
        openModal('Agent Tools', '<div style="color:var(--danger);">You have no approved agent destination account. Register one first.</div>');
        return;
    }
    const destOptions = agentStatus.approved_destinations.map(d => `<option value="${d.id}">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)} - ${escapeHtml(d.identifier)}</option>`).join('');
    const searchTypeLabel = IDENTITY_TYPE_LABELS[document.getElementById('agentSearchType')?.value] || 'document';
    const body = `
        <div style="background:rgba(0,160,173,0.06);border-radius:var(--radius-sm);padding:14px;margin-bottom:14px;">
            <div style="font-size:12px;color:var(--text-muted);">Amount to deposit</div>
            <div style="font-size:24px;font-weight:700;color:var(--primary-dark);">${escapeHtml(claim.amount)} ${escapeHtml(claim.currency)}</div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">This is the client's full payment. It cannot be split — deposit the whole amount now, then give the client their cash or goods for what they need today.</div>
        </div>
        <div class="field-group"><label>Deposit into</label><select id="agentDestSelect">${destOptions}</select></div>
        <div class="field-group"><label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:400;"><input type="checkbox" id="agentDocVerified"> I have physically verified the client's ${searchTypeLabel}</label></div>
        <div class="field-group"><label>Client's Claim PIN</label><input type="password" id="agentClaimPin" inputmode="numeric" maxlength="6" placeholder="Ask the client for their PIN"><div class="help">The client must tell you this themselves — never accept a claim without it.</div></div>
        <div class="cta-row"><button class="btn btn-secondary" onclick="openAgentToolsModal()">← Back to Search</button><button class="btn btn-primary" onclick="submitAgentFinalize()">✅ Deposit Now</button></div>`;
    openModal('Confirm Deposit', body);
}
async function submitAgentFinalize() {
    const destinationAccountId = document.getElementById('agentDestSelect').value;
    const docVerified = document.getElementById('agentDocVerified').checked;
    const pin = document.getElementById('agentClaimPin').value.trim();
    if (!docVerified) { showMessage('You must confirm you verified the client\'s physical document.', 'warning'); return; }
    if (!pin) { showMessage('Enter the client\'s claim PIN.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/finalize_claim.php', { swap_reference: agentSearchResult.swap_reference, pin, identity_document_verified: true, destination_account_id: parseInt(destinationAccountId, 10) });
    if (!result.ok) { showMessage('Deposit failed: ' + result.error, 'error'); return; }
    closeModal();
    showMessage(`Deposited ${agentSearchResult.amount} ${agentSearchResult.currency} into your account. You can now give the client their cash or goods.`, 'success');
    agentSearchResult = null;
}

async function openSwapHistory() {
    openModal('Swap History', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading swaps...</div>');
    if (!CONFIG.USER_ID) {
        document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:30px;color:var(--danger);"><div style="font-size:48px;">⚠️</div><div style="font-weight:600;">Could not identify your account</div><div style="font-size:13px;color:var(--text-muted);margin-top:8px;">Your session doesn't have a user ID attached. Try logging out and back in.</div></div>`;
        return;
    }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/history.php', { user_id: CONFIG.USER_ID, limit: 50 });
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);">❌ Failed to load swap history: ${escapeHtml(result.error)}</div>`; return; }
    renderSwapHistory(result.body);
}
function renderSwapHistory(data) {
    const swaps = data.data || data.swaps || [];
    if (swaps.length === 0) { document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:30px;color:var(--text-muted);"><div style="font-size:48px;">📭</div><div style="font-weight:600;">No swaps found</div></div>`; return; }
    let historyHtml = `<div style="max-height:60vh;overflow-y:auto;"><div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Showing ${swaps.length} swap(s)</div>`;
    swaps.forEach((swap) => {
        const statusColor = swap.status === 'completed' || swap.status === 'success' ? 'var(--success)' : swap.status === 'pending' ? 'var(--warning)' : 'var(--danger)';
        const statusIcon = swap.status === 'completed' || swap.status === 'success' ? '✅' : swap.status === 'pending' ? '⏳' : '❌';
        historyHtml += `<div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:10px;background:#fff;cursor:pointer;" onclick="viewSwapDetail('${swap.reference || swap.swap_reference || 'N/A'}')">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div><div style="font-weight:600;">${swap.swap_type || 'SWAP'} <span style="font-size:11px;color:var(--text-muted);">${swap.reference || swap.swap_reference || ''}</span></div><div style="font-size:12px;color:var(--text-muted);">${swap.source_institution || 'Unknown'} → ${swap.destination_institution || 'Unknown'}</div></div>
                <div style="text-align:right;"><div style="font-weight:700;color:var(--primary-dark);">${swap.amount || 0} ${swap.currency || CONFIG.CURRENCY}</div><div style="font-size:11px;color:${statusColor};">${statusIcon} ${swap.status || 'unknown'}</div></div>
            </div></div>`;
    });
    historyHtml += `</div>`;
    document.getElementById('modalBody').innerHTML = historyHtml;
}
async function viewSwapDetail(reference) {
    openModal('Swap Details', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading details...</div>');
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/details.php', { reference: reference });
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);">❌ Failed to load swap details: ${escapeHtml(result.error)}</div>`; return; }
    renderSwapDetail(result.body);
}
function renderSwapDetail(data) {
    const swap = data.swap || data.data || {};
    document.getElementById('modalBody').innerHTML = `
        <div style="max-height:70vh;overflow-y:auto;">
            <div style="background:rgba(0,160,173,0.06);border-radius:var(--radius-sm);padding:16px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div><div style="font-size:12px;color:var(--text-muted);">Reference</div><div style="font-weight:600;">${swap.reference || swap.swap_reference || 'N/A'}</div></div>
                    <div><div style="font-size:12px;color:var(--text-muted);">Status</div><div style="font-weight:600;">${swap.status || 'unknown'}</div></div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                <div style="background:rgba(0,0,0,0.02);border-radius:var(--radius-sm);padding:12px;"><div style="font-size:11px;color:var(--text-muted);">Swap Type</div><div style="font-weight:600;">${swap.swap_type || 'N/A'}</div></div>
                <div style="background:rgba(0,0,0,0.02);border-radius:var(--radius-sm);padding:12px;"><div style="font-size:11px;color:var(--text-muted);">Amount</div><div style="font-weight:700;font-size:18px;color:var(--primary-dark);">${swap.amount || 0} ${swap.currency || CONFIG.CURRENCY}</div></div>
            </div>
            <div style="margin-top:12px;"><button class="btn btn-secondary" onclick="openSwapHistory()" style="width:100%;">← Back to History</button></div>
        </div>`;
}

function openModal(title, bodyHtml) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
    document.getElementById('modal').classList.add('active');
}
function closeModal() { document.getElementById('modal').classList.remove('active'); }
function showMessage(text, type = 'info') {
    const el = document.getElementById('mainMessage');
    el.textContent = text; el.className = `message show ${type}`;
    clearTimeout(showMessage._t);
    showMessage._t = setTimeout(() => el.classList.remove('show'), 6000);
}
function escapeHtml(str) { const div = document.createElement('div'); div.textContent = str == null ? '' : String(str); return div.innerHTML; }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>
