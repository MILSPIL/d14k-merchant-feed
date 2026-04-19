<?php
if (!defined('ABSPATH')) {
    exit;
}

class D14K_Admin_Settings
{

    private $generator;
    private $yml_generator;
    private $csv_generator;
    private $wpml;
    private $cron;
    private $prom_api;
    private $prom_importer;
    private $prom_exporter;
    private $supplier_feeds;

    public function __construct($generator, $yml_generator, $csv_generator, $wpml, $cron, $prom_api = null, $prom_importer = null, $prom_exporter = null, $supplier_feeds = null)
    {
        $this->generator      = $generator;
        $this->yml_generator  = $yml_generator;
        $this->csv_generator  = $csv_generator;
        $this->wpml           = $wpml;
        $this->cron           = $cron;
        $this->prom_api       = $prom_api;
        $this->prom_importer  = $prom_importer;
        $this->prom_exporter  = $prom_exporter;
        $this->supplier_feeds = $supplier_feeds;

        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_link'), 100);
        add_action('admin_post_d14k_save_settings', array($this, 'save_settings'));
        add_action('admin_post_d14k_generate_now', array($this, 'handle_generate_now'));
        add_action('admin_post_d14k_test_validation', array($this, 'handle_test_validation'));

        // AJAX handlers for channel toggles and per-channel generation
        add_action('wp_ajax_d14k_toggle_channel', array($this, 'ajax_toggle_channel'));
        add_action('wp_ajax_d14k_generate_channel', array($this, 'ajax_generate_channel'));

        // AJAX handlers for Prom.ua integration
        add_action('wp_ajax_d14k_prom_test_api',      array($this, 'ajax_prom_test_api'));
        add_action('wp_ajax_d14k_prom_import_now',    array($this, 'ajax_prom_import_now'));
        add_action('wp_ajax_d14k_prom_export_now',    array($this, 'ajax_prom_export_now'));
        add_action('wp_ajax_d14k_prom_import_status', array($this, 'ajax_prom_import_status'));
        add_action('wp_ajax_d14k_supplier_feeds_analyze', array($this, 'ajax_supplier_feeds_analyze'));
        add_action('wp_ajax_d14k_supplier_feed_analyze_row', array($this, 'ajax_supplier_feed_analyze_row'));
        add_action('wp_ajax_d14k_supplier_feeds_run', array($this, 'ajax_supplier_feeds_run'));
        add_action('wp_ajax_d14k_supplier_feeds_background_start', array($this, 'ajax_supplier_feeds_background_start'));
        add_action('wp_ajax_d14k_supplier_feeds_background_status', array($this, 'ajax_supplier_feeds_background_status'));
        add_action('wp_ajax_d14k_supplier_feeds_background_cancel', array($this, 'ajax_supplier_feeds_background_cancel'));
        add_action('wp_ajax_d14k_supplier_feeds_cleanup', array($this, 'ajax_supplier_feeds_cleanup'));
        add_action('wp_ajax_d14k_supplier_feeds_rollback_last_import', array($this, 'ajax_supplier_feeds_rollback_last_import'));
        add_action('wp_ajax_d14k_supplier_feed_save_row', array($this, 'ajax_supplier_feed_save_row'));
    }

    public function add_menu()
    {
        add_menu_page(
            'MIL SPIL Feed Generator',
            'MIL SPIL Feed',
            'manage_woocommerce',
            'd14k-merchant-feed',
            array($this, 'render_page'),
            'dashicons-cloud-upload',
            56
        );
    }

    public function add_admin_bar_link($admin_bar)
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $admin_bar->add_node(array(
            'id' => 'd14k-merchant-feed-bar',
            'title' => 'Feed Generator',
            'href' => admin_url('admin.php?page=d14k-merchant-feed'),
            'meta' => array(
                'title' => 'Feed Generator Settings',
            ),
        ));
    }

    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'd14k-merchant-feed') === false) {
            return;
        }
        $css_ver = D14K_FEED_VERSION . '.' . filemtime(D14K_FEED_PATH . 'assets/admin.css');
        $js_ver = D14K_FEED_VERSION . '.' . filemtime(D14K_FEED_PATH . 'assets/admin.js');
        wp_enqueue_style('d14k-admin', D14K_FEED_URL . 'assets/admin.css', array(), $css_ver);
        wp_enqueue_script('d14k-admin', D14K_FEED_URL . 'assets/admin.js', array(), $js_ver, true);
        wp_localize_script('d14k-admin', 'd14kFeed', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('d14k_ajax_nonce'),
        ));
    }

    public function render_page()
    {
        $settings = get_option('d14k_feed_settings', $this->get_defaults());
        $prom_settings = $settings;
        $languages = $this->wpml->get_active_languages();
        $intervals = $this->cron->get_interval_options();
        $next_run = $this->cron->get_next_run();
        $last_import = get_option('d14k_prom_import_last_stats', array());
        $last_export = get_option('d14k_prom_last_export', array());
        $supplier_feeds_list = isset($prom_settings['supplier_feeds']) ? (array) $prom_settings['supplier_feeds'] : array();
        $supplier_last_run = get_option('d14k_supplier_feeds_last_run', array());
        $supplier_last_cleanup = get_option('d14k_supplier_feeds_last_cleanup', array());
        // WPML: build translation map term_id => [all lang term_ids]
        $wpml_trans_map = array();
        global $wpdb;
        $_tr = $wpdb->get_results("SELECT tt.term_id, tr.trid FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->prefix}icl_translations tr ON tr.element_id=tt.term_taxonomy_id WHERE tt.taxonomy='product_cat'");
        $_tg = array();
        foreach ($_tr as $_r) {
            $_tg[(int) $_r->trid][] = (int) $_r->term_id;
        }
        foreach ($_tg as $_ti) {
            foreach ($_ti as $_id) {
                $wpml_trans_map[$_id] = array_values(array_unique($_ti));
            }
        }
        // Load only default language categories for display
        $default_lang = apply_filters('wpml_default_language', null);
        $cats_query_args = array('taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name');
        if ($default_lang) {
            $cats_query_args['lang'] = $default_lang;
        }
        $wc_cats = get_terms($cats_query_args);
        $excluded_cat_ids = isset($settings['excluded_categories']) ? array_map('intval', $settings['excluded_categories']) : array();
        // Build category hierarchy for tree rendering
        $cat_by_id = array();
        $cat_children = array();
        if ($wc_cats && !is_wp_error($wc_cats)) {
            foreach ($wc_cats as $_cat) {
                $cat_by_id[$_cat->term_id] = $_cat;
                $cat_children[(int) $_cat->parent][] = $_cat->term_id;
            }
        }
        $settings = $this->apply_category_presets($settings, $wc_cats);
        $wc_attributes = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : array();
        if (!is_array($wc_attributes)) {
            $wc_attributes = array();
        }

        $attr_index = array();
        foreach ($wc_attributes as $attr) {
            if (!isset($attr->attribute_name) || $attr->attribute_name === '') {
                continue;
            }
            $attr_index[(string) $attr->attribute_name] = $attr;
        }

        $attr_table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
        $db_attributes = $wpdb->get_results("SELECT attribute_name, attribute_label FROM {$attr_table} ORDER BY attribute_label ASC");
        if (is_array($db_attributes)) {
            foreach ($db_attributes as $db_attr) {
                if (!isset($db_attr->attribute_name) || $db_attr->attribute_name === '') {
                    continue;
                }
                if (!isset($attr_index[(string) $db_attr->attribute_name])) {
                    $attr_index[(string) $db_attr->attribute_name] = (object) array(
                        'attribute_name' => $db_attr->attribute_name,
                        'attribute_label' => $db_attr->attribute_label,
                    );
                }
            }
        }

        $wc_attributes = array_values($attr_index);
        $brand_attribute_options = array();
        foreach ($wc_attributes as $attr) {
            if (!isset($attr->attribute_name) || $attr->attribute_name === '') {
                continue;
            }
            $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);
            $brand_attribute_options[] = array(
                'value' => $taxonomy,
                'label' => sprintf(
                    '%s (%s)',
                    isset($attr->attribute_label) ? $attr->attribute_label : $attr->attribute_name,
                    $taxonomy
                ),
            );
        }
        $attr_terms_data = array();
        foreach ($wc_attributes as $attr) {
            $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);
            $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
            if (!is_wp_error($terms) && !empty($terms)) {
                $attr_terms_data[$taxonomy] = array(
                    'label' => $attr->attribute_label,
                    'terms' => $terms,
                );
            }
        }
        $custom_rules = isset($settings['custom_rules']) ? $settings['custom_rules'] : array();
        $rule_fields = array(
            'title' => array('label' => 'Назва товару', 'type' => 'text'),
            'sku' => array('label' => 'SKU', 'type' => 'text'),
            'regular_price' => array('label' => 'Ціна (звичайна)', 'type' => 'number'),
            'sale_price' => array('label' => 'Ціна зі знижкою', 'type' => 'number'),
            'stock_status' => array('label' => 'Статус наявності', 'type' => 'status'),
            'tag' => array('label' => 'Тег товару', 'type' => 'text'),
            'custom_meta' => array('label' => 'Власне поле (meta)', 'type' => 'meta'),
        );
        $rule_conditions = array(
            'text' => array('contains' => 'Містить', 'not_contains' => 'Не містить', 'equals' => 'Дорівнює', 'not_equals' => 'Не дорівнює', 'starts_with' => 'Починається з', 'ends_with' => 'Закінчується на', 'is_empty' => 'Порожнє', 'not_empty' => 'Не порожнє'),
            'number' => array('equals' => '= Дорівнює', 'not_equals' => '≠ Не дорівнює', 'gt' => '> Більше ніж', 'lt' => '< Менше ніж', 'gte' => '≥ Більше або рівне', 'lte' => '≤ Менше або рівне', 'is_empty' => 'Не вказана', 'not_empty' => 'Вказана'),
            'status' => array('equals' => 'Дорівнює', 'not_equals' => 'Не дорівнює'),
            'meta' => array('contains' => 'Містить', 'not_contains' => 'Не містить', 'equals' => 'Дорівнює', 'not_equals' => 'Не дорівнює', 'is_empty' => 'Порожнє', 'not_empty' => 'Не порожнє'),
        );

        $notice = isset($_GET['d14k_notice']) ? sanitize_text_field($_GET['d14k_notice']) : '';
        $show_validation = isset($_GET['show_validation']);

        ?>
        <div class="wrap d14k-wrap">
            <h1 class="d14k-screen-reader-anchor"></h1> <!-- Catch WP admin notices here -->

            <!-- Plugin Header -->
            <div class="d14k-header">
                <div class="d14k-header-icon">
                    <img src="<?php echo esc_url(D14K_FEED_URL . 'assets/logo.png?v=3'); ?>" alt="MIL SPIL Feed Generator"
                        class="d14k-header-logo" />
                </div>
                <div class="d14k-header-title">
                    <h1>MIL SPIL Feed Generator</h1>
                    <p>Google Merchant Center + Маркетплейси (Horoshop, Prom.ua, Rozetka)</p>
                </div>
                <span class="d14k-version-badge">v<?php echo esc_html(D14K_FEED_VERSION); ?></span>
            </div>

            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success is-dismissible d14k-notice">
                    <p>✓ Налаштування збережено.</p>
                </div>
            <?php elseif ($notice === 'generated'): ?>
                <div class="notice notice-success is-dismissible d14k-notice">
                    <p>✓ Фіди успішно згенеровано!</p>
                </div>
            <?php elseif ($notice === 'error'): ?>
                <div class="notice notice-error is-dismissible">
                    <p>Помилка генерації. Перевірте логи.</p>
                </div>
            <?php endif; ?>

            <?php if ($show_validation): ?>
                <?php $this->render_validation_results(); ?>
                <?php return; ?>
            <?php endif; ?>

            <?php
            $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
            if ($current_tab === 'feeds') {
                $current_tab = 'overview';
            }
            if ($current_tab === 'prom_sync') {
                $current_tab = 'prom';
            }
            if ($current_tab === 'mapping' || $current_tab === 'filters') {
                $current_tab = 'google';
            }
            if ($current_tab === 'settings') {
                $current_tab = 'overview';
            }
            $tabs = array(
                'overview' => array('label' => 'Огляд', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h7V3H3zm11 0h7V3h-7zM3 21h7v-5H3zm11 0h7v-9h-7z"/></svg>'),
                'google' => array('label' => 'Google', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.2c0-.63-.06-1.24-.16-1.82H12v3.44h5.06a4.33 4.33 0 0 1-1.88 2.84v2.36h3.04c1.78-1.64 2.78-4.05 2.78-6.82Z"/><path d="M12 21c2.52 0 4.64-.84 6.18-2.28l-3.04-2.36c-.84.57-1.92.9-3.14.9-2.41 0-4.46-1.63-5.19-3.81H3.67v2.45A9.34 9.34 0 0 0 12 21Z"/><path d="M6.81 13.45A5.6 5.6 0 0 1 6.52 12c0-.5.1-.99.29-1.45V8.1H3.67A9 9 0 0 0 3 12c0 1.45.35 2.82.97 4.1l2.84-2.65Z"/><path d="M12 6.74c1.37 0 2.6.47 3.57 1.4l2.68-2.68C16.63 3.95 14.52 3 12 3a9.34 9.34 0 0 0-8.33 5.1l3.14 2.45c.73-2.18 2.78-3.81 5.19-3.81Z"/></svg>'),
                'horoshop' => array('label' => 'Horoshop', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/><path d="M8 6v12"/><path d="M16 6v12"/></svg>'),
                'prom' => array('label' => 'Prom', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>'),
                'suppliers' => array('label' => 'Постачальники', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M2 7h20"/><path d="M12 22v-4"/></svg>'),
                'rozetka' => array('label' => 'Rozetka', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4z"/><path d="M9 9h6v6H9z"/></svg>'),
                'facebook' => array('label' => 'Facebook', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>'),
                'help' => array('label' => 'Довідка', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><circle cx="12" cy="17" r="1.5" fill="currentColor" stroke="none"/></svg>'),
            );
            ?>
            <nav class="d14k-tabs">
                <?php foreach ($tabs as $tab_id => $tab_data): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=d14k-merchant-feed&tab=' . $tab_id)); ?>"
                        class="d14k-tab<?php echo $current_tab === $tab_id ? ' d14k-tab--active' : ''; ?>">
                        <?php echo $tab_data['icon']; // SVG is hardcoded, safe ?>
                        <?php echo esc_html($tab_data['label']); ?>
                    </a>
                <?php endforeach; ?>

            </nav>

            <?php
            $this->render_google_tab(
                $current_tab,
                $languages,
                $next_run,
                $settings,
                $wc_attributes,
                $brand_attribute_options,
                $wc_cats,
                $cat_children,
                $cat_by_id,
                $excluded_cat_ids,
                $wpml_trans_map,
                $attr_terms_data,
                $custom_rules,
                $rule_fields,
                $rule_conditions
            );
            ?>

            <?php
            $this->render_horoshop_tab($current_tab, $settings);
            ?>

            <?php
            $this->render_overview_tab(
                $current_tab,
                $languages,
                $next_run,
                $prom_settings,
                $last_import,
                $supplier_feeds_list,
                $supplier_last_run,
                $settings
            );
            ?>
            <!-- TAB: Довідка -->
            <?php $this->render_prom_tab($current_tab, $prom_settings, $last_import, $last_export); ?>

            <?php $this->render_suppliers_tab($current_tab, $prom_settings, $supplier_feeds_list, $supplier_last_run, $supplier_last_cleanup); ?>

            <!-- TAB: Rozetka -->
            <div class="d14k-tab-content<?php echo $current_tab === 'rozetka' ? ' d14k-tab-content--active' : ''; ?>"
                data-tab="rozetka">
                <?php
                $this->render_channel_placeholder(
                    'Rozetka',
                    'Вкладка підготовлена для окремого каналу Rozetka.',
                    'Тут з\'являться специфічні налаштування, генерація фіду, статуси запусків і правила експорту, коли почнемо окрему інтеграцію.'
                );
                ?>
            </div>

            <!-- TAB: Facebook -->
            <div class="d14k-tab-content<?php echo $current_tab === 'facebook' ? ' d14k-tab-content--active' : ''; ?>"
                data-tab="facebook">
                <?php
                $this->render_channel_placeholder(
                    'Facebook',
                    'Вкладка підготовлена для каталогу Meta / Facebook.',
                    'Тут з\'являться окремі поля, відбір товарів, schedule і статус генерації, коли підемо в цю інтеграцію.'
                );
                ?>
            </div>

            </div>

            <div class="d14k-tab-content<?php echo $current_tab === 'help' ? ' d14k-tab-content--active' : ''; ?>"
                data-tab="help">
                <div class="d14k-card d14k-card--help">
                    <div class="d14k-help-content">
                        <div class="d14k-help-hero">
                            <span class="d14k-help-hero__eyebrow">Guide</span>
                            <h2>Довідка та налаштування</h2>
                            <p>Як працює кожен розділ плагіну, та рекомендації для найкращих результатів генерації фідів для
                                Google
                                Merchant Center.</p>
                        </div>
                        <div class="d14k-help-grid">

                            <div class="d14k-help-item">
                                <div class="d14k-help-item-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="3" width="7" height="7" rx="1" />
                                        <rect x="14" y="3" width="7" height="7" rx="1" />
                                        <rect x="3" y="14" width="7" height="7" rx="1" />
                                        <path d="M14 17h7M17.5 14v7" />
                                    </svg>
                                </div>
                                <h3>Фіди</h3>
                                <p>Моніторинг стану XML-фідів: URL, дата останньої генерації, кількість товарів, помилки. Кнопка
                                    «Згенерувати зараз» запускає примусову генерацію поза розкладом.</p>
                            </div>

                            <div class="d14k-help-item">
                                <div class="d14k-help-item-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="3" />
                                        <path
                                            d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                                    </svg>
                                </div>
                                <h3>Налаштування</h3>
                                <p>Глобальні параметри: увімкнення автогенерації, час генерації (раз на добу), назва
                                    бренду
                                    або атрибут товару, країна походження, Google Product Category за замовчуванням.</p>
                            </div>

                            <div class="d14k-help-item">
                                <div class="d14k-help-item-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path
                                            d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18" />
                                    </svg>
                                </div>
                                <h3>Маппінг</h3>
                                <p>Прив'язка категорій WooCommerce до таксономії Google Product Categories. Custom Labels (0–4)
                                    дозволяють сегментувати товари в Google Ads (наприклад: season, margin, promo). Порожнє поле
                                    =
                                    використовується значення за замовчуванням.</p>
                            </div>

                            <div class="d14k-help-item">
                                <div class="d14k-help-item-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
                                    </svg>
                                </div>
                                <h3>Фільтри</h3>
                                <p>Виключення категорій з фіду: позначені категорії та їх підкатегорії не потраплять у XML.
                                    Фільтри
                                    по атрибутах (напр., виключити товари без ціни або певного кольору). Правила по
                                    статусу/наявності.</p>
                            </div>

                            <div class="d14k-help-item">
                                <div class="d14k-help-item-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                        <polyline points="14 2 14 8 20 8" />
                                        <line x1="16" y1="13" x2="8" y2="13" />
                                        <line x1="16" y1="17" x2="8" y2="17" />
                                        <polyline points="10 9 9 9 8 9" />
                                    </svg>
                                </div>
                                <h3>Custom Labels на рівні товару</h3>
                                <p>У картці кожного товару WooCommerce є мета-поля Custom Label 0–4. Вони перезаписують значення
                                    маппінгу по категорії. Корисно для товарів-виключень або seasonal акцій.</p>
                            </div>

                            <div class="d14k-help-item">
                                <div class="d14k-help-item-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <polyline points="12 6 12 12 16 14" />
                                    </svg>
                                </div>
                                <h3>Автоматична генерація</h3>
                                <p>Плагін використовує WordPress Cron для автогенерації. Фіди генеруються раз на добу; час можна
                                    налаштувати під сканування Google Merchant Center (так само як і часовий пояс). Для
                                    продакшн-сайтів рекомендується замінити WP Cron на системний cron (crontab) для надійності.
                                </p>
                            </div>

                        </div><!-- /.d14k-help-grid -->
                    </div><!-- /.d14k-help-content -->

                    <div class="d14k-contact-banner">
                        <div class="d14k-contact-banner-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                <circle cx="12" cy="17" r="1.5" fill="currentColor" stroke="none"></circle>
                            </svg>
                        </div>
                        <div class="d14k-contact-banner-body">
                            <span class="d14k-contact-banner-title">Маєте питання або знайшли баг?</span>
                            <span class="d14k-contact-banner-text">Якщо щось не працює, хочете нову функцію або маєте ідею
                                покращення, пишіть напряму розробнику.</span>
                        </div>
                        <a href="mailto:ms.milspil@gmail.com" class="d14k-contact-banner-link">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                                <polyline points="22,6 12,13 2,6" />
                            </svg>
                            ms.milspil@gmail.com
                        </a>
                    </div>
                </div>
            </div><!-- /.d14k-tab-content[help] -->
        </div>

        <?php
    }

    public function save_settings()
    {
        check_admin_referer('d14k_save_settings');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Access denied');
        }

        $allowed_fields = array('title', 'sku', 'regular_price', 'sale_price', 'stock_status', 'tag', 'custom_meta');
        $allowed_conditions = array('equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'gt', 'lt', 'gte', 'lte', 'is_empty', 'not_empty');
        $old_settings = wp_parse_args(get_option('d14k_feed_settings', array()), $this->get_defaults());
        $settings = $old_settings;
        $tab = isset($_POST['d14k_tab']) ? sanitize_key($_POST['d14k_tab']) : 'feeds';

        if ($tab === 'settings') {
            $settings['enabled'] = !empty($_POST['enabled']);
            $settings['cron_time'] = sanitize_text_field(
                preg_replace(
                    '/[^0-9:]/',
                    '',
                    (isset($_POST['cron_hour']) && isset($_POST['cron_minute']))
                        ? sprintf('%02d:%02d', absint($_POST['cron_hour']), absint($_POST['cron_minute']))
                        : '06:00'
                )
            );
            $settings['cron_timezone'] = in_array(
                sanitize_text_field(isset($_POST['cron_timezone']) ? $_POST['cron_timezone'] : 'Europe/Kiev'),
                timezone_identifiers_list(),
                true
            ) ? sanitize_text_field($_POST['cron_timezone']) : 'Europe/Kiev';
        } elseif ($tab === 'google_settings') {
            $settings['brand'] = sanitize_text_field(isset($_POST['brand']) ? $_POST['brand'] : '');
            $settings['brand_attribute'] = sanitize_key(isset($_POST['brand_attribute']) ? $_POST['brand_attribute'] : '');
            $settings['brand_mode'] = $settings['brand_attribute'] !== '' ? 'attribute' : 'custom';
            $settings['brand_fallback'] = '';
            $settings['country_of_origin'] = sanitize_text_field(isset($_POST['country_of_origin']) ? $_POST['country_of_origin'] : 'UA');
        } elseif ($tab === 'horoshop') {
            $settings['webp_to_jpg'] = !empty($_POST['webp_to_jpg']);
        } elseif ($tab === 'mapping') {
            $settings['default_google_category'] = sanitize_text_field(isset($_POST['default_google_category']) ? $_POST['default_google_category'] : '');
            $settings['category_map'] = array_map(
                'sanitize_text_field',
                isset($_POST['category_map']) ? (array) $_POST['category_map'] : array()
            );

            for ($cli = 0; $cli <= 4; $cli++) {
                $key = 'category_label_' . $cli . '_map';
                $settings[$key] = array_map(
                    'sanitize_text_field',
                    isset($_POST[$key]) ? (array) $_POST[$key] : array()
                );
            }
        } elseif ($tab === 'filters') {
            $custom_rules = array();
            if (!empty($_POST['custom_rules']) && is_array($_POST['custom_rules'])) {
                foreach ($_POST['custom_rules'] as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    $r_field = isset($rule['field']) && in_array($rule['field'], $allowed_fields, true) ? $rule['field'] : '';
                    if (!$r_field) {
                        continue;
                    }
                    $custom_rules[] = array(
                        'field' => $r_field,
                        'meta_key' => isset($rule['meta_key']) ? sanitize_key($rule['meta_key']) : '',
                        'condition' => isset($rule['condition']) && in_array($rule['condition'], $allowed_conditions, true) ? $rule['condition'] : 'equals',
                        'value' => isset($rule['value']) ? sanitize_text_field($rule['value']) : '',
                        'action' => isset($rule['action']) && $rule['action'] === 'include' ? 'include' : 'exclude',
                    );
                }
            }

            $_excl_raw = !empty($_POST['excluded_categories']) && is_array($_POST['excluded_categories'])
                ? array_map('intval', $_POST['excluded_categories']) : array();
            $excluded_cats = $_excl_raw;
            if (!empty($_excl_raw)) {
                global $wpdb;
                $_placeholders = implode(',', array_fill(0, count($_excl_raw), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
                $_trids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT tr.trid FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->prefix}icl_translations tr ON tr.element_id=tt.term_taxonomy_id WHERE tt.taxonomy='product_cat' AND tt.term_id IN({$_placeholders})",
                    $_excl_raw
                ));
                if (!empty($_trids)) {
                    $_trids = array_map('intval', $_trids);
                    $_t_placeholders = implode(',', array_fill(0, count($_trids), '%d'));
                    // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
                    $_all = $wpdb->get_col($wpdb->prepare(
                        "SELECT DISTINCT tt.term_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->prefix}icl_translations tr ON tr.element_id=tt.term_taxonomy_id WHERE tt.taxonomy='product_cat' AND tr.trid IN({$_t_placeholders})",
                        $_trids
                    ));
                    $excluded_cats = array_values(array_unique(array_map('intval', array_merge($_excl_raw, $_all))));
                }
            }

            $attribute_filters = array();
            $enabled_attrs = !empty($_POST['attr_filter_enabled']) ? (array) $_POST['attr_filter_enabled'] : array();
            $filter_modes = !empty($_POST['attr_filter_mode']) ? (array) $_POST['attr_filter_mode'] : array();
            $filter_values = !empty($_POST['attr_filter_values']) ? (array) $_POST['attr_filter_values'] : array();
            foreach ($enabled_attrs as $taxonomy => $v) {
                $taxonomy = sanitize_key($taxonomy);
                $mode = isset($filter_modes[$taxonomy]) && $filter_modes[$taxonomy] === 'include' ? 'include' : 'exclude';
                $values = isset($filter_values[$taxonomy]) ? array_map('sanitize_text_field', (array) $filter_values[$taxonomy]) : array();
                $attribute_filters[$taxonomy] = array(
                    'enabled' => true,
                    'mode' => $mode,
                    'values' => $values,
                );
            }

            $settings['excluded_categories'] = $excluded_cats;
            $settings['attribute_filters'] = $attribute_filters;
            $settings['custom_rules'] = $custom_rules;
        }

        // Marketplace YML channels
        if (isset($_POST['yml_channels'])) {
            $yml_channels = array();
            foreach (array_merge(array('horoshop'), D14K_YML_Generator::CHANNELS) as $ch) {
                $yml_channels[$ch] = !empty($_POST['yml_channels'][$ch]);
            }
            $settings['yml_channels'] = $yml_channels;
        }

        // ---- Prom.ua settings ----
        if (($tab === 'prom' || $tab === 'prom_sync') && isset($_POST['prom_api_token'])) {
            $settings['prom_api_token']               = sanitize_text_field($_POST['prom_api_token']);
            $settings['prom_import_enabled']          = !empty($_POST['prom_import_enabled']);
            $settings['prom_import_mode']             = in_array(isset($_POST['prom_import_mode']) ? $_POST['prom_import_mode'] : 'full', array('full', 'prices'), true) ? $_POST['prom_import_mode'] : 'full';
            $settings['prom_import_interval']         = sanitize_key(isset($_POST['prom_import_interval']) ? $_POST['prom_import_interval'] : 'daily');
            $settings['prom_export_interval']         = sanitize_key(isset($_POST['prom_export_interval']) ? $_POST['prom_export_interval'] : (isset($settings['prom_export_interval']) ? $settings['prom_export_interval'] : $settings['prom_import_interval']));
            $settings['prom_price_markup']            = isset($_POST['prom_price_markup']) ? floatval($_POST['prom_price_markup']) : 0;
            $settings['prom_only_available']          = !empty($_POST['prom_only_available']);
            $settings['prom_only_with_images']        = !empty($_POST['prom_only_with_images']);
            $settings['prom_import_images']           = !empty($_POST['prom_import_images']);
            $settings['prom_import_product_status']   = in_array(isset($_POST['prom_import_product_status']) ? $_POST['prom_import_product_status'] : 'publish', array('publish', 'draft'), true) ? $_POST['prom_import_product_status'] : 'publish';
            $settings['prom_export_enabled']          = !empty($_POST['prom_export_enabled']);
            $settings['prom_auto_export_after_feed']  = !empty($_POST['prom_auto_export_after_feed']);
            $settings['prom_realtime_sync']           = !empty($_POST['prom_realtime_sync']);
            $settings['prom_feed_url_override']       = esc_url_raw(isset($_POST['prom_feed_url_override']) ? $_POST['prom_feed_url_override'] : '');

            // Reschedule Prom crons
            $this->cron->reschedule_prom_import(
                $settings['prom_import_interval'],
                $settings['prom_import_enabled']
            );
            $this->cron->reschedule_prom_export(
                $settings['prom_export_interval'],
                $settings['prom_export_enabled']
            );
        }

        if ($tab === 'suppliers' || $tab === 'prom_sync') {
            $settings['supplier_feeds_enabled']       = !empty($_POST['supplier_feeds_enabled']);
            $settings['supplier_feeds_interval']      = sanitize_key(isset($_POST['supplier_feeds_interval']) ? $_POST['supplier_feeds_interval'] : ($settings['supplier_feeds_interval'] ?? 'daily'));
            $supplier_feeds = array();
            if (!empty($_POST['supplier_feeds']) && is_array($_POST['supplier_feeds'])) {
                foreach ($_POST['supplier_feeds'] as $sf) {
                    $sanitized_feed = $this->sanitize_supplier_feed_row($sf);
                    if ($sanitized_feed) {
                        $supplier_feeds[] = $sanitized_feed;
                    }
                }
            }
            $settings['supplier_feeds'] = $supplier_feeds;
        }

        update_option('d14k_feed_settings', $settings);

        if ($tab === 'suppliers' || $tab === 'prom_sync') {
            $this->cron->reschedule_supplier_feeds(
                $settings['supplier_feeds_interval'] ?? 'daily',
                $settings['supplier_feeds_enabled']
            );
        }

        $old_time = isset($old_settings['cron_time']) ? $old_settings['cron_time'] : '';
        $old_tz = isset($old_settings['cron_timezone']) ? $old_settings['cron_timezone'] : '';
        if ($old_time !== $settings['cron_time'] || $old_tz !== $settings['cron_timezone']) {
            $this->cron->reschedule_at_time($settings['cron_time'], $settings['cron_timezone']);
        }

        $redirect_tab = $tab;
        if ($tab === 'google_settings') {
            $redirect_tab = 'google';
        } elseif ($tab === 'horoshop') {
            $redirect_tab = 'horoshop';
        }
        wp_redirect(admin_url('admin.php?page=d14k-merchant-feed&tab=' . $redirect_tab . '&d14k_notice=saved'));
        exit;
    }

    private function get_supplier_allowed_update_fields()
    {
        return class_exists('D14K_Supplier_Feeds')
            ? D14K_Supplier_Feeds::get_available_update_fields()
            : array();
    }

    private function sanitize_supplier_feed_row($sf, $fallback = array())
    {
        if (!is_array($sf)) {
            return null;
        }

        $url = esc_url_raw(trim((string) ($sf['url'] ?? '')));
        if ($url === '') {
            return null;
        }

        $allowed_update_fields = $this->get_supplier_allowed_update_fields();
        $update_fields = array();

        if (!empty($sf['update_fields']) && is_array($sf['update_fields'])) {
            foreach ($allowed_update_fields as $field_key => $field_label) {
                if (!empty($sf['update_fields'][$field_key])) {
                    $update_fields[$field_key] = 1;
                }
            }
        }

        $defaults = $this->cron
            ? $this->cron->get_supplier_schedule_defaults(get_option('d14k_feed_settings', array()))
            : array(
                'schedule_interval' => 'daily',
                'schedule_time' => '01:00',
                'schedule_weekday' => 'mon',
                'schedule_monthday' => 1,
            );
        $fallback = is_array($fallback) ? $fallback : array();

        $interval_options = $this->cron ? $this->cron->get_interval_options() : array(
            'hourly' => 'Кожну годину',
            'twicedaily' => 'Двічі на добу',
            'daily' => 'Раз на добу',
            'd14k_weekly' => 'Раз на тиждень',
            'd14k_monthly' => 'Раз на місяць',
        );
        $weekday_options = $this->cron ? $this->cron->get_supplier_weekday_options() : array(
            'mon' => 'Понеділок',
            'tue' => 'Вівторок',
            'wed' => 'Середа',
            'thu' => 'Четвер',
            'fri' => 'П’ятниця',
            'sat' => 'Субота',
            'sun' => 'Неділя',
        );

        $schedule_interval = sanitize_key($sf['schedule_interval'] ?? ($fallback['schedule_interval'] ?? $defaults['schedule_interval']));
        if (!isset($interval_options[$schedule_interval])) {
            $schedule_interval = $defaults['schedule_interval'];
        }

        $schedule_time = trim((string) ($sf['schedule_time'] ?? ($fallback['schedule_time'] ?? $defaults['schedule_time'])));
        if (!preg_match('/^\d{2}:\d{2}$/', $schedule_time)) {
            $schedule_time = $defaults['schedule_time'];
        }

        $schedule_weekday = strtolower((string) ($sf['schedule_weekday'] ?? ($fallback['schedule_weekday'] ?? $defaults['schedule_weekday'])));
        if (!isset($weekday_options[$schedule_weekday])) {
            $schedule_weekday = $defaults['schedule_weekday'];
        }

        $schedule_monthday = (int) ($sf['schedule_monthday'] ?? ($fallback['schedule_monthday'] ?? $defaults['schedule_monthday']));
        $schedule_monthday = min(28, max(1, $schedule_monthday));

        return array(
            'url'           => $url,
            'label'         => sanitize_text_field($sf['label'] ?? ''),
            'category_root' => sanitize_text_field($sf['category_root'] ?? ''),
            'markup'        => isset($sf['markup']) ? floatval($sf['markup']) : 0,
            'update_stock'  => !empty($update_fields['stock']),
            'update_fields' => $update_fields,
            'enabled'       => !empty($sf['enabled']),
            'schedule_interval' => $schedule_interval,
            'schedule_time' => $schedule_time,
            'schedule_weekday' => $schedule_weekday,
            'schedule_monthday' => $schedule_monthday,
        );
    }

    public function ajax_supplier_feed_save_row()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        $index = isset($_POST['index']) ? absint($_POST['index']) : 0;
        $feed  = isset($_POST['feed']) && is_array($_POST['feed']) ? wp_unslash($_POST['feed']) : array();

        $settings = get_option('d14k_feed_settings', array());
        $supplier_feeds = isset($settings['supplier_feeds']) && is_array($settings['supplier_feeds'])
            ? $settings['supplier_feeds']
            : array();

        $existing_feed = isset($supplier_feeds[$index]) && is_array($supplier_feeds[$index])
            ? $supplier_feeds[$index]
            : array();
        $sanitized_feed = $this->sanitize_supplier_feed_row($feed, $existing_feed);
        if (!$sanitized_feed) {
            wp_send_json_error('Вкажіть коректний URL фіду.', 400);
        }

        $supplier_feeds[$index] = $sanitized_feed;
        ksort($supplier_feeds, SORT_NUMERIC);

        $settings['supplier_feeds'] = $supplier_feeds;
        update_option('d14k_feed_settings', $settings);
        if ($this->cron) {
            $this->cron->reschedule_supplier_feeds(
                $settings['supplier_feeds_interval'] ?? 'daily',
                !empty($settings['supplier_feeds_enabled'])
            );
        }

        wp_send_json_success(array(
            'index'   => $index,
            'feed'    => $sanitized_feed,
            'message' => 'Налаштування цього постачальника збережено.',
        ));
    }

    public function handle_generate_now()
    {
        check_admin_referer('d14k_generate_now');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Access denied');
        }

        $languages = $this->wpml->get_active_languages();
        $success = true;

        foreach ($languages as $lang) {
            if (!$this->generator->generate($lang)) {
                $success = false;
            }
        }

        $notice = $success ? 'generated' : 'error';
        wp_redirect(admin_url("admin.php?page=d14k-merchant-feed&d14k_notice={$notice}"));
        exit;
    }

    /**
     * AJAX: Toggle YML channel on/off
     */
    public function ajax_toggle_channel()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        $channel = isset($_POST['channel']) ? sanitize_key($_POST['channel']) : '';
        $enabled = isset($_POST['enabled']) ? (bool) $_POST['enabled'] : false;

        $valid_channels = array('horoshop', 'prom', 'rozetka');
        if (!in_array($channel, $valid_channels, true)) {
            wp_send_json_error('Invalid channel', 400);
        }

        $settings = get_option('d14k_feed_settings', array());
        if (!isset($settings['yml_channels'])) {
            $settings['yml_channels'] = array();
        }

        if ($enabled) {
            $settings['yml_channels'][$channel] = 1;
        } else {
            unset($settings['yml_channels'][$channel]);
        }

        update_option('d14k_feed_settings', $settings);

        wp_send_json_success(array(
            'channel' => $channel,
            'enabled' => $enabled,
            'message' => $enabled ? 'Канал увімкнено' : 'Канал вимкнено',
        ));
    }

    /**
     * AJAX: Generate feed for a specific YML channel
     */
    public function ajax_generate_channel()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        $channel = isset($_POST['channel']) ? sanitize_key($_POST['channel']) : '';

        $valid_channels = array('horoshop', 'prom', 'rozetka');
        if (!in_array($channel, $valid_channels, true)) {
            wp_send_json_error('Invalid channel', 400);
        }

        $settings = get_option('d14k_feed_settings', array());
        if (empty($settings['yml_channels'][$channel])) {
            wp_send_json_error('Channel is not enabled', 400);
        }

        if ($channel === 'horoshop') {
            $result = $this->csv_generator->generate($channel);
            $generator_instance = $this->csv_generator;
        } else {
            $result = $this->yml_generator->generate($channel);
            $generator_instance = $this->yml_generator;
        }

        if ($result) {
            $stats = $generator_instance->get_last_stats($channel);
            $last_gen = $generator_instance->get_last_generated($channel);
            wp_send_json_success(array(
                'channel' => $channel,
                'stats' => $stats,
                'generated' => $last_gen,
                'message' => 'Фід згенеровано!',
            ));
        } else {
            wp_send_json_error('Generation failed. Check error logs.', 500);
        }
    }

    public function handle_test_validation()
    {
        check_admin_referer('d14k_test_validation');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Access denied');
        }

        $settings = get_option('d14k_feed_settings', $this->get_defaults());
        $validator = new D14K_Feed_Validator();
        $report = $validator->validate_test_feed($this->wpml, $settings, $this->generator);

        // Store report in transient for display
        set_transient('d14k_validation_report', $report, 300); // 5 minutes

        wp_redirect(admin_url('admin.php?page=d14k-merchant-feed&show_validation=1'));
        exit;
    }

    private function render_validation_results()
    {
        $report = get_transient('d14k_validation_report');
        if (!$report) {
            echo '<div class="notice notice-error"><p>Звіт валідації не знайдено. Спробуйте ще раз.</p></div>';
            return;
        }

        $total = $report['total_products'];
        $valid = $report['valid_products'];
        $invalid = $report['invalid_products'];
        $percentage = $total > 0 ? round(($valid / $total) * 100) : 0;

        ?>
        <div class="d14k-card">
            <h2>🔍 Результати тестової валідації</h2>

            <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <h3 style="margin-top: 0;">Загальна статистика</h3>
                <p style="font-size: 16px; margin: 10px 0;">
                    <strong>Перевірено товарів:</strong> <?php echo (int) $total; ?><br>
                    <strong style="color: #46b450;">✅ Валідних:</strong> <?php echo (int) $valid; ?>
                    (<?php echo (int) $percentage; ?>%)<br>
                    <strong style="color: #dc3232;">❌ З помилками:</strong> <?php echo (int) $invalid; ?>
                    (<?php echo (int) (100 - $percentage); ?>%)
                </p>
            </div>

            <?php if (!empty($report['sample_item_xml'])): ?>
                <div style="margin-bottom: 30px;">
                    <h3>📦 Приклад даних (XML)</h3>
                    <p class="description" style="margin-bottom: 10px;">Так виглядатиме перший пройдений товар у файлі фіда.
                        Перевірте теги та атрибути.</p>
                    <textarea readonly
                        style="width: 100%; height: 300px; font-family: monospace; font-size: 12px; background: #282c34; color: #abb2bf; border-radius: 4px; border: 1px solid #ccc; padding: 10px; box-sizing: border-box; white-space: pre;"><?php echo esc_textarea($report['sample_item_xml']); ?></textarea>
                </div>
            <?php endif; ?>

            <h3>Статистика по полях</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Поле</th>
                        <th>Присутнє</th>
                        <th>Відсутнє</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['fields_stats'] as $field => $stats):
                        $present = $stats['present'];
                        $missing = $stats['missing'];
                        $is_ok = $missing === 0;
                        $status_color = $is_ok ? '#46b450' : '#dc3232';
                        $status_icon = $is_ok ? '✅' : '⚠️';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($stats['label']); ?></strong></td>
                            <td><?php echo (int) $present; ?>/<?php echo (int) $total; ?></td>
                            <td style="color: <?php echo $missing > 0 ? '#dc3232' : '#666'; ?>;"><?php echo (int) $missing; ?></td>
                            <td style="color: <?php echo esc_attr($status_color); ?>; font-size: 18px;">
                                <?php echo $status_icon; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 style="margin-top: 30px;">Детальний список товарів</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Назва товару</th>
                        <th>Статус</th>
                        <th>Відсутні поля</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['products'] as $product):
                        $status_color = $product['is_valid'] ? '#46b450' : '#dc3232';
                        $status_text = $product['is_valid'] ? '✅ Валідний' : '❌ Помилки';
                        ?>
                        <tr>
                            <td><?php echo (int) $product['id']; ?></td>
                            <td><?php echo esc_html($product['title']); ?></td>
                            <td style="color: <?php echo esc_attr($status_color); ?>; font-weight: bold;">
                                <?php echo $status_text; ?>
                            </td>
                            <td>
                                <?php if (!empty($product['missing_fields'])): ?>
                                    <span style="color: #dc3232;">
                                        <?php echo esc_html(implode(', ', $product['missing_fields'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #46b450;">Всі поля присутні</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=d14k-merchant-feed')); ?>" class="button">←
                    Повернутися до налаштувань</a>
            </p>
        </div>
        <?php
    }

    /**
     * Renders an accordion-style UI for category exclusion.
     * Top-level categories are collapsible groups; subcategories shown as a grid inside.
     * One setting covers all WPML languages (translation expansion done in feed generator).
     */
    private function render_cat_accordion($children_map, $cat_by_id, $excluded_ids, $wpml_trans_map = array())
    {
        $top_level = isset($children_map[0]) ? $children_map[0] : array();
        if (empty($top_level)) {
            return '<p class="description">Категорії не знайдені.</p>';
        }

        ob_start();
        ?>
        <style>
            .d14k-cat-toolbar {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 14px;
                flex-wrap: wrap
            }

            .d14k-cat-search {
                flex: 1;
                min-width: 180px;
                height: 32px;
                padding: 0 8px 0 10px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                font-size: 13px
            }

            .d14k-cat-search:focus {
                outline: none;
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1
            }

            .d14k-cat-tbtn {
                height: 32px;
                padding: 0 10px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                background: #fff;
                font-size: 12px;
                cursor: pointer;
                color: #2271b1
            }

            .d14k-cat-tbtn:hover {
                background: #f6f7f7;
                border-color: #2271b1
            }

            .d14k-cat-counter {
                font-size: 12px;
                color: #646970;
                margin-left: auto;
                white-space: nowrap
            }

            .d14k-cat-counter strong {
                color: #d63638
            }

            .d14k-cg {
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                margin-bottom: 6px;
                overflow: hidden
            }

            .d14k-cg.d14k-has-ex {
                border-color: #d63638;
                border-left: 3px solid #d63638
            }

            .d14k-cg-hdr {
                display: flex;
                align-items: center;
                gap: 9px;
                padding: 8px 12px;
                background: #f6f7f7;
                cursor: pointer;
                user-select: none
            }

            .d14k-cg-hdr:hover {
                background: #eef0f2
            }

            .d14k-cg.d14k-has-ex .d14k-cg-hdr {
                background: #fef7f7
            }

            .d14k-cg-arr {
                font-size: 10px;
                color: #646970;
                transition: transform .2s;
                flex-shrink: 0
            }

            .d14k-cg.d14k-open .d14k-cg-arr {
                transform: rotate(90deg)
            }

            .d14k-cg-lbl {
                font-size: 13px;
                font-weight: 600;
                color: #1d2327;
                flex: 1
            }

            .d14k-cg-meta {
                display: flex;
                align-items: center;
                gap: 7px;
                flex-shrink: 0
            }

            .d14k-cg-total {
                font-size: 11px;
                color: #aaa
            }

            .d14k-cg-badge {
                display: none;
                padding: 1px 7px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 700;
                background: #d63638;
                color: #fff
            }

            .d14k-cg-body {
                display: none;
                padding: 8px 12px 10px;
                background: #fff;
                border-top: 1px solid #e0e0e0
            }

            .d14k-cg.d14k-open .d14k-cg-body {
                display: block
            }

            .d14k-ch-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 3px 16px
            }

            .d14k-ch {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 4px 6px;
                border-radius: 3px
            }

            .d14k-ch:hover {
                background: #f6f7f7
            }

            .d14k-ch.d14k-ex {
                background: #fef7f7
            }

            .d14k-ch-name {
                font-size: 13px;
                color: #1d2327;
                cursor: pointer
            }

            .d14k-ch-cnt {
                font-size: 11px;
                color: #aaa;
                flex-shrink: 0
            }

            .d14k-cat-empty {
                text-align: center;
                padding: 24px;
                color: #646970;
                font-size: 13px;
                display: none
            }
        </style>
        <div class="d14k-cat-toolbar">
            <input type="text" class="d14k-cat-search" id="d14kCatSearch" placeholder="Пошук категорії...">
            <button type="button" class="d14k-cat-tbtn d14k-cat-select-all">Виділити всі</button>
            <button type="button" class="d14k-cat-tbtn d14k-cat-clear-all">Зняти всі</button>
            <span class="d14k-cat-counter">Виключено: <strong id="d14kExCount">0</strong></span>
        </div>
        <div id="d14kCatTree">
            <?php
            foreach ($top_level as $parent_id):
                if (!isset($cat_by_id[$parent_id]))
                    continue;
                $parent = $cat_by_id[$parent_id];
                // Check via wpml_trans_map: excluded if this term OR any translation is excluded
                $_p_trans = isset($wpml_trans_map[(int) $parent_id]) ? $wpml_trans_map[(int) $parent_id] : array((int) $parent_id);
                $p_chk = !empty(array_intersect($_p_trans, $excluded_ids));
                $children = isset($children_map[$parent_id]) ? $children_map[$parent_id] : array();

                // Count excluded children
                $ex_kids = 0;
                foreach ($children as $cid) {
                    if (in_array((int) $cid, $excluded_ids, true))
                        $ex_kids++;
                }
                $total_chk = ($p_chk ? 1 : 0) + $ex_kids;
                $has_ex = $total_chk > 0;

                // Build search string: parent + all child names
                $s_data = mb_strtolower($parent->name);
                foreach ($children as $cid) {
                    if (isset($cat_by_id[$cid]))
                        $s_data .= ' ' . mb_strtolower($cat_by_id[$cid]->name);
                }
                $g_cls = 'd14k-cg' . ($has_ex ? ' d14k-has-ex' : '') . ($has_ex ? ' d14k-open' : '');
                ?>
                <div class="<?php echo esc_attr($g_cls); ?>" data-search="<?php echo esc_attr($s_data); ?>">
                    <div class="d14k-cg-hdr d14k-cg-toggle">
                        <span class="d14k-cg-arr">&#9658;</span>
                        <input type="checkbox" class="d14k-cat-cb d14k-cat-parent" name="excluded_categories[]"
                            value="<?php echo (int) $parent_id; ?>" data-id="<?php echo (int) $parent_id; ?>"
                            <?php checked($p_chk); ?>>
                        <span class="d14k-cg-lbl"><?php echo esc_html($parent->name); ?></span>
                        <?php if (!empty($wpml_trans_map[(int) $parent_id]) && count($wpml_trans_map[(int) $parent_id]) > 1): ?>
                            <span class="d14k-cg-lang" title="Синхронізується для всіх мов">🌐</span>
                        <?php endif; ?>
                        <span class="d14k-cg-meta">
                            <?php if ($parent->count): ?>
                                <span class="d14k-cg-total">(<?php echo (int) $parent->count; ?> товарів)</span>
                            <?php endif; ?>
                            <span class="d14k-cg-badge" id="d14kBadge<?php echo (int) $parent_id; ?>"
                                style="<?php echo $has_ex ? 'display:inline-block' : 'display:none'; ?>">
                                <?php echo $total_chk; ?>
                            </span>
                        </span>
                    </div>
                    <?php if (!empty($children)): ?>
                        <div class="d14k-cg-body">
                            <div class="d14k-ch-grid">
                                <?php foreach ($children as $cid):
                                    if (!isset($cat_by_id[$cid]))
                                        continue;
                                    $child = $cat_by_id[$cid];
                                    $_c_trans = isset($wpml_trans_map[(int) $cid]) ? $wpml_trans_map[(int) $cid] : array((int) $cid);
                                    $c_chk = !empty(array_intersect($_c_trans, $excluded_ids));
                                    $c_cls = ($c_chk || $p_chk) ? ' d14k-ex' : '';
                                    ?>
                                    <div class="d14k-ch<?php echo $c_cls; ?>">
                                        <input type="checkbox" class="d14k-cat-cb d14k-cat-child" name="excluded_categories[]"
                                            value="<?php echo (int) $cid; ?>" data-id="<?php echo (int) $cid; ?>"
                                            data-parent="<?php echo (int) $parent_id; ?>" <?php checked($c_chk); ?>>
                                        <label class="d14k-ch-name" for=""><?php echo esc_html($child->name); ?></label>
                                        <?php if ($child->count): ?>
                                            <span class="d14k-ch-cnt">(<?php echo (int) $child->count; ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="d14k-cat-empty" id="d14kCatEmpty">Нічого не знайдено за вашим запитом</div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Prom.ua AJAX handlers
    // =========================================================================

    /**
     * AJAX: Test Prom.ua API connection with the provided token.
     */
    public function ajax_prom_test_api()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        if (!$token) {
            // Try saved token
            $settings = get_option('d14k_feed_settings', array());
            $token = isset($settings['prom_api_token']) ? $settings['prom_api_token'] : '';
        }

        if (!$token) {
            wp_send_json_error('Введіть API-токен');
        }

        $api    = new D14K_Prom_API($token);
        $result = $api->test_connection();

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX: Run Prom.ua → WooCommerce import immediately.
     */
    public function ajax_prom_import_now()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->prom_importer) {
            wp_send_json_error('Prom importer не ініціалізований');
        }

        $settings = get_option('d14k_feed_settings', array());
        $mode     = isset($settings['prom_import_mode']) ? $settings['prom_import_mode'] : 'full';

        if ($mode === 'prices') {
            // Оновлення цін — один запит (не потребує пагінації зображень)
            $stats = $this->prom_importer->update_prices_and_stock();
            if (!empty($stats['errors'])) {
                wp_send_json_error(implode('; ', array_slice($stats['errors'], 0, 3)));
            }
            $msg = "Ціни оновлено. Оновлено: {$stats['updated']}, пропущено: {$stats['skipped']}";
            wp_send_json_success(array('message' => $msg, 'done' => true, 'stats' => $stats));
            return;
        }

        // Порційний імпорт — одна сторінка (100 товарів) за запит.
        // Prom API використовує last_id (cursor), НЕ номер сторінки.
        $last_id  = isset($_POST['last_id']) ? (int)$_POST['last_id'] : 0;
        $settings = get_option('d14k_feed_settings', array());
        $group_id = isset($settings['prom_import_group_id']) ? (int)$settings['prom_import_group_id'] : 0;
        $result   = $this->prom_importer->import_page($last_id, $group_id);

        if (!empty($result['error'])) {
            wp_send_json_error($result['error']);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Push WooCommerce feed to Prom.ua now.
     */
    public function ajax_prom_export_now()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->prom_exporter) {
            wp_send_json_error('Prom exporter не ініціалізований');
        }

        $result = $this->prom_exporter->push_feed_to_prom();

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX: Check status of the last import task on Prom.ua.
     */
    public function ajax_prom_import_status()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->prom_exporter) {
            wp_send_json_error('Prom exporter не ініціалізований');
        }

        $result = $this->prom_exporter->check_last_import_status();
        if ($result === false) {
            wp_send_json_error('Немає активного завдання імпорту');
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Run supplier feeds update immediately.
     */
    public function ajax_supplier_feeds_run()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->supplier_feeds) {
            wp_send_json_error('Supplier feeds не ініціалізований');
        }

        $reset      = !empty($_POST['reset']);
        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 25;
        $result     = $this->supplier_feeds->process_batch($batch_size, $reset);

        if (empty($result['results'])) {
            wp_send_json_error('Жодного увімкненого фіду постачальника не налаштовано');
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Trash invalid supplier products in batches.
     */
    public function ajax_supplier_feeds_cleanup()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->supplier_feeds) {
            wp_send_json_error('Supplier feeds не ініціалізований');
        }

        $reset      = !empty($_POST['reset']);
        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 25;
        $result     = $this->supplier_feeds->cleanup_invalid_products_batch($batch_size, $reset);

        if (empty($result['results'])) {
            wp_send_json_error('Жодного увімкненого фіду постачальника не налаштовано');
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Roll back the last supplier import using the saved snapshot.
     */
    public function ajax_supplier_feeds_rollback_last_import()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->supplier_feeds) {
            wp_send_json_error('Supplier feeds не ініціалізований');
        }

        $result = $this->supplier_feeds->rollback_last_import();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message(), 400);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Start supplier import in background queue.
     */
    public function ajax_supplier_feeds_background_start()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->supplier_feeds) {
            wp_send_json_error('Supplier feeds не ініціалізований');
        }

        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 25;
        $max_items_per_feed = isset($_POST['max_items_per_feed']) ? (int) $_POST['max_items_per_feed'] : 0;
        $fresh_start = !empty($_POST['fresh_start']);
        $selection_mode = isset($_POST['selection_mode']) ? sanitize_key(wp_unslash($_POST['selection_mode'])) : '';
        $requested_feeds = null;

        if (isset($_POST['feed']) && is_array($_POST['feed'])) {
            $requested_feed = $this->sanitize_supplier_feed_row(wp_unslash($_POST['feed']));
            if (!$requested_feed) {
                wp_send_json_error('Вкажіть коректний URL фіду.', 400);
            }

            $requested_feeds = array($requested_feed);
        }

        $result = $this->supplier_feeds->start_background_import(
            $batch_size,
            $fresh_start,
            $requested_feeds,
            true,
            array(
                'max_items_per_feed' => max(0, $max_items_per_feed),
                'selection_mode' => $selection_mode,
            )
        );

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Read current supplier background import status.
     */
    public function ajax_supplier_feeds_background_status()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->supplier_feeds) {
            wp_send_json_error('Supplier feeds не ініціалізований');
        }

        wp_send_json_success($this->supplier_feeds->get_background_import_status());
    }

    /**
     * AJAX: Cancel current supplier background import.
     */
    public function ajax_supplier_feeds_background_cancel()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->supplier_feeds) {
            wp_send_json_error('Supplier feeds не ініціалізований');
        }

        $result = $this->supplier_feeds->cancel_background_import();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Analyze supplier feeds without writing products.
     */
    public function ajax_supplier_feeds_analyze()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->supplier_feeds) {
            wp_send_json_error('Supplier feeds не ініціалізований');
        }

        $results      = $this->supplier_feeds->analyze_all_feeds();
        $total_feeds  = count($results);
        $total_offers = 0;
        $total_create = 0;
        $total_update = 0;
        $total_errors = 0;

        foreach ($results as $result) {
            $total_offers += (int) ($result['offers_total'] ?? 0);
            $total_create += (int) ($result['created'] ?? 0);
            $total_update += (int) ($result['updated'] ?? 0);
            $total_errors += !empty($result['errors']) ? count((array) $result['errors']) : 0;
        }

        if ($total_feeds === 0) {
            wp_send_json_error('Жодного увімкненого фіду постачальника не налаштовано');
        }

        $msg = "Проаналізовано фідів: {$total_feeds}. Оферів: {$total_offers}. Буде створено: {$total_create}. Буде оновлено: {$total_update}";
        if ($total_errors > 0) {
            $msg .= ". Помилок: {$total_errors}";
        }

        wp_send_json_success(array(
            'message' => $msg,
            'results' => $results,
            'html'    => $this->render_supplier_analysis_report_html($results),
        ));
    }

    /**
     * AJAX: Analyze one supplier feed row without writing products.
     */
    public function ajax_supplier_feed_analyze_row()
    {
        check_ajax_referer('d14k_ajax_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        if (!$this->supplier_feeds) {
            wp_send_json_error('Supplier feeds не ініціалізований');
        }

        $feed = isset($_POST['feed']) && is_array($_POST['feed']) ? wp_unslash($_POST['feed']) : array();
        $sanitized_feed = $this->sanitize_supplier_feed_row($feed);

        if (!$sanitized_feed) {
            wp_send_json_error('Вкажіть коректний URL фіду.', 400);
        }

        $result = $this->supplier_feeds->analyze_feed($sanitized_feed);
        $host   = parse_url($sanitized_feed['url'], PHP_URL_HOST);
        $msg    = 'Проаналізовано feed ' . ($host ? $host : $sanitized_feed['url']) . '. Оферів: ' . (int) ($result['offers_total'] ?? 0) . '.';
        $msg   .= ' Буде створено: ' . (int) ($result['created'] ?? 0) . '.';
        $msg   .= ' Буде оновлено: ' . (int) ($result['updated'] ?? 0) . '.';
        $msg   .= ' Пропущено: ' . (int) ($result['skipped'] ?? 0) . '.';

        if (!empty($result['errors'])) {
            $msg .= ' Помилок: ' . count((array) $result['errors']) . '.';
        }

        wp_send_json_success(array(
            'message' => $msg,
            'result'  => $result,
            'html'    => $this->render_supplier_analysis_report_html(array(
                $sanitized_feed['url'] => $result,
            )),
        ));
    }

    private function get_defaults()
    {
        return array(
            'enabled' => true,
            'brand_mode' => 'custom',
            'brand' => '',
            'brand_attribute' => '',
            'brand_fallback' => '',
            'country_of_origin' => '',
            'webp_to_jpg' => false,
            'default_google_category' => '',
            'category_map' => array(),
            'excluded_categories' => array(),
            'attribute_filters' => array(),
            'custom_rules' => array(),
            'category_label_0_map' => array(),
            'category_label_1_map' => array(),
            'category_label_2_map' => array(),
            'category_label_3_map' => array(),
            'category_label_4_map' => array(),
        );
    }

    private function render_google_tab($current_tab, $languages, $next_run, $settings, $wc_attributes, $brand_attribute_options, $wc_cats, $cat_children, $cat_by_id, $excluded_cat_ids, $wpml_trans_map, $attr_terms_data, $custom_rules, $rule_fields, $rule_conditions)
    {
        $google_log_entries = array();
        $default_google_category = isset($settings['default_google_category']) ? trim((string) $settings['default_google_category']) : '';
        $category_map_settings = isset($settings['category_map']) && is_array($settings['category_map']) ? $settings['category_map'] : array();
        $mapping_has_values = $default_google_category !== '';
        foreach ($category_map_settings as $mapped_value) {
            if (trim((string) $mapped_value) !== '') {
                $mapping_has_values = true;
                break;
            }
        }
        $custom_labels_has_values = false;
        for ($label_index = 0; $label_index <= 4; $label_index++) {
            $label_key = 'category_label_' . $label_index . '_map';
            $label_map = isset($settings[$label_key]) && is_array($settings[$label_key]) ? $settings[$label_key] : array();
            foreach ($label_map as $label_value) {
                if (trim((string) $label_value) !== '') {
                    $custom_labels_has_values = true;
                    break 2;
                }
            }
        }
        $attribute_filters_settings = isset($settings['attribute_filters']) && is_array($settings['attribute_filters']) ? $settings['attribute_filters'] : array();
        $attribute_filters_has_values = false;
        foreach ($attribute_filters_settings as $attribute_filter) {
            if (!empty($attribute_filter['enabled']) || !empty($attribute_filter['values'])) {
                $attribute_filters_has_values = true;
                break;
            }
        }
        $excluded_categories_count = count($excluded_cat_ids);
        $custom_rules_count = is_array($custom_rules) ? count($custom_rules) : 0;
        $mapping_summary_label = $mapping_has_values ? 'Налаштовано' : 'Не використовується';
        $labels_summary_label = $custom_labels_has_values ? 'Є значення' : 'Не використовується';
        $excluded_summary_label = $excluded_categories_count ? ('Виключено: ' . $excluded_categories_count) : 'Не використовується';
        $attribute_summary_label = $attribute_filters_has_values ? 'Є активні фільтри' : 'Не використовується';
        $rules_summary_label = $custom_rules_count ? ('Правил: ' . $custom_rules_count) : 'Не використовується';

        foreach ($languages as $lang) {
            $last_generated = get_option("d14k_feed_last_generated_{$lang}", '');
            $last_error = get_option("d14k_feed_last_error_{$lang}", '');
            $last_stats = $this->generator->get_last_stats($lang);
            $summary = array();

            if (!empty($last_stats['valid'])) {
                $summary[] = 'товарів: ' . (int) $last_stats['valid'];
            }

            if (!empty($last_stats['skipped'])) {
                $summary[] = 'пропущено: ' . (int) $last_stats['skipped'];
            }

            if ($last_error) {
                $summary[] = 'помилка: ' . $last_error;
            }

            $google_log_entries[] = array(
                'label' => 'GMC / ' . strtoupper($lang),
                'time' => $last_generated ? $last_generated : 'Ще не було',
                'text' => !empty($summary) ? implode(' • ', $summary) : 'Ще немає згенерованого фіду',
                'tone' => $last_error ? 'error' : ($last_generated ? 'success' : 'muted'),
            );
        }

        if ($next_run) {
            $google_log_entries[] = array(
                'label' => 'Автогенерація',
                'time' => $next_run,
                'text' => 'Наступний запуск за розкладом',
                'tone' => 'info',
            );
        }
        ?>
        <!-- TAB: Google -->
        <div class="d14k-tab-content<?php echo $current_tab === 'google' ? ' d14k-tab-content--active' : ''; ?>"
            data-tab="google">
            <?php
            $brand_attribute = isset($settings['brand_attribute']) ? $settings['brand_attribute'] : '';
            $selected_country = isset($settings['country_of_origin']) ? $settings['country_of_origin'] : '';
            $countries = array(
                '' => '— не вказувати —',
                'UA' => 'Україна (Ukraine)',
                'IT' => 'Італія (Italy)',
                'TR' => 'Туреччина (Turkey)',
                'IN' => 'Індія (India)',
                'CN' => 'Китай (China)',
                'US' => 'США (United States)',
                'DE' => 'Німеччина (Germany)',
                'FR' => 'Франція (France)',
                'GB' => 'Велика Британія (United Kingdom)',
                'PL' => 'Польща (Poland)',
            );
            ?>
            <div class="d14k-card d14k-card--google-shell d14k-google-workbench" id="d14k-google-feeds">
                <div class="d14k-google-workbench__head">
                    <h2>Google Merchant Center</h2>
                    <p>Один робочий блок для генерації, перевірки і базових налаштувань фіду.</p>
                </div>

                <div class="d14k-google-workbench__top">
                    <section class="d14k-google-panel d14k-google-panel--feeds">
                        <div class="d14k-google-panel__head">
                            <h3>Фіди</h3>
                            <span class="d14k-fold-card__status"><?php echo esc_html(sprintf('Мов: %d', count($languages))); ?></span>
                        </div>
                        <div class="d14k-google-feed-list">
                            <?php foreach ($languages as $lang):
                                $feed_url = $this->generator->get_feed_url($lang);
                                $last_gen = get_option("d14k_feed_last_generated_{$lang}", '—');
                                $last_error = get_option("d14k_feed_last_error_{$lang}", '');
                                $last_stats = $this->generator->get_last_stats($lang);
                                $count = $last_stats ? $last_stats['valid'] : '—';
                                $skipped = $last_stats ? (int) $last_stats['skipped'] : 0;
                                $row_status_label = $last_error ? 'Є помилки' : 'Готово';
                                $row_status_class = $last_error ? 'is-error' : 'is-ready';
                                ?>
                                <article class="d14k-google-feed-row">
                                    <div class="d14k-google-feed-row__head">
                                        <span class="d14k-google-feed-row__lang"><?php echo esc_html(strtoupper($lang)); ?></span>
                                        <span class="d14k-google-feed-row__status <?php echo esc_attr($row_status_class); ?>"><?php echo esc_html($row_status_label); ?></span>
                                    </div>
                                    <div class="d14k-google-feed-row__url">
                                        <code id="feed-url-<?php echo esc_attr($lang); ?>"><?php echo esc_url($feed_url); ?></code>
                                        <button type="button" class="button button-small d14k-copy"
                                            data-target="feed-url-<?php echo esc_attr($lang); ?>">Копіювати</button>
                                    </div>
                                    <div class="d14k-google-feed-row__meta">
                                        <span><strong>Оновлено</strong><em><?php echo esc_html($last_gen); ?></em></span>
                                        <span><strong>Товарів</strong><em><?php echo esc_html($count); ?></em></span>
                                        <span><strong>Пропущено</strong><em><?php echo esc_html($skipped); ?></em></span>
                                    </div>
                                    <?php if ($last_error): ?>
                                        <p class="d14k-google-feed-row__issue"><?php echo esc_html($last_error); ?></p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="d14k-google-feed-actions">
                            <?php wp_nonce_field('d14k_generate_now'); ?>
                            <input type="hidden" name="action" value="d14k_generate_now">
                            <button type="submit" class="button button-primary">Згенерувати зараз</button>
                            <?php if ($next_run): ?>
                                <span class="d14k-next-run">Наступна автогенерація: <?php echo esc_html($next_run); ?></span>
                            <?php endif; ?>
                        </form>
                    </section>

                    <section class="d14k-google-panel d14k-google-panel--actions">
                        <div class="d14k-google-panel__head">
                            <h3>Дії і перевірка</h3>
                        </div>
                        <div class="d14k-google-actions">
                            <div class="d14k-google-action-card d14k-google-action-card--validation">
                                <span class="d14k-inline-form__note">Перевірте фід на наявність всіх обов'язкових полів перед відправкою до Google Merchant Center</span>
                                <div class="d14k-google-validation-note">
                                    <div class="d14k-disclaimer-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path
                                                d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                            <line x1="12" y1="9" x2="12" y2="13" />
                                            <line x1="12" y1="17" x2="12.01" y2="17" />
                                        </svg>
                                    </div>
                                    <div class="d14k-disclaimer-content">
                                        <strong>Перед відправкою в Google перевіряйте фід тестовою валідацією</strong>
                                        <p>Це допомагає зловити порожні обов'язкові поля, проблемні категорії й некоректні товари до того, як їх забере Merchant Center.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="d14k-google-validation-action">
                            <?php wp_nonce_field('d14k_test_validation'); ?>
                            <input type="hidden" name="action" value="d14k_test_validation">
                            <button type="submit" class="button button-primary">Тестова перевірка (10 товарів)</button>
                        </form>
                    </section>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="d14k-google-form d14k-google-workbench__settings">
                    <?php wp_nonce_field('d14k_save_settings'); ?>
                    <input type="hidden" name="action" value="d14k_save_settings">
                    <input type="hidden" name="d14k_tab" value="google_settings">

                    <div class="d14k-google-panel__head d14k-google-panel__head--settings">
                        <h3>Базові налаштування</h3>
                        <p>Бренд і країна походження, які потрібні для Merchant Center.</p>
                    </div>

                    <div class="d14k-google-settings-grid">
                        <section class="d14k-google-setting-card">
                            <div class="d14k-google-setting-card__head">
                                <h3>Бренд</h3>
                                <p>Вкажіть свій бренд і, за потреби, атрибут WooCommerce. Якщо атрибут у товару порожній, у фід піде значення з поля бренду.</p>
                            </div>
                            <div class="d14k-google-setting-card__body">
                                <div class="d14k-brand-fields">
                                    <label class="d14k-stack-label d14k-stack-label--field">
                                        <span>Бренд</span>
                                        <input type="text" name="brand"
                                            value="<?php echo esc_attr(isset($settings['brand']) ? $settings['brand'] : ''); ?>"
                                            class="regular-text" placeholder="Назва вашого бренду">
                                    </label>
                                    <label class="d14k-stack-label d14k-stack-label--field">
                                        <span>Атрибут бренду</span>
                                        <select name="brand_attribute" class="d14k-select--brand">
                                            <option value="">— не використовувати атрибут —</option>
                                            <?php foreach ($wc_attributes as $attr):
                                                $tax = wc_attribute_taxonomy_name($attr->attribute_name);
                                                ?>
                                                <option value="<?php echo esc_attr($tax); ?>" <?php selected($brand_attribute, $tax); ?>>
                                                    <?php echo esc_html($attr->attribute_label); ?>
                                                    (<?php echo esc_html($tax); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </section>

                        <section class="d14k-google-setting-card">
                            <div class="d14k-google-setting-card__head">
                                <h3>Країна походження</h3>
                                <p>Якщо не вказувати країну, тег <code>country_of_origin</code> не додається у фід.</p>
                            </div>
                            <div class="d14k-google-setting-card__body">
                                <select name="country_of_origin" class="regular-text d14k-select--country">
                                    <?php foreach ($countries as $code => $name): ?>
                                        <option value="<?php echo esc_attr($code); ?>" <?php selected($selected_country, $code); ?>><?php echo esc_html($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </section>
                    </div>

                    <div class="d14k-google-form__actions">
                        <button type="submit" class="button button-primary">Зберегти налаштування Google</button>
                    </div>
                </form>
            </div>

            <?php $this->render_activity_log_card('Журнал Google', $google_log_entries); ?>

            <?php
            $this->render_section_nav(
                array(
                    'd14k-google-feeds' => 'Фіди',
                    'd14k-google-mapping' => 'Маппінг',
                    'd14k-google-filters' => 'Фільтри',
                )
            );
            ?>

            <div id="d14k-google-mapping">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('d14k_save_settings'); ?>
                <input type="hidden" name="action" value="d14k_save_settings">
                <input type="hidden" name="d14k_tab" value="mapping">

                    <details class="d14k-card d14k-card--tight d14k-card--google-shell d14k-fold-card" <?php echo $mapping_has_values ? 'open' : ''; ?>>
                        <summary class="d14k-fold-card__summary">
                            <span class="d14k-fold-card__summary-main">
                                <span class="d14k-fold-card__summary-top">
                                    <span class="d14k-fold-card__title">Маппінг категорій Google</span>
                                    <span class="d14k-fold-card__status"><?php echo esc_html($mapping_summary_label); ?></span>
                                </span>
                                <span class="d14k-fold-card__summary-copy">Вкажіть Google Product Category за замовчуванням і, за потреби, задайте окремий маппінг для конкретних категорій WooCommerce.</span>
                            </span>
                            <span class="d14k-fold-card__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="d14k-fold-card__body">
                            <table class="form-table">
                                <tr>
                                    <th>Google Product Category<br><small>(за замовчуванням)</small></th>
                                    <td>
                                        <input type="text" name="default_google_category"
                                            value="<?php echo esc_attr($default_google_category); ?>"
                                            class="large-text" placeholder="Hardware &amp; Power &amp; Electrical Supplies &gt; Electrical Wires &amp; Cable">
                                        <p class="description d14k-field-description">Це значення використовується лише тоді, коли для конкретної категорії WooCommerce не задано окремий маппінг нижче.</p>
                                    </td>
                                </tr>
                            </table>
                            <p class="d14k-fold-card__body-note">Порожнє значення в полі категорії означає, що використовується Google Product Category за замовчуванням.</p>
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th>Категорія WooCommerce</th>
                                        <th>Google Product Category</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $cat_map = $category_map_settings;
                                    if ($wc_cats && !is_wp_error($wc_cats)):
                                        foreach ($wc_cats as $cat):
                                            if (!$cat->count) {
                                                continue;
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html($cat->name); ?> <small>(<?php echo (int) $cat->count; ?> товарів)</small></td>
                                                <td>
                                                    <input type="text" name="category_map[<?php echo (int) $cat->term_id; ?>]"
                                                        value="<?php echo esc_attr(isset($cat_map[$cat->term_id]) ? $cat_map[$cat->term_id] : ''); ?>"
                                                        class="large-text" placeholder="Apparel &amp; Accessories &gt; Jewelry &gt; ...">
                                                </td>
                                            </tr>
                                            <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </details>

                    <details class="d14k-card d14k-card--tight d14k-card--google-shell d14k-fold-card" <?php echo $custom_labels_has_values ? 'open' : ''; ?>>
                        <summary class="d14k-fold-card__summary">
                            <span class="d14k-fold-card__summary-main">
                                <span class="d14k-fold-card__summary-top">
                                    <span class="d14k-fold-card__title">Custom Labels (0–4)</span>
                                    <span class="d14k-fold-card__status"><?php echo esc_html($labels_summary_label); ?></span>
                                </span>
                                <span class="d14k-fold-card__summary-copy">Використовуйте custom labels для сегментації в Google Ads. Якщо секція не потрібна, її можна тримати згорнутою.</span>
                            </span>
                            <span class="d14k-fold-card__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="d14k-fold-card__body">
                            <?php if ($wc_cats && !is_wp_error($wc_cats)): ?>
                                <div class="d14k-table-scroll">
                                    <table class="widefat striped d14k-table-wide">
                                        <thead>
                                            <tr>
                                                <th>Категорія</th>
                                                <th>Label 0</th>
                                                <th>Label 1</th>
                                                <th>Label 2</th>
                                                <th>Label 3</th>
                                                <th>Label 4</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $cl_maps = array();
                                            for ($cli = 0; $cli <= 4; $cli++) {
                                                $key = 'category_label_' . $cli . '_map';
                                                $cl_maps[$cli] = isset($settings[$key]) ? $settings[$key] : array();
                                            }
                                            foreach ($wc_cats as $cat):
                                                if (!$cat->count) {
                                                    continue;
                                                }
                                                ?>
                                                <tr>
                                                    <td><?php echo esc_html($cat->name); ?> <small>(<?php echo (int) $cat->count; ?>)</small></td>
                                                    <?php for ($cli = 0; $cli <= 4; $cli++): ?>
                                                        <td>
                                                            <input type="text"
                                                                name="category_label_<?php echo $cli; ?>_map[<?php echo (int) $cat->term_id; ?>]"
                                                                value="<?php echo esc_attr(isset($cl_maps[$cli][$cat->term_id]) ? $cl_maps[$cli][$cat->term_id] : ''); ?>"
                                                                class="d14k-label-input" placeholder="—">
                                                        </td>
                                                    <?php endfor; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="description">Категорії не знайдені.</p>
                            <?php endif; ?>
                        </div>
                    </details>

                    <div class="d14k-google-form__actions">
                        <button type="submit" class="button button-primary">Зберегти маппінг</button>
                    </div>
                </form>
            </div>

            <div id="d14k-google-filters">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('d14k_save_settings'); ?>
                    <input type="hidden" name="action" value="d14k_save_settings">
                    <input type="hidden" name="d14k_tab" value="filters">

                    <details class="d14k-card d14k-card--tight d14k-card--google-shell d14k-fold-card" <?php echo $excluded_categories_count ? 'open' : ''; ?>>
                        <summary class="d14k-fold-card__summary">
                            <span class="d14k-fold-card__summary-main">
                                <span class="d14k-fold-card__summary-top">
                                    <span class="d14k-fold-card__title">Виключені категорії</span>
                                    <span class="d14k-fold-card__status"><?php echo esc_html($excluded_summary_label); ?></span>
                                </span>
                                <span class="d14k-fold-card__summary-copy">Позначені категорії не потраплять у фід для всіх мов одночасно. Якщо секція не використовується, її можна тримати згорнутою.</span>
                            </span>
                            <span class="d14k-fold-card__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="d14k-fold-card__body">
                            <?php if (empty($cat_by_id)): ?>
                                <p class="description">Категорії не знайдені.</p>
                            <?php else: ?>
                                <?php echo $this->render_cat_accordion($cat_children, $cat_by_id, $excluded_cat_ids, $wpml_trans_map); ?>
                            <?php endif; ?>
                        </div>
                    </details>

                    <details class="d14k-card d14k-card--tight d14k-card--google-shell d14k-fold-card" <?php echo $attribute_filters_has_values ? 'open' : ''; ?>>
                        <summary class="d14k-fold-card__summary">
                            <span class="d14k-fold-card__summary-main">
                                <span class="d14k-fold-card__summary-top">
                                    <span class="d14k-fold-card__title">Фільтрація за атрибутами</span>
                                    <span class="d14k-fold-card__status"><?php echo esc_html($attribute_summary_label); ?></span>
                                </span>
                                <span class="d14k-fold-card__summary-copy">Налаштуйте whitelist або blacklist по товарних атрибутах. Це корисно, коли частину каталогу треба прибрати з Google без зміни структури категорій.</span>
                            </span>
                            <span class="d14k-fold-card__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="d14k-fold-card__body">
                            <?php if (empty($attr_terms_data)): ?>
                                <p class="description">Товарні атрибути не знайдені.</p>
                            <?php else: ?>
                                <?php foreach ($attr_terms_data as $taxonomy => $attr_data): ?>
                                    <?php
                                    $terms = $attr_data['terms'];
                                    $af = isset($settings['attribute_filters'][$taxonomy]) ? $settings['attribute_filters'][$taxonomy] : array();
                                    $af_enabled = !empty($af['enabled']);
                                    $af_mode = isset($af['mode']) ? $af['mode'] : 'exclude';
                                    $af_values = isset($af['values']) ? (array) $af['values'] : array();
                                    ?>
                                    <div class="d14k-attr-filter-block">
                                        <div class="d14k-attr-filter-head">
                                            <label>
                                                <input type="checkbox" name="attr_filter_enabled[<?php echo esc_attr($taxonomy); ?>]" value="1" <?php checked($af_enabled); ?>>
                                                <strong><?php echo esc_html($attr_data['label']); ?></strong>
                                            </label>
                                            <small class="d14k-muted-text">(<?php echo esc_html($taxonomy); ?>)</small>
                                        </div>
                                        <div class="d14k-attr-filter-body" <?php echo $af_enabled ? '' : ' style="display:none;"'; ?>>
                                            <p class="d14k-attr-filter-mode">
                                                <label><input type="radio" name="attr_filter_mode[<?php echo esc_attr($taxonomy); ?>]" value="exclude" <?php checked($af_mode, 'exclude'); ?>> Виключити вибрані значення</label>
                                                <label><input type="radio" name="attr_filter_mode[<?php echo esc_attr($taxonomy); ?>]" value="include" <?php checked($af_mode, 'include'); ?>> Включити тільки вибрані значення</label>
                                            </p>
                                            <div class="d14k-attr-filter-columns">
                                                <?php foreach ($terms as $term): ?>
                                                    <label class="d14k-attr-filter-option">
                                                        <input type="checkbox"
                                                            name="attr_filter_values[<?php echo esc_attr($taxonomy); ?>][]"
                                                            value="<?php echo esc_attr($term->slug); ?>" <?php checked(in_array($term->slug, $af_values, true)); ?>>
                                                        <?php echo esc_html($term->name); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </details>

                    <details class="d14k-card d14k-card--tight d14k-card--google-shell d14k-fold-card" <?php echo $custom_rules_count ? 'open' : ''; ?>>
                        <summary class="d14k-fold-card__summary">
                            <span class="d14k-fold-card__summary-main">
                                <span class="d14k-fold-card__summary-top">
                                    <span class="d14k-fold-card__title">Розширені правила фільтрації</span>
                                    <span class="d14k-fold-card__status"><?php echo esc_html($rules_summary_label); ?></span>
                                </span>
                                <span class="d14k-fold-card__summary-copy">Додайте правила для тонкого керування фідом. Якщо правила не потрібні, секцію можна тримати згорнутою.</span>
                            </span>
                            <span class="d14k-fold-card__chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="d14k-fold-card__body">
                            <table class="widefat d14k-rules-table" id="d14k-rules-table">
                                <thead>
                                    <tr>
                                        <th>Поле</th>
                                        <th>Ключ (meta)</th>
                                        <th>Умова</th>
                                        <th>Значення</th>
                                        <th>Дія</th>
                                        <th class="d14k-col-status"></th>
                                    </tr>
                                </thead>
                                <tbody id="d14k-rules-body">
                                    <?php foreach ($custom_rules as $ridx => $rule):
                                        $r_field = isset($rule['field']) && isset($rule_fields[$rule['field']]) ? $rule['field'] : 'title';
                                        $r_mk = isset($rule['meta_key']) ? $rule['meta_key'] : '';
                                        $r_cond = isset($rule['condition']) ? $rule['condition'] : 'contains';
                                        $r_val = isset($rule['value']) ? $rule['value'] : '';
                                        $r_action = isset($rule['action']) ? $rule['action'] : 'exclude';
                                        $r_ftype = $rule_fields[$r_field]['type'];
                                        $r_conds = $rule_conditions[$r_ftype];
                                        $r_no_val = in_array($r_cond, array('is_empty', 'not_empty'), true);
                                        ?>
                                        <tr class="d14k-rule-row">
                                            <td>
                                                <select name="custom_rules[<?php echo (int) $ridx; ?>][field]" class="d14k-rule-field d14k-select-auto">
                                                    <?php foreach ($rule_fields as $fk => $fd): ?>
                                                        <option value="<?php echo esc_attr($fk); ?>" <?php selected($r_field, $fk); ?>>
                                                            <?php echo esc_html($fd['label']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="custom_rules[<?php echo (int) $ridx; ?>][meta_key]"
                                                    class="d14k-rule-meta-key"
                                                    value="<?php echo esc_attr($r_mk); ?>"
                                                    placeholder="напр. _weight"
                                                    style="width:110px;<?php echo $r_ftype !== 'meta' ? 'display:none;' : ''; ?>">
                                            </td>
                                            <td>
                                                <select name="custom_rules[<?php echo (int) $ridx; ?>][condition]" class="d14k-rule-condition d14k-select-auto">
                                                    <?php foreach ($r_conds as $ck => $cl): ?>
                                                        <option value="<?php echo esc_attr($ck); ?>" <?php selected($r_cond, $ck); ?>>
                                                            <?php echo esc_html($cl); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <?php if ($r_ftype === 'status'): ?>
                                                    <select name="custom_rules[<?php echo (int) $ridx; ?>][value]" class="d14k-rule-value d14k-select-auto"
                                                        style="<?php echo $r_no_val ? 'display:none;' : ''; ?>">
                                                        <option value="instock" <?php selected($r_val, 'instock'); ?>>В наявності</option>
                                                        <option value="outofstock" <?php selected($r_val, 'outofstock'); ?>>Немає в наявності</option>
                                                        <option value="onbackorder" <?php selected($r_val, 'onbackorder'); ?>>Під замовлення</option>
                                                    </select>
                                                <?php else: ?>
                                                    <input type="<?php echo $r_ftype === 'number' ? 'number' : 'text'; ?>"
                                                        name="custom_rules[<?php echo (int) $ridx; ?>][value]" class="d14k-rule-value"
                                                        value="<?php echo esc_attr($r_val); ?>" placeholder="Значення"
                                                        style="width:130px;<?php echo $r_no_val ? 'display:none;' : ''; ?>">
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <select name="custom_rules[<?php echo (int) $ridx; ?>][action]" class="d14k-select-auto">
                                                    <option value="exclude" <?php selected($r_action, 'exclude'); ?>>Виключити</option>
                                                    <option value="include" <?php selected($r_action, 'include'); ?>>Включити тільки</option>
                                                </select>
                                            </td>
                                            <td><button type="button" class="button d14k-remove-rule">Видалити</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="d14k-rule-actions">
                                <button type="button" class="button" id="d14k-add-rule">Додати правило</button>
                            </p>
                        </div>
                    </details>

                    <div class="d14k-google-form__actions">
                        <button type="submit" class="button button-primary">Зберегти фільтри</button>
                    </div>
                </form>
            </div>
        </div><!-- /.d14k-tab-content[google] -->
        <?php
    }

    private function render_horoshop_tab($current_tab, $settings)
    {
        $channels = isset($settings['yml_channels']) ? (array) $settings['yml_channels'] : array();
        $horoshop_enabled = !empty($channels['horoshop']);
        $feed_url = $this->csv_generator->get_feed_url('horoshop');
        $last_generated = $horoshop_enabled ? $this->csv_generator->get_last_generated('horoshop') : '';
        $last_stats = $horoshop_enabled ? $this->csv_generator->get_last_stats('horoshop') : array();
        $last_error = $horoshop_enabled ? $this->csv_generator->get_last_error('horoshop') : '';
        $products_count = $last_stats && isset($last_stats['valid']) ? (int) $last_stats['valid'] : '—';
        $horoshop_log_entries = array(
            array(
                'label' => 'CSV / Horoshop',
                'time' => $last_generated ? $last_generated : 'Ще не було',
                'text' => $last_error
                    ? 'помилка: ' . $last_error
                    : (($last_stats && isset($last_stats['valid']))
                        ? 'товарів: ' . (int) $last_stats['valid'] . (!empty($last_stats['skipped']) ? ' • пропущено: ' . (int) $last_stats['skipped'] : '')
                        : 'CSV ще не генерувався'),
                'tone' => $last_error ? 'error' : ($last_generated ? 'success' : 'muted'),
            ),
            array(
                'label' => 'Канал',
                'time' => $horoshop_enabled ? 'Увімкнено' : 'Вимкнено',
                'text' => !empty($settings['webp_to_jpg']) ? 'WebP → JPG увімкнено' : 'WebP → JPG вимкнено',
                'tone' => $horoshop_enabled ? 'info' : 'muted',
            ),
        );
        ?>
        <div class="d14k-tab-content<?php echo $current_tab === 'horoshop' ? ' d14k-tab-content--active' : ''; ?>"
            data-tab="horoshop">
            <div class="d14k-card">
                <h2>CSV для Horoshop</h2>
                <p>Тут керується окремий CSV-експорт для Horoshop. Це не частина Google Merchant Center.</p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Канал</th>
                            <th>URL CSV</th>
                            <th>Статус</th>
                            <th>Останнє оновлення</th>
                            <th>Товарів</th>
                            <th>Дії</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Horoshop</strong></td>
                            <td>
                                <code id="feed-url-horoshop"><?php echo esc_url($feed_url); ?></code>
                                <button type="button" class="button button-small d14k-copy"
                                    data-target="feed-url-horoshop">Копіювати</button>
                            </td>
                            <td>
                                <label>
                                    <input type="checkbox" class="d14k-channel-toggle" data-channel="horoshop" <?php checked($horoshop_enabled); ?>>
                                    <?php echo $horoshop_enabled ? 'Увімкнено' : 'Вимкнено'; ?>
                                </label>
                            </td>
                            <td><?php echo esc_html($last_generated ? $last_generated : 'Ще не було'); ?></td>
                            <td><?php echo esc_html($products_count); ?></td>
                            <td>
                                <button type="button" class="button d14k-generate-channel" data-channel="horoshop" <?php disabled(!$horoshop_enabled); ?>>
                                    Згенерувати CSV
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php if ($last_error): ?>
                    <p class="description"><strong>Остання помилка:</strong> <?php echo esc_html($last_error); ?></p>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('d14k_save_settings'); ?>
                <input type="hidden" name="action" value="d14k_save_settings">
                <input type="hidden" name="d14k_tab" value="horoshop">

                <div class="d14k-card d14k-card--tight">
                    <h2>Налаштування Horoshop</h2>
                    <table class="form-table">
                        <tr>
                            <th>Зображення<br><small>(WebP → JPG)</small></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="webp_to_jpg" value="1" <?php checked(!empty($settings['webp_to_jpg'])); ?>>
                                    <strong>Конвертувати WebP → JPG</strong> тільки для CSV Horoshop
                                </label>
                                <p class="description">
                                    Використовуйте це, якщо Horoshop не приймає WebP. JPG-копії кешуються у
                                    <code>wp-content/uploads/d14k-feeds/images/</code>.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary">Зберегти налаштування Horoshop</button>
                </p>
            </form>

            <?php $this->render_activity_log_card('Журнал Horoshop', $horoshop_log_entries); ?>
        </div>
        <?php
    }

    private function render_overview_tab($current_tab, $languages, $next_run, $prom_settings, $last_import, $supplier_feeds_list, $supplier_last_run, $settings)
    {
        $webp_enabled = !empty($settings['webp_to_jpg']);
        $yml_channels = isset($settings['yml_channels']) ? $settings['yml_channels'] : array();
        $horoshop_enabled = !empty($yml_channels['horoshop']);
        $horoshop_last_generated = $horoshop_enabled ? $this->csv_generator->get_last_generated('horoshop') : '';
        $horoshop_last_stats = $horoshop_enabled ? $this->csv_generator->get_last_stats('horoshop') : array();
        $horoshop_products = $horoshop_last_stats && isset($horoshop_last_stats['valid']) ? (int) $horoshop_last_stats['valid'] : 0;
        $google_last_generated = '';
        $google_products = 0;
        foreach ($languages as $lang) {
            $lang_last_generated = get_option("d14k_feed_last_generated_{$lang}", '');
            if ($lang_last_generated && (!$google_last_generated || strcmp($lang_last_generated, $google_last_generated) > 0)) {
                $google_last_generated = $lang_last_generated;
            }
            $lang_stats = $this->generator->get_last_stats($lang);
            if ($lang_stats && isset($lang_stats['valid'])) {
                $google_products += (int) $lang_stats['valid'];
            }
        }
        $supplier_results_count = !empty($supplier_last_run['results']) && is_array($supplier_last_run['results']) ? count($supplier_last_run['results']) : 0;
        $mem_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $mem_ok = $mem_limit >= 128 * 1024 * 1024;
        $mem_rec = $mem_limit >= 256 * 1024 * 1024;
        $gd_loaded = extension_loaded('gd');
        $gd_webp = $gd_loaded && function_exists('imagecreatefromwebp');
        $exec_time = (int) ini_get('max_execution_time');
        $exec_ok = $exec_time === 0 || $exec_time >= 120;
        $upload_dir = wp_upload_dir();
        $dir_writable = wp_is_writable($upload_dir['basedir']);
        $php_ok = version_compare(PHP_VERSION, '7.4', '>=');
        ?>
        <!-- TAB: Огляд -->
        <div class="d14k-tab-content<?php echo $current_tab === 'overview' ? ' d14k-tab-content--active' : ''; ?>"
            data-tab="overview">
            <div class="d14k-card d14k-card--overview-channels">
                <?php if ($horoshop_enabled): ?>
                    <div class="d14k-notice-box d14k-notice-box--warning d14k-notice-box--spaced">
                        <div class="d14k-notice-box-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                        </div>
                        <div class="d14k-notice-box-content">
                            <strong>Категорії потрібно створити в Horoshop заздалегідь</strong>
                            <p class="d14k-notice-box__text">Horoshop не створює категорії автоматично при імпорті CSV. Перед першим
                                імпортом створіть потрібні категорії та підкатегорії в адмінці Horoshop (<em>Товари →
                                    Категорії</em>), а також призначте їм товарний шаблон. Назви повинні точно збігатися з тими,
                                що у CSV.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="d14k-channel-board">
                    <?php
                    $google_status = count($languages) ? 'Активно' : 'Не налаштовано';
                    $google_status_class = count($languages) ? 'd14k-badge--success' : 'd14k-badge--neutral';
                    $google_last_label = $google_last_generated ? $google_last_generated : 'Ще не було';
                    $google_note = count($languages)
                        ? 'Фідів: ' . count($languages) . ' · Товарів: ' . $google_products
                        : 'Мовні фіди ще не додані';

                    $horoshop_status = $horoshop_enabled ? 'Активно' : 'Вимкнено';
                    $horoshop_status_class = $horoshop_enabled ? 'd14k-badge--success' : 'd14k-badge--neutral';
                    $horoshop_last_label = $horoshop_last_generated ? $horoshop_last_generated : 'Ще не було';
                    $horoshop_note = $horoshop_enabled ? 'Товарів: ' . $horoshop_products : 'CSV-канал вимкнений';

                    $prom_enabled = !empty($prom_settings['prom_import_enabled']) || !empty($prom_settings['prom_export_enabled']);
                    $prom_status = $prom_enabled ? 'Активно' : 'Вимкнено';
                    $prom_status_class = $prom_enabled ? 'd14k-badge--success' : 'd14k-badge--neutral';
                    $prom_last_label = !empty($last_import['time']) ? $last_import['time'] : 'Ще не було';
                    $prom_note_bits = array();
                    $prom_note_bits[] = !empty($prom_settings['prom_import_enabled']) ? 'Імпорт увімкнений' : 'Імпорт вимкнений';
                    $prom_note_bits[] = !empty($prom_settings['prom_export_enabled']) ? 'експорт за розкладом' : 'експорт вручну';
                    $prom_note = implode(' · ', $prom_note_bits);

                    $suppliers_enabled = !empty($prom_settings['supplier_feeds_enabled']);
                    $suppliers_status = $suppliers_enabled ? 'Активно' : 'Вимкнено';
                    $suppliers_status_class = $suppliers_enabled ? 'd14k-badge--success' : 'd14k-badge--neutral';
                    $suppliers_last_label = !empty($supplier_last_run['time']) ? $supplier_last_run['time'] : 'Ще не було';
                    if (!empty($supplier_feeds_list)) {
                        $suppliers_note = 'Джерел: ' . count($supplier_feeds_list);
                        if ($supplier_results_count) {
                            $suppliers_note .= ' · Оброблено: ' . $supplier_results_count;
                        }
                    } else {
                        $suppliers_note = 'Фіди постачальників ще не додані';
                    }

                    $channel_rows = array(
                        array(
                            'name' => 'Google Merchant',
                            'mark' => 'GM',
                            'context' => 'Google',
                            'meta' => array(
                                'Фідів: ' . count($languages),
                                'Товарів: ' . $google_products,
                            ),
                            'status' => $google_status,
                            'status_class' => $google_status_class,
                            'tone' => count($languages) ? 'active' : 'idle',
                            'last' => $google_last_label,
                            'note' => $google_note,
                            'url' => admin_url('admin.php?page=d14k-merchant-feed&tab=google'),
                            'action' => 'Google'
                        ),
                        array(
                            'name' => 'Horoshop',
                            'mark' => 'HS',
                            'context' => 'Horoshop',
                            'meta' => array(
                                'CSV',
                                'Товарів: ' . $horoshop_products,
                            ),
                            'status' => $horoshop_status,
                            'status_class' => $horoshop_status_class,
                            'tone' => $horoshop_enabled ? 'active' : 'idle',
                            'last' => $horoshop_last_label,
                            'note' => $horoshop_note,
                            'url' => admin_url('admin.php?page=d14k-merchant-feed&tab=horoshop'),
                            'action' => 'Horoshop'
                        ),
                        array(
                            'name' => 'Prom.ua',
                            'mark' => 'PR',
                            'context' => 'Prom',
                            'meta' => array(
                                !empty($prom_settings['prom_import_enabled']) ? 'Імпорт on' : 'Імпорт off',
                                !empty($prom_settings['prom_export_enabled']) ? 'Експорт on' : 'Експорт off',
                            ),
                            'status' => $prom_status,
                            'status_class' => $prom_status_class,
                            'tone' => $prom_enabled ? 'active' : 'idle',
                            'last' => $prom_last_label,
                            'note' => $prom_note,
                            'url' => admin_url('admin.php?page=d14k-merchant-feed&tab=prom'),
                            'action' => 'Prom'
                        ),
                        array(
                            'name' => 'Dropshipping',
                            'mark' => 'DS',
                            'context' => 'Постачальники',
                            'meta' => array(
                                'Джерел: ' . count($supplier_feeds_list),
                                $supplier_results_count ? 'Оброблено: ' . $supplier_results_count : 'Запусків ще не було',
                            ),
                            'status' => $suppliers_status,
                            'status_class' => $suppliers_status_class,
                            'tone' => $suppliers_enabled ? 'active' : 'idle',
                            'last' => $suppliers_last_label,
                            'note' => $suppliers_note,
                            'url' => admin_url('admin.php?page=d14k-merchant-feed&tab=suppliers'),
                            'action' => 'Фіди'
                        ),
                        array(
                            'name' => 'Rozetka',
                            'mark' => 'RZ',
                            'context' => 'Rozetka',
                            'meta' => array(
                                'Підготовка',
                                'Канал ще не запущений',
                            ),
                            'status' => 'Планується',
                            'status_class' => 'd14k-badge--coming-soon',
                            'tone' => 'planned',
                            'last' => 'Ще не було',
                            'note' => 'Інтеграція ще не налаштована',
                            'url' => admin_url('admin.php?page=d14k-merchant-feed&tab=rozetka'),
                            'action' => 'Rozetka'
                        ),
                        array(
                            'name' => 'Facebook',
                            'mark' => 'FB',
                            'context' => 'Facebook',
                            'meta' => array(
                                'Підготовка',
                                'Каталог ще не підключений',
                            ),
                            'status' => 'Планується',
                            'status_class' => 'd14k-badge--coming-soon',
                            'tone' => 'planned',
                            'last' => 'Ще не було',
                            'note' => 'Каталог ще не підключений',
                            'url' => admin_url('admin.php?page=d14k-merchant-feed&tab=facebook'),
                            'action' => 'Facebook'
                        ),
                    );

                    ?>

                    <div class="d14k-channel-table">
                        <div class="d14k-channel-list">
                            <?php foreach ($channel_rows as $channel_row): ?>
                                <div class="d14k-channel-row d14k-channel-row--<?php echo esc_attr($channel_row['tone']); ?>">
                                    <div class="d14k-channel-row__main">
                                        <div class="d14k-channel-row__identity">
                                            <div class="d14k-channel-row__copy">
                                                <div class="d14k-channel-row__topline">
                                                    <div class="d14k-channel-row__name"><?php echo esc_html($channel_row['name']); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (!empty($channel_row['meta'])): ?>
                                            <div class="d14k-channel-row__meta">
                                                <?php foreach ($channel_row['meta'] as $meta_item): ?>
                                                    <span class="d14k-channel-row__meta-item"><?php echo esc_html($meta_item); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d14k-channel-row__status">
                                        <span class="d14k-badge <?php echo esc_attr($channel_row['status_class']); ?>"><?php echo esc_html($channel_row['status']); ?></span>
                                    </div>
                                    <div class="d14k-channel-row__last">
                                        <span class="d14k-channel-row__label">Остання активність</span>
                                        <span class="d14k-channel-row__value"><?php echo esc_html($channel_row['last']); ?></span>
                                    </div>
                                    <div class="d14k-channel-row__action">
                                        <a href="<?php echo esc_url($channel_row['url']); ?>" class="button button-secondary d14k-channel-row__settings-link">
                                            <span class="d14k-channel-row__action-icon" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="3"></circle>
                                                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33 1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82 1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                                                </svg>
                                            </span>
                                            <span>Налаштування</span>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d14k-card d14k-card--tight d14k-card--system-status">
                <h2>Статус системи</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th class="d14k-col-icon"></th>
                            <th class="d14k-col-wide">Параметр</th>
                            <th class="d14k-col-value">Значення</th>
                            <th>Рекомендовано</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo $php_ok ? '<span class="d14k-badge d14k-badge--success">OK</span>' : '<span class="d14k-badge d14k-badge--error">Проблема</span>'; ?></td>
                            <td><strong>PHP версія</strong></td>
                            <td><?php echo esc_html(PHP_VERSION); ?></td>
                            <td>≥ 7.4</td>
                        </tr>
                        <tr>
                            <td><?php echo $mem_rec ? '<span class="d14k-badge d14k-badge--success">OK</span>' : ($mem_ok ? '<span class="d14k-badge d14k-badge--warning">Увага</span>' : '<span class="d14k-badge d14k-badge--error">Проблема</span>'); ?></td>
                            <td><strong>Пам'ять (memory_limit)</strong> <span class="d14k-muted-note">Потрібна для
                                    конвертації WebP → JPG</span></td>
                            <td><?php echo esc_html(ini_get('memory_limit')); ?></td>
                            <td>≥ 256M (мін. 128M)</td>
                        </tr>
                        <tr>
                            <td><?php echo $gd_loaded ? '<span class="d14k-badge d14k-badge--success">OK</span>' : '<span class="d14k-badge d14k-badge--error">Проблема</span>'; ?></td>
                            <td><strong>PHP GD</strong></td>
                            <td><?php echo $gd_loaded ? 'Увімкнено' : 'Вимкнено'; ?></td>
                            <td>Обов'язково для конвертації</td>
                        </tr>
                        <tr>
                            <td><?php echo $gd_webp ? '<span class="d14k-badge d14k-badge--success">OK</span>' : '<span class="d14k-badge d14k-badge--error">Проблема</span>'; ?></td>
                            <td><strong>GD WebP підтримка</strong></td>
                            <td><?php echo $gd_webp ? 'Увімкнено' : 'Вимкнено'; ?></td>
                            <td>Обов'язково для WebP → JPG</td>
                        </tr>
                        <tr>
                            <td><?php echo $exec_ok ? '<span class="d14k-badge d14k-badge--success">OK</span>' : '<span class="d14k-badge d14k-badge--warning">Увага</span>'; ?></td>
                            <td><strong>Час виконання (max_execution_time)</strong> <span class="d14k-muted-note">Впливає
                                    на генерацію великих фідів</span></td>
                            <td><?php echo $exec_time === 0 ? 'Необмежено' : esc_html($exec_time) . 's'; ?></td>
                            <td>≥ 120s або без обмежень</td>
                        </tr>
                        <tr>
                            <td><?php echo $dir_writable ? '<span class="d14k-badge d14k-badge--success">OK</span>' : '<span class="d14k-badge d14k-badge--error">Проблема</span>'; ?></td>
                            <td><strong>Запис в uploads</strong> <span class="d14k-muted-note">Для кешу зображень та XML
                                    файлів</span></td>
                            <td><?php echo $dir_writable ? 'Доступно' : 'Заблоковано'; ?></td>
                            <td>Обов'язково</td>
                        </tr>
                    </tbody>
                </table>
                <?php if (!$gd_webp && $webp_enabled): ?>
                    <div class="d14k-notice-inline d14k-notice-warning d14k-notice-inline--spaced">
                        Увага. Конвертація WebP → JPG увімкнена, але GD з підтримкою WebP відсутня. Зверніться до хостингу для
                        встановлення <code>libwebp</code>.
                    </div>
                <?php endif; ?>
                <?php if (!$mem_ok): ?>
                    <div class="d14k-notice-inline d14k-notice-warning d14k-notice-inline--spaced">
                        Увага. Пам'ять менше 128M, тому конвертація зображень може завершитися помилкою. Збільшіть
                        <code>memory_limit</code> у <code>php.ini</code> або <code>wp-config.php</code>.
                    </div>
                <?php endif; ?>
            </div>

            <div class="d14k-disclaimer-box">
                <div class="d14k-disclaimer-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path
                            d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <line x1="12" y1="9" x2="12" y2="13" />
                        <line x1="12" y1="17" x2="12.01" y2="17" />
                    </svg>
                </div>
                <div class="d14k-disclaimer-content">
                    <h4>Відмова від відповідальності</h4>
                    <p>Автор не несе відповідальності за втрати, збитки чи інші наслідки використання плагіна. Ви
                        використовуєте його на власний ризик. <strong>Рекомендується робити резервні копії!</strong></p>
                    <h4>Корисні посилання</h4>
                    <ul>
                        <li><a href="https://github.com/MILSPIL/d14k-merchant-feed" target="_blank"
                                rel="noopener noreferrer">Сторінка плагіна на GitHub</a> — опис, поширені запитання та
                            оновлення.</li>
                        <li><a href="https://wordpress.org/plugins/wpvivid-backuprestore/" target="_blank"
                                rel="noopener noreferrer">WPvivid Backup & Migration</a> — рекомендований плагін для
                            створення резервних копій сайтів.</li>
                    </ul>
                </div>
            </div>
        </div><!-- /.d14k-tab-content[overview] -->
        <?php
    }

    private function render_prom_tab($current_tab, $prom_settings, $last_import, $last_export)
    {
        $prom_token = isset($prom_settings['prom_api_token']) ? $prom_settings['prom_api_token'] : '';
        $next_import = $this->cron->get_next_prom_import_run();
        $next_export = $this->cron->get_next_prom_export_run();
        $prom_log_entries = array();

        if (!empty($last_import)) {
            $import_text = 'створено: ' . (int) ($last_import['created'] ?? 0)
                . ' • оновлено: ' . (int) ($last_import['updated'] ?? 0)
                . ' • пропущено: ' . (int) ($last_import['skipped'] ?? 0);
            if (!empty($last_import['errors'])) {
                $import_text .= ' • помилок: ' . count($last_import['errors']);
            }

            $prom_log_entries[] = array(
                'label' => 'Імпорт Prom',
                'time' => $last_import['started'] ?? 'Ще не було',
                'text' => $import_text,
                'tone' => !empty($last_import['errors']) ? 'error' : 'success',
            );
        }

        if (!empty($last_export)) {
            $export_text = 'статус: ' . ($last_export['status'] ?? '—');
            if (!empty($last_export['import_id'])) {
                $export_text .= ' • ID: ' . $last_export['import_id'];
            }

            $prom_log_entries[] = array(
                'label' => 'Експорт Prom',
                'time' => $last_export['time'] ?? 'Ще не було',
                'text' => $export_text,
                'tone' => !empty($last_export['status']) ? 'info' : 'muted',
            );
        }

        if ($next_import) {
            $prom_log_entries[] = array(
                'label' => 'Автоімпорт',
                'time' => $next_import,
                'text' => 'Наступний запуск імпорту за розкладом',
                'tone' => 'info',
            );
        }

        if ($next_export) {
            $prom_log_entries[] = array(
                'label' => 'Автоекспорт',
                'time' => $next_export,
                'text' => 'Наступний запуск експорту за розкладом',
                'tone' => 'info',
            );
        }
        ?>
        <!-- TAB: Prom -->
        <div class="d14k-tab-content<?php echo $current_tab === 'prom' ? ' d14k-tab-content--active' : ''; ?>"
            data-tab="prom">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('d14k_save_settings'); ?>
                <input type="hidden" name="action" value="d14k_save_settings">
                <input type="hidden" name="d14k_tab" value="prom">

                <div class="d14k-card">
                    <h2>API-токен Prom.ua</h2>
                    <p>Токен отримується в Кабінеті компанії Prom.ua → <strong>Налаштування → API</strong>.<br>
                    Документація: <a href="https://public-api.docs.prom.ua/" target="_blank">public-api.docs.prom.ua</a></p>
                    <table class="form-table">
                        <tr>
                            <th>API токен</th>
                            <td>
                                <div class="d14k-inline-actions d14k-inline-actions--tight">
                                    <input type="password" name="prom_api_token" value="<?php echo esc_attr($prom_token); ?>"
                                        class="regular-text" placeholder="Вставте API-токен Prom.ua" autocomplete="off">
                                    <button type="button" id="d14k-prom-test-api" class="button button-secondary">
                                        Перевірити з'єднання
                                    </button>
                                    <span id="d14k-prom-test-result" class="d14k-action-result" aria-live="polite"></span>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="d14k-card">
                    <h2>Імпорт: Prom.ua → WooCommerce</h2>
                    <p>Завантажує товари з Вашого магазину на Prom.ua та створює або оновлює їх у WooCommerce. Для першого запуску краще лишати повний імпорт, а для регулярного оновлення можна перемкнутися на ціни та наявність.</p>
                    <table class="form-table">
                        <tr>
                            <th>Увімкнути автоімпорт</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="prom_import_enabled" value="1"
                                        <?php checked(!empty($prom_settings['prom_import_enabled'])); ?>>
                                    Виконувати імпорт за розкладом
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Режим імпорту</th>
                            <td>
                                <select name="prom_import_mode">
                                    <option value="full" <?php selected(isset($prom_settings['prom_import_mode']) ? $prom_settings['prom_import_mode'] : 'full', 'full'); ?>>
                                        Повний імпорт (нові + оновлення)
                                    </option>
                                    <option value="prices" <?php selected(isset($prom_settings['prom_import_mode']) ? $prom_settings['prom_import_mode'] : '', 'prices'); ?>>
                                        Тільки ціни та наявність
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Розклад імпорту</th>
                            <td>
                                <select name="prom_import_interval">
                                    <?php foreach ($this->cron->get_interval_options() as $val => $label): ?>
                                        <option value="<?php echo esc_attr($val); ?>"
                                            <?php selected(isset($prom_settings['prom_import_interval']) ? $prom_settings['prom_import_interval'] : 'daily', $val); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($next_import): ?>
                                    <span class="d14k-next-run">Наступний запуск: <?php echo esc_html($next_import); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Націнка на ціну (%)</th>
                            <td>
                                <input type="number" name="prom_price_markup" min="0" max="1000" step="0.1"
                                    value="<?php echo esc_attr(isset($prom_settings['prom_price_markup']) ? $prom_settings['prom_price_markup'] : 0); ?>"
                                    class="small-text">
                                <span class="description">0 = без націнки. 20 = +20% до ціни Prom</span>
                            </td>
                        </tr>
                        <tr>
                            <th>Тільки "є в наявності"</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="prom_only_available" value="1"
                                        <?php checked(isset($prom_settings['prom_only_available']) ? $prom_settings['prom_only_available'] : true); ?>>
                                    Імпортувати тільки товари з наявністю (presence=available)
                                </label>
                                <p class="description">Рекомендовано. Товари "немає в наявності" пропускаються.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Тільки товари з фото</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="prom_only_with_images" value="1"
                                        <?php checked(!empty($prom_settings['prom_only_with_images'])); ?>>
                                    Не імпортувати товари без фотографії
                                </label>
                                <p class="description">Товари з заглушкою або без фото на Prom.ua пропускаються.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Завантажувати зображення</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="prom_import_images" value="1"
                                        <?php checked(!empty($prom_settings['prom_import_images'])); ?>>
                                    Зберігати фото товарів у медіабібліотеку WP
                                </label>
                                <p class="description">Фото завантажуються в розмірі 640×640 через cURL.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Статус нових товарів</th>
                            <td>
                                <select name="prom_import_product_status">
                                    <option value="publish" <?php selected(isset($prom_settings['prom_import_product_status']) ? $prom_settings['prom_import_product_status'] : 'publish', 'publish'); ?>>
                                        Опублікований
                                    </option>
                                    <option value="draft" <?php selected(isset($prom_settings['prom_import_product_status']) ? $prom_settings['prom_import_product_status'] : '', 'draft'); ?>>
                                        Чернетка (перевірити перед публікацією)
                                    </option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <div class="d14k-action-bar">
                        <button type="button" id="d14k-prom-import-now" class="button button-primary">
                            Імпортувати зараз
                        </button>
                        <span id="d14k-prom-import-result" class="d14k-action-result" aria-live="polite"></span>
                    </div>

                    <?php if (!empty($last_import)): ?>
                    <div class="d14k-sync-summary d14k-sync-summary--compact">
                        <div class="d14k-sync-summary-headline">
                            <strong>Останній імпорт:</strong> <?php echo esc_html($last_import['started'] ?? '—'); ?>
                        </div>
                        <div class="d14k-sync-summary-metrics d14k-sync-summary-metrics--inline">
                            <span>Створено: <strong><?php echo (int) ($last_import['created'] ?? 0); ?></strong></span>
                            <span>Оновлено: <strong><?php echo (int) ($last_import['updated'] ?? 0); ?></strong></span>
                            <span>Пропущено: <strong><?php echo (int) ($last_import['skipped'] ?? 0); ?></strong></span>
                        </div>
                        <?php if (!empty($last_import['errors'])): ?>
                            <div class="d14k-error">Помилки: <?php echo esc_html(count($last_import['errors'])); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="d14k-card">
                    <h2>Експорт: WooCommerce → Prom.ua</h2>
                    <p>Відправляє поточний YML-фід на Prom.ua. Тут краще ставити власний розклад, окремий від імпорту, щоб експорт не був прив'язаний до чужого циклу оновлення.
                    <strong>Потрібен увімкнений канал Prom.ua у вкладці «Фіди».</strong></p>
                    <table class="form-table">
                        <tr>
                            <th>Увімкнути автоекспорт</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="prom_export_enabled" value="1"
                                        <?php checked(!empty($prom_settings['prom_export_enabled'])); ?>>
                                    Надсилати фід на Prom.ua за розкладом
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Авто-експорт після генерації фіду</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="prom_auto_export_after_feed" value="1"
                                        <?php checked(!empty($prom_settings['prom_auto_export_after_feed'])); ?>>
                                    Після кожної генерації YML автоматично тригерити Prom
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Розклад автоекспорту</th>
                            <td>
                                <select name="prom_export_interval">
                                    <?php foreach ($this->cron->get_interval_options() as $val => $label): ?>
                                        <option value="<?php echo esc_attr($val); ?>"
                                            <?php selected(isset($prom_settings['prom_export_interval']) ? $prom_settings['prom_export_interval'] : (isset($prom_settings['prom_import_interval']) ? $prom_settings['prom_import_interval'] : 'daily'), $val); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($next_export): ?>
                                    <span class="d14k-next-run">Наступний запуск: <?php echo esc_html($next_export); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Синхронізація при збереженні товару</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="prom_realtime_sync" value="1"
                                        <?php checked(!empty($prom_settings['prom_realtime_sync'])); ?>>
                                    При збереженні товару в WP одразу оновити ціну/статус на Prom
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>URL фіду (за замовчуванням)</th>
                            <td>
                                <div class="d14k-field-stack d14k-field-stack--prom-feed">
                                    <input type="url" name="prom_feed_url_override"
                                        value="<?php echo esc_attr(isset($prom_settings['prom_feed_url_override']) ? $prom_settings['prom_feed_url_override'] : ''); ?>"
                                        class="regular-text d14k-input--feed-url" placeholder="Залиште порожнім — автоматично">
                                    <span class="description d14k-inline-code-note">
                                        Авто-URL:
                                        <code><?php echo esc_html(rtrim(home_url('/'), '/') . '/marketplace-feed/prom/'); ?></code>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <div class="d14k-action-bar d14k-action-bar--footer">
                        <div class="d14k-action-copy">
                            <strong>Ручний експорт</strong>
                            <p>Запускає відправку поточного YML-фіду в Prom без очікування розкладу. Збереження налаштувань цю дію не запускає.</p>
                        </div>
                        <div class="d14k-inline-actions d14k-inline-actions--footer">
                            <button type="button" id="d14k-prom-export-now" class="button button-secondary">
                                Запустити експорт зараз
                            </button>
                            <button type="submit" class="button button-primary button-large">Зберегти налаштування Prom</button>
                        </div>
                        <span id="d14k-prom-export-result" class="d14k-action-result d14k-action-result--footer" aria-live="polite"></span>
                    </div>

                    <?php if (!empty($last_export)): ?>
                    <div class="d14k-sync-summary">
                        <strong>Останній експорт:</strong> <?php echo esc_html($last_export['time'] ?? '—'); ?>
                        <div class="d14k-sync-summary-metrics">
                            <span>Статус: <strong><?php echo esc_html($last_export['status'] ?? '—'); ?></strong></span>
                        </div>
                        <?php if (!empty($last_export['import_id'])): ?>
                            <div class="d14k-inline-actions d14k-inline-actions--tight">
                                <span>ID завдання: <strong><?php echo esc_html($last_export['import_id']); ?></strong></span>
                                <button type="button" id="d14k-prom-check-status" class="button button-secondary">
                                    Оновити статус
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php $this->render_activity_log_card('Журнал Prom', $prom_log_entries); ?>
            </form>
        </div>
        <?php
    }

    private function render_suppliers_tab($current_tab, $prom_settings, $supplier_feeds_list, $supplier_last_run, $supplier_last_cleanup)
    {
        $next_sf = $this->cron->get_next_supplier_feeds_run();
        $supplier_interval_options = $this->cron->get_interval_options();
        $supplier_weekday_options = $this->cron->get_supplier_weekday_options();
        $supplier_schedule_defaults = $this->cron->get_supplier_schedule_defaults($prom_settings);
        $supplier_log_entries = array();

        if (!empty($supplier_last_run['results']) && is_array($supplier_last_run['results'])) {
            foreach ($supplier_last_run['results'] as $sf_url => $sf_res) {
                $error_details = array();
                if (!empty($sf_res['errors']) && is_array($sf_res['errors'])) {
                    $error_details = array_slice(array_values(array_filter(array_map('strval', $sf_res['errors']))), 0, 3);
                }

                $supplier_log_entries[] = array(
                    'label' => parse_url($sf_url, PHP_URL_HOST) ?: 'Feed постачальника',
                    'time' => $supplier_last_run['time'] ?? 'Ще не було',
                    'text' => 'створено: ' . (int) ($sf_res['created'] ?? 0)
                        . ' • оновлено: ' . (int) ($sf_res['updated'] ?? 0)
                        . ' • пропущено: ' . (int) ($sf_res['skipped'] ?? 0)
                        . (!empty($sf_res['errors']) ? ' • помилок: ' . count($sf_res['errors']) : ''),
                    'tone' => !empty($sf_res['errors']) ? 'error' : 'success',
                    'details' => $error_details,
                );
            }
        }

        if ($next_sf) {
            $supplier_log_entries[] = array(
                'label' => 'Автооновлення',
                'time' => $next_sf,
                'text' => 'Наступний запуск обробки фідів постачальників',
                'tone' => 'info',
            );
        }

        if (!empty($supplier_last_cleanup['results']) && is_array($supplier_last_cleanup['results'])) {
            foreach ($supplier_last_cleanup['results'] as $sf_url => $sf_res) {
                $cleanup_details = array();
                if (!empty($sf_res['samples']['trashed']) && is_array($sf_res['samples']['trashed'])) {
                    $cleanup_details = array_slice($sf_res['samples']['trashed'], 0, 3);
                }

                $supplier_log_entries[] = array(
                    'label' => (parse_url($sf_url, PHP_URL_HOST) ?: 'Feed постачальника') . ' cleanup',
                    'time' => $supplier_last_cleanup['time'] ?? '—',
                    'text' => 'перевірено: ' . (int) ($sf_res['scanned'] ?? 0)
                        . ' • переміщено в кошик: ' . (int) ($sf_res['trashed'] ?? 0)
                        . ' • залишено: ' . (int) ($sf_res['kept'] ?? 0)
                        . (!empty($sf_res['errors']) ? ' • помилок: ' . count($sf_res['errors']) : ''),
                    'tone' => !empty($sf_res['trashed']) ? 'warning' : 'info',
                    'details' => $cleanup_details,
                );
            }
        }

        if (empty($supplier_log_entries)) {
            $supplier_log_entries[] = array(
                'label' => 'Постачальники',
                'time' => 'Ще не було',
                'text' => 'Після першого запуску тут буде видно результати по кожному feed',
                'tone' => 'muted',
            );
        }

        $supplier_background_state = $this->supplier_feeds
            ? $this->supplier_feeds->get_background_import_status()
            : array('status' => 'idle');
        $supplier_background_status = (string) ($supplier_background_state['status'] ?? 'idle');
        $supplier_background_labels = array(
            'idle' => 'Черга вільна',
            'queued' => 'Є feed у черзі',
            'running' => 'Імпорт виконується',
            'completed' => 'Останній запуск завершено',
            'failed' => 'Є помилка імпорту',
            'cancelled' => 'Імпорт скасовано',
        );
        $supplier_background_notes = array(
            'idle' => 'Новий запуск можна ставити у фон без конфлікту.',
            'queued' => 'Черга тримає один активний supplier import і не дублює той самий feed.',
            'running' => 'Зараз виконується фоновий supplier import.',
            'completed' => 'Останній фоновий прогін завершився, деталі нижче.',
            'failed' => 'Останній прогін зупинився з помилкою. Можна перевірити статус і продовжити.',
            'cancelled' => 'Останній прогін зупинено вручну.',
        );
        $supplier_background_tone = 'neutral';

        if ($supplier_background_status === 'running') {
            $supplier_background_tone = 'active';
        } elseif ($supplier_background_status === 'queued') {
            $supplier_background_tone = 'warning';
        } elseif ($supplier_background_status === 'failed') {
            $supplier_background_tone = 'error';
        } elseif ($supplier_background_status === 'completed') {
            $supplier_background_tone = 'success';
        }

        $supplier_total_feeds = count($supplier_feeds_list);
        $supplier_enabled_count = 0;
        $supplier_scheduled_count = 0;

        foreach ($supplier_feeds_list as $supplier_feed_item) {
            if (empty($supplier_feed_item['enabled'])) {
                continue;
            }

            $supplier_enabled_count += 1;

            if ($this->cron->get_next_supplier_feed_run($supplier_feed_item)) {
                $supplier_scheduled_count += 1;
            }
        }
        ?>
        <!-- TAB: Постачальники -->
        <div class="d14k-tab-content<?php echo $current_tab === 'suppliers' ? ' d14k-tab-content--active' : ''; ?>"
            data-tab="suppliers">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('d14k_save_settings'); ?>
                <input type="hidden" name="action" value="d14k_save_settings">
                <input type="hidden" name="d14k_tab" value="suppliers">

                <div class="d14k-card d14k-card--suppliers">
                    <h2>Фіди постачальників</h2>
                    <?php $supplier_update_fields = class_exists('D14K_Supplier_Feeds') ? D14K_Supplier_Feeds::get_available_update_fields() : array(); ?>
                    <div class="d14k-supplier-shell">
                        <div class="d14k-supplier-overview">
                            <section class="d14k-supplier-surface d14k-supplier-intro">
                                <span class="d14k-supplier-intro__eyebrow">Supplier feeds</span>
                                <h3>Один спокійний сценарій для додавання, перевірки і запуску</h3>
                                <p>Вкладка тепер зібрана навколо реального процесу роботи: спочатку підключаємо feed, потім перевіряємо аналіз, після цього запускаємо або плануємо фонове оновлення.</p>
                                <div class="d14k-supplier-guide">
                                    <article class="d14k-supplier-guide__item">
                                        <div class="d14k-supplier-guide__topline">
                                            <span class="d14k-supplier-guide__step">1</span>
                                            <strong>Додайте<br>feed</strong>
                                        </div>
                                        <p>URL, назва, корінь категорій і ті поля, які справді треба оновлювати.</p>
                                    </article>
                                    <article class="d14k-supplier-guide__item">
                                        <div class="d14k-supplier-guide__topline">
                                            <span class="d14k-supplier-guide__step">2</span>
                                            <strong>Перевірте<br>аналіз</strong>
                                        </div>
                                        <p>Подивіться, скільки товарів буде створено, оновлено або пропущено, і чи є ризики.</p>
                                    </article>
                                    <article class="d14k-supplier-guide__item">
                                        <div class="d14k-supplier-guide__topline">
                                            <span class="d14k-supplier-guide__step">3</span>
                                            <strong>Запускайте<br>у фоні</strong>
                                        </div>
                                        <p>Черга виконує один supplier import за раз, не дублює queued run і підхоплює наступний feed автоматично.</p>
                                    </article>
                                </div>
                            </section>
                            <div class="d14k-supplier-overview__metrics">
                                <article class="d14k-supplier-metric">
                                    <span class="d14k-supplier-metric__label">Усього feed</span>
                                    <strong class="d14k-supplier-metric__value"><?php echo (int) $supplier_total_feeds; ?></strong>
                                    <span class="d14k-supplier-metric__note"><?php echo $supplier_total_feeds ? 'Можна редагувати по одному або запускати всі разом.' : 'Почніть із додавання першого постачальника.'; ?></span>
                                </article>
                                <article class="d14k-supplier-metric">
                                    <span class="d14k-supplier-metric__label">Активні</span>
                                    <strong class="d14k-supplier-metric__value"><?php echo (int) $supplier_enabled_count; ?></strong>
                                    <span class="d14k-supplier-metric__note"><?php echo (int) $supplier_scheduled_count; ?> feed мають видимий найближчий автозапуск.</span>
                                </article>
                                <article class="d14k-supplier-metric">
                                    <span class="d14k-supplier-metric__label">Найближчий автозапуск</span>
                                    <strong class="d14k-supplier-metric__value d14k-supplier-metric__value--compact"><?php echo esc_html($next_sf ?: 'Ще не заплановано'); ?></strong>
                                    <span class="d14k-supplier-metric__note">Кожен feed нижче має свій розклад і локальний next run.</span>
                                </article>
                                <article class="d14k-supplier-metric">
                                    <span class="d14k-supplier-metric__label">Стан черги</span>
                                    <strong class="d14k-supplier-metric__value d14k-supplier-metric__value--compact"><?php echo esc_html($supplier_background_labels[$supplier_background_status] ?? 'Черга вільна'); ?></strong>
                                    <span class="d14k-supplier-metric__note"><?php echo esc_html($supplier_background_notes[$supplier_background_status] ?? ''); ?></span>
                                </article>
                            </div>
                        </div>

                        <div class="d14k-supplier-workbench">
                            <section class="d14k-supplier-surface d14k-supplier-workbench__automation">
                                <div class="d14k-supplier-surface__head">
                                    <div>
                                        <h3>Автооновлення і черга</h3>
                                        <p>Глобальна зона для планового запуску, live status і керування фоновим імпортом.</p>
                                    </div>
                                </div>
                                <label class="d14k-supplier-toggle">
                                    <input type="checkbox" name="supplier_feeds_enabled" value="1"
                                        <?php checked(!empty($prom_settings['supplier_feeds_enabled'])); ?>>
                                    <span>
                                        <strong>Увімкнути обробку feed постачальників</strong>
                                        <small>Якщо черга в цей момент зайнята, новий запуск чекає без дублювання того самого feed.</small>
                                    </span>
                                </label>
                                <div class="d14k-supplier-inline-meta">
                                    <span><?php echo $next_sf ? 'Найближчий запуск: ' . esc_html($next_sf) : 'Автозапуск ще не заплановано'; ?></span>
                                    <span>Активних feed: <?php echo (int) $supplier_enabled_count; ?> з <?php echo (int) $supplier_total_feeds; ?></span>
                                </div>
                                <div id="d14k-supplier-import-summary" class="d14k-progress-card d14k-progress-card--summary-slot" aria-live="polite"></div>
                            </section>

                            <section class="d14k-supplier-surface d14k-supplier-workbench__actions">
                                <div class="d14k-supplier-surface__head">
                                    <div>
                                        <h3>Запуск і сервісні дії</h3>
                                        <p>Головна дія тут одна: оновити у фоні. Решта інструментів винесені в окрему групу й не сперечаються з нею.</p>
                                    </div>
                                </div>
                                <div class="d14k-supplier-action-cluster">
                                    <button type="button" id="d14k-supplier-feeds-run-now" class="button button-primary">
                                        Оновити у фоні
                                    </button>
                                    <button type="button" id="d14k-supplier-feeds-fresh-start" class="button button-secondary" disabled>
                                        Почати спочатку
                                    </button>
                                    <button type="button" id="d14k-supplier-feeds-cancel" class="button button-secondary" disabled>
                                        Скасувати імпорт
                                    </button>
                                </div>
                                <div class="d14k-supplier-action-cluster d14k-supplier-action-cluster--muted">
                                    <button type="button" id="d14k-supplier-feeds-analyze" class="button button-secondary">
                                        Аналізувати всі фіди
                                    </button>
                                    <button type="button" id="d14k-supplier-feeds-cleanup" class="button button-secondary">
                                        Прибрати некоректні
                                    </button>
                                    <button type="button" id="d14k-supplier-feeds-rollback" class="button button-secondary">
                                        Відкотити останній імпорт
                                    </button>
                                </div>
                                <span id="d14k-supplier-feeds-result" class="d14k-action-result d14k-action-result--supplier" aria-live="polite"></span>
                            </section>
                        </div>

                        <div id="d14k-supplier-import-status" class="d14k-progress-card" aria-live="polite"></div>
                        <div id="d14k-supplier-cleanup-status" class="d14k-progress-card" aria-live="polite"></div>
                        <div id="d14k-supplier-rollback-status" class="d14k-progress-card" aria-live="polite"></div>
                        <div id="d14k-supplier-feeds-analysis-result" class="d14k-action-result d14k-analysis-report" aria-live="polite"></div>
                        <div id="d14k-supplier-feeds-cleanup-result" class="d14k-action-result" aria-live="polite"></div>
                        <div id="d14k-supplier-feeds-rollback-result" class="d14k-action-result" aria-live="polite"></div>

                        <section class="d14k-supplier-list-section">
                            <div class="d14k-supplier-list-header">
                                <div>
                                    <h3>Список постачальників</h3>
                                    <p>Кожен row нижче має власний розклад, локальний live status і окремий запуск одного feed.</p>
                                </div>
                                <button type="button" id="d14k-add-supplier-feed" class="button button-secondary">
                                    Додати фід постачальника
                                </button>
                            </div>
                            <div id="d14k-supplier-feeds-list">
                                <?php if (empty($supplier_feeds_list)): ?>
                                    <div class="d14k-supplier-empty-state">
                                        <strong>Ще немає жодного feed постачальника.</strong>
                                        <p>Додайте перший feed, щоб налаштувати аналіз, розклад і фоновий імпорт без переходів між різними зонами інтерфейсу.</p>
                                    </div>
                                <?php endif; ?>
                                <?php foreach ($supplier_feeds_list as $sf_index => $sf): ?>
                                <?php
                                $sf_url = (string) ($sf['url'] ?? '');
                                $sf_host = parse_url($sf_url, PHP_URL_HOST) ?: 'URL ще не вказаний';
                                $sf_label = trim((string) ($sf['label'] ?? ''));
                                $sf_display_title = $sf_label !== '' ? $sf_label : $sf_host;
                                $sf_schedule_interval = $sf['schedule_interval'] ?? $supplier_schedule_defaults['schedule_interval'];
                                if (!isset($supplier_interval_options[$sf_schedule_interval])) {
                                    $sf_schedule_interval = $supplier_schedule_defaults['schedule_interval'];
                                }
                                $sf_schedule_time = $sf['schedule_time'] ?? $supplier_schedule_defaults['schedule_time'];
                                if (!preg_match('/^\d{2}:\d{2}$/', (string) $sf_schedule_time)) {
                                    $sf_schedule_time = $supplier_schedule_defaults['schedule_time'];
                                }
                                $sf_schedule_weekday = $sf['schedule_weekday'] ?? $supplier_schedule_defaults['schedule_weekday'];
                                if (!isset($supplier_weekday_options[$sf_schedule_weekday])) {
                                    $sf_schedule_weekday = $supplier_schedule_defaults['schedule_weekday'];
                                }
                                $sf_schedule_monthday = (int) ($sf['schedule_monthday'] ?? $supplier_schedule_defaults['schedule_monthday']);
                                $sf_schedule_monthday = min(28, max(1, $sf_schedule_monthday));
                                $sf_schedule_summary = $supplier_interval_options[$sf_schedule_interval] . ' • ' . $sf_schedule_time;
                                if ($sf_schedule_interval === 'd14k_weekly') {
                                    $sf_schedule_summary .= ' • ' . ($supplier_weekday_options[$sf_schedule_weekday] ?? $supplier_schedule_defaults['schedule_weekday']);
                                } elseif ($sf_schedule_interval === 'd14k_monthly') {
                                    $sf_schedule_summary .= ' • ' . $sf_schedule_monthday . ' число';
                                }
                                $sf_next_run = $this->cron->get_next_supplier_feed_run($sf);
                                $sf_last_result = (!empty($supplier_last_run['results']) && isset($supplier_last_run['results'][$sf_url]) && is_array($supplier_last_run['results'][$sf_url]))
                                    ? $supplier_last_run['results'][$sf_url]
                                    : array();
                                $sf_last_cleanup = (!empty($supplier_last_cleanup['results']) && isset($supplier_last_cleanup['results'][$sf_url]) && is_array($supplier_last_cleanup['results'][$sf_url]))
                                    ? $supplier_last_cleanup['results'][$sf_url]
                                    : array();
                                ?>
                                <div class="d14k-supplier-feed-row<?php echo empty($sf['enabled']) ? ' is-disabled' : ''; ?>" data-supplier-index="<?php echo esc_attr($sf_index); ?>">
                                    <div class="d14k-supplier-feed-row__top">
                                        <div class="d14k-supplier-feed-row__identity">
                                            <div class="d14k-supplier-feed-row__title-row">
                                                <h3 class="d14k-supplier-feed-row__title"><?php echo esc_html($sf_display_title); ?></h3>
                                                <div class="d14k-supplier-feed-row__badges">
                                                    <span class="d14k-supplier-state-badge d14k-supplier-state-badge--schedule" data-role="schedule-badge">
                                                        <?php echo esc_html($sf_schedule_summary); ?>
                                                    </span>
                                                    <?php if ($sf_next_run): ?>
                                                        <span class="d14k-supplier-state-badge d14k-supplier-state-badge--next-run">
                                                            <?php echo esc_html($sf_next_run); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d14k-supplier-feed-row__side-actions">
                                            <label class="d14k-supplier-feed-flag d14k-supplier-feed-flag--toggle">
                                                <input type="checkbox" name="supplier_feeds[<?php echo $sf_index; ?>][enabled]" value="1"
                                                    <?php checked(!empty($sf['enabled'])); ?>>
                                                <span>Feed увімкнений</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="d14k-supplier-feed-row__grid">
                                        <section class="d14k-supplier-feed-panel d14k-supplier-feed-panel--source">
                                            <div class="d14k-supplier-feed-panel__head">
                                                <h4>Джерело</h4>
                                                <p>Базові дані постачальника й коренева категорія для цього feed.</p>
                                            </div>
                                            <div class="d14k-supplier-feed-panel__fields d14k-supplier-feed-panel__fields--source">
                                                <label class="d14k-supplier-feed-row__field d14k-supplier-feed-row__field--url">
                                                    <span class="d14k-supplier-feed-row__field-label">URL фіду</span>
                                                    <input type="url" name="supplier_feeds[<?php echo $sf_index; ?>][url]"
                                                        value="<?php echo esc_attr($sf_url); ?>"
                                                        placeholder="https://supplier.com/feed.xml"
                                                        class="regular-text d14k-supplier-feed-row__url">
                                                </label>
                                                <label class="d14k-supplier-feed-row__field d14k-supplier-feed-row__field--compact d14k-supplier-feed-row__field--markup">
                                                    <span class="d14k-supplier-feed-row__field-label">Націнка</span>
                                                    <span class="d14k-supplier-feed-row__markup-shell">
                                                        <input type="number" name="supplier_feeds[<?php echo $sf_index; ?>][markup]"
                                                            value="<?php echo esc_attr($sf['markup'] ?? 0); ?>"
                                                            placeholder="0" min="0" max="1000" step="0.1" class="small-text d14k-supplier-feed-row__markup">
                                                        <span class="d14k-supplier-feed-row__markup-unit">відсотків</span>
                                                    </span>
                                                </label>
                                                <label class="d14k-supplier-feed-row__field d14k-supplier-feed-row__field--label">
                                                    <span class="d14k-supplier-feed-row__field-label">Назва постачальника</span>
                                                    <input type="text" name="supplier_feeds[<?php echo $sf_index; ?>][label]"
                                                        value="<?php echo esc_attr($sf_label); ?>"
                                                        placeholder="Назва постачальника"
                                                        class="regular-text d14k-supplier-feed-row__label">
                                                </label>
                                                <label class="d14k-supplier-feed-row__field d14k-supplier-feed-row__field--category">
                                                    <span class="d14k-supplier-feed-row__field-label">Корінь категорій</span>
                                                    <input type="text" name="supplier_feeds[<?php echo $sf_index; ?>][category_root]"
                                                        value="<?php echo esc_attr($sf['category_root'] ?? ''); ?>"
                                                        placeholder="Окрема папка для цього постачальника"
                                                        class="regular-text">
                                                </label>
                                            </div>
                                        </section>

                                        <section class="d14k-supplier-feed-panel d14k-supplier-feed-panel--updates">
                                            <div class="d14k-supplier-feed-panel__head">
                                                <h4>Що оновлювати</h4>
                                                <p>Для регулярних запусків краще лишати тільки справді потрібні поля, наприклад ціну й наявність.</p>
                                            </div>
                                            <div class="d14k-supplier-feed-row__chips">
                                                <?php
                                                $saved_update_fields = isset($sf['update_fields']) && is_array($sf['update_fields'])
                                                    ? $sf['update_fields']
                                                    : array(
                                                        'price' => 1,
                                                        'stock' => !empty($sf['update_stock']),
                                                        'image' => 1,
                                                        'category' => 1,
                                                        'title' => 1,
                                                        'description' => 1,
                                                        'params' => 1,
                                                        'sku' => 1,
                                                        'vendor' => 1,
                                                    );
                                                ?>
                                                <?php foreach ($supplier_update_fields as $field_key => $field_label): ?>
                                                    <label class="d14k-supplier-feed-chip">
                                                        <input type="checkbox" name="supplier_feeds[<?php echo $sf_index; ?>][update_fields][<?php echo esc_attr($field_key); ?>]" value="1"
                                                            <?php checked(!empty($saved_update_fields[$field_key])); ?>>
                                                        <span><?php echo esc_html($field_label); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </section>

                                        <section class="d14k-supplier-feed-panel d14k-supplier-feed-panel--schedule">
                                            <div class="d14k-supplier-feed-panel__head">
                                                <h4>Розклад цього feed</h4>
                                            </div>
                                            <div class="d14k-supplier-feed-row__schedule">
                                                <label class="d14k-supplier-feed-row__schedule-item">
                                                    <span class="d14k-supplier-feed-row__schedule-label">Частота</span>
                                                    <select name="supplier_feeds[<?php echo $sf_index; ?>][schedule_interval]">
                                                        <?php foreach ($supplier_interval_options as $val => $label): ?>
                                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($sf_schedule_interval, $val); ?>>
                                                                <?php echo esc_html($label); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="d14k-supplier-feed-row__schedule-item">
                                                    <span class="d14k-supplier-feed-row__schedule-label">Час</span>
                                                    <input type="time"
                                                        name="supplier_feeds[<?php echo $sf_index; ?>][schedule_time]"
                                                        value="<?php echo esc_attr($sf_schedule_time); ?>">
                                                </label>
                                                <label class="d14k-supplier-feed-row__schedule-item d14k-supplier-feed-row__schedule-item--weekday<?php echo $sf_schedule_interval !== 'd14k_weekly' ? ' d14k-supplier-feed-row__schedule-item--hidden' : ''; ?>">
                                                    <span class="d14k-supplier-feed-row__schedule-label">День тижня</span>
                                                    <select name="supplier_feeds[<?php echo $sf_index; ?>][schedule_weekday]">
                                                        <?php foreach ($supplier_weekday_options as $val => $label): ?>
                                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($sf_schedule_weekday, $val); ?>>
                                                                <?php echo esc_html($label); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="d14k-supplier-feed-row__schedule-item d14k-supplier-feed-row__schedule-item--monthday<?php echo $sf_schedule_interval !== 'd14k_monthly' ? ' d14k-supplier-feed-row__schedule-item--hidden' : ''; ?>">
                                                    <span class="d14k-supplier-feed-row__schedule-label">День місяця</span>
                                                    <input type="number"
                                                        name="supplier_feeds[<?php echo $sf_index; ?>][schedule_monthday]"
                                                        value="<?php echo esc_attr($sf_schedule_monthday); ?>"
                                                        min="1"
                                                        max="28"
                                                        class="small-text">
                                                </label>
                                            </div>
                                        </section>
                                        <section class="d14k-supplier-feed-panel d14k-supplier-feed-panel--actions">
                                            <div class="d14k-supplier-feed-panel__head d14k-supplier-feed-panel__head--actions">
                                                <h4>Дії з feed</h4>
                                                <span class="d14k-supplier-feed-row__result" aria-live="polite"></span>
                                            </div>
                                            <div class="d14k-supplier-feed-row__action-grid">
                                                <button type="button" class="button button-secondary d14k-remove-feed-row">Видалити</button>
                                                <button type="button" class="button button-secondary d14k-save-feed-row">Зберегти feed</button>
                                                <button type="button" class="button button-secondary d14k-analyze-feed-row">Аналізувати цей feed</button>
                                                <button type="button" class="button button-secondary d14k-run-feed-row">Оновити цей feed у фоні</button>
                                            </div>
                                        </section>
                                    </div>
                                    <div class="d14k-supplier-feed-row__live-status d14k-progress-card" aria-live="polite"></div>
                                    <div class="d14k-supplier-feed-row__analysis d14k-analysis-report" aria-live="polite"></div>
                                    <details class="d14k-supplier-feed-row__test-tools">
                                        <summary>Тестові інструменти</summary>
                                        <div class="d14k-supplier-feed-row__test-tools-body">
                                            <p>Тимчасова зона для локальної перевірки імпорту без повного прогону всього feed.</p>
                                            <button type="button" class="button button-secondary d14k-run-feed-row-test">Тест 2 товари</button>
                                        </div>
                                    </details>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <?php if (!empty($supplier_last_run) || !empty($supplier_last_cleanup)): ?>
                            <div class="d14k-supplier-history-grid">
                                <?php if (!empty($supplier_last_run)): ?>
                                    <section class="d14k-supplier-surface d14k-supplier-history-card">
                                        <div class="d14k-supplier-surface__head">
                                            <div>
                                                <h3>Останній імпорт</h3>
                                                <p><?php echo esc_html($supplier_last_run['time'] ?? '—'); ?></p>
                                            </div>
                                        </div>
                                        <?php if (!empty($supplier_last_run['results'])): ?>
                                            <div class="d14k-supplier-history-list">
                                                <?php foreach ($supplier_last_run['results'] as $sf_url => $sf_res): ?>
                                                    <div class="d14k-supplier-history-line">
                                                        <strong><?php echo esc_html(parse_url($sf_url, PHP_URL_HOST)); ?></strong>
                                                        <span><?php echo (int) ($sf_res['created'] ?? 0); ?> створено · <?php echo (int) ($sf_res['updated'] ?? 0); ?> оновлено · <?php echo (int) ($sf_res['skipped'] ?? 0); ?> пропущено<?php echo !empty($sf_res['errors']) ? ' · помилок: ' . count($sf_res['errors']) : ''; ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                <?php endif; ?>

                                <?php if (!empty($supplier_last_cleanup)): ?>
                                    <section class="d14k-supplier-surface d14k-supplier-history-card">
                                        <div class="d14k-supplier-surface__head">
                                            <div>
                                                <h3>Остання clean-up перевірка</h3>
                                                <p><?php echo esc_html($supplier_last_cleanup['time'] ?? '—'); ?></p>
                                            </div>
                                        </div>
                                        <?php if (!empty($supplier_last_cleanup['results'])): ?>
                                            <div class="d14k-supplier-history-list">
                                                <?php foreach ($supplier_last_cleanup['results'] as $sf_url => $sf_res): ?>
                                                    <div class="d14k-supplier-history-line">
                                                        <strong><?php echo esc_html(parse_url($sf_url, PHP_URL_HOST)); ?></strong>
                                                        <span><?php echo (int) ($sf_res['scanned'] ?? 0); ?> перевірено · <?php echo (int) ($sf_res['trashed'] ?? 0); ?> в кошик · <?php echo (int) ($sf_res['kept'] ?? 0); ?> залишено · <?php echo (int) ($sf_res['categories_deleted'] ?? 0); ?> категорій прибрано<?php echo !empty($sf_res['errors']) ? ' · помилок: ' . count($sf_res['errors']) : ''; ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="d14k-submit-row d14k-submit-row--end">
                        <button type="submit" class="button button-secondary button-large">Зберегти розклад і весь список постачальників</button>
                    </div>
                </div>
            </form>

            <?php $this->render_activity_log_card('Журнал постачальників', $supplier_log_entries); ?>
        </div>
        <?php
    }

    private function render_section_nav($items)
    {
        if (empty($items) || !is_array($items)) {
            return;
        }
        ?>
        <div class="d14k-section-nav">
            <?php foreach ($items as $target_id => $label): ?>
                <a href="#<?php echo esc_attr($target_id); ?>" class="d14k-section-nav__item">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_activity_log_card($title, $entries)
    {
        if (empty($entries) || !is_array($entries)) {
            return;
        }
        ?>
        <div class="d14k-card d14k-card--tight d14k-log-card">
            <h2><?php echo esc_html($title); ?></h2>
            <div class="d14k-log-list">
                <?php foreach ($entries as $entry):
                    $tone = isset($entry['tone']) ? sanitize_html_class($entry['tone']) : 'muted';
                    ?>
                    <div class="d14k-log-item d14k-log-item--<?php echo esc_attr($tone); ?>">
                        <div class="d14k-log-item__meta">
                            <strong><?php echo esc_html($entry['label'] ?? 'Подія'); ?></strong>
                            <span><?php echo esc_html($entry['time'] ?? ''); ?></span>
                        </div>
                        <div class="d14k-log-item__text"><?php echo esc_html($entry['text'] ?? ''); ?></div>
                        <?php if (!empty($entry['details']) && is_array($entry['details'])): ?>
                            <ul class="d14k-log-item__details">
                                <?php foreach ($entry['details'] as $detail): ?>
                                    <li><?php echo esc_html($detail); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_supplier_analysis_report_html($results)
    {
        if (empty($results) || !is_array($results)) {
            return '';
        }

        ob_start();
        ?>
        <div class="d14k-analysis-report__wrap">
            <?php foreach ($results as $feed_url => $result):
                $host        = parse_url($feed_url, PHP_URL_HOST);
                $risk_counts = isset($result['risk_counts']) && is_array($result['risk_counts']) ? $result['risk_counts'] : array();
                $samples     = isset($result['samples']) && is_array($result['samples']) ? $result['samples'] : array();
                ?>
                <div class="d14k-analysis-report__feed">
                    <div class="d14k-analysis-report__head">
                        <strong><?php echo esc_html($host ? $host : $feed_url); ?></strong>
                        <?php if (!empty($result['source_key'])): ?>
                            <code><?php echo esc_html($result['source_key']); ?></code>
                        <?php endif; ?>
                    </div>

                    <div class="d14k-analysis-report__stats">
                        <span>оферів: <?php echo (int) ($result['offers_total'] ?? 0); ?></span>
                        <span>категорій: <?php echo (int) ($result['categories_total'] ?? 0); ?></span>
                        <span>буде створено: <?php echo (int) ($result['created'] ?? 0); ?></span>
                        <span>буде оновлено: <?php echo (int) ($result['updated'] ?? 0); ?></span>
                        <span>пропущено: <?php echo (int) ($result['skipped'] ?? 0); ?></span>
                    </div>

                    <?php if (!empty($result['errors'])): ?>
                        <div class="d14k-analysis-report__block d14k-analysis-report__block--error">
                            <strong>Помилки feed</strong>
                            <ul>
                                <?php foreach (array_slice((array) $result['errors'], 0, 8) as $error): ?>
                                    <li><?php echo esc_html($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($risk_counts['sku_existing_product']) || !empty($risk_counts['sku_other_supplier']) || !empty($risk_counts['missing_identifiers'])): ?>
                        <div class="d14k-analysis-report__block d14k-analysis-report__block--warning">
                            <strong>Ризики перед імпортом</strong>
                            <ul>
                                <?php if (!empty($risk_counts['sku_existing_product'])): ?>
                                    <li>SKU-match з існуючими товарами сайту: <?php echo (int) $risk_counts['sku_existing_product']; ?></li>
                                <?php endif; ?>
                                <?php if (!empty($risk_counts['sku_other_supplier'])): ?>
                                    <li>SKU-match з товарами іншого supplier feed: <?php echo (int) $risk_counts['sku_other_supplier']; ?></li>
                                <?php endif; ?>
                                <?php if (!empty($risk_counts['missing_identifiers'])): ?>
                                    <li>Офери без external ID і SKU, можливі дублікати: <?php echo (int) $risk_counts['missing_identifiers']; ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php
                    $lists = array(
                        'created'              => 'Приклади нових товарів',
                        'updated'              => 'Приклади оновлень',
                        'skipped'              => 'Приклади пропущених оферів',
                        'sku_existing_product' => 'SKU-match з існуючими товарами',
                        'sku_other_supplier'   => 'SKU-match з іншими supplier товарами',
                        'missing_identifiers'  => 'Офери без стабільного ідентифікатора',
                    );
                    foreach ($lists as $sample_key => $label):
                        if (empty($samples[$sample_key]) || !is_array($samples[$sample_key])) {
                            continue;
                        }
                        ?>
                        <div class="d14k-analysis-report__block">
                            <strong><?php echo esc_html($label); ?></strong>
                            <ul>
                                <?php foreach ($samples[$sample_key] as $sample): ?>
                                    <li><?php echo esc_html($sample); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_channel_placeholder($title, $headline, $description)
    {
        ?>
        <div class="d14k-card d14k-card--channel-placeholder">
            <h2><?php echo esc_html($title); ?></h2>
            <div class="d14k-empty-state d14k-empty-state--channel">
                <div class="d14k-empty-state__hero">
                    <span class="d14k-empty-state__eyebrow">Channel</span>
                    <span class="d14k-badge d14k-badge--accent">Планується</span>
                </div>
                <div class="d14k-empty-state__layout">
                    <div class="d14k-empty-state__copy">
                        <strong><?php echo esc_html($headline); ?></strong>
                        <p><?php echo esc_html($description); ?></p>
                    </div>
                    <div class="d14k-empty-state__points">
                        <span>Окремі налаштування</span>
                        <span>Статуси запусків</span>
                        <span>Ручні дії</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function apply_category_presets($settings, $wc_cats)
    {
        if (empty($wc_cats) || is_wp_error($wc_cats)) {
            return $settings;
        }

        $default_google_category = 'Hardware > Power & Electrical Supplies > Electrical Wires & Cable';
        $network_google_category = 'Electronics > Electronics Accessories > Cables > Network Cables';

        $presets = array(
            'АВВГ' => array(
                'google_category' => '',
                'labels' => array('power_cable', 'avvg', 'power', 'universal', 'secondary'),
            ),
            'ВВГ-П' => array(
                'google_category' => '',
                'labels' => array('power_cable', 'vvg_p', 'power', 'indoor', 'core'),
            ),
            'Вита пара внутрішня' => array(
                'google_category' => $network_google_category,
                'labels' => array('network_cable', 'twisted_pair_indoor', 'network', 'indoor', 'secondary'),
            ),
            'Вита пара зовнішня' => array(
                'google_category' => $network_google_category,
                'labels' => array('network_cable', 'twisted_pair_outdoor', 'network', 'outdoor', 'secondary'),
            ),
            'КГ' => array(
                'google_category' => '',
                'labels' => array('power_cable', 'kg', 'flexible_power', 'universal', 'core'),
            ),
            'ПВ-5' => array(
                'google_category' => '',
                'labels' => array('wire', 'pv_5', 'installation_wire', 'indoor', 'secondary'),
            ),
            'ПВ3' => array(
                'google_category' => '',
                'labels' => array('wire', 'pv3', 'installation_wire', 'indoor', 'secondary'),
            ),
            'ПВС' => array(
                'google_category' => '',
                'labels' => array('power_cable', 'pvs', 'flexible_power', 'universal', 'core'),
            ),
            'СІП' => array(
                'google_category' => '',
                'labels' => array('power_cable', 'sip', 'overhead_power', 'outdoor', 'secondary'),
            ),
            'ШВВП' => array(
                'google_category' => '',
                'labels' => array('power_cable', 'shvvp', 'flexible_power', 'indoor', 'core'),
            ),
            'ШВП-2' => array(
                'google_category' => '',
                'labels' => array('power_cable', 'shvp_2', 'flexible_power', 'indoor', 'secondary'),
            ),
        );

        if (empty($settings['default_google_category'])) {
            $settings['default_google_category'] = $default_google_category;
        }

        foreach ($wc_cats as $cat) {
            $cat_name = trim(wp_specialchars_decode($cat->name, ENT_QUOTES));
            if (!isset($presets[$cat_name])) {
                continue;
            }

            $preset = $presets[$cat_name];
            $term_id = (int) $cat->term_id;

            if (!empty($preset['google_category']) && (empty($settings['category_map'][$term_id]) || !isset($settings['category_map'][$term_id]))) {
                $settings['category_map'][$term_id] = $preset['google_category'];
            }

            foreach ($preset['labels'] as $index => $label_value) {
                $map_key = 'category_label_' . $index . '_map';
                if (!isset($settings[$map_key]) || !is_array($settings[$map_key])) {
                    $settings[$map_key] = array();
                }
                if (!isset($settings[$map_key][$term_id]) || $settings[$map_key][$term_id] === '') {
                    $settings[$map_key][$term_id] = $label_value;
                }
            }
        }

        return $settings;
    }
}
