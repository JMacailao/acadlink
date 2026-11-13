<?php
/**
 * Canteen POS (v58): a separate system with the same sign-in and access rules.
 * It is being built; for now every account that can open it sees "Coming soon."
 */
declare(strict_types=1);

const LOCAL_ROUTES = []; // links (sign out, systems) go to ../sis/
require __DIR__ . '/../sis/app/bootstrap.php';
sis_boot();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");
header('Cache-Control: no-store');

$a = Auth::central()->current();
if (!$a || !Auth::user()) { header('Location: ../sis/index.php?r=login'); exit; }
$allowed = Auth::central()->can((int) $a['id'], 'canteen.workspace.view');
if (!$allowed) http_response_code(403); // 200 while being built: some hosts replace 5xx pages with their own
$logo = School::logo();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Canteen POS · <?= h(School::brand()) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="../icons/favicon-32.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" crossorigin="anonymous">
<style>
  :root { --ink: #1c2434; --muted: #5b6577; --line: #e3e7ee; --card: #fff; --bg: #f4f6fa; --accent: #c2410c; --accent-soft: #fff1e6; }
  @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --ink: #eef1f6; --muted: #aab3c2; --line: #2e3646; --card: #1a202b; --bg: #11151c; --accent-soft: #3a1f10; } }
  :root[data-theme="dark"] { --ink: #eef1f6; --muted: #aab3c2; --line: #2e3646; --card: #1a202b; --bg: #11151c; --accent-soft: #3a1f10; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  .box { width: 100%; max-width: 520px; background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 32px 28px; text-align: center; box-shadow: 0 10px 40px #0f172a14; }
  .school { display: flex; align-items: center; justify-content: center; gap: 10px; font-weight: 700; margin-bottom: 22px; }
  .school img { width: 40px; height: 40px; border-radius: 50%; object-fit: contain; background: #fff; }
  .icon { width: 76px; height: 76px; border-radius: 50%; background: var(--accent-soft); color: var(--accent); display: grid; place-items: center; margin: 0 auto 16px; font-size: 36px; }
  h1 { font-size: 1.35rem; margin: 0 0 4px; }
  .status { font-size: 1.15rem; font-weight: 700; color: var(--accent); margin: 0 0 8px; }
  p { color: var(--muted); margin: 0 0 22px; }
  .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 10px; font: 600 15px system-ui, sans-serif; text-decoration: none; cursor: pointer; border: 1px solid var(--line); background: var(--card); color: var(--ink); }
  .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .btn:focus-visible { outline: 3px solid #fb923c; outline-offset: 2px; }
  form { margin: 0; }
</style>
</head>
<body>
<main class="box">
  <div class="school"><img src="<?= h($logo ?: '../icons/acadlink-mark.png') ?>" alt=""><span><?= h(School::brand()) ?></span></div>
  <?php if ($allowed): ?>
  <div class="icon" aria-hidden="true"><i class="bi bi-shop"></i></div>
  <h1>Canteen POS</h1>
  <p class="status">Coming soon.</p>
  <p>This system is planned and will be available soon.</p>
  <?php else: ?>
  <div class="icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
  <h1>Canteen POS</h1>
  <p>Your account doesn't have access to the Canteen POS. Ask the Power User if you need it.</p>
  <?php endif; ?>
  <div class="actions">
    <a class="btn primary" href="../sis/index.php?r=workspaces"><i class="bi bi-grid" aria-hidden="true"></i>Back to my systems</a>
    <form method="post" action="../sis/index.php?r=logout"><?= csrf_field() ?><button class="btn"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Sign out</button></form>
  </div>
</main>
</body>
</html>
