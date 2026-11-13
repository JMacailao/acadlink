<?php
declare(strict_types=1);

/**
 * v62 Student photos, stored as bytes in the database (MEDIUMBLOB), shrunk to at most 800 px.
 * - Admission staff add a photo to an applicant; when the applicant is admitted it becomes the student's photo.
 * - Registrar staff can replace a student's photo on the student record.
 * - Students send a new photo from the Student Portal; it waits for the registrar's approval (Photo approvals).
 * Only staff who can see students, the student themself and their linked parents can open a photo.
 */
final class StudentPhoto
{
    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS sis_student_photos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, student_number VARCHAR(40) NULL, admission_id INT UNSIGNED NULL,
            status ENUM('APPROVED','PENDING','REJECTED','REPLACED') NOT NULL, source ENUM('ADMISSION','STAFF','STUDENT') NOT NULL,
            mime VARCHAR(40) NOT NULL, size_bytes INT UNSIGNED NOT NULL, bytes MEDIUMBLOB NOT NULL,
            uploaded_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_by INT UNSIGNED NULL, decided_at DATETIME NULL, reason VARCHAR(255) NULL,
            KEY k_photo_student (student_number, status, id), KEY k_photo_admission (admission_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function store(array $file, array $row): int
    {
        $img = Canteen::imageFromUpload($file, 'photo', 'PHOTO');
        $st = Db::pdo()->prepare('INSERT INTO sis_student_photos (student_number, admission_id, status, source, mime, size_bytes, bytes, uploaded_by, decided_by, decided_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->bindValue(1, $row['student_number'] ?? null); $st->bindValue(2, $row['admission_id'] ?? null, isset($row['admission_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $st->bindValue(3, $row['status']); $st->bindValue(4, $row['source']); $st->bindValue(5, $img['mime']); $st->bindValue(6, strlen($img['bytes']), PDO::PARAM_INT);
        $st->bindValue(7, $img['bytes'], PDO::PARAM_LOB); $st->bindValue(8, Auth::id()); $st->bindValue(9, $row['status'] === 'APPROVED' ? Auth::id() : null);
        $st->bindValue(10, $row['status'] === 'APPROVED' ? date('Y-m-d H:i:s') : null);
        $st->execute();
        return (int) Db::pdo()->lastInsertId();
    }

    /** Admission staff: the applicant's photo (replaces the earlier one). */
    public static function forAdmission(int $admissionId, array $file, bool $own = false): int
    {
        if ($own) { // an applicant adding a photo to their own application in the Student Portal
            if (!Db::val("SELECT 1 FROM central_record_owners WHERE workspace = 'SIS' AND entity_type = 'admission' AND entity_key = ? AND account_id = ?", [(string) $admissionId, (int) Auth::centralId()])) throw new Forbidden('This is not your application.');
            if (Db::val('SELECT status FROM sis_admissions WHERE id = ?', [$admissionId]) !== 'PENDING') throw new AppError('The photo can be changed only while the application is pending.');
        } else Auth::require('admission');
        $a = Db::one('SELECT id, student_number FROM sis_admissions WHERE id = ?', [$admissionId]) ?: throw new AppError('Admission not found.');
        return Db::tx(function () use ($a, $file) {
            Db::q("UPDATE sis_student_photos SET status = 'REPLACED' WHERE admission_id = ? AND status = 'APPROVED'", [$a['id']]);
            if ($a['student_number']) Db::q("UPDATE sis_student_photos SET status = 'REPLACED' WHERE student_number = ? AND status = 'APPROVED'", [$a['student_number']]);
            $id = self::store($file, ['admission_id' => (int) $a['id'], 'student_number' => $a['student_number'] ?: null, 'status' => 'APPROVED', 'source' => 'ADMISSION']);
            Audit::log('student_photo_added', 'admission', (int) $a['id'], ['photo' => $id]);
            return $id;
        });
    }

    /** When an applicant is admitted, their admission photo becomes the student's photo. */
    public static function linkAdmission(int $admissionId, string $studentNumber): void
    {
        Db::q("UPDATE sis_student_photos SET student_number = ? WHERE admission_id = ? AND student_number IS NULL", [$studentNumber, $admissionId]);
    }

    /** Registrar staff: replace a student's photo directly. */
    public static function staffSet(string $studentNumber, array $file): int
    {
        Auth::require('students.edit');
        if (!GS::studentByNumber($studentNumber)) throw new AppError('Student not found.');
        return Db::tx(function () use ($studentNumber, $file) {
            Db::q("UPDATE sis_student_photos SET status = 'REPLACED' WHERE student_number = ? AND status IN ('APPROVED','PENDING')", [$studentNumber]);
            $id = self::store($file, ['student_number' => $studentNumber, 'status' => 'APPROVED', 'source' => 'STAFF']);
            Audit::log('student_photo_set', 'student', 0, ['student_number' => $studentNumber, 'photo' => $id]);
            return $id;
        });
    }

    /** Student: send a new photo for approval (replaces a photo still waiting). */
    public static function submitOwn(array $file): int
    {
        $no = (string) Auth::studentNumber();
        if ($no === '') throw new Forbidden('Only students can send a photo for their record.');
        $id = Db::tx(function () use ($no, $file) {
            Db::q("UPDATE sis_student_photos SET status = 'REPLACED' WHERE student_number = ? AND status = 'PENDING'", [$no]);
            $id = self::store($file, ['student_number' => $no, 'status' => 'PENDING', 'source' => 'STUDENT']);
            Audit::log('student_photo_submitted', 'student', 0, ['student_number' => $no, 'photo' => $id]);
            return $id;
        });
        $name = (string) (GS::studentByNumber($no)['studentName'] ?? $no);
        Notify::toAccounts(Notify::accountsWith('sis.students.manage'), 'PHOTO_REVIEW', 'New student photo to approve', $name . ' (' . $no . ') sent a new photo for their record.', '../sis/index.php?r=photos', 'photo-review:' . $id);
        return $id;
    }

    /** Registrar: approve or decline a photo a student sent. */
    public static function decide(int $photoId, bool $approve, string $reason = ''): void
    {
        Auth::require('students.edit');
        $reason = trim($reason);
        if (!$approve && $reason === '') throw new AppError('Say why the photo is declined, so the student can send a better one.');
        $p = Db::tx(function () use ($photoId, $approve, $reason) {
            $p = Db::one("SELECT id, student_number FROM sis_student_photos WHERE id = ? AND status = 'PENDING' FOR UPDATE", [$photoId]) ?: throw new AppError('This photo is no longer waiting for approval.');
            if ($approve) Db::q("UPDATE sis_student_photos SET status = 'REPLACED' WHERE student_number = ? AND status = 'APPROVED'", [$p['student_number']]);
            Db::q('UPDATE sis_student_photos SET status = ?, decided_by = ?, decided_at = NOW(), reason = ? WHERE id = ?', [$approve ? 'APPROVED' : 'REJECTED', Auth::id(), $reason !== '' ? mb_substr($reason, 0, 255) : null, $photoId]);
            Audit::log($approve ? 'student_photo_approved' : 'student_photo_declined', 'student', 0, ['student_number' => $p['student_number'], 'photo' => $photoId, 'reason' => $reason]);
            return $p;
        });
        $s = GS::studentByNumber((string) $p['student_number']);
        if ($s) Notify::toAccount(Notify::accountOfStudent((string) $s['id']), 'PHOTO_DECIDED', $approve ? 'Your new photo was approved' : 'Your new photo was not approved',
            $approve ? 'Your photo is now on your student record.' : 'Reason: ' . $reason . '. You can send another photo from My profile.', '../portal/index.php?r=profile');
    }

    /** Approve or decline several photos at once (each is decided and the student told, as one by one). Returns how many were decided. */
    public static function decideMany(array $photoIds, bool $approve, string $reason = ''): int
    {
        Auth::require('students.edit');
        $ids = array_values(array_unique(array_filter(array_map('intval', $photoIds))));
        if (!$ids) throw new AppError('Tick the photos first, or use Select all.');
        if (!$approve && trim($reason) === '') throw new AppError('Say why the photos are declined, so the students can send better ones.');
        $n = 0;
        foreach ($ids as $id) {
            try { self::decide($id, $approve, $reason); $n++; } catch (AppError) { /* already decided by someone else */ }
        }
        return $n;
    }

    /** Photos waiting for approval (without their bytes). */
    public static function pending(): array
    {
        return Db::all("SELECT id, student_number, created_at, size_bytes FROM sis_student_photos WHERE status = 'PENDING' ORDER BY id");
    }

    /** The id of a student's current photo (or null). */
    public static function currentId(string $studentNumber): ?int
    {
        if ($studentNumber === '') return null;
        $id = Db::val("SELECT id FROM sis_student_photos WHERE student_number = ? AND status = 'APPROVED' ORDER BY id DESC LIMIT 1", [$studentNumber]);
        return $id ? (int) $id : null;
    }

    public static function pendingId(string $studentNumber): ?array
    {
        return Db::one("SELECT id, created_at FROM sis_student_photos WHERE student_number = ? AND status = 'PENDING' ORDER BY id DESC LIMIT 1", [$studentNumber]) ?: null;
    }

    public static function lastDeclined(string $studentNumber): ?array
    {
        return Db::one("SELECT id, reason, decided_at FROM sis_student_photos WHERE student_number = ? AND status = 'REJECTED' AND decided_at > COALESCE((SELECT MAX(created_at) FROM sis_student_photos p2 WHERE p2.student_number = ? AND p2.status IN ('PENDING','APPROVED')), '2000-01-01') ORDER BY id DESC LIMIT 1", [$studentNumber, $studentNumber]) ?: null;
    }

    public static function admissionPhotoId(int $admissionId): ?int
    {
        $id = Db::val("SELECT id FROM sis_student_photos WHERE admission_id = ? AND status = 'APPROVED' ORDER BY id DESC LIMIT 1", [$admissionId]);
        return $id ? (int) $id : null;
    }

    /** [mime, bytes] of a photo the signed-in person may see, or throws. */
    public static function open(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT id, student_number, admission_id, mime, bytes FROM sis_student_photos WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: throw new AppError('Photo not found.');
        $no = (string) $p['student_number'];
        $ok = Auth::can('students', 'admission', 'admission.view')
            || ($no !== '' && $no === (string) Auth::studentNumber())
            || ($no !== '' && class_exists('ParentAccess') && in_array($no, array_column(ParentAccess::children(), 'student_number'), true));
        if (!$ok) throw new Forbidden('You cannot open this photo.');
        return [(string) $p['mime'], is_resource($p['bytes']) ? (string) stream_get_contents($p['bytes']) : (string) $p['bytes']];
    }

    /** <img> of a photo through the photo route, or a placeholder with initials. */
    public static function html(?int $id, string $name, string $size = 'md'): string
    {
        if ($id) return '<img class="st-photo st-photo-' . h($size) . '" src="' . h(url('photo', ['id' => $id])) . '" alt="Photo of ' . h($name) . '">';
        $parts = preg_split('/[\s,]+/', trim($name)) ?: [];
        $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
        return '<span class="st-photo st-photo-' . h($size) . ' st-photo-none" aria-label="No photo">' . h($ini) . '</span>';
    }
}
