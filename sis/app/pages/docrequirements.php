<?php
declare(strict_types=1);
/** v56 Administration → Document Requirements: what each student classification must submit at registration. */
if ($isPost) {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'remove') {
        $r = EnrollmentDocs::removeRequirement((int) ($_POST['id'] ?? 0));
        flash('success', $r === 'off' ? 'This document was already ticked for applicants, so it was turned off instead (their records stay).' : 'Document requirement removed.');
    } else {
        EnrollmentDocs::saveRequirement(In::int($_POST, 'id'), $_POST);
        flash('success', 'Document requirement saved. It now appears on the registration checklist of the ticked classifications.');
    }
    redirect('docrequirements');
}
$rows = EnrollmentDocs::requirements();
$e = isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) ? (array_values(array_filter($rows, fn($r) => (int) $r['id'] === (int) $_GET['edit']))[0] ?? null) : null;
layout_start('Document Requirements');
page_title('Document Requirements', '<a class="btn btn-primary" href="' . h(url('docrequirements')) . '#reqForm"><i class="bi bi-plus-lg me-1"></i>Add requirement</a>',
    'Documents students submit at registration. Choose which classifications need each one; the admission form shows the matching checklist.');
?>
<div class="row g-3">
  <div class="col-xl-8"><div class="table-responsive bg-white border rounded"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Order</th><th>Document</th><th>Applies to</th><th>Required</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr class="<?= $r['is_active'] ? '' : 'text-muted' ?><?= $e && (int) $e['id'] === (int) $r['id'] ? ' table-primary' : '' ?>">
      <td><?= (int) $r['sort_order'] ?></td>
      <td class="fw-semibold"><?= h($r['name']) ?><?php if ($r['description']): ?><div class="small text-muted fw-normal"><?= h($r['description']) ?></div><?php endif; ?></td>
      <td class="small"><?= h(implode(', ', array_map(fn($t) => Admission::TYPES[$t] ?? $t, $r['types']))) ?></td>
      <td><?= (int) $r['is_required'] ? '<span class="badge text-bg-danger-subtle text-danger-emphasis">Required</span>' : '<span class="badge text-bg-light border text-secondary">Optional</span>' ?></td>
      <td><?= $r['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Off</span>' ?><?= $r['used'] ? ' <span class="small text-muted">· ' . (int) $r['used'] . ' submitted</span>' : '' ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= h(url('docrequirements', ['edit' => $r['id']])) ?>#reqForm"><i class="bi bi-pencil me-1"></i>Edit</a>
        <form method="post" class="d-inline" data-confirm="Remove “<?= h($r['name']) ?>”? If applicants already submitted it, it is turned off instead."><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Remove <?= h($r['name']) ?>"><i class="bi bi-x-lg"></i></button></form></td>
    </tr><?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No document requirements yet. Add the first one.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="col-xl-4"><form method="post" class="form-section" id="reqForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h((string) ($e['id'] ?? '')) ?>">
    <h2 class="h6"><?= $e ? 'Edit requirement' : 'Add a requirement' ?></h2>
    <label class="form-label small" for="reqName">Document *</label><input class="form-control mb-2" id="reqName" name="name" required maxlength="150" placeholder="e.g. Certificate of Good Moral Character" value="<?= h($e['name'] ?? '') ?>">
    <label class="form-label small" for="reqDesc">Note for staff</label><input class="form-control mb-2" id="reqDesc" name="description" maxlength="255" placeholder="e.g. Original and one photocopy" value="<?= h($e['description'] ?? '') ?>">
    <fieldset class="mb-2"><legend class="form-label small mb-1">Applies to *</legend>
      <?php foreach (Admission::TYPES as $k => $l): ?><label class="form-check small mb-1"><input class="form-check-input" type="checkbox" name="applies_to[]" value="<?= h($k) ?>"<?= in_array($k, $e['types'] ?? ['NEW'], true) ? ' checked' : '' ?>> <span class="form-check-label"><?= h($l) ?></span></label><?php endforeach; ?></fieldset>
    <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="is_required" value="1"<?= ($e['is_required'] ?? 1) ? ' checked' : '' ?>> <span class="form-check-label small">Required (shown as missing until ticked)</span></label>
    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1"<?= ($e['is_active'] ?? 1) ? ' checked' : '' ?>> <span class="form-check-label small">Active</span></label>
    <label class="form-label small" for="reqOrder">Order</label><input class="form-control mb-3" type="number" id="reqOrder" name="sort_order" min="1" max="999" value="<?= h((string) ($e['sort_order'] ?? 100)) ?>">
    <button class="btn btn-primary">Save requirement</button><?php if ($e): ?> <a class="btn btn-link" href="<?= url('docrequirements') ?>">Add instead</a><?php endif; ?>
  </form></div>
</div>
<?php layout_end();
