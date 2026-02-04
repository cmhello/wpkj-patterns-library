<?php
namespace WPKJ\PatternsLibrary\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cache {
    const PREFIX = 'wpkj_pl_';
    const GROUP = 'wpkj_patterns_library';

    /**
     * Clear all caches created by this plugin.
     * Supports both object cache (Redis/Memcached) and transient fallback.
     * Returns number of cache entries cleared.
     */
    public function clear_all() : int {
        $deleted = 0;

        // 1. Try to flush object cache group (if persistent cache is available)
        if ( $this->is_object_cache_available() ) {
            // Use wp_cache_flush_group if available (Redis Object Cache plugin)
            if ( function_exists( 'wp_cache_flush_group' ) ) {
                $result = wp_cache_flush_group( self::GROUP );
                // Most implementations don't return count, assume success cleared many
                return $result ? 100 : 0;
            }
            
            // Fallback: wp_cache doesn't provide a way to list all keys in a group
            // We can't reliably clear object cache without flush_group support
            // Log a notice for developers
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'WPKJ Patterns Library: Object cache detected but wp_cache_flush_group() not available. Cache may not be fully cleared.' );
            }
        }

        // 2. Clear transient-based cache (fallback for non-object-cache setups)
        $deleted += $this->clear_transients();

        return $deleted;
    }

    /**
     * Check if persistent object cache is available.
     */
    private function is_object_cache_available() : bool {
        global $wp_object_cache;
        
        // Check if using persistent backend (not default transient-based cache)
        if ( ! empty( $wp_object_cache ) && method_exists( $wp_object_cache, 'redis_status' ) ) {
            return true; // Redis Object Cache
        }
        
        if ( ! empty( $wp_object_cache ) && method_exists( $wp_object_cache, 'get_mc' ) ) {
            return true; // Memcached
        }
        
        // Check for other object cache plugins
        return wp_using_ext_object_cache();
    }

    /**
     * Clear transient-based cache (database fallback).
     * Returns number of transients deleted.
     */
    private function clear_transients() : int {
        global $wpdb;

        $deleted = 0;
        $keys    = [];

        // Build LIKE patterns for transient keys
        $like = $wpdb->esc_like( self::PREFIX ) . '%';
        $pat_value  = '_transient_' . $like;
        $pat_timeout = '_transient_timeout_' . $like;

        // Query option names that match our transient prefix
        $sql = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $pat_value,
            $pat_timeout
        );
        $names = $wpdb->get_col( $sql );

        if ( is_array( $names ) ) {
            foreach ( $names as $name ) {
                if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
                    $key = substr( $name, strlen( '_transient_timeout_' ) );
                } elseif ( 0 === strpos( $name, '_transient_' ) ) {
                    $key = substr( $name, strlen( '_transient_' ) );
                } else {
                    continue;
                }
                if ( 0 === strpos( $key, self::PREFIX ) ) {
                    $keys[ $key ] = true; // de-duplicate
                }
            }
        }

        foreach ( array_keys( $keys ) as $key ) {
            // delete_transient handles both value and timeout
            if ( delete_transient( $key ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }
}