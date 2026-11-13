<?php
/** Library overview (v60.1): catalog size, today's borrowing, overdue, active members, today's circulation, popular titles, due soon. */
declare(strict_types=1);
if (!Auth::can('library.view')) redirect(Auth::can('library.catalog') ? 'catalog' : 'reports');
$o = Library::overview();
$who = fn(array $r) => $r['borrower_type'] === 'STUDENT' ? (($s = GS::get('students', (string) $r['borrower_ref'])) && !empty($s['sectionName']) ? ' (' . $s['sectionName'] . ')' : '') : ' (Teacher)';
layout_start('Library overview');
$school = in_array(School::name(), ['School Information System', 'AcadLink'], true) ? 'School library' : School::name() . ' Library';
lib_page_head('house-door', 'Library overview', $school, '<span class="lib-date"><i class="bi bi-calendar3" aria-hidden="true"></i>' . h(date('D, M j, Y')) . '</span>');
?>
<div class="lib-stats">
  <a class="lib-stat is-accent" href="<?= url('catalog') ?>"><span class="lib-stat-icon"><i class="bi bi-book" aria-hidden="true"></i></span><span><small>Books in catalog</small><b><?= number_format($o['copies']) ?></b><em><?= number_format($o['titles']) ?> titles</em></span></a>
  <a class="lib-stat" href="<?= url('loans') ?>"><span class="lib-stat-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span><span><small>Borrowed today</small><b><?= number_format($o['today']) ?></b><em><?= number_format($o['returned_today']) ?> returned</em></span></a>
  <a class="lib-stat" href="<?= url('loans', ['overdue' => 1]) ?>"><span class="lib-stat-icon"><i class="bi bi-bookmark" aria-hidden="true"></i></span><span><small>Overdue</small><b class="<?= $o['overdue'] ? 'text-danger' : '' ?>"><?= number_format($o['overdue']) ?></b><em><?= number_format($o['ready']) ?> holds ready</em></span></a>
  <a class="lib-stat" href="<?= url('members') ?>"><span class="lib-stat-icon"><i class="bi bi-people" aria-hidden="true"></i></span><span><small>Active members</small><b><?= number_format($o['members']) ?></b><em>borrowed in the last year</em></span></a>
</div>
<div class="lib-grid-2">
  <section class="lib-card">
    <div class="lib-card-head"><h2><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Today’s circulation</h2><a href="<?= url('circulation') ?>">View circulation <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
    <div class="table-responsive"><table class="lib-table"><thead><tr><th>Time</th><th>Member</th><th>Title</th><th>Type</th></tr></thead><tbody>
    <?php foreach ($o['activity'] as $a): ?><tr><td class="text-nowrap"><?= h(date('g:i A', strtotime((string) $a['at']))) ?></td><td><?= h($a['borrower_name'] . $who($a)) ?></td>
      <td><span class="lib-book"><?= Library::coverHtml((int) $a['title_id'], $a['title'], $a['cover_ver'], 'xs') ?><a href="<?= url('catalog', ['id' => $a['title_id']]) ?>"><?= h($a['title']) ?></a></span></td>
      <td><span class="lib-pill <?= $a['kind'] === 'Borrowed' ? 'is-out' : 'is-muted' ?>"><?= h($a['kind']) ?></span></td></tr>
    <?php endforeach; if (!$o['activity']): ?><tr><td colspan="4" class="lib-empty">No check-outs or returns yet today.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <div class="lib-stack">
    <section class="lib-card">
      <div class="lib-card-head"><h2><i class="bi bi-fire" aria-hidden="true"></i>Popular titles</h2><a href="<?= url('catalog') ?>">View catalog <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
      <ul class="lib-list">
      <?php foreach ($o['popular'] as $p): ?><li><span class="lib-book"><?= Library::coverHtml((int) $p['id'], $p['title'], $p['cover_ver'], 'xs') ?><span><a href="<?= url('catalog', ['id' => $p['id']]) ?>"><b><?= h($p['title']) ?></b></a><small><?= h($p['authors']) ?></small></span></span><span class="lib-pill is-out"><?= (int) $p['n'] ?> borrow<?= (int) $p['n'] === 1 ? '' : 's' ?></span></li>
      <?php endforeach; if (!$o['popular']): ?><li class="lib-empty">No loans in the last year yet.</li><?php endif; ?>
      </ul>
    </section>
    <section class="lib-card">
      <div class="lib-card-head"><h2><i class="bi bi-calendar-week" aria-hidden="true"></i>Due soon</h2><a href="<?= url('loans') ?>">View all <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div>
      <div class="table-responsive"><table class="lib-table"><thead><tr><th>Title</th><th>Member</th><th>Due date</th></tr></thead><tbody>
      <?php foreach ($o['due'] as $d): ?><tr><td><span class="lib-book"><?= Library::coverHtml((int) $d['title_id'], $d['title'], $d['cover_ver'], 'xs') ?><?= h($d['title']) ?></span></td>
        <td><a href="<?= url('circulation', ['b' => $d['borrower_type'] . ':' . $d['borrower_ref']]) ?>"><?= h($d['borrower_name'] . $who($d)) ?></a></td><td class="text-nowrap"><?= $d['due_on'] === date('Y-m-d') ? '<b>Today</b>' : h(date('M j, Y', strtotime($d['due_on']))) ?></td></tr>
      <?php endforeach; if (!$o['due']): ?><tr><td colspan="3" class="lib-empty">Nothing due in the next 3 days.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </div>
</div>
<?php layout_end();
