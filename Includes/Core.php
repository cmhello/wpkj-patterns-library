<?php
namespace WPKJ\PatternsLibrary\Includes;

use WPKJ\PatternsLibrary\Api\ApiClient;
use WPKJ\PatternsLibrary\Api\FavoritesController;
use WPKJ\PatternsLibrary\Api\DepsController;
use WPKJ\PatternsLibrary\Api\ManagerProxyController;
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
        } );
        // Settings page hooks (menu + register settings)
        ( new Settings() )->hooks();

        $this->assets->hooks();
    }
}
