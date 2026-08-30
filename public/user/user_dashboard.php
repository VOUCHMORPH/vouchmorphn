<?php
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

.modal-overlay.active .modal {
    animation: modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.confirm-overlay.active .confirm-box {
    animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes modalPop {
    0% { transform: scale(0.92) translateY(8px); opacity: 0; }
    100% { transform: scale(1) translateY(0); opacity: 1; }
}

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

.swap-wizard { max-width: 560px; margin: 0 auto; }
.swap-step { display: none; animation: fadeInUp 0.35s ease; }
.swap-step.active { display: block; }
.swap-step-header { text-align: center; margin-bottom: 24px; }
.swap-step-number { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--text-dim); font-family: var(--font-mono); margin-bottom: 4px; }
.swap-step-title { font-size: 24px; font-weight: 700; }
.swap-step-sub { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
.swap-step-progress { display: flex; justify-content: center; gap: 8px; margin-bottom: 28px; }
.swap-step-dot { width: 40px; height: 4px; background: var(--border-strong); border-radius: 2px; transition: background 0.3s ease; }
.swap-step-dot.active { background: var(--accent); }
.swap-step-dot.done { background: var(--accent); opacity: 0.4; }
.swap-step-actions { display: flex; gap: 10px; margin-top: 20px; }
.swap-step-actions .btn { flex: 1; }
.swap-step-actions .btn-secondary { flex: 0 0 auto; }
.swap-amount-display { text-align: center; padding: 20px 0; }
.swap-amount-display .currency { font-size: 14px; color: var(--text-dim); font-weight: 600; }
.swap-amount-display input { font-size: 48px; font-weight: 700; font-family: var(--font-mono); border: none; background: transparent; text-align: center; width: 200px; color: var(--text); padding: 4px 0; }
.swap-amount-display input:focus { outline: none; border-bottom: 2px solid var(--accent); }
.swap-amount-display input::placeholder { color: var(--text-dim); font-weight: 400; }
.source-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 10px; margin-bottom: 20px; }
.source-option { border: 2px solid var(--border-strong); padding: 16px 10px; text-align: center; cursor: pointer; transition: var(--transition); background: var(--surface); }
.source-option:hover { border-color: var(--text); }
.source-option.active { border-color: var(--accent); background: var(--accent-soft); }
.source-option .icon { font-size: 28px; display: block; margin-bottom: 6px; }
.source-option .label { font-size: 11px; font-weight: 700; }
.source-option .badge { font-size: 8px; background: var(--accent-2); color: #fff; padding: 1px 6px; border-radius: 10px; margin-left: 4px; }
.source-detail-panel { border: 1px solid var(--border); padding: 18px; background: var(--surface); margin-top: 12px; }
.source-detail-panel .field-group { margin-bottom: 12px; }
.source-detail-panel .field-group:last-child { margin-bottom: 0; }
.dest-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
.dest-option { border: 2px solid var(--border-strong); padding: 16px 10px; text-align: center; cursor: pointer; transition: var(--transition); background: var(--surface); }
.dest-option:hover { border-color: var(--text); }
.dest-option.active { border-color: var(--accent); background: var(--accent-soft); }
.dest-option .icon { font-size: 24px; display: block; margin-bottom: 4px; }
.dest-option .label { font-size: 11px; font-weight: 700; }
.step-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-dim); background: var(--surface-muted); padding: 4px 12px; border: 1px solid var(--border); }
.step-badge .check { color: var(--success); }

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

.swap-destination-section { border: 1px solid var(--border); padding: 18px 16px; margin: 20px 0 4px; transition: all 0.2s ease; display: none; }
.swap-destination-section.ready { display: block; border-color: var(--accent); background: var(--accent-soft); animation: fadeInUp 0.3s ease; }
.swap-destination-heading { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-dim); margin-bottom: 12px; transition: color 0.2s ease; }
.swap-destination-section.ready .swap-destination-heading { color: var(--accent); }
.swap-dest-types { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }

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

.field-label { font-size: 11px; color: var(--text-dim); text-transform: uppercase; display: block; margin-bottom: 6px; font-weight: 700; letter-spacing: 0.05em; }
.field-group { margin-bottom: 14px; }
.field-group label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; letter-spacing: 0.05em; }
.field-group input, .field-group select { width: 100%; padding: 12px 14px; background: var(--surface); border: 1px solid var(--border-strong); color: var(--text); font-size: 14px; font-family: var(--font); }
.field-group input:focus, .field-group select:focus { outline: none; border-color: var(--accent); }
.field-group .help { font-size: 11px; color: var(--text-dim); margin-top: 5px; }
.field-group .help.error-help { color: var(--danger); font-weight: 600; }

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

.dropdown-select { border: 1px solid var(--border-strong); background: var(--surface); position: relative; }
.dropdown-select-trigger { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 16px; cursor: pointer; font-size: 14px; }
.dropdown-select-trigger .placeholder { color: var(--text-dim); }
.dropdown-select-trigger .chosen { color: var(--text); font-weight: 700; }
.dropdown-select-chevron { color: var(--text-dim); font-size: 12px; transition: transform 0.15s ease; flex-shrink: 0; }
.dropdown-select.open .dropdown-select-chevron { transform: rotate(180deg); }
.dropdown-select-panel { display: none; border-top: 1px solid var(--border-strong); max-height: 280px; overflow-y: auto; }
.dropdown-select.open .dropdown-select-panel { display: block; }
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

.toolbox-accordion { border: 1px solid var(--border); margin-bottom: 12px; background: var(--surface); }
.toolbox-accordion-summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text); font-family: var(--font-mono); }
.toolbox-accordion-summary::-webkit-details-marker { display: none; }
.toolbox-accordion-arrow { color: var(--text-dim); transition: transform 0.15s ease; font-size: 16px; }
.toolbox-accordion[open] > .toolbox-accordion-summary .toolbox-accordion-arrow { transform: rotate(90deg); }
.toolbox-accordion > .toolbox-list, .toolbox-accordion > .myc-panel { border-top: 1px solid var(--border); border-left: none; border-right: none; border-bottom: none; }

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

.strategy-row { display: flex; border: 1px solid var(--border-strong); margin-bottom: 10px; }
.strategy-row button { flex: 1; padding: 10px 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: transparent; border: none; border-right: 1px solid var(--border-strong); color: var(--text-muted); cursor: pointer; font-family: var(--font); }
.strategy-row button:last-child { border-right: none; }
.strategy-row button.active { background: var(--primary); color: #fff; }
.card-source-breakdown { border: 1px solid var(--border); padding: 14px; margin: 12px 0; }
.card-source-breakdown-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 12px; }
.card-source-breakdown-row:last-child { border-bottom: none; }

.tab-strategy-hint { text-align: center; font-size: 11px; color: var(--text-dim); margin-bottom: 14px; }
.tab-status-line { text-align: center; font-size: 12px; margin-bottom: 14px; padding: 10px; font-weight: 600; border: 1px solid var(--border); }
.tab-status-line.ok { color: var(--success); border-color: var(--success); }
.tab-status-line.warn { color: #8a6508; border-color: var(--warning); }
.tab-status-line.bad { color: var(--danger); border-color: var(--danger); }

.qr-full-wrap { max-width: 460px; margin: 0 auto; padding: 56px 24px; text-align: center; }
.qr-frame-lg { background: #fff; padding: 30px; border: 1px solid var(--border-strong); display: inline-block; margin-bottom: 24px; }
.qr-full-suffix { font-family: var(--font-mono); font-size: 20px; font-weight: 700; letter-spacing: 0.08em; margin-bottom: 6px; }
.qr-full-name { font-size: 13px; color: var(--text-muted); }
.qr-full-hint { font-size: 12px; color: var(--text-muted); margin-top: 20px; }

.entry-point-note { font-size: 11px; color: var(--text-dim); text-align: center; margin-bottom: 18px; }
.hook-mode-row { display: flex; border: 1px solid var(--border-strong); margin-bottom: 18px; }
.hook-mode-row button { flex: 1; padding: 11px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; background: transparent; border: none; color: var(--text-muted); cursor: pointer; border-right: 1px solid var(--border-strong); font-family: var(--font); }
.hook-mode-row button:last-child { border-right: none; }
.hook-mode-row button.active { background: var(--primary); color: #fff; }
.source-type-picker { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px; }
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
@media (min-width: 900px) {
    .product-view-inner { max-width: 640px; }
    .product-view-inner.wide { max-width: 960px; }
    .myc-qr-panel { position: sticky; top: 88px; }
}

.page-footer { max-width: var(--max-w); width: 100%; margin: 0 auto; padding: 24px 24px 32px; border-top: 1px solid var(--border); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.page-footer .footer-copy { font-size: 11px; color: var(--text-dim); font-family: var(--font-mono); }
.page-footer .footer-links { display: flex; gap: 20px; font-size: 12px; }
.page-footer .footer-links span { color: var(--text-muted); font-weight: 600; cursor: pointer; }
.page-footer .footer-links span:hover { color: var(--accent); text-decoration: underline; }
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

.source-grid.hidden { display: none; }
.source-type-back-link { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--text-muted); background: none; border: none; cursor: pointer; margin-bottom: 16px; padding: 0; text-transform: uppercase; letter-spacing: 0.04em; }
.source-type-back-link:hover { color: var(--text); }

.combine-layout { display: block; }
.combine-col-right { margin-top: 16px; }
@media (min-width: 900px) {
    .combine-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; align-items: start; }
    .combine-col-right { margin-top: 0; position: sticky; top: 88px; }
}

.arcade-console{background:#0b0f10;border:3px solid #2c3a3d;padding:16px;font-family:var(--font-mono);color:#d8f5df}
.arcade-readout{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:6px}
.arcade-num{font-size:30px;font-weight:700;color:#39ff6a;letter-spacing:1px}
.arcade-target-input{background:#000;color:#39ff6a;border:2px solid #39ff6a;font-family:var(--font-mono);font-size:16px;width:90px;text-align:right;padding:4px 6px}
.arcade-strip{display:flex;gap:2px;margin:12px 0}
.arcade-seg{flex:1;height:14px;background:#12181a;border:1px solid #2c3a3d}
.arcade-seg.on{background:#39ff6a}
.arcade-strat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:4px;margin-bottom:6px}
.arcade-strat-btn{background:#000;color:#8ee6a3;border:1px solid #2c3a3d;font-family:var(--font-mono);font-size:9px;padding:7px 2px;text-transform:uppercase;cursor:pointer}
.arcade-strat-btn.active{background:#39ff6a;color:#04240f;border-color:#39ff6a}
.arcade-hint{font-size:10px;color:#6f9c7d;text-align:center;margin:6px 0 14px}
.arcade-row{display:flex;align-items:center;gap:8px;padding:8px 6px;border:1px solid #2c3a3d;margin-bottom:6px;background:#0f1517}
.arcade-row .swatch{width:10px;height:10px;flex-shrink:0}
.arcade-row .title{font-size:11px;color:#d8f5df}
.arcade-row .sub{font-size:9px;color:#6f9c7d}
.arcade-row .amt{font-family:var(--font-mono);font-weight:700;color:#39ff6a}
.arcade-status{margin-top:12px;padding:9px;text-align:center;font-size:11px;font-weight:700;border:2px solid #2c3a3d;letter-spacing:0.5px}
.arcade-status.ready{border-color:#39ff6a;color:#39ff6a;background:rgba(57,255,106,0.08)}
.arcade-add-btn{width:100%;padding:10px;background:#000;border:1px dashed #2c3a3d;color:#8ee6a3;font-family:var(--font-mono);font-size:10px;text-transform:uppercase;cursor:pointer;margin-top:6px}    
.msb-card{background:var(--surface);border:1px solid var(--border-strong);padding:1.25rem}    
.combine-row-card { display: flex; align-items: center; gap: 12px; border: 1px solid var(--border); padding: 12px; margin-bottom: 8px; background: var(--surface); }
.combine-row-main { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.combine-row-icon { font-size: 18px; flex-shrink: 0; }
.combine-row-title { font-size: 13px; font-weight: 700; }
.combine-row-sub { font-size: 11px; color: var(--text-dim); }
.combine-row-amt { font-family: var(--font-mono); font-weight: 700; flex-shrink: 0; }
.combine-row-actions { display: flex; gap: 8px; flex-shrink: 0; }

.combine-type-picker { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 16px; }
.combine-type-picker .source-type-opt.disabled { opacity: 0.35; cursor: not-allowed; }

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

.asset-fields { margin: 8px 0 14px; padding: 14px; background: var(--surface-muted); border: 1px solid var(--border); }
.identity-field { margin: 8px 0 14px; padding: 14px; background: var(--accent-soft); border: 1px dashed var(--accent); }
.unhook-summary { border: 1px solid var(--border); padding: 20px; text-align: center; margin-bottom: 18px; }

#identitySwapHint { display: none; background: var(--accent-soft); border-left: 3px solid var(--accent); padding: 12px 14px; font-size: 12px; margin-top: 8px; color: var(--text-muted); }
#swapReadinessHint { text-align: center; font-size: 12px; color: var(--text-muted); margin-top: 12px; display: none; }
#swapReadinessHint.show { display: block; }
#swapReadinessHint.warning { background: rgba(184,134,11,0.08); border-left: 3px solid var(--warning); padding: 12px 16px; }
#swapReadinessHint.success { background: rgba(31,138,84,0.08); border-left: 3px solid var(--success); padding: 12px 16px; color: var(--success); }    
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

<div class="view" id="swapView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Move money</div>
            <div class="product-view-title">Swap <button class="info-btn" onclick="openHowItWorks('swap')" title="How Swap works" aria-label="How Swap works">?</button></div>
        </div>
        <div id="repeatCardHolder"></div>
        <div class="swap-wizard">
            <div class="swap-step-progress" id="stepDots">
                <div class="swap-step-dot active" data-step="1"></div>
                <div class="swap-step-dot" data-step="2"></div>
                <div class="swap-step-dot" data-step="3"></div>
                <div class="swap-step-dot" data-step="4"></div>
            </div>

            <div class="swap-step active" data-step="1">
                <div class="swap-step-header">
                    <div class="swap-step-number">Step 1 of 4</div>
                    <div class="swap-step-title">How much?</div>
                    <div class="swap-step-sub">Enter the amount you want to swap</div>
                </div>
                <div class="swap-amount-display">
                    <div class="currency">BWP</div>
                    <input type="number" id="wizardAmount" placeholder="0.00" step="0.01" min="0.01" autofocus>
                </div>
                <div class="field-group" style="margin-top:8px;">
                    <label>From currency</label>
                    <select id="wizardCurrency" onchange="updateWizardCurrency()">
                        <option value="BWP">BWP — Botswana Pula</option>
                        <option value="ZAR">ZAR — South African Rand</option>
                        <option value="USD">USD — US Dollar</option>
                    </select>
                </div>
                <div class="swap-step-actions">
                    <button class="btn btn-primary" onclick="wizardNext()">Next: Where from? →</button>
                </div>
            </div>

            <div class="swap-step" data-step="2">
                <div class="swap-step-header">
                    <div class="swap-step-number">Step 2 of 4</div>
                    <div class="swap-step-title">Where from?</div>
                    <div class="swap-step-sub">Pick where the money comes from</div>
                </div>
                <div class="source-grid" id="sourceGrid">
                    <div class="source-option" data-source="WALLET" onclick="selectSource('WALLET')">
                        <span class="icon">💳</span>
                        <span class="label">Wallet / Account</span>
                    </div>
                    <div class="source-option" data-source="CARD" onclick="selectSource('CARD')">
                        <span class="icon">🪪</span>
                        <span class="label">Card</span>
                    </div>
                    <div class="source-option" data-source="VOUCHER" onclick="selectSource('VOUCHER')">
                        <span class="icon">🎟️</span>
                        <span class="label">Voucher</span>
                    </div>
                    <div class="source-option" data-source="VMCARD" onclick="selectSource('VMCARD')">
                        <span class="icon">🧩</span>
                        <span class="label">My Card <span class="badge">Hooked</span></span>
                    </div>
                    <div class="source-option" data-source="COMBINE" onclick="selectSource('COMBINE')">
                        <span class="icon">➕</span>
                        <span class="label">Combine</span>
                    </div>
                </div>
                <div id="sourceDetailPanel" class="source-detail-panel" style="display:none;"></div>
                <div class="swap-step-actions">
                    <button class="btn btn-secondary" onclick="wizardPrev()">← Back</button>
                    <button class="btn btn-primary" id="wizardSourceNext" onclick="wizardNext()" disabled>Next: Where to? →</button>
                </div>
            </div>


            <div class="swap-step" data-step="3">
                <div class="swap-step-header">
                    <div class="swap-step-number">Step 3 of 4</div>
                    <div class="swap-step-title">Where to?</div>
                    <div class="swap-step-sub">Pick where the money goes</div>
                </div>
                <div class="dest-grid">
                    <div class="dest-option" data-dest="DEPOSIT" onclick="selectDestination('DEPOSIT')">
                        <span class="icon">🏦</span>
                        <span class="label">Deposit</span>
                    </div>
                    <div class="dest-option" data-dest="CASHOUT" onclick="selectDestination('CASHOUT')">
                        <span class="icon">💵</span>
                        <span class="label">Cashout</span>
                    </div>
                    <div class="dest-option" data-dest="IDENTITY" onclick="selectDestination('IDENTITY')">
                        <span class="icon">🪪</span>
                        <span class="label">Identity</span>
                    </div>
                </div>
                <div id="destDetailPanel" class="source-detail-panel" style="display:none;"></div>
                <div class="swap-step-actions">
                    <button class="btn btn-secondary" onclick="wizardPrev()">← Back</button>
                    <button class="btn btn-primary" id="wizardDestNext" onclick="wizardNext()" disabled>Next: Review →</button>
                </div>
            </div>
            <div class="swap-step" data-step="4">
                <div class="swap-step-header">
                    <div class="swap-step-number">Step 4 of 4</div>
                    <div class="swap-step-title">Review</div>
                    <div class="swap-step-sub">Preview the fee, then confirm</div>
                </div>
                <div id="reviewPanel" style="background:var(--surface-muted);padding:16px;border:1px solid var(--border);margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                        <span style="font-size:12px;color:var(--text-dim);">Amount</span>
                        <span style="font-weight:700;font-family:var(--font-mono);" id="reviewAmount">—</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                        <span style="font-size:12px;color:var(--text-dim);">From</span>
                        <span style="font-weight:700;" id="reviewSource">—</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;">
                        <span style="font-size:12px;color:var(--text-dim);">To</span>
                        <span style="font-weight:700;" id="reviewDest">—</span>
                    </div>
                </div>
                <div id="reviewPreviewResult" style="display:none;background:var(--surface);border:1px solid var(--accent);padding:14px 16px;margin-bottom:16px;"></div>
                <div class="preview-security" style="margin:0 0 16px;">
                    <div class="preview-security-icon">🔒</div>
                    <div class="preview-security-text">
                        <strong>Your money is protected</strong>
                        <p>Nothing moves until you tap Confirm below.</p>
                    </div>
                </div>
                <div class="swap-step-actions" id="wizardPreviewRow">
                    <button class="btn btn-secondary" onclick="wizardPrev()">← Back</button>
                    <button class="btn btn-primary" id="wizardPreviewBtn" onclick="wizardPreview()">Preview swap</button>
                </div>
                <div class="swap-step-actions" id="wizardConfirmRow" style="display:none;">
                    <button class="btn btn-secondary" onclick="wizardPrev()">← Back</button>
                    <button class="btn btn-primary" id="wizardConfirmBtn" onclick="wizardConfirm()">Confirm & swap</button>
                </div>
            </div>

            <div style="margin-top:20px;text-align:center;">
                <button type="button" class="ledger-link" onclick="viewWalletBalance()">View balance</button>
            </div>
        </div>
    </div>
</div>

<div class="view" id="cardView">
    <div class="product-view-inner wide">
        <div class="product-view-header">
            <div class="product-view-eyebrow">VouchMorph Card</div>
            <div class="product-view-title">My Card <button class="info-btn" onclick="openHowItWorks('card')" title="How the Card works" aria-label="How the Card works">?</button></div>
        </div>
        <div id="cardViewBody"><div style="text-align:center;padding:40px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading...</div></div>
    </div>
</div>

<div class="view" id="qrfullView">
    <div class="qr-full-wrap">
        <div class="qr-frame-lg" id="qrHolderLg"></div>
        <div class="qr-full-suffix" id="qrFullSuffix"></div>
        <div class="qr-full-name" id="qrFullName"></div>
        <div class="qr-full-hint">Anyone with VouchMorph can scan this to hook their own source to your card.</div>
    </div>
</div>

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
        <div style="text-align:center;margin-bottom:14px;">
            <span class="quick-link" onclick="addAllSavedSourcesToHook()">⚡ Hook all my saved sources at once</span>
        </div>
        <div id="hookRowsHolder"></div>
        <button class="add-row-btn" id="addHookRowBtn" style="display:none;" onclick="addHookRow()">+ Add another source</button>
        <button class="btn" id="hookSubmitBtn" onclick="confirmHookBuilder()">Hook to card</button>
        <div style="font-size:11px;color:var(--text-dim);text-align:center;margin-top:14px;" id="hookFinePrint">Each source is held for 24 hours per its own authorized amount.</div>
    </div>
</div>

<div class="view" id="unhookView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Release this hook</div>
            <div class="product-view-title">Unhook everything</div>
        </div>
        <div id="unhookPreviewHolder"></div>
        <button class="btn" style="background:var(--danger);" id="unhookConfirmBtn" onclick="executeUnhook()">Unhook everything</button>
        <button class="btn secondary" style="margin-top:10px;" onclick="goBack()">Keep it hooked</button>
    </div>
</div>

<div class="view" id="activityView">
    <div class="product-view-inner wide">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Your history</div>
            <div class="product-view-title">Activity</div>
        </div>
        <div id="activityViewBody"><div style="text-align:center;padding:40px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading...</div></div>
    </div>
</div>

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
                    <div id="savedSourcesDropdown" class="dropdown-select">
                        <div class="dropdown-select-trigger" onclick="toggleSavedSourceDropdown()">
                            <span id="savedSourceTriggerLabel" class="placeholder">Select a saved source &rsaquo;</span>
                            <span class="dropdown-select-chevron">&#9662;</span>
                        </div>
                        <div class="dropdown-select-panel" id="savedSourcesChips"></div>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-top:8px;">
                        <div style="font-size:11px;color:var(--text-dim);">Pick a saved source to auto-fill it. <span class="quick-link muted" onclick="openAddSource()">+ Add another</span></div>
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
            <span class="quick-link" id="depositToggleBtn" onclick="setSwapType('DEPOSIT')">🏦 Deposit</span>
            <span class="quick-link" id="cashoutToggleBtn" onclick="setSwapType('CASHOUT')">💵 Cashout</span>
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
</div>

<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" id="modalContent">
        <div class="modal-header">
            <h2 id="modalTitle">Preview</h2>
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

// ============================================================
// ENHANCED ASSET TYPE MAPPING (SwapService ↔ GenericBank)
// ============================================================
const ASSET_TYPE_ALIASES = {
    'WALLET': ['MNO-WALLET', 'BANK-WALLET', 'MOBILE_WALLET', 'WALLET'],
    'ACCOUNT': ['ACCOUNT', 'BANK-ACCOUNT', 'SAVINGS-ACCOUNT', 'CURRENT-ACCOUNT'],
    'CARD': ['CARD', 'VISA_MASTERCARD_CARD', 'DEBIT-CARD', 'CREDIT-CARD'],
    'VOUCHER': ['VOUCHER', 'CASHOUT-VOUCHER', 'GIFT-VOUCHER'],
    'ATM': ['ATM', 'CASHOUT-ATM'],
};

const ASSET_ICONS = {
    'ACCOUNT': '🏦', 'WALLET': '💳', 'MNO-WALLET': '📱', 'BANK-WALLET': '🏦',
    'CARD': '🪪', 'VOUCHER': '🎟️', 'ATM': '🏧', 'POSTAL-ORDER': '✉️',
    'CHEQUE': '🧾', 'CRYPTO': '🪙'
};

// Normalize asset type between systems
function normalizeAssetType(genericType) {
    if (!genericType) return null;
    const upper = String(genericType).toUpperCase().replace(/[-_\s]/g, '');
    for (const [key, aliases] of Object.entries(ASSET_TYPE_ALIASES)) {
        for (const alias of aliases) {
            const normalizedAlias = alias.replace(/[-_\s]/g, '');
            if (normalizedAlias === upper) return key;
            if (upper.includes(normalizedAlias) || normalizedAlias.includes(upper)) return key;
        }
    }
    const assetKeys = Object.keys(ASSETS);
    for (const key of assetKeys) {
        const normalizedKey = key.replace(/[-_\s]/g, '').toUpperCase();
        if (upper.includes(normalizedKey) || normalizedKey.includes(upper)) return key;
    }
    return genericType;
}

function assetIcon(type) { if (!type) return '💠'; return ASSET_ICONS[String(type).toUpperCase()] || '💠'; }

function getAssetConfig(type) {
    if (!type) return null;
    const normalized = normalizeAssetType(type);
    if (ASSETS[normalized]) return ASSETS[normalized];
    if (ASSETS[type]) return ASSETS[type];
    const upper = String(type).trim().toUpperCase();
    if (ASSETS[upper]) return ASSETS[upper];
    const alias = ASSET_TYPE_ALIASES[upper];
    if (alias) {
        if (Array.isArray(alias)) {
            for (const a of alias) {
                if (ASSETS[a]) return ASSETS[a];
                if (ASSETS[normalizeAssetType(a)]) return ASSETS[normalizeAssetType(a)];
            }
        }
        else if (ASSETS[alias]) return ASSETS[alias];
    }
    const assetKeys = Object.keys(ASSETS);
    for (const key of assetKeys) {
        const normalizedKey = key.replace(/[-_\s]/g, '').toUpperCase();
        const normalizedType = upper.replace(/[-_\s]/g, '');
        if (normalizedKey.includes(normalizedType) || normalizedType.includes(normalizedKey)) return ASSETS[key];
    }
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

// ============================================================
// STATE MANAGEMENT
// ============================================================
let state = {
    fromCategory: 'WALLET', fromInst: null, fromAsset: null, fromFields: {}, fromAmount: 0,
    swapType: 'DEPOSIT', toInst: null, toAsset: null, toFields: {},
    deliveryMethod: 'ATM', beneficiaryPhone: '',
    toIdentityType: 'national_id', toIdentityValue: '', toIdentitySms: '',
    multiSources: [], lastPreview: null, swapPayload: null,
    tabTotalAmount: 0, tabAllocationMode: 'even', contributionStrategy: 'SMART',
    swapSourceMode: 'WALLET',
    vmCardStrategy: 'SMART',
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
let vmCardSources = null;
let activeSessionPollTimer = null;
let html5QrScanner = null;

let pendingExecution = {
    type: null,
    payload: null,
    callback: null,
};

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
// VIEW NAVIGATION
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
    if (name === 'swap') { initWizard(); }
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
        homeBtn.classList.add('show');
        const prev = viewStack[viewStack.length - 2];
        document.getElementById('backLabel').textContent = (!prev || prev === 'hub') ? 'All products' : (VIEW_LABELS[prev] || 'Back');
        document.getElementById('brandLabel').textContent = VIEW_LABELS[current] || current;
    }
    window.scrollTo(0, 0);
}

// ============================================================
// THEME
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

// ============================================================
// JOURNEY / PROGRESS
// ============================================================
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

// ============================================================
// API HELPERS
// ============================================================
function buildHeaders() {
    const headers = { 'Content-Type': 'application/json' };
    if (CONFIG.COUNTRY_CODE) headers['X-Country-Code'] = CONFIG.COUNTRY_CODE;
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
// UI HELPERS
// ============================================================
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

function returnMovableNodesHome() {
    const offscreen = document.getElementById('offscreenNodes');
    const toInstSlot = document.getElementById('toInstAssetGroupSlot');
    const toInstGroup = document.getElementById('toInstAssetGroup');
    if (toInstGroup && toInstSlot && toInstGroup.parentElement !== toInstSlot) toInstSlot.appendChild(toInstGroup);
    ['fromSection', 'toSection', 'identityFields'].forEach(id => {
        const el = document.getElementById(id);
        if (el && offscreen && el.parentElement !== offscreen) offscreen.appendChild(el);
    });
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

function showPreviewModal(title, bodyHtml, onConfirm, confirmText = 'Confirm', confirmStyle = 'btn-primary') {
    const actions = `
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal(); pendingExecution = { type: null, payload: null, callback: null };">Cancel</button>
            <button class="btn ${confirmStyle}" onclick="executePendingAction()">${confirmText}</button>
        </div>
    `;
    const fullHtml = bodyHtml + actions;
    openModal(title, fullHtml);
}

function executePendingAction() {
    if (!pendingExecution.callback) {
        closeModal();
        return;
    }
    const cb = pendingExecution.callback;
    closeModal();
    cb();  // ← runs first, while payload is still intact
    pendingExecution = { type: null, payload: null, callback: null };  // ← reset after
}

// ============================================================
// SWAP WIZARD — COMPLETE FIXED VERSION
// ============================================================
let wizardState = {
    step: 1,
    amount: 0,
    currency: 'BWP',
    source: null,
    fromInst: null,
    fromAsset: null,
    fromFields: {},
    destType: null,
    toInst: null,
    toAsset: null,
    toFields: {},
    deliveryMethod: 'ATM',
    beneficiaryPhone: '',
    identityType: 'national_id',
    identityValue: '',
    identitySms: '',
    multiSources: [],
    contributionStrategy: 'SMART',
    tabTotalAmount: 0,
    vmCardStrategy: 'SMART',
    lastPreview: null,
    swapPayload: null,
    combineView: 'list',
    combineEditingRowId: null,
};

let multiSourceSeq = 0;
let combineEditSnapshot = null;

// ---- STEP NAVIGATION ----
function wizardNext() {
    const current = wizardState.step;
    if (current === 1 && !validateStep1()) return;
    if (current === 2 && !validateStep2()) return;
    if (current === 3 && !validateStep3()) return;
    if (current === 4) return;
    const next = current + 1;
    if (next > 4) return;
    wizardState.step = next;
    renderStep(next);
}

function wizardPrev() {
    if (wizardState.step <= 1) return;
    wizardState.step--;
    renderStep(wizardState.step);
}

function institutionInitials(code) {
    const name = PARTICIPANTS[code]?.name || code || '';
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return name.slice(0, 2).toUpperCase() || '??';
}

function renderStep(step) {
    document.querySelectorAll('.swap-step').forEach(el => el.classList.remove('active'));
    const target = document.querySelector(`.swap-step[data-step="${step}"]`);
    if (target) target.classList.add('active');

    document.querySelectorAll('.swap-step-dot').forEach((dot, i) => {
        dot.classList.remove('active', 'done');
        if (i + 1 === step) dot.classList.add('active');
        else if (i + 1 < step) dot.classList.add('done');
    });

    document.querySelector('.swap-wizard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    
    if (step === 1) {
        const input = document.getElementById('wizardAmount');
        if (input && wizardState.amount > 0) {
            input.value = wizardState.amount;
        }
        setTimeout(() => input?.focus(), 100);
    }
    if (step === 4) enterReviewStep();
}

// ---- STEP VALIDATION ----
function validateStep1() {
    const input = document.getElementById('wizardAmount');
    wizardState.amount = parseFloat(input.value) || 0;
    wizardState.currency = document.getElementById('wizardCurrency').value;
    if (wizardState.amount <= 0) {
        showMessage('Please enter an amount to swap.', 'warning');
        input.focus();
        return false;
    }
    return true;
}

function validateStep2() {
    if (!wizardState.source) {
        showMessage('Please pick where the money comes from.', 'warning');
        return false;
    }

    if (['WALLET', 'CARD', 'VOUCHER'].includes(wizardState.source)) {
        if (!wizardState.fromInst) {
            showMessage('Please select an institution.', 'warning');
            return false;
        }
        const valid = fieldsValidForAsset(wizardState.fromAsset, wizardState.fromFields, true);
        if (!valid.valid) {
            showMessage(valid.friendlyMessage || 'Please fill in all required fields.', 'warning');
            return false;
        }
    }

    if (wizardState.source === 'VMCARD') {
        if (!myCard || !myCard.is_active) {
            showMessage('Your VouchMorph Card is not active yet.', 'warning');
            return false;
        }
        if (!vmCardSources || vmCardSources.length === 0) {
            showMessage('Nothing is hooked to your card yet.', 'warning');
            return false;
        }
        const merged = dedupeVmCardSources(vmCardSources);
        const total = merged.reduce((s, c) => s + c.amount, 0);
        if (wizardState.amount > total + 0.01) {
            showMessage(`You have ${formatMoney(total, wizardState.currency)} hooked — enter a lower amount, or hook more first.`, 'warning');
            return false;
        }
    }

    if (wizardState.source === 'COMBINE') {
        if (!multiSourcesValid()) {
            showMessage('Complete your combined sources first.', 'warning');
            return false;
        }
    }

    return true;
}

function getAccountIdentifier(assetType, fields) {
    if (!assetType || !fields) return null;
    const config = getAssetConfig(assetType);
    if (!config) return null;
    const idField = (config.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    if (!idField) return null;
    return fields[idField.name] || null;
}

function isSameAccount(inst1, inst2, assetType1, fields1, assetType2, fields2) {
    // Must be same institution
    if (inst1 !== inst2) return false;
    
    // Get identifiers
    const id1 = getAccountIdentifier(assetType1, fields1);
    const id2 = getAccountIdentifier(assetType2, fields2);
    
    // If either is missing, we can't compare
    if (!id1 || !id2) return false;
    
    // Normalize and compare
    const normalized1 = String(id1).trim().replace(/[^0-9a-zA-Z]/g, '');
    const normalized2 = String(id2).trim().replace(/[^0-9a-zA-Z]/g, '');
    
    return normalized1 === normalized2;
}

function validateStep3() {
    if (!wizardState.destType) {
        showMessage('Please pick where the money goes.', 'warning');
        return false;
    }

    if (wizardState.destType === 'IDENTITY') {
        if (!wizardState.identityValue) {
            showMessage('Please enter the identity value (phone, email, or ID).', 'warning');
            return false;
        }
        return true;
    }

    if (wizardState.destType === 'CASHOUT') {
        if (!wizardState.toInst) {
            showMessage('Please select a destination institution.', 'warning');
            return false;
        }
        if (!wizardState.beneficiaryPhone) {
            showMessage('Please enter the beneficiary phone number.', 'warning');
            return false;
        }
        return true;
    }

    if (!wizardState.toInst) {
        showMessage('Please select a destination institution.', 'warning');
        return false;
    }
    if (!wizardState.toAsset) {
        showMessage('Please select an asset type.', 'warning');
        return false;
    }
    
    // ============================================================
    // FIXED VALIDATION: Same institution + Same identifier = NOT allowed
    // Same institution + Different identifier = ALLOWED
    // ============================================================
    if (['WALLET', 'CARD', 'VOUCHER'].includes(wizardState.source) && wizardState.fromInst && wizardState.toInst) {
        const isSame = isSameAccount(
            wizardState.fromInst,
            wizardState.toInst,
            wizardState.fromAsset,
            wizardState.fromFields,
            wizardState.toAsset,
            wizardState.toFields
        );
        
        if (isSame) {
            showMessage('You cannot send money from an account to itself. Please choose a different destination account, wallet, or card number.', 'warning');
            return false;
        }
        
        if (wizardState.fromInst === wizardState.toInst) {
            showMessage('Transferring between different accounts at ' + (PARTICIPANTS[wizardState.toInst]?.name || wizardState.toInst) + '.', 'info');
        }
    }

    // ============================================================
    // 👇👇👇 PASTE THE NEW BLOCK RIGHT HERE 👇👇👇
    // ============================================================
    const destIdField = (getAssetConfig(wizardState.toAsset)?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    const destIdentifier = destIdField ? wizardState.toFields[destIdField.name] : null;
    if (wizardState.toInst && destIdentifier) {
        const hookedMatch = isDestinationCurrentlyHooked(wizardState.toInst, destIdentifier);
        if (hookedMatch) {
            showMessage(
                `That exact account is currently hooked to your VouchMorph Card as a funding source — it's held right now, not free to receive into. ` +
                `Pick a different destination, or unhook it first from Card → Unhook everything if you want to use it this way.`,
                'warning'
            );
            return false;
        }
    }
    // ============================================================
    // 👆👆👆 END NEW BLOCK 👆👆👆
    // ============================================================
    
    const valid = fieldsValidForAsset(wizardState.toAsset, wizardState.toFields, false);
    if (!valid.valid) {
        showMessage(valid.friendlyMessage || 'Please fill in all required destination fields.', 'warning');
        return false;
    }
    return true;
}
    

function isDestinationCurrentlyHooked(destInst, destIdentifier) {
    if (!vmCardSources) return null;
    return vmCardSources.find(c =>
        String(c.institution).toUpperCase() === String(destInst).toUpperCase() &&
        normalizeIdent(c.identifier) === normalizeIdent(destIdentifier)
    ) || null;
}

// ---- STEP 2 — SOURCE SELECTION ----
function selectSource(type) {
    wizardState.source = type;
    document.querySelectorAll('.source-option').forEach(el => {
        el.classList.toggle('active', el.dataset.source === type);
    });
    document.getElementById('sourceGrid')?.classList.add('hidden');

    const panel = document.getElementById('sourceDetailPanel');
    const nextBtn = document.getElementById('wizardSourceNext');

    if (type === 'VMCARD' || type === 'COMBINE') {
        panel.style.display = 'none';
        if (type === 'VMCARD') {
            if (!vmCardSources || vmCardSources.length === 0) {
                loadVmCardSources().then(() => {
                    if (vmCardSources && vmCardSources.length > 0) {
                        renderVmCardBreakdownWizard();
                        nextBtn.disabled = false;
                    } else {
                        renderVmCardEmptyState(panel);
                        nextBtn.disabled = true;
                    }
                });
                return;
            }
            renderVmCardBreakdownWizard();
            nextBtn.disabled = false;
            return;
        }
        if (type === 'COMBINE') {
            wizardState.combineView = wizardState.combineView || 'list';
            if (!wizardState.tabTotalAmount) wizardState.tabTotalAmount = wizardState.amount;
            renderCombineSummaryWizard();
        }
        nextBtn.disabled = false;
        return;
    }

    panel.style.display = 'block';
    wizardState.fromInst = null;
    wizardState.fromAsset = type;
    wizardState.fromFields = {};
    nextBtn.disabled = true;
    renderWizardSourcePicker(panel, type);
}

async function loadVmCardSources() {
    if (!myCard) {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/My.php');
        if (!result.ok) { vmCardSources = null; return; }
        myCard = result.body.data;
    }
    if (!myCard.is_active) { vmCardSources = null; return; }
    const sourcesResult = await callApi(CONFIG.API_BASE + '/api/v1/cards/GetCardSources.php', { card_suffix: myCard.card_suffix });
    if (sourcesResult.ok) {
        const sources = sourcesResult.body.data?.sources || sourcesResult.body.data || [];
        vmCardSources = sources;
    } else {
        vmCardSources = null;
    }
}

function renderVmCardEmptyState(panel) {
    if (!panel) return;
    panel.style.display = 'block';
    panel.innerHTML = `
        <button type="button" class="source-type-back-link" onclick="wizardBackToSourceGrid()">&larr; Change source type</button>
        <div class="empty-source-box">
            <p style="margin-bottom:10px;">Nothing is hooked to your card yet.</p>
            <button class="btn btn-primary" onclick="openHookBuilder('swapwizard')">+ Hook a source now</button>
        </div>`;
}

function normalizeIdent(v) { return String(v || '').trim().replace(/[^0-9a-zA-Z]/g, '').toLowerCase(); }

function findAlreadyHooked(institution, identifier, assetType) {
    if (!vmCardSources) return null;
    return vmCardSources.find(c =>
        String(c.institution).toUpperCase() === String(institution).toUpperCase() &&
        normalizeIdent(c.identifier) === normalizeIdent(identifier)
    ) || null;
}

async function confirmHookBuilder() {
    const valid = hookRows.length > 0 && hookRows.every(r => (r.locked || r.assetType) && r.amount && parseFloat(r.amount) > 0 && r.identifier && (r.locked || r.institution));
    if (!valid) { showMessage('Fill in each source completely — institution, identifier, and amount — before hooking.', 'warning'); return; }

    // NEW: block duplicate hooks with an educational message
    for (const r of hookRows) {
        const dupe = findAlreadyHooked(r.institution, r.identifier, r.assetType);
        if (dupe) {
            const instName = PARTICIPANTS[r.institution]?.name || r.institution;
            showMessage(
                `${instName} · ${r.identifier} is already hooked to your card with ${formatMoney(dupe.available_balance ?? dupe.authorized_amount ?? 0, myCard?.currency || 'BWP')} held. ` +
                `You don't need to hook it again — it's already contributing. Unhook it first if you want to change the authorized amount.`,
                'info'
            );
            return;
        }
    }

    const sources = hookRows.map(r => ({
        institution: r.institution || undefined,
        asset_type: r.assetType,
        identifier: r.identifier,
        authorized_amount: parseFloat(r.amount),
        pin: r.pin || undefined,
        wallet_pin: r.pin || undefined,
    }));

    const totalAmount = sources.reduce((sum, s) => sum + s.authorized_amount, 0);
    const currency = sources.length ? (PARTICIPANTS[sources[0].institution]?.limits?.currency || 'BWP') : 'BWP';

    const bodyHtml = `
        <div class="review-hero">
            <div class="review-hero-label">You're about to hook</div>
            <div class="review-hero-amount">${formatMoney(totalAmount, currency)}</div>
            <div class="review-hero-note">${sources.length} source${sources.length > 1 ? 's' : ''} will be hooked for 24 hours</div>
        </div>
        <div class="preview-box">
            ${sources.map(s => `
                <div class="preview-row">
                    <span>${escapeHtml(PARTICIPANTS[s.institution]?.name || s.institution)}</span>
                    <span class="value">${formatMoney(s.authorized_amount, currency)}</span>
                </div>
            `).join('')}
            <div class="preview-row" style="border-bottom:none;font-weight:700;">
                <span>Total held</span>
                <span class="value highlight">${formatMoney(totalAmount, currency)}</span>
            </div>
        </div>
        <div class="preview-reassure">These sources will be held for up to 24 hours. Nothing moves until you spend them.</div>`;

    pendingExecution = {
        type: 'hook',
        payload: { sources, cardSuffix: hookEntry.targetCardSuffix },
        callback: () => executeHook()
    };
    showPreviewModal('Preview hook', bodyHtml, null, 'Confirm hook');
}

function wizardBackToSourceGrid() {
    wizardState.source = null;
    document.getElementById('sourceGrid')?.classList.remove('hidden');
    document.querySelectorAll('.source-option').forEach(el => el.classList.remove('active'));
    const panel = document.getElementById('sourceDetailPanel');
    panel.style.display = 'none';
    panel.innerHTML = '';
    document.getElementById('wizardSourceNext').disabled = true;
    document.querySelector('.swap-wizard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function sourceTypeBackLinkHtml() {
    return `<button type="button" class="source-type-back-link" onclick="wizardBackToSourceGrid()">&larr; Change source type</button>`;
}

// ---- SINGLE-SOURCE PICKER ----
function renderWizardSourcePicker(panel, type) {
    if (type === 'WALLET') {
        const eligible = userSources.filter(s => s.status === 'active' && assetTypeMatchesTile(s.asset_type, type));
        let html = sourceTypeBackLinkHtml();
        
        if (eligible.length > 0) {
            html += `<div class="dropdown-select" id="wizardSavedDropdown">
                <div class="dropdown-select-trigger" onclick="document.getElementById('wizardSavedDropdown').classList.toggle('open')">
                    <span class="placeholder" id="wizardWalletChosenLabel">Select a saved source &rsaquo;</span>
                    <span class="dropdown-select-chevron">&#9662;</span>
                </div>
                <div class="dropdown-select-panel">
                    ${eligible.map(s => `<div class="saved-source-row" onclick="wizardSelectSavedSource('${s.id}')">
                        <div class="row-main"><div class="row-inst">${escapeHtml(PARTICIPANTS[s.institution]?.name || s.institution)}</div>
                        <div class="row-ident">${assetIcon(s.asset_type)} ${escapeHtml(ASSETS[s.asset_type]?.label || s.asset_type)} · ${escapeHtml(s.identifier || s.source_identifier || '')}</div></div>
                    </div>`).join('')}
                </div>
            </div>
            <div style="text-align:center;margin-top:12px;">— or enter a new source —</div>`;
        } else {
            html += `<div class="empty-source-box" style="margin-bottom:12px;">
                <p style="margin-bottom:8px;">You don't have a wallet or account linked yet.</p>
                <span class="quick-link" onclick="goView('toolbox')">+ Add one from Toolbox</span>
            </div>`;
        }
        
        const supportedInstitutions = Object.keys(PARTICIPANTS).filter(code => {
            const types = PARTICIPANTS[code].asset_types || [];
            return types.some(t => {
                const upper = String(t).toUpperCase();
                const normalized = normalizeAssetType(t);
                return ['ACCOUNT', 'WALLET', 'MNO-WALLET', 'BANK-WALLET', 'MOBILE_WALLET']
                    .some(walletType => normalized === walletType || upper.includes(walletType) || upper === walletType);
            });
        });
        
        html += `
            <div class="field-group" style="margin-top:12px;">
                <label>${eligible.length > 0 ? 'Or select an institution' : 'Select institution'}</label>
                <select id="wizardFromInstSelect" onchange="wizardSelectFromInst(this.value)">
                    <option value="">Select institution</option>
                    ${supportedInstitutions.map(code => 
                        `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`
                    ).join('')}
                </select>
            </div>
            <div id="wizardFromFieldsBox"></div>
            <div class="help" id="wizardFromLimitsHelp"></div>`;
        
        panel.innerHTML = html;
        document.getElementById('wizardSourceNext').disabled = true;
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }

    if (type === 'VOUCHER') {
        let html = sourceTypeBackLinkHtml();
        
        const voucherInstitutions = Object.keys(PARTICIPANTS).filter(code => {
            const types = PARTICIPANTS[code].asset_types || [];
            return types.some(t => {
                const upper = String(t).toUpperCase();
                const normalized = normalizeAssetType(t);
                return normalized === 'VOUCHER' || 
                       upper === 'VOUCHER' || 
                       upper === 'CASHOUT-VOUCHER' || 
                       upper.includes('VOUCHER');
            });
        });
        
        html += `
            <div style="text-align:center;font-size:12px;color:var(--text-dim);margin-bottom:14px;">Enter your voucher details below.</div>
            <div class="field-group">
                <label>Institution</label>
                <select id="wizardFromInstSelect" onchange="wizardSelectFromInst(this.value)">
                    <option value="">Select institution</option>
                    ${voucherInstitutions.map(code => 
                        `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`
                    ).join('')}
                </select>
            </div>
            <div id="wizardFromFieldsBox"></div>
            <div class="help" id="wizardFromLimitsHelp"></div>
            <div style="margin-top:12px;font-size:11px;color:var(--text-dim);">
                <span class="quick-link muted" onclick="openHookBuilder('swapwizard', {instName: 'Voucher', institution: document.getElementById('wizardFromInstSelect')?.value, assetType: 'VOUCHER', identifier: document.getElementById('fromField_voucher_number')?.value})">Hook this voucher to My Card after entering it →</span>
            </div>`;
        panel.innerHTML = html;
        document.getElementById('wizardSourceNext').disabled = true;
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }

    if (type === 'CARD') {
        const eligible = userSources.filter(s => s.status === 'active' && assetTypeMatchesTile(s.asset_type, type));
        let html = sourceTypeBackLinkHtml();

        if (eligible.length > 0) {
            html += `
            <div class="field-group">
                <label>Use a saved card</label>
                <div class="dropdown-select" id="wizardSavedDropdown">
                    <div class="dropdown-select-trigger" onclick="document.getElementById('wizardSavedDropdown').classList.toggle('open')">
                        <span class="placeholder">Select a saved source &rsaquo;</span>
                        <span class="dropdown-select-chevron">&#9662;</span>
                    </div>
                    <div class="dropdown-select-panel">
                        ${eligible.map(s => `<div class="saved-source-row" onclick="wizardSelectSavedSource('${s.id}')">
                            <div class="row-main"><div class="row-inst">${escapeHtml(PARTICIPANTS[s.institution]?.name || s.institution)}</div>
                            <div class="row-ident">${assetIcon(s.asset_type)} ${escapeHtml(ASSETS[s.asset_type]?.label || s.asset_type)} · ${escapeHtml(s.identifier || s.source_identifier || '')}</div></div>
                        </div>`).join('')}
                    </div>
                </div>
            </div>
            <div style="text-align:center;font-size:11px;color:var(--text-dim);margin:10px 0 16px;">— or enter a new card —</div>`;
        } else {
            html += `<div style="text-align:center;font-size:12px;color:var(--text-dim);margin-bottom:14px;">Enter your card details below.</div>`;
        }

        const cardMatches = institutionsForTile('CARD');
        if (cardMatches.length === 0) {
            html += `<div class="help" style="color:var(--danger);">Card swaps aren't configured for this country yet.</div>`;
            panel.innerHTML = html;
            document.getElementById('wizardSourceNext').disabled = true;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        
        if (cardMatches.length === 1) {
            html += `<div id="wizardFromFieldsBox"></div><div class="help" id="wizardFromLimitsHelp"></div>`;
            panel.innerHTML = html;
            const { institution, matchedAssetType } = cardMatches[0];
            wizardState.fromAsset = matchedAssetType;
            wizardSelectFromInst(institution);
            const limitsHelp = panel.querySelector('#wizardFromLimitsHelp');
            if (limitsHelp) limitsHelp.textContent = (limitsHelp.textContent ? limitsHelp.textContent + ' — ' : '') + `Processed via ${PARTICIPANTS[institution]?.name || institution}`;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        
        html += `
            <div class="field-group">
                <label>Card network / processor</label>
                <select id="wizardFromInstSelect" onchange="wizardSelectFromInst(this.value)">
                    <option value="">Select</option>
                    ${cardMatches.map(m => `<option value="${m.institution}" data-asset-type="${m.matchedAssetType}">${PARTICIPANTS[m.institution]?.name || m.institution}</option>`).join('')}
                </select>
            </div>
            <div id="wizardFromFieldsBox"></div>
            <div class="help" id="wizardFromLimitsHelp"></div>`;
        panel.innerHTML = html;
        const sel = panel.querySelector('#wizardFromInstSelect');
        if (sel) {
            sel.onchange = function () {
                const opt = this.options[this.selectedIndex];
                wizardState.fromAsset = opt?.dataset.assetType || 'CARD';
                wizardSelectFromInst(this.value);
            };
        }
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }
    
    panel.innerHTML = `<div class="help" style="color:var(--danger);">Unsupported source type: ${type}</div>`;
    document.getElementById('wizardSourceNext').disabled = true;
}
    
// ---- FIXED: Preserve asset type from saved source ----
function wizardSelectSavedSource(sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    if (!source) return;
    
    const actualAssetType = source.asset_type || source.source_asset_type || 'ACCOUNT';
    
    const sel = document.getElementById('wizardFromInstSelect');
    if (sel) sel.value = source.institution;
    
    wizardSelectFromInstWithAsset(source.institution, source, actualAssetType);
    
    document.getElementById('wizardSavedDropdown')?.classList.remove('open');
    showMessage(`${PARTICIPANTS[source.institution]?.name || source.institution} selected.`, 'success');
}

function wizardSelectFromInstWithAsset(code, prefillSource, assetType) {
    wizardState.fromInst = code || null;
    wizardState.fromAsset = assetType || wizardState.fromAsset;
    wizardState.fromFields = {};
    
    const panel = document.getElementById('sourceDetailPanel');
    if (!panel) return;
    const fieldsBox = panel.querySelector('#wizardFromFieldsBox');
    const limitsHelp = panel.querySelector('#wizardFromLimitsHelp');

    if (!code) {
        if (fieldsBox) fieldsBox.innerHTML = '';
        if (limitsHelp) limitsHelp.textContent = '';
        document.getElementById('wizardSourceNext').disabled = true;
        return;
    }

    const inst = PARTICIPANTS[code];
    if (limitsHelp) {
        limitsHelp.textContent = inst?.limits ? `Limits: ${inst.limits.min_amount} – ${inst.limits.max_amount} ${inst.limits.currency}` : '';
    }

    const supportedAssetTypes = inst?.asset_types || [];
    const normalizedAssetType = normalizeAssetType(assetType);
    const isSupported = supportedAssetTypes.some(t => {
        const normalized = normalizeAssetType(t);
        return normalized === normalizedAssetType || 
               normalized.includes(normalizedAssetType) ||
               normalizedAssetType.includes(normalized);
    });

    if (!isSupported) {
        if (fieldsBox) {
            fieldsBox.innerHTML = `<div class="help" style="color:var(--danger);">This institution does not support ${assetType} as a source.</div>`;
        }
        document.getElementById('wizardSourceNext').disabled = true;
        return;
    }

    if (fieldsBox) {
        renderWizardFields(fieldsBox, wizardState.fromAsset, 'fromField_', (name, value) => {
            wizardState.fromFields[name] = value;
            const valid = fieldsValidForAsset(wizardState.fromAsset, wizardState.fromFields, true);
            document.getElementById('wizardSourceNext').disabled = !valid.valid;
        }, true);

        if (prefillSource) {
            const config = getAssetConfig(wizardState.fromAsset);
            const idField = (config?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
            if (idField) {
                const identifier = prefillSource.identifier || prefillSource.source_identifier || '';
                const input = document.getElementById('fromField_' + idField.name);
                if (input) { 
                    input.value = identifier; 
                    input.disabled = true; 
                    input.style.background = 'var(--surface-muted)'; 
                    input.style.color = 'var(--text-dim)'; 
                }
                wizardState.fromFields[idField.name] = identifier;
            }
        }
    }

    const valid = fieldsValidForAsset(wizardState.fromAsset, wizardState.fromFields, true);
    document.getElementById('wizardSourceNext').disabled = !valid.valid;
}

function wizardSelectFromInst(code, prefillSource) {
    if (prefillSource && prefillSource.asset_type) {
        wizardSelectFromInstWithAsset(code, prefillSource, prefillSource.asset_type);
        return;
    }
    
    wizardState.fromInst = code || null;
    wizardState.fromFields = {};
    const panel = document.getElementById('sourceDetailPanel');
    if (!panel) return;
    const fieldsBox = panel.querySelector('#wizardFromFieldsBox');
    const limitsHelp = panel.querySelector('#wizardFromLimitsHelp');

    if (!code) {
        if (fieldsBox) fieldsBox.innerHTML = '';
        if (limitsHelp) limitsHelp.textContent = '';
        document.getElementById('wizardSourceNext').disabled = true;
        return;
    }

    const inst = PARTICIPANTS[code];
    if (limitsHelp) {
        limitsHelp.textContent = inst?.limits ? `Limits: ${inst.limits.min_amount} – ${inst.limits.max_amount} ${inst.limits.currency}` : '';
    }

    if (fieldsBox) {
        const supportedAssetTypes = inst?.asset_types || [];
        
        if (wizardState.fromAsset) {
            const normalizedFromAsset = normalizeAssetType(wizardState.fromAsset);
            const isSupported = supportedAssetTypes.some(t => {
                const normalized = normalizeAssetType(t);
                return normalized === normalizedFromAsset || 
                       normalized.includes(normalizedFromAsset) ||
                       normalizedFromAsset.includes(normalized);
            });
            
            if (!isSupported) {
                fieldsBox.innerHTML = `<div class="help" style="color:var(--danger);">This institution does not support ${wizardState.fromAsset} as a source.</div>`;
                document.getElementById('wizardSourceNext').disabled = true;
                return;
            }
        }
        
        renderWizardFields(fieldsBox, wizardState.fromAsset, 'fromField_', (name, value) => {
            wizardState.fromFields[name] = value;
            const valid = fieldsValidForAsset(wizardState.fromAsset, wizardState.fromFields, true);
            document.getElementById('wizardSourceNext').disabled = !valid.valid;
        }, true);
    }

    const valid = fieldsValidForAsset(wizardState.fromAsset, wizardState.fromFields, true);
    document.getElementById('wizardSourceNext').disabled = !valid.valid;
}

// ---- TILE MATCHING ----
const TILE_ASSET_ALIASES = {
    WALLET: ['WALLET', 'ACCOUNT', 'MNO-WALLET', 'BANK-WALLET', 'MOBILE_WALLET'],
    CARD: ['CARD', 'VISA_MASTERCARD_CARD'],
    VOUCHER: ['VOUCHER'],
};

function assetTypeMatchesTile(participantAssetType, tileType) {
    const norm = s => String(s || '').toUpperCase().replace(/[-_\s]/g, '');
    const p = norm(participantAssetType);
    const aliases = (TILE_ASSET_ALIASES[tileType] || [tileType]).map(norm);
    if (!p) return false;
    if (aliases.includes(p)) return true;
    return aliases.some(a => p.includes(a) || a.includes(p));
}

function institutionsForTile(type) {
    const results = [];
    const normalizedType = normalizeAssetType(type);
    const aliases = (TILE_ASSET_ALIASES[type] || [type]).map(a => String(a).toUpperCase().replace(/[-_\s]/g, ''));
    
    Object.keys(PARTICIPANTS).forEach(code => {
        const assetTypes = PARTICIPANTS[code].asset_types || [];
        const match = assetTypes.find(t => {
            const normalized = String(t).toUpperCase().replace(/[-_\s]/g, '');
            const normalizedAsset = normalizeAssetType(t);
            return normalizedAsset === normalizedType || 
                   aliases.includes(normalized) || 
                   aliases.some(a => normalized.includes(a) || a.includes(normalized));
        });
        if (match) {
            results.push({ 
                institution: code, 
                matchedAssetType: match,
                matchedNormalized: normalizeAssetType(match)
            });
        }
    });
    return results;
}

// ---- STEP 3 — DESTINATION SELECTION ----
function selectDestination(type) {
    wizardState.destType = type;
    document.querySelectorAll('.dest-option').forEach(el => {
        el.classList.toggle('active', el.dataset.dest === type);
    });

    const panel = document.getElementById('destDetailPanel');
    const nextBtn = document.getElementById('wizardDestNext');

    if (type === 'IDENTITY') {
        panel.style.display = 'block';
        panel.innerHTML = document.getElementById('identityFields').cloneNode(true).innerHTML;
        const typeSel = panel.querySelector('#identityType');
        if (typeSel) typeSel.onchange = function() { wizardState.identityType = this.value; };
        const valInput = panel.querySelector('#identityValue');
        if (valInput) valInput.oninput = function() { wizardState.identityValue = this.value.trim(); };
        const smsInput = panel.querySelector('#identitySms');
        if (smsInput) smsInput.oninput = function() { wizardState.identitySms = this.value; };
        nextBtn.disabled = !wizardState.identityValue;
        return;
    }

    if (type === 'DEPOSIT') {
    const eligible = userSources.filter(u => u.status === 'active');
    if (eligible.length > 0) {
        const savedPickerHtml = `
            <div class="field-group">
                <label>Use one of your saved accounts</label>
                <select id="destSavedSourceSelect" onchange="applySavedSourceAsDestination(this.value)">
                    <option value="">— or select an institution below —</option>
                    ${eligible.map(u => `<option value="${u.id}">${PARTICIPANTS[u.institution]?.name || u.institution} — ${u.identifier || u.source_identifier || ''}</option>`).join('')}
                </select>
            </div>`;
        panel.insertAdjacentHTML('afterbegin', savedPickerHtml);
    }
}
    
    panel.style.display = 'block';
    const toSection = document.getElementById('toSection');
    if (toSection) {
        // Clone the toSection content
        panel.innerHTML = toSection.cloneNode(true).innerHTML;
        
        const cashoutFields = panel.querySelector('#cashoutFields');
        if (cashoutFields) cashoutFields.style.display = type === 'CASHOUT' ? 'block' : 'none';

        const instSel = panel.querySelector('#toInstSelect');
        if (instSel) {
            instSel.onchange = function() { wizardSelectToInst(this.value); };
            const instOptions = Object.keys(PARTICIPANTS);
            instSel.innerHTML = '<option value="">Select institution</option>' +
                instOptions.map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('');
        }
        const assetSel = panel.querySelector('#toAssetSelect');
        if (assetSel) assetSel.onchange = function() { wizardSelectToAsset(this.value); };

        const delSel = panel.querySelector('#deliveryMethodSelect');
        if (delSel) delSel.onchange = function() { wizardState.deliveryMethod = this.value; };

        const phoneInput = panel.querySelector('#beneficiaryPhone');
        if (phoneInput) phoneInput.oninput = function() {
            wizardState.beneficiaryPhone = this.value;
            if (wizardState.destType === 'CASHOUT') {
                document.getElementById('wizardDestNext').disabled =
                    !(wizardState.toInst && wizardState.beneficiaryPhone);
            }
        };
        
        wizardState.toInst = null;
        wizardState.toAsset = null;
        wizardState.toFields = {};
        
        const assetSection = panel.querySelector('#toAssetSection');
        if (assetSection) assetSection.style.display = 'none';
        const fieldsBox = panel.querySelector('#toFields');
        if (fieldsBox) { fieldsBox.innerHTML = ''; fieldsBox.style.display = 'none'; }
        nextBtn.disabled = true;
    }
}

function applySavedSourceAsDestination(sourceId) {
    const source = userSources.find(u => u.id === sourceId);
    if (!source) return;
    const panel = document.getElementById('destDetailPanel');
    const instSel = panel.querySelector('#toInstSelect');
    if (instSel) { instSel.value = source.institution; wizardSelectToInst(source.institution); }

    setTimeout(() => {
        const targetAsset = normalizeAssetType(source.asset_type);
        const assetSel = panel.querySelector('#toAssetSelect');
        if (assetSel && assetSel.options.length) {
            const match = Array.from(assetSel.options).find(o => normalizeAssetType(o.value) === targetAsset);
            if (match) { assetSel.value = match.value; wizardSelectToAsset(match.value); }
        }
        setTimeout(() => {
            const cfg = getAssetConfig(wizardState.toAsset);
            const idField = (cfg?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
            if (idField) {
                const identifier = source.identifier || source.source_identifier || '';
                const input = document.getElementById('toField_' + idField.name);
                if (input) input.value = identifier;
                wizardState.toFields[idField.name] = identifier;
            }
            const valid = fieldsValidForAsset(wizardState.toAsset, wizardState.toFields, false);
            const nextBtn = document.getElementById('wizardDestNext');
            if (nextBtn) nextBtn.disabled = !valid.valid;
        }, 30);
    }, 30);
}
    
function wizardSelectToInst(code) {
    wizardState.toInst = code || null;
    wizardState.toAsset = null;
    wizardState.toFields = {};
    const panel = document.getElementById('destDetailPanel');
    if (!panel) return;

    // ============================================================
    // SAME INSTITUTION WARNING (non-blocking)
    // ============================================================
    if (code && wizardState.fromInst === code && ['WALLET', 'CARD', 'VOUCHER'].includes(wizardState.source)) {
        const warningEl = document.createElement('div');
        warningEl.id = 'sameInstitutionWarning';
        warningEl.style.cssText = 'background: rgba(0,168,120,0.08); border-left: 3px solid var(--accent); padding: 12px 16px; margin-bottom: 12px; font-size: 12px; color: var(--text-muted);';
        warningEl.innerHTML = '💡 You\'re sending money <strong>within the same institution</strong>. Make sure the destination account/wallet/card number is <strong>different</strong> from your source.';
        
        const existingWarning = panel.querySelector('#sameInstitutionWarning');
        if (existingWarning) existingWarning.remove();
        
        const firstChild = panel.firstChild;
        if (firstChild) {
            panel.insertBefore(warningEl, firstChild);
        } else {
            panel.appendChild(warningEl);
        }
    } else {
        const existingWarning = panel.querySelector('#sameInstitutionWarning');
        if (existingWarning) existingWarning.remove();
    }

    if (wizardState.destType === 'CASHOUT') {
        document.getElementById('wizardDestNext').disabled =
            !(wizardState.toInst && wizardState.beneficiaryPhone);
        return;
    }

    const assetSection = panel.querySelector('#toAssetSection');
    const assetSel = panel.querySelector('#toAssetSelect');
    const fieldsBox = panel.querySelector('#toFields');

    if (!code) {
        if (assetSection) assetSection.style.display = 'none';
        if (fieldsBox) { fieldsBox.innerHTML = ''; fieldsBox.style.display = 'none'; }
        document.getElementById('wizardDestNext').disabled = true;
        return;
    }

    const inst = PARTICIPANTS[code];
    if (!inst) return;
    
    let assetTypes = inst.asset_types || [];
    
    if (wizardState.destType === 'DEPOSIT') {
        assetTypes = assetTypes.filter(t => {
            const upper = String(t).toUpperCase();
            return upper !== 'VOUCHER' && 
                   upper !== 'CASHOUT-VOUCHER' && 
                   upper !== 'GIFT-VOUCHER';
        });
    }
    
    if (wizardState.destType === 'DEPOSIT') {
        assetTypes = assetTypes.filter(t => {
            const upper = String(t).toUpperCase();
            return ['ACCOUNT', 'WALLET', 'CARD', 'MNO-WALLET', 'BANK-WALLET'].some(allowed => 
                upper === allowed || upper.includes(allowed)
            );
        });
    }
    
    if (assetSel) {
        if (assetTypes.length === 0) {
            assetSel.innerHTML = `<option value="">No ${wizardState.destType === 'DEPOSIT' ? 'deposit' : ''} asset types supported</option>`;
            assetSection.style.display = 'block';
            document.getElementById('wizardDestNext').disabled = true;
            return;
        }
        
        assetSel.innerHTML = '<option value="">Select asset type</option>' +
            assetTypes.map(t => {
                const config = getAssetConfig(t);
                const label = config?.label || t;
                const icon = assetIcon(t);
                return `<option value="${t}">${icon} ${label}</option>`;
            }).join('');
    }
    if (assetSection) assetSection.style.display = 'block';

    if (assetTypes.length === 1 && assetSel) {
        assetSel.value = assetTypes[0];
        wizardSelectToAsset(assetTypes[0]);
    } else {
        if (fieldsBox) { fieldsBox.innerHTML = ''; fieldsBox.style.display = 'none'; }
        document.getElementById('wizardDestNext').disabled = true;
    }
}

function wizardSelectToAsset(type) {
    wizardState.toAsset = type || null;
    wizardState.toFields = {};
    const panel = document.getElementById('destDetailPanel');
    if (!panel) return;
    const fieldsBox = panel.querySelector('#toFields');

    if (!type) {
        if (fieldsBox) { fieldsBox.innerHTML = ''; fieldsBox.style.display = 'none'; }
        document.getElementById('wizardDestNext').disabled = true;
        return;
    }

    if (fieldsBox) {
        fieldsBox.style.display = 'block';
        renderWizardFields(fieldsBox, type, 'toField_', (name, value) => {
            wizardState.toFields[name] = value;
            const valid = fieldsValidForAsset(wizardState.toAsset, wizardState.toFields, false);
            const nextBtn = document.getElementById('wizardDestNext');
            if (nextBtn) nextBtn.disabled = !valid.valid;
        }, false);
    }

    const valid = fieldsValidForAsset(wizardState.toAsset, wizardState.toFields, false);
    const nextBtn = document.getElementById('wizardDestNext');
    if (nextBtn) nextBtn.disabled = !valid.valid;
}

window._wizardFieldChange = function(name, value, prefix) {
    if (prefix === 'fromField_') {
        wizardState.fromFields[name] = value;
        const valid = fieldsValidForAsset(wizardState.fromAsset, wizardState.fromFields, true);
        document.getElementById('wizardSourceNext').disabled = !valid.valid;
        return;
    }

    if (prefix === 'toField_') {
        wizardState.toFields[name] = value;
        const valid = fieldsValidForAsset(wizardState.toAsset, wizardState.toFields, false);
        const nextBtn = document.getElementById('wizardDestNext');
        if (nextBtn) nextBtn.disabled = !valid.valid;
        return;
    }

    const activeSourcePanel = document.getElementById('sourceDetailPanel');
    const activeDestPanel = document.getElementById('destDetailPanel');
    const isSourceVisible = activeSourcePanel && activeSourcePanel.style.display !== 'none';
    const isDestVisible = activeDestPanel && activeDestPanel.style.display !== 'none';

    if (isSourceVisible) {
        wizardState.fromFields[name] = value;
        const valid = fieldsValidForAsset(wizardState.fromAsset, wizardState.fromFields, true);
        document.getElementById('wizardSourceNext').disabled = !valid.valid;
        return;
    }

    if (isDestVisible) {
        wizardState.toFields[name] = value;
        const valid = fieldsValidForAsset(wizardState.toAsset, wizardState.toFields, false);
        const nextBtn = document.getElementById('wizardDestNext');
        if (nextBtn) nextBtn.disabled = !valid.valid;
        return;
    }
};
    
function updateWizardCurrency() {}

// ---- SHARED FIELD RENDERER ----
function renderWizardFields(container, assetType, prefix, onChange, includePin) {
    const config = getAssetConfig(assetType);
    if (!config) {
        container.innerHTML = `<div class="help" style="color:var(--danger);">This account type isn't supported yet.</div>`;
        return;
    }
    let fields = config.fields || [];
    if (!includePin) fields = fields.filter(f => f.vault_field !== 'pin');
    fields = fields.filter(f => f.name !== 'amount');
    if (!fields.length) { container.innerHTML = ''; return; }

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
            return `<div class="field-group"><label>${f.label} ${f.required ? '*' : ''}</label>
                <select id="${prefix}${f.name}" onchange="window._wizardFieldChange('${f.name}', this.value)">
                    <option value="">${f.placeholder || 'Select'}</option>${optionsHtml}
                </select>${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}</div>`;
        }
        const inputType = f.vault_field === 'pin' || f.name.includes('pin') || f.name === 'cvv' ? 'password' : (f.type || 'text');
        const eventAttr = f.type === 'select' ? 'onchange' : 'oninput';
        return `<div class="field-group"><label>${f.label} ${f.required ? '*' : ''}</label>
            <input type="${inputType}" id="${prefix}${f.name}" placeholder="${f.placeholder || ''}" 
                   ${attrs.join(' ')} ${eventAttr}="window._wizardFieldChange('${f.name}', this.value, '${prefix}')">
            ${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}</div>`;
    }).join('');
}

function fieldsValidForAsset(assetType, values, includePin) {
    const config = getAssetConfig(assetType);
    if (!config) return { valid: false, friendlyMessage: "That account type isn't supported yet." };
    
    let fields = config.fields || [];
    fields = fields.filter(f => f.name !== 'amount');
    if (!includePin) fields = fields.filter(f => f.vault_field !== 'pin');
    
    const requiredFields = fields.filter(f => f.required === true);
    let failedField = null;
    const result = requiredFields.every(f => {
        const val = values[f.name];
        if (val === undefined || val === null || String(val).trim().length === 0) {
            failedField = f.name;
            return false;
        }
        return true;
    });
    
    if (!result) {
        const field = requiredFields.find(f => f.name === failedField);
        return { valid: false, friendlyMessage: `${field?.label || failedField} is required.` };
    }
    
    return { valid: true };
}

function extractPinFromFields(assetType, values) {
    const pinField = (getAssetConfig(assetType)?.fields || []).find(f => f.vault_field === 'pin');
    return pinField ? (values[pinField.name] || '') : '';
}

function assetHasAmountField(assetType) {
    return (getAssetConfig(assetType)?.fields || []).some(f => f.name === 'amount');
}

// ---- STEP 4 — REVIEW ----
function enterReviewStep() {
    let displayAmount = wizardState.amount;
    if (wizardState.source === 'VMCARD' || wizardState.source === 'COMBINE') {
        displayAmount = wizardState.tabTotalAmount || wizardState.amount;
    }
    document.getElementById('reviewAmount').textContent = formatMoney(displayAmount, wizardState.currency);

    const sourceLabels = {
        'WALLET': 'Wallet / Account', 'CARD': 'Card', 'VOUCHER': 'Voucher',
        'VMCARD': 'My Card (hooked sources)', 'COMBINE': 'Combined sources'
    };
    document.getElementById('reviewSource').textContent = sourceLabels[wizardState.source] || wizardState.source;

    const destLabels = {
        'DEPOSIT': 'Deposit', 'CASHOUT': 'Cashout',
        'IDENTITY': `Identity: ${wizardState.identityValue || '—'}`
    };
    document.getElementById('reviewDest').textContent = destLabels[wizardState.destType] || wizardState.destType;

    const resultBox = document.getElementById('reviewPreviewResult');
    if (resultBox) { resultBox.style.display = 'none'; resultBox.innerHTML = ''; }
    wizardState.lastPreview = null;
    wizardState.swapPayload = null;

    const previewRow = document.getElementById('wizardPreviewRow');
    const confirmRow = document.getElementById('wizardConfirmRow');

    if (wizardState.source === 'VMCARD') {
        if (previewRow) previewRow.style.display = 'none';
        if (confirmRow) {
            confirmRow.style.display = 'flex';
            document.getElementById('wizardConfirmBtn').textContent = 'Start swap';
        }
        return;
    }

    if (previewRow) previewRow.style.display = 'flex';
    if (confirmRow) confirmRow.style.display = 'none';
    if (document.getElementById('wizardConfirmBtn')) document.getElementById('wizardConfirmBtn').textContent = 'Confirm & swap';
}

async function wizardPreview() {
    const built = buildWizardPayload();
    wizardState.swapPayload = built;

    const btn = document.getElementById('wizardPreviewBtn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Getting quote…';

    try {
        const result = await callApi(CONFIG.PREVIEW_ENDPOINT, built.payload);
        btn.disabled = false;
        btn.innerHTML = original;

        if (!result.ok) {
            // Show the actual error from the server
            const errorMsg = result.body?.error || result.error || 'Preview failed';
            showMessage('Preview failed: ' + friendlyApiError(errorMsg), 'error');
            console.error('[PREVIEW] Error:', result);
            return;
        }

        wizardState.lastPreview = result.body;
        renderReviewPreviewResult(result.body);
        document.getElementById('wizardConfirmRow').style.display = 'flex';
        
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = original;
        showMessage('Network error: ' + e.message, 'error');
        console.error('[PREVIEW] Exception:', e);
    }
}

function renderReviewPreviewResult(previewData) {
    const data = previewData.preview || {};
    const netAmount = data.net_amount_destination_currency || data.net_amount;
    const destCurrency = data.destination_currency || data.source_currency || wizardState.currency;
    const box = document.getElementById('reviewPreviewResult');
    if (!box) return;
    box.style.display = 'block';
    box.innerHTML = `
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
            <span style="font-size:12px;color:var(--text-dim);">Fee</span>
            <span style="font-weight:700;font-family:var(--font-mono);">${formatMoney(data.total_fee, data.source_currency)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:8px 0;">
            <span style="font-size:12px;color:var(--text-dim);">Destination gets</span>
            <span style="font-weight:700;font-family:var(--font-mono);color:var(--accent);">${formatMoney(netAmount, destCurrency)}</span>
        </div>
        <div style="text-align:center;font-size:11px;color:var(--text-dim);margin-top:6px;">Quote locked in for a few minutes — tap Confirm below to swap.</div>`;
}

// ---- UNIFIED PAYLOAD BUILDER ----
function buildWizardPayload() {
    const reference = 'SWAP_' + Date.now();
    const idempotencyKey = 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

    const getCurrency = (instCode) => {
        if (!instCode) return wizardState.currency;
        return PARTICIPANTS[instCode]?.limits?.currency || wizardState.currency;
    };

    if (wizardState.source === 'COMBINE') {
        const rows = wizardState.multiSources.filter(r => !r._draft && combineRowIsComplete(r));
        const sources = [];

        rows.forEach(r => {
            if (r.type === 'saved' || r.type === 'voucher') {
                const pin = extractPinFromFields(r.assetType, r.fields);
                const config = getAssetConfig(r.assetType);
                const identifierField = (config?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
                sources.push({
                    institution: r.institution,
                    asset_type: normalizeAssetType(r.assetType),
                    identifier: identifierField ? r.fields[identifierField.name] : null,
                    amount: r.amount,
                    wallet_pin: pin || undefined,
                    pin: pin || undefined,
                    asset_fields: { ...r.fields },
                    source_identifier_type: identifierField?.name === 'phone' ? 'phone' : 'account_number',
                });
            }
        });

        const totalAmount = wizardState.tabTotalAmount || sources.reduce((sum, s) => sum + s.amount, 0);
        const sourceCurrency = sources.length ? getCurrency(sources[0].institution) : wizardState.currency;
        
        const payload = {
            swap_type: 'MULTI_SOURCE',
            reference,
            idempotency_key: idempotencyKey,
            user_id: CONFIG.USER_ID,
            amount: totalAmount,
            currency: sourceCurrency,
            contribution_strategy: wizardState.contributionStrategy || 'SMART',
            sources: sources,
            source_currency: sourceCurrency,
        };

        return buildDestinationPayload(payload, sourceCurrency);
    }

    // SINGLE-SOURCE
    const pin = extractPinFromFields(wizardState.fromAsset, wizardState.fromFields);
    const sourceAssetFields = { ...wizardState.fromFields };
    if (assetHasAmountField(wizardState.fromAsset)) sourceAssetFields.amount = wizardState.amount;
    const sourceCurrency = getCurrency(wizardState.fromInst);
    
    const sourceConfig = getAssetConfig(wizardState.fromAsset);
    const sourceIdField = (sourceConfig?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    const sourceIdentifier = sourceIdField ? wizardState.fromFields[sourceIdField.name] : null;
    const sourceIdentifierType = sourceIdField ? 
        (sourceIdField.name === 'phone' || sourceIdField.name === 'phone_number' ? 'phone' : 'account_number') 
        : 'auto';

    const payload = {
        swap_type: wizardState.destType || 'DEPOSIT',
        reference,
        idempotency_key: idempotencyKey,
        user_id: CONFIG.USER_ID,
        from_institution: wizardState.fromInst,
        source_institution: wizardState.fromInst,
        asset_type: normalizeAssetType(wizardState.fromAsset),
        amount: wizardState.amount,
        currency: sourceCurrency,
        wallet_pin: pin || undefined,
        pin: pin || undefined,
        asset_fields: sourceAssetFields,
        ...sourceAssetFields,
        source_identifier: sourceIdentifier,
        source_identifier_type: sourceIdentifierType,
        source_currency: sourceCurrency,
    };

    return buildDestinationPayload(payload, sourceCurrency);
}

function buildDestinationPayload(payload, sourceCurrency) {
    const destType = wizardState.destType || 'DEPOSIT';

    if (destType === 'IDENTITY') {
        payload.identity_type = wizardState.identityType;
        payload.identity_value = wizardState.identityValue;
        if (wizardState.identitySms) payload.notification_phone = wizardState.identitySms;
        payload.destination_currency = sourceCurrency;
        return { type: 'SWAP', payload };
    }

    if (destType === 'CASHOUT') {
        payload.to_institution = wizardState.toInst;
        payload.destination_institution = wizardState.toInst;
        payload.delivery_method = wizardState.deliveryMethod || 'ATM';
        const destCurrency = PARTICIPANTS[wizardState.toInst]?.limits?.currency || sourceCurrency;
        payload.destination_currency = destCurrency;
        
        const beneficiaryPhone = wizardState.beneficiaryPhone || wizardState.toFields?.phone || null;
        if (beneficiaryPhone) { 
            payload.beneficiary_phone = beneficiaryPhone; 
            payload.client_phone = beneficiaryPhone; 
        }
        
        const destConfig = getAssetConfig(wizardState.toAsset);
        const destIdField = (destConfig?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        
        const normalizedToAsset = normalizeAssetType(wizardState.toAsset);
        if (normalizedToAsset === 'VOUCHER') {
            payload.destination_asset_type = 'VOUCHER';
            payload.destination_asset_fields = { 
                voucher_type: 'CASHOUT', 
                recipient_phone: beneficiaryPhone || wizardState.toFields?.recipient_phone || null 
            };
            payload.destination_identifier = beneficiaryPhone || wizardState.toFields?.recipient_phone || null;
        } else {
            payload.destination_asset_type = 'WALLET';
            const phone = wizardState.toFields?.phone || beneficiaryPhone || null;
            payload.destination_asset_fields = { phone };
            payload.destination_identifier = phone;
        }
        return { type: 'SWAP', payload };
    }

    // DEPOSIT
    payload.to_institution = wizardState.toInst;
    payload.destination_institution = wizardState.toInst;
    payload.destination_asset_type = normalizeAssetType(wizardState.toAsset);
    payload.destination_currency = PARTICIPANTS[wizardState.toInst]?.limits?.currency || sourceCurrency;
    
    const destFields = { ...wizardState.toFields };
    if (assetHasAmountField(wizardState.toAsset)) destFields.amount = wizardState.amount;
    payload.destination_asset_fields = destFields;
    
    for (const [key, value] of Object.entries(destFields)) {
        payload[`destination_${key}`] = value;
    }
    
    const destConfig = getAssetConfig(wizardState.toAsset);
    const destIdField = (destConfig?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    if (destIdField) {
        payload.destination_identifier = wizardState.toFields[destIdField.name];
        payload.destination_identifier_type = destIdField.name === 'phone' ? 'phone' : 'account_number';
    }
    
    return { type: 'SWAP', payload };
}

// ---- WIZARD CONFIRM ----
async function wizardConfirm() {
    if (wizardState.source === 'VMCARD') {
        await executeViaContributionSession();
        return;
    }

    if (!wizardState.swapPayload || !wizardState.lastPreview) {
        showMessage('Tap "Preview swap" first so you can see the fee before confirming.', 'warning');
        return;
    }

    const btn = document.getElementById('wizardConfirmBtn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Swapping…';

    const result = await callApi(CONFIG.EXECUTE_ENDPOINT, wizardState.swapPayload.payload);
    btn.disabled = false;
    btn.innerHTML = original;

    if (!result.ok) {
        showMessage('Swap failed: ' + friendlyApiError(result.error), 'error');
        return;
    }

    const journeyData = Journey.recordSwap(wizardState.swapPayload.payload, wizardState.lastPreview);
    renderRepeatCard(); renderProgressCard();
    showWizardResultModal(result.body, journeyData);
}

async function executeViaContributionSession() {
    const btn = document.getElementById('wizardConfirmBtn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Starting…';

    const destPayload = { currency: wizardState.currency };
    
    if (wizardState.destType === 'IDENTITY') {
        destPayload.identity_type = wizardState.identityType;
        destPayload.identity_value = wizardState.identityValue;
        if (wizardState.identitySms) destPayload.beneficiary_phone = wizardState.identitySms;
    } else {
        // ============================================================
        // FIX: Properly extract destination identifier from fields
        // ============================================================
        let destIdentifier = null;
        
        // Try to get identifier from toFields
        if (wizardState.toFields) {
            // Check common field names
            destIdentifier = wizardState.toFields.account_number 
                || wizardState.toFields.phone 
                || wizardState.toFields.wallet_phone
                || wizardState.toFields.card_number
                || wizardState.toFields.card_token
                || Object.values(wizardState.toFields)[0] 
                || null;
        }
        
        // If still null, try to get from the destination panel
        if (!destIdentifier) {
            const panel = document.getElementById('destDetailPanel');
            if (panel) {
                // Try to find any input field in the panel
                const inputs = panel.querySelectorAll('input[type="text"], input[type="tel"], input[type="number"]');
                for (const input of inputs) {
                    if (input.value && input.value.trim().length > 0) {
                        destIdentifier = input.value.trim();
                        break;
                    }
                }
            }
        }
        
        // If still null, try to get from the original destination identifier
        if (!destIdentifier && wizardState._destIdentifier) {
            destIdentifier = wizardState._destIdentifier;
        }
        
        if (!destIdentifier) {
            btn.disabled = false;
            btn.innerHTML = original;
            showMessage('Please enter a destination account number, phone, or wallet ID.', 'warning');
            return;
        }
        
        const destAssetType = normalizeAssetType(wizardState.toAsset) || 'WALLET';
        const destIdentifierType = destAssetType === 'ACCOUNT' ? 'account' 
            : (destAssetType === 'CARD' ? 'card' : 'phone');
        
        destPayload.to_institution = wizardState.toInst;
        destPayload.destination_institution = wizardState.toInst;
        destPayload.destination_identifier = destIdentifier;
        destPayload.destination_identifier_type = destIdentifierType;
        destPayload.destination_asset_type = destAssetType;
        destPayload.delivery_method = wizardState.destType === 'CASHOUT' ? (wizardState.deliveryMethod || 'ATM') : 'DEPOSIT';
        
        if (wizardState.destType === 'CASHOUT' && wizardState.beneficiaryPhone) {
            destPayload.beneficiary_phone = wizardState.beneficiaryPhone;
        }
    }

    console.log('[DEBUG] toFields:', wizardState.toFields);
console.log('[DEBUG] toAsset:', wizardState.toAsset);
console.log('[DEBUG] toInst:', wizardState.toInst);

    const createResult = await callApi(CONFIG.API_BASE + '/api/v1/cards/Create.php', {
        card_suffix: myCard.card_suffix,
        target_amount: wizardState.amount,
        currency: wizardState.currency,
        strategy: wizardState.vmCardStrategy || 'SMART',
        ...destPayload,
    });

    if (!createResult.ok) {
        btn.disabled = false; btn.innerHTML = original;
        showMessage("Couldn't start that swap: " + friendlyApiError(createResult.error), 'error');
        return;
    }

    const sessionId = createResult.body.data.session_id;

    let status = createResult.body.data;
    for (let i = 0; i < 10 && status.status !== 'READY'; i++) {
        await new Promise(r => setTimeout(r, 400));
        const poll = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/ContributionStatus.php?session_id=' + sessionId);
        if (!poll.ok) break;
        status = poll.body.data;
    }

    if (status.status !== 'READY') {
        btn.disabled = false; btn.innerHTML = original;
        showMessage('Your hooked sources don\'t fully cover this amount yet. Hook more, or lower the amount.', 'warning');
        return;
    }

    const execResult = await callApi(CONFIG.API_BASE + '/api/v1/cards/execute.php', { session_id: sessionId });
    btn.disabled = false; btn.innerHTML = original;

    if (!execResult.ok) {
        showMessage('Swap failed: ' + friendlyApiError(execResult.error), 'error');
        return;
    }

    const journeyData = Journey.recordSwap({ swap_type: wizardState.destType, amount: wizardState.amount, currency: wizardState.currency, to_institution: wizardState.toInst }, { preview: { amount_requested: wizardState.amount, source_currency: wizardState.currency } });
    renderRepeatCard(); renderProgressCard();
    showWizardResultModal({ data: { reference: execResult.body.data.swap_reference, amount: wizardState.amount } }, journeyData);
}
    
function dedupeVmCardSources(sources) {
    const map = new Map();
    sources.forEach(c => {
        const key = `${c.institution}|${c.identifier}|${c.asset_type}`;
        const amt = c.available_balance ?? c.authorized_amount ?? 0;
        if (map.has(key)) map.get(key).amount += amt;
        else map.set(key, { institution: c.institution, identifier: c.identifier, asset_type: c.asset_type, amount: amt });
    });
    return Array.from(map.values());
}

function setWizardVmCardStrategy(s) {
    wizardState.vmCardStrategy = s;
    renderVmCardBreakdownWizard();
}

function renderVmCardBreakdownWizard() {
    const panel = document.getElementById('sourceDetailPanel');
    const nextBtn = document.getElementById('wizardSourceNext');
    if (panel) panel.style.display = 'block';
    if (!panel || !vmCardSources) {
        if (panel) panel.innerHTML = `<div style="text-align:center;padding:16px;"><div class="spinner"></div> Loading...</div>`;
        return;
    }
    panel.style.display = 'block';
    const currency = myCard.hook?.currency || myCard.currency || wizardState.currency;
    const merged = dedupeVmCardSources(vmCardSources);
    const total = merged.reduce((s, c) => s + c.amount, 0);
    const target = wizardState.amount || 0;
    const covered = Math.min(target, total);
    const canProceed = target > 0 && total >= target;
    const SEGCOUNT = 24;
    const filled = target > 0 ? Math.min(SEGCOUNT, Math.round((covered / target) * SEGCOUNT)) : 0;
    const STRATEGIES = [
        {id:'SMART', label:'Smart'}, {id:'EQUAL', label:'Equal'}, {id:'RATIO', label:'Ratio'},
        {id:'PRIORITY', label:'Priority'}, {id:'USER_SPECIFIED', label:'Manual'}, {id:'DRAIN_SMALLEST', label:'Drain smallest'}
    ];
    const rows = merged.map(c => `<div class="arcade-row">
        <div class="swatch" style="background:#00A878"></div>
        <div style="flex:1;min-width:0"><div class="title">${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)}</div><div class="sub">${escapeHtml(c.identifier || '')}</div></div>
        <div class="amt">${formatMoney(c.amount, currency)}</div>
    </div>`).join('');

    panel.innerHTML = `
        ${sourceTypeBackLinkHtml()}
        <div class="arcade-console">
            <div class="arcade-readout">
                <span class="arcade-num">${covered.toFixed(2)}</span>
                <span style="font-size:10px;color:#6f9c7d">HOOKED ${formatMoney(total, currency)}</span>
            </div>
            <div style="font-size:9px;color:#6f9c7d;margin-bottom:6px">DRAWING / TARGET ${formatMoney(target, currency)}</div>
            <div class="arcade-strip">${Array.from({length:SEGCOUNT},(_,i)=>`<div class="arcade-seg${i<filled?' on':''}"></div>`).join('')}</div>
            <div class="arcade-strat-row">
                ${STRATEGIES.map(s => `<button type="button" class="arcade-strat-btn${wizardState.vmCardStrategy===s.id?' active':''}" onclick="setWizardVmCardStrategy('${s.id}')">${s.label}</button>`).join('')}
            </div>
            <div style="font-size:9px;color:#6f9c7d;text-align:center;margin-bottom:10px">Drawing from everything hooked to your card — this strategy is used when you start the swap.</div>
            ${rows}
            <div class="arcade-status${canProceed ? ' ready' : ''}">
                ${canProceed ? 'CAN_PROCEED: TRUE' : 'ENTER AN AMOUNT AT OR BELOW WHAT\u2019S HOOKED'}
            </div>
        </div>
        <div style="text-align:center;margin-top:10px;"><span class="quick-link muted" onclick="openHookBuilder('swapwizard')">+ Hook another source</span></div>`;

    if (nextBtn) nextBtn.disabled = !canProceed;
}

// ---- COMBINE SOURCES ----
function combineRowIsComplete(row) {
    if (!row.institution || !row.assetType) return false;
    const valid = fieldsValidForAsset(row.assetType, row.fields || {}, true);
    if (!valid.valid) return false;
    if (!row.amount || parseFloat(row.amount) <= 0) return false;
    return true;
}

function renderCombineSummaryWizard() {
    const container = document.getElementById('sourceDetailPanel');
    if (!container) return;
    const nextBtn = document.getElementById('wizardSourceNext');

        if (wizardState.combineView === 'addRow') {
        renderCombineAddRow(container);
        return;
    }
    
    const rows = wizardState.multiSources.filter(r => !r._draft);
    const total = wizardState.tabTotalAmount || 0;
    const covered = rows.reduce((s, r) => s + (r.amount || 0), 0);
    const remaining = Math.max(0, total - covered);
    const canExecute = total > 0 && covered >= total && rows.length >= 2;
    const SEGCOUNT = 24;
    const filled = total > 0 ? Math.min(SEGCOUNT, Math.round((covered / total) * SEGCOUNT)) : 0;
    const STRATEGIES = [
        {id:'SMART', label:'Smart'}, {id:'EQUAL', label:'Equal'}, {id:'RATIO', label:'Ratio'},
        {id:'PRIORITY', label:'Priority'}, {id:'USER_SPECIFIED', label:'Manual'}, {id:'DRAIN_SMALLEST', label:'Drain smallest'}
    ];
    const SWATCH = { saved: '#ffb300', voucher: '#ff2fa0' };

    let html = sourceTypeBackLinkHtml();
    html += `<div class="arcade-console">
        <div class="arcade-readout">
            <span class="arcade-num" id="combineCoveredNum">${covered.toFixed(2)}</span>
            <span style="display:flex;align-items:center;gap:6px">
                <input type="number" class="arcade-target-input" id="combineTotal" value="${total || ''}" placeholder="0.00" step="0.01" oninput="updateCombineTotal(this.value)">
                <span style="font-size:10px;color:#6f9c7d">${wizardState.currency}</span>
            </span>
        </div>
        <div style="font-size:9px;color:#6f9c7d;margin-bottom:6px">COVERED / TARGET</div>
        <div class="arcade-strip">${Array.from({length:SEGCOUNT},(_,i)=>`<div class="arcade-seg${i<filled?' on':''}"></div>`).join('')}</div>
        <div class="arcade-strat-row">
            ${STRATEGIES.map(s => `<button type="button" class="arcade-strat-btn${wizardState.contributionStrategy===s.id?' active':''}" onclick="setCombineStrategy('${s.id}')">${s.label}</button>`).join('')}
        </div>
        <div class="arcade-hint" id="combineStratHint"></div>
        <div id="combineRowsList">${rows.map(r => renderCombineConsoleRow(r, SWATCH)).join('') || '<div class="empty-source-box"><p>No sources added yet.</p></div>'}</div>
        <button type="button" class="arcade-add-btn" onclick="openCombineAddRow()">+ ADD SOURCE</button>
        <div class="arcade-status${canExecute ? ' ready' : ''}">
            ${canExecute ? 'CAN_EXECUTE: TRUE — READY TO SWAP' : `REMAINING ${remaining.toFixed(2)} ${wizardState.currency} — CAN_EXECUTE: FALSE`}
        </div>
    </div>`;

    container.style.display = 'block';
    container.innerHTML = html;
    
    const hints = { SMART:'VouchMorph balances contributions automatically.', EQUAL:'Splits the target evenly across every source.', RATIO:'Proportional to each source\u2019s available balance.', PRIORITY:'Draws fully from the first source before moving on.', USER_SPECIFIED:'Edit each source\u2019s amount yourself below.', DRAIN_SMALLEST:'Empties the smallest available balance first.' };
    const hintEl = document.getElementById('combineStratHint');
    if (hintEl) hintEl.textContent = hints[wizardState.contributionStrategy] || '';
    
    if (nextBtn) nextBtn.disabled = !canExecute;
}

function renderCombineConsoleRow(row, SWATCH) {
    let title = '', sub = '';
    if (row.type === 'saved') { title = PARTICIPANTS[row.institution]?.name || row.institution; const idField = (getAssetConfig(row.assetType)?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount'); sub = idField ? (row.fields[idField.name] || '') : ''; }
    else if (row.type === 'voucher') { title = 'VOUCHER' + (row.institution ? ` \u00b7 ${PARTICIPANTS[row.institution]?.name || row.institution}` : ''); sub = row.fields.voucher_number || ''; }
    const editable = wizardState.contributionStrategy === 'USER_SPECIFIED';
    const color = SWATCH[row.type] || '#8ee6a3';
    return `<div class="arcade-row" data-row-id="${row.id}">
        <div class="swatch" style="background:${color}"></div>
        <div style="flex:1;min-width:0"><div class="title">${escapeHtml(title)}</div><div class="sub">${escapeHtml(sub)}</div></div>
        ${editable
            ? `<input type="number" step="0.01" value="${row.amount || ''}" style="width:70px;text-align:right;font-family:var(--font-mono);background:#000;color:#39ff6a;border:1px solid #2c3a3d;padding:5px" oninput="setConsoleRowAmount(${row.id}, this.value)">`
            : `<div class="amt">${row.amount.toFixed(2)}</div>`}
        <button type="button" class="quick-link danger" style="padding:4px 8px;font-size:9px" onclick="removeCombineRow(${row.id})">X</button>
    </div>`;
}

function setConsoleRowAmount(id, value) {
    const row = wizardState.multiSources.find(r => r.id === id);
    if (!row) return;
    row.amount = Math.max(0, parseFloat(value) || 0);
    refreshCombineMetrics();
}

function refreshCombineMetrics() {
    const rows = wizardState.multiSources.filter(r => !r._draft);
    const total = wizardState.tabTotalAmount || 0;
    const covered = rows.reduce((s, r) => s + (r.amount || 0), 0);
    const remaining = Math.max(0, total - covered);
    const canExecute = total > 0 && covered >= total && rows.length >= 2;
    const SEGCOUNT = 24;
    const filled = total > 0 ? Math.min(SEGCOUNT, Math.round((covered / total) * SEGCOUNT)) : 0;

    const numEl = document.getElementById('combineCoveredNum');
    if (numEl) numEl.textContent = covered.toFixed(2);

    const strip = document.querySelector('#sourceDetailPanel .arcade-strip');
    if (strip) strip.innerHTML = Array.from({length: SEGCOUNT}, (_, i) => `<div class="arcade-seg${i < filled ? ' on' : ''}"></div>`).join('');

    const status = document.querySelector('#sourceDetailPanel .arcade-status');
    if (status) {
        status.className = 'arcade-status' + (canExecute ? ' ready' : '');
        status.textContent = canExecute ? 'CAN_EXECUTE: TRUE — READY TO SWAP' : `REMAINING ${remaining.toFixed(2)} ${wizardState.currency} — CAN_EXECUTE: FALSE`;
    }

    rows.forEach(r => {
        const el = document.querySelector(`.arcade-row[data-row-id="${r.id}"] .amt`);
        if (el) el.textContent = r.amount.toFixed(2);
    });

    const nextBtn = document.getElementById('wizardSourceNext');
    if (nextBtn) nextBtn.disabled = !multiSourcesValid();
}

function setCombineStrategy(strategy) {
    wizardState.contributionStrategy = strategy;
    if (strategy === 'EQUAL' || strategy === 'SMART') autoSplitCombineEven();
    renderCombineSummaryWizard();
}

function autoSplitCombineEven() {
    const flexRows = wizardState.multiSources.filter(r => r.type === 'saved');
    const voucherTotal = wizardState.multiSources.filter(r => r.type === 'voucher').reduce((s, r) => s + (r.amount || 0), 0);
    const toSplit = Math.max(0, wizardState.tabTotalAmount - voucherTotal);
    if (flexRows.length === 0) return;
    const share = Math.floor((toSplit / flexRows.length) * 100) / 100;
    let allocated = 0;
    flexRows.forEach((r, i) => {
        r.amount = (i === flexRows.length - 1) ? Math.round((toSplit - allocated) * 100) / 100 : share;
        allocated += r.amount;
    });
}

function multiSourcesValid() {
    const rows = wizardState.multiSources.filter(r => !r._draft && combineRowIsComplete(r));
    if (rows.length < 2) return false;
    const target = wizardState.tabTotalAmount || rows.reduce((s, r) => s + r.amount, 0);
    return target > 0;
}

function openCombineAddRow(rowId) {
    wizardState.combineView = 'addRow';
    if (rowId) {
        wizardState.combineEditingRowId = rowId;
        const row = wizardState.multiSources.find(r => r.id === rowId);
        combineEditSnapshot = row ? JSON.parse(JSON.stringify(row)) : null;
    } else {
        wizardState.combineEditingRowId = ++multiSourceSeq;
        combineEditSnapshot = null;
        wizardState.multiSources.push({ id: wizardState.combineEditingRowId, type: null, institution: null, assetType: null, fields: {}, amount: 0, _draft: true });
    }
    renderCombineSummaryWizard();
}

function closeCombineAddRow(discard) {
    const id = wizardState.combineEditingRowId;
    if (discard) {
        if (combineEditSnapshot) {
            const idx = wizardState.multiSources.findIndex(r => r.id === id);
            if (idx > -1) wizardState.multiSources[idx] = combineEditSnapshot;
        } else {
            wizardState.multiSources = wizardState.multiSources.filter(r => !(r.id === id && r._draft));
        }
    } else {
        const row = wizardState.multiSources.find(r => r.id === id);
        if (row) delete row._draft;
    }
    wizardState.combineEditingRowId = null;
    combineEditSnapshot = null;
    wizardState.combineView = 'list';
    if (wizardState.contributionStrategy !== 'RATIO') autoSplitCombineEven();
    renderCombineSummaryWizard();
}

function renderCombineAddRow(panel) {
    const row = wizardState.multiSources.find(r => r.id === wizardState.combineEditingRowId);
    if (!row) { wizardState.combineView = 'list'; renderCombineSummaryWizard(); return; }
    const savedEligible = userSources.filter(u => u.status === 'active');

    let html = `<button type="button" class="source-type-back-link" onclick="closeCombineAddRow(true)">&larr; Cancel, back to sources</button>`;
    html += `<div class="combine-type-picker">
        <div class="source-type-opt ${row.type === 'saved' ? 'active' : ''} ${savedEligible.length ? '' : 'disabled'}" onclick="${savedEligible.length ? "setCombineRowType('saved')" : ''}"><span class="icon">💳</span>Saved source</div>
        <div class="source-type-opt ${row.type === 'voucher' ? 'active' : ''}" onclick="setCombineRowType('voucher')"><span class="icon">🎟️</span>Voucher</div>
    </div>`;

    if (!row.type) { html += `<div class="help" style="text-align:center;">Pick where this part of the swap comes from.</div>`; panel.innerHTML = html; return; }

    if (row.type === 'saved') {
        html += `<div class="dropdown-select" id="combineSavedDropdown">
            <div class="dropdown-select-trigger" onclick="document.getElementById('combineSavedDropdown').classList.toggle('open')">
                <span class="${row.institution ? 'chosen' : 'placeholder'}">${row.institution ? escapeHtml(PARTICIPANTS[row.institution]?.name || row.institution) : 'Select a saved source &rsaquo;'}</span>
                <span class="dropdown-select-chevron">&#9662;</span>
            </div>
            <div class="dropdown-select-panel">
                ${savedEligible.map(u => `<div class="saved-source-row ${row.sourceId === u.id ? 'active' : ''}" onclick="applyCombineSavedSource('${u.id}')"><div class="row-main"><div class="row-inst">${escapeHtml(PARTICIPANTS[u.institution]?.name || u.institution)}</div><div class="row-ident">${escapeHtml(u.identifier || u.source_identifier || '')}</div></div></div>`).join('')}
            </div>
        </div>`;
        if (row.institution && wizardState.contributionStrategy === 'USER_SPECIFIED') {
            html += `<div class="field-group" style="margin-top:14px;"><label>Amount from this source</label><input type="number" step="0.01" placeholder="0.00" value="${row.amount || ''}" oninput="setCombineRowAmount(this.value)"></div>`;
        } else if (row.institution) {
            html += `<div class="help" style="margin-top:14px;">Amount is set automatically by your "${wizardState.contributionStrategy}" strategy after you save.</div>`;
        }
    } else if (row.type === 'voucher') {
        html += `<div class="field-group"><label>Institution</label><select id="combineVoucherInst" onchange="setCombineVoucherInst(this.value)"><option value="">Select</option>${institutionsForTile('VOUCHER').map(m => `<option value="${m.institution}" ${row.institution === m.institution ? 'selected' : ''}>${PARTICIPANTS[m.institution]?.name || m.institution}</option>`).join('')}</select></div>`;
        if (row.institution) html += `<div id="combineVoucherFields">${renderVoucherFieldsForCombine(row)}</div>`;
    }

    html += `<div class="cta-row" style="margin-top:20px;"><button class="btn btn-secondary" onclick="closeCombineAddRow(true)">Cancel</button><button class="btn btn-primary" ${combineRowIsComplete(row) ? '' : 'disabled'} onclick="closeCombineAddRow(false)">Save source</button></div>`;
    panel.innerHTML = html;
}

function setCombineRowType(type) {
    const row = wizardState.multiSources.find(r => r.id === wizardState.combineEditingRowId);
    if (!row) return;
    row.type = type;
    row.institution = null; row.assetType = type === 'voucher' ? 'VOUCHER' : null;
    row.fields = {}; row.sourceId = null; row.amount = row.amount || 0;
    renderCombineSummaryWizard();
}

function applyCombineSavedSource(sourceId) {
    const row = wizardState.multiSources.find(r => r.id === wizardState.combineEditingRowId);
    const source = userSources.find(u => u.id === sourceId);
    if (!row || !source) return;
    row.institution = source.institution;
    row.assetType = String(source.asset_type).toUpperCase();
    row.sourceId = sourceId;
    row.fields = {};
    const idField = (getAssetConfig(row.assetType)?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    if (idField) row.fields[idField.name] = source.identifier || source.source_identifier || '';
    if (wizardState.contributionStrategy !== 'USER_SPECIFIED') {
        autoSplitCombineEven();
    }
    renderCombineSummaryWizard();
}

function setCombineRowAmount(value) {
    const row = wizardState.multiSources.find(r => r.id === wizardState.combineEditingRowId);
    if (row) row.amount = parseFloat(value) || 0;
    const btn = document.querySelector('.cta-row .btn-primary');
    if (btn) btn.disabled = !combineRowIsComplete(row);
}

function setCombineVoucherInst(code) {
    const row = wizardState.multiSources.find(r => r.id === wizardState.combineEditingRowId);
    if (!row) return;
    row.institution = code || null;
    row.fields = {};
    renderCombineSummaryWizard();
}

function renderVoucherFieldsForCombine(row) {
    const fields = (getAssetConfig('VOUCHER')?.fields || []);
    return fields.map(f => {
        const inputType = f.vault_field === 'pin' ? 'password' : (f.type === 'number' ? 'number' : 'text');
        return `<div class="field-group"><label>${f.label}${f.required ? ' *' : ''}</label><input type="${inputType}" placeholder="${f.placeholder || ''}" value="${row.fields[f.name] || ''}" oninput="setCombineVoucherField('${f.name}', this.value)"></div>`;
    }).join('');
}

function setCombineVoucherField(name, value) {
    const row = wizardState.multiSources.find(r => r.id === wizardState.combineEditingRowId);
    if (!row) return;
    row.fields[name] = name === 'amount' ? (parseFloat(value) || 0) : value;
    if (name === 'amount') row.amount = row.fields.amount;
    const btn = document.querySelector('.cta-row .btn-primary');
    if (btn) btn.disabled = !combineRowIsComplete(row);
}

function removeCombineRow(id) {
    wizardState.multiSources = wizardState.multiSources.filter(s => s.id !== id);
    if (wizardState.contributionStrategy !== 'RATIO') autoSplitCombineEven();
    renderCombineSummaryWizard();
}

function updateCombineTotal(value) {
    wizardState.tabTotalAmount = parseFloat(value) || 0;
    if (wizardState.contributionStrategy === 'EQUAL' || wizardState.contributionStrategy === 'SMART') autoSplitCombineEven();
    refreshCombineMetrics();
}

// ---- RESULT MODAL ----
function showWizardResultModal(response, journeyData) {
    const data = response.data || {};
    const reference = response.swap_reference || data.reference || '—';
    const swapType = wizardState.destType || 'DEPOSIT';
    const amount = data.amount ?? wizardState.amount;

    let inner = '';
    if (swapType === 'CASHOUT') {
        inner = `<div class="result-box" id="resultBoxRoot">
            <div class="icon">✓</div>
            <div class="result-title">Nice! Your cashout code is ready</div>
            <div class="result-sub">Reference: ${escapeHtml(reference)}</div>
            ${data.atm_code || data.voucher_number ? `<div style="background:var(--surface-muted);padding:18px;border:1px solid var(--border);margin:12px 0;"><div style="font-size:24px;font-weight:700;font-family:var(--font-mono);letter-spacing:4px;color:var(--accent);">${escapeHtml(data.atm_code || data.voucher_number || '')}</div></div>` : ''}
            <div style="font-size:22px;font-weight:600;font-family:var(--font-mono);">${formatMoney(amount, wizardState.currency)}</div>
        </div>`;
    } else if (swapType === 'IDENTITY') {
        const claimPinBox = data.claim_pin ? `<div style="background:var(--surface-muted);padding:12px;border:1px solid var(--border);margin:12px 0;"><div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Backup PIN — only share this if the recipient doesn't get the SMS</div><div style="font-size:20px;font-weight:700;font-family:var(--font-mono);letter-spacing:4px;color:var(--accent);">${escapeHtml(data.claim_pin)}</div></div>` : '';
        inner = `<div class="result-box" id="resultBoxRoot">
            <div class="icon">✓</div>
            <div class="result-title">Sent! They'll be notified now</div>
            <div class="result-sub">Reference: ${escapeHtml(reference)}</div>
            <div style="margin:12px 0;font-size:13px;"><strong>${escapeHtml(wizardState.identityType)}: ${escapeHtml(wizardState.identityValue)}</strong></div>
            <div style="font-size:22px;font-weight:600;font-family:var(--font-mono);">${formatMoney(amount, wizardState.currency)}</div>${claimPinBox}
        </div>`;
    } else {
        inner = `<div class="result-box" id="resultBoxRoot">
            <div class="icon">✓</div>
            <div class="result-title">Nice! Your money's on its way</div>
            <div class="result-sub">Reference: ${escapeHtml(reference)}</div>
            <div style="font-size:22px;font-weight:600;font-family:var(--font-mono);margin-top:12px;">${formatMoney(amount, wizardState.currency)}</div>
        </div>`;
    }

    const swapCount = journeyData?.swapsThisMonth || 1;
    const statLine = swapCount > 1
        ? `<div class="result-stat">You've made ${swapCount} swaps this month. You've got the hang of this. 🎉</div>`
        : `<div class="result-stat">That's your swap — nicely done. It'll show up in Activity any time you want to check on it.</div>`;

    const fullHtml = `${inner}${statLine}
        <div class="cta-row" style="margin-top:16px;"><button class="btn btn-primary" onclick="closeModal(); resetWizard(); goView('hub');">Done</button></div>
        <div style="display:flex;justify-content:center;gap:20px;margin-top:12px;">
            <button type="button" class="ledger-link" onclick="closeModal(); resetWizard(); goView('activity');">View in Activity</button>
            <button type="button" class="ledger-link" onclick="closeModal(); resetWizard(); goView('swap');">Make another swap</button>
        </div>`;

    openModal('Swap result', fullHtml);
    setTimeout(() => {
        const root = document.getElementById('resultBoxRoot');
        if (root) fireConfetti(root);
    }, 150);
}

function resetWizard() {
    wizardState.step = 1;
    wizardState.amount = 0;
    wizardState.source = null;
    wizardState.fromInst = null;
    wizardState.fromAsset = null;
    wizardState.fromFields = {};
    wizardState.destType = null;
    wizardState.toInst = null;
    wizardState.toAsset = null;
    wizardState.toFields = {};
    wizardState.deliveryMethod = 'ATM';
    wizardState.beneficiaryPhone = '';
    wizardState.identityType = 'national_id';
    wizardState.identityValue = '';
    wizardState.identitySms = '';
    wizardState.multiSources = [];
    wizardState.contributionStrategy = 'SMART';
    wizardState.tabTotalAmount = 0;
    wizardState.vmCardStrategy = 'SMART';
    wizardState.combineView = 'list';
    wizardState.combineEditingRowId = null;
    wizardState.lastPreview = null;
    wizardState.swapPayload = null;

    document.getElementById('wizardAmount').value = '';
    document.querySelectorAll('.source-option').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.dest-option').forEach(el => el.classList.remove('active'));

    document.getElementById('sourceGrid')?.classList.remove('hidden');
    const sourcePanel = document.getElementById('sourceDetailPanel');
    if (sourcePanel) { sourcePanel.style.display = 'none'; sourcePanel.innerHTML = ''; }
    const destPanel = document.getElementById('destDetailPanel');
    if (destPanel) { destPanel.style.display = 'none'; destPanel.innerHTML = ''; }

    renderStep(1);
}

// ---- WIZARD INIT ----
function initWizard() {
    const toSelect = document.getElementById('toInstSelect');
    if (toSelect) {
        const instOptions = Object.keys(PARTICIPANTS);
        toSelect.innerHTML = '<option value="">Select institution</option>' +
            instOptions.map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('');
    }
    resetWizard();
    loadVmCardSources();
}

// ============================================================
// HOOK BUILDER
// ============================================================
const HOOK_ASSET_TYPES = [
    { key: 'ACCOUNT', label: 'Account', icon: '🏦' },
    { key: 'WALLET', label: 'Wallet', icon: '📱' },
    { key: 'CARD', label: 'Card (Visa/Mastercard)', icon: '🪪' },
    { key: 'VOUCHER', label: 'Cashout voucher', icon: '🎟️' },
];
let hookMode = 'single';
let hookRows = [];
let hookRowSeq = 0;
let hookEntry = { source: 'card', targetCardSuffix: null };
let pendingHookPrefill = null;
let unhookTarget = null;

let scanHookState = { source: null, amount: null };

// Entry point. Pass a prefill ({instName, institution, assetType, identifier})
// when the source is already known (e.g. from a source row's "Hook to card"
// link) — the source step is skipped and only the amount is asked.
function openScanAndHook(prefill) {
    scanHookState = { source: prefill ? { ...prefill, locked: true } : null, amount: null };
    renderScanHookSetup();
}

function renderScanHookSetup() {
    const eligible = userSources.filter(s => s.status === 'active');
    const s = scanHookState.source;

    const sourcePickerHtml = (s && s.locked)
        ? `<div class="myc-panel" style="margin-bottom:14px;">
               <div style="font-weight:700;font-size:13px;">${escapeHtml(s.instName)}</div>
               <div style="font-size:11px;color:var(--text-dim);">${escapeHtml(s.identifier)}</div>
           </div>`
        : `<div class="field-group">
               <label>Source to hook</label>
               <select id="scanHookSourceSelect" onchange="onScanHookSourceChange(this.value)">
                   <option value="">Select a saved source</option>
                   ${eligible.map(u => `<option value="${u.id}">${PARTICIPANTS[u.institution]?.name || u.institution} — ${u.identifier || u.source_identifier || ''}</option>`).join('')}
               </select>
               <div class="help">Only sources you've already added can be hooked this way. <span class="quick-link muted" onclick="closeModal(); openAddSource();">Add a new source first &rarr;</span></div>
           </div>`;

    const bodyHtml = `
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">Pick what you're hooking and how much to authorize — then scan the card you're hooking it to.</div>
        ${sourcePickerHtml}
        <div class="field-group">
            <label>Amount to authorize</label>
            <input type="number" id="scanHookAmount" min="0.01" step="0.01" placeholder="0.00"
                   oninput="scanHookState.amount = parseFloat(this.value) || null; refreshScanHookContinue();">
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="scanHookContinueBtn" onclick="proceedToScanForHook()" disabled>Continue to scan →</button>
        </div>`;
    openModal('Scan & hook', bodyHtml);
}

function onScanHookSourceChange(sourceId) {
    const source = userSources.find(u => u.id === sourceId);
    scanHookState.source = source ? {
        instName: PARTICIPANTS[source.institution]?.name || source.institution,
        institution: source.institution,
        assetType: source.asset_type,
        identifier: source.identifier || source.source_identifier || '',
    } : null;
    refreshScanHookContinue();
}

function refreshScanHookContinue() {
    const btn = document.getElementById('scanHookContinueBtn');
    if (btn) btn.disabled = !(scanHookState.source && scanHookState.amount > 0);
}

function proceedToScanForHook() {
    openScanModal('scan_and_hook');
}

// Called from handleScannedQr's 'hook' case when context === 'scan_and_hook'.
function confirmScanAndHook(cardSuffix, displayName) {
    const s = scanHookState.source;
    const bodyHtml = `
        <div class="review-hero">
            <div class="review-hero-label">Hook to ${escapeHtml(displayName)}'s card</div>
            <div class="review-hero-amount">${formatMoney(scanHookState.amount, myCard?.currency || 'BWP')}</div>
        </div>
        <div class="preview-box">
            <div class="preview-row"><span>Source</span><span class="value">${escapeHtml(s.instName)}</span></div>
            <div class="preview-row" style="border-bottom:none;"><span>Identifier</span><span class="value">${escapeHtml(s.identifier)}</span></div>
        </div>
        <div class="preview-reassure">Held for 24 hours — nothing moves until it's spent.</div>`;

    hookEntry = { source: 'scan_and_hook', targetCardSuffix: cardSuffix };
    pendingExecution = {
        type: 'hook',
        payload: {
            sources: [{
                institution: s.institution,
                asset_type: s.assetType,
                identifier: s.identifier,
                authorized_amount: scanHookState.amount,
            }],
            cardSuffix: cardSuffix,
        },
        callback: () => executeHook()
    };
    showPreviewModal('Confirm hook', bodyHtml, null, 'Hook it');
}

async function openHookBuilder(entryType, prefill) {
    if (!myCard) {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/My.php');
        if (!result.ok) { showMessage("Couldn't load your card: " + friendlyApiError(result.error), 'error'); return; }
        myCard = result.body.data;
    }
    if (!myCard.is_active) { showMessage('Your VouchMorph Card is not active yet. Activate it first from the Card view.', 'warning'); return; }
    
    hookEntry = { source: entryType, targetCardSuffix: myCard.card_suffix };
    hookRows = []; hookRowSeq = 0; hookMode = 'single';
    document.getElementById('hookViewTitle').textContent = 'Hook a source';
    document.getElementById('hookModeRow').style.display = 'flex';
    document.getElementById('addHookRowBtn').style.display = 'none';
    document.getElementById('hookSubmitBtn').style.display = 'block';
    document.getElementById('hookFinePrint').style.display = 'block';
    
    const eyebrowMap = {
        card: 'Hook to My VouchMorph Card',
        swapwizard: 'Hook to My VouchMorph Card',
        source: 'Hook this source to a card',
    };
    const noteMap = {
        card: 'Started from the Card view — building the list of sources to hook.',
        swapwizard: 'Started from Swap — hook a source, then come back to fund your swap.',
        source: `Started from "${prefill?.instName}" in My sources — this source is pre-filled below.`,
    };
    document.getElementById('hookEyebrow').textContent = eyebrowMap[entryType] || eyebrowMap.card;
    document.getElementById('hookEntryNote').textContent = noteMap[entryType] || noteMap.card;

    const sourcesResult = await callApi(CONFIG.API_BASE + '/api/v1/cards/GetCardSources.php', { card_suffix: myCard.card_suffix });
    const existing = sourcesResult.ok ? (sourcesResult.body.data?.sources || sourcesResult.body.data || []) : [];
    vmCardSources = existing;
    renderExistingHookedSummary(existing);

    if (entryType === 'source' && prefill) addHookRow(prefill);
    else addHookRow();
    setHookMode('single');
    pushView('hook');
}

function renderExistingHookedSummary(sources) {
    let holder = document.getElementById('hookExistingSummary');
    if (!holder) {
        holder = document.createElement('div');
        holder.id = 'hookExistingSummary';
        const rowsHolder = document.getElementById('hookRowsHolder');
        rowsHolder.parentNode.insertBefore(holder, rowsHolder);
    }
    if (!sources.length) { holder.innerHTML = ''; return; }
    const currency = myCard?.hook?.currency || myCard?.currency || '';
    holder.innerHTML = `
        <div class="myc-panel" style="margin-bottom:16px;">
            <div class="myc-panel-head"><span class="myc-panel-title">Already hooked</span></div>
            ${sources.map(c => `
                <div class="myc-source-row">
                    <div class="myc-source-tile">${escapeHtml(institutionInitials(c.institution))}</div>
                    <div class="myc-source-info">
                        <div class="myc-source-inst">${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)}</div>
                        <div class="myc-source-ident">${escapeHtml(c.identifier || '')}</div>
                    </div>
                    <div class="myc-source-amt">${formatMoney(c.available_balance ?? c.authorized_amount ?? 0, currency)}</div>
                </div>
            `).join('')}
        </div>`;
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

function addAllSavedSourcesToHook() {
    const eligible = userSources.filter(s => s.status === 'active');
    if (!eligible.length) { showMessage("You don't have any saved sources yet — add one from Toolbox first.", 'info'); return; }
    hookRows = eligible.map(s => ({
        id: ++hookRowSeq,
        instName: PARTICIPANTS[s.institution]?.name || s.institution,
        institution: s.institution,
        assetType: s.asset_type,
        identifier: s.identifier || s.source_identifier || '',
        pin: '', amount: '', locked: true
    }));
    hookMode = 'multi';
    document.getElementById('hookModeSingleBtn')?.classList.remove('active');
    document.getElementById('hookModeMultiBtn')?.classList.add('active');
    document.getElementById('addHookRowBtn').style.display = 'block';
    renderHookRows();
    showMessage(`Added all ${eligible.length} of your saved sources — just fill in an amount for each.`, 'success');
}

function pickHookRowFromSaved(rowId) {
    const el = document.getElementById('hookSavedPicker' + rowId);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function applyHookRowSavedSource(rowId, sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    const row = hookRows.find(r => r.id === rowId);
    if (!source || !row) return;
    row.institution = source.institution;
    row.assetType = String(source.asset_type).toUpperCase();
    row.identifier = source.identifier || source.source_identifier || '';
    row.locked = true;
    row.instName = PARTICIPANTS[source.institution]?.name || source.institution;
    renderHookRows();
}

function removeHookRow(id) { hookRows = hookRows.filter(r => r.id !== id); renderHookRows(); }

function setHookRowAssetType(id, type) {
    const row = hookRows.find(r => r.id === id);
    if (!row) return;
    row.assetType = type;
    renderHookRows();
}

function renderHookRows() {
    const holder = document.getElementById('hookRowsHolder');
    if (!holder) return;
    holder.innerHTML = hookRows.map((row, idx) => {
        if (row.locked) {
            return `<div class="hook-row-card"><div class="hook-row-head"><span class="hook-row-label">Source ${idx + 1} — from My sources</span><button class="hook-row-remove" onclick="removeHookRow(${row.id})">Remove</button></div><div style="font-size:13px;font-weight:700;">${escapeHtml(row.instName)}</div><div style="font-size:11px;color:var(--text-dim);margin-bottom:10px;">${escapeHtml(row.identifier)}</div><div class="field-group"><label>Amount to authorize</label><input type="number" placeholder="0.00" value="${row.amount}" oninput="hookRows.find(r=>r.id===${row.id}).amount=this.value"></div></div>`;
        }
        const savedPickerHtml = userSources.filter(u => u.status === 'active').length ? `
    <div style="margin-bottom:10px;">
        <span class="quick-link muted" onclick="pickHookRowFromSaved(${row.id})">Use a saved source &rsaquo;</span>
        <div id="hookSavedPicker${row.id}" style="display:none;margin-top:6px;border:1px solid var(--border-strong);">
            ${userSources.filter(u => u.status === 'active').map(u => `<div class="saved-source-row" onclick="applyHookRowSavedSource(${row.id}, '${u.id}')"><div class="row-main"><div class="row-inst">${escapeHtml(PARTICIPANTS[u.institution]?.name || u.institution)}</div><div class="row-ident">${escapeHtml(u.identifier || u.source_identifier || '')}</div></div></div>`).join('')}
        </div>
    </div>` : '';
        const typeOptions = HOOK_ASSET_TYPES.map(t => `<div class="source-type-opt ${row.assetType === t.key ? 'active' : ''}" onclick="setHookRowAssetType(${row.id}, '${t.key}')"><span class="icon">${t.icon}</span>${t.label}</div>`).join('');
        let extraFields = '';
        let institutionField = '';
        if (row.assetType) {
            const eligibleCodes = Object.keys(PARTICIPANTS).filter(code => (PARTICIPANTS[code].asset_types || []).map(t => String(t).toUpperCase()).includes(row.assetType === 'VOUCHER' ? 'VOUCHER' : row.assetType));
            const options = eligibleCodes.map(code => `<option value="${code}" ${row.institution === code ? 'selected' : ''}>${PARTICIPANTS[code]?.name || code}</option>`).join('');
            institutionField = `<div class="field-group"><label>Institution</label><select onchange="hookRows.find(r=>r.id===${row.id}).institution=this.value; renderHookRows();"><option value="">Select institution</option>${options}</select></div>`;
            const cfg = getAssetConfig(row.assetType);
            const pinField = (cfg?.fields || []).find(f => f.vault_field === 'pin');
            if (pinField) extraFields = `<div class="field-group"><label>${pinField.label}</label><input type="password" placeholder="${pinField.placeholder || ''}" value="${row.pin}" oninput="hookRows.find(r=>r.id===${row.id}).pin=this.value"></div>`;
        }
        return `<div class="hook-row-card">
            <div class="hook-row-head"><span class="hook-row-label">Source ${idx + 1}</span>${hookMode === 'multi' && hookRows.length > 1 ? `<button class="hook-row-remove" onclick="removeHookRow(${row.id})">Remove</button>` : ''}</div>
            ${savedPickerHtml}
            <div class="source-type-picker">${typeOptions}</div>
            ${row.assetType ? `
                ${institutionField}
                <div class="field-group"><label>Identifier</label><input placeholder="Account, phone, or voucher number" value="${escapeHtml(row.identifier)}" oninput="hookRows.find(r=>r.id===${row.id}).identifier=this.value"></div>
                ${extraFields}
                <div class="field-group"><label>Amount to authorize</label><input type="number" placeholder="0.00" value="${row.amount}" oninput="hookRows.find(r=>r.id===${row.id}).amount=this.value"></div>
            ` : ''}
        </div>`;
    }).join('');
}



async function executeHook() {
    const { sources, cardSuffix } = pendingExecution.payload;
    if (!sources || !cardSuffix) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/hook.php', { card_suffix: cardSuffix, sources });
    if (!result.ok) { showMessage('That hook didn\'t go through: ' + friendlyApiError(result.error), 'error'); return; }

    // Refetch real state instead of trusting the count we sent
    const cardResult = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/My.php');
    if (cardResult.ok) myCard = cardResult.body.data;
    const sourcesResult = await callApi(CONFIG.API_BASE + '/api/v1/cards/GetCardSources.php', { card_suffix: cardSuffix });
    const freshSources = sourcesResult.ok ? (sourcesResult.body.data?.sources || sourcesResult.body.data || []) : [];
    vmCardSources = freshSources;

    showHookSuccess(sources.length, freshSources);
}

function showHookSuccess(count, freshSources = []) {
    document.getElementById('hookEyebrow').textContent = 'Done';
    document.getElementById('hookViewTitle').textContent = 'Hooked!';
    document.getElementById('hookEntryNote').textContent = '';
    document.getElementById('hookModeRow').style.display = 'none';
    document.getElementById('addHookRowBtn').style.display = 'none';
    document.getElementById('hookSubmitBtn').style.display = 'none';
    document.getElementById('hookFinePrint').style.display = 'none';
    const existingHolder = document.getElementById('hookExistingSummary');
    if (existingHolder) existingHolder.innerHTML = '';

    const currency = myCard?.hook?.currency || myCard?.currency || '';
    const totalHeld = freshSources.reduce((s, c) => s + (c.available_balance ?? c.authorized_amount ?? 0), 0);

    const statusHtml = `
        <div class="myc-panel" style="margin-bottom:16px;">
            <div class="myc-panel-head">
                <span class="myc-panel-title">Your card right now</span>
                <span class="myc-panel-total">${formatMoney(totalHeld, currency)} held</span>
            </div>
            <div class="help" style="margin-bottom:10px;">This is everything currently hooked, across every time you've hooked a source — held for 24 hours per source unless spent first.</div>
            ${freshSources.map(c => `
                <div class="myc-source-row">
                    <div class="myc-source-tile">${escapeHtml(institutionInitials(c.institution))}</div>
                    <div class="myc-source-info">
                        <div class="myc-source-inst">${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)}</div>
                        <div class="myc-source-ident">${escapeHtml(c.identifier || '')}</div>
                    </div>
                    <div class="myc-source-amt">${formatMoney(c.available_balance ?? c.authorized_amount ?? 0, currency)}</div>
                </div>`).join('')}
        </div>`;

    const summaryHtml = `
        <div class="unhook-summary" style="border-color:var(--accent);">
            <div style="font-size:36px;margin-bottom:8px;">✓</div>
            <div style="font-size:16px;font-weight:700;color:var(--text);">${count} source${count > 1 ? 's' : ''} hooked for 24 hours</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">It's ready to use right away — anywhere you'd pick a source in Swap, "My Card" now covers this.</div>
        </div>`;

    if (hookEntry.source === 'swapwizard') {
        document.getElementById('hookRowsHolder').innerHTML = `
            ${summaryHtml}${statusHtml}
            <div style="font-size:13px;color:var(--text-muted);text-align:center;margin:14px 0;">What do you want to do with it?</div>
            <div class="cta-row" style="flex-direction:column;gap:10px;">
                <button class="btn btn-primary" onclick="viewStack=['hub']; goView('swap'); setTimeout(()=>{ initWizard(); setTimeout(()=>selectSource('VMCARD'), 60); }, 30);">Swap with it now</button>
                <button class="btn secondary" onclick="viewStack=['hub']; goView('card');">Done — just keep it loaded to swipe later</button>
            </div>`;
        return;
    }

    const cameFromLabel = hookEntry.source === 'source' ? 'My sources' : 'Card';
    const cameFromAction = hookEntry.source === 'source' ? "goView('toolbox')" : "goView('card')";
    document.getElementById('hookRowsHolder').innerHTML = `
        ${summaryHtml}${statusHtml}
        <div class="cta-row" style="flex-direction:column;gap:10px;">
            <button class="btn btn-primary" onclick="viewStack=['hub']; ${cameFromAction};">&larr; Back to ${cameFromLabel}</button>
            <button class="btn secondary" onclick="goView('hub')">Go to Home</button>
        </div>`;
}
    

function promptHookThisSource(prefill) {
    pendingHookPrefill = prefill;
    const bodyHtml = `
        <div style="text-align:center;padding:10px 0 20px;">
            <div style="font-size:13px;color:var(--text-muted);margin-bottom:18px;">Where should this source be hooked?</div>
            <div class="cta-row" style="flex-direction:column;gap:10px;">
                <button class="btn btn-primary" onclick="closeModal(); openHookBuilder('source', pendingHookPrefill);">My VouchMorph Card</button>
          <button class="btn btn-secondary" onclick="closeModal(); openScanAndHook(pendingHookPrefill);">Another VouchMorph Card</button>            </div>
        </div>`;
    openModal('Hook this source', bodyHtml);
}

function openAnotherCardChooser() {
    const bodyHtml = `
        <div style="padding:10px 0 4px;">
            <div class="field-group"><label>Card number or suffix</label><input id="manualCardNumber" placeholder="e.g. last 4 digits or full number"></div>
            <div class="cta-row"><button class="btn btn-primary" onclick="submitManualCardNumberForHook()">Continue</button></div>
            <div style="text-align:center;margin:16px 0;font-size:11px;color:var(--text-dim);">or</div>
            <button class="btn btn-secondary" onclick="openScanToHookModal()">Scan their QR code instead</button>
        </div>`;
    openModal('Hook to another card', bodyHtml);
}

async function submitManualCardNumberForHook() {
    const raw = document.getElementById('manualCardNumber')?.value.trim();
    if (!raw) { showMessage('Enter a card number.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/LookupBySuffix.php', { card_suffix: raw });
    if (!result.ok) { showMessage('Couldn\'t find that card: ' + friendlyApiError(result.error), 'error'); return; }
    const { card_suffix, display_name } = result.body.data;
    confirmHookTargetCard(card_suffix, display_name);
}

function confirmHookTargetCard(card_suffix, display_name) {
    const bodyHtml = `
        <div style="text-align:center;padding:16px;">
            <div style="font-size:14px;margin-bottom:16px;">You're about to hook this source to <strong>${escapeHtml(display_name)}'s</strong> VouchMorph Card.</div>
            <div class="cta-row">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="beginHookToOtherCard('${escapeHtml(card_suffix)}', '${escapeHtml(display_name)}')">Continue</button>
            </div>
        </div>`;
    openModal('Confirm', bodyHtml);
}

function beginHookToOtherCard(cardSuffix, displayName) {
    closeModal();
    hookEntry = { source: 'source', targetCardSuffix: cardSuffix };
    hookRows = []; hookRowSeq = 0; hookMode = 'single';
    addHookRow(pendingHookPrefill);
    setHookMode('single');
    document.getElementById('hookEyebrow').textContent = `Hook to ${displayName}'s Card`;
    document.getElementById('hookEntryNote').textContent = "Started from your source — hooking it to someone else's card.";
    document.getElementById('hookModeRow').style.display = 'flex';
    document.getElementById('addHookRowBtn').style.display = 'none';
    document.getElementById('hookSubmitBtn').style.display = 'block';
    document.getElementById('hookFinePrint').style.display = 'block';
    pushView('hook');
}

function openScanToHookModal() {
    const bodyHtml = `
        <div id="qrScannerRegion" style="width:100%;"></div>
        <div style="text-align:center;margin:12px 0;font-size:11px;color:var(--text-dim);">or</div>
        <div class="field-group"><label>Paste the card's code manually</label><input id="manualQrPaste" placeholder="Paste QR text if you can't scan"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="submitManualQr()">Continue</button></div>`;
    openModal('Scan a card', bodyHtml);
    try {
        html5QrScanner = new Html5Qrcode('qrScannerRegion');
        html5QrScanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 }, (decodedText) => { html5QrScanner.stop().catch(() => {}); resolveScannedQr(decodedText); }, () => {})
            .catch((e) => { console.warn('[card-qr] camera scan unavailable, manual paste only:', e); });
    } catch (e) { console.warn('[card-qr] Html5Qrcode not available, manual paste only:', e); }
}

function submitManualQr() {
    const raw = document.getElementById('manualQrPaste')?.value.trim();
    if (!raw) { showMessage('Paste the code first, or use the camera scanner above.', 'warning'); return; }
    resolveScannedQr(raw);
}

async function resolveScannedQr(raw) {
    if (html5QrScanner) { try { await html5QrScanner.stop(); } catch (e) {} }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Resolveqr.php', { raw });
    if (!result.ok) { showMessage('Couldn\'t read that code: ' + friendlyApiError(result.error), 'error'); return; }

    const data = result.body.data;
    switch (data.type) {
        case 'hook':
    if (context === 'scan_and_hook') {
        confirmScanAndHook(data.card_suffix, data.display_name);
    } else if (context === 'agent_charge') {          // added in section 2 below
        confirmAgentChargeCard(data.card_suffix, data.display_name);
    } else {
        confirmHookTargetCard(data.card_suffix, data.display_name);
    }
    break;
            confirmHookTargetCard(data.card_suffix, data.display_name);
            break;
        case 'payment_request':
            renderPaymentRequestConfirm(data);
            break;
        case 'account':
            openSwapWizardPrefilled(data);
            break;
        default:
            showMessage('Unrecognized QR type.', 'error');
    }
}

function openSwapWizardPrefilled(accountData) {
    // Reuses your existing swap wizard — just seeds the destination
    // step instead of leaving it blank for manual entry.
    openSwapWizard(); // your existing wizard-opening function
    wizardState.destination = {
        institution: accountData.institution,
        asset_type: accountData.asset_type,
        identifier: accountData.identifier,
        identifier_type: accountData.identifier_type,
    };
    if (accountData.display_name) {
        showMessage(`Destination filled in: ${accountData.display_name}`, 'success');
    }
    renderWizardStep(wizardState.currentStep); // re-render so the pre-filled fields show
}

function openUnhook(target) {
    unhookTarget = target;
    const bodyHtml = `
        <div class="unhook-summary" style="border-color: var(--warning);">
            <div class="icon">🔓</div>
            <div style="font-size:16px; font-weight:700; margin-bottom:4px;">Release this hook?</div>
            <div style="font-size:12px; color:var(--text-muted);">${target.count} source${target.count > 1 ? 's' : ''} · ${target.amount}</div>
            <div style="font-size:11px; color:var(--warning); margin-top:8px;">This releases every source in this hook — all together, not one at a time.</div>
        </div>
        <div style="font-size:12px; color:var(--text-muted); margin-bottom:16px;">
            Anything already spent (e.g. in a swap) can't be unhooked. Unhooked sources go back to being normal, spendable sources in your Toolbox.
        </div>`;

    pendingExecution = {
        type: 'unhook',
        payload: { hookReference: target.hookReference, cardSuffix: myCard?.card_suffix },
        callback: () => executeUnhook()
    };
    showPreviewModal('Unhook preview', bodyHtml, null, 'Unhook everything', 'btn-danger');
}

async function executeUnhook() {
    const { hookReference, cardSuffix } = pendingExecution.payload;
    if (!hookReference) { goBack(); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/unhook.php', { hook_reference: hookReference, card_suffix: cardSuffix });
    if (!result.ok) { showMessage('Couldn\'t unhook: ' + friendlyApiError(result.error), 'error'); return; }
    const data = result.body || {};
    if (data.status === 'UNHOOK_PARTIAL') showMessage('Some sources released — others could not be released automatically and remain held. See Help for what to do next.', 'warning');
    else showMessage('All hooked sources released. 🎉', 'success');
    goBack();
    loadCardView();
}

// ============================================================
// CARD VIEW
// ============================================================
async function loadCardView() {
    const body = document.getElementById('cardViewBody');
    if (!body) return;
    body.innerHTML = `<div style="text-align:center;padding:40px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading...</div>`;
    try {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/My.php');
        if (!result.ok) {
            body.innerHTML = `<div style="color:var(--danger);padding:12px;text-align:center;"><div style="font-weight:700;margin-bottom:6px;">Couldn't load your card</div><div style="font-size:12px;">${escapeHtml(friendlyApiError(result.error))}</div><button class="btn btn-secondary btn-sm" onclick="loadCardView()" style="margin-top:12px;width:auto;">Retry</button></div>`;
            return;
        }
        myCard = result.body.data;
        body.innerHTML = renderCardViewBody();
        if (myCard.is_active && myCard.qr_payload) renderCardQr(myCard.qr_payload);
        if (myCard.active_session) { lastKnownSession = myCard.active_session; startSessionPolling(myCard.active_session.session_id); }
    } catch (e) {
        body.innerHTML = `<div style="color:var(--danger);padding:12px;text-align:center;"><div style="font-weight:700;margin-bottom:6px;">Something went wrong loading your card</div><div style="font-size:12px;">${escapeHtml(e.message || String(e))}</div><button class="btn btn-secondary btn-sm" onclick="loadCardView()" style="margin-top:12px;width:auto;">Retry</button></div>`;
    }
}

function renderCardViewBody() {
    if (!myCard) return '<div>No card data</div>';
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
                ${hook && hook.contributors.length ? `<button class="btn secondary" style="margin-top:10px;" onclick="goView('swap'); setTimeout(()=>initWizard(), 30);">Use this card as a Swap source</button>` : ''}
                <div style="margin-top:14px;"><span class="quick-link muted" onclick="openScanAndHook(null)">📷 Scan & hook a source to another card</span>
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
    const holder = document.getElementById('qrHolderLg');
    if (holder) { holder.innerHTML = ''; new QRCode(holder, { text: myCard.qr_payload, width: 300, height: 300 }); }
}

function openNameCardModal() {
    const bodyHtml = `<div style="text-align:center;padding:6px 0 10px;font-size:12px;color:var(--text-muted);">Give your card a name — just for you, shown wherever you see it.</div><div class="field-group"><label>Card name</label><input id="cardNameInput" placeholder="e.g. Thabo's Card" maxlength="30"></div><div class="cta-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="saveCardName()">Save</button></div>`;
    openModal('Name your card', bodyHtml);
}

function saveCardName() {
    const name = document.getElementById('cardNameInput')?.value.trim();
    if (!name) { showMessage('Enter a name first.', 'warning'); return; }
    const data = Journey.read(); data.cardNamed = name; Journey.write(data);
    showMessage(`Saved — meet "${name}". 🎉`, 'success');
    closeModal(); loadCardView();
}

function openActivateCardModal() {
    const modalBody = document.getElementById('modalBody');
    if (!modalBody) return;
    
    // Build the activation form directly in the modal
    modalBody.innerHTML = `
        <div style="margin-bottom:16px;">
            <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Activate your VouchMorph Card</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">A one-time ${formatMoney(myCard.activation_fee, myCard.currency)} activation fee will be charged from the source you select.</div>
        </div>
        <div class="field-group">
            <label>Source institution</label>
            <select id="activateInstSelect" onchange="onActivateInstChange(this.value)">
                <option value="">Select institution</option>
                ${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}
            </select>
        </div>
        <div id="activateAssetGroup" style="display:none;">
            <div class="field-group">
                <label>Asset type</label>
                <select id="activateAssetSelect" onchange="onActivateAssetChange(this.value)"></select>
            </div>
        </div>
        <div id="activateFieldsContainer"></div>
        <div class="help" id="activateLimitsHelp" style="margin-top:8px;"></div>
        <div class="cta-row" style="margin-top:16px;">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="activateBtn" onclick="confirmActivateCard()" disabled>Pay and activate</button>
        </div>
    `;
    
    document.getElementById('modalTitle').textContent = 'Activate your card';
    document.getElementById('modal').classList.add('active');
}

// Add these helper functions right after openActivateCardModal:

function onActivateInstChange(code) {
    const assetGroup = document.getElementById('activateAssetGroup');
    const assetSelect = document.getElementById('activateAssetSelect');
    const fieldsContainer = document.getElementById('activateFieldsContainer');
    const limitsHelp = document.getElementById('activateLimitsHelp');
    const btn = document.getElementById('activateBtn');
    
    if (!code) {
        if (assetGroup) assetGroup.style.display = 'none';
        if (fieldsContainer) fieldsContainer.innerHTML = '';
        if (limitsHelp) limitsHelp.textContent = '';
        if (btn) btn.disabled = true;
        return;
    }
    
    const inst = PARTICIPANTS[code];
    if (limitsHelp) {
        limitsHelp.textContent = inst?.limits ? `Limits: ${inst.limits.min_amount} – ${inst.limits.max_amount} ${inst.limits.currency}` : '';
    }
    
    const assetTypes = inst?.asset_types || [];
    if (assetSelect) {
        assetSelect.innerHTML = '<option value="">Select asset type</option>' +
            assetTypes.map(t => `<option value="${t}">${assetIcon(t)} ${ASSETS[normalizeAssetType(t)]?.label || t}</option>`).join('');
    }
    if (assetGroup) assetGroup.style.display = assetTypes.length ? 'block' : 'none';
    if (fieldsContainer) fieldsContainer.innerHTML = '';
    if (btn) btn.disabled = true;
}

function onActivateAssetChange(type) {
    const fieldsContainer = document.getElementById('activateFieldsContainer');
    const btn = document.getElementById('activateBtn');
    
    if (!type || !fieldsContainer) {
        if (fieldsContainer) fieldsContainer.innerHTML = '';
        if (btn) btn.disabled = true;
        return;
    }
    
    // Store selected values globally for the activation
    window._activateSource = { assetType: type };
    
    // Render fields for this asset type
    const config = getAssetConfig(type);
    if (!config || !config.fields) {
        fieldsContainer.innerHTML = '';
        if (btn) btn.disabled = true;
        return;
    }
    
    fieldsContainer.innerHTML = config.fields.map(f => {
        if (f.name === 'amount') return '';
        const inputType = f.vault_field === 'pin' ? 'password' : (f.type === 'number' ? 'number' : 'text');
        return `<div class="field-group">
            <label>${f.label}${f.required ? ' *' : ''}</label>
            <input type="${inputType}" id="activateField_${f.name}" placeholder="${f.placeholder || ''}" oninput="onActivateFieldChange()">
            ${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}
        </div>`;
    }).join('');
    
    // Check validity after fields render
    setTimeout(onActivateFieldChange, 50);
}

function onActivateFieldChange() {
    const btn = document.getElementById('activateBtn');
    if (!btn) return;
    
    const inst = document.getElementById('activateInstSelect')?.value;
    const assetType = document.getElementById('activateAssetSelect')?.value;
    
    if (!inst || !assetType) {
        btn.disabled = true;
        return;
    }
    
    // Get all field values
    const fields = {};
    const config = getAssetConfig(assetType);
    if (config && config.fields) {
        config.fields.forEach(f => {
            if (f.name === 'amount') return;
            const el = document.getElementById('activateField_' + f.name);
            if (el) fields[f.name] = el.value;
        });
    }
    
    // Validate required fields
    const valid = fieldsValidForAsset(assetType, fields, true);
    btn.disabled = !valid.valid;
    
    // Store for later
    window._activateSource.fields = fields;
    window._activateSource.inst = inst;
}

function confirmActivateCard() {
    const inst = document.getElementById('activateInstSelect')?.value;
    const assetType = document.getElementById('activateAssetSelect')?.value;
    const fields = window._activateSource?.fields || {};
    
    // FIX: Use extractPinFromFields instead of direct 'pin' lookup
    const pin = extractPinFromFields(assetType, fields);
    
    const config = getAssetConfig(assetType);
    const idField = (config?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    const identifier = idField ? fields[idField.name] : null;
    
    if (!inst || !assetType || !identifier) {
        showMessage('Please complete all required fields.', 'warning');
        return;
    }
    
    const instName = PARTICIPANTS[inst]?.name || inst;
    
    const bodyHtml = `
        <div class="review-hero">
            <div class="review-hero-label">Activate your VouchMorph Card</div>
            <div class="review-hero-amount">${formatMoney(myCard.activation_fee, myCard.currency)}</div>
            <div class="review-hero-note">One-time fee</div>
        </div>
        <div class="preview-box">
            <div class="preview-row">
                <span>From</span>
                <span class="value">${escapeHtml(instName)}</span>
            </div>
            <div class="preview-row" style="border-bottom:none;">
                <span>Identifier</span>
                <span class="value">${escapeHtml(identifier)}</span>
            </div>
        </div>
        <div class="preview-reassure">This fee activates your card immediately.</div>`;

    pendingExecution = {
        type: 'activate',
        payload: {
            card_suffix: myCard.card_suffix,
            institution: inst,
            asset_type: assetType,
            identifier: identifier,
            pin: pin
        },
        callback: () => executeActivateCard()
    };
    showPreviewModal('Activation preview', bodyHtml, null, 'Pay and activate');
}
    
// ============================================================
// ADD THIS FUNCTION - executeActivateCard
// ============================================================
    
async function executeActivateCard() {
    const payload = pendingExecution.payload;
    if (!payload) {
        showMessage('No activation payload found.', 'error');
        return;
    }
    
    // Remove the redundant closeModal - executePendingAction already handles it
    
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Activate.php', payload);
    
    if (!result.ok) {
        showMessage('Activation didn\'t go through: ' + friendlyApiError(result.error), 'error');
        return;
    }
    
    showMessage('Card activated! 🎉', 'success');
    loadCardView();
}

// executeActivateCard already exists in your file - keep it as-is.
function startSessionPolling(sessionId) {
    stopSessionPolling();
    activeSessionPollTimer = setInterval(async () => {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/ContributionStatus.php?session_id=' + sessionId);
        if (!result.ok) return;
        const session = result.body.data;
        lastKnownSession = session;
        const area = document.getElementById('sessionStatusArea');
        if (area) area.innerHTML = renderSessionStatus(session);
        if (['COMPLETED', 'CANCELLED', 'EXPIRED', 'FAILED'].includes(session.status)) stopSessionPolling();
    }, 3000);
}

function stopSessionPolling() { if (activeSessionPollTimer) { clearInterval(activeSessionPollTimer); activeSessionPollTimer = null; } }

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
    return `<div class="myc-panel"><div class="myc-panel-head"><span class="myc-panel-title">Swap in progress — ${escapeHtml(session.strategy)}</span></div><div class="myc-swap-strip">${avatarsHtml}<span class="myc-swap-strip-label">${contributors.length} hooked, ${contributedCount} have contributed</span></div><div class="comp-bar-track"><div class="comp-bar-seg" style="width:${pct}%;background:var(--accent);"></div></div><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:14px;"><span style="font-weight:700;font-family:var(--font-mono);">${formatMoney(preview.total_covered, session.currency)} of ${formatMoney(preview.total_target, session.currency)}</span><span style="color:var(--text-dim);">${preview.remaining > 0 ? formatMoney(preview.remaining, session.currency) + ' remaining' : 'Fully covered — ready to go! 🎉'}</span></div>${rowsHtml}${manualInput}<div class="cta-row" style="margin-top:14px;"><button class="btn btn-secondary" onclick="cancelSession(${session.session_id})">Cancel</button><button class="btn btn-primary" ${session.can_execute ? '' : 'disabled'} onclick="openSwipePreview(${session.session_id})">${session.can_execute ? 'Ready to swipe' : 'Waiting for full coverage…'}</button></div></div>`;
}

function openCreateSessionModal(cardSuffix) {
    const instOptions = Object.keys(PARTICIPANTS).map(c => `<option value="${c}">${PARTICIPANTS[c]?.name || c}</option>`).join('');
    const bodyHtml = `
        <div class="field-group"><label>Amount to pay</label><input type="number" id="sessTarget" min="0.01" step="0.01" placeholder="0.00"></div>
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
        <div style="font-size:11px;color:var(--text-dim);margin-bottom:12px;">Everyone currently hooked will see this in real time. A small VouchMorph fee applies on top of the amount to pay, the same way it does for Combine Sources — the exact fee shows on the preview before you swipe.</div>
        <div class="cta-row"><button class="btn btn-primary" onclick="submitCreateSession('${cardSuffix}')">Start swap</button></div>`;
    openModal('Start a swap', bodyHtml);
}

async function submitCreateSession(cardSuffix) {
    const target = parseFloat(document.getElementById('sessTarget')?.value);
    const currency = document.getElementById('sessCurrency')?.value.trim();
    const toInst = document.getElementById('sessToInst')?.value;
    const toIdentifier = document.getElementById('sessToIdentifier')?.value.trim();
    const strategy = document.getElementById('sessStrategy')?.value;
    if (!(target > 0)) { showMessage('Enter the amount to pay.', 'warning'); return; }
    if (!toInst || !toIdentifier) { showMessage('Select a destination institution and enter an account/wallet number.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Create.php', { card_suffix: cardSuffix, target_amount: target, currency, strategy, to_institution: toInst, destination_identifier: toIdentifier });
    if (!result.ok) { showMessage('Couldn\'t start that swap: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage('Swap started.', 'success');
    closeModal(); loadCardView();
}

async function submitMyManualAmount(sessionId) {
    const amount = parseFloat(document.getElementById('myManualAmount')?.value);
    if (isNaN(amount) || amount < 0) { showMessage('Enter a valid amount.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Contribute.php', { session_id: sessionId, amount });
    if (!result.ok) { showMessage('Couldn\'t set that contribution: ' + friendlyApiError(result.error), 'error'); return; }
    lastKnownSession = result.body.data;
    const area = document.getElementById('sessionStatusArea');
    if (area) area.innerHTML = renderSessionStatus(result.body.data);
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

function openSwipePreview(sessionId) {
    const session = lastKnownSession;
    const preview = session?.preview || {};
    const contributors = preview.contributors || [];
    const fee = preview.total_fee ?? preview.fee ?? null;
    const rows = contributors.map(c => `
        <div class="preview-row">
            <span>${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)}</span>
            <span class="value">${formatMoney(c.amount, session.currency)}</span>
        </div>
    `).join('');

    const bodyHtml = `
        <div class="review-hero">
            <div class="review-hero-label">Total being paid</div>
            <div class="review-hero-amount">${formatMoney(preview.total_target, session.currency)}</div>
            ${fee !== null ? `<div class="review-hero-note muted">includes ${formatMoney(fee, session.currency)} VouchMorph fee</div>` : `<div class="review-hero-note muted">VouchMorph's fee, if any, applies the same way it does for Combine Sources</div>`}
        </div>
        <div class="preview-box">${rows || '<div style="font-size:12px;color:var(--text-dim);">Breakdown unavailable.</div>'}</div>
        <div class="preview-reassure">This is the last step — swiping executes the swap immediately.</div>`;

    pendingExecution = {
        type: 'swipe',
        payload: { sessionId },
        callback: () => executeSwipe()
    };
    showPreviewModal('Ready to swipe', bodyHtml, null, 'Swipe');
}

async function executeSwipe() {
    const { sessionId } = pendingExecution.payload;
    if (!sessionId) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/execute.php', { session_id: sessionId });
    if (!result.ok) { showMessage('Execution didn\'t go through: ' + friendlyApiError(result.error), 'error'); return; }
    stopSessionPolling();
    showTransactionReport(result.body, lastKnownSession);
}

function showTransactionReport(response, session) {
    const data = response.data || {};
    const preview = session?.preview || {};
    const contributors = preview.contributors || [];
    const rows = contributors.map(c => `
        <div class="preview-row">
            <span>${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)} · ${escapeHtml(c.source_identifier || '')}</span>
            <span class="value">${formatMoney(c.amount, session?.currency)}</span>
        </div>
    `).join('');

    const bodyHtml = `
        <div class="result-box" id="resultBoxRoot">
            <div class="icon">✓</div>
            <div class="result-title">Swipe complete</div>
            <div class="result-sub">Reference: ${escapeHtml(data.reference || response.swap_reference || '—')}</div>
            <div style="font-size:22px;font-weight:600;font-family:var(--font-mono);margin:12px 0;">${formatMoney(preview.total_target ?? data.amount, session?.currency)}</div>
        </div>
        <div class="myc-panel-title" style="margin-bottom:8px;">Paid using</div>
        <div class="preview-box">${rows || '<div style="font-size:12px;color:var(--text-dim);">Breakdown unavailable.</div>'}</div>
        <div class="cta-row" style="margin-top:16px;"><button class="btn btn-primary" onclick="closeModal(); loadCardView();">Done</button></div>`;

    openModal('Transaction report', bodyHtml);
    setTimeout(() => {
        const root = document.getElementById('resultBoxRoot');
        if (root) fireConfetti(root);
    }, 150);
}

// ============================================================
// IDENTITY CLAIMS
// ============================================================
async function submitClaim(swapReference) {
    const pin = document.getElementById('claimPin').value.trim();
    const destType = document.getElementById('claimDestType').value;
    if (!pin) { showMessage('Enter your claim PIN.', 'warning'); return; }

    const payload = { swap_reference: swapReference, pin, destination_type: destType };
    let destInst = null, destIdentifier = null;

    if (destType === 'DEPOSIT') {
        destInst = document.getElementById('claimDestInst').value;
        destIdentifier = document.getElementById('claimDestIdentifier').value.trim();
        payload.destination_institution = destInst;
        payload.destination_identifier = destIdentifier;
        if (!destInst || !destIdentifier) { showMessage('Select a destination institution and enter an account/wallet number.', 'warning'); return; }
    } else if (destType === 'HOOK') {
        if (!claimHookCardSuffix) { showMessage('Choose which card to hook this to first.', 'warning'); return; }
        payload.card_suffix = claimHookCardSuffix;
    }

    const claim = pendingClaims.find(c => c.swap_reference === swapReference);
    const amount = claim?.amount || 0;
    const currency = claim?.currency || 'BWP';
    const sourceInst = claim?.source_institution || 'Unknown';

    let destLabel = '';
    if (destType === 'HOOK') {
        destLabel = `Hook to ${claimHookCardLabel || '•••• ' + claimHookCardSuffix}`;
    } else if (destType === 'DEPOSIT') {
        destLabel = `Deposit to ${PARTICIPANTS[destInst]?.name || destInst} — ${destIdentifier}`;
    } else {
        destLabel = 'Cashout';
    }

    const bodyHtml = `
        <div class="review-hero">
            <div class="review-hero-label">You're claiming</div>
            <div class="review-hero-amount">${formatMoney(amount, currency)}</div>
            <div class="review-hero-note">From ${escapeHtml(sourceInst)}</div>
        </div>
        <div class="preview-box">
            <div class="preview-row">
                <span>Destination</span>
                <span class="value">${escapeHtml(destLabel)}</span>
            </div>
        </div>
        <div class="preview-reassure">This is final — confirm to complete the claim.</div>`;

    pendingExecution = {
        type: 'claim',
        payload: payload,
        callback: () => executeClaim()
    };
    showPreviewModal('Claim preview', bodyHtml, null, 'Confirm claim');
}

async function executeClaim() {
    const payload = pendingExecution.payload;
    if (!payload) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/claim_identity.php', payload);
    if (!result.ok) { showMessage('That claim didn\'t go through: ' + friendlyApiError(result.error), 'error'); return; }
    checkPendingClaims();

    if (payload.destination_type === 'HOOK') {
        showMessage(`Claimed and hooked to ${claimHookCardLabel || 'the card'}. 🎉`, 'success');
        claimHookCardSuffix = null; claimHookCardLabel = null; pendingClaimIdxForHook = null;
        loadToolboxView();
        return;
    }

    const bodyHtml = `
        <div class="result-box" id="resultBoxRoot">
            <div class="icon">✓</div>
            <div class="result-title">Claim complete! 🎉</div>
            <div class="result-sub">The money is now yours.</div>
        </div>
        <div class="cta-row" style="margin-top:16px;">
            <button class="btn btn-primary" onclick="closeModal(); loadToolboxView();">Done</button>
        </div>`;
    openModal('Claim complete', bodyHtml);
    setTimeout(() => fireConfetti(document.getElementById('resultBoxRoot')), 150);
}

// ============================================================
// ACTIVITY VIEW
// ============================================================
async function loadActivityView() {
    const body = document.getElementById('activityViewBody');
    if (!body) return;
    body.innerHTML = `<div style="text-align:center;padding:40px 0;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading swaps...</div>`;
    if (!CONFIG.USER_ID) { body.innerHTML = `<div style="text-align:center;padding:30px;color:var(--danger);"><div style="font-weight:700;">Could not identify your account</div><div style="font-size:12px;color:var(--text-muted);margin-top:8px;">Your session doesn't have a user ID attached. Try logging out and back in.</div></div>`; return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/history.php', { user_id: CONFIG.USER_ID, limit: 50 });
    if (!result.ok) { body.innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);">Couldn't load swap history: ${escapeHtml(friendlyApiError(result.error))}</div>`; return; }
    renderActivityBody(result.body);
}

function renderActivityBody(data) {
    const swaps = data.data || data.swaps || [];
    const body = document.getElementById('activityViewBody');
    if (!body) return;
    if (swaps.length === 0) { body.innerHTML = `<div style="text-align:center;padding:30px;color:var(--text-muted);"><div style="font-weight:700;">No swaps yet</div><div style="font-size:12px;margin-top:8px;">Once you make your first swap, it'll show up here.</div></div>`; return; }
    let html = `<div class="field-group" style="margin-bottom:16px;"><input id="activitySearchInput" placeholder="Search by type, institution, or reference…" oninput="filterActivity(this.value)" style="font-size:15px;padding:14px 16px;"></div>`;
    html += `<div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;" id="activityCount">Showing ${swaps.length} swap(s)</div>`;
    html += `<div id="activityRowsHolder">`;
    swaps.forEach((swap) => {
        const statusColor = swap.status === 'completed' || swap.status === 'success' ? 'var(--success)' : swap.status === 'pending' ? 'var(--warning)' : 'var(--danger)';
        const code = swap.voucher_number || null, pin = swap.atm_pin || null, claimPin = swap.claim_pin || null;
        const hasCode = !!(code || pin || claimPin);
        const codeInlineHtml = hasCode ? `<div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--border);display:flex;gap:16px;flex-wrap:wrap;">${code ? `<div><div style="font-size:10px;color:var(--text-dim);">Code</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(code)}</div></div>` : ''}${pin ? `<div><div style="font-size:10px;color:var(--text-dim);">PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(pin)}</div></div>` : ''}${claimPin ? `<div><div style="font-size:10px;color:var(--text-dim);">Claim PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(claimPin)}</div></div>` : ''}</div>` : '';
        const searchBlob = escapeHtml([swap.swap_type, swap.reference, swap.swap_reference, swap.source_institution, swap.destination_institution, swap.status].filter(Boolean).join(' '));
        html += `<div class="myc-panel" style="cursor:pointer;margin-bottom:8px;" data-search="${searchBlob.toLowerCase()}" onclick="viewSwapDetail('${swap.reference || swap.swap_reference || 'N/A'}')"><div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;"><div><div style="font-weight:700;">${swap.swap_type || 'SWAP'} <span style="font-size:11px;color:var(--text-muted);">${swap.reference || swap.swap_reference || ''}</span></div><div style="font-size:12px;color:var(--text-muted);">${swap.source_institution || 'Unknown'} → ${swap.destination_institution || 'Unknown'}</div></div><div style="text-align:right;"><div style="font-weight:700;color:var(--accent);font-family:var(--font-mono);">${formatMoney(swap.amount, swap.currency)}</div><div style="font-size:11px;color:${statusColor};">${swap.status || 'unknown'}</div></div></div>${codeInlineHtml}</div>`;
    });
    html += `</div>`;
    body.innerHTML = html;
}

function filterActivity(query) {
    const q = query.trim().toLowerCase();
    const rows = document.querySelectorAll('#activityRowsHolder [data-search]');
    let visibleCount = 0;
    rows.forEach(row => {
        const matches = !q || row.dataset.search.includes(q);
        row.style.display = matches ? '' : 'none';
        if (matches) visibleCount++;
    });
    const countEl = document.getElementById('activityCount');
    if (countEl) countEl.textContent = q ? `${visibleCount} match${visibleCount === 1 ? '' : 'es'}` : `Showing ${rows.length} swap(s)`;
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

// ============================================================
// TOOLBOX VIEW
// ============================================================
async function loadToolboxView() {
    const body = document.getElementById('toolboxViewBody');
    if (!body) return;
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
                    ${isActive ? `<button class="unhook-link" style="color:var(--text-muted);" onclick="promptHookThisSource({instName: '${escapeHtml(PARTICIPANTS[source.institution]?.name || source.institution)}', institution: '${source.institution}', assetType: '${source.asset_type}', identifier: '${escapeHtml(source.identifier || source.source_identifier || '')}'})">Hook to card &rsaquo;</button>` : ''}
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
            { icon: '🧾', label: 'Request payment', action: 'openRequestPaymentModal()' },
            { icon: '📷', label: 'Scan to pay', action: 'openScanQrModal()' },
        ]},
        { title: 'Identity', open: claimCount > 0, rows: [
            { icon: '📥', label: 'Finalize identity swap', badge: claimCount > 0 ? claimCount : null, action: isAgent ? 'openAgentFinalizeIdentityModal()' : 'openFinalizeIdentityModal()' },
            { icon: '🪪', label: 'Register identity', action: 'openAddIdentityModal()' },
        ]},
    ];
if (isAgent) groups.push({ title: 'Agent', open: false, rows: [
    { icon: '🧰', label: 'Agent tools', action: 'openAgentToolsModal()' },
    { icon: '🏢', label: 'Agent destinations', action: 'openAgentModal()' },
    { icon: '💳', label: "Charge a customer's card", action: 'openAgentCardPaymentModal()' },   // new
] });    groups.push({ title: 'Account', open: false, rows: [ { icon: '👤', label: 'My profile', action: 'openProfileModal()' }, { icon: '❓', label: 'Help', action: 'openHelpModal()' }, { icon: '📄', label: 'Terms and conditions', action: 'openTermsModal()' } ] });

    const progressHtml = doneCount < steps.length ? `<div style="background:var(--surface-muted);border:1px solid var(--border);padding:14px 16px;margin-bottom:20px;"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-dim);margin-bottom:8px;">Getting set up — ${doneCount}/${steps.length}</div>${steps.map(s => `<div style="font-size:13px;color:${s.done ? 'var(--success)' : 'var(--text-muted)'};padding:3px 0;">${s.done ? '✓' : '○'} ${s.label}</div>`).join('')}</div>` : '';

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

let agentCardPaymentState = { amount: null, currency: 'BWP', cardSuffix: null, displayName: null, destinationAccountId: null };

function openAgentCardPaymentModal() {
    if (!agentStatus.approved_destinations || agentStatus.approved_destinations.length === 0) {
        openModal('Charge a card', '<div style="color:var(--danger);">You need an approved agent destination account first — register one from Toolbox → Agent destinations.</div>');
        return;
    }
    agentCardPaymentState = { amount: null, currency: myCard?.currency || 'BWP', cardSuffix: null, displayName: null, destinationAccountId: null };
    const bodyHtml = `
        <div class="field-group">
            <label>Amount to charge</label>
            <input type="number" id="agentChargeAmount" min="0.01" step="0.01" placeholder="0.00"
                   oninput="agentCardPaymentState.amount = parseFloat(this.value) || null;">
        </div>
        <div class="field-group">
            <label>Deposit into</label>
            <select id="agentChargeDestSelect">
                ${agentStatus.approved_destinations.map(d => `<option value="${d.id}">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)} — ${escapeHtml(d.identifier)}</option>`).join('')}
            </select>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="proceedToChargeCard()">Continue: identify card →</button>
        </div>`;
    openModal("Charge a customer's card", bodyHtml);
}

function proceedToChargeCard() {
    if (!(agentCardPaymentState.amount > 0)) { showMessage('Enter the amount to charge.', 'warning'); return; }
    agentCardPaymentState.destinationAccountId = document.getElementById('agentChargeDestSelect')?.value;
    const bodyHtml = `
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">Enter the customer's card number, or scan their VouchMorph Card QR.</div>
        <div class="field-group"><label>Card number or suffix</label><input id="agentChargeCardManual" placeholder="e.g. last 4 digits or full number"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="submitAgentChargeCardManual()">Continue</button></div>
        <div style="text-align:center;margin:16px 0;font-size:11px;color:var(--text-dim);">or</div>
        <button class="btn btn-secondary" onclick="openScanModal('agent_charge')">Scan their card instead</button>`;
    openModal('Identify the card', bodyHtml);
}

async function submitAgentChargeCardManual() {
    const raw = document.getElementById('agentChargeCardManual')?.value.trim();
    if (!raw) { showMessage('Enter a card number.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/LookupBySuffix.php', { card_suffix: raw });
    if (!result.ok) { showMessage("Couldn't find that card: " + friendlyApiError(result.error), 'error'); return; }
    const { card_suffix, display_name } = result.body.data;
    confirmAgentChargeCard(card_suffix, display_name);
}

async function confirmAgentChargeCard(cardSuffix, displayName) {
    agentCardPaymentState.cardSuffix = cardSuffix;
    agentCardPaymentState.displayName = displayName;

    const statusResult = await callApi(CONFIG.API_BASE + '/api/v1/agent/card_charge_status.php', {
        card_suffix: cardSuffix,
        destination_account_id: agentCardPaymentState.destinationAccountId,
    });

    if (!statusResult.ok) {
        showMessage("Couldn't check that card: " + friendlyApiError(statusResult.error), 'error');
        return;
    }

    const status = statusResult.body.data;

    if (status.code === 'SELF_CARD') {
        openModal("Can't charge your own card", `
            <div style="font-size:13px;color:var(--text);line-height:1.6;">Agents can't charge their own VouchMorph Card through this flow. Use "Start a swap" from your Card view instead.</div>
            <div class="cta-row" style="margin-top:16px;"><button class="btn btn-primary" onclick="closeModal()">OK</button></div>`);
        return;
    }

    if (status.code === 'NO_HOOK') {
        openModal('Nothing hooked to this card', `
            <div style="font-size:13px;color:var(--text);line-height:1.6;">This card doesn't have a source hooked yet, so there's nothing to charge. Ask the customer to hook a source from their own VouchMorph app (Card → Hook a source), then scan again.</div>
            <div class="cta-row" style="margin-top:16px;"><button class="btn btn-primary" onclick="closeModal()">OK</button></div>`);
        return;
    }

    if (status.code === 'ONLY_SOURCE_IS_DESTINATION') {
        openModal("Can't use that account", `
            <div style="font-size:13px;color:var(--text);line-height:1.6;">The only source hooked to this card is the exact account you're depositing into — that would just send money to itself. Pick a different destination, or ask the customer to hook a different source.</div>
            <div class="cta-row" style="margin-top:16px;"><button class="btn btn-primary" onclick="closeModal()">OK</button></div>`);
        return;
    }

    if (status.available < agentCardPaymentState.amount) {
        showMessage(`This card only has ${formatMoney(status.available, status.currency)} hooked — lower the amount or ask the customer to hook more.`, 'warning');
        return;
    }

    const bodyHtml = `
        <div class="review-hero">
            <div class="review-hero-label">Charge ${escapeHtml(displayName)}'s card</div>
            <div class="review-hero-amount">${formatMoney(agentCardPaymentState.amount, agentCardPaymentState.currency)}</div>
        </div>
        <div style="background:var(--accent-soft);border-left:3px solid var(--accent);padding:12px 14px;font-size:12px;margin:14px 0;color:var(--primary);">
            To finalize, hand the device to the customer — they enter their own VouchMorph PIN to approve this. An agent can never finalize a charge without it.
        </div>
        <div class="field-group">
            <label>Customer's VouchMorph PIN</label>
            <input type="password" id="agentChargeOwnerPin" inputmode="numeric" maxlength="6" placeholder="Customer enters this">
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="executeAgentChargeCard()">Finalize payment</button>
        </div>`;
    openModal('Confirm charge', bodyHtml);
}
async function executeAgentChargeCard() {
    const pin = document.getElementById('agentChargeOwnerPin')?.value.trim();
    if (!pin) { showMessage("Enter the customer's PIN to finalize.", 'warning'); return; }
    const destOpt = agentStatus.approved_destinations.find(d => String(d.id) === String(agentCardPaymentState.destinationAccountId));

    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/charge_card.php', {
        card_suffix: agentCardPaymentState.cardSuffix,
        amount: agentCardPaymentState.amount,
        currency: agentCardPaymentState.currency,
        destination_account_id: destOpt?.id,
        card_owner_pin: pin,
    });

    if (!result.ok) { showMessage("Payment didn't go through: " + friendlyApiError(result.error), 'error'); return; }

    const data = result.body.data || {};
    const bodyHtml = `
        <div class="result-box" id="resultBoxRoot">
            <div class="icon">✓</div>
            <div class="result-title">Payment received</div>
            <div class="result-sub">Reference: ${escapeHtml(data.reference || '—')}</div>
            <div style="font-size:22px;font-weight:600;font-family:var(--font-mono);margin-top:12px;">${formatMoney(agentCardPaymentState.amount, agentCardPaymentState.currency)}</div>
        </div>
        <div class="cta-row" style="margin-top:16px;"><button class="btn btn-primary" onclick="closeModal();">Done</button></div>`;
    openModal('Payment complete', bodyHtml);
    setTimeout(() => fireConfetti(document.getElementById('resultBoxRoot')), 150);
}

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

// ============================================================
// HELP MODALS
// ============================================================
function openHowItWorks(key) {
    const info = HOW_IT_WORKS[key];
    if (!info) return;
    openModal(info.title, info.body);
}

function openHelpModal() {
    openModal('Help', `<div style="font-size:13px;line-height:1.7;color:var(--text);">
        <p style="font-weight:700;margin-bottom:6px;">Swapping money</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>From the hub, tap Swap.</li><li>Follow the 4 steps: amount → source → destination → confirm.</li></ol>
        <p style="font-weight:700;margin-bottom:6px;">Your VouchMorph Card</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>From the hub, tap Card. Every account gets one automatically.</li><li>It starts inactive — activate it once with a small one-time fee from any linked source.</li><li>Hook one or many sources — from the Card view ("Hook a source"), or from Toolbox.</li></ol>
        <p style="font-weight:700;margin-bottom:6px;">Claiming money sent to you</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>Toolbox → Finalize identity swap.</li><li>Enter your claim PIN and choose how to receive it.</li></ol>
    </div>`);
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

// ============================================================
// STUB FUNCTIONS (kept for compatibility) - REPLACE THESE
// ============================================================
function restoreSwapSourceUI() { initWizard(); }
function setSwapSourceMode(mode) {}
function toggleSourcePanelInline(cat) {}
function populateInstitutionsForAsset(assetType) {}
function selectFromInst(code) {}
function updateFromField(name, value) {}
function renderDynamicFields(containerId, assetType, prefix, onChange, includePin) {}
function selectToInst(code) {}
function selectToAsset(type) {}
function updateToField(name, value) {}
function setDeliveryMethod(method) {}
function setSwapType(type) {
    state.swapType = type;
    const depositBtn = document.getElementById('depositToggleBtn');
    const cashoutBtn = document.getElementById('cashoutToggleBtn');
    if (depositBtn) depositBtn.classList.toggle('selected', type === 'DEPOSIT');
    if (cashoutBtn) cashoutBtn.classList.toggle('selected', type === 'CASHOUT');
    const cashoutFields = document.getElementById('cashoutFields');
    if (cashoutFields) cashoutFields.style.display = type === 'CASHOUT' ? 'block' : 'none';
}
function updateIdentityHelp() {}
function setSwapDestCategory(cat) {}
function openDestinationModal() {}
function confirmDestinationSelection() {}
function openIdentitySendModal() {}
function confirmIdentitySelection() {}
function getSwapReadiness() { return { ready: true, reasons: [], missingFields: [] }; }
function destinationReadiness() { return []; }
function sourceReadyForCurrentMode() { return true; }
function amountWithinLimits(instCode, amount) {
    const limits = PARTICIPANTS[instCode]?.limits;
    if (!limits) return true;
    return amount >= limits.min_amount && amount <= limits.max_amount;
}
function refreshUI() {}
function updateSelectionChips() {}
function updateCurrencyDisplay() {}
function getInstitutionCurrency(instCode) { if (!instCode) return null; return PARTICIPANTS[instCode]?.limits?.currency || null; }
function buildPayload() {}
function resetSwapState() { resetWizard(); }
function renderSavedSourceChips() {}
function walletEligibleSources() { return []; }
function toggleSavedSourceDropdown() {}
function closeSavedSourceDropdown() {}
function selectSavedSource(sourceId) {}
function clearSourceSelection() {}
function useSourceForSwap(sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    if (!source) { showMessage('Source not found.', 'error'); return; }
    goView('swap');
    setTimeout(() => {
        let tileType = 'WALLET';
        const assetType = String(source.asset_type).toUpperCase();
        if (assetType.includes('CARD')) tileType = 'CARD';
        else if (assetType.includes('VOUCHER')) tileType = 'VOUCHER';
        const tile = document.querySelector(`.source-option[data-source="${tileType}"]`);
        if (tile) {
            tile.click();
            setTimeout(() => {
                const savedSourceRows = document.querySelectorAll('.saved-source-row');
                savedSourceRows.forEach(row => {
                    if (row.textContent.includes(source.identifier || '')) {
                        row.click();
                    }
                });
            }, 200);
        }
    }, 100);
}
function hookSelectedSourceToCard() {}
// ============================================================
// FINALIZE IDENTITY SWAP (self-service)
// ============================================================
function openFinalizeIdentityModal() {
    openModal('Finalize identity swap', renderFinalizeIdentityModal());
}

function renderFinalizeIdentityModal() {
    const claimsHtml = pendingClaims.length === 0
        ? `<div style="font-size:12px;color:var(--text-dim);margin-bottom:16px;">No identity money is currently waiting for you.</div>`
        : `<div style="margin-bottom:16px;">${pendingClaims.map((c, i) => {
            const pinLabel = c.claim_type === 'otp_pin' ? 'the OTP PIN sent by SMS' : 'your transaction PIN';
            const claimPin = c.claim_pin || null;
            const code = c.voucher_number || null;
            const pin = c.atm_pin || null;
            const hasCode = !!(code || pin || claimPin);
            const codeInlineHtml = hasCode ? `
                <div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--border);display:flex;gap:16px;flex-wrap:wrap;">
                    ${code ? `<div><div style="font-size:10px;color:var(--text-dim);">Code</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(code)}</div></div>` : ''}
                    ${pin ? `<div><div style="font-size:10px;color:var(--text-dim);">PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(pin)}</div></div>` : ''}
                    ${claimPin ? `<div><div style="font-size:10px;color:var(--text-dim);">Claim PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(claimPin)}</div></div>` : ''}
                </div>` : '';
            return `
            <div class="myc-panel" style="margin-bottom:8px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                    <div style="flex:1;">
                        <div style="font-weight:700;font-size:15px;color:var(--accent);font-family:var(--font-mono);">${formatMoney(c.amount, c.currency)}</div>
                        <div style="font-size:12px;color:var(--text-muted);">From ${escapeHtml(c.source_institution || 'Unknown')}</div>
                        <div style="font-size:11px;color:var(--text-dim);">Needs ${pinLabel} · Expires ${c.hold_expires_at ? new Date(c.hold_expires_at).toLocaleString() : 'soon'}</div>
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="openClaimForm(${i})" style="flex-shrink:0;">Finalize</button>
                </div>
                ${codeInlineHtml}
            </div>`;
        }).join('')}</div>`;

    return `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Money sent to your national ID, phone, or email shows up here.</div>
        ${claimsHtml}
        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:4px;">
            <div class="field-label" style="margin-bottom:6px;">Need to claim an identity swap?</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">If you received a swap notification, enter the claim PIN below to complete the transaction.</div>
            <div class="field-group"><label>Swap reference</label><input id="directClaimRef" placeholder="e.g. SWAP_123456789"></div>
            <div class="field-group"><label>Claim PIN</label><input type="password" id="directClaimPin" placeholder="Enter the PIN you received" maxlength="6"></div>
            <div class="cta-row"><button class="btn btn-primary" onclick="submitDirectClaim()">Claim swap</button></div>
        </div>
        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;font-size:11px;color:var(--text-dim);">
            <span class="quick-link muted" onclick="closeModal();openAddIdentityModal();">Need to register a new identity instead? Click here &rarr;</span>
        </div>`;
}

function openClaimForm(idx) {
    const claim = pendingClaims[idx];
    if (!claim) return;
    const pinHint = claim.claim_type === 'otp_pin' ? 'Use the one-time PIN sent by SMS when this money was sent.' : 'Use your VouchMorph transaction PIN.';
    const body = `
        <div style="background:var(--accent-soft);padding:14px;margin-bottom:14px;">
            <div style="font-size:20px;font-weight:600;color:var(--accent);font-family:var(--font-mono);">${formatMoney(claim.amount, claim.currency)}</div>
            <div style="font-size:12px;color:var(--text-muted);">From ${escapeHtml(claim.source_institution || 'Unknown')}</div>
        </div>
        <div class="field-group"><label>Claim PIN</label><input type="password" id="claimPin" inputmode="numeric" maxlength="6" placeholder="&bull;&bull;&bull;&bull;"><div class="help">${pinHint}</div></div>
        <div class="field-group"><label>Receive as</label><select id="claimDestType" onchange="toggleClaimDestFields(this.value)"><option value="CASHOUT">Cashout (ATM / Agent code)</option><option value="DEPOSIT">Deposit to an account/wallet</option></select></div>
        <div id="claimDepositFields" style="display:none;">
            <div class="field-group"><label>Destination institution</label><select id="claimDestInst"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div>
            <div class="field-group"><label>Account / wallet number</label><input id="claimDestIdentifier" placeholder="Account number or phone"></div>
        </div>
        <div class="cta-row"><button class="btn btn-secondary" onclick="openFinalizeIdentityModal()">Back</button><button class="btn btn-primary" onclick="submitClaim('${claim.swap_reference}')">Finalize</button></div>`;
    openModal('Finalize identity swap', body);
}

function toggleClaimDestFields(type) {
    const el = document.getElementById('claimDepositFields');
    if (el) el.style.display = type === 'DEPOSIT' ? 'block' : 'none';
}

async function submitDirectClaim() {
    const swapRef = document.getElementById('directClaimRef').value.trim();
    const pin = document.getElementById('directClaimPin').value.trim();
    if (!swapRef) { showMessage('Please enter the swap reference.', 'warning'); return; }
    if (!pin) { showMessage('Please enter your claim PIN.', 'warning'); return; }
    if (!/^\d{4,6}$/.test(pin)) { showMessage('PIN must be 4-6 digits.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/claim_identity.php', { swap_reference: swapRef, pin });
    if (!result.ok) { showMessage('Claim failed: ' + friendlyApiError(result.error), 'error'); return; }
    closeModal();
    showMessage('Funds claimed successfully! 🎉', 'success');
    checkPendingClaims();
}

// ============================================================
// AGENT: Finalize identity swap (search-based)
// ============================================================
function openAgentFinalizeIdentityModal() {
    openModal('Finalize identity swap', renderAgentFinalizeIdentitySearch());
}

function renderAgentFinalizeIdentitySearch() {
    return `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Search for a client's pending identity payment. You'll need to physically verify their document and have them tell you the OTP PIN texted to them — never their personal VouchMorph transaction PIN — before you can finalize.</div>
        <div class="field-group"><label>Document type</label><select id="agentSearchType"><option value="national_id">National ID</option><option value="birth_certificate">Birth Certificate</option><option value="voter_id">Voter ID</option></select></div>
        <div class="field-group"><label>Document number</label><input id="agentSearchValue" placeholder="Enter the client's ID number"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="searchAgentClaim()">Search</button></div>
        <div id="agentSearchResults" style="margin-top:16px;"></div>
        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:20px;">
            <div class="field-label" style="margin-bottom:6px;">Not what the client needs?</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">If the client wants this identity permanently registered to their VouchMorph account, you can do that here instead.</div>
            <span class="quick-link muted" onclick="closeModal();openAddIdentityModal();">Register identity to client's account &rarr;</span>
        </div>`;
}

function openAgentToolsModal() {
    openModal('Agent tools', renderAgentToolsSearch());
}

function renderAgentToolsSearch() {
    return `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Search for a client's pending identity payment.</div>
        <div class="field-group"><label>Document type</label><select id="agentSearchType"><option value="national_id">National ID</option><option value="birth_certificate">Birth Certificate</option><option value="voter_id">Voter ID</option></select></div>
        <div class="field-group"><label>Document number</label><input id="agentSearchValue" placeholder="Enter the client's ID number"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="searchAgentClaim()">Search</button></div>
        <div id="agentSearchResults" style="margin-top:16px;"></div>`;
}

async function searchAgentClaim() {
    const identityType = document.getElementById('agentSearchType').value;
    const identityValue = document.getElementById('agentSearchValue').value.trim();
    const resultsBox = document.getElementById('agentSearchResults');
    if (!identityValue) { showMessage('Enter the document number to search.', 'warning'); return; }
    resultsBox.innerHTML = '<div style="text-align:center;padding:16px;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Searching...</div>';
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/search_claim.php', { identity_type: identityType, identity_value: identityValue });
    if (!result.ok) { resultsBox.innerHTML = `<div style="color:var(--danger);">${escapeHtml(friendlyApiError(result.error))}</div>`; return; }
    const data = result.body.data;
    if (!data) { resultsBox.innerHTML = '<div style="font-size:12px;color:var(--text-dim);">No pending payment found for this identity.</div>'; return; }
    agentSearchData = data;

    function renderBalanceRow(b) {
        const currency = escapeHtml(b.currency);
        const totalAmount = parseFloat(b.total_amount).toFixed(2);
        const swapCount = parseInt(b.swap_count);
        const identityTypeEscaped = escapeHtml(b.identity_type || data.identity_type);
        const identityValueEscaped = escapeHtml(b.identity_value || data.identity_value);
        return `
            <div class="myc-panel" style="margin-bottom:8px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:700;font-size:16px;color:var(--accent);font-family:var(--font-mono);">${formatMoney(totalAmount, currency)}</div>
                        <div style="font-size:12px;color:var(--text-muted);">From ${swapCount} different source(s)</div>
                        <div style="font-size:11px;color:var(--text-dim);">Expires ${b.earliest_expires_at ? new Date(b.earliest_expires_at).toLocaleString() : 'soon'}</div>
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="openAgentFinalizeFormAggregated('${identityTypeEscaped}', '${identityValueEscaped}', '${currency}', ${totalAmount}, ${swapCount})">Claim</button>
                </div>
            </div>`;
    }

    if (data.multi_currency) {
        resultsBox.innerHTML = '<div style="margin-bottom:12px;"><strong>Multiple currencies found for this identity:</strong></div>' + data.balances.map(renderBalanceRow).join('');
    } else {
        resultsBox.innerHTML = renderBalanceRow(data);
    }
}

function openAgentFinalizeFormAggregated(identityType, identityValue, currency, totalAmount, swapCount) {
    const data = agentSearchData;
    if (!data) { showMessage('Search data not found. Please search again.', 'error'); return; }
    if (!agentStatus.approved_destinations || agentStatus.approved_destinations.length === 0) {
        openModal('Agent tools', '<div style="color:var(--danger);">You have no approved agent destination account. Register one first from Toolbox → Agent destinations.</div>');
        return;
    }
    const destOptions = agentStatus.approved_destinations.map(d => `<option value="${d.id}">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)} - ${escapeHtml(d.identifier)}</option>`).join('');
    const searchTypeLabel = IDENTITY_TYPE_LABELS[document.getElementById('agentSearchType')?.value] || 'document';
    const body = `
        <div style="background:var(--accent-soft);padding:14px;margin-bottom:14px;">
            <div style="font-size:12px;color:var(--text-muted);">Client's total balance</div>
            <div style="font-size:22px;font-weight:600;color:var(--accent);font-family:var(--font-mono);">${formatMoney(totalAmount, currency)}</div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">Aggregated from ${swapCount} different source(s). The full amount deposits into your account. Whatever the client doesn't take as cash today is instantly sent back to their identity as a new claim.</div>
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
            <input type="checkbox" id="agentDocVerified" style="width:auto;"> I have physically verified the client's ${searchTypeLabel}
        </label></div>
        <div class="field-group"><label>Client's OTP PIN</label>
            <input type="password" id="agentClaimPin" inputmode="numeric" maxlength="6" placeholder="Ask the client for the PIN texted to them">
            <div class="help">This is the OTP PIN sent by SMS — never a personal transaction PIN.</div>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="openAgentToolsModal()">Back to search</button>
            <button class="btn btn-primary" onclick="submitAgentFinalizeAggregated('${escapeHtml(identityType)}', '${escapeHtml(identityValue)}', ${totalAmount}, '${currency}')">Process</button>
        </div>`;
    openModal('Confirm deposit', body);
}

async function submitAgentFinalizeAggregated(identityType, identityValue, totalAmount, currency) {
    const destinationAccountId = document.getElementById('agentDestSelect').value;
    const docVerified = document.getElementById('agentDocVerified').checked;
    const pin = document.getElementById('agentClaimPin').value.trim();
    const cashNowAmount = parseFloat(document.getElementById('cashNowAmount').value);
    if (!docVerified) { showMessage("You must confirm you verified the client's physical document.", 'warning'); return; }
    if (!pin) { showMessage("Enter the client's claim PIN.", 'warning'); return; }
    if (isNaN(cashNowAmount) || cashNowAmount < 0 || cashNowAmount > totalAmount) { showMessage(`Cash amount must be between 0 and ${totalAmount}.`, 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/finalize_claim.php', {
        identity_type: identityType,
        identity_value: identityValue,
        pin,
        identity_document_verified: true,
        destination_account_id: parseInt(destinationAccountId, 10),
        cash_now_amount: cashNowAmount
    });
    if (!result.ok) { showMessage('Failed: ' + friendlyApiError(result.error), 'error'); return; }
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
    if (netDeposited > 0) { msg += `Deposited ${formatMoney(netDeposited, currency)} into your account`; if (totalFees > 0) msg += ` (fee: ${formatMoney(totalFees, currency)})`; msg += '. '; }
    if (cashGiven > 0) msg += `Gave client ${formatMoney(cashGiven, currency)} in cash. `; else msg += `No cash given now. `;
    if (remainder > 0) msg += `The remaining ${formatMoney(remainder, currency)} was sent back to their identity — a new PIN was texted to them. `;
    if (successfulSwaps > 1) { msg += `(Processed ${successfulSwaps} source(s)`; if (failedSwaps > 0) msg += `, ${failedSwaps} failed`; msg += `)`; }
    else if (failedSwaps > 0) msg += `(${failedSwaps} source(s) failed)`;
    if (data.status === 'partial_success') msg += ' Partial success — some sources failed.';
    showMessage(msg, 'success');
    agentSearchData = null;
}

// ============================================================
// AGENT: Destination registration (the "apply to become an agent" flow)
// ============================================================
const AGENT_ELIGIBLE_ASSET_TYPES = ['ACCOUNT', 'WALLET', 'BANK-WALLET', 'CARD'];

async function openAgentModal() {
    openModal('Agent account', '<div style="text-align:center;padding:20px;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading...</div>');
    await getCurrentUserRole();
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="color:var(--danger);">Couldn't load agent status: ${escapeHtml(friendlyApiError(result.error))}</div>`; return; }
    agentStatus = result.body.data;
    agentStatus.is_agent = SessionUser.is_agent;
    document.getElementById('modalBody').innerHTML = renderAgentModal();
}

function renderAgentModal() {
    const activeDestinations = (agentStatus.all_destinations || []).filter(d => d.status !== 'cancelled' && !d.deleted_at);
    const statusRows = activeDestinations.length ? activeDestinations.map(d => {
        const isPending = d.status === 'pending_confirmation';
        const isRejected = d.status === 'rejected';
        const canCancel = isPending || isRejected;
        const badge = d.status === 'active'
            ? '<span style="background:rgba(31,138,84,0.1);color:var(--success);padding:2px 8px;font-size:10px;font-weight:700;">Active</span>'
            : isPending
                ? '<span style="background:#fef3c7;color:#8a5a0b;padding:2px 8px;font-size:10px;font-weight:700;">Pending approval</span>'
                : `<span style="background:#fbeceb;color:var(--danger);padding:2px 8px;font-size:10px;font-weight:700;">${escapeHtml(d.status || 'Unknown')}</span>`;
        return `<div class="myc-panel" style="margin-bottom:8px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                <div><div style="font-weight:700;">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)}</div><div style="font-size:12px;color:var(--text-muted);">${escapeHtml(d.identifier)} · ${escapeHtml(d.account_type || d.asset_type)}</div></div>
                <div style="display:flex;align-items:center;gap:8px;">${badge}${canCancel ? `<button class="btn-danger-outline" onclick="cancelAgentDestination(${d.id})">Cancel</button>` : ''}</div>
            </div>${d.status === 'rejected' && d.rejection_reason ? `<div style="font-size:12px;color:var(--danger);margin-top:6px;">Reason: ${escapeHtml(d.rejection_reason)}</div>` : ''}
        </div>`;
    }).join('') : '<div style="font-size:12px;color:var(--text-dim);">You have no agent destination accounts registered yet.</div>';

    return `
        <div style="margin-bottom:16px;"><div class="field-label" style="margin-bottom:8px;">Your agent accounts</div>${statusRows}</div>
        <div style="border-top:1px solid var(--border);padding-top:16px;">
            <div class="field-label" style="margin-bottom:8px;">Register a new agent destination</div>
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Register a business/agent account you hold at a participating institution. Only business or agent-designated accounts are eligible. Approval required before activation.</div>
            <div class="field-group"><label>Institution</label><select id="agentInst" onchange="onAgentInstChange(this.value)"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div>
            <div class="field-group" id="agentAssetTypeGroup" style="display:none;"><label>Account type</label><select id="agentAssetType"></select><div class="help">Only Account, Wallet, or Card can be used — vouchers stay manual, never registered as a destination.</div></div>
            <div class="field-group"><label>Account / wallet / card number</label><input id="agentIdentifier" placeholder="Your business account number"></div>
            <div class="field-group"><label>Account name (optional)</label><input id="agentAccountName" placeholder="e.g. Thabo's General Store"></div>
            <div class="cta-row"><button class="btn btn-primary" onclick="submitAgentDestination()">Register and verify</button></div>
        </div>`;
}

function onAgentInstChange(code) {
    const group = document.getElementById('agentAssetTypeGroup');
    const sel = document.getElementById('agentAssetType');
    if (!code) { group.style.display = 'none'; sel.innerHTML = ''; return; }
    const inst = PARTICIPANTS[code];
    const allTypes = inst?.asset_types || [];
    const eligible = allTypes.filter(t => AGENT_ELIGIBLE_ASSET_TYPES.includes(String(t).toUpperCase()));
    if (eligible.length === 0) { group.style.display = 'block'; sel.innerHTML = '<option value="">No eligible account types at this institution</option>'; return; }
    sel.innerHTML = eligible.map(t => `<option value="${t}">${getAssetConfig(t)?.label || t}</option>`).join('');
    group.style.display = 'block';
}

async function cancelAgentDestination(destinationId) {
    showConfirm('Cancel this registration? You can register again later.', async () => {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/cancel_destination.php', { destination_id: destinationId });
        if (!result.ok) { showMessage('Failed to cancel: ' + friendlyApiError(result.error), 'error'); return; }
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
    return `
        <div style="background:var(--accent-soft);padding:14px;margin-bottom:16px;">
            <div style="font-weight:700;margin-bottom:4px;">Verification code sent</div>
            <div style="font-size:12px;color:var(--text-muted);">${escapeHtml(data.message)}</div>
        </div>
        <div class="field-group"><label>Enter the code</label><input type="text" id="agentOtpCode" inputmode="numeric" maxlength="8" placeholder="Code from your bank"></div>
        <div class="cta-row"><button class="btn btn-secondary" onclick="openAgentModal()">Cancel</button><button class="btn btn-primary" onclick="verifyAgentOtp(${data.attempt_id})">Verify and register</button></div>`;
}

async function verifyAgentOtp(attemptId) {
    const otp = document.getElementById('agentOtpCode').value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/verify_destination_otp.php', { attempt_id: attemptId, otp });
    if (!result.ok) { showMessage('Verification failed: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage(result.body.data.message || 'Account verified and registered.', 'success');
    openAgentModal();
}

// ============================================================
// REGISTER IDENTITY (self-service + agent-verified)
// ============================================================
function openAddIdentityModal() {
    openModal('Register identity', '<div style="text-align:center;padding:20px;"><div class="spinner" style="border-color:rgba(16,30,27,0.15);border-top-color:var(--primary);"></div> Loading...</div>');
    getCurrentUserRole().then(session => {
        if (session.is_agent) renderAgentIdentityForm();
        else renderUserIdentityForm();
    });
}

function renderUserIdentityForm() {
    document.getElementById('modalBody').innerHTML = `
        <div>
            <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Add an identity to your account</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Register a phone number, email, or ID so people can swap directly to you.</div>
            <div class="field-group">
                <label>Identity type</label>
                <select id="userIdentityType">
                    <option value="phone">Phone Number</option>
                    <option value="email">Email</option>
                    <option value="national_id">National ID</option>
                    <option value="birth_certificate">Birth Certificate</option>
                    <option value="voter_id">Voter ID</option>
                </select>
            </div>
            <div class="field-group"><label>Identity value</label><input type="text" id="userIdentityValue" placeholder="Enter the ID number, phone, or email"></div>
            <div style="background:var(--accent-soft);border-left:3px solid var(--accent);padding:10px 14px;font-size:12px;margin-bottom:14px;">
                Phone numbers are verified instantly via SMS. National IDs and other documents require in-person verification by a VouchMorph agent.
            </div>
            <div id="regIdentityOtpFields" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;" id="regIdentityOtpMessage"></div>
                <div class="otp-input-group"><input type="text" id="regIdentityOtp" placeholder="Enter verification code" inputmode="numeric" maxlength="8"><button class="btn btn-primary btn-sm" onclick="submitVerifyIdentityOtp()">Verify</button></div>
            </div>
            <div class="cta-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitRegisterIdentity()">Register identity</button></div>
            <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;font-size:11px;color:var(--text-dim);">
                <span class="quick-link muted" onclick="closeModal();openFinalizeIdentityModal();">Need to claim a swap sent to your identity? Click here &rarr;</span>
            </div>
        </div>`;
}

function renderAgentIdentityForm() {
    document.getElementById('modalBody').innerHTML = `
        <div>
            <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Register a verified identity (Agent)</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Use this after physically verifying the person's document.</div>
            <div class="field-group"><label>Account holder's phone or email</label><input type="text" id="agentTargetLookup" placeholder="Phone or email on their VouchMorph account"></div>
            <div class="field-group">
                <label>Identity type</label>
                <select id="agentIdentityType">
                    <option value="national_id">National ID</option>
                    <option value="voters_id">Voter's ID</option>
                    <option value="drivers_license">Driver's License</option>
                    <option value="birth_certificate">Birth Certificate</option>
                    <option value="passport">Passport</option>
                </select>
            </div>
            <div class="field-group"><label>ID number</label><input type="text" id="agentIdentityValue" placeholder="Document number"></div>
            <div class="field-group"><label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:400;">
                <input type="checkbox" id="agentDocVerified" style="width:auto;"> I have physically verified this document
            </label></div>
            <div class="cta-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitAgentIdentity()">Register identity</button></div>
        </div>`;
}

async function submitRegisterIdentity() {
    const identityType = document.getElementById('userIdentityType').value;
    const identityValue = document.getElementById('userIdentityValue').value.trim();
    if (!identityValue) { showMessage('Please enter the identity value.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/user/add_identity.php', { identity_type: identityType, identity_value: identityValue });
    if (!result.ok) { showMessage('Could not register identity: ' + friendlyApiError(result.error), 'error'); return; }
    const data = result.body.data || {};
    regIdentityState.attemptId = data.attempt_id || null;
    regIdentityState.identityType = identityType;
    regIdentityState.identityValue = identityValue;
    if (data.requires_otp) {
        document.getElementById('regIdentityOtpFields').style.display = 'block';
        document.getElementById('regIdentityOtpMessage').textContent = data.message || 'Enter the verification code sent to your phone/email.';
        showMessage('Verification code sent.', 'success');
        return;
    }
    showMessage(data.message || 'Identity submitted for review.', 'success');
    setTimeout(() => closeModal(), 2000);
}

async function submitAgentIdentity() {
    const lookup = document.getElementById('agentTargetLookup').value.trim();
    const type = document.getElementById('agentIdentityType').value;
    const value = document.getElementById('agentIdentityValue').value.trim();
    const verified = document.getElementById('agentDocVerified').checked;
    if (!lookup) { showMessage("Please enter the account holder's contact.", 'warning'); return; }
    if (!value) { showMessage('Please enter the ID number.', 'warning'); return; }
    if (!verified) { showMessage('You must confirm you verified the document.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/add_verified_identity.php', { target_lookup: lookup, identity_type: type, identity_value: value, document_verified: true });
    if (!result.ok) { showMessage('Failed to register identity: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage(result.body.message || 'Identity registered successfully.', 'success');
    setTimeout(() => closeModal(), 2000);
}

async function submitVerifyIdentityOtp() {
    const otp = document.getElementById('regIdentityOtp').value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/user/verify_identity_otp.php', { attempt_id: regIdentityState.attemptId, otp });
    if (!result.ok) { showMessage('Verification failed: ' + friendlyApiError(result.error), 'error'); return; }
    showMessage('Identity verified. You can now receive swaps.', 'success');
    setTimeout(() => closeModal(), 2000);
}

function openPendingSources() {
    openModal('Pending sources', renderPendingSourcesModal());
}

function renderPendingSourcesModal() {
    if (!pendingSources || pendingSources.length === 0) {
        return `<div style="text-align:center;padding:20px;color:var(--text-dim);font-size:13px;">
            Nothing pending right now.
        </div>`;
    }

    const rows = pendingSources.map(p => {
        const instName = PARTICIPANTS[p.institution]?.name || p.institution || 'Unknown institution';
        const status = p.status || 'pending';
        const isAwaitingReview = status === 'pending_confirmation';
        const isOtpPending = status === 'otp_pending';
        const isOauthPending = status === 'oauth_pending';

        let statusLabel = 'Pending';
        let statusColor = 'var(--warning)';
        let explanation = p.message || 'This source is still being processed.';

        if (isAwaitingReview) {
            statusLabel = 'Awaiting manual review';
            explanation = `${instName} doesn't support instant verification, so VouchMorph needs to confirm ownership manually before this source can be used. This can take a little while — you don't need to do anything else.`;
        } else if (isOtpPending) {
            statusLabel = 'Waiting for your code';
            statusColor = 'var(--accent)';
            explanation = `${instName} sent a verification code — enter it to finish linking this source.`;
        } else if (isOauthPending) {
            statusLabel = 'Waiting for bank login';
            statusColor = 'var(--accent)';
            explanation = `You started logging into ${instName} to confirm ownership but didn't finish. Try again to complete it.`;
        }

        return `
            <div class="myc-panel" style="margin-bottom:10px;" data-pending-id="${p.id}">
                <div class="myc-panel-head">
                    <span class="myc-panel-title">${escapeHtml(instName)}</span>
                    <span style="font-size:11px;font-weight:700;color:${statusColor};text-transform:uppercase;">${statusLabel}</span>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">${escapeHtml(p.identifier || '')}</div>
                <div style="font-size:12px;color:var(--text-dim);line-height:1.5;">${escapeHtml(explanation)}</div>
                <div class="cta-row" style="margin-top:10px;">
                    ${isOtpPending ? `<button class="btn btn-primary btn-sm" onclick="closeModal(); openOtpEntryModal(${p.attempt_id || p.id})">Enter code</button>` : ''}
                    <button class="btn-danger-outline" onclick="confirmDeletePendingSource(${p.id}, '${p.type}')">Remove</button>
                </div>
            </div>`;
    }).join('');

    return `<div>${rows}</div>`;
}

function confirmDeletePendingSource(sourceId, type) {
    showConfirm('Remove this pending source? You can add it again anytime.', async () => {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/delete.php', {
            type,
            source_id: sourceId
        });
        if (!result.ok) {
            showMessage('Could not remove that source: ' + friendlyApiError(result.error), 'error');
            return;
        }
        showMessage('Pending source removed.', 'success');
        await loadUserSources(); // refreshes the pendingSources array + badge
        openPendingSources(); // re-render the modal with the updated list
    });
}
    
// ============================================================
// MISSING FUNCTIONS - ADD THESE
// ============================================================

const IDENTITY_TYPE_LABELS = { 
    national_id: 'National ID', 
    birth_certificate: 'Birth Certificate', 
    voter_id: 'Voter ID', 
    phone: 'Phone Number', 
    email: 'Email' 
};

async function fetchBalance(institution, identifier, identifierType = 'auto') {
    try {
        const response = await fetch(CONFIG.API_BASE + '/api/v1/user/balance.php', { 
            method: 'POST', 
            headers: buildHeaders(), 
            body: JSON.stringify({ institution, identifier, identifier_type: identifierType }), 
            credentials: 'include' 
        });
        return await response.json();
    } catch (error) { 
        console.error('Balance fetch error:', error); 
        return { success: false, error: error.message }; 
    }
}

async function fetchAllBalances() {
    try {
        const response = await fetch(CONFIG.API_BASE + '/api/v1/user/all_balances.php', { 
            method: 'GET', 
            headers: buildHeaders(), 
            credentials: 'include' 
        });
        return await response.json();
    } catch (error) { 
        console.error('Fetch all balances error:', error); 
        return { success: false, error: error.message }; 
    }
}

async function viewWalletBalance() {
    openModal('Balances', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading balances...</div>');
    const result = await fetchAllBalances();
    if (!result.success) {
        document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);"><div style="font-weight:700;">Couldn't load your balances</div><div style="font-size:12px;color:var(--text-muted);margin-top:8px;">${escapeHtml(friendlyApiError(result.error))}</div><button class="btn btn-primary btn-sm" onclick="viewWalletBalance()" style="margin-top:12px;">Retry</button></div>`;
        return;
    }
    const data = result.data || {}; 
    const sources = data.sources || []; 
    const totals = data.total || {};
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

function openHowItWorks(key) {
    const info = {
        swap: { title: 'How Swap works', body: '<div style="font-size:13px;line-height:1.7;color:var(--text);"><p style="font-weight:700;margin-bottom:6px;">How Swap works</p><ol style="padding-left:18px;margin-bottom:16px;"><li>Enter the amount you want to swap.</li><li>Choose where the money comes from (Wallet, Card, Voucher, My Card, or Combine).</li><li>Choose where the money goes (Deposit, Cashout, or Identity).</li><li>Review the fee and confirm.</li></ol></div>' },
        card: { title: 'How the Card works', body: '<div style="font-size:13px;line-height:1.7;color:var(--text);"><p style="font-weight:700;margin-bottom:6px;">How the VouchMorph Card works</p><ol style="padding-left:18px;margin-bottom:16px;"><li>Every account gets a VouchMorph Card automatically.</li><li>It starts inactive — activate it once with a small one-time fee from any linked source.</li><li>Hook one or many sources to your card.</li><li>Share your QR code so others can hook sources to your card.</li><li>Start a swap from the Card view or use "My Card" as a source in Swap.</li></ol></div>' }
    };
    const data = info[key];
    if (!data) return;
    openModal(data.title, data.body);
}

function openHelpModal() {
    openModal('Help', `<div style="font-size:13px;line-height:1.7;color:var(--text);">
        <p style="font-weight:700;margin-bottom:6px;">Swapping money</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>From the hub, tap Swap.</li><li>Follow the 4 steps: amount → source → destination → confirm.</li></ol>
        <p style="font-weight:700;margin-bottom:6px;">Your VouchMorph Card</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>From the hub, tap Card. Every account gets one automatically.</li><li>It starts inactive — activate it once with a small one-time fee from any linked source.</li><li>Hook one or many sources — from the Card view ("Hook a source"), or from Toolbox.</li></ol>
        <p style="font-weight:700;margin-bottom:6px;">Claiming money sent to you</p>
        <ol style="padding-left:18px;margin-bottom:16px;"><li>Toolbox → Finalize identity swap.</li><li>Enter your claim PIN and choose how to receive it.</li></ol>
    </div>`);
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

function setSwapType(type) {
    state.swapType = type;
    const depositBtn = document.getElementById('depositToggleBtn');
    const cashoutBtn = document.getElementById('cashoutToggleBtn');
    if (depositBtn) depositBtn.classList.toggle('selected', type === 'DEPOSIT');
    if (cashoutBtn) cashoutBtn.classList.toggle('selected', type === 'CASHOUT');
    const cashoutFields = document.getElementById('cashoutFields');
    if (cashoutFields) cashoutFields.style.display = type === 'CASHOUT' ? 'block' : 'none';
}

function useSourceForSwap(sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    if (!source) { showMessage('Source not found.', 'error'); return; }
    goView('swap');
    setTimeout(() => {
        let tileType = 'WALLET';
        const assetType = String(source.asset_type).toUpperCase();
        if (assetType.includes('CARD')) tileType = 'CARD';
        else if (assetType.includes('VOUCHER')) tileType = 'VOUCHER';
        const tile = document.querySelector(`.source-option[data-source="${tileType}"]`);
        if (tile) {
            tile.click();
            setTimeout(() => {
                const savedSourceRows = document.querySelectorAll('.saved-source-row');
                savedSourceRows.forEach(row => {
                    if (row.textContent.includes(source.identifier || '')) {
                        row.click();
                    }
                });
            }, 200);
        }
    }, 100);
}
    
// ============================================================
// ASYNC INIT FUNCTIONS - DEFINED BEFORE DOM READY
// ============================================================
async function getCurrentUserRole() {
    if (SessionUser) return SessionUser;
    const result = await callApi(CONFIG.API_BASE + '/user/whoami.php', {});
    SessionUser = (result.ok && result.body) ? result.body : { success: false, role: 'user', is_agent: false, is_admin: false, permissions: [] };
    renderProgressCard();
    return SessionUser;
}

async function loadUserSources() {
    if (!CONFIG.USER_ID) return;
    const result = await callApi(CONFIG.API_BASE + '/user/sources.php', {});
    if (result.ok) {
        userSources = result.body.data?.sources || [];
        renderProgressCard();
    }
    const pendingResult = await callApi(CONFIG.API_BASE + '/api/v1/sources/pending.php', {});
    if (pendingResult.ok) { pendingSources = pendingResult.body.data || []; updateToolboxBadge(); }
}

async function checkPendingClaims() {
    if (!CONFIG.USER_ID) return;
    try { 
        const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/pending_claims.php', {}); 
        if (!result.ok) return; 
        pendingClaims = result.body.data || []; 
        updateToolboxBadge(); 
    } catch (e) { 
        console.warn('[claims] Failed to check pending claims:', e); 
    }
}

function updateToolboxBadge() {
    const badge = document.getElementById('toolboxBadge');
    if (!badge) return;
    const totalPending = pendingSources.length + pendingClaims.length;
    if (totalPending > 0) { badge.style.display = 'inline-flex'; badge.textContent = totalPending; } 
    else { badge.style.display = 'none'; }
}

async function loadAgentStatus() {
    if (!CONFIG.USER_ID) return;
    await getCurrentUserRole();
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) return;
    agentStatus = result.body.data; 
    agentStatus.is_agent = SessionUser.is_agent;
}

function openProfileModal() { openModal('My profile', renderProfileModal()); }

function renderProfileModal() {
    const rows = savedIdentities.length ? savedIdentities.map((id, i) => `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);"><div><div style="font-size:11px;color:var(--text-muted);">${escapeHtml(IDENTITY_TYPE_LABELS[id.type] || id.type)}</div><div style="font-size:14px;font-weight:700;">${escapeHtml(id.value)}</div></div><div class="quick-actions" style="margin:0;"><span class="quick-link" onclick="useSavedIdentity(${i})">Use</span><span class="quick-link danger" onclick="removeSavedIdentity(${i})">Remove</span></div></div>`).join('') : `<div style="font-size:12px;color:var(--text-dim);">No saved identities yet.</div>`;
    return `<div style="margin-bottom:12px;"><div style="font-weight:700;margin-bottom:4px;">Your registered identities</div>${rows}</div><div style="border-top:1px solid var(--border);padding-top:16px;"><div class="field-label" style="margin-bottom:8px;">Transaction PIN</div><div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Required to claim money sent to your verified identity. Never share it.</div><div class="field-group"><label>New PIN (4-6 digits)</label><input type="password" id="newPin" inputmode="numeric" maxlength="6" placeholder="••••"></div><div class="field-group"><label>Confirm PIN</label><input type="password" id="confirmPin" inputmode="numeric" maxlength="6" placeholder="••••"></div><div class="cta-row"><button class="btn btn-primary" onclick="setTransactionPin()">Set PIN</button></div></div><div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;"><span class="quick-link" onclick="closeModal();openAddIdentityModal();">Add a new identity</span><span class="quick-link muted" onclick="closeModal();openFinalizeIdentityModal();">Finalize an identity swap</span></div>`;
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

// ============================================================
// ADD SOURCE - opens the modal to add a new source
// ============================================================
function openAddSource() {
    const instOptions = Object.keys(PARTICIPANTS).map(code => 
        `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`
    ).join('');
    
    const bodyHtml = `
        <div style="margin-bottom:16px;">
            <div style="font-size:13px;font-weight:700;margin-bottom:4px;">Link a new source</div>
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
            <label>Asset type</label>
            <select id="addSourceAssetType" onchange="onAddSourceAssetTypeChange(this.value)"></select>
            <div class="help">Users can only add Accounts, Wallets, or Cards.</div>
        </div>
        <div class="field-group">
            <label>Identifier</label>
            <input id="addSourceIdentifier" placeholder="Account number, phone, or card number">
            <div class="help" id="addSourceIdentifierHelp">The number that identifies your account at this institution.</div>
        </div>
        <div class="field-group">
            <label>Account name (optional)</label>
            <input id="addSourceAccountName" placeholder="e.g. My Main Account">
        </div>
        <div style="background:var(--accent-soft);border-left:3px solid var(--accent);padding:10px 14px;font-size:12px;margin-bottom:14px;color:var(--primary);">
            🔒 Your details are encrypted and only used to verify you own this account — VouchMorph never stores your bank password.
        </div>
        <div id="addSourceOtpFields" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
            <div style="font-size:12px;font-weight:700;margin-bottom:8px;">Verify with OTP</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;" id="otpMessage">A verification code has been sent to your registered phone.</div>
            <div class="otp-input-group">
                <input type="text" id="addSourceOtp" placeholder="Enter code" inputmode="numeric" maxlength="8">
                <button class="btn btn-primary btn-sm" onclick="completeSourceOtp()">Verify</button>
            </div>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="addSourceSubmitBtn" onclick="submitAddSource()">Link source</button>
        </div>
    `;
    
    openModal('Add source — step 1 of 2: Link', bodyHtml);
}

// ============================================================
// REQUEST PAYMENT (agent generates a QR)
// ============================================================
function openRequestPaymentModal() {
    const eligible = userSources.filter(s => s.status === 'active'); // reuse saved sources as receiving destinations for now
    const instOptions = Object.keys(PARTICIPANTS).map(code =>
        `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`
    ).join('');

    const bodyHtml = `
        <div class="field-group">
            <label>Amount you want to receive</label>
            <input type="number" id="reqPayAmount" min="0.01" step="0.01" placeholder="0.00">
        </div>
        <div class="field-group">
            <label>Currency</label>
            <select id="reqPayCurrency">
                <option value="BWP">BWP</option>
            </select>
        </div>
        <div class="field-group">
            <label>How do you want to receive it?</label>
            <select id="reqPayDestType" onchange="onReqPayDestTypeChange(this.value)">
                <option value="DEPOSIT">Deposit into an account/wallet</option>
                <option value="CASHOUT">Cashout code</option>
            </select>
        </div>
        <div id="reqPayDepositFields">
            <div class="field-group">
                <label>Receiving institution</label>
                <select id="reqPayInst" onchange="onReqPayInstChange(this.value)">
                    <option value="">Select institution</option>
                    ${instOptions}
                </select>
            </div>
            <div class="field-group" id="reqPayAssetGroup" style="display:none;">
                <label>Asset type</label>
                <select id="reqPayAssetType"></select>
            </div>
            <div class="field-group">
                <label>Account/wallet number</label>
                <input id="reqPayIdentifier" placeholder="Account number or phone">
            </div>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="submitRequestPayment()">Generate QR</button>
        </div>
    `;
    openModal('Request payment', bodyHtml);
}

function onReqPayDestTypeChange(type) {
    // Placeholder hook if CASHOUT needs different fields later.
}

function onReqPayInstChange(code) {
    const group = document.getElementById('reqPayAssetGroup');
    const sel = document.getElementById('reqPayAssetType');
    if (!code) { group.style.display = 'none'; return; }
    const types = PARTICIPANTS[code]?.asset_types || [];
    sel.innerHTML = types.map(t => `<option value="${t}">${assetIcon(t)} ${getAssetConfig(t)?.label || t}</option>`).join('');
    group.style.display = 'block';
}

async function submitRequestPayment() {
    const amount = parseFloat(document.getElementById('reqPayAmount')?.value);
    const currency = document.getElementById('reqPayCurrency')?.value;
    const destType = document.getElementById('reqPayDestType')?.value;
    const inst = document.getElementById('reqPayInst')?.value;
    const assetType = document.getElementById('reqPayAssetType')?.value;
    const identifier = document.getElementById('reqPayIdentifier')?.value.trim();

    if (!(amount > 0)) { showMessage('Enter an amount.', 'warning'); return; }
    if (!inst || !assetType || !identifier) { showMessage('Complete the destination fields.', 'warning'); return; }

    const identifierType = String(assetType).toUpperCase() === 'ACCOUNT' ? 'account_number' : 'phone';

    const result = await callApi(CONFIG.API_BASE + '/api/v1/payments/create_request.php', {
        destination_institution: inst,
        destination_asset_type: assetType,
        destination_identifier: identifier,
        destination_identifier_type: identifierType,
        destination_type: destType,
        net_amount: amount,
        currency: currency,
    });

    if (!result.ok) {
        showMessage('Could not create the payment request: ' + friendlyApiError(result.error), 'error');
        return;
    }

    const data = result.body.data;
    const bodyHtml = `
        <div style="text-align:center;padding:10px 0;">
            <div style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">
                Have the payer scan this to send you ${formatMoney(data.net_amount, data.currency)}
            </div>
            <div class="myc-qr-frame" id="reqPayQrContainer" style="display:inline-flex;"></div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:12px;">
                Expires at ${new Date(data.expires_at).toLocaleTimeString()}
            </div>
        </div>
        <div class="cta-row" style="margin-top:16px;">
            <button class="btn btn-primary" onclick="closeModal()">Done</button>
        </div>
    `;
    openModal('Your payment QR', bodyHtml);

    setTimeout(() => {
        const el = document.getElementById('reqPayQrContainer');
        if (el && typeof QRCode !== 'undefined') {
            new QRCode(el, { text: data.qr_payload, width: 220, height: 220 });
        }
    }, 50);
}

// ============================================================
// SCAN TO PAY (payer scans a payment-request QR)
// ============================================================
function openScanQrModal() {
    const bodyHtml = `
        <div id="payQrScannerRegion" style="width:100%;"></div>
        <div style="text-align:center;margin:12px 0;font-size:11px;color:var(--text-dim);">or</div>
        <div class="field-group"><label>Paste the code manually</label><input id="payManualQrPaste" placeholder="Paste QR text if you can't scan"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="submitPayManualQr()">Continue</button></div>
    `;
    openModal('Scan to pay', bodyHtml);
    try {
        html5QrScanner = new Html5Qrcode('payQrScannerRegion');
        html5QrScanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 }, (decodedText) => {
            html5QrScanner.stop().catch(() => {});
            resolvePaymentQr(decodedText);
        }, () => {}).catch((e) => console.warn('[pay-qr] camera scan unavailable:', e));
    } catch (e) { console.warn('[pay-qr] Html5Qrcode not available:', e); }
}

function submitPayManualQr() {
    const raw = document.getElementById('payManualQrPaste')?.value.trim();
    if (!raw) { showMessage('Paste the code first, or use the camera scanner above.', 'warning'); return; }
    resolvePaymentQr(raw);
}

async function resolvePaymentQr(raw) {
    if (html5QrScanner) { try { await html5QrScanner.stop(); } catch (e) {} }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Resolveqr.php', { raw });
    if (!result.ok) { showMessage(result.error, 'error'); return; }

    const data = result.body.data;
    if (data.type === 'hook') {
        // existing hook behavior — reuse whatever you already call here
        confirmHookTargetCard(data.card_suffix, data.display_name);
        return;
    }
    if (data.type === 'payment_request') {
        renderPaymentRequestConfirm(data);
        return;
    }
    showMessage('Unrecognized QR type.', 'error');
}

let payReqState = { requestId: null, source: null };

function renderPaymentRequestConfirm(data) {
    payReqState = { requestId: data.request_id, source: null };

    const eligible = userSources.filter(s => s.status === 'active');
    const bodyHtml = `
        <div class="review-hero">
            <div class="review-hero-label">Pay ${escapeHtml(data.agent_display_name)}</div>
            <div class="review-hero-amount">${formatMoney(data.net_amount, data.currency)}</div>
        </div>
        <div class="field-group">
            <label>Pay from</label>
            <select id="payReqSourceSelect" onchange="onPayReqSourceChange(this.value)">
                <option value="">Select a saved source</option>
                ${eligible.map(s => `<option value="${s.id}">${PARTICIPANTS[s.institution]?.name || s.institution} — ${s.identifier || ''}</option>`).join('')}
            </select>
        </div>
        <div id="payReqPinField" style="display:none;">
            <div class="field-group">
                <label>PIN (if required)</label>
                <input type="password" id="payReqPin" placeholder="••••">
            </div>
        </div>
        <div class="cta-row">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="payReqConfirmBtn" onclick="executePaymentRequest()" disabled>Pay now</button>
        </div>
    `;
    openModal('Confirm payment', bodyHtml);
}

function onPayReqSourceChange(sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    payReqState.source = source || null;
    document.getElementById('payReqConfirmBtn').disabled = !source;
    document.getElementById('payReqPinField').style.display = source ? 'block' : 'none';
}

async function executePaymentRequest() {
    const source = payReqState.source;
    if (!source) return;
    const pin = document.getElementById('payReqPin')?.value || null;

    const btn = document.getElementById('payReqConfirmBtn');
    btn.disabled = true;
    btn.textContent = 'Paying…';

    const result = await callApi(CONFIG.API_BASE + '/api/v1/payments/execute.php', {
        request_id: payReqState.requestId,
        source: {
            institution: source.institution,
            asset_type: source.asset_type,
            identifier: source.identifier || source.source_identifier,
            wallet_pin: pin,
            pin: pin,
        },
    });

    if (!result.ok) {
        showMessage('Payment failed: ' + friendlyApiError(result.error), 'error');
        btn.disabled = false;
        btn.textContent = 'Pay now';
        return;
    }

    const data = result.body.data;
    const bodyHtml = `
        <div class="result-box" id="resultBoxRoot">
            <div class="icon">✓</div>
            <div class="result-title">Payment sent!</div>
            <div class="result-sub">Reference: ${escapeHtml(data.swap_reference)}</div>
            <div style="font-size:22px;font-weight:600;font-family:var(--font-mono);margin-top:12px;">${formatMoney(data.amount, data.currency)}</div>
        </div>
        <div class="cta-row" style="margin-top:16px;">
            <button class="btn btn-primary" onclick="closeModal(); goView('activity');">Done</button>
        </div>
    `;
    openModal('Payment complete', bodyHtml);
    setTimeout(() => fireConfetti(document.getElementById('resultBoxRoot')), 150);
}

let pendingScannedDestination = null;

function openScanModal(context) {
    const copy = {
        card_hook: { title: 'Scan a card', hint: "Point your camera at their VouchMorph Card QR to hook a source to it." },
        pay:       { title: 'Scan to pay',  hint: "Scan a payment request or someone's card to pay them." },
        swap_dest: { title: 'Scan a code',  hint: "Scan a friend's account QR to fill in the destination." },
        scan_and_hook: { title: 'Scan a card', hint: 'Point your camera at the card you\'re hooking this source to.' },
        agent_charge:  { title: 'Scan a card', hint: "Point your camera at the customer's VouchMorph Card." },
    }[context] || { title: 'Scan a code', hint: 'Point your camera at a VouchMorph QR code.' };

    const bodyHtml = `
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">${copy.hint}</div>
        <div id="qrScannerRegion" style="width:100%;"></div>
        <div style="text-align:center;margin:12px 0;font-size:11px;color:var(--text-dim);">or</div>
        <div class="field-group"><label>Paste the code manually</label><input id="manualQrPaste" placeholder="Paste QR text if you can't scan"></div>
        <div class="cta-row"><button class="btn btn-primary" onclick="submitManualQr('${context}')">Continue</button></div>`;
    openModal(copy.title, bodyHtml);

    try {
        html5QrScanner = new Html5Qrcode('qrScannerRegion');
        html5QrScanner.start(
            { facingMode: 'environment' }, { fps: 10, qrbox: 220 },
            (decodedText) => { html5QrScanner.stop().catch(() => {}); handleScannedQr(decodedText, context); },
            () => {}
        ).catch((e) => console.warn('[qr] camera scan unavailable, manual paste only:', e));
    } catch (e) { console.warn('[qr] Html5Qrcode not available, manual paste only:', e); }
}

function submitManualQr(context) {
    const raw = document.getElementById('manualQrPaste')?.value.trim();
    if (!raw) { showMessage('Paste the code first, or use the camera scanner above.', 'warning'); return; }
    handleScannedQr(raw, context);
}

async function handleScannedQr(raw, context) {
    if (html5QrScanner) { try { await html5QrScanner.stop(); } catch (e) {} }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Resolveqr.php', { raw });
    if (!result.ok) { showMessage("This code couldn't be verified — ask them to show it again.", 'error'); return; }

    const data = result.body.data;
    switch (data.type) {
        case 'hook':
            if (context === 'scan_and_hook') confirmScanAndHook(data.card_suffix, data.display_name);
            else if (context === 'agent_charge') confirmAgentChargeCard(data.card_suffix, data.display_name);
            else confirmHookTargetCard(data.card_suffix, data.display_name);
            break;
        case 'payment_request':
            renderPaymentRequestConfirm(data);
            break;
        case 'account': {
            closeModal();
            const prefill = { institution: data.institution, assetType: data.asset_type, identifier: data.identifier, displayName: data.display_name || null };
            if (viewStack[viewStack.length - 1] === 'swap' && wizardState.step === 3) {
                autoFillDestinationFromScan(prefill);
            } else {
                pendingScannedDestination = prefill;
                viewStack = ['hub'];
                goView('swap');
            }
            break;
        }
        default:
            showMessage("This code couldn't be verified — ask them to show it again.", 'error');
    }
}

function autoFillDestinationFromScan(prefill) {
    selectDestination('DEPOSIT');
    const panel = document.getElementById('destDetailPanel');
    if (!panel) return;
    const instSel = panel.querySelector('#toInstSelect');
    if (instSel) { instSel.value = prefill.institution; wizardSelectToInst(prefill.institution); }
    const targetAsset = normalizeAssetType(prefill.assetType);
    const assetSel = panel.querySelector('#toAssetSelect');
    if (assetSel && assetSel.options.length) {
        const match = Array.from(assetSel.options).find(o => normalizeAssetType(o.value) === targetAsset);
        if (match) { assetSel.value = match.value; wizardSelectToAsset(match.value); }
    }
    setTimeout(() => {
        const cfg = getAssetConfig(wizardState.toAsset);
        const idField = (cfg?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        if (idField) {
            const input = document.getElementById('toField_' + idField.name);
            if (input) input.value = prefill.identifier;
            wizardState.toFields[idField.name] = prefill.identifier;
        }
        const valid = fieldsValidForAsset(wizardState.toAsset, wizardState.toFields, false);
        const nextBtn = document.getElementById('wizardDestNext');
        if (nextBtn) nextBtn.disabled = !valid.valid;
        if (!panel.querySelector('#scannedDestBanner')) {
            const banner = document.createElement('div');
            banner.id = 'scannedDestBanner';
            banner.style.cssText = 'background:var(--accent-soft);border-left:3px solid var(--accent);padding:10px 14px;font-size:12px;margin-bottom:12px;color:var(--primary);';
            banner.textContent = prefill.displayName ? `Filled in from the code you scanned — ${prefill.displayName}` : 'Filled in from the code you scanned.';
            panel.insertBefore(banner, panel.firstChild);
        }
    }, 30);
    pendingScannedDestination = null;
}
    
// ============================================================
// ADD SOURCE - helper functions
// ============================================================
let addSourceState = { 
    attemptId: null, 
    requiresOtp: false, 
    requiresRedirect: false, 
    redirectUrl: null, 
    institution: null, 
    assetType: null, 
    identifier: null 
};

function onAddSourceInstChange(code) {
    const group = document.getElementById('addSourceAssetGroup');
    const sel = document.getElementById('addSourceAssetType');
    if (!code) {
        if (group) group.style.display = 'none';
        if (sel) sel.innerHTML = '';
        return;
    }
    const inst = PARTICIPANTS[code];
    const allTypes = inst?.asset_types || [];
    const eligible = allTypes.filter(t => 
        ['ACCOUNT', 'WALLET', 'CARD', 'MNO-WALLET', 'BANK-WALLET'].includes(String(t).toUpperCase())
    );
    if (eligible.length === 0) {
        if (sel) sel.innerHTML = `<option value="">This institution doesn't support self-service linking yet</option>`;
        if (group) group.style.display = 'block';
        return;
    }
    if (sel) sel.innerHTML = eligible.map(t => 
        `<option value="${t}">${assetIcon(t)} ${getAssetConfig(t)?.label || t}</option>`
    ).join('');
    if (group) group.style.display = 'block';
    onAddSourceAssetTypeChange(eligible[0]);
}

function onAddSourceAssetTypeChange(type) {
    const idInput = document.getElementById('addSourceIdentifier');
    const help = document.getElementById('addSourceIdentifierHelp');
    if (!idInput) return;
    const t = String(type).toUpperCase();
    const copy = { 
        'ACCOUNT': ['e.g. 0011223344', 'Your bank account number.'],
        'CARD': ['e.g. 16-digit card number', 'The number on the front of your card.'],
        'WALLET': ['e.g. +267 71 234 567', 'The phone number your wallet is registered to.'],
        'MNO-WALLET': ['e.g. +267 71 234 567', 'The phone number your mobile wallet is registered to.'],
        'BANK-WALLET': ['e.g. 0011223344', 'Your bank wallet account number.']
    };
    const [ph, h] = copy[t] || ['Account number, phone, or card number', 'The number that identifies your account at this institution.'];
    idInput.placeholder = ph;
    if (help) help.textContent = h;
}

async function submitAddSource() {
    const institution = document.getElementById('addSourceInst')?.value;
    const assetType = document.getElementById('addSourceAssetType')?.value;
    const identifier = document.getElementById('addSourceIdentifier')?.value.trim();
    const accountName = document.getElementById('addSourceAccountName')?.value.trim();
    
    if (!institution) { showMessage('Select an institution.', 'warning'); return; }
    if (!assetType) { showMessage('Select an asset type.', 'warning'); return; }
    if (!identifier) { showMessage('Enter your account identifier.', 'warning'); return; }
    
    const btn = document.getElementById('addSourceSubmitBtn');
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Registering...';
    
    const result = await callApi(CONFIG.API_BASE + '/user/add_source.php', { 
        institution, 
        asset_type: assetType, 
        identifier, 
        account_name: accountName || undefined 
    });
    
    btn.disabled = false;
    btn.textContent = original;
    
    if (!result.ok) {
        showMessage('Could not add that source: ' + friendlyApiError(result.error), 'error');
        return;
    }
    
    const data = result.body.data || {};
    addSourceState = { 
        attemptId: data.attempt_id || null, 
        requiresOtp: data.requires_otp || false, 
        requiresRedirect: data.requires_redirect || false, 
        redirectUrl: data.redirect_url || null, 
        institution, 
        assetType, 
        identifier 
    };
    
    if (data.requires_redirect) {
        showMessage('Redirecting to your bank for verification...', 'info');
        setTimeout(() => { window.location.href = data.redirect_url; }, 1500);
        return;
    }
    
    if (data.requires_otp) {
        document.getElementById('modalTitle').textContent = 'Add source — step 2 of 2: Verify';
        document.getElementById('addSourceOtpFields').style.display = 'block';
        document.getElementById('otpMessage').textContent = data.message || 'A verification code has been sent to your registered phone.';
        document.getElementById('addSourceSubmitBtn').style.display = 'none';
        showMessage('OTP sent! Enter the code to verify.', 'success');
        return;
    }
    
    showMessage(data.message || 'Source added!', 'success');
    setTimeout(() => { 
        loadUserSources(); 
        loadToolboxView(); 
    }, 1500);
}

async function completeSourceOtp() {
    const otp = document.getElementById('addSourceOtp')?.value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    if (!addSourceState.attemptId) { showMessage('No pending verification attempt.', 'error'); return; }
    
    const btn = document.querySelector('#addSourceOtpFields .btn-primary');
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Verifying...';
    
    const result = await callApi(CONFIG.API_BASE + '/user/verify_source.php', { 
        attempt_id: addSourceState.attemptId, 
        otp, 
        user_id: CONFIG.USER_ID 
    });
    
    btn.disabled = false;
    btn.textContent = original;
    
    if (!result.ok) {
        showMessage('That code didn\'t work: ' + friendlyApiError(result.error), 'error');
        return;
    }
    
    showMessage('Source verified and activated! 🎉', 'success');
    setTimeout(() => { 
        loadUserSources(); 
        loadToolboxView(); 
    }, 1500);
}

    
// ============================================================
// DOM READY - NO AWAIT HERE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    loadSavedTheme();
    
    const toSelect = document.getElementById('toInstSelect');
    const instOptions = Object.keys(PARTICIPANTS);
    if (toSelect && instOptions.length > 0) {
        toSelect.innerHTML = '<option value="">Select institution</option>';
        instOptions.forEach(code => { 
            toSelect.insertAdjacentHTML('beforeend', `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`); 
        });
    }
    
    // Call async functions without await - they handle their own errors
    checkPendingClaims();
    loadAgentStatus();
    loadUserSources();
    renderProgressCard();
    renderRepeatCard();
    renderView();
});

document.addEventListener('keydown', function(e) { 
    if (e.key === 'Escape') { 
        if (document.getElementById('modal')?.classList.contains('active')) {
            closeModal(); 
        } else if (viewStack.length > 1) {
            goBack(); 
        }
    } 
});
</script>
</body>
</html>
