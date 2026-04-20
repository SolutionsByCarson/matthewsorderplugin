<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cookie-based authentication for plugin customers.
 *
 * This is SEPARATE from WordPress auth — customers are rows in the plugin's
 * own mop_users table, not wp_users. The auth cookie (MOP_COOKIE_NAME) stores
 * a signed token that maps to a session record.
 *
 * Phase 1 stub. Implementation will land in Phase 4 alongside the login
 * template and password reset flow.
 */
class MOP_Auth {

    public static function init() {
        // Real hook wiring goes here in Phase 4.
    }

    /** Return the current logged-in customer (mop_users row) or null. */
    public static function current_user() {
        // TODO (Phase 4): read cookie, verify signature, load user row.
        return null;
    }

    public static function is_logged_in() {
        return (bool) self::current_user();
    }

    /** Force redirect to login view if no valid cookie. */
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
}
