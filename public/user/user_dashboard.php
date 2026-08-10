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
<title>VouchMorph – Swap</title>
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
body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; line-height: 1.5; -webkit-font-smoothing: antialiased; }
input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; }
.fade-in-up { animation: fadeInUp 0.4s ease forwards; }
@keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.role-badge { font-size: 9px; color: var(--primary); border: 1px solid var(--border-strong); padding: 3px 8px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.04em; }
.agent-badge { font-size: 9px; color: #fff; background: var(--accent-2); padding: 3px 8px; text-transform: uppercase; font-weight: 700; }
.test-mode-badge { font-size: 9px; color: var(--danger); border: 1px solid rgba(198,40,40,0.35); background: rgba(198,40,40,0.06); padding: 3px 8px; text-transform: uppercase; font-weight: 700; }
.toolbox-badge { min-width: 18px; height: 18px; padding: 0 5px; background: var(--accent-2); color: #fff; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }

.site-header { position: sticky; top: 0; z-index: 100; background: var(--surface); border-bottom: 1px solid var(--border); }
.header-inner { max-width: var(--max-w); margin: 0 auto; padding: 0 24px; height: var(--header-h); display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.brand { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
.brand-text { display: flex; flex-direction: column; gap: 1px; }
.logo { font-size: 18px; font-weight: 700; letter-spacing: -0.01em; line-height: 1.1; color: var(--text); }
.logo sup { font-size: 9px; font-weight: 700; vertical-align: super; color: var(--accent); }
.tagline { font-size: 10px; font-weight: 600; color: var(--text-dim); letter-spacing: 0.06em; text-transform: uppercase; font-family: var(--font-mono); }
.brand-divider { width: 1px; height: 26px; background: var(--border-strong); display: none; }
@media (min-width: 640px) { .brand-divider { display: block; } }

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

.user-chip { display: flex; align-items: center; gap: 10px; }
.user-chip-text { display: none; flex-direction: column; line-height: 1.2; }
@media (min-width: 640px) { .user-chip-text { display: flex; } }
.user-chip-name { font-size: 13px; font-weight: 600; color: var(--text); }
.user-chip-role { font-size: 11px; color: var(--text-dim); }
.user-avatar { width: 32px; height: 32px; background: var(--primary); color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.logout-btn { font-size: 12px; font-weight: 600; color: var(--text-dim); text-decoration: none; padding: 8px 6px; }
.logout-btn:hover { color: var(--danger); }

.container { width: 100%; max-width: 460px; margin: 0 auto; padding: 40px 24px 48px; position: relative; z-index: 1; }
.message { padding: 12px 16px; margin: 0 0 20px; font-size: 13px; display: none; font-weight: 500; }
.message.show { display: block; }
.message.info { background: var(--accent-soft); border-left: 3px solid var(--accent); color: var(--primary); }
.message.success { background: rgba(31,138,84,0.08); border-left: 3px solid var(--success); color: var(--success); }
.message.error { background: rgba(198,40,40,0.08); border-left: 3px solid var(--danger); color: var(--danger); }
.message.warning { background: rgba(184,134,11,0.08); border-left: 3px solid var(--warning); color: #8a6508; }

.ledger-card { background: var(--surface); border: 1px solid var(--border); padding: 1.75rem; }
.ledger-eyebrow { font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 14px; font-family: var(--font-mono); text-align: center; }
.ledger-sentence { font-size: 23px; font-weight: 600; line-height: 1.5; text-align: center; }
.ledger-sentence input[type=number] { width: 100px; border: none; border-bottom: 1px solid var(--text); background: transparent; font-size: 23px; font-weight: 600; font-family: var(--font-mono); color: var(--text); text-align: center; padding: 0 2px 1px; }
.ledger-sentence input[type=number]:focus { outline: none; border-bottom-color: var(--accent); color: var(--accent); }

.ledger-rows { border-top: 1px solid var(--border); margin-top: 18px; }
.ledger-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border); cursor: pointer; transition: var(--transition); }
.ledger-row:hover { background: var(--surface-muted); margin: 0 -1.75rem; padding: 15px 1.75rem; }
.ledger-row-label { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 8px; }
.ledger-row-value { font-size: 13px; font-weight: 600; color: var(--text-dim); display: flex; align-items: center; gap: 6px; }
.ledger-row-value.filled { color: var(--text); }

.ledger-links { display: flex; justify-content: center; gap: 20px; margin-top: 16px; }
.ledger-link { font-size: 12px; font-weight: 600; color: var(--text-muted); text-decoration: underline; text-underline-offset: 3px; cursor: pointer; background: none; border: none; font-family: var(--font); }
.ledger-link:hover { color: var(--accent); }

.balance-link-wrap { text-align: center; margin-top: 18px; }

.cta-row { display: flex; justify-content: center; margin-top: 10px; gap: 10px; }
.cta-row .btn, .cta-row .btn-secondary { flex: 1 1 0; max-width: 360px; }
.btn { padding: 14px 24px; border: none; font-size: 11px; font-weight: 700; font-family: var(--font); cursor: pointer; letter-spacing: 0.08em; text-transform: uppercase; transition: var(--transition); }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover:not(:disabled) { background: var(--accent); }
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-secondary { background: transparent; color: var(--text); border: 1px solid var(--border-strong); font-weight: 600; text-transform: none; letter-spacing: 0; }
.btn-secondary:hover { background: var(--surface-muted); }
.btn-danger-outline { background: transparent; color: var(--danger); border: 1px solid rgba(198,40,40,0.4); padding: 6px 14px; font-size: 11px; cursor: pointer; font-weight: 600; }
.btn-sm { padding: 10px 16px !important; font-size: 11px; text-transform: none; letter-spacing: 0; }
.btn-link { background: none; border: none; padding: 0; cursor: pointer; color: var(--accent); font-size: 12px; font-weight: 600; }

.quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
.quick-link { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--text-muted); background: transparent; border: 1px solid var(--border-strong); padding: 8px 12px; cursor: pointer; transition: var(--transition); text-transform: uppercase; letter-spacing: 0.03em; }
.quick-link:hover { border-color: var(--text); color: var(--text); }
.quick-link.muted { color: var(--text-dim); font-weight: 500; text-transform: none; letter-spacing: 0; border: none; padding: 4px 0; }
.quick-link.danger { color: var(--danger); border-color: rgba(198,40,40,0.3); }
.quick-link.selected { background: var(--primary); color: #fff; border-color: var(--primary); }

.spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: spin 0.7s linear infinite; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }

.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(4,18,14,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal { background: var(--surface); max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; border: 1px solid var(--border-strong); }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 22px; background: var(--surface-muted); border-bottom: 1px solid var(--border); }
.modal-header h2 { font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text); font-family: var(--font-mono); }
.modal-close { background: none; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer; line-height: 1; padding: 4px; }
#modalBody { padding: 22px; }

.tab-hero { text-align: center; padding: 6px 0 14px; border-bottom: 1px solid var(--border); margin-bottom: 14px; }
.tab-hero-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; color: var(--text-dim); font-family: var(--font-mono); }
.tab-hero-input { background: transparent; border: none; text-align: center; font-size: 32px; font-weight: 600; width: 100%; color: var(--text); font-family: var(--font-mono); }
.tab-hero-input:focus { outline: none; }
.tab-hero-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.comp-bar-track { display: flex; height: 6px; width: 100%; background: var(--surface-muted); overflow: hidden; margin-bottom: 16px; border: 1px solid var(--border); }
.comp-bar-seg { height: 100%; flex-shrink: 0; transition: width 0.5s cubic-bezier(0.16,1,0.3,1); }
.tab-status-line { text-align: center; font-size: 12px; margin-bottom: 14px; padding: 10px; font-weight: 600; border: 1px solid var(--border); }
.tab-status-line.ok { color: var(--success); border-color: var(--success); }
.tab-status-line.warn { color: #8a6508; border-color: var(--warning); }
.tab-status-line.bad { color: var(--danger); border-color: var(--danger); }
.tab-strategy-row { display: flex; gap: 0; margin-bottom: 16px; border: 1px solid var(--border-strong); }
.tab-strategy-row .quick-link { flex: 1; justify-content: center; border: none; border-right: 1px solid var(--border-strong); }
.tab-strategy-row .quick-link:last-child { border-right: none; }
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
.review-hero-amount { font-size: 34px; font-weight: 600; color: var(--text); font-family: var(--font-mono); }
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
.modal-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 20px; }
.modal-actions .btn-secondary { flex: 0 0 auto; text-transform: none; border: none; background: transparent; color: var(--text-muted); font-size: 12px; }
.modal-actions .btn-primary { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }

.result-box { text-align: center; padding: 10px 0; }
.result-box .icon { font-size: 42px; color: var(--success); margin-bottom: 8px; }
.result-box .result-title { font-size: 20px; font-weight: 600; margin-bottom: 8px; }
.result-box .result-sub { font-size: 12px; color: var(--text-muted); margin-bottom: 16px; font-family: var(--font-mono); }
.result-box .atm-code { margin: 16px 0; padding: 18px; background: var(--surface-muted); border: 1px solid var(--border); }
.result-box .atm-code .code { font-size: 24px; font-weight: 700; font-family: var(--font-mono); letter-spacing: 4px; color: var(--accent); }

.field-label { font-size: 11px; color: var(--text-dim); text-transform: uppercase; display: block; margin-bottom: 6px; font-weight: 700; letter-spacing: 0.05em; }
.field-group { margin-bottom: 14px; }
.field-group label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; letter-spacing: 0.05em; }
.field-group input, .field-group select { width: 100%; padding: 12px 14px; background: var(--surface); border: 1px solid var(--border-strong); color: var(--text); font-size: 14px; font-family: var(--font); }
.field-group input:focus, .field-group select:focus { outline: none; border-color: var(--accent); }
.field-group .help { font-size: 11px; color: var(--text-dim); margin-top: 5px; }
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
.empty-source-box { text-align: center; padding: 24px 14px; border: 1px dashed var(--border-strong); background: var(--surface-muted); }
.empty-source-box p { font-size: 12px; color: var(--text-dim); margin-bottom: 12px; }

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

.toolbox-group { margin-bottom: 18px; }
.toolbox-group:last-child { margin-bottom: 0; }
.toolbox-group-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-dim); margin-bottom: 8px; font-family: var(--font-mono); }
.toolbox-list { display: flex; flex-direction: column; gap: 0; border: 1px solid var(--border); }
.toolbox-row { display: flex; align-items: center; gap: 12px; padding: 13px 14px; cursor: pointer; background: var(--surface); border-bottom: 1px solid var(--border); }
.toolbox-row:last-child { border-bottom: none; }
.toolbox-row:hover { background: var(--surface-muted); }
.toolbox-row-icon { width: 20px; text-align: center; font-size: 14px; }
.toolbox-row-label { flex: 1; font-size: 13px; font-weight: 600; color: var(--text); }

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

@media (max-width: 768px) {
    .header-inner { padding: 0 16px; height: auto; min-height: var(--header-h); flex-wrap: wrap; padding-top: 12px; padding-bottom: 12px; }
    .container { padding: 28px 16px 40px; }
    .ledger-sentence { font-size: 20px; }
    .cta-row { flex-direction: column; align-items: stretch; }
    .cta-row .btn, .cta-row .btn-secondary { max-width: none; }
    .modal-actions { flex-direction: column-reverse; }
    .modal-actions .btn-primary { width: 100%; }
}
@media (max-width: 480px) { .btn, .btn-secondary { width: 100%; } }
</style>
</head>
<body>

<header class="site-header">
    <div class="header-inner">
        <div class="brand">
            <div class="brand-text">
                <div class="logo">VouchMorph<sup>TM</sup></div>
                <div class="tagline">Money, swapped simply</div>
            </div>
            <div class="brand-divider"></div>
        </div>
        <nav class="main-nav" aria-label="Main">
            <span class="nav-pill active">Move money</span>
            <button type="button" class="nav-link" onclick="openSwapHistory()">Activity</button>
        </nav>
        <div class="header-actions">
            <div class="header-meta">
                <span class="role-badge"><?php echo htmlspecialchars(strtoupper($userRole)); ?></span>
                <span class="agent-badge" id="agentBadge" style="display:none;">Agent</span>
                <?php if ($isTestMode): ?><span class="test-mode-badge">Test mode</span><?php endif; ?>
                <select class="country-selector" id="countrySelector" onchange="switchCountry(this.value)">
                    <?php foreach ($availableCountries as $country): ?>
                    <option value="<?php echo htmlspecialchars($country); ?>" <?php echo $country === $userCountry ? 'selected' : ''; ?>><?php echo htmlspecialchars($country); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="toolbox-btn" onclick="openToolbox()">
                    Toolbox
                    <span id="toolboxBadge" class="toolbox-badge" style="display:none;"></span>
                </button>
            </div>
            <div class="user-chip">
                <div class="user-chip-text">
                    <span class="user-chip-name" id="userName"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="user-chip-role"><?php echo htmlspecialchars(ucfirst($userRole)); ?> &middot; <?php echo htmlspecialchars($userCountry); ?></span>
                </div>
                <div class="user-avatar" aria-hidden="true"><?php echo htmlspecialchars($userInitials); ?></div>
            </div>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
</header>

<div class="container">
<div id="mainMessage" class="message"></div>

<div class="ledger-card fade-in-up">
    <div class="ledger-eyebrow">Move money</div>
    <div class="ledger-sentence">
        Swap
        <input type="number" id="fromAmount" placeholder="0.00" step="0.01" min="0.01">
        <span id="fromCurrencyLabel" style="font-size:14px;color:var(--text-dim);"></span>
        from
    </div>
    <div style="text-align:center;font-size:12px;color:var(--text-muted);margin-top:8px;">You'll swap <strong id="amountPreview" style="font-family:var(--font-mono);color:var(--text);">0.00</strong></div>

    <div class="ledger-rows">
        <div class="ledger-row" onclick="openSourceModal()">
            <span class="ledger-row-label">Source</span>
            <span class="ledger-row-value" id="sourceRowText">Not selected &rsaquo;</span>
        </div>
        <div class="ledger-row" onclick="openDestinationModal()" style="border-bottom:none;">
            <span class="ledger-row-label">Destination</span>
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

<footer class="page-footer">
    <div class="footer-copy">&copy; 2026 VouchMorph Financial</div>
    <div class="footer-links">
        <span onclick="openHelpModal()">Help</span>
        <span onclick="openTermsModal()">Terms &amp; Conditions</span>
    </div>
</footer>

<div id="sourceSectionHolder" style="display:none;">
    <div id="fromSection">
        <div class="field-group">
            <label>Source type</label>
            <div class="source-type-buttons" id="sourceTypeButtons">
                <button type="button" class="source-type-btn" data-cat="WALLET" onclick="toggleSourcePanel('WALLET')">
                    <span class="btn-label">Wallet / Account <span class="count" id="walletBtnCount" style="display:none;">0</span></span>
                </button>
                <button type="button" class="source-type-btn" data-cat="CARD" onclick="toggleSourcePanel('CARD')">
                    <span class="btn-label">Card</span>
                </button>
                <button type="button" class="source-type-btn" data-cat="VOUCHER" onclick="toggleSourcePanel('VOUCHER')">
                    <span class="btn-label">Voucher</span>
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
                    <p>No linked wallet or account yet.</p>
                    <button class="btn btn-primary btn-sm" onclick="openAddSource()">Link an account</button>
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
</div>

<div id="destinationSectionHolder" style="display:none;">
    <div id="toSection">
        <div class="quick-actions" id="destTypeToggle">
            <span class="quick-link" id="depositToggleBtn" onclick="setSwapType('DEPOSIT')">Deposit</span>
            <span class="quick-link" id="cashoutToggleBtn" onclick="setSwapType('CASHOUT')">Cashout</span>
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
                    <option value="ATM">ATM</option>
                    <option value="AGENT">Agent</option>
                    <option value="VOUCHER">Voucher</option>
                </select>
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
</div>

<div id="identityFieldsHolder" style="display:none;">
    <div class="identity-field" id="identityFields">
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

<div id="multiDestControlsHolder" style="display:none;">
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
    'WALLET': ['MNO-WALLET', 'BANK-WALLET'],
    'MOBILE_WALLET': 'MNO-WALLET',
    'BANK_WALLET': 'BANK-WALLET',
    'ACCOUNT': 'ACCOUNT',
    'CARD': 'CARD',
    'VOUCHER': 'VOUCHER',
    'ATM': 'ATM',
    'POSTAL_ORDER': 'POSTAL-ORDER',
    'CHEQUE': 'CHEQUE',
    'CRYPTO': 'CRYPTO'
};

function getAssetConfig(type) {
    if (!type) return null;
    if (ASSETS[type]) return ASSETS[type];
    const normalized = String(type).trim().toUpperCase();
    if (ASSETS[normalized]) return ASSETS[normalized];
    const alias = ASSET_TYPE_ALIASES[normalized];
    if (alias) {
        if (Array.isArray(alias)) {
            for (const a of alias) { if (ASSETS[a]) return ASSETS[a]; }
        } else if (ASSETS[alias]) {
            return ASSETS[alias];
        }
    }
    const realKey = ASSET_KEY_MAP[normalized];
    if (realKey) return ASSETS[realKey];
    const assetKeys = Object.keys(ASSETS);
    for (const key of assetKeys) {
        if (key.includes(normalized) || normalized.includes(key)) return ASSETS[key];
    }
    return null;
}

let state = {
    fromCategory: 'WALLET', fromInst: null, fromAsset: null, fromFields: {}, fromAmount: 0,
    swapType: 'DEPOSIT', toInst: null, toAsset: null, toFields: {},
    deliveryMethod: 'ATM', beneficiaryPhone: '',
    toIdentityType: 'national_id', toIdentityValue: '', toIdentitySms: '',
    multiSources: [], lastPreview: null, swapPayload: null,
    multiDestMode: 'institution',
    tabTotalAmount: 0,
    tabAllocationMode: 'even',
    contributionStrategy: 'SMART',
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

async function getUserSources() {
    try {
        const resp = await fetch(CONFIG.API_BASE + '/user/sources.php', { method: 'GET', credentials: 'include', headers: { 'Accept': 'application/json' } });
        const body = await resp.json();
        return { ok: resp.ok && body.success, body };
    } catch (e) {
        return { ok: false, body: { error: e.message } };
    }
}

async function refreshSourceCount() {
    const result = await getUserSources();
    const sources = (result.ok && result.body.data && result.body.data.sources) || [];
    userSources = sources;
    const walletBtnCount = document.getElementById('walletBtnCount');
    if (walletBtnCount) {
        if (sources.length > 0) { walletBtnCount.style.display = 'inline-block'; walletBtnCount.textContent = sources.length; }
        else { walletBtnCount.style.display = 'none'; }
    }
    if (sourcePanelOpenCat === 'WALLET') renderSavedSourceChips();
    return sources;
}
document.addEventListener('DOMContentLoaded', refreshSourceCount);

function returnAllMovableNodesHome() {
    const mappings = [
        ['fromSection', 'sourceSectionHolder'],
        ['toSection', 'destinationSectionHolder'],
        ['identityFields', 'identityFieldsHolder'],
        ['toInstAssetGroup', 'toInstAssetGroupSlot'],
        ['multiDestControls', 'multiDestControlsHolder'],
    ];
    mappings.forEach(([nodeId, homeId]) => {
        const node = document.getElementById(nodeId);
        const home = document.getElementById(homeId);
        if (node && home && node.parentElement !== home) home.appendChild(node);
    });
}

function updateSelectionChips() {
    const sourceEl = document.getElementById('sourceRowText');
    if (sourceEl) {
        if (state.fromInst && state.fromAsset) {
            const instName = PARTICIPANTS[state.fromInst]?.name || state.fromInst;
            sourceEl.textContent = instName;
            sourceEl.classList.add('filled');
        } else {
            sourceEl.textContent = 'Not selected \u203a';
            sourceEl.classList.remove('filled');
        }
    }
    const destEl = document.getElementById('destRowText');
    if (destEl) {
        if (state.swapType === 'IDENTITY') {
            if (state.toIdentityValue) { destEl.textContent = 'Identity: ' + maskIdentifier(state.toIdentityValue); destEl.classList.add('filled'); }
            else { destEl.textContent = 'Not selected \u203a'; destEl.classList.remove('filled'); }
        } else if (state.swapType === 'MULTI_SOURCE') {
            const activeCount = state.multiSources.filter(s => s.institution && s.amount > 0).length;
            if (activeCount > 0) { destEl.textContent = activeCount + ' combined source(s)'; destEl.classList.add('filled'); }
            else { destEl.textContent = 'Not selected \u203a'; destEl.classList.remove('filled'); }
        } else if (state.toInst) {
            const instName = PARTICIPANTS[state.toInst]?.name || state.toInst;
            destEl.textContent = instName;
            destEl.classList.add('filled');
        } else {
            destEl.textContent = 'Not selected \u203a';
            destEl.classList.remove('filled');
        }
    }
}

function openSourceModal() {
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = '';
    modalBody.appendChild(document.getElementById('fromSection'));
    const btnRow = document.createElement('div');
    btnRow.className = 'cta-row';
    btnRow.style.marginTop = '20px';
    btnRow.innerHTML = '<button type="button" class="btn btn-primary" onclick="confirmSourceSelection()">Use this source</button>';
    modalBody.appendChild(btnRow);
    document.getElementById('modalTitle').textContent = 'Select a source';
    document.getElementById('modal').classList.add('active');
}
function confirmSourceSelection() {
    const hasSource = !!(state.fromInst && state.fromAsset && fieldsValidForAsset(state.fromAsset, state.fromFields, true).valid);
    if (!hasSource) { showMessage('Finish selecting your source — institution, asset type, and required fields.', 'warning'); return; }
    closeModal();
}

function openDestinationModal() {
    if (state.swapType !== 'DEPOSIT' && state.swapType !== 'CASHOUT') {
        setSwapType('DEPOSIT');
    }
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = '';
    modalBody.appendChild(document.getElementById('toSection'));
    const btnRow = document.createElement('div');
    btnRow.className = 'cta-row';
    btnRow.style.marginTop = '20px';
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
    btnRow.className = 'cta-row';
    btnRow.style.marginTop = '20px';
    btnRow.innerHTML = '<button type="button" class="btn btn-primary" onclick="confirmIdentitySelection()">Use this identity</button>';
    modalBody.appendChild(btnRow);
    document.getElementById('modalTitle').textContent = 'Swap to identity';
    document.getElementById('modal').classList.add('active');
}
function confirmIdentitySelection() {
    if (!state.toIdentityValue) { showMessage('Enter the identity value to swap to.', 'warning'); return; }
    closeModal();
}

function buildPayload() {
    const reference = 'SWAP_' + Date.now();
    const idempotencyKey = 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

    if (state.swapType === 'MULTI_SOURCE') {
        const activeRows = state.multiSources.filter(s => s.institution && s.assetType);
        const sources = activeRows.map(s => {
            const pin = extractPinFromFields(s.assetType, s.fields);
            const identifierField = (ASSETS[s.assetType]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
            const assetFields = { ...s.fields };
            if (assetHasAmountField(s.assetType)) assetFields.amount = s.amount;
            return {
                institution: s.institution,
                asset_type: s.assetType,
                identifier: identifierField ? s.fields[identifierField.name] : null,
                amount: s.amount,
                wallet_pin: pin || undefined,
                pin: pin || undefined,
                asset_fields: assetFields
            };
        });
        const totalAmount = state.tabTotalAmount || sources.reduce((sum, s) => sum + s.amount, 0);
        const sourceCurrency = activeRows.length ? (PARTICIPANTS[activeRows[0].institution]?.limits?.currency || null) : null;

        const payload = {
            swap_type: 'MULTI_SOURCE',
            reference,
            idempotency_key: idempotencyKey,
            user_id: CONFIG.USER_ID,
            amount: totalAmount,
            currency: sourceCurrency,
            contribution_strategy: state.contributionStrategy,
            sources,
        };

        if (state.contributionStrategy === 'USER_SPECIFIED') {
            payload.user_amounts = buildUserAmountsPayload();
        }

        if (state.multiDestMode === 'identity') {
            payload.identity_type = state.toIdentityType;
            payload.identity_value = state.toIdentityValue;
            if (state.toIdentitySms) payload.notification_phone = state.toIdentitySms;
            payload.destination_currency = sourceCurrency;
            return payload;
        }

        const destFields = { ...state.toFields };
        if (assetHasAmountField(state.toAsset)) destFields.amount = totalAmount;
        const destIdField = (ASSETS[state.toAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        const destCurrency = PARTICIPANTS[state.toInst]?.limits?.currency || sourceCurrency;

        payload.currency = payload.currency || destCurrency;
        payload.destination_currency = destCurrency;
        payload.to_institution = state.toInst;
        payload.destination_institution = state.toInst;
        payload.destination_asset_type = state.toAsset;
        payload.asset_type = state.toAsset;
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
    
    const payload = { 
        swap_type: state.swapType, 
        reference, 
        idempotency_key: idempotencyKey, 
        user_id: CONFIG.USER_ID, 
        from_institution: state.fromInst, 
        source_institution: state.fromInst, 
        asset_type: state.fromAsset, 
        amount: state.fromAmount, 
        currency: sourceCurrency, 
        wallet_pin: pin || undefined, 
        pin: pin || undefined, 
        asset_fields: sourceAssetFields, 
        ...sourceAssetFields,
        source_identifier: sourceIdentifier,
        source_identifier_type: sourceIdentifierType
    };
    
    if (state.swapType === 'IDENTITY') {
        payload.identity_type = state.toIdentityType;
        payload.identity_value = state.toIdentityValue;
        if (state.toIdentitySms) payload.notification_phone = state.toIdentitySms;
        payload.destination_currency = sourceCurrency;
        return payload;
    }
    
    if (state.swapType === 'CASHOUT') {
        payload.to_institution = state.toInst;
        payload.destination_institution = state.toInst;
        payload.delivery_method = state.deliveryMethod || 'ATM';
        payload.destination_currency = PARTICIPANTS[state.toInst]?.limits?.currency || sourceCurrency;
        
        const beneficiaryPhone = state.beneficiaryPhone || state.toFields?.phone || state.toFields?.recipient_phone || null;
        if (beneficiaryPhone) {
            payload.beneficiary_phone = beneficiaryPhone;
            payload.client_phone = beneficiaryPhone;
        }
        
        const destIdField = (ASSETS[state.toAsset]?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
        
        if (state.toAsset === 'VOUCHER') {
            payload.destination_asset_type = 'VOUCHER';
            payload.destination_asset_fields = {
                voucher_type: 'CASHOUT',
                recipient_phone: beneficiaryPhone || state.toFields?.recipient_phone || null,
                voucher_number: state.toFields?.voucher_number || ''
            };
            payload.destination_identifier = beneficiaryPhone || state.toFields?.recipient_phone || state.toFields?.phone || null;
            payload.destination_identifier_type = 'phone';
        } else {
            payload.destination_asset_type = 'WALLET';
            const phone = state.toFields?.phone || state.toFields?.recipient_phone || beneficiaryPhone || null;
            payload.destination_asset_fields = { phone: phone };
            payload.destination_phone = phone;
            payload.destination_identifier = phone;
            payload.destination_identifier_type = 'phone';
        }
        
        if (destIdField) {
            payload.destination_identifier = state.toFields[destIdField.name] || payload.destination_identifier;
        }
        
        return payload;
    }
    
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
    if (destIdField) {
        payload.destination_identifier = state.toFields[destIdField.name];
        if (state.toAsset === 'WALLET' || destIdField.name === 'phone' || destIdField.name === 'phone_number') {
            payload.destination_identifier_type = 'phone';
        } else if (state.toAsset === 'ACCOUNT' || destIdField.name === 'account_number' || destIdField.name === 'account') {
            payload.destination_identifier_type = 'account_number';
        } else {
            payload.destination_identifier_type = destIdField.name || 'account';
        }
    }
    
    return payload;
}

async function viewWalletBalance() {
    openModal('Balances', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading balances...</div>');
    
    const result = await fetchAllBalances();
    
    if (!result.success) {
        document.getElementById('modalBody').innerHTML = `
            <div style="text-align:center;padding:20px;color:var(--danger);">
                <div style="font-weight:700;">Failed to load balances</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">${escapeHtml(result.error)}</div>
                <button class="btn btn-primary btn-sm" onclick="viewWalletBalance()" style="margin-top:12px;">
                    Retry
                </button>
            </div>`;
        return;
    }
    
    const data = result.data || {};
    const sources = data.sources || [];
    const totals = data.total || {};
    
    if (sources.length === 0) {
        document.getElementById('modalBody').innerHTML = `
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <div style="font-weight:700;">No sources linked yet</div>
                <div style="font-size:12px;margin-top:8px;">Add a source to see your balance</div>
                <button class="btn btn-primary btn-sm" onclick="closeModal();openAddSource();" style="margin-top:12px;">
                    Add source
                </button>
            </div>`;
        return;
    }
    
    let html = `
        <div style="margin-bottom:16px;">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">Your total balance across all linked sources</div>`;
    
    if (Object.keys(totals).length > 0) {
        html += `<div style="background:var(--primary);color:#fff;padding:16px;margin-bottom:12px;">`;
        Object.keys(totals).forEach(cur => {
            html += `
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:12px;opacity:0.7;">Total ${cur}</span>
                    <span style="font-size:22px;font-weight:600;font-family:var(--font-mono);">${formatMoney(totals[cur], cur)}</span>
                </div>`;
        });
        html += `</div>`;
    }
    
    html += `<div style="max-height:50vh;overflow-y:auto;">`;
    
    sources.forEach(item => {
        const source = item.source;
        const balance = item.balance;
        const instName = PARTICIPANTS[source.institution]?.name || source.institution;
        const assetLabel = ASSETS[source.asset_type]?.label || source.asset_type;
        
        let balanceDisplay = '—';
        let currency = source.currency || 'BWP';
        
        if (balance.success) {
            balanceDisplay = formatMoney(balance.balance, balance.currency);
            currency = balance.currency;
        } else {
            balanceDisplay = `<span style="color:var(--text-muted);font-size:12px;">${escapeHtml(balance.error || 'Unavailable')}</span>`;
        }
        
        html += `
            <div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:700;">${escapeHtml(instName)}</div>
                        <div style="font-size:12px;color:var(--text-muted);">
                            ${escapeHtml(assetLabel)} · ${escapeHtml(source.identifier)}
                            ${source.account_name ? ` · ${escapeHtml(source.account_name)}` : ''}
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:16px;font-weight:600;font-family:var(--font-mono);">
                            ${balanceDisplay}
                        </div>
                        <div style="font-size:10px;color:var(--text-dim);text-transform:uppercase;">
                            ${source.status}
                        </div>
                    </div>
                </div>
                <div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn-primary btn-sm" onclick="refreshSourceBalance('${source.id}')">Refresh</button>
                    <button class="btn-secondary btn-sm" onclick="closeModal();useSourceForSwap('${source.id}')">Use as source</button>
                </div>
            </div>`;
    });
    
    html += `</div>`;
    
    html += `
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-primary btn-sm" onclick="refreshAllBalances()">Refresh all</button>
            <button class="btn btn-secondary btn-sm" onclick="closeModal();openAddSource()">Add source</button>
            <button class="btn btn-secondary btn-sm" onclick="closeModal()">Close</button>
        </div>`;
    
    document.getElementById('modalBody').innerHTML = html;
}

async function refreshSourceBalance(sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    if (!source) {
        showMessage('Source not found', 'error');
        return;
    }
    
    showMessage(`Fetching balance for ${source.institution}...`, 'info');
    
    const result = await fetchBalance(source.institution, source.identifier, source.identifier_type);
    
    if (result.success) {
        const data = result.data || {};
        showMessage(`Balance updated: ${formatMoney(data.balance, data.currency)}`, 'success');
        viewWalletBalance();
    } else {
        showMessage(`Failed to fetch balance: ${result.error}`, 'error');
        viewWalletBalance();
    }
}

async function refreshAllBalances() {
    showMessage('Refreshing all balances...', 'info');
    await viewWalletBalance();
}

async function fetchBalance(institution, identifier, identifierType = 'auto') {
    try {
        const response = await fetch(CONFIG.API_BASE + '/api/v1/user/balance.php', {
            method: 'POST',
            headers: buildHeaders(),
            body: JSON.stringify({
                institution: institution,
                identifier: identifier,
                identifier_type: identifierType
            }),
            credentials: 'include'
        });
        
        const data = await response.json();
        return data;
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
        
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Fetch all balances error:', error);
        return { success: false, error: error.message };
    }
}

function toggleSourcePanel(cat) {
    const wrap = document.getElementById('sourcePanelWrap');
    const walletPanel = document.getElementById('walletPanel');
    const instAssetPanel = document.getElementById('instAssetPanel');

    if (sourcePanelOpenCat === cat) {
        wrap.style.display = 'none';
        sourcePanelOpenCat = null;
        document.querySelectorAll('.source-type-btn').forEach(b => b.classList.remove('active'));
        return;
    }

    sourcePanelOpenCat = cat;
    wrap.style.display = 'block';
    document.querySelectorAll('.source-type-btn').forEach(b => b.classList.toggle('active', b.dataset.cat === cat));

    state.fromCategory = cat;
    state.fromInst = null; state.fromAsset = null; state.fromFields = {};
    selectedSourceId = null;

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
    if (instOptions.length === 0) { showMessage('No institutions found for this country.', 'error'); return; }
    toSelect.innerHTML = '<option value="">Select institution</option>';
    instOptions.forEach(code => {
        const name = PARTICIPANTS[code]?.name || code;
        toSelect.insertAdjacentHTML('beforeend', `<option value="${code}">${name}</option>`);
    });
    updateCurrencyDisplay();
    document.getElementById('fromAmount').addEventListener('input', function() {
        state.fromAmount = parseFloat(this.value) || 0;
        const preview = document.getElementById('amountPreview');
        if (preview) preview.textContent = formatMoney(this.value, getInstitutionCurrency(state.fromInst));
        refreshUI();
    });
    checkPendingClaims();
    loadAgentStatus();
    loadUserSources();
    updateSelectionChips();
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
        response = await fetch(url, { method: 'POST', headers: buildHeaders(), body: JSON.stringify(payload), credentials: 'include' });
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
    collapseSourcePanel();
    refreshUI();
}
function updateFromField(name, value) { state.fromFields[name] = value; refreshUI(); }
function assetHasAmountField(assetType) { return (getAssetConfig(assetType)?.fields || []).some(f => f.name === 'amount'); }
function renderDynamicFields(containerId, assetType, prefix, onChange, includePin) {
    const container = document.getElementById(containerId);
    const config = getAssetConfig(assetType);
    if (!config) { container.innerHTML = `<div class="help" style="color:var(--danger);">Unknown asset type: ${assetType}</div>`; return; }
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
            return `<div class="field-group">
                <label>${f.label} ${f.required ? '*' : ''}</label>
                <select id="${prefix}${f.name}" onchange="window['${onChange.name}']('${f.name}', this.value)">
                    <option value="">${f.placeholder || 'Select'}</option>${optionsHtml}
                </select>
                ${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}
            </div>`;
        }
        const inputType = f.vault_field === 'pin' || f.name.includes('pin') || f.name === 'cvv' ? 'password' : (f.type || 'text');
        return `<div class="field-group">
            <label>${f.label} ${f.required ? '*' : ''}</label>
            <input type="${inputType}" id="${prefix}${f.name}" placeholder="${f.placeholder || ''}" ${attrs.join(' ')} oninput="window['${onChange.name}']('${f.name}', this.value)">
            ${f.help_text ? `<div class="help">${f.help_text}</div>` : ''}
        </div>`;
    }).join('');
}
function fieldsValidForAsset(assetType, values, includePin) {
    const config = getAssetConfig(assetType);
    if (!config) {
        console.warn('No config found for asset type:', assetType);
        return { valid: false, reason: 'Asset type not found: ' + assetType };
    }
    
    let fields = config.fields || [];
    fields = fields.filter(f => f.name !== 'amount');
    
    fields = fields.filter(f => f.vault_field !== 'pin');
    fields = fields.filter(f => !f.name.toLowerCase().includes('pin'));
    
    const requiredFields = fields.filter(f => f.required === true);
    
    let failedField = null;
    let failedReason = null;
    
    const result = requiredFields.every(f => {
        const val = values[f.name];
        if (!val || String(val).trim().length === 0) {
            failedField = f.name;
            failedReason = 'required but empty';
            return false;
        }
        if (f.pattern) {
            try {
                let pattern = f.pattern;
                pattern = pattern.replace(/\\\\/g, '\\');
                const regex = new RegExp(pattern);
                const matches = regex.test(String(val));
                if (!matches) {
                    if (f.name === 'phone' || f.name === 'phone_number') {
                        const simpleMatch = /^\+?[0-9]{10,15}$/.test(String(val));
                        if (simpleMatch) return true;
                    }
                    if (f.name === 'account_number' || f.name === 'account') {
                        const alphanumericMatch = /^[A-Z0-9]{8,16}$/i.test(String(val));
                        if (alphanumericMatch) return true;
                    }
                    failedField = f.name;
                    failedReason = `pattern mismatch (value: "${val}", pattern: ${pattern})`;
                    return false;
                }
            } catch (e) {
                return true;
            }
        }
        return true;
    });
    
    if (!result) {
        return { valid: false, field: failedField, reason: failedReason };
    }
    
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
function setDeliveryMethod(method) { state.deliveryMethod = method; refreshUI(); }

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
    if (documentTypes.includes(type)) {
        hint.textContent = `This is a physical document that can be verified by an agent in person. The recipient will also receive a notification and can claim via dashboard within 24 hours.`;
    } else {
        hint.textContent = `The recipient will be notified via ${type === 'phone' ? 'SMS' : 'email'} and can claim the funds within 24 hours.`;
    }
}
function quickSetSwapType(type) {
    const sel = document.getElementById('swapTypeSelect');
    if (sel) sel.value = type;
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

function isFixedAssetType(assetType) {
    return ['VOUCHER', 'CASHOUT-VOUCHER'].includes(String(assetType).toUpperCase());
}
function voucherTotalExceedsTarget() {
    const voucherTotal = state.multiSources.filter(s => isFixedAssetType(s.assetType)).reduce((sum, s) => sum + (s.amount || 0), 0);
    return state.tabTotalAmount > 0 && voucherTotal > state.tabTotalAmount + 0.01;
}
function hasDuplicateInstitution() {
    const insts = state.multiSources.filter(s => s.institution).map(s => s.institution);
    return new Set(insts).size !== insts.length;
}
function buildUserAmountsPayload() {
    const amounts = {};
    state.multiSources.forEach(s => {
        if (s.institution && !isFixedAssetType(s.assetType)) amounts[s.institution] = s.amount;
    });
    return amounts;
}

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
    rows.forEach((s, i) => {
        s.amount = (i === rows.length - 1) ? round2(toSplit - allocated) : share;
        allocated += s.amount;
    });
}

function resetToEvenSplit() { state.contributionStrategy = 'EQUAL'; autoSplitEven(); reopenTabBuilder(); }

function tabAllocatedTotal() { return round2(state.multiSources.reduce((sum, s) => sum + (s.amount || 0), 0)); }
function tabRemaining() { return round2(state.tabTotalAmount - tabAllocatedTotal()); }

function tabSourceAmountEdited(rowId, value) {
    state.contributionStrategy = 'USER_SPECIFIED';
    setMultiSourceAmount(rowId, value);
    reopenTabBuilder();
}

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
    rows.forEach((s, i) => {
        const bal = balances[i].success ? (balances[i].data?.balance || 0) : 0;
        s.amount = totalBalance > 0 ? round2(toSplit * (bal / totalBalance)) : 0;
        s._estimated = true;
    });
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

    if (mode === 'vmcard' && !PARTICIPANTS['vouchmorph']) {
        showMessage("VouchMorph Card isn't enabled for this country yet.", 'warning');
        state.multiDestMode = 'institution';
        mode = 'institution';
    }

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
    const identityHome = document.getElementById('identityFieldsHolder');
    const instGroupHome = document.getElementById('toInstAssetGroupSlot');

    if (state.multiDestMode === 'identity') {
        if (instGroupEl && instGroupHome && instGroupEl.parentElement !== instGroupHome) instGroupHome.appendChild(instGroupEl);
        if (identityEl) destArea.appendChild(identityEl);
    } else {
        if (identityEl && identityHome && identityEl.parentElement !== identityHome) identityHome.appendChild(identityEl);
        if (instGroupEl) destArea.appendChild(instGroupEl);
    }
}

function openTabBuilder() {
    if (state.multiSources.length === 0) { addMultiSourceRow(); addMultiSourceRow(); }
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = `
        <div id="tabBuilderGenerated"></div>
        <div style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border);">
            <div class="field-label" style="margin-bottom:8px;">Where should this land?</div>
            <div id="multiDestControlsHost"></div>
            <div id="tabBuilderDestArea" style="margin-top:10px;"></div>
        </div>`;
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
    const bar = document.getElementById('tabCompBar');
    if (bar) {
        const segs = bar.querySelectorAll('.comp-bar-seg');
        requestAnimationFrame(() => {
            segs.forEach(seg => { seg.style.width = (seg.dataset.target || '0') + '%'; });
        });
    }
    const list = document.getElementById('tabSourceList');
    if (list) {
        list.style.opacity = '0';
        requestAnimationFrame(() => { list.style.opacity = '1'; });
    }
}

function refreshTabBuilderIfOpen() {
    if (document.getElementById('tabBuilderGenerated')) reopenTabBuilder();
}

function institutionInitials(code) {
    const name = PARTICIPANTS[code]?.name || code || '';
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return name.slice(0, 2).toUpperCase() || '??';
}

// ============================================================
// Circuit-board diagram — sharp rectangles only, no circles.
// Each source is a chip whose width scales with its share of the
// total; right-angle traces route into a hub chip showing the
// total, which routes onward to the destination chip once one is
// set. A small square "packet" loops along each trace to suggest
// money actually moving. Strictly monochrome: every fill/stroke
// here is var(--primary)/var(--surface)/var(--border-*) — no hex,
// no COMPOSITION_PALETTE. Rebuilt fresh on every render, so the
// draw-in and packet-loop animations replay every time a source,
// amount, or destination changes.
// ============================================================
function renderCircuitDiagram() {
    const total = state.tabTotalAmount || state.multiSources.reduce((s, r) => s + (r.amount || 0), 0);
    const maxAmt = Math.max(...state.multiSources.map(s => s.amount || 0), 1);
    const n = state.multiSources.length || 1;

    const hubX = 230, hubY = 60, hubW = 100, hubH = 90;
    const chipX = 20, chipH = 26;
    const destX = 420, destW = 46, destH = 36;

    function layoutY(count, r0, r1) {
        const usable = r1 - r0;
        return Array.from({ length: count }, (_, i) => r0 + ((i + 0.5) * usable) / count);
    }
    const srcYs = layoutY(n, 12, 228);
    const pinYs = layoutY(n, hubY + 12, hubY + hubH - 12);

    let html = '';

    state.multiSources.forEach((s, i) => {
        const has = !!s.institution;
        const amt = s.amount || 0;
        const w = has ? 34 + Math.round((amt / maxAmt) * 40) : 30;
        const y = srcYs[i];
        const pinY = pinYs[i];
        const midX = 145 + i * 7;
        if (has) {
            html += `<path d="M${chipX + w},${y} H${midX} V${pinY} H${hubX}" fill="none" stroke="var(--border-strong)" stroke-width="1.5" opacity="0.75" stroke-dasharray="400" stroke-dashoffset="400" id="bcTrace${i}"><animate attributeName="stroke-dashoffset" from="400" to="0" dur="0.5s" begin="${i * 0.08}s" fill="freeze" /></path>`;
        }
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
            html += `
                <rect x="${chipX}" y="${y - chipH / 2}" width="${w}" height="${chipH}" fill="none" stroke="var(--border-strong)" stroke-width="1.5" stroke-dasharray="4 3" />
                <text x="${chipX + w / 2}" y="${y + 4}" text-anchor="middle" font-size="12" fill="var(--text-dim)">?</text>`;
        }
    });

    html += `
        <rect x="${hubX}" y="${hubY}" width="${hubW}" height="${hubH}" fill="var(--surface)" stroke="var(--border-strong)" stroke-width="1.5" />
        <rect x="${hubX + 6}" y="${hubY + 6}" width="${hubW - 12}" height="2" fill="var(--border)"><animate attributeName="opacity" values="1;0.2;1" dur="2.2s" repeatCount="indefinite" /></rect>
        <text x="${hubX + hubW / 2}" y="${hubY + hubH / 2 - 2}" text-anchor="middle" font-size="17" font-weight="700" fill="var(--text)" font-family="var(--font-mono)">${total.toFixed(0)}</text>
        <text x="${hubX + hubW / 2}" y="${hubY + hubH / 2 + 15}" text-anchor="middle" font-size="8" fill="var(--text-dim)">total</text>`;

    const hubPinY = hubY + hubH / 2;
    let destLabel = null;
    if (state.multiDestMode === 'identity' && state.toIdentityValue) {
        destLabel = maskIdentifier(state.toIdentityValue).replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase() || 'ID';
    } else if (state.multiDestMode !== 'identity' && state.toInst) {
        destLabel = institutionInitials(state.toInst);
    }

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
        html += `
            <rect x="${destX}" y="${hubPinY - destH / 2}" width="${destW}" height="${destH}" fill="none" stroke="var(--border-strong)" stroke-width="1.5" stroke-dasharray="4 3" />
            <text x="${destX + destW / 2}" y="${hubPinY + 4}" text-anchor="middle" font-size="14" fill="var(--text-dim)">?</text>`;
    }

    return `<svg width="100%" height="240" viewBox="0 0 480 240" style="margin-bottom:14px;overflow:visible;">${html}</svg>`;
}

function renderTabBuilder() {
    const cur = getInstitutionCurrency(state.toInst) || '';
    const remaining = tabRemaining();
    const strategy = state.contributionStrategy;

    let statusHtml = '';
    if (voucherTotalExceedsTarget()) {
        statusHtml = `<div class="tab-status-line bad">Voucher total exceeds your tab amount — remove a voucher or raise the total</div>`;
    } else if (strategy === 'USER_SPECIFIED' && state.tabTotalAmount > 0) {
        if (remaining === 0) {
            statusHtml = `<div class="tab-status-line ok">Fully allocated across ${state.multiSources.filter(s => s.institution).length} source(s)</div>`;
        } else {
            statusHtml = `<div class="tab-status-line ${remaining > 0 ? 'warn' : 'bad'}">${remaining > 0 ? formatMoney(remaining, cur) + ' left to allocate' : 'Over by ' + formatMoney(Math.abs(remaining), cur)}</div>`;
        }
    } else if (strategy === 'RATIO') {
        statusHtml = `<div class="tab-status-line warn">Estimated split — recalculated from live balances when you swap</div>`;
    } else if (strategy === 'SMART') {
        statusHtml = `<div class="tab-status-line ok">VouchMorph will balance this across your sources automatically</div>`;
    }

    if (hasDuplicateInstitution() && strategy === 'USER_SPECIFIED') {
        statusHtml += `<div class="tab-status-line bad">Manual mode needs one source per institution — remove the duplicate</div>`;
    }

    const activeCount = state.multiSources.filter(s => s.institution && s.amount > 0).length;
    const cards = state.multiSources.map((s, i) => renderTabSourceCard(s, i)).join('');

    return `
        <div class="tab-hero">
            <div class="tab-hero-label">Amount to swap</div>
            <input type="number" step="0.01" class="tab-hero-input" value="${state.tabTotalAmount || ''}" placeholder="0.00" oninput="setTabTotalAmount(this.value)">
            <div class="tab-hero-sub">${activeCount} source(s) added</div>
        </div>
        ${renderCircuitDiagram()}
        ${statusHtml}
        <div class="tab-strategy-row">
            <button class="quick-link ${strategy==='EQUAL'?'selected':''}" onclick="setContributionStrategy('EQUAL')">Equal</button>
            <button class="quick-link ${strategy==='RATIO'?'selected':''}" onclick="setContributionStrategy('RATIO')">Ratio</button>
            <button class="quick-link ${strategy==='SMART'?'selected':''}" onclick="setContributionStrategy('SMART')">Smart</button>
            <button class="quick-link ${strategy==='USER_SPECIFIED'?'selected':''}" onclick="setContributionStrategy('USER_SPECIFIED')">Manual</button>
        </div>

        <div id="tabSourceList" class="tab-source-list" style="opacity:0;">${cards}</div>
        <button class="quick-link" style="width:100%;justify-content:center;padding:12px;margin-top:4px;" onclick="addMultiSourceRow(); reopenTabBuilder();">+ Add another source</button>

        ${strategy === 'USER_SPECIFIED' ? `<button class="quick-link muted" style="width:100%;justify-content:center;padding:10px;margin-top:8px;">↻ <span onclick="resetToEvenSplit()">Reset to even split</span></button>` : ''}

        <div class="tab-invite-teaser" onclick="openTabInvite()">
            <span class="tab-invite-icon"></span>
            <div>
                <div class="tab-invite-title">Split this with friends</div>
                <div class="tab-invite-sub">Invite other VouchMorph users to hook their own sources to this tab — coming soon</div>
            </div>
        </div>`;
}

function renderTabSourceCard(src, idx) {
    if (!src.institution) {
        return `<div class="tab-source-empty">
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:8px;">Source ${idx + 1} — not set up yet</div>
            <div class="quick-actions">
                <span class="quick-link" onclick="pickSavedSourceForTab(${src.id})">Use a saved source</span>
                <span class="quick-link muted" onclick="tabSourceManual(${src.id}, 'ACCOUNT')">Account</span>
                <span class="quick-link muted" onclick="tabSourceManual(${src.id}, 'CARD')">Card</span>
                <span class="quick-link muted" onclick="tabSourceManual(${src.id}, 'VOUCHER')">Voucher</span>
            </div>
        </div>`;
    }
    const instName = PARTICIPANTS[src.institution]?.name || src.institution;
    const isFixed = isFixedAssetType(src.assetType);
    const fieldsHtml = (getAssetConfig(src.assetType)?.fields || [])
        .filter(f => f.name !== 'amount' && f.vault_field !== 'pin')
        .map(f => `<div class="field-group" style="margin-top:8px;"><label>${f.label}</label><input value="${src.fields[f.name] || ''}" placeholder="${f.placeholder || ''}" oninput="setMultiSourceField(${src.id}, '${f.name}', this.value)"></div>`)
        .join('');

    let amountHtml;
    if (isFixed) {
        amountHtml = `<div class="tab-fixed-note">Uses full voucher balance — not split (${formatMoney(src.amount || 0, '')})</div>`;
    } else if (state.contributionStrategy === 'RATIO') {
        amountHtml = `<div class="field-group" style="margin-top:8px;"><label>Estimated amount</label><input type="number" value="${src.amount || ''}" disabled style="background:var(--surface);color:var(--text-dim);"></div><div class="tab-estimated-note">Estimated from live balance — recalculated at execution time</div>`;
    } else if (state.contributionStrategy === 'SMART') {
        amountHtml = `<div class="field-group" style="margin-top:8px;"><label>Amount</label><input type="number" value="${src.amount || ''}" disabled style="background:var(--surface);color:var(--text-dim);"></div><div class="tab-estimated-note">VouchMorph balances this automatically</div>`;
    } else {
        amountHtml = `<div class="field-group" style="margin-top:8px;"><label>Amount from this source</label><input type="number" min="0.01" step="0.01" value="${src.amount || ''}" placeholder="0.00" oninput="tabSourceAmountEdited(${src.id}, this.value)"></div>`;
    }

    return `<div class="tab-source-card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="width:10px;height:10px;background:var(--primary);flex-shrink:0;"></span>
                <div><div style="font-weight:700;font-size:13px;">${escapeHtml(instName)}</div><div style="font-size:11px;color:var(--text-dim);">${escapeHtml(getAssetConfig(src.assetType)?.label || src.assetType || '')}</div></div>
            </div>
            ${state.multiSources.length > 2 ? `<button class="btn-danger-outline" onclick="removeMultiSourceRow(${src.id}); reopenTabBuilder();">Remove</button>` : ''}
        </div>
        ${fieldsHtml}
        ${amountHtml}
    </div>`;
}

function tabSourceManual(rowId, assetType) {
    const eligible = Object.keys(PARTICIPANTS).filter(code => (PARTICIPANTS[code].asset_types || []).map(t => String(t).toUpperCase()).includes(assetType));
    const options = eligible.map(c => `<option value="${c}">${PARTICIPANTS[c]?.name || c}</option>`).join('');
    document.getElementById('tabBuilderGenerated').innerHTML = `
        <div style="font-weight:700;margin-bottom:10px;">Add a ${assetType.toLowerCase()} source</div>
        <div class="field-group"><label>Institution</label><select id="tabPickInst"><option value="">Select</option>${options}</select></div>
        <div class="cta-row"><button class="btn btn-secondary" onclick="reopenTabBuilder()">Cancel</button><button class="btn btn-primary" onclick="confirmTabSourceManual(${rowId}, '${assetType}')">Add</button></div>`;
}
function confirmTabSourceManual(rowId, assetType) {
    const inst = document.getElementById('tabPickInst').value;
    if (!inst) { showMessage('Select an institution.', 'warning'); return; }
    if (state.contributionStrategy === 'USER_SPECIFIED' && state.multiSources.some(s => s.institution === inst)) {
        showMessage('Manual mode needs one source per institution — pick a different institution or switch strategy.', 'warning');
        return;
    }
    setMultiSourceInst(rowId, inst);
    const src = state.multiSources.find(s => s.id === rowId);
    src.assetType = assetType; src.fields = {};
    reopenTabBuilder();
}
function pickSavedSourceForTab(rowId) {
    const eligible = userSources.filter(s => s.status === 'active');
    if (eligible.length === 0) { showMessage('No saved sources yet — add one from the toolbox first.', 'info'); return; }
    const rows = eligible.map(s => `
        <div class="saved-source-row" onclick="applySavedSourceToTab(${rowId}, '${s.id}')">
            <div class="row-main"><div class="row-inst">${escapeHtml(PARTICIPANTS[s.institution]?.name || s.institution)}</div><div class="row-ident">${escapeHtml(s.identifier || '')}</div></div>
        </div>`).join('');
    document.getElementById('tabBuilderGenerated').innerHTML = `<div class="saved-source-list">${rows}</div><div class="cta-row" style="margin-top:14px;"><button class="btn btn-secondary" onclick="reopenTabBuilder()">Back</button></div>`;
}
function applySavedSourceToTab(rowId, sourceId) {
    const source = userSources.find(s => s.id === sourceId);
    if (!source) return;
    if (state.contributionStrategy === 'USER_SPECIFIED' && state.multiSources.some(s => s.institution === source.institution)) {
        showMessage('Manual mode needs one source per institution — pick a different institution or switch strategy.', 'warning');
        reopenTabBuilder();
        return;
    }
    setMultiSourceInst(rowId, source.institution);
    const src = state.multiSources.find(s => s.id === rowId);
    src.assetType = source.asset_type;
    src.fields = {};
    const idField = (getAssetConfig(source.asset_type)?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    if (idField) src.fields[idField.name] = source.identifier;
    reopenTabBuilder();
}

function openTabInvite() {
    document.getElementById('tabBuilderGenerated').innerHTML = `
        <div style="text-align:center;padding:20px 10px;">
            <div style="font-weight:700;font-size:15px;margin:8px 0 4px;">Split this tab</div>
            <div style="font-size:12px;color:var(--text-muted);max-width:320px;margin:0 auto 16px;">Soon you'll be able to share a code with friends — they hook their own account, wallet, card, or voucher onto this exact tab, and one swipe covers the whole bill.</div>
            <div style="font-size:11px;color:var(--text-dim);">Not available in this build yet.</div>
        </div>
        <div class="cta-row"><button class="btn btn-primary" onclick="reopenTabBuilder()">Back to my tab</button></div>`;
}

function multiSourcesValid() {
    const activeRows = state.multiSources.filter(s => s.institution);
    if (activeRows.length < 2) return false;
    if (voucherTotalExceedsTarget()) return false;
    if (state.contributionStrategy === 'USER_SPECIFIED') {
        if (state.tabTotalAmount > 0 && Math.abs(tabRemaining()) > 0.01) return false;
        if (hasDuplicateInstitution()) return false;
    }
    return activeRows.every(s => {
        if (!s.assetType || !(s.amount > 0)) return false;
        return fieldsValidForAsset(s.assetType, s.fields, true);
    });
}

function getSwapReadiness() {
    const reasons = [];
    const missingFields = [];
    
    if (state.swapType === 'MULTI_SOURCE') {
        if (!(state.tabTotalAmount > 0)) {
            reasons.push('enter the total amount to swap');
        }
        if (!multiSourcesValid()) {
            state.multiSources.forEach((s, idx) => {
                if (!s.institution) missingFields.push(`Source ${idx + 1}: institution not selected`);
                if (!s.assetType) missingFields.push(`Source ${idx + 1}: asset type not selected`);
                if (!(s.amount > 0)) missingFields.push(`Source ${idx + 1}: amount not set`);
                const config = getAssetConfig(s.assetType);
                if (config) {
                    const fields = config.fields || [];
                    const requiredFields = fields.filter(f => f.required && f.name !== 'amount' && f.vault_field !== 'pin');
                    requiredFields.forEach(f => {
                        if (!s.fields[f.name] || String(s.fields[f.name]).trim().length === 0) {
                            missingFields.push(`Source ${idx + 1}: ${f.label} (${f.name}) required`);
                        }
                    });
                }
            });
            if (voucherTotalExceedsTarget()) reasons.push('voucher total exceeds your tab amount');
            else if (state.contributionStrategy === 'USER_SPECIFIED' && Math.abs(tabRemaining()) > 0.01) reasons.push('manually allocated amounts don\'t add up to the total');
            else if (hasDuplicateInstitution() && state.contributionStrategy === 'USER_SPECIFIED') reasons.push('manual mode needs one source per institution');
            else reasons.push('build your tab (at least 2 sources, in the Combine Sources panel)');
        }
        if (state.multiDestMode === 'identity') {
            if (!state.toIdentityValue) reasons.push('enter the identity value to swap to');
        } else {
            if (!state.toInst) reasons.push('select a destination institution');
            else if (!state.toAsset) reasons.push('select a destination asset type');
            else if (!fieldsValidForAsset(state.toAsset, state.toFields, false).valid) {
                const result = fieldsValidForAsset(state.toAsset, state.toFields, false);
                missingFields.push(`Destination: ${result.field || 'unknown field'} - ${result.reason || 'required'}`);
                reasons.push('fill in the required destination fields');
            }
        }
        return { ready: reasons.length === 0, reasons, missingFields };
    }
    
    if (!state.fromInst || !state.fromAsset) {
        reasons.push('choose a source (Wallet/Account, Card, or Voucher)');
    }
    
    if (!(state.fromAmount > 0)) {
        reasons.push('enter an amount');
    } else if (state.fromInst && !amountWithinLimits(state.fromInst, state.fromAmount)) {
        const limits = PARTICIPANTS[state.fromInst]?.limits;
        reasons.push(limits ? `enter an amount between ${limits.min_amount} and ${limits.max_amount}` : 'enter an amount within this institution\'s limits');
    }
    
    if (state.fromInst && state.fromAsset) {
        const validation = fieldsValidForAsset(state.fromAsset, state.fromFields, true);
        if (!validation.valid) {
            missingFields.push(`Source: ${validation.field || 'unknown field'} - ${validation.reason || 'required'}`);
            reasons.push('fill in the required source fields');
        }
    }
    
    if (state.swapType === 'IDENTITY') {
        if (!state.toIdentityValue) {
            missingFields.push('Identity: identity value required');
            reasons.push('enter the identity value to swap to');
        }
    } else if (state.swapType === 'CASHOUT') {
        if (!state.toInst) {
            reasons.push('select a destination institution for the cashout');
        } else if (state.toInst && state.toAsset) {
            const validation = fieldsValidForAsset(state.toAsset, state.toFields, false);
            if (!validation.valid) {
                missingFields.push(`Destination: ${validation.field || 'unknown field'} - ${validation.reason || 'required'}`);
                reasons.push('fill in the required destination fields');
            }
        }
    } else {
        if (!state.toInst) {
            reasons.push('select a destination institution');
        } else if (!state.toAsset) {
            reasons.push('select a destination asset type');
        } else {
            const validation = fieldsValidForAsset(state.toAsset, state.toFields, false);
            if (!validation.valid) {
                missingFields.push(`Destination: ${validation.field || 'unknown field'} - ${validation.reason || 'required'}`);
                reasons.push('fill in the required destination fields');
            }
        }
    }
    
    return { ready: reasons.length === 0, reasons, missingFields };
}

function isSwapReady() {
    return getSwapReadiness().ready;
}

function refreshUI() {
    const readiness = getSwapReadiness();
    const btn = document.getElementById('reviewBtn');
    if (btn) btn.disabled = false;
    const hint = document.getElementById('swapReadinessHint');
    if (hint) {
        if (readiness.ready) {
            hint.textContent = '';
            hint.className = '';
            hint.style.display = 'none';
        } else {
            let msg = '';
            if (readiness.missingFields && readiness.missingFields.length > 0) {
                msg += 'Missing: ' + readiness.missingFields.join('; ');
            } else {
                msg += readiness.reasons.join(', ');
            }
            hint.textContent = msg;
            hint.className = 'show warning';
            hint.style.display = 'block';
        }
    }
    updateMultiTotal();
    updateToolboxBadge();
    updateSelectionChips();
}

async function previewSwap() {
    const readiness = getSwapReadiness();
    if (!readiness.ready) {
        let msg = 'Before reviewing: ';
        if (readiness.missingFields && readiness.missingFields.length > 0) {
            msg += readiness.missingFields.join('; ');
        } else {
            msg += readiness.reasons.join(', ');
        }
        showMessage(msg + '.', 'warning');
        return;
    }
    const payload = buildPayload();
    state.swapPayload = payload;
    const btn = document.getElementById('reviewBtn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Calculating…';
    const result = await callApi(CONFIG.PREVIEW_ENDPOINT, payload);
    btn.disabled = false;
    btn.innerHTML = original;
    refreshUI();
    if (!result.ok) { showMessage('Preview failed: ' + result.error, 'error'); return; }
    showPreviewModal(result.body);
}
function showPreviewModal(previewData) {
    const data = previewData.preview || {};
    const swapType = state.swapPayload.swap_type;
    const netAmount = data.net_amount_destination_currency || data.net_amount;
    const destCurrency = data.destination_currency || data.source_currency;
    const bodyHtml = `
        <div class="review-hero">
            <div class="review-hero-label">Settlement amount</div>
            <div class="review-hero-amount">${formatMoney(netAmount, destCurrency)}</div>
            <div class="review-hero-note">Real-time quote active</div>
        </div>
        <div class="preview-box">
            <div class="preview-row"><span>Protocol type</span><span class="value">${swapType.replace(/_/g, ' ')}</span></div>
            <div class="preview-row"><span>Source entity</span><span class="value">${escapeHtml(data.source_institution || '—')}</span></div>
            <div class="preview-row"><span>Destination entity</span><span class="value">${escapeHtml(data.destination_institution || '—')}</span></div>
            <div class="preview-row"><span>Principal amount</span><span class="value">${formatMoney(data.amount_requested, data.source_currency)}</span></div>
            <div class="preview-row"><span>Transaction fee</span><span class="value">${formatMoney(data.total_fee, data.source_currency)}</span></div>
        </div>
        <div class="preview-security">
            <div class="preview-security-icon"></div>
            <div class="preview-security-text">
                <strong>Encrypted settlement guaranteed</strong>
                <p>This transaction is protected by VouchMorph's audit-grade multi-layer encryption. All funds are cleared through institutional protocols.</p>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal()">Discard</button>
            <button class="btn btn-primary" onclick="confirmSwap()">Confirm and swap</button>
        </div>`;
    state.lastPreview = previewData;
    openModal('Review swap', bodyHtml);
}
async function confirmSwap() {
    closeModal();
    const payload = state.swapPayload;
    if (!payload) { showMessage('No swap payload to execute', 'error'); return; }
    const btn = document.getElementById('reviewBtn');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Executing…';
    const result = await callApi(CONFIG.EXECUTE_ENDPOINT, payload);
    btn.disabled = false;
    btn.innerHTML = original;
    refreshUI();
    if (!result.ok) { showMessage('Swap failed: ' + result.error, 'error'); return; }
    showResultModal(result.body);
}
function showResultModal(response) {
    const data = response.data || {};
    const swapType = state.swapPayload.swap_type;
    const reference = response.swap_reference || data.reference || '—';
    let inner = '';
    if (swapType === 'CASHOUT') {
        inner = `<div class="result-box"><div class="icon">&#10003;</div><div class="result-title">Cashout code generated</div><div class="result-sub">Reference: ${escapeHtml(reference)}</div>${data.atm_code || data.voucher_number ? `<div class="atm-code"><div class="code">${escapeHtml(data.atm_code || data.voucher_number || '')}</div></div>` : ''}<div style="margin-top:12px;"><div style="font-size:22px;font-weight:600;font-family:var(--font-mono);">${formatMoney(data.amount ?? state.swapPayload.amount, state.swapPayload.currency)}</div></div>`;
    } else if (swapType === 'IDENTITY') {
        const claimPinBox = data.claim_pin ? `
            <div class="atm-code" style="margin-top:12px;">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Backup PIN — only share this if the recipient doesn't get the SMS</div>
                <div class="code">${escapeHtml(data.claim_pin)}</div>
            </div>` : '';
        inner = `<div class="result-box"><div class="icon">&#10003;</div><div class="result-title">Identity swap initiated</div><div class="result-sub">Reference: ${escapeHtml(reference)}</div><div style="margin:12px 0;font-size:13px;"><strong>${escapeHtml(data.identity_type || state.swapPayload.identity_type)}: ${escapeHtml(data.identity_value || state.swapPayload.identity_value)}</strong></div><div style="font-size:22px;font-weight:600;font-family:var(--font-mono);">${formatMoney(data.amount ?? state.swapPayload.amount, data.currency || state.swapPayload.currency)}</div>${claimPinBox}`;
    } else {
        inner = `<div class="result-box"><div class="icon">&#10003;</div><div class="result-title">Swap completed</div><div class="result-sub">Reference: ${escapeHtml(reference)}</div><div style="font-size:22px;font-weight:600;font-family:var(--font-mono);margin-top:12px;">${formatMoney(data.amount ?? state.swapPayload.amount, data.currency || data.destination_currency || state.swapPayload.destination_currency || state.swapPayload.currency)}</div>`;
    }
    openModal('Swap result', `${inner}<div class="cta-row" style="margin-top:16px;"><button class="btn btn-primary" onclick="closeModal(); location.reload();">Done</button></div></div>`);
}

const IDENTITY_TYPE_LABELS = { national_id: 'National ID', birth_certificate: 'Birth Certificate', voter_id: 'Voter ID', phone: 'Phone Number', email: 'Email' };

function getInstitutionCurrency(instCode) {
    if (!instCode) return null;
    return PARTICIPANTS[instCode]?.limits?.currency || null;
}
function updateCurrencyDisplay() {
    const fromCurrency = getInstitutionCurrency(state.fromInst);
    const fromLabel = document.getElementById('fromCurrencyLabel');
    if (fromLabel) fromLabel.textContent = fromCurrency || '';
    const fromInfo = document.getElementById('fromCurrencyInfo');
    if (fromInfo) fromInfo.textContent = state.fromInst ? `Source currency: ${fromCurrency}` : '';
    const preview = document.getElementById('amountPreview');
    if (preview) preview.textContent = formatMoney(state.fromAmount, fromCurrency);
}
function switchCountry(country) {
    if (country !== CONFIG.COUNTRY_CODE) window.location.href = '?country=' + encodeURIComponent(country);
}

function collapseSourcePanel() {
    const wrap = document.getElementById('sourcePanelWrap');
    if (wrap) wrap.style.display = 'none';
    sourcePanelOpenCat = null;
    document.querySelectorAll('.source-type-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.cat === state.fromCategory);
    });
}

async function loadUserSources() {
    if (!CONFIG.USER_ID) return;
    const result = await callApi(CONFIG.API_BASE + '/user/sources.php', {});
    if (result.ok) {
        userSources = result.body.data?.sources || [];
        if (sourcePanelOpenCat === 'WALLET') renderSavedSourceChips();
        const walletBtnCount = document.getElementById('walletBtnCount');
        if (walletBtnCount) {
            if (userSources.length > 0) { walletBtnCount.style.display = 'inline-block'; walletBtnCount.textContent = userSources.length; }
            else { walletBtnCount.style.display = 'none'; }
        }
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
        return assetType === 'ACCOUNT' || assetType === 'WALLET' || assetType === 'MNO-WALLET' || assetType === 'BANK-WALLET' || assetType === 'MOBILE_WALLET' ||
               category === 'MOBILE_MONEY' || category === 'BANK_WALLET' || category === 'BANK_ACCOUNT';
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
    emptyPrompt.style.display = 'none';
    container.style.display = 'block';

    chipsContainer.innerHTML = activeSources.map(source => {
        const instName = PARTICIPANTS[source.institution]?.name || source.institution;
        const identifier = source.identifier || source.source_identifier || '';
        const isSelected = selectedSourceId === source.id;
        return `
            <div class="saved-source-row ${isSelected ? 'active' : ''}" onclick="selectSavedSource('${source.id}')">
                <div class="row-main">
                    <div class="row-inst">${escapeHtml(instName)}</div>
                    <div class="row-ident">${escapeHtml(identifier)}${source.account_name ? ' · ' + escapeHtml(source.account_name) : ''}</div>
                </div>
                <span class="row-check">✓</span>
            </div>`;
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
    fieldsBox.innerHTML = '';
    
    renderDynamicFields('fromFields', source.asset_type, 'fromField_', updateFromField, true);

    setTimeout(() => {
        const assetConfig = getAssetConfig(source.asset_type);
        if (!assetConfig) return;
        
        const fields = assetConfig.fields || [];
        const identifier = source.identifier || source.source_identifier || '';
        
        const identifierField = fields.find(f =>
            f.vault_field !== 'pin' &&
            f.name !== 'amount' &&
            (f.name === 'account_number' || f.name === 'identifier' || f.name === 'account' || 
             f.name === 'phone_number' || f.name === 'phone' || f.name === 'card_number' || 
             f.name === 'wallet_account' || f.name === 'wallet_address' || f.name === 'order_number' || 
             f.name === 'cheque_number' || f.name === 'atm_code' || f.name === 'voucher_number' ||
             f.name === 'source_identifier' || f.name === 'wallet_id')
        );
        
        const pinField = fields.find(f => f.vault_field === 'pin');

        document.querySelectorAll('#fromFields input').forEach(input => {
            if (!input.disabled) input.value = '';
            input.style.background = '#fff';
            input.style.color = 'var(--text)';
        });
        document.querySelectorAll('#fromFields input[disabled]').forEach(input => {
            input.disabled = false;
            input.style.background = '#fff';
            input.style.color = 'var(--text)';
        });

        const newFields = {};

        if (identifierField) {
            const input = document.getElementById(`fromField_${identifierField.name}`);
            if (input) {
                input.value = identifier;
                newFields[identifierField.name] = identifier;
                input.disabled = true;
                input.style.background = 'var(--surface-muted)';
                input.style.color = 'var(--text-dim)';
                let helpText = input.parentElement?.querySelector('.help');
                if (helpText) { 
                    helpText.textContent = 'Auto-filled from your saved source'; 
                    helpText.style.color = 'var(--accent)';
                }
            } else {
                newFields[identifierField.name] = identifier;
            }
        }

        if (pinField) {
            const pin = source.pin || source.source_pin || '';
            if (pin) {
                const pinInput = document.getElementById(`fromField_${pinField.name}`);
                if (pinInput) {
                    pinInput.value = pin;
                    newFields[pinField.name] = pin;
                    pinInput.disabled = true;
                    pinInput.style.background = 'var(--surface-muted)';
                    pinInput.style.color = 'var(--text-dim)';
                } else {
                    newFields[pinField.name] = pin;
                }
            } else {
                const pinInput = document.getElementById(`fromField_${pinField.name}`);
                if (pinInput) {
                    pinInput.placeholder = 'PIN (optional)';
                    pinInput.style.borderColor = 'var(--border)';
                }
            }
        }

        state.fromFields = newFields;
        refreshUI();
    }, 200);

    const helpEl = document.getElementById('sourceSelectedHelp');
    if (helpEl) {
        helpEl.style.display = 'block';
        const config = getAssetConfig(source.asset_type);
        const displayName = config?.label || source.asset_type;
        helpEl.textContent = `${displayName} selected: ${inst?.name || source.institution} — ${source.identifier || ''}. Enter the amount below.`;
    }

    setTimeout(() => {
        document.getElementById('fromAmount')?.focus();
        const config = getAssetConfig(source.asset_type);
        const displayName = config?.label || source.asset_type;
        showMessage(`${displayName} selected: ${inst?.name || source.institution}`, 'success');
        refreshUI();
    }, 300);

    updateCurrencyDisplay();
    collapseSourcePanel();
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

function openMySourcesLegacy() {
    openModal('My sources', renderMySourcesLegacy());
}
function renderMySourcesLegacy() {
    if (userSources.length === 0) {
        return `
            <div style="text-align:center;padding:20px;">
                <div style="font-weight:700;margin:8px 0;">No sources added yet</div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Link your bank accounts, wallets, or cards to use them as swap sources.</div>
                <button class="btn btn-primary" onclick="openAddSource()">Add source</button>
            </div>`;
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
                        ${isActive ? `<button class="btn-primary btn-sm" onclick="useSourceForSwap('${source.id}')" style="margin-left:8px;">Use</button>` : ''}
                        ${isActive ? `<button class="btn-danger-outline" onclick="removeSource('${source.id}')" style="margin-left:4px;">Remove</button>` : ''}
                    </div>
                </div>
                <div class="source-details">
                    ${assetLabel} · ${escapeHtml(source.identifier || source.source_identifier)}
                    ${source.account_name ? ` · ${escapeHtml(source.account_name)}` : ''}
                    ${source.currency ? ` · ${source.currency}` : ''}
                    ${source.confirmed_at ? ` · Confirmed: ${new Date(source.confirmed_at).toLocaleDateString()}` : ''}
                </div>
            </div>`;
    }).join('');
    return `
        <div style="margin-bottom:16px;">
            <button class="btn btn-primary btn-sm" onclick="openAddSource()">Add new source</button>
            <span style="font-size:12px;color:var(--text-muted);margin-left:12px;">${userSources.length} source(s) linked</span>
        </div>
        <div>${sourceList}</div>
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);font-size:11px;color:var(--text-dim);">
            You can add Accounts, Wallets, or Cards here. Vouchers and Bank-Wallets are added manually by admin.
            <br>Click <strong>Use</strong> on any active source to auto-fill the swap form.
        </div>`;
}
function useSourceForSwap(sourceId) {
    closeModal();
    openSourceModal();
    toggleSourcePanel('WALLET');
    setTimeout(() => { selectSavedSource(sourceId); }, 200);
}

function openAddSource() {
    const instOptions = Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('');
    const body = `
        <div style="margin-bottom:16px;">
            <div style="font-size:13px;font-weight:700;margin-bottom:4px;">Link a new source</div>
            <div style="font-size:12px;color:var(--text-muted);">Your bank will verify ownership via OTP or OAuth.</div>
        </div>
        <div class="field-group">
            <label>Institution</label>
            <select id="addSourceInst" onchange="onAddSourceInstChange(this.value)">
                <option value="">Select institution</option>${instOptions}
            </select>
        </div>
        <div class="field-group" id="addSourceAssetGroup" style="display:none;">
            <label>Asset type</label>
            <select id="addSourceAssetType"></select>
            <div class="help">Users can only add Accounts, Wallets, or Cards.</div>
        </div>
        <div class="field-group">
            <label>Identifier</label>
            <input id="addSourceIdentifier" placeholder="Account number, phone, or card number">
            <div class="help">The number that identifies your account at this institution.</div>
        </div>
        <div class="field-group">
            <label>Account name (optional)</label>
            <input id="addSourceAccountName" placeholder="e.g. My Main Account">
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
            <button class="btn btn-secondary" onclick="openMySourcesLegacy()">Cancel</button>
            <button class="btn btn-primary" id="addSourceSubmitBtn" onclick="submitAddSource()">Link source</button>
        </div>`;
    openModal('Add source', body);
}

let addSourceState = { attemptId: null, requiresOtp: false, requiresRedirect: false, redirectUrl: null, institution: null, assetType: null, identifier: null };

function onAddSourceInstChange(code) {
    const group = document.getElementById('addSourceAssetGroup');
    const sel = document.getElementById('addSourceAssetType');
    if (!code) { group.style.display = 'none'; sel.innerHTML = ''; return; }
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
    btn.disabled = true; btn.textContent = 'Registering...';

    const result = await callApi(CONFIG.API_BASE + '/user/add_source.php', { institution, asset_type: assetType, identifier, account_name: accountName || undefined });

    btn.disabled = false; btn.textContent = original;
    if (!result.ok) { showMessage('Failed to add source: ' + result.error, 'error'); return; }

    const data = result.body.data || {};
    addSourceState.attemptId = data.attempt_id || null;
    addSourceState.requiresOtp = data.requires_otp || false;
    addSourceState.requiresRedirect = data.requires_redirect || false;
    addSourceState.redirectUrl = data.redirect_url || null;
    addSourceState.institution = institution; addSourceState.assetType = assetType; addSourceState.identifier = identifier;

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
    setTimeout(() => { loadUserSources(); openMySourcesLegacy(); }, 1500);
}

async function completeSourceOtp() {
    const otp = document.getElementById('addSourceOtp').value.trim();
    if (!otp) { showMessage('Enter the verification code.', 'warning'); return; }
    if (!addSourceState.attemptId) { showMessage('No pending verification attempt.', 'error'); return; }

    const btn = document.querySelector('#addSourceOtpFields .btn-primary');
    const original = btn.textContent;
    btn.disabled = true; btn.textContent = 'Verifying...';

    const result = await callApi(CONFIG.API_BASE + '/user/verify_source.php', { attempt_id: addSourceState.attemptId, otp, user_id: CONFIG.USER_ID });

    btn.disabled = false; btn.textContent = original;
    if (!result.ok) { showMessage('Verification failed: ' + result.error, 'error'); return; }
    showMessage('Source verified and activated!', 'success');
    setTimeout(() => { loadUserSources(); openMySourcesLegacy(); }, 1500);
}

async function removeSource(sourceId) {
    if (!confirm('Remove this source? You can add it again later.')) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/delete.php', { source_id: sourceId });
    if (!result.ok) { showMessage('Failed to remove source: ' + result.error, 'error'); return; }
    showMessage('Source removed.', 'success');
    loadUserSources();
    openMySourcesLegacy();
}

function openPendingSources() {
    openModal('Pending sources', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading pending sources...</div>');
    loadPendingSources();
}
async function loadPendingSources() {
    const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/pending.php', {});
    if (!result.ok) {
        document.getElementById('modalBody').innerHTML = `
            <div style="text-align:center;padding:20px;color:var(--danger);">
                <div style="font-weight:700;">Failed to load pending sources</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">${escapeHtml(result.error)}</div>
                <button class="btn btn-primary btn-sm" onclick="loadPendingSources()" style="margin-top:12px;">Retry</button>
            </div>`;
        return;
    }
    pendingSources = result.body.data || [];
    renderPendingSources();
}
function renderPendingSources() {
    const container = document.getElementById('modalBody');
    if (!pendingSources || pendingSources.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <div style="font-weight:700;font-size:16px;">No pending sources</div>
                <div style="font-size:12px;margin-top:8px;">All your sources are active and verified.</div>
            </div>`;
        return;
    }
    let html = `<div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">${pendingSources.length} source(s) pending verification. Pending sources expire after 3 minutes if not completed.</div><div style="max-height:60vh;overflow-y:auto;">`;
    pendingSources.forEach((source) => {
        const statusLabel = getSourceStatusLabel(source);
        const statusClass = getSourceStatusClass(source);
        const sourceTypeLabel = getSourceTypeLabel(source);
        const canDelete = ['pending_confirmation', 'pending', 'proposed', 'otp_pending', 'oauth_pending'].includes(source.status);
        const canRetry = ['cancelled', 'rejected', 'failed'].includes(source.status);
        const isExpiring = source.is_expiring || false;
        html += `
            <div class="source-card" style="border-left: 3px solid ${isExpiring ? 'var(--warning)' : 'var(--border)'};">
                <div class="source-header">
                    <div>
                        <div class="source-institution">${escapeHtml(source.institution_name || source.institution)}
                            <span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:6px;">(${sourceTypeLabel})</span>
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
                        ${canDelete ? `<button class="btn-danger-outline" onclick="deletePendingSource('${source.type}', ${source.id})">Delete</button>` : ''}
                        ${canRetry ? `<button class="btn-primary btn-sm" onclick="retryPendingSource('${source.type}', ${source.id})">Retry</button>` : ''}
                    </div>
                </div>
                <div style="font-size:11px;color:var(--text-dim);margin-top:6px;">
                    ${source.created_at ? `Added: ${new Date(source.created_at).toLocaleString()}` : ''}
                    ${source.otp_expires_at ? ` · OTP expires: ${new Date(source.otp_expires_at).toLocaleString()}` : ''}
                    ${source.expires_at ? ` · Expires: ${new Date(source.expires_at).toLocaleString()}` : ''}
                </div>
                ${source.error_message ? `<div style="font-size:11px;color:var(--danger);margin-top:4px;">Error: ${escapeHtml(source.error_message)}</div>` : ''}
            </div>`;
    });
    html += `</div>`;
    container.innerHTML = html;
}
function getSourceStatusLabel(source) {
    const statusMap = { pending_confirmation: 'Pending Confirmation', pending: 'Pending', proposed: 'Proposed', otp_pending: 'OTP Verification', oauth_pending: 'OAuth Verification', cancelled: 'Cancelled', rejected: 'Rejected', failed: 'Failed', expired: 'Expired' };
    return statusMap[source.status] || source.status;
}
function getSourceStatusClass(source) {
    const classMap = { pending_confirmation: 'pending', pending: 'pending', proposed: 'pending', otp_pending: 'pending', oauth_pending: 'pending', cancelled: 'inactive', rejected: 'inactive', failed: 'inactive', expired: 'inactive' };
    return classMap[source.status] || 'pending';
}
function getSourceTypeLabel(source) {
    const typeMap = { user_source: 'Source', agent_destination: 'Agent Destination', registration_attempt: 'Verification Attempt', agent_attempt: 'Agent Verification' };
    return typeMap[source.type] || source.type;
}
async function deletePendingSource(type, sourceId) {
    if (!confirm('Delete this pending source? It can be re-added later.')) return;
    const btn = document.querySelector(`[onclick*="deletePendingSource('${type}', ${sourceId})"]`);
    const originalText = btn ? btn.textContent : 'Delete';
    if (btn) { btn.textContent = 'Deleting...'; btn.disabled = true; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/delete.php', { type, source_id: sourceId });
    if (btn) { btn.textContent = originalText; btn.disabled = false; }
    if (!result.ok) { showMessage('Failed to delete source: ' + result.error, 'error'); return; }
    showMessage(result.body.data?.message || 'Source deleted successfully.', 'success');
    loadPendingSources();
}
async function retryPendingSource(type, sourceId) {
    if (!confirm('Retry this source? This will start a new verification attempt.')) return;
    const btn = document.querySelector(`[onclick*="retryPendingSource('${type}', ${sourceId})"]`);
    const originalText = btn ? btn.textContent : 'Retry';
    if (btn) { btn.textContent = 'Retrying...'; btn.disabled = true; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/sources/retry.php', { type, source_id: sourceId });
    if (btn) { btn.textContent = originalText; btn.disabled = false; }
    if (!result.ok) { showMessage('Failed to retry source: ' + result.error, 'error'); return; }
    const data = result.body.data || {};
    if (data.requires_redirect) { showMessage(data.message || 'Redirecting to bank...', 'info'); setTimeout(() => { window.location.href = data.redirect_url; }, 1500); return; }
    if (data.requires_otp) { showMessage(data.message || 'OTP sent. Enter the code to verify.', 'success'); loadPendingSources(); return; }
    showMessage(data.message || 'Source retry initiated successfully.', 'success');
    loadPendingSources();
}

async function openToolbox() {
    openModal('Toolbox', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading...</div>');
    await getCurrentUserRole();
    document.getElementById('modalBody').innerHTML = renderToolbox();
}

function renderToolbox() {
    const pendingCount = pendingSources.length;
    const claimCount = pendingClaims.length;
    const isAgent = !!(SessionUser && SessionUser.is_agent);

    const groups = [
        {
            title: 'Money',
            rows: [
                { label: 'View balance', action: 'viewWalletBalance()' },
                { label: 'My sources', action: 'openMySourcesLegacy()' },
                { label: 'Add source', action: 'openAddSource()' },
                { label: 'Pending sources', badge: pendingCount > 0 ? pendingCount : null, action: 'openPendingSources()' },
                { label: 'Swap history', action: 'openSwapHistory()' },
                { label: 'My VouchMorph Card', action: 'openMyCardModal()' },
            ]
        },
        {
            title: 'Identity',
            rows: [
                { label: 'Finalize identity swap', badge: claimCount > 0 ? claimCount : null, action: isAgent ? 'openAgentFinalizeIdentityModal()' : 'openFinalizeIdentityModal()' },
                { label: 'Register identity', action: 'openAddIdentityModal()' },
            ]
        },
    ];

    if (isAgent) {
        groups.push({
            title: 'Agent',
            rows: [
                { label: 'Agent tools', action: 'openAgentToolsModal()' },
                { label: 'Agent destinations', action: 'openAgentModal()' },
            ]
        });
    }

    groups.push({
        title: 'Account',
        rows: [
            { label: 'My profile', action: 'openProfileModal()' },
            { label: 'Help', action: 'openHelpModal()' },
            { label: 'Terms and conditions', action: 'openTermsModal()' },
        ]
    });

    return groups.map(g => `
        <div class="toolbox-group">
            <div class="toolbox-group-title">${g.title}</div>
            <div class="toolbox-list">${g.rows.map(r => `
                <div class="toolbox-row" onclick="${r.action}">
                    <span class="toolbox-row-label">${r.label}</span>
                    ${r.badge ? `<span class="toolbox-badge" style="display:inline-flex;">${r.badge}</span>` : ''}
                </div>`).join('')}</div>
        </div>`).join('');
}

function updateToolboxBadge() {
    const badge = document.getElementById('toolboxBadge');
    if (!badge) return;
    const totalPending = pendingSources.length + pendingClaims.length;
    if (totalPending > 0) { badge.style.display = 'inline-flex'; badge.textContent = totalPending; } else { badge.style.display = 'none'; }
}

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
                    ${c.voucher_expiry ? `<div><div style="font-size:10px;color:var(--text-dim);">Expires</div><div style="font-size:12px;color:var(--text-muted);">${new Date(c.voucher_expiry).toLocaleString()}</div></div>` : ''}
                </div>` : '';
            return `
            <div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;">
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
            <span class="quick-link muted" onclick="closeModal();openAddIdentityModal();">Need to register a new identity instead? Click here →</span>
        </div>
    `;
}

async function submitDirectClaim() {
    const swapRef = document.getElementById('directClaimRef').value.trim();
    const pin = document.getElementById('directClaimPin').value.trim();
    
    if (!swapRef) { showMessage('Please enter the swap reference.', 'warning'); return; }
    if (!pin) { showMessage('Please enter your claim PIN.', 'warning'); return; }
    if (!/^\d{4,6}$/.test(pin)) { showMessage('PIN must be 4-6 digits.', 'warning'); return; }
    
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/claim_identity.php', {
        swap_reference: swapRef,
        pin: pin
    });
    
    if (!result.ok) { 
        showMessage('Claim failed: ' + result.error, 'error'); 
        return; 
    }
    
    closeModal();
    showMessage('Funds claimed successfully!', 'success');
    checkPendingClaims();
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
        <div class="field-group"><label>Claim PIN</label><input type="password" id="claimPin" inputmode="numeric" maxlength="6" placeholder="••••"><div class="help">${pinHint}</div></div>
        <div class="field-group"><label>Receive as</label><select id="claimDestType" onchange="toggleClaimDestFields(this.value)"><option value="CASHOUT">Cashout (ATM / Agent code)</option><option value="DEPOSIT">Deposit to an account/wallet</option></select></div>
        <div id="claimDepositFields" style="display:none;">
            <div class="field-group"><label>Destination institution</label><select id="claimDestInst"><option value="">Select institution</option>${Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')}</select></div>
            <div class="field-group"><label>Account / wallet number</label><input id="claimDestIdentifier" placeholder="Account number or phone"></div>
        </div>
        <div class="cta-row"><button class="btn btn-secondary" onclick="openFinalizeIdentityModal()">Back</button><button class="btn btn-primary" onclick="submitClaim('${claim.swap_reference}')">Finalize</button></div>`;
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
    if (!result.ok) { showMessage('Claim failed: ' + result.error, 'error'); return; }
    closeModal(); showMessage('Funds claimed successfully!', 'success'); checkPendingClaims();
}

function openAddIdentityModal() {
    openModal('Register identity', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading...</div>');
    getCurrentUserRole().then(session => {
        if (session.is_agent) {
            renderAgentIdentityForm();
        } else {
            renderUserIdentityForm();
        }
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
            
            <div class="field-group">
                <label>Identity value</label>
                <input type="text" id="userIdentityValue" placeholder="Enter the ID number, phone, or email">
            </div>
            
            <div style="background:var(--accent-soft);border-left:3px solid var(--accent);padding:10px 14px;font-size:12px;margin-bottom:14px;">
                Phone numbers are verified instantly via SMS. National IDs and other documents require in-person verification by a VouchMorph agent.
            </div>
            
            <div id="regIdentityOtpFields" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;" id="regIdentityOtpMessage"></div>
                <div class="otp-input-group">
                    <input type="text" id="regIdentityOtp" placeholder="Enter verification code" inputmode="numeric" maxlength="8">
                    <button class="btn btn-primary btn-sm" onclick="submitVerifyIdentityOtp()">Verify</button>
                </div>
            </div>
            
            <div class="cta-row">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="submitRegisterIdentity()">Register identity</button>
            </div>
            
            <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;font-size:11px;color:var(--text-dim);">
                <span class="quick-link muted" onclick="closeModal();openFinalizeIdentityModal();">Need to claim a swap sent to your identity? Click here →</span>
            </div>
        </div>`;
}

function renderAgentIdentityForm() {
    document.getElementById('modalBody').innerHTML = `
        <div>
            <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Register a verified identity (Agent)</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Use this after physically verifying the person's document.</div>
            
            <div class="field-group">
                <label>Account holder's phone or email</label>
                <input type="text" id="agentTargetLookup" placeholder="Phone or email on their VouchMorph account">
            </div>
            
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
            
            <div class="field-group">
                <label>ID number</label>
                <input type="text" id="agentIdentityValue" placeholder="Document number">
            </div>
            
            <div class="field-group">
                <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:400;">
                    <input type="checkbox" id="agentDocVerified" style="width:auto;"> I have physically verified this document
                </label>
            </div>
            
            <div class="cta-row">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="submitAgentIdentity()">Register identity</button>
            </div>
        </div>`;
}

async function submitRegisterIdentity() {
    const identityType = document.getElementById('userIdentityType').value;
    const identityValue = document.getElementById('userIdentityValue').value.trim();
    
    if (!identityValue) { 
        showMessage('Please enter the identity value.', 'warning'); 
        return; 
    }
    
    const result = await callApi(CONFIG.API_BASE + '/user/add_identity.php', {
        identity_type: identityType,
        identity_value: identityValue
    });
    
    if (!result.ok) { 
        showMessage('Could not register identity: ' + result.error, 'error'); 
        return; 
    }
    
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
    
    if (!lookup) { showMessage('Please enter the account holder\'s contact.', 'warning'); return; }
    if (!value) { showMessage('Please enter the ID number.', 'warning'); return; }
    if (!verified) { showMessage('You must confirm you verified the document.', 'warning'); return; }
    
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/add_verified_identity.php', {
        target_lookup: lookup,
        identity_type: type,
        identity_value: value,
        document_verified: true
    });
    
    if (!result.ok) {
        showMessage('Failed to register identity: ' + result.error, 'error');
        return;
    }
    
    showMessage(result.body.message || 'Identity registered successfully.', 'success');
    setTimeout(() => closeModal(), 2000);
}

async function submitVerifyIdentityOtp() {
    const otp = document.getElementById('regIdentityOtp').value.trim();
    if (!otp) { 
        showMessage('Enter the verification code.', 'warning'); 
        return; 
    }
    
    const result = await callApi(CONFIG.API_BASE + '/user/verify_identity_otp.php', {
        attempt_id: regIdentityState.attemptId,
        otp: otp
    });
    
    if (!result.ok) {
        showMessage('Verification failed: ' + result.error, 'error');
        return;
    }
    
    showMessage('Identity verified. You can now receive swaps.', 'success');
    setTimeout(() => closeModal(), 2000);
}

async function getCurrentUserRole() {
    if (SessionUser) return SessionUser;
    const result = await callApi(CONFIG.API_BASE + '/user/whoami.php', {});
    if (result.ok && result.body) {
        SessionUser = result.body;
    } else {
        SessionUser = { success: false, role: 'user', is_agent: false, is_admin: false, permissions: [] };
    }
    const badge = document.getElementById('agentBadge');
    if (badge) badge.style.display = SessionUser.is_agent ? 'inline-block' : 'none';
    return SessionUser;
}

async function loadAgentStatus() {
    if (!CONFIG.USER_ID) return;
    await getCurrentUserRole();
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) return;
    agentStatus = result.body.data;
    agentStatus.is_agent = SessionUser.is_agent;
}

async function openAgentModal() {
    openModal('Agent account', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading...</div>');
    await getCurrentUserRole();
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/status.php', {});
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="color:var(--danger);">Failed to load agent status: ${escapeHtml(result.error)}</div>`; return; }
    agentStatus = result.body.data;
    agentStatus.is_agent = SessionUser.is_agent;
    document.getElementById('modalBody').innerHTML = renderAgentModal();
}

function renderAgentModal() {
    const activeDestinations = agentStatus.all_destinations.filter(d => d.status !== 'cancelled' && !d.deleted_at);
    const statusRows = activeDestinations.length ? activeDestinations.map(d => {
        const isPending = d.status === 'pending_confirmation';
        const isRejected = d.status === 'rejected';
        const canCancel = isPending || isRejected;
        const badge = d.status === 'active' ? '<span style="background:rgba(31,138,84,0.1);color:var(--success);padding:2px 8px;font-size:10px;font-weight:700;">Active</span>'
            : isPending ? '<span style="background:#fef3c7;color:#8a5a0b;padding:2px 8px;font-size:10px;font-weight:700;">Pending approval</span>'
            : '<span style="background:#fbeceb;color:var(--danger);padding:2px 8px;font-size:10px;font-weight:700;">' + (d.status || 'Unknown') + '</span>';
        return `<div style="border:1px solid var(--border);padding:12px;margin-bottom:8px;background:#fff;">
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
    if (eligible.length === 0) { group.style.display = 'block'; sel.innerHTML = '<option value="">No eligible account types at this institution</option>'; return; }
    sel.innerHTML = eligible.map(t => `<option value="${t}">${getAssetConfig(t)?.label || t}</option>`).join('');
    group.style.display = 'block';
}

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
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">If the client wants this identity permanently registered to their VouchMorph account — optional, and separate from finalizing any swap — you can do that here instead.</div>
            <span class="quick-link muted" onclick="closeModal();openAddIdentityModal();">Register identity to client's account →</span>
        </div>`;
}

function openAgentToolsModal() { openModal('Agent tools', renderAgentToolsSearch()); }

function renderAgentToolsSearch() {
    return `
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">Search for a client's pending identity payment. You'll need to physically verify their document and have them tell you the OTP PIN texted to them — never their personal VouchMorph transaction PIN — before you can finalize.</div>
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
                <div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                        <div>
                            <div style="font-weight:700;font-size:16px;color:var(--accent);font-family:var(--font-mono);">${formatMoney(totalAmount, currency)}</div>
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
        <div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                <div>
                    <div style="font-weight:700;font-size:16px;color:var(--accent);font-family:var(--font-mono);">${formatMoney(totalAmount, currency)}</div>
                    <div style="font-size:12px;color:var(--text-muted);">From ${swapCount} different source(s)</div>
                    <div style="font-size:11px;color:var(--text-dim);">Expires ${data.earliest_expires_at ? new Date(data.earliest_expires_at).toLocaleString() : 'soon'}</div>
                </div>
                <button class="btn btn-primary btn-sm" onclick="openAgentFinalizeFormAggregated('${identityTypeEscaped}', '${identityValueEscaped}', '${currency}', ${totalAmount}, ${swapCount})">Claim</button>
            </div>
        </div>`;
}

function openAgentFinalizeFormAggregated(identityType, identityValue, currency, totalAmount, swapCount) {
    const data = agentSearchData;
    if (!data) { showMessage('Search data not found. Please search again.', 'error'); return; }
    if (!agentStatus.approved_destinations || agentStatus.approved_destinations.length === 0) {
        openModal('Agent tools', '<div style="color:var(--danger);">You have no approved agent destination account. Register one first.</div>');
        return;
    }
    const destOptions = agentStatus.approved_destinations.map(d => `<option value="${d.id}">${escapeHtml(PARTICIPANTS[d.institution]?.name || d.institution)} - ${escapeHtml(d.identifier)}</option>`).join('');
    const searchTypeLabel = IDENTITY_TYPE_LABELS[document.getElementById('agentSearchType')?.value] || 'document';
    const body = `
        <div style="background:var(--accent-soft);padding:14px;margin-bottom:14px;">
            <div style="font-size:12px;color:var(--text-muted);">Client's total balance</div>
            <div style="font-size:22px;font-weight:600;color:var(--accent);font-family:var(--font-mono);">${formatMoney(totalAmount, currency)}</div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">This is an aggregated balance from ${swapCount} different source(s). The full amount deposits into your account. Whatever the client doesn't take as cash today is instantly sent back to their identity as a new claim.</div>
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
            <div class="help">This is the OTP PIN sent by SMS — never a personal transaction PIN. The client must tell you this themselves; never accept a claim without it.</div>
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
    if (!docVerified) { showMessage('You must confirm you verified the client\'s physical document.', 'warning'); return; }
    if (!pin) { showMessage('Enter the client\'s claim PIN.', 'warning'); return; }
    if (isNaN(cashNowAmount) || cashNowAmount < 0 || cashNowAmount > totalAmount) { showMessage(`Cash amount must be between 0 and ${totalAmount}.`, 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/agent/finalize_claim.php', { identity_type: identityType, identity_value: identityValue, pin, identity_document_verified: true, destination_account_id: parseInt(destinationAccountId, 10), cash_now_amount: cashNowAmount });
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
    if (netDeposited > 0) { msg += `Deposited ${formatMoney(netDeposited, currency)} into your account`; if (totalFees > 0) msg += ` (fee: ${formatMoney(totalFees, currency)})`; msg += '. '; }
    if (cashGiven > 0) msg += `Gave client ${formatMoney(cashGiven, currency)} in cash. `; else msg += `No cash given now. `;
    if (remainder > 0) msg += `The remaining ${formatMoney(remainder, currency)} was sent back to their identity — a new PIN was texted to them. `;
    if (successfulSwaps > 1) { msg += `(Processed ${successfulSwaps} source(s)`; if (failedSwaps > 0) msg += `, ${failedSwaps} failed`; msg += `)`; }
    else if (failedSwaps > 0) msg += `(${failedSwaps} source(s) failed)`;
    if (data.status === 'partial_success') msg += ' Partial success — some sources failed.';
    showMessage(msg, 'success');
    agentSearchData = null;
}

async function openSwapHistory() {
    openModal('Swap history', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading swaps...</div>');
    if (!CONFIG.USER_ID) {
        document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:30px;color:var(--danger);"><div style="font-weight:700;">Could not identify your account</div><div style="font-size:12px;color:var(--text-muted);margin-top:8px;">Your session doesn't have a user ID attached. Try logging out and back in.</div></div>`;
        return;
    }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/history.php', { user_id: CONFIG.USER_ID, limit: 50 });
    if (!result.ok) { document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger);">Failed to load swap history: ${escapeHtml(result.error)}</div>`; return; }
    renderSwapHistory(result.body);
}

function renderSwapHistory(data) {
    const swaps = data.data || data.swaps || [];
    if (swaps.length === 0) { 
        document.getElementById('modalBody').innerHTML = `<div style="text-align:center;padding:30px;color:var(--text-muted);"><div style="font-weight:700;">No swaps found</div></div>`; 
        return; 
    }
    let historyHtml = `<div style="max-height:60vh;overflow-y:auto;"><div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Showing ${swaps.length} swap(s)</div>`;
    swaps.forEach((swap) => {
        const statusColor = swap.status === 'completed' || swap.status === 'success' ? 'var(--success)' : swap.status === 'pending' ? 'var(--warning)' : 'var(--danger)';
        const code = swap.voucher_number || null;
        const pin = swap.atm_pin || null;
        const claimPin = swap.claim_pin || null;
        const hasCode = !!(code || pin || claimPin);
        const codeInlineHtml = hasCode ? `
            <div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--border);display:flex;gap:16px;flex-wrap:wrap;">
                ${code ? `<div><div style="font-size:10px;color:var(--text-dim);">Code</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(code)}</div></div>` : ''}
                ${pin ? `<div><div style="font-size:10px;color:var(--text-dim);">PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(pin)}</div></div>` : ''}
                ${claimPin ? `<div><div style="font-size:10px;color:var(--text-dim);">Claim PIN</div><div style="font-family:var(--font-mono);font-weight:700;font-size:14px;color:var(--accent);">${escapeHtml(claimPin)}</div></div>` : ''}
                ${swap.voucher_expiry ? `<div><div style="font-size:10px;color:var(--text-dim);">Expires</div><div style="font-size:12px;color:var(--text-muted);">${new Date(swap.voucher_expiry).toLocaleString()}</div></div>` : ''}
            </div>` : '';
        historyHtml += `<div style="border:1px solid var(--border);padding:13px;margin-bottom:8px;background:#fff;cursor:pointer;" onclick="viewSwapDetail('${swap.reference || swap.swap_reference || 'N/A'}')">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div><div style="font-weight:700;">${swap.swap_type || 'SWAP'} <span style="font-size:11px;color:var(--text-muted);">${swap.reference || swap.swap_reference || ''}</span></div><div style="font-size:12px;color:var(--text-muted);">${swap.source_institution || 'Unknown'} → ${swap.destination_institution || 'Unknown'}</div></div>
                <div style="text-align:right;"><div style="font-weight:700;color:var(--accent);font-family:var(--font-mono);">${formatMoney(swap.amount, swap.currency)}</div><div style="font-size:11px;color:${statusColor};">${swap.status || 'unknown'}</div></div>
            </div>${codeInlineHtml}</div>`;
    });
    historyHtml += `</div>`;
    document.getElementById('modalBody').innerHTML = historyHtml;
}
async function viewSwapDetail(reference) {
    openModal('Swap details', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading details...</div>');
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
            <div style="background:var(--accent-soft);padding:16px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div><div style="font-size:12px;color:var(--text-muted);">Reference</div><div style="font-weight:700;">${swap.reference || swap.swap_reference || 'N/A'}</div></div>
                    <div><div style="font-size:12px;color:var(--text-muted);">Status</div><div style="font-weight:700;">${swap.status || 'unknown'}</div></div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                <div style="background:var(--surface);border:1px solid var(--border);padding:12px;"><div style="font-size:11px;color:var(--text-muted);">Swap type</div><div style="font-weight:700;">${swap.swap_type || 'N/A'}</div></div>
                <div style="background:var(--surface);border:1px solid var(--border);padding:12px;"><div style="font-size:11px;color:var(--text-muted);">Amount</div><div style="font-weight:700;font-size:16px;color:var(--accent);font-family:var(--font-mono);">${formatMoney(swap.amount, swap.currency)}</div></div>
            </div>
            ${codeBox}
            <div style="margin-top:12px;"><button class="btn btn-secondary" onclick="openSwapHistory()" style="width:100%;">Back to history</button></div>
        </div>`;
}

function openProfileModal() { openModal('My profile', renderProfileModal()); }

function renderProfileModal() {
    const rows = savedIdentities.length ? savedIdentities.map((id, i) => `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);">
            <div><div style="font-size:11px;color:var(--text-muted);">${escapeHtml(IDENTITY_TYPE_LABELS[id.type] || id.type)}</div><div style="font-size:14px;font-weight:700;">${escapeHtml(id.value)}</div></div>
            <div class="quick-actions" style="margin:0;"><span class="quick-link" onclick="useSavedIdentity(${i})">Use</span><span class="quick-link danger" onclick="removeSavedIdentity(${i})">Remove</span></div>
        </div>`).join('') : `<div style="font-size:12px;color:var(--text-dim);">No saved identities yet.</div>`;
    
    return `
        <div style="margin-bottom:12px;">
            <div style="font-weight:700;margin-bottom:4px;">Your registered identities</div>
            ${rows}
        </div>
        <div style="border-top:1px solid var(--border);padding-top:16px;">
            <div class="field-label" style="margin-bottom:8px;">Transaction PIN</div>
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Required to claim money sent to your verified identity. Never share it.</div>
            <div class="field-group"><label>New PIN (4-6 digits)</label><input type="password" id="newPin" inputmode="numeric" maxlength="6" placeholder="••••"></div>
            <div class="field-group"><label>Confirm PIN</label><input type="password" id="confirmPin" inputmode="numeric" maxlength="6" placeholder="••••"></div>
            <div class="cta-row"><button class="btn btn-primary" onclick="setTransactionPin()">Set PIN</button></div>
        </div>
        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;">
            <span class="quick-link" onclick="closeModal();openAddIdentityModal();">Add a new identity</span>
            <span class="quick-link muted" onclick="closeModal();openFinalizeIdentityModal();">Finalize an identity swap</span>
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
    closeModal();
    openIdentitySendModal();
    state.toIdentityType = id.type; state.toIdentityValue = id.value;
    setTimeout(() => {
        const typeSel = document.getElementById('identityType');
        const valInput = document.getElementById('identityValue');
        if (typeSel) typeSel.value = id.type;
        if (valInput) valInput.value = id.value;
        updateIdentityHelp(); refreshUI();
    }, 50);
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

function openHelpModal() {
    openModal('Help', `
        <div style="font-size:13px;line-height:1.7;color:var(--text);">
            <p style="font-weight:700;margin-bottom:6px;">Swapping money</p>
            <ol style="padding-left:18px;margin-bottom:16px;">
                <li>Tap "Source" — choose Wallet/Account, Card, or Voucher.</li>
                <li>Tap "Destination" — Deposit or Cashout.</li>
                <li>Enter the amount at the top, review the fee, and confirm.</li>
            </ol>
            <p style="font-weight:700;margin-bottom:6px;">Swapping to an identity</p>
            <ol style="padding-left:18px;margin-bottom:16px;">
                <li>Tap "Swap to identity" below the amount.</li>
                <li>Enter their national ID, phone, or email.</li>
            </ol>
            <p style="font-weight:700;margin-bottom:6px;">Combining multiple sources</p>
            <ol style="padding-left:18px;margin-bottom:16px;">
                <li>Tap "Combine sources" below the amount.</li>
                <li>Type the total amount — it splits evenly across your sources, or pick Ratio, Smart, or Manual.</li>
                <li>Pick where it settles: an account/wallet/card, an identity, or a VouchMorph Card.</li>
            </ol>
            <p style="font-weight:700;margin-bottom:6px;">Your VouchMorph Card</p>
            <ol style="padding-left:18px;margin-bottom:16px;">
                <li>Open Toolbox &rarr; My VouchMorph Card. Every account gets one automatically.</li>
                <li>It starts inactive — activate it once with a small one-time fee from any linked source.</li>
                <li>Once active, other VouchMorph users can hook their own sources to your card by scanning its QR code, and you can hook your sources to theirs the same way.</li>
                <li>When multiple people are hooked, the card owner starts a payment, picks how contributions should split (Equal, Ratio, Smart, or Manual), and everyone watches the live progress until it's fully covered — then the owner executes it.</li>
            </ol>
            <p style="font-weight:700;margin-bottom:6px;">Claiming money sent to you</p>
            <ol style="padding-left:18px;margin-bottom:16px;">
                <li>Open Toolbox &rarr; Finalize identity swap.</li>
                <li>Enter your claim PIN and choose how to receive it.</li>
            </ol>
            <p style="font-weight:700;margin-bottom:6px;">Agent access</p>
            <ol style="padding-left:18px;">
                <li>Agent access is granted by VouchMorph admin staff to your account.</li>
                <li>Once granted, open Toolbox &rarr; Agent destinations to register a business account, then use Agent tools to search and finalize client claims.</li>
            </ol>
        </div>`);
}

function openTermsModal() {
    openModal('Terms and conditions', `
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
        </div>`);
}

async function checkPendingClaims() {
    if (!CONFIG.USER_ID) return;
    try {
        const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/pending_claims.php', {}); 
        if (!result.ok) return;
        pendingClaims = result.body.data || [];
        updateToolboxBadge();
    } catch (e) { 
        console.error('[claims] Failed to check pending claims', e); 
    }
}

function openModal(title, bodyHtml) {
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');
    const modal = document.getElementById('modal');
    
    if (modalTitle) modalTitle.textContent = title;
    if (modalBody) modalBody.innerHTML = bodyHtml;
    if (modal) modal.classList.add('active');
}

function closeModal() {
    document.getElementById('modal').classList.remove('active');
    stopSessionPolling();
    returnAllMovableNodesHome();
    updateSelectionChips();
    refreshUI();
}

function showMessage(text, type = 'info') {
    const el = document.getElementById('mainMessage');
    el.textContent = text; el.className = `message show ${type}`;
    clearTimeout(showMessage._t);
    showMessage._t = setTimeout(() => el.classList.remove('show'), 6000);
}

function escapeHtml(str) { const div = document.createElement('div'); div.textContent = str == null ? '' : String(str); return div.innerHTML; }

// ============================================================
// VOUCHMORPH CARD — hooking, QR, activation, contribution sessions
// ============================================================

let myCard = null;
let activeSessionPollTimer = null;
let html5QrScanner = null;

async function callApiGet(endpoint) {
    let url = endpoint;
    if (CONFIG.IS_TEST_MODE) url += (url.includes('?') ? '&' : '?') + 'test_mode=1';
    let response, body;
    try {
        response = await fetch(url, { method: 'GET', headers: buildHeaders(), credentials: 'include' });
    } catch (networkErr) {
        return { ok: false, error: 'Network error: could not reach ' + url + ' (' + networkErr.message + ')' };
    }
    try { body = await response.json(); } catch (parseErr) {
        return { ok: false, error: 'Server returned a non-JSON response (HTTP ' + response.status + ')' };
    }
    if (!response.ok || body.success === false) return { ok: false, error: body.error || ('HTTP ' + response.status), body };
    return { ok: true, body };
}

// ------------------------------------------------------------
// Entry point — Toolbox → My VouchMorph Card. Every account has a
// card auto-provisioned server-side on first call; it may still be
// INACTIVE, which is a normal, expected state, not an error.
// ------------------------------------------------------------
async function openMyCardModal() {
    openModal('My VouchMorph Card', '<div style="text-align:center;padding:20px;"><div class="spinner"></div> Loading...</div>');
    try {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/My.php');
        if (!result.ok) {
            console.error('[card] My.php failed:', result.error);
            document.getElementById('modalBody').innerHTML = `
                <div style="color:var(--danger);padding:12px;">
                    <div style="font-weight:700;margin-bottom:6px;">Couldn't load your card</div>
                    <div style="font-size:12px;">${escapeHtml(result.error)}</div>
                    <button class="btn btn-secondary btn-sm" onclick="openMyCardModal()" style="margin-top:12px;">Retry</button>
                </div>`;
            return;
        }
        myCard = result.body.data;
        document.getElementById('modalBody').innerHTML = renderMyCardModal();
        if (myCard.is_active && myCard.qr_payload) {
            renderCardQr(myCard.qr_payload);
        }
        if (myCard.active_session) {
            startSessionPolling(myCard.active_session.session_id);
        }
    } catch (e) {
        // Catches JS-side failures (bad response shape, a render error,
        // etc.) that would otherwise leave the spinner stuck forever
        // with the real cause only visible in the console.
        console.error('[card] openMyCardModal threw:', e);
        document.getElementById('modalBody').innerHTML = `
            <div style="color:var(--danger);padding:12px;">
                <div style="font-weight:700;margin-bottom:6px;">Something went wrong loading your card</div>
                <div style="font-size:12px;">${escapeHtml(e.message || String(e))}</div>
                <button class="btn btn-secondary btn-sm" onclick="openMyCardModal()" style="margin-top:12px;">Retry</button>
            </div>`;
    }
}

function renderMyCardModal() {
    if (!myCard.is_active) {
        return `
            <div style="text-align:center;padding:10px 0 20px;">
                <div style="font-weight:700;font-size:16px;margin-bottom:6px;">Your VouchMorph Card is ready</div>
                <div style="font-size:12px;color:var(--text-muted);max-width:320px;margin:0 auto 18px;">
                    It's issued but not active yet — activate it once with a small one-time fee from any of your linked sources, and it's ready to use.
                </div>
                <div style="font-family:var(--font-mono);font-size:14px;font-weight:700;margin-bottom:4px;">•••• ${escapeHtml(myCard.card_suffix)}</div>
                <div style="font-size:12px;color:var(--text-dim);margin-bottom:18px;">Activation fee: ${formatMoney(myCard.activation_fee, myCard.currency)}</div>
                <div class="cta-row"><button class="btn btn-primary" onclick="openActivateCardModal()">Activate my card</button></div>
            </div>`;
    }

    const hook = myCard.hook;
    const contributorsHtml = hook && hook.contributors.length
        ? hook.contributors.map(c => `
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:12px;">
                <span>${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)} · ${escapeHtml(c.source_identifier)} ${c.is_me ? '<strong>(you)</strong>' : ''}</span>
                <span style="font-family:var(--font-mono);font-weight:600;">${formatMoney(c.held_amount, hook.currency)}</span>
            </div>`).join('')
        : `<div style="font-size:12px;color:var(--text-dim);">No sources hooked yet.</div>`;

    return `
        <div style="text-align:center;margin-bottom:16px;">
            <div id="cardQrContainer" style="display:inline-block;padding:12px;background:#fff;border:1px solid var(--border-strong);"></div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:8px;">Scan to hook a source to this card</div>
            <div style="font-family:var(--font-mono);font-size:13px;font-weight:700;margin-top:4px;">•••• ${escapeHtml(myCard.card_suffix)}</div>
        </div>

        <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:14px;">
            <div class="field-label" style="margin-bottom:8px;">Hooked sources ${hook ? `— ${formatMoney(hook.total_held, hook.currency)} total, expires ${new Date(hook.expires_at).toLocaleString()}` : ''}</div>
            ${contributorsHtml}
        </div>

        <div class="cta-row" style="margin-top:16px;">
            <button class="btn btn-secondary" onclick="openHookSourceModal(myCard.card_suffix)">Hook a source</button>
            ${hook && hook.contributors.length ? `<button class="btn btn-primary" onclick="openCreateSessionModal(myCard.card_suffix)">Start a payment</button>` : ''}
        </div>

        <div id="sessionStatusArea" style="margin-top:16px;">${myCard.active_session ? renderSessionStatus(myCard.active_session) : ''}</div>

        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px;">
            <span class="quick-link" onclick="openScanToHookModal()">Hook to someone else's card (scan their QR)</span>
        </div>`;
}

function renderCardQr(payloadText) {
    const el = document.getElementById('cardQrContainer');
    if (!el || typeof QRCode === 'undefined') return;
    el.innerHTML = '';
    new QRCode(el, { text: payloadText, width: 180, height: 180 });
}

// ------------------------------------------------------------
// Activation — one-time fee from any source, flips INACTIVE -> ACTIVE
// ------------------------------------------------------------
function openActivateCardModal() {
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = '';
    modalBody.appendChild(document.getElementById('fromSection'));
    const feeNote = document.createElement('div');
    feeNote.style.cssText = 'font-size:12px;color:var(--text-dim);text-align:center;margin-top:10px;';
    feeNote.textContent = `A one-time ${formatMoney(myCard.activation_fee, myCard.currency)} activation fee will be charged from the source you select.`;
    modalBody.appendChild(feeNote);
    const btnRow = document.createElement('div');
    btnRow.className = 'cta-row';
    btnRow.style.marginTop = '14px';
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

    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Activate.php', {
        card_suffix: cardSuffix,
        institution: state.fromInst,
        asset_type: state.fromAsset,
        identifier: identifier,
        pin: pin || undefined,
    });

    if (!result.ok) { showMessage('Activation failed: ' + result.error, 'error'); return; }
    showMessage('Card activated!', 'success');
    closeModal();
    openMyCardModal();
}

// ------------------------------------------------------------
// Hooking a source to a card (yours or someone else's)
// ------------------------------------------------------------
function openHookSourceModal(targetCardSuffix) {
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = '';
    modalBody.appendChild(document.getElementById('fromSection'));
    const btnRow = document.createElement('div');
    btnRow.className = 'cta-row';
    btnRow.style.marginTop = '20px';
    btnRow.innerHTML = `<button type="button" class="btn btn-primary" onclick="confirmHookSource('${targetCardSuffix}')">Hook this source</button>`;
    modalBody.appendChild(btnRow);
    document.getElementById('modalTitle').textContent = 'Hook a source';
    document.getElementById('modal').classList.add('active');
}

async function confirmHookSource(targetCardSuffix) {
    const hasSource = !!(state.fromInst && state.fromAsset && fieldsValidForAsset(state.fromAsset, state.fromFields, true).valid);
    if (!hasSource) { showMessage('Finish selecting the source — institution, asset type, and required fields.', 'warning'); return; }

    const idField = (getAssetConfig(state.fromAsset)?.fields || []).find(f => f.vault_field !== 'pin' && f.name !== 'amount');
    const identifier = idField ? state.fromFields[idField.name] : null;
    const pin = extractPinFromFields(state.fromAsset, state.fromFields);

    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/hook.php', {
        card_suffix: targetCardSuffix,
        sources: [{
            institution: state.fromInst,
            asset_type: state.fromAsset,
            identifier: identifier,
            wallet_pin: pin || undefined,
            pin: pin || undefined,
        }],
    });

    if (!result.ok) { showMessage('Hook failed: ' + result.error, 'error'); return; }
    showMessage('Source hooked successfully.', 'success');
    closeModal();
    openMyCardModal();
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
        html5QrScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: 220 },
            (decodedText) => {
                html5QrScanner.stop().catch(() => {});
                resolveScannedQr(decodedText);
            },
            () => {}
        ).catch((e) => {
            console.warn('[card-qr] camera scan unavailable, manual paste only:', e);
        });
    } catch (e) {
        console.warn('[card-qr] Html5Qrcode not available, manual paste only:', e);
    }
}

function submitManualQr() {
    const raw = document.getElementById('manualQrPaste').value.trim();
    if (!raw) { showMessage('Paste the code first, or use the camera scanner above.', 'warning'); return; }
    resolveScannedQr(raw);
}

async function resolveScannedQr(raw) {
    if (html5QrScanner) { try { await html5QrScanner.stop(); } catch (e) {} }

    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/ResolveQr.php', { raw });
    if (!result.ok) { showMessage('Could not read that code: ' + result.error, 'error'); return; }

    const { card_suffix, display_name } = result.body.data;
    openModal('Confirm', `
        <div style="text-align:center;padding:16px;">
            <div style="font-size:14px;margin-bottom:16px;">You're about to hook a source to <strong>${escapeHtml(display_name)}'s</strong> VouchMorph Card.</div>
            <div class="cta-row">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="openHookSourceModal('${escapeHtml(card_suffix)}')">Continue</button>
            </div>
        </div>`);
}

// ------------------------------------------------------------
// Contribution sessions — owner creates, everyone watches live
// ------------------------------------------------------------
function openCreateSessionModal(cardSuffix) {
    const instOptions = Object.keys(PARTICIPANTS).map(c => `<option value="${c}">${PARTICIPANTS[c]?.name || c}</option>`).join('');
    openModal('Start a payment', `
        <div class="field-group"><label>Amount needed at destination</label><input type="number" id="sessTarget" min="0.01" step="0.01" placeholder="0.00"></div>
        <div class="field-group"><label>Currency</label><input id="sessCurrency" value="${myCard.hook?.currency || myCard.currency || 'BWP'}"></div>
        <div class="field-group"><label>Destination institution</label><select id="sessToInst"><option value="">Select</option>${instOptions}</select></div>
        <div class="field-group"><label>Destination account/wallet number</label><input id="sessToIdentifier" placeholder="Account number or phone"></div>
        <div class="field-group"><label>Strategy — how should contributions split?</label>
            <select id="sessStrategy">
                <option value="EQUAL">Equal — split evenly across hooked sources</option>
                <option value="RATIO">Ratio — proportional to each source's balance</option>
                <option value="SMART" selected>Smart — VouchMorph balances it automatically</option>
                <option value="MANUAL">Manual — each person enters their own amount</option>
            </select>
        </div>
        <div style="font-size:11px;color:var(--text-dim);margin-bottom:12px;">Everyone currently hooked will see this in real time. You decide the strategy; they follow it — under Manual, each person enters their own share.</div>
        <div class="cta-row"><button class="btn btn-primary" onclick="submitCreateSession('${cardSuffix}')">Start session</button></div>`);
}

async function submitCreateSession(cardSuffix) {
    const target = parseFloat(document.getElementById('sessTarget').value);
    const currency = document.getElementById('sessCurrency').value.trim();
    const toInst = document.getElementById('sessToInst').value;
    const toIdentifier = document.getElementById('sessToIdentifier').value.trim();
    const strategy = document.getElementById('sessStrategy').value;

    if (!(target > 0)) { showMessage('Enter the amount needed.', 'warning'); return; }
    if (!toInst || !toIdentifier) { showMessage('Select a destination institution and enter an account/wallet number.', 'warning'); return; }

    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Create.php', {
        card_suffix: cardSuffix,
        target_amount: target,
        currency: currency,
        strategy: strategy,
        to_institution: toInst,
        destination_identifier: toIdentifier,
    });

    if (!result.ok) { showMessage('Could not start session: ' + result.error, 'error'); return; }
    showMessage('Session started.', 'success');
    openMyCardModal();
}

function renderSessionStatus(session) {
    const preview = session.preview || { total_target: session.target_amount, total_covered: 0, remaining: session.target_amount, contributors: [] };
    const pct = preview.total_target > 0 ? Math.min(100, (preview.total_covered / preview.total_target) * 100) : 0;

    const rowsHtml = (preview.contributors || []).map(c => `
        <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;border-bottom:1px solid var(--border);">
            <span>${escapeHtml(PARTICIPANTS[c.institution]?.name || c.institution)} · ${escapeHtml(c.source_identifier)}${c.below_minimum ? ' <span style="color:var(--warning);">(below minimum)</span>' : ''}</span>
            <span style="font-family:var(--font-mono);font-weight:600;">${formatMoney(c.amount, session.currency)}</span>
        </div>`).join('');

    const manualInput = session.strategy === 'MANUAL' ? `
        <div class="field-group" style="margin-top:10px;">
            <label>Your contribution</label>
            <div style="display:flex;gap:8px;">
                <input type="number" id="myManualAmount" min="0" step="0.01" placeholder="0.00" style="flex:1;">
                <button class="btn btn-secondary btn-sm" onclick="submitMyManualAmount(${session.session_id})">Set</button>
            </div>
        </div>` : '';

    return `
        <div style="border-top:1px solid var(--border);padding-top:14px;">
            <div class="field-label" style="margin-bottom:6px;">Payment in progress — ${escapeHtml(session.strategy)}</div>
            <div class="comp-bar-track"><div class="comp-bar-seg" style="width:${pct}%;background:var(--accent);"></div></div>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:10px;">
                <span>${formatMoney(preview.total_covered, session.currency)} of ${formatMoney(preview.total_target, session.currency)}</span>
                <span style="color:var(--text-dim);">${preview.remaining > 0 ? formatMoney(preview.remaining, session.currency) + ' remaining' : 'Fully covered'}</span>
            </div>
            ${rowsHtml}
            ${manualInput}
            <div class="cta-row" style="margin-top:14px;">
                <button class="btn btn-secondary" onclick="cancelSession(${session.session_id})">Cancel</button>
                <button class="btn btn-primary" ${session.can_execute ? '' : 'disabled'} onclick="executeSession(${session.session_id})">
                    ${session.can_execute ? 'Execute payment' : 'Waiting for full coverage…'}
                </button>
            </div>
        </div>`;
}

function startSessionPolling(sessionId) {
    stopSessionPolling();
    activeSessionPollTimer = setInterval(async () => {
        const result = await callApiGet(CONFIG.API_BASE + '/api/v1/cards/ContributionStatus.php?session_id=' + sessionId);
        if (!result.ok) return;
        const session = result.body.data;
        const area = document.getElementById('sessionStatusArea');
        if (area) area.innerHTML = renderSessionStatus(session);
        if (['COMPLETED', 'CANCELLED', 'EXPIRED', 'FAILED'].includes(session.status)) {
            stopSessionPolling();
        }
    }, 3000);
}

function stopSessionPolling() {
    if (activeSessionPollTimer) { clearInterval(activeSessionPollTimer); activeSessionPollTimer = null; }
}

async function submitMyManualAmount(sessionId) {
    const amount = parseFloat(document.getElementById('myManualAmount').value);
    if (isNaN(amount) || amount < 0) { showMessage('Enter a valid amount.', 'warning'); return; }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/Contribute.php', { session_id: sessionId, amount });
    if (!result.ok) { showMessage('Could not set contribution: ' + result.error, 'error'); return; }
    const area = document.getElementById('sessionStatusArea');
    if (area) area.innerHTML = renderSessionStatus(result.body.data);
}

async function executeSession(sessionId) {
    if (!confirm('Execute this payment now?')) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/execute.php', { session_id: sessionId });
    if (!result.ok) { showMessage('Execution failed: ' + result.error, 'error'); return; }
    stopSessionPolling();
    showMessage('Payment executed successfully.', 'success');
    closeModal();
}

async function cancelSession(sessionId) {
    if (!confirm('Cancel this payment session?')) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/cards/cancel.php', { session_id: sessionId, reason: 'Cancelled by owner' });
    if (!result.ok) { showMessage('Could not cancel: ' + result.error, 'error'); return; }
    stopSessionPolling();
    showMessage('Session cancelled.', 'info');
    openMyCardModal();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>
