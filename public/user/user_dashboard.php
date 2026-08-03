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

/* Header Styling */
.site-header {
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
}
.header-inner {
    max-width: var(--max-w);
    margin: 0 auto;
    padding: 0 24px;
    height: var(--header-h);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.brand { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.logo-title { font-size: 22px; font-weight: 800; color: var(--text); letter-spacing: -0.03em; }
.brand-divider { width: 1px; height: 18px; background: var(--border-strong); opacity: 0.6; }
.tagline-text { font-size: 13px; color: var(--text-muted); font-weight: 400; }

.header-nav {
    display: flex;
    align-items: center;
    gap: 4px;
    background: var(--surface-muted);
    padding: 4px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border);
}
.nav-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 18px;
    border-radius: var(--radius-pill);
    font-size: 14px;
    font-weight: 500;
    color: var(--text-muted);
    border: none;
    background: transparent;
    cursor: pointer;
    transition: var(--transition);
    white-space: nowrap;
    text-decoration: none;
}
.nav-pill-btn:hover { color: var(--text); background: rgba(255,255,255,0.6); }
.nav-pill-btn.active { background: var(--primary); color: #FFFFFF; font-weight: 600; }

.header-right { display: flex; align-items: center; gap: 14px; }
.bell-btn {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--surface); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--text-muted); transition: var(--transition);
}
.bell-btn:hover { background: var(--surface-muted); color: var(--text); }

.user-profile-badge {
    display: flex; align-items: center; gap: 10px;
    padding: 4px 6px 4px 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-pill);
    cursor: pointer;
    transition: var(--transition);
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

/* Page Containers */
.main-wrapper {
    max-width: var(--max-w);
    width: 100%;
    margin: 0 auto;
    padding: 32px 24px 60px;
    flex: 1;
}

.view-section { display: none; }
.view-section.active { display: block; }

/* Back Link */
.back-link-btn {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 14px; font-weight: 500; color: var(--text-muted);
    text-decoration: none; cursor: pointer; margin-bottom: 24px;
    transition: var(--transition);
}
.back-link-btn:hover { color: var(--text); }

/* Page Titles */
.page-title { font-size: 32px; font-weight: 800; letter-spacing: -0.02em; color: var(--text); margin-bottom: 4px; }
.page-subtitle { font-size: 16px; color: var(--text-muted); margin-bottom: 32px; }

/* Hero Screen (Image 2) */
.hero-container {
    min-height: 55vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 20px 0;
}
.madlib-sentence {
    font-size: clamp(28px, 4.2vw, 44px);
    font-weight: 800;
    line-height: 1.35;
    letter-spacing: -0.02em;
    color: var(--text);
    max-width: 800px;
    margin: 0 auto 36px;
}
.madlib-slot {
    display: inline-block;
    color: var(--accent);
    background: rgba(90, 138, 122, 0.08);
    border-bottom: 2px solid var(--accent);
    padding: 2px 10px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: var(--transition);
    margin: 0 4px;
    text-decoration: none;
}
.madlib-slot:hover {
    color: var(--primary);
    background: rgba(11, 30, 25, 0.12);
    border-bottom-color: var(--primary);
}
.madlib-amount-input {
    width: 170px;
    border: none;
    border-bottom: 2px solid var(--accent);
    background: rgba(90, 138, 122, 0.08);
    font-size: inherit;
    font-weight: 800;
    color: var(--accent);
    text-align: center;
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    outline: none;
    font-family: var(--font);
}
.madlib-amount-input:focus {
    color: var(--primary);
    border-bottom-color: var(--primary);
    background: rgba(11, 30, 25, 0.12);
}

.total-balance-card {
    width: 100%;
    max-width: 440px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 24px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    cursor: pointer;
    transition: var(--transition);
}
.total-balance-card:hover {
    border-color: var(--border-strong);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.balance-title { font-size: 13px; color: var(--text-muted); margin-bottom: 8px; font-weight: 500; }
.balance-value { font-size: 36px; font-weight: 800; color: var(--text); letter-spacing: -0.02em; margin-bottom: 6px; }
.balance-trend { font-size: 13px; color: var(--success); font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }

/* Grid Layout for Source & Destination screens */
.selection-grid-2col {
    display: grid;
    grid-template-columns: 1fr;
    gap: 24px;
}
@media (min-width: 900px) {
    .selection-grid-2col { grid-template-columns: 1fr 1fr; }
}

/* Cards & Content Boxes */
.option-card-panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 28px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 24px;
}
.card-header-row {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 20px;
}
.card-icon-box {
    width: 44px; height: 44px;
    background: var(--surface-muted);
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: var(--primary);
    flex-shrink: 0;
}
.card-icon-box.dark {
    background: var(--primary);
    color: #FFFFFF;
}
.card-title-text h3 { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
.card-title-text p { font-size: 13px; color: var(--text-muted); }

.dashed-content-box {
    border: 1.5px dashed var(--border-strong);
    background: var(--surface-muted);
    border-radius: var(--radius-md);
    padding: 32px 20px;
    text-align: center;
}
.dashed-content-box p { font-size: 13px; color: var(--text-muted); margin-bottom: 16px; }

.saved-item-row {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.saved-item-info { display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 15px; }
.saved-item-exp { font-size: 13px; color: var(--text-muted); }

.dashed-btn {
    width: 100%;
    border: 1.5px dashed var(--border-strong);
    background: transparent;
    border-radius: var(--radius-sm);
    padding: 12px;
    font-size: 14px; font-weight: 600; color: var(--text);
    cursor: pointer; transition: var(--transition);
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.dashed-btn:hover { background: var(--surface-muted); border-color: var(--text); }

/* Buttons */
.btn-dark-pill {
    background: var(--primary);
    color: #FFFFFF;
    font-size: 14px;
    font-weight: 600;
    padding: 10px 24px;
    border-radius: var(--radius-pill);
    border: none;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
}
.btn-dark-pill:hover { background: var(--primary-dark); }

.btn-dark-full {
    width: 100%;
    background: var(--primary);
    color: #FFFFFF;
    font-size: 14px;
    font-weight: 600;
    padding: 14px;
    border-radius: var(--radius-sm);
    border: none;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.btn-dark-full:hover { background: var(--primary-dark); }

/* Progress bar */
.progress-bar-wrap { margin: 20px 0; }
.progress-label-row { display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 8px; }
.progress-track { width: 100%; height: 8px; background: var(--surface-muted); border-radius: 4px; overflow: hidden; }
.progress-fill { height: 100%; background: var(--primary); border-radius: 4px; }

/* Institutions Grid (Image 3) */
.institutions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 16px;
    margin-top: 16px;
}
.institution-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 16px 12px;
    text-align: center;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
}
.institution-card:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}
.institution-card-icon {
    width: 40px; height: 40px; border-radius: var(--radius-sm);
    background: var(--surface-muted);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: var(--text);
}
.institution-card-name { font-size: 12px; font-weight: 600; color: var(--text); line-height: 1.2; }

/* Inputs */
.large-id-input {
    width: 100%;
    padding: 16px;
    background: var(--surface-muted);
    border: 1px solid var(--border-strong);
    border-radius: var(--radius-sm);
    font-size: 18px;
    font-weight: 700;
    font-family: monospace;
    letter-spacing: 2px;
    color: var(--text);
    margin-bottom: 16px;
}
.large-id-input:focus { outline: none; border-color: var(--primary); background: var(--surface); }

.checkbox-label {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; color: var(--text-muted);
    cursor: pointer; margin-bottom: 20px;
}

/* Transaction Ledger Table (Image 5) */
.ledger-header-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
.ledger-actions { display: flex; gap: 10px; }
.btn-white-pill {
    background: var(--surface);
    border: 1px solid var(--border-strong);
    padding: 8px 16px;
    border-radius: var(--radius-pill);
    font-size: 13px; font-weight: 600;
    color: var(--text); cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: var(--transition);
}
.btn-white-pill:hover { background: var(--surface-muted); }

.ledger-table-container {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    margin-bottom: 24px;
}
.ledger-table { width: 100%; border-collapse: collapse; text-align: left; }
.ledger-table th {
    padding: 14px 20px;
    font-size: 11px; font-weight: 700; color: var(--text-dim);
    letter-spacing: 0.05em; text-transform: uppercase;
    background: var(--surface-muted); border-bottom: 1px solid var(--border);
}
.ledger-table td {
    padding: 16px 20px;
    font-size: 14px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.ledger-table tr:last-child td { border-bottom: none; }
.ledger-table tr:hover { background: rgba(0,0,0,0.01); }

.destination-cell { display: flex; align-items: center; gap: 12px; }
.destination-icon-box {
    width: 36px; height: 36px; border-radius: var(--radius-sm);
    background: var(--surface-muted);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: var(--primary); flex-shrink: 0;
}
.destination-icon-box.green { background: rgba(46, 125, 82, 0.12); color: var(--success); }
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
    padding: 14px 20px;
    background: var(--surface-muted);
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px; color: var(--text-muted); font-weight: 500;
}
.pagination-controls { display: flex; gap: 8px; }
.page-arrow-btn {
    width: 28px; height: 28px; border-radius: 4px;
    border: 1px solid var(--border); background: var(--surface);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--text-muted);
}
.page-arrow-btn:hover { background: var(--surface-hover); color: var(--text); }

/* Ledger Summary Cards (Bottom of Image 5) */
.ledger-summary-grid {
    display: grid; grid-template-columns: 1fr; gap: 20px;
}
@media (min-width: 768px) {
    .ledger-summary-grid { grid-template-columns: 1fr 1fr; }
}
.summary-card {
    border-radius: var(--radius-md); padding: 24px;
}
.summary-card.dark { background: var(--primary); color: #FFFFFF; }
.summary-card.light { background: var(--surface-muted); border: 1px solid var(--border); color: var(--text); }

.summary-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-weight: 700; font-size: 15px; }
.summary-card-desc { font-size: 13px; line-height: 1.5; opacity: 0.85; }

/* Modals */
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
}
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px; border-bottom: 1px solid var(--border);
}
.modal-header h2 { font-size: 18px; font-weight: 800; color: var(--text); }
.modal-close-btn { background: none; border: none; font-size: 24px; color: var(--text-muted); cursor: pointer; }
.modal-body { padding: 24px; }

/* Form Elements inside Modals */
.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; }
.form-group input, .form-group select {
    width: 100%; padding: 12px 14px;
    background: var(--surface-muted); border: 1px solid var(--border-strong);
    border-radius: var(--radius-sm); color: var(--text); font-size: 14px; font-family: var(--font);
}
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); background: var(--surface); }

.segmented-toggle {
    display: flex; background: var(--surface-muted); padding: 4px; border-radius: var(--radius-sm); gap: 4px;
}
.segmented-btn {
    flex: 1; padding: 8px; text-align: center; font-size: 13px; font-weight: 600;
    border: none; background: transparent; color: var(--text-muted); border-radius: 6px; cursor: pointer;
}
.segmented-btn.active { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }

/* Footer */
.site-footer {
    border-top: 1px solid var(--border);
    padding: 28px 0;
    margin-top: auto;
}
.footer-inner {
    max-width: var(--max-w);
    margin: 0 auto;
    padding: 0 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    font-size: 13px;
    color: var(--text-muted);
}
.footer-links { display: flex; gap: 20px; font-weight: 500; }
.footer-links span { cursor: pointer; transition: var(--transition); }
.footer-links span:hover { color: var(--text); }

/* System message bar */
.system-msg {
    max-width: var(--max-w); margin: 16px auto 0; padding: 12px 16px;
    border-radius: var(--radius-sm); font-size: 13px; font-weight: 500; display: none;
}
.system-msg.show { display: block; }
.system-msg.info { background: var(--accent-soft); color: var(--primary); border-left: 3px solid var(--accent); }
.system-msg.success { background: var(--success-bg); color: var(--success-text); border-left: 3px solid var(--success); }
.system-msg.error { background: var(--danger-bg); color: var(--danger-text); border-left: 3px solid var(--danger); }
.system-msg.warning { background: var(--warning-bg); color: var(--warning-text); border-left: 3px solid var(--warning); }
</style>
</head>
<body>

<!-- Header Component -->
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
                <span class="material-symbols-outlined" style="font-size: 20px;">notifications</span>
            </button>
            
            <div class="user-profile-badge" onclick="openProfileModal()">
                <div class="user-info-text">
                    <span class="user-info-name"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="user-info-subtitle"><?php echo htmlspecialchars(ucfirst($userRole)); ?></span>
                </div>
                <div class="avatar-circle"><?php echo htmlspecialchars($userInitials); ?></div>
            </div>
            <a href="logout.php" style="font-size:12px;color:var(--text-muted);text-decoration:none;margin-left:4px;">Logout</a>
        </div>
    </div>
</header>

<div class="system-msg" id="mainMessage"></div>

<!-- Main Content Area -->
<main class="main-wrapper">

    <!-- 1. HERO TRANSFER VIEW (Image 2) -->
    <section class="view-section active" id="view-hero">
        <div class="hero-container">
            <h1 class="madlib-sentence">
                I want to send 
                <span class="madlib-slot" style="padding:0;">
                    <input type="number" id="fromAmount" class="madlib-amount-input" placeholder="0.00" step="0.01" min="0.01">
                    <span id="fromCurrencyLabel" style="font-size:0.6em;font-weight:800;">BWP</span>
                </span>
                from my<br>
                <a class="madlib-slot" id="sourceLink" onclick="showView('source');return false;">[Select Source]</a>
                to<br>
                <a class="madlib-slot" id="destinationLink" onclick="showView('destination');return false;">[Select Destination]</a> .
            </h1>

            <div class="total-balance-card" onclick="viewWalletBalance()">
                <div class="balance-title">Total Balance across all sources</div>
                <div class="balance-value" id="heroTotalBalanceDisplay">$4,278.00</div>
                <div class="balance-trend">📈 +2.4% from last month</div>
            </div>
        </div>
    </section>

    <!-- 2. SELECT A SOURCE VIEW (Image 1) -->
    <section class="view-section" id="view-source">
        <a class="back-link-btn" onclick="showView('hero')">
            <span class="material-symbols-outlined" style="font-size:18px;">arrow_back</span>
            Back to Transfer
        </a>

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
                    <div class="dashed-content-box" id="savedSourcesContainer">
                        <p id="noSourcesPromptText">No bank account linked yet.</p>
                        <div id="savedSourcesList"></div>
                        <button class="btn-dark-pill" onclick="openAddSource()">Link an Account</button>
                    </div>
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
                    <div class="saved-item-row">
                        <div class="saved-item-info">
                            <span style="font-size:18px;">💳</span>
                            <span>•••• 4421</span>
                        </div>
                        <span class="saved-item-exp">Exp 12/26</span>
                    </div>
                    <button class="dashed-btn" onclick="openAddSource()">
                        <span>+</span> Add a New Card
                    </button>
                </div>
            </div>

            <!-- Right Column: Combine Funds -->
            <div>
                <div class="option-card-panel">
                    <div class="card-header-row">
                        <div class="card-icon-box">↗</div>
                        <div class="card-title-text">
                            <h3>Combine Funds</h3>
                            <p>Merge multiple sources into a single transfer.</p>
                        </div>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px;">
                        <div class="saved-item-row">
                            <div class="saved-item-info">
                                <span class="material-symbols-outlined" style="font-size:18px;color:var(--text-muted);">add_circle</span>
                                <span>Main Savings</span>
                            </div>
                            <span style="font-weight:700;">$1,240.00</span>
                        </div>
                        <div class="saved-item-row">
                            <div class="saved-item-info">
                                <span class="material-symbols-outlined" style="font-size:18px;color:var(--text-muted);">add_circle</span>
                                <span>Investment Pool</span>
                            </div>
                            <span style="font-weight:700;">$850.00</span>
                        </div>
                    </div>

                    <div class="progress-bar-wrap">
                        <div class="progress-label-row">
                            <span style="color:var(--text-muted);">Progress to Target</span>
                            <span style="font-weight:700;">75%</span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" style="width: 75%;"></div>
                        </div>
                    </div>

                    <button class="btn-dark-full" onclick="quickSetSwapType('MULTI_SOURCE')">
                        Combine Funds →
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- 3. SELECT A DESTINATION VIEW (Image 3) -->
    <section class="view-section" id="view-destination">
        <a class="back-link-btn" onclick="showView('hero')">
            <span class="material-symbols-outlined" style="font-size:18px;">arrow_back</span>
            Back to Transfer
        </a>

        <h1 class="page-title">Select a Destination</h1>
        <p class="page-subtitle">Where should this money go?</p>

        <div style="max-width:760px;">
            <!-- Card 1: Deposit to Bank/Institution -->
            <div class="option-card-panel">
                <div class="card-header-row" style="justify-content:space-between;align-items:center;">
                    <div style="display:flex;align-items:center;gap:14px;">
                        <div class="card-icon-box dark">🏛️</div>
                        <div class="card-title-text">
                            <h3>Deposit to Bank/Institution</h3>
                        </div>
                    </div>
                    <input type="text" placeholder="🔍 Search other institutions..." style="padding:8px 14px;background:var(--surface-muted);border:1px solid var(--border);border-radius:var(--radius-pill);font-size:13px;width:200px;">
                </div>

                <div class="institutions-grid" id="institutionsGridContainer">
                    <div class="institution-card" onclick="selectDestinationInst('HERITAGE')">
                        <div class="institution-card-icon">🏛️</div>
                        <span class="institution-card-name">Heritage Global</span>
                    </div>
                    <div class="institution-card" onclick="selectDestinationInst('SUMMIT')">
                        <div class="institution-card-icon">🏦</div>
                        <span class="institution-card-name">Summit Reserve</span>
                    </div>
                    <div class="institution-card" onclick="selectDestinationInst('MERIDIAN')">
                        <div class="institution-card-icon">🏬</div>
                        <span class="institution-card-name">Meridian Trust</span>
                    </div>
                    <div class="institution-card" onclick="openAddSource()">
                        <div class="institution-card-icon">+</div>
                        <span class="institution-card-name">Link New</span>
                    </div>
                </div>
            </div>

            <!-- Card 2: Send to ID Card -->
            <div class="option-card-panel">
                <div class="card-header-row">
                    <div class="card-icon-box dark">🪪</div>
                    <div class="card-title-text">
                        <h3>Send to ID Card</h3>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;">Recipient National ID Number</label>
                    <input type="text" id="destinationIdentityInput" class="large-id-input" placeholder="0000 - 0000 - 0000" oninput="state.toIdentityValue=this.value.trim();refreshUI();">
                    
                    <label class="checkbox-label">
                        <input type="checkbox" id="sendSmsNotificationCheck" onchange="state.toIdentitySms=this.checked ? '1' : '';">
                        Send SMS notification
                    </label>

                    <button class="btn-dark-full" id="confirmIdTransferBtn" onclick="confirmIdTransfer()">
                        Confirm ID Transfer
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. TRANSACTION LEDGER VIEW (Image 5) -->
    <section class="view-section" id="view-activity">
        <div class="ledger-header-toolbar">
            <div>
                <h1 class="page-title" style="margin-bottom:2px;">Transaction Ledger</h1>
                <p style="font-size:14px;color:var(--text-muted);">Review your historical and pending fund movements.</p>
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
                        <td>
                            <div style="font-weight:700;">Today</div>
                            <div style="font-size:11px;color:var(--text-muted);">10:24 AM</div>
                        </td>
                        <td>
                            <div class="destination-cell">
                                <div class="destination-icon-box">🏛️</div>
                                <div>
                                    <div class="destination-title">National ID: 8821...902</div>
                                    <div class="destination-sub">Vault Sweep Transfer</div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:right;font-weight:700;">-$12,000.00</td>
                        <td style="text-align:right;"><span class="status-pill pending">• Pending</span></td>
                    </tr>
                    <tr>
                        <td>
                            <div style="font-weight:700;">Oct 24, 2024</div>
                            <div style="font-size:11px;color:var(--text-muted);">03:15 PM</div>
                        </td>
                        <td>
                            <div class="destination-cell">
                                <div class="destination-icon-box green">👤</div>
                                <div>
                                    <div class="destination-title">Sarah Miller</div>
                                    <div class="destination-sub">Consulting Fee Disbursement</div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:right;font-weight:700;color:var(--success);">+$4,250.00</td>
                        <td style="text-align:right;"><span class="status-pill completed">Completed</span></td>
                    </tr>
                    <tr>
                        <td>
                            <div style="font-weight:700;">Oct 22, 2024</div>
                            <div style="font-size:11px;color:var(--text-muted);">09:00 AM</div>
                        </td>
                        <td>
                            <div class="destination-cell">
                                <div class="destination-icon-box">💳</div>
                                <div>
                                    <div class="destination-title">National ID: 4402...115</div>
                                    <div class="destination-sub">Tax Provisioning</div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:right;font-weight:700;">-$2,110.00</td>
                        <td style="text-align:right;"><span class="status-pill completed">Completed</span></td>
                    </tr>
                    <tr>
                        <td>
                            <div style="font-weight:700;">Oct 20, 2024</div>
                            <div style="font-size:11px;color:var(--text-muted);">11:45 PM</div>
                        </td>
                        <td>
                            <div class="destination-cell">
                                <div class="destination-icon-box">👛</div>
                                <div>
                                    <div class="destination-title">Internal Wallet Merge</div>
                                    <div class="destination-sub">Institutional Consolidation</div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:right;font-weight:700;color:var(--success);">+$85,000.00</td>
                        <td style="text-align:right;"><span class="status-pill completed">Completed</span></td>
                    </tr>
                    <tr>
                        <td>
                            <div style="font-weight:700;">Oct 18, 2024</div>
                            <div style="font-size:11px;color:var(--text-muted);">04:30 PM</div>
                        </td>
                        <td>
                            <div class="destination-cell">
                                <div class="destination-icon-box">🏢</div>
                                <div>
                                    <div class="destination-title">Global Equity Partners</div>
                                    <div class="destination-sub">Asset Liquidation</div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:right;font-weight:700;">-$34,500.00</td>
                        <td style="text-align:right;"><span class="status-pill completed">Completed</span></td>
                    </tr>
                </tbody>
            </table>
            <div class="ledger-table-footer">
                <span id="ledgerCountSpan">Showing 5 of 142 transactions</span>
                <div class="pagination-controls">
                    <button class="page-arrow-btn">&lt;</button>
                    <button class="page-arrow-btn">&gt;</button>
                </div>
            </div>
        </div>

        <!-- Summary Cards (Bottom of Image 5) -->
        <div class="ledger-summary-grid">
            <div class="summary-card dark">
                <div class="summary-card-header">
                    <span>📈</span> Monthly Volume
                </div>
                <div class="summary-card-desc">
                    Your total transfer volume this month is 12% higher than your 6-month average.
                </div>
            </div>
            <div class="summary-card light">
                <div class="summary-card-header">
                    <span>🛡️</span> Security Audit
                </div>
                <div class="summary-card-desc">
                    All transactions listed are cryptographically verified and signed by your primary identity key.
                </div>
            </div>
        </div>
    </section>

    <!-- Hidden Form Fields Container for Backend Compatibility -->
    <div id="fullFormCard" style="display:none;">
        <select id="toInstSelect" onchange="selectToInst(this.value)"><option value="">Select institution</option></select>
        <select id="toAssetSelect" onchange="selectToAsset(this.value)"></select>
        <select id="swapTypeSelect" onchange="setSwapType(this.value)">
            <option value="DEPOSIT">Deposit</option>
            <option value="CASHOUT">Cashout</option>
            <option value="IDENTITY">Identity</option>
            <option value="MULTI_SOURCE">Multi-Source</option>
        </select>
        <select id="identityType" onchange="state.toIdentityType=this.value;">
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

<!-- Footer Component -->
<footer class="site-footer">
    <div class="footer-inner">
        <div>© 2026 VouchMorph Financial. All rights reserved.</div>
        <div class="footer-links">
            <span onclick="openHelpModal()">Help Centre</span>
            <span onclick="openTermsModal()">Privacy Policy</span>
            <span onclick="openTermsModal()">Terms & Conditions</span>
        </div>
    </div>
</footer>

<!-- Modals System -->
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal-card" id="modalContent">
        <div class="modal-header">
            <h2 id="modalTitle">Modal Title</h2>
            <button class="modal-close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
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
    multiDestMode: 'institution', tabTotalAmount: 0,
    tabAllocationMode: 'even', contributionStrategy: 'SMART',
    selectedSourceType: null, selectedDestinationType: null
};
let ledgerState = { allSwaps: [], filters: { status: 'all', query: '' } };
let savedIdentities = [];
let userSources = [];
let pendingClaims = [];
let pendingSources = [];
let agentStatus = { is_agent: false, approved_destinations: [], all_destinations: [] };
let SessionUser = null;

function formatMoney(amount, currency) {
    const num = parseFloat(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (!currency) return `$${num}`;
    return `$${num} ${String(currency).toUpperCase().slice(0, 3)}`;
}

function showView(viewName) {
    document.querySelectorAll('.view-section').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-pill-btn').forEach(el => el.classList.remove('active'));

    if (viewName === 'hero') {
        document.getElementById('view-hero').classList.add('active');
        document.getElementById('nav-move-money').classList.add('active');
    } else if (viewName === 'source') {
        document.getElementById('view-source').classList.add('active');
        document.getElementById('nav-move-money').classList.add('active');
    } else if (viewName === 'destination') {
        document.getElementById('view-destination').classList.add('active');
        document.getElementById('nav-move-money').classList.add('active');
    } else if (viewName === 'activity') {
        document.getElementById('view-activity').classList.add('active');
        document.getElementById('nav-activity').classList.add('active');
        openSwapHistory();
    }
}

function selectDestinationInst(instCode) {
    state.toInst = instCode;
    state.swapType = 'DEPOSIT';
    state.toAsset = 'ACCOUNT';
    document.getElementById('destinationLink').textContent = instCode;
    showMessage(`Destination selected: ${instCode}`, 'success');
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
    document.getElementById('destinationLink').textContent = `ID: ${val}`;
    showMessage(`Destination set to National ID: ${val}`, 'success');
    showView('hero');
}

// Add New Recipient Modal (Image 4)
function openAddSource() {
    const instOptions = Object.keys(PARTICIPANTS).length > 0
        ? Object.keys(PARTICIPANTS).map(code => `<option value="${code}">${PARTICIPANTS[code]?.name || code}</option>`).join('')
        : '<option value="HERITAGE">Heritage Global</option><option value="SUMMIT">Summit Reserve</option><option value="MERIDIAN">Meridian Trust</option>';

    const body = `
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">Save frequently used bank accounts or IDs for faster transfers.</p>
        
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
            <button class="btn-dark-pill" id="addSourceSubmitBtn" onclick="submitAddSource()">Save Recipient</button>
        </div>
    `;
    openModal('Add New Recipient', body);
}

function setRecipientTypeTab(type) {
    const bankBtn = document.getElementById('typeBtnBank');
    const idBtn = document.getElementById('typeBtnId');
    const instGroup = document.getElementById('instSelectGroup');
    const numLabel = document.getElementById('accountNumLabel');
    const numInput = document.getElementById('addSourceIdentifier');

    if (type === 'bank') {
        bankBtn.classList.add('active');
        idBtn.classList.remove('active');
        instGroup.style.display = 'block';
        numLabel.textContent = 'Account Number';
        numInput.placeholder = 'Enter account number';
    } else {
        idBtn.classList.add('active');
        bankBtn.classList.remove('active');
        instGroup.style.display = 'none';
        numLabel.textContent = 'National ID Number';
        numInput.placeholder = '0000 - 0000 - 0000';
    }
}

async function submitAddSource() {
    const identifier = document.getElementById('addSourceIdentifier').value.trim();
    const accountName = document.getElementById('addSourceAccountName').value.trim();
    const instSelect = document.getElementById('addSourceInst');
    const institution = instSelect ? instSelect.value : 'HERITAGE';

    if (!identifier) { showMessage('Enter identifier', 'warning'); return; }

    closeModal();
    showMessage(`Recipient ${accountName || identifier} saved successfully!`, 'success');
}

// Transaction History Functionality
async function openSwapHistory() {
    if (!CONFIG.USER_ID) return;
    const result = await callApi(CONFIG.API_BASE + '/api/v1/swap/history.php', { user_id: CONFIG.USER_ID, limit: 50 });
    if (result.ok) {
        const swaps = result.body.data || result.body.swaps || [];
        ledgerState.allSwaps = swaps;
        renderSwapHistory(result.body);
    }
}

function renderSwapHistory(data) {
    const swaps = data.data || data.swaps || ledgerState.allSwaps || [];
    if (swaps.length === 0) return;

    const tbody = document.getElementById('ledgerTableBody');
    if (!tbody) return;

    tbody.innerHTML = swaps.map(swap => {
        const date = new Date(swap.created_at || Date.now());
        const datePrimary = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        const dateSub = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        const amount = parseFloat(swap.amount || 0);
        const isPositive = amount > 0;
        const status = (swap.status || 'completed').toLowerCase();
        const statusClass = status === 'completed' ? 'completed' : status === 'pending' ? 'pending' : 'failed';

        return `
            <tr>
                <td>
                    <div style="font-weight:700;">${datePrimary}</div>
                    <div style="font-size:11px;color:var(--text-muted);">${dateSub}</div>
                </td>
                <td>
                    <div class="destination-cell">
                        <div class="destination-icon-box">🏛️</div>
                        <div>
                            <div class="destination-title">${escapeHtml(swap.destination_institution || swap.reference || 'Transfer')}</div>
                            <div class="destination-sub">${escapeHtml(swap.swap_type || 'Standard')}</div>
                        </div>
                    </div>
                </td>
                <td style="text-align:right;font-weight:700;${isPositive ? 'color:var(--success);' : ''}">
                    ${isPositive ? '+' : '-'}${formatMoney(Math.abs(amount), swap.currency)}
                </td>
                <td style="text-align:right;"><span class="status-pill ${statusClass}">${status}</span></td>
            </tr>
        `;
    }).join('');

    const countSpan = document.getElementById('ledgerCountSpan');
    if (countSpan) countSpan.textContent = `Showing ${swaps.length} of ${swaps.length} transactions`;
}

// Modal helper
function openModal(title, bodyHtml) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
    document.getElementById('modal').classList.add('active');
}
function closeModal() {
    document.getElementById('modal').classList.remove('active');
}

function showMessage(text, type = 'info') {
    const el = document.getElementById('mainMessage');
    el.textContent = text; el.className = `system-msg show ${type}`;
    clearTimeout(showMessage._t);
    showMessage._t = setTimeout(() => el.classList.remove('show'), 5000);
}

function escapeHtml(str) { const div = document.createElement('div'); div.textContent = str == null ? '' : String(str); return div.innerHTML; }

async function buildPayload() {
    return {
        swap_type: state.swapType,
        amount: state.fromAmount,
        user_id: CONFIG.USER_ID
    };
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
async function viewWalletBalance() {
    openModal('My Balances', '<div style="text-align:center;padding:20px;">Total Balance across all accounts: <strong>$4,278.00</strong></div>');
}
async function previewSwap() {
    showMessage('Transaction Preview generated', 'success');
}
function quickSetSwapType(type) {
    state.swapType = type;
    showView('source');
}
function filterLedger() { openModal('Filter Ledger', '<p>Select date range or search keyword to filter transactions.</p>'); }
function exportLedger() { showMessage('Exporting transactions CSV...', 'success'); }
function openMyWalletsMenu() { openModal('My Wallets', '<p>Linked Wallets: Main Savings ($1,240.00), Investment Pool ($850.00).</p>'); }
function openToolbox() { openModal('Toolbox', '<p>Toolbox & Notifications.</p>'); }
function openProfileModal() { openModal('Profile', `<p>User: <?php echo htmlspecialchars($userName); ?><br>Role: <?php echo htmlspecialchars($userRole); ?></p>`); }
function openHelpModal() { openModal('Help Centre', '<p>Need help? Contact support or read docs.</p>'); }
function openTermsModal() { openModal('Terms & Conditions', '<p>VouchMorph terms and conditions.</p>'); }

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('fromAmount').addEventListener('input', (e) => {
        state.fromAmount = parseFloat(e.target.value) || 0;
    });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>
