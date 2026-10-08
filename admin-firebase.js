/**
 * TINDAHAN admin (index.html) <-> PHP API <-> Firebase
 * Pumapalit ito sa inventory.js: Inventory, Reports, Payroll, Notifications.
 */
(function () {
  'use strict';

  // Kung hiwalay ang host/port ng PHP mo, palitan dito (e.g. 'http://localhost:8080/api/index.php')
  const API_URL = 'api/index.php';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = n => Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmt0 = n => Math.round(Number(n || 0)).toLocaleString('en-PH');
  const peso = n => '₱' + fmt(n);

  const CAT_EMOJI = { 'Beverages': '🥤', 'Snacks': '🍿', 'Instant noodles': '🍜', 'Biscuits': '🍪', 'Canned goods': '🥫',
                      'Condiments': '🧂', 'Frozen foods': '🧊', 'Essential': '🧺' };
  const emoji = c => CAT_EMOJI[c] || '📦';

  /* ================= toast + api ================= */
  function toast(msg, type) {
    let wrap = $('#fb-toasts');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'fb-toasts'; document.body.appendChild(wrap); }
    const el = document.createElement('div');
    el.className = 'fb-toast ' + (type || '');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 4500);
  }

  async function api(action, body) {
    const opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
    let res;
    try { res = await fetch(`${API_URL}?action=${action}`, opt); }
    catch (e) { throw new Error('Hindi makonekta sa server. Siguraduhing tumatakbo ang PHP (php -S localhost:8080).'); }
    let data;
    try { data = await res.json(); }
    catch (e) { throw new Error('Mali ang sagot ng server. Buksan ang page gamit ang PHP server (http://localhost:8080), hindi file://.'); }
    if (!data.ok) throw new Error(data.error || 'May error.');
    return data;
  }

  /* ================= notifications bell ================= */
  const bellBtn = $('#bell-btn'), bellPanel = $('#bell-panel'), bellCount = $('#bell-count');

  function ago(iso) {
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'ngayon lang';
    if (s < 3600) return Math.floor(s / 60) + ' min ago';
    if (s < 86400) return Math.floor(s / 3600) + ' hr ago';
    return Math.floor(s / 86400) + ' day(s) ago';
  }

  async function loadNotifs() {
    try {
      const d = await api('notifications');
      bellCount.hidden = !d.unread;
      bellCount.textContent = d.unread > 9 ? '9+' : d.unread;
      bellPanel.innerHTML =
        `<div class="bp-head"><b>Notifications</b><button type="button" id="bp-readall">Mark all read</button></div>` +
        (d.notifications.length
          ? d.notifications.map(n => `
            <div class="bp-item ${n.read ? '' : 'unread'}" data-id="${esc(n.id)}">
              <span class="bp-dot ${esc(n.type)}"></span>
              <div><b>${esc(n.title)}</b><p>${esc(n.msg)}</p><small>${ago(n.ts)}</small></div>
            </div>`).join('')
          : '<p class="bp-empty">Walang notifications pa.</p>');
      $('#bp-readall').onclick = async () => { await api('notif_read', { all: true }); loadNotifs(); };
      $$('.bp-item.unread', bellPanel).forEach(el => el.onclick = async () => { await api('notif_read', { id: el.dataset.id }); loadNotifs(); });
    } catch (e) { /* tahimik lang; lalabas ang error sa ibang parte */ }
  }
  bellBtn.addEventListener('click', e => { e.stopPropagation(); bellPanel.hidden = !bellPanel.hidden; if (!bellPanel.hidden) loadNotifs(); });
  document.addEventListener('click', e => { if (!bellPanel.hidden && !bellPanel.contains(e.target)) bellPanel.hidden = true; });

  /* ================= INVENTORY ================= */
  let products = [];
  let selectedId = null;
  const tbody = $('#inventory-table-body');
  const modal = $('#product-modal');

  const statusOf = p => p.stock <= 0 ? ['Out of stock', 'out-stock'] : (p.stock <= p.reorder ? ['Low stock', 'low-stock'] : ['In stock', 'in-stock']);
  const landed = p => p.cost + p.shipping + p.other;

  async function loadProducts() {
    try {
      const d = await api('products');
      products = d.products;
      // dagdagan ang category filter ng mga category na galing sa database
      const sel = $('#filter-category');
      const have = new Set($$('option', sel).map(o => o.value));
      products.forEach(p => { if (!have.has(p.category)) { sel.add(new Option(p.category, p.category)); have.add(p.category); } });
      if (!products.some(p => p.id === selectedId)) selectedId = products[0] ? products[0].id : null;
      renderInventory();
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="8" class="fb-empty">${esc(e.message)}</td></tr>`;
      toast(e.message, 'error');
    }
  }

  function renderInventory() {
    const q = $('#inv-search').value.trim().toLowerCase();
    const cat = $('#filter-category').value, st = $('#filter-status').value;
    const rows = products.filter(p =>
      (cat === 'All' || p.category === cat) &&
      (st === 'All' || statusOf(p)[0] === st) &&
      (!q || p.name.toLowerCase().includes(q) || (p.barcode && p.barcode.includes(q))));

    tbody.innerHTML = rows.length ? rows.map(p => {
      const [label, cls] = statusOf(p);
      return `<tr data-id="${p.id}" class="${p.id === selectedId ? 'fb-selected' : ''}">
        <td>${emoji(p.category)} ${esc(p.name)}${p.barcode ? `<br><small class="fb-muted">${esc(p.barcode)}</small>` : ''}</td>
        <td>${esc(p.category)}</td><td>${peso(p.cost)}</td><td>${peso(p.price)}</td><td>${p.stock}</td>
        <td>${peso(p.stock * p.cost)}</td><td><span class="badge ${cls}">${label}</span></td>
        <td><button class="row-btn" data-act="edit" title="Edit">✏️</button><button class="row-btn" data-act="del" title="Delete">🗑️</button></td></tr>`;
    }).join('') : `<tr><td colspan="8" class="fb-empty">${products.length ? 'Walang tugma sa filter.' : 'Wala pang products. I-click ang + Add Product.'}</td></tr>`;

    // cards
    $('#card-total-items').textContent = products.length;
    $('#card-total-value').textContent = peso(products.reduce((s, p) => s + p.stock * p.cost, 0));
    $('#card-potential-profit').textContent = peso(products.reduce((s, p) => s + p.stock * (p.price - landed(p)), 0));
    $('#card-low-stock').textContent = products.filter(p => p.stock <= p.reorder).length;
    renderSide();
  }

  function renderSide() {
    const p = products.find(x => x.id === selectedId);
    if (!p) {
      $('#side-img').textContent = '📦'; $('#side-name').textContent = 'Select a product'; $('#side-category').textContent = '-';
      ['unit-cost', 'shipping', 'other-cost', 'total-unit-cost', 'selling-price', 'profit'].forEach(k => $('#side-' + k).textContent = '₱0.00');
      $('#side-margin').textContent = '0%';
      return;
    }
    const total = landed(p), profit = p.price - total;
    $('#side-img').textContent = emoji(p.category);
    $('#side-name').textContent = p.name;
    $('#side-category').textContent = p.category;
    $('#side-unit-cost').textContent = peso(p.cost);
    $('#side-shipping').textContent = peso(p.shipping);
    $('#side-other-cost').textContent = peso(p.other);
    $('#side-total-unit-cost').textContent = peso(total);
    $('#side-selling-price').textContent = peso(p.price);
    $('#side-profit').textContent = peso(profit);
    $('#side-margin').textContent = (p.price > 0 ? (profit / p.price * 100).toFixed(1) : 0) + '%';
  }

  tbody.addEventListener('click', async e => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const id = +tr.dataset.id, act = e.target.closest('[data-act]')?.dataset.act;
    const p = products.find(x => x.id === id);
    if (act === 'edit') return openModal(p);
    if (act === 'del') {
      if (!confirm(`I-delete ang "${p.name}"? Hindi nito mabubura ang dating benta.`)) return;
      try { await api('product_delete', { id }); toast('Na-delete ang product.'); loadProducts(); } catch (err) { toast(err.message, 'error'); }
      return;
    }
    selectedId = id; renderInventory();
  });
  ['inv-search', 'filter-category', 'filter-status'].forEach(id => $('#' + id).addEventListener('input', renderInventory));

  function openModal(p, prefill) {
    const f = $('#add-product-form');
    f.reset();
    $('#modal-title').textContent = p ? 'Edit Product' : 'Add New Product';
    $('#p-id').value = p ? p.id : '';
    if (p) {
      $('#p-name').value = p.name; $('#p-category').value = p.category; $('#p-cost').value = p.cost; $('#p-price').value = p.price;
      $('#p-qty').value = p.stock; $('#p-barcode').value = p.barcode; $('#p-shipping').value = p.shipping; $('#p-other').value = p.other;
    }
    if (prefill && prefill.barcode) $('#p-barcode').value = prefill.barcode;
    modal.showModal();
    $('#p-name').focus();
  }
  $('#open-add-modal-btn').addEventListener('click', () => openModal(null));
  $('#close-modal-btn').addEventListener('click', () => modal.close());

  $('#add-product-form').addEventListener('submit', async e => {
    e.preventDefault();
    const body = {
      id: $('#p-id').value || undefined, name: $('#p-name').value, category: $('#p-category').value,
      cost: $('#p-cost').value, price: $('#p-price').value, stock: $('#p-qty').value,
      barcode: $('#p-barcode').value, shipping: $('#p-shipping').value, other: $('#p-other').value,
    };
    try {
      const d = await api('product_save', body);
      modal.close();
      selectedId = d.product.id;
      toast(body.id ? 'Na-update ang product.' : 'Na-add ang product sa Firebase.');
      await loadProducts();
      loadNotifs();
    } catch (err) { toast(err.message, 'error'); }
  });

  // ----- barcode: scanner/keyboard + camera -----
  function handleBarcode(code) {
    code = String(code).trim();
    if (!code) return;
    const p = products.find(x => x.barcode === code);
    if (p) {
      selectedId = p.id;
      $('#filter-category').value = 'All'; $('#filter-status').value = 'All'; $('#inv-search').value = '';
      renderInventory();
      $(`tr[data-id="${p.id}"]`, tbody)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      toast(`Found: ${p.name} - ${p.stock} in stock`);
    } else if (confirm(`Walang product na may barcode ${code}.\nMag-add ng bagong product?`)) {
      openModal(null, { barcode: code });
    }
  }
  $('#barcode-input').addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    e.preventDefault(); handleBarcode(e.target.value); e.target.value = '';
  });

  let qr = null, camOn = false, lastCode = '', lastAt = 0;
  $('#toggle-camera-btn').addEventListener('click', async () => {
    const btn = $('#toggle-camera-btn'), box = $('#camera-reader-container');
    if (camOn) {
      try { await qr.stop(); qr.clear(); } catch (e) {}
      box.style.display = 'none'; camOn = false; btn.textContent = '📷 Open Camera Scanner';
      return;
    }
    if (typeof Html5Qrcode === 'undefined') return toast('Hindi na-load ang scanner library (check internet).', 'error');
    box.style.display = 'block';
    qr = new Html5Qrcode('reader');
    try {
      await qr.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 250, height: 150 } }, code => {
        const now = Date.now();
        if (code === lastCode && now - lastAt < 2500) return;
        lastCode = code; lastAt = now; handleBarcode(code);
      });
      camOn = true; btn.textContent = '⏹ Close Camera Scanner';
    } catch (e) { box.style.display = 'none'; toast('Hindi ma-open ang camera: ' + e, 'error'); }
  });

  /* ================= REPORTS ================= */
  const PALETTE = ['#d59c7c', '#92c2a5', '#d7bb70', '#eadc9e', '#667d58', '#b77c67'];
  const PAY_LABEL = { cash: 'Cash', gcash: 'GCash', maya: 'Maya' };

  function niceMax(max) {
    const raw = Math.max(max, 1) / 5, mag = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 1.5, 2, 2.5, 3, 4, 5, 10].map(m => m * mag).find(s => s >= raw);
    return step * 5;
  }
  function donut(el, items, valueKey) {
    const total = items.reduce((s, i) => s + i[valueKey], 0);
    if (!total) { el.style.background = '#e6e6e6'; return total; }
    let acc = 0;
    el.style.background = 'conic-gradient(' + items.map((it, i) => {
      const from = acc / total * 100; acc += it[valueKey];
      return `${PALETTE[i % PALETTE.length]} ${from.toFixed(2)}% ${(acc / total * 100).toFixed(2)}%`;
    }).join(', ') + ')';
    return total;
  }
  function legend(el, items, nameFn, valueKey) {
    el.innerHTML = items.length ? items.map((it, i) =>
      `<div class="expense-legend-row"><span class="expense-dot" style="background:${PALETTE[i % PALETTE.length]};"></span><span class="expense-name">${esc(nameFn(it))}</span><span class="expense-value">₱${fmt0(it[valueKey])}</span></div>`).join('')
      : '<div class="expense-legend-row"><span class="expense-name fb-muted">Wala pang data</span></div>';
  }
  function topN(list, n, valueKey) {
    if (list.length <= n) return list;
    const rest = list.slice(n - 1).reduce((s, i) => s + i[valueKey], 0);
    return list.slice(0, n - 1).concat([{ name: 'Others', [valueKey]: rest }]);
  }

  async function loadReports() {
    const rs = $('#reports');
    const days = +$('#report-days').value || 7;
    let d;
    try { d = await api('reports&days=' + days); }
    catch (e) { $('#report-source').textContent = '⚠ ' + e.message; return toast(e.message, 'error'); }

    const t = d.totals, c = d.change;
    const nums = $$('.report-card-number', rs), notes = $$('.report-card-note', rs);
    nums[0].textContent = '₱ ' + fmt(t.revenue); nums[1].textContent = '₱ ' + fmt(t.cost);
    nums[2].textContent = '₱ ' + fmt(t.profit); nums[3].textContent = t.orders;
    const vs = days === 7 ? 'previous week' : `previous ${days} days`;
    ['revenue', 'cost', 'profit', 'orders'].forEach((k, i) => {
      notes[i].textContent = c[k] === null ? '— walang previous data' : `${c[k] >= 0 ? '↑ +' : '↓ '}${c[k]}% vs ${vs}`;
    });

    // revenue & profit chart
    const S = d.series, N = S.length;
    const top = niceMax(Math.max(...S.map(s => s.revenue)));
    const small = N > 7 ? ' style="font-size:8px"' : '';
    $('.revenue-chart', rs).innerHTML =
      '<div class="revenue-grid"></div>' +
      `<div class="chart-y-labels">${[1, .8, .6, .4, .2, 0].map(f => `<span>${fmt0(top * f)}</span>`).join('')}</div>` +
      S.map(s => `<div class="chart-day" title="${esc(s.label)} - Sales ₱${fmt(s.revenue)} | Profit ₱${fmt(s.profit)} | ${s.orders} orders">
          <div class="chart-bar" style="height:${s.revenue > 0 ? Math.max(1.5, s.revenue / top * 100) : 0}%"></div>
          <span class="chart-day-label"${small}>${esc(s.label)}</span></div>`).join('') +
      `<div class="chart-line"><svg viewBox="0 0 700 197" preserveAspectRatio="none"><polyline fill="none" stroke="#e5a9a9" stroke-width="3" points="${
        S.map((s, i) => `${((i + .5) * 700 / N).toFixed(1)},${(197 - s.profit / top * 197).toFixed(1)}`).join(' ')}"/>${
        S.map((s, i) => `<circle cx="${((i + .5) * 700 / N).toFixed(1)}" cy="${(197 - s.profit / top * 197).toFixed(1)}" r="4" fill="#e5a9a9"/>`).join('')}</svg></div>`;

    // donut 1: cost of goods by category
    const cats = topN(d.byCategory, 6, 'cost');
    const total1 = donut($('.expense-donut', rs), cats, 'cost');
    $('.expense-donut-center', rs).innerHTML = `<strong>₱ ${fmt0(total1)}</strong><span>Cost of Goods</span>`;
    legend($('.expense-chart-area .expense-legend', rs), cats, i => i.name, 'cost');
    $('.expense-donut', rs).closest('.report-box').querySelector('h2').textContent = 'Cost of Goods by Category';

    // donut 2: sales by payment method
    const pays = d.byPayment.map(p => ({ name: PAY_LABEL[p.name] || p.name, amount: p.amount }));
    const total2 = donut($('.bottom-expense-donut', rs), pays, 'amount');
    $('.bottom-donut-center', rs).innerHTML = `<strong>₱ ${fmt0(total2)}</strong><span>Total Sales</span>`;
    legend($('.report-bottom-row .expense-legend', rs), pays, i => i.name, 'amount');
    $('.bottom-expense-donut', rs).closest('.report-box').querySelector('h2').textContent = 'Sales by Payment Method';

    // top products
    const box = $('.top-sales', rs);
    $$('.top-sale', box).forEach(x => x.remove());
    const maxQ = Math.max(1, ...d.topProducts.map(p => p.qty));
    box.insertAdjacentHTML('beforeend', d.topProducts.length ? d.topProducts.map(p =>
      `<div class="top-sale"><div class="top-sale-image">${emoji(p.cat)}</div><div class="top-sale-bar" style="width:${(p.qty / maxQ * 100).toFixed(0)}%">${p.qty}</div><span class="top-sale-prod">${esc(p.name)}</span></div>`).join('')
      : '<p class="fb-muted">Wala pang benta sa range na ito.</p>');
    $('h2', box).textContent = days === 7 ? "This week's top sales (units)" : `Top sales - last ${days} days (units)`;

    // recent sales
    const tbl = $('.recent-sales-table', rs);
    $$('tr', tbl).filter(r => !r.querySelector('th')).forEach(r => r.remove());
    (tbl.tBodies[0] || tbl).insertAdjacentHTML('beforeend', d.recent.length ? d.recent.map(r => {
      const when = new Date(r.ts).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
      return `<tr><td>${esc(when)}</td><td>#${esc(r.receiptNo)}</td><td>${r.items}</td><td>₱${fmt(r.total)}</td><td>${esc(PAY_LABEL[r.method] || r.method)}</td><td class="recent-complete">${r.status === 'completed' ? 'Complete' : esc(r.status)}</td></tr>`;
    }).join('') : '<tr><td colspan="6" class="fb-muted">Wala pang benta.</td></tr>');

    // source line + demo buttons
    $('#report-source').textContent = (t.orders ? '● Live from Firebase' : 'Wala pang benta. Mag-benta sa POS o i-load ang demo sales.') + `  ·  ${d.range.from} → ${d.range.to}`;
    $('#demo-load').hidden = !(d.demo && !d.demoLoaded);
    $('#demo-clear').hidden = !(d.demo && d.demoLoaded);
  }
  $('#report-days').addEventListener('change', loadReports);
  $('#demo-load').addEventListener('click', async () => {
    if (!confirm('Mag-lo-load ito ng ~600 sample na benta (14 days) sa Firebase mo, naka-mark bilang "demo" para pwedeng i-clear. Itutuloy?')) return;
    try { const r = await api('demo_sales', {}); toast(`Na-load ang ${r.transactions} demo sales.`); loadReports(); } catch (e) { toast(e.message, 'error'); }
  });
  $('#demo-clear').addEventListener('click', async () => {
    if (!confirm('Buburahin ang lahat ng demo sales. Hindi apektado ang totoong benta. Itutuloy?')) return;
    try { const r = await api('demo_clear', {}); toast(`Nabura ang ${r.deleted} demo sales.`); loadReports(); } catch (e) { toast(e.message, 'error'); }
  });

  /* ================= PAYROLL ================= */
  const pr = { period: null, data: null, sel: null };
  const prBody = $('#pr-body');

  async function loadPayroll(pid) {
    try {
      const d = await api('payroll' + (pid ? '&period=' + encodeURIComponent(pid) : ''));
      pr.data = d; pr.period = d.period.id;
      if (!d.employees.some(e => e.id === pr.sel)) pr.sel = d.employees[0] ? d.employees[0].id : null;
      const sel = $('#pr-period');
      sel.innerHTML = d.periods.map((p, i) => `<option value="${p.id}" ${p.id === pr.period ? 'selected' : ''}>📅 ${esc(p.label)}${i === 0 ? ' (current)' : ''}</option>`).join('');
      renderPayroll();
    } catch (e) {
      prBody.innerHTML = `<tr><td colspan="8" class="fb-empty">${esc(e.message)}</td></tr>`;
      toast(e.message, 'error');
    }
  }

  function renderPayroll() {
    const d = pr.data;
    $('#pr-count').textContent = d.totals.count;
    $('#pr-gross').textContent = peso(d.totals.gross);
    $('#pr-ded').textContent = peso(d.totals.deductions);
    $('#pr-net').textContent = peso(d.totals.net);

    const q = $('#pr-search').value.trim().toLowerCase(), st = $('#pr-status').value;
    const rows = d.employees.filter(e => (st === 'All' || e.status === st) && (!q || e.name.toLowerCase().includes(q) || e.position.toLowerCase().includes(q)));
    prBody.innerHTML = rows.length ? rows.map(e => `
      <tr data-id="${e.id}" class="${e.id === pr.sel ? 'fb-selected' : ''}">
        <td>${esc(e.name)}</td><td>${esc(e.position)}</td><td>${peso(e.pay.regular)}</td><td>${peso(e.pay.overtime)}</td>
        <td>${peso(e.pay.deductions)}</td><td>${peso(e.pay.net)}</td>
        <td><span class="badge ${e.status === 'Processed' ? 'in-stock' : 'low-stock'}">${e.status}</span></td>
        <td><button class="row-btn" data-act="inputs" title="Edit days / OT / deductions">⋮</button></td></tr>`).join('')
      : `<tr><td colspan="8" class="fb-empty">${d.employees.length ? 'Walang tugma sa filter.' : 'Wala pang employee. I-click ang + Add Employee.'}</td></tr>`;
    renderPayDetail();
  }

  function renderPayDetail() {
    const box = $('#pr-detail');
    const e = pr.data.employees.find(x => x.id === pr.sel);
    $('#pr-toggle-status').disabled = $('#pr-edit-inputs').disabled = $('#pr-edit-emp').disabled = $('#pr-del-emp').disabled = !e;
    if (!e) { box.innerHTML = '<h3>Selected Employee</h3><p class="fb-muted">Wala pang napiling employee.</p>'; return; }
    const p = e.pay, ini = e.name.split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
    $('#pr-toggle-status').textContent = e.status === 'Processed' ? 'Mark as Pending' : 'Mark as Processed';
    box.innerHTML = `
      <h3>Selected Employee</h3>
      <div class="product"><div class="avatar">${esc(ini)}</div><div><b>${esc(e.name)}</b><p class="small">${esc(e.position)} · ${peso(e.dailyRate)}/day</p></div></div>
      <p class="small">${e.daysWorked} day(s) · ${e.otHours} OT hr(s) · ${e.lateMin} min late/undertime</p>
      <h4 class="sub">Salary Breakdown</h4>
      <div class="row"><span>Regular Pay</span><span>${peso(p.regular)}</span></div>
      <div class="row"><span>Overtime Pay (125%)</span><span>${peso(p.overtime)}</span></div>
      <div class="row gross"><span>Gross Pay</span><span>${peso(p.gross)}</span></div>
      <h4 class="sub">Deductions</h4>
      <div class="row"><span>Late/Undertime</span><span>${peso(p.late)}</span></div>
      <div class="row"><span>SSS</span><span>${peso(p.sss)}</span></div>
      <div class="row"><span>PhilHealth</span><span>${peso(p.philhealth)}</span></div>
      <div class="row"><span>Pag-IBIG</span><span>${peso(p.pagibig)}</span></div>
      <div class="row"><span>Withholding Tax</span><span>${peso(p.tax)}</span></div>
      <div class="row"><span>Other Deductions</span><span>${peso(p.other)}</span></div>
      <div class="row ded-total"><span>Total Deductions</span><span>${peso(p.deductions)}</span></div>
      <div class="net-box"><p>Net Salary</p><h3>${peso(p.net)}</h3></div>
      <p class="small" style="margin-top:8px">Estimate lang (semi-monthly). Pakisuri laban sa kasalukuyang SSS/PhilHealth/Pag-IBIG tables.</p>`;
  }

  prBody.addEventListener('click', e => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    pr.sel = +tr.dataset.id; renderPayroll();
    if (e.target.closest('[data-act="inputs"]')) openPayModal();
  });
  $('#pr-period').addEventListener('change', e => loadPayroll(e.target.value));
  ['pr-search', 'pr-status'].forEach(id => $('#' + id).addEventListener('input', () => pr.data && renderPayroll()));

  const empModal = $('#emp-modal'), payModal = $('#pay-modal');
  const curEmp = () => pr.data.employees.find(x => x.id === pr.sel);

  function openEmpModal(e) {
    $('#emp-form').reset();
    $('#emp-title').textContent = e ? 'Edit Employee' : 'Add Employee';
    $('#emp-id').value = e ? e.id : '';
    if (e) { $('#emp-name').value = e.name; $('#emp-position').value = e.position; $('#emp-rate').value = e.dailyRate; }
    empModal.showModal(); $('#emp-name').focus();
  }
  function openPayModal() {
    const e = curEmp(); if (!e) return;
    $('#pay-title').textContent = `Payroll inputs - ${e.name}`;
    $('#pay-days').value = e.daysWorked; $('#pay-ot').value = e.otHours; $('#pay-late').value = e.lateMin; $('#pay-other').value = e.otherDed;
    payModal.showModal();
  }
  $('#pr-add-emp').addEventListener('click', () => openEmpModal(null));
  $('#pr-edit-emp').addEventListener('click', () => curEmp() && openEmpModal(curEmp()));
  $('#pr-edit-inputs').addEventListener('click', openPayModal);
  $('#emp-cancel').addEventListener('click', () => empModal.close());
  $('#pay-cancel').addEventListener('click', () => payModal.close());

  $('#emp-form').addEventListener('submit', async ev => {
    ev.preventDefault();
    try {
      const r = await api('employee_save', { id: $('#emp-id').value || undefined, name: $('#emp-name').value, position: $('#emp-position').value, dailyRate: $('#emp-rate').value });
      empModal.close(); pr.sel = r.employee.id; toast('Na-save ang employee sa Firebase.'); loadPayroll(pr.period);
    } catch (e) { toast(e.message, 'error'); }
  });
  $('#pay-form').addEventListener('submit', async ev => {
    ev.preventDefault();
    try {
      await api('payroll_save', { period: pr.period, emp: pr.sel, daysWorked: $('#pay-days').value, otHours: $('#pay-ot').value, lateMin: $('#pay-late').value, otherDed: $('#pay-other').value });
      payModal.close(); toast('Na-save ang payroll inputs.'); loadPayroll(pr.period);
    } catch (e) { toast(e.message, 'error'); }
  });
  $('#pr-toggle-status').addEventListener('click', async () => {
    const e = curEmp(); if (!e) return;
    try { await api('payroll_status', { period: pr.period, emp: e.id, status: e.status === 'Processed' ? 'Pending' : 'Processed' }); loadPayroll(pr.period); loadNotifs(); }
    catch (err) { toast(err.message, 'error'); }
  });
  $('#pr-del-emp').addEventListener('click', async () => {
    const e = curEmp(); if (!e || !confirm(`Tanggalin si ${e.name} sa listahan?`)) return;
    try { await api('employee_delete', { id: e.id }); toast('Natanggal ang employee.'); loadPayroll(pr.period); } catch (err) { toast(err.message, 'error'); }
  });

  $('#pr-export').addEventListener('click', () => {
    if (!pr.data || !pr.data.employees.length) return toast('Walang employee na ie-export.', 'error');
    const head = ['Employee', 'Position', 'Daily Rate', 'Days', 'OT Hours', 'Regular Pay', 'Overtime Pay', 'Gross Pay', 'Late/Undertime', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Tax', 'Other', 'Total Deductions', 'Net Pay', 'Status'];
    const q = v => `"${String(v).replace(/"/g, '""')}"`;
    const lines = [head.map(q).join(',')].concat(pr.data.employees.map(e => [e.name, e.position, e.dailyRate, e.daysWorked, e.otHours,
      e.pay.regular, e.pay.overtime, e.pay.gross, e.pay.late, e.pay.sss, e.pay.philhealth, e.pay.pagibig, e.pay.tax, e.pay.other, e.pay.deductions, e.pay.net, e.status].map(q).join(',')));
    const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = `payroll-${pr.period}.csv`; a.click();
    URL.revokeObjectURL(a.href);
  });

  /* ================= boot ================= */
  // i-refresh ang data tuwing bubuksan ang tab (para laging updated sa POS)
  $('#tab-inventory').addEventListener('change', loadProducts);
  $('#tab-reports').addEventListener('change', loadReports);
  $('#tab-payroll').addEventListener('change', () => loadPayroll(pr.period));

  loadProducts();
  loadReports();
  loadPayroll();
  loadNotifs();
  setInterval(loadNotifs, 30000);
})();
