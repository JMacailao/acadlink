<?php
/** Year levels: the grading system's year levels, with their sections, students and assessment names. */
declare(strict_types=1);
$sy = selected_sy();
$secs = RecordList::rows(fn()=>GS::sections($sy));
$students = [];
foreach (Db::all("SELECT f_yearLevel AS y, COUNT(*) AS n FROM docs WHERE col = 'students' AND f_schoolYear = ? GROUP BY f_yearLevel", [$sy]) as $r) $students[$r['y']] = (int) $r['n'];
$regs = [];
foreach (Db::all("SELECT year_level AS y, COUNT(*) AS n, COALESCE(SUM(total_amount - amount_paid),0) AS bal FROM sis_registrations WHERE school_year = ? AND status = 'REGISTERED' GROUP BY year_level", [$sy]) as $r) $regs[$r['y']] = $r;
$names = Assessment::names(true);
layout_start('Year levels');
page_title('Year Levels', '<form method="get" id="yearFilters" data-record-filters class="d-flex"><input type="hidden" name="r" value="yearlevels"><input type="hidden" name="apply" value="1"><select class="form-select" name="sy" data-autosubmit>' . sy_options($sy) . '</select></form>', 'The year levels used by the grading system.');
?>
<?php $canMoney = Auth::can('payments.view'); ?>
<div class="table-responsive"><table class="table" data-record-noun="year level" data-record-title="Year Level"><thead><tr><th>Year Level</th><th class="text-end">Sections</th><th class="text-end">Students</th><th class="text-end">Registered</th><?php if ($canMoney): ?><th class="text-end">Unpaid Balance</th><?php endif; ?><th>Section Names</th><th>Fee Templates</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach (RecordList::applied()?YEAR_LEVELS:[] as $yl): $ys = array_filter($secs, fn($s) => ($s['yearLevel'] ?? '') === $yl); $yn = array_filter($names, fn($n) => $n['year_level'] === null || $n['year_level'] === $yl); ?>
<tr><td><?= h($yl) ?></td><td class="text-end"><?= count($ys) ?></td><td class="text-end"><?= $students[$yl] ?? 0 ?></td><td class="text-end"><?= (int) ($regs[$yl]['n'] ?? 0) ?></td>
<?php if ($canMoney): ?><td class="text-end"><?= isset($regs[$yl]) ? '₱' . Money::fmt(Money::cents($regs[$yl]['bal'])) : '—' ?></td><?php endif; ?>
<td><?= $ys ? h(implode(', ', array_column($ys, 'sectionName'))) : '<span class="text-muted">None</span>' ?></td>
<td><?= $yn ? h(implode(', ', array_column($yn, 'name'))) : '<span class="text-muted">None</span>' ?></td>
<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('students', ['sy' => $sy, 'year' => $yl, 'apply' => 1]) ?>" data-icon="people">View Students</a> <a class="btn btn-sm btn-outline-primary" href="<?= url('sections', ['sy' => $sy, 'year' => $yl, 'apply' => 1]) ?>" data-icon="collection">View Sections</a></td></tr>
<?php endforeach; if (!RecordList::applied()): ?><tr><td colspan="<?= $canMoney ? 8 : 7 ?>" class="text-center text-muted py-4">Choose a school year, then click Apply Filters.</td></tr><?php endif; ?>
</tbody></table></div>
<?php layout_end();
