<?php
$allNames = RecordList::rows(fn() => Assessment::names(), false);
$names = RecordList::filter($allNames);
if ($nid === null && $names) $nid = (int) $names[0]['id'];
$n = $nid ? Db::one('SELECT * FROM sis_assessment_names WHERE id = ?', [$nid]) : null;
$editor = !empty($_GET['editName']) && $n ? $n : null;
$problems = Assessment::setupProblems();
?>
<section class="fee-template-page" data-fee-template-page data-edit-name="<?= $editor ? '1' : '0' ?>">
  <?php if ($problems): ?>
  <div class="alert alert-warning py-2 small"><strong><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Check the fee setup</strong>
    <ul class="mb-0 ps-3"><?php foreach ($problems as $problem): ?><li><?= h($problem) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <div class="fee-template-workspace">
    <section class="fee-template-list-card" aria-labelledby="feeTemplateListTitle">
      <header class="fee-template-card-head">
        <div><h2 id="feeTemplateListTitle">Templates</h2><p><?= count($names) ?> <?= count($names) === 1 ? 'template' : 'templates' ?></p></div>
      </header>
      <label class="fee-template-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <span class="visually-hidden">Search templates</span>
        <input class="form-control" type="search" data-filter="#feeTemplates" placeholder="Search templates…" aria-label="Search templates">
      </label>
      <div class="table-responsive fee-template-table-wrap">
        <table id="feeTemplates" class="table fee-template-table mb-0">
          <thead><tr><th scope="col">Template</th><th scope="col">Program / scope</th><th scope="col" class="text-end">Fees</th><th scope="col">Status</th></tr></thead>
          <tbody>
          <?php foreach ($names as $row): $rowId = (int) $row['id']; $isSelected = $n && $rowId === (int) $n['id']; ?>
            <tr class="<?= $isSelected ? 'is-selected' : '' ?>">
              <td><a class="fee-template-name" href="<?= url('assessment', ['tab' => 'names', 'id' => $rowId]) ?>"<?= $isSelected ? ' aria-current="page"' : '' ?>><?= h($row['name']) ?></a>
                <?php if (!empty($row['school_year'])): ?><span class="fee-template-year"><?= h($row['school_year']) ?></span><?php endif; ?>
              </td>
              <td><span class="fee-template-program"><?= h($row['program'] ?: 'Any program') ?></span><span class="fee-template-scope"><?= h($row['year_level'] ?: 'Any year level') ?> · <?= h($row['term'] ?: 'Any term') ?></span></td>
              <td class="text-end"><span class="fee-template-count"><?= (int) $row['items'] ?></span></td>
              <td><span class="fee-template-status <?= $row['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$names): ?>
            <tr><td colspan="4" class="fee-template-list-empty">
              <i class="bi bi-card-list" aria-hidden="true"></i>
              <strong><?= $allNames ? 'No templates match these filters' : 'No fee templates yet' ?></strong>
              <span><?= $allNames ? 'Clear the filters above or try a different search.' : 'Create a template to define the fees used during registration.' ?></span>
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <div class="fee-template-detail" data-record-detail>
      <?php if ($n):
        $items = Assessment::items((int) $nid);
        $tplFees = SubjectFees::all((int) $nid);
        $tickIds = array_flip(SubjectFees::ticks((int) $nid));
        $ownKeys = array_flip(array_map(fn($fee) => $fee['subject_doc_id'] . '|' . $fee['label_id'], $tplFees));
        $allFees = array_values(array_filter(SubjectFees::all(), fn($fee) => $fee['assessment_name_id'] === null && isset($tickIds[(int) $fee['id']]) && !isset($ownKeys[$fee['subject_doc_id'] . '|' . $fee['label_id']])));
        $pu = is_numeric($_GET['units'] ?? null) ? (float) $_GET['units'] : 21.0;
        $ps = ctype_digit((string) ($_GET['subjects'] ?? '')) ? (int) $_GET['subjects'] : 7;
        $preview = Assessment::compute((int) $nid, $pu, $ps);
      ?>
      <article class="fee-template-detail-card">
        <header class="fee-template-detail-head">
          <div class="min-w-0">
            <h2><?= h($n['name']) ?><span class="fee-template-status <?= $n['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $n['is_active'] ? 'Active' : 'Inactive' ?></span></h2>
            <p><?= h($n['program'] ?: 'Any program') ?><span>·</span><?= h($n['year_level'] ?: 'Any year level') ?><span>·</span><?= h($n['term'] ?: 'Any term') ?><?php if (!empty($n['school_year'])): ?><span>·</span><?= h($n['school_year']) ?><?php endif; ?></p>
          </div>
          <div class="fee-template-detail-actions">
            <?php if ($canEdit): ?><button type="button" class="btn btn-primary" data-open="tplManage"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Manage fees</button>
            <a class="btn btn-outline-secondary" href="<?= url('assessment', ['tab' => 'names', 'id' => $nid, 'editName' => '1']) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a><?php endif; ?>
          </div>
        </header>

        <section class="fee-template-section" aria-labelledby="feeTemplateGeneralTitle">
          <div class="fee-template-section-head"><h3 id="feeTemplateGeneralTitle">General fees</h3><span><?= count($items) ?> <?= count($items) === 1 ? 'fee line' : 'fee lines' ?></span></div>
          <div class="table-responsive">
            <table class="table table-sm fee-template-detail-table mb-0">
              <thead><tr><th scope="col">Category</th><th scope="col">Label</th><th scope="col">Charged</th><th scope="col" class="text-end">Amount</th></tr></thead>
              <tbody>
              <?php foreach ($items as $item): ?><tr><td><?= h($item['category_name']) ?></td><td class="fw-semibold"><?= h($item['label_name']) ?></td><td><?= h(CHARGE_TYPES[$item['charge_type']]) ?></td><td class="text-end fee-template-money"><?= Money::fmt(Money::cents($item['amount'])) ?></td></tr><?php endforeach; ?>
              <?php if (!$items): ?><tr><td colspan="4" class="fee-template-empty-row">No general fees are included in this template yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="fee-template-section" aria-labelledby="feeTemplateSubjectTitle">
          <div class="fee-template-section-head"><h3 id="feeTemplateSubjectTitle">Subject fees</h3><span><?= count($tplFees) + count($allFees) ?> <?= count($tplFees) + count($allFees) === 1 ? 'subject fee' : 'subject fees' ?></span></div>
          <div class="table-responsive">
            <table class="table table-sm fee-template-detail-table mb-0">
              <thead><tr><th scope="col">Subject</th><th scope="col">Fee</th><th scope="col">Price</th><th scope="col" class="text-end">Per unit</th><th scope="col" class="text-end">For subject</th></tr></thead>
              <tbody>
              <?php foreach (array_merge($tplFees, $allFees) as $fee): $units = (float) ($fee['subject_units'] ?? 0); ?>
                <tr>
                  <td><span class="fee-template-subject-code"><?= h($fee['subject_code']) ?></span><span class="fee-template-subject-name"><?= h($fee['subject_name']) ?> · <?= h((string) $units) ?> units</span></td>
                  <td><?= h($fee['label_name']) ?></td>
                  <td><?= $fee['assessment_name_id'] === null ? 'Normal price' : 'Template price' ?></td>
                  <td class="text-end fee-template-money"><?= Money::fmt(Money::cents($fee['rate'])) ?></td>
                  <td class="text-end fee-template-money"><?= Money::fmt(Money::times(Money::cents($fee['rate']), (string) $units)) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$tplFees && !$allFees): ?><tr><td colspan="5" class="fee-template-empty-row">No subject fees are included in this template yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
          <p class="fee-template-note">A subject fee applies only when a student takes that subject. The template price replaces the regular subject-fee price for the same fee.</p>
        </section>

        <section class="fee-template-preview" aria-labelledby="feeTemplatePreviewTitle">
          <header class="fee-template-preview-head">
            <h3 id="feeTemplatePreviewTitle"><i class="bi bi-eye" aria-hidden="true"></i>Preview</h3>
            <form method="get" class="fee-template-preview-controls">
              <input type="hidden" name="r" value="assessment"><input type="hidden" name="tab" value="names"><input type="hidden" name="id" value="<?= (int) $nid ?>">
              <label for="feePreviewUnits">Units</label><input id="feePreviewUnits" class="form-control form-control-sm" type="number" min="0" step="any" name="units" value="<?= h($pu) ?>">
              <label for="feePreviewSubjects">Subjects</label><input id="feePreviewSubjects" class="form-control form-control-sm" type="number" min="0" step="1" name="subjects" value="<?= h($ps) ?>">
              <button class="btn btn-sm btn-outline-secondary">Update</button>
            </form>
          </header>
          <div class="table-responsive">
            <table class="table table-sm fee-template-preview-table mb-0"><tbody>
              <?php $category = null; foreach ($preview as $line): if ($line['category_name'] !== $category): $category = $line['category_name']; ?><tr class="fee-template-preview-category"><th colspan="2" scope="rowgroup"><?= h($category) ?></th></tr><?php endif; ?>
              <tr><td><?= h($line['label_name']) ?><?= $line['charge_type'] !== 'FIXED' ? ' <span class="fee-template-preview-calculation">(' . h($line['quantity']) . ' × ' . Money::fmt($line['unit_cents']) . ')</span>' : '' ?></td><td class="text-end fee-template-money"><?= Money::fmt($line['amount_cents']) ?></td></tr><?php endforeach; ?>
              <tr class="fee-template-total"><th scope="row">Total</th><td class="text-end">₱<?= Money::fmt(array_sum(array_column($preview, 'amount_cents'))) ?></td></tr>
            </tbody></table>
          </div>
        </section>

        <?php if ($canEdit): ?><form method="post" action="<?= url('assessment', ['tab' => 'names', 'id' => $nid]) ?>" class="fee-template-copy-form"><?= csrf_field() ?><input type="hidden" name="action" value="copy">
          <label for="feeTemplateCopyName">Copy template</label><div><input id="feeTemplateCopyName" class="form-control form-control-sm" name="new_name" required maxlength="120" placeholder="Enter a name for the copy"><button class="btn btn-outline-primary text-nowrap"><i class="bi bi-copy me-1" aria-hidden="true"></i>Copy with fee lines</button></div>
        </form><?php endif; ?>
      </article>

      <?php if ($canEdit): $tf = TemplateFees::rows((int) $nid); ?>
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
            <?php foreach ($tf['general'] as $fee): $key = (int) $fee['label_id']; ?><tr data-tf-row class="<?= $fee['on'] ? 'is-on' : '' ?>"><td><input class="form-check-input" type="checkbox" name="gen[<?= $key ?>][on]" value="1" id="tfg<?= $key ?>"<?= $fee['on'] ? ' checked' : '' ?>></td><td><label for="tfg<?= $key ?>"><?= h($fee['name']) ?> <span class="small text-muted">· <?= h($fee['category']) ?></span><?= $fee['active'] ? '' : ' <span class="badge text-bg-secondary">fee turned off</span>' ?></label></td><td><select class="form-select form-select-sm" name="gen[<?= $key ?>][charge]" data-tf-price aria-label="How <?= h($fee['name']) ?> is charged"><?php foreach (CHARGE_TYPES as $chargeKey => $chargeLabel): ?><option value="<?= $chargeKey ?>"<?= $fee['charge_type'] === $chargeKey ? ' selected' : '' ?>><?= h($chargeLabel) ?></option><?php endforeach; ?></select></td><td><input class="form-control form-control-sm text-end" name="gen[<?= $key ?>][amount]" data-tf-price inputmode="decimal" value="<?= h(Money::fmt($fee['amount_cents'])) ?>" placeholder="<?= h(Money::fmt($fee['default_cents'])) ?>" aria-label="Amount of <?= h($fee['name']) ?>"></td></tr><?php endforeach; ?>
            <?php if (!$tf['general']): ?><tr><td colspan="4" class="text-muted small">No fees yet. Add them in Assessment → Fee labels.</td></tr><?php endif; ?>
          </tbody></table></div>
          <div class="fee-dialog-section"><h3>Subject fees <span>· charged only to students who take the subject</span></h3><span class="small text-muted"><button type="button" class="btn btn-link btn-sm p-0" data-tf-all="sub" data-on="1">Tick all</button> · <button type="button" class="btn btn-link btn-sm p-0" data-tf-all="sub" data-on="0">Untick all</button></span></div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0 tf-table" data-tf-group="sub"><thead><tr><th style="width:2.2rem"><span class="visually-hidden">Charge</span></th><th>Subject</th><th>Fee</th><th class="money">Price / unit</th><th class="money">Total</th></tr></thead><tbody>
            <?php foreach ($tf['subjects'] as $fee): $key = h($fee['key']); ?><tr data-tf-row data-units="<?= h($fee['units']) ?>" data-normal="<?= h(Money::dec($fee['price_cents'] ?? $fee['normal_cents'] ?? 0)) ?>" class="<?= $fee['on'] ? 'is-on' : '' ?>"><td><input class="form-check-input" type="checkbox" name="sub[<?= $key ?>][on]" value="1" id="tfs<?= $key ?>"<?= $fee['on'] ? ' checked' : '' ?>></td><td><label for="tfs<?= $key ?>"><span class="font-monospace"><?= h($fee['subject_code']) ?></span> <span class="small text-muted"><?= h($fee['subject_name']) ?> · <?= h($fee['units']) ?> units</span></label></td><td class="small"><?= h($fee['fee']) ?></td><td class="money"><?= Money::fmt($fee['price_cents'] ?? $fee['normal_cents'] ?? 0) ?><?= $fee['price_cents'] !== null ? '<div class="small text-muted">older price of this template</div>' : '' ?></td><td class="money fw-semibold" data-tf-total></td></tr><?php endforeach; ?>
            <?php if (!$tf['subjects']): ?><tr><td colspan="5" class="text-muted small">No subject prices yet. Set them in Assessment → Subject Fees.</td></tr><?php endif; ?>
          </tbody></table></div>
          <div class="fee-dialog-foot tf-foot"><span class="small me-auto" data-tf-count aria-live="polite"></span><button type="button" class="btn btn-outline-secondary" data-close>Close</button><button class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save fees</button></div>
        </form>
      </dialog>
      <script src="assets/fee-manage.js?v=56b" defer></script>
      <?php endif; ?>
      <?php else: ?>
        <section class="fee-template-empty-state" aria-labelledby="feeTemplateEmptyTitle">
          <i class="bi bi-card-checklist" aria-hidden="true"></i>
          <h2 id="feeTemplateEmptyTitle"><?= $allNames ? 'Choose a template' : 'Start with a fee template' ?></h2>
          <p><?= $allNames ? 'Select a template from the list to review its fees and preview the total.' : 'Create a template to organize the fees used during student registration.' ?></p>
          <?php if (!$allNames && $canEdit): ?><button type="button" class="btn btn-primary" data-open-template-editor><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New template</button><?php endif; ?>
        </section>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canEdit): ?>
  <dialog class="record-dialog fee-template-editor" id="feeTemplateEditor" aria-labelledby="feeTemplateEditorTitle">
    <div class="record-dialog-head"><div><p class="record-eyebrow">Template setup</p><h2 id="feeTemplateEditorTitle" data-template-editor-title><?= $editor ? 'Edit fee template' : 'New fee template' ?></h2></div><button type="button" class="btn-close" data-template-editor-close aria-label="Close"></button></div>
    <form method="post" action="<?= url('assessment', ['tab' => 'names']) ?>" class="record-form fee-template-editor-form" data-template-editor-form>
      <?= csrf_field() ?><input type="hidden" name="action" value="name"><input type="hidden" name="id" value="<?= h($editor['id'] ?? '') ?>">
      <div class="fee-template-editor-fields">
        <div class="fee-template-field fee-template-field-wide"><label class="form-label" for="feeTemplateName">Template name <span aria-hidden="true">*</span></label><input class="form-control" id="feeTemplateName" name="name" required maxlength="120" placeholder="e.g. BSIT 1st Year · 1st Semester" value="<?= h($editor['name'] ?? '') ?>"></div>
        <div class="fee-template-field"><label class="form-label" for="feeTemplateProgram">Program</label><input class="form-control" id="feeTemplateProgram" name="program" list="feeTemplatePrograms" maxlength="40" placeholder="Any program" value="<?= h($editor['program'] ?? '') ?>"><datalist id="feeTemplatePrograms"><?php foreach (GS::programs() as $program): ?><option value="<?= h($program) ?>"><?php endforeach; ?></datalist></div>
        <div class="fee-template-field"><label class="form-label" for="feeTemplateLevel">Year level</label><select class="form-select" id="feeTemplateLevel" name="year_level"><?= list_options(YEAR_LEVELS, $editor['year_level'] ?? null, 'Any year level') ?></select></div>
        <div class="fee-template-field"><label class="form-label" for="feeTemplateTerm">Term</label><select class="form-select" id="feeTemplateTerm" name="term"><?= list_options(TERMS, $editor['term'] ?? null, 'Any term') ?></select></div>
        <div class="fee-template-field"><label class="form-label" for="feeTemplateSchoolYear">School year</label><input class="form-control" id="feeTemplateSchoolYear" name="school_year" maxlength="9" placeholder="Any school year" value="<?= h($editor['school_year'] ?? '') ?>"></div>
        <div class="fee-template-field fee-template-field-wide"><label class="form-label" for="feeTemplateNotes">Notes <span class="text-muted">(optional)</span></label><input class="form-control" id="feeTemplateNotes" name="notes" maxlength="255" placeholder="Internal note about this template" value="<?= h($editor['notes'] ?? '') ?>"></div>
        <label class="form-check fee-template-active-field"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="feeTemplateActive" <?= ($editor['is_active'] ?? 1) ? 'checked' : '' ?>><span class="form-check-label">Active · available for future registrations</span></label>
      </div>
      <div class="fee-template-editor-footer"><button type="button" class="btn btn-outline-secondary" data-template-editor-close>Cancel</button><button class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i><?= $editor ? 'Save changes' : 'Create template' ?></button></div>
    </form>
  </dialog>
  <?php endif; ?>
</section>
