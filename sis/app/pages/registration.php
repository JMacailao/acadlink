<?php
/** Transaction → Registration: section, subjects and the assessment (what the student pays) for a term. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
$canEdit = Auth::can('registration');
// ?embed=1: shown inside a pop-up (New registration, View assessment): no menu, links keep the pop-up
$embed = isset($_GET['embed']);
$E = $embed ? ['embed' => 1] : [];
$embedField = $embed ? '<input type="hidden" name="embed" value="1">' : '';
// v56.2: live preview of a payment plan on the assessment (nothing is saved)
if (!$isPost && $id && isset($_GET['plan_preview'])) {
    Auth::require('registration');
    $r = Registration::get($id);
    $pid = ctype_digit((string) $_GET['plan_preview']) ? (int) $_GET['plan_preview'] : 0;
    $planPreview = $pid ? PaymentPlans::preview($r, $pid) : [];
    $cls = Settings::get('asm_template') === '2' ? 'enr' : 'soa';
    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/../views/soa_plan_box.php';
    exit;
}
if ($isPost) {
    Auth::require('registration');
    $a = $_POST['action'] ?? '';
    if($a==='payment_plan'){PaymentPlans::assign((int)$id,(int)($_POST['plan_id']??0));flash('success','Payment plan saved. Review and republish the assessment if applicable.');redirect('registration',['id'=>$id]);}
    if (!$id && ($postedId = In::int($_POST, 'reg_id'))) $id = $postedId; // from the list's pop-ups
    if ($a === 'publish_portal_assessment') { PortalEnrollment::publish((int)$id,$_POST); flash('success','Assessment published to the Student Portal.'); redirect('registration',['id'=>$id]); }
    if ($a === 'create') {
        $adm = In::int($_POST, 'admission_id');
        $curId = trim((string) ($_POST['curriculum_id'] ?? ''));
        $cur = $curId !== '' ? GS::get('curriculum', $curId) : null;
        if ($curId !== '' && !$cur) throw new AppError('Curriculum not found.');
        [$newId] = Registration::create($adm, $adm ? null : In::str($_POST, 'student_number', 40, true, 'Student ID'),
            (string) In::str($_POST, 'section_id', 191, true, 'Section'), (string) In::str($_POST, 'term', 20, true, 'Term'), In::int($_POST, 'assessment_name_id'),
            $cur ? array_values((array) ($cur['subjectIds'] ?? [])) : null, array_map('intval', array_filter((array) ($_POST['extra_labels'] ?? []), fn($v) => is_string($v) && ctype_digit($v))),
            In::str($_POST, 'override_reason', 255));
        if ($did = In::int($_POST, 'discount_id')) Discounts::give($newId, $did, In::str($_POST, 'discount_remarks', 255));
        redirect('registration', ['id' => $newId]);
    }
    if ($a === 'update' || $a === 'subjects' || $a === 'aname') {
        $r = Registration::get((int) $id);
        $nameId = $a === 'subjects' ? ($r['assessment_name_id'] ? (int) $r['assessment_name_id'] : null) : In::int($_POST, 'assessment_name_id');
        $subjectIds = $a === 'aname' ? array_column(Db::all('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [(int) $id]), 'subject_doc_id')
            : array_values(array_filter((array) ($_POST['subjects'] ?? []), 'is_string'));
        Registration::update((int) $id, $nameId, $subjectIds, $a === 'aname', In::str($_POST, 'prereq_override', 255));
        flash('success', $a === 'aname' ? 'Assessment name changed. The fees were recomputed.' : 'Subjects saved. The assessment was recomputed.');
    } elseif ($a === 'curriculum') {
        $r = Registration::get((int) $id);
        $curId = trim((string) ($_POST['curriculum_id'] ?? ''));
        $cur = $curId !== '' ? GS::get('curriculum', $curId) : ($r['program'] ? GS::curriculumFor($r['program'], $r['year_level'], $r['term']) : null);
        if (!$cur) throw new AppError($curId !== '' ? 'Curriculum not found.' : "No curriculum for {$r['program']} {$r['year_level']} {$r['term']}: choose one from the list.");
        Registration::update((int) $id, $r['assessment_name_id'] ? (int) $r['assessment_name_id'] : null, array_values((array) ($cur['subjectIds'] ?? [])));
        flash('success', 'Subjects loaded from the curriculum ' . GS::curriculumLabel($cur) . '.');
    } elseif ($a === 'adjust') {
        // The amount is always entered as a positive number; the type decides whether it adds or takes off.
        $kind = (string) In::enum($_POST, 'kind', ['charge', 'less'], true, 'Type');
        $cents = (int) In::money($_POST, 'amount', true, 'Amount', false);
        $label = (string) In::str($_POST, 'label', 100, true, 'Description');
        $added = FormOnce::run($_POST, fn() => Registration::addAdjustment((int) $id, $label, $kind === 'less' ? -$cents : $cents, $kind === 'less' ? 'Less / Discount' : 'Other Charges'));
        flash($added ? 'success' : 'info', $added ? 'Adjustment added.' : 'This adjustment was already added. It was not added again.');
    } elseif ($a === 'charge_labels') {
        $selected = $_POST['label_ids'] ?? [];
        $amounts = $_POST['amounts'] ?? [];
        if (!is_array($selected) || !is_array($amounts)) throw new AppError('Choose fee items to charge.');
        $charges = [];
        foreach ($selected as $rawId) {
            if (!is_scalar($rawId) || !ctype_digit((string) $rawId) || (int) $rawId < 1) throw new AppError('One of the selected fee items is invalid.');
            $labelId = (int) $rawId;
            $rawAmount = $amounts[$labelId] ?? '';
            if (!is_scalar($rawAmount)) throw new AppError('Enter a valid fee amount.');
            $amountText = trim((string) $rawAmount);
            $charges[$labelId] = $amountText === '' ? null : In::money(['amount' => $amountText], 'amount', true, 'Fee amount', true);
        }
        Registration::chargeLabels((int) $id, $charges);
        flash('success', count($charges) . ' fee item(s) added to this student\'s assessment.');
    } elseif ($a === 'charge_label') {
        $amt = trim((string) ($_POST['amount'] ?? ''));
        Registration::chargeLabel((int) $id, (int) ($_POST['label_id'] ?? 0), $amt === '' ? null : (int) In::money($_POST, 'amount', true, 'Amount', true));
        flash('success', 'Fee added to this student\'s assessment.');
    } elseif ($a === 'remove_label') {
        Registration::removeLabel((int) $id, (int) ($_POST['label_id'] ?? 0));
        flash('success', 'Fee taken off this student\'s assessment.');
    } elseif ($a === 'reset_label') {
        Registration::resetLabel((int) $id, (int) ($_POST['label_id'] ?? 0));
        flash('success', 'Fee back to the assessment name\'s amount.');
    } elseif ($a === 'remove_adj') {
        Registration::removeAdjustment((int) $id, (int) $_POST['item_id']);
        flash('success', 'Adjustment removed.');
    } elseif ($a === 'give_discount') {
        Discounts::give((int) $id, (int) In::int($_POST, 'discount_id', true, 'Scholarship / discount'), In::str($_POST, 'remarks', 255));
        flash('success', 'Scholarship / discount given. The assessment was recomputed.');
    } elseif ($a === 'remove_discount') {
        Discounts::remove((int) $id, (int) ($_POST['discount_id'] ?? 0));
        flash('success', 'Scholarship / discount removed. The assessment was recomputed.');
    } elseif ($a === 'finish') {
        Registration::finish((int) $id, !empty($_POST['ack']), In::str($_POST, 'eligibility_override', 255), In::str($_POST, 'clearance_reason', 255), (string) ($_POST['clearance_password'] ?? ''));
        flash('success', 'Enrollment confirmed. The assessment was checked and saved; it can now only be viewed. The cashier can receive payments any time until the balance is settled.');
    } elseif ($a === 'adddrop' || $a === 'withdraw') {
        $res = Registration::addDrop((int) $id, (array) ($_POST['add'] ?? []), (array) ($_POST['drop'] ?? []), (string) ($_POST['reason'] ?? ''), In::str($_POST, 'prereq_override', 255), $a === 'withdraw');
        flash('success', ($a === 'withdraw' ? 'Withdrawn from all subjects.' : 'Subjects changed.') . ' Assessment: ₱' . Money::fmt($res['before']) . ' → ₱' . Money::fmt($res['after'])
            . ($res['charged'] ? ' (₱' . Money::fmt($res['charged']) . ' of the dropped subjects stays charged: ' . $res['percent'] . '%)' : '') . '. Dropped subjects show DRP in the Grading System.');
    } elseif ($a === 'recompute') {
        Registration::recompute((int) $id);
        flash('success', 'Fees updated from the current fee setup.');
    } elseif ($a === 'void' || $a === 'cancel') {
        Registration::void((int) $id, (string) ($_POST['reason'] ?? ''));
        flash('success', 'Registration voided. It is kept for the record and can only be viewed.');
    } elseif ($a === 'reopen') {
        Registration::reopen((int) $id, (string) ($_POST['reason'] ?? ''));
        flash('success', 'Registration reopened for changes.');
    } elseif ($a === 'assign_teacher') {
        $r = Registration::get((int) $id);
        $subjectId = (string) ($_POST['subject_id'] ?? '');
        // the class takes this student and the other students of the section registered for the subject this term
        $students = Db::col("SELECT DISTINCT r.student_doc_id FROM sis_registrations r JOIN sis_registration_subjects s ON s.registration_id = r.id
                              WHERE r.section_doc_id = ? AND r.school_year = ? AND r.term = ? AND r.status <> 'CANCELLED' AND s.subject_doc_id = ?", [$r['section_doc_id'], $r['school_year'], $r['term'], $subjectId]);
        GS::assignTeacher((string) $r['section_doc_id'], (string) $r['term'], $subjectId, (string) ($_POST['teacher_id'] ?? ''), $students);
        flash('success', 'Teacher assigned. The class is in the grading system\'s Grading assignments with ' . count($students) . ' registered student(s).');
    }
    $back = (string) ($_POST['back'] ?? '');
    if (preg_match('/^index\.php\?r=registration(&[\w%=.\-+]*)*$/', $back)) { header('Location: ' . $back); exit; } // back to the list
    redirect('registration', ['id' => $id]);
}

// Read-only preview for the selected student, section, term and curriculum.
if (isset($_GET['eligibility_preview'])) {
    Auth::require('registration');
    $section = GS::get('sections', (string) In::str($_GET, 'section', 191, true, 'Section'));
    if (!$section) throw new AppError('Choose a section to check eligibility.');
    $term = (string) In::enum($_GET, 'term', TERMS, true, 'Term');
    $admission = In::int($_GET, 'admission') ? Db::one("SELECT * FROM sis_admissions WHERE id = ? AND status = 'ADMITTED'", [(int) $_GET['admission']]) : null;
    if (In::int($_GET, 'admission') && !$admission) throw new AppError('Choose an admitted applicant.');
    $number = $admission['student_number'] ?? (string) In::str($_GET, 'student', 40, true, 'Student ID');
    $student = GS::studentByNumber($number);
    $curId = trim((string) ($_GET['curriculum'] ?? ''));
    $curriculum = $curId !== '' ? GS::get('curriculum', $curId) : GS::curriculumFor(GS::programOf($section['sectionName']), $section['yearLevel'], $term);
    if ($curId !== '' && !$curriculum) throw new AppError('Curriculum not found.');
    $eligibility = EnrollmentEligibility::check($student['id'] ?? '', $number, $section['schoolYear'], $term, array_values((array) ($curriculum['subjectIds'] ?? [])), null, $admission);
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/../views/eligibility_panel.php';
    exit;
}

// ---------------------------------------------------------------- new registration
if (isset($_GET['new'])) {
    Auth::require('registration');
    $adm = isset($_GET['admission']) ? Db::one("SELECT * FROM sis_admissions WHERE id = ? AND status = 'ADMITTED'", [(int) $_GET['admission']]) : null;
    $find = !$adm && is_string($_GET['student'] ?? null) ? trim($_GET['student']) : '';
    // An exact Student ID picks the student; otherwise search names and IDs and list the matches
    $student = $find !== '' ? GS::studentByNumber($find) : null;
    $matches = $matchApplicants = [];
    if ($find !== '' && !$student) {
        $matches = GS::findStudents($find, 30);
        if (count($matches) === 1) { $student = $matches[0]; $matches = []; }
        $like = '%' . mb_strtolower($find) . '%';
        $matchApplicants = Db::all("SELECT a.* FROM sis_admissions a WHERE a.status = 'ADMITTED'
            AND NOT EXISTS (SELECT 1 FROM sis_registrations r WHERE r.student_number = a.student_number AND r.status <> 'CANCELLED')
            AND (LOWER(CONCAT(a.last_name, ', ', a.first_name)) LIKE ? OR LOWER(CONCAT(a.first_name, ' ', a.last_name)) LIKE ? OR a.student_number LIKE ? OR a.admission_no LIKE ?)
            ORDER BY a.last_name LIMIT 10", [$like, $like, "%$find%", "%$find%"]);
        // An admitted applicant who is not yet in the grading system is registered through the applicant
        $known = array_column($matches, 'studentId');
        $matchApplicants = array_values(array_filter($matchApplicants, fn($a) => !in_array($a['student_number'], $known, true) && !($student && $student['studentId'] === $a['student_number'])));
    }
    // An existing student may have just been promoted by the grading system.
    // Use that shared record's school year by default so Registration opens on
    // the promoted year instead of the registrar's previously selected year.
    // An explicit ?sy=... remains authoritative for staff who intentionally
    // need to register another school year.
    $requestedSy = is_string($_GET['sy'] ?? null) && $_GET['sy'] ? (string) $_GET['sy'] : null;
    $sy = $adm['school_year'] ?? ($requestedSy ?: ($student['schoolYear'] ?? selected_sy()));
    $previewTerm = in_array($_GET['term'] ?? '', TERMS, true) ? $_GET['term'] : '1st Semester';
    $sections = GS::sections($sy);
    $want = $adm ? [$adm['program'], $adm['year_level']] : ($student ? [GS::programOf((string) $student['sectionName']), $student['yearLevel']] : [null, null]);
    usort($sections, fn($a, $b) => [GS::programOf($b['sectionName']) === $want[0], $b['yearLevel'] === $want[1]] <=> [GS::programOf($a['sectionName']) === $want[0], $a['yearLevel'] === $want[1]] ?: strcmp($a['sectionName'], $b['sectionName']));
    $admitted = Db::all("SELECT a.* FROM sis_admissions a WHERE a.status = 'ADMITTED' AND NOT EXISTS (SELECT 1 FROM sis_registrations r WHERE r.student_number = a.student_number AND r.school_year = a.school_year AND r.status <> 'CANCELLED') ORDER BY a.last_name");
    if ($embed) {
        layout_start('New registration', true);
        echo '<div class="embed-wrap">';
    } else {
        layout_start('New registration');
        echo NotificationCenter::banner('registration');
        page_title('New registration', '<a class="btn btn-outline-secondary" href="' . url('registration') . '">Back</a>', 'Choose the student, then the section and term. Subjects come from the curriculum and the fees from the matching assessment name.');
        echo step_bar(2);
    }
    ?>
    <div class="row g-3"><div class="col-12">
    <form method="get" class="form-section"><input type="hidden" name="r" value="registration"><input type="hidden" name="apply" value="1"><input type="hidden" name="new" value="1"><?= $embedField ?>
      <h2 class="h6">1. Student</h2>
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label small">Admitted applicant (new students)</label><select class="form-select form-select-sm" name="admission" data-autosubmit><option value="">—</option><?php foreach ($admitted as $x): ?><option value="<?= $x['id'] ?>"<?= ($adm['id'] ?? 0) == $x['id'] ? ' selected' : '' ?>><?= h($x['last_name'] . ', ' . $x['first_name'] . ' · ' . $x['student_number']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label small" for="findStudent">or find a student: name or Student ID</label><div class="input-group input-group-sm"><input class="form-control" id="findStudent" name="student" value="<?= h($find) ?>" placeholder="e.g. Cruz, ben cruz, 20250002"<?= !$adm && !$student ? ' autofocus' : '' ?>><button class="btn btn-outline-primary"><i class="bi bi-search"></i> Find</button></div></div>
      </div>
      <?php if ($adm): ?><div class="alert alert-success py-2 mt-2 mb-0 small"><?= h(Admission::gradingName($adm)) ?> · <?= h($adm['student_number']) ?> · <?= h(trim($adm['program'] . ' ' . $adm['year_level'] . ' · ' . $adm['school_year'])) ?></div>
      <?php $st = Registration::studentType(null, (string) $adm['student_number'], $sy, 'Summer'); ?><div class="small mt-2"><i class="bi bi-person-badge"></i> Student type: <strong><?= h($st['label']) ?></strong> <span class="text-muted">· <?= h($st['detail']) ?></span></div>
      <?php elseif ($student): ?><div class="alert alert-success py-2 mt-2 mb-0 small"><?= h($student['studentName']) ?> · <?= h($student['studentId']) ?> · now in <?= h($student['sectionName'] . ' (' . $student['schoolYear'] . ')') ?><?php if (($student['promotionState'] ?? '') === 'PROMOTED_PENDING_REGISTRATION' && ($student['schoolYear'] ?? '') === $sy): ?> <span class="badge text-bg-primary ms-1">promoted · student selected</span><?php elseif (($student['schoolYear'] ?? '') === $sy): ?> <span class="badge text-bg-secondary ms-1">student selected</span><?php endif; ?></div>
      <?php $previewTerm = is_string($_GET['term'] ?? null) && in_array($_GET['term'], TERMS, true) ? (string) $_GET['term'] : '1st Semester';
            $st = Registration::studentType($student['id'], (string) $student['studentId'], $sy, $previewTerm); ?><div class="small mt-2"><i class="bi bi-person-badge"></i> Student type: <strong><?= h($st['label']) ?></strong> <span class="text-muted">· <?= h($st['detail']) ?></span></div>
      <?php elseif ($matches || $matchApplicants): ?>
      <div class="mt-2 small text-muted"><?= count($matches) + count($matchApplicants) ?> found for “<?= h($find) ?>”. Choose the student:</div>
      <div class="list-group mt-1" style="max-height:340px;overflow:auto">
        <?php foreach ($matches as $m): ?>
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="<?= url('registration', ['new' => 1, 'student' => $m['studentId'] ?? ''] + $E) ?>">
          <span class="me-auto"><strong><?= h($m['studentName'] ?? '') ?></strong><span class="d-block small text-muted"><?= h(($m['studentId'] ?? '') . ' · ' . ($m['sectionName'] ?? '') . ' · ' . ($m['yearLevel'] ?? '') . ' · ' . ($m['schoolYear'] ?? '')) ?></span></span>
          <span class="btn btn-sm btn-outline-primary">Choose</span></a>
        <?php endforeach; foreach ($matchApplicants as $m): ?>
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="<?= url('registration', ['new' => 1, 'admission' => $m['id']] + $E) ?>">
          <span class="me-auto"><strong><?= h($m['last_name'] . ', ' . $m['first_name']) ?></strong> <span class="badge text-bg-success">new · admitted</span><span class="d-block small text-muted"><?= h($m['student_number'] . ' · ' . trim($m['program'] . ' ' . $m['year_level']) . ' · ' . $m['school_year']) ?></span></span>
          <span class="btn btn-sm btn-outline-primary">Choose</span></a>
        <?php endforeach; ?>
      </div>
      <?php elseif ($find !== ''): ?><div class="alert alert-warning py-2 mt-2 mb-0 small">No student found for “<?= h($find) ?>”. Check the spelling or try the Student ID. New students go through <a href="<?= url('admission') ?>" target="_top">Admission</a> first.</div><?php endif; ?>
    </form>
    <?php if ($adm || $student): ?>
    <form method="post" class="form-section" id="newRegistrationForm" action="<?= url('registration', ['new' => 1]) ?>" target="_top"><?= csrf_field() ?><input type="hidden" name="action" value="create">
      <input type="hidden" name="admission_id" value="<?= h($adm['id'] ?? '') ?>"><input type="hidden" name="student_number" value="<?= h($student['studentId'] ?? '') ?>">
      <h2 class="h6">2. Section, term and assessment</h2>
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label small">Section (<?= h($sy) ?>)</label><select class="form-select form-select-sm" name="section_id" required><?php foreach ($sections as $s): ?><option value="<?= h($s['id']) ?>"<?= $student && ($student['sectionId'] ?? '') === $s['id'] ? ' selected' : '' ?>><?= h($s['sectionName'] . ' · ' . $s['yearLevel']) ?></option><?php endforeach; ?></select>
          <?php if (!$sections): ?><div class="form-text text-danger">No sections for <?= h($sy) ?>. Add them in Section first.</div><?php endif; ?></div>
        <div class="col-md-6"><label class="form-label small">School year</label><select class="form-select form-select-sm" disabled><option><?= h($sy) ?></option></select>
          <?php if (!$adm): ?><div class="form-text"><a href="<?= url('registration', $E + ['new' => 1, 'student' => $student['studentId'], 'sy' => GS::validSchoolYear((string) $student['schoolYear']) ? (substr($student['schoolYear'], 5) . '-' . ((int) substr($student['schoolYear'], 5) + 1)) : $sy]) ?>">Next school year</a> · <a href="<?= url('registration', $E + ['new' => 1, 'student' => $student['studentId'], 'sy' => $student['schoolYear']]) ?>">Student's current year</a></div><?php endif; ?></div>
        <div class="col-md-6"><label class="form-label small">Term</label><select class="form-select form-select-sm" name="term"><?= list_options(TERMS, $previewTerm) ?></select></div>
        <div class="col-md-6"><label class="form-label small" for="newCurriculum">Curriculum (subjects)</label><select class="form-select form-select-sm" id="newCurriculum" name="curriculum_id"><option value="">Automatic (matches the section and term)</option>
          <?php foreach (GS::curricula() as $c): ?><option value="<?= h($c['id']) ?>"><?= h(GS::curriculumLabel($c)) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label small" for="newAname">Assessment name (fees)</label><select class="form-select form-select-sm" id="newAname" name="assessment_name_id"><option value="">Automatic (best match)</option><?= opt(Assessment::names(true), 'id', 'name') ?></select></div>
      </div>
      <?php $progs = Discounts::all(true); if ($progs): ?>
      <div class="row g-2 mt-1">
        <div class="col-md-6"><label class="form-label small" for="newDiscount">Scholarship / discount (optional)</label><select class="form-select form-select-sm" id="newDiscount" name="discount_id"><option value="">None</option>
          <?php foreach ($progs as $d): ?><option value="<?= (int) $d['id'] ?>"><?= h($d['name'] . ' — ' . Discounts::describe($d)) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label small" for="newDiscountRemarks">Remarks</label><input class="form-control form-control-sm" id="newDiscountRemarks" name="discount_remarks" maxlength="255" placeholder="e.g. Dean's lister, 2nd Semester"></div>
      </div>
      <?php endif; ?>
      <?php $allLabels = Assessment::labels(true); if ($allLabels): ?>
      <details class="mt-3 border rounded p-2"><summary class="small fw-semibold">Extra fees for this student (optional)</summary>
        <div class="small text-muted my-1">Tick fee labels to charge besides the assessment name's fees, at their default amount. You can change amounts after registering.</div>
        <div class="row g-1">
        <?php $cat = null; foreach ($allLabels as $l): if ($l['category_name'] !== $cat): $cat = $l['category_name']; ?><div class="col-12 small text-uppercase text-muted mt-2"><?= h($cat) ?></div><?php endif; ?>
          <div class="col-md-6"><label class="form-check small"><input class="form-check-input" type="checkbox" name="extra_labels[]" value="<?= (int) $l['id'] ?>">
            <?= h($l['name']) ?> <span class="text-muted">· <?= h(CHARGE_TYPES[$l['charge_type']] ?? $l['charge_type']) ?> ₱<?= Money::fmt(Money::cents($l['default_amount'])) ?></span></label></div>
        <?php endforeach; ?></div>
      </details>
      <?php endif; ?>
      <?php
      $previewSection = null;
      foreach ($sections as $section) if ($student && ($student['sectionId'] ?? '') === $section['id']) $previewSection = $section;
      $previewSection ??= $sections[0] ?? null;
      if ($previewSection):
          $previewCurriculum = GS::curriculumFor(GS::programOf($previewSection['sectionName']), $previewSection['yearLevel'], $previewTerm);
          $eligibility = EnrollmentEligibility::check($student['id'] ?? '', (string) ($adm['student_number'] ?? $student['studentId']), $sy, $previewTerm, array_values((array) ($previewCurriculum['subjectIds'] ?? [])), null, $adm);
      endif; ?>
      <div id="registrationEligibility" class="mt-3" aria-live="polite" data-preview-url="<?= url('registration', ['eligibility_preview' => 1]) ?>">
        <?php if ($previewSection): require __DIR__ . '/../views/eligibility_panel.php'; else: ?><p class="text-danger">Add a section before creating a registration.</p><?php endif; ?>
      </div>
      <?php if (Auth::can('users.manage')): ?>
      <details class="mt-3 border rounded p-2"><summary class="small fw-semibold">Administrator exception for untagged grades, readmission or enrollment dates</summary>
        <label class="form-label small mt-2" for="newOverride">Reason (required if using an exception)</label><input class="form-control" id="newOverride" name="override_reason" maxlength="255" placeholder="Record the specific reason and authorization">
        <p class="form-text">This cannot bypass a balance restriction, explicit hold, incomplete-grade blocking policy, duplicate registration or subject prerequisite. Exceptions at creation are re-evaluated at confirmation.</p>
      </details>
      <?php endif; ?>
      <button class="btn btn-primary mt-3" id="createRegistrationButton"<?= !$previewSection ? ' disabled' : '' ?>>Create registration draft</button>
      <p class="form-text">An open draft is not confirmed enrollment. Ineligible subjects must be removed or their prerequisites resolved before confirmation.</p>
    </form>
    <?php endif; ?>
    </div></div>
    <?php
    if ($embed) { echo '</div>'; layout_end(true); } else { layout_end(); }
    return;
}

// ---------------------------------------------------------------- one registration
if ($id) {
    $r = Registration::get($id);
    $subs = Db::all('SELECT * FROM sis_registration_subjects WHERE registration_id = ? ORDER BY subject_code, subject_name', [$id]);
    $teacherAssignments = Db::all("SELECT data FROM docs WHERE col = 'gradingAssignments' AND f_sectionId = ? AND f_schoolYear = ?", [$r['section_doc_id'], $r['school_year']]);
    $teachersBySubject = [];
    foreach ($teacherAssignments as $assignmentRow) {
        $assignment = json_decode((string) $assignmentRow['data'], true);
        if (!is_array($assignment) || (string) ($assignment['term'] ?? '') !== (string) $r['term']) continue;
        $subjectId = (string) ($assignment['subjectId'] ?? '');
        $teacherName = trim((string) ($assignment['teacherName'] ?? ''));
        if ($subjectId !== '' && $teacherName !== '') $teachersBySubject[$subjectId][$teacherName] = true;
    }
    foreach ($subs as &$subject) $subject['teacher_name'] = implode(', ', array_keys($teachersBySubject[(string) $subject['subject_doc_id']] ?? []));
    unset($subject);
    $curricula = GS::curricula();
    $usedCur = GS::curriculumMatching(array_column($subs, 'subject_doc_id'));
    $matchCur = $r['program'] ? GS::curriculumFor($r['program'], $r['year_level'], $r['term']) : null;
    $matchName = Assessment::match($r['program'] ?: null, $r['year_level'], $r['term'], $r['school_year']);
    $items = Db::all('SELECT * FROM sis_registration_items WHERE registration_id = ? ORDER BY sort_order, category_name, id', [$id]);
    $pays = Db::all('SELECT p.*, u.full_name FROM sis_payments p JOIN sis_users u ON u.id = p.received_by WHERE p.registration_id = ? ORDER BY p.paid_at', [$id]);
    $state = Registration::state($r);
    $open = $state === 'OPEN' && $canEdit;
    $feeChoices = Registration::chosenLabels($id);
    $labelIdByName = array_column(Assessment::labels(), 'id', 'name');
    $given = Discounts::given($id);
    $stype = Registration::studentType($r['student_doc_id'], $r['student_number'], $r['school_year'], $r['term']);

    // In a pop-up (the "View assessment" button of the list): the printable assessment only
    if ($embed) {
        layout_start($r['reg_no'], true);
        echo '<div class="embed-wrap"><div class="d-flex gap-2 mb-3 d-print-none"><button class="btn btn-primary btn-sm" data-print><i class="bi bi-printer me-1"></i>Print assessment</button>'
            . '<a class="btn btn-outline-secondary btn-sm" href="' . url('registration', ['id' => $id]) . '" target="_top"><i class="bi bi-box-arrow-up-right me-1"></i>' . ($open ? 'Open' : 'View') . ' the registration</a></div>';
        $editable = false;
        require __DIR__ . '/../views/assessment_sheet.php';
        echo '</div>';
        layout_end(true);
        return;
    }

    // Keep the current schedule below the assessment; editing is handled in the Payment plan tool modal.
    $canSetPaymentPlan = $r['status'] !== 'CANCELLED' && ($r['record_origin'] ?? 'ENROLLMENT') === 'ENROLLMENT'
        && Auth::can('registration') && !Db::val('SELECT id FROM sis_payments WHERE registration_id = ? LIMIT 1', [$id]);
    $currentPaymentPlan = PaymentPlans::saved((int) $id);
    $paymentPlanChoices = [];
    if ($canSetPaymentPlan) {
        foreach (Db::all('SELECT id, name, kind, rules FROM sis_payment_plans WHERE is_active = 1 ORDER BY name') as $choice) {
            $rules = json_decode((string) $choice['rules'], true);
            if (PaymentPlans::applicable($r, is_array($rules) ? $rules : [])) $paymentPlanChoices[] = $choice;
        }
    }
    $currentPaymentPlanAvailable = !$currentPaymentPlan || in_array((int) $currentPaymentPlan['plan_id'], array_map('intval', array_column($paymentPlanChoices, 'id')), true);
    ob_start();
    require __DIR__.'/../views/payment-plan-summary.php';
    $paymentPlanHtml = (string) ob_get_clean();
    $eligibility = null;
    $eligibilityHistorical = false;
    if ($open) $eligibility = EnrollmentEligibility::forRegistration($r);
    elseif ($state === 'DONE') {
        $savedDecision = Db::val("SELECT snapshot FROM sis_eligibility_decisions WHERE registration_id = ? AND stage = 'CONFIRMED' ORDER BY id DESC LIMIT 1", [$id]);
        if ($savedDecision) { $eligibility = json_decode((string) $savedDecision, true); $eligibilityHistorical = true; }
    }
    layout_start($r['reg_no']);
    require SIS_ROOT . '/app/views/portal-assessment.php';
    $bal = Registration::balance($r);
    $curText = $usedCur ? trim(($usedCur['program'] ?? '') . ' · ' . ($usedCur['yearLevel'] ?? '') . ' · ' . ($usedCur['term'] ?? ''), ' ·') : ($subs ? 'Subjects picked by hand' : 'No curriculum chosen yet');
    $tool = fn(string $target, string $icon, string $label, bool $featured = false) => '<li><button type="button" class="registration-tool-button' . ($featured ? ' is-payment-plan' : '') . '" data-bs-toggle="modal" data-bs-target="#' . $target . '"><i class="bi bi-' . $icon . '" aria-hidden="true"></i><span class="registration-tool-copy"><strong>' . $label . '</strong></span></button></li>';
    ?>
    <div class="or-screen" data-registration-detail>
      <div class="or-toolbar d-print-none" role="toolbar" aria-label="Registration">
        <a class="tb" href="<?= url('registration') ?>" data-registration-action="back"><i class="bi bi-arrow-left"></i>Back</a>
        <button type="button" class="tb" data-print data-registration-action="print"><i class="bi bi-printer"></i>Print Assessment</button>
        <?php if ($open && Auth::can('enrollment.confirm')): ?><button type="button" class="tb" data-bs-toggle="modal" data-bs-target="#mConfirm" data-registration-action="confirm"><i class="bi bi-check2-circle"></i>Confirm Enrollment</button><?php endif; ?>
        <?php if (Auth::can('payments.record') && $state !== 'VOIDED' && $bal > 0): ?><a class="tb" href="<?= url('cashier', ['reg' => $id]) ?>" data-frame="<?= url('cashier', ['reg' => $id, 'embed' => 1]) ?>" data-frame-title="Receive payment" data-frame-size="xl" data-registration-action="pay"><i class="bi bi-cash-coin"></i>Pay at Cashier</a><?php endif; ?>
        <?php if ($state === 'DONE' && Auth::can('enrollment.reopen')): ?><button type="button" class="tb" data-bs-toggle="modal" data-bs-target="#mReopen" data-registration-action="reopen"><i class="bi bi-unlock text-primary"></i>Reopen</button><?php endif; ?>
        <?php if ($canEdit && $state !== 'VOIDED'): ?><button type="button" class="tb" data-bs-toggle="modal" data-bs-target="#mVoid" data-registration-action="void"><i class="bi bi-slash-circle text-danger"></i>Void</button><?php endif; ?>
        <span class="tb-sep"></span>
        <span class="tb-title me-auto text-truncate"><span class="font-monospace"><?= h($r['reg_no']) ?></span> · <?= h($r['student_name']) ?> <?= badge($state) ?><?= $state !== 'VOIDED' ? ' ' . badge(Registration::payStatus($r)) : '' ?></span>
        <a class="tb" href="<?= url('registration', ['id' => $id]) ?>" data-registration-action="refresh"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
      </div>
      <?php if ($open): $rv = Registration::review($id); $bd = $rv['build']; $canEligibilityException = Auth::can('users.manage') && !$eligibility['can_confirm'] && !array_filter($eligibility['checks'], fn($c) => in_array($c['state'], ['BLOCKED','NEEDS_APPROVAL'], true) && !$c['overrideable']); $icon = ['error' => 'x-circle-fill text-danger', 'warn' => 'exclamation-triangle-fill text-warning', 'ok' => 'check-circle-fill text-success', 'info' => 'info-circle text-secondary']; ?>
      <div class="modal fade" id="mConfirm" tabindex="-1" aria-labelledby="mConfirmT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="mConfirmT"><i class="bi bi-clipboard-check me-1"></i>Assessment correct? Confirm the enrollment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <?php if (!$eligibility['can_confirm']): ?><div class="alert alert-danger"><strong>Enrollment eligibility must be resolved.</strong><ul class="mb-0"><?php foreach ($eligibility['checks'] as $c) if (in_array($c['state'], ['BLOCKED','NEEDS_APPROVAL'], true)): ?><li><?= h($c['message']) ?></li><?php endif; ?></ul></div><?php endif; ?>
          <ul class="review-list">
            <?php foreach ($rv['checks'] as $c): ?><li class="rv-<?= h($c['level']) ?>"><i class="bi bi-<?= $icon[$c['level']] ?>" aria-hidden="true"></i><span><?= h($c['text']) ?></span></li><?php endforeach; ?>
          </ul>
          <table class="table table-sm review-totals mb-0"><tbody>
            <tr><td>Fees (<?= count($bd['lines']) ?> line<?= count($bd['lines']) === 1 ? '' : 's' ?>)</td><td class="money">₱<?= Money::fmt($bd['fees_cents']) ?></td></tr>
            <?php if ($bd['adjust_cents']): ?><tr><td>Charges / less</td><td class="money"><?= $bd['adjust_cents'] < 0 ? '−' : '' ?>₱<?= Money::fmt(abs($bd['adjust_cents'])) ?></td></tr><?php endif; ?>
            <?php foreach ($bd['discounts'] as $d): ?><tr><td><?= h($d['label']) ?></td><td class="money">−₱<?= Money::fmt($d['cents']) ?></td></tr><?php endforeach; ?>
            <tr class="fw-bold"><td>Total assessment to confirm</td><td class="money">₱<?= Money::fmt($bd['total_cents']) ?></td></tr>
            <tr><td>Paid so far</td><td class="money">−₱<?= Money::fmt($rv['paid_cents']) ?></td></tr>
            <tr class="fw-bold"><td>Balance</td><td class="money">₱<?= Money::fmt(max(0, $rv['balance_cents'])) ?></td></tr>
          </tbody></table>
          <p class="small text-muted mt-2 mb-0">After confirming, subjects and fees can no longer be changed (only the administrator can reopen). Payments can still be received.</p>
          <?php $clrNeed = Clearance::requirement((string) $r['student_number'], (string) $r['school_year'], (string) $r['term']); $clrAsk = $clrNeed && !$clrNeed['complete'] && !Db::val('SELECT 1 FROM sis_clearance_overrides WHERE registration_id = ?', [$id]); ?>
          <?php if ($clrAsk): ?><div class="alert alert-warning mt-3 mb-0" id="clrConfirm"><strong><i class="bi bi-patch-exclamation me-1"></i>Clearance of <?= h($clrNeed['registration']['school_year'] . ' ' . $clrNeed['registration']['term']) ?> is not completed.</strong>
            <ul class="small mb-2"><?php foreach ($clrNeed['pending'] as $pnd): ?><li><?= h($pnd) ?></li><?php endforeach; ?></ul>
            <p class="small mb-2">You can still confirm: type the reason and your password. It is logged and shown on the student's record.</p>
            <label class="form-label small" for="clrReason">Reason *</label><textarea class="form-control form-control-sm mb-2" id="clrReason" name="clearance_reason" form="finishForm" rows="2" minlength="5" maxlength="255" required placeholder="e.g. Library clearance to follow; approved by the Dean"></textarea>
            <label class="form-label small" for="clrPw">Your password *</label><input class="form-control form-control-sm" type="password" id="clrPw" name="clearance_password" form="finishForm" required autocomplete="current-password">
          </div><?php elseif ($clrNeed && $clrNeed['complete']): ?><p class="small text-success mt-2 mb-0"><i class="bi bi-patch-check me-1"></i>Clearance of <?= h($clrNeed['registration']['school_year'] . ' ' . $clrNeed['registration']['term']) ?> is completed.</p><?php endif; ?>
        </div>
        <form method="post" id="finishForm" class="modal-footer flex-wrap gap-2" data-confirm="Confirm this enrollment? Subjects and fees will be locked."><?= csrf_field() ?><input type="hidden" name="action" value="finish">
          <?php if ($canEligibilityException): ?><div class="w-100"><label class="form-label small" for="confirmEligibilityOverride">Administrator exception reason *</label><textarea class="form-control" id="confirmEligibilityOverride" name="eligibility_override" maxlength="255" required rows="2"></textarea><p class="form-text">Authorizes only the listed legacy grade-completion, readmission or calendar restrictions. This decision is saved with the enrollment.</p></div><?php endif; ?>
          <?php if ($rv['errors']): ?><span class="text-danger small me-auto"><i class="bi bi-x-circle me-1"></i>Fix the item(s) marked in red first.</span>
          <?php elseif ($rv['warnings']): ?><label class="form-check small me-auto mb-0"><input class="form-check-input" type="checkbox" name="ack" value="1" required> I checked the warning<?= $rv['warnings'] > 1 ? 's' : '' ?> above</label>
          <?php else: ?><span class="small text-success me-auto"><i class="bi bi-check2-circle me-1"></i>Everything checks out.</span><?php endif; ?>
          <?php if (Money::cents($r['total_amount']) !== $bd['total_cents']): ?><button type="submit" form="recomputeForm" class="btn btn-outline-secondary">Update fees now</button><?php endif; ?>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">No, go back and fix</button>
          <button class="btn btn-primary"<?= $rv['errors'] || (!$eligibility['can_confirm'] && !$canEligibilityException) ? ' disabled' : '' ?>><i class="bi bi-lock me-1"></i>Yes, confirm enrollment</button>
        </form>
      </div></div></div>
      <form method="post" id="recomputeForm" class="d-none"><?= csrf_field() ?><input type="hidden" name="action" value="recompute"></form>
      <?php endif; ?>

      <?php foreach (Clearance::overrides((int) $id) as $co): ?><div class="or-note d-print-none"><i class="bi bi-patch-exclamation"></i><span><strong>Confirmed without a completed clearance (<?= h($co['cleared_sy'] . ' ' . $co['cleared_term']) ?>)</strong> by <?= h((string) $co['by_name']) ?>, <?= h(substr($co['created_at'], 0, 16)) ?>: <?= h($co['reason']) ?><br><span class="small">Pending then: <?= h($co['pending']) ?></span></span></div><?php endforeach; ?>
      <?php foreach (AddDrop::overrides((int) $id) as $po): ?><div class="or-note d-print-none"><i class="bi bi-shield-exclamation"></i><span><strong><?= h($po['subject_code']) ?> taken without its prerequisite <?= h($po['prerequisite_code']) ?></strong>: <?= h($po['reason']) ?> (<?= h(substr($po['created_at'], 0, 10)) ?>)</span></div><?php endforeach; ?>
      <?php if (!empty($r['override_reason'])): ?><div class="or-note d-print-none"><i class="bi bi-shield-exclamation"></i><span><strong>Registered by the administrator although a rule would block it:</strong> <?= h($r['override_reason']) ?></span></div><?php endif; ?>
      <?php if ($state === 'DONE'): $by = Db::val('SELECT full_name FROM sis_users WHERE id = ?', [(int) $r['locked_by']]); ?>
      <div class="or-note is-done d-print-none"><i class="bi bi-lock-fill"></i><span><strong>Enrollment confirmed.</strong> This registration can only be viewed<?= $r['locked_at'] ? ' (confirmed ' . h(substr($r['locked_at'], 0, 16)) . ($by ? ' by ' . h($by) : '') . ')' : '' ?>.<?= Registration::balance($r) > 0 ? ' Balance ₱' . Money::fmt(Registration::balance($r)) . ' is not settled yet: the cashier can still receive payments.' : ' Fully paid.' ?></span></div>
      <?php elseif ($state === 'VOIDED'): ?>
      <div class="or-note is-void d-print-none"><i class="bi bi-x-octagon"></i><span><strong>Voided.</strong> <?= h($r['remarks']) ?> This registration is kept for the record and can only be viewed.</span></div>
      <?php endif; ?>

      <div class="or-body">
        <div class="or-main">
          <div class="reg-paper"><?php $editable = $open; $planSummaryBelow = true; require __DIR__ . '/../views/assessment_sheet.php'; ?></div>
          <?php if (trim($paymentPlanHtml) !== ''): ?><div class="mt-2 d-print-none"><?= $paymentPlanHtml ?></div><?php endif; ?>
          <div class="or-panel mt-2 d-print-none"><div class="or-panel-title">Payments</div>
            <div class="or-grid-wrap auto"><table class="or-grid"><thead><tr><th>Official Receipt Number</th><th>Date</th><th>Method</th><th>Cashier Name</th><th class="n">Amount</th></tr></thead><tbody>
            <?php foreach ($pays as $p): ?><tr class="<?= $p['status'] === 'VOIDED' ? 'is-void' : '' ?>"><td class="mono"><a href="<?= url('receipt', ['id' => $p['id']]) ?>" data-frame="<?= url('receipt', ['id' => $p['id'], 'embed' => 1]) ?>" data-frame-title="Official receipt" data-frame-size="lg"><?= h($p['receipt_no']) ?></a></td><td><?= h(date('m/d/Y g:i A', strtotime($p['paid_at']))) ?></td><td><?= h($p['method']) ?></td><td><?= h($p['full_name']) ?></td><td class="n"><?= Money::fmt(Money::cents($p['amount'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$pays): ?><tr class="empty"><td colspan="5">No payments yet.</td></tr><?php endif; ?></tbody></table></div></div>
        </div>

        <aside class="or-side tools-first d-print-none">
          <?= sub_explorer() ?>
          <div class="or-panel">
            <div class="or-panel-title" data-panel-static>Sub-Components</div>
            <?php if ($open): ?>
            <ul class="or-links tools registration-tool-list">
              <?= $tool('mSchedule', 'calendar-week', 'Class schedule') ?>
              <?= $tool('mSubjects', 'journal-check', 'Subjects') ?>
              <?= $tool('mAname', 'card-list', 'Assessment name') ?>
              <?= $tool('mDiscount', 'award', 'Scholarship / discount') ?>
              <?= $tool('mFee', 'tag', 'Add a fee') ?>
              <?= $tool('mAdjust', 'plus-slash-minus', 'Charge or less (discount)') ?>
              <?= Auth::can('teachers.edit') ? $tool('mTeachers', 'person-workspace', 'Teachers') : '' ?>
              <?= $canSetPaymentPlan ? $tool('mPaymentPlan', 'calendar-check', 'Payment plan', true) : '' ?>
            </ul>
            <?php else: ?>
            <ul class="or-links tools registration-tool-list"><?= $tool('mSchedule', 'calendar-week', 'Class schedule') ?><?php if ($r['status'] !== 'CANCELLED' && ($r['record_origin'] ?? '') !== 'MIGRATION' && Auth::can('registration')): ?><?= $tool('mAddDrop', 'arrow-left-right', 'Add / drop subjects') ?><?php endif; ?><?= $canSetPaymentPlan ? $tool('mPaymentPlan', 'calendar-check', 'Payment plan', true) : '' ?></ul>
            <?php endif; ?>
          </div>
          <div class="or-panel">
            <div class="or-panel-title">Summaries</div>
            <div class="or-sum">
              <label>Student</label><div class="or-box"><?= h(mb_strtoupper($r['student_name'])) ?> · <?= h($r['student_number']) ?></div>
              <label>Student Type</label><div class="or-box" title="<?= h($stype['detail']) ?>"><?= h($stype['label']) ?></div>
              <label>School Year / Term</label><div class="or-box"><?= h($r['school_year'] . ' · ' . $r['term']) ?></div>
              <label>Section</label><div class="or-box"><?= h($r['section_name'] . ' · ' . $r['year_level']) ?></div>
              <label>Curriculum</label><div class="or-box"><?= h($curText) ?></div>
              <label>Subjects / Units</label><div class="or-box n"><?= count($subs) ?> · <?= h($r['total_units']) ?></div>
              <label>Total Assessment</label><div class="or-box n"><?= Money::fmt(Money::cents($r['total_amount'])) ?></div>
              <label>Total Paid</label><div class="or-box n"><?= Money::fmt(Money::cents($r['amount_paid'])) ?></div>
              <label>Balance</label><div class="or-box n grand"><?= Money::fmt($bal) ?></div>
            </div>
          </div>
        </aside>
      </div>
    </div>

    <?php if ($canSetPaymentPlan): ?>
    <div class="modal fade" id="mPaymentPlan" tabindex="-1" aria-labelledby="mPaymentPlanTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-md modal-fullscreen-sm-down"><div class="modal-content">
      <form method="post" action="<?= url('registration', ['id' => $id] + $E) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="payment_plan">
        <div class="modal-header"><h2 class="modal-title fs-5" id="mPaymentPlanTitle"><i class="bi bi-calendar-check me-1 text-success" aria-hidden="true"></i>Payment plan<?php if (Settings::on('cashier_require_plan')): ?> <span class="badge text-bg-danger">Required before payment</span><?php endif; ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <?php if (!$paymentPlanChoices): ?><p class="alert alert-warning py-2 small">No active payment plan applies to this registration. Ask an administrator to add one for this school year, term, program, and year level.</p><?php endif; ?>
          <?php if (!$currentPaymentPlan && Settings::on('cashier_require_plan')): ?><p class="small text-danger"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>The Cashier cannot receive a payment until a plan is selected.</p><?php endif; ?>
          <label class="form-label small" for="registrationPlan">Choose a saved plan</label>
          <select name="plan_id" id="registrationPlan" class="form-select" data-plan-preview="<?= h(url('registration', ['id' => $id, 'plan_preview' => ''])) ?>"<?= $currentPaymentPlan && !$currentPaymentPlanAvailable ? ' required' : '' ?>>
            <?php if ($currentPaymentPlan && !$currentPaymentPlanAvailable): ?><option value="" selected disabled>Current plan is unavailable — choose an active plan</option><?php endif; ?>
            <option value="0"<?= !$currentPaymentPlan ? ' selected' : '' ?>>No payment plan</option>
            <?php foreach ($paymentPlanChoices as $choice): ?><option value="<?= (int) $choice['id'] ?>"<?= (int) ($currentPaymentPlan['plan_id'] ?? 0) === (int) $choice['id'] ? ' selected' : '' ?>><?= h($choice['name']) ?><?= $choice['kind'] === 'FULL' ? ' · Full payment' : ' · Installment' ?></option><?php endforeach; ?>
          </select>
          <p class="form-text mb-0">Choose before the first payment. Saved terms are preserved; full-payment discounts remain conditional until earned. The statement preview updates when you choose a plan.</p>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save plan</button></div>
      </form>
    </div></div></div>
    <script src="assets/plan-preview.js?v=56" defer></script>
    <?php endif; ?>

    <?php // ---------------- pop-ups (forms) ---------------- ?>
    <?php if ($open): $all = GS::subjects(); $chosen = array_column($subs, 'subject_doc_id'); $progs = array_filter(Discounts::all(true), fn($d) => !in_array((int) $d['id'], array_map('intval', array_column($given, 'discount_id')), true)); ?>
    <div class="modal fade" id="mSubjects" tabindex="-1" aria-labelledby="mSubjectsT" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
      <div class="modal-header"><h2 class="modal-title fs-5" id="mSubjectsT">Subjects (curriculum)</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <form method="post" class="pick-step" data-confirm="Replace this student's subjects with the chosen curriculum?"><?= csrf_field() ?><input type="hidden" name="action" value="curriculum">
          <div class="fw-semibold small mb-1"><span class="step-no">1</span> Choose the curriculum to use</div>
          <div class="d-flex flex-wrap gap-2">
            <select class="form-select form-select-sm flex-grow-1 w-auto" id="mCurriculum" name="curriculum_id" required aria-label="Curriculum">
              <option value="">— choose a curriculum —</option>
              <?php foreach ($curricula as $c): $isMatch = $c['id'] === ($matchCur['id'] ?? null); ?><option value="<?= h($c['id']) ?>"<?= $c['id'] === ($usedCur['id'] ?? $matchCur['id'] ?? null) ? ' selected' : '' ?>><?= h(GS::curriculumLabel($c)) ?><?= $isMatch ? ' — matches this registration' : '' ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i>Use this curriculum</button>
          </div>
          <div class="form-text"><?= $curricula ? 'Its subjects replace the ones below and the fees are recomputed.' : 'No curriculum yet. Add one in Curriculum (the grading system\'s subjects per term), or tick the subjects below.' ?></div>
        </form>
        <form method="post" id="subjectsForm" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="subjects">
          <div class="fw-semibold small mb-1"><span class="step-no">2</span> Or adjust the subjects one by one</div>
          <input class="form-control form-control-sm mb-2" data-filter="#subjpick" placeholder="Filter subjects" aria-label="Filter subjects">
          <div class="border rounded p-2"><table class="table table-sm mb-0" id="subjpick"><tbody>
          <?php foreach ($all as $s): ?><tr><td style="width:2rem"><input class="form-check-input" type="checkbox" name="subjects[]" value="<?= h($s['id']) ?>"<?= in_array($s['id'], $chosen, true) ? ' checked' : '' ?> aria-label="<?= h($s['subjectCode'] ?? '') ?>"></td><td class="small font-monospace"><?= h($s['subjectCode'] ?? '') ?></td><td class="small"><?= h($s['subjectName'] ?? '') ?></td><td class="small text-end"><?= h($s['units'] ?? '') ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
          <label class="form-label small mt-2 mb-0" for="prereqOverride">Prerequisite override <span class="text-muted">(only if a subject's prerequisite is not passed)</span></label>
          <input class="form-control form-control-sm" id="prereqOverride" name="prereq_override" maxlength="255" placeholder="Reason, e.g. Prerequisite credited from the previous school">
        </form>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" form="subjectsForm" class="btn btn-sm btn-primary">Save ticked subjects</button></div>
    </div></div></div>

    <div class="modal fade" id="mAname" tabindex="-1" aria-labelledby="mAnameT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down"><div class="modal-content">
      <form method="post" class="d-contents"><?= csrf_field() ?><input type="hidden" name="action" value="aname">
      <div class="modal-header"><h2 class="modal-title fs-5" id="mAnameT">Assessment name</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <label class="form-label small" for="mAname2">Fee template for this student</label>
        <select class="form-select" id="mAname2" name="assessment_name_id"><option value="">— none (no fees) —</option>
          <?php foreach (Assessment::names(true) as $n): $isMatch = (int) $n['id'] === (int) ($matchName['id'] ?? 0); ?><option value="<?= (int) $n['id'] ?>"<?= (int) $n['id'] === (int) $r['assessment_name_id'] ? ' selected' : '' ?>><?= h($n['name']) ?><?= $isMatch ? ' — best match' : '' ?></option><?php endforeach; ?>
        </select>
        <div class="form-text">All the fees of the chosen name are charged, including any you took off earlier; fees you added or changed for this student stay as they are. Set up names in <a href="<?= url('assessment', ['tab' => 'names']) ?>">Assessment → Names</a>.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-sm btn-primary">Save and recompute</button></div>
      </form>
    </div></div></div>

    <div class="modal fade" id="mDiscount" tabindex="-1" aria-labelledby="mDiscountT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="give_discount">
      <div class="modal-header"><h2 class="modal-title fs-5" id="mDiscountT"><i class="bi bi-award me-1"></i>Give a scholarship or discount</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?php if ($progs): ?>
        <label class="form-label small" for="mDprog">Program</label><select class="form-select mb-3" id="mDprog" name="discount_id" required><option value="">— choose a program —</option><?php foreach ($progs as $d): ?><option value="<?= (int) $d['id'] ?>"><?= h($d['name'] . ' — ' . Discounts::describe($d)) ?></option><?php endforeach; ?></select>
        <label class="form-label small" for="mDrem">Remarks (optional)</label><input class="form-control" id="mDrem" name="remarks" maxlength="255" placeholder="e.g. Dean's lister, 2nd Semester">
        <div class="form-text">The amount is computed from this student’s fees and updates when subjects or fees change.</div>
        <?php else: ?><p class="mb-0 text-muted">No other active programs. Add them in <a href="<?= url('discounts') ?>">Scholarships &amp; Discounts</a>.</p><?php endif; ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><?php if ($progs): ?><button class="btn btn-primary">Give</button><?php endif; ?></div>
      </form>
    </div></div></div>

    <?php $feeLabels = Assessment::labels(true); ?>
    <div class="modal fade" id="mFee" tabindex="-1" aria-labelledby="mFeeT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down"><div class="modal-content">
      <form method="post" id="feeLabelForm" data-fee-picker><?= csrf_field() ?><input type="hidden" name="action" value="charge_labels">
      <div class="modal-header"><h2 class="modal-title fs-5" id="mFeeT"><i class="bi bi-tag me-1"></i>Add fee items</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="small text-muted mb-2">Check each fee item to charge this student. Checked items are listed below before you add them.</p>
        <?php if ($feeLabels): ?>
        <div class="fee-picker-table-wrap"><table class="table table-sm align-middle fee-picker-table mb-0">
          <thead><tr><th scope="col" class="fee-picker-check"><span class="visually-hidden">Charge</span></th><th scope="col">Fee item</th><th scope="col">Charge type</th><th scope="col">Rate / amount</th></tr></thead>
          <tbody>
          <?php foreach ($feeLabels as $feeLabel): $feeLabelId = (int) $feeLabel['id']; $feeChoice = $feeChoices[$feeLabelId] ?? null; $alreadyAdded = $feeChoice && $feeChoice['mode'] === 'ADD'; $wasExcluded = $feeChoice && $feeChoice['mode'] === 'EXCLUDE'; $customRate = $alreadyAdded && $feeChoice['unit_amount'] !== null ? Money::dec(Money::cents($feeChoice['unit_amount'])) : ''; $defaultRate = Money::dec(Money::cents($feeLabel['default_amount'])); ?>
          <tr data-fee-option data-fee-name="<?= h($feeLabel['name']) ?>" data-fee-category="<?= h($feeLabel['category_name']) ?>" data-charge-type="<?= h($feeLabel['charge_type']) ?>" data-charge-label="<?= h(['FIXED' => 'Fixed', 'PER_UNIT' => 'Per unit', 'PER_SUBJECT' => 'Per subject'][$feeLabel['charge_type']] ?? $feeLabel['charge_type']) ?>" data-default-amount="<?= h($defaultRate) ?>">
            <td class="fee-picker-check"><input class="form-check-input" type="checkbox" name="label_ids[]" value="<?= $feeLabelId ?>" data-fee-select aria-label="Charge <?= h($feeLabel['name']) ?>"></td>
            <td><strong><?= h($feeLabel['name']) ?></strong><?php if ($alreadyAdded): ?> <span class="badge text-bg-success">Already added</span><?php elseif ($wasExcluded): ?> <span class="badge text-bg-secondary">Currently excluded</span><?php endif; ?><small class="d-block text-muted"><?= h($feeLabel['category_name']) ?></small></td>
            <td class="small"><?= h(CHARGE_TYPES[$feeLabel['charge_type']] ?? $feeLabel['charge_type']) ?></td>
            <td><div class="small text-muted mb-1">Default ₱<?= Money::fmt(Money::cents($feeLabel['default_amount'])) ?></div><div class="input-group input-group-sm"><span class="input-group-text">₱</span><input class="form-control fee-picker-amount" type="number" min="0" step="0.01" inputmode="decimal" name="amounts[<?= $feeLabelId ?>]" value="<?= h($customRate) ?>" placeholder="Use default" data-fee-amount aria-label="Optional custom rate for <?= h($feeLabel['name']) ?>" disabled></div></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <section class="fee-picker-selected mt-3" data-fee-summary hidden aria-live="polite">
          <div class="d-flex align-items-start justify-content-between gap-2 mb-2"><h3 class="h6 mb-0 text-success">Checked items to charge (<span data-fee-count>0</span>)</h3><span class="small text-muted">Only checked items will be added.</span></div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th scope="col">Fee item</th><th scope="col">Charge type</th><th scope="col">Rate / amount</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead><tbody data-fee-summary-list></tbody></table></div>
        </section>
        <?php else: ?><p class="alert alert-light border mb-0">No active fee labels are available. Add labels in Assessment before charging an extra fee.</p><?php endif; ?>
        <p class="small text-muted mt-2 mb-0" data-fee-empty<?= $feeLabels ? '' : ' hidden' ?>>Select fee items above to review them here. Leave a custom rate blank to use the label's default; per-unit and per-subject fees use the student's registered units or subjects.</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" data-fee-submit disabled>Add selected fees</button></div>
      </form>
    </div></div></div>

    <?php if (Auth::can('teachers.edit')): $allTeachers = GS::teachers(); ?>
    <div class="modal fade" id="mTeachers" tabindex="-1" aria-labelledby="mTeachersT" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><h2 class="modal-title fs-5" id="mTeachersT">Teachers of <?= h($r['section_name'] . ' · ' . $r['term']) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="small text-muted">Assign teachers now, or leave them pending and assign them later in the grading system's <em>Grading assignments</em>. When an enrollment is confirmed, the student joins the classes that already have a teacher.</p>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Subject</th><th>Teacher</th></tr></thead><tbody>
        <?php foreach ($subs as $s): ?><tr><td><span class="font-monospace small"><?= h((string) $s['subject_code']) ?></span> <?= h($s['subject_name']) ?></td>
          <td><?php if ($s['teacher_name'] !== ''): ?><?= h($s['teacher_name']) ?>
            <?php else: ?><form method="post" class="d-flex gap-2" action="<?= url('registration', ['id' => $id] + $E) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="assign_teacher"><input type="hidden" name="subject_id" value="<?= h($s['subject_doc_id']) ?>">
              <select class="form-select form-select-sm" name="teacher_id" required aria-label="Teacher for <?= h($s['subject_name']) ?>"><option value="">Pending — choose a teacher</option><?= opt($allTeachers, 'id', fn($t) => ($t['teacherName'] ?? '') . ' · ' . ($t['teacherId'] ?? '')) ?></select>
              <button class="btn btn-sm btn-primary">Assign</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$subs): ?><tr><td colspan="2" class="text-muted">No subjects yet.</td></tr><?php endif; ?>
        </tbody></table></div>
      </div>
    </div></div></div>
    <?php endif; ?>
    <div class="modal fade" id="mAdjust" tabindex="-1" aria-labelledby="mAdjustT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <form method="post"><?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="adjust">
      <div class="modal-header"><h2 class="modal-title fs-5" id="mAdjustT">Add a charge or less (discount)</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="row g-2"><div class="col-5"><label class="form-label small" for="mAkind">Type</label><select class="form-select" id="mAkind" name="kind"><option value="charge">Charge</option><option value="less">Less / discount</option></select></div>
          <div class="col-7"><label class="form-label small" for="mAamt">Amount ₱</label><input class="form-control" id="mAamt" name="amount" required inputmode="decimal"></div>
          <div class="col-12"><label class="form-label small" for="mAlabel">Description</label><input class="form-control" id="mAlabel" name="label" required maxlength="100" placeholder="e.g. Late registration, Sibling discount"></div></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add</button></div>
      </form>
    </div></div></div>
    <?php endif; ?>

    <?php if ($canEdit && $state !== 'VOIDED'): ?>
    <div class="modal fade" id="mVoid" tabindex="-1" aria-labelledby="mVoidT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <form method="post" data-confirm="Void this registration? It is kept for the record but can no longer be changed or paid."><?= csrf_field() ?><input type="hidden" name="action" value="void">
      <div class="modal-header"><h2 class="modal-title fs-5 text-danger" id="mVoidT"><i class="bi bi-x-octagon me-1"></i>Void registration</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?php if (Money::cents($r['amount_paid']) > 0): ?><p class="mb-0">Payments were made: the cashier must void them first.</p>
        <?php else: ?><label class="form-label small" for="mVreason">Reason</label><input class="form-control" id="mVreason" name="reason" required maxlength="255" placeholder="e.g. wrong term, student withdrew">
        <div class="form-text">The record is kept as VOIDED. The student can then be registered again for this term.</div><?php endif; ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><?php if (Money::cents($r['amount_paid']) <= 0): ?><button class="btn btn-outline-danger">Void</button><?php endif; ?></div>
      </form>
    </div></div></div>
    <?php endif; ?>

    <?php if ($state === 'DONE' && Auth::can('enrollment.reopen')): ?>
    <div class="modal fade" id="mReopen" tabindex="-1" aria-labelledby="mReopenT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <form method="post" data-confirm="Reopen this registration for changes?"><?= csrf_field() ?><input type="hidden" name="action" value="reopen">
      <div class="modal-header"><h2 class="modal-title fs-5" id="mReopenT">Reopen registration</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><label class="form-label small" for="mRreason">Reason for reopening</label><input class="form-control" id="mRreason" name="reason" required maxlength="255"><div class="form-text">Saved in the activity log.</div></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Reopen</button></div>
      </form>
    </div></div></div>
    <?php endif; ?>
    <?php if (!$open && $r['status'] !== 'CANCELLED' && ($r['record_origin'] ?? '') !== 'MIGRATION' && Auth::can('registration')): $dc = AddDrop::charge($r); $hist = AddDrop::history((int) $id); $taken = array_column($subs, 'subject_doc_id'); ?>
    <div class="modal fade" id="mAddDrop" tabindex="-1" aria-labelledby="mAddDropT" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><h2 class="modal-title fs-5" id="mAddDropT">Add / drop subjects · <?= h($r['student_name']) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="alert alert-info py-2 small"><i class="bi bi-calendar-check"></i> <?= h($dc['text']) ?> The enrollment stays confirmed; the assessment is recomputed and booked again. Dropped subjects show DRP in the Grading System.</div>
        <form method="post" id="addDropForm"><?= csrf_field() ?><input type="hidden" name="action" value="adddrop">
          <div class="row g-3">
            <div class="col-md-6"><h3 class="h6">Drop</h3>
              <?php foreach ($subs as $sb): ?><label class="form-check d-block"><input class="form-check-input" type="checkbox" name="drop[]" value="<?= h($sb['subject_doc_id']) ?>"> <span class="form-check-label"><span class="font-monospace small"><?= h((string) $sb['subject_code']) ?></span> <?= h($sb['subject_name']) ?></span></label><?php endforeach; ?>
              <?php if (!$subs): ?><p class="small text-muted">No subjects.</p><?php endif; ?></div>
            <div class="col-md-6"><h3 class="h6">Add</h3>
              <input class="form-control form-control-sm mb-2" data-filter="#addpick" placeholder="Filter subjects" aria-label="Filter subjects to add">
              <div class="border rounded p-2" style="max-height:240px;overflow:auto"><table class="table table-sm mb-0" id="addpick"><tbody>
              <?php foreach (GS::subjects() as $sb): if (in_array($sb['id'], $taken, true)) continue; ?><tr><td style="width:2rem"><input class="form-check-input" type="checkbox" name="add[]" value="<?= h($sb['id']) ?>" aria-label="Add <?= h($sb['subjectCode'] ?? '') ?>"></td><td><span class="font-monospace small"><?= h($sb['subjectCode'] ?? '') ?></span> <?= h($sb['subjectName'] ?? '') ?> <span class="small text-muted"><?= (float) ($sb['units'] ?? 0) ?> u</span></td></tr><?php endforeach; ?>
              </tbody></table></div></div>
          </div>
          <label class="form-label small mt-3 mb-0" for="adReason">Reason *</label><input class="form-control" id="adReason" name="reason" required maxlength="255" placeholder="e.g. Student request: schedule conflict">
          <label class="form-label small mt-2 mb-0" for="adPrereq">Prerequisite override <span class="text-muted">(only if an added subject's prerequisite is not passed)</span></label><input class="form-control form-control-sm" id="adPrereq" name="prereq_override" maxlength="255">
        </form>
        <?php if ($hist): ?><h3 class="h6 mt-4">History</h3><div class="table-responsive"><table class="table table-sm small mb-0"><thead><tr><th>Date</th><th>Change</th><th class="money">Charged</th><th class="money">Assessment</th><th>Reason</th></tr></thead><tbody>
          <?php foreach ($hist as $hh): ?><tr><td class="text-nowrap"><?= h(substr($hh['created_at'], 0, 16)) ?><div class="text-muted"><?= h((string) $hh['full_name']) ?></div></td><td><?= $hh['kind'] === 'WITHDRAW' ? '<span class="badge text-bg-danger">Withdrawal</span> ' : '' ?><?= $hh['added'] !== '' ? 'Added ' . h($hh['added']) . '<br>' : '' ?><?= $hh['dropped'] !== '' ? 'Dropped ' . h($hh['dropped']) . ' (' . (int) $hh['charge_percent'] . '%)' : '' ?></td>
            <td class="money"><?= Money::fmt(Money::cents($hh['charged'])) ?></td><td class="money"><?= Money::fmt(Money::cents($hh['total_before'])) ?> → <?= Money::fmt(Money::cents($hh['total_after'])) ?></td><td><?= h($hh['reason']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div><?php endif; ?>
      </div>
      <div class="modal-footer flex-wrap gap-2">
        <form method="post" class="me-auto d-flex gap-2 align-items-center" data-confirm="Withdraw <?= h($r['student_name']) ?> from ALL subjects of this term?"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw">
          <input class="form-control form-control-sm" name="reason" required maxlength="255" placeholder="Reason for withdrawal" aria-label="Reason for withdrawal"><button class="btn btn-sm btn-outline-danger text-nowrap">Withdraw (all subjects)</button></form>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" form="addDropForm" class="btn btn-sm btn-primary">Save changes</button>
      </div>
    </div></div></div>
    <?php endif; ?>
    <?php if (isset($schedReg)): ?>
    <div class="modal fade" id="mSchedule" tabindex="-1" aria-labelledby="mScheduleT" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><h2 class="modal-title fs-5" id="mScheduleT">Class schedule · <?= h($r['section_name'] . ' · ' . $r['term'] . ' ' . $r['school_year']) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?php foreach ($schedReg['clashes'] as $c): ?><div class="alert alert-danger py-2 small"><?= h($c) ?></div><?php endforeach; ?>
        <?= Schedules::grid($schedReg['rows'], 'section') ?>
        <?php $unscheduled = array_filter($subs, fn($x) => !in_array($x['subject_doc_id'], array_column($schedReg['rows'], 'subject_doc_id'), true)); if ($unscheduled): ?>
        <p class="small text-muted mt-2 mb-0">No schedule yet: <?= h(implode(', ', array_column($unscheduled, 'subject_code'))) ?>.<?= Auth::can('schedules.edit') ? ' <a href="' . url('schedules', ['school_year' => $r['school_year'], 'term' => $r['term'], 'section' => $r['section_doc_id']]) . '">Set it in Class Schedules</a>.' : '' ?></p><?php endif; ?>
      </div>
    </div></div></div>
    <?php endif; ?>
    <?php
    layout_end();
    return;
}

// ---------------------------------------------------------------- list (official records screen)
$sy = selected_sy();
$term = in_array($_GET['term'] ?? '', TERMS, true) ? $_GET['term'] : '';
$fState = in_array($_GET['state'] ?? '', ['OPEN', 'DONE', 'VOIDED'], true) ? $_GET['state'] : '';
$fPay = in_array($_GET['pay'] ?? '', ['UNPAID', 'PARTIAL', 'PAID'], true) ? $_GET['pay'] : '';
$fSection = trim((string) ($_GET['section'] ?? ''));
$fLevel = in_array($_GET['level']??'',YEAR_LEVELS,true)?$_GET['level']:'';
$fPay = in_array($_GET['pay'] ?? '', ['UNPAID', 'PARTIAL', 'PAID'], true) ? $_GET['pay'] : '';
$showVoided = ($_GET['show_voided'] ?? '') === '1';
$q = trim((string) ($_GET['q'] ?? ''));
$where = ['r.school_year = ?']; $p = [$sy];
if (!$showVoided && $fState !== 'VOIDED') $where[] = "r.status <> 'CANCELLED'";
if ($term) { $where[] = 'r.term = ?'; $p[] = $term; }
if ($fLevel) { $where[] = 'r.year_level = ?'; $p[] = $fLevel; }
if ($fSection !== '') { $where[] = 'r.section_name = ?'; $p[] = $fSection; }
if ($fState === 'VOIDED') $where[] = "r.status = 'CANCELLED'";
if ($fState === 'OPEN') $where[] = "r.status <> 'CANCELLED' AND r.locked_at IS NULL";
if ($fState === 'DONE') $where[] = "r.status <> 'CANCELLED' AND r.locked_at IS NOT NULL";
if ($q !== '') { $where[] = '(r.reg_no LIKE ? OR r.student_number LIKE ? OR r.student_name LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
$rows = RecordList::query('SELECT r.*, u.full_name AS created_by_name FROM sis_registrations r LEFT JOIN sis_users u ON u.id = r.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT 2000', $p);
if ($fPay) $rows = array_values(array_filter($rows, fn($r) => Registration::payStatus($r) === $fPay));
$sum = ['n' => 0, 'total' => 0, 'paid' => 0, 'bal' => 0, 'OPEN' => 0, 'DONE' => 0, 'VOIDED' => 0];
foreach ($rows as $r) {
    $st = Registration::state($r); $sum[$st]++;
    if ($st === 'VOIDED') continue;
    $sum['n']++; $sum['total'] += Money::cents($r['total_amount']); $sum['paid'] += Money::cents($r['amount_paid']); $sum['bal'] += Registration::balance($r);
}
$sectionNames = array_values(array_unique(array_map(fn($x) => $x['sectionName'], GS::sections($sy))));
sort($sectionNames);
$keep = array_filter(['sy' => $sy, 'term' => $term, 'state' => $fState, 'pay' => $fPay, 'section' => $fSection, 'level' => $fLevel, 'show_voided' => $showVoided ? '1' : '', 'q' => $q], fn($v) => $v !== '');
if(RecordList::applied())$keep['apply']='1';
$showFilters = ($_GET['filters'] ?? '') !== 'hide';
$back = url('registration', $keep);
layout_start('Registration');
RecordList::panel();
echo NotificationCenter::banner('registration');
?>
<style>@media print { @page { size: landscape; margin: 10mm; } }</style>
<div class="or-screen">
  <div class="or-toolbar d-print-none" role="toolbar" aria-label="Registrations">
    <button type="button" class="tb" disabled data-row="frame" data-row-key="view" data-row-default data-frame-title="Assessment" data-frame-size="xl"><i class="bi bi-eye"></i>View</button>
    <?php if ($canEdit): ?><a class="tb" href="<?= url('registration', ['new' => 1]) ?>" data-frame="<?= url('registration', ['new' => 1, 'embed' => 1]) ?>" data-frame-title="New registration" data-frame-size="lg"><i class="bi bi-plus-circle text-success"></i>New</a><?php endif; ?>
    <button type="button" class="tb" disabled data-row="go" data-row-key="open"><i class="bi bi-pencil-square"></i>Open</button>
    <?php if ($canEdit): ?><button type="button" class="tb" disabled data-row="modal" data-row-if="voidable" data-bs-toggle="modal" data-bs-target="#mVoidReg"><i class="bi bi-slash-circle text-danger"></i>Void</button><?php endif; ?>
    <?php if (Auth::can('payments.record')): ?><button type="button" class="tb" disabled data-row="frame" data-row-key="pay" data-row-if="payable" data-frame-title="Receive payment" data-frame-size="xl"><i class="bi bi-cash-coin text-success"></i>Pay</button><?php endif; ?>
    <span class="tb-sep"></span>
    <a class="tb<?= $showFilters ? ' active' : '' ?>" href="<?= url('registration', $keep + ['filters' => $showFilters ? 'hide' : 'show']) ?>"><i class="bi bi-funnel"></i><?= $showFilters ? 'Hide' : 'Show' ?> Filters</a>
    <button type="submit" form="regFilters" class="tb"><i class="bi bi-check2-circle text-success"></i>Apply Filters</button>
    <button type="button" class="tb" data-print><i class="bi bi-printer"></i>Print List</button>
    <?= alt_search('registration', $keep, 'q', 'Student name, Student ID or Registration No.') ?>
    <a class="tb" href="<?= url('registration', $keep) ?>"><i class="bi bi-arrow-clockwise text-success"></i>Refresh</a>
  </div>
  <div class="or-body">
    <div class="or-main">
      <form id="regFilters" data-record-filters method="get" class="or-filters d-print-none<?= $showFilters ? '' : ' d-none' ?>"><input type="hidden" name="r" value="registration"><input type="hidden" name="apply" value="1">
        <div class="or-filters-title">Filter Preferences</div>
        <div class="fp-grid">
          <?= fp_row('School Year', '<select class="form-select form-select-sm" name="sy" aria-label="School year">' . sy_options($sy) . '</select>', true, true) ?>
          <?= fp_row('Term', '<select class="form-select form-select-sm" name="term" aria-label="Term">' . list_options(TERMS, $term ?: TERMS[0]) . '</select>', $term !== '') ?>
          <?= fp_row('Year Level', '<select class="form-select form-select-sm" name="level" aria-label="Year level">' . list_options(YEAR_LEVELS, $fLevel ?: YEAR_LEVELS[0]) . '</select>', $fLevel !== '') ?>
          <?= fp_row('Section', '<select class="form-select form-select-sm" name="section" aria-label="Section">' . list_options($sectionNames, $fSection) . '</select>', $fSection !== '') ?>
          <?= fp_row('Registration Status', '<select class="form-select form-select-sm" name="state" aria-label="Registration status">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($fState === $k ? ' selected' : '') . '>' . $l . '</option>', ['OPEN', 'DONE', 'VOIDED'], ['Open', 'Done', 'Voided'])) . '</select>', $fState !== '') ?>
          <?= fp_row('Show Voided', '<label class="form-check form-switch small"><input class="form-check-input" type="checkbox" name="show_voided" value="1"' . ($showVoided ? ' checked' : '') . '> Include voided registrations</label>', $showVoided) ?>
          <?= fp_row('Payment Status', '<select class="form-select form-select-sm" name="pay" aria-label="Payment status">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($fPay === $k ? ' selected' : '') . '>' . $l . '</option>', ['UNPAID', 'PARTIAL', 'PAID'], ['Unpaid', 'Partial', 'Paid'])) . '</select>', $fPay !== '') ?>
        </div>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>
        <div class="fp-actions"><?php if (count($keep) > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('registration', ['sy' => $sy]) ?>">Clear</a><?php endif; ?><button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Apply</button></div>
      </form>
      <div class="print-only"><?php $docTitle = 'Registrations · ' . $sy . ($term ? ' · ' . $term : ''); $docRight = ''; require __DIR__ . '/../views/print_header.php'; ?></div>
      <div class="or-grid-wrap">
        <table class="or-grid" id="regGrid" data-select>
          <thead><tr><th>Registration Number</th><th>Date</th><th>Student ID</th><th>Student Name</th><th>Section</th><th>Year Level</th><th>Term</th><th class="n">Units</th><th class="n">Assessment</th><th class="n">Paid</th><th class="n">Balance</th><th>Status</th><th>Payment</th><th>Registered By</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $st = Registration::state($r); $bal = Registration::balance($r); ?>
            <tr class="<?= $st === 'VOIDED' ? 'is-void' : '' ?>" tabindex="0" data-id="<?= (int) $r['id'] ?>"
                data-view="<?= h(url('registration', ['id' => $r['id'], 'embed' => 1])) ?>" data-open="<?= h(url('registration', ['id' => $r['id']])) ?>"
                data-pay="<?= h(url('cashier', ['reg' => $r['id'], 'embed' => 1])) ?>" data-payable="<?= $st !== 'VOIDED' && $bal > 0 ? '1' : '0' ?>"
                data-voidable="<?= $st !== 'VOIDED' && Money::cents($r['amount_paid']) <= 0 ? '1' : '0' ?>" data-label="<?= h($r['reg_no'] . ' · ' . $r['student_name'] . ' · ' . $r['school_year'] . ' ' . $r['term']) ?>">
              <td class="mono"><?= h($r['reg_no']) ?></td>
              <td><?= h(date('m/d/Y', strtotime($r['created_at']))) ?></td>
              <td><?= h($r['student_number']) ?></td>
              <td class="payer"><?= h(mb_strtoupper($r['student_name'])) ?></td>
              <td><?= h($r['section_name']) ?></td>
              <td><?= h($r['year_level']) ?></td>
              <td><?= h($r['term']) ?></td>
              <td class="n"><?= h($r['total_units']) ?></td>
              <td class="n"><?= Money::fmt(Money::cents($r['total_amount'])) ?></td>
              <td class="n"><?= Money::fmt(Money::cents($r['amount_paid'])) ?></td>
              <td class="n fw-semibold"><?= Money::fmt($bal) ?></td>
              <td><?= badge($st) ?></td>
              <td><?= $st !== 'VOIDED' ? badge(Registration::payStatus($r)) : '' ?></td>
              <td><?= h($r['created_by_name'] ?? '') ?></td>
            </tr>
          <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="14">No registrations for these filters.<?= $canEdit ? ' Click <strong>New</strong> to register a student.' : '' ?></td></tr><?php endif; ?>
          </tbody>
          <?php if ($rows): ?><tfoot><tr><td colspan="8"><?= $sum['n'] ?> registration(s)<?= $sum['VOIDED'] ? ' · voided ones not counted' : '' ?></td><td class="n"><?= Money::fmt($sum['total']) ?></td><td class="n"><?= Money::fmt($sum['paid']) ?></td><td class="n"><?= Money::fmt($sum['bal']) ?></td><td colspan="3"></td></tr></tfoot><?php endif; ?>
        </table>
      </div>
    </div>
    <aside class="or-side">
      <?= sub_explorer() ?>
      <div class="or-panel">
        <div class="or-panel-title">Sub-Components</div>
        <ul class="or-links">
          <?php if ($canEdit): ?><li><a href="<?= url('registration', ['new' => 1]) ?>" data-frame="<?= url('registration', ['new' => 1, 'embed' => 1]) ?>" data-frame-title="New registration" data-frame-size="lg"><i class="bi bi-journal-plus"></i>New Registration</a></li><?php endif; ?>
          <li><a href="#" class="disabled-link" aria-disabled="true" data-row="frame" data-row-key="view" data-frame-title="Print assessment" data-frame-size="xl"><i class="bi bi-printer"></i>Print Assessment</a></li>
          <li><a href="<?= url('reports', ['rep' => 'census', 'run' => 1, 'sy' => $sy, 'term' => $term]) ?>" data-frame="<?= url('reports', ['rep' => 'census', 'run' => 1, 'embed' => 1, 'sy' => $sy, 'term' => $term]) ?>" data-frame-title="Enrollment Census" data-frame-size="xl"><i class="bi bi-bar-chart-line"></i>Enrollment Census</a></li>
          <li><a href="<?= url('reports') ?>"><i class="bi bi-printer"></i>Report Manager</a></li>
          <?php if (Auth::can('admission')): ?><li><a href="<?= url('admission') ?>"><i class="bi bi-person-plus"></i>Admission</a></li><?php endif; ?>
          <?php if (Auth::can('payments.view')): ?><li><a href="<?= url('cashier') ?>"><i class="bi bi-cash-coin"></i>Cashier · Official Receipts</a></li><?php endif; ?>
          <?php if (Auth::can('assessment')): ?><li><a href="<?= url('assessment', ['tab' => 'names']) ?>"><i class="bi bi-card-list"></i>Assessment Names</a></li><li><a href="<?= url('discounts') ?>"><i class="bi bi-award"></i>Scholarships &amp; Discounts</a></li><?php endif; ?>
        </ul>
      </div>
      <div class="or-panel">
        <div class="or-panel-title">Summaries</div>
        <div class="or-sum">
          <label>School Year / Term</label><div class="or-box"><?= h($sy . ' · ' . ($term ?: 'All terms')) ?></div>
          <label>Registered Students</label><div class="or-box n"><?= number_format($sum['n']) ?></div>
          <label>Open · Done · Voided</label><div class="or-box n"><?= $sum['OPEN'] ?> · <?= $sum['DONE'] ?> · <?= $sum['VOIDED'] ?></div>
          <label>Total Assessment</label><div class="or-box n"><?= Money::fmt($sum['total']) ?></div>
          <label>Total Paid</label><div class="or-box n"><?= Money::fmt($sum['paid']) ?></div>
          <label>Balance to Collect</label><div class="or-box n grand"><?= Money::fmt($sum['bal']) ?></div>
        </div>
      </div>
    </aside>
  </div>
</div>
<?php if ($canEdit): ?>
<div class="modal fade" id="mVoidReg" tabindex="-1" aria-labelledby="mVoidRegT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post" action="<?= url('registration') ?>" data-confirm="Void this registration? It is kept for the record but can no longer be changed or paid."><?= csrf_field() ?>
  <input type="hidden" name="action" value="void"><input type="hidden" name="reg_id" value="" data-row-fill="id"><input type="hidden" name="back" value="<?= h($back) ?>">
  <div class="modal-header"><h2 class="modal-title fs-5 text-danger" id="mVoidRegT"><i class="bi bi-slash-circle me-1"></i>Void registration</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><p class="fw-semibold mb-2" data-row-fill="label"></p>
    <label class="form-label small" for="regVoidReason">Reason</label><input class="form-control" id="regVoidReason" name="reason" required maxlength="255" placeholder="e.g. wrong term, student withdrew">
    <div class="form-text">Only registrations without payments can be voided. The student can then be registered again for that term.</div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Void registration</button></div>
  </form>
</div></div></div>
<?php endif; ?>
<?php layout_end();
