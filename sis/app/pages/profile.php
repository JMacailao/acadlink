<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
/**
 * My profile: the signed-in person's name and e-signature (teachers open it from the Grading System too).
 * A Power User opens ?account=<id> to manage someone else's e-signature.
 */
$me = (int) (Auth::central()->current()['id'] ?? 0);
$target = (int) ($_GET['account'] ?? $me);
$managing = $target !== $me;
if ($managing && !ESign::isPowerUser($me)) throw new Forbidden('Only a Power User can manage another person\'s e-signature.');
$acct = Auth::central()->account($target) ?: throw new AppError('Account not found.');
$back = $managing ? ['account' => $target] : [];

if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'name' && !$managing) {
        if ($acct['account_kind'] !== 'STAFF') throw new Forbidden('Students correct their name through the Registrar.');
        $name = ESign::saveOwnName($_POST, (string) ($_POST['password'] ?? ''));
        flash('success', 'Your name is now "' . $name . '" everywhere (Grading System and SIS).');
    } elseif ($a === 'email' && !$managing) {
        if (!Auth::central()->verifyPassword($me, (string) ($_POST['password'] ?? ''))) throw new AppError('Your password is not correct.');
        AccountEmail::set($me, (string) ($_POST['email'] ?? ''), $me);
        flash('success', trim((string) ($_POST['email'] ?? '')) === '' ? 'Email removed.' : 'Email saved. Forgot password sends temporary passwords there.');
    } elseif ($a === 'esign') {
        $bytes = '';
        if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) $bytes = (string) file_get_contents($_FILES['image']['tmp_name']);
        elseif (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', (string) ($_POST['drawn'] ?? ''), $m)) $bytes = (string) base64_decode($m[1], true);
        if ($bytes === '') throw new AppError('Draw your signature in the box, or choose a photo of it.');
        ESign::set($target, $bytes, (string) ($_POST['password'] ?? ''));
        flash('success', $managing ? 'E-signature saved for ' . $acct['display_name'] . '. They were notified.' : 'Your e-signature is saved.');
    } elseif ($a === 'remove') {
        ESign::remove($target, (string) ($_POST['password'] ?? ''));
        flash('success', 'E-signature removed.');
    } elseif (str_starts_with($a, 'two_step_')) { // v63.17: two-step sign-in
        $ts = Auth::central()->twoStep(); $pw = (string) ($_POST['password'] ?? ''); $code = (string) ($_POST['code'] ?? '');
        $ownPw = fn() => Auth::central()->verifyPassword($me, $pw) ?: throw new AppError('Your password is not correct.');
        try {
            if ($managing) {
                if ($a !== 'two_step_off') throw new AppError('Only the person can set up their own two-step sign-in.');
                if ($target === Backup::ownerId()) throw new AppError('power.admin turns this off on their own profile (or with sis/bin/reset-two-step.php on the server).');
                $ownPw(); $ts->disable($target);
                Audit::log('two_step_reset_by_admin', 'account', $target, ['by' => $me]);
                flash('success', 'Two-step sign-in turned off for ' . $acct['display_name'] . '. They sign in with their password and can turn it on again in My profile.');
            } elseif ($a === 'two_step_begin') { $ownPw(); $ts->begin($me, (string) $acct['username'], 'AcadLink'); }
            elseif ($a === 'two_step_cancel') { if (!$ts->enabled($me)) $ts->disable($me); }
            elseif ($a === 'two_step_confirm') {
                $_SESSION['two_step_codes'] = $ts->confirm($me, $code);
                Audit::log('two_step_on', 'account', $me, []);
                flash('success', 'Two-step sign-in is on. Save your recovery codes now: they are shown only once.');
            } elseif ($a === 'two_step_recovery') {
                $ownPw(); if (!$ts->verify($me, $code)) throw new AppError('That code is not right.');
                $_SESSION['two_step_codes'] = $ts->newRecovery($me);
                Audit::log('two_step_new_recovery_codes', 'account', $me, []);
                flash('success', 'New recovery codes made; the old ones no longer work. Save them now.');
            } elseif ($a === 'two_step_off') {
                $ownPw(); if (!$ts->verify($me, $code)) throw new AppError('That code is not right.');
                $ts->disable($me); Audit::log('two_step_off', 'account', $me, []);
                flash('success', 'Two-step sign-in is off. Your password alone signs you in now.');
            } else throw new AppError('Unknown action.');
        } catch (TwoStepError $e) { throw new AppError($e->getMessage()); }
        redirect('profile', $back + ['tab' => 'twostep']);
    } else throw new AppError('Unknown action.');
    redirect('profile', $back);
}

$parts = ESign::nameParts($target);
$isPower = ESign::isPowerUser($target);
$canSign = ESign::canHave($target);
$sig = ESign::dataUri($target);
layout_start($managing ? 'E-signature · ' . $acct['display_name'] : 'My profile');
page_title($managing ? 'E-signature of ' . $acct['display_name'] : 'My profile', $managing ? '<a class="btn btn-outline-secondary" href="' . url('users', ['edit' => $target]) . '"><i class="bi bi-arrow-left"></i> Back to the account</a>' : '',
    $managing ? 'As Power User you can upload, change or remove this person\'s e-signature. Only they can sign with it.' : 'Your name on class lists, grade sheets and documents, and your e-signature.');
?>
<div class="row g-3" data-record-detail>
  <?php if (!$managing && $acct['account_kind'] === 'STUDENT'): ?>
  <div class="col-lg-5"><div class="form-section"><h2 class="h6">Name</h2><p class="mb-0">Your name is <strong><?= h($acct['display_name']) ?></strong>. To correct it, go to the Registrar.</p></div></div>
  <?php elseif (!$managing): ?>
  <div class="col-lg-5">
    <form method="post" class="form-section" id="profileName"><?= csrf_field() ?><input type="hidden" name="action" value="name">
      <h2 class="h6">Name</h2>
      <p class="small text-muted">Shown as <strong><?= h($acct['display_name']) ?></strong>. A change applies right away in the Grading System and the SIS. Documents already issued keep the name they were signed with.</p>
      <div class="row g-2">
        <div class="col-4"><label class="form-label small mb-0" for="pTitle">Title</label><input class="form-control" id="pTitle" name="title" maxlength="60" list="pTitles" placeholder="e.g. Prof." value="<?= h($parts['title']) ?>"><datalist id="pTitles"><option>Prof.</option><option>Dr.</option><option>Engr.</option><option>Atty.</option><option>Mr.</option><option>Ms.</option><option>Mrs.</option></datalist></div>
        <div class="col-8"><label class="form-label small mb-0" for="pFirst">First name *</label><input class="form-control" id="pFirst" name="first_name" maxlength="60" required value="<?= h($parts['first_name']) ?>"></div>
        <div class="col-6"><label class="form-label small mb-0" for="pMiddle">Middle name</label><input class="form-control" id="pMiddle" name="middle_name" maxlength="60" value="<?= h($parts['middle_name']) ?>"></div>
        <div class="col-6"><label class="form-label small mb-0" for="pLast">Last name *</label><input class="form-control" id="pLast" name="last_name" maxlength="60" required value="<?= h($parts['last_name']) ?>"></div>
        <div class="col-6"><label class="form-label small mb-0" for="pSuffix">Suffix</label><input class="form-control" id="pSuffix" name="suffix" maxlength="60" placeholder="e.g. Jr., III" value="<?= h($parts['suffix']) ?>"></div>
        <div class="col-6"><label class="form-label small mb-0" for="pPw">Your password *</label><input class="form-control" type="password" id="pPw" name="password" required autocomplete="current-password"></div>
      </div>
      <button class="btn btn-primary mt-3">Save name</button>
    </form>
    <form method="post" class="form-section<?= ($_GET['need'] ?? '') === 'email' ? ' border-warning' : '' ?>" id="profileEmail"><?= csrf_field() ?><input type="hidden" name="action" value="email">
      <h2 class="h6">Email</h2>
      <p class="small text-muted">Used for Forgot password: a temporary password is sent here.<?= AccountEmail::isPowerUser($me) ? ' <strong>Required for Power Users.</strong>' : ' Optional.' ?></p>
      <label class="form-label small mb-0" for="pEmail">Email</label>
      <input class="form-control mb-2" type="email" id="pEmail" name="email" maxlength="191" value="<?= h(AccountEmail::get($me)) ?>" placeholder="name@gmail.com"<?= AccountEmail::isPowerUser($me) ? ' required' : '' ?>>
      <label class="form-label small mb-0" for="pEmailPw">Your password *</label>
      <input class="form-control mb-3" type="password" id="pEmailPw" name="password" required autocomplete="current-password">
      <button class="btn btn-primary">Save email</button>
    </form>
  </div>
  <?php endif; ?>
  <?php if (!$managing): ?>
  <div class="col-12 order-last"><div class="form-section"><h2 class="h6">Sound</h2>
    <label class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" data-sound-toggle checked> <span class="form-check-label">Play a short sound when a Save or Send succeeds</span></label>
    <p class="small text-muted mb-0 mt-1">Saved in this browser only. The green success message is always shown.</p></div></div>
  <?php endif; ?>
  <?php if ($acct['account_kind'] !== 'STUDENT'): // v63.17: two-step sign-in
    $ts = Auth::central()->twoStep(); $tsOn = $ts->enabled($target); $tsPending = !$managing && !$tsOn ? $ts->pending($target, (string) $acct['username'], 'AcadLink') : null;
    $tsCodes = !$managing ? ($_SESSION['two_step_codes'] ?? null) : null; unset($_SESSION['two_step_codes']); ?>
  <div class="col-lg-<?= $managing ? '8' : '7' ?>" id="twostep">
    <div class="form-section">
      <h2 class="h6"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Two-step sign-in <?= $tsOn ? '<span class="badge text-bg-success ms-1">On</span>' : '<span class="badge text-bg-secondary ms-1">Off</span>' ?></h2>
      <?php if ($managing): ?>
        <?php if ($tsOn && $target !== Backup::ownerId()): ?>
        <p class="small text-muted">Lost their phone and their recovery codes? Turn it off; they can turn it on again in their own profile.</p>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end" data-confirm="Turn off two-step sign-in for <?= h($acct['display_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="two_step_off">
          <div><label class="form-label small mb-0" for="tsAdminPw">Your password *</label><input class="form-control" type="password" id="tsAdminPw" name="password" required autocomplete="current-password"></div>
          <button class="btn btn-outline-danger">Turn off for this person</button></form>
        <?php else: ?><p class="small text-muted mb-0"><?= $tsOn ? 'On.' : 'Off: only they can turn it on, in their own profile.' ?></p><?php endif; ?>
      <?php elseif ($tsCodes): ?>
        <div class="alert alert-warning"><b>Your recovery codes</b> (each works once, if you lose your phone). Write them down or print this page now: they are not shown again.</div>
        <div class="ts-codes mb-3"><?php foreach ($tsCodes as $c): ?><code><?= h($c) ?></code><?php endforeach; ?></div>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-page-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
      <?php elseif ($tsOn): ?>
        <p class="small text-muted">After your password, AcadLink asks for the 6-digit code from your authenticator app. <?= (int) $ts->recoveryLeft($target) ?> recovery codes left.</p>
        <form method="post" class="row g-2 align-items-end mb-2"><?= csrf_field() ?>
          <div class="col-sm-4"><label class="form-label small mb-0" for="tsPw">Your password *</label><input class="form-control" type="password" id="tsPw" name="password" required autocomplete="current-password"></div>
          <div class="col-sm-3"><label class="form-label small mb-0" for="tsCode">Code now *</label><input class="form-control" id="tsCode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9" required></div>
          <div class="col-sm-5 d-flex gap-2"><button class="btn btn-outline-primary" name="action" value="two_step_recovery">New recovery codes</button><button class="btn btn-outline-danger" name="action" value="two_step_off">Turn off</button></div>
        </form>
      <?php elseif ($tsPending): ?>
        <ol class="small ps-3 mb-2"><li>On your phone, open an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…) and add an account by <b>scanning this QR code</b>.</li><li>Type the 6-digit code it shows.</li></ol>
        <div class="d-flex flex-wrap gap-3 align-items-start mb-2">
          <div class="ts-qr border rounded bg-white p-2" data-ts-qr="<?= h($tsPending[1]) ?>" aria-label="QR code for your authenticator app"></div>
          <div class="small"><div class="text-muted">Can't scan? Type this key instead:</div><code class="ts-key user-select-all"><?= h(trim(chunk_split($tsPending[0], 4, ' '))) ?></code><div class="text-muted mt-1">Account: <?= h($acct['username']) ?> · time-based</div></div>
        </div>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end"><?= csrf_field() ?>
          <div><label class="form-label small mb-0" for="tsFirst">Code from the app *</label><input class="form-control" id="tsFirst" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></div>
          <button class="btn btn-primary" name="action" value="two_step_confirm">Turn on</button>
          <button class="btn btn-link" name="action" value="two_step_cancel" formnovalidate>Cancel</button>
        </form>
      <?php else: ?>
        <p class="small text-muted">Even if someone learns your password, they can't sign in without the code from your phone.<?= $target === Backup::ownerId() ? ' <b>Strongly recommended for power.admin.</b>' : '' ?></p>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="two_step_begin">
          <div><label class="form-label small mb-0" for="tsStartPw">Your password *</label><input class="form-control" type="password" id="tsStartPw" name="password" required autocomplete="current-password"></div>
          <button class="btn btn-primary">Turn on two-step sign-in</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="<?= $managing ? 'col-lg-8' : 'col-lg-7' ?>">
    <div class="form-section">
      <h2 class="h6">E-signature</h2>
      <?php if ($isPower): ?>
        <p class="mb-0">Power Users have no e-signature. What you approve or issue shows an <span class="badge text-bg-success">APPROVED</span> stamp with your name, position, date and time. You can manage other people's e-signatures in Users and Groups.</p>
      <?php elseif (!$canSign): ?>
        <p class="mb-0 text-muted">Student accounts don't have an e-signature.</p>
      <?php else: ?>
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap"><div class="border rounded p-2 bg-white" style="min-width:220px;min-height:76px;display:flex;align-items:center;justify-content:center">
          <?= $sig ? '<img src="' . h($sig) . '" alt="Current e-signature" style="max-height:70px;max-width:300px">' : '<span class="small text-muted">No e-signature yet</span>' ?></div>
          <p class="small text-muted mb-0" style="max-width:360px">Only <?= $managing ? h($acct['display_name']) : 'you' ?> can sign with it, when <?= $managing ? 'they' : 'you' ?> issue or sign a document. Saving asks for <?= $managing ? 'your' : 'your' ?> password. Every change is logged<?= $managing ? ' and the owner is notified' : '' ?>.</p></div>
        <form method="post" enctype="multipart/form-data" id="esignForm" data-esign><?= csrf_field() ?><input type="hidden" name="action" value="esign"><input type="hidden" name="drawn">
          <div class="mb-2"><span class="form-label small d-block">Draw the signature (mouse or finger)</span>
            <canvas class="border rounded bg-white w-100" style="max-width:520px;height:170px;touch-action:none;cursor:crosshair" width="520" height="170" aria-label="Signature drawing box"></canvas>
            <div><button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-esign-clear>Clear</button></div></div>
          <label class="form-label small" for="esFile">…or upload a photo of it (PNG or JPG; the white paper becomes transparent)</label>
          <input class="form-control mb-2" type="file" id="esFile" name="image" accept="image/png,image/jpeg" style="max-width:520px">
          <label class="form-label small" for="esPw"><?= $managing ? 'Your' : 'Your' ?> password *</label>
          <input class="form-control mb-3" type="password" id="esPw" name="password" required autocomplete="current-password" style="max-width:280px">
          <p class="small text-danger mb-2" data-esign-error role="alert" hidden></p>
          <button class="btn btn-primary"><i class="bi bi-pen"></i> Save e-signature</button>
        </form>
        <?php if ($sig): ?><form method="post" class="mt-3 d-flex gap-2 align-items-end flex-wrap" data-confirm="Remove this e-signature?"><?= csrf_field() ?><input type="hidden" name="action" value="remove">
          <div><label class="form-label small mb-0" for="esPw2">Password</label><input class="form-control form-control-sm" type="password" id="esPw2" name="password" required autocomplete="current-password"></div>
          <button class="btn btn-sm btn-outline-danger">Remove e-signature</button></form><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="assets/esign.js?v=50"></script>
<?php if (!empty($tsPending) || !empty($tsCodes)): ?><?php if (!empty($tsPending)): ?><script src="../vendor/qrcode-generator-2.0.4/qrcode.js"></script><?php endif; ?><script src="<?= sis_web() ?>assets/two-step.js?v=1" defer></script><?php endif; ?>
<style>.ts-qr svg { display: block; width: 180px; height: 180px; } .ts-key { font-size: 1rem; letter-spacing: .05em; } .ts-codes { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 6px; } .ts-codes code { font-size: 1rem; padding: 6px 8px; border: 1px dashed #c9d3ce; border-radius: 6px; text-align: center; color: #123d2b; }</style>
<?php layout_end();
