/* AcadLink website: theme, header, reveal animations, journey rail, counters and the inquiry form. */
(function () {
  'use strict';
  var EMAIL = 'jeremiahmacailao110102@gmail.com';
  var ENDPOINT = 'https://formsubmit.co/ajax/' + EMAIL;
  var root = document.documentElement;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };

  /* Theme: remembered choice, else the device setting */
  var toggle = $('#themeToggle');
  var sysDark = window.matchMedia('(prefers-color-scheme: dark)');
  function isDark() { return root.dataset.theme ? root.dataset.theme === 'dark' : sysDark.matches; }
  function syncToggle() {
    var d = isDark();
    toggle.setAttribute('aria-label', d ? 'Switch to light mode' : 'Switch to dark mode');
    toggle.title = d ? 'Light mode' : 'Dark mode';
  }
  toggle.addEventListener('click', function () {
    var next = isDark() ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem('acadlink-theme', next); } catch (e) { /* private mode */ }
    syncToggle();
  });
  if (sysDark.addEventListener) sysDark.addEventListener('change', syncToggle);
  syncToggle();

  /* Header turns into a floating island once you scroll */
  var nav = $('#nav');
  function onScrollNav() { nav.classList.toggle('is-island', window.scrollY > 40); }

  /* Mobile menu */
  var navToggle = $('#navToggle'), links = $('#navLinks');
  function setMenu(open) {
    links.classList.toggle('open', open);
    navToggle.setAttribute('aria-expanded', String(open));
    navToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  }
  navToggle.addEventListener('click', function () { setMenu(!links.classList.contains('open')); });
  links.addEventListener('click', function (e) { if (e.target.closest('a')) setMenu(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
  document.addEventListener('click', function (e) { if (!nav.contains(e.target)) setMenu(false); });

  /* Reveal on scroll */
  var showAll = reduce || !('IntersectionObserver' in window);
  function observe(els, cb, opts) {
    if (showAll) { els.forEach(function (el) { cb(el); }); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { cb(en.target); io.unobserve(en.target); } });
    }, opts || { threshold: 0.15, rootMargin: '0px 0px -8% 0px' });
    els.forEach(function (el) { io.observe(el); });
  }
  observe($$('.reveal'), function (el) { el.classList.add('in'); });
  observe($$('#whyGrid'), function (el) { el.classList.add('in'); }, { threshold: 0.25 });

  /* Counters */
  observe($$('[data-count]'), function (el) {
    var end = +el.dataset.count, suffix = el.dataset.suffix || '';
    if (showAll) { el.textContent = end + suffix; return; }
    var t0 = null, dur = 1400;
    (function step(t) {
      if (!t0) t0 = t;
      var k = Math.min(1, (t - t0) / dur), eased = 1 - Math.pow(1 - k, 3);
      el.textContent = Math.round(end * eased) + suffix;
      if (k < 1) requestAnimationFrame(step);
    })(performance.now());
  }, { threshold: 0.6 });

  /* Journey: the line fills as you scroll and each step lights up when the line reaches it */
  var rail = $('#journeyRail'), fill = $('#jrFill'), steps = $$('.jr-step'), end = $('#jrEnd');
  function onScrollJourney() {
    if (!rail) return;
    var line = fill.parentNode.getBoundingClientRect();
    var mark = window.innerHeight * 0.6;
    var pct = Math.max(0, Math.min(1, (mark - line.top) / line.height));
    fill.style.height = (pct * 100) + '%';
    steps.forEach(function (s) {
      var n = s.querySelector('.jr-node').getBoundingClientRect();
      s.classList.toggle('on', n.top + n.height / 2 < mark);
    });
    if (pct >= 1) end.classList.add('is-done');
  }
  if (showAll && rail) { fill.style.height = '100%'; steps.forEach(function (s) { s.classList.add('on'); }); end.classList.add('is-done'); }

  /* Highlight the menu item for the section in view */
  var spy = $$('[data-spy]').map(function (a) { return { a: a, sec: $(a.getAttribute('href')) }; });
  function onScrollSpy() {
    var y = window.innerHeight * 0.35, current = null;
    spy.forEach(function (it) { if (it.sec && it.sec.getBoundingClientRect().top < y) current = it; });
    spy.forEach(function (it) { it.a.classList.toggle('active', it === current); });
  }

  var ticking = false;
  function onScroll() {
    if (ticking) return; ticking = true;
    requestAnimationFrame(function () {
      ticking = false; onScrollNav(); onScrollSpy(); if (!showAll) onScrollJourney();
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  onScroll();

  var year = $('#year'); if (year) year.textContent = new Date().getFullYear();

  /* Inquiry form: sends to the email through FormSubmit; opens the mail app if that fails */
  var form = $('#inquiry'), status = $('#formStatus');
  if (!form) return;
  var btn = form.querySelector('button[type=submit]'), btnHTML = btn.innerHTML;
  var everything = form.querySelector('input[name=modules][value=Everything]');
  form.addEventListener('change', function (e) {
    if (e.target.name !== 'modules') return;
    if (e.target === everything && everything.checked) $$('input[name=modules]', form).forEach(function (c) { if (c !== everything) c.checked = false; });
    else if (e.target !== everything && e.target.checked) everything.checked = false;
  });
  function say(msg, kind) { status.textContent = msg; status.className = 'form-status' + (kind ? ' ' + kind : ''); }
  function collect() {
    var f = form.elements;
    return {
      name: f.name.value.trim(), school: f.school.value.trim(), email: f.email.value.trim(), phone: f.phone.value.trim(),
      role: f.role.value, modules: $$('input[name=modules]:checked', form).map(function (c) { return c.value; }).join(', ') || 'Not specified',
      message: f.message.value.trim()
    };
  }
  function validate() {
    var bad = null;
    ['name', 'school', 'email', 'message'].forEach(function (n) {
      var el = form.elements[n], ok = el.value.trim() !== '' && (n !== 'email' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim()));
      el.setAttribute('aria-invalid', ok ? 'false' : 'true');
      if (!ok && !bad) bad = el;
    });
    return bad;
  }
  function mailtoHref(d) {
    var body = 'Name: ' + d.name + '\nSchool: ' + d.school + '\nEmail: ' + d.email + '\nMobile: ' + (d.phone || '-') +
      '\nRole: ' + d.role + '\nInterested in: ' + d.modules + '\n\n' + d.message;
    return 'mailto:' + EMAIL + '?subject=' + encodeURIComponent('AcadLink inquiry from ' + d.school) + '&body=' + encodeURIComponent(body);
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (form.elements._honey.value) return; // bots fill the hidden field
    var bad = validate();
    if (bad) { say('Please fill in your name, school, a valid email and a message.', 'err'); bad.focus(); return; }
    var d = collect();
    btn.disabled = true; btn.textContent = 'Sending…'; say('');
    fetch(ENDPOINT, {
      method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        _subject: 'AcadLink inquiry: ' + d.school, _template: 'table', _captcha: 'false', _replyto: d.email,
        Name: d.name, School: d.school, Email: d.email, Mobile: d.phone || '-', Role: d.role, 'Interested in': d.modules, Message: d.message
      })
    }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || String(res.j.success) === 'false') throw new Error(res.j.message || 'send failed');
        form.reset(); $$('[aria-invalid]', form).forEach(function (el) { el.removeAttribute('aria-invalid'); });
        say('Thank you! Your inquiry was sent. We will reply to ' + d.email + ' soon.', 'ok');
      })
      .catch(function () {
        status.className = 'form-status err';
        status.innerHTML = 'We could not send it right now. <a href="' + mailtoHref(d).replace(/"/g, '&quot;') + '">Send it with your email app</a> or call 0977 698 0253.';
      })
      .then(function () { btn.disabled = false; btn.innerHTML = btnHTML; });
  });
})();
