/* Review multiple registration fee labels before adding them to an assessment. */
(function (register) {
  'use strict';

  register('registration-fee-picker', function (scope) {
    const form = document.querySelector('[data-fee-picker]');
    if (!form) return;

    const options = [...form.querySelectorAll('[data-fee-option]')];
    const summary = form.querySelector('[data-fee-summary]');
    const summaryList = form.querySelector('[data-fee-summary-list]');
    const count = form.querySelector('[data-fee-count]');
    const empty = form.querySelector('[data-fee-empty]');
    const submit = form.querySelector('[data-fee-submit]');
    if (!options.length || !summary || !summaryList || !submit) return;

    const formatter = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const selected = () => options.filter(row => row.querySelector('[data-fee-select]')?.checked);

    function rateText(row) {
      const input = row.querySelector('[data-fee-amount]');
      const raw = input?.value.trim() || row.dataset.defaultAmount || '0';
      const amount = Number(raw);
      if (!Number.isFinite(amount) || amount < 0) return 'Enter a valid rate';
      const suffix = row.dataset.chargeType === 'PER_UNIT' ? ' per unit'
        : row.dataset.chargeType === 'PER_SUBJECT' ? ' per subject' : '';
      return '₱' + formatter.format(amount) + suffix;
    }

    function render() {
      const checked = selected();
      summary.hidden = checked.length === 0;
      if (empty) empty.hidden = checked.length > 0;
      if (count) count.textContent = String(checked.length);
      options.forEach(row => {
        const checkbox = row.querySelector('[data-fee-select]');
        const amount = row.querySelector('[data-fee-amount]');
        const isSelected = !!checkbox?.checked;
        row.classList.toggle('is-selected', isSelected);
        if (amount) amount.disabled = !isSelected;
      });

      summaryList.replaceChildren();
      checked.forEach(row => {
        const tr = document.createElement('tr');
        const fee = document.createElement('td');
        const strong = document.createElement('strong');
        strong.textContent = row.dataset.feeName || '';
        fee.append(strong);
        if (row.dataset.feeCategory) {
          const small = document.createElement('small');
          small.className = 'd-block text-muted';
          small.textContent = row.dataset.feeCategory;
          fee.append(small);
        }

        const type = document.createElement('td');
        type.textContent = row.dataset.chargeLabel || '';
        const rate = document.createElement('td');
        rate.textContent = rateText(row);
        const removeCell = document.createElement('td');
        removeCell.className = 'text-end';
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-link text-danger p-0 fee-picker-remove';
        remove.textContent = '×';
        remove.title = 'Remove ' + (row.dataset.feeName || 'fee') + ' from selected items';
        remove.setAttribute('aria-label', remove.title);
        remove.addEventListener('click', () => {
          const checkbox = row.querySelector('[data-fee-select]');
          if (checkbox) checkbox.checked = false;
          render();
          checkbox?.focus();
        });
        removeCell.append(remove);
        tr.append(fee, type, rate, removeCell);
        summaryList.append(tr);
      });

      submit.disabled = checked.length === 0 || checked.some(row => !row.querySelector('[data-fee-amount]')?.validity.valid);
    }

    scope.on(form, 'change', event => {
      if (event.target.matches('[data-fee-select]')) render();
    });
    scope.on(form, 'input', event => {
      if (event.target.matches('[data-fee-amount]')) render();
    });
    scope.on(form, 'submit', event => {
      if (!selected().length) {
        event.preventDefault();
        options[0]?.querySelector('[data-fee-select]')?.focus();
      }
    });

    render();
  }, 94);
})(window.SISLifecycle ? window.SISLifecycle.register : function () {
  (window.SISModuleQueue = window.SISModuleQueue || []).push(Array.from(arguments));
});
