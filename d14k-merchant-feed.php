<?php
/**
 * Plugin Name: GMC Feed for WooCommerce
 * Description: Generate Google Merchant Center product feeds with support for simple/variable products and WPML.
 * Version: 1.0.19
 * Author: MIL SPIL
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: d14k-merchant-feed
 */

if (!defined('ABSPATH')) {
    exit;
}

define('D14K_FEED_VERSION', '1.0.19');
define('D14K_FEED_PATH', plugin_dir_path(__FILE__));
define('D14K_FEED_URL', plugin_dir_url(__FILE__));

add_filter('cron_schedules', function ($schedules) {
    $schedules['d14k_every_3_hours'] = array(
        'interval' => 3 * HOUR_IN_SECONDS,
        'display' => 'Every 3 hours',
    );
    $schedules['d14k_every_6_hours'] = array(
        'interval' => 6 * HOUR_IN_SECONDS,
        'display' => 'Every 6 hours',
    );
    $schedules['d14k_every_12_hours'] = array(
        'interval' => 12 * HOUR_IN_SECONDS,
        'display' => 'Every 12 hours',
    );
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
    add_rewrite_rule(
        'merchant-feed/([a-z]{2})/?$',
        'index.php?d14k_feed=1&d14k_feed_lang=$matches[1]',
        'top'
    );
    add_rewrite_tag('%d14k_feed%', '1');
    add_rewrite_tag('%d14k_feed_lang%', '([a-z]{2})');
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
            echo '<div class="notice notice-error"><p><strong>GMC Feed for WooCommerce</strong> requires WooCommerce.</p></div>';
        });
        return;
    }

    require_once D14K_FEED_PATH . 'includes/class-wpml-handler.php';
    require_once D14K_FEED_PATH . 'includes/class-feed-validator.php';
    require_once D14K_FEED_PATH . 'includes/class-feed-generator.php';
    require_once D14K_FEED_PATH . 'includes/class-cron-manager.php';
    require_once D14K_FEED_PATH . 'includes/class-admin-settings.php';

    $wpml = new D14K_WPML_Handler();
    $validator = new D14K_Feed_Validator();
    $generator = new D14K_Feed_Generator($wpml, $validator);

    // Environment restriction hiding
    if (method_exists($generator, 'verify_environment') && !$generator->verify_environment()) {
        return;
    }

    $cron = new D14K_Cron_Manager($generator, $wpml);
    new D14K_Admin_Settings($generator, $wpml, $cron);


    add_action('template_redirect', function () use ($generator) {
        d14k_feed_handle_request($generator);
    });
}
add_action('plugins_loaded', 'd14k_feed_init', 20);

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
