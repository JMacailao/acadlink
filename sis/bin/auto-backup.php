<?php
/**
 * v63.14 Automatic backup, for a scheduled job (cron). Makes a full backup when one is due (Settings → Backup &
 * Restore → Automatic backups) and keeps the newest ones. Safe to run every hour: it does nothing when not due.
 *
 *   php /path/to/acadlink/sis/bin/auto-backup.php          when due
 *   php /path/to/acadlink/sis/bin/auto-backup.php --now    right away (also when off or not due)
 *
 * Hostinger: hPanel → Advanced → Cron Jobs → Custom: the command above, e.g. every hour (minute 17).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
sis_boot();
$now = in_array('--now', array_slice($argv, 1), true);
try {
    $name = Backup::runAuto('cron', $now);
    echo $name !== null ? 'Backup made: ' . $name . "\n" : 'No backup due (' . (Backup::AUTO_EVERY[Backup::auto()['autobackup_every']] ?? 'off') . "); nothing to do.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Automatic backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
