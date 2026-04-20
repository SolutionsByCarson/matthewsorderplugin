<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin activation handler.
 *
 * - Creates custom tables (delegated to MOP_Database::install()).
 * - Creates wp-content/order/ upload tree with .htaccess blocking direct access.
 * - Seeds default settings.
 */
class MOP_Activator {

    public static function activate() {
        require_once MOP_PLUGIN_DIR . 'includes/class-mop-database.php';
        MOP_Database::install();

        self::create_upload_dir();
        self::seed_default_settings();
    }

    private static function create_upload_dir() {
        $base = WP_CONTENT_DIR . '/' . MOP_UPLOAD_SUBDIR;

        if ( ! file_exists( $base ) ) {
            wp_mkdir_p( $base );
        }

        $htaccess = $base . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $rules = "# Matthews Order Plugin — deny direct access\n"
                   . "Order allow,deny\n"
                   . "Deny from all\n"
                   . "<IfModule mod_authz_core.c>\n"
                   . "    Require all denied\n"
                   . "</IfModule>\n";
            file_put_contents( $htaccess, $rules );
        }

        $index = $base . '/index.html';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '' );
        }
    }

    private static function seed_default_settings() {
        if ( false === get_option( 'mop_settings' ) ) {
            add_option( 'mop_settings', [
                'shortcode_url' => '',
                'admin_email'   => get_option( 'admin_email' ),
            ] );
        }
    }
}
