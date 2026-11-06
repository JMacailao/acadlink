/* AcadLink standard toolbar, shared by the SIS and the Grading system.
   New · Edit · Void · Post · Select All | Hide/Show Filters · Apply Filter | Search · Refresh · More Actions
   Every slot is always shown; one a page cannot use stays greyed out with a tooltip saying why.
   The toolbar never does the work itself: each slot runs a function the page adapter gives it
   (usually clicking the module's own control), so permissions and confirmations stay with the module. */
(function () {
  'use strict';
  if (window.AcadToolbar) return;
  const SLOTS = [
    ['new', 'New', 'plus-lg', 'primary'],
    ['edit', 'Edit', 'pencil', ''],
    ['void', 'Void', 'slash-circle', 'danger'],
    ['post', 'Post', 'check2-circle', ''],
    ['selectAll', 'Select All', 'check2-all', ''],
    ['filters', 'Hide Filters', 'funnel', ''],
    ['apply', 'Apply Filter', 'funnel-fill', 'apply'],
  ];
  let openMenu = null;
  function closeMenu() { if (openMenu) { const o = openMenu._opener; openMenu.remove(); openMenu = null; o?.setAttribute('aria-expanded', 'false'); } }
  document.addEventListener('click', (e) => { if (openMenu && !openMenu.contains(e.target) && !e.target.closest('.std-tb-more')) closeMenu(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && openMenu) { const o = openMenu._opener; closeMenu(); o?.focus(); } });

  function button(key, label, icon, tone) {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'std-tb-btn' + (tone ? ' std-tb-' + tone : ''); b.dataset.stdSlot = key;
    b.innerHTML = '<i class="bi bi-' + icon + '" aria-hidden="true"></i><span class="std-tb-label"></span>';
    b.querySelector('.std-tb-label').textContent = label;
    return b;
  }

  /**
   * create({ search: <input>|null, searchPlaceholder, more: () => [{text, icon, run, disabled, title}] })
   * Returns { el, set(key, {enabled, title, run, label}), search }.
   */
  function create(opts) {
    opts = opts || {};
    const el = document.createElement('div');
    el.className = 'std-tb d-print-none'; el.setAttribute('role', 'toolbar'); el.setAttribute('aria-label', 'Record tools');
    const groups = [document.createElement('div'), document.createElement('div'), document.createElement('div')];
    groups.forEach((g, i) => { g.className = 'std-tb-group std-tb-group-' + (i + 1); g.setAttribute('role', 'group'); });
    groups[0].setAttribute('aria-label', 'Record actions'); groups[1].setAttribute('aria-label', 'Filters'); groups[2].setAttribute('aria-label', 'Search and more');
    const slots = {};
    SLOTS.forEach(([key, label, icon, tone]) => { const b = button(key, label, icon, tone); slots[key] = b; (['filters', 'apply'].includes(key) ? groups[1] : groups[0]).append(b); });
    // Search
    const box = document.createElement('div'); box.className = 'std-tb-search'; box.dataset.stdSlot = 'search';
    box.innerHTML = '<i class="bi bi-search" aria-hidden="true"></i>';
    let search = opts.search || null;
    if (!search) { search = document.createElement('input'); search.type = 'search'; search.disabled = true; search.placeholder = opts.searchPlaceholder || 'Search'; search.title = opts.searchTitle || 'This page has no list to search'; }
    search.classList.add('std-tb-input'); search.classList.remove('form-control', 'form-control-sm');
    if (!search.getAttribute('aria-label')) search.setAttribute('aria-label', (search.placeholder || 'Search').replace('…', ''));
    box.append(search);
    const refresh = button('refresh', 'Refresh', 'arrow-clockwise', ''); slots.refresh = refresh;
    const more = button('more', 'More Actions', 'three-dots', ''); more.classList.add('std-tb-more'); slots.more = more;
    more.insertAdjacentHTML('beforeend', '<i class="bi bi-chevron-down std-tb-caret" aria-hidden="true"></i>');
    more.setAttribute('aria-haspopup', 'menu'); more.setAttribute('aria-expanded', 'false');
    groups[2].append(box, refresh, more);
    el.append(...groups);
    const runs = {};
    function set(key, state) {
      const b = slots[key]; if (!b) return;
      const enabled = !!state.enabled;
      b.disabled = !enabled; b.setAttribute('aria-disabled', String(!enabled));
      if (state.label) b.querySelector('.std-tb-label').textContent = state.label;
      const label = b.querySelector('.std-tb-label').textContent;
      b.title = state.title || label; b.setAttribute('aria-label', state.title && enabled ? state.title : label + (enabled ? '' : ' (not available)'));
      runs[key] = enabled ? state.run : null;
      if (state.pressed !== undefined) b.setAttribute('aria-expanded', String(!!state.pressed));
    }
    Object.keys(slots).forEach((k) => { if (k !== 'more') slots[k].addEventListener('click', (e) => { e.stopPropagation(); if (runs[k]) runs[k](); }); });
    more.addEventListener('click', (e) => {
      e.stopPropagation();
      if (openMenu && openMenu._opener === more) { closeMenu(); return; }
      closeMenu();
      const items = (opts.more ? opts.more() : []).filter((it) => it && it.text);
      const menu = document.createElement('div'); menu.className = 'std-tb-menu'; menu.setAttribute('role', 'menu'); menu._opener = more;
      items.forEach((it) => {
        const mi = document.createElement('button'); mi.type = 'button'; mi.setAttribute('role', 'menuitem');
        mi.innerHTML = '<i class="bi bi-' + (it.icon || 'chevron-right') + '" aria-hidden="true"></i>'; mi.append(document.createTextNode(it.text));
        if (it.disabled) { mi.disabled = true; mi.title = it.title || 'Not available for this selection'; }
        mi.addEventListener('click', () => { closeMenu(); it.run(); });
        menu.append(mi);
      });
      if (!items.length) { const p = document.createElement('p'); p.textContent = 'No other actions'; menu.append(p); }
      document.body.append(menu); openMenu = menu; more.setAttribute('aria-expanded', 'true');
      const r = more.getBoundingClientRect(), w = menu.offsetWidth, h = menu.offsetHeight;
      menu.style.left = Math.max(8, Math.min(innerWidth - w - 8, r.right - w)) + 'px';
      menu.style.top = (r.bottom + h + 8 > innerHeight ? Math.max(8, r.top - h - 4) : r.bottom + 4) + 'px';
      menu.querySelector('button:not(:disabled)')?.focus();
    });
    // Arrow keys move between toolbar buttons.
    el.addEventListener('keydown', (e) => {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      if (e.target.matches('input')) return;
      const all = [...el.querySelectorAll('button:not(:disabled), input:not(:disabled)')], i = all.indexOf(e.target); if (i < 0) return;
      e.preventDefault(); all[(i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length].focus();
    });
    return { el, set, search, slots, closeMenu };
  }

  /** Icon for an action label, used for More Actions items. */
  const ICONS = [[/^(view|open|review|details)/i, 'eye'], [/print/i, 'printer'], [/import|upload/i, 'upload'], [/export|download/i, 'download'], [/paste/i, 'clipboard'], [/duplicate/i, 'people'], [/promote/i, 'arrow-up-circle'], [/approve|admit|accept|confirm/i, 'check2-square'], [/reject/i, 'x-circle'], [/send/i, 'send'], [/pay/i, 'cash-coin'], [/history/i, 'clock-history'], [/manage|account/i, 'gear'], [/register/i, 'journal-plus'], [/actions/i, 'layout-sidebar-reverse'], [/section|move/i, 'arrow-left-right'], [/status/i, 'person-check'], [/grade/i, 'journal-text'], [/delete|remove/i, 'trash3']];
  const iconFor = (text) => (ICONS.find(([re]) => re.test(text)) || [, 'chevron-right'])[1];
  /** Visible label of a control, without screen-reader-only text. */
  function labelOf(el) { const c = el.cloneNode(true); c.querySelectorAll('.visually-hidden,.badge').forEach((x) => x.remove()); return (c.textContent.replace(/\s+/g, ' ').trim() || el.getAttribute('aria-label') || el.title || '').trim(); }
  const usable = (el) => !!el && !el.disabled && el.getAttribute('aria-disabled') !== 'true' && !el.classList.contains('disabled');
  /** Which standard slot an action label belongs to (first match wins). */
  const MATCH = {
    edit: /^(edit\b|view\s*\/\s*edit\b)/i,
    void: /^(void\b|cancel admission\b|delete\b|remove\b)/i,
    post: /^(post\b|import\b(?!\s+from))/i,
  };
  function slotOf(text) { for (const k of ['edit', 'void', 'post']) if (MATCH[k].test(text)) return k; return null; }

  window.AcadToolbar = { create, iconFor, labelOf, usable, slotOf, closeMenu };
})();
