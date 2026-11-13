<?php
/** One class: Stream (announcements, materials), Classwork, People, Attendance, Grades and (teachers) Settings. */
declare(strict_types=1);
/** @var bool $isPost */
$c = Lms::classFor((string) ($_GET['id'] ?? ''));
$teach = in_array($c['role'], ['TEACHER', 'ADMIN'], true);
$tab = in_array($_GET['tab'] ?? '', ['stream', 'classwork', 'people', 'attendance', 'grades', 'settings'], true) ? $_GET['tab'] : 'stream';
if ($tab === 'settings' && !$teach) $tab = 'stream';
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    switch ($a) {
        case 'post': Lms::savePost($c, ($_POST['kind'] ?? '') === 'MATERIAL' ? 'MATERIAL' : 'ANNOUNCEMENT', $_POST, Lms::uploads($_FILES['files'] ?? null)); flash('success', 'Posted to the class.'); break;
        case 'delete_post': Lms::deletePost($c, (int) ($_POST['post'] ?? 0)); flash('success', 'Post removed.'); break;
        case 'work':
            $wid = Lms::saveWork($c, null, $_POST, Lms::uploads($_FILES['files'] ?? null));
            flash('success', ($_POST['kind'] ?? '') === 'QUIZ' ? 'Quiz created. Now add its questions.' : 'Classwork created.');
            redirect('work', ['id' => $c['id'], 'w' => $wid] + (($_POST['kind'] ?? '') === 'QUIZ' ? ['edit' => 'questions'] : []));
        case 'attendance':
            Lms::takeAttendance($c, (string) ($_POST['date'] ?? ''), (string) ($_POST['label'] ?? ''), array_map('strval', (array) ($_POST['mark'] ?? [])), (array) ($_POST['note'] ?? []), (string) ($_POST['topic'] ?? ''));
            flash('success', 'Attendance saved.'); break;
        case 'settings': Lms::saveSettings($c, $_POST); flash('success', 'Class settings saved.'); break;
        case 'send':
            $r = Lms::sendToGrading($c);
            flash($r['sent'] + $r['updated'] ? 'success' : 'warning', ($r['sent'] + $r['updated']) . ' grade(s) copied to the Grading System as drafts (' . $r['sent'] . ' new, ' . $r['updated'] . ' updated).'
                . ($r['skipped_submitted'] ? ' ' . $r['skipped_submitted'] . ' already submitted there were left as they are.' : '') . ($r['skipped_complete'] ? ' ' . $r['skipped_complete'] . ' completed term(s) were left alone.' : '')
                . ($r['skipped_empty'] ? ' ' . $r['skipped_empty'] . ' student(s) have no graded work yet.' : '') . ' Review and submit them in the Grading System.');
            break;
        default: throw new AppError('Unknown action.');
    }
    redirect('class', ['id' => $c['id'], 'tab' => $tab]);
}
$set = Lms::settings($c['id']);
layout_start($c['subjectCode'] . ' · ' . $c['sectionName']);
lms_class_header($c, $tab);

if ($tab === 'stream'):
    $posts = Lms::posts($c['id']);
    $upcoming = array_values(array_filter(Lms::classwork($c), fn($w) => $w['status'] === 'PUBLISHED' && $w['due_at'] && strtotime($w['due_at']) >= time()));
?>
<div class="lms-stream">
  <aside class="lms-panel lms-upcoming"><h2>Upcoming</h2>
    <?php foreach (array_slice($upcoming, 0, 5) as $w): ?><a href="<?= url('work', ['id' => $c['id'], 'w' => $w['id']]) ?>"><small>Due <?= h(lms_when($w['due_at'])) ?></small><b><?= h($w['title']) ?></b></a><?php endforeach; ?>
    <?php if (!$upcoming): ?><p class="lms-muted mb-0">No work due soon.</p><?php endif; ?>
    <a class="small" href="<?= url('class', ['id' => $c['id'], 'tab' => 'classwork']) ?>">View all</a>
    <?php if ($set['description']): ?><hr><p class="small mb-0"><?= nl2br(h($set['description'])) ?></p><?php endif; ?>
  </aside>
  <div>
    <?php if ($teach): ?>
    <details class="lms-panel lms-composer"><summary><span class="lms-avatar sm"><?= h(mb_strtoupper(mb_substr(Lms::me()['name'], 0, 1))) ?></span>Announce something to your class</summary>
      <form method="post" enctype="multipart/form-data" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="post"><input type="hidden" name="kind" value="ANNOUNCEMENT">
        <textarea class="form-control mb-2" name="body" rows="3" maxlength="20000" placeholder="Announcement" aria-label="Announcement" required></textarea>
        <div class="row g-2"><div class="col-md-6"><input class="form-control form-control-sm" name="link_url" maxlength="500" placeholder="Link (optional)" aria-label="Link"></div>
          <div class="col-md-6"><input class="form-control form-control-sm" type="file" data-max-mb="10" name="files[]" multiple aria-label="Attach files"></div></div>
        <div class="d-flex justify-content-between align-items-center mt-2"><label class="form-check small"><input class="form-check-input" type="checkbox" name="pinned" value="1"> Pin to the top</label><button class="btn btn-primary">Post</button></div>
      </form></details>
    <?php endif; ?>
    <?php foreach ($posts as $p): ?>
    <article class="lms-panel lms-post">
      <header><span class="lms-avatar sm" style="--h:<?= lms_hue($p['author_name']) ?>"><?= h(mb_strtoupper(mb_substr($p['author_name'], 0, 1))) ?></span><div><b><?= h($p['author_name']) ?></b><small><?= h(lms_when($p['created_at'])) ?><?= $p['kind'] === 'MATERIAL' ? ' · posted a material' : '' ?><?= (int) $p['pinned'] ? ' · <i class="bi bi-pin-angle-fill"></i> pinned' : '' ?></small></div>
        <?php if ($teach): ?><form method="post" class="ms-auto" data-confirm="Remove this post?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_post"><input type="hidden" name="post" value="<?= (int) $p['id'] ?>"><button class="lms-x" aria-label="Remove post"><i class="bi bi-trash3"></i></button></form><?php endif; ?></header>
      <?php if ($p['title']): ?><h3><?= h($p['title']) ?></h3><?php endif; ?>
      <?= lms_text($p['body']) ?>
      <?php if ($p['link_url']): ?><a class="lms-link" href="<?= h($p['link_url']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-link-45deg"></i><?= h($p['link_url']) ?></a><?php endif; ?>
      <?= lms_files_html($p['files']) ?>
    </article>
    <?php endforeach; if (!$posts): ?><div class="lms-panel lms-empty"><i class="bi bi-chat-square-text" aria-hidden="true"></i><p>This is where announcements and materials for the class appear.</p></div><?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'classwork'):
    $works = Lms::classwork($c); $mats = Lms::posts($c['id'], 'MATERIAL');
    $topics = array_values(array_unique(array_filter(array_merge(array_column($works, 'topic'), array_column($mats, 'topic')))));
    sort($topics);
?>
<?php if ($teach): ?>
<details class="lms-panel lms-create"><summary class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Create</summary>
  <div class="lms-create-tabs" data-lms-create>
    <button type="button" data-kind="ASSIGNMENT" class="active"><i class="bi bi-journal-text"></i>Assignment / activity</button>
    <button type="button" data-kind="QUIZ"><i class="bi bi-ui-checks"></i>Quiz / exam</button>
    <button type="button" data-kind="MATERIAL"><i class="bi bi-book"></i>Material</button>
  </div>
  <form method="post" enctype="multipart/form-data" data-form-for="ASSIGNMENT QUIZ"><?= csrf_field() ?><input type="hidden" name="action" value="work"><input type="hidden" name="kind" value="ASSIGNMENT" data-kind-input>
    <div class="row g-2">
      <div class="col-md-8"><label class="form-label small" for="wT">Title</label><input class="form-control" id="wT" name="title" required maxlength="200"></div>
      <div class="col-md-4"><label class="form-label small" for="wC">Grading category</label><select class="form-select" id="wC" name="category"><?php foreach ($set['weights'] as $cat => $wgt): ?><option value="<?= h($cat) ?>"><?= h($cat) ?> (<?= lms_pts($wgt) ?>%)</option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label small" for="wI">Instructions</label><textarea class="form-control" id="wI" name="instructions" rows="3" maxlength="20000"></textarea></div>
      <div class="col-6 col-md-3" data-only="ASSIGNMENT"><label class="form-label small" for="wP">Points</label><input class="form-control" id="wP" name="points" value="100" inputmode="decimal"></div>
      <div class="col-6 col-md-3" data-only="QUIZ"><label class="form-label small" for="wL">Time limit (minutes)</label><input class="form-control" id="wL" name="time_limit_min" inputmode="numeric" placeholder="none"></div>
      <div class="col-6 col-md-4"><label class="form-label small" for="wD">Due</label><input class="form-control" type="datetime-local" id="wD" name="due_at"></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="wTp">Topic</label><input class="form-control" id="wTp" name="topic" maxlength="80" list="lmsTopics" placeholder="e.g. Week 1"></div>
      <div class="col-12 d-flex flex-wrap gap-3">
        <label class="form-check small"><input class="form-check-input" type="checkbox" name="allow_late" value="1" checked> Accept late work</label>
        <label class="form-check small" data-only="ASSIGNMENT"><input class="form-check-input" type="checkbox" name="allow_resubmit" value="1" checked> Allow resubmitting after it is returned</label>
        <label class="form-check small" data-only="QUIZ"><input class="form-check-input" type="checkbox" name="show_results" value="1" checked> Show the score right after turning in (when there are no essays)</label>
        <label class="form-check small"><input class="form-check-input" type="checkbox" name="status" value="DRAFT"> Save as draft (students don't see it yet)</label></div>
      <div class="col-12" data-only="ASSIGNMENT"><label class="form-label small" for="wF">Attach files (up to 10 MB each)</label><input class="form-control" type="file" data-max-mb="10" id="wF" name="files[]" multiple></div>
    </div>
    <button class="btn btn-primary mt-2" data-submit-label>Assign</button>
  </form>
  <form method="post" enctype="multipart/form-data" data-form-for="MATERIAL" hidden><?= csrf_field() ?><input type="hidden" name="action" value="post"><input type="hidden" name="kind" value="MATERIAL">
    <div class="row g-2"><div class="col-md-8"><label class="form-label small" for="mT">Title</label><input class="form-control" id="mT" name="title" required maxlength="200"></div>
      <div class="col-md-4"><label class="form-label small" for="mTp">Topic</label><input class="form-control" id="mTp" name="topic" maxlength="80" list="lmsTopics"></div>
      <div class="col-12"><label class="form-label small" for="mB">Description</label><textarea class="form-control" id="mB" name="body" rows="2" maxlength="20000"></textarea></div>
      <div class="col-md-6"><label class="form-label small" for="mL">Link</label><input class="form-control" id="mL" name="link_url" maxlength="500" placeholder="https://"></div>
      <div class="col-md-6"><label class="form-label small" for="mF">Files</label><input class="form-control" type="file" data-max-mb="10" id="mF" name="files[]" multiple></div></div>
    <button class="btn btn-primary mt-2">Post material</button></form>
  <datalist id="lmsTopics"><?php foreach ($topics as $tp): ?><option value="<?= h($tp) ?>"><?php endforeach; ?></datalist>
</details>
<?php endif; ?>
<?php foreach (array_merge($topics, ['']) as $tp):
    $ws = array_filter($works, fn($w) => (string) $w['topic'] === $tp); $ms = array_filter($mats, fn($m) => (string) $m['topic'] === $tp);
    if (!$ws && !$ms) continue; ?>
<section class="lms-topic"><h2><?= $tp !== '' ? h($tp) : ($topics ? 'Other' : 'Classwork') ?></h2>
  <?php foreach ($ws as $w): $state = $c['role'] === 'STUDENT' ? Lms::state($w, $w['mine']) : null; ?>
  <a class="lms-item" href="<?= url('work', ['id' => $c['id'], 'w' => $w['id']]) ?>"><span class="lms-item-icon<?= $w['kind'] === 'QUIZ' ? ' quiz' : '' ?>"><i class="bi bi-<?= $w['kind'] === 'QUIZ' ? 'ui-checks' : 'journal-text' ?>" aria-hidden="true"></i></span>
    <span class="lms-item-main"><b><?= h($w['title']) ?></b><small><?= h($w['kind'] === 'QUIZ' ? 'Quiz' : $w['category']) ?> · <?= lms_pts($w['points']) ?> pts<?= $w['due_at'] ? ' · Due ' . h(lms_when($w['due_at'])) : ' · No due date' ?><?= $w['status'] === 'DRAFT' ? ' · <b>Draft</b>' : '' ?></small></span>
    <span class="lms-item-side"><?php if ($state): ?><?= lms_state_pill($state) ?><?= $w['mine'] && $w['mine']['status'] === 'RETURNED' && $w['mine']['score'] !== null ? '<b>' . lms_pts($w['mine']['score']) . '/' . lms_pts($w['points']) . '</b>' : '' ?>
      <?php else: ?><span class="lms-count"><b><?= (int) $w['turned_in'] ?></b> turned in</span><?php if ((int) $w['to_grade']): ?><span class="lms-pill is-late"><?= (int) $w['to_grade'] ?> to grade</span><?php endif; ?><?php endif; ?></span></a>
  <?php endforeach; ?>
  <?php foreach ($ms as $m): ?><div class="lms-item"><span class="lms-item-icon mat"><i class="bi bi-book" aria-hidden="true"></i></span><span class="lms-item-main"><b><?= h((string) $m['title']) ?></b><small>Material · <?= h(lms_when($m['created_at'], false)) ?></small><?= lms_text($m['body']) ?>
    <?php if ($m['link_url']): ?><a class="lms-link" href="<?= h($m['link_url']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-link-45deg"></i><?= h($m['link_url']) ?></a><?php endif; ?><?= lms_files_html($m['files']) ?></span></div><?php endforeach; ?>
</section>
<?php endforeach; if (!$works && !$mats): ?><div class="lms-panel lms-empty"><i class="bi bi-journal-plus" aria-hidden="true"></i><p><?= $teach ? 'Create assignments, activities, quizzes, exams and materials here.' : 'Your teacher has not posted any classwork yet.' ?></p></div><?php endif; ?>

<?php elseif ($tab === 'people'): $roster = Lms::roster($c); ?>
<section class="lms-panel lms-people"><h2>Teacher</h2><div class="lms-person"><span class="lms-avatar sm" style="--h:<?= lms_hue($c['teacherName']) ?>"><?= h(mb_strtoupper(mb_substr($c['teacherName'], 0, 1))) ?></span><?= h($c['teacherName']) ?></div></section>
<section class="lms-panel lms-people"><h2>Students <small><?= count($roster) ?></small></h2>
  <?php foreach ($roster as $s): ?><div class="lms-person"><span class="lms-avatar sm" style="--h:<?= lms_hue($s['name']) ?>"><?= h(mb_strtoupper(mb_substr($s['name'], 0, 1))) ?></span><span><?= h($s['name']) ?><?php if ($teach): ?><small><?= h($s['no']) ?><?= $s['email'] ? ' · ' . h($s['email']) : '' ?></small><?php endif; ?></span></div><?php endforeach; ?>
  <?php if (!$roster): ?><p class="lms-muted mb-0">No students are on this class list yet. The class list comes from the Grading System.</p><?php endif; ?></section>

<?php elseif ($tab === 'attendance'):
    if ($teach):
        $roster = Lms::roster($c); $sessions = Lms::sessions($c['id']); $sum = Lms::attendanceSummary($c['id']); $meet = Lms::meetings($c);
        $date = In::date($_GET, 'date') ?? date('Y-m-d');
        $labels = array_values(array_unique(array_map(fn($m) => Schedules::timeRange($m) . ($m['kind'] === 'LAB' ? ' Lab' : ''), $meet)));
        $label = (string) ($_GET['label'] ?? ($labels[0] ?? ''));
        $sid = (int) Db::val('SELECT id FROM lms_attendance_sessions WHERE class_id = ? AND meeting_date = ? AND label = ?', [$c['id'], $date, $label]);
        $marks = $sid ? Lms::sessionMarks($sid) : [];
        $topic = $sid ? (string) Db::val('SELECT topic FROM lms_attendance_sessions WHERE id = ?', [$sid]) : ''; ?>
<div class="lms-two">
  <form method="post" class="lms-panel"><?= csrf_field() ?><input type="hidden" name="action" value="attendance">
    <h2>Take attendance</h2>
    <div class="row g-2 mb-2"><div class="col-6 col-md-4"><label class="form-label small" for="aD">Date</label><input class="form-control" type="date" id="aD" name="date" value="<?= h($date) ?>" max="<?= date('Y-m-d') ?>" data-reload-param="date"></div>
      <div class="col-6 col-md-4"><label class="form-label small" for="aL">Meeting</label><input class="form-control" id="aL" name="label" value="<?= h($label) ?>" list="aLs" maxlength="80" placeholder="e.g. 7:30–8:30 AM"><datalist id="aLs"><?php foreach ($labels as $l): ?><option value="<?= h($l) ?>"><?php endforeach; ?></datalist></div>
      <div class="col-md-4"><label class="form-label small" for="aT">Topic</label><input class="form-control" id="aT" name="topic" value="<?= h($topic) ?>" maxlength="200"></div></div>
    <div class="d-flex gap-2 mb-2"><button type="button" class="btn btn-sm btn-outline-primary" data-mark-all="PRESENT">All present</button><span class="lms-muted small align-self-center"><?= $sid ? 'Saved earlier — change and save again.' : 'Not taken yet for this meeting.' ?></span></div>
    <div class="table-responsive"><table class="table table-sm align-middle lms-att"><tbody>
    <?php foreach ($roster as $s): $cur = $marks[$s['id']]['status'] ?? ''; ?><tr><td><?= h($s['name']) ?></td><td class="text-nowrap"><?php foreach (Lms::ATTENDANCE as $k => $v): ?><label class="lms-att-btn is-<?= strtolower($k) ?>"><input type="radio" name="mark[<?= h($s['id']) ?>]" value="<?= $k ?>"<?= $cur === $k ? ' checked' : '' ?> required><span><?= $v ?></span></label><?php endforeach; ?></td>
      <td><input class="form-control form-control-sm" name="note[<?= h($s['id']) ?>]" value="<?= h((string) ($marks[$s['id']]['note'] ?? '')) ?>" maxlength="200" placeholder="Note" aria-label="Note for <?= h($s['name']) ?>"></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($roster): ?><button class="btn btn-primary">Save attendance</button><?php else: ?><p class="lms-muted">No students on the class list.</p><?php endif; ?>
  </form>
  <div>
    <section class="lms-panel"><h2>Summary</h2><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Student</th><th class="text-end">P</th><th class="text-end">L</th><th class="text-end">A</th><th class="text-end">E</th><th class="text-end">Rate</th></tr></thead><tbody>
      <?php foreach ($roster as $s): $x = $sum[$s['id']] ?? null; ?><tr><td><?= h($s['name']) ?></td><td class="text-end"><?= $x['present'] ?? 0 ?></td><td class="text-end"><?= $x['late'] ?? 0 ?></td><td class="text-end <?= ($x['absent'] ?? 0) ? 'text-danger fw-semibold' : '' ?>"><?= $x['absent'] ?? 0 ?></td><td class="text-end"><?= $x['excused'] ?? 0 ?></td><td class="text-end"><?= isset($x['rate']) ? lms_pts($x['rate']) . '%' : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <section class="lms-panel"><h2>Meetings</h2><?php foreach ($sessions as $s): ?><a class="lms-row" href="<?= url('class', ['id' => $c['id'], 'tab' => 'attendance', 'date' => $s['meeting_date'], 'label' => $s['label']]) ?>"><span><?= h(date('D, M j', strtotime($s['meeting_date']))) ?> <?= h($s['label']) ?><?= $s['topic'] ? ' · ' . h($s['topic']) : '' ?></span><small><?= (int) $s['present'] ?> P · <?= (int) $s['late'] ?> L · <?= (int) $s['absent'] ?> A</small></a><?php endforeach; ?>
      <?php if (!$sessions): ?><p class="lms-muted mb-0">No attendance taken yet.</p><?php endif; ?></section>
  </div>
</div>
<?php else: $mine = Lms::myAttendance($c); $counts = array_count_values(array_filter(array_column($mine, 'status'))); ?>
<section class="lms-panel"><h2>My attendance</h2>
  <div class="lms-att-sum"><?php foreach (Lms::ATTENDANCE as $k => $v): ?><span class="is-<?= strtolower($k) ?>"><b><?= (int) ($counts[$k] ?? 0) ?></b><?= $v ?></span><?php endforeach; ?></div>
  <div class="table-responsive"><table class="table table-sm mb-0"><tbody><?php foreach ($mine as $m): ?><tr><td><?= h(date('D, M j', strtotime($m['meeting_date']))) ?> <?= h($m['label']) ?></td><td><?= h((string) $m['topic']) ?></td><td><?= $m['status'] ? '<span class="lms-pill is-att-' . strtolower($m['status']) . '">' . h(Lms::ATTENDANCE[$m['status']]) . '</span>' : '—' ?></td><td class="small"><?= h((string) $m['note']) ?></td></tr><?php endforeach; ?>
  <?php if (!$mine): ?><tr><td class="lms-muted">No attendance recorded yet.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>

<?php elseif ($tab === 'grades'): $g = Lms::gradebook($c); $scaleText = ['percent' => 'percent (0–100)', 'point1' => '1.00–5.00', 'point5' => '5.00–1.00'][$g['scale']] ?? $g['scale']; ?>
<?php if ($teach): ?>
<section class="lms-panel">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2"><div><h2 class="mb-0">Gradebook</h2><p class="lms-muted small mb-0">Weights: <?= h(implode(' · ', array_map(fn($k, $v) => $k . ' ' . lms_pts($v) . '%', array_keys($g['weights']), $g['weights']))) ?>. <?= $g['count_missing'] ? 'Missing past-due work counts as 0.' : 'Missing work is left out.' ?> The grade column is on the Grading System's scale (<?= h($scaleText) ?>).</p></div>
    <form method="post" data-confirm="Copy these grades into the Grading System as drafts? Submitted grades there are not changed; you still review and submit there."><?= csrf_field() ?><input type="hidden" name="action" value="send"><button class="btn btn-primary"><i class="bi bi-send me-1"></i>Send to Grading System (draft)</button></form></div>
  <div class="table-responsive lms-gradebook"><table class="table table-sm align-middle mb-0"><thead><tr><th class="sticky">Student</th>
    <?php foreach ($g['works'] as $w): ?><th class="text-center"><a href="<?= url('work', ['id' => $c['id'], 'w' => $w['id']]) ?>" title="<?= h($w['title']) ?>"><?= h(mb_strimwidth($w['title'], 0, 18, '…')) ?></a><small class="d-block"><?= h($w['category']) ?> · <?= lms_pts($w['points']) ?></small></th><?php endforeach; ?>
    <?php foreach ($g['weights'] as $cat => $_): ?><th class="text-end"><?= h($cat) ?></th><?php endforeach; ?><th class="text-end">Class work %</th><th class="text-end">Grade</th></tr></thead><tbody>
  <?php foreach ($g['rows'] as $r): ?><tr><td class="sticky"><?= h($r['name']) ?></td>
    <?php foreach ($g['works'] as $w): $cell = $r['cells'][$w['id']]; ?><td class="text-center <?= $cell['missing'] ? 'lms-miss' : '' ?>"><?= $cell['score'] !== null ? lms_pts($cell['score']) : ($cell['missing'] ? 'Missing' : ($cell['status'] === 'SUBMITTED' ? '<span class="lms-pill is-late">To grade</span>' : '—')) ?></td><?php endforeach; ?>
    <?php foreach ($g['weights'] as $cat => $_): ?><td class="text-end text-muted"><?= $r['categories'][$cat] === null ? '—' : lms_pts($r['categories'][$cat]) ?></td><?php endforeach; ?>
    <td class="text-end fw-semibold"><?= $r['percent'] === null ? '—' : lms_pts($r['percent']) ?></td><td class="text-end fw-bold"><?= $r['grade'] === null ? '—' : number_format($r['grade'], 2) ?></td></tr>
  <?php endforeach; if (!$g['rows']): ?><tr><td colspan="99" class="lms-muted text-center">No students on the class list.</td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="lms-muted small mt-2 mb-0">The official final grade stays in the Grading System: after sending, open the class there, check the drafts, then submit them for the registrar.</p>
</section>
<?php else: $me = $g['rows'][Lms::me()['student']] ?? null; ?>
<section class="lms-panel"><h2>My grades</h2>
  <?php if ($me): ?><div class="lms-mygrade"><span>Class work<b><?= $me['percent'] === null ? '—' : lms_pts($me['percent']) . '%' ?></b></span><?php foreach ($g['weights'] as $cat => $wgt): ?><span><?= h($cat) ?> (<?= lms_pts($wgt) ?>%)<b><?= $me['categories'][$cat] === null ? '—' : lms_pts($me['categories'][$cat]) . '%' ?></b></span><?php endforeach; ?></div>
  <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Work</th><th>Category</th><th>Due</th><th class="text-end">Score</th></tr></thead><tbody>
  <?php foreach ($g['works'] as $w): $cell = $me['cells'][$w['id']]; ?><tr><td><a href="<?= url('work', ['id' => $c['id'], 'w' => $w['id']]) ?>"><?= h($w['title']) ?></a></td><td><?= h($w['category']) ?></td><td><?= h(lms_when($w['due_at'])) ?></td>
    <td class="text-end"><?= $cell['score'] !== null && $cell['status'] === 'RETURNED' ? '<b>' . lms_pts($cell['score']) . '</b>/' . lms_pts($w['points']) : ($cell['missing'] ? '<span class="lms-pill is-missing">Missing</span>' : ($cell['status'] === 'SUBMITTED' ? 'Turned in' : '—')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="lms-muted small mt-2 mb-0">This is your class-work grade. Your official grade is given by your teacher in the Grading System and appears in the Student Portal.</p>
  <?php else: ?><p class="lms-muted mb-0">No grades yet.</p><?php endif; ?></section>
<?php endif; ?>

<?php else: /* settings */ ?>
<form method="post" class="lms-panel" style="max-width:720px"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <h2>Grading categories</h2><p class="lms-muted small">Each piece of classwork belongs to one category. The weights must add up to 100%.</p>
  <?php $i = 0; foreach ($set['weights'] + ['' => ''] + ['  ' => ''] as $cat => $wgt): ?><div class="row g-2 mb-1"><div class="col-8"><input class="form-control form-control-sm" name="cat[<?= $i ?>]" value="<?= h(trim((string) $cat)) ?>" placeholder="Category, e.g. Recitation" aria-label="Category name"></div><div class="col-4"><div class="input-group input-group-sm"><input class="form-control" name="weight[<?= $i ?>]" value="<?= $wgt === '' ? '' : lms_pts($wgt) ?>" inputmode="decimal" aria-label="Weight"><span class="input-group-text">%</span></div></div></div><?php $i++; endforeach; ?>
  <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="count_missing" value="1"<?= $set['count_missing'] ? ' checked' : '' ?>> Count missing past-due work as 0</label>
  <label class="form-label small mt-3" for="sD">About this class (shown on the Stream)</label><textarea class="form-control mb-2" id="sD" name="description" rows="3" maxlength="1000"><?= h($set['description']) ?></textarea>
  <button class="btn btn-primary">Save settings</button>
</form>
<?php endif; ?>
<?php layout_end();
