<?php /* Template: Standard Certificate of Good Moral Character
   Paper: A4 */ $pro = ($doc['student']['sex'] ?? '') === 'Female' ? ['she', 'her'] : (($doc['student']['sex'] ?? '') === 'Male' ? ['he', 'his'] : ['the student', 'the student’s']); ?>
<!doctype html><html><head><meta charset="utf-8"><title>Good Moral – <?= e($doc['student']['name']) ?></title>
<?= doc_page_css('A4') ?><style>.body{font-size:14.5px;line-height:1.9;text-align:justify;margin-top:16px;text-indent:3em}</style></head><body><div class="doc">
<?= doc_letterhead($doc) ?>
<div class="doc-control"><?= $doc['control_no'] ? 'Control no. ' . e($doc['control_no']) : '' ?></div>
<div class="doc-title">CERTIFICATE OF GOOD MORAL CHARACTER</div>
<p style="font-size:14.5px;margin-top:20px">TO WHOM IT MAY CONCERN:</p>
<p class="body">This is to certify that <strong><?= e(mb_strtoupper($doc['student']['name'])) ?></strong>
  (Student ID <?= e($doc['student']['id']) ?>)<?= $doc['student']['program'] ? ', a student of ' . e($doc['student']['program']) : '' ?>,
  has been enrolled in this institution since <?= doc_blank(doc_date($doc['student']['date_admitted'])) ?>.</p>
<p class="body">During <?= e($pro[1]) ?> stay in this institution, <?= e($pro[0]) ?> has shown good moral character and has not been
  subjected to any disciplinary action.</p>
<p class="body">This certification is issued upon the request of the above-named student for <strong><?= e($doc['purpose'] ?: '________________') ?></strong>.</p>
<p class="body">Given this <?= e(doc_date($doc['issued_at'])) ?>.</p>
<div style="text-align:right"><?= doc_signature($doc) ?></div>
<?= doc_footer($doc) ?>
</div></body></html>
