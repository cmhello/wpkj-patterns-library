<?php
namespace WPKJ\PatternsLibrary\Includes;

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

        // Drop prefetch loop; rely on on-demand client fetch in UI
    }
}