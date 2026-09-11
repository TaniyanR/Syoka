<?php

declare(strict_types=1);
require_once __DIR__ . '/app.php';

if (!syoka_is_installed()) {
    syoka_redirect('install.php');
}
if (syoka_logged_in()) {
    syoka_redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    syoka_verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = syoka_db()->prepare('SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, (string) $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = (string) $user['username'];
        syoka_redirect('index.php');
    }
    usleep(300000);
    $error = 'ログインIDまたはパスワードが違います。';
}
$flashes = syoka_take_flashes();
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Syoka ログイン</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <h1>Syoka</h1>
    <p class="muted">紹介記事ドラフト作成アプリ</p>
    <?php foreach ($flashes as $flash): ?><div class="notice <?= syoka_h((string) $flash['type']) ?>"><?= syoka_h((string) $flash['message']) ?></div><?php endforeach; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= syoka_h($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= syoka_h(syoka_csrf_token()) ?>">
        <label>ログインID
            <input type="text" name="username" required autocomplete="username">
        </label>
        <label>パスワード
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit" class="button primary">ログイン</button>
    </form>
</main>
</body>
</html>
