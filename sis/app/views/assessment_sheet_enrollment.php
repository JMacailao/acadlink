<?php
/**
 * Template 2: enrollment-style assessment form inspired by the supplied sample.
 * Uses SIS fields available today; class schedules, lecture/lab splits, and installment plans
 * are not stored by this system, so this form shows assigned teachers and actual payment activity.
 * Expects the same registration-page variables as assessment_sheet.php.
 */
$editable = $editable ?? false;
$showBreakdown = Settings::on('asm_show_breakdown');
$groups = [];
foreach ($items as $item) $groups[$item['category_name']][] = $item;
$amountText = static function (int $cents): string {
    if ($cents === 0) return '—';
    return $cents < 0 ? '−₱' . Money::fmt(abs($cents)) : '₱' . Money::fmt($cents);
};
$unitsText = static fn(mixed $units): string => rtrim(rtrim(number_format((float) $units, 1, '.', ''), '0'), '.');
$school = Settings::get('print_school_name') !== '' ? Settings::get('print_school_name') : School::name();
$logo = School::logo() ?: '../icons/acadlink-mark.png';
$printedAt = date('F j, Y');
$grossCents = array_sum(array_map(fn($line) => max(0, Money::cents($line['amount'])), $items));
$lessCents = array_sum(array_map(fn($line) => max(0, -Money::cents($line['amount'])), $items));
$carriedForward = BalanceEnrollment::carriedForward($r);
$processor = Auth::id() ? (string) Db::val('SELECT full_name FROM sis_users WHERE id = ?', [Auth::id()]) : '';
$courseYear = trim(($r['program'] ?: $r['section_name']) . ' · ' . $r['year_level'], ' ·');
?>
<style>@media print { @page { size: <?= Settings::pageSize(Settings::get('asm_paper')) ?>; margin: 10mm; } }</style>
<div class="doc-sheet enrollment-sheet paper-<?= h(strtolower(Settings::get('asm_paper'))) ?>">
  <header class="enr-head">
    <?php if (Settings::on('print_show_logo')): ?><img class="enr-logo" src="<?= h($logo) ?>" alt=""><?php endif; ?>
    <div class="enr-school-name"><?= h($school) ?></div>
    <?php if (Settings::get('print_address') !== ''): ?><div class="enr-school-line"><?= h(Settings::get('print_address')) ?></div><?php endif; ?>
    <?php if (Settings::get('print_contact') !== ''): ?><div class="enr-school-line"><?= h(Settings::get('print_contact')) ?></div><?php endif; ?>
    <h1 class="enr-title">ASSESSMENT FORM</h1>
  </header>

  <section class="enr-student" aria-label="Student and enrollment details">
    <div class="enr-field enr-field-wide"><span>Name:</span><strong><?= h(mb_strtoupper($r['student_name'])) ?></strong></div>
    <div class="enr-field"><span>Period:</span><strong><?= h($r['term'] . ' AY ' . $r['school_year']) ?></strong></div>
    <div class="enr-field"><span>ID No.:</span><strong><?= h($r['student_number']) ?></strong></div>
    <div class="enr-field"><span>Course / Yr:</span><strong><?= h($courseYear) ?></strong></div>
    <div class="enr-field"><span>Date:</span><strong><?= h($printedAt) ?></strong></div>
    <div class="enr-field"><span>Section:</span><strong><?= h($r['section_name']) ?></strong></div>
    <div class="enr-field"><span>Registration No.:</span><strong><?= h($r['reg_no']) ?></strong></div>
  </section>

  <?php if (Settings::on('asm_show_subjects')): ?>
  <section class="enr-section">
    <table class="enr-table enr-subject-table">
      <?php $split = LecLab::on(); $splitTotals = [0.0, 0.0]; ?>
      <thead><tr><th>Subject Code</th><th>Subject Description</th><th>Teacher</th><?php if ($split): ?><th class="enr-num">Lec</th><th class="enr-num">Lab</th><?php endif; ?><th class="enr-num">Units</th><?php if (!$split): ?><th class="enr-num">Price / Unit</th><?php endif; ?><th class="enr-num">Subject Fee</th><th>Section</th></tr></thead>
      <tbody>
        <?php $subjectFees = SubjectFees::perSubject($subs, $items); foreach ($subs as $subject): $sf = $subjectFees[(string) $subject['subject_doc_id']] ?? ['rate_cents' => 0, 'amount_cents' => 0]; ?><tr>
          <td><?= h($subject['subject_code'] ?? '') ?></td>
          <td><?= h($subject['subject_name']) ?></td>
          <td><?= ($subject['teacher_name'] ?? '') !== '' ? h($subject['teacher_name']) : '<span class="enr-unassigned">Unassigned</span>' ?></td>
          <?php if ($split): $sp = LecLab::ofRow($subject); $splitTotals[0] += $sp[0]; $splitTotals[1] += $sp[1]; ?><td class="enr-num"><?= h(LecLab::text($sp[0])) ?></td><td class="enr-num"><?= h(LecLab::text($sp[1])) ?></td><?php endif; ?>
          <td class="enr-num"><?= h($unitsText($subject['units'])) ?></td>
          <?php if (!$split): ?><td class="enr-num"><?= Money::fmt($sf['rate_cents']) ?></td><?php endif; ?>
          <td class="enr-num"><?= Money::fmt($sf['amount_cents']) ?></td>
          <td><?= h($r['section_name']) ?></td>
        </tr><?php endforeach; ?>
        <?php if (!$subs): ?><tr><td colspan="<?= $split ? 8 : 7 ?>" class="enr-empty">No subjects recorded.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr><th colspan="3">Total Units:</th><?php if ($split): ?><th class="enr-num"><?= h(LecLab::text($splitTotals[0])) ?></th><th class="enr-num"><?= h(LecLab::text($splitTotals[1])) ?></th><?php endif; ?><th class="enr-num"><?= h($unitsText($r['total_units'])) ?></th><?php if (!$split): ?><th></th><?php endif; ?><th class="enr-num"><?= Money::fmt(array_sum(array_column($subjectFees, 'amount_cents'))) ?></th><th></th></tr></tfoot>
    </table>
  </section>
  <?php endif; ?>

  <section class="enr-section enr-assessment-section">
    <table class="enr-table enr-fee-table">
      <thead><tr><th>Assessment</th><th class="enr-num">Amount</th><th class="enr-num">Total</th></tr></thead>
      <tbody>
        <?php if (!$items): ?><tr><td colspan="3" class="enr-empty">No fees have been assessed.</td></tr><?php endif; ?>
        <?php foreach ($groups as $category => $lines): $groupTotal = 0; foreach ($lines as $line) $groupTotal += Money::cents($line['amount']); ?>
          <tr class="enr-category"><th colspan="3"><?= h($category) ?></th></tr>
          <?php foreach ($lines as $line):
              $cents = Money::cents($line['amount']);
              $quantity = preg_replace('/\.0$/', '', (string) $line['quantity']) ?? (string) $line['quantity'];
              $hasQuantity = !in_array($line['charge_type'], ['ADJUSTMENT', 'DISCOUNT'], true);
          ?>
          <tr class="enr-fee-line">
            <td><?= h($line['label_name']) ?><?php if ($editable && $line['charge_type'] === 'ADJUSTMENT'): ?> <span class="d-print-none"><?= post_button('registration', ['id' => $id], ['action' => 'remove_adj', 'item_id' => $line['id']], '<i class="bi bi-x-lg"></i>', 'btn-sm btn-link text-danger p-0', 'Remove this adjustment?') ?></span>
              <?php elseif ($editable && isset($labelIdByName[$line['label_name']])): $labelId = (int) $labelIdByName[$line['label_name']]; ?>
                <?php if (isset($feeChoices[$labelId])): ?><span class="badge text-bg-info me-1 d-print-none">for this student</span><?php endif; ?>
                <span class="d-print-none"><?= post_button('registration', ['id' => $id], ['action' => 'remove_label', 'label_id' => $labelId], '<i class="bi bi-x-lg"></i>', 'btn-sm btn-link text-danger p-0', 'Take ' . $line['label_name'] . ' off this student\'s assessment?') ?></span>
              <?php endif; ?>
              <?php if ($showBreakdown && $hasQuantity): ?><small class="enr-breakdown"><?= h($quantity) ?> × ₱<?= Money::fmt(Money::cents($line['unit_amount'])) ?> / unit</small><?php endif; ?>
            </td>
            <td class="enr-num"><?= $amountText($cents) ?></td><td></td>
          </tr>
          <?php endforeach; ?>
          <tr class="enr-subtotal"><td colspan="2">Subtotal · <?= h($category) ?></td><td class="enr-num"><?= $amountText($groupTotal) ?></td></tr>
        <?php endforeach; ?>
        <tr class="enr-grand-total"><th colspan="2">TOTAL ASSESSMENT</th><th class="enr-num"><?= $amountText(Money::cents($r['total_amount'])) ?></th></tr>
        <?php if ($carriedForward['cents'] > 0): foreach ($carriedForward['items'] as $oldBalance): $old = $oldBalance['registration']; ?>
          <tr class="enr-fee-line"><td colspan="2"><strong>Existing balance carried forward</strong><small class="enr-breakdown"><?= h($old['school_year'] . ' ' . $old['term'] . ' · ' . $old['reg_no']) ?></small></td><td class="enr-num"><strong>₱<?= Money::fmt($oldBalance['balance_cents']) ?></strong></td></tr>
        <?php endforeach; ?><tr class="enr-grand-total"><th colspan="2">TOTAL PAYABLE INCLUDING APPROVED PRIOR BALANCE</th><th class="enr-num">₱<?= Money::fmt(Registration::balance($r) + $carriedForward['cents']) ?></th></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <?php $cls = 'enr'; require __DIR__ . '/soa_plan_box.php'; ?>

  <?php if (Settings::on('asm_show_payments')): ?>
  <section class="enr-financial-grid">
    <div>
      <table class="enr-table enr-summary-table">
        <thead><tr><th>Summary</th><th class="enr-num">Amount</th><th class="enr-num">Total</th></tr></thead>
        <tbody>
          <tr><td>Assessment charges</td><td class="enr-num"><?= $amountText($grossCents) ?></td><td></td></tr>
          <tr><td>Scholarships / Less</td><td class="enr-num"><?= $lessCents ? '−₱' . Money::fmt($lessCents) : '—' ?></td><td></td></tr>
          <tr class="enr-summary-net"><th colspan="2">Current Assessment</th><th class="enr-num"><?= $amountText(Money::cents($r['total_amount'])) ?></th></tr>
          <tr><td colspan="2">Payments Posted</td><td class="enr-num"><?= $amountText(-Money::cents($r['amount_paid'])) ?></td></tr>
          <tr class="enr-balance"><th colspan="2">CURRENT RECEIVABLE</th><th class="enr-num">₱<?= Money::fmt(Registration::balance($r)) ?></th></tr>
          <?php if ($carriedForward['cents'] > 0): ?><tr><td colspan="2">Approved prior balance</td><td class="enr-num">₱<?= Money::fmt($carriedForward['cents']) ?></td></tr><tr class="enr-balance"><th colspan="2">TOTAL PAYABLE</th><th class="enr-num">₱<?= Money::fmt(Registration::balance($r) + $carriedForward['cents']) ?></th></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div>
      <table class="enr-table enr-payment-table">
        <thead><tr><th colspan="3">Payment Activity</th></tr><tr><th>Date / OR No.</th><th>Details</th><th class="enr-num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($pays as $payment): ?>
          <tr class="<?= $payment['status'] === 'VOIDED' ? 'enr-payment-void' : '' ?>">
            <td><?= h(date('M j, Y', strtotime($payment['paid_at']))) ?><small class="enr-breakdown"><?= h($payment['receipt_no']) ?></small></td>
            <td><?= h($payment['method']) ?><?php if (!empty($payment['reference_no'])): ?> · Ref <?= h($payment['reference_no']) ?><?php endif; ?><?php if (!empty($payment['remarks'])): ?><small class="enr-breakdown"><?= h($payment['remarks']) ?></small><?php endif; ?><?php if ($payment['status'] === 'VOIDED'): ?><small class="enr-breakdown">VOIDED<?= !empty($payment['void_reason']) ? ': ' . h($payment['void_reason']) : '' ?></small><?php endif; ?></td>
            <td class="enr-num"><?= $payment['status'] === 'VOIDED' ? '−₱' : '₱' ?><?= Money::fmt(Money::cents($payment['amount'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$pays): ?><tr><td colspan="3" class="enr-empty">No payments received.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if (Settings::get('asm_note') !== ''): ?><p class="enr-note"><strong>Note:</strong> <?= nl2br(h(Settings::get('asm_note'))) ?></p><?php endif; ?>
  <?php if (Settings::get('asm_sign_left') !== '' || Settings::get('asm_sign_right') !== ''): ?>
  <footer class="enr-signatures">
    <?php foreach (['asm_sign_left', 'asm_sign_right'] as $key): if (Settings::get($key) === '') continue; ?><div class="enr-sign"><span><?php if ($key === 'asm_sign_left' && SoaPreparer::name() !== ''): ?><b class="enr-sign-name"><?= h(mb_strtoupper(SoaPreparer::name())) ?></b><?php endif; ?></span><strong><?= h(Settings::get($key)) ?></strong></div><?php endforeach; ?>
  </footer>
  <?php endif; ?>
  <div class="enr-footer"><span><?= $processor !== '' ? 'Processed by: ' . h($processor) . ' · ' : '' ?><?= h($printedAt) ?></span><span class="enr-page">Page </span></div>
</div>
