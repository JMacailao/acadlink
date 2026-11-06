<?php
/** Transaction → Admission: record applicants, admit (gives the Student ID) or reject. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
// ?embed=1: the applicant form shown inside a pop-up from the list
$embed = isset($_GET['embed']);
$E = $embed ? ['embed' => 1] : [];
if ($isPost) {
    if (!$id && ($postedId = In::int($_POST, 'adm_id'))) $id = $postedId; // from the list's pop-ups
    $a = $_POST['action'] ?? 'save';
    $back = (string) ($_POST['back'] ?? '');
    $toBack = preg_match('/^index\.php\?r=admission(&[\w%=.\-+]*)*$/', $back) === 1;
    if ($a === 'docs') { EnrollmentDocs::saveChecklist((int) $id, (array) ($_POST['docs'] ?? [])); flash('success', 'Document checklist saved.'); redirect('admission', ['id' => $id] + $E); }
    if ($a === 'admit' || $a === 'reject' || $a === 'cancel') {
        if ($a === 'admit') {
            $isReturnee = in_array(Db::val('SELECT applicant_type FROM sis_admissions WHERE id = ?', [(int) $id]), Admission::KEEPS_ID, true);
            $no = Admission::admit((int) $id);
            flash('success', $isReturnee ? "Readmission approved for Student ID $no. Next: register the student for the term." : "Admitted. Student ID: $no. Next: register the student for the term.");
        }
        elseif ($a === 'reject') { Admission::reject((int) $id, (string) ($_POST['reason'] ?? '')); flash('success', 'Application rejected.'); }
        else { Admission::cancel((int) $id, (string) ($_POST['reason'] ?? '')); flash('success', 'Admission cancelled and hidden from the default list.'); }
        if ($toBack) { header('Location: ' . $back); exit; }
        redirect('admission', ['id' => $id] + $E);
    }
    $d = Admission::validate($_POST);
    if ($id) { Admission::update($id, $d); flash('success', 'Saved.'); }
    else {
        $id = Admission::create($d);
        if (!empty($_POST['docs_sent'])) EnrollmentDocs::saveChecklist($id, (array) ($_POST['docs'] ?? []));
        $miss = EnrollmentDocs::missing(Db::one('SELECT * FROM sis_admissions WHERE id = ?', [$id]));
        flash('success', 'Applicant recorded.' . ($miss ? ' Missing required documents: ' . implode(', ', $miss) . '.' : ' All required documents are ticked.') . ' Admit them when the requirements are complete.');
    }
    redirect('admission', ['id' => $id] + $E);
}

// ---------------------------------------------------------------- one applicant (page or pop-up)
if ($id || isset($_GET['new'])) {
    $e = $id ? Db::one('SELECT * FROM sis_admissions WHERE id = ?', [$id]) : null;
    if ($id && !$e) throw new AppError('Admission not found.');
    $editable = !$e || $e['status'] === 'PENDING';
    $v = fn(string $k, $def = '') => h($e[$k] ?? ($_POST[$k] ?? $def));
    layout_start($e ? 'Applicant ' . $e['admission_no'] : 'New applicant', $embed);
    if ($embed) echo '<div class="embed-wrap">';
    else page_title($e ? 'Applicant ' . $e['admission_no'] : 'New applicant', '<a class="btn btn-outline-secondary" href="' . url('admission') . '"><i class="bi bi-arrow-left"></i> All applicants</a>', 'Record the applicant, then admit them. Admitting gives the Student ID; then register them (step 2).');
    ?>
  <?php if ($e && $e['status'] !== 'PENDING'): ?>
    <div class="alert alert-<?= $e['status'] === 'ADMITTED' ? 'success' : 'secondary' ?> d-flex flex-wrap align-items-center gap-2">
      <div class="me-auto"><?= badge($e['status']) ?> <?= $e['status'] === 'ADMITTED' ? 'Student ID <strong class="font-monospace">' . h($e['student_number']) . '</strong>' : h($e['remarks']) ?></div>
      <?php if ($e['status'] === 'ADMITTED' && Auth::can('registration')): $reg = Db::val("SELECT id FROM sis_registrations WHERE student_number = ? AND status <> 'CANCELLED' ORDER BY id DESC LIMIT 1", [$e['student_number']]); ?>
        <?= $reg ? '<a class="btn btn-sm btn-outline-primary" target="_top" href="' . url('registration', ['id' => $reg]) . '">Open registration</a>' : '<a class="btn btn-sm btn-primary" href="' . url('registration', ['new' => 1, 'admission' => $e['id']] + $E) . '"><i class="bi bi-arrow-right"></i> Register (step 2)</a>' ?>
        <?php if (!$reg && Auth::can('admission.approve')): ?><button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mCancelDetail"><i class="bi bi-slash-circle"></i> Cancel Admission</button><?php endif; ?>
      <?php endif; ?></div>
  <?php endif; ?>
  <form method="post" action="<?= url('admission', ($id ? ['id' => $id] : ['new' => 1]) + $E) ?>" class="form-section"><?= csrf_field() ?>
    <fieldset <?= $editable ? '' : 'disabled' ?>>
    <h2 class="h6"><?= $e ? h($e['admission_no']) . ' · ' . h($e['last_name'] . ', ' . $e['first_name']) : 'New applicant' ?></h2>
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label small" for="aType">Student classification *</label><select class="form-select form-select-sm" id="aType" name="applicant_type" required data-doc-type><option value="">— choose —</option><?php foreach (Admission::TYPES as $k => $l): ?><option value="<?= $k ?>"<?= ($e['applicant_type'] ?? ($_POST['applicant_type'] ?? '')) === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select><div class="form-text">Continuing students and returnees keep the Student ID they already have.</div></div>
      <div class="col-md-4"><label class="form-label small" for="aStudentNo">Student ID (continuing / returnee)</label><input class="form-control form-control-sm font-monospace" id="aStudentNo" name="student_number" maxlength="40" value="<?= h((string) ($e['student_number'] ?? '')) ?>" placeholder="e.g. 20240015"></div>
      <div class="col-md-4"><label class="form-label small" for="aSy">School year</label><input class="form-control form-control-sm" id="aSy" name="school_year" required value="<?= $v('school_year', selected_sy()) ?>"></div>
      <div class="col-md-4"><label class="form-label small" for="aLevel">Year level</label><select class="form-select form-select-sm" id="aLevel" name="year_level"><?= list_options(YEAR_LEVELS, $e['year_level'] ?? '1st Year') ?></select></div>
      <div class="col-md-4"><label class="form-label small" for="aProgram">Program</label><input class="form-control form-control-sm" id="aProgram" name="program" list="programs" maxlength="40" value="<?= $v('program') ?>"></div>
      <div class="col-md-8"><label class="form-label small" for="aPrev">Previous school (transferee)</label><input class="form-control form-control-sm" id="aPrev" name="previous_school" maxlength="150" value="<?= $v('previous_school') ?>"></div>
      <div class="col-md-4"><label class="form-label small" for="aLast">Last name *</label><input class="form-control form-control-sm" id="aLast" name="last_name" required maxlength="80" value="<?= $v('last_name') ?>"></div>
      <div class="col-md-4"><label class="form-label small" for="aFirst">First name *</label><input class="form-control form-control-sm" id="aFirst" name="first_name" required maxlength="80" value="<?= $v('first_name') ?>"></div>
      <div class="col-md-4"><label class="form-label small" for="aMiddle">Middle name</label><input class="form-control form-control-sm" id="aMiddle" name="middle_name" maxlength="80" value="<?= $v('middle_name') ?>"></div>
      <div class="col-md-4"><label class="form-label small" for="aBirth">Birth date</label><input class="form-control form-control-sm" type="date" id="aBirth" name="birth_date" value="<?= $v('birth_date') ?>"></div>
      <div class="col-md-2"><label class="form-label small" for="aSex">Sex</label><select class="form-select form-select-sm" id="aSex" name="sex"><?= list_options(['F', 'M'], $e['sex'] ?? null, '—') ?></select></div>
      <div class="col-md-6"><label class="form-label small" for="aEmail">Email</label><input class="form-control form-control-sm" type="email" id="aEmail" name="email" maxlength="191" value="<?= $v('email') ?>"></div>
      <div class="col-md-4"><label class="form-label small" for="aPhone">Mobile</label><input class="form-control form-control-sm" id="aPhone" name="phone" maxlength="30" value="<?= $v('phone') ?>"></div>
      <div class="col-md-8"><label class="form-label small" for="aAddr">Complete address *</label><input class="form-control form-control-sm" id="aAddr" name="address" required minlength="5" maxlength="255" placeholder="House no. / street, barangay, town or city, province" value="<?= $v('address') ?>"></div>
      <div class="col-md-6"><label class="form-label small" for="aGuard">Parent / guardian</label><input class="form-control form-control-sm" id="aGuard" name="guardian_name" maxlength="150" value="<?= $v('guardian_name') ?>"></div>
      <div class="col-md-6"><label class="form-label small" for="aGuardPh">Guardian's mobile</label><input class="form-control form-control-sm" id="aGuardPh" name="guardian_phone" maxlength="30" value="<?= $v('guardian_phone') ?>"></div>
      <div class="col-12"><label class="form-label small" for="aRemarks">Remarks (requirements submitted, etc.)</label><input class="form-control form-control-sm" id="aRemarks" name="remarks" maxlength="255" value="<?= $v('remarks') ?>"></div>
    </div>
    <datalist id="programs"><?php foreach (GS::programs() as $pg): ?><option><?= h($pg) ?></option><?php endforeach; ?></datalist>
    <?php if (!$e): $docType = (string) ($_POST['applicant_type'] ?? ''); $docChecked = array_flip(array_map('intval', (array) ($_POST['docs'] ?? []))); $docEditable = true; ?><input type="hidden" name="docs_sent" value="1"><?php require __DIR__ . '/../views/admission_docs.php'; endif; ?>
    <?php if ($editable): ?><button class="btn btn-sm btn-primary mt-2"><i class="bi bi-save"></i> <?= $e ? 'Save' : 'Save applicant' ?></button><?php endif; ?>
    </fieldset>
  </form>
  <?php if ($e): $docType = (string) $e['applicant_type']; $docSubmitted = EnrollmentDocs::submitted((int) $e['id']); $docChecked = $docSubmitted; $docEditable = in_array($e['status'], ['PENDING', 'ADMITTED'], true) && Auth::can('admission'); ?>
  <form method="post" action="<?= url('admission', ['id' => $id] + $E) ?>" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="docs">
    <?php require __DIR__ . '/../views/admission_docs.php'; ?>
    <?php if ($docEditable): ?><button class="btn btn-sm btn-primary mt-2"><i class="bi bi-check2-square"></i> Save checklist</button><?php endif; ?>
  </form>
  <?php endif; ?>
  <?php if ($e && $e['status'] === 'ADMITTED' && Auth::can('admission.approve')): ?><div class="modal fade" id="mCancelDetail" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="<?= url('admission', ['id' => $id] + $E) ?>" data-confirm="Cancel this admitted applicant? The record will be kept as voided."><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><div class="modal-header"><h2 class="modal-title fs-5 text-danger">Cancel Admission</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="small">This is allowed only because the student has no active registration. The admission will be kept and hidden from the default list.</p><input class="form-control" name="reason" required maxlength="255" placeholder="Reason for cancellation"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep admission</button><button class="btn btn-danger">Cancel Admission</button></div></form></div></div></div><?php endif; ?>
  <?php if ($e && $e['status'] === 'PENDING' && Auth::can('admission.approve')): $missingDocs = EnrollmentDocs::missing($e); ?>
  <?php if ($missingDocs): ?><div class="alert alert-warning py-2 small mb-2" role="status"><strong><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Missing required documents:</strong> <?= h(implode(', ', $missingDocs)) ?>. You can still admit; the checklist keeps track of what is still to be submitted.</div><?php endif; ?>
  <div class="form-section d-flex flex-wrap gap-2 align-items-start">
    <?= in_array($e['applicant_type'] ?? '', Admission::KEEPS_ID, true)
        ? post_button('admission', ['id' => $id] + $E, ['action' => 'admit'], '<i class="bi bi-check2-circle"></i> Approve readmission (keeps Student ID ' . h((string) $e['student_number']) . ')', 'btn-success', 'Approve this readmission request?')
        : post_button('admission', ['id' => $id] + $E, ['action' => 'admit'], '<i class="bi bi-check2-circle"></i> Admit (gives the Student ID)', 'btn-success', 'Admit this applicant?') ?>
    <form method="post" action="<?= url('admission', ['id' => $id] + $E) ?>" class="input-group input-group-sm" style="max-width:380px" data-confirm="Reject this applicant?"><?= csrf_field() ?><input type="hidden" name="action" value="reject">
      <input class="form-control" name="reason" required maxlength="255" placeholder="Reason for rejecting" aria-label="Reason for rejecting"><button class="btn btn-outline-danger">Reject</button></form>
  </div>
  <?php endif; ?>
    <?php
    if ($embed) echo '</div>';
    layout_end($embed);
    return;
}

// ---------------------------------------------------------------- list (applicants screen)
$fSy = trim((string) ($_GET['sy'] ?? ''));
if ($fSy !== '' && !preg_match('/^\d{4}-\d{4}$/', $fSy)) $fSy = '';
$status = in_array($_GET['status'] ?? '', ['PENDING', 'ADMITTED', 'REJECTED', 'CANCELLED', 'TOREGISTER'], true) ? $_GET['status'] : '';
$showVoided = ($_GET['show_voided'] ?? '') === '1';
$fType = array_key_exists($_GET['type'] ?? '', Admission::TYPES) ? $_GET['type'] : '';
$fProgram = trim((string) ($_GET['program'] ?? ''));
$fLevel = in_array($_GET['level'] ?? '', YEAR_LEVELS, true) ? $_GET['level'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$regSub = "(SELECT r.id FROM sis_registrations r WHERE r.student_number = a.student_number AND r.status <> 'CANCELLED' ORDER BY r.id DESC LIMIT 1)";
$where = ['1=1']; $p = [];
if (!$showVoided && $status !== 'CANCELLED') $where[] = "a.status <> 'CANCELLED'";
if ($fSy !== '') { $where[] = 'a.school_year = ?'; $p[] = $fSy; }
if ($status === 'TOREGISTER') $where[] = "a.status = 'ADMITTED' AND $regSub IS NULL";
elseif ($status) { $where[] = 'a.status = ?'; $p[] = $status; }
if ($fType) { $where[] = 'a.applicant_type = ?'; $p[] = $fType; }
if ($fProgram !== '') { $where[] = 'a.program = ?'; $p[] = $fProgram; }
if ($fLevel) { $where[] = 'a.year_level = ?'; $p[] = $fLevel; }
if ($q !== '') { $where[] = '(a.admission_no LIKE ? OR a.last_name LIKE ? OR a.first_name LIKE ? OR a.student_number LIKE ? OR CONCAT(a.last_name, \', \', a.first_name) LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
$rows = RecordList::query("SELECT a.*, $regSub AS reg_id, u.full_name AS created_by_name FROM sis_admissions a LEFT JOIN sis_users u ON u.id = a.created_by
                  WHERE " . implode(' AND ', $where) . ' ORDER BY FIELD(a.status, "PENDING", "ADMITTED", "REJECTED", "CANCELLED"), a.id DESC LIMIT 2000', $p);
$sum = ['PENDING' => 0, 'ADMITTED' => 0, 'REJECTED' => 0, 'CANCELLED' => 0, 'toReg' => 0] + array_fill_keys(array_keys(Admission::TYPES), 0);
foreach ($rows as $r) {
    $sum[$r['status']]++; $sum[$r['applicant_type']]++;
    if ($r['status'] === 'ADMITTED' && !$r['reg_id']) $sum['toReg']++;
}
$years = Db::all('SELECT DISTINCT school_year FROM sis_admissions ORDER BY school_year DESC');
$years = array_values(array_unique(array_merge([selected_sy()], array_column($years, 'school_year'))));
$programs = array_column(Db::all("SELECT DISTINCT program FROM sis_admissions WHERE program IS NOT NULL AND program <> '' ORDER BY program"), 'program');
$keep = array_filter(['sy' => $fSy, 'status' => $status, 'type' => $fType, 'program' => $fProgram, 'level' => $fLevel, 'show_voided' => $showVoided ? '1' : '', 'q' => $q], fn($v) => $v !== '');
if(RecordList::applied())$keep['apply']='1';
$showFilters = ($_GET['filters'] ?? '') !== 'hide';
$back = url('admission', $keep);
$canRegister = Auth::can('registration');
layout_start('Admission');
RecordList::panel();
echo NotificationCenter::banner('admission');
?>
<style>@media print { @page { size: landscape; margin: 10mm; } }</style>
<div class="admission-heading">
  <div class="admission-heading-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
  <div class="min-w-0"><p>Manage applicants, review decisions, and move admitted students into registration.</p></div>
  <a class="btn btn-primary admission-new d-none d-sm-inline-flex" href="<?= url('admission', ['new' => 1]) ?>" data-frame="<?= url('admission', ['new' => 1, 'embed' => 1]) ?>" data-frame-title="New applicant" data-frame-size="lg"><i class="bi bi-plus-lg me-1"></i>New Applicant</a>
</div>
<div class="admission-stats" aria-label="Applicant summary">
  <a class="admission-stat stat-total" href="<?= url('admission', $keep) ?>"><span class="stat-icon"><i class="bi bi-people"></i></span><span class="stat-copy"><span class="stat-label">Applicants listed</span><strong><?= number_format(count($rows)) ?></strong></span></a>
  <a class="admission-stat stat-pending" href="<?= url('admission', array_merge($keep, ['status' => 'PENDING'])) ?>"><span class="stat-icon"><i class="bi bi-clock-history"></i></span><span class="stat-copy"><span class="stat-label">Pending</span><strong><?= number_format($sum['PENDING']) ?></strong></span></a>
  <a class="admission-stat stat-admitted" href="<?= url('admission', array_merge($keep, ['status' => 'ADMITTED'])) ?>"><span class="stat-icon"><i class="bi bi-check2-circle"></i></span><span class="stat-copy"><span class="stat-label">Admitted</span><strong><?= number_format($sum['ADMITTED']) ?></strong></span></a>
  <a class="admission-stat stat-rejected" href="<?= url('admission', array_merge($keep, ['status' => 'REJECTED'])) ?>"><span class="stat-icon"><i class="bi bi-x-circle"></i></span><span class="stat-copy"><span class="stat-label">Rejected</span><strong><?= number_format($sum['REJECTED']) ?></strong></span></a>
  <a class="admission-stat stat-register" href="<?= url('admission', array_merge($keep, ['status' => 'TOREGISTER'])) ?>"><span class="stat-icon"><i class="bi bi-journal-check"></i></span><span class="stat-copy"><span class="stat-label">Not registered</span><strong><?= number_format($sum['toReg']) ?></strong></span></a>
</div>
<div class="or-screen">
  <div class="or-toolbar d-print-none" role="toolbar" aria-label="Applicants">
    <button type="button" class="tb" disabled data-row="frame" data-row-key="view" data-row-default data-frame-title="Applicant" data-frame-size="lg"><i class="bi bi-eye"></i>View / Edit</button>
    <a class="tb" href="<?= url('admission', ['new' => 1]) ?>" data-frame="<?= url('admission', ['new' => 1, 'embed' => 1]) ?>" data-frame-title="New applicant" data-frame-size="lg"><i class="bi bi-plus-circle text-success"></i>New</a>
    <?php if (Auth::can('admission.approve')): ?><button type="button" class="tb" disabled data-row="modal" data-row-if="pending" data-bs-toggle="modal" data-bs-target="#mAdmit"><i class="bi bi-check2-circle text-success"></i>Admit</button>
    <button type="button" class="tb" disabled data-row="modal" data-row-if="pending" data-bs-toggle="modal" data-bs-target="#mReject"><i class="bi bi-x-circle text-danger"></i>Reject</button>
    <button type="button" class="tb" disabled data-row="modal" data-row-if="cancellable" data-bs-toggle="modal" data-bs-target="#mCancel"><i class="bi bi-slash-circle text-danger"></i>Cancel Admission</button><?php endif; ?>
    <?php if ($canRegister): ?><button type="button" class="tb" disabled data-row="frame" data-row-key="register" data-row-if="registrable" data-frame-title="New registration" data-frame-size="lg"><i class="bi bi-journal-plus text-primary"></i>Register</button><?php endif; ?>
    <span class="tb-sep"></span>
    <a class="tb<?= $showFilters ? ' active' : '' ?>" href="<?= url('admission', $keep + ['filters' => $showFilters ? 'hide' : 'show']) ?>"><i class="bi bi-funnel"></i><?= $showFilters ? 'Hide' : 'Show' ?> Filters</a>
    <button type="submit" form="admFilters" class="tb"><i class="bi bi-check2-circle text-success"></i>Apply Filters</button>
    <button type="button" class="tb" data-print><i class="bi bi-printer"></i>Print List</button>
    <?= alt_search('admission', $keep, 'q', 'Applicant name, Admission No. or Student ID') ?>
    <a class="tb" href="<?= url('admission', $keep) ?>"><i class="bi bi-arrow-clockwise text-success"></i>Refresh</a>
  </div>
  <div class="or-body">
    <div class="or-main">
      <form id="admFilters" data-record-filters method="get" class="or-filters d-print-none<?= $showFilters ? '' : ' d-none' ?>"><input type="hidden" name="r" value="admission"><input type="hidden" name="apply" value="1">
        <div class="or-filters-title">Filter Preferences</div>
        <div class="fp-grid">
          <?= fp_row('School Year', '<select class="form-select form-select-sm" name="sy" aria-label="School year">' . list_options($years, $fSy ?: $years[0]) . '</select>', $fSy !== '') ?>
          <?= fp_row('Admission Status', '<select class="form-select form-select-sm" name="status" aria-label="Admission status">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($status === $k ? ' selected' : '') . '>' . $l . '</option>', ['PENDING', 'ADMITTED', 'TOREGISTER', 'REJECTED', 'CANCELLED'], ['Pending', 'Admitted', 'Admitted, not registered', 'Rejected', 'Voided'])) . '</select>', $status !== '') ?>
          <?= fp_row('Show Voided', '<label class="form-check form-switch small"><input class="form-check-input" type="checkbox" name="show_voided" value="1"' . ($showVoided ? ' checked' : '') . '> Include cancelled admissions</label>', $showVoided) ?>
          <?= fp_row('Classification', '<select class="form-select form-select-sm" name="type" aria-label="Student classification">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($fType === $k ? ' selected' : '') . '>' . h($l) . '</option>', array_keys(Admission::TYPES), Admission::TYPES)) . '</select>', $fType !== '') ?>
          <?= fp_row('Program', '<select class="form-select form-select-sm" name="program" aria-label="Program">' . list_options($programs, $fProgram) . '</select>', $fProgram !== '') ?>
          <?= fp_row('Year Level', '<select class="form-select form-select-sm" name="level" aria-label="Year level">' . list_options(YEAR_LEVELS, $fLevel ?: YEAR_LEVELS[0]) . '</select>', $fLevel !== '') ?>
        </div>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>
        <div class="fp-actions"><?php if ($keep): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('admission') ?>">Clear</a><?php endif; ?><button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Apply</button></div>
      </form>
      <div class="print-only"><?php $docTitle = 'Applicants' . ($fSy !== '' ? ' · ' . $fSy : ''); $docRight = ''; require __DIR__ . '/../views/print_header.php'; ?></div>
      <div class="or-grid-wrap">
        <table class="or-grid" id="admGrid" data-select>
          <thead><tr><th>Admission Number</th><th>Date</th><th>Applicant Name</th><th>Classification</th><th>Documents</th><th>Program</th><th>Year Level</th><th>School Year</th><th>Student ID</th><th>Status</th><th>Registration</th><th>Mobile</th><th>Recorded By</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $name = $r['last_name'] . ', ' . $r['first_name'] . ($r['middle_name'] ? ' ' . mb_substr($r['middle_name'], 0, 1) . '.' : ''); ?>
            <tr class="<?= $r['status'] === 'REJECTED' ? 'is-void' : '' ?>" tabindex="0" data-id="<?= (int) $r['id'] ?>"
                data-view="<?= h(url('admission', ['id' => $r['id'], 'embed' => 1])) ?>"
                data-register="<?= h(url('registration', ['new' => 1, 'admission' => $r['id'], 'embed' => 1])) ?>"
                data-pending="<?= $r['status'] === 'PENDING' ? '1' : '0' ?>" data-cancellable="<?= $r['status'] === 'ADMITTED' && !$r['reg_id'] ? '1' : '0' ?>" data-registrable="<?= $r['status'] === 'ADMITTED' && !$r['reg_id'] ? '1' : '0' ?>"
                data-label="<?= h($r['admission_no'] . ' · ' . $name . ' · ' . trim(($r['program'] ?? '') . ' ' . $r['year_level'])) ?>">
              <td class="mono"><?= h($r['admission_no']) ?></td>
              <td><?= h(date('m/d/Y', strtotime($r['created_at']))) ?></td>
              <td class="payer"><?= h(mb_strtoupper($name)) ?></td>
              <td><?= h(Admission::TYPES[$r['applicant_type']] ?? $r['applicant_type']) ?></td>
              <?php $dp = EnrollmentDocs::progress($r); ?><td title="<?= h($dp['missing'] ? 'Missing: ' . implode(', ', $dp['missing']) : 'All required documents submitted') ?>"><?= $dp['required'] ? ($dp['missing'] ? '<span class="text-warning-emphasis">' . $dp['done'] . ' of ' . $dp['required'] . '</span>' : '<span class="text-success">Complete</span>') : '—' ?></td>
              <td><?= h($r['program'] ?? '') ?></td>
              <td><?= h($r['year_level']) ?></td>
              <td><?= h($r['school_year']) ?></td>
              <td class="mono"><?= h($r['student_number'] ?? '') ?></td>
              <td><?= badge($r['status']) ?></td>
              <td><?= $r['reg_id'] ? '<a class="link-blue" href="' . url('registration', ['id' => $r['reg_id']]) . '">Registered</a>' : ($r['status'] === 'ADMITTED' ? '<span class="text-warning-emphasis">Not yet</span>' : '') ?></td>
              <td><?= h($r['phone'] ?? '') ?></td>
              <td><?= h($r['created_by_name'] ?? '') ?></td>
            </tr>
          <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="13">No applicants for these filters. Click <strong>New</strong> to record one.</td></tr><?php endif; ?>
          </tbody>
          <?php if ($rows): ?><tfoot><tr><td colspan="13"><?= count($rows) ?> applicant(s) · <?= $sum['PENDING'] ?> pending · <?= $sum['ADMITTED'] ?> admitted · <?= $sum['REJECTED'] ?> rejected</td></tr></tfoot><?php endif; ?>
        </table>
      </div>
    </div>
    <aside class="or-side">
      <?= sub_explorer() ?>
      <div class="or-panel">
        <div class="or-panel-title">Sub-Components</div>
        <ul class="or-links">
          <li><a href="<?= url('admission', ['new' => 1]) ?>" data-frame="<?= url('admission', ['new' => 1, 'embed' => 1]) ?>" data-frame-title="New applicant" data-frame-size="lg"><i class="bi bi-person-plus"></i>New Applicant</a></li>
          <li><a href="<?= url('admission', ['status' => 'PENDING']) ?>"><i class="bi bi-hourglass-split"></i>Waiting for Admission</a></li>
          <li><a href="<?= url('admission', ['status' => 'TOREGISTER']) ?>"><i class="bi bi-person-check"></i>Admitted, Not Registered</a></li>
          <?php if ($canRegister): ?><li><a href="<?= url('registration') ?>"><i class="bi bi-journal-text"></i>Registration</a></li><?php endif; ?>
          <?php if (Auth::can('students')): ?><li><a href="<?= url('students') ?>"><i class="bi bi-people"></i>Students</a></li><?php endif; ?>
        </ul>
      </div>
      <div class="or-panel">
        <div class="or-panel-title">Summaries</div>
        <div class="or-sum">
          <label>School Year</label><div class="or-box"><?= h($fSy !== '' ? $fSy : 'All years') ?></div>
          <label>Applicants Listed</label><div class="or-box n"><?= number_format(count($rows)) ?></div>
          <label>Pending · Admitted · Rejected</label><div class="or-box n"><?= $sum['PENDING'] ?> · <?= $sum['ADMITTED'] ?> · <?= $sum['REJECTED'] ?></div>
          <label>New · Continuing · Transferee · Unit Earner · Returnee</label><div class="or-box n"><?= $sum['NEW'] ?> · <?= $sum['CONTINUING'] ?> · <?= $sum['TRANSFEREE'] ?> · <?= $sum['UNIT_EARNER'] ?> · <?= $sum['RETURNEE'] ?></div>
          <label>Admitted, Not Registered</label><div class="or-box n grand"><?= number_format($sum['toReg']) ?></div>
        </div>
      </div>
    </aside>
  </div>
</div>

<div class="modal fade" id="mAdmit" tabindex="-1" aria-labelledby="mAdmitT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post" action="<?= url('admission') ?>"><?= csrf_field() ?>
  <input type="hidden" name="action" value="admit"><input type="hidden" name="adm_id" value="" data-row-fill="id"><input type="hidden" name="back" value="<?= h($back) ?>">
  <div class="modal-header"><h2 class="modal-title fs-5 text-success" id="mAdmitT"><i class="bi bi-check2-circle me-1"></i>Admit applicant</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><p class="fw-semibold mb-2" data-row-fill="label"></p>
    <p class="mb-0 small">Admitting creates the student in the grading system and gives the <strong>Student ID</strong>. Next step: register the student for the term.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Admit</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="mReject" tabindex="-1" aria-labelledby="mRejectT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post" action="<?= url('admission') ?>" data-confirm="Reject this applicant?"><?= csrf_field() ?>
  <input type="hidden" name="action" value="reject"><input type="hidden" name="adm_id" value="" data-row-fill="id"><input type="hidden" name="back" value="<?= h($back) ?>">
  <div class="modal-header"><h2 class="modal-title fs-5 text-danger" id="mRejectT"><i class="bi bi-x-circle me-1"></i>Reject applicant</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><p class="fw-semibold mb-2" data-row-fill="label"></p>
    <label class="form-label small" for="admRejectReason">Reason</label><input class="form-control" id="admRejectReason" name="reason" required maxlength="255" placeholder="e.g. incomplete requirements"></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Reject</button></div>
  </form>
</div></div></div>
<div class="modal fade" id="mCancel" tabindex="-1" aria-labelledby="mCancelT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post" action="<?= url('admission') ?>" data-confirm="Cancel this admitted applicant? The record will be kept as voided."><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="adm_id" value="" data-row-fill="id"><input type="hidden" name="back" value="<?= h($back) ?>">
  <div class="modal-header"><h2 class="modal-title fs-5 text-danger" id="mCancelT"><i class="bi bi-slash-circle me-1"></i>Cancel Admission</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><p class="fw-semibold mb-2" data-row-fill="label"></p><p class="small">Only admitted students with no active registration can be cancelled.</p><label class="form-label small" for="admCancelReason">Reason</label><input class="form-control" id="admCancelReason" name="reason" required maxlength="255" placeholder="e.g. applicant withdrew"></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep admission</button><button class="btn btn-danger">Cancel Admission</button></div>
  </form>
</div></div></div>
<?php layout_end();
