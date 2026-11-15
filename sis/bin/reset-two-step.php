<?php
/**
 * v63.17 Turns off two-step sign-in for one account, when its phone and recovery codes are lost
 * (for power.admin, whom nobody else can reset). Needs access to the server.
 *
 *   php sis/bin/reset-two-step.php power.admin
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
sis_boot();
$user = strtolower(trim((string) ($argv[1] ?? '')));
if ($user === '') { fwrite(STDERR, "Usage: php sis/bin/reset-two-step.php <username>\n"); exit(1); }
$c = Auth::central();
$id = (int) $c->q('SELECT account_id FROM central_login_identifiers WHERE identifier_key = ?', [$user])->fetchColumn();
if (!$id) { fwrite(STDERR, "No account named $user.\n"); exit(1); }
if (!$c->twoStep()->enabled($id)) { echo "Two-step sign-in is already off for $user.\n"; exit(0); }
$c->twoStep()->disable($id);
Audit::log('two_step_reset_on_server', 'account', $id, ['username' => $user]);
echo "Two-step sign-in is off for $user. Sign in with the password, then turn it on again in My profile.\n";
