<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * [matthews_order] shortcode — single entry point that renders different
 * front-end templates based on the mop_view query var.
 *
 * Supported views:
 *   login                     (default if not logged in)
 *   request-password-reset
 *   update-password
 *   my-account                (default if logged in)
 *   edit-account
 *   create-order
 *   order-confirmation
 */
class MOP_Shortcode {

    const VIEWS = [
        'login',
        'request-password-reset',
        'update-password',
        'my-account',
        'edit-account',
        'create-order',
        'order-confirmation',
    ];

    public static function init() {
        add_shortcode( MOP_SHORTCODE, [ __CLASS__, 'render' ] );
    }

    public static function render( $atts = [], $content = '' ) {
        $view = self::resolve_view();

        $public_views = [ 'login', 'request-password-reset', 'update-password' ];
        if ( ! in_array( $view, $public_views, true ) && ! MOP_Auth::is_logged_in() ) {
            MOP_Auth::require_login();
            $view = 'login';
        }

        $template = MOP_PLUGIN_DIR . 'templates/' . $view . '.php';
        if ( ! file_exists( $template ) ) {
            return '';
        }

        ob_start();
        include $template;
        return ob_get_clean();
    }

    private static function resolve_view() {
        $requested = isset( $_GET['mop_view'] ) ? sanitize_key( wp_unslash( $_GET['mop_view'] ) ) : '';
        if ( in_array( $requested, self::VIEWS, true ) ) {
            return $requested;
        }
        return MOP_Auth::is_logged_in() ? 'my-account' : 'login';
    }
}
