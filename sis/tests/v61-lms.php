<?php
/**
 * php sis/tests/v61-lms.php — LMS: classes from the Grading System, roles, stream and files, assignments and
 * submissions, quizzes (auto-scored and essays), attendance, the weighted gradebook, and sending grades to the
 * Grading System as drafts. Disposable database only; never the configured one.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
$cfg = sis_config(); $name = 'sis_v61_lms_test';
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cfg['db_name'] = $name; Db::connect($cfg);
foreach ([dirname(SIS_ROOT) . '/api/install.sql', SIS_ROOT . '/database/schema.sql', SIS_ROOT . '/database/seed.sql', SIS_ROOT . '/database/seed_accounting.sql'] as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) Db::q($stmt);
}
$c = Auth::central(); $c->install(); Schedules::install(Db::pdo()); Lms::install(Db::pdo()); Lms::install(Db::pdo()); Notify::install(Db::pdo()); // v62: notifications
$pass = 0; $fail = 0;
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . "\n"; } }
function denied(callable $fn, string $text = ''): void { try { $fn(); } catch (AppError|Forbidden $e) { if ($text !== '' && stripos($e->getMessage(), $text) === false) throw new RuntimeException('Wrong error: ' . $e->getMessage()); return; } throw new RuntimeException('Expected a refusal' . ($text ? " ($text)" : '')); }
function account(string $user, string $group, ?int $actor = null, array $extra = []): int {
    $c = Auth::central(); $id = $c->save($actor, $extra + ['full_name' => ucwords(str_replace('.', ' ', $user)), 'username' => $user, 'group' => $group, 'status' => 'ACTIVE', 'temp_password' => 'Temporary12345'], $actor === null);
    $c->q('UPDATE central_accounts SET must_change_password=0 WHERE id=?', [$id]); $c->clearCache(); return $id;
}
function as_user(int $id): void { $c = Auth::central(); $c->clearCache(); Auth::actAs(null); $c->establish($id); Auth::actAs(null); }
function upload(string $name, string $bytes): array { $f = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($f, $bytes); return ['name' => $name, 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)]; }
$power = account('lms.power', 'POWER_USER');
as_user($power);
$sec = GS::saveSection(null, '2026-2027', '1st Year', 'LMS 1A');
$sub = GS::saveSubject(null, 'LMS101', 'Learning Online', '3');
$t1 = GS::saveTeacher(null, 'LT01', 'Santos, Maria'); $t2 = GS::saveTeacher(null, 'LT02', 'Other, Teacher');
$s1 = GS::saveStudent(null, ['studentId' => 'L001', 'studentName' => 'Reyes, Ana', 'sectionId' => $sec]);
$s2 = GS::saveStudent(null, ['studentId' => 'L002', 'studentName' => 'Cruz, Ben', 'sectionId' => $sec]);
$s3 = GS::saveStudent(null, ['studentId' => 'L003', 'studentName' => 'Lim, Carl', 'sectionId' => $sec]);
$cls = GS::assignTeacher($sec, '1st Semester', $sub, $t1, [$s1, $s2]);
$teacher = account('lms.teacher', 'TEACHER', $power, ['teacher_doc_id' => $t1]);
$other = account('lms.other', 'TEACHER', $power, ['teacher_doc_id' => $t2]);
$ana = account('l001', 'STUDENT', $power, ['student_number' => 'L001']);
$ben = account('l002', 'STUDENT', $power, ['student_number' => 'L002']);
$carl = account('l003', 'STUDENT', $power, ['student_number' => 'L003']);
$Q = []; $W = [];

test('LMS is open and every group can use it; it is not "coming soon"', function () use ($c, $teacher, $ana) {
    ok(ModuleAccess::COMING_SOON === [], 'coming soon');
    foreach ([$teacher, $ana] as $id) { $c->clearCache(); ok(in_array('LMS', ModuleAccess::systems($c->permissions($id)), true), 'LMS not open for ' . $id); }
});
test('classes come from the Grading System: the teacher teaches it, enrolled students take it, others are refused', function () use ($teacher, $ana, $carl, $other, $cls) {
    as_user($teacher); $m = Lms::myClasses(); ok(count($m) === 1 && $m[0]['id'] === $cls && $m[0]['role'] === 'TEACHER', 'teacher classes');
    ok(count(Lms::roster(Lms::classFor($cls))) === 2, 'roster from Grading');
    as_user($ana); ok(Lms::myClasses()[0]['role'] === 'STUDENT', 'student class');
    as_user($carl); ok(Lms::myClasses() === [], 'carl not enrolled'); denied(fn() => Lms::classFor($cls), 'not in this class');
    as_user($other); denied(fn() => Lms::classFor($cls), 'not in this class');
});
test('stream: only the teacher posts; files are checked and stored as bytes; links must be http(s)', function () use ($teacher, $ana, $cls, &$Q) {
    as_user($ana); denied(fn() => Lms::savePost(Lms::classFor($cls), 'ANNOUNCEMENT', ['body' => 'hi'], []), 'Only the teacher');
    as_user($teacher); $c = Lms::classFor($cls);
    denied(fn() => Lms::savePost($c, 'ANNOUNCEMENT', ['body' => 'x', 'link_url' => 'javascript:alert(1)'], []), 'http');
    denied(fn() => Lms::savePost($c, 'MATERIAL', ['title' => 'Bad'], [upload('evil.php', '<?php echo 1;')]), 'not an allowed');
    denied(fn() => Lms::savePost($c, 'MATERIAL', ['title' => 'Fake'], [upload('fake.pdf', 'not a pdf')]), 'not a valid PDF');
    $pdf = "%PDF-1.4\n% test\n";
    $Q['post'] = Lms::savePost($c, 'MATERIAL', ['title' => 'Week 1 notes', 'topic' => 'Week 1', 'link_url' => 'https://example.org/notes'], [upload('notes.pdf', $pdf)]);
    $f = Lms::files('POST', $Q['post']); ok(count($f) === 1 && $f[0]['mime'] === 'application/pdf', 'file row');
    as_user($ana); $got = Lms::fileFor((int) $f[0]['id']); ok($got['bytes'] === $pdf, 'student downloads the same bytes');
});
test('assignment: hand in files/text/link, late marking, unsubmit, teacher scores with feedback and returns', function () use ($teacher, $ana, $ben, $cls, &$W) {
    as_user($teacher); $c = Lms::classFor($cls);
    denied(fn() => Lms::saveWork($c, null, ['title' => 'X', 'category' => 'Nope'], []), 'grading category');
    $W['a1'] = Lms::saveWork($c, null, ['title' => 'Essay 1', 'category' => 'Assignment', 'points' => '20', 'due_at' => date('Y-m-d H:i', strtotime('+2 days')), 'allow_late' => 1, 'allow_resubmit' => 1], []);
    $W['a2'] = Lms::saveWork($c, null, ['title' => 'Old activity', 'category' => 'Project', 'points' => '10', 'due_at' => date('Y-m-d H:i', strtotime('-1 day')), 'allow_late' => 0], []);
    as_user($ana); $c = Lms::classFor($cls);
    denied(fn() => Lms::submit($c, $W['a1'], [], [], true), 'Attach a file');
    Lms::submit($c, $W['a1'], ['text_answer' => 'My essay', 'link_url' => 'https://docs.example.org/x'], [upload('essay.docx', "PK\x03\x04docx")], true);
    $s = Db::one('SELECT * FROM lms_submissions WHERE work_id = ?', [$W['a1']]); ok($s['status'] === 'SUBMITTED' && !(int) $s['is_late'], 'turned in on time');
    denied(fn() => Lms::submit($c, $W['a1'], ['text_answer' => 'again'], [], true), 'already turned');
    Lms::unsubmit($c, $W['a1']); Lms::submit($c, $W['a1'], ['text_answer' => 'My better essay'], [], true);
    denied(fn() => Lms::submit($c, $W['a2'], ['text_answer' => 'late'], [], true), 'does not accept late');
    as_user($ben); denied(fn() => Lms::fileFor((int) Db::val("SELECT id FROM lms_files WHERE owner_type='SUBMISSION'")), 'another student');
    as_user($teacher); $c = Lms::classFor($cls);
    denied(fn() => Lms::grade($c, $W['a1'], (string) Db::val('SELECT student_doc_id FROM lms_submissions WHERE work_id = ?', [$W['a1']]), '25', '', true), 'more than');
    Lms::grade($c, $W['a1'], (string) Db::val('SELECT student_doc_id FROM lms_submissions WHERE work_id = ?', [$W['a1']]), '18', 'Good argument.', true);
    $s = Db::one('SELECT * FROM lms_submissions WHERE work_id = ?', [$W['a1']]); ok($s['status'] === 'RETURNED' && (float) $s['score'] === 18.0 && $s['feedback'] === 'Good argument.', 'returned with score');
});
test('quiz builder: validation, auto-scored types, total points, locked after students start', function () use ($teacher, $cls, &$W) {
    as_user($teacher); $c = Lms::classFor($cls);
    $W['q'] = Lms::saveWork($c, null, ['kind' => 'QUIZ', 'title' => 'Quiz 1', 'category' => 'Quiz', 'time_limit_min' => '20', 'show_results' => 1, 'allow_late' => 1], []);
    denied(fn() => Lms::saveQuestions($c, $W['q'], [['type' => 'MC', 'prompt' => 'Pick', 'options' => ['a', 'b'], 'answer' => []]]), 'mark the correct');
    denied(fn() => Lms::saveQuestions($c, $W['q'], [['type' => 'MC', 'prompt' => 'Pick', 'options' => ['a', 'b'], 'answer' => [0, 1]]]), 'one correct');
    Lms::saveQuestions($c, $W['q'], [
        ['type' => 'MC', 'prompt' => '2 + 2 = ?', 'options' => ['3', '4', '5'], 'answer' => ['1'], 'points' => '2'],
        ['type' => 'CHECK', 'prompt' => 'Prime numbers', 'options' => ['2', '4', '5'], 'answer' => ['0', '2'], 'points' => '2'],
        ['type' => 'TF', 'prompt' => 'The sun is a star', 'tf' => '0', 'points' => '1'],
        ['type' => 'SHORT', 'prompt' => 'Capital of the Philippines', 'accepted' => "Manila\nCity of Manila", 'points' => '1'],
    ]);
    ok((float) Db::val('SELECT points FROM lms_work WHERE id = ?', [$W['q']]) === 6.0 && count(Lms::questions($W['q'])) === 4, 'total 6 points, 4 questions');
});
test('taking a quiz: start once, auto score, result released at once, cannot retake', function () use ($ana, $teacher, $cls, &$W) {
    as_user($ana); $c = Lms::classFor($cls);
    denied(fn() => Lms::submitQuiz($c, $W['q'], []), 'Open the quiz');
    $s = Lms::startQuiz($c, $W['q']); ok($s['started_at'] !== null && Lms::secondsLeft(Lms::work($c, $W['q']), $s) > 1100, 'timer started');
    $qs = Lms::questions($W['q']); $id = array_column($qs, 'id');
    $r = Lms::submitQuiz($c, $W['q'], [$id[0] => '1', $id[1] => ['0', '2'], $id[2] => '1', $id[3] => '  manila ']);
    ok($r['score'] === 5.0 && $r['released'], 'auto score 5/6 (TF wrong): ' . json_encode($r));
    ok(Db::val('SELECT status FROM lms_submissions WHERE work_id = ? AND student_doc_id = ?', [$W['q'], Lms::me()['student']]) === 'RETURNED', 'released');
    denied(fn() => Lms::startQuiz($c, $W['q']), 'already turned in');
    as_user($teacher); denied(fn() => Lms::saveQuestions(Lms::classFor($cls), $W['q'], [['type' => 'TF', 'prompt' => 'x', 'tf' => '0']]), 'started');
});
test('exam with an essay waits for the teacher, who scores per question and returns it', function () use ($teacher, $ben, $cls, &$W) {
    as_user($teacher); $c = Lms::classFor($cls);
    $W['e'] = Lms::saveWork($c, null, ['kind' => 'QUIZ', 'title' => 'Midterm', 'category' => 'Exam', 'show_results' => 1], []);
    Lms::saveQuestions($c, $W['e'], [['type' => 'TF', 'prompt' => 'Water boils at 100°C at sea level', 'tf' => '0', 'points' => '2'], ['type' => 'ESSAY', 'prompt' => 'Explain photosynthesis', 'points' => '8']]);
    as_user($ben); $c = Lms::classFor($cls); Lms::startQuiz($c, $W['e']);
    $id = array_column(Lms::questions($W['e']), 'id');
    $r = Lms::submitQuiz($c, $W['e'], [$id[0] => '0', $id[1] => 'Plants make food from light.']);
    ok($r['score'] === null && $r['manual'] && !$r['released'], 'waits for teacher');
    as_user($teacher); $c = Lms::classFor($cls);
    $benId = (string) Db::val('SELECT student_doc_id FROM lms_submissions WHERE work_id = ?', [$W['e']]);
    denied(fn() => Lms::scoreQuestions($c, $W['e'], $benId, [], '', true), 'Score every essay');
    denied(fn() => Lms::scoreQuestions($c, $W['e'], $benId, [$id[1] => '9'], '', false), 'between 0');
    ok(Lms::scoreQuestions($c, $W['e'], $benId, [$id[1] => '6.5'], 'Good start.', true) === 8.5, 'total 8.5');
    ok(Db::val('SELECT status FROM lms_submissions WHERE work_id = ? AND student_doc_id = ?', [$W['e'], $benId]) === 'RETURNED', 'returned');
});
test('attendance: per meeting, no future dates, roster only, summary rates', function () use ($teacher, $ana, $cls) {
    as_user($teacher); $c = Lms::classFor($cls); $r = array_keys(Lms::roster($c));
    denied(fn() => Lms::takeAttendance($c, date('Y-m-d', strtotime('+1 day')), 'AM', [$r[0] => 'PRESENT']), 'future');
    denied(fn() => Lms::takeAttendance($c, date('Y-m-d'), 'AM', ['nobody' => 'PRESENT']), 'not in this class');
    Lms::takeAttendance($c, date('Y-m-d', strtotime('-1 day')), 'AM', [$r[0] => 'PRESENT', $r[1] => 'ABSENT'], [$r[1] => 'Sick']);
    $sid = Lms::takeAttendance($c, date('Y-m-d'), 'AM', [$r[0] => 'LATE', $r[1] => 'PRESENT'], [], 'Intro');
    Lms::takeAttendance($c, date('Y-m-d'), 'AM', [$r[0] => 'EXCUSED', $r[1] => 'PRESENT']);
    ok(count(Lms::sessions($cls)) === 2 && Lms::sessionMarks($sid)[$r[0]]['status'] === 'EXCUSED', 'retake updates');
    $sum = Lms::attendanceSummary($cls); ok($sum[$r[1]]['absent'] === 1 && $sum[$r[1]]['rate'] === 50.0 && $sum[$r[0]]['rate'] === 100.0, json_encode($sum));
    as_user($ana); denied(fn() => Lms::takeAttendance(Lms::classFor($cls), date('Y-m-d'), 'PM', []), 'Only the teacher');
    ok(count(Lms::myAttendance(Lms::classFor($cls))) === 2, 'student sees own');
});
test('gradebook: weighted categories, missing counts as 0, converted to the Grading System scale', function () use ($teacher, $cls) {
    as_user($teacher); $c = Lms::classFor($cls);
    denied(fn() => Lms::saveSettings($c, ['cat' => ['A', 'B'], 'weight' => ['50', '40']]), 'add up to 100');
    $g = Lms::gradebook($c); $rows = $g['rows']; $ana = array_values(array_filter($rows, fn($r) => $r['no'] === 'L001'))[0]; $ben = array_values(array_filter($rows, fn($r) => $r['no'] === 'L002'))[0];
    // Ana: Assignment 18/20=90, Quiz 5/6=83.33, Project missing (0/10)=0 → (30*90 + 30*83.33 + 20*0) / 80 = 65
    ok(abs($ana['percent'] - 65.0) < 0.01, 'ana ' . $ana['percent']);
    // Ben: Exam 8.5/10 = 85, Project missing 0, Assignment not yet due/ungraded (left out) → (20*0 + 20*85)/40 = 42.5
    ok(abs($ben['percent'] - 42.5) < 0.01, 'ben ' . $ben['percent']);
    ok($ana['grade'] === 65.0 && $g['scale'] === 'percent', 'percent scale grade');
    Db::q("INSERT INTO docs (col, id, data) VALUES ('settings','options',?) ON DUPLICATE KEY UPDATE data = VALUES(data)", [json_encode(['gradingScale' => 'point1'])]);
    ok(Lms::toGradingScale(90.0) === 1.75 && Lms::toGradingScale(60.0) === 5.0 && Lms::toGradingScale(97.5) === 1.0, 'transmutation');
    Db::q("DELETE FROM docs WHERE col='settings' AND id='options'");
    Lms::saveSettings($c, ['cat' => ['Assignment', 'Quiz', 'Project', 'Exam'], 'weight' => ['30', '30', '20', '20'], 'count_missing' => 0]);
    $ana2 = array_values(array_filter(Lms::gradebook($c)['rows'], fn($r) => $r['no'] === 'L001'))[0];
    ok(abs($ana2['percent'] - 86.67) < 0.01, 'missing left out: ' . $ana2['percent']);
});
test('send to Grading: drafts for the class teacher; submitted grades and completed terms are left alone', function () use ($teacher, $cls, $s1, $s2) {
    as_user($teacher); $c = Lms::classFor($cls);
    $r = Lms::sendToGrading($c);
    ok($r['sent'] === 2, json_encode($r));
    $g = GS::get('grades', $cls . '_' . $s1);
    ok($g['draft'] === true && $g['teacherDocId'] === $c['teacherDocId'] && abs($g['finalGrade'] - 86.67) < 0.01 && $g['remarks'] === 'Passed' && $g['studentNumber'] === 'L001', json_encode($g));
    // the teacher submits Ana's grade in the Grading System; a later send must not touch it
    $g['draft'] = false; Db::q("UPDATE docs SET data = ? WHERE col='grades' AND id = ?", [json_encode(array_diff_key($g, ['id' => 1])), $cls . '_' . $s1]);
    Db::q("INSERT INTO docs (col, id, data) VALUES ('gradeCompletions', ?, '{}')", [$s2 . '__2026-2027__1st-semester']);
    $r2 = Lms::sendToGrading($c);
    ok($r2['skipped_submitted'] === 1 && $r2['skipped_complete'] === 1 && $r2['sent'] + $r2['updated'] === 0, json_encode($r2));
    Db::q("INSERT INTO docs (col, id, data) VALUES ('settings','options',?) ON DUPLICATE KEY UPDATE data = VALUES(data)", [json_encode(['termDeadlines' => ['1st Semester' => ['__ts' => '2000-01-01T00:00:00.000Z']]])]);
    denied(fn() => Lms::sendToGrading($c), 'closed');
    Db::q("DELETE FROM docs WHERE col='settings' AND id='options'");
});
test('students cannot grade, send grades or see draft classwork', function () use ($ana, $teacher, $cls) {
    as_user($teacher); $c = Lms::classFor($cls); $d = Lms::saveWork($c, null, ['title' => 'Hidden', 'category' => 'Assignment', 'status' => 'DRAFT'], []);
    as_user($ana); $c = Lms::classFor($cls);
    denied(fn() => Lms::sendToGrading($c), 'Only the teacher'); denied(fn() => Lms::work($c, $d), 'not found');
    ok(!in_array('Hidden', array_column(Lms::classwork($c), 'title'), true), 'draft hidden');
    $todo = Lms::todo(Lms::myClasses()); ok(!in_array('Hidden', array_column($todo, 'title'), true), 'not in to do');
});
test('v61.1 messages: teacher and student talk privately; others cannot read or write; unread counts clear when read', function () use ($teacher, $ana, $ben, $carl, $other, $cls, $s1, $s2, $s3) {
    as_user($teacher); $c = Lms::classFor($cls);
    denied(fn() => Lms::sendMessage($c, $s3, 'hi'), 'not in this class');
    denied(fn() => Lms::sendMessage($c, $s1, '   '), 'Write a message');
    Lms::sendMessage($c, $s1, 'Please resubmit.');
    as_user($ana); $c = Lms::classFor($cls);
    ok(Lms::unreadMessages(Lms::myClasses()) === 1, 'ana unread');
    denied(fn() => Lms::thread($c, $s2), 'own messages'); denied(fn() => Lms::sendMessage($c, $s2, 'x'), 'own messages');
    ok(count(Lms::thread($c, $s1)) === 1 && Lms::unreadMessages(Lms::myClasses()) === 0, 'read clears');
    Lms::sendMessage($c, $s1, 'Will do!');
    as_user($ben); ok(Lms::threads(Lms::myClasses()) === [], 'ben sees nothing');
    as_user($other); denied(fn() => Lms::classFor($cls));
    as_user($teacher); $t = Lms::threads(Lms::myClasses());
    ok(count($t) === 1 && $t[0]['unread'] === 1 && $t[0]['with'] === 'Reyes, Ana' && $t[0]['body'] === 'Will do!' && !$t[0]['mine'], json_encode($t));
    ok((int) Db::val("SELECT COUNT(*) FROM sis_audit_logs WHERE action = 'lms_message_sent'") >= 2, 'audited');
});
test('v61.1 dashboard: timetable periods, meetings today, attendance today, materials and class report', function () use ($teacher, $ana, $cls, $sec, $sub, $t1) {
    Db::q("INSERT INTO sis_class_schedules (school_year, term, section_doc_id, subject_doc_id, teacher_doc_id, days, start_time, end_time, kind) VALUES
        ('2026-2027','1st Semester',?,?,?,'M,W,F','08:00:00','09:00:00','LEC'), ('2026-2027','1st Semester',?,?,?,'T,Th','10:00:00','11:30:00','LEC')", [$sec, $sub, $t1, $sec, $sub, $t1]);
    as_user($teacher); $classes = Lms::myClasses(); $m = Lms::allMeetings($classes); $tt = Lms::timetable($m);
    ok(count($tt['slots']) === 2 && $tt['days'] === ['M', 'T', 'W', 'Th', 'F'] && count($tt['cells'][0]['M']) === 1 && empty($tt['cells'][0]['T']), json_encode($tt['days']));
    $fri = strtotime('2026-11-13'); $on = Lms::meetingsOn($m, $fri);
    ok(count($on) === 1 && $on[0]['period'] === 1 && $on[0]['label'] === '8:00 AM–9:00 AM', json_encode($on));
    ok(count(Lms::meetingsOn($m, strtotime('2026-11-12'))) === 1 && Lms::meetingsOn($m, strtotime('2026-11-12'))[0]['period'] === 2, 'thursday P2');
    $att = Lms::attendanceOn([$cls], date('Y-m-d')); ok($att['total'] >= 0, 'attendance on');
    ok(Lms::materialCount($classes) >= 1, 'materials counted');
    $r = Lms::classReport(Lms::classFor($cls)); ok($r['students'] === 2 && $r['works'] >= 1 && $r['average'] !== null, json_encode($r));
    as_user($ana); $mine = Lms::materials(Lms::myClasses()); ok($mine && !array_filter($mine, fn($f) => $f['source'] === 'Hidden'), 'student materials');
});
$c->logout(); $server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
