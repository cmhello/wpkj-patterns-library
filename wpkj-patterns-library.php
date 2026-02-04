<?php
/**
 * Plugin Name: WPKJ Patterns Library
 * Description: Client-side plugin to discover and import block patterns from WPKJ Patterns Manager via REST API.
 * Version: 0.6.0
 * Author: WPKJ Team
 * Author URI: https://www.wpdaxue.com
 * Text Domain: wpkj-patterns-library
 * Domain Path: /languages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants for modular development and reliable paths
if ( ! defined( 'WPKJ_PL_VERSION' ) ) {
    define( 'WPKJ_PL_VERSION', '0.6.0' );
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

// Activation: handled by Includes\Activator
register_activation_hook( __FILE__, [ '\\WPKJ\\PatternsLibrary\\Includes\\Activator', 'activate' ] );

// Deactivation: handled by Includes\Deactivator
register_deactivation_hook( __FILE__, [ '\\WPKJ\\PatternsLibrary\\Includes\\Deactivator', 'deactivate' ] );

// Auto-clear plugin cache when critical settings change (API base or JWT)
add_action( 'update_option_wpkj_patterns_library_api_base', function( $old, $new, $option ) {
    // Clearing by prefix ensures both value and timeout entries are removed
    ( new \WPKJ\PatternsLibrary\Includes\Cache() )->clear_all();
}, 10, 3 );

add_action( 'update_option_wpkj_patterns_library_jwt', function( $old, $new, $option ) {
    ( new \WPKJ\PatternsLibrary\Includes\Cache() )->clear_all();
}, 10, 3 );
