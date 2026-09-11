<?php

declare(strict_types=1);
require_once __DIR__ . '/app.php';

if (!syoka_is_installed()) {
    syoka_redirect('install.php');
}
syoka_require_login();

$page = isset($_GET['page']) ? (string) $_GET['page'] : 'dashboard';
$allowedPages = ['dashboard', 'feeds', 'candidates', 'drafts', 'draft'];
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    syoka_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add_feed') {
            $url = trim((string) ($_POST['url'] ?? ''));
            syoka_add_feed($url, !empty($_POST['active']));
            syoka_flash('success', 'RSSを追加しました。');
            syoka_redirect('index.php?page=feeds');
        }

        if ($action === 'save_feeds') {
            $rows = isset($_POST['feeds']) && is_array($_POST['feeds']) ? $_POST['feeds'] : [];
            foreach ($rows as $id => $row) {
                if (!is_array($row)) {
                    continue;
                }
                syoka_update_feed((int) $id, trim((string) ($row['url'] ?? '')), !empty($row['active']));
            }
            syoka_flash('success', 'RSS設定を保存しました。');
            syoka_redirect('index.php?page=feeds');
        }

        if ($action === 'delete_feed') {
            syoka_delete_feed((int) ($_POST['feed_id'] ?? 0));
            syoka_flash('success', 'RSSを削除しました。');
            syoka_redirect('index.php?page=feeds');
        }

        if (str_starts_with($action, 'refresh:')) {
            $feedId = (int) substr($action, 8);
            $feed = syoka_feed_by_id($feedId);
            if (!$feed) {
                throw new RuntimeException('RSSが見つかりません。');
            }
            syoka_feed_items((string) $feed['url'], true);
            syoka_flash('success', 'RSSを再取得しました。');
            syoka_redirect('index.php?page=candidates');
        }

        if ($action === 'create_drafts') {
            $selected = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];
            if (!$selected) {
                throw new RuntimeException('記事を選択してください。');
            }

            $grouped = [];
            foreach ($selected as $value) {
                $parts = explode('|', (string) $value, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $grouped[(int) $parts[0]][] = $parts[1];
            }

            $created = 0;
            $skipped = 0;
            foreach ($grouped as $feedId => $hashes) {
                $feed = syoka_feed_by_id((int) $feedId);
                if (!$feed || empty($feed['active'])) {
                    continue;
                }
                $items = syoka_feed_items((string) $feed['url']);
                $map = [];
                foreach ($items as $item) {
                    $map[(string) $item['item_hash']] = $item;
                }
                foreach (array_unique($hashes) as $hash) {
                    if (!isset($map[$hash])) {
                        $skipped++;
                        continue;
                    }
                    try {
                        syoka_create_draft($map[$hash]);
                        $created++;
                    } catch (RuntimeException $e) {
                        $skipped++;
                    }
                }
            }
            syoka_flash('success', $created . '件の下書きを作成しました。' . ($skipped ? ' ' . $skipped . '件は重複または取得差分のためスキップしました。' : ''));
            syoka_redirect('index.php?page=drafts');
        }

        if ($action === 'save_draft') {
            $id = (int) ($_POST['draft_id'] ?? 0);
            syoka_update_draft(
                $id,
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['excerpt'] ?? ''),
                trim((string) ($_POST['image_url'] ?? ''))
            );
            syoka_flash('success', '下書きを保存しました。');
            syoka_redirect('index.php?page=draft&id=' . $id);
        }

        if ($action === 'delete_draft') {
            syoka_delete_draft((int) ($_POST['draft_id'] ?? 0));
            syoka_flash('success', '下書きを削除しました。');
            syoka_redirect('index.php?page=drafts');
        }
    } catch (Throwable $e) {
        syoka_flash('error', $e->getMessage());
        $back = match (true) {
            str_contains($action, 'feed') => 'feeds',
            str_starts_with($action, 'refresh') || $action === 'create_drafts' => 'candidates',
            str_contains($action, 'draft') => 'drafts',
            default => $page,
        };
        syoka_redirect('index.php?page=' . $back);
    }
}

$flashes = syoka_take_flashes();
$feeds = syoka_get_feeds();
$activeFeeds = array_values(array_filter($feeds, static fn(array $feed): bool => !empty($feed['active'])));
$drafts = syoka_get_drafts();

function syoka_nav_active(string $current, string $target): string
{
    return $current === $target || ($current === 'draft' && $target === 'drafts') ? ' active' : '';
}
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Syoka</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="index.php">Syoka <small>ショーカ</small></a>
        <nav>
            <a class="<?= syoka_nav_active($page, 'dashboard') ?>" href="index.php">ダッシュボード</a>
            <a class="<?= syoka_nav_active($page, 'feeds') ?>" href="index.php?page=feeds">RSSサイト管理</a>
            <a class="<?= syoka_nav_active($page, 'candidates') ?>" href="index.php?page=candidates">候補一覧</a>
            <a class="<?= syoka_nav_active($page, 'drafts') ?>" href="index.php?page=drafts">下書き一覧</a>
        </nav>
        <div class="sidebar-bottom">
            <span><?= syoka_h((string) ($_SESSION['username'] ?? '')) ?></span>
            <a href="logout.php">ログアウト</a>
        </div>
    </aside>

    <main class="main-content">
        <?php foreach ($flashes as $flash): ?>
            <div class="notice <?= syoka_h((string) $flash['type']) ?>"><?= syoka_h((string) $flash['message']) ?></div>
        <?php endforeach; ?>

        <?php if ($page === 'dashboard'): ?>
            <div class="page-head"><div><h1>ダッシュボード</h1><p>RSSを確認し、選んだ記事だけを紹介記事の下書きにします。</p></div></div>
            <div class="stats-grid">
                <a class="stat-card" href="index.php?page=feeds"><strong><?= count($feeds) ?></strong><span>登録RSS / 最大10</span></a>
                <a class="stat-card" href="index.php?page=candidates"><strong><?= count($activeFeeds) ?></strong><span>有効なRSS</span></a>
                <a class="stat-card" href="index.php?page=drafts"><strong><?= count($drafts) ?></strong><span>保存済み下書き</span></a>
            </div>
            <section class="panel">
                <h2>使い方</h2>
                <ol class="steps">
                    <li><b>RSSサイト管理</b>でRSSを最大10サイト登録します。</li>
                    <li><b>候補一覧</b>に各サイト最新5記事が表示されます。</li>
                    <li>紹介したい記事を選び、<b>選択した記事を下書き作成</b>を押します。</li>
                    <li><b>下書き一覧</b>でタイトル・説明・画像URLを確認、編集します。</li>
                </ol>
            </section>

        <?php elseif ($page === 'feeds'): ?>
            <div class="page-head"><div><h1>RSSサイト管理</h1><p>登録できるRSSは最大10サイトです。</p></div><span class="counter"><?= count($feeds) ?> / <?= SYOKA_FEED_LIMIT ?></span></div>

            <section class="panel">
                <h2>RSSを追加</h2>
                <?php if (count($feeds) < SYOKA_FEED_LIMIT): ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= syoka_h(syoka_csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_feed">
                        <input type="url" name="url" placeholder="https://example.com/feed" required>
                        <label class="check"><input type="checkbox" name="active" value="1" checked> 有効</label>
                        <button class="button primary" type="submit">追加</button>
                    </form>
                <?php else: ?>
                    <div class="notice warning">最大10サイト登録されています。追加する場合は既存RSSを削除してください。</div>
                <?php endif; ?>
            </section>

            <section class="panel">
                <h2>登録済みRSS</h2>
                <?php if (!$feeds): ?><p class="empty">登録済みRSSはありません。</p><?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= syoka_h(syoka_csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_feeds">
                    <div class="table-wrap"><table>
                        <thead><tr><th>有効</th><th>RSS URL</th><th>操作</th></tr></thead>
                        <tbody>
                        <?php foreach ($feeds as $feed): ?>
                            <tr>
                                <td><input type="checkbox" name="feeds[<?= (int) $feed['id'] ?>][active]" value="1" <?= !empty($feed['active']) ? 'checked' : '' ?>></td>
                                <td><input class="table-input" type="url" name="feeds[<?= (int) $feed['id'] ?>][url]" value="<?= syoka_h((string) $feed['url']) ?>" required></td>
                                <td><button type="submit" form="delete-feed-<?= (int) $feed['id'] ?>" class="button danger small" onclick="return confirm('このRSSを削除しますか？')">削除</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <p><button class="button primary" type="submit">変更を保存</button></p>
                </form>
                <?php foreach ($feeds as $feed): ?>
                    <form id="delete-feed-<?= (int) $feed['id'] ?>" method="post" class="hidden">
                        <input type="hidden" name="csrf_token" value="<?= syoka_h(syoka_csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_feed">
                        <input type="hidden" name="feed_id" value="<?= (int) $feed['id'] ?>">
                    </form>
                <?php endforeach; ?>
                <?php endif; ?>
            </section>

        <?php elseif ($page === 'candidates'): ?>
            <div class="page-head"><div><h1>候補一覧</h1><p>有効なRSSごとに最新5記事を表示します。選んだ記事だけが下書きになります。</p></div></div>
            <?php if (!$activeFeeds): ?><div class="panel empty">有効なRSSがありません。<a href="index.php?page=feeds">RSSサイト管理</a>から登録してください。</div><?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= syoka_h(syoka_csrf_token()) ?>">
                <?php foreach ($activeFeeds as $feed): ?>
                    <section class="panel feed-panel">
                        <div class="panel-head">
                            <div><h2><?= syoka_h((string) $feed['url']) ?></h2></div>
                            <button class="button small" type="submit" name="action" value="refresh:<?= (int) $feed['id'] ?>">更新（再取得）</button>
                        </div>
                        <?php try { $items = syoka_feed_items((string) $feed['url']); } catch (Throwable $e) { $items = []; ?>
                            <div class="notice error"><?= syoka_h($e->getMessage()) ?></div>
                        <?php } ?>
                        <?php if (!$items): ?><p class="empty">表示できる記事がありません。</p><?php else: ?>
                        <div class="candidate-grid">
                            <?php foreach ($items as $item): $duplicate = syoka_is_duplicate_item((string) $item['guid'], (string) $item['link']); ?>
                                <article class="candidate-card<?= $duplicate ? ' done' : '' ?>">
                                    <div class="candidate-image">
                                        <?php if ((string) $item['image_url'] !== ''): ?><img src="<?= syoka_h((string) $item['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                                        <?php else: ?><div class="image-placeholder">画像なし</div><?php endif; ?>
                                    </div>
                                    <div class="candidate-body">
                                        <div class="candidate-top">
                                            <?php if ($duplicate): ?><span class="badge">下書き済み</span><?php else: ?><label class="select-box"><input type="checkbox" name="items[]" value="<?= (int) $feed['id'] ?>|<?= syoka_h((string) $item['item_hash']) ?>"> 選択</label><?php endif; ?>
                                            <time><?= syoka_h((string) $item['date']) ?></time>
                                        </div>
                                        <h3><?= syoka_h((string) $item['title']) ?></h3>
                                        <?php if ((string) $item['excerpt'] !== ''): ?><p><?= syoka_h((string) $item['excerpt']) ?></p><?php endif; ?>
                                        <a href="<?= syoka_h((string) $item['link']) ?>" target="_blank" rel="noopener noreferrer">元記事を確認 ↗</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
                <div class="sticky-actions"><button class="button primary large" type="submit" name="action" value="create_drafts">選択した記事を下書き作成</button></div>
            </form>
            <?php endif; ?>

        <?php elseif ($page === 'drafts'): ?>
            <div class="page-head"><div><h1>下書き一覧</h1><p>RSSから選択して保存した紹介記事です。</p></div><span class="counter"><?= count($drafts) ?>件</span></div>
            <section class="panel">
            <?php if (!$drafts): ?><p class="empty">下書きはまだありません。<a href="index.php?page=candidates">候補一覧</a>から記事を選んでください。</p><?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>画像</th><th>タイトル</th><th>元記事</th><th>更新日</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($drafts as $draft): ?>
                        <tr>
                            <td class="thumb-cell"><?php if ((string) $draft['image_url'] !== ''): ?><img src="<?= syoka_h((string) $draft['image_url']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><span class="mini-placeholder">画像未設定</span><?php endif; ?></td>
                            <td><a class="title-link" href="index.php?page=draft&id=<?= (int) $draft['id'] ?>"><?= syoka_h((string) $draft['title']) ?></a></td>
                            <td><a href="<?= syoka_h((string) $draft['source_url']) ?>" target="_blank" rel="noopener noreferrer">確認 ↗</a></td>
                            <td><?= syoka_h((string) $draft['updated_at']) ?></td>
                            <td><a class="button small" href="index.php?page=draft&id=<?= (int) $draft['id'] ?>">編集</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            </section>

        <?php elseif ($page === 'draft'): ?>
            <?php $draft = syoka_get_draft((int) ($_GET['id'] ?? 0)); ?>
            <?php if (!$draft): ?>
                <div class="notice error">下書きが見つかりません。</div>
            <?php else: ?>
                <div class="page-head"><div><h1>下書き編集</h1><p>内容を整えて、必要な場所へコピーして利用できます。</p></div><a class="button" href="index.php?page=drafts">一覧へ戻る</a></div>
                <div class="draft-layout">
                    <section class="panel">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= syoka_h(syoka_csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_draft">
                            <input type="hidden" name="draft_id" value="<?= (int) $draft['id'] ?>">
                            <label>タイトル<input type="text" name="title" value="<?= syoka_h((string) $draft['title']) ?>" required></label>
                            <label>画像URL<input type="url" name="image_url" value="<?= syoka_h((string) $draft['image_url']) ?>" placeholder="画像が無い場合は後でここに貼れます"></label>
                            <?php if ((string) $draft['image_url'] === ''): ?><div class="image-missing">ここに画像が入ります<br><small>後で画像URLを貼り付けてください</small></div><?php endif; ?>
                            <label>紹介文<textarea name="excerpt" rows="8"><?= syoka_h((string) $draft['excerpt']) ?></textarea></label>
                            <div class="form-actions"><button class="button primary" type="submit">下書きを保存</button><a class="button" href="<?= syoka_h((string) $draft['source_url']) ?>" target="_blank" rel="noopener noreferrer">元記事を開く ↗</a></div>
                        </form>
                    </section>
                    <section class="panel">
                        <h2>完成HTML</h2>
                        <p class="muted">WordPressなどのHTML編集欄へコピーできます。</p>
                        <textarea id="draft-html" class="code-box" rows="16" readonly><?= syoka_h(syoka_draft_html($draft)) ?></textarea>
                        <p><button class="button" type="button" onclick="navigator.clipboard.writeText(document.getElementById('draft-html').value).then(()=>this.textContent='コピーしました')">HTMLをコピー</button></p>
                    </section>
                </div>
                <form method="post" class="danger-zone" onsubmit="return confirm('この下書きを削除しますか？')">
                    <input type="hidden" name="csrf_token" value="<?= syoka_h(syoka_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_draft">
                    <input type="hidden" name="draft_id" value="<?= (int) $draft['id'] ?>">
                    <button class="button danger" type="submit">この下書きを削除</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
