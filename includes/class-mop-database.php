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
        dbDelta( self::ddl_products( $charset_collate ) );

        update_option( 'mop_db_version', MOP_DB_VERSION );
    }

    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . MOP_TABLE_PREFIX . $name;
    }

    /**
     * Short names of all plugin-owned tables. Used by `wp mop rebuild-db`.
     * Keep this in sync with every dbDelta() call in install().
     */
    public static function known_tables() {
        return [ 'sessions', 'users', 'products' ];
    }

    /**
     * DROP every plugin-owned table and clear the stored schema version so
     * install() will re-run on next boot. Destructive — only callable from
     * WP-CLI (see MOP_CLI::rebuild_db).
     */
    public static function drop_all() {
        global $wpdb;
        foreach ( self::known_tables() as $short ) {
            $table = self::table( $short );
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }
        delete_option( 'mop_db_version' );
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

    /**
     * Product (mop_products) DDL.
     *
     * Field widths that come directly from the FMM ORDIMP.DAT reference:
     *   fmm_item_number — 30 alphanumeric (Record 200 pos 4 "Line Code")
     *   description     — 50 alpha        (Record 200 pos 5 "Line Description")
     *   site_id         — 10 alphanumeric (Record 200 pos 12), default MATTHEWS
     *
     * Category + sort_order are our own UI concerns. The live order form groups
     * products into four brand sections (Lindner / Sunglo / MFG & Private Label /
     * Show-Rite); `category` is a free-text column so admins can add or rename
     * groupings without a schema change, and `sort_order` controls both
     * within-category order AND implicit category order (categories are rendered
     * in ascending MIN(sort_order) of their products).
     *
     * UoM model: one selling UoM per product. Customers order in the selling UoM
     * (e.g. "2" BAG-50) and the ORDIMP generator converts to base UoM using
     * conversion_factor before writing Record 200 pos 6. selling_uom is a free
     * string (BAG-50, POUND, EACH, QT, GAL, CASE, etc.) — intentionally
     * flexible, no lookup table.
     */
    private static function ddl_products( $charset_collate ) {
        $table = self::table( 'products' );
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            fmm_item_number varchar(30) NOT NULL,
            description varchar(50) NOT NULL,
            category varchar(100) DEFAULT NULL,
            sort_order int NOT NULL DEFAULT 0,
            selling_uom varchar(20) NOT NULL,
            base_uom varchar(10) NOT NULL DEFAULT 'POUND',
            conversion_factor decimal(12,4) NOT NULL DEFAULT 1.0000,
            site_id varchar(10) NOT NULL DEFAULT 'MATTHEWS',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY fmm_item_number (fmm_item_number),
            KEY category (category),
            KEY sort_order (sort_order)
        ) {$charset_collate};";
    }
}
