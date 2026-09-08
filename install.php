<?php

declare(strict_types=1);
require_once __DIR__ . '/app.php';

if (syoka_is_installed()) {
    syoka_redirect('login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    syoka_verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
        $error = 'IDは3〜32文字の英数字・_・-・.で入力してください。';
    } elseif (strlen($password) < 10) {
        $error = 'パスワードは10文字以上にしてください。';
    } elseif (!hash_equals($password, $password2)) {
        $error = '確認用パスワードが一致しません。';
    } else {
        try {
            syoka_install_schema();
            $stmt = syoka_db()->prepare('INSERT INTO users(username, password_hash, created_at) VALUES(?,?,?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), syoka_now()]);
            syoka_flash('success', 'Syokaの初期設定が完了しました。ログインしてください。');
            syoka_redirect('login.php');
        } catch (Throwable $e) {
            $error = '初期設定に失敗しました: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Syoka 初期設定</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <h1>Syoka</h1>
    <p class="muted">RSSから紹介記事の下書きを作る独立Webアプリ</p>
    <h2>初期設定</h2>
    <?php if ($error !== ''): ?><div class="notice error"><?= syoka_h($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= syoka_h(syoka_csrf_token()) ?>">
        <label>ログインID
            <input type="text" name="username" required minlength="3" maxlength="32" autocomplete="username">
        </label>
        <label>パスワード
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
        </label>
        <label>パスワード（確認）
            <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
        </label>
        <button type="submit" class="button primary">Syokaをセットアップ</button>
    </form>
    <p class="help">SQLiteを自動作成します。PHP 8.1+ / PDO_SQLite / cURL / SimpleXML が必要です。</p>
</main>
</body>
</html>
