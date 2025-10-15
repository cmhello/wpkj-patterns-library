<?php
namespace WPKJ\PatternsLibrary\Sync;

use WPKJ\PatternsLibrary\Api\ApiClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sync {
    public function run_sync() {
        $api = new ApiClient();

        // Warm up caches for categories and patterns (first page only)
        $cats = $api->get_categories();
        // Use minimal fields for first-page warmup to reduce cache size
        $first = $api->get_patterns_min( [ 'per_page' => 50, 'page' => 1 ] );

        // Optional: prefetch additional pages, but guard by option to avoid overload
        $prefetch = (int) get_option( 'wpkj_patterns_library_max_register', 200 );
        $per_page = 100;
        $page     = 2;
        $fetched  = is_array( $first ) ? count( $first ) : 0;
        while ( $fetched < $prefetch ) {
            // Prefetch using minimal list to keep cache light
            $batch = $api->get_patterns_min( [ 'per_page' => $per_page, 'page' => $page ] );
            if ( empty( $batch ) ) {
                break;
            }
            $fetched += count( $batch );
            $page++;
        }
    }
}