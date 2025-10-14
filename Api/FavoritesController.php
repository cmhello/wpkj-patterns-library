<?php
namespace WPKJ\PatternsLibrary\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FavoritesController {
    const NAMESPACE = 'wpkj-pl/v1';
    const META_KEY  = 'wpkj_pl_favorites_ids';

    public function register_routes() {
        register_rest_route( self::NAMESPACE, '/favorites', [
            [
                'methods'  => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_favorites' ],
                'permission_callback' => [ $this, 'permissions_check' ],
            ],
            [
                'methods'  => \WP_REST_Server::CREATABLE,
                'callback' => [ $this, 'update_favorites' ],
                'permission_callback' => [ $this, 'permissions_check' ],
                'args'     => [
                    'id' => [
                        'required' => true,
                        'type'     => 'integer',
                    ],
                    'action' => [
                        'default' => 'add',
                        'enum'    => [ 'add', 'remove' ],
                    ],
                ],
            ],
        ] );
    }

    public function permissions_check() {
        // Support application password / cookie auth
        return is_user_logged_in() || current_user_can( 'read' );
    }

    public function get_favorites( $request ) {
        $user_id = get_current_user_id();
        $raw = get_user_meta( $user_id, self::META_KEY, true );
        $list = is_array( $raw ) ? array_map( 'intval', $raw ) : [];
        return rest_ensure_response( $list );
    }

    public function update_favorites( $request ) {
        $user_id = get_current_user_id();
        $id      = (int) $request->get_param( 'id' );
        $action  = $request->get_param( 'action' ) ?: 'add';

        $raw  = get_user_meta( $user_id, self::META_KEY, true );
        $list = is_array( $raw ) ? array_map( 'intval', $raw ) : [];

        if ( $action === 'add' ) {
            if ( ! in_array( $id, $list, true ) ) {
                $list[] = $id;
            }
        } else {
            $list = array_values( array_filter( $list, function( $x ) use ( $id ) { return intval( $x ) !== $id; } ) );
        }

        update_user_meta( $user_id, self::META_KEY, $list );

        return rest_ensure_response( [ 'success' => true, 'favorites' => $list ] );
    }
}