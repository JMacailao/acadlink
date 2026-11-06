<?php
/**
 * "Import from Excel" button and pop-up. Expects $impKind (FeeImport::KINDS key) and $impUrl (the page,
 * which handles action=import and ?template=1). Only for people who may edit the fee setup.
 */
if (!Auth::can('assessment.edit')) return;
$impTitle = FeeImport::KINDS[$impKind];
$impSep = str_contains($impUrl, '?') ? '&' : '?';
?>
<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mImport"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Import from Excel</button>
<a class="btn btn-sm btn-outline-secondary" href="<?= h($impUrl . $impSep . 'template=1') ?>"><i class="bi bi-download me-1"></i>Download template</a>
<div class="modal fade" id="mImport" tabindex="-1" aria-labelledby="mImportT" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post" action="<?= h($impUrl) ?>" enctype="multipart/form-data" class="d-contents"><?= csrf_field() ?><input type="hidden" name="action" value="import">
  <div class="modal-header"><h2 class="modal-title fs-5" id="mImportT"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Import <?= h($impTitle) ?> from Excel</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <ol class="small ps-3 mb-3">
      <li><a href="<?= h($impUrl . $impSep . 'template=1') ?>">Download the template</a> (it has your current records, or examples, and a <em>Help</em> sheet).</li>
      <li>Fill it in Excel. Keep the first row (the column headings).</li>
      <li>Choose the file below and import. Same name = updated; new names are added; nothing is deleted.</li>
    </ol>
    <label class="form-label small" for="impFile">Excel file (.xlsx) or CSV</label>
    <input class="form-control" type="file" id="impFile" name="file" accept=".xlsx,.csv" required>
    <label class="form-check small mt-3"><input class="form-check-input" type="checkbox" name="check_only" value="1" checked> Only check the file first (save nothing)</label>
    <div class="form-text">If any row has an error, nothing is saved and the rows to fix are listed.</div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Check / import</button></div>
  </form>
</div></div></div>
