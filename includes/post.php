<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function syoka_is_duplicate( $guid, $link ) {
    $guid = sanitize_text_field( (string) $guid );
    $link = esc_url_raw( (string) $link );

    $queries = array();
    if ( '' !== $guid ) {
        $queries[] = array(
            'key'     => SYOKA_META_SOURCE_GUID,
            'value'   => $guid,
            'compare' => '=',
        );
    }
    if ( '' !== $link ) {
        $queries[] = array(
            'key'     => SYOKA_META_SOURCE_URL,
            'value'   => $link,
            'compare' => '=',
        );
    }

    if ( empty( $queries ) ) {
        return false;
    }

    if ( count( $queries ) > 1 ) {
        $queries = array_merge( array( 'relation' => 'OR' ), $queries );
    }

    $existing = get_posts(
        array(
            'post_type'              => 'post',
            'post_status'            => array( 'draft', 'publish', 'pending', 'future', 'private' ),
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'meta_query'             => $queries,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        )
    );

    return ! empty( $existing );
}

function syoka_create_draft_post( $item ) {
    if ( ! is_array( $item ) ) {
        return syoka_post_result_error( __( '記事データが不正です。', 'syoka' ) );
    }

    $guid      = isset( $item['guid'] ) ? sanitize_text_field( (string) $item['guid'] ) : '';
    $link      = isset( $item['link'] ) ? esc_url_raw( (string) $item['link'] ) : '';
    $feed_url  = isset( $item['feed_url'] ) ? esc_url_raw( (string) $item['feed_url'] ) : '';
    $title     = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
    $excerpt   = isset( $item['excerpt'] ) ? sanitize_textarea_field( (string) $item['excerpt'] ) : '';
    $image_url = isset( $item['image_url'] ) ? esc_url_raw( (string) $item['image_url'] ) : '';

    if ( ! syoka_is_http_url( $link ) ) {
        return syoka_post_result_error( __( '元記事URLが無効です。', 'syoka' ) );
    }

    if ( '' === $title ) {
        $title = __( '(タイトルなし)', 'syoka' );
    }

    if ( syoka_is_duplicate( $guid, $link ) ) {
        return array(
            'status'  => 'duplicate',
            'message' => __( 'すでに同じ記事が作成されています。', 'syoka' ),
        );
    }

    $content = syoka_build_post_content( $excerpt, $link, $feed_url, false );

    $post_id = wp_insert_post(
        array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ),
        true
    );

    if ( is_wp_error( $post_id ) ) {
        return syoka_post_result_error( $post_id->get_error_message() );
    }

    update_post_meta( $post_id, SYOKA_META_SOURCE_URL, $link );
    if ( '' !== $guid ) {
        update_post_meta( $post_id, SYOKA_META_SOURCE_GUID, $guid );
    }

    $image_set = false;
    if ( syoka_is_http_url( $image_url ) ) {
        $attachment_id = syoka_sideload_featured_image( $image_url, $post_id );
        if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
            $image_set = (bool) set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    if ( ! $image_set ) {
        update_post_meta( $post_id, '_syoka_image_missing', 1 );
        wp_update_post(
            array(
                'ID'           => $post_id,
                'post_content' => syoka_build_post_content( $excerpt, $link, $feed_url, true ),
            )
        );
    } else {
        delete_post_meta( $post_id, '_syoka_image_missing' );
    }

    return array(
        'status'  => 'success',
        'message' => __( '下書きを作成しました。', 'syoka' ),
        'post_id' => (int) $post_id,
        'image'   => $image_set ? 'set' : 'missing',
    );
}

function syoka_build_post_content( $excerpt, $link, $feed_url, $show_placeholder ) {
    $parts = array();

    if ( $show_placeholder ) {
        $parts[] = SYOKA_IMAGE_PLACEHOLDER;
    }

    if ( '' !== $excerpt ) {
        $parts[] = '<p>' . esc_html( $excerpt ) . '</p>';
    }

    $parts[] = sprintf(
        '<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
        esc_url( $link ),
        esc_html__( '元記事を読む', 'syoka' )
    );

    $source_url = syoka_is_http_url( $feed_url ) ? $feed_url : $link;
    $parts[] = sprintf(
        '<p>%1$s: <a href="%2$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
        esc_html__( '出典', 'syoka' ),
        esc_url( $source_url )
    );

    return implode( "\n\n", $parts );
}

function syoka_sideload_featured_image( $image_url, $post_id ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    return media_sideload_image( $image_url, $post_id, null, 'id' );
}

function syoka_post_result_error( $message ) {
    return array(
        'status'  => 'error',
        'message' => (string) $message,
    );
}
