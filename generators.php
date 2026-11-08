<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin']);
    verify_csrf();
    $asset = strtoupper(trim((string)($_POST['asset_code'] ?? '')));
    $make = trim((string)($_POST['make'] ?? ''));
    $model = trim((string)($_POST['model'] ?? ''));
    $serial = trim((string)($_POST['serial_number'] ?? ''));
    $capacity = trim((string)($_POST['capacity_kva'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $status = (string)($_POST['status'] ?? 'active');
    $validCapacity = $capacity === '' || (is_numeric($capacity) && (float)$capacity > 0 && (float)$capacity <= 99999999);
    if ($asset === '' || mb_strlen($asset) > 60 || $make === '' || mb_strlen($make) > 100 || mb_strlen($model) > 100 || mb_strlen($serial) > 120 || mb_strlen($location) > 180 || !$validCapacity || !in_array($status, ['active','under_maintenance','inactive'], true)) {
        $error = 'Please check the generator details and try again.';
    } else {
        $stmt = db()->prepare('INSERT INTO generators (customer_id,asset_code,make,model,serial_number,capacity_kva,location,status) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$customerId > 0 ? $customerId : null, $asset, $make, $model ?: null, $serial ?: null, $capacity === '' ? null : $capacity, $location ?: null, $status]);
        audit('generator_created', 'Generator ' . $asset);
        header('Location: generators.php?saved=1');
        exit;
    }
}
$customers = db()->query('SELECT id,customer_name FROM customers ORDER BY customer_name')->fetchAll();
$generators = db()->query('SELECT g.*,c.customer_name FROM generators g LEFT JOIN customers c ON c.id=g.customer_id ORDER BY g.created_at DESC')->fetchAll();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Generators | Gaston Power</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app-shell"><aside class="sidebar"><div class="side-brand"><span class="brand-mark">GP</span><div><strong>Gaston Power</strong><small>Maintenance Suite</small></div></div><nav><a href="dashboard.php">Overview</a><a class="active" href="generators.php">Generators</a><a href="customers.php">Customers</a><a href="#">Maintenance <small>Coming next</small></a><a href="#">Spare parts <small>Coming next</small></a></nav><div class="side-bottom"><span><?= e($_SESSION['full_name'] ?? 'Staff') ?></span><small><?= e(ucfirst($_SESSION['role'] ?? '')) ?></small><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout">Sign out</button></form></div></aside><main class="main-content"><header class="topbar"><div><p class="eyebrow">ASSET REGISTER / GENERATORS</p><h1>Generator records</h1><p class="muted">Track generator identity, capacity, customer and operational status.</p></div><span class="role-chip"><?= count($generators) ?> generators</span></header>
<?php if (isset($_GET['saved'])): ?><p class="success">Generator saved successfully.</p><?php endif; ?><?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (($_SESSION['role'] ?? '') === 'admin'): ?><section class="panel form-panel"><h2>Register a generator</h2><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Asset code<input name="asset_code" maxlength="60" placeholder="e.g. GP-GEN-001" required></label><label>Manufacturer / make<input name="make" maxlength="100" placeholder="e.g. Perkins" required></label><label>Model<input name="model" maxlength="100"></label><label>Serial number<input name="serial_number" maxlength="120"></label><label>Capacity (kVA)<input type="number" name="capacity_kva" min="0.01" step="0.01"></label><label>Customer<select name="customer_id"><option value="0">Unassigned</option><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['customer_name']) ?></option><?php endforeach; ?></select></label><label>Installation location<input name="location" maxlength="180"></label><label>Status<select name="status"><option value="active">Active</option><option value="under_maintenance">Under maintenance</option><option value="inactive">Inactive</option></select></label><div class="wide"><button class="button" type="submit">Save generator</button></div></form></section><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2>Registered generators</h2><p class="muted">All tracked generator assets</p></div></div><?php if (!$generators): ?><div class="empty-state"><h3>No generators recorded</h3><p>Register a generator to begin tracking its service history.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Asset code</th><th>Generator</th><th>Capacity</th><th>Customer</th><th>Location</th><th>Status</th></tr></thead><tbody><?php foreach ($generators as $g): ?><tr><td><?= e($g['asset_code']) ?></td><td><?= e($g['make']) ?> <?= e($g['model'] ?? '') ?><br><small class="muted"><?= e($g['serial_number'] ?? '') ?></small></td><td><?= e($g['capacity_kva'] === null ? '—' : $g['capacity_kva'] . ' kVA') ?></td><td><?= e($g['customer_name'] ?? 'Unassigned') ?></td><td><?= e($g['location'] ?? '—') ?></td><td><span class="status"><?= e(str_replace('_',' ',ucfirst($g['status']))) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><footer class="footer-note">Gaston Power Kenya Ltd · Generator asset register</footer></main></div></body></html>
