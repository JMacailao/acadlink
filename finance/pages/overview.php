<?php
/** Finance home (v58): money collected by source, work waiting, and shortcuts to the Finance pages. */
declare(strict_types=1);
require_once FINANCE_ROOT . '/lib/overview.php';
if (!Auth::central()->can((int) Auth::centralId(), 'finance.workspace.view')) redirect('workspaces');

$data = FinanceOverview::collections((string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
$attention = FinanceOverview::attention();
$shortcuts = [];
if (Auth::can('payments.record')) $shortcuts[] = ['Receive a payment', 'Record a payment and issue a receipt', 'cash-coin', url('cashier', ['new' => 1])];
if (Auth::can('payments.view')) $shortcuts[] = ['Receipts', 'Find, view and re-print receipts', 'receipt', url('cashier')];
if (Auth::can('registration', 'accounting', 'balances.view')) $shortcuts[] = ['Existing balances', 'Previous-term balances and requests', 'wallet2', url('balance')];
if (Auth::can('accounting')) $shortcuts[] = ['Review and post', 'Approve and post journal entries', 'clipboard-check', url('accounting', ['tab' => 'review'])];
if (Auth::can('accounting')) $shortcuts[] = ['Ledger', 'Journal entries by date', 'journal-text', url('accounting', ['tab' => 'ledger'])];
if (Auth::can('payments.view', 'accounting')) $shortcuts[] = ['Reports', 'Cashier and accounting reports', 'printer', url('reports')];

layout_start('Finance overview');
?>
<section class="form-section finance-overview" aria-labelledby="financeCollections">
  <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-2">
    <div><h2 class="h5 mb-0" id="financeCollections">Collections by source</h2>
      <p class="small text-muted mb-0">Posted receipts from <?= h(date('M j, Y', strtotime($data['from']))) ?> to <?= h(date('M j, Y', strtotime($data['to']))) ?>. Voided receipts are not counted.</p></div>
    <form method="get" class="d-flex flex-wrap align-items-end gap-2" aria-label="Date range">
      <input type="hidden" name="r" value="dashboard">
      <div><label class="form-label small mb-0" for="finFrom">From</label><input class="form-control form-control-sm" type="date" id="finFrom" name="from" value="<?= h($data['from']) ?>"></div>
      <div><label class="form-label small mb-0" for="finTo">To</label><input class="form-control form-control-sm" type="date" id="finTo" name="to" value="<?= h($data['to']) ?>"></div>
      <button class="btn btn-sm btn-primary">Show</button>
    </form>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" data-finance-sources>
    <thead><tr><th scope="col">Source</th><th scope="col" class="text-end">Receipts</th><th scope="col" class="text-end">Amount</th><th scope="col" class="text-end">Share</th></tr></thead>
    <tbody>
    <?php foreach ($data['rows'] as $row): ?>
      <tr data-source="<?= h($row['key']) ?>">
        <th scope="row" class="fw-normal"><i class="bi bi-<?= h($row['icon']) ?> me-2" aria-hidden="true"></i><?= h($row['label']) ?></th>
        <?php if ($row['ready']): ?>
        <td class="text-end"><?= number_format($row['count']) ?></td>
        <td class="text-end font-monospace">₱<?= Money::fmt($row['cents']) ?></td>
        <td class="text-end"><?= $data['total'] > 0 ? number_format($row['cents'] * 100 / $data['total'], 1) . '%' : '—' ?></td>
        <?php else: ?>
        <td colspan="3" class="text-end text-muted small">Not set up yet</td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th scope="row">Total</th><td></td><th class="text-end font-monospace">₱<?= Money::fmt($data['total']) ?></th><td></td></tr></tfoot>
  </table></div>
</section>

<?php if ($attention): ?>
<section class="form-section" aria-labelledby="financeAttention">
  <h2 class="h5" id="financeAttention">Needs attention</h2>
  <div class="row g-2">
  <?php foreach ($attention as [$label, $count, $icon, $href]): ?>
    <div class="col-6 col-lg-3"><a class="d-block border rounded-3 p-3 text-decoration-none h-100" href="<?= h($href) ?>">
      <i class="bi bi-<?= h($icon) ?>" aria-hidden="true"></i> <span class="small text-muted"><?= h($label) ?></span>
      <strong class="d-block fs-4"><?= number_format($count) ?></strong></a></div>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($shortcuts): ?>
<section class="form-section" aria-labelledby="financeShortcuts">
  <h2 class="h5" id="financeShortcuts">Go to</h2>
  <div class="row g-2">
  <?php foreach ($shortcuts as [$label, $hint, $icon, $href]): ?>
    <div class="col-12 col-sm-6 col-lg-4"><a class="d-flex gap-3 align-items-center border rounded-3 p-3 text-decoration-none h-100" href="<?= h($href) ?>">
      <i class="bi bi-<?= h($icon) ?> fs-4" aria-hidden="true"></i><span><strong class="d-block"><?= h($label) ?></strong><span class="small text-muted"><?= h($hint) ?></span></span></a></div>
  <?php endforeach; ?>
  </div>
</section>
<?php endif;
layout_end();
