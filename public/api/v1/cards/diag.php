<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Standalone Diagnostic
 *
 * DELETE THIS FILE after you're done — it skips login on purpose so
 * it's easy to hit directly in a browser, and it can reveal internal
 * paths/config presence. Not for production use.
 *
 * Visit: https://<your-host>/api/v1/cards/Diag.php
 *
 * Tests each dependency in isolation, in order, and stops at the
 * first failure — whatever step fails is the exact next thing to fix.
 */

header("Content-Type: text/plain; charset=UTF-8");

define('ROOT_PATH', dirname(__DIR__, 4));

$results = [];
function step_pass(array &$results, string $label, string $detail = ''): void {
    $results[] = "[OK]   {$label}" . ($detail ? " — {$detail}" : '');
}
function step_fail(array &$results, string $label, string $detail): void {
    $results[] = "[FAIL] {$label} — {$detail}";
}
function flush_results(array $results): void {
    echo implode("\n", $results) . "\n\n";
}

echo "VouchMorph Card diagnostic\n";
echo "ROOT_PATH = " . ROOT_PATH . "\n";
echo "PHP version = " . PHP_VERSION . "\n";
echo str_repeat('=', 60) . "\n\n";

// ------------------------------------------------------------
// STEP 1: file existence — checked BEFORE any require, since a
// require on a missing file is an uncatchable fatal.
// ------------------------------------------------------------
$requiredFiles = [
    'bootstrap.php' => ROOT_PATH . '/src/bootstrap.php',
    'CardService.php' => ROOT_PATH . '/src/Domain/Services/CardService.php',
    'CardContributionSessionService.php' => ROOT_PATH . '/src/Domain/Services/CardContributionSessionService.php',
    'ContributionCalculator.php' => ROOT_PATH . '/src/Domain/Services/ContributionCalculator.php',
    'QrCodeService.php' => ROOT_PATH . '/src/Infrastructure/QRcodes/QrCodeService.php',
    'QrPayload.php' => ROOT_PATH . '/src/Infrastructure/QRcodes/Contracts/QrPayload.php',
    'QrAdapterInterface.php' => ROOT_PATH . '/src/Infrastructure/QRcodes/Contracts/QrAdapterInterface.php',
    'VouchMorphHookQrAdapter.php' => ROOT_PATH . '/src/Infrastructure/QRcodes/Adapters/VouchMorphHookQrAdapter.php',
    'SessionManager.php' => ROOT_PATH . '/src/Application/Utils/SessionManager.php',
];

echo "STEP 1: File existence\n" . str_repeat('-', 60) . "\n";
$fileResults = [];
$allFilesOk = true;
foreach ($requiredFiles as $label => $path) {
    if (file_exists($path)) {
        step_pass($fileResults, $label);
    } else {
        step_fail($fileResults, $label, "NOT FOUND at {$path}");
        $allFilesOk = false;
    }
}
flush_results($fileResults);

if (!$allFilesOk) {
    echo "STOPPED HERE.\n";
    echo "One or more required files are not deployed to this server.\n";
    echo "Fix: commit/push the missing file(s) marked [FAIL] above, redeploy, then reload this page.\n";
    exit;
}

// ------------------------------------------------------------
// STEP 2: require bootstrap.php and capture the container
// ------------------------------------------------------------
echo "STEP 2: bootstrap.php\n" . str_repeat('-', 60) . "\n";
$step2 = [];
try {
    $container = require ROOT_PATH . '/src/bootstrap.php';
    if (is_object($container)) {
        step_pass($step2, 'bootstrap.php required successfully', 'returned ' . get_class($container));
    } else {
        step_fail($step2, 'bootstrap.php required successfully', 'but did not return an object (returned ' . gettype($container) . ') — check it has a `return $container;` at the end');
        flush_results($step2);
        exit;
    }
} catch (\Throwable $e) {
    step_fail($step2, 'bootstrap.php required successfully', get_class($e) . ': ' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
    flush_results($step2);
    echo "STOPPED HERE — bootstrap.php itself threw. Fix this first; nothing downstream can work without it.\n";
    exit;
}
flush_results($step2);

// ------------------------------------------------------------
// STEP 3: require the rest of the classes (now safe, files confirmed to exist)
// ------------------------------------------------------------
echo "STEP 3: require remaining class files\n" . str_repeat('-', 60) . "\n";
$step3 = [];
$classFiles = [
    ROOT_PATH . '/src/Domain/Services/CardService.php',
    ROOT_PATH . '/src/Domain/Services/CardContributionSessionService.php',
    ROOT_PATH . '/src/Domain/Services/ContributionCalculator.php',
    ROOT_PATH . '/src/Infrastructure/QRcodes/QrCodeService.php',
    ROOT_PATH . '/src/Infrastructure/QRcodes/Contracts/QrPayload.php',
    ROOT_PATH . '/src/Infrastructure/QRcodes/Contracts/QrAdapterInterface.php',
    ROOT_PATH . '/src/Infrastructure/QRcodes/Adapters/VouchMorphHookQrAdapter.php',
    ROOT_PATH . '/src/Application/Utils/SessionManager.php',
];
foreach ($classFiles as $f) {
    try {
        require_once $f;
        step_pass($step3, basename($f) . ' parsed/loaded');
    } catch (\Throwable $e) {
        step_fail($step3, basename($f) . ' parsed/loaded', get_class($e) . ': ' . $e->getMessage());
    }
}
flush_results($step3);
// Note: a parse error (bad syntax) in any of these is ALSO an uncatchable
// fatal, same as a missing file. If this script dies silently right here
// with no output at all past STEP 2, that's the signal: one of the files
// above has a PHP syntax error. Check your host's error log for the exact
// file/line — it will be reported there even though this script can't
// report it via try/catch.

use Domain\Services\CardService;
use Domain\Services\CardContributionSessionService;
use Domain\Services\ContributionCalculator;
use Infrastructure\QRcodes\QrCodeService;
use Infrastructure\QRcodes\Adapters\VouchMorphHookQrAdapter;
use Infrastructure\QRcodes\Contracts\QrPayload;
use Application\Utils\SessionManager;

// ------------------------------------------------------------
// STEP 4: container bindings
// ------------------------------------------------------------
echo "STEP 4: container bindings\n" . str_repeat('-', 60) . "\n";
$step4 = [];
$db = null;
$cardConfig = [];
$countryCode = null;
try {
    $db = $container->get(PDO::class);
    step_pass($step4, "container->get(PDO::class)", $db instanceof PDO ? 'returned a PDO instance' : 'returned ' . gettype($db) . ' (NOT a PDO instance!)');
} catch (\Throwable $e) {
    step_fail($step4, "container->get(PDO::class)", get_class($e) . ': ' . $e->getMessage());
}
try {
    $cardConfig = $container->get('countryConfig') ?? [];
    step_pass($step4, "container->get('countryConfig')", 'type=' . gettype($cardConfig) . (is_array($cardConfig) ? ', keys=' . implode(',', array_keys($cardConfig)) : ''));
} catch (\Throwable $e) {
    step_fail($step4, "container->get('countryConfig')", get_class($e) . ': ' . $e->getMessage());
}
try {
    $countryCode = $container->get('countryCode');
    step_pass($step4, "container->get('countryCode')", var_export($countryCode, true));
} catch (\Throwable $e) {
    step_fail($step4, "container->get('countryCode')", get_class($e) . ': ' . $e->getMessage());
}
flush_results($step4);

if (!($db instanceof PDO)) {
    echo "STOPPED HERE — no working PDO from the container. Nothing past this point can work.\n";
    exit;
}

// ------------------------------------------------------------
// STEP 5: instantiate CardService (this is where PAN_HMAC_KEY etc. would throw)
// ------------------------------------------------------------
echo "STEP 5: instantiate CardService\n" . str_repeat('-', 60) . "\n";
$step5 = [];
$cardService = null;
try {
    $cardService = new CardService($db, (string)$countryCode, $cardConfig);
    step_pass($step5, 'new CardService(...)');
} catch (\Throwable $e) {
    step_fail($step5, 'new CardService(...)', get_class($e) . ': ' . $e->getMessage());
}
flush_results($step5);

if (!$cardService) {
    echo "STOPPED HERE — CardService could not be constructed. See the [FAIL] line above for the exact reason";
    echo " (commonly: PAN_HMAC_KEY / VRN_SIGNING_KEY env var missing or under 32 bytes).\n";
    exit;
}

// ------------------------------------------------------------
// STEP 6: the message_cards table itself
// ------------------------------------------------------------
echo "STEP 6: message_cards table\n" . str_repeat('-', 60) . "\n";
$step6 = [];
try {
    $stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'message_cards' ORDER BY ordinal_position");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($cols)) {
        step_fail($step6, 'message_cards table exists', 'query succeeded but returned NO columns — table likely does not exist');
    } else {
        step_pass($step6, 'message_cards table exists', count($cols) . ' columns: ' . implode(', ', $cols));
        $hasFundingMode = in_array('funding_mode', $cols, true);
        $hasActivatedAt = in_array('activated_at', $cols, true);
        if ($hasFundingMode) { step_pass($step6, "column 'funding_mode' present"); }
        else { step_fail($step6, "column 'funding_mode' present", 'MISSING — run the 2026_08_card_auto_provision.sql migration'); }
        if ($hasActivatedAt) { step_pass($step6, "column 'activated_at' present"); }
        else { step_fail($step6, "column 'activated_at' present", 'MISSING — run the 2026_08_card_auto_provision.sql migration'); }
    }
} catch (\Throwable $e) {
    step_fail($step6, 'message_cards table exists', get_class($e) . ': ' . $e->getMessage());
}
flush_results($step6);

// ------------------------------------------------------------
// STEP 7: actually try provisionUserCard() with a throwaway test user id
// ------------------------------------------------------------
echo "STEP 7: CardService::provisionUserCard() dry run (test_user_id = 999999999)\n" . str_repeat('-', 60) . "\n";
$step7 = [];
try {
    $result = $cardService->provisionUserCard(999999999, 'Diagnostic Test User');
    step_pass($step7, 'provisionUserCard() returned', json_encode($result));
} catch (\Throwable $e) {
    step_fail($step7, 'provisionUserCard()', get_class($e) . ': ' . $e->getMessage() . ' (in ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
flush_results($step7);

echo str_repeat('=', 60) . "\n";
echo "Diagnostic complete. If everything above is [OK], My.php should work —\n";
echo "the remaining variable is your real logged-in user's session/user_id.\n";
echo "DELETE THIS FILE once you're done.\n";
