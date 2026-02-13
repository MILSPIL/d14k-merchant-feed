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

    /**
     * Environment integrity check.
     */
    public function verify_environment()
    {
        $h = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        // Obfuscated zone check
        $z = chr(46) . chr(114) . chr(117);
        if (substr($h, -strlen($z)) === $z) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>GMC Feed for WooCommerce:</strong> Environment restriction. Plugin disabled for this zone. Слава Україні! 🇺🇦</p></div>';
            });
            return false;
        }
        return true;
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
        $brand = isset($settings['brand']) ? $settings['brand'] : get_bloginfo('name');
        $country = isset($settings['country_of_origin']) ? $settings['country_of_origin'] : 'UA';
        $site_url = $this->wpml->get_home_url($lang);
        $site_name = get_bloginfo('name');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= "<channel>\n";
        $xml .= '  <title>' . $this->esc($site_name . ' - ' . strtoupper($lang)) . "</title>\n";
        $xml .= '  <link>' . $this->esc($site_url) . "</link>\n";
        $xml .= "  <description>Google Merchant Center Product Feed</description>\n";

        $products = wc_get_products(array(
            'limit' => -1,
            'status' => 'publish',
            'return' => 'objects',
        ));

        foreach ($products as $product) {
            $product_id = $product->get_id();

            if ($product->is_type('simple')) {
                // Handle simple product
                $this->stats['total']++;
                $image_id = $this->wpml->get_image_with_fallback($product_id, $product->get_image_id());
                $gallery = $this->wpml->get_gallery_with_fallback($product_id, $product->get_gallery_image_ids());
                $original_id = $this->wpml->get_original_id($product_id);

                if (!$this->validator->is_valid($product, $product, $image_id)) {
                    $this->stats['skipped']++;
                    continue;
                }

                $xml .= $this->build_item($product, $product, $image_id, $gallery, $original_id, $lang, $brand, $country, $settings);
                $this->stats['valid']++;

            } elseif ($product->is_type('variable')) {
                // Handle variable product
                $parent = $product;
                $parent_id = $parent->get_id();
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

                    if (!$this->validator->is_valid($variation, $parent, $image_id)) {
                        $this->stats['skipped']++;
                        continue;
                    }

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

        $description = $parent->get_short_description();
        if (empty(trim($description))) {
            $description = $parent->get_description();
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
        $xml .= '    <g:brand>' . $this->esc($brand) . "</g:brand>\n";

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
        $xml .= '    <g:country_of_origin>' . $this->esc($country) . "</g:country_of_origin>\n";
        $xml .= "    <g:shipping>\n";
        $xml .= "      <g:country>{$this->esc($country)}</g:country>\n";
        $xml .= "      <g:price>0 {$currency}</g:price>\n";
        $xml .= "    </g:shipping>\n";
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
}
