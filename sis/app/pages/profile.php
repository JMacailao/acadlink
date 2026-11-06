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
<?php layout_end();
