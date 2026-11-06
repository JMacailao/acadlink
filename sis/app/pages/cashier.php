<?php
/** Transaction → Cashier: the official receipts (filters, totals, view / void / re-print), and receiving payments. */
declare(strict_types=1);
if (!defined('CASHIER_LIST_MAX')) define('CASHIER_LIST_MAX', 500); // list rows shown; totals still count every receipt
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$regId = isset($_GET['reg']) && ctype_digit((string) $_GET['reg']) ? (int) $_GET['reg'] : null;
$q = trim((string) ($_GET['q'] ?? ''));
if ($isPost) {
    $a = $_POST['action'] ?? '';
    if ($a === 'pay') {
        // The suggested O.R. number left as it is: the series gives the next free number when posting (two cashiers never clash)
        $orNo = trim((string) ($_POST['or_no'] ?? ''));
        if ($orNo !== '' && $orNo === (string) ($_POST['or_suggested'] ?? '')) $orNo = '';
        $res = Cashier::pay((int) $regId, (int) In::money($_POST, 'amount', true, 'Amount', false), (string) ($_POST['method'] ?? ''),
            In::str($_POST, 'reference_no', 60), (string) ($_POST['idem'] ?? ''), In::str($_POST, 'remarks', 255), In::int($_POST, 'series_id'), $orNo !== '' ? $orNo : null, In::int($_POST,'migration_fee'), isset($_POST['expected_paid']) && ctype_digit((string) $_POST['expected_paid']) ? (int) $_POST['expected_paid'] : null);
        flash($res['duplicate'] ? 'warning' : 'success', $res['duplicate'] ? "This payment was already recorded (receipt {$res['receipt_no']}); it was not charged twice." : "Payment recorded. Receipt {$res['receipt_no']}.");
        // From a pop-up: show the receipt in the pop-up; closing it reloads the page behind with the new balance
        redirect('receipt', ['id' => $res['id']] + (isset($_GET['embed']) ? ['embed' => 1] : []));
    }
    if ($a === 'void') {
        $negate = (string) ($_POST['negate'] ?? '');
        if (!in_array($negate, ['0', '1'], true)) throw new AppError('Choose whether to make the O.R. number negative.');
        Cashier::void((int) $_POST['payment_id'], (string) ($_POST['reason'] ?? ''), $negate === '1');
        flash('success', $negate === '1' ? 'Payment voided. Its O.R. number was made negative; new receipts continue with the next number.' : 'Payment voided. The voided receipt keeps its O.R. number.');
    }
    if ($a === 'or_no') {
        Cashier::editNumber((int) $_POST['payment_id'], (string) ($_POST['or_no'] ?? ''), (string) ($_POST['reason'] ?? ''));
        flash('success', 'O.R. number changed.');
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}
$embed = isset($_GET['embed']);
// ---------------------------------------------------------------- receive a payment (also inside the New pop-up)
if ($regId || $q !== '' || isset($_GET['new'])) {
$results = [];
    if ($q !== '') {
        $results = Db::all("SELECT * FROM sis_registrations WHERE status = 'REGISTERED' AND (reg_no = ? OR student_number LIKE ? OR student_name LIKE ?) ORDER BY school_year DESC, id DESC LIMIT 100", [$q, "%$q%", "%$q%"]);
    }
    if ($embed) {
        layout_start('Receive payment', true);
        echo '<div class="embed-wrap">';
    } else {
        layout_start('Receive payment');
        page_title('Receive payment', '<a class="btn btn-outline-secondary" href="' . url('cashier') . '"><i class="bi bi-arrow-left me-1"></i>Official receipts</a>', 'Find the student, receive the payment and print the receipt.');
        echo step_bar(3);
    }
    ?>
<form class="row g-2 mb-3" method="get"><input type="hidden" name="r" value="cashier"><input type="hidden" name="apply" value="1"><input type="hidden" name="new" value="1"><?= $embed ? '<input type="hidden" name="embed" value="1">' : '' ?>
      <div class="col-md-9"><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Student ID, name or registration number" autofocus aria-label="Student ID, name or registration number"></div>
      <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-search"></i> Find</button></div></form>
<?php if ($q !== ''): ?>
<div class="form-section"><table class="table table-sm mb-0"><thead><tr><th>Registration</th><th>Student</th><th>Term</th><th class="money">Assessment</th><th class="money">Balance</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($results as $r): ?><tr><td class="font-monospace small"><?= h($r['reg_no']) ?></td><td><?= h($r['student_name']) ?> <span class="small text-muted"><?= h($r['student_number']) ?></span></td><td class="small"><?= h($r['school_year'] . ' ' . $r['term']) ?></td>
      <td class="money"><?= Money::fmt(Money::cents($r['total_amount'])) ?></td><td class="money"><?= Money::fmt(Registration::balance($r)) ?></td><td><?= badge(Registration::payStatus($r)) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-primary" href="<?= url('cashier', ['reg' => $r['id']] + ($embed ? ['embed' => 1] : [])) ?>">Open</a></td></tr><?php endforeach; ?>
<?php if (!$results): ?><tr><td colspan="7" class="text-muted">No registration found. The student must be registered first.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
    
<?php if ($regId): $r = Registration::get($regId); require __DIR__.'/../views/payment-plan-summary.php'; $bal = Registration::balance($r); $planBlock = PaymentPlans::paymentBlock($r);
      $items = Db::all('SELECT category_name, SUM(amount) AS amount FROM sis_registration_items WHERE registration_id = ? GROUP BY category_name ORDER BY MIN(sort_order)', [$regId]);
      $pays = Db::all('SELECT p.*, u.full_name FROM sis_payments p JOIN sis_users u ON u.id = p.received_by WHERE p.registration_id = ? ORDER BY p.paid_at', [$regId]); ?>
<div class="row g-3 mb-3">
      <div class="col-lg-5">
        <div class="form-section">
          <div class="d-flex"><h2 class="h6 me-auto"><?= h($r['student_name']) ?></h2><?= badge(Registration::payStatus($r)) ?></div>
          <div class="small text-muted mb-2"><?= h($r['student_number'] . ' · ' . $r['reg_no'] . ' · ' . $r['section_name'] . ' · ' . $r['school_year'] . ' ' . $r['term']) ?></div>
          <?php if (Registration::state($r) === 'OPEN'): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-hourglass-split me-1"></i>Enrollment not confirmed yet: the registrar may still change subjects or fees. A down payment is fine; the balance updates if the assessment changes.</div><?php endif; ?>
          <table class="table table-sm mb-2"><?php foreach ($items as $i): ?><tr><td><?= h($i['category_name']) ?></td><td class="money"><?= Money::fmt(Money::cents($i['amount'])) ?></td></tr><?php endforeach; ?>
            <tr class="fw-semibold"><td>Total assessment</td><td class="money"><?= Money::fmt(Money::cents($r['total_amount'])) ?></td></tr>
            <tr><td>Paid</td><td class="money">−<?= Money::fmt(Money::cents($r['amount_paid'])) ?></td></tr>
            <tr class="fw-bold fs-5"><td>Balance</td><td class="money">₱<?= Money::fmt($bal) ?></td></tr></table>
          <a class="small" href="<?= url('registration', ['id' => $regId]) ?>" target="_top">View the full assessment</a>
        </div>
        <?php if ($planBlock && Auth::can('payments.record') && $bal > 0): ?>
        <div class="alert alert-danger" role="alert" data-plan-required><h2 class="h6"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Payment plan required</h2><p class="mb-2 small"><?= h($planBlock) ?></p>
          <?php if (Auth::can('registration')): ?><a class="btn btn-sm btn-danger" target="_top" href="<?= url('registration', ['id' => $regId]) ?>#registrationPlan">Choose the payment plan</a><?php else: ?><p class="small mb-0">Ask the Registrar to choose the student's payment plan, then reload this page.</p><?php endif; ?></div>
        <?php elseif (Auth::can('payments.record') && $bal > 0 && $r['status'] === 'REGISTERED'): ?>
        <form method="post" class="form-section" action="<?= url('cashier', ['reg' => $regId] + ($embed ? ['embed' => 1] : [])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><?php if($r['locked_at']): ?><div class="mb-3"><label class="form-label"><?= ($r['record_origin']??'')==='MIGRATION'?'Opening balance fee':'Pay toward fee' ?></label><select name="migration_fee" class="form-select"><option value="">Allocate to oldest outstanding fee(s)</option><?php foreach(AccountingWorkflow::feeBalances($r) as $fee):$owed=$fee['remaining'];if($owed<=0)continue; ?><option value="<?= (int)$fee['id'] ?>"><?= h($fee['category_name'].' · '.$fee['label_name']) ?> · ₱<?= Money::fmt($owed) ?></option><?php endforeach; ?></select></div><?php endif; ?><input type="hidden" name="idem" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="expected_paid" value="<?= Money::cents($r['amount_paid']) ?>">
          <h2 class="h6">Receive payment</h2>
          <?php $series = OrSeries::all(true); $defSeries = $series[0] ?? null; ?>
          <div class="row g-2">
            <div class="col-6"><label class="form-label small" for="paySeries">O.R. series</label><select class="form-select" id="paySeries" name="series_id" data-or-series="#payOrNo" required>
              <?php foreach ($series as $se): $sug = OrSeries::suggest($se); ?><option value="<?= (int) $se['id'] ?>" data-next="<?= h($sug) ?>"<?= $se === $defSeries ? ' selected' : '' ?> title="<?= h($se['code'] . ' · ' . $se['description']) ?>"><?= h($se['code'] . ($se['prefix'] !== '' ? ' (' . $se['prefix'] . ')' : '')) ?></option><?php endforeach; ?>
              <?php if (!$series): ?><option value="">No active O.R. series</option><?php endif; ?></select></div>
            <?php $defNext = $defSeries ? OrSeries::suggest($defSeries) : ''; ?>
            <div class="col-6"><label class="form-label small" for="payOrNo">O.R. No.</label><input class="form-control font-monospace" id="payOrNo" name="or_no" maxlength="30" autocomplete="off" value="<?= h($defNext) ?>" placeholder="Type the pre-printed O.R. No."><input type="hidden" name="or_suggested" value="<?= h($defNext) ?>" data-or-suggested></div><div class="col-6"><label class="form-label small" for="payAmount">Amount received ₱</label><input class="form-control" id="payAmount" name="amount" required inputmode="decimal" autocomplete="off" placeholder="e.g. 1000.00"><button type="button" class="btn btn-link btn-sm px-0" data-fill="#payAmount" data-fill-value="<?= h(Money::dec($bal)) ?>">Full balance: ₱<?= Money::fmt($bal) ?></button></div>
            <div class="col-6"><label class="form-label small">Method</label><select class="form-select" name="method"><?= list_options(PAYMENT_METHODS, 'CASH') ?></select></div>
            <div class="col-12"><input class="form-control form-control-sm" name="reference_no" maxlength="60" placeholder="Reference no. (GCash, bank, card, check)"></div>
            <div class="col-12"><input class="form-control form-control-sm" name="remarks" maxlength="255" placeholder="Remarks (e.g. down payment, 1st installment)"></div></div>
          <button class="btn btn-success w-100 mt-2"><i class="bi bi-cash"></i> Record payment &amp; print receipt</button>
          <div class="form-text">Partial payments are accepted, up to the balance. Clicking twice records it once. Each O.R. number can be used only once.</div>
        </form>
        <?php endif; ?>
      </div>
      <div class="col-lg-7"><div class="form-section"><h2 class="h6">Payments</h2>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Date</th><th>Receipt</th><th>Method</th><th class="money">Amount</th><th>Cashier</th><th></th></tr></thead><tbody>
        <?php foreach ($pays as $p): ?><tr class="<?= $p['status'] === 'VOIDED' ? 'text-muted' : '' ?>"><td class="small"><?= h($p['paid_at']) ?></td><td><a href="<?= url('receipt', ['id' => $p['id']]) ?>" target="_top"><?= h($p['receipt_no']) ?></a><div class="small"><?= h($p['reference_no']) ?></div></td><td><?= h($p['method']) ?></td>
          <td class="money"><?= Money::fmt(Money::cents($p['amount'])) ?></td><td class="small"><?= h($p['full_name']) ?></td>
          <td><?php if ($p['status'] === 'VOIDED'): ?><?= badge('VOIDED') ?><div class="small"><?= h($p['void_reason']) ?></div>
            <?php elseif(AccountingWorkflow::receiptLocked((int)$p['id'])): ?><span class="badge text-bg-secondary">Accounting posted · locked</span><?php elseif (Auth::can('payments.void')): ?><form method="post" class="input-group input-group-sm" data-confirm="Void this payment? The receipt is cancelled."><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
              <input class="form-control" name="reason" required maxlength="255" placeholder="Reason" style="max-width:120px">
              <select class="form-select" name="negate" required aria-label="Make the O.R. number negative so it can be used again?" title="Make the O.R. number negative so it can be used again?" style="max-width:150px"><option value="">O.R. No.…</option><option value="1">Make negative (reuse)</option><option value="0">Keep on voided</option></select>
              <button class="btn btn-outline-danger">Void</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$pays): ?><tr><td colspan="6" class="text-muted">No payments yet.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div>
<?php endif; ?>
    
<?php
    if ($embed) { echo '</div>'; layout_end(true); } else { layout_end(); }
    return;
}

// ---------------------------------------------------------------- official receipts (the list)
$from = In::date($_GET, 'from') ?? date('Y-m-d');
$to = In::date($_GET, 'to') ?? $from;
if ($to < $from) [$from, $to] = [$to, $from];
$fStatus = in_array($_GET['status'] ?? '', ['POSTED', 'VOIDED'], true) ? $_GET['status'] : '';
$fMethod = in_array($_GET['method'] ?? '', PAYMENT_METHODS, true) ? $_GET['method'] : '';
$fOr = trim((string) ($_GET['or'] ?? ''));
$fPayer = trim((string) ($_GET['payer'] ?? ''));
$fCashier = isset($_GET['cashier']) && ctype_digit((string) $_GET['cashier']) ? (int) $_GET['cashier'] : null;
$where = ['DATE(p.paid_at) BETWEEN ? AND ?']; $args = [$from, $to];
if ($fStatus) { $where[] = 'p.status = ?'; $args[] = $fStatus; }
if ($fMethod) { $where[] = 'p.method = ?'; $args[] = $fMethod; }
if ($fOr !== '') { $where[] = 'p.receipt_no LIKE ?'; $args[] = "%$fOr%"; }
if ($fPayer !== '') { $where[] = '(r.student_name LIKE ? OR r.student_number LIKE ?)'; array_push($args, "%$fPayer%", "%$fPayer%"); }
if ($fCashier) { $where[] = 'p.received_by = ?'; $args[] = $fCashier; }
$rows = RecordList::query('SELECT p.*, r.reg_no, r.student_name, r.student_number, r.term, u.full_name, se.code AS series_code, COALESCE(se.allow_edit, 1) AS allow_edit
                   FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id JOIN sis_users u ON u.id = p.received_by LEFT JOIN sis_or_series se ON se.id = p.series_id
                  WHERE ' . implode(' AND ', $where) . ' ORDER BY p.paid_at, p.id LIMIT ' . (CASHIER_LIST_MAX + 1), $args);
// The list shows at most CASHIER_LIST_MAX receipts; the totals always count every matching receipt.
$shownMore = count($rows) > CASHIER_LIST_MAX; if ($shownMore) array_pop($rows);
$col = fn(array $p) => match ($p['method']) { 'CASH' => 'cash', 'CHECK' => 'check', 'CARD' => 'card', default => 'other' };
$tot = ['cash' => 0, 'check' => 0, 'card' => 0, 'other' => 0]; $allCount = count($rows); $allVoided = count(array_filter($rows, fn($p) => $p['status'] === 'VOIDED'));
if ($shownMore) {
    $agg = Db::all("SELECT p.method, p.status, COUNT(*) n, SUM(p.amount) amt FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE " . implode(' AND ', $where) . ' GROUP BY p.method, p.status', $args);
    $allCount = (int) array_sum(array_column($agg, 'n')); $allVoided = (int) array_sum(array_map(fn($g) => $g['status'] === 'VOIDED' ? $g['n'] : 0, $agg));
    foreach ($agg as $g) if ($g['status'] === 'POSTED') $tot[$col($g)] += Money::cents($g['amt']);
} else foreach ($rows as $p) if ($p['status'] === 'POSTED') $tot[$col($p)] += Money::cents($p['amount']);
$cashiers = Db::all("SELECT DISTINCT u.id, u.full_name FROM sis_users u JOIN sis_payments p ON p.received_by = u.id ORDER BY u.full_name");
$cashierName = $fCashier ? (string) Db::val('SELECT full_name FROM sis_users WHERE id = ?', [$fCashier]) : 'All cashiers';
$filtersOn = $fStatus || $fMethod || $fOr !== '' || $fPayer !== '' || $fCashier || $from !== date('Y-m-d') || $to !== $from;
$showFilters = ($_GET['filters'] ?? '') !== 'hide';
$fmt = fn(int $c) => Money::fmt($c);
$keep = array_filter(['from' => $from, 'to' => $to, 'status' => $fStatus, 'method' => $fMethod, 'or' => $fOr, 'payer' => $fPayer, 'cashier' => $fCashier], fn($v) => $v !== '' && $v !== null);
if(RecordList::applied())$keep['apply']='1';
layout_start('Cashier');
RecordList::panel();
$docFeesWaiting = (int) Db::val("SELECT COUNT(*) FROM sis_document_requests WHERE status = 'PENDING_PAYMENT'");
?>
<?php if ($docFeesWaiting): ?><div class="alert alert-info d-flex flex-wrap gap-2 align-items-center d-print-none"><i class="bi bi-file-earmark-text"></i><span><?= $docFeesWaiting ?> document request<?= $docFeesWaiting === 1 ? '' : 's' ?> from the Student Portal waiting for payment.</span><a class="btn btn-sm btn-primary" href="<?= url('docfees') ?>">Document Fees</a></div><?php endif; ?>
<style>@media print { @page { size: landscape; margin: 10mm; } }</style>
<div class="or-screen">
  <!-- toolbar -->
  <div class="or-toolbar d-print-none" role="toolbar" aria-label="Official receipts">
    <button type="button" class="tb" id="tbView" disabled data-row="frame" data-row-key="receipt" data-row-default data-frame-title="Official receipt" data-frame-size="lg"><i class="bi bi-eye"></i>View</button>
    <?php if (Auth::can('payments.record')): ?><a class="tb" href="<?= url('cashier', ['new' => 1]) ?>" data-frame="<?= url('cashier', ['new' => 1, 'embed' => 1]) ?>" data-frame-title="Receive payment" data-frame-size="xl"><i class="bi bi-plus-circle text-success"></i>New</a><?php endif; ?>
    <?php if (Auth::can('payments.void')): ?><button type="button" class="tb" id="tbVoid" disabled data-row="modal" data-row-if="voidable" data-bs-toggle="modal" data-bs-target="#mVoidPay"><i class="bi bi-slash-circle text-danger"></i>Void</button><?php endif; ?>
    <?php if (Auth::can('payments.void')): ?><button type="button" class="tb" id="tbOrNo" disabled data-row="modal" data-row-if="editable" data-bs-toggle="modal" data-bs-target="#mOrNo"><i class="bi bi-pencil-square"></i>Edit O.R. No.</button><?php endif; ?>
    <button type="button" class="tb" id="tbReprint" disabled data-row="frame" data-row-key="receipt" data-frame-title="Re-print official receipt" data-frame-size="lg"><i class="bi bi-printer"></i>Re-print</button>
    <span class="tb-sep"></span>
    <a class="tb<?= $showFilters ? ' active' : '' ?>" href="<?= url('cashier', $keep + ['filters' => $showFilters ? 'hide' : 'show']) ?>"><i class="bi bi-funnel"></i><?= $showFilters ? 'Hide' : 'Show' ?> Filters</a>
    <button type="submit" form="orFilters" class="tb"><i class="bi bi-check2-circle text-success"></i>Apply Filters</button>
    <button type="button" class="tb" data-print><i class="bi bi-printer"></i>Print List</button>
    <?= alt_search('cashier', $keep, 'payer', 'Payer name or Student ID') ?>
    <a class="tb" href="<?= url('cashier', $keep) ?>"><i class="bi bi-arrow-clockwise text-success"></i>Refresh</a>
  </div>

  <div class="or-body">
    <div class="or-main">
      <!-- filters -->
      <form id="orFilters" data-record-filters method="get" class="or-filters d-print-none<?= $showFilters ? '' : ' d-none' ?>"><input type="hidden" name="r" value="cashier"><input type="hidden" name="apply" value="1">
        <div class="or-filters-title">Filter Preferences</div>
        <div class="fp-grid">
          <?= fp_row('O.R. Date Range', '<input class="form-control form-control-sm" type="date" name="from" value="' . h($from) . '" aria-label="From"><span>–</span><input class="form-control form-control-sm" type="date" name="to" value="' . h($to) . '" aria-label="To">', true, true) ?>
          <?= fp_row('O.R. Status', '<select class="form-select form-select-sm" name="status" aria-label="O.R. status"><option value="POSTED"' . ($fStatus === 'POSTED' ? ' selected' : '') . '>Posted</option><option value="VOIDED"' . ($fStatus === 'VOIDED' ? ' selected' : '') . '>Voided</option></select>', $fStatus !== '') ?>
          <?= fp_row('Payment Method', '<select class="form-select form-select-sm" name="method" aria-label="Payment method">' . list_options(PAYMENT_METHODS, $fMethod ?: PAYMENT_METHODS[0]) . '</select>', $fMethod !== '') ?>
          <?= fp_row('O.R. No.', '<input class="form-control form-control-sm" name="or" value="' . h($fOr) . '" placeholder="e.g. 0000012" aria-label="O.R. number">', $fOr !== '') ?>
          <?= fp_row('Cashier', '<select class="form-select form-select-sm" name="cashier" aria-label="Cashier">' . opt($cashiers, 'id', 'full_name', $fCashier) . '</select>', (bool) $fCashier) ?>
        </div>
        <?php if ($fPayer !== ''): ?><input type="hidden" name="payer" value="<?= h($fPayer) ?>"><?php endif; ?>
        <div class="fp-actions"><?php if ($filtersOn): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('cashier') ?>">Clear</a><?php endif; ?><button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Apply</button></div>
      </form>

      <div class="print-only"><?php $docTitle = 'Collections · ' . ($from === $to ? date('F j, Y', strtotime($from)) : date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to))) . ' · ' . $cashierName; $docRight = ''; require __DIR__ . '/../views/print_header.php'; ?></div>
      <!-- the receipts -->
      <?php if ($shownMore): ?><div class="alert alert-warning py-2 mb-2">Showing the first <?= number_format(CASHIER_LIST_MAX) ?> of <?= number_format($allCount) ?> receipts. The totals include all <?= number_format($allCount) ?>. Narrow the dates, cashier or payment method to see the rest.</div><?php endif; ?>
      <div class="or-grid-wrap">
        <table class="or-grid" id="orGrid" data-select>
          <thead><tr><th>Official Receipt Number</th><th>O.R. Date</th><th>Payer Name</th><th class="n">Cash Amount</th><th class="n">Check Amount</th><th class="n">Credit Card Amount</th><th class="n">Other Amount</th><th class="n">Total Amount</th><th>Status</th><th>Cashier Name</th><th>Remarks</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $p): $c = $col($p); $amt = Money::cents($p['amount']); $void = $p['status'] === 'VOIDED';$accountingLocked=AccountingWorkflow::receiptLocked((int)$p['id']);$accountingState=AccountingWorkflow::receiptState((int)$p['id']); ?>
            <tr class="<?= $void ? 'is-void' : '' ?>" tabindex="0" data-id="<?= (int) $p['id'] ?>" data-receipt="<?= h(url('receipt', ['id' => $p['id'], 'embed' => 1])) ?>" data-voidable="<?= $void || $accountingLocked ? '0' : '1' ?>" data-editable="<?= !$void && !$accountingLocked && $accountingState!=='Reversed · awaiting cashier void' && (int) $p['allow_edit'] ? '1' : '0' ?>" data-orno="<?= h($p['receipt_no']) ?>"
                data-label="<?= h($p['receipt_no'] . ' · ' . $p['student_name'] . ' · ₱' . $fmt($amt)) ?>">
              <td class="mono"><?= h($p['receipt_no']) ?></td>
              <td><?= h(date('m/d/Y', strtotime($p['paid_at']))) ?></td>
              <td class="payer"><?= h(mb_strtoupper($p['student_name'])) ?><span class="sub"><?= h($p['student_number'] . ' · ' . $p['reg_no']) ?></span></td>
              <?php foreach (['cash', 'check', 'card', 'other'] as $k): ?><td class="n"><?= $c === $k ? $fmt($amt) : '0.00' ?></td><?php endforeach; ?>
              <td class="n fw-semibold"><?= $fmt($amt) ?></td>
              <td><?= $void ? '<span class="badge text-bg-danger">VOID</span>' : '<span class="badge text-bg-success">RECEIVED</span><div class="small">'.h($accountingState).'</div>' ?></td>
              <td><?= h($p['full_name']) ?></td>
              <?php $rem = trim(($c === 'other' ? $p['method'] . ' ' : '') . ($p['reference_no'] ? 'Ref ' . $p['reference_no'] . ' ' : '') . ($void ? 'VOID: ' . $p['void_reason'] : (string) $p['remarks'])); ?>
              <td class="rem" title="<?= h($rem) ?>"><?= h($rem) ?></td>
            </tr>
          <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="11">No official receipts for these filters.<?= Auth::can('payments.record') ? ' Click <strong>New</strong> to receive a payment.' : '' ?></td></tr><?php endif; ?>
          </tbody>
          <?php if ($rows): ?><tfoot><tr><td colspan="3"><?= number_format($allCount) ?> receipt(s)<?= $shownMore ? ' (totals for all)' : '' ?><?= $allVoided ? ' · voided ones not counted' : '' ?></td>
            <td class="n"><?= $fmt($tot['cash']) ?></td><td class="n"><?= $fmt($tot['check']) ?></td><td class="n"><?= $fmt($tot['card']) ?></td><td class="n"><?= $fmt($tot['other']) ?></td><td class="n"><?= $fmt(array_sum($tot)) ?></td><td colspan="3"></td></tr></tfoot><?php endif; ?>
        </table>
      </div>
    </div>

    <!-- right panel -->
    <aside class="or-side">
      <?= sub_explorer() ?>
      <div class="or-panel">
        <div class="or-panel-title">Sub-Components</div>
        <ul class="or-links">
          <?php if (Auth::can('payments.record')): ?><li><a href="<?= url('cashier', ['new' => 1]) ?>" data-frame="<?= url('cashier', ['new' => 1, 'embed' => 1]) ?>" data-frame-title="Receive payment" data-frame-size="xl"><i class="bi bi-cash-coin"></i>Receive Payment</a></li><?php endif; ?>
          <li><a href="#" id="lnkReprint" class="disabled-link" aria-disabled="true" data-row="frame" data-row-key="receipt" data-frame-title="Re-print official receipt" data-frame-size="lg"><i class="bi bi-printer"></i>Re-Print Official Receipt</a></li>
          <li><a href="<?= url('reports', ['rep' => 'cashier_details', 'run' => 1] + $keep) ?>" data-frame="<?= url('reports', ['rep' => 'cashier_details', 'run' => 1, 'embed' => 1, 'from' => $from, 'to' => $to] + ($fCashier ? ['cashier' => $fCashier] : [])) ?>" data-frame-title="Cashier Report Details" data-frame-size="xl"><i class="bi bi-file-earmark-text"></i>Cashier Report Details</a></li>
          <li><a href="<?= url('reports') ?>"><i class="bi bi-printer"></i>Report Manager</a></li>
          <li><a href="<?= url('registration') ?>"><i class="bi bi-journal-check"></i>Registrations and Balances</a></li>
          <li><a href="<?= url('search') ?>"><i class="bi bi-search"></i>Find a Student</a></li>
          <?php if (Auth::can('settings.manage')): ?><li><a href="<?= url('settings') ?>"><i class="bi bi-gear"></i>Receipt Settings</a></li><?php endif; ?>
        </ul>
      </div>
      <div class="or-panel">
        <div class="or-panel-title">Summaries</div>
        <div class="or-sum">
          <label>Cashier Name</label><div class="or-box"><?= h(mb_strtoupper($cashierName)) ?></div>
          <label>Reference Date</label><div class="or-box"><?= h($from === $to ? date('m/d/Y', strtotime($from)) : date('m/d/Y', strtotime($from)) . ' – ' . date('m/d/Y', strtotime($to))) ?></div>
          <label>Total Cash</label><div class="or-box n"><?= $fmt($tot['cash']) ?></div>
          <label>Total Check</label><div class="or-box n"><?= $fmt($tot['check']) ?></div>
          <label>Total Card</label><div class="or-box n"><?= $fmt($tot['card']) ?></div>
          <label>Total Others <span class="text-muted">(GCash, Maya, Bank)</span></label><div class="or-box n"><?= $fmt($tot['other']) ?></div>
          <label>Grand Total</label><div class="or-box n grand"><?= $fmt(array_sum($tot)) ?></div>
        </div>
      </div>
    </aside>
  </div>
</div>

<?php if (Auth::can('payments.void')): ?>
<div class="modal fade" id="mVoidPay" tabindex="-1" aria-labelledby="mVoidPayT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post" data-confirm="Void this official receipt? The payment is cancelled and the balance goes back up."><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="payment_id" id="voidPaymentId" value="" data-row-fill="id">
  <div class="modal-header"><h2 class="modal-title fs-5 text-danger" id="mVoidPayT"><i class="bi bi-slash-circle me-1"></i>Void official receipt</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><p class="fw-semibold mb-2" id="voidLabel" data-row-fill="label"></p>
    <label class="form-label small" for="voidReason">Reason</label><input class="form-control" id="voidReason" name="reason" required maxlength="255" placeholder="e.g. wrong amount, wrong student">
    <div class="form-text">The receipt is kept and marked VOID. Record the payment again if needed.</div>
    <fieldset class="mt-3"><legend class="form-label small fw-semibold mb-1">Convert the O.R. number to negative so it can be used again?</legend>
      <div class="form-check"><input class="form-check-input" type="radio" name="negate" id="negYes" value="1" required><label class="form-check-label" for="negYes">Yes, make it negative (e.g. OR-0000012 → <span class="font-monospace">-OR-0000012</span>). Only for a receipt that was never printed or given out: the number may then be typed by hand on a new receipt. The cashier keeps suggesting the next number.</label></div>
      <div class="form-check"><input class="form-check-input" type="radio" name="negate" id="negNo" value="0"><label class="form-check-label" for="negNo">No, the voided receipt keeps its number. It can't be used again.</label></div>
    </fieldset></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Void receipt</button></div>
  </form>
</div></div></div>
<div class="modal fade" id="mOrNo" tabindex="-1" aria-labelledby="mOrNoT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="or_no"><input type="hidden" name="payment_id" value="" data-row-fill="id">
  <div class="modal-header"><h2 class="modal-title fs-5" id="mOrNoT"><i class="bi bi-pencil-square me-1"></i>Edit O.R. number</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><p class="fw-semibold mb-2" data-row-fill="label"></p>
    <label class="form-label small" for="orNoNew">New O.R. No.</label><input class="form-control font-monospace" id="orNoNew" name="or_no" required maxlength="30" autocomplete="off" data-row-fill="orno">
    <label class="form-label small mt-2" for="orNoReason">Reason</label><input class="form-control" id="orNoReason" name="reason" required maxlength="255" placeholder="e.g. printed on the wrong pre-printed form">
    <div class="form-text">The new number must not be used by any other receipt. The change is kept in the activity log.</div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save O.R. No.</button></div>
  </form>
</div></div></div>
<?php endif; ?>
<?php layout_end();
