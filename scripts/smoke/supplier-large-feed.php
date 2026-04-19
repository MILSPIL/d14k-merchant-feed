<?php
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this script via wp eval-file inside WordPress.\n");
    exit(1);
}

$feed_file = getenv('D14K_SUPPLIER_FEED_FILE') ?: '';
$feed_url  = getenv('D14K_SUPPLIER_FEED_URL') ?: '';
$selection_mode = (string) (getenv('D14K_SUPPLIER_SELECTION_MODE') ?: '');
$max_items = max(0, (int) getenv('D14K_SUPPLIER_MAX_ITEMS'));

if ($feed_file === '' && $feed_url === '') {
    fwrite(STDERR, "Set D14K_SUPPLIER_FEED_FILE=/path/to/feed.xml or D14K_SUPPLIER_FEED_URL=https://...\n");
    exit(1);
}

$temp_file = '';

$download_to_temp = static function ($url) use (&$temp_file) {
    $temp_file = wp_tempnam('d14k-supplier-large-feed.xml');
    if (!$temp_file) {
        throw new RuntimeException('Не вдалося створити тимчасовий файл для feed');
    }

    $handle = fopen($temp_file, 'wb');
    if (!$handle) {
        throw new RuntimeException('Не вдалося відкрити тимчасовий файл для запису');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FAILONERROR => false,
        CURLOPT_USERAGENT => 'D14K Supplier Smoke Check',
    ));

    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($handle);

    if ($ok === false || $http_code >= 400) {
        throw new RuntimeException('Не вдалося завантажити feed: HTTP ' . $http_code . ' ' . $error);
    }

    return $temp_file;
};

try {
    $target_file = $feed_file !== '' ? $feed_file : $download_to_temp($feed_url);

    if (!file_exists($target_file)) {
        throw new RuntimeException('Feed file не знайдено: ' . $target_file);
    }

    $supplier = new D14K_Supplier_Feeds();
    $reflection = new ReflectionClass($supplier);
    $method = $reflection->getMethod('parse_feed_dataset_file');
    $method->setAccessible(true);

    $started_at = microtime(true);
    $memory_before = memory_get_usage(true);
    $result = $method->invoke($supplier, $target_file, $selection_mode, $max_items);
    $duration = microtime(true) - $started_at;
    $memory_peak = memory_get_peak_usage(true);

    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }

    echo wp_json_encode(array(
        'source' => $feed_file !== '' ? $feed_file : $feed_url,
        'selection_mode' => $selection_mode,
        'max_items' => $max_items,
        'categories_total' => isset($result['categories']) && is_array($result['categories']) ? count($result['categories']) : 0,
        'offers_total' => isset($result['offers']) && is_array($result['offers']) ? count($result['offers']) : 0,
        'duration_seconds' => round($duration, 3),
        'memory_before_mb' => round($memory_before / 1048576, 2),
        'memory_peak_mb' => round($memory_peak / 1048576, 2),
        'xmlreader_available' => class_exists('XMLReader'),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    if ($temp_file !== '' && file_exists($temp_file)) {
        @unlink($temp_file);
    }
}
