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
page_title(['categories' => 'Assessment Categories', 'labels' => 'Assessment Labels', 'names' => 'Assessment Names'][$tab], $canEdit ? '<a class="btn btn-primary" href="#add' . ucfirst($tab) . '"><i class="bi bi-plus-lg me-1"></i>Add ' . h(['categories' => 'category', 'labels' => 'label', 'names' => 'assessment name'][$tab]) . '</a>' : '', ['categories' => 'Organize fee categories used in student assessments.', 'labels' => 'Manage fee lines, charge types and default amounts.', 'names' => 'Manage fee templates used during registration.'][$tab]);
?>
<div class="d-flex flex-wrap gap-2 justify-content-end mb-2"><?php $impKind = $tab; $impUrl = url('assessment', ['tab' => $tab]); require __DIR__ . '/../views/import_excel.php'; ?></div>
<ul class="nav nav-tabs mb-3">
<?php foreach (['categories' => 'Categories', 'labels' => 'Labels', 'names' => 'Fee Templates'] as $k => $l): ?><li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= url('assessment', ['tab' => $k]) ?>"><?= $l ?></a></li><?php endforeach; ?>
</ul>
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

<?php else: $names = RecordList::rows(fn()=>Assessment::names()); $n = $nid ? Db::one('SELECT * FROM sis_assessment_names WHERE id = ?', [$nid]) : null; $problems = Assessment::setupProblems(); ?>
<?php if ($problems): ?>
<div class="alert alert-warning py-2 small"><strong><i class="bi bi-exclamation-triangle me-1"></i>Check the fee setup</strong>
  <ul class="mb-0 ps-3"><?php foreach ($problems as $pb): ?><li><?= h($pb) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="row g-3">
<div class="col-12">
  <div class="table-responsive bg-white border rounded mb-3"><div class="directory-head"><h2>Fee templates</h2><p><?= count($names) ?> template(s)</p><input class="form-control directory-search" data-filter="#feeTemplates" placeholder="Search fee templates…" aria-label="Search fee templates"></div><table id="feeTemplates" class="table table-hover mb-0"><thead><tr><th>Assessment name</th><th>Program</th><th>Year level</th><th>Term</th><th>Fee lines</th><th class="text-end">Actions</th></tr></thead><tbody>
  <?php foreach ($names as $r): ?><tr><td class="fw-semibold"><?= h($r['name']) ?><?= $r['is_active'] ? '' : ' <span class="badge text-bg-secondary">Inactive</span>' ?></td><td><?= h($r['program'] ?: 'Any') ?></td><td><?= h($r['year_level'] ?: 'Any') ?></td><td><?= h($r['term'] ?: 'Any') ?></td><td><?= (int) $r['items'] ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('assessment', ['tab' => 'names', 'id' => $r['id']] + ($canEdit ? ['manage' => '1'] : [])) ?>">Manage fees</a><?php if ($canEdit): ?> <a class="btn btn-sm btn-outline-secondary" href="<?= url('assessment', ['tab' => 'names', 'id' => $r['id'], 'editName' => '1']) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$names): ?><tr><td colspan="6" class="text-center text-muted py-4">No fee templates yet. Choose Add name to create one.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($canEdit): ?><form method="post" class="form-section" id="addNames"><?= csrf_field() ?><input type="hidden" name="action" value="name"><input type="hidden" name="id" value="<?= h($n['id'] ?? '') ?>">
    <h2 class="h6"><?= $n ? 'Edit name' : 'New assessment name' ?></h2>
    <input class="form-control form-control-sm mb-2" name="name" required maxlength="120" placeholder="e.g. BSIT 1st Year 1st Semester 2026-2027" value="<?= h($n['name'] ?? '') ?>">
    <div class="small text-muted mb-1">Used for (blank = any) — Registration picks the most specific match:</div>
    <div class="row g-2 mb-2"><div class="col-6"><input class="form-control form-control-sm" name="program" list="programs" maxlength="40" placeholder="Program" value="<?= h($n['program'] ?? '') ?>"></div>
      <div class="col-6"><select class="form-select form-select-sm" name="year_level"><?= list_options(YEAR_LEVELS, $n['year_level'] ?? null, 'Any year level') ?></select></div>
      <div class="col-6"><select class="form-select form-select-sm" name="term"><?= list_options(TERMS, $n['term'] ?? null, 'Any term') ?></select></div>
      <div class="col-6"><input class="form-control form-control-sm" name="school_year" maxlength="9" placeholder="Any school year" value="<?= h($n['school_year'] ?? '') ?>"></div></div>
    <datalist id="programs"><?php foreach (GS::programs() as $p): ?><option><?= h($p) ?></option><?php endforeach; ?></datalist>
    <input class="form-control form-control-sm mb-2" name="notes" maxlength="255" placeholder="Notes" value="<?= h($n['notes'] ?? '') ?>">
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="na" <?= ($n['is_active'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label small" for="na">Active</label></div>
    <button class="btn btn-sm btn-primary">Save</button><?php if ($n): ?> <a class="btn btn-sm btn-link" href="<?= url('assessment', ['tab' => 'names']) ?>">New instead</a><?php endif; ?>
  </form>
<?php endif; ?>
  <?php if ($n && $canEdit): $tf = TemplateFees::rows((int) $nid); ?>
  <dialog class="record-dialog fee-dialog" id="tplManage" aria-labelledby="tplManageTitle">
    <div class="record-dialog-head"><div><p class="record-eyebrow">Manage fees</p><h2 id="tplManageTitle"><?= h($n['name']) ?></h2></div><button type="button" class="btn-close" data-close aria-label="Close"></button></div>
    <form method="post" class="fee-dialog-body" data-template-fees><?= csrf_field() ?><input type="hidden" name="action" value="template_fees">
      <p class="small text-muted mb-2">Tick every fee this template charges. A ticked fee shows its price. Untick a fee to stop charging it. General fee amounts can be changed here. Subject fee prices are the same in every template: change them in Assessment → Subject Fees.</p>
      <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <input type="search" class="form-control form-control-sm" style="max-width:280px" data-tf-search placeholder="Search fees or subjects…" aria-label="Search fees or subjects">
        <label class="form-check form-switch small mb-0"><input class="form-check-input" type="checkbox" data-tf-only> <span class="form-check-label">Show ticked only</span></label>
      </div>

      <div class="fee-dialog-section"><h3>General fees <span>· registration, miscellaneous, tuition per unit…</span></h3><span class="small text-muted"><button type="button" class="btn btn-link btn-sm p-0" data-tf-all="gen" data-on="1">Tick all</button> · <button type="button" class="btn btn-link btn-sm p-0" data-tf-all="gen" data-on="0">Untick all</button></span></div>
      <div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0 tf-table" data-tf-group="gen"><thead><tr><th style="width:2.2rem"><span class="visually-hidden">Charge</span></th><th>Fee</th><th>Charged</th><th class="money" style="width:150px">Amount (₱)</th></tr></thead><tbody>
      <?php foreach ($tf['general'] as $g): $k = (int) $g['label_id']; ?><tr data-tf-row class="<?= $g['on'] ? 'is-on' : '' ?>">
        <td><input class="form-check-input" type="checkbox" name="gen[<?= $k ?>][on]" value="1" id="tfg<?= $k ?>"<?= $g['on'] ? ' checked' : '' ?>></td>
        <td><label for="tfg<?= $k ?>"><?= h($g['name']) ?> <span class="small text-muted">· <?= h($g['category']) ?></span><?= $g['active'] ? '' : ' <span class="badge text-bg-secondary">fee turned off</span>' ?></label></td>
        <td><select class="form-select form-select-sm" name="gen[<?= $k ?>][charge]" data-tf-price aria-label="How <?= h($g['name']) ?> is charged"><?php foreach (CHARGE_TYPES as $ck => $cl): ?><option value="<?= $ck ?>"<?= $g['charge_type'] === $ck ? ' selected' : '' ?>><?= h($cl) ?></option><?php endforeach; ?></select></td>
        <td><input class="form-control form-control-sm text-end" name="gen[<?= $k ?>][amount]" data-tf-price inputmode="decimal" value="<?= h(Money::fmt($g['amount_cents'])) ?>" placeholder="<?= h(Money::fmt($g['default_cents'])) ?>" aria-label="Amount of <?= h($g['name']) ?>"></td>
      </tr><?php endforeach; if (!$tf['general']): ?><tr><td colspan="4" class="text-muted small">No fees yet. Add them in Assessment → Labels.</td></tr><?php endif; ?>
      </tbody></table></div>

      <div class="fee-dialog-section"><h3>Subject fees <span>· charged only to students who take the subject (units × price per unit)</span></h3><span class="small text-muted"><button type="button" class="btn btn-link btn-sm p-0" data-tf-all="sub" data-on="1">Tick all</button> · <button type="button" class="btn btn-link btn-sm p-0" data-tf-all="sub" data-on="0">Untick all</button></span></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0 tf-table" data-tf-group="sub"><thead><tr><th style="width:2.2rem"><span class="visually-hidden">Charge</span></th><th>Subject</th><th>Fee</th><th class="money">Price / unit</th><th class="money">Total</th></tr></thead><tbody>
      <?php foreach ($tf['subjects'] as $sf): $k = h($sf['key']); ?><tr data-tf-row data-units="<?= h($sf['units']) ?>" data-normal="<?= h(Money::dec($sf['price_cents'] ?? $sf['normal_cents'] ?? 0)) ?>" class="<?= $sf['on'] ? 'is-on' : '' ?>">
        <td><input class="form-check-input" type="checkbox" name="sub[<?= $k ?>][on]" value="1" id="tfs<?= $k ?>"<?= $sf['on'] ? ' checked' : '' ?>></td>
        <td><label for="tfs<?= $k ?>"><span class="font-monospace"><?= h($sf['subject_code']) ?></span> <span class="small text-muted"><?= h($sf['subject_name']) ?> · <?= h($sf['units']) ?> units</span></label></td>
        <td class="small"><?= h($sf['fee']) ?></td>
        <td class="money"><?= Money::fmt($sf['price_cents'] ?? $sf['normal_cents'] ?? 0) ?><?= $sf['price_cents'] !== null ? '<div class="small text-muted">older price of this template</div>' : '' ?></td>
        <td class="money fw-semibold" data-tf-total></td>
      </tr><?php endforeach; if (!$tf['subjects']): ?><tr><td colspan="5" class="text-muted small">No subject prices yet. Set them in Assessment → Subject Fees (every subject starts at ₱0.00).</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="fee-dialog-foot tf-foot"><span class="small me-auto" data-tf-count aria-live="polite"></span><button type="button" class="btn btn-outline-secondary" data-close>Close</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save fees</button></div>
    </form>
  </dialog>
  <script src="assets/fee-manage.js?v=56b" defer></script>
  <?php endif; ?>
</div>
<div class="col-12" data-record-detail><?php if ($n): $items = Assessment::items($nid);
  $pu = is_numeric($_GET['units'] ?? null) ? (float) $_GET['units'] : 21.0; $ps = ctype_digit((string) ($_GET['subjects'] ?? '')) ? (int) $_GET['subjects'] : 7;
  $preview = Assessment::compute($nid, $pu, $ps); ?>
  <div class="form-section"><div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h2 class="h6 me-auto mb-0">General fees of “<?= h($n['name']) ?>”</h2><?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-primary" data-open="tplManage"><i class="bi bi-sliders me-1"></i>Manage fees</button><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Category</th><th>Label</th><th>Charged</th><th class="money">Amount</th><th></th></tr></thead><tbody>
    <?php foreach ($items as $i): ?><tr><td><?= h($i['category_name']) ?></td><td><?= h($i['label_name']) ?></td><td class="small"><?= h(CHARGE_TYPES[$i['charge_type']]) ?></td><td class="money"><?= Money::fmt(Money::cents($i['amount'])) ?></td>
      <td></td></tr><?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="5" class="text-muted">No fees ticked yet.<?= $canEdit ? ' Choose Manage fees and tick the fees this template charges.' : '' ?></td></tr><?php endif; ?></tbody></table></div>
    <?php $tplFees = SubjectFees::all($nid); $tickIds = array_flip(SubjectFees::ticks((int) $nid)); $ownKeys = array_flip(array_map(fn($f) => $f['subject_doc_id'] . '|' . $f['label_id'], $tplFees)); $allFees = array_values(array_filter(SubjectFees::all(), fn($f) => $f['assessment_name_id'] === null && isset($tickIds[(int) $f['id']]) && !isset($ownKeys[$f['subject_doc_id'] . '|' . $f['label_id']]))); ?>
    <h3 class="h6 mt-3 mb-1">Subject fees</h3>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Subject</th><th>Fee</th><th>Price</th><th class="money">Per unit</th><th class="money">For the subject</th></tr></thead><tbody>
    <?php foreach (array_merge($tplFees, $allFees) as $f): $u = (float) ($f['subject_units'] ?? 0); ?><tr><td><span class="font-monospace"><?= h($f['subject_code']) ?></span> <span class="small text-muted"><?= h($f['subject_name']) ?> · <?= h((string) $u) ?> units</span></td>
      <td><?= h($f['label_name']) ?> <span class="small text-muted">· <?= h($f['category_name']) ?></span></td><td class="small"><?= $f['assessment_name_id'] === null ? 'Normal price' : 'Price for this template' ?></td>
      <td class="money"><?= Money::fmt(Money::cents($f['rate'])) ?></td><td class="money"><?= Money::fmt(Money::times(Money::cents($f['rate']), (string) $u)) ?></td></tr><?php endforeach; ?>
    <?php if (!$tplFees && !$allFees): ?><tr><td colspan="5" class="text-muted">No subject fees ticked.<?= $canEdit ? ' Choose Manage fees to tick the subject prices this template charges.' : '' ?></td></tr><?php endif; ?></tbody></table></div>
    <p class="small text-muted mb-2">A subject fee is added to a student's assessment only when the student takes that subject (units × price per unit), and only when it is ticked for this template (Manage fees). This template's own price replaces the Fee Management price for the same subject and fee.</p>

  </div>
  <div class="form-section"><div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h2 class="h6 me-auto mb-0">Preview</h2>
    <form method="get" class="d-flex flex-wrap gap-1 align-items-center"><input type="hidden" name="r" value="assessment"><input type="hidden" name="tab" value="names"><input type="hidden" name="id" value="<?= $nid ?>">
      <input class="form-control form-control-sm" style="width:80px" name="units" value="<?= h($pu) ?>"><span class="small">units</span><input class="form-control form-control-sm" style="width:70px" name="subjects" value="<?= h($ps) ?>"><span class="small">subjects</span><button class="btn btn-sm btn-outline-secondary">Show</button></form></div>
    <table class="table table-sm mb-0"><tbody><?php $cat = null; foreach ($preview as $l): if ($l['category_name'] !== $cat): $cat = $l['category_name']; ?><tr class="table-light"><td colspan="2" class="fw-semibold"><?= h($cat) ?></td></tr><?php endif; ?>
      <tr><td class="ps-3"><?= h($l['label_name']) ?><?= $l['charge_type'] !== 'FIXED' ? ' <span class="small text-muted">(' . h($l['quantity']) . ' × ' . Money::fmt($l['unit_cents']) . ')</span>' : '' ?></td><td class="money"><?= Money::fmt($l['amount_cents']) ?></td></tr><?php endforeach; ?>
      <tr class="fw-bold"><td>Total</td><td class="money">₱<?= Money::fmt(array_sum(array_column($preview, 'amount_cents'))) ?></td></tr></tbody></table></div>
  <?php if ($canEdit): ?><form method="post" class="form-section d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="copy">
    <input class="form-control form-control-sm" name="new_name" required maxlength="120" placeholder="Copy as… (new name)"><button class="btn btn-sm btn-outline-primary text-nowrap">Copy with fee lines</button></form><?php endif; ?>
<?php else: ?><div class="alert alert-info">Choose an assessment name, or create one, then add its fee lines (labels with amounts).</div><?php endif; ?></div>
</div>
<?php endif; ?>
<?php if($tab==='labels')require __DIR__.'/../views/payment-plan-setup.php';
layout_end();
