<?php
/**
 * php sis/tests/v63-backup.php — v63 whole-system backup and restore: only power.admin; choose the parts; restore replaces
 * only the ticked parts in one transaction with a safety copy first; binary data (photos, LMS files) comes back byte for
 * byte; power.admin keeps its sign-in; its username can't change but its password can. Disposable database only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
$cfg = sis_config(); $name = 'sis_v63_test';
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cfg['db_name'] = $name; Db::connect($cfg);
foreach ([dirname(SIS_ROOT) . '/api/install.sql', SIS_ROOT . '/database/schema.sql', SIS_ROOT . '/database/seed.sql', SIS_ROOT . '/database/seed_accounting.sql'] as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) Db::q($stmt);
}
$c = Auth::central(); $c->install(); AccountEmail::install(); AccountingWorkflow::install(); Schedules::install(Db::pdo());
$pdo = Db::pdo(); Canteen::install($pdo); Library::install($pdo); Lms::install($pdo); Notify::install($pdo); StudentPhoto::install($pdo); Privacy::install($pdo); ParentAccess::install($pdo);
$pass = 0; $fail = 0; $made = [];
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; } }
function denied(callable $fn, string $text = ''): void { try { $fn(); } catch (AppError|Forbidden|CentralAccountError $e) { if ($text !== '' && stripos($e->getMessage(), $text) === false) throw new RuntimeException('Wrong error: ' . $e->getMessage()); return; } throw new RuntimeException('Expected a refusal' . ($text ? " ($text)" : '')); }
function account(string $user, string $group, ?int $actor = null): int {
    $c = Auth::central(); $id = $c->save($actor, ['full_name' => ucwords(str_replace('.', ' ', $user)), 'username' => $user, 'group' => $group, 'status' => 'ACTIVE', 'temp_password' => 'Temporary12345'], $actor === null);
    $c->q('UPDATE central_accounts SET must_change_password=0 WHERE id=?', [$id]); $c->clearCache(); return $id;
}
function as_user(int $id): void { $c = Auth::central(); $c->clearCache(); Auth::actAs(null); $c->establish($id); Auth::actAs(null); }

$admin = account('power.admin', 'POWER_USER');
$other = account('power.two', 'POWER_USER', $admin);
$reg = account('bk.registrar', 'REGISTRAR', $admin);
as_user($admin);
$sec = GS::saveSection(null, '2026-2027', '1st Year', 'BK 1A');
$st = GS::saveStudent(null, ['studentId' => 'BK001', 'studentName' => 'Backup, Ana', 'sectionId' => $sec]);
$blob = random_bytes(5000) . "\0\xff binary";

$pid = Canteen::saveProduct(null, ['name' => 'Turon', 'price' => '15', 'track_stock' => 1, 'low_stock_at' => 2, 'is_active' => 1, 'category' => 'Snacks']);
$ins = Db::pdo()->prepare("INSERT INTO sis_student_photos (student_number, status, source, mime, size_bytes, bytes) VALUES ('BK001','APPROVED','STAFF','image/png',?,?)");
$ins->bindValue(1, strlen($blob), PDO::PARAM_INT); $ins->bindValue(2, $blob, PDO::PARAM_LOB); $ins->execute();
$photoId = (int) Db::pdo()->lastInsertId();

test('only power.admin can back up and restore; other Power Users and staff cannot', function () use ($admin, $other, $reg) {
    as_user($admin); ok(Backup::allowed() && Backup::ownerId() === $admin, 'power.admin');
    as_user($other); ok(!Backup::allowed(), 'another Power User'); denied(fn() => Backup::create(['grading']), 'Only power.admin');
    as_user($reg); ok(!Backup::allowed(), 'registrar');
});
test('every table is in one part; sessions, reset codes and restore staging are never backed up', function () {
    $t = Backup::tables();
    foreach (['central_sessions', 'central_password_resets', 'login_attempts', 'grading_restore_jobs'] as $x) ok(!isset($t[$x]), $x . ' skipped');
    ok($t['docs'] === 'grading' && $t['central_accounts'] === 'accounts' && $t['sis_payments'] === 'finance' && $t['sis_journal'] === 'finance' && $t['sis_registrations'] === 'sis'
        && $t['canteen_products'] === 'canteen' && $t['library_loans'] === 'library' && $t['lms_files'] === 'lms' && $t['sis_student_photos'] === 'photos' && $t['sis_audit_logs'] === 'history', json_encode($t));
    $all = array_map('strval', Db::col('SHOW TABLES'));
    ok(count($all) === count($t) + count(array_intersect($all, ['central_sessions', 'central_password_resets', 'login_attempts', 'grading_restore_jobs', 'grading_restore_records', 'central_schema_migrations'])), 'every other table has a part');
});
test('a full backup is one file with every part and every row; binary data is kept exactly', function () use ($admin, &$made, $photoId, $blob) {
    as_user($admin);
    $f = Backup::create(array_keys(Backup::PARTS), 'manual', 'Test backup'); $made[] = $f;
    $h = Backup::header(Backup::path($f));
    ok(count($h['parts']) === count(Backup::PARTS) && $h['note'] === 'Test backup' && $h['made_by'] === 'power.admin', json_encode($h['parts']));
    ok(in_array($f, array_column(Backup::stored(), 'file'), true), 'listed');
    $GLOBALS['full'] = $f;
});
test('restore replaces only the ticked parts, in one go, after a safety copy; photos come back byte for byte', function () use ($admin, &$made, $pid, $photoId, $blob, $st) {
    as_user($admin);
    // changes after the backup
    Db::q('DELETE FROM canteen_products WHERE id = ?', [$pid]);
    Db::q('UPDATE sis_student_photos SET bytes = ? WHERE id = ?', ['changed', $photoId]);
    GS::saveStudent($st, ['studentId' => 'BK001', 'studentName' => 'Changed, Name', 'sectionId' => GS::get('students', $st)['sectionId']]);
    denied(fn() => Backup::restore($GLOBALS['full'], ['canteen', 'photos'], 'yes'), 'RESTORE');
    denied(fn() => Backup::restore($GLOBALS['full'], [], 'RESTORE'), 'Tick');
    $before = count(Backup::stored());
    $r = Backup::restore($GLOBALS['full'], ['canteen', 'photos'], 'RESTORE'); $made[] = $r['safety'];
    ok(Db::val('SELECT name FROM canteen_products WHERE id = ?', [$pid]) === 'Turon', 'product back');
    ok(Db::val('SELECT bytes FROM sis_student_photos WHERE id = ?', [$photoId]) === $blob, 'photo bytes identical');
    ok(GS::get('students', $st)['studentName'] === 'Changed, Name', 'grading part untouched (not ticked)');
    ok(count(Backup::stored()) === $before + 1 && Backup::header(Backup::path($r['safety']))['kind'] === 'safety', 'safety copy saved');
    // the safety copy brings the newer state back
    $r2 = Backup::restore($r['safety'], ['canteen'], 'RESTORE'); $made[] = $r2['safety'];
    ok(Db::val('SELECT COUNT(*) FROM canteen_products WHERE id = ?', [$pid]) == 0, 'safety copy restores the state before');
});
test('a damaged or cut-off backup changes nothing', function () use ($admin, &$made) {
    as_user($admin);
    $src = Backup::path($GLOBALS['full']);
    $lines = gzfile($src); $cut = date('Ymd-His') . '-manual-cut' . bin2hex(random_bytes(3)) . '.acadlink.gz';
    $gz = gzopen(Backup::dir() . '/' . $cut, 'wb'); foreach (array_slice($lines, 0, (int) (count($lines) * 0.7)) as $l) gzwrite($gz, $l); gzclose($gz); $made[] = $cut;
    $n = (int) Db::val('SELECT COUNT(*) FROM docs');
    Db::q("DELETE FROM docs WHERE col = 'sections'");
    $n2 = (int) Db::val('SELECT COUNT(*) FROM docs');
    try { $r = Backup::restore($cut, ['grading', 'canteen'], 'RESTORE'); $made[] = $r['safety']; throw new RuntimeException('cut file restored'); }
    catch (AppError $e) { ok(str_contains($e->getMessage(), 'incomplete') || str_contains($e->getMessage(), 'damaged'), $e->getMessage()); }
    ok((int) Db::val('SELECT COUNT(*) FROM docs') === $n2, 'nothing changed by the failed restore');
    foreach (glob(Backup::dir() . '/*-safety-*.acadlink.gz') as $f) if (!in_array(basename($f), $made, true)) $made[] = basename($f);
    $r = Backup::restore($GLOBALS['full'], ['grading'], 'RESTORE'); $made[] = $r['safety'];
    ok((int) Db::val('SELECT COUNT(*) FROM docs') === $n, 'full restore of grading brings sections back');
});
test('restoring accounts keeps power.admin\'s current password; its username can\'t change, its password can', function () use ($admin, $other, &$made) {
    as_user($admin); $c = Auth::central();
    $c->setPassword($admin, 'NewSecret2026', false, $admin); $hash = Db::val('SELECT password_hash FROM central_credentials WHERE account_id = ?', [$admin]);
    as_user($admin); // a password change signs every session out
    $c->save($admin, ['id' => $other, 'full_name' => 'Power Two Renamed', 'username' => 'power.two', 'group' => 'POWER_USER', 'status' => 'ACTIVE']);
    $r = Backup::restore($GLOBALS['full'], ['accounts'], 'RESTORE'); $made[] = $r['safety']; $c->clearCache();
    ok(Db::val('SELECT password_hash FROM central_credentials WHERE account_id = ?', [$admin]) === $hash, 'power.admin keeps the new password');
    ok(Db::val('SELECT display_name FROM central_accounts WHERE id = ?', [$other]) === 'Power Two', 'other accounts restored');
    as_user($admin);
    denied(fn() => $c->save($admin, ['id' => $admin, 'full_name' => 'Power Admin', 'username' => 'boss', 'group' => 'POWER_USER', 'status' => 'ACTIVE']), "can't be changed");
    as_user($other); denied(fn() => $c->save($other, ['id' => $admin, 'full_name' => 'Power Admin', 'username' => 'power.admin', 'group' => 'POWER_USER', 'status' => 'DISABLED']), 'active Power User');
    denied(fn() => $c->save($other, ['id' => $admin, 'full_name' => 'Power Admin', 'username' => 'power.admin', 'group' => 'REGISTRAR', 'status' => 'ACTIVE']), 'active Power User');
    as_user($admin);
    denied(fn() => $c->save($admin, ['full_name' => 'Fake', 'username' => 'power.admin', 'group' => 'REGISTRAR', 'status' => 'ACTIVE', 'temp_password' => 'Temporary12345']));
    $c->setPassword($admin, 'Another2026x', false, $admin);
    ok(password_verify('Another2026x', (string) Db::val('SELECT password_hash FROM central_credentials WHERE account_id = ?', [$admin])), 'password can change');
    $c->save($admin, ['id' => $admin, 'full_name' => 'Power Admin Name', 'username' => 'power.admin', 'group' => 'POWER_USER', 'status' => 'ACTIVE']);
    ok(Db::val('SELECT display_name FROM central_accounts WHERE id = ?', [$admin]) === 'Power Admin Name', 'name can change');
});
test('backups are kept in a folder the web cannot open; file names cannot leave it', function () use ($admin) {
    as_user($admin);
    ok(is_file(Backup::dir() . '/.htaccess') && str_contains((string) file_get_contents(Backup::dir() . '/.htaccess'), 'denied'), 'htaccess');
    foreach (['../api/config.php', 'x.php', '..%2f.acadlink.gz', '/etc/passwd'] as $bad) denied(fn() => Backup::path($bad), 'not found');
    ok((int) Db::val("SELECT COUNT(*) FROM sis_audit_logs WHERE action IN ('backup_created','backup_restored')") >= 2, 'logged');
});
foreach (array_unique($made) as $f) @unlink(Backup::dir() . '/' . $f);
$c->logout(); $server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
