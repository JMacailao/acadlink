<?php
/**
 * php sis/tests/v62-features.php — v62: personal notifications (LMS, library, canteen), LMS storage limits, student photos
 * with approval, Data Privacy (consent, download my data, retention review), parent / guardian accounts, and faster LMS
 * class loading. Disposable database only; never the configured one.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
$cfg = sis_config(); $name = 'sis_v62_test';
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cfg['db_name'] = $name; Db::connect($cfg);
foreach ([dirname(SIS_ROOT) . '/api/install.sql', SIS_ROOT . '/database/schema.sql', SIS_ROOT . '/database/seed.sql', SIS_ROOT . '/database/seed_accounting.sql'] as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) Db::q($stmt);
}
$c = Auth::central(); $c->install(); AccountEmail::install(); AccountingWorkflow::install(); Schedules::install(Db::pdo()); EnrollmentDocs::install(); StudentDetails::install();
$pdo = Db::pdo(); Canteen::install($pdo); Library::install($pdo); Lms::install($pdo); Notify::install($pdo); Notify::install($pdo); StudentPhoto::install($pdo); Privacy::install($pdo); ParentAccess::install($pdo);
$pass = 0; $fail = 0;
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; } }
function denied(callable $fn, string $text = ''): void { try { $fn(); } catch (AppError|Forbidden $e) { if ($text !== '' && stripos($e->getMessage(), $text) === false) throw new RuntimeException('Wrong error: ' . $e->getMessage()); return; } throw new RuntimeException('Expected a refusal' . ($text ? " ($text)" : '')); }
function account(string $user, string $group, ?int $actor = null, array $extra = []): int {
    $c = Auth::central(); $id = $c->save($actor, $extra + ['full_name' => ucwords(str_replace('.', ' ', $user)), 'username' => $user, 'group' => $group, 'status' => 'ACTIVE', 'temp_password' => 'Temporary12345'], $actor === null);
    $c->q('UPDATE central_accounts SET must_change_password=0 WHERE id=?', [$id]); $c->clearCache(); return $id;
}
function as_user(int $id): void { $c = Auth::central(); $c->clearCache(); Auth::actAs(null); $c->establish($id); Auth::actAs(null); }
function png(int $w = 40, int $h = 50): array { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200)); ob_start(); imagepng($im); $b = ob_get_clean(); $f = tempnam(sys_get_temp_dir(), 'ph'); file_put_contents($f, $b); return ['name' => 'me.png', 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => strlen($b)]; }
function upload(string $name, string $bytes): array { $f = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($f, $bytes); return ['name' => $name, 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)]; }
function mine_types(): array { return array_column(Notify::mine(), 'type'); }

$power = account('v62.power', 'POWER_USER');
as_user($power);
$sec = GS::saveSection(null, '2026-2027', '1st Year', 'V62 1A');
$oldSec = GS::saveSection(null, '2025-2026', '1st Year', 'V62 OLD');
$sub = GS::saveSubject(null, 'V62', 'Privacy Online', '3');
$t1 = GS::saveTeacher(null, 'VT01', 'Santos, Maria'); $t2 = GS::saveTeacher(null, 'VT02', 'Other, Teacher');
$s1 = GS::saveStudent(null, ['studentId' => 'V001', 'studentName' => 'Reyes, Ana', 'sectionId' => $sec]);
$s2 = GS::saveStudent(null, ['studentId' => 'V002', 'studentName' => 'Cruz, Ben', 'sectionId' => $sec]);
$s3 = GS::saveStudent(null, ['studentId' => 'V003', 'studentName' => 'Lim, Carl', 'sectionId' => $sec]);
$cls = GS::assignTeacher($sec, '1st Semester', $sub, $t1, [$s1, $s2]);
$old = GS::assignTeacher($oldSec, '1st Semester', $sub, $t1, [$s1]);
$other = GS::assignTeacher($sec, '2nd Semester', $sub, $t2, [$s3]);
$teacher = account('v62.teacher', 'TEACHER', $power, ['teacher_doc_id' => $t1]);
$teacher2 = account('v62.teacher2', 'TEACHER', $power, ['teacher_doc_id' => $t2]);
$registrar = account('v62.registrar', 'REGISTRAR', $power);
$ana = account('v001', 'STUDENT', $power, ['student_number' => 'V001']);
$ben = account('v002', 'STUDENT', $power, ['student_number' => 'V002']);
$carl = account('v003', 'STUDENT', $power, ['student_number' => 'V003']);
$W = [];

test('new classwork, announcements, returned work and messages notify the right people; drafts do not', function () use ($teacher, $ana, $ben, $carl, $cls, $s1, &$W) {
    as_user($teacher); $c = Lms::classFor($cls);
    $W['draft'] = Lms::saveWork($c, null, ['title' => 'Hidden draft', 'category' => 'Assignment', 'points' => '10', 'status' => 'DRAFT'], []);
    $W['a'] = Lms::saveWork($c, null, ['title' => 'Essay 1', 'category' => 'Assignment', 'points' => '20', 'due_at' => date('Y-m-d H:i', strtotime('+2 days'))], []);
    Lms::savePost($c, 'ANNOUNCEMENT', ['body' => 'Bring your laptops tomorrow.'], []);
    as_user($ana); $t = mine_types();
    ok(count(array_keys($t, 'LMS_WORK')) === 1 && in_array('LMS_POST', $t, true), json_encode($t));
    ok(Notify::unread() === 2, 'unread ' . Notify::unread());
    ok(str_contains(Notify::mine()[1]['title'], 'New assignment in V62') && str_contains((string) Notify::mine()[1]['link_url'], 'r=work'), json_encode(Notify::mine()[1]));
    as_user($carl); ok(Notify::mine() === [], 'not in the class');
    // publishing the draft later notifies once; saving it again does not
    as_user($teacher); $c = Lms::classFor($cls);
    Lms::saveWork($c, $W['draft'], ['title' => 'Now visible', 'category' => 'Assignment', 'points' => '10'], []);
    Lms::saveWork($c, $W['draft'], ['title' => 'Now visible (edited)', 'category' => 'Assignment', 'points' => '10'], []);
    as_user($ben); ok(count(array_keys(mine_types(), 'LMS_WORK')) === 2, json_encode(mine_types()));
    // student hands in; teacher returns with a score
    as_user($ana); Lms::submit(Lms::classFor($cls), $W['a'], ['text_answer' => 'My essay'], [], true);
    as_user($teacher); Lms::grade(Lms::classFor($cls), $W['a'], $s1, '18', 'Good', true);
    as_user($ana); $r = array_values(array_filter(Notify::mine(), fn($n) => $n['type'] === 'LMS_RETURNED'));
    ok($r && str_contains($r[0]['message'], 'Score: 18 / 20'), json_encode($r));
    // messages go to the other side
    Lms::sendMessage(Lms::classFor($cls), $s1, 'Sir, may I resubmit?');
    as_user($teacher); ok(in_array('LMS_MESSAGE', mine_types(), true), 'teacher got message');
    Lms::sendMessage(Lms::classFor($cls), $s1, 'Yes, by Friday.');
    as_user($ana); ok(count(array_keys(mine_types(), 'LMS_MESSAGE')) === 1, 'ana got reply');
});
test('the list is personal: read, read all, and nobody can open another person\'s notification; only AcadLink links are followed', function () use ($ana, $ben) {
    as_user($ana); $n = Notify::mine()[0]; $before = Notify::unread();
    Notify::markRead((int) $n['id']); ok(Notify::unread() === $before - 1, 'mark read');
    as_user($ben); ok(Notify::find((int) $n['id']) === null, 'ben cannot see ana\'s');
    Notify::markRead((int) $n['id']); as_user($ana); Notify::markAllRead(); ok(Notify::unread() === 0, 'all read');
    ok(Notify::safeLink('../lms/index.php?r=work&id=abc_1&w=2') !== '' && Notify::safeLink('index.php?r=alerts') !== '', 'internal ok');
    foreach (['https://evil.example/x', '//evil.example', 'javascript:alert(1)', '../lms/index.php?r=x"><script>'] as $bad) ok(Notify::safeLink($bad) === '', 'rejected ' . $bad);
});
test('without email set up nothing is queued for email; with an email and set-up it is queued', function () use ($ana) {
    ok(Db::val("SELECT COUNT(*) FROM sis_notifications WHERE email_status <> 'NONE'") == 0, 'nothing queued');
    ok(Notify::flushEmails() === [0, 0], 'flush does nothing');
    global $power, $cls;
    as_user($power);
    Db::q("INSERT INTO docs (col, id, data) VALUES ('settings', 'email', ?) ON DUPLICATE KEY UPDATE data = VALUES(data)", [json_encode(['publicKey' => 'pk_test', 'serviceId' => 'svc_test', 'templateId' => 'tpl_test'])]);
    Mailer::savePrivateKey('privkey_test_123');
    ok(Mailer::missing() === [], json_encode(Mailer::missing()));
    AccountEmail::set($ana, 'ana@example.com', $power);
    $id = Notify::toAccount($ana, 'TEST', 'Hello', 'Queued email', '../portal/index.php?r=alerts');
    $n = Db::one('SELECT email_to, email_status FROM sis_notifications WHERE id = ?', [$id]);
    ok($n['email_to'] === 'ana@example.com' && $n['email_status'] === 'PENDING', json_encode($n));
    Db::q("UPDATE sis_notifications SET email_status = 'NONE' WHERE email_status = 'PENDING'"); // do not send real email from the test
    Mailer::savePrivateKey('');
});
test('library reminders: due tomorrow, overdue and reserved-book-ready, each sent once', function () use ($power, $ana, $ben, $s1, $s2) {
    as_user($power);
    $tid = Library::saveTitle(null, ['title' => 'Noli Me Tangere']); $codes = Library::addCopies($tid, 2, []);
    $l1 = Library::checkout('STUDENT', $s1, $codes[0], date('Y-m-d', strtotime('+1 day')));
    $l2 = Library::checkout('STUDENT', $s2, $codes[1], date('Y-m-d', strtotime('+3 days')));
    Db::q('UPDATE library_loans SET due_on = CURDATE() - INTERVAL 2 DAY WHERE id = ?', [$l2]);
    $t2 = Library::saveTitle(null, ['title' => 'El Filibusterismo']); $c2 = Library::addCopies($t2, 1, []);
    Library::placeHold($t2, 'STUDENT', $s1); // a free copy: goes to the hold shelf at once
    $r = Notify::scan(true); Notify::scan(true);
    ok($r['due'] === 1 && $r['overdue'] === 1 && $r['ready'] === 1, json_encode($r));
    as_user($ana); $t = mine_types(); ok(in_array('LIBRARY_DUE', $t, true) && in_array('LIBRARY_READY', $t, true) && !in_array('LIBRARY_OVERDUE', $t, true), json_encode($t));
    as_user($ben); ok(count(array_keys(mine_types(), 'LIBRARY_OVERDUE')) === 1, 'ben overdue once');
});
test('canteen low stock goes to staff who manage stock, once a day for the same items', function () use ($power) {
    $sup = account('v62.super', 'CANTEEN_SUPERVISOR', $power); $cash = account('v62.cashier', 'CANTEEN_CASHIER', $power);
    as_user($power); $pid = Canteen::saveProduct(null, ['name' => 'Siopao', 'price' => '25', 'track_stock' => 1, 'low_stock_at' => 5, 'is_active' => 1, 'category' => 'Snacks']);
    Db::q('UPDATE canteen_products SET stock_qty = 2 WHERE id = ?', [$pid]);
    $r = Notify::scan(true); $r2 = Notify::scan(true);
    ok($r['low'] >= 1 && $r2['low'] === 0, json_encode([$r, $r2]));
    as_user($sup); $n = array_values(array_filter(Notify::mine(), fn($x) => $x['type'] === 'CANTEEN_LOW_STOCK'));
    ok($n && str_contains($n[0]['message'], 'Siopao (2 left)'), json_encode($n));
    as_user($cash); ok(!in_array('CANTEEN_LOW_STOCK', mine_types(), true), 'cashier without stock access not told');
});
test('LMS storage limit per class; usage counted; coordinator frees an old year but never the current one', function () use ($teacher, $power, $ana, $cls, $old) {
    Db::q("INSERT INTO sis_settings (skey, svalue) VALUES ('lms_class_quota_mb', '1') ON DUPLICATE KEY UPDATE svalue = '1'");
    as_user($teacher); $c = Lms::classFor($cls);
    $big = str_repeat('A', 700000);
    Lms::savePost($c, 'MATERIAL', ['title' => 'Notes 1'], [upload('a.txt', $big)]);
    ok(Lms::classUsage($cls) === 700000, 'usage ' . Lms::classUsage($cls));
    denied(fn() => Lms::savePost(Lms::classFor($cls), 'MATERIAL', ['title' => 'Notes 2'], [upload('b.txt', $big)]), 'file space');
    ok((int) Db::val('SELECT COUNT(*) FROM lms_posts WHERE title = ?', ['Notes 2']) === 0, 'refused post was not saved');
    denied(fn() => Lms::saveQuota(5), 'coordinator');
    $oc = Lms::classFor($old); Lms::savePost($oc, 'MATERIAL', ['title' => 'Old notes'], [upload('old.txt', 'old file')]);
    as_user($power); Lms::saveQuota(0); ok(Lms::quotaBytes() === 0, 'no limit');
    denied(fn() => Lms::cleanYear('2026-2027', '2026-2027'), 'current school year');
    denied(fn() => Lms::cleanYear('2025-2026', 'yes'), 'confirm');
    [$n, $b] = Lms::cleanYear('2025-2026', '2025-2026');
    ok($n === 1 && $b === 8 && Lms::classUsage($old) === 0 && Lms::classUsage($cls) === 700000, json_encode([$n, $b]));
    ok((int) Db::val("SELECT COUNT(*) FROM lms_posts WHERE title = 'Old notes'") === 1, 'post kept');
    Lms::saveQuota(500);
});
test('admission photo becomes the student photo on admission; consent is recorded with the notice version', function () use ($registrar, $power) {
    as_user($power);
    $id = Admission::create(Admission::validate(['applicant_type' => 'NEW', 'address' => '1 Rizal St.', 'last_name' => 'Photo', 'first_name' => 'Pia', 'birth_date' => '2008-01-02', 'program' => 'BSIT', 'year_level' => '1st Year', 'school_year' => '2026-2027']));
    Privacy::recordConsent('ADMISSION', (string) $id, 'PARENT');
    $pid = StudentPhoto::forAdmission($id, png());
    ok(StudentPhoto::admissionPhotoId($id) === $pid, 'admission photo');
    $no = Admission::admit($id);
    ok(StudentPhoto::currentId($no) === $pid, 'student photo after admission');
    $cons = Privacy::consentOf($no); ok($cons && $cons['given_by'] === 'PARENT' && $cons['notice_version'] === Privacy::version(), json_encode($cons));
    $GLOBALS['pia'] = $no;
});
test('a student\'s new photo waits for the registrar; decline needs a reason; approval replaces the photo and tells the student', function () use ($ana, $ben, $registrar, $teacher) {
    as_user($ana); $p1 = StudentPhoto::submitOwn(png(60, 80));
    ok(StudentPhoto::currentId('V001') === null && StudentPhoto::pendingId('V001')['id'] == $p1, 'pending, not current');
    [$mime] = StudentPhoto::open($p1); ok($mime === 'image/png', 'student opens own pending photo');
    as_user($ben); denied(fn() => StudentPhoto::open($p1), 'cannot open');
    as_user($teacher); denied(fn() => StudentPhoto::decide($p1, true));
    as_user($registrar); ok(in_array('PHOTO_REVIEW', mine_types(), true), 'registrar told');
    denied(fn() => StudentPhoto::decide($p1, false, ''), 'why');
    StudentPhoto::decide($p1, false, 'Face not clear');
    as_user($ana); ok(StudentPhoto::lastDeclined('V001')['reason'] === 'Face not clear' && in_array('PHOTO_DECIDED', mine_types(), true), 'declined + told');
    $p2 = StudentPhoto::submitOwn(png(60, 80));
    as_user($registrar); StudentPhoto::decide($p2, true); denied(fn() => StudentPhoto::decide($p2, true), 'no longer waiting');
    ok(StudentPhoto::currentId('V001') === $p2 && StudentPhoto::lastDeclined('V001') === null, 'approved photo is current');
    $p3 = StudentPhoto::staffSet('V001', png()); ok(StudentPhoto::currentId('V001') === $p3, 'staff replaces');
});
test('download my data: the student\'s own records in one file, logged; staff cannot use it', function () use ($ana, $registrar) {
    as_user($ana); $d = Privacy::myData();
    foreach (['account', 'student_record', 'registrations', 'payments', 'grades', 'lms_work', 'lms_messages', 'library_loans', 'photos', 'notifications'] as $k) ok(array_key_exists($k, $d), 'missing ' . $k);
    ok($d['account']['username'] === 'v001' && $d['student_record']['studentId'] === 'V001' && count($d['library_loans']) === 1 && $d['lms_work'][0]['score'] == 18, json_encode([$d['account'], count($d['library_loans'])]));
    ok(!str_contains(json_encode($d), 'password_hash'), 'no password hashes');
    ok((int) Db::val("SELECT COUNT(*) FROM sis_audit_logs WHERE action = 'privacy_data_exported'") === 1, 'logged');
    as_user($registrar); denied(fn() => Privacy::myData(), 'Only students');
});
test('privacy notice is editable and versioned; old records are flagged for review, never deleted', function () use ($power, $registrar, $s3) {
    as_user($power); $v1 = Privacy::version();
    Privacy::saveNotice(Privacy::notice() . "\n\nDPO: dpo@school.example", 5); ok(Privacy::version() !== $v1, 'new version');
    denied(fn() => Privacy::saveNotice('x', 0), '1 to 50');
    Db::insert('sis_registrations', ['reg_no' => 'R-OLD-1', 'student_number' => 'V003', 'student_doc_id' => $s3, 'student_name' => 'Lim, Carl', 'school_year' => '2015-2016', 'term' => '1st Semester', 'year_level' => '1st Year', 'section_doc_id' => 'x', 'section_name' => 'Old', 'created_by' => 1]);
    Db::insert('sis_registrations', ['reg_no' => 'R-NEW-1', 'student_number' => 'V002', 'student_doc_id' => 'x', 'student_name' => 'Cruz, Ben', 'school_year' => '2025-2026', 'term' => '1st Semester', 'year_level' => '1st Year', 'section_doc_id' => 'x', 'section_name' => 'New', 'created_by' => 1]);
    as_user($registrar); $due = Privacy::dueForReview();
    ok(count($due) === 1 && $due[0]['student_number'] === 'V003' && $due[0]['years_ago'] >= 5, json_encode($due));
    ok(Privacy::markReviewed(['V003'], 'Kept for TOR requests') === 1 && Privacy::dueForReview() === [], 'reviewed this year');
    ok((int) Db::val("SELECT COUNT(*) FROM sis_registrations WHERE student_number = 'V003'") === 1 && GS::studentByNumber('V003') !== null, 'nothing deleted');
});
test('the Registrar creates a parent account; parents see only their linked children, read-only', function () use ($registrar, $teacher, $power, $ana, $cls) {
    as_user($teacher); denied(fn() => ParentAccess::create('V001', ['full_name' => 'Maria Reyes', 'username' => 'p.maria', 'temp_password' => 'Temporary12345']));
    as_user($registrar);
    $p = ParentAccess::create('V001', ['full_name' => 'Maria Reyes', 'username' => 'p.maria', 'temp_password' => 'Temporary12345', 'relationship' => 'Mother', 'email' => 'maria@example.com']);
    $c = Auth::central(); $c->clearCache();
    ok($c->group($p) === 'PARENT', 'group');
    ok(array_keys($c->permissions($p)) == ['portal.workspace.view', 'portal.parent.view'] || count(array_diff(array_keys($c->permissions($p)), ['portal.workspace.view', 'portal.parent.view'])) === 0, json_encode(array_keys($c->permissions($p))));
    ok(ModuleAccess::systems($c->permissions($p)) === ['PORTAL'], json_encode(ModuleAccess::systems($c->permissions($p))));
    ok(ParentAccess::parentsOf('V001')[0]['relationship'] === 'Mother', 'listed on the student');
    // the registrar cannot use this to change a staff account
    try { $c->saveParent((int) Auth::centralId(), ['id' => $power, 'full_name' => 'x', 'username' => 'v62.power', 'status' => 'ACTIVE']); throw new RuntimeException('changed a staff account'); } catch (CentralAccountError) {}
    denied(fn() => ParentAccess::link('V002', 'v62.teacher', 'Father'), 'not a Parent');
    ParentAccess::link('V002', 'p.maria', 'Guardian');
    $c->q('UPDATE central_accounts SET must_change_password=0 WHERE id=?', [$p]);
    as_user($p);
    ok(ParentAccess::isParent() && array_column(ParentAccess::children(), 'student_number') === ['V001', 'V002'], json_encode(array_column(ParentAccess::children(), 'student_number')));
    denied(fn() => ParentAccess::child('V003'), 'not linked');
    $sum = ParentAccess::summary('V001');
    ok(count($sum['classes']) === 1 && $sum['classes'][0]['code'] === 'V62' && $sum['photo'] !== null, json_encode($sum['classes']));
    ok(StudentPhoto::open((int) $sum['photo'])[0] === 'image/png', 'parent sees child photo');
    ok(!Auth::can('students') && !Auth::can('lms.view') && Auth::can('parent'), 'read-only parent permissions');
    as_user($registrar); ParentAccess::unlink('V002', $p);
    as_user($p); denied(fn() => ParentAccess::child('V002'), 'not linked');
});
test('LMS reads only this school year\'s classes, and a teacher only their own; results are unchanged', function () use ($teacher, $teacher2, $ana, $cls, $other) {
    as_user($teacher); $m = Lms::myClasses('2026-2027'); ok(array_column($m, 'id') === [$cls], json_encode(array_column($m, 'id')));
    ok(count(Lms::myClasses('2025-2026')) === 1, 'old year class');
    as_user($teacher2); ok(array_column(Lms::myClasses('2026-2027'), 'id') === [$other], 'teacher2 own class');
    as_user($ana); ok(array_column(Lms::myClasses('2026-2027'), 'id') === [$cls], 'student class');
    ok(array_column(Lms::roster(Lms::classFor($cls)), 'no') === ['V002', 'V001'] || count(Lms::roster(Lms::classFor($cls))) === 2, 'roster');
});
$c->logout(); $server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
