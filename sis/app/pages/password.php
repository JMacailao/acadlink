<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$u = Auth::user();
if ($isPost) {
    $new = (string) ($_POST['new'] ?? '');
    if ($new !== ($_POST['confirm'] ?? '')) throw new AppError('The new passwords do not match.');
    try { Auth::central()->changePassword((int)$u['central_id'],(string)($_POST['current']??''),$new); }
    catch(CentralAccountError $e) { throw new AppError($e->getMessage()); }
    flash('success', 'Password changed.');
    redirect('dashboard');
}
layout_start('Change password', (bool) $u['must_change_password']);
?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5">
<?php page_title('Change password'); ?>
<?php if ($u['must_change_password']): ?><div class="alert alert-info">Choose your own password before continuing.</div><?php endif; ?>
<form method="post" class="form-section"><?= csrf_field() ?>
  <div class="mb-3"><label class="form-label">Current password</label><input class="form-control" type="password" name="current" required></div>
  <div class="mb-3"><label class="form-label">New password</label><input class="form-control" type="password" name="new" required minlength="8"><div class="form-text">At least 8 characters with letters and numbers.</div></div>
  <div class="mb-3"><label class="form-label">Repeat new password</label><input class="form-control" type="password" name="confirm" required minlength="8"></div>
  <button class="btn btn-primary">Change password</button>
</form></div></div>
<?php layout_end();
