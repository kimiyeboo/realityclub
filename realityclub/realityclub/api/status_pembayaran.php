<?php
// Dicek berkala oleh halaman (polling) untuk tahu apakah pembayaran sudah lunas / kedaluwarsa.
define('API_MODE', true);
require_once __DIR__ . '/../includes/payment.php';

$orderId = (int)($_REQUEST['order_id'] ?? 0);
$token   = (string)($_REQUEST['token'] ?? '');

$pdo = db();
expire_stale($pdo);
$order = find_order($pdo, $orderId, $token);
if (!$order) {
    json_out(['ok' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
}

$pay = latest_payment($pdo, $orderId);
$res = [
    'ok'          => true,
    'order_status' => $order['status'],   // pending | paid | expired | cancelled
    'server_time' => time(),
    'payment'     => $pay ? payment_public($pay, $order) : null,
    'order'       => [
        'quantity'   => (int)$order['quantity'],
        'total'      => (int)$order['total_price'],
        'unit_price' => (int)$order['quantity'] ? intdiv((int)$order['total_price'], (int)$order['quantity']) : 0,
        'tour'       => $order['city'] . ' — ' . $order['venue'] . ', ' . date('j M Y', strtotime($order['show_date'])),
    ],
];
if ($order['status'] === 'paid') {
    $res['tickets'] = ticket_codes($pdo, $orderId);
    $res['paid_at'] = $pay && $pay['paid_at'] ? date('j M Y, H:i', strtotime($pay['paid_at'])) . ' WIB' : null;
    $res['tour']    = $order['city'] . ' — ' . $order['venue'] . ', ' . date('j M Y', strtotime($order['show_date']));
}
json_out($res);
