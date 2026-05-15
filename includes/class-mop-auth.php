<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cookie-backed session authentication.
 *
 * Two entities matter post-DBv0.6.0:
 *   - mop_users (login identity: email + password + name + auth state)
 *   - mop_customers (the FMM account orders belong to)
 * A session row carries both — user_id (who's logged in) and
 * active_customer_id (which customer they're currently acting for).
 *
 * Login flow:
 *   1. MOP_Handlers::mop_login() finds all users matching email+password.
 *   2. For each matching user, look up attached customers via the bridge.
 *   3. If exactly one (user, customer) pair survives -> log in directly.
 *      If multiple pairs survive (same email under multiple customers, or
 *      multi-customer user) -> render account-picker template with the
 *      candidates; admin pick triggers mop_pick_account which calls
 *      MOP_Auth::login() with the chosen pair.
 */
class MOP_Auth {

    private static $cached_user     = false; // false = unresolved, null = anonymous
    private static $cached_session  = false;
    private static $cached_customer = false;

    public static function init() {
        // Lazy; nothing to hook here.
    }

    /**
     * The currently logged-in user row, or null. Cached per request.
     */
    public static function current_user() {
        self::resolve();
        return self::$cached_user;
    }

    /**
     * The session row, or null. Cached per request.
     */
    public static function current_session() {
        self::resolve();
        return self::$cached_session;
    }

    /**
     * The customer the session is currently acting on behalf of. May be
     * null if (a) user has no attachments yet, or (b) we couldn't load
     * the row (e.g. customer was deleted while logged in — auto-fallback
     * to the user's default attachment).
     */
    public static function current_customer() {
        self::resolve();
        if ( self::$cached_customer === false ) {
            self::$cached_customer = null;
            $session = self::$cached_session;
            $user    = self::$cached_user;
            if ( $session && $user ) {
                $cid = isset( $session['active_customer_id'] ) ? (int) $session['active_customer_id'] : 0;
                if ( $cid > 0 ) {
                    $candidate = MOP_Customer::find( $cid );
                    if ( $candidate && MOP_UserCustomer::user_can_access_customer( (int) $user['id'], $cid ) ) {
                        self::$cached_customer = $candidate;
                    }
                }
                // No / invalid active_customer_id — fall back to default attachment.
                if ( ! self::$cached_customer ) {
                    self::$cached_customer = MOP_UserCustomer::default_customer_for_user( (int) $user['id'] );
                    if ( self::$cached_customer ) {
                        MOP_Session::set_active_customer( (int) $session['id'], (int) self::$cached_customer['id'] );
                    }
                }
            }
        }
        return self::$cached_customer;
    }

    public static function is_logged_in() {
        return (bool) self::current_user();
    }

    /**
     * Create a session for ($user, $customer) and set the auth cookie.
     * Caller is responsible for verifying credentials AND verifying the
     * user has access to the customer (via MOP_UserCustomer).
     *
     * $customer may be null when the user has no attachments — they'll
     * land in My Account with a "no customer linked" notice.
     */
    public static function login( array $user, $customer = null ) {
        $customer_id = ( is_array( $customer ) && isset( $customer['id'] ) ) ? (int) $customer['id'] : null;

        list( $session, $raw ) = MOP_Session::create(
            (int) $user['id'],
            self::client_ip(),
            isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
            $customer_id
        );

        self::set_cookie( $raw, strtotime( $session['expires_at'] . ' UTC' ) );
        MOP_User::touch_last_login( (int) $user['id'] );

        self::$cached_user     = $user;
        self::$cached_session  = $session;
        self::$cached_customer = $customer ?: null;
        return $user;
    }

    public static function logout() {
        $token = isset( $_COOKIE[ MOP_COOKIE_NAME ] ) ? (string) $_COOKIE[ MOP_COOKIE_NAME ] : '';
        if ( $token !== '' ) {
            MOP_Session::delete_by_raw_token( $token );
        }
        self::clear_cookie();
        self::$cached_user     = null;
        self::$cached_session  = null;
        self::$cached_customer = null;
    }

    /**
     * Switch which customer the current session is acting for. No-op if
     * the user doesn't have access to $customer_id (defense in depth —
     * the UI should never offer a customer the user can't reach).
     */
    public static function switch_customer( $customer_id ) {
        $user    = self::current_user();
        $session = self::current_session();
        if ( ! $user || ! $session ) {
            return false;
        }
        if ( ! MOP_UserCustomer::user_can_access_customer( (int) $user['id'], (int) $customer_id ) ) {
            return false;
        }
        MOP_Session::set_active_customer( (int) $session['id'], (int) $customer_id );
        self::$cached_session  = MOP_Session::find_by_id( (int) $session['id'] );
        self::$cached_customer = false; // force re-resolve
        return true;
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

    /**
     * Shared resolver — reads the cookie, looks up the session + user,
     * and caches both. Called by every public accessor.
     */
    private static function resolve() {
        if ( self::$cached_user !== false ) {
            return;
        }
        self::$cached_session = null;
        self::$cached_user    = null;

        $token = isset( $_COOKIE[ MOP_COOKIE_NAME ] ) ? (string) $_COOKIE[ MOP_COOKIE_NAME ] : '';
        if ( $token === '' ) {
            return;
        }

        $session = MOP_Session::find_by_raw_token( $token );
        if ( ! $session ) {
            self::clear_cookie();
            return;
        }

        $user = MOP_User::find( (int) $session['user_id'] );
        if ( ! $user || empty( $user['is_active'] ) ) {
            MOP_Session::delete_by_id( (int) $session['id'] );
            self::clear_cookie();
            return;
        }

        self::$cached_session = $session;
        self::$cached_user    = $user;
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
