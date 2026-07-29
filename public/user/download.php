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
// 2. FIND CHROME/CHROMIUM — Fixed with null checks
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

// Try which command — FIXED: check for null before trim
if (!$chrome) {
    $which = shell_exec('which google-chrome 2>/dev/null || which chromium 2>/dev/null || which chromium-browser 2>/dev/null');
    if ($which !== null && $which !== false && trim($which) !== '') {
        $which = trim($which);
        if (file_exists($which)) {
            $chrome = $which;
        }
    }
}

// Try command -v — FIXED: check for null before trim
if (!$chrome) {
    $cmd = shell_exec('command -v google-chrome 2>/dev/null || command -v chromium 2>/dev/null || command -v chromium-browser 2>/dev/null');
    if ($cmd !== null && $cmd !== false && trim($cmd) !== '') {
        $cmd = trim($cmd);
        if (file_exists($cmd)) {
            $chrome = $cmd;
        }
    }
}

// ============================================================
// 3. IF CHROME NOT FOUND — Try to install it
// ============================================================

if (!$chrome) {
    // Try to install chromium automatically
    $installOutput = [];
    $installStatus = 0;
    
    // Check if apt is available (Ubuntu/Debian)
    $aptCheck = shell_exec('which apt 2>/dev/null');
    if ($aptCheck !== null && trim($aptCheck) !== '') {
        // Try to install chromium
        exec('sudo apt-get update -qq 2>/dev/null && sudo apt-get install -y -qq chromium-browser 2>/dev/null', $installOutput, $installStatus);
        
        // Check if it was installed
        if (file_exists('/usr/bin/chromium-browser')) {
            $chrome = '/usr/bin/chromium-browser';
        } elseif (file_exists('/usr/bin/chromium')) {
            $chrome = '/usr/bin/chromium';
        }
    }
}

// ============================================================
// 4. FINAL CHECK — If still not found, show helpful error
// ============================================================

if (!$chrome) {
    // Check if we're on Railway or similar platform
    $isRailway = getenv('RAILWAY_ENVIRONMENT') !== false || getenv('RAILWAY_SERVICE_ID') !== false;
    
    $errorMsg = "Google Chrome or Chromium is not installed.";
    $fixMsg = "";
    
    if ($isRailway) {
        $fixMsg = "Add this to your Dockerfile:\n\n"
            . "RUN apt-get update && apt-get install -y chromium-browser \\\n"
            . "    && rm -rf /var/lib/apt/lists/*\n\n"
            . "Then redeploy your application.";
    } else {
        $fixMsg = "Run: sudo apt-get update && sudo apt-get install -y chromium-browser";
    }
    
    // Output as JSON with clear instructions
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $errorMsg,
        'fix' => $fixMsg,
        'platform' => $isRailway ? 'Railway' : 'Unknown'
    ]);
    exit;
}

// ============================================================
// 5. GENERATE PDF
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
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'PDF generation failed.',
        'debug' => [
            'chrome' => $chrome,
            'url' => $url,
            'status' => $status,
            'temp_file' => $tempFile,
            'file_exists' => file_exists($tempFile),
            'file_size' => file_exists($tempFile) ? filesize($tempFile) : 0
        ]
    ]);
    exit;
}

// ============================================================
// 6. DOWNLOAD THE PDF
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
