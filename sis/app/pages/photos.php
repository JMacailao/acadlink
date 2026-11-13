<?php
/** v62 Photo approvals: new photos students sent from the Student Portal. Approve to put it on the record, or decline with a reason. */
declare(strict_types=1);
/** @var bool $isPost */
Auth::require('students.edit');
if ($isPost) {
    $ok = in_array($_POST['decision'] ?? '', ['approve', 'approve_many'], true);
    if (in_array($_POST['decision'] ?? '', ['approve_many', 'decline_many'], true)) { // v62: several at once
        $n = StudentPhoto::decideMany((array) ($_POST['ids'] ?? []), $ok, (string) ($_POST['bulk_reason'] ?? ''));
        flash('success', $n . ' photo' . ($n === 1 ? '' : 's') . ($ok ? ' approved. They are now on the student records.' : ' declined. The students were told why.'));
    } else {
        StudentPhoto::decide((int) ($_POST['id'] ?? 0), $ok, (string) ($_POST['reason'] ?? ''));
        flash('success', $ok ? 'Photo approved. It is now on the student record.' : 'Photo declined. The student was told why.');
    }
    redirect('photos');
}
$rows = StudentPhoto::pending();
layout_start('Photo approvals');
page_title('Photo approvals', '', 'New photos students sent from the Student Portal. Check that it is a clear, recent photo of the student\'s face.');
?>
<?php if ($rows): ?>
<form method="post" id="photoBulk" class="form-section d-flex flex-wrap gap-2 align-items-center st-photo-bulk"><?= csrf_field() ?>
  <label class="form-check mb-0 me-2"><input class="form-check-input" type="checkbox" data-check-all="ids[]" data-check-scope="#photoBulkScope"> <b>Select all</b> <span class="text-muted small">(<?= count($rows) ?> waiting)</span></label>
  <button class="btn btn-primary btn-sm" name="decision" value="approve_many"><i class="bi bi-check2-all me-1"></i>Approve selected</button>
  <input class="form-control form-control-sm" name="bulk_reason" maxlength="255" placeholder="Reason, if declining the selected" aria-label="Reason for declining the selected photos" style="flex:1;min-width:200px">
  <button class="btn btn-outline-danger btn-sm" name="decision" value="decline_many">Decline selected</button>
</form>
<?php endif; ?>
<div class="st-photo-queue" id="photoBulkScope">
<?php foreach ($rows as $p): $s = GS::studentByNumber((string) $p['student_number']); $name = (string) ($s['studentName'] ?? $p['student_number']); ?>
  <section class="form-section st-photo-card">
    <div class="st-photo-compare"><figure><?= StudentPhoto::html(StudentPhoto::currentId((string) $p['student_number']), $name, 'lg') ?><figcaption>Now</figcaption></figure>
      <i class="bi bi-arrow-right" aria-hidden="true"></i><figure><?= StudentPhoto::html((int) $p['id'], $name, 'lg') ?><figcaption>New</figcaption></figure></div>
    <label class="form-check st-photo-pick"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" form="photoBulk" aria-label="Select <?= h($name) ?>"> Select</label>
    <h2 class="h6 mb-0"><?= h($name) ?></h2><p class="small text-muted mb-2"><?= h((string) $p['student_number']) ?> · sent <?= h(date('M j, g:i A', strtotime($p['created_at']))) ?></p>
    <form method="post" class="d-flex flex-wrap gap-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <button class="btn btn-primary btn-sm" name="decision" value="approve"><i class="bi bi-check-lg me-1"></i>Approve</button>
      <input class="form-control form-control-sm" name="reason" maxlength="255" placeholder="Reason, if declining (e.g. face not clear)" aria-label="Reason for declining" style="flex:1;min-width:180px">
      <button class="btn btn-outline-danger btn-sm" name="decision" value="decline">Decline</button></form>
  </section>
<?php endforeach; if (!$rows): ?><p class="text-muted">No photos are waiting for approval.</p><?php endif; ?>
</div>
<?php layout_end();
