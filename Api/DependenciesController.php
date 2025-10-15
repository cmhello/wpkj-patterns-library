<?php
namespace WPKJ\PatternsLibrary\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DependenciesController {
    const NAMESPACE = 'wpkj-pl/v1';

    public function register_routes() {
        register_rest_route( self::NAMESPACE, '/dependencies', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_dependencies' ],
                'permission_callback' => '__return_true',
            ],
        ] );
    }

    public function get_dependencies( $request ) {
        $client = new ApiClient();
        $data = $client->get_dependencies();
        return rest_ensure_response( is_array( $data ) ? $data : [] );
    }
}