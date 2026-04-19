<?php
if (!defined('ABSPATH')) {
    exit;
}

class D14K_Cron_Manager
{
    const SUPPLIER_FEED_DUE_HOOK = 'd14k_supplier_feed_due';
    const SUPPLIER_SCHEDULE_OPTION = 'd14k_supplier_feed_schedule_state';

    private $generator;
    private $yml_generator;
    private $csv_generator;
    private $wpml;
    private $prom_importer;
    private $prom_exporter;
    private $supplier_feeds;

    public function __construct($generator, $yml_generator, $csv_generator, $wpml, $prom_importer = null, $prom_exporter = null, $supplier_feeds = null)
    {
        $this->generator      = $generator;
        $this->yml_generator  = $yml_generator;
        $this->csv_generator  = $csv_generator;
        $this->wpml           = $wpml;
        $this->prom_importer  = $prom_importer;
        $this->prom_exporter  = $prom_exporter;
        $this->supplier_feeds = $supplier_feeds;

        add_action('d14k_feed_cron_hook',           array($this, 'execute'));
        add_action('d14k_prom_import_cron',         array($this, 'execute_prom_import'));
        add_action('d14k_prom_import_next_page',    array($this, 'execute_prom_import_next_page'));
        add_action('d14k_prom_export_cron',         array($this, 'execute_prom_export'));
        add_action('d14k_supplier_feeds_cron',      array($this, 'execute_supplier_feeds'));
        add_action(self::SUPPLIER_FEED_DUE_HOOK,    array($this, 'handle_supplier_feed_due'), 10, 1);
        add_action('d14k_supplier_background_import_finalized', array($this, 'handle_supplier_background_import_finalized'));
    }

    // -------------------------------------------------------------------------
    // Original feed generation (GMC + YML + CSV)
    // -------------------------------------------------------------------------

    public function execute()
    {
        $settings = get_option('d14k_feed_settings', array());

        // Generate GMC feeds
        if (!empty($settings['enabled'])) {
            $languages = $this->wpml->get_active_languages();
            foreach ($languages as $lang) {
                try {
                    $this->generator->generate($lang);
                } catch (Exception $e) {
                    error_log('D14K Feed: Cron error [GMC/' . $lang . ']: ' . $e->getMessage());
                }
            }
        }

        // Generate YML feeds for enabled channels (single bilingual feed per channel)
        $channels = isset($settings['yml_channels']) ? $settings['yml_channels'] : array();
        foreach (D14K_YML_Generator::CHANNELS as $channel) {
            if (empty($channels[$channel])) {
                continue;
            }
            try {
                $this->yml_generator->generate($channel);
            } catch (Exception $e) {
                error_log('D14K Feed: Cron error [YML/' . $channel . ']: ' . $e->getMessage());
            }
        }

        // Generate CSV feeds (currently Horoshop only)
        if (!empty($channels['horoshop']) && $this->csv_generator) {
            try {
                $this->csv_generator->generate('horoshop');
            } catch (Exception $e) {
                error_log('D14K Feed: Cron error [CSV/horoshop]: ' . $e->getMessage());
            }
        }

        // After feed generation — push to Prom if auto-export is enabled
        if (!empty($settings['prom_auto_export_after_feed']) && $this->prom_exporter) {
            try {
                $this->prom_exporter->push_feed_to_prom();
            } catch (Exception $e) {
                error_log('D14K Feed: Cron error [Prom export]: ' . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Prom.ua import cron (Prom → WooCommerce)
    // -------------------------------------------------------------------------

    /**
     * Cron handler: import or update products from Prom.ua.
     * Mode is controlled by settings:
     *   - 'full'   : import everything (new + updated)
     *   - 'prices' : only update prices and stock on already-imported products
     */
    public function execute_prom_import()
    {
        if (!$this->prom_importer) {
            return;
        }

        $settings = get_option('d14k_feed_settings', array());
        if (empty($settings['prom_import_enabled'])) {
            return;
        }

        $mode = isset($settings['prom_import_mode']) ? $settings['prom_import_mode'] : 'full';

        try {
            if ($mode === 'prices') {
                $stats = $this->prom_importer->update_prices_and_stock();
                error_log('D14K Prom price update [cron]: created=' . ($stats['updated'] ?? 0));
            } else {
                // Ланцюговий порційний імпорт: один крон = один батч (100 товарів).
                // Prom API пагінація через last_id (cursor), НЕ номер сторінки.
                $last_id = (int) get_transient('d14k_cron_import_last_id');
                // 0 = перший батч (початок)
                if ($last_id < 0) {
                    $last_id = 0;
                }
                delete_transient('d14k_import_running');

                $group_id = isset($settings['prom_import_group_id']) ? (int) $settings['prom_import_group_id'] : 0;
                $result   = $this->prom_importer->import_page($last_id, $group_id);

                if (!$result['done']) {
                    // Є ще батчі — зберігаємо last_id і плануємо наступний запуск за 60 сек
                    set_transient('d14k_cron_import_last_id', $result['next_last_id'], 3600);
                    if (!wp_next_scheduled('d14k_prom_import_next_page')) {
                        wp_schedule_single_event(time() + 60, 'd14k_prom_import_next_page');
                    }
                } else {
                    // Всі батчі оброблено — скидаємо стан
                    delete_transient('d14k_cron_import_last_id');
                    delete_transient('d14k_import_running');
                    $cum = $result['cumulative'] ?? array();
                    error_log('D14K Prom Import [cron] DONE: created=' . ($cum['created'] ?? 0)
                        . ' skipped=' . ($cum['skipped'] ?? 0) . ' errors=' . count($cum['errors'] ?? array()));
                }
            }
        } catch (Exception $e) {
            error_log('D14K Prom Import [cron] error: ' . $e->getMessage());
        }
    }

    /**
     * Cron handler for next-page chained import.
     * Called ~60 sec after previous page, continues from stored page number.
     */
    public function execute_prom_import_next_page()
    {
        // Просто делегуємо в той самий метод — він сам читає сторінку з транзієнта
        $this->execute_prom_import();
    }

    // -------------------------------------------------------------------------
    // Prom.ua export cron (WooCommerce → Prom)
    // -------------------------------------------------------------------------

    /**
     * Cron handler: push current WooCommerce catalog to Prom.ua via feed URL.
     */
    public function execute_prom_export()
    {
        if (!$this->prom_exporter) {
            return;
        }

        $settings = get_option('d14k_feed_settings', array());
        if (empty($settings['prom_export_enabled'])) {
            return;
        }

        try {
            $result = $this->prom_exporter->push_feed_to_prom();
            error_log('D14K Prom Export [cron]: ' . ($result['message'] ?? ''));
        } catch (Exception $e) {
            error_log('D14K Prom Export [cron] error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Supplier feeds cron
    // -------------------------------------------------------------------------

    /**
     * Cron handler: process all supplier XML feeds.
     */
    public function execute_supplier_feeds()
    {
        $this->sync_supplier_feed_schedule();

        if (!$this->supplier_feeds) {
            return;
        }

        $settings = get_option('d14k_feed_settings', array());
        if (empty($settings['supplier_feeds_enabled'])) {
            return;
        }

        foreach ($this->get_saved_supplier_feeds($settings) as $feed) {
            $next_due = $this->get_supplier_feed_next_due_timestamp($feed['url']);
            if (!$next_due || $next_due > time()) {
                continue;
            }

            $this->handle_supplier_feed_due($feed['url']);
        }
    }

    // -------------------------------------------------------------------------
    // Schedule management
    // -------------------------------------------------------------------------

    /**
     * Force regeneration of all enabled YML feeds.
     */
    public function regenerate_yml_feeds()
    {
        $settings = get_option('d14k_feed_settings', array());
        $channels = isset($settings['yml_channels']) ? $settings['yml_channels'] : array();
        $results  = array();

        foreach (D14K_YML_Generator::CHANNELS as $channel) {
            if (empty($channels[$channel])) {
                continue;
            }
            try {
                $ok              = $this->yml_generator->generate($channel);
                $results[$channel] = $ok;
            } catch (Exception $e) {
                $results[$channel] = false;
                error_log('D14K Feed: Manual YML generation error [' . $channel . ']: ' . $e->getMessage());
            }
        }

        if (!empty($channels['horoshop']) && $this->csv_generator) {
            try {
                $ok                = $this->csv_generator->generate('horoshop');
                $results['horoshop'] = $ok;
            } catch (Exception $e) {
                $results['horoshop'] = false;
                error_log('D14K Feed: Manual CSV generation error [horoshop]: ' . $e->getMessage());
            }
        }

        return $results;
    }

    public function reschedule($interval)
    {
        wp_clear_scheduled_hook('d14k_feed_cron_hook');
        if (!wp_next_scheduled('d14k_feed_cron_hook')) {
            wp_schedule_event(time(), $interval, 'd14k_feed_cron_hook');
        }
    }

    /**
     * Schedule Prom import cron.
     *
     * @param string $interval  'daily', 'd14k_weekly', etc.
     * @param bool   $enable    Enable or disable
     */
    public function reschedule_prom_import($interval, $enable = true)
    {
        wp_clear_scheduled_hook('d14k_prom_import_cron');
        if ($enable && !wp_next_scheduled('d14k_prom_import_cron')) {
            wp_schedule_event(time(), $interval, 'd14k_prom_import_cron');
        }
    }

    /**
     * Schedule Prom export cron.
     */
    public function reschedule_prom_export($interval, $enable = true)
    {
        wp_clear_scheduled_hook('d14k_prom_export_cron');
        if ($enable && !wp_next_scheduled('d14k_prom_export_cron')) {
            wp_schedule_event(time(), $interval, 'd14k_prom_export_cron');
        }
    }

    /**
     * Schedule supplier feeds cron.
     */
    public function reschedule_supplier_feeds($interval = null, $enable = true)
    {
        wp_clear_scheduled_hook('d14k_supplier_feeds_cron');
        $this->sync_supplier_feed_schedule();
    }

    /**
     * Schedule daily generation at a specific local time + timezone (GMC-style).
     *
     * @param string $time     "HH:MM" in local timezone, e.g. "06:00"
     * @param string $timezone PHP timezone identifier, e.g. "Europe/Kiev"
     */
    public function reschedule_at_time($time, $timezone)
    {
        wp_clear_scheduled_hook('d14k_feed_cron_hook');

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception $e) {
            $tz = new DateTimeZone('Europe/Kiev');
        }

        list($hour, $minute) = array_map('intval', explode(':', $time . ':00'));

        // Build the next occurrence of HH:MM in the chosen timezone
        $now_local = new DateTime('now', $tz);
        $next      = new DateTime('now', $tz);
        $next->setTime($hour, $minute, 0);

        // If that time already passed today — move to tomorrow
        if ($next <= $now_local) {
            $next->modify('+1 day');
        }

        // Convert to UTC timestamp (what WP cron expects)
        $utc_timestamp = $next->getTimestamp();

        // Schedule as 'daily' recurring event
        wp_schedule_event($utc_timestamp, 'daily', 'd14k_feed_cron_hook');
    }

    public function get_next_run()
    {
        $timestamp = wp_next_scheduled('d14k_feed_cron_hook');
        if ($timestamp) {
            return $this->format_local_timestamp($timestamp);
        }
        return null;
    }

    public function get_next_prom_import_run()
    {
        $timestamp = wp_next_scheduled('d14k_prom_import_cron');
        return $timestamp ? $this->format_local_timestamp($timestamp) : null;
    }

    public function get_next_prom_export_run()
    {
        $timestamp = wp_next_scheduled('d14k_prom_export_cron');
        return $timestamp ? $this->format_local_timestamp($timestamp) : null;
    }

    public function get_next_supplier_feeds_run()
    {
        $timestamp = $this->get_next_supplier_feed_run_timestamp();
        return $timestamp ? $this->format_local_timestamp($timestamp) : null;
    }

    public function get_next_supplier_feed_run($feed)
    {
        $feed_url = is_array($feed) ? (string) ($feed['url'] ?? '') : (string) $feed;
        if ($feed_url === '') {
            return null;
        }

        $timestamp = $this->get_supplier_feed_next_due_timestamp($feed_url);
        return $timestamp ? $this->format_local_timestamp($timestamp) : null;
    }

    public function get_interval_options()
    {
        return array(
            'hourly'        => 'Кожну годину',
            'twicedaily'    => 'Двічі на добу',
            'daily'         => 'Раз на добу',
            'd14k_weekly'   => 'Раз на тиждень',
            'd14k_monthly'  => 'Раз на місяць',
        );
    }

    public function get_supplier_weekday_options()
    {
        return array(
            'mon' => 'Понеділок',
            'tue' => 'Вівторок',
            'wed' => 'Середа',
            'thu' => 'Четвер',
            'fri' => 'П’ятниця',
            'sat' => 'Субота',
            'sun' => 'Неділя',
        );
    }

    public function get_supplier_schedule_defaults($settings = null)
    {
        $settings = is_array($settings) ? $settings : get_option('d14k_feed_settings', array());
        $interval = sanitize_key($settings['supplier_feeds_interval'] ?? 'daily');
        $interval_options = $this->get_interval_options();
        if (!isset($interval_options[$interval])) {
            $interval = 'daily';
        }

        $timestamp = wp_next_scheduled('d14k_supplier_feeds_cron');
        if (!$timestamp) {
            $timestamp = $this->get_next_supplier_feed_run_timestamp();
        }

        if (!$timestamp) {
            $local = current_datetime()->setTime(1, 0, 0);
        } else {
            $local = (new DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone());
        }

        return array(
            'schedule_interval' => $interval,
            'schedule_time'     => $local->format('H:i'),
            'schedule_weekday'  => strtolower($local->format('D')),
            'schedule_monthday' => min(28, max(1, (int) $local->format('j'))),
        );
    }

    public function handle_supplier_feed_due($feed_url = '')
    {
        $feed_url = esc_url_raw(trim((string) $feed_url));
        if ($feed_url === '') {
            return;
        }

        $settings = get_option('d14k_feed_settings', array());
        $feed     = $this->find_saved_supplier_feed($settings, $feed_url);
        $state    = $this->get_supplier_schedule_state();
        $due_at   = $state['feeds'][$feed_url]['next_due_at'] ?? 0;

        if (!$feed || empty($settings['supplier_feeds_enabled']) || empty($feed['enabled'])) {
            $this->drop_supplier_feed_schedule($feed_url, $state);
            return;
        }

        $this->schedule_supplier_feed_due_event($feed, time(), $state);

        if ($this->is_supplier_feed_pending_or_running($feed_url, $state)) {
            return;
        }

        if ($this->is_supplier_background_busy()) {
            $this->enqueue_supplier_feed($feed, $due_at ?: time(), $state);
            return;
        }

        $result = $this->supplier_feeds->start_background_import(25, false, array($feed), true);
        if (!is_wp_error($result)) {
            return;
        }

        if ($result->get_error_code() === 'background_busy') {
            $this->enqueue_supplier_feed($feed, $due_at ?: time(), $state);
            return;
        }

        error_log('D14K Supplier Feed [due] error for ' . $feed_url . ': ' . $result->get_error_message());
    }

    public function handle_supplier_background_import_finalized($state = array())
    {
        $status = is_array($state) ? (string) ($state['status'] ?? '') : '';

        if ($status === 'cancelled') {
            $schedule_state = $this->get_supplier_schedule_state();
            $schedule_state['queue'] = array();
            $this->save_supplier_schedule_state($schedule_state);
            return;
        }

        $this->start_next_queued_supplier_feed();
    }

    private function start_next_queued_supplier_feed()
    {
        if (!$this->supplier_feeds || $this->is_supplier_background_busy()) {
            return;
        }

        $settings = get_option('d14k_feed_settings', array());
        if (empty($settings['supplier_feeds_enabled'])) {
            $state = $this->get_supplier_schedule_state();
            $state['queue'] = array();
            $this->save_supplier_schedule_state($state);
            return;
        }

        $state = $this->get_supplier_schedule_state();
        if (empty($state['queue']) || !is_array($state['queue'])) {
            return;
        }

        while (!empty($state['queue'])) {
            $entry = array_shift($state['queue']);
            $feed_url = esc_url_raw(trim((string) ($entry['feed_url'] ?? '')));
            if ($feed_url === '') {
                continue;
            }

            $feed = $this->find_saved_supplier_feed($settings, $feed_url);
            if (!$feed || empty($feed['enabled'])) {
                $this->drop_supplier_feed_schedule($feed_url, $state);
                continue;
            }

            $this->save_supplier_schedule_state($state);
            $result = $this->supplier_feeds->start_background_import(25, false, array($feed), true);

            if (!is_wp_error($result)) {
                return;
            }

            if ($result->get_error_code() === 'background_busy') {
                $this->enqueue_supplier_feed($feed, (int) ($entry['due_at'] ?? time()), $state);
                return;
            }

            error_log('D14K Supplier Feed [queue] error for ' . $feed_url . ': ' . $result->get_error_message());
        }

        $this->save_supplier_schedule_state($state);
    }

    private function sync_supplier_feed_schedule()
    {
        $settings = get_option('d14k_feed_settings', array());
        $feeds    = $this->get_saved_supplier_feeds($settings);
        $state    = $this->get_supplier_schedule_state();
        $active   = array();

        wp_clear_scheduled_hook('d14k_supplier_feeds_cron');

        if (empty($settings['supplier_feeds_enabled'])) {
            foreach (array_keys($state['feeds']) as $feed_url) {
                wp_clear_scheduled_hook(self::SUPPLIER_FEED_DUE_HOOK, array($feed_url));
            }
            $state['feeds'] = array();
            $state['queue'] = array();
            $this->save_supplier_schedule_state($state);
            return;
        }

        foreach ($feeds as $feed) {
            if (empty($feed['enabled'])) {
                continue;
            }

            $active[$feed['url']] = true;
            $this->schedule_supplier_feed_due_event($feed, null, $state);
        }

        foreach (array_keys($state['feeds']) as $feed_url) {
            if (isset($active[$feed_url])) {
                continue;
            }

            $this->drop_supplier_feed_schedule($feed_url, $state);
        }

        if (!empty($state['queue'])) {
            $state['queue'] = array_values(array_filter($state['queue'], function ($entry) use ($active) {
                $feed_url = esc_url_raw(trim((string) ($entry['feed_url'] ?? '')));
                return $feed_url !== '' && isset($active[$feed_url]);
            }));
        }

        $this->save_supplier_schedule_state($state);
    }

    private function schedule_supplier_feed_due_event($feed, $after_timestamp = null, &$state = null)
    {
        $state = is_array($state) ? $state : $this->get_supplier_schedule_state();
        $feed_url = esc_url_raw(trim((string) ($feed['url'] ?? '')));

        if ($feed_url === '') {
            return 0;
        }

        $signature = $this->build_supplier_schedule_signature($feed);
        $next_due  = $this->calculate_next_supplier_feed_due_timestamp($feed, $after_timestamp);
        $existing  = (int) ($state['feeds'][$feed_url]['next_due_at'] ?? 0);
        $current_signature = (string) ($state['feeds'][$feed_url]['signature'] ?? '');
        $scheduled = (int) wp_next_scheduled(self::SUPPLIER_FEED_DUE_HOOK, array($feed_url));

        if ($scheduled && $scheduled !== $existing) {
            wp_clear_scheduled_hook(self::SUPPLIER_FEED_DUE_HOOK, array($feed_url));
            $scheduled = 0;
        }

        if ($existing && ($existing !== $next_due || $current_signature !== $signature)) {
            wp_clear_scheduled_hook(self::SUPPLIER_FEED_DUE_HOOK, array($feed_url));
            $existing = 0;
            $scheduled = 0;
        }

        if (!$scheduled) {
            wp_schedule_single_event($next_due, self::SUPPLIER_FEED_DUE_HOOK, array($feed_url));
        }

        $state['feeds'][$feed_url] = array(
            'label'       => sanitize_text_field((string) ($feed['label'] ?? '')),
            'next_due_at' => $next_due,
            'signature'   => $signature,
        );

        return $next_due;
    }

    private function drop_supplier_feed_schedule($feed_url, &$state = null)
    {
        $state = is_array($state) ? $state : $this->get_supplier_schedule_state();
        $feed_url = esc_url_raw(trim((string) $feed_url));
        if ($feed_url === '') {
            return;
        }

        wp_clear_scheduled_hook(self::SUPPLIER_FEED_DUE_HOOK, array($feed_url));
        unset($state['feeds'][$feed_url]);

        if (!empty($state['queue'])) {
            $state['queue'] = array_values(array_filter($state['queue'], function ($entry) use ($feed_url) {
                return (string) ($entry['feed_url'] ?? '') !== $feed_url;
            }));
        }

        $this->save_supplier_schedule_state($state);
    }

    private function enqueue_supplier_feed($feed, $due_at, &$state = null)
    {
        $state = is_array($state) ? $state : $this->get_supplier_schedule_state();
        $feed_url = esc_url_raw(trim((string) ($feed['url'] ?? '')));
        if ($feed_url === '') {
            return;
        }

        foreach ($state['queue'] as $entry) {
            if ((string) ($entry['feed_url'] ?? '') === $feed_url) {
                return;
            }
        }

        $state['queue'][] = array(
            'feed_url'  => $feed_url,
            'label'     => sanitize_text_field((string) ($feed['label'] ?? '')),
            'due_at'    => max(0, (int) $due_at),
            'queued_at' => time(),
        );

        usort($state['queue'], function ($left, $right) {
            $left_due  = (int) ($left['due_at'] ?? 0);
            $right_due = (int) ($right['due_at'] ?? 0);

            if ($left_due === $right_due) {
                return (int) ($left['queued_at'] ?? 0) <=> (int) ($right['queued_at'] ?? 0);
            }

            return $left_due <=> $right_due;
        });

        $this->save_supplier_schedule_state($state);
    }

    private function is_supplier_feed_pending_or_running($feed_url, $state = null)
    {
        $feed_url = esc_url_raw(trim((string) $feed_url));
        if ($feed_url === '') {
            return false;
        }

        if ($this->supplier_feeds) {
            $background = $this->supplier_feeds->get_background_import_status();
            $background_urls = isset($background['scope_feed_urls']) && is_array($background['scope_feed_urls'])
                ? $background['scope_feed_urls']
                : array();

            if (
                in_array((string) ($background['status'] ?? ''), array('queued', 'running'), true)
                && in_array($feed_url, $background_urls, true)
            ) {
                return true;
            }
        }

        $state = is_array($state) ? $state : $this->get_supplier_schedule_state();
        foreach ($state['queue'] as $entry) {
            if ((string) ($entry['feed_url'] ?? '') === $feed_url) {
                return true;
            }
        }

        return false;
    }

    private function is_supplier_background_busy()
    {
        if (!$this->supplier_feeds) {
            return false;
        }

        $background = $this->supplier_feeds->get_background_import_status();
        return in_array((string) ($background['status'] ?? ''), array('queued', 'running'), true);
    }

    private function get_next_supplier_feed_run_timestamp()
    {
        $state = $this->get_supplier_schedule_state();
        $timestamps = array();

        foreach ((array) ($state['feeds'] ?? array()) as $feed_state) {
            $next_due = (int) ($feed_state['next_due_at'] ?? 0);
            if ($next_due > 0) {
                $timestamps[] = $next_due;
            }
        }

        if (empty($timestamps)) {
            return 0;
        }

        sort($timestamps);
        return (int) $timestamps[0];
    }

    private function get_supplier_feed_next_due_timestamp($feed_url)
    {
        $feed_url = esc_url_raw(trim((string) $feed_url));
        if ($feed_url === '') {
            return 0;
        }

        $state = $this->get_supplier_schedule_state();
        return (int) ($state['feeds'][$feed_url]['next_due_at'] ?? 0);
    }

    private function get_saved_supplier_feeds($settings = null)
    {
        $settings = is_array($settings) ? $settings : get_option('d14k_feed_settings', array());
        $feeds    = isset($settings['supplier_feeds']) && is_array($settings['supplier_feeds'])
            ? $settings['supplier_feeds']
            : array();

        $saved = array();
        foreach ($feeds as $feed) {
            if (empty($feed['url'])) {
                continue;
            }

            $saved[] = $this->normalize_saved_supplier_feed($feed, $settings);
        }

        return $saved;
    }

    private function find_saved_supplier_feed($settings, $feed_url)
    {
        foreach ($this->get_saved_supplier_feeds($settings) as $feed) {
            if ((string) ($feed['url'] ?? '') === $feed_url) {
                return $feed;
            }
        }

        return null;
    }

    private function normalize_saved_supplier_feed($feed, $settings = null)
    {
        $settings = is_array($settings) ? $settings : get_option('d14k_feed_settings', array());
        $defaults = $this->get_supplier_schedule_defaults($settings);
        $interval_options = $this->get_interval_options();
        $weekday_options = $this->get_supplier_weekday_options();

        $interval = sanitize_key($feed['schedule_interval'] ?? $defaults['schedule_interval']);
        if (!isset($interval_options[$interval])) {
            $interval = $defaults['schedule_interval'];
        }

        $time = trim((string) ($feed['schedule_time'] ?? $defaults['schedule_time']));
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = $defaults['schedule_time'];
        }

        $weekday = strtolower((string) ($feed['schedule_weekday'] ?? $defaults['schedule_weekday']));
        if (!isset($weekday_options[$weekday])) {
            $weekday = $defaults['schedule_weekday'];
        }

        $monthday = (int) ($feed['schedule_monthday'] ?? $defaults['schedule_monthday']);
        $monthday = min(28, max(1, $monthday));

        $feed['url'] = esc_url_raw(trim((string) ($feed['url'] ?? '')));
        $feed['label'] = sanitize_text_field((string) ($feed['label'] ?? ''));
        $feed['enabled'] = !empty($feed['enabled']);
        $feed['schedule_interval'] = $interval;
        $feed['schedule_time'] = $time;
        $feed['schedule_weekday'] = $weekday;
        $feed['schedule_monthday'] = $monthday;

        return $feed;
    }

    private function build_supplier_schedule_signature($feed)
    {
        return implode('|', array(
            sanitize_key((string) ($feed['schedule_interval'] ?? 'daily')),
            (string) ($feed['schedule_time'] ?? '01:00'),
            strtolower((string) ($feed['schedule_weekday'] ?? 'mon')),
            (int) ($feed['schedule_monthday'] ?? 1),
        ));
    }

    private function calculate_next_supplier_feed_due_timestamp($feed, $after_timestamp = null)
    {
        $feed = $this->normalize_saved_supplier_feed($feed);
        $timezone = wp_timezone();
        $after = $after_timestamp
            ? (new DateTimeImmutable('@' . (int) $after_timestamp))->setTimezone($timezone)
            : current_datetime();

        list($hour, $minute) = array_map('intval', explode(':', ($feed['schedule_time'] ?? '01:00') . ':00'));
        $interval = (string) ($feed['schedule_interval'] ?? 'daily');

        if ($interval === 'hourly') {
            $candidate = $after->setTime((int) $after->format('H'), $minute, 0);
            if ($candidate <= $after) {
                $candidate = $candidate->modify('+1 hour');
            }

            return $candidate->getTimestamp();
        }

        if ($interval === 'twicedaily') {
            $first = $after->setTime($hour, $minute, 0);
            $second = $first->modify('+12 hours');

            if ($first > $after) {
                return $first->getTimestamp();
            }

            if ($second > $after) {
                return $second->getTimestamp();
            }

            return $first->modify('+1 day')->getTimestamp();
        }

        if ($interval === 'd14k_weekly') {
            $weekday_map = array(
                'mon' => 1,
                'tue' => 2,
                'wed' => 3,
                'thu' => 4,
                'fri' => 5,
                'sat' => 6,
                'sun' => 7,
            );
            $target_weekday = $weekday_map[$feed['schedule_weekday']] ?? 1;
            $candidate = $after->setTime($hour, $minute, 0);
            $current_weekday = (int) $candidate->format('N');
            $days_ahead = ($target_weekday - $current_weekday + 7) % 7;
            if ($days_ahead > 0) {
                $candidate = $candidate->modify('+' . $days_ahead . ' days');
            }
            if ($candidate <= $after) {
                $candidate = $candidate->modify('+7 days');
            }

            return $candidate->getTimestamp();
        }

        if ($interval === 'd14k_monthly') {
            $target_day = (int) ($feed['schedule_monthday'] ?? 1);
            $candidate = $after->setDate(
                (int) $after->format('Y'),
                (int) $after->format('n'),
                min($target_day, 28)
            )->setTime($hour, $minute, 0);

            if ($candidate <= $after) {
                $candidate = $candidate->modify('first day of next month')
                    ->setDate(
                        (int) $candidate->format('Y'),
                        (int) $candidate->format('n'),
                        min($target_day, 28)
                    )
                    ->setTime($hour, $minute, 0);
            }

            return $candidate->getTimestamp();
        }

        $candidate = $after->setTime($hour, $minute, 0);
        if ($candidate <= $after) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->getTimestamp();
    }

    private function get_supplier_schedule_state()
    {
        $state = get_option(self::SUPPLIER_SCHEDULE_OPTION, array());
        if (!is_array($state)) {
            $state = array();
        }

        if (empty($state['feeds']) || !is_array($state['feeds'])) {
            $state['feeds'] = array();
        }

        if (empty($state['queue']) || !is_array($state['queue'])) {
            $state['queue'] = array();
        }

        return $state;
    }

    private function save_supplier_schedule_state($state)
    {
        update_option(self::SUPPLIER_SCHEDULE_OPTION, $state, false);
    }

    private function format_local_timestamp($timestamp)
    {
        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            return null;
        }

        return wp_date('d.m.Y H:i:s', $timestamp, wp_timezone());
    }
}
