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

    /**
     * Welcome / credentials email sent when an admin creates a user
     * (or when the admin ticks "email credentials" on save). Contains
     * the login URL, the user's email (= username), and the plaintext
     * password. Plaintext over email is explicitly per-spec — keep in
     * mind it's only acceptable for initial onboarding, after which
     * the user should sign in and rotate it.
     */
    public static function new_user( $user, $plaintext_password, $login_url ) {
        $to      = $user['email'];
        $subject = sprintf( '[%s] Your ordering account', self::site_name() );
        $name    = MOP_User::full_name( $user );

        $body  = '<p>Hi ' . esc_html( $name ) . ',</p>';
        $body .= '<p>An ordering account has been created for you at Matthews Feed and Grain.</p>';
        $body .= '<p><strong>Sign in:</strong> <a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a></p>';
        $body .= '<p><strong>Email / username:</strong> ' . esc_html( $user['email'] ) . '<br>';
        $body .= '<strong>Password:</strong> <code>' . esc_html( $plaintext_password ) . '</code></p>';
        $body .= '<p>Please sign in and update your password as soon as possible.</p>';
        $body .= '<p>If you did not expect this email, please contact us.</p>';

        self::send( $to, $subject, $body );
    }

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

    /**
     * Sent to both the customer and the site admin after a customer
     * self-edits their account on the front-end. $changes is the diff
     * produced by MOP_Handlers::diff_user_fields(): a list of
     *   [ 'label' => ..., 'old' => ..., 'new' => ... ] rows.
     *
     * No-op if $changes is empty (nothing actually changed).
     */
    public static function account_change( $user, $changes = [] ) {
        if ( empty( $changes ) ) {
            return;
        }

        $subject = sprintf( '[%s] Account details updated', self::site_name() );
        $name    = MOP_User::full_name( $user );
        $when    = current_time( 'F j, Y g:i a' );
        $summary = self::render_change_summary( $changes );

        $body_user  = '<p>Hi ' . esc_html( $name ) . ',</p>';
        $body_user .= '<p>Your Matthews Feed and Grain account details were updated on ' . esc_html( $when ) . '. Here is a summary of what changed:</p>';
        $body_user .= $summary;
        $body_user .= '<p>If you did not make these changes, please contact us immediately.</p>';

        $body_admin  = '<p>Customer <strong>' . esc_html( $name ) . '</strong> (' . esc_html( $user['email'] ) . ', customer ID ' . esc_html( $user['customer_id'] ) . ') updated their account on ' . esc_html( $when ) . '.</p>';
        $body_admin .= '<p>Changes:</p>';
        $body_admin .= $summary;

        self::send( $user['email'],   $subject, $body_user );
        self::send( self::admin_to(), $subject, $body_admin );
    }

    private static function render_change_summary( array $changes ) {
        $rows = '';
        foreach ( $changes as $change ) {
            $label = isset( $change['label'] ) ? (string) $change['label'] : '';
            $old   = isset( $change['old'] ) && $change['old'] !== '' ? (string) $change['old'] : '—';
            $new   = isset( $change['new'] ) && $change['new'] !== '' ? (string) $change['new'] : '—';

            $rows .= '<tr>';
            $rows .= '<th align="left" style="padding:4px 12px 4px 0;">' . esc_html( $label ) . '</th>';
            $rows .= '<td style="padding:4px 12px 4px 0; color:#777;"><s>' . esc_html( $old ) . '</s></td>';
            $rows .= '<td style="padding:4px 0;"><strong>' . esc_html( $new ) . '</strong></td>';
            $rows .= '</tr>';
        }
        return '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse; margin:0 0 1rem;">' . $rows . '</table>';
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
