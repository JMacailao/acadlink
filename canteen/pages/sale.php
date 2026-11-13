<?php
/** One sale: items, payment, history of void/refund requests, and the forms to ask for a void or refund. */
declare(strict_types=1);
/** @var bool $isPost */
$id = (int) ($_GET['id'] ?? 0);
$sale = Canteen::sale($id);
if (!$sale) throw new AppError('Sale not found.');
if (!Auth::can('canteen.supervise', 'canteen.reports') && (int) $sale['cashier_id'] !== (int) Auth::id()) throw new Forbidden('You can open only your own sales.');
if ($isPost) {
    $kind = ($_POST['action'] ?? '') === 'void' ? 'VOID' : 'REFUND';
    Canteen::request($id, $kind, array_map('intval', (array) ($_POST['qty'] ?? [])), (string) ($_POST['reason'] ?? ''));
    flash('success', ($kind === 'VOID' ? 'Void' : 'Refund') . ' request sent to the canteen supervisor.');
    redirect('sale', ['id' => $id]);
}
$peso = fn(int $c) => '₱' . Money::fmt($c);
$shiftOpen = Db::val('SELECT status FROM canteen_shifts WHERE id = ?', [(int) $sale['shift_id']]) === 'OPEN';
$pending = array_filter($sale['requests'], fn($r) => $r['status'] === 'PENDING');
$canAsk = $sale['status'] === 'POSTED' && !$pending && Auth::can('canteen.sell', 'canteen.supervise');
layout_start('Sale ' . $sale['receipt_no']);
?>
<div class="canteen-page-actions"><a class="btn btn-outline-secondary" href="<?= url('sales') ?>"><i class="bi bi-arrow-left me-1"></i>Sales</a>
  <a class="btn btn-outline-primary" href="<?= url('receipt', ['id' => $id]) ?>"><i class="bi bi-printer me-1"></i>Receipt</a>
  <?php if ($pending && Auth::can('canteen.supervise')): ?><a class="btn btn-warning" href="<?= url('approvals') ?>">Decide the request</a><?php endif; ?></div>
<div class="row g-3">
  <div class="col-lg-7"><section class="form-section">
    <h2 class="h5"><?= h($sale['receipt_no']) ?> <?= $sale['status'] === 'VOIDED' ? '<span class="badge text-bg-secondary">Voided</span>' : '<span class="badge text-bg-success">Paid</span>' ?></h2>
    <p class="small text-muted mb-2"><?= h(date('M j, Y g:i A', strtotime((string) $sale['sold_at']))) ?> · <?= h((string) $sale['cashier']) ?> · Shift #<?= (int) $sale['shift_id'] ?> · <?= h(Canteen::METHODS[$sale['method']]) ?><?= $sale['reference_no'] ? ' · Ref. ' . h($sale['reference_no']) : '' ?></p>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Amount</th></tr></thead><tbody>
    <?php foreach ($sale['items'] as $it): ?><tr><td><?= h($it['name']) ?><?= (int) $it['refunded_qty'] ? ' <small class="text-danger">(' . (int) $it['refunded_qty'] . ' refunded)</small>' : '' ?></td><td class="text-end"><?= (int) $it['qty'] ?></td><td class="text-end"><?= $peso((int) $it['price_cents']) ?></td><td class="text-end"><?= $peso((int) $it['price_cents'] * (int) $it['qty']) ?></td></tr><?php endforeach; ?>
    </tbody><tfoot><tr><th colspan="3">Total</th><th class="text-end"><?= $peso((int) $sale['total_cents']) ?></th></tr>
      <?php if ((int) $sale['refunded_cents']): ?><tr><td colspan="3">Refunded</td><td class="text-end text-danger">−<?= $peso((int) $sale['refunded_cents']) ?></td></tr><?php endif; ?></tfoot></table></div>
    <?php if ($sale['status'] === 'VOIDED'): ?><p class="small mb-0">Voided <?= h((string) $sale['voided_at']) ?>: <?= h((string) $sale['void_reason']) ?></p><?php endif; ?>
  </section>
  <?php if ($sale['requests']): ?><section class="form-section"><h2 class="h6">Requests</h2><ul class="list-unstyled small mb-0">
    <?php foreach ($sale['requests'] as $r): ?><li class="mb-2"><b><?= $r['kind'] === 'VOID' ? 'Void' : 'Refund' ?> <?= $peso((int) $r['amount_cents']) ?></b> · <?= h(ucfirst(strtolower($r['status']))) ?> · asked by <?= h((string) $r['requester']) ?> (<?= h((string) $r['requested_at']) ?>): <?= h($r['reason']) ?>
      <?php if ($r['decided_at']): ?><br>Decided by <?= h((string) $r['decider']) ?> <?= h((string) $r['decided_at']) ?><?= $r['decision_note'] ? ': ' . h($r['decision_note']) : '' ?><?php endif; ?></li><?php endforeach; ?>
  </ul></section><?php endif; ?>
  </div>
  <div class="col-lg-5">
  <?php if ($canAsk): ?>
    <?php if ($shiftOpen && !(int) $sale['refunded_cents']): ?>
    <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="void">
      <h2 class="h6">Void the whole sale</h2><p class="small text-muted">For a mistake before the shift is closed. The supervisor must approve; stock goes back.</p>
      <label class="form-label small" for="vReason">Reason</label><input class="form-control form-control-sm mb-2" id="vReason" name="reason" required minlength="8" maxlength="255" placeholder="e.g. wrong item rung up">
      <button class="btn btn-sm btn-outline-danger">Ask to void</button></form>
    <?php endif; ?>
    <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="refund">
      <h2 class="h6">Refund items</h2><p class="small text-muted">Paid back in cash from your open shift once the supervisor approves.</p>
      <?php foreach ($sale['items'] as $it): $max = (int) $it['qty'] - (int) $it['refunded_qty']; if ($max <= 0) continue; ?>
      <div class="d-flex align-items-center gap-2 mb-1"><label class="small flex-grow-1" for="rq<?= (int) $it['id'] ?>"><?= h($it['name']) ?> <span class="text-muted">(max <?= $max ?>)</span></label>
        <input class="form-control form-control-sm" style="width:80px" type="number" min="0" max="<?= $max ?>" value="0" id="rq<?= (int) $it['id'] ?>" name="qty[<?= (int) $it['id'] ?>]"></div>
      <?php endforeach; ?>
      <label class="form-label small mt-2" for="rReason">Reason</label><input class="form-control form-control-sm mb-2" id="rReason" name="reason" required minlength="8" maxlength="255" placeholder="e.g. spoiled juice returned">
      <button class="btn btn-sm btn-outline-primary">Ask for refund</button></form>
  <?php elseif ($pending): ?><div class="alert alert-warning">A request for this sale is waiting for the canteen supervisor.</div><?php endif; ?>
  </div>
</div>
<?php layout_end();
