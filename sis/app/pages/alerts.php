<?php
/** v62 My notifications: what the signed-in person was told (new classwork, returned work, messages, library reminders, low stock…). */
declare(strict_types=1);
/** @var bool $isPost */
if (!Auth::id()) throw new AuthRequired();
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'read_all') { Notify::markAllRead(); flash('success', 'All notifications marked as read.'); redirect('alerts'); }
    if ($a === 'open') {
        $n = Notify::find((int) ($_POST['id'] ?? 0)) ?: throw new AppError('Notification not found.');
        Notify::markRead((int) $n['id']);
        $to = Notify::safeLink($n['link_url']);
        if ($to !== '') { header('Location: ' . $to, true, 303); exit; }
        redirect('alerts');
    }
    throw new AppError('Unknown action.');
}
$only = ($_GET['show'] ?? '') === 'unread';
$rows = Notify::mine(100, $only);
$unread = Notify::unread();
$icons = ['LMS_WORK' => 'journal-text', 'LMS_POST' => 'megaphone', 'LMS_RETURNED' => 'patch-check', 'LMS_MESSAGE' => 'chat-square-text', 'LIBRARY_DUE' => 'book', 'LIBRARY_OVERDUE' => 'exclamation-triangle',
    'LIBRARY_READY' => 'bookmark-check', 'CANTEEN_LOW_STOCK' => 'box-seam', 'CLEARANCE' => 'patch-check', 'DOC_REQUEST' => 'file-earmark-text', 'PHOTO_REVIEW' => 'person-bounding-box', 'PHOTO_DECIDED' => 'person-check'];
layout_start('Notifications');
$readAll = '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="read_all"><button class="btn btn-outline-primary btn-sm"' . ($unread ? '' : ' disabled') . '>Mark all as read</button></form>';
page_title('Notifications', $readAll, $unread ? $unread . ' unread' : 'You are all caught up.');
?>
<nav class="al-alert-tabs" aria-label="Show"><a href="<?= url('alerts') ?>"<?= !$only ? ' class="active" aria-current="page"' : '' ?>>All</a><a href="<?= url('alerts', ['show' => 'unread']) ?>"<?= $only ? ' class="active" aria-current="page"' : '' ?>>Unread<?= $unread ? ' (' . $unread . ')' : '' ?></a></nav>
<div class="al-alerts">
<?php foreach ($rows as $n): $link = Notify::safeLink($n['link_url']); ?>
  <form method="post" class="al-alert<?= (int) $n['is_read'] ? '' : ' is-unread' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
    <button type="submit"<?= $link === '' && (int) $n['is_read'] ? ' disabled' : '' ?>>
      <span class="al-alert-icon"><i class="bi bi-<?= $icons[$n['type']] ?? 'bell' ?>" aria-hidden="true"></i></span>
      <span class="al-alert-main"><b><?= h($n['title']) ?></b><span><?= h($n['message']) ?></span><small><?= h(date('D, M j · g:i A', strtotime($n['created_at']))) ?><?= $n['email_status'] === 'SENT' ? ' · emailed' : '' ?></small></span>
      <?php if (!(int) $n['is_read']): ?><span class="al-alert-dot" aria-label="Unread"></span><?php endif; ?>
      <?php if ($link !== ''): ?><i class="bi bi-chevron-right al-alert-go" aria-hidden="true"></i><?php endif; ?>
    </button></form>
<?php endforeach; if (!$rows): ?><p class="text-muted my-4 text-center"><?= $only ? 'No unread notifications.' : 'No notifications yet.' ?></p><?php endif; ?>
</div>
<p class="small text-muted mt-3">Notifications are also emailed when the school has set up email and your account has an email address<?= Auth::studentNumber() ? ' (My profile → Email)' : '' ?>.</p>
<?php layout_end();
