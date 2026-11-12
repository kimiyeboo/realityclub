<?php
// =====================================================================
// Pengaturan pembayaran tiket. UBAH nilai di bawah sebelum dipakai sungguhan.
// =====================================================================

// true  = tombol "Simulasikan Pembayaran" muncul (untuk demo / testing di localhost)
// false = WAJIB untuk website yang sudah online
const PAYMENT_SIMULATION = true;

// Kunci rahasia untuk menandatangani notifikasi dari bank / payment gateway (api/payment_callback.php)
const CALLBACK_SECRET = 'ganti-dengan-kunci-rahasia-kamu';

// Password halaman admin (admin/pembayaran.php) untuk konfirmasi transfer manual
const ADMIN_PASSWORD = 'ganti-password-admin';

// Rekening tujuan untuk metode "Transfer Bank" (CONTOH — ganti dengan rekening asli band/EO)
const TRANSFER_ACCOUNT = ['bank' => 'BCA', 'number' => '1234567890', 'name' => 'Reality Club Official'];

// Nama merchant yang tampil di QRIS
const QRIS_MERCHANT = ['name' => 'REALITY CLUB', 'city' => 'JAKARTA'];

// Bank Virtual Account: kode awalan nomor VA (CONTOH — kode asli diberikan bank / payment gateway)
const VA_BANKS = [
    'BCA'     => '39358',
    'BNI'     => '8808',
    'BRI'     => '88810',
    'MANDIRI' => '88608',
];

// Batas waktu bayar (menit) per metode
const PAYMENT_TTL = ['va' => 1440, 'qris' => 30, 'transfer' => 1440];
