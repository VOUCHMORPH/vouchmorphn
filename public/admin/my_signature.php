<?php
/**
 * public/admin/my_signature.php
 * Every admin sets their signature here: draw it with a finger, stylus or
 * mouse, or upload a photo of it signed on paper.
 */
declare(strict_types=1);
require __DIR__ . '/_signing_bootstrap.php';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vm_check_csrf();
    try {
        $code = trim($_POST['mfa_code'] ?? '');
        if (($_POST['mode'] ?? '') === 'draw') {
            $id = $specimens->saveDrawn($adminId, $_POST['drawn'] ?? '', $code);
        } else {
            $id = $specimens->saveUploaded($adminId, $_FILES['upload'] ?? [], $code);
        }
        vm_audit('SIGNATURE_SPECIMEN_SET', ['specimen_id' => $id, 'mode' => $_POST['mode'] ?? 'upload']);
        $flash = ['ok', 'Your signature is saved. It will appear on documents you sign.'];
    } catch (Throwable $e) {
        vm_audit('SIGNATURE_SPECIMEN_REJECTED', ['reason' => $e->getMessage()]);
        $flash = ['err', $e->getMessage()];
    }
}

$current = $specimens->active($adminId);
$currentImg = $current ? $specimens->dataUri((int)$current['id']) : null;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My signature · VouchMorph</title>
<style><?= VM_PAGE_CSS ?>
#pad{border:2px dashed #9fb0c3;border-radius:6px;background:#fff;touch-action:none;width:100%;max-width:600px;height:200px;display:block}
.tabs button{padding:8px 14px;border:1px solid #c4cdd8;background:#fff;cursor:pointer}
.tabs button.on{background:#1F3A5F;color:#fff;border-color:#1F3A5F}
.panel{display:none;margin-top:14px}.panel.on{display:block}
.current img{max-height:80px;background:repeating-conic-gradient(#f2f2f2 0 25%,#fff 0 50%) 0 0/16px 16px;padding:6px;border:1px solid #e3e8ee}
</style></head><body><div class="wrap">
<?= vm_nav() ?>
<h1>My signature</h1>
<p class="sub">This mark appears on every document you sign. Signing always also asks for your MFA code — the image alone never signs anything.</p>

<?php if ($flash): ?><div class="msg <?= $flash[0] ?>"><?= h($flash[1]) ?></div><?php endif; ?>

<div class="card current">
  <b>Current signature</b><br>
  <?php if ($currentImg): ?>
    <img src="<?= $currentImg ?>" alt="Your current signature"><br>
    <small>Set <?= h(date('d M Y H:i', strtotime($current['created_at']))) ?> · <?= h(strtolower($current['kind'])) ?>. Replacing it keeps this one on record for documents already signed.</small>
  <?php else: ?>
    <p class="msg err" style="margin:8px 0 0">You have no signature yet. You cannot sign documents until you set one.</p>
  <?php endif; ?>
</div>

<div class="card">
  <div class="tabs"><button type="button" class="on" data-p="draw">Draw</button><button type="button" data-p="upload">Upload a photo</button></div>

  <form method="post" enctype="multipart/form-data" id="sigform">
    <?= vm_csrf_field() ?>
    <input type="hidden" name="mode" id="mode" value="draw">
    <input type="hidden" name="drawn" id="drawn">

    <div class="panel on" id="p-draw">
      <canvas id="pad" width="600" height="200"></canvas>
      <p><button type="button" class="btn ghost" id="clear">Clear</button> <small>Sign inside the box.</small></p>
    </div>

    <div class="panel" id="p-upload">
      <input type="file" name="upload" accept="image/png,image/jpeg">
      <p><small>Sign in dark ink on plain white paper, photograph it straight on in good light, and upload. PNG or JPEG, under 2 MB. The paper is removed automatically.</small></p>
    </div>

    <p><label>MFA code<br><input type="text" name="mfa_code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code"></label></p>
    <button class="btn" type="submit">Save signature</button>
  </form>
</div>
</div>

<script>
(() => {
  const pad = document.getElementById('pad'), ctx = pad.getContext('2d');
  let drawing = false, dirty = false;
  ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#142a5a';

  const pos = e => { const r = pad.getBoundingClientRect();
    return [(e.clientX - r.left) * pad.width / r.width, (e.clientY - r.top) * pad.height / r.height]; };

  pad.addEventListener('pointerdown', e => { drawing = true; pad.setPointerCapture(e.pointerId);
    const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); });
  pad.addEventListener('pointermove', e => { if (!drawing) return; const [x, y] = pos(e);
    ctx.lineTo(x, y); ctx.stroke(); dirty = true; });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(t => pad.addEventListener(t, () => drawing = false));
  document.getElementById('clear').onclick = () => { ctx.clearRect(0, 0, pad.width, pad.height); dirty = false; };

  document.querySelectorAll('.tabs button').forEach(b => b.onclick = () => {
    document.querySelectorAll('.tabs button').forEach(x => x.classList.toggle('on', x === b));
    document.querySelectorAll('.panel').forEach(p => p.classList.toggle('on', p.id === 'p-' + b.dataset.p));
    document.getElementById('mode').value = b.dataset.p;
  });

  document.getElementById('sigform').addEventListener('submit', e => {
    if (document.getElementById('mode').value === 'draw') {
      if (!dirty) { e.preventDefault(); alert('Draw your signature first.'); return; }
      document.getElementById('drawn').value = pad.toDataURL('image/png');
    }
  });
})();
</script>
</body></html>
