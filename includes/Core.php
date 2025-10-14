<?php
namespace WPKJ\PatternsLibrary\Includes;

use WPKJ\PatternsLibrary\Api\ApiClient;
use WPKJ\PatternsLibrary\Frontend\Frontend;
use WPKJ\PatternsLibrary\Includes\Assets;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Core {
    private $plugin_name;
    private $version;

    private $api_client;
    private $frontend;
    private $assets;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;

        $this->api_client = new ApiClient();
        $this->frontend   = new Frontend( $this->api_client );
        $this->assets     = new Assets();
    }

    public function run() {
        add_action( 'init', [ $this->frontend, 'register_patterns' ] );
        $this->assets->hooks();
    }
}