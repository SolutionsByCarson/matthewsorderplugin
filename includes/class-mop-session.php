<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Session repository.
 *
 * A session = one row in mop_sessions mapped to one auth cookie.
 * Only the SHA-256 hash of the token is stored in the DB — the raw
 * token lives only in the cookie on the client.
 *
 * Sessions are per-device: logging out / resetting password on one
 * browser shouldn't kill sessions on another.
 */
class MOP_Session {

    public static function table() {
        return MOP_Database::table( 'sessions' );
    }

    /**
     * Create a new session for $user_id. Returns [ $session_row, $raw_token ].
     * Caller writes the raw token to the auth cookie.
     */
    public static function create( $user_id, $ip = null, $user_agent = null ) {
        global $wpdb;

        $raw  = bin2hex( random_bytes( 32 ) );
        $hash = hash( 'sha256', $raw );

        $now_ts  = time();
        $expires = gmdate( 'Y-m-d H:i:s', $now_ts + ( MOP_SESSION_DAYS * DAY_IN_SECONDS ) );

        $wpdb->insert( self::table(), [
            'user_id'    => (int) $user_id,
            'token_hash' => $hash,
            'ip_address' => $ip ? substr( $ip, 0, 45 ) : null,
            'user_agent' => $user_agent ? substr( $user_agent, 0, 255 ) : null,
            'created_at' => current_time( 'mysql' ),
            'expires_at' => $expires,
        ] );

        return [ self::find_by_raw_token( $raw ), $raw ];
    }

    public static function find_by_raw_token( $raw_token ) {
        if ( ! $raw_token ) {
            return null;
        }
        $hash = hash( 'sha256', (string) $raw_token );
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s', $hash ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
            self::delete_by_id( (int) $row['id'] );
            return null;
        }
        return $row;
    }

    public static function delete_by_raw_token( $raw_token ) {
        $hash = hash( 'sha256', (string) $raw_token );
        global $wpdb;
        $wpdb->delete( self::table(), [ 'token_hash' => $hash ] );
    }

    public static function delete_by_id( $id ) {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'id' => (int) $id ] );
    }

    public static function delete_all_for_user( $user_id ) {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'user_id' => (int) $user_id ] );
    }

    public static function purge_expired() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE expires_at < %s',
            gmdate( 'Y-m-d H:i:s' )
        ) );
    }
}
