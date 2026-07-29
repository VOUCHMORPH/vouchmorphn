<?php
/**
 * download.php
 * Generate PDF using Headless Chrome/Chromium
 * Automatic download — no print dialog
 */

// Increase timeout for PDF generation
set_time_limit(300);

// ============================================================
// 1. CONFIGURATION
// ============================================================

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$url = $protocol . $host . '/pdf.php';

// Output filename
$outputFilename = "VouchMorph_Investment_Memorandum.pdf";

// Temporary file
$tempFile = sys_get_temp_dir() . '/vouchmorph_memo_' . time() . '.pdf';

// ============================================================
// 2. FIND CHROME/CHROMIUM — Use environment variable first
// ============================================================

$chrome = getenv('CHROME_PATH');

if (!$chrome || !file_exists($chrome)) {
    $chrome = null;
    
    // Common paths as fallback
    $paths = [
        '/usr/bin/chromium',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $chrome = $path;
            break;
        }
    }
}

// Try command -v (POSIX compliant)
if (!$chrome) {
    $which = trim(shell_exec('command -v chromium 2>/dev/null || command -v google-chrome 2>/dev/null'));
    if ($which !== '' && file_exists($which)) {
        $chrome = $which;
    }
}

if (!$chrome) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Chromium is not installed.',
        'fix' => 'Set CHROME_PATH environment variable or install chromium.'
    ]);
    exit;
}

// ============================================================
// 3. GENERATE PDF with headless=new
// ============================================================

$command = sprintf(
    '%s --headless=new --disable-gpu --disable-dev-shm-usage --no-sandbox --run-all-compositor-stages-before-draw --virtual-time-budget=3000 --print-to-pdf=%s %s 2>&1',
    escapeshellarg($chrome),
    escapeshellarg($tempFile),
    escapeshellarg($url)
);

exec($command, $output, $status);

// If first attempt fails, try without --headless=new
if (!file_exists($tempFile) || filesize($tempFile) < 1000) {
    $command = sprintf(
        '%s --headless --disable-gpu --no-sandbox --print-to-pdf=%s %s 2>&1',
        escapeshellarg($chrome),
        escapeshellarg($tempFile),
        escapeshellarg($url)
    );
    exec($command, $output, $status);
}

// Final check
if (!file_exists($tempFile) || filesize($tempFile) < 5000) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'PDF generation failed.',
        'debug' => [
            'chrome' => $chrome,
            'url' => $url,
            'status' => $status,
            'file_exists' => file_exists($tempFile),
            'file_size' => file_exists($tempFile) ? filesize($tempFile) : 0
        ]
    ]);
    exit;
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
