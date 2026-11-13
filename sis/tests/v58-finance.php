<?php
/**
 * php sis/tests/v58-finance.php — Finance as its own workspace: access, the Cashier group, links between the
 * SIS and Finance folders, and collections by source. Disposable database only; never the configured one.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
require_once FINANCE_ROOT . '/lib/overview.php';
$cfg = sis_config(); $name = 'sis_v58_finance_test';
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cfg['db_name'] = $name; Db::connect($cfg);
foreach ([dirname(SIS_ROOT) . '/api/install.sql', SIS_ROOT . '/database/schema.sql', SIS_ROOT . '/database/seed.sql', SIS_ROOT . '/database/seed_accounting.sql'] as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) Db::q($stmt);
}
require __DIR__ . '/_legacy-payments.php';
$c = Auth::central(); $c->install(); DocumentRequests::install(Db::pdo());
$pass = 0; $fail = 0;
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . "\n"; } }
function account(string $user, string $group, ?int $actor = null): int {
    $c = Auth::central(); $id = $c->save($actor, ['full_name' => ucfirst($user) . ' Test', 'username' => $user, 'group' => $group, 'status' => 'ACTIVE', 'temp_password' => 'Temporary12345'], $actor === null);
    $c->q('UPDATE central_accounts SET must_change_password=0 WHERE id=?', [$id]); $c->clearCache(); return $id;
}
$power = account('fin.power', 'POWER_USER'); $acct = account('fin.accountant', 'ACCOUNTING', $power); $cash = account('fin.cashier', 'CASHIER', $power); $reg = account('fin.registrar', 'REGISTRAR', $power);
$sys = fn(int $id) => ModuleAccess::systems(($c->clearCache() ?? $c)->permissions($id));

test('the chooser lists workspaces in the agreed order', function () use ($sys, $power) {
    $s = $sys($power);
    $order = array_values(array_intersect(['GRADING', 'SIS', 'LMS', 'CANTEEN', 'LIBRARY', 'FINANCE'], $s));
    ok($order === ['GRADING', 'SIS', 'LMS', 'CANTEEN', 'LIBRARY', 'FINANCE'], 'Power User order: ' . implode(',', $s));
    ok(array_values($s) === array_values(array_filter(ModuleAccess::CHOOSER_ORDER, fn($x) => in_array($x, $s, true))), 'not in chooser order');
});
test('Cashier group: Cashier in the SIS and in Finance, no accounting books', function () use ($c, $sys, $cash) {
    $p = $c->permissions($cash);
    ok(in_array('FINANCE', $sys($cash), true), 'cashier cannot open Finance');
    ok(in_array('SIS', $sys($cash), true), 'cashier cannot open the SIS (v58.1: Cashier stays in the SIS)');
    foreach (['sis.receipts.view', 'sis.payments.receive', 'sis.balances.view'] as $code) ok(isset($p[$code]), "cashier lacks $code");
    foreach (['sis.accounting.view', 'sis.journals.prepare', 'sis.journals.post', 'sis.payments.void'] as $code) ok(!isset($p[$code]), "cashier has $code");
    ok(Db::val("SELECT role FROM sis_users WHERE id=?", [(int) $c->legacyId($cash, 'SIS')]) === 'CASHIER', 'cashier SIS role is not CASHIER');
    $c->establish($cash); Auth::actAs(null);
    ok(Auth::role() === 'CASHIER' && Auth::user()['role_name'] === 'Cashier', 'signed-in cashier is treated as ' . Auth::role());
    $c->logout();
});
test('Accounting keeps the books and opens Finance', function () use ($c, $sys, $acct) {
    ok(in_array('FINANCE', $sys($acct), true), 'accountant cannot open Finance');
    ok(isset($c->permissions($acct)['sis.journals.post']), 'accountant lost posting');
});
test('nobody loses access: a registrar keeps what they had and SIS stays open', function () use ($c, $sys, $reg) {
    ok(in_array('SIS', $sys($reg), true), 'registrar lost SIS');
    $before = array_keys($c->permissions($reg)); $c->install(); $c->clearCache();
    ok(array_keys($c->permissions($reg)) === $before, 'repeat install changed registrar permissions');
});
test('the v58 upgrade runs once and is repeatable', function () use ($c) {
    $c->install(); $c->install();
    ok((int) Db::val("SELECT COUNT(*) FROM central_groups WHERE code='CASHIER'") === 1, 'duplicate Cashier group');
    ok((int) Db::val("SELECT COUNT(*) FROM central_schema_migrations WHERE migration_key='finance_v58'") === 1, 'migration not recorded once');
    ok(Db::val("SELECT workspace FROM central_permissions WHERE code='sis.journals.post'") === 'FINANCE', 'accounting permission not labeled Finance');
    $type = (string) Db::val("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='central_permissions' AND column_name='workspace'");
    foreach (["'FINANCE'", "'CANTEEN'", "'LIBRARY'", "'LMS'"] as $w) ok(str_contains($type, $w), "workspace enum lacks $w");
});
test('removing a person\'s money modules closes Finance for them (and it is audited)', function () use ($c, $sys, $power, $acct) {
    $c->setUserModules($power, $acct, ['sis.accounting' => ['NONE', []], 'sis.cashier' => ['NONE', []], 'sis.balances' => ['NONE', []]]); $c->clearCache();
    ok(!in_array('FINANCE', $sys($acct), true), 'Finance still open');
    ok((int) Db::val("SELECT COUNT(*) FROM central_account_audit WHERE action='user_modules_updated' AND target_account_id=?", [$acct]) >= 1, 'change not audited');
    $c->resetUserModules($power, $acct); $c->clearCache();
    ok(in_array('FINANCE', $sys($acct), true), 'reset did not restore Finance');
});
test('a group\'s Finance access can be changed in Users and Groups (e.g. let Cashiers void)', function () use ($c, $power, $cash) {
    $c->setGroupModules($power, 'CASHIER', ['sis.cashier' => ['EDIT', ['sis.payments.void']]]); $c->clearCache();
    ok(isset($c->permissions($cash)['sis.payments.void']), 'void not granted to the Cashier group');
    $c->setGroupModules($power, 'CASHIER', ['sis.cashier' => ['EDIT', []]]); $c->clearCache();
    ok(!isset($c->permissions($cash)['sis.payments.void']), 'void not removed again');
});
test('Canteen POS and Library start with the Power User only; only LMS is "coming soon" (v59/v60: Canteen POS and Library are open)', function () use ($sys, $acct, $cash, $reg) {
    foreach ([$acct, $cash, $reg] as $id) ok(!array_intersect(['CANTEEN', 'LIBRARY'], $sys($id)), 'staff got a planned workspace');
    ok(ModuleAccess::COMING_SOON === ['LMS'], 'coming-soon list');
});
test('links: from the SIS, Finance pages go to ../finance/ and SIS pages stay', function () {
    ok(url('cashier', ['new' => 1]) === 'index.php?r=cashier&new=1', 'v58.1: Cashier stays in the SIS: ' . url('cashier'));
    ok(url('receipt', ['id' => 3]) === 'index.php?r=receipt&id=3', url('receipt'));
    ok(url('balance') === '../finance/index.php?r=balance', url('balance'));
    ok(url('accounting', ['tab' => 'ledger']) === '../finance/index.php?r=accounting&tab=ledger', url('accounting'));
    ok(url('registration') === 'index.php?r=registration', url('registration'));
    ok(url('my') === '../portal/index.php?r=my', url('my'));
    ok(app_system() === 'SIS' && sis_web() === '', 'SIS context');
});
test('Finance holds Balances and Accounting; Cashier pages stay in the SIS folder', function () {
    foreach (['cashier', 'docfees', 'receipt', 'docreceipt', 'orseries'] as $f) ok(is_file(SIS_ROOT . "/app/pages/$f.php"), "missing sis/app/pages/$f.php");
    foreach (['index.php', 'pages/balance.php', 'pages/overview.php', 'modules/accounting/pages/accounting.php', 'modules/accounting/lib/accounting.php', 'assets/accounting-workflow.js'] as $f)
        ok(is_file(FINANCE_ROOT . '/' . $f), "missing finance/$f");
    foreach (['balance', 'accounting'] as $f) ok(!is_file(SIS_ROOT . "/app/pages/$f.php"), "sis/app/pages/$f.php still there");
    ok(!is_dir(SIS_ROOT . '/modules/accounting'), 'sis/modules/accounting still there');
});
test('collections by source: posted receipts count, voided do not; canteen and library listed', function () use ($c, $power) {
    Auth::actAs((int) $c->legacyId($power, 'SIS'));
    $sec = GS::saveSection(null, '2026-2027', '1st Year', 'FIN 1A'); $sub = GS::saveSubject(null, 'FIN101', 'Finance subject', '3');
    $cat = Assessment::saveCategory(null, ['name' => 'Fin tuition', 'is_active' => 1]); $label = Assessment::saveLabel(null, ['name' => 'Fin fee', 'category_id' => $cat, 'charge_type' => 'FIXED', 'default_amount' => '1000', 'is_active' => 1]);
    $fee = Assessment::saveName(null, ['name' => 'Fin assessment', 'is_active' => 1]); Assessment::setItem($fee, $label, 'FIXED', 100000);
    GS::saveStudent(null, ['studentId' => 'FIN001', 'studentName' => 'Cruz, Ana', 'sectionId' => $sec]);
    [$rid] = Registration::create(null, 'FIN001', $sec, '1st Semester', $fee, [$sub]);
    Cashier::pay($rid, 30000, 'CASH', null, bin2hex(random_bytes(16)), null);
    $void = Cashier::pay($rid, 5000, 'CASH', null, bin2hex(random_bytes(16)), null);
    Db::q("UPDATE sis_payments SET status='VOIDED' WHERE id=?", [is_array($void) ? (int) ($void['id'] ?? $void[0]) : (int) $void]);
    $today = date('Y-m-d');
    $r = FinanceOverview::collections($today, $today); $rows = array_column($r['rows'], null, 'key');
    ok($rows['TUITION']['cents'] === 30000 && $rows['TUITION']['count'] === 1, 'tuition total ' . $rows['TUITION']['cents']);
    ok($rows['DOCUMENTS']['ready'] && $rows['DOCUMENTS']['cents'] === 0, 'documents');
    ok($rows['CANTEEN']['ready'] && $rows['CANTEEN']['cents'] === 0 && $rows['LIBRARY']['ready'] && $rows['LIBRARY']['cents'] === 0, 'v59/v60: canteen and library count (none yet)');
    ok($r['total'] === 30000, 'total');
    ok(FinanceOverview::collections('2000-01-01', '2000-01-31')['total'] === 0, 'date range ignored');
    ok(FinanceOverview::range('bad', '2026-13-40') === [date('Y-m-01'), date('Y-m-d')], 'bad dates not defaulted');
});
$server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
