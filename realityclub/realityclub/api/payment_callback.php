<?php
// Penerima NOTIFIKASI pembayaran dari bank / payment gateway (webhook).
// Cara kerja: pihak bank/gateway mengirim POST ke URL ini ketika uang sudah masuk.
//
// Field (JSON atau form):
//   payment_id (opsional), pay_code, amount, status ("paid"), reference
//   signature = HMAC-SHA256( payment_id|pay_code|amount|status , CALLBACK_SECRET )  -> hex
//
// Contoh pembuatan signature (PHP):
//   hash_hmac('sha256', $paymentId.'|'.$payCode.'|'.$amount.'|paid', CALLBACK_SECRET)
//
// Midtrans/Xendit memakai format & signature sendiri; file ini perlu disesuaikan
// dengan dokumentasi gateway yang dipilih (bagian pencocokan pembayaran + mark_paid() sama).
define('API_MODE', true);
require_once __DIR__ . '/../includes/payment.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    $data = $_POST;
}

$paymentId = (int)($data['payment_id'] ?? 0);
$payCode   = (string)($data['pay_code'] ?? '');
$amount    = (int)($data['amount'] ?? 0);
$status    = strtolower((string)($data['status'] ?? ''));
$reference = substr((string)($data['reference'] ?? ''), 0, 100);
$signature = (string)($data['signature'] ?? '');

$expected = hash_hmac('sha256', ($paymentId ?: '') . '|' . $payCode . '|' . $amount . '|' . $status, CALLBACK_SECRET);
if (!hash_equals($expected, $signature)) {
    json_out(['ok' => false, 'message' => 'Signature tidak valid.'], 401);
}
if ($status !== 'paid') {
    json_out(['ok' => true, 'message' => 'Status diabaikan.']);
}

$pdo = db();
if ($paymentId > 0) {
    $st = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$paymentId]);
} else {
    $st = $pdo->prepare("SELECT * FROM payments WHERE pay_code = ? AND amount = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $st->execute([$payCode, $amount]);
}
$pay = $st->fetch();
if (!$pay) {
    json_out(['ok' => false, 'message' => 'Pembayaran tidak ditemukan.'], 404);
}
if ((int)$pay['amount'] !== $amount) {
    log_payment($pdo, (int)$pay['id'], 'callback_ignored', 'callback', 'Nominal tidak cocok: ' . $amount);
    json_out(['ok' => false, 'message' => 'Nominal tidak cocok.'], 422);
}

$r = mark_paid($pdo, (int)$pay['id'], 'callback', $reference ?: null);
json_out($r, $r['ok'] ? 200 : 409);
