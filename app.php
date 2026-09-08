<?php

declare(strict_types=1);

const SYOKA_DB_PATH = __DIR__ . '/data/syoka.sqlite';
const SYOKA_FEED_LIMIT = 10;
const SYOKA_ITEMS_PER_FEED = 5;
const SYOKA_CACHE_TTL = 600;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('syoka_session');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

function syoka_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function syoka_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(SYOKA_DB_PATH);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('data ディレクトリを作成できません。');
    }

    $pdo = new PDO('sqlite:' . SYOKA_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    return $pdo;
}

function syoka_is_installed(): bool
{
    if (!is_file(SYOKA_DB_PATH)) {
        return false;
    }

    try {
        $row = syoka_db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        return (bool) $row;
    } catch (Throwable $e) {
        return false;
    }
}

function syoka_install_schema(): void
{
    $db = syoka_db();
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS feeds (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        url TEXT NOT NULL UNIQUE,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS feed_cache (
        feed_url TEXT PRIMARY KEY,
        payload TEXT NOT NULL,
        fetched_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS drafts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_guid TEXT NOT NULL DEFAULT "",
        source_url TEXT NOT NULL UNIQUE,
        feed_url TEXT NOT NULL,
        title TEXT NOT NULL,
        excerpt TEXT NOT NULL DEFAULT "",
        image_url TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_drafts_guid ON drafts(source_guid)');
}

function syoka_now(): string
{
    return date('Y-m-d H:i:s');
}

function syoka_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function syoka_verify_csrf(): void
{
    $token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if ($token === '' || !hash_equals(syoka_csrf_token(), $token)) {
        http_response_code(400);
        exit('不正なリクエストです。ページを再読み込みしてやり直してください。');
    }
}

function syoka_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function syoka_require_login(): void
{
    if (!syoka_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function syoka_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function syoka_take_flashes(): array
{
    $items = isset($_SESSION['flash']) && is_array($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $items;
}

function syoka_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function syoka_valid_http_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function syoka_public_host(string $url): bool
{
    if (!syoka_valid_http_url($url)) {
        return false;
    }

    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '' || strtolower($host) === 'localhost') {
        return false;
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }
    }

    if (!$ips) {
        return false;
    }

    foreach (array_unique($ips) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }

    return true;
}

function syoka_fetch_url(string $url): string
{
    if (!syoka_public_host($url)) {
        throw new RuntimeException('RSS URLが無効、またはローカル/プライベートアドレスです。');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL 拡張が必要です。');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Syoka/2.0 RSS Reader',
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.1'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('RSS取得に失敗しました: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('RSS取得に失敗しました。HTTP ' . $status);
    }
    if (strlen($body) > 5 * 1024 * 1024) {
        throw new RuntimeException('RSSサイズが大きすぎます。');
    }
    if ($contentType !== '' && !str_contains($contentType, 'xml') && !str_contains($contentType, 'rss') && !str_contains($contentType, 'atom') && !str_contains($contentType, 'text')) {
        throw new RuntimeException('RSS/XMLとして扱えないContent-Typeです。');
    }

    return $body;
}

function syoka_feed_items(string $url, bool $force = false): array
{
    $db = syoka_db();
    if (!$force) {
        $stmt = $db->prepare('SELECT payload, fetched_at FROM feed_cache WHERE feed_url = ?');
        $stmt->execute([$url]);
        $row = $stmt->fetch();
        if ($row && ((int) $row['fetched_at'] + SYOKA_CACHE_TTL) >= time()) {
            $items = json_decode((string) $row['payload'], true);
            if (is_array($items)) {
                return $items;
            }
        }
    }

    $xmlText = syoka_fetch_url($url);
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlText, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$xml) {
        throw new RuntimeException('RSS/XMLの解析に失敗しました。');
    }

    $items = [];
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $items[] = syoka_normalize_rss_item($item, $url);
            if (count($items) >= SYOKA_ITEMS_PER_FEED) {
                break;
            }
        }
    } else {
        $namespaces = $xml->getNamespaces(true);
        $entries = $xml->entry ?? [];
        foreach ($entries as $entry) {
            $items[] = syoka_normalize_atom_entry($entry, $url, $namespaces);
            if (count($items) >= SYOKA_ITEMS_PER_FEED) {
                break;
            }
        }
    }

    $items = array_values(array_filter($items, static fn(array $item): bool => $item['link'] !== ''));
    $stmt = $db->prepare('INSERT INTO feed_cache(feed_url, payload, fetched_at) VALUES(?,?,?) ON CONFLICT(feed_url) DO UPDATE SET payload=excluded.payload, fetched_at=excluded.fetched_at');
    $stmt->execute([$url, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
    return $items;
}

function syoka_normalize_rss_item(SimpleXMLElement $item, string $feedUrl): array
{
    $title = trim((string) $item->title);
    $link = trim((string) $item->link);
    $guid = trim((string) $item->guid);
    $date = trim((string) ($item->pubDate ?? ''));
    $description = (string) ($item->description ?? '');
    $content = '';
    $namespaces = $item->getNamespaces(true);
    if (isset($namespaces['content'])) {
        $contentNode = $item->children($namespaces['content']);
        $content = (string) ($contentNode->encoded ?? '');
    }

    return [
        'title' => $title !== '' ? $title : '(タイトルなし)',
        'link' => $link,
        'guid' => $guid,
        'date' => syoka_format_date($date),
        'excerpt' => syoka_excerpt($description !== '' ? $description : $content),
        'image_url' => syoka_extract_rss_image($item, $content !== '' ? $content : $description, $namespaces),
        'feed_url' => $feedUrl,
        'item_hash' => hash('sha256', ($guid !== '' ? $guid : $link)),
    ];
}

function syoka_normalize_atom_entry(SimpleXMLElement $entry, string $feedUrl, array $namespaces): array
{
    $title = trim((string) $entry->title);
    $guid = trim((string) ($entry->id ?? ''));
    $date = trim((string) ($entry->updated ?? $entry->published ?? ''));
    $content = (string) ($entry->content ?? $entry->summary ?? '');
    $link = '';
    foreach ($entry->link as $linkNode) {
        $attrs = $linkNode->attributes();
        $rel = (string) ($attrs['rel'] ?? 'alternate');
        $href = (string) ($attrs['href'] ?? '');
        if ($href !== '' && ($rel === 'alternate' || $link === '')) {
            $link = $href;
        }
    }

    return [
        'title' => $title !== '' ? $title : '(タイトルなし)',
        'link' => $link,
        'guid' => $guid,
        'date' => syoka_format_date($date),
        'excerpt' => syoka_excerpt($content),
        'image_url' => syoka_extract_atom_image($entry, $content, $namespaces),
        'feed_url' => $feedUrl,
        'item_hash' => hash('sha256', ($guid !== '' ? $guid : $link)),
    ];
}

function syoka_extract_rss_image(SimpleXMLElement $item, string $html, array $namespaces): string
{
    if (isset($namespaces['media'])) {
        $media = $item->children($namespaces['media']);
        if (isset($media->thumbnail)) {
            $attrs = $media->thumbnail->attributes();
            if (!empty($attrs['url'])) {
                return trim((string) $attrs['url']);
            }
        }
        if (isset($media->content)) {
            foreach ($media->content as $node) {
                $attrs = $node->attributes();
                if (!empty($attrs['url'])) {
                    return trim((string) $attrs['url']);
                }
            }
        }
    }
    if (isset($item->enclosure)) {
        $attrs = $item->enclosure->attributes();
        if (!empty($attrs['url'])) {
            return trim((string) $attrs['url']);
        }
    }
    return syoka_first_image($html);
}

function syoka_extract_atom_image(SimpleXMLElement $entry, string $html, array $namespaces): string
{
    if (isset($namespaces['media'])) {
        $media = $entry->children($namespaces['media']);
        if (isset($media->thumbnail)) {
            $attrs = $media->thumbnail->attributes();
            if (!empty($attrs['url'])) {
                return trim((string) $attrs['url']);
            }
        }
    }
    return syoka_first_image($html);
}

function syoka_first_image(string $html): string
{
    if ($html !== '' && preg_match('~<img[^>]+src=["\']([^"\']+)["\']~i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

function syoka_excerpt(string $html): string
{
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if (mb_strlen($text) > 240) {
        $text = mb_substr($text, 0, 240) . '…';
    }
    return $text;
}

function syoka_format_date(string $value): string
{
    if ($value === '') {
        return '';
    }
    $time = strtotime($value);
    return $time ? date('Y-m-d H:i', $time) : $value;
}

function syoka_get_feeds(): array
{
    return syoka_db()->query('SELECT * FROM feeds ORDER BY id ASC')->fetchAll();
}

function syoka_feed_by_id(int $id): ?array
{
    $stmt = syoka_db()->prepare('SELECT * FROM feeds WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function syoka_add_feed(string $url, bool $active): void
{
    if (!syoka_valid_http_url($url) || !syoka_public_host($url)) {
        throw new RuntimeException('有効な公開HTTP/HTTPSのRSS URLを入力してください。');
    }
    $db = syoka_db();
    if ((int) $db->query('SELECT COUNT(*) FROM feeds')->fetchColumn() >= SYOKA_FEED_LIMIT) {
        throw new RuntimeException('登録できるRSSは最大10件です。');
    }
    $stmt = $db->prepare('INSERT INTO feeds(url, active, created_at, updated_at) VALUES(?,?,?,?)');
    try {
        $stmt->execute([$url, $active ? 1 : 0, syoka_now(), syoka_now()]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            throw new RuntimeException('このRSS URLはすでに登録されています。');
        }
        throw $e;
    }
}

function syoka_update_feed(int $id, string $url, bool $active): void
{
    if (!syoka_valid_http_url($url) || !syoka_public_host($url)) {
        throw new RuntimeException('有効な公開HTTP/HTTPSのRSS URLを入力してください。');
    }
    $stmt = syoka_db()->prepare('UPDATE feeds SET url=?, active=?, updated_at=? WHERE id=?');
    $stmt->execute([$url, $active ? 1 : 0, syoka_now(), $id]);
}

function syoka_delete_feed(int $id): void
{
    $feed = syoka_feed_by_id($id);
    if ($feed) {
        $stmt = syoka_db()->prepare('DELETE FROM feed_cache WHERE feed_url=?');
        $stmt->execute([$feed['url']]);
    }
    $stmt = syoka_db()->prepare('DELETE FROM feeds WHERE id=?');
    $stmt->execute([$id]);
}

function syoka_is_duplicate_item(string $guid, string $url): bool
{
    $db = syoka_db();
    if ($guid !== '') {
        $stmt = $db->prepare('SELECT id FROM drafts WHERE source_guid = ? OR source_url = ? LIMIT 1');
        $stmt->execute([$guid, $url]);
    } else {
        $stmt = $db->prepare('SELECT id FROM drafts WHERE source_url = ? LIMIT 1');
        $stmt->execute([$url]);
    }
    return (bool) $stmt->fetchColumn();
}

function syoka_create_draft(array $item): int
{
    $guid = trim((string) ($item['guid'] ?? ''));
    $url = trim((string) ($item['link'] ?? ''));
    if ($url === '' || syoka_is_duplicate_item($guid, $url)) {
        throw new RuntimeException('この記事はすでに下書きに保存されています。');
    }
    $stmt = syoka_db()->prepare('INSERT INTO drafts(source_guid, source_url, feed_url, title, excerpt, image_url, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $guid,
        $url,
        (string) ($item['feed_url'] ?? ''),
        (string) ($item['title'] ?? ''),
        (string) ($item['excerpt'] ?? ''),
        (string) ($item['image_url'] ?? ''),
        syoka_now(),
        syoka_now(),
    ]);
    return (int) syoka_db()->lastInsertId();
}

function syoka_get_drafts(): array
{
    return syoka_db()->query('SELECT * FROM drafts ORDER BY id DESC')->fetchAll();
}

function syoka_get_draft(int $id): ?array
{
    $stmt = syoka_db()->prepare('SELECT * FROM drafts WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function syoka_update_draft(int $id, string $title, string $excerpt, string $imageUrl): void
{
    if ($imageUrl !== '' && !syoka_valid_http_url($imageUrl)) {
        throw new RuntimeException('画像URLが無効です。');
    }
    $stmt = syoka_db()->prepare('UPDATE drafts SET title=?, excerpt=?, image_url=?, updated_at=? WHERE id=?');
    $stmt->execute([trim($title), trim($excerpt), trim($imageUrl), syoka_now(), $id]);
}

function syoka_delete_draft(int $id): void
{
    $stmt = syoka_db()->prepare('DELETE FROM drafts WHERE id=?');
    $stmt->execute([$id]);
}

function syoka_draft_html(array $draft): string
{
    $parts = [];
    if ((string) $draft['image_url'] !== '') {
        $parts[] = '<p><img src="' . syoka_h((string) $draft['image_url']) . '" alt=""></p>';
    } else {
        $parts[] = '<div style="border:2px dashed #ccc;padding:12px;margin:0 0 16px;text-align:center;font-weight:700;">ここに画像が入ります（後で手動で画像を設定してください）</div>';
    }
    if ((string) $draft['excerpt'] !== '') {
        $parts[] = '<p>' . syoka_h((string) $draft['excerpt']) . '</p>';
    }
    $parts[] = '<p><a href="' . syoka_h((string) $draft['source_url']) . '" target="_blank" rel="noopener noreferrer">元記事を読む</a></p>';
    $parts[] = '<p>出典: <a href="' . syoka_h((string) $draft['feed_url']) . '" target="_blank" rel="noopener noreferrer">' . syoka_h((string) $draft['feed_url']) . '</a></p>';
    return implode("\n", $parts);
}
