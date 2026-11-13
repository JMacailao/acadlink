<?php
/** Sales and receipts: find a sale by receipt, reference or date; re-print, void or refund it. */
declare(strict_types=1);
$from = In::date($_GET, 'from') ?? date('Y-m-d');
$to = In::date($_GET, 'to') ?? $from;
$f = ['from' => $from, 'to' => $to, 'q' => trim((string) ($_GET['q'] ?? '')), 'method' => (string) ($_GET['method'] ?? ''), 'status' => in_array($_GET['status'] ?? '', ['POSTED', 'VOIDED'], true) ? $_GET['status'] : ''];
// Cashiers see their own sales; supervisors and report viewers see everyone's
if (!Auth::can('canteen.supervise', 'canteen.reports')) $f['cashier'] = Auth::id();
$rows = Canteen::sales($f);
$peso = fn(int $c) => '₱' . Money::fmt($c);
layout_start('Sales & Receipts');
?>
<form method="get" class="form-section row g-2 align-items-end">
  <input type="hidden" name="r" value="sales">
  <div class="col-6 col-md-2"><label class="form-label small" for="sFrom">From</label><input class="form-control form-control-sm" type="date" id="sFrom" name="from" value="<?= h($from) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small" for="sTo">To</label><input class="form-control form-control-sm" type="date" id="sTo" name="to" value="<?= h($to) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small" for="sMethod">Payment</label><select class="form-select form-select-sm" id="sMethod" name="method"><option value="">Any</option><?php foreach (Canteen::METHODS as $k => $v): ?><option value="<?= $k ?>"<?= $f['method'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small" for="sStatus">Status</label><select class="form-select form-select-sm" id="sStatus" name="status"><option value="">Any</option><option value="POSTED"<?= $f['status'] === 'POSTED' ? ' selected' : '' ?>>Paid</option><option value="VOIDED"<?= $f['status'] === 'VOIDED' ? ' selected' : '' ?>>Voided</option></select></div>
  <div class="col-12 col-md-3"><label class="form-label small" for="sQ">Receipt, reference or customer</label><input class="form-control form-control-sm" id="sQ" name="q" value="<?= h($f['q']) ?>"></div>
  <div class="col-12 col-md-1"><button class="btn btn-sm btn-primary w-100">Show</button></div>
</form>
<div class="table-responsive form-section"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Receipt</th><th>Date and time</th><th>Cashier</th><th>Payment</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): ?>
    <tr><td class="font-monospace"><?= h($s['receipt_no']) ?></td><td><?= h(date('M j, g:i A', strtotime((string) $s['sold_at']))) ?></td><td><?= h((string) $s['cashier']) ?></td>
      <td><?= h(Canteen::METHODS[$s['method']]) ?><?= $s['reference_no'] ? ' <small class="text-muted">' . h($s['reference_no']) . '</small>' : '' ?></td>
      <td class="text-end font-monospace"><?= $peso((int) $s['total_cents']) ?><?= (int) $s['refunded_cents'] ? '<br><small class="text-danger">−' . $peso((int) $s['refunded_cents']) . ' refunded</small>' : '' ?></td>
      <td><?= $s['status'] === 'VOIDED' ? '<span class="badge text-bg-secondary">Voided</span>' : '<span class="badge text-bg-success">Paid</span>' ?><?= (int) $s['pending'] ? ' <span class="badge text-bg-warning">Request waiting</span>' : '' ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= url('sale', ['id' => $s['id']]) ?>">Open</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No sales in this period.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php layout_end();
