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

// Pembayaran yang lewat batas waktu -> expired (dan pesanannya juga)
function expire_stale(PDO $pdo): void
{
    $st = $pdo->prepare("SELECT id, order_id FROM payments WHERE status = 'pending' AND expires_at < ?");
    $st->execute([now_str()]);
    foreach ($st->fetchAll() as $p) {
        $up = $pdo->prepare("UPDATE payments SET status = 'expired' WHERE id = ? AND status = 'pending'");
        $up->execute([$p['id']]);
        if ($up->rowCount() > 0) {
            $pdo->prepare("UPDATE ticket_orders SET status = 'expired' WHERE id = ? AND status = 'pending'")
                ->execute([$p['order_id']]);
            log_payment($pdo, (int)$p['id'], 'expired', 'system', 'Melewati batas waktu pembayaran');
        }
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

        $os = $pdo->prepare('SELECT * FROM ticket_orders WHERE id = ? FOR UPDATE');
        $os->execute([$pay['order_id']]);
        $order = $os->fetch();
        if (!$order || ($order['status'] === 'cancelled' && !$force)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Pesanan tidak valid atau sudah dibatalkan.'];
        }

        $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = ?, reference = ? WHERE id = ?")
            ->execute([now_str(), $reference, $paymentId]);
        $pdo->prepare("UPDATE ticket_orders SET status = 'paid' WHERE id = ?")
            ->execute([$order['id']]);
        issue_tickets($pdo, (int)$order['id'], (int)$order['quantity']);
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

// Ambil pesanan + konser, hanya jika token cocok
function find_order(PDO $pdo, int $orderId, string $token): ?array
{
    $st = $pdo->prepare('SELECT o.*, t.city, t.venue, t.show_date, t.tour_name
                           FROM ticket_orders o JOIN tours t ON t.id = o.tour_id WHERE o.id = ?');
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o || empty($o['public_token']) || !hash_equals($o['public_token'], $token)) {
        return null;
    }
    return $o;
}

function latest_payment(PDO $pdo, int $orderId): ?array
{
    $st = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$orderId]);
    $p = $st->fetch();
    return $p ?: null;
}

// Nomor Virtual Account 16 digit: kode bank + nomor pesanan
function build_va(string $bank, int $orderId): string
{
    $prefix = VA_BANKS[$bank];
    return $prefix . str_pad((string)$orderId, 16 - strlen($prefix), '0', STR_PAD_LEFT);
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
