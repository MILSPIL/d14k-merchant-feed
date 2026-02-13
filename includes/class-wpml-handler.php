<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D14K_WPML_Handler {

    public function is_active() {
        return function_exists( 'icl_get_languages' ) && defined( 'ICL_LANGUAGE_CODE' );
    }

    public function get_active_languages() {
        if ( ! $this->is_active() ) {
            return array( substr( get_locale(), 0, 2 ) );
        }
        $languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
        return $languages ? array_keys( $languages ) : array( 'uk' );
    }

    public function switch_language( $lang_code ) {
        if ( $this->is_active() ) {
            do_action( 'wpml_switch_language', $lang_code );
        }
    }

    public function restore_language() {
        if ( $this->is_active() ) {
            do_action( 'wpml_switch_language', null );
        }
    }

    public function get_original_id( $post_id ) {
        if ( ! $this->is_active() ) {
            return $post_id;
        }
        $original_id = apply_filters( 'wpml_original_element_id', null, $post_id, 'post_product' );
        return $original_id ? (int) $original_id : $post_id;
    }

    /**
     * Get image ID for a product with cross-language fallback.
     * If product has no image in current language, fetches _thumbnail_id
     * directly from the original language product via get_post_meta.
     */
    public function get_image_with_fallback( $product_id, $current_image_id ) {
        if ( $current_image_id ) {
            return (int) $current_image_id;
        }
        if ( ! $this->is_active() ) {
            return 0;
        }
        $original_id = $this->get_original_id( $product_id );
        if ( $original_id && $original_id !== $product_id ) {
            $img = get_post_meta( $original_id, '_thumbnail_id', true );
            return $img ? (int) $img : 0;
        }
        return 0;
    }

    /**
     * Get gallery image IDs with cross-language fallback.
     */
    public function get_gallery_with_fallback( $product_id, $current_gallery ) {
        if ( ! empty( $current_gallery ) ) {
            return $current_gallery;
        }
        if ( ! $this->is_active() ) {
            return array();
        }
        $original_id = $this->get_original_id( $product_id );
        if ( $original_id && $original_id !== $product_id ) {
            $gallery_str = get_post_meta( $original_id, '_product_image_gallery', true );
            if ( $gallery_str ) {
                return array_map( 'intval', explode( ',', $gallery_str ) );
            }
        }
        return array();
    }

    public function get_home_url( $lang_code ) {
        if ( $this->is_active() ) {
            return apply_filters( 'wpml_home_url', home_url( '/' ), $lang_code );
        }
        return home_url( '/' );
    }

    public function get_language_name( $lang_code ) {
        $names = array(
            'uk' => 'Українська',
            'ru' => 'Русский',
            'en' => 'English',
        );
        return isset( $names[ $lang_code ] ) ? $names[ $lang_code ] : strtoupper( $lang_code );
    }
}
