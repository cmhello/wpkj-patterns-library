<?php
namespace WPKJ\PatternsLibrary\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cache {
    const PREFIX = 'wpkj_pl_';

    /**
     * Clear all transients created by this plugin's ApiClient (prefix-based).
     * Returns number of transient keys deleted.
     */
    public function clear_all() : int {
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