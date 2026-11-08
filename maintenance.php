<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();
$role = (string)($_SESSION['role'] ?? '');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        require_role(['admin']);
        $generatorId = (int)($_POST['generator_id'] ?? 0);
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);
        $type = (string)($_POST['job_type'] ?? 'routine');
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $scheduled = trim((string)($_POST['scheduled_at'] ?? ''));
        if ($generatorId < 1 || $title === '' || mb_strlen($title) > 180 || mb_strlen($description) > 5000 || !in_array($type,['routine','preventive','corrective','emergency'],true)) {
            $error = 'Check the work order details and try again.';
        } else {
            $check = db()->prepare('SELECT id FROM generators WHERE id=?');
            $check->execute([$generatorId]);
            if (!$check->fetch()) { $error = 'Selected generator was not found.'; }
            else {
                $tech = null;
                if ($assignedTo > 0) {
                    $q = db()->prepare("SELECT id FROM users WHERE id=? AND role='technician' AND is_active=1");
                    $q->execute([$assignedTo]); $tech = $q->fetchColumn();
                    if (!$tech) $error = 'Selected technician is unavailable.';
                }
                if ($error === '') {
                    $date = null;
                    if ($scheduled !== '') {
                        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduled);
                        if (!$dt || $dt->format('Y-m-d\TH:i') !== $scheduled) $error = 'Invalid scheduled date.';
                        else $date = $dt->format('Y-m-d H:i:s');
                    }
                    if ($error === '') {
                        $stmt = db()->prepare('INSERT INTO maintenance_jobs (generator_id,assigned_to,job_type,title,description,scheduled_at,created_by) VALUES (?,?,?,?,?,?,?)');
                        $stmt->execute([$generatorId,$tech ?: null,$type,$title,$description ?: null,$date,(int)$_SESSION['user_id']]);
                        audit('maintenance_created', 'Work order ' . db()->lastInsertId());
                        header('Location: maintenance.php?saved=1'); exit;
                    }
                }
            }
        }
    } elseif ($action === 'update') {
        require_role(['admin','technician']);
        $id = (int)($_POST['job_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $find = db()->prepare('SELECT id,assigned_to FROM maintenance_jobs WHERE id=?');
        $find->execute([$id]); $job = $find->fetch();
        if (!$job || !in_array($status,['scheduled','in_progress','completed','cancelled'],true)) $error = 'Work order or status is invalid.';
        elseif ($role === 'technician' && (int)$job['assigned_to'] !== (int)$_SESSION['user_id']) { http_response_code(403); exit('You can only update work assigned to you.'); }
        else {
            $findings = trim((string)($_POST['findings'] ?? ''));
            $work = trim((string)($_POST['work_performed'] ?? ''));
            if (mb_strlen($findings)>5000 || mb_strlen($work)>5000) $error = 'Notes are too long.';
            else {
                $stmt = db()->prepare('UPDATE maintenance_jobs SET status=?, findings=?, work_performed=?, started_at=IF(?="in_progress" AND started_at IS NULL,NOW(),started_at), completed_at=IF(?="completed",NOW(),IF(?="in_progress",NULL,completed_at)) WHERE id=?');
                $stmt->execute([$status,$findings ?: null,$work ?: null,$status,$status,$status,$id]);
                audit('maintenance_updated','Work order ' . $id . ' set to ' . $status);
                header('Location: maintenance.php?updated=1'); exit;
            }
        }
    }
}
$generators = db()->query('SELECT id,asset_code,make,model FROM generators ORDER BY asset_code')->fetchAll();
$techs = db()->query("SELECT id,full_name FROM users WHERE role='technician' AND is_active=1 ORDER BY full_name")->fetchAll();
$sql = "SELECT j.*,g.asset_code,g.make,g.model,u.full_name AS technician FROM maintenance_jobs j JOIN generators g ON g.id=j.generator_id LEFT JOIN users u ON u.id=j.assigned_to";
if ($role === 'technician') {
    $stmt = db()->prepare($sql . ' WHERE j.assigned_to=? ORDER BY COALESCE(j.scheduled_at,j.created_at) DESC');
    $stmt->execute([(int)$_SESSION['user_id']]); $jobs=$stmt->fetchAll();
} else { $jobs=db()->query($sql . ' ORDER BY COALESCE(j.scheduled_at,j.created_at) DESC')->fetchAll(); }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Maintenance | Gaston Power</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app-shell"><aside class="sidebar"><div class="side-brand"><span class="brand-mark">GP</span><div><strong>Gaston Power</strong><small>Maintenance Suite</small></div></div><nav><a href="dashboard.php">Overview</a><a href="generators.php">Generators</a><a href="customers.php">Customers</a><a class="active" href="maintenance.php">Maintenance</a><a href="parts.php">Spare parts</a><a href="change-password.php">Change password</a></nav><div class="side-bottom"><span><?= e($_SESSION['full_name'] ?? 'Staff') ?></span><small><?= e(ucfirst($role)) ?></small><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout">Sign out</button></form></div></aside><main class="main-content"><header class="topbar"><div><p class="eyebrow">SERVICE OPERATIONS</p><h1>Maintenance work orders</h1><p class="muted">Schedule, assign and record generator servicing.</p></div><span class="role-chip"><?= count($jobs) ?> work orders</span></header>
<?php if(isset($_GET['saved'])):?><p class="success">Work order created.</p><?php endif;?><?php if(isset($_GET['updated'])):?><p class="success">Work order updated.</p><?php endif;?><?php if($error):?><p class="alert"><?=e($error)?></p><?php endif;?>
<?php if($role==='admin'):?><section class="panel form-panel"><h2>Schedule maintenance</h2><?php if(!$generators):?><p class="muted">Register a generator before creating a work order.</p><?php else:?><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create"><label>Generator<select name="generator_id" required><?php foreach($generators as $g):?><option value="<?=(int)$g['id']?>"><?=e($g['asset_code'].' · '.$g['make'].' '.$g['model'])?></option><?php endforeach;?></select></label><label>Assign technician<select name="assigned_to"><option value="0">Unassigned</option><?php foreach($techs as $t):?><option value="<?=(int)$t['id']?>"><?=e($t['full_name'])?></option><?php endforeach;?></select></label><label>Maintenance type<select name="job_type"><option value="routine">Routine</option><option value="preventive">Preventive</option><option value="corrective">Corrective</option><option value="emergency">Emergency</option></select></label><label>Scheduled date and time<input type="datetime-local" name="scheduled_at"></label><label class="wide">Work order title<input name="title" maxlength="180" required placeholder="e.g. 500-hour preventive service"></label><label class="wide">Description<textarea name="description" rows="2" maxlength="5000"></textarea></label><div class="wide"><button class="button">Create work order</button></div></form><?php endif;?></section><?php endif;?>
<section class="panel"><div class="panel-heading"><div><h2>Work order register</h2><p class="muted">Technicians see only jobs assigned to them.</p></div></div><?php if(!$jobs):?><div class="empty-state"><h3>No work orders found</h3><p>Scheduled maintenance will appear here.</p></div><?php else:?><div class="table-wrap"><table><thead><tr><th>Work order</th><th>Generator</th><th>Technician</th><th>Scheduled</th><th>Status</th><th>Update</th></tr></thead><tbody><?php foreach($jobs as $j):?><tr><td><strong><?=e($j['title'])?></strong><br><small class="muted"><?=e(ucfirst($j['job_type']))?> · <?=e($j['description']??'')?></small></td><td><?=e($j['asset_code'].' · '.$j['make'])?></td><td><?=e($j['technician']??'Unassigned')?></td><td><?=e($j['scheduled_at']??'Not set')?></td><td><span class="status"><?=e(str_replace('_',' ',ucfirst($j['status'])))?></span></td><td><details><summary>Update</summary><form method="post" class="mini-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="job_id" value="<?=(int)$j['id']?>"><label>Status<select name="status"><?php foreach(['scheduled','in_progress','completed','cancelled'] as $st):?><option value="<?=$st?>" <?=$j['status']===$st?'selected':''?>><?=e(ucfirst(str_replace('_',' ',$st)))?></option><?php endforeach;?></select></label><label>Findings<textarea name="findings" rows="2" maxlength="5000"><?=e($j['findings']??'')?></textarea></label><label>Work performed<textarea name="work_performed" rows="2" maxlength="5000"><?=e($j['work_performed']??'')?></textarea></label><button class="button">Save update</button></form></details></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section></main></div></body></html>
