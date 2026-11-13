<?php
/** Circulation desk: find the borrower (scan the ID), check out by scanning copies, return, renew, holds, lost items. */
declare(strict_types=1);
/** @var bool $isPost */
if (!Auth::can('library.view')) redirect(Auth::can('library.catalog') ? 'catalog' : 'reports');
$bKey = (string) ($_GET['b'] ?? $_POST['b'] ?? '');
[$bType, $bRef] = array_pad(explode(':', $bKey, 2), 2, '');
$keep = $bKey !== '' ? ['b' => $bKey] : [];
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    switch ($a) {
        case 'checkout':
            $id = Library::checkout($bType, $bRef, (string) ($_POST['barcode'] ?? ''), (string) ($_POST['due_on'] ?? ''));
            $l = Db::one('SELECT l.due_on, t.title FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id WHERE l.id = ?', [$id]);
            flash('success', 'Checked out: ' . $l['title'] . ' · due ' . date('D, M j', strtotime($l['due_on'])) . '.');
            break;
        case 'checkin':
            $r = Library::checkin((string) ($_POST['barcode'] ?? ''), !empty($_POST['damaged']), Money::cents((string) ($_POST['damage'] ?? '0')), trim((string) ($_POST['note'] ?? '')));
            $msg = $r['found'] ? 'Found: ' . $r['title'] . ' was missing and is back on the shelf.' : 'Returned: ' . $r['title'] . ($r['loan'] ? ' (' . $r['loan']['borrower_name'] . ')' : '') . '.';
            if (!$r['found'] && ($r['late'] ?? 0) > 0) $msg .= ' ' . $r['late'] . ' day(s) late' . ($r['fine'] ? ' — fine ₱' . Money::fmt($r['fine']) . ' charged.' : '.');
            if ($r['hold']) $msg .= ' Put it on the HOLD SHELF for ' . $r['hold']['borrower_name'] . ' (until ' . date('M j', strtotime((string) $r['hold']['expires_on'])) . ').';
            flash($r['hold'] || ($r['fine'] ?? 0) ? 'warning' : 'success', $msg);
            if (!empty($_POST['returns_only'])) redirect('dashboard');
            break;
        case 'renew':
            $due = Library::renew((int) ($_POST['loan'] ?? 0));
            flash('success', 'Renewed until ' . date('D, M j', strtotime($due)) . '.');
            break;
        case 'lost':
            Library::markLost((int) ($_POST['loan'] ?? 0), (string) ($_POST['note'] ?? ''));
            flash('warning', 'Marked lost. The replacement price was charged as a fine.');
            break;
        case 'cancel_hold':
            Library::cancelHold((int) ($_POST['hold'] ?? 0));
            flash('success', 'Hold cancelled.');
            break;
        case 'waive':
            Library::waive((int) ($_POST['fine'] ?? 0), (string) ($_POST['reason'] ?? ''));
            flash('success', 'Fine waived.');
            break;
        default: throw new AppError('Unknown action.');
    }
    redirect('dashboard', $keep);
}
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '' && $bKey === '') {
    if ($b = Library::borrowerByCode($q)) redirect('dashboard', ['b' => $b['type'] . ':' . $b['ref']]);
    $matches = Library::findBorrowers($q);
}
$b = $bKey !== '' ? Library::borrower($bType, $bRef) : null;
$acc = $b ? Library::account($b['type'], $b['ref']) : null;
$peso = fn(int $c) => '₱' . Money::fmt($c);
$today = date('Y-m-d');
layout_start('Circulation Desk');
?>
<div class="row g-3">
  <div class="col-xl-8">
    <form method="get" class="form-section lib-scan" data-autofocus><input type="hidden" name="r" value="dashboard">
      <label class="form-label fw-semibold" for="libWho"><i class="bi bi-person-vcard me-1"></i>Borrower: scan the ID card, or type a Student ID, Teacher ID or name</label>
      <div class="d-flex gap-2"><input class="form-control form-control-lg" id="libWho" name="q" value="<?= h($q) ?>" autocomplete="off" <?= $b ? '' : 'autofocus' ?>><button class="btn btn-primary">Find</button>
        <?php if ($b): ?><a class="btn btn-outline-secondary" href="<?= url('dashboard') ?>">Next borrower</a><?php endif; ?></div>
    </form>
    <?php if (isset($matches)): ?><section class="form-section"><h2 class="h6">Choose the borrower</h2>
      <?php foreach ($matches as $m): ?><a class="d-flex justify-content-between border rounded-3 p-2 mb-1 text-decoration-none" href="<?= url('dashboard', ['b' => $m['type'] . ':' . $m['ref']]) ?>"><span><b><?= h($m['name']) ?></b> <span class="text-muted"><?= h($m['no']) ?></span></span><span class="badge text-bg-light border"><?= h(Library::BORROWER_TYPES[$m['type']]) ?> <?= h($m['detail'] !== 'Teacher' ? $m['detail'] : '') ?></span></a><?php endforeach; ?>
      <?php if (!$matches): ?><p class="text-muted mb-0">No student or teacher matches “<?= h($q) ?>”.</p><?php endif; ?></section><?php endif; ?>

    <?php if ($b): $out = count($acc['loans']); ?>
    <section class="form-section lib-borrower">
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div><h2 class="h5 mb-0"><?= h($b['name']) ?></h2><div class="text-muted small"><?= h(Library::BORROWER_TYPES[$b['type']]) ?> · <?= h($b['no']) ?><?= $b['detail'] && $b['detail'] !== 'Teacher' ? ' · ' . h($b['detail']) : '' ?></div></div>
        <div class="d-flex gap-3 text-center">
          <div><div class="fs-4 fw-bold"><?= $out ?>/<?= (int) $acc['max_items'] ?></div><div class="small text-muted">on loan</div></div>
          <div><div class="fs-4 fw-bold <?= $acc['fines_cents'] ? 'text-danger' : '' ?>"><?= $peso($acc['fines_cents']) ?></div><div class="small text-muted">unpaid fines</div></div>
        </div>
      </div>
      <?php if (Auth::can('library.circulate')): ?>
      <form method="post" class="lib-checkout mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="checkout"><input type="hidden" name="b" value="<?= h($bKey) ?>">
        <label class="form-label small fw-semibold" for="libOut"><i class="bi bi-upc-scan me-1"></i>Check out: scan the copy barcode</label>
        <div class="d-flex flex-wrap gap-2"><input class="form-control form-control-lg flex-grow-1" style="min-width:200px" id="libOut" name="barcode" required autocomplete="off" autofocus>
          <input class="form-control" type="date" name="due_on" min="<?= $today ?>" aria-label="Due date (optional)" title="Leave empty for the usual <?= (int) $acc['loan_days'] ?> days" style="max-width:170px">
          <button class="btn btn-primary">Check out</button></div>
        <div class="form-text">Due in <?= (int) $acc['loan_days'] ?> days unless you pick a date.</div>
      </form><?php endif; ?>
    </section>
    <section class="form-section"><h3 class="h6">On loan</h3>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Title</th><th>Barcode</th><th>Due</th><th class="text-end">Fine so far</th><th></th></tr></thead><tbody>
      <?php foreach ($acc['loans'] as $l): $late = (int) $l['days_late'] > 0; ?>
        <tr><td><?= h($l['title']) ?><div class="small text-muted"><?= h($l['authors']) ?></div></td><td class="font-monospace small"><?= h($l['barcode']) ?></td>
          <td class="<?= $late ? 'text-danger fw-semibold' : '' ?>"><?= h(date('M j', strtotime($l['due_on']))) ?><?= $late ? '<br><small>' . (int) $l['days_late'] . ' day(s) late</small>' : '' ?><?= (int) $l['renewals'] ? '<br><small class="text-muted">renewed ' . (int) $l['renewals'] . '×</small>' : '' ?></td>
          <td class="text-end"><?= $l['accruing'] ? $peso((int) $l['accruing']) : '—' ?></td>
          <td class="text-end text-nowrap"><?php if (Auth::can('library.circulate')): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="renew"><input type="hidden" name="b" value="<?= h($bKey) ?>"><input type="hidden" name="loan" value="<?= (int) $l['id'] ?>"><button class="btn btn-sm btn-outline-primary">Renew</button></form>
            <form method="post" class="d-inline" data-confirm="Mark this item lost? Its replacement price is charged as a fine."><?= csrf_field() ?><input type="hidden" name="action" value="lost"><input type="hidden" name="b" value="<?= h($bKey) ?>"><input type="hidden" name="loan" value="<?= (int) $l['id'] ?>"><input type="hidden" name="note" value="Reported lost at the desk"><button class="btn btn-sm btn-outline-danger">Lost</button></form>
          <?php endif; ?></td></tr>
      <?php endforeach; if (!$acc['loans']): ?><tr><td colspan="5" class="text-muted text-center">Nothing on loan.</td></tr><?php endif; ?>
      </tbody></table></div></section>
    <?php if ($acc['holds']): ?><section class="form-section"><h3 class="h6">Holds</h3><ul class="list-unstyled mb-0">
      <?php foreach ($acc['holds'] as $hd): ?><li class="d-flex justify-content-between align-items-center gap-2 py-1 border-bottom"><span><?= h($hd['title']) ?> · <?= $hd['status'] === 'READY' ? '<span class="badge text-bg-success">Ready on the hold shelf</span> ' . h((string) $hd['barcode']) . ' until ' . h(date('M j', strtotime((string) $hd['expires_on']))) : '<span class="badge text-bg-secondary">Waiting</span>' ?></span>
        <?php if (Auth::can('library.circulate')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_hold"><input type="hidden" name="b" value="<?= h($bKey) ?>"><input type="hidden" name="hold" value="<?= (int) $hd['id'] ?>"><button class="btn btn-sm btn-link text-danger">Cancel</button></form><?php endif; ?></li><?php endforeach; ?>
    </ul></section><?php endif; ?>
    <?php if ($acc['fines']): ?><section class="form-section"><h3 class="h6">Unpaid fines <small class="text-muted fw-normal">— paid at the Cashier (SIS or Finance → Library Fines)</small></h3>
      <?php foreach ($acc['fines'] as $f): ?><div class="d-flex flex-wrap justify-content-between gap-2 py-1 border-bottom"><span><?= h(Library::FINE_KINDS[$f['kind']]) ?> · <?= h($f['description']) ?> · <b><?= $peso((int) $f['amount_cents']) ?></b></span>
        <?php if (Auth::can('library.waive')): ?><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="waive"><input type="hidden" name="b" value="<?= h($bKey) ?>"><input type="hidden" name="fine" value="<?= (int) $f['id'] ?>"><input class="form-control form-control-sm" name="reason" required minlength="8" placeholder="Reason to waive" aria-label="Reason to waive" style="width:180px"><button class="btn btn-sm btn-outline-secondary">Waive</button></form><?php endif; ?></div><?php endforeach; ?>
    </section><?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="col-xl-4">
    <?php if (Auth::can('library.circulate')): ?>
    <form method="post" class="form-section lib-return"><?= csrf_field() ?><input type="hidden" name="action" value="checkin"><input type="hidden" name="b" value="<?= h($bKey) ?>"><?php if (!$b): ?><input type="hidden" name="returns_only" value="1"><?php endif; ?>
      <h2 class="h6"><i class="bi bi-box-arrow-in-down me-1"></i>Return</h2>
      <label class="form-label small" for="libIn">Scan the copy barcode</label>
      <input class="form-control form-control-lg mb-2" id="libIn" name="barcode" required autocomplete="off">
      <label class="form-check small"><input class="form-check-input" type="checkbox" name="damaged" value="1" data-toggle-target="#libDamage"> Damaged</label>
      <div id="libDamage" hidden><div class="input-group input-group-sm my-1"><span class="input-group-text">Damage fee ₱</span><input class="form-control" name="damage" inputmode="decimal" value="0"></div>
        <input class="form-control form-control-sm mb-1" name="note" maxlength="255" placeholder="What is damaged?"></div>
      <button class="btn btn-outline-primary w-100 mt-1">Return</button>
      <p class="form-text mb-0">Overdue fines are charged automatically. If someone is waiting for the title, the copy goes to the hold shelf.</p>
    </form><?php endif; ?>
    <?php $due = Db::all("SELECT l.borrower_name, l.due_on, t.title FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id WHERE l.status = 'OPEN' AND l.due_on <= CURDATE() ORDER BY l.due_on LIMIT 8"); ?>
    <section class="form-section"><h2 class="h6">Due today and overdue</h2>
      <?php foreach ($due as $d): ?><div class="small py-1 border-bottom"><b><?= h($d['borrower_name']) ?></b> · <?= h($d['title']) ?> <span class="<?= $d['due_on'] < $today ? 'text-danger' : '' ?>">(<?= h(date('M j', strtotime($d['due_on']))) ?>)</span></div><?php endforeach; ?>
      <?php if (!$due): ?><p class="small text-muted mb-0">Nothing due.</p><?php else: ?><a class="small" href="<?= url('loans', ['overdue' => 1]) ?>">All overdue and notices →</a><?php endif; ?></section>
  </div>
</div>
<script src="assets/library.js?v=60" defer></script>
<?php layout_end();
