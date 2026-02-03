<?php

if (!defined('ABSPATH')) {
    exit;
}

function syoka_build_marker_block(): string
{
    return '<div style="border:2px dashed #ccc; padding:12px; margin:0 0 16px; text-align:center; font-weight:700;">' .
        'ここに画像が入ります（後で手動でアイキャッチを設定してください）' .
        '</div>';
}

function syoka_is_duplicate(string $guid, string $link): bool
{
    $meta_query = ['relation' => 'OR'];

    if ($guid !== '') {
        $meta_query[] = [
            'key' => '_syoka_source_guid',
            'value' => $guid,
            'compare' => '=',
        ];
    }

    if ($link !== '') {
        $meta_query[] = [
            'key' => '_syoka_source_url',
            'value' => $link,
            'compare' => '=',
        ];
    }

    if (count($meta_query) === 1) {
        return false;
    }

    $query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => $meta_query,
    ]);

    return $query->have_posts();
}

function syoka_sideload_image_to_post(string $image_url, int $post_id): int
{
    if ($image_url === '') {
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attachment_id = media_sideload_image($image_url, $post_id, '', 'id');
    if (is_wp_error($attachment_id)) {
        return 0;
    }

    set_post_thumbnail($post_id, (int) $attachment_id);

    return (int) $attachment_id;
}

function syoka_create_post_from_item(SimplePie_Item $item, string $site_name, string $site_url, string $image_url): array
{
    $guid = (string) $item->get_id();
    $link = (string) $item->get_link();

    if (syoka_is_duplicate($guid, $link)) {
        return ['status' => 'duplicate'];
    }

    $title = (string) $item->get_title();
    if ($title === '') {
        $title = '(タイトルなし)';
    }

    $excerpt = syoka_format_excerpt($item);
    $source_name = $site_name !== '' ? $site_name : $site_url;

    $content_parts = [];

    $content_parts[] = '<p>' . esc_html($excerpt !== '' ? $excerpt : 'この記事の要約は後で追加してください。') . '</p>';
    $content_parts[] = '<p><a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">元記事を読む</a></p>';
    $content_parts[] = '<p>出典: ' . esc_html($source_name) . '</p>';

    $post_content = implode("\n\n", $content_parts);

    $inserted_id = wp_insert_post([
        'post_title' => $title,
        'post_content' => $post_content,
        'post_status' => 'draft',
        'post_type' => 'post',
    ], true);

    if (is_wp_error($inserted_id)) {
        return ['status' => 'error', 'message' => $inserted_id->get_error_message()];
    }

    update_post_meta($inserted_id, '_syoka_source_url', $link);
    update_post_meta($inserted_id, '_syoka_source_guid', $guid);

    $has_image = false;
    if ($image_url !== '') {
        $attachment_id = syoka_sideload_image_to_post($image_url, $inserted_id);
        if ($attachment_id > 0) {
            $has_image = true;
        }
    }

    if (!$has_image) {
        $post = get_post($inserted_id);
        if ($post) {
            $marker = syoka_build_marker_block();
            $updated_content = $marker . "\n\n" . $post->post_content;
            wp_update_post([
                'ID' => $inserted_id,
                'post_content' => $updated_content,
            ]);
        }
    }

    return ['status' => 'success', 'post_id' => (int) $inserted_id];
}
