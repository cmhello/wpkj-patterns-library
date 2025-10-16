<?php
/**
 * Plugin Name: WPKJ Patterns Library
 * Description: Client-side plugin to discover and import block patterns from WPKJ Patterns Manager via REST API.
 * Version: 0.4.0
 * Author: WPKJ Team
 * Text Domain: wpkj-patterns-library
 * Domain Path: /languages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants for modular development and reliable paths
if ( ! defined( 'WPKJ_PL_VERSION' ) ) {
    define( 'WPKJ_PL_VERSION', '0.4.0' );
}
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

// Load textdomain and compile zh_CN .mo from .po if missing
add_action( 'init', function() {
    // Load plugin text domain from /languages
    load_plugin_textdomain(
        'wpkj-patterns-library',
        false,
        dirname( plugin_basename( WPKJ_PL_FILE ) ) . '/languages'
    );
}, 5 );

// Activation: schedule sync event
register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'wpkj_pl_sync_event' ) ) {
        wp_schedule_event( time() + 60, 'hourly', 'wpkj_pl_sync_event' );
    }
    // Schedule dependency status refresh using configured TTL interval
    if ( ! wp_next_scheduled( 'wpkj_pl_deps_check_event' ) ) {
        $ttl = (int) get_option( 'wpkj_patterns_library_cache_ttl', 900 );
        $ttl = max( 60, $ttl );
        wp_schedule_event( time() + 120, 'wpkj_pl_deps_ttl', 'wpkj_pl_deps_check_event' );
    }
} );

// Deactivation: clear scheduled event
register_deactivation_hook( __FILE__, function() {
    $timestamp = wp_next_scheduled( 'wpkj_pl_sync_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wpkj_pl_sync_event' );
    }
    // Unschedule dependency status refresh
    $ts2 = wp_next_scheduled( 'wpkj_pl_deps_check_event' );
    if ( $ts2 ) {
        wp_unschedule_event( $ts2, 'wpkj_pl_deps_check_event' );
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
    ( new \WPKJ\PatternsLibrary\Includes\Sync() )->run_sync();
} );

// Dependency status refresh cron
add_action( 'wpkj_pl_deps_check_event', function() {
    ( new \WPKJ\PatternsLibrary\Includes\Dependencies() )->refresh_status();
} );

// Refresh dependency status upon plugin operations
add_action( 'activated_plugin', function( $plugin, $network_wide = false ) {
    ( new \WPKJ\PatternsLibrary\Includes\Dependencies() )->refresh_status();
}, 10, 2 );
add_action( 'deactivated_plugin', function( $plugin, $network_wide = false ) {
    ( new \WPKJ\PatternsLibrary\Includes\Dependencies() )->refresh_status();
}, 10, 2 );
add_action( 'upgrader_process_complete', function( $upgrader, $options ) {
    ( new \WPKJ\PatternsLibrary\Includes\Dependencies() )->refresh_status();
}, 10, 2 );

// Refresh on plugin deletion as well
add_action( 'deleted_plugin', function( $plugin, $deleted ) {
    ( new \WPKJ\PatternsLibrary\Includes\Dependencies() )->refresh_status();
}, 10, 2 );

// Also refresh when active plugins option changes (covers edge cases)
add_action( 'update_option_active_plugins', function( $old, $new, $option ) {
    ( new \WPKJ\PatternsLibrary\Includes\Dependencies() )->refresh_status();
}, 10, 3 );
add_action( 'update_site_option_active_sitewide_plugins', function( $old, $new, $option ) {
    ( new \WPKJ\PatternsLibrary\Includes\Dependencies() )->refresh_status();
}, 10, 3 );

// Manual sync action
add_action( 'admin_post_wpkj_pl_sync_now', function() {
    ( new \WPKJ\PatternsLibrary\Includes\Sync() )->run_sync();
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

// Admin: test remote connectivity and show result
add_action( 'admin_post_wpkj_pl_test_connectivity', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Insufficient permissions.', 'wpkj-patterns-library' ) );
    }
    check_admin_referer( 'wpkj_pl_test_connectivity' );
    $result = ( new \WPKJ\PatternsLibrary\Api\ApiClient() )->test_connectivity();
    $url = add_query_arg( [
        'tested' => 1,
        'ok'     => $result['ok'] ? 1 : 0,
        'code'   => intval( $result['code'] ),
        'msg'    => rawurlencode( $result['message'] ),
    ], admin_url( 'options-general.php?page=wpkj-patterns-library' ) );
    wp_safe_redirect( $url );
    exit;
} );

// Auto-clear plugin cache when critical settings change (API base or JWT)
add_action( 'update_option_wpkj_patterns_library_api_base', function( $old, $new, $option ) {
    // Clearing by prefix ensures both value and timeout entries are removed
    ( new \WPKJ\PatternsLibrary\Includes\Cache() )->clear_all();
}, 10, 3 );

add_action( 'update_option_wpkj_patterns_library_jwt', function( $old, $new, $option ) {
    ( new \WPKJ\PatternsLibrary\Includes\Cache() )->clear_all();
}, 10, 3 );

// Add custom cron schedule matching configured cache TTL
add_filter( 'cron_schedules', function( $schedules ) {
    $ttl = (int) get_option( 'wpkj_patterns_library_cache_ttl', 900 );
    $ttl = max( 60, $ttl );
    $schedules['wpkj_pl_deps_ttl'] = [
        'interval' => $ttl,
        'display'  => __( 'WPKJ PL Dependencies Status TTL', 'wpkj-patterns-library' ),
    ];
    return $schedules;
} );

// Reschedule dependency status cron when cache TTL option changes
add_action( 'update_option_wpkj_patterns_library_cache_ttl', function( $old, $new ) {
    $timestamp = wp_next_scheduled( 'wpkj_pl_deps_check_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wpkj_pl_deps_check_event' );
    }
    // Ensure custom schedule is registered and reschedule with new TTL
    $ttl = max( 60, (int) $new );
    if ( ! wp_next_scheduled( 'wpkj_pl_deps_check_event' ) ) {
        wp_schedule_event( time() + 60, 'wpkj_pl_deps_ttl', 'wpkj_pl_deps_check_event' );
    }
}, 10, 2 );

// Register REST API routes for the plugin controllers
add_action( 'rest_api_init', function() {
    ( new \WPKJ\PatternsLibrary\Api\DepsController() )->register_routes();
    ( new \WPKJ\PatternsLibrary\Api\FavoritesController() )->register_routes();
    ( new \WPKJ\PatternsLibrary\Api\ManagerProxyController() )->register_routes();
} );
