<?php
/**
 * php sis/tests/v63-security.php — v63.3: power.admin's first password is set on First-time setup (no published
 * password); the old starting password is retired; setup proves the installer (database password, or this computer
 * when the database has none); tries are limited; system folders refuse direct web access. Disposable database only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
$cfg = sis_config(); $name = 'sis_v633_test';
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("USE `$name`");
$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(SIS_ROOT) . '/INSTALL.sql'));
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $stmt) { $s = $server->query($stmt); while ($s->nextRowset()) {} $s->closeCursor(); }
$cfg['db_name'] = $name; Db::connect($cfg);
$pass = 0; $fail = 0;
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; } }
function denied(callable $fn, string $text = ''): void { try { $fn(); } catch (AppError|Forbidden|CentralAccountError $e) { if ($text !== '' && stripos($e->getMessage(), $text) === false) throw new RuntimeException('Wrong error: ' . $e->getMessage()); return; } throw new RuntimeException('Expected a refusal' . ($text ? " ($text)" : '')); }
$c = Auth::central();
$id = (int) Db::val("SELECT account_id FROM central_login_identifiers WHERE identifier_key = 'power.admin'");
$why = ''; // the reason the last sign-in was refused (shown when a check fails)
$canLogin = function (string $pw) use ($c, &$why): bool { try { $c->login('power.admin', $pw, 'SIS'); $c->logout(); return true; } catch (Throwable $e) {
    $a = Db::one("SELECT a.status, a.failed_logins, a.locked_until, NOW() AS now, c.password_hash FROM central_accounts a JOIN central_credentials c ON c.account_id = a.id JOIN central_login_identifiers i ON i.account_id = a.id WHERE i.identifier_key = 'power.admin'") ?? [];
    $why = get_class($e) . ': ' . $e->getMessage() . ' [status ' . ($a['status'] ?? '?') . ', failed ' . ($a['failed_logins'] ?? '?') . ', locked until ' . ($a['locked_until'] ?? 'none') . ', now ' . ($a['now'] ?? '?') . ', password matches ' . (isset($a['password_hash']) && password_verify($pw, (string) $a['password_hash']) ? 'yes' : 'no') . ']';
    return false; } };

test('a fresh installation has no usable power.admin password: nobody can sign in until setup', function () use ($id, $canLogin) {
    ok($id > 0 && FirstSetup::needed(), 'setup needed');
    ok(Db::val('SELECT password_hash FROM central_credentials WHERE account_id = ?', [$id]) === FirstSetup::UNSET, 'no hash');
    foreach (['Start-32f32c74-A1', '!setup', '', 'password'] as $pw) ok(!$canLogin($pw), 'signed in with ' . $pw);
    ok(!str_contains((string) file_get_contents(dirname(SIS_ROOT) . '/INSTALL.sql'), 'Start-32f32c74-A1') && !str_contains((string) file_get_contents(dirname(SIS_ROOT) . '/START_HERE.md'), 'Start-32f32c74-A1'), 'no published password');
});
test('setup needs proof: from this computer when the database has no password; never from elsewhere; tries are limited', function () use ($canLogin) {
    if (FirstSetup::mode() !== 'local') { echo "  (the test database has a password: checked the database-password proof)\n"; denied(fn() => FirstSetup::complete('wrong', 'MyNewAdmin2026', 'MyNewAdmin2026'), 'not the database password'); return; }
    $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
    for ($i = 0; $i < 5; $i++) denied(fn() => FirstSetup::complete('', 'MyNewAdmin2026', 'MyNewAdmin2026'), 'computer where AcadLink is installed');
    denied(fn() => FirstSetup::complete('', 'MyNewAdmin2026', 'MyNewAdmin2026'), 'Too many tries');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    denied(fn() => FirstSetup::complete('', 'MyNewAdmin2026', 'Different2026'), 'not the same');
    denied(fn() => FirstSetup::complete('', 'short', 'short'));
    denied(fn() => FirstSetup::complete('', 'Start-32f32c74-A1', 'Start-32f32c74-A1'), 'of your own');
    FirstSetup::complete('', 'MyNewAdmin2026', 'MyNewAdmin2026');
    ok(!FirstSetup::needed() && $canLogin('MyNewAdmin2026'), 'power.admin signs in with the chosen password');
    denied(fn() => FirstSetup::complete('', 'Another2026x', 'Another2026x'), 'already done');
    ok((int) Db::val("SELECT COUNT(*) FROM sis_audit_logs WHERE action = 'first_setup_done'") === 1, 'logged');
});
test('an existing install still using the old starting password is switched to setup; a changed password is left alone', function () use ($id, $canLogin, &$why) {
    // An existing install starts with no failed sign-ins (the tests above try wrong passwords on purpose, and when the test
    // database has a password the setup test never signs in, so they would still count here and lock the account).
    Db::q('UPDATE central_accounts SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$id]); Db::q('DELETE FROM login_attempts');
    Db::q('UPDATE central_credentials SET password_hash = ? WHERE account_id = ?', [password_hash('Start-32f32c74-A1', PASSWORD_DEFAULT), $id]);
    FirstSetup::install(); ok(FirstSetup::needed() && !$canLogin('Start-32f32c74-A1'), 'old password retired');
    Db::q('UPDATE central_credentials SET password_hash = ? WHERE account_id = ?', [password_hash('SchoolOwn2026', PASSWORD_DEFAULT), $id]);
    FirstSetup::install(); ok(!FirstSetup::needed(), 'own password kept (no setup needed)'); ok($canLogin('SchoolOwn2026'), 'own password kept (sign-in: ' . $GLOBALS['why'] . ')');
});
test('system folders refuse direct web access (Apache .htaccess); only index.php and assets stay public', function () {
    $root = dirname(SIS_ROOT);
    foreach (['shared', 'lms/lib', 'lms/pages', 'canteen/lib', 'canteen/pages', 'library/lib', 'library/pages', 'portal/pages', 'portal/views', 'finance/lib', 'sis/app', 'backups'] as $d)
        ok(is_file("$root/$d/.htaccess") && str_contains((string) file_get_contents("$root/$d/.htaccess"), 'Require all denied'), "$d is not protected");
    foreach (['lms', 'canteen', 'library', 'portal'] as $d) ok(str_contains((string) file_get_contents("$root/$d/.htaccess"), 'Require all denied') && !is_file("$root/$d/assets/.htaccess"), "$d root rules");
    ok(str_contains((string) file_get_contents("$root/.htaccess"), '\.(sql|md|log|bak|gz|zip|ini|sh)$'), 'root blocks sql/md/backups');
});
test('two-step sign-in: RFC codes; on with the first code; codes and recovery codes work once; the password alone is not enough', function () use ($c, $id) {
    $rfc = '12345678901234567890';
    ok(TwoStep::code($rfc, intdiv(59, 30)) === '287082' && TwoStep::code($rfc, intdiv(1111111109, 30)) === '081804' && TwoStep::code($rfc, intdiv(1234567890, 30)) === '005924', 'RFC 6238 test values');
    ok(TwoStep::unbase32(TwoStep::base32($rfc)) === $rfc, 'base32 round trip');
    $ts = $c->twoStep(); $ts->disable($id);
    Db::q('UPDATE central_credentials SET password_hash = ? WHERE account_id = ?', [password_hash('TwoStepPass2026', PASSWORD_DEFAULT), $id]);
    Db::q('UPDATE central_accounts SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$id]); Db::q('DELETE FROM login_attempts');
    [$key, $uri] = $ts->begin($id, 'power.admin', 'AcadLink'); $secret = TwoStep::unbase32($key);
    ok(str_starts_with($uri, 'otpauth://totp/') && str_contains($uri, 'secret=' . $key), 'setup link for the app');
    ok(!$ts->enabled($id), 'not on until the first code is confirmed');
    $raw = (string) Db::val('SELECT secret_enc FROM central_two_step WHERE account_id = ?', [$id]); ok(!str_contains($raw, $key) && base64_decode($raw, true) !== false, 'secret is stored encrypted');
    $step = intdiv(time(), 30);
    $codes = $ts->confirm($id, TwoStep::code($secret, $step)); ok($ts->enabled($id) && count($codes) === 10, 'on, with 10 recovery codes');
    ok(!$ts->verify($id, TwoStep::code($secret, $step)), 'the same code does not work twice');
    ok(!$ts->verify($id, '000000') || TwoStep::code($secret, $step + 1) === '000000', 'a wrong code is refused');
    ok($ts->verify($id, TwoStep::code($secret, $step + 1)), 'the next code (clock a little ahead) works');
    ok($ts->verify($id, $codes[0]) && !$ts->verify($id, $codes[0]) && $ts->recoveryLeft($id) === 9, 'a recovery code works once');
    ok($ts->verify($id, strtoupper(str_replace('-', '', $codes[1]))), 'recovery code typed without the dash, in capitals');
    try { $c->login('power.admin', 'TwoStepPass2026', 'SIS'); ok(false, 'signed in without the code'); } catch (CentralTwoStepRequired $t) { ok($t->accountId === $id, 'the password alone asks for the code'); }
    ok($c->current() === null, 'nothing is signed in before the code');
    $a = $c->afterPassword($id, 'SIS', true); ok((int) $a['id'] === $id && $c->current() !== null, 'signed in after the code');
    $c->logout(); $ts->disable($id); ok(!$ts->enabled($id), 'turned off');
});
$c->logout(); $server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
