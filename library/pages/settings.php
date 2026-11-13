<?php
/** Loan rules for students and teachers: loan days, item limits, renewals, fines; holds; lost-item fee. */
declare(strict_types=1);
/** @var bool $isPost */
if ($isPost) { Library::saveRules($_POST); flash('success', 'Loan rules saved. They apply to new loans and returns from now on.'); redirect('settings'); }
$r = Library::rules();
$can = Auth::can('library.settings');
$money = fn(string $k) => Money::dec($r[$k]);
layout_start('Loan Rules');
?>
<form method="post" class="row g-3"><?= csrf_field() ?>
<?php foreach (['student' => 'Students', 'teacher' => 'Teachers'] as $t => $label): ?>
  <div class="col-lg-6"><section class="form-section h-100"><h2 class="h6"><?= $label ?></h2><div class="row g-2">
    <div class="col-6"><label class="form-label small" for="<?= $t ?>D">Loan days</label><input class="form-control" type="number" min="1" max="365" id="<?= $t ?>D" name="<?= $t ?>_loan_days" value="<?= $r[$t . '_loan_days'] ?>"<?= $can ? '' : ' disabled' ?>></div>
    <div class="col-6"><label class="form-label small" for="<?= $t ?>M">Items at a time</label><input class="form-control" type="number" min="0" max="100" id="<?= $t ?>M" name="<?= $t ?>_max_items" value="<?= $r[$t . '_max_items'] ?>"<?= $can ? '' : ' disabled' ?>></div>
    <div class="col-6"><label class="form-label small" for="<?= $t ?>R">Renewals per loan</label><input class="form-control" type="number" min="0" max="20" id="<?= $t ?>R" name="<?= $t ?>_max_renewals" value="<?= $r[$t . '_max_renewals'] ?>"<?= $can ? '' : ' disabled' ?>></div>
    <div class="col-6"><label class="form-label small" for="<?= $t ?>F">Overdue fine per day ₱</label><input class="form-control" inputmode="decimal" id="<?= $t ?>F" name="<?= $t ?>_fine_cents" value="<?= $money($t . '_fine_cents') ?>"<?= $can ? '' : ' disabled' ?>></div>
  </div></section></div>
<?php endforeach; ?>
  <div class="col-12"><section class="form-section"><h2 class="h6">Everyone</h2><div class="row g-2">
    <div class="col-6 col-md-3"><label class="form-label small" for="gG">Grace days before fines</label><input class="form-control" type="number" min="0" max="30" id="gG" name="grace_days" value="<?= $r['grace_days'] ?>"<?= $can ? '' : ' disabled' ?>></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="gX">Most overdue fine per item ₱ (0 = no limit)</label><input class="form-control" inputmode="decimal" id="gX" name="max_fine_cents" value="<?= $money('max_fine_cents') ?>"<?= $can ? '' : ' disabled' ?>></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="gL">Lost item fee when a copy has no price ₱</label><input class="form-control" inputmode="decimal" id="gL" name="lost_fee_cents" value="<?= $money('lost_fee_cents') ?>"<?= $can ? '' : ' disabled' ?>></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="gH">Days to pick up a hold</label><input class="form-control" type="number" min="1" max="30" id="gH" name="hold_pickup_days" value="<?= $r['hold_pickup_days'] ?>"<?= $can ? '' : ' disabled' ?>></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="gM">Holds per borrower</label><input class="form-control" type="number" min="0" max="20" id="gM" name="max_holds" value="<?= $r['max_holds'] ?>"<?= $can ? '' : ' disabled' ?>></div>
    <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="block_with_fines" value="1"<?= $r['block_with_fines'] ? ' checked' : '' ?><?= $can ? '' : ' disabled' ?>> Borrowers with unpaid fines can't borrow until they pay at the Cashier</label></div>
  </div></section></div>
  <?php if ($can): ?><div class="col-12"><button class="btn btn-primary">Save loan rules</button></div><?php endif; ?>
</form>
<?php layout_end();
