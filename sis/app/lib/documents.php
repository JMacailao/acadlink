<?php
declare(strict_types=1);

/**
 * TOR and certificates (v50): student details for documents, e-signatures, and issued documents.
 * Templates are plain PHP files in sis/templates/documents/<type>/ (see the README there).
 */

/** Personal details printed on documents. All optional; a TOR and a Good Moral need some of them before issuing. */
final class StudentDetails
{
    public const FIELDS = [
        'sex' => 'Sex', 'birth_date' => 'Birth date', 'birth_place' => 'Birth place', 'home_address' => 'Home address', 'mobile' => 'Mobile number',
        'date_admitted' => 'Date admitted', 'entrance_credentials' => 'Entrance credentials',
        'graduation_date' => 'Graduation date', 'so_number' => 'SO number',
    ];
    public const SEXES = ['Male', 'Female'];
    /** What a student may update in the Student Portal (My profile); the rest is the registrar's. */
    public const OWN = ['sex', 'birth_date', 'birth_place', 'home_address', 'mobile'];

    public static function install(): void
    {
        Db::q('CREATE TABLE IF NOT EXISTS sis_student_details (
            student_doc_id VARCHAR(191) NOT NULL PRIMARY KEY,
            sex VARCHAR(10) NULL, birth_date DATE NULL, birth_place VARCHAR(191) NULL, home_address VARCHAR(255) NULL, mobile VARCHAR(30) NULL,
            date_admitted DATE NULL, entrance_credentials VARCHAR(255) NULL, graduation_date DATE NULL, so_number VARCHAR(100) NULL,
            updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        // v51: mobile number
        if (!Db::val("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_student_details' AND column_name = 'mobile'"))
            Db::q('ALTER TABLE sis_student_details ADD COLUMN mobile VARCHAR(30) NULL AFTER home_address');
    }

    public static function get(string $studentDocId): array
    {
        $row = Db::one('SELECT * FROM sis_student_details WHERE student_doc_id = ?', [$studentDocId]) ?: [];
        $out = [];
        foreach (self::FIELDS as $k => $_) $out[$k] = (string) ($row[$k] ?? '');
        return $out;
    }

    /** The student updates their own details in the Student Portal (only the fields in OWN). */
    public static function saveOwn(array $in): void
    {
        $a = Auth::central()->current();
        if (!$a || $a['account_kind'] !== 'STUDENT' || empty($a['person_verified']) || empty($a['student_doc_id'])) throw new Forbidden('Only a student with a verified record can update these details.');
        self::write((string) $a['student_doc_id'], $in, self::OWN, 'student_updated_own_details');
    }

    public static function save(string $studentDocId, array $in): void
    {
        Auth::require('students.edit');
        if (!GS::get('students', $studentDocId)) throw new AppError('Student not found.');
        self::write($studentDocId, $in, array_keys(self::FIELDS), 'student_details_updated');
    }

    private static function write(string $studentDocId, array $in, array $fields, string $event): void
    {
        $v = [];
        foreach (array_intersect_key(self::FIELDS, array_flip($fields)) as $k => $label) {
            $x = trim(preg_replace('/\s+/', ' ', (string) ($in[$k] ?? '')));
            if ($x === '') { $v[$k] = null; continue; }
            if (in_array($k, ['birth_date', 'date_admitted', 'graduation_date'], true)) {
                $d = DateTime::createFromFormat('!Y-m-d', $x);
                if (!$d || $d->format('Y-m-d') !== $x) throw new AppError("$label: choose a valid date.");
                if ($d > new DateTime('tomorrow')) throw new AppError("$label can't be in the future.");
            }
            if ($k === 'sex' && !in_array($x, self::SEXES, true)) throw new AppError('Sex: choose Male or Female.');
            if ($k === 'mobile' && !preg_match('/^\+?[0-9][0-9 \-]{6,18}$/', $x)) throw new AppError('Mobile number: digits only, e.g. 0917 123 4567 or +63 917 123 4567.');
            if (mb_strlen($x) > ($k === 'home_address' || $k === 'entrance_credentials' ? 255 : 191)) throw new AppError("$label is too long.");
            $v[$k] = $x;
        }
        $before = self::get($studentDocId);
        Db::q('INSERT INTO sis_student_details (student_doc_id, ' . implode(', ', array_keys($v)) . ', updated_by) VALUES (?' . str_repeat(', ?', count($v)) . ', ?)
               ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($k) => "$k = VALUES($k)", array_keys($v))) . ', updated_by = VALUES(updated_by)',
            array_merge([$studentDocId], array_values($v), [Auth::id()]));
        $changed = array_filter(array_keys($v), fn($k) => (string) ($v[$k] ?? '') !== $before[$k]);
        if ($changed) Audit::log($event, 'student', 0, ['student' => $studentDocId, 'changed' => array_values($changed)]);
    }

    /** Fields a document type needs before it can be issued, that this student has not filled in. */
    public static function missing(string $type, array $details, array $student): array
    {
        $need = match ($type) {
            'tor' => ['sex', 'birth_date', 'birth_place', 'home_address', 'date_admitted', 'entrance_credentials'],
            'good-moral' => ['sex', 'date_admitted'],
            default => [],
        };
        if ($type === 'tor' && strtolower((string) ($student['status'] ?? '')) === 'graduated') array_push($need, 'graduation_date', 'so_number');
        return array_values(array_map(fn($k) => self::FIELDS[$k], array_filter($need, fn($k) => trim($details[$k] ?? '') === '')));
    }
}

/** E-signatures of staff accounts. Only the owner applies their own; a Power User manages them but has none. */
final class ESign
{
    public static function install(): void
    {
        Db::q('CREATE TABLE IF NOT EXISTS central_esignatures (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            image MEDIUMBLOB NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');        Db::q("CREATE TABLE IF NOT EXISTS central_account_names (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            title VARCHAR(60) NOT NULL DEFAULT '', first_name VARCHAR(60) NOT NULL DEFAULT '', middle_name VARCHAR(60) NOT NULL DEFAULT '',
            last_name VARCHAR(60) NOT NULL DEFAULT '', suffix VARCHAR(60) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** Name parts behind an account's display name (My profile). */
    public const NAME_PARTS = ['title' => 'Title', 'first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name', 'suffix' => 'Suffix'];

    public static function nameParts(int $accountId): array
    {
        $row = Db::one('SELECT * FROM central_account_names WHERE account_id = ?', [$accountId]);
        if ($row) return array_map(fn($k) => (string) ($row[$k] ?? ''), array_combine(array_keys(self::NAME_PARTS), array_keys(self::NAME_PARTS)));
        // Not saved yet: split the display name ("Ana M. Dela Rosa" → first "Ana M.", last "Dela Rosa" is a guess the person corrects)
        $words = preg_split('/\s+/', trim((string) (Auth::central()->account($accountId)['display_name'] ?? ''))) ?: [];
        $last = count($words) > 1 ? array_pop($words) : '';
        return ['title' => '', 'first_name' => implode(' ', $words), 'middle_name' => '', 'last_name' => $last, 'suffix' => ''];
    }

    /** "Prof. Ana M. Dela Rosa Jr." from the parts (middle name as an initial). */
    public static function composeName(array $p): string
    {
        $mid = trim($p['middle_name'] ?? '');
        $mid = $mid === '' ? '' : (mb_strlen($mid) <= 2 ? rtrim($mid, '.') . '.' : mb_strtoupper(mb_substr($mid, 0, 1)) . '.');
        return trim(preg_replace('/\s+/', ' ', implode(' ', [trim($p['title'] ?? ''), trim($p['first_name'] ?? ''), $mid, trim($p['last_name'] ?? ''), trim($p['suffix'] ?? '')])));
    }

    /** The signed-in person changes their own name (immediately). Teachers: their teacher record follows. */
    public static function saveOwnName(array $in, string $password): string
    {
        $me = (int) (Auth::central()->current()['id'] ?? 0);
        if (!$me) throw new AuthRequired();
        if (!Auth::central()->verifyPassword($me, $password)) throw new AppError('Your password is not correct.');
        $p = [];
        foreach (self::NAME_PARTS as $k => $label) {
            $p[$k] = trim(preg_replace('/\s+/', ' ', (string) ($in[$k] ?? '')));
            if (mb_strlen($p[$k]) > 60) throw new AppError("$label is too long.");
            if ($p[$k] !== '' && !preg_match("/^[\p{L}\p{M} .,'’-]+$/u", $p[$k])) throw new AppError("$label: use letters, spaces, periods, commas, apostrophes or hyphens only.");
        }
        if ($p['first_name'] === '' || $p['last_name'] === '') throw new AppError('Enter your first and last name.');
        $name = self::composeName($p);
        $a = Auth::central()->account($me); $old = (string) $a['display_name'];
        Db::tx(function () use ($me, $p, $name, $a) {
            Db::q('INSERT INTO central_account_names (account_id, title, first_name, middle_name, last_name, suffix) VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE title = VALUES(title), first_name = VALUES(first_name), middle_name = VALUES(middle_name), last_name = VALUES(last_name), suffix = VALUES(suffix)',
                [$me, $p['title'], $p['first_name'], $p['middle_name'], $p['last_name'], $p['suffix']]);
            Auth::central()->q('UPDATE central_accounts SET display_name = ? WHERE id = ?', [$name, $me]);
            if (!empty($a['teacher_doc_id']) && !empty($a['person_verified'])) GS::renameOwnTeacher((string) $a['teacher_doc_id'], $name);
        });
        Auth::central()->clearCache();
        if ($old !== $name) Auth::central()->audit($me, $me, 'own_name_changed', ['from' => $old, 'to' => $name]);
        return $name;
    }

    public static function isPowerUser(int $accountId): bool { return Auth::central()->group($accountId) === 'POWER_USER'; }
    /** Staff accounts can have an e-signature; Power Users and students can't. */
    public static function canHave(int $accountId): bool
    {
        $a = Auth::central()->account($accountId);
        return $a && $a['account_kind'] === 'STAFF' && !self::isPowerUser($accountId);
    }
    public static function has(int $accountId): bool { return (bool) Db::val('SELECT 1 FROM central_esignatures WHERE account_id = ?', [$accountId]); }
    public static function dataUri(int $accountId): string
    {
        $img = Db::val('SELECT image FROM central_esignatures WHERE account_id = ?', [$accountId]);
        return $img ? 'data:image/png;base64,' . base64_encode((string) $img) : '';
    }

    /**
     * Saves a signature (PNG/JPEG bytes from a drawing or an uploaded photo) for $accountId.
     * The owner, or a Power User for someone else; both re-enter their own password.
     */
    public static function set(int $accountId, string $bytes, string $password): void
    {
        $actor = (int) (Auth::central()->current()['id'] ?? 0);
        self::checkManager($actor, $accountId, $password);
        $png = self::clean($bytes);
        Db::q('INSERT INTO central_esignatures (account_id, image, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE image = VALUES(image), updated_by = VALUES(updated_by)', [$accountId, $png, $actor]);
        Auth::central()->audit($actor, $accountId, 'esignature_saved', ['by_power_user' => $actor !== $accountId]);
        if ($actor !== $accountId) self::notifyOwner($accountId, 'Your e-signature was changed by ' . (Auth::central()->account($actor)['display_name'] ?? 'the Power User') . '.');
    }

    public static function remove(int $accountId, string $password): void
    {
        $actor = (int) (Auth::central()->current()['id'] ?? 0);
        self::checkManager($actor, $accountId, $password);
        Db::q('DELETE FROM central_esignatures WHERE account_id = ?', [$accountId]);
        Auth::central()->audit($actor, $accountId, 'esignature_removed', ['by_power_user' => $actor !== $accountId]);
        if ($actor !== $accountId) self::notifyOwner($accountId, 'Your e-signature was removed by ' . (Auth::central()->account($actor)['display_name'] ?? 'the Power User') . '.');
    }

    private static function checkManager(int $actor, int $accountId, string $password): void
    {
        if (!$actor) throw new AuthRequired();
        if ($actor !== $accountId && !self::isPowerUser($actor)) throw new Forbidden('Only the owner or a Power User can change an e-signature.');
        if (!self::canHave($accountId)) throw new AppError(self::isPowerUser($accountId) ? 'Power Users have no e-signature: what they approve shows "Approved".' : 'This account cannot have an e-signature.');
        if (!Auth::central()->verifyPassword($actor, $password)) throw new AppError('Your password is not correct.');
    }

    private static function notifyOwner(int $accountId, string $message): void
    {
        $uid = Auth::central()->legacyId($accountId, 'SIS');
        if ($uid) Db::insert('sis_notifications', ['recipient_user_id' => (int) $uid, 'type' => 'ESIGN_CHANGED', 'title' => 'E-signature changed', 'message' => $message, 'entity_type' => 'account', 'entity_id' => (string) $accountId, 'is_read' => 0]);
    }

    /** Checks the image, removes a light paper background (when GD is available) and returns PNG bytes. */
    public static function clean(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > 2 * 1024 * 1024) throw new AppError('Choose a signature image smaller than 2 MB.');
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) throw new AppError('The signature must be a PNG or JPG image.');
        if ($info[0] < 20 || $info[1] < 10) throw new AppError('The signature image is too small.');
        if (!function_exists('imagecreatefromstring')) {
            if ($info[2] !== IMAGETYPE_PNG) throw new AppError('This server can only take a PNG signature. Draw it instead, or upload a PNG.');
            return $bytes;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) throw new AppError('The signature image could not be read.');
        // At most 600 × 200, keeping the shape
        $w = imagesx($src); $h = imagesy($src); $k = min(1, 600 / $w, 200 / $h);
        $nw = max(1, (int) round($w * $k)); $nh = max(1, (int) round($h * $k));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        // Light pixels (paper) become transparent; ink keeps its colour
        for ($y = 0; $y < $nh; $y++) for ($x = 0; $x < $nw; $x++) {
            $c = imagecolorat($out, $x, $y); $a = ($c >> 24) & 127;
            $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
            if ($a < 127 && ($r + $g + $b) / 3 > 200) imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, 127));
        }
        ob_start(); imagepng($out); $png = (string) ob_get_clean();
        imagedestroy($src); imagedestroy($out);
        return $png;
    }
}

/** Issued TOR and certificates: build the data, render a template, issue with a control number, reprint, void. */
final class Documents
{
    /** type => [title, needs a term, control number prefix] */
    public const TYPES = [
        'tor' => ['Transcript of Records', false, 'TOR'],
        'enrollment' => ['Certificate of Enrollment', true, 'COE'],
        'grades' => ['Certificate of Grades', true, 'COG'],
        'good-moral' => ['Certificate of Good Moral Character', false, 'GMC'],
        'honorable-dismissal' => ['Certificate of Honorable Dismissal', false, 'HD'],
    ];

    public static function dir(): string { return SIS_ROOT . '/templates/documents'; }

    public static function install(PDO $db): void
    {
        StudentDetails::install();
        ESign::install();
        Db::q("CREATE TABLE IF NOT EXISTS sis_documents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            control_no VARCHAR(40) NULL,
            doc_type VARCHAR(40) NOT NULL, template VARCHAR(80) NOT NULL,
            student_doc_id VARCHAR(191) NOT NULL, student_number VARCHAR(40) NOT NULL, student_name VARCHAR(200) NOT NULL,
            school_year VARCHAR(9) NULL, term VARCHAR(20) NULL, purpose VARCHAR(200) NOT NULL DEFAULT '',
            status ENUM('PREPARED','ISSUED','VOID') NOT NULL,
            html MEDIUMTEXT NULL,
            prepared_by BIGINT UNSIGNED NOT NULL, prepared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            signer_account BIGINT UNSIGNED NULL,
            signed_by BIGINT UNSIGNED NULL, signed_at DATETIME NULL, sign_mode ENUM('ESIGN','APPROVED') NULL,
            prints INT UNSIGNED NOT NULL DEFAULT 0,
            void_reason VARCHAR(255) NULL, voided_by BIGINT UNSIGNED NULL, voided_at DATETIME NULL,
            UNIQUE KEY u_sis_doc_control (control_no),
            KEY k_sis_doc_student (student_doc_id), KEY k_sis_doc_signer (signer_account, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        Db::q('CREATE TABLE IF NOT EXISTS sis_document_counters (
            prefix VARCHAR(10) NOT NULL, yr SMALLINT UNSIGNED NOT NULL, last_no INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (prefix, yr)
        ) ENGINE=InnoDB');
        // Access: Documents module (View / Issue / Reissue or void); Registrars and Power Users get it once
        if (!$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='central_permissions'")->fetchColumn()) return;
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES
            ('sis.documents.view','SIS','View issued TOR and certificates'),
            ('sis.documents.issue','SIS','Prepare, sign and issue TOR and certificates'),
            ('sis.documents.void','SIS','Reissue or void TOR and certificates')");
        $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                   SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON c.code IN ('sis.documents.view','sis.documents.issue','sis.documents.void')
                   WHERE g.code IN ('POWER_USER','REGISTRAR') AND NOT EXISTS (SELECT 1 FROM central_schema_migrations WHERE migration_key='documents_v1')");
        $db->exec("INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES ('documents_v1')");
    }

    // ------------------------------------------------------------------ templates

    /** Templates of a type: [id => ['name' => …, 'paper' => 'A4'|'Legal'|'Letter']], standard first. */
    public static function templates(string $type): array
    {
        if (!isset(self::TYPES[$type])) return [];
        $out = [];
        foreach (glob(self::dir() . '/' . $type . '/*.php') ?: [] as $f) {
            $id = basename($f, '.php');
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $id)) continue;
            $head = (string) file_get_contents($f, false, null, 0, 600);
            $name = preg_match('/Template:\s*(.+?)\s*(?:\*\/|\n)/', $head, $m) ? trim($m[1]) : $id;
            $paper = preg_match('/Paper:\s*(A4|Legal|Letter)/i', $head, $m) ? ucfirst(strtolower($m[1])) : 'A4';
            $out[$id] = ['name' => $name, 'paper' => $paper === 'A4' ? 'A4' : $paper];
        }
        uksort($out, fn($a, $b) => ($a !== 'standard') <=> ($b !== 'standard') ?: strcmp($a, $b));
        return $out;
    }

    /** The saved default template for a document type, falling back safely if a template was removed. */
    public static function defaultTemplate(string $type): string
    {
        $templates = self::templates($type);
        if (!$templates) return '';
        $saved = (string) Settings::get('doc_template_' . $type);
        if (isset($templates[$saved])) return $saved;
        return isset($templates['standard']) ? 'standard' : (string) array_key_first($templates);
    }

    /** Renders a template with $doc; a template with a mistake gives a message naming its file and line. */
    public static function render(string $type, string $template, array $doc): string
    {
        $file = self::dir() . '/' . $type . '/' . $template . '.php';
        if (!isset(self::templates($type)[$template]) || !is_file($file)) throw new AppError('Choose a template.');
        require_once self::dir() . '/_helpers.php';
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $doc): void { include $__file; })($file, $doc);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            $where = str_replace(SIS_ROOT . '/', 'sis/', $e->getFile()) . ', line ' . $e->getLine();
            return '<!doctype html><meta charset="utf-8"><div style="font:15px system-ui;margin:24px;padding:16px;border:2px solid #b42318;border-radius:10px;color:#7a1b12;background:#fff4f2">'
                . '<strong>This template has an error:</strong> ' . htmlspecialchars($where) . '<br><span style="font-size:13px">' . htmlspecialchars($e->getMessage()) . '</span></div>';
        }
    }

    // ------------------------------------------------------------------ data

    /** Terms the student has final grades or a confirmed enrollment for: ["2026-2027|1st Semester", …], newest first. */
    public static function terms(string $studentDocId): array
    {
        $t = [];
        foreach (GS::completions($studentDocId) as $c) $t[($c['schoolYear'] ?? '') . '|' . ($c['term'] ?? '')] = true;
        foreach (Db::all("SELECT school_year, term FROM sis_registrations WHERE student_doc_id = ? AND status = 'REGISTERED'", [$studentDocId]) as $r) $t[$r['school_year'] . '|' . $r['term']] = true;
        $keys = array_keys($t);
        $rank = fn($k) => [explode('|', $k)[0], array_search(explode('|', $k)[1] ?? '', TERMS, true)];
        usort($keys, fn($a, $b) => $rank($b) <=> $rank($a));
        return $keys;
    }

    /** Everything a template can use (see sis/templates/documents/README.md). */
    public static function data(string $type, array $student, array $opts = []): array
    {
        if (!isset(self::TYPES[$type])) throw new AppError('Choose the document type.');
        [$title, $needsTerm] = self::TYPES[$type];
        $scale = GradeScale::current();
        $details = StudentDetails::get((string) $student['id']);
        [$sy, $term] = array_pad(explode('|', (string) ($opts['term'] ?? '')), 2, '');
        if ($needsTerm && $sy === '') throw new AppError('Choose the term.');

        // Final grades (tagged complete in the Grading System), oldest term first
        $periods = [];
        foreach (array_reverse(GS::completions((string) $student['id'])) as $c) {
            if ($type === 'grades' && (($c['schoolYear'] ?? '') !== $sy || ($c['term'] ?? '') !== $term)) continue;
            $subs = [];
            foreach ((array) ($c['subjects'] ?? []) as $s) {
                $grade = is_numeric($s['finalGrade'] ?? null) ? (float) $s['finalGrade'] : null;
                $mark = GradeScale::markCode($s['finalGrade'] ?? null, $s['remarks'] ?? null) ?: (in_array($s['mark'] ?? '', ['INC', 'DRP'], true) ? $s['mark'] : '');
                if ($mark) $grade = null;
                $subs[] = [
                    'code' => (string) ($s['subjectCode'] ?? ''), 'title' => (string) ($s['subjectName'] ?? ''), 'units' => (float) ($s['units'] ?? 0),
                    'finalGrade' => $grade, 'mark' => $mark,
                    'grade' => $mark ?: ($grade === null ? '' : $scale->gradeText($grade)),
                    'equivalent' => $mark ? $mark : ($grade === null || !$scale->finalDiffers() ? '' : number_format((float) $scale->toFinal($grade), 2)),
                    'remarks' => $mark ? GradeScale::MARKS[$mark] : ($grade === null ? '' : ((string) ($s['remarks'] ?? '') ?: $scale->remarksFor($grade))),
                ];
            }
            usort($subs, fn($a, $b) => strnatcmp($a['code'], $b['code']));
            $periods[] = ['school_year' => (string) ($c['schoolYear'] ?? ''), 'term' => (string) ($c['term'] ?? ''), 'year_level' => (string) ($c['yearLevel'] ?? ''),
                'section' => (string) ($c['sectionName'] ?? ''), 'subjects' => $subs] + self::average($scale, $subs);
        }
        $all = array_merge(...array_map(fn($p) => $p['subjects'], $periods ?: [['subjects' => []]]));

        $reg = null;
        if ($sy !== '') $reg = Db::one("SELECT * FROM sis_registrations WHERE student_doc_id = ? AND school_year = ? AND term = ? AND status = 'REGISTERED' ORDER BY id DESC LIMIT 1", [$student['id'], $sy, $term]);
        $last = Db::one("SELECT * FROM sis_registrations WHERE student_doc_id = ? AND status = 'REGISTERED' AND locked_at IS NOT NULL ORDER BY school_year DESC, FIELD(term,'Summer','3rd Semester','2nd Semester','1st Semester') LIMIT 1", [$student['id']]);
        if ($type === 'enrollment' && (!$reg || !$reg['locked_at'])) throw new AppError("There is no confirmed enrollment for $sy" . ($term ? " $term" : '') . '. A Certificate of Enrollment needs a confirmed enrollment.');
        if ($type === 'grades' && !$periods) throw new AppError("There are no final grades for $sy" . ($term ? " $term" : '') . ' yet (tagged complete in the Grading System).');
        $program = (string) (($reg['program'] ?? null) ?: ($last['program'] ?? ''));
        $enrollSubjects = $reg ? Db::all('SELECT subject_code AS code, subject_name AS title, units FROM sis_registration_subjects WHERE registration_id = ? ORDER BY subject_code', [$reg['id']]) : [];
        $lastAttended = $periods ? end($periods) : null;

        return [
            'type' => $type, 'title' => $title, 'template' => (string) ($opts['template'] ?? 'standard'),
            'control_no' => (string) ($opts['control_no'] ?? ''), 'purpose' => trim((string) ($opts['purpose'] ?? '')),
            'issued_at' => (string) ($opts['issued_at'] ?? date('Y-m-d')), 'preview' => !empty($opts['preview']),
            'school' => ['name' => School::brand(), 'address' => Settings::get('print_address') ?: (string) (self::schoolSetting('address')), 'contact' => Settings::get('print_contact') ?: (string) self::schoolSetting('contact'), 'logo' => School::logo()],
            'student' => [
                'id' => (string) ($student['studentId'] ?? ''), 'name' => (string) ($student['studentName'] ?? ''), 'email' => (string) ($student['email'] ?? ''),
                'program' => $program, 'year_level' => (string) ($student['yearLevel'] ?? ''), 'section' => (string) ($student['sectionName'] ?? ''),
                'status' => strtolower((string) ($student['status'] ?? '')) === 'graduated' ? 'Graduated' : 'Active',
            ] + $details,
            'periods' => $periods,
            'totals' => self::average($scale, $all) + ['units_earned' => array_sum(array_map(fn($s) => $s['remarks'] === 'Passed' ? $s['units'] : 0, $all))],
            'scale' => ['grading' => $scale->label(), 'final' => $scale->label($scale->finalScale()), 'differs' => $scale->finalDiffers(), 'legend' => $scale->legend()],
            'term' => ['school_year' => $sy, 'term' => $term],
            'enrollment' => $reg ? ['program' => (string) $reg['program'], 'year_level' => (string) $reg['year_level'], 'section' => (string) $reg['section_name'], 'units' => (float) $reg['total_units'], 'subjects' => $enrollSubjects, 'confirmed_at' => (string) $reg['locked_at']] : null,
            'last_attended' => $lastAttended ? ['school_year' => $lastAttended['school_year'], 'term' => $lastAttended['term']] : null,
            'signature' => $opts['signature'] ?? ['mode' => 'PREVIEW'],
            'signatories' => ['prepared_by' => Settings::get('doc_prepared_by'), 'checked_by' => Settings::get('doc_checked_by')],
        ];
    }

    /** Safe fictional data for previewing a template from Settings without opening a real student's record. */
    public static function sampleData(string $type, string $template): array
    {
        if (!isset(self::TYPES[$type]) || !isset(self::templates($type)[$template])) throw new AppError('Choose an available document template.');
        $scale = GradeScale::current();
        $numbers = match ($scale->scale()) {
            'point1' => [1.75, 1.50],
            'point5' => [4.25, 4.50],
            default => [88.0, 92.0],
        };
        $subjects = [];
        foreach ([['ENG101', 'Academic Communication', 3.0], ['IT101', 'Introduction to Computing', 3.0]] as $i => [$code, $title, $units]) {
            $grade = $numbers[$i];
            $subjects[] = ['code' => $code, 'title' => $title, 'units' => $units, 'grade' => $scale->gradeText($grade),
                'equivalent' => $scale->finalDiffers() ? number_format((float) $scale->toFinal($grade), 2) : '', 'remarks' => $scale->remarksFor($grade),
                'finalGrade' => $grade, 'mark' => ''];
        }
        $period = ['school_year' => '2025-2026', 'term' => '1st Semester', 'year_level' => '1st Year', 'section' => 'BSIT 1A', 'subjects' => $subjects] + self::average($scale, $subjects);
        $totals = self::average($scale, $subjects);
        $totals['units_earned'] = array_sum(array_map(fn($s) => $s['remarks'] === 'Passed' ? $s['units'] : 0, $subjects));
        return [
            'type' => $type, 'title' => self::TYPES[$type][0], 'template' => $template, 'control_no' => '', 'purpose' => 'Template preview',
            'issued_at' => date('Y-m-d'), 'preview' => true, 'school' => self::school(),
            'student' => ['id' => 'SAMPLE-001', 'name' => 'Alex Sample', 'email' => '', 'program' => 'BS Information Technology', 'year_level' => '1st Year',
                'section' => 'BSIT 1A', 'status' => 'Active', 'sex' => 'Female', 'birth_date' => '2007-04-18', 'birth_place' => 'Sample City',
                'home_address' => '123 Sample Street, Sample City', 'date_admitted' => '2025-06-02', 'entrance_credentials' => 'High School Record',
                'graduation_date' => '', 'so_number' => ''],
            'periods' => [$period], 'totals' => $totals,
            'scale' => ['grading' => $scale->label(), 'final' => $scale->label($scale->finalScale()), 'differs' => $scale->finalDiffers(), 'legend' => $scale->legend()],
            'term' => ['school_year' => '2025-2026', 'term' => '1st Semester'],
            'enrollment' => ['program' => 'BS Information Technology', 'year_level' => '1st Year', 'section' => 'BSIT 1A', 'units' => 6.0,
                'subjects' => [['code' => 'ENG101', 'title' => 'Academic Communication', 'units' => 3.0], ['code' => 'IT101', 'title' => 'Introduction to Computing', 'units' => 3.0]], 'confirmed_at' => '2025-06-02 09:00:00'],
            'last_attended' => ['school_year' => '2025-2026', 'term' => '1st Semester', 'year_level' => '1st Year', 'section' => 'BSIT 1A'],
            'signature' => ['mode' => 'PREVIEW'], 'signatories' => ['prepared_by' => Settings::get('doc_prepared_by'), 'checked_by' => Settings::get('doc_checked_by')],
        ];
    }

    /** The letterhead of printouts: school name, address, contact, logo. */
    public static function school(): array
    {
        return ['name' => School::brand(), 'address' => Settings::get('print_address') ?: self::schoolSetting('address'), 'contact' => Settings::get('print_contact') ?: self::schoolSetting('contact'), 'logo' => School::logo()];
    }

    private static function schoolSetting(string $k): string
    {
        $raw = Db::val("SELECT data FROM docs WHERE col = 'settings' AND id = 'school'");
        return (string) (($raw ? json_decode((string) $raw, true) : [])[$k] ?? '');
    }

    /** GWA of subjects, as printed: ['units', 'gwa', 'gwa_text', 'equivalent_text', 'remarks']. */
    private static function average(GradeScale $scale, array $subs): array
    {
        $w = GradeScale::weighted($subs);
        if ($w['gwa'] === null) return ['units' => $w['units'], 'gwa' => null, 'gwa_text' => '—', 'equivalent_text' => '', 'remarks' => ''];
        $g = GradeScale::round2($w['gwa']);
        return ['units' => $w['units'], 'gwa' => $g, 'gwa_text' => number_format($g, 2),
            'equivalent_text' => $scale->finalDiffers() ? number_format((float) $scale->toFinal($g), 2) : '',
            'remarks' => $scale->finalDiffers() ? $scale->finalRemarks((float) $scale->toFinal($g)) : $scale->gwaRemarks($g)];
    }

    // ------------------------------------------------------------------ signing and issuing

    /** The account that signs a document type (Settings → Documents), or null = whoever issues it. */
    public static function signer(string $type): ?int
    {
        $id = (int) Settings::get('doc_signer_' . $type);
        return $id > 0 && Auth::central()->account($id) ? $id : null;
    }

    private static function me(): int { return (int) (Auth::central()->current()['id'] ?? 0); }

    /** The signature block for the current account: an e-signature, or "Approved" for a Power User. */
    private static function signatureFor(int $account, string $type): array
    {
        $a = Auth::central()->account($account);
        $position = ESign::isPowerUser($account) ? (string) (Auth::central()->groups($account)[0]['name'] ?? 'Power User') : (Settings::get('doc_signer_position_' . $type) ?: 'Registrar');
        if (ESign::isPowerUser($account)) return ['mode' => 'APPROVED', 'name' => (string) $a['display_name'], 'position' => $position, 'signed_at' => date('Y-m-d H:i')];
        if (!ESign::has($account)) throw new AppError('Add your e-signature first: open My profile → E-signature.');
        return ['mode' => 'ESIGN', 'image' => ESign::dataUri($account), 'name' => (string) $a['display_name'], 'position' => $position, 'signed_at' => date('Y-m-d H:i')];
    }

    /** Fields to fill before issuing (TOR and Good Moral), or [] when it can be issued. */
    public static function blockers(string $type, array $student): array
    {
        return StudentDetails::missing($type, StudentDetails::get((string) $student['id']), $student);
    }

    /**
     * Issue (or prepare for the signatory): the signatory (or a Power User) signs and issues at once;
     * anyone else with Issue permission prepares it for the signatory's "To sign" list.
     */
    public static function issue(string $studentDocId, array $in): int
    {
        $requestId = (int) ($in['request_id'] ?? 0);
        if ($requestId > 0) {
            $req = DocumentRequests::get($requestId) ?: throw new AppError('Request not found.');
            if ($req['student_doc_id'] !== $studentDocId || $req['doc_type'] !== (string) ($in['type'] ?? '')) throw new AppError('This request is for another student or document.');
        }
        Auth::require('documents.issue');
        $student = GS::get('students', $studentDocId) ?: throw new AppError('Student not found.');
        $type = (string) ($in['type'] ?? ''); $template = (string) ($in['template'] ?? '');
        if (!isset(self::TYPES[$type])) throw new AppError('Choose the document type.');
        if (!isset(self::templates($type)[$template])) throw new AppError('Choose a template.');
        $purpose = trim(preg_replace('/\s+/', ' ', (string) ($in['purpose'] ?? '')));
        if ($purpose === '') throw new AppError('Enter the purpose (e.g. Employment, Board exam, Transfer).');
        if (mb_strlen($purpose) > 200) throw new AppError('Purpose is too long.');
        if ($missing = self::blockers($type, $student)) throw new AppError('Fill in before issuing: ' . implode(', ', $missing) . '. (Student → Records for documents)');
        $opts = ['template' => $template, 'purpose' => $purpose, 'term' => (string) ($in['term'] ?? '')];
        self::data($type, $student, $opts); // checks the term, enrollment and grades now
        $me = self::me(); $signer = self::signer($type);
        $signsNow = $signer === null || $signer === $me || ESign::isPowerUser($me);
        [$sy, $term] = array_pad(explode('|', $opts['term']), 2, '');
        // One transaction: if signing fails (no e-signature, template error) nothing is left behind
        return Db::tx(function () use ($type, $template, $studentDocId, $student, $sy, $term, $purpose, $signsNow, $me, $signer, $requestId) {
        $id = Db::insert('sis_documents', ['doc_type' => $type, 'template' => $template, 'student_doc_id' => $studentDocId,
            'student_number' => (string) ($student['studentId'] ?? ''), 'student_name' => (string) ($student['studentName'] ?? ''),
            'school_year' => $sy ?: null, 'term' => $term ?: null, 'purpose' => $purpose, 'status' => 'PREPARED',
            'prepared_by' => $me, 'signer_account' => $signsNow ? $me : $signer]);
        if ($signsNow) self::sign($id);
        if ($requestId > 0) DocumentRequests::linkDocument($requestId, $id, $signsNow);
        if (!$signsNow) Audit::log('document_prepared', 'document', $id, ['type' => $type, 'student' => $student['studentId'] ?? '', 'for_signer' => $signer]);
        return $id;
        });
    }

    public const SETTINGS = ['doc_prepared_by' => 'Prepared by', 'doc_checked_by' => 'Checked by'];

    /** Settings → Documents: who signs each type, their position, and the Prepared by / Checked by names. */
    public static function saveSettings(array $in): void
    {
        Auth::require('settings.manage');
        $v = [];
        foreach (self::TYPES as $type => $_) {
            $template = trim((string) ($in['doc_template_' . $type] ?? 'standard'));
            if (!isset(self::templates($type)[$template])) throw new AppError('Choose an available template for ' . self::TYPES[$type][0] . '.');
            $v['doc_template_' . $type] = $template;
            $signer = (int) ($in['doc_signer_' . $type] ?? 0);
            if ($signer > 0) {
                $a = Auth::central()->account($signer);
                if (!$a || $a['account_kind'] !== 'STAFF' || $a['status'] !== 'ACTIVE') throw new AppError('Choose an active staff account as signatory.');
                if (!Auth::central()->can($signer, 'sis.documents.issue')) throw new AppError($a['display_name'] . ' cannot issue documents. Give them Documents → Edit in Users and Groups first.');
            }
            $v['doc_signer_' . $type] = $signer > 0 ? (string) $signer : '';
            $v['doc_signer_position_' . $type] = mb_substr(trim((string) ($in['doc_signer_position_' . $type] ?? '')), 0, 80);
            // v52: Student Portal requests
            $fee = trim((string) ($in['doc_fee_' . $type] ?? '0')) ?: '0';
            $cents = (int) In::money(['f' => $fee], 'f', true, Documents::TYPES[$type][0] . ' fee', true);
            $days = trim((string) ($in['doc_days_' . $type] ?? '0'));
            if (!preg_match('/^\d{1,2}$/', $days) || (int) $days > 60) throw new AppError(Documents::TYPES[$type][0] . ': processing days must be 0 to 60.');
            $v['doc_fee_' . $type] = Money::dec($cents);
            $v['doc_days_' . $type] = (string) (int) $days;
            $v['doc_online_' . $type] = !empty($in['doc_online_' . $type]) ? '1' : '0';
        }
        foreach (self::SETTINGS as $k => $_) $v[$k] = mb_substr(trim((string) ($in[$k] ?? '')), 0, 120);
        Db::tx(function () use ($v) { foreach ($v as $k => $val) Db::q('INSERT INTO sis_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$k, $val]); });
        Audit::log('document_settings_saved', 'settings', 0, $v);
    }

    /** The signatory signs a prepared document: it gets its control number and its final copy is saved. */
    public static function sign(int $id): void
    {
        Auth::require('documents.issue');
        $me = self::me();
        Db::tx(function () use ($id, $me) {
            $d = Db::one('SELECT * FROM sis_documents WHERE id = ? FOR UPDATE', [$id]) ?: throw new AppError('Document not found.');
            if ($d['status'] !== 'PREPARED') throw new AppError('This document is already ' . strtolower($d['status']) . '.');
            if ((int) $d['signer_account'] !== $me && !ESign::isPowerUser($me)) throw new Forbidden('Only the signatory can sign this document.');
            $student = GS::get('students', $d['student_doc_id']) ?: throw new AppError('Student not found.');
            if ($missing = self::blockers($d['doc_type'], $student)) throw new AppError('Fill in before issuing: ' . implode(', ', $missing) . '.');
            $sig = self::signatureFor($me, $d['doc_type']);
            $control = self::nextControl(self::TYPES[$d['doc_type']][2]);
            $data = self::data($d['doc_type'], $student, ['template' => $d['template'], 'purpose' => $d['purpose'], 'term' => $d['school_year'] ? $d['school_year'] . '|' . $d['term'] : '',
                'control_no' => $control, 'issued_at' => date('Y-m-d'), 'signature' => $sig]);
            $html = self::render($d['doc_type'], $d['template'], $data);
            if (str_contains($html, 'This template has an error:')) throw new AppError('The template has an error; fix it before issuing (see Preview).');
            Db::q("UPDATE sis_documents SET status = 'ISSUED', control_no = ?, html = ?, signed_by = ?, signed_at = NOW(), sign_mode = ? WHERE id = ?", [$control, $html, $me, $sig['mode'], $id]);
            Audit::log('document_issued', 'document', $id, ['control_no' => $control, 'type' => $d['doc_type'], 'student' => $d['student_number'], 'signed' => $sig['mode']]);
            DocumentRequests::documentSigned($id); // a request waiting for this signature becomes ready
        });
    }

    /** Next control number of a prefix this year, e.g. TOR-2026-00012 (no gaps, no repeats). */
    private static function nextControl(string $prefix): string
    {
        $yr = (int) date('Y');
        Db::q('INSERT INTO sis_document_counters (prefix, yr, last_no) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE last_no = last_no + 1', [$prefix, $yr]);
        $n = (int) Db::val('SELECT last_no FROM sis_document_counters WHERE prefix = ? AND yr = ?', [$prefix, $yr]);
        return sprintf('%s-%d-%05d', $prefix, $yr, $n);
    }

    public static function void(int $id, string $reason): void
    {
        Auth::require('documents.void');
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new AppError('Enter the reason for voiding.');
        $d = Db::one('SELECT * FROM sis_documents WHERE id = ?', [$id]) ?: throw new AppError('Document not found.');
        if ($d['status'] === 'VOID') throw new AppError('Already void.');
        Db::q("UPDATE sis_documents SET status = 'VOID', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?", [mb_substr($reason, 0, 255), self::me(), $id]);
        Audit::log('document_voided', 'document', $id, ['control_no' => $d['control_no'], 'reason' => $reason]);
    }

    /** The saved copy of an issued document (reprint), with VOID marked on it. */
    public static function copy(int $id): string
    {
        Auth::require('documents');
        $d = Db::one('SELECT * FROM sis_documents WHERE id = ?', [$id]) ?: throw new AppError('Document not found.');
        if ($d['status'] === 'PREPARED') {
            $student = GS::get('students', $d['student_doc_id']) ?: throw new AppError('Student not found.');
            return self::render($d['doc_type'], $d['template'], self::data($d['doc_type'], $student, ['template' => $d['template'], 'purpose' => $d['purpose'],
                'term' => $d['school_year'] ? $d['school_year'] . '|' . $d['term'] : '', 'preview' => true, 'signature' => ['mode' => 'PENDING']]));
        }
        Db::q('UPDATE sis_documents SET prints = prints + 1 WHERE id = ?', [$id]);
        $html = (string) $d['html'];
        if ($d['status'] === 'VOID') $html = str_replace('</body>', '<div style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none"><div style="font:900 120px Arial;color:rgba(180,35,24,.25);transform:rotate(-30deg)">VOID</div></div></body>', $html);
        return $html;
    }

    public static function forStudent(string $studentDocId): array
    {
        return Db::all('SELECT * FROM sis_documents WHERE student_doc_id = ? ORDER BY id DESC', [$studentDocId]);
    }
    public static function toSign(): array
    {
        return Db::all("SELECT * FROM sis_documents WHERE status = 'PREPARED' AND signer_account = ? ORDER BY id", [self::me()]);
    }
    public static function search(string $q, int $limit = 200): array
    {
        $q = trim($q);
        if ($q === '') return Db::all('SELECT * FROM sis_documents ORDER BY id DESC LIMIT ' . $limit);
        $like = '%' . $q . '%';
        return Db::all('SELECT * FROM sis_documents WHERE control_no LIKE ? OR student_number LIKE ? OR student_name LIKE ? ORDER BY id DESC LIMIT ' . $limit, [$like, $like, $like]);
    }
    public static function accountName(?int $id): string
    {
        return $id ? (string) (Auth::central()->account($id)['display_name'] ?? '') : '';
    }
}
