<?php
namespace WPKJ\PatternsLibrary\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Deactivator {
    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'wpkj_pl_sync_event' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wpkj_pl_sync_event' );
        }
        // Unschedule dependency status refresh
        $ts2 = wp_next_scheduled( 'wpkj_pl_deps_check_event' );
        if ( $ts2 ) {
            wp_unschedule_event( $ts2, 'wpkj_pl_deps_check_event' );
        }
    }
}