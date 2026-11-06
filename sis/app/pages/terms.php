<?php
/** Settings → Academic terms and deadlines: when enrollment is open, classes start and grades are due, per school year and term. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$embed = isset($_GET['embed']);
$keep = $embed ? ['embed' => 1] : [];
if ($isPost) {
    if (($_POST['action'] ?? '') === 'delete') {
        TermCalendar::delete((int) In::int($_POST, 'id'));
        flash('success', 'Term removed from the calendar.');
    } else {
        TermCalendar::save(In::int($_POST, 'id'), $_POST);
        flash('success', 'Academic term saved.');
    }
    redirect('terms', $keep);
}
$rows = TermCalendar::all();
$e = isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) ? Db::one('SELECT * FROM sis_term_calendar WHERE id = ?', [(int) $_GET['edit']]) : null;
$today = date('Y-m-d');
layout_start('Academic terms and deadlines', $embed);
if ($embed) echo '<div class="embed-wrap">'; else page_title('Academic terms and deadlines', '<a class="btn btn-outline-secondary" href="' . url('settings') . '">Settings</a>', 'Registration is allowed only while enrollment is open for the term; an administrator can still register a student late, with a reason.');
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="form-section p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>School year · term</th><th>Enrollment</th><th>Classes start</th><th>Grades due</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $c): $open = $today >= $c['enrollment_start'] && $today <= $c['enrollment_end']; ?>
      <tr><td><strong><?= h($c['school_year']) ?></strong> · <?= h($c['term']) ?><?= $c['notes'] ? '<div class="small text-muted">' . h($c['notes']) . '</div>' : '' ?></td>
        <td class="small"><?= h(date('M j', strtotime($c['enrollment_start'])) . ' – ' . date('M j, Y', strtotime($c['enrollment_end']))) ?> <span class="badge <?= $open ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $open ? 'open' : ($today < $c['enrollment_start'] ? 'not yet' : 'closed') ?></span></td>
        <td class="small"><?= $c['classes_start'] ? h(date('M j, Y', strtotime($c['classes_start']))) : '—' ?></td>
        <td class="small"><?= $c['grades_due'] ? h(date('M j, Y', strtotime($c['grades_due']))) : '—' ?></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= url('terms', ['edit' => $c['id']] + $keep) ?>">Edit</a>
          <?= post_button('terms', $keep, ['action' => 'delete', 'id' => (string) $c['id']], 'Remove', 'btn-sm btn-outline-danger', 'Remove ' . $c['school_year'] . ' ' . $c['term'] . ' from the calendar? Registration for it is then always open.') ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="text-muted text-center py-4">No terms yet: registration is open for every term. Add one to set its enrollment period.</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
  <div class="col-lg-5"><form method="post" class="form-section" action="<?= url('terms', $keep) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($e['id'] ?? 0) ?: '' ?>">
    <h2 class="h6"><?= $e ? 'Edit ' . h($e['school_year'] . ' ' . $e['term']) : 'Add a term' ?></h2>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small" for="tSy">School year</label><input class="form-control" id="tSy" name="school_year" required pattern="\d{4}-\d{4}" value="<?= h($e['school_year'] ?? selected_sy()) ?>"></div>
      <div class="col-6"><label class="form-label small" for="tTerm">Term</label><select class="form-select" id="tTerm" name="term"><?= list_options(TERMS, $e['term'] ?? '1st Semester') ?></select></div>
      <div class="col-6"><label class="form-label small" for="tEs">Enrollment opens</label><input class="form-control" type="date" id="tEs" name="enrollment_start" required value="<?= h($e['enrollment_start'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small" for="tEe">Enrollment closes</label><input class="form-control" type="date" id="tEe" name="enrollment_end" required value="<?= h($e['enrollment_end'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small" for="tCs">Classes start</label><input class="form-control" type="date" id="tCs" name="classes_start" value="<?= h($e['classes_start'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label small" for="tGd">Final grades due</label><input class="form-control" type="date" id="tGd" name="grades_due" value="<?= h($e['grades_due'] ?? '') ?>"></div>
      <div class="col-12"><label class="form-label small" for="tNotes">Notes</label><input class="form-control" id="tNotes" name="notes" maxlength="255" value="<?= h($e['notes'] ?? '') ?>" placeholder="e.g. Late enrollment with a fee until Aug 20"></div>
    </div>
    <div class="d-flex gap-2 mt-3"><?php if ($e): ?><a class="btn btn-outline-secondary" href="<?= url('terms', $keep) ?>">Cancel</a><?php endif; ?><button class="btn btn-primary ms-auto">Save term</button></div>
  </form></div>
</div>
<?php if ($embed) echo '</div>';
layout_end($embed);
