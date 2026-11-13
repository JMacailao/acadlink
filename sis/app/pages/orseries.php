<?php
/** Settings → Official Receipt Series Types: the O.R. series (code, numbering, template) the cashier chooses from. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$embed = isset($_GET['embed']);
$keep = $embed ? ['embed' => 1] : [];
if ($isPost) {
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        $id = In::int($_POST, 'id');
        OrSeries::save($id, $_POST);
        flash('success', 'O.R. series saved.');
    } elseif ($a === 'delete') {
        OrSeries::delete((int) In::int($_POST, 'id'));
        flash('success', 'O.R. series deleted.');
    }
    redirect('orseries', $keep);
}
$edit = (string) ($_GET['edit'] ?? '');
$icons = ['is_active' => 'check-circle', 'auto_number' => '123', 'allow_edit' => 'pencil-square', 'is_default' => 'star'];
$help = [
    'is_active' => 'Active: the cashier can choose this series.',
    'auto_number' => 'Auto-number: the system gives the next number. Untick it for pre-printed receipts whose number the cashier types.',
    'allow_edit' => 'Editable O.R. No.: the O.R. number of a posted receipt can be corrected (the new number must be unused).',
    'is_default' => 'Default: chosen first when receiving a payment (one series only).',
];
layout_start('Official Receipt Series Types', $embed);
if ($embed) echo '<div class="embed-wrap">';

// ---------------------------------------------------------------- add / edit form
if ($edit !== ''):
    $s = $edit === 'new' ? ['id' => null, 'code' => '', 'description' => '', 'prefix' => '', 'digits' => 7, 'next_number' => 1, 'template' => 'STANDARD',
                            'is_active' => 1, 'auto_number' => 1, 'allow_edit' => 1, 'is_default' => 0]
                         : (ctype_digit($edit) ? OrSeries::get((int) $edit) : null);
    if (!$s) throw new AppError('O.R. series not found.');
    $next = $s['id'] ? OrSeries::suggest($s) : '';
    ?>
<form method="post" class="ors-form" action="<?= url('orseries', $keep) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h((string) $s['id']) ?>">
  <div class="or-filters-title"><?= $s['id'] ? 'Edit O.R. Series · ' . h($s['code']) : 'Add O.R. Series' ?></div>
  <div class="row g-2 p-2">
    <div class="col-4 col-md-3"><label class="form-label small" for="osCode">Code</label><input class="form-control font-monospace text-uppercase" id="osCode" name="code" required maxlength="10" pattern="[A-Za-z0-9]{1,10}" value="<?= h($s['code']) ?>" placeholder="OR1"></div>
    <div class="col-8 col-md-9"><label class="form-label small" for="osDesc">Description</label><input class="form-control" id="osDesc" name="description" required maxlength="100" value="<?= h($s['description']) ?>" placeholder="e.g. Official Receipt Series One"></div>
    <div class="col-4 col-md-3"><label class="form-label small" for="osPrefix">Number prefix</label><input class="form-control font-monospace" id="osPrefix" name="prefix" maxlength="8" value="<?= h($s['prefix']) ?>" placeholder="e.g. OR-"></div>
    <div class="col-4 col-md-2"><label class="form-label small" for="osDigits">Digits</label><select class="form-select" id="osDigits" name="digits"><?= list_options(array_map('strval', range(3, 10)), (string) $s['digits']) ?></select></div>
    <div class="col-4 col-md-3"><label class="form-label small" for="osNext">Next number</label><input class="form-control font-monospace" id="osNext" name="next_number" type="number" min="1" step="1" required value="<?= (int) $s['next_number'] ?>"></div>
    <div class="col-md-4"><label class="form-label small" for="osTemplate">Receipt template</label><select class="form-select" id="osTemplate" name="template"><?php foreach (OrSeries::TEMPLATES as $k => $label): ?><option value="<?= $k ?>"<?= $s['template'] === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 d-flex flex-wrap gap-3">
      <?php foreach (OrSeries::FLAGS as $f => $label): ?><label class="form-check" title="<?= h($help[$f]) ?>"><input class="form-check-input" type="checkbox" name="<?= $f ?>" value="1"<?= (int) $s[$f] ? ' checked' : '' ?>> <i class="bi bi-<?= $icons[$f] ?> text-success"></i> <?= h($label) ?></label><?php endforeach; ?>
    </div>
    <div class="col-12 form-text mt-0"><?= $next !== '' ? 'Next O.R. No. suggested at the cashier: <strong class="font-monospace">' . h($next) . '</strong>. ' : '' ?>Numbers already used are skipped; each O.R. number can be used only once.</div>
  </div>
  <div class="ors-buttons"><a class="btn btn-sm btn-outline-secondary me-auto" href="<?= url('orseries', $keep) ?>">Back to the list</a><button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Save</button></div>
</form>
<?php
// ---------------------------------------------------------------- the list
else:
    $rows = OrSeries::all();
    ?>
<div class="or-screen ors-screen">
  <div class="ors-search"><input class="form-control form-control-sm" data-filter="#orsGrid" placeholder="Search by Description" aria-label="Search by description"><i class="bi bi-search" aria-hidden="true"></i></div>
  <div class="or-grid-wrap ors-grid-wrap">
    <table class="or-grid" id="orsGrid" data-select>
      <thead><tr><th class="ic"></th><th style="width:18%">Code</th><th>Description</th>
        <?php foreach (OrSeries::FLAGS as $f => $label): ?><th class="ors-flag" title="<?= h($help[$f]) ?>"><i class="bi bi-<?= $icons[$f] ?>" aria-hidden="true"></i><span class="visually-hidden"><?= h($label) ?></span></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr tabindex="0" data-id="<?= (int) $r['id'] ?>" data-edit="<?= h(url('orseries', ['edit' => $r['id']] + $keep)) ?>" data-label="<?= h($r['code'] . ' · ' . $r['description']) ?>"
            data-deletable="<?= !(int) $r['used'] && !(int) $r['is_default'] ? '1' : '0' ?>" title="Next O.R. No.: <?= h(OrSeries::suggest($r) ?: 'typed by the cashier') ?> · <?= (int) $r['used'] ?> receipt(s) · <?= h(OrSeries::TEMPLATES[$r['template']]) ?>">
          <td class="ic"><i class="bi bi-caret-right-fill ors-caret" aria-hidden="true"></i></td>
          <td class="mono"><?= h($r['code']) ?></td>
          <td class="<?= (int) $r['is_default'] ? 'fw-bold' : '' ?>"><?= h(mb_strtoupper($r['description'])) ?></td>
          <?php foreach (array_keys(OrSeries::FLAGS) as $f): ?><td class="ors-flag"><input class="form-check-input" type="checkbox" disabled<?= (int) $r[$f] ? ' checked' : '' ?> aria-label="<?= h(OrSeries::FLAGS[$f]) ?>"></td><?php endforeach; ?>
        </tr>
      <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="7">No O.R. series yet. Click <strong>Add</strong>.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="ors-summary"><div class="ors-summary-title">Grand Summaries</div><div class="ors-summary-row"><span>Total Record(s) : <?= count($rows) ?></span><span></span></div></div>
  <div class="collapse" id="orsHelp"><div class="or-note mt-2"><i class="bi bi-info-circle"></i><span>
    <?php foreach (OrSeries::FLAGS as $f => $label): ?><i class="bi bi-<?= $icons[$f] ?> text-success"></i> <?= h($help[$f]) ?><br><?php endforeach; ?>
    Templates: <strong>Standard receipt</strong> uses Settings → Official receipt; <strong>Template Official Receipt</strong> prints the fields of the pre-printed official receipt form.
    When a receipt is voided, the cashier is asked whether to make its O.R. number negative. A negative number frees the original number for manual use only; the series always suggests the next unused number.</span></div></div>
  <div class="ors-buttons">
    <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#orsHelp" aria-expanded="false" aria-controls="orsHelp">Help</button>
    <span class="me-auto"></span>
    <a class="btn btn-sm btn-light border" href="<?= url('orseries', ['edit' => 'new'] + $keep) ?>">Add</a>
    <button type="button" class="btn btn-sm btn-light border" disabled data-row="go" data-row-key="edit" data-row-default>Edit</button>
    <button type="button" class="btn btn-sm btn-light border" disabled data-row="modal" data-row-if="deletable" data-bs-toggle="modal" data-bs-target="#mOrsDelete">Delete</button>
    <a class="btn btn-sm btn-light border" href="<?= url('settings') ?>" data-frame-close>Close</a>
  </div>
</div>

<div class="modal fade" id="mOrsDelete" tabindex="-1" aria-labelledby="mOrsDeleteT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post" action="<?= url('orseries', $keep) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="" data-row-fill="id">
  <div class="modal-header"><h2 class="modal-title fs-5" id="mOrsDeleteT">Delete O.R. series</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><p class="fw-semibold mb-1" data-row-fill="label"></p><p class="small text-muted mb-0">Only a series with no receipts can be deleted. A series in use can be made inactive instead.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Delete</button></div>
  </form>
</div></div></div>
<?php endif;
if ($embed) echo '</div>';
layout_end($embed);
