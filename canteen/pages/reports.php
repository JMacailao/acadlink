<?php
/** Canteen sales reports: totals, by day, by product (with gross profit) and by cashier; print or download CSV. */
declare(strict_types=1);
$from = In::date($_GET, 'from') ?? date('Y-m-01');
$to = In::date($_GET, 'to') ?? date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$r = Canteen::report($from, $to);
if (($_GET['download'] ?? '') === 'csv') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="canteen-sales-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product', 'Quantity sold', 'Sales', 'Cost', 'Gross profit']);
    foreach ($r['byProduct'] as $p) fputcsv($out, [$p['name'], (int) $p['qty'], Money::dec((int) $p['t']), Money::dec((int) $p['cost']), Money::dec((int) $p['t'] - (int) $p['cost'])]);
    fclose($out); exit;
}
$peso = fn(int $c) => '₱' . Money::fmt($c);
layout_start('Sales Reports');
?>
<form method="get" class="form-section row g-2 align-items-end d-print-none"><input type="hidden" name="r" value="reports">
  <div class="col-6 col-md-3"><label class="form-label small" for="rF">From</label><input class="form-control form-control-sm" type="date" id="rF" name="from" value="<?= h($from) ?>"></div>
  <div class="col-6 col-md-3"><label class="form-label small" for="rT">To</label><input class="form-control form-control-sm" type="date" id="rT" name="to" value="<?= h($to) ?>"></div>
  <div class="col-12 col-md-6 d-flex gap-2"><button class="btn btn-sm btn-primary">Show</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-page-print><i class="bi bi-printer me-1"></i>Print</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= url('reports', ['from' => $from, 'to' => $to, 'download' => 'csv']) ?>"><i class="bi bi-download me-1"></i>Products CSV</a></div>
</form>
<h2 class="h6 d-none d-print-block">Canteen sales <?= h($from) ?> to <?= h($to) ?></h2>
<div class="row g-2 mb-3">
  <?php foreach ([['Gross sales', $r['gross']], ['Refunds', -$r['refunds']], ['Net sales', $r['net']], ['Gross profit', $r['net'] - $r['cost']]] as [$l, $v]): ?>
  <div class="col-6 col-lg-3"><div class="form-section h-100 mb-0"><div class="small text-muted"><?= $l ?></div><div class="fs-4 fw-bold"><?= $peso($v) ?></div></div></div>
  <?php endforeach; ?>
</div>
<p class="small text-muted"><?= number_format($r['count']) ?> paid sales · <?= (int) $r['voided']['n'] ?> voided (<?= $peso((int) $r['voided']['t']) ?>) · Gross profit uses each item's cost when it was sold.</p>
<div class="row g-3">
  <div class="col-xl-6"><div class="form-section"><h2 class="h6">By day</h2><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Date</th><th class="text-end">Sales</th><th class="text-end">Cash</th><th class="text-end">QR</th><th class="text-end">Refunds</th><th class="text-end">Net</th></tr></thead><tbody>
    <?php foreach ($r['byDay'] as $d): $ref = (int) ($r['refundsByDay'][$d['d']] ?? 0); ?><tr><td><?= h(date('D, M j', strtotime($d['d']))) ?></td><td class="text-end"><?= (int) $d['n'] ?></td><td class="text-end"><?= $peso((int) $d['cash']) ?></td><td class="text-end"><?= $peso((int) $d['qr']) ?></td><td class="text-end"><?= $ref ? '−' . $peso($ref) : '—' ?></td><td class="text-end fw-semibold"><?= $peso((int) $d['t'] - $ref) ?></td></tr><?php endforeach; ?>
    <?php if (!$r['byDay']): ?><tr><td colspan="6" class="text-center text-muted">No sales.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="form-section"><h2 class="h6">By cashier</h2><table class="table table-sm mb-0"><thead><tr><th>Cashier</th><th class="text-end">Sales</th><th class="text-end">Amount</th></tr></thead><tbody>
    <?php foreach ($r['byCashier'] as $c): ?><tr><td><?= h((string) $c['cashier']) ?></td><td class="text-end"><?= (int) $c['n'] ?></td><td class="text-end"><?= $peso((int) $c['t']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <div class="col-xl-6"><div class="form-section"><h2 class="h6">By product</h2><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Product</th><th class="text-end">Sold</th><th class="text-end">Sales</th><th class="text-end">Profit</th></tr></thead><tbody>
    <?php foreach ($r['byProduct'] as $p): ?><tr><td><?= h($p['name']) ?></td><td class="text-end"><?= (int) $p['qty'] ?></td><td class="text-end"><?= $peso((int) $p['t']) ?></td><td class="text-end"><?= $peso((int) $p['t'] - (int) $p['cost']) ?></td></tr><?php endforeach; ?>
    <?php if (!$r['byProduct']): ?><tr><td colspan="4" class="text-center text-muted">No sales.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
<script src="assets/canteen-pages.js?v=59" defer></script>
<?php layout_end();
