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

    public function __construct($generator, $yml_generator, $csv_generator, $wpml, $cron)
    {
        $this->generator = $generator;
        $this->yml_generator = $yml_generator;
        $this->csv_generator = $csv_generator;
        $this->wpml = $wpml;
        $this->cron = $cron;

        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_link'), 100);
        add_action('admin_post_d14k_save_settings', array($this, 'save_settings'));
        add_action('admin_post_d14k_generate_now', array($this, 'handle_generate_now'));
        add_action('admin_post_d14k_test_validation', array($this, 'handle_test_validation'));

        // AJAX handlers for channel toggles and per-channel generation
        add_action('wp_ajax_d14k_toggle_channel', array($this, 'ajax_toggle_channel'));
        add_action('wp_ajax_d14k_generate_channel', array($this, 'ajax_generate_channel'));
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
        $languages = $this->wpml->get_active_languages();
        $intervals = $this->cron->get_interval_options();
        $next_run = $this->cron->get_next_run();
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
        $wc_attributes = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : array();
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
            <h1 style="display:none;"></h1> <!-- Catch WP admin notices here -->

            <!-- Plugin Header -->
            <div class="d14k-header">
                <div class="d14k-header-icon">
                    <img src="<?php echo esc_url(D14K_FEED_URL . 'assets/logo.png?v=3'); ?>" alt="MIL SPIL Feed Generator"
                        style="width:48px;height:48px;object-fit:contain;border-radius:8px;" />
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
            $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'feeds';
            $tabs = array(
                'feeds' => array('label' => 'Фіди', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 17h7M17.5 14v7"/></svg>'),
                'settings' => array('label' => 'Налаштування', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'),
                'mapping' => array('label' => 'Маппінг', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>'),
                'filters' => array('label' => 'Фільтри', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>'),
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

            <!-- TAB: Фіди -->
            <div class="d14k-tab-content<?php echo $current_tab === 'feeds' ? ' d14k-tab-content--active' : ''; ?>"
                data-tab="feeds">
                <div class="d14k-card">
                    <h2>Фіди</h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Мова</th>
                                <th>URL фіду</th>
                                <th>Останнє оновлення</th>
                                <th>Товарів</th>
                                <th>Помилки</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($languages as $lang):
                                $feed_url = $this->generator->get_feed_url($lang);
                                $last_gen = get_option("d14k_feed_last_generated_{$lang}", '—');
                                $last_error = get_option("d14k_feed_last_error_{$lang}", '');
                                $last_stats = $this->generator->get_last_stats($lang);
                                $count = $last_stats ? $last_stats['valid'] : '—';
                                $skipped = $last_stats ? $last_stats['skipped'] : 0;
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html(strtoupper($lang)); ?></strong></td>
                                    <td>
                                        <code id="feed-url-<?php echo esc_attr($lang); ?>"><?php echo esc_url($feed_url); ?></code>
                                        <button type="button" class="button button-small d14k-copy"
                                            data-target="feed-url-<?php echo esc_attr($lang); ?>">Копіювати</button>
                                    </td>
                                    <td><?php echo esc_html($last_gen); ?></td>
                                    <td><?php echo esc_html($count); ?><?php if ($skipped): ?> <small>(пропущено:
                                                <?php echo (int) $skipped; ?>)</small><?php endif; ?></td>
                                    <td><?php echo $last_error ? '<span class="d14k-error">' . esc_html($last_error) . '</span>' : '&#10003;'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 10px;">
                        <?php wp_nonce_field('d14k_generate_now'); ?>
                        <input type="hidden" name="action" value="d14k_generate_now">
                        <button type="submit" class="button button-primary">Згенерувати зараз</button>
                        <?php if ($next_run): ?>
                            <span class="d14k-next-run">Наступна автогенерація: <?php echo esc_html($next_run); ?></span>
                        <?php endif; ?>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                        style="margin-top: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px;">
                        <?php wp_nonce_field('d14k_test_validation'); ?>
                        <input type="hidden" name="action" value="d14k_test_validation">
                        <button type="submit" class="button">🔍 Тестова перевірка (10 товарів)</button>
                        <span class="description" style="margin: 0;">Перевірте фід на наявність всіх обов'язкових полів перед
                            відправкою до Google Merchant Center</span>
                    </form>

                    <!-- Disclaimer Box was here, moved out -->
                </div>

                <!-- Marketplace Feed Channels -->
                <div class="d14k-card" style="margin-top:0;">
                    <h2>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            style="vertical-align:-2px;margin-right:4px;">
                            <path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7" />
                            <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8" />
                            <path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4" />
                            <path d="M2 7h20" />
                            <path
                                d="M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7" />
                        </svg>
                        Маркетплейси
                        <?php
                        $webp_enabled = !empty($settings['webp_to_jpg']);
                        if ($webp_enabled): ?>
                            <span class="d14k-badge d14k-badge--on">
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12" />
                                </svg>
                                WebP → JPG
                            </span>
                        <?php else: ?>
                            <span class="d14k-badge d14k-badge--off">WebP → JPG: вимкнено</span>
                        <?php endif; ?>
                    </h2>

                    <?php
                    $yml_channels = isset($settings['yml_channels']) ? $settings['yml_channels'] : array();
                    $horoshop_enabled = !empty($yml_channels['horoshop']);
                    ?>

                    <!-- Horoshop Channel -->
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">Статус</th>
                                <th>Канал</th>
                                <th>Файл / URL</th>
                                <th>Останнє оновлення</th>
                                <th>Товарів</th>
                                <th style="width:120px;">Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <label class="d14k-toggle">
                                        <input type="checkbox" class="d14k-channel-toggle" data-channel="horoshop" <?php checked($horoshop_enabled); ?>>
                                        <span class="d14k-toggle-slider"></span>
                                    </label>
                                </td>
                                <td>
                                    <strong>Horoshop</strong><br>
                                    <small style="color:var(--d14k-muted);">CSV імпорт для Horoshop</small>
                                </td>
                                <td>
                                    <?php if ($horoshop_enabled):
                                        $horoshop_url = $this->csv_generator->get_feed_url('horoshop');
                                        ?>
                                        <a href="<?php echo esc_url($horoshop_url); ?>" class="button button-primary button-small"
                                            download style="vertical-align:middle;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round" style="vertical-align:-2px;margin-right:3px;">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                <polyline points="7 10 12 15 17 10" />
                                                <line x1="12" y1="15" x2="12" y2="3" />
                                            </svg>
                                            Завантажити CSV
                                        </a>
                                        <br>
                                        <small class="d14k-url-toggle"
                                            onclick="var el=document.getElementById('yml-url-horoshop');el.style.display=el.style.display==='none'?'block':'none';">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round" style="vertical-align:-1px;margin-right:2px;">
                                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                            </svg>
                                            URL для автоімпорту
                                        </small>
                                        <code id="yml-url-horoshop"
                                            style="display:none;font-size:11px;margin-top:4px;word-break:break-all;"><?php echo esc_url($horoshop_url); ?></code>
                                    <?php else: ?>
                                        <span style="color:var(--d14k-text-muted);">Канал вимкнено</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($horoshop_enabled):
                                        $ch_gen_time = $this->csv_generator->get_last_generated('horoshop');
                                        echo esc_html($ch_gen_time ? $ch_gen_time : '—');
                                    else: ?>
                                        <span style="color:var(--d14k-text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($horoshop_enabled):
                                        $ch_stats = $this->csv_generator->get_last_stats('horoshop');
                                        $ch_count = $ch_stats ? $ch_stats['valid'] : '—';
                                        echo esc_html($ch_count);
                                    else: ?>
                                        <span style="color:var(--d14k-text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small d14k-generate-channel"
                                        data-channel="horoshop" <?php disabled(!$horoshop_enabled); ?>>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" style="vertical-align:-2px;margin-right:2px;">
                                            <path
                                                d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2" />
                                        </svg>
                                        Згенерувати
                                    </button>
                                </td>
                            </tr>
                            <?php if ($horoshop_enabled && !$webp_enabled):
                                $settings_url = admin_url('admin.php?page=d14k-merchant-feed&tab=settings');
                                ?>
                                <tr class="d14k-channel-notice-row">
                                    <td colspan="6" style="background:transparent;">
                                        <div class="d14k-notice-inline d14k-notice-warning">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;">
                                                <path
                                                    d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                                <line x1="12" y1="9" x2="12" y2="13" />
                                                <line x1="12" y1="17" x2="12.01" y2="17" />
                                            </svg>
                                            Horoshop не підтримує WebP зображення. <a
                                                href="<?php echo esc_url($settings_url); ?>">Увімкніть конвертацію WebP → JPG</a> у
                                            налаштуваннях.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Horoshop Category Warning -->
                    <?php if ($horoshop_enabled): ?>
                        <div class="d14k-notice-box d14k-notice-box--warning" style="margin:12px 16px;">
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
                                <p style="margin:4px 0 0;">Horoshop не створює категорії автоматично при імпорті CSV. Перед першим
                                    імпортом створіть потрібні категорії та підкатегорії в адмінці Horoshop (<em>Товари →
                                        Категорії</em>), а також призначте їм товарний шаблон. Назви повинні точно збігатися з тими,
                                    що у CSV.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Coming Soon: Prom & Rozetka -->
                    <div class="d14k-coming-soon-section">
                        <div class="d14k-coming-soon-header">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                style="opacity:.5;">
                                <path d="M14.5 4h-5L7 7H2v13h20V7h-5l-2.5-3Z" />
                                <circle cx="12" cy="14" r="4" />
                            </svg>
                            <span>Незабаром</span>
                        </div>
                        <div class="d14k-coming-soon-items">
                            <div class="d14k-coming-soon-item">
                                <span class="d14k-coming-soon-name">Prom.ua</span>
                                <span class="d14k-badge d14k-badge--coming-soon">Тестування</span>
                            </div>
                            <div class="d14k-coming-soon-item">
                                <span class="d14k-coming-soon-name">Rozetka</span>
                                <span class="d14k-badge d14k-badge--coming-soon">За розкладом</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Status -->
                <div class="d14k-card" style="margin-top:0;">
                    <h2>⚙️ Статус системи</h2>
                    <?php
                    $mem_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
                    $mem_ok = $mem_limit >= 128 * 1024 * 1024; // 128MB min
                    $mem_rec = $mem_limit >= 256 * 1024 * 1024; // 256MB recommended
                    $gd_loaded = extension_loaded('gd');
                    $gd_webp = $gd_loaded && function_exists('imagecreatefromwebp');
                    $exec_time = (int) ini_get('max_execution_time');
                    $exec_ok = $exec_time === 0 || $exec_time >= 120;
                    $upload_dir = wp_upload_dir();
                    $cache_dir = trailingslashit($upload_dir['basedir']) . 'd14k-feeds/images/';
                    $dir_writable = wp_is_writable($upload_dir['basedir']);
                    $php_ok = version_compare(PHP_VERSION, '7.4', '>=');
                    ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width:40px;"></th>
                                <th>Параметр</th>
                                <th>Значення</th>
                                <th>Рекомендовано</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo $php_ok ? '🟢' : '🔴'; ?></td>
                                <td><strong>PHP версія</strong></td>
                                <td><?php echo esc_html(PHP_VERSION); ?></td>
                                <td>≥ 7.4</td>
                            </tr>
                            <tr>
                                <td><?php echo $mem_rec ? '🟢' : ($mem_ok ? '🟡' : '🔴'); ?></td>
                                <td>
                                    <strong>Пам'ять (memory_limit)</strong><br>
                                    <small style="color:#666;">Потрібна для конвертації WebP → JPG</small>
                                </td>
                                <td><?php echo esc_html(ini_get('memory_limit')); ?></td>
                                <td>≥ 256M (мін. 128M)</td>
                            </tr>
                            <tr>
                                <td><?php echo $gd_loaded ? '🟢' : '🔴'; ?></td>
                                <td><strong>PHP GD</strong></td>
                                <td><?php echo $gd_loaded ? 'Увімкнено' : 'Вимкнено'; ?></td>
                                <td>Обов'язково для конвертації</td>
                            </tr>
                            <tr>
                                <td><?php echo $gd_webp ? '🟢' : '🔴'; ?></td>
                                <td><strong>GD WebP підтримка</strong></td>
                                <td><?php echo $gd_webp ? 'Увімкнено' : 'Вимкнено'; ?></td>
                                <td>Обов'язково для WebP → JPG</td>
                            </tr>
                            <tr>
                                <td><?php echo $exec_ok ? '🟢' : '🟡'; ?></td>
                                <td>
                                    <strong>Час виконання (max_execution_time)</strong><br>
                                    <small style="color:#666;">Впливає на генерацію великих фідів</small>
                                </td>
                                <td><?php echo $exec_time === 0 ? 'Необмежено' : esc_html($exec_time) . 's'; ?></td>
                                <td>≥ 120s або без обмежень</td>
                            </tr>
                            <tr>
                                <td><?php echo $dir_writable ? '🟢' : '🔴'; ?></td>
                                <td>
                                    <strong>Запис в uploads</strong><br>
                                    <small style="color:#666;">Для кешу зображень та XML файлів</small>
                                </td>
                                <td><?php echo $dir_writable ? 'Доступно' : 'Заблоковано'; ?></td>
                                <td>Обов'язково</td>
                            </tr>
                        </tbody>
                    </table>
                    <?php if (!$gd_webp && $webp_enabled): ?>
                        <div class="d14k-notice-inline d14k-notice-warning" style="margin-top:12px;">
                            ⚠️ Конвертація WebP → JPG увімкнена, але GD з підтримкою WebP відсутня. Зверніться до хостингу для
                            встановлення <code>libwebp</code>.
                        </div>
                    <?php endif; ?>
                    <?php if (!$mem_ok): ?>
                        <div class="d14k-notice-inline d14k-notice-warning" style="margin-top:12px;">
                            ⚠️ Пам'ять менше 128M — конвертація зображень може завершитися помилкою. Збільшіть
                            <code>memory_limit</code> у <code>php.ini</code> або <code>wp-config.php</code>.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Disclaimer Box -->
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
            </div><!-- /.d14k-tab-content[feeds] -->

            <!-- TAB: Налаштування -->
            <div class="d14k-tab-content<?php echo $current_tab === 'settings' ? ' d14k-tab-content--active' : ''; ?>"
                data-tab="settings">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('d14k_save_settings'); ?>
                    <input type="hidden" name="action" value="d14k_save_settings">
                    <input type="hidden" name="d14k_tab" value="settings">

                    <div class="d14k-card">
                        <h2>Налаштування</h2>
                        <table class="form-table">
                            <tr>
                                <th>Увімкнути фід</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                                        Автоматична генерація за розкладом
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Час генерації</th>
                                <td>
                                    <?php
                                    $cron_time = isset($settings['cron_time']) ? $settings['cron_time'] : '06:00';
                                    $cron_tz = isset($settings['cron_timezone']) ? $settings['cron_timezone'] : 'Europe/Kiev';
                                    $hours = array('00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23');
                                    $mins = array('00', '15', '30', '45');
                                    list($sel_h, $sel_m) = explode(':', $cron_time . ':00');
                                    ?>
                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                        <select name="cron_hour" id="d14k-cron-hour" style="width:80px;">
                                            <?php foreach ($hours as $h): ?>
                                                <option value="<?php echo esc_attr($h); ?>" <?php selected($sel_h, $h); ?>>
                                                    <?php echo esc_html($h); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span>:</span>
                                        <select name="cron_minute" id="d14k-cron-minute" style="width:80px;">
                                            <?php foreach ($mins as $m): ?>
                                                <option value="<?php echo esc_attr($m); ?>" <?php selected($sel_m, $m); ?>>
                                                    <?php echo esc_html($m); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="cron_timezone" id="d14k-cron-timezone" style="min-width:220px;">
                                            <?php
                                            $tz_list = array(
                                                'Kyiv / Київ' => 'Europe/Kiev',
                                                'Warsaw / Варшава' => 'Europe/Warsaw',
                                                'London / Лондон' => 'Europe/London',
                                                'Berlin / Берлін' => 'Europe/Berlin',
                                                'Paris / Париж' => 'Europe/Paris',
                                                'Moscow / Москва' => 'Europe/Moscow',
                                                'Istanbul / Стамбул' => 'Europe/Istanbul',
                                                'Dubai / Дубай' => 'Asia/Dubai',
                                                'UTC' => 'UTC',
                                                'New York / Нью-Йорк' => 'America/New_York',
                                                'Los Angeles / Лос-Анджелес' => 'America/Los_Angeles',
                                            );
                                            foreach ($tz_list as $tz_label => $tz_val): ?>
                                                <option value="<?php echo esc_attr($tz_val); ?>" <?php selected($cron_tz, $tz_val); ?>><?php echo esc_html($tz_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <p class="description" style="margin-top:6px;">Фід генерується щодня о вказаний час.
                                        Мінімальна частота GMC — 1 раз на добу.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Бренд</th>
                                <td>
                                    <?php
                                    $brand_mode = isset($settings['brand_mode']) ? $settings['brand_mode'] : 'custom';
                                    $brand_attribute = isset($settings['brand_attribute']) ? $settings['brand_attribute'] : '';
                                    ?>
                                    <fieldset style="border:none;padding:0;margin:0;">
                                        <label style="display:block;margin-bottom:8px;">
                                            <input type="radio" name="brand_mode" value="custom" class="d14k-brand-mode" <?php checked($brand_mode, 'custom'); ?>>
                                            <strong>Власний бренд</strong> — ввести назву вручну
                                        </label>
                                        <div id="d14k-brand-custom-wrap"
                                            style="margin:0 0 12px 22px;<?php echo $brand_mode !== 'custom' ? 'display:none;' : ''; ?>">
                                            <input type="text" name="brand"
                                                value="<?php echo esc_attr(isset($settings['brand']) ? $settings['brand'] : ''); ?>"
                                                class="regular-text" placeholder="Назва вашого бренду">
                                        </div>
                                        <label style="display:block;margin-bottom:8px;">
                                            <input type="radio" name="brand_mode" value="attribute" class="d14k-brand-mode"
                                                <?php checked($brand_mode, 'attribute'); ?>>
                                            <strong>З атрибуту товару</strong> — брати значення з атрибуту WooCommerce
                                        </label>
                                        <div id="d14k-brand-attr-wrap"
                                            style="margin:0 0 12px 22px;<?php echo $brand_mode !== 'attribute' ? 'display:none;' : ''; ?>">
                                            <select name="brand_attribute" style="min-width:240px;">
                                                <option value="">— оберіть атрибут —</option>
                                                <?php foreach ($wc_attributes as $attr):
                                                    $tax = wc_attribute_taxonomy_name($attr->attribute_name);
                                                    ?>
                                                    <option value="<?php echo esc_attr($tax); ?>" <?php selected($brand_attribute, $tax); ?>>
                                                        <?php echo esc_html($attr->attribute_label); ?>
                                                        (<?php echo esc_html($tax); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description" style="margin:8px 0 4px;">Якщо атрибут відсутній у товарі
                                                —
                                                використовується запасний бренд:</p>
                                            <input type="text" name="brand_fallback"
                                                value="<?php echo esc_attr(isset($settings['brand_fallback']) ? $settings['brand_fallback'] : ''); ?>"
                                                class="regular-text" placeholder="Запасний бренд (якщо атрибут відсутній)">
                                        </div>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th>Зображення<br><small>(WebP → JPG)</small></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="webp_to_jpg" value="1" <?php checked(!empty($settings['webp_to_jpg'])); ?>>
                                        <strong>Конвертувати WebP → JPG</strong> для маркетплейс-фідів
                                    </label>
                                    <p class="description">
                                        Якщо зображення завантажені у WebP, плагін автоматично створить JPG-копії
                                        для Horoshop, Prom.ua та Rozetka (ці платформи не підтримують WebP).
                                        JPG-копії кешуються у <code>wp-content/uploads/d14k-feeds/images/</code>.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Країна походження<br><small>(Country of Origin)</small></th>
                                <td>
                                    <select name="country_of_origin" class="regular-text">
                                        <?php
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
                                        foreach ($countries as $code => $name):
                                            ?>
                                            <option value="<?php echo esc_attr($code); ?>" <?php selected($selected_country, $code); ?>><?php echo esc_html($name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Якщо вибрано «не вказувати» — тег <code>country_of_origin</code>
                                        не
                                        додається у фід.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Google Product Category<br><small>(за замовчуванням)</small></th>
                                <td><input type="text" name="default_google_category"
                                        value="<?php echo esc_attr(isset($settings['default_google_category']) ? $settings['default_google_category'] : ''); ?>"
                                        class="large-text" placeholder="Apparel &amp; Accessories &gt; Jewelry"></td>
                            </tr>
                        </table>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Зберегти налаштування</button>
                    </p>
                </form>
            </div><!-- /.d14k-tab-content[settings] -->

            <!-- TAB: Маппінг -->
            <div class="d14k-tab-content<?php echo $current_tab === 'mapping' ? ' d14k-tab-content--active' : ''; ?>"
                data-tab="mapping">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('d14k_save_settings'); ?>
                    <input type="hidden" name="action" value="d14k_save_settings">
                    <input type="hidden" name="d14k_tab" value="mapping">

                    <div class="d14k-card">
                        <h2>Маппінг категорій Google</h2>
                        <p>Призначте Google Product Category для кожної категорії WooCommerce. Порожнє = використовується
                            категорія
                            за замовчуванням.</p>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>Категорія WooCommerce</th>
                                    <th>Google Product Category</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $cat_map = isset($settings['category_map']) ? $settings['category_map'] : array();
                                if ($wc_cats && !is_wp_error($wc_cats)):
                                    foreach ($wc_cats as $cat):
                                        if (!$cat->count) {
                                            continue;
                                        } // skip empty categories in mapping
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html($cat->name); ?> <small>(<?php echo (int) $cat->count; ?>
                                                    товарів)</small></td>
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

                    <div class="d14k-card" style="margin-top:0;">
                        <h2>Custom Labels (0–4)</h2>
                        <p>Призначте Custom Labels для сегментації в Google Ads. Маппінг по категоріях WooCommerce — пусте =
                            не
                            використовується. Для перевизначення на рівні товару — використовуйте мета-поля в картці товару.
                        </p>
                        <?php if ($wc_cats && !is_wp_error($wc_cats)): ?>
                            <div style="overflow-x:auto;">
                                <table class="widefat striped" style="min-width:700px;">
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
                                                <td><?php echo esc_html($cat->name); ?>
                                                    <small>(<?php echo (int) $cat->count; ?>)</small>
                                                </td>
                                                <?php for ($cli = 0; $cli <= 4; $cli++): ?>
                                                    <td>
                                                        <input type="text"
                                                            name="category_label_<?php echo $cli; ?>_map[<?php echo (int) $cat->term_id; ?>]"
                                                            value="<?php echo esc_attr(isset($cl_maps[$cli][$cat->term_id]) ? $cl_maps[$cli][$cat->term_id] : ''); ?>"
                                                            style="width:100%;min-width:100px;" placeholder="—">
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

                    <p class="submit">
                        <button type="submit" class="button button-primary">Зберегти маппінг</button>
                    </p>
                </form>
            </div><!-- /.d14k-tab-content[mapping] -->

            <!-- TAB: Фільтри -->
            <div class="d14k-tab-content<?php echo $current_tab === 'filters' ? ' d14k-tab-content--active' : ''; ?>"
                data-tab="filters">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('d14k_save_settings'); ?>
                    <input type="hidden" name="action" value="d14k_save_settings">
                    <input type="hidden" name="d14k_tab" value="filters">

                    <div class="d14k-card" style="margin-top:0;">
                        <h2>Виключені категорії</h2>
                        <p>Позначені категорії <strong>не потраплять у фід</strong> (для всіх мов одночасно). При позначенні
                            батьківської — всі підкатегорії виключаються автоматично, але можна зняти окремі.</p>
                        <?php if (empty($cat_by_id)): ?>
                            <p class="description">Категорії не знайдені.</p>
                        <?php else: ?>
                            <?php echo $this->render_cat_accordion($cat_children, $cat_by_id, $excluded_cat_ids, $wpml_trans_map); ?>
                        <?php endif; ?>
                    </div>

                    <div class="d14k-card" style="margin-top:0;">
                        <h2>Фільтрація за атрибутами</h2>
                        <p>Активуйте фільтр для атрибута та вкажіть значення. Якщо фільтр не увімкнено — всі значення
                            потрапляють у
                            фід.</p>
                        <?php if (empty($attr_terms_data)): ?>
                            <p class="description">Атрибути товарів не знайдені.</p>
                        <?php else: ?>
                            <?php foreach ($attr_terms_data as $taxonomy => $attr_data):
                                $af = isset($settings['attribute_filters'][$taxonomy]) ? $settings['attribute_filters'][$taxonomy] : array();
                                $af_enabled = !empty($af['enabled']);
                                $af_mode = isset($af['mode']) ? $af['mode'] : 'exclude';
                                $af_values = isset($af['values']) ? (array) $af['values'] : array();
                                ?>
                                <div class="d14k-attr-filter-block"
                                    style="border:1px solid #ddd;border-radius:4px;padding:15px;margin-bottom:15px;">
                                    <h4 style="margin:0 0 10px;">
                                        <label>
                                            <input type="checkbox" name="attr_filter_enabled[<?php echo esc_attr($taxonomy); ?>]"
                                                value="1" class="d14k-attr-enable" <?php checked($af_enabled); ?>>
                                            <strong><?php echo esc_html($attr_data['label']); ?></strong>
                                            <small
                                                style="color:#888;font-weight:normal;">&nbsp;(<?php echo esc_html($taxonomy); ?>)</small>
                                        </label>
                                    </h4>
                                    <div class="d14k-attr-filter-body" <?php echo $af_enabled ? '' : ' style="display:none;"'; ?>>
                                        <p style="margin:0 0 10px;">
                                            <label style="margin-right:20px;">
                                                <input type="radio" name="attr_filter_mode[<?php echo esc_attr($taxonomy); ?>]"
                                                    value="exclude" <?php checked($af_mode, 'exclude'); ?>>
                                                Виключити товари з цими значеннями
                                            </label>
                                            <label>
                                                <input type="radio" name="attr_filter_mode[<?php echo esc_attr($taxonomy); ?>]"
                                                    value="include" <?php checked($af_mode, 'include'); ?>>
                                                Включити тільки товари з цими значеннями
                                            </label>
                                        </p>
                                        <div style="columns:3;column-gap:20px;">
                                            <?php foreach ($attr_data['terms'] as $term): ?>
                                                <label style="display:block;margin-bottom:5px;">
                                                    <input type="checkbox" name="attr_filter_values[<?php echo esc_attr($taxonomy); ?>][]"
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

                    <div class="d14k-card" style="margin-top:0;">
                        <h2>Розширені правила фільтрації</h2>
                        <p>Додайте правила для тонкого керування фідом. <strong>Виключити</strong> — товар прибирається з
                            фіду,
                            якщо
                            умова виконується. <strong>Включити тільки</strong> — товар потрапляє у фід лише якщо умова
                            виконується
                            (whitelist).</p>
                        <table class="widefat" id="d14k-rules-table" style="table-layout:auto;">
                            <thead>
                                <tr>
                                    <th>Поле</th>
                                    <th>Ключ (meta)</th>
                                    <th>Умова</th>
                                    <th>Значення</th>
                                    <th>Дія</th>
                                    <th style="width:60px;"></th>
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
                                            <select name="custom_rules[<?php echo (int) $ridx; ?>][field]" class="d14k-rule-field"
                                                style="width:auto;">
                                                <?php foreach ($rule_fields as $fk => $fd): ?>
                                                    <option value="<?php echo esc_attr($fk); ?>" <?php selected($r_field, $fk); ?>>
                                                        <?php echo esc_html($fd['label']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="custom_rules[<?php echo (int) $ridx; ?>][meta_key]"
                                                class="d14k-rule-meta-key" value="<?php echo esc_attr($r_mk); ?>"
                                                placeholder="напр. _weight"
                                                style="width:110px;<?php echo $r_ftype !== 'meta' ? 'display:none;' : ''; ?>">
                                        </td>
                                        <td>
                                            <select name="custom_rules[<?php echo (int) $ridx; ?>][condition]"
                                                class="d14k-rule-condition" style="width:auto;">
                                                <?php foreach ($r_conds as $ck => $cl): ?>
                                                    <option value="<?php echo esc_attr($ck); ?>" <?php selected($r_cond, $ck); ?>>
                                                        <?php echo esc_html($cl); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <?php if ($r_ftype === 'status'): ?>
                                                <select name="custom_rules[<?php echo (int) $ridx; ?>][value]" class="d14k-rule-value"
                                                    style="width:auto;<?php echo $r_no_val ? 'display:none;' : ''; ?>">
                                                    <option value="instock" <?php selected($r_val, 'instock'); ?>>В наявності
                                                    </option>
                                                    <option value="outofstock" <?php selected($r_val, 'outofstock'); ?>>Немає в
                                                        наявності
                                                    </option>
                                                    <option value="onbackorder" <?php selected($r_val, 'onbackorder'); ?>>Під
                                                        замовлення
                                                    </option>
                                                </select>
                                            <?php else: ?>
                                                <input type="<?php echo $r_ftype === 'number' ? 'number' : 'text'; ?>"
                                                    name="custom_rules[<?php echo (int) $ridx; ?>][value]" class="d14k-rule-value"
                                                    value="<?php echo esc_attr($r_val); ?>" placeholder="Значення"
                                                    style="width:130px;<?php echo $r_no_val ? 'display:none;' : ''; ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <select name="custom_rules[<?php echo (int) $ridx; ?>][action]" style="width:auto;">
                                                <option value="exclude" <?php selected($r_action, 'exclude'); ?>>Виключити
                                                </option>
                                                <option value="include" <?php selected($r_action, 'include'); ?>>Включити тільки
                                                </option>
                                            </select>
                                        </td>
                                        <td><button type="button" class="button d14k-remove-rule">✕</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p style="margin-top:10px;">
                            <button type="button" class="button" id="d14k-add-rule">+ Додати правило</button>
                        </p>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Зберегти фільтри</button>
                    </p>
                </form>
            </div><!-- /.d14k-tab-content[filters] -->

            <!-- TAB: Довідка -->
            <div class="d14k-tab-content<?php echo $current_tab === 'help' ? ' d14k-tab-content--active' : ''; ?>"
                data-tab="help">
                <div class="d14k-card">
                    <div class="d14k-help-content">
                        <div class="d14k-help-hero">
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

        <script>
            document.querySelectorAll('.d14k-copy').forE        ach(function (b              tn) {
                btn.addEventListener('click', function () {
                    var el = document.getElementById(this.dataset.target);
                    navigator.clipboard.writeText(el.textContent).then(function () {
                        btn.textContent = 'Скопійовано!';
                        setTimeout(function () { btn.textContent = 'Копіювати'; }, 1500);
                    });
                });
            });

            // ---- Brand mode toggle ----
            document.querySelectorAll('.d14k-brand-mode').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    document.getElementById('d14k-brand-custom-wrap').style.display = this.value === 'custom' ? '' : 'none';
                    document.getElementById('d14k-brand-attr-wrap').style.display = this.value === 'attribute' ? '' : 'none';
                });
            });

            // ---- Category exclusion cascade ----
            var d14kCatCbs = Array.from(document.querySelectorAll('.d14k-cat-cb'));
            function d14kGetCatDescendants(parentId) {
                var result = [];
                d14kCatCbs.forEach(function (cb) {
                    if (parseInt(cb.dataset.parent, 10) === parentId) {
                        result.push(cb);
                        d14kGetCatDescendants(parseInt(cb.dataset.id, 10)).forEach(function (d) { result.push(d); });
                    }
                });
                return result;
            }
            d14kCatCbs.forEach(function (cb) {
                cb.addEventListener('change', function () {
                    d14kGetCatDescendants(parseInt(this.dataset.id, 10)).forEach(function (child) {
                        child.checked = cb.checked;
                    });
                });
            });

            document.querySelectorAll('.d14k-attr-enable').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var body = this.closest('.d14k-attr-filter-block').querySelector('.d14k-attr-filter-body');
                    body.style.display = this.checked ? '' : 'none';
                });
            });

            // ---- Розширені правила фільтрації ----
            var d14kRuleFieldsData = <?php
            $jfields = array();
            foreach ($rule_fields as $k => $v) {
                $jfields[] = array('value' => $k, 'label' => $v['label'], 'type' => $v['type']);
            }
            echo json_encode($jfields);
            ?>;
            var d14kCondsByType = <?php echo json_encode($rule_conditions); ?>;
            var d14kStatusVals = [{ v: 'instock', l: 'В наявності' }, { v: 'outofstock', l: 'Немає в наявності' }, { v: 'onbackorder', l: 'Під замовлення' }];
            var d14kNoValConds = ['is_empty', 'not_empty'];
            var d14kRuleIdx = <?php echo count($custom_rules); ?>;

            function d14kEsc(s) {
                return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            function d14kFieldType(fval) {
                var f = d14kRuleFieldsData.find(function (x) { return x.value === fval; });
                return f ? f.type : 'text';
            }
            function d14kBuildCondSelect(idx, ftype, selCond) {
                var conds = d14kCondsByType[ftype] || d14kCondsByType['text'];
                var h = '<select name="custom_rules[' + idx + '][condition]" class="d14k-rule-condition" style="width:auto;">';
                Object.keys(conds).forEach(function (k) { h += '<option value="' + k + '"' + (k === selCond ? ' selected' : '') + '>' + conds[k] + '</option>'; });
                return h + '</select>';
            }
            function d14kBuildValInput(idx, ftype, cond, val) {
                var hide = d14kNoValConds.indexOf(cond) !== -1 ? 'display:none;' : '';
                if (ftype === 'status') {
                    var h = '<select name="custom_rules[' + idx + '][value]" class="d14k-rule-value" style="width:auto;' + hide + '">';
                    d14kStatusVals.forEach(function (s) { h += '<option value="' + s.v + '"' + (s.v === val ? ' selected' : '') + '>' + s.l + '</option>'; });
                    return h + '</select>';
                }
                var itype = ftype === 'number' ? 'number' : 'text';
                return '<input type="' + itype + '" name="custom_rules[' + idx + '][value]" class="d14k-rule-value" value="' + d14kEsc(val) + '" placeholder="Значення" style="width:130px;' + hide + '">';
            }
            function d14kCreateRow(idx, saved) {
                saved = saved || {};
                var field = saved.field || 'title';
                var mk = saved.meta_key || '';
                var cond = saved.condition || 'contains';
                var val = saved.value || '';
                var action = saved.action || 'exclude';
                var ftype = d14kFieldType(field);
                var fsHtml = '<select name="custom_rules[' + idx + '][field]" class="d14k-rule-field" style="width:auto;">';
                d14kRuleFieldsData.forEach(function (f) { fsHtml += '<option value="' + f.value + '"' + (f.value === field ? ' selected' : '') + '>' + f.label + '</option>'; });
                fsHtml += '</select>';
                var tr = document.createElement('tr');
                tr.className = 'd14k-rule-row';
                tr.innerHTML =
                    '<td>' + fsHtml + '</td>' +
                    '<td><input type="text" name="custom_rules[' + idx + '][meta_key]" class="d14k-rule-meta-key" value="' + d14kEsc(mk) + '" placeholder="напр. _weight" style="width:110px;' + (ftype !== 'meta' ? 'display:none;' : '') + '"></td>' +
                    '<td>' + d14kBuildCondSelect(idx, ftype, cond) + '</td>' +
                    '<td>' + d14kBuildValInput(idx, ftype, cond, val) + '</td>' +
                    '<td><select name="custom_rules[' + idx + '][action]" style="width:auto;"><option value="exclude"' + (action === 'exclude' ? ' selected' : '') + '>Виключити</option><option value="include"' + (action === 'include' ? ' selected' : '') + '>Включити тільки</option></select></td>' +
                    '<td><button type="button" class="button d14k-remove-rule">✕</button></td>';
                d14kInitRow(tr);
                return tr;
            }
            function d14kRebuildCondSelect(row, field, keepCond) {
                var ftype = d14kFieldType(field);
                var conds = d14kCondsByType[ftype] || d14kCondsByType['text'];
                var sel = row.querySelector('.d14k-rule-condition');
                var prev = keepCond || sel.value;
                sel.innerHTML = '';
                Object.keys(conds).forEach(function (k) {
                    var o = document.createElement('option');
                    o.value = k; o.textContent = conds[k];
                    if (k === prev) o.selected = true;
                    sel.appendChild(o);
                });
            }
            function d14kRebuildValInput(row, ftype, cond) {
                var valCell = row.querySelector('.d14k-rule-value').closest('td');
                var oldVal = row.querySelector('.d14k-rule-value').value || '';
                var m = row.querySelector('[name*="[field]"]').name.match(/\[(\d+)\]/);
                var idx = m ? m[1] : 0;
                valCell.innerHTML = d14kBuildValInput(idx, ftype, cond, oldVal);
            }
            function d14kInitRow(row) {
                var fs = row.querySelector('.d14k-rule-field');
                var cs = row.querySelector('.d14k-rule-condition');
                var mk = row.querySelector('.d14k-rule-meta-key');
                var rm = row.querySelector('.d14k-remove-rule');
                fs.addEventListener('change', function () {
                    var ftype = d14kFieldType(this.value);
                    if (mk) mk.style.display = ftype === 'meta' ? '' : 'none';
                    d14kRebuildCondSelect(row, this.value, null);
                    d14kRebuildValInput(row, ftype, cs.value);
                });
                cs.addEventListener('change', function () {
                    d14kRebuildValInput(row, d14kFieldType(fs.value), this.value);
                });
                rm.addEventListener('click', function () { row.parentNode.removeChild(row); });
            }
            document.querySelectorAll('.d14k-rule-row').forEach(function (r) { d14kInitRow(r); });
            document.getElementById('d14k-add-rule').addEventListener('click', function () {
                document.getElementById('d14k-rules-body').appendChild(d14kCreateRow(d14kRuleIdx++));
            });
        </script>
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

        // Expand excluded IDs to include all WPML translations automatically
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

        $settings = array(
            'enabled' => !empty($_POST['enabled']),
            'cron_time' => sanitize_text_field(preg_replace('/[^0-9:]/', '', (isset($_POST['cron_hour']) && isset($_POST['cron_minute'])) ? sprintf('%02d:%02d', absint($_POST['cron_hour']), absint($_POST['cron_minute'])) : '06:00')),
            'cron_timezone' => in_array(sanitize_text_field(isset($_POST['cron_timezone']) ? $_POST['cron_timezone'] : 'Europe/Kiev'), timezone_identifiers_list()) ? sanitize_text_field($_POST['cron_timezone']) : 'Europe/Kiev',
            'brand_mode' => isset($_POST['brand_mode']) && $_POST['brand_mode'] === 'attribute' ? 'attribute' : 'custom',
            'brand' => sanitize_text_field(isset($_POST['brand']) ? $_POST['brand'] : ''),
            'brand_attribute' => sanitize_key(isset($_POST['brand_attribute']) ? $_POST['brand_attribute'] : ''),
            'brand_fallback' => sanitize_text_field(isset($_POST['brand_fallback']) ? $_POST['brand_fallback'] : ''),
            'country_of_origin' => sanitize_text_field(isset($_POST['country_of_origin']) ? $_POST['country_of_origin'] : 'UA'),
            'webp_to_jpg' => !empty($_POST['webp_to_jpg']),
            'default_google_category' => sanitize_text_field(isset($_POST['default_google_category']) ? $_POST['default_google_category'] : ''),
            'category_map' => array_map('sanitize_text_field', isset($_POST['category_map']) ? $_POST['category_map'] : array()),
            'excluded_categories' => $excluded_cats,
            'attribute_filters' => $attribute_filters,
            'custom_rules' => $custom_rules,
        );

        // Custom Labels 0–4 category mapping
        for ($cli = 0; $cli <= 4; $cli++) {
            $key = 'category_label_' . $cli . '_map';
            $settings[$key] = array_map(
                'sanitize_text_field',
                isset($_POST[$key]) ? $_POST[$key] : array()
            );
        }

        // Marketplace YML channels (preserve existing if not posted from feeds tab)
        $old_settings = get_option('d14k_feed_settings', array());
        if (isset($_POST['yml_channels'])) {
            $yml_channels = array();
            foreach (D14K_YML_Generator::CHANNELS as $ch) {
                $yml_channels[$ch] = !empty($_POST['yml_channels'][$ch]);
            }
            $settings['yml_channels'] = $yml_channels;
        } elseif (isset($old_settings['yml_channels'])) {
            $settings['yml_channels'] = $old_settings['yml_channels'];
        }

        update_option('d14k_feed_settings', $settings);

        $old_time = isset($old_settings['cron_time']) ? $old_settings['cron_time'] : '';
        $old_tz = isset($old_settings['cron_timezone']) ? $old_settings['cron_timezone'] : '';
        if ($old_time !== $settings['cron_time'] || $old_tz !== $settings['cron_timezone']) {
            $this->cron->reschedule_at_time($settings['cron_time'], $settings['cron_timezone']);
        }

        $redirect_tab = isset($_POST['d14k_tab']) ? sanitize_key($_POST['d14k_tab']) : 'feeds';
        wp_redirect(admin_url('admin.php?page=d14k-merchant-feed&tab=' . $redirect_tab . '&d14k_notice=saved'));
        exit;
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
            <button type="button" class="d14k-cat-tbtn" onclick="d14kSelectAll()">Виділити всі</button>
            <button type="button" class="d14k-cat-tbtn" onclick="d14kClearAll()">Зняти всі</button>
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
                    <div class="d14k-cg-hdr" onclick="d14kToggleGroup(this)">
                        <span class="d14k-cg-arr">&#9658;</span>
                        <input type="checkbox" class="d14k-cat-cb" name="excluded_categories[]"
                            value="<?php echo (int) $parent_id; ?>" data-id="<?php echo (int) $parent_id; ?>"
                            onclick="event.stopPropagation();d14kParentChanged(this)" <?php checked($p_chk); ?>>
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
                                        <input type="checkbox" class="d14k-cat-cb" name="excluded_categories[]"
                                            value="<?php echo (int) $cid; ?>" data-id="<?php echo (int) $cid; ?>"
                                            data-parent="<?php echo (int) $parent_id; ?>" onchange="d14kChildChanged(this)" <?php checked($c_chk); ?>>
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
        <script>
            function d14kToggleGroup(h) { h.closest('.d14k-cg').classList.toggle('d14k-open'); }
            function d14kParentChanged(cb) {
                var g = cb.closest('.d14k-cg'), kids = g.querySelectorAll('.d14k-cg-body .d14k-cat-cb');
                kids.forEach(function (k) { k.checked = cb.checked; var c = k.closest('.d14k-ch'); if (c) c.classList.toggle('d14k-ex', cb.checked); });
                d14kBadge(g); d14kCounter();
            }
            function d14kChildChanged(cb) {
                var c = cb.closest('.d14k-ch'); if (c) c.classList.toggle('d14k-ex', cb.checked);
                var g = cb.closest('.d14k-cg'); d14kSyncParent(g); d14kBadge(g); d14kCounter();
            }
            function d14kSyncParent(g) {
                var kids = g.querySelectorAll('.d14k-cg-body .d14k-cat-cb'), pcb = g.querySelector('.d14k-cg-hdr .d14k-cat-cb'), chk = g.querySelectorAll('.d14k-cg-body .d14k-cat-cb:checked').length;
                if (chk === 0) { pcb.checked = false; pcb.indeterminate = false; }
                else if (chk === kids.length) { pcb.checked = true; pcb.indeterminate = false; }
                else { pcb.checked = false; pcb.indeterminate = true; }
            }
            function d14kBadge(g) {
                var pcb = g.querySelector('.d14k-cg-hdr .d14k-cat-cb'), bid = 'd14kBadge' + pcb.dataset.id, badge = document.getElementById(bid);
                var cnt = g.querySelectorAll('.d14k-cat-cb:checked').length;
                if (badge) { badge.textContent = cnt; badge.style.display = cnt > 0 ? 'inline-block' : 'none'; }
                g.classList.toggle('d14k-has-ex', cnt > 0);
                var hdr = g.querySelector('.d14k-cg-hdr'); hdr.style.background = cnt > 0 ? '#fef7f7' : '';
            }
            function d14kCounter() { var c = document.querySelectorAll('.d14k-cat-cb:checked').length; document.getElementById('d14kExCount').textContent = c; }
            function d14kSelectAll() { document.querySelectorAll('.d14k-cat-cb').forEach(function (cb) { cb.checked = true; cb.indeterminate = false; var i = cb.closest('.d14k-ch'); if (i) i.classList.add('d14k-ex'); }); document.querySelectorAll('.d14k-cg').forEach(function (g) { d14kBadge(g); }); d14kCounter(); }
            function d14kClearAll() { document.querySelectorAll('.d14k-cat-cb').forEach(function (cb) { cb.checked = false; cb.indeterminate = false; var i = cb.closest('.d14k-ch'); if (i) i.classList.remove('d14k-ex'); }); document.querySelectorAll('.d14k-cg').forEach(function (g) { d14kBadge(g); }); d14kCounter(); }
            document.getElementById('d14kCatSearch').addEventListener('input', function () {
                var q = this.value.trim().toLowerCase(), groups = document.querySelectorAll('.d14k-cg'), any = false;
                groups.forEach(function (g) {
                    if (!q) { g.style.display = ''; any = true; return; }
                    var match = (g.dataset.search || '').indexOf(q) >= 0;
                    g.style.display = match ? '' : 'none'; if (match) { any = true; g.classList.add('d14k-open'); }
                });
                document.getElementById('d14kCatEmpty').style.display = any ? 'none' : 'block';
            });
            (function () { d14kCounter(); })();
        </script>
        <?php
        return ob_get_clean();
    }

    private function get_defaults()
    {
        return array(
            'enabled' => true,
            'cron_interval' => 'daily',
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
}
