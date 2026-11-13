<?php
/** v62 Data Privacy (RA 10173): records due for review (flag only, nothing is deleted) and the school's privacy notice. */
declare(strict_types=1);
/** @var bool $isPost */
Auth::require('students.edit', 'settings.manage');
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'reviewed') {
        $n = Privacy::markReviewed((array) ($_POST['sn'] ?? []), (string) ($_POST['note'] ?? ''));
        flash($n ? 'success' : 'warning', $n ? $n . ' record' . ($n === 1 ? '' : 's') . ' marked as reviewed and kept. They are listed again next year.' : 'Tick the records you reviewed.');
    } elseif ($a === 'notice') {
        Privacy::saveNotice((string) ($_POST['notice'] ?? ''), (int) ($_POST['years'] ?? 0));
        flash('success', 'Privacy settings saved. New applicants agree to this version of the notice.');
    } else throw new AppError('Unknown action.');
    redirect('privacy');
}
$canReview = Auth::can('students.edit');
$due = $canReview ? Privacy::dueForReview() : [];
$years = Privacy::years();
layout_start('Data Privacy');
page_title('Data Privacy', '', 'Data Privacy Act of 2012 (RA 10173): the privacy notice people agree to, and old records to review. Nothing is deleted automatically.');
?>
<div class="row g-3">
<?php if ($canReview): ?>
<div class="col-xl-7"><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="reviewed">
  <h2 class="h6">Records due for review <span class="text-muted fw-normal">(last enrolled <?= $years ?>+ years ago)</span></h2>
  <p class="small text-muted">Check whether each record still needs to be kept (for example for transcripts, school or government rules). Mark the ones you keep as reviewed; they come back next year. If a record must be erased, ask the Power User; erasing is never done from this list.</p>
  <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th><input class="form-check-input" type="checkbox" aria-label="Select all" data-check-all="sn[]"></th><th>Student</th><th>Last enrolled</th><th class="text-end">Years ago</th><th>Last review</th></tr></thead><tbody>
  <?php foreach ($due as $r): ?><tr><td><input class="form-check-input" type="checkbox" name="sn[]" value="<?= h($r['student_number']) ?>" aria-label="Reviewed <?= h($r['name']) ?>"></td><td><b><?= h($r['name']) ?></b> <span class="text-muted small"><?= h($r['student_number']) ?></span></td><td><?= h($r['last_school_year']) ?></td><td class="text-end"><?= (int) $r['years_ago'] ?></td><td class="small"><?= $r['reviewed_at'] ? h(date('M j, Y', strtotime($r['reviewed_at']))) . ($r['note'] ? ' · ' . h($r['note']) : '') : '—' ?></td></tr><?php endforeach; ?>
  <?php if (!$due): ?><tr><td colspan="5" class="text-muted">No records are due for review.</td></tr><?php endif; ?></tbody></table></div>
  <?php if ($due): ?><div class="d-flex flex-wrap gap-2"><input class="form-control form-control-sm" name="note" maxlength="255" placeholder="Note, e.g. Kept for transcript requests" aria-label="Review note" style="flex:1;min-width:220px"><button class="btn btn-primary btn-sm">Mark reviewed (keep)</button></div><?php endif; ?>
</form></div>
<?php endif; ?>
<div class="col-xl-5">
  <?php if (Auth::can('settings.manage')): ?>
  <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="notice">
    <h2 class="h6">Privacy notice</h2>
    <p class="small text-muted">Shown to applicants (staff and Student Portal), and in the Student Portal under My profile. Version <code><?= h(Privacy::version()) ?></code>; consents record which version was agreed to.</p>
    <textarea class="form-control mb-2" name="notice" rows="12" maxlength="8000" aria-label="Privacy notice"><?= h(Privacy::notice()) ?></textarea>
    <label class="form-label small" for="pY">Review records of students who left after</label><div class="input-group input-group-sm mb-2" style="max-width:200px"><input class="form-control" id="pY" name="years" type="number" min="1" max="50" value="<?= $years ?>"><span class="input-group-text">years</span></div>
    <button class="btn btn-primary btn-sm">Save</button>
  </form>
  <?php else: ?><section class="form-section"><?= Privacy::noticeHtml() ?></section><?php endif; ?>
  <section class="form-section"><h2 class="h6">What AcadLink does for privacy</h2><ul class="small mb-0">
    <li>Applicants agree to the notice before their application is saved; the consent is kept with the date and version.</li>
    <li>Students can download a copy of their data from the Student Portal (My profile → Download my data).</li>
    <li>Each person sees only what their group allows; parents see only their own children, read-only.</li>
    <li>Sign-ins, changes, exports of personal data and reviews are in the Activity Log.</li></ul></section>
</div></div>
<?php layout_end();
