<?php /* Template: Standard Certificate of Honorable Dismissal
   Paper: A4 */ $la = $doc['last_attended']; ?>
<!doctype html><html><head><meta charset="utf-8"><title>Honorable Dismissal – <?= e($doc['student']['name']) ?></title>
<?= doc_page_css('A4') ?><style>.body{font-size:14.5px;line-height:1.9;text-align:justify;margin-top:16px;text-indent:3em}</style></head><body><div class="doc">
<?= doc_letterhead($doc) ?>
<div class="doc-control"><?= $doc['control_no'] ? 'Control no. ' . e($doc['control_no']) : '' ?></div>
<div class="doc-title">CERTIFICATE OF HONORABLE DISMISSAL</div>
<p style="font-size:14.5px;margin-top:20px">TO WHOM IT MAY CONCERN:</p>
<p class="body">This is to certify that <strong><?= e(mb_strtoupper($doc['student']['name'])) ?></strong> (Student ID <?= e($doc['student']['id']) ?>),
  <?= $doc['student']['program'] ? 'a student of ' . e($doc['student']['program']) . ',' : '' ?> was last enrolled in this institution during the
  <strong><?= $la ? e(($la['term'] ?: 'school year') . ' of school year ' . $la['school_year']) : doc_blank('') ?></strong>.</p>
<p class="body">The student is hereby granted HONORABLE DISMISSAL, having no pending financial or property obligations and no disciplinary case
  in this institution. The Transcript of Records will be sent to the receiving school upon its written request.</p>
<p class="body">Issued upon request for <strong><?= e($doc['purpose'] ?: 'transfer') ?></strong>, this <?= e(doc_date($doc['issued_at'])) ?>.</p>
<div style="text-align:right"><?= doc_signature($doc) ?></div>
<?= doc_footer($doc) ?>
</div></body></html>
