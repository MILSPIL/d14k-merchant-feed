<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prom.ua → WooCommerce Product Importer
 *
 * Fetches products from Prom.ua via API and creates / updates
 * WooCommerce products.  Each WooCommerce product stores its
 * Prom ID in the meta key `_prom_product_id` for future syncing.
 *
 * Dropshipping flow:
 *   1. Fetch all products from Prom.ua (supplier's catalog).
 *   2. Create or update corresponding WooCommerce products.
 *   3. Optionally apply a price markup percentage.
 *   4. Download main product image to WP media library.
 *
 * @since 4.0.0
 */
class D14K_Prom_Importer
{
    /** @var D14K_Prom_API */
    private $api;

    /** @var array Import statistics */
    private $stats = array();

    // Meta keys
    const META_PROM_ID      = '_prom_product_id';
    const META_PROM_UPDATED = '_prom_last_synced';

    public function __construct($api)
    {
        $this->api = $api;
        $this->reset_stats();
    }

    private function reset_stats()
    {
        $this->stats = array(
            'created'  => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'errors'   => array(),
            'total'    => 0,
            'started'  => current_time('mysql'),
            'finished' => null,
        );
    }

    public function get_stats()
    {
        return $this->stats;
    }

    // -------------------------------------------------------------------------
    // Main import entry points
    // -------------------------------------------------------------------------

    /**
     * Import ALL products from Prom.ua.
     * Safe to run from WP-Cron or AJAX (set_time_limit + memory-aware).
     *
     * @param int $group_id  Prom group/category filter (0 = all)
     * @return array         Stats array
     */
    /**
     * Import ONE page of products from Prom.ua.
     * Designed for batched AJAX execution (one page per request).
     * The caller keeps calling with page+1 until done=true.
     *
     * @param int $page     Page number (1-based)
     * @param int $group_id Prom group filter (0 = all)
     * @return array {
     *   done: bool,          // true = no more pages
     *   next_page: int,      // page to call next
     *   page_created: int,   // created on this page
     *   page_skipped: int,   // skipped on this page
     *   page_updated: int,   // updated on this page
     *   page_errors: array,
     *   cumulative: array,   // d14k_prom_import_last_stats so far
     * }
     */
    /**
     * Import one batch (up to 100 products) from Prom.ua using cursor-based pagination.
     * Prom API uses last_id (NOT page numbers) for pagination.
     *
     * @param int $last_id   Prom product ID of the last item from previous batch (0 = first batch)
     * @param int $group_id  Prom group filter (0 = all)
     * @return array {
     *   done: bool,
     *   next_last_id: int,   // pass this as $last_id on the next call
     *   page_created: int,
     *   page_updated: int,
     *   page_skipped: int,
     *   page_errors: array,
     *   products_on_page: int,
     *   cumulative: array,
     * }
     */
    public function import_page($last_id = 0, $group_id = 0)
    {
        if (!$this->api->has_token()) {
            return array('done' => true, 'error' => 'API-токен не налаштований');
        }

        $settings         = get_option('d14k_feed_settings', array());
        $markup_pct       = isset($settings['prom_price_markup']) ? (float)$settings['prom_price_markup'] : 0;
        $import_images    = !empty($settings['prom_import_images']);
        $import_status    = isset($settings['prom_import_product_status']) ? $settings['prom_import_product_status'] : 'publish';
        $only_available   = isset($settings['prom_only_available']) ? (bool)$settings['prom_only_available'] : true;
        $only_with_images = isset($settings['prom_only_with_images']) ? (bool)$settings['prom_only_with_images'] : false;

        @set_time_limit(120);

        $limit  = 100;
        $result = $this->api->get_products($last_id, $limit, $group_id);

        if ($result === false) {
            return array(
                'done'         => true,
                'next_last_id' => $last_id,
                'error'        => 'Помилка API: ' . $this->api->last_error,
            );
        }

        $products = isset($result['products']) ? (array)$result['products'] : array();

        // Накопичуємо статистику
        $is_first = ($last_id === 0);
        $cumulative = get_option('d14k_prom_import_last_stats', array());
        if ($is_first || empty($cumulative)) {
            $cumulative = array(
                'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors'  => array(), 'total' => 0, 'started' => current_time('mysql'),
            );
        }

        $this->reset_stats();
        $this->stats['total'] = count($products);

        foreach ($products as $prom_product) {
            $this->process_product($prom_product, $markup_pct, $import_images, $import_status, $only_available, $only_with_images);
        }

        // Отримуємо last_id з останнього продукту в батчі
        $next_last_id = 0;
        if (!empty($products)) {
            $last_product = end($products);
            $next_last_id = isset($last_product['id']) ? (int)$last_product['id'] : 0;
        }

        // Merge into cumulative
        $cumulative['created'] += $this->stats['created'];
        $cumulative['updated'] += $this->stats['updated'];
        $cumulative['skipped'] += $this->stats['skipped'];
        $cumulative['total']   += $this->stats['total'];
        $cumulative['errors']   = array_merge($cumulative['errors'] ?? array(), $this->stats['errors']);

        $done = count($products) < $limit;
        if ($done) {
            $cumulative['finished'] = current_time('mysql');
        }
        update_option('d14k_prom_import_last_stats', $cumulative);

        return array(
            'done'             => $done,
            'next_last_id'     => $next_last_id,
            'page_created'     => $this->stats['created'],
            'page_updated'     => $this->stats['updated'],
            'page_skipped'     => $this->stats['skipped'],
            'page_errors'      => $this->stats['errors'],
            'products_on_page' => count($products),
            'cumulative'       => $cumulative,
        );
    }

    public function import_all($group_id = 0)
    {
        $this->reset_stats();

        if (!$this->api->has_token()) {
            $this->stats['errors'][] = 'API-токен Prom.ua не налаштований';
            $this->save_stats();
            return $this->stats;
        }

        // Prevent duplicate concurrent imports
        if (get_transient('d14k_import_running')) {
            $this->stats['errors'][] = 'Імпорт вже запущений. Зачекайте завершення.';
            $this->save_stats();
            return $this->stats;
        }
        set_transient('d14k_import_running', true, 600); // 10 хвилин блокування

        $settings       = get_option('d14k_feed_settings', array());
        $markup_pct     = isset($settings['prom_price_markup']) ? (float) $settings['prom_price_markup'] : 0;
        $import_images  = !empty($settings['prom_import_images']);
        $import_status  = isset($settings['prom_import_product_status']) ? $settings['prom_import_product_status'] : 'publish';
        // Імпортувати тільки товари "є в наявності" (presence=available)
        $only_available = isset($settings['prom_only_available']) ? (bool)$settings['prom_only_available'] : true;

        @set_time_limit(0);

        $page  = 1;
        $limit = 100;

        do {
            $result = $this->api->get_products($page, $limit, $group_id);
            if ($result === false) {
                $this->stats['errors'][] = 'Помилка API на сторінці ' . $page . ': ' . $this->api->last_error;
                break;
            }

            $products = isset($result['products']) ? (array) $result['products'] : array();
            $this->stats['total'] += count($products);

            foreach ($products as $prom_product) {
                $this->process_product($prom_product, $markup_pct, $import_images, $import_status, $only_available);
            }

            $page++;

        } while (count($products) === $limit);

        delete_transient('d14k_import_running');

        $this->stats['finished'] = current_time('mysql');
        $this->save_stats();

        return $this->stats;
    }

    /**
     * Quick update: only refresh price and stock for already-imported products.
     * Much faster than full import, suitable for frequent cron runs.
     *
     * @return array Stats
     */
    public function update_prices_and_stock()
    {
        $this->reset_stats();

        if (!$this->api->has_token()) {
            $this->stats['errors'][] = 'API-токен Prom.ua не налаштований';
            $this->save_stats();
            return $this->stats;
        }

        $settings   = get_option('d14k_feed_settings', array());
        $markup_pct = isset($settings['prom_price_markup']) ? (float) $settings['prom_price_markup'] : 0;

        @set_time_limit(0);

        $page  = 1;
        $limit = 100;

        do {
            $result = $this->api->get_products($page, $limit);
            if ($result === false) {
                $this->stats['errors'][] = 'Помилка API: ' . $this->api->last_error;
                break;
            }

            $products = isset($result['products']) ? (array) $result['products'] : array();
            $this->stats['total'] += count($products);

            foreach ($products as $prom_product) {
                $woo_id = $this->find_woo_product_by_prom_id($prom_product['id']);
                if (!$woo_id) {
                    $this->stats['skipped']++;
                    continue; // Only update existing products
                }

                $price = $this->calc_price($prom_product, $markup_pct);
                $stock = $this->presence_to_stock_status($prom_product);

                update_post_meta($woo_id, '_price', $price);
                update_post_meta($woo_id, '_regular_price', $price);
                update_post_meta($woo_id, '_stock_status', $stock['status']);
                update_post_meta($woo_id, '_manage_stock', $stock['manage'] ? 'yes' : 'no');
                if ($stock['qty'] !== null) {
                    update_post_meta($woo_id, '_stock', $stock['qty']);
                }
                wc_delete_product_transients($woo_id);
                update_post_meta($woo_id, self::META_PROM_UPDATED, current_time('mysql'));

                $this->stats['updated']++;
            }

            $page++;

        } while (count($products) === $limit);

        $this->stats['finished'] = current_time('mysql');
        $this->save_stats();

        return $this->stats;
    }

    // -------------------------------------------------------------------------
    // Per-product processing
    // -------------------------------------------------------------------------

    /**
     * Create or update one WooCommerce product from a Prom product array.
     *
     * @param array  $p             Prom product data
     * @param float  $markup_pct    Price markup in % (e.g. 20 = +20%)
     * @param bool   $import_images Download images to media library
     * @param string $woo_status    WooCommerce post_status ('publish'|'draft')
     */
    private function process_product($p, $markup_pct = 0, $import_images = true, $woo_status = 'publish', $only_available = true, $only_with_images = false)
    {
        $prom_id = (int) $p['id'];

        // ---- Find existing WooCommerce product ----
        $woo_id = $this->find_woo_product_by_prom_id($prom_id);

        // Also try by SKU
        if (!$woo_id && !empty($p['sku'])) {
            $woo_id = wc_get_product_id_by_sku(sanitize_text_field($p['sku']));
        }

        // ---- Skip deleted/inactive products ----
        if (!empty($p['status']) && in_array($p['status'], array('deleted'), true)) {
            if ($woo_id) {
                wp_update_post(array('ID' => $woo_id, 'post_status' => 'draft'));
            }
            $this->stats['skipped']++;
            return;
        }

        // ---- Skip unavailable products (якщо ввімкнено фільтр "тільки в наявності") ----
        $presence = isset($p['presence']) ? $p['presence'] : '';
        if ($only_available && $presence === 'not_available') {
            $this->stats['skipped']++;
            if ($woo_id) {
                wp_update_post(array('ID' => $woo_id, 'post_status' => 'draft'));
            }
            return;
        }

        // ---- Skip products without real image (якщо ввімкнено фільтр "тільки з фото") ----
        if ($only_with_images && !$this->has_real_image($p)) {
            $this->stats['skipped']++;
            return;
        }

        // ---- Build WooCommerce product data ----
        $name        = sanitize_text_field($this->extract_name($p));
        $description = $this->extract_description($p);
        $price       = $this->calc_price($p, $markup_pct);
        $sku         = !empty($p['sku']) ? sanitize_text_field($p['sku']) : '';
        $stock       = $this->presence_to_stock_status($p);

        // ---- Category ----
        $cat_id = $this->get_or_create_category($p);

        // ---- Post data ----
        $post_data = array(
            'post_title'   => $name,
            'post_content' => wp_kses_post($description),
            'post_status'  => $woo_status,
            'post_type'    => 'product',
        );

        $is_new = false;
        if ($woo_id) {
            // Update existing
            $post_data['ID'] = $woo_id;
            $result = wp_update_post($post_data, true);
            if (is_wp_error($result)) {
                $this->stats['errors'][] = "wp_update_post failed for Prom #{$prom_id}: " . $result->get_error_message();
                return;
            }
            $this->stats['updated']++;
        } else {
            // Create new
            $is_new = true;
            $woo_id = wp_insert_post($post_data, true);
            if (is_wp_error($woo_id)) {
                $this->stats['errors'][] = "wp_insert_post failed for Prom #{$prom_id}: " . $woo_id->get_error_message();
                return;
            }
            // Set product type
            wp_set_object_terms($woo_id, 'simple', 'product_type');
            $this->stats['created']++;
        }

        // ---- Product meta (WooCommerce) ----
        update_post_meta($woo_id, '_price', $price);
        update_post_meta($woo_id, '_regular_price', $price);
        update_post_meta($woo_id, '_visibility', 'visible');
        update_post_meta($woo_id, '_stock_status', $stock['status']);
        update_post_meta($woo_id, '_manage_stock', $stock['manage'] ? 'yes' : 'no');
        if ($stock['qty'] !== null) {
            update_post_meta($woo_id, '_stock', (int) $stock['qty']);
        }
        if ($sku) {
            update_post_meta($woo_id, '_sku', $sku);
        }

        // ---- Prom sync meta ----
        update_post_meta($woo_id, self::META_PROM_ID, $prom_id);
        update_post_meta($woo_id, self::META_PROM_UPDATED, current_time('mysql'));

        // ---- Category assignment ----
        if ($cat_id) {
            wp_set_object_terms($woo_id, array($cat_id), 'product_cat', false);
        }

        // ---- Tags / Keywords ----
        if (!empty($p['keywords'])) {
            $tags = array_map('trim', explode(',', $p['keywords']));
            $tags = array_filter($tags);
            if ($tags) {
                wp_set_object_terms($woo_id, $tags, 'product_tag', false);
            }
        }

        // ---- Image ----
        if ($import_images) {
            $this->maybe_import_image($woo_id, $p);
        }

        // ---- Update Prom external_id (тільки при першому створенні) ----
        // Не робимо PUT запит на кожен товар — це сильно сповільнює імпорт.
        // external_id встановлюємо лише для нових товарів і тільки якщо увімкнено в налаштуваннях.
        if ($is_new && !empty($settings['prom_set_external_id'])) {
            $this->maybe_set_external_id_on_prom($prom_id, $woo_id, $p);
        }

        // Invalidate WooCommerce cache
        wc_delete_product_transients($woo_id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Find WooCommerce post ID by Prom product ID stored in meta. */
    private function find_woo_product_by_prom_id($prom_id)
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value = %s
             LIMIT 1",
            self::META_PROM_ID,
            (string) $prom_id
        ));
        return $id ? (int) $id : 0;
    }

    /** Extract product name, preferring Ukrainian. */
    private function extract_name($p)
    {
        if (!empty($p['name_multilang']['uk'])) {
            return $p['name_multilang']['uk'];
        }
        return isset($p['name']) ? $p['name'] : 'Товар #' . $p['id'];
    }

    /** Extract description, preferring Ukrainian. */
    private function extract_description($p)
    {
        if (!empty($p['description_multilang']['uk'])) {
            return $p['description_multilang']['uk'];
        }
        return isset($p['description']) ? $p['description'] : '';
    }

    /**
     * Calculate final price with optional markup.
     *
     * @param array $p           Prom product
     * @param float $markup_pct  e.g. 20 = +20%
     * @return string            Formatted price for WooCommerce
     */
    private function calc_price($p, $markup_pct = 0)
    {
        $price = isset($p['price']) ? (float) $p['price'] : 0;
        if ($markup_pct > 0) {
            $price = $price * (1 + $markup_pct / 100);
        }
        return number_format($price, 2, '.', '');
    }

    /**
     * Convert Prom presence/in_stock to WooCommerce stock fields.
     *
     * @return array ['status' => string, 'manage' => bool, 'qty' => int|null]
     */
    private function presence_to_stock_status($p)
    {
        $presence = isset($p['presence']) ? $p['presence'] : 'available';
        $qty      = isset($p['quantity_in_stock']) ? (int) $p['quantity_in_stock'] : null;

        switch ($presence) {
            case 'available':
                $status = 'instock';
                break;
            case 'not_available':
                $status = 'outofstock';
                break;
            case 'order':
                $status = 'onbackorder';
                break;
            default:
                $status = isset($p['in_stock']) && $p['in_stock'] ? 'instock' : 'outofstock';
        }

        $manage = $qty !== null && $qty >= 0;

        return array('status' => $status, 'manage' => $manage, 'qty' => $qty);
    }

    /**
     * Get or create a WooCommerce product category matching the Prom group.
     *
     * @param array $p  Prom product
     * @return int|null  WooCommerce term_id or null
     */
    private function get_or_create_category($p)
    {
        if (empty($p['group']) || empty($p['group']['name'])) {
            return null;
        }

        $group_name = sanitize_text_field($p['group']['name']);
        $prom_group_id = isset($p['group']['id']) ? (int) $p['group']['id'] : 0;

        // Try to find by stored prom group meta
        if ($prom_group_id) {
            global $wpdb;
            $term_id = $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->termmeta}
                 WHERE meta_key = '_prom_group_id' AND meta_value = %s LIMIT 1",
                (string) $prom_group_id
            ));
            if ($term_id) {
                return (int) $term_id;
            }
        }

        // Try to find by name
        $existing = get_term_by('name', $group_name, 'product_cat');
        if ($existing && !is_wp_error($existing)) {
            if ($prom_group_id) {
                update_term_meta($existing->term_id, '_prom_group_id', $prom_group_id);
            }
            return $existing->term_id;
        }

        // Create new category
        $result = wp_insert_term($group_name, 'product_cat');
        if (is_wp_error($result)) {
            return null;
        }

        if ($prom_group_id) {
            update_term_meta($result['term_id'], '_prom_group_id', $prom_group_id);
        }

        return $result['term_id'];
    }

    /**
     * Download product image from Prom and set as WooCommerce featured image.
     * Only downloads if the product has no image yet, or if image URL changed.
     *
     * @param int   $woo_id  WooCommerce product post ID
     * @param array $p       Prom product data
     */
    /**
     * Check if Prom product has a real (non-placeholder) image.
     *
     * @param array $p Prom product
     * @return bool
     */
    private function has_real_image($p)
    {
        $url = '';
        if (!empty($p['main_image'])) {
            $url = $p['main_image'];
        } elseif (!empty($p['images']) && is_array($p['images'])) {
            $first = reset($p['images']);
            $url = is_array($first) ? ($first['url'] ?? '') : (string) $first;
        }

        if (empty($url)) {
            return false;
        }
        if (strpos($url, 'http') !== 0) {
            return false; // відносний URL = заглушка
        }
        if (strpos($url, 'no-image') !== false || strpos($url, 'no_image') !== false) {
            return false;
        }
        return true;
    }

    /**
     * Upgrade Prom image URL from thumbnail (w200_h200) to full size (w640_h640).
     * Prom.ua encodes size in the filename: _w200_h200_ → _w640_h640_
     *
     * @param string $url
     * @return string
     */
    private function upgrade_image_url($url)
    {
        // Замінюємо будь-який розмір _wNNN_hNNN_ на _w640_h640_
        return preg_replace('/_w\d+_h\d+_/', '_w640_h640_', $url);
    }

    private function maybe_import_image($woo_id, $p)
    {
        $image_url = '';
        if (!empty($p['main_image'])) {
            $image_url = $p['main_image'];
        } elseif (!empty($p['images']) && is_array($p['images'])) {
            $first = reset($p['images']);
            $image_url = is_array($first) ? ($first['url'] ?? '') : (string) $first;
        }

        if (empty($image_url)) {
            return;
        }

        // Пропускаємо заглушки "no-image" від Prom.ua
        if (strpos($image_url, 'no-image') !== false || strpos($image_url, 'no_image') !== false) {
            return;
        }

        // Якщо URL відносний — пропускаємо
        if (strpos($image_url, 'http') !== 0) {
            return;
        }

        // Апгрейд розміру: w200 → w640 для нормальної якості
        $image_url = $this->upgrade_image_url($image_url);

        // Check if already imported this URL
        $existing_url = get_post_meta($woo_id, '_prom_main_image_url', true);
        if ($existing_url === $image_url) {
            return; // No change
        }

        // Check if product already has a thumbnail and we shouldn't overwrite
        $existing_thumb = get_post_thumbnail_id($woo_id);
        if ($existing_thumb && $existing_url === $image_url) {
            return;
        }

        $attachment_id = $this->upload_image_from_url($image_url, $woo_id);
        if ($attachment_id) {
            set_post_thumbnail($woo_id, $attachment_id);
            update_post_meta($woo_id, '_prom_main_image_url', $image_url);
        }
    }

    /**
     * Download an image from URL via cURL and add it to the WP Media Library.
     * Uses raw cURL instead of download_url() to bypass Hostinger wp_remote_get issue.
     *
     * @param string $url
     * @param int    $parent_post_id
     * @return int|false   Attachment ID or false
     */
    private function upload_image_from_url($url, $parent_post_id = 0)
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // --- Завантажуємо через cURL (wp_remote_get не працює на Hostinger) ---
        $tmp_file = wp_tempnam($url);
        if (!$tmp_file) {
            error_log("D14K Prom Importer: Cannot create temp file for {$url}");
            return false;
        }

        $ch = curl_init($url);
        $fp = fopen($tmp_file, 'wb');
        curl_setopt_array($ch, array(
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'WordPress/' . get_bloginfo('version'),
        ));
        curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($curl_error || $http_code < 200 || $http_code >= 300) {
            @unlink($tmp_file);
            error_log("D14K Prom Importer: cURL image download failed [{$http_code}] {$curl_error} — {$url}");
            return false;
        }

        $file_array = array(
            'name'     => basename(parse_url($url, PHP_URL_PATH)) ?: 'prom-image.jpg',
            'tmp_name' => $tmp_file,
        );

        $attachment_id = media_handle_sideload($file_array, $parent_post_id);
        @unlink($tmp_file);

        if (is_wp_error($attachment_id)) {
            error_log("D14K Prom Importer: media_handle_sideload failed: " . $attachment_id->get_error_message());
            return false;
        }

        return $attachment_id;
    }

    /**
     * If the product on Prom doesn't have external_id set yet,
     * update it to the WooCommerce post ID for future two-way sync.
     *
     * @param int   $prom_id
     * @param int   $woo_id
     * @param array $p       Prom product data
     */
    private function maybe_set_external_id_on_prom($prom_id, $woo_id, $p)
    {
        $existing_ext = isset($p['external_id']) ? (string) $p['external_id'] : '';

        // Only set if not yet set or different
        if ($existing_ext === (string) $woo_id) {
            return;
        }

        // Fire-and-forget, don't fail the import if this doesn't work
        $this->api->edit_product($prom_id, array('external_id' => (string) $woo_id));
    }

    /**
     * Persist stats to WP options for display in admin.
     */
    private function save_stats()
    {
        update_option('d14k_prom_import_last_stats', $this->stats);
    }
}
