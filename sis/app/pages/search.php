<?php
/** One search box for everything: students, applicants, registrations and receipts (what this user may see). */
declare(strict_types=1);
$q = trim((string) ($_GET['q'] ?? ''));
$like = '%' . mb_strtolower($q) . '%';
$students = $applicants = $regs = $receipts = [];
if (mb_strlen($q) >= 2) {
    if (Auth::can('students')) {
        foreach (Db::all("SELECT id, data FROM docs WHERE col = 'students' AND (LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, '$.studentName'))) LIKE ? OR f_studentId LIKE ?) LIMIT 50", [$like, "%$q%"]) as $r) {
            $students[] = ['id' => $r['id']] + (json_decode((string) $r['data'], true) ?: []);
        }
        usort($students, fn($a, $b) => strcasecmp((string) ($a['studentName'] ?? ''), (string) ($b['studentName'] ?? '')));
    }
    if (Auth::can('admission')) {
        $applicants = Db::all("SELECT * FROM sis_admissions WHERE admission_no LIKE ? OR LOWER(CONCAT(last_name, ', ', first_name)) LIKE ? OR LOWER(CONCAT(first_name, ' ', last_name)) LIKE ? OR student_number LIKE ?
                               ORDER BY FIELD(status, 'PENDING', 'ADMITTED', 'REJECTED'), id DESC LIMIT 30", ["%$q%", $like, $like, "%$q%"]);
    }
    if (Auth::can('registration', 'payments.view')) {
        $regs = Db::all("SELECT * FROM sis_registrations WHERE reg_no LIKE ? OR student_number LIKE ? OR LOWER(student_name) LIKE ? ORDER BY school_year DESC, id DESC LIMIT 30", ["%$q%", "%$q%", $like]);
    }
    if (Auth::can('payments.view')) {
        $receipts = Db::all("SELECT p.*, r.student_name FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE p.receipt_no LIKE ? OR p.reference_no = ? ORDER BY p.id DESC LIMIT 20", ["%$q%", $q]);
    }
}
$total = count($students) + count($applicants) + count($regs) + count($receipts);
layout_start('Search');
page_title('Search', '', 'Type a name, Student ID, applicant number (ADM-…), registration number (REG-…) or receipt number (OR-…).');
?>
<form method="get" class="d-flex gap-2 mb-3"><input type="hidden" name="r" value="search">
  <input class="form-control form-control-lg" name="q" value="<?= h($q) ?>" placeholder="e.g. Cruz, 20250002, REG-2026-000001" autofocus minlength="2">
  <button class="btn btn-primary px-4"><i class="bi bi-search"></i> Find</button></form>
<?php if ($q === ''): ?>
  <p class="text-muted">Tip: press <kbd>/</kbd> on any page to jump to the search box.</p>
<?php elseif (mb_strlen($q) < 2): ?>
  <div class="alert alert-info">Type at least 2 letters or numbers.</div>
<?php elseif (!$total): ?>
  <div class="alert alert-warning">Nothing found for “<?= h($q) ?>”. Check the spelling, or search by Student ID.
    <?php if (Auth::can('admission')): ?> A new student? <a href="<?= url('admission', ['new' => 1]) ?>">Record them in Admission</a>.<?php endif; ?></div>
<?php endif; ?>

<?php if ($students): ?>
<div class="form-section"><h2 class="h6">Students <span class="badge text-bg-light"><?= count($students) ?></span></h2>
  <div class="list-group list-group-flush">
  <?php foreach ($students as $s):
      $reg = Db::one("SELECT * FROM sis_registrations WHERE student_doc_id = ? AND status = 'REGISTERED' ORDER BY school_year DESC, id DESC LIMIT 1", [$s['id']]); ?>
    <div class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
      <div class="me-auto"><a class="fw-semibold" href="<?= url('student', ['id' => $s['id']]) ?>"><?= h($s['studentName'] ?? '') ?></a>
        <div class="small text-muted"><?= h(($s['studentId'] ?? '') . ' · ' . ($s['sectionName'] ?? '') . ' · ' . ($s['schoolYear'] ?? '')) ?>
          <?= $reg ? ' · last registered ' . h($reg['school_year'] . ' ' . $reg['term']) . ' ' . badge(Registration::payStatus($reg)) : ' · not registered in the SIS yet' ?></div></div>
      <a class="btn btn-sm btn-outline-secondary" href="<?= url('student', ['id' => $s['id']]) ?>">Open</a>
      <?php if (Auth::can('registration')): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('registration', ['new' => 1, 'student' => $s['studentId'] ?? '']) ?>"><i class="bi bi-journal-plus"></i> Register</a><?php endif; ?>
      <?php if ($reg && Auth::can('payments.record') && Registration::balance($reg) > 0): ?><a class="btn btn-sm btn-success" href="<?= url('cashier', ['reg' => $reg['id']]) ?>"><i class="bi bi-cash-coin"></i> Receive payment</a><?php endif; ?>
    </div>
  <?php endforeach; ?></div></div>
<?php endif; ?>

<?php if ($applicants): ?>
<div class="form-section"><h2 class="h6">Applicants (Admission) <span class="badge text-bg-light"><?= count($applicants) ?></span></h2>
  <div class="list-group list-group-flush">
  <?php foreach ($applicants as $a): ?>
    <div class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
      <div class="me-auto"><a class="fw-semibold" href="<?= url('admission', ['id' => $a['id']]) ?>"><?= h($a['last_name'] . ', ' . $a['first_name']) ?></a> <?= badge($a['status']) ?>
        <div class="small text-muted"><?= h($a['admission_no'] . ' · ' . trim($a['program'] . ' ' . $a['year_level']) . ' · ' . $a['school_year']) ?><?= $a['student_number'] ? ' · ID ' . h($a['student_number']) : '' ?></div></div>
      <a class="btn btn-sm btn-outline-secondary" href="<?= url('admission', ['id' => $a['id']]) ?>">Open</a>
    </div>
  <?php endforeach; ?></div></div>
<?php endif; ?>

<?php if ($regs): ?>
<div class="form-section"><h2 class="h6">Registrations <span class="badge text-bg-light"><?= count($regs) ?></span></h2>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>No.</th><th>Student</th><th>Term</th><th class="money">Balance</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($regs as $r): ?><tr><td class="font-monospace small"><?= h($r['reg_no']) ?></td><td><?= h($r['student_name']) ?> <span class="small text-muted"><?= h($r['student_number']) ?></span></td>
    <td class="small"><?= h($r['school_year'] . ' ' . $r['term'] . ' · ' . $r['section_name']) ?></td><td class="money"><?= Money::fmt(Registration::balance($r)) ?></td><td><?= badge(Registration::state($r)) ?> <?= Registration::state($r) !== 'VOIDED' ? badge(Registration::payStatus($r)) : '' ?></td>
    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= url('registration', ['id' => $r['id']]) ?>"><?= Registration::state($r) === 'OPEN' && Auth::can('registration') ? 'Open' : 'View' ?></a>
      <?php if ($r['status'] === 'REGISTERED' && Auth::can('payments.record') && Registration::balance($r) > 0): ?><a class="btn btn-sm btn-success" href="<?= url('cashier', ['reg' => $r['id']]) ?>">Receive payment</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<?php if ($receipts): ?>
<div class="form-section"><h2 class="h6">Receipts <span class="badge text-bg-light"><?= count($receipts) ?></span></h2>
  <table class="table table-sm mb-0"><tbody>
  <?php foreach ($receipts as $p): ?><tr><td><a href="<?= url('receipt', ['id' => $p['id']]) ?>"><?= h($p['receipt_no']) ?></a></td><td><?= h($p['student_name']) ?></td><td class="small"><?= h(substr($p['paid_at'], 0, 10)) ?></td>
    <td class="money"><?= Money::fmt(Money::cents($p['amount'])) ?></td><td><?= badge($p['status']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php layout_end();
