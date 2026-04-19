<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Supplier XML/YML Feed Importer (Dropshipping)
 *
 * v1 scope:
 *   - imports and updates simple products only
 *   - builds WooCommerce category tree from feed categories/categoryId
 *   - matches products by source external ID first, then by SKU
 *   - updates dynamic fields on every sync and fills static fields on create
 *
 * @since 4.0.0
 */
class D14K_Supplier_Feeds
{
    const IMPORT_STATE_TRANSIENT = 'd14k_supplier_import_state_';
    const CLEANUP_STATE_TRANSIENT = 'd14k_supplier_cleanup_state_';
    const BACKGROUND_IMPORT_OPTION = 'd14k_supplier_background_import_state';
    const LAST_IMPORT_SNAPSHOT_OPTION = 'd14k_supplier_last_import_snapshot';
    const BACKGROUND_IMPORT_HOOK = 'd14k_supplier_background_import_step';
    const BACKGROUND_IMPORT_GROUP = 'd14k_supplier_import';
    const BACKGROUND_WATCHDOG_HOOK = 'd14k_supplier_background_import_watchdog';
    const BACKGROUND_STEP_SOFT_LIMIT = 20;
    const BACKGROUND_WATCHDOG_DELAY = 330;
    const BACKGROUND_AUTO_CONTINUE_LIMIT = 24;
    const TEMP_DIR_SLUG = 'd14k-supplier-import';

    const META_SOURCE_KEY       = '_d14k_supplier_source_key';
    const META_SOURCE_URL       = '_d14k_supplier_source_url';
    const META_EXTERNAL_ID      = '_d14k_supplier_external_id';
    const META_EXTERNAL_KEY     = '_d14k_supplier_external_key';
    const META_EXTERNAL_SKU     = '_d14k_supplier_external_sku';
    const META_CATEGORY_ID      = '_d14k_supplier_category_id';
    const META_VENDOR           = '_d14k_supplier_vendor';
    const META_SOURCE_PRODUCT   = '_d14k_supplier_product_url';
    const META_SOURCE_IMAGE     = '_d14k_supplier_image_url';
    const META_SOURCE_PARAMS    = '_d14k_supplier_params';
    const META_LAST_SYNC        = '_d14k_supplier_last_updated';

    const TERM_META_SOURCE_KEY  = '_d14k_supplier_term_source_key';
    const TERM_META_CATEGORY_ID = '_d14k_supplier_term_category_id';
    const TERM_META_CATEGORY_KEY = '_d14k_supplier_term_category_key';
    const TERM_META_ROOT_KEY    = '_d14k_supplier_term_root_key';

    /** @var array Results from last run */
    private $results = array();

    public function __construct()
    {
        add_action(self::BACKGROUND_IMPORT_HOOK, array($this, 'handle_background_import_step'));
        add_action(self::BACKGROUND_WATCHDOG_HOOK, array($this, 'handle_background_import_watchdog'));
    }

    /**
     * Process all configured supplier feeds.
     *
     * @return array
     */
    public function process_all_feeds()
    {
        $feeds = $this->get_enabled_feeds();

        $this->results = array();

        if (empty($feeds)) {
            return $this->results;
        }

        @set_time_limit(0);

        foreach ($feeds as $feed) {
            $this->results[$feed['url']] = $this->process_feed($feed);
        }

        update_option('d14k_supplier_feeds_last_run', array(
            'time'    => current_time('mysql'),
            'results' => $this->results,
        ));

        return $this->results;
    }

    /**
     * Process supplier feeds in small AJAX-safe batches.
     *
     * @param int  $batch_size
     * @param bool $reset
     * @return array
     */
    public function process_batch($batch_size = 25, $reset = false)
    {
        $batch_size = max(1, (int) $batch_size);
        $state      = $this->get_import_state($reset, $batch_size);

        if (empty($state['feeds'])) {
            return array(
                'done'       => true,
                'message'    => 'Жодного увімкненого фіду постачальника не налаштовано',
                'batch'      => array('created' => 0, 'updated' => 0, 'skipped' => 0),
                'cumulative' => array('created' => 0, 'updated' => 0, 'skipped' => 0),
                'results'    => array(),
            );
        }

        $result = $this->run_import_step($state, $batch_size);
        $done   = !empty($result['done']);

        if ($done) {
            $this->finalize_import_state($state);
        } else {
            $this->save_import_state($state);
        }

        return $result;
    }

    /**
     * Start server-side background import using Action Scheduler.
     *
     * @param int  $batch_size
     * @param bool $force_restart
     * @return array|WP_Error
     */
    public function start_background_import($batch_size = 25, $force_restart = false, $requested_feeds = null, $auto_continue = true, $options = array())
    {
        if (!$this->supports_background_import()) {
            return new WP_Error('background_unavailable', 'Action Scheduler недоступний');
        }

        $auto_continue = true;
        $options = is_array($options) ? $options : array();
        $requested_feed_configs = $this->resolve_requested_feeds($requested_feeds);
        $state = $this->reconcile_background_import_state($this->get_background_import_state());
        $scope_matches = $this->background_state_matches_requested_scope($state, $requested_feed_configs, $options);

        if (!empty($state) && in_array(($state['status'] ?? ''), array('queued', 'running'), true)) {
            if (!$scope_matches) {
                return new WP_Error('background_busy', 'Інший фоновий імпорт уже виконується. Дочекайтеся завершення або скасуйте його.');
            }

            $state['auto_continue'] = !empty($auto_continue);
            $this->refresh_background_watchdog($state);
            $this->save_background_import_state($state);
            return $state;
        }

        if (!$force_restart && $scope_matches && $this->can_resume_background_import($state)) {
            return $this->queue_background_import_resume(
                $state,
                'Відновлюємо імпорт із збереженого місця.',
                $auto_continue
            );
        }

        if (!empty($state)) {
            $this->unschedule_background_watchdog();
            $this->cleanup_import_state_files($state);
        }

        $state = $this->build_background_import_state(
            $this->determine_background_batch_size($batch_size, $requested_feed_configs),
            $requested_feed_configs,
            array(
                'auto_continue' => $auto_continue,
                'max_items_per_feed' => isset($options['max_items_per_feed']) ? (int) $options['max_items_per_feed'] : 0,
                'selection_mode' => isset($options['selection_mode']) ? sanitize_key((string) $options['selection_mode']) : '',
            )
        );

        if (empty($state['feeds'])) {
            return new WP_Error('no_feeds', 'Жодного увімкненого фіду постачальника не налаштовано');
        }

        $state['action_id'] = $this->schedule_background_import_step();
        $this->refresh_background_watchdog($state);
        $this->save_background_import_state($state);

        return $state;
    }

    /**
     * Return current background import status.
     *
     * @return array
     */
    public function get_background_import_status()
    {
        $state = $this->reconcile_background_import_state($this->get_background_import_state());

        if (empty($state)) {
            return array(
                'status' => 'idle',
            );
        }

        $state['cumulative'] = $this->sum_result_totals($state['results'] ?? array());
        $state['can_resume'] = $this->can_resume_background_import($state);
        return $state;
    }

    /**
     * Cancel current background import and unschedule queued steps.
     *
     * @return array|WP_Error
     */
    public function cancel_background_import()
    {
        $state = $this->reconcile_background_import_state($this->get_background_import_state());

        if (empty($state) || in_array(($state['status'] ?? ''), array('idle', 'completed', 'failed', 'cancelled'), true)) {
            return new WP_Error('background_not_running', 'Фоновий імпорт зараз не виконується');
        }

        $this->unschedule_background_import_actions();
        $this->unschedule_background_watchdog();

        $state['cancel_requested'] = true;
        $state['status']           = 'cancelled';
        $state['current_stage']    = 'cancelled';
        $state['action_id']        = 0;
        $state['completed_at']     = current_time('mysql');
        $state['updated_at']       = $state['completed_at'];
        $state['last_batch']       = array_merge(
            isset($state['last_batch']) && is_array($state['last_batch']) ? $state['last_batch'] : array(),
            array(
                'message' => 'Імпорт скасовано користувачем.',
            )
        );
        $state['cumulative'] = $this->sum_result_totals($state['results'] ?? array());

        $this->cancel_background_import_actions();
        $this->persist_last_import_snapshot($state);
        $this->save_background_import_state($state);
        do_action('d14k_supplier_background_import_finalized', $state);

        return $state;
    }

    /**
     * Worker callback for one background import step.
     *
     * @return void
     */
    public function handle_background_import_step()
    {
        $state = $this->get_background_import_state();

        if (empty($state)) {
            return;
        }

        if (!empty($state['cancel_requested']) || ($state['status'] ?? '') === 'cancelled') {
            $this->save_background_import_state($state);
            return;
        }

        if (!in_array(($state['status'] ?? ''), array('queued', 'running'), true)) {
            return;
        }

        try {
            $state['status']     = 'running';
            $state['updated_at'] = current_time('mysql');

            $result = $this->run_import_step($state, (int) ($state['batch_size'] ?? 25));

            if (!empty($result['done'])) {
                $this->unschedule_background_watchdog();
                $state['status']        = 'completed';
                $state['completed_at']  = current_time('mysql');
                $state['updated_at']    = $state['completed_at'];
                $state['action_id']     = 0;
                $state['current_stage'] = 'completed';
                $state['cumulative']    = $this->sum_result_totals($state['results']);
                update_option('d14k_supplier_feeds_last_run', array(
                    'time'    => current_time('mysql'),
                    'results' => $state['results'],
                ));
                $this->persist_last_import_snapshot($state);
                $this->cleanup_import_state_files($state);
                $this->save_background_import_state($state);
                do_action('d14k_supplier_background_import_finalized', $state);
                return;
            }

            $latest_state = $this->get_background_import_state();
            if (!empty($latest_state['cancel_requested']) || ($latest_state['status'] ?? '') === 'cancelled') {
                $state['cancel_requested'] = true;
                $state['status']           = 'cancelled';
                $state['completed_at']     = current_time('mysql');
                $state['updated_at']       = $state['completed_at'];
                $state['action_id']        = 0;
                $state['current_stage']    = 'cancelled';
                $state['last_batch']       = array_merge(
                    isset($state['last_batch']) && is_array($state['last_batch']) ? $state['last_batch'] : array(),
                    array(
                        'message' => 'Імпорт скасовано після завершення поточного батча.',
                    )
                );
                $state['cumulative'] = $this->sum_result_totals($state['results']);
                $this->persist_last_import_snapshot($state);
                $this->save_background_import_state($state);
                do_action('d14k_supplier_background_import_finalized', $state);
                return;
            }

            $state['action_id']  = $this->schedule_background_import_step();
            $state['updated_at'] = current_time('mysql');
            $state['cumulative'] = $this->sum_result_totals($state['results']);
            $this->refresh_background_watchdog($state);
            $this->save_background_import_state($state);
        } catch (Throwable $e) {
            if (!empty($state['current_feed_url']) && isset($state['results'][$state['current_feed_url']])) {
                $this->append_result_error($state['results'][$state['current_feed_url']], $e->getMessage());
            }

            $this->mark_background_import_failed($state, $e->getMessage());
        }
    }

    /**
     * Watchdog callback that can revive auto-continue runs after server-side failure.
     *
     * @return void
     */
    public function handle_background_import_watchdog()
    {
        $state = $this->get_background_import_state();

        if (empty($state) || !is_array($state)) {
            $this->unschedule_background_watchdog();
            return;
        }

        $state = $this->reconcile_background_import_state($state);

        if (empty($state)) {
            $this->unschedule_background_watchdog();
            return;
        }

        if (!empty($state['auto_continue']) && in_array((string) ($state['status'] ?? ''), array('queued', 'running'), true)) {
            $this->refresh_background_watchdog($state);
            return;
        }

        $this->unschedule_background_watchdog();
    }

    /**
     * Move invalid supplier products to trash in small batches.
     *
     * @param int  $batch_size
     * @param bool $reset
     * @return array
     */
    public function cleanup_invalid_products_batch($batch_size = 25, $reset = false)
    {
        $batch_size = max(1, (int) $batch_size);
        $state      = $this->get_cleanup_state($reset);

        if (empty($state['feeds'])) {
            return array(
                'done'       => true,
                'message'    => 'Жодного увімкненого фіду постачальника не налаштовано',
                'batch'      => array('scanned' => 0, 'trashed' => 0, 'kept' => 0),
                'cumulative' => array('scanned' => 0, 'trashed' => 0, 'kept' => 0),
                'results'    => array(),
            );
        }

        while ($state['current_feed'] < count($state['feeds'])) {
            $feed       = $state['feeds'][$state['current_feed']];
            $url        = $feed['url'];
            $source_key = $this->build_source_key($url);

            $body = $this->download_feed_body($url);
            if (is_wp_error($body)) {
                $this->append_result_error($state['results'][$url], $body->get_error_message());
                $state['current_feed']++;
                $state['current_cursor'] = 0;
                continue;
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
            libxml_clear_errors();

            if ($xml === false) {
                $this->append_result_error($state['results'][$url], 'Не вдалось розпарсити XML');
                $state['current_feed']++;
                $state['current_cursor'] = 0;
                continue;
            }

            $offers           = $this->extract_offers($xml);
            $invalid_offer_map = $this->build_invalid_offer_map($offers);
            $existing_products = $this->get_supplier_products_page($source_key, $state['current_cursor'], $batch_size);

            if (!isset($state['results'][$url]['source_key'])) {
                $state['results'][$url]['source_key']   = $source_key;
                $state['results'][$url]['offers_total'] = count($offers);
            }

            if (empty($existing_products)) {
                $this->finalize_cleanup_feed_result($state['results'][$url], $source_key);
                $state['current_feed']++;
                $state['current_cursor'] = 0;
                continue;
            }

            $batch = array(
                'scanned'    => 0,
                'trashed'    => 0,
                'kept'       => 0,
                'feed_url'   => $url,
                'source_key' => $source_key,
            );

            foreach ($existing_products as $product_row) {
                $product_id = (int) $product_row['ID'];
                $reason     = $this->get_cleanup_reason_for_product($product_row, $invalid_offer_map);

                $batch['scanned']++;
                $state['results'][$url]['scanned']++;

                if ($reason === '') {
                    $batch['kept']++;
                    $state['results'][$url]['kept']++;
                    continue;
                }

                if (wp_trash_post($product_id)) {
                    $batch['trashed']++;
                    $state['results'][$url]['trashed']++;
                    $this->maybe_add_analysis_sample(
                        $state['results'][$url]['samples']['trashed'],
                        '#' . $product_id . ' ' . get_the_title($product_id) . ' • ' . $reason
                    );
                } else {
                    $this->append_result_error($state['results'][$url], 'Не вдалося перемістити в кошик товар #' . $product_id);
                }
            }

            $last_seen_id = 0;
            foreach ($existing_products as $product_row) {
                $last_seen_id = max($last_seen_id, (int) $product_row['ID']);
            }
            $state['current_cursor'] = $last_seen_id;

            if (count($existing_products) < $batch_size) {
                $this->finalize_cleanup_feed_result($state['results'][$url], $source_key);
                $state['current_feed']++;
                $state['current_cursor'] = 0;
            }

            $done = ($state['current_feed'] >= count($state['feeds']));
            if ($done) {
                $this->finalize_cleanup_state($state);
            } else {
                $this->save_cleanup_state($state);
            }

            return array(
                'done'       => $done,
                'batch'      => $batch,
                'cumulative' => $this->sum_cleanup_totals($state['results']),
                'results'    => $state['results'],
                'progress'   => array(
                    'feed_index' => $state['current_feed'],
                    'cursor'     => $state['current_cursor'],
                ),
            );
        }

        $this->finalize_cleanup_state($state);

        return array(
            'done'       => true,
            'batch'      => array('scanned' => 0, 'trashed' => 0, 'kept' => 0),
            'cumulative' => $this->sum_cleanup_totals($state['results']),
            'results'    => $state['results'],
        );
    }

    /**
     * Analyze all configured supplier feeds without writing to WooCommerce.
     *
     * @return array
     */
    public function analyze_all_feeds()
    {
        $feeds   = $this->get_enabled_feeds();
        $results  = array();

        if (empty($feeds)) {
            return $results;
        }

        @set_time_limit(0);

        foreach ($feeds as $feed) {
            $results[$feed['url']] = $this->analyze_feed($feed);
        }

        return $results;
    }

    /**
     * Analyze one supplier feed URL without creating or updating products.
     *
     * @param array $feed
     * @return array
     */
    public function analyze_feed($feed)
    {
        $url     = $feed['url'];
        $markup  = (float) ($feed['markup'] ?? 0);
        $stat = array(
            'source_key'        => '',
            'offers_total'      => 0,
            'categories_total'  => 0,
            'created'           => 0,
            'updated'           => 0,
            'skipped'           => 0,
            'errors'            => array(),
            'match_types'       => array(
                'external_key' => 0,
                'sku'          => 0,
                'none'         => 0,
            ),
            'risk_counts'       => array(
                'sku_existing_product' => 0,
                'sku_other_supplier'   => 0,
                'sku_same_supplier'    => 0,
                'missing_identifiers'  => 0,
            ),
            'samples'           => array(
                'created'              => array(),
                'updated'              => array(),
                'skipped'              => array(),
                'sku_existing_product' => array(),
                'sku_other_supplier'   => array(),
                'sku_same_supplier'    => array(),
                'missing_identifiers'  => array(),
            ),
            'update_stock'      => !empty($feed['update_fields']['stock']),
            'markup_pct'        => $markup,
            'feed_label'        => $feed['label'] ?? '',
            'category_root'     => $this->get_supplier_root_name($feed),
            'update_fields'     => array_keys(array_filter($feed['update_fields'] ?? array())),
        );

        $body = $this->download_feed_body($url);
        if (is_wp_error($body)) {
            $stat['errors'][] = $body->get_error_message();
            return $stat;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($xml === false) {
            $stat['errors'][] = 'Не вдалось розпарсити XML';
            return $stat;
        }

        $source_key               = $feed['source_key'] ?? $this->build_source_key($url);
        $categories               = $this->extract_categories($xml);
        $offers                   = $this->extract_offers($xml);
        $stat['source_key']       = $source_key;
        $stat['categories_total'] = count($categories);
        $stat['offers_total']     = count($offers);

        if (empty($offers)) {
            $stat['errors'][] = 'Жодного товару не знайдено у фіді';
            return $stat;
        }

        foreach ($offers as $offer) {
            $analysis = $this->analyze_offer_import($offer, $source_key);
            $status   = $analysis['status'];

            if (!empty($analysis['error'])) {
                $stat['errors'][] = $analysis['error'];
            }

            if (!empty($analysis['match_type']) && isset($stat['match_types'][$analysis['match_type']])) {
                $stat['match_types'][$analysis['match_type']]++;
            }

            if ($status === 'created') {
                $stat['created']++;
                $this->maybe_add_analysis_sample($stat['samples']['created'], $analysis['summary']);
            } elseif ($status === 'updated') {
                $stat['updated']++;
                $this->maybe_add_analysis_sample($stat['samples']['updated'], $analysis['summary']);
            } else {
                $stat['skipped']++;
                if (!empty($analysis['summary'])) {
                    $this->maybe_add_analysis_sample($stat['samples']['skipped'], $analysis['summary']);
                }
            }

            if (!empty($analysis['risk_key']) && isset($stat['risk_counts'][$analysis['risk_key']])) {
                $stat['risk_counts'][$analysis['risk_key']]++;
                if (!empty($analysis['risk_message'])) {
                    $this->maybe_add_analysis_sample($stat['samples'][$analysis['risk_key']], $analysis['risk_message']);
                }
            }
        }

        return $stat;
    }

    /**
     * Process one supplier feed URL.
     *
     * @param array $feed
     * @return array
     */
    public function process_feed($feed)
    {
        $url  = $feed['url'];
        $stat = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors'  => array(),
        );

        $body = $this->download_feed_body($url);
        if (is_wp_error($body)) {
            $stat['errors'][] = $body->get_error_message();
            return $stat;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($xml === false) {
            $stat['errors'][] = 'Не вдалось розпарсити XML';
            return $stat;
        }

        $categories   = $this->extract_categories($xml);
        $offers       = $this->extract_offers($xml);
        $category_map = $this->sync_offer_categories($categories, $offers, $feed);

        if (empty($offers)) {
            $stat['errors'][] = 'Жодного товару не знайдено у фіді';
            return $stat;
        }

        foreach ($offers as $offer) {
            $result = $this->import_product_from_offer($offer, $feed, $category_map);

            if ($result === 'created') {
                $stat['created']++;
            } elseif ($result === 'updated') {
                $stat['updated']++;
            } elseif ($result === 'skipped') {
                $stat['skipped']++;
            } elseif (is_string($result) && $result !== '') {
                $stat['errors'][] = $result;
            } else {
                $stat['skipped']++;
            }
        }

        return $stat;
    }

    /**
     * Analyze how one offer would be matched during import.
     *
     * @param array  $offer
     * @param string $source_key
     * @return array
     */
    private function analyze_offer_import($offer, $source_key)
    {
        $external_id = trim((string) ($offer['id'] ?? ''));
        $name        = trim((string) ($offer['name'] ?? ''));
        $sku         = trim((string) ($offer['sku'] ?? ''));
        $skip_reason = $this->get_offer_skip_reason($offer);

        $analysis = array(
            'status'       => 'skipped',
            'match_type'   => 'none',
            'summary'      => '',
            'risk_key'     => '',
            'risk_message' => '',
            'error'        => '',
        );

        if ($skip_reason !== '') {
            $analysis['summary'] = $this->build_offer_debug_label($offer) . ' • ' . $skip_reason;
            return $analysis;
        }

        $woo_id     = 0;
        $match_type = 'none';

        if ($external_id !== '') {
            $woo_id = $this->find_product_by_external_key($this->build_external_product_key($source_key, $external_id));
            if ($woo_id) {
                $match_type = 'external_key';
            }
        }

        if (!$woo_id && $sku !== '') {
            $woo_id = (int) wc_get_product_id_by_sku($sku);
            if ($woo_id) {
                $match_type = 'sku';
            }
        }

        $analysis['match_type'] = $match_type;

        if (!$woo_id) {
            $analysis['status']  = 'created';
            $analysis['summary'] = $this->build_offer_debug_label($offer) . ' • буде створено';

            if ($external_id === '' && $sku === '') {
                $analysis['risk_key']     = 'missing_identifiers';
                $analysis['risk_message'] = $this->build_offer_debug_label($offer) . ' • немає external_id і SKU, можливі дублікати при повторному імпорті';
            }

            return $analysis;
        }

        $existing_post = get_post($woo_id);
        if (!$existing_post || $existing_post->post_type !== 'product') {
            $analysis['summary'] = $this->build_offer_debug_label($offer) . ' • знайдено невалідний запис #' . (int) $woo_id;
            return $analysis;
        }

        $analysis['status']  = 'updated';
        $analysis['summary'] = $this->build_offer_debug_label($offer) . ' • оновить товар #' . (int) $woo_id . ' "' . $existing_post->post_title . '"';

        if ($match_type === 'sku') {
            $existing_source_key = (string) get_post_meta($woo_id, self::META_SOURCE_KEY, true);

            if ($existing_source_key === '') {
                $analysis['risk_key']     = 'sku_existing_product';
                $analysis['risk_message'] = $this->build_offer_debug_label($offer) . ' • SKU-match з існуючим товаром #' . (int) $woo_id . ' без supplier source';
            } elseif ($existing_source_key !== $source_key) {
                $analysis['risk_key']     = 'sku_other_supplier';
                $analysis['risk_message'] = $this->build_offer_debug_label($offer) . ' • SKU-match з товаром #' . (int) $woo_id . ' іншого supplier source';
            } else {
                $analysis['risk_key']     = 'sku_same_supplier';
                $analysis['risk_message'] = $this->build_offer_debug_label($offer) . ' • SKU-match з уже прив’язаним товаром цього самого feed';
            }
        }

        return $analysis;
    }

    /**
     * Build a short readable offer label for logs and analysis.
     *
     * @param array $offer
     * @return string
     */
    private function build_offer_debug_label($offer)
    {
        $parts       = array();
        $name        = trim((string) ($offer['name'] ?? ''));
        $sku         = trim((string) ($offer['sku'] ?? ''));
        $external_id = trim((string) ($offer['id'] ?? ''));

        $parts[] = $name !== '' ? $name : 'Товар без назви';

        if ($sku !== '') {
            $parts[] = 'SKU ' . $sku;
        }

        if ($external_id !== '') {
            $parts[] = 'ID ' . $external_id;
        }

        return implode(' • ', $parts);
    }

    /**
     * Add a preview sample without growing the payload too much.
     *
     * @param array  $samples
     * @param string $value
     * @param int    $limit
     * @return void
     */
    private function maybe_add_analysis_sample(&$samples, $value, $limit = 8)
    {
        if ($value === '' || count($samples) >= $limit) {
            return;
        }

        $samples[] = $value;
    }

    /**
     * Download feed contents.
     *
     * @param string $url
     * @return string|WP_Error
     */
    private function download_feed_body($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'WordPress/' . get_bloginfo('version'),
            ));

            $body       = curl_exec($ch);
            $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                return new WP_Error('supplier_feed_curl', 'Помилка отримання фіду: ' . $curl_error);
            }

            if ($http_code < 200 || $http_code >= 300) {
                return new WP_Error('supplier_feed_http', "HTTP {$http_code} при завантаженні фіду");
            }

            if ($body === false || trim($body) === '') {
                return new WP_Error('supplier_feed_empty', 'Порожній фід');
            }

            return $body;
        }

        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'headers' => array(
                'Accept-Encoding' => 'identity',
            ),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('supplier_feed_wp_http', 'Помилка отримання фіду: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('supplier_feed_wp_http_code', "HTTP {$code} при завантаженні фіду");
        }

        $body = wp_remote_retrieve_body($response);
        if (trim((string) $body) === '') {
            return new WP_Error('supplier_feed_wp_http_empty', 'Порожній фід');
        }

        return $body;
    }

    /**
     * Download feed contents into a temporary file.
     *
     * @param string $url
     * @return string|WP_Error
     */
    private function download_feed_to_temp_file($url)
    {
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp_file = wp_tempnam($url);
        if (!$tmp_file) {
            return new WP_Error('supplier_feed_temp_file', 'Не вдалося створити тимчасовий файл для supplier feed');
        }

        if (function_exists('curl_init')) {
            $fp = fopen($tmp_file, 'wb');
            if (!$fp) {
                @unlink($tmp_file);
                return new WP_Error('supplier_feed_temp_file_open', 'Не вдалося відкрити тимчасовий файл supplier feed');
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'WordPress/' . get_bloginfo('version'),
            ));

            curl_exec($ch);
            $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if ($curl_error) {
                @unlink($tmp_file);
                return new WP_Error('supplier_feed_curl', 'Помилка отримання фіду: ' . $curl_error);
            }

            if ($http_code < 200 || $http_code >= 300) {
                @unlink($tmp_file);
                return new WP_Error('supplier_feed_http', "HTTP {$http_code} при завантаженні фіду");
            }

            if (!file_exists($tmp_file) || filesize($tmp_file) <= 0) {
                @unlink($tmp_file);
                return new WP_Error('supplier_feed_empty', 'Порожній фід');
            }

            return $tmp_file;
        }

        $response = wp_safe_remote_get($url, array(
            'timeout' => 120,
            'stream'  => true,
            'filename' => $tmp_file,
            'headers' => array(
                'Accept-Encoding' => 'identity',
            ),
        ));

        if (is_wp_error($response)) {
            @unlink($tmp_file);
            return new WP_Error('supplier_feed_wp_http', 'Помилка отримання фіду: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            @unlink($tmp_file);
            return new WP_Error('supplier_feed_wp_http_code', "HTTP {$code} при завантаженні фіду");
        }

        if (!file_exists($tmp_file) || filesize($tmp_file) <= 0) {
            @unlink($tmp_file);
            return new WP_Error('supplier_feed_wp_http_empty', 'Порожній фід');
        }

        return $tmp_file;
    }

    /**
     * Extract category tree from the feed.
     *
     * @param SimpleXMLElement $xml
     * @return array
     */
    private function extract_categories($xml)
    {
        $categories = array();
        $shop       = $this->get_shop_node($xml);
        $nodes      = isset($shop->categories->category) ? $shop->categories->category : array();

        foreach ($nodes as $category) {
            $attrs = $category->attributes();
            $id    = trim((string) ($attrs['id'] ?? ''));
            $name  = sanitize_text_field(trim((string) $category));

            if ($id === '' || $name === '') {
                continue;
            }

            $categories[$id] = array(
                'id'        => $id,
                'parent_id' => trim((string) ($attrs['parentId'] ?? '')),
                'name'      => $name,
            );
        }

        return $categories;
    }

    /**
     * Extract offers from the feed in a normalized structure.
     *
     * @param SimpleXMLElement $xml
     * @return array
     */
    private function extract_offers($xml)
    {
        $offers = array();
        $root   = $xml->getName();
        $shop   = $this->get_shop_node($xml);

        if (isset($shop->offers->offer)) {
            foreach ($shop->offers->offer as $offer) {
                $offers[] = $this->parse_yml_offer($offer);
            }
            return $offers;
        }

        if ($root === 'rss' && isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $parsed = $this->parse_rss_item($item);
                if ($parsed) {
                    $offers[] = $parsed;
                }
            }
            return $offers;
        }

        foreach (array('product', 'offer', 'item') as $tag) {
            $nodes = $xml->$tag ?? null;
            if ($nodes && count($nodes) > 0) {
                foreach ($nodes as $node) {
                    $offers[] = $this->parse_generic_node($node);
                }
                return $offers;
            }

            foreach ($xml->children() as $child) {
                $nodes = $child->$tag ?? null;
                if ($nodes && count($nodes) > 0) {
                    foreach ($nodes as $node) {
                        $offers[] = $this->parse_generic_node($node);
                    }
                    return $offers;
                }
            }
        }

        return $offers;
    }

    /**
     * Parse a YML offer.
     *
     * @param SimpleXMLElement $offer
     * @return array
     */
    private function parse_yml_offer($offer)
    {
        $attrs    = $offer->attributes();
        $pictures = array();
        $params   = array();

        foreach ($offer->picture as $picture) {
            $url = esc_url_raw(trim((string) $picture));
            if ($url !== '') {
                $pictures[] = $url;
            }
        }

        foreach ($offer->param as $param) {
            $param_name = sanitize_text_field(trim((string) ($param['name'] ?? '')));
            $param_val  = sanitize_text_field(trim((string) $param));
            if ($param_name !== '' && $param_val !== '') {
                $params[] = array(
                    'name'  => $param_name,
                    'value' => $param_val,
                );
            }
        }

        return array(
            'id'             => trim((string) ($attrs['id'] ?? '')),
            'sku'            => sanitize_text_field((string) ($offer->vendorCode ?? $offer->vendor_code ?? $attrs['id'] ?? '')),
            'name'           => sanitize_text_field((string) ($offer->name ?? $offer->model ?? '')),
            'name_ua'        => sanitize_text_field((string) ($offer->name_ua ?? '')),
            'price'          => $this->parse_decimal((string) ($offer->price ?? '0')),
            'old_price'      => $this->parse_decimal((string) ($offer->oldprice ?? $offer->old_price ?? '0')),
            'currency'       => sanitize_text_field((string) ($offer->currencyId ?? 'UAH')),
            'available'      => $this->resolve_offer_availability($offer, $attrs),
            'quantity'       => $this->extract_offer_quantity($offer),
            'description'    => (string) ($offer->description ?? ''),
            'description_ua' => (string) ($offer->description_ua ?? ''),
            'category_id'    => trim((string) ($offer->categoryId ?? '')),
            'vendor'         => sanitize_text_field((string) ($offer->vendor ?? '')),
            'vendor_code'    => sanitize_text_field((string) ($offer->vendorCode ?? '')),
            'product_url'    => esc_url_raw(trim((string) ($offer->url ?? ''))),
            'pictures'       => $pictures,
            'params'         => $params,
            'group_id'       => sanitize_text_field((string) ($offer->group_id ?? '')),
            'group_name'     => sanitize_text_field((string) ($offer->group_name ?? '')),
        );
    }

    /**
     * Parse an RSS item.
     *
     * @param SimpleXMLElement $item
     * @return array|null
     */
    private function parse_rss_item($item)
    {
        $ns_g      = $item->children('http://base.google.com/ns/1.0');
        $price_raw = trim((string) ($ns_g->price ?? '0'));
        $price     = $this->parse_decimal($price_raw);

        if ($price <= 0) {
            return null;
        }

        $picture = esc_url_raw(trim((string) ($ns_g->image_link ?? $ns_g->additional_image_link ?? '')));

        return array(
            'id'             => trim((string) ($ns_g->id ?? '')),
            'sku'            => sanitize_text_field((string) ($ns_g->mpn ?? $ns_g->id ?? '')),
            'name'           => sanitize_text_field((string) ($item->title ?? '')),
            'name_ua'        => '',
            'price'          => $price,
            'old_price'      => 0.0,
            'currency'       => 'UAH',
            'available'      => strtolower(trim((string) ($ns_g->availability ?? 'in stock'))) === 'in stock',
            'quantity'       => null,
            'description'    => (string) ($item->description ?? ''),
            'description_ua' => '',
            'category_id'    => sanitize_text_field((string) ($ns_g->google_product_category ?? '')),
            'vendor'         => sanitize_text_field((string) ($ns_g->brand ?? '')),
            'vendor_code'    => sanitize_text_field((string) ($ns_g->mpn ?? '')),
            'product_url'    => esc_url_raw(trim((string) ($item->link ?? ''))),
            'pictures'       => $picture ? array($picture) : array(),
            'params'         => array(),
            'group_id'       => '',
            'group_name'     => '',
        );
    }

    /**
     * Parse a generic product node.
     *
     * @param SimpleXMLElement $node
     * @return array
     */
    private function parse_generic_node($node)
    {
        $attrs    = $node->attributes();
        $pictures = array();

        foreach ($node->picture as $picture) {
            $url = esc_url_raw(trim((string) $picture));
            if ($url !== '') {
                $pictures[] = $url;
            }
        }

        $price_raw = (string) ($node->price ?? $node->Price ?? '0');

        return array(
            'id'             => trim((string) ($attrs['id'] ?? $node->id ?? $node->product_id ?? '')),
            'sku'            => sanitize_text_field((string) ($node->sku ?? $node->article ?? $node->vendorCode ?? $attrs['id'] ?? '')),
            'name'           => sanitize_text_field((string) ($node->name ?? $node->title ?? $node->product_name ?? '')),
            'name_ua'        => sanitize_text_field((string) ($node->name_ua ?? '')),
            'price'          => $this->parse_decimal($price_raw),
            'old_price'      => $this->parse_decimal((string) ($node->oldprice ?? $node->old_price ?? '0')),
            'currency'       => sanitize_text_field((string) ($node->currency ?? $node->currencyId ?? 'UAH')),
            'available'      => !in_array(strtolower(trim((string) ($node->available ?? $node->availability ?? 'true'))), array('false', '0', 'no', 'not available'), true),
            'quantity'       => isset($node->quantity) ? (int) $node->quantity : null,
            'description'    => (string) ($node->description ?? ''),
            'description_ua' => (string) ($node->description_ua ?? ''),
            'category_id'    => trim((string) ($node->categoryId ?? $node->category_id ?? $node->category ?? '')),
            'vendor'         => sanitize_text_field((string) ($node->vendor ?? '')),
            'vendor_code'    => sanitize_text_field((string) ($node->vendorCode ?? '')),
            'product_url'    => esc_url_raw(trim((string) ($node->url ?? ''))),
            'pictures'       => $pictures,
            'params'         => array(),
            'group_id'       => sanitize_text_field((string) ($node->group_id ?? '')),
            'group_name'     => sanitize_text_field((string) ($node->group_name ?? '')),
        );
    }

    /**
     * Import or update one WooCommerce product from the normalized offer.
     *
     * @param array  $offer
     * @param array  $feed
     * @param array  $category_map
     * @return string
     */
    private function import_product_from_offer($offer, &$feed, $category_map)
    {
        $skip_reason = $this->get_offer_skip_reason($offer);

        if ($skip_reason !== '') {
            return 'skipped';
        }

        $external_id = trim((string) ($offer['id'] ?? ''));
        $name        = trim((string) ($offer['name'] ?? ''));
        $sku         = trim((string) ($offer['sku'] ?? ''));
        $price       = (float) ($offer['price'] ?? 0);
        $source_url  = $feed['url'];
        $source_key  = $feed['source_key'] ?? $this->build_source_key($source_url);
        $markup_pct  = (float) ($feed['markup'] ?? 0);
        $update_fields = $feed['update_fields'] ?? $this->get_legacy_update_field_map(!empty($feed['update_stock']));

        if ($markup_pct > 0) {
            $price = $price * (1 + ($markup_pct / 100));
        }

        $price     = wc_format_decimal($price, 2);
        $old_price = (float) ($offer['old_price'] ?? 0);
        if ($old_price > 0 && $markup_pct > 0) {
            $old_price = $old_price * (1 + ($markup_pct / 100));
        }
        $old_price = $old_price > 0 ? wc_format_decimal($old_price, 2) : '';

        $woo_id     = 0;
        $is_created = false;
        $has_changes = false;
        $description = wp_kses_post(!empty($offer['description']) ? $offer['description'] : '');
        $stock_status = !empty($offer['available']) ? 'instock' : 'outofstock';
        $manage_stock = $offer['quantity'] !== null ? 'yes' : 'no';
        $stock_qty    = $offer['quantity'] !== null ? (int) $offer['quantity'] : null;
        $vendor       = !empty($offer['vendor']) ? sanitize_text_field($offer['vendor']) : '';
        $product_url  = !empty($offer['product_url']) ? esc_url_raw($offer['product_url']) : '';
        $params_json  = !empty($offer['params']) ? wp_json_encode($offer['params']) : '';
        $external_key = $external_id !== '' ? $this->build_external_product_key($source_key, $external_id) : '';
        $category_id  = trim((string) ($offer['category_id'] ?? ''));
        $target_term_id = ($category_id !== '' && isset($category_map[$category_id])) ? (int) $category_map[$category_id] : 0;
        $image_needs_update = false;

        if ($external_id !== '') {
            $woo_id = $this->find_product_by_external_key($external_key);
        }

        if (!$woo_id && $sku !== '') {
            $woo_id = (int) wc_get_product_id_by_sku($sku);
        }

        if ($woo_id) {
            $existing_post = get_post($woo_id);
            if (!$existing_post || $existing_post->post_type !== 'product') {
                return 'skipped';
            }

            $post_update = array('ID' => $woo_id);
            if (!empty($update_fields['title']) && $name !== '' && $existing_post->post_title !== $name) {
                $post_update['post_title'] = $name;
                $has_changes = true;
            }
            if (!empty($update_fields['description']) && $existing_post->post_content !== $description) {
                $post_update['post_content'] = $description;
                $has_changes = true;
            }

            if (count($post_update) > 1) {
                $result = wp_update_post($post_update, true);
                if (is_wp_error($result)) {
                    return 'Не вдалося оновити товар #' . $woo_id . ': ' . $result->get_error_message();
                }
            }
        } else {
            $postarr = array(
                'post_title'   => $name,
                'post_content' => wp_kses_post(!empty($offer['description']) ? $offer['description'] : ''),
                'post_status'  => 'publish',
                'post_type'    => 'product',
            );

            $woo_id = wp_insert_post($postarr, true);
            if (is_wp_error($woo_id)) {
                return 'Не вдалося створити товар "' . $name . '": ' . $woo_id->get_error_message();
            }

            wp_set_object_terms($woo_id, 'simple', 'product_type');
            $is_created = true;
            $has_changes = true;
            $this->remember_created_product_id($feed, $woo_id);
        }

        if ($is_created || !empty($update_fields['price'])) {
            if ($old_price !== '' && (float) $old_price > (float) $price) {
                $has_changes = $this->sync_post_meta_value($woo_id, '_sale_price', $price) || $has_changes;
                $has_changes = $this->sync_post_meta_value($woo_id, '_regular_price', $old_price) || $has_changes;
                $has_changes = $this->sync_post_meta_value($woo_id, '_price', $price) || $has_changes;
            } else {
                $has_changes = $this->sync_post_meta_value($woo_id, '_regular_price', $price) || $has_changes;
                $has_changes = $this->sync_post_meta_value($woo_id, '_price', $price) || $has_changes;
                $has_changes = $this->delete_post_meta_if_exists($woo_id, '_sale_price') || $has_changes;
            }
        }

        if ($is_created || !empty($update_fields['stock'])) {
            $has_changes = $this->sync_post_meta_value($woo_id, '_stock_status', $stock_status) || $has_changes;
            $has_changes = $this->sync_post_meta_value($woo_id, '_manage_stock', $manage_stock) || $has_changes;

            if ($stock_qty !== null) {
                $has_changes = $this->sync_post_meta_value($woo_id, '_stock', $stock_qty) || $has_changes;
            } else {
                $has_changes = $this->delete_post_meta_if_exists($woo_id, '_stock') || $has_changes;
            }
        }

        if (($is_created || !empty($update_fields['sku'])) && $sku !== '' && (int) wc_get_product_id_by_sku($sku) === (int) $woo_id) {
            $has_changes = $this->sync_post_meta_value($woo_id, '_sku', $sku) || $has_changes;
        } elseif (($is_created || !empty($update_fields['sku'])) && $sku !== '' && !get_post_meta($woo_id, '_sku', true)) {
            $has_changes = $this->sync_post_meta_value($woo_id, '_sku', $sku) || $has_changes;
        }

        if (($is_created || !empty($update_fields['category'])) && $target_term_id > 0) {
            if ($is_created || !$this->product_has_exact_terms($woo_id, 'product_cat', array($target_term_id))) {
                wp_set_object_terms($woo_id, array($target_term_id), 'product_cat', false);
                $has_changes = true;
            }

            $has_changes = $this->sync_post_meta_value($woo_id, self::META_CATEGORY_ID, $category_id) || $has_changes;
        }

        if (($is_created || !empty($update_fields['vendor'])) && $vendor !== '') {
            $has_changes = $this->sync_post_meta_value($woo_id, self::META_VENDOR, $vendor) || $has_changes;
        }

        if (($is_created || !empty($update_fields['product_url'])) && $product_url !== '') {
            $has_changes = $this->sync_post_meta_value($woo_id, self::META_SOURCE_PRODUCT, $product_url) || $has_changes;
        }

        $has_changes = $this->sync_post_meta_value($woo_id, self::META_SOURCE_KEY, $source_key) || $has_changes;
        $has_changes = $this->sync_post_meta_value($woo_id, self::META_SOURCE_URL, esc_url_raw($source_url)) || $has_changes;
        $has_changes = $this->sync_post_meta_value($woo_id, self::META_EXTERNAL_ID, $external_id) || $has_changes;
        $has_changes = $this->sync_post_meta_value($woo_id, self::META_EXTERNAL_KEY, $external_key) || $has_changes;
        $has_changes = $this->sync_post_meta_value($woo_id, self::META_EXTERNAL_SKU, $sku) || $has_changes;

        if (($is_created || !empty($update_fields['params'])) && $params_json !== '') {
            $has_changes = $this->sync_post_meta_value($woo_id, self::META_SOURCE_PARAMS, $params_json) || $has_changes;
        }

        $attribute_vendor = (($is_created || !empty($update_fields['vendor'])) && $vendor !== '') ? $vendor : '';
        $attribute_params = (($is_created || !empty($update_fields['params'])) && !empty($offer['params']) && is_array($offer['params']))
            ? $offer['params']
            : array();

        if ($attribute_vendor !== '' || !empty($attribute_params)) {
            $attribute_sync_result = $this->sync_supplier_product_attributes($woo_id, $attribute_vendor, $attribute_params);

            if (is_wp_error($attribute_sync_result)) {
                return 'Не вдалося синхронізувати WooCommerce-атрибути для товару #' . $woo_id . ': ' . $attribute_sync_result->get_error_message();
            }

            $has_changes = $attribute_sync_result || $has_changes;
        }

        if ($is_created || !empty($update_fields['image'])) {
            $image_needs_update = $this->offer_featured_image_needs_update($woo_id, $offer);
            $has_changes = $image_needs_update || $has_changes;
        }

        if (!$is_created && !$has_changes) {
            return 'skipped';
        }

        if ($is_created || !empty($update_fields['image'])) {
            $this->maybe_import_featured_image($woo_id, $offer);
        }

        $this->sync_post_meta_value($woo_id, self::META_LAST_SYNC, current_time('mysql'));
        wc_delete_product_transients($woo_id);

        return $is_created ? 'created' : 'updated';
    }

    /**
     * Update post meta only when the value actually changed.
     *
     * @param int                 $post_id
     * @param string              $meta_key
     * @param string|int|float    $value
     * @return bool
     */
    private function sync_post_meta_value($post_id, $meta_key, $value)
    {
        $current = get_post_meta($post_id, $meta_key, true);

        if ((string) $current === (string) $value) {
            return false;
        }

        update_post_meta($post_id, $meta_key, $value);
        return true;
    }

    /**
     * Delete post meta only when it exists.
     *
     * @param int    $post_id
     * @param string $meta_key
     * @return bool
     */
    private function delete_post_meta_if_exists($post_id, $meta_key)
    {
        if (!metadata_exists('post', $post_id, $meta_key)) {
            return false;
        }

        delete_post_meta($post_id, $meta_key);
        return true;
    }

    /**
     * Sync supplier brand and params into visible WooCommerce taxonomy attributes.
     *
     * Brand is stored in the dedicated global attribute `pa_brand`.
     * Feed params are converted into global WooCommerce attributes by param name.
     *
     * @param int    $post_id
     * @param string $vendor
     * @param array  $params
     * @return bool|WP_Error
     */
    private function sync_supplier_product_attributes($post_id, $vendor, $params)
    {
        $desired_attributes = array();

        $vendor = sanitize_text_field((string) $vendor);
        if ($vendor !== '') {
            $brand_attribute = $this->get_or_create_global_product_attribute('Бренд', 'brand');
            if (is_wp_error($brand_attribute)) {
                return $brand_attribute;
            }

            $desired_attributes[$brand_attribute['taxonomy']] = array(
                'id'     => (int) $brand_attribute['id'],
                'values' => array($vendor),
            );
        }

        if (!empty($params) && is_array($params)) {
            foreach ($params as $param) {
                $source_param_name = '';
                $param_value = '';

                if (is_array($param)) {
                    $source_param_name  = sanitize_text_field((string) ($param['name'] ?? ''));
                    $param_value = sanitize_text_field((string) ($param['value'] ?? ''));
                }

                if ($source_param_name === '' || $param_value === '') {
                    continue;
                }

                $param_name = $this->normalize_supplier_attribute_label($source_param_name);
                $param_value = $this->normalize_supplier_attribute_value($source_param_name, $param_name, $param_value);

                if ($param_name === '' || $param_value === '') {
                    continue;
                }

                $param_attribute = $this->get_or_create_global_product_attribute($param_name, $source_param_name);
                if (is_wp_error($param_attribute)) {
                    return $param_attribute;
                }

                $taxonomy = $param_attribute['taxonomy'];

                if (!isset($desired_attributes[$taxonomy])) {
                    $desired_attributes[$taxonomy] = array(
                        'id'     => (int) $param_attribute['id'],
                        'values' => array(),
                    );
                }

                $desired_attributes[$taxonomy]['values'][] = $param_value;
            }
        }

        if (empty($desired_attributes)) {
            return false;
        }

        $product_attributes = get_post_meta($post_id, '_product_attributes', true);
        if (!is_array($product_attributes)) {
            $product_attributes = array();
        }

        $attributes_meta_changed = false;
        $terms_changed = false;
        $next_position = $this->get_next_product_attribute_position($product_attributes);

        foreach ($desired_attributes as $taxonomy => $attribute_data) {
            $term_ids = array();
            $values   = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $attribute_data['values']))));

            if (empty($values)) {
                continue;
            }

            foreach ($values as $value) {
                $term_id = $this->get_or_create_attribute_term_id($taxonomy, $value);
                if (is_wp_error($term_id)) {
                    return $term_id;
                }

                $term_ids[] = (int) $term_id;
            }

            $term_ids = array_values(array_unique(array_filter($term_ids)));

            if (empty($term_ids)) {
                continue;
            }

            if (!$this->product_has_exact_terms($post_id, $taxonomy, $term_ids)) {
                $set_terms_result = wp_set_object_terms($post_id, $term_ids, $taxonomy, false);

                if (is_wp_error($set_terms_result)) {
                    return $set_terms_result;
                }

                $terms_changed = true;
            }

            $position = isset($product_attributes[$taxonomy]['position'])
                ? (int) $product_attributes[$taxonomy]['position']
                : $next_position++;

            $attribute_meta = array(
                'name'         => $taxonomy,
                'value'        => '',
                'position'     => $position,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1,
            );

            if (!$this->product_attribute_meta_matches(isset($product_attributes[$taxonomy]) ? $product_attributes[$taxonomy] : array(), $attribute_meta)) {
                $product_attributes[$taxonomy] = $attribute_meta;
                $attributes_meta_changed = true;
            }
        }

        if ($attributes_meta_changed) {
            update_post_meta($post_id, '_product_attributes', $product_attributes);
        }

        return $attributes_meta_changed || $terms_changed;
    }

    /**
     * Create or get a global WooCommerce product attribute.
     *
     * @param string $label
     * @param string $preferred_name
     * @return array|WP_Error
     */
    private function get_or_create_global_product_attribute($label, $preferred_name = '')
    {
        $label = sanitize_text_field((string) $label);

        if ($label === '') {
            return new WP_Error('supplier_attribute_label_empty', 'Порожня назва атрибута');
        }

        $existing_attributes = $this->get_global_product_attributes_index();
        $normalized_label    = $this->normalize_attribute_label($label);
        $preferred_name      = $this->normalize_attribute_name($preferred_name);
        $normalized_name     = $this->normalize_attribute_name($label);

        foreach ($existing_attributes as $attribute) {
            $attribute_name = isset($attribute->attribute_name) ? (string) $attribute->attribute_name : '';

            if ($attribute_name === '') {
                continue;
            }

            if (($preferred_name !== '' && $attribute_name === $preferred_name) || $attribute_name === $normalized_name) {
                $attribute_label = isset($attribute->attribute_label) ? sanitize_text_field((string) $attribute->attribute_label) : $label;
                $attribute_label = $this->maybe_update_global_product_attribute_label(
                    (int) $attribute->attribute_id,
                    $attribute_name,
                    $attribute_label,
                    $label
                );
                $this->ensure_runtime_attribute_taxonomy_registered($attribute_name, $attribute_label);

                return array(
                    'id'       => (int) $attribute->attribute_id,
                    'name'     => $attribute_name,
                    'label'    => $attribute_label,
                    'taxonomy' => wc_attribute_taxonomy_name($attribute_name),
                );
            }
        }

        foreach ($existing_attributes as $attribute) {
            $attribute_label = isset($attribute->attribute_label) ? sanitize_text_field((string) $attribute->attribute_label) : '';
            if ($attribute_label !== '' && $this->normalize_attribute_label($attribute_label) === $normalized_label) {
                $attribute_label = $this->maybe_update_global_product_attribute_label(
                    (int) $attribute->attribute_id,
                    (string) $attribute->attribute_name,
                    $attribute_label,
                    $label
                );
                $this->ensure_runtime_attribute_taxonomy_registered($attribute->attribute_name, $attribute_label);

                return array(
                    'id'       => (int) $attribute->attribute_id,
                    'name'     => (string) $attribute->attribute_name,
                    'label'    => $attribute_label,
                    'taxonomy' => wc_attribute_taxonomy_name($attribute->attribute_name),
                );
            }
        }

        $attribute_name = $this->build_unique_attribute_name($label, $preferred_name, $existing_attributes);
        $attribute_id   = wc_create_attribute(array(
            'name'         => $label,
            'slug'         => $attribute_name,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ));

        if (is_wp_error($attribute_id)) {
            return $attribute_id;
        }

        $this->invalidate_global_attribute_cache();
        $this->ensure_runtime_attribute_taxonomy_registered($attribute_name, $label);

        return array(
            'id'       => (int) $attribute_id,
            'name'     => $attribute_name,
            'label'    => $label,
            'taxonomy' => wc_attribute_taxonomy_name($attribute_name),
        );
    }

    /**
     * Update a global WooCommerce attribute label in place when we know a better canonical label.
     *
     * @param int    $attribute_id
     * @param string $attribute_name
     * @param string $current_label
     * @param string $desired_label
     * @return string
     */
    private function maybe_update_global_product_attribute_label($attribute_id, $attribute_name, $current_label, $desired_label)
    {
        $attribute_id = (int) $attribute_id;
        $attribute_name = sanitize_text_field((string) $attribute_name);
        $current_label = sanitize_text_field((string) $current_label);
        $desired_label = sanitize_text_field((string) $desired_label);

        if ($attribute_id <= 0 || $attribute_name === '' || $desired_label === '' || $current_label === $desired_label) {
            return $current_label !== '' ? $current_label : $desired_label;
        }

        if (function_exists('wc_update_attribute')) {
            $result = wc_update_attribute($attribute_id, array(
                'name'         => $desired_label,
                'slug'         => $attribute_name,
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ));

            if (!is_wp_error($result)) {
                $this->invalidate_global_attribute_cache();
                return $desired_label;
            }
        }

        return $current_label !== '' ? $current_label : $desired_label;
    }

    /**
     * Return global WooCommerce attributes from API and DB.
     *
     * @return array
     */
    private function get_global_product_attributes_index()
    {
        global $wpdb;

        $attributes = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : array();
        if (!is_array($attributes)) {
            $attributes = array();
        }

        $attribute_index = array();

        foreach ($attributes as $attribute) {
            if (empty($attribute->attribute_name)) {
                continue;
            }

            $attribute_index[(string) $attribute->attribute_name] = $attribute;
        }

        $table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
        $db_attributes = $wpdb->get_results("SELECT attribute_id, attribute_name, attribute_label FROM {$table} ORDER BY attribute_name ASC");

        if (is_array($db_attributes)) {
            foreach ($db_attributes as $db_attribute) {
                if (empty($db_attribute->attribute_name)) {
                    continue;
                }

                if (!isset($attribute_index[(string) $db_attribute->attribute_name])) {
                    $attribute_index[(string) $db_attribute->attribute_name] = $db_attribute;
                }
            }
        }

        return array_values($attribute_index);
    }

    /**
     * Build a stable WooCommerce attribute name limited to 28 chars.
     *
     * @param string $label
     * @param string $preferred_name
     * @param array  $existing_attributes
     * @return string
     */
    private function build_unique_attribute_name($label, $preferred_name, $existing_attributes)
    {
        $base_name = $this->normalize_attribute_name($preferred_name !== '' ? $preferred_name : $label);

        if ($base_name === '') {
            $base_name = 'attr-' . substr(md5($label), 0, 8);
        }

        $base_name = substr($base_name, 0, 28);
        $normalized_label = $this->normalize_attribute_label($label);

        foreach ($existing_attributes as $attribute) {
            $attribute_name  = isset($attribute->attribute_name) ? (string) $attribute->attribute_name : '';
            $attribute_label = isset($attribute->attribute_label) ? sanitize_text_field((string) $attribute->attribute_label) : '';

            if ($attribute_name === $base_name && $this->normalize_attribute_label($attribute_label) === $normalized_label) {
                return $base_name;
            }
        }

        $existing_names = array();
        foreach ($existing_attributes as $attribute) {
            if (!empty($attribute->attribute_name)) {
                $existing_names[(string) $attribute->attribute_name] = true;
            }
        }

        if (!isset($existing_names[$base_name])) {
            return $base_name;
        }

        $suffix = substr(md5($label), 0, 6);
        $trimmed = substr($base_name, 0, max(1, 28 - strlen($suffix) - 1));

        return $trimmed . '-' . $suffix;
    }

    /**
     * Normalize raw attribute names to Woo-friendly ASCII slugs.
     *
     * @param string $value
     * @return string
     */
    private function normalize_attribute_name($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/^pa[_-]/i', '', $value);
        $value = sanitize_title($value);
        $value = preg_replace('/^pa-+/i', '', (string) $value);
        $value = str_replace('-', '_', (string) $value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', (string) $value);
        $value = preg_replace('/_+/', '_', (string) $value);

        return trim((string) $value, '_');
    }

    /**
     * Normalize supplier attribute labels to one canonical Ukrainian label set.
     *
     * @param string $label
     * @return string
     */
    private function normalize_supplier_attribute_label($label)
    {
        $label = sanitize_text_field((string) $label);
        if ($label === '') {
            return '';
        }

        $lookup = $this->normalize_supplier_attribute_lookup_key($label);
        $map = array(
            'гарантийный срок' => 'Гарантійний термін',
            'тип аккумулятора' => 'Тип акумулятора',
            'напряжение' => 'Напруга',
            'емкость аккумулятора' => 'Ємність акумулятора',
            'тип токовывода' => 'Тип струмовиводу',
            'материал изготовления корпуса' => 'Матеріал виготовлення корпусу',
            'выходное напряжение' => 'Вихідна напруга',
            'выходная мощность' => 'Вихідна потужність',
            'напряжение аккумулятора' => 'Напруга акумулятора',
            'время переключения' => 'Час перемикання',
            'вес' => 'Вага',
            'индикация' => 'Індикація',
            'количество выходных розеток' => 'Кількість вихідних розеток',
            'цвет корпуса' => 'Колір корпусу',
            'форма выходного напряжения' => 'Форма вихідної напруги',
            'живлення' => 'Живлення',
            'цена за' => 'Ціна за',
            'колiр' => 'Колір',
            'тип ибп' => 'Тип ДБЖ',
        );

        if (isset($map[$lookup])) {
            return $map[$lookup];
        }

        return $label;
    }

    /**
     * Normalize supplier attribute values to one canonical Ukrainian value set.
     *
     * @param string $source_label
     * @param string $normalized_label
     * @param string $value
     * @return string
     */
    private function normalize_supplier_attribute_value($source_label, $normalized_label, $value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }

        $exact_map = array(
            'белый' => 'білий',
            'черный' => 'чорний',
            'металл' => 'метал',
            'есть' => 'є',
            'фиксированный' => 'фіксований',
            'линейно-интерактивный с правильной синусоидой' => 'лінійно-інтерактивний з правильною синусоїдою',
            'правильная синусоида' => 'правильна синусоїда',
            'гелевый' => 'гелевий',
            'мультигелевый' => 'мультигелевий',
            'свинцово-кислотный' => 'свинцево-кислотний',
        );

        $lookup = $this->normalize_supplier_attribute_lookup_key($value);
        if (isset($exact_map[$lookup])) {
            return $exact_map[$lookup];
        }

        $replacements = array(
            'линейно-интерактивный' => 'лінійно-інтерактивний',
            'правильная синусоида' => 'правильна синусоїда',
            'под болт' => 'під болт',
            'белый' => 'білий',
            'черный' => 'чорний',
            'металл' => 'метал',
            'свинцово-кислотный' => 'свинцево-кислотний',
            'мультигелевый' => 'мультигелевий',
            'гелевый' => 'гелевий',
            'кислотный' => 'кислотний',
        );

        $normalized_value = $value;
        foreach ($replacements as $search => $replace) {
            $normalized_value = preg_replace('/' . preg_quote($search, '/') . '/iu', $replace, $normalized_value);
        }

        return sanitize_text_field((string) $normalized_value);
    }

    /**
     * Build a stable lowercase lookup key for supplier labels and values.
     *
     * @param string $value
     * @return string
     */
    private function normalize_supplier_attribute_lookup_key($value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }

        $value = str_replace(array('ё', 'i', 'ї', 'ґ'), array('е', 'і', 'і', 'г'), $value);
        $value = preg_replace('/\s+/u', ' ', (string) $value);

        return trim((string) $value);
    }

    /**
     * Normalize labels for case-insensitive comparisons.
     *
     * @param string $value
     * @return string
     */
    private function normalize_attribute_label($value)
    {
        $value = sanitize_text_field((string) $value);

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    /**
     * Ensure the product attribute taxonomy is available in the current request.
     *
     * @param string $attribute_name
     * @param string $label
     * @return void
     */
    private function ensure_runtime_attribute_taxonomy_registered($attribute_name, $label)
    {
        $attribute_name = $this->normalize_attribute_name($attribute_name);

        if ($attribute_name === '') {
            return;
        }

        $taxonomy = wc_attribute_taxonomy_name($attribute_name);

        if (taxonomy_exists($taxonomy)) {
            return;
        }

        $label = sanitize_text_field((string) $label);

        register_taxonomy(
            $taxonomy,
            apply_filters('woocommerce_taxonomy_objects_' . $taxonomy, array('product')),
            apply_filters('woocommerce_taxonomy_args_' . $taxonomy, array(
                'hierarchical'          => false,
                'update_count_callback' => '_update_post_term_count',
                'labels'                => array(
                    'name' => $label !== '' ? $label : ucfirst(str_replace('-', ' ', $attribute_name)),
                ),
                'show_ui'               => false,
                'show_in_quick_edit'    => false,
                'show_admin_column'     => false,
                'query_var'             => true,
                'rewrite'               => false,
                'sort'                  => false,
                'public'                => false,
            ))
        );
    }

    /**
     * Clear WooCommerce attribute caches after creating a new global attribute.
     *
     * @return void
     */
    private function invalidate_global_attribute_cache()
    {
        delete_transient('wc_attribute_taxonomies');

        if (class_exists('WC_Cache_Helper') && method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
            WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
        }
    }

    /**
     * Get or create a term inside an attribute taxonomy.
     *
     * @param string $taxonomy
     * @param string $value
     * @return int|WP_Error
     */
    private function get_or_create_attribute_term_id($taxonomy, $value)
    {
        $taxonomy = sanitize_key((string) $taxonomy);
        $value    = sanitize_text_field((string) $value);

        if ($taxonomy === '' || $value === '') {
            return new WP_Error('supplier_attribute_term_invalid', 'Порожня таксономія або значення терма');
        }

        $existing_term = term_exists($value, $taxonomy);

        if (is_array($existing_term) && !empty($existing_term['term_id'])) {
            return (int) $existing_term['term_id'];
        }

        if (is_numeric($existing_term)) {
            return (int) $existing_term;
        }

        $created_term = wp_insert_term($value, $taxonomy);

        if (is_wp_error($created_term)) {
            $existing_term = term_exists($value, $taxonomy);

            if (is_array($existing_term) && !empty($existing_term['term_id'])) {
                return (int) $existing_term['term_id'];
            }

            if (is_numeric($existing_term)) {
                return (int) $existing_term;
            }

            return $created_term;
        }

        return (int) $created_term['term_id'];
    }

    /**
     * Find the next free position for product attribute ordering.
     *
     * @param array $product_attributes
     * @return int
     */
    private function get_next_product_attribute_position($product_attributes)
    {
        $max_position = -1;

        foreach ((array) $product_attributes as $product_attribute) {
            $position = isset($product_attribute['position']) ? (int) $product_attribute['position'] : 0;
            if ($position > $max_position) {
                $max_position = $position;
            }
        }

        return $max_position + 1;
    }

    /**
     * Compare product attribute meta rows in normalized form.
     *
     * @param array $current
     * @param array $expected
     * @return bool
     */
    private function product_attribute_meta_matches($current, $expected)
    {
        $normalized_current = array(
            'name'         => isset($current['name']) ? (string) $current['name'] : '',
            'value'        => isset($current['value']) ? (string) $current['value'] : '',
            'position'     => isset($current['position']) ? (int) $current['position'] : 0,
            'is_visible'   => !empty($current['is_visible']) ? 1 : 0,
            'is_variation' => !empty($current['is_variation']) ? 1 : 0,
            'is_taxonomy'  => !empty($current['is_taxonomy']) ? 1 : 0,
        );

        $normalized_expected = array(
            'name'         => (string) $expected['name'],
            'value'        => (string) $expected['value'],
            'position'     => (int) $expected['position'],
            'is_visible'   => !empty($expected['is_visible']) ? 1 : 0,
            'is_variation' => !empty($expected['is_variation']) ? 1 : 0,
            'is_taxonomy'  => !empty($expected['is_taxonomy']) ? 1 : 0,
        );

        return $normalized_current === $normalized_expected;
    }

    /**
     * Check whether the product already has exactly the expected term set.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @param array  $term_ids
     * @return bool
     */
    private function product_has_exact_terms($post_id, $taxonomy, $term_ids)
    {
        $current_term_ids = wp_get_object_terms($post_id, $taxonomy, array(
            'fields' => 'ids',
        ));

        if (is_wp_error($current_term_ids)) {
            return false;
        }

        $current_term_ids = array_map('intval', $current_term_ids);
        $term_ids         = array_map('intval', $term_ids);

        sort($current_term_ids);
        sort($term_ids);

        return $current_term_ids === $term_ids;
    }

    /**
     * Check whether featured image update would change the product.
     *
     * @param int   $woo_id
     * @param array $offer
     * @return bool
     */
    private function offer_featured_image_needs_update($woo_id, $offer)
    {
        if (empty($offer['pictures']) || !is_array($offer['pictures'])) {
            return false;
        }

        $image_url = esc_url_raw((string) reset($offer['pictures']));
        if ($image_url === '' || strpos($image_url, 'http') !== 0) {
            return false;
        }

        $existing_url = get_post_meta($woo_id, self::META_SOURCE_IMAGE, true);
        $existing_id  = get_post_thumbnail_id($woo_id);

        if ($existing_url === $image_url && $existing_id) {
            return false;
        }

        if ($existing_id && $existing_url && $existing_url !== $image_url) {
            return false;
        }

        return true;
    }

    /**
     * Sync category tree from the supplier feed.
     *
     * @param array  $categories
     * @param array  $feed
     * @return array
     */
    private function sync_categories($categories, &$feed)
    {
        if (empty($categories)) {
            return array();
        }

        $term_map = array();
        $root_term_id = $this->get_or_create_supplier_root_term($feed);

        foreach (array_keys($categories) as $external_id) {
            $this->ensure_category_term($external_id, $categories, $feed, $term_map, $root_term_id);
        }

        return $term_map;
    }

    /**
     * Sync only categories that are actually reachable by importable offers.
     *
     * This prevents creating empty category branches for offers that will be
     * skipped later because of missing price, availability, or image.
     *
     * @param array $categories
     * @param array $offers
     * @param array $feed
     * @return array
     */
    private function sync_offer_categories($categories, $offers, &$feed)
    {
        if (empty($categories) || empty($offers)) {
            return array();
        }

        $required_category_ids = $this->collect_required_category_ids($categories, $offers);
        if (empty($required_category_ids)) {
            return array();
        }

        $filtered_categories = array();

        foreach ($required_category_ids as $category_id) {
            if (!isset($categories[$category_id])) {
                continue;
            }

            $filtered_categories[$category_id] = $categories[$category_id];
        }

        return $this->sync_categories($filtered_categories, $feed);
    }

    /**
     * Collect category IDs referenced by importable offers, including ancestors.
     *
     * @param array $categories
     * @param array $offers
     * @return array
     */
    private function collect_required_category_ids($categories, $offers)
    {
        $required = array();

        foreach ($offers as $offer) {
            if ($this->get_offer_skip_reason($offer) !== '') {
                continue;
            }

            $category_id = trim((string) ($offer['category_id'] ?? ''));
            if ($category_id === '' || !isset($categories[$category_id])) {
                continue;
            }

            $this->append_category_lineage($category_id, $categories, $required);
        }

        return array_keys($required);
    }

    /**
     * Add one category and all its parents to the required sync set.
     *
     * @param string $category_id
     * @param array  $categories
     * @param array  $required
     * @return void
     */
    private function append_category_lineage($category_id, $categories, &$required)
    {
        if ($category_id === '' || isset($required[$category_id]) || !isset($categories[$category_id])) {
            return;
        }

        $required[$category_id] = true;

        $parent_id = trim((string) ($categories[$category_id]['parent_id'] ?? ''));
        if ($parent_id !== '' && isset($categories[$parent_id])) {
            $this->append_category_lineage($parent_id, $categories, $required);
        }
    }

    /**
     * Ensure one category and its parents exist in WooCommerce.
     *
     * @param string $external_id
     * @param array  $categories
     * @param array  $feed
     * @param array  $term_map
     * @param int    $root_term_id
     * @return int
     */
    private function ensure_category_term($external_id, $categories, &$feed, &$term_map, $root_term_id = 0)
    {
        if (isset($term_map[$external_id])) {
            return (int) $term_map[$external_id];
        }

        if (empty($categories[$external_id])) {
            return 0;
        }

        $category       = $categories[$external_id];
        $parent_term_id = (int) $root_term_id;
        $source_key     = $feed['source_key'] ?? $this->build_source_key($feed['url']);

        if (!empty($category['parent_id']) && isset($categories[$category['parent_id']])) {
            $parent_term_id = $this->ensure_category_term($category['parent_id'], $categories, $feed, $term_map, $root_term_id);
        }

        $term_id = $this->find_term_by_external_category($source_key, $external_id);
        if (!$term_id) {
            $existing = term_exists($category['name'], 'product_cat', $parent_term_id);
            if ($existing && !is_wp_error($existing)) {
                $term_id = (int) (is_array($existing) ? $existing['term_id'] : $existing);
            } else {
                $created = wp_insert_term($category['name'], 'product_cat', array(
                    'parent' => $parent_term_id,
                ));

                if (is_wp_error($created)) {
                    return 0;
                }

                $term_id = (int) $created['term_id'];
                $this->remember_created_term_id($feed, $term_id);
            }
        } else {
            wp_update_term($term_id, 'product_cat', array(
                'name'   => $category['name'],
                'parent' => $parent_term_id,
            ));
        }

        update_term_meta($term_id, self::TERM_META_SOURCE_KEY, $source_key);
        update_term_meta($term_id, self::TERM_META_CATEGORY_ID, $external_id);
        update_term_meta($term_id, self::TERM_META_CATEGORY_KEY, $this->build_external_category_key($source_key, $external_id));

        $term_map[$external_id] = $term_id;

        return $term_id;
    }

    /**
     * Import the first source image as featured image.
     *
     * @param int   $woo_id
     * @param array $offer
     * @return void
     */
    private function maybe_import_featured_image($woo_id, $offer)
    {
        if (empty($offer['pictures']) || !is_array($offer['pictures'])) {
            return;
        }

        $image_url = esc_url_raw((string) reset($offer['pictures']));
        if ($image_url === '' || strpos($image_url, 'http') !== 0) {
            return;
        }

        $existing_url = get_post_meta($woo_id, self::META_SOURCE_IMAGE, true);
        $existing_id  = get_post_thumbnail_id($woo_id);
        if ($existing_url === $image_url && $existing_id) {
            return;
        }

        if ($existing_id && $existing_url && $existing_url !== $image_url) {
            return;
        }

        $attachment_id = $this->upload_image_from_url($image_url, $woo_id);
        if ($attachment_id) {
            set_post_thumbnail($woo_id, $attachment_id);
            update_post_meta($woo_id, self::META_SOURCE_IMAGE, $image_url);
        }
    }

    /**
     * Upload image from remote URL.
     *
     * @param string $url
     * @param int    $parent_post_id
     * @return int|false
     */
    private function upload_image_from_url($url, $parent_post_id = 0)
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp_file = wp_tempnam($url);
        if (!$tmp_file) {
            return false;
        }

        $ch = curl_init($url);
        $fp = fopen($tmp_file, 'wb');

        curl_setopt_array($ch, array(
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'WordPress/' . get_bloginfo('version'),
        ));

        curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($curl_error || $http_code < 200 || $http_code >= 300) {
            @unlink($tmp_file);
            return false;
        }

        $file_array = array(
            'name'     => basename(parse_url($url, PHP_URL_PATH)) ?: 'supplier-image.jpg',
            'tmp_name' => $tmp_file,
        );

        $attachment_id = media_handle_sideload($file_array, $parent_post_id);
        @unlink($tmp_file);

        if (is_wp_error($attachment_id)) {
            return false;
        }

        return $attachment_id;
    }

    /**
     * Decide whether the offer is allowed into the supplier import.
     *
     * @param array $offer
     * @return string Empty string when import is allowed, otherwise a human-readable skip reason.
     */
    private function get_offer_skip_reason($offer)
    {
        $name  = trim((string) ($offer['name'] ?? ''));
        $price = (float) ($offer['price'] ?? 0);

        if ($name === '' || $price <= 0) {
            return 'пропуск через порожню назву або ціну';
        }

        if (empty($offer['available'])) {
            return 'пропуск через відсутність товару в наявності';
        }

        if (!$this->offer_has_valid_image($offer)) {
            return 'пропуск через відсутність картинки';
        }

        return '';
    }

    /**
     * Check whether the offer has at least one usable remote image URL.
     *
     * @param array $offer
     * @return bool
     */
    private function offer_has_valid_image($offer)
    {
        if (empty($offer['pictures']) || !is_array($offer['pictures'])) {
            return false;
        }

        foreach ($offer['pictures'] as $picture) {
            $image_url = esc_url_raw(trim((string) $picture));
            if ($image_url !== '' && strpos($image_url, 'http') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether Action Scheduler functions are available.
     *
     * @return bool
     */
    private function supports_background_import()
    {
        return function_exists('as_enqueue_async_action');
    }

    /**
     * Compute a soft deadline for one import step.
     *
     * @param array $state
     * @return float
     */
    private function get_import_step_deadline($state)
    {
        if (($state['mode'] ?? '') !== 'background') {
            return 0;
        }

        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0) {
            $limit = max(10, min(self::BACKGROUND_STEP_SOFT_LIMIT, $limit - 5));
        } else {
            $limit = self::BACKGROUND_STEP_SOFT_LIMIT;
        }

        return microtime(true) + $limit;
    }

    /**
     * Decide whether the current import step should yield before timeout.
     *
     * @param array $state
     * @param float $deadline
     * @return bool
     */
    private function should_yield_import_step($state, $deadline)
    {
        if (($state['mode'] ?? '') !== 'background' || $deadline <= 0) {
            return false;
        }

        return microtime(true) >= $deadline;
    }

    /**
     * Persist the current background stage before a long-running step.
     *
     * @param array  $state
     * @param string $stage
     * @param string $message
     * @return void
     */
    private function checkpoint_background_stage(&$state, $stage, $message = '')
    {
        if (($state['mode'] ?? '') !== 'background') {
            return;
        }

        $state['status']        = 'running';
        $state['current_stage'] = sanitize_key((string) $stage);
        $state['updated_at']    = current_time('mysql');

        if ($message !== '') {
            $state['last_batch'] = array_merge(
                isset($state['last_batch']) && is_array($state['last_batch']) ? $state['last_batch'] : array(),
                array(
                    'message' => sanitize_text_field($message),
                )
            );
        }

        $this->save_background_import_state($state);
    }

    /**
     * Build initial shared import state.
     *
     * @param string $mode
     * @param int    $batch_size
     * @return array
     */
    private function build_import_state($mode, $batch_size, $feeds = null, $options = array())
    {
        $feeds = is_array($feeds) ? array_values($feeds) : $this->get_enabled_feeds();
        $first_feed = !empty($feeds[0]) ? $feeds[0] : array();
        $scope_mode = count($feeds) === 1 ? 'single' : 'all';
        $max_items_per_feed = max(0, (int) ($options['max_items_per_feed'] ?? 0));
        $selection_mode = sanitize_key((string) ($options['selection_mode'] ?? ''));

        return array(
            'status'             => $mode === 'background' ? 'queued' : 'running',
            'mode'               => $mode,
            'feeds'              => $feeds,
            'scope_mode'         => $scope_mode,
            'scope_feed_urls'    => $this->extract_feed_urls($feeds),
            'scope_label'        => $this->build_import_scope_label($feeds, $max_items_per_feed, $selection_mode),
            'results'            => $this->build_empty_import_results($feeds),
            'current_feed'       => 0,
            'current_offset'     => 0,
            'batch_size'         => max(1, (int) $batch_size),
            'max_items_per_feed' => $max_items_per_feed,
            'selection_mode'     => $selection_mode,
            'auto_continue'      => $mode === 'background',
            'auto_continue_attempts' => 0,
            'auto_continue_progress_key' => '0:0',
            'started_at'         => current_time('mysql'),
            'updated_at'         => current_time('mysql'),
            'completed_at'       => '',
            'action_id'          => 0,
            'last_batch'         => array(),
            'cumulative'         => array('created' => 0, 'updated' => 0, 'skipped' => 0),
            'current_stage'      => $mode === 'background' ? 'queued' : 'preparing',
            'current_feed_url'   => $first_feed['url'] ?? '',
            'current_feed_label' => $first_feed['label'] ?? '',
        );
    }

    /**
     * Build initial background import state.
     *
     * @param int $batch_size
     * @return array
     */
    private function build_background_import_state($batch_size, $feeds = null, $options = array())
    {
        return $this->build_import_state('background', $batch_size, $feeds, $options);
    }

    /**
     * Load current background import state.
     *
     * @return array
     */
    private function get_background_import_state()
    {
        $state = get_option(self::BACKGROUND_IMPORT_OPTION, array());
        return is_array($state) ? $state : array();
    }

    /**
     * Persist background import state.
     *
     * @param array $state
     * @return void
     */
    private function save_background_import_state($state)
    {
        update_option(self::BACKGROUND_IMPORT_OPTION, $state, false);
    }

    /**
     * Resolve which feeds should participate in this run.
     *
     * @param array|null $requested_feeds
     * @return array
     */
    private function resolve_requested_feeds($requested_feeds = null)
    {
        if (!is_array($requested_feeds) || empty($requested_feeds)) {
            return $this->get_enabled_feeds();
        }

        $feeds = array();
        $enabled_feeds = $this->get_enabled_feeds();
        $enabled_by_url = array();

        foreach ($enabled_feeds as $enabled_feed) {
            $enabled_url = trim((string) ($enabled_feed['url'] ?? ''));
            if ($enabled_url !== '') {
                $enabled_by_url[$enabled_url] = $enabled_feed;
            }
        }

        foreach ($requested_feeds as $feed) {
            if (is_string($feed)) {
                $url = esc_url_raw(trim($feed));
                if ($url === '') {
                    continue;
                }

                if (isset($enabled_by_url[$url])) {
                    $feeds[] = $enabled_by_url[$url];
                    continue;
                }

                $feeds[] = $this->build_feed_config(
                    array(
                        'url' => $url,
                        'enabled' => true,
                    )
                );
                continue;
            }

            if (!is_array($feed)) {
                continue;
            }

            $feed = array_merge(
                array(
                    'enabled' => true,
                ),
                $feed
            );

            $config = $this->build_feed_config($feed);
            if (empty($config['url'])) {
                continue;
            }

            if (isset($enabled_by_url[$config['url']])) {
                $config = array_merge($enabled_by_url[$config['url']], $config);
                $config['enabled'] = true;
            }

            $feeds[] = $config;
        }

        return $feeds;
    }

    /**
     * Extract feed URLs from a normalized feed list.
     *
     * @param array $feeds
     * @return array
     */
    private function extract_feed_urls($feeds)
    {
        $urls = array();

        foreach ($feeds as $feed) {
            $url = trim((string) ($feed['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Build a short human-readable label for the selected import scope.
     *
     * @param array $feeds
     * @return string
     */
    private function build_import_scope_label($feeds, $max_items_per_feed = 0, $selection_mode = '')
    {
        $suffix = $max_items_per_feed > 0 ? ' • тест ' . $max_items_per_feed . ' товар(и)' : '';
        if ($max_items_per_feed > 0 && $selection_mode === 'nonempty_params') {
            $suffix .= ' з характеристиками';
        }

        if (count($feeds) === 1) {
            return sanitize_text_field((string) (($feeds[0]['label'] ?? '') ?: $this->derive_supplier_label($feeds[0]['url'] ?? ''))) . $suffix;
        }

        return 'Усі увімкнені фіди' . $suffix;
    }

    /**
     * Check whether saved background state matches the requested feed scope.
     *
     * @param array $state
     * @param array $requested_feeds
     * @return bool
     */
    private function background_state_matches_requested_scope($state, $requested_feeds, $options = array())
    {
        if (empty($state) || !is_array($state)) {
            return false;
        }

        $state_urls = isset($state['scope_feed_urls']) && is_array($state['scope_feed_urls'])
            ? $state['scope_feed_urls']
            : $this->extract_feed_urls($state['feeds'] ?? array());
        $requested_urls = $this->extract_feed_urls($requested_feeds);

        sort($state_urls);
        sort($requested_urls);

        if ($state_urls !== $requested_urls) {
            return false;
        }

        $requested_limit = max(0, (int) ($options['max_items_per_feed'] ?? 0));
        $state_limit = max(0, (int) ($state['max_items_per_feed'] ?? 0));
        $requested_selection_mode = sanitize_key((string) ($options['selection_mode'] ?? ''));
        $state_selection_mode = sanitize_key((string) ($state['selection_mode'] ?? ''));

        return $requested_limit === $state_limit && $requested_selection_mode === $state_selection_mode;
    }

    /**
     * Decide whether the previous background import can be resumed.
     *
     * @param array $state
     * @return bool
     */
    private function can_resume_background_import($state)
    {
        if (empty($state) || !is_array($state)) {
            return false;
        }

        if (!in_array((string) ($state['status'] ?? ''), array('failed', 'cancelled'), true)) {
            return false;
        }

        $feeds = isset($state['feeds']) && is_array($state['feeds']) ? $state['feeds'] : array();
        if (empty($feeds)) {
            return false;
        }

        $current_feed   = (int) ($state['current_feed'] ?? 0);
        $current_offset = (int) ($state['current_offset'] ?? 0);

        if ($current_feed < count($feeds)) {
            return true;
        }

        return $current_offset > 0;
    }

    /**
     * Put a resumable background import back into the queue.
     *
     * @param array $state
     * @param string $message
     * @param bool|null $auto_continue
     * @return array
     */
    private function queue_background_import_resume($state, $message, $auto_continue = null)
    {
        $state['status']           = 'queued';
        $state['current_stage']    = 'queued';
        $state['completed_at']     = '';
        $state['updated_at']       = current_time('mysql');
        $state['cancel_requested'] = false;
        $state['auto_continue']    = $auto_continue === null ? !empty($state['auto_continue']) : !empty($auto_continue);
        $state['batch_size']       = $this->determine_background_batch_size(
            (int) ($state['batch_size'] ?? 25),
            $state['feeds'] ?? array(),
            true
        );
        $state['last_batch']       = array_merge(
            isset($state['last_batch']) && is_array($state['last_batch']) ? $state['last_batch'] : array(),
            array(
                'message' => (string) $message,
            )
        );
        $state['action_id'] = $this->schedule_background_import_step();
        $this->refresh_background_watchdog($state);
        $this->save_background_import_state($state);

        return $state;
    }

    /**
     * Build the current feed:offset key for auto-continue retries.
     *
     * @param array $state
     * @return string
     */
    private function get_background_progress_key($state)
    {
        return (int) ($state['current_feed'] ?? 0) . ':' . (int) ($state['current_offset'] ?? 0);
    }

    /**
     * Reset auto-continue attempts after forward progress.
     *
     * @param array $state
     * @return void
     */
    private function reset_background_auto_continue_attempts(&$state)
    {
        $state['auto_continue_attempts'] = 0;
        $state['auto_continue_progress_key'] = $this->get_background_progress_key($state);
    }

    /**
     * Reserve one auto-continue retry slot.
     *
     * @param array $state
     * @return bool
     */
    private function reserve_background_auto_continue_attempt(&$state)
    {
        $progress_key = $this->get_background_progress_key($state);

        if (($state['auto_continue_progress_key'] ?? '') !== $progress_key) {
            $state['auto_continue_progress_key'] = $progress_key;
            $state['auto_continue_attempts'] = 0;
        }

        $attempts = (int) ($state['auto_continue_attempts'] ?? 0) + 1;
        if ($attempts > self::BACKGROUND_AUTO_CONTINUE_LIMIT) {
            return false;
        }

        $state['auto_continue_attempts'] = $attempts;
        return true;
    }

    /**
     * Decide whether the failed run should try to continue automatically.
     *
     * @param array $state
     * @return bool
     */
    private function should_auto_continue_background_import($state)
    {
        return !empty($state['auto_continue']) && $this->can_resume_background_import($state);
    }

    /**
     * Schedule automatic continuation after a failed background step.
     *
     * @param array $state
     * @param string $message
     * @return array|null
     */
    private function maybe_auto_continue_background_import($state, $message)
    {
        if (!$this->should_auto_continue_background_import($state)) {
            return null;
        }

        if (!$this->reserve_background_auto_continue_attempt($state)) {
            $state['last_batch'] = array_merge(
                isset($state['last_batch']) && is_array($state['last_batch']) ? $state['last_batch'] : array(),
                array(
                    'message' => (string) $message . ' Автопродовження зупинено після ' . self::BACKGROUND_AUTO_CONTINUE_LIMIT . ' спроб на тому ж місці.',
                )
            );
            return $state;
        }

        return $this->queue_background_import_resume(
            $state,
            (string) $message . ' Автопродовження спробуємо з цього ж місця.',
            true
        );
    }

    /**
     * Pick a safer batch size for background supplier import.
     *
     * @param int        $requested
     * @param array|null $feeds
     * @param bool       $is_resume
     * @return int
     */
    private function determine_background_batch_size($requested, $feeds = null, $is_resume = false)
    {
        $requested = max(1, (int) $requested);
        $feeds      = is_array($feeds) ? $feeds : $this->get_enabled_feeds();
        $batch_size = min(25, $requested);

        foreach ($feeds as $feed) {
            $fields = $feed['update_fields'] ?? array();

            if (!empty($fields['image']) || !empty($fields['description']) || !empty($fields['params']) || !empty($fields['title'])) {
                $batch_size = min($batch_size, 8);
                continue;
            }

            if (!empty($fields['category']) || !empty($fields['vendor']) || !empty($fields['sku']) || !empty($fields['product_url'])) {
                $batch_size = min($batch_size, 10);
                continue;
            }

            $batch_size = min($batch_size, 15);
        }

        if ($is_resume) {
            $batch_size = min($batch_size, max(5, (int) floor($requested / 2)));
        }

        return max(5, $batch_size);
    }

    /**
     * Normalize persisted background state if queue has already died or been cancelled.
     *
     * @param array $state
     * @return array
     */
    private function reconcile_background_import_state($state)
    {
        if (empty($state) || !is_array($state)) {
            return array();
        }

        $status = (string) ($state['status'] ?? '');

        if ($status === 'cancelled') {
            if (!$this->has_active_background_import_action()) {
                $state['action_id'] = 0;
                $this->save_background_import_state($state);
            }

            $this->unschedule_background_watchdog();

            return $state;
        }

        if ($status === 'completed') {
            $this->unschedule_background_watchdog();
            return $state;
        }

        if (!in_array($status, array('queued', 'running'), true)) {
            return $state;
        }

        if ($this->has_active_background_import_action()) {
            return $state;
        }

        $action_status = $this->get_background_import_action_status((int) ($state['action_id'] ?? 0));
        if ($action_status === 'failed') {
            return $this->mark_background_import_failed(
                $state,
                'Останній background step завершився з помилкою або був зупинений сервером.'
            );
        }

        $updated_at = $this->parse_wp_local_mysql_timestamp($state['updated_at'] ?? '');
        if (!$updated_at) {
            return $state;
        }

        if ((current_time('timestamp') - $updated_at) < 305) {
            return $state;
        }

        return $this->mark_background_import_failed(
            $state,
            'Останній background step не завершився. Найімовірніше спрацював timeout або серверна помилка.'
        );
    }

    /**
     * Convert a WordPress-local MySQL datetime string into a Unix timestamp.
     *
     * @param string $datetime
     * @return int
     */
    private function parse_wp_local_mysql_timestamp($datetime)
    {
        $datetime = trim((string) $datetime);
        if ($datetime === '') {
            return 0;
        }

        try {
            $date = new DateTimeImmutable($datetime, wp_timezone());
        } catch (Throwable $e) {
            return 0;
        }

        return (int) $date->getTimestamp();
    }

    /**
     * Read current Action Scheduler status for the given background import action.
     *
     * @param int $action_id
     * @return string
     */
    private function get_background_import_action_status($action_id)
    {
        global $wpdb;

        $action_id = (int) $action_id;
        if ($action_id <= 0) {
            return '';
        }

        $table = $wpdb->prefix . 'actionscheduler_actions';
        $status = $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$table} WHERE action_id = %d LIMIT 1", $action_id)
        );

        return is_string($status) ? $status : '';
    }

    /**
     * Mark the background import state as failed and persist it.
     *
     * @param array  $state
     * @param string $message
     * @return array
     */
    private function mark_background_import_failed($state, $message)
    {
        $state['status']        = 'failed';
        $state['current_stage'] = 'failed';
        $state['completed_at']  = current_time('mysql');
        $state['updated_at']    = $state['completed_at'];
        $state['action_id']     = 0;
        $state['last_batch']    = array_merge(
            isset($state['last_batch']) && is_array($state['last_batch']) ? $state['last_batch'] : array(),
            array(
                'message' => (string) $message,
            )
        );
        $state['cumulative'] = $this->sum_result_totals($state['results'] ?? array());
        $auto_continued = $this->maybe_auto_continue_background_import($state, $message);
        if (is_array($auto_continued) && ($auto_continued['status'] ?? '') === 'queued') {
            return $auto_continued;
        }

        $this->unschedule_background_watchdog();
        $this->persist_last_import_snapshot($state);
        $this->save_background_import_state($state);
        do_action('d14k_supplier_background_import_finalized', $state);

        return $state;
    }

    /**
     * Check whether Action Scheduler still has active jobs for supplier import.
     *
     * @return bool
     */
    private function has_active_background_import_action()
    {
        if (!$this->supports_background_import() || !function_exists('as_get_scheduled_actions')) {
            return false;
        }

        $statuses = array('pending', 'in-progress', 'running');

        foreach ($statuses as $status) {
            $actions = as_get_scheduled_actions(array(
                'hook'     => self::BACKGROUND_IMPORT_HOOK,
                'group'    => self::BACKGROUND_IMPORT_GROUP,
                'status'   => $status,
                'per_page' => 1,
                'orderby'  => 'date',
                'order'    => 'DESC',
            ), 'ids');

            if (!empty($actions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mark queued or stuck supplier-import actions as canceled.
     *
     * @return void
     */
    private function cancel_background_import_actions()
    {
        if (
            !$this->supports_background_import()
            || !function_exists('as_get_scheduled_actions')
            || !class_exists('ActionScheduler')
        ) {
            return;
        }

        $store = ActionScheduler::store();
        if (!is_object($store) || !method_exists($store, 'cancel_action')) {
            return;
        }

        foreach (array('pending', 'in-progress', 'running') as $status) {
            $action_ids = as_get_scheduled_actions(array(
                'hook'     => self::BACKGROUND_IMPORT_HOOK,
                'group'    => self::BACKGROUND_IMPORT_GROUP,
                'status'   => $status,
                'per_page' => 100,
                'orderby'  => 'date',
                'order'    => 'DESC',
            ), 'ids');

            if (empty($action_ids)) {
                continue;
            }

            foreach ($action_ids as $action_id) {
                try {
                    $store->cancel_action((int) $action_id);
                } catch (Throwable $e) {
                    // Ignore scheduler-store edge cases and rely on state reconciliation next.
                }
            }
        }
    }

    /**
     * Remove queued async steps for supplier background import.
     *
     * @return void
     */
    private function unschedule_background_import_actions()
    {
        if (!$this->supports_background_import() || !function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(self::BACKGROUND_IMPORT_HOOK, array(), self::BACKGROUND_IMPORT_GROUP);
    }

    /**
     * Schedule or refresh the watchdog for auto-continue runs.
     *
     * @param array $state
     * @return void
     */
    private function refresh_background_watchdog($state)
    {
        $this->unschedule_background_watchdog();

        if (
            empty($state['auto_continue'])
            || !in_array((string) ($state['status'] ?? ''), array('queued', 'running'), true)
            || !function_exists('as_schedule_single_action')
        ) {
            return;
        }

        as_schedule_single_action(
            time() + self::BACKGROUND_WATCHDOG_DELAY,
            self::BACKGROUND_WATCHDOG_HOOK,
            array(),
            self::BACKGROUND_IMPORT_GROUP
        );
    }

    /**
     * Remove queued watchdog actions for supplier background import.
     *
     * @return void
     */
    private function unschedule_background_watchdog()
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(self::BACKGROUND_WATCHDOG_HOOK, array(), self::BACKGROUND_IMPORT_GROUP);
    }

    /**
     * Queue the next async Action Scheduler job.
     *
     * @return int
     */
    private function schedule_background_import_step()
    {
        if (!$this->supports_background_import()) {
            return 0;
        }

        return (int) as_enqueue_async_action(self::BACKGROUND_IMPORT_HOOK, array(), self::BACKGROUND_IMPORT_GROUP);
    }

    /**
     * Process one import step against a mutable state array.
     *
     * @param array $state
     * @param int   $batch_size
     * @return array
     */
    private function run_import_step(&$state, $batch_size)
    {
        $batch_size = max(1, (int) $batch_size);
        $state['batch_size'] = $batch_size;
        $deadline = $this->get_import_step_deadline($state);

        if (empty($state['feeds'])) {
            return array(
                'done'       => true,
                'batch'      => array('created' => 0, 'updated' => 0, 'skipped' => 0),
                'cumulative' => array('created' => 0, 'updated' => 0, 'skipped' => 0),
                'results'    => array(),
            );
        }

        while ($state['current_feed'] < count($state['feeds'])) {
            $feed_index = (int) $state['current_feed'];
            $feed       = &$state['feeds'][$feed_index];
            $url        = $feed['url'];

            $state['current_feed_url']   = $url;
            $state['current_feed_label'] = $feed['label'];
            $state['current_stage']      = 'preparing';

            $prepared = $this->prepare_feed_dataset($state, $feed_index);
            if (is_wp_error($prepared)) {
                $this->append_result_error($state['results'][$url], $prepared->get_error_message());
                $this->advance_import_feed($state, $feed_index);
                unset($feed);
                continue;
            }

            $max_items_per_feed = max(0, (int) ($state['max_items_per_feed'] ?? 0));

            if (empty($feed['category_synced'])) {
                $dataset = $this->load_feed_dataset($feed);
                if (is_wp_error($dataset)) {
                    $this->append_result_error($state['results'][$url], $dataset->get_error_message());
                    $this->advance_import_feed($state, $feed_index);
                    unset($feed);
                    continue;
                }

                $state['current_stage'] = 'sync_categories';
                $sync_offers = $dataset['offers'];
                if ($max_items_per_feed > 0) {
                    $sync_offers = array_slice($sync_offers, 0, $max_items_per_feed);
                }

                $feed['category_map']   = $this->sync_offer_categories($dataset['categories'], $sync_offers, $feed);
                $feed['category_synced'] = true;
            }

            if ($this->should_yield_import_step($state, $deadline)) {
                $batch = array(
                    'created'    => 0,
                    'updated'    => 0,
                    'skipped'    => 0,
                    'feed_url'   => $url,
                    'source_key' => $feed['source_key'],
                    'message'    => 'Крок завершено раніше, щоб не впертися в timeout. Продовжимо з цього ж місця.',
                );

                $state['last_batch'] = $batch;
                $state['updated_at'] = current_time('mysql');
                $state['cumulative'] = $this->sum_result_totals($state['results']);

                unset($feed);

                return $this->build_import_step_response($state, $batch);
            }

            $dataset = $this->load_feed_dataset($feed);
            if (is_wp_error($dataset)) {
                $this->append_result_error($state['results'][$url], $dataset->get_error_message());
                $this->advance_import_feed($state, $feed_index);
                unset($feed);
                continue;
            }

            if (empty($dataset['offers'])) {
                $this->append_result_error($state['results'][$url], 'Жодного товару не знайдено у фіді');
                $this->advance_import_feed($state, $feed_index);
                unset($feed);
                continue;
            }

            $offers_total_limit = (int) ($feed['offers_total'] ?? count($dataset['offers']));
            if ($max_items_per_feed > 0) {
                $offers_total_limit = min($offers_total_limit, $max_items_per_feed);
            }

            if ($state['current_offset'] >= $offers_total_limit) {
                $this->cleanup_feed_dataset($feed);
                $state['current_feed']   = $feed_index + 1;
                $state['current_offset'] = 0;
                unset($feed);
                continue;
            }

            $remaining = max(0, $offers_total_limit - (int) $state['current_offset']);
            $slice = array_slice($dataset['offers'], $state['current_offset'], min($batch_size, $remaining));
            $batch = array(
                'created'    => 0,
                'updated'    => 0,
                'skipped'    => 0,
                'feed_url'   => $url,
                'source_key' => $feed['source_key'],
                'message'    => '',
            );

            $state['current_stage'] = 'importing';

            foreach ($slice as $offer) {
                $result = $this->import_product_from_offer($offer, $feed, $feed['category_map']);

                if ($result === 'created') {
                    $batch['created']++;
                    $state['results'][$url]['created']++;
                } elseif ($result === 'updated') {
                    $batch['updated']++;
                    $state['results'][$url]['updated']++;
                } elseif ($result === 'skipped') {
                    $batch['skipped']++;
                    $state['results'][$url]['skipped']++;
                } elseif (is_string($result) && $result !== '') {
                    $this->append_result_error($state['results'][$url], $result);
                } else {
                    $batch['skipped']++;
                    $state['results'][$url]['skipped']++;
                }

                $state['current_offset']++;
                $state['last_batch'] = $batch;
                $this->checkpoint_background_import_progress($state);

                if (
                    $this->should_yield_import_step($state, $deadline)
                    && $state['current_offset'] < $offers_total_limit
                ) {
                    $batch['message'] = 'Крок завершено раніше, щоб не впертися в timeout. Продовжимо з цього ж місця.';
                    $state['last_batch'] = $batch;
                    $state['cumulative'] = $this->sum_result_totals($state['results']);

                    unset($feed);

                    return $this->build_import_step_response($state, $batch);
                }
            }

            $state['last_batch']      = $batch;
            $state['updated_at']      = current_time('mysql');
            $state['cumulative']      = $this->sum_result_totals($state['results']);

            if ($state['current_offset'] >= $offers_total_limit) {
                $this->cleanup_feed_dataset($feed);
                $state['current_feed']   = $feed_index + 1;
                $state['current_offset'] = 0;
            }

            unset($feed);

            return $this->build_import_step_response($state, $batch);
        }

        return array(
            'done'       => true,
            'batch'      => array('created' => 0, 'updated' => 0, 'skipped' => 0),
            'cumulative' => $this->sum_result_totals($state['results']),
            'results'    => $state['results'],
        );
    }

    /**
     * Load or initialize import batch state.
     *
     * @param bool $reset
     * @param int  $batch_size
     * @return array
     */
    private function get_import_state($reset = false, $batch_size = 25)
    {
        $key   = self::IMPORT_STATE_TRANSIENT . get_current_user_id();
        $state = $reset ? false : get_transient($key);

        if (is_array($state) && isset($state['feeds'], $state['results'])) {
            $state['batch_size'] = max(1, (int) ($state['batch_size'] ?? $batch_size));
            return $state;
        }

        return $this->build_import_state('browser', $batch_size);
    }

    /**
     * Build a normalized import-step response.
     *
     * @param array $state
     * @param array $batch
     * @return array
     */
    private function build_import_step_response($state, $batch)
    {
        return array(
            'done'       => ($state['current_feed'] >= count($state['feeds'])),
            'batch'      => $batch,
            'cumulative' => $state['cumulative'],
            'results'    => $state['results'],
            'progress'   => array(
                'feed_index'   => $state['current_feed'],
                'offer_offset' => $state['current_offset'],
            ),
        );
    }

    /**
     * Persist a lightweight progress checkpoint during background import.
     *
     * @param array $state
     * @return void
     */
    private function checkpoint_background_import_progress(&$state)
    {
        if (($state['mode'] ?? '') !== 'background') {
            return;
        }

        $this->reset_background_auto_continue_attempts($state);
        $state['updated_at'] = current_time('mysql');
        $state['cumulative'] = $this->sum_result_totals($state['results']);
        $this->save_background_import_state($state);
    }

    /**
     * Persist import state between AJAX requests.
     *
     * @param array $state
     * @return void
     */
    private function save_import_state($state)
    {
        set_transient(self::IMPORT_STATE_TRANSIENT . get_current_user_id(), $state, HOUR_IN_SECONDS * 2);
    }

    /**
     * Finalize import state and write the last-run summary.
     *
     * @param array $state
     * @return void
     */
    private function finalize_import_state($state)
    {
        update_option('d14k_supplier_feeds_last_run', array(
            'time'    => current_time('mysql'),
            'results' => $state['results'],
        ));

        $this->persist_last_import_snapshot($state);
        $this->cleanup_import_state_files($state);
        delete_transient(self::IMPORT_STATE_TRANSIENT . get_current_user_id());
    }

    /**
     * Load or initialize cleanup state.
     *
     * @param bool $reset
     * @return array
     */
    private function get_cleanup_state($reset = false)
    {
        $key   = self::CLEANUP_STATE_TRANSIENT . get_current_user_id();
        $state = $reset ? false : get_transient($key);

        if (is_array($state) && isset($state['feeds'], $state['results'])) {
            return $state;
        }

        $feeds   = $this->get_enabled_feeds();
        $results = array();

        foreach ($feeds as $feed) {
            $results[$feed['url']] = array(
                'scanned'    => 0,
                'trashed'    => 0,
                'kept'       => 0,
                'categories_deleted' => 0,
                'errors'     => array(),
                'source_key' => $this->build_source_key($feed['url']),
                'categories_pruned' => false,
                'samples'    => array(
                    'trashed' => array(),
                    'categories_deleted' => array(),
                ),
            );
        }

        return array(
            'feeds'          => $feeds,
            'results'        => $results,
            'current_feed'   => 0,
            'current_cursor' => 0,
            'started_at'     => current_time('mysql'),
        );
    }

    /**
     * Persist cleanup state between AJAX requests.
     *
     * @param array $state
     * @return void
     */
    private function save_cleanup_state($state)
    {
        set_transient(self::CLEANUP_STATE_TRANSIENT . get_current_user_id(), $state, HOUR_IN_SECONDS * 2);
    }

    /**
     * Finalize cleanup state and write the last-run summary.
     *
     * @param array $state
     * @return void
     */
    private function finalize_cleanup_state($state)
    {
        update_option('d14k_supplier_feeds_last_cleanup', array(
            'time'    => current_time('mysql'),
            'results' => $state['results'],
        ));

        delete_transient(self::CLEANUP_STATE_TRANSIENT . get_current_user_id());
    }

    /**
     * Return only enabled supplier feeds from settings.
     *
     * @return array
     */
    private function get_enabled_feeds()
    {
        $settings = get_option('d14k_feed_settings', array());
        $feeds    = isset($settings['supplier_feeds']) ? (array) $settings['supplier_feeds'] : array();
        $enabled  = array();

        foreach ($feeds as $feed) {
            if (empty($feed['url']) || empty($feed['enabled'])) {
                continue;
            }

            $enabled[] = $this->build_feed_config($feed);
        }

        return $enabled;
    }

    /**
     * Build normalized feed config from saved settings.
     *
     * @param array $feed
     * @return array
     */
    private function build_feed_config($feed)
    {
        $url          = esc_url_raw(trim((string) ($feed['url'] ?? '')));
        $source_key   = $this->build_source_key($url);
        $label        = sanitize_text_field((string) ($feed['label'] ?? ''));
        $category_root = sanitize_text_field((string) ($feed['category_root'] ?? ''));
        $update_fields = $this->normalize_update_fields(
            isset($feed['update_fields']) ? (array) $feed['update_fields'] : array(),
            !empty($feed['update_stock']),
            !array_key_exists('update_fields', $feed)
        );

        return array(
            'url'             => $url,
            'source_key'      => $source_key,
            'markup'          => isset($feed['markup']) ? (float) $feed['markup'] : 0,
            'update_stock'    => !empty($update_fields['stock']),
            'update_fields'   => $update_fields,
            'enabled'         => !empty($feed['enabled']),
            'label'           => $label !== '' ? $label : $this->derive_supplier_label($url),
            'category_root'   => $category_root,
            'dataset_file'    => '',
            'offers_total'    => 0,
            'categories_total'=> 0,
            'category_map'    => array(),
            'category_synced' => false,
            'created_product_ids' => array(),
            'created_term_ids' => array(),
        );
    }

    /**
     * Return supported update fields.
     *
     * @return array
     */
    public static function get_available_update_fields()
    {
        return array(
            'price'       => 'Ціна та знижка',
            'stock'       => 'Наявність і кількість',
            'image'       => 'Картинка',
            'category'    => 'Категорія',
            'title'       => 'Назва',
            'description' => 'Опис',
            'params'      => 'Характеристики',
            'sku'         => 'Артикул',
            'vendor'      => 'Бренд',
        );
    }

    /**
     * Return full legacy update field map.
     *
     * @param bool $include_stock
     * @return array
     */
    private function get_legacy_update_field_map($include_stock = true)
    {
        $fields = array_fill_keys(array_keys(self::get_available_update_fields()), true);

        if (!$include_stock) {
            $fields['stock'] = false;
        }

        return $fields;
    }

    /**
     * Normalize update field selection.
     *
     * @param array $fields
     * @param bool  $legacy_update_stock
     * @param bool  $legacy_mode
     * @return array
     */
    private function normalize_update_fields($fields, $legacy_update_stock = true, $legacy_mode = false)
    {
        if ($legacy_mode) {
            return $this->get_legacy_update_field_map($legacy_update_stock);
        }

        $normalized = array_fill_keys(array_keys(self::get_available_update_fields()), false);

        foreach ($fields as $field_key => $field_value) {
            $key = is_string($field_key) && !is_numeric($field_key) ? $field_key : $field_value;
            $key = sanitize_key((string) $key);

            if (array_key_exists($key, $normalized) && !empty($field_value)) {
                $normalized[$key] = true;
            }
        }

        if (!array_filter($normalized)) {
            $normalized['price'] = true;
            $normalized['stock'] = true;
        }

        return $normalized;
    }

    /**
     * Prepare empty result rows for import state.
     *
     * @param array $feeds
     * @return array
     */
    private function build_empty_import_results($feeds)
    {
        $results = array();

        foreach ($feeds as $feed) {
            $results[$feed['url']] = array(
                'created'          => 0,
                'updated'          => 0,
                'skipped'          => 0,
                'errors'           => array(),
                'source_key'       => $feed['source_key'],
                'offers_total'     => 0,
                'categories_total' => 0,
                'feed_label'       => $feed['label'],
                'created_product_ids' => array(),
                'created_term_ids' => array(),
            );
        }

        return $results;
    }

    /**
     * Move import state to the next feed after an error.
     *
     * @param array $state
     * @param int   $feed_index
     * @return void
     */
    private function advance_import_feed(&$state, $feed_index)
    {
        if (isset($state['feeds'][$feed_index])) {
            $this->cleanup_feed_dataset($state['feeds'][$feed_index]);
        }

        $state['current_feed']   = $feed_index + 1;
        $state['current_offset'] = 0;
    }

    /**
     * Prepare one feed dataset file for batched processing.
     *
     * @param array $state
     * @param int   $feed_index
     * @return true|WP_Error
     */
    private function prepare_feed_dataset(&$state, $feed_index)
    {
        if (empty($state['feeds'][$feed_index])) {
            return new WP_Error('supplier_feed_missing', 'Feed не знайдено в state');
        }

        $feed = &$state['feeds'][$feed_index];
        $url  = $feed['url'];

        if (!empty($feed['dataset_file']) && file_exists($feed['dataset_file'])) {
            return true;
        }

        $this->checkpoint_background_stage($state, 'download_feed', 'Завантажуємо supplier feed у тимчасовий файл.');
        $feed_file = $this->download_feed_to_temp_file($url);
        if (is_wp_error($feed_file)) {
            return $feed_file;
        }

        try {
            $this->checkpoint_background_stage($state, 'parse_feed', 'Готуємо dataset supplier feed.');
            $parsed_dataset = $this->parse_feed_dataset_file(
                $feed_file,
                sanitize_key((string) ($state['selection_mode'] ?? '')),
                max(0, (int) ($state['max_items_per_feed'] ?? 0))
            );
        } finally {
            if (is_string($feed_file) && $feed_file !== '' && file_exists($feed_file)) {
                @unlink($feed_file);
            }
        }

        if (is_wp_error($parsed_dataset)) {
            return $parsed_dataset;
        }

        $categories = $parsed_dataset['categories'];
        $offers     = $parsed_dataset['offers'];

        $dataset_file = $this->write_feed_dataset($feed['source_key'], array(
            'categories' => $categories,
            'offers'     => $offers,
        ));

        if (is_wp_error($dataset_file)) {
            return $dataset_file;
        }

        $feed['dataset_file']     = $dataset_file;
        $feed['offers_total']     = count($offers);
        $feed['categories_total'] = count($categories);
        $feed['category_synced']  = false;
        $feed['category_map']     = array();

        $state['results'][$url]['offers_total']     = $feed['offers_total'];
        $state['results'][$url]['categories_total'] = $feed['categories_total'];
        $state['results'][$url]['source_key']       = $feed['source_key'];

        return true;
    }

    /**
     * Parse one downloaded feed file into a lightweight dataset.
     *
     * @param string $feed_file
     * @param string $selection_mode
     * @param int    $max_items_per_feed
     * @return array|WP_Error
     */
    private function parse_feed_dataset_file($feed_file, $selection_mode = '', $max_items_per_feed = 0)
    {
        if (class_exists('XMLReader')) {
            $parsed = $this->parse_feed_dataset_file_streaming($feed_file, $selection_mode, $max_items_per_feed);
            if (!is_wp_error($parsed)) {
                return $parsed;
            }
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($feed_file, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($xml === false) {
            return new WP_Error('supplier_feed_parse', 'Не вдалось розпарсити XML');
        }

        return $this->build_feed_dataset_payload(
            $this->extract_categories($xml),
            $this->extract_offers($xml),
            $selection_mode,
            $max_items_per_feed
        );
    }

    /**
     * Parse one feed file with XMLReader to avoid loading the full XML body into memory.
     *
     * @param string $feed_file
     * @param string $selection_mode
     * @param int    $max_items_per_feed
     * @return array|WP_Error
     */
    private function parse_feed_dataset_file_streaming($feed_file, $selection_mode = '', $max_items_per_feed = 0)
    {
        $reader = new XMLReader();
        $opened = @$reader->open($feed_file, null, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);

        if (!$opened) {
            return new WP_Error('supplier_feed_parse_stream', 'Не вдалося відкрити XMLReader для supplier feed');
        }

        libxml_use_internal_errors(true);

        try {
            $categories = array();
            $offers = array();

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $node_name = $reader->localName;

                if ($node_name === 'category') {
                    $category_xml = $reader->readOuterXML();
                    if ($category_xml !== '') {
                        $category_node = simplexml_load_string($category_xml, 'SimpleXMLElement', LIBXML_NOCDATA);
                        if ($category_node !== false) {
                            $attrs = $category_node->attributes();
                            $id    = trim((string) ($attrs['id'] ?? ''));
                            $name  = sanitize_text_field(trim((string) $category_node));

                            if ($id !== '' && $name !== '') {
                                $categories[$id] = array(
                                    'id'        => $id,
                                    'parent_id' => trim((string) ($attrs['parentId'] ?? '')),
                                    'name'      => $name,
                                );
                            }
                        }
                    }

                    $reader->next('category');
                    continue;
                }

                if (!in_array($node_name, array('offer', 'product', 'item'), true)) {
                    continue;
                }

                $offer_xml = $reader->readOuterXML();
                if ($offer_xml === '') {
                    $reader->next($node_name);
                    continue;
                }

                $offer_node = simplexml_load_string($offer_xml, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($offer_node === false) {
                    $reader->next($node_name);
                    continue;
                }

                $offer = null;
                if ($node_name === 'offer') {
                    $offer = $this->parse_yml_offer($offer_node);
                } elseif ($node_name === 'item') {
                    $offer = $this->parse_rss_item($offer_node);
                } else {
                    $offer = $this->parse_generic_node($offer_node);
                }

                if (empty($offer) || !is_array($offer)) {
                    $reader->next($node_name);
                    continue;
                }

                if ($selection_mode === 'nonempty_params' && !$this->offer_has_nonempty_params($offer)) {
                    $reader->next($node_name);
                    continue;
                }

                $offers[] = $offer;

                if ($max_items_per_feed > 0 && count($offers) >= $max_items_per_feed) {
                    break;
                }

                $reader->next($node_name);
            }
        } finally {
            libxml_clear_errors();
            $reader->close();
        }

        return $this->build_feed_dataset_payload($categories, $offers, $selection_mode, $max_items_per_feed, true);
    }

    /**
     * Apply test-mode offer filtering and build one normalized dataset payload.
     *
     * @param array $categories
     * @param array $offers
     * @param string $selection_mode
     * @param int    $max_items_per_feed
     * @param bool   $offers_already_filtered
     * @return array|WP_Error
     */
    private function build_feed_dataset_payload($categories, $offers, $selection_mode = '', $max_items_per_feed = 0, $offers_already_filtered = false)
    {
        $categories = is_array($categories) ? $categories : array();
        $offers     = is_array($offers) ? array_values($offers) : array();

        if ($selection_mode === 'nonempty_params' && !$offers_already_filtered) {
            $offers = array_values(array_filter($offers, array($this, 'offer_has_nonempty_params')));
        }

        if ($selection_mode === 'nonempty_params' && empty($offers)) {
            return new WP_Error('supplier_feed_no_nonempty_params', 'У цьому feed не знайдено товарів з непорожніми характеристиками для тестового запуску.');
        }

        if ($max_items_per_feed > 0 && !empty($offers)) {
            $offers = array_slice($offers, 0, $max_items_per_feed);
        }

        return array(
            'categories' => $categories,
            'offers'     => $offers,
        );
    }

    /**
     * Load one prepared dataset from disk.
     *
     * @param array $feed
     * @return array|WP_Error
     */
    private function load_feed_dataset($feed)
    {
        if (empty($feed['dataset_file']) || !file_exists($feed['dataset_file'])) {
            return new WP_Error('supplier_feed_dataset_missing', 'Підготовлений dataset не знайдено');
        }

        $content = file_get_contents($feed['dataset_file']);
        if ($content === false || $content === '') {
            return new WP_Error('supplier_feed_dataset_empty', 'Не вдалося прочитати dataset supplier feed');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return new WP_Error('supplier_feed_dataset_invalid', 'Не вдалося декодувати dataset supplier feed');
        }

        return array(
            'categories' => isset($decoded['categories']) && is_array($decoded['categories']) ? $decoded['categories'] : array(),
            'offers'     => isset($decoded['offers']) && is_array($decoded['offers']) ? $decoded['offers'] : array(),
        );
    }

    /**
     * Check whether one parsed offer has at least one non-empty param.
     *
     * @param array $offer
     * @return bool
     */
    private function offer_has_nonempty_params($offer)
    {
        if (empty($offer['params']) || !is_array($offer['params'])) {
            return false;
        }

        foreach ($offer['params'] as $param) {
            $param_name = '';
            $param_value = '';

            if (is_array($param)) {
                $param_name = sanitize_text_field((string) ($param['name'] ?? ''));
                $param_value = sanitize_text_field((string) ($param['value'] ?? ''));
            }

            if ($param_name !== '' && $param_value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist prepared dataset to uploads temp directory.
     *
     * @param string $source_key
     * @param array  $payload
     * @return string|WP_Error
     */
    private function write_feed_dataset($source_key, $payload)
    {
        $dir = $this->get_temp_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }

        $path = trailingslashit($dir) . sanitize_file_name($source_key . '-' . wp_generate_password(8, false, false) . '.json');
        $json = wp_json_encode($payload);

        if ($json === false || file_put_contents($path, $json) === false) {
            return new WP_Error('supplier_feed_dataset_write', 'Не вдалося записати dataset supplier feed');
        }

        return $path;
    }

    /**
     * Return writable temp dir for prepared supplier datasets.
     *
     * @return string|WP_Error
     */
    private function get_temp_dir()
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('supplier_feed_uploads', $uploads['error']);
        }

        $dir = trailingslashit($uploads['basedir']) . self::TEMP_DIR_SLUG;
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('supplier_feed_temp_dir', 'Не вдалося створити тимчасову директорію supplier import');
        }

        return $dir;
    }

    /**
     * Remove one prepared dataset file.
     *
     * @param array $feed
     * @return void
     */
    private function cleanup_feed_dataset(&$feed)
    {
        if (!empty($feed['dataset_file']) && file_exists($feed['dataset_file'])) {
            @unlink($feed['dataset_file']);
        }

        $feed['dataset_file'] = '';
    }

    /**
     * Remove all prepared dataset files from an import state.
     *
     * @param array $state
     * @return void
     */
    private function cleanup_import_state_files($state)
    {
        if (empty($state['feeds']) || !is_array($state['feeds'])) {
            return;
        }

        foreach ($state['feeds'] as $feed) {
            if (!empty($feed['dataset_file']) && file_exists($feed['dataset_file'])) {
                @unlink($feed['dataset_file']);
            }
        }
    }

    /**
     * Derive readable supplier label from feed URL.
     *
     * @param string $url
     * @return string
     */
    private function derive_supplier_label($url)
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return 'Постачальник';
        }

        $parts = array_values(array_filter(explode('.', strtolower($host))));
        $parts = array_values(array_filter($parts, function ($part) {
            return !in_array($part, array('www', 'api', 'feed', 'xml', 'cdn', 'img', 'com', 'net', 'org', 'ua', 'biz', 'shop', 'b2b'), true);
        }));

        if (empty($parts)) {
            return $host;
        }

        $label = end($parts);
        $label = str_replace(array('-', '_'), ' ', $label);

        return ucwords($label);
    }

    /**
     * Return supplier root category name.
     *
     * @param array $feed
     * @return string
     */
    private function get_supplier_root_name($feed)
    {
        $configured = trim((string) ($feed['category_root'] ?? ''));
        if ($configured !== '') {
            return sanitize_text_field($configured);
        }

        return '';
    }

    /**
     * Ensure top-level supplier root category exists.
     *
     * @param array $feed
     * @return int
     */
    private function get_or_create_supplier_root_term(&$feed)
    {
        $source_key = $feed['source_key'] ?? $this->build_source_key($feed['url']);
        $root_name  = $this->get_supplier_root_name($feed);

        if ($root_name === '') {
            return 0;
        }

        $term_id = $this->find_supplier_root_term($source_key);
        if (!$term_id) {
            $existing = term_exists($root_name, 'product_cat', 0);
            if ($existing && !is_wp_error($existing)) {
                $term_id = (int) (is_array($existing) ? $existing['term_id'] : $existing);
            } else {
                $created = wp_insert_term($root_name, 'product_cat', array(
                    'parent' => 0,
                ));

                if (is_wp_error($created)) {
                    return 0;
                }

                $term_id = (int) $created['term_id'];
                $this->remember_created_term_id($feed, $term_id);
            }
        } else {
            wp_update_term($term_id, 'product_cat', array(
                'name'   => $root_name,
                'parent' => 0,
            ));
        }

        update_term_meta($term_id, self::TERM_META_SOURCE_KEY, $source_key);
        update_term_meta($term_id, self::TERM_META_ROOT_KEY, '1');

        return $term_id;
    }

    /**
     * Find existing supplier root term by source key.
     *
     * @param string $source_key
     * @return int
     */
    private function find_supplier_root_term($source_key)
    {
        global $wpdb;

        $term_id = $wpdb->get_var($wpdb->prepare(
            "SELECT root.term_id
             FROM {$wpdb->termmeta} root
             INNER JOIN {$wpdb->termmeta} src
                ON src.term_id = root.term_id
               AND src.meta_key = %s
               AND src.meta_value = %s
             WHERE root.meta_key = %s
             LIMIT 1",
            self::TERM_META_SOURCE_KEY,
            $source_key,
            self::TERM_META_ROOT_KEY
        ));

        return $term_id ? (int) $term_id : 0;
    }

    /**
     * Sum import totals from per-feed results.
     *
     * @param array $results
     * @return array
     */
    private function sum_result_totals($results)
    {
        return array(
            'created' => array_sum(array_map(function ($row) { return (int) ($row['created'] ?? 0); }, $results)),
            'updated' => array_sum(array_map(function ($row) { return (int) ($row['updated'] ?? 0); }, $results)),
            'skipped' => array_sum(array_map(function ($row) { return (int) ($row['skipped'] ?? 0); }, $results)),
        );
    }

    /**
     * Sum cleanup totals from per-feed results.
     *
     * @param array $results
     * @return array
     */
    private function sum_cleanup_totals($results)
    {
        return array(
            'scanned' => array_sum(array_map(function ($row) { return (int) ($row['scanned'] ?? 0); }, $results)),
            'trashed' => array_sum(array_map(function ($row) { return (int) ($row['trashed'] ?? 0); }, $results)),
            'kept'    => array_sum(array_map(function ($row) { return (int) ($row['kept'] ?? 0); }, $results)),
            'categories_deleted' => array_sum(array_map(function ($row) { return (int) ($row['categories_deleted'] ?? 0); }, $results)),
        );
    }

    /**
     * Return the saved snapshot for the last supplier import.
     *
     * @return array
     */
    public function get_last_import_snapshot()
    {
        $snapshot = get_option(self::LAST_IMPORT_SNAPSHOT_OPTION, array());
        return is_array($snapshot) ? $snapshot : array();
    }

    /**
     * Delete entities created by the last tracked supplier import.
     *
     * @return array|WP_Error
     */
    public function rollback_last_import()
    {
        $state = $this->reconcile_background_import_state($this->get_background_import_state());
        if (in_array((string) ($state['status'] ?? ''), array('queued', 'running'), true)) {
            return new WP_Error('background_busy', 'Спершу дочекайтеся завершення або скасуйте активний імпорт.');
        }

        $this->cancel_background_import_actions();
        if ($this->has_active_background_import_action()) {
            return new WP_Error('background_busy', 'Rollback тимчасово заблоковано, бо Action Scheduler ще не відпустив попередній крок імпорту.');
        }

        $snapshot = $this->get_last_import_snapshot();
        if (empty($snapshot['feeds']) || !is_array($snapshot['feeds'])) {
            return new WP_Error('rollback_unavailable', 'Немає збережених даних для rollback останнього імпорту.');
        }

        $result = array(
            'snapshot_status' => (string) ($snapshot['status'] ?? ''),
            'scope_label' => (string) ($snapshot['scope_label'] ?? ''),
            'deleted_products' => 0,
            'deleted_categories' => 0,
            'skipped_products' => 0,
            'skipped_categories' => 0,
            'errors' => array(),
        );

        foreach ($snapshot['feeds'] as $feed) {
            $source_key = (string) ($feed['source_key'] ?? '');
            if ($source_key === '') {
                continue;
            }

            $product_ids = array_values(array_unique(array_merge(
                array_filter(array_map('intval', $feed['created_product_ids'] ?? array())),
                $this->find_recent_created_supplier_products_for_rollback($source_key, $snapshot)
            )));
            foreach ($product_ids as $product_id) {
                $post = get_post($product_id);
                if (!$post || $post->post_type !== 'product') {
                    $result['skipped_products']++;
                    continue;
                }

                if ((string) get_post_meta($product_id, self::META_SOURCE_KEY, true) !== $source_key) {
                    $result['skipped_products']++;
                    continue;
                }

                if (wp_delete_post($product_id, true)) {
                    $result['deleted_products']++;
                } else {
                    $result['errors'][] = 'Не вдалося видалити товар #' . $product_id;
                }
            }

            $term_cleanup = $this->delete_empty_supplier_terms($source_key);
            $result['deleted_categories'] += (int) ($term_cleanup['deleted'] ?? 0);
            $result['skipped_categories'] += (int) ($term_cleanup['skipped'] ?? 0);
            if (!empty($term_cleanup['errors'])) {
                $result['errors'] = array_merge($result['errors'], $term_cleanup['errors']);
            }
        }

        $snapshot['rollback_completed_at'] = current_time('mysql');
        $snapshot['rollback_result'] = $result;
        update_option(self::LAST_IMPORT_SNAPSHOT_OPTION, $snapshot, false);

        return $result;
    }

    /**
     * Find products from the same supplier source that were created during the rollback window.
     *
     * This covers edge cases where the import step finishes a write right around cancellation
     * and the product ID misses the saved snapshot by a few seconds.
     *
     * @param string $source_key
     * @param array  $snapshot
     * @return array
     */
    private function find_recent_created_supplier_products_for_rollback($source_key, $snapshot)
    {
        $source_key = (string) $source_key;
        $started_at = (string) ($snapshot['started_at'] ?? '');
        if ($source_key === '' || $started_at === '') {
            return array();
        }

        $completed_at = (string) ($snapshot['completed_at'] ?? '');
        if ($completed_at === '') {
            $completed_at = current_time('mysql');
        }

        $end_timestamp = strtotime($completed_at);
        if ($end_timestamp === false) {
            $end_timestamp = time();
        }

        $window_end_local = date('Y-m-d H:i:s', $end_timestamp + (5 * MINUTE_IN_SECONDS));

        global $wpdb;

        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = %s
               AND pm.meta_value = %s
             WHERE p.post_type = 'product'
               AND p.post_date >= %s
               AND p.post_date <= %s",
            self::META_SOURCE_KEY,
            $source_key,
            $started_at,
            $window_end_local
        ));

        return array_values(array_unique(array_filter(array_map('intval', $product_ids))));
    }

    /**
     * Save a compact snapshot that can later drive rollback.
     *
     * @param array $state
     * @return void
     */
    private function persist_last_import_snapshot($state)
    {
        if (empty($state['feeds']) || !is_array($state['feeds'])) {
            return;
        }

        $feeds = array();

        foreach ($state['feeds'] as $feed) {
            $created_product_ids = array_values(array_unique(array_filter(array_map('intval', $feed['created_product_ids'] ?? array()))));
            $created_term_ids = array_values(array_unique(array_filter(array_map('intval', $feed['created_term_ids'] ?? array()))));

            if (empty($created_product_ids) && empty($created_term_ids)) {
                continue;
            }

            $feeds[] = array(
                'url' => (string) ($feed['url'] ?? ''),
                'label' => (string) ($feed['label'] ?? ''),
                'source_key' => (string) ($feed['source_key'] ?? ''),
                'created_product_ids' => $created_product_ids,
                'created_term_ids' => $created_term_ids,
            );
        }

        if (empty($feeds)) {
            return;
        }

        update_option(self::LAST_IMPORT_SNAPSHOT_OPTION, array(
            'status' => (string) ($state['status'] ?? ''),
            'scope_mode' => (string) ($state['scope_mode'] ?? ''),
            'scope_label' => (string) ($state['scope_label'] ?? ''),
            'started_at' => (string) ($state['started_at'] ?? ''),
            'completed_at' => (string) ($state['completed_at'] ?? ''),
            'current_stage' => (string) ($state['current_stage'] ?? ''),
            'totals' => $this->sum_result_totals($state['results'] ?? array()),
            'feeds' => $feeds,
        ), false);
    }

    /**
     * Remember one created product inside mutable feed state.
     *
     * @param array $feed
     * @param int   $product_id
     * @return void
     */
    private function remember_created_product_id(&$feed, $product_id)
    {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return;
        }

        if (empty($feed['created_product_ids']) || !is_array($feed['created_product_ids'])) {
            $feed['created_product_ids'] = array();
        }

        if (!in_array($product_id, $feed['created_product_ids'], true)) {
            $feed['created_product_ids'][] = $product_id;
        }
    }

    /**
     * Remember one created category term inside mutable feed state.
     *
     * @param array $feed
     * @param int   $term_id
     * @return void
     */
    private function remember_created_term_id(&$feed, $term_id)
    {
        $term_id = (int) $term_id;
        if ($term_id <= 0) {
            return;
        }

        if (empty($feed['created_term_ids']) || !is_array($feed['created_term_ids'])) {
            $feed['created_term_ids'] = array();
        }

        if (!in_array($term_id, $feed['created_term_ids'], true)) {
            $feed['created_term_ids'][] = $term_id;
        }
    }

    /**
     * Finish cleanup for one feed by pruning empty supplier categories once.
     *
     * @param array  $result
     * @param string $source_key
     * @return void
     */
    private function finalize_cleanup_feed_result(&$result, $source_key)
    {
        if (!empty($result['categories_pruned'])) {
            return;
        }

        $term_cleanup = $this->delete_empty_supplier_terms($source_key);
        $result['categories_deleted'] = (int) ($result['categories_deleted'] ?? 0) + (int) ($term_cleanup['deleted'] ?? 0);
        $result['categories_pruned'] = true;

        if (!empty($term_cleanup['errors'])) {
            foreach ($term_cleanup['errors'] as $error) {
                $this->append_result_error($result, $error);
            }
        }

        if (!empty($term_cleanup['deleted_term_names'])) {
            foreach ($term_cleanup['deleted_term_names'] as $term_name) {
                $this->maybe_add_analysis_sample($result['samples']['categories_deleted'], $term_name);
            }
        }
    }

    /**
     * Delete empty supplier categories for one source key.
     *
     * @param string     $source_key
     * @param array|null $restrict_term_ids
     * @return array
     */
    private function delete_empty_supplier_terms($source_key, $restrict_term_ids = null)
    {
        $terms = $this->get_supplier_terms_by_source_key($source_key, $restrict_term_ids);
        if (empty($terms)) {
            return array(
                'deleted' => 0,
                'skipped' => 0,
                'errors' => array(),
                'deleted_term_names' => array(),
            );
        }

        usort($terms, function ($a, $b) {
            $depth_a = count(get_ancestors((int) $a->term_id, 'product_cat', 'taxonomy'));
            $depth_b = count(get_ancestors((int) $b->term_id, 'product_cat', 'taxonomy'));

            if ($depth_a === $depth_b) {
                return (int) $b->term_id <=> (int) $a->term_id;
            }

            return $depth_b <=> $depth_a;
        });

        $result = array(
            'deleted' => 0,
            'skipped' => 0,
            'errors' => array(),
            'deleted_term_names' => array(),
        );

        foreach ($terms as $term) {
            $term_id = (int) $term->term_id;
            $fresh_term = get_term($term_id, 'product_cat');

            if (!$fresh_term || is_wp_error($fresh_term)) {
                continue;
            }

            if ((string) get_term_meta($term_id, self::TERM_META_SOURCE_KEY, true) !== $source_key) {
                $result['skipped']++;
                continue;
            }

            if ((int) $fresh_term->count > 0) {
                $result['skipped']++;
                continue;
            }

            $deleted = wp_delete_term($term_id, 'product_cat');
            if (is_wp_error($deleted) || !$deleted) {
                $result['errors'][] = 'Не вдалося видалити категорію #' . $term_id . ' "' . $fresh_term->name . '"';
                continue;
            }

            $result['deleted']++;
            $result['deleted_term_names'][] = $fresh_term->name;
        }

        return $result;
    }

    /**
     * Load supplier categories by source key.
     *
     * @param string     $source_key
     * @param array|null $restrict_term_ids
     * @return array
     */
    private function get_supplier_terms_by_source_key($source_key, $restrict_term_ids = null)
    {
        $args = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'meta_query' => array(
                array(
                    'key' => self::TERM_META_SOURCE_KEY,
                    'value' => $source_key,
                ),
            ),
        );

        if (is_array($restrict_term_ids)) {
            $restrict_term_ids = array_values(array_unique(array_filter(array_map('intval', $restrict_term_ids))));
            if (empty($restrict_term_ids)) {
                return array();
            }

            $args['include'] = $restrict_term_ids;
        }

        $terms = get_terms($args);
        if (is_wp_error($terms) || empty($terms)) {
            return array();
        }

        return $terms;
    }

    /**
     * Append one error to a per-feed result row.
     *
     * @param array  $result
     * @param string $message
     * @return void
     */
    private function append_result_error(&$result, $message)
    {
        if ($message === '') {
            return;
        }

        if (empty($result['errors']) || !is_array($result['errors'])) {
            $result['errors'] = array();
        }

        $result['errors'][] = $message;
    }

    /**
     * Build invalid offer lookup maps for cleanup.
     *
     * @param array $offers
     * @return array
     */
    private function build_invalid_offer_map($offers)
    {
        $map = array(
            'external_ids' => array(),
            'skus'         => array(),
        );

        foreach ($offers as $offer) {
            $reason      = $this->get_offer_skip_reason($offer);
            $external_id = trim((string) ($offer['id'] ?? ''));
            $sku         = trim((string) ($offer['sku'] ?? ''));

            if ($reason === '') {
                continue;
            }

            if ($external_id !== '') {
                $map['external_ids'][$external_id] = $reason;
            }

            if ($sku !== '') {
                $map['skus'][$sku] = $reason;
            }
        }

        return $map;
    }

    /**
     * Load a page of already imported supplier products for one source key.
     *
     * @param string $source_key
     * @param int    $after_id
     * @param int    $limit
     * @return array
     */
    private function get_supplier_products_page($source_key, $after_id = 0, $limit = 25)
    {
        global $wpdb;

        $after_id = max(0, (int) $after_id);
        $limit    = max(1, (int) $limit);

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status,
                    external_id.meta_value AS external_id,
                    sku.meta_value AS sku,
                    stock.meta_value AS stock_status,
                    thumb.meta_value AS thumbnail_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} src
                ON src.post_id = p.ID
               AND src.meta_key = %s
               AND src.meta_value = %s
             LEFT JOIN {$wpdb->postmeta} external_id
                ON external_id.post_id = p.ID
               AND external_id.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} sku
                ON sku.post_id = p.ID
               AND sku.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} stock
                ON stock.post_id = p.ID
               AND stock.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} thumb
                ON thumb.post_id = p.ID
               AND thumb.meta_key = %s
             WHERE p.post_type = 'product'
               AND p.post_status <> 'trash'
               AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            self::META_SOURCE_KEY,
            $source_key,
            self::META_EXTERNAL_ID,
            '_sku',
            '_stock_status',
            '_thumbnail_id',
            $after_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * Decide whether an imported product should be removed from the catalog.
     *
     * @param array $product_row
     * @param array $invalid_offer_map
     * @return string
     */
    private function get_cleanup_reason_for_product($product_row, $invalid_offer_map)
    {
        $external_id   = trim((string) ($product_row['external_id'] ?? ''));
        $sku           = trim((string) ($product_row['sku'] ?? ''));
        $stock_status  = (string) ($product_row['stock_status'] ?? '');
        $thumbnail_id  = (int) ($product_row['thumbnail_id'] ?? 0);

        if ($stock_status === 'outofstock') {
            return 'немає в наявності на сайті';
        }

        if ($thumbnail_id <= 0) {
            return 'немає картинки на сайті';
        }

        if ($external_id !== '' && !empty($invalid_offer_map['external_ids'][$external_id])) {
            return $invalid_offer_map['external_ids'][$external_id];
        }

        if ($sku !== '' && !empty($invalid_offer_map['skus'][$sku])) {
            return $invalid_offer_map['skus'][$sku];
        }

        return '';
    }

    /**
     * Find WooCommerce product by combined supplier external key.
     *
     * @param string $external_key
     * @return int
     */
    private function find_product_by_external_key($external_key)
    {
        global $wpdb;

        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value = %s
             LIMIT 1",
            self::META_EXTERNAL_KEY,
            $external_key
        ));

        return $post_id ? (int) $post_id : 0;
    }

    /**
     * Find category term by combined supplier category key.
     *
     * @param string $source_key
     * @param string $external_id
     * @return int
     */
    private function find_term_by_external_category($source_key, $external_id)
    {
        global $wpdb;

        $term_id = $wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta}
             WHERE meta_key = %s AND meta_value = %s
             LIMIT 1",
            self::TERM_META_CATEGORY_KEY,
            $this->build_external_category_key($source_key, $external_id)
        ));

        return $term_id ? (int) $term_id : 0;
    }

    /**
     * Get shop node for different root formats.
     *
     * @param SimpleXMLElement $xml
     * @return SimpleXMLElement
     */
    private function get_shop_node($xml)
    {
        if ($xml->getName() === 'yml_catalog' && isset($xml->shop)) {
            return $xml->shop;
        }

        if ($xml->getName() === 'shop') {
            return $xml;
        }

        if (isset($xml->shop)) {
            return $xml->shop;
        }

        return $xml;
    }

    /**
     * Build stable source key.
     *
     * @param string $url
     * @return string
     */
    private function build_source_key($url)
    {
        return 'supplier_' . md5($url);
    }

    /**
     * Build combined external product key.
     *
     * @param string $source_key
     * @param string $external_id
     * @return string
     */
    private function build_external_product_key($source_key, $external_id)
    {
        return $source_key . ':' . $external_id;
    }

    /**
     * Build combined external category key.
     *
     * @param string $source_key
     * @param string $external_id
     * @return string
     */
    private function build_external_category_key($source_key, $external_id)
    {
        return $source_key . ':' . $external_id;
    }

    /**
     * Parse decimal number from feed values.
     *
     * @param string $value
     * @return float
     */
    private function parse_decimal($value)
    {
        $value = str_replace(array(' ', "\xc2\xa0"), '', trim((string) $value));
        $value = str_replace(',', '.', $value);
        return (float) $value;
    }

    /**
     * Resolve boolean availability from different offer formats.
     *
     * @param SimpleXMLElement $offer
     * @param SimpleXMLElement $attrs
     * @return bool
     */
    private function resolve_offer_availability($offer, $attrs)
    {
        $raw_values = array(
            (string) ($attrs['available'] ?? ''),
            (string) ($attrs['in_stock'] ?? ''),
            (string) ($offer->available ?? ''),
            (string) ($offer->in_stock ?? ''),
            (string) ($offer->presence ?? ''),
        );

        foreach ($raw_values as $raw) {
            $raw = strtolower(trim($raw));
            if ($raw === '') {
                continue;
            }
            if (in_array($raw, array('true', '1', 'yes', 'available', 'in stock', 'instock'), true)) {
                return true;
            }
            if (in_array($raw, array('false', '0', 'no', 'not available', 'outofstock', 'out of stock', 'not_available'), true)) {
                return false;
            }
        }

        $quantity = $this->extract_offer_quantity($offer);
        if ($quantity !== null) {
            return $quantity > 0;
        }

        return true;
    }

    /**
     * Extract quantity from different feed tags.
     *
     * @param SimpleXMLElement $offer
     * @return int|null
     */
    private function extract_offer_quantity($offer)
    {
        $candidates = array(
            isset($offer->quantity_in_stock) ? (string) $offer->quantity_in_stock : '',
            isset($offer->quantity) ? (string) $offer->quantity : '',
            isset($offer->quantityInStock) ? (string) $offer->quantityInStock : '',
        );

        foreach ($candidates as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            return (int) $this->parse_decimal($value);
        }

        return null;
    }
}
