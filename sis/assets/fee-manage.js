/* Registered once; runs again each time the SIS shows an Assessment page.
   Fee template → Manage fees pop-up, and its Add fee line / Add subject fee forms with searchable lists. */
(function(register){register('fee-manage',function(scope){
(function () {
  var manage = document.getElementById('tplManage');
  if (!manage) return;
  // Page layout code rearranges #main after this runs; dialogs kept in <body> stay modal
  var dialogs = Array.from(document.querySelectorAll('dialog.fee-dialog'));
  dialogs.forEach(function (d) { document.body.appendChild(d); });
  scope.cleanup(function () { dialogs.forEach(function (d) { if (d.open) d.close(); d.remove(); }); });
  function show(id) {
    document.querySelectorAll('dialog.fee-dialog[open]').forEach(function (d) { d.close(); });
    var d = document.getElementById(id); d.showModal();
    var first = d.querySelector('input[type=search]'); if (first) first.focus();
  }
  document.querySelectorAll('[data-open]').forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.open); }); });
  document.querySelectorAll('dialog.fee-dialog').forEach(function (d) {
    d.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
    d.querySelectorAll('[data-back]').forEach(function (b) { b.addEventListener('click', function () { show('tplManage'); }); });
  });

  // Searchable list: type to filter, click an item to choose it (the hidden input carries the value).
  document.querySelectorAll('.fee-pick').forEach(function (pick) {
    var search = pick.querySelector('input[type=search]'), value = pick.querySelector('input[type=hidden]');
    var items = Array.from(pick.querySelectorAll('[role=option]')), none = pick.querySelector('.fee-pick-none');
    function filter() {
      var words = search.value.toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
      items.forEach(function (it) {
        var text = it.textContent.toLowerCase();
        it.hidden = !words.every(function (w) { return text.indexOf(w) >= 0; });
        if (!it.hidden) shown++;
      });
      none.hidden = shown > 0;
    }
    function choose(it) {
      items.forEach(function (o) { o.setAttribute('aria-selected', o === it ? 'true' : 'false'); });
      value.value = it.dataset.value;
      search.value = it.querySelector('span').textContent;
      pick.classList.add('is-chosen');
      items.forEach(function (o) { o.hidden = false; }); none.hidden = true;
      var err = pick.closest('form').querySelector('.fee-pick-error'); if (err) err.hidden = true;
    }
    items.forEach(function (it) { it.addEventListener('click', function () { choose(it); }); });
    search.addEventListener('input', function () { value.value = ''; pick.classList.remove('is-chosen'); items.forEach(function (o) { o.setAttribute('aria-selected', 'false'); }); filter(); });
    search.addEventListener('keydown', function (e) {
      var visible = items.filter(function (o) { return !o.hidden; });
      if (e.key === 'Enter') {
        e.preventDefault();
        // Prefer a match on the item's own name over one found only in its details (category, units)
        var words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
        var best = visible.find(function (o) { var t = o.querySelector('span').textContent.toLowerCase(); return words.every(function (w) { return t.indexOf(w) >= 0; }); }) || visible[0];
        if (best) choose(best);
      }
      if (e.key === 'ArrowDown' && visible.length) { e.preventDefault(); visible[0].focus(); }
    });
    pick.querySelector('.fee-pick-list').addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      var visible = items.filter(function (o) { return !o.hidden; }), i = visible.indexOf(document.activeElement);
      e.preventDefault();
      if (e.key === 'ArrowUp' && i <= 0) { search.focus(); return; }
      var next = visible[i + (e.key === 'ArrowDown' ? 1 : -1)]; if (next) next.focus();
    });
  });
  document.querySelectorAll('form[data-fee-form]').forEach(function (form) {
    // Checked on the Save click, before the browser's own required-field check
    form.querySelector('button:not([type])').addEventListener('click', function (e) {
      var missing = Array.from(form.querySelectorAll('.fee-pick')).find(function (p) { return !p.querySelector('input[type=hidden]').value; });
      if (!missing) return;
      e.preventDefault();
      var err = form.querySelector('.fee-pick-error'); err.textContent = missing.dataset.required; err.hidden = false;
      missing.querySelector('input[type=search]').focus();
    });
  });
  // v56: Edit a fee line = the Add fee line form, filled in (saving the same fee replaces its amount)
  document.querySelectorAll('[data-edit-line]').forEach(function (b) { b.addEventListener('click', function () {
    show('tplAddLine');
    var d = document.getElementById('tplAddLine'), opt = d.querySelector('#lineFeeList [data-value="' + b.dataset.label + '"]');
    if (opt) opt.click();
    d.querySelector('#lineCharge').value = b.dataset.charge || '';
    d.querySelector('#lineAmount').value = b.dataset.amount || '';
    d.querySelector('#tplAddLineTitle').textContent = 'Edit fee line';
    d.querySelector('#lineAmount').focus();
  }); });
  if (new URLSearchParams(location.search).has('manage')) scope.frame(function () { show('tplManage'); });
})();

/* Add subject fee: the price already set on the Subject Fees page fills in the amount, with a live total. */
(function () {
  var form = document.querySelector('form[data-subject-fee]');
  if (!form) return;
  var known = JSON.parse(form.dataset.known || '{}');
  var subject = form.querySelector('input[name=subject_doc_id]'), fee = form.querySelector('input[name=label_id]');
  var rate = form.elements.rate, note = form.querySelector('[data-rate-note]'), total = form.querySelector('[data-total]');
  var plain = note.textContent, filled = '';
  var money = function (n) { return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function units() { var o = form.querySelector('#sfSubjectList [aria-selected=true]'); return o ? Number(o.dataset.units) : 0; }
  function showTotal() {
    var r = Number(String(rate.value).replace(/[₱,\s]/g, '')), u = units();
    total.hidden = !(u > 0 && r > 0);
    if (!total.hidden) total.textContent = u + ' units × ' + money(r) + ' = ' + money(u * r) + ' for each student who takes this subject';
  }
  function update() {
    // A subject with a price on only one fee: choose that fee for the user
    if (subject.value && !fee.value) {
      var mine = Object.keys(known).filter(function (k) { return k.split('|')[0] === subject.value; });
      if (mine.length === 1) { var opt = form.querySelector('#sfFeeList [data-value="' + known[mine[0]].label + '"]'); if (opt) opt.click(); return; }
    }
    var k = known[subject.value + '|' + fee.value];
    if (k && (rate.value === '' || rate.value === filled)) { rate.value = filled = k.rate; }
    else if (!k && rate.value === filled) { rate.value = filled = ''; }
    note.textContent = k ? (k.own ? 'This template already charges ₱' + k.rate + ' per unit; saving replaces it.' : 'Price from Subject Fees: ₱' + k.rate + ' per unit (all templates). Change it to charge this template differently.') : plain;
    showTotal();
  }
  form.querySelectorAll('[role=option]').forEach(function (o) { o.addEventListener('click', function () { setTimeout(update); }); });
  form.querySelectorAll('input[type=search]').forEach(function (s) { s.addEventListener('input', update); s.addEventListener('keydown', function (e) { if (e.key === 'Enter') setTimeout(update); }); });
  rate.addEventListener('input', showTotal);
})();

/* v56 Subject Fees: live Total = Units × Price per Unit while typing the price. */
(function () {
  var form = document.querySelector('form[data-price-form]');
  if (!form) return;
  var rate = form.elements.rate, out = form.querySelector('[data-price-total]'), pick = form.querySelector('[data-units-select]'), fixed = form.querySelector('input[data-units]');
  var money = function (n) { return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var basis = form.querySelector('[data-basis]');
  function units() {
    var src = fixed || (pick && pick.selectedOptions[0]); if (!src || !src.dataset) return 0;
    var b = basis ? basis.value : 'ALL'; // v56.2: all, lecture or laboratory units
    return Number(b === 'LEC' ? src.dataset.lec : b === 'LAB' ? src.dataset.lab : (fixed ? fixed.value : src.dataset.units)) || 0;
  }
  function show() {
    var raw = String(rate.value).replace(/[₱,\s]/g, ''), r = raw === '' ? 0 : Number(raw), u = units();
    if (!isFinite(r) || r < 0) { out.textContent = 'Enter ₱0.00 or more.'; out.classList.add('text-danger'); return; }
    out.classList.remove('text-danger');
    out.textContent = u ? 'Total: ' + u + ' units × ' + money(r) + ' = ' + money(Math.round(u * r * 100) / 100) : 'Choose the subject to see the total.';
  }
  rate.addEventListener('input', show); if (pick) pick.addEventListener('change', show); if (basis) basis.addEventListener('change', show); show();
})();

/* v56 Fee template → tick the Fee Management prices this template charges. */
(function () {
  var box = document.querySelector('[data-tick-list]');
  if (!box) return;
  var search = box.querySelector('[data-tick-search]'), rows = Array.from(box.querySelectorAll('[data-tick-row]')), count = box.querySelector('[data-tick-count]');
  function counted() { count.textContent = box.querySelectorAll('input[name="subject_fee_ids[]"]:checked').length + ' ticked'; }
  if (search) search.addEventListener('input', function () {
    var words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
    rows.forEach(function (r) { var t = r.textContent.toLowerCase(); r.hidden = !words.every(function (w) { return t.indexOf(w) >= 0; }); });
  });
  box.querySelectorAll('[data-tick-all]').forEach(function (b) { b.addEventListener('click', function () {
    var on = b.dataset.tickAll === '1';
    rows.forEach(function (r) { if (!r.hidden) r.querySelector('input').checked = on; }); counted();
  }); });
  box.addEventListener('change', counted); counted();
})();

/* v56.1 Manage fees: one list of every fee. Tick a fee to charge it; its price appears. Live subject totals and counts. */
(function () {
  var form = document.querySelector('form[data-template-fees]');
  if (!form) return;
  var rows = Array.from(form.querySelectorAll('[data-tf-row]'));
  var search = form.querySelector('[data-tf-search]'), only = form.querySelector('[data-tf-only]'), count = form.querySelector('[data-tf-count]');
  var money = function (n) { return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function box(row) { return row.querySelector('input[type=checkbox]'); }
  function paint(row) {
    var on = box(row).checked;
    row.classList.toggle('is-on', on);
    row.querySelectorAll('[data-tf-price]').forEach(function (el) { el.disabled = !on; });
    var total = row.querySelector('[data-tf-total]');
    if (total) {
      var raw = String((row.querySelector('input[data-tf-price]') || {}).value || '').replace(/[₱,\s]/g, '');
      var price = raw === '' ? Number(row.dataset.normal || 0) : Number(raw), units = Number(row.dataset.units || 0);
      total.textContent = on && isFinite(price) ? money(Math.round(units * price * 100) / 100) : '';
    }
  }
  function counted() {
    var g = form.querySelectorAll('[data-tf-group=gen] [data-tf-row].is-on').length, s = form.querySelectorAll('[data-tf-group=sub] [data-tf-row].is-on').length;
    count.textContent = g + ' general fee(s) and ' + s + ' subject fee(s) ticked';
  }
  function filter() {
    var words = (search.value || '').toLowerCase().split(/\s+/).filter(Boolean);
    rows.forEach(function (row) { var t = row.textContent.toLowerCase();
      row.hidden = !words.every(function (w) { return t.indexOf(w) >= 0; }) || (only.checked && !box(row).checked); });
  }
  rows.forEach(function (row) {
    box(row).addEventListener('change', function () { paint(row); counted(); if (box(row).checked) { var p = row.querySelector('input[data-tf-price]'); if (p) p.focus(); } });
    row.querySelectorAll('input[data-tf-price]').forEach(function (el) { el.addEventListener('input', function () { paint(row); }); });
    paint(row);
  });
  form.querySelectorAll('[data-tf-all]').forEach(function (b) { b.addEventListener('click', function () {
    form.querySelectorAll('[data-tf-group=' + b.dataset.tfAll + '] [data-tf-row]').forEach(function (row) { if (row.hidden) return; box(row).checked = b.dataset.on === '1'; paint(row); });
    counted();
  }); });
  search.addEventListener('input', filter); only.addEventListener('change', filter);
  // Disabled boxes are not sent; enable them just before saving so ticked rows keep their typed prices.
  form.addEventListener('submit', function () { rows.forEach(function (row) { if (box(row).checked) row.querySelectorAll('[data-tf-price]').forEach(function (el) { el.disabled = false; }); }); });
  counted();
})();

},75);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
