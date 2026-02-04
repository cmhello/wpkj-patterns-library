<?php
/**
 * Media Sideload Controller
 * 
 * Handles downloading external media (images, videos) from imported patterns
 * and saving them to the WordPress media library.
 * 
 * @package WPKJ\PatternsLibrary
 */

namespace WPKJ\PatternsLibrary\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MediaSideloadController {

    const NAMESPACE = 'wpkj-pl/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route( self::NAMESPACE, '/sideload-media', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'sideload_media' ],
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'args'                => [
                'content' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'wp_kses_post',
                    'description'       => 'Pattern HTML content',
                ],
                'pattern_id' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'Pattern ID for reference',
                ],
            ],
        ] );
    }

    /**
     * Sideload media from pattern content
     * 
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function sideload_media( $request ) {
        $content    = $request->get_param( 'content' );
        $pattern_id = $request->get_param( 'pattern_id' );

        if ( empty( $content ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => __( 'Empty content provided', 'wpkj-patterns-library' ),
            ], 400 );
        }

        // Increase time limit for large downloads
        if ( ! ini_get( 'safe_mode' ) ) {
            @set_time_limit( 300 ); // 5 minutes max
        }

        // Require WordPress media functions
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Extract all media URLs from content
        $all_media = $this->extract_media_urls( $content );

        // Filter: only external URLs (not current site)
        $external_media = $this->filter_external_urls( $all_media );

        // Separate images and videos
        $images = array_filter( $external_media, [ $this, 'is_image_url' ] );
        $videos = array_filter( $external_media, [ $this, 'is_video_url' ] );

        // Pre-check existing downloads (batch query to avoid N+1)
        $existing_map = $this->batch_find_by_original_urls( $images );

        $url_map    = []; // Original URL => Local URL
        $failed     = [];
        $skipped    = [];

        // Process images
        foreach ( $images as $url ) {
            try {
                // Use cached result if available
                if ( isset( $existing_map[ $url ] ) ) {
                    $url_map[ $url ] = $existing_map[ $url ];
                    continue;
                }

                $local_url = $this->download_and_save( $url );
                
                if ( $local_url && ! is_wp_error( $local_url ) ) {
                    $url_map[ $url ] = $local_url;
                } else {
                    $failed[] = $url;
                }
            } catch ( \Exception $e ) {
                $failed[] = $url;
                
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'WPKJ PL Media Sideload Error: ' . $e->getMessage() );
                }
            }
        }

        // Videos: skip but track
        $skipped = $videos;

        // Replace URLs in content
        $new_content = $this->replace_urls( $content, $url_map );

        // Build response
        $response = [
            'success'       => true,
            'content'       => $new_content,
            'stats'         => [
                'total'     => count( $external_media ),
                'images'    => count( $images ),
                'videos'    => count( $videos ),
                'downloaded' => count( $url_map ),
                'failed'    => count( $failed ),
                'skipped'   => count( $skipped ),
            ],
            'url_map'       => $url_map,
            'failed_urls'   => $failed,
            'video_urls'    => $videos,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Extract all media URLs from HTML content
     * 
     * @param string $content
     * @return array
     */
    private function extract_media_urls( $content ) {
        $urls = [];

        // 1. Extract <img src="...">
        if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches ) ) {
            $urls = array_merge( $urls, $matches[1] );
        }

        // 2. Extract <video poster="...">
        if ( preg_match_all( '/<video[^>]+poster=["\']([^"\']+)["\']/', $content, $matches ) ) {
            $urls = array_merge( $urls, $matches[1] );
        }

        // 3. Extract <source src="..."> (video sources)
        if ( preg_match_all( '/<source[^>]+src=["\']([^"\']+)["\']/', $content, $matches ) ) {
            $urls = array_merge( $urls, $matches[1] );
        }

        // 4. Extract CSS background-image: url(...)
        if ( preg_match_all( '/background-image:\s*url\(["\']?([^"\')\s]+)["\']?\)/i', $content, $matches ) ) {
            $urls = array_merge( $urls, $matches[1] );
        }

        // 5. Extract data-* attributes (common in blocks)
        if ( preg_match_all( '/data-[a-z-]+=["\']([^"\']+\.(jpg|jpeg|png|gif|webp|svg|mp4|webm|ogg))["\']/', $content, $matches ) ) {
            $urls = array_merge( $urls, $matches[1] );
        }

        // Clean and deduplicate
        $urls = array_map( 'trim', $urls );
        $urls = array_filter( $urls ); // Remove empty
        $urls = array_unique( $urls );

        return array_values( $urls );
    }

    /**
     * Filter URLs to only include external ones (not current site)
     * 
     * @param array $urls
     * @return array
     */
    private function filter_external_urls( $urls ) {
        $site_url = get_site_url();
        $site_host = parse_url( $site_url, PHP_URL_HOST );

        return array_filter( $urls, function( $url ) use ( $site_host ) {
            // Must be absolute URL
            if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                return false;
            }

            $host = parse_url( $url, PHP_URL_HOST );
            
            // Exclude current site
            return $host !== $site_host;
        } );
    }

    /**
     * Check if URL is an image
     * 
     * @param string $url
     * @return bool
     */
    private function is_image_url( $url ) {
        $image_exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico' ];
        $ext = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        
        return in_array( $ext, $image_exts, true );
    }

    /**
     * Check if URL is a video
     * 
     * @param string $url
     * @return bool
     */
    private function is_video_url( $url ) {
        $video_exts = [ 'mp4', 'webm', 'ogg', 'mov', 'avi', 'wmv', 'flv' ];
        $ext = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        
        return in_array( $ext, $video_exts, true );
    }

    /**
     * Download media and save to WordPress media library
     * 
     * @param string $url
     * @return string|WP_Error Local URL or error
     */
    private function download_and_save( $url ) {
        // Check if already downloaded (by original URL)
        $existing_id = $this->find_by_original_url( $url );
        
        if ( $existing_id ) {
            $local_url = wp_get_attachment_url( $existing_id );
            if ( $local_url ) {
                return $local_url;
            }
        }

        // Download file with timeout control
        add_filter( 'http_request_timeout', [ $this, 'set_download_timeout' ] );
        $tmp_file = download_url( $url );
        remove_filter( 'http_request_timeout', [ $this, 'set_download_timeout' ] );
        
        if ( is_wp_error( $tmp_file ) ) {
            return $tmp_file;
        }

        // Prepare file array
        $file_array = [
            'name'     => basename( parse_url( $url, PHP_URL_PATH ) ),
            'tmp_name' => $tmp_file,
        ];

        // Sideload into media library
        $id = media_handle_sideload( $file_array, 0 );

        // Clean up temp file
        if ( file_exists( $tmp_file ) ) {
            @unlink( $tmp_file );
        }

        if ( is_wp_error( $id ) ) {
            return $id;
        }

        // Store original URL as meta (for deduplication)
        update_post_meta( $id, '_wpkj_original_url', $url );

        // Return local URL
        return wp_get_attachment_url( $id );
    }

    /**
     * Set download timeout to 30 seconds
     * 
     * @return int
     */
    public function set_download_timeout() {
        return 30;
    }

    /**
     * Batch find attachments by original URLs (avoid N+1 queries)
     * 
     * @param array $urls
     * @return array URL => Local URL map
     */
    private function batch_find_by_original_urls( $urls ) {
        if ( empty( $urls ) ) {
            return [];
        }

        global $wpdb;

        // Escape URLs for SQL IN clause
        $placeholders = implode( ', ', array_fill( 0, count( $urls ), '%s' ) );
        
        $query = $wpdb->prepare(
            "SELECT pm.meta_value as original_url, p.ID 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = %s
            AND pm.meta_value IN ({$placeholders})
            AND p.post_type = %s
            AND p.post_status = %s",
            array_merge(
                [ '_wpkj_original_url' ],
                $urls,
                [ 'attachment', 'inherit' ]
            )
        );

        $results = $wpdb->get_results( $query );

        $map = [];
        if ( $results ) {
            foreach ( $results as $row ) {
                $local_url = wp_get_attachment_url( $row->ID );
                if ( $local_url ) {
                    $map[ $row->original_url ] = $local_url;
                }
            }
        }

        return $map;
    }

    /**
     * Find attachment by original URL
     * 
     * @param string $url
     * @return int|null Attachment ID or null
     */
    private function find_by_original_url( $url ) {
        $query = new \WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'meta_key'       => '_wpkj_original_url',
            'meta_value'     => $url,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ] );

        return ! empty( $query->posts ) ? $query->posts[0] : null;
    }

    /**
     * Replace URLs in content
     * 
     * @param string $content
     * @param array $url_map Original URL => Local URL
     * @return string
     */
    private function replace_urls( $content, $url_map ) {
        if ( empty( $url_map ) ) {
            return $content;
        }

        // Sort by URL length (longest first) to avoid partial replacements
        uksort( $url_map, function( $a, $b ) {
            return strlen( $b ) - strlen( $a );
        } );

        foreach ( $url_map as $old => $new ) {
            $content = str_replace( $old, $new, $content );
        }

        return $content;
    }
}
