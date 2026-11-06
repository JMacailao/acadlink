<?php
/** Scholarships & Discounts: the programs (what they take off), and who received them. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$tab = ($_GET['tab'] ?? '') === 'grantees' ? 'grantees' : 'programs';
$canEdit = Auth::can('discounts.manage');
if ($isPost) {
    if (($_POST['action'] ?? '') === 'import') { FeeImport::handleUpload('discounts'); redirect('discounts'); }
    $id = Discounts::save(In::int($_POST, 'id'), $_POST);
    flash('success', 'Program saved.');
    redirect('discounts');
}
if (isset($_GET['template'])) Xlsx::download('sis-scholarships-discounts-template.xlsx', FeeImport::template('discounts'));
$cats = Assessment::categories();
layout_start('Scholarships & Discounts');
page_title('Scholarships & Discounts', $canEdit && $tab === 'programs' ? '<a class="btn btn-primary" href="#addDiscount"><i class="bi bi-plus-lg me-1"></i>Add discount</a>' : '', 'Programs that take an amount off a student’s assessment: a percentage or a fixed amount, off the whole assessment or off chosen fee categories. Give them to a student on the registration.');
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'programs' ? ' active' : '' ?>" href="<?= url('discounts') ?>">Programs</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'grantees' ? ' active' : '' ?>" href="<?= url('discounts', ['tab' => 'grantees']) ?>">Grantees</a></li>
</ul>
<?php if ($tab === 'programs'): ?>
<div class="d-flex flex-wrap gap-2 justify-content-end mb-2"><?php $impKind = 'discounts'; $impUrl = url('discounts'); require __DIR__ . '/../views/import_excel.php'; ?></div>
<?php
    $rows = RecordList::rows(fn()=>Discounts::all());
    $e = isset($_GET['edit']) ? Db::one('SELECT * FROM sis_discounts WHERE id = ?', [(int) $_GET['edit']]) : null;
    $eCats = $e ? array_keys(Discounts::categoriesOf((int) $e['id'])) : []; ?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="table-responsive bg-white border rounded"><table class="table table-sm table-hover mb-0">
      <thead class="table-light"><tr><th>Program</th><th>Type</th><th>Takes off</th><th class="text-end">Given</th><th>Active</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $d): ?><tr class="<?= $d['is_active'] ? '' : 'text-muted' ?>">
        <td><strong><?= h($d['name']) ?></strong><?= $d['description'] ? '<div class="small text-muted">' . h($d['description']) . '</div>' : '' ?></td>
        <td><span class="badge <?= $d['kind'] === 'SCHOLARSHIP' ? 'text-bg-info' : 'text-bg-warning' ?>"><?= h(Discounts::KINDS[$d['kind']]) ?></span></td>
        <td class="small"><?= h(Discounts::describe($d)) ?></td>
        <td class="text-end"><a href="<?= url('discounts', ['tab' => 'grantees', 'discount' => $d['id']]) ?>"><?= (int) $d['used'] ?></a></td>
        <td><?= $d['is_active'] ? badge('ACTIVE') : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
        <td class="text-end"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('discounts', ['edit' => $d['id']]) ?>">Edit</a><?php endif; ?></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No programs yet.<?= $canEdit ? ' Choose Add discount to create the first program, e.g. “Academic Scholarship · 100% of Tuition” or “Sibling Discount · 10% of the whole assessment”.' : '' ?></td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <?php if ($canEdit): ?>
  <div class="col-xl-4">
    <form method="post" class="form-section" id="addDiscount"><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= h($e['id'] ?? '') ?>">
      <h2 class="h6"><?= $e ? 'Edit program' : 'New program' ?></h2>
      <label class="form-label small" for="dName">Name</label><input class="form-control form-control-sm mb-2" id="dName" name="name" required maxlength="100" value="<?= h($e['name'] ?? '') ?>" placeholder="e.g. Academic Scholarship">
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small" for="dKind">Type</label><select class="form-select form-select-sm" id="dKind" name="kind"><?php foreach (Discounts::KINDS as $k => $l): ?><option value="<?= $k ?>"<?= ($e['kind'] ?? 'SCHOLARSHIP') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small" for="dMethod">Computation</label><select class="form-select form-select-sm" id="dMethod" name="method"><?php foreach (Discounts::METHODS as $k => $l): ?><option value="<?= $k ?>"<?= ($e['method'] ?? 'PERCENT') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small" for="dValue">Percentage or amount</label><input class="form-control form-control-sm" id="dValue" name="value" required inputmode="decimal" value="<?= h($e['value'] ?? '') ?>" placeholder="50 or 1000"></div>
        <div class="col-6"><label class="form-label small" for="dMax">Maximum ₱ (optional)</label><input class="form-control form-control-sm" id="dMax" name="max_amount" inputmode="decimal" value="<?= h($e['max_amount'] ?? '') ?>" placeholder="No limit"></div>
      </div>
      <div class="small fw-semibold mb-1">Applies to</div>
      <div class="border rounded p-2 mb-2">
        <div class="small text-muted mb-1">Tick fee categories, or none for the whole assessment.</div>
        <?php foreach ($cats as $c): ?><label class="form-check small"><input class="form-check-input" type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $eCats, true) ? ' checked' : '' ?>> <?= h($c['name']) ?></label><?php endforeach; ?>
      </div>
      <label class="form-label small" for="dDesc">Description (optional)</label><input class="form-control form-control-sm mb-2" id="dDesc" name="description" maxlength="255" value="<?= h($e['description'] ?? '') ?>" placeholder="e.g. GWA 1.50 or better, renewed each term">
      <label class="form-check small mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1"<?= ($e['is_active'] ?? 1) ? ' checked' : '' ?>> Active (can be given to students)</label>
      <div class="d-flex gap-2"><button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Save</button><?php if ($e): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('discounts') ?>">New program</a><?php endif; ?></div>
      <div class="form-text">Changing a program updates the assessments of open registrations the next time they are recomputed; confirmed enrollments keep their amounts.</div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php else:
    $sy = selected_sy();
    $term = in_array($_GET['term'] ?? '', TERMS, true) ? $_GET['term'] : '';
    $only = isset($_GET['discount']) && ctype_digit((string) $_GET['discount']) ? (int) $_GET['discount'] : null;
    $rows = RecordList::rows(fn()=>Discounts::grantees($sy,$term,$only));
    $byKind = [];
    foreach ($rows as $r) $byKind[$r['kind']] = ($byKind[$r['kind']] ?? 0) + $r['amount']; ?>
<form id="discountFilters" data-record-filters class="row g-2 mb-3 d-print-none" method="get"><input type="hidden" name="r" value="discounts"><input type="hidden" name="apply" value="1"><input type="hidden" name="tab" value="grantees">
  <div class="col-md-3"><select class="form-select" name="sy" data-autosubmit><?= sy_options($sy) ?></select></div>
  <div class="col-md-3"><select class="form-select" name="term" data-autosubmit><?= list_options(TERMS, $term, 'All terms') ?></select></div>
  <div class="col-md-4"><select class="form-select" name="discount" data-autosubmit><option value="">All programs</option><?= opt(Discounts::all(), 'id', 'name', $only) ?></select></div>
  <div class="col-md-2"><button type="button" class="btn btn-outline-secondary w-100" data-print><i class="bi bi-printer me-1"></i>Print</button></div>
</form>
<div class="d-flex flex-wrap gap-3 mb-2 small">
  <span>Grantees: <strong><?= count($rows) ?></strong></span>
  <?php foreach (Discounts::KINDS as $k => $l): ?><span><?= h($l) ?>s: <strong>₱<?= Money::fmt($byKind[$k] ?? 0) ?></strong></span><?php endforeach; ?>
  <span>Total: <strong>₱<?= Money::fmt(array_sum($byKind)) ?></strong></span>
</div>
<div class="table-responsive bg-white border rounded"><table class="table table-sm table-hover mb-0">
  <thead class="table-light"><tr><th>Program</th><th>Student</th><th>Term</th><th>Section</th><th>Remarks</th><th class="money">Amount off</th><th class="d-print-none"></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= h($r['name']) ?></td><td><?= h($r['student_name']) ?> <span class="small text-muted"><?= h($r['student_number']) ?></span></td>
    <td class="small"><?= h($r['term']) ?></td><td class="small"><?= h($r['section_name']) ?></td><td class="small"><?= h($r['remarks']) ?></td>
    <td class="money">₱<?= Money::fmt($r['amount']) ?></td><td class="text-end d-print-none"><a class="btn btn-sm btn-outline-secondary" href="<?= url('registration', ['id' => $r['registration_id']]) ?>">View</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No scholarships or discounts given in <?= h($sy . ($term ? " $term" : '')) ?>.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif;
layout_end();
