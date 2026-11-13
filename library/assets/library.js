/* Library (v60): print buttons, keep the scan boxes ready, show the damage fee field, batch stocktake scanning. */
(function () {
  'use strict';
  document.querySelectorAll('[data-page-print]').forEach(b => b.addEventListener('click', () => window.print()));
  document.querySelectorAll('[data-toggle-target]').forEach(cb => {
    const t = document.querySelector(cb.dataset.toggleTarget);
    const sync = () => { if (t) t.hidden = !cb.checked; };
    cb.addEventListener('change', sync); sync();
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
