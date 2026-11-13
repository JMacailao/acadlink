<?php
/** Accounting: transaction ledger (journal), chart of accounts, account mappings, cash and bank reconciliation. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
require_once __DIR__.'/workspace.php';
$tab=isset(ACCOUNTING_TABS[$_GET['tab']??''])?$_GET['tab']:'overview';
if($isPost)accounting_new_action((string)($_POST['action']??''));
// An opened entry shows the list of its own status, so the list above it is not left empty after Open, Approve or Post.
if(!$isPost&&$tab==='review'&&!empty($_GET['entry'])&&!RecordList::applied())$_GET['apply']='1';
if($isPost&&!in_array($tab,['ledger','accounts','mappings','reconcile'],true))throw new AppError('Unknown accounting action.');
if(!in_array($tab,['ledger','accounts','mappings','reconcile'],true)){accounting_workspace($tab);return;}
$canEdit = Auth::can('accounting.edit');
if ($isPost) {
    $a = $_POST['action'] ?? '';
    if ($a === 'entry') {
        // Sent twice (slow link, Reload, double-click): the second send does not add another draft
        $id = null;
        if (!FormOnce::run($_POST, function () use (&$id) { $id = Accounting::manualEntry((string) ($_POST['entry_date'] ?? ''), (string) ($_POST['memo'] ?? ''), array_values((array) ($_POST['lines'] ?? [])),(string)($_POST['fund']??'OPERATING'),(string)($_POST['department']??'School')); })) {
            flash('warning', 'This entry was already saved as a draft. It was not saved again.');
            redirect('accounting', ['tab' => 'review', 'status' => 'DRAFT']);
        }
        flash('success','Draft saved. Send it for review, approve, then post.');
        redirect('accounting',['tab'=>'review','entry'=>$id,'status'=>'DRAFT']);
    } elseif ($a === 'reverse') {
        $id = Accounting::reverseManual((int) ($_POST['entry_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
        flash('success', 'Entry reversed by ' . Db::val('SELECT entry_no FROM sis_journal WHERE id = ?', [$id]) . '.');
    } elseif ($a === 'sync') {
        Auth::require('accounting.edit');
        $n = Accounting::syncExisting();
        flash('success', "Prepared {$n['assessments']} assessment(s) and {$n['payments']} payment(s) for Accounting review.");
    } elseif ($a === 'account') {
        Accounting::saveAccount(In::int($_POST, 'id'), $_POST);
        flash('success', 'Account saved.');
        redirect('accounting', ['tab' => 'accounts']);
    } elseif ($a === 'map') {
        Accounting::saveMap((array) ($_POST['map'] ?? []));
        flash('success', 'Account mappings saved. New entries use them.');
    } elseif ($a === 'reconcile') {
        Accounting::reconcile((int) ($_POST['account_id'] ?? 0), (string) ($_POST['statement_date'] ?? ''), (int) In::money($_POST, 'statement_balance', true, 'Statement ending balance'),
            array_values((array) ($_POST['clear'] ?? [])), In::str($_POST, 'notes', 255));
        flash('success', 'Reconciled. The ticked items are cleared.');
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}
$accounts = Accounting::accounts();
$active = array_values(array_filter($accounts, fn($a) => (int) $a['is_active']));
$accOpt = fn($sel, string $empty = '— choose —', ?callable $only = null) => '<option value="">' . h($empty) . '</option>'
    . implode('', array_map(fn($a) => '<option value="' . (int) $a['id'] . '"' . ((int) $a['id'] === (int) $sel ? ' selected' : '') . '>' . h($a['code'] . ' · ' . $a['name']) . '</option>',
        array_values(array_filter($active, $only ?? fn() => true))));
$fmt = fn($v) => Money::fmt(is_int($v) ? $v : Money::cents((string) $v));
layout_start('Accounting');
page_title('Accounting', $canEdit && $tab === 'ledger' ? '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#mEntry"><i class="bi bi-plus-lg me-1"></i>Add journal entry</button>' : ($canEdit && $tab === 'accounts' ? '<a class="btn btn-primary" href="#addAccount"><i class="bi bi-plus-lg me-1"></i>Add account</a>' : ''),
    'The General Ledger contains posted entries only. New assessments and payments await Accounting review and posting.');
?>
<?php accounting_nav($tab); ?>

<?php if ($tab === 'ledger'):
    accounting_entry_toolbar();
    $from = In::date($_GET, 'from') ?? date('Y-m-d'); // v57: today by default
    $to = In::date($_GET, 'to') ?? date('Y-m-d');
    $src = in_array($_GET['source'] ?? '', ['ASSESSMENT', 'PAYMENT', 'MANUAL'], true) ? $_GET['source'] : '';
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = ["j.status='POSTED'",'j.entry_date BETWEEN ? AND ?']; $args = [$from, $to];
    if ($src) { $where[] = 'j.source = ?'; $args[] = $src; }
    if ($q !== '') { $where[] = '(j.memo LIKE ? OR j.entry_no LIKE ?)'; array_push($args, "%$q%", "%$q%"); }
    $entries = RecordList::query('SELECT j.*, u.full_name, (SELECT SUM(debit) FROM sis_journal_lines WHERE journal_id = j.id) AS amount, (SELECT entry_no FROM sis_journal x WHERE x.id = j.reversed_by_id) AS reversed_no,
                               (SELECT entry_no FROM sis_journal x WHERE x.id = j.reverses_id) AS reverses_no
                          FROM sis_journal j LEFT JOIN sis_users u ON u.id = j.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY j.entry_date DESC, j.id DESC LIMIT 500', $args);
    $lines = $entries ? Db::all('SELECT l.*, a.code, a.name FROM sis_journal_lines l JOIN sis_accounts a ON a.id = l.account_id WHERE l.journal_id IN ' . Db::in(array_column($entries, 'id')) . ' ORDER BY l.journal_id, l.credit > 0, l.id', array_column($entries, 'id')) : [];
    $byEntry = [];
    foreach ($lines as $l) $byEntry[(int) $l['journal_id']][] = $l;
    $tb = Accounting::balances(); $inBal = array_sum($tb) === 0;
    $unbooked = (int) Db::val("SELECT COUNT(*) FROM sis_registrations WHERE status <> 'CANCELLED' AND locked_at IS NOT NULL AND id NOT IN (SELECT source_id FROM sis_journal WHERE source = 'ASSESSMENT' AND source_id IS NOT NULL)")
              + (int) Db::val("SELECT COUNT(*) FROM sis_payments WHERE status = 'POSTED' AND id NOT IN (SELECT source_id FROM sis_journal WHERE source = 'PAYMENT' AND source_id IS NOT NULL)");
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <span class="badge <?= $inBal ? 'text-bg-success' : 'text-bg-danger' ?> align-self-center"><i class="bi bi-<?= $inBal ? 'check2-circle' : 'exclamation-triangle' ?>"></i> Books <?= $inBal ? 'in balance' : 'NOT in balance' ?></span>
  <?php if ($unbooked && $canEdit): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-arrow-down me-1"></i>Book <?= $unbooked ?> earlier record(s)</button></form><?php endif; ?>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= url('reports', ['rep' => 'trial_balance', 'run' => 1]) ?>" data-frame="<?= url('reports', ['rep' => 'trial_balance', 'run' => 1, 'embed' => 1]) ?>" data-frame-title="Trial Balance" data-frame-size="xl"><i class="bi bi-file-earmark-text me-1"></i>Trial balance</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('reports') ?>"><i class="bi bi-printer me-1"></i>Accounting reports</a>
</div>
<form id="accountingLedgerFilters" data-record-filters method="get" class="form-section d-flex flex-wrap gap-2 align-items-end"><input type="hidden" name="r" value="accounting"><input type="hidden" name="tab" value="ledger"><input type="hidden" name="apply" value="1">
  <div><label class="form-label small" for="lFrom">From</label><input class="form-control form-control-sm" type="date" id="lFrom" name="from" value="<?= h($from) ?>"></div>
  <div><label class="form-label small" for="lTo">To</label><input class="form-control form-control-sm" type="date" id="lTo" name="to" value="<?= h($to) ?>"></div>
  <div><label class="form-label small" for="lSrc">Source</label><select class="form-select form-select-sm" id="lSrc" name="source"><option value="">All</option><?php foreach (['ASSESSMENT' => 'Assessments', 'PAYMENT' => 'Payments', 'MANUAL' => 'Hand-made'] as $k => $l): ?><option value="<?= $k ?>"<?= $src === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="flex-grow-1"><label class="form-label small" for="lQ">Find</label><input class="form-control form-control-sm" id="lQ" name="q" value="<?= h($q) ?>" placeholder="Entry no., O.R. no., student, registration"></div>
  <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Show</button>
</form>
<div class="form-section p-0">
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th>Account</th><th class="money">Debit</th><th class="money">Credit</th><th></th></tr></thead>
    <?php foreach ($entries as $e): $ls = $byEntry[(int) $e['id']] ?? []; $rev = $e['reversed_by_id'] !== null; ?>
    <tbody class="<?= $rev ? 'text-muted' : '' ?>"<?= accounting_entry_attributes($e) ?>>
      <?php foreach ($ls as $i => $l): ?><tr<?= $i === 0 ? ' class="border-top"' : '' ?>>
        <?php if ($i === 0): ?><td rowspan="<?= count($ls) ?>" class="small text-nowrap"><?= h(date('m/d/Y', strtotime($e['entry_date']))) ?></td>
        <td rowspan="<?= count($ls) ?>" class="font-monospace small text-nowrap"><?= h($e['entry_no']) ?><div><span class="badge text-bg-<?= ['ASSESSMENT' => 'info', 'PAYMENT' => 'success', 'MANUAL' => 'secondary'][$e['source']] ?>"><?= h(ucfirst(strtolower($e['source']))) ?></span></div></td>
        <td rowspan="<?= count($ls) ?>" class="small"><?= h($e['memo']) ?><?php if ($rev): ?><div><span class="badge text-bg-warning">Reversed by <?= h($e['reversed_no']) ?></span></div><?php endif; ?><div class="text-muted"><?= h($e['full_name'] ?? 'System') ?></div></td><?php endif; ?>
        <td class="small<?= Money::cents($l['credit']) > 0 ? ' ps-4' : '' ?>"><?= h($l['code'] . ' · ' . $l['name']) ?><?= $l['memo'] ? '<div class="text-muted">' . h($l['memo']) . '</div>' : '' ?></td>
        <td class="money small"><?= Money::cents($l['debit']) ? $fmt($l['debit']) : '' ?></td><td class="money small"><?= Money::cents($l['credit']) ? $fmt($l['credit']) : '' ?></td>
        <?php if ($i === 0): ?><td rowspan="<?= count($ls) ?>" class="text-end"><?php if (Auth::can('accounting.post') && $e['source'] === 'MANUAL' && !$rev && !$e['reverses_id'] && !PaymentPlans::managedForUi((int)$e['id'])): ?>
          <form method="post" class="d-flex gap-1" data-confirm="Reverse this journal entry? A reversing entry is booked today."><?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="entry_id" value="<?= (int) $e['id'] ?>">
            <input class="form-control form-control-sm" name="reason" required minlength="8" maxlength="200" placeholder="Reason" style="max-width:120px" aria-label="Reason for reversing"><button class="btn btn-sm btn-outline-danger">Reverse</button></form><?php endif; ?></td><?php endif; ?>
      </tr><?php endforeach; ?>
    </tbody>
    <?php endforeach; if (!$entries): ?><tbody><tr><td colspan="7" class="text-center text-muted py-4">No journal entries for these filters.</td></tr></tbody><?php endif; ?>
  </table></div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="mEntry" tabindex="-1" aria-labelledby="mEntryT" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
  <form method="post"><?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="entry">
  <div class="modal-header"><h2 class="modal-title fs-5" id="mEntryT">New journal entry</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <div class="row g-2 mb-2"><div class="col-sm-6"><label class="form-label">Fund</label><select name="fund" class="form-select"><option value="OPERATING">Operating</option><option value="CAPITAL">Capital</option><option value="RESTRICTED">Restricted / scholarship</option></select></div><div class="col-sm-6"><label class="form-label">Department</label><input name="department" class="form-control" value="School" maxlength="80" required></div></div><div class="row g-2 mb-2"><div class="col-sm-4"><label class="form-label small" for="eDate">Date</label><input class="form-control" type="date" id="eDate" name="entry_date" required value="<?= date('Y-m-d') ?>"></div>
      <div class="col-sm-8"><label class="form-label small" for="eMemo">Description</label><input class="form-control" id="eMemo" name="memo" required maxlength="255" placeholder="e.g. Bank charges for September; Deposit of cash collections"></div></div>
    <div class="table-responsive"><table class="table table-sm mb-1"><thead><tr><th>Account</th><th style="width:20%">Debit ₱</th><th style="width:20%">Credit ₱</th><th>Line note</th></tr></thead><tbody>
      <?php for ($i = 0; $i < 6; $i++): ?><tr><td><select class="form-select form-select-sm" name="lines[<?= $i ?>][account_id]" aria-label="Account, line <?= $i + 1 ?>"><?= $accOpt(null, '—') ?></select></td>
        <td><input class="form-control form-control-sm text-end" name="lines[<?= $i ?>][debit]" inputmode="decimal" aria-label="Debit, line <?= $i + 1 ?>"></td><td><input class="form-control form-control-sm text-end" name="lines[<?= $i ?>][credit]" inputmode="decimal" aria-label="Credit, line <?= $i + 1 ?>"></td>
        <td><input class="form-control form-control-sm" name="lines[<?= $i ?>][memo]" maxlength="160" aria-label="Note, line <?= $i + 1 ?>"></td></tr><?php endfor; ?>
    </tbody></table></div>
    <div class="form-text">Total debits must equal total credits. Example — depositing cash collections: Debit 1020 Cash in Bank, Credit 1010 Cash on Hand.</div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save draft</button></div>
  </form>
</div></div></div>
<?php endif; ?>

<?php elseif ($tab === 'accounts'):
    $e = isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) ? Accounting::account((int) $_GET['edit']) : null;
    $bal = Accounting::balances();
?>
<div class="row g-3">
  <div class="<?= $canEdit ? 'col-lg-8' : 'col-12' ?>"><div class="form-section p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="money">Balance</th><th></th></tr></thead><tbody>
    <?php foreach (RecordList::rows(fn()=>$accounts) as $a): $b = Accounting::normal($a, $bal[(int) $a['id']] ?? 0); ?>
      <tr class="<?= (int) $a['is_active'] ? '' : 'text-muted' ?>"><td class="font-monospace"><?= h($a['code']) ?></td>
        <td><?= h($a['name']) ?><?= (int) $a['is_cash'] ? ' <span class="badge text-bg-info">cash / bank</span>' : '' ?><?= (int) $a['is_active'] ? '' : ' <span class="badge text-bg-secondary">inactive</span>' ?><?= $a['description'] ? '<div class="small text-muted">' . h($a['description']) . '</div>' : '' ?></td>
        <td class="small"><?= h(Accounting::TYPES[$a['type']]) ?></td><td class="money"><?= Money::fmt($b) ?></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= url('reports', ['rep' => 'general_ledger', 'run' => 1, 'account' => $a['id'], 'from' => date('Y-01-01'), 'to' => date('Y-m-d')]) ?>">Ledger</a>
          <?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('accounting', ['tab' => 'accounts', 'edit' => $a['id']]) ?>">Edit</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div></div></div>
  <?php if ($canEdit): ?>
  <div class="col-lg-4"><form method="post" class="form-section" id="addAccount"><?= csrf_field() ?><input type="hidden" name="action" value="account"><input type="hidden" name="id" value="<?= (int) ($e['id'] ?? 0) ?: '' ?>">
    <h2 class="h6"><?= $e ? 'Edit account ' . h($e['code']) : 'Add an account' ?></h2>
    <div class="row g-2">
      <div class="col-4"><label class="form-label small" for="aCode">Code</label><input class="form-control font-monospace" id="aCode" name="code" required maxlength="20" value="<?= h($e['code'] ?? '') ?>" placeholder="1040"></div>
      <div class="col-8"><label class="form-label small" for="aName">Name</label><input class="form-control" id="aName" name="name" required maxlength="120" value="<?= h($e['name'] ?? '') ?>"></div>
      <div class="col-12"><label class="form-label small" for="aType">Type</label><select class="form-select" id="aType" name="type"><?php foreach (Accounting::TYPES as $k => $l): ?><option value="<?= $k ?>"<?= ($e['type'] ?? 'ASSET') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label small" for="aDesc">Description</label><input class="form-control" id="aDesc" name="description" maxlength="255" value="<?= h($e['description'] ?? '') ?>"></div>
      <div class="col-12 d-flex flex-wrap gap-3"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_cash" value="1"<?= (int) ($e['is_cash'] ?? 0) ? ' checked' : '' ?>> Cash or bank account</label>
        <label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1"<?= (int) ($e['is_active'] ?? 1) ? ' checked' : '' ?>> Active</label></div>
    </div>
    <div class="d-flex gap-2 mt-3"><?php if ($e): ?><a class="btn btn-outline-secondary" href="<?= url('accounting', ['tab' => 'accounts']) ?>">Cancel</a><?php endif; ?><button class="btn btn-primary ms-auto">Save account</button></div>
    <div class="form-text">An account with entries keeps its type. Cash or bank accounts can be reconciled against statements.</div>
  </form></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'mappings'):
    $map = Accounting::map();
    $sel = fn(string $key, bool $required, ?callable $only = null) => '<select class="form-select form-select-sm" name="map[' . h($key) . ']"' . ($canEdit ? '' : ' disabled') . ' aria-label="Account for ' . h($key) . '">'
        . $accOpt($map[$key] ?? null, $required ? '— choose —' : '— use the default —', $only) . '</select>';
    $rev = fn($a) => in_array($a['type'], ['REVENUE'], true);
    $less = fn($a) => in_array($a['type'], ['CONTRA_REVENUE', 'EXPENSE'], true);
    $cash = fn($a) => (int) $a['is_cash'] === 1;
?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="map">
<div class="row g-3">
  <div class="col-lg-6"><div class="form-section">
    <h2 class="h6">Main accounts</h2>
    <?php foreach (Accounting::MAP_KEYS as $k => $label): ?><div class="mb-2"><label class="form-label small mb-1"><?= h($label) ?></label><?= $sel($k, true) ?></div><?php endforeach; ?>
  </div>
  <div class="form-section">
    <h2 class="h6">Payment methods → cash and bank accounts</h2>
    <?php foreach (PAYMENT_METHODS as $m): ?><div class="mb-2"><label class="form-label small mb-1"><?= h($m) ?></label><?= $sel('method:' . $m, true, $cash) ?></div><?php endforeach; ?>
  </div></div>
  <div class="col-lg-6"><div class="form-section">
    <h2 class="h6">Fee categories → revenue</h2>
    <?php foreach (Assessment::categories() as $c): ?><div class="mb-2"><label class="form-label small mb-1"><?= h($c['name']) ?></label><?= $sel('cat:' . $c['id'], false, $rev) ?></div><?php endforeach; ?>
    <details class="mt-2"><summary class="small fw-semibold">Fee labels with their own account (optional)</summary>
      <div class="mt-2"><?php foreach (Assessment::labels() as $l): ?><div class="mb-2"><label class="form-label small mb-1"><?= h($l['category_name'] . ' · ' . $l['name']) ?></label><?= $sel('label:' . $l['id'], false, $rev) ?></div><?php endforeach; ?></div></details>
  </div>
  <div class="form-section">
    <h2 class="h6">Canteen POS (one entry per accepted shift)</h2>
    <?php foreach (['canteen:cash' => ['Canteen cash collected', $cash], 'canteen:qr' => ['Canteen QR payments', $cash], 'canteen:sales' => ['Canteen sales revenue', $rev], 'canteen:overshort' => ['Cash short and over', $less]] as $k => [$label, $only]): ?><div class="mb-2"><label class="form-label small mb-1"><?= h($label) ?></label><?= $sel($k, true, $only) ?></div><?php endforeach; ?>
  </div>
  <div class="form-section">
    <h2 class="h6">Library</h2>
    <div class="mb-2"><label class="form-label small mb-1">Library fines (overdue, lost, damage)</label><?= $sel('library:fines', false, $rev) ?></div>
  </div>
  <div class="form-section">
    <h2 class="h6">Scholarships and discounts</h2>
    <?php foreach (Discounts::all() as $d): ?><div class="mb-2"><label class="form-label small mb-1"><?= h($d['name']) ?></label><?= $sel('discount:' . $d['id'], false, $less) ?></div><?php endforeach; ?>
    <?php if (!Discounts::all()): ?><p class="small text-muted mb-0">No scholarship or discount programs yet.</p><?php endif; ?>
  </div></div>
</div>
<?php if ($canEdit): ?><div class="d-flex justify-content-end"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save mappings</button></div><?php endif; ?>
<p class="form-text">New entries use the mappings when they are booked; entries already in the ledger keep their accounts.</p>
</form>

<?php else: // reconcile
    $cashAccounts = array_values(array_filter($active, fn($a) => (int) $a['is_cash']));
    $accId = isset($_GET['account']) && ctype_digit((string) $_GET['account']) ? (int) $_GET['account'] : (int) ($cashAccounts[0]['id'] ?? 0);
    $date = In::date($_GET, 'date') ?? date('Y-m-d');
    $v = $accId && RecordList::applied() ? Accounting::reconcileView($accId, $date) : null;
    $history = $accId && RecordList::applied() ? Db::all('SELECT r.*, u.full_name FROM sis_reconciliations r LEFT JOIN sis_users u ON u.id = r.created_by WHERE r.account_id = ? ORDER BY r.statement_date DESC, r.id DESC LIMIT 12', [$accId]) : [];
?>
<form id="accountingReconcileFilters" data-record-filters method="get" class="form-section d-flex flex-wrap gap-2 align-items-end"><input type="hidden" name="r" value="accounting"><input type="hidden" name="tab" value="reconcile"><input type="hidden" name="apply" value="1">
  <div><label class="form-label small" for="rAcc">Cash or bank account</label><select class="form-select form-select-sm" id="rAcc" name="account"><?= implode('', array_map(fn($a) => '<option value="' . (int) $a['id'] . '"' . ((int) $a['id'] === $accId ? ' selected' : '') . '>' . h($a['code'] . ' · ' . $a['name']) . '</option>', $cashAccounts)) ?></select></div>
  <div><label class="form-label small" for="rDate">Statement date</label><input class="form-control form-control-sm" type="date" id="rDate" name="date" value="<?= h($date) ?>"></div>
  <button class="btn btn-sm btn-primary">Show</button>
</form>
<?php if ($v): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reconcile"><input type="hidden" name="account_id" value="<?= $accId ?>"><input type="hidden" name="statement_date" value="<?= h($date) ?>">
<div class="row g-3">
  <div class="col-lg-8"><div class="form-section p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th style="width:2rem"></th><th>Date</th><th>Entry</th><th class="money">In</th><th class="money">Out</th></tr></thead><tbody>
    <?php foreach ($v['lines'] as $l): ?><tr><td><input class="form-check-input" type="checkbox" name="clear[]" value="<?= (int) $l['id'] ?>"<?= Auth::can('accounting.reconcile') ? '' : ' disabled' ?> aria-label="Cleared"></td>
      <td class="small"><?= h(date('m/d/Y', strtotime($l['entry_date']))) ?></td><td class="small"><span class="font-monospace"><?= h($l['entry_no']) ?></span> · <?= h($l['memo']) ?></td>
      <td class="money"><?= Money::cents($l['debit']) ? $fmt($l['debit']) : '' ?></td><td class="money"><?= Money::cents($l['credit']) ? $fmt($l['credit']) : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$v['lines']): ?><tr><td colspan="5" class="text-center text-muted py-4">Nothing to clear up to this date.</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
  <div class="col-lg-4"><div class="form-section">
    <h2 class="h6"><?= h($v['account']['code'] . ' · ' . $v['account']['name']) ?></h2>
    <table class="table table-sm mb-2"><tr><td>Book balance on <?= h(date('M j, Y', strtotime($date))) ?></td><td class="money"><?= Money::fmt($v['book']) ?></td></tr>
      <tr><td>Already cleared</td><td class="money"><?= Money::fmt($v['cleared']) ?></td></tr>
      <tr><td>Not cleared yet</td><td class="money"><?= Money::fmt($v['book'] - $v['cleared']) ?></td></tr></table>
    <?php if (Auth::can('accounting.reconcile')): ?>
    <label class="form-label small" for="rStmt">Statement ending balance ₱</label><input class="form-control mb-2" id="rStmt" name="statement_balance" required inputmode="decimal" placeholder="e.g. 152,300.00">
    <label class="form-label small" for="rNotes">Notes</label><input class="form-control mb-2" id="rNotes" name="notes" maxlength="255" placeholder="e.g. BDO statement no. 0925">
    <button class="btn btn-primary w-100"><i class="bi bi-check2-square me-1"></i>Reconcile</button>
    <div class="form-text">Tick the items that appear on the statement. It saves only when the cleared total equals the statement balance; book bank charges or interest first with a journal entry.</div>
    <?php endif; ?>
  </div>
  <?php if ($history): ?><div class="form-section"><h2 class="h6">Earlier reconciliations</h2><table class="table table-sm small mb-0">
    <?php foreach ($history as $h): ?><tr><td><?= h(date('m/d/Y', strtotime($h['statement_date']))) ?><div class="text-muted"><?= h($h['full_name'] ?? '') ?><?= $h['notes'] ? ' · ' . h($h['notes']) : '' ?></div></td><td class="money"><?= $fmt($h['statement_balance']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
  </div>
</div>
</form>
<?php elseif (!$cashAccounts): ?><div class="alert alert-info">No cash or bank accounts yet. Add one in Chart of Accounts.</div><?php endif; ?>
<?php endif; ?>
<?php echo '<script src="../finance/assets/accounting-workflow.js?v=58" defer></script>';
layout_end();
