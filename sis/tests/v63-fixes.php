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

$server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
