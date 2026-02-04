<?php
namespace WPKJ\PatternsLibrary\Api;

use WPKJ\PatternsLibrary\Includes\Dependencies;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deps controller: expose dependency list, status, and install endpoints.
 * Merges former DependenciesController responsibilities for unified routing.
 */
class DepsController {
    const NAMESPACE = 'wpkj-pl/v1';
    
    /** @var ApiClient Singleton instance */
    private $api;
    
    /** @var Dependencies Singleton instance */
    private $deps;
    
    public function __construct() {
        $this->api = new ApiClient();
        $this->deps = new Dependencies();
    }

    /** Register routes for dependency info, status and installation. */
    public function register_routes() {
        // Public dependency list (proxied from manager API)
        register_rest_route( self::NAMESPACE, '/dependencies', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_dependencies' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // Status: allow bypassing transient via `no_cache=1`
        register_rest_route( self::NAMESPACE, '/deps-status', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_status' ],
                'permission_callback' => function() { return current_user_can( 'read' ); },
                'args'     => [
                    'no_cache' => [ 'type' => 'boolean', 'required' => false ],
                ],
            ],
        ] );

        // Install/activate required dependencies
        register_rest_route( self::NAMESPACE, '/deps-install', [
            [
                'methods'  => \WP_REST_Server::CREATABLE,
                'callback' => [ $this, 'install_all' ],
                'permission_callback' => function() { return current_user_can( 'install_plugins' ) || current_user_can( 'activate_plugins' ); },
                'args'     => [
                    'slugs' => [ 'type' => 'array', 'required' => false ],
                ],
            ],
        ] );
    }

    /** Get dependencies list from manager via ApiClient. */
    public function get_dependencies( $request ) {
        $data = $this->api->get_dependencies();
        return rest_ensure_response( is_array( $data ) ? $data : [] );
    }

    /** Return dependency readiness status; optionally bypass cache. */
    public function get_status( $request ) {
        $no_cache = $request->get_param( 'no_cache' );
        if ( $no_cache && in_array( strtolower( (string) $no_cache ), [ '1', 'true', 'yes' ], true ) ) {
            return rest_ensure_response( $this->deps->refresh_status() );
        }
        return rest_ensure_response( $this->deps->get_status() );
    }

    /** Ensure install/activate all required dependencies. */
    public function install_all( $request ) {
        $slugs = $request->get_param( 'slugs' );
        if ( ! is_array( $slugs ) ) $slugs = [];
        $res = $this->deps->ensure_all_ready( $slugs );
        return rest_ensure_response( $res );
    }
}