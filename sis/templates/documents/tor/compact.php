<?php /* Template: Compact Transcript
   Paper: Legal */ $st = $doc['student']; ?>
<!doctype html>
<html>

<head>
   <meta charset="utf-8">
   <title>Transcript – <?= e($st['name']) ?></title>
   <?= doc_page_css('Legal', '7mm') ?><style>
      body {
         font-size: 10.5px
      }

      .doc-letterhead {
         padding-bottom: 5px;
         margin-bottom: 5px
      }

      .doc-letterhead img {
         height: 48px
      }

      .doc-school {
         font-size: 16px
      }

      .doc-title {
         font-size: 14px;
         margin: 5px 0
      }

      .meta {
         width: 100%;
         border-collapse: collapse;
         margin: 4px 0
      }

      .meta td {
         padding: 2px 4px;
         border-bottom: 1px solid #aaa
      }

      .meta .k {
         width: 13%;
         font-weight: bold
      }

      .doc-table {
         font-size: 9.5px !important
      }

      .doc-table th,
      .doc-table td {
         padding: 2px 3px !important
      }

      h3.doc-term {
         font-size: 10px;
         background: #eee;
         padding: 3px;
         margin: 5px 0 1px
      }

      .summary {
         border: 1px solid #444;
         padding: 4px;
         margin-top: 5px;
         font-weight: bold
      }
   </style>
</head>

<body>
   <div class="doc">
      <?= doc_letterhead($doc) ?><div class="doc-control"><?= $doc['control_no'] ? 'Control no. ' . e($doc['control_no']) : '' ?></div>
      <div class="doc-title">OFFICIAL TRANSCRIPT OF RECORDS</div>
      <table class="meta">
         <tr>
            <td class="k">Student</td>
            <td><strong><?= e(mb_strtoupper($st['name'])) ?></strong></td>
            <td class="k">ID</td>
            <td><?= e($st['id']) ?></td>
            <td class="k">Program</td>
            <td><?= e($st['program']) ?></td>
         </tr>
         <tr>
            <td class="k">Birth date</td>
            <td><?= e(doc_date($st['birth_date'])) ?></td>
            <td class="k">Birthplace</td>
            <td><?= e($st['birth_place']) ?></td>
            <td class="k">Admitted</td>
            <td><?= e(doc_date($st['date_admitted'])) ?></td>
         </tr>
         <tr>
            <td class="k">Address</td>
            <td colspan="5"><?= e($st['home_address']) ?></td>
         </tr><?php if ($st['status'] === 'Graduated'): ?><tr>
               <td class="k">Graduated</td>
               <td><?= e(doc_date($st['graduation_date'])) ?></td>
               <td class="k">S.O. no.</td>
               <td colspan="3"><?= e($st['so_number']) ?></td>
            </tr><?php endif; ?>
      </table>
      <?php foreach ($doc['periods'] as $p): ?><h3 class="doc-term"><?= e($p['school_year']) ?><?= $p['term'] ? ' · ' . e($p['term']) : '' ?><?= $p['year_level'] ? ' · ' . e($p['year_level']) : '' ?></h3>
         <table class="doc-table">
            <thead>
               <tr>
                  <th>Code</th>
                  <th>Course title</th>
                  <th class="n">Grade</th><?php if ($doc['scale']['differs']): ?><th class="n">Equivalent</th><?php endif; ?><th class="n">Units</th>
                  <th>Remarks</th>
               </tr>
            </thead>
            <tbody>
               <?php foreach ($p['subjects'] as $s): ?><tr>
                     <td><?= e($s['code']) ?></td>
                     <td><?= e($s['title']) ?></td>
                     <td class="n"><?= e($s['grade']) ?></td><?php if ($doc['scale']['differs']): ?><td class="n"><?= e($s['equivalent']) ?></td><?php endif; ?><td class="n"><?= e((float)$s['units']) ?></td>
                     <td><?= e($s['remarks']) ?></td>
                  </tr><?php endforeach; ?><tr>
                  <td colspan="2" class="n"><strong>Term GWA</strong></td>
                  <td class="n"><strong><?= e($p['gwa_text']) ?></strong></td><?php if ($doc['scale']['differs']): ?><td class="n"><?= e($p['equivalent_text']) ?></td><?php endif; ?><td class="n"><?= e((float)$p['units']) ?></td>
                  <td><?= e($p['remarks']) ?></td>
               </tr>
            </tbody>
         </table><?php endforeach; ?>
      <?php if (!$doc['periods']): ?><p><em>No final grades are recorded.</em></p><?php endif; ?><div class="summary">Units earned: <?= e((float)$doc['totals']['units_earned']) ?> · Overall GWA: <?= e($doc['totals']['gwa_text']) ?><?= $doc['totals']['equivalent_text'] ? ' · Equivalent: ' . e($doc['totals']['equivalent_text']) : '' ?> · <?= e($doc['totals']['remarks']) ?></div>
      <?= doc_legend($doc) ?><p>Issued for <?= e($doc['purpose'] ?: '________________') ?>. Nothing follows.</p>
      <div style="display:flex;justify-content:space-between;align-items:end">
         <div>Prepared<div class="doc-sign">
               <div class="line"><?= e($doc['signatories']['prepared_by'] ?: ' ') ?></div>
            </div>
         </div>
         <div>Checked<div class="doc-sign">
               <div class="line"><?= e($doc['signatories']['checked_by'] ?: ' ') ?></div>
            </div>
         </div><?= doc_signature($doc, 'Certified correct:') ?>
      </div><?= doc_footer($doc) ?>
   </div>
</body>

</html>