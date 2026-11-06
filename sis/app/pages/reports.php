<?php
/** Report Manager: the list of reports by group; each report opens with its options and prints. */
declare(strict_types=1);
$key = (string) ($_GET['rep'] ?? '');
$embed = isset($_GET['embed']);

// ---------------------------------------------------------------- one report (page or pop-up)
if ($key !== '') {
    [$group, $title, $remarks, , $needs, $landscape] = Reports::def($key);
    $p = Reports::params($_GET);
    $run = isset($_GET['run']);
    $data = $run ? Reports::run($key, $p) : null;
    $sel = fn(array $values, string $current, string $blank) => ($blank !== '' ? '<option value="">' . h($blank) . '</option>' : '') . implode('', array_map(fn($v) => '<option' . ((string) $v === $current ? ' selected' : '') . '>' . h((string) $v) . '</option>', $values));
    layout_start($title, $embed);
    ?>
<style>@media print { @page { size: <?= $landscape ? 'landscape' : 'portrait' ?>; margin: 10mm; } }</style>
<?php if ($embed): ?><div class="embed-wrap"><?php endif; ?>
<div class="or-screen">
  <form method="get" class="or-filters d-print-none"><input type="hidden" name="r" value="reports"><input type="hidden" name="rep" value="<?= h($key) ?>"><input type="hidden" name="run" value="1"><?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
    <div class="or-filters-title"><?= h($title) ?> · Report Options</div>
    <div class="fp-grid">
      <?php foreach ($needs as $n):
          if ($n === 'sy') echo fp_row('School Year', '<select class="form-select form-select-sm" name="sy" aria-label="School year">' . sy_options($p['sy']) . '</select>', true, true);
          if ($n === 'term') echo fp_row('Term', '<select class="form-select form-select-sm" name="term" aria-label="Term">' . $sel(TERMS, $p['term'] ?: TERMS[0], '') . '</select>', $p['term'] !== '');
          if ($n === 'section') { $names = array_values(array_unique(array_map(fn($x) => $x['sectionName'], GS::sections($p['sy'])))); sort($names);
              echo fp_row('Section', '<select class="form-select form-select-sm" name="section" aria-label="Section">' . $sel($names, $p['section'], '') . '</select>', $p['section'] !== ''); }
          if ($n === 'dates') echo fp_row('Date Range', '<input class="form-control form-control-sm" type="date" name="from" value="' . h($p['from']) . '" aria-label="From"><span>–</span><input class="form-control form-control-sm" type="date" name="to" value="' . h($p['to']) . '" aria-label="To">', true, true);
          if ($n === 'cashier') echo fp_row('Cashier', '<select class="form-select form-select-sm" name="cashier" aria-label="Cashier">' . opt(Db::all("SELECT DISTINCT u.id, u.full_name FROM sis_users u JOIN sis_payments p ON p.received_by = u.id ORDER BY u.full_name"), 'id', 'full_name', $p['cashier']) . '</select>', (bool) $p['cashier']);
          if ($n === 'asof') echo fp_row('As of', '<input class="form-control form-control-sm" type="date" name="asof" value="' . h($p['asof']) . '" aria-label="As of">', true, true);
          if ($n === 'account') echo fp_row('Account', '<select class="form-select form-select-sm" name="account" aria-label="Account">' . implode('', array_map(fn($a) => '<option value="' . (int) $a['id'] . '"' . ((int) $a['id'] === (int) $p['account'] ? ' selected' : '') . '>' . h($a['code'] . ' · ' . $a['name']) . '</option>', Accounting::accounts())) . '</select>', (bool) $p['account']);
          if($n==='fund')echo fp_row('Fund','<select name="fund" class="form-select form-select-sm"><option value="">All funds</option>'.implode('',array_map(fn($k,$v)=>'<option value="'.$k.'"'.($p['fund']===$k?' selected':'').'>'.h($v).'</option>',array_keys(AccountingWorkflow::FUNDS),array_values(AccountingWorkflow::FUNDS))).'</select>',true,true);
          if($n==='department')echo fp_row('Department','<select name="department" class="form-select form-select-sm">'.$sel(Db::col('SELECT DISTINCT department FROM sis_journal ORDER BY department'),$p['department'],'All departments').'</select>',true,true);
          if ($n === 'adm_status') echo fp_row('Admission Status', '<select class="form-select form-select-sm" name="adm_status" aria-label="Admission status">' . $sel(['PENDING', 'ADMITTED', 'REJECTED'], $p['adm_status'], '') . '</select>', $p['adm_status'] !== '');
      endforeach; ?>
    </div>
    <div class="fp-actions"><?php if (!$embed): ?><a class="btn btn-sm btn-outline-secondary me-auto" href="<?= url('reports') ?>"><i class="bi bi-arrow-left me-1"></i>Report Manager</a><?php endif; ?>
      <?php if ($data): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-print><i class="bi bi-printer me-1"></i>Print</button><?php endif; ?>
      <button class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>Preview</button></div>
  </form>

  <?php if (!$data): ?>
  <div class="or-note d-print-none"><i class="bi bi-info-circle"></i><span>Set the options, then click <strong>Preview</strong>. Print from the preview.</span></div>
  <?php else:
      $cols = $data['cols']; $nc = count($cols);
      $cell = fn(array $row, string $tag = 'td') => implode('', array_map(fn($v, $i) => "<$tag" . (($cols[$i][1] ?? '') === 'n' ? ' class="n"' : '') . '>' . h((string) $v) . "</$tag>", $row, array_keys($row)));
      $rowsN = array_sum(array_map(fn($s) => count($s['rows']), $data['sections'])); ?>
  <div class="reg-paper">
    <div class="doc-sheet report-sheet<?= $landscape ? ' is-wide' : '' ?>">
      <?php $docTitle = $title; $docRight = '<div class="small text-muted">Printed ' . h(date('M j, Y g:i A')) . '</div><div class="small text-muted">by ' . h((string) Auth::user()['full_name']) . '</div>'; require __DIR__ . '/../views/print_header.php'; ?>
      <div class="report-params"><?= h(Reports::describe($key, $p)) ?></div>
      <?php if ($data['summary']): ?><div class="report-summary"><?php foreach ($data['summary'] as [$l, $v]): ?><div><span><?= h($l) ?></span><b><?= h($v) ?></b></div><?php endforeach; ?></div><?php endif; ?>
      <div class="table-responsive">
      <table class="report-table">
        <thead><tr><?= $cell(array_column($cols, 0), 'th') ?></tr></thead>
        <?php foreach ($data['sections'] as $s): ?>
        <tbody<?= !empty($s['void']) ? ' class="is-void"' : '' ?>>
          <?php if ($s['title'] !== null): ?><tr class="rg"><td colspan="<?= $nc ?>"><?= h($s['title']) ?></td></tr><?php endif; ?>
          <?php foreach ($s['rows'] as $row): ?><tr><?= $cell($row) ?></tr><?php endforeach; ?>
          <?php if ($s['total']): ?><tr class="sub"><?= $cell($s['total']) ?></tr><?php endif; ?>
        </tbody>
        <?php endforeach; ?>
        <?php if (!$rowsN): ?><tbody><tr><td colspan="<?= $nc ?>" class="text-center text-muted py-4">Nothing to report for these options.</td></tr></tbody><?php endif; ?>
        <?php if ($data['grand']): ?><tfoot><tr><?= $cell($data['grand']) ?></tr></tfoot><?php endif; ?>
      </table>
      </div>
      <?php if ($data['note'] !== ''): ?><div class="print-note"><?= h($data['note']) ?></div><?php endif; ?>
      <div class="print-signs">
        <?php foreach ($data['signs'] ?? ['Prepared by', 'Checked by'] as $sg): ?><div class="print-sign"><span class="line"></span><span><?= h($sg) ?></span></div><?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php if ($embed): ?></div><?php endif; ?>
    <?php
    layout_end($embed);
    return;
}

// ---------------------------------------------------------------- Report Manager (the list)
$groups = Reports::available();
$count = array_sum(array_map('count', $groups));
layout_start('Report Manager');
?>
<div class="or-screen">
  <div class="or-toolbar d-print-none" role="toolbar" aria-label="Reports">
    <button type="button" class="tb" disabled data-row="frame" data-row-key="preview" data-row-default data-frame-size="xl"><i class="bi bi-file-earmark-text"></i>Preview</button>
    <button type="button" class="tb" disabled data-row="frame" data-row-key="options" data-frame-size="xl"><i class="bi bi-wrench"></i>Options</button>
    <button type="button" class="tb" disabled data-row="go" data-row-key="page"><i class="bi bi-box-arrow-up-right text-primary"></i>Open in Full Page</button>
    <span class="tb-sep"></span>
    <div class="tb-search"><input class="form-control form-control-sm" data-filter="#repGrid" placeholder="Type to find a report" aria-label="Find a report"><span class="tb-search-label">Alternative Search Key</span></div>
    <a class="tb" href="<?= url('reports') ?>"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
  </div>
  <div class="or-grid-wrap report-list">
    <table class="or-grid" id="repGrid" data-select>
      <thead><tr><th class="ic"></th><th class="ic"></th><th style="width:34%">Report Description</th><th>Remarks</th></tr></thead>
      <?php foreach ($groups as $g => $reports): $gid = 'rg-' . preg_replace('/[^a-z]+/', '-', strtolower($g)); ?>
      <tbody id="<?= $gid ?>">
        <tr class="rg-head"><td colspan="4"><button type="button" class="rg-toggle" data-collapse="<?= $gid ?>" aria-expanded="true" aria-controls="<?= $gid ?>"><i class="bi bi-dash-square" aria-hidden="true"></i>Report Group : <?= h($g) ?> (<?= count($reports) ?> item<?= count($reports) === 1 ? '' : 's' ?>)</button></td></tr>
        <?php foreach ($reports as $k => [, $title, $remarks]):
            $preview = url('reports', ['rep' => $k, 'run' => 1, 'embed' => 1]); $options = url('reports', ['rep' => $k, 'embed' => 1]); ?>
        <tr tabindex="0" data-id="<?= h($k) ?>" data-title="<?= h($title) ?>" data-preview="<?= h($preview) ?>" data-options="<?= h($options) ?>" data-page="<?= h(url('reports', ['rep' => $k])) ?>">
          <td class="ic"><a class="rep-ic" href="<?= h(url('reports', ['rep' => $k, 'run' => 1])) ?>" data-frame="<?= h($preview) ?>" data-frame-title="<?= h($title) ?>" data-frame-size="xl" title="Preview / print" aria-label="Preview <?= h($title) ?>"><i class="bi bi-file-earmark-text"></i></a></td>
          <td class="ic"><a class="rep-ic" href="<?= h(url('reports', ['rep' => $k])) ?>" data-frame="<?= h($options) ?>" data-frame-title="<?= h($title) ?>" data-frame-size="xl" title="Options" aria-label="Options for <?= h($title) ?>"><i class="bi bi-wrench"></i></a></td>
          <td><?= h($title) ?></td>
          <td class="rem" title="<?= h($remarks) ?>"><?= h($remarks) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; if (!$count): ?><tbody><tr class="empty"><td colspan="4">No reports for your role.</td></tr></tbody><?php endif; ?>
    </table>
  </div>
</div>
<?php layout_end();
