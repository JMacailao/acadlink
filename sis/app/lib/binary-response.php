<?php
declare(strict_types=1);

/**
 * v63.21: sending a stored file (product photo, student photo, book cover, LMS file) without slowing the page around it.
 * - begin(): releases the session lock at once, so the 20–1,000 photos of a list download side by side instead of one
 *   after another, and the after-page jobs (notification scan, email queue, automatic backup) are skipped for files.
 * - send(): long browser caching when the address names an unchanging version (a photo id that is never reused, or a
 *   ?v= tag that changes with the picture), and "304 Not Modified" when the browser already has these exact bytes.
 */
final class BinaryResponse
{
    public static function begin(): void
    {
        if (!defined('ACADLINK_BINARY_RESPONSE')) define('ACADLINK_BINARY_RESPONSE', true);
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }

    /** True while a file route runs: after-page jobs leave such requests alone. */
    public static function active(): bool
    {
        return defined('ACADLINK_BINARY_RESPONSE');
    }

    /**
     * @param bool $immutable the address always names these exact bytes (cache for a year)
     * @param array<string,string> $headers extra headers, e.g. Content-Disposition or a stricter Content-Security-Policy
     */
    public static function send(string $mime, string $bytes, bool $immutable, int $maxAge = 86400, array $headers = []): never
    {
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) { header_remove('Expires'); header_remove('Pragma'); header_remove('Set-Cookie'); }
        $etag = '"' . substr(sha1($bytes), 0, 20) . '"';
        header('Cache-Control: private, ' . ($immutable ? 'max-age=31536000, immutable' : 'max-age=' . max(0, $maxAge)));
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $k => $v) header($k . ': ' . $v);
        $sent = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($sent !== '' && in_array($etag, array_map('trim', explode(',', $sent)), true)) { http_response_code(304); exit; }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    /** The ?v= tag of the address is the start of these bytes' SHA-1 (as the lists write it), so the address can be cached for a year. */
    public static function versionMatches(string $bytes): bool
    {
        $v = strtolower((string) ($_GET['v'] ?? ''));
        return strlen($v) >= 8 && ctype_xdigit($v) && str_starts_with(sha1($bytes), $v);
    }
}
