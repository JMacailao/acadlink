<?php
/** How to use the SIS: the person's own job first, then the whole flow. */
declare(strict_types=1);
$u = Auth::user();
$link = fn(string $label, string $route, array $p = []) => '<a href="' . url($route, $p) . '">' . h($label) . '</a>';

/** [title, [steps], result] — the jobs, in the order they happen */
$jobs = [
    'setup' => ['Set up the fees (once per school year)', [
        'Go to ' . $link('Categories', 'assessment', ['tab' => 'categories']) . ': the fee groups (Tuition, Miscellaneous, Laboratory, Other Fees) are already there. Add more if you need them.',
        'Go to ' . $link('Labels', 'assessment', ['tab' => 'labels']) . ': add each fee line with its category, how it is charged (fixed, per unit, per subject) and the usual amount. Example: Tuition Fee · per unit · ₱500.',
        'Go to ' . $link('Fee Templates', 'assessment', ['tab' => 'names']) . ': make a fee template such as “BSIT 1st Year 1st Semester 2026-2027” and add the labels it charges. Use <b>Copy</b> to make the next one quickly.',
        'Add your scholarships and discounts in ' . $link('Scholarships & Discounts', 'discounts') . ': a percentage or a fixed amount, off the whole assessment or off chosen categories (e.g. 50% of Tuition), with an optional maximum.',
        'Check that ' . $link('Sections', 'sections') . ' (e.g. BSIT 1A) and the ' . $link('Curriculum', 'curriculum') . ' (subjects per program, year level and term) are ready.',
    ], 'Registration now fills in subjects and fees automatically.', ['assessment.edit']],
    'admission' => ['Step 1 · Admit a new student', [
        'Open ' . $link('Admission', 'admission') . ' and fill in the applicant form, then <b>Save</b>.',
        'When the requirements are complete, click <b>Admit</b>. (Or <b>Reject</b> with a reason.)',
        'Click <b>Register (step 2)</b> to continue.',
    ], 'The applicant gets a Student ID.', ['admission']],
    'registration' => ['Step 2 · Register a student for a term', [
        'Open ' . $link('New registration', 'registration', ['new' => 1]) . '. Choose the admitted applicant, or type a continuing student’s Student ID and click <b>Find</b>.',
        'Choose the <b>section</b> and <b>term</b>, then click <b>Register</b>. A continuing student can be registered only when the final grades of their last term are <b>tagged complete</b> by the registrar (grading system → Final grades).',
        'Check the subjects and fees. Give a scholarship or discount under <b>Scholarship / discount</b>: the amount is computed and shown as a “Less” line. To charge a fee this student needs, use <b>Add a fee (from Labels)</b>: pick the label, keep its default amount or type another. Click <b>×</b> on a fee the student should not pay. For anything else, add a charge or a <b>less / discount</b>.',
        'When everything is right, click <b>Confirm Enrollment</b>: the assessment is checked first (<i>Assessment correct?</i>). The student does not need to pay first: an unpaid or partly paid balance is collected by the cashier later. After that the registration can only be <b>viewed</b> (only the administrator can reopen it). A wrong registration is <b>voided</b> with a reason.',
        '<b>Print</b> the assessment and send the student to the cashier.',
    ], 'The student is enrolled in that section (also in the grading system) and has an amount to pay.', ['registration']],
    'cashier' => ['Step 3 · Receive a payment', [
        'Open ' . $link('Cashier', 'cashier') . ' (or use the search box at the top) and find the student by name, Student ID or registration number.',
        'Enter the amount and the method. GCash, Maya, bank, card and check need a reference number.',
        'Click <b>Record payment &amp; print receipt</b>, then print the receipt.',
        'A mistake? <b>Void</b> the payment with a reason and record it again.',
    ], 'The balance goes down. Partial payments are fine; the student can pay the rest later.', ['payments.record']],
    'users' => ['Accounts', [
        'Open ' . $link('Users', 'users') . ' to add staff (Registrar, Admission, Cashier) and students.',
        'A student account uses the <b>Student ID</b> as the username.',
        'Someone forgot their password or is locked out? Reset their password there.',
    ], 'Each person sees only the menus they need.', ['users.manage']],
];
$mine = array_filter($jobs, fn($j) => Auth::can(...$j[3]));
$others = array_diff_key($jobs, $mine);

layout_start('Help');
page_title('How to use the SIS', '<a class="btn btn-outline-secondary" href="' . url('dashboard') . '"><i class="bi bi-house-door"></i> Home</a>', 'A short guide. You can open it any time from <i class="bi bi-question-circle"></i> Help at the top.');
?>
<div class="form-section">
  <h2 class="h6">The big picture</h2>
  <p class="mb-2">Every term, each student goes through three steps. The menu on the left shows them in the same order.</p>
  <?= step_bar(0) ?>
  <p class="small text-muted mb-0">New students start at step 1. Continuing students start at step 2 with their Student ID. After the term, the registrar tags the final grades complete in the grading system, and students see them in the Student Portal.</p>
</div>

<?php if (Auth::can('self') && !Auth::can('students')): ?>
<div class="form-section"><h2 class="h6">For students</h2>
  <ul class="mb-0"><li>Open <?= $link('My Account', 'my') ?> to see your final grades, your assessment (what you pay), your balance and your receipts.</li>
  <li>Your grades appear after the registrar tags them complete.</li><li>Use <i class="bi bi-key"></i> at the top to change your password.</li></ul></div>
<?php endif; ?>

<?php foreach ([['Your tasks', $mine], ['Other people’s tasks', $others]] as [$heading, $list]): if (!$list) continue; ?>
<h2 class="h5 mt-4 mb-3"><?= h($heading) ?></h2>
<div class="row g-3">
  <?php foreach ($list as [$title, $steps, $result]): ?>
  <div class="col-lg-6"><div class="form-section h-100 mb-0">
    <h3 class="h6"><?= h($title) ?></h3>
    <ol class="mb-2"><?php foreach ($steps as $s): ?><li><?= $s ?></li><?php endforeach; ?></ol>
    <div class="small text-success-emphasis bg-success-subtle rounded px-2 py-1"><i class="bi bi-check2-circle"></i> <?= h($result) ?></div>
  </div></div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="form-section mt-4">
  <h2 class="h6">Good to know</h2>
  <ul class="mb-0">
    <li>Press <kbd>/</kbd> on any page to jump to the search box.</li>
    <li>Students, sections, teachers and curriculum are the same records as in the grading system. A change in one shows in the other.</li>
    <li>A registration can be cancelled only before any payment. Void the payments first.</li>
    <li>Five wrong passwords lock an account for 15 minutes; an administrator can reset the password in Users.</li>
  </ul>
</div>
<?php layout_end();
