<?php
/**
 * public/admin/signing_inbox.php
 * Reports waiting for this admin's signature. Open one, read it, tick the
 * attestation, enter the MFA code and press Sign — it moves to the next
 * person automatically. Or return it with a reason.
 */
declare(strict_types=1);
require __DIR__ . '/_signing_bootstrap.php';

use Application\Reporting\SigningWorkflow;

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vm_check_csrf();
    $rid = (int)($_POST['report_id'] ?? 0);
    try {
        if (($_POST['action'] ?? '') === 'sign') {
            $res = $workflow->sign($rid, $adminId, trim($_POST['mfa_code'] ?? ''), !empty($_POST['attest']), $_SERVER['REMOTE_ADDR'] ?? null);
            vm_audit('REPORT_SIGNED', ['report_id' => $rid] + $res);
            $flash = ['ok', $res['next_step']
                ? 'Signed. Sent to the ' . $workflow->label($res['next_role']) . ' for the ' . $res['next_step'] . ' step.'
                : 'Signed. All signatures complete — the report has been issued and is now visible to the Bank of Botswana.'];
        } elseif (($_POST['action'] ?? '') === 'return') {
            $workflow->returnForCorrection($rid, $adminId, trim($_POST['reason'] ?? ''));
            vm_audit('REPORT_RETURNED', ['report_id' => $rid]);
            $flash = ['ok', 'Returned to the preparer with your reason. A corrected version will come back to you.'];
        }
    } catch (Throwable $e) {
        vm_audit('REPORT_SIGN_FAILED', ['report_id' => $rid, 'reason' => $e->getMessage()]);
        $flash = ['err', $e->getMessage()];
    }
}

$inbox = $db->prepare('SELECT * FROM v_signing_inbox WHERE admin_id = :a ORDER BY overdue DESC, due_at NULLS LAST, generated_at');
$inbox->execute([':a' => $adminId]);
$items = $inbox->fetchAll(PDO::FETCH_ASSOC);

$open = null;
$trail = [];
if (!empty($_GET['id'])) {
    $candidate = (int)$_GET['id'];
    foreach ($items as $it) {
        if ((int)$it['report_id'] === $candidate) {
            $open = $it;
        }
    }
    if ($open) {
        $t = $db->prepare('SELECT s.step, s.signed_at, s.signer_role, a.full_name FROM report_signatures s
                             JOIN admins a ON a.admin_id = s.signer_admin_id WHERE s.report_id = :r ORDER BY s.signed_at');
        $t->execute([':r' => $candidate]);
        $trail = $t->fetchAll(PDO::FETCH_ASSOC);
    }
}

$hasSignature = $specimens->active($adminId) !== null;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Signing inbox · VouchMorph</title>
<style><?= VM_PAGE_CSS ?>
.grid{display:grid;grid-template-columns:1fr 1.6fr;gap:16px}
iframe{width:100%;height:640px;border:1px solid #d6dde6;border-radius:4px;background:#fff}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
</style></head><body><div class="wrap">
<?= vm_nav() ?>
<h1>Signing inbox</h1>
<p class="sub">Reports waiting for your signature. Nothing reaches the Bank until every required person has signed.</p>

<?php if ($flash): ?><div class="msg <?= $flash[0] ?>"><?= h($flash[1]) ?></div><?php endif; ?>
<?php if (!$hasSignature): ?><div class="msg err">Set up your signature under <a href="my_signature.php">My signature</a> before you can sign.</div><?php endif; ?>

<div class="grid">
  <div class="card">
    <?php if (!$items): ?><p>Nothing is waiting for you.</p>
    <?php else: ?>
    <table><tr><th>Report</th><th>Step</th><th>Due</th></tr>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><a href="?id=<?= (int)$it['report_id'] ?>"><?= h(str_replace('_', ' ', $it['report_type'])) ?></a><br>
            <small>v<?= (int)$it['version'] ?> · <?= h($it['period_end'] ?? '') ?></small></td>
        <td><span class="pill"><?= h($it['current_step']) ?></span></td>
        <td><?= $it['due_at'] ? '<span class="pill ' . ($it['overdue'] ? 'late' : '') . '">' . h(date('d M H:i', strtotime($it['due_at']))) . '</span>' : '—' ?></td>
      </tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
  </div>

  <div class="card">
    <?php if (!$open): ?><p>Select a report to read and sign it.</p>
    <?php else: ?>
      <h2 style="margin-top:0;color:#1F3A5F"><?= h(str_replace('_', ' ', $open['report_type'])) ?> · v<?= (int)$open['version'] ?></h2>

      <?php if ($trail): ?><p><small>Signed so far:
        <?php foreach ($trail as $s): ?><?= h($s['step']) ?> — <?= h($s['full_name']) ?>, <?= h(date('d M H:i', strtotime($s['signed_at']))) ?>; <?php endforeach; ?>
      </small></p><?php endif; ?>

      <iframe src="report_preview.php?id=<?= (int)$open['report_id'] ?>" title="Report preview"></iframe>

      <form method="post" style="margin-top:14px">
        <?= vm_csrf_field() ?>
        <input type="hidden" name="report_id" value="<?= (int)$open['report_id'] ?>">
        <input type="hidden" name="action" value="sign">
        <label><input type="checkbox" name="attest" value="1" required>
          <?= h(SigningWorkflow::attestationFor($open['current_step'])) ?></label>
        <p><label>MFA code <input type="text" name="mfa_code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code"></label>
           <button class="btn" type="submit" <?= $hasSignature ? '' : 'disabled' ?>>Sign</button></p>
      </form>

      <details><summary style="cursor:pointer;color:#a93226">Return for correction instead</summary>
        <form method="post" style="margin-top:10px">
          <?= vm_csrf_field() ?>
          <input type="hidden" name="report_id" value="<?= (int)$open['report_id'] ?>">
          <input type="hidden" name="action" value="return">
          <textarea name="reason" rows="3" style="width:100%" minlength="10" required placeholder="What is wrong, and what should change"></textarea>
          <p><button class="btn warn" type="submit">Return to preparer</button></p>
        </form>
      </details>
    <?php endif; ?>
  </div>
</div>
</div></body></html>
