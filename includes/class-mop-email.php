<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin-local email notifications.
 *
 * Five notifications:
 *   password_reset     → user_email (link to set a new password)
 *   password_update    → user_email + admin_email (after successful reset)
 *   account_change     → user_email + admin_email
 *   order_notification → user_email (customer receipt)
 *   order_submission   → admin_email + ORDIMP.dat attachment
 *
 * Bodies intentionally plain-text-ish HTML — easy to customize later.
 */
class MOP_Email {

    public static function password_reset( $user, $reset_url ) {
        $to      = $user['email'];
        $subject = sprintf( '[%s] Reset your password', self::site_name() );
        $name    = MOP_User::full_name( $user );
        $minutes = (int) MOP_RESET_MINUTES;

        $body  = '<p>Hi ' . esc_html( $name ) . ',</p>';
        $body .= '<p>We received a request to reset the password on your Matthews Feed and Grain ordering account.</p>';
        $body .= '<p><a href="' . esc_url( $reset_url ) . '">Click here to set a new password</a>. This link will expire in ' . $minutes . ' minutes.</p>';
        $body .= '<p>If you did not request this, you can safely ignore this email.</p>';

        self::send( $to, $subject, $body );
    }

    public static function password_update( $user ) {
        $subject = sprintf( '[%s] Password changed', self::site_name() );
        $name    = MOP_User::full_name( $user );
        $when    = current_time( 'F j, Y g:i a' );

        $body_user  = '<p>Hi ' . esc_html( $name ) . ',</p>';
        $body_user .= '<p>Your Matthews Feed and Grain ordering password was changed on ' . esc_html( $when ) . '.</p>';
        $body_user .= '<p>All other signed-in devices have been signed out. If you did not do this, contact us immediately.</p>';

        $body_admin  = '<p>Customer ' . esc_html( $name ) . ' (' . esc_html( $user['email'] ) . ', customer ID ' . esc_html( $user['customer_id'] ) . ') reset their password on ' . esc_html( $when ) . '.</p>';

        self::send( $user['email'],    $subject, $body_user );
        self::send( self::admin_to(),  $subject, $body_admin );
    }

    public static function account_change( $user, $changes = [] ) {
        // Implemented in Phase 4 with edit-account template.
    }

    public static function order_notification( $user, $order ) {
        // Implemented in Phase 5.
    }

    public static function order_submission( $user, $order, $ordimp_path ) {
        // Implemented in Phase 5.
    }

    private static function send( $to, $subject, $html_body, $attachments = [] ) {
        if ( ! $to ) {
            return false;
        }
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        return wp_mail( $to, $subject, $html_body, $headers, $attachments );
    }

    private static function admin_to() {
        return MOP_Settings::get( 'admin_email', get_option( 'admin_email' ) );
    }

    private static function site_name() {
        return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    }
}
