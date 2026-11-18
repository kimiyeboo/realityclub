<?php
// Jendela pembayaran bersama (QRIS, Virtual Account, Transfer Bank). Dipakai di tour.php dan shop.php.
// Set $payType = 'ticket' atau 'shop' sebelum include.
$payType = ($payType ?? 'ticket') === 'shop' ? 'shop' : 'ticket';
?>
<div class="modal-overlay" id="payment-modal" data-type="<?= e($payType) ?>">
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
            <li>Pembayaran dicek otomatis. <?= $payType === 'shop' ? 'Setelah diterima, status pesananmu menjadi lunas.' : 'Setelah diterima, kode tiket muncul di sini.' ?></li>
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
      <p class="pay-note" data-pay="done-note"><?= $payType === 'shop' ? 'Terima kasih! Pesananmu akan kami proses.' : 'Simpan kode tiket di atas. Bawa identitas sesuai nama pemesan saat check-in.' ?></p>
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
