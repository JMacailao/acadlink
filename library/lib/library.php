<?php
declare(strict_types=1);

/**
 * v60 Library: catalog (titles and individual copies with barcodes), check-out, returns, renewals, holds,
 * overdue tracking and notices, fines charged to the borrower (paid at the SIS/Finance Cashier with an O.R.),
 * acquisitions that create copies, and shelf stocktakes. Borrowers are AcadLink students and teachers.
 * Amounts are whole centavos.
 */
final class Library
{
    public const COPY_STATUS = ['AVAILABLE' => 'Available', 'ON_LOAN' => 'On loan', 'ON_HOLD_SHELF' => 'On hold shelf', 'IN_REPAIR' => 'In repair',
        'MISSING' => 'Missing', 'LOST' => 'Lost', 'WITHDRAWN' => 'Withdrawn'];
    public const FINE_KINDS = ['OVERDUE' => 'Overdue', 'LOST' => 'Lost item', 'DAMAGE' => 'Damage'];
    public const BORROWER_TYPES = ['STUDENT' => 'Student', 'TEACHER' => 'Teacher'];
    public const DEFAULTS = [
        'student_loan_days' => 7, 'student_max_items' => 3, 'student_max_renewals' => 1, 'student_fine_cents' => 500,
        'teacher_loan_days' => 30, 'teacher_max_items' => 10, 'teacher_max_renewals' => 3, 'teacher_fine_cents' => 0,
        'grace_days' => 0, 'max_fine_cents' => 20000, 'lost_fee_cents' => 50000, 'hold_pickup_days' => 3, 'max_holds' => 3, 'block_with_fines' => 1,
    ];

    // ------------------------------------------------------------------ install

    public static function install(PDO $db): void
    {
        $t = fn(string $sql) => $db->exec($sql . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $t("CREATE TABLE IF NOT EXISTS library_titles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, subtitle VARCHAR(255) NULL, authors VARCHAR(255) NOT NULL DEFAULT '',
            isbn VARCHAR(20) NULL, publisher VARCHAR(150) NULL, pub_year SMALLINT NULL, edition VARCHAR(40) NULL, subject VARCHAR(120) NULL,
            call_no VARCHAR(60) NULL, language VARCHAR(40) NULL, material ENUM('BOOK','REFERENCE','PERIODICAL','THESIS','AV','OTHER') NOT NULL DEFAULT 'BOOK',
            description TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY k_lib_title_isbn (isbn), FULLTEXT KEY ft_lib_title (title, subtitle, authors, subject)
        )");
        $t("CREATE TABLE IF NOT EXISTS library_acquisitions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, supplier VARCHAR(150) NOT NULL, reference_no VARCHAR(60) NULL, ordered_on DATE NOT NULL,
            status ENUM('ORDERED','PARTIAL','RECEIVED','CANCELLED') NOT NULL DEFAULT 'ORDERED', notes VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $t("CREATE TABLE IF NOT EXISTS library_acquisition_lines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, acquisition_id INT UNSIGNED NOT NULL, title_id INT UNSIGNED NOT NULL,
            qty SMALLINT UNSIGNED NOT NULL, received_qty SMALLINT UNSIGNED NOT NULL DEFAULT 0, unit_cost_cents BIGINT NOT NULL DEFAULT 0,
            KEY k_lib_acq_line (acquisition_id), CONSTRAINT fk_lib_acq_line FOREIGN KEY (acquisition_id) REFERENCES library_acquisitions (id),
            CONSTRAINT fk_lib_acq_title FOREIGN KEY (title_id) REFERENCES library_titles (id), CONSTRAINT ck_lib_acq_qty CHECK (qty > 0 AND received_qty <= qty)
        )");
        $t("CREATE TABLE IF NOT EXISTS library_copies (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title_id INT UNSIGNED NOT NULL, barcode VARCHAR(40) NOT NULL,
            status ENUM('AVAILABLE','ON_LOAN','ON_HOLD_SHELF','IN_REPAIR','MISSING','LOST','WITHDRAWN') NOT NULL DEFAULT 'AVAILABLE',
            reference_only TINYINT(1) NOT NULL DEFAULT 0, location VARCHAR(80) NULL, price_cents BIGINT NOT NULL DEFAULT 0,
            acquisition_id INT UNSIGNED NULL, acquired_on DATE NULL, notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_lib_copy_barcode (barcode), KEY k_lib_copy_title (title_id), KEY k_lib_copy_status (status),
            CONSTRAINT fk_lib_copy_title FOREIGN KEY (title_id) REFERENCES library_titles (id)
        )");
        $t("CREATE TABLE IF NOT EXISTS library_loans (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, copy_id INT UNSIGNED NOT NULL,
            borrower_type ENUM('STUDENT','TEACHER') NOT NULL, borrower_ref VARCHAR(191) NOT NULL, borrower_no VARCHAR(40) NOT NULL, borrower_name VARCHAR(150) NOT NULL,
            loaned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, due_on DATE NOT NULL, returned_at DATETIME NULL, renewals TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('OPEN','RETURNED','LOST') NOT NULL DEFAULT 'OPEN', loaned_by INT UNSIGNED NULL, returned_by INT UNSIGNED NULL, notes VARCHAR(255) NULL,
            open_guard INT UNSIGNED GENERATED ALWAYS AS (IF(status = 'OPEN', copy_id, NULL)) STORED,
            UNIQUE KEY u_lib_loan_open_copy (open_guard), KEY k_lib_loan_borrower (borrower_type, borrower_ref), KEY k_lib_loan_due (status, due_on),
            CONSTRAINT fk_lib_loan_copy FOREIGN KEY (copy_id) REFERENCES library_copies (id)
        )");
        $t("CREATE TABLE IF NOT EXISTS library_holds (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title_id INT UNSIGNED NOT NULL,
            borrower_type ENUM('STUDENT','TEACHER') NOT NULL, borrower_ref VARCHAR(191) NOT NULL, borrower_no VARCHAR(40) NOT NULL, borrower_name VARCHAR(150) NOT NULL,
            placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, placed_by INT UNSIGNED NULL, source ENUM('DESK','PORTAL') NOT NULL DEFAULT 'DESK',
            status ENUM('WAITING','READY','FULFILLED','CANCELLED','EXPIRED') NOT NULL DEFAULT 'WAITING', copy_id INT UNSIGNED NULL,
            ready_at DATETIME NULL, expires_on DATE NULL, closed_at DATETIME NULL,
            KEY k_lib_hold_title (title_id, status), KEY k_lib_hold_borrower (borrower_type, borrower_ref, status),
            CONSTRAINT fk_lib_hold_title FOREIGN KEY (title_id) REFERENCES library_titles (id)
        )");
        $t("CREATE TABLE IF NOT EXISTS library_fines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, loan_id INT UNSIGNED NULL, kind ENUM('OVERDUE','LOST','DAMAGE') NOT NULL,
            borrower_type ENUM('STUDENT','TEACHER') NOT NULL, borrower_ref VARCHAR(191) NOT NULL, borrower_no VARCHAR(40) NOT NULL, borrower_name VARCHAR(150) NOT NULL,
            amount_cents BIGINT NOT NULL, description VARCHAR(255) NOT NULL, status ENUM('UNPAID','PAID','WAIVED') NOT NULL DEFAULT 'UNPAID',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INT UNSIGNED NULL, payment_id INT UNSIGNED NULL,
            waived_by INT UNSIGNED NULL, waived_at DATETIME NULL, waive_reason VARCHAR(255) NULL,
            KEY k_lib_fine_borrower (borrower_type, borrower_ref, status), KEY k_lib_fine_status (status), CONSTRAINT ck_lib_fine_amount CHECK (amount_cents > 0)
        )");
        $t("CREATE TABLE IF NOT EXISTS library_fine_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, receipt_no VARCHAR(30) NOT NULL, series_id INT UNSIGNED NULL, idempotency_key CHAR(32) NOT NULL,
            borrower_type ENUM('STUDENT','TEACHER') NOT NULL, borrower_ref VARCHAR(191) NOT NULL, borrower_no VARCHAR(40) NOT NULL, borrower_name VARCHAR(150) NOT NULL,
            amount_cents BIGINT NOT NULL, method ENUM('CASH','GCASH','MAYA','BANK','CARD','CHECK') NOT NULL DEFAULT 'CASH', reference_no VARCHAR(60) NULL,
            status ENUM('POSTED','VOIDED') NOT NULL DEFAULT 'POSTED', received_by INT UNSIGNED NOT NULL, paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voided_by INT UNSIGNED NULL, voided_at DATETIME NULL, void_reason VARCHAR(255) NULL,
            UNIQUE KEY u_lib_pay_receipt (receipt_no), UNIQUE KEY u_lib_pay_idem (idempotency_key), KEY k_lib_pay_date (paid_at), CONSTRAINT ck_lib_pay_amount CHECK (amount_cents > 0)
        )");
        $t("CREATE TABLE IF NOT EXISTS library_stocktakes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, location VARCHAR(80) NULL,
            status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN', started_by INT UNSIGNED NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_by INT UNSIGNED NULL, closed_at DATETIME NULL, result VARCHAR(500) NULL
        )");
        $t("CREATE TABLE IF NOT EXISTS library_stocktake_scans (
            stocktake_id INT UNSIGNED NOT NULL, copy_id INT UNSIGNED NOT NULL, scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, scanned_by INT UNSIGNED NULL,
            PRIMARY KEY (stocktake_id, copy_id), CONSTRAINT fk_lib_scan_take FOREIGN KEY (stocktake_id) REFERENCES library_stocktakes (id),
            CONSTRAINT fk_lib_scan_copy FOREIGN KEY (copy_id) REFERENCES library_copies (id)
        )");
        $t("CREATE TABLE IF NOT EXISTS library_settings (skey VARCHAR(40) NOT NULL PRIMARY KEY, svalue VARCHAR(255) NOT NULL)");
        $has = fn(string $tb) => (bool) $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$tb'")->fetchColumn();
        if ($has('sis_accounts') && $has('sis_account_map')) {
            $db->exec("INSERT IGNORE INTO sis_accounts (code, name, type, is_cash, description) VALUES ('4700','Library Fines','REVENUE',0,'Overdue, lost and damage fines collected at the Cashier')");
            $db->exec("INSERT IGNORE INTO sis_account_map (map_key, account_id) SELECT 'library:fines', id FROM sis_accounts WHERE code = '4700'");
        }
        if ($has('sis_journal')) {
            $type = (string) $db->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_journal' AND column_name='source'")->fetchColumn();
            if ($type !== '' && !str_contains($type, "'LIBRARY_FINE'")) {
                preg_match_all("/'([A-Z_]+)'/", $type, $m);
                $db->exec("ALTER TABLE sis_journal MODIFY source ENUM('" . implode("','", array_unique(array_merge($m[1], ['LIBRARY_FINE']))) . "') NOT NULL");
            }
        }
    }

    // ------------------------------------------------------------------ settings (loan rules)

    private static ?array $rules = null;
    private static bool $housekept = false;

    public static function rule(string $key): int
    {
        if (self::$rules === null) { self::$rules = []; try { self::$rules = array_map('intval', array_column(Db::all('SELECT skey, svalue FROM library_settings'), 'svalue', 'skey')); } catch (Throwable) {} }
        return self::$rules[$key] ?? (int) (self::DEFAULTS[$key] ?? 0);
    }

    /** Forget the cached loan rules and the once-per-request hold check (after saving rules; in tests). */
    public static function resetCache(): void { self::$rules = null; self::$housekept = false; }

    public static function rules(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) $out[$k] = self::rule($k);
        return $out;
    }

    public static function saveRules(array $in): void
    {
        Auth::require('library.settings');
        $vals = [];
        foreach (self::DEFAULTS as $k => $_) {
            if (!array_key_exists($k, $in)) continue;
            $raw = trim((string) $in[$k]);
            $v = str_ends_with($k, '_cents') ? Money::cents($raw === '' ? '0' : $raw) : ($k === 'block_with_fines' ? (int) !empty($in[$k]) : (ctype_digit($raw) ? (int) $raw : -1));
            if ($v < 0 || $v > 100000000) throw new AppError('Check the loan rules: use whole numbers (days, items) and peso amounts.');
            if (str_ends_with($k, '_loan_days') && ($v < 1 || $v > 365)) throw new AppError('Loan days must be between 1 and 365.');
            if (str_ends_with($k, '_max_items') && $v > 100) throw new AppError('At most 100 items per borrower.');
            $vals[$k] = $v;
        }
        if (!isset($in['block_with_fines'])) $vals['block_with_fines'] = 0;
        Db::tx(function () use ($vals) {
            foreach ($vals as $k => $v) Db::q('INSERT INTO library_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$k, (string) $v]);
            Audit::log('library_rules_saved', 'library_settings', null, $vals);
        });
        self::resetCache();
    }

    private static function typeRule(string $type, string $what): int { return self::rule(strtolower($type) . '_' . $what); }

    // ------------------------------------------------------------------ borrowers (AcadLink students and teachers)

    /** A borrower by exact Student ID or Teacher ID (what is printed on the ID card), or null. */
    public static function borrowerByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') return null;
        if ($s = GS::studentByNumber($code)) return ['type' => 'STUDENT', 'ref' => (string) $s['id'], 'no' => (string) $s['studentId'], 'name' => (string) ($s['studentName'] ?? ''), 'detail' => trim(($s['sectionName'] ?? '') . ' · ' . ($s['schoolYear'] ?? ''), ' ·')];
        $t = Db::one("SELECT id, data FROM docs WHERE col = 'teachers' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.teacherId')) = ? LIMIT 1", [$code]);
        if ($t) { $d = json_decode((string) $t['data'], true) ?: []; return ['type' => 'TEACHER', 'ref' => (string) $t['id'], 'no' => (string) ($d['teacherId'] ?? ''), 'name' => (string) ($d['teacherName'] ?? ''), 'detail' => 'Teacher']; }
        return null;
    }

    public static function borrower(string $type, string $ref): ?array
    {
        if (!isset(self::BORROWER_TYPES[$type])) return null;
        $d = GS::get($type === 'STUDENT' ? 'students' : 'teachers', $ref);
        if (!$d) return null;
        return $type === 'STUDENT'
            ? ['type' => 'STUDENT', 'ref' => $ref, 'no' => (string) ($d['studentId'] ?? ''), 'name' => (string) ($d['studentName'] ?? ''), 'detail' => trim(($d['sectionName'] ?? '') . ' · ' . ($d['schoolYear'] ?? ''), ' ·')]
            : ['type' => 'TEACHER', 'ref' => $ref, 'no' => (string) ($d['teacherId'] ?? ''), 'name' => (string) ($d['teacherName'] ?? ''), 'detail' => 'Teacher'];
    }

    /** Borrowers whose name or ID contains $q. */
    public static function findBorrowers(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $out = array_map(fn($s) => ['type' => 'STUDENT', 'ref' => (string) $s['id'], 'no' => (string) ($s['studentId'] ?? ''), 'name' => (string) ($s['studentName'] ?? ''), 'detail' => (string) ($s['sectionName'] ?? '')], GS::findStudents($q, $limit));
        $like = '%' . mb_strtolower($q) . '%';
        foreach (Db::all("SELECT id, data FROM docs WHERE col = 'teachers' AND (LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, '$.teacherName'))) LIKE ? OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, '$.teacherId'))) LIKE ?) LIMIT " . $limit, [$like, $like]) as $t) {
            $d = json_decode((string) $t['data'], true) ?: [];
            $out[] = ['type' => 'TEACHER', 'ref' => (string) $t['id'], 'no' => (string) ($d['teacherId'] ?? ''), 'name' => (string) ($d['teacherName'] ?? ''), 'detail' => 'Teacher'];
        }
        return array_slice($out, 0, $limit);
    }

    /** Loans, holds, fines and limits of one borrower. */
    public static function account(string $type, string $ref): array
    {
        self::housekeep();
        $loans = Db::all("SELECT l.*, c.barcode, t.title, t.authors, t.id AS title_id, DATEDIFF(CURDATE(), l.due_on) AS days_late FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id
            WHERE l.borrower_type = ? AND l.borrower_ref = ? AND l.status = 'OPEN' ORDER BY l.due_on", [$type, $ref]);
        $holds = Db::all("SELECT h.*, t.title, c.barcode FROM library_holds h JOIN library_titles t ON t.id = h.title_id LEFT JOIN library_copies c ON c.id = h.copy_id
            WHERE h.borrower_type = ? AND h.borrower_ref = ? AND h.status IN ('WAITING','READY') ORDER BY h.status = 'READY' DESC, h.id", [$type, $ref]);
        $fines = Db::all("SELECT * FROM library_fines WHERE borrower_type = ? AND borrower_ref = ? AND status = 'UNPAID' ORDER BY id", [$type, $ref]);
        foreach ($loans as &$l) $l['accruing'] = self::overdueFine($type, (int) $l['days_late']);
        unset($l);
        return ['loans' => $loans, 'holds' => $holds, 'fines' => $fines, 'fines_cents' => array_sum(array_map(fn($f) => (int) $f['amount_cents'], $fines)),
            'max_items' => self::typeRule($type, 'max_items'), 'loan_days' => self::typeRule($type, 'loan_days')];
    }

    // ------------------------------------------------------------------ catalog

    public static function saveTitle(?int $id, array $in): int
    {
        Auth::require('library.catalog.edit');
        $row = [
            'title' => In::str($in, 'title', 255, true, 'Title'), 'subtitle' => In::str($in, 'subtitle', 255), 'authors' => (string) In::str($in, 'authors', 255),
            'isbn' => self::isbn((string) ($in['isbn'] ?? '')), 'publisher' => In::str($in, 'publisher', 150),
            'pub_year' => ($y = trim((string) ($in['pub_year'] ?? ''))) === '' ? null : (preg_match('/^\d{4}$/', $y) && (int) $y >= 1400 && (int) $y <= (int) date('Y') + 1 ? (int) $y : throw new AppError('Enter the year as four digits, e.g. 2021.')),
            'edition' => In::str($in, 'edition', 40), 'subject' => In::str($in, 'subject', 120), 'call_no' => In::str($in, 'call_no', 60), 'language' => In::str($in, 'language', 40),
            'material' => in_array($in['material'] ?? '', ['BOOK', 'REFERENCE', 'PERIODICAL', 'THESIS', 'AV', 'OTHER'], true) ? $in['material'] : 'BOOK',
            'description' => ($d = trim((string) ($in['description'] ?? ''))) === '' ? null : mb_substr($d, 0, 4000),
        ];
        return Db::tx(function () use ($id, $row) {
            if ($row['isbn'] && Db::val('SELECT id FROM library_titles WHERE isbn = ? AND id <> ?', [$row['isbn'], (int) $id])) throw new AppError('Another title already has ISBN ' . $row['isbn'] . '. Add copies to that title instead.');
            if ($id) {
                if (!Db::val('SELECT 1 FROM library_titles WHERE id = ?', [$id])) throw new AppError('Title not found.');
                Db::q('UPDATE library_titles SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
                Audit::log('library_title_saved', 'library_title', $id, ['title' => $row['title']]);
            } else {
                $id = Db::insert('library_titles', $row);
                Audit::log('library_title_added', 'library_title', $id, ['title' => $row['title'], 'isbn' => $row['isbn']]);
            }
            return (int) $id;
        });
    }

    /** ISBN-10 or ISBN-13 (dashes and spaces removed, check digit verified), or null when blank. */
    public static function isbn(string $raw): ?string
    {
        $s = strtoupper(preg_replace('/[\s-]+/', '', $raw));
        if ($s === '') return null;
        if (preg_match('/^\d{9}[\dX]$/', $s)) {
            $sum = 0; for ($i = 0; $i < 10; $i++) $sum += ($s[$i] === 'X' ? 10 : (int) $s[$i]) * (10 - $i);
            if ($sum % 11 === 0) return $s;
        } elseif (preg_match('/^\d{13}$/', $s)) {
            $sum = 0; for ($i = 0; $i < 12; $i++) $sum += (int) $s[$i] * ($i % 2 ? 3 : 1);
            if ((10 - $sum % 10) % 10 === (int) $s[12]) return $s;
        }
        throw new AppError('That ISBN is not valid. Check the digits (10 or 13).');
    }

    public static function title(int $id): ?array
    {
        $t = Db::one('SELECT * FROM library_titles WHERE id = ?', [$id]);
        if ($t) {
            $t['copies'] = Db::all("SELECT c.*, l.borrower_name, l.due_on FROM library_copies c LEFT JOIN library_loans l ON l.copy_id = c.id AND l.status = 'OPEN' WHERE c.title_id = ? ORDER BY c.id", [$id]);
            $t['holds'] = Db::all("SELECT * FROM library_holds WHERE title_id = ? AND status IN ('WAITING','READY') ORDER BY id", [$id]);
        }
        return $t;
    }

    /** Catalog search: words in title, author or subject, an ISBN, a call number or a copy barcode. */
    public static function search(string $q, string $material = '', int $limit = 60, bool $onlyAvailable = false): array
    {
        $q = trim($q); $w = []; $p = [];
        if ($q !== '') {
            $isbn = strtoupper(preg_replace('/[\s-]+/', '', $q));
            $or = ['t.isbn = ?', 't.call_no = ?', 'EXISTS (SELECT 1 FROM library_copies x WHERE x.title_id = t.id AND x.barcode = ?)'];
            array_push($p, $isbn, $q, $q);
            $words = array_filter(preg_split('/\s+/', mb_strtolower($q)) ?: [], fn($x) => $x !== '');
            $and = [];
            foreach ($words as $word) { $and[] = "LOWER(CONCAT_WS(' ', t.title, t.subtitle, t.authors, t.subject, t.publisher)) LIKE ?"; $p[] = '%' . $word . '%'; }
            if ($and) $or[] = '(' . implode(' AND ', $and) . ')';
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        if ($material !== '') { $w[] = 't.material = ?'; $p[] = $material; }
        $rows = Db::all("SELECT t.*, COUNT(c.id) AS copies, SUM(c.status = 'AVAILABLE' AND c.reference_only = 0) AS available, SUM(c.status = 'AVAILABLE' AND c.reference_only = 1) AS reference_copies,
                (SELECT COUNT(*) FROM library_holds h WHERE h.title_id = t.id AND h.status = 'WAITING') AS waiting
            FROM library_titles t LEFT JOIN library_copies c ON c.title_id = t.id AND c.status NOT IN ('WITHDRAWN','LOST')"
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' GROUP BY t.id ORDER BY t.title LIMIT ' . max(1, min(500, $limit)), $p);
        return $onlyAvailable ? array_values(array_filter($rows, fn($r) => (int) $r['available'] > 0)) : $rows;
    }

    /** Add $count copies of a title with new barcodes (LB0000001…). Returns the barcodes. */
    public static function addCopies(int $titleId, int $count, array $in = [], ?int $acquisitionId = null): array
    {
        Auth::require('library.catalog.edit', 'library.acquire');
        if ($count < 1 || $count > 200) throw new AppError('Add between 1 and 200 copies at a time.');
        $price = Money::cents((string) ($in['price'] ?? '0'));
        if ($price < 0) throw new AppError('The price can\'t be negative.');
        $own = trim((string) ($in['barcode'] ?? ''));
        if ($own !== '' && $count !== 1) throw new AppError('Type your own barcode only when adding one copy.');
        if ($own !== '' && !preg_match('/^[A-Z0-9\-.]{3,40}$/', strtoupper($own))) throw new AppError('A barcode may only have letters, numbers, dashes and dots.');
        return Db::tx(function () use ($titleId, $count, $in, $acquisitionId, $price, $own) {
            if (!Db::val('SELECT 1 FROM library_titles WHERE id = ?', [$titleId])) throw new AppError('Title not found.');
            $codes = [];
            for ($i = 0; $i < $count; $i++) {
                $code = $own !== '' ? strtoupper($own) : 'LB' . str_pad((string) Seq::next('library_copy'), 7, '0', STR_PAD_LEFT);
                if (Db::val('SELECT 1 FROM library_copies WHERE barcode = ?', [$code])) throw new AppError("Barcode $code is already used.");
                Db::insert('library_copies', ['title_id' => $titleId, 'barcode' => $code, 'reference_only' => empty($in['reference_only']) ? 0 : 1,
                    'location' => In::str($in, 'location', 80), 'price_cents' => $price, 'acquisition_id' => $acquisitionId, 'acquired_on' => date('Y-m-d'), 'notes' => In::str($in, 'notes', 255)]);
                $codes[] = $code;
            }
            Audit::log('library_copies_added', 'library_title', $titleId, ['barcodes' => $codes, 'acquisition' => $acquisitionId]);
            self::serveHolds($titleId);
            return $codes;
        });
    }

    /** Change a copy's status (repair, withdrawn, missing, found…), location or price. Copies on loan or on hold change at the desk. */
    public static function updateCopy(int $copyId, array $in): void
    {
        Auth::require('library.catalog.edit');
        Db::tx(function () use ($copyId, $in) {
            $c = Db::one('SELECT * FROM library_copies WHERE id = ? FOR UPDATE', [$copyId]) ?: throw new AppError('Copy not found.');
            $status = (string) ($in['status'] ?? $c['status']);
            if (!isset(self::COPY_STATUS[$status])) throw new AppError('Choose a status.');
            if ($status !== $c['status'] && (in_array($c['status'], ['ON_LOAN', 'ON_HOLD_SHELF'], true) || in_array($status, ['ON_LOAN', 'ON_HOLD_SHELF'], true)))
                throw new AppError('Loans and holds change at the Circulation Desk (check out, return or cancel the hold).');
            $price = isset($in['price']) ? Money::cents((string) $in['price']) : (int) $c['price_cents'];
            Db::q('UPDATE library_copies SET status = ?, location = ?, price_cents = ?, reference_only = ?, notes = ? WHERE id = ?',
                [$status, In::str($in, 'location', 80), max(0, $price), empty($in['reference_only']) ? 0 : 1, In::str($in, 'notes', 255), $copyId]);
            Audit::log('library_copy_updated', 'library_copy', $copyId, ['barcode' => $c['barcode'], 'status' => [$c['status'], $status]]);
            if ($status === 'AVAILABLE' && $c['status'] !== 'AVAILABLE') self::serveHolds((int) $c['title_id']);
        });
    }

    public static function copyByBarcode(string $barcode): ?array
    {
        return Db::one('SELECT c.*, t.title, t.authors, t.material FROM library_copies c JOIN library_titles t ON t.id = c.title_id WHERE c.barcode = ?', [strtoupper(trim($barcode))]);
    }

    // ------------------------------------------------------------------ circulation

    /** Check a copy out to a borrower. Returns the loan id. */
    public static function checkout(string $type, string $ref, string $barcode, ?string $dueOn = null): int
    {
        Auth::require('library.circulate');
        $b = self::borrower($type, $ref) ?: throw new AppError('Borrower not found.');
        return Db::tx(function () use ($b, $barcode, $dueOn) {
            self::housekeep();
            $c = Db::one('SELECT c.*, t.title FROM library_copies c JOIN library_titles t ON t.id = c.title_id WHERE c.barcode = ? FOR UPDATE', [strtoupper(trim($barcode))]);
            if (!$c) throw new AppError('No copy has barcode ' . trim($barcode) . '.');
            if ((int) $c['reference_only']) throw new AppError('"' . $c['title'] . '" is for library use only.');
            $hold = null;
            if ($c['status'] === 'ON_HOLD_SHELF') {
                $hold = Db::one("SELECT * FROM library_holds WHERE copy_id = ? AND status = 'READY' FOR UPDATE", [$c['id']]);
                if ($hold && ($hold['borrower_type'] !== $b['type'] || $hold['borrower_ref'] !== $b['ref'])) throw new AppError('This copy is on the hold shelf for ' . $hold['borrower_name'] . '.');
            } elseif ($c['status'] !== 'AVAILABLE') throw new AppError('This copy is ' . strtolower(self::COPY_STATUS[$c['status']]) . '. Return it first if it is in your hands.');
            $open = (int) Db::val("SELECT COUNT(*) FROM library_loans WHERE borrower_type = ? AND borrower_ref = ? AND status = 'OPEN'", [$b['type'], $b['ref']]);
            if ($open >= self::typeRule($b['type'], 'max_items')) throw new AppError($b['name'] . ' already has ' . $open . ' item(s) out, the most allowed for a ' . strtolower(self::BORROWER_TYPES[$b['type']]) . '.');
            if (Db::val("SELECT 1 FROM library_loans WHERE borrower_type = ? AND borrower_ref = ? AND status = 'OPEN' AND due_on < CURDATE() LIMIT 1", [$b['type'], $b['ref']]))
                throw new AppError($b['name'] . ' has an overdue item. Return or renew it first.');
            if (self::rule('block_with_fines') && ($owed = (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM library_fines WHERE borrower_type = ? AND borrower_ref = ? AND status = 'UNPAID'", [$b['type'], $b['ref']])) > 0)
                throw new AppError($b['name'] . ' has unpaid library fines of ₱' . Money::fmt($owed) . '. Pay them at the Cashier first.');
            // Another borrower waiting for this title? Only they may take an available copy when every copy is needed.
            $waiting = Db::one("SELECT * FROM library_holds WHERE title_id = ? AND status = 'WAITING' ORDER BY id LIMIT 1", [$c['title_id']]);
            if (!$hold && $waiting && ($waiting['borrower_type'] !== $b['type'] || $waiting['borrower_ref'] !== $b['ref'])) {
                $free = (int) Db::val("SELECT COUNT(*) FROM library_copies WHERE title_id = ? AND status = 'AVAILABLE' AND reference_only = 0", [$c['title_id']]);
                $queue = (int) Db::val("SELECT COUNT(*) FROM library_holds WHERE title_id = ? AND status = 'WAITING'", [$c['title_id']]);
                if ($free <= $queue) throw new AppError('"' . $c['title'] . '" is reserved for ' . $waiting['borrower_name'] . ' (hold queue).');
            }
            $due = $dueOn !== null && $dueOn !== '' ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueOn) && $dueOn >= date('Y-m-d') ? $dueOn : throw new AppError('Choose a due date from today on.'))
                : date('Y-m-d', strtotime('+' . self::typeRule($b['type'], 'loan_days') . ' days'));
            $id = Db::insert('library_loans', ['copy_id' => (int) $c['id'], 'borrower_type' => $b['type'], 'borrower_ref' => $b['ref'], 'borrower_no' => $b['no'], 'borrower_name' => $b['name'],
                'due_on' => $due, 'loaned_by' => Auth::id()]);
            Db::q("UPDATE library_copies SET status = 'ON_LOAN' WHERE id = ?", [$c['id']]);
            $mine = $hold ?: Db::one("SELECT * FROM library_holds WHERE title_id = ? AND borrower_type = ? AND borrower_ref = ? AND status = 'WAITING' ORDER BY id LIMIT 1", [$c['title_id'], $b['type'], $b['ref']]);
            if ($mine) Db::q("UPDATE library_holds SET status = 'FULFILLED', copy_id = ?, closed_at = NOW() WHERE id = ?", [$c['id'], $mine['id']]);
            Audit::log('library_checkout', 'library_loan', $id, ['barcode' => $c['barcode'], 'title' => $c['title'], 'borrower' => $b['no'] . ' ' . $b['name'], 'due' => $due]);
            return $id;
        });
    }

    /** Return a copy. Charges the overdue fine; puts the copy on the hold shelf if someone is waiting. */
    public static function checkin(string $barcode, bool $damaged = false, int $damageCents = 0, string $note = ''): array
    {
        Auth::require('library.circulate');
        return Db::tx(function () use ($barcode, $damaged, $damageCents, $note) {
            $c = Db::one('SELECT c.*, t.title FROM library_copies c JOIN library_titles t ON t.id = c.title_id WHERE c.barcode = ? FOR UPDATE', [strtoupper(trim($barcode))]);
            if (!$c) throw new AppError('No copy has barcode ' . trim($barcode) . '.');
            $l = Db::one("SELECT * FROM library_loans WHERE copy_id = ? AND status IN ('OPEN','LOST') ORDER BY id DESC LIMIT 1 FOR UPDATE", [$c['id']]);
            if (!$l || ($l['status'] === 'OPEN' && $c['status'] !== 'ON_LOAN' && $c['status'] !== 'LOST')) {
                if (in_array($c['status'], ['MISSING'], true)) { Db::q("UPDATE library_copies SET status = 'AVAILABLE' WHERE id = ?", [$c['id']]); Audit::log('library_copy_found', 'library_copy', (int) $c['id'], ['barcode' => $c['barcode']]); self::serveHolds((int) $c['title_id']); return ['title' => $c['title'], 'loan' => null, 'fine' => 0, 'hold' => self::holdFor((int) $c['id']), 'found' => true]; }
                throw new AppError('"' . $c['title'] . '" (' . $c['barcode'] . ') is not on loan.');
            }
            $late = max(0, (int) ((strtotime(date('Y-m-d')) - strtotime((string) $l['due_on'])) / 86400));
            $fine = self::overdueFine((string) $l['borrower_type'], $late);
            Db::q("UPDATE library_loans SET status = 'RETURNED', returned_at = NOW(), returned_by = ?, notes = ? WHERE id = ?", [Auth::id(), $note !== '' ? mb_substr($note, 0, 255) : null, $l['id']]);
            if ($l['status'] === 'LOST') {
                // A lost item came back: the lost-item fine is dropped if it is still unpaid
                Db::q("UPDATE library_fines SET status = 'WAIVED', waived_by = ?, waived_at = NOW(), waive_reason = 'Item returned after it was marked lost' WHERE loan_id = ? AND kind = 'LOST' AND status = 'UNPAID'", [Auth::id(), $l['id']]);
            }
            if ($fine > 0 && !Db::val("SELECT 1 FROM library_fines WHERE loan_id = ? AND kind = 'OVERDUE'", [$l['id']]))
                self::charge($l, 'OVERDUE', $fine, $late . ' day(s) late · ' . $c['title'] . ' (' . $c['barcode'] . ')');
            if ($damaged && $damageCents > 0) self::charge($l, 'DAMAGE', $damageCents, 'Damage · ' . $c['title'] . ' (' . $c['barcode'] . ')' . ($note !== '' ? ' · ' . $note : ''));
            Db::q("UPDATE library_copies SET status = ? WHERE id = ?", [$damaged ? 'IN_REPAIR' : 'AVAILABLE', $c['id']]);
            Audit::log('library_checkin', 'library_loan', (int) $l['id'], ['barcode' => $c['barcode'], 'days_late' => $late, 'fine' => $fine, 'damaged' => $damaged]);
            $hold = $damaged ? null : self::serveHolds((int) $c['title_id'], (int) $c['id']);
            return ['title' => $c['title'], 'loan' => $l, 'fine' => $fine, 'late' => $late, 'hold' => $hold, 'found' => false];
        });
    }

    public static function overdueFine(string $type, int $daysLate): int
    {
        $days = $daysLate - self::rule('grace_days');
        if ($days <= 0) return 0;
        $f = $days * self::typeRule($type, 'fine_cents');
        $cap = self::rule('max_fine_cents');
        return $cap > 0 ? min($f, $cap) : $f;
    }

    /** Renew a loan (desk, or the borrower in the Student Portal). Returns the new due date. */
    public static function renew(int $loanId, bool $byBorrower = false): string
    {
        if (!$byBorrower) Auth::require('library.circulate');
        return Db::tx(function () use ($loanId, $byBorrower) {
            $l = Db::one("SELECT l.*, c.title_id FROM library_loans l JOIN library_copies c ON c.id = l.copy_id WHERE l.id = ? FOR UPDATE", [$loanId]);
            if (!$l || $l['status'] !== 'OPEN') throw new AppError('This loan is not open.');
            if ($byBorrower && ($l['borrower_type'] !== 'STUDENT' || $l['borrower_no'] !== (string) Auth::studentNumber())) throw new Forbidden('You can renew only your own loans.');
            if ($l['due_on'] < date('Y-m-d')) throw new AppError('This item is overdue. Return it to the library' . ($byBorrower ? '.' : ' (the overdue fine is charged on return).'));
            if ((int) $l['renewals'] >= self::typeRule((string) $l['borrower_type'], 'max_renewals')) throw new AppError('This item can\'t be renewed again. Return it to the library.');
            if (Db::val("SELECT 1 FROM library_holds WHERE title_id = ? AND status = 'WAITING' LIMIT 1", [$l['title_id']])) throw new AppError('Someone is waiting for this title, so it can\'t be renewed.');
            $due = date('Y-m-d', strtotime('+' . self::typeRule((string) $l['borrower_type'], 'loan_days') . ' days'));
            if ($due <= $l['due_on']) $due = date('Y-m-d', strtotime($l['due_on'] . ' +1 day'));
            Db::q('UPDATE library_loans SET due_on = ?, renewals = renewals + 1 WHERE id = ?', [$due, $loanId]);
            Audit::log('library_renewed', 'library_loan', $loanId, ['due' => [$l['due_on'], $due], 'by_borrower' => $byBorrower]);
            return $due;
        });
    }

    /** The borrower lost the item: the copy is LOST and the replacement price (plus overdue fine so far) is charged. */
    public static function markLost(int $loanId, string $note): void
    {
        Auth::require('library.circulate');
        Db::tx(function () use ($loanId, $note) {
            $l = Db::one("SELECT l.*, c.price_cents, c.barcode, t.title FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id WHERE l.id = ? FOR UPDATE", [$loanId]);
            if (!$l || $l['status'] !== 'OPEN') throw new AppError('This loan is not open.');
            $price = (int) $l['price_cents'] ?: self::rule('lost_fee_cents');
            $late = max(0, (int) ((strtotime(date('Y-m-d')) - strtotime((string) $l['due_on'])) / 86400));
            Db::q("UPDATE library_loans SET status = 'LOST', notes = ? WHERE id = ?", [mb_substr(trim($note), 0, 255) ?: null, $loanId]);
            Db::q("UPDATE library_copies SET status = 'LOST' WHERE id = ?", [$l['copy_id']]);
            if ($price > 0) self::charge($l, 'LOST', $price, 'Lost item · ' . $l['title'] . ' (' . $l['barcode'] . ')');
            if (($f = self::overdueFine((string) $l['borrower_type'], $late)) > 0) self::charge($l, 'OVERDUE', $f, $late . ' day(s) late before it was reported lost · ' . $l['title']);
            Audit::log('library_marked_lost', 'library_loan', $loanId, ['barcode' => $l['barcode'], 'charged' => $price]);
        });
    }

    private static function charge(array $loan, string $kind, int $cents, string $desc): int
    {
        return Db::insert('library_fines', ['loan_id' => (int) $loan['id'], 'kind' => $kind, 'borrower_type' => $loan['borrower_type'], 'borrower_ref' => $loan['borrower_ref'],
            'borrower_no' => $loan['borrower_no'], 'borrower_name' => $loan['borrower_name'], 'amount_cents' => $cents, 'description' => mb_substr($desc, 0, 255), 'created_by' => Auth::id()]);
    }

    public static function waive(int $fineId, string $reason): void
    {
        Auth::require('library.waive');
        if (mb_strlen(trim($reason)) < 8) throw new AppError('Give a reason of at least 8 characters for waiving.');
        Db::tx(function () use ($fineId, $reason) {
            $f = Db::one('SELECT * FROM library_fines WHERE id = ? FOR UPDATE', [$fineId]);
            if (!$f || $f['status'] !== 'UNPAID') throw new AppError('Only an unpaid fine can be waived.');
            Db::q("UPDATE library_fines SET status = 'WAIVED', waived_by = ?, waived_at = NOW(), waive_reason = ? WHERE id = ?", [Auth::id(), mb_substr(trim($reason), 0, 255), $fineId]);
            Audit::log('library_fine_waived', 'library_fine', $fineId, ['amount' => (int) $f['amount_cents'], 'borrower' => $f['borrower_no'], 'reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ holds

    public static function placeHold(int $titleId, string $type, string $ref, string $source = 'DESK'): int
    {
        if ($source === 'DESK') Auth::require('library.circulate');
        $b = self::borrower($type, $ref) ?: throw new AppError('Borrower not found.');
        return Db::tx(function () use ($titleId, $b, $source) {
            $t = Db::one('SELECT * FROM library_titles WHERE id = ? FOR UPDATE', [$titleId]) ?: throw new AppError('Title not found.');
            if (!Db::val("SELECT 1 FROM library_copies WHERE title_id = ? AND reference_only = 0 AND status NOT IN ('WITHDRAWN','LOST')", [$titleId])) throw new AppError('This title has no copy that can be borrowed.');
            if (Db::val("SELECT 1 FROM library_holds WHERE title_id = ? AND borrower_type = ? AND borrower_ref = ? AND status IN ('WAITING','READY')", [$titleId, $b['type'], $b['ref']])) throw new AppError('There is already a hold on this title for ' . $b['name'] . '.');
            if (Db::val("SELECT 1 FROM library_loans l JOIN library_copies c ON c.id = l.copy_id WHERE c.title_id = ? AND l.borrower_type = ? AND l.borrower_ref = ? AND l.status = 'OPEN'", [$titleId, $b['type'], $b['ref']])) throw new AppError($b['name'] . ' already has this title on loan.');
            if ((int) Db::val("SELECT COUNT(*) FROM library_holds WHERE borrower_type = ? AND borrower_ref = ? AND status IN ('WAITING','READY')", [$b['type'], $b['ref']]) >= self::rule('max_holds')) throw new AppError('At most ' . self::rule('max_holds') . ' holds at a time.');
            $id = Db::insert('library_holds', ['title_id' => $titleId, 'borrower_type' => $b['type'], 'borrower_ref' => $b['ref'], 'borrower_no' => $b['no'], 'borrower_name' => $b['name'], 'placed_by' => Auth::id(), 'source' => $source]);
            Audit::log('library_hold_placed', 'library_title', $titleId, ['hold' => $id, 'borrower' => $b['no'] . ' ' . $b['name'], 'source' => $source]);
            self::serveHolds($titleId);
            return $id;
        });
    }

    public static function cancelHold(int $holdId, bool $byBorrower = false): void
    {
        if (!$byBorrower) Auth::require('library.circulate');
        Db::tx(function () use ($holdId, $byBorrower) {
            $h = Db::one('SELECT * FROM library_holds WHERE id = ? FOR UPDATE', [$holdId]);
            if (!$h || !in_array($h['status'], ['WAITING', 'READY'], true)) throw new AppError('This hold is no longer active.');
            if ($byBorrower && ($h['borrower_type'] !== 'STUDENT' || $h['borrower_no'] !== (string) Auth::studentNumber())) throw new Forbidden('You can cancel only your own holds.');
            Db::q("UPDATE library_holds SET status = 'CANCELLED', closed_at = NOW() WHERE id = ?", [$holdId]);
            if ($h['status'] === 'READY' && $h['copy_id']) { Db::q("UPDATE library_copies SET status = 'AVAILABLE' WHERE id = ? AND status = 'ON_HOLD_SHELF'", [$h['copy_id']]); self::serveHolds((int) $h['title_id']); }
            Audit::log('library_hold_cancelled', 'library_title', (int) $h['title_id'], ['hold' => $holdId, 'by_borrower' => $byBorrower]);
        });
    }

    /** Give available copies of a title to the oldest waiting holds (copy goes to the hold shelf). Returns the hold served with $copyId, if any. */
    private static function serveHolds(int $titleId, ?int $copyId = null): ?array
    {
        $served = null;
        while ($h = Db::one("SELECT * FROM library_holds WHERE title_id = ? AND status = 'WAITING' ORDER BY id LIMIT 1 FOR UPDATE", [$titleId])) {
            $c = $copyId !== null && Db::val("SELECT status FROM library_copies WHERE id = ?", [$copyId]) === 'AVAILABLE' ? ['id' => $copyId]
                : Db::one("SELECT id FROM library_copies WHERE title_id = ? AND status = 'AVAILABLE' AND reference_only = 0 ORDER BY id LIMIT 1 FOR UPDATE", [$titleId]);
            if (!$c) break;
            $exp = date('Y-m-d', strtotime('+' . max(1, self::rule('hold_pickup_days')) . ' days'));
            Db::q("UPDATE library_copies SET status = 'ON_HOLD_SHELF' WHERE id = ?", [$c['id']]);
            Db::q("UPDATE library_holds SET status = 'READY', copy_id = ?, ready_at = NOW(), expires_on = ? WHERE id = ?", [$c['id'], $exp, $h['id']]);
            if ($copyId !== null && (int) $c['id'] === $copyId) $served = $h + ['expires_on' => $exp];
            $copyId = null;
        }
        return $served;
    }

    private static function holdFor(int $copyId): ?array
    {
        return Db::one("SELECT * FROM library_holds WHERE copy_id = ? AND status = 'READY'", [$copyId]);
    }

    /** Expire ready holds that were not picked up; their copies go to the next in line or back to the shelf. */
    public static function housekeep(): void
    {
        if (self::$housekept) return; self::$housekept = true;
        foreach (Db::all("SELECT * FROM library_holds WHERE status = 'READY' AND expires_on < CURDATE()") as $h) {
            Db::tx(function () use ($h) {
                Db::q("UPDATE library_holds SET status = 'EXPIRED', closed_at = NOW() WHERE id = ? AND status = 'READY'", [$h['id']]);
                if ($h['copy_id']) Db::q("UPDATE library_copies SET status = 'AVAILABLE' WHERE id = ? AND status = 'ON_HOLD_SHELF'", [$h['copy_id']]);
                self::serveHolds((int) $h['title_id']);
            });
        }
    }

    public static function holds(string $status = 'ACTIVE'): array
    {
        self::housekeep();
        $w = $status === 'ACTIVE' ? "h.status IN ('WAITING','READY')" : 'h.status = ?';
        return Db::all("SELECT h.*, t.title, c.barcode FROM library_holds h JOIN library_titles t ON t.id = h.title_id LEFT JOIN library_copies c ON c.id = h.copy_id WHERE $w ORDER BY h.status = 'READY' DESC, h.id", $status === 'ACTIVE' ? [] : [$status]);
    }

    // ------------------------------------------------------------------ loans list and overdue notices

    public static function loans(array $f = []): array
    {
        $w = ["l.status = 'OPEN'"]; $p = [];
        if (!empty($f['overdue'])) $w[] = 'l.due_on < CURDATE()';
        if (!empty($f['type']) && isset(self::BORROWER_TYPES[$f['type']])) { $w[] = 'l.borrower_type = ?'; $p[] = $f['type']; }
        if (($f['q'] ?? '') !== '') { $w[] = '(l.borrower_name LIKE ? OR l.borrower_no LIKE ? OR t.title LIKE ? OR c.barcode = ?)'; array_push($p, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%', $f['q']); }
        return Db::all('SELECT l.*, c.barcode, t.title, t.authors, DATEDIFF(CURDATE(), l.due_on) AS days_late FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id
            WHERE ' . implode(' AND ', $w) . ' ORDER BY l.due_on, l.borrower_name LIMIT 1000', $p);
    }

    // ------------------------------------------------------------------ fines at the Cashier (SIS and Finance)

    public static function unpaidFines(string $q = ''): array
    {
        $q = trim($q);
        return Db::all("SELECT borrower_type, borrower_ref, borrower_no, borrower_name, COUNT(*) n, SUM(amount_cents) total, MIN(created_at) since FROM library_fines WHERE status = 'UNPAID'"
            . ($q !== '' ? ' AND (borrower_name LIKE ? OR borrower_no LIKE ?)' : '') . ' GROUP BY borrower_type, borrower_ref, borrower_no, borrower_name ORDER BY borrower_name LIMIT 300', $q !== '' ? ["%$q%", "%$q%"] : []);
    }

    public static function borrowerFines(string $type, string $ref, string $status = 'UNPAID'): array
    {
        return Db::all('SELECT * FROM library_fines WHERE borrower_type = ? AND borrower_ref = ? AND status = ? ORDER BY id', [$type, $ref, $status]);
    }

    /** The Cashier receives chosen unpaid fines of one borrower and issues one O.R. Returns [payment id, receipt no]. */
    public static function payFines(string $type, string $ref, array $fineIds, string $method, ?string $reference, string $idem, ?string $orNo = null): array
    {
        Auth::require('payments.record');
        if (!preg_match('/^[a-f0-9]{32}$/', $idem)) throw new AppError('The payment form expired. Reload the page and try again.');
        if (!in_array($method, PAYMENT_METHODS, true)) throw new AppError('Choose the payment method.');
        $reference = $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 60) : null;
        if ($method !== 'CASH' && !$reference) throw new AppError("Enter the reference number of the $method payment.");
        $fineIds = array_values(array_unique(array_filter(array_map('intval', $fineIds))));
        if (!$fineIds) throw new AppError('Choose the fines to pay.');
        $orNo = $orNo !== null && trim($orNo) !== '' ? trim($orNo) : null;
        if ($orNo !== null && !preg_match(OrSeries::NUMBER_RE, $orNo)) throw new AppError('O.R. No.: up to 30 letters, numbers, -, # or / (not starting with -).');
        return Db::tx(function () use ($type, $ref, $fineIds, $method, $reference, $idem, $orNo) {
            AccountingWorkflow::lock();
            if ($dup = Db::one('SELECT id, receipt_no FROM library_fine_payments WHERE idempotency_key = ?', [$idem])) return [(int) $dup['id'], $dup['receipt_no']];
            $fines = Db::all('SELECT * FROM library_fines WHERE id IN (' . implode(',', $fineIds) . ') FOR UPDATE');
            if (count($fines) !== count($fineIds)) throw new AppError('A chosen fine was not found.');
            foreach ($fines as $f) {
                if ($f['status'] !== 'UNPAID') throw new AppError('A chosen fine is already ' . strtolower($f['status']) . '. Reload the page.');
                if ($f['borrower_type'] !== $type || $f['borrower_ref'] !== $ref) throw new AppError('The chosen fines belong to different borrowers.');
            }
            $total = array_sum(array_map(fn($f) => (int) $f['amount_cents'], $fines));
            $s = Db::one('SELECT * FROM sis_or_series WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1 FOR UPDATE') ?: throw new AppError('No O.R. series to use. Set one up in O.R. Series.');
            if ($orNo === null && !(int) $s['auto_number']) throw new AppError("Enter the O.R. number (series {$s['code']} is numbered by hand).");
            $no = $orNo ?? OrSeries::suggest($s);
            foreach (['sis_payments', 'sis_document_payments', 'library_fine_payments'] as $tb) if (Db::val("SELECT 1 FROM $tb WHERE receipt_no = ?", [$no])) throw new AppError("O.R. No. $no is already used.");
            $f0 = $fines[0];
            $pid = Db::insert('library_fine_payments', ['receipt_no' => $no, 'series_id' => (int) $s['id'], 'idempotency_key' => $idem, 'borrower_type' => $type, 'borrower_ref' => $ref,
                'borrower_no' => $f0['borrower_no'], 'borrower_name' => $f0['borrower_name'], 'amount_cents' => $total, 'method' => $method, 'reference_no' => $reference, 'received_by' => Auth::id()]);
            $n = OrSeries::numberOf($s, $no);
            if ($n !== null && $n >= (int) $s['next_number']) Db::q('UPDATE sis_or_series SET next_number = ? WHERE id = ?', [$n + 1, $s['id']]);
            Db::q("UPDATE library_fines SET status = 'PAID', payment_id = ? WHERE id IN (" . implode(',', $fineIds) . ')', [$pid]);
            $map = Accounting::map();
            $cash = $map['method:' . $method] ?? null; $income = $map['library:fines'] ?? ($map['default_revenue'] ?? null);
            if (!$cash || !$income) throw new AppError('Accounting: map the ' . $method . ' account and Library fines (Accounting → Account mappings) before receiving library fines.');
            Accounting::post(date('Y-m-d'), 'LIBRARY_FINE', $pid, null, "O.R. $no · library fines · {$f0['borrower_name']}", [[(int) $cash, $total, 0, 'O.R. ' . $no], [(int) $income, 0, $total, 'Library fines']]);
            Audit::log('library_fines_paid', 'library_fine_payment', $pid, ['receipt_no' => $no, 'amount' => $total, 'fines' => $fineIds, 'method' => $method]);
            return [$pid, $no];
        });
    }

    public static function voidFinePayment(int $paymentId, string $reason): void
    {
        Auth::require('payments.void');
        if (mb_strlen(trim($reason)) < 3) throw new AppError('Enter the reason for voiding.');
        Db::tx(function () use ($paymentId, $reason) {
            $p = Db::one('SELECT * FROM library_fine_payments WHERE id = ? FOR UPDATE', [$paymentId]) ?: throw new AppError('Receipt not found.');
            if ($p['status'] === 'VOIDED') throw new AppError('Already voided.');
            $e = Accounting::activeEntry('LIBRARY_FINE', $paymentId);
            if ($e && $e['status'] === 'POSTED') throw new AppError('This receipt is posted in Accounting. Accounting must reverse the posting before Cashier can void it.');
            if ($e) Accounting::reverse((int) $e['id'], 'library fine receipt voided — ' . $reason);
            Db::q("UPDATE library_fine_payments SET status = 'VOIDED', voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?", [Auth::id(), mb_substr(trim($reason), 0, 255), $paymentId]);
            Db::q("UPDATE library_fines SET status = 'UNPAID', payment_id = NULL WHERE payment_id = ?", [$paymentId]);
            Audit::log('library_fine_receipt_voided', 'library_fine_payment', $paymentId, ['receipt_no' => $p['receipt_no'], 'reason' => $reason]);
        });
    }

    public static function finePayment(int $id): ?array
    {
        $p = Db::one('SELECT p.*, u.full_name AS cashier FROM library_fine_payments p LEFT JOIN sis_users u ON u.id = p.received_by WHERE p.id = ?', [$id]);
        if ($p) $p['fines'] = Db::all('SELECT * FROM library_fines WHERE payment_id = ? ORDER BY id', [$id]);
        return $p;
    }

    public static function finePayments(int $limit = 50): array
    {
        return Db::all('SELECT p.*, u.full_name AS cashier FROM library_fine_payments p LEFT JOIN sis_users u ON u.id = p.received_by ORDER BY p.id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /** Fines collected between two datetimes (Finance → Collections by source). */
    public static function collected(string $from, string $toExclusive): array
    {
        if (!Db::val("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'library_fine_payments'")) return [0, 0];
        $r = Db::one("SELECT COALESCE(SUM(amount_cents),0) t, COUNT(*) n FROM library_fine_payments WHERE status = 'POSTED' AND paid_at >= ? AND paid_at < ?", [$from, $toExclusive]);
        return [(int) $r['t'], (int) $r['n']];
    }

    /** What a student owes the library (for the Student Portal and the Cashier). */
    public static function studentOwes(string $studentNumber): int
    {
        try { return (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM library_fines WHERE borrower_type = 'STUDENT' AND borrower_no = ? AND status = 'UNPAID'", [$studentNumber]); }
        catch (Throwable) { return 0; }
    }

    // ------------------------------------------------------------------ acquisitions

    public static function saveAcquisition(array $in): int
    {
        Auth::require('library.acquire');
        $supplier = In::str($in, 'supplier', 150, true, 'Supplier');
        $date = In::date($in, 'ordered_on') ?? date('Y-m-d');
        $lines = [];
        foreach ((array) ($in['lines'] ?? []) as $l) {
            $tid = (int) ($l['title_id'] ?? 0); $q = (int) ($l['qty'] ?? 0);
            if (!$tid && !$q) continue;
            if (!$tid || $q < 1 || $q > 500) throw new AppError('Each line needs a title and a quantity (1–500).');
            $lines[] = [$tid, $q, Money::cents((string) ($l['unit_cost'] ?? '0'))];
        }
        if (!$lines) throw new AppError('Add at least one title to the order.');
        return Db::tx(function () use ($supplier, $date, $in, $lines) {
            $id = Db::insert('library_acquisitions', ['supplier' => $supplier, 'reference_no' => In::str($in, 'reference_no', 60), 'ordered_on' => $date, 'notes' => In::str($in, 'notes', 255), 'created_by' => Auth::id()]);
            foreach ($lines as [$tid, $q, $cost]) {
                if (!Db::val('SELECT 1 FROM library_titles WHERE id = ?', [$tid])) throw new AppError('A title in the order was not found.');
                Db::insert('library_acquisition_lines', ['acquisition_id' => $id, 'title_id' => $tid, 'qty' => $q, 'unit_cost_cents' => max(0, $cost)]);
            }
            Audit::log('library_order_created', 'library_acquisition', $id, ['supplier' => $supplier, 'lines' => count($lines)]);
            return $id;
        });
    }

    /** Receive items of an order: creates the copies (with barcodes and the unit cost as price). Returns the new barcodes. */
    public static function receive(int $acqId, array $received, array $in = []): array
    {
        Auth::require('library.acquire');
        return Db::tx(function () use ($acqId, $received, $in) {
            $a = Db::one('SELECT * FROM library_acquisitions WHERE id = ? FOR UPDATE', [$acqId]) ?: throw new AppError('Order not found.');
            if (in_array($a['status'], ['RECEIVED', 'CANCELLED'], true)) throw new AppError('This order is already ' . strtolower($a['status']) . '.');
            $codes = [];
            foreach (Db::all('SELECT * FROM library_acquisition_lines WHERE acquisition_id = ? FOR UPDATE', [$acqId]) as $l) {
                $q = (int) ($received[$l['id']] ?? 0);
                if ($q < 0 || $q > (int) $l['qty'] - (int) $l['received_qty']) throw new AppError('You can receive at most ' . ((int) $l['qty'] - (int) $l['received_qty']) . ' more of a line.');
                if ($q === 0) continue;
                $codes = array_merge($codes, self::addCopies((int) $l['title_id'], $q, ['price' => Money::dec((int) $l['unit_cost_cents']), 'location' => $in['location'] ?? null], $acqId));
                Db::q('UPDATE library_acquisition_lines SET received_qty = received_qty + ? WHERE id = ?', [$q, $l['id']]);
            }
            if (!$codes) throw new AppError('Type how many of each title arrived.');
            $left = (int) Db::val('SELECT COALESCE(SUM(qty - received_qty),0) FROM library_acquisition_lines WHERE acquisition_id = ?', [$acqId]);
            Db::q('UPDATE library_acquisitions SET status = ? WHERE id = ?', [$left ? 'PARTIAL' : 'RECEIVED', $acqId]);
            Audit::log('library_order_received', 'library_acquisition', $acqId, ['copies' => count($codes)]);
            return $codes;
        });
    }

    public static function cancelAcquisition(int $acqId): void
    {
        Auth::require('library.acquire');
        Db::tx(function () use ($acqId) {
            $a = Db::one('SELECT * FROM library_acquisitions WHERE id = ? FOR UPDATE', [$acqId]) ?: throw new AppError('Order not found.');
            if ($a['status'] !== 'ORDERED') throw new AppError('Only an order with nothing received can be cancelled.');
            Db::q("UPDATE library_acquisitions SET status = 'CANCELLED' WHERE id = ?", [$acqId]);
            Audit::log('library_order_cancelled', 'library_acquisition', $acqId, []);
        });
    }

    // ------------------------------------------------------------------ stocktake

    public static function startStocktake(string $name, ?string $location): int
    {
        Auth::require('library.stocktake');
        if (Db::val("SELECT 1 FROM library_stocktakes WHERE status = 'OPEN'")) throw new AppError('A stocktake is already open. Close it first.');
        $id = Db::insert('library_stocktakes', ['name' => mb_substr(trim($name) ?: 'Stocktake ' . date('M j, Y'), 0, 120), 'location' => $location !== null && trim($location) !== '' ? mb_substr(trim($location), 0, 80) : null, 'started_by' => Auth::id()]);
        Audit::log('library_stocktake_started', 'library_stocktake', $id, ['location' => $location]);
        return $id;
    }

    /** Record a copy as seen on the shelf. Returns [status, message]. */
    public static function scan(int $takeId, string $barcode): array
    {
        Auth::require('library.stocktake');
        $s = Db::one('SELECT * FROM library_stocktakes WHERE id = ?', [$takeId]);
        if (!$s || $s['status'] !== 'OPEN') throw new AppError('This stocktake is closed.');
        $c = self::copyByBarcode($barcode);
        if (!$c) return ['unknown', 'Unknown barcode ' . trim($barcode) . ' — not in the catalog.'];
        if (Db::val('SELECT 1 FROM library_stocktake_scans WHERE stocktake_id = ? AND copy_id = ?', [$takeId, $c['id']])) return ['repeat', $c['title'] . ' (' . $c['barcode'] . ') was already scanned.'];
        Db::insert('library_stocktake_scans', ['stocktake_id' => $takeId, 'copy_id' => (int) $c['id'], 'scanned_by' => Auth::id()]);
        $warn = match ($c['status']) { 'ON_LOAN' => 'is on loan in the system — return it at the desk', 'MISSING' => 'was missing — it will be marked found', 'LOST' => 'was marked lost', 'WITHDRAWN' => 'is withdrawn', default => '' };
        return [$warn ? 'warn' : 'ok', $c['title'] . ' (' . $c['barcode'] . ')' . ($warn ? ' ' . $warn . '.' : ' ✓')];
    }

    /** Copies expected on the shelf but not scanned (in the stocktake's location, if it has one), and scanned copies with a different status. */
    public static function stocktakeReport(int $takeId): array
    {
        $s = Db::one('SELECT * FROM library_stocktakes WHERE id = ?', [$takeId]) ?: throw new AppError('Stocktake not found.');
        $loc = $s['location'] ? ' AND c.location = ?' : '';
        $args = $s['location'] ? [$takeId, $s['location']] : [$takeId];
        $missing = Db::all("SELECT c.*, t.title FROM library_copies c JOIN library_titles t ON t.id = c.title_id WHERE c.status IN ('AVAILABLE','ON_HOLD_SHELF','IN_REPAIR')
            AND NOT EXISTS (SELECT 1 FROM library_stocktake_scans x WHERE x.stocktake_id = ? AND x.copy_id = c.id)$loc ORDER BY t.title, c.barcode", $args);
        $odd = Db::all("SELECT c.*, t.title FROM library_stocktake_scans x JOIN library_copies c ON c.id = x.copy_id JOIN library_titles t ON t.id = c.title_id WHERE x.stocktake_id = ? AND c.status NOT IN ('AVAILABLE','ON_HOLD_SHELF','IN_REPAIR') ORDER BY t.title", [$takeId]);
        $scanned = (int) Db::val('SELECT COUNT(*) FROM library_stocktake_scans WHERE stocktake_id = ?', [$takeId]);
        return ['take' => $s, 'missing' => $missing, 'odd' => $odd, 'scanned' => $scanned];
    }

    /** Close: unscanned shelf copies become MISSING (if chosen); scanned MISSING copies become AVAILABLE again. */
    public static function closeStocktake(int $takeId, bool $markMissing): array
    {
        Auth::require('library.stocktake');
        return Db::tx(function () use ($takeId, $markMissing) {
            $r = self::stocktakeReport($takeId);
            if ($r['take']['status'] !== 'OPEN') throw new AppError('This stocktake is already closed.');
            $missing = 0; $found = 0;
            if ($markMissing) foreach ($r['missing'] as $c) if ($c['status'] === 'AVAILABLE') { Db::q("UPDATE library_copies SET status = 'MISSING' WHERE id = ?", [$c['id']]); $missing++; }
            foreach ($r['odd'] as $c) if ($c['status'] === 'MISSING') { Db::q("UPDATE library_copies SET status = 'AVAILABLE' WHERE id = ?", [$c['id']]); $found++; self::serveHolds((int) $c['title_id']); }
            $result = "Scanned {$r['scanned']}; not found " . count($r['missing']) . ($markMissing ? " ($missing marked missing)" : '') . "; found again $found.";
            Db::q("UPDATE library_stocktakes SET status = 'CLOSED', closed_by = ?, closed_at = NOW(), result = ? WHERE id = ?", [Auth::id(), $result, $takeId]);
            Audit::log('library_stocktake_closed', 'library_stocktake', $takeId, ['result' => $result]);
            return ['missing' => $missing, 'found' => $found, 'result' => $result];
        });
    }

    // ------------------------------------------------------------------ reports

    public static function report(string $from, string $to): array
    {
        $a = $from . ' 00:00:00'; $b = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
        return [
            'loans' => (int) Db::val('SELECT COUNT(*) FROM library_loans WHERE loaned_at >= ? AND loaned_at < ?', [$a, $b]),
            'returns' => (int) Db::val('SELECT COUNT(*) FROM library_loans WHERE returned_at >= ? AND returned_at < ?', [$a, $b]),
            'open' => (int) Db::val("SELECT COUNT(*) FROM library_loans WHERE status = 'OPEN'"),
            'overdue' => (int) Db::val("SELECT COUNT(*) FROM library_loans WHERE status = 'OPEN' AND due_on < CURDATE()"),
            'charged' => (int) Db::val('SELECT COALESCE(SUM(amount_cents),0) FROM library_fines WHERE created_at >= ? AND created_at < ?', [$a, $b]),
            'collected' => self::collected($a, $b)[0],
            'waived' => (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM library_fines WHERE status = 'WAIVED' AND waived_at >= ? AND waived_at < ?", [$a, $b]),
            'unpaid' => (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM library_fines WHERE status = 'UNPAID'"),
            'top' => Db::all('SELECT t.id, t.title, t.authors, COUNT(*) n FROM library_loans l JOIN library_copies c ON c.id = l.copy_id JOIN library_titles t ON t.id = c.title_id WHERE l.loaned_at >= ? AND l.loaned_at < ? GROUP BY t.id, t.title, t.authors ORDER BY n DESC LIMIT 15', [$a, $b]),
            'byType' => Db::all('SELECT borrower_type, COUNT(*) n, COUNT(DISTINCT borrower_ref) people FROM library_loans WHERE loaned_at >= ? AND loaned_at < ? GROUP BY borrower_type', [$a, $b]),
            'collection' => Db::all("SELECT status, COUNT(*) n FROM library_copies GROUP BY status"),
            'titles' => (int) Db::val('SELECT COUNT(*) FROM library_titles'),
        ];
    }

    // ------------------------------------------------------------------ barcode labels (Code 39, readable by any scanner)

    private const CODE39 = ['0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw',
        'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn', 'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn',
        'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn'];

    /** An inline SVG Code 39 barcode for $text (letters, digits, - and .). */
    public static function barcodeSvg(string $text, int $height = 40): string
    {
        $text = strtoupper($text);
        $x = 0; $bars = '';
        foreach (str_split('*' . $text . '*') as $ch) {
            $pattern = self::CODE39[$ch] ?? null;
            if ($pattern === null) continue;
            foreach (str_split($pattern) as $i => $w) {
                $width = $w === 'w' ? 3 : 1;
                if ($i % 2 === 0) $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $height . '"/>';
                $x += $width;
            }
            $x += 1; // gap between characters
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $x . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="Barcode ' . h($text) . '" class="lib-barcode"><g fill="#000">' . $bars . '</g></svg>';
    }
}
