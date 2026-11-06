/* Grading system: the standard toolbar on the list pages (same as the SIS).
   New · Edit · Void · Post · Select All | Hide/Show Filters · Apply Filter | Search · Refresh · More Actions
   - Click a row to select it; Edit and Void click that row's own Edit and Delete buttons (double-click = Edit).
   - Filters and the search wait for Apply Filter (or Enter in the search box), as in the SIS.
   - School-year filters open at the current school year (the newest school year on record).
   - Filters you applied are kept for this browser tab, so Refresh and coming back show the same list. */
(function () {
  'use strict';
  const T = window.AcadToolbar; if (!T) return;
  const page = (location.pathname.split('/').pop() || '').replace(/\.html$/, '');
  const PAGES = {
    students: { name: 'Students', list: '#tbody', newBtn: '#btnAdd', search: '#search', filters: ['#filterYear', '#filterSection', '#filterStatus'], sy: '#filterYear', selectAll: '#selectAll', more: ['#btnImport', '#btnPasteList', '#btnFindDups', '#btnBulkMove', '#btnBulkStatus', '#btnBulkDelete'] },
    teachers: { name: 'Teachers', list: '#tbody', newBtn: '#btnAdd', search: '#search', filters: [], more: [] },
    subjects: { name: 'Subjects', list: '#tbody', newBtn: '#btnAdd', search: '#search', filters: [], more: ['#btnSubjImportOpen'] },
    sections: { name: 'Sections', list: '#tbody', newBtn: '#btnAdd', search: '#search', filters: ['#filterYear'], sy: '#filterYear', more: ['#btnPromote'] },
    curriculum: { name: 'Curriculum', list: '#curBody', newBtn: '#btnCurNew', newFocus: '#curProgram', search: null, filters: [], more: [] },
    assignments: { name: 'Grading assignments', list: '#tbody', newBtn: '#btnCancelEdit', newFocus: '.wizard-step select', search: '#search', filters: ['#filterYear', '#filterTerm', '#filterSection', '#filterTeacher'], sy: '#filterYear', more: ["#btnCheckDupGrades"] },
  };
  const cfg = PAGES[page]; if (!cfg) return;
  const $ = (s) => (s ? document.querySelector(s) : null);
  const body = $(cfg.list); if (!body) return;
  const table = body.closest('table');
  const storeKey = 'gs-filters-' + page;
  const store = { get() { try { return JSON.parse(sessionStorage.getItem(storeKey) || 'null'); } catch (e) { return null; } }, set(v) { try { sessionStorage.setItem(storeKey, JSON.stringify(v)); } catch (e) { /* private mode */ } } };

  const search = $(cfg.search);
  const filters = cfg.filters.map($).filter(Boolean);
  const watched = [...filters, ...(search ? [search] : [])];
  const filterBox = filters[0] ? filters[0].parentElement : null;
  const more = cfg.more.map($).filter(Boolean);
  const newBtn = $(cfg.newBtn);

  const searchBox = search ? search.closest('.search-box') : null;
  const tb = T.create({ search, searchPlaceholder: 'Search', searchTitle: 'This page has no list search', more: moreItems });
  const host = document.createElement('div'); host.className = 'std-tb-host d-print-none'; host.append(tb.el);
  const note = document.createElement('p'); note.className = 'std-tb-note d-print-none'; note.setAttribute('role', 'status'); note.setAttribute('aria-live', 'polite');
  // Default place on every Grading page: the top of the page, above the title (as in the SIS), staying in view on scroll.
  const mainEl = document.getElementById('main') || document.body;
  const alertEl = mainEl.querySelector(':scope > #config-alert');
  if (alertEl) alertEl.after(host); else mainEl.prepend(host);
  host.after(note);
  // The page's own header buttons are reached through the toolbar (New, More Actions), so they are not shown twice.
  const headActions = document.querySelector('#main .page-head > div:last-child');
  if (headActions && headActions !== document.querySelector('#main .page-head > div:first-child') && headActions.querySelector('button')) headActions.classList.add('d-none');
  if (searchBox) searchBox.classList.add('d-none');
  // Their header and list-head buttons now live under More Actions (bulk-bar buttons stay: they act on ticked rows).
  more.forEach((b) => { if (!b.closest('.bulk-bar,.page-head')) b.classList.add('std-tb-moved'); });

  // ---- Explicit apply: changes wait for Apply Filter ----
  let applying = false, dirty = false;
  const isWatched = (t) => watched.includes(t);
  ['input', 'change'].forEach((type) => window.addEventListener(type, (e) => {
    if (applying || !isWatched(e.target)) return;
    e.stopImmediatePropagation();
    if (!dirty) { dirty = true; update(); }
  }, true));
  function apply() {
    applying = true; dirty = false;
    try { watched.forEach((el) => el.dispatchEvent(new Event(el === search ? 'input' : 'change', { bubbles: true }))); }
    finally { applying = false; }
    store.set(Object.fromEntries(watched.map((el) => [el.id, el.value])));
    update();
  }
  if (search) search.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); apply(); } });

  // ---- Defaults: saved filters for this tab, else the current school year ----
  const saved = store.get();
  let defaulted = false;
  const syEl = $(cfg.sy);
  function newestYear(sel) { return [...sel.options].map((o) => o.value).filter((v) => /^\d{4}-\d{4}$/.test(v)).sort().reverse()[0] || ''; }
  function tryDefaults() {
    if (defaulted) return;
    const ready = filters.every((el) => el.options.length > 1 || !el.matches('select')) && (!syEl || newestYear(syEl));
    if (!ready && !(saved && !syEl)) return;
    defaulted = true;
    if (saved) watched.forEach((el) => { if (!(el.id in saved)) return; if (el.tagName === 'SELECT' && ![...el.options].some((o) => o.value === saved[el.id])) return; el.value = saved[el.id]; });
    else if (syEl) syEl.value = newestYear(syEl);
    apply();
  }
  if ('MutationObserver' in window) {
    const mo = new MutationObserver(() => tryDefaults());
    filters.forEach((el) => mo.observe(el, { childList: true }));
  }
  setTimeout(tryDefaults, 0);

  // ---- Row selection ----
  let selectedId = null;
  const rows = () => [...body.rows].filter((tr) => tr.cells.length > 1 && !tr.querySelector('[colspan]'));
  const idOf = (tr) => tr.querySelector('[data-edit]')?.dataset.edit || tr.querySelector('[data-delete]')?.dataset.delete || tr.dataset.id || null;
  const selectedRow = () => rows().find((tr) => idOf(tr) === selectedId) || null;
  function mark() { rows().forEach((tr) => { const on = selectedId && idOf(tr) === selectedId; tr.classList.toggle('std-row-selected', !!on); tr.classList.add('std-row-selectable'); tr.setAttribute('aria-selected', on ? 'true' : 'false'); }); }
  body.addEventListener('click', (e) => {
    const tr = e.target.closest('tr'); if (!tr || !rows().includes(tr)) return;
    if (e.target.closest('a,button,input,select,textarea,label,.dropdown-menu')) return;
    selectedId = idOf(tr) === selectedId ? null : idOf(tr); mark(); update();
  });
  body.addEventListener('dblclick', (e) => { const tr = e.target.closest('tr'); if (!tr || e.target.closest('a,button,input')) return; selectedId = idOf(tr); mark(); update(); tb.slots.edit.click(); });
  if ('MutationObserver' in window) new MutationObserver(() => { if (selectedId && !selectedRow()) selectedId = null; mark(); update(); }).observe(body, { childList: true });

  // ---- More Actions ----
  function moreItems() {
    const items = [], seen = new Set();
    const add = (el) => { const text = T.labelOf(el); if (!text || seen.has(text.toLowerCase())) return; seen.add(text.toLowerCase()); items.push({ text, icon: T.iconFor(text), disabled: !T.usable(el), title: el.title, run: () => el.click() }); };
    const tr = selectedRow();
    if (tr) [...tr.querySelectorAll('a[href], button')].filter((el) => !el.matches('[data-edit],[data-delete],.dropdown-toggle') && T.labelOf(el)).forEach(add);
    more.forEach(add);
    return items;
  }

  // ---- State ----
  function update() {
    const tr = selectedRow();
    if (newBtn) tb.set('new', { enabled: !newBtn.disabled, title: T.labelOf(newBtn) === 'New' || newBtn === $('#btnCancelEdit') ? 'New ' + (cfg.name === 'Curriculum' ? 'curriculum' : 'assignment') : T.labelOf(newBtn), run: () => {
      if (newBtn.offsetParent || newBtn.id === 'btnAdd') newBtn.click();
      const f = $(cfg.newFocus); if (f) { f.scrollIntoView({ block: 'center', behavior: 'smooth' }); setTimeout(() => f.focus(), 250); }
    } });
    else tb.set('new', { enabled: false, title: cfg.name + ' has no New action' });
    const edit = tr && tr.querySelector('[data-edit]'), del = tr && tr.querySelector('[data-delete]');
    tb.set('edit', edit ? { enabled: true, title: T.labelOf(edit) || 'Edit', run: () => edit.click() } : { enabled: false, title: tr ? 'This record cannot be edited' : 'Select a row first' });
    if (del) tb.set('void', { enabled: T.usable(del), title: T.labelOf(del) || 'Delete', run: () => del.click() });
    else tb.set('void', { enabled: false, title: !tr ? 'Select a row first' : (tr.querySelector('.btn-outline-danger[disabled]')?.closest('[title]')?.title || 'This record cannot be deleted') });
    tb.set('post', { enabled: false, title: cfg.name + ' has no Post action' });
    const all = $(cfg.selectAll);
    tb.set('selectAll', all ? { enabled: !all.disabled, label: all.checked ? 'Clear All' : 'Select All', title: 'Select every student on the list', run: () => { all.click(); setTimeout(update); } } : { enabled: false, title: cfg.name + ' has no bulk actions' });
    if (filterBox && filters.length) {
      const hidden = filters.every((el) => el.classList.contains('std-tb-filters-hidden'));
      tb.set('filters', { enabled: true, label: hidden ? 'Show Filters' : 'Hide Filters', pressed: !hidden, run: () => { filters.forEach((el) => el.classList.toggle('std-tb-filters-hidden', !hidden)); update(); } });
    } else tb.set('filters', { enabled: false, title: 'This list has no filters' });
    tb.set('apply', watched.length ? { enabled: true, title: 'Apply Filter', run: apply } : { enabled: false, title: 'This list has no filters' });
    tb.set('refresh', { enabled: true, title: 'Reload the list with the same filters', run: () => { store.set(Object.fromEntries(watched.map((el) => [el.id, el.value]))); location.reload(); } });
    tb.el.classList.toggle('std-tb-dirty', dirty);
    note.classList.toggle('is-dirty', dirty);
    note.textContent = dirty ? 'Filters changed — click Apply Filter.' : tr ? '1 selected · ' + (tr.cells[1]?.textContent.trim() || '') : '';
    note.hidden = !note.textContent;
  }
  update();
})();
