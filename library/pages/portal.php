<?php
/** Student Portal → Library (v60): search the catalog, place and cancel holds, see loans and renew, see unpaid fines. */
declare(strict_types=1);
/** @var bool $isPost */
$num = (string) Auth::studentNumber();
$st = $num !== '' ? GS::studentByNumber($num) : null;
if (!$st) { layout_start('Library'); page_title('Library'); echo '<div class="form-section"><p class="mb-0 text-muted">The library is available once you have a Student ID.</p></div>'; layout_end(); return; }
$ref = (string) $st['id'];
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'hold') { Library::placeHold((int) ($_POST['title'] ?? 0), 'STUDENT', $ref, 'PORTAL'); flash('success', 'Hold placed. We will keep it on the hold shelf when a copy is ready.'); }
    elseif ($a === 'cancel') { Library::cancelHold((int) ($_POST['hold'] ?? 0), true); flash('success', 'Hold cancelled.'); }
    elseif ($a === 'renew') { $due = Library::renew((int) ($_POST['loan'] ?? 0), true); flash('success', 'Renewed. New due date: ' . date('F j', strtotime($due)) . '.'); }
    redirect('library', array_filter(['q' => $_POST['q'] ?? null]));
}
$acc = Library::account('STUDENT', $ref);
$q = trim((string) ($_GET['q'] ?? ''));
$results = $q !== '' ? Library::search($q, '', 40) : [];
$peso = fn(int $c) => '₱' . Money::fmt($c);
$maxRenew = Library::rule('student_max_renewals');
layout_start('Library');
page_title('Library', '', 'Search the catalog, reserve books, and see what you have borrowed.');
?>
<?php foreach ($acc['holds'] as $hd): if ($hd['status'] !== 'READY') continue; ?>
<div class="alert alert-success"><i class="bi bi-bookmark-check me-1"></i><b><?= h($hd['title']) ?></b> is ready for you at the library. Pick it up by <?= h(date('l, F j', strtotime((string) $hd['expires_on']))) ?>.</div>
<?php endforeach; ?>
<?php foreach ($acc['loans'] as $l): if ((int) $l['days_late'] <= 0) continue; ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><b><?= h($l['title']) ?></b> is <?= (int) $l['days_late'] ?> day(s) overdue. Please return it. Fine so far: <?= $peso((int) $l['accruing']) ?>.</div>
<?php endforeach; ?>
<?php if ($acc['fines_cents']): ?><div class="alert alert-warning">Unpaid library fines: <b><?= $peso($acc['fines_cents']) ?></b>. Pay them at the Cashier.</div><?php endif; ?>
<section class="form-section">
  <form method="get" class="d-flex gap-2"><input type="hidden" name="r" value="library"><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Title, author, subject or ISBN" aria-label="Search the library catalog"><button class="btn btn-primary">Search</button></form>
  <?php if ($q !== ''): ?><div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0"><thead><tr><th>Title</th><th>Author(s)</th><th class="text-end">Available</th><th></th></tr></thead><tbody>
  <?php foreach ($results as $r): ?><tr><td><?= h($r['title']) ?><?= $r['pub_year'] ? ' <small class="text-muted">(' . (int) $r['pub_year'] . ')</small>' : '' ?><?= $r['call_no'] ? '<div class="small text-muted">Call no. ' . h($r['call_no']) . '</div>' : '' ?></td><td><?= h($r['authors']) ?></td>
    <td class="text-end"><?= (int) $r['available'] ? '<span class="text-success fw-semibold">' . (int) $r['available'] . ' on the shelf</span>' : '<span class="text-muted">All out' . ((int) $r['waiting'] ? ' · ' . (int) $r['waiting'] . ' waiting' : '') . '</span>' ?><?= (int) $r['reference_copies'] ? '<div class="small text-muted">' . (int) $r['reference_copies'] . ' for library use only</div>' : '' ?></td>
    <td class="text-end"><?php if ((int) $r['copies'] > (int) $r['reference_copies']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="hold"><input type="hidden" name="title" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>"><button class="btn btn-sm btn-outline-primary">Reserve</button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$results): ?><tr><td colspan="4" class="text-muted text-center">Nothing matches “<?= h($q) ?>”.</td></tr><?php endif; ?>
  </tbody></table></div><?php endif; ?>
</section>
<div class="row g-3">
  <div class="col-lg-7"><section class="form-section h-100"><h2 class="h6">Borrowed (<?= count($acc['loans']) ?> of <?= (int) $acc['max_items'] ?>)</h2>
    <?php foreach ($acc['loans'] as $l): $late = (int) $l['days_late'] > 0; ?><div class="d-flex justify-content-between align-items-center gap-2 py-2 border-bottom">
      <div><b><?= h($l['title']) ?></b><div class="small <?= $late ? 'text-danger' : 'text-muted' ?>">Due <?= h(date('D, M j', strtotime($l['due_on']))) ?><?= $late ? ' · overdue' : '' ?></div></div>
      <?php if (!$late && (int) $l['renewals'] < $maxRenew): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="renew"><input type="hidden" name="loan" value="<?= (int) $l['id'] ?>"><button class="btn btn-sm btn-outline-primary">Renew</button></form><?php endif; ?></div>
    <?php endforeach; if (!$acc['loans']): ?><p class="text-muted mb-0">You have nothing borrowed.</p><?php endif; ?></section></div>
  <div class="col-lg-5"><section class="form-section h-100"><h2 class="h6">Reserved</h2>
    <?php foreach ($acc['holds'] as $hd): ?><div class="d-flex justify-content-between align-items-center gap-2 py-2 border-bottom"><div><?= h($hd['title']) ?><div class="small text-muted"><?= $hd['status'] === 'READY' ? 'Ready to pick up' : 'Waiting for a copy' ?></div></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="hold" value="<?= (int) $hd['id'] ?>"><button class="btn btn-sm btn-link text-danger">Cancel</button></form></div>
    <?php endforeach; if (!$acc['holds']): ?><p class="text-muted mb-0">No reservations.</p><?php endif; ?>
    <?php if ($acc['fines']): ?><h2 class="h6 mt-3">Fines</h2><ul class="small mb-0"><?php foreach ($acc['fines'] as $f): ?><li><?= h($f['description']) ?> — <?= $peso((int) $f['amount_cents']) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </section></div>
</div>
<?php layout_end();
