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

// Derive currency from country config or country name
$countryCurrency = $countryConfig['currency'] ?? ($countryConfig['currency_code'] ?? '');
if (!$countryCurrency) {
    // Reasonable defaults per country
    $currencyMap = [
        'Botswana' => 'BWP', 'Zimbabwe' => 'ZWL', 'SouthAfrica' => 'ZAR',
        'South Africa' => 'ZAR', 'Kenya' => 'KES', 'Ghana' => 'GHS',
        'Nigeria' => 'NGN', 'Tanzania' => 'TZS', 'Uganda' => 'UGX',
    ];
    $countryCurrency = $currencyMap[$userCountry] ?? 'USD';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VouchMorph – The Quiet Professional</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
<style>
:root {
    --bg: #FAF9F6;
    --surface: #FFFFFF;
    --surface-muted: #F3F1EF;
    --surface-hover: #EAE8E6;
    --border: rgba(0, 0, 0, 0.08);
    --border-strong: rgba(0, 0, 0, 0.16);
    --border-active: rgba(13, 35, 30, 0.35);
    --text: #0E1412;
    --text-muted: #666A68;
    --text-dim: #8E9290;
    --primary: #0B1E19;
    --primary-dark: #040D0B;
    --accent: #5A8A7A;
    --accent-soft: rgba(90, 138, 122, 0.1);
    --success: #2E7D52;
    --success-bg: #DCFCE7;
    --success-text: #166534;
    --warning: #B8860B;
    --warning-bg: #FEF3C7;
    --warning-text: #8A5A0B;
    --danger: #C62828;
    --danger-bg: #FEE2E2;
    --danger-text: #991B1B;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --radius-pill: 9999px;
    --font: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04);
    --shadow-md: 0 8px 30px rgba(0,0,0,0.06);
    --header-h: 72px;
    --max-w: 1120px;
}

* { margin: 0; padding: 0; box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
    min-height: 100vh;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    display: flex;
    flex-direction: column;
}

.material-symbols-outlined {
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}

/* ── Header ─────────────────────────────────────────── */
.site-header {
    position: sticky; top: 0; z-index: 100;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
}
.header-inner {
    max-width: var(--max-w); margin: 0 auto; padding: 0 24px;
    height: var(--header-h);
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
}
.brand { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.logo-title { font-size: 22px; font-weight: 800; color: var(--text); letter-spacing: -0.03em; }
.brand-divider { width: 1px; height: 18px; background: var(--border-strong); opacity: 0.6; }
.tagline-text { font-size: 13px; color: var(--text-muted); font-weight: 400; }

.header-nav {
    display: flex; align-items: center; gap: 4px;
    background: var(--surface-muted); padding: 4px;
    border-radius: var(--radius-pill); border: 1px solid var(--border);
}
.nav-pill-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 18px; border-radius: var(--radius-pill);
    font-size: 14px; font-weight: 500; color: var(--text-muted);
    border: none; background: transparent; cursor: pointer;
    transition: var(--transition); white-space: nowrap; text-decoration: none;
}
.nav-pill-btn:hover { color: var(--text); background: rgba(255,255,255,0.6); }
.nav-pill-btn.active { background: var(--primary); color: #FFFFFF; font-weight: 600; }

.header-right { display: flex; align-items: center; gap: 14px; }
.bell-btn {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--surface); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--text-muted); transition: var(--transition);
    position: relative;
}
.bell-btn:hover { background: var(--surface-muted); color: var(--text); }
.bell-badge {
    position: absolute; top: -4px; right: -4px;
    width: 16px; height: 16px; border-radius: 50%;
    background: var(--danger); color: #fff;
    font-size: 9px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--surface);
}

.user-profile-badge {
    display: flex; align-items: center; gap: 10px;
    padding: 4px 6px 4px 12px;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-pill); cursor: pointer; transition: var(--transition);
}
.user-profile-badge:hover { border-color: var(--border-strong); }
.user-info-text { display: flex; flex-direction: column; text-align: right; line-height: 1.2; }
.user-info-name { font-size: 13px; font-weight: 700; color: var(--text); }
.user-info-subtitle { font-size: 11px; color: var(--text-muted); }
.avatar-circle {
    width: 32px; height: 32px; border-radius: 50%;
    background: var(--primary); color: #FFFFFF;
    font-size: 12px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}

/* ── Page Containers ─────────────────────────────────── */
.main-wrapper {
    max-width: var(--max-w); width: 100%;
    margin: 0 auto; padding: 32px 24px 60px; flex: 1;
}

.view-section { display: none; }
.view-section.active { display: block;
    animation: fadeSlideIn 0.22s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes fadeSlideIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Back Link ───────────────────────────────────────── */
.back-link-btn {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 14px; font-weight: 500; color: var(--text-muted);
    text-decoration: none; cursor: pointer; margin-bottom: 24px;
    transition: var(--transition); background: none; border: none; padding: 0;
}
.back-link-btn:hover { color: var(--text); }

/* ── Page Titles ─────────────────────────────────────── */
.page-title { font-size: 32px; font-weight: 800; letter-spacing: -0.02em; color: var(--text); margin-bottom: 4px; }
.page-subtitle { font-size: 16px; color: var(--text-muted); margin-bottom: 32px; }

/* ── Hero (Image 2) ──────────────────────────────────── */
.hero-container {
    min-height: 55vh;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    text-align: center; padding: 20px 0;
}
.madlib-sentence {
    font-size: clamp(26px, 4vw, 42px);
    font-weight: 800; line-height: 1.4;
    letter-spacing: -0.02em; color: var(--text);
    max-width: 820px; margin: 0 auto 36px;
}
.madlib-slot {
    display: inline-flex; align-items: center;
    color: var(--accent); background: rgba(90,138,122,0.08);
    border-bottom: 2px solid var(--accent);
    padding: 2px 10px; border-radius: var(--radius-sm);
    cursor: pointer; transition: var(--transition);
    margin: 0 4px; text-decoration: none; white-space: nowrap;
}
.madlib-slot:hover { color: var(--primary); background: rgba(11,30,25,0.12); border-bottom-color: var(--primary); }
.madlib-slot.filled { color: var(--primary); background: rgba(11,30,25,0.07); border-bottom-color: var(--primary); font-weight: 700; }

/* Amount input slot */
.amount-slot {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--accent); background: rgba(90,138,122,0.08);
    border-bottom: 2px solid var(--accent);
    padding: 2px 10px; border-radius: var(--radius-sm);
    transition: var(--transition); margin: 0 4px;
}
.amount-slot:focus-within { color: var(--primary); background: rgba(11,30,25,0.12); border-bottom-color: var(--primary); }
.madlib-amount-input {
    width: 120px; border: none; background: transparent;
    font-size: inherit; font-weight: 800; color: inherit;
    text-align: right; padding: 0; outline: none; font-family: var(--font);
    min-width: 60px;
}
.madlib-amount-input::placeholder { color: rgba(90,138,122,0.5); }
.currency-badge {
    font-size: 0.55em; font-weight: 800; color: inherit;
    opacity: 0.75; letter-spacing: 0.03em;
}

/* Action buttons below hero */
.hero-actions {
    display: flex; gap: 12px; margin-top: 28px; flex-wrap: wrap; justify-content: center;
}

.total-balance-card {
    width: 100%; max-width: 440px;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 24px; text-align: center;
    box-shadow: var(--shadow-sm); cursor: pointer; transition: var(--transition);
}
.total-balance-card:hover { border-color: var(--border-strong); box-shadow: var(--shadow-md); transform: translateY(-2px); }
.balance-title { font-size: 13px; color: var(--text-muted); margin-bottom: 8px; font-weight: 500; }
.balance-value { font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: -0.02em; margin-bottom: 6px; }
.balance-trend { font-size: 13px; color: var(--success); font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
.balance-loading { color: var(--text-muted); font-size: 14px; }

/* ── Grid Layouts ────────────────────────────────────── */
.selection-grid-2col { display: grid; grid-template-columns: 1fr; gap: 24px; }
@media (min-width: 900px) { .selection-grid-2col { grid-template-columns: 1fr 1fr; } }

/* ── Cards ───────────────────────────────────────────── */
.option-card-panel {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); padding: 28px;
    box-shadow: var(--shadow-sm); margin-bottom: 24px;
}
.option-card-panel.selectable-card {
    cursor: pointer; transition: var(--transition);
}
.option-card-panel.selectable-card:hover { border-color: var(--accent); box-shadow: var(--shadow-md); }
.option-card-panel.selectable-card.selected-card { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary); }

.card-header-row { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px; }
.card-icon-box {
    width: 44px; height: 44px;
    background: var(--surface-muted); border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: var(--primary); flex-shrink: 0;
}
.card-icon-box.dark { background: var(--primary); color: #FFFFFF; }
.card-title-text h3 { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
.card-title-text p { font-size: 13px; color: var(--text-muted); }

.dashed-content-box {
    border: 1.5px dashed var(--border-strong); background: var(--surface-muted);
    border-radius: var(--radius-md); padding: 32px 20px; text-align: center;
}
.dashed-content-box p { font-size: 13px; color: var(--text-muted); margin-bottom: 16px; }

.saved-item-row {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 14px 16px;
    display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;
    cursor: pointer; transition: var(--transition);
}
.saved-item-row:hover { border-color: var(--primary); background: var(--surface-muted); }
.saved-item-row.selected { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(11,30,25,0.15); }
.saved-item-info { display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 15px; }
.saved-item-exp { font-size: 13px; color: var(--text-muted); }
.saved-item-balance { font-size: 14px; font-weight: 700; color: var(--text); }

.dashed-btn {
    width: 100%; border: 1.5px dashed var(--border-strong); background: transparent;
    border-radius: var(--radius-sm); padding: 12px;
    font-size: 14px; font-weight: 600; color: var(--text);
    cursor: pointer; transition: var(--transition);
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.dashed-btn:hover { background: var(--surface-muted); border-color: var(--text); }

/* ── Buttons ─────────────────────────────────────────── */
.btn-dark-pill {
    background: var(--primary); color: #FFFFFF;
    font-size: 14px; font-weight: 600;
    padding: 10px 24px; border-radius: var(--radius-pill);
    border: none; cursor: pointer; transition: var(--transition);
    display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none;
}
.btn-dark-pill:hover { background: var(--primary-dark); }

.btn-dark-full {
    width: 100%; background: var(--primary); color: #FFFFFF;
    font-size: 14px; font-weight: 600; padding: 14px;
    border-radius: var(--radius-sm); border: none; cursor: pointer; transition: var(--transition);
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-dark-full:hover { background: var(--primary-dark); }
.btn-dark-full:disabled { opacity: 0.4; cursor: not-allowed; }

.btn-accent-full {
    width: 100%; background: var(--accent); color: #FFFFFF;
    font-size: 14px; font-weight: 600; padding: 14px;
    border-radius: var(--radius-sm); border: none; cursor: pointer; transition: var(--transition);
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-accent-full:hover { background: #4a7a6a; }

/* ── Progress bar ────────────────────────────────────── */
.progress-bar-wrap { margin: 20px 0; }
.progress-label-row { display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 8px; }
.progress-track { width: 100%; height: 8px; background: var(--surface-muted); border-radius: 4px; overflow: hidden; }
.progress-fill { height: 100%; background: var(--primary); border-radius: 4px; transition: width 0.6s ease; }

/* ── Institutions Grid ───────────────────────────────── */
.institutions-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 16px; margin-top: 16px;
}
.institution-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); padding: 16px 12px; text-align: center;
    cursor: pointer; transition: var(--transition);
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;
}
.institution-card:hover { border-color: var(--primary); transform: translateY(-2px); box-shadow: var(--shadow-sm); }
.institution-card.selected { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(11,30,25,0.2); background: rgba(11,30,25,0.03); }
.institution-card-icon {
    width: 40px; height: 40px; border-radius: var(--radius-sm);
    background: var(--surface-muted); display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: var(--text);
}
.institution-card-name { font-size: 12px; font-weight: 600; color: var(--text); line-height: 1.2; }

/* ── Inputs ──────────────────────────────────────────── */
.large-id-input {
    width: 100%; padding: 16px;
    background: var(--surface-muted); border: 1px solid var(--border-strong);
    border-radius: var(--radius-sm);
    font-size: 18px; font-weight: 700; font-family: monospace;
    letter-spacing: 2px; color: var(--text); margin-bottom: 16px;
}
.large-id-input:focus { outline: none; border-color: var(--primary); background: var(--surface); }

.checkbox-label {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; color: var(--text-muted); cursor: pointer; margin-bottom: 20px;
}

.search-bar-input {
    padding: 8px 14px; background: var(--surface-muted);
    border: 1px solid var(--border); border-radius: var(--radius-pill);
    font-size: 13px; width: 220px; font-family: var(--font);
    color: var(--text); transition: var(--transition);
}
.search-bar-input:focus { outline: none; border-color: var(--primary); background: var(--surface); width: 260px; }

/* ── Skeleton loader ─────────────────────────────────── */
.skeleton {
    background: linear-gradient(90deg, var(--surface-muted) 25%, var(--surface-hover) 50%, var(--surface-muted) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 6px;
}
@keyframes shimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }

/* ── Transaction Ledger ──────────────────────────────── */
.ledger-header-toolbar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.ledger-actions { display: flex; gap: 10px; }
.btn-white-pill {
    background: var(--surface); border: 1px solid var(--border-strong);
    padding: 8px 16px; border-radius: var(--radius-pill);
    font-size: 13px; font-weight: 600; color: var(--text); cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px; transition: var(--transition);
}
.btn-white-pill:hover { background: var(--surface-muted); }

.ledger-table-container {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-md); overflow: hidden; margin-bottom: 24px;
}
.ledger-table { width: 100%; border-collapse: collapse; text-align: left; }
.ledger-table th {
    padding: 14px 20px; font-size: 11px; font-weight: 700;
    color: var(--text-dim); letter-spacing: 0.05em; text-transform: uppercase;
    background: var(--surface-muted); border-bottom: 1px solid var(--border);
}
.ledger-table td { padding: 16px 20px; font-size: 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.ledger-table tr:last-child td { border-bottom: none; }
.ledger-table tr:hover td { background: rgba(0,0,0,0.015); }

.destination-cell { display: flex; align-items: center; gap: 12px; }
.destination-icon-box {
    width: 36px; height: 36px; border-radius: var(--radius-sm);
    background: var(--surface-muted); display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: var(--primary); flex-shrink: 0;
}
.destination-icon-box.green { background: rgba(46,125,82,0.12); color: var(--success); }
.destination-title { font-weight: 700; font-size: 14px; color: var(--text); }
.destination-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

.status-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: var(--radius-pill);
    font-size: 12px; font-weight: 600; text-transform: capitalize;
}
.status-pill.pending { background: #E5E7EB; color: #4B5563; }
.status-pill.completed { background: var(--success-bg); color: var(--success-text); }
.status-pill.failed { background: var(--danger-bg); color: var(--danger-text); }

.ledger-table-footer {
    padding: 14px 20px; background: var(--surface-muted);
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px; color: var(--text-muted); font-weight: 500;
    border-top: 1px solid var(--border);
}
.pagination-controls { display: flex; gap: 8px; }
.page-arrow-btn {
    width: 28px; height: 28px; border-radius: 4px;
    border: 1px solid var(--border); background: var(--surface);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--text-muted); font-size: 13px;
}
.page-arrow-btn:hover { background: var(--surface-hover); color: var(--text); }
.page-arrow-btn:disabled { opacity: 0.3; cursor: not-allowed; }

/* ── Summary Cards ───────────────────────────────────── */
.ledger-summary-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
@media (min-width: 768px) { .ledger-summary-grid { grid-template-columns: 1fr 1fr; } }
.summary-card { border-radius: var(--radius-md); padding: 24px; }
.summary-card.dark { background: var(--primary); color: #FFFFFF; }
.summary-card.light { background: var(--surface-muted); border: 1px solid var(--border); color: var(--text); }
.summary-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-weight: 700; font-size: 15px; }
.summary-card-value { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
.summary-card-desc { font-size: 13px; line-height: 1.5; opacity: 0.85; }

/* ── Modals ──────────────────────────────────────────── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.4); backdrop-filter: blur(4px);
    z-index: 1000; align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal-card {
    background: var(--surface); border-radius: var(--radius-lg);
    max-width: 520px; width: 100%; max-height: 90vh; overflow-y: auto;
    border: 1px solid var(--border); box-shadow: var(--shadow-md);
    animation: modalIn 0.2s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes modalIn { from { opacity:0; transform:scale(0.96) translateY(8px); } to { opacity:1; transform:none; } }
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px; border-bottom: 1px solid var(--border);
}
.modal-header h2 { font-size: 18px; font-weight: 800; color: var(--text); }
.modal-close-btn { background: none; border: none; font-size: 24px; color: var(--text-muted); cursor: pointer; line-height: 1; }
.modal-body { padding: 24px; }

/* ── Form Elements ───────────────────────────────────── */
.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; }
.form-group input, .form-group select {
    width: 100%; padding: 12px 14px;
    background: var(--surface-muted); border: 1px solid var(--border-strong);
    border-radius: var(--radius-sm); color: var(--text); font-size: 14px; font-family: var(--font);
}
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); background: var(--surface); }

.segmented-toggle { display: flex; background: var(--surface-muted); padding: 4px; border-radius: var(--radius-sm); gap: 4px; }
.segmented-btn { flex: 1; padding: 8px; text-align: center; font-size: 13px; font-weight: 600; border: none; background: transparent; color: var(--text-muted); border-radius: 6px; cursor: pointer; transition: var(--transition); }
.segmented-btn.active { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }

/* ── Dynamic fields ──────────────────────────────────── */
.dynamic-field-group { margin-bottom: 14px; }
.dynamic-field-group label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.04em; }
.dynamic-field-group input, .dynamic-field-group select {
    width: 100%; padding: 11px 13px;
    background: var(--surface-muted); border: 1px solid var(--border-strong);
    border-radius: var(--radius-sm); color: var(--text); font-size: 14px; font-family: var(--font);
}
.dynamic-field-group input:focus, .dynamic-field-group select:focus { outline: none; border-color: var(--primary); background: var(--surface); }

/* ── Review / Preview panel ──────────────────────────── */
.review-panel {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); padding: 24px; margin-top: 32px;
    box-shadow: var(--shadow-sm);
}
.review-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 14px;
}
.review-row:last-child { border-bottom: none; }
.review-label { color: var(--text-muted); font-weight: 500; }
.review-value { font-weight: 700; color: var(--text); text-align: right; }

/* ── Footer ──────────────────────────────────────────── */
.site-footer { border-top: 1px solid var(--border); padding: 28px 0; margin-top: auto; }
.footer-inner {
    max-width: var(--max-w); margin: 0 auto; padding: 0 24px;
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 16px; font-size: 13px; color: var(--text-muted);
}
.footer-links { display: flex; gap: 20px; font-weight: 500; }
.footer-links span { cursor: pointer; transition: var(--transition); }
.footer-links span:hover { color: var(--text); }

/* ── System message bar ──────────────────────────────── */
.system-msg {
    max-width: var(--max-w); margin: 16px auto 0; padding: 12px 16px;
    border-radius: var(--radius-sm); font-size: 13px; font-weight: 500; display: none;
}
.system-msg.show { display: block; }
.system-msg.info { background: var(--accent-soft); color: var(--primary); border-left: 3px solid var(--accent); }
.system-msg.success { background: var(--success-bg); color: var(--success-text); border-left: 3px solid var(--success); }
.system-msg.error { background: var(--danger-bg); color: var(--danger-text); border-left: 3px solid var(--danger); }
.system-msg.warning { background: var(--warning-bg); color: var(--warning-text); border-left: 3px solid var(--warning); }

/* ── Empty state ─────────────────────────────────────── */
.empty-state { text-align: center; padding: 48px 20px; color: var(--text-muted); }
.empty-state-icon { font-size: 40px; margin-bottom: 12px; }
.empty-state h4 { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.empty-state p { font-size: 13px; }

/* ── Combine funds source checkbox ───────────────────── */
.combine-check-row {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 14px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: var(--surface);
    margin-bottom: 10px; cursor: pointer; transition: var(--transition);
}
.combine-check-row:hover { border-color: var(--primary); }
.combine-check-row.checked { border-color: var(--primary); background: rgba(11,30,25,0.03); }
.combine-check-row input[type="checkbox"] { accent-color: var(--primary); width: 16px; height: 16px; }
.combine-check-label { flex: 1; font-size: 14px; font-weight: 600; }
.combine-check-balance { font-size: 13px; font-weight: 700; color: var(--text-muted); }
</style>
</head>
<body>

<!-- ═══════════════════ HEADER ═══════════════════ -->
<header class="site-header">
    <div class="header-inner">
        <div class="brand">
            <span class="logo-title">VouchMorph</span>
            <div class="brand-divider"></div>
            <span class="tagline-text">The Quiet Professional</span>
        </div>

        <nav class="header-nav">
            <button class="nav-pill-btn active" id="nav-move-money" onclick="showView('hero')">
                <span>⇄</span> Move Money
            </button>
            <button class="nav-pill-btn" id="nav-activity" onclick="showView('activity')">
                <span>🕒</span> Activity
            </button>
            <button class="nav-pill-btn" id="nav-wallets" onclick="openMyWalletsMenu()">
                <span>👛</span> My Wallets
            </button>
        </nav>

        <div class="header-right">
            <button class="bell-btn" onclick="openToolbox()" title="Toolbox & Notifications">
                <span class="material-symbols-outlined" style="font-size:20px;">notifications</span>
                <span class="bell-badge" id="notifBadge" style="display:none;">0</span>
            </button>
            
            <div class="user-profile-badge" onclick="openProfileModal()">
                <div class="user-info-text">
                    <span class="user-info-name"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="user-info-subtitle"><?php echo htmlspecialchars(ucfirst($userRole)); ?> · <?php echo htmlspecialchars($userCountry); ?></span>
                </div>
                <div class="avatar-circle"><?php echo htmlspecialchars($userInitials); ?></div>
            </div>
            <a href="logout.php" style="font-size:12px;color:var(--text-muted);text-decoration:none;margin-left:4px;">Logout</a>
        </div>
    </div>
</header>

<div class="system-msg" id="mainMessage"></div>

<!-- ═══════════════════ MAIN CONTENT ═══════════════════ -->
<main class="main-wrapper">

    <!-- ── 1. HERO / MOVE MONEY VIEW ── -->
    <section class="view-section active" id="view-hero">
        <div class="hero-container">
            <h1 class="madlib-sentence">
                I want to send&nbsp;
                <span class="amount-slot" id="amountSlot">
                    <input
                        type="number"
                        id="fromAmount"
                        class="madlib-amount-input"
                        placeholder="0.00"
                        step="0.01"
                        min="0.01"
                        autocomplete="off"
                        aria-label="Transfer amount"
                        oninput="onAmountInput(this)"
                    >
                    <span class="currency-badge" id="fromCurrencyLabel"><?php echo htmlspecialchars($countryCurrency); ?></span>
                </span>
                &nbsp;from my<br>
                <a class="madlib-slot" id="sourceLink" onclick="showView('source');return false;" href="#">
                    [Select Source]
                </a>
                &nbsp;to<br>
                <a class="madlib-slot" id="destinationLink" onclick="showView('destination');return false;" href="#">
                    [Select Destination]
                </a>&thinsp;.
            </h1>

            <!-- Total Balance Card -->
            <div class="total-balance-card" onclick="viewWalletBalance()" id="heroBalanceCard">
                <div class="balance-title">Total Balance across all sources</div>
                <div class="balance-value" id="heroTotalBalanceDisplay">
                    <span class="skeleton" style="display:inline-block;width:140px;height:36px;"></span>
                </div>
                <div class="balance-trend" id="heroBalanceTrend" style="display:none;">
                    📈 Loading…
                </div>
            </div>

            <!-- Proceed Button (visible when source + destination selected) -->
            <div class="hero-actions" id="heroActionsRow" style="display:none;">
                <button class="btn-dark-pill" id="reviewSwapBtn" onclick="openReviewModal()" style="padding:14px 36px;font-size:16px;">
                    Review Transfer →
                </button>
            </div>
        </div>
    </section>

    <!-- ── 2. SELECT A SOURCE VIEW ── -->
    <section class="view-section" id="view-source">
        <button class="back-link-btn" onclick="showView('hero')">
            <span class="material-symbols-outlined" style="font-size:18px;">arrow_back</span>
            Back to Transfer
        </button>

        <h1 class="page-title">Select a Source</h1>
        <p class="page-subtitle">Where are we moving money from?</p>

        <div class="selection-grid-2col">
            <!-- Left Column: Individual Sources -->
            <div>
                <!-- Card 1: Wallet / Account -->
                <div class="option-card-panel">
                    <div class="card-header-row">
                        <div class="card-icon-box">🏦</div>
                        <div class="card-title-text">
                            <h3>Wallet / Account</h3>
                            <p>Access funds directly from your linked accounts.</p>
                        </div>
                    </div>
                    <div id="savedSourcesContainer">
                        <!-- Populated by JS -->
                        <div class="dashed-content-box" id="noSourcesBox" style="display:none;">
                            <p id="noSourcesPromptText">No bank account linked yet.</p>
                            <button class="btn-dark-pill" onclick="openAddSource()">Link an Account</button>
                        </div>
                        <div id="savedSourcesList"></div>
                        <div id="sourcesLoadingShimmer" style="padding:12px 0;">
                            <div class="skeleton" style="height:52px;border-radius:8px;margin-bottom:10px;"></div>
                            <div class="skeleton" style="height:52px;border-radius:8px;width:80%;"></div>
                        </div>
                    </div>
                    <button class="dashed-btn" style="margin-top:12px;" onclick="openAddSource()">
                        <span>+</span> Link an Account
                    </button>
                </div>

                <!-- Card 2: Card -->
                <div class="option-card-panel">
                    <div class="card-header-row">
                        <div class="card-icon-box">💳</div>
                        <div class="card-title-text">
                            <h3>Card</h3>
                            <p>Use a saved credit or debit card.</p>
                        </div>
                    </div>
                    <div id="savedCardsList">
                        <!-- Populated by JS -->
                        <div class="dashed-content-box" id="noCardsBox">
                            <p>No cards saved yet.</p>
                        </div>
                    </div>
                    <button class="dashed-btn" style="margin-top:12px;" onclick="openAddCard()">
                        <span>+</span> Add a New Card
                    </button>
                </div>
            </div>

            <!-- Right Column: Combine Funds -->
            <div>
                <div class="option-card-panel">
                    <div class="card-header-row">
                        <div class="card-icon-box dark">↗</div>
                        <div class="card-title-text">
                            <h3>Combine Funds</h3>
                            <p>Merge multiple sources into a single transfer.</p>
                        </div>
                    </div>

                    <div id="combineSourcesList" style="margin-bottom:20px;">
                        <div class="skeleton" style="height:48px;border-radius:8px;margin-bottom:10px;"></div>
                        <div class="skeleton" style="height:48px;border-radius:8px;width:85%;"></div>
                    </div>

                    <div class="progress-bar-wrap" id="combineProgressWrap" style="display:none;">
                        <div class="progress-label-row">
                            <span style="color:var(--text-muted);">Combined towards target</span>
                            <span style="font-weight:700;" id="combineProgressPct">0%</span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" id="combineProgressFill" style="width:0%;"></div>
                        </div>
                        <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">
                            Selected: <strong id="combineSelectedTotal">0.00</strong>
                            <span id="combineCurrencyLabel"> <?php echo htmlspecialchars($countryCurrency); ?></span>
                        </div>
                    </div>

                    <button class="btn-dark-full" id="combineFundsBtn" onclick="selectCombineFunds()" disabled>
                        Combine Funds →
                    </button>
                </div>

                <!-- Dynamic From Fields (rendered when source asset selected) -->
                <div class="option-card-panel" id="fromFieldsPanel" style="display:none;">
                    <div class="card-header-row">
                        <div class="card-icon-box">📋</div>
                        <div class="card-title-text">
                            <h3>Source Details</h3>
                            <p>Additional info required for this source.</p>
                        </div>
                    </div>
                    <div id="fromFieldsContainer"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── 3. SELECT A DESTINATION VIEW ── -->
    <section class="view-section" id="view-destination">
        <button class="back-link-btn" onclick="showView('hero')">
            <span class="material-symbols-outlined" style="font-size:18px;">arrow_back</span>
            Back to Transfer
        </button>

        <h1 class="page-title">Select a Destination</h1>
        <p class="page-subtitle">Where should this money go?</p>

        <div style="max-width:780px;">
            <!-- Card 1: Deposit to Bank/Institution -->
            <div class="option-card-panel">
                <div class="card-header-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                    <div style="display:flex;align-items:center;gap:14px;">
                        <div class="card-icon-box dark">🏛️</div>
                        <div class="card-title-text">
                            <h3>Deposit to Bank / Institution</h3>
                        </div>
                    </div>
                    <input
                        type="text"
                        id="institutionSearchInput"
                        class="search-bar-input"
                        placeholder="🔍 Search institutions…"
                        oninput="filterInstitutions(this.value)"
                    >
                </div>

                <div class="institutions-grid" id="institutionsGridContainer">
                    <div class="skeleton" style="height:88px;border-radius:12px;"></div>
                    <div class="skeleton" style="height:88px;border-radius:12px;"></div>
                    <div class="skeleton" style="height:88px;border-radius:12px;"></div>
                    <div class="skeleton" style="height:88px;border-radius:12px;"></div>
                </div>

                <!-- To Fields (rendered when institution selected) -->
                <div id="toFieldsPanel" style="display:none;margin-top:20px;padding-top:20px;border-top:1px solid var(--border);">
                    <h4 style="font-size:14px;font-weight:700;margin-bottom:14px;color:var(--text);">Account Details</h4>
                    <div id="toFieldsContainer"></div>
                    <button class="btn-dark-full" onclick="confirmInstitutionDest()">Confirm Destination →</button>
                </div>
            </div>

            <!-- Card 2: Send to National ID -->
            <div class="option-card-panel">
                <div class="card-header-row">
                    <div class="card-icon-box dark">🪪</div>
                    <div class="card-title-text">
                        <h3>Send to ID Card</h3>
                        <p>Send directly to a recipient's national identity number.</p>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.04em;">Recipient National ID Number</label>
                    <input
                        type="text"
                        id="destinationIdentityInput"
                        class="large-id-input"
                        placeholder="0000 - 0000 - 0000"
                        oninput="state.toIdentityValue = this.value.trim(); refreshHeroDestLabel();"
                    >
                    
                    <label class="checkbox-label">
                        <input type="checkbox" id="sendSmsNotificationCheck" onchange="state.toIdentitySms = this.checked ? '1' : '';">
                        Send SMS notification to recipient
                    </label>

                    <button class="btn-dark-full" id="confirmIdTransferBtn" onclick="confirmIdTransfer()">
                        Confirm ID Transfer →
                    </button>
                </div>
            </div>

            <!-- Saved Recipients -->
            <div class="option-card-panel" id="savedRecipientsPanel">
                <div class="card-header-row">
                    <div class="card-icon-box">👥</div>
                    <div class="card-title-text">
                        <h3>Saved Recipients</h3>
                        <p>Select a previously saved recipient.</p>
                    </div>
                </div>
                <div id="savedRecipientsList">
                    <div class="empty-state">
                        <div class="empty-state-icon">👤</div>
                        <h4>No saved recipients</h4>
                        <p>Save a recipient below to speed up future transfers.</p>
                    </div>
                </div>
                <button class="dashed-btn" style="margin-top:12px;" onclick="openAddSource()">
                    <span>+</span> Add New Recipient
                </button>
            </div>
        </div>
    </section>

    <!-- ── 4. TRANSACTION LEDGER VIEW ── -->
    <section class="view-section" id="view-activity">
        <div class="ledger-header-toolbar">
            <div>
                <h1 class="page-title" style="margin-bottom:2px;">Transaction Ledger</h1>
                <p style="font-size:14px;color:var(--text-muted);">
                    Review your historical and pending fund movements — <?php echo htmlspecialchars($userName); ?>
                </p>
            </div>
            <div class="ledger-actions">
                <button class="btn-white-pill" onclick="filterLedger()">
                    <span class="material-symbols-outlined" style="font-size:16px;">filter_list</span> Filter
                </button>
                <button class="btn-white-pill" onclick="exportLedger()">
                    <span class="material-symbols-outlined" style="font-size:16px;">download</span> Export
                </button>
            </div>
        </div>

        <div class="ledger-table-container">
            <table class="ledger-table">
                <thead>
                    <tr>
                        <th>DATE</th>
                        <th>DESTINATION</th>
                        <th style="text-align:right;">AMOUNT</th>
                        <th style="text-align:right;">STATUS</th>
                    </tr>
                </thead>
                <tbody id="ledgerTableBody">
                    <tr>
                        <td colspan="4">
                            <div class="skeleton" style="height:48px;border-radius:6px;margin:8px 16px;"></div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="4">
                            <div class="skeleton" style="height:48px;border-radius:6px;margin:8px 16px;width:80%;"></div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="4">
                            <div class="skeleton" style="height:48px;border-radius:6px;margin:8px 16px;width:65%;"></div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="ledger-table-footer">
                <span id="ledgerCountSpan">Loading transactions…</span>
                <div class="pagination-controls">
                    <button class="page-arrow-btn" id="ledgerPrevBtn" onclick="ledgerPagePrev()" disabled title="Previous">&lt;</button>
                    <span id="ledgerPageLabel" style="font-size:12px;font-weight:600;align-self:center;padding:0 4px;">1</span>
                    <button class="page-arrow-btn" id="ledgerNextBtn" onclick="ledgerPageNext()" title="Next">&gt;</button>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="ledger-summary-grid">
            <div class="summary-card dark">
                <div class="summary-card-header">
                    <span>📈</span> Monthly Volume
                </div>
                <div class="summary-card-value" id="monthlyVolumeValue">—</div>
                <div class="summary-card-desc" id="monthlyVolumeDesc">
                    Loading your transfer volume data…
                </div>
            </div>
            <div class="summary-card light">
                <div class="summary-card-header">
                    <span>🛡️</span> Security Audit
                </div>
                <div class="summary-card-desc">
                    All transactions are cryptographically verified and signed by your primary identity key.
                    Account: <strong><?php echo htmlspecialchars($userName); ?></strong> · ID <?php echo htmlspecialchars($userId); ?>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Hidden backend form fields (preserved for API compatibility) ── -->
    <div id="fullFormCard" style="display:none;" aria-hidden="true">
        <select id="toInstSelect" onchange="selectToInst(this.value)">
            <option value="">Select institution</option>
        </select>
        <select id="toAssetSelect" onchange="selectToAsset(this.value)"></select>
        <select id="swapTypeSelect" onchange="setSwapType(this.value)">
            <option value="DEPOSIT">Deposit</option>
            <option value="CASHOUT">Cashout</option>
            <option value="IDENTITY">Identity</option>
            <option value="MULTI_SOURCE">Multi-Source</option>
        </select>
        <select id="identityType" onchange="state.toIdentityType = this.value;">
            <option value="national_id">National ID</option>
            <option value="phone">Phone</option>
            <option value="email">Email</option>
        </select>
        <input id="identityValue">
        <input id="identitySms">
        <input id="beneficiaryPhone">
        <div id="fromFields"></div>
        <div id="toFields"></div>
        <button id="reviewBtn" onclick="previewSwap()">Review</button>
        <div id="swapReadinessHint"></div>
    </div>

</main>

<!-- ═══════════════════ FOOTER ═══════════════════ -->
<footer class="site-footer">
    <div class="footer-inner">
        <div>© <?php echo date('Y'); ?> VouchMorph Financial. All rights reserved.</div>
        <div class="footer-links">
            <span onclick="openHelpModal()">Help Centre</span>
            <span onclick="openTermsModal()">Privacy Policy</span>
            <span onclick="openTermsModal()">Terms & Conditions</span>
        </div>
    </div>
</footer>

<!-- ═══════════════════ MODAL ═══════════════════ -->
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal-card" id="modalContent">
        <div class="modal-header">
            <h2 id="modalTitle">Modal Title</h2>
            <button class="modal-close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<!-- ═══════════════════ JAVASCRIPT ═══════════════════ -->
<script>
// ─── CONFIG (PHP-injected) ─────────────────────────────────────────────────
const CONFIG = {
    API_KEY:          '<?php echo htmlspecialchars($apiKey); ?>',
    COUNTRY_CODE:     '<?php echo htmlspecialchars($userCountry); ?>',
    API_BASE:         '<?php echo htmlspecialchars($apiBase); ?>',
    IS_TEST_MODE:     <?php echo $isTestMode ? 'true' : 'false'; ?>,
    PREVIEW_ENDPOINT: '<?php echo $apiBase; ?>/api/v1/swap/preview.php',
    EXECUTE_ENDPOINT: '<?php echo $apiBase; ?>/api/v1/swap/execute.php',
    USER_ID:          <?php echo json_encode($userId); ?>,
    USER_NAME:        <?php echo json_encode($userName); ?>,
    CURRENCY:         '<?php echo htmlspecialchars($countryCurrency); ?>',
};
const PARTICIPANTS = <?php echo json_encode($participants); ?>;
const ASSETS       = <?php echo json_encode($assetTypes); ?>;
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

// ─── STATE ─────────────────────────────────────────────────────────────────
let state = {
    fromCategory: 'WALLET', fromInst: null, fromAsset: null,
    fromFields: {}, fromAmount: 0,
    swapType: 'DEPOSIT', toInst: null, toAsset: null, toFields: {},
    deliveryMethod: 'ATM', beneficiaryPhone: '',
    toIdentityType: 'national_id', toIdentityValue: '', toIdentitySms: '',
    multiSources: [], lastPreview: null, swapPayload: null,
    multiDestMode: 'institution', tabTotalAmount: 0,
    tabAllocationMode: 'even', contributionStrategy: 'SMART',
    selectedSourceType: null, selectedDestinationType: null
};
let ledgerState  = { allSwaps: [], filters: { status: 'all', query: '' }, page: 1, perPage: 10 };
let savedIdentities = [];
let userSources  = [];
let pendingClaims = [];
let pendingSources = [];
let agentStatus  = { is_agent: false, approved_destinations: [], all_destinations: [] };
let SessionUser  = null;
let userBalances = [];

// ─── UTILITY ───────────────────────────────────────────────────────────────
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

function formatMoney(amount, currency) {
    const num = parseFloat(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const cur = currency ? String(currency).toUpperCase().slice(0, 3) : CONFIG.CURRENCY;
    return `${num} ${cur}`;
}

function showMessage(text, type = 'info') {
    const el = document.getElementById('mainMessage');
    el.textContent = text;
    el.className = `system-msg show ${type}`;
    clearTimeout(showMessage._t);
    showMessage._t = setTimeout(() => el.classList.remove('show'), 5000);
}

async function callApi(endpoint, payload) {
    try {
        const res = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const body = await res.json();
        return { ok: res.ok, body };
    } catch (e) {
        return { ok: false, error: e.message };
    }
}

// ─── ASSET CONFIG ──────────────────────────────────────────────────────────
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
    for (const key of Object.keys(ASSETS)) {
        if (key.includes(normalized) || normalized.includes(key)) return ASSETS[key];
    }
    return null;
}

// ─── VIEW ROUTING ──────────────────────────────────────────────────────────
function showView(viewName) {
    document.querySelectorAll('.view-section').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-pill-btn').forEach(el => el.classList.remove('active'));

    if (viewName === 'hero') {
        document.getElementById('view-hero').classList.add('active');
        document.getElementById('nav-move-money').classList.add('active');
    } else if (viewName === 'source') {
        document.getElementById('view-source').classList.add('active');
        document.getElementById('nav-move-money').classList.add('active');
        renderSourceView();
    } else if (viewName === 'destination') {
        document.getElementById('view-destination').classList.add('active');
        document.getElementById('nav-move-money').classList.add('active');
        renderDestinationView();
    } else if (viewName === 'activity') {
        document.getElementById('view-activity').classList.add('active');
        document.getElementById('nav-activity').classList.add('active');
        openSwapHistory();
    }
}

// ─── AMOUNT INPUT ──────────────────────────────────────────────────────────
function onAmountInput(input) {
    const raw = parseFloat(input.value);
    state.fromAmount = isNaN(raw) ? 0 : raw;
    // Auto-resize input width based on value length
    const len = input.value.length || 4;
    input.style.width = Math.max(80, len * 18) + 'px';
    refreshHeroProceedBtn();
}

function refreshHeroProceedBtn() {
    const row = document.getElementById('heroActionsRow');
    if (!row) return;
    const ready = state.fromAmount > 0 && state.fromInst && state.toInst;
    row.style.display = ready ? 'flex' : 'none';
}

// ─── SOURCE: LABEL UPDATE ──────────────────────────────────────────────────
function refreshHeroSourceLabel() {
    const el = document.getElementById('sourceLink');
    if (!el) return;
    if (state.fromInst) {
        const name = getParticipantName(state.fromInst);
        const assetLabel = state.fromAsset ? ` · ${state.fromAsset}` : '';
        el.textContent = `${name}${assetLabel}`;
        el.classList.add('filled');
    } else {
        el.textContent = '[Select Source]';
        el.classList.remove('filled');
    }
    refreshHeroProceedBtn();
}

function refreshHeroDestLabel() {
    const el = document.getElementById('destinationLink');
    if (!el) return;
    if (state.swapType === 'IDENTITY' && state.toIdentityValue) {
        el.textContent = `ID: ${state.toIdentityValue}`;
        el.classList.add('filled');
    } else if (state.toInst) {
        const name = getParticipantName(state.toInst);
        el.textContent = name;
        el.classList.add('filled');
    } else {
        el.textContent = '[Select Destination]';
        el.classList.remove('filled');
    }
    refreshHeroProceedBtn();
}

function getParticipantName(code) {
    if (!code) return code;
    const p = PARTICIPANTS[code];
    return p ? (p.name || p.label || code) : code;
}

// ─── USER SOURCES (BALANCES) ───────────────────────────────────────────────
async function loadUserSources() {
    if (!CONFIG.USER_ID) return;
    const result = await callApi(CONFIG.API_BASE + '/user/sources.php', { user_id: CONFIG.USER_ID });
    if (result.ok) {
        userSources = result.body.sources || result.body.data || [];
    }
    renderSourceView();
    loadUserBalance();
}

async function loadUserBalance() {
    if (!CONFIG.USER_ID) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/user/balance.php', { user_id: CONFIG.USER_ID });
    if (result.ok) {
        const bal = result.body.total_balance ?? result.body.balance ?? result.body.data?.total_balance ?? null;
        const currency = result.body.currency || CONFIG.CURRENCY;
        const pct = result.body.change_pct ?? result.body.month_change ?? null;

        const display = document.getElementById('heroTotalBalanceDisplay');
        if (display) {
            if (bal !== null) {
                display.textContent = formatMoney(bal, currency);
                userBalances = result.body.sources || result.body.accounts || [];
            } else {
                display.textContent = '—';
            }
        }
        const trend = document.getElementById('heroBalanceTrend');
        if (trend) {
            if (pct !== null) {
                const sign = pct >= 0 ? '+' : '';
                trend.textContent = `📈 ${sign}${parseFloat(pct).toFixed(1)}% from last month`;
                trend.style.display = 'inline-flex';
                trend.style.color = pct >= 0 ? 'var(--success)' : 'var(--danger)';
            } else {
                trend.style.display = 'none';
            }
        }
    } else {
        const display = document.getElementById('heroTotalBalanceDisplay');
        if (display) display.textContent = '—';
    }
}

// ─── RENDER SOURCE VIEW ────────────────────────────────────────────────────
function renderSourceView() {
    renderSavedSourcesList();
    renderCombineList();
}

function renderSavedSourcesList() {
    const list = document.getElementById('savedSourcesList');
    const noBox = document.getElementById('noSourcesBox');
    const shimmer = document.getElementById('sourcesLoadingShimmer');
    if (!list) return;

    if (shimmer) shimmer.style.display = 'none';

    if (!userSources || userSources.length === 0) {
        if (noBox) noBox.style.display = 'block';
        list.innerHTML = '';
        return;
    }
    if (noBox) noBox.style.display = 'none';

    list.innerHTML = userSources.map((src, i) => {
        const instCode = src.institution || src.participant || src.inst_code || '';
        const instName = getParticipantName(instCode) || instCode;
        const assetType = src.asset_type || src.type || 'ACCOUNT';
        const icon = (getAssetConfig(assetType)?.icon) || '🏦';
        const identifier = src.identifier || src.account_number || src.msisdn || '';
        const masked = identifier.length > 4 ? '••••' + identifier.slice(-4) : identifier;
        const balStr = src.balance != null ? formatMoney(src.balance, src.currency || CONFIG.CURRENCY) : '';
        const isSelected = state.fromInst === instCode && state.fromAsset === assetType;

        return `<div class="saved-item-row${isSelected ? ' selected' : ''}"
                     onclick="selectSource('${escapeHtml(instCode)}', '${escapeHtml(assetType)}', ${i})"
                     data-source-idx="${i}">
            <div class="saved-item-info">
                <span style="font-size:18px;">${escapeHtml(icon)}</span>
                <div>
                    <div>${escapeHtml(instName)}</div>
                    <div style="font-size:12px;color:var(--text-muted);font-weight:400;">${escapeHtml(masked)} · ${escapeHtml(assetType)}</div>
                </div>
            </div>
            ${balStr ? `<span class="saved-item-balance">${escapeHtml(balStr)}</span>` : ''}
        </div>`;
    }).join('');
}

function renderCombineList() {
    const container = document.getElementById('combineSourcesList');
    if (!container) return;

    if (!userSources || userSources.length === 0) {
        container.innerHTML = `<div class="empty-state" style="padding:24px 0;">
            <div class="empty-state-icon">🔗</div>
            <h4>No linked accounts</h4>
            <p>Link accounts to combine funds.</p>
        </div>`;
        return;
    }

    container.innerHTML = userSources.map((src, i) => {
        const instCode = src.institution || src.participant || src.inst_code || '';
        const instName = getParticipantName(instCode) || instCode;
        const assetType = src.asset_type || src.type || 'ACCOUNT';
        const bal = parseFloat(src.balance || 0);
        const balStr = formatMoney(bal, src.currency || CONFIG.CURRENCY);
        const isChecked = state.multiSources.some(s => s.idx === i);

        return `<label class="combine-check-row${isChecked ? ' checked' : ''}" id="combineRow${i}">
            <input type="checkbox" ${isChecked ? 'checked' : ''} onchange="toggleCombineSource(${i}, ${bal}, this.checked)">
            <span class="combine-check-label">${escapeHtml(instName)} · ${escapeHtml(assetType)}</span>
            <span class="combine-check-balance">${escapeHtml(balStr)}</span>
        </label>`;
    }).join('');

    updateCombineProgress();
    document.getElementById('combineProgressWrap').style.display = 'block';
}

function toggleCombineSource(idx, bal, checked) {
    const row = document.getElementById(`combineRow${idx}`);
    if (checked) {
        if (!state.multiSources.some(s => s.idx === idx)) {
            state.multiSources.push({ idx, balance: bal });
        }
        if (row) row.classList.add('checked');
    } else {
        state.multiSources = state.multiSources.filter(s => s.idx !== idx);
        if (row) row.classList.remove('checked');
    }
    updateCombineProgress();
}

function updateCombineProgress() {
    const total = state.multiSources.reduce((sum, s) => sum + (s.balance || 0), 0);
    const allBal = userSources.reduce((sum, s) => sum + parseFloat(s.balance || 0), 0);
    const pct = allBal > 0 ? Math.round((total / allBal) * 100) : 0;

    const pctEl = document.getElementById('combineProgressPct');
    const fillEl = document.getElementById('combineProgressFill');
    const totalEl = document.getElementById('combineSelectedTotal');
    const btn = document.getElementById('combineFundsBtn');

    if (pctEl) pctEl.textContent = `${pct}%`;
    if (fillEl) fillEl.style.width = `${pct}%`;
    if (totalEl) totalEl.textContent = formatMoney(total, CONFIG.CURRENCY);
    if (btn) btn.disabled = state.multiSources.length < 2;
}

function selectSource(instCode, assetType, idx) {
    state.fromInst = instCode;
    state.fromAsset = assetType;
    state.swapType = 'DEPOSIT';

    // Sync hidden select
    const sel = document.getElementById('toInstSelect');
    if (sel) sel.value = instCode;

    // Render dynamic fields if needed
    renderFromFields(assetType);
    refreshHeroSourceLabel();
    renderSavedSourcesList(); // refresh selection state

    showMessage(`Source selected: ${getParticipantName(instCode)} · ${assetType}`, 'success');
    setTimeout(() => showView('hero'), 400);
}

function renderFromFields(assetType) {
    const cfg = getAssetConfig(assetType);
    const panel = document.getElementById('fromFieldsPanel');
    const container = document.getElementById('fromFieldsContainer');
    if (!cfg || !cfg.fields || !Array.isArray(cfg.fields) || cfg.fields.length === 0) {
        if (panel) panel.style.display = 'none';
        return;
    }
    if (panel) panel.style.display = 'block';
    if (container) {
        container.innerHTML = renderDynamicFields(cfg.fields, 'from', state.fromFields);
    }
}

function selectCombineFunds() {
    if (state.multiSources.length < 2) {
        showMessage('Select at least 2 sources to combine.', 'warning');
        return;
    }
    state.swapType = 'MULTI_SOURCE';
    const names = state.multiSources.map(s => {
        const src = userSources[s.idx];
        return src ? getParticipantName(src.institution || '') : `Source ${s.idx+1}`;
    }).join(', ');

    const sourceLink = document.getElementById('sourceLink');
    if (sourceLink) {
        sourceLink.textContent = `Combined: ${names}`;
        sourceLink.classList.add('filled');
    }
    showMessage('Combined sources selected!', 'success');
    showView('hero');
}

// ─── RENDER DESTINATION VIEW ────────────────────────────────────────────────
function renderDestinationView() {
    renderInstitutionsGrid(PARTICIPANTS);
    renderSavedRecipients();
}

function renderInstitutionsGrid(participants, filter = '') {
    const grid = document.getElementById('institutionsGridContainer');
    if (!grid) return;

    const keys = Object.keys(participants).filter(code => {
        if (!filter) return true;
        const name = (participants[code]?.name || code).toLowerCase();
        return name.includes(filter.toLowerCase());
    });

    if (keys.length === 0 && !filter) {
        // No participants configured — show placeholder
        grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--text-muted);">No institutions configured for ${CONFIG.COUNTRY_CODE}.</div>`;
        return;
    }

    const institutionIcons = {
        'BAN': '🏦', 'BANK': '🏦', 'MNO': '📱', 'POST': '📮',
        'INSURANCE': '🛡️', 'MICRO': '🏘️', 'SACCO': '🤝'
    };

    function getIcon(code, p) {
        if (p?.type) {
            const t = p.type.toUpperCase();
            for (const [k, v] of Object.entries(institutionIcons)) {
                if (t.includes(k)) return v;
            }
        }
        return '🏛️';
    }

    const html = keys.map(code => {
        const p = participants[code];
        const name = p?.name || p?.label || code;
        const icon = getIcon(code, p);
        const isSelected = state.toInst === code;
        return `<div class="institution-card${isSelected ? ' selected' : ''}" onclick="selectDestinationInst('${escapeHtml(code)}')" id="instCard_${escapeHtml(code)}">
            <div class="institution-card-icon">${icon}</div>
            <span class="institution-card-name">${escapeHtml(name)}</span>
        </div>`;
    }).join('');

    // Always add a "Link New" card at the end
    const linkNewCard = `<div class="institution-card" onclick="openAddSource()" style="border-style:dashed;">
        <div class="institution-card-icon" style="font-size:22px;">+</div>
        <span class="institution-card-name">Link New</span>
    </div>`;

    grid.innerHTML = html + linkNewCard;
}

function filterInstitutions(query) {
    renderInstitutionsGrid(PARTICIPANTS, query);
}

function selectDestinationInst(instCode) {
    state.toInst = instCode;
    state.swapType = 'DEPOSIT';

    // Sync hidden select
    const sel = document.getElementById('toInstSelect');
    if (sel) sel.value = instCode;

    // Highlight selected card
    document.querySelectorAll('.institution-card').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById(`instCard_${instCode}`);
    if (card) card.classList.add('selected');

    // Get participant asset types for this institution
    const p = PARTICIPANTS[instCode];
    const pAssets = p?.assets || p?.asset_types || [];

    if (pAssets.length > 0) {
        // Pick first asset type and render its fields
        const assetType = pAssets[0];
        state.toAsset = assetType;
        renderToFields(assetType);
    } else {
        // Default to ACCOUNT
        state.toAsset = 'ACCOUNT';
        renderToFields('ACCOUNT');
    }

    refreshHeroDestLabel();
}

function renderToFields(assetType) {
    const cfg = getAssetConfig(assetType);
    const panel = document.getElementById('toFieldsPanel');
    const container = document.getElementById('toFieldsContainer');

    if (!cfg || !cfg.fields || !Array.isArray(cfg.fields) || cfg.fields.length === 0) {
        // Still show confirm button even without dynamic fields
        if (panel) {
            panel.style.display = 'block';
            if (container) container.innerHTML = '';
        }
        return;
    }
    if (panel) panel.style.display = 'block';
    if (container) {
        container.innerHTML = renderDynamicFields(cfg.fields, 'to', state.toFields);
    }
}

function confirmInstitutionDest() {
    if (!state.toInst) {
        showMessage('Please select an institution first.', 'warning');
        return;
    }
    // Collect to-fields
    const container = document.getElementById('toFieldsContainer');
    if (container) {
        container.querySelectorAll('[data-field]').forEach(input => {
            state.toFields[input.dataset.field] = input.value;
        });
    }
    refreshHeroDestLabel();
    showMessage(`Destination: ${getParticipantName(state.toInst)} selected.`, 'success');
    showView('hero');
}

function confirmIdTransfer() {
    const val = document.getElementById('destinationIdentityInput').value.trim();
    if (!val) {
        showMessage('Please enter a Recipient National ID Number', 'warning');
        return;
    }
    state.toIdentityValue = val;
    state.swapType = 'IDENTITY';
    state.toInst = '_IDENTITY_';

    // Sync hidden fields
    const ivEl = document.getElementById('identityValue');
    if (ivEl) ivEl.value = val;
    const smsEl = document.getElementById('identitySms');
    if (smsEl) smsEl.value = state.toIdentitySms;
    const swType = document.getElementById('swapTypeSelect');
    if (swType) swType.value = 'IDENTITY';

    refreshHeroDestLabel();
    showMessage(`Destination set to National ID: ${val}`, 'success');
    showView('hero');
}

function renderSavedRecipients() {
    const list = document.getElementById('savedRecipientsList');
    if (!list) return;
    if (!savedIdentities || savedIdentities.length === 0) {
        list.innerHTML = `<div class="empty-state" style="padding:20px 0;">
            <div class="empty-state-icon">👤</div>
            <h4>No saved recipients</h4>
            <p>Save a recipient to speed up future transfers.</p>
        </div>`;
        return;
    }
    list.innerHTML = savedIdentities.map((id, i) => {
        return `<div class="saved-item-row" onclick="selectSavedRecipient(${i})">
            <div class="saved-item-info">
                <span style="font-size:18px;">👤</span>
                <div>
                    <div>${escapeHtml(id.name || id.identifier)}</div>
                    <div style="font-size:12px;color:var(--text-muted);font-weight:400;">${escapeHtml(id.type || 'National ID')}</div>
                </div>
            </div>
        </div>`;
    }).join('');
}

function selectSavedRecipient(idx) {
    const recipient = savedIdentities[idx];
    if (!recipient) return;
    state.toIdentityValue = recipient.identifier || '';
    state.swapType = 'IDENTITY';
    state.toInst = '_IDENTITY_';
    refreshHeroDestLabel();
    showMessage(`Recipient: ${recipient.name || recipient.identifier} selected.`, 'success');
    showView('hero');
}

// ─── DYNAMIC FIELD RENDERER ────────────────────────────────────────────────
function renderDynamicFields(fields, prefix, valuesObj) {
    if (!fields || !Array.isArray(fields)) return '';
    return fields.map(f => {
        const key = f.key || f.name || '';
        const label = f.label || key;
        const type = f.type || 'text';
        const required = f.required ? 'required' : '';
        const current = valuesObj[key] || '';
        const dataAttr = `data-field="${escapeHtml(key)}" data-prefix="${prefix}"`;
        const onChange = `onchange="updateDynamicField('${prefix}','${escapeHtml(key)}',this.value)"`;
        const onInput  = `oninput="updateDynamicField('${prefix}','${escapeHtml(key)}',this.value)"`;

        if (type === 'select' && f.options) {
            const opts = f.options.map(o => {
                const val = typeof o === 'object' ? (o.value || o.key) : o;
                const lbl = typeof o === 'object' ? (o.label || o.value || o.key) : o;
                return `<option value="${escapeHtml(val)}" ${current===val?'selected':''}>${escapeHtml(lbl)}</option>`;
            }).join('');
            return `<div class="dynamic-field-group">
                <label>${escapeHtml(label)}</label>
                <select ${dataAttr} ${onChange} ${required}>
                    <option value="">Select ${escapeHtml(label)}</option>
                    ${opts}
                </select>
            </div>`;
        }

        const inputType = type === 'phone' ? 'tel' : type === 'number' ? 'number' : 'text';
        return `<div class="dynamic-field-group">
            <label>${escapeHtml(label)}</label>
            <input type="${inputType}" ${dataAttr} value="${escapeHtml(current)}" placeholder="${escapeHtml(label)}" ${onInput} ${required}>
        </div>`;
    }).join('');
}

function updateDynamicField(prefix, key, value) {
    if (prefix === 'from') {
        state.fromFields[key] = value;
    } else {
        state.toFields[key] = value;
    }
}

function fieldsValidForAsset(assetType, fieldsObj) {
    const cfg = getAssetConfig(assetType);
    if (!cfg || !cfg.fields) return true;
    return cfg.fields.filter(f => f.required).every(f => {
        const key = f.key || f.name || '';
        return fieldsObj[key] && String(fieldsObj[key]).trim() !== '';
    });
}

// ─── HIDDEN SELECT SYNC ────────────────────────────────────────────────────
function selectToInst(value) {
    state.toInst = value;
    refreshHeroDestLabel();
}
function selectToAsset(value) {
    state.toAsset = value;
}
function setSwapType(value) {
    state.swapType = value;
}

// ─── REVIEW MODAL ──────────────────────────────────────────────────────────
function openReviewModal() {
    if (!state.fromAmount || state.fromAmount <= 0) {
        showMessage('Please enter a valid amount.', 'warning');
        return;
    }
    if (!state.fromInst && state.multiSources.length < 1) {
        showMessage('Please select a source.', 'warning');
        return;
    }
    if (!state.toInst) {
        showMessage('Please select a destination.', 'warning');
        return;
    }

    const srcName = state.swapType === 'MULTI_SOURCE'
        ? `${state.multiSources.length} Combined Sources`
        : getParticipantName(state.fromInst);
    const destName = state.swapType === 'IDENTITY'
        ? `National ID: ${state.toIdentityValue}`
        : getParticipantName(state.toInst);

    const body = `
        <div class="review-panel" style="margin-top:0;">
            <div class="review-row">
                <span class="review-label">Amount</span>
                <span class="review-value">${formatMoney(state.fromAmount, CONFIG.CURRENCY)}</span>
            </div>
            <div class="review-row">
                <span class="review-label">From</span>
                <span class="review-value">${escapeHtml(srcName)}</span>
            </div>
            <div class="review-row">
                <span class="review-label">To</span>
                <span class="review-value">${escapeHtml(destName)}</span>
            </div>
            <div class="review-row">
                <span class="review-label">Type</span>
                <span class="review-value">${escapeHtml(state.swapType)}</span>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:24px;">
            <button class="btn-dark-full" onclick="previewSwap()">
                Get Quote & Preview →
            </button>
            <button class="btn-white-pill" style="width:100%;justify-content:center;" onclick="closeModal()">
                Edit Transfer
            </button>
        </div>
    `;
    openModal('Review Transfer', body);
}

// ─── API PAYLOAD & PREVIEW ─────────────────────────────────────────────────
async function buildPayload() {
    const payload = {
        swap_type: state.swapType,
        amount: state.fromAmount,
        user_id: CONFIG.USER_ID,
        from_institution: state.fromInst,
        from_asset: state.fromAsset,
        from_fields: state.fromFields,
        to_institution: state.toInst,
        to_asset: state.toAsset,
        to_fields: state.toFields,
        delivery_method: state.deliveryMethod,
        beneficiary_phone: state.beneficiaryPhone,
        to_identity_type: state.toIdentityType,
        to_identity_value: state.toIdentityValue,
        to_identity_sms: state.toIdentitySms,
        multi_sources: state.multiSources.map(s => {
            const src = userSources[s.idx];
            return src || {};
        }),
        country: CONFIG.COUNTRY_CODE,
    };
    return payload;
}

async function previewSwap() {
    const payload = await buildPayload();
    showMessage('Fetching quote…', 'info');
    const result = await callApi(CONFIG.PREVIEW_ENDPOINT, payload);
    if (result.ok) {
        state.lastPreview = result.body;
        const preview = result.body;
        const fee = preview.fee ?? preview.total_fee ?? 0;
        const recv = preview.recipient_gets ?? preview.net_amount ?? (state.fromAmount - fee);
        const exch = preview.exchange_rate ?? preview.rate ?? null;

        const detailHtml = `
            <div class="review-panel" style="margin-top:0;">
                <div class="review-row">
                    <span class="review-label">You Send</span>
                    <span class="review-value">${formatMoney(state.fromAmount, CONFIG.CURRENCY)}</span>
                </div>
                ${fee ? `<div class="review-row">
                    <span class="review-label">Fee</span>
                    <span class="review-value">${formatMoney(fee, CONFIG.CURRENCY)}</span>
                </div>` : ''}
                ${exch ? `<div class="review-row">
                    <span class="review-label">Exchange Rate</span>
                    <span class="review-value">${exch}</span>
                </div>` : ''}
                <div class="review-row">
                    <span class="review-label">Recipient Gets</span>
                    <span class="review-value" style="color:var(--success);font-size:16px;">${formatMoney(recv, preview.to_currency || CONFIG.CURRENCY)}</span>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:10px;margin-top:20px;">
                <button class="btn-dark-full" onclick="executeSwap()">Confirm & Execute →</button>
                <button class="btn-white-pill" style="width:100%;justify-content:center;" onclick="closeModal()">Cancel</button>
            </div>
        `;
        openModal('Transfer Quote', detailHtml);
    } else {
        showMessage(result.error || 'Could not fetch quote. Please try again.', 'error');
    }
}

async function executeSwap() {
    const payload = await buildPayload();
    showMessage('Executing transfer…', 'info');
    const result = await callApi(CONFIG.EXECUTE_ENDPOINT, payload);
    if (result.ok) {
        closeModal();
        showMessage('Transfer executed successfully!', 'success');
        // Reset state
        state.fromInst = null; state.fromAsset = null;
        state.toInst = null; state.toAsset = null;
        state.fromAmount = 0; state.toIdentityValue = '';
        state.multiSources = [];
        document.getElementById('fromAmount').value = '';
        refreshHeroSourceLabel();
        refreshHeroDestLabel();
        // Reload balance & history
        loadUserBalance();
    } else {
        showMessage(result.body?.message || result.error || 'Transfer failed. Please try again.', 'error');
    }
}

// ─── TRANSACTION HISTORY ───────────────────────────────────────────────────
async function openSwapHistory() {
    if (!CONFIG.USER_ID) {
        renderEmptyLedger('No user session found.');
        return;
    }
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/history.php', {
        user_id: CONFIG.USER_ID, limit: 100
    });
    if (result.ok) {
        const swaps = result.body.data || result.body.swaps || [];
        ledgerState.allSwaps = swaps;
        ledgerState.page = 1;
        renderLedgerPage();
        renderLedgerSummary(swaps);
    } else {
        renderEmptyLedger('Could not load transactions.');
    }
}

function renderLedgerPage() {
    const { allSwaps, page, perPage, filters } = ledgerState;
    let filtered = allSwaps.filter(s => {
        if (filters.status !== 'all' && s.status !== filters.status) return false;
        if (filters.query) {
            const q = filters.query.toLowerCase();
            const dest = (s.destination_institution || s.reference || '').toLowerCase();
            const type = (s.swap_type || '').toLowerCase();
            return dest.includes(q) || type.includes(q);
        }
        return true;
    });

    const total = filtered.length;
    const start = (page - 1) * perPage;
    const pageItems = filtered.slice(start, start + perPage);

    const tbody = document.getElementById('ledgerTableBody');
    const countSpan = document.getElementById('ledgerCountSpan');
    const pageLabel = document.getElementById('ledgerPageLabel');
    const prevBtn = document.getElementById('ledgerPrevBtn');
    const nextBtn = document.getElementById('ledgerNextBtn');

    if (pageItems.length === 0) {
        renderEmptyLedger('No transactions found.');
        return;
    }

    tbody.innerHTML = pageItems.map(swap => {
        const date = new Date(swap.created_at || Date.now());
        const datePrimary = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: '2-digit' });
        const dateSub = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        const amount = parseFloat(swap.amount || 0);
        const isPositive = amount > 0;
        const status = (swap.status || 'completed').toLowerCase();
        const statusClass = status === 'completed' ? 'completed' : status === 'pending' ? 'pending' : 'failed';
        const statusLabel = status === 'completed' ? 'Completed' : status === 'pending' ? '• Pending' : 'Failed';

        const destInst = swap.destination_institution || swap.to_institution || '';
        const destName = getParticipantName(destInst) || destInst || swap.reference || 'Transfer';
        const swapType = swap.swap_type || 'Standard';
        const icon = swapType === 'IDENTITY' ? '🪪' : swapType === 'MULTI_SOURCE' ? '🔗' : '🏛️';
        const iconClass = isPositive ? 'green' : '';

        return `<tr>
            <td>
                <div style="font-weight:700;">${escapeHtml(datePrimary)}</div>
                <div style="font-size:11px;color:var(--text-muted);">${escapeHtml(dateSub)}</div>
            </td>
            <td>
                <div class="destination-cell">
                    <div class="destination-icon-box ${iconClass}">${icon}</div>
                    <div>
                        <div class="destination-title">${escapeHtml(destName)}</div>
                        <div class="destination-sub">${escapeHtml(swapType)}</div>
                    </div>
                </div>
            </td>
            <td style="text-align:right;font-weight:700;${isPositive ? 'color:var(--success);' : ''}">
                ${isPositive ? '+' : '−'}${formatMoney(Math.abs(amount), swap.currency || CONFIG.CURRENCY)}
            </td>
            <td style="text-align:right;">
                <span class="status-pill ${statusClass}">${statusLabel}</span>
            </td>
        </tr>`;
    }).join('');

    if (countSpan) countSpan.textContent = `Showing ${start + 1}–${Math.min(start + perPage, total)} of ${total} transactions`;
    if (pageLabel) pageLabel.textContent = page;
    if (prevBtn) prevBtn.disabled = page <= 1;
    if (nextBtn) nextBtn.disabled = start + perPage >= total;
}

function renderEmptyLedger(msg) {
    const tbody = document.getElementById('ledgerTableBody');
    if (tbody) {
        tbody.innerHTML = `<tr><td colspan="4">
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <h4>No transactions yet</h4>
                <p>${escapeHtml(msg)}</p>
            </div>
        </td></tr>`;
    }
    const span = document.getElementById('ledgerCountSpan');
    if (span) span.textContent = '0 transactions';
}

function renderLedgerSummary(swaps) {
    // Monthly volume
    const now = new Date();
    const thisMonth = swaps.filter(s => {
        const d = new Date(s.created_at || 0);
        return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
    });
    const monthTotal = thisMonth.reduce((sum, s) => sum + Math.abs(parseFloat(s.amount || 0)), 0);

    const valEl = document.getElementById('monthlyVolumeValue');
    const descEl = document.getElementById('monthlyVolumeDesc');
    if (valEl) valEl.textContent = formatMoney(monthTotal, CONFIG.CURRENCY);
    if (descEl) {
        const txCount = thisMonth.length;
        if (txCount > 0) {
            descEl.textContent = `${txCount} transaction${txCount !== 1 ? 's' : ''} this month · ${CONFIG.CURRENCY} volume`;
        } else {
            descEl.textContent = 'No transactions this month yet.';
        }
    }
}

function ledgerPageNext() {
    ledgerState.page++;
    renderLedgerPage();
}
function ledgerPagePrev() {
    if (ledgerState.page > 1) {
        ledgerState.page--;
        renderLedgerPage();
    }
}

// ─── ADD SOURCE / RECIPIENT MODAL ──────────────────────────────────────────
function openAddSource() {
    const instOptions = Object.keys(PARTICIPANTS).length > 0
        ? Object.keys(PARTICIPANTS).map(code => `<option value="${escapeHtml(code)}">${escapeHtml(PARTICIPANTS[code]?.name || code)}</option>`).join('')
        : '<option value="HERITAGE">Heritage Global</option><option value="SUMMIT">Summit Reserve</option>';

    const body = `
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">Save frequently used accounts or IDs for faster transfers.</p>

        <div class="form-group">
            <label>Recipient Name</label>
            <input id="addSourceAccountName" placeholder="e.g. Jane Doe">
        </div>

        <div class="form-group">
            <label>Recipient Type</label>
            <div class="segmented-toggle">
                <button type="button" class="segmented-btn active" id="typeBtnBank" onclick="setRecipientTypeTab('bank')">Bank Account</button>
                <button type="button" class="segmented-btn" id="typeBtnId" onclick="setRecipientTypeTab('id')">National ID</button>
            </div>
        </div>

        <div class="form-group" id="instSelectGroup">
            <label>Institution</label>
            <select id="addSourceInst">
                <option value="">Select an institution</option>
                ${instOptions}
            </select>
        </div>

        <div class="form-group">
            <label id="accountNumLabel">Account Number</label>
            <input id="addSourceIdentifier" placeholder="Enter account number">
        </div>

        <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:28px;">
            <button class="btn-white-pill" onclick="closeModal()">Cancel</button>
            <button class="btn-dark-pill" onclick="submitAddSource()">Save Recipient</button>
        </div>
    `;
    openModal('Add New Recipient', body);
}

function openAddCard() {
    const body = `
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">Link a credit or debit card as a funding source.</p>
        <div class="form-group">
            <label>Cardholder Name</label>
            <input id="cardholderName" placeholder="${escapeHtml(CONFIG.USER_NAME)}">
        </div>
        <div class="form-group">
            <label>Card Number</label>
            <input id="cardNumber" placeholder="•••• •••• •••• ••••" maxlength="19" oninput="formatCardInput(this)">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label>Expiry (MM/YY)</label>
                <input id="cardExpiry" placeholder="MM/YY" maxlength="5">
            </div>
            <div class="form-group">
                <label>CVV</label>
                <input id="cardCvv" placeholder="•••" maxlength="4" type="password">
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:20px;">
            <button class="btn-white-pill" onclick="closeModal()">Cancel</button>
            <button class="btn-dark-pill" onclick="submitAddCard()">Save Card</button>
        </div>
    `;
    openModal('Add New Card', body);
}

function formatCardInput(input) {
    let val = input.value.replace(/\D/g, '').slice(0, 16);
    input.value = val.replace(/(.{4})/g, '$1 ').trim();
}

function submitAddCard() {
    const num = (document.getElementById('cardNumber')?.value || '').replace(/\s/g, '');
    if (num.length < 12) { showMessage('Enter a valid card number.', 'warning'); return; }
    const last4 = num.slice(-4);
    const expiry = document.getElementById('cardExpiry')?.value || '';
    closeModal();

    // Render the new card in the UI
    const cardList = document.getElementById('savedCardsList');
    const noCardsBox = document.getElementById('noCardsBox');
    if (noCardsBox) noCardsBox.style.display = 'none';
    if (cardList) {
        const item = document.createElement('div');
        item.className = 'saved-item-row';
        item.innerHTML = `
            <div class="saved-item-info">
                <span style="font-size:18px;">💳</span>
                <span>•••• ${escapeHtml(last4)}</span>
            </div>
            <span class="saved-item-exp">Exp ${escapeHtml(expiry)}</span>
        `;
        item.onclick = () => {
            state.fromInst = '_CARD_';
            state.fromAsset = 'CARD';
            const srcLink = document.getElementById('sourceLink');
            if (srcLink) { srcLink.textContent = `Card ••••${last4}`; srcLink.classList.add('filled'); }
            refreshHeroProceedBtn();
            showMessage(`Card ••••${last4} selected as source.`, 'success');
            showView('hero');
        };
        // Insert before the dashed-btn
        cardList.insertBefore(item, cardList.firstChild);
    }
    showMessage(`Card ••••${last4} saved successfully!`, 'success');
}

function setRecipientTypeTab(type) {
    const bankBtn = document.getElementById('typeBtnBank');
    const idBtn = document.getElementById('typeBtnId');
    const instGroup = document.getElementById('instSelectGroup');
    const numLabel = document.getElementById('accountNumLabel');
    const numInput = document.getElementById('addSourceIdentifier');

    if (type === 'bank') {
        bankBtn?.classList.add('active');
        idBtn?.classList.remove('active');
        if (instGroup) instGroup.style.display = 'block';
        if (numLabel) numLabel.textContent = 'Account Number';
        if (numInput) numInput.placeholder = 'Enter account number';
    } else {
        idBtn?.classList.add('active');
        bankBtn?.classList.remove('active');
        if (instGroup) instGroup.style.display = 'none';
        if (numLabel) numLabel.textContent = 'National ID Number';
        if (numInput) numInput.placeholder = '0000 - 0000 - 0000';
    }
}

async function submitAddSource() {
    const identifier = document.getElementById('addSourceIdentifier')?.value.trim();
    const accountName = document.getElementById('addSourceAccountName')?.value.trim();
    const instSelect = document.getElementById('addSourceInst');
    const institution = instSelect ? instSelect.value : '';

    if (!identifier) { showMessage('Enter an account number or ID.', 'warning'); return; }

    // Optimistically add to savedIdentities list
    savedIdentities.push({ name: accountName || identifier, identifier, institution, type: institution ? 'Bank Account' : 'National ID' });

    closeModal();
    showMessage(`Recipient ${accountName || identifier} saved!`, 'success');
    renderSavedRecipients();
}

// ─── WALLETS MODAL ─────────────────────────────────────────────────────────
async function openMyWalletsMenu() {
    let content = '<p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;">Your linked wallets and balances:</p>';

    if (userSources.length > 0) {
        content += userSources.map(src => {
            const instCode = src.institution || src.participant || '';
            const name = getParticipantName(instCode) || instCode;
            const assetType = src.asset_type || src.type || 'ACCOUNT';
            const bal = src.balance != null ? formatMoney(src.balance, src.currency || CONFIG.CURRENCY) : '—';
            return `<div class="saved-item-row" style="cursor:default;">
                <div class="saved-item-info">
                    <span style="font-size:18px;">🏦</span>
                    <div>
                        <div>${escapeHtml(name)}</div>
                        <div style="font-size:12px;color:var(--text-muted);font-weight:400;">${escapeHtml(assetType)}</div>
                    </div>
                </div>
                <span class="saved-item-balance">${escapeHtml(bal)}</span>
            </div>`;
        }).join('');
    } else {
        content += `<div class="empty-state">
            <div class="empty-state-icon">👛</div>
            <h4>No linked accounts</h4>
            <p>Link an account to see balances here.</p>
        </div>`;
    }
    content += `<button class="btn-dark-full" style="margin-top:16px;" onclick="closeModal();showView('source');">+ Link New Account</button>`;
    openModal('My Wallets', content);
}

// ─── BALANCE MODAL ─────────────────────────────────────────────────────────
async function viewWalletBalance() {
    const balDisplay = document.getElementById('heroTotalBalanceDisplay')?.textContent || '—';
    let content = `<div style="text-align:center;padding:8px 0 24px;">
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px;">Total Balance</div>
        <div style="font-size:36px;font-weight:800;letter-spacing:-0.02em;">${escapeHtml(balDisplay)}</div>
    </div>`;
    if (userSources.length > 0) {
        content += '<hr style="border:none;border-top:1px solid var(--border);margin:0 0 16px;">';
        content += '<p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">By Account</p>';
        content += userSources.map(src => {
            const instCode = src.institution || src.participant || '';
            const name = getParticipantName(instCode) || instCode;
            const bal = src.balance != null ? formatMoney(src.balance, src.currency || CONFIG.CURRENCY) : '—';
            return `<div class="review-row">
                <span class="review-label">${escapeHtml(name)}</span>
                <span class="review-value">${escapeHtml(bal)}</span>
            </div>`;
        }).join('');
    }
    openModal('Balance Overview', content);
}

// ─── FILTER / EXPORT ───────────────────────────────────────────────────────
function filterLedger() {
    const statusOptions = ['all', 'completed', 'pending', 'failed'].map(s =>
        `<option value="${s}" ${ledgerState.filters.status === s ? 'selected' : ''}>${s === 'all' ? 'All Statuses' : s.charAt(0).toUpperCase() + s.slice(1)}</option>`
    ).join('');
    openModal('Filter Transactions', `
        <div class="form-group">
            <label>Status</label>
            <select id="filterStatusSel">${statusOptions}</select>
        </div>
        <div class="form-group">
            <label>Search</label>
            <input id="filterQueryInput" value="${escapeHtml(ledgerState.filters.query)}" placeholder="Destination or type…">
        </div>
        <div style="display:flex;gap:10px;margin-top:20px;">
            <button class="btn-dark-full" onclick="applyLedgerFilter()">Apply Filter</button>
            <button class="btn-white-pill" style="width:100%;justify-content:center;" onclick="resetLedgerFilter()">Reset</button>
        </div>
    `);
}

function applyLedgerFilter() {
    ledgerState.filters.status = document.getElementById('filterStatusSel')?.value || 'all';
    ledgerState.filters.query = document.getElementById('filterQueryInput')?.value || '';
    ledgerState.page = 1;
    closeModal();
    renderLedgerPage();
}

function resetLedgerFilter() {
    ledgerState.filters = { status: 'all', query: '' };
    ledgerState.page = 1;
    closeModal();
    renderLedgerPage();
}

function exportLedger() {
    const swaps = ledgerState.allSwaps;
    if (!swaps.length) { showMessage('No transactions to export.', 'warning'); return; }
    const rows = [['Date', 'Destination', 'Amount', 'Currency', 'Status', 'Type']];
    swaps.forEach(s => {
        rows.push([
            s.created_at || '',
            s.destination_institution || s.reference || '',
            s.amount || '',
            s.currency || CONFIG.CURRENCY,
            s.status || '',
            s.swap_type || ''
        ]);
    });
    const csv = rows.map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `vouchmorph_transactions_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    showMessage('Transactions exported!', 'success');
}

// ─── QUICK HELPERS ─────────────────────────────────────────────────────────
function quickSetSwapType(type) {
    state.swapType = type;
    if (type === 'MULTI_SOURCE') selectCombineFunds();
}

// ─── MODAL HELPERS ─────────────────────────────────────────────────────────
function openModal(title, bodyHtml) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
    document.getElementById('modal').classList.add('active');
}
function closeModal() {
    document.getElementById('modal').classList.remove('active');
}

// ─── PROFILE / TOOLBOX / HELP ──────────────────────────────────────────────
function openProfileModal() {
    openModal('Profile', `
        <div style="text-align:center;padding-bottom:20px;">
            <div class="avatar-circle" style="width:64px;height:64px;font-size:24px;margin:0 auto 12px;"><?php echo htmlspecialchars($userInitials); ?></div>
            <div style="font-size:20px;font-weight:800;"><?php echo htmlspecialchars($userName); ?></div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;"><?php echo htmlspecialchars(ucfirst($userRole)); ?> · <?php echo htmlspecialchars($userCountry); ?></div>
        </div>
        <div class="review-row"><span class="review-label">User ID</span><span class="review-value"><?php echo htmlspecialchars($userId); ?></span></div>
        <div class="review-row"><span class="review-label">Currency</span><span class="review-value"><?php echo htmlspecialchars($countryCurrency); ?></span></div>
        <div class="review-row"><span class="review-label">Country</span><span class="review-value"><?php echo htmlspecialchars($userCountry); ?></span></div>
        <a href="logout.php" style="display:block;margin-top:24px;text-align:center;font-size:13px;color:var(--danger);font-weight:600;text-decoration:none;">Sign Out</a>
    `);
}

function openToolbox() {
    openModal('Notifications', '<div class="empty-state"><div class="empty-state-icon">🔔</div><h4>All caught up</h4><p>No new notifications at this time.</p></div>');
}
function openHelpModal() {
    openModal('Help Centre', '<p style="color:var(--text-muted);line-height:1.7;">Need help? Contact <strong>support@vouchmorph.com</strong> or visit our documentation portal.<br><br>Our support team operates Monday–Friday, 08:00–17:00.</p>');
}
function openTermsModal() {
    openModal('Terms & Conditions', '<p style="color:var(--text-muted);line-height:1.7;">By using VouchMorph, you agree to our Terms of Service and Privacy Policy. All transactions are subject to applicable financial regulations in your jurisdiction.</p>');
}

// ─── INIT ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Wire amount input resize on load
    const amountInput = document.getElementById('fromAmount');
    if (amountInput) {
        amountInput.addEventListener('input', (e) => onAmountInput(e.target));
        amountInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                showView('source');
            }
        });
    }

    // Load real user data
    loadUserSources();   // loads sources → renders source view & balance
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>
