<?php
declare(strict_types=1);
/**
 * Online Clearance (v55). Teachers (from the Grading System) and office staff sign the lines they own;
 * the registrar opens clearance per term and gives the final approval; viewers see every student's clearance.
 */
/** @var bool $isPost */
$me = (int) Auth::centralId();
if (!Clearance::on()) {
    layout_start('Online Clearance');
    page_title('Online Clearance');
    echo '<div class="alert alert-info"><i class="bi bi-toggle-off me-1"></i>' . h(Clearance::offMessage()) . '</div>';
    layout_end();
    return;
}
if (!Clearance::canView($me)) throw new Forbidden('You do not sign or review student clearance.');
$power = Clearance::isPower($me);
$canAll = $power || Auth::can('clearance');
$manage = $power || Auth::can('clearance.manage');
$teacher = Auth::central()->group($me) === 'TEACHER';

if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    $back = $_SERVER['REQUEST_URI'] ?? url('clearance');
    if ($a === 'period') {
        Clearance::savePeriod($_POST);
        flash('success', 'Clearance period saved.');
        redirect('clearance', ['tab' => 'periods']);
    } elseif ($a === 'tag') {
        Clearance::tag((int) ($_POST['reg'] ?? 0), (string) ($_POST['key'] ?? ''), (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''));
        flash('success', ($_POST['status'] ?? '') === 'CLEARED' ? 'Signed as cleared.' : 'Tagged as not cleared. The student was told what to settle.');
    } elseif ($a === 'signmany') {
        $res = Clearance::signMany((array) ($_POST['pick'] ?? []), (string) ($_POST['password'] ?? ''));
        flash($res['skipped'] ? 'warning' : 'success', $res['signed'] . ' signed as cleared.' . ($res['skipped'] ? ' Not signed: ' . implode(' · ', array_unique($res['skipped'])) : ''));
    } elseif ($a === 'undo') {
        Clearance::undo((int) ($_POST['tag'] ?? 0), (string) ($_POST['reason'] ?? ''));
        flash('success', 'Undone. The line is waiting again.');
    } elseif ($a === 'approve') {
        Clearance::approve((int) ($_POST['reg'] ?? 0));
        flash('success', 'Clearance approved as final, with your e-signature and a control number.');
    } elseif ($a === 'reply') {
        Clearance::reply((int) ($_POST['sub'] ?? 0), (string) ($_POST['reply'] ?? ''));
        flash('success', 'Reply sent to the student.');
    } else throw new AppError('Unknown action.');
    header('Location: ' . $back); exit;
}

$stateBadge = fn(string $s) => ['CLEARED' => '<span class="badge text-bg-success">Cleared</span>', 'NOT_CLEARED' => '<span class="badge text-bg-danger">Not cleared</span>', 'PENDING' => '<span class="badge text-bg-warning">Waiting</span>'][$s] ?? h($s);
$periods = Clearance::periods();
$grading = $teacher ? '<a class="btn btn-outline-secondary" href="../pages/dashboard.html"><i class="bi bi-arrow-left"></i> Grading System</a>' : '';

// ------------------------------------------------------------------ one student's clearance
if (isset($_GET['reg'])) {
    $r = Clearance::registration((int) $_GET['reg']);
    if (!$r['locked_at']) throw new AppError('Only confirmed enrollments have a clearance.');
    $st = Clearance::status($r);
    $mine = array_filter($st['items'], fn($i) => Clearance::mayTag($me, $i));
    if (!$canAll && !$mine) throw new Forbidden('You do not sign any line of this clearance.');
    foreach ($mine as $i) Clearance::markSeen((int) $r['id'], $i['key']);
    $subs = [];
    foreach (Clearance::submissions((int) $r['id']) as $s) $subs[$s['item_key']][] = $s;
    $open = Clearance::isOpen($st['period']);
    $finalItem = ['key' => 'FINAL', 'kind' => 'FINAL', 'office' => 'REGISTRAR', 'teacher_doc_id' => '', 'subject_code' => ''];
    layout_start('Clearance · ' . $r['student_name']);
    page_title('Clearance · ' . $r['student_name'], $grading . ' <a class="btn btn-outline-secondary" href="' . url('clearance', ['sy' => $r['school_year'], 'term' => $r['term']]) . '"><i class="bi bi-list-check"></i> Clearance list</a> <a class="btn btn-outline-primary" href="' . url('clearanceslip', ['reg' => $r['id']]) . '" target="_blank"><i class="bi bi-printer"></i> Clearance slip</a>',
        h($r['student_number'] . ' · ' . $r['program'] . ' ' . $r['year_level'] . ' · ' . $r['section_name'] . ' · ' . $r['school_year'] . ' ' . $r['term']));
    ?>
<div data-record-detail>
<?php if ($st['final']): ?><div class="alert alert-success"><i class="bi bi-patch-check-fill me-1"></i><strong>Clearance approved</strong> by <?= h($st['final']['signer_name']) ?> on <?= h(substr($st['final']['signed_at'], 0, 16)) ?> · control no. <?= h((string) $st['final']['control_no']) ?></div>
<?php elseif ($st['complete']): ?><div class="alert alert-success"><i class="bi bi-check2-circle me-1"></i><strong>Clearance completed.</strong> Every line is signed; the registrar can approve it as final.</div>
<?php else: ?><div class="alert alert-warning"><i class="bi bi-hourglass-split me-1"></i><?= $st['counts']['CLEARED'] ?> of <?= count($st['items']) ?> cleared<?= $st['counts']['NOT_CLEARED'] ? ' · ' . $st['counts']['NOT_CLEARED'] . ' not cleared' : '' ?>.<?= $open ? '' : ' Clearance for this term is not open now' . ($st['period'] ? ' (' . h(date('M j', strtotime($st['period']['opens_on']))) . '–' . h(date('M j, Y', strtotime($st['period']['closes_on']))) . (!(int) $st['period']['is_open'] ? ', closed' : '') . ')' : '') . '.' ?></div><?php endif; ?>
<div class="form-section"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Office / subject</th><th>Status</th><th>Signed by</th><th class="text-end">Action</th></tr></thead><tbody>
<?php foreach ($st['items'] as $it): $t = $it['tag']; $may = Clearance::mayTag($me, $it); ?>
  <tr><td><strong><?= h($it['label']) ?></strong><?php if ($it['kind'] === 'SUBJECT'): ?><div class="small text-muted"><?= h($it['teacher_name']) ?></div><?php endif; ?>
    <?php foreach ($subs[$it['key']] ?? [] as $s): ?><div class="clr-sub small border rounded p-2 mt-2"><div><i class="bi bi-chat-left-text me-1"></i><strong>Student</strong> · <?= h(substr($s['created_at'], 0, 16)) ?> <?= $s['status'] === 'NEW' ? '<span class="badge text-bg-primary">new</span>' : '' ?></div>
      <?php if ($s['message'] !== ''): ?><div style="white-space:pre-line"><?= h($s['message']) ?></div><?php endif; ?>
      <?php if ($s['file_name']): ?><a href="<?= url('clearancefile', ['id' => $s['id']]) ?>" target="_blank"><i class="bi bi-paperclip"></i><?= h($s['file_name']) ?></a> <span class="text-muted">(<?= number_format((int) $s['file_size'] / 1024, 0) ?> KB)</span><?php endif; ?>
      <?php if ($s['reply']): ?><div class="mt-1 text-success"><i class="bi bi-reply"></i> <?= h($s['reply']) ?></div>
      <?php elseif ($may): ?><form method="post" class="d-flex gap-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="sub" value="<?= (int) $s['id'] ?>"><input class="form-control form-control-sm" name="reply" maxlength="1000" required placeholder="Reply to the student" aria-label="Reply"><button class="btn btn-sm btn-outline-primary">Reply</button></form><?php endif; ?>
    </div><?php endforeach; ?></td>
    <td><?= $stateBadge($it['state']) ?><?php if ($t && $t['status'] === 'NOT_CLEARED'): ?><div class="small text-danger"><?= h($t['note']) ?></div><?php endif; ?></td>
    <td class="small"><?php if ($t): ?><?php if ($t['signature_mode'] === 'ESIGN'): ?><img src="<?= h(Clearance::signatureImage((int) $t['id'])) ?>" alt="" style="height:32px;display:block"><?php elseif ($t['signature_mode'] === 'APPROVED'): ?><span class="badge text-bg-success">Approved</span><?php endif; ?><?= h($t['signer_name']) ?><br><span class="text-muted"><?= h($t['signer_position']) ?> · <?= h(substr($t['signed_at'], 0, 16)) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
    <td class="text-end"><?php if ($may && $open && !$st['final'] && $it['state'] !== 'CLEARED'): ?>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="tag"><input type="hidden" name="reg" value="<?= (int) $r['id'] ?>"><input type="hidden" name="key" value="<?= h($it['key']) ?>"><input type="hidden" name="status" value="CLEARED"><button class="btn btn-sm btn-success"><i class="bi bi-pen"></i> Sign as cleared</button></form>
      <details class="d-inline-block text-start"><summary class="btn btn-sm btn-outline-danger">Not cleared</summary><form method="post" class="mt-1" style="min-width:16rem"><?= csrf_field() ?><input type="hidden" name="action" value="tag"><input type="hidden" name="reg" value="<?= (int) $r['id'] ?>"><input type="hidden" name="key" value="<?= h($it['key']) ?>"><input type="hidden" name="status" value="NOT_CLEARED"><input class="form-control form-control-sm mb-1" name="note" required minlength="3" maxlength="500" placeholder="What's missing" aria-label="What's missing" value="<?= h($t['note'] ?? '') ?>"><button class="btn btn-sm btn-danger">Save</button></form></details>
    <?php endif; ?>
    <?php if ($t && !$st['final'] && ((int) $t['signer_account_id'] === $me || $power)): ?><details class="d-inline-block text-start"><summary class="btn btn-sm btn-link">Undo</summary><form method="post" class="mt-1" style="min-width:14rem"><?= csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="tag" value="<?= (int) $t['id'] ?>"><input class="form-control form-control-sm mb-1" name="reason" required minlength="3" maxlength="255" placeholder="Reason" aria-label="Reason for undoing"><button class="btn btn-sm btn-outline-secondary">Undo</button></form></details><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php foreach ($st['retired'] as $t): ?><tr class="text-muted"><td><?= h($t['item_label']) ?> <span class="small">(no longer signs clearance)</span></td><td><?= $stateBadge('CLEARED') ?></td><td class="small"><?= h($t['signer_name']) ?> · <?= h(substr($t['signed_at'], 0, 16)) ?></td><td></td></tr><?php endforeach; ?>
<?php if (!$st['items']): ?><tr><td colspan="4" class="text-muted text-center py-3">No office signs clearance yet (Users and Groups → tick "This group signs student clearance").</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="form-section"><h2 class="h6">Registrar · final approval</h2>
<?php if ($st['final']): $t = $st['final']; ?>
  <p class="mb-1"><?php if ($t['signature_mode'] === 'ESIGN'): ?><img src="<?= h(Clearance::signatureImage((int) $t['id'])) ?>" alt="" style="height:40px"><?php else: ?><span class="badge text-bg-success">Approved</span><?php endif; ?> <?= h($t['signer_name']) ?> · <?= h(substr($t['signed_at'], 0, 16)) ?> · <?= h((string) $t['control_no']) ?></p>
  <?php if ((int) $t['signer_account_id'] === $me || $power || Clearance::mayTag($me, $finalItem)): ?><details><summary class="btn btn-sm btn-link px-0">Undo the final approval</summary><form method="post" class="d-flex gap-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="tag" value="<?= (int) $t['id'] ?>"><input class="form-control form-control-sm" name="reason" required minlength="3" maxlength="255" placeholder="Reason" aria-label="Reason"><button class="btn btn-sm btn-outline-secondary">Undo</button></form></details><?php endif; ?>
<?php elseif (Clearance::mayTag($me, $finalItem)): ?>
  <form method="post" data-confirm="Approve this clearance as final with your e-signature?"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="reg" value="<?= (int) $r['id'] ?>"><button class="btn btn-primary"<?= $st['complete'] && $open ? '' : ' disabled' ?>><i class="bi bi-patch-check"></i> Approve clearance (final)</button> <span class="small text-muted"><?= $st['complete'] ? ($open ? 'Adds your e-signature and a control number.' : 'Clearance for this term is not open.') : 'Available when every line is cleared.' ?></span></form>
<?php else: ?><p class="small text-muted mb-0">The registrar approves it after every line is cleared.</p><?php endif; ?>
</div>
</div>
<?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ lists
$tab = in_array($_GET['tab'] ?? '', ['overview', 'periods'], true) ? (string) $_GET['tab'] : 'sign';
if ($tab === 'periods' && !$manage) $tab = 'sign';
if ($tab === 'overview' && !$canAll) $tab = 'sign';
$cur = null;
foreach ($periods as $p) if (Clearance::isOpen($p)) { $cur = $p; break; }
$cur ??= $periods[0] ?? null;
$sy = (string) ($_GET['sy'] ?? ($cur['school_year'] ?? '')); $term = (string) ($_GET['term'] ?? ($cur['term'] ?? ''));
if (isset($_GET['pt']) && str_contains((string) $_GET['pt'], '|')) [$sy, $term] = explode('|', (string) $_GET['pt'], 2);
$f = ['program' => (string) ($_GET['program'] ?? ''), 'year' => (string) ($_GET['year'] ?? ''), 'q' => (string) ($_GET['q'] ?? ''), 'state' => (string) ($_GET['state'] ?? ''), 'office' => (string) ($_GET['office'] ?? '')];
$programs = $sy !== '' ? Db::col("SELECT DISTINCT program FROM sis_registrations WHERE school_year = ? AND term = ? AND locked_at IS NOT NULL AND program IS NOT NULL ORDER BY program", [$sy, $term]) : [];
$years = $sy !== '' ? Db::col("SELECT DISTINCT year_level FROM sis_registrations WHERE school_year = ? AND term = ? AND locked_at IS NOT NULL ORDER BY year_level", [$sy, $term]) : [];
$period = $sy !== '' ? Clearance::period($sy, $term) : null;
layout_start('Online Clearance');
page_title('Online Clearance', $grading, $teacher ? 'Sign the clearance of the students in your classes.' : 'Sign and review student clearance per term.');
$nNew = Clearance::newCount($me);
?>
<?php if ($nNew): ?><div class="alert alert-primary py-2"><i class="bi bi-inbox me-1"></i><?= $nNew ?> new submission<?= $nNew === 1 ? '' : 's' ?> from students for lines you sign. Look for the blue "new from student" badges below.</div><?php endif; ?>
<nav class="nav nav-pills gap-2 mb-3">
  <a class="nav-link<?= $tab === 'sign' ? ' active' : '' ?>" href="<?= url('clearance', ['sy' => $sy, 'term' => $term]) ?>">To sign</a>
  <?php if ($canAll): ?><a class="nav-link<?= $tab === 'overview' ? ' active' : '' ?>" href="<?= url('clearance', ['tab' => 'overview', 'sy' => $sy, 'term' => $term]) ?>">Overview</a><?php endif; ?>
  <?php if ($manage): ?><a class="nav-link<?= $tab === 'periods' ? ' active' : '' ?>" href="<?= url('clearance', ['tab' => 'periods']) ?>">Clearance periods</a><?php endif; ?>
</nav>
<?php if ($tab === 'periods'): ?>
<div class="row g-3"><div class="col-lg-7"><div class="form-section"><h2 class="h6">Clearance periods</h2>
<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Term</th><th>Opens</th><th>Closes</th><th>Status</th></tr></thead><tbody>
<?php foreach ($periods as $p): ?><tr><td><?= h($p['school_year'] . ' · ' . $p['term']) ?></td><td><?= h(date('M j, Y', strtotime($p['opens_on']))) ?></td><td><?= h(date('M j, Y', strtotime($p['closes_on']))) ?></td><td><?= Clearance::isOpen($p) ? '<span class="badge text-bg-success">Open now</span>' : ((int) $p['is_open'] ? '<span class="badge text-bg-secondary">Outside the dates</span>' : '<span class="badge text-bg-dark">Closed</span>') ?></td></tr><?php endforeach; ?>
<?php if (!$periods): ?><tr><td colspan="4" class="text-muted text-center py-3">No clearance period yet. Open one for a term.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<div class="col-lg-5"><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="period"><h2 class="h6">Open or change a period</h2>
  <div class="row g-2">
    <div class="col-6"><label class="form-label small" for="cpSy">School year</label><select class="form-select" id="cpSy" name="school_year"><?php foreach (GS::schoolYears() as $y): ?><option<?= $y === $sy ? ' selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?></select></div>
    <div class="col-6"><label class="form-label small" for="cpTerm">Term</label><select class="form-select" id="cpTerm" name="term"><?php foreach (['1st Semester', '2nd Semester', '3rd Semester', 'Summer'] as $t): ?><option<?= $t === $term ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-6"><label class="form-label small" for="cpOpen">Opens on</label><input class="form-control" type="date" id="cpOpen" name="opens_on" required value="<?= h(date('Y-m-d')) ?>"></div>
    <div class="col-6"><label class="form-label small" for="cpClose">Closes on</label><input class="form-control" type="date" id="cpClose" name="closes_on" required value="<?= h(date('Y-m-d', strtotime('+30 days'))) ?>"></div>
    <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_open" value="1" checked> Open (untick to close it early)</label></div>
  </div>
  <p class="form-text">Teachers and offices can sign only between these dates. Saving the same term again changes its dates.</p>
  <button class="btn btn-primary">Save period</button></form></div></div>
<?php else: ?>
<form method="get" class="form-section d-flex flex-wrap gap-2 align-items-end"><input type="hidden" name="r" value="clearance"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <div><label class="form-label small mb-0" for="clT">Term</label><select class="form-select" id="clT" name="pt"><?php foreach ($periods as $p): $k = $p['school_year'] . '|' . $p['term']; ?><option value="<?= h($k) ?>"<?= $p['school_year'] === $sy && $p['term'] === $term ? ' selected' : '' ?>><?= h($p['school_year'] . ' · ' . $p['term']) ?><?= Clearance::isOpen($p) ? ' (open)' : '' ?></option><?php endforeach; if (!$periods): ?><option value="">No clearance period yet</option><?php endif; ?></select></div>
  <div><label class="form-label small mb-0" for="clP">Program</label><select class="form-select" id="clP" name="program"><option value="">All</option><?php foreach ($programs as $p): ?><option<?= $p === $f['program'] ? ' selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0" for="clY">Year level</label><select class="form-select" id="clY" name="year"><option value="">All</option><?php foreach ($years as $y): ?><option<?= $y === $f['year'] ? ' selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?></select></div>
  <?php if ($tab === 'sign'): ?><div><label class="form-label small mb-0" for="clS">Status</label><select class="form-select" id="clS" name="state"><option value="">All</option><?php foreach (['PENDING' => 'Waiting', 'NOT_CLEARED' => 'Not cleared', 'CLEARED' => 'Cleared'] as $k => $v): ?><option value="<?= $k ?>"<?= $k === $f['state'] ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0" for="clQ">Student</label><input class="form-control" id="clQ" name="q" value="<?= h($f['q']) ?>" placeholder="Name or ID"></div><?php endif; ?>
  <button class="btn btn-outline-primary">Show</button>
</form>
<?php if ($sy === ''): ?><div class="alert alert-info">No clearance period yet. <?= $manage ? 'Open one in <a href="' . url('clearance', ['tab' => 'periods']) . '">Clearance periods</a>.' : 'The registrar opens clearance for a term.' ?></div>
<?php elseif (!Clearance::isOpen($period)): ?><div class="alert alert-warning py-2"><i class="bi bi-lock me-1"></i>Clearance for <?= h($sy . ' ' . $term) ?> is not open now<?= $period ? ' (' . h(date('M j', strtotime($period['opens_on'])) . '–' . date('M j, Y', strtotime($period['closes_on']))) . (!(int) $period['is_open'] ? ', closed' : '') . ')' : '' ?>: you can look, but not sign.</div><?php endif; ?>
<?php if ($sy !== '' && $tab === 'sign'): $rows = Clearance::toSign($me, $sy, $term, $f); $open = Clearance::isOpen($period); $signable = array_filter($rows, fn($x) => $x['item']['state'] !== 'CLEARED' && !$x['final']); ?>
<form method="post" id="bulkSign" data-confirm="Sign every ticked line as cleared with your e-signature?"><?= csrf_field() ?><input type="hidden" name="action" value="signmany"></form>
<div class="form-section"><div class="d-flex flex-wrap gap-2 align-items-end mb-2"><h2 class="h6 me-auto mb-0"><?= count($rows) ?> line(s) to sign · <?= count($signable) ?> not yet cleared</h2>
  <?php if ($open && $signable): ?><div><label class="form-label small mb-0" for="bsPw">Your password</label><input class="form-control form-control-sm" type="password" id="bsPw" name="password" form="bulkSign" required autocomplete="current-password"></div><button class="btn btn-sm btn-success" form="bulkSign"><i class="bi bi-pen"></i> Sign all ticked as cleared</button><?php endif; ?></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0" id="clrList"><thead><tr><th><?php if ($open && $signable): ?><input class="form-check-input" type="checkbox" data-check-all="#clrList" aria-label="Tick all" id="clrAll"><?php endif; ?></th><th>Student</th><th>Line</th><th>Status</th><th class="text-end">Balance</th><th class="text-end"></th></tr></thead><tbody>
<?php foreach ($rows as $x): $r = $x['reg']; $it = $x['item']; $can = $open && $it['state'] !== 'CLEARED' && !$x['final']; ?>
  <tr><td><?php if ($can): ?><input class="form-check-input clr-pick" type="checkbox" name="pick[]" value="<?= (int) $r['id'] . '|' . h($it['key']) ?>" form="bulkSign" aria-label="Tick <?= h($r['student_name']) ?>"><?php endif; ?></td>
    <td><a href="<?= url('clearance', ['reg' => $r['id']]) ?>"><strong><?= h($r['student_name']) ?></strong></a><div class="small text-muted"><?= h($r['student_number'] . ' · ' . $r['program'] . ' ' . $r['year_level'] . ' · ' . $r['section_name']) ?></div></td>
    <td><?= h($it['label']) ?><?= $x['new'] ? ' <a class="badge text-bg-primary" href="' . url('clearance', ['reg' => $r['id']]) . '">' . $x['new'] . ' new from student</a>' : '' ?></td>
    <td><?= $stateBadge($it['state']) ?><?= $it['tag'] && $it['state'] === 'NOT_CLEARED' ? '<div class="small text-danger">' . h($it['tag']['note']) . '</div>' : '' ?></td>
    <td class="text-end money"><?= $x['balance'] > 0 ? '<span class="text-danger">₱' . Money::fmt($x['balance']) . '</span>' : '<span class="text-success small">Paid</span>' ?></td>
    <td class="text-end text-nowrap"><?php if ($can): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="tag"><input type="hidden" name="reg" value="<?= (int) $r['id'] ?>"><input type="hidden" name="key" value="<?= h($it['key']) ?>"><input type="hidden" name="status" value="CLEARED"><button class="btn btn-sm btn-success" title="Sign as cleared"><i class="bi bi-pen"></i> Sign</button></form>
      <details class="d-inline-block text-start"><summary class="btn btn-sm btn-outline-danger">Not cleared</summary><form method="post" class="mt-1" style="min-width:15rem"><?= csrf_field() ?><input type="hidden" name="action" value="tag"><input type="hidden" name="reg" value="<?= (int) $r['id'] ?>"><input type="hidden" name="key" value="<?= h($it['key']) ?>"><input type="hidden" name="status" value="NOT_CLEARED"><input class="form-control form-control-sm mb-1" name="note" required minlength="3" maxlength="500" placeholder="What's missing" aria-label="What's missing"><button class="btn btn-sm btn-danger">Save</button></form></details><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-muted text-center py-3"><?= Clearance::canSign($me) ? 'Nothing to sign for these filters.' : 'Your account is not ticked "Can e-sign clearance", so you can look but not sign.' ?></td></tr><?php endif; ?>
</tbody></table></div></div>
<?php elseif ($sy !== '' && $tab === 'overview'): $ov = Clearance::overview($sy, $term, $f['program'], $f['year']); $all = Clearance::statuses(Clearance::registrations($sy, $term, $f['program'], $f['year'])); ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-4"><div class="form-section h-100 mb-0"><div class="small text-muted">Students</div><div class="fs-3 fw-semibold"><?= $ov['students']['total'] ?></div></div></div>
  <div class="col-6 col-md-4"><div class="form-section h-100 mb-0"><div class="small text-muted">Clearance completed</div><div class="fs-3 fw-semibold text-success"><?= $ov['students']['complete'] ?></div></div></div>
  <div class="col-12 col-md-4"><div class="form-section h-100 mb-0"><div class="small text-muted">Approved by the registrar</div><div class="fs-3 fw-semibold"><?= $ov['students']['approved'] ?></div></div></div>
</div>
<div class="form-section"><h2 class="h6">By office</h2><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Office</th><th class="text-end">Cleared</th><th class="text-end">Not cleared</th><th class="text-end">Waiting</th></tr></thead><tbody>
<?php foreach ($ov['offices'] as $o): ?><tr><td><?= h($o['label']) ?></td><td class="text-end text-success"><?= $o['CLEARED'] ?></td><td class="text-end text-danger"><?= $o['NOT_CLEARED'] ?></td><td class="text-end"><?= $o['PENDING'] ?></td></tr><?php endforeach; ?>
<?php if (!$ov['offices']): ?><tr><td colspan="4" class="text-muted text-center py-3">No confirmed enrollments for these filters.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="form-section"><h2 class="h6">Students</h2><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Student</th><th>Progress</th><th>Status</th></tr></thead><tbody>
<?php foreach ($all as $st): $r = $st['registration']; $n = count($st['items']); ?><tr><td><a href="<?= url('clearance', ['reg' => $r['id']]) ?>"><?= h($r['student_name']) ?></a> <span class="small text-muted"><?= h($r['student_number'] . ' · ' . $r['program'] . ' ' . $r['year_level']) ?></span></td>
  <td style="min-width:10rem"><div class="progress" role="progressbar" aria-label="Cleared lines" aria-valuenow="<?= $st['counts']['CLEARED'] ?>" aria-valuemin="0" aria-valuemax="<?= $n ?>"><div class="progress-bar bg-success" style="width:<?= $n ? round(100 * $st['counts']['CLEARED'] / $n) : 0 ?>%"></div></div><span class="small"><?= $st['counts']['CLEARED'] ?> of <?= $n ?> cleared</span></td>
  <td><?= $st['final'] ? '<span class="badge text-bg-success">Approved</span>' : ($st['complete'] ? '<span class="badge text-bg-info">Completed</span>' : ($st['counts']['NOT_CLEARED'] ? '<span class="badge text-bg-danger">Not cleared</span>' : '<span class="badge text-bg-warning">In progress</span>')) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php endif; ?>
<script src="assets/clearance.js?v=55"></script>
<?php layout_end();
