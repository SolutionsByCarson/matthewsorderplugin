<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form POST handlers for the front-end auth flow.
 *
 * All handlers use admin-post.php (both nopriv + priv variants) so customers
 * who aren't logged into WordPress can submit them. Each form carries a nonce
 * and a redirect_to hidden input pointing back at the shortcode URL.
 *
 * Enumeration defense: request_reset always shows the same success message
 * whether or not the email matched a user, so attackers can't harvest emails.
 */
class MOP_Handlers {

    public static function init() {
        $actions = [ 'mop_login', 'mop_logout', 'mop_request_reset', 'mop_reset_password' ];
        foreach ( $actions as $action ) {
            add_action( 'admin_post_' . $action,        [ __CLASS__, $action ] );
            add_action( 'admin_post_nopriv_' . $action, [ __CLASS__, $action ] );
        }
    }

    public static function mop_login() {
        self::verify( 'mop_login' );

        $email    = isset( $_POST['email'] )    ? sanitize_email( wp_unslash( $_POST['email'] ) )    : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] )          : '';

        $user = $email ? MOP_User::find_by_email( $email ) : null;
        if ( ! $user || empty( $user['is_active'] ) || ! MOP_User::verify_password( $user, $password ) ) {
            self::redirect_with( 'login', [ 'mop_error' => 'bad_credentials' ] );
        }

        MOP_Auth::login( $user );
        self::redirect_with( 'my-account' );
    }

    public static function mop_logout() {
        self::verify( 'mop_logout' );
        MOP_Auth::logout();
        self::redirect_with( 'login', [ 'mop_msg' => 'logged_out' ] );
    }

    public static function mop_request_reset() {
        self::verify( 'mop_request_reset' );

        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $user  = $email ? MOP_User::find_by_email( $email ) : null;

        if ( $user && ! empty( $user['is_active'] ) ) {
            $token = MOP_User::issue_reset_token( (int) $user['id'] );
            $url   = self::reset_url( (int) $user['id'], $token );
            MOP_Email::password_reset( $user, $url );
        }

        self::redirect_with( 'request-password-reset', [ 'mop_msg' => 'reset_sent' ] );
    }

    public static function mop_reset_password() {
        self::verify( 'mop_reset_password' );

        $uid       = isset( $_POST['uid'] )       ? (int) $_POST['uid'] : 0;
        $token     = isset( $_POST['token'] )     ? (string) wp_unslash( $_POST['token'] )     : '';
        $password  = isset( $_POST['password'] )  ? (string) wp_unslash( $_POST['password'] )  : '';
        $password2 = isset( $_POST['password2'] ) ? (string) wp_unslash( $_POST['password2'] ) : '';

        if ( $password === '' || strlen( $password ) < 8 ) {
            self::redirect_with( 'update-password', [ 'uid' => $uid, 'token' => $token, 'mop_error' => 'weak_password' ] );
        }
        if ( $password !== $password2 ) {
            self::redirect_with( 'update-password', [ 'uid' => $uid, 'token' => $token, 'mop_error' => 'mismatch' ] );
        }

        $user = MOP_User::find_by_reset_token( $uid, $token );
        if ( ! $user ) {
            self::redirect_with( 'request-password-reset', [ 'mop_error' => 'invalid_token' ] );
        }

        MOP_User::update( (int) $user['id'], [ 'password' => $password ] );
        MOP_User::clear_reset_token( (int) $user['id'] );
        MOP_Session::delete_all_for_user( (int) $user['id'] ); // force re-login everywhere

        MOP_Email::password_update( $user );

        self::redirect_with( 'login', [ 'mop_msg' => 'password_updated' ] );
    }

    private static function verify( $action ) {
        check_admin_referer( $action );
    }

    private static function reset_url( $uid, $raw_token ) {
        $base = MOP_Settings::get( 'shortcode_url' );
        if ( ! $base ) {
            $base = home_url( '/' );
        }
        return add_query_arg( [
            'mop_view' => 'update-password',
            'uid'      => (int) $uid,
            'token'    => $raw_token,
        ], $base );
    }

    private static function redirect_with( $view, array $extra = [] ) {
        $base = MOP_Settings::get( 'shortcode_url' );
        if ( ! $base ) {
            $base = wp_get_referer() ?: home_url( '/' );
        }
        $url = add_query_arg( array_merge( [ 'mop_view' => $view ], $extra ), $base );
        wp_safe_redirect( $url );
        exit;
    }
}
