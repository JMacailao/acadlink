<?php /* Template: Standard Transcript of Records (CHED style)
   Paper: Legal */ ?>
<!doctype html><html><head><meta charset="utf-8"><title>TOR – <?= e($doc['student']['name']) ?></title>
<?= doc_page_css('Legal', '10mm') ?>
<style>
  .info{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:6px}.info td{padding:2px 4px;vertical-align:top}.info td.k{width:19%;white-space:nowrap}
  .totals{font-weight:bold;margin-top:6px}.signs{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:12px;margin-top:10px}
</style></head><body><div class="doc">
<?= doc_letterhead($doc) ?>
<div class="doc-control"><?= $doc['control_no'] ? 'Control no. ' . e($doc['control_no']) : '' ?></div>
<div class="doc-title">OFFICIAL TRANSCRIPT OF RECORDS</div>

<table class="info">
  <tr><td class="k">Name:</td><td><strong><?= e(mb_strtoupper($doc['student']['name'])) ?></strong></td><td class="k">Student ID:</td><td><?= e($doc['student']['id']) ?></td></tr>
  <tr><td class="k">Sex:</td><td><?= doc_blank($doc['student']['sex'], '6em') ?></td><td class="k">Date of birth:</td><td><?= doc_blank(doc_date($doc['student']['birth_date']), '10em') ?></td></tr>
  <tr><td class="k">Place of birth:</td><td colspan="3"><?= doc_blank($doc['student']['birth_place'], '20em') ?></td></tr>
  <tr><td class="k">Home address:</td><td colspan="3"><?= doc_blank($doc['student']['home_address'], '28em') ?></td></tr>
  <tr><td class="k">Program:</td><td><?= doc_blank($doc['student']['program'], '14em') ?></td><td class="k">Date admitted:</td><td><?= doc_blank(doc_date($doc['student']['date_admitted']), '10em') ?></td></tr>
  <tr><td class="k">Entrance credentials:</td><td colspan="3"><?= doc_blank($doc['student']['entrance_credentials'], '20em') ?></td></tr>
  <?php if ($doc['student']['status'] === 'Graduated'): ?>
  <tr><td class="k">Date of graduation:</td><td><?= doc_blank(doc_date($doc['student']['graduation_date']), '10em') ?></td><td class="k">Special Order no.:</td><td><?= doc_blank($doc['student']['so_number'], '10em') ?></td></tr>
  <?php endif; ?>
</table>

<?php foreach ($doc['periods'] as $p): ?>
<h3 class="doc-term"><?= e($p['school_year']) ?><?= $p['term'] ? ' · ' . e($p['term']) : '' ?><?= $p['year_level'] ? ' · ' . e($p['year_level']) : '' ?></h3>
<table class="doc-table">
  <thead><tr><th style="width:14%">Course code</th><th>Descriptive title</th><th class="n" style="width:9%">Grade</th>
    <?php if ($doc['scale']['differs']): ?><th class="n" style="width:11%">Equivalent<br>(<?= e($doc['scale']['final']) ?>)</th><?php endif; ?>
    <th class="n" style="width:7%">Units</th><th style="width:12%">Remarks</th></tr></thead>
  <tbody>
  <?php foreach ($p['subjects'] as $s): ?>
    <tr><td><?= e($s['code']) ?></td><td><?= e($s['title']) ?></td><td class="n"><?= e($s['grade']) ?></td>
      <?php if ($doc['scale']['differs']): ?><td class="n"><?= e($s['equivalent']) ?></td><?php endif; ?>
      <td class="n"><?= e((float) $s['units']) ?></td><td><?= e($s['remarks']) ?></td></tr>
  <?php endforeach; ?>
    <tr><td colspan="2" style="text-align:right"><strong>Term GWA</strong></td><td class="n"><strong><?= e($p['gwa_text']) ?></strong></td>
      <?php if ($doc['scale']['differs']): ?><td class="n"><strong><?= e($p['equivalent_text']) ?></strong></td><?php endif; ?>
      <td class="n"><?= e((float) $p['units']) ?></td><td><?= e($p['remarks']) ?></td></tr>
  </tbody>
</table>
<?php endforeach; ?>
<?php if (!$doc['periods']): ?><p><em>No final grades yet.</em></p><?php endif; ?>

<div class="totals">Units earned: <?= e((float) $doc['totals']['units_earned']) ?> ·
  General weighted average: <?= e($doc['totals']['gwa_text']) ?><?= $doc['totals']['equivalent_text'] ? ' (' . e($doc['totals']['equivalent_text']) . ')' : '' ?>
  <?= $doc['totals']['remarks'] ? ' · ' . e($doc['totals']['remarks']) : '' ?></div>
<?= doc_legend($doc) ?>
<p style="margin:8px 0 0">Remarks: Issued for <strong><?= e($doc['purpose'] ?: '________________') ?></strong>. *** Nothing follows ***</p>

<div class="signs">
  <div><div style="font-size:11.5px">Prepared by:</div><div class="doc-sign" style="margin-top:22px"><div class="line"><?= e($doc['signatories']['prepared_by'] ?: ' ') ?></div></div></div>
  <div><div style="font-size:11.5px">Checked by:</div><div class="doc-sign" style="margin-top:22px"><div class="line"><?= e($doc['signatories']['checked_by'] ?: ' ') ?></div></div></div>
  <div><?= doc_signature($doc, 'Certified correct:') ?></div>
</div>
<?= doc_footer($doc) ?>
</div></body></html>
