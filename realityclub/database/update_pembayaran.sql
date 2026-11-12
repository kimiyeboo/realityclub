-- =====================================================================
-- UPDATE: sistem pembayaran tiket (VA bank, QRIS, transfer manual)
-- Jalankan di phpMyAdmin -> pilih database realityclub -> tab SQL -> Go
-- Jalankan SEKALI saja pada database yang sudah ter-import.
-- (Jika import ulang realityclub_database.sql yang baru, file ini tidak perlu.)
-- =====================================================================

-- 1) Status pesanan: tambah 'expired'; token publik untuk cek status pesanan
ALTER TABLE ticket_orders
  MODIFY status ENUM('pending','paid','cancelled','expired') NOT NULL DEFAULT 'pending' COMMENT 'Status pembayaran',
  ADD COLUMN public_token CHAR(32) NULL COMMENT 'Token acak untuk cek status pesanan' AFTER total_price;

-- 2) Harga tiket (CONTOH — ubah sesuai harga asli di phpMyAdmin, tabel tours kolom ticket_price)
UPDATE tours SET ticket_price = 350000 WHERE city = 'Jakarta'      AND ticket_price IS NULL;
UPDATE tours SET ticket_price = 300000 WHERE city = 'Bandung'      AND ticket_price IS NULL;
UPDATE tours SET ticket_price = 300000 WHERE city = 'Surabaya'     AND ticket_price IS NULL;
UPDATE tours SET ticket_price = 450000 WHERE city = 'Singapore'    AND ticket_price IS NULL;
UPDATE tours SET ticket_price = 400000 WHERE city = 'Kuala Lumpur' AND ticket_price IS NULL;

-- 3) Pembayaran tiket
CREATE TABLE payments (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT COMMENT 'ID pembayaran',
  order_id INT UNSIGNED NOT NULL COMMENT 'Pesanan tiket yang dibayar',
  method ENUM('va','qris','transfer') NOT NULL COMMENT 'Metode: Virtual Account / QRIS / Transfer manual',
  provider VARCHAR(20) NULL COMMENT 'Bank (BCA, BNI, BRI, MANDIRI) atau QRIS',
  pay_code VARCHAR(64) NULL COMMENT 'Nomor VA / nomor rekening tujuan / payload QRIS',
  unique_code SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Kode unik (transfer manual), ditambahkan ke total',
  amount INT UNSIGNED NOT NULL COMMENT 'Nominal yang harus dibayar (Rp)',
  status ENUM('pending','paid','expired','cancelled') NOT NULL DEFAULT 'pending' COMMENT 'Status pembayaran',
  expires_at DATETIME NOT NULL COMMENT 'Batas waktu pembayaran',
  paid_at DATETIME NULL COMMENT 'Waktu pembayaran diterima',
  reference VARCHAR(100) NULL COMMENT 'Referensi dari bank / payment gateway',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu dibuat',
  CONSTRAINT fk_payments_order_id FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
  KEY idx_payments_status (status, expires_at)
) ENGINE=InnoDB COMMENT='Pembayaran pesanan tiket';

-- 4) Riwayat kejadian pembayaran (dibuat, notifikasi masuk, lunas, kedaluwarsa)
CREATE TABLE payment_logs (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT COMMENT 'ID log',
  payment_id INT UNSIGNED NOT NULL COMMENT 'Pembayaran terkait',
  event VARCHAR(40) NOT NULL COMMENT 'created, paid, expired, cancelled, callback_ignored ...',
  source VARCHAR(30) NOT NULL COMMENT 'user, callback, admin, simulasi, system',
  note VARCHAR(255) NULL COMMENT 'Keterangan',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu kejadian',
  CONSTRAINT fk_payment_logs_payment_id FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Riwayat kejadian pembayaran';
