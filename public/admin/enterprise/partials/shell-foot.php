<?php
/**
 * partials/shell-foot.php — closes out partials/shell-head.php's open
 * <main class="content">, renders the footer, and wires up the
 * sidebar's interactive behavior (desktop collapse, mobile drawer,
 * dark-mode toggle). Every page that requires shell-head.php must
 * require this at the end of its body.
 *
 * Optional variable the including page may set before requiring this:
 *   $footerStatusLine — extra HTML echoed on its own line above the
 *   standard org/role line, for real per-page status (e.g. a DB health
 *   check). Left unset, only the standard line renders.
 */
?>
        </main>

        <footer class="footer">
            <div class="footer-status">
                <span><?php echo $footerStatusLine ?? ''; ?></span>
                <span>VOUCHMORPH · <?php echo safeHtml($orgName); ?> · Role: <?php echo safeHtml(getRoleLabel($userRole)); ?> · <?php echo date('Y-m-d H:i:s'); ?> <?php echo date('T'); ?></span>
            </div>
        </footer>
    </div>

    <script>
        (function () {
            var body = document.body;
            var collapseBtn = document.getElementById('collapseBtn');
            collapseBtn.addEventListener('click', function () {
                body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('vm_sidebar_collapsed', body.classList.contains('sidebar-collapsed') ? '1' : '0');
            });

            // Mobile drawer: separate from the desktop collapsed/expanded
            // state above — below the 860px breakpoint the sidebar is an
            // off-canvas panel, closed by default, opened by the
            // hamburger button or closed by tapping the backdrop.
            var mobileMenuBtn = document.getElementById('mobileMenuBtn');
            var sidebarBackdrop = document.getElementById('sidebarBackdrop');
            function closeMobileMenu() { body.classList.remove('sidebar-mobile-open'); }
            mobileMenuBtn.addEventListener('click', function () { body.classList.toggle('sidebar-mobile-open'); });
            sidebarBackdrop.addEventListener('click', closeMobileMenu);
            document.querySelectorAll('.sidebar .nav-link, .sidebar .btn-create').forEach(function (el) {
                el.addEventListener('click', closeMobileMenu);
            });

            var themeBtn = document.getElementById('themeBtn');
            var themeIcon = document.getElementById('themeIcon');
            var sunSvg = <?php echo json_encode(svgIcon('sun')); ?>;
            var moonSvg = <?php echo json_encode(svgIcon('moon')); ?>;
            function syncThemeIcon() {
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark'
                    || (!document.documentElement.hasAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
                themeIcon.innerHTML = isDark ? sunSvg : moonSvg;
            }
            themeBtn.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-theme');
                var isDark = current === 'dark' || (!current && window.matchMedia('(prefers-color-scheme: dark)').matches);
                var next = isDark ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('vm_theme', next);
                syncThemeIcon();
            });
            syncThemeIcon();
        })();
    </script>

