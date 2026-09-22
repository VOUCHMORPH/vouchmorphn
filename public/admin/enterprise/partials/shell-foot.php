<?php
/**
 * partials/shell-foot.php — closes the .stage/.stage-outer opened by
 * shell-head.php and prints the FIXED footer. This footer never
 * scrolls off screen: it is position:fixed in shell.css, and
 * .stage-outer already reserves --footer-h of bottom padding so the
 * last row of real content is never hidden underneath it.
 *
 * Optional variable the including page may set before requiring this:
 *   $footerNote — short plain text shown on the left of the footer
 *   bar (e.g. "3 of 6 setup steps complete"). Left unset, only the
 *   standard org / role / time line renders.
 */
?>
    </div><!-- /.stage -->
</div><!-- /.stage-outer -->
    </div><!-- /.main-col -->
</div><!-- /.app-shell -->

<footer class="ftr">
    <div class="ftr-inner">
        <div class="ftr-status"><span class="chip"></span><?php echo safeHtml($footerNote ?? 'VouchMorph Enterprise'); ?></div>
        <div class="ftr-right"><?php echo safeHtml($orgName); ?> &middot; <?php echo safeHtml(getRoleLabel($userRole)); ?> &middot; <?php echo safeHtml($fullName); ?> &middot; <span title="<?php echo safeHtml(date('Y-m-d H:i:s') . ' ' . date('T')); ?>"><?php echo date('H:i'); ?> <?php echo date('T'); ?></span></div>
    </div>
</footer>
<script>
(function () {
    var btn = document.getElementById('themeToggleBtn');
    var icon = document.getElementById('themeToggleIcon');
    if (!btn || !icon) return;
    var moonSvg = icon.innerHTML;
    var sunSvg = <?php echo json_encode(svgIcon('sun')); ?>;
    function syncThemeBtnVisibility() {
        // The light/dark toggle only means something on Control
        // Room — the three intensity skins are dark by identity,
        // and letting the toggle fight that would just produce a
        // washed-out warroom/alpha/gala rather than a real light
        // mode for them (they don't have one).
        var skin = document.documentElement.getAttribute('data-skin');
        btn.style.display = (!skin || skin === 'control-room') ? '' : 'none';
    }
    function sync() {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        icon.innerHTML = dark ? sunSvg : moonSvg;
        syncThemeBtnVisibility();
    }
    btn.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('vm_theme', next);
        sync();
    });
    sync();
})();
(function () {
    var picker = document.getElementById('skinPicker');
    if (!picker) return;
    function syncChips() {
        var current = document.documentElement.getAttribute('data-skin') || 'control-room';
        picker.querySelectorAll('.skin-chip').forEach(function (chip) {
            chip.classList.toggle('active', chip.dataset.chip === current);
        });
        var themeBtn = document.getElementById('themeToggleBtn');
        if (themeBtn) themeBtn.style.display = (current === 'control-room') ? '' : 'none';
    }
    window.setSkin = function (name) {
        if (name === 'control-room') { document.documentElement.removeAttribute('data-skin'); }
        else { document.documentElement.setAttribute('data-skin', name); }
        localStorage.setItem('vm_skin', name);
        syncChips();
    };
    syncChips();
})();
(function () {
    // Sidebar collapse — persisted, same mechanism as the theme/skin
    // choices above. Collapsed width still shows icons (title=""
    // tooltips carry the label), never hides navigation entirely.
    var sideNav = document.getElementById('sideNav');
    var btn = document.getElementById('sideNavCollapseBtn');
    var label = document.getElementById('collapseBtnLabel');
    if (!sideNav || !btn) return;
    function apply(collapsed) {
        sideNav.classList.toggle('collapsed', collapsed);
        if (label) label.innerHTML = collapsed ? '&rarr;' : '&larr; Collapse';
    }
    apply(localStorage.getItem('vm_sidebar_collapsed') === '1');
    btn.addEventListener('click', function () {
        var next = !sideNav.classList.contains('collapsed');
        apply(next);
        localStorage.setItem('vm_sidebar_collapsed', next ? '1' : '0');
    });
    // Mobile: hamburger opens/closes the off-canvas sidebar instead.
    var mobileBtn = document.getElementById('mobileNavBtn');
    if (mobileBtn) {
        if (window.matchMedia('(max-width: 760px)').matches) mobileBtn.style.display = '';
        mobileBtn.addEventListener('click', function () { sideNav.classList.toggle('mobile-open'); });
    }
})();
(function () {
    // Notification popout — one at a time, click-outside closes it.
    // This is the "locks and can be hidden with a button" behavior
    // from the reference: opening it doesn't navigate anywhere, it
    // just answers "what needs me right now" without leaving the page.
    var popout = document.getElementById('notifPopout');
    if (!popout) return;
    window.toggleNotifPopout = function (force) {
        var open = typeof force === 'boolean' ? force : !popout.classList.contains('open');
        popout.classList.toggle('open', open);
    };
    document.addEventListener('click', function (e) {
        if (!popout.classList.contains('open')) return;
        if (popout.contains(e.target) || e.target.closest('#notifBellBtn')) return;
        popout.classList.remove('open');
    });
})();
</script>
<!-- Role manual: one press away on every page, for every role, forever. -->
<a href="<?php echo htmlspecialchars(($basePath ?? '') . 'manual.php', ENT_QUOTES); ?>" target="_blank" rel="noopener"
   style="position:fixed;right:24px;bottom:calc(var(--footer-h,40px) + 16px);z-index:50;font-family:var(--f-display);font-weight:600;
          font-size:14px;letter-spacing:.04em;padding:12px 18px;border:var(--border,2px) solid var(--line);background:var(--paper);
          color:var(--ink);text-decoration:none" aria-label="Open my role manual">My manual</a>
