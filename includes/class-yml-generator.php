<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * YML Feed Generator for Ukrainian Marketplaces.
 *
 * Generates YML (Yandex Market Language) XML feeds for:
 *   - Horoshop (minimal: id, name, categoryId)
 *   - Prom.ua  (selling_type, group_id, description mandatory)
 *   - Rozetka  (stock_quantity, vendor, min 3 params mandatory)
 *
 * @since 3.0.0
 */
class D14K_YML_Generator
{
    /** @var D14K_WPML_Handler */
    private $wpml;

    /** @var D14K_Feed_Validator */
    private $validator;

    /** @var string */
    private $feed_dir;

    /** @var array */
    private $stats = array();

    /** @var array Plugin settings (loaded at generation time) */
    private $settings = array();

    /** @var array Supported marketplace channels */
    const CHANNELS = array('prom', 'rozetka');

    public function __construct($wpml, $validator)
    {
        $this->wpml = $wpml;
        $this->validator = $validator;
        $upload_dir = wp_upload_dir();
        $this->feed_dir = $upload_dir['basedir'] . '/d14k-feeds/';
    }

    /**
     * Generate a single bilingual YML feed for the specified channel.
     *
     * All Ukrainian marketplaces support bilingual content in one feed
     * via name_ua / description_ua tags. Products are loaded in the
     * default language (RU), and UA translations are fetched via WPML.
     *
     * @param string $channel Channel name (prom, rozetka)
     * @return bool Success
     */
    public function generate($channel)
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            error_log("D14K YML: Unknown channel '{$channel}'");
            return false;
        }

        $this->stats = array('total' => 0, 'valid' => 0, 'skipped' => 0, 'errors' => array());

        if (!file_exists($this->feed_dir)) {
            wp_mkdir_p($this->feed_dir);
        }

        // Marketplace feeds use RU as base language (YML standard),
        // with UA translations embedded as name_ua / description_ua.
        $this->wpml->switch_language('ru');

        try {
            $xml = $this->build_yml($channel);
            $path = $this->get_feed_path($channel);
            $written = file_put_contents($path, $xml);

            if ($written === false) {
                throw new Exception("Cannot write YML feed file: {$path}");
            }

            update_option("d14k_yml_last_generated_{$channel}", current_time('mysql'));
            update_option("d14k_yml_last_stats_{$channel}", $this->stats);
            delete_option("d14k_yml_last_error_{$channel}");

        } catch (Exception $e) {
            $this->wpml->restore_language();
            update_option("d14k_yml_last_error_{$channel}", $e->getMessage());
            error_log('D14K YML: Generation failed [' . $channel . ']: ' . $e->getMessage());
            return false;
        }

        $this->wpml->restore_language();
        return true;
    }

    /**
     * Build the complete YML XML document.
     */
    private function build_yml($channel)
    {
        $settings = get_option('d14k_feed_settings', array());
        $this->settings = $settings;
        $excluded_categories = isset($settings['excluded_categories']) ? array_map('intval', $settings['excluded_categories']) : array();
        if (!empty($excluded_categories) && method_exists($this, 'expand_excluded_with_translations')) {
            $excluded_categories = $this->expand_excluded_with_translations($excluded_categories);
        }
        $attribute_filters = isset($settings['attribute_filters']) ? $settings['attribute_filters'] : array();
        $custom_rules = isset($settings['custom_rules']) ? $settings['custom_rules'] : array();

        $default_lang = $this->wpml->get_default_language();
        $site_url = $this->wpml->get_home_url($default_lang);
        $site_name = get_bloginfo('name');
        $date = current_time('Y-m-d H:i');

        // Build XML header
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<yml_catalog date="' . $this->esc($date) . '">' . "\n";
        $xml .= "<shop>\n";
        $xml .= '  <name>' . $this->esc($site_name) . "</name>\n";
        $xml .= '  <company>' . $this->esc($site_name) . "</company>\n";
        $xml .= '  <url>' . $this->esc($site_url) . "</url>\n";
        $xml .= "  <currencies>\n";
        $xml .= '    <currency id="UAH" rate="1"/>' . "\n";
        $xml .= "  </currencies>\n";

        // Build categories
        // Categories in default language (RU) — marketplaces match by text
        $xml .= $this->build_categories();

        // Build offers
        $xml .= "  <offers>\n";

        set_time_limit(0);

        $product_ids = wc_get_products(array(
            'limit' => -1,
            'status' => 'publish',
            'return' => 'ids',
        ));

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }

            $product_id = $product->get_id();

            if ($product->is_type('simple')) {
                if (!empty($excluded_categories) && $this->is_in_excluded_categories($product_id, $excluded_categories)) {
                    continue;
                }

                $this->stats['total']++;

                $image_id = $this->wpml->get_image_with_fallback($product_id, $product->get_image_id());
                $gallery = $this->wpml->get_gallery_with_fallback($product_id, $product->get_gallery_image_ids());

                if (!empty($attribute_filters) && $this->is_excluded_by_attributes($product, $attribute_filters)) {
                    $this->stats['skipped']++;
                    continue;
                }

                if (!empty($custom_rules) && !$this->passes_custom_rules($product, $product, $custom_rules)) {
                    $this->stats['skipped']++;
                    continue;
                }

                // For YML, skip products without images (all platforms need at least URL)
                if (!$image_id) {
                    $this->stats['skipped']++;
                    continue;
                }

                $brand = $this->resolve_brand($product, $product, $settings);
                $xml .= $this->build_offer($product, $product, $image_id, $gallery, $brand, $channel, $settings);
                $this->stats['valid']++;

            } elseif ($product->is_type('variable')) {
                $parent = $product;
                $parent_id = $parent->get_id();

                if (!empty($excluded_categories) && $this->is_in_excluded_categories($parent_id, $excluded_categories)) {
                    continue;
                }

                $parent_image_id = $this->wpml->get_image_with_fallback($parent_id, $parent->get_image_id());
                $parent_gallery = $this->wpml->get_gallery_with_fallback($parent_id, $parent->get_gallery_image_ids());

                $variation_ids = $parent->get_children();

                foreach ($variation_ids as $var_id) {
                    $variation = wc_get_product($var_id);
                    if (!$variation || !$variation->is_type('variation')) {
                        continue;
                    }
                    $this->stats['total']++;

                    $image_id = $variation->get_image_id();
                    if (!$image_id) {
                        $image_id = $parent_image_id;
                    }

                    if (!$image_id) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    if (!empty($attribute_filters) && $this->is_excluded_by_attributes($variation, $attribute_filters)) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    if (!empty($custom_rules) && !$this->passes_custom_rules($variation, $parent, $custom_rules)) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    $brand = $this->resolve_brand($variation, $parent, $settings);
                    $xml .= $this->build_offer($variation, $parent, $image_id, $parent_gallery, $brand, $channel, $settings);
                    $this->stats['valid']++;
                }
            }
        }

        $xml .= "  </offers>\n";
        $xml .= "</shop>\n";
        $xml .= "</yml_catalog>\n";

        return $xml;
    }

    /**
     * Build a single <offer> element with platform-specific fields.
     */
    private function build_offer($product, $parent, $image_id, $gallery_ids, $brand, $channel, $settings)
    {
        $id = $product->get_id();
        $is_variation = ($product->get_id() !== $parent->get_id());

        // Title
        $title = $product->get_name();
        if (empty(trim($title))) {
            $title = $parent->get_name();
        }

        // Description
        $description = $parent->get_description();
        if (empty(trim($description))) {
            $description = $parent->get_short_description();
        }
        if (empty(trim($description))) {
            $description = $title;
        }

        // For Rozetka — text only with CDATA, strip HTML
        if ($channel === 'rozetka') {
            $description_output = wp_strip_all_tags($description);
        } else {
            // Prom/Horoshop accept HTML in CDATA
            $description_output = $description;
        }

        // URL
        $url = $product->get_permalink();

        // Image
        $image_url = $image_id ? $this->maybe_convert_webp_url(wp_get_attachment_url($image_id)) : '';

        // Gallery images
        $max_photos = ($channel === 'rozetka') ? 15 : 10;
        $additional_images = array();
        foreach (array_slice($gallery_ids, 0, $max_photos) as $gid) {
            if ($gid != $image_id) {
                $img_url = $this->maybe_convert_webp_url(wp_get_attachment_url($gid));
                if ($img_url) {
                    $additional_images[] = $img_url;
                }
            }
        }

        // Prices
        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();

        // Stock
        $stock_status = $product->get_stock_status();
        $available = ($stock_status === 'instock') ? 'true' : 'false';
        $stock_quantity = $product->get_stock_quantity();

        // SKU
        $sku = $product->get_sku();
        if (empty($sku) && $is_variation) {
            $sku = $parent->get_sku();
        }

        // Category
        $category_id = $this->get_primary_category_id($parent);

        // Attributes / params
        $params = $this->get_product_params($product, $parent, $settings);

        // --- Build the <offer> XML ---

        $offer_id = $id;
        $offer_attrs = 'id="' . $offer_id . '" available="' . $available . '"';

        // Prom-specific: selling_type & group_id
        if ($channel === 'prom') {
            $offer_attrs .= ' selling_type="r"';
            if ($is_variation) {
                $offer_attrs .= ' group_id="' . $parent->get_id() . '"';
            }
            $offer_attrs .= ' in_stock="' . $available . '"';
        }

        $xml = "    <offer {$offer_attrs}>\n";

        // URL
        $xml .= '      <url>' . $this->esc($url) . "</url>\n";

        // Price handling: YML uses price = current price, oldprice = original if on sale
        if ($sale_price && (float) $sale_price > 0 && (float) $sale_price < (float) $regular_price) {
            $xml .= '      <price>' . $this->format_price($sale_price) . "</price>\n";
            $price_old_tag = ($channel === 'rozetka') ? 'price_old' : 'oldprice';
            $xml .= '      <' . $price_old_tag . '>' . $this->format_price($regular_price) . '</' . $price_old_tag . ">\n";
        } else {
            $xml .= '      <price>' . $this->format_price($regular_price) . "</price>\n";
        }

        $xml .= "      <currencyId>UAH</currencyId>\n";
        $xml .= '      <categoryId>' . intval($category_id) . "</categoryId>\n";

        // Pictures — all channels: standard YML <picture> tags
        // First <picture> = main photo, rest = gallery
        if ($image_url) {
            $xml .= '      <picture>' . $this->esc($image_url) . "</picture>\n";
        }
        foreach ($additional_images as $ai) {
            $xml .= '      <picture>' . $this->esc($ai) . "</picture>\n";
        }

        // Vendor / Brand
        if (!empty($brand)) {
            $xml .= '      <vendor>' . $this->esc($brand) . "</vendor>\n";
        }

        // SKU / Article / VendorCode
        if (!empty($sku)) {
            if ($channel === 'rozetka') {
                $xml .= '      <article>' . $this->esc($sku) . "</article>\n";
            } else {
                // Prom uses vendorCode
                $xml .= '      <vendorCode>' . $this->esc($sku) . "</vendorCode>\n";
            }
        }

        // Name (base language — RU)
        $xml .= '      <name>' . $this->esc($title) . "</name>\n";

        // Name UA — Ukrainian translation
        $ua_title = $this->get_translated_value($parent, $product, 'title', 'uk');
        if ($ua_title && $ua_title !== $title) {
            $xml .= '      <name_ua>' . $this->esc($ua_title) . "</name_ua>\n";
        }

        // Description (base language — RU)
        $xml .= '      <description>' . $this->cdata($description_output) . "</description>\n";

        // Description UA — Ukrainian translation
        $ua_desc = $this->get_translated_value($parent, $product, 'description', 'uk');
        if ($ua_desc) {
            if ($channel === 'rozetka') {
                $ua_desc = wp_strip_all_tags($ua_desc);
            }
            $xml .= '      <description_ua>' . $this->cdata($ua_desc) . "</description_ua>\n";
        }

        // Stock quantity (Prom & Rozetka)
        if ($channel === 'prom') {
            if ($stock_quantity !== null && $stock_quantity !== '') {
                $xml .= '      <quantity_in_stock>' . intval($stock_quantity) . "</quantity_in_stock>\n";
            }
        } elseif ($channel === 'rozetka') {
            $qty = ($stock_quantity !== null && $stock_quantity !== '') ? intval($stock_quantity) : ($available === 'true' ? 1 : 0);
            $xml .= '      <stock_quantity>' . $qty . "</stock_quantity>\n";
        }

        // Country of origin
        $country = isset($settings['country_of_origin']) ? $settings['country_of_origin'] : '';
        if (!empty($country) && $channel === 'prom') {
            $xml .= '      <country>' . $this->esc($this->get_country_name($country)) . "</country>\n";
        }

        // Delivery — тільки для Prom (Horoshop не підтримує цей тег)
        if ($channel === 'prom') {
            $xml .= "      <delivery>true</delivery>\n";
        }

        // Params / Characteristics
        foreach ($params as $param) {
            $param_xml = '      <param name="' . $this->esc($param['name']) . '"';
            if (!empty($param['unit'])) {
                $param_xml .= ' unit="' . $this->esc($param['unit']) . '"';
            }
            $param_xml .= '>' . $this->esc($param['value']) . "</param>\n";
            $xml .= $param_xml;
        }

        // Keywords (Prom only)
        if ($channel === 'prom') {
            $tags = $this->get_product_tags($parent);
            if (!empty($tags)) {
                $xml .= '      <keywords>' . $this->esc(implode(', ', $tags)) . "</keywords>\n";
            }
        }

        // GTIN (if available — Prom supports it for GMC integration)
        $gtin = get_post_meta($id, '_gtin', true);
        if (empty($gtin) && $is_variation) {
            $gtin = get_post_meta($parent->get_id(), '_gtin', true);
        }
        if (!empty($gtin) && $channel === 'prom') {
            $xml .= '      <gtin>' . $this->esc($gtin) . "</gtin>\n";
        }

        $xml .= "    </offer>\n";

        return $xml;
    }

    /**
     * Build the <categories> block from WooCommerce product categories.
     */
    private function build_categories()
    {
        $xml = "  <categories>\n";

        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'orderby' => 'id',
        ));

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $cat_xml = '    <category id="' . $term->term_id . '"';
                if ($term->parent > 0) {
                    $cat_xml .= ' parentId="' . $term->parent . '"';
                }
                $cat_xml .= '>' . $this->esc($term->name) . "</category>\n";
                $xml .= $cat_xml;
            }
        }

        $xml .= "  </categories>\n";
        return $xml;
    }

    /**
     * Get the primary category ID for a product.
     */
    private function get_primary_category_id($product)
    {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            // Prefer the deepest (most specific) category
            $deepest = null;
            $max_depth = -1;
            foreach ($terms as $term) {
                $depth = count(get_ancestors($term->term_id, 'product_cat'));
                if ($depth > $max_depth) {
                    $max_depth = $depth;
                    $deepest = $term;
                }
            }
            return $deepest ? $deepest->term_id : $terms[0]->term_id;
        }
        return 0;
    }

    /**
     * Extract product attributes as params array.
     *
     * @return array Array of ['name' => ..., 'value' => ..., 'unit' => ...]
     */
    private function get_product_params($product, $parent, $settings = array())
    {
        $params = array();
        $attributes = $parent->get_attributes();

        $brand_attr = isset($settings['brand_attribute']) ? $settings['brand_attribute'] : '';

        foreach ($attributes as $attr_key => $attribute) {
            $name = '';
            $values = array();

            if ($attribute instanceof WC_Product_Attribute) {
                if ($attribute->is_taxonomy()) {
                    $taxonomy = $attribute->get_taxonomy();

                    if ($brand_attr !== '' && $taxonomy === $brand_attr) {
                        continue;
                    }

                    $tax_obj = get_taxonomy($taxonomy);
                    $name = $tax_obj ? $tax_obj->labels->singular_name : wc_attribute_label($taxonomy);

                    // For variations, get the specific value
                    if ($product->is_type('variation')) {
                        $var_val = $product->get_attribute($taxonomy);
                        if (!empty($var_val)) {
                            $values = array($var_val);
                        }
                    } else {
                        $terms = wc_get_product_terms($parent->get_id(), $taxonomy, array('fields' => 'names'));
                        if (!empty($terms)) {
                            $values = $terms;
                        }
                    }
                } else {
                    // Custom (non-taxonomy) attribute
                    $name = $attribute->get_name();

                    if ($brand_attr !== '' && $name === $brand_attr) {
                        continue;
                    }

                    $val = $product->get_attribute($attr_key);
                    if (!empty($val)) {
                        $values = array($val);
                    } elseif (!$product->is_type('variation')) {
                        $options = $attribute->get_options();
                        if (!empty($options)) {
                            $values = $options;
                        }
                    }
                }
            }

            if (!empty($name) && !empty($values)) {
                foreach ($values as $value) {
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $params[] = array(
                            'name' => $name,
                            'value' => $value,
                            'unit' => '',
                        );
                    }
                }
            }
        }

        return $params;
    }

    /**
     * Get product tags as array of names.
     */
    private function get_product_tags($product)
    {
        $terms = get_the_terms($product->get_id(), 'product_tag');
        if ($terms && !is_wp_error($terms)) {
            return wp_list_pluck($terms, 'name');
        }
        return array();
    }

    /**
     * Get translated product value via WPML.
     *
     * @param WC_Product $parent   Parent product
     * @param WC_Product $product  Product or variation
     * @param string     $field    'title' or 'description'
     * @param string     $target_lang Target language code
     * @return string|null Translated value or null
     */
    private function get_translated_value($parent, $product, $field, $target_lang)
    {
        if (!$this->wpml || !method_exists($this->wpml, 'get_original_id')) {
            return null;
        }

        $original_id = $this->wpml->get_original_id($parent->get_id());
        $translated_id = apply_filters('wpml_object_id', $original_id, 'product', false, $target_lang);

        if (!$translated_id || $translated_id == $parent->get_id()) {
            return null;
        }

        $translated_product = wc_get_product($translated_id);
        if (!$translated_product) {
            return null;
        }

        if ($field === 'title') {
            return $translated_product->get_name();
        } elseif ($field === 'short_description') {
            return $translated_product->get_short_description();
        } elseif ($field === 'description') {
            $desc = $translated_product->get_description();
            if (empty(trim($desc))) {
                $desc = $translated_product->get_short_description();
            }
            return $desc;
        }

        return null;
    }

    /**
     * Convert ISO country code to Ukrainian name (for Prom.ua).
     */
    private function get_country_name($code)
    {
        $countries = array(
            'UA' => 'Україна',
            'CN' => 'Китай',
            'KR' => 'Корея',
            'US' => 'США',
            'DE' => 'Німеччина',
            'FR' => 'Франція',
            'IT' => 'Італія',
            'PL' => 'Польща',
            'TR' => 'Туреччина',
            'GB' => 'Великобританія',
        );
        return isset($countries[$code]) ? $countries[$code] : $code;
    }

    // --- Utility methods (reused from D14K_Feed_Generator) ---

    /**
     * Format price for YML (no currency suffix, dot as separator).
     */
    private function format_price($price)
    {
        $p = (float) $price;
        // If integer value, return without decimals
        if ($p == intval($p)) {
            return (string) intval($p);
        }
        return number_format($p, 2, '.', '');
    }

    private function esc($text)
    {
        return htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function cdata($text)
    {
        $text = str_replace(']]>', ']]]]><![CDATA[>', (string) $text);
        return '<![CDATA[' . $text . ']]>';
    }

    // --- Path & URL helpers ---

    public function get_feed_path($channel)
    {
        return $this->feed_dir . "yml-{$channel}.xml";
    }

    public function get_feed_url($channel)
    {
        return home_url("/marketplace-feed/{$channel}/");
    }

    public function get_last_stats($channel)
    {
        return get_option("d14k_yml_last_stats_{$channel}", null);
    }

    public function get_last_generated($channel)
    {
        return get_option("d14k_yml_last_generated_{$channel}", null);
    }

    public function get_last_error($channel)
    {
        return get_option("d14k_yml_last_error_{$channel}", null);
    }

    // --- Filtering methods (delegated from D14K_Feed_Generator) ---

    /**
     * Keep the original image URL for YML feeds.
     *
     * WebP to JPG conversion is reserved for the dedicated Horoshop CSV flow.
     *
     * @param string $url Image URL
     * @return string
     */
    private function maybe_convert_webp_url($url)
    {
        return $url;
    }

    /**
     * Parse PHP memory_limit into bytes.
     *
     * @return int Memory limit in bytes, 0 if unlimited (-1)
     */
    private function get_memory_limit_bytes()
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return 0;
        }
        $value = (int) $limit;
        $unit = strtolower(substr(trim($limit), -1));
        switch ($unit) {
            case 'g':
                $value *= 1024;
            // fallthrough
            case 'm':
                $value *= 1024;
            // fallthrough
            case 'k':
                $value *= 1024;
        }
        return $value;
    }

    /**
     * Resolve brand using same logic as GMC generator.
     */
    private function resolve_brand($product, $parent, $settings)
    {
        $custom_brand = isset($settings['brand']) && $settings['brand'] !== ''
            ? $settings['brand']
            : get_bloginfo('name');

        $attr_tax = isset($settings['brand_attribute']) ? $settings['brand_attribute'] : '';

        if (empty($attr_tax)) {
            return $custom_brand;
        }

        $terms = get_the_terms($parent->get_id(), $attr_tax);

        if ($terms && !is_wp_error($terms)) {
            $names = array_map(function ($t) {
                return html_entity_decode($t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $terms);
            $brand_val = trim(implode(', ', $names));
            return $brand_val !== '' ? $brand_val : $custom_brand;
        }

        $brand_val = trim((string) $parent->get_attribute($attr_tax));
        return $brand_val !== '' ? $brand_val : $custom_brand;
    }

    private function is_in_excluded_categories($product_id, $excluded_categories)
    {
        $terms = get_the_terms($product_id, 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return false;
        }
        foreach ($terms as $term) {
            if (in_array((int) $term->term_id, $excluded_categories, true)) {
                return true;
            }
            $ancestors = get_ancestors((int) $term->term_id, 'product_cat');
            foreach ($ancestors as $ancestor_id) {
                if (in_array((int) $ancestor_id, $excluded_categories, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function expand_excluded_with_translations($term_ids)
    {
        if (empty($term_ids)) {
            return $term_ids;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'icl_translations';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return $term_ids;
        }
        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
        $trids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT trid FROM {$table}
                 WHERE element_type = 'tax_product_cat'
                 AND element_id IN ($placeholders)",
                $term_ids
            )
        );
        if (empty($trids)) {
            return $term_ids;
        }
        $trid_ph = implode(',', array_fill(0, count($trids), '%d'));
        $all_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT element_id FROM {$table}
                 WHERE element_type = 'tax_product_cat'
                 AND trid IN ($trid_ph)
                 AND element_id IS NOT NULL",
                $trids
            )
        );
        return array_unique(array_map('intval', array_merge($term_ids, $all_ids)));
    }

    private function is_excluded_by_attributes($product, $attribute_filters)
    {
        foreach ($attribute_filters as $taxonomy => $filter) {
            if (empty($filter['enabled']) || empty($filter['values'])) {
                continue;
            }
            $mode = isset($filter['mode']) ? $filter['mode'] : 'exclude';
            $filter_slugs = (array) $filter['values'];

            if ($product->is_type('variation')) {
                $variation_attrs = $product->get_variation_attributes();
                $attr_key = 'attribute_' . $taxonomy;
                if (!array_key_exists($attr_key, $variation_attrs)) {
                    continue;
                }
                $term_slug = $variation_attrs[$attr_key];
                if ($term_slug === '') {
                    continue;
                }
                if ($mode === 'exclude' && in_array($term_slug, $filter_slugs, true)) {
                    return true;
                }
                if ($mode === 'include' && !in_array($term_slug, $filter_slugs, true)) {
                    return true;
                }
            } else {
                $terms = wp_get_object_terms($product->get_id(), $taxonomy, array('fields' => 'slugs'));
                if (is_wp_error($terms) || empty($terms)) {
                    continue;
                }
                if ($mode === 'exclude' && !empty(array_intersect($terms, $filter_slugs))) {
                    return true;
                }
                if ($mode === 'include' && empty(array_intersect($terms, $filter_slugs))) {
                    return true;
                }
            }
        }
        return false;
    }

    private function passes_custom_rules($product, $parent, $custom_rules)
    {
        foreach ($custom_rules as $rule) {
            if (empty($rule['field']) || empty($rule['condition'])) {
                continue;
            }
            $field = $rule['field'];
            $meta_key = isset($rule['meta_key']) ? $rule['meta_key'] : '';
            $cond = $rule['condition'];
            $value = isset($rule['value']) ? $rule['value'] : '';
            $action = isset($rule['action']) ? $rule['action'] : 'exclude';

            $pval = $this->get_rule_field_value($product, $parent, $field, $meta_key);
            $matches = $this->eval_rule_condition($pval, $cond, $value);

            if ($action === 'exclude' && $matches) {
                return false;
            }
            if ($action === 'include' && !$matches) {
                return false;
            }
        }
        return true;
    }

    private function get_rule_field_value($product, $parent, $field, $meta_key = '')
    {
        switch ($field) {
            case 'title':
                return strtolower($parent->get_name());
            case 'sku':
                return strtolower((string) $product->get_sku());
            case 'regular_price':
                $v = $product->get_regular_price();
                return $v !== '' ? (float) $v : null;
            case 'sale_price':
                $v = $product->get_sale_price();
                return $v !== '' ? (float) $v : null;
            case 'stock_status':
                return $product->get_stock_status();
            case 'tag':
                $terms = wp_get_object_terms($parent->get_id(), 'product_tag', array('fields' => 'names'));
                if (is_wp_error($terms) || empty($terms)) {
                    return '';
                }
                return strtolower(implode(',', $terms));
            case 'custom_meta':
                if (empty($meta_key)) {
                    return '';
                }
                $v = get_post_meta($product->get_id(), $meta_key, true);
                if ($v === '' || $v === false) {
                    $v = get_post_meta($parent->get_id(), $meta_key, true);
                }
                return strtolower((string) $v);
            default:
                return '';
        }
    }

    private function eval_rule_condition($pval, $cond, $rval)
    {
        $rval_lc = strtolower((string) $rval);
        $pval_s = (string) $pval;

        switch ($cond) {
            case 'equals':
                return $pval_s === $rval_lc;
            case 'not_equals':
                return $pval_s !== $rval_lc;
            case 'contains':
                return strpos($pval_s, $rval_lc) !== false;
            case 'not_contains':
                return strpos($pval_s, $rval_lc) === false;
            case 'starts_with':
                return strpos($pval_s, $rval_lc) === 0;
            case 'ends_with':
                return $rval_lc !== '' && substr($pval_s, -strlen($rval_lc)) === $rval_lc;
            case 'gt':
                return $pval !== null && is_numeric($pval) && (float) $pval > (float) $rval;
            case 'lt':
                return $pval !== null && is_numeric($pval) && (float) $pval < (float) $rval;
            case 'gte':
                return $pval !== null && is_numeric($pval) && (float) $pval >= (float) $rval;
            case 'lte':
                return $pval !== null && is_numeric($pval) && (float) $pval <= (float) $rval;
            case 'is_empty':
                return $pval === '' || $pval === null || $pval === false;
            case 'not_empty':
                return $pval !== '' && $pval !== null && $pval !== false;
            default:
                return false;
        }
    }
}
