<?php /* Template: Standard Certificate of Grades
   Paper: A4 */ ?>
<!doctype html><html><head><meta charset="utf-8"><title>Certificate of Grades – <?= e($doc['student']['name']) ?></title>
<?= doc_page_css('A4') ?><style>.body{font-size:14px;line-height:1.7;text-align:justify}</style></head><body><div class="doc">
<?= doc_letterhead($doc) ?>
<div class="doc-control"><?= $doc['control_no'] ? 'Control no. ' . e($doc['control_no']) : '' ?></div>
<div class="doc-title">CERTIFICATE OF GRADES</div>
<p class="body">This is to certify that <strong><?= e(mb_strtoupper($doc['student']['name'])) ?></strong> (Student ID <?= e($doc['student']['id']) ?>)<?= $doc['student']['program'] ? ', ' . e($doc['student']['program']) : '' ?>,
  obtained the following final grades for the <strong><?= e($doc['term']['term'] ?: 'school year') ?></strong> of school year <strong><?= e($doc['term']['school_year']) ?></strong>:</p>
<?php foreach ($doc['periods'] as $p): ?>
<table class="doc-table">
  <thead><tr><th style="width:16%">Code</th><th>Subject</th><th class="n" style="width:10%">Grade</th>
    <?php if ($doc['scale']['differs']): ?><th class="n" style="width:12%">Equivalent<br>(<?= e($doc['scale']['final']) ?>)</th><?php endif; ?>
    <th class="n" style="width:8%">Units</th><th style="width:13%">Remarks</th></tr></thead>
  <tbody><?php foreach ($p['subjects'] as $s): ?>
    <tr><td><?= e($s['code']) ?></td><td><?= e($s['title']) ?></td><td class="n"><?= e($s['grade']) ?></td>
      <?php if ($doc['scale']['differs']): ?><td class="n"><?= e($s['equivalent']) ?></td><?php endif; ?>
      <td class="n"><?= e((float) $s['units']) ?></td><td><?= e($s['remarks']) ?></td></tr>
  <?php endforeach; ?>
    <tr><td colspan="2" style="text-align:right"><strong>General weighted average</strong></td><td class="n"><strong><?= e($p['gwa_text']) ?></strong></td>
      <?php if ($doc['scale']['differs']): ?><td class="n"><strong><?= e($p['equivalent_text']) ?></strong></td><?php endif; ?>
      <td class="n"><?= e((float) $p['units']) ?></td><td><?= e($p['remarks']) ?></td></tr></tbody>
</table>
<?php endforeach; ?>
<?= doc_legend($doc) ?>
<p class="body">Issued upon request for <strong><?= e($doc['purpose'] ?: '________________') ?></strong>, this <?= e(doc_date($doc['issued_at'])) ?>.</p>
<div style="text-align:right"><?= doc_signature($doc) ?></div>
<?= doc_footer($doc) ?>
</div></body></html>
