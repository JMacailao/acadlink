<?php
declare(strict_types=1);

/**
 * v52: document requests from the Student Portal.
 * Student requests → pays at the Cashier (O.R.) → registrar issues it (TOR & Certificates) → Ready → Released.
 * Status: PENDING_PAYMENT, PAID, PROCESSING, READY, RELEASED, CANCELLED.
 */
final class DocumentRequests
{
    public const STATUS = [
        'PENDING_PAYMENT' => ['Waiting for payment', 'warning'], 'PAID' => ['Paid', 'info'], 'PROCESSING' => ['Processing', 'primary'],
        'READY' => ['Ready for pickup', 'success'], 'RELEASED' => ['Released', 'secondary'], 'CANCELLED' => ['Cancelled', 'danger'],
    ];
    public const MAX_COPIES = 5;

    public static function install(PDO $db): void
    {
        Db::q("CREATE TABLE IF NOT EXISTS sis_document_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ref_no VARCHAR(30) NOT NULL,
            student_doc_id VARCHAR(191) NOT NULL, student_number VARCHAR(40) NOT NULL, student_name VARCHAR(200) NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            doc_type VARCHAR(40) NOT NULL, school_year VARCHAR(9) NULL, term VARCHAR(20) NULL,
            copies TINYINT UNSIGNED NOT NULL DEFAULT 1, purpose VARCHAR(200) NOT NULL,
            fee_per_copy DECIMAL(12,2) NOT NULL DEFAULT 0, amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('PENDING_PAYMENT','PAID','PROCESSING','READY','RELEASED','CANCELLED') NOT NULL,
            processing_days TINYINT UNSIGNED NOT NULL DEFAULT 0, expected_date DATE NULL,
            document_id INT UNSIGNED NULL,
            paid_at DATETIME NULL, ready_at DATETIME NULL,
            released_at DATETIME NULL, released_to VARCHAR(150) NULL, released_by INT UNSIGNED NULL,
            cancelled_at DATETIME NULL, cancel_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY u_sis_docreq_ref (ref_no), KEY k_sis_docreq_student (student_doc_id), KEY k_sis_docreq_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        Db::q("CREATE TABLE IF NOT EXISTS sis_document_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            receipt_no VARCHAR(30) NOT NULL, series_id INT UNSIGNED NULL, idempotency_key CHAR(32) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method ENUM('CASH','GCASH','MAYA','BANK','CARD','CHECK') NOT NULL DEFAULT 'CASH', reference_no VARCHAR(60) NULL,
            status ENUM('POSTED','VOIDED') NOT NULL DEFAULT 'POSTED',
            received_by INT UNSIGNED NOT NULL, paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voided_by INT UNSIGNED NULL, voided_at DATETIME NULL, void_reason VARCHAR(255) NULL,
            UNIQUE KEY u_sis_docpay_receipt (receipt_no), UNIQUE KEY u_sis_docpay_idem (idempotency_key), KEY k_sis_docpay_req (request_id),
            CONSTRAINT fk_sis_docpay_req FOREIGN KEY (request_id) REFERENCES sis_document_requests (id),
            CONSTRAINT ck_sis_docpay_amount CHECK (amount > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // The journal knows document fee receipts (Dr Cash/Bank, Cr document fee income)
        $type = (string) Db::val("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_journal' AND column_name = 'source'");
        if ($type !== '' && !str_contains($type, "'DOCUMENT_FEE'")) Db::q("ALTER TABLE sis_journal MODIFY source ENUM('ASSESSMENT','PAYMENT','MANUAL','DOCUMENT_FEE') NOT NULL");
    }

    // ------------------------------------------------------------------ settings

    public static function fee(string $type): int { return Money::cents(Settings::get('doc_fee_' . $type) ?: '0'); }
    public static function days(string $type): int { return max(0, min(60, (int) Settings::get('doc_days_' . $type))); }
    public static function online(string $type): bool { return Settings::get('doc_online_' . $type) === '1'; }

    /** "5 working days" after $from (Saturdays and Sundays skipped). */
    public static function addWorkingDays(string $from, int $days): string
    {
        $t = strtotime(substr($from, 0, 10));
        while ($days > 0) { $t = strtotime('+1 day', $t); if ((int) date('N', $t) < 6) $days--; }
        return date('Y-m-d', $t);
    }

    // ------------------------------------------------------------------ student

    /** The signed-in student's record, or a refusal. */
    public static function ownStudent(): array
    {
        $a = Auth::central()->current();
        if (!$a || $a['account_kind'] !== 'STUDENT' || empty($a['person_verified']) || empty($a['student_doc_id'])) throw new Forbidden('Document requests open once the Registrar has linked your student record.');
        return GS::get('students', (string) $a['student_doc_id']) ?: throw new AppError('Your student record was not found. Ask the Registrar.');
    }

    public static function create(array $in): int
    {
        $s = self::ownStudent();
        $a = Auth::central()->current();
        $type = (string) ($in['type'] ?? '');
        if (!isset(Documents::TYPES[$type]) || !self::online($type)) throw new AppError('Choose a document you can request online.');
        [$title, $needsTerm] = Documents::TYPES[$type];
        $copies = (int) ($in['copies'] ?? 1);
        if ($copies < 1 || $copies > self::MAX_COPIES) throw new AppError('Copies: 1 to ' . self::MAX_COPIES . '.');
        $purpose = trim(preg_replace('/\s+/', ' ', (string) ($in['purpose'] ?? '')));
        if ($purpose === '' || mb_strlen($purpose) > 200) throw new AppError('Enter the purpose (e.g. Employment, Scholarship).');
        $term = $needsTerm ? (string) ($in['term'] ?? '') : '';
        if ($needsTerm) Documents::data($type, $s, ['term' => $term]); // checks the term has grades / a confirmed enrollment
        [$sy, $tm] = array_pad(explode('|', $term), 2, '');
        if (Db::val("SELECT 1 FROM sis_document_requests WHERE student_doc_id = ? AND doc_type = ? AND IFNULL(school_year,'') = ? AND IFNULL(term,'') = ? AND status IN ('PENDING_PAYMENT','PAID','PROCESSING')", [$s['id'], $type, $sy, $tm]))
            throw new AppError("You already have an open request for the $title" . ($sy ? " ($sy $tm)" : '') . '. Wait until it is ready, or cancel it first.');
        if ((int) Db::val("SELECT COUNT(*) FROM sis_document_requests WHERE account_id = ? AND created_at > NOW() - INTERVAL 1 DAY", [$a['id']]) >= 10) throw new AppError('Too many requests today. Try again tomorrow.');
        $fee = self::fee($type); $total = $fee * $copies;
        return Db::tx(function () use ($s, $a, $type, $sy, $tm, $copies, $purpose, $fee, $total, $title) {
            $ref = sprintf('REQ-%d-%05d', (int) date('Y'), Seq::next('docreq_' . date('Y')));
            $id = Db::insert('sis_document_requests', ['ref_no' => $ref, 'student_doc_id' => $s['id'], 'student_number' => (string) ($s['studentId'] ?? ''), 'student_name' => (string) ($s['studentName'] ?? ''),
                'account_id' => (int) $a['id'], 'doc_type' => $type, 'school_year' => $sy ?: null, 'term' => $tm ?: null, 'copies' => $copies, 'purpose' => $purpose,
                'fee_per_copy' => Money::dec($fee), 'amount' => Money::dec($total), 'status' => $total > 0 ? 'PENDING_PAYMENT' : 'PAID', 'processing_days' => self::days($type)]);
            if ($total <= 0) self::markPaid($id);
            Audit::log('document_requested', 'document_request', $id, ['ref' => $ref, 'type' => $type, 'copies' => $copies, 'amount' => Money::dec($total)]);
            self::notifyStaff('REGISTRAR', 'New document request', $s['studentName'] . " requested $copies × $title ($ref)" . ($total > 0 ? ': waiting for payment.' : '.'), $id);
            return $id;
        });
    }

    /** The student cancels their own request while it is unpaid. */
    public static function cancelOwn(int $id): void
    {
        $s = self::ownStudent();
        $r = Db::one('SELECT * FROM sis_document_requests WHERE id = ? AND student_doc_id = ?', [$id, $s['id']]) ?: throw new AppError('Request not found.');
        if ($r['status'] !== 'PENDING_PAYMENT') throw new AppError('Only an unpaid request can be cancelled here. Ask the Registrar.');
        Db::q("UPDATE sis_document_requests SET status = 'CANCELLED', cancelled_at = NOW(), cancel_reason = 'Cancelled by the student' WHERE id = ?", [$id]);
        Audit::log('document_request_cancelled', 'document_request', $id, ['by' => 'student']);
    }

    public static function forStudent(string $studentDocId): array
    {
        return Db::all('SELECT r.*, (SELECT receipt_no FROM sis_document_payments p WHERE p.request_id = r.id AND p.status = "POSTED" ORDER BY p.id DESC LIMIT 1) AS receipt_no
                          FROM sis_document_requests r WHERE r.student_doc_id = ? ORDER BY r.id DESC', [$studentDocId]);
    }

    // ------------------------------------------------------------------ cashier

    public static function unpaid(string $q = ''): array
    {
        $like = '%' . trim($q) . '%';
        return Db::all("SELECT * FROM sis_document_requests WHERE status = 'PENDING_PAYMENT'" . (trim($q) !== '' ? ' AND (ref_no LIKE ? OR student_number LIKE ? OR student_name LIKE ?)' : '') . ' ORDER BY id', trim($q) !== '' ? [$like, $like, $like] : []);
    }

    public static function payments(int $limit = 50): array
    {
        return Db::all('SELECT p.*, r.ref_no, r.student_name, r.student_number, r.doc_type, r.copies FROM sis_document_payments p JOIN sis_document_requests r ON r.id = p.request_id ORDER BY p.id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /** The cashier receives the fee (full amount) and issues an O.R. from the same series as tuition receipts. */
    public static function pay(int $id, string $method, ?string $ref, string $idem, ?string $orNo = null): array
    {
        Auth::require('payments.record');
        if (!preg_match('/^[a-f0-9]{32}$/', $idem)) throw new AppError('The payment form expired. Reload the page and try again.');
        if (!in_array($method, PAYMENT_METHODS, true)) throw new AppError('Choose the payment method.');
        $ref = $ref !== null && trim($ref) !== '' ? trim($ref) : null;
        if ($method !== 'CASH' && !$ref) throw new AppError("Enter the reference number of the $method payment.");
        $orNo = $orNo !== null && trim($orNo) !== '' ? trim($orNo) : null;
        if ($orNo !== null && !preg_match(OrSeries::NUMBER_RE, $orNo)) throw new AppError('O.R. No.: up to 30 letters, numbers, -, # or / (not starting with -).');
        return Db::tx(function () use ($id, $method, $ref, $idem, $orNo) {
            AccountingWorkflow::lock();
            $dup = Db::one('SELECT id, receipt_no FROM sis_document_payments WHERE idempotency_key = ?', [$idem]);
            if ($dup) return ['id' => (int) $dup['id'], 'receipt_no' => $dup['receipt_no'], 'duplicate' => true];
            $r = Db::one('SELECT * FROM sis_document_requests WHERE id = ? FOR UPDATE', [$id]) ?: throw new AppError('Request not found.');
            if ($r['status'] !== 'PENDING_PAYMENT') throw new AppError('This request is not waiting for payment (' . self::STATUS[$r['status']][0] . ').');
            $cents = Money::cents($r['amount']);
            $s = Db::one('SELECT * FROM sis_or_series WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1 FOR UPDATE') ?: throw new AppError('No O.R. series to use. Set one up in Settings → O.R. Series.');
            if ($orNo === null && !(int) $s['auto_number']) throw new AppError("Enter the O.R. number (series {$s['code']} is numbered by hand).");
            $no = $orNo ?? OrSeries::suggest($s);
            if (Db::val('SELECT 1 FROM sis_payments WHERE receipt_no = ?', [$no]) || Db::val('SELECT 1 FROM sis_document_payments WHERE receipt_no = ?', [$no])) throw new AppError("O.R. No. $no is already used.");
            $pid = Db::insert('sis_document_payments', ['request_id' => $id, 'receipt_no' => $no, 'series_id' => (int) $s['id'], 'idempotency_key' => $idem, 'amount' => Money::dec($cents),
                'method' => $method, 'reference_no' => $ref, 'received_by' => Auth::id()]);
            $n = OrSeries::numberOf($s, $no);
            if ($n !== null && $n >= (int) $s['next_number']) Db::q('UPDATE sis_or_series SET next_number = ? WHERE id = ?', [$n + 1, $s['id']]);
            self::postJournal($pid);
            self::markPaid($id);
            Audit::log('document_fee_received', 'document_request', $id, ['receipt_no' => $no, 'amount' => Money::dec($cents), 'method' => $method]);
            return ['id' => $pid, 'receipt_no' => $no, 'duplicate' => false];
        });
    }

    /** Dr Cash/Bank (by method), Cr document fee income (Accounting → mapping "doc_fee", else the default revenue account). */
    private static function postJournal(int $paymentId): void
    {
        $p = Db::one('SELECT p.*, r.ref_no, r.student_name FROM sis_document_payments p JOIN sis_document_requests r ON r.id = p.request_id WHERE p.id = ?', [$paymentId]);
        $map = Accounting::map();
        $cash = $map['method:' . $p['method']] ?? null; $income = $map['doc_fee'] ?? ($map['default_revenue'] ?? null);
        if (!$cash || !$income) throw new AppError('Accounting: map the ' . $p['method'] . ' account and a revenue account (Accounting → Account mappings) before receiving document fees.');
        $c = Money::cents($p['amount']);
        Accounting::post(substr((string) $p['paid_at'], 0, 10) ?: date('Y-m-d'), 'DOCUMENT_FEE', $paymentId, null, "O.R. {$p['receipt_no']} · document fee {$p['ref_no']} · {$p['student_name']}",
            [[(int) $cash, $c, 0, 'O.R. ' . $p['receipt_no']], [(int) $income, 0, $c, $p['ref_no']]]);
    }

    private static function markPaid(int $id): void
    {
        $r = Db::one('SELECT * FROM sis_document_requests WHERE id = ?', [$id]);
        $expected = self::addWorkingDays(date('Y-m-d'), (int) $r['processing_days']);
        Db::q("UPDATE sis_document_requests SET status = 'PAID', paid_at = NOW(), expected_date = ? WHERE id = ?", [$expected, $id]);
        self::notifyStudent($id, 'Payment received', 'Your request ' . $r['ref_no'] . ' is paid. Expected release: ' . date('F j, Y', strtotime($expected)) . '.');
    }

    /** Voids a document fee receipt: the request waits for payment again. */
    public static function voidPayment(int $paymentId, string $reason): void
    {
        Auth::require('payments.void');
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new AppError('Enter the reason for voiding.');
        Db::tx(function () use ($paymentId, $reason) {
            $p = Db::one('SELECT * FROM sis_document_payments WHERE id = ? FOR UPDATE', [$paymentId]) ?: throw new AppError('Receipt not found.');
            if ($p['status'] === 'VOIDED') throw new AppError('Already voided.');
            $r = Db::one('SELECT * FROM sis_document_requests WHERE id = ? FOR UPDATE', [$p['request_id']]);
            if (in_array($r['status'], ['READY', 'RELEASED'], true)) throw new AppError('The document is already ' . strtolower(self::STATUS[$r['status']][0]) . '. Void the issued document first if this was a mistake.');
            $e = Accounting::activeEntry('DOCUMENT_FEE', $paymentId);
            if ($e && $e['status'] === 'POSTED') throw new AppError('This receipt is posted in Accounting. Accounting must reverse the posting before Cashier can void it.');
            if ($e) Accounting::reverse((int) $e['id'], 'document fee receipt voided — ' . $reason);
            Db::q("UPDATE sis_document_payments SET status = 'VOIDED', voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?", [Auth::id(), mb_substr($reason, 0, 255), $paymentId]);
            Db::q("UPDATE sis_document_requests SET status = 'PENDING_PAYMENT', paid_at = NULL, expected_date = NULL WHERE id = ?", [$p['request_id']]);
            Audit::log('document_fee_voided', 'document_request', (int) $p['request_id'], ['receipt_no' => $p['receipt_no'], 'reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ registrar

    public static function list(string $status = 'OPEN', string $q = ''): array
    {
        $w = []; $a = [];
        if ($status === 'OPEN') $w[] = "r.status IN ('PENDING_PAYMENT','PAID','PROCESSING','READY')";
        elseif (isset(self::STATUS[$status])) { $w[] = 'r.status = ?'; $a[] = $status; }
        if (trim($q) !== '') { $w[] = '(r.ref_no LIKE ? OR r.student_number LIKE ? OR r.student_name LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
        return Db::all('SELECT r.*, d.control_no, d.status AS doc_status, (SELECT receipt_no FROM sis_document_payments p WHERE p.request_id = r.id AND p.status = "POSTED" ORDER BY p.id DESC LIMIT 1) AS receipt_no
                          FROM sis_document_requests r LEFT JOIN sis_documents d ON d.id = r.document_id'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY FIELD(r.status,'PAID','PROCESSING','READY','PENDING_PAYMENT','RELEASED','CANCELLED'), r.id", $a);
    }

    public static function get(int $id): ?array { return Db::one('SELECT * FROM sis_document_requests WHERE id = ?', [$id]); }

    /** Called when the registrar issues (or prepares) the document for a request. */
    public static function linkDocument(int $requestId, int $documentId, bool $issued): void
    {
        $r = self::get($requestId) ?: throw new AppError('Request not found.');
        if (!in_array($r['status'], ['PAID', 'PROCESSING'], true)) throw new AppError('This request is ' . strtolower(self::STATUS[$r['status']][0]) . '; it can be issued only after payment.');
        Db::q('UPDATE sis_document_requests SET document_id = ?, status = ? WHERE id = ?', [$documentId, $issued ? 'READY' : 'PROCESSING', $requestId]);
        if ($issued) { Db::q('UPDATE sis_document_requests SET ready_at = NOW() WHERE id = ?', [$requestId]); self::notifyStudent($requestId, 'Document ready for pickup', 'Your ' . Documents::TYPES[$r['doc_type']][0] . ' (' . $r['ref_no'] . ') is ready. Bring your ID to the Registrar.'); }
    }

    /** A prepared document was signed: its request becomes ready. */
    public static function documentSigned(int $documentId): void
    {
        foreach (Db::col("SELECT id FROM sis_document_requests WHERE document_id = ? AND status = 'PROCESSING'", [$documentId]) as $rid) {
            Db::q("UPDATE sis_document_requests SET status = 'READY', ready_at = NOW() WHERE id = ?", [$rid]);
            $r = self::get((int) $rid);
            self::notifyStudent((int) $rid, 'Document ready for pickup', 'Your ' . Documents::TYPES[$r['doc_type']][0] . ' (' . $r['ref_no'] . ') is ready. Bring your ID to the Registrar.');
        }
    }

    public static function startProcessing(int $id): void
    {
        Auth::require('documents.issue');
        $r = self::get($id) ?: throw new AppError('Request not found.');
        if ($r['status'] !== 'PAID') throw new AppError('Only a paid request can be marked Processing.');
        Db::q("UPDATE sis_document_requests SET status = 'PROCESSING' WHERE id = ?", [$id]);
        self::notifyStudent($id, 'Request being processed', 'The Registrar is preparing your request ' . $r['ref_no'] . '.');
    }

    public static function release(int $id, string $to): void
    {
        Auth::require('documents.issue');
        $to = trim(preg_replace('/\s+/', ' ', $to));
        if ($to === '' || mb_strlen($to) > 150) throw new AppError('Enter who received the document (the student, or an authorized person).');
        $r = self::get($id) ?: throw new AppError('Request not found.');
        if ($r['status'] !== 'READY') throw new AppError('Only a document that is ready can be released.');
        Db::q("UPDATE sis_document_requests SET status = 'RELEASED', released_at = NOW(), released_to = ?, released_by = ? WHERE id = ?", [$to, Auth::id(), $id]);
        Audit::log('document_released', 'document_request', $id, ['to' => $to]);
        self::notifyStudent($id, 'Document released', 'Your request ' . $r['ref_no'] . ' was released to ' . $to . '.');
    }

    public static function cancel(int $id, string $reason): void
    {
        Auth::require('documents.issue');
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new AppError('Enter the reason for cancelling.');
        $r = self::get($id) ?: throw new AppError('Request not found.');
        if (in_array($r['status'], ['RELEASED', 'CANCELLED'], true)) throw new AppError('This request is already ' . strtolower(self::STATUS[$r['status']][0]) . '.');
        if (Db::val("SELECT 1 FROM sis_document_payments WHERE request_id = ? AND status = 'POSTED'", [$id])) throw new AppError('This request is paid. The Cashier must void its O.R. (and refund) before it can be cancelled.');
        Db::q("UPDATE sis_document_requests SET status = 'CANCELLED', cancelled_at = NOW(), cancel_reason = ? WHERE id = ?", [mb_substr($reason, 0, 255), $id]);
        Audit::log('document_request_cancelled', 'document_request', $id, ['by' => 'registrar', 'reason' => $reason]);
        self::notifyStudent($id, 'Request cancelled', 'Your request ' . $r['ref_no'] . ' was cancelled: ' . $reason);
    }

    // ------------------------------------------------------------------ notices

    private static function notifyStaff(string $role, string $title, string $message, int $id): void
    {
        Db::insert('sis_notifications', ['recipient_role' => $role, 'type' => 'DOC_REQUEST', 'title' => $title, 'message' => mb_substr($message, 0, 1000), 'entity_type' => 'document_request', 'entity_id' => (string) $id, 'is_read' => 0]);
    }

    /** In-app notice to the student, and an email when email sending is set up (best effort). */
    private static function notifyStudent(int $id, string $title, string $message): void
    {
        $r = self::get($id);
        $uid = Auth::central()->legacyId((int) $r['account_id'], 'SIS');
        if ($uid) Db::insert('sis_notifications', ['recipient_user_id' => (int) $uid, 'type' => 'DOC_REQUEST', 'title' => $title, 'message' => mb_substr($message, 0, 1000), 'entity_type' => 'document_request', 'entity_id' => (string) $id, 'is_read' => 0]);
        $email = AccountEmail::forReset((int) $r['account_id']);
        if ($email !== '' && !Mailer::missing()) {
            try { Mailer::send($email, (string) $r['student_name'], $title . ' · ' . $r['ref_no'], '<p>' . h($message) . '</p><p>You can follow your requests in the Student Portal → Documents.</p>'); }
            catch (AppError $e) { error_log('Document request email failed: ' . $e->getMessage()); }
        }
    }

    public static function forStudentNotices(int $sisUserId, int $limit = 10): array
    {
        return Db::all("SELECT * FROM sis_notifications WHERE recipient_user_id = ? AND type = 'DOC_REQUEST' ORDER BY id DESC LIMIT " . max(1, min(50, $limit)), [$sisUserId]);
    }
}
