<?php
/** Official receipt for library fines (printable). */
declare(strict_types=1);
$p = Library::finePayment((int) ($_GET['id'] ?? 0)) ?: throw new AppError('Receipt not found.');
$peso = fn(int $c) => '₱' . Money::fmt($c);
layout_start('O.R. ' . $p['receipt_no']);
?>
<div class="d-print-none mb-3 d-flex gap-2"><a class="btn btn-outline-secondary" href="<?= url('libfines') ?>"><i class="bi bi-arrow-left me-1"></i>Library Fines</a><button type="button" class="btn btn-primary" data-page-print><i class="bi bi-printer me-1"></i>Print</button></div>
<section class="form-section" style="max-width:640px">
  <?php require SIS_ROOT . '/app/views/print_header.php'; ?>
  <h2 class="h5 text-center my-2">OFFICIAL RECEIPT</h2>
  <?php if ($p['status'] === 'VOIDED'): ?><div class="alert alert-danger text-center fw-bold py-1">VOIDED — <?= h((string) $p['void_reason']) ?></div><?php endif; ?>
  <table class="table table-sm"><tbody>
    <tr><th style="width:35%">O.R. No.</th><td class="font-monospace"><?= h($p['receipt_no']) ?></td></tr>
    <tr><th>Date</th><td><?= h(date('F j, Y g:i A', strtotime($p['paid_at']))) ?></td></tr>
    <tr><th>Received from</th><td><?= h($p['borrower_name']) ?> (<?= h(Library::BORROWER_TYPES[$p['borrower_type']]) ?> <?= h($p['borrower_no']) ?>)</td></tr>
    <tr><th>For</th><td>Library fines<ul class="mb-0 small"><?php foreach ($p['fines'] as $f): ?><li><?= h(Library::FINE_KINDS[$f['kind']]) ?> · <?= h($f['description']) ?> — <?= $peso((int) $f['amount_cents']) ?></li><?php endforeach; ?></ul></td></tr>
    <tr><th>Amount</th><td class="fw-bold"><?= $peso((int) $p['amount_cents']) ?></td></tr>
    <tr><th>Paid by</th><td><?= h($p['method']) ?><?= $p['reference_no'] ? ' · Ref. ' . h($p['reference_no']) : '' ?></td></tr>
    <tr><th>Cashier</th><td><?= h((string) $p['cashier']) ?></td></tr>
  </tbody></table>
</section>
<script src="../library/assets/library.js?v=61" defer></script>
<?php layout_end();
