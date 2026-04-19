<?php
/**
 * Plugin Name: MIL SPIL Feed Generator
 * Description: Генерація фідів для Google Merchant Center та українських маркетплейсів (Horoshop, Prom.ua, Rozetka). Підтримка WooCommerce, WPML, Custom Labels, YML/XML експорт. | Feed generator for GMC & UA marketplaces.
 * Version: 4.0.2
 * Author: MIL SPIL
 * Author URI: https://milspil.net
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: d14k-merchant-feed
 */

if (!defined('ABSPATH')) {
    exit;
}

define('D14K_FEED_VERSION', '4.0.2');
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
        wp_schedule_event(time(), 'daily', 'd14k_feed_cron_hook');
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
    wp_clear_scheduled_hook('d14k_supplier_feeds_cron');
    wp_clear_scheduled_hook('d14k_supplier_feed_due');
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
    require_once D14K_FEED_PATH . 'includes/class-csv-generator.php';
    require_once D14K_FEED_PATH . 'includes/class-cron-manager.php';
    require_once D14K_FEED_PATH . 'includes/class-admin-settings.php';
    require_once D14K_FEED_PATH . 'includes/class-product-meta.php';
    // Prom.ua integration
    require_once D14K_FEED_PATH . 'includes/class-prom-api.php';
    require_once D14K_FEED_PATH . 'includes/class-prom-importer.php';
    require_once D14K_FEED_PATH . 'includes/class-prom-exporter.php';
    require_once D14K_FEED_PATH . 'includes/class-supplier-feeds.php';

    $wpml = new D14K_WPML_Handler();
    $validator = new D14K_Feed_Validator();
    $generator = new D14K_Feed_Generator($wpml, $validator);
    $yml_generator = new D14K_YML_Generator($wpml, $validator);
    $csv_generator = new D14K_CSV_Generator($wpml, $validator);

    // Prom.ua integration instances
    $prom_api       = new D14K_Prom_API();
    $prom_importer  = new D14K_Prom_Importer($prom_api);
    $prom_exporter  = new D14K_Prom_Exporter($prom_api, $yml_generator);
    $supplier_feeds = new D14K_Supplier_Feeds();

    // Environment restriction hiding
    /*if (method_exists($generator, 'verify_environment') && !$generator->verify_environment()) {
        return;
    }*/

    // Auto-flush rewrite rules on version update
    if (get_option('d14k_feed_db_version', '0') !== D14K_FEED_VERSION) {
        update_option('d14k_feed_flush_rewrite', 'yes');
        update_option('d14k_feed_db_version', D14K_FEED_VERSION);
    }

    $cron = new D14K_Cron_Manager($generator, $yml_generator, $csv_generator, $wpml, $prom_importer, $prom_exporter, $supplier_feeds);
    new D14K_Admin_Settings($generator, $yml_generator, $csv_generator, $wpml, $cron, $prom_api, $prom_importer, $prom_exporter, $supplier_feeds);

    // Використовуємо пріоритет 1, щоб перехопити запит ДО канонічного редиректу WordPress
    add_action('template_redirect', function () use ($generator, $yml_generator, $csv_generator) {
        d14k_feed_handle_request($generator);
        d14k_yml_feed_handle_request($yml_generator, $csv_generator);
    }, 1);

    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::add_command('d14k-feed generate-gmc', function ($args, $assoc_args) use ($generator, $wpml) {
            $lang = isset($args[0]) ? $args[0] : $wpml->get_default_language();
            WP_CLI::log("Generating GMC feed for language: $lang");
            if ($generator->generate($lang)) {
                WP_CLI::success("GMC feed generated.");
            } else {
                WP_CLI::error("Failed to generate GMC feed.");
            }
        });

        WP_CLI::add_command('d14k-feed generate-yml', function ($args, $assoc_args) use ($yml_generator) {
            $channel = isset($args[0]) ? $args[0] : '';
            if (empty($channel)) {
                WP_CLI::error("Please specify a channel (e.g. prom, rozetka).");
            }
            WP_CLI::log("Generating YML feed for channel: $channel");
            if ($yml_generator->generate($channel)) {
                WP_CLI::success("YML feed generated.");
            } else {
                WP_CLI::error("Failed to generate YML feed.");
            }
        });

        WP_CLI::add_command('d14k-feed generate-csv', function ($args, $assoc_args) use ($csv_generator) {
            $channel = isset($args[0]) ? $args[0] : 'horoshop';
            WP_CLI::log("Generating CSV feed for channel: $channel");
            if ($csv_generator->generate($channel)) {
                WP_CLI::success("CSV feed generated.");
            } else {
                WP_CLI::error("Failed to generate CSV feed.");
            }
        });
    }
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
function d14k_yml_feed_handle_request($yml_generator, $csv_generator = null)
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

    // Handle horoshop CSV
    if ($channel === 'horoshop') {
        if (!$csv_generator) {
            status_header(500);
            exit('CSV generator not initialized.');
        }

        // Check if channel is enabled
        $settings = get_option('d14k_feed_settings', array());
        $channels = isset($settings['yml_channels']) ? $settings['yml_channels'] : array();
        if (empty($channels[$channel])) {
            status_header(403);
            exit('This feed channel is disabled.');
        }

        $feed_path = $csv_generator->get_feed_path($channel);
        if (!file_exists($feed_path)) {
            $result = $csv_generator->generate($channel);
            if (!$result) {
                status_header(500);
                exit('CSV feed generation failed.');
            }
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        header('Content-Disposition: attachment; filename="feed-' . $channel . '.csv"');
        readfile($feed_path);
        exit;
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
