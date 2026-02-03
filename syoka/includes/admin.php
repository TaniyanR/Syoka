<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'syoka_register_admin_menu' );
add_action( 'admin_init', 'syoka_handle_admin_actions' );
add_action( 'admin_notices', 'syoka_render_admin_notices' );

function syoka_register_admin_menu() {
    add_menu_page(
        __( 'Syoka', 'syoka' ),
        __( 'Syoka', 'syoka' ),
        'manage_options',
        'syoka_feeds',
        'syoka_render_feeds_page',
        'dashicons-rss'
    );

    add_submenu_page(
        'syoka_feeds',
        __( 'RSSサイト管理', 'syoka' ),
        __( 'RSSサイト管理', 'syoka' ),
        'manage_options',
        'syoka_feeds',
        'syoka_render_feeds_page'
    );

    add_submenu_page(
        'syoka_feeds',
        __( '候補一覧', 'syoka' ),
        __( '候補一覧', 'syoka' ),
        'manage_options',
        'syoka_candidates',
        'syoka_render_candidates_page'
    );
}

function syoka_handle_admin_actions() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_POST['syoka_action'] ) && $_POST['syoka_action'] === 'save_feeds' ) {
        check_admin_referer( 'syoka_save_feeds' );
        syoka_handle_save_feeds();
        wp_safe_redirect( admin_url( 'admin.php?page=syoka_feeds' ) );
        exit;
    }

    if ( isset( $_GET['syoka_action'] ) && $_GET['syoka_action'] === 'refresh_feed' ) {
        check_admin_referer( 'syoka_refresh_feed' );
        $feed_url = isset( $_GET['feed_url'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['feed_url'] ) ) ) : '';
        if ( ! empty( $feed_url ) ) {
            syoka_clear_feed_cache( $feed_url );
            syoka_add_notice( 'success', __( 'キャッシュを削除して再取得します。', 'syoka' ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=syoka_candidates' ) );
        exit;
    }

    if ( isset( $_POST['syoka_action'] ) && $_POST['syoka_action'] === 'create_posts' ) {
        check_admin_referer( 'syoka_create_posts' );
        syoka_handle_create_posts();
        wp_safe_redirect( admin_url( 'admin.php?page=syoka_candidates' ) );
        exit;
    }
}

function syoka_get_feeds() {
    $feeds = get_option( SYOKA_FEEDS_OPTION, array() );
    if ( ! is_array( $feeds ) ) {
        $feeds = array();
    }
    return $feeds;
}

function syoka_save_feeds( $feeds ) {
    update_option( SYOKA_FEEDS_OPTION, $feeds );
}

function syoka_handle_save_feeds() {
    $urls = isset( $_POST['feed_url'] ) ? (array) wp_unslash( $_POST['feed_url'] ) : array();
    $actives = isset( $_POST['feed_active'] ) ? (array) wp_unslash( $_POST['feed_active'] ) : array();
    $deletes = isset( $_POST['feed_delete'] ) ? (array) wp_unslash( $_POST['feed_delete'] ) : array();

    $feeds = array();

    foreach ( $urls as $index => $url ) {
        $url = esc_url_raw( $url );
        if ( empty( $url ) ) {
            continue;
        }

        if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
            continue;
        }

        if ( isset( $deletes[ $index ] ) ) {
            continue;
        }

        $feeds[] = array(
            'url'    => $url,
            'active' => isset( $actives[ $index ] ) ? 1 : 0,
        );
    }

    $new_url = isset( $_POST['new_feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_feed_url'] ) ) : '';
    $new_active = isset( $_POST['new_feed_active'] ) ? 1 : 0;

    if ( ! empty( $new_url ) ) {
        if ( ! preg_match( '/^https?:\/\//i', $new_url ) ) {
            syoka_add_notice( 'error', __( '追加URLはhttp/httpsのみ有効です。', 'syoka' ) );
        } elseif ( count( $feeds ) >= SYOKA_FEED_LIMIT ) {
            syoka_add_notice( 'error', __( '登録できるRSSは最大10件までです。', 'syoka' ) );
        } else {
            $feeds[] = array(
                'url'    => $new_url,
                'active' => $new_active,
            );
        }
    }

    if ( count( $feeds ) > SYOKA_FEED_LIMIT ) {
        $feeds = array_slice( $feeds, 0, SYOKA_FEED_LIMIT );
        syoka_add_notice( 'error', __( 'RSSは最大10件までです。超過分は削除されました。', 'syoka' ) );
    }

    syoka_save_feeds( $feeds );
    syoka_add_notice( 'success', __( 'RSSサイト設定を保存しました。', 'syoka' ) );
}

function syoka_handle_create_posts() {
    $items = isset( $_POST['syoka_items'] ) ? (array) wp_unslash( $_POST['syoka_items'] ) : array();

    if ( empty( $items ) ) {
        syoka_add_notice( 'warning', __( '選択された記事がありません。', 'syoka' ) );
        return;
    }

    $selected = array();
    foreach ( $items as $encoded ) {
        $encoded = sanitize_text_field( $encoded );
        if ( '' === $encoded || false === strpos( $encoded, '|' ) ) {
            continue;
        }
        list( $feed_url, $hash ) = explode( '|', $encoded, 2 );
        $feed_url = esc_url_raw( rawurldecode( $feed_url ) );
        $hash = sanitize_text_field( $hash );
        if ( empty( $feed_url ) || empty( $hash ) ) {
            continue;
        }
        if ( ! isset( $selected[ $feed_url ] ) ) {
            $selected[ $feed_url ] = array();
        }
        $selected[ $feed_url ][] = $hash;
    }

    if ( empty( $selected ) ) {
        syoka_add_notice( 'warning', __( '選択された記事がありません。', 'syoka' ) );
        return;
    }

    foreach ( $selected as $feed_url => $hashes ) {
        $hashes = array_values( array_unique( $hashes ) );

        $feed_items = syoka_get_feed_items( $feed_url, true );
        if ( is_wp_error( $feed_items ) ) {
            syoka_add_notice(
                'error',
                esc_html( sprintf( __( 'RSS取得に失敗しました (%1$s): %2$s', 'syoka' ), $feed_url, $feed_items->get_error_message() ) )
            );
            continue;
        }

        $item_map = array();
        foreach ( $feed_items as $feed_item ) {
            if ( empty( $feed_item['item_hash'] ) ) {
                continue;
            }
            $item_map[ $feed_item['item_hash'] ] = $feed_item;
        }

        foreach ( $hashes as $hash ) {
            if ( ! isset( $item_map[ $hash ] ) ) {
                syoka_add_notice( 'error', __( '選択された記事が見つかりませんでした。', 'syoka' ) );
                continue;
            }

            $result = syoka_create_draft_post( $item_map[ $hash ] );
            if ( $result['status'] === 'success' ) {
                $edit_link = get_edit_post_link( $result['post_id'], '' );
                $message = __( '下書きを作成しました。', 'syoka' );
                if ( $edit_link ) {
                    $message .= ' <a href="' . esc_url( $edit_link ) . '">' . esc_html__( '編集', 'syoka' ) . '</a>';
                }
                syoka_add_notice( 'success', $message );
            } elseif ( $result['status'] === 'duplicate' ) {
                syoka_add_notice( 'warning', __( '既存投稿があるためスキップしました。', 'syoka' ) );
            } else {
                syoka_add_notice( 'error', sprintf( __( '作成に失敗しました: %s', 'syoka' ), esc_html( $result['message'] ) ) );
            }
        }
    }
}

function syoka_add_notice( $type, $message ) {
    $notices = get_option( SYOKA_NOTICE_OPTION, array() );
    if ( ! is_array( $notices ) ) {
        $notices = array();
    }
    $notices[] = array(
        'type'    => $type,
        'message' => $message,
    );
    update_option( SYOKA_NOTICE_OPTION, $notices );
}

function syoka_render_admin_notices() {
    $notices = get_option( SYOKA_NOTICE_OPTION, array() );
    if ( empty( $notices ) || ! is_array( $notices ) ) {
        return;
    }

    foreach ( $notices as $notice ) {
        $type = isset( $notice['type'] ) ? $notice['type'] : 'info';
        $message = isset( $notice['message'] ) ? $notice['message'] : '';

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr( $type ),
            wp_kses_post( $message )
        );
    }

    delete_option( SYOKA_NOTICE_OPTION );
}

function syoka_render_feeds_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $feeds = syoka_get_feeds();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'RSSサイト管理', 'syoka' ); ?></h1>
        <form method="post">
            <?php wp_nonce_field( 'syoka_save_feeds' ); ?>
            <input type="hidden" name="syoka_action" value="save_feeds">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__( '有効', 'syoka' ); ?></th>
                        <th><?php echo esc_html__( 'RSS URL', 'syoka' ); ?></th>
                        <th><?php echo esc_html__( '削除', 'syoka' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $feeds ) ) : ?>
                        <tr>
                            <td colspan="3"><?php echo esc_html__( '登録済みのRSSがありません。', 'syoka' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $feeds as $index => $feed ) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="feed_active[<?php echo esc_attr( $index ); ?>]" value="1" <?php checked( ! empty( $feed['active'] ) ); ?> />
                                </td>
                                <td>
                                    <input type="url" class="regular-text" name="feed_url[<?php echo esc_attr( $index ); ?>]" value="<?php echo esc_url( $feed['url'] ); ?>" required />
                                </td>
                                <td>
                                    <input type="checkbox" name="feed_delete[<?php echo esc_attr( $index ); ?>]" value="1" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'RSSを追加', 'syoka' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__( 'RSS URL', 'syoka' ); ?></th>
                    <td>
                        <input type="url" class="regular-text" name="new_feed_url" placeholder="https://example.com/feed" />
                        <label>
                            <input type="checkbox" name="new_feed_active" value="1" />
                            <?php echo esc_html__( '有効にする', 'syoka' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( '保存', 'syoka' ) ); ?>
        </form>
        <p><?php echo esc_html__( '登録できるRSSは最大10件です。', 'syoka' ); ?></p>
    </div>
    <?php
}

function syoka_render_candidates_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $feeds = syoka_get_feeds();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( '候補一覧', 'syoka' ); ?></h1>

        <form method="post">
            <?php wp_nonce_field( 'syoka_create_posts' ); ?>
            <input type="hidden" name="syoka_action" value="create_posts">

            <?php if ( empty( $feeds ) ) : ?>
                <p><?php echo esc_html__( 'RSSサイトが登録されていません。', 'syoka' ); ?></p>
            <?php else : ?>
                <?php foreach ( $feeds as $feed ) : ?>
                    <?php if ( empty( $feed['active'] ) ) : ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php
                    $items = syoka_get_feed_items( $feed['url'] );
                    if ( is_wp_error( $items ) ) {
                        printf(
                            '<div class="notice notice-error"><p>%s</p></div>',
                            esc_html( sprintf( __( 'RSS取得に失敗しました (%1$s): %2$s', 'syoka' ), $feed['url'], $items->get_error_message() ) )
                        );
                        $items = array();
                    }
                    ?>
                    <h2><?php echo esc_html( $feed['url'] ); ?></h2>
                    <?php
                    $refresh_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'page'         => 'syoka_candidates',
                                'syoka_action' => 'refresh_feed',
                                'feed_url'     => rawurlencode( $feed['url'] ),
                            ),
                            admin_url( 'admin.php' )
                        ),
                        'syoka_refresh_feed'
                    );
                    ?>
                    <p><a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php echo esc_html__( '更新（再取得）', 'syoka' ); ?></a></p>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__( '選択', 'syoka' ); ?></th>
                                <th><?php echo esc_html__( 'サムネイル', 'syoka' ); ?></th>
                                <th><?php echo esc_html__( 'タイトル', 'syoka' ); ?></th>
                                <th><?php echo esc_html__( '元URL', 'syoka' ); ?></th>
                                <th><?php echo esc_html__( '日付', 'syoka' ); ?></th>
                                <th><?php echo esc_html__( '状態', 'syoka' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $items ) ) : ?>
                                <tr>
                                    <td colspan="6"><?php echo esc_html__( '表示できる記事がありません。', 'syoka' ); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $items as $item ) : ?>
                                    <?php
                                    $is_duplicate = syoka_is_duplicate( $item['guid'], $item['link'] );
                                    $item_hash = isset( $item['item_hash'] ) ? $item['item_hash'] : '';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ( $is_duplicate || empty( $item_hash ) ) : ?>
                                                <input type="checkbox" disabled />
                                            <?php else : ?>
                                                <input type="checkbox" name="syoka_items[]" value="<?php echo esc_attr( rawurlencode( $feed['url'] ) . '|' . $item_hash ); ?>" />
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( ! empty( $item['image_url'] ) ) : ?>
                                                <img src="<?php echo esc_url( $item['image_url'] ); ?>" alt="" style="width:80px;height:auto;" />
                                            <?php else : ?>
                                                <?php echo esc_html__( 'なし', 'syoka' ); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( $item['title'] ); ?></td>
                                        <td><a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['link'] ); ?></a></td>
                                        <td><?php echo esc_html( $item['date'] ); ?></td>
                                        <td>
                                            <?php if ( $is_duplicate ) : ?>
                                                <span style="color:#999; font-weight:700;"><?php echo esc_html__( '投稿済み', 'syoka' ); ?></span>
                                            <?php else : ?>
                                                <?php echo esc_html__( '未投稿', 'syoka' ); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php submit_button( __( '選択した記事を下書きで作成', 'syoka' ) ); ?>
        </form>
    </div>
    <?php
}
