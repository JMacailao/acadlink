/* Canteen Cashier (v59): product grid, barcode/search, cart, cash and QR payment. Prices shown here are for the
   cashier only; the server prices every item again from the database when the sale is saved. */
(function () {
  'use strict';
  const root = document.querySelector('[data-canteen-pos]');
  if (!root) return;
  const catalog = JSON.parse(document.getElementById('cposCatalog').textContent || '[]');
  const byId = new Map(catalog.map(p => [p.id, p]));
  const shift = root.dataset.shift || '0';
  const key = 'acadlink-canteen-cart-' + shift;
  const $ = id => document.getElementById(id);
  const grid = $('cposGrid'), search = $('cposSearch'), empty = $('cposEmpty');
  const form = $('cposForm');
  const canSell = !!form;
  const peso = c => '₱' + (c / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const toCents = v => { const s = String(v || '').replace(/[,\s₱]/g, ''); if (!/^\d*(\.\d{0,2})?$/.test(s) || s === '' || s === '.') return null; return Math.round(parseFloat(s) * 100); };
  let cat = 0, view = 'grid', method = 'CASH';
  let cart = [];
  const store = {
    get(k) { try { return sessionStorage.getItem(k); } catch (_) { return null; } },
    set(k, v) { try { sessionStorage.setItem(k, v); } catch (_) {} },
    del(k) { try { sessionStorage.removeItem(k); } catch (_) {} },
  };

  // A sale that was submitted and succeeded (no error shown) empties the cart.
  const submitted = store.get(key + '-submitted');
  if (submitted && !document.querySelector('.alert-danger')) { store.del(key); store.del(key + '-submitted'); }
  else if (submitted) store.del(key + '-submitted');
  try { cart = (JSON.parse(store.get(key) || '[]') || []).filter(l => byId.has(l.id) && l.qty > 0); } catch (_) { cart = []; }
  try { view = localStorage.getItem('acadlink-canteen-view') === 'list' ? 'list' : 'grid'; } catch (_) {}

  function inCart(id) { const l = cart.find(x => x.id === id); return l ? l.qty : 0; }
  function left(p) { return p.track ? p.stock - inCart(p.id) : Infinity; }

  function renderGrid() {
    const q = (search.value || '').trim().toLowerCase();
    const list = catalog.filter(p => (!cat || p.cat === cat) && (!q || p.name.toLowerCase().includes(q) || p.barcode.toLowerCase() === q || p.sku.toLowerCase() === q));
    grid.classList.toggle('is-list', view === 'list');
    grid.replaceChildren(...list.map(p => {
      const card = document.createElement('article');
      card.className = 'cpos-card'; card.dataset.id = p.id;
      const out = p.track && p.stock <= 0;
      if (out) card.classList.add('is-out');
      const pic = document.createElement('div'); pic.className = 'cpos-pic';
      if (p.img) { const img = document.createElement('img'); img.src = p.img; img.alt = ''; img.loading = 'lazy'; pic.append(img); }
      else { const i = document.createElement('i'); i.className = 'bi bi-' + p.icon; i.setAttribute('aria-hidden', 'true'); pic.append(i); }
      const name = document.createElement('h3'); name.textContent = p.name;
      const foot = document.createElement('div'); foot.className = 'cpos-card-foot';
      const price = document.createElement('strong'); price.textContent = peso(p.price);
      const add = document.createElement('button'); add.type = 'button'; add.className = 'cpos-add';
      add.innerHTML = '<i class="bi bi-plus" aria-hidden="true"></i>Add';
      add.setAttribute('aria-label', 'Add ' + p.name);
      add.disabled = !canSell || out;
      add.addEventListener('click', () => addItem(p.id));
      foot.append(price, add);
      card.append(pic, name, foot);
      if (p.track) { const s = document.createElement('span'); s.className = 'cpos-stock' + (p.stock <= 0 ? ' out' : ''); s.textContent = p.stock <= 0 ? 'Out of stock' : p.stock + ' left'; card.append(s); }
      return card;
    }));
    empty.hidden = list.length > 0;
  }

  function addItem(id, qty = 1) {
    if (!canSell) return;
    const p = byId.get(id); if (!p) return;
    if (left(p) < qty) { flashLine(id, 'Only ' + Math.max(0, p.stock) + ' left'); return; }
    const l = cart.find(x => x.id === id);
    if (l) l.qty += qty; else cart.push({ id, qty });
    save(); renderCart(); flashLine(id);
  }
  function setQty(id, qty) {
    const p = byId.get(id); const l = cart.find(x => x.id === id); if (!l || !p) return;
    if (qty <= 0) cart = cart.filter(x => x.id !== id);
    else if (p.track && qty > p.stock) { l.qty = p.stock; flashLine(id, 'Only ' + p.stock + ' left'); }
    else l.qty = Math.min(999, qty);
    save(); renderCart();
  }
  function save() { store.set(key, JSON.stringify(cart)); }
  function total() { return cart.reduce((t, l) => t + byId.get(l.id).price * l.qty, 0); }

  function renderCart() {
    if (!canSell) return;
    const lines = $('cposLines');
    lines.replaceChildren(...cart.map(l => {
      const p = byId.get(l.id);
      const li = document.createElement('li'); li.className = 'cpos-line'; li.dataset.id = l.id;
      const pic = document.createElement('div'); pic.className = 'cpos-pic sm';
      if (p.img) { const img = document.createElement('img'); img.src = p.img; img.alt = ''; pic.append(img); } else pic.innerHTML = '<i class="bi bi-' + p.icon + '" aria-hidden="true"></i>';
      const info = document.createElement('div'); info.className = 'cpos-line-info';
      const n = document.createElement('b'); n.textContent = p.name; const u = document.createElement('small'); u.textContent = peso(p.price);
      info.append(n, u);
      const step = document.createElement('div'); step.className = 'cpos-step';
      const minus = document.createElement('button'); minus.type = 'button'; minus.textContent = '−'; minus.setAttribute('aria-label', 'One less ' + p.name);
      const q = document.createElement('input'); q.type = 'text'; q.inputMode = 'numeric'; q.value = l.qty; q.setAttribute('aria-label', 'Quantity of ' + p.name);
      const plus = document.createElement('button'); plus.type = 'button'; plus.textContent = '+'; plus.setAttribute('aria-label', 'One more ' + p.name);
      minus.addEventListener('click', () => setQty(l.id, l.qty - 1));
      plus.addEventListener('click', () => addItem(l.id));
      q.addEventListener('change', () => setQty(l.id, parseInt(q.value, 10) || 0));
      step.append(minus, q, plus);
      const amt = document.createElement('strong'); amt.className = 'cpos-line-total'; amt.textContent = peso(p.price * l.qty);
      li.append(pic, info, step, amt);
      return li;
    }));
    const items = cart.reduce((t, l) => t + l.qty, 0);
    $('cposCount').textContent = '(' + items + (items === 1 ? ' item)' : ' items)');
    $('cposHint').hidden = cart.length > 0;
    $('cposSubtotal').textContent = peso(total());
    $('cposTotal').textContent = peso(total());
    if ($('cposBarTotal')) { $('cposBarTotal').textContent = peso(total()); $('cposBarCount').textContent = items + (items === 1 ? ' item' : ' items'); }
    const charge = $('cposCharge');
    charge.disabled = cart.length === 0;
    charge.querySelector('span').textContent = 'Charge Payment (' + peso(total()) + ')';
    renderGrid();
  }
  function flashLine(id, msg) {
    const el = document.querySelector('.cpos-line[data-id="' + id + '"]');
    if (el) { el.classList.remove('pulse'); void el.offsetWidth; el.classList.add('pulse'); }
    if (msg) { const s = document.createElement('div'); s.className = 'cpos-toast'; s.textContent = msg; s.setAttribute('role', 'status'); document.body.append(s); setTimeout(() => s.remove(), 1800); }
  }

  // Search and barcode: Enter adds the exact barcode/SKU match (or the only match).
  search.addEventListener('input', renderGrid);
  search.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const q = search.value.trim().toLowerCase(); if (!q) return;
    let hit = catalog.find(p => p.barcode.toLowerCase() === q || p.sku.toLowerCase() === q);
    if (!hit) { const m = catalog.filter(p => (!cat || p.cat === cat) && p.name.toLowerCase().includes(q)); if (m.length === 1) hit = m[0]; }
    if (hit) { addItem(hit.id); search.value = ''; renderGrid(); }
    else flashLine(0, 'No product with that barcode');
  });
  document.querySelectorAll('.cpos-cat').forEach(b => b.addEventListener('click', () => {
    cat = parseInt(b.dataset.cat, 10) || 0;
    document.querySelectorAll('.cpos-cat').forEach(x => { x.classList.toggle('active', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    renderGrid();
  }));
  document.querySelectorAll('.cpos-view button').forEach(b => b.addEventListener('click', () => {
    view = b.dataset.view;
    document.querySelectorAll('.cpos-view button').forEach(x => { x.classList.toggle('active', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    try { localStorage.setItem('acadlink-canteen-view', view); } catch (_) {}
    renderGrid();
  }));
  document.querySelector('.cpos-view button[data-view="' + view + '"]')?.click();

  if (canSell) {
    $('cposClear').addEventListener('click', () => { if (cart.length && confirm('Remove every item from this order?')) { cart = []; save(); renderCart(); } });
    document.querySelectorAll('.cpos-tender button').forEach(b => b.addEventListener('click', () => {
      method = b.dataset.method;
      document.querySelectorAll('.cpos-tender button').forEach(x => { x.classList.toggle('active', x === b); x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
    }));
    const modalEl = $('cposPay');
    const modal = window.bootstrap ? new window.bootstrap.Modal(modalEl) : null;
    const tendered = $('cposTendered'), err = $('cposPayError');
    function updateChange() {
      const c = toCents(tendered.value), t = total();
      $('cposChange').textContent = c === null ? '—' : peso(Math.max(0, c - t));
      $('cposChange').classList.toggle('text-danger', c !== null && c < t);
    }
    tendered.addEventListener('input', updateChange);
    tendered.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('cposConfirm').click(); } });
    $('cposCharge').addEventListener('click', () => {
      if (!cart.length) return;
      const t = total();
      $('cposDue').textContent = peso(t); err.textContent = '';
      $('cposPayTitle').textContent = method === 'CASH' ? 'Cash payment' : 'QR payment';
      modalEl.querySelector('[data-pay="CASH"]').hidden = method !== 'CASH';
      modalEl.querySelector('[data-pay="QR"]').hidden = method !== 'QR';
      $('cposConfirm').textContent = method === 'CASH' ? 'Confirm payment' : 'Payment received';
      const quick = $('cposQuick'); quick.replaceChildren();
      const opts = [t, ...[2000, 5000, 10000, 20000, 50000, 100000].filter(v => v > t)].slice(0, 5);
      opts.forEach((v, i) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-outline-secondary btn-sm'; b.textContent = i === 0 ? 'Exact' : peso(v); b.addEventListener('click', () => { tendered.value = (v / 100).toFixed(2); updateChange(); }); quick.append(b); });
      tendered.value = ''; updateChange();
      if (modal) { modal.show(); modalEl.addEventListener('shown.bs.modal', () => (method === 'CASH' ? tendered : $('cposRef')).focus(), { once: true }); }
      else $('cposConfirm').click();
    });
    $('cposConfirm').addEventListener('click', () => {
      const t = total();
      let given = 0;
      if (method === 'CASH') {
        given = toCents(tendered.value);
        if (given === null) { err.textContent = 'Type the cash received, e.g. 100 or 100.00.'; tendered.focus(); return; }
        if (given < t) { err.textContent = 'The cash received is less than the total.'; tendered.focus(); return; }
      }
      form.elements.items.value = JSON.stringify(cart.map(l => ({ id: l.id, qty: l.qty })));
      form.elements.method.value = method;
      form.elements.tendered.value = method === 'CASH' ? (given / 100).toFixed(2) : '';
      form.elements.reference.value = method === 'QR' ? ($('cposRef').value || '').trim() : '';
      $('cposConfirm').disabled = true; $('cposConfirm').textContent = 'Saving…';
      store.set(key + '-submitted', form.elements.idem.value);
      form.submit();
    });
    // Barcode scanners type into the page: send stray keystrokes to the search box.
    document.addEventListener('keydown', e => {
      if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
      const t = e.target; if (t && (t.closest('input,textarea,select,[contenteditable]') || document.querySelector('.modal.show'))) return;
      search.focus();
    });
  }
  renderCart(); renderGrid();
})();
