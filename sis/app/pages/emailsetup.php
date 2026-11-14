<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
/**
 * Email sending (v51): the server sends password emails with the school's EmailJS account.
 * The Public Key, Service ID and Template ID are the Grading System's (Settings → System Settings → Email sending);
 * only the Private Key is kept here, encrypted and never shown again.
 */
$me = (int) Auth::centralId();
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'key') {
        Mailer::savePrivateKey((string) ($_POST['private_key'] ?? ''));
        flash('success', trim((string) ($_POST['private_key'] ?? '')) === '' ? 'Private Key removed. Forgot password can\'t send email until a key is saved.' : 'Private Key saved (encrypted). Send a test email to check it.');
    } elseif ($a === 'test') {
        $to = strtolower(trim((string) ($_POST['to'] ?? '')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new AppError('Enter the email address to send the test to.');
        Mailer::send($to, (string) (Auth::central()->account($me)['display_name'] ?? ''), 'Test email from ' . School::brand(),
            '<p>This is a test from ' . h(School::brand()) . '. Email sending for Forgot password works.</p><p>Sent ' . h(date('F j, Y g:i A')) . '.</p>');
        Audit::log('email_test_sent', 'settings', 0, ['to' => AccountEmail::mask($to)]);
        flash('success', 'Test email sent to ' . $to . '. Check the inbox (and the spam folder).');
    } else throw new AppError('Unknown action.');
    redirect('emailsetup');
}
$k = Mailer::keys();
$missing = Mailer::missing();
$show = fn(string $v) => $v === '' ? '<span class="text-danger">not set</span>' : h(mb_substr($v, 0, 4) . str_repeat('•', max(0, min(12, mb_strlen($v) - 4))));
layout_start('Email sending');
page_title('Email sending', '', 'Forgot password sends a temporary password by email, through the school\'s EmailJS account.');
?>
<div class="row g-3" data-record-detail>
  <div class="col-lg-6">
    <section class="form-section">
      <h2 class="h6">Status</h2>
      <?php if ($missing): ?><div class="alert alert-warning small mb-3"><strong>Not ready.</strong> Missing: <?= h(implode('; ', $missing)) ?>.</div>
      <?php else: ?><div class="alert alert-success small mb-3"><strong>Ready.</strong> Send a test email to be sure.</div><?php endif; ?>
      <dl class="row small mb-0">
        <dt class="col-5">Public Key</dt><dd class="col-7"><?= $show($k['publicKey']) ?></dd>
        <dt class="col-5">Service ID</dt><dd class="col-7"><?= $show($k['serviceId']) ?></dd>
        <dt class="col-5">Template ID</dt><dd class="col-7"><?= $show($k['templateId']) ?></dd>
        <dt class="col-5">Private Key</dt><dd class="col-7"><?= Mailer::hasPrivateKey() ? '<span class="text-success">Saved ••••</span>' : '<span class="text-danger">not set</span>' ?></dd>
      </dl>
      <p class="small text-muted mt-2 mb-0">The first three are set in <a href="<?= h(url('systemsettings')) ?>">Settings → System Settings → Email sending</a>. The same template is used (to_email, to_name, from_name, subject, message_html).</p>
    </section>
    <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="test">
      <h2 class="h6">Send a test email</h2>
      <label class="form-label small mb-0" for="esTo">Send to</label>
      <input class="form-control mb-2" type="email" id="esTo" name="to" required value="<?= h(AccountEmail::get($me)) ?>" placeholder="name@gmail.com">
      <button class="btn btn-outline-primary"<?= $missing ? ' disabled' : '' ?>><i class="bi bi-send"></i> Send test email</button>
    </form>
  </div>
  <div class="col-lg-6">
    <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="key">
      <h2 class="h6">EmailJS Private Key</h2>
      <ol class="small ps-3">
        <li>Sign in to <strong>EmailJS</strong> → <strong>Account</strong> → <strong>Security</strong>.</li>
        <li>Turn on <strong>"Allow EmailJS API for non-browser applications"</strong>.</li>
        <li>Copy the <strong>Private Key</strong> (on the same Account page) and paste it here.</li>
      </ol>
      <label class="form-label small mb-0" for="esKey">Private Key</label>
      <input class="form-control mb-2" type="password" id="esKey" name="private_key" maxlength="120" autocomplete="off" placeholder="<?= Mailer::hasPrivateKey() ? 'Saved: paste a new one to replace it' : 'Paste the Private Key' ?>">
      <p class="small text-muted">Saved encrypted on the server and never shown again. Leave empty and save to remove it.</p>
      <button class="btn btn-primary">Save Private Key</button>
    </form>
  </div>
</div>
<?php layout_end();
