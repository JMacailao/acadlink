<?php
/** Supervisor: closed shifts to accept (books one journal entry in Finance) or return to the cashier; shift history. */
declare(strict_types=1);
/** @var bool $isPost */
if ($isPost) {
    $accept = ($_POST['decision'] ?? '') === 'accept';
    $j = Canteen::reviewShift((int) ($_POST['id'] ?? 0), $accept, (string) ($_POST['note'] ?? ''));
    flash('success', $accept ? 'Shift accepted.' . ($j ? ' Its journal entry is waiting in Finance → Review and Post.' : ' It had no sales to book.') : 'Shift returned to the cashier to count again.');
    redirect('shifts');
}
$status = in_array($_GET['status'] ?? '', ['OPEN', 'CLOSED', 'ACCEPTED'], true) ? $_GET['status'] : '';
$from = In::date($_GET, 'from') ?? date('Y-m-d', strtotime('-14 days'));
$to = In::date($_GET, 'to') ?? date('Y-m-d');
$rows = Canteen::shifts(['status' => $status, 'from' => $from, 'to' => $to]);
$waiting = Canteen::shifts(['status' => 'CLOSED']);
$peso = fn(int $c) => '₱' . Money::fmt($c);
$canReview = Auth::can('canteen.supervise');
layout_start('Shifts & Cash Counts');
?>
<?php if ($canReview): ?>
<h2 class="h5">Waiting for review</h2>
<?php foreach ($waiting as $s): $t = Canteen::shiftTotals((int) $s['id']); $d = (int) $s['counted_cents'] - (int) $s['expected_cents']; ?>
<section class="form-section">
  <div class="d-flex flex-wrap justify-content-between gap-3">
    <div><h3 class="h6 mb-1">Shift #<?= (int) $s['id'] ?> · <?= h((string) $s['cashier']) ?></h3>
      <p class="small mb-1"><?= h(date('M j, g:i A', strtotime((string) $s['opened_at']))) ?> – <?= h(date('g:i A', strtotime((string) $s['closed_at']))) ?> · <?= $t['count'] ?> sales · cash <?= $peso($t['cash']) ?> · QR <?= $peso($t['qr']) ?> · refunds <?= $peso($t['refunds']) ?></p>
      <p class="mb-1">Expected <b><?= $peso((int) $s['expected_cents']) ?></b> · Counted <b><?= $peso((int) $s['counted_cents']) ?></b> ·
        <span class="<?= $d === 0 ? 'text-success' : 'text-danger' ?> fw-semibold"><?= $d === 0 ? 'Balanced' : ($d < 0 ? 'Short ' : 'Over ') . $peso(abs($d)) ?></span></p>
      <p class="small text-muted mb-0"><?= h((string) $s['count_detail']) ?><?= $s['close_note'] ? ' · Cashier: ' . h($s['close_note']) : '' ?></p></div>
    <form method="post" class="d-flex flex-wrap gap-2 align-items-start"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
      <input class="form-control form-control-sm" name="note" maxlength="255" placeholder="<?= $d === 0 ? 'Note (optional)' : 'Note (required: short/over)' ?>" aria-label="Note" style="max-width:240px">
      <button class="btn btn-sm btn-primary" name="decision" value="accept">Accept</button>
      <button class="btn btn-sm btn-outline-secondary" name="decision" value="return">Return to cashier</button></form>
  </div>
</section>
<?php endforeach; if (!$waiting): ?><p class="text-muted">No closed shift is waiting.</p><?php endif; ?>
<?php endif; ?>
<form method="get" class="form-section row g-2 align-items-end mt-2"><input type="hidden" name="r" value="shifts">
  <div class="col-6 col-md-3"><label class="form-label small" for="shF">From</label><input class="form-control form-control-sm" type="date" id="shF" name="from" value="<?= h($from) ?>"></div>
  <div class="col-6 col-md-3"><label class="form-label small" for="shT">To</label><input class="form-control form-control-sm" type="date" id="shT" name="to" value="<?= h($to) ?>"></div>
  <div class="col-6 col-md-3"><label class="form-label small" for="shS">Status</label><select class="form-select form-select-sm" id="shS" name="status"><option value="">Any</option><?php foreach (['OPEN' => 'Open', 'CLOSED' => 'Closed, waiting', 'ACCEPTED' => 'Accepted'] as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-3"><button class="btn btn-sm btn-primary w-100">Show</button></div>
</form>
<div class="table-responsive form-section"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Shift</th><th>Cashier</th><th>Opened</th><th>Closed</th><th class="text-end">Expected</th><th class="text-end">Counted</th><th>Status</th><th>Journal</th></tr></thead><tbody>
  <?php foreach ($rows as $s): ?>
  <tr><td>#<?= (int) $s['id'] ?></td><td><?= h((string) $s['cashier']) ?></td><td><?= h(date('M j, g:i A', strtotime((string) $s['opened_at']))) ?></td><td><?= $s['closed_at'] ? h(date('M j, g:i A', strtotime((string) $s['closed_at']))) : '—' ?></td>
    <td class="text-end"><?= $s['expected_cents'] === null ? '—' : $peso((int) $s['expected_cents']) ?></td><td class="text-end"><?= $s['counted_cents'] === null ? '—' : $peso((int) $s['counted_cents']) ?></td>
    <td><?= ['OPEN' => '<span class="badge text-bg-success">Open</span>', 'CLOSED' => '<span class="badge text-bg-warning">Waiting</span>', 'ACCEPTED' => '<span class="badge text-bg-secondary">Accepted</span>'][$s['status']] ?></td>
    <td class="small"><?= $s['entry_no'] ? h($s['entry_no']) . ' · ' . h(ucfirst(strtolower((string) $s['journal_status']))) : '—' ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-3">No shifts in this period.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php layout_end();
