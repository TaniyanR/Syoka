<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function syoka_is_duplicate( $guid, $link ) {
    $meta_query = array( 'relation' => 'OR' );

    if ( ! empty( $guid ) ) {
        $meta_query[] = array(
            'key'   => '_syoka_source_guid',
            'value' => $guid,
        );
    }

    if ( ! empty( $link ) ) {
        $meta_query[] = array(
            'key'   => '_syoka_source_url',
            'value' => $link,
        );
    }

    if ( count( $meta_query ) === 1 ) {
        return false;
    }

    $existing = get_posts(
        array(
            'post_type'      => 'post',
            'post_status'    => array( 'draft', 'publish', 'pending', 'future', 'private' ),
            'posts_per_page' => 1,
            'meta_query'     => $meta_query,
            'fields'         => 'ids',
        )
    );

    return ! empty( $existing );
}

function syoka_create_draft_post( $item ) {
    $guid = isset( $item['guid'] ) ? sanitize_text_field( $item['guid'] ) : '';
    $link = isset( $item['link'] ) ? esc_url_raw( $item['link'] ) : '';

    if ( syoka_is_duplicate( $guid, $link ) ) {
        return array(
            'status'  => 'duplicate',
            'message' => __( 'Duplicate item skipped.', 'syoka' ),
        );
    }

    $title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
    $excerpt = isset( $item['excerpt'] ) ? wp_kses_post( $item['excerpt'] ) : '';

    $content_parts = array();
    if ( ! empty( $excerpt ) ) {
        $content_parts[] = $excerpt;
    }

    if ( ! empty( $link ) ) {
        $content_parts[] = sprintf(
            '<p><a href="%1$s" target="_blank" rel="noopener">%2$s</a></p>',
            esc_url( $link ),
            esc_html__( '元記事はこちら', 'syoka' )
        );
        $content_parts[] = sprintf(
            '<p>%1$s: <a href="%2$s" target="_blank" rel="noopener">%2$s</a></p>',
            esc_html__( '出典', 'syoka' ),
            esc_url( $link )
        );
    }

    $content = implode( "\n", $content_parts );

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
        return array(
            'status'  => 'error',
            'message' => $post_id->get_error_message(),
        );
    }

    update_post_meta( $post_id, '_syoka_source_url', $link );
    update_post_meta( $post_id, '_syoka_source_guid', $guid );

    $image_url = isset( $item['image_url'] ) ? esc_url_raw( $item['image_url'] ) : '';
    $image_set = false;

    if ( ! empty( $image_url ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image( $image_url, $post_id, null, 'id' );
        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
            $image_set = true;
        }
    }

    if ( ! $image_set ) {
        $placeholder = '<div style="border:2px dashed #ccc; padding:12px; margin:0 0 16px; text-align:center; font-weight:700;">ここに画像が入ります（後で手動でアイキャッチを設定してください）</div>';
        $updated_content = $placeholder . "\n" . get_post_field( 'post_content', $post_id );
        wp_update_post(
            array(
                'ID'           => $post_id,
                'post_content' => $updated_content,
            )
        );
    }

    return array(
        'status'  => 'success',
        'message' => __( 'Draft created.', 'syoka' ),
        'post_id' => $post_id,
    );
}
