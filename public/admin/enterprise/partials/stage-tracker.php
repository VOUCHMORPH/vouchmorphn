<?php
/**
 * partials/stage-tracker.php — shared 5-stage progress indicator for the
 * Create Batch workflow (source_input.php, add_destinations.php,
 * review_batch.php). Required inside <main class="content">, right after
 * the including page's own .page-header.
 *
 * Set exactly ONE of the following before requiring this file:
 *   $stageTrackerStage  (int, 1-5) — explicit override, for pages that
 *                        are structurally a fixed stage regardless of
 *                        batch status: source_input.php = 1,
 *                        add_destinations.php = 2.
 *   $stageTrackerStatus (string)   — a raw disbursement_batches.status
 *                        value (e.g. $batch['status']). Mapped below via
 *                        stageTrackerStageFromStatus(). Used only by
 *                        review_batch.php, which spans stages 3-5 on one
 *                        URL depending on where the batch is in its
 *                        lifecycle.
 * If neither is set, defaults to stage 1.
 *
 * Requires svgIcon() and safeHtml() to already be defined (guaranteed by
 * partials/shell-head.php, which every including page requires first).
 */

if (!function_exists('stageTrackerStageFromStatus')) {
    function stageTrackerStageFromStatus(string $status): array {
        $status = strtolower(trim($status));
        return match ($status) {
            'draft' => ['active' => 3, 'completed_through' => 2],
            'pending_approval', 'pending' => ['active' => 4, 'completed_through' => 3],
            'rejected' => ['active' => 4, 'completed_through' => 3, 'error' => true],
            'approved', 'executing', 'partially_completed', 'partial_success' => ['active' => 5, 'completed_through' => 4],
            'completed', 'executed' => ['active' => 5, 'completed_through' => 5],
            default => ['active' => 3, 'completed_through' => 2],
        };
    }
}

$stageTrackerLabels = [1 => 'Source Selection', 2 => 'Recipients', 3 => 'Validation', 4 => 'Authorization', 5 => 'Execution'];

if (isset($stageTrackerStage)) {
    $stageTrackerActive = max(1, min(5, (int)$stageTrackerStage));
    $stageTrackerCompletedThrough = $stageTrackerActive - 1;
    $stageTrackerHasError = false;
} else {
    $stageTrackerResolved = stageTrackerStageFromStatus($stageTrackerStatus ?? 'draft');
    $stageTrackerActive = $stageTrackerResolved['active'];
    $stageTrackerCompletedThrough = $stageTrackerResolved['completed_through'];
    $stageTrackerHasError = $stageTrackerResolved['error'] ?? false;
}
?>
<nav class="stage-tracker" aria-label="Batch creation progress">
    <?php foreach ($stageTrackerLabels as $stageTrackerNum => $stageTrackerLabel): ?>
    <?php
        if ($stageTrackerNum <= $stageTrackerCompletedThrough) {
            $stageTrackerState = 'is-complete';
        } elseif ($stageTrackerNum === $stageTrackerActive) {
            $stageTrackerState = $stageTrackerHasError ? 'is-active is-error' : 'is-active';
        } else {
            $stageTrackerState = 'is-upcoming';
        }
    ?>
    <div class="stage-tracker-item <?php echo $stageTrackerState; ?>">
        <div class="stage-tracker-marker">
            <?php if ($stageTrackerNum <= $stageTrackerCompletedThrough): ?>
            <?php echo svgIcon('check'); ?>
            <?php else: ?>
            <span class="stage-tracker-num"><?php echo $stageTrackerNum; ?></span>
            <?php endif; ?>
        </div>
        <div class="stage-tracker-label"><?php echo safeHtml($stageTrackerLabel); ?></div>
    </div>
    <?php if ($stageTrackerNum < 5): ?>
    <div class="stage-tracker-connector<?php echo $stageTrackerNum <= $stageTrackerCompletedThrough ? ' is-filled' : ''; ?>"></div>
    <?php endif; ?>
    <?php endforeach; ?>
</nav>
