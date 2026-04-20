<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP-CLI command surface. Registered only when WP_CLI is defined.
 *
 * Usage:
 *   wp mop rebuild-db [--yes]
 *   wp mop seed-products [--reset] [--dry-run]
 */
class MOP_CLI {

    public static function register() {
        if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }
        \WP_CLI::add_command( 'mop rebuild-db',    [ __CLASS__, 'rebuild_db' ] );
        \WP_CLI::add_command( 'mop seed-products', [ __CLASS__, 'seed_products' ] );
    }

    /**
     * Drop every plugin-owned table and recreate them from the current schema.
     *
     * DESTRUCTIVE — used during active development as the schema evolves. Does
     * not touch wp-content/order/ uploads or the plugin's options.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp mop rebuild-db --yes
     */
    public static function rebuild_db( $args, $assoc_args ) {
        $tables = array_map( [ 'MOP_Database', 'table' ], MOP_Database::known_tables() );

        \WP_CLI::warning( 'This will DROP and recreate the following tables (all data lost):' );
        foreach ( $tables as $t ) {
            \WP_CLI::log( '  - ' . $t );
        }
        \WP_CLI::confirm( 'Proceed?', $assoc_args );

        MOP_Database::drop_all();
        MOP_Database::install();

        \WP_CLI::success( sprintf(
            'Rebuilt %d table(s). mop_db_version now %s.',
            count( $tables ),
            (string) get_option( 'mop_db_version' )
        ) );
    }

    /**
     * Seed the products catalog from includes/data/products-seed.php.
     *
     * Idempotent: an existing row with a matching fmm_item_number is updated
     * in place (so running the command twice doesn't create duplicates). Pass
     * `--reset` to wipe the products table first for a clean re-seed.
     *
     * FMM item numbers in the seed file are PLACEHOLDERS (brand-prefixed
     * sequences like LIN-001, SUN-001, ...). Replace them with real FMM Line
     * Codes in the Products admin before generating real ORDIMP.DAT exports.
     *
     * ## OPTIONS
     *
     * [--reset]
     * : Delete all existing products before seeding.
     *
     * [--dry-run]
     * : Report what would be inserted/updated but don't write to the database.
     *
     * ## EXAMPLES
     *
     *     wp mop seed-products
     *     wp mop seed-products --reset
     *     wp mop seed-products --dry-run
     */
    public static function seed_products( $args, $assoc_args ) {
        $seed_file = MOP_PLUGIN_DIR . 'includes/data/products-seed.php';
        if ( ! file_exists( $seed_file ) ) {
            \WP_CLI::error( 'Seed file not found: ' . $seed_file );
        }
        $catalog = require $seed_file;
        if ( ! is_array( $catalog ) ) {
            \WP_CLI::error( 'Seed file did not return an array.' );
        }

        $dry_run = ! empty( $assoc_args['dry-run'] );
        $reset   = ! empty( $assoc_args['reset'] );

        if ( $reset && ! $dry_run ) {
            global $wpdb;
            $table = MOP_Product::table();
            $wpdb->query( "DELETE FROM {$table}" );
            \WP_CLI::log( 'Deleted all existing products.' );
        } elseif ( $reset && $dry_run ) {
            \WP_CLI::log( '[dry-run] Would DELETE all existing products.' );
        }

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $category_step = 100;  // Leaves 9 open slots per category for manual reordering.
        $category_base = 0;

        foreach ( $catalog as $group ) {
            $category_base += $category_step;
            $category       = isset( $group['name'] ) ? (string) $group['name'] : '';
            $products       = isset( $group['products'] ) && is_array( $group['products'] ) ? $group['products'] : [];

            \WP_CLI::log( '--- ' . $category . ' (' . count( $products ) . ') ---' );

            foreach ( $products as $i => $p ) {
                $row = [
                    'fmm_item_number'   => MOP_Product::normalize_item_number( $p['fmm'] ),
                    'description'       => substr( (string) $p['desc'], 0, 50 ),
                    'category'          => substr( $category, 0, 100 ),
                    'sort_order'        => $category_base + ( $i * 10 ),
                    'selling_uom'       => strtoupper( substr( (string) $p['uom'], 0, 20 ) ),
                    'base_uom'          => in_array( $p['base'], [ 'POUND', 'EACH' ], true ) ? $p['base'] : 'POUND',
                    'conversion_factor' => (float) $p['factor'],
                    'site_id'           => 'MATTHEWS',
                ];

                $existing = MOP_Product::find_by_item_number( $row['fmm_item_number'] );

                if ( $dry_run ) {
                    $action = $existing ? 'UPDATE' : 'INSERT';
                    \WP_CLI::log( sprintf( '  [%s] %-10s  %s  (%s × %s %s)', $action, $row['fmm_item_number'], $row['description'], $row['selling_uom'], $row['conversion_factor'], $row['base_uom'] ) );
                    $existing ? $updated++ : $created++;
                    continue;
                }

                if ( $existing ) {
                    MOP_Product::update( (int) $existing['id'], $row );
                    $updated++;
                } else {
                    MOP_Product::create( $row );
                    $created++;
                }
            }
        }

        \WP_CLI::success( sprintf(
            '%s %d product(s): %d created, %d updated, %d skipped.',
            $dry_run ? '[dry-run] Would have processed' : 'Processed',
            $created + $updated + $skipped,
            $created,
            $updated,
            $skipped
        ) );
    }
}
