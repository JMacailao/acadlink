<?php
/**
 * v63.17 Two-step sign-in: after the password, a 6-digit code from an authenticator app (TOTP, RFC 6238:
 * SHA-1, 30-second steps, 6 digits; Google Authenticator, Microsoft Authenticator, Authy…), or a one-time recovery code.
 * The secret is kept encrypted (AES-256-GCM); recovery codes are kept as password hashes.
 */
declare(strict_types=1);
final class TwoStepError extends RuntimeException {}
/** Thrown by the sign-in when the password was right and the account uses two-step sign-in: ask for the code. */
final class CentralTwoStepRequired extends RuntimeException {
    public function __construct(public readonly int $accountId) { parent::__construct('Enter the code from your authenticator app.'); }
}

final class TwoStep
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private static bool $installed = false;
    public function __construct(private PDO $db, private string $cryptSeed) {}

    private function q(string $sql, array $args = []): PDOStatement { $s = $this->db->prepare($sql); $s->execute($args); return $s; }

    public function install(): void
    {
        if (self::$installed) return;
        $this->db->exec("CREATE TABLE IF NOT EXISTS central_two_step (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            secret_enc VARCHAR(255) NOT NULL,
            enabled_at DATETIME NULL,
            last_step BIGINT NOT NULL DEFAULT 0,
            recovery_json TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$installed = true;
    }

    // ------------------------------------------------------------------ helpers
    public static function base32(string $bin): string
    {
        $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = ''; foreach (str_split($bits, 5) as $chunk) $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }
    public static function unbase32(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s)); $bits = '';
        foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        $out = ''; foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }
    /** The 6-digit code for a secret at a 30-second step (RFC 6238 / RFC 4226). */
    public static function code(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('N2', ($step >> 32) & 0xffffffff, $step & 0xffffffff), $secret, true);
        $o = ord($h[19]) & 0x0f;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }
    private function key(): string { return hash('sha256', 'acadlink-two-step|' . $this->cryptSeed, true); }
    private function seal(string $plain): string
    {
        if (!function_exists('openssl_encrypt')) throw new TwoStepError('This server cannot encrypt (PHP OpenSSL is missing).');
        $iv = random_bytes(12); $tag = '';
        $enc = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($enc === false || strlen($tag) !== 16) throw new TwoStepError('Could not encrypt the secret.');
        return base64_encode($iv . $tag . $enc); // 12-byte IV, 16-byte tag, then the secret
    }
    private function open(string $sealed): string
    {
        $bin = base64_decode($sealed, true);
        if ($bin === false || strlen($bin) < 29) return '';
        $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $plain === false ? '' : $plain;
    }
    private function row(int $id): ?array { $this->install(); return $this->q('SELECT * FROM central_two_step WHERE account_id = ?', [$id])->fetch(PDO::FETCH_ASSOC) ?: null; }

    // ------------------------------------------------------------------ status
    public function enabled(int $id): bool { $r = $this->row($id); return $r !== null && $r['enabled_at'] !== null; }
    public function recoveryLeft(int $id): int { $r = $this->row($id); return $r ? count(json_decode((string) $r['recovery_json'], true) ?: []) : 0; }

    // ------------------------------------------------------------------ turning it on
    /** Starts setting up (or restarts): a new secret, not yet active. Returns [base32 secret, otpauth URI]. */
    public function begin(int $id, string $label, string $issuer): array
    {
        if ($this->enabled($id)) throw new TwoStepError('Two-step sign-in is already on.');
        $secret = random_bytes(20);
        $this->q('INSERT INTO central_two_step (account_id, secret_enc, enabled_at, last_step, recovery_json) VALUES (?, ?, NULL, 0, ?) ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc), enabled_at = NULL, last_step = 0, recovery_json = VALUES(recovery_json)',
            [$id, $this->seal($secret), '[]']);
        $b32 = self::base32($secret);
        $uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $label) . '?secret=' . $b32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
        return [$b32, $uri];
    }
    /** The secret being set up (to show again on the page), or null. */
    public function pending(int $id, string $label, string $issuer): ?array
    {
        $r = $this->row($id);
        if (!$r || $r['enabled_at'] !== null) return null;
        $s = $this->open((string) $r['secret_enc']); if ($s === '') return null;
        $b32 = self::base32($s);
        return [$b32, 'otpauth://totp/' . rawurlencode($issuer . ':' . $label) . '?secret=' . $b32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30'];
    }
    /** Confirms the first code: two-step sign-in is on. Returns the 10 recovery codes (shown once). */
    public function confirm(int $id, string $code): array
    {
        $r = $this->row($id);
        if (!$r || $r['enabled_at'] !== null) throw new TwoStepError('Start again: choose "Turn on two-step sign-in".');
        if (!$this->checkTotp($id, (string) $r['secret_enc'], (int) $r['last_step'], $code)) throw new TwoStepError('That code is not right. Check the time on your phone, and type the 6 digits shown now.');
        $codes = $this->newRecovery($id);
        $this->q('UPDATE central_two_step SET enabled_at = NOW() WHERE account_id = ?', [$id]);
        return $codes;
    }
    /** New recovery codes (the old ones stop working). */
    public function newRecovery(int $id): array
    {
        $codes = []; $hashes = [];
        for ($i = 0; $i < 10; $i++) { $c = strtolower(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2))); $codes[] = $c; $hashes[] = password_hash($c, PASSWORD_DEFAULT); }
        $this->q('UPDATE central_two_step SET recovery_json = ? WHERE account_id = ?', [json_encode($hashes), $id]);
        return $codes;
    }
    public function disable(int $id): void { $this->install(); $this->q('DELETE FROM central_two_step WHERE account_id = ?', [$id]); }

    // ------------------------------------------------------------------ signing in
    /** A 6-digit code from the app (this step or one either side, each used once) or a recovery code (used once). */
    public function verify(int $id, string $input): bool
    {
        $r = $this->row($id);
        if (!$r || $r['enabled_at'] === null) return false;
        $input = strtolower(trim($input));
        if (preg_match('/^[0-9a-f]{4}-?[0-9a-f]{4}$/', $input) && !preg_match('/^\d{6}$/', $input)) {
            $c = strlen($input) === 8 ? substr($input, 0, 4) . '-' . substr($input, 4) : $input;
            $hashes = json_decode((string) $r['recovery_json'], true) ?: [];
            foreach ($hashes as $i => $h) if (password_verify($c, $h)) {
                unset($hashes[$i]);
                $this->q('UPDATE central_two_step SET recovery_json = ? WHERE account_id = ?', [json_encode(array_values($hashes)), $id]);
                return true;
            }
            return false;
        }
        return $this->checkTotp($id, (string) $r['secret_enc'], (int) $r['last_step'], $input);
    }
    private function checkTotp(int $id, string $sealed, int $lastStep, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $secret = $this->open($sealed); if ($secret === '') return false;
        $now = intdiv(time(), 30);
        foreach ([0, -1, 1] as $d) {
            $step = $now + $d;
            if ($step <= $lastStep) continue; // each code works once
            if (hash_equals(self::code($secret, $step), $code)) { $this->q('UPDATE central_two_step SET last_step = ? WHERE account_id = ?', [$step, $id]); return true; }
        }
        return false;
    }
}
