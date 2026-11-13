/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('app',function(scope){
// SIS — small progressive enhancements (the server validates everything again).
scope.on(document,'submit', function (e) {
  var f = e.target;
  if (e.defaultPrevented) return;
  if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) { e.preventDefault(); return; }
  // Prevent double submission (the server also blocks duplicates with a one-time token)
  if (f.dataset.submitted === '1') { e.preventDefault(); return; }
  var button=e.submitter, label=(button ? button.textContent : '').trim();
  // File responses do not navigate; leave their controls available after the download.
  if (/download|export|print/i.test(label) || /^(download|export)/i.test((f.elements.action && f.elements.action.value)||'')) return;
  if (window.SISActivity) {
    var text=f.dataset.loadingLabel;
    if(!text) {
      var route=document.body.dataset.route;
      text= /sign in/i.test(label)?'Signing in…':/sign out/i.test(label)?'Signing out…':/void/i.test(label)?'Voiding entry…':/post/i.test(label)?'Posting entry…':/payment|pay\b/i.test(label)?'Recording payment…':/confirm|enroll/i.test(label)?'Checking and confirming enrollment…':/delete|remove/i.test(label)?'Removing record…':/preview|check/i.test(label)?'Checking records…':/report|generate/i.test(label)?'Preparing report…':/search|filter|load/i.test(label)?'Loading results…':(f.method.toLowerCase()==='get'?'Loading results…':'Saving changes…');
    }
    window.SISActivity.begin(text);
  }
  if ((f.getAttribute('method') || 'get').toLowerCase() === 'post') {
    f.dataset.submitted = '1';
    // Keep the clicked button's name/value: disabled buttons are not sent
    if (e.submitter && e.submitter.name) {
      var h = document.createElement('input'); h.type = 'hidden'; h.dataset.submitCopy='1'; h.name = e.submitter.name; h.value = e.submitter.value; f.appendChild(h);
    }
    f.querySelectorAll('button[type=submit],button:not([type])').forEach(function (b) { if(b.disabled)return;b.dataset.activityOriginal=b.innerHTML;b.disabled = true; });
    if(button){button.setAttribute('aria-busy','true');button.textContent=f.dataset.loadingLabel||(/preview/i.test(label)?'Checking accounts…':/sign in/i.test(label)?'Signing in…':/sign out/i.test(label)?'Signing out…':/void/i.test(label)?'Voiding…':/post/i.test(label)?'Posting…':/pay/i.test(label)?'Recording payment…':'Saving…');}
  }
});

// Live text filter for tables: <input data-filter="#tableId">
scope.on(document,'input', function (e) {
  var sel = e.target.dataset && e.target.dataset.filter;
  if (!sel) return;
  var q = e.target.value.toLowerCase();
  document.querySelectorAll(sel + ' tbody tr').forEach(function (tr) {
    if (tr.classList.contains('rg-head')) return;
    tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
  });
});

// Curriculum select follows program select: <select data-curricula='{"programId":[[id,label],…]}' data-program="#program_id">
document.querySelectorAll('select[data-curricula]').forEach(function (cur) {
  var map = JSON.parse(cur.dataset.curricula);
  var prog = document.querySelector(cur.dataset.program);
  if (!prog) return;
  function fill() {
    var keep = cur.value;
    cur.innerHTML = '<option value="">— choose —</option>';
    (map[prog.value] || []).forEach(function (c) {
      var o = document.createElement('option'); o.value = c[0]; o.textContent = c[1];
      if (String(c[0]) === keep) o.selected = true;
      cur.appendChild(o);
    });
  }
  prog.addEventListener('change', fill); fill();
});

// Print buttons
document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

// Benefit type: hide value when FULL_WAIVER
document.querySelectorAll('select[name=benefit_type]').forEach(function (s) {
  function sync() { var v = document.getElementById('value-wrap'); if (v) v.style.display = s.value === 'FULL_WAIVER' ? 'none' : ''; }
  s.addEventListener('change', sync); sync();
});

// <select data-autosubmit> submits its form on change (no inline handlers: CSP)
document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
  el.addEventListener('change', function () { if(!el.closest('[data-record-filters]'))el.form.requestSubmit(); });
});

// "Tick all" checkbox on the grading-system import list
var checkAll = document.getElementById('checkAll');
if (checkAll) checkAll.addEventListener('change', function () {
  document.querySelectorAll('input[name="numbers[]"]').forEach(function (c) { c.checked = checkAll.checked; });
});

// Press "/" anywhere (when not typing) to jump to the search box at the top
scope.on(document,'keydown', function (e) {
  if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
  var t = e.target;
  if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
  var box = document.getElementById('globalSearch');
  if (box && box.offsetParent !== null) { e.preventDefault(); box.focus(); box.select(); }
  else if (box) { if(window.SISNavigation)window.SISNavigation.navigate('index.php?r=search');else window.location.href='index.php?r=search'; }
});

// Buttons with data-frame="url" open that page in a pop-up (New registration, View assessment).
// Without JavaScript they are ordinary links to the full page.
(function () {
  var modalEl = document.getElementById('frameModal');
  if (!modalEl || !window.bootstrap) return;
  var frame = modalEl.querySelector('iframe'), dialog = modalEl.querySelector('.modal-dialog'), loading = modalEl.querySelector('.frame-loading');
  scope.on(document,'click', function (e) {
    var b = e.target.closest('[data-frame]');
    if (!b || e.ctrlKey || e.metaKey || e.shiftKey) return; // new tab still works
    e.preventDefault();
    modalEl.querySelector('.modal-title').textContent = b.dataset.frameTitle || '';
    frame.title = b.dataset.frameTitle || '';
    dialog.classList.remove('modal-lg', 'modal-xl');
    dialog.classList.add(b.dataset.frameSize === 'lg' ? 'modal-lg' : 'modal-xl');
    loading.hidden = false; frame.style.visibility = 'hidden';
    frame.src = b.dataset.frame;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  });
  scope.on(frame,'load', function () { loading.hidden = true; frame.style.visibility = ''; });
  scope.on(modalEl,'hidden.bs.modal', function () { frame.src = 'about:blank'; });
})();

// Grid screens (Cashier, Registration, Admission): click a row to select it; double-click or Enter runs
// the default action. Toolbar items with data-row act on the selected row:
//   data-row="frame" data-row-key="x"  → opens tr.dataset.x in the pop-up
//   data-row="go"    data-row-key="x"  → goes to tr.dataset.x
//   data-row="modal"                   → opens its Bootstrap modal (data-bs-target)
//   data-row-if="flag"                 → enabled only when tr.dataset.flag === '1'
// Elements with data-row-fill="x" get tr.dataset.x (inputs: value, others: text).
document.querySelectorAll('table[data-select]').forEach(function (grid) {
  // The fixed header moves row actions outside .or-screen. Bind within the
  // whole page workspace so selection still reaches its docked toolbar.
  var gridScope = grid.closest('#main') || grid.closest('.or-screen') || document;
  var items = gridScope.querySelectorAll('[data-row]');
  function select(tr) {
    grid.querySelectorAll('tbody tr.selected').forEach(function (r) { r.classList.remove('selected'); r.setAttribute('aria-selected', 'false'); });
    tr.classList.add('selected'); tr.setAttribute('aria-selected', 'true');
    items.forEach(function (b) {
      var ok = !b.dataset.rowIf || tr.dataset[b.dataset.rowIf] === '1';
      if (b.dataset.row === 'frame') b.dataset.frame = tr.dataset[b.dataset.rowKey] || '';
      if (b.dataset.row === 'frame' && tr.dataset.title) b.dataset.frameTitle = tr.dataset.title;
      if (b.dataset.row === 'go') b.dataset.go = tr.dataset[b.dataset.rowKey] || '';
      if ('disabled' in b && b.tagName === 'BUTTON') b.disabled = !ok;
      b.classList.toggle('disabled-link', !ok);
      b.setAttribute('aria-disabled', ok ? 'false' : 'true');
    });
    document.querySelectorAll('[data-row-fill]').forEach(function (el) {
      var v = tr.dataset[el.dataset.rowFill] || '';
      if (el.tagName === 'INPUT') el.value = v; else el.textContent = v;
    });
  }
  function runDefault() { var d = gridScope.querySelector('[data-row-default]'); if (d && !d.disabled) d.click(); }
  grid.addEventListener('click', function (e) { var tr = e.target.closest('tbody tr[data-id]'); if (tr) select(tr); });
  grid.addEventListener('dblclick', function (e) { var tr = e.target.closest('tbody tr[data-id]'); if (tr) { select(tr); runDefault(); } });
  grid.addEventListener('keydown', function (e) {
    var tr = e.target.closest('tbody tr[data-id]'); if (!tr) return;
    if (e.key === 'Enter') { select(tr); runDefault(); }
    var next = e.key === 'ArrowDown' ? tr.nextElementSibling : e.key === 'ArrowUp' ? tr.previousElementSibling : null;
    if (next && next.dataset.id) { e.preventDefault(); next.focus(); select(next); }
  });
  items.forEach(function (b) {
    if (b.dataset.row === 'go') b.addEventListener('click', function (e) { e.preventDefault(); if (b.dataset.go && b.getAttribute('aria-disabled') !== 'true') window.SISNavigation?window.SISNavigation.navigate(b.dataset.go):location.assign(b.dataset.go); });
    if (b.tagName === 'A') b.addEventListener('click', function (e) { if (b.getAttribute('aria-disabled') === 'true' || (b.dataset.row === 'frame' && !b.dataset.frame)) { e.preventDefault(); e.stopImmediatePropagation(); } }, true);
  });
});

// A pop-up page that saved something marks <body data-changed="1">: closing the pop-up reloads the list
(function () {
  var modalEl = document.getElementById('frameModal'); if (!modalEl) return;
  var frame = modalEl.querySelector('iframe'), changed = false;
  scope.on(frame,'load', function () { try { if (frame.contentDocument && frame.contentDocument.body && frame.contentDocument.body.dataset.changed === '1') changed = true; } catch (e) {} });
  scope.on(modalEl,'hidden.bs.modal', function () { if (changed) { changed = false; if(window.SISNavigation)window.SISNavigation.refresh();else location.reload(); } });
})();

// Collapsible explorer groups (sidebar) and right-side panels: click the blue bar; remembered per browser.
(function () {
  function store(key, val) { try { if (val === undefined) return localStorage.getItem(key); localStorage.setItem(key, val); } catch (e) { return null; } }
  function wire(bar, target, key) {
    function set(open) {
      target.classList.toggle('is-collapsed', !open);
      bar.classList.toggle('is-collapsed', !open);
      bar.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (store(key) === '0' && !target.querySelector('.active')) set(false);
    scope.on(bar,'click', function () { var open = bar.getAttribute('aria-expanded') === 'false'; set(open); store(key, open ? '1' : '0'); });
  }
  document.querySelectorAll('[data-collapse]').forEach(function (bar) {
    var t = document.getElementById(bar.dataset.collapse); if (t) wire(bar, t, 'sis.' + bar.dataset.collapse);
  });
  document.querySelectorAll('.or-panel').forEach(function (panel, i) {
    // The Actions explorer binds its own accessible collapse controls after moving .or-side.
    // Avoid a second click handler on those panels so a collapsed heading can reopen reliably.
    if (panel.closest('.or-side')) return;
    var bar = panel.querySelector('.or-panel-title'); if (!bar) return;
    bar.setAttribute('role', 'button'); bar.setAttribute('tabindex', '0');
    var ic = document.createElement('i'); ic.className = 'bi bi-chevron-double-up pc'; ic.setAttribute('aria-hidden', 'true'); bar.appendChild(ic);
    scope.on(bar,'keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); bar.click(); } });
    wire(bar, panel, 'sis.panel.' + (document.body.dataset.route || location.search) + '.' + i);
  });
})();

// Filter Preferences: a filter is on only while its box is ticked (unticked controls are disabled, so not sent).
document.querySelectorAll('[data-fp]').forEach(function (box) {
  var ctl = box.closest('.fp-row').querySelector('.fp-ctl');
  function sync() {
    ctl.querySelectorAll('input, select').forEach(function (el) { el.disabled = !box.checked; });
    box.closest('.fp-row').classList.toggle('is-off', !box.checked);
  }
  if (!box.disabled) box.addEventListener('change', function () { sync(); if (box.checked) { var f = ctl.querySelector('input, select'); if (f) f.focus(); } });
  sync();
});


// A button that puts a value into a field (e.g. "Full balance" on the payment form)
scope.on(document,'click', function (e) {
  var b = e.target.closest('[data-fill]'); if (!b) return;
  var f = document.querySelector(b.dataset.fill); if (!f) return;
  f.value = b.dataset.fillValue || ''; f.focus();
});

// Never show an old copy of a page: when the browser restores one with Back / Forward, load it fresh
// (balances, payments and statuses must always be current, also when printing)
scope.on(window,'pageshow', function (e) { if (e.persisted && !window.SISNavigation) location.reload(); });

// Cashier: choosing an O.R. series puts its next number in the O.R. No. box (unless the cashier typed another one)
document.querySelectorAll('select[data-or-series]').forEach(function (sel) {
  var input = document.querySelector(sel.dataset.orSeries), hidden = sel.form && sel.form.querySelector('[data-or-suggested]');
  if (!input || !hidden) return;
  sel.addEventListener('change', function () {
    var next = (sel.selectedOptions[0] && sel.selectedOptions[0].dataset.next) || '';
    if (input.value === '' || input.value === hidden.value) input.value = next;
    hidden.value = next;
  });
});

// data-frame-close: closes the pop-up this page is shown in (or goes to its link when opened as a full page)
scope.on(document,'click', function (e) {
  var b = e.target.closest('[data-frame-close]'); if (!b) return;
  try {
    var m = window.parent !== window && window.parent.document.getElementById('frameModal');
    if (m && window.parent.bootstrap) { e.preventDefault(); window.parent.bootstrap.Modal.getOrCreateInstance(m).hide(); }
  } catch (err) { /* not in a pop-up: follow the link */ }
});

// View-only access to this module: forms that change records are hidden (the server refuses them anyway).
if (document.getElementById('sisViewOnly')) {
  Array.prototype.forEach.call(document.querySelectorAll('#main form'), function (f) {
    if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    f.hidden = true;
    var modal = f.closest('.modal');
    if (modal && modal.id) Array.prototype.forEach.call(document.querySelectorAll('[data-bs-target="#' + modal.id + '"], [href="#' + modal.id + '"]'), function (b) { b.hidden = true; });
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-list-add], a[href*="new=1"], [data-theme-edit]'), function (b) { b.hidden = true; });
  var main = document.getElementById('main'), note = document.createElement('div');
  note.className = 'alert alert-info py-2 d-print-none'; note.setAttribute('role', 'status');
  note.textContent = 'View only: you can look at these records but not change them.';
  var head = main && main.querySelector('.sis-page-heading');
  if (head) head.insertAdjacentElement('afterend', note); else if (main) main.prepend(note);
}

// A placeholder cut short by a narrow box can still be read in full on hover.
Array.prototype.forEach.call(document.querySelectorAll('input[placeholder]:not([title]), textarea[placeholder]:not([title])'), function (i) { i.title = i.placeholder; });

// A form the server refused: put back what was typed (index.php keeps it for one page load).
(function restoreOldInput() {
  var tag = document.getElementById('sisOldInput');
  if (!tag) return;
  var old; try { old = JSON.parse(tag.textContent); } catch (err) { return; }
  tag.remove();
  var fields = old.fields || {}, names = Object.keys(fields);
  var forms = Array.prototype.filter.call(document.querySelectorAll('form'), function (f) { return (f.getAttribute('method') || '').toLowerCase() === 'post'; });
  // The form that was sent: same action, then the one sharing the most field names
  var score = function (f) {
    var a = f.querySelector('input[name="action"]');
    if (old.action && (!a || a.value !== old.action)) return -1;
    return names.filter(function (n) { return f.querySelector('[name="' + CSS.escape(n) + '"], [name^="' + CSS.escape(n) + '["]'); }).length;
  };
  var form = forms.map(function (f) { return [score(f), f]; }).filter(function (x) { return x[0] > 0; }).sort(function (a, b) { return b[0] - a[0]; })[0];
  if (!form) return;
  form = form[1];
  var editable = function (el) { return el.type !== 'hidden' && el.type !== 'file' && el.type !== 'submit' && el.type !== 'button' && !el.disabled; };
  var put = function (el, value) {
    if (!editable(el)) return;
    if (el.type === 'checkbox' || el.type === 'radio') el.checked = Array.isArray(value) ? value.indexOf(el.value) >= 0 : String(value) === el.value;
    else if (el.tagName === 'SELECT' && el.multiple) Array.prototype.forEach.call(el.options, function (o) { o.selected = (Array.isArray(value) ? value : [value]).indexOf(o.value) >= 0; });
    else el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
  };
  // Boxes that were not ticked are not sent, so untick them first
  Array.prototype.forEach.call(form.querySelectorAll('input[type=checkbox]'), function (el) { if (editable(el)) el.checked = false; });
  names.forEach(function (n) {
    var v = fields[n];
    if (v !== null && typeof v === 'object') {
      var list = Array.isArray(v) ? v : null;
      if (list) {
        var els = form.querySelectorAll('[name="' + CSS.escape(n + '[]') + '"]');
        if (els.length && els[0].type !== 'checkbox' && els[0].type !== 'radio') Array.prototype.forEach.call(els, function (el, i) { if (i < list.length) put(el, list[i]); });
        else Array.prototype.forEach.call(els, function (el) { put(el, list); });
      } else Object.keys(v).forEach(function (k) { Array.prototype.forEach.call(form.querySelectorAll('[name="' + CSS.escape(n + '[' + k + ']') + '"]'), function (el) { put(el, v[k]); }); });
    } else Array.prototype.forEach.call(form.querySelectorAll('[name="' + CSS.escape(n) + '"]'), function (el) { put(el, v); });
  });
  // Show the form again if it sits in a pop-up or a closed panel
  var modal = form.closest('.modal');
  if (modal && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modal).show();
  var hidden = form.closest('.collapse:not(.show), details:not([open])');
  if (hidden && hidden.tagName === 'DETAILS') hidden.open = true; else if (hidden && window.bootstrap) window.bootstrap.Collapse.getOrCreateInstance(hidden).show();
  form.setAttribute('data-restored', '1');
})();

},40);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});

// v62: a "select all" checkbox for a list of checkboxes (data-check-all="name[]")
document.addEventListener('change', e => {
  const all = e.target.closest && e.target.closest('[data-check-all]');
  if (!all) return;
  const scope = (all.dataset.checkScope && document.querySelector(all.dataset.checkScope)) || all.closest('form') || document;
  scope.querySelectorAll('input[type=checkbox][name="' + all.dataset.checkAll + '"]').forEach(c => { c.checked = all.checked; });
});

// v62.1: images can be at most 5 MB (other LMS files 10 MB). Tell the person right away instead of after uploading.
document.addEventListener('change', e => {
  const input = e.target;
  if (!input || input.type !== 'file' || !input.files) return;
  const imageMax = 5 * 1048576, fileMax = (parseFloat(input.dataset.maxMb) || 0) * 1048576;
  const bad = Array.from(input.files).find(f => (/^image\//.test(f.type) && f.size > imageMax) || (fileMax && f.size > fileMax));
  if (!bad) return;
  const mb = (bad.size / 1048576).toFixed(1), isImage = /^image\//.test(bad.type);
  window.alert('"' + bad.name + '" is ' + mb + ' MB. ' + (isImage ? 'Images can be at most 5 MB: choose a smaller photo, or take a screenshot of it.' : 'Files can be at most ' + input.dataset.maxMb + ' MB.'));
  input.value = '';
});
