<?php
/** My shift: totals so far, and the cash count that closes it. */
declare(strict_types=1);
/** @var bool $isPost */
$shift = Canteen::openShift();
if ($isPost) {
    if (($_POST['action'] ?? '') === 'open') { Canteen::startShift(Money::cents((string) ($_POST['opening'] ?? '0'))); flash('success', 'Shift opened.'); redirect('dashboard'); }
    if (!$shift) throw new AppError('You have no open shift.');
    $r = Canteen::closeShift((int) $shift['id'], (array) ($_POST['d'] ?? []), (string) ($_POST['note'] ?? ''));
    flash($r['difference'] === 0 ? 'success' : 'warning', 'Shift closed. Counted ₱' . Money::fmt($r['counted']) . ', expected ₱' . Money::fmt($r['expected'])
        . ($r['difference'] === 0 ? ' — balanced.' : ($r['difference'] < 0 ? ' — short ₱' : ' — over ₱') . Money::fmt(abs($r['difference'])) . '.') . ' The supervisor will review it.');
    redirect('shift');
}
$peso = fn(int $c) => '₱' . Money::fmt($c);
$last = Db::one('SELECT * FROM canteen_shifts WHERE cashier_id = ? AND status <> \'OPEN\' ORDER BY id DESC LIMIT 1', [Auth::id()]);
layout_start('My Shift');
if (!$shift): ?>
<div class="row g-3"><div class="col-md-6"><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="open">
  <h2 class="h5">Open a shift</h2><p class="small text-muted">Count the cash in your drawer before your first sale.</p>
  <label class="form-label" for="shOpen">Opening cash ₱</label><input class="form-control mb-2" id="shOpen" name="opening" required inputmode="decimal" value="0.00">
  <button class="btn btn-primary">Open shift</button></form></div>
<?php if ($last): ?><div class="col-md-6"><section class="form-section"><h2 class="h6">Your last shift (#<?= (int) $last['id'] ?>)</h2>
  <p class="small mb-1">Closed <?= h((string) $last['closed_at']) ?> · counted <?= $peso((int) $last['counted_cents']) ?>, expected <?= $peso((int) $last['expected_cents']) ?></p>
  <p class="small mb-0">Status: <b><?= $last['status'] === 'ACCEPTED' ? 'Accepted by the supervisor' : 'Waiting for the supervisor' ?></b><?= $last['review_note'] ? ' · ' . h($last['review_note']) : '' ?></p></section></div><?php endif; ?>
</div>
<?php layout_end(); return; endif;
$t = Canteen::shiftTotals((int) $shift['id']);
?>
<?php if ($shift['review_note']): ?><div class="alert alert-warning">Returned by the supervisor: <?= h($shift['review_note']) ?> Count the drawer again.</div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-5"><section class="form-section">
    <h2 class="h5">Shift #<?= (int) $shift['id'] ?></h2><p class="small text-muted">Opened <?= h(date('M j, g:i A', strtotime((string) $shift['opened_at']))) ?></p>
    <table class="table table-sm mb-0"><tbody>
      <tr><td>Opening cash</td><td class="text-end"><?= $peso($t['opening']) ?></td></tr>
      <tr><td>Cash sales</td><td class="text-end"><?= $peso($t['cash']) ?></td></tr>
      <tr><td>QR payments</td><td class="text-end"><?= $peso($t['qr']) ?></td></tr>
      <tr><td>Refunds paid out</td><td class="text-end">−<?= $peso($t['refunds']) ?></td></tr>
      <tr><td>Sales (<?= $t['count'] ?>)</td><td class="text-end"><?= $peso($t['net']) ?></td></tr>
      <tr class="fw-bold"><td>Cash expected in drawer</td><td class="text-end"><?= $peso($t['expected']) ?></td></tr>
    </tbody></table>
    <?php if ($t['pending']): ?><div class="alert alert-warning mt-2 mb-0 small">A void or refund request is waiting for the supervisor. It must be decided before you close.</div><?php endif; ?>
  </section></div>
  <div class="col-lg-7"><form method="post" class="form-section" data-cash-count data-expected="<?= $t['expected'] ?>" data-confirm="Close this shift with the counted cash? You can't sell in it after closing."><?= csrf_field() ?><input type="hidden" name="action" value="close">
    <h2 class="h5">Count the drawer and close</h2>
    <div class="row g-2">
    <?php foreach (Canteen::DENOMINATIONS as $d): ?>
      <div class="col-6 col-sm-4"><label class="form-label small mb-0" for="d<?= $d ?>"><?= $d >= 100 ? '₱' . number_format($d / 100) : $d . '¢' ?> <?= $d >= 2000 ? 'bills' : 'coins' ?></label>
        <input class="form-control form-control-sm" type="number" min="0" max="99999" inputmode="numeric" id="d<?= $d ?>" name="d[<?= $d ?>]" data-denom="<?= $d ?>" placeholder="0"></div>
    <?php endforeach; ?>
    </div>
    <p class="mt-3 mb-1">Counted <b data-count-total>₱0.00</b> · <span data-count-diff></span></p>
    <label class="form-label small" for="shNote">Note (if short or over)</label><input class="form-control form-control-sm mb-2" id="shNote" name="note" maxlength="255">
    <button class="btn btn-primary">Close shift</button>
  </form></div>
</div>
<script src="assets/canteen-pages.js?v=59" defer></script>
<?php layout_end();
