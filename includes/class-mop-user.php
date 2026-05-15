<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Login account (mop_users) repository.
 *
 * Stores email + password + contact name + auth state only. Customer
 * identity (FMM number, company, addresses) lives on mop_customers and
 * is reached via the mop_user_customers bridge.
 *
 * `email` is NOT unique — a single address can sign in to multiple
 * distinct customer accounts. Callers that look up by email get all
 * matching rows; the login flow disambiguates with an account picker.
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

    /**
     * Returns ALL users with the given email (lower-cased). Email is no
     * longer unique — the same address may appear for multiple users
     * (each attached to a different set of customers via the bridge).
     * Callers expecting one user should iterate + verify_password.
     */
    public static function find_all_by_email( $email ) {
        global $wpdb;
        $email = strtolower( trim( (string) $email ) );
        if ( $email === '' ) {
            return [];
        }
        return $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE LOWER(email) = %s', $email ),
            ARRAY_A
        );
    }

    /**
     * Convenience for callers that genuinely only want the first match
     * (e.g. forgot-password where any matching user gets a link). Returns
     * null if no row matches. Prefer find_all_by_email() for login flow.
     */
    public static function find_by_email( $email ) {
        $rows = self::find_all_by_email( $email );
        return $rows ? $rows[0] : null;
    }

    public static function all() {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT * FROM ' . self::table() . ' ORDER BY email ASC',
            ARRAY_A
        );
    }

    public static function delete( $id ) {
        global $wpdb;
        // Detach every bridge first so user_customers doesn't orphan.
        MOP_UserCustomer::detach_all_for_user( (int) $id );
        $wpdb->delete( self::table(), [ 'id' => (int) $id ] );
    }

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
        $row['email'] = strtolower( trim( (string) $row['email'] ) );
        $wpdb->insert( self::table(), $row );
        return $wpdb->insert_id ? self::find( (int) $wpdb->insert_id ) : null;
    }

    public static function update( $id, array $data ) {
        global $wpdb;
        if ( array_key_exists( 'password', $data ) ) {
            if ( $data['password'] !== '' ) {
                $data['password_hash'] = wp_hash_password( $data['password'] );
            }
            unset( $data['password'] );
        }
        if ( isset( $data['email'] ) ) {
            $data['email'] = strtolower( trim( (string) $data['email'] ) );
        }
        $data['updated_at'] = current_time( 'mysql' );
        $wpdb->update( self::table(), $data, [ 'id' => (int) $id ] );
        return self::find( (int) $id );
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

    /**
     * Display name for emails, account pickers, admin lists. Falls back
     * to the email address when both first + last are blank — the
     * intentional behavior so emails generated from CSV-imported users
     * (who only have email) read sensibly: "Hi 4and1farm@gmail.com,".
     */
    public static function full_name( $user ) {
        if ( ! is_array( $user ) ) {
            return '';
        }
        $first = isset( $user['contact_first_name'] ) ? trim( (string) $user['contact_first_name'] ) : '';
        $last  = isset( $user['contact_last_name'] )  ? trim( (string) $user['contact_last_name'] )  : '';
        $name  = trim( $first . ' ' . $last );
        return $name !== '' ? $name : ( isset( $user['email'] ) ? (string) $user['email'] : '' );
    }

    private static function defaults() {
        return [
            'email'                  => '',
            'password_hash'          => null,
            'contact_first_name'     => null,
            'contact_last_name'      => null,
            'is_active'              => 1,
            'reset_token_hash'       => null,
            'reset_token_expires_at' => null,
            'last_login_at'          => null,
        ];
    }
}
