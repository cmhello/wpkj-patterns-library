<?php
namespace WPKJ\PatternsLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {
    public function register_settings() {
        register_setting( 'wpkj_pl_settings', 'wpkj_patterns_library_api_base', [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'wpkj_pl_settings', 'wpkj_patterns_library_cache_ttl', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 900 ] );
        register_setting( 'wpkj_pl_settings', 'wpkj_patterns_library_max_register', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 200 ] );
        register_setting( 'wpkj_pl_settings', 'wpkj_patterns_library_jwt', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );

        add_settings_section( 'wpkj_pl_main', __( 'Patterns Library Settings', 'wpkj-patterns-library' ), function() {
            echo '<p>' . esc_html__( 'Configure API and caching for the Patterns Library.', 'wpkj-patterns-library' ) . '</p>';
        }, 'wpkj-patterns-library' );

        add_settings_field( 'api_base', __( 'API Base URL', 'wpkj-patterns-library' ), function() {
            $val = esc_attr( get_option( 'wpkj_patterns_library_api_base', home_url( '/wp-json/wpkj/v1' ) ) );
            echo '<input type="url" class="regular-text" name="wpkj_patterns_library_api_base" value="' . $val . '" placeholder="https://example.com/wp-json/wpkj/v1" />';
        }, 'wpkj-patterns-library', 'wpkj_pl_main' );

        add_settings_field( 'cache_ttl', __( 'Cache TTL (seconds)', 'wpkj-patterns-library' ), function() {
            $val = esc_attr( get_option( 'wpkj_patterns_library_cache_ttl', 900 ) );
            echo '<input type="number" min="60" step="30" name="wpkj_patterns_library_cache_ttl" value="' . $val . '" />';
        }, 'wpkj-patterns-library', 'wpkj_pl_main' );

        add_settings_field( 'max_register', __( 'Max Patterns Registered', 'wpkj-patterns-library' ), function() {
            $val = esc_attr( get_option( 'wpkj_patterns_library_max_register', 200 ) );
            echo '<input type="number" min="50" step="50" name="wpkj_patterns_library_max_register" value="' . $val . '" />';
        }, 'wpkj-patterns-library', 'wpkj_pl_main' );

        add_settings_field( 'jwt', __( 'JWT Token (optional)', 'wpkj-patterns-library' ), function() {
            $val = esc_attr( get_option( 'wpkj_patterns_library_jwt', '' ) );
            echo '<input type="text" class="regular-text" name="wpkj_patterns_library_jwt" value="' . $val . '" placeholder="Bearer token" />';
        }, 'wpkj-patterns-library', 'wpkj_pl_main' );
    }

    public function render() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'WPKJ Patterns Library', 'wpkj-patterns-library' ) . '</h1>';
        if ( isset( $_GET['synced'] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Sync completed.', 'wpkj-patterns-library' ) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
        settings_fields( 'wpkj_pl_settings' );
        do_settings_sections( 'wpkj-patterns-library' );
        submit_button();
        echo '</form>';
        echo '<hr />';
        echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin-post.php?action=wpkj_pl_sync_now' ) ) . '">' . esc_html__( 'Sync Now', 'wpkj-patterns-library' ) . '</a></p>';
        echo '</div>';
    }
}