<?php
/** Members: students and teachers who borrow. Without a search, everyone with loans, reservations or unpaid fines. */
declare(strict_types=1);
$q = trim((string) ($_GET['q'] ?? ''));
$rows = Library::members($q);
$peso = fn(int $c) => '₱' . Money::fmt($c);
$ini = fn(string $n) => mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/[\s,]+/', trim($n)) ?: [], 0, 2))));
layout_start('Members');
lib_page_head('people', 'Members', 'Students and teachers on record can borrow. Search to find anyone.');
?>
<section class="lib-card">
  <form method="get" class="lib-find mb-3"><input type="hidden" name="r" value="members"><i class="bi bi-person" aria-hidden="true"></i><input name="q" value="<?= h($q) ?>" placeholder="Student ID, Teacher ID or name" aria-label="Search members" autofocus><button aria-label="Search"><i class="bi bi-search" aria-hidden="true"></i></button></form>
  <p class="lib-hint"><?= $q === '' ? 'Showing members with loans, reservations or unpaid fines.' : count($rows) . ' match(es) for “' . h($q) . '”.' ?></p>
  <div class="table-responsive"><table class="lib-table"><thead><tr><th>Member</th><th>Type</th><th class="text-end">On loan</th><th class="text-end">Overdue</th><th class="text-end">Reserved</th><th class="text-end">Unpaid fines</th><th class="text-end">All-time loans</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $m): ?><tr>
    <td><span class="lib-book"><span class="lib-avatar sm" aria-hidden="true"><?= h($ini($m['name'])) ?></span><span><b><?= h($m['name']) ?></b><small><?= h($m['no']) ?><?= $m['detail'] && $m['detail'] !== 'Teacher' ? ' · ' . h($m['detail']) : '' ?></small></span></span></td>
    <td><?= h(Library::BORROWER_TYPES[$m['type']]) ?></td><td class="text-end"><?= $m['loans'] ?></td>
    <td class="text-end"><?= $m['overdue'] ? '<span class="lib-pill is-late">' . $m['overdue'] . '</span>' : '0' ?></td><td class="text-end"><?= $m['holds'] ?></td>
    <td class="text-end <?= $m['fines'] ? 'text-danger fw-semibold' : '' ?>"><?= $peso($m['fines']) ?></td><td class="text-end text-muted"><?= $m['total'] ?></td>
    <td class="text-end"><a class="lib-renew" href="<?= url('circulation', ['b' => $m['type'] . ':' . $m['ref']]) ?>"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Open</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="lib-empty"><?= $q === '' ? 'No one has books out, reservations or fines right now.' : 'No student or teacher matches.' ?></td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php layout_end();
