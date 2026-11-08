<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

try {
    $count = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $exception) {
    http_response_code(500);
    exit('Database is not ready. Import database/schema.sql and check config/database.php.');
}
if ($count > 0) {
    http_response_code(403);
    exit('Initial setup is already complete. Delete setup.php from the server.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || !$email || strlen($password) < 12) {
        $error = 'Enter a name, valid email, and password of at least 12 characters.';
    } else {
        $stmt = db()->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,'admin')");
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        header('Location: login.php?setup=complete');
        exit;
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Initial setup | Gaston Power</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="auth-page"><main class="auth-card"><div class="brand-mark">GP</div><p class="eyebrow">GASTON POWER KENYA LTD</p><h1>Create administrator</h1><p class="muted">This one-time page creates the first system administrator.</p>
<?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Full name<input name="full_name" maxlength="120" required autocomplete="name"></label><label>Email address<input type="email" name="email" maxlength="190" required autocomplete="email"></label><label>Password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button class="button" type="submit">Create administrator</button></form><p class="small muted">After setup, immediately delete this file from your web server.</p></main></body></html>
