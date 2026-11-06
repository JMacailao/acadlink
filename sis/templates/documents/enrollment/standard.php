<?php /* Template: Standard Certificate of Enrollment
   Paper: A4 */ $en = $doc['enrollment']; ?>
<!doctype html><html><head><meta charset="utf-8"><title>Certificate of Enrollment – <?= e($doc['student']['name']) ?></title>
<?= doc_page_css('A4') ?><style>.body{font-size:14px;line-height:1.8;text-align:justify;margin-top:18px}</style></head><body><div class="doc">
<?= doc_letterhead($doc) ?>
<div class="doc-control"><?= $doc['control_no'] ? 'Control no. ' . e($doc['control_no']) : '' ?></div>
<div class="doc-title">CERTIFICATE OF ENROLLMENT</div>
<p class="body">TO WHOM IT MAY CONCERN:</p>
<p class="body">This is to certify that <strong><?= e(mb_strtoupper($doc['student']['name'])) ?></strong> (Student ID <?= e($doc['student']['id']) ?>)
  is officially enrolled in <strong><?= doc_blank($en['program'] ?? $doc['student']['program']) ?></strong>, <?= e($en['year_level'] ?? '') ?>,
  section <?= e($en['section'] ?? '') ?>, for the <strong><?= e($doc['term']['term'] ?: 'school year') ?></strong> of school year <strong><?= e($doc['term']['school_year']) ?></strong>,
  carrying <?= e((float) ($en['units'] ?? 0)) ?> units.</p>
<?php if (!empty($en['subjects'])): ?>
<table class="doc-table"><thead><tr><th style="width:20%">Code</th><th>Subject</th><th class="n" style="width:10%">Units</th></tr></thead><tbody>
<?php foreach ($en['subjects'] as $s): ?><tr><td><?= e($s['code']) ?></td><td><?= e($s['title']) ?></td><td class="n"><?= e((float) $s['units']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<p class="body">This certification is issued upon the request of the above-named student for <strong><?= e($doc['purpose'] ?: '________________') ?></strong>.</p>
<p class="body">Given this <?= e(doc_date($doc['issued_at'])) ?>.</p>
<div style="text-align:right"><?= doc_signature($doc) ?></div>
<?= doc_footer($doc) ?>
</div></body></html>
