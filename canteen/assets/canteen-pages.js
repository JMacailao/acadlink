/* Canteen pages (v59): print the receipt slip, live totals on the cash count, select-all on stocktake. */
(function () {
  'use strict';
  document.querySelectorAll('[data-canteen-print]').forEach(b => b.addEventListener('click', () => {
    document.body.classList.add('canteen-print');
    window.print();
    setTimeout(() => document.body.classList.remove('canteen-print'), 500);
  }));
  document.querySelectorAll('[data-page-print]').forEach(b => b.addEventListener('click', () => window.print()));
  const peso = c => '₱' + (c / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const count = document.querySelector('[data-cash-count]');
  if (count) {
    const expected = parseInt(count.dataset.expected || '0', 10);
    const update = () => {
      let total = 0;
      count.querySelectorAll('input[data-denom]').forEach(i => { const n = parseInt(i.value, 10); if (n > 0) total += n * parseInt(i.dataset.denom, 10); });
      const out = count.querySelector('[data-count-total]'), diff = count.querySelector('[data-count-diff]');
      if (out) out.textContent = peso(total);
      if (diff) { const d = total - expected; diff.textContent = d === 0 ? 'Balanced' : (d < 0 ? 'Short ' : 'Over ') + peso(Math.abs(d)); diff.className = d === 0 ? 'text-success' : 'text-danger'; }
    };
    count.addEventListener('input', update); update();
  }
  document.querySelectorAll('[data-fill-system]').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('input[data-system-qty]').forEach(i => { if (i.value === '') i.value = i.dataset.systemQty; });
  }));
})();
