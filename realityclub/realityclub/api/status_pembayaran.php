<?php
// Dicek berkala oleh halaman (polling) untuk tahu apakah pembayaran sudah lunas / kedaluwarsa.
// Parameter type: ticket (default) atau shop.
define('API_MODE', true);
require_once __DIR__ . '/../includes/payment.php';

$type    = norm_type($_REQUEST['type'] ?? 'ticket');
$orderId = (int)($_REQUEST['order_id'] ?? 0);
$token   = (string)($_REQUEST['token'] ?? '');

$pdo = db();
expire_stale($pdo);
$order = find_order($pdo, $orderId, $token, $type);
if (!$order) {
    json_out(['ok' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
}

$pay = latest_payment($pdo, $orderId, $type);
$sum = order_summary($pdo, $order, $type);
$res = [
    'ok'           => true,
    'order_status' => $order['status'],   // pending | paid | expired | cancelled
    'server_time'  => time(),
    'payment'      => $pay ? payment_public($pay, $order) : null,
    'order'        => [
        'quantity'   => $sum['quantity'],
        'total'      => (int)$order['total_price'],
        'unit_price' => $sum['quantity'] ? intdiv((int)$order['total_price'], $sum['quantity']) : 0,
        'tour'       => $sum['title'],   // nama lama, dipertahankan agar kompatibel
        'title'      => $sum['title'],
        'line'       => $sum['line'],
    ],
];
if ($order['status'] === 'paid') {
    $res['paid_at'] = $pay && $pay['paid_at'] ? date('j M Y, H:i', strtotime($pay['paid_at'])) . ' WIB' : null;
    $res['title']   = $sum['title'];
    $res['tour']    = $sum['title'];
    if ($type === 'shop') {
        $res['items']     = $sum['items'];
        $res['done_note'] = 'Terima kasih! Pesananmu akan kami proses. Konfirmasi dikirim lewat email / HP yang kamu isi (album digital: tautan unduhan dikirim lewat email).';
        $res['toast']     = '✓ Pembayaran diterima! Pesananmu sedang kami proses.';
    } else {
        $res['tickets']   = ticket_codes($pdo, $orderId);
        $res['done_note'] = 'Simpan kode tiket di atas. Bawa identitas sesuai nama pemesan saat check-in.';
        $res['toast']     = '✓ Pembayaran diterima! Tiketmu sudah terbit.';
    }
}
json_out($res);
