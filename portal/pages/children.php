<?php
/** v62 Student Portal for parents / guardians: their linked children, read-only — grades, class work, attendance, balance, receipts. */
declare(strict_types=1);
$kids = ParentAccess::children();
$pick = (string) ($_GET['sn'] ?? ($kids[0]['student_number'] ?? ''));
layout_start('My children');
if (!$kids) {
    page_title('My children');
    echo '<div class="alert alert-info">No student is linked to your account yet. Ask the Registrar to link your child to your account.</div>';
    layout_end(); return;
}
$d = ParentAccess::summary($pick);
$s = $d['child']['student'];
page_title('My children', '', 'Read-only view of your child\'s records. For questions, contact the Registrar or the teacher.');
?>
<?php if (count($kids) > 1): ?><nav class="portal-kid-tabs mb-3" aria-label="Child"><?php foreach ($kids as $k): ?><a class="btn btn-sm <?= $k['student_number'] === $pick ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= url('children', ['sn' => $k['student_number']]) ?>"<?= $k['student_number'] === $pick ? ' aria-current="page"' : '' ?>><?= h((string) ($k['student']['studentName'] ?? $k['student_number'])) ?></a> <?php endforeach; ?></nav><?php endif; ?>
<section class="form-section">
  <div class="parent-child"><?= StudentPhoto::html($d['photo'], (string) ($s['studentName'] ?? ''), 'md') ?>
    <div><h2 class="h5 mb-1"><?= h((string) ($s['studentName'] ?? '')) ?></h2><p class="small text-muted mb-0"><?= h($pick . ' · ' . ($s['sectionName'] ?? '') . ' · ' . ($s['yearLevel'] ?? '') . ' · ' . ($s['schoolYear'] ?? '')) ?><br>You are linked as: <?= h($d['child']['relationship']) ?></p></div></div>
  <div class="parent-stats">
    <div><small>Balance</small><b<?= $d['balance'] > 0 ? ' class="text-danger"' : '' ?>>₱<?= Money::fmt($d['balance']) ?></b><small><?= $d['balance'] > 0 ? 'to pay at the Cashier' : 'nothing due' ?></small></div>
    <?php $att = ['p' => 0, 'n' => 0, 'ab' => 0]; foreach ($d['classes'] as $c) { $att['p'] += $c['present'] + $c['late'] + $c['excused']; $att['n'] += $c['meetings']; $att['ab'] += $c['absent']; } ?>
    <div><small>Attendance</small><b><?= $att['n'] ? (int) round($att['p'] * 100 / $att['n']) . '%' : '—' ?></b><small><?= $att['n'] ? $att['ab'] . ' absence' . ($att['ab'] === 1 ? '' : 's') . ' in ' . $att['n'] . ' meetings' : 'no meetings recorded' ?></small></div>
    <?php $miss = array_sum(array_column($d['classes'], 'missing')); ?>
    <div><small>Missing work</small><b<?= $miss ? ' class="text-danger"' : '' ?>><?= $miss ?></b><small>past due, not turned in</small></div>
  </div>
</section>
<section class="form-section"><h2 class="h6">Classes this school year</h2>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Class</th><th>Teacher</th><th class="text-end">Class work</th><th class="text-end">Returned</th><th class="text-end">Missing</th><th class="text-end">Present</th><th class="text-end">Late</th><th class="text-end">Absent</th></tr></thead><tbody>
  <?php foreach ($d['classes'] as $c): ?><tr><td><b><?= h($c['code']) ?></b> <?= h($c['name']) ?><small class="d-block text-muted"><?= h($c['term']) ?></small></td><td><?= h($c['teacher']) ?></td>
    <td class="text-end fw-semibold"><?= $c['percent'] === null ? '—' : h(rtrim(rtrim(number_format((float) $c['percent'], 2), '0'), '.')) . '%' ?></td><td class="text-end"><?= $c['returned'] ?>/<?= $c['works'] ?></td>
    <td class="text-end<?= $c['missing'] ? ' text-danger fw-semibold' : '' ?>"><?= $c['missing'] ?></td><td class="text-end"><?= $c['present'] ?></td><td class="text-end"><?= $c['late'] ?></td><td class="text-end<?= $c['absent'] ? ' text-danger' : '' ?>"><?= $c['absent'] ?></td></tr><?php endforeach; ?>
  <?php if (!$d['classes']): ?><tr><td colspan="8" class="text-muted">No classes this school year yet.</td></tr><?php endif; ?></tbody></table></div>
  <p class="small text-muted mt-2 mb-0">"Class work" is the running grade from the LMS. The official final grade is given by the teacher in the Grading System and shows below once the registrar releases it.</p></section>
<section class="form-section"><h2 class="h6">Final grades</h2>
  <?php if ($d['held']): ?><div class="alert alert-warning py-2 small"><i class="bi bi-lock"></i> Final grades for <strong><?= h(implode(', ', $d['held'])) ?></strong> are ready and will show once the balance is paid.</div><?php endif; ?>
  <?= grades_html($d['completions'], 'Final grades appear here once the registrar releases them.') ?></section>
<section class="form-section"><h2 class="h6">Balance by term</h2><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Term</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead><tbody>
  <?php foreach ($d['registrations'] as $r): ?><tr><td><?= h($r['school_year'] . ' · ' . $r['term']) ?></td><td class="money"><?= Money::fmt(Money::cents($r['total_amount'])) ?></td><td class="money"><?= Money::fmt(Money::cents($r['amount_paid'])) ?></td><td class="money fw-semibold">₱<?= Money::fmt(Registration::balance($r)) ?></td></tr><?php endforeach; ?>
  <?php if (!$d['registrations']): ?><tr><td colspan="4" class="text-muted">Not registered for a term yet.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="form-section"><h2 class="h6">Receipts</h2><table class="table table-sm mb-0"><tbody>
  <?php foreach ($d['payments'] as $p): ?><tr class="<?= $p['status'] === 'VOIDED' ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= h(substr((string) $p['paid_at'], 0, 10)) ?></td><td><a href="<?= url('receipt', ['id' => $p['id']]) ?>"><?= h($p['receipt_no']) ?></a></td><td><?= h($p['method']) ?></td><td class="money"><?= Money::fmt(Money::cents($p['amount'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$d['payments']): ?><tr><td class="text-muted">No payments yet.</td></tr><?php endif; ?></tbody></table></section>
<?php layout_end();
