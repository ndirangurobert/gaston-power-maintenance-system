<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$stats = [
    'generators' => (int)db()->query('SELECT COUNT(*) FROM generators')->fetchColumn(),
    'active' => (int)db()->query("SELECT COUNT(*) FROM generators WHERE status='active'")->fetchColumn(),
    'scheduled' => (int)db()->query("SELECT COUNT(*) FROM maintenance_jobs WHERE status='scheduled'")->fetchColumn(),
    'overdue' => (int)db()->query("SELECT COUNT(*) FROM maintenance_jobs WHERE status='scheduled' AND scheduled_at < NOW()")->fetchColumn(),
];
$recent = db()->query("SELECT j.id,j.title,j.job_type,j.status,j.scheduled_at,g.asset_code,g.make,g.model FROM maintenance_jobs j JOIN generators g ON g.id=j.generator_id ORDER BY COALESCE(j.scheduled_at,j.created_at) DESC LIMIT 8")->fetchAll();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Dashboard | Gaston Power</title><link rel="stylesheet" href="assets/style.css"></head>
<body><div class="app-shell"><aside class="sidebar"><div class="side-brand"><span class="brand-mark">GP</span><div><strong>Gaston Power</strong><small>Maintenance Suite</small></div></div><nav><a class="active" href="dashboard.php">Overview</a><a href="generators.php">Generators</a><a href="customers.php">Customers</a><a href="maintenance.php">Maintenance</a><a href="parts.php">Spare parts</a><a href="change-password.php">Change password</a></nav><div class="side-bottom"><span><?= e($_SESSION['full_name'] ?? 'Staff') ?></span><small><?= e(ucfirst($_SESSION['role'] ?? '')) ?></small><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout" type="submit">Sign out</button></form></div></aside>
<main class="main-content"><header class="topbar"><div><p class="eyebrow">OPERATIONS / OVERVIEW</p><h1>Good day, <?= e(explode(' ', (string)($_SESSION['full_name'] ?? 'there'))[0]) ?></h1><p class="muted">Here is the current maintenance snapshot.</p></div><span class="role-chip"><?= e(ucfirst($_SESSION['role'] ?? '')) ?></span></header>
<section class="stats-grid"><article class="stat-card"><span>Total generators</span><strong><?= $stats['generators'] ?></strong><small>Registered assets</small></article><article class="stat-card"><span>Active generators</span><strong><?= $stats['active'] ?></strong><small>Operational status</small></article><article class="stat-card"><span>Scheduled jobs</span><strong><?= $stats['scheduled'] ?></strong><small>Upcoming work orders</small></article><article class="stat-card"><span>Overdue jobs</span><strong><?= $stats['overdue'] ?></strong><small>Require attention</small></article></section>
<section class="panel"><div class="panel-heading"><div><h2>Recent maintenance activity</h2><p class="muted">Latest scheduled and recorded work orders</p></div></div>
<?php if (!$recent): ?><div class="empty-state"><div class="empty-icon">↗</div><h3>No maintenance jobs yet</h3><p>Once generator and maintenance modules are added, your recent work orders will appear here.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Work order</th><th>Generator</th><th>Type</th><th>Scheduled</th><th>Status</th></tr></thead><tbody><?php foreach ($recent as $job): ?><tr><td><?= e($job['title']) ?></td><td><?= e($job['asset_code']) ?> · <?= e($job['make']) ?> <?= e($job['model']) ?></td><td><?= e(ucfirst($job['job_type'])) ?></td><td><?= e($job['scheduled_at'] ?? 'Not set') ?></td><td><span class="status"><?= e(str_replace('_',' ',ucfirst($job['status']))) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<footer class="footer-note">Gaston Power Kenya Ltd · Internal maintenance workspace</footer></main></div></body></html>
