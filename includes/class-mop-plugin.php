<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin bootstrap — wires together the components loaded in matthewsorderplugin.php.
 *
 * Also runs MOP_Database::install() on every load if the stored schema
 * version is stale, so pulls/upgrades converge without needing a manual
 * deactivate → reactivate.
 */
final class MOP_Plugin {

    public static function boot() {
        MOP_Database::install();

        MOP_Settings::init();
        MOP_Auth::init();
        MOP_Assets::init();
        MOP_Shortcode::init();
        MOP_Handlers::init();
        MOP_Admin::init();
    }
}
