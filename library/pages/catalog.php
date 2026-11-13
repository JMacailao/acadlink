<?php
/** Catalog: search titles; a title's details, its copies (each with a barcode) and holds; add and edit titles and copies. */
declare(strict_types=1);
/** @var bool $isPost */
$edit = Auth::can('library.catalog.edit');
$id = (int) ($_GET['id'] ?? 0);
if ($isPost) {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'title') {
        $id = Library::saveTitle(((int) ($_POST['id'] ?? 0)) ?: null, $_POST);
        if (($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) Library::saveCover($id, $_FILES['cover']);
        elseif (!empty($_POST['remove_cover'])) Library::deleteCover($id);
        flash('success', 'Title saved.'); redirect('catalog', ['id' => $id]);
    }
    if ($a === 'copies') { $codes = Library::addCopies($id, (int) ($_POST['count'] ?? 1), $_POST); flash('success', count($codes) . ' copy(ies) added: ' . implode(', ', $codes) . '. Print their labels in Barcode Labels.'); redirect('catalog', ['id' => $id]); }
    if ($a === 'copy') { Library::updateCopy((int) ($_POST['copy'] ?? 0), $_POST); flash('success', 'Copy updated.'); redirect('catalog', ['id' => $id]); }
    if ($a === 'hold') {
        $b = Library::borrowerByCode((string) ($_POST['who'] ?? '')) ?: throw new AppError('No student or teacher has that ID.');
        Library::placeHold($id, $b['type'], $b['ref']);
        flash('success', 'Hold placed for ' . $b['name'] . '.');
        redirect('catalog', ['id' => $id]);
    }
    throw new AppError('Unknown action.');
}
$materials = ['BOOK' => 'Book', 'REFERENCE' => 'Reference', 'PERIODICAL' => 'Periodical', 'THESIS' => 'Thesis', 'AV' => 'Audio-visual', 'OTHER' => 'Other'];
$peso = fn(int $c) => '₱' . Money::fmt($c);
if ($id || isset($_GET['new'])):
    $t = $id ? (Library::title($id) ?: throw new AppError('Title not found.')) : null;
    $showForm = $edit && (!$t || isset($_GET['edit']));
    layout_start($t ? $t['title'] : 'New title');
    if (!$t || $showForm) lib_page_head('book', $t ? 'Edit title' : 'New title', $t ? $t['title'] : 'Add a book or other material to the catalog.');
?>
<div class="mb-2"><a class="btn btn-sm btn-outline-secondary" href="<?= url('catalog') ?>"><i class="bi bi-arrow-left me-1"></i>Catalog</a>
  <?php if ($t && $edit && !$showForm): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('catalog', ['id' => $id, 'edit' => 1]) ?>">Edit title</a> <a class="btn btn-sm btn-outline-secondary" href="<?= url('labels', ['title' => $id]) ?>"><i class="bi bi-upc me-1"></i>Print labels</a><?php endif; ?></div>
<?php if ($showForm): $v = fn(string $k) => h((string) ($t[$k] ?? '')); ?>
<form method="post" enctype="multipart/form-data" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="title"><input type="hidden" name="id" value="<?= (int) $id ?>">
  <div class="row g-2">
    <div class="col-md-8"><label class="form-label small" for="tT">Title</label><input class="form-control" id="tT" name="title" required maxlength="255" value="<?= $v('title') ?>"></div>
    <div class="col-md-4"><label class="form-label small" for="tM">Type</label><select class="form-select" id="tM" name="material"><?php foreach ($materials as $k => $m): ?><option value="<?= $k ?>"<?= ($t['material'] ?? 'BOOK') === $k ? ' selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></div>
    <div class="col-md-8"><label class="form-label small" for="tS">Subtitle</label><input class="form-control" id="tS" name="subtitle" maxlength="255" value="<?= $v('subtitle') ?>"></div>
    <div class="col-md-4"><label class="form-label small" for="tI">ISBN</label><input class="form-control font-monospace" id="tI" name="isbn" maxlength="20" value="<?= $v('isbn') ?>"></div>
    <div class="col-md-6"><label class="form-label small" for="tA">Author(s)</label><input class="form-control" id="tA" name="authors" maxlength="255" value="<?= $v('authors') ?>"></div>
    <div class="col-md-6"><label class="form-label small" for="tP">Publisher</label><input class="form-control" id="tP" name="publisher" maxlength="150" value="<?= $v('publisher') ?>"></div>
    <div class="col-4 col-md-2"><label class="form-label small" for="tY">Year</label><input class="form-control" id="tY" name="pub_year" inputmode="numeric" maxlength="4" value="<?= $v('pub_year') ?>"></div>
    <div class="col-8 col-md-2"><label class="form-label small" for="tE">Edition</label><input class="form-control" id="tE" name="edition" maxlength="40" value="<?= $v('edition') ?>"></div>
    <div class="col-md-3"><label class="form-label small" for="tSub">Subject</label><input class="form-control" id="tSub" name="subject" maxlength="120" value="<?= $v('subject') ?>"></div>
    <div class="col-md-3"><label class="form-label small" for="tC">Call number</label><input class="form-control font-monospace" id="tC" name="call_no" maxlength="60" value="<?= $v('call_no') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="tL">Language</label><input class="form-control" id="tL" name="language" maxlength="40" value="<?= $v('language') ?>"></div>
    <div class="col-12"><label class="form-label small" for="tCv">Cover (PNG, JPEG or WebP, up to 5 MB; shrunk to 600 px)</label>
      <div class="d-flex gap-3 align-items-center"><?= $t ? Library::coverHtml((int) $id, (string) $t['title'], $t['cover_ver'], 'sm') : '' ?><input class="form-control" type="file" id="tCv" name="cover" accept="image/png,image/jpeg,image/webp,image/gif">
      <?php if ($t && $t['cover_ver']): ?><label class="form-check small text-nowrap"><input class="form-check-input" type="checkbox" name="remove_cover" value="1"> Remove cover</label><?php endif; ?></div></div>
    <div class="col-12"><label class="form-label small" for="tD">Description</label><textarea class="form-control" id="tD" name="description" rows="2" maxlength="4000"><?= $v('description') ?></textarea></div>
  </div>
  <div class="mt-2 d-flex gap-2"><button class="btn btn-primary">Save title</button><a class="btn btn-link" href="<?= url('catalog', $t ? ['id' => $id] : []) ?>">Cancel</a></div>
</form>
<?php else: ?>
<section class="lib-card d-flex gap-3 align-items-start"><?= Library::coverHtml((int) $id, (string) $t['title'], $t['cover_ver'], 'lg') ?><div class="flex-grow-1 min-w-0"><h1 class="h3 fw-bold mb-1"><?= h($t['title']) ?><?= $t['subtitle'] ? '<small class="text-muted fw-normal">: ' . h($t['subtitle']) . '</small>' : '' ?></h1>
  <p class="mb-1"><?= h($t['authors']) ?></p>
  <p class="small text-muted mb-0"><?= h(implode(' · ', array_filter([$materials[$t['material']] ?? '', $t['publisher'], $t['pub_year'], $t['edition'], $t['subject'], $t['call_no'] ? 'Call no. ' . $t['call_no'] : null, $t['isbn'] ? 'ISBN ' . $t['isbn'] : null]))) ?></p>
  <?php if ($t['description']): ?><p class="small mt-2 mb-0"><?= nl2br(h($t['description'])) ?></p><?php endif; ?></div></section>
<div class="row g-3">
  <div class="col-xl-8"><section class="form-section"><h3 class="h6">Copies</h3><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Barcode</th><th>Status</th><th>Location</th><th class="text-end">Price</th><?php if ($edit): ?><th></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($t['copies'] as $c): ?><tr>
      <td class="font-monospace"><?= h($c['barcode']) ?><?= (int) $c['reference_only'] ? ' <span class="badge text-bg-info">Library use only</span>' : '' ?></td>
      <td><?= h(Library::COPY_STATUS[$c['status']]) ?><?= $c['status'] === 'ON_LOAN' ? '<br><small class="text-muted">' . h((string) $c['borrower_name']) . ' · due ' . h(date('M j', strtotime((string) $c['due_on']))) . '</small>' : '' ?></td>
      <td><?= h((string) $c['location']) ?></td><td class="text-end"><?= $peso((int) $c['price_cents']) ?></td>
      <?php if ($edit): ?><td class="text-end"><details><summary class="small">Change</summary>
        <form method="post" class="mt-1 text-start" style="min-width:220px"><?= csrf_field() ?><input type="hidden" name="action" value="copy"><input type="hidden" name="copy" value="<?= (int) $c['id'] ?>">
          <select class="form-select form-select-sm mb-1" name="status" aria-label="Status"><?php foreach (Library::COPY_STATUS as $k => $s): if (in_array($k, ['ON_LOAN', 'ON_HOLD_SHELF'], true) && $c['status'] !== $k) continue; ?><option value="<?= $k ?>"<?= $c['status'] === $k ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
          <input class="form-control form-control-sm mb-1" name="location" maxlength="80" value="<?= h((string) $c['location']) ?>" placeholder="Location / shelf" aria-label="Location">
          <input class="form-control form-control-sm mb-1" name="price" inputmode="decimal" value="<?= Money::dec((int) $c['price_cents']) ?>" aria-label="Replacement price">
          <label class="form-check small"><input class="form-check-input" type="checkbox" name="reference_only" value="1"<?= (int) $c['reference_only'] ? ' checked' : '' ?>> Library use only</label>
          <input class="form-control form-control-sm mb-1" name="notes" maxlength="255" value="<?= h((string) $c['notes']) ?>" placeholder="Notes" aria-label="Notes">
          <button class="btn btn-sm btn-primary">Save</button></form></details></td><?php endif; ?>
    </tr><?php endforeach; if (!$t['copies']): ?><tr><td colspan="5" class="text-muted text-center">No copies yet.</td></tr><?php endif; ?>
    </tbody></table></div></section></div>
  <div class="col-xl-4">
    <?php if ($edit): ?><form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="copies">
      <h3 class="h6">Add copies</h3>
      <div class="row g-2"><div class="col-4"><label class="form-label small" for="cN">How many</label><input class="form-control form-control-sm" type="number" id="cN" name="count" min="1" max="200" value="1"></div>
        <div class="col-8"><label class="form-label small" for="cP">Replacement price ₱</label><input class="form-control form-control-sm" id="cP" name="price" inputmode="decimal" value="0.00"></div>
        <div class="col-12"><label class="form-label small" for="cL">Location / shelf</label><input class="form-control form-control-sm" id="cL" name="location" maxlength="80"></div>
        <div class="col-12"><label class="form-label small" for="cB">Own barcode (only for 1 copy; blank = automatic)</label><input class="form-control form-control-sm font-monospace" id="cB" name="barcode" maxlength="40" autocomplete="off"></div>
        <div class="col-12"><label class="form-check small"><input class="form-check-input" type="checkbox" name="reference_only" value="1"<?= $t['material'] === 'REFERENCE' ? ' checked' : '' ?>> Library use only (can't be borrowed)</label></div></div>
      <button class="btn btn-sm btn-primary mt-2">Add copies</button></form><?php endif; ?>
    <section class="form-section"><h3 class="h6">Holds</h3>
      <?php foreach ($t['holds'] as $hd): ?><div class="small py-1 border-bottom"><?= h($hd['borrower_name']) ?> · <?= $hd['status'] === 'READY' ? '<span class="badge text-bg-success">Ready</span>' : 'Waiting since ' . h(date('M j', strtotime($hd['placed_at']))) ?></div><?php endforeach; ?>
      <?php if (!$t['holds']): ?><p class="small text-muted">No one is waiting.</p><?php endif; ?>
      <?php if (Auth::can('library.circulate')): ?><form method="post" class="d-flex gap-1 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="hold"><input class="form-control form-control-sm" name="who" required placeholder="Student or Teacher ID" aria-label="Borrower ID"><button class="btn btn-sm btn-outline-primary">Place hold</button></form><?php endif; ?>
    </section>
  </div>
</div>
<?php endif;
    layout_end(); return;
endif;
$q = trim((string) ($_GET['q'] ?? '')); $mat = isset($materials[$_GET['material'] ?? '']) ? $_GET['material'] : '';
$rows = Library::search($q, $mat, 200);
layout_start('Catalog');
lib_page_head('book', 'Catalog', 'Titles and their copies. Search by title, author, subject, ISBN, call number or barcode.');
lib_tabs(array_filter(['catalog' => 'Titles', 'acquisitions' => 'Acquisitions', 'stocktake' => 'Stocktake', 'labels' => 'Barcode labels'], fn($r) => Auth::can(...ROUTES[$r][1]), ARRAY_FILTER_USE_KEY), 'catalog');
?>
<form method="get" class="d-flex flex-wrap gap-2 mb-2"><input type="hidden" name="r" value="catalog">
  <input class="form-control" style="max-width:360px" name="q" value="<?= h($q) ?>" placeholder="Title, author, subject, ISBN, call number or barcode" aria-label="Search the catalog" autofocus>
  <select class="form-select" style="max-width:180px" name="material" aria-label="Type"><option value="">All types</option><?php foreach ($materials as $k => $m): ?><option value="<?= $k ?>"<?= $mat === $k ? ' selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select>
  <button class="btn btn-outline-secondary">Search</button>
  <?php if ($edit): ?><a class="btn btn-primary ms-auto" href="<?= url('catalog', ['new' => 1]) ?>"><i class="bi bi-plus-lg me-1"></i>Add title</a><?php endif; ?></form>
<div class="table-responsive form-section"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Title</th><th>Author(s)</th><th>Call no.</th><th class="text-end">Copies</th><th class="text-end">Available</th><th class="text-end">Waiting</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><span class="lib-book"><?= Library::coverHtml((int) $r['id'], $r['title'], $r['cover_ver'], 'xs') ?><a href="<?= url('catalog', ['id' => $r['id']]) ?>"><?= h($r['title']) ?></a></span><?= $r['pub_year'] ? ' <small class="text-muted">(' . (int) $r['pub_year'] . ')</small>' : '' ?></td><td><?= h($r['authors']) ?></td><td class="font-monospace small"><?= h((string) $r['call_no']) ?></td>
    <td class="text-end"><?= (int) $r['copies'] ?></td><td class="text-end <?= (int) $r['available'] ? 'text-success fw-semibold' : 'text-muted' ?>"><?= (int) $r['available'] ?><?= (int) $r['reference_copies'] ? ' <small class="text-muted">+' . (int) $r['reference_copies'] . ' ref.</small>' : '' ?></td><td class="text-end"><?= (int) $r['waiting'] ?: '' ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= $q !== '' ? 'Nothing matches “' . h($q) . '”.' : 'The catalog is empty. Add the first title.' ?></td></tr><?php endif; ?>
  </tbody></table></div>
<?php layout_end();
