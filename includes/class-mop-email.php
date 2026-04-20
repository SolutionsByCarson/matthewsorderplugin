<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin-local email notification system.
 *
 * Five named notifications — all live here rather than in generic wp_mail calls
 * so subjects/bodies/recipients can be swapped or templated centrally later.
 *
 * Phase 1 stub: method signatures defined, bodies to be implemented in Phase 5.
 */
class MOP_Email {

    /** Sent to user_email only. Contains the reset-password link. */
    public static function password_reset( $user, $reset_url ) {
        // TODO (Phase 5).
    }

    /** Sent to user_email AND admin_email. Password was just changed. */
    public static function password_update( $user ) {
        // TODO (Phase 5).
    }

    /** Sent to user_email AND admin_email. Account info changed. */
    public static function account_change( $user, $changes = [] ) {
        // TODO (Phase 5).
    }

    /** Sent to user_email. Order received, confirmation receipt. */
    public static function order_notification( $user, $order ) {
        // TODO (Phase 5).
    }

    /**
     * Sent to admin_email. Includes order details and the generated
     * ORDIMP.DAT as a file attachment.
     */
    public static function order_submission( $user, $order, $ordimp_path ) {
        // TODO (Phase 5).
    }
}
