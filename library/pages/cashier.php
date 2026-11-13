<?php
/**
 * Cashier → Library Fines (opened from the SIS and from Finance): find a borrower with unpaid library fines, receive the
 * payment with an O.R. from the usual series, re-print or void receipts. Each payment is booked in Accounting.
 */
declare(strict_types=1);
/** @var bool $isPost */
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'pay') {
        [$pid, $no] = Library::payFines((string) ($_POST['type'] ?? ''), (string) ($_POST['ref'] ?? ''), (array) ($_POST['fines'] ?? []), (string) ($_POST['method'] ?? 'CASH'),
            (string) ($_POST['reference'] ?? ''), (string) ($_POST['idem'] ?? ''), (string) ($_POST['or_no'] ?? ''));
        flash('success', 'Library fines paid. O.R. ' . $no . '.');
        redirect('libreceipt', ['id' => $pid]);
    }
    if ($a === 'void') { Library::voidFinePayment((int) ($_POST['id'] ?? 0), (string) ($_POST['reason'] ?? '')); flash('success', 'Receipt voided; the fines are unpaid again.'); redirect('libfines', ['tab' => 'receipts']); }
    throw new AppError('Unknown action.');
}
$tab = ($_GET['tab'] ?? '') === 'receipts' ? 'receipts' : 'unpaid';
$q = trim((string) ($_GET['q'] ?? ''));
[$bType, $bRef] = array_pad(explode(':', (string) ($_GET['b'] ?? ''), 2), 2, '');
$peso = fn(int $c) => '₱' . Money::fmt($c);
layout_start('Library Fines');
?>
<nav class="nav nav-pills gap-2 mb-3"><a class="nav-link<?= $tab === 'unpaid' ? ' active' : '' ?>" href="<?= url('libfines') ?>">To pay</a><a class="nav-link<?= $tab === 'receipts' ? ' active' : '' ?>" href="<?= url('libfines', ['tab' => 'receipts']) ?>">Receipts</a></nav>
<?php if ($tab === 'receipts'): $rows = Library::finePayments(100); ?>
<div class="table-responsive form-section"><table class="table table-sm align-middle mb-0"><thead><tr><th>O.R.</th><th>Date</th><th>Borrower</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $p): ?><tr><td class="font-monospace"><a href="<?= url('libreceipt', ['id' => $p['id']]) ?>"><?= h($p['receipt_no']) ?></a></td><td class="small"><?= h(date('M j, g:i A', strtotime($p['paid_at']))) ?></td><td><?= h($p['borrower_name']) ?> <small class="text-muted"><?= h($p['borrower_no']) ?></small></td>
  <td><?= h($p['method']) ?></td><td class="text-end"><?= $peso((int) $p['amount_cents']) ?></td><td><?= $p['status'] === 'VOIDED' ? '<span class="badge text-bg-secondary">Voided</span>' : '<span class="badge text-bg-success">Posted</span>' ?></td>
  <td class="text-end"><?php if ($p['status'] === 'POSTED' && Auth::can('payments.void')): ?><details><summary class="small">Void</summary><form method="post" class="d-flex gap-1 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input class="form-control form-control-sm" name="reason" required minlength="3" placeholder="Reason" aria-label="Reason"><button class="btn btn-sm btn-outline-danger">Void</button></form></details><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">No library fine receipts yet.</td></tr><?php endif; ?>
</tbody></table></div>
<?php elseif ($bRef !== ''):
    $fines = Library::borrowerFines($bType, $bRef);
    $who = $fines[0] ?? null; ?>
<div class="mb-2"><a class="btn btn-sm btn-outline-secondary" href="<?= url('libfines') ?>"><i class="bi bi-arrow-left me-1"></i>All borrowers</a></div>
<?php if (!$fines): ?><div class="alert alert-success">No unpaid library fines.</div><?php else: ?>
<form method="post" class="form-section" data-confirm="Receive this payment and issue an O.R.?"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="type" value="<?= h($bType) ?>"><input type="hidden" name="ref" value="<?= h($bRef) ?>"><input type="hidden" name="idem" value="<?= bin2hex(random_bytes(16)) ?>">
  <h2 class="h5"><?= h($who['borrower_name']) ?> <small class="text-muted"><?= h(Library::BORROWER_TYPES[$who['borrower_type']]) ?> <?= h($who['borrower_no']) ?></small></h2>
  <table class="table table-sm align-middle"><thead><tr><th style="width:36px"></th><th>Date</th><th>Fine</th><th class="text-end">Amount</th></tr></thead><tbody>
  <?php foreach ($fines as $f): ?><tr><td><input class="form-check-input" type="checkbox" name="fines[]" value="<?= (int) $f['id'] ?>" checked aria-label="Pay this fine"></td><td class="small"><?= h(date('M j, Y', strtotime($f['created_at']))) ?></td><td><?= h(Library::FINE_KINDS[$f['kind']]) ?> · <span class="small"><?= h($f['description']) ?></span></td><td class="text-end"><?= $peso((int) $f['amount_cents']) ?></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><th colspan="3">Total of all unpaid</th><th class="text-end"><?= $peso(array_sum(array_map(fn($f) => (int) $f['amount_cents'], $fines))) ?></th></tr></tfoot></table>
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small" for="lfM">Method</label><select class="form-select" id="lfM" name="method"><?php foreach (PAYMENT_METHODS as $m): ?><option><?= $m ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="lfR">Reference no. (non-cash)</label><input class="form-control" id="lfR" name="reference" maxlength="60"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="lfO">O.R. No. (blank = next)</label><input class="form-control font-monospace" id="lfO" name="or_no" maxlength="30"></div>
    <div class="col-6 col-md-3"><?php if (Auth::can('payments.record')): ?><button class="btn btn-primary w-100">Receive payment</button><?php endif; ?></div>
  </div>
</form><?php endif; ?>
<?php else: $rows = Library::unpaidFines($q); ?>
<form method="get" class="d-flex gap-2 mb-2"><input type="hidden" name="r" value="libfines"><input class="form-control" style="max-width:320px" name="q" value="<?= h($q) ?>" placeholder="Student or teacher name or ID" aria-label="Borrower" autofocus><button class="btn btn-outline-secondary">Find</button></form>
<div class="table-responsive form-section"><table class="table table-sm align-middle mb-0"><thead><tr><th>Borrower</th><th>Type</th><th class="text-end">Fines</th><th class="text-end">Owed</th><th>Since</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= h($r['borrower_name']) ?> <small class="text-muted"><?= h($r['borrower_no']) ?></small></td><td><?= h(Library::BORROWER_TYPES[$r['borrower_type']]) ?></td><td class="text-end"><?= (int) $r['n'] ?></td><td class="text-end fw-semibold"><?= $peso((int) $r['total']) ?></td><td class="small"><?= h(date('M j, Y', strtotime($r['since']))) ?></td>
  <td class="text-end"><a class="btn btn-sm btn-primary" href="<?= url('libfines', ['b' => $r['borrower_type'] . ':' . $r['borrower_ref']]) ?>">Receive</a></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3"><?= $q !== '' ? 'No unpaid library fines for “' . h($q) . '”.' : 'No unpaid library fines.' ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php endif;
layout_end();
