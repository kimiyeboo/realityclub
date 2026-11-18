<?php
// Fungsi bantu pembayaran tiket (dipakai oleh api/*.php dan admin/pembayaran.php)
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/payment_config.php';

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function log_payment(PDO $pdo, int $paymentId, string $event, string $source, ?string $note = null): void
{
    $pdo->prepare('INSERT INTO payment_logs (payment_id, event, source, note) VALUES (?, ?, ?, ?)')
        ->execute([$paymentId, $event, $source, $note]);
}

// Nama tabel pesanan menurut jenisnya
function order_table(string $type): string
{
    return $type === 'shop' ? 'orders' : 'ticket_orders';
}

function norm_type($t): string
{
    return $t === 'shop' ? 'shop' : 'ticket';
}

// Kembalikan stok barang sebuah pesanan toko (hanya produk yang stoknya dibatasi)
function restore_stock(PDO $pdo, int $orderId): void
{
    $pdo->prepare('UPDATE products p JOIN order_items oi ON oi.product_id = p.id
                      SET p.stock = p.stock + oi.quantity
                    WHERE oi.order_id = ? AND p.stock IS NOT NULL')->execute([$orderId]);
}

// Ambil stok lagi (dipakai bila admin melunasi pesanan yang sudah kedaluwarsa). false = stok kurang.
function take_stock(PDO $pdo, int $orderId): bool
{
    $st = $pdo->prepare('SELECT oi.product_id, oi.quantity, p.stock
                           FROM order_items oi JOIN products p ON p.id = oi.product_id
                          WHERE oi.order_id = ?');
    $st->execute([$orderId]);
    $up = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
    foreach ($st->fetchAll() as $it) {
        if ($it['stock'] === null) {
            continue;
        }
        $up->execute([$it['quantity'], $it['product_id'], $it['quantity']]);
        if ($up->rowCount() === 0) {
            return false;
        }
    }
    return true;
}

// Tandai pesanan kedaluwarsa (stok toko dikembalikan sekali saja)
function expire_order(PDO $pdo, string $type, int $orderId): void
{
    $up = $pdo->prepare('UPDATE ' . order_table($type) . " SET status = 'expired' WHERE id = ? AND status = 'pending'");
    $up->execute([$orderId]);
    if ($up->rowCount() > 0 && $type === 'shop') {
        restore_stock($pdo, $orderId);
    }
}

// Pembayaran yang lewat batas waktu -> expired (dan pesanannya juga)
function expire_stale(PDO $pdo): void
{
    $st = $pdo->prepare("SELECT id, order_id, order_type FROM payments WHERE status = 'pending' AND expires_at < ?");
    $st->execute([now_str()]);
    foreach ($st->fetchAll() as $p) {
        $up = $pdo->prepare("UPDATE payments SET status = 'expired' WHERE id = ? AND status = 'pending'");
        $up->execute([$p['id']]);
        if ($up->rowCount() > 0) {
            expire_order($pdo, norm_type($p['order_type']), (int)$p['order_id']);
            log_payment($pdo, (int)$p['id'], 'expired', 'system', 'Melewati batas waktu pembayaran');
        }
    }

    // pesanan toko yang tidak pernah sampai ke pembayaran: lepaskan stoknya
    $hold = (int)ORDER_HOLD_MINUTES;
    $old = $pdo->query("SELECT o.id FROM orders o
                         WHERE o.status = 'pending' AND o.created_at < (NOW() - INTERVAL $hold MINUTE)
                           AND NOT EXISTS (SELECT 1 FROM payments p
                                            WHERE p.order_type = 'shop' AND p.order_id = o.id AND p.status = 'pending')");
    foreach ($old->fetchAll() as $o) {
        expire_order($pdo, 'shop', (int)$o['id']);
    }
}

// Terbitkan kode tiket yang belum ada (jumlah akhir = quantity pesanan)
function issue_tickets(PDO $pdo, int $orderId, int $qty): void
{
    $have = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE order_id = ?');
    $have->execute([$orderId]);
    $missing = $qty - (int)$have->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO tickets (order_id, ticket_code) VALUES (?, ?)');
    for ($k = 0; $k < $missing; $k++) {
        for ($try = 0; $try < 5; $try++) {
            $code = strtoupper(bin2hex(random_bytes(6))); // 12 karakter
            try {
                $ins->execute([$orderId, $code]);
                break;
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000' || $try === 4) {
                    throw $ex;
                }
            }
        }
    }
}

function ticket_codes(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare('SELECT ticket_code FROM tickets WHERE order_id = ? ORDER BY id');
    $st->execute([$orderId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = implode('-', str_split($r['ticket_code'], 4));
    }
    return $out;
}

/**
 * Tandai pembayaran LUNAS: pembayaran -> paid, pesanan -> paid, kode tiket diterbitkan, dicatat di log.
 * $force = true hanya untuk admin (boleh menandai lunas walau pembayaran sudah expired).
 * Aman dipanggil berulang (idempotent).
 */
function mark_paid(PDO $pdo, int $paymentId, string $source, ?string $reference = null, bool $force = false): array
{
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $st->execute([$paymentId]);
        $pay = $st->fetch();
        if (!$pay) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Pembayaran tidak ditemukan.'];
        }
        if ($pay['status'] === 'paid') {
            $pdo->commit();
            return ['ok' => true, 'message' => 'Pembayaran sudah tercatat lunas sebelumnya.'];
        }
        if (!$force && $pay['status'] !== 'pending') {
            log_payment($pdo, $paymentId, 'callback_ignored', $source, 'Status pembayaran: ' . $pay['status']);
            $pdo->commit();
            return ['ok' => false, 'message' => 'Pembayaran tidak lagi menunggu (status: ' . $pay['status'] . ').'];
        }

        $type = norm_type($pay['order_type'] ?? 'ticket');
        $os = $pdo->prepare('SELECT * FROM ' . order_table($type) . ' WHERE id = ? FOR UPDATE');
        $os->execute([$pay['order_id']]);
        $order = $os->fetch();
        if (!$order || ($order['status'] === 'cancelled' && !$force)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Pesanan tidak valid atau sudah dibatalkan.'];
        }

        // pesanan toko yang sudah kedaluwarsa: stok sudah dikembalikan, ambil lagi
        if ($type === 'shop' && $order['status'] === 'expired' && !take_stock($pdo, (int)$order['id'])) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Stok barang sudah habis, pesanan tidak bisa dilunasi.'];
        }

        $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = ?, reference = ? WHERE id = ?")
            ->execute([now_str(), $reference, $paymentId]);
        $pdo->prepare('UPDATE ' . order_table($type) . " SET status = 'paid' WHERE id = ?")
            ->execute([$order['id']]);
        if ($type === 'ticket') {
            issue_tickets($pdo, (int)$order['id'], (int)$order['quantity']);
        }
        log_payment($pdo, $paymentId, 'paid', $source, $reference);
        $pdo->commit();
        return ['ok' => true, 'message' => 'Pembayaran berhasil dicatat.'];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('mark_paid gagal: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'Gagal mencatat pembayaran.'];
    }
}

// Ambil pesanan (tiket: beserta konser), hanya jika token cocok
function find_order(PDO $pdo, int $orderId, string $token, string $type = 'ticket'): ?array
{
    $type = norm_type($type);
    if ($type === 'shop') {
        $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    } else {
        $st = $pdo->prepare('SELECT o.*, t.city, t.venue, t.show_date, t.tour_name
                               FROM ticket_orders o JOIN tours t ON t.id = o.tour_id WHERE o.id = ?');
    }
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o || empty($o['public_token']) || !hash_equals($o['public_token'], $token)) {
        return null;
    }
    $o['order_type'] = $type;
    return $o;
}

function latest_payment(PDO $pdo, int $orderId, string $type = 'ticket'): ?array
{
    $st = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? AND order_type = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$orderId, norm_type($type)]);
    $p = $st->fetch();
    return $p ?: null;
}

// Nomor Virtual Account 16 digit: kode bank + nomor pesanan.
// Pesanan toko memakai angka 9 setelah kode bank supaya tidak bentrok dengan pesanan tiket.
function build_va(string $bank, int $orderId, string $type = 'ticket'): string
{
    $prefix = VA_BANKS[$bank];
    if ($type === 'shop') {
        return $prefix . '9' . str_pad((string)$orderId, 15 - strlen($prefix), '0', STR_PAD_LEFT);
    }
    return $prefix . str_pad((string)$orderId, 16 - strlen($prefix), '0', STR_PAD_LEFT);
}

// Nomor pesanan yang tampil ke pembeli
function order_no(string $type, int $orderId): string
{
    return ($type === 'shop' ? 'RS-' : 'RC-') . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
}

// Ringkasan pesanan untuk ditampilkan di jendela pembayaran
function order_summary(PDO $pdo, array $order, string $type): array
{
    if ($type === 'shop') {
        $st = $pdo->prepare('SELECT p.name, oi.quantity FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id');
        $st->execute([$order['id']]);
        $items = [];
        $qty = 0;
        foreach ($st->fetchAll() as $r) {
            $items[] = $r['name'] . ' × ' . $r['quantity'];
            $qty += (int)$r['quantity'];
        }
        return ['title' => 'Pesanan ' . order_no('shop', (int)$order['id']), 'line' => $qty . ' barang', 'quantity' => $qty, 'items' => $items];
    }
    $qty = (int)$order['quantity'];
    return [
        'title'    => $order['city'] . ' — ' . $order['venue'] . ', ' . date('j M Y', strtotime($order['show_date'])),
        'line'     => $qty . ' tiket × ' . rupiah($qty ? intdiv((int)$order['total_price'], $qty) : 0),
        'quantity' => $qty,
        'items'    => [],
    ];
}

// Pengaturan pembayaran yang boleh dilihat browser
function payment_config_public(): array
{
    return [
        'banks'      => array_keys(VA_BANKS),
        'transfer'   => ['bank' => TRANSFER_ACCOUNT['bank']],
        'simulation' => PAYMENT_SIMULATION,
        'ttl'        => PAYMENT_TTL,
    ];
}

function crc16_ccitt(string $s): string
{
    $crc = 0xFFFF;
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $crc ^= ord($s[$i]) << 8;
        for ($b = 0; $b < 8; $b++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

function tlv(string $id, string $val): string
{
    return $id . str_pad((string)strlen($val), 2, '0', STR_PAD_LEFT) . $val;
}

// Teks QRIS (format EMVCo dinamis + CRC16). NMID di bawah hanya CONTOH:
// untuk QRIS asli, pakai QR dari bank / payment gateway (Midtrans, Xendit, dll).
function build_qris(int $amount, string $billNo): string
{
    $merchant = tlv('00', 'ID.CO.QRIS.WWW') . tlv('02', 'ID1000000000001') . tlv('03', 'UME');
    $body = tlv('00', '01')
          . tlv('01', '12')
          . tlv('51', $merchant)
          . tlv('52', '7999')
          . tlv('53', '360')
          . tlv('54', (string)$amount)
          . tlv('58', 'ID')
          . tlv('59', substr(QRIS_MERCHANT['name'], 0, 25))
          . tlv('60', substr(QRIS_MERCHANT['city'], 0, 15))
          . tlv('62', tlv('01', substr($billNo, 0, 25)))
          . '6304';
    return $body . crc16_ccitt($body);
}

// Data pembayaran untuk dikirim ke browser
function payment_public(array $p, array $order): array
{
    $out = [
        'id'         => (int)$p['id'],
        'method'     => $p['method'],
        'provider'   => $p['provider'],
        'status'     => $p['status'],
        'amount'     => (int)$p['amount'],
        'unique_code' => (int)$p['unique_code'],
        'base_amount' => (int)$order['total_price'],
        'expires_at' => date('c', strtotime($p['expires_at'])),
        'expires_ts' => strtotime($p['expires_at']),
        'pay_code'   => $p['pay_code'],
    ];
    if ($p['method'] === 'va') {
        $out['pay_code_pretty'] = implode(' ', str_split((string)$p['pay_code'], 4));
    }
    if ($p['method'] === 'transfer') {
        $out['account_name'] = TRANSFER_ACCOUNT['name'];
        $out['bank']         = TRANSFER_ACCOUNT['bank'];
    }
    return $out;
}
