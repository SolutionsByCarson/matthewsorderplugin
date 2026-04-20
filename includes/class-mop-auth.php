<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cookie-backed session authentication for mop_users (customers).
 *
 * Flow:
 *   login()   – verifies password, creates mop_sessions row, sets cookie
 *   logout()  – deletes the session row, clears the cookie
 *   current_user() – reads the cookie, resolves session → user (cached per request)
 *   require_login() – redirects to ?mop_view=login if not authenticated
 */
class MOP_Auth {

    private static $cached_user = false; // false = not resolved yet, null = logged out.

    public static function init() {
        // Nothing to hook — auth resolution is lazy via current_user().
    }

    public static function current_user() {
        if ( self::$cached_user !== false ) {
            return self::$cached_user;
        }

        $token = isset( $_COOKIE[ MOP_COOKIE_NAME ] ) ? (string) $_COOKIE[ MOP_COOKIE_NAME ] : '';
        if ( $token === '' ) {
            return self::$cached_user = null;
        }

        $session = MOP_Session::find_by_raw_token( $token );
        if ( ! $session ) {
            self::clear_cookie();
            return self::$cached_user = null;
        }

        $user = MOP_User::find( (int) $session['user_id'] );
        if ( ! $user || empty( $user['is_active'] ) ) {
            MOP_Session::delete_by_id( (int) $session['id'] );
            self::clear_cookie();
            return self::$cached_user = null;
        }

        return self::$cached_user = $user;
    }

    public static function is_logged_in() {
        return (bool) self::current_user();
    }

    /**
     * Create a session for $user and set the auth cookie. Returns the user row.
     */
    public static function login( array $user ) {
        list( $session, $raw ) = MOP_Session::create(
            (int) $user['id'],
            self::client_ip(),
            isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
        );

        self::set_cookie( $raw, strtotime( $session['expires_at'] . ' UTC' ) );
        MOP_User::touch_last_login( (int) $user['id'] );

        self::$cached_user = $user;
        return $user;
    }

    public static function logout() {
        $token = isset( $_COOKIE[ MOP_COOKIE_NAME ] ) ? (string) $_COOKIE[ MOP_COOKIE_NAME ] : '';
        if ( $token !== '' ) {
            MOP_Session::delete_by_raw_token( $token );
        }
        self::clear_cookie();
        self::$cached_user = null;
    }

    public static function require_login() {
        if ( self::is_logged_in() ) {
            return;
        }
        $url = MOP_Settings::get( 'shortcode_url' );
        if ( $url ) {
            wp_safe_redirect( add_query_arg( 'mop_view', 'login', $url ) );
            exit;
        }
    }

    private static function set_cookie( $raw_token, $expires_ts ) {
        $params = [
            'expires'  => $expires_ts,
            'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie( MOP_COOKIE_NAME, $raw_token, $params );
        $_COOKIE[ MOP_COOKIE_NAME ] = $raw_token;
    }

    private static function clear_cookie() {
        $params = [
            'expires'  => time() - DAY_IN_SECONDS,
            'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie( MOP_COOKIE_NAME, '', $params );
        unset( $_COOKIE[ MOP_COOKIE_NAME ] );
    }

    private static function client_ip() {
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return (string) $_SERVER['REMOTE_ADDR'];
        }
        return null;
    }
}
