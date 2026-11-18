-- =====================================================================
-- UPDATE: pembayaran untuk pesanan SHOP (merch & album)
-- Jalankan SEKALI di database yang SUDAH ter-import
-- (HeidiSQL / phpMyAdmin -> pilih database realityclub -> jalankan).
-- Jika kamu import ulang realityclub_database.sql yang baru, file ini TIDAK perlu.
-- Syarat: update_pembayaran.sql (pembayaran tiket) sudah pernah dijalankan.
-- =====================================================================

-- 1) Pesanan toko: tambah status 'expired' + token publik untuk cek status
ALTER TABLE orders
  MODIFY status ENUM('pending','paid','shipped','cancelled','expired') NOT NULL DEFAULT 'pending' COMMENT 'Status pesanan',
  ADD COLUMN public_token CHAR(32) NULL COMMENT 'Token acak untuk cek status pesanan' AFTER total_price;

-- 2) Pembayaran dipakai bersama oleh tiket dan toko
--    (foreign key ke ticket_orders dilepas karena order_id kini bisa menunjuk ke orders)
ALTER TABLE payments
  DROP FOREIGN KEY fk_payments_order_id,
  ADD COLUMN order_type ENUM('ticket','shop') NOT NULL DEFAULT 'ticket' COMMENT 'Jenis pesanan: ticket (ticket_orders) / shop (orders)' AFTER id,
  ADD KEY idx_payments_order (order_type, order_id);
