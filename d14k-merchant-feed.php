<?php
/**
 * Plugin Name: MIL SPIL Feed Generator
 * Description: Генерація фідів для Google Merchant Center та українських маркетплейсів (Horoshop, Prom.ua, Rozetka). Підтримка WooCommerce, WPML, Custom Labels, YML/XML експорт. | Feed generator for GMC & UA marketplaces.
 * Version: 3.0.0
 * Author: MIL SPIL
 * Author URI: https://milspil.net
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: d14k-merchant-feed
 */

if (!defined('ABSPATH')) {
    exit;
}

define('D14K_FEED_VERSION', '3.0.0');
define('D14K_FEED_BASENAME', plugin_basename(__FILE__));
define('D14K_FEED_PATH', plugin_dir_path(__FILE__));
define('D14K_FEED_URL', plugin_dir_url(__FILE__));

add_filter('cron_schedules', function ($schedules) {
    $schedules['d14k_weekly'] = array(
        'interval' => 7 * DAY_IN_SECONDS,
        'display' => 'Once a week',
    );
    $schedules['d14k_monthly'] = array(
        'interval' => 30 * DAY_IN_SECONDS,
        'display' => 'Once a month',
    );
    return $schedules;
});

function d14k_feed_register_endpoint()
{
    // GMC feed endpoint: /merchant-feed/{lang}/
    add_rewrite_rule(
        'merchant-feed/([a-z]{2})/?$',
        'index.php?d14k_feed=1&d14k_feed_lang=$matches[1]',
        'top'
    );
    add_rewrite_tag('%d14k_feed%', '1');
    add_rewrite_tag('%d14k_feed_lang%', '([a-z]{2})');

    // Marketplace feed endpoint: /marketplace-feed/{channel}/
    add_rewrite_rule(
        'marketplace-feed/([a-z]+)/?$',
        'index.php?d14k_yml_feed=1&d14k_yml_channel=$matches[1]',
        'top'
    );
    add_rewrite_tag('%d14k_yml_feed%', '1');
    add_rewrite_tag('%d14k_yml_channel%', '([a-z]+)');
}
add_action('init', 'd14k_feed_register_endpoint');

function d14k_feed_activate()
{
    update_option('d14k_feed_flush_rewrite', 'yes');
    if (!wp_next_scheduled('d14k_feed_cron_hook')) {
        wp_schedule_event(time(), 'd14k_every_6_hours', 'd14k_feed_cron_hook');
    }
}
register_activation_hook(__FILE__, 'd14k_feed_activate');

function d14k_feed_maybe_flush()
{
    if (get_option('d14k_feed_flush_rewrite') === 'yes') {
        flush_rewrite_rules();
        delete_option('d14k_feed_flush_rewrite');
    }
}
add_action('init', 'd14k_feed_maybe_flush', 20);

function d14k_feed_deactivate()
{
    wp_clear_scheduled_hook('d14k_feed_cron_hook');
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'd14k_feed_deactivate');

function d14k_feed_init()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>MIL SPIL GMC Feed</strong> потребує WooCommerce для роботи.</p></div>';
        });
        return;
    }

    require_once D14K_FEED_PATH . 'includes/class-wpml-handler.php';
    require_once D14K_FEED_PATH . 'includes/class-feed-validator.php';
    require_once D14K_FEED_PATH . 'includes/class-feed-generator.php';
    require_once D14K_FEED_PATH . 'includes/class-yml-generator.php';
    require_once D14K_FEED_PATH . 'includes/class-cron-manager.php';
    require_once D14K_FEED_PATH . 'includes/class-admin-settings.php';
    require_once D14K_FEED_PATH . 'includes/class-product-meta.php';

    $wpml = new D14K_WPML_Handler();
    $validator = new D14K_Feed_Validator();
    $generator = new D14K_Feed_Generator($wpml, $validator);
    $yml_generator = new D14K_YML_Generator($wpml, $validator);

    // Environment restriction hiding
    if (method_exists($generator, 'verify_environment') && !$generator->verify_environment()) {
        return;
    }

    $cron = new D14K_Cron_Manager($generator, $yml_generator, $wpml);
    new D14K_Admin_Settings($generator, $yml_generator, $wpml, $cron);

    add_action('template_redirect', function () use ($generator, $yml_generator) {
        d14k_feed_handle_request($generator);
        d14k_yml_feed_handle_request($yml_generator);
    });
}
add_action('plugins_loaded', 'd14k_feed_init', 20);

// Settings link on Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_url = admin_url('admin.php?page=d14k-merchant-feed');
    array_unshift($links, '<a href="' . esc_url($settings_url) . '">Налаштування</a>');
    return $links;
});

function d14k_feed_handle_request($generator)
{
    $is_feed = get_query_var('d14k_feed');
    if (empty($is_feed)) {
        return;
    }
    $lang = sanitize_key(get_query_var('d14k_feed_lang', ''));
    if (empty($lang)) {
        status_header(404);
        exit('Feed language not specified.');
    }
    $feed_path = $generator->get_feed_path($lang);
    if (!file_exists($feed_path)) {
        $result = $generator->generate($lang);
        if (!$result) {
            status_header(500);
            exit('Feed generation failed.');
        }
    }
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    readfile($feed_path);
    exit;
}

/**
 * Handle marketplace YML feed requests.
 * URL pattern: /marketplace-feed/{channel}/
 * Each channel produces a single bilingual feed.
 */
function d14k_yml_feed_handle_request($yml_generator)
{
    $is_feed = get_query_var('d14k_yml_feed');
    if (empty($is_feed)) {
        return;
    }

    $channel = sanitize_key(get_query_var('d14k_yml_channel', ''));

    if (empty($channel)) {
        status_header(404);
        exit('Channel not specified.');
    }

    if (!in_array($channel, D14K_YML_Generator::CHANNELS, true)) {
        status_header(404);
        exit('Unknown marketplace channel.');
    }

    // Check if channel is enabled
    $settings = get_option('d14k_feed_settings', array());
    $channels = isset($settings['yml_channels']) ? $settings['yml_channels'] : array();
    if (empty($channels[$channel])) {
        status_header(403);
        exit('This feed channel is disabled.');
    }

    $feed_path = $yml_generator->get_feed_path($channel);
    if (!file_exists($feed_path)) {
        $result = $yml_generator->generate($channel);
        if (!$result) {
            status_header(500);
            exit('YML feed generation failed.');
        }
    }

    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    readfile($feed_path);
    exit;
}
