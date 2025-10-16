<?php
namespace WPKJ\PatternsLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {
    public function register_settings() {
        register_setting( 'wpkj_pl_settings', 'wpkj_patterns_library_api_base', [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'wpkj_pl_settings', 'wpkj_patterns_library_cache_ttl', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 900 ] );
        // JWT no longer used in frontend; keep option for server-side filters if needed
        register_setting( 'wpkj_pl_settings', 'wpkj_patterns_library_jwt', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );

        add_settings_section( 'wpkj_pl_main', __( 'Patterns Library Settings', 'wpkj-patterns-library' ), function() {
            echo '<p>' . esc_html__( 'Configure API and caching for the Patterns Library.', 'wpkj-patterns-library' ) . '</p>';
        }, 'wpkj-patterns-library' );

        add_settings_field( 'api_base', __( 'API Base URL', 'wpkj-patterns-library' ), function() {
            $val = esc_attr( get_option( 'wpkj_patterns_library_api_base', 'https://mb.wpkz.cn/wp-json/wpkj/v1' ) );
            echo '<input type="url" class="regular-text" name="wpkj_patterns_library_api_base" value="' . $val . '" placeholder="https://mb.wpkz.cn/wp-json/wpkj/v1" />';
        }, 'wpkj-patterns-library', 'wpkj_pl_main' );

        add_settings_field( 'cache_ttl', __( 'Cache TTL (seconds)', 'wpkj-patterns-library' ), function() {
            $val = esc_attr( get_option( 'wpkj_patterns_library_cache_ttl', 900 ) );
            echo '<input type="number" min="60" step="30" name="wpkj_patterns_library_cache_ttl" value="' . $val . '" />';
        }, 'wpkj-patterns-library', 'wpkj_pl_main' );

        add_settings_field( 'jwt', __( 'JWT Token (optional)', 'wpkj-patterns-library' ), function() {
            $val = esc_attr( get_option( 'wpkj_patterns_library_jwt', '' ) );
            echo '<input type="text" class="regular-text" name="wpkj_patterns_library_jwt" value="' . $val . '" placeholder="Server-side only (not injected to JS)" />';
        }, 'wpkj-patterns-library', 'wpkj_pl_main' );
    }

    public function render() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'WPKJ Patterns Library', 'wpkj-patterns-library' ) . '</h1>';
        if ( isset( $_GET['synced'] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Sync completed.', 'wpkj-patterns-library' ) . '</p></div>';
        }
        if ( isset( $_GET['cache_cleared'] ) ) {
            $removed = isset( $_GET['removed'] ) ? intval( $_GET['removed'] ) : 0;
            echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Cache cleared. %d entries removed.', 'wpkj-patterns-library' ), $removed ) . '</p></div>';
        }
        if ( isset( $_GET['tested'] ) ) {
            $ok   = isset( $_GET['ok'] ) ? (bool) intval( $_GET['ok'] ) : false;
            $code = isset( $_GET['code'] ) ? intval( $_GET['code'] ) : 0;
            $msg  = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
            $cls  = $ok ? 'notice-success' : 'notice-error';
            $text = $ok ? 'Remote connectivity OK' : ( 'Connectivity failed: ' . ( $code ? ( '[' . $code . '] ' ) : '' ) . $msg );
            echo '<div class="notice ' . esc_attr( $cls ) . '"><p>' . esc_html( $text ) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
        settings_fields( 'wpkj_pl_settings' );
        do_settings_sections( 'wpkj-patterns-library' );
        submit_button();
        echo '</form>';
        echo '<hr />';
        $clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=wpkj_pl_clear_cache' ), 'wpkj_pl_clear_cache' );
        $test_url  = wp_nonce_url( admin_url( 'admin-post.php?action=wpkj_pl_test_connectivity' ), 'wpkj_pl_test_connectivity' );
        echo '<p>';
        echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin-post.php?action=wpkj_pl_sync_now' ) ) . '">' . esc_html__( 'Sync Now', 'wpkj-patterns-library' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Clear Cache', 'wpkj-patterns-library' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( $test_url ) . '">' . esc_html__( 'Test Connectivity', 'wpkj-patterns-library' ) . '</a>';
        echo '</p>';
        echo '</div>';
    }
}