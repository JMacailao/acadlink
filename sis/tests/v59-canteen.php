<?php
/**
 * php sis/tests/v59-canteen.php — Canteen POS: products, stock, shifts, sales, supervisor-approved voids and refunds,
 * cash counts, the shift journal entry and Finance collections. Disposable database only; never the configured one.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
require_once FINANCE_ROOT . '/lib/overview.php';
$cfg = sis_config(); $name = 'sis_v59_canteen_test';
if ($name === $cfg['db_name']) exit("Unsafe database name.\n");
$dsn = !empty($cfg['db_socket']) ? 'mysql:unix_socket=' . $cfg['db_socket'] . ';charset=utf8mb4' : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
$server = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cfg['db_name'] = $name; Db::connect($cfg);
foreach ([dirname(SIS_ROOT) . '/api/install.sql', SIS_ROOT . '/database/schema.sql', SIS_ROOT . '/database/seed.sql', SIS_ROOT . '/database/seed_accounting.sql'] as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) Db::q($stmt);
}
$c = Auth::central(); $c->install(); AccountingWorkflow::install(); DocumentRequests::install(Db::pdo()); Canteen::install(Db::pdo()); Canteen::install(Db::pdo());
$pass = 0; $fail = 0;
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); }
function test(string $label, callable $fn): void { global $pass, $fail; try { $fn(); $pass++; echo "PASS $label\n"; } catch (Throwable $e) { $fail++; echo "FAIL $label: " . $e->getMessage() . "\n"; } }
function denied(callable $fn, string $text = ''): void { try { $fn(); } catch (AppError|Forbidden $e) { if ($text !== '' && stripos($e->getMessage(), $text) === false) throw new RuntimeException('Wrong error: ' . $e->getMessage()); return; } throw new RuntimeException('Expected a refusal' . ($text ? " ($text)" : '')); }
function account(string $user, string $group, ?int $actor = null): int {
    $c = Auth::central(); $id = $c->save($actor, ['full_name' => ucwords(str_replace('.', ' ', $user)), 'username' => $user, 'group' => $group, 'status' => 'ACTIVE', 'temp_password' => 'Temporary12345'], $actor === null);
    $c->q('UPDATE central_accounts SET must_change_password=0 WHERE id=?', [$id]); $c->clearCache(); return $id;
}
function as_user(int $id): void { $c = Auth::central(); $c->clearCache(); Auth::actAs(null); $c->establish($id); Auth::actAs(null); }
$idem = fn() => bin2hex(random_bytes(16));
$power = account('can.power', 'POWER_USER'); $cashier = account('can.cashier', 'CANTEEN_CASHIER', $power); $super = account('can.super', 'CANTEEN_SUPERVISOR', $power);
$other = account('can.cashier2', 'CANTEEN_CASHIER', $power); $finCashier = account('fin.cashier', 'CASHIER', $power);
$sys = function (int $id) use ($c) { $c->clearCache(); return ModuleAccess::systems($c->permissions($id)); };

test('install is repeatable and seeds categories, accounts and mappings', function () {
    ok((int) Db::val('SELECT COUNT(*) FROM canteen_categories') === 4, 'categories');
    $map = Accounting::map();
    foreach (['canteen:cash', 'canteen:qr', 'canteen:sales', 'canteen:overshort'] as $k) ok(!empty($map[$k]), "mapping $k");
    ok(str_contains((string) Db::val("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_journal' AND column_name='source'"), "'CANTEEN'"), 'journal source');
    ok((int) Db::val("SELECT COUNT(*) FROM central_groups WHERE code IN ('CANTEEN_CASHIER','CANTEEN_SUPERVISOR')") === 2, 'groups');
});
test('workspaces: canteen staff open Canteen POS only; it is no longer "coming soon"', function () use ($sys, $cashier, $super, $finCashier, $power) {
    ok($sys($cashier) === ['CANTEEN'], 'cashier: ' . implode(',', $sys($cashier)));
    ok($sys($super) === ['CANTEEN'], 'supervisor: ' . implode(',', $sys($super)));
    ok(!in_array('CANTEEN', $sys($finCashier), true), 'Finance cashier should not get the canteen');
    ok(in_array('CANTEEN', $sys($power), true) && !in_array('CANTEEN', ModuleAccess::COMING_SOON, true), 'power user / coming soon');
});
as_user($super);
$cats = array_column(Canteen::categories(), 'id', 'name');
test('roles: canteen staff are named for their group, not Teacher', function () { ok(Auth::user()['role_name'] === 'Canteen Supervisor' && Auth::role() !== 'TEACHER', Auth::role() . ' ' . Auth::user()['role_name']); });
$meal = Canteen::saveProduct(null, ['name' => 'Chicken Rice Meal', 'category_id' => $cats['Rice Meals'], 'price' => '95', 'cost' => '60', 'barcode' => 'MEAL001', 'track_stock' => 1, 'low_stock_at' => 3, 'is_active' => 1]);
$water = Canteen::saveProduct(null, ['name' => 'Bottled Water (500 mL)', 'category_id' => $cats['Drinks'], 'price' => '20', 'cost' => '12', 'barcode' => '4800016644290', 'track_stock' => 1, 'is_active' => 1]);
$cookie = Canteen::saveProduct(null, ['name' => 'Cookie', 'category_id' => $cats['Snacks'], 'price' => '15.50', 'cost' => '0', 'track_stock' => 0, 'is_active' => 1]);
test('products: price required, barcodes unique, inactive items cannot be sold', function () use ($cats) {
    denied(fn() => Canteen::saveProduct(null, ['name' => 'Bad', 'price' => '0']), 'price');
    denied(fn() => Canteen::saveProduct(null, ['name' => 'Dup', 'price' => '5', 'barcode' => 'MEAL001']), 'barcode');
    denied(fn() => Canteen::saveProduct(null, ['name' => 'Bad code', 'price' => '5', 'barcode' => 'a b']), 'barcode');
    ok((int) Canteen::product((int) Db::val("SELECT id FROM canteen_products WHERE barcode='MEAL001'"))['price_cents'] === 9500, 'price saved in centavos');
});
test('stock: receive, prepare, adjust (with reason) and stocktake are recorded', function () use ($meal, $water) {
    ok(Canteen::addStock($meal, 'PREPARE', 10, null, 'Lunch batch') === 10, 'prepare');
    ok(Canteen::addStock($water, 'RECEIVE', 24, 1100, 'Supplier A') === 24, 'receive');
    ok((int) Canteen::product($water)['cost_cents'] === 1100, 'unit cost updated on receiving');
    denied(fn() => Canteen::adjust($water, -1, ''), 'reason');
    ok(Canteen::adjust($water, -1, 'Leaking bottle') === 23, 'adjust');
    [$n, $d] = Canteen::stocktake([$water => '22', $meal => '10'], 'Morning count');
    ok($n === 1 && $d === -1 && (int) Canteen::product($water)['stock_qty'] === 22, "stocktake $n $d");
    ok((int) Db::val("SELECT COUNT(*) FROM canteen_stock_moves") === 4, 'movements');
});
test('permissions: a canteen cashier cannot change products, stock or approve', function () use ($cashier, $water) {
    as_user($cashier);
    denied(fn() => Canteen::saveProduct(null, ['name' => 'X', 'price' => '1']));
    denied(fn() => Canteen::adjust($water, 5, 'Trying to add'));
    denied(fn() => Canteen::reviewShift(1, true, ''));
});
as_user($cashier);
test('no selling before the shift is open', function () use ($meal, $idem) { denied(fn() => Canteen::sell([[$meal, 1]], 'CASH', 10000, $idem()), 'Open your shift'); });
$shift = Canteen::startShift(200000);
test('one open shift per cashier', function () { denied(fn() => Canteen::startShift(0), 'already'); });
$s1 = $s2 = $s3 = 0;
test('cash sale: server prices, change, stock and receipt number', function () use ($meal, $water, $cookie, $idem, &$s1) {
    $k = $idem();
    $s1 = Canteen::sell([[$meal, 2], [$water, 1], [$cookie, 2], [$meal, 1]], 'CASH', 50000, $k);
    $s = Canteen::sale($s1);
    ok((int) $s['total_cents'] === 3 * 9500 + 2000 + 2 * 1550, 'total ' . $s['total_cents']);
    ok((int) $s['change_cents'] === 50000 - (int) $s['total_cents'], 'change');
    ok(preg_match('/^CN-\d{7}$/', $s['receipt_no']) === 1, 'receipt number');
    ok((int) Canteen::product($meal)['stock_qty'] === 7 && (int) Canteen::product($water)['stock_qty'] === 21, 'stock');
    ok(Canteen::sell([[$meal, 1]], 'CASH', 50000, $k) === $s1 && (int) Canteen::product($meal)['stock_qty'] === 7, 'double submit records once');
});
test('cash below total, too few in stock and unknown items are refused', function () use ($meal, $idem) {
    denied(fn() => Canteen::sell([[$meal, 1]], 'CASH', 5000, $idem()), 'less than');
    denied(fn() => Canteen::sell([[$meal, 8]], 'CASH', 100000, $idem()), 'only 7 left');
    denied(fn() => Canteen::sell([[999999, 1]], 'CASH', 100000, $idem()), 'no longer sold');
    denied(fn() => Canteen::sell([], 'CASH', 0, $idem()), 'at least one');
});
test('QR sale keeps an optional reference', function () use ($meal, $idem, &$s2) {
    $s2 = Canteen::sell([[$meal, 1]], 'QR', 0, $idem(), 'GC-12345');
    $s = Canteen::sale($s2); ok($s['method'] === 'QR' && $s['reference_no'] === 'GC-12345' && (int) $s['tendered_cents'] === 9500, 'qr');
});
$s3 = Canteen::sell([[$water, 2]], 'CASH', 4000, $idem());
test('void: needs a reason and the supervisor; a cashier cannot approve', function () use ($s3, $water, $super, $cashier, &$voidReq) {
    denied(fn() => Canteen::request($s3, 'VOID', [], 'short'), '8 characters');
    $GLOBALS['voidReq'] = Canteen::request($s3, 'VOID', [], 'Rang up the wrong drink');
    denied(fn() => Canteen::request($s3, 'REFUND', [], 'Another request now'), 'already has a request');
    denied(fn() => Canteen::decide($GLOBALS['voidReq'], true, ''));
    ok((int) Canteen::product($water)['stock_qty'] === 19, 'stock before approval');
});
test('closing is blocked while a request waits', function () use ($shift) { denied(fn() => Canteen::closeShift($shift, [100000 => 2], ''), 'waiting'); });
test('supervisor approves the void: sale voided, stock back, audited', function () use ($s3, $water, $super, $cashier) {
    as_user($super);
    Canteen::decide($GLOBALS['voidReq'], true, 'OK');
    ok(Canteen::sale($s3)['status'] === 'VOIDED', 'voided');
    ok((int) Canteen::product($water)['stock_qty'] === 21, 'stock returned');
    ok((int) Db::val("SELECT COUNT(*) FROM sis_audit_logs WHERE action='canteen_void_approved'") === 1, 'audit');
    denied(fn() => Canteen::decide($GLOBALS['voidReq'], true, ''), 'already decided');
    as_user($cashier);
});
test('refund: part of a sale, paid from the open shift, stock back', function () use ($s1, $meal, $super, $cashier) {
    $items = array_column(Canteen::sale($s1)['items'], 'id', 'product_id');
    denied(fn() => Canteen::request($s1, 'REFUND', [$items[$meal] => 4], 'Too many refunded'), 'at most 3');
    $req = Canteen::request($s1, 'REFUND', [$items[$meal] => 1], 'Customer found a hair in the meal');
    as_user($super);
    denied(fn() => Canteen::decide($req, false, ''), 'why');
    Canteen::decide($req, true, 'Refunded in cash');
    $s = Canteen::sale($s1);
    ok((int) $s['refunded_cents'] === 9500 && (int) $s['items'][0]['refunded_qty'] === 1, 'refunded');
    ok((int) Canteen::product($meal)['stock_qty'] === 7, 'stock back after refund');
    as_user($cashier);
    denied(fn() => Canteen::request($s1, 'VOID', [], 'Void after refund'), 'refund the rest');
});
test('shift totals: expected cash = opening + cash sales − refunds', function () use ($shift) {
    $t = Canteen::shiftTotals($shift);
    ok($t['cash'] === 33600 && $t['qr'] === 9500 && $t['refunds'] === 9500 && $t['voided'] === 4000, json_encode($t));
    ok($t['expected'] === 200000 + 33600 - 9500, 'expected ' . $t['expected']);
});
test('cash count closes the shift; denominations add up; another cashier cannot close it', function () use ($shift, $other) {
    as_user($other); denied(fn() => Canteen::closeShift($shift, [], '')); as_user(Auth::centralId() ? (int) Db::val("SELECT account_id FROM central_login_identifiers WHERE identifier='can.cashier'") : 0);
    // expected 224100: count 224000 (₱1 short)
    $r = Canteen::closeShift($shift, [100000 => 2, 20000 => 1, 2000 => 2, 25 => 0, 100 => 0], 'one peso short?');
    ok($r['counted'] === 224000 && $r['expected'] === 224100 && $r['difference'] === -100, json_encode($r));
    denied(fn() => Canteen::sell([[1, 1]], 'CASH', 10000, bin2hex(random_bytes(16))), 'Open your shift');
});
test('supervisor: a short count needs a note; return sends it back; accept books one journal for Finance', function () use ($shift, $super, $cashier) {
    as_user($super);
    denied(fn() => Canteen::reviewShift($shift, true, ''), 'short');
    Canteen::reviewShift($shift, false, 'Count the coins again');
    ok(Canteen::shift($shift)['status'] === 'OPEN', 'returned');
    as_user($cashier); Canteen::closeShift($shift, [100000 => 2, 20000 => 1, 2000 => 2], 'Recounted, still short');
    as_user($super);
    $j = Canteen::reviewShift($shift, true, 'Short ₱1 accepted');
    $e = Db::one('SELECT * FROM sis_journal WHERE id = ?', [$j]);
    ok($e['source'] === 'CANTEEN' && $e['status'] === 'REVIEW' && (int) $e['source_id'] === $shift, 'journal header');
    $lines = Db::all('SELECT a.code, l.debit, l.credit FROM sis_journal_lines l JOIN sis_accounts a ON a.id = l.account_id WHERE l.journal_id = ? ORDER BY a.code', [$j]);
    $by = []; foreach ($lines as $l) $by[$l['code']] = [Money::cents($l['debit']), Money::cents($l['credit'])];
    ok($by['1010'] === [24000, 0], 'cash in = counted − opening: ' . json_encode($by));
    ok($by['1030'] === [9500, 0], 'qr');
    ok($by['4600'] === [0, 33600], 'net sales = 43100 − 9500');
    ok($by['5600'] === [100, 0], 'short ₱1');
    ok(array_sum(array_column($by, 0)) === array_sum(array_column($by, 1)), 'balanced');
    ok(Canteen::shift($shift)['status'] === 'ACCEPTED' && (int) Canteen::shift($shift)['journal_id'] === $j, 'accepted');
    denied(fn() => Canteen::reviewShift($shift, true, ''), 'closed shift');
});
test('Finance collections show canteen net sales', function () {
    $r = array_column(FinanceOverview::collections(date('Y-m-d'), date('Y-m-d'))['rows'], null, 'key');
    ok($r['CANTEEN']['ready'] && $r['CANTEEN']['cents'] === 43100 - 9500 && $r['CANTEEN']['count'] === 2, json_encode($r['CANTEEN']));
});
test('reports: gross, refunds, net and profit by product', function () use ($meal) {
    $r = Canteen::report(date('Y-m-d'), date('Y-m-d'));
    ok($r['gross'] === 43100 && $r['refunds'] === 9500 && $r['net'] === 33600 && $r['count'] === 2, json_encode([$r['gross'], $r['refunds'], $r['net'], $r['count']]));
    $p = array_column($r['byProduct'], null, 'product_id');
    ok((int) $p[$meal]['qty'] === 3 && (int) $p[$meal]['t'] === 28500 && (int) $p[$meal]['cost'] === 18000, json_encode($p[$meal]));
    ok((int) $r['voided']['n'] === 1, 'voided count');
});
test('settings: only images are accepted for the QR code', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'qr'); file_put_contents($tmp, 'not an image');
    denied(fn() => Canteen::saveSettings(['name' => 'X'], ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 12], false), 'PNG');
    $png = tempnam(sys_get_temp_dir(), 'qr'); $im = imagecreatetruecolor(4, 4); imagepng($im, $png);
    Canteen::saveSettings(['name' => 'Rivermere Canteen', 'qr_label' => 'Scan me'], ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'size' => filesize($png)], false);
    $qr = Canteen::image('QR', 0);
    ok($qr && $qr[0] === 'image/png' && str_starts_with($qr[1], "\x89PNG") && Canteen::setting('name') === 'Rivermere Canteen', 'QR saved as PNG bytes');
    ok(Db::val("SELECT COUNT(*) FROM canteen_settings WHERE svalue LIKE 'data:%'") == 0, 'no base64 text kept');
    Canteen::saveSettings(['name' => 'Rivermere Canteen'], null, true); ok(Canteen::image('QR', 0) === null, 'QR removed');
});
test('product photos are stored as bytes, large ones shrunk, and replaced or removed', function () use ($meal) {
    $f = tempnam(sys_get_temp_dir(), 'ph'); $im = imagecreatetruecolor(1600, 1200); imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 40)); imagejpeg($im, $f, 90);
    Canteen::saveProduct($meal, ['name' => 'Chicken Rice Meal', 'price' => '95', 'cost' => '60', 'barcode' => 'MEAL001', 'track_stock' => 1, 'is_active' => 1], ['error' => UPLOAD_ERR_OK, 'tmp_name' => $f, 'size' => filesize($f)]);
    $row = Db::one("SELECT mime, width, height, size_bytes, LENGTH(bytes) len, sha1, SHA1(bytes) real_sha FROM canteen_images WHERE owner_type='PRODUCT' AND owner_id=?", [$meal]);
    ok($row['mime'] === 'image/jpeg' && (int) $row['width'] === 800 && (int) $row['height'] === 600, 'shrunk to 800x600: ' . json_encode($row));
    ok((int) $row['len'] === (int) $row['size_bytes'] && $row['sha1'] === $row['real_sha'], 'bytes stored intact');
    $img = Canteen::image('PRODUCT', $meal); ok(str_starts_with($img[1], "\xFF\xD8\xFF") && strlen($img[1]) === (int) $row['len'], 'read back as JPEG bytes');
    ok(Canteen::product($meal)['image_ver'] === substr($row['sha1'], 0, 10), 'version tag');
    $g = tempnam(sys_get_temp_dir(), 'ph'); $im = imagecreatetruecolor(60, 40); imagepng($im, $g);
    Canteen::saveProduct($meal, ['name' => 'Chicken Rice Meal', 'price' => '95', 'cost' => '60', 'barcode' => 'MEAL001', 'track_stock' => 1, 'is_active' => 1], ['error' => UPLOAD_ERR_OK, 'tmp_name' => $g, 'size' => filesize($g)]);
    ok((int) Db::val("SELECT COUNT(*) FROM canteen_images WHERE owner_type='PRODUCT' AND owner_id=?", [$meal]) === 1 && Canteen::image('PRODUCT', $meal)[1] === file_get_contents($g), 'replaced; small images kept byte for byte');
    Canteen::saveProduct($meal, ['name' => 'Chicken Rice Meal', 'price' => '95', 'cost' => '60', 'barcode' => 'MEAL001', 'track_stock' => 1, 'is_active' => 1, 'remove_image' => 1]);
    ok(Canteen::image('PRODUCT', $meal) === null && Canteen::product($meal)['image_ver'] === null, 'removed');
    $big = tempnam(sys_get_temp_dir(), 'ph'); file_put_contents($big, str_repeat('x', 10));
    denied(fn() => Canteen::saveProduct($meal, ['name' => 'Chicken Rice Meal', 'price' => '95'], ['error' => UPLOAD_ERR_OK, 'tmp_name' => $big, 'size' => 6000000]), '5 MB');
});
$c->logout(); $server->exec("DROP DATABASE IF EXISTS `$name`");
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
