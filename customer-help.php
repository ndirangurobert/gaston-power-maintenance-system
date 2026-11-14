<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_role(['admin','technician']);
$role = (string)($_SESSION['role'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        require_role(['admin']);
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $subject = trim((string)($_POST['subject'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);
        if ($customerId < 1 || $subject === '' || mb_strlen($subject) > 180 || $message === '' || mb_strlen($message) > 10000) {
            $error = 'Choose a customer and enter a subject and message.';
        } else {
            $customerCheck = db()->prepare('SELECT id FROM customers WHERE id=?');
            $customerCheck->execute([$customerId]);
            if (!$customerCheck->fetch()) {
                $error = 'The selected customer could not be found.';
            } else {
                $techId = null;
                if ($assignedTo > 0) {
                    $techCheck = db()->prepare("SELECT id FROM users WHERE id=? AND role='technician' AND is_active=1");
                    $techCheck->execute([$assignedTo]);
                    $techId = $techCheck->fetchColumn();
                    if (!$techId) $error = 'The selected technician is not available.';
                }
                if ($error === '') {
                    $stmt = db()->prepare('INSERT INTO customer_help_requests (customer_id,subject,message,assigned_to,created_by) VALUES (?,?,?,?,?)');
                    $stmt->execute([$customerId,$subject,$message,$techId ?: null,(int)$_SESSION['user_id']]);
                    audit('customer_help_created', 'Help request ' . db()->lastInsertId());
                    header('Location: customer-help.php?created=1');
                    exit;
                }
            }
        }
    } elseif ($action === 'update') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $response = trim((string)($_POST['staff_response'] ?? ''));
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);
        if (!in_array($status,['pending','in_progress','resolved'],true) || mb_strlen($response) > 10000) {
            $error = 'Check the status and response, then try again.';
        } else {
            $find = db()->prepare('SELECT id,assigned_to FROM customer_help_requests WHERE id=?');
            $find->execute([$requestId]);
            $request = $find->fetch();
            if (!$request) {
                $error = 'The help request could not be found.';
            } elseif ($role === 'technician' && (int)$request['assigned_to'] !== (int)$_SESSION['user_id']) {
                http_response_code(403);
                exit('You can only update help requests assigned to you.');
            } else {
                $techId = $request['assigned_to'];
                if ($role === 'admin') {
                    $techId = null;
                    if ($assignedTo > 0) {
                        $techCheck = db()->prepare("SELECT id FROM users WHERE id=? AND role='technician' AND is_active=1");
                        $techCheck->execute([$assignedTo]);
                        $techId = $techCheck->fetchColumn();
                        if (!$techId) $error = 'The selected technician is not available.';
                    }
                }
                if ($error === '') {
                    $stmt = db()->prepare('UPDATE customer_help_requests SET status=?, staff_response=?, assigned_to=? WHERE id=?');
                    $stmt->execute([$status,$response ?: null,$techId,$requestId]);
                    audit('customer_help_updated', 'Help request ' . $requestId . ' updated to ' . $status);
                    header('Location: customer-help.php?updated=1');
                    exit;
                }
            }
        }
    }
}

$customers = db()->query('SELECT id,customer_name,phone,email FROM customers ORDER BY customer_name')->fetchAll();
$technicians = db()->query("SELECT id,full_name FROM users WHERE role='technician' AND is_active=1 ORDER BY full_name")->fetchAll();
$sql = 'SELECT h.*,c.customer_name,c.phone,c.email,u.full_name AS technician FROM customer_help_requests h JOIN customers c ON c.id=h.customer_id LEFT JOIN users u ON u.id=h.assigned_to';
if ($role === 'technician') {
    $stmt = db()->prepare($sql . ' WHERE h.assigned_to=? ORDER BY h.updated_at DESC');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $requests = $stmt->fetchAll();
} else {
    $requests = db()->query($sql . ' ORDER BY h.updated_at DESC')->fetchAll();
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Customer help | Gaston Power</title><link rel="stylesheet" href="assets/style.css"></head>
<body><div class="app-shell">
<aside class="sidebar"><div class="side-brand"><span class="brand-mark">GP</span><div><strong>Gaston Power</strong><small>Maintenance Suite</small></div></div>
<nav><a href="dashboard.php">Overview</a><a href="generators.php">Generators</a><a href="customers.php">Customers</a><a href="customer-help.php" class="active">Customer help</a><a href="maintenance.php">Maintenance</a><a href="parts.php">Spare parts</a><a href="change-password.php">Change password</a></nav>
<div class="side-bottom"><span><?= e($_SESSION['full_name'] ?? 'Staff') ?></span><small><?= e(ucfirst($role)) ?></small><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout" type="submit">Sign out</button></form></div></aside>
<main class="main-content"><header class="topbar"><div><p class="eyebrow">CUSTOMER SERVICE / SUPPORT</p><h1>Customer help requests</h1><p class="muted"><?= $role === 'admin' ? 'Record customer concerns, assign technicians and monitor resolutions.' : 'Review and update customer requests assigned to you.' ?></p></div><span class="role-chip"><?= count($requests) ?> requests</span></header>
<?php if(isset($_GET['created'])):?><p class="success">Customer help request created and saved.</p><?php endif;?>
<?php if(isset($_GET['updated'])):?><p class="success">Customer help request updated.</p><?php endif;?>
<?php if($error):?><p class="alert"><?=e($error)?></p><?php endif;?>
<?php if($role==='admin'):?>
<section class="panel form-panel"><h2>Record a customer request</h2>
<?php if(!$customers):?><p class="muted">Add a customer record first, then you can log their request here. <a href="customers.php">Open customers</a>.</p>
<?php else:?><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create">
<label>Customer<select name="customer_id" required><option value="">Select customer</option><?php foreach($customers as $c):?><option value="<?=(int)$c['id']?>"><?=e($c['customer_name'])?><?= $c['phone'] ? ' · '.e($c['phone']) : '' ?></option><?php endforeach;?></select></label>
<label>Assign technician<select name="assigned_to"><option value="0">Not assigned</option><?php foreach($technicians as $t):?><option value="<?=(int)$t['id']?>"><?=e($t['full_name'])?></option><?php endforeach;?></select></label>
<label class="wide">Subject<input name="subject" maxlength="180" required placeholder="e.g. Generator not starting"></label>
<label class="wide">Customer message / issue<textarea name="message" rows="3" maxlength="10000" required placeholder="Record the customer's concern and relevant details"></textarea></label>
<div class="wide"><button class="button" type="submit">Save customer request</button></div></form><?php endif;?></section>
<?php endif;?>
<section class="panel"><div class="panel-heading"><div><h2><?= $role==='admin' ? 'All customer requests' : 'Requests assigned to me' ?></h2><p class="muted">Update progress and record the response given to the customer.</p></div></div>
<?php if(!$requests):?><div class="empty-state"><h3>No customer help requests</h3><p><?= $role==='admin' ? 'New customer requests will appear here after they are recorded.' : 'Requests assigned to your account will appear here.' ?></p></div>
<?php else:?><div class="table-wrap"><table><thead><tr><th>Customer / contact</th><th>Request</th><th>Assigned technician</th><th>Status</th><th>Update</th></tr></thead><tbody>
<?php foreach($requests as $r):?><tr><td><strong><?=e($r['customer_name'])?></strong><br><small class="muted"><?=e($r['phone']??'No phone')?><?= $r['email'] ? ' · '.e($r['email']) : '' ?></small></td>
<td><strong><?=e($r['subject'])?></strong><br><small class="muted"><?=nl2br(e($r['message']))?></small><?php if($r['staff_response']):?><br><small><strong>Staff response:</strong> <?=nl2br(e($r['staff_response']))?></small><?php endif;?></td>
<td><?=e($r['technician']??'Not assigned')?></td><td><span class="status"><?=e(ucfirst(str_replace('_',' ',$r['status'])))?></span></td>
<td><details><summary>Edit / respond</summary><form method="post" class="mini-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="request_id" value="<?=(int)$r['id']?>">
<label>Status<select name="status"><?php foreach(['pending','in_progress','resolved'] as $st):?><option value="<?=$st?>" <?=$r['status']===$st?'selected':''?>><?=e(ucfirst(str_replace('_',' ',$st)))?></option><?php endforeach;?></select></label>
<?php if($role==='admin'):?><label>Assign technician<select name="assigned_to"><option value="0">Not assigned</option><?php foreach($technicians as $t):?><option value="<?=(int)$t['id']?>" <?=(int)$r['assigned_to']===(int)$t['id']?'selected':''?>><?=e($t['full_name'])?></option><?php endforeach;?></select></label><?php else:?><input type="hidden" name="assigned_to" value="<?=(int)$r['assigned_to']?>"><?php endif;?>
<label>Response / work notes<textarea name="staff_response" rows="3" maxlength="10000"><?=e($r['staff_response']??'')?></textarea></label><button class="button" type="submit">Save changes</button></form></details></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<footer class="footer-note">Gaston Power Kenya Ltd · Customer support workspace</footer></main></div></body></html>
