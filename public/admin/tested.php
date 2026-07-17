<?php
/**
 * check_missing_leg.php
 *
 * Read-only. Cross-checks a specific hold_transactions row that came back
 * NOT_FOUND during reconciliation against its parent multi_destination_swaps
 * record, to answer: did the original PLACE_HOLD for this leg ever actually
 * run, or did this leg fail before a hold was placed (in which case
 * NOT_FOUND is correct and expected, not a bug)?
 *
 * USAGE: ?ref=DISP_20260715_202327_F3CF14_DEST_0
 * (or any hold_transactions.swap_reference value)
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

define('PROJECT_ROOT', dirname(__DIR__, 2));
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;

if (!SessionManager::isAdminLoggedIn()) {
    die("Not logged in as admin.");
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
    $db = DBConnection::getConnection();
    $db->query("SELECT 1");
} catch (Throwable $e) {
    die("DB connection failed: " . h($e->getMessage()));
}

$ref = trim($_GET['ref'] ?? '');

?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Check Missing Leg</title>
<style>
body{font-family:'IBM Plex Mono',monospace;background:#f7f9fc;color:#001B44;padding:24px;max-width:1000px;margin:0 auto;}
.section{background:#fff;border:2px solid #001B44;border-radius:6px;padding:16px;margin-bottom:16px;}
input[type=text]{padding:8px;border:2px solid #001B44;border-radius:4px;font-family:monospace;min-width:400px;}
.btn{padding:8px 16px;background:#001B44;color:#fff;border:none;border-radius:4px;cursor:pointer;font-family:monospace;font-weight:700;}
pre{background:#1e293b;color:#4ade80;padding:12px;border-radius:4px;overflow-x:auto;font-size:0.75rem;}
.verdict{padding:12px;border-radius:6px;font-weight:700;margin-bottom:12px;}
.verdict.expected{background:#d4edda;color:#155724;}
.verdict.unclear{background:#fff3cd;color:#856404;}
</style>
</head>
<body>
<h1>🔍 Check Missing Leg</h1>
<form method="get" style="margin-bottom:20px;">
    <input type="text" name="ref" placeholder="e.g. DISP_20260715_202327_F3CF14_DEST_0" value="<?php echo h($ref); ?>">
    <button type="submit" class="btn">CHECK</button>
</form>

<?php if ($ref === ''): ?>
<div class="section">Enter the swap_reference (or sub-reference) that came back NOT_FOUND during reconciliation.</div>
<?php else:
    // Derive the parent reference by stripping any _DEST_N or _ID_N suffix.
    $parentRef = preg_replace('/_(DEST|ID)_\d+$/', '', $ref);
    ?>

<div class="section">
    <strong>Looking up:</strong> <?php echo h($ref); ?><br>
    <strong>Derived parent reference:</strong> <?php echo h($parentRef); ?>
</div>

<?php
try {
    $stmt = $db->prepare("SELECT * FROM multi_destination_swaps WHERE reference = :ref LIMIT 1");
    $stmt->execute([':ref' => $parentRef]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $parent = null;
    echo "<div class='section' style='color:#dc3545;'>Query failed: " . h($e->getMessage()) . "</div>";
}
?>

<?php if (!$parent): ?>
<div class="section">
    <div class="verdict unclear">No parent multi_destination_swaps record found for "<?php echo h($parentRef); ?>". This might not be a multi-destination sub-leg at all - check hold_transactions directly for this reference.</div>
</div>
<?php else:
    $results = json_decode($parent['results_payload'] ?? '[]', true) ?: [];
    $destinations = json_decode($parent['destinations_payload'] ?? '[]', true) ?: [];

    // Find the specific leg's index from the suffix.
    preg_match('/_(DEST|ID)_(\d+)$/', $ref, $m);
    $idx = isset($m[2]) ? (int)$m[2] : null;
    $legResult = $idx !== null ? ($results[$idx] ?? null) : null;
?>
<div class="section">
    <strong>Parent swap:</strong> <?php echo h($parent['reference']); ?> — status: <?php echo h($parent['status']); ?>,
    <?php echo h($parent['successful_count']); ?> succeeded / <?php echo h($parent['failed_count']); ?> failed
    out of <?php echo h($parent['total_destinations']); ?> total destinations.
</div>

<?php if ($legResult === null): ?>
<div class="section">
    <div class="verdict unclear">Could not find a result entry at index <?php echo h((string)$idx); ?> in this swap's results_payload. Showing the full payload below for manual inspection.</div>
    <pre><?php echo h(json_encode($results, JSON_PRETTY_PRINT)); ?></pre>
</div>
<?php else: ?>
<div class="section">
    <?php if (($legResult['status'] ?? '') === 'failed'): ?>
    <div class="verdict expected">✅ Expected: this leg is recorded as FAILED in VOUCHMORPH's own records. Error was: "<?php echo h($legResult['error'] ?? 'unknown'); ?>". A failed leg's hold likely never reached the PLACE_HOLD step successfully (or was released immediately on failure), so NOT_FOUND at SACCUSSALIS is consistent, not a mystery. No reconciliation action needed for this one - it correctly failed and (per the code) any hold it did place should already have been released via the normal failure-handling path.</div>
    <?php else: ?>
    <div class="verdict unclear">⚠️ This leg is recorded as "<?php echo h($legResult['status'] ?? 'unknown'); ?>" in VOUCHMORPH's own records - NOT failed. That means VOUCHMORPH believes this leg succeeded, but no hold exists for it at SACCUSSALIS. This is worth investigating directly - possibly a case where the hold placement itself hit the earlier duplicate-reference bug and silently didn't create a row, while downstream code still marked it as processed.</div>
    <?php endif; ?>
    <pre><?php echo h(json_encode($legResult, JSON_PRETTY_PRINT)); ?></pre>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

</body>
</html>
