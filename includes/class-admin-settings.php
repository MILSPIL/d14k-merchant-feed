<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D14K_Admin_Settings {

    private $generator;
    private $wpml;
    private $cron;

    public function __construct( $generator, $wpml, $cron ) {
        $this->generator = $generator;
        $this->wpml      = $wpml;
        $this->cron      = $cron;

        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_link' ), 100 );
        add_action( 'admin_post_d14k_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_d14k_generate_now', array( $this, 'handle_generate_now' ) );
        add_action( 'admin_post_d14k_test_validation', array( $this, 'handle_test_validation' ) );
    }

    public function add_menu() {
        add_menu_page(
            'GMC Feed',
            'GMC Feed',
            'manage_woocommerce',
            'd14k-merchant-feed',
            array( $this, 'render_page' ),
            'dashicons-cloud-upload',
            56 // Position close to WooCommerce products often
        );
    }

    public function add_admin_bar_link( $admin_bar ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $admin_bar->add_node( array(
            'id'    => 'd14k-merchant-feed-bar',
            'title' => 'GMC Feed',
            'href'  => admin_url( 'admin.php?page=d14k-merchant-feed' ),
            'meta'  => array(
                'title' => 'Google Merchant Center Feed Settings',
            ),
        ) );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'd14k-merchant-feed' ) === false ) {
            return;
        }
        wp_enqueue_style( 'd14k-admin', D14K_FEED_URL . 'assets/admin.css', array(), D14K_FEED_VERSION );
    }

    public function render_page() {
        $settings  = get_option( 'd14k_feed_settings', $this->get_defaults() );
        $languages = $this->wpml->get_active_languages();
        $intervals = $this->cron->get_interval_options();
        $next_run  = $this->cron->get_next_run();
        $wc_cats   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
        $notice    = isset( $_GET['d14k_notice'] ) ? sanitize_text_field( $_GET['d14k_notice'] ) : '';
        $show_validation = isset( $_GET['show_validation'] );

        ?>
        <div class="wrap d14k-wrap">
            <h1>D14K Merchant Feed</h1>

            <?php if ( $notice === 'saved' ) : ?>
                <div class="notice notice-success is-dismissible"><p>Налаштування збережено.</p></div>
            <?php elseif ( $notice === 'generated' ) : ?>
                <div class="notice notice-success is-dismissible"><p>Фіди успішно згенеровано!</p></div>
            <?php elseif ( $notice === 'error' ) : ?>
                <div class="notice notice-error is-dismissible"><p>Помилка генерації. Перевірте логи.</p></div>
            <?php endif; ?>

            <?php if ( $show_validation ) : ?>
                <?php $this->render_validation_results(); ?>
                <?php return; ?>
            <?php endif; ?>

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
                    <?php foreach ( $languages as $lang ) :
                        $feed_url   = $this->generator->get_feed_url( $lang );
                        $last_gen   = get_option( "d14k_feed_last_generated_{$lang}", '—' );
                        $last_error = get_option( "d14k_feed_last_error_{$lang}", '' );
                        $last_stats = $this->generator->get_last_stats( $lang );
                        $count      = $last_stats ? $last_stats['valid'] : '—';
                        $skipped    = $last_stats ? $last_stats['skipped'] : 0;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( strtoupper( $lang ) ); ?></strong></td>
                            <td>
                                <code id="feed-url-<?php echo esc_attr( $lang ); ?>"><?php echo esc_url( $feed_url ); ?></code>
                                <button type="button" class="button button-small d14k-copy" data-target="feed-url-<?php echo esc_attr( $lang ); ?>">Копіювати</button>
                            </td>
                            <td><?php echo esc_html( $last_gen ); ?></td>
                            <td><?php echo esc_html( $count ); ?><?php if ( $skipped ) : ?> <small>(пропущено: <?php echo (int) $skipped; ?>)</small><?php endif; ?></td>
                            <td><?php echo $last_error ? '<span class="d14k-error">' . esc_html( $last_error ) . '</span>' : '&#10003;'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
                    <?php wp_nonce_field( 'd14k_generate_now' ); ?>
                    <input type="hidden" name="action" value="d14k_generate_now">
                    <button type="submit" class="button button-primary">Згенерувати зараз</button>
                    <?php if ( $next_run ) : ?>
                        <span class="d14k-next-run">Наступна автогенерація: <?php echo esc_html( $next_run ); ?></span>
                    <?php endif; ?>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
                    <?php wp_nonce_field( 'd14k_test_validation' ); ?>
                    <input type="hidden" name="action" value="d14k_test_validation">
                    <button type="submit" class="button">🔍 Тестова перевірка (10 товарів)</button>
                    <p class="description">Перевірте фід на наявність всіх обов'язкових полів перед відправкою до Google Merchant Center</p>
                </form>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'd14k_save_settings' ); ?>
                <input type="hidden" name="action" value="d14k_save_settings">

                <div class="d14k-card">
                    <h2>Налаштування</h2>
                    <table class="form-table">
                        <tr>
                            <th>Увімкнути фід</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                                    Автоматична генерація за розкладом
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Інтервал оновлення</th>
                            <td>
                                <select name="cron_interval">
                                    <?php foreach ( $intervals as $key => $label ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( isset( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'd14k_every_6_hours', $key ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Бренд</th>
                            <td><input type="text" name="brand" value="<?php echo esc_attr( isset( $settings['brand'] ) ? $settings['brand'] : '' ); ?>" class="regular-text" placeholder="Назва вашого бренду"></td>
                        </tr>
                        <tr>
                            <th>Країна походження<br><small>(Country of Origin)</small></th>
                            <td>
                                <select name="country_of_origin" class="regular-text">
                                    <?php
                                    $selected_country = isset( $settings['country_of_origin'] ) ? $settings['country_of_origin'] : 'UA';
                                    $countries = array(
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
                                    foreach ( $countries as $code => $name ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected_country, $code ); ?>><?php echo esc_html( $name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Вкажіть країну, де виготовлені ваші товари. Це обов'язкове поле для Google Merchant Center.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Google Product Category<br><small>(за замовчуванням)</small></th>
                            <td><input type="text" name="default_google_category" value="<?php echo esc_attr( isset( $settings['default_google_category'] ) ? $settings['default_google_category'] : '' ); ?>" class="large-text" placeholder="Apparel &amp; Accessories &gt; Jewelry"></td>
                        </tr>
                    </table>
                </div>

                <div class="d14k-card">
                    <h2>Маппінг категорій Google</h2>
                    <p>Призначте Google Product Category для кожної категорії WooCommerce. Порожнє = використовується категорія за замовчуванням.</p>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Категорія WooCommerce</th>
                                <th>Google Product Category</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $cat_map = isset( $settings['category_map'] ) ? $settings['category_map'] : array();
                        if ( $wc_cats && ! is_wp_error( $wc_cats ) ) :
                            foreach ( $wc_cats as $cat ) :
                        ?>
                            <tr>
                                <td><?php echo esc_html( $cat->name ); ?> <small>(<?php echo (int) $cat->count; ?> товарів)</small></td>
                                <td>
                                    <input type="text"
                                           name="category_map[<?php echo (int) $cat->term_id; ?>]"
                                           value="<?php echo esc_attr( isset( $cat_map[ $cat->term_id ] ) ? $cat_map[ $cat->term_id ] : '' ); ?>"
                                           class="large-text"
                                           placeholder="Apparel &amp; Accessories &gt; Jewelry &gt; ...">
                                </td>
                            </tr>
                        <?php
                            endforeach;
                        endif;
                        ?>
                        </tbody>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary">Зберегти налаштування</button>
                </p>
            </form>
        </div>

        <script>
        document.querySelectorAll('.d14k-copy').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var el = document.getElementById(this.dataset.target);
                navigator.clipboard.writeText(el.textContent).then(function() {
                    btn.textContent = 'Скопійовано!';
                    setTimeout(function() { btn.textContent = 'Копіювати'; }, 1500);
                });
            });
        });
        </script>
        <?php
    }

    public function save_settings() {
        check_admin_referer( 'd14k_save_settings' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Access denied' );
        }

        $settings = array(
            'enabled'                 => ! empty( $_POST['enabled'] ),
            'cron_interval'           => sanitize_text_field( isset( $_POST['cron_interval'] ) ? $_POST['cron_interval'] : 'd14k_every_6_hours' ),
            'brand'                   => sanitize_text_field( isset( $_POST['brand'] ) ? $_POST['brand'] : '' ),
            'country_of_origin'       => sanitize_text_field( isset( $_POST['country_of_origin'] ) ? $_POST['country_of_origin'] : 'UA' ),
            'default_google_category' => sanitize_text_field( isset( $_POST['default_google_category'] ) ? $_POST['default_google_category'] : '' ),
            'category_map'            => array_map( 'sanitize_text_field', isset( $_POST['category_map'] ) ? $_POST['category_map'] : array() ),
        );

        $old_settings = get_option( 'd14k_feed_settings', array() );
        update_option( 'd14k_feed_settings', $settings );

        $old_interval = isset( $old_settings['cron_interval'] ) ? $old_settings['cron_interval'] : '';
        if ( $old_interval !== $settings['cron_interval'] ) {
            $this->cron->reschedule( $settings['cron_interval'] );
        }

        wp_redirect( admin_url( 'admin.php?page=d14k-merchant-feed&d14k_notice=saved' ) );
        exit;
    }

    public function handle_generate_now() {
        check_admin_referer( 'd14k_generate_now' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Access denied' );
        }

        $languages = $this->wpml->get_active_languages();
        $success   = true;

        foreach ( $languages as $lang ) {
            if ( ! $this->generator->generate( $lang ) ) {
                $success = false;
            }
        }

        $notice = $success ? 'generated' : 'error';
        wp_redirect( admin_url( "admin.php?page=d14k-merchant-feed&d14k_notice={$notice}" ) );
        exit;
    }

    public function handle_test_validation() {
        check_admin_referer( 'd14k_test_validation' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Access denied' );
        }

        $settings = get_option( 'd14k_feed_settings', $this->get_defaults() );
        $validator = new D14K_Feed_Validator();
        $report = $validator->validate_test_feed( $this->wpml, $settings, $this->generator );

        // Store report in transient for display
        set_transient( 'd14k_validation_report', $report, 300 ); // 5 minutes

        wp_redirect( admin_url( 'admin.php?page=d14k-merchant-feed&show_validation=1' ) );
        exit;
    }

    private function render_validation_results() {
        $report = get_transient( 'd14k_validation_report' );
        if ( ! $report ) {
            echo '<div class="notice notice-error"><p>Звіт валідації не знайдено. Спробуйте ще раз.</p></div>';
            return;
        }

        $total = $report['total_products'];
        $valid = $report['valid_products'];
        $invalid = $report['invalid_products'];
        $percentage = $total > 0 ? round( ( $valid / $total ) * 100 ) : 0;

        ?>
        <div class="d14k-card">
            <h2>🔍 Результати тестової валідації</h2>
            
            <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <h3 style="margin-top: 0;">Загальна статистика</h3>
                <p style="font-size: 16px; margin: 10px 0;">
                    <strong>Перевірено товарів:</strong> <?php echo (int) $total; ?><br>
                    <strong style="color: #46b450;">✅ Валідних:</strong> <?php echo (int) $valid; ?> (<?php echo (int) $percentage; ?>%)<br>
                    <strong style="color: #dc3232;">❌ З помилками:</strong> <?php echo (int) $invalid; ?> (<?php echo (int) ( 100 - $percentage ); ?>%)
                </p>
            </div>

            <?php if ( ! empty( $report['sample_item_xml'] ) ) : ?>
            <div style="margin-bottom: 30px;">
                <h3>📦 Приклад даних (XML)</h3>
                <p class="description" style="margin-bottom: 10px;">Так виглядатиме перший пройдений товар у файлі фіда. Перевірте теги та атрибути.</p>
                <textarea readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px; background: #282c34; color: #abb2bf; border-radius: 4px; border: 1px solid #ccc; padding: 10px; box-sizing: border-box; white-space: pre;"><?php echo esc_textarea( $report['sample_item_xml'] ); ?></textarea>
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
                <?php foreach ( $report['fields_stats'] as $field => $stats ) : 
                    $present = $stats['present'];
                    $missing = $stats['missing'];
                    $is_ok = $missing === 0;
                    $status_color = $is_ok ? '#46b450' : '#dc3232';
                    $status_icon = $is_ok ? '✅' : '⚠️';
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $stats['label'] ); ?></strong></td>
                        <td><?php echo (int) $present; ?>/<?php echo (int) $total; ?></td>
                        <td style="color: <?php echo $missing > 0 ? '#dc3232' : '#666'; ?>;"><?php echo (int) $missing; ?></td>
                        <td style="color: <?php echo esc_attr( $status_color ); ?>; font-size: 18px;"><?php echo $status_icon; ?></td>
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
                <?php foreach ( $report['products'] as $product ) : 
                    $status_color = $product['is_valid'] ? '#46b450' : '#dc3232';
                    $status_text = $product['is_valid'] ? '✅ Валідний' : '❌ Помилки';
                ?>
                    <tr>
                        <td><?php echo (int) $product['id']; ?></td>
                        <td><?php echo esc_html( $product['title'] ); ?></td>
                        <td style="color: <?php echo esc_attr( $status_color ); ?>; font-weight: bold;"><?php echo $status_text; ?></td>
                        <td>
                            <?php if ( ! empty( $product['missing_fields'] ) ) : ?>
                                <span style="color: #dc3232;">
                                    <?php echo esc_html( implode( ', ', $product['missing_fields'] ) ); ?>
                                </span>
                            <?php else : ?>
                                <span style="color: #46b450;">Всі поля присутні</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 20px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=d14k-merchant-feed' ) ); ?>" class="button">← Повернутися до налаштувань</a>
            </p>
        </div>
        <?php
    }

    private function get_defaults() {
        return array(
            'enabled'                 => true,
            'cron_interval'           => 'd14k_every_6_hours',
            'brand'                   => '',
            'country_of_origin'       => 'UA',
            'default_google_category' => '',
            'category_map'            => array(),
        );
    }
}
