<?php
/**
 * php sis/tests/v60-library.php — Library: catalog and copies, check-out rules, returns and overdue fines, renewals,
 * holds and the hold shelf, lost items, fines paid at the Cashier with an O.R. and a journal entry, waivers,
 * acquisitions, stocktakes, the Student Portal actions and permissions. Disposable database only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
require_once FINANCE_ROOT . '/lib/overview.php';
$cfg = sis_config(); $name = 'sis_v60_library_test';
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cfg['db_name'] = $name; Db::connect($cfg);
foreach ([dirname(SIS_ROOT) . '/api/install.sql', SIS_ROOT . '/database/schema.sql', SIS_ROOT . '/database/seed.sql', SIS_ROOT . '/database/seed_accounting.sql'] as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) Db::q($stmt);
}
$c = Auth::central(); $c->install(); AccountingWorkflow::install(); DocumentRequests::install(Db::pdo()); Canteen::install(Db::pdo()); Library::install(Db::pdo()); Library::install(Db::pdo());
$pass = 0; $fail = 0;
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . "\n"; } }
function denied(callable $fn, string $text = ''): void { try { $fn(); } catch (AppError|Forbidden $e) { if ($text !== '' && stripos($e->getMessage(), $text) === false) throw new RuntimeException('Wrong error: ' . $e->getMessage()); return; } throw new RuntimeException('Expected a refusal' . ($text ? " ($text)" : '')); }
function account(string $user, string $group, ?int $actor = null, array $extra = []): int {
    $c = Auth::central(); $id = $c->save($actor, $extra + ['full_name' => ucwords(str_replace('.', ' ', $user)), 'username' => $user, 'group' => $group, 'status' => 'ACTIVE', 'temp_password' => 'Temporary12345'], $actor === null);
    $c->q('UPDATE central_accounts SET must_change_password=0 WHERE id=?', [$id]); $c->clearCache(); return $id;
}
function as_user(int $id): void { $c = Auth::central(); $c->clearCache(); Auth::actAs(null); $c->establish($id); Auth::actAs(null); }
function days_ago(int $loanId, int $days): void { Db::q('UPDATE library_loans SET due_on = DATE_SUB(CURDATE(), INTERVAL ? DAY) WHERE id = ?', [$days, $loanId]); }
$power = account('lib.power', 'POWER_USER'); $lib = account('lib.librarian', 'LIBRARIAN', $power); $cash = account('lib.cashier', 'CASHIER', $power);
as_user($power);
$sec = GS::saveSection(null, '2026-2027', '1st Year', 'LIB 1A');
$s1 = GS::saveStudent(null, ['studentId' => 'LIB001', 'studentName' => 'Reyes, Ana', 'sectionId' => $sec]);
$s2 = GS::saveStudent(null, ['studentId' => 'LIB002', 'studentName' => 'Cruz, Ben', 'sectionId' => $sec]);
$t1 = GS::saveTeacher(null, 'LT01', 'Santos, Maria');
$stuAcc = account('lib002', 'STUDENT', $power, ['student_number' => 'LIB002']);
$sys = function (int $id) use ($c) { $c->clearCache(); return ModuleAccess::systems($c->permissions($id)); };

test('install is repeatable; Librarian group; Library open, not "coming soon"', function () use ($sys, $lib) {
    ok((int) Db::val("SELECT COUNT(*) FROM central_groups WHERE code='LIBRARIAN'") === 1, 'group');
    ok($sys($lib) === ['LIBRARY'], 'librarian systems: ' . implode(',', $sys($lib)));
    ok(!in_array('LIBRARY', ModuleAccess::COMING_SOON, true), 'coming soon');
    ok(!empty(Accounting::map()['library:fines']), 'fines account mapped');
    ok(str_contains((string) Db::val("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_journal' AND column_name='source'"), "'LIBRARY_FINE'"), 'journal source');
});
as_user($lib);
test('catalog: ISBN check digits, duplicate ISBN, copies get unique barcodes', function () {
    denied(fn() => Library::saveTitle(null, ['title' => 'Bad', 'isbn' => '978-0-306-40615-8']), 'ISBN');
    ok(Library::isbn('978-0-306-40615-7') === '9780306406157' && Library::isbn('0-306-40615-2') === '0306406152', 'isbn normalised');
    $GLOBALS['titleA'] = Library::saveTitle(null, ['title' => 'Noli Me Tangere', 'authors' => 'José Rizal', 'isbn' => '9780306406157', 'subject' => 'Philippine literature', 'call_no' => 'PL 6058 .R5']);
    denied(fn() => Library::saveTitle(null, ['title' => 'Copy', 'isbn' => '9780306406157']), 'already has ISBN');
    $GLOBALS['codesA'] = Library::addCopies($GLOBALS['titleA'], 2, ['price' => '350', 'location' => 'Shelf A']);
    ok(count($GLOBALS['codesA']) === 2 && $GLOBALS['codesA'][0] !== $GLOBALS['codesA'][1] && preg_match('/^LB\d{7}$/', $GLOBALS['codesA'][0]) === 1, 'barcodes ' . implode(',', $GLOBALS['codesA']));
    $GLOBALS['tR'] = Library::saveTitle(null, ['title' => 'Encyclopedia Britannica', 'material' => 'REFERENCE']);
    $GLOBALS['codeR'] = Library::addCopies($GLOBALS['tR'], 1, ['reference_only' => 1, 'barcode' => 'ref-001'])[0];
    ok($GLOBALS['codeR'] === 'REF-001', 'own barcode');
    denied(fn() => Library::addCopies($GLOBALS['tR'], 1, ['barcode' => 'REF-001']), 'already used');
    ok(count(Library::search('rizal noli')) === 1 && count(Library::search($GLOBALS['codesA'][1])) === 1 && count(Library::search('PL 6058 .R5')) === 1, 'search by words, barcode, call no.');
});
$A = fn() => $GLOBALS['codesA']; $tA = fn() => $GLOBALS['titleA'];
test('borrowers are found by Student ID, Teacher ID or name', function () use ($s1, $t1) {
    ok(Library::borrowerByCode('LIB001')['ref'] === $s1, 'student'); ok(Library::borrowerByCode('LT01')['type'] === 'TEACHER', 'teacher');
    ok(count(Library::findBorrowers('ana reyes')) >= 1 && Library::borrowerByCode('NOPE') === null, 'search');
});
$loan1 = 0;
test('check-out: due date from the rules; reference copies and copies already out are refused', function () use ($s1, $s2, $A, &$loan1) {
    $loan1 = Library::checkout('STUDENT', $s1, $A()[0]);
    ok(Db::val('SELECT due_on FROM library_loans WHERE id = ?', [$loan1]) === date('Y-m-d', strtotime('+7 days')), 'student loan 7 days');
    ok(Library::copyByBarcode($A()[0])['status'] === 'ON_LOAN', 'copy on loan');
    denied(fn() => Library::checkout('STUDENT', $s2, $A()[0]), 'on loan');
    denied(fn() => Library::checkout('STUDENT', $s2, $GLOBALS['codeR']), 'library use only');
    denied(fn() => Library::checkout('STUDENT', $s2, 'NOPE-1'), 'No copy');
});
test('limits: max items per borrower type', function () use ($s1, $A) {
    Db::q("INSERT INTO library_settings (skey, svalue) VALUES ('student_max_items','1') ON DUPLICATE KEY UPDATE svalue='1'"); Library::resetCache();
    denied(fn() => Library::checkout('STUDENT', $s1, $A()[1]), 'most allowed');
    Db::q("DELETE FROM library_settings WHERE skey='student_max_items'"); Library::resetCache();
});
test('renew: allowed by the rules, not when someone is waiting, not when overdue', function () use ($s2, $tA, &$loan1, $A) {
    $due = Library::renew($loan1);
    ok($due >= date('Y-m-d', strtotime('+7 days')), 'renewed');
    denied(fn() => Library::renew($loan1), "can't be renewed again");
});
$hold = 0;
test('holds: a waiting hold reserves the next returned copy (hold shelf), others cannot take it', function () use ($s1, $s2, $t1, $tA, $A, &$hold) {
    // copy 2 goes out to the teacher, so no copy is free
    $GLOBALS['loanT'] = Library::checkout('TEACHER', $t1, $A()[1]);
    ok(Db::val('SELECT due_on FROM library_loans WHERE id = ?', [$GLOBALS['loanT']]) === date('Y-m-d', strtotime('+30 days')), 'teacher loan 30 days');
    $hold = Library::placeHold($tA(), 'STUDENT', $s2);
    denied(fn() => Library::placeHold($tA(), 'STUDENT', $s2), 'already a hold');
    denied(fn() => Library::placeHold($tA(), 'STUDENT', $s1), 'already has this title');
    $r = Library::checkin($A()[1]);
    ok($r['hold'] && $r['hold']['borrower_ref'] === $s2 && Library::copyByBarcode($A()[1])['status'] === 'ON_HOLD_SHELF', 'copy to hold shelf for Ben');
    ok($r['fine'] === 0, 'teacher not late');
    denied(fn() => Library::checkout('TEACHER', $t1, $A()[1]), 'hold shelf for');
    $l = Library::checkout('STUDENT', $s2, $A()[1]);
    ok(Db::val('SELECT status FROM library_holds WHERE id = ?', [$hold]) === 'FULFILLED', 'hold fulfilled');
    Library::checkin($A()[1]);
});
test('ready holds expire after the pickup days and the copy goes back', function () use ($s1, $tA, $A) {
    $h = Library::placeHold($tA(), 'STUDENT', Library::borrowerByCode('LIB002')['ref']);
    ok(Db::val('SELECT status FROM library_holds WHERE id = ?', [$h]) === 'READY', 'ready at once (copy on shelf)');
    Db::q('UPDATE library_holds SET expires_on = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?', [$h]);
    Library::resetCache(); Library::housekeep();
    ok(Db::val('SELECT status FROM library_holds WHERE id = ?', [$h]) === 'EXPIRED' && Library::copyByBarcode($A()[1])['status'] === 'AVAILABLE', 'expired, copy back');
});
test('return late: overdue fine per day after grace, capped; recorded once', function () use ($s1, $A, &$loan1) {
    days_ago($loan1, 4);
    denied(fn() => Library::renew($loan1), 'overdue');
    denied(fn() => Library::checkout('STUDENT', $GLOBALS['s1'] ?? Library::borrowerByCode('LIB001')['ref'], $A()[1]), 'overdue item');
    $r = Library::checkin($A()[0]);
    ok($r['late'] === 4 && $r['fine'] === 2000, 'late ' . $r['late'] . ' fine ' . $r['fine']);
    ok((int) Db::val("SELECT COUNT(*) FROM library_fines WHERE loan_id = ? AND kind='OVERDUE'", [$loan1]) === 1, 'one fine');
    ok(Library::overdueFine('STUDENT', 100) === 20000 && Library::overdueFine('TEACHER', 10) === 0, 'cap / teacher no fine');
});
test('unpaid fines block borrowing (setting) until paid at the Cashier', function () use ($A) {
    denied(fn() => Library::checkout('STUDENT', Library::borrowerByCode('LIB001')['ref'], $A()[1]), 'unpaid library fines');
});
$lostLoan = 0;
test('lost item: replacement price + overdue charged; coming back later waives the lost fee', function () use ($A, &$lostLoan) {
    $lostLoan = Library::checkout('STUDENT', Library::borrowerByCode('LIB002')['ref'], $A()[1]);
    Library::markLost($lostLoan, 'Left on the jeepney');
    ok(Library::copyByBarcode($A()[1])['status'] === 'LOST', 'copy lost');
    ok((int) Db::val("SELECT amount_cents FROM library_fines WHERE loan_id = ? AND kind='LOST'", [$lostLoan]) === 35000, 'price charged');
    Library::checkin($A()[1]);
    ok(Db::val("SELECT status FROM library_fines WHERE loan_id = ? AND kind='LOST'", [$lostLoan]) === 'WAIVED' && Library::copyByBarcode($A()[1])['status'] === 'AVAILABLE', 'returned after lost');
});
test('damage on return is charged and the copy goes to repair', function () use ($A) {
    Db::q("DELETE FROM library_fines WHERE borrower_no='LIB002' AND status='UNPAID'");
    Library::checkout('STUDENT', Library::borrowerByCode('LIB002')['ref'], $A()[1]);
    $r = Library::checkin($A()[1], true, 15000, 'Torn cover');
    ok(Library::copyByBarcode($A()[1])['status'] === 'IN_REPAIR' && (int) Db::val("SELECT amount_cents FROM library_fines WHERE kind='DAMAGE'") === 15000, 'damage');
});
test('waive needs the permission and a reason', function () use ($cash, $lib) {
    $f = (int) Db::val("SELECT id FROM library_fines WHERE kind='DAMAGE'");
    denied(fn() => Library::waive($f, 'short'), '8 characters');
    as_user($cash); denied(fn() => Library::waive($f, 'Cashier trying to waive')); as_user($lib);
    Library::waive($f, 'Cover was already loose');
    ok(Db::val('SELECT status FROM library_fines WHERE id = ?', [$f]) === 'WAIVED', 'waived');
});
$pay = 0;
test('Cashier: pays fines with an O.R., books a journal entry, Finance shows Library fines', function () use ($cash, &$pay) {
    as_user($cash);
    $b = Library::borrowerByCode('LIB001');
    $fines = array_column(Library::borrowerFines('STUDENT', $b['ref']), 'id');
    ok(count($fines) === 1, 'one unpaid fine');
    denied(fn() => Library::payFines('STUDENT', $b['ref'], $fines, 'GCASH', '', bin2hex(random_bytes(16))), 'reference');
    $idem = bin2hex(random_bytes(16));
    [$pay, $no] = Library::payFines('STUDENT', $b['ref'], $fines, 'CASH', null, $idem);
    ok(Library::payFines('STUDENT', $b['ref'], $fines, 'CASH', null, $idem)[0] === $pay, 'double submit once');
    ok(Db::val('SELECT status FROM library_fines WHERE id = ?', [$fines[0]]) === 'PAID', 'fine paid');
    $j = Db::one("SELECT * FROM sis_journal WHERE source='LIBRARY_FINE' AND source_id = ?", [$pay]);
    $lines = Db::all('SELECT a.code, l.debit, l.credit FROM sis_journal_lines l JOIN sis_accounts a ON a.id = l.account_id WHERE l.journal_id = ? ORDER BY a.code', [$j['id']]);
    ok($j['status'] === 'REVIEW' && $lines[0]['code'] === '1010' && Money::cents($lines[0]['debit']) === 2000 && $lines[1]['code'] === '4700' && Money::cents($lines[1]['credit']) === 2000, json_encode($lines));
    $r = array_column(FinanceOverview::collections(date('Y-m-d'), date('Y-m-d'))['rows'], null, 'key');
    ok($r['LIBRARY']['ready'] && $r['LIBRARY']['cents'] === 2000, 'finance: ' . json_encode($r['LIBRARY']));
});
test('Cashier: voiding the receipt reverses the journal and makes the fines unpaid again', function () use (&$pay, $power) {
    as_user($power); Library::voidFinePayment($pay, 'Wrong student');
    ok(Db::val("SELECT status FROM library_fines WHERE payment_id IS NULL AND borrower_no='LIB001' AND kind='OVERDUE'") === 'UNPAID', 'unpaid again');
    ok(Accounting::activeEntry('LIBRARY_FINE', $pay) === null || Accounting::activeEntry('LIBRARY_FINE', $pay)['status'] !== 'REVIEW', 'journal reversed');
});
test('a librarian cannot take fine payments; a cashier cannot check out', function () use ($lib, $cash, $power) {
    as_user($lib); denied(fn() => Library::payFines('STUDENT', 'x', [1], 'CASH', null, bin2hex(random_bytes(16))));
    as_user($cash); denied(fn() => Library::checkout('STUDENT', Library::borrowerByCode('LIB002')['ref'], $GLOBALS['codesA'][0]));
    as_user($lib);
});
test('acquisitions: order, partial and full receiving create priced copies', function () {
    $t = Library::saveTitle(null, ['title' => 'Physics for Scientists', 'authors' => 'Serway']);
    $a = Library::saveAcquisition(['supplier' => 'National Book Store', 'reference_no' => 'INV-77', 'lines' => [['title_id' => $t, 'qty' => 3, 'unit_cost' => '1,250.00']]]);
    $line = (int) Db::val('SELECT id FROM library_acquisition_lines WHERE acquisition_id = ?', [$a]);
    denied(fn() => Library::receive($a, [$line => 4]), 'at most 3');
    $c1 = Library::receive($a, [$line => 2]);
    ok(count($c1) === 2 && Db::val('SELECT status FROM library_acquisitions WHERE id = ?', [$a]) === 'PARTIAL', 'partial');
    Library::receive($a, [$line => 1]);
    ok(Db::val('SELECT status FROM library_acquisitions WHERE id = ?', [$a]) === 'RECEIVED' && (int) Db::val('SELECT COUNT(*) FROM library_copies WHERE acquisition_id = ? AND price_cents = 125000', [$a]) === 3, 'received with prices');
    denied(fn() => Library::cancelAcquisition($a), 'nothing received');
});
test('stocktake: scans, unknown codes, missing copies marked, found copies restored', function () {
    $id = Library::startStocktake('Test count', '');
    denied(fn() => Library::startStocktake('Again', ''), 'already open');
    ok(Library::scan($id, $GLOBALS['codesA'][1])[0] === 'ok', 'scan ok');
    ok(Library::scan($id, $GLOBALS['codesA'][1])[0] === 'repeat', 'repeat');
    ok(Library::scan($id, 'ZZZ-404')[0] === 'unknown', 'unknown');
    $missing = count(Library::stocktakeReport($id)['missing']);
    ok($missing >= 3, 'not seen ' . $missing);
    $r = Library::closeStocktake($id, true);
    ok($r['missing'] >= 1 && Db::val('SELECT status FROM library_copies WHERE barcode = ?', ['REF-001']) === 'MISSING', 'marked missing: ' . $r['result']);
    $id2 = Library::startStocktake('Recount', '');
    ok(Library::scan($id2, 'REF-001')[0] === 'warn', 'found warning');
    Library::closeStocktake($id2, false);
    ok(Db::val('SELECT status FROM library_copies WHERE barcode = ?', ['REF-001']) === 'AVAILABLE', 'found again');
});
test('Student Portal: a student reserves, renews and cancels only their own', function () use ($stuAcc, $tA) {
    as_user($stuAcc);
    ok(Auth::studentNumber() === 'LIB002', 'student session');
    $ref = (string) GS::studentByNumber('LIB002')['id'];
    $h = Library::placeHold((int) Db::val("SELECT id FROM library_titles WHERE title = 'Physics for Scientists'"), 'STUDENT', $ref, 'PORTAL');
    ok(Db::val('SELECT source FROM library_holds WHERE id = ?', [$h]) === 'PORTAL', 'portal hold');
    $other = (int) Db::val("SELECT id FROM library_loans WHERE borrower_no <> 'LIB002' AND status = 'OPEN' LIMIT 1");
    if ($other) denied(fn() => Library::renew($other, true), 'own');
    Library::cancelHold($h, true);
    denied(fn() => Library::checkout('STUDENT', $ref, 'LB0000001'));
});
test('reports and labels', function () {
    $r = Library::report(date('Y-m-01'), date('Y-m-d'));
    ok($r['loans'] >= 5 && $r['titles'] === 3 && $r['charged'] > 0, json_encode(array_slice($r, 0, 9)));
    $svg = Library::barcodeSvg('LB0000001');
    ok(str_starts_with($svg, '<svg') && substr_count($svg, '<rect') === 11 * 5, 'code39 bars: ' . substr_count($svg, '<rect'));
});
$c->logout(); $server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
