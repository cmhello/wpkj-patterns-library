<?php
namespace WPKJ\PatternsLibrary\Includes;

use WPKJ\PatternsLibrary\Api\ApiClient;
use WPKJ\PatternsLibrary\Api\FavoritesController;
use WPKJ\PatternsLibrary\Api\DepsController;
use WPKJ\PatternsLibrary\Includes\Assets;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Core {
    private $plugin_name;
    private $version;

    private $api_client;
    private $assets;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;

        $this->api_client = new ApiClient();
        $this->assets     = new Assets();
    }

    public function run() {
        add_action( 'rest_api_init', function() {
            ( new FavoritesController() )->register_routes();
            ( new DepsController() )->register_routes();
        } );
        $this->assets->hooks();
    }
}
