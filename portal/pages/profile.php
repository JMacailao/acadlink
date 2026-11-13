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
    } elseif ($act === 'photo') { // v62: a new photo waits for the registrar's approval
        StudentPhoto::submitOwn($_FILES['photo'] ?? []);
        flash('success', 'Photo sent. It appears on your record once the Registrar approves it.');
    } elseif ($act === 'mydata') { // v62: Data Privacy Act — a copy of everything AcadLink holds about you
        $data = Privacy::myData();
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="my-data-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) Auth::studentNumber()) . '-' . date('Ymd') . '.json"');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
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
    <?php if (Auth::studentNumber()): $no = (string) Auth::studentNumber(); $pend = StudentPhoto::pendingId($no); $dec = StudentPhoto::lastDeclined($no); ?>
    <form method="post" enctype="multipart/form-data" class="form-section" id="myPhoto"><?= csrf_field() ?><input type="hidden" name="action" value="photo">
      <h2 class="h6">My photo</h2>
      <div class="st-photo-box"><figure class="m-0 text-center"><?= StudentPhoto::html(StudentPhoto::currentId($no), (string) ($student['studentName'] ?? $a['display_name']), 'md') ?><figcaption class="small text-muted">On my record</figcaption></figure>
        <?php if ($pend): ?><figure class="m-0 text-center"><?= StudentPhoto::html((int) $pend['id'], 'new', 'md') ?><figcaption class="small text-muted">Waiting for approval</figcaption></figure><?php endif; ?>
        <div class="st-photo-actions"><?php if ($dec): ?><div class="alert alert-warning small py-2">Your last photo was not approved: <?= h((string) $dec['reason']) ?></div><?php endif; ?>
          <label class="form-label small mb-0" for="myPhotoIn">Send a new photo</label><input class="form-control mb-2" type="file" id="myPhotoIn" name="photo" accept="image/png,image/jpeg,image/webp" required>
          <p class="small text-muted mb-2">A clear, recent photo of your face. The Registrar checks it before it goes on your record and ID.</p>
          <button class="btn btn-primary btn-sm">Send photo</button></div></div>
    </form>
    <?php endif; ?>
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
    <section class="form-section" id="myPrivacy"><h2 class="h6">My data and privacy</h2>
      <?= Privacy::noticeHtml() ?>
      <?php if (Auth::studentNumber()): $cons = Privacy::consentOf((string) Auth::studentNumber()); ?>
      <p class="small text-muted mt-2 mb-2"><?= $cons ? 'You (or your parent/guardian) agreed to the privacy notice on ' . h(date('M j, Y', strtotime($cons['created_at']))) . ($cons['notice_version'] !== Privacy::version() ? '. The notice has changed since then.' : '.') : 'No privacy consent is on record yet.' ?></p>
      <form method="post" class="d-flex flex-wrap gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="action" value="mydata"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1" aria-hidden="true"></i>Download my data</button><span class="small text-muted">One file (JSON) with your records: account, admission, registrations, payments, grades, LMS work and attendance, library loans and notifications.</span></form>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php layout_end();
