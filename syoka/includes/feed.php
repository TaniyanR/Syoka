<?php

if (!defined('ABSPATH')) {
    exit;
}

function syoka_get_feed_cache_key(string $url): string
{
    return 'syoka_feed_' . md5($url);
}

function syoka_clear_feed_cache(string $url): void
{
    delete_transient(syoka_get_feed_cache_key($url));
}

function syoka_extract_image_url(SimplePie_Item $item): string
{
    $media_thumbnail = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
    if (!empty($media_thumbnail[0]['attribs'][''])) {
        $attrs = $media_thumbnail[0]['attribs'][''];
        if (!empty($attrs['url'])) {
            return esc_url_raw($attrs['url']);
        }
    }

    $media_content = $item->get_item_tags('http://search.yahoo.com/mrss/', 'content');
    if (!empty($media_content[0]['attribs'][''])) {
        $attrs = $media_content[0]['attribs'][''];
        if (!empty($attrs['url'])) {
            return esc_url_raw($attrs['url']);
        }
    }

    $enclosure = $item->get_enclosure();
    if ($enclosure && $enclosure->get_link()) {
        return esc_url_raw($enclosure->get_link());
    }

    $content = $item->get_content();
    if ($content && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches)) {
        return esc_url_raw($matches[1]);
    }

    $description = $item->get_description();
    if ($description && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $description, $matches)) {
        return esc_url_raw($matches[1]);
    }

    return '';
}

function syoka_format_excerpt(SimplePie_Item $item): string
{
    $description = $item->get_description();
    if (!$description) {
        return '';
    }

    $text = wp_strip_all_tags($description);
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    return wp_trim_words($text, 55, '...');
}

function syoka_get_feed_items(string $url, bool $force_refresh = false): array
{
    $cache_key = syoka_get_feed_cache_key($url);

    if (!$force_refresh) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    require_once ABSPATH . WPINC . '/feed.php';
    $feed = fetch_feed($url);

    if (is_wp_error($feed)) {
        return ['error' => $feed->get_error_message(), 'items' => []];
    }

    $feed->set_item_limit(5);
    $items = $feed->get_items(0, 5);

    $payload = ['error' => '', 'items' => $items];
    set_transient($cache_key, $payload, 10 * MINUTE_IN_SECONDS);

    return $payload;
}
