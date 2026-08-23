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
</script>
