<?php
/**
 * php sis/tests/v63-db-collation.php — v63.19/v63.20: AcadLink never fails with "Illegal mix of collations".
 *  - Hosting like Hostinger: the server forces collation_connection = utf8mb4_unicode_ci and the database is created with
 *    utf8mb4_general_ci. A fresh install there must sign in, check permissions and create accounts of every group.
 *  - Every table AcadLink creates names its collation; the database and its tables are put on one rule on upgrade.
 *  - Every database connection states the rule (SET NAMES … COLLATE).
 * Builds its own throwaway database; the Hostinger-like part needs a database user that may create users (CI's root).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
$cfg = sis_config(); $name = 'sis_v6320_test'; $root = dirname(SIS_ROOT);
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$pass = 0; $fail = 0; $skip = 0;
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; } }

// ------------------------------------------------------------------ the source code
$files = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files "*.php" "*.sql" 2>/dev/null')));
if (!$files) { // not a git checkout: walk the folders
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { $p = substr($f->getPathname(), strlen($root) + 1); if (preg_match('/\.(php|sql)$/', $p) && !str_starts_with($p, '.git/') && !str_starts_with($p, 'node_modules/')) $files[] = $p; }
}
test('every CREATE TABLE names its collation (never the server\'s default)', function () use ($files, $root) {
    $bad = [];
    foreach ($files as $f) {
        $s = (string) file_get_contents("$root/$f");
        if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(/i', $s, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as $i => [$text, $at]) {
            // walk to the closing parenthesis of the column list, then read the table options up to ; or a quote
            $p = $at + strlen($text); $depth = 1; $n = strlen($s);
            while ($p < $n && $depth > 0) { $ch = $s[$p++]; if ($ch === '(') $depth++; elseif ($ch === ')') $depth--; }
            preg_match('/\G[^;\'"]*/', $s, $opt, 0, $p);
            $helper = trim($opt[0] ?? '') === '' && preg_match('/\$sql\s*\.\s*\' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\'/', $s); // install helper adds the options
            if (!$helper && stripos($opt[0] ?? '', 'COLLATE') === false) $bad[] = "$f:" . (substr_count($s, "\n", 0, $at) + 1) . ' ' . $m[1][$i][0];
        }
    }
    ok(!$bad, "CREATE TABLE without COLLATE=utf8mb4_unicode_ci (or utf8mb4_bin): " . implode(', ', $bad));
});
test('every database connection states the collation (SET NAMES utf8mb4 COLLATE …)', function () use ($files, $root) {
    $bad = [];
    foreach ($files as $f) {
        if (str_starts_with($f, 'sis/tests/')) continue; // test scripts open their own server connections
        $s = (string) file_get_contents("$root/$f");
        if (!str_contains($s, 'new PDO(')) continue;
        if (!preg_match('/SET NAMES utf8mb4 COLLATE/', $s)) $bad[] = $f;
    }
    ok(!$bad, 'connects without SET NAMES utf8mb4 COLLATE: ' . implode(', ', $bad));
    ok(Db::COLLATION === 'utf8mb4_unicode_ci', 'Db::COLLATION changed');
});

// ------------------------------------------------------------------ a database on a Hostinger-like server
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`");
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); // the host's default, not AcadLink's
$server->exec("USE `$name`");
$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents("$root/INSTALL.sql"));
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $stmt) { $s = $server->query($stmt); while ($s->nextRowset()) {} $s->closeCursor(); }
// a table an older version (or the host) made with another rule; the upgrade must convert it
$server->exec('CREATE TABLE zz_old_rule (code VARCHAR(20) NOT NULL PRIMARY KEY, label VARCHAR(80) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
$server->exec("INSERT INTO zz_old_rule VALUES ('STUDENT', 'Student')");

// Hostinger: a database user without SUPER, and the server sets collation_connection = utf8mb4_unicode_ci on connect
$hostUser = 'acl_host_test'; $hostPw = 'Ht' . bin2hex(random_bytes(8)) . '9';
$oldInit = null; $hostCfg = null;
try {
    $oldInit = (string) $server->query('SELECT @@GLOBAL.init_connect')->fetchColumn();
    $server->exec("DROP USER IF EXISTS '$hostUser'@'%'"); $server->exec("DROP USER IF EXISTS '$hostUser'@'localhost'");
    foreach (['%', 'localhost'] as $h) { $server->exec("CREATE USER '$hostUser'@'$h' IDENTIFIED BY '$hostPw'"); $server->exec("GRANT ALL ON `$name`.* TO '$hostUser'@'$h'"); }
    $server->exec("SET GLOBAL init_connect = 'SET collation_connection = utf8mb4_unicode_ci'");
    $hostCfg = ['db_user' => $hostUser, 'db_pass' => $hostPw, 'db_name' => $name] + $cfg;
} catch (Throwable $e) { $skip++; echo "SKIP Hostinger-like server (needs a database user that may create users): " . $e->getMessage() . "\n"; }
$restore = function () use ($server, $oldInit, $hostUser) {
    if ($oldInit !== null) { $q = $server->quote($oldInit); $server->exec("SET GLOBAL init_connect = $q"); }
    try { $server->exec("DROP USER IF EXISTS '$hostUser'@'%'"); $server->exec("DROP USER IF EXISTS '$hostUser'@'localhost'"); } catch (Throwable $e) {}
};
register_shutdown_function($restore);

if ($hostCfg) {
    test('the Hostinger-like server really forces another rule (the test reproduces the problem)', function () use ($hostCfg, $dsn) {
        $raw = new PDO(str_replace(';charset=utf8mb4', ';dbname=' . $hostCfg['db_name'] . ';charset=utf8mb4', $dsn), $hostCfg['db_user'], $hostCfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        ok((string) $raw->query('SELECT @@collation_connection')->fetchColumn() === 'utf8mb4_unicode_ci', 'init_connect did not apply');
        try { $s = $raw->prepare("SELECT ? = 'STUDENT'"); $s->execute(['STAFF']); throw new LogicException('no error without the fix: this server does not reproduce Hostinger'); }
        catch (PDOException $e) { ok(str_contains($e->getMessage(), '1267'), 'unexpected error: ' . $e->getMessage()); }
    });
}
Db::connect($hostCfg ?? (['db_name' => $name] + $cfg));

test('a connection compares sent values with the table rule (no error 1267)', function () {
    ok((string) Db::val('SELECT @@collation_connection') === 'utf8mb4_unicode_ci', 'collation_connection is ' . Db::val('SELECT @@collation_connection'));
    ok((int) Db::val("SELECT ? = 'STUDENT'", ['STUDENT']) === 1, 'parameter = literal');
    ok((string) Db::val('SELECT label FROM zz_old_rule WHERE code = ?', ['student']) === 'Student', 'parameter = column of another rule');
});
test('the upgrade puts the database and every table on one rule', function () use ($name) {
    sis_upgrade();
    $c = Auth::central(); $c->install(); $pdo = Db::pdo();
    AccountEmail::install(); Canteen::install($pdo); Library::install($pdo); Lms::install($pdo); Notify::install($pdo); StudentPhoto::install($pdo); Privacy::install($pdo); ParentAccess::install($pdo);
    $again = Db::alignCollation(); // tables the module installs just made, if any
    ok((string) Db::val('SELECT default_collation_name FROM information_schema.schemata WHERE schema_name = DATABASE()') === 'utf8mb4_unicode_ci', 'database default not converted');
    $bad = Db::col("SELECT CONCAT(table_name, ' ', table_collation) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_collation NOT IN ('utf8mb4_unicode_ci', 'utf8mb4_bin')");
    ok(!$bad, 'tables on another rule: ' . implode(', ', $bad));
    $badCols = Db::col("SELECT CONCAT(table_name, '.', column_name, ' ', collation_name) FROM information_schema.columns WHERE table_schema = DATABASE() AND collation_name LIKE 'utf8mb4\\_%' AND collation_name NOT IN ('utf8mb4_unicode_ci', 'utf8mb4_bin')");
    ok(!$badCols, 'columns on another rule: ' . implode(', ', $badCols));
    ok(!$again['skipped'], 'could not convert: ' . json_encode($again['skipped']));
    ok((string) Db::val("SELECT label FROM zz_old_rule WHERE code = 'STUDENT'") === 'Student', 'data kept');
});
test('on that server: setup password, sign in, permissions and an account of every group', function () {
    $c = Auth::central(); $c->clearCache();
    $owner = (int) $c->q("SELECT account_id FROM central_login_identifiers WHERE identifier_key = 'power.admin'")->fetchColumn();
    $pw = 'Collation2026x';
    $c->setPassword($owner, $pw, false, $owner); $c->q('UPDATE central_accounts SET must_change_password = 0 WHERE id = ?', [$owner]);
    AccountEmail::set($owner, 'power.admin@example.test', $owner);
    $c->login('power.admin', $pw, 'SIS'); $c->logout();
    ok(count($c->permissions($owner)) > 10, 'power.admin has no permissions (the Hostinger error was here)');
    foreach (['REGISTRAR', 'CASHIER', 'CANTEEN_SUPERVISOR', 'LIBRARIAN', 'STUDENT', 'PARENT'] /* a TEACHER needs a teacher record */ as $g) {
        if (!Db::val('SELECT 1 FROM central_groups WHERE code = ?', [$g])) continue;
        $u = 'coll.' . strtolower($g);
        $id = $c->save($owner, ['full_name' => "Coll $g", 'username' => $u, 'group' => $g, 'status' => 'ACTIVE', 'temp_password' => $pw]);
        $c->clearCache(); $c->permissions($id); $c->group($id);
    }
    ok((int) Db::val("SELECT COUNT(*) FROM central_login_identifiers WHERE identifier_key LIKE 'coll.%'") >= 5, 'accounts not created');
});

$restore();
$server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail ? 1 : 0);
