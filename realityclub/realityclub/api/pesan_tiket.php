<?php
// Membuat pesanan tiket (status: menunggu pembayaran).
// Kode tiket baru diterbitkan SETELAH pembayaran lunas (lihat includes/payment.php -> mark_paid).
define('API_MODE', true);
require_once __DIR__ . '/../includes/payment.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$tourId = (int)($_POST['tour_id'] ?? 0);
$name   = trim($_POST['name'] ?? '');
$email  = trim($_POST['email'] ?? '');
$phone  = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
$qty    = (int)($_POST['qty'] ?? 0);

if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    json_out(['ok' => false, 'message' => 'Nama wajib diisi (maksimal 100 karakter).'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    json_out(['ok' => false, 'message' => 'Email tidak valid.'], 422);
}
if (strlen($phone) < 8 || strlen($phone) > 15) {
    json_out(['ok' => false, 'message' => 'Nomor HP tidak valid.'], 422);
}
if ($qty < 1 || $qty > 4) {
    json_out(['ok' => false, 'message' => 'Jumlah tiket harus 1 sampai 4.'], 422);
}

$pdo = db();
$st = $pdo->prepare('SELECT * FROM tours WHERE id = ?');
$st->execute([$tourId]);
$tour = $st->fetch();
if (!$tour) {
    json_out(['ok' => false, 'message' => 'Konser tidak ditemukan.'], 404);
}
if ($tour['status'] === 'soldout') {
    json_out(['ok' => false, 'message' => 'Maaf, tiket show ini sudah habis. Silakan daftar waitlist.'], 409);
}
if ($tour['ticket_price'] === null || (int)$tour['ticket_price'] <= 0) {
    json_out(['ok' => false, 'message' => 'Harga tiket show ini belum ditetapkan. Silakan coba lagi nanti.'], 409);
}

$price = (int)$tour['ticket_price'];
$total = $price * $qty;
$token = bin2hex(random_bytes(16));

try {
    $pdo->prepare('INSERT INTO ticket_orders (tour_id, buyer_name, email, phone, quantity, total_price, public_token) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tourId, $name, $email, $phone, $qty, $total, $token]);
    $orderId = (int)$pdo->lastInsertId();
} catch (Throwable $ex) {
    error_log('pesan_tiket gagal: ' . $ex->getMessage());
    json_out(['ok' => false, 'message' => 'Pesanan gagal diproses. Silakan coba lagi.'], 500);
}

json_out([
    'ok'      => true,
    'message' => 'Pesanan #' . $orderId . ' dibuat. Silakan pilih metode pembayaran.',
    'order'   => [
        'id'         => $orderId,
        'token'      => $token,
        'quantity'   => $qty,
        'unit_price' => $price,
        'total'      => $total,
        'tour'       => $tour['city'] . ' — ' . $tour['venue'] . ', ' . date('j M Y', strtotime($tour['show_date'])),
        'buyer'      => $name,
    ],
    'config'  => [
        'banks'      => array_keys(VA_BANKS),
        'transfer'   => ['bank' => TRANSFER_ACCOUNT['bank']],
        'simulation' => PAYMENT_SIMULATION,
        'ttl'        => PAYMENT_TTL,
    ],
]);
