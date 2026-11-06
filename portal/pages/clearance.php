<?php
declare(strict_types=1);
/** Student Portal → My clearance (v55): every office and teacher, what to settle, who signed and when; proof can be sent online. */
/** @var bool $isPost */
if (!Clearance::on()) {
    layout_start('My clearance');
    page_title('My clearance');
    echo '<div class="form-section"><p class="mb-0 text-muted">Online clearance is not used at this time.</p></div>';
    layout_end();
    return;
}
$regs = Clearance::ownRegistrations();
if ($isPost) {
    Clearance::submit((int) ($_POST['reg'] ?? 0), (string) ($_POST['key'] ?? ''), (string) ($_POST['message'] ?? ''), $_FILES['proof'] ?? null);
    flash('success', 'Sent. The office or teacher will check it and sign your clearance or reply.');
    header('Location: ' . url('clearance', ['reg' => (int) ($_POST['reg'] ?? 0)])); exit;
}
$pick = (int) ($_GET['reg'] ?? ($regs[0]['id'] ?? 0));
$r = null; foreach ($regs as $x) if ((int) $x['id'] === $pick) $r = $x;
$st = $r ? Clearance::status($r) : null;
$subs = [];
if ($r) foreach (Clearance::submissions((int) $r['id']) as $s) $subs[$s['item_key']][] = $s;
$notices = Clearance::notices((int) Auth::id(), 5);
$badge = fn(string $s) => ['CLEARED' => '<span class="badge text-bg-success">Cleared</span>', 'NOT_CLEARED' => '<span class="badge text-bg-danger">Not cleared</span>', 'PENDING' => '<span class="badge text-bg-warning">Waiting</span>'][$s] ?? '';
layout_start('My clearance');
page_title('My clearance', $r ? '<a class="btn btn-outline-primary" href="' . url('clearanceslip', ['reg' => $r['id']]) . '" target="_blank"><i class="bi bi-printer"></i> Clearance slip</a>' : '', 'Each office and teacher signs your clearance. If something is missing, settle it and send your proof here.');
?>
<?php if (!$regs): ?><div class="form-section"><p class="mb-0 text-muted">Your clearance appears here once the registrar opens clearance for a term you are enrolled in.</p></div>
<?php else: ?>
<?php if (count($regs) > 1): ?><form method="get" class="form-section d-flex flex-wrap gap-2 align-items-end"><input type="hidden" name="r" value="clearance">
  <div><label class="form-label small mb-0" for="myClr">Term</label><select class="form-select" id="myClr" name="reg"><?php foreach ($regs as $x): ?><option value="<?= (int) $x['id'] ?>"<?= (int) $x['id'] === $pick ? ' selected' : '' ?>><?= h($x['school_year'] . ' · ' . $x['term']) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-outline-primary">Show</button></form><?php endif; ?>
<?php if ($st): $n = count($st['items']); ?>
<section class="form-section">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h2 class="h6 mb-0 me-auto"><?= h($r['school_year'] . ' · ' . $r['term']) ?></h2>
    <?= $st['final'] ? '<span class="badge text-bg-success fs-6"><i class="bi bi-patch-check-fill"></i> Clearance approved · ' . h((string) $st['final']['control_no']) . '</span>' : ($st['complete'] ? '<span class="badge text-bg-success fs-6"><i class="bi bi-check2-circle"></i> Clearance completed</span>' : '<span class="badge text-bg-warning fs-6">' . $st['counts']['CLEARED'] . ' of ' . $n . ' cleared</span>') ?></div>
  <div class="progress mb-3" role="progressbar" aria-label="Cleared" aria-valuenow="<?= $st['counts']['CLEARED'] ?>" aria-valuemin="0" aria-valuemax="<?= $n ?>"><div class="progress-bar bg-success" style="width:<?= $n ? round(100 * $st['counts']['CLEARED'] / $n) : 0 ?>%"></div></div>
  <?php if ($st['period'] && !Clearance::isOpen($st['period'])): ?><p class="small text-muted">Signing is open <?= h(date('M j', strtotime($st['period']['opens_on'])) . '–' . date('M j, Y', strtotime($st['period']['closes_on']))) ?>.</p><?php endif; ?>
  <div class="list-group">
  <?php foreach ($st['items'] as $it): $t = $it['tag']; ?>
    <div class="list-group-item">
      <div class="d-flex flex-wrap gap-2 align-items-start"><div class="me-auto"><strong><?= h($it['label']) ?></strong><?php if ($it['kind'] === 'SUBJECT'): ?><div class="small text-muted"><?= h($it['teacher_name']) ?></div><?php endif; ?></div><?= $badge($it['state']) ?></div>
      <?php if ($t && $t['status'] === 'CLEARED'): ?><div class="small text-muted mt-1"><i class="bi bi-pen"></i> Signed by <?= h($t['signer_name']) ?> · <?= h(substr($t['signed_at'], 0, 16)) ?></div>
      <?php elseif ($t): ?><div class="small text-danger mt-1"><i class="bi bi-exclamation-circle"></i> To settle: <?= h($t['note']) ?></div><?php endif; ?>
      <?php foreach ($subs[$it['key']] ?? [] as $s): ?><div class="small border rounded p-2 mt-2 bg-body-tertiary"><div class="text-muted">You sent · <?= h(substr($s['created_at'], 0, 16)) ?></div><?php if ($s['message'] !== ''): ?><div style="white-space:pre-line"><?= h($s['message']) ?></div><?php endif; ?><?php if ($s['file_name']): ?><a href="<?= url('clearancefile', ['id' => $s['id']]) ?>" target="_blank"><i class="bi bi-paperclip"></i><?= h($s['file_name']) ?></a><?php endif; ?><?php if ($s['reply']): ?><div class="mt-1 text-success"><i class="bi bi-reply"></i> Reply: <?= h($s['reply']) ?></div><?php else: ?><div class="text-muted mt-1"><?= $s['status'] === 'NEW' ? 'Not seen yet' : 'Seen' ?></div><?php endif; ?></div><?php endforeach; ?>
      <?php if ($it['state'] !== 'CLEARED' && Clearance::uploads() && !$st['final']): ?>
      <details class="mt-2"><summary class="small text-primary">Send proof or a message</summary>
        <form method="post" enctype="multipart/form-data" class="mt-2"><?= csrf_field() ?><input type="hidden" name="reg" value="<?= (int) $r['id'] ?>"><input type="hidden" name="key" value="<?= h($it['key']) ?>">
          <textarea class="form-control form-control-sm mb-2" name="message" rows="2" maxlength="1000" placeholder="e.g. I returned the 2 books today (receipt attached)" aria-label="Message"></textarea>
          <input class="form-control form-control-sm mb-2" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" aria-label="Photo or PDF">
          <div class="form-text mb-2">A photo (JPG, PNG, WebP) or PDF, up to 5 MB.</div>
          <button class="btn btn-sm btn-primary">Send</button></form></details><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$st['items']): ?><div class="list-group-item text-muted">No office signs clearance yet.</div><?php endif; ?>
  </div>
  <?php if ($st['final']): ?><p class="small mt-3 mb-0"><i class="bi bi-patch-check text-success"></i> Approved by the registrar <?= h($st['final']['signer_name']) ?> on <?= h(substr($st['final']['signed_at'], 0, 16)) ?>.</p>
  <?php elseif ($st['complete']): ?><p class="small mt-3 mb-0">Every office has signed. The registrar will approve your clearance.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php if ($notices): ?><section class="form-section"><h2 class="h6">Updates</h2><ul class="list-unstyled small mb-0"><?php foreach ($notices as $nt): ?><li class="mb-1"><strong><?= h($nt['title']) ?></strong> · <?= h($nt['message']) ?> <span class="text-muted"><?= h(substr((string) $nt['created_at'], 0, 16)) ?></span></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php endif; ?>
<?php layout_end();
