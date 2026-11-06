<?php
/** Student portal: the student's own registrations, assessment and payments. */
declare(strict_types=1);
$num = Auth::studentNumber();
if (!$num) {
    layout_start('My account');
    page_title('Welcome, ' . Auth::user()['full_name']);
    echo '<div class="alert alert-info">Your student record has not been linked yet. You can submit and follow your admission application. Grades and assessments become available after staff verify your Student ID.</div><a class="btn btn-primary" href="' . url('myadmission') . '">My admission application</a>';
    layout_end(); return;
}
$s = GS::studentByNumber($num);
$regs = Db::all('SELECT r.*, n.name AS assessment_name FROM sis_registrations r LEFT JOIN sis_assessment_names n ON n.id = r.assessment_name_id WHERE r.student_number = ? AND r.status = "REGISTERED" ORDER BY r.school_year DESC, r.id DESC', [$num]);
$pays = Db::all('SELECT p.*, r.reg_no FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE r.student_number = ? ORDER BY p.paid_at DESC', [$num]);
layout_start('My account');
page_title($s['studentName'] ?? Auth::user()['full_name'], '', h($num . ($s ? ' · ' . $s['sectionName'] . ' · ' . $s['yearLevel'] . ' · ' . $s['schoolYear'] : '')));
?>
<?php
echo '<div class="form-section d-flex justify-content-end flex-wrap gap-2"><a class="btn btn-outline-primary" href="'.url('history',['tab'=>'grades']).'">History of Grades</a><a class="btn btn-outline-primary" href="'.url('history',['tab'=>'assessments']).'">History of Assessments</a></div>';
echo '<div class="form-section"><a class="btn btn-primary" href="'.url('myenrollment').'">Apply for Enrollment / Track my application</a></div>';
$regs = array_values(array_filter($regs, function($r) { $a=Db::one("SELECT * FROM sis_portal_enrollments WHERE registration_id=?",[$r['id']]); return !$a || PortalEnrollment::published($a,$r); }));
// Grade visibility (Settings → Student Portal): grades tagged complete are shown; the school may hold a term's grades while its balance is unpaid
$completions = $s ? GS::completions($s['id']) : [];
$held = [];
if (Settings::on('portal_hide_grades_unpaid')) {
    $completions = array_values(array_filter($completions, function ($c) use ($num, &$held) {
        $owed = 0;
        foreach (Db::all("SELECT * FROM sis_registrations WHERE student_number = ? AND school_year = ? AND status <> 'CANCELLED'" . (($c['term'] ?? '') !== '' ? ' AND term = ?' : ''),
            array_merge([$num, (string) ($c['schoolYear'] ?? '')], ($c['term'] ?? '') !== '' ? [$c['term']] : [])) as $r) $owed += max(0, Registration::balance($r));
        if ($owed > 0) { $held[] = GS::completionPeriod($c) . ' (balance ₱' . Money::fmt($owed) . ')'; return false; }
        return true;
    }));
}
?>
<div class="form-section"><h2 class="h6">My final grades</h2>
  <?php if ($held): ?><div class="alert alert-warning py-2 small"><i class="bi bi-lock"></i> Your final grades for <strong><?= h(implode(', ', $held)) ?></strong> are ready and will show once the balance is paid.</div><?php endif; ?>
  <?= grades_html($completions, $held ? 'Other final grades will appear here once the registrar tags them complete.' : 'Your final grades will appear here once the registrar tags them complete.') ?></div>
<?php
foreach ($regs as $r):
    $items = Db::all('SELECT * FROM sis_registration_items WHERE registration_id = ? ORDER BY sort_order, category_name, id', [$r['id']]);
    $subs = Db::all('SELECT * FROM sis_registration_subjects WHERE registration_id = ? ORDER BY subject_code', [$r['id']]); ?>
  <div class="form-section">
    <div class="d-flex flex-wrap gap-2 mb-2"><h2 class="h6 me-auto mb-0"><?= h($r['school_year'] . ' · ' . $r['term'] . ' · ' . $r['section_name']) ?></h2><?= badge(Registration::payStatus($r)) ?></div>
    <div class="row g-3"><div class="col-md-6"><table class="table table-sm small mb-0"><thead><tr><th>Subject</th><th class="text-end">Units</th></tr></thead><tbody>
      <?php foreach ($subs as $x): ?><tr><td><?= h(trim($x['subject_code'] . ' ' . $x['subject_name'])) ?></td><td class="text-end"><?= h($x['units']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="col-md-6"><table class="table table-sm small mb-0"><tbody>
      <?php foreach ($items as $it): ?><tr><td><?= h($it['label_name']) ?></td><td class="money"><?= Money::fmt(Money::cents($it['amount'])) ?></td></tr><?php endforeach; ?>
      <tr class="fw-semibold"><td>Total</td><td class="money"><?= Money::fmt(Money::cents($r['total_amount'])) ?></td></tr><tr><td>Paid</td><td class="money"><?= Money::fmt(Money::cents($r['amount_paid'])) ?></td></tr>
      <tr class="fw-bold"><td>Balance</td><td class="money">₱<?= Money::fmt(Registration::balance($r)) ?></td></tr></tbody></table></div></div>
  </div>
<?php require SIS_ROOT.'/app/views/payment-plan-summary.php'; endforeach; if (!$regs): ?><div class="alert alert-info">You are not registered for a term yet.</div><?php endif; ?>
<div class="form-section"><h2 class="h6">Receipts</h2><table class="table table-sm mb-0"><tbody>
<?php foreach ($pays as $p): ?><tr class="<?= $p['status'] === 'VOIDED' ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= h(substr($p['paid_at'], 0, 10)) ?></td><td><a href="<?= url('receipt', ['id' => $p['id']]) ?>"><?= h($p['receipt_no']) ?></a></td><td><?= h($p['method']) ?></td><td class="money"><?= Money::fmt(Money::cents($p['amount'])) ?></td></tr><?php endforeach; ?>
<?php if (!$pays): ?><tr><td class="text-muted">No payments yet.</td></tr><?php endif; ?></tbody></table></div>
<?php layout_end();
