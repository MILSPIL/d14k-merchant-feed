<?php
if (!defined('ABSPATH')) {
    exit;
}

class D14K_Cron_Manager
{

    private $generator;
    private $wpml;

    public function __construct($generator, $wpml)
    {
        $this->generator = $generator;
        $this->wpml = $wpml;
        add_action('d14k_feed_cron_hook', array($this, 'execute'));
    }

    public function execute()
    {
        $settings = get_option('d14k_feed_settings', array());
        if (empty($settings['enabled'])) {
            return;
        }
        $languages = $this->wpml->get_active_languages();
        foreach ($languages as $lang) {
            try {
                $this->generator->generate($lang);
            } catch (Exception $e) {
                error_log('D14K Feed: Cron error [' . $lang . ']: ' . $e->getMessage());
            }
        }
    }

    public function reschedule($interval)
    {
        wp_clear_scheduled_hook('d14k_feed_cron_hook');
        if (!wp_next_scheduled('d14k_feed_cron_hook')) {
            wp_schedule_event(time(), $interval, 'd14k_feed_cron_hook');
        }
    }

    public function get_next_run()
    {
        $timestamp = wp_next_scheduled('d14k_feed_cron_hook');
        if ($timestamp) {
            return date_i18n('d.m.Y H:i:s', $timestamp);
        }
        return null;
    }

    public function get_interval_options()
    {
        return array(
            'd14k_every_3_hours' => 'Кожні 3 години',
            'd14k_every_6_hours' => 'Кожні 6 годин',
            'd14k_every_12_hours' => 'Кожні 12 годин',
            'daily' => 'Раз на добу',
            'd14k_weekly' => 'Раз на тиждень',
            'd14k_monthly' => 'Раз на місяць',
        );
    }
}
