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
    ok(str_contains($page, 'data-cur-tpicker') && substr_count($page, 'name="curTeacherPick"') === 1, 'shared teacher list missing (v65.2: one Select teacher window)');
    ok(substr_count($page, 'foreach ($teachers as $t)') === 2, 'a teacher loop is back inside the subject rows');
    $js = (string) file_get_contents(dirname(SIS_ROOT) . '/js/curriculum.js');
    ok(str_contains($js, 'shortTeacherOption(s.id)') && !preg_match('/renderSubjects[\s\S]{0,1500}teachers\.filter\([^)]*\)\.map/', $js), 'Grading curriculum still lists every teacher per subject');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/assets/curriculum-picker.js'), 'inp.disabled=!(b&&b.checked)'), 'unticked subjects would still be sent (input limit)');
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

test('v65: printouts, readable colours, labels and the Students pager', function () {
    $root = dirname(SIS_ROOT);
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/app/views/payment-plan-summary.php'), 'payment-plan-summary d-print-none'), 'on-screen plan box prints above the statement');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/assets/sis-actions.css'), '.sis-skip-link { display:none!important; }'), 'Skip to content prints');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/assets/app.js'), "u.searchParams.set('per', 'all')"), 'Print List prints only the page shown');
    foreach (['sis/assets/blue-theme.css', 'css/style.css', 'css/workspace-selector.css', 'portal/assets/portal.css'] as $f)
        ok(stripos((string) file_get_contents("$root/$f"), '#EF7D24') === false, "$f still has the orange that white text cannot be read on (2.75:1)");
    $h = (string) file_get_contents(SIS_ROOT . '/app/pages/history.php');
    ok(str_contains($h, 'for="hSy"') && str_contains($h, 'for="hYear"') && str_contains($h, 'for="hTerm"'), 'history filters have no labels');
    ok(str_contains((string) file_get_contents("$root/js/standard-toolbar-core.js"), "more.setAttribute('aria-label', 'More actions')"), 'More actions has no name for screen readers');
    $st = (string) file_get_contents(SIS_ROOT . '/app/pages/students.php');
    ok(str_contains($st, '$pageRows = RecordList::slice($rows, $win)') && !str_contains($st, 'array_slice($rows, 0, 500)'), 'Students is not paged');
});

test('v65.1: Cashier, Admission and Registration filters are one plain row with From/To date boxes', function () {
    foreach (['cashier', 'admission', 'registration'] as $pg)
        ok(str_contains((string) file_get_contents(SIS_ROOT . "/app/pages/$pg.php"), 'class="or-filters al-filters d-print-none'), "$pg filters do not use the new row");
    $l = (string) file_get_contents(SIS_ROOT . '/app/views/layout.php');
    ok(str_contains($l, '<label class="al-date-box"><span>From</span>') && str_contains($l, '<label class="al-date-box"><span>To</span>'), 'From/To date boxes');
    $css = (string) file_get_contents(SIS_ROOT . '/assets/blue-theme.css');
    ok(str_contains($css, '.or-filters.al-filters .fp-row:has(.al-date-box) { grid-column: span 2; }'), 'the date range takes two columns');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/app/pages/cashier.php'), "'All cashiers'"), 'Cashier shows All cashiers');
});

test('v65.2: Add / Edit curriculum is its own page with the designed windows', function () {
    $page = (string) file_get_contents(SIS_ROOT . '/app/pages/curriculum.php');
    foreach (['Back to curricula', 'Curriculum details', 'name="curriculumName"', 'data-cur-tab="curPaneSubjects"', 'data-cur-tab="curPaneTeachers"', 'Selected subjects', 'Assign teachers in the Teachers tab.',
              'Select subjects', 'Available subjects', 'Confirm selection', 'Assign teachers', 'Select teacher', 'Teacher ID', 'data-cur-dirty', "['new' => 1]"] as $needle)
        ok(str_contains($page, $needle), "missing: $needle");
    ok(!str_contains($page, 'id="addCurriculum"'), 'the old pop-up form id would be turned into a dialog by friendly-ui.js');
    ok(preg_match('/<dialog class="cur-picker cur-tpicker"[\s\S]*<\/dialog>/', $page) && strpos($page, 'data-cur-tpicker') > strpos($page, '</form>'), 'teacher radio buttons must be outside the saved form');
    $g = (string) file_get_contents(SIS_ROOT . '/app/lib/grading.php');
    ok(str_contains($g, '?string $name=null') && str_contains($g, "\$data['name']=\$name"), 'the curriculum name is not saved');
    $js = (string) file_get_contents(SIS_ROOT . '/assets/curriculum-picker.js');
    ok(str_contains($js, 'pending=new Set(chosen()') && str_contains($js, '[data-cur-confirm-picker]'), 'Select subjects must work on a copy until Confirm selection');
    ok(str_contains($js, "'beforeunload'"), 'leaving with unsaved changes is not warned');
});

test('v65.3: Fee templates list on their own; View opens a template with only its fees', function () {
    $v = (string) file_get_contents(SIS_ROOT . '/app/views/assessment-fee-templates.php');
    ok(!str_contains($v, "\$nid = (int) \$names[0]['id']"), 'the first template still opens by itself');
    ok(str_contains($v, '<?php if (!$n): ?>') && str_contains($v, 'Back to templates'), 'list and template are not separate');
    ok(substr_count($v, '>View</a>') === 1 && str_contains($v, "aria-label=\"<?= h('View ' . \$row['name']) ?>\""), 'rows have no View action for the toolbar');
    ok(!str_contains($v, 'fee-template-preview'), 'preview is back on the template page');
    ok(substr_count($v, 'class="fee-template-add" data-open="tplManage"') === 2, 'Add fee / Add subject fee missing');
    ok(str_contains($v, 'id="tplCopy"') && str_contains($v, 'name="new_name"'), 'Copy template lost');
    ok(substr_count($v, 'data-plain-table') === 2 && str_contains((string) file_get_contents(SIS_ROOT . '/assets/theme.js'), "!t.matches('[data-plain-table]')"), 'fee tables would become selectable lists');
});

test('v65.4: Subject pricing page sets several subject prices at once', function () {
    $pg = (string) file_get_contents(SIS_ROOT . '/app/pages/subjectfees.php');
    ok(str_contains($pg, "\$action === 'bulk'") && str_contains($pg, "\$_POST['p_subject']") && str_contains($pg, 'Db::tx(function () use ($subs, $fees, $rates'), 'bulk save missing or not in one transaction');
    ok(str_contains($pg, "require SIS_ROOT . '/app/views/subject-pricing.php'") && !str_contains($pg, 'id="subjectPrice"'), 'Set a subject price / Edit do not open the pricing page');
    $v = (string) file_get_contents(SIS_ROOT . '/app/views/subject-pricing.php');
    foreach (['Select subjects', 'Price per unit', 'Set as default', 'Charge in fee templates', 'Choose templates', 'Only selected templates will be charged.', 'Selected subjects', 'Total amount', 'Save prices', 'name="templates[]"', 'type="application/json" id="spData"'] as $needle)
        ok(str_contains($v, $needle), "missing: $needle");
    $js = (string) file_get_contents(SIS_ROOT . '/assets/subject-pricing.js');
    ok(str_contains($js, "inp.name='p_rate[]'") && str_contains($js, "h1.name='p_subject[]'") && str_contains($js, "h2.name='p_fee[]'"), 'rows are not posted as flat lists (kept after a refused save)');
    ok(str_contains($js, 'Add at least one subject.') && str_contains($js, "'beforeunload'"), 'empty save or leaving unsaved is not handled');
});

test('v65.5: a class schedule follows the teacher of its class (clashes are left as they were)', function () {
    $pdo = Db::pdo();
    $doc = fn(string $col, string $id, array $d) => Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?)', [$col, $id, json_encode($d)]);
    foreach (['t1' => 'Ana One', 't2' => 'Ben Two', 't3' => 'Cara Three'] as $id => $n) $doc('teachers', "st_$id", ['teacherId' => strtoupper($id), 'teacherName' => $n]);
    $sy = '2031-2032'; $term = '1st Semester';
    $cls = ['teacherDocId' => 'st_t1', 'teacherName' => 'Ana One', 'subjectId' => 'st_sub1', 'subjectCode' => 'ST1', 'schoolYear' => $sy, 'term' => $term, 'sectionId' => 'st_secA', 'sectionName' => 'ST 1A', 'studentIds' => []];
    $doc('gradingAssignments', 'st_cls1', $cls);
    $sched = fn(string $sec, string $sub, ?string $t, string $days, string $a, string $b) => (int) Db::insert('sis_class_schedules', ['school_year' => $sy, 'term' => $term, 'section_doc_id' => $sec, 'subject_doc_id' => $sub, 'teacher_doc_id' => $t, 'days' => $days, 'start_time' => $a, 'end_time' => $b, 'kind' => 'LEC']);
    $s1 = $sched('st_secA', 'st_sub1', 'st_t1', 'M', '08:00:00', '09:00:00');   // Ben is busy then (s3): stays with Ana
    $s2 = $sched('st_secA', 'st_sub1', 'st_t1', 'W', '08:00:00', '09:00:00');   // follows to Ben
    $s3 = $sched('st_secB', 'st_subX', 'st_t2', 'M', '08:30:00', '09:30:00');   // Ben's other class
    $s4 = $sched('st_secA', 'st_sub1', null, 'F', '08:00:00', '09:00:00');      // no teacher yet: follows
    $who = fn(int $id) => Db::val('SELECT teacher_doc_id FROM sis_class_schedules WHERE id = ?', [$id]);
    $svc = new CurriculumAssignments($pdo);
    ScheduleTeacherSync::$moved = ScheduleTeacherSync::$clashed = 0;
    $after = array_replace($cls, ['teacherDocId' => 'st_t2', 'teacherName' => 'Ben Two']);
    Db::tx(fn() => $svc->reassignGrades('st_cls1', $after, $cls));
    ok($who($s2) === 'st_t2' && $who($s4) === 'st_t2', 'schedules did not follow the new teacher');
    ok($who($s1) === 'st_t1' && ScheduleTeacherSync::$clashed === 1, 'a schedule that clashes with the new teacher must stay as it was');
    ok($who($s3) === 'st_t2', 'another section was changed');
    // a later change moves only the entries that still have the previous teacher
    Db::tx(fn() => $svc->reassignGrades('st_cls1', array_replace($after, ['teacherDocId' => 'st_t3']), $after));
    ok($who($s2) === 'st_t3' && $who($s4) === 'st_t3' && $who($s1) === 'st_t1', 'only entries with the previous teacher should move');
    // Professor X (needs teacher) is no teacher in a schedule
    Db::tx(fn() => $svc->reassignGrades('st_cls1', array_replace($after, ['teacherDocId' => '_acadlink_professor_x']), array_replace($after, ['teacherDocId' => 'st_t3'])));
    ok($who($s2) === null, 'Professor X should leave the schedule without a teacher');
    // a new class gives its teacher to schedules that have none, and never replaces a chosen one
    $s5 = $sched('st_secA', 'st_sub2', null, 'T', '10:00:00', '11:00:00'); $s6 = $sched('st_secA', 'st_sub2', 'st_t1', 'TH', '10:00:00', '11:00:00');
    $new = array_replace($cls, ['subjectId' => 'st_sub2', 'teacherDocId' => 'st_t3']);
    $doc('gradingAssignments', 'st_cls2', $new);
    Db::tx(fn() => $svc->reassignGrades('st_cls2', $new, null));
    ok($who($s5) === 'st_t3' && $who($s6) === 'st_t1', 'a new class should fill only empty schedules');
    ok(ScheduleTeacherSync::classTeachers($pdo, 'st_secA', $sy, $term)['st_sub2']['id'] === 'st_t3', 'class teacher lookup');
});

test('v65.6: Auto-schedule places lecture and lab hours where section and teacher are free', function () {
    $doc = fn(string $col, string $id, array $d) => Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?)', [$col, $id, json_encode($d)]);
    $sy = '2033-2034'; $term = '1st Semester';
    $doc('teachers', 'au_t1', ['teacherId' => 'AU1', 'teacherName' => 'Auto Teacher']);
    $doc('subjects', 'au_s1', ['subjectCode' => 'AU101', 'subjectName' => 'Auto Lab', 'units' => 3, 'lecUnits' => 2, 'labUnits' => 1]);
    $doc('subjects', 'au_s2', ['subjectCode' => 'AU102', 'subjectName' => 'Auto Lecture', 'units' => 3]);
    foreach (['au_s1' => 'AU101', 'au_s2' => 'AU102'] as $sid => $code)
        $doc('gradingAssignments', "au_c_$sid", ['teacherDocId' => 'au_t1', 'teacherName' => 'Auto Teacher', 'subjectId' => $sid, 'subjectCode' => $code, 'schoolYear' => $sy, 'term' => $term, 'sectionId' => 'au_sec', 'sectionName' => 'AU 1A', 'studentIds' => []]);
    // the teacher already teaches another section every weekday 7:30–9:30
    Db::insert('sis_class_schedules', ['school_year' => $sy, 'term' => $term, 'section_doc_id' => 'au_other', 'subject_doc_id' => 'au_x', 'teacher_doc_id' => 'au_t1', 'days' => 'M,T,W,Th,F', 'start_time' => '07:30:00', 'end_time' => '09:30:00', 'kind' => 'LEC']);
    $d = ScheduleAuto::draft('au_sec', $sy, $term, ScheduleAuto::options([]));
    ok(!$d['unplaced'] && count($d['subjects']) === 2, 'subjects were left out: ' . implode('; ', $d['unplaced']));
    $hours = []; $min = fn($t) => ScheduleAuto::min($t);
    foreach ($d['rows'] as $r) {
        ok($min($r['start']) >= $min('09:30'), "{$r['code']} {$r['kind']} starts while the teacher is busy ({$r['start']})");
        ok(!($min($r['start']) < 13 * 60 && $min($r['end']) > 12 * 60), "{$r['code']} uses the lunch hour");
        $hours[$r['code'] . $r['kind']] = ($hours[$r['code'] . $r['kind']] ?? 0) + count($r['days']) * ($min($r['end']) - $min($r['start'])) / 60;
    }
    ok(($hours['AU101LAB'] ?? 0) == 3 && ($hours['AU101LEC'] ?? 0) == 2 && ($hours['AU102LEC'] ?? 0) == 3, 'hours: lab 3 (1 lab unit), lecture 2 and 3: ' . json_encode($hours));
    $lab = array_values(array_filter($d['rows'], fn($r) => $r['kind'] === 'LAB'))[0];
    ok(count($lab['days']) === 1, 'a 3-hour lab should be one block');
    // the rows do not overlap each other
    foreach ($d['rows'] as $i => $a) foreach ($d['rows'] as $j => $b) if ($i < $j && array_intersect($a['days'], $b['days']))
        ok($min($a['end']) <= $min($b['start']) || $min($b['end']) <= $min($a['start']), "{$a['code']} and {$b['code']} overlap");
    ok(ScheduleAuto::patterns('LEC', 3, ['M', 'T', 'W', 'Th', 'F'])[0] === [['M', 'W', 'F'], 60] && ScheduleAuto::patterns('LEC', 3, ['M', 'T', 'W', 'Th', 'F'], 1)[0] === [['T', 'Th'], 90], 'MWF 1 hr / TTh 1.5 hrs');
});

test('v65.7: the wide top-bar toolbar is not capped (the search box covered Void on a registration)', function () {
    $css = (string) file_get_contents(SIS_ROOT . '/assets/easy-navigation.css');
    ok(!str_contains($css, 'flex: 0 1 860px; max-width: 860px;'), 'the 860px cap is back');
    ok(str_contains($css, ':is(.std-tb-group-1, .std-tb-group-2) { flex-shrink: 0; }'), 'button groups can shrink under the search box again');
});

test('v65.8: a registration shows its Tools beside the statement, and Class schedule opens', function () {
    $pg = (string) file_get_contents(SIS_ROOT . '/app/pages/registration.php');
    ok(str_contains($pg, '<aside class="or-side tools-first d-print-none" data-keep-side'), 'the Tools panel is not kept on the page');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/assets/sis-actions.js'), ".or-side:not([data-keep-side])"), 'the Tools panel is moved into the hidden Actions panel again');
    ok(str_contains($pg, '$schedReg = Schedules::forRegistration($r') && !str_contains($pg, 'isset($schedReg)'), 'Class schedule has no window to open (schedReg never set)');
});

test('v65.9: SIS menu order and groups', function () {
    $src = (string) file_get_contents(SIS_ROOT . '/app/views/layout.php');
    preg_match('/const NAV = \[(.*?)\n\];/s', $src, $m);
    preg_match_all("/^\\s*\\['([^']*)', '[^']*', '[^']*', \\[[^\\]]*\\], '([^']*)'/m", $m[1] ?? '', $rows, PREG_SET_ORDER);
    $nav = array_map(fn($r) => [$r[1], '', '', [], $r[2]], $rows);
    ok(count($nav) > 20, 'menu not read');
    $groups = []; $routes = [];
    foreach ($nav as $n) { if ($n[4] !== '' && !in_array($n[4], $groups, true)) $groups[] = $n[4]; $routes[$n[0]] = $n[4]; }
    ok($groups === ['Assessment', 'Academics', 'Operations', 'Student Services', 'Administration'], 'group order: ' . implode(', ', $groups));
    ok($nav[0][0] === 'notifications' && array_search('dashboard', array_column($nav, 0)) < 3, 'Notifications first, then Dashboard');
    foreach (['enrollment', 'admission', 'registration', 'cashier'] as $r) ok($routes[$r] === 'Operations', "$r is not in Operations");
    foreach (['students', 'photos', 'privacy', 'documents'] as $r) ok($routes[$r] === 'Student Services', "$r is not in Student Services");
    foreach (['history&tab=grades', 'history&tab=assessments', '@GRADING', '@LMS'] as $r) ok(!isset($routes[$r]), "$r is still in the menu");
});

test('v65.10: the banded menu in every workspace', function () {
    $src = (string) file_get_contents(SIS_ROOT . '/app/views/layout.php');
    ok(str_contains($src, "<?php if (\$r === 'dashboard' && \$g === ''): ?><div class=\"nav-static-head\""), 'Overview band only in some workspaces');
    preg_match('/const FINANCE_NAV = \[\s*\[\'(\w+)\'/', $src, $m); ok(($m[1] ?? '') === 'notifications', 'Finance: Notifications first');
    foreach (['LIBRARY_NAV' => "'Circulation'", 'LMS_NAV' => "'Classes'", 'CANTEEN_NAV' => "'Point of Sale'"] as $nav => $g) {
        preg_match('/const ' . $nav . ' = \[(.*?)\n\];/s', $src, $mm); ok(str_contains($mm[1] ?? '', $g), "$nav has no $g group");
    }
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/assets/theme.js'), "if(document.body.classList.contains('sis-easy')){ // v65.10"), 'groups not always open everywhere');
    foreach (glob(dirname(SIS_ROOT) . '/pages/*.html') as $f) {
        $h = (string) file_get_contents($f); if (!str_contains($h, 'nav-group-label')) continue;
        ok(strpos($h, 'href="notifications.html" data-roles') < strpos($h, '>Overview</div>') && strpos($h, '>Overview</div>') < strpos($h, 'href="dashboard.html" data-roles'), basename($f) . ': Notifications, Overview, Dashboard');
    }
});

test('v65.11: an × on each subject tuition line takes it off one student (and Charge again)', function () {
    ok(Db::tableExists('sis_registration_subject_skips'), 'the skips table is not created by the upgrade');
    $sheet = (string) file_get_contents(SIS_ROOT . '/app/views/assessment_sheet.php');
    ok(str_contains($sheet, "'action' => 'skip_subject_fee'") && str_contains($sheet, "'action' => 'charge_subject_fee'"), 'no × or Charge again on subject fee lines');
    ok(str_contains($sheet, 'soa-skipped" data-keep-controls'), 'Charge again would be hidden by the toolbar script');
    $lib = (string) file_get_contents(SIS_ROOT . '/app/lib/sis.php');
    ok(str_contains($lib, "!isset(\$skip[\$l['subject_doc_id'] . '|' . \$l['fee_label_id']])"), 'skipped subject fees are still charged');
    $line = SubjectFees::lines([['subject_doc_id' => 'nope', 'subject_code' => 'X', 'units' => 3]], null);
    ok($line === [], 'lines() of an unpriced subject');
    ok(str_contains((string) file_get_contents(SIS_ROOT . '/app/lib/subject-fees.php'), "'subject_doc_id' => (string) \$s['subject_doc_id'], 'fee_label_id' => (int) \$f['label_id']"), 'subject fee lines do not say which subject and fee they are');
});

$server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
