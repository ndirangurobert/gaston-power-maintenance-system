<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin']);
    verify_csrf();
    $name = trim((string)($_POST['customer_name'] ?? ''));
    $contact = trim((string)($_POST['contact_person'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $emailRaw = trim((string)($_POST['email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $email = $emailRaw === '' ? null : filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
    if ($name === '' || mb_strlen($name) > 160 || mb_strlen($contact) > 120 || mb_strlen($phone) > 40 || ($emailRaw !== '' && !$email) || mb_strlen($address) > 5000) {
        $error = 'Please check the customer details and try again.';
    } else {
        $stmt = db()->prepare('INSERT INTO customers (customer_name,contact_person,phone,email,address) VALUES (?,?,?,?,?)');
        $stmt->execute([$name, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null]);
        audit('customer_created', 'Customer record ID ' . db()->lastInsertId());
        header('Location: customers.php?saved=1');
        exit;
    }
}
$customers = db()->query('SELECT c.*, (SELECT COUNT(*) FROM generators g WHERE g.customer_id=c.id) AS generator_count FROM customers c ORDER BY c.customer_name')->fetchAll();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Customers | Gaston Power</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app-shell"><aside class="sidebar"><div class="side-brand"><span class="brand-mark">GP</span><div><strong>Gaston Power</strong><small>Maintenance Suite</small></div></div><nav><a href="dashboard.php">Overview</a><a href="generators.php">Generators</a><a class="active" href="customers.php">Customers</a><a href="customer-help.php">Customer help</a><a href="#">Maintenance <small>Coming next</small></a><a href="#">Spare parts <small>Coming next</small></a></nav><div class="side-bottom"><span><?= e($_SESSION['full_name'] ?? 'Staff') ?></span><small><?= e(ucfirst($_SESSION['role'] ?? '')) ?></small><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout">Sign out</button></form></div></aside><main class="main-content"><header class="topbar"><div><p class="eyebrow">OPERATIONS / CUSTOMERS</p><h1>Customer records</h1><p class="muted">Manage customers and the generators assigned to them.</p></div><span class="role-chip"><?= count($customers) ?> customers</span></header>
<?php if (isset($_GET['saved'])): ?><p class="success">Customer saved successfully.</p><?php endif; ?><?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (($_SESSION['role'] ?? '') === 'admin'): ?><section class="panel form-panel"><h2>Add a customer</h2><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Customer / company name<input name="customer_name" maxlength="160" required></label><label>Contact person<input name="contact_person" maxlength="120"></label><label>Phone number<input name="phone" maxlength="40"></label><label>Email<input type="email" name="email" maxlength="190"></label><label class="wide">Address / location<textarea name="address" rows="2" maxlength="5000"></textarea></label><div class="wide"><button class="button" type="submit">Save customer</button></div></form></section><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2>All customers</h2><p class="muted">Customer directory</p></div></div><?php if (!$customers): ?><div class="empty-state"><h3>No customers recorded</h3><p>Add a customer using the form above.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Customer</th><th>Contact</th><th>Phone</th><th>Email</th><th>Generators</th></tr></thead><tbody><?php foreach ($customers as $c): ?><tr><td><?= e($c['customer_name']) ?></td><td><?= e($c['contact_person'] ?? '—') ?></td><td><?= e($c['phone'] ?? '—') ?></td><td><?= e($c['email'] ?? '—') ?></td><td><?= (int)$c['generator_count'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><footer class="footer-note">Gaston Power Kenya Ltd · Customer management</footer></main></div></body></html>
