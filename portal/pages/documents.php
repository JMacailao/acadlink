<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
/** Student Portal → Documents (v52): request a TOR or certificate, pay at the Cashier, follow it until release. */
$s = DocumentRequests::ownStudent();
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'request') {
        $id = DocumentRequests::create($_POST);
        $r = DocumentRequests::get($id);
        flash('success', 'Request ' . $r['ref_no'] . ' sent.' . ($r['status'] === 'PENDING_PAYMENT' ? ' Pay ₱' . Money::fmt(Money::cents($r['amount'])) . ' at the Cashier; we start preparing it after payment.' : ''));
    } elseif ($a === 'cancel') { DocumentRequests::cancelOwn((int) ($_POST['id'] ?? 0)); flash('success', 'Request cancelled.'); }
    else throw new AppError('Unknown action.');
    redirect('documents');
}
$types = array_filter(Documents::TYPES, fn($t) => DocumentRequests::online($t), ARRAY_FILTER_USE_KEY);
$terms = Documents::terms((string) $s['id']);
$mine = DocumentRequests::forStudent((string) $s['id']);
$missing = array_unique(array_merge(Documents::blockers('tor', $s), Documents::blockers('good-moral', $s)));
layout_start('Documents');
page_title('Documents', '', 'Request your Transcript of Records or a certificate. Pay at the Cashier, then pick it up at the Registrar when it is ready.');
?>
<div class="row g-3">
  <div class="col-lg-5">
    <?php if ($types): ?>
    <form method="post" class="form-section" id="docRequest"><?= csrf_field() ?><input type="hidden" name="action" value="request">
      <h2 class="h6">Request a document</h2>
      <label class="form-label small mb-0" for="rqType">Document *</label>
      <select class="form-select mb-2" id="rqType" name="type" required><option value="">— choose —</option>
        <?php foreach ($types as $t => [$title]): ?><option value="<?= h($t) ?>"><?= h($title) ?> · ₱<?= Money::fmt(DocumentRequests::fee($t)) ?> per copy · about <?= DocumentRequests::days($t) ?> working day<?= DocumentRequests::days($t) === 1 ? '' : 's' ?></option><?php endforeach; ?></select>
      <label class="form-label small mb-0" for="rqTerm">Term <span class="text-muted">(Certificate of Enrollment and of Grades)</span></label>
      <select class="form-select mb-2" id="rqTerm" name="term"><?php foreach ($terms as $k): ?><option value="<?= h($k) ?>"><?= h(str_replace('|', ' · ', $k)) ?></option><?php endforeach; if (!$terms): ?><option value="">No terms yet</option><?php endif; ?></select>
      <div class="row g-2">
        <div class="col-4"><label class="form-label small mb-0" for="rqCopies">Copies *</label><input class="form-control" type="number" id="rqCopies" name="copies" min="1" max="<?= DocumentRequests::MAX_COPIES ?>" value="1" required></div>
        <div class="col-8"><label class="form-label small mb-0" for="rqPurpose">Purpose *</label><input class="form-control" id="rqPurpose" name="purpose" required maxlength="200" list="rqPurposes" placeholder="e.g. Employment"></div>
      </div>
      <datalist id="rqPurposes"><option>Employment</option><option>Board examination</option><option>Transfer to another school</option><option>Scholarship</option><option>Further studies</option></datalist>
      <?php if ($missing): ?><p class="small text-warning-emphasis mt-2 mb-0"><i class="bi bi-info-circle"></i> For a TOR or Good Moral, the Registrar needs: <?= h(implode(', ', $missing)) ?>. Fill in what you can in <a href="<?= url('profile') ?>">My profile</a>.</p><?php endif; ?>
      <p class="small text-muted mt-2 mb-2">The fee is shown before you send. Total = fee × copies.</p>
      <button class="btn btn-primary w-100"><i class="bi bi-send"></i> Send request</button>
    </form>
    <?php else: ?><section class="form-section"><h2 class="h6">Request a document</h2><p class="mb-0 text-muted">Online requests are not open right now. Ask the Registrar.</p></section><?php endif; ?>
    <section class="form-section small"><h2 class="h6">How it works</h2>
      <ol class="mb-0 ps-3"><li>Send your request here.</li><li>Pay the fee at the <strong>Cashier</strong> (mention your request number).</li><li>The Registrar prepares it; we notify you when it is <strong>ready for pickup</strong>.</li><li>Pick it up at the Registrar with your ID.</li></ol></section>
  </div>
  <div class="col-lg-7">
    <section class="form-section"><h2 class="h6">My requests</h2>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Request</th><th>Document</th><th>Status</th><th class="money">Fee</th><th></th></tr></thead><tbody>
      <?php foreach ($mine as $r): [$rl, $rc] = DocumentRequests::STATUS[$r['status']]; ?><tr>
        <td class="font-monospace small"><?= h($r['ref_no']) ?><div class="text-muted"><?= h(date('M j, Y', strtotime($r['created_at']))) ?></div></td>
        <td><?= (int) $r['copies'] ?> × <?= h(Documents::TYPES[$r['doc_type']][0]) ?><?= $r['school_year'] ? '<div class="small text-muted">' . h($r['school_year'] . ' ' . $r['term']) . '</div>' : '' ?><div class="small text-muted"><?= h($r['purpose']) ?></div></td>
        <td><span class="badge text-bg-<?= $rc ?>"><?= h($rl) ?></span>
          <?php if ($r['status'] === 'PENDING_PAYMENT'): ?><div class="small text-muted">Pay at the Cashier</div><?php endif; ?>
          <?php if (in_array($r['status'], ['PAID', 'PROCESSING'], true) && $r['expected_date']): ?><div class="small text-muted">Expected <?= h(date('M j, Y', strtotime($r['expected_date']))) ?></div><?php endif; ?>
          <?php if ($r['status'] === 'READY'): ?><div class="small">Pick up at the Registrar with your ID</div><?php endif; ?>
          <?php if ($r['status'] === 'RELEASED'): ?><div class="small text-muted"><?= h(date('M j, Y', strtotime($r['released_at']))) ?> · to <?= h($r['released_to']) ?></div><?php endif; ?>
          <?php if ($r['status'] === 'CANCELLED'): ?><div class="small text-muted"><?= h($r['cancel_reason']) ?></div><?php endif; ?></td>
        <td class="money small">₱<?= Money::fmt(Money::cents($r['amount'])) ?><?php if ($r['receipt_no']): $pid = Db::val("SELECT id FROM sis_document_payments WHERE request_id = ? AND status = 'POSTED' ORDER BY id DESC LIMIT 1", [$r['id']]); ?><div><a target="_blank" rel="noopener" href="<?= url('docreceipt', ['id' => $pid]) ?>">O.R. <?= h($r['receipt_no']) ?></a></div><?php endif; ?></td>
        <td class="text-end"><?php if ($r['status'] === 'PENDING_PAYMENT'): ?><form method="post" data-confirm="Cancel request <?= h($r['ref_no']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-secondary">Cancel</button></form><?php endif; ?></td>
      </tr><?php endforeach; if (!$mine): ?><tr><td colspan="5" class="text-center text-muted py-3">No requests yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </div>
</div>
<?php layout_end();
