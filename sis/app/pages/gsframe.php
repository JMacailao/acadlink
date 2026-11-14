<?php
declare(strict_types=1);
/**
 * v63.9: Grading System settings shown inside SIS → Settings.
 *  - gradingoptions: Setup and options (grading scale, final grade, audit trail, combined classes, term deadlines…)
 *  - systemsettings: sign-in security, teacher downtime, the school's database, email keys and Google Sheets
 *    (School details are edited in School Profile).
 * The Grading System page runs in a frame with its own menus hidden; it saves through the Grading System as before.
 */
global $route;
$pages = [
    'gradingoptions' => ['Grading Options', 'options.html', 'How the school grades students: grading scale, final grade (GWA), audit trail, combined classes, term deadlines and section changes.'],
    'systemsettings' => ['System Settings', 'settings.html', 'Sign-in security, teacher downtime, the school\'s database, email sending keys and Google Sheets.'],
    'gradingaudit' => ['Grading Audit Trail', 'audit.html', 'Who added, edited or deleted grading records (subjects, sections, students, grades…), with the old and new values.'],
    'gradingbackup' => ['Grading Backup', 'backup.html', 'Download a copy of the Grading System\'s records, restore one, and the Google Sheets copy.'],
];
[$title, $file, $about] = $pages[(string) $route] ?? $pages['gradingoptions'];
layout_start($title);
page_title($title, '', $about);
?>
<div class="gs-frame-wrap" data-record-detail>
  <div class="gs-frame-loading" id="gsFrameLoading"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</div>
  <iframe id="gsFrame" class="gs-frame" title="<?= h($title) ?>" src="../pages/<?= h($file) ?>?embed=sis"></iframe>
  <p class="small text-muted mt-2 mb-0">Only Grading System administrators can change these. Changes are saved in the Grading System and listed in its Audit trail.</p>
</div>
<style>
.gs-frame-wrap { position: relative; }
.gs-frame { display: block; width: 100%; min-height: 70vh; border: 0; background: transparent; }
.gs-frame-loading { position: absolute; inset: 0 0 auto 0; padding: 24px; color: #6b7c74; }
</style>
<script src="<?= sis_web() ?>assets/gs-frame.js?v=1" defer></script>
<?php layout_end();
