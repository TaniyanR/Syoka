<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function syoka_get_feed_items( $feed_url, $force_refresh = false ) {
    $feed_url = esc_url_raw( (string) $feed_url );
    if ( ! syoka_is_http_url( $feed_url ) ) {
        return new WP_Error( 'syoka_invalid_feed_url', __( 'RSS URLが無効です。', 'syoka' ) );
    }

    $cache_key = syoka_get_feed_cache_key( $feed_url );
    if ( $force_refresh ) {
        delete_transient( $cache_key );
    }

    $cached = get_transient( $cache_key );
    if ( false !== $cached && is_array( $cached ) ) {
        return $cached;
    }

    require_once ABSPATH . WPINC . '/feed.php';

    $feed = fetch_feed( $feed_url );
    if ( is_wp_error( $feed ) ) {
        return $feed;
    }

    $items = $feed->get_items( 0, SYOKA_ITEMS_PER_FEED );
    if ( ! is_array( $items ) ) {
        $items = array();
    }

    $normalized = array();
    foreach ( $items as $item ) {
        $guid        = trim( (string) $item->get_id() );
        $link        = esc_url_raw( (string) $item->get_link() );
        $title       = trim( wp_strip_all_tags( (string) $item->get_title() ) );
        $date        = (string) $item->get_date( 'Y-m-d H:i' );
        $content     = (string) $item->get_content();
        $description = (string) $item->get_description();

        if ( ! syoka_is_http_url( $link ) ) {
            continue;
        }

        if ( '' === $title ) {
            $title = __( '(タイトルなし)', 'syoka' );
        }

        $normalized[] = array(
            'guid'      => sanitize_text_field( $guid ),
            'link'      => $link,
            'title'     => $title,
            'date'      => $date,
            'excerpt'   => syoka_build_excerpt( $description, $content ),
            'image_url' => syoka_extract_image_url( $item, $content, $description ),
            'feed_url'  => $feed_url,
            'item_hash' => syoka_generate_item_hash( $guid, $link ),
        );
    }

    set_transient( $cache_key, $normalized, SYOKA_CACHE_TTL );

    return $normalized;
}

function syoka_get_feed_cache_key( $feed_url ) {
    return 'syoka_feed_' . md5( (string) $feed_url );
}

function syoka_clear_feed_cache( $feed_url ) {
    $feed_url = esc_url_raw( (string) $feed_url );
    if ( syoka_is_http_url( $feed_url ) ) {
        delete_transient( syoka_get_feed_cache_key( $feed_url ) );
    }
}

function syoka_generate_item_hash( $guid, $link ) {
    $identity = '' !== trim( (string) $guid ) ? trim( (string) $guid ) : trim( (string) $link );
    return hash( 'sha256', $identity );
}

function syoka_build_excerpt( $description, $content ) {
    $text = '' !== trim( (string) $description ) ? (string) $description : (string) $content;
    $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, get_bloginfo( 'charset' ) );
    $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

    if ( '' === $text ) {
        return '';
    }

    if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 240 ) {
        return mb_substr( $text, 0, 240 ) . '…';
    }

    if ( ! function_exists( 'mb_strlen' ) && strlen( $text ) > 240 ) {
        return substr( $text, 0, 240 ) . '…';
    }

    return $text;
}

function syoka_extract_image_url( $item, $content, $description ) {
    $thumbnail = $item->get_item_tags( SIMPLEPIE_NAMESPACE_MEDIARSS, 'thumbnail' );
    if ( ! empty( $thumbnail[0]['attribs']['']['url'] ) ) {
        $url = syoka_normalize_image_url( $thumbnail[0]['attribs']['']['url'] );
        if ( $url ) {
            return $url;
        }
    }

    $media = $item->get_item_tags( SIMPLEPIE_NAMESPACE_MEDIARSS, 'content' );
    if ( ! empty( $media ) ) {
        foreach ( $media as $entry ) {
            if ( empty( $entry['attribs']['']['url'] ) ) {
                continue;
            }
            $type = isset( $entry['attribs']['']['type'] ) ? (string) $entry['attribs']['']['type'] : '';
            if ( '' !== $type && 0 !== stripos( $type, 'image/' ) ) {
                continue;
            }
            $url = syoka_normalize_image_url( $entry['attribs']['']['url'] );
            if ( $url ) {
                return $url;
            }
        }
    }

    $enclosure = $item->get_enclosure();
    if ( $enclosure && $enclosure->get_link() ) {
        $type = (string) $enclosure->get_type();
        if ( '' === $type || 0 === stripos( $type, 'image/' ) ) {
            $url = syoka_normalize_image_url( $enclosure->get_link() );
            if ( $url ) {
                return $url;
            }
        }
    }

    foreach ( array( (string) $content, (string) $description ) as $html ) {
        if ( '' === trim( $html ) ) {
            continue;
        }

        if ( preg_match( '/<img[^>]+(?:src|data-src)=["\']([^"\']+)["\']/i', $html, $matches ) ) {
            $url = syoka_normalize_image_url( $matches[1] );
            if ( $url ) {
                return $url;
            }
        }
    }

    return '';
}

function syoka_normalize_image_url( $url ) {
    $url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
    $url = esc_url_raw( $url );
    return syoka_is_http_url( $url ) ? $url : '';
}

function syoka_is_http_url( $url ) {
    if ( ! is_string( $url ) || '' === $url ) {
        return false;
    }

    $parts = wp_parse_url( $url );
    if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
        return false;
    }

    return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
}
