<?php
/** v62 Photo approvals: new photos students sent from the Student Portal. Approve to put it on the record, or decline with a reason. */
declare(strict_types=1);
/** @var bool $isPost */
Auth::require('students.edit');
if ($isPost) {
    $ok = ($_POST['decision'] ?? '') === 'approve';
    StudentPhoto::decide((int) ($_POST['id'] ?? 0), $ok, (string) ($_POST['reason'] ?? ''));
    flash('success', $ok ? 'Photo approved. It is now on the student record.' : 'Photo declined. The student was told why.');
    redirect('photos');
}
$rows = StudentPhoto::pending();
layout_start('Photo approvals');
page_title('Photo approvals', '', 'New photos students sent from the Student Portal. Check that it is a clear, recent photo of the student\'s face.');
?>
<div class="st-photo-queue">
<?php foreach ($rows as $p): $s = GS::studentByNumber((string) $p['student_number']); $name = (string) ($s['studentName'] ?? $p['student_number']); ?>
  <section class="form-section st-photo-card">
    <div class="st-photo-compare"><figure><?= StudentPhoto::html(StudentPhoto::currentId((string) $p['student_number']), $name, 'lg') ?><figcaption>Now</figcaption></figure>
      <i class="bi bi-arrow-right" aria-hidden="true"></i><figure><?= StudentPhoto::html((int) $p['id'], $name, 'lg') ?><figcaption>New</figcaption></figure></div>
    <h2 class="h6 mb-0"><?= h($name) ?></h2><p class="small text-muted mb-2"><?= h((string) $p['student_number']) ?> · sent <?= h(date('M j, g:i A', strtotime($p['created_at']))) ?></p>
    <form method="post" class="d-flex flex-wrap gap-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <button class="btn btn-primary btn-sm" name="decision" value="approve"><i class="bi bi-check-lg me-1"></i>Approve</button>
      <input class="form-control form-control-sm" name="reason" maxlength="255" placeholder="Reason, if declining (e.g. face not clear)" aria-label="Reason for declining" style="flex:1;min-width:180px">
      <button class="btn btn-outline-danger btn-sm" name="decision" value="decline">Decline</button></form>
  </section>
<?php endforeach; if (!$rows): ?><p class="text-muted">No photos are waiting for approval.</p><?php endif; ?>
</div>
<?php layout_end();
