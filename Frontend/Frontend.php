<?php
namespace WPKJ\PatternsLibrary\Frontend;

use WPKJ\PatternsLibrary\Api\ApiClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Frontend {
    private $api;

    public function __construct( ApiClient $api ) {
        $this->api = $api;
    }

    public function register_patterns() {
        if ( ! function_exists( 'register_block_pattern' ) || ! function_exists( 'register_block_pattern_category' ) ) {
            return;
        }

        // Only run on admin/editor screens to reduce frontend load
        if ( ! is_admin() ) {
            return;
        }

        // Limit maximum patterns to register to avoid registry overload
        $max_register = (int) get_option( 'wpkj_patterns_library_max_register', 200 );

        // Register categories first
        $categories = $this->api->get_categories();
        foreach ( $categories as $cat ) {
            if ( isset( $cat['slug'], $cat['name'] ) ) {
                register_block_pattern_category( $cat['slug'], [ 'label' => $cat['name'] ] );
            }
        }

        // Register patterns
        // Prefer first page only; full fetch happens in background sync
        $patterns = $this->api->get_patterns( [ 'per_page' => min( 100, $max_register ), 'page' => 1 ] );
        $count = 0;
        foreach ( $patterns as $pattern ) {
            $name = 'wpkj/' . sanitize_title( $pattern['title'] ?? 'pattern' ) . '-' . intval( $pattern['id'] ?? 0 );

            $args = [
                'title'       => $pattern['title'] ?? ( 'Pattern ' . ( $pattern['id'] ?? '' ) ),
                'content'     => $pattern['content'] ?? '',
                'categories'  => [],
            ];

            // Map categories to slugs
            if ( ! empty( $pattern['categories'] ) && is_array( $pattern['categories'] ) ) {
                $slugs = [];
                foreach ( $pattern['categories'] as $term ) {
                    if ( isset( $term['slug'] ) ) {
                        $slugs[] = $term['slug'];
                    }
                }
                $args['categories'] = array_values( array_unique( $slugs ) );
            }

            // Optional fields
            if ( ! empty( $pattern['excerpt'] ) ) {
                $args['description'] = $pattern['excerpt'];
            }

            $meta = isset( $pattern['meta'] ) && is_array( $pattern['meta'] ) ? $pattern['meta'] : [];
            if ( isset( $meta['keywords'] ) && is_array( $meta['keywords'] ) ) {
                $args['keywords'] = array_filter( array_map( 'sanitize_text_field', $meta['keywords'] ) );
            }
            if ( isset( $meta['viewport_width'] ) ) {
                $args['viewportWidth'] = intval( $meta['viewport_width'] );
            }
            if ( isset( $meta['inserter'] ) ) {
                $args['inserter'] = (bool) $meta['inserter'];
            }

            // Avoid duplicate registration
            if ( class_exists( '\\WP_Block_Patterns_Registry' ) ) {
                $registry = \WP_Block_Patterns_Registry::get_instance();
                if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( $name ) ) {
                    continue;
                }
            }

            register_block_pattern( $name, $args );
            $count++;
            if ( $count >= $max_register ) {
                break;
            }
        }
    }
}