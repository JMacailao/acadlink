<?php
declare(strict_types=1);

/**
 * v63.3 First-time setup of power.admin. A new installation no longer has a published starting password: power.admin
 * has no usable password until the installer sets one on SIS → First-time setup. To prove they installed the site,
 * the installer types the database password from api/config.php (which visitors can't read). If the database has no
 * password (a local XAMPP/Laragon install), setup works only from the computer itself (localhost).
 *
 * Existing installations whose power.admin still has the old published starting password are switched to this setup
 * automatically, so nobody can sign in with that password.
 */
final class FirstSetup
{
    public const UNSET = '!setup';
    private const OLD_DEFAULT = 'Start-32f32c74-A1';

    private static function ownerId(): ?int
    {
        $id = Db::val("SELECT account_id FROM central_login_identifiers WHERE identifier_key = 'power.admin' AND is_primary = 1");
        return $id ? (int) $id : null;
    }

    /** Called on upgrade: retire the old published starting password. */
    public static function install(): void
    {
        if (!Db::val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('central_login_identifiers','central_credentials')")) return; // accounts not installed yet
        $id = self::ownerId();
        if (!$id) return;
        $hash = (string) Db::val('SELECT password_hash FROM central_credentials WHERE account_id = ?', [$id]);
        if ($hash !== self::UNSET && $hash !== '' && password_verify(self::OLD_DEFAULT, $hash)) {
            Db::q('UPDATE central_credentials SET password_hash = ? WHERE account_id = ?', [self::UNSET, $id]);
            Db::q("UPDATE sis_users u JOIN central_legacy_links l ON l.source_system = 'SIS' AND l.source_key = CAST(u.id AS CHAR) AND l.account_id = ? SET u.password_hash = ?", [$id, self::UNSET]);
            Audit::log('first_setup_required', 'account', $id, ['reason' => 'the published starting password was still in use']);
        }
    }

    /** True while power.admin has no password yet. */
    public static function needed(): bool
    {
        $id = self::ownerId();
        return $id && Db::val('SELECT password_hash FROM central_credentials WHERE account_id = ?', [$id]) === self::UNSET;
    }

    /** How the installer proves who they are: 'dbpass' (type the database password) or 'local' (only on this computer). */
    public static function mode(): string
    {
        return (string) (sis_config()['db_pass'] ?? '') !== '' ? 'dbpass' : 'local';
    }

    public static function fromThisComputer(): bool
    {
        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true) && empty($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    /** Set power.admin's first password. Throws AppError with a clear reason. */
    public static function complete(string $proof, string $password, string $confirm): void
    {
        if (!self::needed()) throw new AppError('Setup is already done. Sign in as power.admin.');
        $ip = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 64);
        if ((int) Db::val("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = 'first-setup' AND at > NOW() - INTERVAL 15 MINUTE", [$ip]) >= 5)
            throw new AppError('Too many tries. Wait 15 minutes, then try again.');
        $ok = self::mode() === 'dbpass' ? hash_equals((string) sis_config()['db_pass'], $proof) : self::fromThisComputer();
        if (!$ok) {
            Db::q("INSERT INTO login_attempts (ip, email) VALUES (?, 'first-setup')", [$ip]);
            throw new AppError(self::mode() === 'dbpass' ? 'That is not the database password in api/config.php.' : 'Open this page on the computer where AcadLink is installed (http://localhost/…).');
        }
        if ($password !== $confirm) throw new AppError('The two passwords are not the same.');
        if ($p = CentralAccounts::passwordProblem($password)) throw new AppError($p);
        if (strcasecmp($password, self::OLD_DEFAULT) === 0 || stripos($password, 'power.admin') !== false) throw new AppError('Choose a password of your own (not the old starting password or the username).');
        $id = (int) self::ownerId();
        Db::tx(function () use ($id, $password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            Db::q('UPDATE central_credentials SET password_hash = ?, password_changed_at = NOW() WHERE account_id = ? AND password_hash = ?', [$hash, $id, self::UNSET]);
            Db::q("UPDATE central_accounts SET must_change_password = 0, status = 'ACTIVE', failed_logins = 0, locked_until = NULL WHERE id = ?", [$id]);
            Db::q("UPDATE sis_users u JOIN central_legacy_links l ON l.source_system = 'SIS' AND l.source_key = CAST(u.id AS CHAR) AND l.account_id = ? SET u.password_hash = ?, u.must_change_password = 0", [$id, $hash]);
        });
        Audit::log('first_setup_done', 'account', $id, ['by' => self::mode()]);
    }
}
