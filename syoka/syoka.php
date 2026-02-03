<?php
/**
 * Plugin Name: Syoka
 * Description: RSS候補から紹介記事の下書きを作成するプラグイン。
 * Version: 1.0.0
 * Author: Syoka
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SYOKA_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once SYOKA_PLUGIN_DIR . 'includes/admin.php';
require_once SYOKA_PLUGIN_DIR . 'includes/feed.php';
require_once SYOKA_PLUGIN_DIR . 'includes/post.php';

function syoka_activate(): void
{
    if (get_option('syoka_rss_sites') === false) {
        add_option('syoka_rss_sites', []);
    }
}
register_activation_hook(__FILE__, 'syoka_activate');

function syoka_admin_menu(): void
{
    add_menu_page(
        'Syoka',
        'Syoka',
        'manage_options',
        'syoka',
        'syoka_render_sites_page',
        'dashicons-rss',
        60
    );

    add_submenu_page(
        'syoka',
        'RSSサイト管理',
        'RSSサイト管理',
        'manage_options',
        'syoka',
        'syoka_render_sites_page'
    );

    add_submenu_page(
        'syoka',
        '候補一覧',
        '候補一覧',
        'manage_options',
        'syoka-candidates',
        'syoka_render_candidates_page'
    );
}
add_action('admin_menu', 'syoka_admin_menu');
