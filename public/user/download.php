<?php
/**
 * download.php
 * Generate PDF using Headless Chrome/Chromium
 * Automatic download — no print dialog
 */

// ============================================================
// 1. CONFIGURATION
// ============================================================

// Get the base URL of your site
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$url = $protocol . $host . '/pdf.php';

// If you're in a subdirectory, add it
// $url = $protocol . $host . '/path/to/pdf.php';

// Output filename
$outputFilename = "VouchMorph_Investment_Memorandum.pdf";

// Temporary file
$tempFile = sys_get_temp_dir() . '/vouchmorph_memo_' . time() . '.pdf';

// ============================================================
// 2. FIND CHROME/CHROMIUM
// ============================================================

$chrome = null;

// Common paths
$paths = [
    // Linux
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/snap/bin/chromium',
    '/snap/bin/google-chrome',
    // macOS
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    // Windows (WSL)
    '/mnt/c/Program Files/Google/Chrome/Application/chrome.exe',
];

foreach ($paths as $path) {
    if (file_exists($path)) {
        $chrome = $path;
        break;
    }
}

// Try which command
if (!$chrome) {
    $which = trim(shell_exec('which google-chrome 2>/dev/null || which chromium 2>/dev/null || which chromium-browser 2>/dev/null'));
    if ($which && file_exists($which)) {
        $chrome = $which;
    }
}

// If still not found, check if it's in PATH
if (!$chrome) {
    $chrome = trim(shell_exec('command -v google-chrome 2>/dev/null || command -v chromium 2>/dev/null || command -v chromium-browser 2>/dev/null'));
    if (!empty($chrome) && file_exists($chrome)) {
        $chrome = trim($chrome);
    }
}

// If still not found, die with clear message
if (!$chrome) {
    die(json_encode([
        'success' => false,
        'error' => 'Google Chrome or Chromium is not installed.',
        'fix' => 'Run: sudo apt-get install chromium-browser'
    ]));
}

// ============================================================
// 3. GENERATE PDF
// ============================================================

// Chrome headless command with best settings
$command = sprintf(
    '%s --headless --disable-gpu --no-sandbox --disable-dev-shm-usage --print-to-pdf=%s %s 2>&1',
    escapeshellarg($chrome),
    escapeshellarg($tempFile),
    escapeshellarg($url)
);

// Execute
exec($command, $output, $status);

// Check if PDF was created
if (!file_exists($tempFile) || filesize($tempFile) < 1000) {
    // Try with different flags
    $command = sprintf(
        '%s --headless --disable-gpu --no-sandbox --print-to-pdf=%s --print-to-pdf-no-header %s 2>&1',
        escapeshellarg($chrome),
        escapeshellarg($tempFile),
        escapeshellarg($url)
    );
    exec($command, $output, $status);
}

// Final check
if (!file_exists($tempFile) || filesize($tempFile) < 5000) {
    die(json_encode([
        'success' => false,
        'error' => 'PDF generation failed.',
        'debug' => [
            'chrome' => $chrome,
            'url' => $url,
            'command' => $command,
            'status' => $status,
            'output' => $output,
            'temp_file' => $tempFile,
            'file_exists' => file_exists($tempFile),
            'file_size' => file_exists($tempFile) ? filesize($tempFile) : 0
        ]
    ]));
}

// ============================================================
// 4. DOWNLOAD THE PDF
// ============================================================

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $outputFilename . '"');
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($tempFile);

// Clean up
unlink($tempFile);

exit;
