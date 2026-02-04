<?php
namespace WPKJ\PatternsLibrary\Includes;

use WPKJ\PatternsLibrary\Api\ApiClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sync {
    public function run_sync() {
        $api = new ApiClient();

        // Warm up caches for commonly accessed data that matches frontend usage
        // 1. Categories and Types (used in filter sidebar)
        $api->get_categories();
        $api->get_types();
        
        // 2. Default first page (matches editor.js PER_PAGE=18, default sort)
        $api->get_patterns( [
            'per_page' => 18,
            'page'     => 1,
            'orderby'  => 'date',
            'order'    => 'DESC'
        ] );
        
        // Note: Additional pages are fetched on-demand by client for better performance
    }
}