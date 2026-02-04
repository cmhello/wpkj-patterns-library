<?php
namespace WPKJ\PatternsLibrary\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Scheduler {
    public function hooks() {
        // Register custom cron schedule based on cache TTL
        add_filter( 'cron_schedules', [ $this, 'register_deps_ttl_schedule' ] );

        // Reschedule dependency status cron when cache TTL option changes
        add_action( 'update_option_wpkj_patterns_library_cache_ttl', [ $this, 'on_cache_ttl_update' ], 10, 2 );

        // Wire cron event handlers
        add_action( 'wpkj_pl_sync_event', [ $this, 'handle_sync_event' ] );
        add_action( 'wpkj_pl_deps_check_event', [ $this, 'handle_deps_check_event' ] );
    }

    public function register_deps_ttl_schedule( $schedules ) {
        $ttl = (int) get_option( 'wpkj_patterns_library_cache_ttl', 900 );
        $ttl = max( 60, $ttl );
        $schedules['wpkj_pl_deps_ttl'] = [
            'interval' => $ttl,
            'display'  => __( 'WPKJ PL Dependencies Status TTL', 'wpkj-patterns-library' ),
        ];
        return $schedules;
    }

    public function on_cache_ttl_update( $old, $new ) {
        $timestamp = wp_next_scheduled( 'wpkj_pl_deps_check_event' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wpkj_pl_deps_check_event' );
        }
        // Ensure custom schedule is registered and reschedule with new TTL
        $ttl = max( 60, (int) $new );
        if ( ! wp_next_scheduled( 'wpkj_pl_deps_check_event' ) ) {
            wp_schedule_event( time() + 60, 'wpkj_pl_deps_ttl', 'wpkj_pl_deps_check_event' );
        }
    }

    public function handle_sync_event() {
        $sync = new Sync();
        $sync->run_sync();
        $sync->mark_synced();
    }

    public function handle_deps_check_event() {
        ( new Dependencies() )->refresh_status();
    }
}