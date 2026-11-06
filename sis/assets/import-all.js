/* v56 Import All preview: Select all / Deselect all, bulk action for ticked rows, filter, live totals and REPLACE confirmation. */
(function (register) { register('import-all', function (scope) {
  var form = document.querySelector('form[data-import-all]');
  if (!form) return;
  var rows = Array.from(form.querySelectorAll('[data-ia-row]'));
  var replaceBox = form.querySelector('[data-ia-replace]'), replaceInput = form.querySelector('#iaReplace');
  function box(row) { return row.querySelector('input[type=checkbox]'); }
  function act(row) { return row.querySelector('[data-ia-act]'); }
  function count() {
    var n = { selected: 0, add: 0, skip: 0, update: 0, replace: 0 };
    rows.forEach(function (row) {
      var b = box(row), a = act(row);
      if (!b || b.disabled || !b.checked || !a) { n.skip++; return; }
      n.selected++; var v = a.value.toLowerCase(); n[v] = (n[v] || 0) + 1;
    });
    Object.keys(n).forEach(function (k) { var el = form.querySelector('[data-n="' + k + '"]'); if (el) el.textContent = n[k]; });
    replaceBox.hidden = !n.replace;
    replaceInput.required = n.replace > 0;
  }
  form.querySelectorAll('[data-ia-all]').forEach(function (b) { scope.on(b, 'click', function () {
    var on = b.dataset.iaAll === '1';
    rows.forEach(function (row) { var c = box(row); if (c && !c.disabled && !row.hidden) c.checked = on; }); count();
  }); });
  scope.on(form.querySelector('[data-ia-bulk]'), 'click', function () {
    var v = form.querySelector('#iaBulk').value; if (!v) return;
    var missed = 0;
    rows.forEach(function (row) { var c = box(row), a = act(row); if (!c || c.disabled || !c.checked || !a) return;
      if (Array.from(a.options).some(function (o) { return o.value === v; })) a.value = v; else missed++; });
    count();
    if (missed) alertLike(missed + ' ticked row(s) cannot use this action (for example Add on an existing subject) and kept their action.');
  });
  function alertLike(text) { var p = form.querySelector('[data-ia-note]'); if (!p) { p = document.createElement('p'); p.dataset.iaNote = '1'; p.className = 'small text-warning-emphasis mb-2'; form.querySelector('[role=toolbar]').after(p); } p.textContent = text; }
  scope.on(form.querySelector('[data-ia-show]'), 'change', function (e) { var v = e.target.value; rows.forEach(function (row) { row.hidden = !!v && row.dataset.status !== v; }); });
  scope.on(form, 'change', count);
  count();
}, 80); })(window.SISLifecycle ? window.SISLifecycle.register : function () { (window.SISModuleQueue = window.SISModuleQueue || []).push(Array.from(arguments)); });
