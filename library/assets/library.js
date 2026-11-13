/* Library (v60): print buttons, keep the scan boxes ready, show the damage fee field, batch stocktake scanning. */
(function () {
  'use strict';
  document.querySelectorAll('[data-page-print]').forEach(b => b.addEventListener('click', () => window.print()));
  document.querySelectorAll('[data-toggle-target]').forEach(cb => {
    const t = document.querySelector(cb.dataset.toggleTarget);
    const sync = () => { if (t) t.hidden = !cb.checked; };
    cb.addEventListener('change', sync); sync();
  });
  // Circulation desk: Check-out / Check-in / Renew tabs; the scan box of the open tab gets the focus.
  document.querySelectorAll('[data-lib-tabs]').forEach(box => {
    const tabs = box.querySelectorAll('[data-tab]'), panels = box.querySelectorAll('[data-panel]');
    const show = (mode, focus) => {
      tabs.forEach(t => { const on = t.dataset.tab === mode; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
      panels.forEach(p => { p.hidden = p.dataset.panel !== mode; });
      const input = box.querySelector('[data-panel="' + mode + '"] [data-scan]');
      if (focus && input) input.focus();
      try { const u = new URL(location.href); u.searchParams.set('mode', mode); history.replaceState(null, '', u); } catch (_) {}
    };
    tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.tab, true)));
    show(box.dataset.mode || 'in', !document.querySelector('[autofocus]'));
  });
  // Stocktake: a scanner types the code and Enter; each Enter adds a line, two quick Enters (or the button) save.
  const batch = document.querySelector('[data-scan-batch]');
  if (batch) {
    let last = 0;
    batch.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return;
      const now = Date.now();
      if (now - last < 400 || batch.value.trim().split(/\s+/).length >= 25) { e.preventDefault(); batch.form.submit(); }
      last = now;
    });
  }
})();
