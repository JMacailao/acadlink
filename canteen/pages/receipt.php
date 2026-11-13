<?php
/** A canteen receipt: on screen with Print and New order, and as an 80 mm slip when printed. */
declare(strict_types=1);
$sale = Canteen::sale((int) ($_GET['id'] ?? 0));
if (!$sale) throw new AppError('Receipt not found.');
$peso = fn(int $c) => '₱' . Money::fmt($c);
$name = Canteen::setting('name') ?: School::brand() . ' Canteen';
layout_start('Receipt ' . $sale['receipt_no']);
?>
<div class="canteen-page-actions d-print-none">
  <?php if (Auth::can('canteen.sell')): ?><a class="btn btn-primary" href="<?= url('dashboard') ?>" id="crNew"><i class="bi bi-plus-lg me-1"></i>New order</a><?php endif; ?>
  <button type="button" class="btn btn-outline-primary" data-canteen-print><i class="bi bi-printer me-1"></i>Print receipt</button>
  <a class="btn btn-outline-secondary" href="<?= url('sale', ['id' => $sale['id']]) ?>"><i class="bi bi-receipt me-1"></i>Sale details</a>
</div>
<article class="creceipt" aria-label="Receipt">
  <h1><?= h($name) ?></h1>
  <?php if (!in_array(School::name(), ['School Information System', 'AcadLink', $name], true)): ?><div class="c"><?= h(School::name()) ?></div><?php endif; ?>
  <hr>
  <table><tr><td>Receipt</td><td class="r"><?= h($sale['receipt_no']) ?></td></tr>
    <tr><td>Date</td><td class="r"><?= h(date('M j, Y g:i A', strtotime((string) $sale['sold_at']))) ?></td></tr>
    <tr><td>Cashier</td><td class="r"><?= h((string) $sale['cashier']) ?></td></tr></table>
  <?php if ($sale['status'] === 'VOIDED'): ?><div class="void">VOIDED</div><?php endif; ?>
  <hr>
  <table>
  <?php foreach ($sale['items'] as $it): ?>
    <tr><td colspan="2"><?= h($it['name']) ?></td></tr>
    <tr><td>&nbsp;&nbsp;<?= (int) $it['qty'] ?> × <?= Money::fmt((int) $it['price_cents']) ?><?= (int) $it['refunded_qty'] ? ' (' . (int) $it['refunded_qty'] . ' refunded)' : '' ?></td><td class="r"><?= Money::fmt((int) $it['price_cents'] * (int) $it['qty']) ?></td></tr>
  <?php endforeach; ?>
  </table>
  <hr>
  <table>
    <tr><td><b>TOTAL</b></td><td class="r"><b><?= $peso((int) $sale['total_cents']) ?></b></td></tr>
    <?php if ($sale['method'] === 'CASH'): ?>
    <tr><td>Cash</td><td class="r"><?= $peso((int) $sale['tendered_cents']) ?></td></tr>
    <tr><td>Change</td><td class="r"><?= $peso((int) $sale['change_cents']) ?></td></tr>
    <?php else: ?>
    <tr><td>QR payment</td><td class="r"><?= $peso((int) $sale['total_cents']) ?></td></tr>
    <?php if ($sale['reference_no']): ?><tr><td>Ref. no.</td><td class="r"><?= h($sale['reference_no']) ?></td></tr><?php endif; ?>
    <?php endif; ?>
    <?php if ((int) $sale['refunded_cents']): ?><tr><td>Refunded</td><td class="r">-<?= $peso((int) $sale['refunded_cents']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <div class="c"><?= h(Canteen::setting('receipt_footer') ?: 'Thank you!') ?></div>
  <div class="c small">This is not an official receipt.</div>
</article>
<script src="assets/canteen-pages.js?v=59" defer></script>
<?php layout_end();
