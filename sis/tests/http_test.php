<?php
/**
 * Web tests: every role opens every page, denied pages answer 403, CSRF is enforced, and one
 * Admission → Registration → Cashier run goes through the real forms.
 *   php bin/install.php --fresh --sample      (on a TEST copy of the database)
 *   php -S 127.0.0.1:8090 -t ..   (the grading system's folder)  and in api/config.php "sis_debug" => true
 *   php tests/http_test.php [http://127.0.0.1:8090/sis/index.php]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
sis_boot();
$base = $argv[1] ?? 'http://127.0.0.1:8090/sis/index.php';
$passed = 0; $failed = 0;

function req(string $url, ?array $post, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string) curl_exec($ch);
    $out = [curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
    curl_close($ch);
    return $out;
}
function csrf(string $body): string { return preg_match('/name="_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : ''; }
function check(string $name, bool $ok, string $why = ''): void { global $passed, $failed; if ($ok) $passed++; else { $failed++; echo "  ✘ $name $why\n"; } }
function login(string $base, string $user, string $pass, string $expect = 'dashboard'): string
{
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    [, $body] = req("$base?r=login", null, $jar);
    [$code, , $loc] = req("$base?r=login", ['_csrf' => csrf($body), 'username' => $user, 'password' => $pass], $jar);
    check("login $user", $code === 302 && str_contains($loc, "r=$expect"), "($code $loc)");
    return $jar;
}

$student = (string) Db::val("SELECT id FROM docs WHERE col = 'students' LIMIT 1");
$section = (string) Db::val("SELECT id FROM docs WHERE col = 'sections' LIMIT 1");
$reg = (int) Db::val('SELECT MIN(id) FROM sis_registrations');
$pay = (int) Db::val('SELECT MIN(id) FROM sis_payments');
$adm = (int) Db::val('SELECT MIN(id) FROM sis_admissions');
$routes = ['dashboard', "students", "student&id=$student", 'sections', "sections&edit=$section", 'yearlevels', 'teachers', 'curriculum', 'curriculum&tab=subjects',
    'assessment', 'assessment&tab=labels', 'assessment&tab=names', 'assessment&tab=names&id=1', 'discounts', 'discounts&tab=grantees', 'admission', "admission&id=$adm", 'registration', 'registration&new=1',
    "registration&id=$reg", 'cashier', "cashier&reg=$reg", 'cashier&q=Cruz', "receipt&id=$pay", 'users', 'settings', 'my', 'password', 'help', 'activity', 'search', 'search&q=Cruz', 'search&q=OR-',
    'reports', 'reports&rep=census&run=1', 'reports&rep=cashier_details&run=1', 'reports&rep=admission_list'];
$denied = [
    'cashier' => ['settings', 'activity', 'sections', 'yearlevels', 'teachers', 'curriculum', 'assessment', 'discounts', 'admission', 'registration&new=1', 'users', 'my', 'reports&rep=admission_list'],
    'admission' => ['settings', 'activity', 'teachers', 'cashier', "cashier&reg=$reg", "receipt&id=$pay", 'users', 'my', 'reports&rep=cashier_details&run=1'],
    'registrar' => ['users', 'settings', 'my'],
    'admin' => ['my'],
    'student' => ['settings', 'activity', 'students', 'student', 'yearlevels', 'sections', 'teachers', 'curriculum', 'assessment', 'discounts', 'admission', 'registration', 'cashier', 'users', 'search', 'reports'],
];

echo "Administrator first sign-in\n";
$jar = login($base, 'admin', 'ChangeMe!2026', 'password');
[, $body] = req("$base?r=password", null, $jar);
[$code, , $loc] = req("$base?r=password", ['_csrf' => csrf($body), 'current' => 'ChangeMe!2026', 'new' => 'Admin-Pass-2026', 'confirm' => 'Admin-Pass-2026'], $jar);
check('admin password changed', $code === 302 && str_contains($loc, 'dashboard'), "($code $loc)");

$accounts = ['admin' => ['admin', 'Admin-Pass-2026'], 'registrar' => ['registrar', 'Password123'], 'admission' => ['admission', 'Password123'],
    'cashier' => ['cashier', 'Password123'], 'student' => [(string) Db::val("SELECT username FROM sis_users WHERE role = 'STUDENT' LIMIT 1"), 'Password123']];
foreach ($accounts as $role => [$u, $p]) {
    echo "Role: $role\n";
    $jar = login($base, $u, $p, $role === 'student' ? 'dashboard' : 'dashboard');
    foreach ($routes as $r) {
        [$code, $body] = req("$base?r=$r", null, $jar);
        $isDenied = in_array($r, $denied[$role] ?? [], true) || in_array(explode('&', $r)[0], $denied[$role] ?? [], true);
        if ($isDenied) check("$role denied $r", $code === 403, "(got $code)");
        else check("$role $r", in_array($code, [200, 302], true), "(got $code)");
        if (preg_match('/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>|Uncaught/', $body, $m)) check("$role $r no PHP errors", false, strip_tags(substr($body, strpos($body, $m[0]), 300)));
    }
}

$assessmentJar = login($base, 'registrar', 'Password123');
[$assessmentCode, $assessmentBody] = req("$base?r=registration&id=$reg&embed=1", null, $assessmentJar);
check('assessment preview uses the statement-of-account layout', $assessmentCode === 200 && str_contains($assessmentBody, 'class="doc-sheet soa-sheet') && str_contains($assessmentBody, 'SUMMARY OF FEES') && str_contains($assessmentBody, 'PAYMENTS RECEIVED') && str_contains($assessmentBody, 'BALANCE DUE') && str_contains($assessmentBody, 'Student ID'), "($assessmentCode)");
$subjectForTeacher = Db::one('SELECT subject_doc_id, subject_code, subject_name FROM sis_registration_subjects WHERE registration_id = ? ORDER BY id LIMIT 1', [$reg]);
$teacherReg = Registration::get($reg);
if ($subjectForTeacher) {
    $qaAssignments = [];
    $qaTeacherCases = [
        ['QA Assigned Teacher', $teacherReg['section_doc_id'], $teacherReg['term']],
        ['Wrong Term Teacher', $teacherReg['section_doc_id'], $teacherReg['term'] === '1st Semester' ? '2nd Semester' : '1st Semester'],
        ['Wrong Section Teacher', 'qa-other-section', $teacherReg['term']],
    ];
    foreach ($qaTeacherCases as [$teacherName, $sectionId, $term]) {
        $assignmentId = 'qa-print-teacher-' . bin2hex(random_bytes(5));
        $assignmentData = ['subjectId' => $subjectForTeacher['subject_doc_id'], 'subjectCode' => $subjectForTeacher['subject_code'], 'subjectName' => $subjectForTeacher['subject_name'],
            'teacherDocId' => 'qa-teacher', 'teacherName' => $teacherName, 'sectionId' => $sectionId, 'sectionName' => $teacherReg['section_name'], 'schoolYear' => $teacherReg['school_year'], 'term' => $term];
        Db::q('INSERT INTO docs (col, id, data) VALUES (\'gradingAssignments\', ?, ?)', [$assignmentId, json_encode($assignmentData, JSON_UNESCAPED_UNICODE)]);
        $qaAssignments[] = $assignmentId;
    }
    [, $teacherBody] = req("$base?r=registration&id=$reg&embed=1", null, $assessmentJar);
    $subjectHeaderPos = strpos($teacherBody, '<th class="soa-subject">Subject</th>');
    $teacherHeaderPos = strpos($teacherBody, '<th class="soa-teacher">Teacher</th>');
    $unitsHeaderPos = strpos($teacherBody, '<th class="soa-num soa-units">Units</th>');
    check('teacher column follows Subject and appears before Units', $subjectHeaderPos !== false && $teacherHeaderPos > $subjectHeaderPos && $unitsHeaderPos > $teacherHeaderPos, '');
    check('statement resolves the teacher for the exact section, year and term', str_contains($teacherBody, 'QA Assigned Teacher') && !str_contains($teacherBody, 'Wrong Term Teacher') && !str_contains($teacherBody, 'Wrong Section Teacher'), '');
    Db::q('DELETE FROM docs WHERE col = \'gradingAssignments\' AND id IN ' . Db::in($qaAssignments), $qaAssignments);
} else {
    check('teacher column follows Subject and appears before Units', false, 'sample registration has no subjects');
    check('statement resolves the teacher for the exact section, year and term', false, 'sample registration has no subjects');
}

echo "Home, search and help\n";
$jar = login($base, 'cashier', 'Password123');
[, $body] = req("$base?r=dashboard", null, $jar);
check('cashier home: own quick actions, own recent activity, enrollment progress', str_contains($body, 'Receive a payment') && str_contains($body, 'Your recent activity') && str_contains($body, 'Enrollment progress') && !str_contains($body, 'Step 1 · record and admit'), '');
$regJar = login($base, 'registrar', 'Password123');
[, $body] = req("$base?r=dashboard", null, $regJar);
check('registrar home: everyone\'s recent activity in words', str_contains($body, 'Recent activity') && str_contains($body, 'Payment received') && str_contains($body, 'View all'), '');
[, $body] = req("$base?r=search&q=Cruz", null, $jar);
check('search finds the student and offers payment', str_contains($body, 'Cruz, Ben') && str_contains($body, 'Receive payment') && !str_contains($body, 'Applicants (Admission)'), '');
$jar = login($base, 'admission', 'Password123');
[, $body] = req("$base?r=search&q=Tan", null, $jar);
check('admission staff find applicants', str_contains($body, 'Applicants (Admission)') && str_contains($body, 'Tan, Eva'), '');
[, $body] = req("$base?r=help", null, $jar);
check('help lists the person\'s own tasks first', strpos($body, 'Your tasks') < strpos($body, 'Step 1 · Admit') && strpos($body, 'Step 1 · Admit') < strpos($body, 'Other people'), '');

echo "Unauthenticated and CSRF\n";
$anon = tempnam(sys_get_temp_dir(), 'jar');
foreach (['dashboard', 'students', 'cashier', 'assessment'] as $r) { [$code, , $loc] = req("$base?r=$r", null, $anon); check("anonymous $r → login", $code === 302 && str_contains($loc, 'r=login'), "($code)"); }
$jar = login($base, 'cashier', 'Password123');
[$code] = req("$base?r=cashier&reg=$reg", ['action' => 'void', 'payment_id' => $pay, 'reason' => 'x'], $jar);
check('POST without CSRF token refused', $code === 403, "($code)");

echo "Admission → Registration → Cashier through the web\n";
$jar = login($base, 'admission', 'Password123');
[, $body] = req("$base?r=admission", null, $jar);
[$code, , $loc] = req("$base?r=admission", ['_csrf' => csrf($body), 'applicant_type' => 'NEW', 'address' => '12 Rizal St., Brgy. Centro, Sample City', 'school_year' => '2026-2027', 'year_level' => '1st Year', 'program' => 'BSIT',
    'last_name' => 'Villanueva', 'first_name' => 'Rosa', 'middle_name' => 'Diaz', 'email' => 'rosa@example.com'], $jar);
check('applicant saved', preg_match('/r=admission&id=(\d+)/', $loc, $m) === 1, "($code $loc)");
$aid = (int) ($m[1] ?? 0);
[, $body] = req("$base?r=admission&id=$aid", null, $jar);
req("$base?r=admission&id=$aid", ['_csrf' => csrf($body), 'action' => 'admit'], $jar);
[, $body] = req("$base?r=admission&id=$aid", null, $jar);
check('admitted with a Student ID', str_contains($body, 'ADMITTED') && str_contains($body, 'Register (step 2)'), '');
[, $body] = req("$base?r=admission", null, $jar);
check('admission grid lists the applicant with pop-ups', str_contains($body, 'id="admGrid" data-select') && preg_match('/data-id="' . $aid . '"\\s+data-view="[^"]*admission&(amp;)?id=' . $aid . '&(amp;)?embed=1"/', $body) === 1 && str_contains($body, 'data-registrable="1"') && str_contains($body, 'Summaries'), '');
[, $body] = req("$base?r=admission&new=1&embed=1", null, $jar);
check('new applicant form opens bare for the pop-up', str_contains($body, 'Save applicant') && !str_contains($body, 'sis-top'), '');
[$code, , $loc] = req("$base?r=admission&new=1&embed=1", ['_csrf' => csrf($body), 'applicant_type' => 'TRANSFEREE', 'address' => '12 Rizal St., Brgy. Centro, Sample City', 'school_year' => '2026-2027', 'year_level' => '2nd Year', 'program' => 'BSIT', 'last_name' => 'Ocampo', 'first_name' => 'Lia', 'previous_school' => 'Rizal High'], $jar);
check('pop-up save stays in the pop-up', $code === 302 && str_contains($loc, 'embed=1') && preg_match('/id=(\d+)/', $loc, $mm) === 1, "($code $loc)");
$aid2 = (int) ($mm[1] ?? 0);
[, $body] = req("$base?r=admission&id=$aid2&embed=1", null, $jar);
check('pop-up marks the list for reload after saving', str_contains($body, 'data-changed="1"'), '');
[$code, , $loc] = req("$base?r=admission", ['_csrf' => csrf($body), 'action' => 'reject', 'adm_id' => $aid2, 'reason' => 'Incomplete papers', 'back' => 'index.php?r=admission&status=PENDING'], $jar);
check('reject from the list returns to the filtered list', $code === 302 && str_ends_with($loc, 'index.php?r=admission&status=PENDING') && Db::val('SELECT status FROM sis_admissions WHERE id = ?', [$aid2]) === 'REJECTED', "($code $loc)");
[$code, , $loc] = req("$base?r=admission", ['_csrf' => csrf($body), 'action' => 'admit', 'adm_id' => $aid2, 'back' => 'https://evil.example/'], $jar);
check('a rejected applicant cannot be admitted; foreign back URL ignored', !str_contains($loc, 'evil') && Db::val('SELECT status FROM sis_admissions WHERE id = ?', [$aid2]) === 'REJECTED', "($code $loc)");
$sec1A = (string) Db::val("SELECT id FROM docs WHERE col = 'sections' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.sectionName')) = 'BSIT 1A' AND f_schoolYear = '2026-2027'");
[, $body] = req("$base?r=registration&new=1&admission=$aid", null, $jar);
[$code, , $loc] = req("$base?r=registration&new=1&admission=$aid", ['_csrf' => csrf($body), 'action' => 'create', 'admission_id' => $aid, 'section_id' => $sec1A, 'term' => '1st Semester', 'assessment_name_id' => ''], $jar);
check('registered', preg_match('/r=registration&id=(\d+)/', $loc, $m) === 1, "($code $loc)");
$rid = (int) ($m[1] ?? 0);
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
check('assessment computed (17 units → ₱11,300.00)', str_contains($body, '11,300.00') && str_contains($body, 'Villanueva, Rosa D.'), '');
check('subjects and assessment name are separate tools', str_contains($body, 'Subjects (curriculum)') && str_contains($body, 'data-bs-target="#mSubjects"') && str_contains($body, 'id="mAname"') && str_contains($body, 'Curriculum: BSIT'), '');
$own = (string) Db::val("SELECT id FROM docs WHERE col = 'curriculum' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.program')) = 'BSIT' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.yearLevel')) = '1st Year' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.term')) = '1st Semester'");
$other = (string) Db::val("SELECT id FROM docs WHERE col = 'curriculum' AND id <> ? AND JSON_LENGTH(JSON_EXTRACT(data, '$.subjectIds')) > 0 LIMIT 1", [$own]);
$units = fn() => (string) Db::val('SELECT total_units FROM sis_registrations WHERE id = ?', [$rid]);
$before = $units();
if ($other !== '') {
    req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'curriculum', 'curriculum_id' => $other], $jar);
    check('another curriculum can be chosen', Db::val('SELECT COUNT(*) FROM sis_registration_subjects WHERE registration_id = ?', [$rid]) == count(GS::get('curriculum', $other)['subjectIds']), '');
}
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'curriculum', 'curriculum_id' => $own], $jar);
check('back to its own curriculum', $units() === $before, $units());
$nameId = (int) Db::val('SELECT assessment_name_id FROM sis_registrations WHERE id = ?', [$rid]);
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'aname', 'assessment_name_id' => ''], $jar);
check('assessment name removed keeps the subjects', Db::val('SELECT assessment_name_id FROM sis_registrations WHERE id = ?', [$rid]) === null && $units() === $before, '');
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'aname', 'assessment_name_id' => $nameId], $jar);
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
check('assessment name chosen again: same total', str_contains($body, '11,300.00'), '');
[, $nb] = req("$base?r=registration&new=1&student=" . urlencode((string) Db::val('SELECT student_number FROM sis_admissions WHERE id = ?', [$aid])), null, $jar);
check('new registration lets you pick the curriculum', str_contains($nb, 'name="curriculum_id"') && str_contains($nb, 'Automatic (matches the section and term)'), '');
$num = (string) Db::val('SELECT student_number FROM sis_admissions WHERE id = ?', [$aid]);
$g = GS::studentByNumber($num);
check('student added to the grading system in BSIT 1A', ($g['sectionName'] ?? '') === 'BSIT 1A' && ($g['studentName'] ?? '') === 'Villanueva, Rosa D.', json_encode($g));

$jar = login($base, 'cashier', 'Password123');
[, $body] = req("$base?r=cashier&reg=$rid", null, $jar);
$idem = preg_match('/name="idem" value="([a-f0-9]{32})"/', $body, $m) ? $m[1] : '';
$post = ['_csrf' => csrf($body), 'action' => 'pay', 'idem' => $idem, 'amount' => '5000', 'method' => 'CASH'];
[$c1, , $l1] = req("$base?r=cashier&reg=$rid", $post, $jar);
[$c2, , $l2] = req("$base?r=cashier&reg=$rid", $post, $jar);
check('payment recorded, receipt shown', $c1 === 302 && str_contains($l1, 'r=receipt'), "($c1 $l1)");
check('double submit → same receipt, one payment', $l1 === $l2 && (int) Db::val('SELECT COUNT(*) FROM sis_payments WHERE registration_id = ?', [$rid]) === 1, "($l1 / $l2)");
[, $body] = req("$base?r=cashier&reg=$rid", null, $jar);
check('balance ₱6,300.00 and PARTIAL', str_contains($body, '₱6,300.00') && str_contains($body, 'PARTIAL'), '');
[, $body] = req("$base?r=cashier&reg=$rid", null, $jar);
req("$base?r=cashier&reg=$rid", ['_csrf' => csrf($body), 'action' => 'pay', 'idem' => bin2hex(random_bytes(16)), 'amount' => '999999', 'method' => 'CASH'], $jar);
[, $body] = req("$base?r=cashier&reg=$rid", null, $jar);
check('overpayment refused', str_contains($body, 'more than the balance'), '');

echo "Down payment and void show on the assessment right away\n";
$dpReg = (int) Db::val("SELECT id FROM sis_registrations WHERE status <> 'CANCELLED' AND total_amount - amount_paid > 1000 ORDER BY id LIMIT 1");
$dpRow = Db::one('SELECT total_amount, amount_paid FROM sis_registrations WHERE id = ?', [$dpReg]);
$dpPaid0 = Money::cents($dpRow['amount_paid']);
$dpTotal = Money::cents($dpRow['total_amount']) - $dpPaid0;  // the balance before the down payment
[, $body] = req("$base?r=cashier&reg=$dpReg&embed=1", null, $jar);
check('payment form: amount starts empty, Full balance button', preg_match('/name="amount"[^>]*placeholder/', $body) === 1 && !preg_match('/name="amount"[^>]*value="/', $body) && str_contains($body, 'Full balance: ₱' . Money::fmt($dpTotal)), '');
preg_match('/name="idem" value="([^"]+)"/', $body, $mi);
[$code, , $loc] = req("$base?r=cashier&reg=$dpReg&embed=1", ['_csrf' => csrf($body), 'action' => 'pay', 'idem' => $mi[1] ?? '', 'amount' => '1000', 'method' => 'CASH', 'remarks' => 'down payment'], $jar);
check('paying in a pop-up shows the receipt in the pop-up', $code === 302 && str_contains($loc, 'r=receipt') && str_contains($loc, 'embed=1'), "($code $loc)");
[, $body] = req($loc, null, $jar);
check('…and closing it refreshes the page behind', str_contains($body, 'data-changed="1"'), '');
[, $body] = req("$base?r=registration&id=$dpReg&embed=1", null, $jar);
$paidAfterDownPayment = $dpPaid0 + 100000;
check('down payment: statement shows PARTIAL, updated paid-to-date, and balance due',
    str_contains($body, 'PARTIAL') && str_contains($body, '₱' . Money::fmt($paidAfterDownPayment)) && str_contains($body, '₱' . Money::fmt($dpTotal - 100000)) && str_contains($body, 'BALANCE DUE'), '');
$dpPay = (int) Db::val("SELECT id FROM sis_payments WHERE registration_id = ? AND status = 'POSTED' ORDER BY id DESC LIMIT 1", [$dpReg]);
[, $body] = req("$base?r=cashier&reg=$dpReg&embed=1", null, $jar);
req("$base?r=cashier&reg=$dpReg&embed=1", ['_csrf' => csrf($body), 'action' => 'void', 'payment_id' => $dpPay, 'reason' => 'wrong student', 'negate' => '0'], $jar);
[, $body] = req("$base?r=registration&id=$dpReg&embed=1", null, $jar);
check('void: statement restores prior payment status and balance, and marks the receipt void',
    str_contains($body, $dpPaid0 > 0 ? 'PARTIAL' : 'UNPAID') && str_contains($body, 'VOIDED') && str_contains($body, 'wrong student') && str_contains($body, '₱' . Money::fmt($dpTotal)) && str_contains($body, 'BALANCE DUE'), '');

echo "Fee labels chosen for one student\n";
$jar = login($base, 'registrar', 'Password123');
$ins = (int) Db::val("SELECT id FROM sis_fee_labels WHERE name = 'Insurance'");
$lab = (int) Db::val("SELECT id FROM sis_fee_labels WHERE name = 'Computer Laboratory'");
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
check('add-a-fee form lists the labels', str_contains($body, 'Add a fee (from Labels)') && str_contains($body, 'Insurance — fixed ₱100.00'), '');
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'charge_label', 'label_id' => $ins, 'amount' => '250'], $jar);
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'remove_label', 'label_id' => $lab], $jar);
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
check('insurance ₱250 and no lab fee: ₱11,300 + 150 − 1,500 = ₱9,950', str_contains($body, '9,950.00') && str_contains($body, 'not charged') && str_contains($body, 'for this student'), '');
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'reset_label', 'label_id' => $lab], $jar);
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
check('charge the lab fee again: ₱11,450', str_contains($body, '11,450.00'), '');

echo "New registration: find by name or Student ID\n";
[, $body] = req("$base?r=registration&new=1&student=" . urlencode('ana reyes'), null, $jar);
check('one name match picks the student', str_contains($body, 'Reyes, Ana M. · 20250001 · now in'), '');
[, $body] = req("$base?r=registration&new=1&student=20250001", null, $jar);
check('exact Student ID picks the student', str_contains($body, 'Reyes, Ana M. · 20250001 · now in'), '');
[, $body] = req("$base?r=registration&new=1&student=a", null, $jar);
check('several matches: a list to choose from', str_contains($body, 'Choose the student') && substr_count($body, '>Choose</span>') >= 2, '');
[, $body] = req("$base?r=registration&new=1&student=zzzz", null, $jar);
check('no match: a clear message', str_contains($body, 'No student found for'), '');

echo "Scholarships & discounts\n";
[, $body] = req("$base?r=discounts", null, $jar);
check('programs listed', str_contains($body, 'Academic Scholarship') && str_contains($body, '50% of Tuition') && str_contains($body, 'Sibling Discount'), '');
req("$base?r=discounts", ['_csrf' => csrf($body), 'name' => 'PWD Discount', 'kind' => 'DISCOUNT', 'method' => 'FIXED', 'value' => '1000', 'categories' => [(string) Db::val("SELECT id FROM sis_fee_categories WHERE name = 'Miscellaneous'")], 'is_active' => '1'], $jar);
$pwd = (int) Db::val("SELECT id FROM sis_discounts WHERE name = 'PWD Discount'");
check('new program saved', $pwd > 0, '');
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
$before = Money::cents(Registration::get($rid)['total_amount']);
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'give_discount', 'discount_id' => $pwd, 'remarks' => 'ID 12345'], $jar);
check('₱1,000 off Miscellaneous (capped at the ₱1,200 misc fees)', Money::cents(Registration::get($rid)['total_amount']) === $before - 100000, '');
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
check('shown on the assessment', str_contains($body, 'Less: Scholarships &amp; Discounts') && str_contains($body, 'PWD Discount (₱1,000.00 off Miscellaneous)') && str_contains($body, 'ID 12345'), '');
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'remove_discount', 'discount_id' => $pwd], $jar);
check('removed again', Money::cents(Registration::get($rid)['total_amount']) === $before, '');
[, $body] = req("$base?r=discounts&tab=grantees&sy=2026-2027", null, $jar);
check('grantees list: Ana with the Academic Scholarship', str_contains($body, 'Reyes, Ana M.') && str_contains($body, 'Academic Scholarship') && str_contains($body, '2,475.00'), '');

echo "Settings: printing\n";
$adminJar2 = login($base, 'admin', 'Admin-Pass-2026');
[, $body] = req("$base?r=settings", null, $adminJar2);
$form = ['_csrf' => csrf($body)] + Settings::DEFAULTS;
$originalSchoolData = Db::val("SELECT data FROM docs WHERE col = 'settings' AND id = 'school'");
$schoolData = $originalSchoolData !== null ? (json_decode((string) $originalSchoolData, true) ?: []) : [];
$testSchoolLogo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/h1sAAAAASUVORK5CYII=';
$schoolData['logo'] = $testSchoolLogo;
Db::q("INSERT INTO docs (col, id, data) VALUES ('settings', 'school', ?) ON DUPLICATE KEY UPDATE data = VALUES(data)", [json_encode($schoolData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
$form = array_merge($form, ['print_address' => '123 Rizal St., Quezon City', 'asm_template' => '2', 'asm_title' => 'Statement of Account', 'asm_note' => 'Pay 40% upon enrollment.', 'or_copies' => '2', 'or_paper' => 'THERMAL']);
foreach ($form as $k => $v2) if ($v2 === '0') unset($form[$k]); // unchecked boxes are not sent
req("$base?r=settings", $form, $adminJar2);
[, $body] = req("$base?r=registration&id=$rid&embed=1", null, $adminJar2);
check('Template 2 uses enrollment layout, settings, and configured Grading System logo', str_contains($body, 'class="doc-sheet enrollment-sheet') && str_contains($body, 'ASSESSMENT FORM') && str_contains($body, '123 Rizal St., Quezon City') && str_contains($body, 'Pay 40% upon enrollment.') && str_contains($body, 'Conforme') && str_contains($body, 'src="' . h($testSchoolLogo) . '"'), '');
if ($originalSchoolData !== null) Db::q("UPDATE docs SET data = ? WHERE col = 'settings' AND id = 'school'", [(string) $originalSchoolData]);
else Db::q("DELETE FROM docs WHERE col = 'settings' AND id = 'school'");
[, $settingsBody] = req("$base?r=settings", null, $adminJar2);
$form['_csrf'] = csrf($settingsBody);
$form['asm_template'] = '1';
req("$base?r=settings", $form, $adminJar2);
[, $body] = req("$base?r=registration&id=$rid&embed=1", null, $adminJar2);
check('Template 1 remains selectable and uses the configured title', str_contains($body, 'class="doc-sheet soa-sheet') && str_contains($body, 'Statement of Account'), '');
$pid = (int) Db::val('SELECT MAX(id) FROM sis_payments');
[, $body] = req("$base?r=receipt&id=$pid&embed=1", null, $adminJar2);
check('receipt: two copies, amount in words, 80 mm paper', substr_count($body, 'class="receipt-copy"') === 2 && str_contains($body, 'Duplicate') && str_contains($body, 'pesos and') && str_contains($body, '80mm'), json_encode([substr_count($body, 'class="receipt-copy"'), str_contains($body, 'Duplicate'), str_contains($body, 'pesos and'), str_contains($body, '80mm'), substr(strip_tags($body), 0, 300)]));
Db::q('DELETE FROM sis_settings');

echo "Confirm Enrollment: view only\n";
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
check('open registration: Confirm Enrollment button', str_contains($body, 'Confirm Enrollment') && str_contains($body, 'Add a fee (from Labels)'), '');
$toolButtons = [];
preg_match_all('/<button\b[^>]*class="registration-tool-button[^"]*"[^>]*>.*?<\/button>/s', $body, $toolButtons);
check('registration tool buttons omit per-button descriptions', count($toolButtons[0]) > 0 && !preg_match('/<small\b/i', implode('', $toolButtons[0])), '');
check('Confirm Enrollment opens the "Assessment correct?" review', str_contains($body, 'id="mConfirm"') && str_contains($body, 'Assessment correct? Confirm the enrollment') && str_contains($body, 'Total assessment to confirm') && str_contains($body, 'Yes, confirm enrollment'), '');
check('student type in the summaries and on the assessment', str_contains($body, 'Student Type') && preg_match('/(New student|Continuing student|Returning student|Transferee)/', $body) === 1, '');
[$code, , $loc] = req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'recompute'], $jar);
check('update fees now (recompute) keeps the total', $code === 302 && Db::val('SELECT total_amount FROM sis_registrations WHERE id = ?', [$rid]) !== null, "($code)");
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'finish'], $jar);
[, $body] = req("$base?r=registration&id=$rid", null, $jar);
check('done: view only, no edit forms, void still offered', str_contains($body, 'can only be viewed') && !str_contains($body, 'Add a fee (from Labels)') && !str_contains($body, 'Save and recompute') && str_contains($body, 'Void registration'), '');
req("$base?r=registration&id=$rid", ['_csrf' => csrf($body), 'action' => 'adjust', 'kind' => 'charge', 'amount' => '100', 'label' => 'Sneaky'], $jar);
check('server refuses changes to a done registration', !Db::val("SELECT 1 FROM sis_registration_items WHERE registration_id = ? AND label_name = 'Sneaky'", [$rid]), '');
[, $body] = req("$base?r=registration", null, $jar);
check('grid lists the done registration with a View pop-up', preg_match('/data-id="' . $rid . '"\\s+data-view="[^"]*registration&(amp;)?id=' . $rid . '&(amp;)?embed=1"/', $body) === 1 && str_contains($body, 'id="regGrid" data-select') && str_contains($body, 'Sub-Components') && str_contains($body, 'Summaries'), '');
check('registrar cannot reopen', !str_contains(req("$base?r=registration&id=$rid", null, $jar)[1], 'Reopen'), '');
$adminJar = login($base, 'admin', 'Admin-Pass-2026');
[, $body] = req("$base?r=registration&id=$rid", null, $adminJar);
check('administrator sees Reopen', str_contains($body, 'Reopen'), '');

echo "Cashier: official receipts list\n";
$cjar = login($base, 'cashier', 'Password123');
[, $body] = req("$base?r=cashier", null, $cjar);
$today = Db::all("SELECT receipt_no, method, amount, status FROM sis_payments WHERE DATE(paid_at) = CURDATE()");
$cashTotal = array_sum(array_map(fn($p) => $p['status'] === 'POSTED' && $p['method'] === 'CASH' ? Money::cents($p['amount']) : 0, $today));
check('list of today\'s receipts with the cash total', count($today) > 0 && str_contains($body, $today[0]['receipt_no']) && str_contains($body, 'Total Cash') && str_contains($body, Money::fmt($cashTotal)) && str_contains($body, 'Grand Total'), '');
[, $body] = req("$base?r=cashier&method=GCASH", null, $cjar);
$cashRow = array_values(array_filter($today, fn($p) => $p['method'] === 'CASH'))[0]['receipt_no'] ?? 'none';
check('filter by payment method', !str_contains($body, '>' . $cashRow . '<'), '');
[, $body] = req("$base?r=cashier&new=1&embed=1", null, $cjar);
check('New: receive-payment form for the pop-up (no menu)', str_contains($body, 'Student ID, name or registration number') && !str_contains($body, 'sis-top'), '');

echo "Next term needs the final grades tagged complete\n";
$ben = GS::studentByNumber((string) Db::val("SELECT student_number FROM sis_admissions WHERE last_name = 'Cruz'"));
[, $body] = req("$base?r=registration&new=1&student={$ben['studentId']}", null, $jar);
check('blocked notice and disabled button', str_contains($body, "Can't register yet") && str_contains($body, '2026-2027 1st Semester') && preg_match('/<button class="btn btn-primary mt-3" disabled>/', $body) === 1, '');
[$code, , $loc] = req("$base?r=registration&new=1&student={$ben['studentId']}", ['_csrf' => csrf($body), 'action' => 'create', 'student_number' => $ben['studentId'], 'section_id' => $sec1A, 'term' => '2nd Semester', 'assessment_name_id' => ''], $jar);
check('server refuses the registration', !str_contains($loc, 'r=registration&id='), "($code $loc)");
Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?)', ['gradeCompletions', "{$ben['id']}__2026-2027__1st-semester", json_encode(['studentId' => $ben['id'], 'schoolYear' => '2026-2027', 'term' => '1st Semester', 'termKey' => '1st-semester', 'subjects' => []])]);
[, $body] = req("$base?r=registration&new=1&student={$ben['studentId']}", null, $jar);
check('after tagging: allowed', str_contains($body, 'are tagged complete') && !str_contains($body, "Can't register yet"), '');
[$code, , $loc] = req("$base?r=registration&new=1&student={$ben['studentId']}", ['_csrf' => csrf($body), 'action' => 'create', 'student_number' => $ben['studentId'], 'section_id' => $sec1A, 'term' => '2nd Semester', 'assessment_name_id' => ''], $jar);
check('registered for the 2nd Semester', str_contains($loc, 'r=registration&id='), "($code $loc)");

echo "Assessment workflow screens\n";
$rj = login($base, 'registrar', 'Password123');
$openReg = (int) Db::val("SELECT id FROM sis_registrations WHERE status <> 'CANCELLED' AND locked_at IS NULL ORDER BY id DESC LIMIT 1");
$stuNum = (string) Db::val('SELECT student_number FROM sis_registrations WHERE id = ?', [$openReg]);
[, $body] = req("$base?r=registration&new=1&student=" . urlencode($stuNum), null, $rj);
check('new registration shows the student type', str_contains($body, 'Student type:'), '');
$cj = login($base, 'cashier', 'Password123');
[, $body] = req("$base?r=cashier&reg=$openReg", null, $cj);
check('cashier is told the enrollment is not confirmed yet', str_contains($body, 'Enrollment not confirmed yet'), '');
$ghost = Db::insert('sis_assessment_names', ['name' => 'Empty template test', 'program' => 'ZZZ', 'is_active' => 1]);
[, $body] = req("$base?r=assessment&tab=names", null, $rj);
check('names tab warns about a template with no fee lines', str_contains($body, 'Check the fee setup') && str_contains($body, 'Empty template test'), '');
Db::q('DELETE FROM sis_assessment_names WHERE id = ?', [$ghost]);

echo "Import fee setup from Excel\n";
$upload = function (string $url, array $fields, string $file, string $name, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields + ['file' => new CURLFile($file, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $name)]]);
    $b = (string) curl_exec($ch); $out = [curl_getinfo($ch, CURLINFO_HTTP_CODE), $b, (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL)]; curl_close($ch); return $out;
};
$ij = login($base, 'registrar', 'Password123');
foreach (['categories', 'labels', 'names'] as $k) {
    [, $body] = req("$base?r=assessment&tab=$k", null, $ij);
    check("$k tab: Import from Excel and template buttons", str_contains($body, 'Import from Excel') && str_contains($body, 'template=1'), '');
    $ch = curl_init("$base?r=assessment&tab=$k&template=1"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $ij, CURLOPT_HEADER => true]);
    $resp = (string) curl_exec($ch); curl_close($ch);
    check("$k template downloads as .xlsx", str_contains($resp, 'spreadsheetml.sheet') && str_contains($resp, "sis-$k-template.xlsx") && str_contains($resp, "PK\x03\x04"), substr($resp, 0, 200));
}
[, $body] = req("$base?r=discounts", null, $ij);
check('scholarships page: Import from Excel', str_contains($body, 'Import from Excel'), '');
$xf = tempnam(sys_get_temp_dir(), 'web') . '.xlsx';
file_put_contents($xf, Xlsx::write(['Labels' => [['Category', 'Label', 'Charged', 'Default amount'], ['Miscellaneous', 'Web Import Fee', 'Fixed amount', '75']]]));
[, $body] = req("$base?r=assessment&tab=labels", null, $ij);
$upload("$base?r=assessment&tab=labels", ['_csrf' => csrf($body), 'action' => 'import', 'check_only' => '1'], $xf, 'labels.xlsx', $ij);
[, $body] = req("$base?r=assessment&tab=labels", null, $ij);
check('check only: told what would happen, nothing saved', str_contains($body, '1 would be added') && !Db::val("SELECT 1 FROM sis_fee_labels WHERE name = 'Web Import Fee'"), '');
[$code, , $loc] = $upload("$base?r=assessment&tab=labels", ['_csrf' => csrf($body), 'action' => 'import'], $xf, 'labels.xlsx', $ij);
[, $body] = req("$base?r=assessment&tab=labels", null, $ij);
check('import saves the label', $code === 302 && str_contains($body, 'Imported Labels: 1 added') && Db::val("SELECT default_amount FROM sis_fee_labels WHERE name = 'Web Import Fee'") === '75.00', "($code)");
file_put_contents($xf, Xlsx::write(['Labels' => [['Category', 'Label', 'Charged'], ['Miscellaneous', 'Broken', 'Monthly']]]));
$upload("$base?r=assessment&tab=labels", ['_csrf' => csrf($body), 'action' => 'import'], $xf, 'bad.xlsx', $ij);
[, $body] = req("$base?r=assessment&tab=labels", null, $ij);
check('errors are listed by row, nothing saved', str_contains($body, 'Nothing was imported') && str_contains($body, 'row 2') && !Db::val("SELECT 1 FROM sis_fee_labels WHERE name = 'Broken'"), '');
Db::q("DELETE FROM sis_fee_labels WHERE name = 'Web Import Fee'");
$cjI = login($base, 'cashier', 'Password123');
[$code] = req("$base?r=assessment&tab=labels&template=1", null, $cjI);
check('cashier cannot download fee templates', $code === 403, "($code)");

echo "Accounts: student portal and temporary passwords\n";
$jar = login($base, 'admin', 'Admin-Pass-2026');
[, $body] = req("$base?r=users", null, $jar);
check('new account form suggests a temporary password', preg_match('/name="temp_password"[^>]*value="[A-Za-z]+-\d{4}-[A-Za-z]+"/', $body) === 1, '');
$sid = (string) Db::val("SELECT s.f_studentId FROM docs s WHERE s.col = 'students' AND s.f_studentId NOT IN (SELECT student_number FROM sis_users WHERE student_number IS NOT NULL) LIMIT 1");
$sname = (string) GS::studentByNumber($sid)['studentName'];
req("$base?r=users", ['_csrf' => csrf($body), 'action' => 'save', 'role' => 'STUDENT', 'username' => '', 'student_number' => $sid, 'full_name' => '', 'is_active' => '1', 'temp_password' => 'Student-2026-Pw'], $jar);
$acc = Db::one('SELECT * FROM sis_users WHERE student_number = ?', [$sid]);
check('student account created with only the Student ID', $acc && $acc['username'] === strtolower($sid) && $acc['full_name'] === $sname && (int) $acc['must_change_password'] === 1, $sid);
[, $body] = req("$base?r=users", null, $jar);
req("$base?r=users", ['_csrf' => csrf($body), 'action' => 'save', 'role' => 'CASHIER', 'username' => '', 'full_name' => 'No Name', 'is_active' => '1'], $jar);
check('staff accounts still need a username', !Db::val("SELECT 1 FROM sis_users WHERE full_name = 'No Name'"), '');
$sj = login($base, $sid, 'Student-2026-Pw', 'password');
[, $body] = req("$base?r=password", null, $sj);
req("$base?r=password", ['_csrf' => csrf($body), 'current' => 'Student-2026-Pw', 'new' => 'MyOwnPass-2026', 'confirm' => 'MyOwnPass-2026'], $sj);
[$code, $body] = req("$base?r=my", null, $sj);
check('student portal opens after changing the password', $code === 200 && str_contains($body, 'My final grades') && str_contains($body, h($sname)), "($code)");
[, $body] = req("$base?r=users&edit={$acc['id']}", null, $jar);
check('edit shows Change password with a suggestion', str_contains($body, 'Change password of ' . strtolower($sid)) && preg_match('/id="resetPw"[^>]*value="[A-Za-z]+-\d{4}-[A-Za-z]+"/', $body) === 1, '');
req("$base?r=users", ['_csrf' => csrf($body), 'action' => 'reset', 'id' => $acc['id'], 'temp_password' => 'short1'], $jar);
check('a weak temporary password is refused', password_verify('MyOwnPass-2026', (string) Db::val('SELECT password_hash FROM sis_users WHERE id = ?', [$acc['id']])), '');
[, $body] = req("$base?r=users&edit={$acc['id']}", null, $jar);
[$code, , $loc] = req("$base?r=users", ['_csrf' => csrf($body), 'action' => 'reset', 'id' => $acc['id'], 'temp_password' => 'Chosen-By-Admin-7'], $jar);
$after = Db::one('SELECT * FROM sis_users WHERE id = ?', [$acc['id']]);
check('administrator sets the password they chose', password_verify('Chosen-By-Admin-7', $after['password_hash']) && (int) $after['must_change_password'] === 1 && str_contains($loc, 'edit=' . $acc['id']), "($code $loc)");
login($base, strtoupper($sid) . ' ', 'Chosen-By-Admin-7', 'password');
login($base, 'REGISTRAR', 'Password123');

echo "Report Manager\n";
$jar = login($base, 'admin', 'Admin-Pass-2026');
[, $body] = req("$base?r=reports", null, $jar);
check('report manager lists the groups', str_contains($body, 'Report Group : Cashier and Collections') && str_contains($body, 'Cashier Report Details') && str_contains($body, 'Enrollment Census'), '');
foreach (array_keys(Reports::LIST) as $k) {
    [$code, $body] = req("$base?r=reports&rep=$k&run=1&embed=1&sy=2026-2027&from=2020-01-01&to=2030-12-31", null, $jar);
    $err = preg_match('/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>|Uncaught/', $body, $m) ? strip_tags(substr($body, strpos($body, $m[0]), 300)) : '';
    check("report $k runs", $code === 200 && str_contains($body, 'report-table') && $err === '', "($code) $err");
}
[, $body] = req("$base?r=reports&rep=cashier_details&run=1&from=2020-01-01&to=2030-12-31", null, $jar);
$posted = Money::fmt(Money::cents((string) Db::val("SELECT COALESCE(SUM(amount),0) FROM sis_payments WHERE status = 'POSTED'")));
check('cashier report grand total = posted payments', str_contains($body, 'Grand total') && str_contains($body, $posted) && (str_contains($body, 'Voided (not counted)') === (bool) Db::val("SELECT COUNT(*) FROM sis_payments WHERE status = 'VOIDED'")), $posted);
[, $body] = req("$base?r=reports&rep=census&run=1&sy=2026-2027", null, $jar);
$regd = (int) Db::val("SELECT COUNT(*) FROM sis_registrations WHERE school_year = '2026-2027' AND status <> 'CANCELLED'");
check('census counts the registrations', preg_match('/Registered students<\/span><b>' . $regd . '<\/b>/', $body) === 1, (string) $regd);

echo "Registration: remove a fee, void the charge, restore it, and void again\n";
$admissionJar = login($base, 'admission', 'Password123');
$eva = (int) Db::val("SELECT id FROM sis_admissions WHERE first_name = 'Eva' AND status = 'PENDING' ORDER BY id LIMIT 1");
[, $body] = req("$base?r=admission&id=$eva", null, $admissionJar);
req("$base?r=admission&id=$eva", ['_csrf' => csrf($body), 'action' => 'admit'], $admissionJar);
$registrarJar = login($base, 'registrar', 'Password123');
[, $body] = req("$base?r=registration&new=1&admission=$eva", null, $registrarJar);
$section2A = (string) Db::val("SELECT id FROM docs WHERE col = 'sections' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.sectionName')) = 'BSIT 2A' AND f_schoolYear = '2026-2027'");
[$code, , $loc] = req("$base?r=registration&new=1&admission=$eva", ['_csrf' => csrf($body), 'action' => 'create', 'admission_id' => $eva, 'section_id' => $section2A, 'term' => '1st Semester', 'assessment_name_id' => ''], $registrarJar);
check('fresh registration created through the form', $code === 302 && preg_match('/r=registration&id=(\d+)/', $loc, $rm) === 1, "($code $loc)");
$freshReg = (int) ($rm[1] ?? 0);
$labId = (int) Db::val("SELECT id FROM sis_fee_labels WHERE name = 'Computer Laboratory'");
$originalTotal = Money::cents((string) Db::val('SELECT total_amount FROM sis_registrations WHERE id = ?', [$freshReg]));
[, $body] = req("$base?r=registration&id=$freshReg", null, $registrarJar);
req("$base?r=registration&id=$freshReg", ['_csrf' => csrf($body), 'action' => 'charge_label', 'label_id' => $labId, 'amount' => '1500'], $registrarJar);
$cashierJar = login($base, 'cashier', 'Password123');
[, $body] = req("$base?r=cashier&reg=$freshReg", null, $cashierJar);
[$firstPayCode] = req("$base?r=cashier&reg=$freshReg", ['_csrf' => csrf($body), 'action' => 'pay', 'idem' => bin2hex(random_bytes(16)), 'amount' => '100', 'method' => 'CASH'], $cashierJar);
$firstPaymentId = (int) Db::val('SELECT MAX(id) FROM sis_payments WHERE registration_id = ?', [$freshReg]);
[, $body] = req("$base?r=registration&id=$freshReg", null, $registrarJar);
req("$base?r=registration&id=$freshReg", ['_csrf' => csrf($body), 'action' => 'remove_label', 'label_id' => $labId], $registrarJar);
$removedTotal = Money::cents((string) Db::val('SELECT total_amount FROM sis_registrations WHERE id = ?', [$freshReg]));
check('fee item removal updates the assessment once', $removedTotal === $originalTotal - 150000, Money::fmt($removedTotal));

[, $body] = req("$base?r=cashier&reg=$freshReg", null, $cashierJar);
[$firstVoidCode] = req("$base?r=cashier&reg=$freshReg", ['_csrf' => csrf($body), 'action' => 'void', 'payment_id' => $firstPaymentId, 'reason' => 'fee removed after payment', 'negate' => '1'], $cashierJar);
check('payment made before fee removal can be voided afterward', $firstPayCode === 302 && $firstVoidCode === 302 && Db::val('SELECT status FROM sis_payments WHERE id = ?', [$firstPaymentId]) === 'VOIDED', "($firstPayCode/$firstVoidCode)");
foreach ([100, 250] as $amount) {
    [, $body] = req("$base?r=cashier&reg=$freshReg", null, $cashierJar);
    [$payCode] = req("$base?r=cashier&reg=$freshReg", ['_csrf' => csrf($body), 'action' => 'pay', 'idem' => bin2hex(random_bytes(16)), 'amount' => (string) $amount, 'method' => 'CASH'], $cashierJar);
    [, $body] = req("$base?r=cashier&reg=$freshReg", null, $cashierJar);
    $paymentId = (int) Db::val('SELECT MAX(id) FROM sis_payments WHERE registration_id = ?', [$freshReg]);
    [$voidCode] = req("$base?r=cashier&reg=$freshReg", ['_csrf' => csrf($body), 'action' => 'void', 'payment_id' => $paymentId, 'reason' => 'registration regression test', 'negate' => '0'], $cashierJar);
    check("payment ₱$amount is recorded and voided", $payCode === 302 && $voidCode === 302 && Db::val('SELECT status FROM sis_payments WHERE id = ?', [$paymentId]) === 'VOIDED', "($payCode/$voidCode)");
}
[, $body] = req("$base?r=registration&id=$freshReg", null, $registrarJar);
req("$base?r=registration&id=$freshReg", ['_csrf' => csrf($body), 'action' => 'reset_label', 'label_id' => $labId], $registrarJar);
$finalTotal = Money::cents((string) Db::val('SELECT total_amount FROM sis_registrations WHERE id = ?', [$freshReg]));
$freshRow = Registration::get($freshReg);
check('restore removed fee after voids; no balance drift', $finalTotal === $originalTotal && $freshRow['amount_paid'] === '0.00' && Registration::balance($freshRow) === $originalTotal && (int) Db::val("SELECT COUNT(*) FROM sis_payments WHERE registration_id = ? AND status = 'VOIDED'", [$freshReg]) === 3, '');
[, $body] = req("$base?r=registration&id=$freshReg", null, $registrarJar);
req("$base?r=registration&id=$freshReg", ['_csrf' => csrf($body), 'action' => 'remove_label', 'label_id' => $labId], $registrarJar);
$freshName = (int) Db::val('SELECT assessment_name_id FROM sis_registrations WHERE id = ?', [$freshReg]);
[, $body] = req("$base?r=registration&id=$freshReg", null, $registrarJar);
[$anameCode] = req("$base?r=registration&id=$freshReg", ['_csrf' => csrf($body), 'action' => 'aname', 'assessment_name_id' => (string) $freshName], $registrarJar);
$retagTotal = Money::cents((string) Db::val('SELECT total_amount FROM sis_registrations WHERE id = ?', [$freshReg]));
check('fee taken off comes back when the assessment name is chosen again', $freshName > 0 && $anameCode === 302 && $retagTotal === $originalTotal, Money::fmt($retagTotal));

echo "Official receipt series, O.R. numbers and the official receipt template\n";
$adminJar3 = login($base, 'admin', 'Admin-Pass-2026');
[, $body] = req("$base?r=settings", null, $adminJar3);
check('settings shows the O.R. series and the setup button', str_contains($body, 'O.R. Series Setup') && str_contains($body, 'OR1') && !str_contains($body, 'name="or_prefix"'), '');
[$code, $body] = req("$base?r=orseries&embed=1", null, $adminJar3);
check('O.R. series pop-up: search, grid, summaries and buttons', $code === 200 && str_contains($body, 'Search by Description') && str_contains($body, 'Total Record(s) : 1') && str_contains($body, 'OFFICIAL RECEIPT SERIES ONE') && str_contains($body, 'data-frame-close') && str_contains($body, '>Add</a>'), "($code)");
[, $body] = req("$base?r=orseries&edit=new&embed=1", null, $adminJar3);
[$code] = req("$base?r=orseries&embed=1", ['_csrf' => csrf($body), 'action' => 'save', 'id' => '', 'code' => 'or3', 'description' => 'Loose Leaf Receipt', 'prefix' => '', 'digits' => '6', 'next_number' => '500',
    'template' => 'OFFICIAL', 'is_active' => '1', 'auto_number' => '1', 'allow_edit' => '1'], $adminJar3);
$or3 = (int) Db::val("SELECT id FROM sis_or_series WHERE code = 'OR3'");
check('series added from the pop-up', $code === 302 && $or3 > 0, "($code)");
[, $body] = req("$base?r=orseries&edit=$or3&embed=1", null, $adminJar3);
check('edit form shows the next number', str_contains($body, 'Edit O.R. Series') && str_contains($body, '000500'), '');
[, $body] = req("$base?r=orseries&embed=1", null, $adminJar3);
req("$base?r=orseries&embed=1", ['_csrf' => csrf($body), 'action' => 'delete', 'id' => (string) Db::val("SELECT id FROM sis_or_series WHERE code = 'OR1'")], $adminJar3);
check('the default series is not deleted', (bool) Db::val("SELECT 1 FROM sis_or_series WHERE code = 'OR1'"), '');

$cashJar = login($base, 'cashier', 'Password123');
[, $body] = req("$base?r=cashier&reg=$freshReg", null, $cashJar);
check('payment form offers the O.R. series with the next number', str_contains($body, 'OR3 · Loose Leaf Receipt') && str_contains($body, 'data-next="000500"') && str_contains($body, 'id="payOrNo"'), '');
$payOr = function (string $orNo, string $suggested) use ($base, $freshReg, $cashJar, $or3): int {
    [, $b] = req("$base?r=cashier&reg=$freshReg", null, $cashJar);
    [$c] = req("$base?r=cashier&reg=$freshReg", ['_csrf' => csrf($b), 'action' => 'pay', 'idem' => bin2hex(random_bytes(16)), 'amount' => '100', 'method' => 'CASH', 'series_id' => (string) $or3, 'or_no' => $orNo, 'or_suggested' => $suggested], $cashJar);
    return $c;
};
$payOr('000500', '000500');
$p500 = Db::one("SELECT id, series_id FROM sis_payments WHERE receipt_no = '000500'");
check('suggested O.R. No. posted in the chosen series', $p500 && (int) $p500['series_id'] === $or3, '');
$payOr('000777', '000501');
$before = (int) Db::val('SELECT COUNT(*) FROM sis_payments');
$payOr('000777', '000501');
check('typed O.R. No. used once only', (bool) Db::val("SELECT 1 FROM sis_payments WHERE receipt_no = '000777'") && (int) Db::val('SELECT COUNT(*) FROM sis_payments') === $before, '');
[$code, $body] = req("$base?r=receipt&id={$p500['id']}&embed=1", null, $cashJar);
check('Template Official Receipt prints the form fields', $code === 200 && str_contains($body, 'ofr-sheet') && str_contains($body, 'Acct ID Per Sem.') && str_contains($body, 'Payment ID') && str_contains($body, 'Amount in Words')
    && str_contains($body, 'One hundred pesos and 00/100') && str_contains($body, 'Total Vat-Exempt Sales') && str_contains($body, 'Less Withholding Tax') && str_contains($body, 'TOTAL AMOUNT DUE') && str_contains($body, 'Year Level'), "($code)");
[, $body] = req("$base?r=receipt&id={$p500['id']}&embed=1", null, $cashJar);
[$code] = req("$base?r=receipt&id={$p500['id']}&embed=1", ['_csrf' => csrf($body), 'action' => 'or_no', 'or_no' => '000501', 'reason' => 'form 501 used'], $cashJar);
check('O.R. No. edited after posting from the receipt', $code === 302 && Db::val('SELECT receipt_no FROM sis_payments WHERE id = ?', [$p500['id']]) === '000501', "($code)");
$p777 = (int) Db::val("SELECT id FROM sis_payments WHERE receipt_no = '000777'");
[, $body] = req("$base?r=cashier", null, $cashJar);
check('receipts list: Edit O.R. No. and the void question', str_contains($body, 'Edit O.R. No.') && str_contains($body, 'data-editable="1"') && str_contains($body, 'Convert the O.R. number to negative'), '');
req("$base?r=cashier", ['_csrf' => csrf($body), 'action' => 'or_no', 'payment_id' => (string) $p777, 'or_no' => '000501', 'reason' => 'x'], $cashJar);
check('edit to a used number is refused', Db::val('SELECT receipt_no FROM sis_payments WHERE id = ?', [$p777]) === '000777', '');
[, $body] = req("$base?r=cashier", null, $cashJar);
req("$base?r=cashier", ['_csrf' => csrf($body), 'action' => 'or_no', 'payment_id' => (string) $p777, 'or_no' => '000778', 'reason' => 'typo'], $cashJar);
check('O.R. No. edited from the receipts list', Db::val('SELECT receipt_no FROM sis_payments WHERE id = ?', [$p777]) === '000778', '');
[, $body] = req("$base?r=cashier", null, $cashJar);
req("$base?r=cashier", ['_csrf' => csrf($body), 'action' => 'void', 'payment_id' => (string) $p777, 'reason' => 'wrong'], $cashJar);
check('void asks about the negative O.R. No. first', Db::val('SELECT status FROM sis_payments WHERE id = ?', [$p777]) === 'POSTED', '');
[, $body] = req("$base?r=cashier", null, $cashJar);
req("$base?r=cashier", ['_csrf' => csrf($body), 'action' => 'void', 'payment_id' => (string) $p777, 'reason' => 'wrong', 'negate' => '1'], $cashJar);
check('voided with a negative O.R. No.; the number is free again', Db::val('SELECT receipt_no FROM sis_payments WHERE id = ?', [$p777]) === '-000778' && $payOr('000778', '') === 302 && (bool) Db::val("SELECT 1 FROM sis_payments WHERE receipt_no = '000778' AND status = 'POSTED'"), '');
[, $body] = req("$base?r=receipt&id=$p777&embed=1", null, $cashJar);
check('voided official receipt is marked VOID', str_contains($body, 'ofr-void') && str_contains($body, '-000778'), '');

echo "Sign-in page and its picture (grading system → School settings)\n";
$anonJar = tempnam(sys_get_temp_dir(), 'sl');
Db::q("DELETE FROM docs WHERE col = 'settings' AND id = 'signin'");
[$code] = req("$base?r=signinpic", null, $anonJar);
[, $body] = req("$base?r=login", null, $anonJar);
check('no picture: 404, and the sign-in page has no photo', $code === 404 && !str_contains($body, 'class="sl-pic"') && str_contains($body, 'Sign In') && str_contains($body, 'Remember me') && str_contains($body, 'Only authorized personnel'), "($code)");
$png = base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/h1sAAAAASUVORK5CYII='));
$pic = 'data:image/png;base64,' . $png;
$picDoc = ['parts' => 0];
foreach (str_split($pic, 30) as $i => $part) { $picDoc['image' . $i] = $part; $picDoc['parts'] = $i + 1; }
Db::q("INSERT INTO docs (col, id, data) VALUES ('settings', 'signin', ?)", [json_encode($picDoc)]);
[$code, $img] = req("$base?r=signinpic", null, $anonJar);
[, $body] = req("$base?r=login", null, $anonJar);
check('picture in pieces is joined and served; the sign-in page shows it', $code === 200 && $img === base64_decode($png) && str_contains($body, 'class="sl-pic"') && $picDoc['parts'] > 1, "($code)");
[, $body] = req("$base?r=login&portal=student", null, $anonJar);
check('student sign-in page uses the same design', str_contains($body, 'Student Portal') && str_contains($body, 'Student ID') && str_contains($body, 'class="sl-pic"'), '');
Db::q("UPDATE docs SET data = ? WHERE col = 'settings' AND id = 'signin'", [json_encode(['parts' => 1, 'image0' => 'data:text/html;base64,PHNjcmlwdD4='])]);
[$code] = req("$base?r=signinpic", null, $anonJar);
check('anything but a picture is refused', $code === 404, "($code)");
[, $staffBody] = req("$base?r=login", null, $anonJar);
[, $portalBody] = req("$base?r=login&portal=student", null, $anonJar);
[, $studentHome] = req("$base?r=my", null, login($base, (string) Db::val("SELECT username FROM sis_users WHERE role = 'STUDENT' AND must_change_password = 0 LIMIT 1"), 'Password123'));
[, $staffHome] = req("$base?r=dashboard", null, login($base, 'registrar', 'Password123'));
check('sign-in corner icon and buttons to the other systems', str_contains($staffBody, 'bi-book"></i></div>') && str_contains($staffBody, 'sw-grading') && str_contains($staffBody, 'portal=student')
    && str_contains($portalBody, 'bi-mortarboard"></i></div>') && str_contains($portalBody, 'login.html?system=grading') && str_contains($portalBody, 'sw-sis'), '');
check('color theme per system: staff green (sis), students purple (portal)', str_contains($staffBody, 'data-system="sis"') && str_contains($portalBody, 'data-system="portal"')
    && str_contains($studentHome, 'data-system="portal"') && str_contains($staffHome, 'data-system="sis"'), '');
Db::q("DELETE FROM docs WHERE col = 'settings' AND id = 'signin'");

echo "Accounting, academic terms, readmission and teachers\n";
$adminJ = login($base, 'admin', 'Admin-Pass-2026');
foreach (['ledger', 'accounts', 'mappings', 'reconcile'] as $t) {
    [$code, $body] = req("$base?r=accounting&tab=$t", null, $adminJ);
    check("accounting: $t page opens", $code === 200 && str_contains($body, 'Accounting') && !str_contains($body, 'Fatal'), "($code)");
}
[$code] = req("$base?r=accounting", null, login($base, 'cashier', 'Password123'));
check('cashier cannot open accounting', $code === 403 || $code === 302, "($code)");
Db::insert('sis_users', ['username' => 'acct', 'full_name' => 'Ana Accounting', 'password_hash' => password_hash('Password123', PASSWORD_DEFAULT), 'role' => 'ACCOUNTING']);
$acctJ = login($base, 'acct', 'Password123');
[, $body] = req("$base?r=accounting&tab=ledger", null, $acctJ);
$cash = (int) Db::val("SELECT id FROM sis_accounts WHERE code = '1010'"); $bank = (int) Db::val("SELECT id FROM sis_accounts WHERE code = '1020'");
[$code] = req("$base?r=accounting&tab=ledger", ['_csrf' => csrf($body), 'action' => 'entry', 'entry_date' => date('Y-m-d'), 'memo' => 'Deposit of collections',
    'lines' => [['account_id' => $bank, 'debit' => '1000'], ['account_id' => $cash, 'credit' => '1000']]], $acctJ);
check('accounting staff books a journal entry', $code === 302 && (bool) Db::val("SELECT 1 FROM sis_journal WHERE source = 'MANUAL' AND memo = 'Deposit of collections'"), "($code)");
check('the books stay in balance', Money::cents((string) Db::val('SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM sis_journal_lines')) === 0, '');
foreach (['trial_balance', 'income_statement', 'balance_sheet', 'ar_ledger', 'general_ledger', 'collections_by_account'] as $rep) {
    [$code, $body] = req("$base?r=reports&rep=$rep&run=1&embed=1&from=2020-01-01", null, $acctJ);
    check("report $rep runs", $code === 200 && str_contains($body, 'report-table'), "($code)");
}
[, $body] = req("$base?r=reports&rep=trial_balance&run=1&embed=1", null, $acctJ);
check('trial balance is in balance', str_contains($body, 'In balance') && str_contains($body, '<b>Yes</b>'), '');
[$code, $body] = req("$base?r=terms&embed=1", null, $adminJ);
[$code2] = req("$base?r=terms&embed=1", ['_csrf' => csrf($body), 'school_year' => '2026-2027', 'term' => 'Summer', 'enrollment_start' => '2026-04-01', 'enrollment_end' => '2026-04-30'], $adminJ);
check('academic term saved', $code === 200 && $code2 === 302 && (bool) TermCalendar::get('2026-2027', 'Summer'), "($code/$code2)");
[, $body] = req("$base?r=settings", null, $adminJ);
check('settings: terms and student portal sections', str_contains($body, 'Academic terms and deadlines') && str_contains($body, 'portal_hide_grades_unpaid'), '');
[, $body] = req("$base?r=admission&new=1", null, login($base, 'admission', 'Password123'));
check('admission form: Student ID for returnees', str_contains($body, 'name="student_number"') && str_contains($body, 'Returnee = readmission request'), '');
$someReg = (int) Db::val("SELECT id FROM sis_registrations WHERE status <> 'CANCELLED' AND locked_at IS NULL ORDER BY id DESC LIMIT 1");
[, $body] = req("$base?r=registration&id=$someReg", null, login($base, 'registrar', 'Password123'));
check('registration: Teachers sub-component', str_contains($body, 'id="mTeachers"') && str_contains($body, 'assign_teacher') || str_contains($body, 'All subjects have a teacher'), '');
[, $body] = req("$base?r=registration&new=1", null, $adminJ);
check('new registration form opens for the administrator', str_contains($body, 'New registration'), '');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
