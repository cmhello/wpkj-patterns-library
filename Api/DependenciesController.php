<?php
namespace WPKJ\PatternsLibrary\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dependencies controller: proxy dependency list from manager to client.
 */
class DependenciesController {
    const NAMESPACE = 'wpkj-pl/v1';

    /** Register dependency routes (publicly readable). */
    public function register_routes() {
        register_rest_route( self::NAMESPACE, '/dependencies', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_dependencies' ],
                'permission_callback' => '__return_true',
            ],
        ] );
    }

    /**
     * Get dependencies: delegate to ApiClient calling manager `/dependencies`.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response object.
     */
    public function get_dependencies( $request ) {
        $client = new ApiClient();
        $data = $client->get_dependencies();
        return rest_ensure_response( is_array( $data ) ? $data : [] );
    }
}