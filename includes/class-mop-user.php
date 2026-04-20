<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Customer (mop_users) repository.
 *
 * Thin data-access layer around $wpdb. No business logic beyond:
 *   - secure password hashing (wp_hash_password / wp_check_password)
 *   - one-time reset-token generation (raw token returned to caller,
 *     only a SHA-256 hash is persisted)
 *
 * Callers that return a "user" get an associative array matching the
 * mop_users column names — we don't bother with a DTO class at this
 * size.
 */
class MOP_User {

    public static function table() {
        return MOP_Database::table( 'users' );
    }

    public static function find( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function find_by_email( $email ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', $email ), ARRAY_A );
        return $row ?: null;
    }

    public static function find_by_customer_id( $customer_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE customer_id = %s', $customer_id ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Create a user. $data keys map to column names. Password (plaintext)
     * can be passed in $data['password'] and will be hashed.
     */
    public static function create( array $data ) {
        global $wpdb;

        $now = current_time( 'mysql' );
        $row = array_merge( self::defaults(), $data, [
            'created_at' => $now,
            'updated_at' => $now,
        ] );

        if ( ! empty( $data['password'] ) ) {
            $row['password_hash'] = wp_hash_password( $data['password'] );
        }
        unset( $row['password'] );

        $wpdb->insert( self::table(), $row );
        return $wpdb->insert_id ? self::find( $wpdb->insert_id ) : null;
    }

    public static function update( $id, array $data ) {
        global $wpdb;

        if ( array_key_exists( 'password', $data ) ) {
            if ( $data['password'] !== '' ) {
                $data['password_hash'] = wp_hash_password( $data['password'] );
            }
            unset( $data['password'] );
        }

        $data['updated_at'] = current_time( 'mysql' );
        $wpdb->update( self::table(), $data, [ 'id' => (int) $id ] );
        return self::find( $id );
    }

    public static function verify_password( $user, $plaintext ) {
        if ( empty( $user['password_hash'] ) ) {
            return false;
        }
        return wp_check_password( $plaintext, $user['password_hash'], $user['id'] );
    }

    public static function touch_last_login( $id ) {
        global $wpdb;
        $wpdb->update( self::table(), [ 'last_login_at' => current_time( 'mysql' ) ], [ 'id' => (int) $id ] );
    }

    /**
     * Generate a reset token for a user. Persists only the hash +
     * expiry. Returns the RAW token — caller must put it in the email
     * link immediately; it is not recoverable after this call returns.
     */
    public static function issue_reset_token( $id ) {
        $raw  = bin2hex( random_bytes( 32 ) );
        $hash = hash( 'sha256', $raw );
        $expires = gmdate( 'Y-m-d H:i:s', time() + ( MOP_RESET_MINUTES * MINUTE_IN_SECONDS ) );

        global $wpdb;
        $wpdb->update( self::table(), [
            'reset_token_hash'       => $hash,
            'reset_token_expires_at' => $expires,
            'updated_at'             => current_time( 'mysql' ),
        ], [ 'id' => (int) $id ] );

        return $raw;
    }

    /** Find a user whose active reset token matches the supplied raw token. */
    public static function find_by_reset_token( $user_id, $raw_token ) {
        $user = self::find( $user_id );
        if ( ! $user || empty( $user['reset_token_hash'] ) || empty( $user['reset_token_expires_at'] ) ) {
            return null;
        }
        if ( strtotime( $user['reset_token_expires_at'] . ' UTC' ) < time() ) {
            return null;
        }
        if ( ! hash_equals( $user['reset_token_hash'], hash( 'sha256', (string) $raw_token ) ) ) {
            return null;
        }
        return $user;
    }

    public static function clear_reset_token( $id ) {
        global $wpdb;
        $wpdb->update( self::table(), [
            'reset_token_hash'       => null,
            'reset_token_expires_at' => null,
            'updated_at'             => current_time( 'mysql' ),
        ], [ 'id' => (int) $id ] );
    }

    public static function full_name( $user ) {
        $first = isset( $user['contact_first_name'] ) ? trim( (string) $user['contact_first_name'] ) : '';
        $last  = isset( $user['contact_last_name'] )  ? trim( (string) $user['contact_last_name'] )  : '';
        $name  = trim( $first . ' ' . $last );
        return $name !== '' ? $name : ( isset( $user['email'] ) ? $user['email'] : '' );
    }

    private static function defaults() {
        return [
            'customer_id'            => '',
            'company_name'           => null,
            'contact_first_name'     => null,
            'contact_last_name'      => null,
            'email'                  => '',
            'password_hash'          => null,
            'bill_to_line1'          => null,
            'bill_to_line2'          => null,
            'bill_to_city'           => null,
            'bill_to_state'          => null,
            'bill_to_zip'            => null,
            'ship_to_line1'          => null,
            'ship_to_line2'          => null,
            'ship_to_city'           => null,
            'ship_to_state'          => null,
            'ship_to_zip'            => null,
            'is_active'              => 1,
            'reset_token_hash'       => null,
            'reset_token_expires_at' => null,
            'last_login_at'          => null,
        ];
    }
}
