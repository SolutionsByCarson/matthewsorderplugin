<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP-CLI command surface. Registered only when WP_CLI is defined.
 *
 * Usage:
 *   wp mop rebuild-db [--yes]
 */
class MOP_CLI {

    public static function register() {
        if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }
        \WP_CLI::add_command( 'mop rebuild-db', [ __CLASS__, 'rebuild_db' ] );
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
}
