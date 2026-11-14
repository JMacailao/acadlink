<?php
/** v63 Backup & Restore of the whole system (all workspaces). Only power.admin can open it. */
declare(strict_types=1);
/** @var bool $isPost */
if (!Backup::allowed()) throw new Forbidden('Only ' . Backup::OWNER . ' can back up and restore AcadLink.');
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'backup') {
        $name = Backup::create((array) ($_POST['parts'] ?? []), 'manual', (string) ($_POST['note'] ?? ''));
        flash('success', 'Backup made: ' . $name . '. Download it and keep a copy off this server (a USB drive or Google Drive).');
        redirect('backup', ['ready' => $name]);
    }
    if ($a === 'upload') { $name = Backup::receive($_FILES['file'] ?? []); flash('success', 'Backup file received. Choose what to restore below.'); redirect('backup', ['pick' => $name]); }
    if ($a === 'delete') { Backup::delete((string) ($_POST['file'] ?? '')); flash('success', 'Backup deleted from the server.'); redirect('backup'); }
    if ($a === 'restore') {
        $r = Backup::restore((string) ($_POST['file'] ?? ''), (array) ($_POST['parts'] ?? []), (string) ($_POST['confirm'] ?? ''));
        flash('success', 'Restore finished: ' . number_format($r['rows']) . ' records in ' . $r['tables'] . ' tables. A safety copy of what was there before is kept: ' . $r['safety'] . '.'
            . ($r['skipped'] ? ' Not restored (not in this version): ' . implode(', ', $r['skipped']) . '.' : '') . (isset($r['signin']) ? ' ' . $r['signin'] : ''));
        redirect('backup');
    }
    if ($a === 'auto') { // v63.14: automatic backups
        Backup::saveAuto((string) ($_POST['every'] ?? ''), (int) ($_POST['keep'] ?? 0));
        flash('success', ($_POST['every'] ?? '') === 'off' ? 'Automatic backups are off.' : 'Automatic backups saved.');
        redirect('backup');
    }
    if ($a === 'auto_now') { // v63.14: make the automatic backup right away (also tests that it works on this server)
        $name = Backup::runAuto('page', true);
        flash($name ? 'success' : 'warning', $name ? 'Automatic backup made: ' . $name . '.' : 'Another backup is running. Try again in a minute.');
        redirect('backup', $name ? ['ready' => $name] : []);
    }
    throw new AppError('Unknown action.');
}
if (isset($_GET['download'])) {
    $path = Backup::path((string) $_GET['download']);
    Audit::log('backup_downloaded', 'backup', 0, ['file' => basename($path)]);
    Backup::noteDownload(); // v63.14: for the "keep a copy off this server" reminder
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/gzip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="acadlink-backup-' . basename($path) . '"');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}
$sum = Backup::summary();
$stored = Backup::stored();
$pick = (string) ($_GET['pick'] ?? '');
$pickHead = $pick !== '' ? Backup::header(Backup::path($pick)) : [];
$mb = fn(int $b) => $b >= 1073741824 ? number_format($b / 1073741824, 1) . ' GB' : number_format(max($b, 1) / 1048576, 1) . ' MB';
layout_start('Backup & Restore');
page_title('Backup & Restore', '', 'A copy of all of AcadLink — every workspace — in one file. Only ' . h(Backup::OWNER) . ' can use this page.');
?>
<div class="row g-3">
<div class="col-xl-6">
  <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="backup">
    <h2 class="h6"><i class="bi bi-cloud-arrow-down me-1" aria-hidden="true"></i>Back up</h2>
    <p class="small text-muted">Tick what to include. Everything is ticked: that is a full backup.</p>
    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" data-check-all="parts[]" checked> <b>Select all</b></label>
    <div class="bk-parts">
    <?php foreach (Backup::PARTS as $k => [$label, $desc]): ?><label class="bk-part"><input class="form-check-input" type="checkbox" name="parts[]" value="<?= $k ?>" checked>
      <span><b><?= h($label) ?></b><small><?= h($desc) ?></small><small class="text-muted"><?= number_format($sum[$k]['rows']) ?> records · about <?= $mb($sum[$k]['bytes']) ?></small></span></label><?php endforeach; ?>
    </div>
    <input class="form-control form-control-sm my-2" name="note" maxlength="200" placeholder="Note (optional), e.g. Before enrollment for 2nd semester" aria-label="Note">
    <button class="btn btn-primary"><i class="bi bi-download me-1" aria-hidden="true"></i>Make backup</button>
    <p class="small text-muted mt-2 mb-0">The backup is saved on the server, then you can download it. Keep at least one copy somewhere else (USB drive or Google Drive): a copy on the same server does not help if the server fails.</p>
  </form>
</div>
<div class="col-xl-6">
  <section class="form-section">
    <h2 class="h6"><i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>Restore</h2>
    <?php if ($pickHead): ?>
    <form method="post" data-confirm="Replace the ticked parts with the backup from <?= h(date('M j, Y g:i A', strtotime($pickHead['created_at'] ?? 'now'))) ?>? A safety copy of the current data is made first."><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="file" value="<?= h($pick) ?>">
      <p class="small mb-2">Backup of <b><?= h(date('D, M j, Y g:i A', strtotime($pickHead['created_at'] ?? 'now'))) ?></b><?= !empty($pickHead['note']) ? ' · ' . h($pickHead['note']) : '' ?><?= !empty($pickHead['school']) ? ' · ' . h($pickHead['school']) : '' ?>. <a href="<?= url('backup') ?>">Choose another</a></p>
      <p class="small text-muted">Tick what to restore. Ticked parts are <b>replaced</b> by the backup; unticked parts stay as they are now.</p>
      <label class="form-check mb-2"><input class="form-check-input" type="checkbox" data-check-all="parts[]"> <b>Select all</b></label>
      <div class="bk-parts">
      <?php foreach (Backup::PARTS as $k => [$label, $desc]): $in = !empty($pickHead['parts'][$k]); ?><label class="bk-part<?= $in ? '' : ' is-off' ?>"><input class="form-check-input" type="checkbox" name="parts[]" value="<?= $k ?>"<?= $in ? '' : ' disabled' ?>>
        <span><b><?= h($label) ?></b><small><?= $in ? h($desc) : 'Not in this backup.' ?></small></span></label><?php endforeach; ?>
      </div>
      <div class="alert alert-warning small mt-2 mb-2">Restore related parts together (for example SIS records with Grading System and Cashier and accounting), so students, registrations and payments match.</div>
      <label class="form-label small" for="bkConfirm">Type <b>RESTORE</b> to confirm</label>
      <div class="d-flex gap-2"><input class="form-control" id="bkConfirm" name="confirm" autocomplete="off" required pattern="RESTORE" style="max-width:200px"><button class="btn btn-danger">Restore ticked parts</button></div>
      <p class="small text-muted mt-2 mb-0">It all happens at once: if anything goes wrong, nothing is changed. Before restoring, a safety copy of the ticked parts is saved on the server.</p>
    </form>
    <?php else: ?>
    <p class="small text-muted">Pick a backup from the list below, or upload a backup file you downloaded before.</p>
    <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <input class="form-control" type="file" name="file" accept=".gz,application/gzip" required aria-label="Backup file" style="max-width:340px"><button class="btn btn-outline-primary">Upload</button></form>
    <p class="small text-muted mt-2 mb-0">This server accepts uploads up to <?= h((string) ini_get('upload_max_filesize')) ?>. For a bigger file, copy it into the <code>backups</code> folder on the server; it then appears in the list below.</p>
    <?php endif; ?>
  </section>
</div>
</div>
<?php $auto = Backup::auto(); $hours = Backup::autoHours(); $lastOk = $auto['autobackup_last_ok'] !== '' ? strtotime($auto['autobackup_last_ok']) : 0;
  $cron = Backup::cronActive(); $cmd = 'php ' . (realpath(SIS_ROOT . '/bin/auto-backup.php') ?: SIS_ROOT . '/bin/auto-backup.php'); ?>
<section class="form-section" id="auto">
  <h2 class="h6"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Automatic backups</h2>
  <form method="post" class="row g-2 align-items-end mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="auto">
    <div class="col-sm-5"><label class="form-label" for="abEvery">Back up everything</label>
      <select class="form-select" id="abEvery" name="every"><?php foreach (Backup::AUTO_EVERY as $k => $label): ?><option value="<?= h($k) ?>"<?= $auto['autobackup_every'] === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-4"><label class="form-label" for="abKeep">Keep the newest</label>
      <div class="input-group"><input class="form-control" id="abKeep" name="keep" type="number" min="3" max="90" value="<?= (int) $auto['autobackup_keep'] ?>"><span class="input-group-text">backups</span></div></div>
    <div class="col-sm-3"><button class="btn btn-primary w-100">Save</button></div>
    <div class="col-12 form-text">Older automatic backups are deleted. Your own backups and the safety copies made before a restore are never deleted.</div>
  </form>
  <dl class="row small mb-2">
    <dt class="col-5">Last automatic backup</dt><dd class="col-7"><?= $lastOk ? h(date('D, M j, Y g:i A', $lastOk)) : '<span class="text-muted">none yet</span>' ?></dd>
    <dt class="col-5">Next one</dt><dd class="col-7"><?= !$hours ? '<span class="text-muted">off</span>' : ($lastOk && $lastOk + $hours * 3600 > time() ? h('after ' . date('D, M j g:i A', $lastOk + $hours * 3600)) : 'due now') ?></dd>
    <dt class="col-5">Runs by</dt><dd class="col-7"><?= $cron ? '<span class="text-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>scheduled job (last checked ' . h(date('M j, g:i A', strtotime($auto['autobackup_cron_seen']))) . ')</span>' : 'after someone opens a page (no scheduled job yet)' ?></dd>
    <?php if ($auto['autobackup_last_error'] !== ''): ?><dt class="col-5 text-danger">Last problem</dt><dd class="col-7 text-danger"><?= h($auto['autobackup_last_error']) ?> <small class="text-muted">(<?= h(date('M j, g:i A', strtotime($auto['autobackup_last_error_at']))) ?>)</small></dd><?php endif; ?>
  </dl>
  <form method="post" class="mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="auto_now"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>Make the automatic backup now</button></form>
  <?php if (!$cron): ?>
  <details class="small"><summary class="fw-semibold">Set up a scheduled job (recommended)</summary>
    <p class="mt-2 mb-1">Without one, the backup is made after someone opens a page once it is due, so it can be late on quiet days. A scheduled job makes it on time:</p>
    <ol class="mb-2 ps-3"><li>Hostinger: <b>hPanel → Advanced → Cron Jobs</b> (cPanel: <b>Cron Jobs</b>).</li><li>Choose <b>Custom</b>, paste this command, and run it <b>every hour</b> (it does nothing until a backup is due):</li></ol>
    <code class="d-block p-2 bg-light border rounded user-select-all text-break"><?= h($cmd) ?></code>
    <p class="mt-2 mb-0">This page shows "scheduled job" above once it has run.</p>
  </details>
  <?php endif; ?>
  <p class="small text-muted mt-2 mb-0">Backups stay on this server. Download one now and then and keep it somewhere else (a USB drive or Google Drive): if the server is lost, so are the backups on it.</p>
</section>
<section class="form-section"><h2 class="h6">Backups on this server</h2>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Made</th><th>Kind</th><th>Parts</th><th class="text-end">Size</th><th></th></tr></thead><tbody>
  <?php foreach ($stored as $b): ?><tr<?= ($_GET['ready'] ?? '') === $b['file'] ? ' class="table-success"' : '' ?>><td><?= h(date('D, M j, Y g:i A', strtotime($b['created']))) ?><?= $b['note'] !== '' ? '<small class="d-block text-muted">' . h($b['note']) . '</small>' : '' ?></td>
    <td><?= ['manual' => 'Backup', 'auto' => 'Automatic', 'safety' => 'Safety copy (before a restore)', 'uploaded' => 'Uploaded'][$b['kind']] ?? h($b['kind']) ?></td>
    <td class="small"><?= count($b['parts']) === count(Backup::PARTS) ? 'Everything' : h(implode(', ', array_map(fn($p) => Backup::PARTS[$p][0] ?? $p, $b['parts']))) ?></td><td class="text-end"><?= $mb((int) $b['size']) ?></td>
    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= url('backup', ['download' => $b['file']]) ?>" data-native>Download</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= url('backup', ['pick' => $b['file']]) ?>">Restore…</a>
      <form method="post" class="d-inline" data-confirm="Delete this backup from the server?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= h($b['file']) ?>"><button class="btn btn-sm btn-link text-danger">Delete</button></form></td></tr><?php endforeach; ?>
  <?php if (!$stored): ?><tr><td colspan="5" class="text-muted">No backups yet.</td></tr><?php endif; ?></tbody></table></div>
</section>
<?php layout_end();
