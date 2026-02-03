<?php
/*
Plugin Name: Syoka
Description: RSS候補を確認して選んで下書き投稿できる、手動確定型の紹介記事ドラフト生成プラグイン（最大10サイト／各5件）
Version: 1.0.0
Author: TaniyanR
Text Domain: syoka
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SYOKA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYOKA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'SYOKA_FEEDS_OPTION', 'syoka_feeds' );
define( 'SYOKA_NOTICE_OPTION', 'syoka_admin_notices' );

define( 'SYOKA_FEED_LIMIT', 10 );
define( 'SYOKA_ITEMS_PER_FEED', 5 );
define( 'SYOKA_CACHE_TTL', 10 * MINUTE_IN_SECONDS );

require_once SYOKA_PLUGIN_DIR . 'includes/feed.php';
require_once SYOKA_PLUGIN_DIR . 'includes/post.php';
require_once SYOKA_PLUGIN_DIR . 'includes/admin.php';

register_activation_hook( __FILE__, 'syoka_activate_plugin' );

function syoka_activate_plugin() {
    if ( ! get_option( SYOKA_FEEDS_OPTION ) ) {
        add_option( SYOKA_FEEDS_OPTION, array() );
    }
}
