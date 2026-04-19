<?php
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this script via wp eval-file inside WordPress.\n");
    exit(1);
}

$url = trim((string) getenv('D14K_SUPPLIER_FEED_URL'));
$max_items = max(0, (int) getenv('D14K_SUPPLIER_MAX_ITEMS'));
$batch_size = max(1, (int) (getenv('D14K_SUPPLIER_BATCH_SIZE') ?: 5));
$selection_mode = (string) (getenv('D14K_SUPPLIER_SELECTION_MODE') ?: '');

if ($url === '') {
    fwrite(STDERR, "Set D14K_SUPPLIER_FEED_URL=https://...\n");
    exit(1);
}

$supplier = new D14K_Supplier_Feeds();
$state = $supplier->start_background_import(
    $batch_size,
    true,
    array($url),
    true,
    array(
        'max_items_per_feed' => $max_items,
        'selection_mode' => $selection_mode,
    )
);

echo "START\n";

if (is_wp_error($state)) {
    echo wp_json_encode(
        array(
            'error' => $state->get_error_message(),
        ),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;
    exit(2);
}

echo wp_json_encode(
    array(
        'status' => $state['status'] ?? null,
        'current_stage' => $state['current_stage'] ?? null,
        'action_id' => $state['action_id'] ?? null,
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . PHP_EOL;

$supplier->handle_background_import_step();
$status = $supplier->get_background_import_status();

echo "AFTER\n";
echo wp_json_encode(
    array(
        'status' => $status['status'] ?? null,
        'current_stage' => $status['current_stage'] ?? null,
        'current_feed_url' => $status['current_feed_url'] ?? null,
        'last_batch' => $status['last_batch'] ?? null,
        'result_errors' => $status['results'][$url]['errors'] ?? array(),
        'offers_total' => $status['results'][$url]['offers_total'] ?? null,
        'categories_total' => $status['results'][$url]['categories_total'] ?? null,
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
