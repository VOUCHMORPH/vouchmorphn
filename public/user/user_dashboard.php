<?php
// ============================================================ 
// SessionManager-based auth
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
$userRole = $userData['role'] ?? 'user';
$userId = $userData['id'] ?? $userData['user_id'] ?? 0;
$nameParts = preg_split('/\s+/', trim($userName));
$userInitials = strtoupper(
    count($nameParts) >= 2
        ? mb_substr($nameParts[0], 0, 1) . mb_substr($nameParts[count($nameParts) - 1], 0, 1)
        : mb_substr($userName, 0, 2)
);

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
<title>VouchMorph – Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
<style>
:root {
    --bg: #FAF9F6;
    --surface: #FFFFFF;
    --surface-muted: #F4F3EF;
    --surface-hover: #EFEEE8;
    --border: rgba(16,30,27,0.12);
    --border-strong: rgba(16,30,27,0.22);
    --border-active: rgba(16,30,27,0.6);
    --text: #10201C;
    --text-muted: #63706A;
    --text-dim: #8A968F;
    --primary: #10201C;
    --primary-dark: #04120E;
    --accent: #00A878;
    --accent-2: #FF7A59;
    --accent-soft: rgba(0,168,120,0.10);
    --success: #1F8A54;
    --warning: #B8860B;
    --danger: #C62828;
    --radius: 0;
    --radius-sm: 0;
    --radius-pill: 0;
    --font: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --font-mono: ui-monospace, SFMono-Regular, 'Cascadia Mono', Consolas, monospace;
    --transition: all 0.2s ease;
    --shadow-sm: 0 1px 2px rgba(16,30,27,0.04);
    --shadow-md: 0 6px 20px rgba(16,30,27,0.08);
    --header-h: 72px;
    --max-w: 1040px;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
html { scroll-behavior: smooth; }
body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; line-height: 1.5; -webkit-font-smoothing: antialiased; transition: background 0.2s ease, color 0.2s ease; }
input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; }
.fade-in-up { animation: fadeInUp 0.4s ease forwards; }
@keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes popIn { 0% { transform: scale(0.85); opacity: 0; } 60% { transform: scale(1.04); opacity: 1; } 100% { transform: scale(1); } }
@keyframes ringDraw { from { stroke-dashoffset: var(--ring-from); } to { stroke-dashoffset: var(--ring-to); } }
@keyframes confettiFall { 0% { transform: translateY(-10px) rotate(0deg); opacity: 1; } 100% { transform: translateY(140px) rotate(280deg); opacity: 0; } }

/* ============================================================
   APP SHELL — one sticky header, one view slot. Only one of
   #hubView / #swapView / #cardView / #activityView / #toolboxView
   / #qrFullView / #hookView / #unhookView is visible at a time.
   Everything the person is NOT using is fully out of the DOM's
   visible area, not just behind a modal overlay.
   ============================================================ */
.shell-header { position: sticky; top: 0; z-index: 100; background: var(--surface); border-bottom: 1px solid var(--border); }
.shell-header-inner { max-width: var(--max-w); margin: 0 auto; padding: 0 24px; height: var(--header-h); display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.header-left { display: flex; align-items: center; gap: 6px; min-width: 0; }
.brand { font-size: 19px; font-weight: 800; letter-spacing: -0.01em; }
.brand sup { color: var(--accent); font-size: 10px; }
.back-btn { display: none; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); background: none; border: none; cursor: pointer; padding: 8px 4px; font-family: var(--font); }
.back-btn:hover { color: var(--text); }
.back-btn.show { display: inline-flex; }
.home-btn { display: none; align-items: center; justify-content: center; width: 34px; height: 34px; background: none; border: 1px solid var(--border-strong); cursor: pointer; color: var(--text-muted); flex-shrink: 0; }
.home-btn svg { width: 16px; height: 16px; stroke: currentColor; }
.home-btn:hover { color: var(--text); border-color: var(--text); }
.home-btn.show { display: inline-flex; }
.header-right { display: flex; align-items: center; gap: 14px; }
.test-mode-badge { font-size: 10px; color: var(--danger); border: 1px solid rgba(198,40,40,0.35); background: rgba(198,40,40,0.06); padding: 4px 9px; text-transform: uppercase; font-weight: 700; }
.user-chip { width: 38px; height: 38px; background: var(--primary); color: #fff; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
.logout-btn { display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; color: var(--text-muted); border: 1px solid var(--border-strong); flex-shrink: 0; text-decoration: none; }
.logout-btn svg { width: 16px; height: 16px; stroke: currentColor; }
.logout-btn:hover { color: var(--danger); border-color: var(--danger); }

.view { display: none; }
.view.active { display: block; animation: fadeInUp 0.25s ease; }

.message { max-width: var(--max-w); margin: 16px auto 0; padding: 12px 16px; font-size: 13px; display: none; font-weight: 500; }
.message.show { display: block; }
.message.info { background: var(--accent-soft); border-left: 3px solid var(--accent); color: var(--primary); }
.message.success { background: rgba(31,138,84,0.08); border-left: 3px solid var(--success); color: var(--success); }
.message.error { background: rgba(198,40,40,0.08); border-left: 3px solid var(--danger); color: var(--danger); }
.message.warning { background: rgba(184,134,11,0.08); border-left: 3px solid var(--warning); color: #8a6508; }

/* ------------------------------------------------------------
   HUB — the only screen with more than one product visible.
   ------------------------------------------------------------ */
#hubView { max-width: 760px; margin: 0 auto; padding: 48px 24px 60px; }
.hub-eyebrow { text-align: center; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-dim); font-family: var(--font-mono); margin-bottom: 8px; }
.hub-title { text-align: center; font-size: 31px; font-weight: 700; margin-bottom: 40px; }
.product-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 560px) { .product-grid { grid-template-columns: 1fr; } }
.product-tile { background: var(--surface); border: 1px solid var(--border); padding: 32px 26px; cursor: pointer; transition: var(--transition); text-align: left; position: relative; }
.product-tile:hover { border-color: var(--primary); transform: translateY(-2px); }
.product-tile-icon { width: 52px; height: 52px; background: var(--primary); display: flex; align-items: center; justify-content: center; margin-bottom: 18px; }
.product-tile-icon svg { width: 26px; height: 26px; stroke: #fff; }
.product-tile-title { font-size: 19px; font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
.product-tile-sub { font-size: 14px; color: var(--text-muted); line-height: 1.55; margin-bottom: 14px; }
.product-tile-arrow { font-size: 12px; color: var(--accent); font-weight: 700; }
.product-tile-badge { font-size: 8px; font-weight: 800; letter-spacing: 0.04em; color: var(--accent); border: 1px solid var(--accent); padding: 2px 6px; text-transform: uppercase; }

.product-view-inner { max-width: 480px; margin: 0 auto; padding: 40px 24px 60px; }
.product-view-inner.wide { max-width: 720px; }
.product-view-header { text-align: center; margin-bottom: 26px; }
.product-view-eyebrow { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--text-dim); font-family: var(--font-mono); margin-bottom: 6px; }
.product-view-title { font-size: 27px; font-weight: 700; }
.info-btn { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; margin-left: 6px; border: 1px solid var(--border-strong); background: transparent; color: var(--text-dim); font-size: 13px; font-weight: 700; font-family: var(--font-mono); cursor: pointer; vertical-align: middle; }
.info-btn:hover { border-color: var(--accent); color: var(--accent); }

.progress-card { background: var(--surface); border: 1px solid var(--border); padding: 16px 18px; display: flex; align-items: center; gap: 14px; cursor: pointer; transition: var(--transition); }
.progress-card:hover { border-color: var(--border-strong); background: var(--surface-muted); }
.progress-ring-wrap { position: relative; width: 64px; height: 64px; flex-shrink: 0; }
.progress-ring-wrap svg { transform: rotate(-90deg); }
.progress-ring-pct { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; font-family: var(--font-mono); color: var(--primary); }
.progress-card-text { flex: 1; min-width: 0; }
.progress-card-title { font-size: 13px; font-weight: 700; color: var(--text); }
.progress-card-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.progress-card-arrow { font-size: 16px; color: var(--text-dim); flex-shrink: 0; }

.repeat-card { background: var(--primary); color: #fff; padding: 14px 16px; margin-bottom: 18px; display: flex; align-items: center; gap: 12px; cursor: pointer; transition: var(--transition); }
.repeat-card:hover { background: var(--accent); }
.repeat-card-icon { width: 34px; height: 34px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.repeat-card-text { flex: 1; min-width: 0; }
.repeat-card-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.85; }
.repeat-card-sub { font-size: 14px; font-weight: 700; margin-top: 2px; }

.ledger-card { background: var(--surface); border: 1px solid var(--border); padding: 1.75rem; }
.ledger-eyebrow { font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 14px; font-family: var(--font-mono); text-align: center; }
.ledger-sentence { font-size: 29px; font-weight: 600; line-height: 1.5; text-align: center; }
.ledger-sentence input[type=number] { width: 125px; border: none; border-bottom: 2px solid var(--text); background: transparent; font-size: 29px; font-weight: 600; font-family: var(--font-mono); color: var(--text); text-align: center; padding: 0 2px 2px; }
.ledger-sentence input[type=number]:focus { outline: none; border-bottom-color: var(--accent); color: var(--accent); }
.ledger-rows { border-top: 1px solid var(--border); margin-top: 18px; }
.ledger-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border); cursor: pointer; transition: var(--transition); }
.ledger-row:hover { background: var(--surface-muted); margin: 0 -1.75rem; padding: 15px 1.75rem; }
.ledger-row-label { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 8px; }
.ledger-row-value { font-size: 13px; font-weight: 600; color: var(--text-dim); display: flex; align-items: center; gap: 6px; }
.ledger-row-value.filled { color: var(--text); }
.row-icon { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; font-size: 13px; flex-shrink: 0; }
.ledger-links { display: flex; justify-content: center; gap: 20px; margin-top: 16px; }
.ledger-link { font-size: 12px; font-weight: 600; color: var(--text-muted); text-decoration: underline; text-underline-offset: 3px; cursor: pointer; background: none; border: none; font-family: var(--font); }
.ledger-link:hover { color: var(--accent); }
.balance-link-wrap { text-align: center; margin-top: 18px; }

/* Swap source-type row now includes "My Card" as a fourth option */
.swap-source-types { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 18px 0 4px; }
.swap-source-type-opt { border: 1px solid var(--border-strong); padding: 12px 6px; text-align: center; cursor: pointer; font-size: 10px; font-weight: 700; }
.swap-source-type-opt:hover { background: var(--surface-muted); }
.swap-source-type-opt.active { background: var(--primary); color: #fff; }
.swap-source-type-opt .icon { font-size: 16px; display: block; margin-bottom: 4px; }
.swap-source-type-opt[disabled], .swap-source-type-opt.disabled { opacity: 0.4; cursor: not-allowed; }
.card-source-breakdown { border: 1px solid var(--border); padding: 14px; margin: 12px 0; }
.card-source-breakdown-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 12px; }
.card-source-breakdown-row:last-child { border-bottom: none; }
.strategy-row { display: flex; border: 1px solid var(--border-strong); margin-bottom: 10px; }
.strategy-row button { flex: 1; padding: 10px 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: transparent; border: none; border-right: 1px solid var(--border-strong); color: var(--text-muted); cursor: pointer; font-family: var(--font); }
.strategy-row button:last-child { border-right: none; }
.strategy-row button.active { background: var(--primary); color: #fff; }

.cta-row { display: flex; justify-content: center; margin-top: 10px; gap: 10px; }
.cta-row .btn, .cta-row .btn-secondary { flex: 1 1 0; max-width: 360px; }
.btn { padding: 17px 26px; border: none; font-size: 13px; font-weight: 700; font-family: var(--font); cursor: pointer; letter-spacing: 0.06em; text-transform: uppercase; transition: var(--transition); width: 100%; background: var(--primary); color: #fff; }
.btn:hover:not(:disabled) { background: var(--accent); }
.btn:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover:not(:disabled) { background: var(--accent); }
.btn-secondary, .btn.secondary { background: transparent; color: var(--text); border: 1px solid var(--border-strong); font-weight: 600; text-transform: none; letter-spacing: 0; }
.btn-secondary:hover, .btn.secondary:hover { background: var(--surface-muted); }
.btn-danger-outline { background: transparent; color: var(--danger); border: 1px solid rgba(198,40,40,0.4); padding: 6px 14px; font-size: 11px; cursor: pointer; font-weight: 600; }
.btn-sm { padding: 10px 16px !important; font-size: 11px; text-transform: none; letter-spacing: 0; width: auto; }
.btn-link { background: none; border: none; padding: 0; cursor: pointer; color: var(--accent); font-size: 12px; font-weight: 600; }

.quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
.quick-link { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--text-muted); background: transparent; border: 1px solid var(--border-strong); padding: 8px 12px; cursor: pointer; transition: var(--transition); text-transform: uppercase; letter-spacing: 0.03em; }
.quick-link:hover { border-color: var(--text); color: var(--text); }
.quick-link.muted { color: var(--text-dim); font-weight: 500; text-transform: none; letter-spacing: 0; border: none; padding: 4px 0; }
.quick-link.danger { color: var(--danger); border-color: rgba(198,40,40,0.3); }
.quick-link.selected { background: var(--primary); color: #fff; border-color: var(--primary); }
.quick-link.recommended { border-color: var(--accent); position: relative; }
.recommended-badge { position: absolute; top: -8px; right: -6px; background: var(--accent); color: #fff; font-size: 8px; padding: 1px 5px; font-weight: 800; letter-spacing: 0.03em; }

.spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: spin 0.7s linear infinite; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }

.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(4,18,14,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal { background: var(--surface); max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; border: 1px solid var(--border-strong); animation: popIn 0.22s ease; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 22px; background: var(--surface-muted); border-bottom: 1px solid var(--border); }
.modal-header h2 { font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text); font-family: var(--font-mono); }
.modal-close { background: none; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer; line-height: 1; padding: 4px; }
#modalBody { padding: 22px; }

.confirm-overlay { display: none; position: fixed; inset: 0; background: rgba(4,18,14,0.55); z-index: 1100; align-items: center; justify-content: center; padding: 20px; }
.confirm-overlay.active { display: flex; }
.confirm-box { background: var(--surface); border: 1px solid var(--border-strong); max-width: 340px; width: 100%; padding: 20px; animation: popIn 0.18s ease; text-align: center; }
.confirm-box p { font-size: 13px; color: var(--text); margin-bottom: 16px; line-height: 1.5; }
.confirm-box .cta-row { margin-top: 0; }

.tab-hero { text-align: center; padding: 6px 0 14px; border-bottom: 1px solid var(--border); margin-bottom: 14px; }
.tab-hero-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; color: var(--text-dim); font-family: var(--font-mono); }
.tab-hero-input { background: transparent; border: none; text-align: center; font-size: 40px; font-weight: 600; width: 100%; color: var(--text); font-family: var(--font-mono); }
.tab-hero-input:focus { outline: none; }
.tab-hero-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.comp-bar-track { display: flex; height: 6px; width: 100%; background: var(--surface-muted); overflow: hidden; margin-bottom: 16px; border: 1px solid var(--border); }
.comp-bar-seg { height: 100%; flex-shrink: 0; transition: width 0.5s cubic-bezier(0.16,1,0.3,1); }
.tab-status-line { text-align: center; font-size: 12px; margin-bottom: 14px; padding: 10px; font-weight: 600; border: 1px solid var(--border); }
.tab-status-line.ok { color: var(--success); border-color: var(--success); }
.tab-status-line.warn { color: #8a6508; border-color: var(--warning); }
.tab-status-line.bad { color: var(--danger); border-color: var(--danger); }
.tab-status-line .quick-link { margin-left: 8px; }
.tab-strategy-row { display: flex; gap: 0; margin-bottom: 6px; border: 1px solid var(--border-strong); }
.tab-strategy-row .quick-link { flex: 1; justify-content: center; border: none; border-right: 1px solid var(--border-strong); }
.tab-strategy-row .quick-link:last-child { border-right: none; }
.tab-strategy-hint { text-align: center; font-size: 11px; color: var(--text-dim); margin-bottom: 14px; }
.tab-source-list { transition: opacity 0.35s ease; }
.tab-source-card { border: 1px solid var(--border); padding: 14px; margin-bottom: 8px; background: var(--surface); }
.tab-source-empty { border: 1px dashed var(--border-strong); padding: 14px; margin-bottom: 8px; background: var(--surface-muted); }
.tab-fixed-note { margin-top: 8px; padding: 10px; background: var(--surface-muted); font-size: 12px; color: var(--text-dim); }
.tab-estimated-note { font-size: 11px; color: var(--text-dim); margin-top: 4px; font-style: italic; }
.tab-invite-teaser { display: flex; align-items: center; gap: 12px; margin-top: 16px; padding: 14px; border: 1px dashed var(--accent); background: var(--accent-soft); cursor: pointer; }
.tab-invite-icon { font-size: 22px; }
.tab-invite-title { font-weight: 700; font-size: 13px; }
.tab-invite-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.vm-card-note { font-size: 12px; color: var(--text-muted); background: var(--accent-soft); border-left: 3px solid var(--accent); padding: 12px 14px; margin-top: 10px; }

.review-hero { text-align: center; padding: 6px 0 18px; border-bottom: 1px solid var(--border); margin-bottom: 16px; }
.review-hero-label { font-size: 10px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 8px; font-family: var(--font-mono); }
.review-hero-amount { font-size: 42px; font-weight: 600; color: var(--text); font-family: var(--font-mono); }
.review-hero-note { font-size: 11px; color: var(--success); font-weight: 600; margin-top: 8px; }
.preview-box { margin: 0; }
.preview-row { display: flex; justify-content: space-between; align-items: center; padding: 13px 0; gap: 12px; font-size: 13px; border-bottom: 1px solid var(--border); }
.preview-row span:first-child { font-size: 10px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-dim); }
.preview-row .value { font-weight: 600; color: var(--text); text-align: right; font-family: var(--font-mono); }
.preview-row .value.highlight { color: var(--accent); font-size: 20px; }
.preview-security { display: flex; gap: 14px; align-items: flex-start; padding: 14px; background: var(--surface-muted); margin: 16px 0; border: 1px solid var(--border); }
.preview-security-icon { width: 34px; height: 34px; background: var(--primary); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 15px; flex-shrink: 0; }
.preview-security-text strong { display: block; font-size: 12px; margin-bottom: 4px; }
.preview-security-text p { font-size: 11px; color: var(--text-muted); line-height: 1.5; }
.preview-reassure { text-align: center; font-size: 11px; color: var(--text-dim); margin-top: 10px; }
.modal-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 20px; }
.modal-actions .btn-secondary { flex: 0 0 auto; text-transform: none; border: none; background: transparent; color: var(--text-muted); font-size: 12px; width: auto; }
.modal-actions .btn-primary { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }

.result-box { text-align: center; padding: 10px 0; position: relative; overflow: visible; }
.result-box .icon { font-size: 42px; color: var(--success); margin-bottom: 8px; }
.result-box .result-title { font-size: 20px; font-weight: 600; margin-bottom: 8px; }
.result-box .result-sub { font-size: 12px; color: var(--text-muted); margin-bottom: 16px; font-family: var(--font-mono); }
.result-box .atm-code { margin: 16px 0; padding: 18px; background: var(--surface-muted); border: 1px solid var(--border); }
.result-box .atm-code .code { font-size: 24px; font-weight: 700; font-family: var(--font-mono); letter-spacing: 4px; color: var(--accent); }
.result-stat { background: var(--accent-soft); border-left: 3px solid var(--accent); padding: 10px 14px; margin-top: 14px; font-size: 12px; color: var(--primary); text-align: left; }
.confetti-piece { position: absolute; top: 10%; width: 6px; height: 6px; background: var(--accent); animation: confettiFall 1.1s ease-in forwards; }

.field-label { font-size: 11px; color: var(--text-dim); text-transform: uppercase; display: block; margin-bottom: 6px; font-weight: 700; letter-spacing: 0.05em; }
.field-group { margin-bottom: 14px; }
.field-group label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; letter-spacing: 0.05em; }
.field-group input, .field-group select { width: 100%; padding: 12px 14px; background: var(--surface); border: 1px solid var(--border-strong); color: var(--text); font-size: 14px; font-family: var(--font); }
.field-group input:focus, .field-group select:focus { outline: none; border-color: var(--accent); }
.field-group .help { font-size: 11px; color: var(--text-dim); margin-top: 5px; }
.field-group .help.error-help { color: var(--danger); font-weight: 600; }
.asset-fields { margin: 8px 0 14px; padding: 14px; background: var(--surface-muted); border: 1px solid var(--border); }
.identity-field { margin: 8px 0 14px; padding: 14px; background: var(--accent-soft); border: 1px dashed var(--accent); }

.source-type-buttons { display: flex; flex-direction: column; gap: 0; border: 1px solid var(--border-strong); margin-bottom: 8px; }
.source-type-btn { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 13px 15px; font-size: 14px; font-weight: 600; text-align: left; background: var(--surface); color: var(--text); border: none; border-bottom: 1px solid var(--border-strong); cursor: pointer; font-family: var(--font); }
.source-type-btn:last-child { border-bottom: none; }
.source-type-btn:hover { background: var(--surface-muted); }
.source-type-btn.active { background: var(--primary); color: #fff; }
.source-type-btn .btn-label { display: flex; align-items: center; gap: 10px; }
.source-type-btn .count { background: var(--accent-2); color: #fff; font-size: 10px; font-weight: 800; padding: 2px 7px; }
.source-type-btn.active .count { background: rgba(255,255,255,0.25); }
.source-panel-wrap { margin-bottom: 8px; margin-top: 4px; }
.source-panel { margin-bottom: 4px; }
.empty-source-box { text-align: center; padding: 32px 20px; border: 1px dashed var(--border-strong); background: var(--surface-muted); }
.empty-source-box p { font-size: 13px; color: var(--text-dim); margin-bottom: 14px; }

.source-card { background: var(--surface); border: 1px solid var(--border); padding: 13px; margin-bottom: 8px; }
.source-card .source-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.source-card .source-institution { font-weight: 700; font-size: 14px; }
.source-card .source-details { font-size: 12px; color: var(--text-muted); }
.source-card .source-status { font-size: 10px; padding: 2px 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
.source-status.active { background: rgba(31,138,84,0.1); color: var(--success); }
.source-status.pending { background: rgba(184,134,11,0.1); color: var(--warning); }
.source-status.inactive { background: rgba(198,40,40,0.06); color: var(--danger); }
.otp-input-group { display: flex; gap: 8px; margin: 12px 0; }
.otp-input-group input { flex: 1; }

.saved-source-list { border: 1px solid var(--border-strong); background: var(--surface); }
.saved-source-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 14px; cursor: pointer; border-bottom: 1px solid var(--border); }
.saved-source-row:last-child { border-bottom: none; }
.saved-source-row:hover { background: var(--surface-muted); }
.saved-source-row.active { background: var(--primary); color: #fff; }
.saved-source-row .row-main { flex: 1; min-width: 0; }
.saved-source-row .row-inst { font-weight: 700; font-size: 13px; }
.saved-source-row .row-ident { font-size: 11px; color: var(--text-dim); }
.saved-source-row.active .row-ident { color: rgba(255,255,255,0.7); }
.saved-source-row .row-check { font-size: 13px; opacity: 0; }
.saved-source-row.active .row-check { opacity: 1; }

.toolbox-group { margin-bottom: 22px; }
.toolbox-group:last-child { margin-bottom: 0; }
.toolbox-group-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-dim); margin-bottom: 8px; font-family: var(--font-mono); }
.toolbox-list { display: flex; flex-direction: column; gap: 0; border: 1px solid var(--border); }
.toolbox-row { display: flex; align-items: center; gap: 14px; padding: 16px 16px; cursor: pointer; background: var(--surface); border-bottom: 1px solid var(--border); }
.toolbox-row:last-child { border-bottom: none; }
.toolbox-row:hover { background: var(--surface-muted); }
.toolbox-row-icon { width: 22px; text-align: center; font-size: 16px; }
.toolbox-row-label { flex: 1; font-size: 15px; font-weight: 600; color: var(--text); }
.toolbox-badge { min-width: 20px; height: 20px; padding: 0 6px; background: var(--accent-2); color: #fff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }

/* Accordion — collapses each Toolbox section so a long list of
   sources/tools/theme doesn't force an endless scroll. Native
   <details>/<summary> so open/close needs no extra JS state. */
.toolbox-accordion { border: 1px solid var(--border); margin-bottom: 12px; background: var(--surface); }
.toolbox-accordion-summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text); font-family: var(--font-mono); }
.toolbox-accordion-summary::-webkit-details-marker { display: none; }
.toolbox-accordion-arrow { color: var(--text-dim); transition: transform 0.15s ease; font-size: 16px; }
.toolbox-accordion[open] > .toolbox-accordion-summary .toolbox-accordion-arrow { transform: rotate(90deg); }
.toolbox-accordion > .toolbox-list, .toolbox-accordion > .myc-panel { border-top: 1px solid var(--border); border-left: none; border-right: none; border-bottom: none; }
#toolboxSearchInput { font-family: var(--font); }

#identitySwapHint { display: none; background: var(--accent-soft); border-left: 3px solid var(--accent); padding: 12px 14px; font-size: 12px; margin-top: 8px; color: var(--text-muted); }
#swapReadinessHint { text-align: center; font-size: 12px; color: var(--text-muted); margin-top: 12px; display: none; }
#swapReadinessHint.show { display: block; }
#swapReadinessHint.warning { background: rgba(184,134,11,0.08); border-left: 3px solid var(--warning); padding: 12px 16px; }
#swapReadinessHint.success { background: rgba(31,138,84,0.08); border-left: 3px solid var(--success); padding: 12px 16px; color: var(--success); }

.page-footer { max-width: var(--max-w); width: 100%; margin: 0 auto; padding: 24px 24px 32px; border-top: 1px solid var(--border); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.page-footer .footer-copy { font-size: 11px; color: var(--text-dim); font-family: var(--font-mono); }
.page-footer .footer-links { display: flex; gap: 20px; font-size: 12px; }
.page-footer .footer-links span { color: var(--text-muted); font-weight: 600; cursor: pointer; }
.page-footer .footer-links span:hover { color: var(--accent); text-decoration: underline; }

/* ============================================================
   MY VOUCHMORPH CARD — persistent view (not a modal). QR block
   styled after Coinbase's centered/aligned receive screen; the
   contribution-swap strip styled after split-pay apps. Square
   tiles throughout — never circles — to stay inside the app's
   sharp-edged (--radius: 0) system.
   ============================================================ */
.myc-layout { display: grid; grid-template-columns: 320px 1fr; gap: 18px; align-items: start; }
@media (max-width: 860px) { .myc-layout { grid-template-columns: 1fr; } }

.myc-qr-panel { background: var(--surface); border: 1px solid var(--border); padding: 28px 22px; display: flex; flex-direction: column; align-items: center; text-align: center; }
.myc-card-visual { width: 100%; aspect-ratio: 1.586 / 1; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); position: relative; overflow: hidden; margin-bottom: 22px; }
.myc-card-visual::before { content: ""; position: absolute; top: -40%; right: -20%; width: 70%; height: 180%; background: rgba(0,168,120,0.14); transform: rotate(18deg); }
.myc-card-top { position: absolute; top: 16px; left: 18px; right: 18px; display: flex; justify-content: space-between; align-items: flex-start; }
.myc-card-mark { font-size: 11px; font-weight: 800; color: rgba(255,255,255,0.92); letter-spacing: 0.02em; }
.myc-card-mark sup { color: var(--accent); font-size: 8px; }
.myc-card-status { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.55); border: 1px solid rgba(255,255,255,0.25); padding: 3px 7px; }
.myc-card-status.active { color: var(--accent); border-color: rgba(0,168,120,0.5); }
.myc-card-bottom { position: absolute; bottom: 16px; left: 18px; right: 18px; }
.myc-card-number { font-family: var(--font-mono); font-size: 17px; letter-spacing: 0.1em; color: rgba(255,255,255,0.9); font-weight: 600; }
.myc-card-name { font-size: 10px; color: rgba(255,255,255,0.5); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.05em; }

.qr-tap-target { text-align: center; cursor: pointer; transition: opacity 0.15s ease; }
.qr-tap-target:hover { opacity: 0.75; }
.myc-qr-frame { background: #fff; padding: 18px; border: 1px solid var(--border-strong); display: inline-flex; align-items: center; justify-content: center; }
.myc-qr-caption { font-size: 11px; color: var(--text-dim); margin-top: 12px; }
.myc-qr-tap-hint { font-size: 10px; color: var(--accent); font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 4px; }
.myc-qr-suffix-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; padding: 8px 14px; background: var(--surface-muted); border: 1px solid var(--border); }
.myc-qr-suffix { font-family: var(--font-mono); font-size: 13px; font-weight: 700; letter-spacing: 0.04em; }

.myc-panel { background: var(--surface); border: 1px solid var(--border); padding: 18px 20px; margin-bottom: 14px; }
.myc-panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.myc-panel-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-dim); font-family: var(--font-mono); }
.myc-panel-total { font-size: 16px; font-weight: 700; font-family: var(--font-mono); }

.myc-source-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); }
.myc-source-row:last-child { border-bottom: none; }
.myc-source-tile { width: 32px; height: 32px; background: var(--surface-muted); border: 1px solid var(--border-strong); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; font-family: var(--font-mono); flex-shrink: 0; }
.myc-source-info { flex: 1; min-width: 0; }
.myc-source-inst { font-size: 13px; font-weight: 700; }
.myc-source-ident { font-size: 11px; color: var(--text-dim); }
.myc-source-amt { font-size: 16px; font-weight: 700; font-family: var(--font-mono); color: var(--accent); }
.myc-source-status-row { display: flex; align-items: center; gap: 6px; margin-top: 4px; justify-content: flex-end; }
.source-status-badge { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; padding: 2px 6px; border: 1px solid; }
.source-status-badge.open { color: var(--accent); border-color: var(--accent); }
.source-status-badge.expired { color: var(--text-dim); border-color: var(--border-strong); }
.unhook-link { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--danger); background: none; border: none; cursor: pointer; font-family: var(--font); }

.myc-swap-strip { display: flex; align-items: center; margin-bottom: 14px; flex-wrap: wrap; row-gap: 8px; }
.myc-avatar-tile { width: 42px; height: 42px; background: var(--primary); color: #fff; font-size: 13px; font-weight: 800; font-family: var(--font-mono); display: flex; align-items: center; justify-content: center; border: 2px solid var(--surface); margin-left: -10px; }
.myc-avatar-tile:first-child { margin-left: 0; }
.myc-avatar-tile.you { background: var(--accent); }
.myc-avatar-tile.pending { background: var(--surface-muted); color: var(--text-dim); border-style: dashed; border-color: var(--border-strong); }
.myc-swap-strip-label { margin-left: 12px; font-size: 12px; color: var(--text-muted); }

.myc-contributor-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--border); font-size: 12px; }
.myc-contributor-row:last-child { border-bottom: none; }
.myc-contributor-tile { width: 22px; height: 22px; background: var(--surface-muted); border: 1px solid var(--border-strong); font-size: 9px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.myc-contributor-name { flex: 1; color: var(--text); font-weight: 600; }
.myc-contributor-amt { font-family: var(--font-mono); font-weight: 700; }

/* Full-screen QR takeover */
.qr-full-wrap { max-width: 460px; margin: 0 auto; padding: 56px 24px; text-align: center; }
.qr-frame-lg { background: #fff; padding: 30px; border: 1px solid var(--border-strong); display: inline-block; margin-bottom: 24px; }
.qr-full-suffix { font-family: var(--font-mono); font-size: 20px; font-weight: 700; letter-spacing: 0.08em; margin-bottom: 6px; }
.qr-full-name { font-size: 13px; color: var(--text-muted); }
.qr-full-hint { font-size: 12px; color: var(--text-muted); margin-top: 20px; }

/* Hook builder — dual entry: from Card ("Hook a source") or from
   a row in My Sources ("Hook to card"). Supports one or many
   sources per hook: Account, Wallet, Cashout Voucher, or Identity
   claim (only enabled once a claimed identity balance exists). */
.entry-point-note { font-size: 11px; color: var(--text-dim); text-align: center; margin-bottom: 18px; }
.hook-mode-row { display: flex; border: 1px solid var(--border-strong); margin-bottom: 18px; }
.hook-mode-row button { flex: 1; padding: 11px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; background: transparent; border: none; color: var(--text-muted); cursor: pointer; border-right: 1px solid var(--border-strong); font-family: var(--font); }
.hook-mode-row button:last-child { border-right: none; }
.hook-mode-row button.active { background: var(--primary); color: #fff; }
.source-type-picker { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
.source-type-opt { border: 1px solid var(--border-strong); padding: 14px 12px; text-align: center; cursor: pointer; font-size: 12px; font-weight: 700; }
.source-type-opt:hover { background: var(--surface-muted); }
.source-type-opt.active { background: var(--primary); color: #fff; }
.source-type-opt.disabled { opacity: 0.4; cursor: not-allowed; }
.source-type-opt .icon { font-size: 18px; display: block; margin-bottom: 6px; }
.source-type-opt .note { font-size: 9px; font-weight: 500; text-transform: none; color: var(--text-dim); margin-top: 4px; display: block; }
.source-type-opt.active .note { color: rgba(255,255,255,0.7); }
.hook-row-card { border: 1px solid var(--border); padding: 14px; margin-bottom: 10px; position: relative; }
.hook-row-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.hook-row-label { font-size: 11px; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; }
.hook-row-remove { font-size: 11px; color: var(--danger); background: none; border: none; cursor: pointer; font-weight: 700; font-family: var(--font); }
.add-row-btn { width: 100%; padding: 12px; border: 1px dashed var(--border-strong); background: transparent; color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; cursor: pointer; margin-bottom: 16px; font-family: var(--font); }
.add-row-btn:hover { border-color: var(--accent); color: var(--accent); }

/* Unhook confirm */
.unhook-summary { border: 1px solid var(--border); padding: 20px; text-align: center; margin-bottom: 18px; }

/* Theme picker */
.theme-picker { display: flex; gap: 8px; flex-wrap: wrap; }
.theme-chip { flex: 1; min-width: 100px; border: 2px solid var(--border-strong); padding: 14px 10px; text-align: center; cursor: pointer; font-size: 11px; font-weight: 700; background: var(--surface); color: var(--text); }
.theme-chip.active { border-color: var(--accent); }
.theme-chip .swatch { display: flex; gap: 3px; justify-content: center; margin-bottom: 8px; }
.theme-chip .dot { width: 14px; height: 14px; }

@media (max-width: 480px) {
    .myc-qr-panel { padding: 22px 16px; }
    .shell-header-inner { padding: 0 16px; }
    .product-view-inner { padding: 32px 16px 48px; }
}
</style>
</head>
<body>

<header class="shell-header">
    <div class="shell-header-inner">
        <div class="header-left">
            <button class="back-btn" id="backBtn" onclick="goBack()">&larr; <span id="backLabel">All products</span></button>
            <button class="home-btn" id="homeBtn" onclick="goView('hub')" title="Back to home" style="display:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10"/></svg>
            </button>
        </div>
        <div class="brand" id="brandLabel">VouchMorph<sup>TM</sup></div>
        <div class="header-right">
            <?php if ($isTestMode): ?><span class="test-mode-badge">Test mode</span><?php endif; ?>
            <div class="user-chip" onclick="goView('toolbox')" id="userAvatarChip"><?php echo htmlspecialchars($userInitials); ?></div>
            <a href="logout.php" class="logout-btn" title="Log out">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            </a>
        </div>
    </div>
</header>

<div id="mainMessage" class="message"></div>

<!-- ============================================================
     HUB — the only screen where more than one product is visible.
     ============================================================ -->
<div class="view active" id="hubView">
    <div class="hub-eyebrow">VouchMorph</div>
    <div class="hub-title">What do you want to do?</div>
    <div id="progressCardHolder" style="max-width:520px;margin:0 auto 24px;"></div>
    <div class="product-grid">
        <div class="product-tile" onclick="goView('swap')">
            <div class="product-tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M7 10l5-5 5 5M7 14l5 5 5-5"/></svg></div>
            <div class="product-tile-title">Swap</div>
            <div class="product-tile-sub">Move money between accounts, wallets, cards, or straight to someone's identity.</div>
            <div class="product-tile-arrow">Open Swap &rsaquo;</div>
        </div>
        <div class="product-tile" onclick="goView('card')">
            <div class="product-tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="2" y="5" width="20" height="14"/><path d="M2 10h20"/></svg></div>
            <div class="product-tile-title">VouchMorph Card <span style="font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.04em;">(My Card)</span></div>
            <div class="product-tile-sub">Hook one or many sources, share the QR, split a swap.</div>
            <div class="product-tile-arrow">Open Card &rsaquo;</div>
        </div>
        <div class="product-tile" onclick="goView('activity')">
            <div class="product-tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></div>
            <div class="product-tile-title">Activity</div>
            <div class="product-tile-sub">Your full swap history, one screen, searchable.</div>
            <div class="product-tile-arrow">Open Activity &rsaquo;</div>
        </div>
        <div class="product-tile" onclick="goView('toolbox')">
            <div class="product-tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a6.5 6.5 0 0113 0"/></svg></div>
            <div class="product-tile-title">Toolbox</div>
            <div class="product-tile-sub">Sources, identity, theme, agent tools, and account settings.</div>
            <div class="product-tile-arrow">Open Toolbox &rsaquo;</div>
        </div>
    </div>
</div>

<!-- ============================================================
     SWAP — full takeover.
     ============================================================ -->
<div class="view" id="swapView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Move money</div>
            <div class="product-view-title">Swap <button class="info-btn" onclick="openHowItWorks('swap')" title="How Swap works" aria-label="How Swap works">?</button></div>
        </div>

        <div id="repeatCardHolder"></div>

        <div class="ledger-card fade-in-up">
            <div class="ledger-eyebrow">Move money</div>
            <div class="ledger-sentence">
                Swap
                <input type="number" id="fromAmount" placeholder="0.00" step="0.01" min="0.01">
                <span id="fromCurrencyLabel" style="font-size:14px;color:var(--text-dim);"></span>
                from
            </div>
            <div style="text-align:center;font-size:12px;color:var(--text-muted);margin-top:8px;">You'll swap <strong id="amountPreview" style="font-family:var(--font-mono);color:var(--text);">0.00</strong></div>

            <div class="swap-source-types">
                <div class="swap-source-type-opt active" id="swapSrcWallet" onclick="setSwapSourceMode('WALLET')"><span class="icon">💳</span>Wallet</div>
                <div class="swap-source-type-opt" id="swapSrcCard" onclick="setSwapSourceMode('CARD')"><span class="icon">🪪</span>Card</div>
                <div class="swap-source-type-opt" id="swapSrcVoucher" onclick="setSwapSourceMode('VOUCHER')"><span class="icon">🎟️</span>Voucher</div>
                <div class="swap-source-type-opt" id="swapSrcVmcard" onclick="setSwapSourceMode('VMCARD')"><span class="icon">🧩</span>My Card</div>
            </div>

            <div id="swapSingleSourceHolder"></div>
            <div id="swapCardSourceHolder" style="display:none;"></div>

            <div class="ledger-rows">
                <div class="ledger-row" onclick="openDestinationModal()">
                    <span class="ledger-row-label"><span class="row-icon" id="destRowIcon">🎯</span>Destination</span>
                    <span class="ledger-row-value" id="destRowText">Not selected &rsaquo;</span>
                </div>
            </div>

            <div class="cta-row">
                <button class="btn btn-primary" id="reviewBtn" onclick="previewSwap()">Review swap</button>
            </div>
            <div id="swapReadinessHint"></div>

            <div class="ledger-links">
                <button type="button" class="ledger-link" onclick="openIdentitySendModal()">Swap to identity</button>
                <button type="button" class="ledger-link" onclick="quickSetSwapType('MULTI_SOURCE')">Combine sources</button>
            </div>
        </div>

        <div class="balance-link-wrap">
            <button type="button" class="ledger-link" onclick="viewWalletBalance()">View balance</button>
        </div>
    </div>
</div>

<!-- ============================================================
     CARD — persistent full-page view (not a modal).
     ============================================================ -->
<div class="view" id="cardView">
    <div class="product-view-inner wide">
        <div class="product-view-header">
            <div class="product-view-eyebrow">VouchMorph Card</div>
            <div class="product-view-title">My Card <button class="info-btn" onclick="openHowItWorks('card')" title="How the Card works" aria-label="How the Card works">?</button></div>
        </div>
        <div id="cardViewBody"><div style="text-align:center;padding:40px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading...</div></div>
    </div>
</div>

<!-- ============================================================
     FULL-SCREEN QR — takes over when the QR on Card is tapped.
     ============================================================ -->
<div class="view" id="qrfullView">
    <div class="qr-full-wrap">
        <div class="qr-frame-lg" id="qrHolderLg"></div>
        <div class="qr-full-suffix" id="qrFullSuffix"></div>
        <div class="qr-full-name" id="qrFullName"></div>
        <div class="qr-full-hint">Anyone with VouchMorph can scan this to hook their own source to your card.</div>
    </div>
</div>

<!-- ============================================================
     HOOK BUILDER — reachable from Card ("Hook a source") or from
     any row in My Sources ("Hook to card"). Same UI either way.
     ============================================================ -->
<div class="view" id="hookView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow" id="hookEyebrow">Hook to My VouchMorph Card</div>
            <div class="product-view-title" id="hookViewTitle">Hook a source</div>
        </div>
        <div class="entry-point-note" id="hookEntryNote"></div>
        <div class="hook-mode-row" id="hookModeRow">
            <button class="active" id="hookModeSingleBtn" onclick="setHookMode('single')">One source</button>
            <button id="hookModeMultiBtn" onclick="setHookMode('multi')">Multiple sources</button>
        </div>
        <div id="hookRowsHolder"></div>
        <button class="add-row-btn" id="addHookRowBtn" style="display:none;" onclick="addHookRow()">+ Add another source</button>
        <button class="btn" id="hookSubmitBtn" onclick="confirmHookBuilder()">Hook to card</button>
        <div style="font-size:11px;color:var(--text-dim);text-align:center;margin-top:14px;" id="hookFinePrint">Each source is held for 24 hours per its own authorized amount.</div>
    </div>
</div>

<!-- ============================================================
     UNHOOK — reachable from any hooked-source row on Card.
     ============================================================ -->
<div class="view" id="unhookView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Release this hook</div>
            <div class="product-view-title">Unhook everything</div>
        </div>
        <div class="unhook-summary">
            <div style="font-size:11px;color:var(--text-dim);margin-bottom:6px;" id="unhookInstLine"></div>
            <div style="font-size:24px;font-weight:600;font-family:var(--font-mono);color:var(--accent);" id="unhookAmountLine"></div>
        </div>
        <div class="myc-panel" style="font-size:12px;color:var(--text-muted);">
            This releases every source currently hooked to this card, all together — there's no way to release just one source out of the group. Anything already spent (e.g. in a swap) can't be unhooked.
        </div>
        <button class="btn" style="background:var(--danger);" onclick="confirmUnhook()">Unhook everything</button>
        <button class="btn secondary" style="margin-top:10px;" onclick="goBack()">Keep it hooked</button>
    </div>
</div>

<!-- ============================================================
     ACTIVITY — full takeover, swap history rendered inline.
     ============================================================ -->
<div class="view" id="activityView">
    <div class="product-view-inner wide">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Your history</div>
            <div class="product-view-title">Activity</div>
        </div>
        <div id="activityViewBody"><div style="text-align:center;padding:40px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading...</div></div>
    </div>
</div>

<!-- ============================================================
     TOOLBOX — My sources (dual hook entry), identity, agent,
     profile, help/terms, and the theme picker.
     ============================================================ -->
<div class="view" id="toolboxView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Settings</div>
            <div class="product-view-title">Toolbox</div>
        </div>
        <div id="toolboxViewBody"></div>
    </div>
</div>

<footer class="page-footer">
    <div class="footer-copy">&copy; 2026 VouchMorph Financial</div>
    <div class="footer-links">
        <span onclick="openHelpModal()">Help</span>
        <span onclick="openTermsModal()">Terms &amp; Conditions</span>
    </div>
</footer>

<!-- ============================================================
     Off-screen source nodes reused by both Swap (single-source
     path) and the modal-based Add-source / Activate-card flows —
     same pattern as before, just re-homed under the shell.
     ============================================================ -->
<div id="offscreenNodes" style="display:none;">
    <div id="fromSection">
        <div class="field-group">
            <label>Source type</label>
            <div class="source-type-buttons" id="sourceTypeButtons">
                <button type="button" class="source-type-btn" data-cat="WALLET" onclick="toggleSourcePanelInline('WALLET')">
                    <span class="btn-label">💳 Wallet / Account <span class="count" id="walletBtnCount" style="display:none;">0</span></span>
                </button>
                <button type="button" class="source-type-btn" data-cat="CARD" onclick="toggleSourcePanelInline('CARD')">
                    <span class="btn-label">🪪 Card</span>
                </button>
                <button type="button" class="source-type-btn" data-cat="VOUCHER" onclick="toggleSourcePanelInline('VOUCHER')">
                    <span class="btn-label">🎟️ Voucher</span>
                </button>
            </div>
        </div>
        <div id="sourcePanelWrap" class="source-panel-wrap" style="display:none;">
            <div id="walletPanel" class="source-panel" style="display:none;">
                <div id="savedSourcesContainer" style="margin-bottom:12px;display:none;">
                    <div id="savedSourcesChips" class="saved-source-list"></div>
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-top:8px;">
                        <div style="font-size:11px;color:var(--text-dim);">Tap a saved source to auto-fill it. <span class="quick-link muted" onclick="openAddSource()">+ Add another</span></div>
                        <span class="quick-link muted" onclick="clearSourceSelection()" style="display:none;" id="clearSourceBtn">Clear</span>
                    </div>
                </div>
                <div id="noSourcesPrompt" class="empty-source-box" style="display:none;">
                    <div style="font-size:28px;margin-bottom:10px;">🏦</div>
                    <p style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px;">You don't have a wallet or account linked yet</p>
                    <p style="font-size:13px;">Add one now to swap from it — it only takes a minute.</p>
                    <button class="btn btn-primary" onclick="openAddSource()" style="max-width:280px;margin:0 auto;">Add a source</button>
                </div>
            </div>
            <div id="instAssetPanel" class="source-panel" style="display:none;">
                <div class="field-group">
                    <label>Institution</label>
                    <select id="fromInstSelect" onchange="selectFromInst(this.value)"><option value="">Select institution</option></select>
                </div>
            </div>
        </div>
        <div class="asset-fields" id="fromFields" style="display:none;"></div>
        <div class="field-group" style="margin-bottom:0;">
            <div class="help" id="fromLimitsHelp"></div>
            <div class="help" id="fromCurrencyInfo" style="font-size:11px;color:var(--text-dim);margin-top:2px;"></div>
            <div class="help" id="sourceSelectedHelp" style="font-size:11px;color:var(--accent);margin-top:2px;display:none;"></div>
        </div>
    </div>

    <div id="toSection">
        <div class="quick-actions" id="destTypeToggle">
            <span class="quick-link" id="depositToggleBtn" onclick="setSwapType('DEPOSIT')">🏦 To an account</span>
            <span class="quick-link" id="cashoutToggleBtn" onclick="setSwapType('CASHOUT')">💵 Cash pickup</span>
        </div>
        <div id="toInstAssetGroupSlot">
            <div id="toInstAssetGroup">
                <div id="toInstSection">
                    <div class="field-group">
                        <label>Institution</label>
                        <select id="toInstSelect" onchange="selectToInst(this.value)"><option value="">Select institution</option></select>
                    </div>
                </div>
                <div class="field-group" id="toAssetSection" style="display:none;">
                    <label>Asset type</label>
                    <select id="toAssetSelect" onchange="selectToAsset(this.value)"></select>
                </div>
                <div class="asset-fields" id="toFields" style="display:none;"></div>
            </div>
        </div>
        <div id="cashoutFields" style="display:none;">
            <div class="field-group">
                <label>Delivery method</label>
                <select id="deliveryMethodSelect" onchange="setDeliveryMethod(this.value)">
                    <option value="ATM">ATM — get a code, withdraw cash at any partner ATM</option>
                    <option value="AGENT">Agent — get a code, collect cash from a nearby agent</option>
                    <option value="VOUCHER">Voucher — get a code, redeem it anywhere vouchers are accepted</option>
                </select>
                <div class="help" id="deliveryMethodHelp">You'll receive a code by SMS to withdraw cash at any partner ATM.</div>
            </div>
            <div class="field-group">
                <label>Beneficiary phone <span style="color:var(--danger);">*</span></label>
                <input id="beneficiaryPhone" placeholder="+267XXXXXXXX" oninput="state.beneficiaryPhone=this.value; refreshUI();">
                <div class="help">Required for cashout code delivery via SMS</div>
            </div>
        </div>
        <select id="swapTypeSelect" style="display:none;">
            <option value="DEPOSIT">Deposit</option>
            <option value="CASHOUT">Cashout</option>
            <option value="IDENTITY">Identity</option>
            <option value="MULTI_SOURCE">Multi-Source</option>
        </select>
    </div>

    <div id="identityFields" class="identity-field">
        <div class="field-group">
            <label>Identity type</label>
            <select id="identityType" onchange="state.toIdentityType=this.value; updateIdentityHelp();">
                <option value="national_id">National ID</option>
                <option value="birth_certificate">Birth Certificate</option>
                <option value="voter_id">Voter ID</option>
                <option value="phone">Phone Number</option>
                <option value="email">Email</option>
            </select>
        </div>
        <div class="field-group">
            <label>Recipient national ID number</label>
            <input id="identityValue" placeholder="0000 - 0000 - 0000" oninput="state.toIdentityValue=this.value.trim(); refreshUI();">
        </div>
        <div class="field-group">
            <label>SMS notification (optional)</label>
            <input id="identitySms" placeholder="Phone to send SMS notification" oninput="state.toIdentitySms=this.value">
        </div>
        <div id="identitySwapHint">We'll text the recipient a code. If they have a VouchMorph account, they finalize instantly — no code needed. If not, an agent finalizes it for them using the code.</div>
        <div class="hint" id="identityHint" style="font-size:11px;color:var(--text-muted);margin-top:4px;">The recipient will be notified and can claim the funds within 24 hours.</div>
    </div>

    <div id="multiDestControls">
        <div id="multiDestModeRow" class="quick-actions">
            <span class="quick-link" onclick="tabSetMultiDest('institution')">Account / Wallet / Card</span>
            <span class="quick-link" onclick="tabSetMultiDest('identity')">Send to identity</span>
            <span class="quick-link" onclick="tabSetMultiDest('vmcard')">VouchMorph Card</span>
        </div>
        <div id="vmCardNote" class="vm-card-note" style="display:none;">No balance of its own — it's a pathway. One swipe draws directly from everything on this tab.</div>
    </div>
</div>

<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" id="modalContent">
        <div class="modal-header">
            <h2 id="modalTitle">Swap preview</h2>
            <button class="modal-close" onclick="closeModal()" aria-label="Close">&times;</button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <p id="confirmBoxText"></p>
        <div class="cta-row">
            <button class="btn btn-secondary" id="confirmBoxCancel">Cancel</button>
            <button class="btn btn-primary" id="confirmBoxOk">Confirm</button>
        </div>
    </div>
</div>

<script>
const CONFIG = {
    API_KEY: '<?php echo htmlspecialchars($apiKey); ?>',
    COUNTRY_CODE: '<?php echo htmlspecialchars($userCountry); ?>',
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

const ASSET_TYPE_ALIASES = {
    'WALLET': ['MNO-WALLET', 'BANK-WALLET'], 'MOBILE_WALLET': 'MNO-WALLET', 'BANK_WALLET': 'BANK-WALLET',
    'ACCOUNT': 'ACCOUNT', 'CARD': 'CARD', 'VOUCHER': 'VOUCHER', 'ATM': 'ATM',
    'POSTAL_ORDER': 'POSTAL-ORDER', 'CHEQUE': 'CHEQUE', 'CRYPTO': 'CRYPTO'
};
const ASSET_ICONS = {
    'ACCOUNT': '🏦', 'WALLET': '💳', 'MNO-WALLET': '📱', 'BANK-WALLET': '🏦',
    'CARD': '🪪', 'VOUCHER': '🎟️', 'ATM': '🏧', 'POSTAL-ORDER': '✉️',
    'CHEQUE': '🧾', 'CRYPTO': '🪙'
};
function assetIcon(type) { if (!type) return '💠'; return ASSET_ICONS[String(type).toUpperCase()] || '💠'; }

function getAssetConfig(type) {
    if (!type) return null;
    if (ASSETS[type]) return ASSETS[type];
    const normalized = String(type).trim().toUpperCase();
    if (ASSETS[normalized]) return ASSETS[normalized];
    const alias = ASSET_TYPE_ALIASES[normalized];
    if (alias) {
        if (Array.isArray(alias)) { for (const a of alias) { if (ASSETS[a]) return ASSETS[a]; } }
        else if (ASSETS[alias]) { return ASSETS[alias]; }
    }
    const realKey = ASSET_KEY_MAP[normalized];
    if (realKey) return ASSETS[realKey];
    const assetKeys = Object.keys(ASSETS);
    for (const key of assetKeys) { if (key.includes(normalized) || normalized.includes(key)) return ASSETS[key]; }
    return null;
}

function friendlyFieldMessage(assetType, fieldName, reason) {
    const config = getAssetConfig(assetType);
    const field = (config?.fields || []).find(f => f.name === fieldName);
    const label = field?.label || fieldName || 'This field';
    if (!reason) return `${label} is required.`;
    if (reason === 'required but empty') return `${label} is required.`;
    if (field?.help_text) return `${label}: ${field.help_text}`;
    if (fieldName === 'phone' || fieldName === 'phone_number') return `${label} should be a valid phone number, e.g. +267 71 234 567.`;
    if (fieldName === 'account_number' || fieldName === 'account') return `${label} looks off — double check the number and try again.`;
    return `${label} doesn't look right — please check it and try again.`;
}
function friendlyApiError(raw) {
    if (!raw) return "Something didn't go through. Please try again.";
    const map = [
        [/network error/i, "Couldn't reach VouchMorph — check your connection and try again."],
        [/timeout/i, "That took too long. Please try again."],
        [/insufficient/i, "There isn't enough balance on that source for this amount."],
        [/limit/i, "That amount is outside what this institution allows."],
        [/non-json/i, "VouchMorph had a hiccup on our end. Please try again in a moment."],
    ];
    for (const [re, msg] of map) if (re.test(raw)) return msg;
    return raw;
}

let state = {
    fromCategory: 'WALLET', fromInst: null, fromAsset: null, fromFields: {}, fromAmount: 0,
    swapType: 'DEPOSIT', toInst: null, toAsset: null, toFields: {},
    deliveryMethod: 'ATM', beneficiaryPhone: '',
    toIdentityType: 'national_id', toIdentityValue: '', toIdentitySms: '',
    multiSources: [], lastPreview: null, swapPayload: null,
    multiDestMode: 'institution', tabTotalAmount: 0, tabAllocationMode: 'even', contributionStrategy: 'SMART',
    swapSourceMode: 'WALLET', // WALLET | CARD | VOUCHER | VMCARD — which of the 4 Swap source tiles is active
    vmCardStrategy: 'SMART',  // strategy used when swapSourceMode === 'VMCARD'
};
let savedIdentities = [];
let userSources = [];
let agentSearchData = null;
let selectedSourceId = null;
let pendingClaims = [];
let pendingSources = [];
let agentStatus = { is_agent: false, approved_destinations: [], all_destinations: [] };
let sourcePanelOpenCat = null;
let SessionUser = null;
let regIdentityState = { attemptId: null, identityType: null, identityValue: null };
let myCard = null;
let vmCardSources = null; // flat list from GetCardSources.php — the real source-of-truth for "My Card" as a Swap source, aggregated across all active hooks (unlike My.php's single most-recent hook)
let activeSessionPollTimer = null;
let html5QrScanner = null;

function formatMoney(amount, currency) {
    const num = parseFloat(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (!currency) return num;
    return `${num} ${String(currency).toUpperCase().slice(0, 3)}`;
}
function maskIdentifier(value) {
    if (!value) return '';
    const str = String(value);
    if (str.includes('@')) { const [local, domain] = str.split('@'); return local.slice(0, 2) + '•••@' + domain; }
    if (str.length <= 4) return '•'.repeat(str.length);
    return str.slice(0, 3) + '•'.repeat(Math.max(0, str.length - 6)) + str.slice(-3);
}
function escapeHtml(str) { const div = document.createElement('div'); div.textContent = str == null ? '' : String(str); return div.innerHTML; }

// ============================================================
// NAVIGATION SHELL — one product visible at a time. viewStack
// always starts at 'hub'; every other entry pushes on top so
// Back always returns to exactly where you were, in order.
// ============================================================
const VIEW_LABELS = {
    swap: 'Swap', card: 'Card', activity: 'Activity', toolbox: 'Toolbox',
    hook: 'Hook a source', qrfull: 'Card QR', unhook: 'Unhook everything',
};
let viewStack = ['hub'];

function goView(name) {
    viewStack = ['hub', name];
    renderView();
    if (name === 'card') loadCardView();
    if (name === 'activity') loadActivityView();
    if (name === 'toolbox') loadToolboxView();
    // Swap must actively initialize its source picker — without this,
    // the view renders with the "Wallet" tile marked active but an
    // empty picker underneath, since nothing has told it to populate
    // #swapSingleSourceHolder yet (that only happens inside
    // setSwapSourceMode()/toggleSourcePanelInline()).
    if (name === 'swap') { setSwapSourceMode(state.swapSourceMode || 'WALLET'); }
}
function pushView(name) { viewStack.push(name); renderView(); }
function goBack() {
    viewStack.pop();
    if (viewStack.length === 0) viewStack = ['hub'];
    renderView();
    stopSessionPolling();
}
function renderView() {
    const current = viewStack[viewStack.length - 1];
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    const el = document.getElementById(current + 'View');
    if (el) el.classList.add('active');
    const backBtn = document.getElementById('backBtn');
    const homeBtn = document.getElementById('homeBtn');
    if (current === 'hub') {
        backBtn.classList.remove('show');
        homeBtn.classList.remove('show');
        document.getElementById('brandLabel').innerHTML = 'VouchMorph<sup>TM</sup>';
    } else {
        backBtn.classList.add('show');
        // Home is always reachable in one tap from anywhere, separate
        // from Back (which only steps up one level) — addresses not
        // having any way to jump straight to the hub from deep views
        // like the hook builder or full-screen QR.
        homeBtn.classList.add('show');
        const prev = viewStack[viewStack.length - 2];
        document.getElementById('backLabel').textContent = (!prev || prev === 'hub') ? 'All products' : (VIEW_LABELS[prev] || 'Back');
        document.getElementById('brandLabel').textContent = VIEW_LABELS[current] || current;
    }
    window.scrollTo(0, 0);
}

// ============================================================
// THEME — pure color-token swap; every theme keeps the same
// sharp-edged (--radius: 0) shapes and layout, just different
// palettes, so switching never feels like a different app.
// ============================================================
const THEMES = {
    classic: { '--bg':'#FAF9F6', '--surface':'#FFFFFF', '--surface-muted':'#F4F3EF', '--text':'#10201C', '--text-muted':'#63706A', '--text-dim':'#8A968F', '--primary':'#10201C', '--primary-dark':'#04120E', '--accent':'#00A878', '--accent-2':'#FF7A59', '--border':'rgba(16,30,27,0.12)', '--border-strong':'rgba(16,30,27,0.22)' },
    women:   { '--bg':'#FFF7F5', '--surface':'#FFFFFF', '--surface-muted':'#FDEDEA', '--text':'#3A1F2B', '--text-muted':'#8C5A6B', '--text-dim':'#B98FA0', '--primary':'#7A3B57', '--primary-dark':'#5A2740', '--accent':'#E08FA4', '--accent-2':'#F2B5A0', '--border':'rgba(122,59,87,0.15)', '--border-strong':'rgba(122,59,87,0.3)' },
    kids:    { '--bg':'#F0FBFF', '--surface':'#FFFFFF', '--surface-muted':'#E4F6FF', '--text':'#132447', '--text-muted':'#4A6280', '--text-dim':'#7D93AC', '--primary':'#1E3A8A', '--primary-dark':'#122457', '--accent':'#FFB400', '--accent-2':'#FF6B6B', '--border':'rgba(30,58,138,0.15)', '--border-strong':'rgba(30,58,138,0.3)' },
    alpha:   { '--bg':'#0A0A0A', '--surface':'#151515', '--surface-muted':'#1E1E1E', '--text':'#F2F2F2', '--text-muted':'#9A9A9A', '--text-dim':'#6E6E6E', '--primary':'#F2F2F2', '--primary-dark':'#FFFFFF', '--accent':'#D6242C', '--accent-2':'#FF5A5F', '--border':'rgba(255,255,255,0.12)', '--border-strong':'rgba(255,255,255,0.25)' },
};
function setTheme(name) {
    const vars = THEMES[name];
    if (!vars) return;
    Object.entries(vars).forEach(([k, v]) => document.documentElement.style.setProperty(k, v));
    document.querySelectorAll('.theme-chip').forEach(c => c.classList.toggle('active', c.dataset.theme === name));
    try { localStorage.setItem('vm_theme', name); } catch (e) {}
}
function loadSavedTheme() {
    let saved = 'classic';
    try { saved = localStorage.getItem('vm_theme') || 'classic'; } catch (e) {}
    setTheme(saved);
}

const Journey = {
    KEY: 'vm_journey_v1',
    read() {
        try { const raw = localStorage.getItem(this.KEY); return raw ? JSON.parse(raw) : { swapsThisMonth: 0, monthStamp: '', lastSwap: null, cardNamed: false }; }
        catch (e) { return { swapsThisMonth: 0, monthStamp: '', lastSwap: null, cardNamed: false }; }
    },
    write(data) { try { localStorage.setItem(this.KEY, JSON.stringify(data)); } catch (e) {} },
    recordSwap(payload, previewData) {
        const data = this.read();
        const stamp = new Date().toISOString().slice(0, 7);
        if (data.monthStamp !== stamp) { data.monthStamp = stamp; data.swapsThisMonth = 0; }
        data.swapsThisMonth += 1;
        data.lastSwap = { payload, amount: previewData?.preview?.amount_requested ?? payload.amount, currency: previewData?.preview?.source_currency ?? payload.currency, when: Date.now(), label: describeSwapForRepeat(payload) };
        this.write(data);
        return data;
    },
    monthlyTotal() { return this.read().swapsThisMonth || 0; }
};
function describeSwapForRepeat(payload) {
    const type = payload.swap_type;
    if (type === 'IDENTITY') return `To identity ${maskIdentifier(payload.identity_value || '')}`;
    if (type === 'CASHOUT') return `Cash pickup via ${payload.delivery_method || 'ATM'}`;
    if (type === 'MULTI_SOURCE') return `Combined sources swap`;
    const instName = PARTICIPANTS[payload.to_institution]?.name || payload.to_institution || 'destination';
    return `To ${instName}`;
}

function computeProgressSteps() {
    return [
        { done: userSources.length > 0, label: 'Add a source' },
        { done: savedIdentities.length > 0, label: 'Register an identity' },
        { done: !!(SessionUser && SessionUser.has_pin), label: 'Set a transaction PIN' },
        { done: Journey.monthlyTotal() > 0 || !!Journey.read().lastSwap, label: 'Make your first swap' },
    ];
}
function renderProgressCard() {
    const holder = document.getElementById('progressCardHolder');
    if (!holder) return;
    const steps = computeProgressSteps();
    const doneCount = steps.filter(s => s.done).length;
    if (doneCount >= steps.length) { holder.innerHTML = ''; return; }
    const pct = Math.round((doneCount / steps.length) * 100);
    const r = 22, circumference = 2 * Math.PI * r;
    const offset = circumference - (pct / 100) * circumference;
    const nextStep = steps.find(s => !s.done);
    holder.innerHTML = `
        <div class="progress-card fade-in-up" onclick="goView('toolbox')">
            <div class="progress-ring-wrap">
                <svg width="52" height="52" viewBox="0 0 52 52">
                    <circle cx="26" cy="26" r="${r}" fill="none" stroke="var(--border)" stroke-width="4" />
                    <circle cx="26" cy="26" r="${r}" fill="none" stroke="var(--accent)" stroke-width="4" stroke-dasharray="${circumference}" stroke-dashoffset="${circumference}" stroke-linecap="round" style="--ring-from:${circumference};--ring-to:${offset};animation:ringDraw 0.8s ease forwards;" />
                </svg>
                <div class="progress-ring-pct">${pct}%</div>
            </div>
            <div class="progress-card-text">
                <div class="progress-card-title">Getting set up — ${doneCount}/${steps.length} done</div>
                <div class="progress-card-sub">${nextStep ? 'Next: ' + nextStep.label : "You're all set"}</div>
            </div>
            <div class="progress-card-arrow">&rsaquo;</div>
        </div>`;
}
function renderRepeatCard() {
    const holder = document.getElementById('repeatCardHolder');
    if (!holder) return;
    const last = Journey.read().lastSwap;
    if (!last) { holder.innerHTML = ''; return; }
    holder.innerHTML = `
        <div class="repeat-card fade-in-up" onclick="repeatLastSwap()">
            <div class="repeat-card-icon">↻</div>
            <div class="repeat-card-text">
                <div class="repeat-card-title">Repeat last swap</div>
                <div class="repeat-card-sub">${escapeHtml(last.label)} · ${formatMoney(last.amount, last.currency)}</div>
            </div>
            <div class="progress-card-arrow" style="color:#fff;opacity:0.8;">&rsaquo;</div>
        </div>`;
}
async function repeatLastSwap() {
    const last = Journey.read().lastSwap;
    if (!last) return;
    state.swapPayload = { ...last.payload, reference: 'SWAP_' + Date.now(), idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8) };
    showMessage('Preparing your repeat swap…', 'info');
    const result = await callApi(CONFIG.PREVIEW_ENDPOINT, state.swapPayload);
    if (!result.ok) { showMessage('Could not repeat that swap: ' + friendlyApiError(result.error), 'error'); return; }
    showPreviewModal(result.body);
}

function showConfirm(message, onConfirm) {
    const overlay = document.getElementById('confirmOverlay');
    document.getElementById('confirmBoxText').textContent = message;
    overlay.classList.add('active');
    const okBtn = document.getElementById('confirmBoxOk');
    const cancelBtn = document.getElementById('confirmBoxCancel');
    const cleanup = () => { overlay.classList.remove('active'); okBtn.onclick = null; cancelBtn.onclick = null; };
    okBtn.onclick = () => { cleanup(); onConfirm(); };
    cancelBtn.onclick = () => { cleanup(); };
}
function fireConfetti(container) {
    if (!container) return;
    const colors = ['var(--accent)', 'var(--accent-2)', 'var(--primary)'];
    for (let i = 0; i < 14; i++) {
        const piece = document.createElement('div');
        piece.className = 'confetti-piece';
        piece.style.left = (10 + Math.random() * 80) + '%';
        piece.style.background = colors[i % colors.length];
        piece.style.animationDelay = (Math.random() * 0.2) + 's';
        container.appendChild(piece);
        setTimeout(() => piece.remove(), 1400);
    }
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
    let response, body;
    try { response = await fetch(url, { method: 'POST', headers: buildHeaders(), body: JSON.stringify(payload), credentials: 'include' }); }
    catch (networkErr) { return { ok: false, error: 'Network error: could not reach ' + url + ' (' + networkErr.message + ')' }; }
    try { body = await response.json(); } catch (parseErr) { return { ok: false, error: 'Server returned a non-JSON response (HTTP ' + response.status + ')' }; }
    if (!response.ok || body.success === false) return { ok: false, error: body.error || ('HTTP ' + response.status), body };
    return { ok: true, body };
}
async function callApiGet(endpoint) {
    let url = endpoint;
    if (CONFIG.IS_TEST_MODE) url += (url.includes('?') ? '&' : '?') + 'test_mode=1';
    let response, body;
    try { response = await fetch(url, { method: 'GET', headers: buildHeaders(), credentials: 'include' }); }
    catch (networkErr) { return { ok: false, error: 'Network error: could not reach ' + url + ' (' + networkErr.message + ')' }; }
    try { body = await response.json(); } catch (parseErr) { return { ok: false, error: 'Server returned a non-JSON response (HTTP ' + response.status + ')' }; }
    if (!response.ok || body.success === false) return { ok: false, error: body.error || ('HTTP ' + response.status), body };
    return { ok: true, body };
}

// ============================================================
// SWAP — source-type row now has 4 tiles: Wallet, Card, Voucher
// (all three route to the existing single-source flow, just with
// state.fromCategory pre-set) and "My Card" (VMCARD), which draws
// from whatever is hooked to your VouchMorph Card using the same
// Smart/Equal/Ratio/Manual strategy as Combine Sources.
// ============================================================
function setSwapSourceMode(mode) {
    state.swapSourceMode = mode;
    ['Wallet', 'Card', 'Voucher', 'Vmcard'].forEach(suffix => document.getElementById('swapSrc' + suffix).classList.remove('active'));
    const idMap = { WALLET: 'swapSrcWallet', CARD: 'swapSrcCard', VOUCHER: 'swapSrcVoucher', VMCARD: 'swapSrcVmcard' };
    document.getElementById(idMap[mode]).classList.add('active');

    const singleHolder = document.getElementById('swapSingleSourceHolder');
    const cardHolder = document.getElementById('swapCardSourceHolder');

    if (mode === 'VMCARD') {
        singleHolder.style.display = 'none';
        cardHolder.style.display = 'block';
        state.fromInst = null; state.fromAsset = null; state.fromFields = {};
        renderVmCardBreakdown();
    } else {
        cardHolder.style.display = 'none';
        singleHolder.style.display = 'block';
        if (singleHolder.children.length === 0) singleHolder.appendChild(document.getElementById('fromSection'));
        state.fromCategory = mode;
        toggleSourcePanelInline(mode);
    }
    refreshUI();
}

// Renders the single-source picker inline in Swap (not a modal) —
// same underlying state/logic as before, just always-visible now.
function toggleSourcePanelInline(cat) {
    document.getElementById('sourcePanelWrap').style.display = 'block';
    document.querySelectorAll('.source-type-btn').forEach(b => b.style.display = 'none');
    state.fromInst = null; state.fromAsset = null; state.fromFields = {};
    selectedSourceId = null;
    const fieldsBox = document.getElementById('fromFields');
    fieldsBox.innerHTML = ''; fieldsBox.style.display = 'none';
    const helpEl = document.getElementById('sourceSelectedHelp');
    if (helpEl) helpEl.style.display = 'none';

    const walletPanel = document.getElementById('walletPanel');
    const instAssetPanel = document.getElementById('instAssetPanel');
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
}

// ------------------------------------------------------------
// VMCARD breakdown — mirrors renderTabBuilder()'s strategy row,
// applied to myCard.hook.contributors instead of user-picked rows.
// ------------------------------------------------------------
async function renderVmCardBreakdown() {
    const holder = document.getElementById('swapCardSourceHolder');
    holder.innerHTML = `<div style="text-align:center;padding:16px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading your card…</div>`;

    if (!myCard) {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/My.php');
        if (!result.ok) {
            holder.innerHTML = `<div class="tab-status-line bad">Couldn't load your card: ${escapeHtml(friendlyApiError(result.error))}</div>`;
            return;
        }
        myCard = result.body.data;
    }

    if (!myCard.is_active) {
        holder.innerHTML = `<div class="tab-status-line warn">Your VouchMorph Card isn't active yet. <span class="quick-link muted" style="text-decoration:underline;" onclick="goView('card')">Activate it →</span></div>`;
        return;
    }

    // GetCardSources.php (not My.php's embedded "hook") is the real
    // resolver for spending purposes — it aggregates every currently
    // HOOKED source across ALL of this card's active hooks, capped by
    // live balance. My.php only shows the single most-recently-created
    // hook for display, which would under-count what's actually
    // spendable if more than one hook is active at once.
    const sourcesResult = await callApi(CONFIG.API_BASE + '/api/v1/cards/GetCardSources.php', { card_suffix: myCard.card_suffix });
    if (!sourcesResult.ok) {
        holder.innerHTML = `<div class="tab-status-line warn">${escapeHtml(friendlyApiError(sourcesResult.error))} <span class="quick-link muted" style="text-decoration:underline;" onclick="goView('card')">Hook a source →</span></div>`;
        vmCardSources = null;
        return;
    }
    const sources = sourcesResult.body.data?.sources || sourcesResult.body.data || [];
    if (!sources.length) {
        holder.innerHTML = `<div class="tab-status-line warn">Nothing is hooked to your card yet. <span class="quick-link muted" style="text-decoration:underline;" onclick="goView('card')">Hook a source →</span></div>`;
        vmCardSources = null;
        return;
    }
    vmCardSources = sources;

    const currency = myCard.hook?.currency || myCard.currency;
    const total = sources.reduce((s, c) => s + (c.available_balance ?? c.authorized_amount ?? 0), 0);
    const rows = sources.map(c => `
        <div class="card-source-breakdown-row">
            <span>${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)} · ${escapeHtml(c.identifier)}</span>
            <span style="font-family:var(--font-mono);font-weight:700;">${formatMoney(c.available_balance ?? c.authorized_amount, currency)}</span>
        </div>`).join('');

    holder.innerHTML = `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Drawing from everything hooked to your card, up to what's typed in "Swap ___ from" above — behaves exactly like Combine sources.</div>
        <div class="strategy-row">
            <button class="${state.vmCardStrategy==='SMART'?'active':''}" onclick="setVmCardStrategy('SMART')">Smart</button>
            <button class="${state.vmCardStrategy==='EQUAL'?'active':''}" onclick="setVmCardStrategy('EQUAL')">Equal</button>
            <button class="${state.vmCardStrategy==='RATIO'?'active':''}" onclick="setVmCardStrategy('RATIO')">Ratio</button>
            <button class="${state.vmCardStrategy==='MANUAL'?'active':''}" onclick="setVmCardStrategy('MANUAL')">Manual</button>
        </div>
        <div class="card-source-breakdown">
            ${rows}
            <div class="card-source-breakdown-row" style="border-bottom:none;padding-top:10px;font-weight:700;">
                <span>Max available to swap</span><span style="font-family:var(--font-mono);color:var(--accent);">${formatMoney(total, currency)}</span>
            </div>
        </div>`;
}
function setVmCardStrategy(s) { state.vmCardStrategy = s; renderVmCardBreakdown(); }

function populateInstitutionsForAsset(assetType) {
    const sel = document.getElementById('fromInstSelect');
    const codes = Object.keys(PARTICIPANTS).filter(code => (PARTICIPANTS[code].asset_types || []).map(t => String(t).toUpperCase()).includes(assetType));
    if (codes.length === 0) { sel.innerHTML = `<option value="">No institutions support ${assetType.toLowerCase()} right now</option>`; return; }
    sel.innerHTML = '<option value="">Select institution</option>' + codes.map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('');
}

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
    if (!config) { container.innerHTML = `<div class="help" style="color:var(--danger);">This account type isn't supported yet.</div>`; return; }
    let fields = config.fields || [];
    if (!includePin) fields = fields.filter(f => f.vault_field !== 'pin');
    fields = fields.filter(f => f.name !== 'amount');
    if (!fields || fields.length === 0) { container.innerHTML = ''; return; }
    container.innerHTML = fields.map(f => {
        const attrs = [];
        if (f.pattern) attrs.push(`pattern="${f.pattern}"`);
        if (f.min_length) attrs.push(`minlength="${f.min_length}"`);
        if (f.max_length) attrs.push(`maxlength="${f.max_length}"`);
        if (f.required) attrs.push('required');
        if (f.min !== undefined) attrs.push(`min="${f.min}"`);
        if (f.max !== undefined) attrs.push(`max="${f.max}"`);
        if (f.type === 'select' && f.options) {
            const optionsHtml = f.options.map(opt => `<option value="${opt}">${opt}</option>`).join('');
            return `<div class="field-group"><label>${f.label} ${f.required ? '*' : ''}</label><select id="${prefix}${f.name}" onchange="window['${onChange.name}']('${f.name}', this.value)"><option value="">${f.placeholder || 'Select'}</option>${optionsHtml}</select>${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}</div>`;
        }
        const inputType = f.vault_field === 'pin' || f.name.includes('pin') || f.name === 'cvv' ? 'password' : (f.type || 'text');
        return `<div class="field-group"><label>${f.label} ${f.required ? '*' : ''}</label><input type="${inputType}" id="${prefix}${f.name}" placeholder="${f.placeholder || ''}" ${attrs.join(' ')} oninput="window['${onChange.name}']('${f.name}', this.value)">${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}</div>`;
    }).join('');
}
function fieldsValidForAsset(assetType, values, includePin) {
    const config = getAssetConfig(assetType);
    if (!config) { console.warn('No config found for asset type:', assetType); return { valid: false, reason: 'not_supported', friendlyMessage: "That account type isn't supported yet." }; }
    let fields = config.fields || [];
    fields = fields.filter(f => f.name !== 'amount');
    fields = fields.filter(f => f.vault_field !== 'pin');
    fields = fields.filter(f => !f.name.toLowerCase().includes('pin'));
    const requiredFields = fields.filter(f => f.required === true);
    let failedField = null, failedReason = null;
    const result = requiredFields.every(f => {
        const val = values[f.name];
        if (!val || String(val).trim().length === 0) { failedField = f.name; failedReason = 'required but empty'; return false; }
        if (f.pattern) {
            try {
                let pattern = f.pattern.replace(/\\\\/g, '\\');
                const regex = new RegExp(pattern);
                if (!regex.test(String(val))) {
                    if (f.name === 'phone' || f.name === 'phone_number') { if (/^\+?[0-9]{10,15}$/.test(String(val))) return true; }
                    if (f.name === 'account_number' || f.name === 'account') { if (/^[A-Z0-9]{8,16}$/i.test(String(val))) return true; }
                    failedField = f.name; failedReason = 'pattern_mismatch'; return false;
                }
            } catch (e) { return true; }
        }
        return true;
    });
    if (!result) return { valid: false, field: failedField, reason: failedReason, friendlyMessage: friendlyFieldMessage(assetType, failedField, failedReason) };
    return { valid: true };
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
function setDeliveryMethod(method) {
    state.deliveryMethod = method;
    const help = document.getElementById('deliveryMethodHelp');
    if (help) {
        const copy = { ATM: "You'll receive a code by SMS to withdraw cash at any partner ATM.", AGENT: "You'll receive a code by SMS — show it to any partner agent to collect cash.", VOUCHER: "You'll receive a voucher code by SMS, redeemable anywhere VouchMorph vouchers are accepted." };
        help.textContent = copy[method] || '';
    }
    refreshUI();
}

function setSwapType(type) {
    state.swapType = type;
    const isIdentity = type === 'IDENTITY', isMulti = type === 'MULTI_SOURCE', isDeposit = type === 'DEPOSIT', isCashout = type === 'CASHOUT';
    const identityFieldsEl = document.getElementById('identityFields');
    if (identityFieldsEl) identityFieldsEl.style.display = (isIdentity || (isMulti && state.multiDestMode === 'identity')) ? 'block' : 'none';
    const identitySwapHintEl = document.getElementById('identitySwapHint');
    if (identitySwapHintEl) identitySwapHintEl.style.display = isIdentity ? 'block' : 'none';
    const cashoutFieldsEl = document.getElementById('cashoutFields');
    if (cashoutFieldsEl) cashoutFieldsEl.style.display = isCashout ? 'block' : 'none';
    const toInstSectionEl = document.getElementById('toInstSection');
    if (toInstSectionEl) toInstSectionEl.style.display = (isDeposit || isCashout || (isMulti && state.multiDestMode !== 'identity')) ? 'block' : 'none';
    const toAssetSectionEl = document.getElementById('toAssetSection');
    if (toAssetSectionEl) toAssetSectionEl.style.display = (isDeposit || isMulti) && state.toAsset && !(isMulti && state.multiDestMode === 'identity') ? 'block' : 'none';
    const toFieldsEl = document.getElementById('toFields');
    if (toFieldsEl) toFieldsEl.style.display = (isDeposit || isMulti) && state.toAsset && !(isMulti && state.multiDestMode === 'identity') ? 'block' : 'none';
    document.getElementById('depositToggleBtn')?.classList.toggle('selected', isDeposit);
    document.getElementById('cashoutToggleBtn')?.classList.toggle('selected', isCashout);
    if (isIdentity) updateIdentityHelp();
    updateCurrencyDisplay();
    if (isMulti && state.multiSources.length === 0) { addMultiSourceRow(); addMultiSourceRow(); }
    refreshUI();
}
function updateIdentityHelp() {
    const type = document.getElementById('identityType')?.value;
    const hint = document.getElementById('identityHint');
    if (!hint || !type) return;
    const documentTypes = ['national_id', 'birth_certificate', 'voter_id'];
    if (documentTypes.includes(type)) hint.textContent = `This is a physical document that can be verified by an agent in person. The recipient will also receive a notification and can claim via dashboard within 24 hours.`;
    else hint.textContent = `The recipient will be notified via ${type === 'phone' ? 'SMS' : 'email'} and can claim the funds within 24 hours.`;
}
function quickSetSwapType(type) {
    if (type === 'MULTI_SOURCE' && state.swapSourceMode === 'VMCARD') {
        // Combine Sources (manually-picked rows) and My Card (auto-drawn
        // from hooks) are two different multi-source mechanisms — letting
        // both be "selected" at once means one silently overrides the
        // other's payload in buildPayload(), which would look like a
        // successful setup right up until the swap uses the wrong sources.
        showMessage('Switched off "My Card" — Combine sources lets you pick sources manually instead.', 'info');
        setSwapSourceMode('WALLET');
    }
    setSwapType(type);
    if (type === 'MULTI_SOURCE') { openTabBuilder(); return; }
}

let multiSourceSeq = 0;
function addMultiSourceRow() { state.multiSources.push({ id: ++multiSourceSeq, institution: null, assetType: null, fields: {}, amount: 0 }); updateMultiTotal(); refreshTabBuilderIfOpen(); refreshUI(); }
function removeMultiSourceRow(id) { state.multiSources = state.multiSources.filter(s => s.id !== id); updateMultiTotal(); refreshTabBuilderIfOpen(); refreshUI(); }
function setMultiSourceInst(id, code) { const src = state.multiSources.find(s => s.id === id); src.institution = code || null; src.assetType = null; src.fields = {}; updateMultiTotal(); refreshTabBuilderIfOpen(); }
function setMultiSourceAsset(id, type) { const src = state.multiSources.find(s => s.id === id); src.assetType = type || null; src.fields = {}; updateMultiTotal(); refreshTabBuilderIfOpen(); }
function setMultiSourceField(id, name, value) { state.multiSources.find(s => s.id === id).fields[name] = value; refreshUI(); }
function setMultiSourceAmount(id, value) { state.multiSources.find(s => s.id === id).amount = parseFloat(value) || 0; updateMultiTotal(); refreshUI(); }
function updateMultiTotal() {
    const total = state.tabTotalAmount || state.multiSources.reduce((sum, s) => sum + (s.amount || 0), 0);
    const cur = PARTICIPANTS[state.toInst]?.limits?.currency || null;
    const el = document.getElementById('multiTotal');
    if (el) el.textContent = formatMoney(total, cur);
    updateSelectionChips();
}
function isFixedAssetType(assetType) { return ['VOUCHER', 'CASHOUT-VOUCHER'].includes(String(assetType).toUpperCase()); }
function voucherTotalExceedsTarget() {
    const voucherTotal = state.multiSources.filter(s => isFixedAssetType(s.assetType)).reduce((sum, s) => sum + (s.amount || 0), 0);
    return state.tabTotalAmount > 0 && voucherTotal > state.tabTotalAmount + 0.01;
}
function fixVoucherOverflow() {
    const voucherTotal = state.multiSources.filter(s => isFixedAssetType(s.assetType)).reduce((sum, s) => sum + (s.amount || 0), 0);
    state.tabTotalAmount = voucherTotal;
    if (state.contributionStrategy === 'EQUAL') autoSplitEven();
    reopenTabBuilder();
}
function hasDuplicateInstitution() { const insts = state.multiSources.filter(s => s.institution).map(s => s.institution); return new Set(insts).size !== insts.length; }
function buildUserAmountsPayload() { const amounts = {}; state.multiSources.forEach(s => { if (s.institution && !isFixedAssetType(s.assetType)) amounts[s.institution] = s.amount; }); return amounts; }
function round2(n) { return Math.round(n * 100) / 100; }
function setTabTotalAmount(value) {
    state.tabTotalAmount = parseFloat(value) || 0;
    if (state.contributionStrategy === 'EQUAL') autoSplitEven();
    if (state.contributionStrategy === 'RATIO') previewRatioSplit();
    reopenTabBuilder();
}
function autoSplitEven() {
    const rows = state.multiSources.filter(s => s.institution && !isFixedAssetType(s.assetType));
    const voucherTotal = state.multiSources.filter(s => isFixedAssetType(s.assetType)).reduce((sum, s) => sum + (s.amount || 0), 0);
    const toSplit = Math.max(0, state.tabTotalAmount - voucherTotal);
    if (rows.length === 0) return;
    const share = Math.floor((toSplit / rows.length) * 100) / 100;
    let allocated = 0;
    rows.forEach((s, i) => { s.amount = (i === rows.length - 1) ? round2(toSplit - allocated) : share; allocated += s.amount; });
}
function resetToEvenSplit() { state.contributionStrategy = 'EQUAL'; autoSplitEven(); reopenTabBuilder(); }
function tabAllocatedTotal() { return round2(state.multiSources.reduce((sum, s) => sum + (s.amount || 0), 0)); }
function tabRemaining() { return round2(state.tabTotalAmount - tabAllocatedTotal()); }
function tabSourceAmountEdited(rowId, value) { state.contributionStrategy = 'USER_SPECIFIED'; setMultiSourceAmount(rowId, value); reopenTabBuilder(); }
async function previewRatioSplit() {
    const rows = state.multiSources.filter(s => s.institution && s.assetType && !isFixedAssetType(s.assetType));
    if (rows.length === 0) return;
    const balances = await Promise.all(rows.map(s => {
        const idField = (getAssetConfig(s.assetType)?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        const identifier = idField ? s.fields[idField.name] : null;
        return identifier ? fetchBalance(s.institution, identifier) : Promise.resolve({ success: false });
    }));
    const voucherTotal = state.multiSources.filter(s => isFixedAssetType(s.assetType)).reduce((sum, s) => sum + (s.amount || 0), 0);
    const toSplit = Math.max(0, state.tabTotalAmount - voucherTotal);
    const totalBalance = balances.reduce((sum, b) => sum + (b.success ? (b.data?.balance || 0) : 0), 0);
    rows.forEach((s, i) => { const bal = balances[i].success ? (balances[i].data?.balance || 0) : 0; s.amount = totalBalance > 0 ? round2(toSplit * (bal / totalBalance)) : 0; s._estimated = true; });
    reopenTabBuilder();
}
function setContributionStrategy(strategy) {
    state.contributionStrategy = strategy;
    if (strategy === 'EQUAL') autoSplitEven();
    if (strategy === 'RATIO') { previewRatioSplit(); return; }
    reopenTabBuilder();
}
function tabSetMultiDest(mode) {
    state.multiDestMode = mode;
    if (mode === 'vmcard' && !PARTICIPANTS['vouchmorph']) { showMessage("VouchMorph Card isn't enabled for this country yet.", 'warning'); state.multiDestMode = 'institution'; mode = 'institution'; }
    const vmNote = document.getElementById('vmCardNote');
    const toInstSectionEl = document.getElementById('toInstSection');
    const toAssetSectionEl = document.getElementById('toAssetSection');
    const toFieldsEl = document.getElementById('toFields');
    const identityFieldsEl = document.getElementById('identityFields');
    if (mode === 'institution') {
        if (toInstSectionEl) toInstSectionEl.style.display = 'block';
        if (toAssetSectionEl) toAssetSectionEl.style.display = state.toAsset ? 'block' : 'none';
        if (toFieldsEl) toFieldsEl.style.display = state.toAsset ? 'block' : 'none';
        if (identityFieldsEl) identityFieldsEl.style.display = 'none';
        if (vmNote) vmNote.style.display = 'none';
    } else if (mode === 'identity') {
        if (toInstSectionEl) toInstSectionEl.style.display = 'none';
        if (toAssetSectionEl) toAssetSectionEl.style.display = 'none';
        if (toFieldsEl) toFieldsEl.style.display = 'none';
        if (identityFieldsEl) identityFieldsEl.style.display = 'block';
        if (vmNote) vmNote.style.display = 'none';
    } else if (mode === 'vmcard') {
        if (toInstSectionEl) toInstSectionEl.style.display = 'none';
        if (toAssetSectionEl) toAssetSectionEl.style.display = 'none';
        if (toFieldsEl) toFieldsEl.style.display = 'none';
        if (identityFieldsEl) identityFieldsEl.style.display = 'none';
        if (vmNote) vmNote.style.display = 'block';
        selectToInst('vouchmorph');
        setTimeout(() => selectToAsset('CARD'), 50);
    }
    if (document.getElementById('tabBuilderDestArea')) renderTabBuilderDestSlot();
    refreshUI();
}
function renderTabBuilderDestSlot() {
    const destArea = document.getElementById('tabBuilderDestArea');
    if (!destArea) return;
    const identityEl = document.getElementById('identityFields');
    const instGroupEl = document.getElementById('toInstAssetGroup');
    if (state.multiDestMode === 'identity') {
        if (identityEl) destArea.appendChild(identityEl);
    } else {
        if (instGroupEl) destArea.appendChild(instGroupEl);
    }
}
function openTabBuilder() {
    if (state.multiSources.length === 0) { addMultiSourceRow(); addMultiSourceRow(); }
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = `<div id="tabBuilderGenerated"></div><div style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border);"><div class="field-label" style="margin-bottom:8px;">Where should this land?</div><div id="multiDestControlsHost"></div><div id="tabBuilderDestArea" style="margin-top:10px;"></div></div>`;
    document.getElementById('modalTitle').textContent = 'Combine sources';
    document.getElementById('modal').classList.add('active');
    document.getElementById('multiDestControlsHost').appendChild(document.getElementById('multiDestControls'));
    document.getElementById('tabBuilderGenerated').innerHTML = renderTabBuilder();
    tabSetMultiDest(state.multiDestMode || 'institution');
    animateTabBuilderIn();
}
function reopenTabBuilder() {
    const gen = document.getElementById('tabBuilderGenerated');
    if (!gen) { openTabBuilder(); return; }
    gen.innerHTML = renderTabBuilder();
    updateMultiTotal();
    animateTabBuilderIn();
}
function animateTabBuilderIn() {
    const list = document.getElementById('tabSourceList');
    if (list) { list.style.opacity = '0'; requestAnimationFrame(() => { list.style.opacity = '1'; }); }
}
function refreshTabBuilderIfOpen() { if (document.getElementById('tabBuilderGenerated')) reopenTabBuilder(); }
function institutionInitials(code) {
    const name = PARTICIPANTS[code]?.name || code || '';
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return name.slice(0, 2).toUpperCase() || '??';
}

function renderCircuitDiagram() {
    const total = state.tabTotalAmount || state.multiSources.reduce((s, r) => s + (r.amount || 0), 0);
    const maxAmt = Math.max(...state.multiSources.map(s => s.amount || 0), 1);
    const n = state.multiSources.length || 1;
    const hubX = 230, hubY = 60, hubW = 100, hubH = 90;
    const chipX = 20, chipH = 26;
    const destX = 420, destW = 46, destH = 36;
    function layoutY(count, r0, r1) { const usable = r1 - r0; return Array.from({ length: count }, (_, i) => r0 + ((i + 0.5) * usable) / count); }
    const srcYs = layoutY(n, 12, 228);
    const pinYs = layoutY(n, hubY + 12, hubY + hubH - 12);
    let html = '';
    state.multiSources.forEach((s, i) => {
        const has = !!s.institution;
        const amt = s.amount || 0;
        const w = has ? 34 + Math.round((amt / maxAmt) * 40) : 30;
        const y = srcYs[i], pinY = pinYs[i], midX = 145 + i * 7;
        if (has) html += `<path d="M${chipX + w},${y} H${midX} V${pinY} H${hubX}" fill="none" stroke="var(--border-strong)" stroke-width="1.5" opacity="0.75" stroke-dasharray="400" stroke-dashoffset="400" id="bcTrace${i}"><animate attributeName="stroke-dashoffset" from="400" to="0" dur="0.5s" begin="${i * 0.08}s" fill="freeze" /></path>`;
        html += `<rect x="${hubX - 4}" y="${pinY - 2}" width="4" height="4" fill="var(--border-strong)" />`;
    });
    state.multiSources.forEach((s, i) => {
        const has = !!s.institution;
        const amt = s.amount || 0;
        const w = has ? 34 + Math.round((amt / maxAmt) * 40) : 30;
        const y = srcYs[i];
        const pct = total > 0 ? amt / total : 0;
        if (has) {
            const label = institutionInitials(s.institution);
            html += `
                <rect x="${chipX}" y="${y - chipH / 2}" width="0" height="${chipH}" fill="var(--primary)"><animate attributeName="width" from="0" to="${w}" dur="0.35s" begin="${0.25 + i * 0.08}s" fill="freeze" /></rect>
                <text x="${chipX + w / 2}" y="${y - 3}" text-anchor="middle" font-size="10" fill="var(--surface)" font-weight="700" font-family="var(--font-mono)">${escapeHtml(label)}</text>
                <text x="${chipX + w / 2}" y="${y + 10}" text-anchor="middle" font-size="8" fill="var(--surface)" opacity="0.75">${amt.toFixed(0)}</text>
                <rect x="${chipX}" y="${y + chipH / 2 + 3}" width="${w}" height="3" fill="var(--surface-muted)" />
                <rect x="${chipX}" y="${y + chipH / 2 + 3}" width="0" height="3" fill="var(--primary)"><animate attributeName="width" from="0" to="${(w * pct).toFixed(1)}" dur="0.4s" begin="${0.5 + i * 0.08}s" fill="freeze" /></rect>
                <rect width="5" height="5" fill="var(--primary)"><animateMotion dur="${1.6 + i * 0.3}s" repeatCount="indefinite" begin="${1 + i * 0.15}s"><mpath href="#bcTrace${i}" /></animateMotion></rect>`;
        } else {
            html += `<rect x="${chipX}" y="${y - chipH / 2}" width="${w}" height="${chipH}" fill="none" stroke="var(--border-strong)" stroke-width="1.5" stroke-dasharray="4 3" /><text x="${chipX + w / 2}" y="${y + 4}" text-anchor="middle" font-size="12" fill="var(--text-dim)">?</text>`;
        }
    });
    html += `
        <rect x="${hubX}" y="${hubY}" width="${hubW}" height="${hubH}" fill="var(--surface)" stroke="var(--border-strong)" stroke-width="1.5" />
        <rect x="${hubX + 6}" y="${hubY + 6}" width="${hubW - 12}" height="2" fill="var(--border)"><animate attributeName="opacity" values="1;0.2;1" dur="2.2s" repeatCount="indefinite" /></rect>
        <text x="${hubX + hubW / 2}" y="${hubY + hubH / 2 - 2}" text-anchor="middle" font-size="17" font-weight="700" fill="var(--text)" font-family="var(--font-mono)">${total.toFixed(0)}</text>
        <text x="${hubX + hubW / 2}" y="${hubY + hubH / 2 + 15}" text-anchor="middle" font-size="8" fill="var(--text-dim)">total</text>`;
    const hubPinY = hubY + hubH / 2;
    let destLabel = null;
    if (state.multiDestMode === 'identity' && state.toIdentityValue) destLabel = maskIdentifier(state.toIdentityValue).replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase() || 'ID';
    else if (state.multiDestMode !== 'identity' && state.toInst) destLabel = institutionInitials(state.toInst);
    if (destLabel) {
        const midX = hubX + hubW + 60;
        html += `
            <rect x="${hubX + hubW}" y="${hubPinY - 2}" width="4" height="4" fill="var(--border-strong)" />
            <path d="M${hubX + hubW},${hubPinY} H${midX} V${hubPinY} H${destX}" fill="none" stroke="var(--border-strong)" stroke-width="1.5" opacity="0.75" stroke-dasharray="260" stroke-dashoffset="260" id="bcDestTrace"><animate attributeName="stroke-dashoffset" from="260" to="0" dur="0.5s" fill="freeze" /></path>
            <rect x="${destX}" y="${hubPinY - destH / 2}" width="${destW}" height="${destH}" fill="var(--primary)" />
            <text x="${destX + destW / 2}" y="${hubPinY - 2}" text-anchor="middle" font-size="10" fill="var(--surface)" font-weight="700" font-family="var(--font-mono)">${escapeHtml(destLabel)}</text>
            <text x="${destX + destW / 2}" y="${hubPinY + 12}" text-anchor="middle" font-size="7" fill="var(--surface)" opacity="0.75">dest</text>
            <rect width="5" height="5" fill="var(--text-dim)"><animateMotion dur="1.3s" repeatCount="indefinite" begin="1.5s"><mpath href="#bcDestTrace" /></animateMotion></rect>`;
    } else {
        html += `<rect x="${destX}" y="${hubPinY - destH / 2}" width="${destW}" height="${destH}" fill="none" stroke="var(--border-strong)" stroke-width="1.5" stroke-dasharray="4 3" /><text x="${destX + destW / 2}" y="${hubPinY + 4}" text-anchor="middle" font-size="14" fill="var(--text-dim)">?</text>`;
    }
    return `<svg width="100%" height="240" viewBox="0 0 480 240" style="margin-bottom:14px;overflow:visible;">${html}</svg>`;
}

function renderTabBuilder() {
    const cur = getInstitutionCurrency(state.toInst) || '';
    const remaining = tabRemaining();
    const strategy = state.contributionStrategy;
    let statusHtml = '';
    if (voucherTotalExceedsTarget()) {
        statusHtml = `<div class="tab-status-line bad">Your voucher is bigger than the tab amount. <span class="quick-link muted" style="text-decoration:underline;" onclick="fixVoucherOverflow()">Fix it for me →</span></div>`;
    } else if (strategy === 'USER_SPECIFIED' && state.tabTotalAmount > 0) {
        if (remaining === 0) statusHtml = `<div class="tab-status-line ok">Fully allocated across ${state.multiSources.filter(s => s.institution).length} source(s)</div>`;
        else statusHtml = `<div class="tab-status-line ${remaining > 0 ? 'warn' : 'bad'}">${remaining > 0 ? formatMoney(remaining, cur) + ' left to allocate' : 'Over by ' + formatMoney(Math.abs(remaining), cur)}</div>`;
    } else if (strategy === 'RATIO') {
        statusHtml = `<div class="tab-status-line warn">Estimated split — recalculated from live balances when you swap</div>`;
    } else if (strategy === 'SMART') {
        statusHtml = `<div class="tab-status-line ok">✓ You don't need to do anything — we'll balance this across your sources automatically</div>`;
    }
    if (hasDuplicateInstitution() && strategy === 'USER_SPECIFIED') statusHtml += `<div class="tab-status-line bad">Manual mode needs one source per institution — remove the duplicate</div>`;
    const activeCount = state.multiSources.filter(s => s.institution && s.amount > 0).length;
    const cards = state.multiSources.map((s, i) => renderTabSourceCard(s, i)).join('');
    return `
        <div class="tab-hero">
            <div class="tab-hero-label">Amount to swap</div>
            <input type="number" step="0.01" class="tab-hero-input" value="${state.tabTotalAmount || ''}" placeholder="0.00" oninput="setTabTotalAmount(this.value)">
            <div class="tab-hero-sub">${activeCount} source(s) added</div>
        </div>
        ${renderCircuitDiagram()}
        <div class="tab-strategy-row">
            <button class="quick-link ${strategy==='SMART'?'selected':''} recommended" onclick="setContributionStrategy('SMART')">Smart<span class="recommended-badge">Best</span></button>
            <button class="quick-link ${strategy==='EQUAL'?'selected':''}" onclick="setContributionStrategy('EQUAL')">Equal</button>
            <button class="quick-link ${strategy==='RATIO'?'selected':''}" onclick="setContributionStrategy('RATIO')">Ratio</button>
            <button class="quick-link ${strategy==='USER_SPECIFIED'?'selected':''}" onclick="setContributionStrategy('USER_SPECIFIED')">Manual</button>
        </div>
        <div class="tab-strategy-hint">Not sure? Smart is recommended — we'll balance it for you.</div>
        ${statusHtml}
        <div id="tabSourceList" class="tab-source-list" style="opacity:0;">${cards}</div>
        <button class="quick-link" style="width:100%;justify-content:center;padding:12px;margin-top:4px;" onclick="addMultiSourceRow(); reopenTabBuilder();">+ Add another source</button>
        ${strategy === 'USER_SPECIFIED' ? `<button class="quick-link muted" style="width:100%;justify-content:center;padding:10px;margin-top:8px;">↻ <span onclick="resetToEvenSplit()">Reset to even split</span></button>` : ''}
        <div class="tab-invite-teaser" onclick="openTabInvite()"><span class="tab-invite-icon"></span><div><div class="tab-invite-title">Split this with friends</div><div class="tab-invite-sub">Invite other VouchMorph users to hook their own sources to this tab — coming soon</div></div></div>`;
}

function renderTabSourceCard(src, idx) {
    if (!src.institution) {
        return `<div class="tab-source-empty"><div style="font-size:12px;color:var(--text-dim);margin-bottom:8px;">Source ${idx + 1} — not set up yet</div><div class="quick-actions"><span class="quick-link" onclick="pickSavedSourceForTab(${src.id})">Use a saved source</span><span class="quick-link muted" onclick="tabSourceManual(${src.id}, 'ACCOUNT')">🏦 Account</span><span class="quick-link muted" onclick="tabSourceManual(${src.id}, 'CARD')">🪪 Card</span><span class="quick-link muted" onclick="tabSourceManual(${src.id}, 'VOUCHER')">🎟️ Voucher</span></div></div>`;
    }
    const instName = PARTICIPANTS[src.institution]?.name || src.institution;
    const isFixed = isFixedAssetType(src.assetType);
    const fieldsHtml = (getAssetConfig(src.assetType)?.fields || []).filter(f => f.name !== 'amount' && f.vault_field !== 'pin').map(f => `<div class="field-group" style="margin-top:8px;"><label>${f.label}</label><input value="${src.fields[f.name] || ''}" placeholder="${f.placeholder || ''}" oninput="setMultiSourceField(${src.id}, '${f.name}', this.value)"></div>`).join('');
    const srcCurrency = PARTICIPANTS[src.institution]?.limits?.currency || '';
    let amountHtml;
    if (isFixed) amountHtml = `<div class="tab-fixed-note">Uses full voucher balance — not split (${formatMoney(src.amount || 0, srcCurrency)})</div>`;
    else if (state.contributionStrategy === 'RATIO') amountHtml = `<div class="field-group" style="margin-top:8px;"><label>Estimated amount</label><input type="number" value="${src.amount || ''}" disabled style="background:var(--surface);color:var(--text-dim);"></div><div class="tab-estimated-note">Estimated from live balance — recalculated at execution time</div>`;
    else if (state.contributionStrategy === 'SMART') amountHtml = `<div class="field-group" style="margin-top:8px;"><label>Amount</label><input type="number" value="${src.amount || ''}" disabled style="background:var(--surface);color:var(--text-dim);"></div><div class="tab-estimated-note">VouchMorph balances this automatically</div>`;
    else amountHtml = `<div class="field-group" style="margin-top:8px;"><label>Amount from this source</label><input type="number" min="0.01" step="0.01" value="${src.amount || ''}" placeholder="0.00" oninput="tabSourceAmountEdited(${src.id}, this.value)"></div>`;
    return `<div class="tab-source-card"><div style="display:flex;justify-content:space-between;align-items:center;"><div style="display:flex;align-items:center;gap:8px;"><span class="row-icon" style="font-size:16px;">${assetIcon(src.assetType)}</span><div><div style="font-weight:700;font-size:13px;">${escapeHtml(instName)}</div><div style="font-size:11px;color:var(--text-dim);">${escapeHtml(getAssetConfig(src.assetType)?.label || src.assetType || '')}</div></div></div>${state.multiSources.length > 2 ? `<button class="btn-danger-outline" onclick="removeMultiSourceRow(${src.id}); reopenTabBuilder();">Remove</button>` : ''}</div>${fieldsHtml}${amountHtml}</div>`;
}
function tabSourceManual(rowId, assetType) {
    const eligible = Object.keys(PARTICIPANTS).filter(code => (PARTICIPANTS[code].asset_types || []).map(t => String(t).toUpperCase()).includes(assetType));
    const options = eligible.map(c => `<option value="${c}">${PARTICIPANTS[c]?.name || c}</option>`).join('');
    document.getElementById('tabBuilderGenerated').innerHTML = `<div style="font-weight:700;margin-bottom:10px;">Add a ${assetType.toLowerCase()} source</div><div class="field-group"><label>Institution</label><select id="tabPickInst"><option value="">Select</option>${options}</select></div><div class="cta-row"><button class="btn btn-secondary" onclick="reopenTabBuilder()">Cancel</button><button class="btn btn-primary" onclick="confirmTabSourceManual(${rowId}, '${assetType}')">Add</button></div>`;
}
function confirmTabSourceManual(rowId, assetType) {
    const inst = document.getElementById('tabPickInst').value;
    if (!inst) { showMessage('Select an institution.', 'warning'); return; }
    if (state.contributionStrategy === 'USER_SPECIFIED' && state.multiSources.some(s => s.institution === inst)) { showMessage('Manual mode needs one source per institution — pick a different institution or switch strategy.', 'warning'); return; }
    setMultiSourceInst(rowId, inst);
    const src = state.multiSources.find(s => s.id === rowId);
    src.assetType = assetType; src.fields = {};
    reopenTabBuilder();
}
function pickSavedSourceForTab(rowId) {
    const eligible = userSources.filter(s => s.status === 'active');
    if (eligible.length === 0) { showMessage('No saved sources yet — add one from the toolbox first.', 'info'); return; }
    const rows = eligible.map(s => `<div class="saved-source-row" onclick="applySavedSourceToTab(${rowId}, '${s.id}')"><div class="row-main"><div class="row-inst">${escapeHtml(PARTICIPANTS[s.institution]?.name || s.institution)}</div><div class="row-ident">${escapeHtml(s.identifier || '')}</div></div></div>`).join('');
    document.getElementById('tabBuilderGenerated').innerHTML = `<div class="saved-source-list">${rows}</div><div class="cta-row" style="margin-top:14px;"><button class="btn btn-secondary" onclick="reopenTabBuilder()">Back</button></div>`;
}
function applySavedSourceToTab(rowId, sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    if (!source) return;
    if (state.contributionStrategy === 'USER_SPECIFIED' && state.multiSources.some(s => s.institution === source.institution)) { showMessage('Manual mode needs one source per institution — pick a different institution or switch strategy.', 'warning'); reopenTabBuilder(); return; }
    setMultiSourceInst(rowId, source.institution);
    const src = state.multiSources.find(s => s.id === rowId);
    src.assetType = source.asset_type; src.fields = {};
    const idField = (getAssetConfig(source.asset_type)?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    if (idField) src.fields[idField.name] = source.identifier;
    reopenTabBuilder();
}
function openTabInvite() {
    document.getElementById('tabBuilderGenerated').innerHTML = `<div style="text-align:center;padding:20px 10px;"><div style="font-weight:700;font-size:15px;margin:8px 0 4px;">Split this tab</div><div style="font-size:12px;color:var(--text-muted);max-width:320px;margin:0 auto 16px;">Soon you'll be able to share a code with friends — they hook their own account, wallet, card, or voucher onto this exact tab, and one swipe covers the whole bill.</div><div style="font-size:11px;color:var(--text-dim);">Not available in this build yet.</div></div><div class="cta-row"><button class="btn btn-primary" onclick="reopenTabBuilder()">Back to my tab</button></div>`;
}

function multiSourcesValid() {
    const activeRows = state.multiSources.filter(s => s.institution);
    if (activeRows.length < 2) return false;
    if (voucherTotalExceedsTarget()) return false;
    if (state.contributionStrategy === 'USER_SPECIFIED') {
        if (state.tabTotalAmount > 0 && Math.abs(tabRemaining()) > 0.01) return false;
        if (hasDuplicateInstitution()) return false;
    }
    return activeRows.every(s => { if (!s.assetType || !(s.amount > 0)) return false; return fieldsValidForAsset(s.assetType, s.fields, true); });
}

function destinationReadiness() {
    const reasons = [];
    if (state.swapType === 'IDENTITY') {
        if (!state.toIdentityValue) reasons.push('enter the identity value to swap to');
    } else if (state.swapType === 'CASHOUT') {
        if (!state.toInst) reasons.push('select a destination institution for the cashout');
        else if (state.toAsset) {
            const validation = fieldsValidForAsset(state.toAsset, state.toFields, false);
            if (!validation.valid) reasons.push('fill in the required destination fields');
        }
    } else {
        if (!state.toInst) reasons.push('select a destination institution');
        else if (!state.toAsset) reasons.push('select a destination asset type');
        else { const validation = fieldsValidForAsset(state.toAsset, state.toFields, false); if (!validation.valid) reasons.push('fill in the required destination fields'); }
    }
    return reasons;
}

function getSwapReadiness() {
    const reasons = [];
    const missingFields = [];

    // ------------------------------------------------------------
    // "My Card" source mode — needs an active card with at least
    // one hooked contributor, plus a normal destination. This is
    // the newest source path, so it gets its own explicit branch
    // rather than being squeezed into the MULTI_SOURCE checks
    // below, which assume state.multiSources (manually-picked rows).
    // ------------------------------------------------------------
    if (state.swapSourceMode === 'VMCARD') {
        if (!myCard) reasons.push('load your VouchMorph Card first');
        else if (!myCard.is_active) reasons.push('activate your VouchMorph Card first');
        else if (!vmCardSources || vmCardSources.length === 0) reasons.push('hook at least one source to your card first');
        else {
            const currency = myCard.hook?.currency || myCard.currency;
            const maxAvailable = vmCardSources.reduce((s, c) => s + (c.available_balance ?? c.authorized_amount ?? 0), 0);
            if (!(state.fromAmount > 0)) reasons.push('enter an amount to swap (up to ' + formatMoney(maxAvailable, currency) + ' hooked)');
            else if (state.fromAmount > maxAvailable + 0.01) reasons.push('enter an amount no more than what\'s hooked — ' + formatMoney(maxAvailable, currency));
        }
        reasons.push(...destinationReadiness());
        return { ready: reasons.length === 0, reasons, missingFields };
    }

    if (state.swapType === 'MULTI_SOURCE') {
        if (!(state.tabTotalAmount > 0)) reasons.push('enter the total amount to swap');
        if (!multiSourcesValid()) {
            state.multiSources.forEach((s, idx) => {
                if (!s.institution) missingFields.push(`Source ${idx + 1}: pick an institution`);
                if (!s.assetType) missingFields.push(`Source ${idx + 1}: pick an asset type`);
                if (!(s.amount > 0)) missingFields.push(`Source ${idx + 1}: enter an amount`);
                const config = getAssetConfig(s.assetType);
                if (config) {
                    const requiredFields = (config.fields || []).filter(f => f.required && f.name !== 'amount' && f.vault_field !== 'pin');
                    requiredFields.forEach(f => { if (!s.fields[f.name] || String(s.fields[f.name]).trim().length === 0) missingFields.push(`Source ${idx + 1}: ${friendlyFieldMessage(s.assetType, f.name, 'required but empty')}`); });
                }
            });
            if (voucherTotalExceedsTarget()) reasons.push('voucher total exceeds your tab amount');
            else if (state.contributionStrategy === 'USER_SPECIFIED' && Math.abs(tabRemaining()) > 0.01) reasons.push('manually allocated amounts don\'t add up to the total');
            else if (hasDuplicateInstitution() && state.contributionStrategy === 'USER_SPECIFIED') reasons.push('manual mode needs one source per institution');
            else reasons.push('build your tab (at least 2 sources, in the Combine Sources panel)');
        }
        if (state.multiDestMode === 'identity') { if (!state.toIdentityValue) reasons.push('enter the identity value to swap to'); }
        else {
            if (!state.toInst) reasons.push('select a destination institution');
            else if (!state.toAsset) reasons.push('select a destination asset type');
            else if (!fieldsValidForAsset(state.toAsset, state.toFields, false).valid) { missingFields.push(`Destination: ${fieldsValidForAsset(state.toAsset, state.toFields, false).friendlyMessage || 'please fill this in'}`); reasons.push('fill in the required destination fields'); }
        }
        return { ready: reasons.length === 0, reasons, missingFields };
    }

    if (!state.fromInst || !state.fromAsset) reasons.push('choose a source (Wallet/Account, Card, or Voucher)');
    if (!(state.fromAmount > 0)) reasons.push('enter an amount');
    else if (state.fromInst && !amountWithinLimits(state.fromInst, state.fromAmount)) {
        const limits = PARTICIPANTS[state.fromInst]?.limits;
        reasons.push(limits ? `enter an amount between ${limits.min_amount} and ${limits.max_amount}` : 'enter an amount within this institution\'s limits');
    }
    if (state.fromInst && state.fromAsset) {
        const validation = fieldsValidForAsset(state.fromAsset, state.fromFields, true);
        if (!validation.valid) { missingFields.push(`Source: ${validation.friendlyMessage || 'please fill this in'}`); reasons.push('fill in the required source fields'); }
    }
    if (state.swapType === 'IDENTITY') {
        if (!state.toIdentityValue) { missingFields.push('Identity: enter who you\'re sending to'); reasons.push('enter the identity value to swap to'); }
    } else if (state.swapType === 'CASHOUT') {
        if (!state.toInst) reasons.push('select a destination institution for the cashout');
        else if (state.toInst && state.toAsset) {
            const validation = fieldsValidForAsset(state.toAsset, state.toFields, false);
            if (!validation.valid) { missingFields.push(`Destination: ${validation.friendlyMessage || 'please fill this in'}`); reasons.push('fill in the required destination fields'); }
        }
    } else {
        if (!state.toInst) reasons.push('select a destination institution');
        else if (!state.toAsset) reasons.push('select a destination asset type');
        else { const validation = fieldsValidForAsset(state.toAsset, state.toFields, false); if (!validation.valid) { missingFields.push(`Destination: ${validation.friendlyMessage || 'please fill this in'}`); reasons.push('fill in the required destination fields'); } }
    }
    return { ready: reasons.length === 0, reasons, missingFields };
}

function refreshUI() {
    const readiness = getSwapReadiness();
    const btn = document.getElementById('reviewBtn');
    if (btn) btn.disabled = false;
    const hint = document.getElementById('swapReadinessHint');
    if (hint) {
        if (readiness.ready) { hint.textContent = ''; hint.className = ''; hint.style.display = 'none'; }
        else {
            let msg = (readiness.missingFields && readiness.missingFields.length > 0) ? readiness.missingFields.join('; ') : readiness.reasons.join(', ');
            hint.textContent = msg; hint.className = 'show warning'; hint.style.display = 'block';
        }
    }
    updateMultiTotal();
    updateToolboxBadge();
    updateSelectionChips();
}
function updateSelectionChips() {
    const destEl = document.getElementById('destRowText');
    const destIcon = document.getElementById('destRowIcon');
    if (destEl) {
        if (state.swapType === 'IDENTITY') {
            if (state.toIdentityValue) { destEl.textContent = 'Identity: ' + maskIdentifier(state.toIdentityValue); destEl.classList.add('filled'); if (destIcon) destIcon.textContent = '🪪'; }
            else { destEl.textContent = 'Not selected \u203a'; destEl.classList.remove('filled'); if (destIcon) destIcon.textContent = '🎯'; }
        } else if (state.toInst) {
            const instName = PARTICIPANTS[state.toInst]?.name || state.toInst;
            destEl.textContent = instName; destEl.classList.add('filled');
            if (destIcon) destIcon.textContent = state.swapType === 'CASHOUT' ? '💵' : assetIcon(state.toAsset);
        } else { destEl.textContent = 'Not selected \u203a'; destEl.classList.remove('filled'); if (destIcon) destIcon.textContent = '🎯'; }
    }
}

function buildPayload() {
    const reference = 'SWAP_' + Date.now();
    const idempotencyKey = 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

    // Note: "My Card" (VMCARD) never reaches buildPayload() — it's
    // handled entirely by startVmCardSwap(), which creates a real
    // contribution session (cards/Create.php) instead of a normal swap
    // payload. See the comment above previewSwap() for why.

    if (state.swapType === 'MULTI_SOURCE') {
        const activeRows = state.multiSources.filter(s => s.institution && s.assetType);
        const sources = activeRows.map(s => {
            const pin = extractPinFromFields(s.assetType, s.fields);
            const identifierField = (ASSETS[s.assetType]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
            const assetFields = { ...s.fields };
            if (assetHasAmountField(s.assetType)) assetFields.amount = s.amount;
            return { institution: s.institution, asset_type: s.assetType, identifier: identifierField ? s.fields[identifierField.name] : null, amount: s.amount, wallet_pin: pin || undefined, pin: pin || undefined, asset_fields: assetFields };
        });
        const totalAmount = state.tabTotalAmount || sources.reduce((sum, s) => sum + s.amount, 0);
        const sourceCurrency = activeRows.length ? (PARTICIPANTS[activeRows[0].institution]?.limits?.currency || null) : null;
        const payload = { swap_type: 'MULTI_SOURCE', reference, idempotency_key: idempotencyKey, user_id: CONFIG.USER_ID, amount: totalAmount, currency: sourceCurrency, contribution_strategy: state.contributionStrategy, sources };
        if (state.contributionStrategy === 'USER_SPECIFIED') payload.user_amounts = buildUserAmountsPayload();
        if (state.multiDestMode === 'identity') {
            payload.identity_type = state.toIdentityType; payload.identity_value = state.toIdentityValue;
            if (state.toIdentitySms) payload.notification_phone = state.toIdentitySms;
            payload.destination_currency = sourceCurrency;
            return payload;
        }
        const destFields = { ...state.toFields };
        if (assetHasAmountField(state.toAsset)) destFields.amount = totalAmount;
        const destIdField = (ASSETS[state.toAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        const destCurrency = PARTICIPANTS[state.toInst]?.limits?.currency || sourceCurrency;
        payload.currency = payload.currency || destCurrency; payload.destination_currency = destCurrency;
        payload.to_institution = state.toInst; payload.destination_institution = state.toInst;
        payload.destination_asset_type = state.toAsset; payload.asset_type = state.toAsset;
        payload.destination_asset_fields = destFields;
        for (const [key, value] of Object.entries(destFields)) payload[`destination_${key}`] = value;
        if (destIdField) payload.destination_identifier = state.toFields[destIdField.name];
        return payload;
    }

    const pin = extractPinFromFields(state.fromAsset, state.fromFields);
    const sourceAssetFields = { ...state.fromFields };
    if (assetHasAmountField(state.fromAsset)) sourceAssetFields.amount = state.fromAmount;
    const sourceCurrency = PARTICIPANTS[state.fromInst]?.limits?.currency;
    const sourceIdField = (ASSETS[state.fromAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    const sourceIdentifier = sourceIdField ? state.fromFields[sourceIdField.name] : null;
    const sourceIdentifierType = sourceIdField ? (sourceIdField.name === 'phone' || sourceIdField.name === 'phone_number' ? 'phone' : 'account_number') : 'auto';
    const payload = { swap_type: state.swapType, reference, idempotency_key: idempotencyKey, user_id: CONFIG.USER_ID, from_institution: state.fromInst, source_institution: state.fromInst, asset_type: state.fromAsset, amount: state.fromAmount, currency: sourceCurrency, wallet_pin: pin || undefined, pin: pin || undefined, asset_fields: sourceAssetFields, ...sourceAssetFields, source_identifier: sourceIdentifier, source_identifier_type: sourceIdentifierType };

    if (state.swapType === 'IDENTITY') {
        payload.identity_type = state.toIdentityType; payload.identity_value = state.toIdentityValue;
        if (state.toIdentitySms) payload.notification_phone = state.toIdentitySms;
        payload.destination_currency = sourceCurrency;
        return payload;
    }
    if (state.swapType === 'CASHOUT') {
        payload.to_institution = state.toInst; payload.destination_institution = state.toInst;
        payload.delivery_method = state.deliveryMethod || 'ATM';
        payload.destination_currency = PARTICIPANTS[state.toInst]?.limits?.currency || sourceCurrency;
        const beneficiaryPhone = state.beneficiaryPhone || state.toFields?.phone || state.toFields?.recipient_phone || null;
        if (beneficiaryPhone) { payload.beneficiary_phone = beneficiaryPhone; payload.client_phone = beneficiaryPhone; }
        const destIdField = (ASSETS[state.toAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        if (state.toAsset === 'VOUCHER') {
            payload.destination_asset_type = 'VOUCHER';
            payload.destination_asset_fields = { voucher_type: 'CASHOUT', recipient_phone: beneficiaryPhone || state.toFields?.recipient_phone || null, voucher_number: state.toFields?.voucher_number || '' };
            payload.destination_identifier = beneficiaryPhone || state.toFields?.recipient_phone || state.toFields?.phone || null;
            payload.destination_identifier_type = 'phone';
        } else {
            payload.destination_asset_type = 'WALLET';
            const phone = state.toFields?.phone || state.toFields?.recipient_phone || beneficiaryPhone || null;
            payload.destination_asset_fields = { phone: phone };
            payload.destination_phone = phone; payload.destination_identifier = phone; payload.destination_identifier_type = 'phone';
        }
        if (destIdField) payload.destination_identifier = state.toFields[destIdField.name] || payload.destination_identifier;
        return payload;
    }
    payload.to_institution = state.toInst; payload.destination_institution = state.toInst;
    payload.destination_asset_type = state.toAsset;
    payload.destination_currency = PARTICIPANTS[state.toInst]?.limits?.currency || sourceCurrency;
    const destFields = { ...state.toFields };
    if (assetHasAmountField(state.toAsset)) destFields.amount = state.fromAmount;
    payload.destination_asset_fields = destFields;
    for (const [key, value] of Object.entries(destFields)) payload[`destination_${key}`] = value;
    payload.amount = state.fromAmount;
    const destIdField = (ASSETS[state.toAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    if (destIdField) {
        payload.destination_identifier = state.toFields[destIdField.name];
        if (state.toAsset === 'WALLET' || destIdField.name === 'phone' || destIdField.name === 'phone_number') payload.destination_identifier_type = 'phone';
        else if (state.toAsset === 'ACCOUNT' || destIdField.name === 'account_number' || destIdField.name === 'account') payload.destination_identifier_type = 'account_number';
        else payload.destination_identifier_type = destIdField.name || 'account';
    }
    return payload;
}

async function previewSwap() {
    const readiness = getSwapReadiness();
    if (!readiness.ready) {
        let msg = 'Before reviewing: ' + ((readiness.missingFields && readiness.missingFields.length > 0) ? readiness.missingFields.join('; ') : readiness.reasons.join(', '));
        showMessage(msg + '.', 'warning');
        return;
    }
    // ------------------------------------------------------------
    // "My Card" is not a normal preview → confirm → execute swap.
    // Verified against the real backend (PoolCoordinator::executeFromCardHook,
    // called only by CardContributionSessionService): swapping from a
    // card's hooked sources always goes through a CONTRIBUTION SESSION —
    // Create.php → (Contribute.php for Manual) → execute.php — the same
    // mechanism as the Card page's "Start a swap" button. There is no
    // separate one-shot "swap now from card" endpoint, so this delegates
    // to that real, already-working flow instead of a fictional preview.
    // ------------------------------------------------------------
    if (state.swapSourceMode === 'VMCARD') {
        await startVmCardSwap();
        return;
    }
    const payload = buildPayload();
    state.swapPayload = payload;
    const btn = document.getElementById('reviewBtn');
    const original = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>Calculating…';
    const result = await callApi(CONFIG.PREVIEW_ENDPOINT, payload);
    btn.disabled = false; btn.innerHTML = original;
    refreshUI();
    if (!result.ok) { showMessage('Preview failed: ' + friendlyApiError(result.error), 'error'); return; }
    showPreviewModal(result.body);
}

// Builds a contribution session (Create.php) from what's already filled
// in on the Swap screen, then jumps to the Card view where the existing
// renderSessionStatus()/startSessionPolling() UI (built earlier against
// the real API) tracks it through OPEN → READY → EXECUTING → COMPLETED.
async function startVmCardSwap() {
    const btn = document.getElementById('reviewBtn');
    const original = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>Starting…';

    let destination_identifier = null;
    if (state.swapType !== 'IDENTITY') {
        const destIdField = (ASSETS[state.toAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        destination_identifier = destIdField ? state.toFields[destIdField.name] : null;
    }
    const payload = {
        card_suffix: myCard.card_suffix,
        target_amount: state.fromAmount,
        currency: myCard.hook?.currency || myCard.currency,
        strategy: state.vmCardStrategy,
        to_institution: state.swapType === 'IDENTITY' ? undefined : state.toInst,
        destination_identifier: state.swapType === 'IDENTITY' ? undefined : destination_identifier,
        identity_type: state.swapType === 'IDENTITY' ? state.toIdentityType : undefined,
        identity_value: state.swapType === 'IDENTITY' ? state.toIdentityValue : undefined,
    };
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Create.php', payload);
    btn.disabled = false; btn.innerHTML = original;
    if (!result.ok) { showMessage("Couldn't start that swap: " + friendlyApiError(result.error), 'error'); return; }
    showMessage('Swap started — track it from the Card page.', 'success');
    goView('card');
}
function showPreviewModal(previewData) {
    const data = previewData.preview || {};
    const swapType = state.swapPayload.swap_type;
    const netAmount = data.net_amount_destination_currency || data.net_amount;
    const destCurrency = data.destination_currency || data.source_currency;
    const swapTypeLabel = { DEPOSIT: 'To an account', CASHOUT: 'Cash pickup', IDENTITY: 'To an identity', MULTI_SOURCE: 'Combined sources' }[swapType] || swapType.replace(/_/g, ' ');
    const bodyHtml = `
        <div class="review-hero"><div class="review-hero-label">You'll receive</div><div class="review-hero-amount">${formatMoney(netAmount, destCurrency)}</div><div class="review-hero-note">Live quote — locked in for a few minutes</div></div>
        <div class="preview-box">
            <div class="preview-row"><span>Swap type</span><span class="value">${escapeHtml(swapTypeLabel)}</span></div>
            <div class="preview-row"><span>From</span><span class="value">${escapeHtml(data.source_institution || '—')}</span></div>
            <div class="preview-row"><span>To</span><span class="value">${escapeHtml(data.destination_institution || '—')}</span></div>
            <div class="preview-row"><span>Amount</span><span class="value">${formatMoney(data.amount_requested, data.source_currency)}</span></div>
            <div class="preview-row"><span>Fee</span><span class="value">${formatMoney(data.total_fee, data.source_currency)}</span></div>
        </div>
        <div class="preview-security"><div class="preview-security-icon">🔒</div><div class="preview-security-text"><strong>Your money is protected</strong><p>This swap is encrypted end-to-end and only moves once you tap Confirm below. Nothing happens until then.</p></div></div>
        <div class="preview-reassure">Nothing is final until you confirm — you can still back out.</div>
        <div class="modal-actions"><button class="btn btn-secondary" onclick="closeModal()">Discard</button><button class="btn btn-primary" onclick="confirmSwap()">Confirm and swap</button></div>`;
    state.lastPreview = previewData;
    openModal('Review swap', bodyHtml);
}
async function confirmSwap() {
    closeModal();
    const payload = state.swapPayload;
    if (!payload) { showMessage('No swap payload to execute', 'error'); return; }
    const btn = document.getElementById('reviewBtn');
    const original = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>Executing…';
    const result = await callApi(CONFIG.EXECUTE_ENDPOINT, payload);
    btn.disabled = false; btn.innerHTML = original;
    refreshUI();
    if (!result.ok) { showMessage('Swap failed: ' + friendlyApiError(result.error), 'error'); return; }
    const journeyData = Journey.recordSwap(payload, state.lastPreview);
    renderRepeatCard(); renderProgressCard();
    showResultModal(result.body, journeyData);
}
function showResultModal(response, journeyData) {
    const data = response.data || {};
    const swapType = state.swapPayload.swap_type;
    const reference = response.swap_reference || data.reference || '—';
    let inner = '';
    if (swapType === 'CASHOUT') {
        inner = `<div class="result-box" id="resultBoxRoot"><div class="icon">✓</div><div class="result-title">Nice! Your cashout code is ready</div><div class="result-sub">Reference: ${escapeHtml(reference)}</div>${data.atm_code || data.voucher_number ? `<div class="atm-code"><div class="code">${escapeHtml(data.atm_code || data.voucher_number || '')}</div></div>` : ''}<div style="margin-top:12px;"><div style="font-size:22px;font-weight:600;font-family:var(--font-mono);">${formatMoney(data.amount ?? state.swapPayload.amount, state.swapPayload.currency)}</div></div>`;
    } else if (swapType === 'IDENTITY') {
        const claimPinBox = data.claim_pin ? `<div class="atm-code" style="margin-top:12px;"><div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Backup PIN — only share this if the recipient doesn't get the SMS</div><div class="code">${escapeHtml(data.claim_pin)}</div></div>` : '';
        inner = `<div class="result-box" id="resultBoxRoot"><div class="icon">✓</div><div class="result-title">Sent! They'll be notified now</div><div class="result-sub">Reference: ${escapeHtml(reference)}</div><div style="margin:12px 0;font-size:13px;"><strong>${escapeHtml(data.identity_type || state.swapPayload.identity_type)}: ${escapeHtml(data.identity_value || state.swapPayload.identity_value)}</strong></div><div style="font-size:22px;font-weight:600;font-family:var(--font-mono);">${formatMoney(data.amount ?? state.swapPayload.amount, data.currency || state.swapPayload.currency)}</div>${claimPinBox}`;
    } else {
        inner = `<div class="result-box" id="resultBoxRoot"><div class="icon">✓</div><div class="result-title">Nice! Your money's on its way</div><div class="result-sub">Reference: ${escapeHtml(reference)}</div><div style="font-size:22px;font-weight:600;font-family:var(--font-mono);margin-top:12px;">${formatMoney(data.amount ?? state.swapPayload.amount, data.currency || data.destination_currency || state.swapPayload.destination_currency || state.swapPayload.currency)}</div>`;
    }
    const swapCount = journeyData?.swapsThisMonth || 1;
    const statLine = swapCount > 1 ? `<div class="result-stat">You've made ${swapCount} swaps this month. You've got the hang of this. 🎉</div>` : `<div class="result-stat">That's your swap — nicely done. It'll show up in Activity any time you want to check on it.</div>`;
    openModal('Swap result', `${inner}${statLine}<div class="cta-row" style="margin-top:16px;"><button class="btn btn-primary" onclick="closeModal(); goView('hub');">Done</button></div></div>`);
    setTimeout(() => fireConfetti(document.getElementById('resultBoxRoot')), 150);
}

const IDENTITY_TYPE_LABELS = { national_id: 'National ID', birth_certificate: 'Birth Certificate', voter_id: 'Voter ID', phone: 'Phone Number', email: 'Email' };
function getInstitutionCurrency(instCode) { if (!instCode) return null; return PARTICIPANTS[instCode]?.limits?.currency || null; }
function updateCurrencyDisplay() {
    const fromCurrency = state.swapSourceMode === 'VMCARD' ? (myCard?.hook?.currency || myCard?.currency) : getInstitutionCurrency(state.fromInst);
    const fromLabel = document.getElementById('fromCurrencyLabel');
    if (fromLabel) fromLabel.textContent = fromCurrency || '';
    const preview = document.getElementById('amountPreview');
    if (preview) preview.textContent = formatMoney(state.fromAmount, fromCurrency);
}
function switchCountry(country) { if (country !== CONFIG.COUNTRY_CODE) window.location.href = '?country=' + encodeURIComponent(country); }

function openDestinationModal() {
    if (state.swapType !== 'DEPOSIT' && state.swapType !== 'CASHOUT') setSwapType('DEPOSIT');
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = '';
    modalBody.appendChild(document.getElementById('toSection'));
    const btnRow = document.createElement('div');
    btnRow.className = 'cta-row'; btnRow.style.marginTop = '20px';
    btnRow.innerHTML = '<button type="button" class="btn btn-primary" onclick="confirmDestinationSelection()">Use this destination</button>';
    modalBody.appendChild(btnRow);
    document.getElementById('modalTitle').textContent = 'Select a destination';
    document.getElementById('modal').classList.add('active');
}
function confirmDestinationSelection() {
    if (!state.toInst) { showMessage('Select a destination institution first.', 'warning'); return; }
    if (state.swapType === 'CASHOUT') {
        const beneficiaryPhone = state.beneficiaryPhone || state.toFields?.phone || state.toFields?.recipient_phone;
        if (!beneficiaryPhone) { showMessage('Enter the beneficiary phone number for cashout.', 'warning'); return; }
    } else if (!state.toAsset || !fieldsValidForAsset(state.toAsset, state.toFields, false).valid) {
        showMessage('Finish selecting the destination asset type and required fields.', 'warning'); return;
    }
    closeModal();
}
function openIdentitySendModal() {
    setSwapType('IDENTITY');
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = '';
    const identityFieldsEl = document.getElementById('identityFields');
    modalBody.appendChild(identityFieldsEl);
    identityFieldsEl.style.display = 'block';
    const btnRow = document.createElement('div');
    btnRow.className = 'cta-row'; btnRow.style.marginTop = '20px';
    btnRow.innerHTML = '<button type="button" class="btn btn-primary" onclick="confirmIdentitySelection()">Use this identity</button>';
    modalBody.appendChild(btnRow);
    document.getElementById('modalTitle').textContent = 'Swap to identity';
    document.getElementById('modal').classList.add('active');
}
function confirmIdentitySelection() {
    if (!state.toIdentityValue) { showMessage('Enter the identity value to swap to.', 'warning'); return; }
    closeModal();
}

async function viewWalletBalance() {
    openModal('Balances', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading balances...</div>');
    const result = await fetchAllBalances();
    if (!result.success) {
        document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);"><div style="font-weight:700;">Couldn't load your balances</div><div style="font-size:12px;color:var(--text-muted);margin-top:8px;">${escapeHtml(friendlyApiError(result.error))}</div><button class="btn btn-primary btn-sm" onclick="viewWalletBalance()" style="margin-top:12px;">Retry</button></div>`;
        return;
    }
    const data = result.data || {}; const sources = data.sources || []; const totals = data.total || {};
    if (sources.length === 0) {
        document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:30px;color:var(--text-muted);"><div style="font-weight:700;">No sources linked yet</div><div style="font-size:12px;margin-top:8px;">Add a source to see your balance</div><button class="btn btn-primary btn-sm" onclick="closeModal();goView('toolbox');" style="margin-top:12px;">Add source</button></div>`;
        return;
    }
    let html = `<div style="margin-bottom:16px;"><div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">Your total balance across all linked sources</div>`;
    if (Object.keys(totals).length > 0) {
        html += `<div style="background:var(--primary);color:#fff;padding:16px;margin-bottom:12px;">`;
        Object.keys(totals).forEach(cur => { html += `<div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:12px;opacity:0.7;">Total ${cur}</span><span style="font-size:22px;font-weight:600;font-family:var(--font-mono);">${formatMoney(totals[cur], cur)}</span></div>`; });
        html += `</div>`;
    }
    html += `<div style="max-height:50vh;overflow-y:auto;">`;
    sources.forEach(item => {
        const source = item.source, balance = item.balance;
        const instName = PARTICIPANTS[source.institution]?.name || source.institution;
        const assetLabel = ASSETS[source.asset_type]?.label || source.asset_type;
        let balanceDisplay = '—';
        if (balance.success) balanceDisplay = formatMoney(balance.balance, balance.currency);
        else balanceDisplay = `<span style="color:var(--text-muted);font-size:12px;">${escapeHtml(friendlyApiError(balance.error) || 'Unavailable')}</span>`;
        html += `<div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;"><div style="display:flex;align-items:center;gap:10px;"><span class="row-icon" style="font-size:18px;">${assetIcon(source.asset_type)}</span><div><div style="font-weight:700;">${escapeHtml(instName)}</div><div style="font-size:12px;color:var(--text-muted);">${escapeHtml(assetLabel)} · ${escapeHtml(source.identifier)}${source.account_name ? ` · ${escapeHtml(source.account_name)}` : ''}</div></div></div><div style="text-align:right;"><div style="font-size:16px;font-weight:600;font-family:var(--font-mono);">${balanceDisplay}</div><div style="font-size:10px;color:var(--text-dim);text-transform:uppercase;">${source.status}</div></div></div><div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;"><button class="btn-primary btn-sm" onclick="refreshSourceBalance('${source.id}')">Refresh</button></div></div>`;
    });
    html += `</div><div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;"><button class="btn btn-primary btn-sm" onclick="refreshAllBalances()">Refresh all</button><button class="btn btn-secondary btn-sm" onclick="closeModal();goView('toolbox');">Add source</button><button class="btn btn-secondary btn-sm" onclick="closeModal()">Close</button></div>`;
    document.getElementById('modalBody').innerHTML = html;
}
async function refreshSourceBalance(sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    if (!source) { showMessage('Source not found', 'error'); return; }
    showMessage(`Fetching balance for ${source.institution}...`, 'info');
    const result = await fetchBalance(source.institution, source.identifier, source.identifier_type);
    if (result.success) { showMessage(`Balance updated: ${formatMoney(result.data?.balance, result.data?.currency)}`, 'success'); viewWalletBalance(); }
    else { showMessage(`Couldn't fetch that balance: ${friendlyApiError(result.error)}`, 'error'); viewWalletBalance(); }
}
async function refreshAllBalances() { showMessage('Refreshing all balances...', 'info'); await viewWalletBalance(); }
async function fetchBalance(institution, identifier, identifierType = 'auto') {
    try {
        const response = await fetch(CONFIG.API_BASE + '/api/v1/user/balance.php', { method: 'POST', headers: buildHeaders(), body: JSON.stringify({ institution, identifier, identifier_type: identifierType }), credentials: 'include' });
        return await response.json();
    } catch (error) { console.error('Balance fetch error:', error); return { success: false, error: error.message }; }
}
async function fetchAllBalances() {
    try {
        const response = await fetch(CONFIG.API_BASE + '/api/v1/user/all_balances.php', { method: 'GET', headers: buildHeaders(), credentials: 'include' });
        return await response.json();
    } catch (error) { console.error('Fetch all balances error:', error); return { success: false, error: error.message }; }
}

async function loadUserSources() {
    if (!CONFIG.USER_ID) return;
    const result = await callApi(CONFIG.API_BASE + '/user/sources.php', {});
    if (result.ok) {
        userSources = result.body.data?.sources || [];
        if (sourcePanelOpenCat === 'WALLET' || state.fromCategory === 'WALLET') renderSavedSourceChips();
        renderProgressCard();
    }
    const pendingResult = await callApi(CONFIG.API_BASE + '/api/v1/sources/pending.php', {});
    if (pendingResult.ok) { pendingSources = pendingResult.body.data || []; updateToolboxBadge(); }
}
function walletEligibleSources() {
    return userSources.filter(s => {
        if (s.status !== 'active') return false;
        const assetType = String(s.asset_type).toUpperCase();
        const config = getAssetConfig(s.asset_type);
        const category = config?.category || '';
        return assetType === 'ACCOUNT' || assetType === 'WALLET' || assetType === 'MNO-WALLET' || assetType === 'BANK-WALLET' || assetType === 'MOBILE_WALLET' || category === 'MOBILE_MONEY' || category === 'BANK_WALLET' || category === 'BANK_ACCOUNT';
    });
}
function renderSavedSourceChips() {
    const container = document.getElementById('savedSourcesContainer');
    const chipsContainer = document.getElementById('savedSourcesChips');
    const clearBtn = document.getElementById('clearSourceBtn');
    const emptyPrompt = document.getElementById('noSourcesPrompt');
    if (!container || !chipsContainer) return;
    const activeSources = walletEligibleSources();
    if (activeSources.length === 0) { container.style.display = 'none'; emptyPrompt.style.display = 'block'; return; }
    emptyPrompt.style.display = 'none'; container.style.display = 'block';
    chipsContainer.innerHTML = activeSources.map(source => {
        const instName = PARTICIPANTS[source.institution]?.name || source.institution;
        const identifier = source.identifier || source.source_identifier || '';
        const isSelected = selectedSourceId === source.id;
        return `<div class="saved-source-row ${isSelected ? 'active' : ''}" onclick="selectSavedSource('${source.id}')"><div class="row-main"><div class="row-inst">${assetIcon(source.asset_type)} ${escapeHtml(instName)}</div><div class="row-ident">${escapeHtml(identifier)}${source.account_name ? ' · ' + escapeHtml(source.account_name) : ''}</div></div><span class="row-check">✓</span></div>`;
    }).join('');
    clearBtn.style.display = selectedSourceId ? 'inline-flex' : 'none';
}
function selectSavedSource(sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    if (!source) { showMessage('Source not found.', 'error'); return; }
    selectedSourceId = sourceId;
    state.fromInst = source.institution; state.fromAsset = source.asset_type; state.fromFields = {};
    const inst = PARTICIPANTS[source.institution];
    document.getElementById('fromLimitsHelp').textContent = inst?.limits ? `Limits: ${inst.limits.min_amount} – ${inst.limits.max_amount} ${inst.limits.currency}` : '';
    const fieldsBox = document.getElementById('fromFields');
    fieldsBox.style.display = 'block'; fieldsBox.innerHTML = '';
    renderDynamicFields('fromFields', source.asset_type, 'fromField_', updateFromField, true);
    setTimeout(() => {
        const assetConfig = getAssetConfig(source.asset_type);
        if (!assetConfig) return;
        const fields = assetConfig.fields || [];
        const identifier = source.identifier || source.source_identifier || '';
        const identifierField = fields.find(f => f.vault_field !== 'pin' && f.name !== 'amount' && (f.name === 'account_number' || f.name === 'identifier' || f.name === 'account' || f.name === 'phone_number' || f.name === 'phone' || f.name === 'card_number' || f.name === 'wallet_account' || f.name === 'wallet_address' || f.name === 'order_number' || f.name === 'cheque_number' || f.name === 'atm_code' || f.name === 'voucher_number' || f.name === 'source_identifier' || f.name === 'wallet_id'));
        const pinField = fields.find(f => f.vault_field === 'pin');
        document.querySelectorAll('#fromFields input').forEach(input => { if (!input.disabled) input.value = ''; input.style.background = '#fff'; input.style.color = 'var(--text)'; });
        document.querySelectorAll('#fromFields input[disabled]').forEach(input => { input.disabled = false; input.style.background = '#fff'; input.style.color = 'var(--text)'; });
        const newFields = {};
        if (identifierField) {
            const input = document.getElementById(`fromField_${identifierField.name}`);
            if (input) { input.value = identifier; newFields[identifierField.name] = identifier; input.disabled = true; input.style.background = 'var(--surface-muted)'; input.style.color = 'var(--text-dim)'; const helpText = input.parentElement?.querySelector('.help'); if (helpText) { helpText.textContent = 'Auto-filled from your saved source'; helpText.style.color = 'var(--accent)'; } }
            else newFields[identifierField.name] = identifier;
        }
        if (pinField) {
            const pin = source.pin || source.source_pin || '';
            if (pin) { const pinInput = document.getElementById(`fromField_${pinField.name}`); if (pinInput) { pinInput.value = pin; newFields[pinField.name] = pin; pinInput.disabled = true; pinInput.style.background = 'var(--surface-muted)'; pinInput.style.color = 'var(--text-dim)'; } else newFields[pinField.name] = pin; }
            else { const pinInput = document.getElementById(`fromField_${pinField.name}`); if (pinInput) { pinInput.placeholder = 'PIN (optional)'; pinInput.style.borderColor = 'var(--border)'; } }
        }
        state.fromFields = newFields; refreshUI();
    }, 200);
    const helpEl = document.getElementById('sourceSelectedHelp');
    if (helpEl) { helpEl.style.display = 'block'; const config = getAssetConfig(source.asset_type); helpEl.textContent = `${config?.label || source.asset_type} selected: ${inst?.name || source.institution} — ${source.identifier || ''}. Enter the amount below.`; }
    setTimeout(() => {
        document.getElementById('fromAmount')?.focus();
        showMessage(`${getAssetConfig(source.asset_type)?.label || source.asset_type} selected: ${inst?.name || source.institution}`, 'success');
        const existingHookLink = document.getElementById('hookToCardEntryPoint');
        if (existingHookLink) existingHookLink.remove();
        document.getElementById('sourceSelectedHelp')?.insertAdjacentHTML('afterend', `<div id="hookToCardEntryPoint" style="margin-top:8px;"><span class="quick-link muted" onclick="hookSelectedSourceToCard()">Hook this source to a VouchMorph Card instead →</span></div>`);
        refreshUI();
    }, 300);
    updateCurrencyDisplay(); refreshUI();
}
function clearSourceSelection() {
    selectedSourceId = null; state.fromInst = null; state.fromAsset = null; state.fromFields = {};
    document.getElementById('fromFields').innerHTML = ''; document.getElementById('fromFields').style.display = 'none';
    const helpEl = document.getElementById('sourceSelectedHelp'); if (helpEl) helpEl.style.display = 'none';
    const amountField = document.getElementById('fromAmount'); if (amountField) { amountField.value = ''; amountField.focus(); }
    showMessage('Source selection cleared.', 'info');
    updateCurrencyDisplay(); renderSavedSourceChips(); refreshUI();
}
function useSourceForSwap(sourceId) {
    goView('swap');
    setSwapSourceMode('WALLET');
    setTimeout(() => { selectSavedSource(sourceId); }, 200);
}

function openAddSource() {
    const instOptions = Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('');
    const body = `
        <div style="margin-bottom:16px;"><div style="font-size:13px;font-weight:700;margin-bottom:4px;">Link a new source</div><div style="font-size:12px;color:var(--text-muted);">Your bank will verify ownership via OTP or OAuth.</div></div>
        <div class="field-group"><label>Institution</label><select id="addSourceInst" onchange="onAddSourceInstChange(this.value)"><option value="">Select institution</option>${instOptions}</select></div>
        <div class="field-group" id="addSourceAssetGroup" style="display:none;"><label>Asset type</label><select id="addSourceAssetType" onchange="onAddSourceAssetTypeChange(this.value)"></select><div class="help">Users can only add Accounts, Wallets, or Cards.</div></div>
        <div class="field-group"><label>Identifier</label><input id="addSourceIdentifier" placeholder="Account number, phone, or card number"><div class="help" id="addSourceIdentifierHelp">The number that identifies your account at this institution.</div></div>
        <div class="field-group"><label>Account name (optional)</label><input id="addSourceAccountName" placeholder="e.g. My Main Account"></div>
        <div style="background:var(--accent-soft);border-left:3px solid var(--accent);padding:10px 14px;font-size:12px;margin-bottom:14px;color:var(--primary);">🔒 Your details are encrypted and only used to verify you own this account — VouchMorph never stores your bank password.</div>
        <div id="addSourceOtpFields" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);"><div style="font-size:12px;font-weight:700;margin-bottom:8px;">Verify with OTP</div><div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;" id="otpMessage">A verification code has been sent to your registered phone.</div><div class="otp-input-group"><input type="text" id="addSourceOtp" placeholder="Enter code" inputmode="numeric" maxlength="8"><button class="btn btn-primary btn-sm" onclick="completeSourceOtp()">Verify</button></div></div>
        <div class="cta-row"><button class="btn btn-secondary" onclick="loadToolboxView()">Cancel</button><button class="btn btn-primary" id="addSourceSubmitBtn" onclick="submitAddSource()">Link source</button></div>`;
    openModal('Add source — step 1 of 2: Link', body);
}
let addSourceState = { attemptId: null, requiresOtp: false, requiresRedirect: false, redirectUrl: null, institution: null, assetType: null, identifier: null };
function onAddSourceInstChange(code) {
    const group = document.getElementById('addSourceAssetGroup'); const sel = document.getElementById('addSourceAssetType');
    if (!code) { group.style.display = 'none'; sel.innerHTML = ''; return; }
    const inst = PARTICIPANTS[code]; const allTypes = inst?.asset_types || [];
    const eligible = allTypes.filter(t => ['ACCOUNT', 'WALLET', 'CARD', 'MNO-WALLET', 'BANK-WALLET'].includes(String(t).toUpperCase()));
    if (eligible.length === 0) { sel.innerHTML = `<option value="">This institution doesn't support self-service linking yet</option>`; group.style.display = 'block'; return; }
    sel.innerHTML = eligible.map(t => `<option value="${t}">${assetIcon(t)} ${getAssetConfig(t)?.label || t}</option>`).join('');
    group.style.display = 'block'; onAddSourceAssetTypeChange(eligible[0]);
}
function onAddSourceAssetTypeChange(type) {
    const idInput = document.getElementById('addSourceIdentifier'); const help = document.getElementById('addSourceIdentifierHelp');
    if (!idInput) return;
    const t = String(type).toUpperCase();
    const copy = { 'ACCOUNT': ['e.g. 0011223344', 'Your bank account number.'], 'CARD': ['e.g. 16-digit card number', 'The number on the front of your card.'], 'WALLET': ['e.g. +267 71 234 567', 'The phone number your wallet is registered to.'], 'MNO-WALLET': ['e.g. +267 71 234 567', 'The phone number your mobile wallet is registered to.'], 'BANK-WALLET': ['e.g. 0011223344', 'Your bank wallet account number.'] };
    const [ph, h] = copy[t] || ['Account number, phone, or card number', 'The number that identifies your account at this institution.'];
    idInput.placeholder = ph; if (help) help.textContent = h;
}
async function submitAddSource() {
    const institution = document.getElementById('addSourceInst').value;
    const assetType = document.getElementById('addSourceAssetType').value;
    const identifier = document.getElementById('addSourceIdentifier').value.trim();
    const accountName = document.getElementById('addSourceAccountName').value.trim();
    if (!institution) { showMessage('Select an institution.', 'warning'); return; }
    if (!assetType) { showMessage('Select an asset type.', 'warning'); return; }
    if (!identifier) { showMessage('Enter your account identifier.', 'warning'); return; }
    const btn = document.getElementById('addSourceSubmitBtn'); const original = btn.textContent;
    btn.disabled = true; btn.textContent = 'Registering...';
    const result = await callApi(CONFIG.API_BASE + '/user/add_source.php', { institution, asset_type: assetType, identifier, account_name: accountName || undefined });
    btn.disabled = false; btn.textContent = original;
    if (!result.ok) { showMessage('Could not add that source: ' + friendlyApiError(result.error), 'error'); return; }
    const data = result.body.data || {};
    addSourceState = { attemptId: data.attempt_id || null, requiresOtp: data.requires_otp || false, requiresRedirect: data.requires_redirect || false, redirectUrl: data.redirect_url || null, institution, assetType, identifier };
    if (data.requires_redirect) { showMessage('Redirecting to your bank for verification...', 'info'); setTimeout(() => { window.location.href = data.redirect_url; }, 1500); return; }
    if (data.requires_otp) {
        document.getElementById('modalTitle').textContent = 'Add source — step 2 of 2: Verify';
        document.getElementById('addSourceOtpFields').style.display = 'block';
        document.getElementById('otpMessage').textContent = data.message || 'A verification code has been sent to your registered phone.';
        document.getElementById('addSourceSubmitBtn').style.display = 'none';
        showMessage('OTP sent! Enter the code to verify.', 'success'); return;
    }
    showMessage(data.message || 'Source added!', 'success');
    setTimeout(() => { loadUserSources(); loadToolboxView(); }, 1500);
}
async function completeSourceOtp() {
    const otp = document.getElementById('addSourceOtp').value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    if (!addSourceState.attemptId) { showMessage('No pending verification attempt.', 'error'); return; }
    const btn = document.querySelector('#addSourceOtpFields .btn-primary'); const original = btn.textContent;
    btn.disabled = true; btn.textContent = 'Verifying...';
    const result = await callApi(CONFIG.API_BASE + '/user/verify_source.php', { attempt_id: addSourceState.attemptId, otp, user_id: CONFIG.USER_ID });
    btn.disabled = false; btn.textContent = original;
    if (!result.ok) { showMessage('That code didn\'t work: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage('Source verified and activated! 🎉', 'success');
    setTimeout(() => { loadUserSources(); loadToolboxView(); }, 1500);
}
async function removeSource(sourceId) {
    showConfirm('Remove this source? You can add it again later.', async () => {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/delete.php', { source_id: sourceId });
        if (!result.ok) { showMessage('Could not remove that source: ' + friendlyApiError(result.error), 'error'); return; }
        showMessage('Source removed.', 'success');
        loadUserSources(); loadToolboxView();
    });
}
function openPendingSources() { openModal('Pending sources', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading pending sources...</div>'); loadPendingSources(); }
async function loadPendingSources() {
    const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/pending.php', {});
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);"><div style="font-weight:700;">Couldn't load pending sources</div><div style="font-size:12px;color:var(--text-muted);margin-top:8px;">${escapeHtml(friendlyApiError(result.error))}</div><button class="btn btn-primary btn-sm" onclick="loadPendingSources()" style="margin-top:12px;">Retry</button></div>`; return; }
    pendingSources = result.body.data || [];
    renderPendingSources();
}
function renderPendingSources() {
    const container = document.getElementById('modalBody');
    if (!pendingSources || pendingSources.length === 0) { container.innerHTML = `<div style="text-align:center;padding:30px;color:var(--text-muted);"><div style="font-weight:700;font-size:16px;">No pending sources</div><div style="font-size:12px;margin-top:8px;">All your sources are active and verified.</div></div>`; return; }
    let html = `<div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">${pendingSources.length} source(s) pending verification. Pending sources expire after 3 minutes if not completed.</div><div style="max-height:60vh;overflow-y:auto;">`;
    pendingSources.forEach((source) => {
        const statusLabel = getSourceStatusLabel(source), statusClass = getSourceStatusClass(source), sourceTypeLabel = getSourceTypeLabel(source);
        const canDelete = ['pending_confirmation', 'pending', 'proposed', 'otp_pending', 'oauth_pending'].includes(source.status);
        const canRetry = ['cancelled', 'rejected', 'failed'].includes(source.status);
        const isExpiring = source.is_expiring || false;
        html += `<div class="source-card" style="border-left: 3px solid ${isExpiring ? 'var(--warning)' : 'var(--border)'};"><div class="source-header"><div><div class="source-institution">${escapeHtml(source.institution_name || source.institution)}<span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:6px;">(${sourceTypeLabel})</span></div><div class="source-details" style="margin-top:4px;"><span style="font-weight:600;">${escapeHtml(source.identifier)}</span>${source.account_name ? ` · ${escapeHtml(source.account_name)}` : ''}${source.asset_type ? ` · ${source.asset_type}` : ''}</div></div><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><span class="source-status ${statusClass}">${statusLabel}</span>${isExpiring ? `<span style="font-size:10px;color:var(--warning);font-weight:700;">Expiring soon</span>` : ''}${canDelete ? `<button class="btn-danger-outline" onclick="deletePendingSource('${source.type}', ${source.id})">Delete</button>` : ''}${canRetry ? `<button class="btn-primary btn-sm" onclick="retryPendingSource('${source.type}', ${source.id})">Retry</button>` : ''}</div></div>${source.error_message ? `<div style="font-size:11px;color:var(--danger);margin-top:4px;">${escapeHtml(friendlyApiError(source.error_message))}</div>` : ''}</div>`;
    });
    html += `</div>`;
    container.innerHTML = html;
}
function getSourceStatusLabel(source) { return { pending_confirmation: 'Pending Confirmation', pending: 'Pending', proposed: 'Proposed', otp_pending: 'OTP Verification', oauth_pending: 'OAuth Verification', cancelled: 'Cancelled', rejected: 'Rejected', failed: 'Failed', expired: 'Expired' }[source.status] || source.status; }
function getSourceStatusClass(source) { return { pending_confirmation: 'pending', pending: 'pending', proposed: 'pending', otp_pending: 'pending', oauth_pending: 'pending', cancelled: 'inactive', rejected: 'inactive', failed: 'inactive', expired: 'inactive' }[source.status] || 'pending'; }
function getSourceTypeLabel(source) { return { user_source: 'Source', agent_destination: 'Agent Destination', registration_attempt: 'Verification Attempt', agent_attempt: 'Agent Verification' }[source.type] || source.type; }
async function deletePendingSource(type, sourceId) {
    showConfirm('Delete this pending source? It can be re-added later.', async () => {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/delete.php', { type, source_id: sourceId });
        if (!result.ok) { showMessage('Could not delete that source: ' + friendlyApiError(result.error), 'error'); return; }
        showMessage(result.body.data?.message || 'Source deleted successfully.', 'success');
        loadPendingSources();
    });
}
async function retryPendingSource(type, sourceId) {
    showConfirm('Retry this source? This will start a new verification attempt.', async () => {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/retry.php', { type, source_id: sourceId });
        if (!result.ok) { showMessage('Could not retry that source: ' + friendlyApiError(result.error), 'error'); return; }
        const data = result.body.data || {};
        if (data.requires_redirect) { showMessage(data.message || 'Redirecting to bank...', 'info'); setTimeout(() => { window.location.href = data.redirect_url; }, 1500); return; }
        if (data.requires_otp) { showMessage(data.message || 'OTP sent. Enter the code to verify.', 'success'); loadPendingSources(); return; }
        showMessage(data.message || 'Source retry initiated successfully.', 'success');
        loadPendingSources();
    });
}

// ============================================================
// TOOLBOX — persistent view. "My sources" rows each carry a
// "Hook to card" quick action (source-side hook entry, alongside
// the card-side entry from the Card view itself).
// ============================================================
async function loadToolboxView() {
    const body = document.getElementById('toolboxViewBody');
    body.innerHTML = `<div style="text-align:center;padding:40px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading...</div>`;
    await getCurrentUserRole();
    await loadUserSources();
    body.innerHTML = renderToolboxBody();
}
function renderToolboxBody() {
    const claimCount = pendingClaims.length;
    const isAgent = !!(SessionUser && SessionUser.is_agent);
    const steps = computeProgressSteps();
    const doneCount = steps.filter(s => s.done).length;

    const sourcesHtml = userSources.length === 0
        ? `<div style="font-size:13px;color:var(--text-dim);padding:8px 0;">No sources linked yet.</div>`
        : userSources.map(source => {
            const isActive = source.status === 'active';
            return `<div class="myc-source-row" data-search="${escapeHtml((PARTICIPANTS[source.institution]?.name || source.institution) + ' ' + (source.identifier || ''))}">
                <div class="myc-source-tile">${escapeHtml(institutionInitials(source.institution))}</div>
                <div class="myc-source-info"><div class="myc-source-inst">${escapeHtml(PARTICIPANTS[source.institution]?.name || source.institution)}</div><div class="myc-source-ident">${escapeHtml(ASSETS[source.asset_type]?.label || source.asset_type)} · ${escapeHtml(source.identifier || source.source_identifier || '')}</div></div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                    ${isActive ? `<button class="unhook-link" style="color:var(--accent);" onclick="useSourceForSwap('${source.id}')">Use</button>` : `<span class="source-status-badge expired">${escapeHtml(source.status)}</span>`}
                    ${isActive ? `<button class="unhook-link" style="color:var(--text-muted);" onclick="openHookBuilder('source', {instName: '${escapeHtml(PARTICIPANTS[source.institution]?.name || source.institution)}', institution: '${source.institution}', assetType: '${source.asset_type}', identifier: '${escapeHtml(source.identifier || source.source_identifier || '')}'})">Hook to card &rsaquo;</button>` : ''}
                </div>
            </div>`;
        }).join('');

    const identityClaimAvailable = pendingClaims.length > 0;
    const identityClaimHtml = identityClaimAvailable ? pendingClaims.map(c => `
        <div class="myc-source-row" data-search="identity claim">
            <div class="myc-source-tile">ID</div>
            <div class="myc-source-info"><div class="myc-source-inst">Identity claim</div><div class="myc-source-ident">${formatMoney(c.amount, c.currency)} pending — finalize to unlock</div></div>
            <button class="unhook-link" style="color:var(--text-muted);" onclick="showMessage('Finalize this identity swap first (Toolbox → Finalize identity swap), then it becomes hookable.', 'info')">Hook to card &rsaquo;</button>
        </div>`).join('') : '';

    const groups = [
        { title: 'Money', open: true, rows: [
            { icon: '💰', label: 'View balance', action: 'viewWalletBalance()' },
            { icon: '➕', label: 'Add source', action: 'openAddSource()' },
            { icon: '📋', label: 'Pending sources', badge: pendingSources.length > 0 ? pendingSources.length : null, action: 'openPendingSources()' },
        ]},
        { title: 'Identity', open: claimCount > 0, rows: [
            { icon: '📥', label: 'Finalize identity swap', badge: claimCount > 0 ? claimCount : null, action: isAgent ? 'openAgentFinalizeIdentityModal()' : 'openFinalizeIdentityModal()' },
            { icon: '🪪', label: 'Register identity', action: 'openAddIdentityModal()' },
        ]},
    ];
    if (isAgent) groups.push({ title: 'Agent', open: false, rows: [ { icon: '🧰', label: 'Agent tools', action: 'openAgentToolsModal()' }, { icon: '🏢', label: 'Agent destinations', action: 'openAgentModal()' } ] });
    groups.push({ title: 'Account', open: false, rows: [ { icon: '👤', label: 'My profile', action: 'openProfileModal()' }, { icon: '❓', label: 'Help', action: 'openHelpModal()' }, { icon: '📄', label: 'Terms and conditions', action: 'openTermsModal()' } ] });

    const progressHtml = doneCount < steps.length ? `<div style="background:var(--surface-muted);border:1px solid var(--border);padding:14px 16px;margin-bottom:20px;"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-dim);margin-bottom:8px;">Getting set up — ${doneCount}/${steps.length}</div>${steps.map(s => `<div style="font-size:13px;color:${s.done ? 'var(--success)' : 'var(--text-muted)'};padding:3px 0;">${s.done ? '✓' : '○'} ${s.label}</div>`).join('')}</div>` : '';

    // Each group is a native <details> accordion so the page doesn't
    // scroll forever — only "Money" (and "Identity" when something's
    // waiting) start open. data-search carries a plain-text label per
    // row so the search box above can filter without re-rendering.
    const groupsHtml = groups.map((g, gi) => `
        <details class="toolbox-accordion" ${g.open ? 'open' : ''} data-group-idx="${gi}">
            <summary class="toolbox-accordion-summary">${g.title}<span class="toolbox-accordion-arrow">&rsaquo;</span></summary>
            <div class="toolbox-list">${g.rows.map(r => `<div class="toolbox-row" data-search="${escapeHtml(g.title + ' ' + r.label)}" onclick="${r.action}"><span class="toolbox-row-icon">${r.icon || ''}</span><span class="toolbox-row-label">${r.label}</span>${r.badge ? `<span class="toolbox-badge" style="display:inline-flex;">${r.badge}</span>` : ''}</div>`).join('')}</div>
        </details>`).join('');

    return `
        ${progressHtml}
        <div class="field-group" style="margin-bottom:20px;">
            <input id="toolboxSearchInput" placeholder="Search sources, tools, activity…" oninput="filterToolbox(this.value)" style="font-size:15px;padding:14px 16px;">
        </div>
        <details class="toolbox-accordion" open data-group-idx="sources">
            <summary class="toolbox-accordion-summary">My sources<span class="toolbox-accordion-arrow">&rsaquo;</span></summary>
            <div class="myc-panel" style="margin-top:10px;">${sourcesHtml}${identityClaimHtml}</div>
        </details>
        ${groupsHtml}
        <details class="toolbox-accordion" data-group-idx="theme">
            <summary class="toolbox-accordion-summary">Theme<span class="toolbox-accordion-arrow">&rsaquo;</span></summary>
            <div class="myc-panel" style="margin-top:10px;">
                <div class="theme-picker">
                    <div class="theme-chip" data-theme="classic" onclick="setTheme('classic')"><div class="swatch"><div class="dot" style="background:#10201C;"></div><div class="dot" style="background:#00A878;"></div></div>Classic</div>
                    <div class="theme-chip" data-theme="women" onclick="setTheme('women')"><div class="swatch"><div class="dot" style="background:#7A3B57;"></div><div class="dot" style="background:#E08FA4;"></div></div>Women</div>
                    <div class="theme-chip" data-theme="kids" onclick="setTheme('kids')"><div class="swatch"><div class="dot" style="background:#1E3A8A;"></div><div class="dot" style="background:#FFB400;"></div></div>Kids</div>
                    <div class="theme-chip" data-theme="alpha" onclick="setTheme('alpha')"><div class="swatch"><div class="dot" style="background:#0A0A0A;"></div><div class="dot" style="background:#D6242C;"></div></div>Alpha</div>
                </div>
            </div>
        </details>`;
}
// Filters rows by their data-search text; auto-opens any accordion
// that still has a visible match so results are never hidden inside
// a collapsed section, and shows a plain "no matches" note otherwise.
function filterToolbox(query) {
    const q = query.trim().toLowerCase();
    const accordions = document.querySelectorAll('#toolboxViewBody .toolbox-accordion');
    let anyMatch = false;
    accordions.forEach(acc => {
        const rows = acc.querySelectorAll('[data-search]');
        let groupHasMatch = false;
        rows.forEach(row => {
            const matches = !q || (row.dataset.search || '').toLowerCase().includes(q);
            row.style.display = matches ? '' : 'none';
            if (matches) groupHasMatch = true;
        });
        if (rows.length > 0) {
            acc.style.display = (q && !groupHasMatch) ? 'none' : '';
            if (q && groupHasMatch) acc.open = true;
        }
        if (groupHasMatch) anyMatch = true;
    });
    let noResults = document.getElementById('toolboxNoResults');
    if (q && !anyMatch) {
        if (!noResults) {
            noResults = document.createElement('div');
            noResults.id = 'toolboxNoResults';
            noResults.style.cssText = 'text-align:center;font-size:13px;color:var(--text-dim);padding:20px 0;';
            noResults.textContent = 'No matches — try a different search.';
            document.getElementById('toolboxViewBody').appendChild(noResults);
        }
    } else if (noResults) {
        noResults.remove();
    }
}
function updateToolboxBadge() {
    const badge = document.getElementById('toolboxBadge');
    if (!badge) return;
    const totalPending = pendingSources.length + pendingClaims.length;
    if (totalPending > 0) { badge.style.display = 'inline-flex'; badge.textContent = totalPending; } else { badge.style.display = 'none'; }
}
function hookSelectedSourceToCard() {
    const idField = (getAssetConfig(state.fromAsset)?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    const identifier = idField ? state.fromFields[idField.name] : '';
    openHookBuilder('source', { instName: PARTICIPANTS[state.fromInst]?.name || state.fromInst, institution: state.fromInst, assetType: state.fromAsset, identifier });
}

function openFinalizeIdentityModal() { openModal('Finalize identity swap', renderFinalizeIdentityModal()); }
function renderFinalizeIdentityModal() {
    const claimsHtml = pendingClaims.length === 0 ? `<div style="font-size:12px;color:var(--text-dim);margin-bottom:16px;">No identity money is currently waiting for you.</div>` : `<div style="margin-bottom:16px;">${pendingClaims.map((c, i) => {
        const pinLabel = c.claim_type === 'otp_pin' ? 'the OTP PIN sent by SMS' : 'your transaction PIN';
        const claimPin = c.claim_pin || null, code = c.voucher_number || null, pin = c.atm_pin || null;
        const hasCode = !!(code || pin || claimPin);
        const codeInlineHtml = hasCode ? `<div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--border);display:flex;gap:16px;flex-wrap:wrap;">${code ? `<div><div style="font-size:10px;color:var(--text-dim);">Code</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(code)}</div></div>` : ''}${pin ? `<div><div style="font-size:10px;color:var(--text-dim);">PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(pin)}</div></div>` : ''}${claimPin ? `<div><div style="font-size:10px;color:var(--text-dim);">Claim PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(claimPin)}</div></div>` : ''}${c.voucher_expiry ? `<div><div style="font-size:10px;color:var(--text-dim);">Expires</div><div style="font-size:12px;color:var(--text-muted);">${new Date(c.voucher_expiry).toLocaleString()}</div></div>` : ''}</div>` : '';
        return `<div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;"><div style="flex:1;"><div style="font-weight:700;font-size:15px;color:var(--accent);font-family:var(--font-mono);">${formatMoney(c.amount, c.currency)}</div><div style="font-size:12px;color:var(--text-muted);">From ${escapeHtml(c.source_institution || 'Unknown')}</div><div style="font-size:11px;color:var(--text-dim);">Needs ${pinLabel} · Expires ${c.hold_expires_at ? new Date(c.hold_expires_at).toLocaleString() : 'soon'}</div></div><button class="btn btn-primary btn-sm" onclick="openClaimForm(${i})" style="flex-shrink:0;">Finalize</button></div>${codeInlineHtml}</div>`;
    }).join('')}</div>`;
    return `<div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Money sent to your national ID, phone, or email shows up here.</div>${claimsHtml}<div style="border-top:1px solid var(--border);padding-top:16px;margin-top:4px;"><div class="field-label" style="margin-bottom:6px;">Need to claim an identity swap?</div><div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">If you received a swap notification, enter the claim PIN below to complete the transaction.</div><div class="field-group"><label>Swap reference</label><input id="directClaimRef" placeholder="e.g. SWAP_123456789"></div><div class="field-group"><label>Claim PIN</label><input type="password" id="directClaimPin" placeholder="Enter the PIN you received" maxlength="6"></div><div class="cta-row"><button class="btn btn-primary" onclick="submitDirectClaim()">Claim swap</button></div></div><div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;font-size:11px;color:var(--text-dim);"><span class="quick-link muted" onclick="closeModal();openAddIdentityModal();">Need to register a new identity instead? Click here →</span></div>`;
}
async function submitDirectClaim() {
    const swapRef = document.getElementById('directClaimRef').value.trim();
    const pin = document.getElementById('directClaimPin').value.trim();
    if (!swapRef) { showMessage('Please enter the swap reference.', 'warning'); return; }
    if (!pin) { showMessage('Please enter your claim PIN.', 'warning'); return; }
    if (!/^\d{4,6}$/.test(pin)) { showMessage('Your PIN should be 4 to 6 digits.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/claim_identity.php', { swap_reference: swapRef, pin });
    if (!result.ok) { showMessage('That claim didn\'t go through: ' + friendlyApiError(result.error), 'error'); return; }
    closeModal(); showMessage('Funds claimed successfully! 🎉', 'success'); checkPendingClaims(); loadToolboxView();
}
function openClaimForm(idx) {
    const claim = pendingClaims[idx]; if (!claim) return;
    const pinHint = claim.claim_type === 'otp_pin' ? 'Use the one-time PIN sent by SMS when this money was sent.' : 'Use your VouchMorph transaction PIN.';
    const body = `<div style="background:var(--accent-soft);padding:14px;margin-bottom:14px;"><div style="font-size:20px;font-weight:600;color:var(--accent);font-family:var(--font-mono);">${formatMoney(claim.amount, claim.currency)}</div><div style="font-size:12px;color:var(--text-muted);">From ${escapeHtml(claim.source_institution || 'Unknown')}</div></div><div class="field-group"><label>Claim PIN</label><input type="password" id="claimPin" inputmode="numeric" maxlength="6" placeholder="••••"><div class="help">${pinHint}</div></div><div class="field-group"><label>Receive as</label><select id="claimDestType" onchange="toggleClaimDestFields(this.value)"><option value="CASHOUT">Cashout (ATM / Agent code)</option><option value="DEPOSIT">Deposit to an account/wallet</option></select></div><div id="claimDepositFields" style="display:none;"><div class="field-group"><label>Destination institution</label><select id="claimDestInst"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div><div class="field-group"><label>Account / wallet number</label><input id="claimDestIdentifier" placeholder="Account number or phone"></div></div><div class="cta-row"><button class="btn btn-secondary" onclick="openFinalizeIdentityModal()">Back</button><button class="btn btn-primary" onclick="submitClaim('${claim.swap_reference}')">Finalize</button></div>`;
    openModal('Finalize identity swap', body);
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
    if (!result.ok) { showMessage('That claim didn\'t go through: ' + friendlyApiError(result.error), 'error'); return; }
    closeModal(); showMessage('Funds claimed successfully! 🎉', 'success'); checkPendingClaims(); loadToolboxView();
}
function openAddIdentityModal() {
    openModal('Register identity', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading...</div>');
    getCurrentUserRole().then(session => { if (session.is_agent) renderAgentIdentityForm(); else renderUserIdentityForm(); });
}
function renderUserIdentityForm() {
    document.getElementById('modalBody').innerHTML = `<div><div style="font-weight:700;font-size:15px;margin-bottom:4px;">Add an identity to your account</div><div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Register a phone number, email, or ID so people can swap directly to you.</div><div class="field-group"><label>Identity type</label><select id="userIdentityType"><option value="phone">Phone Number</option><option value="email">Email</option><option value="national_id">National ID</option><option value="birth_certificate">Birth Certificate</option><option value="voter_id">Voter ID</option></select></div><div class="field-group"><label>Identity value</label><input type="text" id="userIdentityValue" placeholder="Enter the ID number, phone, or email"></div><div style="background:var(--accent-soft);border-left:3px solid var(--accent);padding:10px 14px;font-size:12px;margin-bottom:14px;">Phone numbers are verified instantly via SMS. National IDs and other documents require in-person verification by a VouchMorph agent.</div><div id="regIdentityOtpFields" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);"><div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;" id="regIdentityOtpMessage"></div><div class="otp-input-group"><input type="text" id="regIdentityOtp" placeholder="Enter verification code" inputmode="numeric" maxlength="8"><button class="btn btn-primary btn-sm" onclick="submitVerifyIdentityOtp()">Verify</button></div></div><div class="cta-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitRegisterIdentity()">Register identity</button></div><div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;font-size:11px;color:var(--text-dim);"><span class="quick-link muted" onclick="closeModal();openFinalizeIdentityModal();">Need to claim a swap sent to your identity? Click here →</span></div></div>`;
}
function renderAgentIdentityForm() {
    document.getElementById('modalBody').innerHTML = `<div><div style="font-weight:700;font-size:15px;margin-bottom:4px;">Register a verified identity (Agent)</div><div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Use this after physically verifying the person's document.</div><div class="field-group"><label>Account holder's phone or email</label><input type="text" id="agentTargetLookup" placeholder="Phone or email on their VouchMorph account"></div><div class="field-group"><label>Identity type</label><select id="agentIdentityType"><option value="national_id">National ID</option><option value="voters_id">Voter's ID</option><option value="drivers_license">Driver's License</option><option value="birth_certificate">Birth Certificate</option><option value="passport">Passport</option></select></div><div class="field-group"><label>ID number</label><input type="text" id="agentIdentityValue" placeholder="Document number"></div><div class="field-group"><label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:400;"><input type="checkbox" id="agentDocVerified" style="width:auto;"> I have physically verified this document</label></div><div class="cta-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitAgentIdentity()">Register identity</button></div></div>`;
}
async function submitRegisterIdentity() {
    const identityType = document.getElementById('userIdentityType').value;
    const identityValue = document.getElementById('userIdentityValue').value.trim();
    if (!identityValue) { showMessage('Please enter the identity value.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/user/add_identity.php', { identity_type: identityType, identity_value: identityValue });
    if (!result.ok) { showMessage('Could not register that identity: ' + friendlyApiError(result.error), 'error'); return; }
    const data = result.body.data || {};
    regIdentityState = { attemptId: data.attempt_id || null, identityType, identityValue };
    if (data.requires_otp) { document.getElementById('regIdentityOtpFields').style.display = 'block'; document.getElementById('regIdentityOtpMessage').textContent = data.message || 'Enter the verification code sent to your phone/email.'; showMessage('Verification code sent.', 'success'); return; }
    savedIdentities.push({ type: identityType, value: identityValue });
    showMessage(data.message || 'Identity submitted for review.', 'success');
    renderProgressCard();
    setTimeout(() => closeModal(), 2000);
}
async function submitAgentIdentity() {
    const lookup = document.getElementById('agentTargetLookup').value.trim();
    const type = document.getElementById('agentIdentityType').value;
    const value = document.getElementById('agentIdentityValue').value.trim();
    const verified = document.getElementById('agentDocVerified').checked;
    if (!lookup) { showMessage('Please enter the account holder\'s contact.', 'warning'); return; }
    if (!value) { showMessage('Please enter the ID number.', 'warning'); return; }
    if (!verified) { showMessage('You must confirm you verified the document.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/add_verified_identity.php', { target_lookup: lookup, identity_type: type, identity_value: value, document_verified: true });
    if (!result.ok) { showMessage('Could not register that identity: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage(result.body.message || 'Identity registered successfully.', 'success');
    setTimeout(() => closeModal(), 2000);
}
async function submitVerifyIdentityOtp() {
    const otp = document.getElementById('regIdentityOtp').value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/user/verify_identity_otp.php', { attempt_id: regIdentityState.attemptId, otp });
    if (!result.ok) { showMessage('That code didn\'t work: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage('Identity verified. You can now receive swaps. 🎉', 'success');
    renderProgressCard();
    setTimeout(() => closeModal(), 2000);
}

async function getCurrentUserRole() {
    if (SessionUser) return SessionUser;
    const result = await callApi(CONFIG.API_BASE + '/user/whoami.php', {});
    SessionUser = (result.ok && result.body) ? result.body : { success: false, role: 'user', is_agent: false, is_admin: false, permissions: [] };
    renderProgressCard();
    return SessionUser;
}
async function loadAgentStatus() {
    if (!CONFIG.USER_ID) return;
    await getCurrentUserRole();
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) return;
    agentStatus = result.body.data; agentStatus.is_agent = SessionUser.is_agent;
}
async function openAgentModal() {
    openModal('Agent account', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading...</div>');
    await getCurrentUserRole();
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="color:var(--danger);">Couldn't load agent status: ${escapeHtml(friendlyApiError(result.error))}</div>`; return; }
    agentStatus = result.body.data; agentStatus.is_agent = SessionUser.is_agent;
    document.getElementById('modalBody').innerHTML = renderAgentModal();
}
function renderAgentModal() {
    const activeDestinations = agentStatus.all_destinations.filter(d => d.status !== 'cancelled' && !d.deleted_at);
    const statusRows = activeDestinations.length ? activeDestinations.map(d => {
        const isPending = d.status === 'pending_confirmation', isRejected = d.status === 'rejected', canCancel = isPending || isRejected;
        const badge = d.status === 'active' ? '<span style="background:rgba(31,138,84,0.1);color:var(--success);padding:2px 8px;font-size:10px;font-weight:700;">Active</span>' : isPending ? '<span style="background:#fef3c7;color:#8a5a0b;padding:2px 8px;font-size:10px;font-weight:700;">Pending approval</span>' : '<span style="background:#fbeceb;color:var(--danger);padding:2px 8px;font-size:10px;font-weight:700;">' + (d.status || 'Unknown') + '</span>';
        return `<div style="border:1px solid var(--border);padding:12px;margin-bottom:8px;background:#fff;"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;"><div><div style="font-weight:700;">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)}</div><div style="font-size:12px;color:var(--text-muted);">${escapeHtml(d.identifier)} · ${escapeHtml(d.account_type || d.asset_type)}</div></div><div style="display:flex;align-items:center;gap:8px;">${badge}${canCancel ? `<button class="btn-danger-outline" onclick="cancelAgentDestination(${d.id})">Cancel</button>` : ''}</div></div>${d.status === 'rejected' && d.rejection_reason ? `<div style="font-size:12px;color:var(--danger);margin-top:6px;">Reason: ${escapeHtml(d.rejection_reason)}</div>` : ''}</div>`;
    }).join('') : '<div style="font-size:12px;color:var(--text-dim);">You have no agent destination accounts registered yet.</div>';
    return `<div style="margin-bottom:16px;"><div class="field-label" style="margin-bottom:8px;">Your agent accounts</div>${statusRows}</div><div style="border-top:1px solid var(--border);padding-top:16px;"><div class="field-label" style="margin-bottom:8px;">Register a new agent destination</div><div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Register a business/agent account you hold at a participating institution. Only business or agent-designated accounts are eligible. Approval required before activation.</div><div class="field-group"><label>Institution</label><select id="agentInst" onchange="onAgentInstChange(this.value)"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div><div class="field-group" id="agentAssetTypeGroup" style="display:none;"><label>Account type</label><select id="agentAssetType"></select><div class="help">Only Account, Wallet, or Card can be used — vouchers stay manual, never registered as a destination.</div></div><div class="field-group"><label>Account / wallet / card number</label><input id="agentIdentifier" placeholder="Your business account number"></div><div class="field-group"><label>Account name (optional)</label><input id="agentAccountName" placeholder="e.g. Thabo's General Store"></div><div class="cta-row"><button class="btn btn-primary" onclick="submitAgentDestination()">Register and verify</button></div></div>`;
}
async function cancelAgentDestination(destinationId) {
    showConfirm('Cancel this registration? You can register again later.', async () => {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/cancel_destination.php', { destination_id: destinationId });
        if (!result.ok) { showMessage('Could not cancel: ' + friendlyApiError(result.error), 'error'); return; }
        showMessage('Registration cancelled successfully.', 'success');
        openAgentModal();
    });
}
async function submitAgentDestination() {
    const institution = document.getElementById('agentInst').value;
    const assetType = document.getElementById('agentAssetType').value;
    const identifier = document.getElementById('agentIdentifier').value.trim();
    const accountName = document.getElementById('agentAccountName').value.trim();
    if (!institution || !identifier) { showMessage('Select an institution and enter your account number.', 'warning'); return; }
    if (!assetType) { showMessage('Select whether this is an Account, Wallet, or Card.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/propose_destination.php', { institution, asset_type: assetType, identifier, account_name: accountName || undefined });
    if (!result.ok) { showMessage('Could not register: ' + friendlyApiError(result.error), 'error'); return; }
    const data = result.body.data;
    if (data.requires_redirect) { showMessage(data.message || 'Redirecting you to your bank to confirm this account...', 'info'); window.location.href = data.redirect_url; return; }
    if (!data.requires_otp) { showMessage(data.message, data.otp_supported ? 'success' : 'warning'); openAgentModal(); return; }
    document.getElementById('modalBody').innerHTML = renderAgentOtpStep(data);
}
function renderAgentOtpStep(data) {
    return `<div style="background:var(--accent-soft);padding:14px;margin-bottom:16px;"><div style="font-weight:700;margin-bottom:4px;">Verification code sent</div><div style="font-size:12px;color:var(--text-muted);">${escapeHtml(data.message)}</div></div><div class="field-group"><label>Enter the code</label><input type="text" id="agentOtpCode" inputmode="numeric" maxlength="8" placeholder="Code from your bank"></div><div class="cta-row"><button class="btn btn-secondary" onclick="openAgentModal()">Cancel</button><button class="btn btn-primary" onclick="verifyAgentOtp(${data.attempt_id})">Verify and register</button></div>`;
}
async function verifyAgentOtp(attemptId) {
    const otp = document.getElementById('agentOtpCode').value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/verify_destination_otp.php', { attempt_id: attemptId, otp });
    if (!result.ok) { showMessage('That code didn\'t work: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage(result.body.data.message || 'Account verified and registered.', 'success');
    openAgentModal();
}
const AGENT_ELIGIBLE_ASSET_TYPES = ['ACCOUNT', 'WALLET', 'BANK-WALLET', 'CARD'];
function onAgentInstChange(code) {
    const group = document.getElementById('agentAssetTypeGroup'); const sel = document.getElementById('agentAssetType');
    if (!code) { group.style.display = 'none'; sel.innerHTML = ''; return; }
    const inst = PARTICIPANTS[code]; const allTypes = inst?.asset_types || [];
    const eligible = allTypes.filter(t => AGENT_ELIGIBLE_ASSET_TYPES.includes(String(t).toUpperCase()));
    if (eligible.length === 0) { group.style.display = 'block'; sel.innerHTML = '<option value="">No eligible account types at this institution</option>'; return; }
    sel.innerHTML = eligible.map(t => `<option value="${t}">${getAssetConfig(t)?.label || t}</option>`).join('');
    group.style.display = 'block';
}
function openAgentFinalizeIdentityModal() { openModal('Finalize identity swap', renderAgentFinalizeIdentitySearch()); }
function renderAgentFinalizeIdentitySearch() {
    return `<div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Search for a client's pending identity payment. You'll need to physically verify their document and have them tell you the OTP PIN texted to them — never their personal VouchMorph transaction PIN — before you can finalize.</div><div class="field-group"><label>Document type</label><select id="agentSearchType"><option value="national_id">National ID</option><option value="birth_certificate">Birth Certificate</option><option value="voter_id">Voter ID</option></select></div><div class="field-group"><label>Document number</label><input id="agentSearchValue" placeholder="Enter the client's ID number"></div><div class="cta-row"><button class="btn btn-primary" onclick="searchAgentClaim()">Search</button></div><div id="agentSearchResults" style="margin-top:16px;"></div><div style="border-top:1px solid var(--border);padding-top:16px;margin-top:20px;"><div class="field-label" style="margin-bottom:6px;">Not what the client needs?</div><div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">If the client wants this identity permanently registered to their VouchMorph account — optional, and separate from finalizing any swap — you can do that here instead.</div><span class="quick-link muted" onclick="closeModal();openAddIdentityModal();">Register identity to client's account →</span></div>`;
}
function openAgentToolsModal() { openModal('Agent tools', renderAgentToolsSearch()); }
function renderAgentToolsSearch() {
    return `<div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Search for a client's pending identity payment. You'll need to physically verify their document and have them tell you the OTP PIN texted to them — never their personal VouchMorph transaction PIN — before you can finalize.</div><div class="field-group"><label>Document type</label><select id="agentSearchType"><option value="national_id">National ID</option><option value="birth_certificate">Birth Certificate</option><option value="voter_id">Voter ID</option></select></div><div class="field-group"><label>Document number</label><input id="agentSearchValue" placeholder="Enter the client's ID number"></div><div class="cta-row"><button class="btn btn-primary" onclick="searchAgentClaim()">Search</button></div><div id="agentSearchResults" style="margin-top:16px;"></div>`;
}

async function searchAgentClaim() {
    const identityType = document.getElementById('agentSearchType').value;
    const identityValue = document.getElementById('agentSearchValue').value.trim();
    const resultsBox = document.getElementById('agentSearchResults');
    if (!identityValue) { showMessage('Enter the document number to search.', 'warning'); return; }
    resultsBox.innerHTML = '<div style="text-align:center;padding:16px;"><div class="spinner"></div> Searching...</div>';
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/search_claim.php', { identity_type: identityType, identity_value: identityValue });
    if (!result.ok) { resultsBox.innerHTML = `<div style="color:var(--danger);">${escapeHtml(friendlyApiError(result.error))}</div>`; return; }
    const data = result.body.data;
    if (!data) { resultsBox.innerHTML = '<div style="font-size:12px;color:var(--text-dim);">No pending payment found for this identity.</div>'; return; }
    agentSearchData = data;
    if (data.multi_currency) {
        let html = '<div style="margin-bottom:12px;"><strong>Multiple currencies found for this identity:</strong></div>';
        data.balances.forEach((b) => {
            const currency = escapeHtml(b.currency), totalAmount = parseFloat(b.total_amount).toFixed(2), swapCount = parseInt(b.swap_count);
            const identityTypeEscaped = escapeHtml(data.identity_type), identityValueEscaped = escapeHtml(data.identity_value);
            html += `<div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;"><div><div style="font-weight:700;font-size:16px;color:var(--accent);font-family:var(--font-mono);">${formatMoney(totalAmount, currency)}</div><div style="font-size:12px;color:var(--text-muted);">From ${swapCount} different source(s)</div><div style="font-size:11px;color:var(--text-dim);">Expires ${b.earliest_expires_at ? new Date(b.earliest_expires_at).toLocaleString() : 'soon'}</div></div><button class="btn btn-primary btn-sm" onclick="openAgentFinalizeFormAggregated('${identityTypeEscaped}', '${identityValueEscaped}', '${currency}', ${totalAmount}, ${swapCount})">Claim</button></div></div>`;
        });
        resultsBox.innerHTML = html; return;
    }
    const currency = escapeHtml(data.currency), totalAmount = parseFloat(data.total_amount).toFixed(2), swapCount = parseInt(data.swap_count);
    const identityTypeEscaped = escapeHtml(data.identity_type), identityValueEscaped = escapeHtml(data.identity_value);
    resultsBox.innerHTML = `<div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;"><div><div style="font-weight:700;font-size:16px;color:var(--accent);font-family:var(--font-mono);">${formatMoney(totalAmount, currency)}</div><div style="font-size:12px;color:var(--text-muted);">From ${swapCount} different source(s)</div><div style="font-size:11px;color:var(--text-dim);">Expires ${data.earliest_expires_at ? new Date(data.earliest_expires_at).toLocaleString() : 'soon'}</div></div><button class="btn btn-primary btn-sm" onclick="openAgentFinalizeFormAggregated('${identityTypeEscaped}', '${identityValueEscaped}', '${currency}', ${totalAmount}, ${swapCount})">Claim</button></div></div>`;
}
function openAgentFinalizeFormAggregated(identityType, identityValue, currency, totalAmount, swapCount) {
    const data = agentSearchData;
    if (!data) { showMessage('Search data not found. Please search again.', 'error'); return; }
    if (!agentStatus.approved_destinations || agentStatus.approved_destinations.length === 0) { openModal('Agent tools', '<div style="color:var(--danger);">You have no approved agent destination account. Register one first.</div>'); return; }
    const destOptions = agentStatus.approved_destinations.map(d => `<option value="${d.id}">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)} - ${escapeHtml(d.identifier)}</option>`).join('');
    const searchTypeLabel = IDENTITY_TYPE_LABELS[document.getElementById('agentSearchType')?.value] || 'document';
    const body = `<div style="background:var(--accent-soft);padding:14px;margin-bottom:14px;"><div style="font-size:12px;color:var(--text-muted);">Client's total balance</div><div style="font-size:22px;font-weight:600;color:var(--accent);font-family:var(--font-mono);">${formatMoney(totalAmount, currency)}</div><div style="font-size:11px;color:var(--text-dim);margin-top:4px;">This is an aggregated balance from ${swapCount} different source(s). The full amount deposits into your account. Whatever the client doesn't take as cash today is instantly sent back to their identity as a new claim.</div></div><div class="field-group"><label>Deposit into</label><select id="agentDestSelect">${destOptions}</select></div><div class="field-group"><label>Cash to give the client now</label><input type="number" id="cashNowAmount" min="0" max="${totalAmount}" step="0.01" value="${totalAmount}"><div class="help">Leave less than the full amount to split — the rest becomes a new claim for them to collect elsewhere.</div></div><div class="quick-actions" style="margin:-4px 0 12px;"><span class="quick-link" onclick="document.getElementById('cashNowAmount').value=${totalAmount}">Give it all</span><span class="quick-link muted" onclick="document.getElementById('cashNowAmount').value=0">Give none now</span></div><div class="field-group"><label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:400;"><input type="checkbox" id="agentDocVerified" style="width:auto;"> I have physically verified the client's ${searchTypeLabel}</label></div><div class="field-group"><label>Client's OTP PIN</label><input type="password" id="agentClaimPin" inputmode="numeric" maxlength="6" placeholder="Ask the client for the PIN texted to them"><div class="help">This is the OTP PIN sent by SMS — never a personal transaction PIN. The client must tell you this themselves; never accept a claim without it.</div></div><div class="cta-row"><button class="btn btn-secondary" onclick="openAgentToolsModal()">Back to search</button><button class="btn btn-primary" onclick="submitAgentFinalizeAggregated('${escapeHtml(identityType)}', '${escapeHtml(identityValue)}', ${totalAmount}, '${currency}')">Process</button></div>`;
    openModal('Confirm deposit', body);
}
async function submitAgentFinalizeAggregated(identityType, identityValue, totalAmount, currency) {
    const destinationAccountId = document.getElementById('agentDestSelect').value;
    const docVerified = document.getElementById('agentDocVerified').checked;
    const pin = document.getElementById('agentClaimPin').value.trim();
    const cashNowAmount = parseFloat(document.getElementById('cashNowAmount').value);
    if (!docVerified) { showMessage('You must confirm you verified the client\'s physical document.', 'warning'); return; }
    if (!pin) { showMessage('Enter the client\'s claim PIN.', 'warning'); return; }
    if (isNaN(cashNowAmount) || cashNowAmount < 0 || cashNowAmount > totalAmount) { showMessage(`Cash amount must be between 0 and ${totalAmount}.`, 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/finalize_claim.php', { identity_type: identityType, identity_value: identityValue, pin, identity_document_verified: true, destination_account_id: parseInt(destinationAccountId, 10), cash_now_amount: cashNowAmount });
    if (!result.ok) { showMessage('That claim didn\'t go through: ' + friendlyApiError(result.error), 'error'); return; }
    const data = result.body.data || {};
    closeModal();
    const netDeposited = data.actually_claimed_net || totalAmount, grossAmount = data.actually_claimed_gross || totalAmount;
    const remainder = data.remainder_reswap?.amount || 0, cashGiven = data.cash_now_amount || cashNowAmount;
    const successfulSwaps = data.swap_count || 0, failedSwaps = data.failed_deposits ? data.failed_deposits.length : 0;
    const totalFees = parseFloat(grossAmount) - parseFloat(netDeposited);
    let msg = '';
    if (netDeposited > 0) { msg += `Deposited ${formatMoney(netDeposited, currency)} into your account`; if (totalFees > 0) msg += ` (fee: ${formatMoney(totalFees, currency)})`; msg += '. '; }
    if (cashGiven > 0) msg += `Gave client ${formatMoney(cashGiven, currency)} in cash. `; else msg += `No cash given now. `;
    if (remainder > 0) msg += `The remaining ${formatMoney(remainder, currency)} was sent back to their identity — a new PIN was texted to them. `;
    if (successfulSwaps > 1) { msg += `(Processed ${successfulSwaps} source(s)`; if (failedSwaps > 0) msg += `, ${failedSwaps} failed`; msg += `)`; } else if (failedSwaps > 0) msg += `(${failedSwaps} source(s) failed)`;
    if (data.status === 'partial_success') msg += ' Partial success — some sources failed.';
    showMessage(msg, 'success');
    agentSearchData = null;
}

// ============================================================
// ACTIVITY — persistent view, swap history rendered inline
// (was a modal before).
// ============================================================
async function loadActivityView() {
    const body = document.getElementById('activityViewBody');
    body.innerHTML = `<div style="text-align:center;padding:40px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading swaps...</div>`;
    if (!CONFIG.USER_ID) { body.innerHTML = `<div style="text-align:center;padding:30px;color:var(--danger);"><div style="font-weight:700;">Could not identify your account</div><div style="font-size:12px;color:var(--text-muted);margin-top:8px;">Your session doesn't have a user ID attached. Try logging out and back in.</div></div>`; return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/history.php', { user_id: CONFIG.USER_ID, limit: 50 });
    if (!result.ok) { body.innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);">Couldn't load swap history: ${escapeHtml(friendlyApiError(result.error))}</div>`; return; }
    renderActivityBody(result.body);
}
function renderActivityBody(data) {
    const swaps = data.data || data.swaps || [];
    const body = document.getElementById('activityViewBody');
    if (swaps.length === 0) { body.innerHTML = `<div style="text-align:center;padding:30px;color:var(--text-muted);"><div style="font-weight:700;">No swaps yet</div><div style="font-size:12px;margin-top:8px;">Once you make your first swap, it'll show up here.</div></div>`; return; }
    let html = `<div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Showing ${swaps.length} swap(s)</div>`;
    swaps.forEach((swap) => {
        const statusColor = swap.status === 'completed' || swap.status === 'success' ? 'var(--success)' : swap.status === 'pending' ? 'var(--warning)' : 'var(--danger)';
        const code = swap.voucher_number || null, pin = swap.atm_pin || null, claimPin = swap.claim_pin || null;
        const hasCode = !!(code || pin || claimPin);
        const codeInlineHtml = hasCode ? `<div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--border);display:flex;gap:16px;flex-wrap:wrap;">${code ? `<div><div style="font-size:10px;color:var(--text-dim);">Code</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(code)}</div></div>` : ''}${pin ? `<div><div style="font-size:10px;color:var(--text-dim);">PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(pin)}</div></div>` : ''}${claimPin ? `<div><div style="font-size:10px;color:var(--text-dim);">Claim PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(claimPin)}</div></div>` : ''}</div>` : '';
        html += `<div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;cursor:pointer;" onclick="viewSwapDetail('${swap.reference || swap.swap_reference || 'N/A'}')"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;"><div><div style="font-weight:700;">${swap.swap_type || 'SWAP'} <span style="font-size:11px;color:var(--text-muted);">${swap.reference || swap.swap_reference || ''}</span></div><div style="font-size:12px;color:var(--text-muted);">${swap.source_institution || 'Unknown'} → ${swap.destination_institution || 'Unknown'}</div></div><div style="text-align:right;"><div style="font-weight:700;color:var(--accent);font-family:var(--font-mono);">${formatMoney(swap.amount, swap.currency)}</div><div style="font-size:11px;color:${statusColor};">${swap.status || 'unknown'}</div></div></div>${codeInlineHtml}</div>`;
    });
    body.innerHTML = html;
}
async function viewSwapDetail(reference) {
    openModal('Swap details', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading details...</div>');
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/details.php', { reference });
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);">Couldn't load swap details: ${escapeHtml(friendlyApiError(result.error))}</div>`; return; }
    renderSwapDetail(result.body);
}
function renderSwapDetail(data) {
    const swap = data.swap || data.data || {};
    const code = swap.voucher_number || null, pin = swap.atm_pin || null;
    const codeBox = (code || pin) ? `<div class="atm-code" style="margin-bottom:12px;">${code ? `<div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Cashout / Voucher Code</div><div class="code">${escapeHtml(code)}</div>` : ''}${pin ? `<div style="font-size:11px;color:var(--text-muted);margin:${code ? '10px' : '0'} 0 4px;">PIN</div><div class="code">${escapeHtml(pin)}</div>` : ''}${swap.voucher_expiry ? `<div style="font-size:11px;color:var(--text-dim);margin-top:8px;">Expires ${new Date(swap.voucher_expiry).toLocaleString()}</div>` : ''}</div>` : '';
    document.getElementById('modalBody').innerHTML = `<div style="max-height:70vh;overflow-y:auto;"><div style="background:var(--accent-soft);padding:16px;margin-bottom:12px;"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;"><div><div style="font-size:12px;color:var(--text-muted);">Reference</div><div style="font-weight:700;">${swap.reference || swap.swap_reference || 'N/A'}</div></div><div><div style="font-size:12px;color:var(--text-muted);">Status</div><div style="font-weight:700;">${swap.status || 'unknown'}</div></div></div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;"><div style="background:var(--surface);border:1px solid var(--border);padding:12px;"><div style="font-size:11px;color:var(--text-muted);">Swap type</div><div style="font-weight:700;">${swap.swap_type || 'N/A'}</div></div><div style="background:var(--surface);border:1px solid var(--border);padding:12px;"><div style="font-size:11px;color:var(--text-muted);">Amount</div><div style="font-weight:700;font-size:16px;color:var(--accent);font-family:var(--font-mono);">${formatMoney(swap.amount, swap.currency)}</div></div></div>${codeBox}<div style="margin-top:12px;"><button class="btn btn-secondary" onclick="closeModal()" style="width:100%;">Close</button></div></div>`;
}

function openProfileModal() { openModal('My profile', renderProfileModal()); }
function renderProfileModal() {
    const rows = savedIdentities.length ? savedIdentities.map((id, i) => `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);"><div><div style="font-size:11px;color:var(--text-muted);">${escapeHtml(IDENTITY_TYPE_LABELS[id.type] || id.type)}</div><div style="font-size:14px;font-weight:700;">${escapeHtml(id.value)}</div></div><div class="quick-actions" style="margin:0;"><span class="quick-link" onclick="useSavedIdentity(${i})">Use</span><span class="quick-link danger" onclick="removeSavedIdentity(${i})">Remove</span></div></div>`).join('') : `<div style="font-size:12px;color:var(--text-dim);">No saved identities yet.</div>`;
    return `<div style="margin-bottom:12px;"><div style="font-weight:700;margin-bottom:4px;">Your registered identities</div>${rows}</div><div style="border-top:1px solid var(--border);padding-top:16px;"><div class="field-label" style="margin-bottom:8px;">Transaction PIN</div><div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Required to claim money sent to your verified identity. Never share it.</div><div class="field-group"><label>New PIN (4-6 digits)</label><input type="password" id="newPin" inputmode="numeric" maxlength="6" placeholder="••••"></div><div class="field-group"><label>Confirm PIN</label><input type="password" id="confirmPin" inputmode="numeric" maxlength="6" placeholder="••••"></div><div class="cta-row"><button class="btn btn-primary" onclick="setTransactionPin()">Set PIN</button></div></div><div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;"><span class="quick-link" onclick="closeModal();openAddIdentityModal();">Add a new identity</span><span class="quick-link muted" onclick="closeModal();openFinalizeIdentityModal();">Finalize an identity swap</span></div>`;
}
function removeSavedIdentity(idx) { savedIdentities.splice(idx, 1); document.getElementById('modalBody').innerHTML = renderProfileModal(); }
function useSavedIdentity(idx) {
    const id = savedIdentities[idx]; if (!id) return;
    closeModal(); goView('swap'); openIdentitySendModal();
    state.toIdentityType = id.type; state.toIdentityValue = id.value;
    setTimeout(() => { const typeSel = document.getElementById('identityType'); const valInput = document.getElementById('identityValue'); if (typeSel) typeSel.value = id.type; if (valInput) valInput.value = id.value; updateIdentityHelp(); refreshUI(); }, 50);
    showMessage(`Using saved ${IDENTITY_TYPE_LABELS[id.type] || id.type}: ${id.value}`, 'success');
}
async function setTransactionPin() {
    const pin = document.getElementById('newPin').value.trim();
    const confirmPin = document.getElementById('confirmPin').value.trim();
    if (!/^\d{4,6}$/.test(pin)) { showMessage('Your PIN should be 4 to 6 digits.', 'warning'); return; }
    if (pin !== confirmPin) { showMessage('Those two PINs don\'t match — try again.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/set_pin.php', { pin, confirm_pin: confirmPin });
    if (!result.ok) { showMessage('Could not set your PIN: ' + friendlyApiError(result.error), 'error'); return; }
    if (SessionUser) SessionUser.has_pin = true;
    showMessage('Transaction PIN set. Keep it private. 🎉', 'success');
    renderProgressCard(); closeModal();
}

function openHelpModal() {
    openModal('Help', `<div style="font-size:13px;line-height:1.7;color:var(--text);">
        <p style="font-weight:700;margin-bottom:6px;">Swapping money</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>From the hub, tap Swap.</li><li>Pick a source tile: Wallet, Card, Voucher, or My Card (draws from whatever's hooked to your VouchMorph Card).</li><li>Tap Destination — To an account, or Cash pickup.</li><li>Enter the amount, review the fee, and confirm. Nothing moves until you tap Confirm.</li></ol>
        <p style="font-weight:700;margin-bottom:6px;">Swapping to an identity</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>Tap "Swap to identity" below the amount.</li><li>Enter their national ID, phone, or email.</li></ol>
        <p style="font-weight:700;margin-bottom:6px;">Combining multiple sources</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>Tap "Combine sources" below the amount.</li><li>Type the total amount — "Smart" (recommended) balances it across your sources for you automatically.</li><li>Pick where it settles: an account/wallet/card, an identity, or a VouchMorph Card.</li></ol>
        <p style="font-weight:700;margin-bottom:6px;">Your VouchMorph Card</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>From the hub, tap Card. Every account gets one automatically.</li><li>It starts inactive — activate it once with a small one-time fee from any linked source.</li><li>Hook one or many sources — from the Card view ("Hook a source"), or from any row in Toolbox → My sources ("Hook to card"). Each hooked source is held for up to 24 hours per its own authorized amount.</li><li>Once something is hooked, you can spend it directly: go to Swap and pick "My Card" as your source — it draws from everything hooked, using the same Smart/Equal/Ratio/Manual split as Combine sources.</li><li>Each hooked source shows an Unhook option if you want to release it before you spend it.</li></ol>
        <p style="font-weight:700;margin-bottom:6px;">Claiming money sent to you</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>Toolbox → Finalize identity swap.</li><li>Enter your claim PIN and choose how to receive it.</li></ol>
        <p style="font-weight:700;margin-bottom:6px;">Agent access</p>
        <ol style="padding-left:18px;"><li>Agent access is granted by VouchMorph admin staff to your account.</li><li>Once granted, open Toolbox → Agent destinations to register a business account, then use Agent tools to search and finalize client claims.</li></ol>
    </div>`);
}

// ============================================================
// Lightweight per-product "How it works" — a short read for
// people who like reading, reached via a small "?" button next
// to the product title, separate from the full Help modal.
// ============================================================
const HOW_IT_WORKS = {
    swap: {
        title: 'How Swap works',
        body: `<div style="font-size:13px;line-height:1.7;color:var(--text);">
            <p style="margin-bottom:12px;">Pick a source (Wallet, Card, Voucher, or My Card), pick a destination, enter an amount, and review the fee before anything moves.</p>
            <p style="margin-bottom:12px;"><strong>My Card</strong> draws from whatever's hooked to your VouchMorph Card instead of one single source — it splits automatically using Smart, Equal, Ratio, or Manual, the same as Combine sources.</p>
            <p>Nothing is final until you tap Confirm on the review screen — you can back out any time before that.</p>
        </div>`,
    },
    card: {
        title: 'How the Card works',
        body: `<div style="font-size:13px;line-height:1.7;color:var(--text);">
            <p style="margin-bottom:12px;">Every account gets a VouchMorph Card automatically. It starts inactive — a small one-time fee from any linked source turns it on.</p>
            <p style="margin-bottom:12px;">Once active, <strong>hook</strong> sources to it — accounts, wallets, cashout vouchers, or a claimed identity balance. Each hook holds that amount for up to 24 hours.</p>
            <p style="margin-bottom:12px;">You can hook one source or several at once, and start from either side: the Card view's "Hook a source," or any source's "Hook to card" in Toolbox.</p>
            <p>Anything hooked becomes spendable right away as a Swap source ("My Card"), and can be released early via Unhook if it hasn't been spent yet.</p>
        </div>`,
    },
};
function openHowItWorks(key) {
    const info = HOW_IT_WORKS[key];
    if (!info) return;
    openModal(info.title, info.body);
}
function openTermsModal() {
    openModal('Terms and conditions', `<div style="font-size:13px;line-height:1.7;color:var(--text);">
        <p style="font-weight:700;margin-bottom:6px;">1. The service</p><p style="margin-bottom:14px;">VouchMorph facilitates transfers, cashouts, and identity-based payments between participating institutions on your instruction. We act as an intermediary; the underlying funds remain with the institutions holding your linked sources until a swap completes.</p>
        <p style="font-weight:700;margin-bottom:6px;">2. Your responsibilities</p><p style="margin-bottom:14px;">You are responsible for keeping your PIN, claim codes, and linked source credentials confidential. VouchMorph staff will never ask for your PIN.</p>
        <p style="font-weight:700;margin-bottom:6px;">3. Fees</p><p style="margin-bottom:14px;">Applicable fees are shown before you confirm any swap. Fees vary by swap type, delivery method, and destination institution.</p>
        <p style="font-weight:700;margin-bottom:6px;">4. Identity swaps</p><p style="margin-bottom:14px;">Money sent to an identity (national ID, phone, email, etc.) is held for the recipient for a limited window and requires verification to claim.</p>
        <p style="margin-top:16px;color:var(--danger);font-size:11px;font-weight:700;">⚠ Placeholder — replace with reviewed legal terms and a recorded consent flow before this goes live with real funds.</p>
    </div>`);
}
async function checkPendingClaims() {
    if (!CONFIG.USER_ID) return;
    try { const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/pending_claims.php', {}); if (!result.ok) return; pendingClaims = result.body.data || []; updateToolboxBadge(); }
    catch (e) { console.error('[claims] Failed to check pending claims', e); }
}
// ------------------------------------------------------------
// Nodes like #fromSection / #toSection / #identityFields /
// #multiDestControls get physically moved into whichever modal is
// using them (Destination picker, Identity picker, Combine
// sources, Activate card). If a modal is closed and these nodes
// aren't returned home, the NEXT modal that does
// `modalBody.innerHTML = ''` destroys them outright, and every
// subsequent flow that expects e.g. #toInstSelect to exist breaks.
// This must run before any modal is reused.
// ------------------------------------------------------------
function returnMovableNodesHome() {
    const offscreen = document.getElementById('offscreenNodes');
    const toInstSlot = document.getElementById('toInstAssetGroupSlot');
    const toInstGroup = document.getElementById('toInstAssetGroup');
    if (toInstGroup && toInstSlot && toInstGroup.parentElement !== toInstSlot) toInstSlot.appendChild(toInstGroup);
    ['fromSection', 'toSection', 'identityFields', 'multiDestControls'].forEach(id => {
        const el = document.getElementById(id);
        if (el && offscreen && el.parentElement !== offscreen) offscreen.appendChild(el);
    });
    // If Swap's single-source picker is the active mode, re-adopt
    // fromSection immediately so the view isn't left empty right
    // after closing a modal that borrowed it (e.g. Activate card).
    if (state.swapSourceMode !== 'VMCARD') {
        const singleHolder = document.getElementById('swapSingleSourceHolder');
        const fromSectionEl = document.getElementById('fromSection');
        if (singleHolder && fromSectionEl && fromSectionEl.parentElement !== singleHolder) singleHolder.appendChild(fromSectionEl);
    }
}
function openModal(title, bodyHtml) {
    const modalTitle = document.getElementById('modalTitle'), modalBody = document.getElementById('modalBody'), modal = document.getElementById('modal');
    if (modalTitle) modalTitle.textContent = title;
    if (modalBody) modalBody.innerHTML = bodyHtml;
    if (modal) modal.classList.add('active');
}
function closeModal() {
    document.getElementById('modal').classList.remove('active');
    returnMovableNodesHome();
    stopSessionPolling();
    refreshUI();
}
function showMessage(text, type = 'info') {
    const el = document.getElementById('mainMessage');
    el.textContent = text; el.className = `message show ${type}`;
    clearTimeout(showMessage._t);
    showMessage._t = setTimeout(() => el.classList.remove('show'), 6000);
}

// ============================================================
// CARD — persistent full-page view. QR tap takes over the whole
// screen (goQrFull). Hooked-source rows show an "Open" status
// badge and an Unhook action. "Use as Swap source" jumps straight
// into Swap with My Card pre-selected.
// ============================================================
async function loadCardView() {
    const body = document.getElementById('cardViewBody');
    body.innerHTML = `<div style="text-align:center;padding:40px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading...</div>`;
    try {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/My.php');
        if (!result.ok) {
            console.error('[card] My.php failed:', result.error);
            body.innerHTML = `<div style="color:var(--danger);padding:12px;text-align:center;"><div style="font-weight:700;margin-bottom:6px;">Couldn't load your card</div><div style="font-size:12px;">${escapeHtml(friendlyApiError(result.error))}</div><button class="btn btn-secondary btn-sm" onclick="loadCardView()" style="margin-top:12px;width:auto;">Retry</button></div>`;
            return;
        }
        myCard = result.body.data;
        body.innerHTML = renderCardViewBody();
        if (myCard.is_active && myCard.qr_payload) renderCardQr(myCard.qr_payload);
        if (myCard.active_session) startSessionPolling(myCard.active_session.session_id);
    } catch (e) {
        console.error('[card] loadCardView threw:', e);
        body.innerHTML = `<div style="color:var(--danger);padding:12px;text-align:center;"><div style="font-weight:700;margin-bottom:6px;">Something went wrong loading your card</div><div style="font-size:12px;">${escapeHtml(e.message || String(e))}</div><button class="btn btn-secondary btn-sm" onclick="loadCardView()" style="margin-top:12px;width:auto;">Retry</button></div>`;
    }
}
function renderCardViewBody() {
    if (!myCard.is_active) {
        return `<div style="text-align:center;padding:10px 0 20px;">
            <div style="font-weight:700;font-size:16px;margin-bottom:6px;">Your VouchMorph Card is ready</div>
            <div style="font-size:12px;color:var(--text-muted);max-width:320px;margin:0 auto 18px;">It's issued but not active yet — activate it once with a small one-time fee from any of your linked sources, and it's ready to use.</div>
            <div style="font-family:var(--font-mono);font-size:14px;font-weight:700;margin-bottom:4px;">•••• ${escapeHtml(myCard.card_suffix)}</div>
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:18px;">Activation fee: ${formatMoney(myCard.activation_fee, myCard.currency)}</div>
            <div class="cta-row"><button class="btn btn-primary" onclick="openActivateCardModal()">Activate my card</button></div>
            <div style="margin-top:14px;"><span class="quick-link muted" onclick="openHelpModal()">How does this work? →</span></div>
        </div>`;
    }
    const hook = myCard.hook;
    // Per the real backend (CardService::releaseHook / unhook.php), a
    // hook is released as one all-or-nothing unit — every source in it
    // together, via hook_reference. There is no per-source release, so
    // this is one "Unhook everything" action for the hook shown here,
    // not a button on each row.
    const contributorsHtml = hook && hook.contributors.length ? hook.contributors.map(c => `
        <div class="myc-source-row">
            <div class="myc-source-tile">${escapeHtml(institutionInitials(c.institution))}</div>
            <div class="myc-source-info"><div class="myc-source-inst">${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)}${c.is_me ? ' <span style="color:var(--accent);font-weight:700;">(you)</span>' : ''}</div><div class="myc-source-ident">${escapeHtml(c.source_identifier)}</div></div>
            <div style="text-align:right;">
                <div class="myc-source-amt">${formatMoney(c.held_amount, hook.currency)}</div>
                <span class="source-status-badge open">Open</span>
            </div>
        </div>`).join('') : `<div style="font-size:12px;color:var(--text-dim);padding:8px 0;">No sources hooked yet.</div>`;
    const cardName = myCard.display_name || (Journey.read().cardNamed ? Journey.read().cardNamed : null);
    return `
        <div class="myc-layout">
            <div class="myc-qr-panel">
                <div class="myc-card-visual">
                    <div class="myc-card-top"><div class="myc-card-mark">VouchMorph<sup>TM</sup></div><div class="myc-card-status active">Active</div></div>
                    <div class="myc-card-bottom"><div class="myc-card-number">•••• •••• •••• ${escapeHtml(myCard.card_suffix)}</div><div class="myc-card-name">${cardName ? escapeHtml(cardName) : 'VouchMorph Card'}</div></div>
                </div>
                <div class="qr-tap-target" onclick="goQrFull()">
                    <div class="myc-qr-frame" id="cardQrContainer"></div>
                    <div class="myc-qr-caption">Scan to hook a source to this card</div>
                    <div class="myc-qr-tap-hint">Tap to view full screen &rsaquo;</div>
                </div>
                ${cardName ? '' : `<div style="margin-top:12px;"><span class="quick-link muted" onclick="openNameCardModal()">Give your card a name →</span></div>`}
                <div class="cta-row" style="margin-top:20px;width:100%;">
                    <button class="btn btn-secondary" onclick="openHookBuilder('card')">Hook a source</button>
                    ${hook && hook.contributors.length ? `<button class="btn btn-primary" onclick="openCreateSessionModal(myCard.card_suffix)">Start a swap</button>` : ''}
                </div>
                ${hook && hook.contributors.length ? `<button class="btn secondary" style="margin-top:10px;" onclick="goView('swap'); setTimeout(()=>setSwapSourceMode('VMCARD'), 30);">Use this card as a Swap source</button>` : ''}
                <div style="margin-top:14px;"><span class="quick-link muted" onclick="openScanToHookModal()">Hook to someone else's card (scan their QR)</span></div>
            </div>
            <div>
                <div class="myc-panel">
                    <div class="myc-panel-head"><span class="myc-panel-title">Hooked sources</span>${hook ? `<span class="myc-panel-total">${formatMoney(hook.total_held, hook.currency)}</span>` : ''}</div>
                    ${hook ? `<div class="help" style="margin-bottom:6px;">Held until ${new Date(hook.expires_at).toLocaleString()} — releases automatically after 24 hours if it isn't spent first.</div>` : ''}
                    ${contributorsHtml}
                    ${hook && hook.contributors.length ? `<button class="btn secondary" style="margin-top:14px;" onclick="openUnhook({hookReference: hook.hook_reference, count: hook.contributors.length, amount: formatMoney(hook.total_held, hook.currency)})">Unhook everything</button>` : ''}
                </div>
                <div id="sessionStatusArea">${myCard.active_session ? renderSessionStatus(myCard.active_session) : ''}</div>
            </div>
        </div>`;
}
function openNameCardModal() {
    openModal('Name your card', `<div style="text-align:center;padding:6px 0 10px;font-size:12px;color:var(--text-muted);">Give your card a name — just for you, shown wherever you see it.</div><div class="field-group"><label>Card name</label><input id="cardNameInput" placeholder="e.g. Thabo's Card" maxlength="30"></div><div class="cta-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="saveCardName()">Save</button></div>`);
}
function saveCardName() {
    const name = document.getElementById('cardNameInput').value.trim();
    if (!name) { showMessage('Enter a name first.', 'warning'); return; }
    const data = Journey.read(); data.cardNamed = name; Journey.write(data);
    showMessage(`Saved — meet "${name}". 🎉`, 'success');
    closeModal(); loadCardView();
}
function renderCardQr(payloadText) {
    const el = document.getElementById('cardQrContainer');
    if (!el || typeof QRCode === 'undefined') return;
    el.innerHTML = '';
    new QRCode(el, { text: payloadText, width: 176, height: 176 });
}
function goQrFull() {
    if (!myCard || !myCard.qr_payload) { showMessage("Card QR isn't ready yet.", 'warning'); return; }
    document.getElementById('qrFullSuffix').textContent = '•••• ' + myCard.card_suffix;
    document.getElementById('qrFullName').textContent = (myCard.display_name || Journey.read().cardNamed) || 'VouchMorph Card';
    pushView('qrfull');
    document.getElementById('qrHolderLg').innerHTML = '';
    new QRCode(document.getElementById('qrHolderLg'), { text: myCard.qr_payload, width: 300, height: 300 });
}

function openActivateCardModal() {
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = '';
    modalBody.appendChild(document.getElementById('fromSection'));
    state.fromCategory = 'WALLET'; toggleSourcePanelInline('WALLET');
    const feeNote = document.createElement('div');
    feeNote.style.cssText = 'font-size:12px;color:var(--text-dim);text-align:center;margin-top:10px;';
    feeNote.textContent = `A one-time ${formatMoney(myCard.activation_fee, myCard.currency)} activation fee will be charged from the source you select.`;
    modalBody.appendChild(feeNote);
    const btnRow = document.createElement('div');
    btnRow.className = 'cta-row'; btnRow.style.marginTop = '14px';
    btnRow.innerHTML = `<button type="button" class="btn btn-primary" onclick="confirmActivateCard('${myCard.card_suffix}')">Pay and activate</button>`;
    modalBody.appendChild(btnRow);
    document.getElementById('modalTitle').textContent = 'Activate your card';
    document.getElementById('modal').classList.add('active');
}
async function confirmActivateCard(cardSuffix) {
    const hasSource = !!(state.fromInst && state.fromAsset && fieldsValidForAsset(state.fromAsset, state.fromFields, true).valid);
    if (!hasSource) { showMessage('Finish selecting the source — institution, asset type, and required fields.', 'warning'); return; }
    const idField = (getAssetConfig(state.fromAsset)?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    const identifier = idField ? state.fromFields[idField.name] : null;
    const pin = extractPinFromFields(state.fromAsset, state.fromFields);
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Activate.php', { card_suffix: cardSuffix, institution: state.fromInst, asset_type: state.fromAsset, identifier, pin: pin || undefined });
    if (!result.ok) { showMessage('Activation didn\'t go through: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage('Card activated! 🎉', 'success');
    closeModal(); loadCardView();
}

// ============================================================
// HOOK BUILDER — dual entry (card-side "Hook a source" / source-
// side "Hook to card"), single or multiple sources per hook, four
// asset types: Account, Wallet, Cashout Voucher, Identity claim
// (only enabled once there's an actual claimed balance — mirrors
// pendingClaims, same signal used elsewhere in the app).
// ============================================================
const HOOK_ASSET_TYPES = [
    { key: 'ACCOUNT', label: 'Account', icon: '🏦' },
    { key: 'WALLET', label: 'Wallet', icon: '📱' },
    { key: 'VOUCHER', label: 'Cashout voucher', icon: '🎟️' },
    { key: 'IDENTITY', label: 'Identity claim', icon: '🪪', note: 'Needs a claimed swap' },
];
let hookMode = 'single';
let hookRows = [];
let hookRowSeq = 0;
let hookEntry = { source: 'card', targetCardSuffix: null };

async function openHookBuilder(entryType, prefill) {
    if (!myCard) {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/My.php');
        if (!result.ok) { showMessage("Couldn't load your card: " + friendlyApiError(result.error), 'error'); return; }
        myCard = result.body.data;
    }
    if (!myCard.is_active) { showMessage('Your VouchMorph Card is not active yet. Activate it first from the Card view.', 'warning'); return; }
    hookEntry = { source: entryType, targetCardSuffix: myCard.card_suffix };
    hookRows = []; hookRowSeq = 0; hookMode = 'single';
    // Reset from any previous success screen this view might be
    // showing — these nodes are reused across visits.
    document.getElementById('hookViewTitle').textContent = 'Hook a source';
    document.getElementById('hookModeRow').style.display = 'flex';
    document.getElementById('addHookRowBtn').style.display = 'none';
    document.getElementById('hookSubmitBtn').style.display = 'block';
    document.getElementById('hookFinePrint').style.display = 'block';
    document.getElementById('hookEyebrow').textContent = entryType === 'card' ? 'Hook to My VouchMorph Card' : 'Hook this source to a card';
    document.getElementById('hookEntryNote').textContent = entryType === 'card'
        ? 'Started from the Card view — building the list of sources to hook.'
        : `Started from "${prefill?.instName}" in My sources — this source is pre-filled below.`;
    if (entryType === 'source' && prefill) addHookRow(prefill);
    else addHookRow();
    setHookMode('single');
    pushView('hook');
}
function setHookMode(mode) {
    hookMode = mode;
    document.getElementById('hookModeSingleBtn').classList.toggle('active', mode === 'single');
    document.getElementById('hookModeMultiBtn').classList.toggle('active', mode === 'multi');
    document.getElementById('addHookRowBtn').style.display = mode === 'multi' ? 'block' : 'none';
    if (mode === 'single' && hookRows.length > 1) hookRows = hookRows.slice(0, 1);
    renderHookRows();
}
function addHookRow(prefill) {
    if (hookMode === 'single' && hookRows.length >= 1) return;
    hookRows.push({ id: ++hookRowSeq, instName: prefill?.instName || null, institution: prefill?.institution || null, assetType: prefill?.assetType || null, identifier: prefill?.identifier || '', pin: '', amount: '', locked: !!prefill });
    renderHookRows();
}
function removeHookRow(id) { hookRows = hookRows.filter(r => r.id !== id); renderHookRows(); }
function setHookRowAssetType(id, type) {
    const row = hookRows.find(r => r.id === id);
    if (!row) return;
    if (type === 'IDENTITY' && pendingClaims.length === 0) return;
    row.assetType = type;
    if (type === 'IDENTITY' && pendingClaims.length > 0) row.identifier = formatMoney(pendingClaims[0].amount, pendingClaims[0].currency) + ' available';
    renderHookRows();
}
function renderHookRows() {
    const holder = document.getElementById('hookRowsHolder');
    holder.innerHTML = hookRows.map((row, idx) => {
        if (row.locked) {
            return `<div class="hook-row-card"><div class="hook-row-head"><span class="hook-row-label">Source ${idx + 1} — from My sources</span></div><div style="font-size:13px;font-weight:700;">${escapeHtml(row.instName)}</div><div style="font-size:11px;color:var(--text-dim);margin-bottom:10px;">${escapeHtml(row.identifier)}</div><div class="field-group"><label>Amount to authorize</label><input type="number" placeholder="0.00" value="${row.amount}" oninput="hookRows.find(r=>r.id===${row.id}).amount=this.value"></div></div>`;
        }
        const typeOptions = HOOK_ASSET_TYPES.map(t => {
            const disabled = t.key === 'IDENTITY' && pendingClaims.length === 0;
            return `<div class="source-type-opt ${row.assetType === t.key ? 'active' : ''} ${disabled ? 'disabled' : ''}" onclick="${disabled ? '' : `setHookRowAssetType(${row.id}, '${t.key}')`}"><span class="icon">${t.icon}</span>${t.label}${t.note ? `<span class="note">${disabled ? t.note : 'Available now'}</span>` : ''}</div>`;
        }).join('');
        let extraFields = '';
        let institutionField = '';
        if (row.assetType && row.assetType !== 'IDENTITY') {
            // Every non-identity source belongs to a specific
            // institution — without this, confirmHookBuilder() has no
            // way to know whether "•••• 4471" is at Zuru Bank or
            // Saccussalis, and the hook request would be ambiguous.
            const eligibleCodes = Object.keys(PARTICIPANTS).filter(code => (PARTICIPANTS[code].asset_types || []).map(t => String(t).toUpperCase()).includes(row.assetType === 'VOUCHER' ? 'VOUCHER' : row.assetType));
            const options = eligibleCodes.map(code => `<option value="${code}" ${row.institution === code ? 'selected' : ''}>${PARTICIPANTS[code]?.name || code}</option>`).join('');
            institutionField = `<div class="field-group"><label>Institution</label><select onchange="hookRows.find(r=>r.id===${row.id}).institution=this.value; renderHookRows();"><option value="">Select institution</option>${options}</select></div>`;
            const cfg = getAssetConfig(row.assetType);
            const pinField = (cfg?.fields || []).find(f => f.vault_field === 'pin');
            if (pinField) extraFields = `<div class="field-group"><label>${pinField.label}</label><input type="password" placeholder="${pinField.placeholder || ''}" value="${row.pin}" oninput="hookRows.find(r=>r.id===${row.id}).pin=this.value"></div>`;
        }
        return `<div class="hook-row-card">
            <div class="hook-row-head"><span class="hook-row-label">Source ${idx + 1}</span>${hookMode === 'multi' && hookRows.length > 1 ? `<button class="hook-row-remove" onclick="removeHookRow(${row.id})">Remove</button>` : ''}</div>
            <div class="source-type-picker">${typeOptions}</div>
            ${row.assetType ? `
                ${institutionField}
                <div class="field-group"><label>${row.assetType === 'IDENTITY' ? 'Claimed identity balance' : 'Identifier'}</label><input placeholder="${row.assetType === 'IDENTITY' ? '' : 'Account, phone, or voucher number'}" value="${escapeHtml(row.identifier)}" ${row.assetType === 'IDENTITY' ? 'disabled' : ''} oninput="hookRows.find(r=>r.id===${row.id}).identifier=this.value"></div>
                ${extraFields}
                <div class="field-group"><label>Amount to authorize</label><input type="number" placeholder="0.00" value="${row.amount}" oninput="hookRows.find(r=>r.id===${row.id}).amount=this.value"></div>
            ` : ''}
        </div>`;
    }).join('');
}
async function confirmHookBuilder() {
    const valid = hookRows.length > 0 && hookRows.every(r => (r.locked || r.assetType) && r.amount && parseFloat(r.amount) > 0 && (r.assetType === 'IDENTITY' || r.identifier) && (r.locked || r.assetType === 'IDENTITY' || r.institution));
    if (!valid) { showMessage('Fill in each source completely — institution, identifier, and amount — before hooking.', 'warning'); return; }
    const sources = hookRows.map(r => ({
        institution: r.institution || undefined,
        asset_type: r.assetType,
        identifier: r.assetType === 'IDENTITY' ? undefined : r.identifier,
        authorized_amount: parseFloat(r.amount),
        pin: r.pin || undefined,
        wallet_pin: r.pin || undefined,
        source: r.assetType === 'IDENTITY' ? 'identity_claim' : undefined,
    }));
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/hook.php', { card_suffix: hookEntry.targetCardSuffix, sources });
    if (!result.ok) { showMessage('That hook didn\'t go through: ' + friendlyApiError(result.error), 'error'); return; }
    showHookSuccess(sources.length);
}

// ------------------------------------------------------------
// A clear success state with an explicit path back — either to
// wherever the hook was started from (Card, or My sources) or
// straight home — rather than silently snapping back a view.
// ------------------------------------------------------------
function showHookSuccess(count) {
    const cameFromLabel = hookEntry.source === 'source' ? 'My sources' : 'Card';
    const cameFromAction = hookEntry.source === 'source' ? "goView('toolbox')" : "goView('card')";
    document.getElementById('hookEyebrow').textContent = 'Done';
    document.getElementById('hookViewTitle').textContent = 'Hooked!';
    document.getElementById('hookEntryNote').textContent = '';
    document.getElementById('hookModeRow').style.display = 'none';
    document.getElementById('addHookRowBtn').style.display = 'none';
    document.getElementById('hookSubmitBtn').style.display = 'none';
    document.getElementById('hookFinePrint').style.display = 'none';
    document.getElementById('hookRowsHolder').innerHTML = `
        <div class="unhook-summary" style="border-color:var(--accent);">
            <div style="font-size:36px;margin-bottom:8px;">✓</div>
            <div style="font-size:16px;font-weight:700;color:var(--text);">${count} source${count > 1 ? 's' : ''} hooked for 24 hours</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">It's ready to use as a Swap source right away.</div>
        </div>
        <div class="cta-row" style="flex-direction:column;gap:10px;">
            <button class="btn btn-primary" onclick="viewStack=['hub']; ${cameFromAction};">&larr; Back to ${cameFromLabel}</button>
            <button class="btn secondary" onclick="goView('hub')">Go to Home</button>
        </div>`;
}

// ------------------------------------------------------------
// Scan someone else's card QR to hook to it
// ------------------------------------------------------------
function openScanToHookModal() {
    openModal('Scan a card', `
        <div id="qrScannerRegion" style="width:100%;"></div>
        <div style="text-align:center;margin:12px 0;font-size:11px;color:var(--text-dim);">or</div>
        <div class="field-group"><label>Paste the card's code manually</label><input id="manualQrPaste" placeholder="Paste QR text if you can't scan"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="submitManualQr()">Continue</button></div>`);
    try {
        html5QrScanner = new Html5Qrcode('qrScannerRegion');
        html5QrScanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 }, (decodedText) => { html5QrScanner.stop().catch(() => {}); resolveScannedQr(decodedText); }, () => {})
            .catch((e) => { console.warn('[card-qr] camera scan unavailable, manual paste only:', e); });
    } catch (e) { console.warn('[card-qr] Html5Qrcode not available, manual paste only:', e); }
}
function submitManualQr() {
    const raw = document.getElementById('manualQrPaste').value.trim();
    if (!raw) { showMessage('Paste the code first, or use the camera scanner above.', 'warning'); return; }
    resolveScannedQr(raw);
}
async function resolveScannedQr(raw) {
    if (html5QrScanner) { try { await html5QrScanner.stop(); } catch (e) {} }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Resolveqr.php', { raw });
    if (!result.ok) { showMessage('Couldn\'t read that code: ' + friendlyApiError(result.error), 'error'); return; }
    const { card_suffix, display_name } = result.body.data;
    openModal('Confirm', `<div style="text-align:center;padding:16px;"><div style="font-size:14px;margin-bottom:16px;">You're about to hook a source to <strong>${escapeHtml(display_name)}'s</strong> VouchMorph Card.</div><div class="cta-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="closeModal(); hookEntry={source:'card', targetCardSuffix:'${escapeHtml(card_suffix)}'}; hookRows=[]; hookRowSeq=0; addHookRow(); setHookMode('single'); document.getElementById('hookEyebrow').textContent='Hook to ${escapeHtml(display_name)}\\'s Card'; document.getElementById('hookEntryNote').textContent='Hooking a source to someone else\\'s card via their QR.'; pushView('hook');">Continue</button></div></div>`);
}

// ============================================================
// UNHOOK — real endpoint confirmed against the VouchMorph repo
// (public/api/v1/cards/unhook.php + CardService::releaseHook()).
// It releases an entire hook (hook_reference) as one all-or-nothing
// unit — every source in that hook together. There is no per-source
// release, so this is one "Unhook everything" action for the hook
// currently shown on the Card view, not a button per row.
// ============================================================
let unhookTarget = null;
function openUnhook(target) {
    unhookTarget = target;
    document.getElementById('unhookInstLine').textContent = `${target.count} source${target.count > 1 ? 's' : ''} in this hook`;
    document.getElementById('unhookAmountLine').textContent = target.amount;
    pushView('unhook');
}
async function confirmUnhook() {
    if (!unhookTarget) { goBack(); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/unhook.php', { hook_reference: unhookTarget.hookReference, card_suffix: myCard?.card_suffix });
    if (!result.ok) { showMessage('Couldn\'t unhook: ' + friendlyApiError(result.error), 'error'); return; }
    const data = result.body || {};
    if (data.status === 'UNHOOK_PARTIAL') showMessage('Some sources released — others could not be released automatically and remain held. See Help for what to do next.', 'warning');
    else showMessage('All hooked sources released. 🎉', 'success');
    goBack();
    loadCardView();
}

// ------------------------------------------------------------
// Contribution swaps — owner creates, everyone watches live
// ------------------------------------------------------------
function openCreateSessionModal(cardSuffix) {
    const instOptions = Object.keys(PARTICIPANTS).map(c => `<option value="${c}">${PARTICIPANTS[c]?.name || c}</option>`).join('');
    openModal('Start a swap', `
        <div class="field-group"><label>Amount needed at destination</label><input type="number" id="sessTarget" min="0.01" step="0.01" placeholder="0.00"></div>
        <div class="field-group"><label>Currency</label><select id="sessCurrency">${[...new Set(Object.values(PARTICIPANTS).map(p => p?.limits?.currency).filter(Boolean))].map(c => `<option value="${c}" ${c === (myCard.hook?.currency || myCard.currency) ? 'selected' : ''}>${c}</option>`).join('') || `<option value="${myCard.hook?.currency || myCard.currency || 'BWP'}">${myCard.hook?.currency || myCard.currency || 'BWP'}</option>`}</select></div>
        <div class="field-group"><label>Destination institution</label><select id="sessToInst"><option value="">Select</option>${instOptions}</select></div>
        <div class="field-group"><label>Destination account/wallet number</label><input id="sessToIdentifier" placeholder="Account number or phone"></div>
        <div class="field-group"><label>Strategy — how should contributions split?</label>
            <select id="sessStrategy">
                <option value="SMART" selected>Smart (recommended) — VouchMorph balances it automatically</option>
                <option value="EQUAL">Equal — split evenly across hooked sources</option>
                <option value="RATIO">Ratio — proportional to each source's balance</option>
                <option value="MANUAL">Manual — each person enters their own amount</option>
            </select>
        </div>
        <div style="font-size:11px;color:var(--text-dim);margin-bottom:12px;">Everyone currently hooked will see this in real time.</div>
        <div class="cta-row"><button class="btn btn-primary" onclick="submitCreateSession('${cardSuffix}')">Start swap</button></div>`);
}
async function submitCreateSession(cardSuffix) {
    const target = parseFloat(document.getElementById('sessTarget').value);
    const currency = document.getElementById('sessCurrency').value.trim();
    const toInst = document.getElementById('sessToInst').value;
    const toIdentifier = document.getElementById('sessToIdentifier').value.trim();
    const strategy = document.getElementById('sessStrategy').value;
    if (!(target > 0)) { showMessage('Enter the amount needed.', 'warning'); return; }
    if (!toInst || !toIdentifier) { showMessage('Select a destination institution and enter an account/wallet number.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Create.php', { card_suffix: cardSuffix, target_amount: target, currency, strategy, to_institution: toInst, destination_identifier: toIdentifier });
    if (!result.ok) { showMessage('Couldn\'t start that swap: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage('Swap started.', 'success');
    closeModal(); loadCardView();
}
function renderSessionStatus(session) {
    const preview = session.preview || { total_target: session.target_amount, total_covered: 0, remaining: session.target_amount, contributors: [] };
    const pct = preview.total_target > 0 ? Math.min(100, (preview.total_covered / preview.total_target) * 100) : 0;
    const contributors = preview.contributors || [];
    const avatarsHtml = contributors.map(c => {
        const hasContributed = (c.amount || 0) > 0;
        return `<div class="myc-avatar-tile ${c.is_me ? 'you' : ''} ${hasContributed ? '' : 'pending'}" title="${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)}">${escapeHtml(institutionInitials(c.institution))}</div>`;
    }).join('');
    const contributedCount = contributors.filter(c => (c.amount || 0) > 0).length;
    const rowsHtml = contributors.map(c => `<div class="myc-contributor-row"><div class="myc-contributor-tile">${escapeHtml(institutionInitials(c.institution))}</div><span class="myc-contributor-name">${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)} · ${escapeHtml(c.source_identifier)}${c.below_minimum ? ' <span style="color:var(--warning);">(below minimum — won\'t be included)</span>' : ''}</span><span class="myc-contributor-amt">${formatMoney(c.amount, session.currency)}</span></div>`).join('');
    const manualInput = session.strategy === 'MANUAL' ? `<div class="field-group" style="margin-top:10px;"><label>Your contribution</label><div style="display:flex;gap:8px;"><input type="number" id="myManualAmount" min="0" step="0.01" placeholder="0.00" style="flex:1;"><button class="btn btn-secondary btn-sm" onclick="submitMyManualAmount(${session.session_id})">Set</button></div></div>` : '';
    return `<div class="myc-panel"><div class="myc-panel-head"><span class="myc-panel-title">Swap in progress — ${escapeHtml(session.strategy)}</span></div><div class="myc-swap-strip">${avatarsHtml}<span class="myc-swap-strip-label">${contributors.length} hooked, ${contributedCount} have contributed</span></div><div class="comp-bar-track"><div class="comp-bar-seg" style="width:${pct}%;background:var(--accent);"></div></div><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:14px;"><span style="font-weight:700;font-family:var(--font-mono);">${formatMoney(preview.total_covered, session.currency)} of ${formatMoney(preview.total_target, session.currency)}</span><span style="color:var(--text-dim);">${preview.remaining > 0 ? formatMoney(preview.remaining, session.currency) + ' remaining' : 'Fully covered — ready to go! 🎉'}</span></div>${rowsHtml}${manualInput}<div class="cta-row" style="margin-top:14px;"><button class="btn btn-secondary" onclick="cancelSession(${session.session_id})">Cancel</button><button class="btn btn-primary" ${session.can_execute ? '' : 'disabled'} onclick="executeSession(${session.session_id})">${session.can_execute ? 'Execute swap' : 'Waiting for full coverage…'}</button></div></div>`;
}
function startSessionPolling(sessionId) {
    stopSessionPolling();
    activeSessionPollTimer = setInterval(async () => {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/ContributionStatus.php?session_id=' + sessionId);
        if (!result.ok) return;
        const session = result.body.data;
        const area = document.getElementById('sessionStatusArea');
        if (area) area.innerHTML = renderSessionStatus(session);
        if (['COMPLETED', 'CANCELLED', 'EXPIRED', 'FAILED'].includes(session.status)) stopSessionPolling();
    }, 3000);
}
function stopSessionPolling() { if (activeSessionPollTimer) { clearInterval(activeSessionPollTimer); activeSessionPollTimer = null; } }
async function submitMyManualAmount(sessionId) {
    const amount = parseFloat(document.getElementById('myManualAmount').value);
    if (isNaN(amount) || amount < 0) { showMessage('Enter a valid amount.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Contribute.php', { session_id: sessionId, amount });
    if (!result.ok) { showMessage('Couldn\'t set that contribution: ' + friendlyApiError(result.error), 'error'); return; }
    const area = document.getElementById('sessionStatusArea');
    if (area) area.innerHTML = renderSessionStatus(result.body.data);
}
async function executeSession(sessionId) {
    showConfirm('Execute this swap now?', async () => {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/execute.php', { session_id: sessionId });
        if (!result.ok) { showMessage('Execution didn\'t go through: ' + friendlyApiError(result.error), 'error'); return; }
        stopSessionPolling();
        showMessage('Swap executed successfully. 🎉', 'success');
        loadCardView();
    });
}
async function cancelSession(sessionId) {
    showConfirm('Cancel this swap session? The hooked sources stay held for a new swap — this only cancels the swap attempt itself, not the holds.', async () => {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/cancel.php', { session_id: sessionId, reason: 'Cancelled by owner' });
        if (!result.ok) { showMessage('Couldn\'t cancel: ' + friendlyApiError(result.error), 'error'); return; }
        stopSessionPolling();
        showMessage('Swap cancelled — hooked sources are still held for next time.', 'info');
        loadCardView();
    });
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    loadSavedTheme();
    const toSelect = document.getElementById('toInstSelect');
    const instOptions = Object.keys(PARTICIPANTS);
    if (toSelect && instOptions.length > 0) {
        toSelect.innerHTML = '<option value="">Select institution</option>';
        instOptions.forEach(code => { toSelect.insertAdjacentHTML('beforeend', `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`); });
    }
    document.getElementById('fromAmount')?.addEventListener('input', function() {
        state.fromAmount = parseFloat(this.value) || 0;
        const preview = document.getElementById('amountPreview');
        if (preview) preview.textContent = formatMoney(this.value, state.swapSourceMode === 'VMCARD' ? (myCard?.hook?.currency || myCard?.currency) : getInstitutionCurrency(state.fromInst));
        refreshUI();
    });
    checkPendingClaims();
    loadAgentStatus();
    loadUserSources();
    renderProgressCard();
    renderRepeatCard();
    renderView();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { if (document.getElementById('modal').classList.contains('active')) closeModal(); else if (viewStack.length > 1) goBack(); } });
</script>
</body>
</html>
