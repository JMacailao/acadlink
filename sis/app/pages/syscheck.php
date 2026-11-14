<?php
declare(strict_types=1);
/** v63.16 System Check: is this server ready and set up safely? Only power.admin (it shows server details). */
if (!Backup::allowed()) throw new Forbidden('Only ' . Backup::OWNER . ' can open the System Check.');
$rows = SystemCheck::run();
$_SESSION['syscheck'] = ['at' => time(), 'n' => count(array_filter($rows, fn($r) => $r[2] === 'fail'))];
$count = array_count_values(array_column($rows, 2));
$icon = ['ok' => ['check-circle-fill', 'text-success', 'OK'], 'warn' => ['exclamation-triangle-fill', 'text-warning', 'Look at'], 'fail' => ['x-circle-fill', 'text-danger', 'Problem'], 'info' => ['info-circle', 'text-secondary', 'Note']];
layout_start('System Check');
page_title('System Check', '<a class="btn btn-outline-primary" href="' . h(url('syscheck')) . '"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Check again</a>', 'Is this server ready, and is AcadLink set up safely? Open it after every upload.');
?>
<div class="syscheck-summary mb-3" role="status">
  <span class="sc-pill sc-fail"><i class="bi bi-x-circle-fill" aria-hidden="true"></i><?= (int) ($count['fail'] ?? 0) ?> problem<?= ($count['fail'] ?? 0) === 1 ? '' : 's' ?></span>
  <span class="sc-pill sc-warn"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><?= (int) ($count['warn'] ?? 0) ?> to look at</span>
  <span class="sc-pill sc-ok"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><?= (int) ($count['ok'] ?? 0) ?> OK</span>
  <span class="text-muted small ms-auto">Checked <?= h(date('M j, Y g:i A')) ?></span>
</div>
<?php foreach (SystemCheck::GROUPS as $g => $title): $list = array_values(array_filter($rows, fn($r) => $r[0] === $g)); if (!$list) continue; ?>
<section class="form-section">
  <h2 class="h6 mb-2"><?= h($title) ?></h2>
  <ul class="sc-list">
  <?php foreach ($list as [, $label, $status, $found, $fix]): [$ic, $cls, $word] = $icon[$status]; ?>
    <li class="sc-row sc-<?= h($status) ?>">
      <i class="bi bi-<?= $ic ?> <?= $cls ?>" aria-hidden="true"></i>
      <div class="min-w-0"><div><b><?= h($label) ?></b> <span class="visually-hidden"><?= h($word) ?>:</span><span class="text-muted">· <?= h($found) ?></span></div>
        <?php if ($fix !== '' && $status !== 'ok'): ?><div class="small sc-fix"><i class="bi bi-arrow-return-right me-1" aria-hidden="true"></i><?= h($fix) ?></div><?php endif; ?></div>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<section class="form-section">
  <details>
    <summary class="fw-semibold">Uploading a new version: checklist</summary>
    <ol class="small mt-2 mb-2">
      <li><b>Back up first:</b> Settings → Backup & Restore → Back up everything, then <b>Download</b> it to this computer.</li>
      <li>On the host, turn <b>SSL</b> on for the domain and choose <b>PHP 8.3</b> (Hostinger: hPanel → Security → SSL; Advanced → PHP Configuration).</li>
      <li>Upload the files at a quiet time. Keep <code>api/config.php</code> (it holds the database password). Turn on <b>show hidden files</b> so every <code>.htaccess</code> is uploaded, and include the <code>vendor</code> folder.</li>
      <li>Open the site with <b>https://</b> and press <b>Ctrl+F5</b>, then sign in as power.admin.</li>
      <li>Open this page: fix every <b>Problem</b>; look at each <b>Look at</b>.</li>
      <li>Quick tour: SIS dashboard, a student, the Cashier, the Student Portal (a student account), the Grading System, LMS, Canteen POS and Library.</li>
      <li>Settings → Backup & Restore → <b>Make the automatic backup now</b>, and set up the hourly scheduled job shown there.</li>
      <li>If something is wrong: upload the previous version again; your records are untouched (restore the backup from step 1 only if records were changed).</li>
    </ol>
    <p class="small text-muted mb-0">How it fits together: the SIS, Student Portal, LMS, Canteen POS, Library and Finance are PHP pages (<code>sis/</code>, <code>portal/</code>, …); the Grading System is a separate browser app (<code>pages/</code>, <code>js/</code>) that talks to <code>api/</code>. Both use the same database and the same sign-in, so upload them together.</p>
  </details>
</section>
<style>
.syscheck-summary { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.sc-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-weight: 600; font-size: .9rem; background: #f1f4f2; }
.sc-pill.sc-fail { color: #b42318; background: #fdecea; } .sc-pill.sc-warn { color: #8a5a00; background: #fff4db; } .sc-pill.sc-ok { color: #0f5132; background: #dff1e6; }
.sc-list { list-style: none; padding: 0; margin: 0; }
.sc-row { display: flex; gap: 10px; align-items: flex-start; padding: 9px 4px; border-top: 1px solid #eef2f0; }
.sc-row:first-child { border-top: 0; }
.sc-row > i { font-size: 1.1rem; margin-top: 1px; }
.sc-fix { color: #5b4400; margin-top: 2px; } .sc-fail .sc-fix { color: #8b1a12; }
</style>
<?php layout_end();
