<?php
/** Library reports: circulation, overdue, fines (charged, collected, waived, unpaid), most borrowed titles, collection status. */
declare(strict_types=1);
$from = In::date($_GET, 'from') ?? date('Y-m-01');
$to = In::date($_GET, 'to') ?? date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$r = Library::report($from, $to);
$peso = fn(int $c) => '₱' . Money::fmt($c);
layout_start('Library Reports');
lib_page_head('bar-chart', 'Reports', 'Circulation, fines and the collection for a period.');
?>
<form method="get" class="form-section row g-2 align-items-end d-print-none"><input type="hidden" name="r" value="reports">
  <div class="col-6 col-md-3"><label class="form-label small" for="rF">From</label><input class="form-control form-control-sm" type="date" id="rF" name="from" value="<?= h($from) ?>"></div>
  <div class="col-6 col-md-3"><label class="form-label small" for="rT">To</label><input class="form-control form-control-sm" type="date" id="rT" name="to" value="<?= h($to) ?>"></div>
  <div class="col-md-6 d-flex gap-2"><button class="btn btn-sm btn-primary">Show</button><button type="button" class="btn btn-sm btn-outline-secondary" data-page-print><i class="bi bi-printer me-1"></i>Print</button></div>
</form>
<div class="row g-2 mb-3">
<?php foreach ([['Check-outs', number_format($r['loans'])], ['Returns', number_format($r['returns'])], ['On loan now', number_format($r['open'])], ['Overdue now', number_format($r['overdue'])],
    ['Fines charged', $peso($r['charged'])], ['Fines collected', $peso($r['collected'])], ['Waived', $peso($r['waived'])], ['Unpaid now', $peso($r['unpaid'])]] as [$l, $v]): ?>
  <div class="col-6 col-lg-3"><div class="form-section h-100 mb-0"><div class="small text-muted"><?= $l ?></div><div class="fs-4 fw-bold"><?= $v ?></div></div></div>
<?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-xl-7"><div class="form-section"><h2 class="h6">Most borrowed</h2><table class="table table-sm mb-0"><thead><tr><th>Title</th><th>Author(s)</th><th class="text-end">Loans</th></tr></thead><tbody>
    <?php foreach ($r['top'] as $t): ?><tr><td><a href="<?= url('catalog', ['id' => $t['id']]) ?>"><?= h($t['title']) ?></a></td><td><?= h($t['authors']) ?></td><td class="text-end"><?= (int) $t['n'] ?></td></tr><?php endforeach; ?>
    <?php if (!$r['top']): ?><tr><td colspan="3" class="text-muted text-center">No loans in this period.</td></tr><?php endif; ?></tbody></table></div></div>
  <div class="col-xl-5">
    <div class="form-section"><h2 class="h6">Borrowers</h2><table class="table table-sm mb-0"><tbody><?php foreach ($r['byType'] as $b): ?><tr><td><?= h(Library::BORROWER_TYPES[$b['borrower_type']]) ?>s</td><td class="text-end"><?= (int) $b['people'] ?> people</td><td class="text-end"><?= (int) $b['n'] ?> loans</td></tr><?php endforeach; ?></tbody></table></div>
    <div class="form-section"><h2 class="h6">Collection (<?= number_format($r['titles']) ?> titles)</h2><table class="table table-sm mb-0"><tbody><?php foreach ($r['collection'] as $c): ?><tr><td><?= h(Library::COPY_STATUS[$c['status']]) ?></td><td class="text-end"><?= (int) $c['n'] ?> copies</td></tr><?php endforeach; ?></tbody></table></div>
  </div>
</div>
<script src="assets/library.js?v=61" defer></script>
<?php layout_end();
