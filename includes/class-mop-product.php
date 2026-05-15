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

    /**
     * Columns admins round-trip through CSV import.
     *
     * `category` is in the CSV so the spreadsheet drives catalog grouping.
     * If a row's category is non-empty it overwrites; if blank/missing,
     * existing categories are preserved on updates (and null on creates).
     *
     * `sort_order`, `site_id`, and the auto-derived `base_uom` /
     * `conversion_factor` remain admin-only — re-imports never touch them.
     */
    public static function csv_columns() {
        return [
            'fmm_item_number',
            'fmm_description',
            'web_description',
            'category',
            'uom_schedule',
            'selling_uom',
            'vfd_required',
            'minimum_order',
            'sold_individually',
        ];
    }

    /**
     * Strip the schedule suffix off uom_schedule and validate the prefix
     * is one of FMM's two accepted base UoMs (Section 6 of the ORDIMP
     * reference). Returns 'POUND' or 'EACH' on success, or null on failure.
     *
     * Strict pattern: bare POUND/EACH, or POUND-N / EACH-N where N is one
     * or more digits. This deliberately rejects bizarre schedules like
     * "EACH-LBS" — they imply a base unit other than EACH/POUND, which
     * FMM doesn't accept, so we'd rather flag the row than guess.
     */
    public static function derive_base_uom( $uom_schedule ) {
        $val = strtoupper( trim( (string) $uom_schedule ) );
        if ( $val === '' ) {
            return null;
        }
        if ( preg_match( '/^(POUND|EACH)(-\d+)?$/', $val, $m ) ) {
            return $m[1];
        }
        return null;
    }

    /**
     * Parse the conversion factor out of a selling_uom string:
     *   BAG-50    -> 50
     *   PAIL-20   -> 20
     *   POUND     -> 1
     *   EACH      -> 1
     * Returns a positive float on success, or null if the string does not
     * resolve to a sensible factor (e.g. ambiguous values like "EACH 7.5").
     */
    public static function derive_conversion_factor( $selling_uom ) {
        $val = strtoupper( trim( (string) $selling_uom ) );
        if ( $val === '' ) {
            return null;
        }
        if ( $val === 'POUND' || $val === 'EACH' ) {
            return 1.0;
        }
        // Match PREFIX-NUMBER where PREFIX is alpha and NUMBER is positive.
        if ( preg_match( '/^[A-Z]+-(\d+(?:\.\d+)?)$/', $val, $m ) ) {
            $factor = (float) $m[1];
            return $factor > 0 ? $factor : null;
        }
        return null;
    }

    /**
     * Truthy/empty-checker tolerant of the values the client's spreadsheet
     * uses: "YES" / "NO" / blank for sold_individually, "VFD/AOD REQUIRED"
     * / blank for the VFD flag.
     */
    public static function parse_bool( $val ) {
        $v = strtoupper( trim( (string) $val ) );
        if ( $v === '' ) {
            return null; // unknown / unset
        }
        if ( in_array( $v, [ '1', 'YES', 'Y', 'TRUE', 'T' ], true ) ) {
            return 1;
        }
        if ( in_array( $v, [ '0', 'NO', 'N', 'FALSE', 'F' ], true ) ) {
            return 0;
        }
        // VFD column uses the literal phrase as truthy.
        if ( $v === 'VFD/AOD REQUIRED' ) {
            return 1;
        }
        return null;
    }

    private static function defaults() {
        return [
            'fmm_item_number'   => '',
            'description'       => '',
            'web_description'   => null,
            'category'          => null,
            'sort_order'        => 0,
            'uom_schedule'      => null,
            'selling_uom'       => 'EACH',
            'base_uom'          => 'EACH',
            'conversion_factor' => 1,
            'requires_vfd'      => 0,
            'minimum_order_qty' => null,
            'sold_individually' => null,
            'site_id'           => MOP_Ordimp::DEFAULT_SITE_ID,
        ];
    }
}
