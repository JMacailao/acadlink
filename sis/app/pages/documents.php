<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
/** TOR and certificates: issue for one student (?student=), the "To sign" list, issued documents and their settings. */
$studentId = (string) ($_GET['student'] ?? '');
$canIssue = Auth::can('documents.issue');
$canVoid = Auth::can('documents.void');
$canSettings = Auth::can('settings.manage');
$me = (int) (Auth::central()->current()['id'] ?? 0);

if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    $back = $studentId !== '' ? ['student' => $studentId] : [];
    if ($a === 'issue') {
        [$type, $template] = array_pad(explode(':', (string) ($_POST['doc'] ?? '')), 2, '');
        $id = Documents::issue($studentId, ['type' => $type, 'template' => $template, 'term' => $_POST['term'] ?? '', 'purpose' => $_POST['purpose'] ?? '', 'request_id' => (int) ($_POST['request_id'] ?? 0)]);
        $d = Db::one('SELECT * FROM sis_documents WHERE id = ?', [$id]);
        flash('success', $d['status'] === 'ISSUED' ? 'Issued ' . $d['control_no'] . '. Choose Print to print it.' : 'Prepared. It is waiting in ' . Documents::accountName((int) $d['signer_account']) . "'s To sign list.");
        if (!empty($_POST['request_id'])) redirect('documents', ['tab' => 'requests']);
        redirect('documents', $back + ['open' => $id]);
    }
    if ($a === 'sign') {
        if (isset($_POST['id'])) $ids = [(int) $_POST['id']]; // the Sign button of one row
        else {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
            if (!$ids) throw new AppError('Tick the documents to sign.');
            if (!Auth::central()->verifyPassword($me, (string) ($_POST['password'] ?? ''))) throw new AppError('Enter your password to sign the ticked documents.');
        }
        foreach ($ids as $i) if ($i > 0) Documents::sign($i);
        flash('success', count($ids) === 1 ? 'Signed and issued.' : count($ids) . ' documents signed and issued.');
        redirect('documents', $back);
    }
    if ($a === 'void') { Auth::require('documents.void'); Documents::void((int) ($_POST['id'] ?? 0), (string) ($_POST['reason'] ?? '')); flash('success', 'Document voided. Its copy now shows VOID.'); redirect('documents', $back); }
    // v52: requests from the Student Portal
    if ($a === 'req_processing') { DocumentRequests::startProcessing((int) ($_POST['id'] ?? 0)); flash('success', 'Marked as processing. The student was notified.'); redirect('documents', ['tab' => 'requests']); }
    if ($a === 'req_release') { DocumentRequests::release((int) ($_POST['id'] ?? 0), (string) ($_POST['released_to'] ?? '')); flash('success', 'Released. The student was notified.'); redirect('documents', ['tab' => 'requests']); }
    if ($a === 'req_cancel') { DocumentRequests::cancel((int) ($_POST['id'] ?? 0), (string) ($_POST['reason'] ?? '')); flash('success', 'Request cancelled. The student was notified.'); redirect('documents', ['tab' => 'requests']); }
    if ($a === 'settings') { Documents::saveSettings($_POST); flash('success', 'Document settings saved.'); redirect('documents', ['tab' => 'settings']); }
    throw new AppError('Unknown action.');
}

$tab = in_array($_GET['tab'] ?? '', ['issued', 'settings', 'requests'], true) ? $_GET['tab'] : 'issued';
$open = (int) ($_GET['open'] ?? 0);
$statusBadge = fn(string $s) => ['ISSUED' => '<span class="badge text-bg-success">Issued</span>', 'PREPARED' => '<span class="badge text-bg-warning">Waiting for signature</span>', 'VOID' => '<span class="badge text-bg-danger">Void</span>'][$s] ?? h($s);
$typeName = fn(string $t) => Documents::TYPES[$t][0] ?? $t;
$docRows = function (array $rows, bool $showStudent) use ($statusBadge, $typeName, $canVoid, $studentId, $open): string {
    ob_start(); ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Control no.</th><th>Document</th><?php if ($showStudent): ?><th>Student</th><?php endif; ?><th>Purpose</th><th>Status</th><th>Signed by</th><th class="text-end">Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $d): ?><tr<?= (int) $d['id'] === $open ? ' class="table-success"' : '' ?>>
    <td class="font-monospace"><?= h($d['control_no'] ?: '—') ?></td>
    <td><?= h($typeName($d['doc_type'])) ?><?= $d['school_year'] ? '<div class="small text-muted">' . h($d['school_year'] . ' ' . $d['term']) . '</div>' : '' ?></td>
    <?php if ($showStudent): ?><td><a href="<?= url('documents', ['student' => $d['student_doc_id']]) ?>"><?= h($d['student_name']) ?></a><div class="small text-muted"><?= h($d['student_number']) ?></div></td><?php endif; ?>
    <td class="small"><?= h($d['purpose']) ?></td>
    <td><?= $statusBadge($d['status']) ?><?= $d['status'] === 'VOID' ? '<div class="small text-muted">' . h($d['void_reason']) . '</div>' : '' ?></td>
    <td class="small"><?= $d['signed_by'] ? h(Documents::accountName((int) $d['signed_by'])) . ($d['sign_mode'] === 'APPROVED' ? ' (Approved)' : ' (e-signed)') . '<div class="text-muted">' . h($d['signed_at']) . '</div>' : ($d['status'] === 'PREPARED' ? 'For ' . h(Documents::accountName((int) $d['signer_account'])) : '') ?></td>
    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= url('docview', ['id' => $d['id']]) ?>"><i class="bi bi-printer"></i> <?= $d['status'] === 'ISSUED' ? 'Print / reprint' : 'Open' ?></a>
      <?php if ($canVoid && $d['status'] !== 'VOID'): ?><details class="d-inline-block text-start"><summary class="btn btn-sm btn-outline-danger">Void</summary>
        <form method="post" class="mt-1 p-2 border rounded bg-white" style="min-width:240px"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
          <label class="form-label small mb-1" for="vr<?= (int) $d['id'] ?>">Reason *</label><input class="form-control form-control-sm mb-2" id="vr<?= (int) $d['id'] ?>" name="reason" required minlength="3" maxlength="255">
          <button class="btn btn-sm btn-danger">Void this document</button></form></details><?php endif; ?></td>
  </tr><?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">No documents yet.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php return (string) ob_get_clean();
};

// ---------------------------------------------------------------- one student
if ($studentId !== '') {
    $s = GS::get('students', $studentId) ?: throw new AppError('Student not found.');
    $terms = Documents::terms($studentId);
    $request = isset($_GET['request']) ? DocumentRequests::get((int) $_GET['request']) : null;
    if ($request && $request['student_doc_id'] !== $studentId) $request = null;
    $defaultType = (string) ($request['doc_type'] ?? 'tor');
    if (!isset(Documents::TYPES[$defaultType])) $defaultType = 'tor';
    $defaultChoice = $defaultType . ':' . Documents::defaultTemplate($defaultType);
    $choice = (string) ($_GET['doc'] ?? $defaultChoice);
    [$type, $template] = array_pad(explode(':', $choice), 2, '');
    if (!isset(Documents::templates($type)[$template])) { $type = $defaultType; $template = Documents::defaultTemplate($type); $choice = $type . ':' . $template; }
    $term = (string) ($_GET['term'] ?? ($terms[0] ?? ''));
    $purpose = (string) ($_GET['purpose'] ?? '');
    $missing = Documents::blockers($type, $s);
    $signer = Documents::signer($type);
    $iSign = $signer === null || $signer === $me || ESign::isPowerUser($me);
    $noSig = $iSign && !ESign::isPowerUser($me) && !ESign::has($me);
    $previewError = '';
    try { Documents::data($type, $s, ['term' => $term]); } catch (AppError $e) { $previewError = $e->getMessage(); }
    layout_start('Documents · ' . ($s['studentName'] ?? ''));
    page_title('TOR & Certificates', '<a class="btn btn-outline-secondary" href="' . url('student', ['id' => $studentId]) . '"><i class="bi bi-arrow-left"></i> Student record</a>',
        h(($s['studentName'] ?? '') . ' · ' . ($s['studentId'] ?? '')));
    ?>
<?php if ($request): [$rl, $rc] = DocumentRequests::STATUS[$request['status']]; ?>
<div class="alert alert-info d-flex flex-wrap gap-2 align-items-center"><i class="bi bi-inbox"></i><span>For request <strong><?= h($request['ref_no']) ?></strong>: <?= (int) $request['copies'] ?> × <?= h(Documents::TYPES[$request['doc_type']][0]) ?> · <?= h($request['purpose']) ?> · <span class="badge text-bg-<?= $rc ?>"><?= h($rl) ?></span>. Issuing it marks the request ready for pickup.</span></div>
<?php endif; ?>
<div class="row g-3" data-record-detail>
  <div class="col-xl-4">
    <form method="get" class="form-section" id="docChoose"><?php if ($request): ?><input type="hidden" name="request" value="<?= (int) $request['id'] ?>"><?php endif; ?><input type="hidden" name="r" value="documents"><input type="hidden" name="student" value="<?= h($studentId) ?>">
      <h2 class="h6">Document</h2>
      <label class="form-label small" for="docPick">Document and template</label>
      <select class="form-select mb-2" id="docPick" name="doc">
        <?php foreach (Documents::TYPES as $t => [$title]): ?><optgroup label="<?= h($title) ?>">
          <?php foreach (Documents::templates($t) as $tid => $tpl): ?><option value="<?= h("$t:$tid") ?>"<?= "$t:$tid" === $choice ? ' selected' : '' ?>><?= h($tpl['name'] . ' · ' . $tpl['paper']) ?></option><?php endforeach; ?>
        </optgroup><?php endforeach; ?>
      </select>
      <label class="form-label small" for="docTerm">Term <span class="text-muted">(Certificate of Enrollment and of Grades)</span></label>
      <select class="form-select mb-2" id="docTerm" name="term"><?php foreach ($terms as $k): ?><option value="<?= h($k) ?>"<?= $k === $term ? ' selected' : '' ?>><?= h(str_replace('|', ' · ', $k)) ?></option><?php endforeach; if (!$terms): ?><option value="">No terms with grades or enrollment</option><?php endif; ?></select>
      <label class="form-label small" for="docPurpose">Purpose *</label>
      <input class="form-control mb-3" id="docPurpose" name="purpose" maxlength="200" value="<?= h($purpose) ?>" placeholder="e.g. Employment, Board exam, Transfer" list="docPurposes">
      <datalist id="docPurposes"><option>Employment</option><option>Board examination</option><option>Transfer to another school</option><option>Scholarship</option><option>Further studies</option><option>Reference</option></datalist>
      <button class="btn btn-outline-primary w-100"><i class="bi bi-eye"></i> Preview</button>
    </form>
    <?php if ($canIssue): ?>
    <form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="issue">
      <input type="hidden" name="doc" value="<?= h($choice) ?>"><input type="hidden" name="term" value="<?= h($term) ?>"><input type="hidden" name="purpose" value="<?= h($purpose) ?>"><?php if ($request): ?><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><?php endif; ?>
      <h2 class="h6"><?= $iSign ? 'Sign and issue' : 'Prepare for signature' ?></h2>
      <?php if ($missing): ?><div class="alert alert-warning small mb-2" role="alert"><strong>Fill in before issuing:</strong> <?= h(implode(', ', $missing)) ?>.
        <a href="<?= url('student', ['id' => $studentId]) ?>#studentDetails">Open Records for documents</a></div><?php endif; ?>
      <?php if ($previewError): ?><div class="alert alert-warning small mb-2"><?= h($previewError) ?></div><?php endif; ?>
      <?php if ($purpose === ''): ?><div class="small text-muted mb-2">Enter the purpose and choose Preview first.</div><?php endif; ?>
      <?php if ($noSig): ?><div class="alert alert-info small mb-2">You sign this document. Add your e-signature first: <a href="<?= url('profile') ?>">My profile &amp; e-signature</a>.</div><?php endif; ?>
      <p class="small text-muted mb-2"><?= $iSign ? (ESign::isPowerUser($me) ? 'As Power User it is issued with the "APPROVED" stamp and your name.' : 'Your e-signature is placed on it and it gets a control number.') : 'It goes to ' . h(Documents::accountName($signer)) . "'s To sign list; it gets its control number when signed." ?></p>
      <button class="btn btn-primary w-100"<?= $missing || $previewError || $purpose === '' || $noSig ? ' disabled' : '' ?>><i class="bi bi-pen"></i> <?= $iSign ? 'Sign and issue' : 'Prepare' ?></button>
    </form>
    <?php endif; ?>
  </div>
  <div class="col-xl-8">
    <div class="form-section p-0 overflow-hidden"><div class="d-flex align-items-center gap-2 p-2 border-bottom"><strong class="me-auto small">Preview</strong>
      <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= url('docview', ['student' => $studentId, 'doc' => $choice, 'term' => $term, 'purpose' => $purpose]) ?>"><i class="bi bi-box-arrow-up-right"></i> Open in a new tab</a></div>
      <?php if ($previewError): ?><div class="p-3 text-muted"><?= h($previewError) ?></div>
      <?php else: ?><iframe title="Document preview" class="w-100 d-block" style="height:78vh;border:0;background:#e9edf2" src="<?= url('docview', ['student' => $studentId, 'doc' => $choice, 'term' => $term, 'purpose' => $purpose]) ?>"></iframe><?php endif; ?>
    </div>
    <div class="form-section"><h2 class="h6">Documents of this student</h2><?= $docRows(Documents::forStudent($studentId), false) ?></div>
  </div>
</div>
<?php layout_end(); return;
}

// ---------------------------------------------------------------- To sign, issued documents, settings
$toSign = $canIssue ? Documents::toSign() : [];
$q = trim((string) ($_GET['q'] ?? ''));
layout_start('TOR & Certificates');
page_title('TOR & Certificates', '', 'Issue from a student\'s record (Students → open a student → Documents). Every issued document has a control number and a saved copy for reprinting.');
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'issued' ? ' active' : '' ?>" href="<?= url('documents') ?>">Documents<?= $toSign ? ' <span class="badge text-bg-warning">' . count($toSign) . ' to sign</span>' : '' ?></a></li>
  <?php $openReq = (int) Db::val("SELECT COUNT(*) FROM sis_document_requests WHERE status IN ('PAID','PROCESSING')"); ?>
  <li class="nav-item"><a class="nav-link<?= $tab === 'requests' ? ' active' : '' ?>" href="<?= url('documents', ['tab' => 'requests']) ?>">Requests<?= $openReq ? ' <span class="badge text-bg-primary">' . $openReq . ' to prepare</span>' : '' ?></a></li>
  <?php if ($canSettings): ?><li class="nav-item"><a class="nav-link<?= $tab === 'settings' ? ' active' : '' ?>" href="<?= url('documents', ['tab' => 'settings']) ?>">Settings</a></li><?php endif; ?>
</ul>
<?php if ($tab === 'settings' && $canSettings):
    $staff = array_filter(Db::all("SELECT a.id, a.display_name FROM central_accounts a WHERE a.account_kind = 'STAFF' AND a.status = 'ACTIVE' ORDER BY a.display_name"), fn($a) => Auth::central()->can((int) $a['id'], 'sis.documents.issue')); ?>
<form method="post" class="form-section" data-record-detail><?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <h2 class="h6">Who signs each document</h2>
  <p class="small text-muted">The signatory's e-signature is placed when they issue it. Others with Documents → Edit prepare it for the signatory's To sign list. Leave "Anyone" to let whoever issues it sign. Power Users always issue with an "APPROVED" stamp.</p>
  <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Document</th><th>Default template</th><th>Signatory</th><th>Position printed under the name</th><th>Fee per copy (₱)</th><th>Processing (working days)</th><th>Students can request online</th></tr></thead><tbody>
  <?php foreach (Documents::TYPES as $t => [$title]): ?><tr><td><?= h($title) ?></td>
    <td><div class="d-flex gap-2 align-items-center"><select class="form-select form-select-sm" name="doc_template_<?= h($t) ?>" aria-label="Default template for <?= h($title) ?>">
      <?php foreach (Documents::templates($t) as $tid => $tpl): ?><option value="<?= h($tid) ?>"<?= Documents::defaultTemplate($t) === $tid ? ' selected' : '' ?>><?= h($tpl['name'] . ' · ' . $tpl['paper']) ?></option><?php endforeach; ?>
    </select><a class="btn btn-sm btn-outline-secondary text-nowrap" target="_blank" rel="noopener" data-document-template-preview data-type="<?= h($t) ?>" href="<?= url('docview', ['sample' => 'template', 'doc' => $t . ':' . Documents::defaultTemplate($t)]) ?>"><i class="bi bi-eye" aria-hidden="true"></i> Preview</a></div></td>
    <td><select class="form-select form-select-sm" name="doc_signer_<?= h($t) ?>" aria-label="Signatory for <?= h($title) ?>"><option value="">Anyone who issues it</option>
      <?php foreach ($staff as $st): ?><option value="<?= (int) $st['id'] ?>"<?= Settings::get('doc_signer_' . $t) === (string) $st['id'] ? ' selected' : '' ?>><?= h($st['display_name']) ?><?= ESign::isPowerUser((int) $st['id']) ? ' (Power User: "Approved")' : (ESign::has((int) $st['id']) ? '' : ' (no e-signature yet)') ?></option><?php endforeach; ?></select></td>
    <td><input class="form-control form-control-sm" name="doc_signer_position_<?= h($t) ?>" maxlength="80" value="<?= h(Settings::get('doc_signer_position_' . $t)) ?>" aria-label="Position for <?= h($title) ?>"></td>
    <td><input class="form-control form-control-sm" style="max-width:110px" name="doc_fee_<?= h($t) ?>" inputmode="decimal" value="<?= h(Settings::get('doc_fee_' . $t)) ?>" aria-label="Fee per copy for <?= h($title) ?>"></td>
    <td><input class="form-control form-control-sm" style="max-width:80px" name="doc_days_<?= h($t) ?>" inputmode="numeric" value="<?= h(Settings::get('doc_days_' . $t)) ?>" aria-label="Processing days for <?= h($title) ?>"></td>
    <td class="text-center"><input class="form-check-input" type="checkbox" name="doc_online_<?= h($t) ?>" value="1"<?= DocumentRequests::online($t) ? ' checked' : '' ?> aria-label="<?= h($title) ?> can be requested online"></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="small text-muted">The selected template is preselected when preparing a new document. Previously issued documents keep their saved copy.</p>
  <div class="row g-2 mb-3"><div class="col-md-6"><label class="form-label small" for="dPrep">Prepared by (name on the TOR)</label><input class="form-control" id="dPrep" name="doc_prepared_by" maxlength="120" value="<?= h(Settings::get('doc_prepared_by')) ?>"></div>
    <div class="col-md-6"><label class="form-label small" for="dChk">Checked by (name on the TOR)</label><input class="form-control" id="dChk" name="doc_checked_by" maxlength="120" value="<?= h(Settings::get('doc_checked_by')) ?>"></div></div>
  <button class="btn btn-primary">Save</button>
  <p class="small text-muted mt-3 mb-0">Templates are files in <code>sis/templates/documents/</code>. Copy a <code>standard.php</code> to a new name in the same folder to add your own; new templates appear in the matching selector automatically (see the README there).</p>
</form>
<?php elseif ($tab === 'requests'):
    $rs = in_array($_GET['status'] ?? '', array_merge(['OPEN', 'ALL'], array_keys(DocumentRequests::STATUS)), true) ? $_GET['status'] : 'OPEN';
    $rows = DocumentRequests::list($rs, $q); ?>
<div class="form-section" data-record-detail>
  <form method="get" class="d-flex flex-wrap gap-2 mb-3"><input type="hidden" name="r" value="documents"><input type="hidden" name="tab" value="requests">
    <select class="form-select" style="max-width:220px" name="status" aria-label="Status"><option value="OPEN"<?= $rs === 'OPEN' ? ' selected' : '' ?>>Open requests</option><?php foreach (DocumentRequests::STATUS as $k => [$l]): ?><option value="<?= $k ?>"<?= $rs === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?><option value="ALL"<?= $rs === 'ALL' ? ' selected' : '' ?>>All</option></select>
    <input class="form-control" style="max-width:320px" name="q" value="<?= h($q) ?>" placeholder="Reference, Student ID or name" aria-label="Search requests"><button class="btn btn-outline-secondary">Show</button></form>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Request</th><th>Student</th><th>Document</th><th>Status</th><th>Paid</th><th>Expected</th><th class="text-end">Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $r): [$rl, $rc] = DocumentRequests::STATUS[$r['status']]; $st = GS::get('students', $r['student_doc_id']); $miss = $st ? Documents::blockers($r['doc_type'], $st) : []; ?><tr>
    <td class="font-monospace small"><?= h($r['ref_no']) ?><div class="text-muted"><?= h(substr($r['created_at'], 0, 16)) ?></div></td>
    <td><?= h($r['student_name']) ?><div class="small text-muted"><?= h($r['student_number']) ?></div></td>
    <td><?= (int) $r['copies'] ?> × <?= h($typeName($r['doc_type'])) ?><?= $r['school_year'] ? '<div class="small text-muted">' . h($r['school_year'] . ' ' . $r['term']) . '</div>' : '' ?><div class="small text-muted"><?= h($r['purpose']) ?></div></td>
    <td><span class="badge text-bg-<?= $rc ?>"><?= h($rl) ?></span><?= $r['control_no'] ? '<div class="small font-monospace">' . h($r['control_no']) . '</div>' : '' ?><?= $r['status'] === 'RELEASED' ? '<div class="small text-muted">to ' . h($r['released_to']) . '</div>' : '' ?><?= $r['status'] === 'CANCELLED' ? '<div class="small text-muted">' . h($r['cancel_reason']) . '</div>' : '' ?>
      <?php if ($miss && in_array($r['status'], ['PAID', 'PROCESSING'], true)): ?><div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> Fill in first: <?= h(implode(', ', $miss)) ?></div><?php endif; ?></td>
    <td class="small">₱<?= Money::fmt(Money::cents($r['amount'])) ?><?= $r['receipt_no'] ? '<div class="font-monospace">O.R. ' . h($r['receipt_no']) . '</div>' : '' ?></td>
    <td class="small"><?= $r['expected_date'] ? h(date('M j, Y', strtotime($r['expected_date']))) : '—' ?></td>
    <td class="text-end text-nowrap">
      <?php if ($canIssue && in_array($r['status'], ['PAID', 'PROCESSING'], true) && !$r['document_id']): ?>
        <a class="btn btn-sm btn-primary" href="<?= url('documents', ['student' => $r['student_doc_id'], 'doc' => $r['doc_type'] . ':standard', 'term' => $r['school_year'] ? $r['school_year'] . '|' . $r['term'] : '', 'purpose' => $r['purpose'], 'request' => $r['id']]) ?>"><i class="bi bi-pen"></i> Issue</a>
      <?php endif; ?>
      <?php if ($r['document_id']): ?><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= url('docview', ['id' => $r['document_id']]) ?>"><i class="bi bi-printer"></i> Print</a><?php endif; ?>
      <?php if ($canIssue && $r['status'] === 'PAID'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="req_processing"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-secondary">Processing</button></form><?php endif; ?>
      <?php if ($canIssue && $r['status'] === 'READY'): ?><details class="d-inline-block text-start"><summary class="btn btn-sm btn-success">Release</summary>
        <form method="post" class="mt-1 p-2 border rounded bg-white" style="min-width:240px"><?= csrf_field() ?><input type="hidden" name="action" value="req_release"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <label class="form-label small mb-1" for="rt<?= (int) $r['id'] ?>">Received by *</label><input class="form-control form-control-sm mb-2" id="rt<?= (int) $r['id'] ?>" name="released_to" required maxlength="150" value="<?= h($r['student_name']) ?>">
          <button class="btn btn-sm btn-success">Mark released</button></form></details><?php endif; ?>
      <?php if ($canIssue && !in_array($r['status'], ['RELEASED', 'CANCELLED', 'READY'], true)): ?><details class="d-inline-block text-start"><summary class="btn btn-sm btn-outline-danger">Cancel</summary>
        <form method="post" class="mt-1 p-2 border rounded bg-white" style="min-width:240px"><?= csrf_field() ?><input type="hidden" name="action" value="req_cancel"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <label class="form-label small mb-1" for="rc<?= (int) $r['id'] ?>">Reason *</label><input class="form-control form-control-sm mb-2" id="rc<?= (int) $r['id'] ?>" name="reason" required minlength="3" maxlength="255">
          <button class="btn btn-sm btn-danger">Cancel request</button></form></details><?php endif; ?>
    </td></tr><?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">No requests here.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php else: ?>
<?php if ($toSign): ?>
<form method="post" class="form-section" data-record-detail><?= csrf_field() ?><input type="hidden" name="action" value="sign">
  <h2 class="h6">To sign <span class="badge text-bg-warning"><?= count($toSign) ?></span></h2>
  <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th><span class="visually-hidden">Choose</span></th><th>Document</th><th>Student</th><th>Purpose</th><th>Prepared by</th><th class="text-end"></th></tr></thead><tbody>
  <?php foreach ($toSign as $d): ?><tr><td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>" aria-label="Choose <?= h($d['student_name']) ?>"></td>
    <td><?= h($typeName($d['doc_type'])) ?></td><td><?= h($d['student_name']) ?> <span class="small text-muted"><?= h($d['student_number']) ?></span></td><td class="small"><?= h($d['purpose']) ?></td>
    <td class="small"><?= h(Documents::accountName((int) $d['prepared_by'])) ?> · <?= h($d['prepared_at']) ?></td>
    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= url('docview', ['id' => $d['id']]) ?>">Open</a>
      <button class="btn btn-sm btn-primary" name="id" value="<?= (int) $d['id'] ?>" formnovalidate>Sign</button></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="d-flex flex-wrap gap-2 align-items-end"><div><label class="form-label small mb-1" for="signPw">Your password (to sign the ones ticked)</label><input class="form-control form-control-sm" type="password" id="signPw" name="password" autocomplete="current-password"></div>
    <button class="btn btn-sm btn-primary" name="bulk" value="1"><i class="bi bi-pen"></i> Sign ticked</button></div>
</form>
<?php endif; ?>
<div class="form-section" data-record-detail><form method="get" class="d-flex gap-2 mb-2"><input type="hidden" name="r" value="documents">
  <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search control no., Student ID or name" aria-label="Search documents"><button class="btn btn-outline-secondary">Search</button></form>
  <?= $docRows(Documents::search($q), true) ?></div>
<?php endif; ?>
<?php layout_end();
