<?php
/** v63.3 First-time setup: the installer sets power.admin's first password (no published starting password). */
declare(strict_types=1);
/** @var bool $isPost */
if (!FirstSetup::needed()) redirect('login');
$problem = '';
if ($isPost) {
    try {
        FirstSetup::complete((string) ($_POST['proof'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
        flash('success', 'power.admin is ready. Sign in with the username power.admin and the password you just chose.');
        redirect('login');
    } catch (AppError $e) { $problem = $e->getMessage(); }
}
$mode = FirstSetup::mode();
layout_start('First-time setup', true);
?>
<main class="container py-5" style="max-width:560px">
  <h1 class="h3 mb-1">First-time setup</h1>
  <p class="text-muted">Choose the password of <b>power.admin</b>, the main administrator of <?= h(School::brand()) ?>. Only power.admin can make backups and give other people access.</p>
  <?php if ($problem !== ''): ?><div class="alert alert-danger" role="alert"><?= h($problem) ?></div><?php endif; ?>
  <form method="post" class="form-section" autocomplete="off"><?= csrf_field() ?>
    <?php if ($mode === 'dbpass'): ?>
    <label class="form-label" for="suProof">Database password <span class="text-muted fw-normal">(the <code>db_pass</code> in <code>api/config.php</code>)</span></label>
    <input class="form-control mb-1" type="password" id="suProof" name="proof" required autocomplete="off">
    <p class="small text-muted">This shows that you installed the site: visitors can't read api/config.php.</p>
    <?php elseif (!FirstSetup::fromThisComputer()): ?>
    <div class="alert alert-warning">This database has no password, so setup works only on the computer where AcadLink is installed. Open <b>http://localhost/…</b> there, or give the database a password in api/config.php.</div>
    <?php endif; ?>
    <label class="form-label" for="suPw">New password for power.admin</label>
    <input class="form-control mb-2" type="password" id="suPw" name="password" required minlength="8" autocomplete="new-password">
    <label class="form-label" for="suPw2">Repeat the password</label>
    <input class="form-control mb-1" type="password" id="suPw2" name="confirm" required minlength="8" autocomplete="new-password">
    <p class="small text-muted">At least 8 characters with letters and numbers. Keep it safe; it can be changed later in My profile.</p>
    <button class="btn btn-primary">Save and go to sign in</button>
  </form>
</main>
<?php layout_end(true);
