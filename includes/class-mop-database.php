<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Schema management.
 *
 * Phase 1 stub. The real table DDL will be defined in Phase 2 once we settle
 * the mop_users / mop_products / mop_orders / mop_order_lines schemas against
 * the FMM ORDIMP field requirements (see FMM_Order_Import_Reference_Guide-V2.pdf).
 *
 * When schemas are added, bump MOP_DB_VERSION and handle migrations inside
 * install() by comparing against the 'mop_db_version' option.
 */
class MOP_Database {

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $installed = get_option( 'mop_db_version' );

        if ( $installed === MOP_DB_VERSION ) {
            return;
        }

        // TODO (Phase 2): dbDelta() calls for:
        //   {prefix}mop_users
        //   {prefix}mop_products
        //   {prefix}mop_orders
        //   {prefix}mop_order_lines

        update_option( 'mop_db_version', MOP_DB_VERSION );
    }

    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . MOP_TABLE_PREFIX . $name;
    }
}
