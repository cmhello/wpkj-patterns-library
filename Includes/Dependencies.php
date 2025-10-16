<?php
namespace WPKJ\PatternsLibrary\Includes;

use WPKJ\PatternsLibrary\Api\ApiClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dependencies {
    const STATUS_TRANSIENT = 'wpkj_pl_deps_status';
    const STATUS_TTL       = 12 * HOUR_IN_SECONDS; // legacy default; overridden by option

    /** Get TTL (seconds) for status transient, from settings with sane minimum. */
    private function get_status_ttl() : int {
        $opt = (int) get_option( 'wpkj_patterns_library_cache_ttl', 900 );
        $ttl = $opt > 0 ? $opt : self::STATUS_TTL;
        // Ensure at least 60 seconds to avoid thrashing
        $ttl = max( 60, $ttl );
        /**
         * Filter the TTL used for dependency status transient.
         *
         * @param int $ttl  TTL in seconds.
         */
        return (int) apply_filters( 'wpkj_pl_deps_status_ttl', $ttl );
    }

    /** Compute required dependencies from manager API. */
    public function get_required_list() : array {
        $api = new ApiClient();
        $list = $api->get_dependencies();
        $required = [];
        if ( is_array( $list ) ) {
            foreach ( $list as $item ) {
                $slug = '';
                $name = '';
                $is_required = false;
                if ( is_string( $item ) ) {
                    $slug = sanitize_key( $item );
                    $name = $slug;
                    $is_required = true; // assume required if only slug provided
                } elseif ( is_array( $item ) ) {
                    $slug = isset( $item['info']['slug'] ) ? sanitize_key( $item['info']['slug'] ) : ( isset( $item['slug'] ) ? sanitize_key( $item['slug'] ) : '' );
                    $name = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : ( $slug ?: '' );
                    $is_required = ! empty( $item['info']['required'] );
                }
                if ( $slug && $is_required ) {
                    $required[] = [ 'slug' => $slug, 'name' => $name ];
                }
            }
        }
        // De-duplicate by slug
        $seen = [];
        $normalized = [];
        foreach ( $required as $r ) {
            if ( ! isset( $seen[ $r['slug'] ] ) ) {
                $seen[ $r['slug'] ] = true;
                $normalized[] = $r;
            }
        }
        return $normalized;
    }

    /** Map installed plugins to slug => [file, active]. */
    private function get_installed_map() : array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = (array) get_plugins();
        $active = (array) get_option( 'active_plugins', [] );
        $network_active = (array) get_site_option( 'active_sitewide_plugins', [] );
        $map = [];
        foreach ( $all as $file => $data ) {
            $slug = strpos( $file, '/' ) !== false ? substr( $file, 0, strpos( $file, '/' ) ) : $file;
            if ( ! $slug ) continue;
            $is_active = in_array( $file, $active, true ) || isset( $network_active[ $file ] );
            $map[ $slug ] = [ 'file' => $file, 'active' => $is_active ];
        }
        return $map;
    }

    /** Compute and return dependency status; does not write transient. */
    public function compute_status() : array {
        $req = $this->get_required_list();
        $installed_map = $this->get_installed_map();
        $details = [];
        $all_ready = true;
        foreach ( $req as $item ) {
            $slug = $item['slug'];
            $name = $item['name'];
            $installed = isset( $installed_map[ $slug ] );
            $active = $installed ? ! empty( $installed_map[ $slug ]['active'] ) : false;
            if ( ! $installed || ! $active ) {
                $all_ready = false;
            }
            $details[] = [ 'slug' => $slug, 'name' => $name, 'installed' => $installed, 'active' => $active ];
        }
        return [ 'all_ready' => $all_ready, 'required' => $details ];
    }

    /** Get cached status, recompute if expired or missing. */
    public function get_status() : array {
        $cached = get_transient( self::STATUS_TRANSIENT );
        if ( is_array( $cached ) ) return $cached;
        return $this->refresh_status();
    }

    /** Force recompute and cache status. */
    public function refresh_status() : array {
        $status = $this->compute_status();
        set_transient( self::STATUS_TRANSIENT, $status, $this->get_status_ttl() );
        return $status;
    }

    /** Install or activate a plugin by slug. Returns result array. */
    public function ensure_plugin_ready( string $slug ) : array {
        $slug = sanitize_key( $slug );
        $installed_map = $this->get_installed_map();
        $result = [ 'slug' => $slug, 'installed' => false, 'activated' => false, 'error' => '' ];

        // If installed
        if ( isset( $installed_map[ $slug ] ) ) {
            $result['installed'] = true;
            $file = $installed_map[ $slug ]['file'];
            if ( ! $installed_map[ $slug ]['active'] ) {
                if ( ! current_user_can( 'activate_plugins' ) ) {
                    $result['error'] = 'no_permission_activate';
                } else {
                    $act = activate_plugin( $file );
                    if ( is_wp_error( $act ) ) {
                        $result['error'] = $act->get_error_message();
                    } else {
                        $result['activated'] = true;
                    }
                }
            } else {
                $result['activated'] = true;
            }
            return $result;
        }

        // Not installed: attempt install
        if ( ! current_user_can( 'install_plugins' ) ) {
            $result['error'] = 'no_permission_install';
            return $result;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $api = plugins_api( 'plugin_information', [ 'slug' => $slug, 'fields' => [ 'sections' => false ] ] );
        if ( is_wp_error( $api ) ) {
            $result['error'] = 'plugin_info_failed';
            return $result;
        }

        $upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
        $installed = $upgrader->install( $api->download_link );
        if ( is_wp_error( $installed ) || ! $installed ) {
            $result['error'] = is_wp_error( $installed ) ? $installed->get_error_message() : 'install_failed';
            return $result;
        }

        // After install, find file and activate
        $installed_map = $this->get_installed_map();
        if ( isset( $installed_map[ $slug ] ) ) {
            $result['installed'] = true;
            $file = $installed_map[ $slug ]['file'];
            if ( current_user_can( 'activate_plugins' ) ) {
                $act = activate_plugin( $file );
                if ( is_wp_error( $act ) ) {
                    $result['error'] = $act->get_error_message();
                } else {
                    $result['activated'] = true;
                }
            }
        }

        return $result;
    }

    /** Ensure all required plugins ready; limit specific slugs if provided. */
    public function ensure_all_ready( array $limit_slugs = [] ) : array {
        $required = $this->get_required_list();
        $targets = [];
        $limit = array_map( 'sanitize_key', $limit_slugs );
        if ( $limit ) {
            foreach ( $required as $r ) { if ( in_array( $r['slug'], $limit, true ) ) $targets[] = $r['slug']; }
        } else {
            foreach ( $required as $r ) { $targets[] = $r['slug']; }
        }
        $results = [];
        foreach ( $targets as $slug ) {
            $results[] = $this->ensure_plugin_ready( $slug );
        }
        // Refresh status after operations
        $status = $this->refresh_status();
        return [ 'results' => $results, 'status' => $status ];
    }
}