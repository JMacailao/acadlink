<?php
/**
 * CLI installer:  php bin/install.php [--fresh] [--sample]
 *   Installs the SIS tables (sis_*) into the grading system's database (api/config.php).
 *   --fresh   drop and recreate the SIS tables (destroys SIS data; grading records are kept)
 *   --sample  add demo data: grading sections/subjects/teachers/curriculum/students + SIS fees,
 *             an admission, registrations and payments (for testing only)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
sis_boot();
$args = array_slice($argv, 1);
$pdo = Db::pdo();
if (!GS::available()) { fwrite(STDERR, "The grading system's tables (docs) are not in this database. Run api/install.sql first.\n"); exit(1); }

function sis_run_sql(PDO $pdo, string $file): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) $pdo->exec($stmt);
}
function sis_tables(): array
{
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?(\w+)/', (string) file_get_contents(SIS_ROOT . '/database/schema.sql'), $m);
    return $m[1];
}

if (in_array('--fresh', $args, true)) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (sis_tables() as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "Dropped the SIS tables (grading records kept).\n";
}
sis_run_sql($pdo, SIS_ROOT . '/database/schema.sql');
sis_run_sql($pdo, SIS_ROOT . '/database/seed.sql');
sis_run_sql($pdo, SIS_ROOT . '/database/seed_accounting.sql');
echo "SIS tables ready. Follow CENTRAL_ACCOUNTS.md to install central accounts and bootstrap the Power User.\n";
if (in_array('--sample', $args, true)) {
    require __DIR__ . '/sample_data.php';
    try { sis_sample_data(); } catch (AppError $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
    echo "Sample data added (password for sample SIS accounts: Password123).\n";
}
