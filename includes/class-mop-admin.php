<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress admin menu + screen routing.
 *
 * Editors and administrators can manage plugin data. Each submenu hosts a
 * WP_List_Table-backed screen, with CSV import/export where appropriate
 * (Users + Products) and ORDIMP download on the Orders screen.
 *
 * Phase 1 stub — screen callbacks render placeholders. Real list tables land
 * in Phase 3 once schema is finalized.
 */
class MOP_Admin {

    const CAPABILITY = 'edit_pages'; // Editors + administrators.

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
    }

    public static function register_menu() {
        add_menu_page(
            __( 'Matthews Orders', 'matthewsorderplugin' ),
            __( 'Matthews Orders', 'matthewsorderplugin' ),
            self::CAPABILITY,
            'mop_dashboard',
            [ __CLASS__, 'render_dashboard' ],
            'dashicons-clipboard',
            30
        );

        add_submenu_page( 'mop_dashboard', __( 'Users',    'matthewsorderplugin' ), __( 'Users',    'matthewsorderplugin' ), self::CAPABILITY, 'mop_users',    [ __CLASS__, 'render_users' ] );
        add_submenu_page( 'mop_dashboard', __( 'Products', 'matthewsorderplugin' ), __( 'Products', 'matthewsorderplugin' ), self::CAPABILITY, 'mop_products', [ __CLASS__, 'render_products' ] );
        add_submenu_page( 'mop_dashboard', __( 'Orders',   'matthewsorderplugin' ), __( 'Orders',   'matthewsorderplugin' ), self::CAPABILITY, 'mop_orders',   [ __CLASS__, 'render_orders' ] );
        add_submenu_page( 'mop_dashboard', __( 'Settings', 'matthewsorderplugin' ), __( 'Settings', 'matthewsorderplugin' ), 'manage_options', 'mop_settings', [ 'MOP_Settings', 'render_page' ] );
    }

    public static function render_dashboard() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Matthews Orders', 'matthewsorderplugin' ) . '</h1>';
        echo '<p>' . esc_html__( 'Use the submenus to manage users, products, and orders.', 'matthewsorderplugin' ) . '</p></div>';
    }

    public static function render_users() {
        MOP_Admin_Users::render();
    }

    public static function render_products() {
        MOP_Admin_Products::render();
    }

    public static function render_orders() {
        MOP_Admin_Orders::render();
    }
}
