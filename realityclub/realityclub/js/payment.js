/* Alur pembayaran tiket: pilih metode -> menunggu pembayaran -> selesai.
   Status dicek otomatis ke server tiap beberapa detik (api/status_pembayaran.php). */
(function () {
  'use strict';
  var modal = document.getElementById('payment-modal');
  if (!modal) return;

  var $ = function (sel, root) { return (root || modal).querySelector(sel); };
  var pay = function (name) { return document.querySelector('[data-pay="' + name + '"]'); }; // toast & tombol lanjut ada di luar modal
  var STORE = 'rc_pending_order';
  var POLL_MS = 4000;

  var S = { order: null, config: null, payment: null, method: null, provider: null,
            offset: 0, pollTimer: null, tickTimer: null, busy: false };

  var rupiah = function (n) { return 'Rp' + Number(n).toLocaleString('id-ID'); };

  /* ---------- total harga di form pembelian ---------- */
  var unitPrice = 0;
  var ticketModal = document.getElementById('ticket-modal');
  if (ticketModal) {
    var totalBox = ticketModal.querySelector('[data-ticket-total]');
    var qtySel = ticketModal.querySelector('select[name="qty"]');
    var updateTotal = function () {
      if (!totalBox) return;
      var q = parseInt(qtySel && qtySel.value, 10) || 0;
      if (unitPrice > 0 && q > 0) {
        totalBox.hidden = false;
        totalBox.querySelector('strong').textContent = rupiah(unitPrice * q);
      } else { totalBox.hidden = true; }
    };
    document.querySelectorAll('[data-modal-open="#ticket-modal"]').forEach(function (a) {
      a.addEventListener('click', function () {
        unitPrice = parseInt(a.getAttribute('data-price'), 10) || 0;
        updateTotal();
      });
    });
    if (qtySel) qtySel.addEventListener('change', updateTotal);
  }

  /* ---------- util tampilan ---------- */
  function showView(name) {
    modal.querySelectorAll('.pay-view').forEach(function (v) { v.hidden = v.getAttribute('data-view') !== name; });
    var box = modal.querySelector('.modal'); if (box) box.scrollTop = 0;
  }
  function openModal() { modal.classList.add('open'); document.body.style.overflow = 'hidden'; }
  function toast(msg) {
    var t = pay('toast'); if (!t) return;
    t.textContent = msg; t.hidden = false;
    clearTimeout(toast._t); toast._t = setTimeout(function () { t.hidden = true; }, 6000);
  }
  function setError(msg) {
    var el = pay('error'); if (!el) return;
    el.textContent = msg || ''; el.hidden = !msg;
  }
  function save() {
    try { localStorage.setItem(STORE, JSON.stringify({ id: S.order.id, token: S.order.token, config: S.config })); } catch (e) {}
  }
  function clearSaved() { try { localStorage.removeItem(STORE); } catch (e) {} }
  function api(url, data) {
    var opt = data ? { method: 'POST', body: new URLSearchParams(data) } : {};
    return fetch(url, opt).then(function (r) { return r.json(); });
  }

  /* ---------- 1. pilih metode ---------- */
  function renderChoose() {
    var o = S.order;
    pay('tour').textContent = o.tour;
    pay('qty-line').textContent = o.quantity + ' tiket × ' + rupiah(o.unit_price);
    pay('total').textContent = rupiah(o.total);
    var tfb = pay('transfer-bank');
    if (tfb && S.config && S.config.transfer) tfb.textContent = 'Transfer manual ke rekening ' + S.config.transfer.bank;

    var banks = pay('banks'); banks.innerHTML = '';
    ((S.config && S.config.banks) || []).forEach(function (b) {
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'pay-bank'; btn.textContent = b; btn.setAttribute('data-bank', b);
      btn.addEventListener('click', function () { S.provider = b; refreshChoose(); });
      banks.appendChild(btn);
    });
    S.method = null; S.provider = null; setError('');
    refreshChoose();
  }
  function refreshChoose() {
    modal.querySelectorAll('.pay-method').forEach(function (m) {
      var active = m.getAttribute('data-method') === S.method;
      m.classList.toggle('active', active);
      var h = m.querySelector('.pay-method-head'); if (h) h.setAttribute('aria-expanded', active ? 'true' : 'false');
    });
    modal.querySelectorAll('.pay-bank').forEach(function (b) {
      b.classList.toggle('selected', b.getAttribute('data-bank') === S.provider);
    });
    var ready = S.method === 'qris' || S.method === 'transfer' || (S.method === 'va' && !!S.provider);
    pay('submit').disabled = !ready || S.busy;
  }
  modal.querySelectorAll('[data-method-head]').forEach(function (h) {
    h.addEventListener('click', function () {
      S.method = h.getAttribute('data-method-head');
      if (S.method !== 'va') S.provider = null;
      setError(''); refreshChoose();
    });
  });
  pay('submit').addEventListener('click', function () {
    if (S.busy || !S.order) return;
    S.busy = true; refreshChoose(); setError('');
    api('api/bayar.php', { order_id: S.order.id, token: S.order.token, method: S.method, provider: S.provider || '' })
      .then(function (res) {
        S.busy = false;
        if (!res.ok) { setError(res.message || 'Gagal membuat pembayaran.'); refreshChoose(); return; }
        S.offset = (res.server_time || 0) - Math.floor(Date.now() / 1000);
        S.payment = res.payment;
        showWait(); startPolling(); refreshChoose();
      })
      .catch(function () {
        S.busy = false; refreshChoose();
        setError('Tidak dapat terhubung ke server. Pastikan Apache dan MySQL sudah berjalan.');
      });
  });

  /* ---------- 2. menunggu pembayaran ---------- */
  function showWait() {
    var p = S.payment; if (!p) return;
    showView('wait');
    modal.querySelectorAll('[data-method-panel]').forEach(function (el) { el.hidden = el.getAttribute('data-method-panel') !== p.method; });
    var titles = { va: 'Virtual Account ' + (p.provider || ''), qris: 'QRIS', transfer: 'Transfer Bank ' + (p.provider || '') };
    pay('wait-title').textContent = titles[p.method] || 'Pembayaran';
    pay('amount').textContent = rupiah(p.amount);
    var note = pay('amount-note');
    if (p.method === 'transfer' && p.unique_code) {
      note.hidden = false;
      note.textContent = 'Sudah termasuk kode unik ' + p.unique_code + ' (harga tiket ' + rupiah(p.base_amount) + '). Transfer persis sesuai nominal.';
    } else { note.hidden = true; }

    if (p.method === 'va') {
      pay('va-bank').textContent = 'Nomor Virtual Account ' + (p.provider || '');
      pay('va-number').textContent = p.pay_code_pretty || p.pay_code;
    } else if (p.method === 'transfer') {
      pay('tf-bank').textContent = 'Rekening ' + (p.bank || p.provider || '');
      pay('tf-number').textContent = p.pay_code;
      pay('tf-name').textContent = p.account_name || '';
    } else if (p.method === 'qris') {
      drawQR(p.pay_code);
    }
    pay('status-text').textContent = 'Menunggu pembayaran…';
    pay('simulate').hidden = !(S.config && S.config.simulation);
    startTick(); openModal(); save();
  }

  function drawQR(text) {
    var box = pay('qr'); box.innerHTML = '';
    var render = function () {
      try {
        new window.QRCode(box, { text: text, width: 220, height: 220, correctLevel: window.QRCode.CorrectLevel.M });
      } catch (e) { fallback(); }
    };
    var fallback = function () {
      var d = document.createElement('div'); d.className = 'pay-qr-fallback';
      d.textContent = 'Kode QR tidak dapat dimuat (periksa koneksi internet). Kode QRIS: ' + text;
      box.innerHTML = ''; box.appendChild(d);
    };
    if (window.QRCode) { render(); return; }
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
    s.onload = render; s.onerror = fallback;
    document.head.appendChild(s);
  }

  function startTick() {
    clearInterval(S.tickTimer);
    var el = pay('countdown');
    var tick = function () {
      if (!S.payment) return;
      var left = S.payment.expires_ts - (Math.floor(Date.now() / 1000) + S.offset);
      if (left <= 0) { el.textContent = '00:00:00'; clearInterval(S.tickTimer); checkStatus(); return; }
      var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
      el.textContent = [h, m, s].map(function (n) { return String(n).padStart(2, '0'); }).join(':');
    };
    tick(); S.tickTimer = setInterval(tick, 1000);
  }

  /* ---------- polling status ---------- */
  function startPolling() {
    clearInterval(S.pollTimer);
    S.pollTimer = setInterval(checkStatus, POLL_MS);
  }
  function stopAll() { clearInterval(S.pollTimer); clearInterval(S.tickTimer); }

  function checkStatus() {
    if (!S.order) return Promise.resolve();
    return api('api/status_pembayaran.php?order_id=' + encodeURIComponent(S.order.id) + '&token=' + encodeURIComponent(S.order.token))
      .then(function (res) {
        if (!res.ok) { if (res.message && /tidak ditemukan/i.test(res.message)) { stopAll(); clearSaved(); hideResume(); } return; }
        S.offset = (res.server_time || 0) - Math.floor(Date.now() / 1000);
        if (res.order_status === 'paid') { onPaid(res); }
        else if (res.order_status === 'expired' || res.order_status === 'cancelled') { onExpired(); }
      })
      .catch(function () { /* jaringan putus sementara: coba lagi di putaran berikut */ });
  }

  /* ---------- 3. selesai / 4. kedaluwarsa ---------- */
  function onPaid(res) {
    stopAll(); clearSaved(); hideResume();
    var info = pay('done-info');
    info.textContent = (res.tour || (S.order && S.order.tour) || '') + (res.paid_at ? ' · dibayar ' + res.paid_at : '');
    var list = pay('tickets'); list.innerHTML = '';
    (res.tickets || []).forEach(function (code) {
      var d = document.createElement('div'); d.className = 'pay-ticket'; d.textContent = code; list.appendChild(d);
    });
    showView('done'); openModal();
    toast('✓ Pembayaran diterima! Tiketmu sudah terbit.');
    S.order = null; S.payment = null;
  }
  function onExpired() {
    stopAll(); clearSaved(); hideResume();
    showView('expired'); openModal();
    S.order = null; S.payment = null;
  }

  /* ---------- tombol-tombol ---------- */
  pay('check').addEventListener('click', function () {
    var el = pay('status-text'); el.textContent = 'Memeriksa pembayaran…';
    checkStatus().then(function () {
      if (S.order) el.textContent = 'Belum ada pembayaran masuk. Menunggu pembayaran…';
    });
  });
  pay('change').addEventListener('click', function () {
    S.method = null; S.provider = null; setError('');
    showView('choose'); refreshChoose();
  });
  pay('simulate').addEventListener('click', function () {
    if (!S.order) return;
    pay('status-text').textContent = 'Mensimulasikan pembayaran masuk…';
    api('api/simulasi_bayar.php', { order_id: S.order.id, token: S.order.token })
      .then(function (res) {
        if (!res.ok) { pay('status-text').textContent = res.message || 'Simulasi gagal.'; return; }
        checkStatus();
      })
      .catch(function () { pay('status-text').textContent = 'Tidak dapat terhubung ke server.'; });
  });
  modal.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!S.payment) return;
      var text = btn.getAttribute('data-copy') === 'amount' ? String(S.payment.amount) : String(S.payment.pay_code);
      var done = function () { var old = btn.textContent; btn.textContent = 'Tersalin ✓'; setTimeout(function () { btn.textContent = old; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () {});
      } else {
        var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta);
        ta.select(); try { document.execCommand('copy'); done(); } catch (e) {} ta.remove();
      }
    });
  });

  /* ---------- lanjutkan pesanan yang belum dibayar (setelah tutup/refresh) ---------- */
  var resume = pay('resume');
  function hideResume() { if (resume) resume.hidden = true; }
  if (resume) resume.addEventListener('click', function () { if (S.payment) showWait(); else showView('choose'); openModal(); });
  modal.addEventListener('click', function () { /* ditutup lewat handler main.js; tampilkan tombol lanjut */
    setTimeout(function () { if (S.order && !modal.classList.contains('open') && resume) resume.hidden = false; }, 400);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setTimeout(function () { if (S.order && !modal.classList.contains('open') && resume) resume.hidden = false; }, 400);
  });

  /* ---------- pesanan baru dari form ---------- */
  document.addEventListener('form:success', function (ev) {
    var f = ev.target;
    if (!f || !f.matches || !f.matches('form[data-endpoint="api/pesan_tiket.php"]')) return;
    var r = ev.detail || {};
    if (!r.order) return;
    stopAll();
    S.order = r.order; S.config = r.config || {}; S.payment = null;
    save(); renderChoose(); showView('choose'); hideResume();
  });

  /* ---------- pulihkan pesanan tersimpan ---------- */
  (function restore() {
    var saved; try { saved = JSON.parse(localStorage.getItem(STORE) || 'null'); } catch (e) { saved = null; }
    if (!saved || !saved.id || !saved.token) return;
    api('api/status_pembayaran.php?order_id=' + encodeURIComponent(saved.id) + '&token=' + encodeURIComponent(saved.token))
      .then(function (res) {
        if (!res.ok || res.order_status !== 'pending') { clearSaved(); return; }
        S.config = saved.config || {};
        S.order = { id: saved.id, token: saved.token, quantity: res.order.quantity, unit_price: res.order.unit_price, total: res.order.total, tour: res.order.tour };
        S.offset = (res.server_time || 0) - Math.floor(Date.now() / 1000);
        renderChoose();
        if (res.payment && res.payment.status === 'pending') { S.payment = res.payment; startPolling(); }
        if (resume) resume.hidden = false;
      })
      .catch(function () {});
  })();
})();
