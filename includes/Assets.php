<?php
namespace WPKJ\PatternsLibrary\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Assets {
    public function hooks() {
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor' ] );
    }

    public function enqueue_editor() {
        $js_path = WPKJ_PL_DIR . 'assets/js/editor.js';
        $css_path = WPKJ_PL_DIR . 'assets/css/editor.css';
        $js_url  = WPKJ_PL_URL . 'assets/js/editor.js';
        $css_url = WPKJ_PL_URL . 'assets/css/editor.css';
        $js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : WPKJ_PL_VERSION;
        $css_ver = file_exists( $css_path ) ? filemtime( $css_path ) : WPKJ_PL_VERSION;

        // Align dependencies with WP 6.8.3 recommended handles
        // Use wp-edit-post for SlotFill components (PluginSidebar, PluginToolbarButton, etc.)
        wp_register_script(
            'wpkj-pl-editor',
            $js_url,
            [ 'wp-plugins', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-data', 'wp-i18n', 'wp-dom-ready', 'wp-blocks' ],
            $js_ver,
            true
        );

        $config = [
            'apiBase'        => get_option( 'wpkj_patterns_library_api_base', home_url( '/wp-json/wpkj/v1' ) ),
            'jwt'            => get_option( 'wpkj_patterns_library_jwt', '' )
        ];
        wp_localize_script( 'wpkj-pl-editor', 'WPKJPatternsConfig', $config );

        wp_register_style( 'wpkj-pl-editor', $css_url, [], $css_ver );
        wp_enqueue_script( 'wpkj-pl-editor' );
        wp_enqueue_style( 'wpkj-pl-editor' );
    }
}