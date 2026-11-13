<?php
/** Barcode labels (Code 39) for copies: recent copies, a title's copies, or typed barcodes; print on label sheets. */
declare(strict_types=1);
$codes = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($_GET['codes'] ?? '')) ?: [])));
$title = (int) ($_GET['title'] ?? 0);
$since = In::date($_GET, 'since');
if ($codes) $copies = Db::all('SELECT c.barcode, c.location, t.title, t.call_no FROM library_copies c JOIN library_titles t ON t.id = c.title_id WHERE c.barcode IN (' . implode(',', array_fill(0, count($codes), '?')) . ') ORDER BY c.barcode', array_map('strtoupper', $codes));
elseif ($title) $copies = Db::all('SELECT c.barcode, c.location, t.title, t.call_no FROM library_copies c JOIN library_titles t ON t.id = c.title_id WHERE t.id = ? ORDER BY c.barcode', [$title]);
else $copies = Db::all('SELECT c.barcode, c.location, t.title, t.call_no FROM library_copies c JOIN library_titles t ON t.id = c.title_id WHERE c.created_at >= ? ORDER BY c.barcode LIMIT 300', [($since ?? date('Y-m-d')) . ' 00:00:00']);
layout_start('Barcode Labels');
?>
<form method="get" class="form-section d-print-none row g-2 align-items-end"><input type="hidden" name="r" value="labels">
  <div class="col-md-3"><label class="form-label small" for="lS">Copies added since</label><input class="form-control form-control-sm" type="date" id="lS" name="since" value="<?= h($since ?? date('Y-m-d')) ?>"></div>
  <div class="col-md-6"><label class="form-label small" for="lC">…or these barcodes (separate with spaces)</label><input class="form-control form-control-sm font-monospace" id="lC" name="codes" value="<?= h(implode(' ', $codes)) ?>"></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-outline-secondary">Show</button><button type="button" class="btn btn-sm btn-primary" data-page-print><i class="bi bi-printer me-1"></i>Print <?= count($copies) ?></button></div>
</form>
<div class="lib-labels">
<?php foreach ($copies as $c): ?><div class="lib-label"><div class="lib-label-title"><?= h(mb_strimwidth($c['title'], 0, 42, '…')) ?></div><?= Library::barcodeSvg($c['barcode']) ?><div class="lib-label-code"><?= h($c['barcode']) ?><?= $c['call_no'] ? ' · ' . h($c['call_no']) : '' ?></div></div><?php endforeach; ?>
</div>
<?php if (!$copies): ?><p class="text-muted">No copies to label. Pick a date or type barcodes.</p><?php endif; ?>
<script src="assets/library.js?v=60" defer></script>
<?php layout_end();
