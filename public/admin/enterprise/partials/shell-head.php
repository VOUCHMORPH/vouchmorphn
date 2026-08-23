<?php
/**
 * partials/shell-head.php — CENTER-STAGE header.
 *
 * There is no sidebar. There is one fixed strip at the top of every
 * page (brand + a single "back" action + bell + avatar) and one fixed
 * strip at the bottom (partials/shell-foot.php). Everything else on
 * the page is "the stage" — see .stage / .stage-view in shell.css.
 *
 * Contract — before requiring this file, the including page must:
 *   - define safeHtml() and getRoleLabel()
 *   - set: $fullName, $orgName, $userRole
 *   - set: $basePath — '' at the enterprise root, '../' one level down
 *   - optionally set:
 *       $backHref   (string|null) — where the back button goes. Leave
 *                   unset/null on the hub (index.php) to hide it.
 *       $backLabel  (string) — text next to the arrow, default 'Home'
 *       $attentionHref   (default $basePath.'index.php#attention')
 *       $attentionActive (bool) — shows the sky dot on the bell
 *
 * Ends with <div class="stage-outer"><div class="stage"> left OPEN —
 * the including page writes its stage-view(s), then requires
 * partials/shell-foot.php to close both and print the fixed footer.
 */

if (!function_exists('svgIcon')) {
    function svgIcon(string $name): string {
        $icons = [
            'mark' => '<path d="M12 2l9 5v10l-9 5-9-5V7z"/><path d="M12 8v8M8.5 10l3.5 2 3.5-2"/>',
            'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 4.5 1.5 6 1.5 6h-15S6 13.5 6 9Z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
            'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        ];
        $path = $icons[$name] ?? $icons['mark'];
        return '<svg class="i" viewBox="0 0 24 24">' . $path . '</svg>';
    }
}

$basePath = $basePath ?? '';
$backHref = $backHref ?? null;
$backLabel = $backLabel ?? 'Home';
$attentionHref = $attentionHref ?? ($basePath . 'index.php#attention');
$attentionActive = $attentionActive ?? false;
?>
<header class="hdr">
    <div class="hdr-inner">
        <div class="hdr-left">
            <?php if ($backHref): ?>
            <a href="<?php echo safeHtml($backHref); ?>" class="hdr-back show"><?php echo svgIcon('arrow-left'); ?> <?php echo safeHtml($backLabel); ?></a>
            <?php endif; ?>
            <a href="<?php echo safeHtml($basePath . 'index.php'); ?>" style="display:flex;align-items:center;gap:var(--u2);text-decoration:none;color:inherit;min-width:0;">
                <div class="hdr-mark"><?php echo svgIcon('mark'); ?></div>
                <div class="hdr-org">
                    <div class="hdr-org-name">VOUCHMORPH</div>
                    <div class="hdr-org-sub"><?php echo safeHtml($orgName); ?></div>
                </div>
            </a>
        </div>
        <div class="hdr-right">
            <a href="<?php echo safeHtml($attentionHref); ?>" class="hdr-icon-btn" title="Needs your attention">
                <?php echo svgIcon('bell'); ?>
                <?php if ($attentionActive): ?><span class="dot">!</span><?php endif; ?>
            </a>
            <a href="<?php echo safeHtml($basePath . 'settings.php'); ?>" class="hdr-avatar" title="<?php echo safeHtml($fullName . ' — ' . getRoleLabel($userRole)); ?>"><?php echo safeHtml(strtoupper(substr($fullName, 0, 1))); ?></a>
            <a href="<?php echo safeHtml($basePath . 'logout.php'); ?>" class="hdr-icon-btn" title="Log out"><?php echo svgIcon('logout'); ?></a>
        </div>
    </div>
</header>

<div class="stage-outer">
    <div class="stage">
