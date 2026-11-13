<?php
declare(strict_types=1);

/**
 * v61 Learning Management System. A class is a Grading System class assignment (teacher + subject + section + term,
 * with its official roster), so rosters and teachers come from the existing records. Each class has a stream
 * (announcements and materials), classwork (assignments, quizzes… with files, typed answers or links), submissions with
 * scores and feedback, attendance by meeting, and a weighted gradebook. The class-work grade can be copied into the
 * Grading System's grade sheet as a DRAFT; the teacher submits and the registrar finalizes there as before.
 * Class schedules and their conflict checks come from the SIS (sis_class_schedules).
 */
final class Lms
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const FILE_TYPES = [
        'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'zip' => 'application/zip', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
    ];
    public const ATTENDANCE = ['PRESENT' => 'Present', 'LATE' => 'Late', 'ABSENT' => 'Absent', 'EXCUSED' => 'Excused'];
    public const DEFAULT_WEIGHTS = ['Assignment' => 30, 'Quiz' => 30, 'Project' => 20, 'Exam' => 20];

    // ------------------------------------------------------------------ install

    public static function install(PDO $db): void
    {
        $t = fn(string $sql) => $db->exec($sql . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $t("CREATE TABLE IF NOT EXISTS lms_class_settings (
            class_id VARCHAR(191) NOT NULL PRIMARY KEY, weights VARCHAR(1000) NOT NULL DEFAULT '', count_missing TINYINT(1) NOT NULL DEFAULT 1,
            description VARCHAR(1000) NULL, updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $t("CREATE TABLE IF NOT EXISTS lms_posts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, class_id VARCHAR(191) NOT NULL, kind ENUM('ANNOUNCEMENT','MATERIAL') NOT NULL,
            title VARCHAR(200) NULL, body TEXT NULL, link_url VARCHAR(500) NULL, topic VARCHAR(80) NULL, pinned TINYINT(1) NOT NULL DEFAULT 0,
            author_id INT UNSIGNED NULL, author_name VARCHAR(150) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
            KEY k_lms_post_class (class_id, deleted_at, created_at)
        )");
        $t("CREATE TABLE IF NOT EXISTS lms_work (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, class_id VARCHAR(191) NOT NULL, kind ENUM('ASSIGNMENT','QUIZ') NOT NULL DEFAULT 'ASSIGNMENT',
            title VARCHAR(200) NOT NULL, instructions TEXT NULL, time_limit_min SMALLINT UNSIGNED NULL, show_results TINYINT(1) NOT NULL DEFAULT 1,
            category VARCHAR(40) NOT NULL DEFAULT 'Assignment', topic VARCHAR(80) NULL, points DECIMAL(7,2) NOT NULL DEFAULT 100, due_at DATETIME NULL,
            allow_late TINYINT(1) NOT NULL DEFAULT 1, allow_resubmit TINYINT(1) NOT NULL DEFAULT 1, status ENUM('DRAFT','PUBLISHED') NOT NULL DEFAULT 'PUBLISHED',
            created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL, KEY k_lms_work_class (class_id, deleted_at), CONSTRAINT ck_lms_points CHECK (points > 0)
        )");
        $t("CREATE TABLE IF NOT EXISTS lms_submissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, work_id INT UNSIGNED NOT NULL, student_doc_id VARCHAR(191) NOT NULL,
            student_no VARCHAR(40) NOT NULL, student_name VARCHAR(150) NOT NULL, text_answer TEXT NULL, link_url VARCHAR(500) NULL,
            status ENUM('DRAFT','SUBMITTED','RETURNED') NOT NULL DEFAULT 'DRAFT', submitted_at DATETIME NULL, is_late TINYINT(1) NOT NULL DEFAULT 0, attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            answers MEDIUMTEXT NULL, question_scores TEXT NULL, started_at DATETIME NULL,
            score DECIMAL(7,2) NULL, feedback TEXT NULL, graded_by INT UNSIGNED NULL, graded_at DATETIME NULL, returned_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_lms_sub (work_id, student_doc_id), CONSTRAINT fk_lms_sub_work FOREIGN KEY (work_id) REFERENCES lms_work (id)
        )");
        $t("CREATE TABLE IF NOT EXISTS lms_questions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, work_id INT UNSIGNED NOT NULL, sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            qtype ENUM('MC','CHECK','TF','SHORT','ESSAY') NOT NULL, prompt TEXT NOT NULL, options TEXT NULL, answer TEXT NULL, points DECIMAL(6,2) NOT NULL DEFAULT 1,
            KEY k_lms_q_work (work_id, sort_order), CONSTRAINT fk_lms_q_work FOREIGN KEY (work_id) REFERENCES lms_work (id), CONSTRAINT ck_lms_q_points CHECK (points > 0)
        )");
        $t("CREATE TABLE IF NOT EXISTS lms_files (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, owner_type ENUM('POST','WORK','SUBMISSION') NOT NULL, owner_id INT UNSIGNED NOT NULL,
            name VARCHAR(200) NOT NULL, mime VARCHAR(120) NOT NULL, size_bytes INT UNSIGNED NOT NULL, sha1 CHAR(40) NOT NULL, bytes MEDIUMBLOB NOT NULL,
            uploaded_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_lms_file_owner (owner_type, owner_id)
        )");
        $t("CREATE TABLE IF NOT EXISTS lms_attendance_sessions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, class_id VARCHAR(191) NOT NULL, meeting_date DATE NOT NULL, label VARCHAR(80) NOT NULL DEFAULT '',
            schedule_id INT UNSIGNED NULL, topic VARCHAR(200) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY u_lms_session (class_id, meeting_date, label)
        )");
        $t("CREATE TABLE IF NOT EXISTS lms_attendance (
            session_id INT UNSIGNED NOT NULL, student_doc_id VARCHAR(191) NOT NULL, status ENUM('PRESENT','LATE','ABSENT','EXCUSED') NOT NULL, note VARCHAR(200) NULL,
            marked_by INT UNSIGNED NULL, marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (session_id, student_doc_id), CONSTRAINT fk_lms_att_session FOREIGN KEY (session_id) REFERENCES lms_attendance_sessions (id)
        )");
        // v61.1: private messages between a class's teacher and one student (one conversation per class and student)
        $t("CREATE TABLE IF NOT EXISTS lms_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, class_id VARCHAR(191) NOT NULL, student_doc_id VARCHAR(191) NOT NULL,
            from_teacher TINYINT(1) NOT NULL, sender_id INT UNSIGNED NULL, sender_name VARCHAR(150) NOT NULL, body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL,
            KEY k_lms_msg_thread (class_id, student_doc_id, id), KEY k_lms_msg_student (student_doc_id, read_at)
        )");
    }

    // ------------------------------------------------------------------ who is signed in

    /** The signed-in person in LMS terms: teacher doc id, student doc id, and whether they coordinate every class. */
    public static function me(): array
    {
        static $me = null;
        if ($me !== null && PHP_SAPI !== 'cli') return $me;
        $c = Auth::central(); $id = (int) Auth::centralId(); $a = $id ? $c->account($id) : null;
        $teacher = $a && !empty($a['teacher_doc_id']) && ($a['person_verified'] ?? 1) ? (string) $a['teacher_doc_id'] : null;
        $num = Auth::studentNumber();
        $student = $num ? GS::studentByNumber((string) $num) : null;
        return $me = ['id' => $id, 'name' => (string) (Auth::user()['full_name'] ?? ''), 'teacher' => $teacher,
            'student' => $student ? (string) $student['id'] : null, 'student_no' => $num ? (string) $num : null, 'student_name' => $student ? (string) ($student['studentName'] ?? '') : '',
            'admin' => $id && ($c->group($id) === 'POWER_USER' || $c->can($id, 'lms.manage.all'))];
    }

    // ------------------------------------------------------------------ classes (Grading System class assignments)

    /** Classes the signed-in person teaches or takes (coordinators: every class of the school year). */
    public static function myClasses(?string $schoolYear = null): array
    {
        static $cache = [];
        $me = self::me(); $sy = $schoolYear ?? (GS::schoolYears()[0] ?? '');
        $key = ($me['id'] ?? 0) . '|' . $sy;
        if (isset($cache[$key]) && PHP_SAPI !== 'cli') return $cache[$key]; // v62: once per page
        // v62: only this school year's classes are read (indexed column), and teachers read only their own
        $where = "col = 'gradingAssignments'" . ($sy !== '' ? ' AND f_schoolYear = ?' : '');
        $args = $sy !== '' ? [$sy] : [];
        if (!$me['admin'] && $me['teacher'] && !$me['student']) { $where .= ' AND f_teacherDocId = ?'; $args[] = $me['teacher']; }
        $all = array_map(fn($r) => ['id' => $r['id']] + (json_decode((string) $r['data'], true) ?: []), Db::all("SELECT id, data FROM docs WHERE $where", $args));
        $out = [];
        foreach ($all as $a) {
            $role = null;
            if ($me['teacher'] && ($a['teacherDocId'] ?? '') === $me['teacher']) $role = 'TEACHER';
            elseif ($me['student'] && in_array($me['student'], (array) ($a['studentIds'] ?? []), true)) $role = 'STUDENT';
            elseif ($me['admin']) $role = 'ADMIN';
            if ($role) $out[] = self::shape($a) + ['role' => $role];
        }
        usort($out, fn($x, $y) => [$x['term'], $x['subjectCode']] <=> [$y['term'], $y['subjectCode']]);
        return $cache[$key] = $out;
    }

    private static function shape(array $a): array
    {
        return ['id' => (string) $a['id'], 'subjectId' => (string) ($a['subjectId'] ?? ''), 'subjectCode' => (string) ($a['subjectCode'] ?? ''), 'subjectName' => (string) ($a['subjectName'] ?? ''),
            'sectionId' => (string) ($a['sectionId'] ?? ''), 'sectionName' => (string) ($a['sectionName'] ?? ''), 'teacherDocId' => (string) ($a['teacherDocId'] ?? ''),
            'teacherName' => (string) ($a['teacherName'] ?? ''), 'schoolYear' => (string) ($a['schoolYear'] ?? ''), 'term' => (string) ($a['term'] ?? ''),
            'yearLevel' => (string) ($a['yearLevel'] ?? ''), 'units' => $a['units'] ?? null, 'studentIds' => array_values(array_map('strval', (array) ($a['studentIds'] ?? []))), 'raw' => $a];
    }

    /** One class with the signed-in person's role in it (TEACHER, STUDENT or ADMIN); refuses anyone else. */
    public static function classFor(string $id): array
    {
        $a = GS::get('gradingAssignments', $id) ?: throw new AppError('Class not found.');
        $me = self::me(); $c = self::shape($a);
        $role = $me['teacher'] && $c['teacherDocId'] === $me['teacher'] ? 'TEACHER' : ($me['student'] && in_array($me['student'], $c['studentIds'], true) ? 'STUDENT' : ($me['admin'] ? 'ADMIN' : null));
        if (!$role) throw new Forbidden('You are not in this class.');
        return $c + ['role' => $role];
    }

    /** Teacher or coordinator of the class. */
    private static function requireTeach(array $c): void
    {
        if (!in_array($c['role'], ['TEACHER', 'ADMIN'], true)) throw new Forbidden('Only the teacher of this class can do this.');
    }

    /** The class roster from the Grading System: [doc id => ['id','no','name','section']]. */
    public static function roster(array $c): array
    {
        $out = [];
        $docs = [];
        foreach (array_chunk($c['studentIds'], 500) as $chunk) // v62: one query instead of one per student
            foreach (Db::all("SELECT id, data FROM docs WHERE col = 'students' AND id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) $docs[$r['id']] = json_decode((string) $r['data'], true) ?: [];
        foreach ($c['studentIds'] as $sid) {
            $s = $docs[$sid] ?? [];
            $out[$sid] = ['id' => $sid, 'no' => (string) ($s['studentId'] ?? ''), 'name' => (string) ($s['studentName'] ?? 'Unknown student'), 'email' => (string) ($s['email'] ?? '')];
        }
        uasort($out, fn($x, $y) => strcasecmp($x['name'], $y['name']));
        return $out;
    }

    public static function settings(string $classId): array
    {
        $r = Db::one('SELECT * FROM lms_class_settings WHERE class_id = ?', [$classId]);
        $w = $r && $r['weights'] !== '' ? (json_decode($r['weights'], true) ?: []) : self::DEFAULT_WEIGHTS;
        return ['weights' => $w, 'count_missing' => $r ? (bool) $r['count_missing'] : true, 'description' => (string) ($r['description'] ?? '')];
    }

    public static function saveSettings(array $c, array $in): void
    {
        self::requireTeach($c);
        $w = [];
        foreach ((array) ($in['cat'] ?? []) as $i => $name) {
            $name = trim((string) $name); $pct = trim((string) ($in['weight'][$i] ?? ''));
            if ($name === '' && $pct === '') continue;
            if ($name === '' || mb_strlen($name) > 40) throw new AppError('Each grading category needs a name (up to 40 letters).');
            if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $pct) || (float) $pct <= 0) throw new AppError('Weights are percentages, e.g. 30.');
            $w[$name] = (float) $pct;
        }
        if (!$w) throw new AppError('Keep at least one grading category.');
        if (abs(array_sum($w) - 100) > 0.01) throw new AppError('The weights add up to ' . rtrim(rtrim(number_format(array_sum($w), 2), '0'), '.') . '%. Make them add up to 100%.');
        Db::q('INSERT INTO lms_class_settings (class_id, weights, count_missing, description, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE weights = VALUES(weights), count_missing = VALUES(count_missing), description = VALUES(description), updated_by = VALUES(updated_by)',
            [$c['id'], json_encode($w, JSON_UNESCAPED_UNICODE), empty($in['count_missing']) ? 0 : 1, mb_substr(trim((string) ($in['description'] ?? '')), 0, 1000) ?: null, Auth::id()]);
        Audit::log('lms_class_settings_saved', 'lms_class', $c['id'], ['weights' => $w]);
    }

    // ------------------------------------------------------------------ files (bytes in lms_files)

    /** Check and read an upload; returns [name, mime, bytes]. */
    public static function readUpload(array $f): array
    {
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new AppError('A file is larger than the server allows. Ask the administrator to raise the upload limit, or upload a smaller file.');
        if ($err !== UPLOAD_ERR_OK || (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $f['tmp_name']))) throw new AppError('A file could not be uploaded. Try again.');
        $size = (int) ($f['size'] ?? 0);
        if ($size <= 0) throw new AppError('A file is empty.');
        if ($size > self::MAX_FILE_BYTES) throw new AppError('Files can be at most 10 MB each.');
        $name = trim(preg_replace('/[\x00-\x1f\/\\\\]+/', '_', basename((string) ($f['name'] ?? 'file'))));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::FILE_TYPES[$ext])) throw new AppError('"' . $name . '" is not an allowed file type. Use PDF, Word, Excel, PowerPoint, text, images, ZIP, MP3 or MP4.');
        $bytes = (string) file_get_contents((string) $f['tmp_name']);
        $sniff = function_exists('finfo_open') ? (string) finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $bytes) : '';
        if (str_starts_with(self::FILE_TYPES[$ext], 'image/') && !@getimagesizefromstring($bytes)) throw new AppError('"' . $name . '" is not a valid image.');
        if ($ext === 'pdf' && !str_starts_with($bytes, '%PDF')) throw new AppError('"' . $name . '" is not a valid PDF.');
        if (in_array($sniff, ['text/html', 'application/x-php', 'text/x-php', 'application/x-msdownload', 'application/x-dosexec'], true)) throw new AppError('"' . $name . '" is not allowed.');
        return [mb_substr($name, 0, 200), self::FILE_TYPES[$ext], $bytes];
    }

    /** Uploaded files from a multiple file input ($_FILES['x']) as a list. */
    public static function uploads(?array $f): array
    {
        if (!$f || !isset($f['name'])) return [];
        if (!is_array($f['name'])) return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$f];
        $out = [];
        foreach ($f['name'] as $i => $n) if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $out[] = ['name' => $n, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i], 'size' => $f['size'][$i] ?? 0];
        if (count($out) > 10) throw new AppError('Attach at most 10 files at a time.');
        return $out;
    }

    private static function storeFiles(string $type, int $ownerId, array $files): void
    {
        if ($files) { // v62: each class has a storage limit
            $classId = (string) Db::val($type === 'POST' ? 'SELECT class_id FROM lms_posts WHERE id = ?' : ($type === 'WORK' ? 'SELECT class_id FROM lms_work WHERE id = ?'
                : 'SELECT w.class_id FROM lms_submissions s JOIN lms_work w ON w.id = s.work_id WHERE s.id = ?'), [$ownerId]);
            $add = array_sum(array_map(fn($f) => strlen($f[2]), $files));
            $used = self::classUsage($classId); $limit = self::quotaBytes();
            if ($limit > 0 && $used + $add > $limit)
                throw new AppError('This class has used ' . self::mb($used) . ' of its ' . self::mb($limit) . ' file space, so these files (' . self::mb($add) . ') don\'t fit. '
                    . ($type === 'SUBMISSION' ? 'Ask your teacher.' : 'Remove old files or ask the LMS coordinator to free up space.'));
        }
        foreach ($files as [$name, $mime, $bytes]) {
            $st = Db::pdo()->prepare('INSERT INTO lms_files (owner_type, owner_id, name, mime, size_bytes, sha1, bytes, uploaded_by) VALUES (?,?,?,?,?,?,?,?)');
            $st->bindValue(1, $type); $st->bindValue(2, $ownerId, PDO::PARAM_INT); $st->bindValue(3, $name); $st->bindValue(4, $mime);
            $st->bindValue(5, strlen($bytes), PDO::PARAM_INT); $st->bindValue(6, sha1($bytes)); $st->bindValue(7, $bytes, PDO::PARAM_LOB); $st->bindValue(8, Auth::id());
            $st->execute();
        }
    }

    public static function files(string $type, int $ownerId): array
    {
        return Db::all('SELECT id, name, mime, size_bytes, created_at FROM lms_files WHERE owner_type = ? AND owner_id = ? ORDER BY id', [$type, $ownerId]);
    }

    /** A file the signed-in person may open: class members for posts and classwork; the student and the teacher for submissions. */
    public static function fileFor(int $id): array
    {
        $f = Db::one('SELECT id, owner_type, owner_id, name, mime, size_bytes FROM lms_files WHERE id = ?', [$id]) ?: throw new AppError('File not found.');
        $classId = match ($f['owner_type']) {
            'POST' => (string) Db::val('SELECT class_id FROM lms_posts WHERE id = ? AND deleted_at IS NULL', [$f['owner_id']]),
            'WORK' => (string) Db::val('SELECT class_id FROM lms_work WHERE id = ? AND deleted_at IS NULL', [$f['owner_id']]),
            'SUBMISSION' => (string) Db::val('SELECT w.class_id FROM lms_submissions s JOIN lms_work w ON w.id = s.work_id WHERE s.id = ?', [$f['owner_id']]),
        };
        if ($classId === '') throw new AppError('File not found.');
        $c = self::classFor($classId);
        if ($f['owner_type'] === 'SUBMISSION' && $c['role'] === 'STUDENT' && Db::val('SELECT student_doc_id FROM lms_submissions WHERE id = ?', [$f['owner_id']]) !== self::me()['student']) throw new Forbidden('This file belongs to another student.');
        if ($f['owner_type'] === 'WORK' && $c['role'] === 'STUDENT' && Db::val('SELECT status FROM lms_work WHERE id = ?', [$f['owner_id']]) !== 'PUBLISHED') throw new AppError('File not found.');
        $st = Db::pdo()->prepare('SELECT bytes FROM lms_files WHERE id = ?'); $st->execute([$id]);
        $b = $st->fetchColumn();
        return $f + ['bytes' => is_resource($b) ? (string) stream_get_contents($b) : (string) $b];
    }

    // ------------------------------------------------------------------ stream (announcements and materials)

    public static function savePost(array $c, string $kind, array $in, array $uploads): int
    {
        if (!in_array($kind, ['ANNOUNCEMENT', 'MATERIAL'], true)) throw new AppError('Choose an announcement or material.');
        self::requireTeach($c);
        $body = trim((string) ($in['body'] ?? '')); $title = trim((string) ($in['title'] ?? ''));
        $link = self::link((string) ($in['link_url'] ?? ''));
        if ($kind === 'MATERIAL' && $title === '') throw new AppError('Give the material a title.');
        if ($body === '' && $title === '' && !$link && !$uploads) throw new AppError('Write something, add a link or attach a file.');
        $files = array_map([self::class, 'readUpload'], $uploads);
        return Db::tx(function () use ($c, $kind, $body, $title, $link, $files, $in) {
            $id = Db::insert('lms_posts', ['class_id' => $c['id'], 'kind' => $kind, 'title' => $title !== '' ? mb_substr($title, 0, 200) : null, 'body' => $body !== '' ? mb_substr($body, 0, 20000) : null,
                'link_url' => $link, 'topic' => ($tp = trim((string) ($in['topic'] ?? ''))) !== '' ? mb_substr($tp, 0, 80) : null, 'pinned' => empty($in['pinned']) ? 0 : 1,
                'author_id' => Auth::id(), 'author_name' => (string) (Auth::user()['full_name'] ?? '')]);
            self::storeFiles('POST', $id, $files);
            Audit::log('lms_' . strtolower($kind) . '_posted', 'lms_class', $c['id'], ['post' => $id, 'title' => $title, 'files' => count($files)]);
            Notify::toStudents($c['studentIds'], 'LMS_POST', ($kind === 'MATERIAL' ? 'New material' : 'New announcement') . ' in ' . $c['subjectCode'],
                ($title !== '' ? $title . ': ' : '') . mb_strimwidth($body !== '' ? $body : 'Open the class to see it.', 0, 240, '…'), self::classLink($c['id'], $kind === 'MATERIAL' ? 'classwork' : 'stream'), 'lms-post:' . $id);
            return $id;
        });
    }

    public static function deletePost(array $c, int $postId): void
    {
        self::requireTeach($c);
        if (!Db::q('UPDATE lms_posts SET deleted_at = NOW() WHERE id = ? AND class_id = ? AND deleted_at IS NULL', [$postId, $c['id']])->rowCount()) throw new AppError('Post not found.');
        Audit::log('lms_post_deleted', 'lms_class', $c['id'], ['post' => $postId]);
    }

    public static function posts(string $classId, ?string $kind = null): array
    {
        $rows = Db::all('SELECT * FROM lms_posts WHERE class_id = ? AND deleted_at IS NULL' . ($kind ? ' AND kind = ?' : '') . ' ORDER BY pinned DESC, created_at DESC LIMIT 200', $kind ? [$classId, $kind] : [$classId]);
        foreach ($rows as &$r) $r['files'] = self::files('POST', (int) $r['id']);
        return $rows;
    }

    private static function link(string $raw): ?string
    {
        $u = trim($raw);
        if ($u === '') return null;
        if (!preg_match('#^https?://[^\s<>"]+$#i', $u) || mb_strlen($u) > 500) throw new AppError('Links must start with http:// or https://.');
        return $u;
    }

    // ------------------------------------------------------------------ classwork

    public static function saveWork(array $c, ?int $id, array $in, array $uploads): int
    {
        self::requireTeach($c);
        $title = In::str($in, 'title', 200, true, 'Title');
        $points = trim((string) ($in['points'] ?? '100'));
        if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $points) || (float) $points <= 0) throw new AppError('Points must be a number above 0.');
        $cat = trim((string) ($in['category'] ?? 'Assignment'));
        if (!array_key_exists($cat, self::settings($c['id'])['weights'])) throw new AppError('Choose a grading category of this class.');
        $due = trim((string) ($in['due_at'] ?? ''));
        if ($due !== '') { $ts = strtotime($due); if ($ts === false) throw new AppError('Check the due date and time.'); $due = date('Y-m-d H:i:00', $ts); } else $due = null;
        $kind = ($in['kind'] ?? '') === 'QUIZ' ? 'QUIZ' : 'ASSIGNMENT';
        $limit = trim((string) ($in['time_limit_min'] ?? ''));
        if ($limit !== '' && (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 600)) throw new AppError('The time limit is in minutes (1–600), or leave it empty.');
        $row = ['kind' => $kind, 'time_limit_min' => $limit === '' || $kind !== 'QUIZ' ? null : (int) $limit, 'show_results' => empty($in['show_results']) ? 0 : 1,
            'title' => $title, 'instructions' => ($x = trim((string) ($in['instructions'] ?? ''))) !== '' ? mb_substr($x, 0, 20000) : null, 'category' => $cat,
            'topic' => ($tp = trim((string) ($in['topic'] ?? ''))) !== '' ? mb_substr($tp, 0, 80) : null, 'points' => $points, 'due_at' => $due,
            'allow_late' => empty($in['allow_late']) ? 0 : 1, 'allow_resubmit' => empty($in['allow_resubmit']) ? 0 : 1, 'status' => ($in['status'] ?? '') === 'DRAFT' ? 'DRAFT' : 'PUBLISHED'];
        $files = array_map([self::class, 'readUpload'], $uploads);
        $wasPublished = $id ? Db::val('SELECT status FROM lms_work WHERE id = ? AND class_id = ?', [$id, $c['id']]) === 'PUBLISHED' : false;
        $newId = Db::tx(function () use ($c, $id, $row, $files) {
            if ($id) {
                if (!Db::val('SELECT 1 FROM lms_work WHERE id = ? AND class_id = ? AND deleted_at IS NULL', [$id, $c['id']])) throw new AppError('Classwork not found.');
                Db::q('UPDATE lms_work SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
            } else $id = Db::insert('lms_work', $row + ['class_id' => $c['id'], 'created_by' => Auth::id()]);
            self::storeFiles('WORK', (int) $id, $files);
            Audit::log('lms_work_saved', 'lms_class', $c['id'], ['work' => $id, 'title' => $row['title'], 'points' => $row['points'], 'status' => $row['status']]);
            return (int) $id;
        });
        if ($row['status'] === 'PUBLISHED' && !$wasPublished)
            Notify::toStudents($c['studentIds'], 'LMS_WORK', 'New ' . ($row['kind'] === 'QUIZ' ? 'quiz' : strtolower($row['category'])) . ' in ' . $c['subjectCode'],
                $row['title'] . ($row['due_at'] ? ' · due ' . date('D, M j, g:i A', strtotime($row['due_at'])) : '') . ' · ' . self::pts((float) $row['points']) . ((float) $row['points'] == 1.0 ? ' point' : ' points'), self::workLink($c['id'], $newId), 'lms-work:' . $newId);
        return $newId;
    }

    /** 18, 18.5 (never "1" for 10). */
    private static function pts(float $n): string { return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); }

    private static function classLink(string $classId, string $tab = 'stream'): string { return '../lms/index.php?' . http_build_query(['r' => 'class', 'id' => $classId, 'tab' => $tab]); }
    private static function workLink(string $classId, int $workId): string { return '../lms/index.php?' . http_build_query(['r' => 'work', 'id' => $classId, 'w' => $workId]); }

    /** Tell a student their work came back, with the score. */
    private static function notifyReturned(array $w, string $studentDocId, float $score): void
    {
        $c = self::shape(GS::get('gradingAssignments', (string) $w['class_id']) ?: ['id' => $w['class_id']]);
        Notify::toAccount(Notify::accountOfStudent($studentDocId), 'LMS_RETURNED', 'Your work was returned: ' . $w['title'],
            'Score: ' . self::pts($score) . ' / ' . self::pts((float) $w['points']) . ($c['subjectCode'] !== '' ? ' · ' . $c['subjectCode'] : '') . '. Open it to see the feedback.',
            self::workLink((string) $w['class_id'], (int) $w['id']));
    }

    public static function deleteWork(array $c, int $workId): void
    {
        self::requireTeach($c);
        if (Db::val("SELECT COUNT(*) FROM lms_submissions WHERE work_id = ? AND status <> 'DRAFT'", [$workId])) throw new AppError('Students already handed this in. Move it to Draft instead of deleting it.');
        if (!Db::q('UPDATE lms_work SET deleted_at = NOW() WHERE id = ? AND class_id = ? AND deleted_at IS NULL', [$workId, $c['id']])->rowCount()) throw new AppError('Classwork not found.');
        Audit::log('lms_work_deleted', 'lms_class', $c['id'], ['work' => $workId]);
    }

    public static function work(array $c, int $workId): array
    {
        $w = Db::one('SELECT * FROM lms_work WHERE id = ? AND class_id = ? AND deleted_at IS NULL', [$workId, $c['id']]) ?: throw new AppError('Classwork not found.');
        if ($c['role'] === 'STUDENT' && $w['status'] !== 'PUBLISHED') throw new AppError('Classwork not found.');
        $w['files'] = self::files('WORK', $workId);
        return $w;
    }

    /** Classwork of a class; for a student, with their own submission. */
    public static function classwork(array $c): array
    {
        $student = $c['role'] === 'STUDENT';
        $rows = Db::all('SELECT w.*, (SELECT COUNT(*) FROM lms_submissions s WHERE s.work_id = w.id AND s.status IN (\'SUBMITTED\',\'RETURNED\')) AS turned_in,
                (SELECT COUNT(*) FROM lms_submissions s WHERE s.work_id = w.id AND s.status = \'SUBMITTED\') AS to_grade
            FROM lms_work w WHERE w.class_id = ? AND w.deleted_at IS NULL' . ($student ? " AND w.status = 'PUBLISHED'" : '') . ' ORDER BY w.due_at IS NULL, w.due_at, w.id', [$c['id']]);
        if ($student) foreach ($rows as &$r) $r['mine'] = Db::one('SELECT * FROM lms_submissions WHERE work_id = ? AND student_doc_id = ?', [$r['id'], self::me()['student']]);
        return $rows;
    }

    /** Assigned / Turned in / Late / Missing / Returned for a student's work. */
    public static function state(array $w, ?array $sub): array
    {
        $due = $w['due_at'] ? strtotime((string) $w['due_at']) : null;
        if ($sub && $sub['status'] === 'RETURNED') return ['Returned', 'returned'];
        if ($sub && $sub['status'] === 'SUBMITTED') return [(int) $sub['is_late'] ? 'Turned in late' : 'Turned in', (int) $sub['is_late'] ? 'late' : 'done'];
        if ($due && $due < time()) return ['Missing', 'missing'];
        return ['Assigned', 'assigned'];
    }

    // ------------------------------------------------------------------ quizzes and exams

    public const QTYPES = ['MC' => 'Multiple choice', 'CHECK' => 'Checkboxes', 'TF' => 'True or false', 'SHORT' => 'Short answer', 'ESSAY' => 'Essay'];

    public static function questions(int $workId): array
    {
        $rows = Db::all('SELECT * FROM lms_questions WHERE work_id = ? ORDER BY sort_order, id', [$workId]);
        foreach ($rows as &$q) { $q['options'] = json_decode((string) $q['options'], true) ?: []; $q['answer'] = json_decode((string) $q['answer'], true); }
        return $rows;
    }

    /**
     * Replace a quiz's questions. $list: [['type','prompt','options'=>[...],'answer'=>…,'points']]. MC/TF answer = option index (TF: 0 true, 1 false);
     * CHECK answer = list of indexes; SHORT answer = list of accepted texts; ESSAY = no answer (teacher scores). The quiz's points become the total.
     */
    public static function saveQuestions(array $c, int $workId, array $list): void
    {
        self::requireTeach($c);
        $w = self::work($c, $workId);
        if ($w['kind'] !== 'QUIZ') throw new AppError('Questions belong to quizzes and exams.');
        if (Db::val("SELECT 1 FROM lms_submissions WHERE work_id = ? AND (status <> 'DRAFT' OR started_at IS NOT NULL)", [$workId])) throw new AppError('Students have started this quiz, so its questions can\'t change.');
        $clean = [];
        foreach ($list as $i => $q) {
            $type = (string) ($q['type'] ?? ''); $prompt = trim((string) ($q['prompt'] ?? ''));
            if ($prompt === '' && $type === '') continue;
            $n = count($clean) + 1;
            if (!isset(self::QTYPES[$type])) throw new AppError("Question $n: choose its type.");
            if ($prompt === '') throw new AppError("Question $n: write the question.");
            $pts = trim((string) ($q['points'] ?? '1'));
            if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $pts) || (float) $pts <= 0) throw new AppError("Question $n: points must be above 0.");
            $opts = []; $ans = null;
            if (in_array($type, ['MC', 'CHECK'], true)) {
                $opts = array_values(array_filter(array_map(fn($o) => mb_substr(trim((string) $o), 0, 500), (array) ($q['options'] ?? [])), fn($o) => $o !== ''));
                if (count($opts) < 2) throw new AppError("Question $n: give at least two choices.");
                $marked = array_values(array_unique(array_map('intval', (array) ($q['answer'] ?? []))));
                $marked = array_values(array_filter($marked, fn($k) => $k >= 0 && $k < count($opts)));
                if (!$marked) throw new AppError("Question $n: mark the correct answer.");
                if ($type === 'MC' && count($marked) !== 1) throw new AppError("Question $n: a multiple-choice question has one correct answer (use Checkboxes for more).");
                $ans = $type === 'MC' ? $marked[0] : $marked;
            } elseif ($type === 'TF') {
                $opts = ['True', 'False']; $a = isset($q['tf']) ? [(string) $q['tf']] : (array) ($q['answer'] ?? []);
                if (!isset($a[0]) || !in_array((string) $a[0], ['0', '1'], true)) throw new AppError("Question $n: choose True or False as the answer.");
                $ans = (int) $a[0];
            } elseif ($type === 'SHORT') {
                $acc = $q['accepted'] ?? [];
                if (is_string($acc)) $acc = preg_split('/\r?\n/', $acc) ?: [];
                $ans = array_values(array_filter(array_map(fn($o) => mb_substr(trim((string) $o), 0, 200), (array) $acc), fn($o) => $o !== ''));
                if (!$ans) throw new AppError("Question $n: give at least one accepted answer (it is checked without capitals or extra spaces).");
            }
            $clean[] = ['qtype' => $type, 'prompt' => mb_substr($prompt, 0, 5000), 'options' => json_encode($opts, JSON_UNESCAPED_UNICODE), 'answer' => json_encode($ans, JSON_UNESCAPED_UNICODE), 'points' => $pts];
        }
        if (!$clean) throw new AppError('Add at least one question.');
        if (count($clean) > 200) throw new AppError('A quiz can have at most 200 questions.');
        Db::tx(function () use ($w, $clean, $c) {
            Db::q('DELETE FROM lms_questions WHERE work_id = ?', [$w['id']]);
            foreach ($clean as $i => $q) Db::insert('lms_questions', $q + ['work_id' => (int) $w['id'], 'sort_order' => $i + 1]);
            $total = array_sum(array_map(fn($q) => (float) $q['points'], $clean));
            Db::q('UPDATE lms_work SET points = ? WHERE id = ?', [number_format($total, 2, '.', ''), $w['id']]);
            Audit::log('lms_quiz_questions_saved', 'lms_work', (int) $w['id'], ['questions' => count($clean), 'points' => $total]);
        });
    }

    /** A student opens a quiz: the clock starts (once). Returns the submission row. */
    public static function startQuiz(array $c, int $workId): array
    {
        if ($c['role'] !== 'STUDENT') throw new Forbidden('Only students take quizzes.');
        $w = self::work($c, $workId); $me = self::me();
        if ($w['kind'] !== 'QUIZ') throw new AppError('This is not a quiz.');
        if (!self::questions($workId)) throw new AppError('This quiz has no questions yet.');
        return Db::tx(function () use ($w, $me) {
            $s = Db::one('SELECT * FROM lms_submissions WHERE work_id = ? AND student_doc_id = ? FOR UPDATE', [$w['id'], $me['student']]);
            if ($s && $s['status'] !== 'DRAFT') throw new AppError('You already turned in this quiz.');
            if ($w['due_at'] && strtotime((string) $w['due_at']) < time() && !(int) $w['allow_late']) throw new AppError('This quiz is closed (the due date has passed).');
            if (!$s) { Db::insert('lms_submissions', ['work_id' => (int) $w['id'], 'student_doc_id' => $me['student'], 'student_no' => (string) $me['student_no'], 'student_name' => $me['student_name'], 'started_at' => date('Y-m-d H:i:s')]); }
            elseif (!$s['started_at']) Db::q('UPDATE lms_submissions SET started_at = NOW() WHERE id = ?', [$s['id']]);
            return Db::one('SELECT * FROM lms_submissions WHERE work_id = ? AND student_doc_id = ?', [$w['id'], $me['student']]);
        });
    }

    /** Seconds left of a timed quiz for a started submission (null = no limit). */
    public static function secondsLeft(array $w, ?array $s): ?int
    {
        if (!$w['time_limit_min'] || !$s || !$s['started_at']) return null;
        return max(0, strtotime((string) $s['started_at']) + (int) $w['time_limit_min'] * 60 - time());
    }

    private static function norm(string $t): string { return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $t))); }

    /** Score one answer; null = needs the teacher (essay). */
    public static function autoScore(array $q, $given): ?float
    {
        $pts = (float) $q['points'];
        switch ($q['qtype']) {
            case 'MC': case 'TF': return $given !== null && $given !== '' && (int) $given === (int) $q['answer'] ? $pts : 0.0;
            case 'CHECK':
                $g = array_values(array_unique(array_map('intval', (array) $given))); sort($g); $a = array_map('intval', (array) $q['answer']); sort($a);
                return $g === $a ? $pts : 0.0;
            case 'SHORT':
                foreach ((array) $q['answer'] as $ok) if (self::norm((string) $given) === self::norm((string) $ok)) return $pts;
                return 0.0;
            default: return null;
        }
    }

    /**
     * Turn in a quiz. Objective questions are scored at once; if there are no essays and results are shown, the score is
     * released right away (Returned). With essays, the quiz waits for the teacher. Answers after the time limit (plus a
     * one-minute grace) are refused.
     */
    public static function submitQuiz(array $c, int $workId, array $answers): array
    {
        if ($c['role'] !== 'STUDENT') throw new Forbidden('Only students take quizzes.');
        $w = self::work($c, $workId); $me = self::me(); $qs = self::questions($workId);
        return Db::tx(function () use ($w, $me, $qs, $answers) {
            $s = Db::one('SELECT * FROM lms_submissions WHERE work_id = ? AND student_doc_id = ? FOR UPDATE', [$w['id'], $me['student']]);
            if (!$s || !$s['started_at']) throw new AppError('Open the quiz first.');
            if ($s['status'] !== 'DRAFT') throw new AppError('You already turned in this quiz.');
            // After the time limit the answers sent are still scored (a dropped connection must not cost the whole quiz); late is marked.
            $overtime = $w['time_limit_min'] && time() > strtotime((string) $s['started_at']) + (int) $w['time_limit_min'] * 60 + 60;
            $given = []; $scores = []; $auto = 0.0; $manual = false;
            foreach ($qs as $q) {
                $a = $answers[$q['id']] ?? null;
                if ($q['qtype'] === 'CHECK') $a = array_values(array_map('intval', (array) $a));
                elseif ($a !== null) $a = mb_substr(trim((string) $a), 0, $q['qtype'] === 'ESSAY' ? 20000 : 500);
                $given[$q['id']] = $a;
                $sc = self::autoScore($q, $a);
                if ($sc === null) $manual = true; else { $scores[$q['id']] = $sc; $auto += $sc; }
            }
            $late = ($w['due_at'] && strtotime((string) $w['due_at']) < time()) || $overtime;
            $done = !$manual && (int) $w['show_results'];
            Db::q("UPDATE lms_submissions SET answers = ?, question_scores = ?, status = ?, submitted_at = NOW(), is_late = ?, attempts = attempts + 1, score = ?, graded_at = ?, returned_at = ? WHERE id = ?",
                [json_encode($given, JSON_UNESCAPED_UNICODE), json_encode($scores), $done ? 'RETURNED' : 'SUBMITTED', $late ? 1 : 0, $manual ? null : $auto, $manual ? null : date('Y-m-d H:i:s'), $done ? date('Y-m-d H:i:s') : null, $s['id']]);
            Audit::log('lms_quiz_turned_in', 'lms_work', (int) $w['id'], ['submission' => (int) $s['id'], 'auto_score' => $auto, 'needs_teacher' => $manual]);
            return ['score' => $manual ? null : $auto, 'auto' => $auto, 'manual' => $manual, 'released' => $done, 'points' => (float) $w['points']];
        });
    }

    /** The teacher scores (or overrides) single questions of a turned-in quiz; the total updates. Returns the new total. */
    public static function scoreQuestions(array $c, int $workId, string $studentDocId, array $perQuestion, string $feedback, bool $return): float
    {
        self::requireTeach($c);
        $w = self::work($c, $workId); $qs = array_column(self::questions($workId), null, 'id');
        return Db::tx(function () use ($w, $studentDocId, $perQuestion, $qs, $feedback, $return) {
            $s = Db::one('SELECT * FROM lms_submissions WHERE work_id = ? AND student_doc_id = ? FOR UPDATE', [$w['id'], $studentDocId]);
            if (!$s || $s['status'] === 'DRAFT') throw new AppError('This student has not turned in the quiz.');
            $scores = json_decode((string) $s['question_scores'], true) ?: [];
            foreach ($perQuestion as $qid => $v) {
                if (!isset($qs[$qid]) || trim((string) $v) === '') continue;
                if (!is_numeric($v) || (float) $v < 0 || (float) $v > (float) $qs[$qid]['points']) throw new AppError('Question scores must be between 0 and the question\'s points.');
                $scores[$qid] = round((float) $v, 2);
            }
            $missing = array_filter($qs, fn($q) => !array_key_exists($q['id'], $scores));
            $total = array_sum(array_map('floatval', $scores));
            if ($return && $missing) throw new AppError('Score every essay question before returning the quiz.');
            Db::q('UPDATE lms_submissions SET question_scores = ?, score = ?, feedback = ?, graded_by = ?, graded_at = NOW()' . ($return ? ", status = 'RETURNED', returned_at = NOW()" : '') . ' WHERE id = ?',
                [json_encode($scores), $missing ? null : $total, trim($feedback) !== '' ? mb_substr(trim($feedback), 0, 10000) : null, Auth::id(), $s['id']]);
            Audit::log($return ? 'lms_quiz_returned' : 'lms_quiz_scored', 'lms_work', (int) $w['id'], ['student' => $s['student_no'], 'score' => $total]);
            if ($return) self::notifyReturned($w, $studentDocId, (float) $total);
            return $total;
        });
    }

    // ------------------------------------------------------------------ submissions

    /** A student saves and (optionally) turns in their work: files, a typed answer and/or a link. */
    public static function submit(array $c, int $workId, array $in, array $uploads, bool $turnIn): int
    {
        if ($c['role'] !== 'STUDENT') throw new Forbidden('Only students hand in work.');
        $w = self::work($c, $workId); $me = self::me();
        if ($w['kind'] === 'QUIZ') throw new AppError('Answer the quiz questions and turn it in from the quiz page.');
        $files = array_map([self::class, 'readUpload'], $uploads);
        return Db::tx(function () use ($w, $in, $files, $turnIn, $me) {
            $s = Db::one('SELECT * FROM lms_submissions WHERE work_id = ? AND student_doc_id = ? FOR UPDATE', [$w['id'], $me['student']]);
            if ($s && $s['status'] === 'RETURNED' && !(int) $w['allow_resubmit']) throw new AppError('This work was returned and can\'t be handed in again.');
            if ($s && $s['status'] === 'SUBMITTED') throw new AppError('You already turned this in. Unsubmit it first to change it.');
            $late = $w['due_at'] && strtotime((string) $w['due_at']) < time();
            if ($turnIn && $late && !(int) $w['allow_late']) throw new AppError('The due date has passed and this work does not accept late hand-ins.');
            $text = trim((string) ($in['text_answer'] ?? '')); $link = self::link((string) ($in['link_url'] ?? ''));
            $row = ['text_answer' => $text !== '' ? mb_substr($text, 0, 50000) : null, 'link_url' => $link];
            if (!$s) {
                $id = Db::insert('lms_submissions', $row + ['work_id' => (int) $w['id'], 'student_doc_id' => $me['student'], 'student_no' => (string) $me['student_no'], 'student_name' => $me['student_name']]);
            } else { $id = (int) $s['id']; Db::q('UPDATE lms_submissions SET text_answer = ?, link_url = ?, status = \'DRAFT\' WHERE id = ?', [$row['text_answer'], $row['link_url'], $id]); }
            self::storeFiles('SUBMISSION', $id, $files);
            if ($turnIn) {
                if (!$row['text_answer'] && !$row['link_url'] && !Db::val("SELECT 1 FROM lms_files WHERE owner_type = 'SUBMISSION' AND owner_id = ?", [$id])) throw new AppError('Attach a file, type an answer or add a link before turning it in.');
                Db::q("UPDATE lms_submissions SET status = 'SUBMITTED', submitted_at = NOW(), is_late = ?, attempts = attempts + 1 WHERE id = ?", [$late ? 1 : 0, $id]);
                Audit::log('lms_work_turned_in', 'lms_work', (int) $w['id'], ['submission' => $id, 'late' => $late]);
            }
            return $id;
        });
    }

    public static function unsubmit(array $c, int $workId): void
    {
        if ($c['role'] !== 'STUDENT') throw new Forbidden('Only students unsubmit their work.');
        $w = self::work($c, $workId);
        if ($w['kind'] === 'QUIZ') throw new AppError('A turned-in quiz can\'t be taken back.');
        $n = Db::q("UPDATE lms_submissions SET status = 'DRAFT' WHERE work_id = ? AND student_doc_id = ? AND status = 'SUBMITTED'", [$w['id'], self::me()['student']])->rowCount();
        if (!$n) throw new AppError('There is nothing turned in to unsubmit.');
    }

    public static function removeMyFile(array $c, int $fileId): void
    {
        $f = Db::one("SELECT f.id, s.status, s.student_doc_id FROM lms_files f JOIN lms_submissions s ON s.id = f.owner_id WHERE f.id = ? AND f.owner_type = 'SUBMISSION'", [$fileId]) ?: throw new AppError('File not found.');
        if ($c['role'] !== 'STUDENT' || $f['student_doc_id'] !== self::me()['student']) throw new Forbidden('You can remove only your own files.');
        if ($f['status'] === 'SUBMITTED') throw new AppError('Unsubmit first to change your files.');
        Db::q('DELETE FROM lms_files WHERE id = ?', [$fileId]);
    }

    /** Every student of the class with their submission for one piece of work. */
    public static function submissions(array $c, int $workId): array
    {
        self::requireTeach($c);
        $subs = array_column(Db::all('SELECT * FROM lms_submissions WHERE work_id = ?', [$workId]), null, 'student_doc_id');
        $out = [];
        foreach (self::roster($c) as $sid => $st) {
            $s = $subs[$sid] ?? null;
            if ($s) $s['files'] = self::files('SUBMISSION', (int) $s['id']);
            $out[] = $st + ['sub' => $s];
        }
        return $out;
    }

    /** The teacher scores and gives feedback; return=true sends it back to the student (they see the score). */
    public static function grade(array $c, int $workId, string $studentDocId, ?string $score, string $feedback, bool $return): void
    {
        self::requireTeach($c);
        $w = self::work($c, $workId);
        $roster = self::roster($c);
        if (!isset($roster[$studentDocId])) throw new AppError('That student is not in this class.');
        $sc = null;
        if ($score !== null && trim($score) !== '') {
            if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', trim($score))) throw new AppError('Scores are numbers, e.g. 18 or 18.5.');
            $sc = (float) trim($score);
            if ($sc > (float) $w['points']) throw new AppError('The score is more than the ' . rtrim(rtrim($w['points'], '0'), '.') . ' points of this work.');
        }
        if ($return && $sc === null) throw new AppError('Give a score before returning the work.');
        $st = $roster[$studentDocId];
        Db::tx(function () use ($w, $studentDocId, $st, $sc, $feedback, $return) {
            $s = Db::one('SELECT * FROM lms_submissions WHERE work_id = ? AND student_doc_id = ? FOR UPDATE', [$w['id'], $studentDocId]);
            $id = $s ? (int) $s['id'] : Db::insert('lms_submissions', ['work_id' => (int) $w['id'], 'student_doc_id' => $studentDocId, 'student_no' => $st['no'], 'student_name' => $st['name']]);
            Db::q('UPDATE lms_submissions SET score = ?, feedback = ?, graded_by = ?, graded_at = NOW()' . ($return ? ", status = 'RETURNED', returned_at = NOW()" : '') . ' WHERE id = ?',
                [$sc, trim($feedback) !== '' ? mb_substr(trim($feedback), 0, 10000) : null, Auth::id(), $id]);
            Audit::log($return ? 'lms_work_returned' : 'lms_work_scored', 'lms_work', (int) $w['id'], ['student' => $st['no'], 'score' => $sc]);
            if ($return) self::notifyReturned($w, $studentDocId, (float) $sc);
        });
    }

    // ------------------------------------------------------------------ attendance

    /** Meetings of the class from the SIS class schedule (days and times), for labels and the weekly view. */
    public static function meetings(array $c): array
    {
        return array_values(array_filter(Schedules::list(['school_year' => $c['schoolYear'], 'term' => $c['term'], 'section' => $c['sectionId'], 'subject' => $c['subjectId']])));
    }

    /** Record attendance for one meeting (date + label); $marks: student doc id => status. Returns the session id. */
    public static function takeAttendance(array $c, string $date, string $label, array $marks, array $notes = [], string $topic = ''): int
    {
        self::requireTeach($c);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw new AppError('Choose the date of the meeting.');
        if ($date > date('Y-m-d')) throw new AppError('Attendance can\'t be taken for a future date.');
        $roster = self::roster($c);
        foreach ($marks as $sid => $st) {
            if (!isset($roster[$sid])) throw new AppError('A student is not in this class.');
            if (!isset(self::ATTENDANCE[$st])) throw new AppError('Choose Present, Late, Absent or Excused.');
        }
        return Db::tx(function () use ($c, $date, $label, $marks, $notes, $topic) {
            $label = mb_substr(trim($label), 0, 80);
            $id = (int) Db::val('SELECT id FROM lms_attendance_sessions WHERE class_id = ? AND meeting_date = ? AND label = ?', [$c['id'], $date, $label]);
            if (!$id) $id = Db::insert('lms_attendance_sessions', ['class_id' => $c['id'], 'meeting_date' => $date, 'label' => $label, 'topic' => trim($topic) !== '' ? mb_substr(trim($topic), 0, 200) : null, 'created_by' => Auth::id()]);
            elseif (trim($topic) !== '') Db::q('UPDATE lms_attendance_sessions SET topic = ? WHERE id = ?', [mb_substr(trim($topic), 0, 200), $id]);
            foreach ($marks as $sid => $st)
                Db::q('INSERT INTO lms_attendance (session_id, student_doc_id, status, note, marked_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), marked_by = VALUES(marked_by)',
                    [$id, (string) $sid, $st, ($n = trim((string) ($notes[$sid] ?? ''))) !== '' ? mb_substr($n, 0, 200) : null, Auth::id()]);
            Audit::log('lms_attendance_taken', 'lms_class', $c['id'], ['date' => $date, 'label' => $label, 'students' => count($marks)]);
            return $id;
        });
    }

    public static function sessions(string $classId): array
    {
        return Db::all("SELECT s.*, SUM(a.status = 'PRESENT') present, SUM(a.status = 'LATE') late, SUM(a.status = 'ABSENT') absent, SUM(a.status = 'EXCUSED') excused
            FROM lms_attendance_sessions s LEFT JOIN lms_attendance a ON a.session_id = s.id WHERE s.class_id = ? GROUP BY s.id ORDER BY s.meeting_date DESC, s.label", [$classId]);
    }

    public static function sessionMarks(int $sessionId): array
    {
        return array_column(Db::all('SELECT * FROM lms_attendance WHERE session_id = ?', [$sessionId]), null, 'student_doc_id');
    }

    /** Per student: counts and attendance rate (present + late + excused over meetings marked). */
    public static function attendanceSummary(string $classId): array
    {
        $out = [];
        foreach (Db::all("SELECT a.student_doc_id, SUM(a.status = 'PRESENT') p, SUM(a.status = 'LATE') l, SUM(a.status = 'ABSENT') a, SUM(a.status = 'EXCUSED') e, COUNT(*) n
            FROM lms_attendance a JOIN lms_attendance_sessions s ON s.id = a.session_id WHERE s.class_id = ? GROUP BY a.student_doc_id", [$classId]) as $r)
            $out[$r['student_doc_id']] = ['present' => (int) $r['p'], 'late' => (int) $r['l'], 'absent' => (int) $r['a'], 'excused' => (int) $r['e'], 'total' => (int) $r['n'],
                'rate' => (int) $r['n'] ? round(((int) $r['p'] + (int) $r['l'] + (int) $r['e']) * 100 / (int) $r['n'], 1) : null];
        return $out;
    }

    public static function myAttendance(array $c): array
    {
        return Db::all('SELECT s.meeting_date, s.label, s.topic, a.status, a.note FROM lms_attendance_sessions s LEFT JOIN lms_attendance a ON a.session_id = s.id AND a.student_doc_id = ?
            WHERE s.class_id = ? ORDER BY s.meeting_date DESC', [self::me()['student'], $c['id']]);
    }

    // ------------------------------------------------------------------ gradebook

    /**
     * Weighted class-work grade per student. Each category's percent = points earned / points possible of its published work
     * (returned or scored work counts; past-due work with nothing turned in counts as 0 when "count missing" is on).
     * Categories without any counted work are left out and the remaining weights are scaled up.
     */
    public static function gradebook(array $c): array
    {
        $set = self::settings($c['id']);
        $works = Db::all("SELECT * FROM lms_work WHERE class_id = ? AND deleted_at IS NULL AND status = 'PUBLISHED' ORDER BY due_at IS NULL, due_at, id", [$c['id']]);
        $subs = [];
        foreach (Db::all('SELECT s.* FROM lms_submissions s JOIN lms_work w ON w.id = s.work_id WHERE w.class_id = ?', [$c['id']]) as $s) $subs[$s['work_id']][$s['student_doc_id']] = $s;
        $rows = [];
        foreach (self::roster($c) as $sid => $st) {
            $cat = []; $cells = [];
            foreach ($works as $w) {
                $s = $subs[$w['id']][$sid] ?? null;
                $score = $s && $s['score'] !== null ? (float) $s['score'] : null;
                $past = $w['due_at'] && strtotime((string) $w['due_at']) < time();
                $missing = $score === null && $past && (!$s || $s['status'] === 'DRAFT');
                $cells[$w['id']] = ['score' => $score, 'missing' => $missing, 'status' => $s['status'] ?? null, 'late' => (bool) ($s['is_late'] ?? false)];
                if ($score === null && !($missing && $set['count_missing'])) continue;
                $cat[$w['category']]['earned'] = ($cat[$w['category']]['earned'] ?? 0) + ($score ?? 0);
                $cat[$w['category']]['possible'] = ($cat[$w['category']]['possible'] ?? 0) + (float) $w['points'];
            }
            $sumW = 0; $sum = 0; $byCat = [];
            foreach ($set['weights'] as $name => $weight) {
                if (empty($cat[$name]['possible'])) { $byCat[$name] = null; continue; }
                $pct = $cat[$name]['earned'] * 100 / $cat[$name]['possible'];
                $byCat[$name] = round($pct, 2); $sumW += $weight; $sum += $weight * $pct;
            }
            $final = $sumW > 0 ? GradeScale::round2($sum / $sumW) : null;
            $rows[$sid] = $st + ['cells' => $cells, 'categories' => $byCat, 'percent' => $final, 'grade' => $final === null ? null : self::toGradingScale($final)];
        }
        return ['works' => $works, 'rows' => $rows, 'weights' => $set['weights'], 'count_missing' => $set['count_missing'], 'scale' => GradeScale::current()->scale()];
    }

    /** A class-work percent on the Grading System's grade-entry scale (percent, or 1.00–5.00 through the school's transmutation table). */
    public static function toGradingScale(float $percent): float
    {
        $gs = GradeScale::current();
        if ($gs->scale() === 'percent') return GradeScale::round2(max(0, min(100, $percent)));
        $v = GradeScale::round2($percent); $g = 5.0;
        foreach ($gs->transmutationRows() as $r) if ($v >= (float) $r['min']) { $g = (float) $r['grade']; break; }
        return $gs->scale() === 'point5' ? 6 - $g : $g;
    }

    /**
     * Copy the class-work grades into the Grading System's grade sheet as DRAFTS of this class's teacher.
     * Submitted grades, students whose term is tagged Complete, and closed grade entry are left alone; the teacher still
     * reviews and submits in the Grading System, and the registrar finalizes there. Returns counts.
     */
    public static function sendToGrading(array $c, array $studentIds = []): array
    {
        self::requireTeach($c);
        $book = self::gradebook($c);
        $opt = json_decode((string) Db::val("SELECT data FROM docs WHERE col = 'settings' AND id = 'options'"), true) ?: [];
        $dl = $opt['termDeadlines'][$c['term']]['__ts'] ?? null;
        if ($dl && strtotime((string) $dl) < time()) throw new AppError('Grade entry for ' . $c['term'] . ' is closed (the deadline has passed).');
        $down = json_decode((string) Db::val("SELECT data FROM docs WHERE col = 'settings' AND id = 'downtime'"), true) ?: [];
        if (($down['teachersLocked'] ?? false) === true && (empty($down['until']['__ts']) || strtotime((string) $down['until']['__ts']) > time())) throw new AppError('Grade entry is locked right now. Try again later.');
        $gs = GradeScale::current(); $a = $c['raw'];
        $res = ['sent' => 0, 'updated' => 0, 'skipped_submitted' => 0, 'skipped_complete' => 0, 'skipped_empty' => 0];
        $termKey = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($c['term'])), '-') ?: 'noterm';
        Db::tx(function () use ($c, $book, $gs, $a, &$res, $studentIds, $termKey) {
            foreach ($book['rows'] as $sid => $r) {
                if ($studentIds && !in_array($sid, $studentIds, true)) continue;
                if ($r['grade'] === null) { $res['skipped_empty']++; continue; }
                if (Db::val("SELECT 1 FROM docs WHERE col = 'gradeCompletions' AND id IN (?, ?)", ["{$sid}__{$c['schoolYear']}__{$termKey}", "{$sid}__{$c['schoolYear']}__year"])) { $res['skipped_complete']++; continue; }
                $gid = $c['id'] . '_' . $sid;
                $now = GS::get('grades', $gid);
                if ($now && ($now['draft'] ?? null) !== true) { $res['skipped_submitted']++; continue; }
                $rec = ['assignmentId' => $c['id'], 'teacherDocId' => $a['teacherDocId'] ?? '', 'teacherId' => $a['teacherId'] ?? '', 'teacherName' => $a['teacherName'] ?? '',
                    'subjectId' => $a['subjectId'] ?? '', 'subjectCode' => $a['subjectCode'] ?? '', 'subjectName' => $a['subjectName'] ?? '', 'units' => $a['units'] ?? null, // exactly as on the class: the Grading System checks it matches
                    'schoolYear' => $c['schoolYear'], 'term' => $c['term'], 'yearLevel' => $a['yearLevel'] ?? '', 'sectionId' => $c['sectionId'], 'sectionName' => $c['sectionName'],
                    'studentId' => $sid, 'studentNumber' => $r['no'], 'studentName' => $r['name'], 'finalGrade' => $r['grade'], 'remarks' => $gs->remarksFor((float) $r['grade']),
                    'draft' => true, 'source' => 'LMS', 'updatedAt' => GS::ts()];
                $data = $now ? array_merge(array_diff_key($now, ['id' => 1]), $rec) : $rec + ['createdAt' => GS::ts()];
                Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)', ['grades', $gid, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
                $res[$now ? 'updated' : 'sent']++;
            }
            Audit::log('lms_grades_sent_to_grading', 'lms_class', $c['id'], $res);
        });
        return $res;
    }

    // ------------------------------------------------------------------ to do

    /** A student's upcoming and missing work across classes; a teacher's work waiting to be graded. */
    public static function todo(array $classes): array
    {
        $ids = array_column($classes, 'id');
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $me = self::me();
        $names = array_column($classes, 'subjectCode', 'id');
        if ($me['student'] && array_filter($classes, fn($c) => $c['role'] === 'STUDENT')) {
            $rows = Db::all("SELECT w.*, s.status AS sub_status, s.is_late FROM lms_work w LEFT JOIN lms_submissions s ON s.work_id = w.id AND s.student_doc_id = ?
                WHERE w.class_id IN ($in) AND w.deleted_at IS NULL AND w.status = 'PUBLISHED' AND (s.status IS NULL OR s.status = 'DRAFT') AND (w.due_at IS NULL OR w.due_at >= DATE_SUB(NOW(), INTERVAL 14 DAY))
                ORDER BY w.due_at IS NULL, w.due_at LIMIT 12", array_merge([$me['student']], $ids));
        } else {
            $rows = Db::all("SELECT w.*, (SELECT COUNT(*) FROM lms_submissions s WHERE s.work_id = w.id AND s.status = 'SUBMITTED') AS to_grade FROM lms_work w
                WHERE w.class_id IN ($in) AND w.deleted_at IS NULL HAVING to_grade > 0 ORDER BY w.due_at LIMIT 12", $ids);
        }
        foreach ($rows as &$r) $r['class_code'] = $names[$r['class_id']] ?? '';
        return $rows;
    }

    // ------------------------------------------------------------------ v61.1 dashboard and cross-class pages

    /** Schedule day code (M, T, W, Th, F, S, Su) of a date. */
    public static function dayCode(int $ts): string
    {
        return ['Mon' => 'M', 'Tue' => 'T', 'Wed' => 'W', 'Thu' => 'Th', 'Fri' => 'F', 'Sat' => 'S', 'Sun' => 'Su'][date('D', $ts)];
    }

    /** Every meeting of the given classes (from the SIS class schedule), each with its class. */
    public static function allMeetings(array $classes): array
    {
        $out = []; $lists = [];
        foreach ($classes as $c) {
            $k = $c['schoolYear'] . '|' . $c['term']; // v62: one schedule query per term, not per class
            $lists[$k] ??= Schedules::list(['school_year' => $c['schoolYear'], 'term' => $c['term']]);
            foreach ($lists[$k] as $m) if ($m['section_doc_id'] === $c['sectionId'] && $m['subject_doc_id'] === $c['subjectId'])
                $out[] = $m + ['class' => $c, 'label' => Schedules::timeRange($m) . (($m['kind'] ?? '') === 'LAB' ? ' Lab' : '')];
        }
        usort($out, fn($a, $b) => [$a['start_time'], $a['end_time']] <=> [$b['start_time'], $b['end_time']]);
        return $out;
    }

    /** The weekly timetable: periods (distinct time slots, P1…), the school days used (Mon–Fri plus any weekend day), and cells. */
    public static function timetable(array $meetings): array
    {
        $slots = []; $days = ['M' => 1, 'T' => 1, 'W' => 1, 'Th' => 1, 'F' => 1];
        foreach ($meetings as $m) {
            $slots[$m['start_time'] . '|' . $m['end_time']] = [$m['start_time'], $m['end_time']];
            foreach (Schedules::parseDays(explode(',', (string) $m['days'])) as $d) $days[$d] = 1;
        }
        ksort($slots); $slots = array_values($slots);
        $days = array_values(array_intersect(array_keys(Schedules::DAYS), array_keys($days)));
        $cells = [];
        foreach ($meetings as $m) {
            $p = array_search([$m['start_time'], $m['end_time']], $slots, true);
            foreach (Schedules::parseDays(explode(',', (string) $m['days'])) as $d) $cells[$p][$d][] = $m;
        }
        return ['slots' => $slots, 'days' => $days, 'cells' => $cells];
    }

    /** Meetings on a date, each with its period number (P1 = earliest slot of the week). */
    public static function meetingsOn(array $meetings, int $ts): array
    {
        $tt = self::timetable($meetings); $d = self::dayCode($ts); $out = [];
        foreach ($meetings as $m) if (in_array($d, Schedules::parseDays(explode(',', (string) $m['days'])), true))
            $out[] = $m + ['period' => 1 + (int) array_search([$m['start_time'], $m['end_time']], $tt['slots'], true)];
        return $out;
    }

    /** Attendance marked on a date in the given classes: present, late, absent, excused, total. */
    public static function attendanceOn(array $classIds, string $date): array
    {
        $r = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'total' => 0];
        if (!$classIds) return $r;
        $in = implode(',', array_fill(0, count($classIds), '?'));
        $x = Db::one("SELECT SUM(a.status = 'PRESENT') p, SUM(a.status = 'LATE') l, SUM(a.status = 'ABSENT') a, SUM(a.status = 'EXCUSED') e, COUNT(*) n
            FROM lms_attendance a JOIN lms_attendance_sessions s ON s.id = a.session_id WHERE s.meeting_date = ? AND s.class_id IN ($in)", array_merge([$date], array_values($classIds)));
        return ['present' => (int) $x['p'], 'late' => (int) $x['l'], 'absent' => (int) $x['a'], 'excused' => (int) $x['e'], 'total' => (int) $x['n']];
    }

    /** A student's own attendance over the given classes. */
    public static function myAttendanceTotals(array $classIds): array
    {
        $r = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'total' => 0];
        $sid = self::me()['student'];
        if (!$classIds || !$sid) return $r;
        $in = implode(',', array_fill(0, count($classIds), '?'));
        $x = Db::one("SELECT SUM(a.status = 'PRESENT') p, SUM(a.status = 'LATE') l, SUM(a.status = 'ABSENT') a, SUM(a.status = 'EXCUSED') e, COUNT(*) n
            FROM lms_attendance a JOIN lms_attendance_sessions s ON s.id = a.session_id WHERE a.student_doc_id = ? AND s.class_id IN ($in)", array_merge([$sid], array_values($classIds)));
        return ['present' => (int) $x['p'], 'late' => (int) $x['l'], 'absent' => (int) $x['a'], 'excused' => (int) $x['e'], 'total' => (int) $x['n']];
    }

    /** All classwork of the given classes (teachers: with turned-in and to-grade counts; students: published only, with their own submission). */
    public static function allWork(array $classes): array
    {
        $out = [];
        foreach ($classes as $c) foreach (self::classwork($c) as $w) $out[] = $w + ['class' => $c];
        usort($out, fn($a, $b) => [$a['due_at'] === null, $a['due_at'] ?? '', -(int) $a['id']] <=> [$b['due_at'] === null, $b['due_at'] ?? '', -(int) $b['id']]);
        return $out;
    }

    /** Files teachers shared in the given classes (stream posts, materials and classwork), newest first. Students see published classwork only. */
    public static function materials(array $classes, int $limit = 500): array
    {
        $ids = array_column($classes, 'id');
        if (!$ids) return [];
        $by = array_column($classes, null, 'id'); $in = implode(',', array_fill(0, count($ids), '?'));
        $studentOnly = !array_filter($classes, fn($c) => $c['role'] !== 'STUDENT');
        $rows = Db::all("SELECT f.id, f.name, f.mime, f.size_bytes, f.created_at, p.class_id, COALESCE(p.title, IF(p.kind = 'MATERIAL', 'Material', 'Announcement')) AS source, p.kind AS source_kind, NULL AS work_id
              FROM lms_files f JOIN lms_posts p ON f.owner_type = 'POST' AND p.id = f.owner_id WHERE p.deleted_at IS NULL AND p.class_id IN ($in)
            UNION ALL
            SELECT f.id, f.name, f.mime, f.size_bytes, f.created_at, w.class_id, w.title, w.kind, w.id
              FROM lms_files f JOIN lms_work w ON f.owner_type = 'WORK' AND w.id = f.owner_id WHERE w.deleted_at IS NULL AND w.class_id IN ($in)" . ($studentOnly ? " AND w.status = 'PUBLISHED'" : '') . "
            ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit), array_merge($ids, $ids));
        foreach ($rows as &$r) $r['class'] = $by[$r['class_id']];
        return $rows;
    }

    /** Files shared in the classes, counted. */
    public static function materialCount(array $classes): int { return count(self::materials($classes)); }

    // ------------------------------------------------------------------ v61.1 messages

    /** Conversations of the signed-in person: teachers, one per student who has messages; students, one per class. Newest first. */
    public static function threads(array $classes): array
    {
        $ids = array_column($classes, 'id');
        if (!$ids) return [];
        $by = array_column($classes, null, 'id'); $in = implode(',', array_fill(0, count($ids), '?'));
        $me = self::me(); $out = [];
        $teachIds = array_column(array_filter($classes, fn($c) => $c['role'] !== 'STUDENT'), 'id');
        if ($teachIds) {
            $tin = implode(',', array_fill(0, count($teachIds), '?'));
            foreach (Db::all("SELECT m.class_id, m.student_doc_id, MAX(m.id) last_id, SUM(m.from_teacher = 0 AND m.read_at IS NULL) unread FROM lms_messages m
                WHERE m.class_id IN ($tin) GROUP BY m.class_id, m.student_doc_id", $teachIds) as $r) $out[] = $r;
        }
        if ($me['student']) {
            foreach (Db::all("SELECT m.class_id, m.student_doc_id, MAX(m.id) last_id, SUM(m.from_teacher = 1 AND m.read_at IS NULL) unread FROM lms_messages m
                WHERE m.student_doc_id = ? AND m.class_id IN ($in) GROUP BY m.class_id, m.student_doc_id", array_merge([$me['student']], $ids)) as $r)
                if (($by[$r['class_id']]['role'] ?? '') === 'STUDENT') $out[] = $r;
        }
        foreach ($out as &$t) {
            $last = Db::one('SELECT body, created_at, from_teacher FROM lms_messages WHERE id = ?', [$t['last_id']]);
            $c = $by[$t['class_id']]; $t['unread'] = (int) $t['unread'];
            $t += ['class' => $c, 'body' => $last['body'], 'at' => $last['created_at'], 'mine' => (bool) $last['from_teacher'] === ($c['role'] !== 'STUDENT'),
                'with' => $c['role'] === 'STUDENT' ? $c['teacherName'] : (string) (GS::get('students', $t['student_doc_id'])['studentName'] ?? 'Student')];
        }
        unset($t);
        usort($out, fn($a, $b) => (int) $b['last_id'] <=> (int) $a['last_id']);
        return $out;
    }

    public static function unreadMessages(array $classes): int { return array_sum(array_column(self::threads($classes), 'unread')); }

    /** Checks the signed-in person may read the conversation of a class and student; returns whether they are the teacher's side. */
    private static function threadSide(array $c, string $studentDocId): bool
    {
        if ($c['role'] === 'STUDENT') {
            if ($studentDocId !== self::me()['student']) throw new Forbidden('You can only read your own messages.');
            return false;
        }
        if (!in_array($studentDocId, $c['studentIds'], true)) throw new AppError('That student is not in this class.');
        return true;
    }

    /** The messages of one conversation, oldest first; marks the other side's messages as read. */
    public static function thread(array $c, string $studentDocId): array
    {
        $teacher = self::threadSide($c, $studentDocId);
        Db::q('UPDATE lms_messages SET read_at = NOW() WHERE class_id = ? AND student_doc_id = ? AND from_teacher = ? AND read_at IS NULL', [$c['id'], $studentDocId, $teacher ? 0 : 1]);
        return Db::all('SELECT * FROM lms_messages WHERE class_id = ? AND student_doc_id = ? ORDER BY id', [$c['id'], $studentDocId]);
    }

    public static function sendMessage(array $c, string $studentDocId, string $body): int
    {
        $teacher = self::threadSide($c, $studentDocId);
        $body = trim($body);
        if ($body === '') throw new AppError('Write a message first.');
        if (mb_strlen($body) > 5000) throw new AppError('Messages can be up to 5,000 characters.');
        $id = Db::insert('lms_messages', ['class_id' => $c['id'], 'student_doc_id' => $studentDocId, 'from_teacher' => $teacher ? 1 : 0, 'sender_id' => Auth::id(),
            'sender_name' => self::me()['name'] ?: ($teacher ? $c['teacherName'] : 'Student'), 'body' => $body]);
        Audit::log('lms_message_sent', 'lms_class', $c['id'], ['message' => $id, 'student' => $studentDocId, 'from_teacher' => $teacher]);
        $to = $teacher ? Notify::accountOfStudent($studentDocId) : Notify::accountOfTeacher($c['teacherDocId']);
        Notify::toAccount($to, 'LMS_MESSAGE', 'New message from ' . (self::me()['name'] ?: ($teacher ? $c['teacherName'] : 'a student')), mb_strimwidth($body, 0, 240, '…') . ' · ' . $c['subjectCode'],
            '../lms/index.php?' . http_build_query(['r' => 'messages', 'c' => $c['id']] + ($teacher ? [] : ['s' => $studentDocId])));
        return $id;
    }

    /** Per class: students, published work, hand-in rate, class average and attendance rate (for Reports and the Gradebook list). */
    public static function classReport(array $c): array
    {
        $book = self::gradebook($c);
        $pct = array_filter(array_column($book['rows'], 'percent'), fn($v) => $v !== null);
        $gs = GradeScale::current();
        $passing = count(array_filter($book['rows'], fn($r) => $r['grade'] !== null && stripos($gs->remarksFor((float) $r['grade']), 'pass') === 0));
        $n = count($book['rows']); $works = count($book['works']);
        $handed = $works && $n ? (int) Db::val("SELECT COUNT(*) FROM lms_submissions s JOIN lms_work w ON w.id = s.work_id WHERE w.class_id = ? AND w.deleted_at IS NULL AND w.status = 'PUBLISHED' AND s.status IN ('SUBMITTED','RETURNED')", [$c['id']]) : 0;
        $late = (int) Db::val("SELECT COUNT(*) FROM lms_submissions s JOIN lms_work w ON w.id = s.work_id WHERE w.class_id = ? AND w.deleted_at IS NULL AND s.is_late = 1 AND s.status <> 'DRAFT'", [$c['id']]);
        $missing = 0; foreach ($book['rows'] as $r) foreach ($r['cells'] as $x) $missing += $x['missing'] ? 1 : 0;
        $att = Db::one("SELECT SUM(a.status IN ('PRESENT','LATE','EXCUSED')) ok, COUNT(*) n, COUNT(DISTINCT s.id) meetings FROM lms_attendance_sessions s LEFT JOIN lms_attendance a ON a.session_id = s.id WHERE s.class_id = ?", [$c['id']]);
        return ['students' => $n, 'works' => $works, 'handed' => $handed, 'expected' => $works * $n, 'late' => $late, 'missing' => $missing,
            'average' => $pct ? round(array_sum($pct) / count($pct), 2) : null, 'passing' => $passing, 'graded' => count($pct),
            'meetings' => (int) $att['meetings'], 'attendance' => (int) $att['n'] ? round((int) $att['ok'] * 100 / (int) $att['n'], 1) : null];
    }

    // ------------------------------------------------------------------ v62 storage limits

    public const DEFAULT_QUOTA_MB = 500;

    /** The file space each class may use, in bytes (0 = no limit). Set by the LMS coordinator. */
    public static function quotaBytes(): int
    {
        $v = Db::val("SELECT svalue FROM sis_settings WHERE skey = 'lms_class_quota_mb'");
        return ($v === null || $v === false ? self::DEFAULT_QUOTA_MB : max(0, (int) $v)) * 1048576;
    }

    public static function saveQuota(int $mb): void
    {
        if (!self::me()['admin']) throw new Forbidden('Only the LMS coordinator can change the storage limit.');
        if ($mb < 0 || $mb > 100000) throw new AppError('The limit is in MB (0 means no limit).');
        Db::q("INSERT INTO sis_settings (skey, svalue) VALUES ('lms_class_quota_mb', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)", [(string) $mb]);
        Audit::log('lms_quota_saved', 'settings', 0, ['mb' => $mb]);
    }

    /** Bytes of files in a class: stream posts, classwork and students' hand-ins. */
    public static function classUsage(string $classId): int
    {
        return (int) Db::val("SELECT COALESCE(SUM(f.size_bytes), 0) FROM lms_files f
            LEFT JOIN lms_posts p ON f.owner_type = 'POST' AND p.id = f.owner_id
            LEFT JOIN lms_work w ON f.owner_type = 'WORK' AND w.id = f.owner_id
            LEFT JOIN lms_submissions s ON f.owner_type = 'SUBMISSION' AND s.id = f.owner_id LEFT JOIN lms_work sw ON sw.id = s.work_id
            WHERE COALESCE(p.class_id, w.class_id, sw.class_id) = ?", [$classId]);
    }

    /** Storage by school year: classes, files and bytes (for the coordinator's clean-up). */
    public static function usageByYear(): array
    {
        $years = [];
        foreach (GS::all('gradingAssignments') as $a) $years[(string) ($a['id'])] = (string) ($a['schoolYear'] ?? '');
        $rows = Db::all("SELECT COALESCE(p.class_id, w.class_id, sw.class_id) cid, COUNT(*) n, SUM(f.size_bytes) b FROM lms_files f
            LEFT JOIN lms_posts p ON f.owner_type = 'POST' AND p.id = f.owner_id LEFT JOIN lms_work w ON f.owner_type = 'WORK' AND w.id = f.owner_id
            LEFT JOIN lms_submissions s ON f.owner_type = 'SUBMISSION' AND s.id = f.owner_id LEFT JOIN lms_work sw ON sw.id = s.work_id GROUP BY cid");
        $out = [];
        foreach ($rows as $r) { $y = $years[(string) $r['cid']] ?? '(class removed)'; $out[$y] ??= ['files' => 0, 'bytes' => 0, 'classes' => 0]; $out[$y]['files'] += (int) $r['n']; $out[$y]['bytes'] += (int) $r['b']; $out[$y]['classes']++; }
        krsort($out);
        return $out;
    }

    /**
     * Free up space: delete the files (not the grades, posts or hand-in records) of every class of an earlier school year.
     * The current school year can't be cleaned. Returns [files, bytes] removed.
     */
    public static function cleanYear(string $schoolYear, string $confirm): array
    {
        if (!self::me()['admin']) throw new Forbidden('Only the LMS coordinator can free up space.');
        $current = GS::schoolYears()[0] ?? '';
        if ($schoolYear === '' || $schoolYear === $current) throw new AppError('Files of the current school year (' . $current . ') can\'t be removed.');
        if (trim($confirm) !== $schoolYear) throw new AppError('Type the school year (' . $schoolYear . ') to confirm.');
        $ids = array_values(array_map(fn($a) => (string) $a['id'], array_filter(GS::all('gradingAssignments'), fn($a) => ($a['schoolYear'] ?? '') === $schoolYear)));
        if (!$ids) return [0, 0];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $files = Db::all("SELECT f.id, f.size_bytes FROM lms_files f LEFT JOIN lms_posts p ON f.owner_type = 'POST' AND p.id = f.owner_id LEFT JOIN lms_work w ON f.owner_type = 'WORK' AND w.id = f.owner_id
            LEFT JOIN lms_submissions s ON f.owner_type = 'SUBMISSION' AND s.id = f.owner_id LEFT JOIN lms_work sw ON sw.id = s.work_id WHERE COALESCE(p.class_id, w.class_id, sw.class_id) IN ($in)", $ids);
        $bytes = array_sum(array_map(fn($f) => (int) $f['size_bytes'], $files));
        foreach (array_chunk(array_column($files, 'id'), 500) as $chunk) Db::q('DELETE FROM lms_files WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
        Audit::log('lms_files_cleaned', 'settings', 0, ['school_year' => $schoolYear, 'files' => count($files), 'bytes' => $bytes]);
        return [count($files), $bytes];
    }

    public static function mb(int $bytes): string { return $bytes >= 1073741824 ? number_format($bytes / 1073741824, 1) . ' GB' : number_format($bytes / 1048576, $bytes < 10485760 ? 1 : 0) . ' MB'; }
}
