<?php
declare(strict_types=1);
/** v56 Data Migration → Import All: subjects, prices, fee templates, teachers and curriculum from one Excel sheet. */
ImportAll::requireAccess();
$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
if (($_GET['action'] ?? '') === 'template') {
    $t = ImportAll::template(($_GET['mode'] ?? '') === 'current');
    Xlsx::download('AcadLink-Import-All-' . (($_GET['mode'] ?? '') === 'current' ? 'current-subjects' : 'template') . '.xlsx', $t['sheets'], $t['dropdowns']);
}
if (($_GET['action'] ?? '') === 'report' && $id) {
    $b = ImportAll::batch($id);
    Xlsx::download('import-all-' . $id . '-' . ($b['result'] ? 'results' : 'check') . '.xlsx', ['Report' => ImportAll::report($b)]);
}
if ($isPost) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'upload') {
        $file = $_FILES['file'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new AppError('Choose the completed Excel file (.xlsx or .csv), smaller than the upload limit.');
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) throw new AppError('The file is larger than 5 MB. Split it into smaller files.');
        $id = ImportAll::upload((string) $file['tmp_name'], (string) $file['name']);
        flash('success', 'File checked. Nothing has been imported yet: tick the rows, choose the actions, then confirm.');
        redirect('importall', ['id' => $id]);
    }
    if ($action === 'discard' && $id) { ImportAll::discard($id); flash('success', 'Preview discarded. Nothing was imported.'); redirect('importall'); }
    if ($action === 'apply' && $id) {
        $choice = [];
        foreach ((array) ($_POST['pick'] ?? []) as $i => $on) if ($on && ctype_digit((string) $i)) $choice[(int) $i] = (string) (($_POST['act'][$i] ?? '') ?: 'SKIP');
        if (!$choice) throw new AppError('Tick at least one row to import.');
        $n = ImportAll::apply($id, $choice, !empty($_POST['reviewed']), (string) ($_POST['replace_word'] ?? ''));
        flash($n['failed'] ? 'warning' : 'success', "Import finished: {$n['successful']} successful ({$n['added']} added, {$n['updated']} updated, {$n['replaced']} replaced), {$n['skipped']} skipped, {$n['failed']} failed.");
        redirect('importall', ['id' => $id]);
    }
    throw new AppError('Unknown action.');
}
$b = $id ? ImportAll::batch($id) : null;
$money = fn(?int $c) => $c === null ? '' : '₱' . Money::fmt($c);
$teacherName = [];
foreach (GS::teachers() as $t) $teacherName[$t['id']] = (string) $t['teacherName'];
$labelName = array_column(Assessment::labels(), 'name', 'id');
$templateName = array_column(Assessment::names(), 'name', 'id');
layout_start('Import All');
page_title('Import All', '<a class="btn btn-outline-secondary" href="' . h(url('migration')) . '"><i class="bi bi-arrow-left me-1"></i>Data Migration</a>',
    'Bulk import of subjects, prices per unit, fee templates, teachers and curriculum. Upload, review the preview, tick the rows and confirm.');
?>
<?php if ($b): $rows = $b['rows']; $sum = $b['summary']; ?>
<section class="form-section">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h2 class="h5 mb-0 me-auto">Import #<?= (int) $b['id'] ?> · <?= h($b['file_name']) ?> <?= badge($b['status']) ?></h2>
    <a class="btn btn-sm btn-outline-primary" data-native download href="<?= h(url('importall', ['id' => $b['id'], 'action' => 'report'])) ?>"><i class="bi bi-download me-1"></i><?= $b['result'] ? 'Download results report' : 'Download check report' ?></a></div>
  <p class="small text-muted mb-2">Uploaded by <?= h((string) $b['created_by_name']) ?> on <?= h((string) $b['created_at']) ?><?= $b['applied_at'] ? ' · imported by ' . h((string) $b['applied_by_name']) . ' on ' . h((string) $b['applied_at']) : '' ?></p>
  <div class="d-flex flex-wrap gap-2 small" aria-label="Upload summary">
    <span class="badge text-bg-light border">Uploaded: <?= (int) ($sum['total'] ?? 0) ?></span><span class="badge text-bg-primary">New: <?= (int) ($sum['new'] ?? 0) ?></span><span class="badge text-bg-info">Existing: <?= (int) ($sum['existing'] ?? 0) ?></span><span class="badge text-bg-danger">Errors: <?= (int) ($sum['errors'] ?? 0) ?></span><span class="badge text-bg-warning">Warnings: <?= (int) ($sum['warnings'] ?? 0) ?></span>
  </div>
</section>
<?php if ($b['status'] === 'APPLIED'): $res = $b['result']; ?>
<section class="form-section">
  <?php if (!$res): ?><div class="alert alert-warning">This import was started but its results were not saved (the page may have been interrupted). Check Subject Fees and Curriculum before importing the file again.</div>
  <?php else: $c = $res['counts']; ?>
  <h2 class="h6">Results</h2>
  <div class="d-flex flex-wrap gap-2 mb-3"><span class="badge text-bg-success fs-6">Successful: <?= (int) $c['successful'] ?></span><span class="badge text-bg-danger fs-6">Failed: <?= (int) $c['failed'] ?></span><span class="badge text-bg-light border fs-6">Added: <?= (int) $c['added'] ?></span><span class="badge text-bg-light border fs-6">Updated: <?= (int) $c['updated'] ?></span><span class="badge text-bg-light border fs-6">Replaced: <?= (int) $c['replaced'] ?></span><span class="badge text-bg-light border fs-6">Skipped: <?= (int) $c['skipped'] ?></span></div>
  <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Excel row</th><th>Subject Code</th><th>Action</th><th>Result</th><th>Details</th></tr></thead><tbody>
  <?php foreach ($res['rows'] as $x): ?><tr class="<?= $x['result'] === 'FAILED' ? 'table-danger' : '' ?>"><td><?= (int) $x['row'] ?></td><td class="font-monospace"><?= h($x['code']) ?></td><td><?= h(ImportAll::ACTIONS[$x['action']] ?? $x['action']) ?></td><td><?= h(ucfirst(strtolower($x['result']))) ?></td><td class="small"><?= h($x['message']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <a class="btn btn-primary" href="<?= url('importall') ?>">Import another file</a>
</section>
<?php elseif ($b['status'] === 'PREVIEW'): ?>
<form method="post" class="form-section import-all-preview" data-import-all><?= csrf_field() ?><input type="hidden" name="action" value="apply">
  <h2 class="h6">Preview · tick the rows to import</h2>
  <div class="d-flex flex-wrap gap-2 align-items-center mb-2" role="toolbar" aria-label="Selection">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-ia-all="1">Select all</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-ia-all="0">Deselect all</button>
    <label class="small ms-2" for="iaBulk">For ticked rows:</label>
    <select class="form-select form-select-sm w-auto" id="iaBulk"><option value="">Choose action…</option><?php foreach (ImportAll::ACTIONS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select>
    <button type="button" class="btn btn-sm btn-outline-primary" data-ia-bulk>Apply to ticked</button>
    <label class="small ms-2" for="iaShow">Show:</label>
    <select class="form-select form-select-sm w-auto" id="iaShow" data-ia-show><option value="">All rows</option><option value="NEW">New</option><option value="EXISTING">Existing</option><option value="ERROR">Errors</option></select>
  </div>
  <div class="table-responsive" style="max-height:60vh;overflow:auto"><table class="table table-sm align-middle mb-2" id="iaRows">
    <thead class="table-light" style="position:sticky;top:0;z-index:1"><tr><th><span class="visually-hidden">Import</span></th><th>Row</th><th>Subject</th><th class="text-end">Units</th><th>Type</th><th class="text-end">Price / unit</th><th class="text-end">Total</th><th>Fee · templates</th><th>Teacher</th><th>Curriculum</th><th style="min-width:240px">Check</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($rows as $i => $r): $c = $r['clean']; $err = $r['status'] === 'ERROR'; $units = $c['units'] !== '' ? (float) $c['units'] : null; ?>
    <tr data-ia-row data-status="<?= h($r['status']) ?>" class="<?= $err ? 'table-danger' : '' ?>">
      <td><input class="form-check-input" type="checkbox" name="pick[<?= $i ?>]" value="1" aria-label="Import row <?= (int) $r['row'] ?>"<?= $err ? ' disabled' : ' checked' ?>></td>
      <td><?= (int) $r['row'] ?></td>
      <td><span class="font-monospace"><?= h($c['code']) ?></span> <span class="small"><?= h($c['name'] ?: '(keeps name)') ?></span><?= $r['status'] === 'EXISTING' ? '<div class="small text-muted">Used by ' . (int) $r['links']['classes'] . ' class(es), ' . (int) $r['links']['registrations'] . ' registration(s)</div>' : '' ?></td>
      <td class="text-end"><?= h($c['units']) ?></td><td class="small"><?= h($c['type']) ?></td>
      <td class="text-end"><?= $err ? '' : ($c['price'] === null ? '<span class="small text-muted">' . ($r['status'] === 'NEW' ? '₱0.00' : 'unchanged') . '</span>' : h($money($c['price']))) ?></td>
      <td class="text-end"><?= !$err && $units !== null && ($c['price'] !== null || $r['status'] === 'NEW') ? h($money(Money::times($c['price'] ?? 0, (string) $units))) : '' ?></td>
      <td class="small"><?= h($c['fee'] && ($c['price'] !== null || trim($r['input']['fee']) !== '' || $c['templates']) ? ($labelName[$c['fee']] ?? '') : '') ?><?= $c['templates'] ? '<div class="text-muted">' . h(implode(', ', array_map(fn($t) => $templateName[$t] ?? '', $c['templates']))) . '</div>' : '' ?></td>
      <td class="small"><?= h($c['teacher'] ? ($teacherName[$c['teacher']] ?? '') : ($r['target'] ? 'Professor X / keep' : '')) ?></td>
      <td class="small"><?= $r['target'] ? h(implode(' · ', $r['target'])) : '' ?></td>
      <td class="small"><?= ['NEW' => '<span class="badge text-bg-primary">New</span>', 'EXISTING' => '<span class="badge text-bg-info">Existing</span>', 'ERROR' => '<span class="badge text-bg-danger">Error</span>'][$r['status']] ?>
        <?php foreach ($r['errors'] as $m): ?><div class="text-danger"><?= h($m) ?></div><?php endforeach; foreach ($r['warnings'] as $m): ?><div class="text-warning-emphasis"><?= h($m) ?></div><?php endforeach; ?></td>
      <td><?php if (!$err): ?><select class="form-select form-select-sm" style="min-width:112px" name="act[<?= $i ?>]" data-ia-act aria-label="Action for row <?= (int) $r['row'] ?>"><?php foreach ($r['actions'] as $a): ?><option value="<?= $a ?>"<?= $a === $r['action'] ? ' selected' : '' ?>><?= h(ImportAll::ACTIONS[$a]) ?></option><?php endforeach; ?></select><?php endif; ?></td>
    </tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="border rounded p-3 bg-light" aria-live="polite">
    <h3 class="h6">Confirm the import</h3>
    <div class="d-flex flex-wrap gap-3 small mb-2" data-ia-counts>
      <span>Total uploaded: <strong data-n="total"><?= count($rows) ?></strong></span><span>Selected: <strong data-n="selected">0</strong></span><span>To add: <strong data-n="add">0</strong></span><span>To skip: <strong data-n="skip">0</strong></span><span>To update: <strong data-n="update">0</strong></span><span>To replace: <strong data-n="replace">0</strong></span>
    </div>
    <p class="small mb-2"><strong>Update</strong> changes only the values in the file. <strong>Replace</strong> sets the subject, its price for that fee, its fee templates and its curriculum teacher to the file's values. Grades, registrations, payments and enrollment history are never changed; confirmed assessments keep their amounts. Rows with errors cannot be imported.</p>
    <div class="mb-2" data-ia-replace hidden><label class="form-label small" for="iaReplace">Type <strong>REPLACE</strong> to confirm replacing saved subjects</label><input class="form-control form-control-sm" style="max-width:220px" id="iaReplace" name="replace_word" autocomplete="off"></div>
    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="reviewed" value="1" required> <span class="form-check-label small">I reviewed the preview and the totals above.</span></label>
    <button class="btn btn-primary" data-loading-label="Importing records…"><i class="bi bi-cloud-upload me-1"></i>Import ticked records</button>
  </div>
</form>
<form method="post" class="mb-3" data-confirm="Discard this preview? Nothing will be imported."><?= csrf_field() ?><input type="hidden" name="action" value="discard"><button class="btn btn-sm btn-outline-danger">Discard preview</button></form>
<script src="assets/import-all.js?v=56" defer></script>
<?php else: ?><section class="form-section"><p class="mb-2">This preview was discarded. Nothing was imported.</p><a class="btn btn-primary" href="<?= url('importall') ?>">Import a file</a></section><?php endif; ?>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-6"><section class="form-section h-100"><h2 class="h5">1. Download Template</h2>
    <p class="small">The template follows the fields the system uses: Subject Code, Subject Name, Subject Units, Subject Type, Price per Unit, Fee Label, Fee Templates, Professor / Teacher, Curriculum, Course / Program, Year Level and Semester. Drop-down lists come from the current setup. A price left blank stays at ₱0.00.</p>
    <div class="d-flex flex-wrap gap-2"><a class="btn btn-primary" data-native download href="<?= h(url('importall', ['action' => 'template'])) ?>"><i class="bi bi-download me-1"></i>Download Template</a><a class="btn btn-outline-primary" data-native download href="<?= h(url('importall', ['action' => 'template', 'mode' => 'current'])) ?>"><i class="bi bi-download me-1"></i>Template with current subjects</a></div>
  </section></div>
  <div class="col-lg-6"><section class="form-section h-100"><h2 class="h5">2. Upload Excel File</h2>
    <form method="post" enctype="multipart/form-data" data-loading-label="Reading and checking the file…"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <label class="form-label" for="iaFile">Completed template</label><input class="form-control mb-2" type="file" id="iaFile" name="file" accept=".xlsx,.csv" required>
      <p class="small text-muted">Up to <?= ImportAll::MAX_ROWS ?> rows / 5 MB. Uploading only checks the file and shows a preview; nothing is imported until you confirm.</p>
      <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload and preview</button></form>
  </section></div>
</div>
<?php endif; ?>
<section class="form-section"><h2 class="h5">Import history</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Import</th><th>File</th><th>Uploaded by</th><th>Date</th><th>Status</th><th>Result</th></tr></thead><tbody>
<?php foreach (ImportAll::history() as $h): $rc = $h['result_json'] ? (json_decode($h['result_json'], true)['counts'] ?? null) : null; $sc = json_decode((string) $h['summary_json'], true) ?: []; ?><tr>
  <td><a href="<?= url('importall', ['id' => $h['id']]) ?>">#<?= (int) $h['id'] ?></a></td><td><?= h($h['file_name']) ?></td><td><?= h((string) $h['created_by_name']) ?></td><td><?= h($h['applied_at'] ?: $h['created_at']) ?></td><td><?= badge($h['status']) ?></td>
  <td class="small"><?= $rc ? h("{$rc['successful']} successful · {$rc['failed']} failed · {$rc['added']} added · {$rc['updated']} updated · {$rc['replaced']} replaced · {$rc['skipped']} skipped" . ($h['applied_by_name'] ? ' · by ' . $h['applied_by_name'] : '')) : h(($sc['total'] ?? 0) . ' rows · ' . ($sc['errors'] ?? 0) . ' errors') ?></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php layout_end();
