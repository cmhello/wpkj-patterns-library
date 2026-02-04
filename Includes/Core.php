<?php
namespace WPKJ\PatternsLibrary\Includes;

use WPKJ\PatternsLibrary\Api\ApiClient;
use WPKJ\PatternsLibrary\Api\FavoritesController;
use WPKJ\PatternsLibrary\Api\DepsController;
use WPKJ\PatternsLibrary\Api\ManagerProxyController;
use WPKJ\PatternsLibrary\Api\MediaSideloadController;
use WPKJ\PatternsLibrary\Includes\Assets;
use WPKJ\PatternsLibrary\Includes\I18n;
use WPKJ\PatternsLibrary\Includes\Scheduler;
use WPKJ\PatternsLibrary\Admin\AdminActions;
use WPKJ\PatternsLibrary\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Core {
    private $plugin_name;
    private $version;

    private $api_client;
    private $assets;
    private $i18n;
    private $scheduler;
    private $admin_actions;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;

        $this->api_client   = new ApiClient();
        $this->assets       = new Assets();
        $this->i18n         = new I18n();
        $this->scheduler    = new Scheduler();
        $this->admin_actions = new AdminActions();
    }

    public function run() {
        // I18n
        $this->i18n->hooks();

        // Admin actions (admin_post handlers)
        $this->admin_actions->hooks();

        // Scheduler (cron schedules + handlers + TTL reschedule)
        $this->scheduler->hooks();

        // Dependencies lifecycle listeners
        ( new Dependencies() )->hooks();

        add_action( 'rest_api_init', function() {
            ( new FavoritesController() )->register_routes();
            ( new DepsController() )->register_routes();
            ( new ManagerProxyController() )->register_routes();
            ( new MediaSideloadController() )->register_routes();
        } );
        // Settings page hooks (menu + register settings)
        ( new Settings() )->hooks();

        $this->assets->hooks();
        
        // Smart cache warmup on admin init (only if needed)
        add_action( 'admin_init', function() {
            // Only run in admin and for users who can edit posts
            if ( is_admin() && current_user_can( 'edit_posts' ) ) {
                $sync = new Sync();
                // Check if warmup is needed, run in background if so
                if ( $sync->should_sync() ) {
                    // Use shutdown hook to avoid blocking page load
                    add_action( 'shutdown', function() use ( $sync ) {
                        $sync->run_sync();
                        $sync->mark_synced();
                    } );
                }
            }
        }, 999 );
    }
}
