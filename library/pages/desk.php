<?php
/** Circulation desk (v60.1 design): find the member, then check out, check in or renew by scanning; active loans with covers. */
declare(strict_types=1);
/** @var bool $isPost */
$bKey = (string) ($_GET['b'] ?? $_POST['b'] ?? '');
[$bType, $bRef] = array_pad(explode(':', $bKey, 2), 2, '');
$keep = $bKey !== '' ? ['b' => $bKey] : [];
$mode = in_array($_GET['mode'] ?? '', ['out', 'in', 'renew'], true) ? $_GET['mode'] : ($bKey !== '' ? 'out' : 'in');
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    switch ($a) {
        case 'checkout':
            $id = Library::checkout($bType, $bRef, (string) ($_POST['barcode'] ?? ''), (string) ($_POST['due_on'] ?? ''));
            $l = Db::one('SELECT l.due_on, t.title FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id WHERE l.id = ?', [$id]);
            flash('success', 'Checked out: ' . $l['title'] . ' · due ' . date('D, M j', strtotime($l['due_on'])) . '.');
            $keep['mode'] = 'out';
            break;
        case 'checkin':
            $r = Library::checkin((string) ($_POST['barcode'] ?? ''), !empty($_POST['damaged']), Money::cents((string) ($_POST['damage'] ?? '0')), trim((string) ($_POST['note'] ?? '')));
            $msg = $r['found'] ? 'Found: ' . $r['title'] . ' was missing and is back on the shelf.' : 'Checked in: ' . $r['title'] . ($r['loan'] ? ' (' . $r['loan']['borrower_name'] . ')' : '') . '.';
            if (!$r['found'] && ($r['late'] ?? 0) > 0) $msg .= ' ' . $r['late'] . ' day(s) late' . ($r['fine'] ? ' — fine ₱' . Money::fmt($r['fine']) . ' charged.' : '.');
            if ($r['hold']) $msg .= ' Put it on the HOLD SHELF for ' . $r['hold']['borrower_name'] . ' (until ' . date('M j', strtotime((string) $r['hold']['expires_on'])) . ').';
            flash($r['hold'] || ($r['fine'] ?? 0) ? 'warning' : 'success', $msg);
            $keep['mode'] = 'in';
            break;
        case 'renew_scan':
            [$due, $title, $who] = Library::renewByBarcode((string) ($_POST['barcode'] ?? ''));
            flash('success', 'Renewed: ' . $title . ' (' . $who . ') · due ' . date('D, M j', strtotime($due)) . '.');
            $keep['mode'] = 'renew';
            break;
        case 'renew':
            $due = Library::renew((int) ($_POST['loan'] ?? 0));
            flash('success', 'Renewed until ' . date('D, M j', strtotime($due)) . '.');
            break;
        case 'lost':
            Library::markLost((int) ($_POST['loan'] ?? 0), 'Reported lost at the desk');
            flash('warning', 'Marked lost. The replacement price was charged as a fine.');
            break;
        case 'cancel_hold': Library::cancelHold((int) ($_POST['hold'] ?? 0)); flash('success', 'Reservation cancelled.'); break;
        case 'waive': Library::waive((int) ($_POST['fine'] ?? 0), (string) ($_POST['reason'] ?? '')); flash('success', 'Fine waived.'); break;
        default: throw new AppError('Unknown action.');
    }
    redirect('circulation', $keep);
}
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '' && $bKey === '') {
    if ($found = Library::borrowerByCode($q)) redirect('circulation', ['b' => $found['type'] . ':' . $found['ref']]);
    $matches = Library::findBorrowers($q);
}
$b = $bKey !== '' ? Library::borrower($bType, $bRef) : null;
$acc = $b ? Library::account($b['type'], $b['ref']) : null;
$canDo = Auth::can('library.circulate');
$peso = fn(int $c) => '₱' . Money::fmt($c);
$ini = fn(string $n) => mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/[\s,]+/', trim($n)) ?: [], 0, 2))));
layout_start('Circulation');
lib_page_head('arrow-left-right', 'Circulation desk', 'Check out, check in, and renew library materials.', '<a class="btn btn-outline-primary" href="' . h(url('loans')) . '"><i class="bi bi-list-ul me-1"></i>All loans &amp; overdue</a>');
?>
<div class="lib-desk-top">
  <section class="lib-card">
    <h2 class="lib-card-title">Find member</h2>
    <form method="get" class="lib-find"><input type="hidden" name="r" value="circulation">
      <i class="bi bi-person" aria-hidden="true"></i><input name="q" value="<?= h($q) ?>" placeholder="Student ID, Teacher ID or name" aria-label="Find member: scan the ID card or type an ID or name" autocomplete="off" <?= $b ? '' : 'autofocus' ?>>
      <button aria-label="Find"><i class="bi bi-search" aria-hidden="true"></i></button></form>
    <?php if (isset($matches)): ?><div class="lib-matches"><?php foreach ($matches as $m): ?><a href="<?= url('circulation', ['b' => $m['type'] . ':' . $m['ref']]) ?>"><b><?= h($m['name']) ?></b><span><?= h($m['no']) ?> · <?= h($m['detail'] ?: Library::BORROWER_TYPES[$m['type']]) ?></span></a><?php endforeach; ?>
      <?php if (!$matches): ?><p class="lib-empty mb-0">No student or teacher matches “<?= h($q) ?>”.</p><?php endif; ?></div><?php endif; ?>
  </section>
  <section class="lib-card lib-member<?= $b ? '' : ' is-empty' ?>">
    <div class="lib-member-head">Current member<?php if ($b): ?><a href="<?= url('circulation', ['mode' => 'in']) ?>">Clear</a><?php endif; ?></div>
    <?php if ($b): ?>
    <div class="lib-member-body">
      <span class="lib-avatar" aria-hidden="true"><?= h($ini($b['name'])) ?></span>
      <div class="lib-member-id"><b><?= h($b['name']) ?></b><span>ID: <?= h($b['no']) ?></span><span><?= h($b['type'] === 'TEACHER' ? 'Teacher' : ($b['detail'] ?: 'Student')) ?></span></div>
      <div class="lib-member-count"><i class="bi bi-stack" aria-hidden="true"></i><small>Active loans</small><b><?= count($acc['loans']) ?></b><em>of <?= (int) $acc['max_items'] ?></em></div>
    </div>
    <?php $od = count(array_filter($acc['loans'], fn($l) => (int) $l['days_late'] > 0)); if ($acc['fines_cents'] || $od): ?><div class="lib-member-warn"><?php if ($acc['fines_cents']): ?><span><i class="bi bi-exclamation-circle"></i> Unpaid fines <?= $peso($acc['fines_cents']) ?> (pay at the Cashier)</span><?php endif; ?><?php if ($od): ?><span><i class="bi bi-clock-history"></i> <?= $od ?> overdue</span><?php endif; ?></div><?php endif; ?>
    <?php else: ?><p class="lib-empty mb-0">Scan a library card or find a member to check out books. Check-in and renew work without a member.</p><?php endif; ?>
  </section>
</div>

<?php if ($canDo): ?>
<section class="lib-card lib-actions" data-lib-tabs data-mode="<?= h($mode) ?>">
  <div class="lib-tabbar" role="tablist">
    <button type="button" role="tab" data-tab="out">Check-out</button><button type="button" role="tab" data-tab="in">Check-in</button><button type="button" role="tab" data-tab="renew">Renew</button>
  </div>
  <div class="lib-panel" data-panel="out" role="tabpanel">
    <h2><i class="bi bi-arrow-right" aria-hidden="true"></i>Check-out</h2>
    <?php if ($b): ?><p>Scan or enter the book barcode or ISBN to check out to the current member.</p>
    <form method="post" class="lib-scanrow"><?= csrf_field() ?><input type="hidden" name="action" value="checkout"><input type="hidden" name="b" value="<?= h($bKey) ?>">
      <label class="lib-scan-input"><i class="bi bi-upc-scan" aria-hidden="true"></i><input name="barcode" required placeholder="Book barcode or ISBN" aria-label="Book barcode or ISBN" autocomplete="off" data-scan></label>
      <input class="form-control lib-due" type="date" name="due_on" min="<?= date('Y-m-d') ?>" aria-label="Due date (optional)" title="Optional: leave empty for the usual <?= (int) $acc['loan_days'] ?> days">
      <button class="btn btn-primary lib-go"><i class="bi bi-arrow-right" aria-hidden="true"></i>Check out</button></form>
    <p class="lib-hint">Due in <?= (int) $acc['loan_days'] ?> days unless you pick a date.</p>
    <?php else: ?><p class="lib-empty">Find a member first.</p><?php endif; ?>
  </div>
  <div class="lib-panel" data-panel="in" role="tabpanel">
    <h2><i class="bi bi-arrow-left" aria-hidden="true"></i>Check-in</h2><p>Scan the book barcode. Overdue fines are charged automatically; reserved titles go to the hold shelf.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="checkin"><input type="hidden" name="b" value="<?= h($bKey) ?>">
      <div class="lib-scanrow"><label class="lib-scan-input"><i class="bi bi-upc-scan" aria-hidden="true"></i><input name="barcode" required placeholder="Book barcode" aria-label="Book barcode" autocomplete="off" data-scan></label>
        <button class="btn btn-primary lib-go"><i class="bi bi-arrow-left" aria-hidden="true"></i>Check in</button></div>
      <label class="form-check small mt-2"><input class="form-check-input" type="checkbox" name="damaged" value="1" data-toggle-target="#libDamage"> Returned damaged</label>
      <div id="libDamage" class="lib-damage" hidden><div class="input-group input-group-sm"><span class="input-group-text">Damage fee ₱</span><input class="form-control" name="damage" inputmode="decimal" value="0"></div><input class="form-control form-control-sm" name="note" maxlength="255" placeholder="What is damaged?" aria-label="What is damaged?"></div>
    </form>
  </div>
  <div class="lib-panel" data-panel="renew" role="tabpanel">
    <h2><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Renew</h2><p>Scan the book barcode to extend its due date (within the loan rules).</p>
    <form method="post" class="lib-scanrow"><?= csrf_field() ?><input type="hidden" name="action" value="renew_scan"><input type="hidden" name="b" value="<?= h($bKey) ?>">
      <label class="lib-scan-input"><i class="bi bi-upc-scan" aria-hidden="true"></i><input name="barcode" required placeholder="Book barcode" aria-label="Book barcode to renew" autocomplete="off" data-scan></label>
      <button class="btn btn-primary lib-go"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Renew</button></form>
  </div>
</section>
<?php endif; ?>

<?php if ($b): ?>
<section class="lib-card">
  <h2 class="lib-card-title lg"><i class="bi bi-stack" aria-hidden="true"></i>Active loans (<?= count($acc['loans']) ?>)</h2>
  <div class="table-responsive"><table class="lib-table lib-loans"><thead><tr><th>Book</th><th>Borrowed</th><th>Due date</th><th>Status</th><th>Action</th></tr></thead><tbody>
  <?php foreach ($acc['loans'] as $l): $late = (int) $l['days_late']; ?>
    <tr><td><span class="lib-book"><?= Library::coverHtml((int) $l['title_id'], $l['title'], $l['cover_ver'], 'md') ?><span><b><?= h($l['title']) ?></b><small><?= h($l['authors']) ?></small><small><?= $l['isbn'] ? 'ISBN ' . h($l['isbn']) . ' · ' : '' ?><?= h($l['barcode']) ?></small></span></span></td>
      <td class="text-nowrap"><?= h(date('M j, Y', strtotime($l['loaned_at']))) ?></td><td class="text-nowrap"><?= h(date('M j, Y', strtotime($l['due_on']))) ?></td>
      <td><?= $late > 0 ? '<span class="lib-pill is-late">Overdue (' . $late . ' day' . ($late === 1 ? '' : 's') . ')</span>' . ($l['accruing'] ? '<small class="d-block text-danger mt-1">fine so far ' . $peso((int) $l['accruing']) . '</small>' : '') : ($late === 0 ? '<span class="lib-pill is-due">Due today</span>' : '<span class="lib-pill is-out">On loan</span>') ?><?= (int) $l['renewals'] ? '<small class="d-block text-muted mt-1">renewed ' . (int) $l['renewals'] . '×</small>' : '' ?></td>
      <td class="text-nowrap"><?php if ($canDo): ?>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="renew"><input type="hidden" name="b" value="<?= h($bKey) ?>"><input type="hidden" name="loan" value="<?= (int) $l['id'] ?>"><button class="lib-renew"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Renew</button></form>
        <form method="post" class="d-inline" data-confirm="Mark “<?= h($l['title']) ?>” lost? Its replacement price is charged as a fine."><?= csrf_field() ?><input type="hidden" name="action" value="lost"><input type="hidden" name="b" value="<?= h($bKey) ?>"><input type="hidden" name="loan" value="<?= (int) $l['id'] ?>"><button class="lib-link-danger">Lost</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; if (!$acc['loans']): ?><tr><td colspan="5" class="lib-empty">Nothing on loan.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php if ($acc['holds'] || $acc['fines']): ?>
<div class="lib-grid-2 even">
  <section class="lib-card"><h2 class="lib-card-title"><i class="bi bi-bookmark" aria-hidden="true"></i>Reservations</h2>
    <?php foreach ($acc['holds'] as $hd): ?><div class="lib-row"><span><?= h($hd['title']) ?> <?= $hd['status'] === 'READY' ? '<span class="lib-pill is-ready">Ready · until ' . h(date('M j', strtotime((string) $hd['expires_on']))) . '</span>' : '<span class="lib-pill is-muted">Waiting</span>' ?></span>
      <?php if ($canDo): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_hold"><input type="hidden" name="b" value="<?= h($bKey) ?>"><input type="hidden" name="hold" value="<?= (int) $hd['id'] ?>"><button class="lib-link-danger">Cancel</button></form><?php endif; ?></div><?php endforeach; ?>
    <?php if (!$acc['holds']): ?><p class="lib-empty mb-0">No reservations.</p><?php endif; ?></section>
  <section class="lib-card"><h2 class="lib-card-title"><i class="bi bi-coin" aria-hidden="true"></i>Unpaid fines <small>paid at the Cashier</small></h2>
    <?php foreach ($acc['fines'] as $f): ?><div class="lib-row"><span><?= h(Library::FINE_KINDS[$f['kind']]) ?> · <?= h($f['description']) ?> · <b><?= $peso((int) $f['amount_cents']) ?></b></span>
      <?php if (Auth::can('library.waive')): ?><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="waive"><input type="hidden" name="b" value="<?= h($bKey) ?>"><input type="hidden" name="fine" value="<?= (int) $f['id'] ?>"><input class="form-control form-control-sm" name="reason" required minlength="8" placeholder="Reason" aria-label="Reason to waive" style="width:150px"><button class="btn btn-sm btn-outline-secondary">Waive</button></form><?php endif; ?></div><?php endforeach; ?>
    <?php if (!$acc['fines']): ?><p class="lib-empty mb-0">No unpaid fines.</p><?php endif; ?></section>
</div>
<?php endif; ?>
<?php endif; ?>
<script src="assets/library.js?v=61" defer></script>
<?php layout_end();
