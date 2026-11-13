<?php
/**
 * v61.1 Messages: private conversations between a class's teacher and one student (like private comments in a class).
 * Teachers pick a class and a student to start one; students write to the teacher of any of their classes.
 */
declare(strict_types=1);
/** @var bool $isPost */
$sy = lms_year();
$classes = Lms::myClasses($sy);
$teach = lms_teaches($classes);
$me = Lms::me();
$cid = (string) ($_GET['c'] ?? $_POST['c'] ?? '');
$sid = (string) ($_GET['s'] ?? $_POST['s'] ?? '');
$c = $cid !== '' ? Lms::classFor($cid) : null;
if ($c && $c['role'] === 'STUDENT') $sid = (string) $me['student'];
if ($isPost) {
    if (!$c || $sid === '') throw new AppError('Choose who to write to.');
    Lms::sendMessage($c, $sid, (string) ($_POST['body'] ?? ''));
    redirect('messages', array_filter(['c' => $c['id'], 's' => $c['role'] === 'STUDENT' ? null : $sid, 'sy' => $_GET['sy'] ?? null]));
}
$thread = $c && $sid !== '' ? Lms::thread($c, $sid) : null;
$threads = Lms::threads($classes);
$withName = $c ? ($c['role'] === 'STUDENT' ? $c['teacherName'] : (string) (GS::get('students', $sid)['studentName'] ?? 'Student')) : '';
layout_start('Messages');
lms_head('Messages', $teach ? 'Private messages with your students. Only you and the student can read a conversation.' : 'Private messages with your teachers. Only you and your teacher can read a conversation.', '', lms_year_picker('messages', $sy));
?>
<div class="lms-msg">
  <aside class="lms-panel lms-msg-list">
    <form method="get" class="lms-msg-new"><input type="hidden" name="r" value="messages"><?php if (!empty($_GET['sy'])): ?><input type="hidden" name="sy" value="<?= h((string) $_GET['sy']) ?>"><?php endif; ?>
      <label class="form-label small mb-1" for="mC">New message</label>
      <?php if ($teach): ?>
      <select class="form-select form-select-sm mb-1" id="mC" name="c" data-autosubmit><option value="">Choose a class…</option><?php foreach ($classes as $x) if ($x['role'] !== 'STUDENT'): ?><option value="<?= h($x['id']) ?>"<?= $c && $c['id'] === $x['id'] ? ' selected' : '' ?>><?= h($x['subjectCode'] . ' · ' . $x['sectionName']) ?></option><?php endif; ?></select>
      <?php if ($c && $c['role'] !== 'STUDENT'): ?><select class="form-select form-select-sm" name="s" aria-label="Student" data-autosubmit><option value="">Choose a student…</option><?php foreach (Lms::roster($c) as $st): ?><option value="<?= h($st['id']) ?>"<?= $sid === $st['id'] ? ' selected' : '' ?>><?= h($st['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
      <?php else: ?>
      <select class="form-select form-select-sm" id="mC" name="c" data-autosubmit><option value="">Choose a class…</option><?php foreach ($classes as $x): ?><option value="<?= h($x['id']) ?>"<?= $c && $c['id'] === $x['id'] ? ' selected' : '' ?>><?= h($x['subjectCode'] . ' · ' . $x['teacherName']) ?></option><?php endforeach; ?></select>
      <?php endif; ?>
    </form>
    <?php foreach ($threads as $t): $active = $c && $c['id'] === $t['class_id'] && $sid === $t['student_doc_id']; ?>
      <a class="lms-thread<?= $active ? ' active' : '' ?><?= $t['unread'] ? ' is-unread' : '' ?>" href="<?= url('messages', array_filter(['c' => $t['class_id'], 's' => $t['class']['role'] === 'STUDENT' ? null : $t['student_doc_id'], 'sy' => $_GET['sy'] ?? null])) ?>"<?= $active ? ' aria-current="true"' : '' ?>>
        <span class="lms-avatar sm" style="--h:<?= lms_hue($t['with']) ?>"><?= h(mb_strtoupper(mb_substr($t['with'], 0, 1))) ?></span>
        <span class="lms-thread-main"><b><?= h($t['with']) ?></b><small><?= h($t['class']['subjectCode'] . ' · ' . $t['class']['sectionName']) ?></small><span><?= $t['mine'] ? 'You: ' : '' ?><?= h(mb_strimwidth($t['body'], 0, 60, '…')) ?></span></span>
        <span class="lms-thread-side"><small><?= h(lms_when($t['at'], false)) ?></small><?php if ($t['unread']): ?><em class="sis-nav-badge" aria-label="<?= $t['unread'] ?> unread"><?= $t['unread'] ?></em><?php endif; ?></span></a>
    <?php endforeach; if (!$threads): ?><p class="lms-muted small mt-2 mb-0">No conversations yet.</p><?php endif; ?>
  </aside>
  <section class="lms-panel lms-msg-thread">
    <?php if ($thread !== null): ?>
      <div class="lms-msg-head"><span class="lms-avatar sm" style="--h:<?= lms_hue($withName) ?>"><?= h(mb_strtoupper(mb_substr($withName, 0, 1))) ?></span><div><b><?= h($withName) ?></b><small class="d-block lms-muted"><?= h(lms_class_label($c)) ?></small></div></div>
      <div class="lms-bubbles" aria-live="polite">
        <?php foreach ($thread as $m): $mine = (bool) $m['from_teacher'] === ($c['role'] !== 'STUDENT'); ?><div class="lms-bubble<?= $mine ? ' is-mine' : '' ?>"><div><?= nl2br(h($m['body'])) ?></div><small><?= h($mine ? 'You' : $m['sender_name']) ?> · <?= h(lms_when($m['created_at'])) ?><?= $mine && $m['read_at'] ? ' · Seen' : '' ?></small></div><?php endforeach; ?>
        <?php if (!$thread): ?><p class="lms-muted text-center my-4">Start the conversation with <?= h($withName) ?>.</p><?php endif; ?>
      </div>
      <form method="post" class="lms-msg-form"><?= csrf_field() ?><input type="hidden" name="c" value="<?= h($c['id']) ?>"><input type="hidden" name="s" value="<?= h($sid) ?>">
        <textarea class="form-control" name="body" rows="2" maxlength="5000" placeholder="Write a message…" aria-label="Message" required></textarea><button class="btn btn-primary"><i class="bi bi-send me-1"></i>Send</button></form>
    <?php else: ?><div class="lms-empty"><i class="bi bi-chat-square-text" aria-hidden="true"></i><p class="mb-0">Choose a conversation, or start a new message.</p></div><?php endif; ?>
  </section>
</div>
<?php layout_end();
