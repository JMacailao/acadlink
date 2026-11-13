<?php
/** Supervisor: void and refund requests to approve or reject. */
declare(strict_types=1);
/** @var bool $isPost */
if ($isPost) {
    $approve = ($_POST['decision'] ?? '') === 'approve';
    Canteen::decide((int) ($_POST['id'] ?? 0), $approve, (string) ($_POST['note'] ?? ''));
    flash('success', $approve ? 'Approved.' : 'Rejected.');
    redirect('approvals');
}
$rows = Canteen::pendingRequests();
$peso = fn(int $c) => '₱' . Money::fmt($c);
layout_start('Approvals');
?>
<p class="text-muted">Approving a void cancels the sale and returns its stock. Approving a refund pays the amount back in cash from the cashier's open shift and returns the items to stock.</p>
<?php foreach ($rows as $r): $items = (array) json_decode((string) $r['items'], true); ?>
<section class="form-section">
  <div class="d-flex flex-wrap justify-content-between gap-2">
    <div><h2 class="h6 mb-1"><?= $r['kind'] === 'VOID' ? 'Void' : 'Refund' ?> <?= $peso((int) $r['amount_cents']) ?> · <a href="<?= url('sale', ['id' => $r['sale_id']]) ?>"><?= h($r['receipt_no']) ?></a></h2>
      <p class="small mb-1">Sale of <?= $peso((int) $r['total_cents']) ?> on <?= h(date('M j, g:i A', strtotime((string) $r['sold_at']))) ?> (<?= h(Canteen::METHODS[$r['method']]) ?>) · asked by <b><?= h((string) $r['requester']) ?></b> <?= h(date('M j, g:i A', strtotime((string) $r['requested_at']))) ?></p>
      <p class="small mb-1">Reason: <?= h($r['reason']) ?></p>
      <?php if ($items): ?><p class="small mb-0">Items: <?= h(implode(', ', array_map(fn($iid, $q) => $q . ' × ' . (string) Db::val('SELECT name FROM canteen_sale_items WHERE id = ?', [(int) $iid]), array_keys($items), $items))) ?></p><?php endif; ?>
    </div>
    <form method="post" class="d-flex flex-wrap gap-2 align-items-start"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <input class="form-control form-control-sm" name="note" maxlength="255" placeholder="Note (required to reject)" aria-label="Note" style="max-width:220px">
      <button class="btn btn-sm btn-primary" name="decision" value="approve">Approve</button>
      <button class="btn btn-sm btn-outline-danger" name="decision" value="reject">Reject</button></form>
  </div>
</section>
<?php endforeach; if (!$rows): ?><div class="form-section text-center text-muted py-4"><i class="bi bi-check2-circle fs-3 d-block"></i>Nothing waiting for approval.</div><?php endif;
layout_end();
