<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product (mop_products) repository.
 *
 * Thin data-access layer. Writes upper-case the fmm_item_number because FMM
 * requires an exact upper-case match (ORDIMP guide Record 200 pos 4).
 *
 * `all_grouped_by_category()` is the primary read for the order form UI:
 *   returns [ category_name => [ product_row, ... ], ... ] with categories
 *   ordered by the min sort_order of their products.
 */
class MOP_Product {

    public static function table() {
        return MOP_Database::table( 'products' );
    }

    public static function find( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function find_by_item_number( $fmm_item_number ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE fmm_item_number = %s', self::normalize_item_number( $fmm_item_number ) ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function all() {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT * FROM ' . self::table() . ' ORDER BY sort_order ASC, id ASC',
            ARRAY_A
        );
    }

    public static function all_grouped_by_category() {
        $rows     = self::all();
        $grouped  = [];
        $category_order = []; // tracks first-sort_order per category to preserve stable category ordering.

        foreach ( $rows as $row ) {
            $cat = $row['category'] !== null && $row['category'] !== '' ? $row['category'] : __( 'Uncategorized', 'matthewsorderplugin' );
            if ( ! isset( $grouped[ $cat ] ) ) {
                $grouped[ $cat ] = [];
                $category_order[ $cat ] = (int) $row['sort_order'];
            }
            $grouped[ $cat ][] = $row;
        }

        uksort( $grouped, function ( $a, $b ) use ( $category_order ) {
            $sa = $category_order[ $a ];
            $sb = $category_order[ $b ];
            if ( $sa === $sb ) {
                return strcasecmp( $a, $b );
            }
            return $sa <=> $sb;
        } );

        return $grouped;
    }

    public static function create( array $data ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $row = array_merge( self::defaults(), $data, [
            'created_at' => $now,
            'updated_at' => $now,
        ] );
        $row['fmm_item_number'] = self::normalize_item_number( $row['fmm_item_number'] );

        $wpdb->insert( self::table(), $row );
        return $wpdb->insert_id ? self::find( $wpdb->insert_id ) : null;
    }

    public static function update( $id, array $data ) {
        global $wpdb;
        if ( isset( $data['fmm_item_number'] ) ) {
            $data['fmm_item_number'] = self::normalize_item_number( $data['fmm_item_number'] );
        }
        $data['updated_at'] = current_time( 'mysql' );
        $wpdb->update( self::table(), $data, [ 'id' => (int) $id ] );
        return self::find( $id );
    }

    public static function delete( $id ) {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'id' => (int) $id ] );
    }

    /** Convert a customer-entered selling-UoM quantity into base-UoM quantity for ORDIMP. */
    public static function convert_to_base( $product, $qty_selling ) {
        $factor = isset( $product['conversion_factor'] ) ? (float) $product['conversion_factor'] : 1.0;
        return round( ( (float) $qty_selling ) * $factor, 4 );
    }

    public static function normalize_item_number( $val ) {
        return strtoupper( trim( (string) $val ) );
    }

    private static function defaults() {
        return [
            'fmm_item_number'   => '',
            'description'       => '',
            'category'          => null,
            'sort_order'        => 0,
            'selling_uom'       => 'EACH',
            'base_uom'          => 'EACH',
            'conversion_factor' => 1,
            'site_id'           => MOP_Ordimp::DEFAULT_SITE_ID,
        ];
    }
}
