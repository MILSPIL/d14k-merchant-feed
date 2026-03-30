<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSV Feed Generator for Horoshop.
 *
 * Generates a full CSV feed with proper category paths (Раздел),
 * bilingual descriptions, and dynamic attribute columns.
 *
 * @since 3.0.0
 */
class D14K_CSV_Generator
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
    const CHANNELS = array('horoshop');

    public function __construct($wpml, $validator)
    {
        $this->wpml = $wpml;
        $this->validator = $validator;
        $upload_dir = wp_upload_dir();
        $this->feed_dir = $upload_dir['basedir'] . '/d14k-feeds/';
    }

    /**
     * Get paths for the generated feeds.
     */
    public function get_feed_path($channel)
    {
        return $this->feed_dir . $channel . '-feed.csv';
    }

    public function get_feed_url($channel)
    {
        return home_url("/marketplace-feed/{$channel}/");
    }

    public function get_last_stats($channel)
    {
        return get_option("d14k_csv_last_stats_{$channel}", null);
    }

    public function get_last_generated($channel)
    {
        return get_option("d14k_csv_last_generated_{$channel}", null);
    }

    public function get_last_error($channel)
    {
        return get_option("d14k_csv_last_error_{$channel}", null);
    }

    /**
     * Generate a single bilingual CSV feed for Horoshop.
     *
     * @param string $channel Channel name (horoshop)
     * @return bool Success
     */
    public function generate($channel)
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            error_log("D14K CSV: Unknown channel '{$channel}'");
            return false;
        }

        $this->stats = array('total' => 0, 'valid' => 0, 'skipped' => 0, 'errors' => array());

        if (!file_exists($this->feed_dir)) {
            wp_mkdir_p($this->feed_dir);
        }

        $this->wpml->switch_language('ru');

        try {
            $settings = get_option('d14k_feed_settings', array());
            $this->settings = $settings;

            $excluded_categories = isset($settings['excluded_categories']) ? array_map('intval', $settings['excluded_categories']) : array();
            if (!empty($excluded_categories) && method_exists($this, 'expand_excluded_with_translations')) {
                $excluded_categories = $this->expand_excluded_with_translations($excluded_categories);
            }
            $attribute_filters = isset($settings['attribute_filters']) ? $settings['attribute_filters'] : array();

            $rows = array();
            $headers = array(
                'Артикул',
                'Родительский артикул',
                'Название модификации (RU)',
                'Название модификации (UA)',
                'Описание товара (RU)',
                'Описание товара (UA)',
                'Короткое описание (RU)',
                'Короткое описание (UA)',
                'Цена',
                'Старая цена',
                'Наличие',
                'Раздел',
                'Бренд',
                'Фото'
            );

            // Fetch products
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



                    if (!$image_id) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    $brand = $this->resolve_brand($product, $product, $settings);
                    $row = $this->build_row($product, $product, $image_id, $gallery, $brand, $settings);

                    // Add any dynamic parameter columns to headers
                    foreach (array_keys($row) as $key) {
                        if (!in_array($key, $headers, true)) {
                            $headers[] = $key;
                        }
                    }

                    $rows[] = $row;
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



                        $brand = $this->resolve_brand($variation, $parent, $settings);
                        $row = $this->build_row($variation, $parent, $image_id, $parent_gallery, $brand, $settings);

                        foreach (array_keys($row) as $key) {
                            if (!in_array($key, $headers, true)) {
                                $headers[] = $key;
                            }
                        }

                        $rows[] = $row;
                        $this->stats['valid']++;
                    }
                }
            }

            // Write to CSV
            $path = $this->get_feed_path($channel);
            $fp = fopen($path, 'w');
            if ($fp === false) {
                throw new Exception("Cannot open CSV for writing: {$path}");
            }

            // Add BOM for Excel UTF-8 display
            fputs($fp, "\xEF\xBB\xBF");

            // Write headers (using semicolon as commonly preferred by Horoshop/Excel in Ukraine by default)
            fputcsv($fp, $headers, ';');

            // Write rows
            foreach ($rows as $row) {
                $csv_row = array();
                foreach ($headers as $header) {
                    $csv_row[] = isset($row[$header]) ? $row[$header] : '';
                }
                fputcsv($fp, $csv_row, ';');
            }

            fclose($fp);

            update_option("d14k_csv_last_generated_{$channel}", current_time('mysql'));
            update_option("d14k_csv_last_stats_{$channel}", $this->stats);
            delete_option("d14k_csv_last_error_{$channel}");

        } catch (Exception $e) {
            $this->wpml->restore_language();
            update_option("d14k_csv_last_error_{$channel}", $e->getMessage());
            error_log('D14K CSV: Generation failed [' . $channel . ']: ' . $e->getMessage());
            return false;
        }

        $this->wpml->restore_language();
        return true;
    }

    private function build_row($product, $parent, $image_id, $gallery_ids, $brand, $settings)
    {
        $id = $product->get_id();
        $is_variation = ($product->get_id() !== $parent->get_id());

        $row = array();

        // Артикул
        $sku = $product->get_sku();
        if (empty($sku) && $is_variation) {
            $sku = $parent->get_sku() . '-' . $id;
        }
        $row['Артикул'] = !empty($sku) ? $sku : $id;

        // Родительский артикул
        if ($is_variation) {
            $parent_sku = $parent->get_sku();
            $row['Родительский артикул'] = !empty($parent_sku) ? $parent_sku : $parent->get_id();
        } else {
            $row['Родительский артикул'] = '';
        }

        // Название модификации
        $title = $product->get_name();
        if (empty(trim($title))) {
            $title = $parent->get_name();
        }
        $row['Название модификации (RU)'] = $title;

        $ua_title = $this->get_translated_value($parent, $product, 'title', 'uk');
        $row['Название модификации (UA)'] = ($ua_title && $ua_title !== $title) ? $ua_title : '';

        // Описание товара
        $description = $parent->get_description();
        if (empty(trim($description))) {
            $description = $parent->get_short_description();
        }
        if (empty(trim($description))) {
            $description = $title;
        }
        $row['Описание товара (RU)'] = wp_strip_all_tags($description);

        $ua_desc = $this->strip_newlines($this->get_translated_value($parent, $product, 'description', 'uk'));
        $row['Описание товара (UA)'] = !empty($ua_desc) ? wp_strip_all_tags($ua_desc) : '';

        // Короткое описание
        $short_desc = $parent->get_short_description();
        $row['Короткое описание (RU)'] = !empty($short_desc) ? wp_strip_all_tags($short_desc) : '';

        $ua_short_desc = $this->strip_newlines($this->get_translated_value($parent, $product, 'short_description', 'uk'));
        $row['Короткое описание (UA)'] = !empty($ua_short_desc) ? wp_strip_all_tags($ua_short_desc) : '';

        // Prices (Хорошоп: Цена = поточна ціна продажу, Старая цена = перекреслена)
        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();

        if ($sale_price && (float) $sale_price > 0 && (float) $sale_price < (float) $regular_price) {
            $row['Цена'] = $this->format_price($sale_price);
            $row['Старая цена'] = $this->format_price($regular_price);
        } else {
            $row['Цена'] = $this->format_price($regular_price);
            $row['Старая цена'] = '';
        }

        // Stock
        $stock_status = $product->get_stock_status();
        $row['Наличие'] = ($stock_status === 'instock') ? 'В наличии' : 'Нет в наличии';

        // Category Path
        $row['Раздел'] = $this->get_category_path($parent);

        // Brand
        $row['Бренд'] = $brand;

        // Фото: всі зображення в одній колонці через ';' (перше = головне, решта = галерея)
        $all_photos = array();
        $image_url = $image_id ? $this->maybe_convert_webp_url(wp_get_attachment_url($image_id)) : '';
        if ($image_url) {
            $all_photos[] = $image_url;
        }

        $max_photos = 15;
        foreach (array_slice($gallery_ids, 0, $max_photos) as $gid) {
            if ($gid != $image_id) {
                $img_url = $this->maybe_convert_webp_url(wp_get_attachment_url($gid));
                if ($img_url) {
                    $all_photos[] = $img_url;
                }
            }
        }
        $row['Фото'] = implode(';', $all_photos);

        // Parameters
        $params = $this->get_product_params($product, $parent, $settings);
        foreach ($params as $param) {
            // Horoshop convention: Характеристика: Имя
            $col_name = 'Характеристика: ' . $param['name'];
            if (!empty($param['unit'])) {
                $row[$col_name] = $param['value'] . ' ' . $param['unit'];
            } else {
                $row[$col_name] = $param['value'];
            }
        }

        return $row;
    }

    private function get_category_path($product, $lang = 'ru')
    {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return '';
        }

        $deepest = null;
        $max_depth = -1;
        foreach ($terms as $term) {
            $ancestors = get_ancestors($term->term_id, 'product_cat');
            $depth = count($ancestors);
            if ($depth > $max_depth) {
                $max_depth = $depth;
                $deepest = $term;
            }
        }

        if (!$deepest) {
            $deepest = $terms[0];
        }

        $ancestors = array_reverse(get_ancestors($deepest->term_id, 'product_cat'));
        $path = array();

        foreach ($ancestors as $ancestor_id) {
            $term = get_term($ancestor_id, 'product_cat');
            if ($term) {
                $path[] = $term->name;
            }
        }
        $path[] = $deepest->name;

        // Assuming path uses "/" as separator for Horoshop categories.
        return implode('/', $path);
    }

    private function format_price($price)
    {
        return $price ? number_format((float) $price, 2, '.', '') : '';
    }

    private function maybe_convert_webp_url($url)
    {
        if (!$url)
            return '';
        $settings = $this->settings;
        $should_convert = !empty($settings['webp_to_jpg']);

        if ($should_convert && preg_match('/\.webp$/i', $url)) {
            $url = preg_replace('/\.webp$/i', '.jpg', $url);
        }
        return $url;
    }

    private function resolve_brand($product, $parent, $settings)
    {
        // Re-use logical from YML Generator
        $mode = isset($settings['brand_mode']) ? $settings['brand_mode'] : 'custom';
        if ($mode === 'attribute' && !empty($settings['brand_attribute'])) {
            $attr = $settings['brand_attribute'];
            $val = $product->get_attribute($attr);
            if (empty($val) && $product->is_type('variation')) {
                $val = $parent->get_attribute($attr);
            }
            if (!empty($val)) {
                return $val;
            }
        }
        return isset($settings['brand_custom']) ? $settings['brand_custom'] : '';
    }

    // Import helper functions that existed natively in D14K_YML_Generator
    private function is_in_excluded_categories($product_id, $excluded)
    {
        $terms = get_the_terms($product_id, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (in_array($term->term_id, $excluded)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function expand_excluded_with_translations($excluded_categories)
    {
        $expanded = $excluded_categories;
        if (function_exists('apply_filters')) {
            foreach ($excluded_categories as $cat_id) {
                $tr_id = apply_filters('wpml_object_id', $cat_id, 'product_cat', false, 'uk');
                if ($tr_id) {
                    $expanded[] = $tr_id;
                }
            }
        }
        return array_unique($expanded);
    }

    private function is_excluded_by_attributes($product, $filters)
    {
        $lines = array_map('trim', explode("\n", $filters));
        foreach ($lines as $line) {
            if (empty($line))
                continue;
            $parts = array_map('trim', explode(':', $line, 2));
            if (count($parts) === 2) {
                $attr = $parts[0];
                $val = strtolower($parts[1]);
                $product_val = strtolower((string) $product->get_attribute($attr));
                if ($product_val === $val || strpos($product_val, $val . ',') !== false || strpos($product_val, ', ' . $val) !== false) {
                    return true;
                }
            }
        }
        return false;
    }



    private function get_product_params($product, $parent, $settings = array())
    {
        $params = array();
        $attributes = $parent->get_attributes();

        $brand_mode = isset($settings['brand_mode']) ? $settings['brand_mode'] : 'custom';
        $brand_attr = isset($settings['brand_attribute']) ? $settings['brand_attribute'] : '';

        foreach ($attributes as $attr_key => $attribute) {
            $name = '';
            $values = array();

            if ($attribute instanceof WC_Product_Attribute) {
                if ($attribute->is_taxonomy()) {
                    $taxonomy = $attribute->get_taxonomy();
                    if ($brand_mode === 'attribute' && $taxonomy === $brand_attr) {
                        continue;
                    }
                    $tax_obj = get_taxonomy($taxonomy);
                    $name = $tax_obj ? $tax_obj->labels->singular_name : wc_attribute_label($taxonomy);

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
                    $name = $attribute->get_name();
                    if ($brand_mode === 'attribute' && $name === $brand_attr) {
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

    private function get_translated_value($parent, $product, $field, $target_lang)
    {
        $original_lang = $this->wpml->get_default_language();
        $this->wpml->switch_language($target_lang);
        $value = null;

        $tr_parent_id = apply_filters('wpml_object_id', $parent->get_id(), 'product', false, $target_lang);

        if ($tr_parent_id) {
            $tr_parent = wc_get_product($tr_parent_id);
            if ($tr_parent) {
                if ($field === 'title') {
                    $value = $product->is_type('variation') ? $this->get_variation_title_translated($product, $tr_parent, $target_lang) : $tr_parent->get_name();
                } elseif ($field === 'description') {
                    $value = $tr_parent->get_description();
                } elseif ($field === 'short_description') {
                    $value = $tr_parent->get_short_description();
                }
            }
        }

        $this->wpml->switch_language($original_lang);
        return $value;
    }

    private function get_variation_title_translated($variation, $tr_parent, $lang)
    {
        $tr_var_id = apply_filters('wpml_object_id', $variation->get_id(), 'product_variation', false, $lang);
        if ($tr_var_id) {
            $tr_var = wc_get_product($tr_var_id);
            if ($tr_var && !empty(trim($tr_var->get_name()))) {
                return $tr_var->get_name();
            }
        }
        return $this->strip_newlines($tr_parent->get_name() . ' ' . wc_get_formatted_variation($variation, true, false, false));
    }

    /**
     * Strip newlines from text for CSV output.
     *
     * @param string $text
     * @return string
     */
    private function strip_newlines($text)
    {
        if (!$text)
            return '';
        return str_replace(["\n", "\r"], ' ', $text);
    }
}
