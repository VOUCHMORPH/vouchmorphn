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
        'icon' => $assetConfig['icon'] ?? '',
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
    --border: rgba(0,0,0,0.16); --border-active: rgba(0,150,160,0.5);
    /* Dark, near-black text throughout. Colored accents (primary/success/warning/danger) are untouched. */
    --text: #0d0d0d; --text-muted: #1f1f1f; --text-dim: #3a3a3a;
    --primary: #00a0ad; --primary-dark: #007d88;
    --gradient: linear-gradient(135deg, #00a0ad 0%, #8a2be2 100%);
    --success: #1a9e5c; --warning: #b8860b; --danger: #d32f2f;
    /* Sharp edges everywhere. */
    --radius: 0px; --radius-sm: 0px;
    --font: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --transition: all 0.2s ease;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; line-height: 1.5; padding: 24px 16px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.container { width: 100%; max-width: 960px; margin: 6vh auto 0; position: relative; z-index: 1; }
.doodle-bg { position: fixed; inset: 0; width: 100vw; height: 100vh; z-index: 0; pointer-events: none; overflow: hidden; }
.topbar { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
.logo { font-size: 22px; font-weight: 800; color: var(--text); letter-spacing: 0.3px; }
.logo sup { font-size: 11px; font-weight: 700; margin-left: 2px; }
.page-footer { max-width: 960px; width: 100%; margin: 40px auto 0; text-align: center; }
.page-footer p { font-size: 13px; color: var(--text-dim); line-height: 1.7; max-width: 620px; margin: 0 auto 14px; }
.page-footer .footer-links { display: flex; gap: 18px; justify-content: center; font-size: 12px; }
.page-footer .footer-links span { color: var(--text-muted); font-weight: 700; cursor: pointer; }
.page-footer .footer-links span:hover { color: var(--text); }
.page-footer .footer-mark { font-size: 11px; color: var(--text-dim); margin-top: 16px; }
.topbar-right { display: flex; align-items: center; gap: 14px; font-size: 14px; flex-wrap: wrap; }
.topbar-right .greeting { color: var(--text-muted); }
.country-selector { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); padding: 6px 10px; color: var(--text-muted); font-size: 12px; cursor: pointer; font-family: var(--font); }
.logout-btn { color: var(--text-muted); text-decoration: none; padding: 8px 14px; border: 1px solid var(--border); border-radius: var(--radius); font-weight: 600; font-size: 13px; }
.logout-btn:hover { border-color: var(--danger); color: var(--danger); }
.role-badge { font-size: 10px; color: var(--primary-dark); border: 1px solid var(--primary); padding: 3px 10px; border-radius: var(--radius); text-transform: uppercase; font-weight: 700; }
.agent-badge { font-size: 10px; color: #fff; background: var(--primary-dark); padding: 3px 10px; border-radius: var(--radius); text-transform: uppercase; font-weight: 700; }
.test-mode-badge { font-size: 10px; color: #791f1f; border: 1px solid #d32f2f; padding: 3px 10px; border-radius: var(--radius); text-transform: uppercase; font-weight: 700; }

/* Toolbox trigger — one door into everything that used to crowd the topbar */
.toolbox-btn { position: relative; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #fff; background: var(--text); border: none; padding: 9px 18px; border-radius: var(--radius); cursor: pointer; }
.toolbox-btn:hover { background: #000; }
.toolbox-badge { min-width: 18px; height: 18px; padding: 0 5px; background: var(--danger); color: #fff; font-size: 11px; font-weight: 700; border-radius: var(--radius); display: inline-flex; align-items: center; justify-content: center; }

.message { padding: 12px 16px; border-radius: var(--radius); margin: 0 0 16px; font-size: 13px; display: none; font-weight: 600; }
.message.show { display: block; }
.message.info { background: rgba(0,160,173,0.10); border-left: 3px solid var(--primary); color: #00707a; }
.message.success { background: rgba(26,158,92,0.10); border-left: 3px solid var(--success); color: #146b40; }
.message.error { background: rgba(211,47,47,0.08); border-left: 3px solid var(--danger); color: #a12525; }
.message.warning { background: rgba(184,134,11,0.10); border-left: 3px solid var(--warning); color: #8a6508; }

.card { background: transparent; border: none; padding: 0; }
.section.split-box { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; }
.swap-columns { display: flex; align-items: stretch; gap: 18px; }
.swap-columns > .section { flex: 1 1 0; min-width: 0; }
@media (max-width: 860px) { .container { max-width: 560px; } .swap-columns { flex-direction: column; } .swap-divider span { transform: rotate(0deg) !important; } }
.swap-divider { display: flex; align-items: center; justify-content: center; margin: 0; color: var(--text-dim); flex: 0 0 auto; }
.swap-divider span { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border: 1px solid var(--border); background: #fff; border-radius: var(--radius); font-size: 15px; transform: rotate(90deg); }
.section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; margin-bottom: 14px; color: var(--text); }
.section-title .n { width: 20px; height: 20px; border-radius: var(--radius); background: var(--text); color: #fff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; }

.field-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 4px; font-weight: 700; letter-spacing: 0.3px; }
.field-group { margin-bottom: 12px; }
.field-group label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; letter-spacing: 0.3px; }
.field-group input, .field-group select { width: 100%; padding: 12px 14px; background: #fff; border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); font-size: 16px; font-family: var(--font); }
.field-group input:focus, .field-group select:focus { outline: none; border-color: var(--primary); }
.field-group input.invalid { border-color: var(--danger); }
.field-group .help { font-size: 12px; color: var(--text-dim); margin-top: 4px; }
.amount-field { position: relative; }
.amount-field input { padding-right: 64px; font-size: 18px; font-weight: 700; }
.amount-field .currency-suffix { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; font-weight: 700; }
.asset-fields { margin: 4px 0 12px; padding: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); }
.identity-field { margin: 4px 0 12px; padding: 14px; background: rgba(0,160,173,0.05); border: 1px dashed var(--primary); border-radius: var(--radius); }
.cta-row { display: flex; justify-content: center; margin-top: 24px; gap: 12px; }
.btn { padding: 14px 40px; border: none; border-radius: var(--radius); font-size: 14px; font-weight: 700; font-family: var(--font); cursor: pointer; }
.btn-primary { background: var(--text); color: #fff; }
.btn-primary:hover { background: #000; }
.btn-primary:disabled { opacity: 0.35; cursor: not-allowed; }
.btn-secondary { background: #fff; color: var(--text); border: 1px solid var(--border); padding: 14px 32px; border-radius: var(--radius); font-weight: 700; cursor: pointer; }
.btn-danger-outline { background: transparent; color: var(--danger); border: 1px solid rgba(211,47,47,0.4); padding: 6px 14px; border-radius: var(--radius); font-size: 11px; cursor: pointer; font-weight: 700; }
.btn-sm { padding: 8px 18px !important; font-size: 12px; }
.quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: -4px 0 12px; }
.quick-link { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: var(--primary-dark); background: rgba(0,160,173,0.07); border: 1px solid rgba(0,160,173,0.25); padding: 6px 12px; border-radius: var(--radius); cursor: pointer; }
.quick-link.muted { color: var(--text-muted); background: var(--surface); border-color: var(--border); }
.quick-link.danger { color: var(--danger); background: rgba(211,47,47,0.05); border-color: rgba(211,47,47,0.25); }
.source-row { border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; margin-bottom: 10px; background: #fff; }
.source-row-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.multi-total { text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 12px; font-weight: 600; }
.spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: spin 0.7s linear infinite; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(10,10,10,0.6); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal { background: #fff; border-radius: var(--radius); max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 24px; border: 1px solid var(--border); }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px solid var(--border); margin-bottom: 16px; }
.modal-header h2 { font-size: 16px; font-weight: 700; }
.modal-close { background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer; line-height: 1; }
.preview-box { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; margin: 12px 0; }
.preview-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--border); gap: 12px; font-size: 13px; }
.preview-row .value.highlight { color: var(--primary-dark); font-size: 20px; font-weight: 700; }
.result-box { text-align: center; padding: 12px 0; }
.result-box .icon { font-size: 40px; }
.result-box .atm-code { margin: 12px 0; padding: 16px; background: rgba(0,160,173,0.06); border: 2px solid var(--primary); border-radius: var(--radius); }
.result-box .atm-code .code { font-size: 26px; font-weight: 700; font-family: monospace; letter-spacing: 4px; color: var(--primary-dark); }
.raw-json { text-align: left; font-size: 11px; background: var(--surface); border-radius: var(--radius); padding: 10px; white-space: pre-wrap; word-break: break-all; color: var(--text-dim); margin-top: 12px; max-height: 200px; overflow-y: auto; }
@media (max-width: 480px) { body { padding: 12px; } .btn, .btn-secondary { padding: 12px 24px; width: 100%; } .cta-row { flex-direction: column; } .topbar { flex-direction: column; align-items: stretch; } }

/* Source category tabs (Wallet/Account, Card, Voucher) */
.type-tabs { display: flex; gap: 8px; margin-bottom: 14px; }
.type-tab { flex: 1; padding: 11px 10px; font-size: 12px; font-weight: 700; text-align: center; background: #fff; color: var(--text-muted); border: 1px solid var(--border); border-radius: var(--radius); cursor: pointer; font-family: var(--font); }
.type-tab:hover { border-color: var(--primary); color: var(--primary-dark); }
.type-tab.active { background: var(--text); color: #fff; border-color: var(--text); }
.source-panel { margin-bottom: 4px; }
.empty-source-box { text-align: center; padding: 18px 12px; border: 1px dashed var(--border); border-radius: var(--radius); background: var(--surface); }
.empty-source-box p { font-size: 12px; color: var(--text-dim); margin-bottom: 10px; }

/* Source management styles */
.source-card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); padding: 14px; margin-bottom: 10px; }
.source-card .source-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.source-card .source-institution { font-weight: 700; font-size: 15px; }
.source-card .source-details { font-size: 13px; color: var(--text-muted); }
.source-card .source-status { font-size: 11px; padding: 3px 10px; border-radius: var(--radius); font-weight: 700; }
.source-status.active { background: #dcfce7; color: #166534; }
.source-status.pending { background: #fef3c7; color: #8a5a0b; }
.source-status.inactive { background: #fbeceb; color: var(--danger); }
.otp-input-group { display: flex; gap: 8px; margin: 12px 0; }
.otp-input-group input { flex: 1; }
.otp-input-group button { flex-shrink: 0; }

/* Quick source chips (Wallet/Account only) */
.source-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; padding: 6px 12px; border-radius: var(--radius); cursor: pointer; border: 1px solid var(--border); background: #fff; }
.source-chip:hover { background: var(--text); color: #fff; border-color: var(--text); }
.source-chip .chip-identifier { font-weight: 400; color: var(--text-dim); font-size: 10px; }
.source-chip:hover .chip-identifier { color: rgba(255,255,255,0.75); }
.source-chip.active { background: var(--text); color: #fff; border-color: var(--text); }
.source-chip.active .chip-identifier { color: rgba(255,255,255,0.75); }

/* Toolbox modal rows */
.toolbox-list { display: flex; flex-direction: column; gap: 2px; }
.toolbox-row { display: flex; align-items: center; gap: 12px; padding: 13px 12px; border: 1px solid var(--border); border-radius: var(--radius); cursor: pointer; margin-bottom: 6px; background: #fff; }
.toolbox-row:hover { background: var(--surface); border-color: var(--text); }
.toolbox-row-icon { width: 22px; text-align: center; font-size: 15px; }
.toolbox-row-label { flex: 1; font-size: 14px; font-weight: 600; color: var(--text); }
.toolbox-row-badge { min-width: 18px; height: 18px; padding: 0 5px; background: var(--danger); color: #fff; font-size: 11px; font-weight: 700; border-radius: var(--radius); display: inline-flex; align-items: center; justify-content: center; }
</style>
</head>
<body>
<div class="doodle-bg" aria-hidden="true">
<svg width="100%" height="100%" viewBox="0 0 1440 1000" preserveAspectRatio="xMidYMid slice" focusable="false">
<defs>
<symbol id="d-wallet" viewBox="0 0 40 40"><rect x="4" y="10" width="32" height="22" rx="4"/><path d="M4 17h32" stroke-linecap="round"/><circle cx="27" cy="24" r="2"/></symbol>
<symbol id="d-coin" viewBox="0 0 40 40"><circle cx="20" cy="20" r="14"/><path d="M20 13v14M16 16.5h6a3 3 0 0 1 0 6h-4a3 3 0 0 0 0 6h6" stroke-linecap="round"/></symbol>
<symbol id="d-card" viewBox="0 0 40 40"><rect x="3" y="9" width="34" height="22" rx="4"/><path d="M3 16.5h34" stroke-linecap="round"/><path d="M9 25h9" stroke-linecap="round"/></symbol>
<symbol id="d-swap" viewBox="0 0 40 40"><path d="M6 14h22" stroke-linecap="round"/><path d="M22 8l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/><path d="M34 26H12" stroke-linecap="round"/><path d="M18 32l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="d-phone" viewBox="0 0 40 40"><rect x="11" y="4" width="18" height="32" rx="4"/><path d="M18 32h4" stroke-linecap="round"/></symbol>
<symbol id="d-bank" viewBox="0 0 40 40"><path d="M20 4l16 9H4z" stroke-linejoin="round"/><path d="M7 15v16M15 15v16M25 15v16M33 15v16" stroke-linecap="round"/><path d="M4 35h32" stroke-linecap="round"/></symbol>
<symbol id="d-envelope" viewBox="0 0 40 40"><rect x="3" y="8" width="34" height="24" rx="3"/><path d="M3 10.5l17 12.5L37 10.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="d-check" viewBox="0 0 40 40"><circle cx="20" cy="20" r="15"/><path d="M13 20.5l5 5 10-11" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="p-chevron" viewBox="0 0 40 40"><path d="M4 12l9 8-9 8M17 12l9 8-9 8M30 12l6 8-6 8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="p-diamond" viewBox="0 0 40 40"><path d="M20 4l16 16-16 16L4 20z" stroke-linejoin="round"/><path d="M20 14l6 6-6 6-6-6z" stroke-linejoin="round"/></symbol>
<symbol id="p-triangles" viewBox="0 0 40 40"><path d="M8 30l7-14 7 14z" stroke-linejoin="round"/><path d="M22 30l7-14 7 14z" stroke-linejoin="round"/></symbol>
<symbol id="p-sun" viewBox="0 0 40 40"><circle cx="20" cy="20" r="7"/><path d="M20 3v6M20 31v6M3 20h6M31 20h6M8 8l4.2 4.2M27.8 27.8L32 32M32 8l-4.2 4.2M12.2 27.8L8 32" stroke-linecap="round"/></symbol>
<symbol id="p-spiral" viewBox="0 0 40 40"><path d="M20 20c0-3.3 2.7-6 6-6s6 2.7 6 6-2.7 6-6 6-8-3.6-8-8 4-10 10-10 12 5.4 12 12" stroke-linecap="round"/></symbol>
<symbol id="p-dots" viewBox="0 0 40 40"><circle cx="20" cy="10" r="2.4"/><circle cx="11" cy="27" r="2.4"/><circle cx="29" cy="27" r="2.4"/></symbol>
</defs>
<g fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
<use href="#p-chevron" transform="translate(47.3 29.1) rotate(45) scale(0.57) translate(-20 -20)" opacity="0.046" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(159.5 25.2) rotate(-30) scale(0.89) translate(-20 -20)" opacity="0.023" stroke="#8B5A3C"/>
<use href="#p-chevron" transform="translate(332.2 57.8) rotate(60) scale(0.91) translate(-20 -20)" opacity="0.022" stroke="#8B5A3C"/>
<use href="#p-spiral" transform="translate(424.1 28.0) rotate(-30) scale(0.9) translate(-20 -20)" opacity="0.045" stroke="#8B5A3C"/>
<use href="#p-chevron" transform="translate(511.4 54.3) rotate(45) scale(1.0) translate(-20 -20)" opacity="0.045" stroke="#8B5A3C"/>
<use href="#d-check" transform="translate(659.7 51.9) rotate(60) scale(1.15) translate(-20 -20)" opacity="0.036" stroke="#8B5A3C"/>
<use href="#p-triangles" transform="translate(756.9 66.8) rotate(45) scale(0.85) translate(-20 -20)" opacity="0.035" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(907.8 24.4) rotate(10) scale(0.61) translate(-20 -20)" opacity="0.042" stroke="#B8860B"/>
<use href="#p-triangles" transform="translate(1032.1 65.9) rotate(10) scale(0.99) translate(-20 -20)" opacity="0.046" stroke="#8B5A3C"/>
<use href="#d-check" transform="translate(1136.8 70.4) rotate(90) scale(0.55) translate(-20 -20)" opacity="0.052" stroke="#8B5A3C"/>
<use href="#p-dots" transform="translate(1265.6 60.9) rotate(20) scale(1.12) translate(-20 -20)" opacity="0.035" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(1369.6 56.7) rotate(0) scale(0.59) translate(-20 -20)" opacity="0.031" stroke="#8B5A3C"/>
<use href="#d-phone" transform="translate(86.7 124.8) rotate(-20) scale(1.07) translate(-20 -20)" opacity="0.058" stroke="#8B5A3C"/>
<use href="#d-swap" transform="translate(173.9 141.5) rotate(-20) scale(0.56) translate(-20 -20)" opacity="0.027" stroke="#8B5A3C"/>
<use href="#p-triangles" transform="translate(264.9 169.9) rotate(-45) scale(0.6) translate(-20 -20)" opacity="0.044" stroke="#8B5A3C"/>
<use href="#d-wallet" transform="translate(406.9 127.5) rotate(30) scale(1.13) translate(-20 -20)" opacity="0.054" stroke="#8B5A3C"/>
<use href="#d-coin" transform="translate(561.4 143.5) rotate(30) scale(0.94) translate(-20 -20)" opacity="0.023" stroke="#B8860B"/>
<use href="#d-wallet" transform="translate(639.0 129.7) rotate(-30) scale(0.5) translate(-20 -20)" opacity="0.027" stroke="#B8860B"/>
<use href="#d-envelope" transform="translate(770.2 121.5) rotate(-20) scale(0.94) translate(-20 -20)" opacity="0.062" stroke="#8B5A3C"/>
<use href="#d-check" transform="translate(898.1 126.9) rotate(30) scale(0.84) translate(-20 -20)" opacity="0.024" stroke="#B8860B"/>
<use href="#d-card" transform="translate(1008.7 135.9) rotate(45) scale(0.52) translate(-20 -20)" opacity="0.062" stroke="#8B5A3C"/>
<use href="#p-spiral" transform="translate(1114.6 152.6) rotate(0) scale(1.18) translate(-20 -20)" opacity="0.058" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(1242.8 142.0) rotate(45) scale(0.88) translate(-20 -20)" opacity="0.042" stroke="#8B5A3C"/>
<use href="#d-swap" transform="translate(1388.2 167.3) rotate(-10) scale(1.07) translate(-20 -20)" opacity="0.053" stroke="#8B5A3C"/>
<use href="#p-chevron" transform="translate(61.3 241.3) rotate(0) scale(0.83) translate(-20 -20)" opacity="0.029" stroke="#8B5A3C"/>
<use href="#d-bank" transform="translate(168.8 268.5) rotate(10) scale(0.56) translate(-20 -20)" opacity="0.024" stroke="#8B5A3C"/>
<use href="#d-wallet" transform="translate(288.3 249.0) rotate(30) scale(1.14) translate(-20 -20)" opacity="0.035" stroke="#8B5A3C"/>
<use href="#p-dots" transform="translate(444.1 227.2) rotate(-10) scale(0.83) translate(-20 -20)" opacity="0.028" stroke="#8B5A3C"/>
<use href="#d-envelope" transform="translate(527.9 268.0) rotate(30) scale(0.78) translate(-20 -20)" opacity="0.062" stroke="#8B5A3C"/>
<use href="#p-sun" transform="translate(636.2 227.6) rotate(90) scale(0.6) translate(-20 -20)" opacity="0.056" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(791.3 241.0) rotate(-45) scale(0.51) translate(-20 -20)" opacity="0.063" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(901.9 276.0) rotate(-10) scale(0.52) translate(-20 -20)" opacity="0.029" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(1039.0 239.6) rotate(-45) scale(1.14) translate(-20 -20)" opacity="0.036" stroke="#8B5A3C"/>
<use href="#p-spiral" transform="translate(1146.0 274.3) rotate(-20) scale(0.87) translate(-20 -20)" opacity="0.043" stroke="#B8860B"/>
<use href="#p-diamond" transform="translate(1255.7 231.0) rotate(-20) scale(0.6) translate(-20 -20)" opacity="0.047" stroke="#B8860B"/>
<use href="#p-sun" transform="translate(1348.4 260.9) rotate(-30) scale(1.12) translate(-20 -20)" opacity="0.023" stroke="#8B5A3C"/>
<use href="#d-wallet" transform="translate(27.0 325.9) rotate(-30) scale(0.81) translate(-20 -20)" opacity="0.047" stroke="#8B5A3C"/>
<use href="#d-check" transform="translate(180.9 361.6) rotate(45) scale(1.16) translate(-20 -20)" opacity="0.051" stroke="#8B5A3C"/>
<use href="#d-swap" transform="translate(331.8 335.6) rotate(30) scale(0.6) translate(-20 -20)" opacity="0.025" stroke="#8B5A3C"/>
<use href="#p-dots" transform="translate(389.2 334.4) rotate(0) scale(1.05) translate(-20 -20)" opacity="0.059" stroke="#B8860B"/>
<use href="#p-diamond" transform="translate(555.6 359.6) rotate(30) scale(0.65) translate(-20 -20)" opacity="0.062" stroke="#8B5A3C"/>
<use href="#d-card" transform="translate(659.1 379.4) rotate(20) scale(1.2) translate(-20 -20)" opacity="0.038" stroke="#8B5A3C"/>
<use href="#d-bank" transform="translate(769.7 325.5) rotate(45) scale(0.82) translate(-20 -20)" opacity="0.051" stroke="#8B5A3C"/>
<use href="#d-coin" transform="translate(901.3 337.7) rotate(-10) scale(1.18) translate(-20 -20)" opacity="0.025" stroke="#8B5A3C"/>
<use href="#d-card" transform="translate(986.9 366.7) rotate(20) scale(1.09) translate(-20 -20)" opacity="0.05" stroke="#8B5A3C"/>
<use href="#d-check" transform="translate(1133.2 352.2) rotate(10) scale(0.56) translate(-20 -20)" opacity="0.023" stroke="#8B5A3C"/>
<use href="#d-coin" transform="translate(1254.6 324.3) rotate(0) scale(0.56) translate(-20 -20)" opacity="0.058" stroke="#B8860B"/>
<use href="#d-envelope" transform="translate(1406.1 347.2) rotate(0) scale(0.94) translate(-20 -20)" opacity="0.022" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(91.5 478.2) rotate(-10) scale(1.15) translate(-20 -20)" opacity="0.048" stroke="#8B5A3C"/>
<use href="#p-triangles" transform="translate(158.8 446.7) rotate(10) scale(1.06) translate(-20 -20)" opacity="0.064" stroke="#B8860B"/>
<use href="#d-check" transform="translate(265.3 450.3) rotate(-10) scale(1.15) translate(-20 -20)" opacity="0.025" stroke="#8B5A3C"/>
<use href="#d-envelope" transform="translate(415.1 449.7) rotate(45) scale(0.72) translate(-20 -20)" opacity="0.029" stroke="#8B5A3C"/>
<use href="#d-card" transform="translate(518.3 472.9) rotate(20) scale(1.19) translate(-20 -20)" opacity="0.063" stroke="#8B5A3C"/>
<use href="#d-envelope" transform="translate(625.0 457.5) rotate(-20) scale(0.54) translate(-20 -20)" opacity="0.049" stroke="#8B5A3C"/>
<use href="#p-dots" transform="translate(780.4 478.3) rotate(0) scale(0.53) translate(-20 -20)" opacity="0.028" stroke="#8B5A3C"/>
<use href="#p-spiral" transform="translate(864.3 441.8) rotate(10) scale(0.67) translate(-20 -20)" opacity="0.062" stroke="#8B5A3C"/>
<use href="#p-sun" transform="translate(1009.7 420.1) rotate(0) scale(0.85) translate(-20 -20)" opacity="0.029" stroke="#8B5A3C"/>
<use href="#p-sun" transform="translate(1104.4 435.9) rotate(60) scale(0.53) translate(-20 -20)" opacity="0.021" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(1240.8 455.1) rotate(90) scale(1.12) translate(-20 -20)" opacity="0.054" stroke="#8B5A3C"/>
<use href="#p-triangles" transform="translate(1399.0 463.2) rotate(60) scale(0.95) translate(-20 -20)" opacity="0.022" stroke="#8B5A3C"/>
<use href="#d-card" transform="translate(88.2 557.6) rotate(45) scale(1.03) translate(-20 -20)" opacity="0.045" stroke="#8B5A3C"/>
<use href="#d-swap" transform="translate(145.2 561.2) rotate(-30) scale(0.52) translate(-20 -20)" opacity="0.026" stroke="#8B5A3C"/>
<use href="#d-wallet" transform="translate(271.6 570.1) rotate(90) scale(0.87) translate(-20 -20)" opacity="0.031" stroke="#8B5A3C"/>
<use href="#d-coin" transform="translate(416.9 524.2) rotate(90) scale(0.87) translate(-20 -20)" opacity="0.053" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(562.3 570.8) rotate(-10) scale(1.02) translate(-20 -20)" opacity="0.063" stroke="#8B5A3C"/>
<use href="#d-wallet" transform="translate(651.5 548.7) rotate(60) scale(0.94) translate(-20 -20)" opacity="0.029" stroke="#8B5A3C"/>
<use href="#d-card" transform="translate(767.9 559.1) rotate(-45) scale(0.84) translate(-20 -20)" opacity="0.041" stroke="#8B5A3C"/>
<use href="#d-phone" transform="translate(871.2 533.1) rotate(30) scale(0.83) translate(-20 -20)" opacity="0.054" stroke="#8B5A3C"/>
<use href="#p-sun" transform="translate(1023.5 538.7) rotate(-45) scale(0.7) translate(-20 -20)" opacity="0.023" stroke="#8B5A3C"/>
<use href="#d-swap" transform="translate(1175.6 579.6) rotate(-30) scale(0.91) translate(-20 -20)" opacity="0.026" stroke="#8B5A3C"/>
<use href="#d-phone" transform="translate(1292.6 528.0) rotate(-30) scale(0.99) translate(-20 -20)" opacity="0.03" stroke="#8B5A3C"/>
<use href="#p-sun" transform="translate(1379.0 521.5) rotate(90) scale(0.82) translate(-20 -20)" opacity="0.033" stroke="#B8860B"/>
<use href="#d-wallet" transform="translate(48.8 639.0) rotate(10) scale(1.03) translate(-20 -20)" opacity="0.057" stroke="#B8860B"/>
<use href="#d-phone" transform="translate(210.7 662.8) rotate(0) scale(0.76) translate(-20 -20)" opacity="0.037" stroke="#8B5A3C"/>
<use href="#p-triangles" transform="translate(306.4 641.6) rotate(-45) scale(0.7) translate(-20 -20)" opacity="0.022" stroke="#8B5A3C"/>
<use href="#d-envelope" transform="translate(429.7 628.9) rotate(45) scale(0.72) translate(-20 -20)" opacity="0.054" stroke="#8B5A3C"/>
<use href="#d-envelope" transform="translate(534.8 621.7) rotate(45) scale(0.88) translate(-20 -20)" opacity="0.052" stroke="#B8860B"/>
<use href="#d-phone" transform="translate(676.7 647.1) rotate(30) scale(0.53) translate(-20 -20)" opacity="0.061" stroke="#B8860B"/>
<use href="#p-dots" transform="translate(778.0 640.6) rotate(90) scale(0.68) translate(-20 -20)" opacity="0.049" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(904.1 643.7) rotate(-30) scale(0.65) translate(-20 -20)" opacity="0.06" stroke="#8B5A3C"/>
<use href="#d-check" transform="translate(999.8 674.4) rotate(20) scale(0.6) translate(-20 -20)" opacity="0.028" stroke="#B8860B"/>
<use href="#p-triangles" transform="translate(1128.6 625.5) rotate(60) scale(0.64) translate(-20 -20)" opacity="0.021" stroke="#8B5A3C"/>
<use href="#p-triangles" transform="translate(1251.6 664.8) rotate(10) scale(1.03) translate(-20 -20)" opacity="0.042" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(1369.9 661.2) rotate(-30) scale(0.69) translate(-20 -20)" opacity="0.031" stroke="#8B5A3C"/>
<use href="#d-wallet" transform="translate(56.1 777.2) rotate(-20) scale(0.52) translate(-20 -20)" opacity="0.051" stroke="#8B5A3C"/>
<use href="#p-sun" transform="translate(178.1 755.2) rotate(45) scale(1.1) translate(-20 -20)" opacity="0.063" stroke="#8B5A3C"/>
<use href="#d-coin" transform="translate(271.9 729.3) rotate(90) scale(1.09) translate(-20 -20)" opacity="0.059" stroke="#B8860B"/>
<use href="#p-spiral" transform="translate(439.9 720.1) rotate(-45) scale(0.95) translate(-20 -20)" opacity="0.033" stroke="#B8860B"/>
<use href="#d-coin" transform="translate(522.1 758.2) rotate(-30) scale(0.55) translate(-20 -20)" opacity="0.043" stroke="#8B5A3C"/>
<use href="#d-wallet" transform="translate(651.9 733.4) rotate(45) scale(0.71) translate(-20 -20)" opacity="0.04" stroke="#8B5A3C"/>
<use href="#d-swap" transform="translate(790.4 773.0) rotate(45) scale(0.67) translate(-20 -20)" opacity="0.062" stroke="#8B5A3C"/>
<use href="#d-envelope" transform="translate(886.1 721.3) rotate(-30) scale(0.68) translate(-20 -20)" opacity="0.049" stroke="#8B5A3C"/>
<use href="#d-envelope" transform="translate(1000.3 722.0) rotate(10) scale(0.98) translate(-20 -20)" opacity="0.029" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(1157.2 750.3) rotate(0) scale(1.04) translate(-20 -20)" opacity="0.029" stroke="#8B5A3C"/>
<use href="#p-spiral" transform="translate(1243.1 773.4) rotate(30) scale(0.93) translate(-20 -20)" opacity="0.059" stroke="#8B5A3C"/>
<use href="#d-envelope" transform="translate(1409.5 723.4) rotate(-45) scale(0.65) translate(-20 -20)" opacity="0.063" stroke="#B8860B"/>
<use href="#p-dots" transform="translate(27.7 823.6) rotate(10) scale(1.01) translate(-20 -20)" opacity="0.064" stroke="#8B5A3C"/>
<use href="#d-check" transform="translate(167.7 831.1) rotate(-45) scale(0.72) translate(-20 -20)" opacity="0.052" stroke="#8B5A3C"/>
<use href="#p-chevron" transform="translate(334.9 846.5) rotate(0) scale(0.56) translate(-20 -20)" opacity="0.038" stroke="#8B5A3C"/>
<use href="#p-triangles" transform="translate(424.4 865.5) rotate(20) scale(0.56) translate(-20 -20)" opacity="0.051" stroke="#8B5A3C"/>
<use href="#p-dots" transform="translate(543.0 846.8) rotate(30) scale(0.52) translate(-20 -20)" opacity="0.038" stroke="#8B5A3C"/>
<use href="#p-chevron" transform="translate(679.2 822.4) rotate(-45) scale(0.68) translate(-20 -20)" opacity="0.053" stroke="#8B5A3C"/>
<use href="#d-wallet" transform="translate(768.4 836.3) rotate(0) scale(1.02) translate(-20 -20)" opacity="0.05" stroke="#8B5A3C"/>
<use href="#p-dots" transform="translate(885.4 863.3) rotate(-30) scale(0.52) translate(-20 -20)" opacity="0.03" stroke="#8B5A3C"/>
<use href="#p-triangles" transform="translate(1052.9 877.2) rotate(20) scale(1.07) translate(-20 -20)" opacity="0.026" stroke="#8B5A3C"/>
<use href="#p-dots" transform="translate(1104.6 875.9) rotate(-20) scale(0.93) translate(-20 -20)" opacity="0.034" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(1250.1 866.9) rotate(20) scale(1.03) translate(-20 -20)" opacity="0.031" stroke="#B8860B"/>
<use href="#p-sun" transform="translate(1346.4 853.2) rotate(-30) scale(1.19) translate(-20 -20)" opacity="0.032" stroke="#B8860B"/>
<use href="#d-check" transform="translate(30.9 949.9) rotate(-20) scale(0.66) translate(-20 -20)" opacity="0.038" stroke="#8B5A3C"/>
<use href="#d-coin" transform="translate(192.5 964.9) rotate(0) scale(0.71) translate(-20 -20)" opacity="0.045" stroke="#8B5A3C"/>
<use href="#p-diamond" transform="translate(317.1 932.0) rotate(-10) scale(0.61) translate(-20 -20)" opacity="0.059" stroke="#8B5A3C"/>
<use href="#d-swap" transform="translate(407.5 943.8) rotate(90) scale(1.07) translate(-20 -20)" opacity="0.049" stroke="#8B5A3C"/>
<use href="#d-check" transform="translate(511.4 948.5) rotate(10) scale(0.53) translate(-20 -20)" opacity="0.033" stroke="#B8860B"/>
<use href="#d-coin" transform="translate(637.6 978.4) rotate(10) scale(0.86) translate(-20 -20)" opacity="0.028" stroke="#8B5A3C"/>
<use href="#p-dots" transform="translate(799.8 959.9) rotate(60) scale(1.0) translate(-20 -20)" opacity="0.035" stroke="#B8860B"/>
<use href="#d-wallet" transform="translate(888.5 922.6) rotate(60) scale(1.01) translate(-20 -20)" opacity="0.06" stroke="#8B5A3C"/>
<use href="#d-phone" transform="translate(1043.0 944.5) rotate(-30) scale(0.64) translate(-20 -20)" opacity="0.055" stroke="#8B5A3C"/>
<use href="#d-card" transform="translate(1108.6 926.1) rotate(90) scale(0.87) translate(-20 -20)" opacity="0.049" stroke="#8B5A3C"/>
<use href="#d-envelope" transform="translate(1243.5 979.3) rotate(-45) scale(0.72) translate(-20 -20)" opacity="0.045" stroke="#8B5A3C"/>
<use href="#d-bank" transform="translate(1374.0 971.9) rotate(90) scale(0.64) translate(-20 -20)" opacity="0.052" stroke="#8B5A3C"/>
</g>
</svg>
</div>
<div class="container">
<div class="topbar">
    <div class="logo">VOUCHMORPH<sup>TM</sup></div>
    <div class="topbar-right">
        <span class="greeting">Hello, <span id="userName"><?php echo htmlspecialchars($userName); ?></span></span>
        <span class="role-badge"><?php echo htmlspecialchars(strtoupper($userRole)); ?></span>
        <span class="agent-badge" id="agentBadge" style="display:none;">Agent</span>
        <?php if ($isTestMode): ?><span class="test-mode-badge">Test mode</span><?php endif; ?>
        <select class="country-selector" id="countrySelector" onchange="switchCountry(this.value)">
            <?php foreach ($availableCountries as $country): ?>
            <option value="<?php echo htmlspecialchars($country); ?>" <?php echo $country === $userCountry ? 'selected' : ''; ?>><?php echo htmlspecialchars($country); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="toolbox-btn" onclick="openToolbox()">
            Toolbox
            <span id="toolboxBadge" class="toolbox-badge" style="display:none;"></span>
        </button>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div id="mainMessage" class="message"></div>

<div class="card">
    <div class="swap-columns">
    <div class="section split-box" id="fromSection">
        <div class="section-title"><span class="n">1</span> From</div>

        <div class="type-tabs" id="sourceTypeTabs">
            <button type="button" class="type-tab active" data-cat="WALLET" onclick="selectSourceCategory('WALLET')">Wallet / Account</button>
            <button type="button" class="type-tab" data-cat="CARD" onclick="selectSourceCategory('CARD')">Card</button>
            <button type="button" class="type-tab" data-cat="VOUCHER" onclick="selectSourceCategory('VOUCHER')">Voucher</button>
        </div>

        <!-- WALLET / ACCOUNT: saved sources, or a prompt to add one -->
        <div id="walletPanel" class="source-panel">
            <div id="savedSourcesContainer" style="margin-bottom:12px;display:none;">
                <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
                    <div id="savedSourcesChips" style="display:inline-flex;flex-wrap:wrap;gap:6px;"></div>
                    <span class="quick-link muted" onclick="clearSourceSelection()" style="font-size:10px;padding:4px 10px;display:none;" id="clearSourceBtn">Clear</span>
                </div>
                <div style="font-size:10px;color:var(--text-dim);margin-top:6px;">Tap a saved source to auto-fill it, then just enter the amount. <span class="quick-link muted" style="padding:3px 9px;font-size:10px;" onclick="openAddSource()">+ Add another</span></div>
            </div>
            <div id="noSourcesPrompt" class="empty-source-box" style="display:none;">
                <p>No linked wallet or account yet.</p>
                <button class="btn btn-primary btn-sm" onclick="openAddSource()">Add wallet or account</button>
            </div>
        </div>

        <!-- CARD / VOUCHER: institution + dynamic fields entered fresh each time -->
        <div id="instAssetPanel" class="source-panel" style="display:none;">
            <div class="field-group">
                <label>Institution</label>
                <select id="fromInstSelect" onchange="selectFromInst(this.value)"><option value="">Select institution</option></select>
            </div>
        </div>

        <div class="asset-fields" id="fromFields" style="display:none;"></div>

        <div class="field-group amount-field">
            <label>Amount</label>
            <input type="number" id="fromAmount" placeholder="0.00" step="0.01" min="0.01">
            <span class="currency-suffix" id="fromCurrencyLabel"><?php echo htmlspecialchars($userCurrency); ?></span>
            <div class="help" id="fromLimitsHelp"></div>
            <div class="help" id="fromCurrencyInfo" style="font-size:11px;color:var(--text-dim);margin-top:2px;"></div>
            <div class="help" id="sourceSelectedHelp" style="font-size:11px;color:var(--primary-dark);margin-top:2px;display:none;"></div>
        </div>
    </div>
    <div class="swap-divider"><span>&#8645;</span></div>
    <div class="section split-box" id="toSection">
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
            <span class="quick-link" onclick="quickSetSwapType('IDENTITY')">Swap to identity</span>
            <span class="quick-link" onclick="quickSetSwapType('MULTI_SOURCE')">Multi-source</span>
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
                    <option value="ATM">ATM</option>
                    <option value="AGENT">Agent</option>
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
            <span class="quick-link" onclick="addMultiSourceRow()">+ Add another source</span>
            <div class="multi-total">Total requested: <span class="amt" id="multiTotal"><?php echo $userCurrency; ?> 0.00</span></div>
        </div>
    </div>
    </div>
    <div class="cta-row">
        <button class="btn btn-primary" id="reviewBtn" onclick="previewSwap()" disabled>Review Swap &rarr;</button>
    </div>
</div>

<div class="page-footer">
    <p>VOUCHMORPH connects wallets, bank accounts, cards and vouchers across participating institutions, so a swap started in one place can be claimed in another — by an account, a phone number, or a national ID — in minutes.</p>
    <div class="footer-links">
        <span onclick="openHelpModal()">Help</span>
        <span onclick="openTermsModal()">Terms &amp; conditions</span>
        <span onclick="openMySources()">My sources</span>
    </div>
    <div class="footer-mark">VOUCHMORPH<sup>TM</sup> &middot; <?php echo htmlspecialchars($userCountry); ?></div>
</div>
</div>

<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" id="modalContent">
        <div class="modal-header">
            <h2 id="modalTitle">Swap Preview</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
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
    fromCategory: 'WALLET', fromInst: null, fromAsset: null, fromFields: {}, fromAmount: 0,
    swapType: 'DEPOSIT', toInst: null, toAsset: null, toFields: {},
    deliveryMethod: 'ATM', beneficiaryPhone: '',
    toIdentityType: 'national_id', toIdentityValue: '', toIdentitySms: '',
    multiSources: [], lastPreview: null, swapPayload: null,
};
let savedIdentities = [];
let userSources = [];
let agentSearchResult = null;
let agentSearchData = null;
let selectedSourceId = null;
let pendingClaims = [];
let pendingSources = [];
let agentStatus = { is_agent: false, approved_destinations: [], all_destinations: [] };

function getInstitutionCurrency(instCode) {
    if (!instCode) return CONFIG.CURRENCY;
    return PARTICIPANTS[instCode]?.limits?.currency || CONFIG.CURRENCY;
}
function updateCurrencyDisplay() {
    const fromCurrency = getInstitutionCurrency(state.fromInst);
    const fromLabel = document.getElementById('fromCurrencyLabel');
    if (fromLabel) fromLabel.textContent = fromCurrency;
    const fromInfo = document.getElementById('fromCurrencyInfo');
    if (fromInfo) fromInfo.textContent = state.fromInst ? `Source currency: ${fromCurrency}` : '';
}
function switchCountry(country) {
    if (country !== CONFIG.COUNTRY_CODE) window.location.href = '?country=' + encodeURIComponent(country);
}

// ============================================================
// SOURCE CATEGORY (Wallet/Account, Card, Voucher)
// ============================================================

function selectSourceCategory(cat) {
    state.fromCategory = cat;
    state.fromInst = null; state.fromAsset = null; state.fromFields = {};
    selectedSourceId = null;

    document.querySelectorAll('.type-tab').forEach(b => b.classList.toggle('active', b.dataset.cat === cat));

    const walletPanel = document.getElementById('walletPanel');
    const instAssetPanel = document.getElementById('instAssetPanel');
    const fieldsBox = document.getElementById('fromFields');
    const helpEl = document.getElementById('sourceSelectedHelp');

    fieldsBox.innerHTML = ''; fieldsBox.style.display = 'none';
    if (helpEl) helpEl.style.display = 'none';

    if (cat === 'WALLET') {
        walletPanel.style.display = 'block';
        instAssetPanel.style.display = 'none';
        renderSavedSourceChips();
    } else {
        walletPanel.style.display = 'none';
        instAssetPanel.style.display = 'block';
        state.fromAsset = cat;
        populateInstitutionsForAsset(cat);
        document.getElementById('fromInstSelect').value = '';
    }
    updateCurrencyDisplay();
    refreshUI();
}

function populateInstitutionsForAsset(assetType) {
    const sel = document.getElementById('fromInstSelect');
    const codes = Object.keys(PARTICIPANTS).filter(code =>
        (PARTICIPANTS[code].asset_types || []).map(t => String(t).toUpperCase()).includes(assetType)
    );
    if (codes.length === 0) {
        sel.innerHTML = `<option value="">No institutions support ${assetType.toLowerCase()} right now</option>`;
        return;
    }
    sel.innerHTML = '<option value="">Select institution</option>' + codes.map(code =>
        `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`
    ).join('');
}

document.addEventListener('DOMContentLoaded', function() {
    const toSelect = document.getElementById('toInstSelect');
    if (!toSelect) { showMessage('Dashboard initialization error. Please refresh.', 'error'); return; }
    const instOptions = Object.keys(PARTICIPANTS);
    if (instOptions.length === 0) {
        showMessage('No institutions found for this country.', 'error');
        return;
    }
    toSelect.innerHTML = '<option value="">Select institution</option>';
    instOptions.forEach(code => {
        const name = PARTICIPANTS[code]?.name || code;
        toSelect.insertAdjacentHTML('beforeend', `<option value="${code}">${name}</option>`);
    });
    updateCurrencyDisplay();
    document.getElementById('fromAmount').addEventListener('input', function() {
        state.fromAmount = parseFloat(this.value) || 0;
        refreshUI();
    });
    selectSourceCategory('WALLET');
    checkPendingClaims();
    loadAgentStatus();
    loadUserSources();
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
        response = await fetch(url, { 
            method: 'POST', 
            headers: buildHeaders(), 
            body: JSON.stringify(payload),
            credentials: 'include'  // ← ADD THIS - sends session cookie
        });
    } catch (networkErr) {
        return { ok: false, error: 'Network error: could not reach ' + url + ' (' + networkErr.message + ')' };
    }
    try { body = await response.json(); } catch (parseErr) {
        return { ok: false, error: 'Server returned a non-JSON response (HTTP ' + response.status + ')' };
    }
    if (!response.ok || body.success === false) return { ok: false, error: body.error || ('HTTP ' + response.status), body };
    return { ok: true, body };
}

// ============================================================
// FROM SIDE: institution + fields (used by Card/Voucher, and by
// the Wallet/Account "saved source" auto-fill)
// ============================================================

function selectFromInst(code) {
    state.fromInst = code || null;
    state.fromFields = {};
    const box = document.getElementById('fromFields');
    if (!code) { box.innerHTML = ''; box.style.display = 'none'; updateCurrencyDisplay(); refreshUI(); return; }
    const inst = PARTICIPANTS[code];
    if (!inst) { showMessage('Institution not found: ' + code, 'error'); return; }
    document.getElementById('fromLimitsHelp').textContent = inst.limits ? `Limits: ${inst.limits.min_amount} – ${inst.limits.max_amount} ${inst.limits.currency}` : '';
    box.style.display = 'block';
    renderDynamicFields('fromFields', state.fromAsset, 'fromField_', updateFromField, true);
    updateCurrencyDisplay();
    refreshUI();
}
function updateFromField(name, value) { state.fromFields[name] = value; refreshUI(); }
function assetHasAmountField(assetType) { return (getAssetConfig(assetType)?.fields || []).some(f => f.name === 'amount'); }
function renderDynamicFields(containerId, assetType, prefix, onChange, includePin) {
    const container = document.getElementById(containerId);
    const config = getAssetConfig(assetType);
    if (!config) {
        container.innerHTML = `<div class="help" style="color:var(--danger);">Unknown asset type: ${assetType}</div>`;
        return;
    }
    
    // Get fields, filter out amount if needed, and handle PIN filtering
    let fields = config.fields || [];
    if (!includePin) {
        fields = fields.filter(f => f.vault_field !== 'pin');
    }
    fields = fields.filter(f => f.name !== 'amount'); // Amount is handled separately
    
    if (!fields || fields.length === 0) {
        container.innerHTML = '';
        return;
    }
    
    container.innerHTML = fields.map(f => {
        const attrs = [];
        if (f.pattern) attrs.push(`pattern="${f.pattern}"`);
        if (f.min_length) attrs.push(`minlength="${f.min_length}"`);
        if (f.max_length) attrs.push(`maxlength="${f.max_length}"`);
        if (f.required) attrs.push('required');
        if (f.min !== undefined) attrs.push(`min="${f.min}"`);
        if (f.max !== undefined) attrs.push(`max="${f.max}"`);
        
        // For select fields
        if (f.type === 'select' && f.options) {
            const optionsHtml = f.options.map(opt => 
                `<option value="${opt}">${opt}</option>`
            ).join('');
            return `<div class="field-group">
                <label>${f.label} ${f.required ? '*' : ''}</label>
                <select id="${prefix}${f.name}" onchange="window['${onChange.name}']('${f.name}', this.value)">
                    <option value="">${f.placeholder || 'Select'}</option>
                    ${optionsHtml}
                </select>
                ${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}
            </div>`;
        }
        
        // For password fields
        const inputType = f.vault_field === 'pin' || f.name.includes('pin') || f.name === 'cvv' ? 'password' : (f.type || 'text');
        
        return `<div class="field-group">
            <label>${f.label} ${f.required ? '*' : ''}</label>
            <input type="${inputType}" 
                   id="${prefix}${f.name}" 
                   placeholder="${f.placeholder || ''}" 
                   ${attrs.join(' ')} 
                   oninput="window['${onChange.name}']('${f.name}', this.value)">
            ${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}
        </div>`;
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
        sel.innerHTML = '<option value="">Select asset type</option>' + assetTypes.map(t => `<option value="${t}">${getAssetConfig(t)?.label || t}</option>`).join('');
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
function refreshUI() {
    document.getElementById('reviewBtn').disabled = !isSwapReady();
    updateToolboxBadge();
}
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
            <button class="btn btn-primary" onclick="confirmSwap()" style="flex:1;">Confirm &amp; execute</button>
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
        inner = `<div class="icon">&#9679;</div><div style="font-size:18px;font-weight:700;">Cashout Code Generated</div><div style="color:var(--text-muted);">Reference: ${escapeHtml(reference)}</div>${data.atm_code || data.voucher_number ? `<div class="atm-code"><div class="code">${escapeHtml(data.atm_code || data.voucher_number || '')}</div></div>` : ''}<div style="margin-top:12px;"><div style="font-size:24px;font-weight:700;">${data.amount ?? state.swapPayload.amount} ${state.swapPayload.currency || CONFIG.CURRENCY}</div></div>`;
    } else if (swapType === 'IDENTITY') {
        const claimPinBox = data.claim_pin ? `
            <div class="atm-code" style="margin-top:12px;">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Backup PIN — only share this if the recipient doesn't get the SMS</div>
                <div class="code">${escapeHtml(data.claim_pin)}</div>
            </div>` : '';
        inner = `<div class="icon">&#9679;</div><div style="font-size:18px;font-weight:700;">Identity Swap Initiated</div><div style="color:var(--text-muted);">Reference: ${escapeHtml(reference)}</div><div style="margin:12px 0;"><strong>${escapeHtml(data.identity_type || state.swapPayload.identity_type)}: ${escapeHtml(data.identity_value || state.swapPayload.identity_value)}</strong></div><div style="font-size:24px;font-weight:700;">${data.amount ?? state.swapPayload.amount} ${data.currency || CONFIG.CURRENCY}</div>${claimPinBox}`;
    } else {
        inner = `<div class="icon">&#9679;</div><div style="font-size:18px;font-weight:700;">Swap Completed</div><div style="color:var(--text-muted);">Reference: ${escapeHtml(reference)}</div><div style="font-size:24px;font-weight:700;margin-top:12px;">${data.amount ?? state.swapPayload.amount} ${CONFIG.CURRENCY}</div>`;
    }
    openModal('Swap Result', `<div class="result-box">${inner}<div class="cta-row"><button class="btn btn-primary" onclick="closeModal(); location.reload();">Done</button></div></div>`);
}

const IDENTITY_TYPE_LABELS = { national_id: 'National ID', birth_certificate: 'Birth Certificate', voter_id: 'Voter ID', phone: 'Phone Number', email: 'Email' };

// ============================================================
// WALLET/ACCOUNT SAVED SOURCES
// ============================================================

async function loadUserSources() {
    if (!CONFIG.USER_ID) return;
    
    // Load active sources
    const result = await callApi(CONFIG.API_BASE + '/user/sources.php', {});
    if (result.ok) {
        userSources = result.body.data?.sources || [];
        if (state.fromCategory === 'WALLET') renderSavedSourceChips();
    }
    
    // Load pending sources for the badge count
    const pendingResult = await callApi(CONFIG.API_BASE + '/api/v1/sources/pending.php', {});
    if (pendingResult.ok) {
        pendingSources = pendingResult.body.data || [];
        updateToolboxBadge();
    }
}

function walletEligibleSources() {
    return userSources.filter(s => s.status === 'active' && ['WALLET', 'ACCOUNT'].includes(String(s.asset_type).toUpperCase()));
}

function renderSavedSourceChips() {
    const container = document.getElementById('savedSourcesContainer');
    const chipsContainer = document.getElementById('savedSourcesChips');
    const clearBtn = document.getElementById('clearSourceBtn');
    const emptyPrompt = document.getElementById('noSourcesPrompt');
    if (!container || !chipsContainer) return;

    const activeSources = walletEligibleSources();

    if (activeSources.length === 0) {
        container.style.display = 'none';
        emptyPrompt.style.display = 'block';
        return;
    }
    emptyPrompt.style.display = 'none';
    container.style.display = 'block';

    chipsContainer.innerHTML = activeSources.map(source => {
        const instName = PARTICIPANTS[source.institution]?.name || source.institution;
        const identifier = source.identifier || source.source_identifier || '';
        const shortId = identifier.length > 15 ? identifier.substring(0, 12) + '…' : identifier;
        const isSelected = selectedSourceId === source.id;
        return `
            <span class="source-chip ${isSelected ? 'active' : ''}"
                  onclick="selectSavedSource(${source.id})"
                  title="${escapeHtml(instName)} - ${escapeHtml(identifier)}">
                <span>${escapeHtml(instName)}</span>
                <span class="chip-identifier">${escapeHtml(shortId)}</span>
            </span>
        `;
    }).join('');

    clearBtn.style.display = selectedSourceId ? 'inline-flex' : 'none';
}

function selectSavedSource(sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    if (!source) { showMessage('Source not found.', 'error'); return; }

    selectedSourceId = sourceId;
    state.fromInst = source.institution;
    state.fromAsset = source.asset_type;
    state.fromFields = {};

    const inst = PARTICIPANTS[source.institution];
    document.getElementById('fromLimitsHelp').textContent = inst?.limits ? `Limits: ${inst.limits.min_amount} – ${inst.limits.max_amount} ${inst.limits.currency}` : '';

    const fieldsBox = document.getElementById('fromFields');
    fieldsBox.style.display = 'block';
    
    // Render fields - use setTimeout to ensure DOM is ready
    renderDynamicFields('fromFields', source.asset_type, 'fromField_', updateFromField, true);
    
    // Use a longer delay to ensure all DOM elements are created
    setTimeout(() => {
        fillSourceIdentifierFields(source);
        // Verify the field was filled
        const assetConfig = getAssetConfig(source.asset_type);
        if (assetConfig) {
            const idField = assetConfig.fields?.find(f => 
                f.vault_field !== 'pin' && 
                f.name !== 'amount'
            );
            if (idField) {
                const input = document.getElementById(`fromField_${idField.name}`);
                if (input && !input.value) {
                    console.warn('Field still empty, trying direct fill:', idField.name);
                    input.value = source.identifier || source.source_identifier || '';
                    updateFromField(idField.name, input.value);
                }
            }
        }
    }, 100);

    const helpEl = document.getElementById('sourceSelectedHelp');
    if (helpEl) {
        helpEl.style.display = 'block';
        helpEl.textContent = `Source selected: ${inst?.name || source.institution} — ${source.identifier || ''}. Enter the amount below.`;
    }

    setTimeout(() => {
        document.getElementById('fromAmount')?.focus();
        showMessage(`Source selected: ${inst?.name || source.institution}`, 'success');
    }, 300);

    updateCurrencyDisplay();
    renderSavedSourceChips();
    refreshUI();
}

function fillSourceIdentifierFields(source) {
    const assetConfig = ASSETS[source.asset_type];
    if (!assetConfig) {
        console.warn('No asset config for type:', source.asset_type);
        return;
    }
    
    const fields = assetConfig.fields || [];

    // Find the identifier field - match against ALL possible field names from assets.yaml
    const identifierField = fields.find(f =>
        f.vault_field !== 'pin' &&
        f.name !== 'amount' &&
        (f.name === 'account_number' ||  // ACCOUNT type
         f.name === 'identifier' ||      // Generic
         f.name === 'account' || 
         f.name === 'phone_number' ||    // MNO-WALLET type
         f.name === 'phone' ||           // VOUCHER type
         f.name === 'card_number' ||     // CARD type
         f.name === 'wallet_account' ||  // BANK-WALLET type
         f.name === 'wallet_address' ||  // CRYPTO type
         f.name === 'order_number' ||    // POSTAL-ORDER type
         f.name === 'cheque_number' ||   // CHEQUE type
         f.name === 'atm_code' ||        // ATM type
         f.name === 'voucher_number' ||  // VOUCHER type
         f.name === 'source_identifier' ||
         f.name === 'wallet_id')
    );
    
    const pinField = fields.find(f => f.vault_field === 'pin');

    // Remove disabled state from all inputs first
    document.querySelectorAll('#fromFields input[disabled]').forEach(input => {
        input.disabled = false;
        input.style.background = '#fff';
        input.style.color = 'var(--text)';
    });

    // Fill identifier field
    if (identifierField) {
        const input = document.getElementById(`fromField_${identifierField.name}`);
        if (input) {
            const identifier = source.identifier || source.source_identifier || '';
            input.value = identifier;
            updateFromField(identifierField.name, identifier);
            input.disabled = true;
            input.style.background = 'var(--surface)';
            input.style.color = 'var(--text-dim)';
            
            // Add help text
            let helpText = input.parentElement?.querySelector('.help');
            if (helpText) { 
                helpText.textContent = 'Auto-filled from your saved source'; 
                helpText.style.color = 'var(--primary-dark)';
            }
        } else {
            console.warn('Identifier input not found:', `fromField_${identifierField.name}`);
        }
    }

    // Fill PIN field if saved
    if (pinField && source.pin) {
        const pinInput = document.getElementById(`fromField_${pinField.name}`);
        if (pinInput) {
            pinInput.value = source.pin;
            updateFromField(pinField.name, source.pin);
            pinInput.disabled = true;
            pinInput.style.background = 'var(--surface)';
            pinInput.style.color = 'var(--text-dim)';
        }
    }
    
    // Fallback: If no specific identifier field was found but we have source_identifier
    if (!identifierField && source.source_identifier) {
        // Try to find a generic text/tel/number field that's not the PIN
        const genericField = fields.find(f => 
            f.vault_field !== 'pin' && 
            f.name !== 'amount' && 
            (f.type === 'text' || f.type === 'tel' || f.type === 'number') &&
            !f.name.includes('pin') &&
            !f.name.includes('cvv')
        );
        if (genericField) {
            const input = document.getElementById(`fromField_${genericField.name}`);
            if (input) {
                input.value = source.source_identifier;
                updateFromField(genericField.name, source.source_identifier);
                input.disabled = true;
                input.style.background = 'var(--surface)';
                input.style.color = 'var(--text-dim)';
            }
        }
    }

    refreshUI();
}

function clearSourceSelection() {
    selectedSourceId = null;
    state.fromInst = null; state.fromAsset = null; state.fromFields = {};

    document.getElementById('fromFields').innerHTML = '';
    document.getElementById('fromFields').style.display = 'none';

    const helpEl = document.getElementById('sourceSelectedHelp');
    if (helpEl) helpEl.style.display = 'none';

    const amountField = document.getElementById('fromAmount');
    if (amountField) { amountField.value = ''; amountField.focus(); }

    showMessage('Source selection cleared.', 'info');
    updateCurrencyDisplay();
    renderSavedSourceChips();
    refreshUI();
}

function openMySources() {
    openModal('My sources', renderMySources());
}
function renderMySources() {
    if (userSources.length === 0) {
        return `
            <div style="text-align:center;padding:20px;">
                <div style="font-weight:700;margin:8px 0;">No sources added yet</div>
                <div style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Link your bank accounts, wallets, or cards to use them as swap sources.</div>
                <button class="btn btn-primary" onclick="openAddSource()">+ Add Source</button>
            </div>
        `;
    }
    const sourceList = userSources.map(source => {
        const statusClass = source.status === 'active' ? 'active' : source.status === 'pending_confirmation' ? 'pending' : 'inactive';
        const statusLabel = source.status === 'active' ? 'Active' : source.status === 'pending_confirmation' ? 'Pending' : 'Inactive';
        const isActive = source.status === 'active';
        const assetLabel = ASSETS[source.asset_type]?.label || source.asset_type;
        return `
            <div class="source-card">
                <div class="source-header">
                    <div class="source-institution">${escapeHtml(PARTICIPANTS[source.institution]?.name || source.institution)}</div>
                    <div>
                        <span class="source-status ${statusClass}">${statusLabel}</span>
                        ${isActive ? `<button class="btn-primary btn-sm" onclick="useSourceForSwap(${source.id})" style="margin-left:8px;">Use</button>` : ''}
                        ${isActive ? `<button class="btn-danger-outline" onclick="removeSource(${source.id})" style="margin-left:4px;">Remove</button>` : ''}
                    </div>
                </div>
                <div class="source-details">
                    ${assetLabel} · ${escapeHtml(source.identifier || source.source_identifier)}
                    ${source.account_name ? ` · ${escapeHtml(source.account_name)}` : ''}
                    ${source.currency ? ` · ${source.currency}` : ''}
                    ${source.confirmed_at ? ` · Confirmed: ${new Date(source.confirmed_at).toLocaleDateString()}` : ''}
                </div>
            </div>
        `;
    }).join('');
    return `
        <div style="margin-bottom:16px;">
            <button class="btn btn-primary btn-sm" onclick="openAddSource()">+ Add New Source</button>
            <span style="font-size:12px;color:var(--text-muted);margin-left:12px;">${userSources.length} source(s) linked</span>
        </div>
        <div>${sourceList}</div>
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);font-size:11px;color:var(--text-dim);">
            You can add Accounts, Wallets, or Cards here. Vouchers and Bank-Wallets are added manually by admin.
            <br>Click <strong>Use</strong> on any active source to auto-fill the swap form.
        </div>
    `;
}
function useSourceForSwap(sourceId) {
    closeModal();
    selectSourceCategory('WALLET');
    setTimeout(() => { selectSavedSource(sourceId); }, 200);
}

function openAddSource() {
    const instOptions = Object.keys(PARTICIPANTS).map(code =>
        `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`
    ).join('');
    const body = `
        <div style="margin-bottom:16px;">
            <div style="font-size:14px;font-weight:700;margin-bottom:4px;">Link a new source</div>
            <div style="font-size:12px;color:var(--text-muted);">Your bank will verify ownership via OTP or OAuth.</div>
        </div>
        <div class="field-group">
            <label>Institution</label>
            <select id="addSourceInst" onchange="onAddSourceInstChange(this.value)">
                <option value="">Select institution</option>
                ${instOptions}
            </select>
        </div>
        <div class="field-group" id="addSourceAssetGroup" style="display:none;">
            <label>Asset Type</label>
            <select id="addSourceAssetType"></select>
            <div class="help">Users can only add Accounts, Wallets, or Cards.</div>
        </div>
        <div class="field-group">
            <label>Identifier</label>
            <input id="addSourceIdentifier" placeholder="Account number, phone, or card number">
            <div class="help">The number that identifies your account at this institution.</div>
        </div>
        <div class="field-group">
            <label>Account Name (optional)</label>
            <input id="addSourceAccountName" placeholder="e.g. My Main Account">
        </div>
        <div id="addSourceOtpFields" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
            <div style="font-size:13px;font-weight:700;margin-bottom:8px;">Verify with OTP</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;" id="otpMessage">A verification code has been sent to your registered phone.</div>
            <div class="otp-input-group">
                <input type="text" id="addSourceOtp" placeholder="Enter code" inputmode="numeric" maxlength="8">
                <button class="btn btn-primary btn-sm" onclick="completeSourceOtp()">Verify</button>
            </div>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="openMySources()">Cancel</button>
            <button class="btn btn-primary" id="addSourceSubmitBtn" onclick="submitAddSource()">Link source</button>
        </div>
    `;
    openModal('Add Source', body);
}

let addSourceState = { attemptId: null, requiresOtp: false, requiresRedirect: false, redirectUrl: null, institution: null, assetType: null, identifier: null };

function onAddSourceInstChange(code) {
    const group = document.getElementById('addSourceAssetGroup');
    const sel = document.getElementById('addSourceAssetType');
    if (!code) { group.style.display = 'none'; sel.innerHTML = ''; return; }
    // Hardcoded rather than filtered from PARTICIPANTS[code].asset_types --
    // that config list is per-institution and has been found incomplete
    // (e.g. an institution supporting WALLET but missing it from its
    // asset_types entry). Account/Wallet/Card are always offered as
    // addable source types; the bank adapter itself will reject the
    // combination server-side if a given institution genuinely doesn't
    // support one of them.
    const eligibleTypes = ['ACCOUNT', 'WALLET', 'CARD'];
    sel.innerHTML = eligibleTypes.map(t => `<option value="${t}">${getAssetConfig(t)?.label || t}</option>`).join('');
    group.style.display = 'block';
}

async function submitAddSource() {
    const institution = document.getElementById('addSourceInst').value;
    const assetType = document.getElementById('addSourceAssetType').value;
    const identifier = document.getElementById('addSourceIdentifier').value.trim();
    const accountName = document.getElementById('addSourceAccountName').value.trim();

    if (!institution) { showMessage('Select an institution.', 'warning'); return; }
    if (!assetType) { showMessage('Select an asset type.', 'warning'); return; }
    if (!identifier) { showMessage('Enter your account identifier.', 'warning'); return; }

    const btn = document.getElementById('addSourceSubmitBtn');
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Registering...';

    const result = await callApi(CONFIG.API_BASE + '/user/add_source.php', {
        institution: institution, asset_type: assetType, identifier: identifier, account_name: accountName || undefined
    });

    btn.disabled = false;
    btn.textContent = original;

    if (!result.ok) { showMessage('Failed to add source: ' + result.error, 'error'); return; }

    const data = result.body.data || {};
    addSourceState.attemptId = data.attempt_id || null;
    addSourceState.requiresOtp = data.requires_otp || false;
    addSourceState.requiresRedirect = data.requires_redirect || false;
    addSourceState.redirectUrl = data.redirect_url || null;
    addSourceState.institution = institution;
    addSourceState.assetType = assetType;
    addSourceState.identifier = identifier;

    if (data.requires_redirect) {
        showMessage('Redirecting to your bank for verification...', 'info');
        setTimeout(() => { window.location.href = data.redirect_url; }, 1500);
        return;
    }
    if (data.requires_otp) {
        document.getElementById('addSourceOtpFields').style.display = 'block';
        document.getElementById('otpMessage').textContent = data.message || 'A verification code has been sent to your registered phone.';
        document.getElementById('addSourceSubmitBtn').style.display = 'none';
        showMessage('OTP sent! Enter the code to verify.', 'success');
        return;
    }
    showMessage(data.message || 'Source added!', 'success');
    setTimeout(() => { loadUserSources(); openMySources(); }, 1500);
}

async function completeSourceOtp() {
    const otp = document.getElementById('addSourceOtp').value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    if (!addSourceState.attemptId) { showMessage('No pending verification attempt.', 'error'); return; }

    const btn = document.querySelector('#addSourceOtpFields .btn-primary');
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Verifying...';

    // ✅ FIX: Include user_id in the request
    const result = await callApi(CONFIG.API_BASE + '/user/verify_source.php', { 
        attempt_id: addSourceState.attemptId, 
        otp: otp,
        user_id: CONFIG.USER_ID  // ← ADD THIS
    });

    btn.disabled = false;
    btn.textContent = original;

    if (!result.ok) { 
        showMessage('Verification failed: ' + result.error, 'error'); 
        return; 
    }
    showMessage('Source verified and activated!', 'success');
    setTimeout(() => { loadUserSources(); openMySources(); }, 1500);
}

async function removeSource(sourceId) {
    if (!confirm('Remove this source? You can add it again later.')) return;
    const result = await callApi(CONFIG.API_BASE + '/user/sources/delete.php', { source_id: sourceId });
    if (!result.ok) { showMessage('Failed to remove source: ' + result.error, 'error'); return; }
    showMessage('Source removed.', 'success');
    loadUserSources();
    openMySources();
}

// ============================================================
// PENDING SOURCES MANAGEMENT - View, Delete, Retry
// ============================================================

/**
 * Open the pending sources modal
 */
function openPendingSources() {
    openModal('Pending Sources', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading pending sources...</div>');
    loadPendingSources();
}

/**
 * Load pending sources from the API
 */
async function loadPendingSources() {
    const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/pending.php', {});
    
    if (!result.ok) {
        document.getElementById('modalBody').innerHTML = `
            <div style="text-align:center;padding:20px;color:var(--danger);">
                <div style="font-weight:700;">Failed to load pending sources</div>
                <div style="font-size:13px;color:var(--text-muted);margin-top:8px;">${escapeHtml(result.error)}</div>
                <button class="btn btn-primary btn-sm" onclick="loadPendingSources()" style="margin-top:12px;">Retry</button>
            </div>
        `;
        return;
    }

    pendingSources = result.body.data || [];
    renderPendingSources();
}

/**
 * Render the pending sources list
 */
function renderPendingSources() {
    const container = document.getElementById('modalBody');
    
    if (!pendingSources || pendingSources.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <div style="font-size:40px;margin-bottom:12px;">✓</div>
                <div style="font-weight:700;font-size:18px;">No pending sources</div>
                <div style="font-size:13px;margin-top:8px;">All your sources are active and verified.</div>
            </div>
        `;
        return;
    }

    let html = `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">
            ${pendingSources.length} source(s) pending verification. 
            Pending sources expire after 3 minutes if not completed.
        </div>
        <div style="max-height:60vh;overflow-y:auto;">
    `;

    pendingSources.forEach((source, index) => {
        const statusLabel = getSourceStatusLabel(source);
        const statusClass = getSourceStatusClass(source);
        const sourceTypeLabel = getSourceTypeLabel(source);
        const icon = getSourceIcon(source);
        const canDelete = ['pending_confirmation', 'pending', 'proposed', 'otp_pending', 'oauth_pending'].includes(source.status);
        const canRetry = ['cancelled', 'rejected', 'failed'].includes(source.status);
        const isExpiring = source.is_expiring || false;

        html += `
            <div class="source-card" style="border-left: 3px solid ${isExpiring ? 'var(--warning)' : 'var(--border)'};">
                <div class="source-header">
                    <div>
                        <div class="source-institution">
                            <span style="margin-right:8px;">${icon}</span>
                            ${escapeHtml(source.institution_name || source.institution)}
                            <span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:6px;">
                                (${sourceTypeLabel})
                            </span>
                        </div>
                        <div class="source-details" style="margin-top:4px;">
                            <span style="font-weight:600;">${escapeHtml(source.identifier)}</span>
                            ${source.account_name ? ` · ${escapeHtml(source.account_name)}` : ''}
                            ${source.asset_type ? ` · ${source.asset_type}` : ''}
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span class="source-status ${statusClass}">${statusLabel}</span>
                        ${isExpiring ? `<span style="font-size:10px;color:var(--warning);font-weight:700;">Expiring soon</span>` : ''}
                        ${canDelete ? `<button class="btn-danger-outline" onclick="deletePendingSource('${source.type}', ${source.id})" style="font-size:10px;padding:4px 10px;">Delete</button>` : ''}
                        ${canRetry ? `<button class="btn-primary btn-sm" onclick="retryPendingSource('${source.type}', ${source.id})" style="font-size:10px;padding:4px 10px;">Retry</button>` : ''}
                    </div>
                </div>
                <div style="font-size:11px;color:var(--text-dim);margin-top:6px;">
                    ${source.created_at ? `Added: ${new Date(source.created_at).toLocaleString()}` : ''}
                    ${source.otp_expires_at ? ` · OTP expires: ${new Date(source.otp_expires_at).toLocaleString()}` : ''}
                    ${source.expires_at ? ` · Expires: ${new Date(source.expires_at).toLocaleString()}` : ''}
                </div>
                ${source.error_message ? `<div style="font-size:11px;color:var(--danger);margin-top:4px;">Error: ${escapeHtml(source.error_message)}</div>` : ''}
            </div>
        `;
    });

    html += `</div>`;
    container.innerHTML = html;
}

/**
 * Get status label for display
 */
function getSourceStatusLabel(source) {
    const statusMap = {
        'pending_confirmation': 'Pending Confirmation',
        'pending': 'Pending',
        'proposed': 'Proposed',
        'otp_pending': 'OTP Verification',
        'oauth_pending': 'OAuth Verification',
        'cancelled': 'Cancelled',
        'rejected': 'Rejected',
        'failed': 'Failed',
        'expired': 'Expired'
    };
    return statusMap[source.status] || source.status;
}

/**
 * Get status class for styling
 */
function getSourceStatusClass(source) {
    const classMap = {
        'pending_confirmation': 'pending',
        'pending': 'pending',
        'proposed': 'pending',
        'otp_pending': 'pending',
        'oauth_pending': 'pending',
        'cancelled': 'inactive',
        'rejected': 'inactive',
        'failed': 'inactive',
        'expired': 'inactive'
    };
    return classMap[source.status] || 'pending';
}

/**
 * Get human-readable source type
 */
function getSourceTypeLabel(source) {
    const typeMap = {
        'user_source': 'Source',
        'agent_destination': 'Agent Destination',
        'registration_attempt': 'Verification Attempt',
        'agent_attempt': 'Agent Verification'
    };
    return typeMap[source.type] || source.type;
}

/**
 * Get icon for source type
 */
function getSourceIcon(source) {
    const iconMap = {
        'user_source': '🏦',
        'agent_destination': '🏢',
        'registration_attempt': '📱',
        'agent_attempt': '📋'
    };
    return iconMap[source.type] || '📌';
}

/**
 * Delete a pending source
 */
async function deletePendingSource(type, sourceId) {
    if (!confirm('Delete this pending source? It can be re-added later.')) return;
    
    const btn = document.querySelector(`[onclick*="deletePendingSource('${type}', ${sourceId})"]`);
    const originalText = btn ? btn.textContent : 'Delete';
    if (btn) { btn.textContent = 'Deleting...'; btn.disabled = true; }

    const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/delete.php', {
        type: type,
        source_id: sourceId
    });

    if (btn) { btn.textContent = originalText; btn.disabled = false; }

    if (!result.ok) {
        showMessage('Failed to delete source: ' + result.error, 'error');
        return;
    }

    showMessage(result.body.data?.message || 'Source deleted successfully.', 'success');
    loadPendingSources();
}

/**
 * Retry a failed source
 */
async function retryPendingSource(type, sourceId) {
    if (!confirm('Retry this source? This will start a new verification attempt.')) return;
    
    const btn = document.querySelector(`[onclick*="retryPendingSource('${type}', ${sourceId})"]`);
    const originalText = btn ? btn.textContent : 'Retry';
    if (btn) { btn.textContent = 'Retrying...'; btn.disabled = true; }

    const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/retry.php', {
        type: type,
        source_id: sourceId
    });

    if (btn) { btn.textContent = originalText; btn.disabled = false; }

    if (!result.ok) {
        showMessage('Failed to retry source: ' + result.error, 'error');
        return;
    }

    const data = result.body.data || {};
    if (data.requires_redirect) {
        showMessage(data.message || 'Redirecting to bank...', 'info');
        setTimeout(() => { window.location.href = data.redirect_url; }, 1500);
        return;
    }
    if (data.requires_otp) {
        showMessage(data.message || 'OTP sent. Enter the code to verify.', 'success');
        // Refresh the list to show the OTP state
        loadPendingSources();
        return;
    }

    showMessage(data.message || 'Source retry initiated successfully.', 'success');
    loadPendingSources();
}


// ============================================================
// TOOLBOX — single entry point for everything that used to
// crowd the topbar: sources, history, claims, agent tools,
// profile, help, terms.
// ============================================================

function openToolbox() { openModal('Toolbox', renderToolbox()); }

function renderToolbox() {
    // Count pending sources from the stored data
    const pendingCount = pendingSources.length;
    
    const claimCount = pendingClaims.length;
    const rows = [
        { label: 'Finalize identity swap', badge: claimCount > 0 ? claimCount : null, action: 'openFinalizeIdentityModal()' },
        { label: 'Pending sources', badge: pendingCount > 0 ? pendingCount : null, action: 'openPendingSources()' },
        { label: 'My sources', action: 'openMySources()' },
        { label: 'Swap history', action: 'openSwapHistory()' },
    ];
    if (agentStatus.is_agent) rows.push({ label: 'Agent tools', action: 'openAgentToolsModal()' });
    rows.push({ label: 'Become an agent', action: 'openAgentModal()' });
    rows.push({ label: 'My profile', action: 'openProfileModal()' });
    rows.push({ label: 'Help', action: 'openHelpModal()' });
    rows.push({ label: 'Terms & conditions', action: 'openTermsModal()' });

    return `<div class="toolbox-list">${rows.map(r => `
        <div class="toolbox-row" onclick="${r.action}">
            <span class="toolbox-row-label">${r.label}</span>
            ${r.badge ? `<span class="toolbox-row-badge">${r.badge}</span>` : ''}
        </div>`).join('')}</div>`;
}

/**
 * Update the toolbox badge with pending count
 */
function updateToolboxBadge() {
    const badge = document.getElementById('toolboxBadge');
    const totalPending = pendingSources.length + pendingClaims.length;
    if (totalPending > 0) {
        badge.style.display = 'inline-flex';
        badge.textContent = totalPending;
    } else {
        badge.style.display = 'none';
    }
}

function openHelpModal() {
    openModal('Help', `
        <div style="font-size:13px;line-height:1.7;color:var(--text);">
            <p style="font-weight:700;margin-bottom:6px;">Sending money</p>
            <ol style="padding-left:18px;margin-bottom:16px;">
                <li>Choose Wallet/Account, Card, or Voucher as your source.</li>
                <li>Pick where it should go: Deposit, Cashout, Send to identity, or Multi-source.</li>
                <li>Enter the amount, review the fee, and confirm.</li>
            </ol>
            <p style="font-weight:700;margin-bottom:6px;">Claiming money sent to you</p>
            <ol style="padding-left:18px;margin-bottom:16px;">
                <li>Open Toolbox &rarr; Claim money.</li>
                <li>Enter your claim PIN and choose how to receive it.</li>
            </ol>
            <p style="font-weight:700;margin-bottom:6px;">Becoming an agent</p>
            <ol style="padding-left:18px;">
                <li>Open Toolbox &rarr; Become an agent and register a business account.</li>
                <li>Once approved, use Agent tools to search and finalize client claims.</li>
            </ol>
        </div>
    `);
}

function openTermsModal() {
    openModal('Terms &amp; conditions', `
        <div style="font-size:13px;line-height:1.7;color:var(--text);">
            <p style="font-weight:700;margin-bottom:6px;">1. The service</p>
            <p style="margin-bottom:14px;">VouchMorph facilitates transfers, cashouts, and identity-based payments between participating institutions on your instruction. We act as an intermediary; the underlying funds remain with the institutions holding your linked sources until a swap completes.</p>
            <p style="font-weight:700;margin-bottom:6px;">2. Your responsibilities</p>
            <p style="margin-bottom:14px;">You are responsible for keeping your PIN, claim codes, and linked source credentials confidential. VouchMorph staff will never ask for your PIN.</p>
            <p style="font-weight:700;margin-bottom:6px;">3. Fees</p>
            <p style="margin-bottom:14px;">Applicable fees are shown before you confirm any swap. Fees vary by swap type, delivery method, and destination institution.</p>
            <p style="font-weight:700;margin-bottom:6px;">4. Identity swaps</p>
            <p style="margin-bottom:14px;">Money sent to an identity (national ID, phone, email, etc.) is held for the recipient for a limited window and requires verification to claim.</p>
            <p style="margin-top:16px;color:var(--text-dim);font-size:11px;">This is placeholder text for structure only — replace with your reviewed legal terms before launch.</p>
        </div>
    `);
}

function openProfileModal() { openModal('My Profile', renderProfileModal()); }
function renderProfileModal() {
    const rows = savedIdentities.length ? savedIdentities.map((id, i) => `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);">
            <div><div style="font-size:11px;color:var(--text-muted);">${escapeHtml(IDENTITY_TYPE_LABELS[id.type] || id.type)}</div><div style="font-size:14px;font-weight:700;">${escapeHtml(id.value)}</div></div>
            <div class="quick-actions" style="margin:0;"><span class="quick-link" onclick="useSavedIdentity(${i})">Use</span><span class="quick-link danger" onclick="removeSavedIdentity(${i})">Remove</span></div>
        </div>`).join('') : `<div style="font-size:12px;color:var(--text-dim);">No saved identities yet.</div>`;
    return `
        <div style="margin-bottom:12px;">${rows}</div>
        <div class="field-group"><label>Identity Type</label><select id="newIdentityType"><option value="national_id">National ID</option><option value="birth_certificate">Birth Certificate</option><option value="voter_id">Voter ID</option><option value="phone">Phone Number</option><option value="email">Email</option></select></div>
        <div class="field-group"><label>Identity Value</label><input id="newIdentityValue" placeholder="Enter the identity value"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="addSavedIdentity()">+ Add Identity</button></div>
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
            <div class="field-label" style="margin-bottom:8px;">Transaction PIN</div>
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Required to claim money sent to your verified identity. Never share it over SMS.</div>
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

async function checkPendingClaims() {
    if (!CONFIG.USER_ID) return;
    try {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/pending_claims.php', {});
        if (!result.ok) return;
        pendingClaims = result.body.data || [];
        updateToolboxBadge();
    } catch (e) { console.error('[claims] Failed to check pending claims', e); }
}
function openFinalizeIdentityModal() {
    openModal('Finalize Identity Swap', renderFinalizeIdentityModal());
}

function renderFinalizeIdentityModal() {
    const claimsHtml = pendingClaims.length === 0
        ? `<div style="font-size:12px;color:var(--text-dim);margin-bottom:16px;">No identity money is currently waiting for you.</div>`
        : `<div style="margin-bottom:16px;">${pendingClaims.map((c, i) => {
            const pinLabel = c.claim_type === 'otp_pin' ? 'the OTP PIN sent by SMS' : 'your transaction PIN';
            return `
            <div style="border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:10px;background:#fff;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:700;font-size:16px;color:var(--primary-dark);">${escapeHtml(c.amount)} ${escapeHtml(c.currency)}</div>
                        <div style="font-size:12px;color:var(--text-muted);">From ${escapeHtml(c.source_institution || 'Unknown')}</div>
                        <div style="font-size:11px;color:var(--text-dim);">Needs ${pinLabel} · Expires ${c.hold_expires_at ? new Date(c.hold_expires_at).toLocaleString() : 'soon'}</div>
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="openClaimForm(${i})">Finalize</button>
                </div>
            </div>`;
        }).join('')}</div>`;

    return `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Money sent to your national ID, phone, or email shows up here.</div>
        ${claimsHtml}
        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:4px;">
            <div class="field-label" style="margin-bottom:6px;">Don't want to depend on SMS?</div>
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Register an identity to your account and set a transaction PIN. Once verified, future identity swaps sent to it can be finalized with your own PIN instead of waiting on an OTP text.</div>
            <div class="field-group"><label>Identity Type</label>
                <select id="regIdentityType">
                    <option value="national_id">National ID</option>
                    <option value="phone">Phone Number</option>
                    <option value="email">Email</option>
                    <option value="birth_certificate">Birth Certificate</option>
                    <option value="voter_id">Voter ID</option>
                </select>
            </div>
            <div class="field-group"><label>Identity Value</label><input id="regIdentityValue" placeholder="Enter the ID number, phone, or email"></div>
            <div class="help" style="margin-bottom:10px;">Phone numbers are confirmed instantly by SMS code. National ID / birth certificate / voter ID go to manual review before they're usable.</div>
            <div class="cta-row"><button class="btn btn-primary btn-sm" onclick="submitRegisterIdentity()">Register identity</button></div>
            <div id="regIdentityOtpFields" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;" id="regIdentityOtpMessage"></div>
                <div class="otp-input-group">
                    <input type="text" id="regIdentityOtp" placeholder="Enter code" inputmode="numeric" maxlength="8">
                    <button class="btn btn-primary btn-sm" onclick="submitVerifyIdentityOtp()">Verify</button>
                </div>
            </div>
        </div>
        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;">
            <span class="quick-link muted" onclick="openProfileModal()">Set / change transaction PIN</span>
        </div>
    `;
}

let regIdentityState = { attemptId: null, identityType: null, identityValue: null };

async function submitRegisterIdentity() {
    const identityType = document.getElementById('regIdentityType').value;
    const identityValue = document.getElementById('regIdentityValue').value.trim();
    if (!identityValue) { showMessage('Enter the identity value.', 'warning'); return; }

    const result = await callApi(CONFIG.API_BASE + '/user/add_identity.php', {
        identity_type: identityType, identity_value: identityValue
    });
    if (!result.ok) { showMessage('Could not register identity: ' + result.error, 'error'); return; }

    const data = result.body.data || {};
    regIdentityState.identityType = identityType;
    regIdentityState.identityValue = identityValue;

    if (data.requires_otp) {
        regIdentityState.attemptId = data.attempt_id || null;
        document.getElementById('regIdentityOtpFields').style.display = 'block';
        document.getElementById('regIdentityOtpMessage').textContent = data.message || 'Enter the code we texted you to confirm this is yours.';
        showMessage('Verification code sent.', 'success');
        return;
    }

    showMessage(data.message || 'Identity submitted for review.', 'success');
}

async function submitVerifyIdentityOtp() {
    const otp = document.getElementById('regIdentityOtp').value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/user/verify_identity_otp.php', {
        attempt_id: regIdentityState.attemptId, otp
    });
    if (!result.ok) { showMessage('Verification failed: ' + result.error, 'error'); return; }
    showMessage('Identity verified. You can now use your transaction PIN for this identity.', 'success');
    openFinalizeIdentityModal();
}

function openClaimForm(idx) {
    const claim = pendingClaims[idx];
    if (!claim) return;
    const pinHint = claim.claim_type === 'otp_pin' ? 'Use the one-time PIN sent by SMS when this money was sent.' : 'Use your VouchMorph transaction PIN.';
    const body = `
        <div style="background:rgba(0,160,173,0.06);border-radius:var(--radius);padding:14px;margin-bottom:14px;">
            <div style="font-size:20px;font-weight:700;color:var(--primary-dark);">${escapeHtml(claim.amount)} ${escapeHtml(claim.currency)}</div>
            <div style="font-size:12px;color:var(--text-muted);">From ${escapeHtml(claim.source_institution || 'Unknown')}</div>
        </div>
        <div class="field-group"><label>Claim PIN</label><input type="password" id="claimPin" inputmode="numeric" maxlength="6" placeholder="••••"><div class="help">${pinHint}</div></div>
        <div class="field-group"><label>Receive as</label><select id="claimDestType" onchange="toggleClaimDestFields(this.value)"><option value="CASHOUT">Cashout (ATM / Agent code)</option><option value="DEPOSIT">Deposit to an account/wallet</option></select></div>
        <div id="claimDepositFields" style="display:none;">
            <div class="field-group"><label>Destination Institution</label><select id="claimDestInst"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div>
            <div class="field-group"><label>Account / Wallet Number</label><input id="claimDestIdentifier" placeholder="Account number or phone"></div>
        </div>
        <div class="cta-row"><button class="btn btn-secondary" onclick="openFinalizeIdentityModal()">← Back</button><button class="btn btn-primary" onclick="submitClaim('${claim.swap_reference}')">Finalize</button></div>`;
    openModal('Finalize Identity Swap', body);
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

async function loadAgentStatus() {
    if (!CONFIG.USER_ID) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) return;
    agentStatus = result.body.data;
    document.getElementById('agentBadge').style.display = agentStatus.is_agent ? 'inline-block' : 'none';
}
async function openAgentModal() {
    openModal('Agent Account', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading...</div>');
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="color:var(--danger);">Failed to load agent status: ${escapeHtml(result.error)}</div>`; return; }
    agentStatus = result.body.data;
    document.getElementById('agentBadge').style.display = agentStatus.is_agent ? 'inline-block' : 'none';
    document.getElementById('modalBody').innerHTML = renderAgentModal();
}

function renderAgentModal() {
    const activeDestinations = agentStatus.all_destinations.filter(d => d.status !== 'cancelled' && !d.deleted_at);
    const statusRows = activeDestinations.length ? activeDestinations.map(d => {
        const isPending = d.status === 'pending_confirmation';
        const isRejected = d.status === 'rejected';
        const canCancel = isPending || isRejected;
        const badge = d.status === 'active' ? '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:0;font-size:11px;font-weight:700;">Active</span>'
            : isPending ? '<span style="background:#fef3c7;color:#8a5a0b;padding:3px 10px;border-radius:0;font-size:11px;font-weight:700;">Pending approval</span>'
            : isRejected ? '<span style="background:#fbeceb;color:var(--danger);padding:3px 10px;border-radius:0;font-size:11px;font-weight:700;">Rejected</span>'
            : '<span style="background:#fbeceb;color:var(--danger);padding:3px 10px;border-radius:0;font-size:11px;font-weight:700;">' + (d.status || 'Unknown') + '</span>';
        return `<div style="border:1px solid var(--border);border-radius:var(--radius);padding:12px;margin-bottom:8px;background:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                <div><div style="font-weight:700;">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)}</div><div style="font-size:12px;color:var(--text-muted);">${escapeHtml(d.identifier)} · ${escapeHtml(d.account_type || d.asset_type)}</div></div>
                <div style="display:flex;align-items:center;gap:8px;">
                    ${badge}
                    ${canCancel ? `<button class="btn-danger-outline" onclick="cancelAgentDestination(${d.id})" style="font-size:10px;padding:4px 10px;">Cancel</button>` : ''}
                </div>
            </div>${d.status === 'rejected' && d.rejection_reason ? `<div style="font-size:12px;color:var(--danger);margin-top:6px;">Reason: ${escapeHtml(d.rejection_reason)}</div>` : ''}
        </div>`;
    }).join('') : '<div style="font-size:12px;color:var(--text-dim);">You have no agent destination accounts registered yet.</div>';

    return `
        <div style="margin-bottom:16px;"><div class="field-label" style="margin-bottom:8px;">Your Agent Accounts</div>${statusRows}</div>
        <div style="border-top:1px solid var(--border);padding-top:16px;">
            <div class="field-label" style="margin-bottom:8px;">Register a New Agent Destination</div>
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Register a business/agent account you hold at a participating institution. Only business or agent-designated accounts are eligible. Approval required before activation.</div>
            <div class="field-group"><label>Institution</label><select id="agentInst" onchange="onAgentInstChange(this.value)"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div>
            <div class="field-group" id="agentAssetTypeGroup" style="display:none;"><label>Account Type</label><select id="agentAssetType"></select><div class="help">Only Account, Wallet, or Card can be used — vouchers stay manual, never registered as a destination.</div></div>
            <div class="field-group"><label>Account / Wallet / Card Number</label><input id="agentIdentifier" placeholder="Your business account number"></div>
            <div class="field-group"><label>Account Name (optional)</label><input id="agentAccountName" placeholder="e.g. Thabo's General Store"></div>
            <div class="cta-row"><button class="btn btn-primary" onclick="submitAgentDestination()">Register &amp; verify</button></div>
        </div>`;
}

async function cancelAgentDestination(destinationId) {
    if (!confirm('Cancel this registration? You can register again later.')) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/cancel_destination.php', { destination_id: destinationId });
    if (!result.ok) { showMessage('Failed to cancel: ' + result.error, 'error'); return; }
    showMessage('Registration cancelled successfully.', 'success');
    openAgentModal();
}

async function submitAgentDestination() {
    const institution = document.getElementById('agentInst').value;
    const assetType = document.getElementById('agentAssetType').value;
    const identifier = document.getElementById('agentIdentifier').value.trim();
    const accountName = document.getElementById('agentAccountName').value.trim();
    if (!institution || !identifier) { showMessage('Select an institution and enter your account number.', 'warning'); return; }
    if (!assetType) { showMessage('Select whether this is an Account, Wallet, or Card.', 'warning'); return; }

    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/propose_destination.php', { institution, asset_type: assetType, identifier, account_name: accountName || undefined });
    if (!result.ok) { showMessage('Could not register: ' + result.error, 'error'); return; }

    const data = result.body.data;
    if (data.requires_redirect) {
        showMessage(data.message || 'Redirecting you to your bank to confirm this account...', 'info');
        window.location.href = data.redirect_url;
        return;
    }
    if (!data.requires_otp) {
        showMessage(data.message, data.otp_supported ? 'success' : 'warning');
        openAgentModal();
        return;
    }
    document.getElementById('modalBody').innerHTML = renderAgentOtpStep(data);
}

function renderAgentOtpStep(data) {
    return `
        <div style="background:rgba(0,160,173,0.06);border-radius:var(--radius);padding:14px;margin-bottom:16px;">
            <div style="font-weight:700;margin-bottom:4px;">Verification code sent</div>
            <div style="font-size:12px;color:var(--text-muted);">${escapeHtml(data.message)}</div>
        </div>
        <div class="field-group"><label>Enter the code</label><input type="text" id="agentOtpCode" inputmode="numeric" maxlength="8" placeholder="Code from your bank"></div>
        <div class="cta-row"><button class="btn btn-secondary" onclick="openAgentModal()">Cancel</button><button class="btn btn-primary" onclick="verifyAgentOtp(${data.attempt_id})">Verify &amp; register</button></div>
    `;
}

async function verifyAgentOtp(attemptId) {
    const otp = document.getElementById('agentOtpCode').value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/verify_destination_otp.php', { attempt_id: attemptId, otp });
    if (!result.ok) { showMessage('Verification failed: ' + result.error, 'error'); return; }
    showMessage(result.body.data.message || 'Account verified and registered.', 'success');
    openAgentModal();
}

const AGENT_ELIGIBLE_ASSET_TYPES = ['ACCOUNT', 'WALLET', 'BANK-WALLET', 'CARD'];

function onAgentInstChange(code) {
    const group = document.getElementById('agentAssetTypeGroup');
    const sel = document.getElementById('agentAssetType');
    if (!code) { group.style.display = 'none'; sel.innerHTML = ''; return; }
    const inst = PARTICIPANTS[code];
    const allTypes = inst?.asset_types || [];
    const eligible = allTypes.filter(t => AGENT_ELIGIBLE_ASSET_TYPES.includes(String(t).toUpperCase()));
    if (eligible.length === 0) {
        group.style.display = 'block';
        sel.innerHTML = '<option value="">No eligible account types at this institution</option>';
        return;
    }
    sel.innerHTML = eligible.map(t => `<option value="${t}">${getAssetConfig(t)?.label || t}</option>`).join('');
    group.style.display = 'block';
}

function openAgentToolsModal() { openModal('Agent Tools', renderAgentToolsSearch()); }

function renderAgentToolsSearch() {
    return `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Search for a client's pending identity payment. You'll need to physically verify their document and have them tell you the OTP PIN texted to them — never their personal VouchMorph transaction PIN — before you can finalize.</div>
        <div class="field-group"><label>Document Type</label><select id="agentSearchType"><option value="national_id">National ID</option><option value="birth_certificate">Birth Certificate</option><option value="voter_id">Voter ID</option></select></div>
        <div class="field-group"><label>Document Number</label><input id="agentSearchValue" placeholder="Enter the client's ID number"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="searchAgentClaim()">Search</button></div>
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

    const data = result.body.data;
    if (!data) { resultsBox.innerHTML = '<div style="font-size:12px;color:var(--text-dim);">No pending payment found for this identity.</div>'; return; }

    agentSearchData = data;

    if (data.multi_currency) {
        let html = '<div style="margin-bottom:12px;"><strong>Multiple currencies found for this identity:</strong></div>';
        data.balances.forEach((b) => {
            const currency = escapeHtml(b.currency);
            const totalAmount = parseFloat(b.total_amount).toFixed(2);
            const swapCount = parseInt(b.swap_count);
            const identityTypeEscaped = escapeHtml(data.identity_type);
            const identityValueEscaped = escapeHtml(data.identity_value);
            html += `
                <div style="border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:10px;background:#fff;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                        <div>
                            <div style="font-weight:700;font-size:18px;color:var(--primary-dark);">${totalAmount} ${currency}</div>
                            <div style="font-size:12px;color:var(--text-muted);">From ${swapCount} different source(s)</div>
                            <div style="font-size:11px;color:var(--text-dim);">Expires ${b.earliest_expires_at ? new Date(b.earliest_expires_at).toLocaleString() : 'soon'}</div>
                        </div>
                        <button class="btn btn-primary btn-sm" onclick="openAgentFinalizeFormAggregated('${identityTypeEscaped}', '${identityValueEscaped}', '${currency}', ${totalAmount}, ${swapCount})">Claim</button>
                    </div>
                </div>`;
        });
        resultsBox.innerHTML = html;
        return;
    }

    const currency = escapeHtml(data.currency);
    const totalAmount = parseFloat(data.total_amount).toFixed(2);
    const swapCount = parseInt(data.swap_count);
    const identityTypeEscaped = escapeHtml(data.identity_type);
    const identityValueEscaped = escapeHtml(data.identity_value);

    resultsBox.innerHTML = `
        <div style="border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:10px;background:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                <div>
                    <div style="font-weight:700;font-size:18px;color:var(--primary-dark);">${totalAmount} ${currency}</div>
                    <div style="font-size:12px;color:var(--text-muted);">From ${swapCount} different source(s)</div>
                    <div style="font-size:11px;color:var(--text-dim);">Expires ${data.earliest_expires_at ? new Date(data.earliest_expires_at).toLocaleString() : 'soon'}</div>
                </div>
                <button class="btn btn-primary btn-sm" onclick="openAgentFinalizeFormAggregated('${identityTypeEscaped}', '${identityValueEscaped}', '${currency}', ${totalAmount}, ${swapCount})">Claim</button>
            </div>
        </div>
    `;
}

function openAgentFinalizeFormAggregated(identityType, identityValue, currency, totalAmount, swapCount) {
    const data = agentSearchData;
    if (!data) { showMessage('Search data not found. Please search again.', 'error'); return; }
    if (!agentStatus.approved_destinations || agentStatus.approved_destinations.length === 0) {
        openModal('Agent Tools', '<div style="color:var(--danger);">You have no approved agent destination account. Register one first.</div>');
        return;
    }
    const destOptions = agentStatus.approved_destinations.map(d =>
        `<option value="${d.id}">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)} - ${escapeHtml(d.identifier)}</option>`
    ).join('');
    const searchTypeLabel = IDENTITY_TYPE_LABELS[document.getElementById('agentSearchType')?.value] || 'document';

    const body = `
        <div style="background:rgba(0,160,173,0.06);border-radius:var(--radius);padding:14px;margin-bottom:14px;">
            <div style="font-size:12px;color:var(--text-muted);">Client's total balance</div>
            <div style="font-size:24px;font-weight:700;color:var(--primary-dark);">${totalAmount} ${currency}</div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">
                This is an aggregated balance from ${swapCount} different source(s).
                The full amount deposits into your account. Whatever the client doesn't take as cash today
                is instantly sent back to their identity as a new claim.
            </div>
        </div>
        <div class="field-group"><label>Deposit into</label><select id="agentDestSelect">${destOptions}</select></div>
        <div class="field-group">
            <label>Cash to give the client now</label>
            <input type="number" id="cashNowAmount" min="0" max="${totalAmount}" step="0.01" value="${totalAmount}">
            <div class="help">Leave less than the full amount to split — the rest becomes a new claim for them to collect elsewhere.</div>
        </div>
        <div class="quick-actions" style="margin:-4px 0 12px;">
            <span class="quick-link" onclick="document.getElementById('cashNowAmount').value=${totalAmount}">Give it all</span>
            <span class="quick-link muted" onclick="document.getElementById('cashNowAmount').value=0">Give none now</span>
        </div>
        <div class="field-group"><label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:400;">
            <input type="checkbox" id="agentDocVerified"> I have physically verified the client's ${searchTypeLabel}
        </label></div>
        <div class="field-group"><label>Client's OTP PIN</label>
            <input type="password" id="agentClaimPin" inputmode="numeric" maxlength="6" placeholder="Ask the client for the PIN texted to them">
            <div class="help">This is the OTP PIN sent by SMS — never a personal transaction PIN. The client must tell you this themselves; never accept a claim without it.</div>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="openAgentToolsModal()">← Back to Search</button>
            <button class="btn btn-primary" onclick="submitAgentFinalizeAggregated('${escapeHtml(identityType)}', '${escapeHtml(identityValue)}', ${totalAmount}, '${currency}')">Process</button>
        </div>
    `;
    openModal('Confirm Deposit', body);
}

async function submitAgentFinalizeAggregated(identityType, identityValue, totalAmount, currency) {
    const destinationAccountId = document.getElementById('agentDestSelect').value;
    const docVerified = document.getElementById('agentDocVerified').checked;
    const pin = document.getElementById('agentClaimPin').value.trim();
    const cashNowAmount = parseFloat(document.getElementById('cashNowAmount').value);

    if (!docVerified) { showMessage('You must confirm you verified the client\'s physical document.', 'warning'); return; }
    if (!pin) { showMessage('Enter the client\'s claim PIN.', 'warning'); return; }
    if (isNaN(cashNowAmount) || cashNowAmount < 0 || cashNowAmount > totalAmount) {
        showMessage(`Cash amount must be between 0 and ${totalAmount}.`, 'warning');
        return;
    }

    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/finalize_claim.php', {
        identity_type: identityType, identity_value: identityValue, pin: pin,
        identity_document_verified: true, destination_account_id: parseInt(destinationAccountId, 10), cash_now_amount: cashNowAmount
    });

    if (!result.ok) { showMessage('Failed: ' + result.error, 'error'); return; }

    const data = result.body.data || {};
    closeModal();

    const netDeposited = data.actually_claimed_net || totalAmount;
    const grossAmount = data.actually_claimed_gross || totalAmount;
    const remainder = data.remainder_reswap?.amount || 0;
    const cashGiven = data.cash_now_amount || cashNowAmount;
    const successfulSwaps = data.swap_count || 0;
    const failedSwaps = data.failed_deposits ? data.failed_deposits.length : 0;
    const totalFees = parseFloat(grossAmount) - parseFloat(netDeposited);

    let msg = '';
    if (netDeposited > 0) {
        msg += `Deposited ${netDeposited.toFixed(2)} ${currency} into your account`;
        if (totalFees > 0) msg += ` (fee: ${totalFees.toFixed(2)} ${currency})`;
        msg += '. ';
    }
    if (cashGiven > 0) msg += `Gave client ${cashGiven.toFixed(2)} ${currency} in cash. `;
    else msg += `No cash given now. `;
    if (remainder > 0) msg += `The remaining ${remainder.toFixed(2)} ${currency} was sent back to their identity — a new PIN was texted to them. `;
    if (successfulSwaps > 1) {
        msg += `(Processed ${successfulSwaps} source(s)`;
        if (failedSwaps > 0) msg += `, ${failedSwaps} failed`;
        msg += `)`;
    } else if (failedSwaps > 0) {
        msg += `(${failedSwaps} source(s) failed)`;
    }
    if (data.status === 'partial_success') msg += ' Partial success — some sources failed.';

    showMessage(msg, 'success');
    agentSearchData = null;
    agentSearchResult = null;
}

function openAgentFinalizeForm(claim) {
    if (claim && claim.total_amount !== undefined) {
        openAgentFinalizeFormAggregated(claim.identity_type, claim.identity_value, claim.currency, claim.total_amount, claim.swap_count);
        return;
    }
    agentSearchResult = claim;
    if (!agentStatus.approved_destinations || agentStatus.approved_destinations.length === 0) {
        openModal('Agent Tools', '<div style="color:var(--danger);">You have no approved agent destination account. Register one first.</div>');
        return;
    }
    const destOptions = agentStatus.approved_destinations.map(d =>
        `<option value="${d.id}">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)} - ${escapeHtml(d.identifier)}</option>`
    ).join('');
    const searchTypeLabel = IDENTITY_TYPE_LABELS[document.getElementById('agentSearchType')?.value] || 'document';
    const body = `
        <div style="background:rgba(0,160,173,0.06);border-radius:var(--radius);padding:14px;margin-bottom:14px;">
            <div style="font-size:12px;color:var(--text-muted);">Client's balance</div>
            <div style="font-size:24px;font-weight:700;color:var(--primary-dark);">${escapeHtml(claim.amount)} ${escapeHtml(claim.currency)}</div>
        </div>
        <div class="field-group"><label>Deposit into</label><select id="agentDestSelect">${destOptions}</select></div>
        <div class="field-group">
            <label>Cash to give the client now</label>
            <input type="number" id="cashNowAmount" min="0" max="${claim.amount}" step="0.01" value="${claim.amount}">
        </div>
        <div class="field-group"><label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:400;">
            <input type="checkbox" id="agentDocVerified"> I have physically verified the client's ${searchTypeLabel}
        </label></div>
        <div class="field-group"><label>Client's OTP PIN</label>
            <input type="password" id="agentClaimPin" inputmode="numeric" maxlength="6" placeholder="Ask the client for the PIN texted to them">
            <div class="help">This is the OTP PIN sent by SMS — never a personal transaction PIN.</div>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="openAgentToolsModal()">← Back</button>
            <button class="btn btn-primary" onclick="submitAgentFinalize()">Process</button>
        </div>`;
    openModal('Confirm Deposit', body);
}

async function submitAgentFinalize() {
    const destinationAccountId = document.getElementById('agentDestSelect').value;
    const docVerified = document.getElementById('agentDocVerified').checked;
    const pin = document.getElementById('agentClaimPin').value.trim();
    const cashNowAmount = parseFloat(document.getElementById('cashNowAmount').value);

    if (!docVerified) { showMessage('You must confirm you verified the client\'s physical document.', 'warning'); return; }
    if (!pin) { showMessage('Enter the client\'s claim PIN.', 'warning'); return; }
    if (isNaN(cashNowAmount) || cashNowAmount < 0 || cashNowAmount > agentSearchResult.amount) {
        showMessage(`Cash amount must be between 0 and ${agentSearchResult.amount}.`, 'warning');
        return;
    }

    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/finalize_claim.php', {
        swap_reference: agentSearchResult.swap_reference, pin, identity_document_verified: true,
        destination_account_id: parseInt(destinationAccountId, 10), cash_now_amount: cashNowAmount
    });

    if (!result.ok) { showMessage('Failed: ' + result.error, 'error'); return; }

    const data = result.body.data || {};
    closeModal();

    const netDeposited = data.actually_claimed_net || agentSearchResult.amount;
    const remainder = data.remainder_reswap?.amount || 0;
    const cashGiven = data.cash_now_amount || cashNowAmount;
    const totalFees = data.total_fee || 0;

    let msg = '';
    if (netDeposited > 0) {
        msg += `Deposited ${netDeposited.toFixed(2)} ${agentSearchResult.currency} into your account`;
        if (totalFees > 0) msg += ` (fee: ${totalFees.toFixed(2)} ${agentSearchResult.currency})`;
        msg += '. ';
    }
    if (cashGiven > 0) msg += `Gave client ${cashGiven.toFixed(2)} ${agentSearchResult.currency} in cash. `;
    else msg += `No cash given now. `;
    if (remainder > 0) msg += `The remaining ${remainder.toFixed(2)} ${agentSearchResult.currency} was sent back to their identity — a new PIN was texted to them.`;

    showMessage(msg, 'success');
    agentSearchResult = null;
}

async function openSwapHistory() {
    openModal('Swap History', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading swaps...</div>');
    if (!CONFIG.USER_ID) {
        document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:30px;color:var(--danger);"><div style="font-weight:700;">Could not identify your account</div><div style="font-size:13px;color:var(--text-muted);margin-top:8px;">Your session doesn't have a user ID attached. Try logging out and back in.</div></div>`;
        return;
    }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/history.php', { user_id: CONFIG.USER_ID, limit: 50 });
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);">Failed to load swap history: ${escapeHtml(result.error)}</div>`; return; }
    renderSwapHistory(result.body);
}

function renderSwapHistory(data) {
    const swaps = data.data || data.swaps || [];
    if (swaps.length === 0) { document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:30px;color:var(--text-muted);"><div style="font-weight:700;">No swaps found</div></div>`; return; }
    let historyHtml = `<div style="max-height:60vh;overflow-y:auto;"><div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Showing ${swaps.length} swap(s)</div>`;
    swaps.forEach((swap) => {
        const statusColor = swap.status === 'completed' || swap.status === 'success' ? 'var(--success)' : swap.status === 'pending' ? 'var(--warning)' : 'var(--danger)';
        const code = swap.voucher_number || null;
        const pin = swap.atm_pin || null;
        const hasCode = !!(code || pin);
        const codeInlineHtml = hasCode ? `
            <div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--border);display:flex;gap:16px;flex-wrap:wrap;">
                ${code ? `<div><div style="font-size:10px;color:var(--text-dim);">Code</div><div style="font-family:monospace;font-weight:700;font-size:14px;color:var(--primary-dark);">${escapeHtml(code)}</div></div>` : ''}
                ${pin ? `<div><div style="font-size:10px;color:var(--text-dim);">PIN</div><div style="font-family:monospace;font-weight:700;font-size:14px;color:var(--primary-dark);">${escapeHtml(pin)}</div></div>` : ''}
                ${swap.voucher_expiry ? `<div><div style="font-size:10px;color:var(--text-dim);">Expires</div><div style="font-size:12px;color:var(--text-muted);">${new Date(swap.voucher_expiry).toLocaleString()}</div></div>` : ''}
            </div>` : '';
        historyHtml += `<div style="border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-bottom:10px;background:#fff;cursor:pointer;" onclick="viewSwapDetail('${swap.reference || swap.swap_reference || 'N/A'}')">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div><div style="font-weight:700;">${swap.swap_type || 'SWAP'} <span style="font-size:11px;color:var(--text-muted);">${swap.reference || swap.swap_reference || ''}</span></div><div style="font-size:12px;color:var(--text-muted);">${swap.source_institution || 'Unknown'} → ${swap.destination_institution || 'Unknown'}</div></div>
                <div style="text-align:right;"><div style="font-weight:700;color:var(--primary-dark);">${swap.amount || 0} ${swap.currency || CONFIG.CURRENCY}</div><div style="font-size:11px;color:${statusColor};">${swap.status || 'unknown'}</div></div>
            </div>${codeInlineHtml}</div>`;
    });
    historyHtml += `</div>`;
    document.getElementById('modalBody').innerHTML = historyHtml;
}

async function viewSwapDetail(reference) {
    openModal('Swap Details', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading details...</div>');
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/details.php', { reference: reference });
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);">Failed to load swap details: ${escapeHtml(result.error)}</div>`; return; }
    renderSwapDetail(result.body);
}

function renderSwapDetail(data) {
    const swap = data.swap || data.data || {};
    const code = swap.voucher_number || null;
    const pin = swap.atm_pin || null;
    const codeBox = (code || pin) ? `
        <div class="atm-code" style="margin-bottom:12px;">
            ${code ? `<div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Cashout / Voucher Code</div><div class="code">${escapeHtml(code)}</div>` : ''}
            ${pin ? `<div style="font-size:11px;color:var(--text-muted);margin:${code ? '10px' : '0'} 0 4px;">PIN</div><div class="code">${escapeHtml(pin)}</div>` : ''}
            ${swap.voucher_expiry ? `<div style="font-size:11px;color:var(--text-dim);margin-top:8px;">Expires ${new Date(swap.voucher_expiry).toLocaleString()}</div>` : ''}
        </div>` : '';
    document.getElementById('modalBody').innerHTML = `
        <div style="max-height:70vh;overflow-y:auto;">
            <div style="background:rgba(0,160,173,0.06);border-radius:var(--radius);padding:16px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div><div style="font-size:12px;color:var(--text-muted);">Reference</div><div style="font-weight:700;">${swap.reference || swap.swap_reference || 'N/A'}</div></div>
                    <div><div style="font-size:12px;color:var(--text-muted);">Status</div><div style="font-weight:700;">${swap.status || 'unknown'}</div></div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                <div style="background:var(--surface);border-radius:var(--radius);padding:12px;"><div style="font-size:11px;color:var(--text-muted);">Swap Type</div><div style="font-weight:700;">${swap.swap_type || 'N/A'}</div></div>
                <div style="background:var(--surface);border-radius:var(--radius);padding:12px;"><div style="font-size:11px;color:var(--text-muted);">Amount</div><div style="font-weight:700;font-size:18px;color:var(--primary-dark);">${swap.amount || 0} ${swap.currency || CONFIG.CURRENCY}</div></div>
            </div>
            ${codeBox}
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
