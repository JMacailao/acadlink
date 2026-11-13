<?php
/** Library fines: unpaid (by borrower), recent payments made at the Cashier, and waivers. */
declare(strict_types=1);
/** @var bool $isPost */
if ($isPost) { Library::waive((int) ($_POST['fine'] ?? 0), (string) ($_POST['reason'] ?? '')); flash('success', 'Fine waived.'); redirect('fines'); }
$status = in_array($_GET['status'] ?? '', ['UNPAID', 'PAID', 'WAIVED'], true) ? $_GET['status'] : 'UNPAID';
$q = trim((string) ($_GET['q'] ?? ''));
$rows = Db::all('SELECT f.*, p.receipt_no FROM library_fines f LEFT JOIN library_fine_payments p ON p.id = f.payment_id WHERE f.status = ?' . ($q !== '' ? ' AND (f.borrower_name LIKE ? OR f.borrower_no LIKE ?)' : '') . ' ORDER BY f.id DESC LIMIT 500',
    $q !== '' ? [$status, "%$q%", "%$q%"] : [$status]);
$total = array_sum(array_map(fn($r) => (int) $r['amount_cents'], $rows));
$peso = fn(int $c) => '₱' . Money::fmt($c);
layout_start('Fines');
lib_page_head('coin', 'Fines', 'Overdue, lost and damage fines. They are paid at the Cashier (SIS or Finance → Library Fines).');
?>
<p class="text-muted small">Fines are charged automatically (overdue on return, lost items, damage) and added to the borrower's account. They are paid at the Cashier: SIS or Finance → Library Fines.</p>
<form method="get" class="d-flex flex-wrap gap-2 mb-2"><input type="hidden" name="r" value="fines">
  <select class="form-select" style="max-width:160px" name="status" aria-label="Status"><?php foreach (['UNPAID' => 'Unpaid', 'PAID' => 'Paid', 'WAIVED' => 'Waived'] as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <input class="form-control" style="max-width:260px" name="q" value="<?= h($q) ?>" placeholder="Borrower name or ID" aria-label="Borrower"><button class="btn btn-outline-secondary">Show</button>
  <span class="ms-auto align-self-center fw-semibold">Total <?= $peso($total) ?></span></form>
<div class="table-responsive form-section"><table class="table table-sm align-middle mb-0"><thead><tr><th>Date</th><th>Borrower</th><th>Fine</th><th class="text-end">Amount</th><th><?= $status === 'PAID' ? 'O.R.' : ($status === 'WAIVED' ? 'Reason' : '') ?></th></tr></thead><tbody>
<?php foreach ($rows as $f): ?><tr><td class="small"><?= h(date('M j, Y', strtotime($f['created_at']))) ?></td><td><?= h($f['borrower_name']) ?> <small class="text-muted"><?= h($f['borrower_no']) ?></small></td>
  <td><?= h(Library::FINE_KINDS[$f['kind']]) ?> · <span class="small"><?= h($f['description']) ?></span></td><td class="text-end"><?= $peso((int) $f['amount_cents']) ?></td>
  <td><?php if ($status === 'PAID'): ?><span class="font-monospace small"><?= h((string) $f['receipt_no']) ?></span><?php elseif ($status === 'WAIVED'): ?><span class="small"><?= h((string) $f['waive_reason']) ?></span><?php elseif (Auth::can('library.waive')): ?>
    <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="fine" value="<?= (int) $f['id'] ?>"><input class="form-control form-control-sm" name="reason" required minlength="8" placeholder="Reason to waive" aria-label="Reason" style="width:170px"><button class="btn btn-sm btn-outline-secondary">Waive</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-3">None.</td></tr><?php endif; ?>
</tbody></table></div>
<?php layout_end();
