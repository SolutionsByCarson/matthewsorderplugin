<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ORDIMP.DAT file generator.
 *
 * Builds FMM Feed Mill Manager import files according to
 * FMM_Order_Import_Reference_Guide-V2.pdf. Format-critical details:
 *
 *   - ASCII, no header, no quoted strings, comma-delimited
 *   - Windows CRLF line endings (required)
 *   - Record 100 = 9 fields | Record 110 = 2 fields | Record 200 = 25 fields
 *     (spec says 24, FMM silently drops lines with fewer than 25 — trailing empty required)
 *   - Record 200 pos 6 = qty in BASE UofM; pos 7 always 0; pos 8 = base UofM string
 *   - PO numbers (Record 100 pos 2) must be globally unique — FMM rejects
 *     duplicates against both work tables (err 5) and history (err 8)
 *
 * Storage layout: wp-content/order/{user_id}/{order_id}/ORDIMP.dat
 *
 * Phase 1 stub — full generator lands in Phase 5.
 */
class MOP_Ordimp {

    const LINE_ENDING   = "\r\n";
    const RECORD_100_FIELDS = 9;
    const RECORD_110_FIELDS = 2;
    const RECORD_200_FIELDS = 25;
    const DEFAULT_SITE_ID   = 'MATTHEWS';

    /** Build + write the ORDIMP.DAT for an order. Returns absolute path. */
    public static function generate( $order ) {
        // TODO (Phase 5):
        //   1. Build Record 100 (header)
        //   2. Build Record 110 (comment, optional)
        //   3. For each line: convert selling qty -> base qty via product's conversion_factor,
        //      build Record 200 with exactly 25 fields, upper-case the item number.
        //   4. Join with CRLF, write to storage_path(), return path.
        return '';
    }

    public static function storage_path( $user_id, $order_id ) {
        $user_id  = (int) $user_id;
        $order_id = (int) $order_id;
        $dir = WP_CONTENT_DIR . '/' . MOP_UPLOAD_SUBDIR . '/' . $user_id . '/' . $order_id;
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir . '/ORDIMP.dat';
    }

    /** Format a record's fields: comma-join, pad to exact field count with empties. */
    public static function format_record( array $fields, $expected_count ) {
        $fields = array_pad( $fields, $expected_count, '' );
        $fields = array_slice( $fields, 0, $expected_count );
        return implode( ',', array_map( [ __CLASS__, 'sanitize_field' ], $fields ) );
    }

    /** Strip commas + CR/LF from any user-supplied field value. */
    private static function sanitize_field( $value ) {
        $value = (string) $value;
        return str_replace( [ ',', "\r", "\n" ], ' ', $value );
    }
}
