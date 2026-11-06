<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
/** Student Portal → My profile: the student's email (saved on their account) and personal details. */
$a = Auth::central()->current() ?: throw new AuthRequired();
$me = (int) $a['id'];
$student = !empty($a['person_verified']) && !empty($a['student_doc_id']) ? GS::get('students', (string) $a['student_doc_id']) : null;
if ($isPost) {
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'email') {
        if (!Auth::central()->verifyPassword($me, (string) ($_POST['password'] ?? ''))) throw new AppError('Your password is not correct.');
        AccountEmail::set($me, (string) ($_POST['email'] ?? ''), $me);
        flash('success', trim((string) ($_POST['email'] ?? '')) === '' ? 'Email removed from your account.' : 'Email saved on your account. Forgot password sends temporary passwords there.');
    } elseif ($act === 'details') {
        StudentDetails::saveOwn($_POST);
        flash('success', 'Your details are saved.');
    } else throw new AppError('Unknown action.');
    redirect('profile');
}
$det = $student ? StudentDetails::get((string) $student['id']) : null;
$email = AccountEmail::get($me);
$recordEmail = $student ? (string) ($student['email'] ?? '') : '';
layout_start('My profile');
page_title('My profile', '', 'Your email and personal details. Your name, Student ID and program are kept by the Registrar.');
?>
<div class="row g-3">
  <div class="col-lg-5">
    <section class="form-section">
      <h2 class="h6">My account</h2>
      <dl class="row small mb-0">
        <dt class="col-5">Name</dt><dd class="col-7"><?= h((string) ($student['studentName'] ?? $a['display_name'])) ?></dd>
        <dt class="col-5">Username</dt><dd class="col-7"><?= h((string) $a['username']) ?></dd>
        <?php if ($student): ?>
        <dt class="col-5">Student ID</dt><dd class="col-7"><?= h((string) ($student['studentId'] ?? '')) ?></dd>
        <dt class="col-5">Section</dt><dd class="col-7"><?= h(trim(($student['sectionName'] ?? '') . ' · ' . ($student['yearLevel'] ?? '') . ' · ' . ($student['schoolYear'] ?? ''), ' ·')) ?></dd>
        <?php endif; ?>
      </dl>
      <p class="small text-muted mt-2 mb-0">To correct these, go to the Registrar.</p>
    </section>
    <form method="post" class="form-section" id="myEmail"><?= csrf_field() ?><input type="hidden" name="action" value="email">
      <h2 class="h6">Email</h2>
      <p class="small text-muted">If you forget your password, a temporary password is sent here.<?= $email === '' && $recordEmail !== '' ? ' Until you add one, the email on your student record (' . h(AccountEmail::mask($recordEmail)) . ') is used.' : '' ?></p>
      <label class="form-label small mb-0" for="myEmailIn">Email</label>
      <input class="form-control mb-2" type="email" id="myEmailIn" name="email" maxlength="191" value="<?= h($email) ?>" placeholder="name@gmail.com" autocomplete="email">
      <label class="form-label small mb-0" for="myEmailPw">Your password *</label>
      <input class="form-control mb-3" type="password" id="myEmailPw" name="password" required autocomplete="current-password">
      <button class="btn btn-primary">Save email</button>
    </form>
  </div>
  <div class="col-lg-7">
    <?php if ($det !== null): ?>
    <form method="post" class="form-section" id="myDetails"><?= csrf_field() ?><input type="hidden" name="action" value="details">
      <h2 class="h6">Personal details</h2>
      <p class="small text-muted">These print on your Transcript of Records and certificates. Keep them correct.</p>
      <div class="row g-2">
        <div class="col-sm-6"><label class="form-label small mb-0" for="mySex">Sex</label><select class="form-select" id="mySex" name="sex"><option value="">—</option><?php foreach (StudentDetails::SEXES as $x): ?><option<?= $det['sex'] === $x ? ' selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6"><label class="form-label small mb-0" for="myBirth">Birth date</label><input class="form-control" type="date" id="myBirth" name="birth_date" value="<?= h($det['birth_date']) ?>"></div>
        <div class="col-12"><label class="form-label small mb-0" for="myPlace">Birth place</label><input class="form-control" id="myPlace" name="birth_place" maxlength="191" value="<?= h($det['birth_place']) ?>"></div>
        <div class="col-12"><label class="form-label small mb-0" for="myAddr">Home address</label><input class="form-control" id="myAddr" name="home_address" maxlength="255" value="<?= h($det['home_address']) ?>" autocomplete="street-address"></div>
        <div class="col-sm-6"><label class="form-label small mb-0" for="myMobile">Mobile number</label><input class="form-control" id="myMobile" name="mobile" maxlength="30" inputmode="tel" value="<?= h($det['mobile']) ?>" placeholder="0917 123 4567" autocomplete="tel"></div>
      </div>
      <button class="btn btn-primary mt-3">Save details</button>
    </form>
    <?php else: ?>
    <section class="form-section"><h2 class="h6">Personal details</h2><p class="mb-0 text-muted">These open once the Registrar has linked your student record.</p></section>
    <?php endif; ?>
  </div>
</div>
<?php layout_end();
