<?php
/**
 * php sis/tests/v63-fixes.php — v63.24 fixes found while filling the live test site:
 *  - a "saved" message shows only on the page the save went to (not in another tab of the same account);
 *  - a fee template that charges tuition per unit AND the subjects' tuition prices is flagged (students would pay twice);
 *  - list wording (Subjects tab counter, one Actions column on Teachers) and "Nothing overdue" on the payment plan.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
$cfg = sis_config(); $name = 'sis_v6324_test'; $root = dirname(SIS_ROOT);
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $server->exec("USE `$name`");
$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents("$root/INSTALL.sql"));
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $stmt) { $s = $server->query($stmt); while ($s->nextRowset()) {} $s->closeCursor(); }
$cfg['db_name'] = $name; Db::connect($cfg); sis_upgrade();
$pass = 0; $fail = 0;
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; } }

test('a message waits for the page the save went to (another tab does not take it)', function () {
    $_SESSION = [];
    flash('success', 'Teacher saved.');
    foreach ($_SESSION['flash'] as &$f) if (!isset($f['to'])) $f['to'] = app_system() . '|teachers'; unset($f); // what redirect('teachers') does
    $GLOBALS['route'] = 'sections'; ok(take_flashes() === [], 'the Sections page took the Teachers message');
    $GLOBALS['route'] = 'teachers'; ok(take_flashes() === [['success', 'Teacher saved.']], 'the Teachers page did not get its message');
    $GLOBALS['route'] = 'teachers'; ok(take_flashes() === [], 'shown twice');
    flash('info', 'No page named.'); $GLOBALS['route'] = 'dashboard'; ok(take_flashes() === [['info', 'No page named.']], 'a message without a page shows on the next page');
    $_SESSION['flash'] = [['warning', 'Old', 'at' => time() - 600, 'to' => app_system() . '|students']]; $GLOBALS['route'] = 'dashboard';
    take_flashes(); ok(empty($_SESSION['flash']), 'an unseen message older than two minutes is kept');
    $src = (string) file_get_contents(SIS_ROOT . '/app/lib/core.php');
    ok(str_contains($src, "\$f['to'] = app_system() . '|' . \$route"), 'redirect() does not address messages');
    unset($GLOBALS['route']); $_SESSION = [];
});
test('fee template charging tuition per unit and the subjects\' tuition prices is flagged', function () {
    $cat = (int) Db::val("SELECT id FROM sis_fee_categories WHERE name = 'Tuition'"); ok($cat > 0, 'Tuition category');
    Db::q("INSERT INTO sis_fee_labels (category_id, name, charge_type, default_amount, is_active) VALUES (?, 'Tuition Fee', 'PER_UNIT', 450, 1)", [$cat]); $lab = (int) Db::pdo()->lastInsertId();
    $misc = (int) Db::val("SELECT id FROM sis_fee_categories WHERE name = 'Miscellaneous'");
    Db::q("INSERT INTO sis_fee_labels (category_id, name, charge_type, default_amount, is_active) VALUES (?, 'Library Fee', 'FIXED', 300, 1)", [$misc]); $libLab = (int) Db::pdo()->lastInsertId();
    Db::q("INSERT INTO sis_assessment_names (name, program, year_level, term, school_year, is_active) VALUES ('BSIT 1st Year 1st Semester', 'BSIT', '1st Year', '1st Semester', '2026-2027', 1)"); $tpl = (int) Db::pdo()->lastInsertId();
    Db::q("INSERT INTO sis_assessment_name_items (assessment_name_id, label_id, charge_type, amount) VALUES (?, ?, 'FIXED', 300)", [$tpl, $libLab]);
    ok(TemplateFees::doubleCharge($tpl) === null, 'flagged with only a fixed fee');
    Db::q("INSERT INTO sis_assessment_name_items (assessment_name_id, label_id, charge_type, amount) VALUES (?, ?, 'PER_UNIT', 450)", [$tpl, $lab]);
    ok(TemplateFees::doubleCharge($tpl) === null, 'flagged with the per-unit fee alone');
    Db::q("INSERT INTO sis_subject_fees (subject_doc_id, assessment_name_id, label_id, rate) VALUES ('subj1', NULL, ?, 450)", [$lab]); $sf = (int) Db::pdo()->lastInsertId();
    ok(TemplateFees::doubleCharge($tpl) === null, 'flagged although the template does not charge the subject price');
    Db::q('INSERT INTO sis_assessment_name_subject_fees (assessment_name_id, subject_fee_id) VALUES (?, ?)', [$tpl, $sf]);
    $d = TemplateFees::doubleCharge($tpl); ok($d !== null && $d['category'] === 'Tuition' && $d['general'] === ['Tuition Fee'], 'double tuition not flagged');
    Db::q("DELETE FROM sis_assessment_name_items WHERE assessment_name_id = ? AND label_id = ?", [$tpl, $lab]);
    ok(TemplateFees::doubleCharge($tpl) === null, 'still flagged after unticking the per-unit fee');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/app/views/assessment-fee-templates.php'), 'TemplateFees::doubleCharge('), 'the template page does not show the warning');
});
test('wording: Subjects counter, one Actions column on Teachers, "Nothing overdue"', function () use ($root) {
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/assets/theme.js'), "curriculum:tab==='subjects'?'subject':'curriculum'"), 'Subjects tab still counts curriculums');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/app/pages/teachers.php'), 'data-theme-row-icons="own"'), 'Teachers gets a second Actions column');
    $v = (string) file_get_contents(SIS_ROOT . '/app/views/payment-plan-summary.php'); ok(str_contains($v, 'Nothing overdue') && !str_contains($v, '· Overdue: ₱<?= Money::fmt'), 'Overdue: ₱0.00 still shown');
});

test('v64.6: recent activity never shows an internal record id, and names books and LMS work', function () {
    $row = fn(string $type, $id, array $d, string $action = 'x_done') => Activity::describe(['created_at' => '2026-11-15 10:00:00', 'action' => $action, 'details' => json_encode($d), 'entity_type' => $type, 'entity_id' => $id, 'username' => 'u', 'role' => '']);
    $a = $row('lms_class', 'cur_8166e0d1fbe942b2d7ca61bde8fa3096', ['message' => 8], 'lms_message_sent');
    ok(!str_contains($a['name'] . $a['detail'], 'cur_') && !str_contains($a['name'] . $a['detail'], '#'), 'internal id shown: ' . $a['name'] . ' / ' . $a['detail']);
    ok($a['action'] === 'LMS message sent', 'action wording: ' . $a['action']);
    $b = $row('library_title', 999, ['title' => 'Noli Me Tangere']); ok($b['name'] === 'Noli Me Tangere', 'book title missing');
    $c = $row('mystery_thing', 'abc123', []); ok($c['detail'] === 'Mystery thing' && !str_contains($c['detail'], 'abc123'), 'fallback shows the id: ' . $c['detail']);
    $t = $row('grading_assignment', 'x', ['subject' => 'WEB101', 'teacher' => 'Tomas Teacher', 'section' => 'BSIT 2A'], 'teacher_assigned');
    ok($t['name'] === 'Tomas Teacher' && str_contains($t['detail'], 'WEB101'), 'teacher assignment unreadable');
});

test('v64.6: dashboard numbers are kept a minute and dropped when this person saves', function () {
    ok(ShortCache::get('k', 60, fn() => 1) === 1, 'CLI: loads directly');
    $src = (string) file_get_contents(SIS_ROOT . '/app/pages/dashboard.php');
    ok(substr_count($src, '$keep(') >= 7 && str_contains((string) file_get_contents(dirname(SIS_ROOT) . '/finance/pages/overview.php'), 'ShortCache::get'), 'dashboards not cached');
    ok(!str_contains((string) file_get_contents(SIS_ROOT . '/app/pages/reports.php'), 'ShortCache'), 'reports must stay live');
});

test('v64.6: Curriculum does not write every teacher into every subject (froze the browser at 1,000 × 1,000)', function () {
    $page = (string) file_get_contents(SIS_ROOT . '/app/pages/curriculum.php');
    ok(str_contains($page, 'data-teacher-pick') && str_contains($page, '<template id="teacherOptions">'), 'shared teacher list missing');
    ok(substr_count($page, 'foreach ($teachers as $t)') === 2, 'a teacher loop is back inside the subject rows');
    $js = (string) file_get_contents(dirname(SIS_ROOT) . '/js/curriculum.js');
    ok(str_contains($js, 'shortTeacherOption(s.id)') && !preg_match('/renderSubjects[\s\S]{0,1500}teachers\.filter\([^)]*\)\.map/', $js), 'Grading curriculum still lists every teacher per subject');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/assets/curriculum-picker.js'), 's.disabled=true'), 'unticked subjects would still be sent (input limit)');
});

test('v64.6: "Inspect" is restricted on every kind of page (SIS family, Portal, Grading System)', function () {
    $root = dirname(SIS_ROOT);
    foreach (['sis/app/views/layout.php', 'portal/views/layout.php', 'index.html'] as $f) ok(str_contains((string) file_get_contents("$root/$f"), 'no-inspect.js'), "$f does not load it");
    foreach (glob("$root/pages/*.html") as $f) ok(str_contains((string) file_get_contents($f), 'no-inspect.js'), basename($f) . ' does not load it');
    $js = (string) file_get_contents("$root/js/no-inspect.js");
    ok(str_contains($js, "'F12'") && str_contains($js, 'contextmenu') && str_contains($js, 'input, textarea'), 'shortcuts or the text-box exception missing');
});

test('v64.8: no "Actions" side panel; its actions are in More Actions; LMS, Library and Canteen are left alone', function () {
    $root = dirname(SIS_ROOT);
    $css = (string) file_get_contents(SIS_ROOT . '/assets/sis-actions.css');
    ok(str_contains($css, '.sis-actions-toggle, .sis-actions-drawer { display:none!important; }') && str_contains($css, '.sis-actions-panel { display:none!important; }'), 'panel or button still shown');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/assets/sis-actions.js'), "['sis','finance'].includes(document.documentElement.dataset.app)"), 'panel would run in LMS / Library / Canteen');
    $tb = (string) file_get_contents(SIS_ROOT . '/assets/standard-toolbar.js');
    ok(str_contains($tb, "document.querySelector('.sis-actions-panel')") && str_contains($tb, '{header:true,text:name}'), 'More Actions does not list the panel groups');
    ok(str_contains((string) file_get_contents("$root/js/standard-toolbar-core.js"), 'std-tb-menu-head'), 'menu has no group headings');
});

test('v64.8: Students row has Create account (Power User), linked to the student; Manage account once linked', function () {
    $p = (string) file_get_contents(SIS_ROOT . '/app/pages/students.php');
    ok(str_contains($p, "\$bulk->preview('STUDENT', [\$docId])") && str_contains($p, "\$bulk->create('STUDENT', \$check"), 'does not use the bulk-account checks');
    ok(str_contains($p, "Auth::central()->group((int) Auth::centralId()) === 'POWER_USER'") && str_contains($p, "throw new Forbidden('Only a Power User can create student accounts.')"), 'not limited to Power Users');
    ok(str_contains($p, 'data-icon="person-plus">Create account') && str_contains($p, 'data-icon="person-gear">Manage account'), 'row items missing');
});

test('v64.9: long lists send one page (15 / 30 / 50 / 100 / All); totals still count every row', function () {
    $saveGet = $_GET; $saveCookie = $_COOKIE;
    $_GET = []; $_COOKIE = []; $w = RecordList::window(1003);
    ok($w === ['offset' => 0, 'size' => 15, 'page' => 1, 'total' => 1003, 'all' => false], 'default window: ' . json_encode($w));
    $_GET = ['pg' => '4']; ok(RecordList::window(1003)['offset'] === 45, 'page 4 offset');
    $_GET = ['pg' => '999']; ok(RecordList::window(1003)['page'] === 67, 'page past the end is the last page');
    $_GET = ['per' => '30', 'pg' => '2']; $w = RecordList::window(100); ok($w['size'] === 30 && $w['offset'] === 30, 'per 30');
    $_GET = ['per' => '7']; ok(RecordList::window(100)['size'] === 15, 'only 15/30/50/100 are allowed');
    $_GET = ['per' => 'all']; $w = RecordList::window(2001); ok($w['all'] && $w['size'] === 2001 && $w['offset'] === 0, 'All');
    $_GET = []; $_COOKIE = ['sis_perpage' => '50']; ok(RecordList::window(500)['size'] === 50, 'remembered rows per page');
    $_GET = ['pg' => '2']; $_COOKIE = []; $rows = range(1, 40); $page = RecordList::slice($rows, $w2); ok($page === range(16, 30) && $w2['total'] === 40, 'slice');
    ok(RecordList::pagesAttr($w2) === ' data-server-pages="1,15,40"', 'table attribute');
    $_GET = ['per' => 'all']; ok(str_contains(RecordList::pagesAttr(RecordList::window(9)), '"0,0,9"'), 'All attribute');
    foreach (['admission.php' => 'RecordList::pagesAttr($win)', 'registration.php' => '$pageRows = RecordList::slice($rows, $win)', 'teachers.php' => '$pageTeachers = RecordList::slice($teachers, $win)', 'subjectfees.php' => "RecordList::pagerHtml(\$win, 'subjectfees'"] as $f => $needle)
        ok(str_contains((string) file_get_contents(SIS_ROOT . '/app/pages/' . $f), $needle), "$f is not paged");
    $_GET = $saveGet; $_COOKIE = $saveCookie;
});

test('v64.10: Admission and Registration filter by date (From–To); Cashier keeps its O.R. date range; quick choices on all three', function () {
    $layout = (string) file_get_contents(SIS_ROOT . '/app/views/layout.php');
    ok(str_contains($layout, 'function date_range_controls(?string $from, ?string $to): string') && substr_count($layout, 'data-date-preset=') === 3, 'date controls');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/assets/app.js'), "b.dataset.datePreset === 'month'"), 'quick choices do nothing');
    $adm = (string) file_get_contents(SIS_ROOT . '/app/pages/admission.php');
    ok(str_contains($adm, "fp_row('Date Applied', date_range_controls(\$dFrom, \$dTo)") && str_contains($adm, 'a.created_at >= ? AND a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'), 'Admission date filter');
    $reg = (string) file_get_contents(SIS_ROOT . '/app/pages/registration.php');
    ok(str_contains($reg, "fp_row('Date Registered', date_range_controls(\$dFrom, \$dTo)") && str_contains($reg, 'r.created_at >= ? AND r.created_at < DATE_ADD(?, INTERVAL 1 DAY)'), 'Registration date filter');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/app/pages/cashier.php'), "fp_row('O.R. Date Range', date_range_controls(\$from, \$to), true, true)"), 'Cashier date range');
});

test('v64.11: a list reopens on page 1 (filters are remembered, the page number is not)', function () {
    $save = [$_GET, $_SESSION['record_lists'] ?? null, $_SERVER['REQUEST_METHOD'] ?? null];
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['r' => 'teachers', 'apply' => '1', 'q' => 'Bulk', 'pg' => '5', 'per' => 'all'];
    RecordList::remember(); $kept = $_SESSION['record_lists'][RecordList::key()] ?? [];
    ok(($kept['q'] ?? '') === 'Bulk' && !isset($kept['pg']) && !isset($kept['per']), 'remembered: ' . json_encode($kept));
    [$_GET, $_SESSION['record_lists'], $_SERVER['REQUEST_METHOD']] = $save;
});

test('v65: a busy database (host connection limit) is waited for; only settings errors fail at once', function () {
    $e = fn(string $m) => new PDOException($m);
    ok(Db::connectErrorCode($e("SQLSTATE[HY000] [1226] User 'u' has exceeded the 'max_user_connections' resource")) === 1226, 'error number');
    foreach (['[1226] max_user_connections', '[1040] Too many connections', '[1203] max_user_connections', '[2002] Resource temporarily unavailable', '[2006] MySQL server has gone away', 'no number at all'] as $m)
        ok(Db::connectRetryable($e('SQLSTATE[HY000] ' . $m)), "should wait and retry: $m");
    foreach (['[1045] Access denied', '[1049] Unknown database', '[1044] Access denied for user to database'] as $m)
        ok(!Db::connectRetryable($e('SQLSTATE[HY000] ' . $m)), "settings error must fail at once: $m");
    ok(str_contains((string) file_get_contents(dirname(SIS_ROOT) . '/api/lib.php'), 'microtime(true) - $started <= 6.0'), 'the Grading System API does not wait either');
});

$server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
