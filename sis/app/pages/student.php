<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$id = (string) ($_GET['id'] ?? '');
$s = GS::get('students', $id);
if (!$s) throw new AppError('Student not found in the grading system.');
$canEdit = Auth::can('students.edit');
if ($isPost && ($_POST['action'] ?? '') === 'details') {
    StudentDetails::save($id, $_POST);
    flash('success', 'Records for documents saved.');
    redirect('student', ['id' => $id]);
}
if ($isPost && in_array($_POST['action'] ?? '', ['parent_create', 'parent_link', 'parent_unlink'], true)) { // v62: parent / guardian accounts
    $no = (string) ($s['studentId'] ?? '');
    $act = (string) $_POST['action'];
    if ($act === 'parent_create') { ParentAccess::create($no, $_POST); flash('success', 'Parent account created and linked. Give them the username and temporary password; they choose their own password at first sign-in.'); }
    elseif ($act === 'parent_link') { ParentAccess::link($no, (string) ($_POST['username'] ?? ''), (string) ($_POST['relationship'] ?? '')); flash('success', 'Parent account linked.'); }
    else { ParentAccess::unlink($no, (int) ($_POST['account'] ?? 0)); flash('success', 'Parent account unlinked from this student.'); }
    redirect('student', ['id' => $id]);
}
if ($isPost && ($_POST['action'] ?? '') === 'photo') { // v62
    StudentPhoto::staffSet((string) ($s['studentId'] ?? ''), $_FILES['photo'] ?? []);
    flash('success', 'Photo saved on the student record.');
    redirect('student', ['id' => $id]);
}
if ($isPost) {
    GS::saveStudent($id, $_POST);
    flash('success', 'Student updated (also in the grading system).');
    redirect('student', ['id' => $id]);
}
$regs = Db::all('SELECT r.*, n.name AS assessment_name FROM sis_registrations r LEFT JOIN sis_assessment_names n ON n.id = r.assessment_name_id WHERE r.student_doc_id = ? OR r.student_number = ? ORDER BY r.school_year DESC, r.id DESC', [$id, $s['studentId'] ?? '']);
$pays = Db::all('SELECT p.*, r.reg_no FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE r.student_doc_id = ? OR r.student_number = ? ORDER BY p.paid_at DESC', [$id, $s['studentId'] ?? '']);
$adm = Db::one('SELECT * FROM sis_admissions WHERE student_number = ?', [$s['studentId'] ?? '']);
layout_start((string) ($s['studentName'] ?? 'Student'));
page_title((string) ($s['studentName'] ?? ''), Auth::can('registration') ? '<a class="btn btn-primary" href="' . url('registration', ['new' => 1, 'student' => $s['studentId'] ?? '']) . '"><i class="bi bi-journal-plus"></i> Register for a term</a>' : '',
    h(($s['studentId'] ?? '') . ' · ' . ($s['sectionName'] ?? '') . ' · ' . ($s['yearLevel'] ?? '') . ' · ' . ($s['schoolYear'] ?? '')));
?>
<div class="row g-3">
<div class="col-lg-4">
  <?php $no = (string) ($s['studentId'] ?? ''); $pend = StudentPhoto::pendingId($no); $cons = $no !== '' ? Privacy::consentOf($no) : null; ?>
  <section class="form-section" id="studentPhoto"><h2 class="h6">Photo</h2>
    <div class="st-photo-box"><?= StudentPhoto::html(StudentPhoto::currentId($no), (string) ($s['studentName'] ?? ''), 'md') ?>
      <div class="st-photo-actions"><?php if ($pend): ?><p class="small mb-2"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>The student sent a new photo. <?php if ($canEdit): ?><a href="<?= url('photos') ?>">Review it</a><?php endif; ?></p><?php endif; ?>
        <?php if ($canEdit && $no !== ''): ?><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="photo"><input class="form-control form-control-sm mb-2" type="file" name="photo" accept="image/png,image/jpeg,image/webp" required aria-label="New photo"><button class="btn btn-sm btn-outline-primary">Replace photo</button></form><?php endif; ?>
        <p class="small text-muted mt-2 mb-0"><i class="bi bi-shield-check me-1" aria-hidden="true"></i><?= $cons ? 'Privacy notice agreed ' . h(date('M j, Y', strtotime($cons['created_at']))) . ' by the ' . ($cons['given_by'] === 'PARENT' ? 'parent/guardian' : strtolower($cons['given_by'])) . '.' : 'No privacy consent on record.' ?></p></div></div>
  </section>
  <?php if ($no !== ''): $parents = ParentAccess::parentsOf($no); ?>
  <section class="form-section" id="studentParents"><h2 class="h6">Parents / guardians <span class="text-muted fw-normal small">(Student Portal, read-only)</span></h2>
    <?php foreach ($parents as $pa): ?><div class="d-flex align-items-center gap-2 py-1 border-bottom"><i class="bi bi-person" aria-hidden="true"></i><span class="me-auto small"><b><?= h($pa['display_name']) ?></b> · <?= h($pa['relationship']) ?><br><span class="text-muted"><?= h((string) $pa['username']) ?> · <?= $pa['status'] === 'ACTIVE' ? ($pa['last_login_at'] ? 'last signed in ' . h(date('M j, Y', strtotime($pa['last_login_at']))) : 'not signed in yet') : 'disabled' ?></span></span>
      <?php if ($canEdit): ?><form method="post" data-confirm="Unlink <?= h($pa['display_name']) ?> from this student? Their account stays."><?= csrf_field() ?><input type="hidden" name="action" value="parent_unlink"><input type="hidden" name="account" value="<?= (int) $pa['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0" aria-label="Unlink <?= h($pa['display_name']) ?>">Unlink</button></form><?php endif; ?></div><?php endforeach; ?>
    <?php if (!$parents): ?><p class="small text-muted mb-2">No parent account yet.</p><?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="mt-2"><summary class="small fw-semibold">Create a parent account</summary>
      <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="parent_create">
        <input class="form-control form-control-sm mb-1" name="full_name" maxlength="150" placeholder="Full name, e.g. Maria Reyes" aria-label="Parent full name" required>
        <div class="d-flex gap-1 mb-1"><input class="form-control form-control-sm" name="username" maxlength="64" placeholder="Username" aria-label="Username" pattern="[a-z0-9._-]{3,64}" required><select class="form-select form-select-sm" name="relationship" aria-label="Relationship"><option>Mother</option><option>Father</option><option>Guardian</option><option>Grandparent</option><option>Sibling</option><option>Other</option></select></div>
        <input class="form-control form-control-sm mb-1" type="email" name="email" maxlength="191" placeholder="Email (optional, for notifications and password reset)" aria-label="Email">
        <input class="form-control form-control-sm mb-1" name="temp_password" minlength="8" maxlength="72" placeholder="Temporary password (8+ letters and numbers)" aria-label="Temporary password" required autocomplete="off">
        <button class="btn btn-sm btn-primary">Create and link</button></form></details>
    <details class="mt-2"><summary class="small fw-semibold">Link an existing parent account</summary>
      <form method="post" class="mt-2 d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="parent_link"><input class="form-control form-control-sm" name="username" placeholder="Parent's username" aria-label="Parent username" required><select class="form-select form-select-sm" name="relationship" aria-label="Relationship"><option>Mother</option><option>Father</option><option>Guardian</option><option>Grandparent</option><option>Sibling</option><option>Other</option></select><button class="btn btn-sm btn-outline-primary">Link</button></form>
      <p class="small text-muted mt-1 mb-0">Use this for brothers and sisters: one parent account can see several children.</p></details>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($canEdit): ?>
  <form method="post" class="form-section" id="studentEdit"><?= csrf_field() ?><input type="hidden" name="_version" value="<?= h(GS::version('students', $id)) ?>">
    <h2 class="h6">Student record (grading system)</h2>
    <label class="form-label small">Student ID</label><input class="form-control form-control-sm mb-2" name="studentId" value="<?= h($s['studentId'] ?? '') ?>" maxlength="40" required>
    <label class="form-label small">Name (Last, First M.)</label><input class="form-control form-control-sm mb-2" name="studentName" value="<?= h($s['studentName'] ?? '') ?>" maxlength="150" required>
    <label class="form-label small">Email</label><input class="form-control form-control-sm mb-2" type="email" name="email" value="<?= h($s['email'] ?? '') ?>" maxlength="191">
    <label class="form-label small">Section</label><select class="form-select form-select-sm mb-2" name="sectionId"><?php foreach (GS::sections() as $x): ?><option value="<?= h($x['id']) ?>"<?= $x['id'] === ($s['sectionId'] ?? '') ? ' selected' : '' ?>><?= h($x['sectionName'] . ' · ' . $x['yearLevel'] . ' · ' . $x['schoolYear']) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm btn-primary">Save</button>
    <div class="form-text">To move a student to another class during the school year, use the grading system's Students page: it also moves their classes and grades.</div>
  </form>
  <?php else: ?>
  <div class="form-section small"><dl class="row mb-0"><dt class="col-5">Student ID</dt><dd class="col-7"><?= h($s['studentId'] ?? '') ?></dd><dt class="col-5">Email</dt><dd class="col-7"><?= h($s['email'] ?? '') ?></dd><dt class="col-5">Section</dt><dd class="col-7"><?= h($s['sectionName'] ?? '') ?></dd></dl></div>
  <?php endif; ?>
  <?php $det = StudentDetails::get($id); $detMissing = array_unique(array_merge(StudentDetails::missing('tor', $det, $s), StudentDetails::missing('good-moral', $det, $s))); ?>
  <?php if ($canEdit): ?>
  <form method="post" class="form-section" id="studentDetails"><?= csrf_field() ?><input type="hidden" name="action" value="details">
    <h2 class="h6">Records for documents <span class="small text-muted fw-normal">(optional; needed for TOR and Good Moral)</span></h2>
    <?php if ($detMissing): ?><p class="small text-warning-emphasis mb-2"><i class="bi bi-info-circle"></i> Needed before issuing a TOR or Good Moral: <?= h(implode(', ', $detMissing)) ?></p><?php endif; ?>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small mb-0" for="dSex">Sex</label><select class="form-select form-select-sm" id="dSex" name="sex"><option value="">—</option><?php foreach (StudentDetails::SEXES as $x): ?><option<?= $det['sex'] === $x ? ' selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label small mb-0" for="dBirth">Birth date</label><input class="form-control form-control-sm" type="date" id="dBirth" name="birth_date" value="<?= h($det['birth_date']) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0" for="dPlace">Birth place</label><input class="form-control form-control-sm" id="dPlace" name="birth_place" maxlength="191" value="<?= h($det['birth_place']) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0" for="dMobile">Mobile number</label><input class="form-control form-control-sm" id="dMobile" name="mobile" maxlength="30" inputmode="tel" value="<?= h($det['mobile']) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0" for="dAddr">Home address</label><input class="form-control form-control-sm" id="dAddr" name="home_address" maxlength="255" value="<?= h($det['home_address']) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="dAdm">Date admitted</label><input class="form-control form-control-sm" type="date" id="dAdm" name="date_admitted" value="<?= h($det['date_admitted']) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="dCred">Entrance credentials</label><input class="form-control form-control-sm" id="dCred" name="entrance_credentials" maxlength="255" placeholder="e.g. Form 138" value="<?= h($det['entrance_credentials']) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="dGrad">Graduation date</label><input class="form-control form-control-sm" type="date" id="dGrad" name="graduation_date" value="<?= h($det['graduation_date']) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="dSo">SO number</label><input class="form-control form-control-sm" id="dSo" name="so_number" maxlength="100" value="<?= h($det['so_number']) ?>"></div>
    </div>
    <button class="btn btn-sm btn-primary mt-2">Save</button>
  </form>
  <?php else: ?>
  <div class="form-section small" id="studentDetails"><h2 class="h6">Records for documents</h2><dl class="row mb-0"><?php foreach (StudentDetails::FIELDS as $k => $label): ?><dt class="col-5"><?= h($label) ?></dt><dd class="col-7"><?= h($det[$k] ?: '—') ?></dd><?php endforeach; ?></dl></div>
  <?php endif; ?>
  <?php if ($adm): ?><div class="form-section small"><h2 class="h6">Admission</h2><?= h($adm['admission_no']) ?> · <?= h(Admission::TYPES[$adm['applicant_type']]) ?> · <?= badge($adm['status']) ?><br><?= h($adm['phone']) ?> <?= h($adm['address']) ?><?= $adm['guardian_name'] ? '<br>Guardian: ' . h($adm['guardian_name'] . ' ' . $adm['guardian_phone']) : '' ?>
    <?php $dp = EnrollmentDocs::progress($adm); if ($dp['required']): ?><br><strong>Documents:</strong> <?= $dp['missing'] ? h($dp['done'] . ' of ' . $dp['required'] . ' required submitted · missing: ' . implode(', ', $dp['missing'])) : 'all required documents submitted' ?><?php if (Auth::can('admission')): ?> · <a href="<?= url('admission', ['id' => $adm['id']]) ?>">Checklist</a><?php endif; ?><?php endif; ?></div><?php endif; ?>
</div>
<div class="col-lg-8"><div class="form-section d-flex justify-content-end flex-wrap gap-2"><?php if (Auth::can('documents')): ?><a class="btn btn-outline-primary" href="<?= url('documents', ['student' => $id]) ?>"><i class="bi bi-file-earmark-text"></i> TOR &amp; Certificates</a><?php endif; ?><?php if(StudentHistory::canViewAllGrades()): ?><a class="btn btn-outline-primary" href="<?= url('history',['id'=>$id,'tab'=>'grades']) ?>">History of Grades</a><?php endif; ?><a class="btn btn-outline-primary" href="<?= url('history',['id'=>$id,'tab'=>'assessments']) ?>">History of Assessments</a></div>
  <?php if(StudentHistory::canViewAllGrades()): ?><div class="form-section"><h2 class="h6">Final grades <span class="text-muted fw-normal small">(tagged complete by the registrar in the grading system)</span></h2>
    <?= grades_html(GS::completions($id)) ?></div><?php endif; ?>
  <?php $clrOv = Clearance::overrides(null, (string) ($s['studentId'] ?? '')); if ($clrOv): ?><div class="form-section"><h2 class="h6"><i class="bi bi-patch-exclamation me-1"></i>Enrolled without a completed clearance</h2><ul class="small mb-0"><?php foreach ($clrOv as $co): ?><li><?= h($co['school_year'] . ' ' . $co['term']) ?> confirmed by <?= h((string) $co['by_name']) ?> on <?= h(substr($co['created_at'], 0, 10)) ?> although the clearance of <?= h($co['cleared_sy'] . ' ' . $co['cleared_term']) ?> was not completed. Reason: <?= h($co['reason']) ?>. Pending then: <?= h($co['pending']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="form-section"><h2 class="h6">Registrations and assessments</h2>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>No.</th><th>Term</th><th>Section</th><th>Assessment</th><th class="money">Total</th><th class="money">Balance</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($regs as $r): ?><tr><td class="font-monospace"><?= h($r['reg_no']) ?></td><td><?= h($r['school_year'] . ' ' . $r['term']) ?></td><td><?= h($r['section_name']) ?></td><td class="small"><?= h($r['assessment_name']) ?></td>
      <td class="money"><?= Money::fmt(Money::cents($r['total_amount'])) ?></td><td class="money"><?= Money::fmt(Registration::balance($r)) ?></td><td><?= badge(Registration::state($r)) ?> <?= Registration::state($r) !== 'VOIDED' ? badge(Registration::payStatus($r)) : '' ?></td>
      <td><a class="btn btn-sm btn-outline-<?= Registration::state($r) === 'OPEN' && Auth::can('registration') ? 'primary" href="' . url('registration', ['id' => $r['id']]) . '">Open' : 'secondary" href="' . url('registration', ['id' => $r['id']]) . '">View' ?></a></td></tr><?php endforeach; ?>
    <?php if (!$regs): ?><tr><td colspan="8" class="text-muted">Not registered yet.</td></tr><?php endif; ?></tbody></table></div></div>
  <div class="form-section"><h2 class="h6">Payments</h2>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Receipt</th><th>Registration</th><th>Method</th><th class="money">Amount</th></tr></thead><tbody>
    <?php foreach ($pays as $p): ?><tr class="<?= $p['status'] === 'VOIDED' ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= h(substr($p['paid_at'], 0, 10)) ?></td><td><a href="<?= url('receipt', ['id' => $p['id']]) ?>"><?= h($p['receipt_no']) ?></a></td><td><?= h($p['reg_no']) ?></td><td><?= h($p['method']) ?></td><td class="money"><?= Money::fmt(Money::cents($p['amount'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$pays): ?><tr><td colspan="5" class="text-muted">No payments.</td></tr><?php endif; ?></tbody></table></div></div>
</div></div>
<?php layout_end();
