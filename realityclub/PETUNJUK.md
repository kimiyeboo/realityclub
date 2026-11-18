# Reality Club — Website terhubung ke Database (PHP + MySQL)

## Cara menjalankan (XAMPP)
1. Buka XAMPP Control Panel, **Start** Apache dan MySQL.
2. Buka http://localhost/phpmyadmin → tab **Import** → pilih `database/realityclub_database.sql` → **Go**.
3. Salin folder **realityclub** (yang berisi index.php, band.php, dst.) ke `C:\xampp\htdocs\`.
4. Buka http://localhost/realityclub/index.php

Jika password MySQL kamu bukan kosong, ubah `includes/koneksi.php`.

## File yang ditambah / diubah
- `includes/koneksi.php`  : koneksi database (PDO), tempat mengubah user/password.
- `includes/helpers.php`  : fungsi bantu (format rupiah, kartu konser, dll).
- `includes/footer.php`   : footer bersama, data dari tabel streaming_links, social_links, contact_info.
- `api/pesan_tiket.php`   : form Beli Tiket -> ticket_orders + tickets (kode unik per lembar).
- `api/waitlist.php`      : form Waitlist -> waitlist.
- `api/kirim_pesan.php`   : form Contact -> contact_messages.
- `api/checkout.php`      : keranjang Shop -> orders + order_items (harga & stok dicek dari database).
- Semua `*.html` diubah menjadi `*.php`; `js/main.js` dan `css/pages.css` diperbarui.

## Halaman -> tabel
- index.php   : albums, tours, streaming_links
- band.php    : band, members, member_social_links, timeline_events
- music.php   : albums, tracks, album_behind_the_scenes, music_videos, streaming_links, products
- tour.php    : tours, faqs, ticket_orders, tickets, waitlist
- shop.php    : products, orders, order_items
- contact.php : contact_info, contact_messages


## Pembayaran tiket (baru)
Alur: form Beli Tiket -> pilih metode (Virtual Account BCA/BNI/BRI/Mandiri, QRIS, Transfer Bank) -> menunggu pembayaran
(hitung mundur + cek status otomatis tiap 4 detik) -> "Pembayaran Selesai" + kode tiket terbit. Semua tercatat di database.

Database lama: jalankan `database/update_pembayaran.sql` SEKALI di phpMyAdmin. Database baru: cukup import `realityclub_database.sql`.
Tabel: `payments` (pembayaran), `payment_logs` (riwayat), `ticket_orders` (+ kolom public_token, status 'expired'). Harga di `tours.ticket_price` (isi contoh, ubah sesuai harga asli).

File: `includes/payment_config.php` (pengaturan), `includes/payment.php` (fungsi), `api/bayar.php`, `api/status_pembayaran.php`,
`api/simulasi_bayar.php` (demo), `api/payment_callback.php` (webhook bank/gateway), `admin/pembayaran.php` (konfirmasi transfer manual),
`js/payment.js`, `css/payment.css`.

Menandai LUNAS: (1) otomatis lewat webhook api/payment_callback.php dari bank/payment gateway, (2) manual lewat admin/pembayaran.php,
(3) tombol "Simulasikan pembayaran" untuk demo (matikan dengan PAYMENT_SIMULATION=false sebelum website online).
Ganti CALLBACK_SECRET, ADMIN_PASSWORD, rekening TRANSFER_ACCOUNT, dan kode VA_BANKS di payment_config.php.

## Pembayaran Shop (baru)
Alur: pilih barang -> klik ikon keranjang -> isi data pembeli -> "Lanjut ke Pembayaran" -> pilih QRIS / Virtual Account /
Transfer Bank -> menunggu pembayaran -> pesanan lunas. Memakai jendela dan sistem pembayaran yang sama dengan tiket konser.

Database lama: jalankan `database/update_pembayaran_shop.sql` SEKALI (setelah update_pembayaran.sql). Database baru: cukup import `realityclub_database.sql`.
- `orders`: + kolom `public_token`, status `expired`.
- `payments`: + kolom `order_type` ('ticket' / 'shop'); satu tabel pembayaran untuk keduanya.
- Stok produk dikurangi saat pesanan dibuat dan DIKEMBALIKAN otomatis jika pesanan kedaluwarsa (`ORDER_HOLD_MINUTES` di payment_config.php).
- `includes/payment_modal.php` : jendela pembayaran bersama (tour.php dan shop.php).
- `admin/pembayaran.php` kini menampilkan pembayaran tiket dan toko.

PENTING: pembayaran masih MODE DEMO (PAYMENT_SIMULATION = true). QR dan nomor VA belum bisa menerima uang sungguhan.
Untuk uang asli, sambungkan ke payment gateway (Midtrans / Xendit / dll) lewat `api/payment_callback.php`, lalu set PAYMENT_SIMULATION = false.
