<?php
/**
 * Grading restore vs. SIS identities:  php sis/tests/grading-restore-identity.php
 * Uses a throwaway database on the server in api/config.php (never the configured one) and runs the real
 * Grading restore (begin → upload → finish) from a private copy of the API files.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$live = require $root . '/api/config.php';
$name = 'acadlink_restore_test_' . bin2hex(random_bytes(4));
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $live['db_host'], (int) $live['db_port']), $live['db_user'], $live['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$tmp = sys_get_temp_dir() . '/' . $name;
register_shutdown_function(function () use ($server, $name, $tmp) {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    foreach (['api/config.php', 'api/lib.php', 'api/grading-restore.php'] as $f) @unlink("$tmp/$f");
    @unlink("$tmp/shared"); @rmdir("$tmp/api"); @rmdir($tmp);
});
// A private API copy: the same code, pointed at the throwaway database.
mkdir("$tmp/api", 0700, true);
copy("$root/api/lib.php", "$tmp/api/lib.php");
copy("$root/api/grading-restore.php", "$tmp/api/grading-restore.php");
symlink("$root/shared", "$tmp/shared");
file_put_contents("$tmp/api/config.php", '<?php return ' . var_export(array_replace($live, ['db_name' => $name]), true) . ';');
$_SESSION = [];
require "$tmp/api/lib.php";
require "$tmp/api/grading-restore.php";

$db = pdo();
$db->exec((string) file_get_contents("$root/INSTALL.sql"));
$power = (int) $db->query('SELECT id FROM central_accounts ORDER BY id LIMIT 1')->fetchColumn();
centralAccounts()->setPassword($power, 'Testing123', false, $power);
centralAccounts()->establish($power);

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void { global $passed, $failed; try { $fn(); $passed++; echo "PASS $name\n"; } catch (Throwable $e) { $failed++; echo "FAIL $name: " . $e->getMessage() . "\n"; } }
function expect(bool $ok, string $msg): void { if (!$ok) throw new RuntimeException($msg); }
function doc(string $col, string $id): ?array { $s = pdo()->prepare('SELECT data FROM docs WHERE col=? AND id=?'); $s->execute([$col, $id]); $v = $s->fetchColumn(); return $v === false ? null : json_decode($v, true); }
function put(string $col, string $id, array $data): void { pdo()->prepare('INSERT INTO docs(col,id,data) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)')->execute([$col, $id, json_encode($data)]); }
function registration(string $doc, string $number, string $name, string $term = '1st Semester'): void {
    static $n = 0; $n++;
    pdo()->prepare("INSERT INTO sis_registrations(reg_no,student_number,student_doc_id,student_name,school_year,term,year_level,section_doc_id,section_name,created_by) VALUES(?,?,?,?,'2026-2027',?,'1st Year','secA','BSIT 1A',1)")->execute(['REG-T-' . $n, $number, $doc, $name, $term]);
}
function student(string $number, string $name): array { return ['studentId' => $number, 'studentName' => $name, 'email' => '', 'sectionId' => 'secA', 'sectionName' => 'BSIT 1A', 'schoolYear' => '2026-2027', 'yearLevel' => '1st Year']; }
function grade(string $doc, string $number, string $name, $final = 90): array { return ['studentId' => $doc, 'studentNumber' => $number, 'studentName' => $name, 'sectionId' => 'secA', 'subjectId' => 'subj1', 'term' => '1st Semester', 'schoolYear' => '2026-2027', 'finalGrade' => $final, 'remarks' => is_numeric($final) && $final >= 75 ? 'Passed' : 'Failed']; }
/** Runs the real staged restore and returns its result. */
function restore(array $records, string $mode = 'merge'): array {
    $counts = [];
    foreach ($records as [$col]) $counts[$col] = ($counts[$col] ?? 0) + 1;
    $begin = restoreBegin(json_decode(json_encode($counts)), 1, $mode);
    restoreUpload($begin['token'], 0, json_decode(json_encode(array_map(fn($r) => ['collection' => $r[0], 'id' => $r[1], 'data' => $r[2]], $records))));
    $GLOBALS['lastToken'] = $begin['token'];
    $ctx = makeContext();
    return restoreFinish($ctx, $begin['token'], $mode === 'replace' ? 'RESTORE' : '');
}
function freshLive(): void {
    pdo()->exec("DELETE FROM docs WHERE col IN ('sections','subjects','students','grades')");
    pdo()->exec('DELETE FROM sis_registrations');
    put('sections', 'secA', ['sectionName' => 'BSIT 1A', 'schoolYear' => '2026-2027', 'yearLevel' => '1st Year']);
    put('subjects', 'subj1', ['subjectCode' => 'IT101', 'subjectName' => 'Intro', 'units' => 3]);
    // Case A: SIS renumbered stuA after the backup was taken.
    put('students', 'stuA', student('NEW-001', 'Alpha, Ann'));
    registration('stuA', 'NEW-001', 'Alpha, Ann');
    // Case B: the backup's number TAKEN-002 now belongs to a different live record, stuY.
    put('students', 'stuY', student('TAKEN-002', 'Young, Yna'));
    registration('stuY', 'TAKEN-002', 'Young, Yna');
}
$backup = fn() => [
    ['sections', 'secA', ['sectionName' => 'BSIT 1A', 'schoolYear' => '2026-2027', 'yearLevel' => '1st Year']],
    ['subjects', 'subj1', ['subjectCode' => 'IT101', 'subjectName' => 'Intro', 'units' => 3]],
    ['students', 'stuA', student('OLD-001', 'Alpha, Ann')],
    ['grades', 'gA', grade('stuA', 'OLD-001', 'Alpha, Ann')],
    ['students', 'stuB', student('TAKEN-002', 'Bravo, Ben')],
    ['grades', 'gB', grade('stuB', 'TAKEN-002', 'Bravo, Ben')],
    ['students', 'stuC', student('CLEAN-003', 'Charlie, Cy')],
    ['grades', 'gC', grade('stuC', 'CLEAN-003', 'Charlie, Cy')],
];

foreach (['merge', 'replace'] as $mode) {
    freshLive();
    $result = null;
    test("$mode: identity conflicts no longer stop the whole restore", function () use (&$result, $backup, $mode) { $result = restore($backup(), $mode); expect(($result['state'] ?? '') === 'COMMITTED', 'restore did not commit'); });
    if (!$result) continue;
    test("$mode: renumbered student is restored with the SIS Student ID", function () { expect(doc('students', 'stuA')['studentId'] === 'NEW-001', 'stuA has ' . json_encode(doc('students', 'stuA')['studentId'] ?? null)); });
    test("$mode: that student's grade carries the SIS Student ID too", function () { expect(doc('grades', 'gA')['studentNumber'] === 'NEW-001', 'gA has ' . json_encode(doc('grades', 'gA')['studentNumber'] ?? null)); });
    test("$mode: report lists the corrected Student ID", function () use (&$result) {
        $row = $result['identity_adjusted_list'][0] ?? [];
        expect(($result['identity_adjusted'] ?? 0) === 1 && $row['id'] === 'stuA' && $row['backup'] === 'OLD-001' && $row['live'] === 'NEW-001', json_encode($result['identity_adjusted_list'] ?? null));
    });
    test("$mode: student whose number belongs to another SIS record is skipped, not merged", function () { expect(doc('students', 'stuB') === null, 'stuB was restored'); });
    test("$mode: the live SIS student keeps its record", function () { $y = doc('students', 'stuY'); expect($y && $y['studentId'] === 'TAKEN-002' && $y['studentName'] === 'Young, Yna', 'stuY changed: ' . json_encode($y)); });
    test("$mode: grades of a skipped student are held back, not orphaned", function () { expect(doc('grades', 'gB') === null, 'gB was restored for a missing student'); });
    test("$mode: report lists the skipped student and held records", function () use (&$result) {
        $row = $result['skipped_list'][0] ?? [];
        expect(($result['skipped_students'] ?? 0) === 1 && $row['id'] === 'stuB' && $row['backup'] === 'TAKEN-002' && $row['owner'] === 'stuY' && ($result['held_records'] ?? 0) === 1, json_encode([$result['skipped_list'] ?? null, $result['held_records'] ?? null]));
    });
    test("$mode: other records restore normally", function () { expect(doc('students', 'stuC')['studentId'] === 'CLEAN-003' && doc('grades', 'gC')['finalGrade'] === 90, 'clean records missing'); });
    test("$mode: Check restore result returns the same report", function () use (&$result) {
        $again = restoreFinish(makeContext(), $GLOBALS['lastToken'], $GLOBALS['mode'] ?? '');
        expect(($again['identity_adjusted'] ?? null) === $result['identity_adjusted'] && ($again['skipped_list'] ?? null) == $result['skipped_list'], 'retry differs: ' . json_encode($again));
    });
}

freshLive();
// Live stuD exists unlinked with LIVE-004; the backup gives it TAKEN-002, which SIS assigns to stuY.
put('students', 'stuD', student('LIVE-004', 'Delta, Dee'));
// SIS itself has TWIN-005 on two records; the backup's stuE (linked to TWIN-005) must not roll back the restore.
put('students', 'stuE', student('TWIN-005', 'Echo, Eve')); registration('stuE', 'TWIN-005', 'Echo, Eve'); registration('stuZ', 'TWIN-005', 'Zed, Zoe', '2nd Semester');
$edge = null;
test('edge cases still commit', function () use (&$edge) {
    $edge = restore([
        ['students', 'stuD', student('TAKEN-002', 'Delta, Dee')], ['grades', 'gD', grade('stuD', 'TAKEN-002', 'Delta, Dee')],
        ['students', 'stuE', student('TWIN-005', 'Echo, Eve')], ['grades', 'gE', grade('stuE', 'TWIN-005', 'Echo, Eve')],
    ]);
    expect(($edge['state'] ?? '') === 'COMMITTED', 'not committed');
});
test('skipped student that exists live keeps its record; its grades carry the live Student ID', function () {
    expect(doc('students', 'stuD')['studentId'] === 'LIVE-004', 'stuD changed');
    expect((doc('grades', 'gD')['studentNumber'] ?? null) === 'LIVE-004', 'gD has ' . json_encode(doc('grades', 'gD')['studentNumber'] ?? null));
});
test('a Student ID duplicated inside SIS is skipped and reported, not rolled back', function () use (&$edge) {
    $ids = array_column($edge['skipped_list'] ?? [], 'id');
    expect(in_array('stuE', $ids, true) && in_array('stuD', $ids, true), json_encode($edge['skipped_list'] ?? null));
});

freshLive();
test('an invalid grade still rolls back everything, including identity fixes', function () use ($backup) {
    $records = $backup(); $records[] = ['grades', 'gBad', grade('stuC', 'CLEAN-003', 'Charlie, Cy', 'not-a-grade')];
    try { restore($records); throw new RuntimeException('restore committed'); }
    catch (ApiError $e) { expect(str_contains($e->getMessage(), 'Nothing has been restored'), $e->getMessage()); }
    expect(doc('students', 'stuA')['studentId'] === 'NEW-001' && doc('students', 'stuC') === null && doc('grades', 'gA') === null, 'live data changed after a failed restore');
});

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
