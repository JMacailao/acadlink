<?php
/** Activity rows (from Activity::recent) as a table. Expects $activity. */
?>
<div class="table-responsive"><table class="table activity-table align-middle mb-0">
  <thead><tr><th>Date &amp; Time</th><th>Action</th><th>Name / Details</th><th>User</th></tr></thead><tbody>
  <?php foreach ($activity as $x): ?><tr>
    <td class="text-nowrap text-muted"><?= h(date('M j, Y g:i A', strtotime($x['at']))) ?></td>
    <td><?= h($x['action']) ?></td>
    <td><?= $x['link'] ? '<a class="text-decoration-none" href="' . h($x['link']) . '">' . h($x['name'] ?: '—') . '</a>' : h($x['name'] ?: '—') ?><?= $x['detail'] !== '' ? '<span class="sub">' . h($x['detail']) . '</span>' : '' ?></td>
    <td class="text-nowrap"><?= h($x['user']) ?> <?php if ($x['role']): ?><span class="role-chip role-<?= h($x['role']) ?>"><?= h(Auth::ROLES[$x['role']] ?? $x['role']) ?></span><?php endif; ?></td>
  </tr><?php endforeach; if (!$activity): ?><tr><td colspan="4" class="text-center text-muted py-4">No activity yet.</td></tr><?php endif; ?>
</tbody></table></div>
