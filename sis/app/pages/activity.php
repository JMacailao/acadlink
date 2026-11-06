<?php
/** Activity log: who did what in the SIS (newest first). */
declare(strict_types=1);
$page = max(1, (int) ($_GET['page'] ?? 1));
$withSignIns = !empty($_GET['signins']);
$per = 50;
$activity = Activity::recent($per + 1, $withSignIns, ($page - 1) * $per);
$more = count($activity) > $per;
$activity = array_slice($activity, 0, $per);
layout_start('Activity log');
page_title('Activity log', '<a class="btn btn-outline-secondary" href="' . url('dashboard') . '"><i class="bi bi-house-door me-1"></i>Home</a>', 'Every admission, registration, payment and setup change, newest first.');
?>
<div class="home-panel">
  <form method="get" class="d-flex align-items-center gap-2 mb-3"><input type="hidden" name="r" value="activity">
    <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="signins" value="1"<?= $withSignIns ? ' checked' : '' ?> data-autosubmit> Include sign-ins and password changes</label></form>
  <?php require __DIR__ . '/../views/activity_table.php'; ?>
  <div class="d-flex justify-content-between mt-3">
    <?= $page > 1 ? '<a class="btn btn-sm btn-outline-secondary" href="' . url('activity', ['page' => $page - 1] + ($withSignIns ? ['signins' => 1] : [])) . '"><i class="bi bi-chevron-left"></i> Newer</a>' : '<span></span>' ?>
    <?= $more ? '<a class="btn btn-sm btn-outline-secondary" href="' . url('activity', ['page' => $page + 1] + ($withSignIns ? ['signins' => 1] : [])) . '">Older <i class="bi bi-chevron-right"></i></a>' : '' ?>
  </div>
</div>
<?php layout_end();
