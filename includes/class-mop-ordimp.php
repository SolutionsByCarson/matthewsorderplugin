<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ORDIMP.DAT file generator.
 *
 * Builds FMM Feed Mill Manager import files per the reference guide
 * (FMM_Order_Import_Reference_Guide-V2.pdf). The format was validated against
 * the Matthews TEST company on 2026-04-07 — every choice below matches the
 * confirmed working sample file included in the guide.
 *
 * File requirements:
 *   - ASCII, no header, no quoted strings, comma-delimited
 *   - Windows CRLF (\r\n) line endings
 *   - Record 100 = 9 fields  (order header)
 *   - Record 110 = 2 fields  (comment, optional, up to 100 chars per record,
 *                             500 chars total concatenated)
 *   - Record 200 = 25 fields (spec says 24 but FMM silently drops lines
 *                             with fewer than 25 — trailing empty required)
 *
 * Record 200 critical fields:
 *   pos 3 (Pricing Flag)     = 0  → FMM re-prices using customer price list
 *   pos 4 (Line Code)        = FMM Item Number, upper-case
 *   pos 6 (Ordered Qty)      = quantity in BASE UoM (e.g. POUND, EACH)
 *   pos 7 (Qty in Base UofM) = 0 (flag that pos 8 should be used)
 *   pos 8 (UofM)             = base UoM string (POUND or EACH)
 *   pos 12 (Site ID)         = MATTHEWS
 *
 * Storage layout: wp-content/order/{user_id}/{order_id}/ORDIMP.dat
 * (the directory is created at order-submit time; the wp-content/order
 * tree has a deny-all .htaccess from plugin activation so files can only
 * be retrieved through the PHP download handlers in MOP_Handlers).
 */
class MOP_Ordimp {

    const LINE_ENDING       = "\r\n";
    const RECORD_100_FIELDS = 9;
    const RECORD_110_FIELDS = 2;
    const RECORD_200_FIELDS = 25;
    const DEFAULT_SITE_ID   = 'MATTHEWS';
    const MAX_COMMENT_TOTAL = 500;
    const MAX_COMMENT_LINE  = 100;

    /**
     * Build + write the ORDIMP.DAT for an order.
     *
     * $order is the mop_orders row (must include id, user_id, po_number, date
     * snapshots). $lines is an array of mop_order_lines rows, already ordered
     * by line_number. Returns the absolute path of the written file, or
     * WP_Error on failure.
     */
    public static function generate( array $order, array $lines ) {
        if ( empty( $lines ) ) {
            return new WP_Error( 'mop_ordimp_no_lines', 'Cannot generate ORDIMP.DAT for an order with no lines.' );
        }

        $records = [];
        $records[] = self::build_record_100( $order );

        $comment = self::build_comment( $order );
        if ( $comment !== '' ) {
            foreach ( self::chunk_comment( $comment ) as $chunk ) {
                $records[] = self::format_record( [ '110', $chunk ], self::RECORD_110_FIELDS );
            }
        }

        foreach ( $lines as $line ) {
            $records[] = self::build_record_200( $line );
        }

        $body = implode( self::LINE_ENDING, $records ) . self::LINE_ENDING;

        $path = self::storage_path( (int) $order['user_id'], (int) $order['id'] );
        $written = file_put_contents( $path, $body );
        if ( $written === false ) {
            return new WP_Error( 'mop_ordimp_write_failed', 'Failed to write ORDIMP.DAT to ' . $path );
        }
        return $path;
    }

    /**
     * Record 100 — order header, 9 fields.
     *   1: 100
     *   2: Customer PO Number (WEB-MFG-YYYYMMDD-NNN, unique)
     *   3: Customer ID — must match FMM exactly
     *   4: Customer Name — optional display name
     *   5: Ordered Date (YYYYMMDD)
     *   6: Ordered Time (HHMMSS)
     *   7: Delivery Date (YYYYMMDD) — same as ordered for now, FMM just uses
     *      this as scheduling metadata on import
     *   8: Delivery Time (HHMMSS) — blank
     *   9: Split Billing ID — blank
     */
    private static function build_record_100( array $order ) {
        $ordered_date = self::date_yyyymmdd( $order['ordered_date'] ?? '' );
        $ordered_time = self::time_hhmmss( $order['ordered_time'] ?? '' );

        $fields = [
            '100',
            (string) $order['po_number'],
            (string) $order['customer_id_snapshot'],
            (string) ( $order['company_snapshot'] ?? '' ),
            $ordered_date,
            $ordered_time,
            $ordered_date, // delivery date mirrors ordered date
            '',
            '',
        ];
        return self::format_record( $fields, self::RECORD_100_FIELDS );
    }

    /**
     * Record 200 — stock item line, 25 fields.
     * See class docblock for field mapping.
     */
    private static function build_record_200( array $line ) {
        $fields = [
            '200',                                                      // 1: record type
            '2',                                                        // 2: line type = stock
            '0',                                                        // 3: pricing flag = re-price
            strtoupper( (string) $line['fmm_item_number'] ),            // 4: line code
            (string) $line['description'],                              // 5: description
            self::format_qty( $line['qty_base'] ),                      // 6: ordered qty (BASE)
            '0',                                                        // 7: qty in base = 0
            strtoupper( (string) $line['base_uom'] ),                   // 8: UofM
            '',                                                         // 9: price level
            '',                                                         // 10: unit price
            '',                                                         // 11: unit cost
            strtoupper( (string) ( $line['site_id'] ?? self::DEFAULT_SITE_ID ) ), // 12: site ID
            '', '', '', '', '', '',                                     // 13-18: group/farm/barn/room/pen/bin
            '', '',                                                     // 19-20: start/end delivery date
            '', '',                                                     // 21-22: start/end delivery time
            '', '',                                                     // 23-24: user-defined 1/2
            '',                                                         // 25: required trailing empty
        ];
        return self::format_record( $fields, self::RECORD_200_FIELDS );
    }

    /**
     * Build a single comment string that'll be split across one or more
     * Record 110s. Starts with "WEB ORDER" + contact name + order type so
     * an admin scanning the raw file can immediately see where it came
     * from. Customer-entered free-text comments are appended.
     */
    private static function build_comment( array $order ) {
        $name = trim(
            (string) ( $order['contact_first_name_snapshot'] ?? '' ) . ' ' .
            (string) ( $order['contact_last_name_snapshot'] ?? '' )
        );
        $company = (string) ( $order['company_snapshot'] ?? '' );

        $who = $name !== '' ? $name : $company;
        if ( $who === '' ) {
            $who = (string) $order['customer_id_snapshot'];
        }

        $type_label = strtoupper( MOP_Order::order_type_label( $order['order_type'] ?? '' ) );

        $header = 'WEB ORDER - ' . strtoupper( $who ) . ' - ' . $type_label;

        $user_comment = trim( (string) ( $order['comments'] ?? '' ) );
        if ( $user_comment !== '' ) {
            $header .= ' - ' . $user_comment;
        }
        return $header;
    }

    /**
     * Split a single comment string into up to N=5 lines of ≤100 chars each
     * (FMM caps total at 500). Commas/CR/LF in the comment are stripped by
     * format_record()'s sanitize_field.
     */
    private static function chunk_comment( $comment ) {
        $comment = substr( $comment, 0, self::MAX_COMMENT_TOTAL );
        $chunks  = [];
        for ( $i = 0; $i < strlen( $comment ); $i += self::MAX_COMMENT_LINE ) {
            $chunks[] = substr( $comment, $i, self::MAX_COMMENT_LINE );
        }
        return $chunks;
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

    /**
     * Format a record's fields: comma-join, pad to exact field count with
     * empties, slice off any overflow. FMM requires exactly N fields per
     * record; a trailing `,` to produce the final empty field is part of
     * the spec for Record 200 (25 fields with 24 documented — the final
     * empty IS required).
     */
    public static function format_record( array $fields, $expected_count ) {
        $fields = array_pad( $fields, $expected_count, '' );
        $fields = array_slice( $fields, 0, $expected_count );
        return implode( ',', array_map( [ __CLASS__, 'sanitize_field' ], $fields ) );
    }

    /**
     * Strip commas + CR/LF from any user-supplied field value (they'd
     * break the delimiter / line structure) and collapse extra whitespace.
     */
    private static function sanitize_field( $value ) {
        $value = (string) $value;
        $value = str_replace( [ ',', "\r", "\n" ], ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    /**
     * Format a qty for the file. Drop trailing zeros so whole numbers come
     * out as "50" not "50.0000" — matches the PDF sample.
     */
    private static function format_qty( $qty ) {
        $num = (float) $qty;
        if ( $num == (int) $num ) {
            return (string) (int) $num;
        }
        return rtrim( rtrim( number_format( $num, 4, '.', '' ), '0' ), '.' );
    }

    private static function date_yyyymmdd( $value ) {
        if ( ! $value ) {
            return '';
        }
        $ts = strtotime( (string) $value );
        return $ts ? gmdate( 'Ymd', $ts ) : '';
    }

    private static function time_hhmmss( $value ) {
        if ( ! $value ) {
            return '';
        }
        $ts = strtotime( (string) $value );
        return $ts ? gmdate( 'His', $ts ) : '';
    }
}
