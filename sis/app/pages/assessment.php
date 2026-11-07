<?php
/** Assessment: build what a student pays — Categories → Labels → Names (fee templates). */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$tab = in_array($_GET['tab'] ?? '', ['categories', 'labels', 'names'], true) ? $_GET['tab'] : 'categories';
$canEdit = Auth::can('assessment.edit');
$nid = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
if ($isPost) {
    if(($_POST['action']??'')==='toggle_label'){Auth::require('assessment.edit');$labelId=(int)($_POST['label_id']??0);Db::tx(function()use($labelId){$label=Db::one('SELECT id,is_active FROM sis_fee_labels WHERE id=? FOR UPDATE',[$labelId]);if(!$label)throw new AppError('Select an existing fee label.');Db::q('UPDATE sis_fee_labels SET is_active=? WHERE id=?',[$label['is_active']?0:1,$labelId]);Audit::log('label_status_changed','label',$labelId);});flash('success','Label status changed.');redirect('assessment',['tab'=>'labels']);}
    if(($_POST['action']??'')==='payment_plan'){PaymentPlans::save($_POST);flash('success','Payment plan saved. Existing student plans keep their terms.');redirect('assessment',['tab'=>'labels','plan'=>'list']);}
    $a = $_POST['action'] ?? '';
    $backToPopup = !empty($_POST['from_popup']) ? ['manage' => '1'] : []; // reopen the template's Manage fees pop-up
    if ($a === 'import') { FeeImport::handleUpload($tab); redirect('assessment', ['tab' => $tab]); }
    if ($a === 'category') { Assessment::saveCategory(In::int($_POST, 'id'), $_POST); flash('success', 'Category saved.'); redirect('assessment', ['tab' => 'categories']); }
    if ($a === 'label') { Assessment::saveLabel(In::int($_POST, 'id'), $_POST); flash('success', 'Label saved.'); redirect('assessment', ['tab' => 'labels']); }
    if ($a === 'name') { $isNew = !In::int($_POST, 'id'); $id = Assessment::saveName(In::int($_POST, 'id'), $_POST); flash('success', $isNew ? 'Fee template created. Tick the fees it charges, then save.' : 'Fee template saved.'); redirect('assessment', ['tab' => 'names', 'id' => $id] + ($isNew ? ['manage' => '1'] : [])); }
    if ($a === 'item') {
        $lbl = Db::one('SELECT * FROM sis_fee_labels WHERE id = ?', [(int) In::int($_POST, 'label_id', true, 'Label')]);
        if (!$lbl) throw new AppError('Label not found.');
        $amt = In::money($_POST, 'amount', false, 'Amount');
        Assessment::setItem((int) $nid, (int) $lbl['id'], (string) (($_POST['charge_type'] ?? '') ?: $lbl['charge_type']), $amt ?? Money::cents($lbl['default_amount']));
        flash('success', 'Fee line saved.'); redirect('assessment', ['tab' => 'names', 'id' => $nid] + $backToPopup);
    }
    if ($a === 'remove_item') { Assessment::removeItem((int) $nid, (int) $_POST['item_id']); flash('success', 'Fee line removed.'); redirect('assessment', ['tab' => 'names', 'id' => $nid] + $backToPopup); }
    // Subject fees of this template: only students assessed with it pay them
    if ($a === 'subject_fee') { SubjectFees::save(['assessment_name_id' => (string) $nid] + array_diff_key($_POST, ['id' => 1, 'templates' => 1, 'templates_sent' => 1])); flash('success', 'Subject fee saved for this template.'); redirect('assessment', ['tab' => 'names', 'id' => $nid] + $backToPopup); }
    // v56: the Fee Management prices this template charges, ticked by hand
    if ($a === 'subject_ticks') { $t = SubjectFees::setTicks((int) $nid, (array) ($_POST['subject_fee_ids'] ?? [])); flash('success', $t['ticked'] . ' subject fee(s) ticked for this template.' . ($t['recomputed'] ? ' ' . $t['recomputed'] . ' open assessment(s) recalculated.' : '') . ($t['skipped'] ? ' ' . $t['skipped'] . ' open assessment(s) with payments kept the old amount.' : '')); redirect('assessment', ['tab' => 'names', 'id' => $nid] + $backToPopup); }
    if ($a === 'remove_subject_fee') {
        $fid = (int) ($_POST['subject_fee_id'] ?? 0);
        if (!Db::val('SELECT 1 FROM sis_subject_fees WHERE id = ? AND assessment_name_id = ?', [$fid, $nid])) throw new AppError('That subject fee is not part of this template.');
        SubjectFees::delete($fid); flash('success', 'Subject fee removed from this template.'); redirect('assessment', ['tab' => 'names', 'id' => $nid] + $backToPopup);
    }
    // v56.1: one list of every fee (general and subject fees): tick to charge, ticked fees show their price
    if ($a === 'template_fees') { $t = TemplateFees::save((int) $nid, $_POST); flash('success', "Fees saved: {$t['general']} general fee(s) and {$t['subjects']} subject fee(s) are charged by this template." . ($t['recomputed'] ? " {$t['recomputed']} open assessment(s) recalculated." : '') . ($t['skipped'] ? " {$t['skipped']} open assessment(s) with payments kept the old amount." : '')); redirect('assessment', ['tab' => 'names', 'id' => $nid]); }
    if ($a === 'copy') { $id = Assessment::copyName((int) $nid, (string) ($_POST['new_name'] ?? '')); flash('success', 'Copied.'); redirect('assessment', ['tab' => 'names', 'id' => $id]); }
    throw new AppError('Unknown action.');
}
if (isset($_GET['template'])) Xlsx::download('sis-' . $tab . '-template.xlsx', FeeImport::template($tab));
$dis = $canEdit ? '' : ' disabled';
if($tab==='labels'&&($_GET['export']??'')==='labels'){header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="AcadLink_fee_labels.csv"');$out=fopen('php://output','w');fputcsv($out,['Category','Label','Charge type','Default amount','Active']);foreach(Assessment::labels() as $l){$text=fn($v)=>preg_match('/^[=+@\-]/',(string)$v)?"'".$v:(string)$v;fputcsv($out,[$text($l['category_name']),$text($l['name']),$l['charge_type'],$l['default_amount'],$l['is_active']?'Yes':'No']);}fclose($out);exit;}
layout_start('Assessment');
$pageTitle = ['categories' => 'Assessment Categories', 'labels' => 'Assessment Labels', 'names' => 'Fee Templates'][$tab];
$pageDescription = ['categories' => 'Organize fee categories used in student assessments.', 'labels' => 'Manage fee lines, charge types and default amounts.', 'names' => 'Manage assessment fee templates for programs, year levels, and terms.'][$tab];
$pageActions = '';
if ($canEdit) {
    $pageActions = $tab === 'names'
        ? '<button type="button" class="btn btn-primary" data-open-template-editor><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New template</button>'
        : '<a class="btn btn-primary" href="#add' . ucfirst($tab) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add ' . h(['categories' => 'category', 'labels' => 'label'][$tab]) . '</a>';
}
page_title($pageTitle, $pageActions, $pageDescription);
?>
<div class="d-flex flex-wrap gap-2 justify-content-end mb-2"><?php $impKind = $tab; $impUrl = url('assessment', ['tab' => $tab]); require __DIR__ . '/../views/import_excel.php'; ?></div>
<?php if ($tab === 'names'): ?>
<nav class="fee-setup-nav" aria-label="Finance setup">
  <span class="fee-setup-nav-label"><i class="bi bi-sliders" aria-hidden="true"></i>Finance setup</span>
  <a href="<?= url('assessment', ['tab' => 'categories']) ?>"><i class="bi bi-tags" aria-hidden="true"></i>Categories</a>
  <a href="<?= url('assessment', ['tab' => 'labels']) ?>"><i class="bi bi-tag" aria-hidden="true"></i>Fee labels</a>
  <a class="is-active" href="<?= url('assessment', ['tab' => 'names']) ?>" aria-current="page"><i class="bi bi-card-list" aria-hidden="true"></i>Fee templates</a>
  <?php if (Auth::can('assessment')): ?><a href="<?= url('subjectfees') ?>"><i class="bi bi-journal-plus" aria-hidden="true"></i>Subject fees</a><?php endif; ?>
  <?php if (Auth::can('assessment') || Auth::can('discounts.manage')): ?><a href="<?= url('discounts') ?>"><i class="bi bi-award" aria-hidden="true"></i>Scholarships &amp; discounts</a><?php endif; ?>
  <?php if (Auth::can('assessment')): ?><a href="<?= url('assessment', ['tab' => 'labels', 'plan' => 'list']) ?>"><i class="bi bi-calendar3" aria-hidden="true"></i>Payment setup</a><?php endif; ?>
</nav>
<?php else: ?>
<ul class="nav nav-tabs mb-3">
<?php foreach (['categories' => 'Categories', 'labels' => 'Labels', 'names' => 'Fee Templates'] as $k => $l): ?><li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= url('assessment', ['tab' => $k]) ?>"><?= $l ?></a></li><?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($tab === 'categories'): $rows = RecordList::rows(fn()=>Assessment::categories()); $e = isset($_GET['edit']) ? Db::one('SELECT * FROM sis_fee_categories WHERE id = ?', [(int) $_GET['edit']]) : null; ?>
<div class="row g-3"><div class="col-lg-8"><div class="table-responsive bg-white border rounded"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Order</th><th>Category</th><th>Description</th><th>Labels</th><th>Active</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td><?= h($r['sort_order']) ?></td><td class="fw-semibold"><?= h($r['name']) ?></td><td class="small"><?= h($r['description']) ?></td><td><?= (int) $r['labels'] ?></td><td><?= $r['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
  <td class="text-end"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('assessment', ['tab' => 'categories', 'edit' => $r['id']]) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No categories yet. Choose Add category to begin.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if ($canEdit): ?><div class="col-lg-4"><form method="post" class="form-section" id="addCategories"><?= csrf_field() ?><input type="hidden" name="action" value="category"><input type="hidden" name="id" value="<?= h($e['id'] ?? '') ?>">
  <h2 class="h6"><?= $e ? 'Edit category' : 'Add a category' ?></h2>
  <input class="form-control form-control-sm mb-2" name="name" required maxlength="80" placeholder="Category name (e.g. Miscellaneous)" value="<?= h($e['name'] ?? '') ?>">
  <input class="form-control form-control-sm mb-2" name="description" maxlength="255" placeholder="Description" value="<?= h($e['description'] ?? '') ?>">
  <label class="form-label small">Order on the assessment</label><input class="form-control form-control-sm mb-2" type="number" name="sort_order" min="1" max="999" value="<?= h($e['sort_order'] ?? 100) ?>">
  <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="ca" <?= ($e['is_active'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label small" for="ca">Active</label></div>
  <button class="btn btn-sm btn-primary">Save</button><?php if ($e): ?> <a class="btn btn-sm btn-link" href="<?= url('assessment', ['tab' => 'categories']) ?>">Add instead</a><?php endif; ?>
</form></div><?php endif; ?></div>

<?php elseif ($tab === 'labels'): $rows = RecordList::rows(fn()=>Assessment::labels()); $e = isset($_GET['edit']) ? Db::one('SELECT * FROM sis_fee_labels WHERE id = ?', [(int) $_GET['edit']]) : null; ?>
<div class="row g-3"><div class="col-lg-8">
<input class="form-control form-control-sm mb-2" data-filter="#labels" placeholder="Search labels">
<div class="table-responsive bg-white border rounded"><table class="table table-sm mb-0" id="labels"><thead class="table-light"><tr><th>Category</th><th>Label</th><th>Charged</th><th class="money">Default amount</th><th>Active</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr data-label-id="<?= (int)$r['id'] ?>" data-label-name="<?= h($r['name']) ?>" data-label-active="<?= (int)$r['is_active'] ?>" class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td><?= h($r['category_name']) ?></td><td class="fw-semibold"><?= h($r['name']) ?><div class="small text-muted"><?= h($r['description']) ?></div></td><td class="small"><?= h(CHARGE_TYPES[$r['charge_type']]) ?></td>
  <td class="money"><?= Money::fmt(Money::cents($r['default_amount'])) ?></td><td><?= $r['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
  <td class="text-end"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('assessment', ['tab' => 'labels', 'edit' => $r['id']]) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-muted text-center py-4">No labels yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if ($canEdit): ?><div class="col-lg-4"><form method="post" class="form-section" id="addLabels"><?= csrf_field() ?><input type="hidden" name="action" value="label"><input type="hidden" name="id" value="<?= h($e['id'] ?? '') ?>">
  <h2 class="h6"><?= $e ? 'Edit label' : 'Add a label (fee line)' ?></h2>
  <label class="form-label small">Category</label><select class="form-select form-select-sm mb-2" name="category_id" required><?= opt(Assessment::categories(), 'id', 'name', $e['category_id'] ?? null, '— choose —') ?></select>
  <input class="form-control form-control-sm mb-2" name="name" required maxlength="100" placeholder="Label (e.g. Library Fee)" value="<?= h($e['name'] ?? '') ?>">
  <input class="form-control form-control-sm mb-2" name="description" maxlength="255" placeholder="Description" value="<?= h($e['description'] ?? '') ?>">
  <div class="row g-2 mb-2"><div class="col-7"><label class="form-label small">Charged</label><select class="form-select form-select-sm" name="charge_type"><?php foreach (CHARGE_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= ($e['charge_type'] ?? 'FIXED') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-5"><label class="form-label small">Default ₱</label><input class="form-control form-control-sm" name="default_amount" value="<?= h($e['default_amount'] ?? '0.00') ?>"></div></div>
  <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="la" <?= ($e['is_active'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label small" for="la">Active</label></div>
  <button class="btn btn-sm btn-primary">Save</button><?php if ($e): ?> <a class="btn btn-sm btn-link" href="<?= url('assessment', ['tab' => 'labels']) ?>">Add instead</a><?php endif; ?>
  <div class="form-text">Per unit = amount × the student's units; per subject = amount × number of subjects.</div>
</form></div><?php endif; ?></div>

<?php else: require __DIR__ . '/../views/assessment-fee-templates.php'; ?>
<?php endif; ?>
<?php if($tab==='labels')require __DIR__.'/../views/payment-plan-setup.php';
layout_end();
