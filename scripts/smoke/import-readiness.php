<?php
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this script via wp eval-file inside WordPress.\n");
    exit(1);
}

$checks = array(
    'class_prom_api'       => class_exists('D14K_Prom_API'),
    'class_prom_importer'  => class_exists('D14K_Prom_Importer'),
    'class_supplier_feeds' => class_exists('D14K_Supplier_Feeds'),
    'curl_available'       => function_exists('curl_init'),
    'simplexml_available'  => function_exists('simplexml_load_string'),
    'xmlreader_available'  => class_exists('XMLReader'),
    'method_import_page'   => method_exists('D14K_Prom_Importer', 'import_page'),
    'method_bg_start'      => method_exists('D14K_Supplier_Feeds', 'start_background_import'),
    'method_bg_status'     => method_exists('D14K_Supplier_Feeds', 'get_background_import_status'),
);

$settings = get_option('d14k_feed_settings', array());

$report = array(
    'checks' => $checks,
    'settings' => array(
        'has_prom_token'             => !empty($settings['prom_api_token']),
        'prom_import_enabled'        => !empty($settings['prom_import_enabled']),
        'prom_import_product_status' => $settings['prom_import_product_status'] ?? '',
        'prom_only_available'        => !empty($settings['prom_only_available']),
        'prom_only_with_images'      => !empty($settings['prom_only_with_images']),
    ),
);

echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Smoke check failed: {$name}\n");
        exit(2);
    }
}
