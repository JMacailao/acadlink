<?php /* Template: Modern Academic Record
   Paper: Legal */ $st = $doc['student']; ?>
<!doctype html><html><head><meta charset="utf-8"><title>Academic Record – <?= e($st['name']) ?></title>
<?= doc_page_css('Legal', '9mm') ?><style>
.identity{display:grid;grid-template-columns:repeat(4,1fr);gap:5px;margin:10px 0}.identity div{border:1px solid #9aa8a0;padding:5px 7px}.identity span{display:block;color:#42554a;font-size:9px;text-transform:uppercase;letter-spacing:.06em}.identity strong{font-size:11px}
.accent{color:#155b3d}.doc-title{letter-spacing:.1em;border-bottom:2px solid #155b3d;padding-bottom:7px}.term{margin:9px 0 3px;padding:5px 7px;background:#e8f1ec;border-left:4px solid #155b3d;font-weight:bold}.doc-table th{background:#e8f1ec!important}
</style></head><body><div class="doc">
<?= doc_letterhead($doc) ?><div class="doc-control"><?= $doc['control_no'] ? 'Control no. ' . e($doc['control_no']) : '' ?></div>
<div class="doc-title accent">TRANSCRIPT OF RECORDS</div>
<div class="identity"><div><span>Student</span><strong><?= e(mb_strtoupper($st['name'])) ?></strong></div><div><span>Student ID</span><strong><?= e($st['id']) ?></strong></div><div><span>Program</span><strong><?= e($st['program']) ?></strong></div><div><span>Status</span><strong><?= e($st['status']) ?></strong></div>
<div><span>Date of birth</span><strong><?= e(doc_date($st['birth_date'])) ?></strong></div><div><span>Place of birth</span><strong><?= e($st['birth_place']) ?></strong></div><div><span>Date admitted</span><strong><?= e(doc_date($st['date_admitted'])) ?></strong></div><div><span>Entrance credentials</span><strong><?= e($st['entrance_credentials']) ?></strong></div></div>
<?php if ($st['status'] === 'Graduated'): ?><p><strong>Graduation:</strong> <?= e(doc_date($st['graduation_date'])) ?> &nbsp; <strong>Special Order:</strong> <?= e($st['so_number']) ?></p><?php endif; ?>
<?php foreach ($doc['periods'] as $p): ?><div class="term"><?= e($p['school_year']) ?><?= $p['term'] ? ' · ' . e($p['term']) : '' ?><?= $p['year_level'] ? ' · ' . e($p['year_level']) : '' ?></div>
<table class="doc-table"><thead><tr><th style="width:13%">Code</th><th>Course title</th><th class="n">Grade</th><?php if ($doc['scale']['differs']): ?><th class="n">Equivalent</th><?php endif; ?><th class="n">Units</th><th>Remarks</th></tr></thead><tbody>
<?php foreach ($p['subjects'] as $s): ?><tr><td><?= e($s['code']) ?></td><td><?= e($s['title']) ?></td><td class="n"><?= e($s['grade']) ?></td><?php if ($doc['scale']['differs']): ?><td class="n"><?= e($s['equivalent']) ?></td><?php endif; ?><td class="n"><?= e((float)$s['units']) ?></td><td><?= e($s['remarks']) ?></td></tr><?php endforeach; ?>
<tr><td colspan="2"><strong>Term summary</strong></td><td class="n"><strong><?= e($p['gwa_text']) ?></strong></td><?php if ($doc['scale']['differs']): ?><td class="n"><?= e($p['equivalent_text']) ?></td><?php endif; ?><td class="n"><?= e((float)$p['units']) ?></td><td><?= e($p['remarks']) ?></td></tr></tbody></table><?php endforeach; ?>
<?php if (!$doc['periods']): ?><p><em>No final grades are recorded.</em></p><?php endif; ?>
<p><strong>Total units earned:</strong> <?= e((float)$doc['totals']['units_earned']) ?> &nbsp; <strong>Overall GWA:</strong> <?= e($doc['totals']['gwa_text']) ?><?= $doc['totals']['equivalent_text'] ? ' (' . e($doc['totals']['equivalent_text']) . ')' : '' ?> &nbsp; <?= e($doc['totals']['remarks']) ?></p>
<?= doc_legend($doc) ?><p>Issued for <strong><?= e($doc['purpose'] ?: '________________') ?></strong>. <em>Nothing follows.</em></p>
<div style="display:flex;justify-content:space-between;align-items:end;gap:12px;margin-top:10px"><div>Prepared by<div class="doc-sign"><div class="line"><?= e($doc['signatories']['prepared_by'] ?: ' ') ?></div></div></div><div>Checked by<div class="doc-sign"><div class="line"><?= e($doc['signatories']['checked_by'] ?: ' ') ?></div></div></div><?= doc_signature($doc, 'Certified correct:') ?></div>
<?= doc_footer($doc) ?></div></body></html>
