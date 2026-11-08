<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($password) > 1024) {
        $error = 'Invalid email or password.';
    } else {
        $stmt = db()->prepare('SELECT id,full_name,email,password_hash,role,is_active,failed_login_count,locked_until FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $locked = $user && $user['locked_until'] && strtotime($user['locked_until']) > time();
        if (!$user || !$user['is_active'] || $locked || !password_verify($password, $user['password_hash'])) {
            if ($user && !$locked) {
                $fails = (int)$user['failed_login_count'] + 1;
                $until = $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
                $up = db()->prepare('UPDATE users SET failed_login_count = ?, locked_until = ? WHERE id = ?');
                $up->execute([$until ? 0 : $fails, $until, $user['id']]);
            }
            usleep(250000);
            $error = 'Invalid email or password, or the account is temporarily unavailable.';
        } else {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $rehash = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $up = db()->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?');
            $up->execute([$user['id']]);
            audit('login', 'Successful sign in');
            header('Location: dashboard.php');
            exit;
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in | Gaston Power</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="auth-page"><main class="auth-card"><div class="brand-mark">GP</div><p class="eyebrow">GASTON POWER KENYA LTD</p><h1>Generator Maintenance</h1><p class="muted">Sign in to your maintenance workspace.</p>
<?php if (isset($_GET['setup'])): ?><p class="success">Administrator created. Delete setup.php from the server before continuing.</p><?php endif; ?>
<?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Email address<input type="email" name="email" maxlength="190" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="button" type="submit">Sign in</button></form><p class="small muted">Access is restricted to authorised staff.</p></main></body></html>
