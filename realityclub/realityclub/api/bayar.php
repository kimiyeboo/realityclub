<?php
// Membuat pembayaran untuk sebuah pesanan: Virtual Account bank, QRIS, atau transfer manual.
// Jika pelanggan ganti metode, pembayaran lama (yang masih menunggu) otomatis dibatalkan.
define('API_MODE', true);
require_once __DIR__ . '/../includes/payment.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$type     = norm_type($_POST['type'] ?? 'ticket');
$orderId  = (int)($_POST['order_id'] ?? 0);
$token    = (string)($_POST['token'] ?? '');
$method   = (string)($_POST['method'] ?? '');
$provider = strtoupper(trim((string)($_POST['provider'] ?? '')));

if (!in_array($method, ['va', 'qris', 'transfer'], true)) {
    json_out(['ok' => false, 'message' => 'Pilih metode pembayaran.'], 422);
}
if ($method === 'va' && !isset(VA_BANKS[$provider])) {
    json_out(['ok' => false, 'message' => 'Pilih bank untuk Virtual Account.'], 422);
}

$pdo = db();
expire_stale($pdo);
$order = find_order($pdo, $orderId, $token, $type);
if (!$order) {
    json_out(['ok' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
}
if ($order['status'] === 'paid') {
    json_out(['ok' => false, 'message' => 'Pesanan ini sudah dibayar.'], 409);
}
if ($order['status'] !== 'pending') {
    json_out(['ok' => false, 'message' => 'Pesanan sudah kedaluwarsa atau dibatalkan. Silakan buat pesanan baru.'], 409);
}

$base = (int)$order['total_price'];

try {
    $pdo->beginTransaction();

    // batalkan pembayaran lama yang masih menunggu
    $old = $pdo->prepare("SELECT id FROM payments WHERE order_id = ? AND order_type = ? AND status = 'pending'");
    $old->execute([$orderId, $type]);
    foreach ($old->fetchAll() as $o) {
        $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE id = ?")->execute([$o['id']]);
        log_payment($pdo, (int)$o['id'], 'cancelled', 'user', 'Ganti metode pembayaran');
    }

    $unique = 0;
    $amount = $base;
    if ($method === 'transfer') {
        // kode unik 3 digit supaya transfer mudah dikenali
        $chk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE method = 'transfer' AND status = 'pending' AND amount = ?");
        for ($i = 0; $i < 30; $i++) {
            $unique = random_int(101, 999);
            $chk->execute([$base + $unique]);
            if ((int)$chk->fetchColumn() === 0) {
                break;
            }
        }
        $amount = $base + $unique;
    }

    if ($method === 'va') {
        $payCode = build_va($provider, $orderId, $type);
    } elseif ($method === 'qris') {
        $provider = 'QRIS';
        $payCode  = build_qris($amount, ($type === 'shop' ? 'RS' : 'RC') . str_pad((string)$orderId, 8, '0', STR_PAD_LEFT));
    } else {
        $provider = TRANSFER_ACCOUNT['bank'];
        $payCode  = TRANSFER_ACCOUNT['number'];
    }

    $expires = date('Y-m-d H:i:s', time() + PAYMENT_TTL[$method] * 60);
    $pdo->prepare('INSERT INTO payments (order_type, order_id, method, provider, pay_code, unique_code, amount, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$type, $orderId, $method, $provider, $payCode, $unique, $amount, $expires]);
    $paymentId = (int)$pdo->lastInsertId();
    log_payment($pdo, $paymentId, 'created', 'user', $method . ' ' . $provider);
    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('bayar gagal: ' . $ex->getMessage());
    json_out(['ok' => false, 'message' => 'Gagal membuat pembayaran. Silakan coba lagi.'], 500);
}

$pay = latest_payment($pdo, $orderId, $type);
json_out(['ok' => true, 'payment' => payment_public($pay, $order), 'server_time' => time()]);
