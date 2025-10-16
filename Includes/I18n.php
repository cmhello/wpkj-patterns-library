<?php
namespace WPKJ\PatternsLibrary\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class I18n {
    public function hooks() {
        add_action( 'init', [ $this, 'load_textdomain' ], 5 );
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'wpkj-patterns-library',
            false,
            dirname( plugin_basename( WPKJ_PL_FILE ) ) . '/languages'
        );
    }
}