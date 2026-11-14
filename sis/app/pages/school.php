<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
/**
 * School Profile (v63.8): the school's name, contacts, registrar, logo and sign-in page picture.
 * It edits the same records as the Grading System's School settings (settings/school and settings/signin),
 * so the SIS, Student Portal, LMS, Canteen POS, Library and printouts all follow it.
 */
const SCHOOL_LOGO_MAX = 30000;      // characters of the logo's data URL (same limit as the Grading System)
const SCHOOL_PIC_PART = 30000;      // the sign-in picture is kept in pieces image0…imageN of this size
const SCHOOL_PIC_PARTS = 20;

$read = function (string $id): array {
    $raw = Db::val("SELECT data FROM docs WHERE col = 'settings' AND id = ?", [$id]);
    return $raw ? (json_decode((string) $raw, true) ?: []) : [];
};
$write = function (string $id, array $data): void {
    Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)',
        ['settings', $id, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
};
$me = Auth::user();
$byName = (string) ($me['full_name'] ?? $me['username'] ?? '');

if ($isPost) {
    $one = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
    $in = [
        'schoolName' => $one('schoolName', 160), 'shortName' => $one('shortName', 40),
        'address' => mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 300),
        'phone' => $one('phone', 80), 'email' => strtolower($one('email', 160)), 'website' => $one('website', 200),
        'registrarName' => $one('registrarName', 120), 'registrarTitle' => $one('registrarTitle', 120),
    ];
    if ($in['schoolName'] === '') throw new AppError('Enter the school name.');
    if ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) throw new AppError('Enter a valid school email address, or leave it empty.');

    $old = $read('school');
    $logo = (string) ($_POST['logo'] ?? '__keep__');
    if ($logo === '__keep__') $logo = (string) ($old['logo'] ?? '');
    elseif ($logo !== '' && (strlen($logo) > SCHOOL_LOGO_MAX || !preg_match('#^data:image/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$#', $logo)))
        throw new AppError('The logo could not be saved. Choose a PNG, JPG or WEBP image and try again.');
    $in['logo'] = $logo;

    $pic = (string) ($_POST['signin_pic'] ?? '__keep__');
    if ($pic !== '__keep__') {
        if ($pic !== '' && (strlen($pic) > SCHOOL_PIC_PART * SCHOOL_PIC_PARTS || !preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $pic)))
            throw new AppError('The sign-in picture could not be saved. Choose a smaller PNG, JPG or WEBP picture.');
    }

    Db::tx(function () use ($write, $old, $in, $pic, $byName) {
        $write('school', array_merge($old, $in, ['updatedAt' => GS::ts(), 'updatedByName' => $byName]));
        if ($pic !== '__keep__') {
            $doc = ['parts' => 0, 'updatedAt' => GS::ts(), 'updatedByName' => $byName];
            for ($i = 0; $i * SCHOOL_PIC_PART < strlen($pic); $i++) { $doc['image' . $i] = substr($pic, $i * SCHOOL_PIC_PART, SCHOOL_PIC_PART); $doc['parts'] = $i + 1; }
            $write('signin', $doc);
        }
    });
    Audit::log('school_profile_saved', 'settings', 0, ['school' => $in['schoolName'], 'logo' => $in['logo'] !== '' ? 'set' : 'none', 'signin_picture' => $pic === '__keep__' ? 'unchanged' : ($pic === '' ? 'removed' : 'changed')]);
    flash('success', 'School profile saved. The name and logo now show in every system and on printouts.');
    redirect('school');
}

$s = $read('school') + ['schoolName' => '', 'shortName' => '', 'address' => '', 'phone' => '', 'email' => '', 'website' => '', 'registrarName' => '', 'registrarTitle' => '', 'logo' => ''];
$logoNow = School::logo();
$hasPic = School::signinPicture() !== null;
$saved = '';
if (is_array($s['updatedAt'] ?? null) && is_string($s['updatedAt']['__ts'] ?? null)) {
    $t = strtotime($s['updatedAt']['__ts']);
    if ($t) $saved = 'Last saved ' . date('F j, Y g:i A', $t) . (($s['updatedByName'] ?? '') !== '' ? ' by ' . $s['updatedByName'] : '');
}
$v = fn(string $k) => h((string) ($s[$k] ?? ''));
layout_start('School Profile');
page_title('School Profile', '', 'The school\'s name, logo and contacts. Every system (SIS, Student Portal, Grading System, LMS, Canteen POS, Library) and every printout uses them.');
?>
<form method="post" class="row g-3" id="schoolForm" data-record-detail><?= csrf_field() ?>
  <input type="hidden" name="logo" id="spLogoData" value="__keep__">
  <input type="hidden" name="signin_pic" id="spPicData" value="__keep__">
  <div class="col-xl-8">
    <section class="form-section">
      <h2 class="h6"><i class="bi bi-building me-1" aria-hidden="true"></i>School</h2>
      <div class="row g-3">
        <div class="col-md-8"><label class="form-label" for="spName">School name <span class="text-danger">*</span></label><input class="form-control" id="spName" name="schoolName" value="<?= $v('schoolName') ?>" maxlength="160" required autocomplete="organization"></div>
        <div class="col-md-4"><label class="form-label" for="spShort">Short name</label><input class="form-control" id="spShort" name="shortName" value="<?= $v('shortName') ?>" maxlength="40" placeholder="e.g. STA"></div>
        <div class="col-12"><label class="form-label" for="spAddress">Address</label><input class="form-control" id="spAddress" name="address" value="<?= $v('address') ?>" maxlength="300" placeholder="e.g. 123 Rizal St., Poblacion, Quezon City"></div>
        <div class="col-md-4"><label class="form-label" for="spPhone">Phone</label><input class="form-control" id="spPhone" name="phone" value="<?= $v('phone') ?>" maxlength="80" inputmode="tel"></div>
        <div class="col-md-4"><label class="form-label" for="spEmail">Email</label><input class="form-control" id="spEmail" name="email" type="email" value="<?= $v('email') ?>" maxlength="160"></div>
        <div class="col-md-4"><label class="form-label" for="spWebsite">Website</label><input class="form-control" id="spWebsite" name="website" value="<?= $v('website') ?>" maxlength="200" placeholder="e.g. www.school.edu.ph"></div>
      </div>
    </section>
    <section class="form-section">
      <h2 class="h6"><i class="bi bi-pen me-1" aria-hidden="true"></i>Registrar (signs grade reports)</h2>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="spReg">Registrar's name</label><input class="form-control" id="spReg" name="registrarName" value="<?= $v('registrarName') ?>" maxlength="120"></div>
        <div class="col-md-6"><label class="form-label" for="spRegTitle">Title</label><input class="form-control" id="spRegTitle" name="registrarTitle" value="<?= $v('registrarTitle') ?>" maxlength="120" placeholder="e.g. Registrar"></div>
      </div>
    </section>
  </div>
  <div class="col-xl-4">
    <section class="form-section">
      <h2 class="h6"><i class="bi bi-image me-1" aria-hidden="true"></i>School logo</h2>
      <div class="sp-logo-row">
        <div class="sp-logo-box" id="spLogoBox"><?php if ($logoNow): ?><img src="<?= h($logoNow) ?>" alt="School logo"><?php else: ?><i class="bi bi-image text-secondary" aria-hidden="true"></i><?php endif; ?></div>
        <div>
          <label class="btn btn-outline-primary btn-sm mb-2"><i class="bi bi-upload me-1" aria-hidden="true"></i>Choose logo<input type="file" id="spLogoFile" accept="image/png,image/jpeg,image/webp" hidden></label>
          <button type="button" class="btn btn-outline-danger btn-sm mb-2<?= $logoNow ? '' : ' d-none' ?>" id="spLogoRemove"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Clear</button>
          <p class="small text-muted mb-0">PNG, JPG or WEBP, up to 5 MB. It is made small (160 px) before saving. A square logo looks best.</p>
        </div>
      </div>
    </section>
    <section class="form-section">
      <h2 class="h6"><i class="bi bi-card-image me-1" aria-hidden="true"></i>Sign-in page picture</h2>
      <div class="sp-pic-box" id="spPicBox"><?php if ($hasPic): ?><img src="<?= h(url('signinpic', ['v' => (string) time()])) ?>" alt="Sign-in page picture"><?php else: ?><i class="bi bi-image text-secondary" aria-hidden="true"></i><?php endif; ?></div>
      <label class="btn btn-outline-primary btn-sm mt-2"><i class="bi bi-upload me-1" aria-hidden="true"></i>Choose picture<input type="file" id="spPicFile" accept="image/png,image/jpeg,image/webp" hidden></label>
      <button type="button" class="btn btn-outline-danger btn-sm mt-2<?= $hasPic ? '' : ' d-none' ?>" id="spPicRemove"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Clear</button>
      <p class="small text-muted mt-2 mb-0">Shown beside the sign-in form. A wide photo of the campus works well (up to 15 MB).</p>
    </section>
    <section class="form-section">
      <button class="btn btn-primary w-100" id="spSave"><i class="bi bi-save me-1" aria-hidden="true"></i>Save school profile</button>
      <?php if ($saved !== ''): ?><p class="small text-muted mt-2 mb-0"><?= h($saved) ?></p><?php endif; ?>
      <p class="small text-muted mt-2 mb-0">The Grading System's School settings edit the same profile; its connection, email and Google Sheets settings stay there.</p>
    </section>
  </div>
</form>
<style>
.sp-logo-row { display: flex; gap: 14px; align-items: flex-start; }
.sp-logo-box { width: 96px; height: 96px; flex-shrink: 0; border: 1px dashed #c8d3cd; border-radius: 50%; display: grid; place-items: center; overflow: hidden; background: #fff; font-size: 2rem; }
.sp-logo-box img { width: 100%; height: 100%; object-fit: contain; }
.sp-pic-box { aspect-ratio: 16 / 9; border: 1px dashed #c8d3cd; border-radius: 12px; display: grid; place-items: center; overflow: hidden; background: #f6f8f7; font-size: 2rem; }
.sp-pic-box img { width: 100%; height: 100%; object-fit: cover; }
</style>
<script src="<?= sis_web() ?>assets/school-profile.js?v=2" defer></script>
<?php layout_end();
