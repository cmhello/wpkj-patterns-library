<?php
namespace WPKJ\PatternsLibrary\Admin;

use WPKJ\PatternsLibrary\Includes\Cache;
use WPKJ\PatternsLibrary\Includes\Sync;
use WPKJ\PatternsLibrary\Api\ApiClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminActions {
    public function hooks() {
        // Manual sync action
        add_action( 'admin_post_wpkj_pl_sync_now', [ $this, 'handle_sync_now' ] );

        // Clear cache
        add_action( 'admin_post_wpkj_pl_clear_cache', [ $this, 'handle_clear_cache' ] );

        // Test connectivity
        add_action( 'admin_post_wpkj_pl_test_connectivity', [ $this, 'handle_test_connectivity' ] );
    }

    public function handle_sync_now() {
        ( new Sync() )->run_sync();
        wp_safe_redirect( admin_url( 'options-general.php?page=wpkj-patterns-library&synced=1' ) );
        exit;
    }

    public function handle_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'wpkj-patterns-library' ) );
        }
        check_admin_referer( 'wpkj_pl_clear_cache' );
        $cache = new Cache();
        $removed = $cache->clear_all();
        wp_safe_redirect( admin_url( 'options-general.php?page=wpkj-patterns-library&cache_cleared=1&removed=' . intval( $removed ) ) );
        exit;
    }

    public function handle_test_connectivity() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'wpkj-patterns-library' ) );
        }
        check_admin_referer( 'wpkj_pl_test_connectivity' );
        $result = ( new ApiClient() )->test_connectivity();
        $url = add_query_arg( [
            'tested' => 1,
            'ok'     => $result['ok'] ? 1 : 0,
            'code'   => intval( $result['code'] ),
            'msg'    => rawurlencode( $result['message'] ),
        ], admin_url( 'options-general.php?page=wpkj-patterns-library' ) );
        wp_safe_redirect( $url );
        exit;
    }
}