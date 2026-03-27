<?php
if (!defined('ABSPATH')) {
    exit;
}

class D14K_Product_Meta
{

    public function __construct()
    {
        // Add options to the General tab in Product Data
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_custom_label_field'));
        // Save the custom field
        add_action('woocommerce_process_product_meta', array($this, 'save_custom_label_field'));
    }

    public function add_custom_label_field()
    {
        global $woocommerce, $post;

        echo '<div class="options_group">';

        wp_nonce_field('d14k_save_product_meta', 'd14k_product_meta_nonce');

        // Custom Label 0 Field
        woocommerce_wp_text_input(
            array(
                'id' => '_d14k_custom_label_0',
                'label' => __('GMC Custom Label 0', 'woocommerce'),
                'placeholder' => 'Наприклад: Rings, Earrings, etc.',
                'desc_tip' => 'true',
                'description' => __('Перевизначити Custom Label 0 для Google Merchant Center фіду. Якщо порожньо, буде визначено автоматично за категорією або назвою.', 'woocommerce')
            )
        );

        // Custom Label 1 Field
        woocommerce_wp_text_input(
            array(
                'id' => '_d14k_custom_label_1',
                'label' => __('GMC Custom Label 1', 'woocommerce'),
                'placeholder' => '',
                'desc_tip' => 'true',
                'description' => __('Перевизначити Custom Label 1', 'woocommerce')
            )
        );

        // Custom Label 2 Field
        woocommerce_wp_text_input(
            array(
                'id' => '_d14k_custom_label_2',
                'label' => __('GMC Custom Label 2', 'woocommerce'),
                'placeholder' => '',
                'desc_tip' => 'true',
                'description' => __('Перевизначити Custom Label 2', 'woocommerce')
            )
        );

        // Custom Label 3 Field
        woocommerce_wp_text_input(
            array(
                'id' => '_d14k_custom_label_3',
                'label' => __('GMC Custom Label 3', 'woocommerce'),
                'placeholder' => '',
                'desc_tip' => 'true',
                'description' => __('Перевизначити Custom Label 3', 'woocommerce')
            )
        );

        // Custom Label 4 Field
        woocommerce_wp_text_input(
            array(
                'id' => '_d14k_custom_label_4',
                'label' => __('GMC Custom Label 4', 'woocommerce'),
                'placeholder' => '',
                'desc_tip' => 'true',
                'description' => __('Перевизначити Custom Label 4', 'woocommerce')
            )
        );

        echo '</div>';
    }

    public function save_custom_label_field($post_id)
    {
        if (!isset($_POST['d14k_product_meta_nonce']) || !wp_verify_nonce($_POST['d14k_product_meta_nonce'], 'd14k_save_product_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        for ($i = 0; $i <= 4; $i++) {
            $key = '_d14k_custom_label_' . $i;
            if (isset($_POST[$key])) {
                update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
            }
        }
    }
}
new D14K_Product_Meta();
