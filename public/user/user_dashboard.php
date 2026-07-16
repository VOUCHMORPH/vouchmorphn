<?php
// ============================================================
// FIX 1: Use SessionManager instead of bare session_start()
// ============================================================
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

// Start session using SessionManager
SessionManager::start();

// Check if user is logged in using SessionManager
if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Get user info from session using SessionManager
$userData = SessionManager::getUser();
$userName = $userData['full_name'] ?? $userData['username'] ?? 'User';
$userCountry = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
$userCurrency = $userData['currency'] ?? getenv('VOUCHMORPH_CURRENCY') ?: 'BWP';
$userRole = $userData['role'] ?? 'user';

// Get API configuration from environment
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: '';
$apiBase = getenv('API_BASE_URL') ?: '';

// Check if we're in test mode (no valid API key)
$isTestMode = empty($apiKey);

// ============================================================
// FIX 2: Local YAML-subset parser - NO dependency on the `yaml`
// PHP extension (yaml_parse_file), and NO separate file to require.
// This is intentionally self-contained in user_dashboard.php so
// there's no path/autoload issue to debug.
//
// Supports: nested maps, lists of scalars, lists of maps, quoted/
// unquoted scalars, booleans, numbers, inline [a, b, c] lists,
// and # comments. Does NOT support anchors/aliases, block scalars
// (| or >), or flow maps ({a: 1}).
// ============================================================
if (!function_exists('dashboard_yaml_parse_file')) {

    function dashboard_yaml_castScalar(string $v)
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }

        // ============================================================
        // Quoted values: find the ACTUAL closing quote by scanning the
        // string (skipping backslash-escaped characters), rather than
        // requiring $v to literally end with a quote. This correctly
        // handles a quoted value followed by a trailing inline comment,
        // e.g.  pattern: "^\\+?[0-9]{10,15}$"  # some comment
        // The old version required str_ends_with($v, '"'), which failed
        // here because the line ends with the comment text, not the
        // quote - so the whole quotes+comment blob was returned as-is
        // and corrupted downstream HTML rendering.
        // ============================================================
        if ($v[0] === '"' || $v[0] === "'") {
            $quote = $v[0];
            $len = strlen($v);
            for ($i = 1; $i < $len; $i++) {
                if ($v[$i] === '\\' && $i + 1 < $len) {
                    $i++; // skip escaped character, don't treat it as a delimiter
                    continue;
                }
                if ($v[$i] === $quote) {
                    return substr($v, 1, $i - 1);
                }
            }
            // No closing quote found - malformed line, but don't throw;
            // just return the trimmed raw value so parsing can continue.
            error_log("[dashboard_yaml_parse_file] Unterminated quoted value: {$v}");
            return $v;
        }

        // Unquoted values: strip a trailing inline comment if present.
        $hashPos = strpos($v, ' #');
        if ($hashPos !== false) {
            $v = trim(substr($v, 0, $hashPos));
        }

        $lower = strtolower($v);
        if ($lower === 'true' || $lower === 'yes') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no') {
            return false;
        }
        if ($lower === 'null' || $v === '~') {
            return null;
        }
        if (is_numeric($v)) {
            return $v + 0;
        }

        if ($v[0] === '[' && str_ends_with($v, ']')) {
            $inner = trim(substr($v, 1, -1));
            if ($inner === '') {
                return [];
            }
            return array_map(
                fn($x) => dashboard_yaml_castScalar(trim($x)),
                explode(',', $inner)
            );
        }

        return $v;
    }

    function dashboard_yaml_tokenize(string $content): array
    {
        $raw = explode("\n", str_replace("\r\n", "\n", $content));
        $lines = [];
        foreach ($raw as $line) {
            $trimmedRight = rtrim($line);
            if ($trimmedRight === '') {
                continue;
            }
            $stripped = ltrim($trimmedRight);
            if ($stripped === '' || $stripped[0] === '#') {
                continue;
            }
            if (preg_match('/^---\s*$/', $stripped) || preg_match('/^\.\.\.\s*$/', $stripped)) {
                continue;
            }
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

            if ($blockIndent === -1) {
                $blockIndent = $indent;
            }

            if ($indent < $blockIndent) {
                break;
            }

            if ($indent > $blockIndent) {
                $idx++;
                continue;
            }

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

            error_log("[dashboard_yaml_parse_file] Skipping unrecognized line: {$content}");
            $idx++;
        }

        return $result;
    }

    function dashboard_yaml_parse_file(string $path): array
    {
        if (!file_exists($path)) {
            error_log("[dashboard_yaml_parse_file] File not found: {$path}");
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            error_log("[dashboard_yaml_parse_file] Failed to read file: {$path}");
            return [];
        }

        try {
            $lines = dashboard_yaml_tokenize($content);
            $idx = 0;
            return dashboard_yaml_parseBlock($lines, $idx, -1);
        } catch (\Throwable $e) {
            error_log("[dashboard_yaml_parse_file] Failed to parse {$path}: " . $e->getMessage());
            return [];
        }
    }
}

// ============================================================
// LOAD COUNTRY CONFIGURATION - NO HARDCODED BANKS
// ============================================================
$countryConfig = [];
$countryConfigPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/config.php';
if (file_exists($countryConfigPath)) {
    $countryConfig = require $countryConfigPath;
}

// Load participants dynamically based on country - NO HARDCODED FALLBACK
$participants = [];
$participantsPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/participants.yaml';
if (file_exists($participantsPath)) {
    $parsed = dashboard_yaml_parse_file($participantsPath);
    $participants = $parsed['participants'] ?? [];
} else {
    error_log("[DASHBOARD] Participants file not found: " . $participantsPath);
}

// Load assets dynamically based on country
$assets = [];
$assetsPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/assets.yaml';
if (file_exists($assetsPath)) {
    $assets = dashboard_yaml_parse_file($assetsPath);
} else {
    error_log("[DASHBOARD] Assets file not found: " . $assetsPath);
}

// Get available countries for switching
$availableCountries = [];
$countriesDir = __DIR__ . '/../../src/Core/Config/Countries/';
if (is_dir($countriesDir)) {
    foreach (scandir($countriesDir) as $dir) {
        if (is_dir($countriesDir . $dir) && !in_array($dir, ['.', '..'])) {
            $availableCountries[] = $dir;
        }
    }
}

// ============================================================
// BUILD ASSETS FROM CONFIG - NO HARDCODED VALUES
// ============================================================
$assetTypes = [];
foreach ($assets as $assetKey => $assetConfig) {
    $assetTypes[$assetKey] = [
        'icon' => $assetConfig['icon'] ?? '📦',
        'label' => $assetConfig['label'] ?? $assetKey,
        'fields' => $assetConfig['fields'] ?? []
    ];
}

// If no assets loaded, show error but don't hardcode
if (empty($assetTypes)) {
    error_log("[DASHBOARD] No assets loaded for country: " . $userCountry);
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
    --bg: #0a0a12;
    --surface: rgba(255,255,255,0.04);
    --surface-hover: rgba(255,255,255,0.08);
    --border: rgba(255,255,255,0.08);
    --border-active: rgba(0,240,255,0.3);
    --text: #ffffff;
    --text-muted: #8888a0;
    --text-dim: #505070;
    --primary: #00f0ff;
    --primary-dark: #00c0d0;
    --gradient: linear-gradient(135deg, #00f0ff 0%, #b000ff 100%);
    --success: #00e676;
    --warning: #ffc107;
    --danger: #ff5252;
    --radius: 12px;
    --radius-sm: 8px;
    --font: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; padding: 16px; line-height: 1.5; }
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--text-dim); border-radius: 4px; }

.container { max-width: 560px; margin: 0 auto; }

.topbar { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
.logo { font-size: 22px; font-weight: 800; background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.topbar-right { display: flex; align-items: center; gap: 16px; font-size: 14px; flex-wrap: wrap; }
.topbar-right .greeting { color: var(--text-muted); }
.country-selector { background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 4px 10px; color: var(--text-muted); font-size: 12px; cursor: pointer; font-family: var(--font); }
.country-selector:focus { outline: none; border-color: var(--primary); }
.country-selector option { background: var(--bg); }
.logout-btn { color: var(--text-muted); text-decoration: none; padding: 6px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); transition: var(--transition); }
.logout-btn:hover { background: var(--surface-hover); color: var(--text); }
.role-badge { font-size: 10px; color: var(--primary); border: 1px solid var(--primary); padding: 2px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px; }
.test-mode-badge { font-size: 10px; color: #ff6b6b; border: 1px solid #ff6b6b; padding: 2px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px; animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

.message { padding: 12px 16px; border-radius: var(--radius-sm); margin: 0 0 16px; font-size: 13px; display: none; }
.message.show { display: block; }
.message.info { background: rgba(0,240,255,0.1); border-left: 3px solid var(--primary); color: var(--primary); }
.message.success { background: rgba(0,230,118,0.1); border-left: 3px solid var(--success); color: var(--success); }
.message.error { background: rgba(255,82,82,0.1); border-left: 3px solid var(--danger); color: var(--danger); }
.message.warning { background: rgba(255,193,7,0.1); border-left: 3px solid var(--warning); color: var(--warning); }

.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; }
.section { margin-bottom: 4px; }
.section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 12px; }
.section-title .n { width: 20px; height: 20px; border-radius: 50%; background: var(--gradient); color: #000; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

.swap-divider { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 20px 0; color: var(--text-dim); }
.swap-divider::before, .swap-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.swap-divider .icon { font-size: 16px; }

.field-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px; }

.field-group { margin-bottom: 12px; }
.field-group:last-child { margin-bottom: 0; }
.field-group label { display: block; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 4px; }
.field-group input, .field-group select {
    width: 100%; padding: 11px 14px; background: rgba(255,255,255,0.05); border: 1px solid var(--border);
    border-radius: var(--radius-sm); color: var(--text); font-size: 14px; font-family: var(--font); transition: var(--transition);
    appearance: none; -webkit-appearance: none;
}
.field-group select {
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6'><path d='M0 0l5 6 5-6z' fill='%238888a0'/></svg>");
    background-repeat: no-repeat; background-position: right 14px center; padding-right: 34px; cursor: pointer;
}
.field-group input:focus, .field-group select:focus { outline: none; border-color: var(--primary); }
.field-group input.invalid { border-color: var(--danger); }
.field-group .help { font-size: 11px; color: var(--text-dim); margin-top: 4px; }
.field-group input::placeholder { color: var(--text-dim); }
.field-group select option { background: var(--bg); }

.amount-field { position: relative; }
.amount-field input { padding-right: 64px; font-size: 18px; font-weight: 600; }
.amount-field .currency-suffix { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; font-weight: 600; pointer-events: none; }

.asset-fields { margin: 4px 0 12px; padding: 14px; background: rgba(255,255,255,0.02); border-radius: var(--radius-sm); border: 1px solid rgba(255,255,255,0.04); }

.identity-field { margin: 4px 0 12px; padding: 14px; background: rgba(0,240,255,0.04); border: 1px dashed var(--primary); border-radius: var(--radius-sm); }
.identity-field .hint { font-size: 11px; color: var(--text-dim); margin-top: 4px; }

.cta-row { display: flex; justify-content: center; margin-top: 24px; }
.btn { padding: 14px 40px; border: none; border-radius: 999px; font-size: 14px; font-weight: 700; font-family: var(--font); cursor: pointer; transition: var(--transition); }
.btn-primary { background: var(--gradient); color: #000; }
.btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,240,255,0.25); }
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; transform: none; box-shadow: none; }
.btn-success { background: var(--success); color: #000; }
.btn-success:hover:not(:disabled) { transform: translateY(-2px); }
.btn-success:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
.btn-secondary { background: rgba(255,255,255,0.06); color: var(--text); border: 1px solid var(--border); padding: 14px 32px; border-radius: 999px; font-size: 14px; font-weight: 600; font-family: var(--font); cursor: pointer; transition: var(--transition); }
.btn-secondary:hover { background: var(--surface-hover); }
.btn-danger-outline { background: transparent; color: var(--danger); border: 1px solid rgba(255,82,82,0.3); padding: 6px 14px; border-radius: 999px; font-size: 11px; cursor: pointer; }
.btn-danger-outline:hover { background: rgba(255,82,82,0.08); }

.quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: -4px 0 12px; }
.quick-link {
    display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: var(--primary);
    background: rgba(0,240,255,0.06); border: 1px solid rgba(0,240,255,0.15); padding: 5px 12px; border-radius: 999px;
    cursor: pointer; transition: var(--transition); font-family: var(--font); user-select: none;
}
.quick-link:hover { background: rgba(0,240,255,0.12); border-color: var(--border-active); }
.quick-link.muted { color: var(--text-muted); background: rgba(255,255,255,0.04); border-color: var(--border); }
.quick-link.muted:hover { color: var(--text); }
.quick-link.danger { color: var(--danger); background: rgba(255,82,82,0.06); border-color: rgba(255,82,82,0.15); }

.source-row { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 10px; background: rgba(255,255,255,0.02); }
.source-row-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.source-row-head .idx { font-size: 12px; color: var(--text-muted); font-weight: 600; }
.multi-total { text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 12px; }
.multi-total .amt { color: var(--text); font-weight: 700; }

.spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid rgba(0,0,0,0.3); border-top-color: #000; border-radius: 50%; animation: spin 0.7s linear infinite; margin-right: 6px; vertical-align: -2px; }
@keyframes spin { to { transform: rotate(360deg); } }

.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(8px); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 24px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px solid var(--border); margin-bottom: 16px; }
.modal-header h2 { font-size: 20px; font-weight: 700; }
.modal-close { background: none; border: none; color: var(--text-muted); font-size: 24px; cursor: pointer; transition: var(--transition); }
.modal-close:hover { color: var(--text); }
.preview-box { background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; margin: 12px 0; }
.preview-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.04); gap: 12px; }
.preview-row:last-child { border-bottom: none; }
.preview-row .label { color: var(--text-muted); font-size: 13px; }
.preview-row .value { font-weight: 600; font-size: 14px; text-align: right; }
.preview-row .value.highlight { color: var(--primary); font-size: 20px; }
.result-box { text-align: center; padding: 12px 0; }
.result-box .icon { font-size: 48px; }
.result-box .title { font-size: 18px; font-weight: 700; margin: 8px 0 4px; }
.result-box .ref { font-size: 13px; color: var(--text-muted); }
.result-box .amount-display { margin: 12px 0; padding: 12px; background: rgba(255,255,255,0.04); border-radius: var(--radius-sm); }
.result-box .amount-display .amt { font-size: 28px; font-weight: 700; }
.result-box .atm-code { margin: 12px 0; padding: 16px; background: rgba(0,240,255,0.08); border: 2px solid var(--primary); border-radius: var(--radius-sm); }
.result-box .atm-code .code { font-size: 28px; font-weight: 700; font-family: monospace; letter-spacing: 4px; }
.raw-json { text-align: left; font-size: 11px; background: rgba(0,0,0,0.3); border-radius: var(--radius-sm); padding: 10px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; color: var(--text-muted); margin-top: 12px; max-height: 200px; overflow-y: auto; }
details.raw-json-wrap summary { cursor: pointer; font-size: 11px; color: var(--text-dim); margin-top: 8px; }
.modal .cta-row { gap: 12px; }

@media (max-width: 480px) { .card { padding: 18px; } .btn, .btn-secondary { padding: 12px 24px; } .topbar { flex-direction: column; align-items: stretch; } .topbar-right { justify-content: center; } }
</style>
</head>
<body>
<div class="container">
<div class="topbar">
    <div class="logo">VOUCHMORPH</div>
    <div class="topbar-right">
        <span class="greeting">Hello, <span id="userName"><?php echo htmlspecialchars($userName); ?></span></span>
        <span class="role-badge"><?php echo htmlspecialchars(strtoupper($userRole)); ?></span>
        <?php if ($isTestMode): ?>
        <span class="test-mode-badge">🔓 TEST MODE</span>
        <?php endif; ?>
        <select class="country-selector" id="countrySelector" onchange="switchCountry(this.value)">
            <?php foreach ($availableCountries as $country): ?>
            <option value="<?php echo htmlspecialchars($country); ?>" <?php echo $country === $userCountry ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($country); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <span class="quick-link muted" onclick="openProfileModal()">👤 My Profile</span>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div id="mainMessage" class="message"></div>

<div class="card">
    <div class="section" id="fromSection">
        <div class="section-title"><span class="n">1</span> From</div>

        <div class="field-group">
            <label>Institution</label>
            <select id="fromInstSelect" onchange="selectFromInst(this.value)">
                <option value="">Select institution</option>
            </select>
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
        </div>
    </div>

    <div class="swap-divider"><span class="icon">⇅</span></div>

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
                <select id="toInstSelect" onchange="selectToInst(this.value)">
                    <option value="">Select institution</option>
                </select>
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
                <select id="identityType" onchange="state.toIdentityType=this.value">
                    <option value="national_id">National ID</option>
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
            <div class="hint">The recipient will be notified and can claim the funds within 24 hours</div>
        </div>

        <div id="multiSourceFields" style="display:none;">
            <label class="field-label">Sources (minimum 2)</label>
            <div id="multiSourceList"></div>
            <span class="quick-link" onclick="addMultiSourceRow()">➕ Add another source</span>
            <div class="multi-total">Total requested: <span class="amt" id="multiTotal"><?php echo $userCurrency; ?> 0.00</span></div>
            <div class="help" style="margin-top:6px;text-align:center;">Each source needs its own PIN — funds are only pulled once its balance and PIN are verified.</div>
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
// ============================================================
// CONFIGURATION - DYNAMIC FROM SERVER - NO HARDCODED VALUES
// ============================================================
const CONFIG = {
    API_KEY: '<?php echo htmlspecialchars($apiKey); ?>',
    COUNTRY_CODE: '<?php echo htmlspecialchars($userCountry); ?>',
    CURRENCY: '<?php echo htmlspecialchars($userCurrency); ?>',
    API_BASE: '<?php echo htmlspecialchars($apiBase); ?>',
    IS_TEST_MODE: <?php echo $isTestMode ? 'true' : 'false'; ?>,
    PREVIEW_ENDPOINT: '<?php echo $apiBase; ?>/api/v1/swap/preview.php',
    EXECUTE_ENDPOINT: '<?php echo $apiBase; ?>/api/v1/swap/execute.php',
};

// ============================================================
// PARTICIPANTS - LOADED DYNAMICALLY FROM COUNTRY FILE - NO HARDCODING
// ============================================================
const PARTICIPANTS = <?php echo json_encode($participants); ?>;

// ============================================================
// ASSETS - LOADED DYNAMICALLY FROM COUNTRY FILE - NO HARDCODING
// ============================================================
const ASSETS = <?php echo json_encode($assetTypes); ?>;

// ============================================================
// CASE/WHITESPACE-SAFE ASSET LOOKUP
// participants.yaml and assets.yaml are separate files. If an
// asset_types entry in participants.yaml doesn't match a key in
// assets.yaml EXACTLY (case, stray whitespace), ASSETS[type]
// silently returns undefined and the asset-specific form fields
// just don't render - no error, nothing in the console. This
// normalizes the lookup and logs loudly the moment that happens,
// so a config mismatch is visible instead of looking like a
// missing/broken form.
// ============================================================
const ASSET_KEY_MAP = {};
Object.keys(ASSETS).forEach(k => { ASSET_KEY_MAP[k.trim().toUpperCase()] = k; });

function getAssetConfig(type) {
    if (!type) return null;
    if (ASSETS[type]) return ASSETS[type];
    const normalized = String(type).trim().toUpperCase();
    const realKey = ASSET_KEY_MAP[normalized];
    if (realKey) {
        console.warn(`[assets] "${type}" only matched "${realKey}" after case/whitespace normalization - check that this asset_type is spelled identically in participants.yaml and assets.yaml.`);
        return ASSETS[realKey];
    }
    console.error(`[assets] No asset config found for type "${type}". Available in assets.yaml: ${Object.keys(ASSETS).join(', ') || '(none loaded)'}`);
    return null;
}

// ============================================================
// STATE
// ============================================================
let state = {
    fromInst: null, fromAsset: null, fromFields: {}, fromAmount: 0,
    swapType: 'DEPOSIT',
    toInst: null, toAsset: null, toFields: {},
    deliveryMethod: 'ATM', beneficiaryPhone: '',
    toIdentityType: 'national_id', toIdentityValue: '', toIdentitySms: '',
    multiSources: [],
    lastPreview: null,
    swapPayload: null,
};

let savedIdentities = [];

// ============================================================
// COUNTRY SWITCH
// ============================================================
function switchCountry(country) {
    if (country !== CONFIG.COUNTRY_CODE) {
        window.location.href = '?country=' + encodeURIComponent(country);
    }
}

// ============================================================
// INIT - DYNAMICALLY POPULATE FROM CONFIG - NO HARDCODING
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const fromSelect = document.getElementById('fromInstSelect');
    const toSelect = document.getElementById('toInstSelect');
    
    // Populate institutions from loaded participants - NO HARDCODING
    const instOptions = Object.keys(PARTICIPANTS);
    
    if (instOptions.length === 0) {
        // Show error if no participants loaded
        showMessage('No institutions found for this country. Please check the configuration.', 'error');
        console.error('No participants loaded for country:', CONFIG.COUNTRY_CODE);
    }
    
    instOptions.forEach(code => {
        const name = PARTICIPANTS[code]?.name || code;
        fromSelect.insertAdjacentHTML('beforeend', `<option value="${code}">${name}</option>`);
        toSelect.insertAdjacentHTML('beforeend', `<option value="${code}">${name}</option>`);
    });
    
    document.getElementById('fromAmount').addEventListener('input', function() {
        state.fromAmount = parseFloat(this.value) || 0;
        refreshUI();
    });
    refreshUI();
});

// ============================================================
// API HELPERS - Test mode support
// ============================================================
function buildHeaders() {
    const headers = { 'Content-Type': 'application/json' };
    if (CONFIG.COUNTRY_CODE) headers['X-Country-Code'] = CONFIG.COUNTRY_CODE;
    if (CONFIG.API_KEY && !CONFIG.IS_TEST_MODE) {
        headers['X-API-Key'] = CONFIG.API_KEY;
    }
    return headers;
}

async function callApi(endpoint, payload) {
    // Add test_mode parameter if in test mode
    let url = endpoint;
    if (CONFIG.IS_TEST_MODE) {
        url += (url.includes('?') ? '&' : '?') + 'test_mode=1';
        console.log('[TEST MODE] API call with test_mode=1');
    }
    
    let response, body;
    try {
        response = await fetch(url, { 
            method: 'POST', 
            headers: buildHeaders(), 
            body: JSON.stringify(payload) 
        });
    } catch (networkErr) {
        return { ok: false, error: 'Network error: could not reach ' + url + ' (' + networkErr.message + ')' };
    }
    try {
        body = await response.json();
    } catch (parseErr) {
        return { ok: false, error: 'Server returned a non-JSON response (HTTP ' + response.status + ')' };
    }
    if (!response.ok || body.success === false) {
        return { ok: false, error: body.error || ('HTTP ' + response.status), body };
    }
    return { ok: true, body };
}

// ============================================================
// FROM (SOURCE) - DYNAMIC FROM CONFIG
// ============================================================
function selectFromInst(code) {
    state.fromInst = code || null;
    state.fromAsset = null;
    state.fromFields = {};
    const assetGroup = document.getElementById('fromAssetGroup');
    if (!code) { assetGroup.style.display = 'none'; document.getElementById('fromFields').innerHTML = ''; refreshUI(); return; }
    const inst = PARTICIPANTS[code];
    if (!inst) { showMessage('Institution not found: ' + code, 'error'); return; }
    const sel = document.getElementById('fromAssetSelect');
    const assetTypes = inst.asset_types || [];
    sel.innerHTML = '<option value="">Select asset type</option>' + assetTypes.map(t => 
        `<option value="${t}">${getAssetConfig(t)?.icon || '📦'} ${getAssetConfig(t)?.label || t}</option>`
    ).join('');
    assetGroup.style.display = 'block';
    document.getElementById('fromCurrencyLabel').textContent = inst.limits?.currency || CONFIG.CURRENCY;
    document.getElementById('fromLimitsHelp').textContent = inst.limits
        ? `Limits: ${inst.limits.min_amount} – ${inst.limits.max_amount} ${inst.limits.currency}` : '';
    if (assetTypes.length === 1) { sel.value = assetTypes[0]; selectFromAsset(assetTypes[0]); }
    else { document.getElementById('fromFields').innerHTML = ''; refreshUI(); }
}

function selectFromAsset(type) {
    state.fromAsset = type || null;
    state.fromFields = {};
    const box = document.getElementById('fromFields');
    if (!type) { box.style.display = 'none'; box.innerHTML = ''; refreshUI(); return; }
    box.style.display = 'block';
    renderDynamicFields('fromFields', type, 'fromField_', updateFromField, true);
    refreshUI();
}

function updateFromField(name, value) { state.fromFields[name] = value; refreshUI(); }
function setAmount(val) { document.getElementById('fromAmount').value = val; state.fromAmount = val; refreshUI(); }

// ============================================================
// SHARED: dynamic asset field rendering - FROM CONFIG
// ============================================================
function assetHasAmountField(assetType) {
    return (getAssetConfig(assetType)?.fields || []).some(f => f.name === 'amount');
}

function renderDynamicFields(containerId, assetType, prefix, onChange, includePin) {
    const container = document.getElementById(containerId);
    const fields = (getAssetConfig(assetType)?.fields || [])
        .filter(f => includePin || f.vault_field !== 'pin')
        .filter(f => f.name !== 'amount');
    if (!fields || fields.length === 0) { container.innerHTML = ''; return; }
    container.innerHTML = fields.map(f => {
        const attrs = [];
        if (f.pattern) attrs.push(`pattern="${f.pattern}"`);
        if (f.min_length) attrs.push(`minlength="${f.min_length}"`);
        if (f.max_length) attrs.push(`maxlength="${f.max_length}"`);
        if (f.min !== undefined) attrs.push(`min="${f.min}"`);
        if (f.max !== undefined) attrs.push(`max="${f.max}"`);
        if (f.required) attrs.push('required');
        if (f.type === 'select') {
            return `<div class="field-group">
                <label>${f.label} ${f.required ? '*' : ''}</label>
                <select id="${prefix}${f.name}" onchange="window['${onChange.name}']('${f.name}', this.value)">
                    <option value="">${f.placeholder || 'Select'}</option>
                    ${(f.options || []).map(o => `<option value="${o}">${o}</option>`).join('')}
                </select>
                ${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}
            </div>`;
        }
        return `<div class="field-group">
            <label>${f.label} ${f.required ? '*' : ''}</label>
            <input type="${f.type}" id="${prefix}${f.name}" placeholder="${f.placeholder || ''}" ${attrs.join(' ')}
                   oninput="window['${onChange.name}']('${f.name}', this.value); validateDynamicField(this, ${JSON.stringify(f).replace(/"/g, '&quot;')})">
            ${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}
        </div>`;
    }).join('');
}

function validateDynamicField(input, field) {
    let valid = true;
    if (field.pattern && input.value) valid = new RegExp(field.pattern).test(input.value);
    input.classList.toggle('invalid', !valid && input.value.length > 0);
}

function fieldsValidForAsset(assetType, values, includePin) {
    const fields = (getAssetConfig(assetType)?.fields || [])
        .filter(f => includePin || f.vault_field !== 'pin')
        .filter(f => f.name !== 'amount');
    return fields.every(f => {
        const val = values[f.name];
        if (f.required && (!val || String(val).trim().length === 0)) return false;
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

// ============================================================
// TO (DESTINATION) - DYNAMIC FROM CONFIG
// ============================================================
function selectToInst(code) {
    state.toInst = code || null;
    state.toAsset = null;
    state.toFields = {};
    if (state.swapType === 'DEPOSIT' || state.swapType === 'MULTI_SOURCE') {
        const sel = document.getElementById('toAssetSelect');
        const group = document.getElementById('toAssetSection');
        if (!code) { group.style.display = 'none'; document.getElementById('toFields').style.display = 'none'; refreshUI(); return; }
        const inst = PARTICIPANTS[code];
        if (!inst) { showMessage('Institution not found: ' + code, 'error'); return; }
        const assetTypes = inst.asset_types || [];
        sel.innerHTML = '<option value="">Select asset type</option>' + assetTypes.map(t => 
            `<option value="${t}">${getAssetConfig(t)?.icon || '📦'} ${getAssetConfig(t)?.label || t}</option>`
        ).join('');
        group.style.display = 'block';
        if (assetTypes.length === 1) { sel.value = assetTypes[0]; selectToAsset(assetTypes[0]); }
        else { document.getElementById('toFields').style.display = 'none'; refreshUI(); }
    } else {
        refreshUI();
    }
}

function selectToAsset(type) {
    state.toAsset = type || null;
    state.toFields = {};
    const box = document.getElementById('toFields');
    if (!type) { box.style.display = 'none'; box.innerHTML = ''; refreshUI(); return; }
    box.style.display = 'block';
    renderDynamicFields('toFields', type, 'toField_', updateToField, false);
    refreshUI();
}

function updateToField(name, value) { state.toFields[name] = value; refreshUI(); }
function setDeliveryMethod(method) { state.deliveryMethod = method; refreshUI(); }

// ============================================================
// SWAP TYPE SWITCHING
// ============================================================
function setSwapType(type) {
    state.swapType = type;
    const isIdentity = type === 'IDENTITY';
    const isMulti = type === 'MULTI_SOURCE';
    const isDeposit = type === 'DEPOSIT';
    const isCashout = type === 'CASHOUT';

    document.getElementById('identityFields').style.display = isIdentity ? 'block' : 'none';
    document.getElementById('multiSourceFields').style.display = isMulti ? 'block' : 'none';
    document.getElementById('cashoutFields').style.display = isCashout ? 'block' : 'none';
    document.getElementById('toInstSection').style.display = (isDeposit || isCashout || isMulti) ? 'block' : 'none';
    document.getElementById('toAssetSection').style.display = (isDeposit || isMulti) && state.toAsset ? 'block' : 'none';
    document.getElementById('toFields').style.display = (isDeposit || isMulti) && state.toAsset ? 'block' : 'none';
    document.getElementById('fromSection').style.display = isMulti ? 'none' : 'block';
    document.querySelector('.swap-divider').style.display = isMulti ? 'none' : 'flex';

    if (isMulti && state.multiSources.length === 0) { addMultiSourceRow(); addMultiSourceRow(); }
    refreshUI();
}

function quickSetSwapType(type) {
    document.getElementById('swapTypeSelect').value = type;
    setSwapType(type);
    const target = type === 'IDENTITY' ? document.getElementById('identityFields') : document.getElementById('multiSourceFields');
    target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ============================================================
// MULTI-SOURCE ROWS - DYNAMIC FROM CONFIG
// ============================================================
let multiSourceSeq = 0;

function addMultiSourceRow() {
    state.multiSources.push({ id: ++multiSourceSeq, institution: null, assetType: null, fields: {}, amount: 0 });
    renderMultiSourceRows();
}

function removeMultiSourceRow(id) {
    state.multiSources = state.multiSources.filter(s => s.id !== id);
    renderMultiSourceRows();
}

function renderMultiSourceRows() {
    const container = document.getElementById('multiSourceList');
    container.innerHTML = state.multiSources.map((src, idx) => `
        <div class="source-row">
            <div class="source-row-head">
                <span class="idx">Source ${idx + 1}</span>
                ${state.multiSources.length > 2 ? `<button class="btn-danger-outline" onclick="removeMultiSourceRow(${src.id})">Remove</button>` : ''}
            </div>
            <div class="field-group">
                <label>Institution</label>
                <select onchange="setMultiSourceInst(${src.id}, this.value)">
                    <option value="">Select institution</option>
                    ${Object.keys(PARTICIPANTS).map(code => `<option value="${code}" ${src.institution === code ? 'selected' : ''}>${PARTICIPANTS[code]?.name || code}</option>`).join('')}
                </select>
            </div>
            ${src.institution ? `
            <div class="field-group">
                <label>Asset Type</label>
                <select onchange="setMultiSourceAsset(${src.id}, this.value)">
                    <option value="">Select asset type</option>
                    ${(PARTICIPANTS[src.institution]?.asset_types || []).map(t => `<option value="${t}" ${src.assetType === t ? 'selected' : ''}>${getAssetConfig(t)?.label || t}</option>`).join('')}
                </select>
            </div>` : ''}
            ${src.assetType ? (getAssetConfig(src.assetType)?.fields || []).filter(f => f.name !== 'amount').map(f => `
                <div class="field-group">
                    <label>${f.label} ${f.required ? '*' : ''}</label>
                    <input type="${f.type === 'select' ? 'text' : f.type}" value="${src.fields[f.name] || ''}"
                           placeholder="${f.placeholder || ''}"
                           oninput="setMultiSourceField(${src.id}, '${f.name}', this.value)">
                </div>
            `).join('') : ''}
            <div class="field-group">
                <label>Amount to pull from this source</label>
                <input type="number" min="0.01" step="0.01" value="${src.amount || ''}" placeholder="0.00"
                       oninput="setMultiSourceAmount(${src.id}, this.value)">
            </div>
        </div>
    `).join('');
    updateMultiTotal();
    refreshUI();
}

function setMultiSourceInst(id, code) {
    const src = state.multiSources.find(s => s.id === id);
    src.institution = code || null; src.assetType = null; src.fields = {};
    renderMultiSourceRows();
}

function setMultiSourceAsset(id, type) {
    const src = state.multiSources.find(s => s.id === id);
    src.assetType = type || null; src.fields = {};
    renderMultiSourceRows();
}

function setMultiSourceField(id, name, value) {
    state.multiSources.find(s => s.id === id).fields[name] = value;
    refreshUI();
}

function setMultiSourceAmount(id, value) {
    state.multiSources.find(s => s.id === id).amount = parseFloat(value) || 0;
    updateMultiTotal();
    refreshUI();
}

function updateMultiTotal() {
    const total = state.multiSources.reduce((sum, s) => sum + (s.amount || 0), 0);
    document.getElementById('multiTotal').textContent = `${CONFIG.CURRENCY} ${total.toFixed(2)}`;
}

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

// ============================================================
// VALIDATION
// ============================================================
function refreshUI() {
    const btn = document.getElementById('reviewBtn');
    btn.disabled = !isSwapReady();
}

function isSwapReady() {
    if (state.swapType === 'MULTI_SOURCE') {
        return multiSourcesValid() && state.toInst && state.toAsset && fieldsValidForAsset(state.toAsset, state.toFields, false);
    }
    const hasSource = state.fromInst && state.fromAsset;
    if (!hasSource) return false;
    if (!(state.fromAmount > 0) || !amountWithinLimits(state.fromInst, state.fromAmount)) return false;
    if (!fieldsValidForAsset(state.fromAsset, state.fromFields, true)) return false;

    if (state.swapType === 'IDENTITY') return !!state.toIdentityValue;
    if (state.swapType === 'CASHOUT') return !!state.toInst;
    return !!(state.toInst && state.toAsset && fieldsValidForAsset(state.toAsset, state.toFields, false));
}

// ============================================================
// BUILD PAYLOAD
// ============================================================
function buildPayload() {
    const reference = 'SWAP_' + Date.now();
    const idempotencyKey = 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

    if (state.swapType === 'MULTI_SOURCE') {
        const sources = state.multiSources.map(s => {
            const pin = extractPinFromFields(s.assetType, s.fields);
            const identifierField = (ASSETS[s.assetType]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
            const assetFields = { ...s.fields };
            if (assetHasAmountField(s.assetType)) assetFields.amount = s.amount;
            return {
                institution: s.institution, asset_type: s.assetType,
                identifier: identifierField ? s.fields[identifierField.name] : null,
                amount: s.amount, wallet_pin: pin || undefined, pin: pin || undefined, asset_fields: assetFields,
            };
        });
        const totalAmount = sources.reduce((sum, s) => sum + s.amount, 0);
        const destFields = { ...state.toFields };
        if (assetHasAmountField(state.toAsset)) destFields.amount = totalAmount;
        const destIdField = (ASSETS[state.toAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        const payload = {
            swap_type: 'MULTI_SOURCE', reference, idempotency_key: idempotencyKey,
            amount: totalAmount, currency: CONFIG.CURRENCY, contribution_strategy: 'USER_SPECIFIED',
            sources, to_institution: state.toInst, destination_institution: state.toInst,
            destination_asset_type: state.toAsset, asset_type: state.toAsset,
            destination_asset_fields: destFields,
        };
        for (const [key, value] of Object.entries(destFields)) payload[`destination_${key}`] = value;
        if (destIdField) payload.destination_identifier = state.toFields[destIdField.name];
        return payload;
    }

    const pin = extractPinFromFields(state.fromAsset, state.fromFields);
    const sourceAssetFields = { ...state.fromFields };
    if (assetHasAmountField(state.fromAsset)) sourceAssetFields.amount = state.fromAmount;
    const payload = {
        swap_type: state.swapType, reference, idempotency_key: idempotencyKey,
        from_institution: state.fromInst, source_institution: state.fromInst,
        asset_type: state.fromAsset, amount: state.fromAmount,
        currency: PARTICIPANTS[state.fromInst]?.limits?.currency || CONFIG.CURRENCY,
        wallet_pin: pin || undefined, pin: pin || undefined, asset_fields: sourceAssetFields,
        ...sourceAssetFields,
    };
    payload.amount = state.fromAmount;
    const idField = (ASSETS[state.fromAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    if (idField) payload.source_identifier = state.fromFields[idField.name];

    if (state.swapType === 'IDENTITY') {
        payload.identity_type = state.toIdentityType;
        payload.identity_value = state.toIdentityValue;
        if (state.toIdentitySms) payload.notification_phone = state.toIdentitySms;
    } else if (state.swapType === 'CASHOUT') {
        payload.to_institution = state.toInst;
        payload.destination_institution = state.toInst;
        payload.delivery_method = state.deliveryMethod;
        if (state.beneficiaryPhone) { payload.beneficiary_phone = state.beneficiaryPhone; payload.client_phone = state.beneficiaryPhone; }
    } else {
        payload.to_institution = state.toInst;
        payload.destination_institution = state.toInst;
        payload.destination_asset_type = state.toAsset;
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

// ============================================================
// PREVIEW
// ============================================================
async function previewSwap() {
    if (!isSwapReady()) {
        if (state.swapType === 'MULTI_SOURCE') {
            showMessage('Fill in every source (institution, asset type, identifier, PIN, amount — at least 2) and the destination.', 'warning');
        } else if (!state.fromInst || !state.fromAsset) {
            showMessage('Please select a source institution and asset.', 'warning');
        } else if (!amountWithinLimits(state.fromInst, state.fromAmount)) {
            const l = PARTICIPANTS[state.fromInst].limits;
            showMessage(`Amount must be between ${l.min_amount} and ${l.max_amount} ${l.currency}.`, 'warning');
        } else {
            showMessage('Please fill in all required fields.', 'warning');
        }
        return;
    }

    const payload = buildPayload();
    state.swapPayload = payload;

    const btn = document.getElementById('reviewBtn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Working…';

    const result = await callApi(CONFIG.EXECUTE_ENDPOINT, payload);

    btn.disabled = false;
    btn.innerHTML = original;
    refreshUI();

    if (!result.ok) { showMessage('Swap failed: ' + result.error, 'error'); return; }
    showResultModal(result.body);
}

// ============================================================
// RESULT MODAL
// ============================================================
function showResultModal(response) {
    const data = response.data || {};
    const swapType = state.swapPayload.swap_type;
    const reference = response.swap_reference || data.reference || data.swap_reference || '—';
    let inner = '';

    if (swapType === 'CASHOUT') {
        inner = `
            <div class="icon">💰</div>
            <div class="title">Cashout Code Generated</div>
            <div class="ref">Reference: ${escapeHtml(reference)}</div>
            ${data.atm_code || data.voucher_number ? `
            <div class="atm-code">
                <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Code</div>
                <div class="code">${escapeHtml(data.atm_code || data.voucher_number || data.swap_code || '')}</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Expires: ${escapeHtml(data.code_expiry || 'see reference')}</div>
            </div>` : ''}
            <div class="amount-display">
                <div style="font-size:12px;color:var(--text-muted);">Amount</div>
                <div class="amt">${data.amount ?? state.swapPayload.amount} ${state.swapPayload.currency || CONFIG.CURRENCY}</div>
            </div>`;
    } else if (swapType === 'IDENTITY') {
        inner = `
            <div class="icon">🔑</div>
            <div class="title">Identity Swap Initiated</div>
            <div class="ref">Reference: ${escapeHtml(reference)}</div>
            <div style="margin:12px 0;padding:12px;background:rgba(0,240,255,0.04);border-radius:var(--radius-sm);">
                <div style="font-size:12px;color:var(--text-muted);">Identity</div>
                <div style="font-size:16px;font-weight:600;">${escapeHtml(data.identity_type || state.swapPayload.identity_type)}: ${escapeHtml(data.identity_value || state.swapPayload.identity_value)}</div>
            </div>
            <div class="amount-display">
                <div style="font-size:12px;color:var(--text-muted);">Amount Held</div>
                <div class="amt">${data.amount ?? state.swapPayload.amount} ${data.currency || CONFIG.CURRENCY}</div>
            </div>
            <div style="font-size:13px;color:var(--text-muted);margin:8px 0;">⏳ Waiting for recipient to claim (expires ${escapeHtml(data.expires_at || 'in 24h')})</div>`;
    } else if (swapType === 'MULTI_SOURCE') {
        inner = `
            <div class="icon">📤</div>
            <div class="title">Multi-Source Swap Submitted</div>
            <div class="ref">Reference: ${escapeHtml(reference)}</div>
            <div style="font-size:13px;color:var(--text-muted);margin:8px 0;">Status: ${escapeHtml(response.status || data.status || 'submitted')}</div>`;
    } else {
        inner = `
            <div class="icon">✅</div>
            <div class="title">Swap Completed</div>
            <div class="ref">Reference: ${escapeHtml(reference)}</div>
            <div class="amount-display">
                <div style="font-size:12px;color:var(--text-muted);">Amount</div>
                <div class="amt">${data.amount ?? state.swapPayload.amount} ${CONFIG.CURRENCY}</div>
                ${data.fee !== undefined ? `<div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Fee: ${data.fee}</div>` : ''}
            </div>`;
    }

    openModal('Swap Result', `
        <div class="result-box">
            ${inner}
            <details class="raw-json-wrap"><summary>Raw response</summary><div class="raw-json">${escapeHtml(JSON.stringify(response, null, 2))}</div></details>
            <div class="cta-row"><button class="btn btn-primary" onclick="closeModal(); location.reload();">Done</button></div>
        </div>
    `);
}

// ============================================================
// MY PROFILE - Saved Identities
// ============================================================
const IDENTITY_TYPE_LABELS = { national_id: 'National ID', phone: 'Phone Number', email: 'Email' };

function openProfileModal() {
    openModal('My Profile', renderProfileModal());
}

function renderProfileModal() {
    const rows = savedIdentities.length
        ? savedIdentities.map((id, i) => `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);">
                <div>
                    <div style="font-size:11px;color:var(--text-muted);">${escapeHtml(IDENTITY_TYPE_LABELS[id.type] || id.type)}</div>
                    <div style="font-size:14px;font-weight:600;">${escapeHtml(id.value)}</div>
                </div>
                <div class="quick-actions" style="margin:0;">
                    <span class="quick-link" onclick="useSavedIdentity(${i})">✓ Use</span>
                    <span class="quick-link danger" onclick="removeSavedIdentity(${i})">✕ Remove</span>
                </div>
            </div>`).join('')
        : `<div class="hint">No saved identities yet — add a phone number, national ID, or email below for quick reuse in the "Swap to Identity" flow.</div>`;
    return `
        <div style="margin-bottom:12px;">${rows}</div>
        <div class="field-group">
            <label>Identity Type</label>
            <select id="newIdentityType">
                <option value="national_id">National ID</option>
                <option value="phone">Phone Number</option>
                <option value="email">Email</option>
            </select>
        </div>
        <div class="field-group">
            <label>Identity Value</label>
            <input id="newIdentityValue" placeholder="Enter the identity value">
        </div>
        <div class="cta-row"><button class="btn btn-primary" onclick="addSavedIdentity()">+ Add Identity</button></div>
        <div class="hint" style="margin-top:8px;">Saved here for this session only.</div>`;
}

function addSavedIdentity() {
    const type = document.getElementById('newIdentityType').value;
    const value = document.getElementById('newIdentityValue').value.trim();
    if (!value) { showMessage('Enter an identity value first', 'error'); return; }
    savedIdentities.push({ type, value });
    document.getElementById('modalBody').innerHTML = renderProfileModal();
}

function removeSavedIdentity(idx) {
    savedIdentities.splice(idx, 1);
    document.getElementById('modalBody').innerHTML = renderProfileModal();
}

function useSavedIdentity(idx) {
    const id = savedIdentities[idx];
    if (!id) return;
    closeModal();
    quickSetSwapType('IDENTITY');
    state.toIdentityType = id.type;
    state.toIdentityValue = id.value;
    document.getElementById('identityType').value = id.type;
    document.getElementById('identityValue').value = id.value;
    refreshUI();
    showMessage(`Using saved ${IDENTITY_TYPE_LABELS[id.type] || id.type}: ${id.value}`, 'success');
}

// ============================================================
// MODAL / MESSAGE HELPERS
// ============================================================
function openModal(title, bodyHtml) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
    document.getElementById('modal').classList.add('active');
}

function closeModal() { document.getElementById('modal').classList.remove('active'); }

function showMessage(text, type = 'info') {
    const el = document.getElementById('mainMessage');
    el.textContent = text;
    el.className = `message show ${type}`;
    clearTimeout(showMessage._t);
    showMessage._t = setTimeout(() => el.classList.remove('show'), 6000);
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>
