<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
if (Auth::user()) redirect('workspaces');
$forgot = ($GLOBALS['route'] ?? '') === 'forgot'; // the same page, asking for a temporary password (v51)
if ($isPost && $forgot) {
    $res = PasswordReset::request((string) In::str($_POST, 'username', 64, true, 'Username or Student ID'));
    flash($res['ok'] ? 'success' : 'warning', $res['message']);
    redirect('forgot', ($_GET['portal'] ?? '') === 'student' ? ['portal' => 'student'] : []);
}
if ($isPost) {
    $u = Auth::login((string) In::str($_POST, 'username', 64, true, 'Username'), (string) ($_POST['password'] ?? ''));
    $next = (string) ($_POST['next'] ?? '');
    if (!$u['must_change_password'] && preg_match('/^[a-z-]+\.html$/', $next) && $next !== 'login.html' && $next !== 'dashboard.html' && Auth::central()->can((int) Auth::centralId(), 'grading.workspace.view')) { header('Location: ../pages/' . $next); exit; }
    $back = $_SESSION['return_to'] ?? null;
    unset($_SESSION['return_to']);
    // v58: back to the Finance page that asked for the sign-in (finance/index.php checks the route and access again)
    if (!$u['must_change_password'] && is_array($back) && in_array($back['app'] ?? '', ['FINANCE', 'CANTEEN', 'LIBRARY', 'LMS'], true) && preg_match('/^[a-z]+$/', (string) ($back['r'] ?? ''))) { header('Location: ../' . strtolower((string) $back['app']) . '/index.php?' . http_build_query(['r' => $back['r']] + array_filter((array) ($back['params'] ?? []), 'is_scalar'))); exit; }
    if (!$u['must_change_password'] && is_array($back) && (isset(ROUTES[$back['r'] ?? '']) || in_array($back['r'] ?? '', ['my', 'myenrollment', 'myadmission'], true)) && !in_array($back['r'], ['login', 'logout'], true)) redirect($back['r'], (array) ($back['params'] ?? []));
    redirect($u['must_change_password'] ? 'password' : 'workspaces');
}
$student = ($_GET['portal'] ?? '') === 'student';
$logo = School::logo();
$hasPic = School::signinPicture() !== null;
$features = $student
    ? [['mortarboard', 'View Final Grades'], ['receipt', 'Check Fees and Balance'], ['printer', 'Print Receipts']]
    : [['mortarboard', 'Grades'], ['journal-text', 'Enrollment'], ['credit-card-2-front', 'Payments']];
layout_start('Sign in', true);
?>
<div class="sl">
  <!-- v63.1 Phones: brand, help and "Your school. Connected." (hidden on larger screens) -->
  <header class="sl-m-head" aria-label="<?= h(School::brand()) ?>">
    <div class="sl-m-bar"><span class="sl-m-brand"><span class="sl-m-logo"><img src="<?= h($logo ?: '../icons/acadlink-mark.png') ?>" alt=""></span><span><?= h(School::brand()) ?></span></span>
      <button type="button" class="sl-m-help" data-bs-toggle="collapse" data-bs-target="#slHelp" aria-expanded="false" aria-controls="slHelp" aria-label="Help signing in"><i class="bi bi-question-lg" aria-hidden="true"></i></button></div>
    <div class="sl-m-hero"><p class="sl-m-big"><span class="sl-m-nw">Your school.</span><br><span class="sl-m-on">Connected.</span></p>
      <svg class="sl-m-art" viewBox="-14 0 144 90" aria-hidden="true" focusable="false"><g fill="none" stroke-width="3.2"><rect x="44" y="6" width="70" height="40" rx="11" transform="skewX(-18)" stroke="#5fae86" opacity=".75"/><rect x="18" y="36" width="70" height="40" rx="11" transform="skewX(-18)" stroke="#8fe3b0"/></g><circle cx="88" cy="7" r="4.5" fill="#a78bfa"/><circle cx="121" cy="42" r="4.5" fill="#f97316"/><circle cx="25" cy="76" r="4.5" fill="#86efac"/></svg>
      <p class="sl-m-sub"><?= $student ? 'Grades, fees and receipts in one place.' : 'One account. Every workspace.' ?></p></div>
  </header>
  <!-- Left: school, what the system is for, and the school's picture -->
  <aside class="sl-side<?= $hasPic ? ' has-pic' : '' ?>">
    <?php if ($hasPic): ?><img class="sl-pic" src="<?= url('signinpic') ?>" alt="" data-signin-pic><?php endif; ?>
    <div class="sl-side-in">
      <div class="sl-top">
        <div class="sl-brand">
          <span class="brand-seal has-logo<?= $logo ? '' : ' is-default' ?>" aria-hidden="true"><img src="<?= h($logo ?: '../icons/acadlink-mark.png') ?>" alt=""></span>
          <span class="sl-brand-text"><span class="sl-brand-name"><?= h(School::brand()) ?></span><span class="sl-brand-sub"><?= $student ? 'Student Portal' : 'AcadLink' ?></span></span>
        </div>
        <div class="sl-motto" aria-hidden="true"><span>Learn</span><i>·</i><span>Grow</span><i>·</i><span class="on">Succeed</span></div>
      </div>
      <h2 class="sl-heading"><?php if ($student): ?><b>Your grades,</b> fees and <em>receipts</em><span>.</span><?php else: ?><b>Grades,</b> enrollment and <em>payments</em><span>.</span><?php endif; ?></h2>
      <p class="sl-lede"><?= $student ? 'Sign in with your Student ID to see your final grades, assessment, balance and receipts.' : 'One account for the Grading System, the School Information System and the Student Portal. After signing in you see the systems your account can open.' ?></p>
      <ul class="sl-features">
        <?php foreach ($features as [$icon, $label]): ?><li><span class="sl-feat-ic"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></span><span><?= h($label) ?></span></li><?php endforeach; ?>
      </ul>
      <div class="sl-trust"><i class="bi bi-shield-fill-check" aria-hidden="true"></i><span>Secure</span><i class="dot">·</i><span>Reliable</span><i class="dot">·</i><span>Trusted</span></div>
    </div>
    <svg class="sl-curve" viewBox="0 0 160 1000" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="M160 0H118C40 160 40 300 72 430C104 560 96 760 0 1000H160Z"/></svg>
  </aside>

  <!-- Right: the sign-in card -->
  <main class="sl-main">
    <div class="sl-main-top">
      <div class="sl-clock" aria-hidden="true"><i class="bi bi-calendar3"></i><span><b data-clock-date><?= h(date('D, M j, Y')) ?></b><small data-clock-time><?= h(date('g:i A')) ?></small></span></div>
    </div>
    <div class="sl-card">
      <div class="sl-corner" aria-hidden="true"><i class="bi bi-<?= $student ? 'mortarboard' : 'book' ?>"></i></div>
      <div class="sl-wave" aria-hidden="true">👋</div>
      <p class="sl-hello"><span data-welcome-line>Welcome,</span></p>
      <p class="sl-m-title" aria-hidden="true"><?= $forgot ? 'Forgot password' : 'Welcome back' ?></p>
      <p class="sl-m-lead"><?= $forgot ? 'We email you a temporary password.' : 'Sign in to your ' . h($student ? 'Student Portal' : School::brand()) . ' account.' ?></p>
      <?php if ($forgot): ?>
      <h1 class="sl-name">Forgot password</h1>
      <p class="sl-intro">Enter your username or Student ID. If your account has an email, we send you a temporary password that works once for <?= PasswordReset::MINUTES ?> minutes.</p>
      <?php foreach (take_flashes() as [$type, $msg]): ?><div class="alert alert-<?= h($type) ?> py-2" role="alert"><?= h($msg) ?></div><?php endforeach; ?>
      <form method="post" class="sl-form" autocomplete="on">
        <?= csrf_field() ?>
        <label class="sl-field" for="fpUser">
          <i class="bi bi-person-fill sl-field-ic" aria-hidden="true"></i>
          <span class="sl-field-body"><span class="sl-field-label">Username or Student ID</span>
            <input id="fpUser" name="username" required autofocus maxlength="64" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="e.g. juan.cruz or 20250001"></span>
        </label>
        <button class="sl-submit"><i class="bi bi-envelope-arrow-up" aria-hidden="true"></i> Send temporary password</button>
      </form>
      <p class="mt-3 mb-3 text-center"><a class="sl-forgot" href="<?= url('login', $student ? ['portal' => 'student'] : []) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to sign in</a></p>
      <?php else: ?>
      <h1 class="sl-name" data-welcome-name><?= $student ? 'Student' : 'Sign in' ?></h1>
      <p class="sl-intro">Sign in once. You will then see the systems your account can open.</p>
      <?php $why = ['disabled' => 'This account is disabled. Ask an administrator to turn it back on.', 'noprofile' => 'This account has been removed. Ask an administrator for access.', 'elsewhere' => 'You were signed out because your account was opened on another device.', 'idle' => 'You were signed out because there was no activity for a while.'][(string) ($_GET['reason'] ?? '')] ?? null;
      if ($why): ?><div class="alert alert-warning py-2"><?= h($why) ?></div><?php endif; ?>
      <?php foreach (take_flashes() as [$type, $msg]): ?><div class="alert alert-<?= h($type) ?> py-2"><?= h($msg) ?></div><?php endforeach; ?>
      <form method="post" autocomplete="on" class="sl-form" data-signin="<?= $student ? 'student' : 'staff' ?>">
        <?= csrf_field() ?><input type="hidden" name="next" value="<?= h(preg_match('/^[a-z-]+\.html$/', (string) ($_GET['next'] ?? '')) ? (string) $_GET['next'] : '') ?>">
        <label class="sl-field" for="username">
          <i class="bi bi-person-fill sl-field-ic" aria-hidden="true"></i>
          <span class="sl-field-body"><span class="sl-field-label"><?= $student ? 'Student ID' : 'Username' ?></span>
            <input id="username" name="username" data-signin-user required autofocus maxlength="64" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="<?= $student ? 'e.g. 20250001' : 'Your username' ?>"></span>
          <i class="bi bi-check-circle-fill sl-ok" aria-hidden="true"></i>
        </label>
        <label class="sl-field" for="password">
          <i class="bi bi-lock-fill sl-field-ic" aria-hidden="true"></i>
          <span class="sl-field-body"><span class="sl-field-label">Password</span>
            <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Your password"></span>
          <button type="button" class="sl-eye" data-pw-toggle="#password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye-slash" aria-hidden="true"></i></button>
        </label>
        <div class="sl-row">
          <label class="form-check m-0"><input class="form-check-input" type="checkbox" id="remember" data-remember> <span class="form-check-label">Remember me</span></label>
          <a class="sl-forgot" href="<?= url('forgot', $student ? ['portal' => 'student'] : []) ?>">Forgot password?</a>
        </div>
        <button class="sl-submit"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> <span>Sign In</span><i class="bi bi-arrow-right sl-m-arrow" aria-hidden="true"></i></button>
      </form>
      <?php endif; ?>
      <p class="sl-m-need">Need access? <a href="#slHelp" data-bs-toggle="collapse" role="button" aria-controls="slHelp">Contact your school</a></p>
      <div class="collapse sl-m-helpbox" id="slHelp"><p class="mb-1"><b>Signing in</b></p><ul class="mb-0">
        <li><?= $student ? 'Use your Student ID as the username.' : 'Use the username your school gave you. Students use their Student ID.' ?></li>
        <li>Forgot your password? Tap <b>Forgot password?</b>; a temporary password goes to the email on your account.</li>
        <li>No account yet, or still stuck? Ask the Registrar's office of <?= h(School::brand()) ?>.</li></ul></div>
      <p class="sl-m-foot"><i class="bi bi-lock" aria-hidden="true"></i> Your school account, securely connected.</p>
      <p class="sl-auth"><i class="bi bi-shield-check" aria-hidden="true"></i> <?= $student ? 'Only students of ' . h(School::brand()) . ' can sign in here.' : 'Only authorized personnel are allowed to access this system.' ?></p>
    </div>
  </main>
</div>
<script src="../js/signin-ui.js"></script>
<?php layout_end(true);
