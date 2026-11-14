<?php
/**
 * Loads configuration, connects to MySQL, starts the secure session.
 * Used by index.php (web) and bin/*.php / tests (CLI).
 */
declare(strict_types=1);

define('SIS_ROOT', dirname(__DIR__));
define('FINANCE_ROOT', dirname(SIS_ROOT) . '/finance'); // v58: the Finance system's own folder
date_default_timezone_set('Asia/Manila');

require SIS_ROOT . '/app/lib/core.php';
require SIS_ROOT . '/app/lib/record-list.php';
require dirname(SIS_ROOT) . '/shared/central-accounts.php';
require_once dirname(SIS_ROOT) . '/shared/curriculum-assignments.php';
require_once dirname(SIS_ROOT) . '/shared/student-history.php';
require SIS_ROOT . '/app/lib/student-history.php';
require SIS_ROOT . '/app/lib/student-migration.php';
require SIS_ROOT . '/app/lib/auth.php';
require SIS_ROOT . '/app/lib/grading.php';
require SIS_ROOT . '/app/lib/sis.php';
require SIS_ROOT . '/app/lib/subject-fees.php';
require SIS_ROOT . '/app/lib/enrollment-docs.php';
require SIS_ROOT . '/app/lib/import-all.php';
require SIS_ROOT . '/app/lib/grade-scale.php';
require SIS_ROOT . '/app/lib/documents.php';
require SIS_ROOT . '/app/lib/account-email.php';
require SIS_ROOT . '/app/lib/document-requests.php';
require SIS_ROOT . '/app/lib/schedules.php';
require SIS_ROOT . '/app/lib/adddrop.php';
require SIS_ROOT . '/app/lib/clearance.php';
require SIS_ROOT . '/app/lib/balance.php';
require SIS_ROOT . '/app/lib/eligibility.php';
require SIS_ROOT . '/app/lib/portal-enrollment.php';
require SIS_ROOT . '/app/lib/payment-plans.php';
require SIS_ROOT . '/app/lib/notifications.php';
require SIS_ROOT . '/app/lib/notify.php'; // v62: personal notifications (in-app + email)
require SIS_ROOT . '/app/lib/student-photo.php'; // v62: student photos
require SIS_ROOT . '/app/lib/privacy.php'; // v62: Data Privacy Act helpers
require SIS_ROOT . '/app/lib/parents.php'; // v62: parent / guardian accounts
require SIS_ROOT . '/app/lib/backup.php'; // v63: whole-system backup and restore
require SIS_ROOT . '/app/lib/first-setup.php'; // v63.3: first password of power.admin
require SIS_ROOT . '/app/lib/balance-workspace.php';
require FINANCE_ROOT . '/modules/accounting/lib/accounting.php';
require FINANCE_ROOT . '/modules/accounting/lib/workflow.php';
require SIS_ROOT . '/app/lib/reports.php';
require SIS_ROOT . '/app/lib/xlsx.php';
require SIS_ROOT . '/app/lib/feeimport.php';
require dirname(SIS_ROOT) . '/canteen/lib/canteen.php'; // v59: Canteen POS (canteen/)
require dirname(SIS_ROOT) . '/library/lib/library.php'; // v60: Library (library/)
require dirname(SIS_ROOT) . '/lms/lib/lms.php'; // v61: Learning Management System (lms/)

/**
 * One database only: the SIS always uses the grading system's MySQL database, read from
 * ../api/config.php. There is no separate SIS database setting.
 * Optional SIS-only keys in api/config.php: sis_debug, sis_force_secure_cookie, timezone.
 */
function sis_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $shared = dirname(SIS_ROOT) . '/api/config.php';
    if (!is_file($shared)) {
        if (PHP_SAPI === 'cli') { fwrite(STDERR, "Missing api/config.php: set up the grading system's database first.\n"); exit(1); }
        http_response_code(500);
        exit('The SIS uses the grading system\'s database: fill in api/config.php first (see README-PHP.md).');
    }
    $g = require $shared;
    $cfg = [
        'db_host' => $g['db_host'] ?? 'localhost', 'db_port' => $g['db_port'] ?? 3306, 'db_name' => $g['db_name'],
        'db_user' => $g['db_user'], 'db_pass' => $g['db_pass'], 'db_socket' => $g['db_socket'] ?? null,
        // Never share the grading system's sign-in cookie
        'session_name' => 'SISSESSION',
        'force_secure_cookie' => !empty($g['sis_force_secure_cookie']),
        'timezone' => $g['timezone'] ?? 'Asia/Manila',
        'debug' => !empty($g['sis_debug']),
        // v51, optional: extra secret for encrypting saved keys; EmailJS address (tests use a local stand-in)
        'app_key' => (string) ($g['app_key'] ?? ''),
        'emailjs_endpoint' => $g['emailjs_endpoint'] ?? null,
        // v63, optional: folder for whole-system backups (best outside the website), e.g. '/home/school/acadlink-backups'
        'backup_dir' => (string) ($g['backup_dir'] ?? ''),
    ];
    date_default_timezone_set($cfg['timezone']);
    return $cfg;
}

function sis_boot(): void
{
    $cfg = sis_config();
    ini_set('display_errors', !empty($cfg['debug']) ? '1' : '0');
    error_reporting(E_ALL);
    try {
        Db::connect($cfg);
    } catch (PDOException $e) {
        error_log('SIS DB connect failed: ' . $e->getMessage());
        if (PHP_SAPI === 'cli') { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
        $code = (int) ($e->errorInfo[1] ?? 0); if (!$code && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $code = (int) $m[1];
        if (in_array($code, [1040, 1203, 1226], true)) { // too many connections: the hosting limit was reached for a moment
            http_response_code(503); header('Retry-After: 10'); header('Content-Type: text/html; charset=utf-8');
            $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
            exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . ($post ? '' : '<meta http-equiv="refresh" content="10">')
                . '<title>The system is busy</title><body style="font:16px/1.5 system-ui,sans-serif;max-width:34rem;margin:12vh auto;padding:0 16px;color:#1f2a25">'
                . '<h1 style="font-size:1.4rem">The system is busy right now</h1><p>Many people are using AcadLink at the same moment. '
                . ($post ? '<strong>Nothing was saved.</strong> Wait a few seconds, then go back and submit again.</p><p><a href="javascript:history.back()">Go back</a></p>'
                         : 'This page will try again by itself in 10 seconds.</p><p><a href="">Try again now</a></p>'));
        }
        http_response_code(500);
        exit('Cannot connect to the MySQL database. Check api/config.php.');
    }
    Auth::startSession($cfg);
    sis_upgrade();
    // v62: library reminders and canteen low-stock alerts (at most every 10 minutes), then queued emails, after the page is sent
    if (PHP_SAPI !== 'cli') register_shutdown_function(function () {
        try { if (!Auth::id()) return; if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); Notify::scan(); Notify::flushEmails(25); }
        catch (Throwable $e) { error_log('AcadLink notifications: ' . $e->getMessage()); }
    });
}

/**
 * Adds tables of newer SIS versions to an existing install (CREATE TABLE IF NOT EXISTS only;
 * nothing is changed or deleted). Checked once per session.
 */
function sis_upgrade(): void
{
    if (PHP_SAPI !== 'cli' && ($_SESSION['sis_schema'] ?? 0) >= 34) return;
    $have = array_map('strtolower', Db::col("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'sis\\_%'"));
    if (!in_array('sis_registrations', $have, true) || !in_array('sis_fee_labels', $have, true)) return; // not installed yet
    // Tables of newer versions: created from schema.sql (CREATE TABLE IF NOT EXISTS only)
    $sql = (string) file_get_contents(SIS_ROOT . '/database/schema.sql');
    foreach (['sis_payment_plans', 'sis_registration_plans', 'sis_payment_plan_events', 'sis_registration_labels', 'sis_discounts', 'sis_discount_categories', 'sis_registration_discounts', 'sis_settings'] as $t) {
        if (!in_array($t, $have, true) && preg_match('/CREATE TABLE IF NOT EXISTS ' . $t . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $m)) Db::q($m[0]);
    }
    // v2: registrations can be marked "done" (view only)
    if (!Db::val("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_registrations' AND column_name = 'locked_at'")) {
        Db::q('ALTER TABLE sis_registrations ADD COLUMN locked_at DATETIME NULL AFTER remarks, ADD COLUMN locked_by INT UNSIGNED NULL AFTER locked_at');
    }
    // v3: scholarship / discount lines on the assessment
    $type = (string) Db::val("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_registration_items' AND column_name = 'charge_type'");
    if ($type !== '' && !str_contains($type, 'DISCOUNT')) {
        Db::q("ALTER TABLE sis_registration_items MODIFY charge_type ENUM('FIXED','PER_UNIT','PER_SUBJECT','ADJUSTMENT','DISCOUNT') NOT NULL");
    }
    // v5: official receipt series; payments keep their series, numbers may be edited or made negative when voided
    if (!in_array('sis_or_series', $have, true) && preg_match('/CREATE TABLE IF NOT EXISTS sis_or_series \(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $m)) Db::q($m[0]);
    if (!Db::val('SELECT 1 FROM sis_or_series LIMIT 1')) {
        // the first series continues the numbering of the old receipt settings
        $old = array_column(Db::all("SELECT skey, svalue FROM sis_settings WHERE skey IN ('or_prefix', 'or_digits')"), 'svalue', 'skey');
        $digits = (int) ($old['or_digits'] ?? 7);
        Db::q("INSERT INTO sis_or_series (code, description, prefix, digits, next_number, template, is_active, auto_number, allow_edit, is_default) VALUES ('OR1', 'Official Receipt Series One', ?, ?, ?, 'STANDARD', 1, 1, 1, 1)",
            [(string) ($old['or_prefix'] ?? 'OR-'), $digits >= 3 && $digits <= 10 ? $digits : 7, max(1, (int) Db::val("SELECT next_value FROM sis_sequences WHERE name = 'receipt'"))]);
    }
    if (!Db::val("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_payments' AND column_name = 'series_id'")) {
        Db::q('ALTER TABLE sis_payments MODIFY receipt_no VARCHAR(30) NOT NULL, ADD COLUMN series_id INT UNSIGNED NULL AFTER receipt_no,
               ADD CONSTRAINT fk_sis_pay_series FOREIGN KEY (series_id) REFERENCES sis_or_series (id)');
        Db::q('UPDATE sis_payments SET series_id = (SELECT id FROM sis_or_series WHERE is_default = 1 ORDER BY id LIMIT 1) WHERE series_id IS NULL');
    }
    // v6: double-entry accounting, academic terms and deadlines, registrations an administrator allowed
    foreach (['sis_accounts', 'sis_account_map', 'sis_journal', 'sis_journal_lines', 'sis_reconciliations', 'sis_term_calendar', 'sis_balance_cases', 'sis_balance_case_events', 'sis_notifications', 'sis_eligibility_holds', 'sis_subject_prerequisites', 'sis_eligibility_decisions', 'sis_portal_enrollments', 'sis_portal_enrollment_events'] as $t) {
        if (!in_array($t, $have, true) && preg_match('/CREATE TABLE IF NOT EXISTS ' . $t . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $m)) Db::q($m[0]);
    }
    $needsAccountingSeed=!Db::val('SELECT 1 FROM sis_accounts LIMIT 1');
    AccountingWorkflow::install();
    if ($needsAccountingSeed) {
        $seed = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(SIS_ROOT . '/database/seed_accounting.sql'));
        foreach (array_filter(array_map('trim', explode(";\n", $seed))) as $stmt) Db::q($stmt);
        Accounting::syncExisting(); // books the confirmed assessments and payments recorded before accounting
    }
    if (Db::val("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sis_admissions' AND index_name = 'u_sis_adm_student'")) {
        Db::q('ALTER TABLE sis_admissions DROP INDEX u_sis_adm_student, ADD KEY k_sis_adm_student (student_number)');
    }
    $roles = (string) Db::val("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_users' AND column_name = 'role'");
    if ($roles !== '' && !str_contains($roles, 'ACCOUNTING')) Db::q("ALTER TABLE sis_users MODIFY role ENUM('ADMIN','REGISTRAR','ADMISSION','CASHIER','ACCOUNTING','STUDENT') NOT NULL");
    if (!Db::val("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_registrations' AND column_name = 'override_reason'")) {
        Db::q('ALTER TABLE sis_registrations ADD COLUMN override_reason VARCHAR(255) NULL AFTER remarks');
    }
    $admStatusType = (string) Db::val("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sis_admissions' AND column_name = 'status'");
    if ($admStatusType !== '' && !str_contains($admStatusType, "'CANCELLED'")) {
        Db::q("ALTER TABLE sis_admissions MODIFY status ENUM('PENDING','ADMITTED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING'");
    }
    PortalEnrollment::install();
    StudentMigration::install();
    ModuleAccess::install(Db::pdo()); // v40: per-module access and per-person overrides
    SubjectFees::install();            // v43: fees of particular subjects, per unit
    Documents::install(Db::pdo());     // v50: TOR and certificates, student details, e-signatures
    AccountEmail::install();           // v51: account emails and Forgot password
    DocumentRequests::install(Db::pdo()); // v52: document requests from the Student Portal
    Schedules::install(Db::pdo());     // v53: rooms and class schedules
    AddDrop::install();                // v54: prerequisite overrides and add/drop history
    Clearance::install(Db::pdo());     // v55: online clearance
    PaymentPlans::installRule();       // v56: payment plan before the first Cashier payment
    EnrollmentDocs::install();         // v56: student classifications and the document checklist
    ImportAll::install();              // v56: Data Migration → Import All (subjects, fees, teachers, curriculum)
    LecLab::install();                 // v56.2: lecture / laboratory units
    Canteen::install(Db::pdo());       // v59: Canteen POS
    Library::install(Db::pdo());       // v60: Library
    Lms::install(Db::pdo());           // v61: LMS
    Notify::install(Db::pdo());        // v62: personal notifications
    StudentPhoto::install(Db::pdo());  // v62: student photos
    Privacy::install(Db::pdo());       // v62: privacy consents and reviews
    ParentAccess::install(Db::pdo());  // v62: parent links
    FirstSetup::install();             // v63.3: retire the old published starting password
    // v63.20: one text-comparison rule for the database and its tables (never "Illegal mix of collations")
    try { $c = Db::alignCollation(); if ($c['converted'] || $c['database']) error_log('AcadLink: text rule set to ' . Db::COLLATION . ' for ' . ($c['database'] ? 'the database' : '') . ($c['converted'] ? ' tables ' . implode(', ', $c['converted']) : '')); }
    catch (Throwable $e) { error_log('AcadLink collation: ' . $e->getMessage()); }
    if (PHP_SAPI !== 'cli') $_SESSION['sis_schema'] = 34; // v63.20
}
