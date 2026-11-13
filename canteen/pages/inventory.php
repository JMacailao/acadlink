<?php
/** Stock: levels and low-stock warnings, receive deliveries, record servings prepared, adjust, stocktake, movement history. */
declare(strict_types=1);
/** @var bool $isPost */
$edit = Auth::can('canteen.inventory.edit');
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    $pid = (int) ($_POST['product'] ?? 0);
    if ($a === 'add') {
        $cost = trim((string) ($_POST['unit_cost'] ?? '')) === '' ? null : Money::cents((string) $_POST['unit_cost']);
        $b = Canteen::addStock($pid, (string) ($_POST['kind'] ?? ''), (int) ($_POST['qty'] ?? 0), $cost, trim((string) ($_POST['note'] ?? '')));
        flash('success', 'Stock added. New balance: ' . $b . '.');
    } elseif ($a === 'adjust') {
        $b = Canteen::adjust($pid, (int) ($_POST['delta'] ?? 0), (string) ($_POST['reason'] ?? ''));
        flash('success', 'Stock adjusted. New balance: ' . $b . '.');
    } elseif ($a === 'stocktake') {
        [$n, $diff] = Canteen::stocktake((array) ($_POST['count'] ?? []), trim((string) ($_POST['note'] ?? '')));
        flash('success', $n ? "Stocktake saved: $n product(s) changed, " . ($diff >= 0 ? '+' : '') . "$diff units in all." : 'Stocktake saved: every count matched.');
        redirect('inventory', ['tab' => 'history', 'kind' => 'STOCKTAKE']);
    } else throw new AppError('Unknown action.');
    redirect('inventory', ['tab' => $_POST['tab'] ?? 'stock']);
}
$tab = in_array($_GET['tab'] ?? '', ['stock', 'stocktake', 'history'], true) ? $_GET['tab'] : 'stock';
$products = Canteen::products(['active' => 0]);
$tracked = array_values(array_filter($products, fn($p) => (int) $p['track_stock']));
$opts = implode('', array_map(fn($p) => '<option value="' . (int) $p['id'] . '">' . h($p['name']) . ' (' . (int) $p['stock_qty'] . ')</option>', $tracked));
layout_start('Stock');
?>
<nav class="nav nav-pills gap-2 mb-3"><?php foreach (['stock' => 'Stock levels', 'stocktake' => 'Stocktake', 'history' => 'History'] as $k => $v): ?><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= url('inventory', ['tab' => $k]) ?>"><?= $v ?></a><?php endforeach; ?></nav>
<?php if ($tab === 'stock'): $low = array_filter($tracked, fn($p) => (int) $p['stock_qty'] <= (int) $p['low_stock_at']); ?>
<?php if ($low): ?><div class="alert alert-warning"><b>Low stock:</b> <?= h(implode(', ', array_map(fn($p) => $p['name'] . ' (' . (int) $p['stock_qty'] . ')', $low))) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="<?= $edit ? 'col-xl-7' : 'col-12' ?>"><div class="table-responsive form-section"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Product</th><th>Category</th><th class="text-end">In stock</th><th class="text-end">Warn at</th><th class="text-end">Value at cost</th></tr></thead><tbody>
    <?php foreach ($tracked as $p): $isLow = (int) $p['stock_qty'] <= (int) $p['low_stock_at']; ?>
      <tr><td><a href="<?= url('inventory', ['tab' => 'history', 'product' => $p['id']]) ?>"><?= h($p['name']) ?></a></td><td><?= h((string) $p['category_name']) ?></td>
        <td class="text-end <?= $isLow ? 'text-danger fw-semibold' : '' ?>"><?= (int) $p['stock_qty'] ?></td><td class="text-end text-muted"><?= (int) $p['low_stock_at'] ?></td>
        <td class="text-end">₱<?= Money::fmt(max(0, (int) $p['stock_qty']) * (int) $p['cost_cents']) ?></td></tr>
    <?php endforeach; if (!$tracked): ?><tr><td colspan="5" class="text-center text-muted py-4">No product counts stock yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <?php if ($edit && $tracked): ?><div class="col-xl-5">
    <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="add">
      <h2 class="h6">Add stock</h2>
      <div class="row g-2">
        <div class="col-12"><label class="form-label small" for="aProd">Product</label><select class="form-select form-select-sm" id="aProd" name="product" required><?= $opts ?></select></div>
        <div class="col-6"><label class="form-label small" for="aKind">What happened</label><select class="form-select form-select-sm" id="aKind" name="kind"><option value="RECEIVE">Received from supplier</option><option value="PREPARE">Servings prepared today</option></select></div>
        <div class="col-6"><label class="form-label small" for="aQty">Quantity</label><input class="form-control form-control-sm" type="number" id="aQty" name="qty" min="1" max="100000" required></div>
        <div class="col-6"><label class="form-label small" for="aCost">Unit cost ₱ (optional)</label><input class="form-control form-control-sm" id="aCost" name="unit_cost" inputmode="decimal"></div>
        <div class="col-6"><label class="form-label small" for="aNote">Supplier / note</label><input class="form-control form-control-sm" id="aNote" name="note" maxlength="255"></div>
      </div><button class="btn btn-sm btn-primary mt-2">Add stock</button></form>
    <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="adjust">
      <h2 class="h6">Adjust</h2><p class="small text-muted">Spoiled, expired, lost or found items. Use − to remove.</p>
      <div class="row g-2">
        <div class="col-12"><label class="form-label small" for="jProd">Product</label><select class="form-select form-select-sm" id="jProd" name="product" required><?= $opts ?></select></div>
        <div class="col-4"><label class="form-label small" for="jDelta">Change</label><input class="form-control form-control-sm" type="number" id="jDelta" name="delta" required placeholder="-2"></div>
        <div class="col-8"><label class="form-label small" for="jWhy">Reason</label><input class="form-control form-control-sm" id="jWhy" name="reason" required minlength="4" maxlength="255" placeholder="e.g. 2 juices expired"></div>
      </div><button class="btn btn-sm btn-outline-primary mt-2">Save adjustment</button></form>
  </div><?php endif; ?>
</div>
<?php elseif ($tab === 'stocktake'): ?>
<form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="stocktake">
  <p class="small text-muted">Count what is on the shelves and type it. Leave a box empty to skip that product. Differences are saved as stocktake movements.</p>
  <?php if ($edit): ?><button type="button" class="btn btn-sm btn-outline-secondary mb-2" data-fill-system>Fill empty boxes with the system count</button><?php endif; ?>
  <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Product</th><th class="text-end">System count</th><th style="width:140px">Counted</th></tr></thead><tbody>
  <?php foreach ($tracked as $p): ?><tr><td><?= h($p['name']) ?></td><td class="text-end"><?= (int) $p['stock_qty'] ?></td>
    <td><input class="form-control form-control-sm" type="number" min="0" max="999999" name="count[<?= (int) $p['id'] ?>]" data-system-qty="<?= max(0, (int) $p['stock_qty']) ?>" aria-label="Counted <?= h($p['name']) ?>"<?= $edit ? '' : ' disabled' ?>></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($edit): ?><label class="form-label small" for="stNote">Note</label><input class="form-control form-control-sm mb-2" id="stNote" name="note" maxlength="255" placeholder="e.g. Month-end count, Nov 2026">
  <button class="btn btn-primary">Save stocktake</button><?php endif; ?>
</form>
<script src="assets/canteen-pages.js?v=59" defer></script>
<?php else:
    $kind = (string) ($_GET['kind'] ?? ''); $prod = (int) ($_GET['product'] ?? 0);
    $moves = Canteen::moves(['product' => $prod, 'kind' => $kind, 'from' => In::date($_GET, 'from'), 'to' => In::date($_GET, 'to')]); ?>
<form method="get" class="d-flex flex-wrap gap-2 mb-2"><input type="hidden" name="r" value="inventory"><input type="hidden" name="tab" value="history">
  <select class="form-select form-select-sm" style="max-width:240px" name="product" aria-label="Product"><option value="0">All products</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $prod === (int) $p['id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select>
  <select class="form-select form-select-sm" style="max-width:180px" name="kind" aria-label="Movement"><option value="">All movements</option><?php foreach (Canteen::MOVE_KINDS as $k => $v): ?><option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm btn-outline-secondary">Show</button></form>
<div class="table-responsive form-section"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>When</th><th>Product</th><th>Movement</th><th class="text-end">Change</th><th class="text-end">Balance</th><th>Note</th><th>By</th></tr></thead><tbody>
  <?php foreach ($moves as $m): ?><tr><td class="small"><?= h(date('M j, g:i A', strtotime((string) $m['created_at']))) ?></td><td><?= h($m['product_name']) ?></td><td><?= h(Canteen::MOVE_KINDS[$m['kind']]) ?></td>
    <td class="text-end <?= (int) $m['delta'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $m['delta'] > 0 ? '+' : '' ?><?= (int) $m['delta'] ?></td><td class="text-end"><?= (int) $m['balance'] ?></td>
    <td class="small"><?= h((string) $m['note']) ?></td><td class="small"><?= h((string) $m['actor']) ?></td></tr><?php endforeach; ?>
  <?php if (!$moves): ?><tr><td colspan="7" class="text-center text-muted py-4">No movements.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php endif;
layout_end();
