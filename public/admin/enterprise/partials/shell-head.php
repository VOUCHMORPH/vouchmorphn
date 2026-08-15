<?php
/**
 * partials/shell-head.php — the shared sidebar + topbar every
 * redesigned enterprise page includes, instead of hand-rolling its own
 * copy. This is what actually guarantees nav consistency across pages
 * (not just visual similarity): every page renders the exact same PHP,
 * so it can't independently drift the way the old per-page nav did.
 *
 * Contract — the including page must, before requiring this file:
 *   - already have `safeHtml()` and `getRoleLabel()` defined
 *   - already have `svgIcon()` defined UNLESS it requires this file
 *     before its own icon set — this file defines svgIcon() itself
 *     (guarded) so most pages don't need their own copy at all
 *   - set: $fullName, $orgName, $userRole, $navItems, $navUtility,
 *     $canCreate, $setupReady
 *   - optionally set: $topbarSearchShow (bool, default true),
 *     $topbarSearchAction, $topbarSearchName, $topbarSearchPlaceholder,
 *     $topbarSearchValue, $attentionHref (default '#attention'),
 *     $attentionActive (bool, shows the red dot on the bell)
 *
 * Ends with <main class="content"> left OPEN — the including page
 * writes its own content, then requires partials/shell-foot.php to
 * close it out.
 */

if (!function_exists('svgIcon')) {
    /**
     * Shared inline stroke-icon set, 20x20, currentColor. Kept in one
     * place so the sidebar, topbar, and any page-level card headers all
     * draw from the same set rather than each hand-rolling their own SVG.
     */
    function svgIcon(string $name): string {
        $icons = [
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'wallet' => '<rect x="3" y="6" width="18" height="13" rx="1.5"/><path d="M3 10h18"/><circle cx="16.5" cy="14" r="1"/>',
            'people' => '<circle cx="8.5" cy="8" r="3.2"/><path d="M2.5 19c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="8.5" r="2.6"/><path d="M15.2 13.6c2.6.3 4.3 2.3 4.3 5.4"/>',
            'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.8-4.8"/>',
            'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 8h1M8 12h1M8 16h1M15 8h1M15 12h1M15 16h1M9 21v-4h6v4"/>',
            'bank' => '<path d="M3 9l9-5 9 5"/><rect x="4" y="9" width="16" height="10" rx="0.5"/><path d="M2 21h20M6 9v10M11 9v10M16 9v10"/>',
            'idcard' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M6 17c0-2 1.4-3.2 3-3.2s3 1.2 3 3.2M14 9h5M14 13h5"/>',
            'chart' => '<path d="M4 20V4M4 20h16"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="14" width="3" height="4"/>',
            'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2.2M12 18.8V21M4.9 4.9l1.6 1.6M17.5 17.5l1.6 1.6M3 12h2.2M18.8 12H21M4.9 19.1l1.6-1.6M17.5 6.5l1.6-1.6"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
            'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 4.5 1.5 6 1.5 6h-15S6 13.5 6 9Z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
            'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.3 9a2.7 2.7 0 1 1 3.9 2.4c-.9.5-1.2 1-1.2 2"/><path d="M12 17h.01"/>',
            'moon' => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5Z"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/>',
            'chevron' => '<path d="M9 6l6 6-6 6"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'warning' => '<path d="M12 3l10 18H2Z"/><path d="M12 10v4M12 17h.01"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
            'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'lock' => '<rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 19h16"/>',
            'filter' => '<path d="M4 5h16M7 12h10M10 19h4"/>',
            'check' => '<path d="M4 12l5 5L20 6"/>',
        ];
        $path = $icons[$name] ?? $icons['grid'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}

// $basePath: how to reach the enterprise root from wherever the
// including page lives — '' for pages at that root (index.php,
// beneficiaries.php, ...), '../' for one level down (departments/,
// batches/, settings/, imports/...). Every relative link in this
// partial that isn't already supplied pre-built (like $navItems'
// hrefs, which each page constructs itself) is built from this.
$basePath = $basePath ?? '';
$topbarSearchShow = $topbarSearchShow ?? true;
$topbarSearchAction = $topbarSearchAction ?? ($basePath . 'index.php');
$topbarSearchName = $topbarSearchName ?? 'trace';
$topbarSearchPlaceholder = $topbarSearchPlaceholder ?? 'Search…';
$topbarSearchValue = $topbarSearchValue ?? '';
$attentionHref = $attentionHref ?? ($basePath . 'index.php#attention');
$attentionActive = $attentionActive ?? false;
?>
    <script>
        // Runs before paint so the sidebar never "flashes" open then
        // collapses, and the theme never flashes light-then-dark.
        (function () {
            if (localStorage.getItem('vm_sidebar_collapsed') === '1') {
                document.body ? document.body.classList.add('sidebar-collapsed') : null;
            }
            var theme = localStorage.getItem('vm_theme');
            if (theme === 'dark' || theme === 'light') {
                document.documentElement.setAttribute('data-theme', theme);
            }
        })();
    </script>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ============================================================ -->
    <!-- SIDEBAR — see partials/shell.css for styling -->
    <!-- ============================================================ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <div class="brand">
                <div class="brand-mark"><?php echo svgIcon('bank'); ?></div>
                <div class="brand-text">
                    <div class="brand-name">VOUCHMORPH</div>
                    <div class="brand-org"><?php echo safeHtml($orgName); ?></div>
                </div>
            </div>
            <button type="button" class="collapse-btn" id="collapseBtn" title="Collapse sidebar" aria-label="Collapse sidebar">
                <?php echo svgIcon('chevron'); ?>
            </button>
        </div>

        <div class="create-batch-wrap">
            <?php if ($canCreate && $setupReady): ?>
            <a href="<?php echo safeHtml($basePath . 'imports/source_input.php'); ?>" class="btn-create"><?php echo svgIcon('plus'); ?><span class="label">Create Batch</span></a>
            <?php elseif ($canCreate): ?>
            <span class="btn-create locked" title="Your Owner needs to finish setup first"><?php echo svgIcon('lock'); ?><span class="label">Create Batch</span></span>
            <?php endif; ?>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($navItems as $item): if (empty($item['show'])) continue; ?>
            <a href="<?php echo safeHtml($item['href']); ?>" class="nav-link<?php echo !empty($item['active']) ? ' active' : ''; ?>" title="<?php echo safeHtml($item['label']); ?>">
                <?php echo svgIcon($item['icon']); ?>
                <span class="nav-label"><?php echo safeHtml($item['label']); ?></span>
                <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?php echo (int)$item['badge']; ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <?php foreach ($navUtility as $item): if (empty($item['show'])) continue; ?>
            <a href="<?php echo safeHtml($item['href']); ?>" class="nav-link" title="<?php echo safeHtml($item['label']); ?>">
                <?php echo svgIcon($item['icon']); ?>
                <span class="nav-label"><?php echo safeHtml($item['label']); ?></span>
            </a>
            <?php endforeach; ?>

            <div class="sidebar-toggle-row">
                <button type="button" class="theme-btn" id="themeBtn" title="Toggle dark mode">
                    <span class="theme-icon" id="themeIcon"><?php echo svgIcon('moon'); ?></span>
                    <span class="label">Dark Mode</span>
                </button>
            </div>

            <div class="user-chip" title="<?php echo safeHtml($fullName); ?>">
                <div class="user-avatar"><?php echo safeHtml(strtoupper(substr($fullName, 0, 1))); ?></div>
                <div class="user-meta">
                    <div class="name"><?php echo safeHtml($fullName); ?></div>
                    <div class="role"><?php echo safeHtml(getRoleLabel($userRole)); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- ============================================================ -->
    <!-- MAIN -->
    <!-- ============================================================ -->
    <div class="main">
        <div class="topbar">
            <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu"><?php echo svgIcon('grid'); ?></button>
            <?php if ($topbarSearchShow): ?>
            <div class="topbar-search">
                <form method="get" action="<?php echo safeHtml($topbarSearchAction); ?>">
                    <?php echo svgIcon('search'); ?>
                    <input type="text" name="<?php echo safeHtml($topbarSearchName); ?>" placeholder="<?php echo safeHtml($topbarSearchPlaceholder); ?>" value="<?php echo safeHtml($topbarSearchValue); ?>">
                </form>
            </div>
            <?php endif; ?>
            <div class="topbar-icons">
                <a href="<?php echo safeHtml($attentionHref); ?>" class="icon-btn" title="Needs your attention">
                    <?php echo svgIcon('bell'); ?>
                    <?php if ($attentionActive): ?><span class="dot"></span><?php endif; ?>
                </a>
                <a href="<?php echo safeHtml($basePath . 'settings.php'); ?>" class="topbar-avatar" title="<?php echo safeHtml($fullName); ?>"><?php echo safeHtml(strtoupper(substr($fullName, 0, 1))); ?></a>
            </div>
        </div>

        <main class="content">
