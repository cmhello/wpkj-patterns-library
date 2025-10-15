<?php
/**
 * Plugin Name: WPKJ Patterns Library
 * Description: Client-side plugin to discover and import block patterns from WPKJ Patterns Manager via REST API.
 * Version: 0.3.0
 * Author: WPKJ Team
 * Text Domain: wpkj-patterns-library
 * Domain Path: /languages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants for modular development and reliable paths
if ( ! defined( 'WPKJ_PL_FILE' ) ) {
    define( 'WPKJ_PL_FILE', __FILE__ );
}
if ( ! defined( 'WPKJ_PL_DIR' ) ) {
    define( 'WPKJ_PL_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPKJ_PL_URL' ) ) {
    define( 'WPKJ_PL_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WPKJ_PL_SLUG' ) ) {
    define( 'WPKJ_PL_SLUG', 'wpkj-patterns-library' );
}
if ( ! defined( 'WPKJ_PL_VERSION' ) ) {
    define( 'WPKJ_PL_VERSION', '0.3.0' );
}

// PSR-4 like, lightweight autoloader for this plugin namespace
spl_autoload_register( function ( $class ) {
    $prefix = 'WPKJ\\PatternsLibrary\\';
    $base_dir = WPKJ_PL_DIR;

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $path = str_replace( '\\', '/', $relative_class );

    // Primary path (PSR-4 style)
    $file = $base_dir . $path . '.php';
    if ( file_exists( $file ) ) {
        require $file;
        return;
    }

    // Fallback: lowercase directories only, keep filename case
    $segments = explode( '/', $path );
    if ( count( $segments ) > 1 ) {
        $filename = end( $segments );
        $dir = strtolower( implode( '/', array_slice( $segments, 0, -1 ) ) );
        $alt_file = $base_dir . $dir . '/' . $filename . '.php';
        if ( file_exists( $alt_file ) ) {
            require $alt_file;
            return;
        }
    }
} );

// Bootstrap the plugin core
function wpkj_patterns_library_run() {
    $plugin = new WPKJ\PatternsLibrary\Includes\Core( WPKJ_PL_SLUG, WPKJ_PL_VERSION );
    $plugin->run();
}

wpkj_patterns_library_run();

// Activation: schedule sync event
register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'wpkj_pl_sync_event' ) ) {
        wp_schedule_event( time() + 60, 'hourly', 'wpkj_pl_sync_event' );
    }
} );

// Deactivation: clear scheduled event
register_deactivation_hook( __FILE__, function() {
    $timestamp = wp_next_scheduled( 'wpkj_pl_sync_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wpkj_pl_sync_event' );
    }
} );

// Admin menu: settings page
add_action( 'admin_menu', function() {
    add_options_page(
        __( 'WPKJ Patterns Library', 'wpkj-patterns-library' ),
        __( 'WPKJ Patterns Library', 'wpkj-patterns-library' ),
        'manage_options',
        'wpkj-patterns-library',
        function() {
            ( new \WPKJ\PatternsLibrary\Admin\Settings() )->render();
        }
    );
} );

// Register settings on admin init
add_action( 'admin_init', function() {
    ( new \WPKJ\PatternsLibrary\Admin\Settings() )->register_settings();
} );

// Hook sync event
add_action( 'wpkj_pl_sync_event', function() {
    ( new \WPKJ\PatternsLibrary\Sync\Sync() )->run_sync();
} );

// Manual sync action
add_action( 'admin_post_wpkj_pl_sync_now', function() {
    ( new \WPKJ\PatternsLibrary\Sync\Sync() )->run_sync();
    wp_safe_redirect( admin_url( 'options-general.php?page=wpkj-patterns-library&synced=1' ) );
    exit;
} );

// Admin: clear plugin cache (transients with prefix)
add_action( 'admin_post_wpkj_pl_clear_cache', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Insufficient permissions.', 'wpkj-patterns-library' ) );
    }
    check_admin_referer( 'wpkj_pl_clear_cache' );
    $cache = new \WPKJ\PatternsLibrary\Includes\Cache();
    $removed = $cache->clear_all();
    wp_safe_redirect( admin_url( 'options-general.php?page=wpkj-patterns-library&cache_cleared=1&removed=' . intval( $removed ) ) );
    exit;
} );