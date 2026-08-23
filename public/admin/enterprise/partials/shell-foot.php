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
        <div class="ftr-status"><span class="chip"></span><?php echo safeHtml($footerNote ?? 'VOUCHMORPH ENTERPRISE'); ?></div>
        <div class="ftr-right"><?php echo safeHtml($orgName); ?> &middot; <?php echo safeHtml(getRoleLabel($userRole)); ?> &middot; <?php echo safeHtml($fullName); ?> &middot; <span title="<?php echo safeHtml(date('Y-m-d H:i:s') . ' ' . date('T')); ?>"><?php echo date('H:i'); ?> <?php echo date('T'); ?></span></div>
    </div>
</footer>
