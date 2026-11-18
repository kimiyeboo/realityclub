<?php
// MODE DEMO: menyimulasikan notifikasi "pembayaran masuk" tanpa uang sungguhan.
// Hanya aktif jika PAYMENT_SIMULATION = true di includes/payment_config.php.
define('API_MODE', true);
require_once __DIR__ . '/../includes/payment.php';

if (!PAYMENT_SIMULATION) {
    json_out(['ok' => false, 'message' => 'Simulasi pembayaran dinonaktifkan.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$type    = norm_type($_POST['type'] ?? 'ticket');
$orderId = (int)($_POST['order_id'] ?? 0);
$token   = (string)($_POST['token'] ?? '');

$pdo = db();
expire_stale($pdo);
$order = find_order($pdo, $orderId, $token, $type);
if (!$order) {
    json_out(['ok' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
}
$pay = latest_payment($pdo, $orderId, $type);
if (!$pay) {
    json_out(['ok' => false, 'message' => 'Belum ada pembayaran untuk pesanan ini.'], 409);
}

$r = mark_paid($pdo, (int)$pay['id'], 'simulasi', 'SIM-' . strtoupper(bin2hex(random_bytes(4))));
json_out($r, $r['ok'] ? 200 : 409);
