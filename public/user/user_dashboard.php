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
<title>VouchMorph – Full Hub</title>
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
    --header-h: 68px;
    --max-w: 1040px;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
html { scroll-behavior: smooth; }
body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; line-height: 1.5; -webkit-font-smoothing: antialiased; transition: background 0.25s ease, color 0.25s ease; }
input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; }
.fade-in-up { animation: fadeInUp 0.4s ease forwards; }
@keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes popIn { 0% { transform: scale(0.85); opacity: 0; } 60% { transform: scale(1.04); opacity: 1; } 100% { transform: scale(1); } }
@keyframes ringDraw { from { stroke-dashoffset: var(--ring-from); } to { stroke-dashoffset: var(--ring-to); } }
@keyframes confettiFall { 0% { transform: translateY(-10px) rotate(0deg); opacity: 1; } 100% { transform: translateY(140px) rotate(280deg); opacity: 0; } }
@keyframes softPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.55; } }

/* ===== THEME SWATCHES ===== */
.theme-classic { --bg: #FAF9F6; --surface: #FFFFFF; --surface-muted: #F4F3EF; --text: #10201C; --text-muted: #63706A; --text-dim: #8A968F; --primary: #10201C; --primary-dark: #04120E; --accent: #00A878; --accent-2: #FF7A59; --border: rgba(16,30,27,0.12); --border-strong: rgba(16,30,27,0.22); }
.theme-women { --bg: #FFF7F5; --surface: #FFFFFF; --surface-muted: #FDEDEA; --text: #3A1F2B; --text-muted: #8C5A6B; --text-dim: #B98FA0; --primary: #7A3B57; --primary-dark: #5A2740; --accent: #E08FA4; --accent-2: #F2B5A0; --border: rgba(122,59,87,0.15); --border-strong: rgba(122,59,87,0.3); }
.theme-kids { --bg: #F0FBFF; --surface: #FFFFFF; --surface-muted: #E4F6FF; --text: #132447; --text-muted: #4A6280; --text-dim: #7D93AC; --primary: #1E3A8A; --primary-dark: #122457; --accent: #FFB400; --accent-2: #FF6B6B; --border: rgba(30,58,138,0.15); --border-strong: rgba(30,58,138,0.3); }
.theme-alpha { --bg: #0A0A0A; --surface: #151515; --surface-muted: #1E1E1E; --text: #F2F2F2; --text-muted: #9A9A9A; --text-dim: #6E6E6E; --primary: #F2F2F2; --primary-dark: #FFFFFF; --accent: #D6242C; --accent-2: #FF5A5F; --border: rgba(255,255,255,0.12); --border-strong: rgba(255,255,255,0.25); }

.role-badge { font-size: 9px; color: var(--primary); border: 1px solid var(--border-strong); padding: 3px 8px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.04em; }
.agent-badge { font-size: 9px; color: #fff; background: var(--accent-2); padding: 3px 8px; text-transform: uppercase; font-weight: 700; }
.test-mode-badge { font-size: 9px; color: var(--danger); border: 1px solid rgba(198,40,40,0.35); background: rgba(198,40,40,0.06); padding: 3px 8px; text-transform: uppercase; font-weight: 700; }
.toolbox-badge { min-width: 18px; height: 18px; padding: 0 5px; background: var(--accent-2); color: #fff; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }

/* ===== HEADER ===== */
.site-header { position: sticky; top: 0; z-index: 100; background: var(--surface); border-bottom: 1px solid var(--border); }
.header-inner { max-width: var(--max-w); margin: 0 auto; padding: 0 24px; height: var(--header-h); display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.brand { display: flex; align-items: center; gap: 14px; flex-shrink: 0; cursor: pointer; }
.brand-text { display: flex; flex-direction: column; gap: 1px; }
.logo { font-size: 18px; font-weight: 700; letter-spacing: -0.01em; line-height: 1.1; color: var(--text); }
.logo sup { font-size: 9px; font-weight: 700; vertical-align: super; color: var(--accent); }
.tagline { font-size: 10px; font-weight: 600; color: var(--text-dim); letter-spacing: 0.06em; text-transform: uppercase; font-family: var(--font-mono); }
.brand-divider { width: 1px; height: 26px; background: var(--border-strong); display: none; }
@media (min-width: 640px) { .brand-divider { display: block; } }

/* ===== NAV ===== */
.main-nav { display: none; align-items: center; gap: 2px; border: 1px solid var(--border); }
@media (min-width: 768px) { .main-nav { display: flex; } }
.nav-pill, .nav-link { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; font-size: 12px; font-weight: 600; font-family: var(--font); border: none; background: transparent; color: var(--text-muted); cursor: pointer; text-decoration: none; transition: var(--transition); white-space: nowrap; text-transform: uppercase; letter-spacing: 0.04em; }
.nav-pill.active, .nav-pill:hover { background: var(--primary); color: #fff; }
.nav-link:hover { color: var(--text); background: var(--surface-muted); }
.nav-icon { font-size: 13px; opacity: 0.85; }

.header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
.header-meta { display: none; align-items: center; gap: 10px; }
@media (min-width: 900px) { .header-meta { display: flex; } }
.country-selector { background: var(--surface-muted); border: 1px solid var(--border); padding: 8px 12px; color: var(--text-muted); font-size: 12px; cursor: pointer; font-family: var(--font); line-height: 1; }
.toolbox-btn { position: relative; display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: var(--text); background: transparent; border: 1px solid var(--border-strong); padding: 8px 14px; cursor: pointer; font-family: var(--font); transition: var(--transition); text-transform: uppercase; letter-spacing: 0.03em; }
.toolbox-btn:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

.user-chip { display: flex; align-items: center; gap: 10px; cursor: pointer; }
.user-chip-text { display: none; flex-direction: column; line-height: 1.2; }
@media (min-width: 640px) { .user-chip-text { display: flex; } }
.user-chip-name { font-size: 13px; font-weight: 600; color: var(--text); }
.user-chip-role { font-size: 11px; color: var(--text-dim); }
.user-avatar { width: 32px; height: 32px; background: var(--primary); color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; position: relative; }
.logout-btn { font-size: 12px; font-weight: 600; color: var(--text-dim); text-decoration: none; padding: 8px 6px; }
.logout-btn:hover { color: var(--danger); }

/* ===== BACK BUTTON (hub navigation) ===== */
.back-btn { display: none; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--text-muted); background: none; border: none; cursor: pointer; padding: 6px 4px; font-family: var(--font); }
.back-btn:hover { color: var(--text); }
.back-btn.show { display: inline-flex; }

/* ===== VIEW SYSTEM ===== */
.view { display: none; }
.view.active { display: block; }

/* ===== HUB ===== */
.hub-section { max-width: 760px; margin: 0 auto; padding: 48px 24px 60px; }
.hub-eyebrow { text-align: center; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-dim); font-family: var(--font-mono); margin-bottom: 6px; }
.hub-title { text-align: center; font-size: 26px; font-weight: 700; margin-bottom: 36px; }
.product-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 560px) { .product-grid { grid-template-columns: 1fr; } }
.product-tile { background: var(--surface); border: 1px solid var(--border); padding: 28px 22px; cursor: pointer; transition: all 0.15s ease; text-align: left; position: relative; }
.product-tile:hover { border-color: var(--primary); transform: translateY(-2px); }
.product-tile-icon { width: 42px; height: 42px; background: var(--primary); display: flex; align-items: center; justify-content: center; margin-bottom: 18px; }
.product-tile-icon svg { width: 21px; height: 21px; stroke: #fff; }
.product-tile-title { font-size: 16px; font-weight: 700; margin-bottom: 5px; }
.product-tile-sub { font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 14px; }
.product-tile-arrow { font-size: 12px; color: var(--accent); font-weight: 700; }

/* ===== PRODUCT VIEWS ===== */
.product-view-inner { max-width: 480px; margin: 0 auto; padding: 32px 24px 60px; }
.product-view-header { text-align: center; margin-bottom: 28px; }
.product-view-eyebrow { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--text-dim); font-family: var(--font-mono); margin-bottom: 4px; }
.product-view-title { font-size: 22px; font-weight: 700; }

.panel { background: var(--surface); border: 1px solid var(--border); padding: 24px 22px; margin-bottom: 14px; }
.panel-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-dim); font-family: var(--font-mono); margin-bottom: 14px; }

.btn { width: 100%; padding: 14px; background: var(--primary); color: #fff; border: none; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; cursor: pointer; transition: var(--transition); }
.btn:hover { background: var(--accent); }
.btn-secondary { background: transparent; color: var(--text); border: 1px solid var(--border-strong); }
.btn-secondary:hover { background: var(--surface-muted); color: var(--text); }
.btn-danger { background: var(--danger); color: #fff; }
.btn-danger:hover { background: #a02020; }
.btn-row { display: flex; gap: 10px; margin-top: 16px; }
.btn-row .btn { margin-top: 0; }

/* ===== CARD VIEW ===== */
.card-visual { width: 100%; aspect-ratio: 1.586/1; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); margin-bottom: 22px; position: relative; cursor: default; border-radius: 0; }
.card-visual-mark { position: absolute; top: 16px; left: 18px; color: #fff; font-weight: 800; font-size: 12px; letter-spacing: 0.02em; }
.card-visual-mark sup { color: var(--accent); font-size: 8px; }
.card-visual-status { position: absolute; top: 16px; right: 18px; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.55); border: 1px solid rgba(255,255,255,0.25); padding: 3px 7px; }
.card-visual-status.active { color: var(--accent); border-color: rgba(0,168,120,0.5); }
.card-visual-number { position: absolute; bottom: 16px; left: 18px; color: rgba(255,255,255,0.9); font-family: var(--font-mono); font-size: 14px; letter-spacing: 0.1em; font-weight: 600; }
.card-visual-name { position: absolute; bottom: 16px; right: 18px; font-size: 10px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.05em; }

.qr-tap-target { text-align: center; cursor: pointer; padding: 8px; transition: opacity 0.15s ease; }
.qr-tap-target:hover { opacity: 0.75; }
.qr-frame-sm { background: #fff; padding: 12px; border: 1px solid var(--border-strong); display: inline-block; }
.qr-caption { font-size: 11px; color: var(--text-dim); margin-top: 10px; }
.qr-tap-hint { font-size: 10px; color: var(--accent); font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 4px; }

/* ===== SOURCE ROWS (card & toolbox) ===== */
.source-row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); }
.source-row:last-child { border-bottom: none; }
.source-tile { width: 32px; height: 32px; background: var(--surface-muted); border: 1px solid var(--border-strong); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; font-family: var(--font-mono); flex-shrink: 0; }
.source-info { flex: 1; min-width: 0; }
.source-inst { font-size: 13px; font-weight: 700; }
.source-ident { font-size: 11px; color: var(--text-dim); }
.source-action { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--accent); background: none; border: none; cursor: pointer; padding: 4px 0; }
.source-action:hover { text-decoration: underline; }
.source-status-badge { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; padding: 2px 7px; border: 1px solid; }
.source-status-badge.open { color: var(--accent); border-color: var(--accent); }
.source-status-badge.pending { color: var(--warning); border-color: var(--warning); }
.source-status-badge.spent { color: var(--text-muted); border-color: var(--border-strong); }
.source-status-badge.expired { color: var(--text-dim); border-color: var(--border-strong); }

.unhook-link { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--danger); background: none; border: none; cursor: pointer; margin-left: 8px; }
.unhook-link:hover { text-decoration: underline; }

/* ===== UNHOOK VIEW ===== */
.unhook-summary { border: 1px solid var(--border); padding: 20px; text-align: center; margin-bottom: 18px; background: var(--surface); }
.unhook-summary .amount { font-size: 24px; font-weight: 600; font-family: var(--font-mono); color: var(--accent); }
.unhook-summary .inst { font-size: 11px; color: var(--text-dim); margin-bottom: 4px; }

/* ===== SWAP VIEW ===== */
.swap-source-types { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 16px; }
.swap-source-type-opt { border: 1px solid var(--border-strong); padding: 12px 6px; text-align: center; cursor: pointer; font-size: 10px; font-weight: 700; transition: var(--transition); }
.swap-source-type-opt:hover { background: var(--surface-muted); }
.swap-source-type-opt.active { background: var(--primary); color: #fff; }
.swap-source-type-opt .icon { font-size: 16px; display: block; margin-bottom: 4px; }

.card-source-breakdown { border: 1px solid var(--border); padding: 16px; margin-bottom: 14px; background: var(--surface); }
.card-source-breakdown-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 12px; }
.card-source-breakdown-row:last-child { border-bottom: none; }
.strategy-row { display: flex; border: 1px solid var(--border-strong); margin-bottom: 14px; }
.strategy-row button { flex: 1; padding: 10px 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: transparent; border: none; border-right: 1px solid var(--border-strong); color: var(--text-muted); cursor: pointer; font-family: var(--font); transition: var(--transition); }
.strategy-row button:last-child { border-right: none; }
.strategy-row button.active { background: var(--primary); color: #fff; }
.strategy-row button:hover:not(.active) { background: var(--surface-muted); }

.swap-amount-row { display: flex; align-items: center; justify-content: center; gap: 6px; margin-bottom: 16px; font-size: 22px; font-weight: 600; }
.swap-amount-row input { width: 100px; border: none; border-bottom: 1px solid var(--text); background: transparent; font-size: 22px; font-weight: 600; font-family: var(--font-mono); color: var(--text); text-align: center; padding: 0 2px 1px; }
.swap-amount-row input:focus { outline: none; border-bottom-color: var(--accent); color: var(--accent); }

/* ===== HOOK BUILDER ===== */
.hook-mode-row { display: flex; border: 1px solid var(--border-strong); margin-bottom: 18px; }
.hook-mode-row button { flex: 1; padding: 11px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; background: transparent; border: none; color: var(--text-muted); cursor: pointer; border-right: 1px solid var(--border-strong); font-family: var(--font); transition: var(--transition); }
.hook-mode-row button:last-child { border-right: none; }
.hook-mode-row button.active { background: var(--primary); color: #fff; }
.hook-mode-row button:hover:not(.active) { background: var(--surface-muted); }

.source-type-picker { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
.source-type-opt { border: 1px solid var(--border-strong); padding: 14px 12px; text-align: center; cursor: pointer; font-size: 12px; font-weight: 700; transition: var(--transition); }
.source-type-opt:hover { background: var(--surface-muted); }
.source-type-opt.active { background: var(--primary); color: #fff; }
.source-type-opt.disabled { opacity: 0.4; cursor: not-allowed; }
.source-type-opt .icon { font-size: 18px; display: block; margin-bottom: 6px; }
.source-type-opt .note { font-size: 9px; font-weight: 500; text-transform: none; color: var(--text-dim); margin-top: 4px; display: block; }
.source-type-opt.active .note { color: rgba(255,255,255,0.7); }

.hook-row-card { border: 1px solid var(--border); padding: 14px; margin-bottom: 10px; background: var(--surface); }
.hook-row-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.hook-row-label { font-size: 11px; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; }
.hook-row-remove { font-size: 11px; color: var(--danger); background: none; border: none; cursor: pointer; font-weight: 700; }
.field-group { margin-bottom: 10px; }
.field-group label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--text-dim); margin-bottom: 5px; letter-spacing: 0.04em; }
.field-group input, .field-group select { width: 100%; padding: 10px 12px; border: 1px solid var(--border-strong); font-size: 13px; font-family: var(--font); background: var(--surface); color: var(--text); transition: var(--transition); }
.field-group input:focus, .field-group select:focus { outline: none; border-color: var(--accent); }
.field-group input:disabled { background: var(--surface-muted); color: var(--text-dim); cursor: not-allowed; }

.add-row-btn { width: 100%; padding: 12px; border: 1px dashed var(--border-strong); background: transparent; color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; cursor: pointer; margin-bottom: 16px; font-family: var(--font); transition: var(--transition); }
.add-row-btn:hover { border-color: var(--accent); color: var(--accent); }

.entry-point-note { font-size: 11px; color: var(--text-dim); text-align: center; margin-bottom: 18px; }
.hook-note-small { font-size: 11px; color: var(--text-dim); text-align: center; margin-top: 14px; }

/* ===== FULL-SCREEN QR ===== */
#qrFullView .qr-full-wrap { max-width: 420px; margin: 0 auto; padding: 60px 24px; text-align: center; }
.qr-frame-lg { background: #fff; padding: 28px; border: 1px solid var(--border-strong); display: inline-block; margin-bottom: 24px; }
.qr-full-suffix { font-family: var(--font-mono); font-size: 16px; font-weight: 700; letter-spacing: 0.08em; margin-bottom: 4px; }
.qr-full-name { font-size: 13px; color: var(--text-muted); }

/* ===== THEME PICKER ===== */
.theme-picker { display: flex; gap: 8px; flex-wrap: wrap; }
.theme-chip { flex: 1; min-width: 100px; border: 2px solid var(--border-strong); padding: 12px 10px; text-align: center; cursor: pointer; font-size: 11px; font-weight: 700; transition: var(--transition); background: var(--surface); }
.theme-chip.active { border-color: var(--accent); }
.theme-chip .swatch { display: flex; gap: 3px; justify-content: center; margin-bottom: 6px; }
.theme-chip .dot { width: 14px; height: 14px; border-radius: 50%; }

/* ===== ACTIVITY ===== */
.activity-empty { text-align: center; color: var(--text-dim); font-size: 12px; padding: 40px 24px; }

/* ===== MODAL OVERLAY ===== */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(4,18,14,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal { background: var(--surface); max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; border: 1px solid var(--border-strong); animation: popIn 0.22s ease; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 22px; background: var(--surface-muted); border-bottom: 1px solid var(--border); }
.modal-header h2 { font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text); font-family: var(--font-mono); }
.modal-close { background: none; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer; line-height: 1; padding: 4px; }
#modalBody { padding: 22px; }
.modal.wide { max-width: 760px; }

/* ===== CONFIRM OVERLAY ===== */
.confirm-overlay { display: none; position: fixed; inset: 0; background: rgba(4,18,14,0.55); z-index: 1100; align-items: center; justify-content: center; padding: 20px; }
.confirm-overlay.active { display: flex; }
.confirm-box { background: var(--surface); border: 1px solid var(--border-strong); max-width: 340px; width: 100%; padding: 20px; animation: popIn 0.18s ease; text-align: center; }
.confirm-box p { font-size: 13px; color: var(--text); margin-bottom: 16px; line-height: 1.5; }
.confirm-box .btn-row { margin-top: 0; }

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
    .product-view-inner { padding: 20px 16px 40px; }
    .panel { padding: 18px 16px; }
    .swap-source-types { grid-template-columns: 1fr 1fr; }
    .source-type-picker { grid-template-columns: 1fr 1fr; }
    .theme-picker .theme-chip { min-width: 80px; padding: 10px 6px; }
    .hub-section { padding: 32px 16px 40px; }
}
</style>
</head>
<body>

<header class="site-header">
    <div class="header-inner">
        <div class="brand" onclick="goView('hub')">
            <div class="brand-text">
                <div class="logo">VouchMorph<sup>TM</sup></div>
                <div class="tagline">Money, swapped simply</div>
            </div>
            <div class="brand-divider"></div>
        </div>
        <button class="back-btn" id="backBtn" onclick="goBack()">&larr; <span id="backLabel">All products</span></button>
        <nav class="main-nav" aria-label="Main">
            <span class="nav-pill active" onclick="goView('swap')">Move money</span>
            <button type="button" class="nav-link" onclick="goView('activity')">Activity</button>
        </nav>
        <div class="header-actions">
            <div class="header-meta">
                <span class="role-badge" id="userRoleBadge">USER</span>
                <span class="agent-badge" id="agentBadge" style="display:none;">Agent</span>
                <span class="test-mode-badge" id="testModeBadge" style="display:none;">Test mode</span>
                <button type="button" class="toolbox-btn" onclick="goView('toolbox')">
                    Toolbox
                    <span id="toolboxBadge" class="toolbox-badge" style="display:none;"></span>
                </button>
            </div>
            <div class="user-chip" onclick="goView('toolbox')">
                <div class="user-chip-text">
                    <span class="user-chip-name" id="userName">Thabo</span>
                    <span class="user-chip-role" id="userRoleText">User &middot; BW</span>
                </div>
                <div class="user-avatar" aria-hidden="true">TH</div>
            </div>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
</header>

<!-- ============================== HUB VIEW ============================== -->
<div class="view active" id="hubView">
    <div class="hub-section">
        <div class="hub-eyebrow">VouchMorph</div>
        <div class="hub-title">What do you want to do?</div>
        <div class="product-grid">
            <div class="product-tile" onclick="goView('swap')">
                <div class="product-tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M7 10l5-5 5 5M7 14l5 5 5-5"/></svg></div>
                <div class="product-tile-title">Swap</div>
                <div class="product-tile-sub">Move money between accounts, wallets, cards, or straight to someone's identity.</div>
                <div class="product-tile-arrow">Open Swap &rsaquo;</div>
            </div>
            <div class="product-tile" onclick="goView('card')">
                <div class="product-tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="2" y="5" width="20" height="14"/><path d="M2 10h20"/></svg></div>
                <div class="product-tile-title">Card</div>
                <div class="product-tile-sub">Your VouchMorph Card — hook one or many sources, share the QR, split a swap.</div>
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
</div>

<!-- ============================== SWAP VIEW ============================== -->
<div class="view" id="swapView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Move money</div>
            <div class="product-view-title">Swap</div>
        </div>
        <div class="panel">
            <div class="swap-amount-row">
                Swap <input type="number" id="swapAmount" placeholder="0.00" step="0.01" min="0.01" oninput="updateSwapPreview()">
                <span id="swapCurrencyLabel" style="font-size:14px;color:var(--text-dim);">BWP</span>
                from
            </div>

            <div class="swap-source-types">
                <div class="swap-source-type-opt" onclick="setSwapSourceType('WALLET')" id="swapSrcWallet"><span class="icon">💳</span>Wallet</div>
                <div class="swap-source-type-opt" onclick="setSwapSourceType('CARD')" id="swapSrcCard"><span class="icon">🪪</span>Card</div>
                <div class="swap-source-type-opt" onclick="setSwapSourceType('VOUCHER')" id="swapSrcVoucher"><span class="icon">🎟️</span>Voucher</div>
                <div class="swap-source-type-opt active" onclick="setSwapSourceType('VMCARD')" id="swapSrcVmcard"><span class="icon">🧩</span>My Card</div>
            </div>

            <div id="swapSourceBody"></div>

            <button class="btn" id="swapReviewBtn" onclick="previewSwap()">Review swap</button>
            <div id="swapReadinessHint" style="text-align:center;font-size:12px;color:var(--text-muted);margin-top:10px;display:none;"></div>
        </div>

        <div style="text-align:center;margin-top:8px;">
            <button type="button" class="source-action" onclick="openIdentitySendModal()">Swap to identity</button>
            <span style="color:var(--text-dim);margin:0 8px;">·</span>
            <button type="button" class="source-action" onclick="openTabBuilder()">Combine sources</button>
        </div>
    </div>
</div>

<!-- ============================== CARD VIEW ============================== -->
<div class="view" id="cardView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">My VouchMorph Card</div>
            <div class="product-view-title">Card</div>
        </div>

        <div class="panel" style="text-align:center;">
            <div class="card-visual">
                <div class="card-visual-mark">VouchMorph<sup>TM</sup></div>
                <div class="card-visual-status active">Active</div>
                <div class="card-visual-number">•••• •••• •••• 8841</div>
                <div class="card-visual-name">Thabo's Card</div>
            </div>

            <div class="qr-tap-target" onclick="goQrFull()">
                <div class="qr-frame-sm" id="qrHolderSm"></div>
                <div class="qr-caption">Scan to hook a source to this card</div>
                <div class="qr-tap-hint">Tap to view full screen &rsaquo;</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">Hooked sources — <span id="hookedTotal">2,450.00 BWP</span></div>
            <div id="hookedSourcesList">
                <div class="source-row">
                    <div class="source-tile">ZB</div>
                    <div class="source-info"><div class="source-inst">Zuru Bank</div><div class="source-ident">•••• 4471 · you</div></div>
                    <div style="text-align:right;">
                        <div style="font-family:var(--font-mono);font-weight:700;color:var(--accent);">1,500.00</div>
                        <div style="margin-top:4px;"><span class="source-status-badge open">Open</span><button class="unhook-link" onclick="openUnhook('Zuru Bank', '•••• 4471', '1,500.00', 'open')">Unhook</button></div>
                    </div>
                </div>
                <div class="source-row">
                    <div class="source-tile">CC</div>
                    <div class="source-info"><div class="source-inst">CazaCom</div><div class="source-ident">+267 71••• 567</div></div>
                    <div style="text-align:right;">
                        <div style="font-family:var(--font-mono);font-weight:700;color:var(--accent);">950.00</div>
                        <div style="margin-top:4px;"><span class="source-status-badge open">Open</span><button class="unhook-link" onclick="openUnhook('CazaCom', '+267 71••• 567', '950.00', 'open')">Unhook</button></div>
                    </div>
                </div>
            </div>
        </div>

        <button class="btn" onclick="openHookBuilder('card')">Hook a source</button>
        <button class="btn btn-secondary" style="margin-top:10px;" onclick="goView('swap'); setTimeout(()=>setSwapSourceType('VMCARD'), 50);">Use this card as a Swap source</button>
    </div>
</div>

<!-- ============================== UNHOOK VIEW ============================== -->
<div class="view" id="unhookView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Release a hold</div>
            <div class="product-view-title">Unhook source</div>
        </div>
        <div class="unhook-summary">
            <div class="inst" id="unhookInstLine">Zuru Bank · •••• 4471</div>
            <div class="amount" id="unhookAmountLine">1,500.00 BWP</div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:6px;" id="unhookStatusLine">Status: Open</div>
        </div>
        <div class="panel" style="font-size:12px;color:var(--text-muted);">
            <p style="margin-bottom:8px;">If this source hasn't been spent yet and is eligible, unhooking releases the hold immediately and it stops counting toward this card's available balance.</p>
            <p style="font-size:11px;color:var(--text-dim);">If it's not yet eligible, this request queues until the hold's 24-hour window ends. There's no way to release it early once hooked.</p>
        </div>
        <button class="btn btn-danger" id="unhookConfirmBtn" onclick="confirmUnhook()">Unhook this source</button>
        <button class="btn btn-secondary" style="margin-top:10px;" onclick="goBack()">Keep it hooked</button>
    </div>
</div>

<!-- ============================== FULL-SCREEN QR ============================== -->
<div class="view" id="qrFullView">
    <div class="qr-full-wrap">
        <div class="qr-frame-lg" id="qrHolderLg"></div>
        <div class="qr-full-suffix">•••• 8841</div>
        <div class="qr-full-name">Thabo's Card</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:20px;">Anyone with VouchMorph can scan this to hook their own source to your card.</div>
        <button class="btn btn-secondary" style="margin-top:24px;" onclick="goBack()">Back to card</button>
    </div>
</div>

<!-- ============================== HOOK BUILDER ============================== -->
<div class="view" id="hookView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow" id="hookEyebrow">Hook to My VouchMorph Card</div>
            <div class="product-view-title">Hook a source</div>
        </div>

        <div class="entry-point-note" id="hookEntryNote">Started from the Card view — building the list of sources to hook.</div>

        <div class="hook-mode-row">
            <button class="active" id="hookModeSingleBtn" onclick="setHookMode('single')">One source</button>
            <button id="hookModeMultiBtn" onclick="setHookMode('multi')">Multiple sources</button>
        </div>

        <div id="hookRowsHolder"></div>

        <button class="add-row-btn" id="addHookRowBtn" style="display:none;" onclick="addHookRow()">+ Add another source</button>

        <button class="btn" onclick="confirmHook()">Hook to card</button>
        <div class="hook-note-small">Each source is held for 24 hours per its own authorized amount. There's no way to release a hook early once confirmed.</div>
    </div>
</div>

<!-- ============================== ACTIVITY VIEW ============================== -->
<div class="view" id="activityView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Your history</div>
            <div class="product-view-title">Activity</div>
        </div>
        <div class="activity-empty">No swaps yet. Your first swap will show up here.</div>
    </div>
</div>

<!-- ============================== TOOLBOX VIEW ============================== -->
<div class="view" id="toolboxView">
    <div class="product-view-inner">
        <div class="product-view-header">
            <div class="product-view-eyebrow">Settings</div>
            <div class="product-view-title">Toolbox</div>
        </div>

        <div class="panel">
            <div class="panel-title">My sources</div>
            <div class="source-row">
                <div class="source-tile">ZB</div>
                <div class="source-info"><div class="source-inst">Zuru Bank</div><div class="source-ident">Account •••• 4471</div></div>
                <button class="source-action" onclick="openHookBuilder('source', 'Zuru Bank', 'ACCOUNT', '•••• 4471')">Hook to card &rsaquo;</button>
            </div>
            <div class="source-row">
                <div class="source-tile">CC</div>
                <div class="source-info"><div class="source-inst">CazaCom</div><div class="source-ident">Wallet +267 71••• 567</div></div>
                <button class="source-action" onclick="openHookBuilder('source', 'CazaCom', 'WALLET', '+267 71••• 567')">Hook to card &rsaquo;</button>
            </div>
            <div class="source-row">
                <div class="source-tile">ID</div>
                <div class="source-info"><div class="source-inst">Identity claim</div><div class="source-ident">300.00 BWP available</div></div>
                <button class="source-action" onclick="openHookBuilder('source', 'Identity claim', 'IDENTITY', '300.00 BWP available')">Hook to card &rsaquo;</button>
            </div>
            <div style="margin-top:12px;"><button class="btn btn-secondary" style="padding:10px;" onclick="openAddSource()">+ Add a source</button></div>
        </div>

        <div class="panel">
            <div class="panel-title">Theme</div>
            <div class="theme-picker">
                <div class="theme-chip active" data-theme="classic" onclick="setTheme('classic')">
                    <div class="swatch"><div class="dot" style="background:#10201C;"></div><div class="dot" style="background:#00A878;"></div><div class="dot" style="background:#FAF9F6;border:1px solid #ddd;"></div></div>
                    Classic
                </div>
                <div class="theme-chip" data-theme="women" onclick="setTheme('women')">
                    <div class="swatch"><div class="dot" style="background:#7A3B57;"></div><div class="dot" style="background:#E08FA4;"></div><div class="dot" style="background:#FFF7F5;border:1px solid #ddd;"></div></div>
                    Women
                </div>
                <div class="theme-chip" data-theme="kids" onclick="setTheme('kids')">
                    <div class="swatch"><div class="dot" style="background:#1E3A8A;"></div><div class="dot" style="background:#FFB400;"></div><div class="dot" style="background:#F0FBFF;border:1px solid #ddd;"></div></div>
                    Kids
                </div>
                <div class="theme-chip" data-theme="alpha" onclick="setTheme('alpha')">
                    <div class="swatch"><div class="dot" style="background:#0A0A0A;"></div><div class="dot" style="background:#D6242C;"></div><div class="dot" style="background:#151515;border:1px solid #333;"></div></div>
                    Alpha
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">Account</div>
            <div style="display:flex;flex-direction:column;gap:6px;">
                <button class="source-action" style="text-align:left;font-size:12px;padding:6px 0;" onclick="openHelpModal()">❓ Help</button>
                <button class="source-action" style="text-align:left;font-size:12px;padding:6px 0;" onclick="openTermsModal()">📄 Terms &amp; Conditions</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================== MODALS ============================== -->
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" id="modalContent">
        <div class="modal-header">
            <h2 id="modalTitle">Modal</h2>
            <button class="modal-close" onclick="closeModal()" aria-label="Close">&times;</button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <p id="confirmBoxText"></p>
        <div class="btn-row">
            <button class="btn btn-secondary" id="confirmBoxCancel">Cancel</button>
            <button class="btn" id="confirmBoxOk" style="background:var(--danger);">Confirm</button>
        </div>
    </div>
</div>

<script>
// ============================================================
// THEME SYSTEM
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

// Load saved theme
(function() {
    try {
        const saved = localStorage.getItem('vm_theme');
        if (saved && THEMES[saved]) setTheme(saved);
    } catch (e) {}
})();

// ============================================================
// VIEW NAVIGATION
// ============================================================
const VIEW_LABELS = {
    hub: ['VouchMorph', null],
    swap: ['Swap', 'All products'],
    card: ['Card', 'All products'],
    activity: ['Activity', 'All products'],
    toolbox: ['Toolbox', 'All products'],
    hook: ['Hook a source', null],
    qrfull: ['Card QR', null],
    unhook: ['Unhook source', null],
};

let viewStack = ['hub'];

function goView(name) {
    if (name === viewStack[viewStack.length - 1]) return;
    viewStack.push(name);
    renderView();
}

function goQrFull() {
    viewStack.push('qrfull');
    renderView();
    // Re-render QR in the larger container
    setTimeout(() => {
        const el = document.getElementById('qrHolderLg');
        if (el && typeof QRCode !== 'undefined') {
            el.innerHTML = '';
            new QRCode(el, { text: 'vouchmorph://card/8841', width: 260, height: 260 });
        }
    }, 50);
}

function goBack() {
    if (viewStack.length <= 1) return;
    viewStack.pop();
    renderView();
}

function renderView() {
    const current = viewStack[viewStack.length - 1];
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    const target = document.getElementById(current + 'View');
    if (target) target.classList.add('active');

    const backBtn = document.getElementById('backBtn');
    const brandLabel = document.getElementById('brandLabel')?.querySelector('.logo') || document.querySelector('.brand .logo');
    const brandTagline = document.querySelector('.brand .tagline');

    if (current === 'hub') {
        backBtn.classList.remove('show');
        if (brandLabel) brandLabel.textContent = 'VouchMorph';
        if (brandTagline) brandTagline.textContent = 'Money, swapped simply';
    } else {
        backBtn.classList.add('show');
        const prev = viewStack[viewStack.length - 2];
        const backLabel = document.getElementById('backLabel');
        if (backLabel) backLabel.textContent = prev === 'hub' ? 'All products' : (VIEW_LABELS[prev]?.[0] || 'Back');
        const label = VIEW_LABELS[current];
        if (brandLabel) brandLabel.textContent = label?.[0] || current;
        if (brandTagline) brandTagline.textContent = '';
    }

    // Re-render dynamic content if needed
    if (current === 'swap') renderSwapCardBreakdown();
    if (current === 'card') renderHookedSources();
}

// ============================================================
// SWAP — My Card as a source
// ============================================================
const MOCK_HOOKED = [
    { inst: 'Zuru Bank', ident: '•••• 4471', held: 1500.00 },
    { inst: 'CazaCom', ident: '+267 71••• 567', held: 950.00 },
];
let swapCardStrategy = 'SMART';
let swapSourceType = 'VMCARD';

function setSwapSourceType(type) {
    swapSourceType = type;
    ['swapSrcWallet', 'swapSrcCard', 'swapSrcVoucher', 'swapSrcVmcard'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('active', id === 'swapSrc' + type.charAt(0) + type.slice(1).toLowerCase());
    });
    const body = document.getElementById('swapSourceBody');
    if (type !== 'VMCARD') {
        body.innerHTML = `<div style="text-align:center;font-size:12px;color:var(--text-dim);padding:20px 0;">Normal single-source flow for ${type.toLowerCase()} — select a source and enter details.</div>`;
        return;
    }
    renderSwapCardBreakdown();
}

function setSwapCardStrategy(s) {
    swapCardStrategy = s;
    renderSwapCardBreakdown();
}

function renderSwapCardBreakdown() {
    const body = document.getElementById('swapSourceBody');
    if (!body || swapSourceType !== 'VMCARD') return;

    const total = MOCK_HOOKED.reduce((sum, s) => sum + s.held, 0);
    const rows = MOCK_HOOKED.map(s => `
        <div class="card-source-breakdown-row">
            <span>${s.inst} · ${s.ident}</span>
            <span style="font-family:var(--font-mono);font-weight:700;">${s.held.toFixed(2)}</span>
        </div>`).join('');

    body.innerHTML = `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Drawing from everything currently hooked to your card — behaves exactly like Combine sources.</div>
        <div class="strategy-row">
            <button class="${swapCardStrategy==='SMART'?'active':''}" onclick="setSwapCardStrategy('SMART')">Smart</button>
            <button class="${swapCardStrategy==='EQUAL'?'active':''}" onclick="setSwapCardStrategy('EQUAL')">Equal</button>
            <button class="${swapCardStrategy==='RATIO'?'active':''}" onclick="setSwapCardStrategy('RATIO')">Ratio</button>
            <button class="${swapCardStrategy==='MANUAL'?'active':''}" onclick="setSwapCardStrategy('MANUAL')">Manual</button>
        </div>
        <div class="card-source-breakdown">
            ${rows}
            <div class="card-source-breakdown-row" style="border-bottom:none;padding-top:10px;font-weight:700;">
                <span>Total available</span>
                <span style="font-family:var(--font-mono);color:var(--accent);">${total.toFixed(2)} BWP</span>
            </div>
        </div>
        <button class="btn" onclick="alert('Preview swap from My Card — pulls from hooked sources per the ${swapCardStrategy} strategy. (mock)')">Review swap</button>
        <div id="swapReadinessHint" style="text-align:center;font-size:12px;color:var(--text-muted);margin-top:10px;"></div>`;
}

function updateSwapPreview() {
    const amt = document.getElementById('swapAmount')?.value || '0';
    // Update any preview text if needed
}

function previewSwap() {
    const amt = document.getElementById('swapAmount')?.value;
    if (!amt || parseFloat(amt) <= 0) {
        showMessage('Enter an amount first.', 'warning');
        return;
    }
    if (swapSourceType === 'VMCARD') {
        alert(`Reviewing swap of ${amt} BWP from My Card (${swapCardStrategy} strategy). (mock)`);
    } else {
        alert(`Reviewing swap of ${amt} BWP from ${swapSourceType.toLowerCase()}. (mock)`);
    }
}

function openIdentitySendModal() {
    openModal('Swap to identity', `
        <div class="field-group"><label>Identity type</label>
            <select id="identityType">
                <option value="national_id">National ID</option>
                <option value="birth_certificate">Birth Certificate</option>
                <option value="voter_id">Voter ID</option>
                <option value="phone">Phone Number</option>
                <option value="email">Email</option>
            </select>
        </div>
        <div class="field-group"><label>Recipient identity</label>
            <input id="identityValue" placeholder="ID number, phone, or email">
        </div>
        <div class="field-group"><label>SMS notification (optional)</label>
            <input id="identitySms" placeholder="Phone to send SMS notification">
        </div>
        <div style="background:var(--accent-soft);border-left:3px solid var(--accent);padding:10px 14px;font-size:12px;margin-bottom:14px;">
            The recipient will be notified and can claim the funds within 24 hours.
        </div>
        <div class="btn-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn" onclick="alert('Swap to identity confirmed. (mock)');closeModal();">Send</button></div>
    `);
}

function openTabBuilder() {
    openModal('Combine sources', `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Build a multi-source swap. Smart mode is recommended.</div>
        <div class="strategy-row" style="margin-bottom:14px;">
            <button class="active" style="flex:1;padding:10px;font-size:10px;font-weight:700;text-transform:uppercase;background:var(--primary);color:#fff;border:none;border-right:1px solid var(--border-strong);cursor:pointer;">Smart</button>
            <button style="flex:1;padding:10px;font-size:10px;font-weight:700;text-transform:uppercase;background:transparent;border:none;border-right:1px solid var(--border-strong);color:var(--text-muted);cursor:pointer;">Equal</button>
            <button style="flex:1;padding:10px;font-size:10px;font-weight:700;text-transform:uppercase;background:transparent;border:none;border-right:1px solid var(--border-strong);color:var(--text-muted);cursor:pointer;">Ratio</button>
            <button style="flex:1;padding:10px;font-size:10px;font-weight:700;text-transform:uppercase;background:transparent;border:none;color:var(--text-muted);cursor:pointer;">Manual</button>
        </div>
        <div style="border:1px solid var(--border);padding:16px;margin-bottom:14px;background:var(--surface);">
            <div class="card-source-breakdown-row"><span>Zuru Bank · •••• 4471</span><span style="font-family:var(--font-mono);">0.00</span></div>
            <div class="card-source-breakdown-row"><span>CazaCom · +267 71••• 567</span><span style="font-family:var(--font-mono);">0.00</span></div>
            <div class="card-source-breakdown-row" style="border-bottom:none;padding-top:10px;font-weight:700;">
                <span>Total</span><span style="font-family:var(--font-mono);color:var(--accent);">0.00 BWP</span>
            </div>
        </div>
        <button class="add-row-btn" style="margin-bottom:14px;">+ Add source</button>
        <div class="btn-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn" onclick="alert('Multi-source swap preview. (mock)');closeModal();">Review swap</button></div>
    `);
}

// ============================================================
// CARD — Hooked sources with Unhook
// ============================================================
function renderHookedSources() {
    // Re-render the hooked sources list if needed
    // Static for mock, but dynamic in real app
}

function openUnhook(inst, ident, amount, status) {
    document.getElementById('unhookInstLine').textContent = `${inst} · ${ident}`;
    document.getElementById('unhookAmountLine').textContent = `${amount} BWP`;
    document.getElementById('unhookStatusLine').textContent = `Status: ${status.charAt(0).toUpperCase() + status.slice(1)}`;
    goView('unhook');
}

function confirmUnhook() {
    const inst = document.getElementById('unhookInstLine').textContent;
    showConfirm(`Release this hold from ${inst}?`, () => {
        alert(`Unhook requested for ${inst}. (mock)`);
        goBack();
    });
}

// ============================================================
// HOOK BUILDER
// ============================================================
const ASSET_TYPES = [
    { key: 'ACCOUNT', label: 'Account', icon: '🏦' },
    { key: 'WALLET', label: 'Wallet', icon: '📱' },
    { key: 'CASHOUT_VOUCHER', label: 'Cashout voucher', icon: '🎟️' },
    { key: 'IDENTITY', label: 'Identity claim', icon: '🪪', note: 'Needs a claimed swap' },
];
const IDENTITY_CLAIM_AVAILABLE = true;
let hookMode = 'single';
let hookRows = [];
let hookRowSeq = 0;
let hookEntry = { source: 'card' };

function openHookBuilder(entryType, instName, assetType, identifier) {
    hookEntry = { source: entryType, instName, assetType, identifier };
    hookRows = [];
    hookRowSeq = 0;
    hookMode = 'single';

    document.getElementById('hookEyebrow').textContent = entryType === 'card' ? 'Hook to My VouchMorph Card' : 'Hook this source to a card';
    document.getElementById('hookEntryNote').textContent = entryType === 'card'
        ? 'Started from the Card view — building the list of sources to hook.'
        : `Started from "${instName}" in My sources — this source is pre-filled below.`;

    if (entryType === 'source') {
        addHookRow({ instName, assetType, identifier, locked: true });
    } else {
        addHookRow();
    }

    setHookMode('single');
    goView('hook');
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
    hookRows.push({
        id: ++hookRowSeq,
        instName: prefill?.instName || null,
        assetType: prefill?.assetType || null,
        identifier: prefill?.identifier || '',
        amount: '',
        locked: !!prefill?.locked,
    });
    renderHookRows();
}

function removeHookRow(id) {
    hookRows = hookRows.filter(r => r.id !== id);
    renderHookRows();
}

function setHookRowAssetType(id, type) {
    const row = hookRows.find(r => r.id === id);
    if (!row) return;
    if (type === 'IDENTITY' && !IDENTITY_CLAIM_AVAILABLE) return;
    row.assetType = type;
    row.identifier = '';
    renderHookRows();
}

function renderHookRows() {
    const holder = document.getElementById('hookRowsHolder');
    if (!holder) return;

    holder.innerHTML = hookRows.map((row, idx) => {
        if (row.locked) {
            return `
                <div class="hook-row-card">
                    <div class="hook-row-head">
                        <span class="hook-row-label">Source ${idx + 1} — from My sources</span>
                    </div>
                    <div style="font-size:13px;font-weight:700;">${row.instName}</div>
                    <div style="font-size:11px;color:var(--text-dim);margin-bottom:10px;">${row.identifier}</div>
                    <div class="field-group"><label>Amount to authorize</label><input type="number" placeholder="0.00" value="${row.amount}" oninput="hookRows.find(r=>r.id===${row.id}).amount=this.value"></div>
                </div>`;
        }

        const typeOptions = ASSET_TYPES.map(t => {
            const disabled = t.key === 'IDENTITY' && !IDENTITY_CLAIM_AVAILABLE;
            return `<div class="source-type-opt ${row.assetType === t.key ? 'active' : ''} ${disabled ? 'disabled' : ''}" onclick="${disabled ? '' : `setHookRowAssetType(${row.id}, '${t.key}')`}">
                <span class="icon">${t.icon}</span>${t.label}
                ${t.note ? `<span class="note">${disabled ? t.note : 'Available now'}</span>` : ''}
            </div>`;
        }).join('');

        const identityNote = row.assetType === 'IDENTITY' ? '<div style="font-size:11px;color:var(--accent);margin-bottom:6px;">✓ Claimed identity balance available</div>' : '';

        return `
            <div class="hook-row-card">
                <div class="hook-row-head">
                    <span class="hook-row-label">Source ${idx + 1}</span>
                    ${hookMode === 'multi' && hookRows.length > 1 ? `<button class="hook-row-remove" onclick="removeHookRow(${row.id})">Remove</button>` : ''}
                </div>
                <div class="source-type-picker">${typeOptions}</div>
                ${row.assetType ? `
                    ${identityNote}
                    <div class="field-group"><label>${row.assetType === 'IDENTITY' ? 'Claimed identity balance' : 'Identifier'}</label>
                        <input placeholder="${row.assetType === 'IDENTITY' ? '300.00 BWP available' : 'Account, phone, or voucher number'}" value="${row.identifier}" ${row.assetType === 'IDENTITY' ? 'disabled' : ''} oninput="hookRows.find(r=>r.id===${row.id}).identifier=this.value">
                    </div>
                    <div class="field-group"><label>Amount to authorize</label>
                        <input type="number" placeholder="0.00" value="${row.amount}" oninput="hookRows.find(r=>r.id===${row.id}).amount=this.value">
                    </div>
                ` : ''}
            </div>`;
    }).join('');
}

function confirmHook() {
    const valid = hookRows.length > 0 && hookRows.every(r => (r.locked || r.assetType) && (r.amount || r.assetType === 'IDENTITY'));
    if (!valid) { showMessage('Fill in each source before hooking.', 'warning'); return; }
    showMessage(`Hooked ${hookRows.length} source(s) to the card.`, 'success');
    setTimeout(() => goBack(), 800);
}

// ============================================================
// ADD SOURCE (toolbox)
// ============================================================
function openAddSource() {
    openModal('Add a source', `
        <div style="margin-bottom:14px;font-size:12px;color:var(--text-muted);">Link your bank account, wallet, or card to use it as a swap source.</div>
        <div class="field-group"><label>Institution</label>
            <select><option value="">Select institution</option><option>Zuru Bank</option><option>CazaCom</option><option>First National</option></select>
        </div>
        <div class="field-group"><label>Asset type</label>
            <select><option value="">Select type</option><option>Account</option><option>Wallet</option><option>Card</option></select>
        </div>
        <div class="field-group"><label>Identifier</label>
            <input placeholder="Account number, phone, or card number">
        </div>
        <div class="field-group"><label>Account name (optional)</label>
            <input placeholder="e.g. My Main Account">
        </div>
        <div style="background:var(--accent-soft);border-left:3px solid var(--accent);padding:10px 14px;font-size:12px;margin-bottom:14px;">
            🔒 Your details are encrypted and only used to verify you own this account.
        </div>
        <div class="btn-row"><button class="btn btn-secondary" onclick="closeModal()">Cancel</button><button class="btn" onclick="alert('Source added. (mock)');closeModal();">Link source</button></div>
    `);
}

// ============================================================
// HELP & TERMS MODALS
// ============================================================
function openHelpModal() {
    openModal('Help', `
        <div style="font-size:13px;line-height:1.7;color:var(--text);">
            <p style="font-weight:700;margin-bottom:6px;">Swapping money</p>
            <ol style="padding-left:18px;margin-bottom:16px;">
                <li>Choose a source — Wallet/Account, Card, Voucher, or My Card (uses everything hooked to your card).</li>
                <li>Choose a destination or swap to an identity.</li>
                <li>Enter the amount and confirm. Nothing moves until you tap Confirm.</li>
            </ol>
            <p style="font-weight:700;margin-bottom:6px;">VouchMorph Card</p>
            <ol style="padding-left:18px;margin-bottom:16px;">
                <li>Open Card from the hub — every account gets one automatically.</li>
                <li>Hook sources to it — each source is held for 24 hours.</li>
                <li>Share the QR for others to hook their sources too.</li>
                <li>Start a swap from the card, choose a strategy (Smart recommended), and execute when covered.</li>
            </ol>
            <p style="font-weight:700;margin-bottom:6px;">Unhooking a source</p>
            <ol style="padding-left:18px;">
                <li>On the Card view, tap Unhook on any source.</li>
                <li>If eligible, it releases immediately. If not, it queues until the 24-hour window ends.</li>
                <li>There's no way to release it early once hooked — only hook what you're comfortable tying up.</li>
            </ol>
        </div>`);
}

function openTermsModal() {
    openModal('Terms and conditions', `
        <div style="font-size:13px;line-height:1.7;color:var(--text);">
            <p style="font-weight:700;margin-bottom:6px;">1. The service</p>
            <p style="margin-bottom:14px;">VouchMorph facilitates transfers, cashouts, and identity-based payments between participating institutions on your instruction.</p>
            <p style="font-weight:700;margin-bottom:6px;">2. Your responsibilities</p>
            <p style="margin-bottom:14px;">You are responsible for keeping your PIN, claim codes, and linked source credentials confidential.</p>
            <p style="font-weight:700;margin-bottom:6px;">3. Fees</p>
            <p style="margin-bottom:14px;">Applicable fees are shown before you confirm any swap.</p>
            <p style="font-weight:700;margin-bottom:6px;">4. Holds</p>
            <p style="margin-bottom:14px;">Sources hooked to a card are held for up to 24 hours. Holds release automatically when spent or after 24 hours.</p>
            <p style="margin-top:16px;color:var(--danger);font-size:11px;font-weight:700;">⚠ Placeholder — replace with reviewed legal terms.</p>
        </div>`);
}

// ============================================================
// MODAL SYSTEM
// ============================================================
function openModal(title, bodyHtml) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
    document.getElementById('modal').classList.add('active');
}

function closeModal() {
    document.getElementById('modal').classList.remove('active');
}

function showMessage(text, type = 'info') {
    const el = document.getElementById('swapReadinessHint') || document.createElement('div');
    if (!el.id) {
        el.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--surface);border:1px solid var(--border);padding:12px 20px;font-size:13px;font-weight:600;z-index:2000;max-width:90%;box-shadow:var(--shadow-md);';
        document.body.appendChild(el);
    }
    el.textContent = text;
    el.style.color = type === 'error' ? 'var(--danger)' : type === 'success' ? 'var(--success)' : 'var(--text)';
    el.style.display = 'block';
    clearTimeout(el._timer);
    el._timer = setTimeout(() => { el.style.display = 'none'; }, 5000);
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

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Render QR codes
    if (typeof QRCode !== 'undefined') {
        const smEl = document.getElementById('qrHolderSm');
        if (smEl) new QRCode(smEl, { text: 'vouchmorph://card/8841', width: 140, height: 140 });
        const lgEl = document.getElementById('qrHolderLg');
        if (lgEl) new QRCode(lgEl, { text: 'vouchmorph://card/8841', width: 260, height: 260 });
    }

    // Initialize swap view with My Card selected
    renderSwapCardBreakdown();
    renderView();
});

// Keyboard shortcuts
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (document.getElementById('modal').classList.contains('active')) {
            closeModal();
        } else if (document.getElementById('confirmOverlay').classList.contains('active')) {
            document.getElementById('confirmOverlay').classList.remove('active');
        } else if (viewStack.length > 1) {
            goBack();
        }
    }
});
</script>
</body>
</html>
