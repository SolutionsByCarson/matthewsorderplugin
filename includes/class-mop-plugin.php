<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin bootstrap — wires together the components loaded in matthewsorderplugin.php.
 */
final class MOP_Plugin {

    public static function boot() {
        MOP_Settings::init();
        MOP_Auth::init();
        MOP_Assets::init();
        MOP_Shortcode::init();
        MOP_Admin::init();
    }
}
