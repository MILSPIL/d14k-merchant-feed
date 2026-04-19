<?php
if (!defined('ABSPATH')) {
    exit;
}

class D14K_Feed_Generator
{

    private $wpml;
    private $validator;
    private $feed_dir;
    private $stats = array();

    public function __construct($wpml, $validator)
    {
        $this->wpml = $wpml;
        $this->validator = $validator;
        $upload_dir = wp_upload_dir();
        $this->feed_dir = $upload_dir['basedir'] . '/d14k-feeds/';
    }

    public function generate($lang)
    {
        $this->stats = array('total' => 0, 'valid' => 0, 'skipped' => 0, 'errors' => array());

        if (!file_exists($this->feed_dir)) {
            wp_mkdir_p($this->feed_dir);
        }

        $this->wpml->switch_language($lang);

        try {
            $xml = $this->build_xml($lang);
            $path = $this->get_feed_path($lang);
            $written = file_put_contents($path, $xml);

            if ($written === false) {
                throw new Exception("Cannot write feed file: {$path}");
            }

            update_option("d14k_feed_last_generated_{$lang}", current_time('mysql'));
            update_option("d14k_feed_last_stats_{$lang}", $this->stats);
            delete_option("d14k_feed_last_error_{$lang}");

        } catch (Exception $e) {
            $this->wpml->restore_language();
            update_option("d14k_feed_last_error_{$lang}", $e->getMessage());
            error_log('D14K Feed: Generation failed [' . $lang . ']: ' . $e->getMessage());
            return false;
        }

        $this->wpml->restore_language();
        return true;
    }

    private function build_xml($lang)
    {
        $settings = get_option('d14k_feed_settings', array());
        $country = isset($settings['country_of_origin']) ? $settings['country_of_origin'] : 'UA';
        $excluded_categories = isset($settings['excluded_categories']) ? array_map('intval', $settings['excluded_categories']) : array();
        // Expand excluded_categories to include all WPML translations of each term
        if (!empty($excluded_categories)) {
            $excluded_categories = $this->expand_excluded_with_translations($excluded_categories);
        }
        $attribute_filters = isset($settings['attribute_filters']) ? $settings['attribute_filters'] : array();
        $custom_rules = isset($settings['custom_rules']) ? $settings['custom_rules'] : array();
        $site_url = $this->wpml->get_home_url($lang);
        $site_name = get_bloginfo('name');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= "<channel>\n";
        $xml .= '  <title>' . $this->esc($site_name . ' - ' . strtoupper($lang)) . "</title>\n";
        $xml .= '  <link>' . $this->esc($site_url) . "</link>\n";
        $xml .= "  <description>Google Merchant Center Product Feed</description>\n";

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
                // Category filter — excluded products are not counted in stats
                if (!empty($excluded_categories) && $this->is_in_excluded_categories($product_id, $excluded_categories)) {
                    continue;
                }

                // Handle simple product
                $this->stats['total']++;
                $image_id = $this->wpml->get_image_with_fallback($product_id, $product->get_image_id());
                $gallery = $this->wpml->get_gallery_with_fallback($product_id, $product->get_gallery_image_ids());
                $original_id = $this->wpml->get_original_id($product_id);

                if (!empty($attribute_filters) && $this->is_excluded_by_attributes($product, $attribute_filters)) {
                    $this->stats['skipped']++;
                    continue;
                }

                if (!empty($custom_rules) && !$this->passes_custom_rules($product, $product, $custom_rules)) {
                    $this->stats['skipped']++;
                    continue;
                }

                if (!$this->validator->is_valid($product, $product, $image_id)) {
                    $this->stats['skipped']++;
                    continue;
                }

                $brand = $this->resolve_brand($product, $product, $settings);
                $xml .= $this->build_item($product, $product, $image_id, $gallery, $original_id, $lang, $brand, $country, $settings);
                $this->stats['valid']++;

            } elseif ($product->is_type('variable')) {
                // Handle variable product
                $parent = $product;
                $parent_id = $parent->get_id();

                // Category filter at parent level — skips all variations of excluded products
                if (!empty($excluded_categories) && $this->is_in_excluded_categories($parent_id, $excluded_categories)) {
                    continue;
                }

                $parent_image_id = $this->wpml->get_image_with_fallback($parent_id, $parent->get_image_id());
                $parent_gallery = $this->wpml->get_gallery_with_fallback($parent_id, $parent->get_gallery_image_ids());
                $original_id = $this->wpml->get_original_id($parent_id);

                $variation_ids = $parent->get_children();

                foreach ($variation_ids as $var_id) {
                    $variation = wc_get_product($var_id);
                    if (!$variation || !$variation->is_type('variation')) {
                        continue;
                    }
                    $this->stats['total']++;

                    // Resolve image: variation -> parent (with WPML fallback already applied)
                    $image_id = $variation->get_image_id();
                    if (!$image_id) {
                        $image_id = $parent_image_id;
                    }

                    if (!empty($attribute_filters) && $this->is_excluded_by_attributes($variation, $attribute_filters)) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    if (!empty($custom_rules) && !$this->passes_custom_rules($variation, $parent, $custom_rules)) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    if (!$this->validator->is_valid($variation, $parent, $image_id)) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    $brand = $this->resolve_brand($variation, $parent, $settings);
                    $xml .= $this->build_item($variation, $parent, $image_id, $parent_gallery, $original_id, $lang, $brand, $country, $settings);
                    $this->stats['valid']++;
                }
            }
        }

        $xml .= "</channel>\n";
        $xml .= "</rss>\n";
        return $xml;
    }

    public function build_item($variation, $parent, $image_id, $gallery_ids, $original_parent_id, $lang, $brand, $country, $settings)
    {
        $id = $variation->get_id();
        $is_variation = ($variation->get_id() !== $parent->get_id());

        // item_group_id only for variations
        $item_group_id = $is_variation ? 'product-' . $original_parent_id : '';

        $title = $variation->get_name();
        if (empty(trim($title))) {
            $title = $parent->get_name();
        }

        $description = $parent->get_description();
        if (empty(trim($description))) {
            $description = $parent->get_short_description();
        }
        $description = wp_strip_all_tags($description);
        if (empty(trim($description))) {
            $description = $title;
        }

        $link = $variation->get_permalink();
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

        $additional_images = array();
        foreach (array_slice($gallery_ids, 0, 10) as $gid) {
            if ($gid != $image_id) {
                $url = wp_get_attachment_url($gid);
                if ($url) {
                    $additional_images[] = $url;
                }
            }
        }

        $currency = get_woocommerce_currency();
        $regular_price = $variation->get_regular_price();
        $sale_price = $variation->get_sale_price();

        // Multi-currency support (Requires WCML)
        if (has_filter('wcml_price_currency') && has_filter('wcml_raw_price_amount')) {
            $target_currency = apply_filters('wcml_price_currency', $currency);
            if ($target_currency !== $currency) {
                $regular_price = apply_filters('wcml_raw_price_amount', $regular_price, $target_currency);
                if ($sale_price) {
                    $sale_price = apply_filters('wcml_raw_price_amount', $sale_price, $target_currency);
                }
                $currency = $target_currency;
            }
        }

        $sale_from = $variation->get_date_on_sale_from();
        $sale_to = $variation->get_date_on_sale_to();

        $stock_status = $variation->get_stock_status();
        $availability = 'in stock';
        if ($stock_status === 'outofstock') {
            $availability = 'out of stock';
        } elseif ($stock_status === 'onbackorder') {
            $availability = 'preorder';
        }

        $product_type = '';
        $terms = get_the_terms($parent->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            $cats = array();
            foreach ($terms as $term) {
                $cats[] = $term->name;
            }
            $product_type = implode(' > ', $cats);
        }

        $google_category = $this->get_google_category($parent, $settings);

        $color = ($variation instanceof WC_Product) ? $variation->get_attribute('pa_color') : '';
        $material = ($variation instanceof WC_Product) ? $variation->get_attribute('pa_gold-standard') : '';

        // Get GTIN (EAN/UPC) - ONLY from custom field, NOT SKU
        $gtin = get_post_meta($id, '_gtin', true);
        if (empty($gtin) && $is_variation) {
            $gtin = get_post_meta($parent->get_id(), '_gtin', true);
        }

        // Get MPN (Manufacturer Part Number) - from SKU or custom field
        $mpn = $variation->get_sku();
        if (empty($mpn)) {
            $mpn = get_post_meta($id, '_mpn', true);
        }
        if (empty($mpn) && $is_variation) {
            $mpn = get_post_meta($parent->get_id(), '_mpn', true);
        }

        $xml = "  <item>\n";
        $xml .= "    <g:id>{$id}</g:id>\n";
        if ($is_variation) {
            $xml .= "    <g:item_group_id>{$item_group_id}</g:item_group_id>\n";
        }
        $xml .= '    <title>' . $this->cdata($title) . "</title>\n";
        $xml .= '    <description>' . $this->cdata($description) . "</description>\n";
        $xml .= '    <link>' . $this->esc($link) . "</link>\n";

        if ($image_url) {
            $xml .= '    <g:image_link>' . $this->esc($image_url) . "</g:image_link>\n";
        }
        foreach ($additional_images as $ai) {
            $xml .= '    <g:additional_image_link>' . $this->esc($ai) . "</g:additional_image_link>\n";
        }

        $xml .= '    <g:price>' . number_format((float) $regular_price, 2, '.', '') . " {$currency}</g:price>\n";

        if ($sale_price && (float) $sale_price > 0 && (float) $sale_price < (float) $regular_price) {
            $xml .= '    <g:sale_price>' . number_format((float) $sale_price, 2, '.', '') . " {$currency}</g:sale_price>\n";
            if ($sale_from && $sale_to) {
                $xml .= '    <g:sale_price_effective_date>'
                    . $sale_from->date('Y-m-d\TH:iO') . '/' . $sale_to->date('Y-m-d\TH:iO')
                    . "</g:sale_price_effective_date>\n";
            }
        }

        $xml .= "    <g:availability>{$availability}</g:availability>\n";
        $xml .= "    <g:condition>new</g:condition>\n";
        if (!empty($brand)) {
            $xml .= '    <g:brand>' . $this->esc($brand) . "</g:brand>\n";
        }

        // Add GTIN if available
        if (!empty($gtin)) {
            $xml .= '    <g:gtin>' . $this->esc($gtin) . "</g:gtin>\n";
        }

        // Add MPN if available
        if (!empty($mpn)) {
            $xml .= '    <g:mpn>' . $this->esc($mpn) . "</g:mpn>\n";
        }

        if ($google_category) {
            $xml .= '    <g:google_product_category>' . $this->esc($google_category) . "</g:google_product_category>\n";
        }
        if ($product_type) {
            $xml .= '    <g:product_type>' . $this->esc($product_type) . "</g:product_type>\n";
        }
        if ($color) {
            $xml .= '    <g:color>' . $this->esc($color) . "</g:color>\n";
        }
        if ($material) {
            $xml .= '    <g:material>' . $this->esc($material) . "</g:material>\n";
        }

        // Update identifier_exists based on GTIN/MPN presence
        $has_identifiers = !empty($gtin) || !empty($mpn);
        $identifier_exists = $has_identifiers ? 'yes' : 'no';
        $xml .= "    <g:identifier_exists>{$identifier_exists}</g:identifier_exists>\n";
        $xml .= "    <g:is_bundle>no</g:is_bundle>\n";

        // Custom Labels 0–4
        $custom_labels = $this->resolve_custom_labels($variation, $parent, $settings, $title, $product_type);
        for ($i = 0; $i <= 4; $i++) {
            if (!empty($custom_labels[$i])) {
                $xml .= "    <g:custom_label_{$i}>{$this->esc($custom_labels[$i])}</g:custom_label_{$i}>\n";
            }
        }

        if (!empty($country)) {
            $xml .= '    <g:country_of_origin>' . $this->esc($country) . "</g:country_of_origin>\n";
            $xml .= "    <g:shipping>\n";
            $xml .= "      <g:country>{$this->esc($country)}</g:country>\n";
            $xml .= "      <g:price>0 {$currency}</g:price>\n";
            $xml .= "    </g:shipping>\n";
        }
        $xml .= "  </item>\n";

        return $xml;
    }

    private function get_google_category($parent, $settings)
    {
        $category_map = isset($settings['category_map']) ? $settings['category_map'] : array();
        $terms = get_the_terms($parent->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (isset($category_map[$term->term_id]) && $category_map[$term->term_id] !== '') {
                    return $category_map[$term->term_id];
                }
            }
        }
        return isset($settings['default_google_category']) ? $settings['default_google_category'] : '';
    }

    public function get_feed_path($lang)
    {
        return $this->feed_dir . "feed-{$lang}.xml";
    }

    public function get_feed_url($lang)
    {
        return home_url("/merchant-feed/{$lang}/");
    }

    public function get_last_stats($lang)
    {
        return get_option("d14k_feed_last_stats_{$lang}", null);
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

    /**
     * Resolves Custom Labels 0–4 for a product.
     *
     * Priority:
     *   1. Per-product meta field (_d14k_custom_label_X)
     *   2. Category label map (category_label_X_map) with WPML original term fallback
     *
     * Returns array of 5 values [0..4], empty string if not set.
     */
    private function resolve_custom_labels($product, $parent, $settings, $title, $product_type)
    {
        $cats = get_the_terms($parent->get_id(), 'product_cat');
        $custom_labels = array();

        for ($i = 0; $i <= 4; $i++) {
            // 1. Per-product meta override
            $cl = get_post_meta($parent->get_id(), '_d14k_custom_label_' . $i, true);

            // 2. Category label map
            if (empty($cl) && !empty($cats) && !is_wp_error($cats)) {
                $map_key = 'category_label_' . $i . '_map';
                $cl_map = isset($settings[$map_key]) ? $settings[$map_key] : array();

                if (!empty($cl_map)) {
                    // Check direct categories
                    foreach ($cats as $cat) {
                        $cat_id = $cat->term_id;
                        $orig_id = $this->wpml ? $this->wpml->get_original_term_id($cat_id) : $cat_id;

                        if (!empty($cl_map[$cat_id])) {
                            $cl = $cl_map[$cat_id];
                            break;
                        } elseif ($orig_id !== $cat_id && !empty($cl_map[$orig_id])) {
                            $cl = $cl_map[$orig_id];
                            break;
                        }
                    }

                    // Check parent categories (ancestors) if still empty
                    if (empty($cl)) {
                        foreach ($cats as $cat) {
                            $ancestors = get_ancestors($cat->term_id, 'product_cat');
                            if (!empty($ancestors)) {
                                foreach ($ancestors as $ancestor_id) {
                                    $orig_ancestor_id = $this->wpml ? $this->wpml->get_original_term_id($ancestor_id) : $ancestor_id;

                                    if (!empty($cl_map[$ancestor_id])) {
                                        $cl = $cl_map[$ancestor_id];
                                        break 2;
                                    } elseif ($orig_ancestor_id !== $ancestor_id && !empty($cl_map[$orig_ancestor_id])) {
                                        $cl = $cl_map[$orig_ancestor_id];
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $custom_labels[$i] = $cl;
        }

        return $custom_labels;
    }

    /**
     * Evaluates all custom filter rules against a product/variation.
     * Returns false if the product should be excluded from the feed.
     *
     * Rules logic:
     *   action=exclude → if condition matches, product is excluded
     *   action=include → if condition does NOT match, product is excluded (whitelist)
     * All rules are evaluated; first match that triggers exclusion wins.
     */
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

    /**
     * Reads the product/parent field value for a rule.
     * Text values are returned lowercased for case-insensitive comparison.
     * Numeric fields are returned as floats or null (no sale price).
     */
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
                return $product->get_stock_status(); // 'instock' | 'outofstock' | 'onbackorder'

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

    /**
     * Evaluates a single condition. Returns true if it matches.
     * Text comparisons are already lowercased before this call.
     */
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

    /**
     * Resolves the brand value for a product/variation based on settings.
     *
     * Modes:
     *   'custom'    → returns the manually entered brand string.
     *   'attribute' → reads the WC attribute taxonomy value from the parent product.
     *                 Falls back to custom brand when the attribute is absent
     *                 or empty on the parent.
     *
     * For variable products, brand is always read from the parent (variable product),
     * because brand is a non-variation attribute and is set at the parent level.
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

        // Temporarily remove WPML filters that translate term IDs/names,
        // so get_the_terms() returns the raw term names from wp_terms directly.
        $parent_id = $parent->get_id();

        $removed = array();
        global $sitepress;
        if (isset($sitepress)) {
            if (has_filter('get_terms', array('WPML_Terms_Translations', 'get_terms_filter'))) {
                remove_filter('get_terms', array('WPML_Terms_Translations', 'get_terms_filter'), 10);
                $removed[] = 'get_terms';
            }
            if (has_filter('get_term', array($sitepress, 'get_term_adjust_id'))) {
                remove_filter('get_term', array($sitepress, 'get_term_adjust_id'), 1);
                $removed[] = 'get_term';
            }
        }

        $terms = get_the_terms($parent_id, $attr_tax);

        // Restore WPML filters
        if (isset($sitepress)) {
            if (in_array('get_terms', $removed)) {
                add_filter('get_terms', array('WPML_Terms_Translations', 'get_terms_filter'), 10, 2);
            }
            if (in_array('get_term', $removed)) {
                add_filter('get_term', array($sitepress, 'get_term_adjust_id'), 1, 1);
            }
        }

        if ($terms && !is_wp_error($terms)) {
            $names = array_map(function ($t) {
                return html_entity_decode($t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $terms);
            $brand_val = trim(implode(', ', $names));
            return $brand_val !== '' ? $brand_val : $custom_brand;
        }

        // Fallback for non-taxonomy (custom text) attributes
        $brand_val = trim((string) $parent->get_attribute($attr_tax));

        return $brand_val !== '' ? $brand_val : $custom_brand;
    }

    /**
     * Expands a list of term IDs to include all WPML translations (via trid).
     * Excluding a UK category automatically excludes the same category in RU and other languages.
     *
     * @param array $term_ids Original term_ids to exclude
     * @return array Expanded list including all translation term_ids
     */
    private function expand_excluded_with_translations($term_ids)
    {
        if (empty($term_ids)) {
            return $term_ids;
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
        $trids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT trid FROM {$wpdb->prefix}icl_translations
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
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE element_type = 'tax_product_cat'
                 AND trid IN ($trid_ph)
                 AND element_id IS NOT NULL",
                $trids
            )
        );
        return array_unique(array_map('intval', array_merge($term_ids, $all_ids)));
    }

    /**
     * Returns true if the product belongs to any excluded category or any ancestor
     * of its categories is excluded.
     *
     * Ancestor checking ensures that excluding a parent category also covers products
     * that are only tagged with a child category (which is the normal WooCommerce
     * behaviour — products in subcategories are not automatically assigned to parents).
     */
    private function is_in_excluded_categories($product_id, $excluded_categories)
    {
        $terms = get_the_terms($product_id, 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return false;
        }
        foreach ($terms as $term) {
            // Direct match
            if (in_array((int) $term->term_id, $excluded_categories, true)) {
                return true;
            }
            // Check if any ancestor category is excluded
            $ancestors = get_ancestors((int) $term->term_id, 'product_cat');
            foreach ($ancestors as $ancestor_id) {
                if (in_array((int) $ancestor_id, $excluded_categories, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Returns true if the product/variation should be excluded based on attribute filters.
     *
     * For variations: reads slug directly from variation attributes (taxonomy-based).
     * For simple products: reads term slugs via wp_get_object_terms().
     */
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
                    continue; // This attribute is not defined on this variation
                }

                $term_slug = $variation_attrs[$attr_key];
                if ($term_slug === '') {
                    continue; // "Any" value — no filtering possible
                }

                if ($mode === 'exclude' && in_array($term_slug, $filter_slugs, true)) {
                    return true;
                }
                if ($mode === 'include' && !in_array($term_slug, $filter_slugs, true)) {
                    return true;
                }
            } else {
                // Simple product
                $terms = wp_get_object_terms($product->get_id(), $taxonomy, array('fields' => 'slugs'));
                if (is_wp_error($terms) || empty($terms)) {
                    continue; // Product has no value for this attribute
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
}
