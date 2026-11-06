<?php
/**
 * Printable registration statement of account.
 * Expects $r (Registration::get), $subs, $items, $pays, $state; when $editable is true,
 * fee lines may show registrar-only controls. Browser print hides those controls.
 */
// The registration page shows the plan below the statement instead ($planSummaryBelow).
if (empty($planSummaryBelow)) require __DIR__.'/payment-plan-summary.php';
if (Settings::get('asm_template') === '2') {
    require __DIR__ . '/assessment_sheet_enrollment.php';
    return;
}
$editable = $editable ?? false;
$showBreakdown = Settings::on('asm_show_breakdown');
$groups = [];
foreach ($items as $item) $groups[$item['category_name']][] = $item;
$amountText = static function (int $cents): string {
    if ($cents === 0) return '—';
    return $cents < 0 ? '−₱' . Money::fmt(abs($cents)) : '₱' . Money::fmt($cents);
};
$unitsText = static fn(mixed $units): string => rtrim(rtrim(number_format((float) $units, 1, '.', ''), '0'), '.');
$carriedForward = BalanceEnrollment::carriedForward($r);
$school = Settings::get('print_school_name') !== '' ? Settings::get('print_school_name') : School::name();
$logo = School::logo() ?: '../icons/acadlink-mark.png';
$printedAt = date('F j, Y');
// v56: "Prepared by" is the staff member signed in now (printing the statement), never typed by hand.
$preparedBy = SoaPreparer::name();
$columnCount = $showBreakdown ? 6 : 4;
?>
<style>@media print { @page { size: <?= Settings::pageSize(Settings::get('asm_paper')) ?>; margin: 10mm; } }</style>
<div class="doc-sheet soa-sheet paper-<?= h(strtolower(Settings::get('asm_paper'))) ?>">
  <header class="soa-head">
    <?php if (Settings::on('print_show_logo')): ?><img class="soa-logo" src="<?= h($logo) ?>" alt=""><?php else: ?><span class="soa-logo-spacer" aria-hidden="true"></span><?php endif; ?>
    <div class="soa-school">
      <div class="soa-school-name"><?= h($school) ?></div>
      <?php if (Settings::get('print_address') !== ''): ?><div><?= h(Settings::get('print_address')) ?></div><?php endif; ?>
      <?php if (Settings::get('print_contact') !== ''): ?><div><?= h(Settings::get('print_contact')) ?></div><?php endif; ?>
      <div class="soa-title"><?= h(Settings::get('asm_title')) ?></div>
    </div>
    <div class="soa-ref">
      <span class="soa-ref-label">STATEMENT REF. NO.</span>
      <strong class="soa-ref-number"><?= h($r['reg_no']) ?></strong>
      <div><span>Prepared:</span> <?= h($printedAt) ?></div>
      <div><span>Enrollment:</span> <?= h($state) ?></div>
      <div><span>Payment:</span> <?= h(Registration::payStatus($r)) ?></div>
    </div>
  </header>

  <section class="soa-student" aria-label="Student and enrollment details">
    <div class="soa-detail-primary">
      <div class="soa-field soa-name"><span>Student Name</span><strong><?= h(mb_strtoupper($r['student_name'])) ?></strong></div>
      <div class="soa-field"><span>Student ID</span><strong><?= h($r['student_number']) ?></strong></div>
      <div class="soa-field"><span>Student Type</span><strong><?= h(($stype ?? Registration::studentType($r['student_doc_id'], $r['student_number'], $r['school_year'], $r['term']))['label']) ?></strong></div>
    </div>
    <div class="soa-detail-secondary">
      <div class="soa-field"><span>School Year</span><strong><?= h($r['school_year']) ?></strong></div>
      <div class="soa-field"><span>Term</span><strong><?= h($r['term']) ?></strong></div>
      <div class="soa-field"><span>Section / Year Level</span><strong><?= h($r['section_name'] . ' · ' . $r['year_level']) ?></strong></div>
    </div>
  </section>

  <?php if (Settings::on('asm_show_subjects')): ?>
  <section class="soa-section">
    <h2 class="soa-section-title">SUBJECTS / REGISTRATION DETAILS</h2>
    <?php $split = LecLab::on(); $splitTotals = [0.0, 0.0]; ?>
    <table class="soa-table soa-subject-table<?= $split ? ' is-split' : '' ?>">
      <thead><tr><th class="soa-code">Code</th><th class="soa-subject">Subject</th><th class="soa-teacher">Teacher</th><?php if ($split): ?><th class="soa-num soa-lec">Lec</th><th class="soa-num soa-lab">Lab</th><?php endif; ?><th class="soa-num soa-units">Units</th><?php if (!$split): ?><th class="soa-num soa-price">Price / Unit</th><?php endif; ?><th class="soa-num soa-subject-fee">Subject Fee</th></tr></thead>
      <tbody>
        <?php $subjectFees = SubjectFees::perSubject($subs, $items); foreach ($subs as $subject): $sf = $subjectFees[(string) $subject['subject_doc_id']] ?? ['rate_cents' => 0, 'amount_cents' => 0]; ?><tr><td><?= h($subject['subject_code'] ?? '') ?></td><td><?= h($subject['subject_name']) ?></td><td><?= ($subject['teacher_name'] ?? '') !== '' ? h($subject['teacher_name']) : '<span class="soa-unassigned">Unassigned</span>' ?></td><?php if ($split): $sp = LecLab::ofRow($subject); $splitTotals[0] += $sp[0]; $splitTotals[1] += $sp[1]; ?><td class="soa-num"><?= h(LecLab::text($sp[0])) ?></td><td class="soa-num"><?= h(LecLab::text($sp[1])) ?></td><?php endif; ?><td class="soa-num"><?= h($unitsText($subject['units'])) ?></td><?php if (!$split): ?><td class="soa-num">₱<?= Money::fmt($sf['rate_cents']) ?></td><?php endif; ?><td class="soa-num">₱<?= Money::fmt($sf['amount_cents']) ?></td></tr><?php endforeach; ?>
        <?php if (!$subs): ?><tr><td colspan="<?= $split ? 7 : 6 ?>" class="soa-empty">No subjects recorded.</td></tr><?php endif; ?>
      </tbody>
        <tfoot><tr><td colspan="3"><?= count($subs) ?> subject(s)</td><?php if ($split): ?><td class="soa-num"><?= h(LecLab::text($splitTotals[0])) ?></td><td class="soa-num"><?= h(LecLab::text($splitTotals[1])) ?></td><?php endif; ?><td class="soa-num"><?= h($unitsText($r['total_units'])) ?></td><?php if (!$split): ?><td></td><?php endif; ?><td class="soa-num">₱<?= Money::fmt(array_sum(array_column($subjectFees, 'amount_cents'))) ?></td></tr></tfoot>
    </table>
  </section>
  <?php else: ?><p class="soa-subject-summary"><?= count($subs) ?> subject(s) · <?= h($unitsText($r['total_units'])) ?> units</p><?php endif; ?>

  <section class="soa-section">
    <h2 class="soa-section-title">SUMMARY OF FEES</h2>
    <table class="soa-table soa-fee-table">
      <thead><tr>
        <th class="soa-particular">Particulars</th>
        <?php if ($showBreakdown): ?><th class="soa-qty soa-num">Qty / Units</th><th class="soa-rate soa-num">Rate</th><?php endif; ?>
        <th class="soa-num">Actual Charges</th><th class="soa-num">Scholarships / Less</th><th class="soa-num">Net Amount</th>
      </tr></thead>
      <tbody>
      <?php if (!$items): ?><tr><td colspan="<?= $columnCount ?>" class="soa-empty">No fees have been assessed.</td></tr><?php endif; ?>
      <?php foreach ($groups as $category => $lines):
          $groupCharges = 0; $groupDiscounts = 0; $groupNet = 0;
          foreach ($lines as $line) { $c = Money::cents($line['amount']); $groupCharges += max(0, $c); $groupDiscounts += max(0, -$c); $groupNet += $c; }
      ?>
        <tr class="soa-category"><th colspan="<?= $columnCount ?>"><?= h($category) ?></th></tr>
        <?php foreach ($lines as $line):
            $cents = Money::cents($line['amount']);
            $quantity = (string) $line['quantity'];
            $quantity = preg_replace('/\.0$/', '', $quantity) ?? $quantity;
            $hasQuantity = !in_array($line['charge_type'], ['ADJUSTMENT', 'DISCOUNT'], true);
        ?>
        <tr class="soa-fee-line">
          <td><?= h($line['label_name']) ?><?php if ($editable && $line['charge_type'] === 'ADJUSTMENT'): ?> <span class="d-print-none"><?= post_button('registration', ['id' => $id], ['action' => 'remove_adj', 'item_id' => $line['id']], '<i class="bi bi-x-lg"></i>', 'btn-sm btn-link text-danger p-0', 'Remove this adjustment?') ?></span>
            <?php elseif ($editable && isset($labelIdByName[$line['label_name']])): $labelId = (int) $labelIdByName[$line['label_name']]; ?>
              <?php if (isset($feeChoices[$labelId])): ?><span class="badge text-bg-info me-1 d-print-none">for this student</span><?php endif; ?>
              <span class="d-print-none"><?= post_button('registration', ['id' => $id], ['action' => 'remove_label', 'label_id' => $labelId], '<i class="bi bi-x-lg"></i>', 'btn-sm btn-link text-danger p-0', 'Take ' . $line['label_name'] . ' off this student\'s assessment?') ?></span>
            <?php endif; ?></td>
          <?php if ($showBreakdown): ?>
          <td class="soa-num"><?= $hasQuantity ? h($quantity) : '—' ?></td>
          <td class="soa-num"><?= $hasQuantity ? '₱' . Money::fmt(Money::cents($line['unit_amount'])) : '—' ?></td>
          <?php endif; ?>
          <td class="soa-num"><?= $cents > 0 ? $amountText($cents) : '—' ?></td>
          <td class="soa-num"><?= $cents < 0 ? '₱' . Money::fmt(abs($cents)) : '—' ?></td>
          <td class="soa-num"><?= $amountText($cents) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="soa-subtotal">
          <td colspan="<?= $showBreakdown ? 3 : 1 ?>">Subtotal · <?= h($category) ?></td>
          <td class="soa-num"><?= $amountText($groupCharges) ?></td><td class="soa-num"><?= $groupDiscounts ? '₱' . Money::fmt($groupDiscounts) : '—' ?></td><td class="soa-num"><?= $amountText($groupNet) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="soa-grand-total">
        <th colspan="<?= $showBreakdown ? 3 : 1 ?>">TOTAL ASSESSMENT</th>
        <th class="soa-num"><?= $amountText(array_sum(array_map(fn($line) => max(0, Money::cents($line['amount'])), $items))) ?></th>
        <th class="soa-num"><?= $amountText(array_sum(array_map(fn($line) => max(0, -Money::cents($line['amount'])), $items))) ?></th>
        <th class="soa-num"><?= $amountText(Money::cents($r['total_amount'])) ?></th>
      </tr>
      <?php if ($carriedForward['cents'] > 0): foreach ($carriedForward['items'] as $oldBalance): $old = $oldBalance['registration']; ?>
        <tr class="soa-fee-line"><td colspan="<?= $showBreakdown ? 5 : 3 ?>"><strong>Existing balance carried forward</strong><br><small><?= h($old['school_year'] . ' ' . $old['term'] . ' · ' . $old['reg_no']) ?></small></td><td class="soa-num"><strong>₱<?= Money::fmt($oldBalance['balance_cents']) ?></strong></td></tr>
      <?php endforeach; ?><tr class="soa-grand-total"><th colspan="<?= $showBreakdown ? 5 : 3 ?>">TOTAL PAYABLE INCLUDING APPROVED PRIOR BALANCE</th><th class="soa-num">₱<?= Money::fmt(Money::cents($r['total_amount']) - Money::cents($r['amount_paid']) + $carriedForward['cents']) ?></th></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <?php $cls = 'soa'; require __DIR__ . '/soa_plan_box.php'; ?>

  <?php if (Settings::on('asm_show_payments')): ?>
  <section class="soa-section soa-payment-section">
    <h2 class="soa-section-title">PAYMENTS RECEIVED</h2>
    <table class="soa-table soa-pay-table">
      <thead><tr><th>Date</th><th>Official Receipt No.</th><th>Payment Details</th><th class="soa-num">Amount</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($pays as $payment): ?>
        <tr class="<?= $payment['status'] === 'VOIDED' ? 'soa-payment-void' : '' ?>">
          <td><?= h(date('M j, Y', strtotime($payment['paid_at']))) ?></td>
          <td class="soa-mono"><?= h($payment['receipt_no']) ?></td>
          <td><?= h($payment['method']) ?><?= !empty($payment['reference_no']) ? ' · Ref ' . h($payment['reference_no']) : '' ?><?= !empty($payment['remarks']) ? ' · ' . h($payment['remarks']) : '' ?><?php if ($payment['status'] === 'VOIDED' && !empty($payment['void_reason'])): ?><small class="soa-void-reason">Void reason: <?= h($payment['void_reason']) ?></small><?php endif; ?></td>
          <td class="soa-num"><?= $payment['status'] === 'VOIDED' ? '−₱' : '₱' ?><?= Money::fmt(Money::cents($payment['amount'])) ?></td>
          <td><?= $payment['status'] === 'VOIDED' ? 'VOIDED' : 'POSTED' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$pays): ?><tr><td colspan="5" class="soa-empty">No payments received.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <div class="soa-summary-wrap">
    <table class="soa-summary">
      <tbody>
        <tr><th>Total Assessment</th><td><?= $amountText(Money::cents($r['total_amount'])) ?></td></tr>
        <tr><th>Less: Payments Posted</th><td><?= $amountText(-Money::cents($r['amount_paid'])) ?></td></tr>
        <tr class="soa-balance"><th>BALANCE DUE</th><td>₱<?= Money::fmt(Registration::balance($r)) ?></td></tr>
        <?php if ($carriedForward['cents'] > 0): ?><tr><th>Approved prior balance</th><td>₱<?= Money::fmt($carriedForward['cents']) ?></td></tr><tr class="soa-balance"><th>TOTAL PAYABLE</th><td>₱<?= Money::fmt(Registration::balance($r) + $carriedForward['cents']) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (Settings::get('asm_note') !== ''): ?><div class="soa-note"><strong>Note:</strong> <?= nl2br(h(Settings::get('asm_note'))) ?></div><?php endif; ?>
  <?php if (Settings::get('asm_sign_left') !== '' || Settings::get('asm_sign_right') !== ''): ?>
  <footer class="soa-signatures">
    <?php foreach (['asm_sign_left', 'asm_sign_right'] as $key): if (Settings::get($key) === '') continue; ?><div class="soa-sign"><span class="soa-sign-line"><?php if ($key === 'asm_sign_left' && $preparedBy !== ''): ?><strong class="soa-sign-name"><?= h(mb_strtoupper($preparedBy)) ?></strong><?php endif; ?></span><span><?= h(Settings::get($key)) ?></span></div><?php endforeach; ?>
  </footer>
  <?php endif; ?>
</div>
