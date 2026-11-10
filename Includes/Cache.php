<?php
namespace WPKJ\PatternsLibrary\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Transient-backed cache that also keeps an index of the keys it wrote.
 *
 * The index is what makes invalidation work on both storage backends: without
 * it, clearing can only be done by scanning wp_options, which finds nothing
 * when a persistent object cache is configured because transients then never
 * touch the database.
 */
class Cache {
    const PREFIX      = 'wpkj_pl_';
    const KEYS_OPTION = 'wpkj_pl_cache_keys';
    const MAX_KEYS    = 300;

    /** Store a value and remember its key so it can be invalidated later. */
    public function set( string $key, $value, int $ttl ) : void {
        set_transient( $key, $value, $ttl );
        $this->track( $key );
    }

    /**
     * Clear all caches created by this plugin. Returns number of entries removed.
     */
    public function clear_all() : int {
        $deleted = 0;

        // delete_transient() targets the object cache or wp_options depending
        // on how the site is configured, so tracked keys cover both backends.
        foreach ( array_keys( $this->get_keys() ) as $key ) {
            if ( delete_transient( $key ) ) {
                $deleted++;
            }
        }

        // Entries written before key tracking existed are only reachable
        // through the options table.
        $deleted += $this->clear_transients();

        delete_option( self::KEYS_OPTION );

        return $deleted;
    }

    /** Add a key to the index, keeping it bounded to the most recent entries. */
    private function track( string $key ) : void {
        $keys = $this->get_keys();

        if ( isset( $keys[ $key ] ) ) {
            return;
        }

        $keys[ $key ] = time();

        if ( count( $keys ) > self::MAX_KEYS ) {
            asort( $keys );
            $keys = array_slice( $keys, -self::MAX_KEYS, null, true );
        }

        // Autoload disabled: the index is read only on warmup and on clear.
        update_option( self::KEYS_OPTION, $keys, false );
    }

    /** Indexed cache keys as key => last written timestamp. */
    private function get_keys() : array {
        $keys = get_option( self::KEYS_OPTION, [] );
        return is_array( $keys ) ? $keys : [];
    }

    /**
     * Delete every wpkj_pl_* transient by scanning wp_options.
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
