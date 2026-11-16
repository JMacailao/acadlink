<?php
/** Activity log: who did what in the SIS (newest first). */
declare(strict_types=1);
$page = max(1, (int) ($_GET['page'] ?? 1));
$withSignIns = !empty($_GET['signins']);
$per = 50;
try { Audit::seal(); } catch (Throwable) {} // v66.4: seal the entries written a few minutes ago
$check = isset($_GET['check']) ? Audit::check() : null;
$activity = Activity::recent($per + 1, $withSignIns, ($page - 1) * $per);
$more = count($activity) > $per;
$activity = array_slice($activity, 0, $per);
layout_start('Activity log');
page_title('Activity log', '<a class="btn btn-outline-secondary" href="' . url('dashboard') . '"><i class="bi bi-house-door me-1"></i>Home</a>', 'Every admission, registration, payment and setup change, newest first.');
?>
<div class="home-panel log-seal d-print-none">
  <div class="d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-shield-lock" aria-hidden="true"></i><strong>Tamper check</strong>
    <span class="small text-muted me-auto">Each entry is sealed to the one before it. If someone changes or removes entries directly in the database, the check shows where.</span>
    <form method="get" class="m-0"><input type="hidden" name="r" value="activity"><input type="hidden" name="check" value="1"><?= $page > 1 ? '<input type="hidden" name="page" value="' . $page . '">' : '' ?><?= $withSignIns ? '<input type="hidden" name="signins" value="1">' : '' ?>
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search me-1" aria-hidden="true"></i>Check the log</button></form><?php /* v66.5: a form, so the page's Actions panel leaves the button here */ ?>
  </div>
  <?php if ($check): ?>
  <div class="alert <?= $check['ok'] ? 'alert-success' : 'alert-danger' ?> mt-2 mb-0 py-2" role="status">
    <?php if ($check['ok']): ?><i class="bi bi-check-circle me-1" aria-hidden="true"></i><strong>No changes found.</strong> <?= number_format($check['checked']) ?> sealed entr<?= $check['checked'] === 1 ? 'y' : 'ies' ?> match their fingerprints<?= $check['unsealed'] ? ' (' . $check['unsealed'] . ' newer one' . ($check['unsealed'] === 1 ? ' is' : 's are') . ' sealed a few minutes after they are written)' : '' ?>.
      <div class="small mt-1">Fingerprint of the log now: <code><?= h(substr($check['fingerprint'], 0, 16)) ?></code> — write it down with the date; if the log is ever rebuilt by someone, this fingerprint will not appear again.</div>
    <?php else: ?><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i><strong>The log was changed.</strong> <?= h((string) $check['problem']) ?><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<div class="home-panel">
  <form method="get" class="d-flex align-items-center gap-2 mb-3"><input type="hidden" name="r" value="activity">
    <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="signins" value="1"<?= $withSignIns ? ' checked' : '' ?> data-autosubmit> Include sign-ins and password changes</label></form>
  <?php require __DIR__ . '/../views/activity_table.php'; ?>
  <div class="d-flex justify-content-between mt-3">
    <?= $page > 1 ? '<a class="btn btn-sm btn-outline-secondary" href="' . url('activity', ['page' => $page - 1] + ($withSignIns ? ['signins' => 1] : [])) . '"><i class="bi bi-chevron-left"></i> Newer</a>' : '<span></span>' ?>
    <?= $more ? '<a class="btn btn-sm btn-outline-secondary" href="' . url('activity', ['page' => $page + 1] + ($withSignIns ? ['signins' => 1] : [])) . '">Older <i class="bi bi-chevron-right"></i></a>' : '' ?>
  </div>
</div>
<?php layout_end();
