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
    elseif ($action === 'bulk') {
        // v65.4: Subject pricing page: one fee, several subjects, each with its own price per unit, charged in the chosen templates
        $subs = array_values((array) ($_POST['p_subject'] ?? [])); $fees = array_values((array) ($_POST['p_fee'] ?? [])); $rates = array_values((array) ($_POST['p_rate'] ?? []));
        if (!$subs) throw new AppError('Add at least one subject.');
        if (count($subs) > 500 || count($subs) !== count($rates)) throw new AppError('The list of subjects was not sent completely. Reload the page and try again.');
        $sum = ['recomputed' => 0, 'skipped' => 0]; $saved = 0;
        Db::tx(function () use ($subs, $fees, $rates, &$sum, &$saved) {
            foreach ($subs as $i => $sid) {
                $in = ['subject_doc_id' => (string) $sid, 'label_id' => (string) ($_POST['label_id'] ?? ''), 'rate' => (string) ($rates[$i] ?? ''), 'templates' => (array) ($_POST['templates'] ?? []), 'templates_sent' => '1'];
                if (isset($_POST['unit_basis'])) $in['unit_basis'] = (string) $_POST['unit_basis'];
                if (ctype_digit((string) ($fees[$i] ?? ''))) $in['id'] = (string) $fees[$i];
                $subject = GS::get('subjects', (string) $sid);
                try { $r = SubjectFees::save($in); }
                catch (AppError $e) { throw new AppError(($subject['subjectCode'] ?? 'A subject') . ': ' . $e->getMessage()); }
                $sum['recomputed'] += (int) $r['recomputed']; $sum['skipped'] += (int) $r['skipped']; $saved++;
            }
        });
        flash('success', $saved . ' subject price' . ($saved === 1 ? '' : 's') . ' saved (Units × Price per Unit).' . $recomputeNote($sum));
    }
    else { $r = SubjectFees::save($_POST + ['templates_sent' => '1']); flash('success', 'Subject price saved (Units × Price per Unit).' . $recomputeNote($r)); }
    if ($action === 'default_label' && !empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
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
// v64.9: search runs on the server (all subjects), and only one page of the list is drawn
$fq = trim((string) ($_GET['q'] ?? ''));
$shown = $fq === '' ? $rows : array_values(array_filter($rows, fn($r) => str_contains(mb_strtolower($r['subject_code'] . ' ' . $r['subject_name'] . ' ' . ($r['fee']['label_name'] ?? '')), mb_strtolower($fq))));
$pageRows = RecordList::slice($shown, $win);
$split = LecLab::on(); // v56.2: Settings → Separate lecture and laboratory units
$pricing = $canEdit && ($editFee || $editSubject || isset($_GET['new']));
if ($pricing) { require SIS_ROOT . '/app/views/subject-pricing.php'; return; }
layout_start('Subject Fees');
page_title('Subject Fees', $canEdit ? '<a class="btn btn-primary" href="' . h(url('subjectfees', ['new' => 1])) . '"><i class="bi bi-plus-lg me-1"></i>Set a subject price</a>' : '',
    'Every subject is listed with its Price per Unit. Total = Subject Units × Price per Unit. A new subject starts at ₱0.00 and still shows on assessments. Tick the fee templates that charge each price.');
?>
<div class="row g-3">
  <div class="col-12">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
      <form method="get" class="d-flex gap-2 d-print-none" role="search"><input type="hidden" name="r" value="subjectfees"><input class="form-control form-control-sm" style="max-width:320px" type="search" name="q" value="<?= h($fq) ?>" placeholder="Search subject code, name or fee" aria-label="Search subject prices"><button class="btn btn-sm btn-outline-primary">Search</button><?php if ($fq !== ''): ?><a class="btn btn-sm btn-link" href="<?= url('subjectfees') ?>">Clear</a><?php endif; ?></form>
      <span class="small text-muted"><?= count($rows) ?> row(s) · <?= $priced ?> priced · <?= count($rows) - $priced ?> at ₱0.00</span>
    </div>
    <div class="table-responsive bg-white border rounded"><table class="table table-sm align-middle mb-0" id="subjectPrices">
    <thead class="table-light"><tr><th>Subject</th><th class="text-end"><?= $split ? 'Lec / Lab / Units' : 'Units' ?></th><th>Fee</th><?php if ($split): ?><th>Charged per</th><?php endif; ?><th class="text-end">Price per unit</th><th class="text-end">Total</th><th>Charged in templates</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>
    <?php foreach ($pageRows as $r): $f = $r['fee']; ?><tr<?= $f && $editFee && (int) $f['id'] === (int) $editFee['id'] ? ' class="table-primary"' : '' ?>>
      <td><span class="font-monospace"><?= h($r['subject_code'] ?: '—') ?></span><div class="small text-muted"><?= h($r['subject_name']) ?></div></td>
      <td class="text-end text-nowrap"><?= $split ? h(LecLab::text($r['lec']) . ' / ' . LecLab::text($r['lab']) . ' / ') : '' ?><?= h($r['units']) ?></td>
      <td class="small"><?= $f ? h($f['label_name']) . ' <span class="small text-muted">· ' . h($f['category_name']) . '</span>' . ($f['label_active'] ? '' : ' <span class="badge text-bg-secondary">fee turned off</span>') : '<span class="small text-muted">No price yet</span>' ?></td>
      <?php if ($split): ?><td class="small"><?= $f ? h(LecLab::BASIS[$f['unit_basis'] ?? 'ALL'] . ' (' . LecLab::text($r['basis_units']) . ')') : '' ?></td><?php endif; ?>
      <td class="text-end">₱<?= Money::fmt($r['rate_cents']) ?></td>
      <td class="text-end fw-semibold">₱<?= Money::fmt($r['total_cents']) ?></td>
      <td class="small"><?php if (!$f): ?><span class="text-muted">—</span><?php elseif (!$f['templates']): ?><span class="text-warning-emphasis">None ticked</span> <span class="text-muted">(charged only without a template)</span><?php else: ?><?= h(implode(', ', array_column($f['templates'], 'name'))) ?><?php endif; ?></td>
      <td class="text-end text-nowrap d-print-none"><?php if ($canEdit): ?>
        <?php if ($f): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url('subjectfees', ['edit' => $f['id']])) ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
          <form method="post" class="d-inline" data-confirm="Remove the <?= h($f['label_name']) ?> price of <?= h($r['subject_code'] ?: $r['subject_name']) ?>? The subject goes back to ₱0.00."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Remove price"><i class="bi bi-x-lg"></i></button></form>
        <?php else: ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url('subjectfees', ['subject' => $r['subject_doc_id']])) ?>"><i class="bi bi-pencil me-1"></i>Edit</a><?php endif; ?>
      <?php endif; ?></td>
    </tr><?php endforeach; if (!$pageRows && $fq !== ''): ?><tr><td colspan="<?= $split ? 8 : 7 ?>" class="text-center text-muted py-4">No subject matches “<?= h($fq) ?>”.</td></tr><?php endif; if (!$rows): ?><tr><td colspan="<?= $split ? 8 : 7 ?>" class="text-center text-muted py-4">No subjects yet. Add subjects in Curriculum → Subjects (or in Grading → Subjects); they appear here at ₱0.00.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?= RecordList::pagerHtml($win, 'subjectfees', ['q' => $fq], 'subjects') ?>
    <p class="small text-muted mt-2">Confirmed enrollments keep the assessment they were confirmed with. Open assessments without payments are recalculated when a price changes; the rest are recalculated when the registrar saves them.</p>
  </div>
</div>
<script src="assets/fee-manage.js?v=56b" defer></script>
<?php layout_end();
