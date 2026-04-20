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
            'strings' => [
                'add'        => __( 'Add to order', 'matthewsorderplugin' ),
                'update'     => __( 'Update quantity', 'matthewsorderplugin' ),
                'modify'     => __( 'Modify', 'matthewsorderplugin' ),
                'remove'     => __( 'Remove', 'matthewsorderplugin' ),
                'emptyCart'  => __( 'No products added yet. Select items above to build your order.', 'matthewsorderplugin' ),
                'notReady'   => __( "Order submission isn't wired up yet. Your selections look good — the back-end will be hooked up in the next phase.", 'matthewsorderplugin' ),
                'invalidQty' => __( 'Quantity must be at least 1.', 'matthewsorderplugin' ),
                /* translators: %1$s quantity, %2$s base unit name, e.g. "50 POUND" */
                'totalBase'  => __( 'Order qty in base UoM: %1$s %2$s', 'matthewsorderplugin' ),
            ],
        ] );
    }

    private static function current_post_has_shortcode() {
        global $post;
        return $post instanceof WP_Post && has_shortcode( $post->post_content, MOP_SHORTCODE );
    }
}
