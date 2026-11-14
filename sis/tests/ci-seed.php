<?php
/**
 * v63.18 Builds a throwaway AcadLink for the browser checks (sis/tests/browser-smoke.mjs): INSTALL.sql, every module,
 * the sample data, and one account per role. Runs ONLY when ACADLINK_CI=1 and the configured database is empty.
 *   ACADLINK_CI=1 php sis/tests/ci-seed.php     prints the accounts as JSON
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
if (getenv('ACADLINK_CI') !== '1') { fwrite(STDERR, "Refusing: set ACADLINK_CI=1 (this fills the configured database with test data).\n"); exit(1); }
require __DIR__ . '/../app/bootstrap.php';
$cfg = sis_config();
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $cfg['db_name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$server->exec('USE `' . str_replace('`', '``', $cfg['db_name']) . '`');
if ((int) $server->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() > 0) {
    fwrite(STDERR, "Refusing: database {$cfg['db_name']} is not empty.\n"); exit(1);
}
$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(SIS_ROOT) . '/INSTALL.sql'));
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $stmt) { $s = $server->query($stmt); while ($s->nextRowset()) {} $s->closeCursor(); }
sis_boot();
sis_upgrade();
$c = Auth::central(); $c->install(); AccountEmail::install(); AccountingWorkflow::install(); Schedules::install(Db::pdo()); EnrollmentDocs::install(); StudentDetails::install();
$pdo = Db::pdo(); Canteen::install($pdo); Library::install($pdo); Lms::install($pdo); Notify::install($pdo); StudentPhoto::install($pdo); Privacy::install($pdo); ParentAccess::install($pdo);
// the sample data works as the legacy SIS administrator "admin" (not in a fresh INSTALL.sql)
Db::q("INSERT IGNORE INTO sis_users (username, full_name, password_hash, role) VALUES ('admin', 'Sample Admin', ?, 'ADMIN')", [password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
require SIS_ROOT . '/bin/sample_data.php';
$sample = 'loaded';
try { sis_sample_data(); } catch (Throwable $e) { $sample = 'partly loaded (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')'; }
Auth::actAs(null);

$pw = 'CiBrowser2026';
$owner = (int) $c->q("SELECT account_id FROM central_login_identifiers WHERE identifier_key = 'power.admin'")->fetchColumn();
$c->setPassword($owner, $pw, false, $owner);
$c->q('UPDATE central_accounts SET must_change_password = 0 WHERE id = ?', [$owner]);
AccountEmail::set($owner, 'power.admin@example.test', $owner); // Power Users need an email (else every page asks for it)
$teacher = (string) Db::val("SELECT id FROM docs WHERE col = 'teachers' AND id <> '_acadlink_professor_x' ORDER BY id LIMIT 1");
$student = (string) Db::val("SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.studentId')) FROM docs WHERE col = 'students' ORDER BY id LIMIT 1");
$accounts = ['power.admin' => 'POWER_USER'];
foreach (['ci.registrar' => ['REGISTRAR', []], 'ci.cashier' => ['CASHIER', []], 'ci.canteen' => ['CANTEEN_SUPERVISOR', []], 'ci.librarian' => ['LIBRARIAN', []],
          'ci.teacher' => ['TEACHER', $teacher !== '' ? ['teacher_doc_id' => $teacher] : []], 'ci.student' => ['STUDENT', $student !== '' ? ['student_number' => $student] : []]] as $user => [$group, $extra]) {
    try {
        $id = $c->save($owner, $extra + ['full_name' => ucwords(str_replace('.', ' ', $user)), 'username' => $user, 'group' => $group, 'status' => 'ACTIVE', 'temp_password' => $pw]);
        $c->q('UPDATE central_accounts SET must_change_password = 0 WHERE id = ?', [$id]);
        $accounts[$user] = $group;
    } catch (Throwable $e) { fwrite(STDERR, "Could not create $user ($group): " . $e->getMessage() . "\n"); }
}
$c->clearCache();
echo json_encode(['password' => $pw, 'accounts' => $accounts, 'sample' => $sample, 'student' => $student], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
