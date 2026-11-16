<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
/** Class Schedules (v53): schedules per section and term, rooms, and printable weekly timetables. */
$tab = in_array($_GET['tab'] ?? '', ['schedules', 'rooms', 'print'], true) ? $_GET['tab'] : 'schedules';
$years = GS::schoolYears();
$sy = preg_match('/^\d{4}-\d{4}$/', (string) ($_GET['school_year'] ?? '')) ? (string) $_GET['school_year'] : (selected_sy() ?: ($years[0] ?? ''));
$term = in_array($_GET['term'] ?? '', TERMS, true) ? (string) $_GET['term'] : TERMS[0];
$section = (string) ($_GET['section'] ?? '');
$canEdit = Auth::can('schedules.edit');
$keep = ['school_year' => $sy, 'term' => $term];

if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'save') {
        $res = Schedules::save(In::int($_POST, 'id'), $_POST + ['school_year' => $sy, 'term' => $term]);
        flash($res['warnings'] ? 'warning' : 'success', 'Schedule saved.' . ($res['warnings'] ? "\n" . implode("\n", $res['warnings']) : ''));
        redirect('schedules', $keep + ['section' => (string) ($_POST['section_doc_id'] ?? '')]);
    }
    if ($a === 'auto_save') { // v65.6: Auto-schedule → Save
        $sec = (string) ($_POST['section_doc_id'] ?? '');
        if (!GS::get('sections', $sec)) throw new AppError('Choose the section.');
        $n = ScheduleAuto::save($sec, $sy, $term, $_POST);
        flash('success', "$n class schedule" . ($n === 1 ? '' : 's') . ' saved. Each one can still be changed with Edit.');
        redirect('schedules', $keep + ['section' => $sec]);
    }
    if ($a === 'delete') { Schedules::delete((int) ($_POST['id'] ?? 0)); flash('success', 'Schedule removed.'); redirect('schedules', $keep + ['section' => $section]); }
    if ($a === 'room') { Schedules::saveRoom(In::int($_POST, 'id'), $_POST); flash('success', 'Room saved.'); redirect('schedules', ['tab' => 'rooms']); }
    if ($a === 'copy') {
        [$from_sy, $from_term] = array_pad(explode('|', (string) ($_POST['from'] ?? '')), 2, '');
        [$n, $skip] = Schedules::copyTerm($from_sy, $from_term, $sy, $term);
        flash($skip ? 'warning' : 'success', "Copied $n schedule(s) to $sy $term." . ($skip ? " $skip were skipped because they clash with schedules already there." : ''));
        redirect('schedules', $keep);
    }
    throw new AppError('Unknown action.');
}

$sections = GS::sections($sy ?: null);
$subjects = GS::subjects(); $teachers = GS::teachers(); $rooms = Schedules::rooms();
$edit = isset($_GET['edit']) ? Db::one('SELECT * FROM sis_class_schedules WHERE id = ?', [(int) $_GET['edit']]) : null;
if ($edit) $section = $edit['section_doc_id'];
$opt = fn(array $rows, string $key, callable $label, ?string $sel) => implode('', array_map(fn($r) => '<option value="' . h((string) $r[$key]) . '"' . ((string) $r[$key] === (string) $sel ? ' selected' : '') . '>' . h($label($r)) . '</option>', $rows));
layout_start('Class Schedules');
page_title('Class Schedules', '', 'Days, times, rooms and teachers of each section\'s subjects. The same room, teacher or section can\'t have two classes at the same time.');
?>
<ul class="nav nav-tabs mb-3">
  <?php foreach (['schedules' => 'Schedules', 'rooms' => 'Rooms', 'print' => 'Timetables'] as $k => $l): ?><li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= url('schedules', ['tab' => $k] + $keep) ?>"><?= $l ?></a></li><?php endforeach; ?>
</ul>

<?php if ($tab === 'rooms'): $er = isset($_GET['room']) ? Db::one('SELECT * FROM sis_rooms WHERE id = ?', [(int) $_GET['room']]) : null; ?>
<div class="row g-3" data-record-detail>
  <div class="col-lg-8"><div class="form-section"><h2 class="h6">Rooms</h2>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Room</th><th>Building</th><th>Type</th><th class="text-end">Capacity</th><th>Classes a week</th><th></th></tr></thead><tbody>
    <?php foreach ($rooms as $rm): $n = (int) Db::val('SELECT COUNT(*) FROM sis_class_schedules WHERE room_id = ? AND school_year = ? AND term = ?', [$rm['id'], $sy, $term]); ?><tr<?= $rm['is_active'] ? '' : ' class="text-muted"' ?>>
      <td class="fw-semibold"><?= h($rm['code']) ?><?= $rm['is_active'] ? '' : ' <span class="badge text-bg-secondary">Inactive</span>' ?></td><td><?= h($rm['building']) ?></td><td><?= h(Schedules::ROOM_KINDS[$rm['kind']]) ?></td><td class="text-end"><?= (int) $rm['capacity'] ?></td>
      <td><a href="<?= url('schedules', ['tab' => 'print', 'by' => 'room', 'who' => $rm['id']] + $keep) ?>"><?= $n ?> in <?= h($term) ?></a></td>
      <td class="text-end"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('schedules', ['tab' => 'rooms', 'room' => $rm['id']]) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rooms): ?><tr><td colspan="6" class="text-center text-muted py-3">No rooms yet. Add the school's rooms first.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
  <?php if ($canEdit): ?><div class="col-lg-4"><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="room"><input type="hidden" name="id" value="<?= h((string) ($er['id'] ?? '')) ?>">
    <h2 class="h6"><?= $er ? 'Edit room' : 'Add room' ?></h2>
    <label class="form-label small mb-0" for="rmCode">Room *</label><input class="form-control mb-2" id="rmCode" name="code" required maxlength="30" placeholder="e.g. 201, LAB-1" value="<?= h((string) ($er['code'] ?? '')) ?>">
    <label class="form-label small mb-0" for="rmBldg">Building</label><input class="form-control mb-2" id="rmBldg" name="building" maxlength="80" value="<?= h((string) ($er['building'] ?? '')) ?>">
    <div class="row g-2"><div class="col-6"><label class="form-label small mb-0" for="rmCap">Capacity *</label><input class="form-control" id="rmCap" name="capacity" required inputmode="numeric" value="<?= h((string) ($er['capacity'] ?? '40')) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="rmKind">Type</label><select class="form-select" id="rmKind" name="kind"><?php foreach (Schedules::ROOM_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= ($er['kind'] ?? 'LECTURE') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></div>
    <div class="form-check my-2"><input class="form-check-input" type="checkbox" id="rmActive" name="is_active" value="1"<?= ($er['is_active'] ?? 1) ? ' checked' : '' ?>><label class="form-check-label small" for="rmActive">Active</label></div>
    <button class="btn btn-primary">Save room</button><?= $er ? ' <a class="btn btn-link" href="' . url('schedules', ['tab' => 'rooms']) . '">New instead</a>' : '' ?>
  </form></div><?php endif; ?>
</div>

<?php elseif ($tab === 'print'):
    $by = in_array($_GET['by'] ?? '', ['section', 'teacher', 'room'], true) ? $_GET['by'] : 'section';
    $who = (string) ($_GET['who'] ?? '');
    $rows = $who !== '' ? Schedules::list($keep + [$by => $who]) : [];
    $whoName = $by === 'section' ? ($who ? (GS::get('sections', $who)['sectionName'] ?? '') : '') : ($by === 'teacher' ? ($who ? (GS::get('teachers', $who)['teacherName'] ?? '') : '') : ($who ? (Db::val('SELECT code FROM sis_rooms WHERE id = ?', [(int) $who]) ?: '') : '')); ?>
<form method="get" class="form-section d-flex flex-wrap gap-2 align-items-end d-print-none" data-record-detail><input type="hidden" name="r" value="schedules"><input type="hidden" name="tab" value="print">
  <div><label class="form-label small mb-0" for="pSy">School year</label><select class="form-select" id="pSy" name="school_year"><?php foreach ($years as $y): ?><option<?= $y === $sy ? ' selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0" for="pTerm">Term</label><select class="form-select" id="pTerm" name="term"><?php foreach (TERMS as $t): ?><option<?= $t === $term ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0" for="pBy">Timetable of</label><select class="form-select" id="pBy" name="by"><?php foreach (['section' => 'A section', 'teacher' => 'A teacher', 'room' => 'A room'] as $k => $l): ?><option value="<?= $k ?>"<?= $by === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0" for="pWho">Which</label><select class="form-select" id="pWho" name="who"><option value="">— choose —</option>
    <?php if ($by === 'section') echo $opt($sections, 'id', fn($s) => $s['sectionName'] . ' · ' . ($s['yearLevel'] ?? ''), $who);
          elseif ($by === 'teacher') echo $opt($teachers, 'id', fn($t) => $t['teacherName'], $who);
          else echo $opt($rooms, 'id', fn($r) => $r['code'] . ($r['building'] ? ' · ' . $r['building'] : ''), $who); ?></select></div>
  <button class="btn btn-outline-primary">Show</button>
</form>
<?php if ($who !== ''): ?>
<div class="form-section" data-record-detail>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h2 class="h5 mb-0 me-auto"><?= h(School::brand()) ?> · Class schedule of <?= h(['section' => 'section ', 'teacher' => '', 'room' => 'room '][$by] . $whoName) ?> · <?= h($term . ' ' . $sy) ?></h2>
    <div class="doc-toolbar d-print-none"><button type="button" class="btn btn-sm btn-outline-secondary" id="docPrint"><i class="bi bi-printer"></i> Print</button></div></div>
  <?= Schedules::grid($rows, $by, $whoName) ?>
</div>
<script src="assets/doc-print.js?v=50"></script>
<?php endif; ?>

<?php else: // schedules of a section
    $rows = $section !== '' ? Schedules::list($keep + ['section' => $section]) : [];
    $secRow = $section !== '' ? GS::get('sections', $section) : null;
    $size = $section !== '' ? Schedules::sectionSize($section, $sy, $term) : 0;
    $classT = $section !== '' ? ScheduleTeacherSync::classTeachers(Db::pdo(), $section, $sy, $term) : []; // v65.5 ?>
<form method="get" class="form-section d-flex flex-wrap gap-2 align-items-end" data-record-detail><input type="hidden" name="r" value="schedules">
  <div><label class="form-label small mb-0" for="sSy">School year</label><select class="form-select" id="sSy" name="school_year"><?php foreach ($years as $y): ?><option<?= $y === $sy ? ' selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0" for="sTerm">Term</label><select class="form-select" id="sTerm" name="term"><?php foreach (TERMS as $t): ?><option<?= $t === $term ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0" for="sSec">Section</label><select class="form-select" id="sSec" name="section"><option value="">— choose —</option><?= $opt($sections, 'id', fn($s) => $s['sectionName'] . ' · ' . ($s['yearLevel'] ?? ''), $section) ?></select></div>
  <button class="btn btn-outline-primary">Show</button>
</form>
<?php if ($section === ''): ?><div class="alert alert-info">Choose a section to see and set its class schedule.</div>
<?php else: ?>
<?php if ($canEdit && isset($_GET['auto'])): $ao = ScheduleAuto::options($_GET); $draft = ScheduleAuto::draft($section, $sy, $term, $ao); $allRooms = Schedules::rooms(true); ?>
<section class="form-section auto-card" aria-labelledby="autoTitle" data-record-detail>
  <div class="auto-head"><h2 class="h6 mb-0" id="autoTitle"><i class="bi bi-magic" aria-hidden="true"></i> Auto-schedule · <?= h(($secRow['sectionName'] ?? '') . ' · ' . $term . ' ' . $sy) ?></h2>
    <a class="btn btn-sm btn-link" href="<?= url('schedules', $keep + ['section' => $section]) ?>">Close</a></div>
  <form method="get" class="auto-opts"><input type="hidden" name="r" value="schedules"><input type="hidden" name="school_year" value="<?= h($sy) ?>"><input type="hidden" name="term" value="<?= h($term) ?>"><input type="hidden" name="section" value="<?= h($section) ?>"><input type="hidden" name="auto" value="1"><input type="hidden" name="o_go" value="1">
    <fieldset><legend>Days</legend><?php foreach (Schedules::DAYS as $k => $l): if ($k === 'Su') continue; ?><label class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" name="o_wd[]" value="<?= $k ?>"<?= in_array($k, $ao['days'], true) ? ' checked' : '' ?>><span class="form-check-label"><?= $k ?></span></label><?php endforeach; ?></fieldset>
    <label>From <input class="form-control form-control-sm" type="time" name="o_start" step="1800" value="<?= h($ao['start']) ?>"></label>
    <label>To <input class="form-control form-control-sm" type="time" name="o_end" step="1800" value="<?= h($ao['end']) ?>"></label>
    <label class="form-check m-0"><input class="form-check-input" type="checkbox" name="o_lunch" value="1"<?= $ao['lunch'] ? ' checked' : '' ?>> <span class="form-check-label">Keep 12:00–1:00 free</span></label>
    <label>Hours a week per lecture unit <input class="form-control form-control-sm auto-num" name="o_lec_hours" inputmode="decimal" value="<?= h(LecLab::text($ao['lec_hours'])) ?>"></label>
    <label>per lab unit <input class="form-control form-control-sm auto-num" name="o_lab_hours" inputmode="decimal" value="<?= h(LecLab::text($ao['lab_hours'])) ?>"></label>
    <?php if ($allRooms): ?><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="o_rooms" value="1"<?= $ao['rooms'] ? ' checked' : '' ?>> <span class="form-check-label">Assign rooms</span></label><?php endif; ?>
    <button class="btn btn-sm btn-outline-primary">Make draft again</button>
  </form>
  <?php if (!$allRooms): ?><p class="small text-muted mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> No rooms yet, so rooms show TBA. Add rooms in the <a href="<?= url('schedules', ['tab' => 'rooms'] + $keep) ?>">Rooms</a> tab and they are filled in too.</p><?php endif; ?>
  <?php if ($draft['none']): ?><div class="alert alert-info mb-0">This section has no subjects (classes) for <?= h($term . ' ' . $sy) ?> yet. Saving its curriculum, or Grading assignments, creates them.</div>
  <?php else: ?>
  <h3 class="auto-sub">Subjects of the section</h3>
  <div class="table-responsive"><table class="table table-sm align-middle auto-units mb-3" data-plain-table><thead><tr><th>Subject</th><th class="text-center">Lec units</th><th class="text-center">Lab units</th><th class="text-center">Total units</th><th>Hours a week</th><th>Already scheduled</th><th>Teacher</th></tr></thead><tbody>
  <?php $tu = [0, 0, 0]; foreach ($draft['subjects'] as $su): $tu[0] += $su['lec']; $tu[1] += $su['lab']; $tu[2] += $su['total']; ?><tr>
    <td><span class="font-monospace fw-semibold"><?= h($su['code']) ?></span> <span class="small text-muted"><?= h($su['name']) ?></span></td>
    <td class="text-center"><?= h(LecLab::text($su['lec'])) ?></td><td class="text-center"><?= h(LecLab::text($su['lab'])) ?></td><td class="text-center fw-semibold"><?= h(LecLab::text($su['total'])) ?></td>
    <td class="small"><?= $su['need_lec'] > 0 ? 'Lecture ' . h(ScheduleAuto::hoursText($su['need_lec'])) : '' ?><?= $su['need_lec'] > 0 && $su['need_lab'] > 0 ? ' · ' : '' ?><?= $su['need_lab'] > 0 ? 'Lab ' . h(ScheduleAuto::hoursText($su['need_lab'])) : '' ?></td>
    <td class="small"><?= $su['have_lec'] + $su['have_lab'] > 0 ? h(ScheduleAuto::hoursText($su['have_lec'] + $su['have_lab'])) : '<span class="text-muted">—</span>' ?></td>
    <td class="small"><?= $su['teacher_name'] !== '' ? h($su['teacher_name']) : '<span class="text-muted">Needs teacher</span>' ?></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><th>Total</th><th class="text-center"><?= h(LecLab::text($tu[0])) ?></th><th class="text-center"><?= h(LecLab::text($tu[1])) ?></th><th class="text-center"><?= h(LecLab::text($tu[2])) ?></th><th colspan="3"></th></tr></tfoot></table></div>
  <?php if ($draft['unplaced']): ?><div class="alert alert-warning small"><strong>Could not place:</strong><ul class="mb-0 ps-3"><?php foreach ($draft['unplaced'] as $u): ?><li><?= h($u) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($draft['rows']): ?>
  <h3 class="auto-sub">Draft schedule <span class="small text-muted fw-normal">· change anything, untick a row to leave it out, then Save</span></h3>
  <form method="post" class="auto-draft"><?= csrf_field() ?><input type="hidden" name="action" value="auto_save"><input type="hidden" name="section_doc_id" value="<?= h($section) ?>">
    <div class="table-responsive"><table class="table table-sm align-middle mb-2" data-plain-table><thead><tr><th><span class="visually-hidden">Keep</span></th><th>Subject</th><th>Type</th><th>Days</th><th>Start</th><th>End</th><th>Room</th><th>Teacher</th></tr></thead><tbody>
    <?php foreach ($draft['rows'] as $i => $dr): ?><tr>
      <td><input class="form-check-input" type="checkbox" name="a_keep[]" value="<?= $i ?>" checked aria-label="Keep <?= h($dr['code']) ?>"><input type="hidden" name="a_subject[]" value="<?= h($dr['subject']) ?>"></td>
      <td><span class="font-monospace fw-semibold"><?= h($dr['code']) ?></span> <span class="small text-muted"><?= h($dr['name']) ?></span></td>
      <td><select class="form-select form-select-sm" name="a_kind[]" aria-label="Type"><?php foreach (['LEC' => 'Lecture', 'LAB' => 'Laboratory'] as $k => $l): ?><option value="<?= $k ?>"<?= $dr['kind'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
      <td><input class="form-control form-control-sm auto-days" name="a_days[]" value="<?= h(implode('', $dr['days'])) ?>" aria-label="Days, e.g. MWF or TTh" pattern="^\s*((Su|Th|M|T|W|F|S)[\s,]*)+$" required></td>
      <td><input class="form-control form-control-sm" type="time" name="a_start[]" step="300" value="<?= h($dr['start']) ?>" required aria-label="Start"></td>
      <td><input class="form-control form-control-sm" type="time" name="a_end[]" step="300" value="<?= h($dr['end']) ?>" required aria-label="End"></td>
      <td><select class="form-select form-select-sm" name="a_room[]" aria-label="Room"><option value="">TBA</option><?php foreach ($allRooms as $rm): ?><option value="<?= (int) $rm['id'] ?>"<?= (int) $dr['room'] === (int) $rm['id'] ? ' selected' : '' ?>><?= h($rm['code']) ?></option><?php endforeach; ?></select></td>
      <td class="small"><?= $dr['teacher_name'] !== '' ? h($dr['teacher_name']) : '<span class="text-muted">Needs teacher</span>' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="form-text mt-0">Days: M T W Th F S (e.g. MWF, TTh). The teacher is the class's teacher in Grading assignments and follows it. Saving checks every row again; if one clashes, nothing is saved and the message says which row.</p>
    <button class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save schedule</button> <a class="btn btn-link" href="<?= url('schedules', $keep + ['section' => $section]) ?>">Cancel</a>
  </form>
  <?php elseif (!$draft['unplaced']): ?><div class="alert alert-success mb-0">Every subject of this section already has its hours in the schedule.</div><?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
<div class="row g-3" data-record-detail>
  <div class="col-xl-8">
    <div class="form-section"><h2 class="h6 d-flex flex-wrap align-items-center gap-2"><span><?= h(($secRow['sectionName'] ?? '') . ' · ' . $term . ' ' . $sy) ?> <span class="small text-muted fw-normal">· <?= $size ?> student(s) registered</span></span><?php if ($canEdit && !isset($_GET['auto'])): ?> <a class="btn btn-sm btn-primary ms-auto auto-btn" href="<?= url('schedules', $keep + ['section' => $section, 'auto' => 1]) ?>"><i class="bi bi-magic me-1" aria-hidden="true"></i>Auto-schedule</a><?php endif; ?></h2>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Subject</th><th>Days</th><th>Time</th><th>Room</th><th>Teacher</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $s): $over = $s['capacity'] !== null && $size > (int) $s['capacity']; ?><tr>
        <td><span class="font-monospace"><?= h($s['subject_code']) ?></span> <span class="small text-muted"><?= h(Schedules::KINDS[$s['kind']]) ?></span></td>
        <td><?= h(Schedules::daysText($s['days'])) ?></td><td class="text-nowrap"><?= h(Schedules::timeRange($s)) ?></td>
        <td><?= $s['room_code'] ? h($s['room_code']) . ($over ? ' <span class="badge text-bg-warning" title="' . $size . ' students, room holds ' . (int) $s['capacity'] . '">Over capacity</span>' : '') : '<span class="text-muted">—</span>' ?></td>
        <td><?= $s['teacher_name'] !== '' ? h($s['teacher_name']) : '<span class="text-muted">—</span>' ?><?php $ct = $classT[$s['subject_doc_id']] ?? null; if ($ct && $ct['id'] !== '' && $ct['id'] !== (string) ($s['teacher_doc_id'] ?? '')): ?>
          <span class="sched-teacher-diff" title="The class in Grading assignments has another teacher. Edit this schedule to use the same teacher."><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Grading teacher: <?= h($ct['name']) ?></span><?php endif; ?></td>
        <td class="text-end text-nowrap"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('schedules', $keep + ['section' => $section, 'edit' => $s['id']]) ?>">Edit</a>
          <form method="post" class="d-inline" data-confirm="Remove this schedule?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Remove"><i class="bi bi-x"></i></button></form><?php endif; ?></td>
      </tr><?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3">No schedule yet for this section and term.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <div class="form-section"><h2 class="h6">Week</h2><?= Schedules::grid($rows, 'section') ?></div>
  </div>
  <?php if ($canEdit): $e = $edit ?: []; $eDays = $e ? explode(',', $e['days']) : []; ?>
  <div class="col-xl-4">
    <form method="post" class="form-section" id="schedForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h((string) ($e['id'] ?? '')) ?>"><input type="hidden" name="section_doc_id" value="<?= h($section) ?>">
      <h2 class="h6"><?= $e ? 'Edit schedule' : 'Add a class' ?></h2>
      <label class="form-label small mb-0" for="scSub">Subject *</label>
      <select class="form-select mb-2" id="scSub" name="subject_doc_id" required><option value="">— choose —</option><?= $opt($subjects, 'id', fn($x) => ($x['subjectCode'] ?? '') . ' · ' . ($x['subjectName'] ?? ''), $e['subject_doc_id'] ?? null) ?></select>
      <span class="form-label small d-block mb-0">Days *</span>
      <div class="d-flex flex-wrap gap-2 mb-2"><?php foreach (Schedules::DAYS as $k => $l): ?><label class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" name="days[]" value="<?= $k ?>"<?= in_array($k, $eDays, true) ? ' checked' : '' ?>><span class="form-check-label small"><?= $k ?></span></label><?php endforeach; ?></div>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small mb-0" for="scStart">Start *</label><input class="form-control" type="time" id="scStart" name="start_time" required step="300" value="<?= h(substr((string) ($e['start_time'] ?? '07:30'), 0, 5)) ?>"></div>
        <div class="col-6"><label class="form-label small mb-0" for="scEnd">End *</label><input class="form-control" type="time" id="scEnd" name="end_time" required step="300" value="<?= h(substr((string) ($e['end_time'] ?? '08:30'), 0, 5)) ?>"></div></div>
      <label class="form-label small mb-0" for="scKind">Type</label><select class="form-select mb-2" id="scKind" name="kind"><?php foreach (Schedules::KINDS as $k => $l): ?><option value="<?= $k ?>"<?= ($e['kind'] ?? 'LEC') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <label class="form-label small mb-0" for="scRoom">Room</label><select class="form-select mb-2" id="scRoom" name="room_id"><option value="">— none yet —</option><?= $opt(array_filter($rooms, fn($r) => $r['is_active']), 'id', fn($r) => $r['code'] . ' · holds ' . $r['capacity'] . ($r['building'] ? ' · ' . $r['building'] : ''), (string) ($e['room_id'] ?? '')) ?></select>
      <label class="form-label small mb-0" for="scTeacher">Teacher</label><select class="form-select mb-1" id="scTeacher" name="teacher_doc_id" data-class-teachers="<?= h(json_encode(array_map(fn($c) => $c['id'], $classT), JSON_UNESCAPED_UNICODE)) ?>"><option value="">— none yet —</option><?= $opt(array_filter($teachers, fn($t) => $t['id'] !== '_acadlink_professor_x'), 'id', fn($t) => $t['teacherName'], $e['teacher_doc_id'] ?? null) ?></select>
      <p class="form-text mt-0 mb-3" data-class-teacher-note>The teacher fills in from this section's class in Grading assignments. Leave it empty to use that teacher; a later change of the class's teacher also changes this schedule.</p>
      <button class="btn btn-primary">Save</button><?= $e ? ' <a class="btn btn-link" href="' . url('schedules', $keep + ['section' => $section]) . '">Cancel</a>' : '' ?>
    </form>
    <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="copy">
      <h2 class="h6">Copy from another term</h2><p class="small text-muted">Copies every schedule of that term into <?= h($term . ' ' . $sy) ?>, skipping ones that would clash.</p>
      <select class="form-select mb-2" name="from" aria-label="Copy from"><?php foreach (Db::all('SELECT DISTINCT school_year, term FROM sis_class_schedules ORDER BY school_year DESC, term') as $t): if ($t['school_year'] === $sy && $t['term'] === $term) continue; ?><option value="<?= h($t['school_year'] . '|' . $t['term']) ?>"><?= h($t['school_year'] . ' · ' . $t['term']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-outline-secondary btn-sm">Copy schedules</button></form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<script src="assets/schedule-teacher.js?v=65.5" defer></script>
<?php layout_end();
