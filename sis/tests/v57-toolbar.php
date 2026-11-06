<?php
/** php tests/v57-toolbar.php — v57 standard toolbar: lists open already applied with default filters.
 *  Uses a disposable sis_v57_test database only. Browser behaviour is checked separately (see V57_UPDATE.md). */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/layout.php';
$cfg = sis_config(); $live = $cfg['db_name'];
$name = getenv('SIS_V57_TEST_DB') ?: 'sis_v57_test';
if ($name === $live || !preg_match('/^sis_[a-z0-9_]*test$/', $name)) exit("Refusing an unsafe test database name.\n");
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']), $cfg['db_user'], $cfg['db_pass']);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cfg['db_name'] = $name; Db::connect($cfg);
foreach ([dirname(SIS_ROOT) . '/api/install.sql', SIS_ROOT . '/database/schema.sql', SIS_ROOT . '/database/seed.sql'] as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) Db::q($statement);
}
$failures = 0; $passed = 0;
function expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function scenario(string $label, callable $fn): void { global $failures, $passed; try { $fn(); $passed++; echo "PASS $label\n"; } catch (Throwable $e) { $failures++; echo "FAIL $label: " . $e->getMessage() . "\n"; } }
function open_list(array $get): array { $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = $get; RecordList::openDefaults(); return $_GET; }
$_SESSION = [];
$today = date('Y-m-d');

scenario('a list opens applied (no empty "click Apply" state)', function () {
    $g = open_list(['r' => 'teachers']);
    expect(($g['apply'] ?? '') === '1' && RecordList::applied(), 'teachers did not open applied');
});
scenario('school-year lists open at the top-bar school year', function () {
    foreach (['admission', 'registration', 'enrollment', 'students'] as $r) {
        $g = open_list(['r' => $r]);
        expect(($g['sy'] ?? '') === selected_sy(), "$r did not default to " . selected_sy());
    }
    expect(!isset(open_list(['r' => 'bulkaccounts'])['sy']), 'teacher accounts must not get a school year');
    expect((open_list(['r' => 'bulkaccounts', 'group' => 'STUDENT'])['sy'] ?? '') === selected_sy(), 'student accounts default to the school year');
});
scenario('date filters open at today (Cashier, Accounting ledger)', function () use ($today) {
    $c = open_list(['r' => 'cashier']); expect($c['from'] === $today && $c['to'] === $today, 'cashier dates');
    $a = open_list(['r' => 'accounting', 'tab' => 'ledger']); expect($a['from'] === $today && $a['to'] === $today, 'ledger dates');
});
scenario('filters given in the link are kept; only missing ones are filled', function () {
    $g = open_list(['r' => 'students', 'section' => 'S1']);
    expect($g['section'] === 'S1' && $g['sy'] === selected_sy() && $g['apply'] === '1', 'link filters lost');
});
scenario('filters applied earlier in the session come back instead of the defaults', function () {
    open_list(['r' => 'admission', 'sy' => '2020-2021', 'status' => 'ADMITTED', 'apply' => '1']); RecordList::remember();
    $g = open_list(['r' => 'admission']);
    expect(($g['sy'] ?? '') === '2020-2021' && ($g['status'] ?? '') === 'ADMITTED', 'saved filters not restored');
});
scenario('Clear Filters (clear=1) forgets them and returns to the defaults', function () {
    $g = open_list(['r' => 'admission', 'clear' => '1']);
    expect(($g['sy'] ?? '') === selected_sy() && !isset($g['status']) && !isset($g['clear']), 'clear did not reset');
    $g = open_list(['r' => 'admission']);
    expect(!isset($g['status']), 'cleared filters came back');
});
scenario('pages that are not lists, single records and POSTs are untouched', function () {
    expect(!isset(open_list(['r' => 'dashboard'])['apply']), 'dashboard changed');
    expect(!isset(open_list(['r' => 'registration', 'id' => '5'])['apply']), 'single record changed');
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = ['r' => 'teachers']; RecordList::openDefaults();
    expect(!isset($_GET['apply']), 'POST changed');
});
scenario('the clear link carries clear=1', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['r' => 'teachers', 'apply' => '1'];
    ob_start(); (new ReflectionProperty(RecordList::class, 'rendered'))->setValue(null, false); RecordList::panel(); $html = ob_get_clean();
    expect(str_contains($html, 'clear=1') && str_contains($html, 'data-list-clear'), 'clear link missing clear=1');
});
scenario('every staff page loads the standard toolbar', function () {
    $layout = file_get_contents(SIS_ROOT . '/app/views/layout.php');
    expect(str_contains($layout, '../js/standard-toolbar-core.js') && str_contains($layout, 'assets/standard-toolbar.js') && str_contains($layout, '../css/standard-toolbar.css'), 'layout does not load the toolbar');
    foreach (['students', 'teachers', 'subjects', 'sections', 'curriculum', 'assignments'] as $p) {
        $html = file_get_contents(dirname(SIS_ROOT) . "/pages/$p.html");
        expect(str_contains($html, 'standard-toolbar-core.js') && str_contains($html, 'record-toolbar.js'), "Grading $p.html does not load the toolbar");
    }
});

$server->exec("DROP DATABASE IF EXISTS `$name`");
echo "\n$passed passed, $failures failed\n";
exit($failures ? 1 : 0);
