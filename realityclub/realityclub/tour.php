<?php require_once __DIR__ . '/includes/helpers.php'; $pdo = db(); ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tour — Reality Club</title>
<meta name="description" content="Jadwal konser Reality Club dan cara membeli tiket.">
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/pages.css">
<link rel="stylesheet" href="css/payment.css?v=<?= filemtime(__DIR__ . '/css/payment.css') ?>">
</head>
<body>

<div class="loader"><div class="loader-mark">REALITY&nbsp;CLUB</div><div class="loader-bar"></div></div>

<nav class="navbar">
  <div class="nav-inner">
    <a href="index.php" class="brand"><span class="brand-mark"></span>Reality Club</a>
    <button class="nav-toggle" aria-label="Buka menu" aria-expanded="false">
      <svg width="22" height="16" viewBox="0 0 22 16" fill="none"><path d="M0 1H22M0 8H22M0 15H22" stroke="currentColor" stroke-width="1.4"/></svg>
    </button>
    <ul class="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="band.php">The Band</a></li>
      <li><a href="music.php">Music</a></li>
      <li><a href="tour.php" class="active">Tour</a></li>
      <li><a href="shop.php">Shop</a></li>
      <li><a href="contact.php">Contact</a></li>
    </ul>
    <a href="#tour-schedule" class="nav-cta">Get Tickets</a>
  </div>
</nav>

<header class="page-hero">
  <div class="container">
    <div class="eyebrow reveal">Live 2026</div>
    <h1 class="reveal mt-2">Kami akan<br>ke kotamu.</h1>
    <p class="reveal">Semua jadwal konser Reality Club — dari panggung kecil di Bandung sampai tur regional Asia Tenggara.</p>
  </div>
</header>

<section id="tour-schedule">
  <div class="container">
    <div class="section-head reveal">
      <div class="eyebrow">Jadwal Konser</div>
      <h2>Upcoming Shows</h2>
    </div>

    <div class="tour-list">
<?php render_tour_cards($pdo->query("SELECT * FROM tours ORDER BY show_date")->fetchAll(), false); ?>
    </div>
  </div>
</section>

<div class="section-divider">
  <div class="waveform-wrap">
    <svg viewBox="0 0 1200 60" preserveAspectRatio="none"><path class="wave-path dim" d="M0,30 Q50,5 100,30 T200,30 T300,30 T400,30 T500,30 T600,30 T700,30 T800,30 T900,30 T1000,30 T1100,30 T1200,30"/></svg>
  </div>
</div>

<section id="faq">
  <div class="container">
    <div class="section-head reveal">
      <div class="eyebrow">Perlu Tahu</div>
      <h2>Frequently Asked</h2>
    </div>

    <div class="reveal" style="max-width:720px;">
<?php foreach ($pdo->query("SELECT * FROM faqs ORDER BY sort_order, id")->fetchAll() as $f): ?>
      <div class="faq-item">
        <div class="faq-q"><span><?= e($f['question']) ?></span><span class="plus">+</span></div>
        <div class="faq-a"><p><?= e($f['answer']) ?></p></div>
      </div>
<?php endforeach; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>


<button class="back-to-top" aria-label="Kembali ke atas">
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 13V3M8 3L3 8M8 3L13 8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
</button>

<!-- Ticket modal -->
<div class="modal-overlay" id="ticket-modal">
  <div class="modal">
    <button class="modal-close" data-modal-close aria-label="Tutup">×</button>
    <div class="eyebrow">Beli Tiket</div>
    <h3 class="mt-2" data-fill="tour-name">—</h3>
    <form data-validate="#payment-modal" data-endpoint="api/pesan_tiket.php" class="mt-4">
      <input type="hidden" name="tour_id">
      <div class="field">
        <label>Nama Lengkap</label>
        <input type="text" name="name" required placeholder="Nama sesuai KTP">
        <span class="field-error">Nama wajib diisi.</span>
      </div>
      <div class="field">
        <label>Email</label>
        <input type="email" name="email" required placeholder="nama@email.com">
        <span class="field-error">Masukkan email yang valid.</span>
      </div>
      <div class="field">
        <label>Nomor HP</label>
        <input type="tel" name="phone" required placeholder="08xxxxxxxxxx">
        <span class="field-error">Nomor HP wajib diisi.</span>
      </div>
      <div class="field">
        <label>Jumlah Tiket</label>
        <select name="qty" required>
          <option value="">Pilih jumlah</option>
          <option>1</option><option>2</option><option>3</option><option>4</option>
        </select>
        <span class="field-error">Pilih jumlah tiket.</span>
      </div>
      <div class="ticket-total" data-ticket-total hidden><span>Total bayar</span><strong>—</strong></div>
      <button type="submit" class="btn btn-primary mt-2" style="width:100%;justify-content:center;">Lanjut ke Pembayaran</button>
    </form>
  </div>
</div>

<!-- Waitlist modal -->
<div class="modal-overlay" id="waitlist-modal">
  <div class="modal">
    <button class="modal-close" data-modal-close aria-label="Tutup">×</button>
    <div class="eyebrow">Waitlist</div>
    <h3 class="mt-2" data-fill="tour-name">—</h3>
    <p class="mt-2" style="color:var(--silver);">Show ini sudah sold out. Daftar di waitlist dan kami kabari jika ada tiket tambahan dirilis.</p>
    <form data-validate="#success-modal" data-endpoint="api/waitlist.php" class="mt-4">
      <input type="hidden" name="tour_id">
      <div class="field">
        <label>Email</label>
        <input type="email" name="email" required placeholder="nama@email.com">
        <span class="field-error">Masukkan email yang valid.</span>
      </div>
      <button type="submit" class="btn btn-primary mt-2" style="width:100%;justify-content:center;">Gabung Waitlist</button>
    </form>
  </div>
</div>

<!-- Modal pembayaran tiket -->
<div class="modal-overlay" id="payment-modal">
  <div class="modal pay-modal" role="dialog" aria-modal="true" aria-labelledby="pay-title">
    <button class="modal-close" data-modal-close aria-label="Tutup">×</button>

    <!-- 1. Pilih metode -->
    <section class="pay-view" data-view="choose">
      <div class="eyebrow">Pembayaran</div>
      <h3 class="mt-2" id="pay-title">Pilih Metode Pembayaran</h3>
      <div class="pay-summary">
        <div class="pay-tour" data-pay="tour"></div>
        <div class="pay-line"><span data-pay="qty-line"></span><strong data-pay="total"></strong></div>
      </div>

      <div class="pay-methods">
        <div class="pay-method" data-method="va">
          <button type="button" class="pay-method-head" data-method-head="va" aria-expanded="false">
            <span class="pay-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 10l9-6 9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18"/></svg></span>
            <span class="pay-method-txt"><strong>Virtual Account</strong><small>BCA, BNI, BRI, Mandiri</small></span>
          </button>
          <div class="pay-method-body"><div class="pay-banks" data-pay="banks"></div></div>
        </div>
        <div class="pay-method" data-method="qris">
          <button type="button" class="pay-method-head" data-method-head="qris" aria-expanded="false">
            <span class="pay-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h3M20 17v4"/></svg></span>
            <span class="pay-method-txt"><strong>QRIS</strong><small>GoPay, OVO, DANA, ShopeePay, m-banking</small></span>
          </button>
        </div>
        <div class="pay-method" data-method="transfer">
          <button type="button" class="pay-method-head" data-method-head="transfer" aria-expanded="false">
            <span class="pay-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h14l-3-3M20 16H6l3 3"/></svg></span>
            <span class="pay-method-txt"><strong>Transfer Bank</strong><small data-pay="transfer-bank">Transfer manual ke rekening</small></span>
          </button>
        </div>
      </div>

      <p class="pay-error" data-pay="error" hidden></p>
      <button type="button" class="btn btn-primary pay-submit" data-pay="submit" disabled>Bayar Sekarang</button>
    </section>

    <!-- 2. Menunggu pembayaran -->
    <section class="pay-view" data-view="wait" hidden>
      <div class="eyebrow">Menunggu Pembayaran</div>
      <h3 class="mt-2" data-pay="wait-title">—</h3>
      <div class="pay-timer"><span>Bayar sebelum</span><strong data-pay="countdown">--:--:--</strong></div>

      <div class="pay-box">
        <div class="pay-row"><span>Total yang harus dibayar</span>
          <div class="pay-copy"><strong class="pay-amount" data-pay="amount">—</strong><button type="button" class="pay-copy-btn" data-copy="amount">Salin</button></div>
        </div>
        <p class="pay-note" data-pay="amount-note" hidden></p>

        <div data-method-panel="va" hidden>
          <div class="pay-row"><span data-pay="va-bank">Nomor Virtual Account</span>
            <div class="pay-copy"><strong class="pay-code" data-pay="va-number">—</strong><button type="button" class="pay-copy-btn" data-copy="code">Salin</button></div>
          </div>
          <ol class="pay-steps">
            <li>Buka aplikasi m-banking / ATM / internet banking bank tersebut.</li>
            <li>Pilih menu <em>Transfer → Virtual Account</em> lalu masukkan nomor di atas.</li>
            <li>Pastikan nominal sesuai, lalu konfirmasi pembayaran.</li>
          </ol>
        </div>

        <div data-method-panel="qris" hidden>
          <div class="pay-qr" data-pay="qr"></div>
          <ol class="pay-steps">
            <li>Buka e-wallet atau m-banking yang mendukung QRIS.</li>
            <li>Pilih <em>Scan / Bayar</em> lalu arahkan kamera ke kode QR.</li>
            <li>Cek nominal lalu konfirmasi pembayaran.</li>
          </ol>
        </div>

        <div data-method-panel="transfer" hidden>
          <div class="pay-row"><span data-pay="tf-bank">Rekening tujuan</span>
            <div class="pay-copy"><strong class="pay-code" data-pay="tf-number">—</strong><button type="button" class="pay-copy-btn" data-copy="code">Salin</button></div>
          </div>
          <div class="pay-row"><span>Atas nama</span><strong data-pay="tf-name">—</strong></div>
          <ol class="pay-steps">
            <li>Transfer <strong>tepat sesuai nominal</strong> di atas (termasuk kode unik 3 digit terakhir).</li>
            <li>Pembayaran dicek otomatis. Setelah diterima, kode tiket muncul di sini.</li>
          </ol>
        </div>
      </div>

      <div class="pay-status"><span class="pay-spinner" aria-hidden="true"></span><span data-pay="status-text">Menunggu pembayaran…</span></div>
      <div class="pay-actions">
        <button type="button" class="btn btn-outline" data-pay="check">Cek Status</button>
        <button type="button" class="btn btn-outline" data-pay="change">Ganti Metode</button>
      </div>
      <button type="button" class="pay-sim" data-pay="simulate" hidden>Simulasikan pembayaran (mode demo)</button>
    </section>

    <!-- 3. Selesai -->
    <section class="pay-view text-center" data-view="done" hidden>
      <div class="pay-check" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
      <div class="eyebrow" style="justify-content:center;">Pembayaran Selesai</div>
      <h3 class="mt-2">Terima kasih!</h3>
      <p class="pay-done-info" data-pay="done-info"></p>
      <div class="pay-tickets" data-pay="tickets"></div>
      <p class="pay-note">Simpan kode tiket di atas. Bawa identitas sesuai nama pemesan saat check-in.</p>
      <button type="button" class="btn btn-primary" data-modal-close style="width:100%;justify-content:center;">Selesai</button>
    </section>

    <!-- 4. Kedaluwarsa -->
    <section class="pay-view text-center" data-view="expired" hidden>
      <div class="eyebrow" style="justify-content:center;">Waktu Habis</div>
      <h3 class="mt-2">Pesanan Kedaluwarsa</h3>
      <p class="mt-2" style="color:var(--silver);">Batas waktu pembayaran sudah lewat dan pesanan dibatalkan otomatis. Silakan buat pesanan baru.</p>
      <button type="button" class="btn btn-primary mt-3" data-modal-close style="width:100%;justify-content:center;">Tutup</button>
    </section>
  </div>
</div>

<div class="pay-toast" data-pay="toast" role="status" aria-live="polite" hidden></div>
<button type="button" class="pay-resume" data-pay="resume" hidden>Lanjutkan pembayaran</button>

<div class="modal-overlay" id="success-modal">
  <div class="modal text-center">
    <button class="modal-close" data-modal-close aria-label="Tutup">×</button>
    <div class="eyebrow" style="justify-content:center;">Berhasil</div>
    <h3 class="mt-2">Terima Kasih</h3>
    <p class="mt-2" data-fill="result-message" style="color:var(--silver);">Cek email kamu untuk konfirmasi lebih lanjut. Sampai jumpa!</p>
    <div class="mt-2 ticket-codes" data-fill="result-extra"></div>
  </div>
</div>

<script src="js/main.js"></script>
<script src="js/payment.js?v=<?= filemtime(__DIR__ . '/js/payment.js') ?>"></script>
</body>
</html>
