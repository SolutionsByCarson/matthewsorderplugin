<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Schema management.
 *
 * Runs dbDelta for all plugin-owned tables whenever the stored
 * 'mop_db_version' option is older than MOP_DB_VERSION. Called from the
 * activation hook AND on plugins_loaded, so both fresh installs and
 * version upgrades converge to the current schema.
 *
 * Tables are never dropped — this is a proprietary data plugin.
 */
class MOP_Database {

    public static function install() {
        $installed = get_option( 'mop_db_version' );

        if ( $installed === MOP_DB_VERSION ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta( self::ddl_users( $charset_collate ) );
        dbDelta( self::ddl_sessions( $charset_collate ) );

        update_option( 'mop_db_version', MOP_DB_VERSION );
    }

    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . MOP_TABLE_PREFIX . $name;
    }

    /**
     * Customer (mop_users) DDL.
     *
     * Field widths that come directly from the FMM ORDIMP.DAT reference:
     *   customer_id  — 15 alphanumeric (Record 100 pos 3, must match FMM exactly)
     *   company_name — 64 (Record 100 pos 4 "Customer Name" display field)
     *
     * email uses varchar(190) to stay under MySQL's 767-byte unique-key limit
     * on utf8mb4 — same convention WordPress uses for user_login/user_email.
     */
    private static function ddl_users( $charset_collate ) {
        $table = self::table( 'users' );
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id varchar(15) NOT NULL,
            company_name varchar(64) DEFAULT NULL,
            contact_first_name varchar(50) DEFAULT NULL,
            contact_last_name varchar(50) DEFAULT NULL,
            email varchar(190) NOT NULL,
            password_hash varchar(255) DEFAULT NULL,
            bill_to_line1 varchar(100) DEFAULT NULL,
            bill_to_line2 varchar(100) DEFAULT NULL,
            bill_to_city varchar(50) DEFAULT NULL,
            bill_to_state varchar(2) DEFAULT NULL,
            bill_to_zip varchar(10) DEFAULT NULL,
            ship_to_line1 varchar(100) DEFAULT NULL,
            ship_to_line2 varchar(100) DEFAULT NULL,
            ship_to_city varchar(50) DEFAULT NULL,
            ship_to_state varchar(2) DEFAULT NULL,
            ship_to_zip varchar(10) DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            reset_token_hash varchar(64) DEFAULT NULL,
            reset_token_expires_at datetime DEFAULT NULL,
            last_login_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY customer_id (customer_id),
            UNIQUE KEY email (email),
            KEY reset_token_hash (reset_token_hash)
        ) {$charset_collate};";
    }

    private static function ddl_sessions( $charset_collate ) {
        $table = self::table( 'sessions' );
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            token_hash varchar(64) NOT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) {$charset_collate};";
    }
}
