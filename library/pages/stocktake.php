<?php
/** Stocktake: start a count (whole library or one shelf/location), scan every copy on the shelves, then close it. */
declare(strict_types=1);
/** @var bool $isPost */
$open = Db::one("SELECT * FROM library_stocktakes WHERE status = 'OPEN' ORDER BY id DESC LIMIT 1");
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'start') { Library::startStocktake((string) ($_POST['name'] ?? ''), (string) ($_POST['location'] ?? '')); flash('success', 'Stocktake started. Scan the copies on the shelves.'); }
    elseif ($a === 'scan' && $open) {
        $msgs = [];
        foreach (preg_split('/[\s,]+/', (string) ($_POST['barcodes'] ?? '')) ?: [] as $code) if ($code !== '') { [$st, $msg] = Library::scan((int) $open['id'], $code); $msgs[$st === 'ok' ? 'success' : ($st === 'repeat' ? 'info' : 'warning')][] = $msg; }
        foreach ($msgs as $type => $list) flash($type, implode("\n", array_slice($list, 0, 30)) . (count($list) > 30 ? "\n…and " . (count($list) - 30) . ' more' : ''));
    } elseif ($a === 'close' && $open) { $r = Library::closeStocktake((int) $open['id'], !empty($_POST['mark_missing'])); flash('success', 'Stocktake closed. ' . $r['result']); }
    redirect('stocktake');
}
$locations = Db::col("SELECT DISTINCT location FROM library_copies WHERE location IS NOT NULL AND location <> '' ORDER BY location");
$past = Db::all("SELECT s.*, u.full_name FROM library_stocktakes s LEFT JOIN sis_users u ON u.id = s.started_by WHERE s.status = 'CLOSED' ORDER BY s.id DESC LIMIT 20");
layout_start('Stocktake');
lib_page_head('clipboard-check', 'Stocktake', 'Scan every copy on the shelves to find what is missing.');
lib_tabs(array_filter(['catalog' => 'Titles', 'acquisitions' => 'Acquisitions', 'stocktake' => 'Stocktake', 'labels' => 'Barcode labels'], fn($r) => Auth::can(...ROUTES[$r][1]), ARRAY_FILTER_USE_KEY), 'stocktake');
if (!$open): ?>
<form method="post" class="form-section" style="max-width:560px"><?= csrf_field() ?><input type="hidden" name="action" value="start">
  <h2 class="h6">Start a stocktake</h2>
  <label class="form-label small" for="sN">Name</label><input class="form-control mb-2" id="sN" name="name" maxlength="120" value="Stocktake <?= h(date('F Y')) ?>">
  <label class="form-label small" for="sL">Shelf / location (blank = the whole library)</label>
  <input class="form-control mb-2" id="sL" name="location" maxlength="80" list="sLocs"><datalist id="sLocs"><?php foreach ($locations as $l): ?><option value="<?= h($l) ?>"><?php endforeach; ?></datalist>
  <button class="btn btn-primary">Start</button></form>
<?php else: $r = Library::stocktakeReport((int) $open['id']); ?>
<div class="row g-3">
  <div class="col-xl-6"><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="scan">
    <h2 class="h6"><?= h($open['name']) ?><?= $open['location'] ? ' · ' . h($open['location']) : ' · whole library' ?></h2>
    <p class="small text-muted mb-2">Scanned <b><?= $r['scanned'] ?></b> · not seen yet <b><?= count($r['missing']) ?></b></p>
    <label class="form-label small" for="sB">Scan barcodes (one after another; Enter saves)</label>
    <textarea class="form-control font-monospace" id="sB" name="barcodes" rows="3" autofocus data-scan-batch placeholder="LB0000001"></textarea>
    <button class="btn btn-primary mt-2">Save scans</button></form>
    <form method="post" class="form-section" data-confirm="Close the stocktake? This can't be reopened."><?= csrf_field() ?><input type="hidden" name="action" value="close">
      <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="mark_missing" value="1" checked> Mark the <?= count($r['missing']) ?> copies not seen as <b>Missing</b></label>
      <p class="small text-muted">Missing copies scanned during this count become Available again. Copies scanned while on loan should be returned at the desk.</p>
      <button class="btn btn-outline-primary">Close stocktake</button></form></div>
  <div class="col-xl-6"><section class="form-section"><h2 class="h6">Not seen yet (<?= count($r['missing']) ?>)</h2><div class="table-responsive" style="max-height:420px"><table class="table table-sm mb-0"><tbody>
    <?php foreach (array_slice($r['missing'], 0, 300) as $c): ?><tr><td class="font-monospace small"><?= h($c['barcode']) ?></td><td><?= h($c['title']) ?></td><td class="small text-muted"><?= h((string) $c['location']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <?php if ($r['odd']): ?><section class="form-section"><h2 class="h6">Scanned, but the system says otherwise</h2><ul class="small mb-0"><?php foreach ($r['odd'] as $c): ?><li><?= h($c['title']) ?> (<?= h($c['barcode']) ?>): <?= h(Library::COPY_STATUS[$c['status']]) ?></li><?php endforeach; ?></ul></section><?php endif; ?></div>
</div>
<?php endif; ?>
<?php if ($past): ?><h2 class="h6 mt-3">Past stocktakes</h2><div class="table-responsive form-section"><table class="table table-sm mb-0"><tbody>
<?php foreach ($past as $p): ?><tr><td><?= h($p['name']) ?></td><td><?= h((string) $p['location'] ?: 'Whole library') ?></td><td class="small"><?= h(date('M j, Y', strtotime((string) $p['closed_at']))) ?></td><td class="small"><?= h((string) $p['result']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<script src="assets/library.js?v=61" defer></script>
<?php layout_end();
