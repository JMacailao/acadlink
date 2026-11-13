<?php
/** Official receipt of one payment, printed as set in Settings (title, paper, copies, note, signature). */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$p = Db::one('SELECT p.*, r.reg_no, r.student_name, r.student_number, r.school_year, r.term, r.section_name, r.program, r.year_level, r.total_amount, r.amount_paid, u.full_name,
                     COALESCE(se.template, \'STANDARD\') AS template, se.code AS series_code, COALESCE(se.allow_edit, 1) AS allow_edit
                FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id JOIN sis_users u ON u.id = p.received_by LEFT JOIN sis_or_series se ON se.id = p.series_id
               WHERE p.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$p) throw new AppError('Receipt not found.');
if (!Auth::can('payments.view') && Auth::studentNumber() !== $p['student_number']) throw new Forbidden('Not your receipt.');
$embed = isset($_GET['embed']);
$canEditNo = Auth::can('payments.void') && $p['status'] === 'POSTED' && (int) $p['allow_edit'] && !AccountingWorkflow::receiptLocked((int)$p['id']) && !Db::val("SELECT id FROM sis_journal WHERE source='PAYMENT' AND source_id=? AND reversed_by_id IS NOT NULL",[$p['id']]);
if ($isPost) {
    if (!$canEditNo || ($_POST['action'] ?? '') !== 'or_no') throw new Forbidden('This O.R. number can\'t be edited.');
    Cashier::editNumber((int) $p['id'], (string) ($_POST['or_no'] ?? ''), (string) ($_POST['reason'] ?? ''));
    flash('success', 'O.R. number changed.');
    redirect('receipt', ['id' => $p['id']] + ($embed ? ['embed' => 1] : []));
}
$left = Money::cents($p['total_amount']) - Money::cents($p['amount_paid']);
$paper = Settings::get('or_paper');
$copies = Settings::get('or_copies') === '2' ? ['Original · Payer’s copy', 'Duplicate · School’s copy'] : [''];

$receipt = function (string $copyLabel) use ($p, $left): void {
    $docTitle = Settings::get('or_title');
    $docRight = '<div class="small text-muted">No.</div><div class="h5 font-monospace mb-0">' . h($p['receipt_no']) . '</div>' . ($copyLabel !== '' ? '<div class="small text-muted">' . h($copyLabel) . '</div>' : '');
    ?>
  <div class="receipt-copy">
    <?php if ($p['status'] === 'VOIDED'): ?><div class="alert alert-danger text-center fw-bold py-1">VOID — <?= h($p['void_reason']) ?></div><?php endif; ?>
    <?php require SIS_ROOT . '/app/views/print_header.php'; ?>
    <table class="table table-sm receipt-table">
      <tr><th>Date</th><td><?= h(date('F j, Y g:i A', strtotime($p['paid_at']))) ?></td></tr>
      <tr><th>Received from</th><td><strong><?= h($p['student_name']) ?></strong> (<?= h($p['student_number']) ?>)</td></tr>
      <tr><th>For</th><td><?= h($p['reg_no'] . ' · ' . $p['school_year'] . ' ' . $p['term'] . ' · ' . $p['section_name']) ?></td></tr>
      <tr><th>Payment</th><td><?= h($p['method']) ?><?= $p['reference_no'] ? ' · Ref ' . h($p['reference_no']) : '' ?></td></tr>
      <?php if ($p['remarks']): ?><tr><th>Remarks</th><td><?= h($p['remarks']) ?></td></tr><?php endif; ?>
      <tr class="receipt-amount"><th>Amount</th><td>₱<?= Money::fmt(Money::cents($p['amount'])) ?></td></tr>
      <?php if (Settings::on('or_show_words')): ?><tr><th>In words</th><td class="fst-italic"><?= h(Settings::pesosInWords(Money::cents($p['amount']))) ?></td></tr><?php endif; ?>
      <?php if (Settings::on('or_show_balance')): ?><tr><th>Remaining balance</th><td>₱<?= Money::fmt(max(0, $left)) ?></td></tr><?php endif; ?>
    </table>
    <?php if (Settings::get('or_note') !== ''): ?><div class="print-note"><?= nl2br(h(Settings::get('or_note'))) ?></div><?php endif; ?>
    <div class="print-signs">
      <div class="print-sign"><span class="line"></span><span><?= h($p['full_name']) ?><?= Settings::get('or_sign') !== '' ? ' · ' . h(Settings::get('or_sign')) : '' ?></span></div>
    </div>
  </div>
<?php };

// "Template Official Receipt": the fields of the pre-printed official receipt form (one per sheet)
$official = function () use ($p): void {
    $amt = Money::cents($p['amount']);
    $address = (string) Db::val('SELECT address FROM sis_admissions WHERE student_number = ? AND address IS NOT NULL ORDER BY id DESC LIMIT 1', [$p['student_number']]);
    $fields = ['Date' => date('m/d/Y', strtotime($p['paid_at'])), 'Acct ID Per Sem.' => $p['reg_no'], 'Payment ID' => $p['receipt_no'], 'Student Name' => $p['student_name'],
               'Address' => $address, 'Course' => (string) ($p['program'] ?: $p['section_name']), 'Year Level' => $p['year_level'], 'Total Amount' => Money::fmt($amt)];
    ?>
  <div class="ofr">
    <?php if ($p['status'] === 'VOIDED'): ?><div class="ofr-void" aria-label="Voided">VOID</div><?php endif; ?>
    <table class="ofr-fields">
      <?php foreach ($fields as $label => $val): ?><tr><th scope="row"><?= h($label) ?>:</th><td><?= h($val) ?></td></tr><?php endforeach; ?>
      <tr class="ofr-words"><th scope="row">Amount in Words:</th><td><?= h(Settings::pesosInWords($amt)) ?></td></tr>
    </table>
    <table class="ofr-totals">
      <tr><th scope="row">Total Vat-Exempt Sales:</th><td><?= Money::fmt($amt) ?></td></tr>
      <tr><th scope="row">Less Discount:</th><td>0.00</td></tr>
      <tr><th scope="row">Less Withholding Tax:</th><td>0.00</td></tr>
      <tr class="ofr-due"><th scope="row">TOTAL AMOUNT DUE:</th><td><?= Money::fmt($amt) ?></td></tr>
    </table>
    <div class="ofr-cashier"><span class="ofr-cashier-name"><?= h($p['full_name']) ?></span><span>Cashier</span></div>
  </div>
<?php };

layout_start('Receipt ' . $p['receipt_no'], $embed);
?>
<style>@media print { @page { size: <?= Settings::pageSize($paper) ?>; margin: <?= $paper === 'THERMAL' ? '3mm' : '10mm' ?>; } }</style>
<?php if ($embed): ?><div class="embed-wrap"><?php endif; ?>
<div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
  <button class="btn btn-primary" data-print><i class="bi bi-printer"></i> Print receipt</button>
  <?php if (!$embed): ?>
  <?php if (Auth::can('payments.view')): ?>
  <a class="btn btn-outline-secondary" href="<?= url('cashier', ['reg' => $p['registration_id']]) ?>"><i class="bi bi-arrow-left"></i> Back to this student</a>
  <?php if (Auth::can('payments.record')): ?><a class="btn btn-success" href="<?= url('cashier') ?>"><i class="bi bi-person-plus"></i> Next student</a><?php endif; ?>
  <?php else: ?><a class="btn btn-outline-secondary" href="<?= url('my') ?>"><i class="bi bi-arrow-left"></i> Back</a><?php endif; ?>
  <?php endif; ?>
</div>
<?php if ($canEditNo): ?>
<details class="form-section d-print-none mb-3 py-2"><summary class="small fw-semibold"><i class="bi bi-pencil-square me-1"></i>Edit O.R. No. <span class="font-monospace"><?= h($p['receipt_no']) ?></span></summary>
  <form method="post" class="row g-2 mt-1" action="<?= url('receipt', ['id' => $p['id']] + ($embed ? ['embed' => 1] : [])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="or_no">
    <div class="col-sm-4"><label class="form-label small" for="rcOrNo">New O.R. No.</label><input class="form-control form-control-sm font-monospace" id="rcOrNo" name="or_no" required maxlength="30" value="<?= h($p['receipt_no']) ?>"></div>
    <div class="col-sm-5"><label class="form-label small" for="rcOrWhy">Reason</label><input class="form-control form-control-sm" id="rcOrWhy" name="reason" required maxlength="255"></div>
    <div class="col-sm-3 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100">Save O.R. No.</button></div>
    <div class="col-12 form-text mt-0">Each O.R. number can be used only once.</div>
  </form></details>
<?php endif; ?>
<?php if (!$embed && Auth::can('payments.view') && $p['status'] === 'POSTED'): ?>
<div class="alert alert-<?= $left > 0 ? 'warning' : 'success' ?> d-print-none py-2"><?= $left > 0 ? 'Remaining balance: <strong>₱' . Money::fmt($left) . '</strong>. The student can pay the rest later.' : '<i class="bi bi-check2-circle"></i> Fully paid for this term.' ?></div>
<?php endif; ?>
<?php if ($p['template'] === 'OFFICIAL'): ?>
<div class="doc-sheet ofr-sheet paper-<?= h(strtolower($paper)) ?>"><?php $official(); ?></div>
<?php else: ?>
<div class="doc-sheet receipt-sheet paper-<?= h(strtolower($paper)) ?>">
  <?php foreach ($copies as $i => $label): if ($i > 0): ?><div class="receipt-cut" aria-hidden="true"><span>✂ cut here</span></div><?php endif; $receipt($label); endforeach; ?>
</div>
<?php endif; ?>
<?php if ($embed): ?></div><?php endif; ?>
<?php layout_end($embed);
