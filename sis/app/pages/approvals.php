<?php
declare(strict_types=1);
/**
 * v66: SIS → Operations → Approvals. Fee reductions and reopening of confirmed enrollments:
 * - Waiting: requests that need an approver (approvers approve or decline; the person who asked can cancel).
 * - History: every change that lowered what a student pays, or reopened an enrollment, with its reason — the report.
 * @var bool $isPost
 */
$canApprove = Reductions::canApprove();
$tab = ($_GET['tab'] ?? '') === 'history' ? 'history' : 'waiting';
$sy = preg_match('/^\d{4}-\d{4}$/', (string) ($_GET['sy'] ?? '')) ? (string) $_GET['sy'] : '';
$term = in_array($_GET['term'] ?? '', TERMS, true) ? (string) $_GET['term'] : '';
$q = mb_substr(trim((string) ($_GET['find'] ?? '')), 0, 80);
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    $cid = (int) ($_POST['change_id'] ?? 0);
    if ($a === 'approve' || $a === 'decline') {
        Reductions::decide($cid, $a === 'approve', (string) ($_POST['note'] ?? ''));
        flash('success', $a === 'approve' ? 'Approved. The change was made and the person who asked was notified.' : 'Declined. The person who asked was notified.');
    } elseif ($a === 'cancel') { Reductions::cancel($cid); flash('success', 'Request cancelled.'); }
    else throw new AppError('Unknown action.');
    redirect('approvals', array_filter(['tab' => $tab === 'history' ? 'history' : null]));
}
$rows = Reductions::list($tab === 'history' ? 'HISTORY' : 'PENDING', $sy, $term, $q);
layout_start('Approvals');
page_title('Approvals', $tab === 'history' ? '<a class="btn btn-outline-primary" href="' . h(url('approvals', ['tab' => 'history', 'sy' => $sy, 'term' => $term, 'find' => $q, 'print' => 1])) . '"><i class="bi bi-printer me-1"></i>Print</a>' : '',
    'Fee reductions and reopening of confirmed enrollments. ' . ($canApprove ? 'You can approve them.' : 'An approver (Users and Groups → "Approve fee reductions and reopening") decides on them.'));
?>
<ul class="nav nav-tabs mb-3 d-print-none">
  <li class="nav-item"><a class="nav-link<?= $tab === 'waiting' ? ' active' : '' ?>" href="<?= url('approvals') ?>">Waiting <span class="badge text-bg-warning ms-1"><?= Reductions::pendingCount() ?></span></a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'history' ? ' active' : '' ?>" href="<?= url('approvals', ['tab' => 'history']) ?>">History</a></li>
</ul>
<?php if ($tab === 'history'): ?>
<form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-3 d-print-none"><input type="hidden" name="r" value="approvals"><input type="hidden" name="tab" value="history">
  <div><label class="form-label small mb-0" for="apSy">School year</label><select class="form-select form-select-sm" id="apSy" name="sy"><option value="">All</option><?php foreach (GS::schoolYears() as $y): ?><option<?= $y === $sy ? ' selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0" for="apTerm">Term</label><select class="form-select form-select-sm" id="apTerm" name="term"><option value="">All</option><?= list_options(TERMS, $term ?: null) ?></select></div>
  <div><label class="form-label small mb-0" for="apQ">Student, change or reason</label><input class="form-control form-control-sm" id="apQ" name="find" value="<?= h($q) ?>"></div>
  <button class="btn btn-sm btn-outline-primary">Show</button>
</form>
<?php endif; ?>
<div class="table-responsive bg-white border rounded"><table class="table table-sm align-middle mb-0 approvals-table" data-plain-table>
  <thead class="table-light"><tr><th>Date</th><th>Student</th><th>Change</th><th>Reason</th><th>Asked by</th><th><?= $tab === 'history' ? 'Status' : 'Total now' ?></th><?php if ($tab === 'history'): ?><th class="text-end">Total before → after</th><?php else: ?><th class="d-print-none"></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rows as $c): ?><tr>
    <td class="text-nowrap small"><?= h(date('M j, Y g:i A', strtotime($c['requested_at']))) ?></td>
    <td><a href="<?= url('registration', ['id' => $c['registration_id']]) ?>"><?= h($c['student_name']) ?></a><div class="small text-muted"><?= h($c['reg_no'] . ' · ' . $c['school_year'] . ' ' . $c['term'] . ' · ' . $c['section_name']) ?></div></td>
    <td><?= h($c['summary']) ?></td>
    <td><?= h($c['reason'] !== '' ? $c['reason'] : '—') ?></td>
    <td class="small"><?= h($c['requested_by_name'] ?? '') ?></td>
    <?php if ($tab === 'history'): ?>
    <td><span class="chg-status chg-<?= strtolower($c['status']) ?>"><?= h(Reductions::STATUSES[$c['status']]) ?></span><?= $c['decided_by_name'] && $c['status'] !== 'APPLIED' ? '<div class="small text-muted">' . h($c['decided_by_name']) . ($c['decision_note'] ? ': ' . h($c['decision_note']) : '') . '</div>' : '' ?></td>
    <td class="text-end text-nowrap"><?= $c['total_before'] !== null ? '₱' . Money::fmt(Money::cents($c['total_before'])) : '' ?><?= $c['total_after'] !== null ? ' → ₱' . Money::fmt(Money::cents($c['total_after'])) : '' ?></td>
    <?php else: ?>
    <td class="text-nowrap">₱<?= Money::fmt(Money::cents($c['total_now'])) ?></td>
    <td class="d-print-none" style="min-width:260px"><?php if ($canApprove): ?>
      <form method="post" class="d-flex flex-wrap gap-1 align-items-center"><?= csrf_field() ?><input type="hidden" name="change_id" value="<?= (int) $c['id'] ?>">
        <input class="form-control form-control-sm" name="note" maxlength="500" placeholder="Note (needed to decline)" aria-label="Note" style="flex:1 1 140px">
        <button class="btn btn-sm btn-primary" name="action" value="approve">Approve</button>
        <button class="btn btn-sm btn-outline-danger" name="action" value="decline">Decline</button></form>
      <?php elseif ((int) $c['requested_by'] === (int) Auth::id()): ?><?= post_button('approvals', [], ['action' => 'cancel', 'change_id' => $c['id']], 'Cancel request', 'btn-sm btn-outline-secondary', 'Cancel this request?') ?><?php else: ?><span class="small text-muted">Waiting for an approver</span><?php endif; ?></td>
    <?php endif; ?>
  </tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= $tab === 'history' ? 'No fee reductions or reopening recorded for these filters.' : 'Nothing is waiting for approval.' ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($tab === 'history' && $rows): $sum = 0; foreach ($rows as $c) if ($c['total_before'] !== null && $c['total_after'] !== null && in_array($c['status'], ['APPLIED', 'APPROVED'], true)) $sum += Money::cents($c['total_before']) - Money::cents($c['total_after']); ?>
<p class="small text-muted mt-2"><?= count($rows) ?> change(s). Total lowered by the changes shown: <strong>₱<?= Money::fmt(max(0, $sum)) ?></strong>.</p>
<?php endif; ?>
<?php layout_end();
