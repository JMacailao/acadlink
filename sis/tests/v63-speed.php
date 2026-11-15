<?php
/**
 * php sis/tests/v63-speed.php — v63.21 speed fixes, on a throwaway database:
 *  - sis_upgrade() stores the schema version, so a new session costs one query instead of ~50 table checks;
 *  - table checks on every request ask the table itself (no information_schema) and are remembered;
 *  - the Admission list loads its document checklists in one query (it ran ~10 queries per applicant);
 *  - file routes (photos, covers, LMS files): session released, long caching only for an exact version, 304 support.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
$cfg = sis_config(); $name = 'sis_v6321_test'; $root = dirname(SIS_ROOT);
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $server->exec("USE `$name`");
$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents("$root/INSTALL.sql"));
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $stmt) { $s = $server->query($stmt); while ($s->nextRowset()) {} $s->closeCursor(); }
$cfg['db_name'] = $name; Db::connect($cfg);
$pass = 0; $fail = 0;
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; } }
test('sis_upgrade() stores the schema version (a new session then costs one query)', function () {
    sis_upgrade(); $c = Auth::central(); $c->install(); $pdo = Db::pdo();
    AccountEmail::install(); Canteen::install($pdo); Library::install($pdo); Lms::install($pdo); Notify::install($pdo); StudentPhoto::install($pdo); Privacy::install($pdo); ParentAccess::install($pdo);
    ok((string) Db::val("SELECT svalue FROM sis_settings WHERE skey = 'schema_version'") === (string) SIS_SCHEMA_VERSION, 'schema_version not stored');
    ok((bool) Db::val("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sis_registrations' AND index_name = 'k_sis_reg_student'"), 'student index missing');
    $src = (string) file_get_contents(SIS_ROOT . '/app/bootstrap.php');
    ok(str_contains($src, "skey = 'schema_version'") && str_contains($src, '$done >= SIS_SCHEMA_VERSION'), 'web requests do not read the stored version first');
});
test('table checks ask the table itself, remember a yes, and say no for a missing table', function () {
    ok(Db::tableExists('sis_settings') && Db::tableExists('sis_settings'), 'existing table');
    ok(!Db::tableExists('no_such_table_v6321'), 'missing table');
    $c = Auth::central(); ok($c->installed(), 'central accounts installed');
    // no information_schema left in the request-time checks of central accounts
    $src = (string) file_get_contents(dirname(SIS_ROOT) . '/shared/central-accounts.php');
    foreach (['public function installed', 'public function temporaryExpired', 'public function securityPolicy'] as $fn) {
        $at = strpos($src, $fn); ok($at !== false, "$fn not found");
        $body = substr($src, $at, (int) strpos($src, "\n    }", $at) - $at); ok(!str_contains($body, 'information_schema'), "$fn still uses information_schema");
    }
});
test('Admission list: checklists of all applicants in one query, same result as one by one', function () {
    $actor = (int) Db::val('SELECT MIN(id) FROM sis_users'); if (!$actor) { Db::q("INSERT INTO sis_users (username, full_name, password_hash, role) VALUES ('speed.test','Speed Test',?, 'ADMIN')", [password_hash('x', PASSWORD_DEFAULT)]); $actor = (int) Db::val('SELECT MIN(id) FROM sis_users'); }
    $reqs = EnrollmentDocs::forType('NEW'); ok(count($reqs) >= 2, 'requirements for new students');
    $ids = [];
    for ($i = 1; $i <= 30; $i++) {
        Db::q("INSERT INTO sis_admissions (admission_no, applicant_type, last_name, first_name, program, year_level, school_year, status, created_by) VALUES (?, 'NEW', ?, 'Speed', 'BSIT', '1st Year', '2026-2027', 'PENDING', ?)", ['SPD-' . $i, 'Tester' . $i, $actor]);
        $id = (int) Db::pdo()->lastInsertId(); $ids[] = $id;
        foreach (array_slice($reqs, 0, $i % (count($reqs) + 1)) as $r) Db::q('INSERT INTO sis_admission_documents (admission_id, requirement_id, received_by) VALUES (?, ?, ?)', [$id, $r['id'], $actor]);
    }
    $have = EnrollmentDocs::submittedFor($ids);
    foreach ($ids as $id) { $a = Db::one('SELECT * FROM sis_admissions WHERE id = ?', [$id]); ok(EnrollmentDocs::progress($a, $have[$id]) === EnrollmentDocs::progress($a), "different result for admission $id"); }
    // the requirement list is read once per request (it was read again for every row)
    $q0 = (int) Db::pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
    foreach ($ids as $id) EnrollmentDocs::progress(['id' => $id, 'applicant_type' => 'NEW'], $have[$id]);
    $q1 = (int) Db::pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
    ok($q1 - $q0 <= 2, 'progress() for 30 applicants sent ' . ($q1 - $q0 - 1) . ' queries (expected none)');
    // a change to the requirements is seen right away (the remembered list is dropped)
    $before = count(EnrollmentDocs::requirements());
    Auth::actAs($actor);
    try { EnrollmentDocs::saveRequirement(null, ['name' => 'Speed Test Paper', 'applies_to' => ['NEW'], 'is_required' => 1, 'is_active' => 1]); } catch (Forbidden $e) { Db::q("INSERT INTO sis_document_requirements (name, applies_to, is_required, sort_order) VALUES ('Speed Test Paper','NEW',1,99)"); EnrollmentDocs::forget(); }
    Auth::actAs(null);
    ok(count(EnrollmentDocs::requirements()) === $before + 1, 'new requirement not seen');
    $src = (string) file_get_contents(SIS_ROOT . '/app/pages/admission.php');
    ok(str_contains($src, 'EnrollmentDocs::submittedFor(') && str_contains($src, 'progress($r, $docsHave'), 'Admission list does not preload checklists');
    ok(str_contains($src, 'GROUP BY a.status, a.applicant_type') && str_contains($src, 'RecordList::window($matching)') && str_contains($src, "' OFFSET ' . \$win['offset']"), 'Admission counters are not counted in the database, or the list is not paged by the database'); // v64.9
});
test('file routes: session released, year-long caching only for the exact version, 304 for a known copy', function () use ($root) {
    $bytes = random_bytes(500); $sha = sha1($bytes);
    $_GET['v'] = substr($sha, 0, 10); ok(BinaryResponse::versionMatches($bytes), 'right tag');
    $_GET['v'] = 'deadbeef00'; ok(!BinaryResponse::versionMatches($bytes), 'wrong tag cached for a year');
    $_GET['v'] = substr($sha, 0, 4); ok(!BinaryResponse::versionMatches($bytes), 'too short a tag');
    $_GET['v'] = '<script>'; ok(!BinaryResponse::versionMatches($bytes), 'not hexadecimal');
    unset($_GET['v']); ok(!BinaryResponse::versionMatches($bytes), 'no tag');
    foreach (['canteen/pages/image.php', 'library/pages/cover.php', 'sis/app/pages/photo.php', 'lms/pages/file.php'] as $f) {
        $s = (string) file_get_contents("$root/$f");
        ok(str_contains($s, 'BinaryResponse::begin()') && str_contains($s, 'BinaryResponse::send('), "$f does not use BinaryResponse");
        ok(strpos($s, 'BinaryResponse::begin()') < strpos($s, 'BinaryResponse::send('), "$f begins after sending");
    }
    $boot = (string) file_get_contents(SIS_ROOT . '/app/bootstrap.php'); ok(str_contains($boot, 'BinaryResponse::active() || !Auth::id()'), 'after-page jobs still run for files');
    $lazy = ['sis/app/lib/student-photo.php' => 'loading="lazy"', 'library/lib/library.php' => 'loading="lazy"', 'canteen/pages/products.php' => 'loading="lazy"', 'canteen/assets/pos.js' => "img.loading = 'lazy'"];
    foreach ($lazy as $f => $needle) ok(str_contains((string) file_get_contents("$root/$f"), $needle), "$f: photos are not lazy-loaded");
    // v63.23: a script must mark the image lazy BEFORE giving it an address, or the browser downloads it at once
    preg_match_all('/img\.(loading|src) = [^;]+;/', (string) file_get_contents("$root/canteen/assets/pos.js"), $m);
    for ($i = 0; $i + 1 < count($m[1]); $i += 2) ok($m[1][$i] === 'loading' && $m[1][$i + 1] === 'src', 'pos.js sets src before loading = lazy');
    ok(count($m[1]) >= 4, 'pos.js photo code not found');
});

test('request cache: a repeated read is remembered, and any write drops it', function () {
    $n = 0; $load = function () use (&$n) { $n++; return Db::val('SELECT COUNT(*) FROM sis_settings'); };
    $a = Db::memo('t.count', $load); $b = Db::memo('t.count', $load); ok($n === 1 && $a === $b, 'read twice');
    Db::q("INSERT INTO sis_settings (skey, svalue) VALUES ('speed_test_key', '1') ON DUPLICATE KEY UPDATE svalue = '2'");
    $c = Db::memo('t.count', $load); ok($n === 2, 'not dropped after a write'); ok((int) $c >= (int) $a, 'stale value');
    Db::changed(); Db::memo('t.count', $load); ok($n === 3, 'Db::changed() does not drop it');
    // Grading System lists: a new section is seen in the same request
    $before = count(GS::all('sections'));
    Db::q("INSERT INTO docs (col, id, data) VALUES ('sections', 'speedtestsection', ?)", [json_encode(['schoolYear' => '2026-2027', 'yearLevel' => '1st Year', 'sectionName' => 'SPEED 1A'])]);
    ok(count(GS::all('sections')) === $before + 1, 'GS::all() stale after a write');
    ok(in_array('2026-2027', GS::schoolYears(), true), 'school years stale');
});
test('signed-in user built once per request, rebuilt after an account change', function () {
    $c = Auth::central(); $owner = (int) $c->q("SELECT account_id FROM central_login_identifiers WHERE identifier_key = 'power.admin'")->fetchColumn();
    $legacy = (int) $c->legacyId($owner, 'SIS'); ok($legacy > 0, 'power.admin has no SIS link');
    Auth::actAs($legacy); $q0 = (int) Db::pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
    for ($i = 0; $i < 50; $i++) Auth::user();
    $q1 = (int) Db::pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
    ok($q1 - $q0 <= 3, '50 Auth::user() calls sent ' . ($q1 - $q0 - 1) . ' queries (was 2 each)');
    $name = (string) Auth::user()['full_name'];
    Db::q('UPDATE sis_users SET full_name = ? WHERE id = ?', ['Speed Renamed', $legacy]);
    ok((string) Auth::user()['full_name'] === 'Speed Renamed', 'user not rebuilt after a change'); Db::q('UPDATE sis_users SET full_name = ? WHERE id = ?', [$name, $legacy]);
    for ($i = 0; $i < 20; $i++) $c->legacyId($owner, 'SIS');
    $q2 = (int) Db::pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1]; ok($q2 - $q1 <= 6, 'legacyId not remembered');
    Auth::actAs(null);
});
test('sign-in check: "last seen" written at most once a minute; temporary-password check only when one is set', function () {
    $src = (string) file_get_contents(dirname(SIS_ROOT) . '/shared/central-accounts.php');
    $at = strpos($src, 'public function current(): ?array'); $body = substr($src, $at, (int) strpos($src, "\n    }", $at) - $at);
    ok(str_contains($body, "(int)\$s['seen_ago'] >= 60") && str_contains($body, "(int)\$s['must_change_password'] && \$this->temporaryExpired"), 'current() not throttled');
    ok(str_contains($body, 's.revoked_at IS NULL') && str_contains($body, 's.account_auth_version=a.auth_version') && str_contains($body, 's.policy_version=p.policy_version'), 'revocation no longer checked on every request');
});

$server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
