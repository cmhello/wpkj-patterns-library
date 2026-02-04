<?php
namespace WPKJ\PatternsLibrary\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {
    public static function activate() {
        // Set default options on first activation
        self::set_default_options();
        
        // Schedule sync event hourly
        if ( ! wp_next_scheduled( 'wpkj_pl_sync_event' ) ) {
            wp_schedule_event( time() + 60, 'hourly', 'wpkj_pl_sync_event' );
        }
        // Schedule dependency status refresh using configured TTL interval
        if ( ! wp_next_scheduled( 'wpkj_pl_deps_check_event' ) ) {
            $ttl = (int) get_option( 'wpkj_patterns_library_cache_ttl', 900 );
            $ttl = max( 60, $ttl );
            // Use custom schedule key; registered via Scheduler hooks
            wp_schedule_event( time() + 120, 'wpkj_pl_deps_ttl', 'wpkj_pl_deps_check_event' );
        }
    }
    
    /**
     * Set default options if not already set
     *
     * @since 0.5.1
     */
    private static function set_default_options() {
        $default_options = [
            'wpkj_patterns_library_api_base' => 'https://mb.wpkz.cn/wp-json/wpkj/v1',
            'wpkj_patterns_library_cache_ttl' => 900,
        ];

        foreach ( $default_options as $option => $value ) {
            if ( false === get_option( $option ) ) {
                add_option( $option, $value );
            }
        }
    }
}