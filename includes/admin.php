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
        'dashicons-rss',
        66
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
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $post_action = isset( $_POST['syoka_action'] ) ? sanitize_key( wp_unslash( $_POST['syoka_action'] ) ) : '';
    $get_action  = isset( $_GET['syoka_action'] ) ? sanitize_key( wp_unslash( $_GET['syoka_action'] ) ) : '';

    if ( 'save_feeds' === $post_action ) {
        check_admin_referer( 'syoka_save_feeds' );
        syoka_handle_save_feeds();
        wp_safe_redirect( admin_url( 'admin.php?page=syoka_feeds' ) );
        exit;
    }

    if ( 'create_posts' === $post_action ) {
        check_admin_referer( 'syoka_create_posts' );
        syoka_handle_create_posts();
        wp_safe_redirect( admin_url( 'admin.php?page=syoka_candidates' ) );
        exit;
    }

    if ( 'refresh_feed' === $get_action ) {
        check_admin_referer( 'syoka_refresh_feed' );
        syoka_handle_refresh_feed();
        wp_safe_redirect( admin_url( 'admin.php?page=syoka_candidates' ) );
        exit;
    }
}

function syoka_get_feeds() {
    $stored = get_option( SYOKA_FEEDS_OPTION, array() );
    if ( ! is_array( $stored ) ) {
        return array();
    }

    $feeds = array();
    foreach ( $stored as $feed ) {
        if ( ! is_array( $feed ) ) {
            continue;
        }

        $url = isset( $feed['url'] ) ? esc_url_raw( (string) $feed['url'] ) : '';
        if ( ! syoka_is_http_url( $url ) ) {
            continue;
        }

        $feeds[] = array(
            'url'    => $url,
            'active' => ! empty( $feed['active'] ) ? 1 : 0,
        );

        if ( count( $feeds ) >= SYOKA_FEED_LIMIT ) {
            break;
        }
    }

    return $feeds;
}

function syoka_save_feeds( $feeds ) {
    update_option( SYOKA_FEEDS_OPTION, array_values( $feeds ), false );
}

function syoka_handle_save_feeds() {
    $urls    = isset( $_POST['feed_url'] ) ? (array) wp_unslash( $_POST['feed_url'] ) : array();
    $actives = isset( $_POST['feed_active'] ) ? (array) wp_unslash( $_POST['feed_active'] ) : array();
    $deletes = isset( $_POST['feed_delete'] ) ? (array) wp_unslash( $_POST['feed_delete'] ) : array();

    $feeds = array();
    $seen  = array();

    foreach ( $urls as $index => $raw_url ) {
        if ( isset( $deletes[ $index ] ) ) {
            continue;
        }

        $url = esc_url_raw( (string) $raw_url );
        if ( ! syoka_is_http_url( $url ) ) {
            syoka_add_notice( 'warning', __( '無効なRSS URLを1件スキップしました。', 'syoka' ) );
            continue;
        }

        $key = strtolower( untrailingslashit( $url ) );
        if ( isset( $seen[ $key ] ) ) {
            syoka_add_notice( 'warning', __( '重複しているRSS URLを1件スキップしました。', 'syoka' ) );
            continue;
        }

        $seen[ $key ] = true;
        $feeds[] = array(
            'url'    => $url,
            'active' => isset( $actives[ $index ] ) ? 1 : 0,
        );
    }

    $new_url = isset( $_POST['new_feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_feed_url'] ) ) : '';
    if ( '' !== $new_url ) {
        if ( ! syoka_is_http_url( $new_url ) ) {
            syoka_add_notice( 'error', __( '追加するRSS URLはhttp/httpsで指定してください。', 'syoka' ) );
        } elseif ( count( $feeds ) >= SYOKA_FEED_LIMIT ) {
            syoka_add_notice( 'error', __( '登録できるRSSは最大10件までです。', 'syoka' ) );
        } else {
            $key = strtolower( untrailingslashit( $new_url ) );
            if ( isset( $seen[ $key ] ) ) {
                syoka_add_notice( 'warning', __( '同じRSS URLはすでに登録されています。', 'syoka' ) );
            } else {
                $feeds[] = array(
                    'url'    => $new_url,
                    'active' => isset( $_POST['new_feed_active'] ) ? 1 : 0,
                );
            }
        }
    }

    if ( count( $feeds ) > SYOKA_FEED_LIMIT ) {
        $feeds = array_slice( $feeds, 0, SYOKA_FEED_LIMIT );
        syoka_add_notice( 'warning', __( '10件を超えたRSSは保存しませんでした。', 'syoka' ) );
    }

    syoka_save_feeds( $feeds );
    syoka_add_notice( 'success', __( 'RSSサイト設定を保存しました。', 'syoka' ) );
}

function syoka_handle_refresh_feed() {
    $index = isset( $_GET['feed_index'] ) ? absint( $_GET['feed_index'] ) : -1;
    $feeds = syoka_get_feeds();

    if ( ! isset( $feeds[ $index ] ) || empty( $feeds[ $index ]['active'] ) ) {
        syoka_add_notice( 'error', __( '更新対象のRSSが見つかりません。', 'syoka' ) );
        return;
    }

    syoka_clear_feed_cache( $feeds[ $index ]['url'] );
    syoka_add_notice( 'success', __( 'RSSキャッシュを削除しました。最新候補を再取得します。', 'syoka' ) );
}

function syoka_handle_create_posts() {
    $submitted = isset( $_POST['syoka_items'] ) ? (array) wp_unslash( $_POST['syoka_items'] ) : array();
    if ( empty( $submitted ) ) {
        syoka_add_notice( 'warning', __( '選択された記事がありません。', 'syoka' ) );
        return;
    }

    $feeds    = syoka_get_feeds();
    $selected = array();

    foreach ( $submitted as $value ) {
        $value = sanitize_text_field( (string) $value );
        if ( ! preg_match( '/^(\d+)\|([a-f0-9]{64})$/', $value, $matches ) ) {
            continue;
        }

        $feed_index = absint( $matches[1] );
        $item_hash  = $matches[2];

        if ( ! isset( $feeds[ $feed_index ] ) || empty( $feeds[ $feed_index ]['active'] ) ) {
            continue;
        }

        if ( ! isset( $selected[ $feed_index ] ) ) {
            $selected[ $feed_index ] = array();
        }
        $selected[ $feed_index ][] = $item_hash;
    }

    if ( empty( $selected ) ) {
        syoka_add_notice( 'warning', __( '有効な選択記事がありません。', 'syoka' ) );
        return;
    }

    foreach ( $selected as $feed_index => $hashes ) {
        $feed_url = $feeds[ $feed_index ]['url'];
        $items    = syoka_get_feed_items( $feed_url );

        if ( is_wp_error( $items ) ) {
            syoka_add_notice(
                'error',
                sprintf(
                    __( 'RSS取得に失敗しました（%1$s）: %2$s', 'syoka' ),
                    esc_html( $feed_url ),
                    esc_html( $items->get_error_message() )
                )
            );
            continue;
        }

        $item_map = array();
        foreach ( $items as $item ) {
            if ( ! empty( $item['item_hash'] ) ) {
                $item_map[ $item['item_hash'] ] = $item;
            }
        }

        foreach ( array_unique( $hashes ) as $hash ) {
            if ( ! isset( $item_map[ $hash ] ) ) {
                syoka_add_notice( 'warning', __( '選択した候補が更新されたため見つかりませんでした。候補一覧を再確認してください。', 'syoka' ) );
                continue;
            }

            $result = syoka_create_draft_post( $item_map[ $hash ] );
            syoka_add_create_result_notice( $result, $item_map[ $hash ] );
        }
    }
}

function syoka_add_create_result_notice( $result, $item ) {
    $title = isset( $item['title'] ) ? (string) $item['title'] : __( '(タイトルなし)', 'syoka' );

    if ( isset( $result['status'] ) && 'success' === $result['status'] ) {
        $edit_link = get_edit_post_link( $result['post_id'], '' );
        $message   = sprintf( __( '「%s」を下書き作成しました。', 'syoka' ), esc_html( $title ) );
        if ( $edit_link ) {
            $message .= ' <a href="' . esc_url( $edit_link ) . '">' . esc_html__( '編集する', 'syoka' ) . '</a>';
        }
        if ( isset( $result['image'] ) && 'missing' === $result['image'] ) {
            $message .= ' ' . esc_html__( '画像は取得できなかったため、本文に画像挿入用の目印を追加しました。', 'syoka' );
        }
        syoka_add_notice( 'success', $message );
        return;
    }

    if ( isset( $result['status'] ) && 'duplicate' === $result['status'] ) {
        syoka_add_notice( 'warning', sprintf( __( '「%s」は投稿済みのためスキップしました。', 'syoka' ), esc_html( $title ) ) );
        return;
    }

    $message = isset( $result['message'] ) ? (string) $result['message'] : __( '不明なエラーです。', 'syoka' );
    syoka_add_notice( 'error', sprintf( __( '「%1$s」の作成に失敗しました: %2$s', 'syoka' ), esc_html( $title ), esc_html( $message ) ) );
}

function syoka_add_notice( $type, $message ) {
    $allowed = array( 'success', 'error', 'warning', 'info' );
    if ( ! in_array( $type, $allowed, true ) ) {
        $type = 'info';
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return;
    }

    $notices = get_user_meta( $user_id, SYOKA_NOTICE_META, true );
    if ( ! is_array( $notices ) ) {
        $notices = array();
    }

    $notices[] = array(
        'type'    => $type,
        'message' => (string) $message,
    );

    update_user_meta( $user_id, SYOKA_NOTICE_META, array_slice( $notices, -30 ) );
}

function syoka_render_admin_notices() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return;
    }

    $notices = get_user_meta( $user_id, SYOKA_NOTICE_META, true );
    if ( empty( $notices ) || ! is_array( $notices ) ) {
        return;
    }

    delete_user_meta( $user_id, SYOKA_NOTICE_META );

    foreach ( $notices as $notice ) {
        $type    = isset( $notice['type'] ) ? (string) $notice['type'] : 'info';
        $message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr( $type ),
            wp_kses_post( $message )
        );
    }
}

function syoka_render_feeds_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'このページを表示する権限がありません。', 'syoka' ) );
    }

    $feeds = syoka_get_feeds();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Syoka - RSSサイト管理', 'syoka' ); ?></h1>
        <p><?php echo esc_html__( 'RSSを最大10サイト登録できます。有効にしたRSSだけが候補一覧に表示されます。', 'syoka' ); ?></p>

        <form method="post">
            <?php wp_nonce_field( 'syoka_save_feeds' ); ?>
            <input type="hidden" name="syoka_action" value="save_feeds" />

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:80px;"><?php echo esc_html__( '有効', 'syoka' ); ?></th>
                        <th><?php echo esc_html__( 'RSS URL', 'syoka' ); ?></th>
                        <th style="width:80px;"><?php echo esc_html__( '削除', 'syoka' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $feeds ) ) : ?>
                        <tr><td colspan="3"><?php echo esc_html__( '登録済みのRSSはありません。', 'syoka' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $feeds as $index => $feed ) : ?>
                            <tr>
                                <td><input type="checkbox" name="feed_active[<?php echo esc_attr( $index ); ?>]" value="1" <?php checked( ! empty( $feed['active'] ) ); ?> /></td>
                                <td><input type="url" class="large-text" name="feed_url[<?php echo esc_attr( $index ); ?>]" value="<?php echo esc_url( $feed['url'] ); ?>" required /></td>
                                <td><input type="checkbox" name="feed_delete[<?php echo esc_attr( $index ); ?>]" value="1" /></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'RSSを追加', 'syoka' ); ?></h2>
            <?php if ( count( $feeds ) >= SYOKA_FEED_LIMIT ) : ?>
                <p><?php echo esc_html__( '登録上限の10件に達しています。追加する場合は既存RSSを削除してください。', 'syoka' ); ?></p>
            <?php else : ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="syoka-new-feed-url"><?php echo esc_html__( 'RSS URL', 'syoka' ); ?></label></th>
                        <td>
                            <input id="syoka-new-feed-url" type="url" class="large-text" name="new_feed_url" placeholder="https://example.com/feed/" />
                            <label><input type="checkbox" name="new_feed_active" value="1" checked /> <?php echo esc_html__( '有効にする', 'syoka' ); ?></label>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>

            <?php submit_button( __( '保存', 'syoka' ) ); ?>
        </form>
    </div>
    <?php
}

function syoka_render_candidates_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'このページを表示する権限がありません。', 'syoka' ) );
    }

    $feeds = syoka_get_feeds();
    $has_active_feed = false;
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Syoka - 候補一覧', 'syoka' ); ?></h1>
        <p><?php echo esc_html__( '各RSSの最新5件から、紹介記事にしたいものを選択してください。投稿は必ず下書きで作成されます。', 'syoka' ); ?></p>

        <form method="post">
            <?php wp_nonce_field( 'syoka_create_posts' ); ?>
            <input type="hidden" name="syoka_action" value="create_posts" />

            <?php foreach ( $feeds as $feed_index => $feed ) : ?>
                <?php if ( empty( $feed['active'] ) ) { continue; } ?>
                <?php
                $has_active_feed = true;
                $items = syoka_get_feed_items( $feed['url'] );
                $refresh_url = wp_nonce_url(
                    add_query_arg(
                        array(
                            'page'         => 'syoka_candidates',
                            'syoka_action' => 'refresh_feed',
                            'feed_index'   => $feed_index,
                        ),
                        admin_url( 'admin.php' )
                    ),
                    'syoka_refresh_feed'
                );
                ?>

                <hr />
                <h2><?php echo esc_html( $feed['url'] ); ?></h2>
                <p><a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php echo esc_html__( '更新（再取得）', 'syoka' ); ?></a></p>

                <?php if ( is_wp_error( $items ) ) : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html( sprintf( __( 'RSS取得に失敗しました: %s', 'syoka' ), $items->get_error_message() ) ); ?></p></div>
                    <?php continue; ?>
                <?php endif; ?>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:60px;"><?php echo esc_html__( '選択', 'syoka' ); ?></th>
                            <th style="width:100px;"><?php echo esc_html__( '画像', 'syoka' ); ?></th>
                            <th><?php echo esc_html__( 'タイトル', 'syoka' ); ?></th>
                            <th><?php echo esc_html__( '元記事', 'syoka' ); ?></th>
                            <th style="width:140px;"><?php echo esc_html__( '日付', 'syoka' ); ?></th>
                            <th style="width:90px;"><?php echo esc_html__( '状態', 'syoka' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $items ) ) : ?>
                            <tr><td colspan="6"><?php echo esc_html__( '表示できる候補がありません。', 'syoka' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $items as $item ) : ?>
                                <?php
                                $duplicate = syoka_is_duplicate( $item['guid'], $item['link'] );
                                $hash      = isset( $item['item_hash'] ) ? (string) $item['item_hash'] : '';
                                ?>
                                <tr>
                                    <td>
                                        <?php if ( $duplicate || '' === $hash ) : ?>
                                            <input type="checkbox" disabled />
                                        <?php else : ?>
                                            <input type="checkbox" name="syoka_items[]" value="<?php echo esc_attr( $feed_index . '|' . $hash ); ?>" />
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( ! empty( $item['image_url'] ) ) : ?>
                                            <img src="<?php echo esc_url( $item['image_url'] ); ?>" alt="" width="80" loading="lazy" style="height:auto;max-width:80px;" />
                                        <?php else : ?>
                                            <span><?php echo esc_html__( 'なし', 'syoka' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $item['title'] ); ?></td>
                                    <td><a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( '確認', 'syoka' ); ?></a></td>
                                    <td><?php echo esc_html( $item['date'] ? $item['date'] : '-' ); ?></td>
                                    <td><?php echo $duplicate ? '<strong>' . esc_html__( '投稿済み', 'syoka' ) . '</strong>' : esc_html__( '未投稿', 'syoka' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <?php if ( ! $has_active_feed ) : ?>
                <p><?php echo esc_html__( '有効なRSSがありません。RSSサイト管理でRSSを登録・有効化してください。', 'syoka' ); ?></p>
            <?php else : ?>
                <?php submit_button( __( '選択した記事を下書きで作成', 'syoka' ), 'primary' ); ?>
            <?php endif; ?>
        </form>
    </div>
    <?php
}
