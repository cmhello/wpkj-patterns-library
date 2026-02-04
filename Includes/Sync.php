<?php
namespace WPKJ\PatternsLibrary\Includes;

use WPKJ\PatternsLibrary\Api\ApiClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sync {
    /**
     * Run cache warmup for commonly accessed data
     * This ensures fast first-load experience for users
     */
    public function run_sync() {
        $api = new ApiClient();

        // Warm up caches for commonly accessed data that matches frontend usage
        // 1. Categories and Types (used in filter sidebar) - High priority
        $api->get_categories();
        $api->get_types();
        
        // 2. Default first page (matches editor.js PER_PAGE=18, default sort)
        $api->get_patterns( [
            'per_page' => 18,
            'page'     => 1,
            'orderby'  => 'date',
            'order'    => 'DESC'
        ] );
        
        // 3. Popular sort (commonly used)
        $api->get_patterns( [
            'per_page' => 18,
            'page'     => 1,
            'orderby'  => 'popular',
            'order'    => 'DESC'
        ] );
        
        // Note: Additional pages are fetched on-demand by client for better performance
        
        // Log success if WP_DEBUG is enabled
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'WPKJ Patterns Library: Cache warmup completed at ' . current_time( 'mysql' ) );
        }
    }
    
    /**
     * Check if sync is needed based on last run time
     * 
     * @return bool True if sync should run
     */
    public function should_sync() {
        $last_sync = get_transient( 'wpkj_pl_last_sync' );
        if ( false === $last_sync ) {
            return true;
        }
        
        $cache_ttl = (int) get_option( 'wpkj_patterns_library_cache_ttl', 900 );
        $elapsed = time() - $last_sync;
        
        return $elapsed >= $cache_ttl;
    }
    
    /**
     * Mark sync as completed
     */
    public function mark_synced() {
        $cache_ttl = (int) get_option( 'wpkj_patterns_library_cache_ttl', 900 );
        set_transient( 'wpkj_pl_last_sync', time(), $cache_ttl );
    }
    
    /**
     * Run sync with check
     */
    public function run_sync_if_needed() {
        if ( $this->should_sync() ) {
            $this->run_sync();
            $this->mark_synced();
        }
    }
}