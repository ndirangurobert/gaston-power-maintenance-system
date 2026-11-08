<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
 $stmt=db()->prepare('SELECT password_hash FROM users WHERE id=? AND is_active=1');$stmt->execute([(int)$_SESSION['user_id']]);$hash=$stmt->fetchColumn();
 if(!$hash||!password_verify($current,(string)$hash))$error='Your current password is incorrect.';
 elseif(strlen($new)<12||strlen($new)>1024)$error='New password must be at least 12 characters.';
 elseif($new!==$confirm)$error='The new passwords do not match.';
 elseif($new===$current)$error='Choose a password different from your current one.';
 else{$up=db()->prepare('UPDATE users SET password_hash=? WHERE id=?');$up->execute([password_hash($new,PASSWORD_DEFAULT),(int)$_SESSION['user_id']]);session_regenerate_id(true);audit('password_changed','User changed their password');header('Location: change-password.php?changed=1');exit;}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Change password | Gaston Power</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app-shell"><aside class="sidebar"><div class="side-brand"><span class="brand-mark">GP</span><div><strong>Gaston Power</strong><small>Maintenance Suite</small></div></div><nav><a href="dashboard.php">Overview</a><a href="generators.php">Generators</a><a href="customers.php">Customers</a><a href="maintenance.php">Maintenance</a><a href="parts.php">Spare parts</a><a class="active" href="change-password.php">Change password</a></nav><div class="side-bottom"><span><?=e($_SESSION['full_name']??'Staff')?></span><small><?=e(ucfirst($_SESSION['role']??''))?></small></div></aside><main class="main-content"><header class="topbar"><div><p class="eyebrow">ACCOUNT / SECURITY</p><h1>Change password</h1><p class="muted">Keep your account protected with a unique password.</p></div></header><?php if(isset($_GET['changed'])):?><p class="success">Password updated successfully.</p><?php endif;?><?php if($error):?><p class="alert"><?=e($error)?></p><?php endif;?><section class="panel form-panel"><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label class="wide">Current password<input type="password" name="current_password" autocomplete="current-password" required></label><label>New password<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><label>Confirm new password<input type="password" name="confirm_password" minlength="12" autocomplete="new-password" required></label><div class="wide"><p class="muted small">Use at least 12 characters. Avoid passwords used on other websites.</p><button class="button">Update password</button></div></form></section></main></div></body></html>
