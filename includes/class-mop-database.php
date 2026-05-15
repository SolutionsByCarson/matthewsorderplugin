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

        // Customers table first — must exist before the user_customers bridge.
        dbDelta( self::ddl_customers( $charset_collate ) );
        dbDelta( self::ddl_users( $charset_collate ) );
        dbDelta( self::ddl_sessions( $charset_collate ) );
        dbDelta( self::ddl_user_customers( $charset_collate ) );
        dbDelta( self::ddl_products( $charset_collate ) );
        dbDelta( self::ddl_orders( $charset_collate ) );
        dbDelta( self::ddl_order_lines( $charset_collate ) );

        // One-time migration of pre-split user/customer data. Idempotent;
        // safe to call on every boot. dbDelta does not drop columns or
        // unique keys, so this finishes the schema refactor.
        self::migrate_user_customer_split();

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
        return [ 'order_lines', 'orders', 'user_customers', 'sessions', 'users', 'customers', 'products' ];
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
     * Customer (mop_customers) DDL — FMM entity, one row per Customer Number.
     *
     * Customer-identity columns that used to live on mop_users moved here in
     * DB v0.6.0. Same column names + widths preserved so the rest of the
     * codebase can drop into the new home without churn. The bridge table
     * mop_user_customers connects to mop_users by id.
     */
    private static function ddl_customers( $charset_collate ) {
        $table = self::table( 'customers' );
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id varchar(15) NOT NULL,
            company_name varchar(64) DEFAULT NULL,
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
            phone varchar(30) DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY customer_id (customer_id),
            KEY company_name (company_name)
        ) {$charset_collate};";
    }

    /**
     * Login (mop_users) DDL — login credentials only as of DB v0.6.0.
     *
     * The customer-identity columns moved to mop_customers; a user attaches
     * to any number of customers via the mop_user_customers bridge. email
     * is intentionally NOT unique any longer — the same address can sign in
     * to multiple distinct customer accounts (handled via account picker at
     * login time).
     */
    private static function ddl_users( $charset_collate ) {
        $table = self::table( 'users' );
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            password_hash varchar(255) DEFAULT NULL,
            contact_first_name varchar(50) DEFAULT NULL,
            contact_last_name varchar(50) DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            reset_token_hash varchar(64) DEFAULT NULL,
            reset_token_expires_at datetime DEFAULT NULL,
            last_login_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY email (email),
            KEY reset_token_hash (reset_token_hash)
        ) {$charset_collate};";
    }

    /**
     * User ↔ customer bridge. Many-to-many: one user can attach to multiple
     * customers, one customer can have multiple users. `is_default` flags
     * the user's preferred active context when they have more than one
     * attachment (otherwise the account-picker shows up at login).
     */
    private static function ddl_user_customers( $charset_collate ) {
        $table = self::table( 'user_customers' );
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_customer (user_id, customer_id),
            KEY customer_id (customer_id)
        ) {$charset_collate};";
    }

    private static function ddl_sessions( $charset_collate ) {
        $table = self::table( 'sessions' );
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            active_customer_id bigint(20) unsigned DEFAULT NULL,
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
     * One-time data migration for DB v0.6.0: split mop_users into
     * mop_customers + mop_users + mop_user_customers. Runs after the new
     * tables exist (post-dbDelta). dbDelta won't drop columns or unique
     * indexes, so we finish that work explicitly here.
     *
     * Idempotent: short-circuits once mop_users has lost its customer_id
     * column. Safe to call on every boot.
     */
    private static function migrate_user_customer_split() {
        global $wpdb;
        $users_table          = self::table( 'users' );
        $customers_table      = self::table( 'customers' );
        $user_customers_table = self::table( 'user_customers' );

        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$users_table}" );
        $cols = array_map( 'strval', $cols );
        if ( ! in_array( 'customer_id', $cols, true ) ) {
            return; // already migrated
        }

        // Pull every existing user row WITH its customer fields, then for
        // each row: insert a customer (or reuse an existing one by
        // FMM customer_id), insert a bridge row.
        $rows = $wpdb->get_results( "SELECT * FROM {$users_table}", ARRAY_A );
        $now  = current_time( 'mysql' );
        foreach ( $rows as $row ) {
            $fmm_cid = (string) ( $row['customer_id'] ?? '' );
            if ( $fmm_cid === '' ) {
                continue;
            }
            $customer_pk = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$customers_table} WHERE customer_id = %s", $fmm_cid )
            );
            if ( ! $customer_pk ) {
                $wpdb->insert( $customers_table, [
                    'customer_id'   => substr( $fmm_cid, 0, 15 ),
                    'company_name'  => $row['company_name']   ?? null,
                    'bill_to_line1' => $row['bill_to_line1']  ?? null,
                    'bill_to_line2' => $row['bill_to_line2']  ?? null,
                    'bill_to_city'  => $row['bill_to_city']   ?? null,
                    'bill_to_state' => $row['bill_to_state']  ?? null,
                    'bill_to_zip'   => $row['bill_to_zip']    ?? null,
                    'ship_to_line1' => $row['ship_to_line1']  ?? null,
                    'ship_to_line2' => $row['ship_to_line2']  ?? null,
                    'ship_to_city'  => $row['ship_to_city']   ?? null,
                    'ship_to_state' => $row['ship_to_state']  ?? null,
                    'ship_to_zip'   => $row['ship_to_zip']    ?? null,
                    'is_active'     => 1,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ] );
                $customer_pk = (int) $wpdb->insert_id;
            }
            if ( $customer_pk ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$user_customers_table} (user_id, customer_id, is_default, created_at)
                     VALUES (%d, %d, 1, %s)",
                    (int) $row['id'], $customer_pk, $now
                ) );
            }
        }

        // Drop the now-obsolete unique index + customer columns from
        // mop_users. SHOW INDEXES → only drop if present so a partial
        // previous run doesn't trip an error.
        $idx = $wpdb->get_col( "SHOW INDEX FROM {$users_table} WHERE Key_name = 'customer_id'", 2 );
        if ( ! empty( $idx ) ) {
            $wpdb->query( "ALTER TABLE {$users_table} DROP INDEX customer_id" );
        }
        $idx = $wpdb->get_col( "SHOW INDEX FROM {$users_table} WHERE Key_name = 'email'", 2 );
        // Drop UNIQUE email if it's still unique; dbDelta will re-add it as a non-unique KEY.
        $is_unique = $wpdb->get_var( "SHOW INDEX FROM {$users_table} WHERE Key_name = 'email' AND Non_unique = 0" );
        if ( $is_unique ) {
            $wpdb->query( "ALTER TABLE {$users_table} DROP INDEX email" );
            $wpdb->query( "ALTER TABLE {$users_table} ADD KEY email (email)" );
        }

        $drop_cols = [
            'customer_id', 'company_name',
            'bill_to_line1', 'bill_to_line2', 'bill_to_city', 'bill_to_state', 'bill_to_zip',
            'ship_to_line1', 'ship_to_line2', 'ship_to_city', 'ship_to_state', 'ship_to_zip',
        ];
        $present = $wpdb->get_col( "SHOW COLUMNS FROM {$users_table}" );
        $present = array_map( 'strval', $present );
        foreach ( $drop_cols as $c ) {
            if ( in_array( $c, $present, true ) ) {
                $wpdb->query( "ALTER TABLE {$users_table} DROP COLUMN {$c}" );
            }
        }
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
            web_description varchar(100) DEFAULT NULL,
            category varchar(100) DEFAULT NULL,
            sort_order int NOT NULL DEFAULT 0,
            uom_schedule varchar(20) DEFAULT NULL,
            selling_uom varchar(20) NOT NULL,
            base_uom varchar(10) NOT NULL DEFAULT 'POUND',
            conversion_factor decimal(12,4) NOT NULL DEFAULT 1.0000,
            requires_vfd tinyint(1) NOT NULL DEFAULT 0,
            minimum_order_qty varchar(40) DEFAULT NULL,
            sold_individually tinyint(1) DEFAULT NULL,
            site_id varchar(10) NOT NULL DEFAULT 'MATTHEWS',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY fmm_item_number (fmm_item_number),
            KEY category (category),
            KEY sort_order (sort_order),
            KEY requires_vfd (requires_vfd)
        ) {$charset_collate};";
    }

    /**
     * Orders (mop_orders) DDL.
     *
     * Everything the ORDIMP.DAT generator needs is snapshotted into the row at
     * order-submit time so later edits to the user account or product catalog
     * never retroactively change an existing order. `po_number` is our
     * Customer PO Number (Record 100 pos 2) — globally unique, format
     * `WEB-MFG-YYYYMMDD-NNN`. `ordimp_path` is the absolute path returned by
     * MOP_Ordimp::storage_path() once the file is written.
     */
    private static function ddl_orders( $charset_collate ) {
        $table = self::table( 'orders' );
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            po_number varchar(20) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            customer_id_snapshot varchar(15) NOT NULL,
            company_snapshot varchar(64) DEFAULT NULL,
            contact_first_name_snapshot varchar(50) DEFAULT NULL,
            contact_last_name_snapshot varchar(50) DEFAULT NULL,
            email_snapshot varchar(190) DEFAULT NULL,
            bill_to_line1_snapshot varchar(100) DEFAULT NULL,
            bill_to_line2_snapshot varchar(100) DEFAULT NULL,
            bill_to_city_snapshot varchar(50) DEFAULT NULL,
            bill_to_state_snapshot varchar(2) DEFAULT NULL,
            bill_to_zip_snapshot varchar(10) DEFAULT NULL,
            ship_to_line1_snapshot varchar(100) DEFAULT NULL,
            ship_to_line2_snapshot varchar(100) DEFAULT NULL,
            ship_to_city_snapshot varchar(50) DEFAULT NULL,
            ship_to_state_snapshot varchar(2) DEFAULT NULL,
            ship_to_zip_snapshot varchar(10) DEFAULT NULL,
            order_type varchar(20) NOT NULL,
            comments text,
            ordered_date date NOT NULL,
            ordered_time time NOT NULL,
            ordimp_path varchar(500) DEFAULT NULL,
            ordimp_generated_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY po_number (po_number),
            KEY user_id (user_id),
            KEY ordered_date (ordered_date)
        ) {$charset_collate};";
    }

    /**
     * Order lines (mop_order_lines) DDL.
     *
     * One row per cart item. Stores BOTH the customer-facing qty_selling
     * (what the user typed — e.g. 2 bags) AND the computed qty_base
     * (2 × conversion_factor = 100 POUND) so ORDIMP Record 200 pos 6 is
     * just a column read, and the admin/CSV report can show either view.
     * product_id is nullable so later deletions of a product don't break
     * historical order display.
     */
    private static function ddl_order_lines( $charset_collate ) {
        $table = self::table( 'order_lines' );
        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            line_number int NOT NULL DEFAULT 1,
            product_id bigint(20) unsigned DEFAULT NULL,
            fmm_item_number varchar(30) NOT NULL,
            description varchar(50) NOT NULL,
            category_snapshot varchar(100) DEFAULT NULL,
            selling_uom varchar(20) NOT NULL,
            base_uom varchar(10) NOT NULL,
            conversion_factor decimal(12,4) NOT NULL DEFAULT 1.0000,
            qty_selling decimal(12,4) NOT NULL,
            qty_base decimal(12,4) NOT NULL,
            site_id varchar(10) NOT NULL DEFAULT 'MATTHEWS',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY fmm_item_number (fmm_item_number)
        ) {$charset_collate};";
    }
}
