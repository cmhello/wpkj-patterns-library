<?php
namespace WPKJ\PatternsLibrary\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ApiClient {
    private $base_url;

    public function __construct() {
        $this->base_url = $this->get_base_url();
    }

    private function get_base_url() : string {
        $default = trailingslashit( home_url( '/wp-json/wpkj/v1' ) );
        $option  = get_option( 'wpkj_patterns_library_api_base' );
        $base    = $option ? $option : apply_filters( 'wpkj_patterns_library_default_api_base', $default );
        return trailingslashit( $base );
    }

    private function get_headers() : array {
        $headers = [
            'Accept' => 'application/json',
        ];

        // Allow JWT or custom headers via filter
        $headers = apply_filters( 'wpkj_patterns_library_api_headers', $headers );
        return $headers;
    }

    private function build_url( string $path, array $params = [] ) : string {
        $url = $this->base_url . ltrim( $path, '/' );
        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }
        return $url;
    }

    private function get_cache_ttl( string $path, array $params = [] ) : int {
        $opt = (int) get_option( 'wpkj_patterns_library_cache_ttl', 900 );
        return (int) apply_filters( 'wpkj_patterns_library_cache_ttl', $opt, $path, $params ); // default 15 minutes
    }

    private function request( string $path, array $params = [] ) {
        $url       = $this->build_url( $path, $params );
        $cache_key = 'wpkj_pl_' . md5( $url );

        $bypass = (bool) apply_filters( 'wpkj_patterns_library_bypass_cache', false, $path, $params );
        if ( ! $bypass ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        // Inject JWT from option if set
        $headers = $this->get_headers();
        $jwt     = get_option( 'wpkj_patterns_library_jwt', '' );
        if ( ! empty( $jwt ) ) {
            $headers['Authorization'] = 'Bearer ' . $jwt;
        }

        $response = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 15 ] );
        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( null === $data ) {
            return [];
        }

        set_transient( $cache_key, $data, $this->get_cache_ttl( $path, $params ) );
        return $data;
    }

    public function get_patterns( array $args = [] ) : array {
        $args = wp_parse_args( $args, [
            'per_page' => 10,
            'page'     => 1,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ] );
        $data = $this->request( 'patterns', $args );
        return is_array( $data ) ? $data : [];
    }

    public function get_all_patterns( array $args = [] ) : array {
        $per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 100;
        $page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
        $args['per_page'] = $per_page;

        $all = [];
        while ( true ) {
            $args['page'] = $page;
            $batch = $this->get_patterns( $args );
            if ( empty( $batch ) ) {
                break;
            }
            $all = array_merge( $all, $batch );
            if ( count( $batch ) < $per_page ) {
                break;
            }
            $page++;
        }
        return $all;
    }

    public function get_categories() : array {
        $data = $this->request( 'categories' );
        return is_array( $data ) ? $data : [];
    }

    public function get_types() : array {
        $data = $this->request( 'types' );
        return is_array( $data ) ? $data : [];
    }

    public function get_dependencies() : array {
        $data = $this->request( 'dependencies' );
        return is_array( $data ) ? $data : [];
    }

    public function search( string $q, array $args = [] ) : array {
        $params = wp_parse_args( $args, [ 'q' => $q ] );
        $data = $this->request( 'search', $params );
        return is_array( $data ) ? $data : [];
    }
}