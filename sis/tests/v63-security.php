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
$canLogin = function (string $pw) use ($c, &$why): bool { try { $c->login('power.admin', $pw, 'SIS'); $c->logout(); return true; } catch (Throwable $e) { $why = get_class($e) . ': ' . $e->getMessage(); return false; } };

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
$c->logout(); $server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
