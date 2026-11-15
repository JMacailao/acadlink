<?php
/**
 * v65.4: Subject pricing (Assessment → Subject Fees → Set a subject price / Edit), as in the design:
 * one fee and a price per unit, the subjects it is set for (each with its own price), the fee templates that charge it,
 * a summary (subjects, units, price, total) and Save prices. Saves through SubjectFees::save, one subject at a time,
 * in one transaction.
 *
 * @var array $labels @var array $templates @var ?int $defaultLabel @var ?array $editFee @var ?array $editSubject
 * @var array $editTicks @var bool $split
 */
declare(strict_types=1);
$subjects = [];
foreach (GS::subjects() as $s) { $ls = LecLab::ofSubject($s); $subjects[] = ['id' => (string) $s['id'], 'code' => (string) ($s['subjectCode'] ?? ''), 'name' => (string) ($s['subjectName'] ?? ''), 'units' => (float) ($s['units'] ?? 0), 'lec' => (float) $ls[0], 'lab' => (float) $ls[1]]; }
$existing = [];
foreach (SubjectFees::catalog() as $f) $existing[(string) $f['subject_doc_id']][(string) $f['label_id']] = ['id' => (int) $f['id'], 'rate' => Money::dec(Money::cents($f['rate']))];
$labelId = (int) ($editFee['label_id'] ?? $defaultLabel ?? 0);
$basis = (string) ($editFee['unit_basis'] ?? 'ALL');
$ticks = $editTicks;
$topRate = $editFee ? Money::dec(Money::cents($editFee['rate'])) : '0.00';
$initial = [];
if ($editFee) $initial[] = ['subject' => (string) $editFee['subject_doc_id'], 'fee' => (string) (int) $editFee['id'], 'rate' => $topRate];
elseif ($editSubject) { $have = $existing[(string) $editSubject['id']][(string) $labelId] ?? null; $initial[] = ['subject' => (string) $editSubject['id'], 'fee' => '', 'rate' => $have['rate'] ?? $topRate]; if ($have) $topRate = $have['rate']; }
// A refused save comes back here with what was entered (see keep_old_input): put it back.
$old = $_SESSION['old_input'] ?? null;
if (is_array($old) && ($old['action'] ?? '') === 'bulk' && ($old['uri'] ?? '') === (string) ($_SERVER['REQUEST_URI'] ?? '')) {
    $o = (array) ($old['fields'] ?? []); $initial = [];
    foreach (array_values((array) ($o['p_subject'] ?? [])) as $i => $sid) $initial[] = ['subject' => (string) $sid, 'fee' => (string) (array_values((array) ($o['p_fee'] ?? []))[$i] ?? ''), 'rate' => (string) (array_values((array) ($o['p_rate'] ?? []))[$i] ?? '')];
    if (ctype_digit((string) ($o['label_id'] ?? ''))) $labelId = (int) $o['label_id'];
    if (isset($o['unit_basis'])) $basis = (string) $o['unit_basis'];
    $ticks = array_map('intval', array_values((array) ($o['templates'] ?? [])));
}
$data = ['subjects' => $subjects, 'existing' => $existing, 'initial' => $initial, 'split' => $split];
$labelText = fn(array $l) => $l['category_name'] . ' · ' . $l['name'];
layout_start('Subject Fees');
?>
<div class="cur-page sp-page">
<a class="cur-back" href="<?= url('subjectfees') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to subject fees</a>
<div class="cur-title" role="heading" aria-level="2">Subject pricing</div>
<script type="application/json" id="spData"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<form method="post" id="spForm" class="sp-form" data-sp-form novalidate><?= csrf_field() ?><input type="hidden" name="action" value="bulk">
  <section class="cur-card sp-card" aria-labelledby="spSelectTitle">
    <h3 id="spSelectTitle">Select subjects</h3>
    <div class="sp-top">
      <div class="cur-field sp-fee"><label for="spFee">Fee</label><select class="form-select" id="spFee" name="label_id" required><?php foreach ($labels as $l): ?><option value="<?= (int) $l['id'] ?>"<?= $labelId === (int) $l['id'] ? ' selected' : '' ?>><?= h($labelText($l)) ?></option><?php endforeach; ?></select></div>
      <?php if ($split): ?><div class="cur-field sp-basis"><label for="spBasis">Charged per</label><select class="form-select" id="spBasis" name="unit_basis"><?php foreach (LecLab::BASIS as $bk => $bl): ?><option value="<?= h($bk) ?>"<?= $basis === $bk ? ' selected' : '' ?>><?= h($bl) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="cur-field sp-rate"><label for="spRate">Price per unit</label><div class="sp-peso"><span aria-hidden="true">₱</span><input class="form-control" id="spRate" inputmode="decimal" autocomplete="off" value="<?= h($topRate) ?>" aria-describedby="spRateHelp"></div></div>
      <div class="sp-default-wrap"><button type="button" class="btn sp-default" data-sp-default>Set as default</button><span class="sp-default-msg" data-sp-default-msg aria-live="polite"></span></div>
    </div>
    <p class="sp-hint" id="spRateHelp">The price is used for the subjects you add; you can change each subject's price below. "Set as default" makes this fee the first choice next time.</p>
    <div class="cur-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" data-sp-search placeholder="Search subject code or name..." aria-label="Search subject code or name"></div>
    <div class="cur-table-wrap cur-avail sp-avail"><table class="table cur-table mb-0"><thead><tr><th>Code</th><th>Subject name</th><th class="text-center">Units</th><th class="text-center">Action</th></tr></thead><tbody data-sp-avail></tbody></table></div>
    <div class="sp-templates">
      <span class="sp-tlabel">Charge in fee templates</span>
      <span class="sp-chips" data-sp-chips></span>
      <button type="button" class="btn btn-outline-primary sp-choose" data-sp-choose>Choose templates</button>
    </div>
    <p class="sp-hint mb-0">Only selected templates will be charged.</p>
  </section>

  <section class="sp-summary" aria-label="Summary">
    <div><span>Selected subjects</span><strong data-sp-stat="count">0</strong></div>
    <div><span>Total units</span><strong data-sp-stat="units">0</strong></div>
    <div><span>Price per unit</span><strong data-sp-stat="rate">₱0.00</strong></div>
    <div><span>Total amount</span><strong data-sp-stat="total">₱0.00</strong></div>
  </section>

  <section class="cur-card sp-card" aria-labelledby="spSelectedTitle">
    <h3 id="spSelectedTitle">Selected subjects <span class="sp-badge" data-sp-count>0</span></h3>
    <div class="cur-table-wrap"><table class="table cur-table sp-selected mb-0"><thead><tr><th>Code</th><th>Subject name</th><th class="text-center sp-unitcol">Units</th><th class="sp-feecol">Fee</th><th>Price / unit</th><th class="text-end">Amount</th><th class="text-center">Remove</th></tr></thead>
      <tbody data-sp-selected></tbody>
      <tfoot><tr><th colspan="5" class="sp-totlabel">Total</th><th class="text-end" data-sp-foot>₱0.00</th><th></th></tr></tfoot></table></div>
  </section>

  <dialog class="cur-picker sp-tdialog" data-sp-tdialog aria-labelledby="spTTitle">
    <div class="cur-picker-head"><div><h3 id="spTTitle">Choose templates</h3><p class="cur-picker-sub">Only the templates you tick charge these prices.</p></div><button type="button" class="btn-close" data-sp-tclose aria-label="Close"></button></div>
    <div class="cur-picker-body">
      <?php if ($templates): ?><div class="cur-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" data-sp-tsearch placeholder="Search templates..." aria-label="Search templates"></div>
      <div class="sp-tlist"><?php foreach ($templates as $t): ?><label class="sp-titem"><input class="form-check-input" type="checkbox" name="templates[]" value="<?= (int) $t['id'] ?>" data-name="<?= h($t['name']) ?>"<?= in_array((int) $t['id'], $ticks, true) ? ' checked' : '' ?>><span><?= h($t['name']) ?><?= $t['is_active'] ? '' : ' <em>(inactive)</em>' ?></span></label><?php endforeach; ?></div>
      <?php else: ?><p class="text-muted">No fee templates yet (Assessment → Fee templates).</p><?php endif; ?>
    </div>
    <div class="cur-picker-foot"><span data-sp-tcount>0 selected</span><button type="button" class="btn btn-primary" data-sp-tclose>Done</button></div>
  </dialog>

  <div class="cur-savebar"><span class="cur-dirty" data-cur-dirty>No changes</span>
    <a class="btn btn-outline-primary" href="<?= url('subjectfees') ?>">Cancel</a>
    <button class="btn btn-primary" data-sp-save>Save prices</button></div>
</form>
</div>
<script src="assets/subject-pricing.js?v=65.4d" defer></script>
<?php layout_end();
