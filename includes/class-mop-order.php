<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Order + order-line repository.
 *
 * Order submission writes two tables: mop_orders (one row per order) and
 * mop_order_lines (one row per cart line). Everything the ORDIMP.DAT
 * generator needs is snapshotted into these rows at submit time so later
 * edits to the user or product catalog cannot retroactively change an
 * existing order.
 *
 * PO number format: WEB-MFG-YYYYMMDD-NNN — the sequence resets each day,
 * globally unique because the date component prevents collisions across
 * days. FMM rejects duplicates (err 5 work tables, err 8 history).
 */
class MOP_Order {

    const PO_PREFIX    = 'WEB-MFG';
    const ORDER_TYPES  = [ 'delivery', 'pickup', 'dock' ];

    public static function table() {
        return MOP_Database::table( 'orders' );
    }

    public static function lines_table() {
        return MOP_Database::table( 'order_lines' );
    }

    public static function find( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function find_by_po( $po_number ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE po_number = %s', $po_number ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_lines( $order_id ) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::lines_table() . ' WHERE order_id = %d ORDER BY line_number ASC, id ASC',
                (int) $order_id
            ),
            ARRAY_A
        );
    }

    /**
     * Admin list — orders joined with lightweight user display.
     * Returns rows with all mop_orders columns plus `line_count`.
     */
    public static function all_with_summary() {
        global $wpdb;
        $orders = self::table();
        $lines  = self::lines_table();
        return $wpdb->get_results(
            "SELECT o.*, (SELECT COUNT(*) FROM {$lines} l WHERE l.order_id = o.id) AS line_count
             FROM {$orders} o
             ORDER BY o.created_at DESC, o.id DESC",
            ARRAY_A
        );
    }

    /**
     * Persist an order + its lines.
     *
     * $header is the snapshot data for the `mop_orders` row. $lines is an
     * array of line dicts already expanded to include product snapshot +
     * qty_selling + qty_base. `po_number` on the header must be set by
     * caller via next_po_number() — do it up-front so we can retry on
     * the unique-key collision without having to roll back lines.
     *
     * Returns [ 'order' => row, 'lines' => [ row, ... ] ] or null on failure.
     */
    public static function create( array $header, array $lines ) {
        global $wpdb;

        if ( empty( $header['po_number'] ) || empty( $lines ) ) {
            return null;
        }

        $now = current_time( 'mysql' );
        $ordered_date = isset( $header['ordered_date'] ) ? $header['ordered_date'] : current_time( 'Y-m-d' );
        $ordered_time = isset( $header['ordered_time'] ) ? $header['ordered_time'] : current_time( 'H:i:s' );

        $row = array_merge( self::defaults(), $header, [
            'ordered_date' => $ordered_date,
            'ordered_time' => $ordered_time,
            'created_at'   => $now,
        ] );

        $ok = $wpdb->insert( self::table(), $row );
        if ( ! $ok ) {
            return null;
        }
        $order_id = (int) $wpdb->insert_id;

        $line_number = 0;
        $saved_lines = [];
        foreach ( $lines as $line ) {
            $line_number++;
            $line_row = array_merge( [
                'order_id'          => $order_id,
                'line_number'       => $line_number,
                'product_id'        => null,
                'fmm_item_number'   => '',
                'description'       => '',
                'category_snapshot' => null,
                'selling_uom'       => '',
                'base_uom'          => 'POUND',
                'conversion_factor' => 1,
                'qty_selling'       => 0,
                'qty_base'          => 0,
                'site_id'           => 'MATTHEWS',
                'created_at'        => $now,
            ], $line );

            $line_row['fmm_item_number'] = strtoupper( (string) $line_row['fmm_item_number'] );

            $wpdb->insert( self::lines_table(), $line_row );
            $line_row['id'] = (int) $wpdb->insert_id;
            $saved_lines[]  = $line_row;
        }

        return [
            'order' => self::find( $order_id ),
            'lines' => $saved_lines,
        ];
    }

    /**
     * Attach the ORDIMP.dat absolute path to an order row and mark the
     * generation timestamp. Stored path is absolute so admin download
     * handlers can open it directly; the directory layout is enforced
     * by MOP_Ordimp::storage_path().
     */
    public static function set_ordimp_path( $order_id, $path ) {
        global $wpdb;
        $wpdb->update( self::table(), [
            'ordimp_path'         => $path,
            'ordimp_generated_at' => current_time( 'mysql' ),
        ], [ 'id' => (int) $order_id ] );
    }

    /**
     * Generate the next PO number for today's date. Format:
     *   WEB-MFG-YYYYMMDD-NNN
     *
     * NNN is one higher than the max sequence already used for today,
     * starting at 001. Because the date component is always in the PO,
     * we only need uniqueness within the day.
     *
     * Caller should still handle the rare case where a parallel request
     * takes this number first — the `po_number` column has a UNIQUE
     * constraint and create() will return null if the insert collides,
     * in which case the caller can retry.
     */
    public static function next_po_number() {
        global $wpdb;
        $date   = current_time( 'Ymd' );
        $prefix = self::PO_PREFIX . '-' . $date . '-';
        $table  = self::table();

        $last = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT po_number FROM {$table} WHERE po_number LIKE %s ORDER BY po_number DESC LIMIT 1",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );

        $next = 1;
        if ( $last ) {
            $tail = substr( $last, strlen( $prefix ) );
            if ( is_numeric( $tail ) ) {
                $next = (int) $tail + 1;
            }
        }
        return $prefix . str_pad( (string) $next, 3, '0', STR_PAD_LEFT );
    }

    /**
     * Human label for an order_type value — used in admin list, CSV, emails,
     * and the customer receipt.
     */
    public static function order_type_label( $order_type ) {
        $map = [
            'delivery' => __( 'Delivery', 'matthewsorderplugin' ),
            'pickup'   => __( 'Pick up', 'matthewsorderplugin' ),
            'dock'     => __( 'Dock order', 'matthewsorderplugin' ),
        ];
        return isset( $map[ $order_type ] ) ? $map[ $order_type ] : ucfirst( (string) $order_type );
    }

    /**
     * Build a snapshot of the user's current bill/ship/contact info for
     * inclusion on an order header. Ensures the order row captures the
     * user's state at order-submit time regardless of later edits.
     */
    public static function snapshot_from_user( array $user ) {
        $keys = [
            'customer_id'        => 'customer_id_snapshot',
            'company_name'       => 'company_snapshot',
            'contact_first_name' => 'contact_first_name_snapshot',
            'contact_last_name'  => 'contact_last_name_snapshot',
            'email'              => 'email_snapshot',
            'bill_to_line1'      => 'bill_to_line1_snapshot',
            'bill_to_line2'      => 'bill_to_line2_snapshot',
            'bill_to_city'       => 'bill_to_city_snapshot',
            'bill_to_state'      => 'bill_to_state_snapshot',
            'bill_to_zip'        => 'bill_to_zip_snapshot',
            'ship_to_line1'      => 'ship_to_line1_snapshot',
            'ship_to_line2'      => 'ship_to_line2_snapshot',
            'ship_to_city'       => 'ship_to_city_snapshot',
            'ship_to_state'      => 'ship_to_state_snapshot',
            'ship_to_zip'        => 'ship_to_zip_snapshot',
        ];

        $out = [];
        foreach ( $keys as $user_key => $order_key ) {
            $out[ $order_key ] = isset( $user[ $user_key ] ) ? $user[ $user_key ] : null;
        }
        return $out;
    }

    private static function defaults() {
        return [
            'po_number'                   => '',
            'user_id'                     => 0,
            'customer_id_snapshot'        => '',
            'company_snapshot'            => null,
            'contact_first_name_snapshot' => null,
            'contact_last_name_snapshot'  => null,
            'email_snapshot'              => null,
            'bill_to_line1_snapshot'      => null,
            'bill_to_line2_snapshot'      => null,
            'bill_to_city_snapshot'       => null,
            'bill_to_state_snapshot'      => null,
            'bill_to_zip_snapshot'        => null,
            'ship_to_line1_snapshot'      => null,
            'ship_to_line2_snapshot'      => null,
            'ship_to_city_snapshot'       => null,
            'ship_to_state_snapshot'      => null,
            'ship_to_zip_snapshot'        => null,
            'order_type'                  => 'delivery',
            'comments'                    => null,
            'ordimp_path'                 => null,
            'ordimp_generated_at'         => null,
        ];
    }
}
