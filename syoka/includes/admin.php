<?php

if (!defined('ABSPATH')) {
    exit;
}

function syoka_get_sites(): array
{
    $sites = get_option('syoka_rss_sites');
    if (!is_array($sites)) {
        return [];
    }

    return $sites;
}

function syoka_save_sites(array $sites): void
{
    update_option('syoka_rss_sites', $sites);
}

function syoka_render_sites_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.'));
    }

    $sites = syoka_get_sites();
    $notice = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['syoka_action'])) {
        check_admin_referer('syoka_sites_action', 'syoka_nonce');
        $action = sanitize_text_field(wp_unslash($_POST['syoka_action']));

        if ($action === 'add') {
            $url = isset($_POST['syoka_url']) ? esc_url_raw(wp_unslash($_POST['syoka_url'])) : '';
            if ($url === '' || !preg_match('#^https?://#', $url)) {
                $notice = 'URLが無効です。';
            } elseif (count($sites) >= 10) {
                $notice = '登録できるRSSは最大10件です。';
            } else {
                $sites[] = [
                    'url' => $url,
                    'active' => true,
                ];
                syoka_save_sites($sites);
                $notice = '追加しました。';
            }
        }

        if ($action === 'update') {
            $updated = [];
            $posted_sites = isset($_POST['syoka_sites']) ? (array) wp_unslash($_POST['syoka_sites']) : [];

            foreach ($posted_sites as $index => $site_data) {
                if (!is_array($site_data)) {
                    continue;
                }
                $url = isset($site_data['url']) ? esc_url_raw($site_data['url']) : '';
                if ($url === '' || !preg_match('#^https?://#', $url)) {
                    continue;
                }
                $active = isset($site_data['active']) && $site_data['active'] === '1';
                $updated[] = [
                    'url' => $url,
                    'active' => $active,
                ];
            }

            if (count($updated) > 10) {
                $updated = array_slice($updated, 0, 10);
                $notice = '登録できるRSSは最大10件です。';
            } else {
                $notice = '更新しました。';
            }

            syoka_save_sites($updated);
            $sites = $updated;
        }

        if ($action === 'delete') {
            $delete_index = isset($_POST['syoka_index']) ? (int) $_POST['syoka_index'] : -1;
            if (isset($sites[$delete_index])) {
                unset($sites[$delete_index]);
                $sites = array_values($sites);
                syoka_save_sites($sites);
                $notice = '削除しました。';
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>RSSサイト管理</h1>';

    if ($notice !== '') {
        echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
    }

    echo '<h2>RSSを追加</h2>';
    echo '<form method="post">';
    wp_nonce_field('syoka_sites_action', 'syoka_nonce');
    echo '<input type="hidden" name="syoka_action" value="add" />';
    echo '<input type="url" name="syoka_url" class="regular-text" required />';
    echo '<p><button class="button button-primary"' . (count($sites) >= 10 ? ' disabled' : '') . '>追加</button></p>';
    echo '</form>';

    echo '<h2>登録済みRSS</h2>';
    if (empty($sites)) {
        echo '<p>登録されたRSSがありません。</p>';
    } else {
        echo '<form method="post">';
        wp_nonce_field('syoka_sites_action', 'syoka_nonce');
        echo '<input type="hidden" name="syoka_action" value="update" />';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>URL</th><th>有効</th><th>削除</th></tr></thead>';
        echo '<tbody>';
        foreach ($sites as $index => $site) {
            $url = isset($site['url']) ? $site['url'] : '';
            $active = !empty($site['active']);
            echo '<tr>';
            echo '<td><input type="url" name="syoka_sites[' . esc_attr((string) $index) . '][url]" value="' . esc_url($url) . '" class="regular-text" required /></td>';
            echo '<td><label><input type="checkbox" name="syoka_sites[' . esc_attr((string) $index) . '][active]" value="1"' . checked(true, $active, false) . ' /> 有効</label></td>';
            echo '<td>';
            echo '<button class="button" name="syoka_action" value="delete" onclick="return confirm(\'削除しますか？\');">削除</button>';
            echo '<input type="hidden" name="syoka_index" value="' . esc_attr((string) $index) . '" />';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '<p><button class="button button-primary">更新</button></p>';
        echo '</form>';
    }
    echo '</div>';
}

function syoka_render_candidates_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.'));
    }

    $sites = array_values(array_filter(syoka_get_sites(), static function ($site) {
        return !empty($site['active']);
    }));

    $notice_messages = [];
    $created = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['syoka_action'])) {
        check_admin_referer('syoka_candidates_action', 'syoka_nonce');
        $action = sanitize_text_field(wp_unslash($_POST['syoka_action']));

        if (str_starts_with($action, 'refresh::')) {
            $refresh_hash = substr($action, strlen('refresh::'));
            $feed_urls = isset($_POST['syoka_feed_urls']) ? (array) wp_unslash($_POST['syoka_feed_urls']) : [];
            $refresh_url = isset($feed_urls[$refresh_hash]) ? esc_url_raw($feed_urls[$refresh_hash]) : '';
            if ($refresh_url !== '') {
                syoka_clear_feed_cache($refresh_url);
                $notice_messages[] = 'キャッシュを削除して再取得します。';
            }
        }

        if ($action === 'create') {
            $selected = isset($_POST['syoka_items']) ? (array) wp_unslash($_POST['syoka_items']) : [];

            foreach ($selected as $payload) {
                if (!isset($payload['selected']) || $payload['selected'] !== '1') {
                    continue;
                }
                if (!isset($payload['feed_url'], $payload['item_hash'])) {
                    continue;
                }
                $feed_url = esc_url_raw($payload['feed_url']);
                $item_hash = sanitize_text_field($payload['item_hash']);

                $feed_data = syoka_get_feed_items($feed_url, false);
                if (!empty($feed_data['error'])) {
                    $notice_messages[] = $feed_url . ' の取得でエラー: ' . $feed_data['error'];
                    continue;
                }

                $match = null;
                foreach ($feed_data['items'] as $item) {
                    $guid = (string) $item->get_id();
                    $link = (string) $item->get_link();
                    $hash = md5($guid !== '' ? $guid : $link);
                    if ($hash === $item_hash) {
                        $match = $item;
                        break;
                    }
                }

                if (!$match) {
                    $notice_messages[] = '選択された記事が見つかりませんでした。';
                    continue;
                }

                $image_url = syoka_extract_image_url($match);
                $site_name = $match->get_feed() ? $match->get_feed()->get_title() : '';
                $result = syoka_create_post_from_item($match, $site_name, $feed_url, $image_url);

                if ($result['status'] === 'success') {
                    $created[] = (int) $result['post_id'];
                } elseif ($result['status'] === 'duplicate') {
                    $notice_messages[] = $match->get_title() . ' は既に投稿済みです。';
                } else {
                    $notice_messages[] = $match->get_title() . ' の作成に失敗しました: ' . ($result['message'] ?? '');
                }
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>候補一覧</h1>';

    foreach ($notice_messages as $message) {
        echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
    }

    if (!empty($created)) {
        echo '<div class="notice notice-success"><p>下書きを作成しました。</p><ul>';
        foreach ($created as $post_id) {
            $edit_link = get_edit_post_link($post_id);
            $title = get_the_title($post_id);
            echo '<li><a href="' . esc_url($edit_link) . '">' . esc_html($title) . '</a></li>';
        }
        echo '</ul></div>';
    }

    if (empty($sites)) {
        echo '<p>有効なRSSがありません。RSSサイト管理で追加してください。</p>';
        echo '</div>';
        return;
    }

    echo '<form method="post">';
    wp_nonce_field('syoka_candidates_action', 'syoka_nonce');

    foreach ($sites as $site) {
        $feed_url = $site['url'];
        $feed_data = syoka_get_feed_items($feed_url, false);
        $feed_hash = md5($feed_url);
        echo '<h2>' . esc_html($feed_url) . '</h2>';
        echo '<input type="hidden" name="syoka_feed_urls[' . esc_attr($feed_hash) . ']" value="' . esc_url($feed_url) . '" />';

        if (!empty($feed_data['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html($feed_data['error']) . '</p></div>';
            continue;
        }

        $items = $feed_data['items'];
        if (empty($items)) {
            echo '<p>候補がありません。</p>';
            continue;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>選択</th><th>サムネ</th><th>タイトル</th><th>URL</th><th>日付</th><th>状態</th></tr></thead>';
        echo '<tbody>';

        foreach ($items as $item) {
            $guid = (string) $item->get_id();
            $link = (string) $item->get_link();
            $hash = md5($guid !== '' ? $guid : $link);
            $date = $item->get_date('Y-m-d H:i');
            $image_url = syoka_extract_image_url($item);
            $is_duplicate = syoka_is_duplicate($guid, $link);

            echo '<tr>';
            echo '<td>';
            if ($is_duplicate) {
                echo '<input type="checkbox" disabled />';
            } else {
                echo '<input type="checkbox" name="syoka_items[' . esc_attr($hash) . '][selected]" value="1" />';
                echo '<input type="hidden" name="syoka_items[' . esc_attr($hash) . '][feed_url]" value="' . esc_url($feed_url) . '" />';
                echo '<input type="hidden" name="syoka_items[' . esc_attr($hash) . '][item_hash]" value="' . esc_attr($hash) . '" />';
            }
            echo '</td>';

            echo '<td>';
            if ($image_url !== '') {
                echo '<img src="' . esc_url($image_url) . '" alt="" style="max-width:60px; height:auto;" />';
            } else {
                echo 'なし';
            }
            echo '</td>';

            echo '<td>' . esc_html($item->get_title()) . '</td>';
            echo '<td><a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link) . '</a></td>';
            echo '<td>' . esc_html($date ?: '-') . '</td>';
            echo '<td>' . ($is_duplicate ? '<span class="badge">投稿済み</span>' : '-') . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        echo '<p>';
        echo '<button class="button" name="syoka_action" value="refresh::' . esc_attr($feed_hash) . '">更新（再取得）</button>';
        echo '</p>';
    }

    echo '<p><button class="button button-primary" name="syoka_action" value="create">選択した記事を下書きで作成</button></p>';
    echo '</form>';
    echo '</div>';
}
