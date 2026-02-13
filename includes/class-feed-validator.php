<?php
if (!defined('ABSPATH')) {
    exit;
}

class D14K_Feed_Validator
{

    private $errors = array();

    /**
     * Validate a product variation for Google Merchant Center.
     *
     * @param object $variation WC_Product_Variation
     * @param object $parent   WC_Product_Variable
     * @param int    $resolved_image_id  Pre-resolved image ID (with WPML fallback applied)
     * @return bool
     */
    public function is_valid($variation, $parent, $resolved_image_id = 0)
    {
        $this->errors = array();
        $id = $variation->get_id();

        if (!is_a($variation, 'WC_Product_Variation')) {
            $this->errors[$id][] = 'Not a valid WC_Product_Variation';
            return false;
        }

        if (!$parent || !$parent->get_id()) {
            $this->errors[$id][] = 'Parent product missing';
            return false;
        }

        $title = $variation->get_name();
        if (empty(trim($title))) {
            $title = $parent->get_name();
        }
        if (empty(trim($title))) {
            $this->errors[$id][] = 'Empty title';
            return false;
        }

        $price = (float) $variation->get_regular_price();
        if ($price <= 0) {
            $price = (float) $variation->get_price();
        }
        if ($price <= 0) {
            $this->errors[$id][] = 'Price is 0 or negative';
            return false;
        }

        if (!$resolved_image_id) {
            $this->errors[$id][] = 'No image';
            return false;
        }

        $url = $variation->get_permalink();
        if (empty($url) || strpos($url, '?product_variation=') !== false) {
            $this->errors[$id][] = 'Invalid URL: ' . $url;
            return false;
        }

        return true;
    }

    public function get_errors()
    {
        return $this->errors;
    }

    public function get_error_log()
    {
        $lines = array();
        foreach ($this->errors as $id => $errs) {
            foreach ($errs as $err) {
                $lines[] = "ID {$id}: {$err}";
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Validate test feed (first 10 products) with detailed reporting.
     *
     * @param D14K_WPML_Handler $wpml
     * @param array $settings
     * @return array Validation report
     */
    public function validate_test_feed($wpml, $settings, $generator = null)
    {
        $report = array(
            'total_products' => 0,
            'valid_products' => 0,
            'invalid_products' => 0,
            'fields_stats' => array(),
            'products' => array(),
            'sample_item_xml' => '',
        );

        $required_fields = $this->get_required_fields();

        // Initialize field statistics
        foreach ($required_fields as $field => $label) {
            $report['fields_stats'][$field] = array(
                'label' => $label,
                'present' => 0,
                'missing' => 0,
                'required' => true,
            );
        }

        $languages = $wpml->get_active_languages();
        if (empty($languages)) {
            $languages = array('default');
        }

        $lang = $languages[0];
        $wpml->switch_language($lang);

        $products = wc_get_products(array(
            'limit' => 5,
            'status' => 'publish',
        ));

        $count = 0;
        foreach ($products as $product) {
            if ($count >= 10) {
                break;
            }

            if ($product->is_type('simple')) {
                $product_data = $this->validate_product_fields($product, $product, $settings);
                $report['products'][] = $product_data;
                $report['total_products']++;

                if ($product_data['is_valid']) {
                    $report['valid_products']++;

                    // Generate sample XML for the first valid product
                    if (empty($report['sample_item_xml']) && $generator) {
                        $image_id = $wpml->get_image_with_fallback($product->get_id(), $product->get_image_id());
                        $gallery_ids = $wpml->get_gallery_with_fallback($product->get_id(), $product->get_gallery_image_ids());
                        $original_id = $wpml->get_original_id($product->get_id());

                        $report['sample_item_xml'] = $generator->build_item(
                            $product,
                            $product,
                            $image_id,
                            $gallery_ids,
                            $original_id,
                            $lang,
                            isset($settings['brand']) ? $settings['brand'] : get_bloginfo('name'),
                            isset($settings['country_of_origin']) ? $settings['country_of_origin'] : 'UA',
                            $settings
                        );
                    }
                } else {
                    $report['invalid_products']++;
                }

                $this->update_field_stats($report, $required_fields, $product_data);
                $count++;

            } elseif ($product->is_type('variable')) {
                $parent = $product;
                $variations = $parent->get_children();
                foreach ($variations as $var_id) {
                    if ($count >= 10) {
                        break;
                    }

                    $variation = wc_get_product($var_id);
                    if (!$variation) {
                        continue;
                    }

                    $product_data = $this->validate_product_fields($variation, $parent, $settings);
                    $report['products'][] = $product_data;
                    $report['total_products']++;

                    if ($product_data['is_valid']) {
                        $report['valid_products']++;

                        // Generate sample XML for the first valid product
                        if (empty($report['sample_item_xml']) && $generator) {
                            $image_id = $wpml->get_image_with_fallback($variation->get_id(), $variation->get_image_id());
                            if (!$image_id) {
                                $image_id = $wpml->get_image_with_fallback($parent->get_id(), $parent->get_image_id());
                            }

                            $gallery_ids = $wpml->get_gallery_with_fallback($parent->get_id(), $parent->get_gallery_image_ids());
                            $original_parent_id = $wpml->get_original_id($parent->get_id());

                            $report['sample_item_xml'] = $generator->build_item(
                                $variation,
                                $parent,
                                $image_id,
                                $gallery_ids,
                                $original_parent_id,
                                $lang,
                                isset($settings['brand']) ? $settings['brand'] : get_bloginfo('name'),
                                isset($settings['country_of_origin']) ? $settings['country_of_origin'] : 'UA',
                                $settings
                            );
                        }
                    } else {
                        $report['invalid_products']++;
                    }

                    $this->update_field_stats($report, $required_fields, $product_data);
                    $count++;
                }
            }
        }

        $wpml->restore_language();

        return $report;
    }

    /**
     * Validate all required fields for a single product.
     *
     * @param WC_Product_Variation $variation
     * @param WC_Product_Variable $parent
     * @param array $settings
     * @return array Product validation data
     */
    private function validate_product_fields($variation, $parent, $settings)
    {
        $id = $variation->get_id();
        $data = array(
            'id' => $id,
            'title' => '',
            'is_valid' => true,
            'missing_fields' => array(),
            'present_fields' => array(),
            'warnings' => array(),
        );

        $required_fields = $this->get_required_fields();

        // Check ID
        if ($id > 0) {
            $data['present_fields'][] = 'id';
        } else {
            $data['missing_fields'][] = 'id';
            $data['is_valid'] = false;
        }

        // Check title
        $title = $variation->get_name();
        if (empty(trim($title))) {
            $title = $parent->get_name();
        }
        $data['title'] = $title;

        if (!empty(trim($title))) {
            $data['present_fields'][] = 'title';
        } else {
            $data['missing_fields'][] = 'title';
            $data['is_valid'] = false;
        }

        // Check description
        $desc = $variation->get_description();
        if (empty($desc)) {
            $desc = $parent->get_description();
        }
        if (!empty(trim($desc))) {
            $data['present_fields'][] = 'description';
        } else {
            $data['missing_fields'][] = 'description';
            $data['is_valid'] = false;
        }

        // Check link
        $link = $variation->get_permalink();
        if (!empty($link) && strpos($link, '?product_variation=') === false) {
            $data['present_fields'][] = 'link';
        } else {
            $data['missing_fields'][] = 'link';
            $data['is_valid'] = false;
        }

        // Check image
        $image_id = $variation->get_image_id();
        if (!$image_id) {
            $image_id = $parent->get_image_id();
        }
        if ($image_id) {
            $data['present_fields'][] = 'image_link';
        } else {
            $data['missing_fields'][] = 'image_link';
            $data['is_valid'] = false;
        }

        // Check price
        $price = (float) $variation->get_regular_price();
        if ($price <= 0) {
            $price = (float) $variation->get_price();
        }
        if ($price > 0) {
            $data['present_fields'][] = 'price';
        } else {
            $data['missing_fields'][] = 'price';
            $data['is_valid'] = false;
        }

        // Check availability
        $stock = $variation->get_stock_status();
        if (!empty($stock)) {
            $data['present_fields'][] = 'availability';
        } else {
            $data['missing_fields'][] = 'availability';
            $data['is_valid'] = false;
        }

        // Check condition (always 'new')
        $data['present_fields'][] = 'condition';

        // Check brand
        $brand = isset($settings['brand']) ? $settings['brand'] : '';
        if (!empty($brand)) {
            $data['present_fields'][] = 'brand';
        } else {
            $data['missing_fields'][] = 'brand';
            $data['is_valid'] = false;
        }

        // Check GTIN (ONLY from custom field)
        $gtin = get_post_meta($id, '_gtin', true);
        if (empty($gtin)) {
            $gtin = get_post_meta($parent->get_id(), '_gtin', true);
        }
        if (!empty($gtin)) {
            $data['present_fields'][] = 'gtin';
        } else {
            $data['missing_fields'][] = 'gtin';
            // Warning only if MPN is also missing, or just a general notice?
            // Actually, for GMC, if identifier_exists=yes (which it will be if MPN is there), 
            // and we don't have GTIN, that's fine as long as we don't send TRASH in GTIN.
            // So this is just reporting "Missing GTIN", which is accurate.
            $data['warnings'][] = 'GTIN відсутній (необов\'язково, якщо є MPN+Brand)';
        }

        // Check MPN (from SKU or custom field)
        $mpn = $variation->get_sku();
        if (empty($mpn)) {
            $mpn = get_post_meta($id, '_mpn', true);
        }
        if (empty($mpn)) {
            $mpn = get_post_meta($parent->get_id(), '_mpn', true);
        }
        if (!empty($mpn)) {
            $data['present_fields'][] = 'mpn';
        } else {
            $data['missing_fields'][] = 'mpn';
            $data['warnings'][] = 'MPN відсутній - додайте SKU або custom field _mpn';
        }

        // Check google_product_category
        $google_cat = '';
        $category_map = isset($settings['category_map']) ? $settings['category_map'] : array();
        $terms = get_the_terms($parent->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (isset($category_map[$term->term_id]) && $category_map[$term->term_id] !== '') {
                    $google_cat = $category_map[$term->term_id];
                    break;
                }
            }
        }
        if (empty($google_cat)) {
            $google_cat = isset($settings['default_google_category']) ? $settings['default_google_category'] : '';
        }
        if (!empty($google_cat)) {
            $data['present_fields'][] = 'google_product_category';
        } else {
            $data['missing_fields'][] = 'google_product_category';
            $data['is_valid'] = false;
        }

        // Check country_of_origin
        $country = isset($settings['country_of_origin']) ? $settings['country_of_origin'] : 'UA';
        if (!empty($country)) {
            $data['present_fields'][] = 'country_of_origin';
        } else {
            $data['missing_fields'][] = 'country_of_origin';
            $data['is_valid'] = false;
        }

        return $data;
    }

    /**
     * Get list of required fields for GMC.
     *
     * @return array
     */
    private function get_required_fields()
    {
        return array(
            'id' => 'ID товару',
            'title' => 'Назва',
            'description' => 'Опис',
            'link' => 'Посилання',
            'image_link' => 'Зображення',
            'price' => 'Ціна',
            'availability' => 'Наявність',
            'condition' => 'Стан',
            'brand' => 'Бренд',
            'gtin' => 'GTIN (штрих-код)',
            'mpn' => 'MPN (артикул)',
            'google_product_category' => 'Google Category',
            'country_of_origin' => 'Країна походження',
        );
    }

    private function update_field_stats(&$report, $required_fields, $product_data)
    {
        foreach ($required_fields as $field => $label) {
            if (in_array($field, $product_data['present_fields'])) {
                $report['fields_stats'][$field]['present']++;
            } else {
                $report['fields_stats'][$field]['missing']++;
            }
        }
    }
}
