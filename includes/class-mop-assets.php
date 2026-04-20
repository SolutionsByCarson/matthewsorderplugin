<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conditional front-end asset loader.
 *
 * CSS and JS only enqueue on pages whose post_content contains the
 * [matthews_order] shortcode — no site-wide footprint.
 */
class MOP_Assets {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ] );
    }

    public static function maybe_enqueue() {
        if ( ! self::current_post_has_shortcode() ) {
            return;
        }

        wp_enqueue_style(
            'matthewsorderplugin',
            MOP_PLUGIN_URL . 'assets/css/matthewsorder.css',
            [],
            MOP_VERSION
        );

        wp_enqueue_script(
            'matthewsorderplugin',
            MOP_PLUGIN_URL . 'assets/js/matthewsorder.js',
            [ 'jquery' ],
            MOP_VERSION,
            true
        );

        wp_localize_script( 'matthewsorderplugin', 'MOP', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'mop_ajax' ),
        ] );
    }

    private static function current_post_has_shortcode() {
        global $post;
        return $post instanceof WP_Post && has_shortcode( $post->post_content, MOP_SHORTCODE );
    }
}
