<?php
/** Administration → Settings: how the assessment form and the official receipt are printed. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
if ($isPost && ($_POST['action'] ?? '') === 'clearance') { // v55: Online clearance (Power Users only)
    Clearance::saveSettings($_POST);
    flash('success', !empty($_POST['clr_enabled']) ? 'Online clearance is on.' : 'Online clearance is off. Its records are kept.');
    header('Location: ' . url('settings') . '#clearance'); exit;
}
if ($isPost) {
    Settings::save($_POST);
    flash('success', 'Settings saved. Enrollment checks and new printouts use them right away.');
    redirect('settings');
}
$s = Settings::all();
$v = fn(string $k) => h($s[$k]);
$chk = fn(string $k) => $s[$k] === '1' ? ' checked' : '';
$papers = fn(string $k) => implode('', array_map(fn($key, $label) => '<option value="' . $key . '"' . ($s[$k] === $key ? ' selected' : '') . '>' . h($label) . '</option>', array_keys(Settings::PAPERS), Settings::PAPERS));
$lastReg = (int) Db::val("SELECT MAX(id) FROM sis_registrations WHERE status <> 'CANCELLED'");
$lastPay = (int) Db::val("SELECT MAX(id) FROM sis_payments WHERE status = 'POSTED'");
$series = OrSeries::all();
layout_start('Settings');
page_title('Settings', '', 'How the assessment statement and official receipt look when printed. Changes apply to every printout from now on.');
?>
<form method="post" class="settings-grid"><?= csrf_field() ?>
  <div class="settings-main">
    <section class="form-section">
      <h2 class="h6"><i class="bi bi-building me-1"></i>Header of both printouts</h2>
      <div class="row g-2">
        <div class="col-12"><label class="form-label small" for="sName">School name</label><input class="form-control" id="sName" name="print_school_name" maxlength="150" value="<?= $v('print_school_name') ?>" placeholder="<?= h(School::name()) ?> (from the grading system)"></div>
        <div class="col-md-7"><label class="form-label small" for="sAddr">Address</label><input class="form-control" id="sAddr" name="print_address" maxlength="200" value="<?= $v('print_address') ?>" placeholder="e.g. 123 Rizal St., Poblacion, Quezon City"></div>
        <div class="col-md-5"><label class="form-label small" for="sContact">Contact line</label><input class="form-control" id="sContact" name="print_contact" maxlength="150" value="<?= $v('print_contact') ?>" placeholder="e.g. (02) 8123 4567 · registrar@school.edu.ph"></div>
        <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="print_show_logo" value="1"<?= $chk('print_show_logo') ?>> Show the school logo <span class="text-muted small">(set in the grading system's School settings)</span></label></div>
      </div>
    </section>

    <section class="form-section">
      <h2 class="h6"><i class="bi bi-file-earmark-text me-1"></i>Assessment statement</h2>
      <div class="row g-2">
        <div class="col-12"><label class="form-label small" for="aTemplate">Assessment template</label><select class="form-select" id="aTemplate" name="asm_template"><option value="1"<?= $s['asm_template'] === '1' ? ' selected' : '' ?>>Template 1 — Statement of Account</option><option value="2"<?= $s['asm_template'] === '2' ? ' selected' : '' ?>>Template 2 — Enrollment Assessment Form</option></select><div class="form-text">Both templates use the logo configured in the grading system's School settings. Template 2 follows the supplied enrollment form; class schedules are not stored in this SIS, so it shows assigned teachers, current fees, and actual payments instead. Lecture / laboratory units show when they are separated (below).</div></div>
        <div class="col-md-7"><label class="form-label small" for="aTitle">Title (Template 1)</label><input class="form-control" id="aTitle" name="asm_title" maxlength="80" value="<?= $v('asm_title') ?>"><div class="form-text">Template 2 uses the heading “Assessment Form.”</div></div>
        <div class="col-md-5"><label class="form-label small" for="aPaper">Paper</label><select class="form-select" id="aPaper" name="asm_paper"><?= $papers('asm_paper') ?></select></div>
        <div class="col-12 d-flex flex-wrap gap-3">
          <label class="form-check"><input class="form-check-input" type="checkbox" name="asm_show_subjects" value="1"<?= $chk('asm_show_subjects') ?>> List the subjects</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="asm_show_breakdown" value="1"<?= $chk('asm_show_breakdown') ?>> Show how per-unit fees are computed (17 × 500.00)</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="asm_show_payments" value="1"<?= $chk('asm_show_payments') ?>> Show payment history and balance due</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="asm_split_lec_lab" value="1"<?= $chk('asm_split_lec_lab') ?>> Separate lecture and laboratory units (subjects get Lecture and Laboratory units; subject fees can be charged per lecture or per laboratory unit; the assessment shows Lec / Lab)</label>
        </div>
        <div class="col-12"><label class="form-label small" for="aNote">Note at the bottom</label><textarea class="form-control" id="aNote" name="asm_note" rows="3" maxlength="1000" placeholder="e.g. Payment schedule: 40% upon enrollment, 30% before midterms, 30% before finals. Fees are non-refundable after the second week of classes."><?= $v('asm_note') ?></textarea></div>
        <div class="col-md-6"><label class="form-label small" for="aSignL">Signature line 1</label><input class="form-control" id="aSignL" name="asm_sign_left" maxlength="60" value="<?= $v('asm_sign_left') ?>" placeholder="Empty = none"></div>
        <div class="col-md-6"><label class="form-label small" for="aSignR">Signature line 2</label><input class="form-control" id="aSignR" name="asm_sign_right" maxlength="60" value="<?= $v('asm_sign_right') ?>" placeholder="Empty = none"></div>
      </div>
    </section>

    <section class="form-section">
      <h2 class="h6"><i class="bi bi-receipt me-1"></i>Official receipt</h2>
      <div class="row g-2">
        <div class="col-md-7"><label class="form-label small" for="rTitle">Title</label><input class="form-control" id="rTitle" name="or_title" maxlength="60" value="<?= $v('or_title') ?>"></div>
        <div class="col-md-5"><label class="form-label small" for="rPaper">Paper</label><select class="form-select" id="rPaper" name="or_paper"><?= $papers('or_paper') ?></select></div>
        <div class="col-md-6"><label class="form-label small" for="rCopies">Copies on one sheet</label><select class="form-select" id="rCopies" name="or_copies"><option value="1"<?= $s['or_copies'] === '1' ? ' selected' : '' ?>>1 copy</option><option value="2"<?= $s['or_copies'] === '2' ? ' selected' : '' ?>>2 copies: Original (payer) + Duplicate (school)</option></select></div>
        <div class="col-12">
          <div class="ors-settings">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><strong class="small me-auto"><i class="bi bi-123 me-1"></i>O.R. numbers and templates</strong>
              <a class="btn btn-sm btn-outline-primary" href="<?= url('orseries') ?>" data-frame="<?= url('orseries', ['embed' => 1]) ?>" data-frame-title="Official Receipt Series Types" data-frame-size="lg"><i class="bi bi-list-ol me-1"></i>O.R. Series Setup</a></div>
            <table class="table table-sm small mb-1"><thead><tr><th>Code</th><th>Description</th><th>Next O.R. No.</th><th>Template</th></tr></thead><tbody>
            <?php foreach ($series as $se): ?><tr class="<?= (int) $se['is_active'] ? '' : 'text-muted' ?>"><td class="font-monospace"><?= h($se['code']) ?><?= (int) $se['is_default'] ? ' <span class="badge text-bg-primary">default</span>' : '' ?><?= (int) $se['is_active'] ? '' : ' <span class="badge text-bg-secondary">inactive</span>' ?></td><td><?= h($se['description']) ?></td>
              <td class="font-monospace"><?= h(OrSeries::suggest($se) ?: 'typed by the cashier') ?></td><td><?= $se['template'] === 'OFFICIAL' ? 'Template Official Receipt' : 'Standard' ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <div class="form-text mt-0">Each series has its own numbering and template. An O.R. number is used only once; it can be corrected after posting, and a voided receipt's number can be made negative so it can be used again. The title, copies and note below are for the standard template.</div>
          </div>
        </div>
        <div class="col-12 d-flex flex-wrap gap-3">
          <label class="form-check"><input class="form-check-input" type="checkbox" name="or_show_words" value="1"<?= $chk('or_show_words') ?>> Amount in words</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="or_show_balance" value="1"<?= $chk('or_show_balance') ?>> Show the remaining balance</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="cashier_require_plan" value="1"<?= $chk('cashier_require_plan') ?>> Cashier: require a payment plan before the first payment of an enrollment (opening balances and enrollments that already have payments are not blocked)</label>
        </div>
        <div class="col-md-8"><label class="form-label small" for="rNote">Note at the bottom</label><textarea class="form-control" id="rNote" name="or_note" rows="2" maxlength="500"><?= $v('or_note') ?></textarea></div>
        <div class="col-md-4"><label class="form-label small" for="rSign">Signature label</label><input class="form-control" id="rSign" name="or_sign" maxlength="60" value="<?= $v('or_sign') ?>" placeholder="e.g. Cashier"></div>
      </div>
    </section>
    <section class="form-section">
      <h2 class="h6"><i class="bi bi-calendar-range me-1"></i>Academic terms and deadlines</h2>
      <?php $cal = TermCalendar::all(); ?>
      <table class="table table-sm small mb-2"><tbody>
      <?php foreach (array_slice($cal, 0, 6) as $c): $openNow = date('Y-m-d') >= $c['enrollment_start'] && date('Y-m-d') <= $c['enrollment_end']; ?><tr><td><?= h($c['school_year'] . ' · ' . $c['term']) ?></td><td>Enrollment <?= h(date('M j', strtotime($c['enrollment_start'])) . ' – ' . date('M j, Y', strtotime($c['enrollment_end']))) ?> <span class="badge <?= $openNow ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $openNow ? 'open' : 'closed' ?></span></td><td><?= $c['grades_due'] ? 'Grades due ' . h(date('M j', strtotime($c['grades_due']))) : '' ?></td></tr><?php endforeach; ?>
      <?php if (!$cal): ?><tr><td class="text-muted">No enrollment periods set: registration is open for every term.</td></tr><?php endif; ?>
      </tbody></table>
      <a class="btn btn-sm btn-outline-primary" href="<?= url('terms') ?>" data-frame="<?= url('terms', ['embed' => 1]) ?>" data-frame-title="Academic terms and deadlines" data-frame-size="xl"><i class="bi bi-calendar-plus me-1"></i>Academic terms and deadlines</a>
    </section>

    <section class="form-section">
      <h2 class="h6"><i class="bi bi-clipboard-check me-1"></i>Enrollment eligibility</h2>
      <label class="form-check"><input class="form-check-input" type="checkbox" name="eligibility_block_incomplete" value="1"<?= $chk('eligibility_block_incomplete') ?>> Block enrollment when the previous finalized term still has INC or missing grades</label>
      <p class="form-text">Off preserves the existing warning-only policy for a term tagged complete with incomplete grades. Subject prerequisites and explicit holds still block confirmation. Untagged previous-term grades still require completion or a recorded administrator exception.</p>
      <a class="btn btn-sm btn-outline-primary" href="<?= url('eligibility') ?>">Manage eligibility checks, holds and prerequisites</a>
    </section>

    <section class="form-section">
      <h2 class="h6"><i class="bi bi-wallet2 me-1"></i>Existing Balance Enrollment</h2>
      <p class="small text-muted">Previous-semester balances are calculated from existing registrations minus posted payments. No duplicate balance or receipt is created.</p>
      <div class="row g-2">
        <div class="col-12 d-flex flex-wrap gap-3">
          <label class="form-check"><input class="form-check-input" type="checkbox" name="balance_enrollment_enabled" value="1"<?= $chk('balance_enrollment_enabled') ?>> Enforce the balance enrollment workflow</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="balance_allow_pay" value="1"<?= $chk('balance_allow_pay') ?>> Option A · Pay balance at Cashier</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="balance_allow_arrangement" value="1"<?= $chk('balance_allow_arrangement') ?>> Option B · Payment arrangement</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="balance_allow_special" value="1"<?= $chk('balance_allow_special') ?>> Option C · Special approval</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="balance_show_on_latest_assessment" value="1"<?= $chk('balance_show_on_latest_assessment') ?>> Show approved existing balance on latest assessment</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="balance_require_approval" value="1"<?= $chk('balance_require_approval') ?>> Require an approved case before enrollment with balance</label>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="acct_separate_duties" value="1"<?= $chk('acct_separate_duties') ?>> Separation of duties: the person who prepares a journal entry or uploads opening balances cannot also approve or post it</label>
        </div>
        <div class="col-md-4"><label class="form-label small">Minimum payment before enrollment</label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control" name="balance_min_payment" value="<?= $v('balance_min_payment') ?>" inputmode="decimal"></div></div>
        <div class="col-md-4"><label class="form-label small">Arrangement approver roles</label><input class="form-control" name="balance_arrangement_approver_roles" value="<?= $v('balance_arrangement_approver_roles') ?>"><div class="form-text">ADMIN, REGISTRAR, ACCOUNTING</div></div>
        <div class="col-md-4"><label class="form-label small">Special approval roles</label><input class="form-control" name="balance_special_approver_roles" value="<?= $v('balance_special_approver_roles') ?>"><div class="form-text">ADMIN, REGISTRAR, ACCOUNTING</div></div>
        <div class="col-12"><label class="form-label small">Installment and payment-arrangement rules</label><textarea class="form-control" name="balance_installment_rules" rows="2" maxlength="1000"><?= $v('balance_installment_rules') ?></textarea></div>
      </div>
      <div class="form-text mt-2">If enabled, the latest assessment displays the approved prior balance with its original school year, semester, and registration number. It remains a separate receivable; payments continue to use the original registration and official receipt.</div>
    </section>

    <section class="form-section">
      <h2 class="h6"><i class="bi bi-mortarboard me-1"></i>Student Portal</h2>
      <label class="form-check"><input class="form-check-input" type="checkbox" name="portal_hide_grades_unpaid" value="1"<?= $chk('portal_hide_grades_unpaid') ?>> Hold a term's final grades on the Student Portal while its balance is unpaid</label>
      <div class="form-text">Off: grades show as soon as the registrar tags them complete. Staff always see every grade.</div>
    </section>
  </div>

  <aside class="settings-side">
    <div class="form-section">
      <h2 class="h6">Save and preview</h2>
      <button class="btn btn-primary w-100 mb-2"><i class="bi bi-save me-1"></i>Save settings</button>
      <p class="small text-muted mb-2">Save first, then preview with real records:</p>
      <?php if ($lastReg): ?><a class="btn btn-outline-secondary w-100 mb-2" href="<?= url('registration', ['id' => $lastReg]) ?>" data-frame="<?= url('registration', ['id' => $lastReg, 'embed' => 1]) ?>" data-frame-title="Preview · Assessment form" data-frame-size="xl"><i class="bi bi-file-earmark-text me-1"></i>Preview assessment form</a>
      <?php else: ?><div class="small text-muted mb-2">No registration yet to preview the assessment.</div><?php endif; ?>
      <?php if ($lastPay): ?><a class="btn btn-outline-secondary w-100" href="<?= url('receipt', ['id' => $lastPay]) ?>" data-frame="<?= url('receipt', ['id' => $lastPay, 'embed' => 1]) ?>" data-frame-title="Preview · Official receipt" data-frame-size="lg"><i class="bi bi-receipt me-1"></i>Preview official receipt</a>
      <?php else: ?><div class="small text-muted">No payment yet to preview the receipt.</div><?php endif; ?>
      <p class="small text-muted mt-3 mb-0">In the browser's print window, choose the same paper size and turn off “Headers and footers” for a clean printout.</p>
    </div>
  </aside>
</form>
<?php $clrPower = Clearance::isPower((int) Auth::centralId()); ?>
<form method="post" class="form-section mt-3" id="clearance"><?= csrf_field() ?><input type="hidden" name="action" value="clearance">
  <h2 class="h6"><i class="bi bi-patch-check me-1"></i>Online clearance</h2>
  <label class="form-check form-switch fs-6"><input class="form-check-input" type="checkbox" role="switch" name="clr_enabled" value="1"<?= Clearance::on() ? ' checked' : '' ?><?= $clrPower ? '' : ' disabled' ?>> <strong>Online clearance</strong></label>
  <p class="form-text mt-0">Off: there are no clearance pages or buttons in the Student Portal, Grading System or SIS, and enrollment never asks for clearance. Turning it off keeps every clearance record and signature; they come back when it's turned on again.</p>
  <div class="d-flex flex-wrap gap-3 ms-md-4">
    <label class="form-check"><input class="form-check-input" type="checkbox" name="clr_required" value="1"<?= Clearance::setting('clr_required') === '1' ? ' checked' : '' ?><?= $clrPower ? '' : ' disabled' ?>> Required before enrolling in the next term <span class="text-muted small">(the registrar can still confirm with a reason and their password)</span></label>
    <label class="form-check"><input class="form-check-input" type="checkbox" name="clr_uploads" value="1"<?= Clearance::setting('clr_uploads') === '1' ? ' checked' : '' ?><?= $clrPower ? '' : ' disabled' ?>> Students can submit compliance online (a message, photo or PDF)</label>
  </div>
  <p class="small mt-2 mb-2">Who signs: tick <em>This group signs student clearance</em> in <a href="<?= url('users', ['tab' => 'groups']) ?>">Users and Groups</a> (Accounting, Registrar and Teacher by default). The registrar opens clearance per term on the <a href="<?= url('clearance', ['tab' => 'periods']) ?>">Online Clearance</a> page.</p>
  <?php if ($clrPower): ?><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save online clearance</button><?php else: ?><p class="small text-muted mb-0"><i class="bi bi-lock"></i> Only a Power User can change these. Every change is logged.</p><?php endif; ?>
</form>
<?php layout_end();
