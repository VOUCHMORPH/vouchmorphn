<?php
/**
 * partials/shell-head.php — CENTER-STAGE header + persistent sidebar.
 *
 * Reversal from the previous version, done in the open: this file
 * used to render NO sidebar at all — navigation lived entirely in
 * hub tiles. That broke down for anything list-shaped (Batches,
 * Attention), so the sidebar is back, structured after the LeadHive
 * reference: icon + label nav on the left, collapsible, with a
 * dedicated collapse control pinned at the bottom. Every page that
 * requires this file now gets the SAME sidebar from the SAME array —
 * that shared-source-of-truth is the actual fix for "Departments
 * looks like a different product than the hub."
 *
 * Contract — before requiring this file, the including page must:
 *   - define safeHtml() and getRoleLabel()
 *   - set: $fullName, $orgName, $userRole
 *   - set: $basePath — '' at the enterprise root, '../' one level down
 *   - set: $navItems — array of ['key','icon','label','href','show','badge']
 *           in display order. 'show' is a bool the page computes from
 *           its own already-established $canView*/$can* capability
 *           flags — this file does zero permission logic itself.
 *   - set: $currentNavKey — which $navItems['key'] is "this page",
 *           so the right sidebar row highlights. No key = nothing
 *           highlights (fine for pages not in the nav, like a 403).
 *   - optionally set:
 *       $backHref   (string|null) — an extra "back" pill in the header,
 *                   for drill-down pages the sidebar doesn't cover.
 *       $backLabel  (string) — text next to the arrow, default 'Home'
 *       $attentionHref   (default $basePath.'index.php#attention')
 *       $attentionActive (bool) — shows the sky dot on the bell
 *       $notificationItems (array) — rows for the bell's popout panel,
 *                   each ['label','count','href']. Empty/unset = the
 *                   popout shows "All clear."
 *
 * Ends with <div class="stage-outer"><div class="stage"> left OPEN —
 * the including page writes its stage-view(s), then requires
 * partials/shell-foot.php to close stage/stage-outer/main-col/app-shell
 * and print the fixed footer.
 */

if (!function_exists('svgIcon')) {
    function svgIcon(string $name): string {
        $icons = [
            'mark' => '<path d="M12 2l9 5v10l-9 5-9-5V7z"/><path d="M12 8v8M8.5 10l3.5 2 3.5-2"/>',
            'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 4.5 1.5 6 1.5 6h-15S6 13.5 6 9Z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
            'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
            'moon' => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5Z"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/>',
            'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.8-4.8"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
            'layers' => '<rect x="4" y="7" width="16" height="11"/><path d="M4 11h16"/>',
            'history' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
            'sitemap' => '<rect x="9" y="3" width="6" height="5"/><rect x="3" y="16" width="6" height="5"/><rect x="15" y="16" width="6" height="5"/><path d="M12 8v4M6 16v-4h12v4"/>',
            'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="8.5" r="2.6"/><path d="M15.2 15c2.6.3 4.3 2.3 4.3 5"/>',
            'bank' => '<path d="M3 9l9-5 9 5"/><rect x="4" y="9" width="16" height="10"/><path d="M2 21h20M6 9v10M11 9v10M16 9v10"/>',
            'shield' => '<path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/>',
            'file' => '<path d="M6 3h9l5 5v13H6z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
            'chevron-left' => '<path d="M15 6l-6 6 6 6"/>',
            'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
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
$navItems = $navItems ?? [];
$currentNavKey = $currentNavKey ?? null;
$notificationItems = $notificationItems ?? [];
// $canTrace is optional — only index.php's Trace stage needs the
// header shortcut; every other page simply omits it.
$showTraceShortcut = !empty($canTrace);
?>
<script>
(function () {
    var t = localStorage.getItem('vm_theme'); if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    var s = localStorage.getItem('vm_skin'); if (s && s !== 'control-room') document.documentElement.setAttribute('data-skin', s);
})();
</script>
<div class="app-shell">
    <aside class="side-nav" id="sideNav">
        <a href="<?php echo safeHtml($basePath . 'index.php'); ?>" class="side-nav-brand">
            <div class="hdr-mark" style="width:32px;height:32px;flex-shrink:0;"><?php echo svgIcon('mark'); ?></div>
            <span class="label" style="font-family:var(--f-display);font-weight:700;font-size:14px;letter-spacing:.02em;">VOUCHMORPH</span>
        </a>
        <nav class="side-nav-list">
            <?php foreach ($navItems as $item): if (empty($item['show'])) continue; $active = ($currentNavKey !== null && $item['key'] === $currentNavKey); ?>
            <a href="<?php echo safeHtml($item['href']); ?>" class="side-nav-item<?php echo $active ? ' active' : ''; ?>" title="<?php echo safeHtml($item['label']); ?>">
                <?php echo svgIcon($item['icon']); ?>
                <span class="label"><?php echo safeHtml($item['label']); ?></span>
                <?php if (!empty($item['badge'])): ?><span class="badge-count"><?php echo (int)$item['badge']; ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="side-nav-foot">
            <button type="button" class="side-nav-collapse-btn" id="sideNavCollapseBtn"><span id="collapseBtnLabel">&larr; Collapse</span></button>
        </div>
    </aside>

    <div class="main-col">
        <header class="hdr">
            <div class="hdr-inner">
                <div class="hdr-left">
                    <button type="button" class="hdr-icon-btn" id="mobileNavBtn" style="display:none;" title="Menu"><?php echo svgIcon('menu'); ?></button>
                    <?php if ($backHref): ?>
                    <a href="<?php echo safeHtml($backHref); ?>" class="hdr-back show"><?php echo svgIcon('arrow-left'); ?> <?php echo safeHtml($backLabel); ?></a>
                    <?php endif; ?>
                    <div class="hdr-org">
                        <div class="hdr-org-name"><?php echo safeHtml($orgName); ?></div>
                        <div class="hdr-org-sub">Enterprise console</div>
                    </div>
                </div>
                <div class="hdr-right" style="position: relative;">
                    <?php if ($showTraceShortcut): ?>
                    <a href="<?php echo safeHtml($basePath . 'index.php#stage-trace'); ?>" class="hdr-icon-btn" title="Trace a payment"><?php echo svgIcon('search'); ?></a>
                    <?php endif; ?>
                    <div class="skin-picker" id="skinPicker">
                        <button type="button" class="skin-chip active" data-chip="control-room" title="Control Room (default)" onclick="setSkin('control-room')"></button>
                        <button type="button" class="skin-chip" data-chip="warroom" title="War Room" onclick="setSkin('warroom')"></button>
                        <button type="button" class="skin-chip" data-chip="alpha" title="Alpha" onclick="setSkin('alpha')"></button>
                        <button type="button" class="skin-chip" data-chip="gala" title="Gala" onclick="setSkin('gala')"></button>
                    </div>
                    <button type="button" class="hdr-icon-btn" id="themeToggleBtn" title="Toggle dark mode"><span id="themeToggleIcon"><?php echo svgIcon('moon'); ?></span></button>
                    <button type="button" class="hdr-icon-btn" id="notifBellBtn" title="Needs your attention" onclick="toggleNotifPopout()">
                        <?php echo svgIcon('bell'); ?>
                        <?php if ($attentionActive): ?><span class="dot">!</span><?php endif; ?>
                    </button>
                    <a href="<?php echo safeHtml($basePath . 'settings.php'); ?>" class="hdr-avatar" title="<?php echo safeHtml($fullName . ' — ' . getRoleLabel($userRole)); ?>"><?php echo safeHtml(strtoupper(substr($fullName, 0, 1))); ?></a>
                    <a href="<?php echo safeHtml($basePath . 'logout.php'); ?>" class="hdr-icon-btn" title="Log out"><?php echo svgIcon('logout'); ?></a>

                    <!-- Notification popout — the reference's "small pop-up
                         that locks and can be hidden with a button." Closed
                         by default; toggled by the bell, never both a link
                         AND a panel doing the same job. -->
                    <div class="popout" id="notifPopout">
                        <div class="popout-head">
                            <span>Needs Attention</span>
                            <button type="button" class="popout-close" onclick="toggleNotifPopout(false)">&times;</button>
                        </div>
                        <div class="popout-body">
                            <?php if (empty($notificationItems)): ?>
                            <div class="popout-empty">All clear — nothing needs your attention.</div>
                            <?php else: foreach ($notificationItems as $n): ?>
                            <div class="popout-row"><span><?php echo safeHtml($n['label']); ?></span><span class="badge-count" style="background:var(--ink);color:var(--sky);padding:1px 7px;font-family:var(--f-mono);font-size:10px;"><?php echo (int)$n['count']; ?></span></div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div class="popout-foot"><a href="<?php echo safeHtml($attentionHref); ?>" class="btn-quiet" style="font-family:var(--f-mono);font-size:11px;text-transform:uppercase;">Open full docket &rsaquo;</a></div>
                    </div>
                </div>
            </div>
        </header>

        <div class="stage-outer">
            <div class="stage">
