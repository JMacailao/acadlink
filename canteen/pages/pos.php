<?php
/** Canteen Cashier (v59): find or scan products, build the order, take cash or QR payment, print the receipt. */
declare(strict_types=1);
/** @var bool $isPost */
if (!Auth::can('canteen.view')) redirect(Auth::can('canteen.products') ? 'products' : (Auth::can('canteen.inventory') ? 'inventory' : 'reports'));

if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'open_shift') {
        Canteen::startShift(Money::cents((string) ($_POST['opening'] ?? '0')));
        flash('success', 'Shift opened. You can start selling.');
        redirect('dashboard');
    }
    if ($a === 'sell') {
        $items = json_decode((string) ($_POST['items'] ?? '[]'), true);
        if (!is_array($items)) throw new AppError('The order could not be read. Try again.');
        $method = (string) ($_POST['method'] ?? '');
        $tendered = $method === 'CASH' ? Money::cents((string) ($_POST['tendered'] ?? '0')) : 0;
        $id = Canteen::sell(array_map(fn($i) => [(int) ($i['id'] ?? 0), (int) ($i['qty'] ?? 0)], array_filter($items, 'is_array')), $method, $tendered,
            (string) ($_POST['idem'] ?? ''), (string) ($_POST['reference'] ?? ''), (string) ($_POST['customer'] ?? ''));
        $sale = Canteen::sale($id);
        flash('success', 'Paid · ' . $sale['receipt_no'] . ($sale['change_cents'] > 0 ? ' · Change ₱' . Money::fmt((int) $sale['change_cents']) : ''));
        redirect('receipt', ['id' => $id, 'done' => 1]);
    }
    throw new AppError('Unknown action.');
}

$shift = Canteen::openShift();
$totals = $shift ? Canteen::shiftTotals((int) $shift['id']) : null;
$canSell = Auth::can('canteen.sell');
$cats = Canteen::categories(true);
$products = Canteen::products(['active' => 1]);
$qr = Canteen::setting('qr_image') !== '';
$catalog = array_map(fn($p) => [
    'id' => (int) $p['id'], 'name' => $p['name'], 'price' => (int) $p['price_cents'], 'cat' => (int) $p['category_id'],
    'barcode' => (string) $p['barcode'], 'sku' => (string) $p['sku'], 'track' => (bool) $p['track_stock'], 'stock' => (int) $p['stock_qty'],
    'img' => $p['image'] ? url('img', ['id' => $p['id'], 'v' => substr(md5((string) $p['updated_at']), 0, 8)]) : '', 'icon' => $p['category_icon'] ?: 'tag',
], $products);
$peso = fn(int $c) => '₱' . Money::fmt($c);
layout_start('Canteen Cashier');
?>
<div class="canteen-pos" data-canteen-pos data-shift="<?= (int) ($shift['id'] ?? 0) ?>">
  <div class="cpos-head">
    <div><h1 class="cpos-title">Canteen Cashier</h1><p class="cpos-sub">Serve students and staff · Quick and easy checkout</p></div>
    <?php if ($shift): ?>
    <section class="cpos-shift" aria-label="Your shift">
      <div class="cpos-shift-state"><span class="cpos-pill"><span class="dot" aria-hidden="true"></span>Shift: Open</span>
        <small>Opened: <?= h(date('g:i A', strtotime((string) $shift['opened_at']))) ?> by <?= h((string) $shift['cashier']) ?></small></div>
      <dl class="cpos-shift-nums">
        <div><dt>Opening Cash</dt><dd><?= $peso($totals['opening']) ?></dd></div>
        <div><dt>Sales (this shift)</dt><dd><?= $peso($totals['net']) ?></dd></div>
        <div class="strong"><dt>Cash in Drawer</dt><dd><?= $peso($totals['expected']) ?></dd></div>
      </dl>
    </section>
    <?php endif; ?>
  </div>

  <div class="cpos-body">
    <section class="cpos-panel cpos-catalog" aria-label="Products">
      <div class="cpos-search"><i class="bi bi-upc-scan" aria-hidden="true"></i>
        <input type="search" id="cposSearch" class="form-control" placeholder="Scan barcode or search product (e.g., Rice, Sandwich, Water)" aria-label="Scan a barcode or search products" autocomplete="off" <?= $shift ? 'autofocus' : '' ?>>
        <i class="bi bi-search" aria-hidden="true"></i></div>
      <div class="cpos-cats" role="toolbar" aria-label="Categories">
        <button type="button" class="cpos-cat active" data-cat="0" aria-pressed="true"><i class="bi bi-grid" aria-hidden="true"></i>All Items</button>
        <?php foreach ($cats as $c): ?><button type="button" class="cpos-cat" data-cat="<?= (int) $c['id'] ?>" aria-pressed="false"><i class="bi bi-<?= h($c['icon']) ?>" aria-hidden="true"></i><?= h($c['name']) ?></button><?php endforeach; ?>
      </div>
      <div class="cpos-products-head"><h2>Products</h2>
        <div class="cpos-view" role="group" aria-label="Show as"><button type="button" class="active" data-view="grid" aria-label="Grid" aria-pressed="true"><i class="bi bi-grid-3x2-gap"></i></button><button type="button" data-view="list" aria-label="List" aria-pressed="false"><i class="bi bi-list-ul"></i></button></div></div>
      <div class="cpos-grid" id="cposGrid" aria-live="polite"></div>
      <p class="cpos-empty text-muted" id="cposEmpty" hidden>No product matches. <?php if (Auth::can('canteen.products.edit')): ?><a href="<?= url('products', ['new' => 1]) ?>">Add a product</a><?php endif; ?></p>
    </section>

    <aside class="cpos-panel cpos-order" id="cposOrder" aria-label="Current order">
      <?php if (!$shift && $canSell): ?>
      <div class="cpos-open">
        <i class="bi bi-door-open" aria-hidden="true"></i><h2>Open your shift</h2>
        <p>Count the cash in your drawer before your first sale.</p>
        <form method="post" class="text-start"><?= csrf_field() ?><input type="hidden" name="action" value="open_shift">
          <label class="form-label" for="cposOpening">Opening cash ₱</label>
          <input class="form-control form-control-lg mb-3" id="cposOpening" name="opening" inputmode="decimal" required value="0.00">
          <button class="btn btn-primary w-100 btn-lg">Open shift</button></form>
      </div>
      <?php elseif (!$shift): ?>
      <div class="cpos-open"><i class="bi bi-eye" aria-hidden="true"></i><h2>View only</h2><p>Your account can look at the canteen but not sell.</p></div>
      <?php else: ?>
      <div class="cpos-order-head"><h2>Current Order <small id="cposCount">(0 items)</small></h2>
        <button type="button" class="cpos-clear" id="cposClear"><i class="bi bi-trash3" aria-hidden="true"></i>Clear Cart</button></div>
      <ol class="cpos-lines" id="cposLines"></ol>
      <p class="cpos-hint text-muted" id="cposHint">Tap a product or scan a barcode to add it.</p>
      <div class="cpos-sum"><div><span>Subtotal</span><span id="cposSubtotal">₱0.00</span></div>
        <div class="total"><span>Total</span><span id="cposTotal">₱0.00</span></div></div>
      <h3 class="cpos-tender-title">Tender Payment</h3>
      <div class="cpos-tender" role="radiogroup" aria-label="Payment">
        <button type="button" class="active" data-method="CASH" role="radio" aria-checked="true"><i class="bi bi-cash" aria-hidden="true"></i>Cash</button>
        <button type="button" data-method="QR" role="radio" aria-checked="false"><i class="bi bi-qr-code" aria-hidden="true"></i>QR Payment</button>
      </div>
      <button type="button" class="cpos-charge" id="cposCharge" disabled><i class="bi bi-credit-card-2-front" aria-hidden="true"></i><span>Charge Payment (₱0.00)</span></button>
      <form method="post" id="cposForm" hidden><?= csrf_field() ?>
        <input type="hidden" name="action" value="sell"><input type="hidden" name="items"><input type="hidden" name="method">
        <input type="hidden" name="tendered"><input type="hidden" name="reference"><input type="hidden" name="idem" value="<?= bin2hex(random_bytes(16)) ?>">
      </form>
      <?php endif; ?>
    </aside>
  </div>
</div>

<?php if ($shift && $canSell): ?><div class="cpos-mobilebar" aria-hidden="true"><span><small id="cposBarCount">0 items</small><br><b id="cposBarTotal">₱0.00</b></span><a href="#cposOrder">View order</a></div><?php endif; ?>
<div class="modal fade" id="cposPay" tabindex="-1" aria-labelledby="cposPayTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title fs-5" id="cposPayTitle">Cash payment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <p class="cpos-due">Amount due <strong id="cposDue">₱0.00</strong></p>
    <div data-pay="CASH">
      <label class="form-label" for="cposTendered">Cash received ₱</label>
      <input class="form-control form-control-lg" id="cposTendered" inputmode="decimal" autocomplete="off">
      <div class="cpos-quick" id="cposQuick"></div>
      <p class="cpos-change">Change <strong id="cposChange">₱0.00</strong></p>
    </div>
    <div data-pay="QR" hidden class="text-center">
      <?php if ($qr): ?><img class="cpos-qr" src="<?= h(url('qr', ['v' => substr(md5(Canteen::setting('qr_image')), 0, 8)])) ?>" alt="Canteen payment QR code"><p class="fw-semibold mb-1"><?= h(Canteen::setting('qr_label', 'Scan to pay')) ?></p>
      <?php else: ?><div class="alert alert-warning text-start">No QR code is saved yet. <?= Auth::can('canteen.products.edit') ? '<a href="' . h(url('settings')) . '">Upload it in Canteen Settings.</a>' : 'Ask the canteen supervisor to upload it.' ?></div><?php endif; ?>
      <label class="form-label small text-start d-block mt-2" for="cposRef">Reference number (optional)</label>
      <input class="form-control" id="cposRef" maxlength="60" autocomplete="off" placeholder="e.g. GCash ref. no.">
    </div>
    <p class="text-danger small mt-2 mb-0" id="cposPayError" role="alert"></p>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button>
    <button type="button" class="btn btn-primary" id="cposConfirm">Confirm payment</button></div>
</div></div></div>
<script type="application/json" id="cposCatalog"><?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
<script src="assets/pos.js?v=59b" defer></script>
<?php layout_end();
