<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * mop_user_customers bridge — many-to-many between users and customers.
 *
 * One bridge row per (user_id, customer_id) pair. The UNIQUE index on
 * (user_id, customer_id) means attach() is naturally idempotent.
 *
 * `is_default` marks the user's preferred customer for sign-in: when a
 * user has multiple attachments and one is flagged default, we use it
 * silently; if none is flagged (or multiple defaults disagree across
 * a re-import), the account-picker shows up.
 */
class MOP_UserCustomer {

    public static function table() {
        return MOP_Database::table( 'user_customers' );
    }

    /**
     * Idempotent attach. Returns true if a bridge exists (or was created).
     */
    public static function attach( $user_id, $customer_id, $is_default = false ) {
        global $wpdb;
        $user_id     = (int) $user_id;
        $customer_id = (int) $customer_id;
        if ( $user_id <= 0 || $customer_id <= 0 ) {
            return false;
        }
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND customer_id = %d',
                $user_id, $customer_id
            ),
            ARRAY_A
        );
        if ( $existing ) {
            if ( $is_default && ! (int) $existing['is_default'] ) {
                $wpdb->update( self::table(), [ 'is_default' => 1 ], [ 'id' => (int) $existing['id'] ] );
            }
            return true;
        }
        $wpdb->insert( self::table(), [
            'user_id'     => $user_id,
            'customer_id' => $customer_id,
            'is_default'  => $is_default ? 1 : 0,
            'created_at'  => current_time( 'mysql' ),
        ] );
        return (bool) $wpdb->insert_id;
    }

    public static function detach( $user_id, $customer_id ) {
        global $wpdb;
        $wpdb->delete( self::table(), [
            'user_id'     => (int) $user_id,
            'customer_id' => (int) $customer_id,
        ] );
    }

    public static function detach_all_for_user( $user_id ) {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'user_id' => (int) $user_id ] );
    }

    public static function detach_all_for_customer( $customer_id ) {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'customer_id' => (int) $customer_id ] );
    }

    /**
     * All customers a user is attached to, ordered with the default first
     * then by company_name. Returns full mop_customers rows.
     */
    public static function customers_for_user( $user_id ) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.*, uc.is_default
                 FROM ' . MOP_Customer::table() . ' c
                 INNER JOIN ' . self::table() . ' uc ON uc.customer_id = c.id
                 WHERE uc.user_id = %d
                 ORDER BY uc.is_default DESC, c.company_name ASC, c.customer_id ASC',
                (int) $user_id
            ),
            ARRAY_A
        );
    }

    /**
     * All users attached to a customer. Returns mop_users rows + the
     * bridge id and is_default flag for admin display.
     */
    public static function users_for_customer( $customer_id ) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT u.*, uc.id AS bridge_id, uc.is_default
                 FROM ' . MOP_User::table() . ' u
                 INNER JOIN ' . self::table() . ' uc ON uc.user_id = u.id
                 WHERE uc.customer_id = %d
                 ORDER BY u.email ASC',
                (int) $customer_id
            ),
            ARRAY_A
        );
    }

    /**
     * Whether a user has access to a customer. Used by order placement
     * to verify the session's active_customer_id is legitimate.
     */
    public static function user_can_access_customer( $user_id, $customer_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT 1 FROM ' . self::table() . ' WHERE user_id = %d AND customer_id = %d LIMIT 1',
                (int) $user_id, (int) $customer_id
            )
        );
    }

    /**
     * Pick the customer to load by default for $user_id, given:
     *   - the bridge row flagged is_default (if any), else
     *   - the bridge row with the lowest id (earliest attachment).
     * Returns the mop_customers row, or null if the user has no attachments.
     */
    public static function default_customer_for_user( $user_id ) {
        $rows = self::customers_for_user( (int) $user_id );
        return $rows ? $rows[0] : null;
    }
}
