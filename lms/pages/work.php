<?php
/**
 * One piece of classwork. Students: read it, hand in files / a typed answer / a link, or take the quiz (timed if set).
 * Teachers: edit it, build quiz questions, see every student's work, score with feedback and return it.
 */
declare(strict_types=1);
/** @var bool $isPost */
$c = Lms::classFor((string) ($_GET['id'] ?? ''));
$teach = in_array($c['role'], ['TEACHER', 'ADMIN'], true);
$wid = (int) ($_GET['w'] ?? 0);
$w = Lms::work($c, $wid);
$keep = ['id' => $c['id'], 'w' => $wid];
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    switch ($a) {
        case 'save': case 'turn_in':
            Lms::submit($c, $wid, $_POST, Lms::uploads($_FILES['files'] ?? null), $a === 'turn_in');
            flash('success', $a === 'turn_in' ? 'Turned in.' : 'Saved. Turn it in when you are ready.'); break;
        case 'unsubmit': Lms::unsubmit($c, $wid); flash('success', 'Unsubmitted. Change your work and turn it in again.'); break;
        case 'remove_file': Lms::removeMyFile($c, (int) ($_POST['file'] ?? 0)); break;
        case 'start': Lms::startQuiz($c, $wid); break;
        case 'quiz':
            $r = Lms::submitQuiz($c, $wid, (array) ($_POST['q'] ?? []));
            flash('success', $r['released'] ? 'Turned in. Your score: ' . lms_pts($r['score']) . ' / ' . lms_pts($r['points']) . '.' : 'Turned in. Your teacher will check it and return your score.'); break;
        case 'edit':
            Lms::saveWork($c, $wid, $_POST + ['kind' => $w['kind']], Lms::uploads($_FILES['files'] ?? null)); flash('success', 'Saved.'); break;
        case 'delete': Lms::deleteWork($c, $wid); flash('success', 'Deleted.'); redirect('class', ['id' => $c['id'], 'tab' => 'classwork']);
        case 'questions': Lms::saveQuestions($c, $wid, (array) ($_POST['questions'] ?? [])); flash('success', 'Questions saved.'); redirect('work', $keep + ['edit' => 'questions']);
        case 'grade':
            $sid = (string) ($_POST['student'] ?? '');
            if ($w['kind'] === 'QUIZ') Lms::scoreQuestions($c, $wid, $sid, (array) ($_POST['qscore'] ?? []), (string) ($_POST['feedback'] ?? ''), !empty($_POST['return']));
            else Lms::grade($c, $wid, $sid, (string) ($_POST['score'] ?? ''), (string) ($_POST['feedback'] ?? ''), !empty($_POST['return']));
            flash('success', !empty($_POST['return']) ? 'Returned to the student.' : 'Score saved (not returned yet).');
            redirect('work', $keep + ['view' => 'students', 's' => $sid]);
        default: throw new AppError('Unknown action.');
    }
    redirect('work', $keep);
}
$qs = $w['kind'] === 'QUIZ' ? Lms::questions($wid) : [];
$pts = lms_pts($w['points']);
layout_start($w['title']);
lms_class_header($c, 'classwork');
?>
<div class="lms-work-head"><span class="lms-item-icon<?= $w['kind'] === 'QUIZ' ? ' quiz' : '' ?>"><i class="bi bi-<?= $w['kind'] === 'QUIZ' ? 'ui-checks' : 'journal-text' ?>" aria-hidden="true"></i></span>
  <div><h1><?= h($w['title']) ?></h1><p class="lms-muted mb-0"><?= h($w['kind'] === 'QUIZ' ? 'Quiz / exam' : $w['category']) ?> · <?= $pts ?> points<?= $w['due_at'] ? ' · Due ' . h(lms_when($w['due_at'])) : ' · No due date' ?><?= $w['time_limit_min'] ? ' · ' . (int) $w['time_limit_min'] . ' minutes' : '' ?><?= $w['status'] === 'DRAFT' ? ' · <b>Draft (students don\'t see it)</b>' : '' ?></p></div>
  <a class="btn btn-outline-secondary ms-auto" href="<?= url('class', ['id' => $c['id'], 'tab' => 'classwork']) ?>"><i class="bi bi-arrow-left me-1"></i>Classwork</a></div>

<?php if ($teach):
    $view = in_array($_GET['view'] ?? '', ['students', 'edit'], true) ? $_GET['view'] : (($_GET['edit'] ?? '') === 'questions' ? 'questions' : 'students');
    if (($_GET['edit'] ?? '') === 'questions') $view = 'questions'; ?>
<nav class="lms-subtabs"><a href="<?= url('work', $keep + ['view' => 'students']) ?>"<?= $view === 'students' ? ' class="active"' : '' ?>>Student work</a><?php if ($w['kind'] === 'QUIZ'): ?><a href="<?= url('work', $keep + ['edit' => 'questions']) ?>"<?= $view === 'questions' ? ' class="active"' : '' ?>>Questions (<?= count($qs) ?>)</a><?php endif; ?><a href="<?= url('work', $keep + ['view' => 'edit']) ?>"<?= $view === 'edit' ? ' class="active"' : '' ?>>Instructions &amp; settings</a></nav>
<?php if ($view === 'questions'):
    $locked = (bool) Db::val("SELECT 1 FROM lms_submissions WHERE work_id = ? AND (status <> 'DRAFT' OR started_at IS NOT NULL)", [$wid]); ?>
<form method="post" class="lms-qbuilder" data-qbuilder><?= csrf_field() ?><input type="hidden" name="action" value="questions">
  <?php if ($locked): ?><div class="alert alert-info">Students have started this quiz, so the questions are locked.</div><?php endif; ?>
  <div data-qlist>
  <?php foreach ($qs ?: [['qtype' => 'MC', 'prompt' => '', 'options' => ['', '', '', ''], 'answer' => null, 'points' => 1]] as $i => $q): ?>
    <fieldset class="lms-panel lms-q" data-q<?= $locked ? ' disabled' : '' ?>>
      <div class="lms-q-head"><b data-qno><?= $i + 1 ?></b>
        <select class="form-select form-select-sm" name="questions[<?= $i ?>][type]" data-qtype aria-label="Question type"><?php foreach (Lms::QTYPES as $k => $v): ?><option value="<?= $k ?>"<?= $q['qtype'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
        <div class="input-group input-group-sm lms-q-pts"><input class="form-control" name="questions[<?= $i ?>][points]" value="<?= lms_pts($q['points']) ?>" inputmode="decimal" aria-label="Points"><span class="input-group-text">pts</span></div>
        <button type="button" class="lms-x" data-qdel aria-label="Remove question"><i class="bi bi-trash3"></i></button></div>
      <textarea class="form-control mb-2" name="questions[<?= $i ?>][prompt]" rows="2" placeholder="Question" aria-label="Question"><?= h($q['prompt']) ?></textarea>
      <div data-for="MC CHECK"><p class="small lms-muted mb-1">Choices — mark the correct one(s)</p><div data-opts>
        <?php foreach (($q['qtype'] === 'MC' || $q['qtype'] === 'CHECK') ? $q['options'] : ['', '', '', ''] as $k => $o): $ok = $q['qtype'] === 'MC' ? $q['answer'] === $k : in_array($k, (array) $q['answer'], true); ?>
        <div class="lms-opt"><input class="form-check-input" type="checkbox" name="questions[<?= $i ?>][answer][]" value="<?= $k ?>"<?= $ok ? ' checked' : '' ?> aria-label="Correct"><input class="form-control form-control-sm" name="questions[<?= $i ?>][options][]" value="<?= h($o) ?>" placeholder="Choice <?= $k + 1 ?>" aria-label="Choice <?= $k + 1 ?>"></div>
        <?php endforeach; ?></div><button type="button" class="btn btn-sm btn-link px-0" data-addopt>+ Add choice</button></div>
      <div data-for="TF"><label class="form-check form-check-inline"><input class="form-check-input" type="radio" name="questions[<?= $i ?>][tf]" value="0"<?= $q['qtype'] === 'TF' && $q['answer'] === 0 ? ' checked' : '' ?>> True is correct</label><label class="form-check form-check-inline"><input class="form-check-input" type="radio" name="questions[<?= $i ?>][tf]" value="1"<?= $q['qtype'] === 'TF' && $q['answer'] === 1 ? ' checked' : '' ?>> False is correct</label></div>
      <div data-for="SHORT"><label class="form-label small">Accepted answers (one per line; capitals and extra spaces are ignored)</label><textarea class="form-control form-control-sm" name="questions[<?= $i ?>][accepted]" rows="2"><?= h($q['qtype'] === 'SHORT' ? implode("\n", (array) $q['answer']) : '') ?></textarea></div>
      <div data-for="ESSAY"><p class="small lms-muted mb-0">Students write an answer; you score it when you check the quiz.</p></div>
    </fieldset>
  <?php endforeach; ?>
  </div>
  <?php if (!$locked): ?><div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-outline-primary" data-qadd><i class="bi bi-plus-lg me-1"></i>Add question</button><button class="btn btn-primary">Save questions</button></div>
  <p class="lms-muted small mt-2">The quiz is worth the total of its question points. Multiple choice, checkboxes, true/false and short answers are scored automatically; essays wait for you.</p><?php endif; ?>
</form>
<?php elseif ($view === 'edit'): ?>
<form method="post" enctype="multipart/form-data" class="lms-panel"><?= csrf_field() ?><input type="hidden" name="action" value="edit">
  <div class="row g-2">
    <div class="col-md-8"><label class="form-label small" for="eT">Title</label><input class="form-control" id="eT" name="title" required maxlength="200" value="<?= h($w['title']) ?>"></div>
    <div class="col-md-4"><label class="form-label small" for="eC">Grading category</label><select class="form-select" id="eC" name="category"><?php foreach (Lms::settings($c['id'])['weights'] as $cat => $_): ?><option<?= $w['category'] === $cat ? ' selected' : '' ?>><?= h($cat) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><label class="form-label small" for="eI">Instructions</label><textarea class="form-control" id="eI" name="instructions" rows="4" maxlength="20000"><?= h((string) $w['instructions']) ?></textarea></div>
    <?php if ($w['kind'] === 'QUIZ'): ?><input type="hidden" name="points" value="<?= h((string) $w['points']) ?>"><div class="col-6 col-md-3"><label class="form-label small" for="eL">Time limit (minutes)</label><input class="form-control" id="eL" name="time_limit_min" value="<?= h((string) $w['time_limit_min']) ?>" inputmode="numeric"></div>
    <?php else: ?><div class="col-6 col-md-3"><label class="form-label small" for="eP">Points</label><input class="form-control" id="eP" name="points" value="<?= $pts ?>" inputmode="decimal"></div><?php endif; ?>
    <div class="col-6 col-md-4"><label class="form-label small" for="eD">Due</label><input class="form-control" type="datetime-local" id="eD" name="due_at" value="<?= $w['due_at'] ? h(date('Y-m-d\TH:i', strtotime($w['due_at']))) : '' ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="eTp">Topic</label><input class="form-control" id="eTp" name="topic" maxlength="80" value="<?= h((string) $w['topic']) ?>"></div>
    <div class="col-12 d-flex flex-wrap gap-3"><label class="form-check small"><input class="form-check-input" type="checkbox" name="allow_late" value="1"<?= (int) $w['allow_late'] ? ' checked' : '' ?>> Accept late work</label>
      <?php if ($w['kind'] === 'QUIZ'): ?><label class="form-check small"><input class="form-check-input" type="checkbox" name="show_results" value="1"<?= (int) $w['show_results'] ? ' checked' : '' ?>> Show the score right after turning in</label><?php else: ?><label class="form-check small"><input class="form-check-input" type="checkbox" name="allow_resubmit" value="1"<?= (int) $w['allow_resubmit'] ? ' checked' : '' ?>> Allow resubmitting</label><?php endif; ?>
      <label class="form-check small"><input class="form-check-input" type="checkbox" name="status" value="DRAFT"<?= $w['status'] === 'DRAFT' ? ' checked' : '' ?>> Draft (hidden from students)</label></div>
    <div class="col-12"><?= lms_files_html($w['files']) ?><label class="form-label small" for="eF">Add files</label><input class="form-control" type="file" data-max-mb="10" id="eF" name="files[]" multiple></div>
  </div>
  <div class="d-flex gap-2 mt-2"><button class="btn btn-primary">Save</button></div></form>
<form method="post" data-confirm="Delete this classwork?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
<?php else: $subs = Lms::submissions($c, $wid); $sel = (string) ($_GET['s'] ?? ''); $one = null; foreach ($subs as $x) if ($x['id'] === $sel) $one = $x;
    $counts = ['turned' => 0, 'assigned' => 0, 'returned' => 0]; foreach ($subs as $x) { $st = $x['sub']['status'] ?? 'NONE'; $counts[$st === 'SUBMITTED' ? 'turned' : ($st === 'RETURNED' ? 'returned' : 'assigned')]++; } ?>
<div class="lms-counts"><span><b><?= $counts['turned'] ?></b>Turned in</span><span><b><?= $counts['assigned'] ?></b>Assigned</span><span><b><?= $counts['returned'] ?></b>Returned</span></div>
<div class="lms-grading">
  <section class="lms-panel lms-students"><?php foreach ($subs as $x): $state = Lms::state($w, $x['sub']); ?>
    <a href="<?= url('work', $keep + ['view' => 'students', 's' => $x['id']]) ?>"<?= $x['id'] === $sel ? ' class="active"' : '' ?>><span class="lms-avatar sm" style="--h:<?= lms_hue($x['name']) ?>"><?= h(mb_strtoupper(mb_substr($x['name'], 0, 1))) ?></span><span><?= h($x['name']) ?><small><?= lms_state_pill($state) ?></small></span><b><?= $x['sub'] && $x['sub']['score'] !== null ? lms_pts($x['sub']['score']) . '/' . $pts : '' ?></b></a>
  <?php endforeach; if (!$subs): ?><p class="lms-muted mb-0">No students on the class list.</p><?php endif; ?></section>
  <section class="lms-panel">
  <?php if (!$one): ?><p class="lms-muted mb-0">Choose a student to see and grade their work.</p>
  <?php else: $s = $one['sub']; ?>
    <h2><?= h($one['name']) ?> <small class="lms-muted"><?= h($one['no']) ?></small></h2>
    <p><?= lms_state_pill(Lms::state($w, $s)) ?><?= $s && $s['submitted_at'] ? ' <span class="lms-muted small">Turned in ' . h(lms_when($s['submitted_at'])) . '</span>' : '' ?></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="grade"><input type="hidden" name="student" value="<?= h($one['id']) ?>">
    <?php if ($w['kind'] === 'QUIZ'): $ans = json_decode((string) ($s['answers'] ?? ''), true) ?: []; $qsc = json_decode((string) ($s['question_scores'] ?? ''), true) ?: []; ?>
      <?php if (!$s || $s['status'] === 'DRAFT'): ?><p class="lms-muted">Not turned in yet.</p><?php else: foreach ($qs as $i => $q): $given = $ans[$q['id']] ?? null; $sc = $qsc[$q['id']] ?? null; ?>
      <div class="lms-answer"><div class="d-flex justify-content-between gap-2"><b><?= $i + 1 ?>. <?= nl2br(h($q['prompt'])) ?></b><div class="input-group input-group-sm" style="width:130px"><input class="form-control" name="qscore[<?= (int) $q['id'] ?>]" value="<?= $sc === null ? '' : lms_pts($sc) ?>" inputmode="decimal" aria-label="Score for question <?= $i + 1 ?>"<?= $q['qtype'] === 'ESSAY' && $sc === null ? ' required' : '' ?>><span class="input-group-text">/<?= lms_pts($q['points']) ?></span></div></div>
        <?php if (in_array($q['qtype'], ['MC', 'TF', 'CHECK'], true)): $pick = (array) $given; ?><ul class="lms-choices"><?php foreach ($q['options'] as $k => $o): $right = $q['qtype'] === 'CHECK' ? in_array($k, (array) $q['answer'], true) : $k === $q['answer']; $chosen = in_array((string) $k, array_map('strval', $pick), true); ?><li class="<?= $right ? 'ok' : '' ?> <?= $chosen ? 'chosen' : '' ?>"><?= $chosen ? '<i class="bi bi-record-circle"></i>' : '<i class="bi bi-circle"></i>' ?> <?= h($o) ?><?= $right ? ' <i class="bi bi-check-lg"></i>' : '' ?></li><?php endforeach; ?></ul>
        <?php else: ?><div class="lms-text lms-given"><?= $given !== null && $given !== '' ? nl2br(h((string) $given)) : '<i class="lms-muted">No answer</i>' ?></div><?= $q['qtype'] === 'SHORT' ? '<small class="lms-muted">Accepted: ' . h(implode(' / ', (array) $q['answer'])) . '</small>' : '' ?><?php endif; ?></div>
      <?php endforeach; endif; ?>
    <?php else: ?>
      <?php if ($s): ?><?= lms_text($s['text_answer']) ?><?php if ($s['link_url']): ?><a class="lms-link" href="<?= h($s['link_url']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-link-45deg"></i><?= h($s['link_url']) ?></a><?php endif; ?><?= lms_files_html($s['files']) ?><?php endif; ?>
      <?php if (!$s || $s['status'] === 'DRAFT'): ?><p class="lms-muted">Nothing turned in yet. You can still give a score (e.g. for work handed in on paper).</p><?php endif; ?>
      <label class="form-label small mt-2" for="gS">Score</label><div class="input-group mb-2" style="max-width:200px"><input class="form-control" id="gS" name="score" value="<?= $s && $s['score'] !== null ? lms_pts($s['score']) : '' ?>" inputmode="decimal"><span class="input-group-text">/ <?= $pts ?></span></div>
    <?php endif; ?>
      <label class="form-label small" for="gF">Feedback for the student</label><textarea class="form-control mb-2" id="gF" name="feedback" rows="3" maxlength="10000"><?= h((string) ($s['feedback'] ?? '')) ?></textarea>
      <div class="d-flex gap-2"><button class="btn btn-outline-primary">Save score</button><button class="btn btn-primary" name="return" value="1">Return to student</button></div>
    </form>
  <?php endif; ?></section>
</div>
<?php endif; ?>

<?php else: /* student */
    $s = Db::one('SELECT * FROM lms_submissions WHERE work_id = ? AND student_doc_id = ?', [$wid, Lms::me()['student']]);
    $state = Lms::state($w, $s); $files = $s ? Lms::files('SUBMISSION', (int) $s['id']) : []; ?>
<div class="lms-two">
  <section class="lms-panel"><?= lms_text($w['instructions']) ?: '<p class="lms-muted">No instructions.</p>' ?><?= lms_files_html($w['files']) ?></section>
  <aside class="lms-panel lms-yourwork"><div class="d-flex justify-content-between align-items-center"><h2>Your work</h2><?= lms_state_pill($state) ?></div>
  <?php if ($s && $s['status'] === 'RETURNED'): ?><div class="lms-score"><b><?= $s['score'] === null ? '—' : lms_pts($s['score']) ?></b>/<?= $pts ?></div><?php if ($s['feedback']): ?><div class="lms-feedback"><small>Feedback</small><?= nl2br(h($s['feedback'])) ?></div><?php endif; ?><?php endif; ?>
  <?php if ($w['kind'] === 'QUIZ'):
      $left = Lms::secondsLeft($w, $s); ?>
    <?php if (!$s || ($s['status'] === 'DRAFT' && !$s['started_at'])): ?>
      <p><?= count($qs) ?> question(s) · <?= $pts ?> points<?= $w['time_limit_min'] ? ' · ' . (int) $w['time_limit_min'] . ' minutes once you start' : '' ?>. You can take it once.</p>
      <?php if ($qs): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="btn btn-primary w-100">Start quiz</button></form><?php else: ?><p class="lms-muted">The quiz is not ready yet.</p><?php endif; ?>
    <?php elseif ($s['status'] === 'SUBMITTED'): ?><p>Turned in <?= h(lms_when($s['submitted_at'])) ?>. Your teacher will check it and return your score.</p>
    <?php elseif ($s['status'] === 'RETURNED'): ?><p class="small lms-muted mb-0">Turned in <?= h(lms_when($s['submitted_at'])) ?>.</p><?php endif; ?>
  <?php else: ?>
    <?= $files ? lms_files_html($files, $s['status'] !== 'SUBMITTED', $c['id'], $wid) : '' ?>
    <?php if ($s && $s['status'] === 'SUBMITTED'): ?><?= lms_text($s['text_answer']) ?><?php if ($s['link_url']): ?><a class="lms-link" href="<?= h($s['link_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['link_url']) ?></a><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unsubmit"><button class="btn btn-outline-secondary w-100 mt-2">Unsubmit</button></form>
    <?php elseif (!$s || $s['status'] === 'DRAFT' || (int) $w['allow_resubmit']): ?>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
        <label class="form-label small" for="yF">Add files (up to 10 MB each)</label><input class="form-control form-control-sm mb-2" type="file" data-max-mb="10" id="yF" name="files[]" multiple>
        <label class="form-label small" for="yT">Answer (optional)</label><textarea class="form-control form-control-sm mb-2" id="yT" name="text_answer" rows="4" maxlength="50000"><?= h((string) ($s['text_answer'] ?? '')) ?></textarea>
        <label class="form-label small" for="yL">Link (optional)</label><input class="form-control form-control-sm mb-2" id="yL" name="link_url" maxlength="500" value="<?= h((string) ($s['link_url'] ?? '')) ?>" placeholder="https://">
        <div class="d-grid gap-2"><button class="btn btn-primary" name="action" value="turn_in"><?= $s && $s['status'] === 'RETURNED' ? 'Resubmit' : 'Turn in' ?></button><button class="btn btn-outline-secondary" name="action" value="save">Save without turning in</button></div>
        <?php if ($w['due_at'] && strtotime($w['due_at']) < time()): ?><p class="small text-danger mt-2 mb-0"><?= (int) $w['allow_late'] ? 'The due date has passed: it will be marked late.' : 'The due date has passed; late work is not accepted.' ?></p><?php endif; ?>
      </form>
    <?php endif; ?>
  <?php endif; ?>
  </aside>
</div>
<?php if ($w['kind'] === 'QUIZ' && $s && $s['status'] === 'DRAFT' && $s['started_at']): ?>
<form method="post" class="lms-quiz" data-quiz<?= $left !== null ? ' data-seconds-left="' . (int) $left . '"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="quiz">
  <?php if ($left !== null): ?><div class="lms-timer" data-timer role="timer" aria-live="polite">Time left <b>--:--</b></div><?php endif; ?>
  <?php foreach ($qs as $i => $q): ?><fieldset class="lms-panel lms-take"><legend><b><?= $i + 1 ?>.</b> <?= nl2br(h($q['prompt'])) ?> <small class="lms-muted">(<?= lms_pts($q['points']) ?> pt<?= (float) $q['points'] === 1.0 ? '' : 's' ?>)</small></legend>
    <?php if (in_array($q['qtype'], ['MC', 'TF'], true)): foreach ($q['options'] as $k => $o): ?><label class="lms-choice"><input type="radio" name="q[<?= (int) $q['id'] ?>]" value="<?= $k ?>"> <?= h($o) ?></label><?php endforeach;
    elseif ($q['qtype'] === 'CHECK'): foreach ($q['options'] as $k => $o): ?><label class="lms-choice"><input type="checkbox" name="q[<?= (int) $q['id'] ?>][]" value="<?= $k ?>"> <?= h($o) ?></label><?php endforeach;
    elseif ($q['qtype'] === 'SHORT'): ?><input class="form-control" name="q[<?= (int) $q['id'] ?>]" maxlength="500" aria-label="Your answer" autocomplete="off">
    <?php else: ?><textarea class="form-control" name="q[<?= (int) $q['id'] ?>]" rows="5" maxlength="20000" aria-label="Your answer"></textarea><?php endif; ?>
  </fieldset><?php endforeach; ?>
  <button class="btn btn-primary btn-lg" data-confirm-quiz>Turn in quiz</button>
</form>
<?php elseif ($w['kind'] === 'QUIZ' && $s && $s['status'] === 'RETURNED' && (int) $w['show_results']): $ans = json_decode((string) $s['answers'], true) ?: []; $qsc = json_decode((string) $s['question_scores'], true) ?: []; ?>
<section class="lms-panel"><h2>Your answers</h2>
  <?php foreach ($qs as $i => $q): $given = $ans[$q['id']] ?? null; $sc = $qsc[$q['id']] ?? null; $full = $sc !== null && (float) $sc >= (float) $q['points']; ?>
  <div class="lms-answer <?= $full ? 'is-right' : 'is-wrong' ?>"><div class="d-flex justify-content-between"><b><?= $i + 1 ?>. <?= nl2br(h($q['prompt'])) ?></b><span><?= $sc === null ? '—' : lms_pts($sc) ?>/<?= lms_pts($q['points']) ?></span></div>
    <?php if (in_array($q['qtype'], ['MC', 'TF', 'CHECK'], true)): $pick = array_map('strval', (array) $given); ?><ul class="lms-choices"><?php foreach ($q['options'] as $k => $o): $right = $q['qtype'] === 'CHECK' ? in_array($k, (array) $q['answer'], true) : $k === $q['answer']; ?><li class="<?= $right ? 'ok' : '' ?> <?= in_array((string) $k, $pick, true) ? 'chosen' : '' ?>"><?= in_array((string) $k, $pick, true) ? '<i class="bi bi-record-circle"></i>' : '<i class="bi bi-circle"></i>' ?> <?= h($o) ?><?= $right ? ' <i class="bi bi-check-lg"></i>' : '' ?></li><?php endforeach; ?></ul>
    <?php else: ?><div class="lms-text lms-given"><?= $given !== null && $given !== '' ? nl2br(h((string) $given)) : '<i class="lms-muted">No answer</i>' ?></div><?php endif; ?></div>
  <?php endforeach; ?></section>
<?php endif; ?>
<?php endif; ?>
<?php layout_end();
