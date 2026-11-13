<?php
/** Products and categories: names, prices, costs, barcodes, photos, stock tracking. */
declare(strict_types=1);
/** @var bool $isPost */
$edit = Auth::can('canteen.products.edit');
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'product') {
        $id = Canteen::saveProduct(((int) ($_POST['id'] ?? 0)) ?: null, $_POST, $_FILES['image'] ?? null);
        flash('success', 'Product saved.');
        redirect('products', ['edit' => $id]);
    }
    if ($a === 'category') {
        Canteen::saveCategory(((int) ($_POST['id'] ?? 0)) ?: null, $_POST);
        flash('success', 'Category saved.');
        redirect('products', ['tab' => 'categories']);
    }
    throw new AppError('Unknown action.');
}
$tab = ($_GET['tab'] ?? '') === 'categories' ? 'categories' : 'products';
$cats = Canteen::categories();
$peso = fn(int $c) => '₱' . Money::fmt($c);
layout_start('Products');
?>
<nav class="nav nav-pills gap-2 mb-3"><a class="nav-link<?= $tab === 'products' ? ' active' : '' ?>" href="<?= url('products') ?>">Products</a><a class="nav-link<?= $tab === 'categories' ? ' active' : '' ?>" href="<?= url('products', ['tab' => 'categories']) ?>">Categories</a></nav>
<?php if ($tab === 'categories'):
    $ec = isset($_GET['cat']) ? (array) (Db::one('SELECT * FROM canteen_categories WHERE id = ?', [(int) $_GET['cat']]) ?? []) : []; ?>
<div class="row g-3"><div class="col-lg-7"><div class="table-responsive form-section"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Category</th><th>Icon</th><th class="text-end">Products</th><th>Shown</th><th></th></tr></thead><tbody>
  <?php foreach ($cats as $c): ?><tr><td><?= h($c['name']) ?></td><td><i class="bi bi-<?= h($c['icon']) ?>"></i> <small class="text-muted"><?= h($c['icon']) ?></small></td><td class="text-end"><?= (int) $c['products'] ?></td><td><?= (int) $c['is_active'] ? 'Yes' : 'No' ?></td>
    <td class="text-end"><?php if ($edit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('products', ['tab' => 'categories', 'cat' => $c['id']]) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <?php if ($edit): ?><div class="col-lg-5"><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="category"><input type="hidden" name="id" value="<?= (int) ($ec['id'] ?? 0) ?>">
    <h2 class="h6"><?= $ec ? 'Edit category' : 'Add a category' ?></h2>
    <label class="form-label small" for="cName">Name</label><input class="form-control form-control-sm mb-2" id="cName" name="name" required maxlength="60" value="<?= h((string) ($ec['name'] ?? '')) ?>">
    <label class="form-label small" for="cIcon">Icon (Bootstrap icon name, e.g. cup-straw, egg-fried, basket)</label><input class="form-control form-control-sm mb-2" id="cIcon" name="icon" maxlength="40" pattern="[a-z0-9-]+" value="<?= h((string) ($ec['icon'] ?? 'tag')) ?>">
    <label class="form-label small" for="cSort">Order</label><input class="form-control form-control-sm mb-2" type="number" id="cSort" name="sort_order" min="0" max="999" value="<?= (int) ($ec['sort_order'] ?? 10) ?>">
    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1"<?= !$ec || (int) $ec['is_active'] ? ' checked' : '' ?>> Shown on the Cashier screen</label>
    <button class="btn btn-sm btn-primary">Save category</button><?php if ($ec): ?> <a class="btn btn-sm btn-link" href="<?= url('products', ['tab' => 'categories']) ?>">Cancel</a><?php endif; ?></form></div><?php endif; ?>
</div>
<?php else:
    $q = trim((string) ($_GET['q'] ?? '')); $fc = (int) ($_GET['category'] ?? 0);
    $rows = Canteen::products(['q' => $q, 'category' => $fc]);
    $p = isset($_GET['edit']) ? Canteen::product((int) $_GET['edit']) : null;
    $showForm = $edit && ($p || isset($_GET['new'])); ?>
<div class="row g-3">
  <div class="<?= $showForm ? 'col-xl-7' : 'col-12' ?>">
    <form method="get" class="d-flex flex-wrap gap-2 mb-2"><input type="hidden" name="r" value="products">
      <input class="form-control form-control-sm" style="max-width:260px" name="q" value="<?= h($q) ?>" placeholder="Name, barcode or SKU" aria-label="Search products">
      <select class="form-select form-select-sm" style="max-width:200px" name="category" aria-label="Category"><option value="0">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $fc === (int) $c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-outline-secondary">Search</button>
      <?php if ($edit): ?><a class="btn btn-sm btn-primary ms-auto" href="<?= url('products', ['new' => 1]) ?>"><i class="bi bi-plus-lg me-1"></i>Add product</a><?php endif; ?></form>
    <div class="table-responsive form-section"><table class="table table-sm align-middle mb-0">
      <thead><tr><th></th><th>Product</th><th>Category</th><th>Barcode</th><th class="text-end">Price</th><th class="text-end">Cost</th><th class="text-end">Stock</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): $low = (int) $r['track_stock'] && (int) $r['stock_qty'] <= (int) $r['low_stock_at']; ?>
      <tr class="<?= (int) $r['is_active'] ? '' : 'text-muted' ?>"><td style="width:48px"><?= $r['image_ver'] ? '<img src="' . h(url('img', ['id' => $r['id'], 'v' => $r['image_ver']])) . '" alt="" width="40" height="40" style="object-fit:cover;border-radius:6px">' : '<i class="bi bi-' . h($r['category_icon'] ?: 'tag') . ' fs-4 text-muted"></i>' ?></td>
        <td><?= h($r['name']) ?><?= (int) $r['is_active'] ? '' : ' <span class="badge text-bg-secondary">Hidden</span>' ?></td><td><?= h((string) $r['category_name']) ?></td><td class="font-monospace small"><?= h((string) $r['barcode']) ?></td>
        <td class="text-end"><?= $peso((int) $r['price_cents']) ?></td><td class="text-end text-muted"><?= $peso((int) $r['cost_cents']) ?></td>
        <td class="text-end"><?= (int) $r['track_stock'] ? '<span class="' . ($low ? 'text-danger fw-semibold' : '') . '">' . (int) $r['stock_qty'] . '</span>' : '<span class="text-muted">not counted</span>' ?></td>
        <td class="text-end"><?php if ($edit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('products', ['edit' => $r['id']]) ?>">Edit</a><?php endif; ?></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No products yet.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
  <?php if ($showForm): ?>
  <div class="col-xl-5"><form method="post" enctype="multipart/form-data" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="product"><input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
    <h2 class="h6"><?= $p ? 'Edit ' . h($p['name']) : 'Add a product' ?></h2>
    <div class="row g-2">
      <div class="col-12"><label class="form-label small" for="pName">Name</label><input class="form-control form-control-sm" id="pName" name="name" required maxlength="100" value="<?= h((string) ($p['name'] ?? '')) ?>"></div>
      <div class="col-6"><label class="form-label small" for="pCat">Category</label><select class="form-select form-select-sm" id="pCat" name="category_id"><option value="">—</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) ($p['category_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label small" for="pSku">SKU (optional)</label><input class="form-control form-control-sm font-monospace" id="pSku" name="sku" maxlength="40" value="<?= h((string) ($p['sku'] ?? '')) ?>"></div>
      <div class="col-12"><label class="form-label small" for="pBar">Barcode (scan it here)</label><input class="form-control form-control-sm font-monospace" id="pBar" name="barcode" maxlength="64" value="<?= h((string) ($p['barcode'] ?? '')) ?>" autocomplete="off"></div>
      <div class="col-6"><label class="form-label small" for="pPrice">Selling price ₱</label><input class="form-control form-control-sm" id="pPrice" name="price" required inputmode="decimal" value="<?= $p ? Money::dec((int) $p['price_cents']) : '' ?>"></div>
      <div class="col-6"><label class="form-label small" for="pCost">Unit cost ₱</label><input class="form-control form-control-sm" id="pCost" name="cost" inputmode="decimal" value="<?= $p ? Money::dec((int) $p['cost_cents']) : '0.00' ?>"></div>
      <div class="col-6"><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="track_stock" value="1"<?= !$p || (int) $p['track_stock'] ? ' checked' : '' ?>> Count stock</label></div>
      <div class="col-6"><label class="form-label small" for="pLow">Warn when stock is at or below</label><input class="form-control form-control-sm" type="number" id="pLow" name="low_stock_at" min="0" value="<?= (int) ($p['low_stock_at'] ?? 5) ?>"></div>
      <div class="col-12"><label class="form-label small" for="pImg">Photo (PNG, JPEG or WebP, up to 5 MB; large photos are shrunk to 800 px)</label><input class="form-control form-control-sm" type="file" id="pImg" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
        <?php if ($p && $p['image_ver']): ?><img src="<?= h(url('img', ['id' => $p['id'], 'v' => $p['image_ver']])) ?>" alt="Current photo" class="d-block mt-2 rounded" style="width:120px;height:90px;object-fit:cover"><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="remove_image" value="1"> Remove the current photo</label><?php endif; ?></div>
      <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1"<?= !$p || (int) $p['is_active'] ? ' checked' : '' ?>> Sold on the Cashier screen</label></div>
    </div>
    <?php if (!$p): ?><p class="form-text">New products start with 0 in stock. Add stock in <a href="<?= url('inventory') ?>">Stock</a>.</p><?php endif; ?>
    <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary">Save product</button><a class="btn btn-sm btn-link" href="<?= url('products') ?>">Cancel</a></div>
  </form></div>
  <?php endif; ?>
</div>
<?php endif;
layout_end();
