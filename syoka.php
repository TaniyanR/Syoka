<?php
/*
Plugin Name: Syoka
Description: RSS候補を確認して選んで下書き投稿できる、手動確定型の紹介記事ドラフト生成プラグイン（最大10サイト／各5件）
Version: 1.1.0
Author: TaniyanR
Text Domain: syoka
Requires at least: 6.0
Requires PHP: 8.1
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SYOKA_VERSION', '1.1.0' );
define( 'SYOKA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYOKA_FEEDS_OPTION', 'syoka_feeds' );
define( 'SYOKA_NOTICE_META', '_syoka_admin_notices' );
define( 'SYOKA_FEED_LIMIT', 10 );
define( 'SYOKA_ITEMS_PER_FEED', 5 );
define( 'SYOKA_CACHE_TTL', 10 * MINUTE_IN_SECONDS );
define( 'SYOKA_META_SOURCE_URL', '_syoka_source_url' );
define( 'SYOKA_META_SOURCE_GUID', '_syoka_source_guid' );

define(
    'SYOKA_IMAGE_PLACEHOLDER',
    '<div style="border:2px dashed #ccc; padding:12px; margin:0 0 16px; text-align:center; font-weight:700;">ここに画像が入ります（後で手動でアイキャッチを設定してください）</div>'
);

require_once SYOKA_PLUGIN_DIR . 'includes/feed.php';
require_once SYOKA_PLUGIN_DIR . 'includes/post.php';
require_once SYOKA_PLUGIN_DIR . 'includes/admin.php';

register_activation_hook( __FILE__, 'syoka_activate_plugin' );

function syoka_activate_plugin() {
    if ( false === get_option( SYOKA_FEEDS_OPTION, false ) ) {
        add_option( SYOKA_FEEDS_OPTION, array(), '', false );
    }
}
