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
        <!-- FIX: the footer used to render a full "Y-m-d H:i:s TZ"
             timestamp plus org name plus role — ~96 characters — inside
             a tall dark bar with plenty of room. That footer is now a
             compact 22px VS Code-style status strip, and nobody checked
             whether the content still fit it. It does on desktop, but
             wraps on mobile with nowhere to go (fixed height, no
             overflow rule). Two changes: seconds/timezone moved into a
             title tooltip instead of always-visible text (H:i instead
             of H:i:s + T), and the role segment is wrapped in its own
             span so shell.css can hide it below 600px, keeping the org
             name and time visible as the two things worth keeping on a
             phone screen. -->
        <footer class="footer">
            <div class="footer-status">
                <span><?php echo $footerStatusLine ?? ''; ?></span>
                <span class="footer-meta" title="<?php echo safeHtml(date('Y-m-d H:i:s') . ' ' . date('T')); ?>">
                    VOUCHMORPH · <?php echo safeHtml($orgName); ?> ·
                    <span class="footer-meta-role">Role: <?php echo safeHtml(getRoleLabel($userRole)); ?> ·</span>
                    <?php echo date('H:i'); ?> <?php echo date('T'); ?>
                </span>
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
