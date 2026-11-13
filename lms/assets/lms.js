/* LMS (v61): create menu, quiz builder, quiz timer, attendance helpers, small form conveniences. */
(function () {
  'use strict';
  document.querySelectorAll('[data-autosubmit]').forEach(el => el.addEventListener('change', () => el.form && el.form.submit()));
  document.querySelectorAll('[data-reload-param]').forEach(el => el.addEventListener('change', () => {
    const u = new URL(location.href); u.searchParams.set(el.dataset.reloadParam, el.value); location.assign(u);
  }));
  document.querySelectorAll('[data-mark-all]').forEach(b => b.addEventListener('click', () => {
    b.closest('form').querySelectorAll('input[type=radio][value="' + b.dataset.markAll + '"]').forEach(r => { r.checked = true; });
  }));

  // Classwork → Create: Assignment / activity, Quiz / exam, or Material
  const create = document.querySelector('[data-lms-create]');
  if (create) {
    const box = create.parentElement;
    const pick = kind => {
      create.querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.kind === kind));
      box.querySelectorAll('[data-form-for]').forEach(f => { f.hidden = !f.dataset.formFor.split(' ').includes(kind); });
      const work = box.querySelector('[data-form-for~="QUIZ"]');
      work.querySelector('[data-kind-input]').value = kind === 'QUIZ' ? 'QUIZ' : 'ASSIGNMENT';
      work.querySelectorAll('[data-only]').forEach(el => { el.hidden = el.dataset.only !== (kind === 'QUIZ' ? 'QUIZ' : 'ASSIGNMENT'); });
      const lbl = work.querySelector('[data-submit-label]'); if (lbl) lbl.textContent = kind === 'QUIZ' ? 'Create quiz and add questions' : 'Assign';
    };
    create.querySelectorAll('button').forEach(b => b.addEventListener('click', () => pick(b.dataset.kind)));
    pick('ASSIGNMENT');
  }

  // Quiz builder
  const qb = document.querySelector('[data-qbuilder]');
  if (qb) {
    const list = qb.querySelector('[data-qlist]');
    const sync = fs => {
      const t = fs.querySelector('[data-qtype]').value;
      fs.querySelectorAll('[data-for]').forEach(el => { el.hidden = !el.dataset.for.split(' ').includes(t); });
      fs.querySelectorAll('[data-for] input, [data-for] textarea').forEach(el => { el.disabled = el.closest('[data-for]').hidden || fs.disabled; });
    };
    const renumber = () => list.querySelectorAll('[data-q]').forEach((fs, i) => {
      fs.querySelector('[data-qno]').textContent = i + 1;
      fs.querySelectorAll('[name^="questions["]').forEach(el => { el.name = el.name.replace(/^questions\[\d+\]/, 'questions[' + i + ']'); });
    });
    const wire = fs => {
      fs.querySelector('[data-qtype]').addEventListener('change', () => sync(fs));
      fs.querySelector('[data-qdel]').addEventListener('click', () => { if (list.querySelectorAll('[data-q]').length > 1) { fs.remove(); renumber(); } });
      fs.querySelector('[data-addopt]').addEventListener('click', () => {
        const opts = fs.querySelector('[data-opts]'), row = opts.lastElementChild.cloneNode(true), n = opts.children.length;
        row.querySelector('input[type=checkbox]').value = n; row.querySelector('input[type=checkbox]').checked = false;
        const txt = row.querySelector('input:not([type=checkbox])'); txt.value = ''; txt.placeholder = 'Choice ' + (n + 1); txt.setAttribute('aria-label', 'Choice ' + (n + 1));
        opts.append(row);
      });
      // Multiple choice: one correct answer
      fs.querySelector('[data-opts]').addEventListener('change', e => {
        if (e.target.type === 'checkbox' && e.target.checked && fs.querySelector('[data-qtype]').value === 'MC')
          fs.querySelectorAll('[data-opts] input[type=checkbox]').forEach(cb => { if (cb !== e.target) cb.checked = false; });
      });
      sync(fs);
    };
    list.querySelectorAll('[data-q]').forEach(wire);
    const add = qb.querySelector('[data-qadd]');
    if (add) add.addEventListener('click', () => {
      const src = list.lastElementChild, fs = src.cloneNode(true);
      fs.querySelectorAll('textarea').forEach(t => { t.value = ''; });
      fs.querySelectorAll('[data-opts] input:not([type=checkbox])').forEach(i => { i.value = ''; });
      fs.querySelectorAll('input[type=checkbox], input[type=radio]').forEach(i => { i.checked = false; });
      fs.querySelector('[name$="[points]"]').value = '1';
      list.append(fs); renumber(); wire(fs); fs.querySelector('textarea').focus();
    });
    qb.addEventListener('submit', renumber);
  }

  // Taking a quiz: countdown, then it is turned in automatically
  const quiz = document.querySelector('[data-quiz]');
  if (quiz) {
    let sent = false;
    quiz.addEventListener('submit', e => {
      if (sent) return;
      if (!quiz.dataset.timeUp && !confirm('Turn in the quiz? You can\'t change your answers afterwards.')) { e.preventDefault(); return; }
      sent = true;
    });
    const timer = quiz.querySelector('[data-timer] b');
    if (timer && quiz.dataset.secondsLeft) {
      const end = Date.now() + parseInt(quiz.dataset.secondsLeft, 10) * 1000;
      const tick = () => {
        const s = Math.max(0, Math.round((end - Date.now()) / 1000));
        timer.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        timer.parentElement.classList.toggle('is-low', s <= 60);
        if (s === 0) { quiz.dataset.timeUp = '1'; if (!sent) { sent = true; quiz.submit(); } return; }
        setTimeout(tick, 500);
      };
      tick();
    }
  }
})();
