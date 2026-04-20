<?php
/**
 * Plugin Name: Matthews Feed and Grain Order Form
 * Plugin URI:  https://github.com/SolutionsByCarson/matthewsorderplugin
 * Description: Shortcode-based customer order submission for Matthews Feed and Grain. Generates FMM ORDIMP.DAT files.
 * Version:     0.5.1
 * Author:      SolutionsByCarson
 * License:     GPL-2.0+
 * Text Domain: matthewsorderplugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MOP_VERSION',       '0.5.1' );
define( 'MOP_DB_VERSION',    '0.3.0' );
define( 'MOP_SESSION_DAYS',  30 );
define( 'MOP_RESET_MINUTES', 60 );
define( 'MOP_PLUGIN_FILE',  __FILE__ );
define( 'MOP_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'MOP_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'MOP_PLUGIN_SLUG',  'matthewsorderplugin' );
define( 'MOP_SHORTCODE',    'matthews_order' );
define( 'MOP_TABLE_PREFIX', 'mop_' );
define( 'MOP_UPLOAD_SUBDIR','order' );
define( 'MOP_COOKIE_NAME',  'mop_auth' );

require_once MOP_PLUGIN_DIR . 'includes/class-mop-activator.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-deactivator.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-database.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-settings.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-user.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-product.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-session.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-auth.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-assets.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-shortcode.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-email.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-ordimp.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-handlers.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-admin.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-admin-users.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-admin-products.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-cli.php';
require_once MOP_PLUGIN_DIR . 'includes/class-mop-plugin.php';

register_activation_hook(   __FILE__, [ 'MOP_Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'MOP_Deactivator', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'MOP_Plugin', 'boot' ] );
