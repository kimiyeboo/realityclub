<?php
// Halaman admin sederhana: lihat semua pembayaran tiket + konfirmasi transfer manual.
// Buka: http://localhost/realityclub/realityclub/admin/pembayaran.php
require_once __DIR__ . '/../includes/payment.php';
session_start();

$pdo = db();
$err = '';

if (isset($_GET['keluar'])) {
    session_destroy();
    header('Location: pembayaran.php');
    exit;
}

if (isset($_POST['password'])) {
    if (hash_equals(ADMIN_PASSWORD, (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf']  = bin2hex(random_bytes(16));
        header('Location: pembayaran.php');
        exit;
    }
    $err = 'Password salah.';
}

$logged = !empty($_SESSION['admin']);
$flash  = '';

if ($logged && isset($_POST['lunas'])) {
    if (hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        $r = mark_paid($pdo, (int)$_POST['lunas'], 'admin', 'Dikonfirmasi admin', true);
        $flash = $r['message'];
    } else {
        $flash = 'Sesi tidak valid, muat ulang halaman.';
    }
}

if ($logged) {
    expire_stale($pdo);
    $rows = $pdo->query("SELECT p.*,
                                COALESCE(o.buyer_name, so.customer_name) AS buyer_name,
                                COALESCE(o.email, so.email) AS email,
                                COALESCE(o.phone, so.phone) AS phone,
                                o.quantity, t.city, t.venue, t.show_date,
                                so.shipping_address,
                                (SELECT SUM(quantity) FROM order_items WHERE order_id = so.id) AS shop_qty
                           FROM payments p
                           LEFT JOIN ticket_orders o ON p.order_type = 'ticket' AND o.id = p.order_id
                           LEFT JOIN tours t ON t.id = o.tour_id
                           LEFT JOIN orders so ON p.order_type = 'shop' AND so.id = p.order_id
                          ORDER BY p.id DESC LIMIT 100")->fetchAll();
}
$labels = ['va' => 'Virtual Account', 'qris' => 'QRIS', 'transfer' => 'Transfer'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Pembayaran — Reality Club</title>
<style>
  body{margin:0;background:#050A1A;color:#E8ECF8;font:15px/1.5 system-ui,Segoe UI,sans-serif;padding:24px;}
  h1{font-size:1.3rem;margin:0 0 4px;} .sub{color:#9AA6C4;margin:0 0 20px;font-size:.9rem;}
  table{width:100%;border-collapse:collapse;background:#0F1A3C;border-radius:10px;overflow:hidden;}
  th,td{padding:10px 12px;text-align:left;border-bottom:1px solid rgba(154,166,196,.14);font-size:.88rem;vertical-align:top;}
  th{background:#141F45;color:#9AA6C4;font-weight:600;}
  .wrap{overflow-x:auto;}
  .st{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.78rem;border:1px solid #5C6690;}
  .st.paid{color:#8FF6E4;border-color:#8FF6E4;} .st.pending{color:#ffd37a;border-color:#ffd37a;}
  .st.expired,.st.cancelled{color:#8892b5;}
  button{background:#2B4FD9;color:#fff;border:0;border-radius:8px;padding:7px 14px;cursor:pointer;font-size:.82rem;}
  button:hover{background:#3b5fe8;}
  input[type=password]{padding:10px 12px;border-radius:8px;border:1px solid #5C6690;background:#0F1A3C;color:#fff;}
  .box{max-width:340px;margin:12vh auto;} .err{color:#ff9a9a;margin:8px 0;} .ok{background:#0f3a35;color:#8FF6E4;padding:10px 14px;border-radius:8px;margin-bottom:16px;}
  a{color:#8FF6E4;}
</style>
</head>
<body>
<?php if (!$logged): ?>
  <form method="post" class="box">
    <h1>Admin Pembayaran</h1>
    <p class="sub">Masukkan password admin (diatur di includes/payment_config.php).</p>
    <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
    <input type="password" name="password" placeholder="Password" autofocus required>
    <button type="submit">Masuk</button>
  </form>
<?php else: ?>
  <h1>Pembayaran Tiket &amp; Toko</h1>
  <p class="sub">100 pembayaran terbaru · <a href="pembayaran.php">Muat ulang</a> · <a href="?keluar=1">Keluar</a></p>
  <?php if ($flash): ?><div class="ok"><?= e($flash) ?></div><?php endif; ?>
  <div class="wrap">
  <table>
    <tr><th>#</th><th>Pembeli</th><th>Pesanan</th><th>Metode</th><th>Nominal</th><th>Status</th><th>Batas bayar</th><th>Dibayar</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?><br><small><?= $r['order_type'] === 'shop' ? 'Toko ' : 'Tiket ' ?>#<?= (int)$r['order_id'] ?></small></td>
      <td><?= e($r['buyer_name']) ?><br><small><?= e($r['email']) ?> · <?= e($r['phone']) ?></small></td>
      <?php if ($r['order_type'] === 'shop'): ?>
      <td>Toko (merch / album)<br><small><?= (int)$r['shop_qty'] ?> barang<?= $r['shipping_address'] ? ' · ' . e($r['shipping_address']) : ' · digital' ?></small></td>
      <?php else: ?>
      <td><?= e($r['city']) ?> — <?= e($r['venue']) ?><br><small><?= e(date('j M Y', strtotime($r['show_date']))) ?> · <?= (int)$r['quantity'] ?> tiket</small></td>
      <?php endif; ?>
      <td><?= e($labels[$r['method']] ?? $r['method']) ?><br><small><?= e($r['provider']) ?></small></td>
      <td><?= e(rupiah((int)$r['amount'])) ?></td>
      <td><span class="st <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
      <td><?= e(date('j M, H:i', strtotime($r['expires_at']))) ?></td>
      <td><?= $r['paid_at'] ? e(date('j M, H:i', strtotime($r['paid_at']))) : '—' ?><?= $r['reference'] ? '<br><small>' . e($r['reference']) . '</small>' : '' ?></td>
      <td>
        <?php if ($r['status'] !== 'paid' && $r['status'] !== 'cancelled'): ?>
        <form method="post" onsubmit="return confirm('Tandai pembayaran #<?= (int)$r['id'] ?> sebagai LUNAS?');">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <button type="submit" name="lunas" value="<?= (int)$r['id'] ?>">Tandai Lunas</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" style="color:#9AA6C4;">Belum ada pembayaran.</td></tr><?php endif; ?>
  </table>
  </div>
<?php endif; ?>
</body>
</html>
