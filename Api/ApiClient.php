<?php
namespace WPKJ\PatternsLibrary\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API client for communicating with manager `wpkj/v1` REST endpoints.
 */
class ApiClient {
    /**
     * Manager API base URL (with trailing slash).
     * @var string
     */
    private $base_url;

    /** Constructor: initialize base URL. */
    public function __construct() {
        $this->base_url = $this->get_base_url();
    }

    /**
     * Compute API base URL; prefer option `wpkj_patterns_library_api_base`,
     * otherwise fallback to the current site's `/wp-json/wpkj/v1`.
     */
    private function get_base_url() : string {
        $default = trailingslashit( 'https://mb.wpkz.cn/wp-json/wpkj/v1' );
        $option  = get_option( 'wpkj_patterns_library_api_base' );
        $base    = $option ? $option : apply_filters( 'wpkj_patterns_library_default_api_base', $default );
        return trailingslashit( $base );
    }

    /**
     * Generate request headers; extend via `wpkj_patterns_library_api_headers` filter (e.g., inject JWT).
     */
    private function get_headers() : array {
        $headers = [
            'Accept' => 'application/json',
        ];

        // Allow injecting JWT or custom headers via filter
        $headers = apply_filters( 'wpkj_patterns_library_api_headers', $headers );
        return $headers;
    }

    /** Build URL from path and query parameters. */
    private function build_url( string $path, array $params = [] ) : string {
        $url = $this->base_url . ltrim( $path, '/' );
        if ( ! empty( $params ) ) {
            // Normalize array params - use 'category' and 'type' as array values
            $normalized = [];
            foreach ( $params as $key => $val ) {
                if ( in_array( $key, [ 'category', 'categories', 'type', 'types' ], true ) ) {
                    // Accept scalar, comma string, or array
                    $arr = is_array( $val ) ? $val : ( is_string( $val ) ? preg_split( '/\s*,\s*/', trim( $val ) ) : [ $val ] );
                    $arr = array_filter( array_map( 'intval', $arr ) ); // Convert to integers and filter empty
                    if ( ! empty( $arr ) ) {
                        // Use 'category' or 'type' as key (without []) - WordPress REST API will handle array
                        if ( 'categories' === $key || 'category' === $key ) {
                            $normalized['category'] = $arr;
                        } else {
                            $normalized['type'] = $arr;
                        }
                    }
                } else {
                    $normalized[ $key ] = $val;
                }
            }
            // add_query_arg serializes arrays as repeated keys automatically
            $url = add_query_arg( $normalized, $url );
        }
        return $url;
    }

    /** Get cache TTL (default 15 minutes), adjustable via filter. */
    private function get_cache_ttl( string $path, array $params = [] ) : int {
        $opt = (int) get_option( 'wpkj_patterns_library_cache_ttl', 900 );
        return (int) apply_filters( 'wpkj_patterns_library_cache_ttl', $opt, $path, $params );
    }

    /**
     * Perform GET request and cache the response; inject Bearer token if option
     * `wpkj_patterns_library_jwt` is set. Returns array data, empty array on failure.
     */
    private function request( string $path, array $params = [] ) {
        $url       = $this->build_url( $path, $params );
        $cache_key = 'wpkj_pl_' . md5( $url );

        $bypass = (bool) apply_filters( 'wpkj_patterns_library_bypass_cache', false, $path, $params );
        if ( ! $bypass ) {
            // Try object cache first (Redis/Memcached if available)
            $cached = wp_cache_get( $cache_key, 'wpkj_patterns_library' );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        // Inject JWT from option (if present)
        $headers = $this->get_headers();
        $jwt     = get_option( 'wpkj_patterns_library_jwt', '' );
        if ( ! empty( $jwt ) ) {
            $headers['Authorization'] = 'Bearer ' . $jwt;
        }

        // Basic rate limiting: avoid hammering on repeated failures
        $response = $this->robust_get( $url, $headers );
        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( null === $data ) {
            return [];
        }

        $ttl = $this->get_cache_ttl( $path, $params );
        // Set in object cache (with group for easy bulk clearing)
        wp_cache_set( $cache_key, $data, 'wpkj_patterns_library', $ttl );
        return $data;
    }

    /**
     * Perform GET request but cache only minimal fields for list endpoints.
     * Adds a query marker `fields=min` to avoid conflicting with full-cache keys.
     */
    private function request_min( string $path, array $params = [] ) {
        $params_min = $params;
        $params_min['fields'] = 'min'; // marker for URL uniqueness

        $url       = $this->build_url( $path, $params_min );
        $cache_key = 'wpkj_pl_' . md5( $url );

        $bypass = (bool) apply_filters( 'wpkj_patterns_library_bypass_cache', false, $path, $params_min );
        if ( ! $bypass ) {
            $cached = wp_cache_get( $cache_key, 'wpkj_patterns_library' );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        // Inject JWT from option (if present)
        $headers = $this->get_headers();
        $jwt     = get_option( 'wpkj_patterns_library_jwt', '' );
        if ( ! empty( $jwt ) ) {
            $headers['Authorization'] = 'Bearer ' . $jwt;
        }

        // Use the same URL with fields=min parameter
        $response = $this->robust_get( $url, $headers );
        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( null === $data ) {
            return [];
        }

        // Keep only minimal fields to reduce storage: id, title, link, featured_image
        if ( is_array( $data ) ) {
            $min = [];
            foreach ( $data as $row ) {
                if ( is_array( $row ) ) {
                    $min[] = [
                        'id' => $row['id'] ?? null,
                        'title' => $row['title'] ?? '',
                        'link' => $row['link'] ?? '',
                        'featured_image' => $row['featured_image'] ?? '',
                    ];
                }
            }
            $data = $min;
        }

        $ttl = $this->get_cache_ttl( $path, $params_min );
        wp_cache_set( $cache_key, $data, 'wpkj_patterns_library', $ttl );
        return $data;
    }

    /**
     * Fetch patterns list (paginated).
     *
     * @param array $args Query args: per_page, page, orderby, order.
     * @return array Patterns array.
     */
    public function get_patterns( array $args = [] ) : array {
        $args = wp_parse_args( $args, [
            'per_page' => 10,
            'page'     => 1,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ] );
        // Normalize category/type filters to use 'category' and 'type' keys
        foreach ( [ 'category', 'categories', 'type', 'types' ] as $k ) {
            if ( isset( $args[ $k ] ) ) {
                $v = $args[ $k ];
                $arr = is_array( $v ) ? $v : ( is_string( $v ) ? preg_split( '/\s*,\s*/', trim( $v ) ) : [ $v ] );
                $arr = array_filter( array_map( 'intval', $arr ) );
                if ( ! empty( $arr ) ) {
                    if ( 'category' === $k || 'categories' === $k ) {
                        $args['category'] = $arr;
                        unset( $args['categories'] );
                    } else {
                        $args['type'] = $arr;
                        unset( $args['types'] );
                    }
                } else {
                    // Remove empty filters
                    unset( $args[ $k ] );
                }
            }
        }
        $data = $this->request( 'patterns', $args );
        return is_array( $data ) ? $data : [];
    }

    /** Fetch patterns list (minimal fields, for caching/prewarm only). */
    public function get_patterns_min( array $args = [] ) : array {
        $args = wp_parse_args( $args, [
            'per_page' => 10,
            'page'     => 1,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ] );
        foreach ( [ 'category', 'categories', 'type', 'types' ] as $k ) {
            if ( isset( $args[ $k ] ) ) {
                $v = $args[ $k ];
                $arr = is_array( $v ) ? $v : ( is_string( $v ) ? preg_split( '/\s*,\s*/', trim( $v ) ) : [ $v ] );
                $arr = array_filter( array_map( 'intval', $arr ) );
                if ( ! empty( $arr ) ) {
                    if ( 'category' === $k || 'categories' === $k ) {
                        $args['category'] = $arr;
                        unset( $args['categories'] );
                    } else {
                        $args['type'] = $arr;
                        unset( $args['types'] );
                    }
                } else {
                    unset( $args[ $k ] );
                }
            }
        }
        $data = $this->request_min( 'patterns', $args );
        return is_array( $data ) ? $data : [];
    }

    /** Fetch all patterns (auto pagination, up to 100 per page). */
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

    /** Get categories list. */
    public function get_categories() : array {
        $data = $this->request( 'categories' );
        return is_array( $data ) ? $data : [];
    }

    /** Get types list. */
    public function get_types() : array {
        $data = $this->request( 'types' );
        return is_array( $data ) ? $data : [];
    }

    /** Get single pattern detail by ID. */
    public function get_pattern( int $id ) : array {
        $data = $this->request( 'patterns/' . $id );
        return is_array( $data ) ? $data : [];
    }

    /** Get dependencies list. */
    public function get_dependencies() : array {
        $data = $this->request( 'dependencies' );
        return is_array( $data ) ? $data : [];
    }

    /** Search patterns by keyword. */
    public function search( string $q, array $args = [] ) : array {
        $params = wp_parse_args( $args, [ 'q' => $q ] );
        // Normalize category/type filters if provided in $args
        foreach ( [ 'category', 'categories', 'type', 'types' ] as $k ) {
            if ( isset( $params[ $k ] ) ) {
                $v = $params[ $k ];
                $arr = is_array( $v ) ? $v : ( is_string( $v ) ? preg_split( '/\s*,\s*/', trim( $v ) ) : [ $v ] );
                $arr = array_filter( array_map( 'intval', $arr ) );
                if ( ! empty( $arr ) ) {
                    if ( 'category' === $k || 'categories' === $k ) {
                        $params['category'] = $arr;
                        unset( $params['categories'] );
                    } else {
                        $params['type'] = $arr;
                        unset( $params['types'] );
                    }
                } else {
                    unset( $params[ $k ] );
                }
            }
        }
        $data = $this->request( 'search', $params );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Import a pattern (server-side POST, no caching).
     * Returns parsed JSON or empty array on failure.
     */
    public function import_pattern( int $id ) : array {
        $path    = 'patterns/' . $id . '/import';
        $url     = $this->build_url( $path );
        $headers = $this->get_headers();
        // Inject JWT from server-side option if present; frontend does not pass tokens.
        $jwt     = get_option( 'wpkj_patterns_library_jwt', '' );
        if ( ! empty( $jwt ) ) {
            $headers['Authorization'] = 'Bearer ' . $jwt;
        }
        $headers['Content-Type'] = 'application/json';

        $response = wp_remote_post( $url, [ 'headers' => $headers, 'timeout' => 20, 'body' => json_encode( [] ) ] );
        if ( is_wp_error( $response ) ) {
            return [];
        }
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        return ( null === $data ) ? [] : $data;
    }

    /**
     * Robust GET with simple rate limit and exponential backoff on 5xx.
     * - Tracks last failure timestamp in object cache for 60s cooldown.
     * - Retries up to 3 times with backoff 0.5s, 1s, 2s for 5xx.
     */
    private function robust_get( string $url, array $headers ) {
        $cool_key = 'wpkj_pl_cool_' . md5( parse_url( $url, PHP_URL_HOST ) . parse_url( $url, PHP_URL_PATH ) );
        $cool_until = (int) wp_cache_get( $cool_key, 'wpkj_patterns_library' );
        if ( $cool_until > time() ) {
            return new \WP_Error( 'wpkj_pl_rate_limited', 'Rate limited due to previous failures' );
        }

        $timeouts = [ 15, 15, 15 ];
        $backoffs = [ 0.5, 1.0, 2.0 ];
        for ( $i = 0; $i < 3; $i++ ) {
            $response = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => $timeouts[ $i ] ] );
            if ( is_wp_error( $response ) ) {
                // Network error: set short cooldown and bail
                wp_cache_set( $cool_key, time() + 30, 'wpkj_patterns_library', 60 );
                return $response;
            }
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code >= 500 ) {
                // Backoff on server errors
                usleep( (int) ( $backoffs[ $i ] * 1000000 ) );
                continue;
            }
            // Success or 4xx: return immediately (4xx will be handled by caller)
            if ( $code >= 200 && $code < 300 ) {
                return $response;
            } else {
                return $response;
            }
        }
        // After retries, set cooldown for 60s
        wp_cache_set( $cool_key, time() + 60, 'wpkj_patterns_library', 60 );
        return new \WP_Error( 'wpkj_pl_backoff_fail', 'Remote server error after retries' );
    }

    /** Test connectivity to remote manager API. Returns [ 'ok' => bool, 'code' => int, 'message' => string ]. */
    public function test_connectivity() : array {
        $url = $this->build_url( 'categories' );
        $headers = $this->get_headers();
        $response = $this->robust_get( $url, $headers );
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'code' => 0, 'message' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $ok = ( $code >= 200 && $code < 300 );
        return [ 'ok' => $ok, 'code' => $code, 'message' => $ok ? 'OK' : ( wp_remote_retrieve_response_message( $response ) ?: 'Error' ) ];
    }
}