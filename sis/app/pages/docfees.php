<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
/** Cashier → Document fees (v52): receive the fee of a Student Portal document request, with an O.R. from the same series. */
$q = trim((string) ($_GET['q'] ?? ''));
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'pay') {
        $res = DocumentRequests::pay((int) ($_POST['id'] ?? 0), (string) ($_POST['method'] ?? 'CASH'), (string) ($_POST['reference_no'] ?? ''), (string) ($_POST['idem'] ?? ''), (string) ($_POST['or_no'] ?? ''));
        flash('success', ($res['duplicate'] ? 'Already recorded: ' : 'Received. ') . 'O.R. ' . $res['receipt_no'] . '. The student was notified.');
        redirect('docfees', ['printed' => $res['id']]);
    }
    if ($a === 'void') { DocumentRequests::voidPayment((int) ($_POST['id'] ?? 0), (string) ($_POST['reason'] ?? '')); flash('success', 'Receipt voided. The request waits for payment again.'); redirect('docfees'); }
    throw new AppError('Unknown action.');
}
$unpaid = DocumentRequests::unpaid($q);
$recent = DocumentRequests::payments(50);
$canPay = Auth::can('payments.record'); $canVoid = Auth::can('payments.void');
$printed = (int) ($_GET['printed'] ?? 0);
layout_start('Document fees');
page_title('Document fees', '<a class="btn btn-outline-secondary" href="' . url('cashier') . '"><i class="bi bi-arrow-left me-1"></i>Cashier</a>', 'Fees of TOR and certificates requested in the Student Portal. Paying here marks the request Paid and tells the student.');
?>
<?php if ($printed): ?><div class="alert alert-success d-flex gap-2 align-items-center"><i class="bi bi-printer"></i><span>Print the receipt:</span> <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="<?= url('docreceipt', ['id' => $printed]) ?>">Print O.R.</a></div><?php endif; ?>
<div class="form-section" data-record-detail>
  <form method="get" class="d-flex flex-wrap gap-2 mb-3"><input type="hidden" name="r" value="docfees">
    <input class="form-control" style="max-width:340px" name="q" value="<?= h($q) ?>" placeholder="Reference, Student ID or name" aria-label="Search unpaid requests"><button class="btn btn-outline-secondary">Search</button></form>
  <h2 class="h6">Waiting for payment</h2>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Request</th><th>Student</th><th>Document</th><th class="money">Amount</th><th>Receive</th></tr></thead><tbody>
  <?php foreach ($unpaid as $r): ?><tr>
    <td class="font-monospace small"><?= h($r['ref_no']) ?></td>
    <td><?= h($r['student_name']) ?><div class="small text-muted"><?= h($r['student_number']) ?></div></td>
    <td><?= (int) $r['copies'] ?> × <?= h(Documents::TYPES[$r['doc_type']][0]) ?><div class="small text-muted">₱<?= Money::fmt(Money::cents($r['fee_per_copy'])) ?> each</div></td>
    <td class="money fw-semibold">₱<?= Money::fmt(Money::cents($r['amount'])) ?></td>
    <td><?php if ($canPay): ?><form method="post" class="d-flex flex-wrap gap-1 align-items-center"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="idem" value="<?= bin2hex(random_bytes(16)) ?>">
      <select class="form-select form-select-sm" style="width:auto" name="method" aria-label="Method"><?php foreach (PAYMENT_METHODS as $m): ?><option><?= $m ?></option><?php endforeach; ?></select>
      <input class="form-control form-control-sm" style="width:130px" name="reference_no" maxlength="60" placeholder="Reference (non-cash)" aria-label="Reference number">
      <input class="form-control form-control-sm" style="width:120px" name="or_no" maxlength="30" placeholder="O.R. (auto)" aria-label="O.R. number">
      <button class="btn btn-sm btn-primary">Receive ₱<?= Money::fmt(Money::cents($r['amount'])) ?></button></form><?php else: ?><span class="small text-muted">Cashier only</span><?php endif; ?></td>
  </tr><?php endforeach; if (!$unpaid): ?><tr><td colspan="5" class="text-center text-muted py-3">No document fees waiting.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<div class="form-section" data-record-detail><h2 class="h6">Recent document fee receipts</h2>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>O.R.</th><th>Date</th><th>Student</th><th>Request</th><th class="money">Amount</th><th>Method</th><th class="text-end"></th></tr></thead><tbody>
  <?php foreach ($recent as $p): ?><tr<?= $p['status'] === 'VOIDED' ? ' class="text-muted"' : '' ?>>
    <td class="font-monospace"><?= h($p['receipt_no']) ?><?= $p['status'] === 'VOIDED' ? ' <span class="badge text-bg-danger">Void</span>' : '' ?></td><td class="small"><?= h(substr($p['paid_at'], 0, 16)) ?></td>
    <td><?= h($p['student_name']) ?></td><td class="small"><?= h($p['ref_no']) ?> · <?= (int) $p['copies'] ?> × <?= h(Documents::TYPES[$p['doc_type']][0]) ?></td>
    <td class="money">₱<?= Money::fmt(Money::cents($p['amount'])) ?></td><td class="small"><?= h($p['method'] . ($p['reference_no'] ? ' ' . $p['reference_no'] : '')) ?></td>
    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= url('docreceipt', ['id' => $p['id']]) ?>"><i class="bi bi-printer"></i></a>
      <?php if ($canVoid && $p['status'] === 'POSTED'): ?><details class="d-inline-block text-start"><summary class="btn btn-sm btn-outline-danger">Void</summary>
        <form method="post" class="mt-1 p-2 border rounded bg-white" style="min-width:240px"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <label class="form-label small mb-1" for="dv<?= (int) $p['id'] ?>">Reason *</label><input class="form-control form-control-sm mb-2" id="dv<?= (int) $p['id'] ?>" name="reason" required minlength="3" maxlength="255">
          <button class="btn btn-sm btn-danger">Void receipt</button></form></details><?php endif; ?></td>
  </tr><?php endforeach; if (!$recent): ?><tr><td colspan="7" class="text-center text-muted py-3">No receipts yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php layout_end();
