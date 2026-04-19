<?php
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this script via wp eval-file inside WordPress.\n");
    exit(1);
}

$settings = get_option('d14k_feed_settings', array());
$saved_feeds = isset($settings['supplier_feeds']) ? (array) $settings['supplier_feeds'] : array();
$enabled_feed = null;

foreach ($saved_feeds as $candidate) {
    if (!empty($candidate['url']) && !empty($candidate['enabled'])) {
        $enabled_feed = $candidate;
        break;
    }
}

if (!$enabled_feed) {
    fwrite(STDERR, "No enabled supplier feed found in d14k_feed_settings.\n");
    exit(2);
}

$supplier = new D14K_Supplier_Feeds();
$reflection = new ReflectionClass($supplier);

$resolve_requested_feeds = $reflection->getMethod('resolve_requested_feeds');
$resolve_requested_feeds->setAccessible(true);

$background_scope_match = $reflection->getMethod('background_state_matches_requested_scope');
$background_scope_match->setAccessible(true);

$resolved_from_url = $resolve_requested_feeds->invoke($supplier, array($enabled_feed['url']));
$resolved_from_array = $resolve_requested_feeds->invoke(
    $supplier,
    array(
        array(
            'url' => $enabled_feed['url'],
        ),
    )
);

$checks = array(
    'resolved_from_url_count' => is_array($resolved_from_url) && count($resolved_from_url) === 1,
    'resolved_from_url_same_url' => !empty($resolved_from_url[0]['url']) && $resolved_from_url[0]['url'] === $enabled_feed['url'],
    'resolved_from_url_enabled' => !empty($resolved_from_url[0]['enabled']),
    'resolved_from_array_count' => is_array($resolved_from_array) && count($resolved_from_array) === 1,
    'resolved_from_array_same_url' => !empty($resolved_from_array[0]['url']) && $resolved_from_array[0]['url'] === $enabled_feed['url'],
    'resolved_from_array_enabled' => !empty($resolved_from_array[0]['enabled']),
);

$state = array(
    'scope_feed_urls' => array($enabled_feed['url']),
    'max_items_per_feed' => 1,
    'selection_mode' => '',
);

$checks['scope_matches_url_request'] = (bool) $background_scope_match->invoke(
    $supplier,
    $state,
    $resolved_from_url,
    array(
        'max_items_per_feed' => 1,
        'selection_mode' => '',
    )
);

echo wp_json_encode(
    array(
        'feed_url' => $enabled_feed['url'],
        'checks' => $checks,
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . PHP_EOL;

foreach ($checks as $check_name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Supplier background assertion failed: {$check_name}\n");
        exit(3);
    }
}
