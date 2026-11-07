(() => {
  const initAdmissionForms = () => {
    document.querySelectorAll('.admission-workflow').forEach((workflow) => {
      const tabs = Array.from(workflow.querySelectorAll('[data-admission-tab]'));
      const panels = Array.from(workflow.querySelectorAll('[data-admission-panel]'));
      const footers = Array.from(workflow.querySelectorAll('[data-admission-show]'));
      const detailsForm = workflow.querySelector('#admissionDetailsForm');
      const separateDocumentsForm = workflow.querySelector('#admissionDocumentsForm');
      if (!tabs.length || !panels.length) return;

      const names = new Map(tabs.map((tab) => [tab.dataset.admissionTab, tab.textContent.trim()]));
      let activeKey = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.admissionTab || tabs[0].dataset.admissionTab;

      const activate = (key, focus = false) => {
        if (!names.has(key)) return;
        activeKey = key;
        tabs.forEach((tab) => {
          const selected = tab.dataset.admissionTab === key;
          tab.setAttribute('aria-selected', String(selected));
          tab.tabIndex = selected ? 0 : -1;
          if (selected && focus) tab.focus();
        });
        panels.forEach((panel) => {
          panel.hidden = panel.dataset.admissionPanel !== key;
        });
        if (separateDocumentsForm) {
          detailsForm.hidden = key === 'documents';
          separateDocumentsForm.hidden = key !== 'documents';
        }
        footers.forEach((footer) => {
          footer.hidden = !footer.dataset.admissionShow.split(',').includes(key);
        });
        const currentIndex = tabs.findIndex((tab) => tab.dataset.admissionTab === key);
        workflow.querySelectorAll('[data-admission-prev]').forEach((button) => {
          button.hidden = currentIndex <= 0;
        });
        workflow.querySelectorAll('[data-admission-next]').forEach((button) => {
          const nextKey = tabs[currentIndex + 1]?.dataset.admissionTab;
          button.hidden = !nextKey;
          if (nextKey) {
            const label = button.querySelector('span');
            if (label) label.textContent = `Next: ${names.get(nextKey)}`;
          }
        });
      };

      tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.admissionTab));
        tab.addEventListener('keydown', (event) => {
          let nextIndex = index;
          if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
          else if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
          else if (event.key === 'Home') nextIndex = 0;
          else if (event.key === 'End') nextIndex = tabs.length - 1;
          else return;
          event.preventDefault();
          activate(tabs[nextIndex].dataset.admissionTab, true);
        });
      });

      workflow.querySelectorAll('[data-admission-prev]').forEach((button) => {
        button.addEventListener('click', () => {
          const index = tabs.findIndex((tab) => tab.dataset.admissionTab === activeKey);
          if (index > 0) activate(tabs[index - 1].dataset.admissionTab, true);
        });
      });
      workflow.querySelectorAll('[data-admission-next]').forEach((button) => {
        button.addEventListener('click', () => {
          const index = tabs.findIndex((tab) => tab.dataset.admissionTab === activeKey);
          if (tabs[index + 1]) activate(tabs[index + 1].dataset.admissionTab, true);
        });
      });

      workflow.addEventListener('invalid', (event) => {
        const panel = event.target.closest('[data-admission-panel]');
        if (panel) activate(panel.dataset.admissionPanel);
      }, true);

      const birthDate = workflow.querySelector('#aBirth');
      const age = workflow.querySelector('#aAge');
      if (birthDate && age) {
        const updateAge = () => {
          const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(birthDate.value);
          if (!match) {
            age.value = '';
            return;
          }
          const year = Number(match[1]);
          const month = Number(match[2]);
          const day = Number(match[3]);
          const entered = new Date(year, month - 1, day);
          if (entered.getFullYear() !== year || entered.getMonth() !== month - 1 || entered.getDate() !== day) {
            age.value = '';
            return;
          }
          const today = new Date();
          let years = today.getFullYear() - year;
          if (today.getMonth() < month - 1 || (today.getMonth() === month - 1 && today.getDate() < day)) years -= 1;
          age.value = years >= 0 ? String(years) : '';
        };
        birthDate.addEventListener('input', updateAge);
        birthDate.addEventListener('change', updateAge);
        updateAge();
      }

      const classification = workflow.querySelector('#aType');
      const previousSchool = workflow.querySelector('[data-previous-school]');
      const transferRequired = workflow.querySelector('[data-transfer-required]');
      if (classification && previousSchool) {
        const updatePreviousSchool = () => {
          const required = classification.value === 'TRANSFEREE';
          previousSchool.required = required;
          previousSchool.setAttribute('aria-required', String(required));
          if (transferRequired) transferRequired.hidden = !required;
        };
        classification.addEventListener('change', updatePreviousSchool);
        updatePreviousSchool();
      }

      activate(activeKey);
    });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAdmissionForms, { once: true });
  else initAdmissionForms();
})();
