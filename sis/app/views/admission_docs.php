<?php
/**
 * v56: registration document checklist. Expects $docType (classification, '' = not chosen yet), $docChecked
 * (requirement id => anything, the ticked ones), $docEditable, and optionally $docSubmitted (who / when).
 * On the new-applicant form every active requirement is drawn; assets/admission-docs.js shows those that apply
 * to the chosen classification (the server keeps only those when saving).
 */
$docAll = EnrollmentDocs::requirements(true);
$docSubmitted = $docSubmitted ?? [];
$docRows = $docType === '' ? $docAll : array_values(array_filter($docAll, fn($r) => in_array($docType, $r['types'], true) || isset($docChecked[(int) $r['id']])));
?>
<fieldset class="mt-3 admission-docs" data-doc-list<?= $docType === '' ? ' data-doc-follow' : '' ?>>
  <legend class="h6 mb-1">Document checklist <span class="small text-muted fw-normal">· tick what the applicant submitted</span></legend>
  <?php if (Auth::can('settings.manage')): ?><p class="small mb-2"><a href="<?= url('docrequirements') ?>" target="_top">Change the document requirements</a></p><?php endif; ?>
  <p class="small text-muted mb-2" data-doc-choose<?= $docType === '' ? '' : ' hidden' ?>>Choose the student classification to see its requirements.</p>
  <ul class="list-unstyled mb-0 admission-doc-items">
  <?php foreach ($docRows as $req): $rid = (int) $req['id']; $done = isset($docChecked[$rid]); $who = $docSubmitted[$rid] ?? null; ?>
    <li class="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom" data-doc-row data-applies="<?= h(implode(',', $req['types'])) ?>"<?= $docType === '' ? ' hidden' : '' ?>>
      <label class="form-check mb-0 me-auto"><input class="form-check-input" type="checkbox" name="docs[]" value="<?= $rid ?>"<?= $done ? ' checked' : '' ?><?= $docEditable ? '' : ' disabled' ?>> <span class="form-check-label"><?= h($req['name']) ?></span></label>
      <?= (int) $req['is_required'] ? '<span class="badge text-bg-danger-subtle text-danger-emphasis">Required</span>' : '<span class="badge text-bg-light text-secondary border">Optional</span>' ?>
      <?php if ($done): ?><span class="badge text-bg-success">Submitted</span><?php if ($who): ?><span class="small text-muted"><?= h(date('M j, Y', strtotime($who['submitted_at']))) ?><?= $who['received_by_name'] !== '' ? ' · ' . h($who['received_by_name']) : '' ?></span><?php endif; ?>
      <?php elseif ((int) $req['is_required']): ?><span class="badge text-bg-warning">Missing</span><?php endif; ?>
      <?php if ($req['description']): ?><span class="small text-muted w-100"><?= h($req['description']) ?></span><?php endif; ?>
    </li>
  <?php endforeach; if (!$docRows): ?><li class="small text-muted">No document requirements apply.<?= Auth::can('settings.manage') ? ' Add them in Settings → Document Requirements.' : '' ?></li><?php endif; ?>
  </ul>
</fieldset>
<script src="assets/admission-docs.js?v=56" defer></script>
