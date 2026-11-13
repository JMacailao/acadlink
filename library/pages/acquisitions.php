<?php
/** Acquisitions: order titles from a supplier, then receive them (copies are created with barcodes and prices). */
declare(strict_types=1);
/** @var bool $isPost */
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'order') { $id = Library::saveAcquisition($_POST); flash('success', 'Order saved.'); redirect('acquisitions', ['id' => $id]); }
    if ($a === 'receive') { $codes = Library::receive((int) $_POST['id'], array_map('intval', (array) ($_POST['got'] ?? [])), $_POST); flash('success', count($codes) . ' copy(ies) added to the catalog: ' . implode(', ', array_slice($codes, 0, 12)) . (count($codes) > 12 ? '…' : '') . '.'); redirect('acquisitions', ['id' => (int) $_POST['id']]); }
    if ($a === 'cancel') { Library::cancelAcquisition((int) $_POST['id']); flash('success', 'Order cancelled.'); redirect('acquisitions'); }
    throw new AppError('Unknown action.');
}
$peso = fn(int $c) => '₱' . Money::fmt($c);
$titles = Db::all('SELECT id, title, authors FROM library_titles ORDER BY title LIMIT 3000');
$opts = '<option value="">— title —</option>' . implode('', array_map(fn($t) => '<option value="' . (int) $t['id'] . '">' . h($t['title'] . ($t['authors'] ? ' — ' . $t['authors'] : '')) . '</option>', $titles));
$id = (int) ($_GET['id'] ?? 0);
layout_start('Acquisitions');
lib_page_head('truck', 'Acquisitions', 'Order titles from suppliers; receiving adds the copies with barcodes and prices.');
lib_tabs(array_filter(['catalog' => 'Titles', 'acquisitions' => 'Acquisitions', 'stocktake' => 'Stocktake', 'labels' => 'Barcode labels'], fn($r) => Auth::can(...ROUTES[$r][1]), ARRAY_FILTER_USE_KEY), 'acquisitions');
if ($id):
    $a = Db::one('SELECT * FROM library_acquisitions WHERE id = ?', [$id]) ?: throw new AppError('Order not found.');
    $lines = Db::all('SELECT l.*, t.title FROM library_acquisition_lines l JOIN library_titles t ON t.id = l.title_id WHERE l.acquisition_id = ? ORDER BY l.id', [$id]);
?>
<div class="mb-2"><a class="btn btn-sm btn-outline-secondary" href="<?= url('acquisitions') ?>"><i class="bi bi-arrow-left me-1"></i>Orders</a></div>
<form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="receive"><input type="hidden" name="id" value="<?= $id ?>">
  <h2 class="h5">Order #<?= $id ?> · <?= h($a['supplier']) ?> <span class="badge text-bg-<?= ['ORDERED' => 'warning', 'PARTIAL' => 'info', 'RECEIVED' => 'success', 'CANCELLED' => 'secondary'][$a['status']] ?>"><?= h(ucfirst(strtolower($a['status']))) ?></span></h2>
  <p class="small text-muted"><?= h(date('M j, Y', strtotime($a['ordered_on']))) ?><?= $a['reference_no'] ? ' · Ref. ' . h($a['reference_no']) : '' ?><?= $a['notes'] ? ' · ' . h($a['notes']) : '' ?></p>
  <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Title</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Unit cost</th><?php if (in_array($a['status'], ['ORDERED', 'PARTIAL'], true)): ?><th style="width:130px">Arrived now</th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($lines as $l): $left = (int) $l['qty'] - (int) $l['received_qty']; ?><tr><td><?= h($l['title']) ?></td><td class="text-end"><?= (int) $l['qty'] ?></td><td class="text-end"><?= (int) $l['received_qty'] ?></td><td class="text-end"><?= $peso((int) $l['unit_cost_cents']) ?></td>
    <?php if (in_array($a['status'], ['ORDERED', 'PARTIAL'], true)): ?><td><input class="form-control form-control-sm" type="number" min="0" max="<?= $left ?>" value="<?= $left ?>" name="got[<?= (int) $l['id'] ?>]" aria-label="Arrived"<?= $left ? '' : ' disabled' ?>></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody><tfoot><tr><th>Total</th><th></th><th></th><th class="text-end"><?= $peso(array_sum(array_map(fn($l) => (int) $l['qty'] * (int) $l['unit_cost_cents'], $lines))) ?></th></tr></tfoot></table></div>
  <?php if (in_array($a['status'], ['ORDERED', 'PARTIAL'], true)): ?>
  <div class="d-flex flex-wrap gap-2 align-items-end"><div><label class="form-label small" for="rLoc">Shelf / location for the new copies</label><input class="form-control form-control-sm" id="rLoc" name="location" maxlength="80"></div>
    <button class="btn btn-primary">Receive and add copies</button></div><?php endif; ?>
</form>
<?php if ($a['status'] === 'ORDERED'): ?><form method="post" data-confirm="Cancel this order?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-sm btn-outline-danger">Cancel order</button></form><?php endif; ?>
<?php else:
    $orders = Db::all('SELECT a.*, (SELECT SUM(qty) FROM library_acquisition_lines l WHERE l.acquisition_id = a.id) qty, (SELECT SUM(received_qty) FROM library_acquisition_lines l WHERE l.acquisition_id = a.id) got,
        (SELECT SUM(qty * unit_cost_cents) FROM library_acquisition_lines l WHERE l.acquisition_id = a.id) total FROM library_acquisitions a ORDER BY a.id DESC LIMIT 200'); ?>
<div class="row g-3">
  <div class="col-xl-6"><div class="table-responsive form-section"><table class="table table-sm align-middle mb-0"><thead><tr><th>#</th><th>Supplier</th><th>Date</th><th class="text-end">Items</th><th class="text-end">Total</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($orders as $o): ?><tr><td><a href="<?= url('acquisitions', ['id' => $o['id']]) ?>">#<?= (int) $o['id'] ?></a></td><td><?= h($o['supplier']) ?></td><td><?= h(date('M j, Y', strtotime($o['ordered_on']))) ?></td><td class="text-end"><?= (int) $o['got'] ?>/<?= (int) $o['qty'] ?></td><td class="text-end"><?= $peso((int) $o['total']) ?></td><td><?= h(ucfirst(strtolower($o['status']))) ?></td></tr><?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="6" class="text-center text-muted py-3">No orders yet.</td></tr><?php endif; ?></tbody></table></div></div>
  <div class="col-xl-6"><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="order">
    <h2 class="h6">New order</h2>
    <?php if (!$titles): ?><div class="alert alert-info small">Add the titles to the <a href="<?= url('catalog', ['new' => 1]) ?>">catalog</a> first, then order copies of them here.</div><?php endif; ?>
    <div class="row g-2"><div class="col-md-6"><label class="form-label small" for="oS">Supplier</label><input class="form-control form-control-sm" id="oS" name="supplier" required maxlength="150"></div>
      <div class="col-6 col-md-3"><label class="form-label small" for="oD">Date</label><input class="form-control form-control-sm" type="date" id="oD" name="ordered_on" value="<?= date('Y-m-d') ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small" for="oR">Invoice / ref.</label><input class="form-control form-control-sm" id="oR" name="reference_no" maxlength="60"></div></div>
    <table class="table table-sm mt-2 mb-1"><thead><tr><th>Title</th><th style="width:80px">Qty</th><th style="width:110px">Unit cost ₱</th></tr></thead><tbody>
    <?php for ($i = 0; $i < 6; $i++): ?><tr><td><select class="form-select form-select-sm" name="lines[<?= $i ?>][title_id]" aria-label="Title <?= $i + 1 ?>"><?= $opts ?></select></td><td><input class="form-control form-control-sm" type="number" min="1" max="500" name="lines[<?= $i ?>][qty]" aria-label="Quantity <?= $i + 1 ?>"></td><td><input class="form-control form-control-sm" inputmode="decimal" name="lines[<?= $i ?>][unit_cost]" aria-label="Unit cost <?= $i + 1 ?>"></td></tr><?php endfor; ?>
    </tbody></table>
    <input class="form-control form-control-sm mb-2" name="notes" maxlength="255" placeholder="Notes" aria-label="Notes">
    <button class="btn btn-sm btn-primary">Save order</button></form></div>
</div>
<?php endif;
layout_end();
