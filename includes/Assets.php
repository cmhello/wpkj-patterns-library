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

        // Bind script translations for the editor UI
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wpkj-pl-editor', 'wpkj-patterns-library', WPKJ_PL_DIR . 'languages' );
        }

        // Prepare active plugin slugs and capability
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active_plugins = (array) get_option( 'active_plugins', [] );
        $network_active = (array) get_site_option( 'active_sitewide_plugins', [] );
        $active_slugs   = [];
        foreach ( $active_plugins as $path ) {
            $slug = strpos( $path, '/' ) !== false ? substr( $path, 0, strpos( $path, '/' ) ) : $path;
            if ( $slug ) $active_slugs[] = $slug;
        }
        foreach ( array_keys( $network_active ) as $path ) {
            $slug = strpos( $path, '/' ) !== false ? substr( $path, 0, strpos( $path, '/' ) ) : $path;
            if ( $slug && ! in_array( $slug, $active_slugs, true ) ) $active_slugs[] = $slug;
        }

        $config = [
            'apiBase'            => get_option( 'wpkj_patterns_library_api_base', home_url( '/wp-json/wpkj/v1' ) ),
            'jwt'                => get_option( 'wpkj_patterns_library_jwt', '' ),
            'restNonce'          => wp_create_nonce( 'wp_rest' ),
            'activeSlugs'        => $active_slugs,
            'canInstallPlugins'  => current_user_can( 'install_plugins' ),
            'adminUrlPluginInstall' => admin_url( 'plugin-install.php?tab=search&s=' ),
            'adminUrlPlugins'    => admin_url( 'plugins.php' ),
        ];
        wp_localize_script( 'wpkj-pl-editor', 'WPKJPatternsConfig', $config );

        wp_register_style( 'wpkj-pl-editor', $css_url, [], $css_ver );
        wp_enqueue_script( 'wpkj-pl-editor' );
        wp_enqueue_style( 'wpkj-pl-editor' );

        // Ensure wp.updates API is available for plugin installs from editor UI
        wp_enqueue_script( 'updates' );
    }
}