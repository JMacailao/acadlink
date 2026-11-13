<?php
/** Loans and overdue: everything out, overdue items, and printable overdue notices (one per borrower). */
declare(strict_types=1);
$f = ['overdue' => !empty($_GET['overdue']), 'type' => (string) ($_GET['type'] ?? ''), 'q' => trim((string) ($_GET['q'] ?? ''))];
$rows = Library::loans($f);
$peso = fn(int $c) => '₱' . Money::fmt($c);
$notices = isset($_GET['notices']);
layout_start($notices ? 'Overdue notices' : 'Loans & Overdue');
if (!$notices) { lib_page_head('journal-arrow-up', 'All loans & overdue', 'Everything on loan, overdue items and printable notices.'); lib_tabs(['circulation' => 'Desk', 'loans' => 'All loans & overdue'], 'loans'); }
if ($notices):
    $by = [];
    foreach (Library::loans(['overdue' => 1, 'type' => $f['type']]) as $l) $by[$l['borrower_type'] . ':' . $l['borrower_ref']][] = $l;
?>
<div class="d-print-none mb-3 d-flex gap-2"><a class="btn btn-outline-secondary" href="<?= url('loans', ['overdue' => 1]) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a><button type="button" class="btn btn-primary" data-page-print><i class="bi bi-printer me-1"></i>Print <?= count($by) ?> notice(s)</button></div>
<?php foreach ($by as $loans): $l0 = $loans[0]; $owed = Library::studentOwes($l0['borrower_type'] === 'STUDENT' ? $l0['borrower_no'] : ''); ?>
<article class="lib-notice">
  <header><b><?= h(School::name()) ?> Library</b><span><?= h(date('F j, Y')) ?></span></header>
  <h2>Overdue notice</h2>
  <p>To: <b><?= h($l0['borrower_name']) ?></b> (<?= h(Library::BORROWER_TYPES[$l0['borrower_type']]) ?> <?= h($l0['borrower_no']) ?>)</p>
  <p>The following item(s) borrowed from the library are past their due date. Please return them as soon as possible.</p>
  <table><thead><tr><th>Title</th><th>Barcode</th><th>Due</th><th>Days late</th><th>Fine so far</th></tr></thead><tbody>
  <?php foreach ($loans as $l): ?><tr><td><?= h($l['title']) ?></td><td><?= h($l['barcode']) ?></td><td><?= h(date('M j, Y', strtotime($l['due_on']))) ?></td><td><?= (int) $l['days_late'] ?></td><td><?= $peso(Library::overdueFine($l['borrower_type'], (int) $l['days_late'])) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="small">Fines are charged when the item is returned and are paid at the Cashier.<?= $owed ? ' Unpaid library fines now: ' . $peso($owed) . '.' : '' ?></p>
  <footer>Librarian: ______________________</footer>
</article>
<?php endforeach; if (!$by): ?><p class="text-muted">No overdue items.</p><?php endif; ?>
<script src="assets/library.js?v=61" defer></script>
<?php layout_end(); return; endif; ?>
<form method="get" class="d-flex flex-wrap gap-2 mb-2"><input type="hidden" name="r" value="loans">
  <input class="form-control" style="max-width:280px" name="q" value="<?= h($f['q']) ?>" placeholder="Borrower, title or barcode" aria-label="Search loans">
  <select class="form-select" style="max-width:160px" name="type" aria-label="Borrower type"><option value="">Everyone</option><?php foreach (Library::BORROWER_TYPES as $k => $v): ?><option value="<?= $k ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= $v ?>s</option><?php endforeach; ?></select>
  <label class="form-check align-self-center"><input class="form-check-input" type="checkbox" name="overdue" value="1"<?= $f['overdue'] ? ' checked' : '' ?>> Overdue only</label>
  <button class="btn btn-outline-secondary">Show</button>
  <a class="btn btn-primary ms-auto" href="<?= url('loans', ['notices' => 1, 'type' => $f['type']]) ?>"><i class="bi bi-envelope-paper me-1"></i>Print overdue notices</a></form>
<div class="table-responsive form-section"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Borrower</th><th>Title</th><th>Barcode</th><th>Borrowed</th><th>Due</th><th class="text-end">Fine so far</th></tr></thead><tbody>
  <?php foreach ($rows as $l): $late = (int) $l['days_late'] > 0; ?><tr>
    <td><a href="<?= url('circulation', ['b' => $l['borrower_type'] . ':' . $l['borrower_ref']]) ?>"><?= h($l['borrower_name']) ?></a><div class="small text-muted"><?= h(Library::BORROWER_TYPES[$l['borrower_type']]) ?> <?= h($l['borrower_no']) ?></div></td>
    <td><?= h($l['title']) ?></td><td class="font-monospace small"><?= h($l['barcode']) ?></td><td class="small"><?= h(date('M j', strtotime($l['loaned_at']))) ?></td>
    <td class="<?= $late ? 'text-danger fw-semibold' : '' ?>"><?= h(date('M j', strtotime($l['due_on']))) ?><?= $late ? ' · ' . (int) $l['days_late'] . 'd late' : '' ?></td>
    <td class="text-end"><?= $late ? $peso(Library::overdueFine($l['borrower_type'], (int) $l['days_late'])) : '—' ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing on loan<?= $f['overdue'] ? ' is overdue' : '' ?>.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php layout_end();
