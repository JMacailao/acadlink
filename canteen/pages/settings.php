<?php
/** Canteen settings: the payment QR code shown at checkout, the canteen name and the receipt footer. */
declare(strict_types=1);
/** @var bool $isPost */
if ($isPost) {
    Canteen::saveSettings($_POST, $_FILES['qr'] ?? null, !empty($_POST['remove_qr']));
    flash('success', 'Canteen settings saved.');
    redirect('settings');
}
$qr = Canteen::imageVersion('QR', 0);
layout_start('Canteen Settings');
?>
<form method="post" enctype="multipart/form-data" class="row g-3"><?= csrf_field() ?>
  <div class="col-lg-6"><section class="form-section h-100">
    <h2 class="h6">QR payment</h2>
    <p class="small text-muted">Upload the canteen's GCash, Maya or bank QR code. The Cashier screen shows it when the customer pays by QR; the cashier confirms when the payment arrives.</p>
    <?php if ($qr !== ''): ?><img src="<?= h(url('qr', ['v' => $qr])) ?>" alt="Saved payment QR code" class="cpos-qr mb-2"><label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="remove_qr" value="1"> Remove this QR code</label><?php else: ?><div class="alert alert-warning small">No QR code saved yet.</div><?php endif; ?>
    <label class="form-label small" for="stQr"><?= $qr !== '' ? 'Replace with' : 'QR code image' ?> (PNG, JPEG or WebP, up to 5 MB)</label>
    <input class="form-control form-control-sm mb-2" type="file" id="stQr" name="qr" accept="image/png,image/jpeg,image/webp,image/gif">
    <label class="form-label small" for="stQrLabel">Text under the QR code</label>
    <input class="form-control form-control-sm" id="stQrLabel" name="qr_label" maxlength="80" value="<?= h(Canteen::setting('qr_label', 'Scan to pay with GCash or Maya')) ?>">
  </section></div>
  <div class="col-lg-6"><section class="form-section h-100">
    <h2 class="h6">Receipt</h2>
    <label class="form-label small" for="stName">Canteen name</label>
    <input class="form-control form-control-sm mb-2" id="stName" name="name" maxlength="80" value="<?= h(Canteen::setting('name')) ?>" placeholder="<?= h(School::brand()) ?> Canteen">
    <label class="form-label small" for="stFoot">Footer</label>
    <input class="form-control form-control-sm" id="stFoot" name="receipt_footer" maxlength="200" value="<?= h(Canteen::setting('receipt_footer', 'Thank you!')) ?>">
    <p class="form-text">Accounting accounts for canteen sales are set by Finance in Accounting → Account Mappings.</p>
  </section></div>
  <div class="col-12"><button class="btn btn-primary">Save settings</button></div>
</form>
<?php layout_end();
