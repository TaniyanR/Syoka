<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function syoka_get_feed_items( $feed_url, $force_refresh = false ) {
    $feed_url = esc_url_raw( $feed_url );
    if ( empty( $feed_url ) ) {
        return new WP_Error( 'syoka_invalid_url', __( 'Invalid feed URL.', 'syoka' ) );
    }

    $transient_key = 'syoka_feed_' . md5( $feed_url );
    if ( $force_refresh ) {
        delete_transient( $transient_key );
    }

    $cached = get_transient( $transient_key );
    if ( false !== $cached ) {
        return $cached;
    }

    require_once ABSPATH . WPINC . '/feed.php';

    $feed = fetch_feed( $feed_url );
    if ( is_wp_error( $feed ) ) {
        return $feed;
    }

    $feed->set_item_limit( SYOKA_ITEMS_PER_FEED );
    $items = $feed->get_items( 0, SYOKA_ITEMS_PER_FEED );

    $normalized = array();
    foreach ( $items as $item ) {
        $guid = (string) $item->get_id();
        $link = (string) $item->get_link();
        $title = (string) $item->get_title();
        $date = $item->get_date( 'Y-m-d H:i' );
        $content = $item->get_content();
        $description = $item->get_description();
        $excerpt = syoka_build_excerpt( $description, $content );

        $image_url = syoka_extract_image_url( $item, $content, $description );
        $item_hash = syoka_generate_item_hash( $guid, $link, $title, $date );

        $normalized[] = array(
            'guid'        => $guid,
            'link'        => $link,
            'title'       => $title,
            'date'        => $date,
            'excerpt'     => $excerpt,
            'image_url'   => $image_url,
            'feed_url'    => $feed_url,
            'item_hash'   => $item_hash,
        );
    }

    set_transient( $transient_key, $normalized, SYOKA_CACHE_TTL );

    return $normalized;
}

function syoka_generate_item_hash( $guid, $link, $title, $date ) {
    $primary = ! empty( $guid ) ? $guid : $link;
    return md5( $primary . '|' . $title . '|' . $date );
}

function syoka_build_excerpt( $description, $content ) {
    $text = '';
    if ( ! empty( $description ) ) {
        $text = $description;
    } elseif ( ! empty( $content ) ) {
        $text = $content;
    }

    $text = wp_strip_all_tags( (string) $text );
    $text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
    $text = preg_replace( '/\s+/u', ' ', $text );
    $text = trim( $text );

    if ( '' === $text ) {
        return '';
    }

    $limit = 240;
    if ( function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $text ) > $limit ) {
            $text = mb_substr( $text, 0, $limit );
        }
    } elseif ( strlen( $text ) > $limit ) {
        $text = substr( $text, 0, $limit );
    }

    return $text;
}

function syoka_extract_image_url( $item, $content, $description ) {
    $media_thumbnail = $item->get_item_tags( SIMPLEPIE_NAMESPACE_MEDIARSS, 'thumbnail' );
    if ( ! empty( $media_thumbnail[0]['attribs']['']['url'] ) ) {
        return esc_url_raw( $media_thumbnail[0]['attribs']['']['url'] );
    }

    $media_content = $item->get_item_tags( SIMPLEPIE_NAMESPACE_MEDIARSS, 'content' );
    if ( ! empty( $media_content[0]['attribs']['']['url'] ) ) {
        return esc_url_raw( $media_content[0]['attribs']['']['url'] );
    }

    $enclosure = $item->get_enclosure();
    if ( $enclosure && $enclosure->get_link() ) {
        return esc_url_raw( $enclosure->get_link() );
    }

    $html = '';
    if ( ! empty( $content ) ) {
        $html = $content;
    } elseif ( ! empty( $description ) ) {
        $html = $description;
    }

    if ( ! empty( $html ) ) {
        if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches ) ) {
            return esc_url_raw( $matches[1] );
        }
    }

    return '';
}

function syoka_clear_feed_cache( $feed_url ) {
    $feed_url = esc_url_raw( $feed_url );
    if ( empty( $feed_url ) ) {
        return;
    }
    delete_transient( 'syoka_feed_' . md5( $feed_url ) );
}
