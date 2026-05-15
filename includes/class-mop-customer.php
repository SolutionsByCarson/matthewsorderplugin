<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Customer (mop_customers) repository.
 *
 * A customer is the FMM-side entity: one row per Customer Number. Holds
 * billing/shipping addresses, company name, phone. Users (login accounts)
 * attach to customers many-to-many via the mop_user_customers bridge.
 *
 * `customer_id` is the FMM identifier string and is UNIQUE — that's the
 * lookup key the source spreadsheet and our admin UI both reference.
 * Internal PK `id` is what the bridge / order rows / etc. reference.
 */
class MOP_Customer {

    public static function table() {
        return MOP_Database::table( 'customers' );
    }

    public static function find( $id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function find_by_customer_id( $customer_id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE customer_id = %s', (string) $customer_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function all() {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT * FROM ' . self::table() . ' ORDER BY company_name ASC, customer_id ASC',
            ARRAY_A
        );
    }

    public static function create( array $data ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $row = array_merge( self::defaults(), $data, [
            'created_at' => $now,
            'updated_at' => $now,
        ] );
        $row['customer_id'] = substr( trim( (string) $row['customer_id'] ), 0, 15 );
        $wpdb->insert( self::table(), $row );
        return $wpdb->insert_id ? self::find( (int) $wpdb->insert_id ) : null;
    }

    public static function update( $id, array $data ) {
        global $wpdb;
        if ( isset( $data['customer_id'] ) ) {
            $data['customer_id'] = substr( trim( (string) $data['customer_id'] ), 0, 15 );
        }
        $data['updated_at'] = current_time( 'mysql' );
        $wpdb->update( self::table(), $data, [ 'id' => (int) $id ] );
        return self::find( (int) $id );
    }

    public static function delete( $id ) {
        global $wpdb;
        // Detach the bridge first so user_customers doesn't orphan.
        MOP_UserCustomer::detach_all_for_customer( (int) $id );
        $wpdb->delete( self::table(), [ 'id' => (int) $id ] );
    }

    /**
     * Display label for the account-picker / admin lists. Falls back to the
     * raw customer_id if company_name is blank.
     */
    public static function display_name( $customer ) {
        if ( ! is_array( $customer ) ) {
            return '';
        }
        $name = isset( $customer['company_name'] ) ? trim( (string) $customer['company_name'] ) : '';
        if ( $name !== '' ) {
            return $name;
        }
        return isset( $customer['customer_id'] ) ? (string) $customer['customer_id'] : '';
    }

    private static function defaults() {
        return [
            'customer_id'   => '',
            'company_name'  => null,
            'bill_to_line1' => null,
            'bill_to_line2' => null,
            'bill_to_city'  => null,
            'bill_to_state' => null,
            'bill_to_zip'   => null,
            'ship_to_line1' => null,
            'ship_to_line2' => null,
            'ship_to_city'  => null,
            'ship_to_state' => null,
            'ship_to_zip'   => null,
            'phone'         => null,
            'is_active'     => 1,
        ];
    }
}
