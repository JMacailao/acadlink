<?php
declare(strict_types=1);
/**
 * Assessment → Subject Fees (Fee Management): the Price per Unit of every subject.
 * v56: every subject appears (₱0.00 until a price is set), Total = Units × Price per Unit, Edit changes the
 * existing price in place, and each price is charged only by the fee templates ticked for it.
 */
$canEdit = Auth::can('assessment.edit');
$recomputeNote = static function (array $r): string {
    $n = (int) ($r['recomputed'] ?? 0); $s = (int) ($r['skipped'] ?? 0);
    return ($n ? " $n open assessment" . ($n === 1 ? ' was' : 's were') . ' recalculated.' : '')
        . ($s ? " $s open assessment" . ($s === 1 ? '' : 's') . ' with payments or a published portal assessment kept the old amount: open each one and save it to recalculate.' : '');
};
if ($isPost) {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete') { $r = SubjectFees::delete((int) ($_POST['id'] ?? 0)); flash('success', 'Subject price removed. The subject now shows ₱0.00.' . $recomputeNote(['recomputed' => $r[0], 'skipped' => $r[1]])); }
    elseif ($action === 'default_label') { SubjectFees::setDefaultLabel((int) ($_POST['label_id'] ?? 0)); flash('success', 'Default fee saved. New subject prices use it.'); }
    else { $r = SubjectFees::save($_POST + ['templates_sent' => '1']); flash('success', 'Subject price saved (Units × Price per Unit).' . $recomputeNote($r)); }
    redirect('subjectfees');
}
$rows = SubjectFees::management();
$labels = Assessment::labels(true);
$templates = Assessment::names();
$defaultLabel = SubjectFees::defaultLabel();
$editFee = isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) ? Db::one('SELECT * FROM sis_subject_fees WHERE id = ? AND assessment_name_id IS NULL', [(int) $_GET['edit']]) : null;
$editSubject = $editFee ? GS::get('subjects', (string) $editFee['subject_doc_id']) : (isset($_GET['subject']) ? GS::get('subjects', (string) $_GET['subject']) : null);
$editTicks = $editFee ? array_map('intval', Db::col('SELECT assessment_name_id FROM sis_assessment_name_subject_fees WHERE subject_fee_id = ?', [(int) $editFee['id']])) : [];
$priced = count(array_filter($rows, fn($r) => $r['fee'] !== null));
$split = LecLab::on(); // v56.2: Settings → Separate lecture and laboratory units
layout_start('Subject Fees');
page_title('Subject Fees', $canEdit ? '<a class="btn btn-primary" href="' . h(url('subjectfees', ['new' => 1]) . '#subjectPrice') . '"><i class="bi bi-plus-lg me-1"></i>Set a subject price</a>' : '',
    'Every subject is listed with its Price per Unit. Total = Subject Units × Price per Unit. A new subject starts at ₱0.00 and still shows on assessments. Tick the fee templates that charge each price.');
?>
<div class="row g-3">
  <div class="col-12">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
      <input class="form-control form-control-sm" style="max-width:320px" data-filter="#subjectPrices" placeholder="Search subject code, name or fee" aria-label="Search subject prices">
      <span class="small text-muted"><?= count($rows) ?> row(s) · <?= $priced ?> priced · <?= count($rows) - $priced ?> at ₱0.00</span>
    </div>
    <div class="table-responsive bg-white border rounded"><table class="table table-sm align-middle mb-0" id="subjectPrices">
    <thead class="table-light"><tr><th>Subject</th><th class="text-end"><?= $split ? 'Lec / Lab / Units' : 'Units' ?></th><th>Fee</th><?php if ($split): ?><th>Charged per</th><?php endif; ?><th class="text-end">Price per unit</th><th class="text-end">Total</th><th>Charged in templates</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $f = $r['fee']; ?><tr<?= $f && $editFee && (int) $f['id'] === (int) $editFee['id'] ? ' class="table-primary"' : '' ?>>
      <td><span class="font-monospace"><?= h($r['subject_code'] ?: '—') ?></span><div class="small text-muted"><?= h($r['subject_name']) ?></div></td>
      <td class="text-end text-nowrap"><?= $split ? h(LecLab::text($r['lec']) . ' / ' . LecLab::text($r['lab']) . ' / ') : '' ?><?= h($r['units']) ?></td>
      <td class="small"><?= $f ? h($f['label_name']) . ' <span class="small text-muted">· ' . h($f['category_name']) . '</span>' . ($f['label_active'] ? '' : ' <span class="badge text-bg-secondary">fee turned off</span>') : '<span class="small text-muted">No price yet</span>' ?></td>
      <?php if ($split): ?><td class="small"><?= $f ? h(LecLab::BASIS[$f['unit_basis'] ?? 'ALL'] . ' (' . LecLab::text($r['basis_units']) . ')') : '' ?></td><?php endif; ?>
      <td class="text-end">₱<?= Money::fmt($r['rate_cents']) ?></td>
      <td class="text-end fw-semibold">₱<?= Money::fmt($r['total_cents']) ?></td>
      <td class="small"><?php if (!$f): ?><span class="text-muted">—</span><?php elseif (!$f['templates']): ?><span class="text-warning-emphasis">None ticked</span> <span class="text-muted">(charged only without a template)</span><?php else: ?><?= h(implode(', ', array_column($f['templates'], 'name'))) ?><?php endif; ?></td>
      <td class="text-end text-nowrap"><?php if ($canEdit): ?>
        <?php if ($f): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url('subjectfees', ['edit' => $f['id']]) . '#subjectPrice') ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
          <form method="post" class="d-inline" data-confirm="Remove the <?= h($f['label_name']) ?> price of <?= h($r['subject_code'] ?: $r['subject_name']) ?>? The subject goes back to ₱0.00."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Remove price"><i class="bi bi-x-lg"></i></button></form>
        <?php else: ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url('subjectfees', ['subject' => $r['subject_doc_id']]) . '#subjectPrice') ?>"><i class="bi bi-pencil me-1"></i>Edit</a><?php endif; ?>
      <?php endif; ?></td>
    </tr><?php endforeach; if (!$rows): ?><tr><td colspan="<?= $split ? 8 : 7 ?>" class="text-center text-muted py-4">No subjects yet. Add subjects in Curriculum → Subjects (or in Grading → Subjects); they appear here at ₱0.00.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="small text-muted mt-2">Confirmed enrollments keep the assessment they were confirmed with. Open assessments without payments are recalculated when a price changes; the rest are recalculated when the registrar saves them.</p>
  </div>
  <?php if ($canEdit): ?>
  <div class="col-lg-7">
    <form method="post" class="form-section" id="subjectPrice" data-price-form><?= csrf_field() ?><input type="hidden" name="action" value="save">
      <?php if ($editFee): ?><input type="hidden" name="id" value="<?= (int) $editFee['id'] ?>"><?php endif; ?>
      <h2 class="h6"><?= $editFee ? 'Edit subject price' : 'Set a subject price' ?></h2>
      <label class="form-label small" for="sfSubject">Subject *</label>
      <?php if ($editFee): ?><input class="form-control mb-2" id="sfSubject" value="<?= h(($editSubject['subjectCode'] ?? '') . ' · ' . ($editSubject['subjectName'] ?? 'Subject no longer exists')) ?>" disabled><?php $es = $editSubject ? LecLab::ofSubject($editSubject) : [0, 0, 0]; ?><input type="hidden" data-units value="<?= h((string) (float) ($editSubject['units'] ?? 0)) ?>" data-lec="<?= h((string) $es[0]) ?>" data-lab="<?= h((string) $es[1]) ?>">
      <?php else: ?><select class="form-select mb-2" id="sfSubject" name="subject_doc_id" required data-units-select><option value="">— choose —</option>
        <?php foreach (GS::subjects() as $s): ?><?php $ss = LecLab::ofSubject($s); ?><option value="<?= h($s['id']) ?>" data-units="<?= h((string) (float) ($s['units'] ?? 0)) ?>" data-lec="<?= h((string) $ss[0]) ?>" data-lab="<?= h((string) $ss[1]) ?>"<?= ($editSubject['id'] ?? '') === $s['id'] ? ' selected' : '' ?>><?= h(($s['subjectCode'] ?? '') . ' · ' . ($s['subjectName'] ?? '') . ' (' . (float) ($s['units'] ?? 0) . ' units)') ?></option><?php endforeach; ?></select><?php endif; ?>
      <label class="form-label small" for="sfLabel">Fee</label>
      <select class="form-select mb-1" id="sfLabel" name="label_id"><?php foreach ($labels as $l): ?><option value="<?= (int) $l['id'] ?>"<?= (int) ($editFee['label_id'] ?? $defaultLabel) === (int) $l['id'] ? ' selected' : '' ?>><?= h($l['category_name'] . ' · ' . $l['name']) ?></option><?php endforeach; ?></select>
      <p class="form-text mt-0">Fees come from Assessment → Labels.</p>
      <?php if ($split): ?><label class="form-label small" for="sfBasis">Charged per</label>
      <select class="form-select mb-2" id="sfBasis" name="unit_basis" data-basis><?php foreach (LecLab::BASIS as $bk => $bl): ?><option value="<?= $bk ?>"<?= ($editFee['unit_basis'] ?? 'ALL') === $bk ? ' selected' : '' ?>><?= h($bl) ?></option><?php endforeach; ?></select>
      <p class="form-text mt-0">Example: Laboratory fee per laboratory unit; Tuition per lecture unit or all units.</p><?php endif; ?>
      <label class="form-label small" for="sfRate">Price per unit (₱)</label>
      <input class="form-control mb-1" id="sfRate" name="rate" inputmode="decimal" placeholder="0.00" value="<?= h($editFee ? Money::fmt(Money::cents($editFee['rate'])) : '0.00') ?>">
      <p class="fee-total small mb-2" data-price-total aria-live="polite"></p>
      <fieldset class="mb-3"><legend class="form-label small mb-1">Charge in these fee templates</legend>
        <?php if ($templates): ?><div class="border rounded p-2" style="max-height:180px;overflow:auto">
          <?php foreach ($templates as $t): ?><label class="form-check small mb-1"><input class="form-check-input" type="checkbox" name="templates[]" value="<?= (int) $t['id'] ?>"<?= in_array((int) $t['id'], $editTicks, true) ? ' checked' : '' ?>> <span class="form-check-label"><?= h($t['name']) ?><?= $t['is_active'] ? '' : ' <span class="text-muted">(inactive)</span>' ?></span></label><?php endforeach; ?>
        </div><p class="form-text mb-0">Only ticked templates charge this price. You can also tick prices while managing a fee template.</p>
        <?php else: ?><p class="small text-muted mb-0">No fee templates yet (Assessment → Fee Templates).</p><?php endif; ?>
      </fieldset>
      <button class="btn btn-primary">Save price</button><?php if ($editFee || $editSubject): ?> <a class="btn btn-link" href="<?= url('subjectfees') ?>">Cancel</a><?php endif; ?>
    </form>
  </div>
  <div class="col-lg-5">
    <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="default_label">
      <h2 class="h6">Default fee for subject prices</h2>
      <select class="form-select form-select-sm mb-2" name="label_id" aria-label="Default fee"><?php foreach ($labels as $l): ?><option value="<?= (int) $l['id'] ?>"<?= (int) $defaultLabel === (int) $l['id'] ? ' selected' : '' ?>><?= h($l['category_name'] . ' · ' . $l['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-outline-primary">Save default</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<script src="assets/fee-manage.js?v=56b" defer></script>
<?php layout_end();
