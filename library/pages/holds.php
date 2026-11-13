<?php
/** Holds: the hold shelf (ready for pickup) and the waiting queue. */
declare(strict_types=1);
/** @var bool $isPost */
if ($isPost) { Library::cancelHold((int) ($_POST['hold'] ?? 0)); flash('success', 'Hold cancelled.'); redirect('holds'); }
$rows = Library::holds();
$ready = array_filter($rows, fn($r) => $r['status'] === 'READY');
$waiting = array_filter($rows, fn($r) => $r['status'] === 'WAITING');
layout_start('Reservations');
lib_page_head('bookmark', 'Reservations', 'Holds placed at the desk or in the Student Portal, and the hold shelf.');
$table = function (array $list, bool $isReady) { ?>
<div class="table-responsive form-section"><table class="table table-sm align-middle mb-0"><thead><tr><th>Borrower</th><th>Title</th><?= $isReady ? '<th>Copy</th><th>Pick up by</th>' : '<th>Placed</th><th>From</th>' ?><th></th></tr></thead><tbody>
<?php foreach ($list as $h): ?><tr><td><a href="<?= url('circulation', ['b' => $h['borrower_type'] . ':' . $h['borrower_ref']]) ?>"><?= h($h['borrower_name']) ?></a> <small class="text-muted"><?= h($h['borrower_no']) ?></small></td><td><?= h($h['title']) ?></td>
  <?php if ($isReady): ?><td class="font-monospace small"><?= h((string) $h['barcode']) ?></td><td><?= h(date('D, M j', strtotime((string) $h['expires_on']))) ?></td><?php else: ?><td class="small"><?= h(date('M j, g:i A', strtotime($h['placed_at']))) ?></td><td class="small"><?= $h['source'] === 'PORTAL' ? 'Student Portal' : 'Desk' ?></td><?php endif; ?>
  <td class="text-end"><?php if (Auth::can('library.circulate')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="hold" value="<?= (int) $h['id'] ?>"><button class="btn btn-sm btn-link text-danger">Cancel</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="5" class="text-center text-muted">None.</td></tr><?php endif; ?>
</tbody></table></div>
<?php };
?>
<h2 class="h6">On the hold shelf (<?= count($ready) ?>)</h2>
<p class="small text-muted">Ready holds that are not picked up by the date go to the next person waiting, or back to the shelf.</p>
<?php $table($ready, true); ?>
<h2 class="h6 mt-3">Waiting (<?= count($waiting) ?>)</h2>
<?php $table($waiting, false);
layout_end();
