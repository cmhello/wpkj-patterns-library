<?php
namespace WPKJ\PatternsLibrary\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ManagerProxyController: expose local REST endpoints that proxy to the remote
 * WPKJ manager API via ApiClient. Centralizes base URL, headers and caching.
 */
class ManagerProxyController {
    const NAMESPACE = 'wpkj-pl/v1';
    
    /** @var ApiClient Singleton instance */
    private $api;
    
    public function __construct() {
        $this->api = new ApiClient();
    }

    /** Register proxy routes. */
    public function register_routes() {
        // List patterns
        register_rest_route( self::NAMESPACE, '/manager/patterns', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_patterns' ],
                'permission_callback' => '__return_true',
                'args' => [
                    'per_page' => [ 'type' => 'integer', 'required' => false ],
                    'page'     => [ 'type' => 'integer', 'required' => false ],
                    'orderby'  => [ 'type' => 'string',  'required' => false ],
                    'order'    => [ 'type' => 'string',  'required' => false ],
                    'category' => [ 'type' => 'array',   'required' => false ],
                    'type'     => [ 'type' => 'array',   'required' => false ],
                ],
            ],
        ] );

        // Pattern detail
        register_rest_route( self::NAMESPACE, '/manager/patterns/(?P<id>\\d+)', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_pattern' ],
                'permission_callback' => '__return_true',
                'args' => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
            ],
            [
                'methods'  => \WP_REST_Server::CREATABLE,
                'callback' => [ $this, 'import_pattern' ],
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
                'args' => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
            ],
        ] );

        // Categories
        register_rest_route( self::NAMESPACE, '/manager/categories', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_categories' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // Types
        register_rest_route( self::NAMESPACE, '/manager/types', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_types' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // Search
        register_rest_route( self::NAMESPACE, '/manager/search', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'search' ],
                'permission_callback' => '__return_true',
                'args' => [
                    'q'        => [ 'type' => 'string',  'required' => true ],
                    'per_page' => [ 'type' => 'integer', 'required' => false ],
                    'page'     => [ 'type' => 'integer', 'required' => false ],
                    'category' => [ 'type' => 'array',   'required' => false ],
                    'type'     => [ 'type' => 'array',   'required' => false ],
                    'orderby'  => [ 'type' => 'string',  'required' => false ],
                    'order'    => [ 'type' => 'string',  'required' => false ],
                ],
            ],
        ] );
    }

    /** Route callbacks */
    public function get_patterns( \WP_REST_Request $req ) {
        $args = [
            'per_page' => (int) $req->get_param( 'per_page' ),
            'page'     => (int) $req->get_param( 'page' ),
            'orderby'  => (string) $req->get_param( 'orderby' ),
            'order'    => (string) $req->get_param( 'order' ),
        ];
        $cat = $req->get_param( 'category' );
        $typ = $req->get_param( 'type' );
        if ( is_array( $cat ) ) $args['category'] = array_map( 'intval', $cat );
        if ( is_array( $typ ) ) $args['type']     = array_map( 'intval', $typ );
        return rest_ensure_response( $this->api->get_patterns( $args ) );
    }

    public function get_pattern( \WP_REST_Request $req ) {
        $id = (int) $req->get_param( 'id' );
        return rest_ensure_response( $this->api->get_pattern( $id ) );
    }

    public function import_pattern( \WP_REST_Request $req ) {
        $id = (int) $req->get_param( 'id' );
        return rest_ensure_response( $this->api->import_pattern( $id ) );
    }

    public function get_categories() {
        return rest_ensure_response( $this->api->get_categories() );
    }

    public function get_types() {
        return rest_ensure_response( $this->api->get_types() );
    }

    public function search( \WP_REST_Request $req ) {
        $q = (string) $req->get_param( 'q' );
        $args = [
            'per_page' => (int) $req->get_param( 'per_page' ),
            'page'     => (int) $req->get_param( 'page' ),
            'orderby'  => (string) $req->get_param( 'orderby' ),
            'order'    => (string) $req->get_param( 'order' ),
        ];
        $cat = $req->get_param( 'category' );
        $typ = $req->get_param( 'type' );
        if ( is_array( $cat ) ) $args['category'] = array_map( 'intval', $cat );
        if ( is_array( $typ ) ) $args['type']     = array_map( 'intval', $typ );
        return rest_ensure_response( $this->api->search( $q, $args ) );
    }
}