<?php
declare(strict_types=1);

/**
 * v51: email on accounts, sending email through the school's EmailJS setup (from the server),
 * and Forgot password with a one-time temporary password.
 */

/** One email per account (optional; required for Power Users). */
final class AccountEmail
{
    public static function install(): void
    {
        Db::q('CREATE TABLE IF NOT EXISTS central_account_emails (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            email VARCHAR(191) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_central_account_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        Db::q('CREATE TABLE IF NOT EXISTS central_password_resets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NULL,
            temp_hash VARCHAR(255) NULL,
            sent TINYINT(1) NOT NULL DEFAULT 0,
            ip VARCHAR(64) NOT NULL,
            expires_at DATETIME NULL, used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY k_reset_account (account_id, created_at), KEY k_reset_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public static function get(int $accountId): string
    {
        return (string) (Db::val('SELECT email FROM central_account_emails WHERE account_id = ?', [$accountId]) ?: '');
    }

    /** Where a password email goes: the account's email, or for a student the email on their student record. */
    public static function forReset(int $accountId): string
    {
        $mine = self::get($accountId);
        if ($mine !== '') return $mine;
        $a = Auth::central()->account($accountId);
        if ($a && $a['account_kind'] === 'STUDENT' && !empty($a['student_doc_id']) && !empty($a['person_verified'])) {
            $s = GS::get('students', (string) $a['student_doc_id']);
            $e = strtolower(trim((string) ($s['email'] ?? '')));
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
        }
        return '';
    }

    public static function isPowerUser(int $accountId): bool { return Auth::central()->group($accountId) === 'POWER_USER'; }

    /** Saves (or clears) an account's email. $actor: who is saving (logged). */
    public static function set(int $accountId, string $email, ?int $actor): void
    {
        $email = strtolower(trim($email));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191)) throw new AppError('Enter a valid email address, e.g. name@gmail.com.');
        if ($email === '' && self::isPowerUser($accountId)) throw new AppError('A Power User needs an email, so a forgotten password can be reset.');
        if ($email !== '' && Db::val('SELECT account_id FROM central_account_emails WHERE email = ? AND account_id <> ?', [$email, $accountId])) throw new AppError('This email is already used by another account.');
        $old = self::get($accountId);
        if ($old === $email) return;
        if ($email === '') Db::q('DELETE FROM central_account_emails WHERE account_id = ?', [$accountId]);
        else Db::q('INSERT INTO central_account_emails (account_id, email, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE email = VALUES(email), updated_by = VALUES(updated_by)', [$accountId, $email, $actor]);
        Auth::central()->audit($actor, $accountId, 'account_email_changed', ['from' => self::mask($old), 'to' => self::mask($email)]);
    }

    /** "juan@gmail.com" → "j•••@gmail.com" */
    public static function mask(string $email): string
    {
        if (!str_contains($email, '@')) return $email === '' ? '' : '•••';
        [$u, $d] = explode('@', $email, 2);
        return mb_substr($u, 0, 1) . '•••@' . $d;
    }
}

/** Email through the school's EmailJS account (Grading System → School settings → Email sending), sent by the server. */
final class Mailer
{
    private const ENDPOINT = 'https://api.emailjs.com/api/v1.0/email/send';
    private const SECRET = 'emailjs_private_key'; // sis_settings key; the value is encrypted

    /** The keys saved in the Grading System: publicKey, serviceId, templateId. */
    public static function keys(): array
    {
        $raw = Db::val("SELECT data FROM docs WHERE col = 'settings' AND id = 'email'");
        $d = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        return ['publicKey' => trim((string) ($d['publicKey'] ?? '')), 'serviceId' => trim((string) ($d['serviceId'] ?? '')), 'templateId' => trim((string) ($d['templateId'] ?? ''))];
    }

    private static function cryptKey(): string
    {
        $c = sis_config();
        return hash('sha256', 'acadlink-mailer|' . ($c['app_key'] ?? '') . '|' . $c['db_name'] . '|' . $c['db_user'] . '|' . $c['db_pass'], true);
    }

    public static function hasPrivateKey(): bool { return self::privateKey() !== ''; }

    private static function privateKey(): string
    {
        $v = (string) (Db::val('SELECT svalue FROM sis_settings WHERE skey = ?', [self::SECRET]) ?: '');
        if ($v === '' || !function_exists('openssl_decrypt')) return '';
        $bin = base64_decode($v, true);
        if ($bin === false || strlen($bin) < 29) return '';
        $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::cryptKey(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $plain === false ? '' : $plain;
    }

    /** Saves the EmailJS Private Key encrypted (never shown again). '' removes it. */
    public static function savePrivateKey(string $key): void
    {
        Auth::require('settings.manage');
        $key = trim($key);
        if ($key === '') { Db::q('DELETE FROM sis_settings WHERE skey = ?', [self::SECRET]); Audit::log('email_private_key_removed', 'settings', 0); return; }
        if (!preg_match('/^[A-Za-z0-9_\-]{8,120}$/', $key)) throw new AppError('That does not look like an EmailJS Private Key (letters, numbers, - and _).');
        if (!function_exists('openssl_encrypt')) throw new AppError('This server cannot encrypt the key (PHP OpenSSL is missing).');
        $iv = random_bytes(12); $tag = '';
        $enc = openssl_encrypt($key, 'aes-256-gcm', self::cryptKey(), OPENSSL_RAW_DATA, $iv, $tag);
        Db::q('INSERT INTO sis_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [self::SECRET, base64_encode($iv . $tag . $enc)]);
        Audit::log('email_private_key_saved', 'settings', 0);
    }

    /** What is missing before the server can send ([] = ready). */
    public static function missing(): array
    {
        $k = self::keys(); $m = [];
        if ($k['publicKey'] === '' || $k['serviceId'] === '' || $k['templateId'] === '') $m[] = 'EmailJS keys in the Grading System (School settings → Email sending)';
        if (!self::hasPrivateKey()) $m[] = 'EmailJS Private Key (SIS → Email sending)';
        return $m;
    }

    /** Sends one email with the school's EmailJS template. Throws AppError with a readable reason. */
    public static function send(string $to, string $toName, string $subject, string $html): void
    {
        if ($m = self::missing()) throw new AppError('Email sending is not set up: ' . implode('; ', $m) . '.');
        $k = self::keys();
        $body = json_encode(['service_id' => $k['serviceId'], 'template_id' => $k['templateId'], 'user_id' => $k['publicKey'], 'accessToken' => self::privateKey(),
            'template_params' => ['to_email' => $to, 'cc_email' => '', 'to_name' => $toName, 'from_name' => School::brand(), 'subject' => $subject, 'message_html' => $html]], JSON_THROW_ON_ERROR);
        $url = (string) (sis_config()['emailjs_endpoint'] ?? self::ENDPOINT); // tests point this at a local stand-in
        [$status, $reply] = self::post($url, $body);
        if ($status !== 200) throw new AppError('EmailJS refused the email (' . ($status ?: 'no connection') . '): ' . mb_substr(trim(strip_tags($reply)), 0, 200));
    }

    private static function post(string $url, string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')]]);
            $reply = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            return [$status, $reply === false ? $err : (string) $reply];
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 20, 'ignore_errors' => true]]);
        $reply = @file_get_contents($url, false, $ctx);
        $status = preg_match('#^HTTP/\S+ (\d{3})#', (string) ($http_response_header[0] ?? ''), $m) ? (int) $m[1] : 0;
        return [$status, (string) $reply];
    }
}

/** Forgot password: a one-time temporary password by email. The old password keeps working until it is used. */
final class PasswordReset
{
    public const MINUTES = 60;
    public const NO_EMAIL = "There is no email on your account. Ask the administrator to reset your password.";

    /** The account for a username or Student ID, or null. */
    public static function find(string $login): ?int
    {
        $login = strtolower(trim($login));
        if ($login === '' || mb_strlen($login) > 191) return null;
        $id = Db::val('SELECT account_id FROM central_login_identifiers WHERE identifier_key = ? AND verified_at IS NOT NULL', [$login])
            ?: Db::val('SELECT account_id FROM central_person_links WHERE LOWER(student_number) = ? AND verified_at IS NOT NULL', [$login]);
        return $id ? (int) $id : null;
    }

    /** Handles the Forgot password form: ['ok' => bool, 'message' => text shown to the person]. */
    public static function request(string $login): array
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 64);
        Db::q('DELETE FROM central_password_resets WHERE created_at < NOW() - INTERVAL 7 DAY AND used_at IS NULL');
        if ((int) Db::val('SELECT COUNT(*) FROM central_password_resets WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ip]) >= 10)
            return ['ok' => false, 'message' => 'Too many requests from this device. Try again in an hour.'];
        $id = self::find($login);
        $a = $id ? Auth::central()->account($id) : null;
        $email = $a && $a['status'] === 'ACTIVE' ? AccountEmail::forReset($id) : '';
        if ($id && (int) Db::val('SELECT COUNT(*) FROM central_password_resets WHERE account_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$id]) >= 3) {
            Db::insert('central_password_resets', ['account_id' => $id, 'ip' => $ip, 'sent' => 0]);
            return ['ok' => false, 'message' => 'A temporary password was already requested several times for this account. Check your email, or try again in an hour.'];
        }
        if ($email === '') {
            // Same answer for "not found", "no email" and unavailable accounts
            Db::insert('central_password_resets', ['account_id' => $id, 'ip' => $ip, 'sent' => 0]);
            if ($id) Auth::central()->audit(null, $id, 'password_reset_no_email', ['ip' => $ip]);
            return ['ok' => false, 'message' => self::NO_EMAIL];
        }
        $temp = Auth::suggestPassword();
        $expires = date('Y-m-d H:i:s', time() + self::MINUTES * 60);
        $html = '<p>Hello ' . h((string) $a['display_name']) . ',</p><p>Someone asked to reset the password of your ' . h(School::brand()) . ' account (<strong>' . h((string) $a['username']) . '</strong>).</p>'
            . '<p>Your temporary password is:</p><p style="font-size:20px;font-weight:bold;letter-spacing:1px">' . h($temp) . '</p>'
            . '<p>It works once, until ' . h(date('F j, Y g:i A', strtotime($expires))) . '. After signing in you will choose a new password.</p>'
            . '<p>If you did not ask for this, ignore this email: your current password still works.</p>';
        try {
            Mailer::send($email, (string) $a['display_name'], 'Your temporary password', $html);
        } catch (AppError $e) {
            Db::insert('central_password_resets', ['account_id' => $id, 'ip' => $ip, 'sent' => 0]);
            Auth::central()->audit(null, $id, 'password_reset_email_failed', ['reason' => mb_substr($e->getMessage(), 0, 300)]);
            error_log('Password reset email failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => "We couldn't send the email right now. Please try again later, or ask the administrator to reset your password."];
        }
        Db::insert('central_password_resets', ['account_id' => $id, 'temp_hash' => password_hash($temp, PASSWORD_DEFAULT), 'sent' => 1, 'ip' => $ip, 'expires_at' => $expires]);
        Auth::central()->audit(null, $id, 'password_reset_sent', ['to' => AccountEmail::mask($email)]);
        return ['ok' => true, 'message' => 'A temporary password was sent to ' . AccountEmail::mask($email) . '. It works once, for ' . self::MINUTES . ' minutes. Check your spam folder too.'];
    }
}
