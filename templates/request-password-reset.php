<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$msg_code   = isset( $_GET['mop_msg'] )   ? sanitize_key( wp_unslash( $_GET['mop_msg'] ) )   : '';
$error_code = isset( $_GET['mop_error'] ) ? sanitize_key( wp_unslash( $_GET['mop_error'] ) ) : '';

$messages = [
    'reset_sent' => __( 'If that email is registered, a password reset link has been sent.', 'matthewsorderplugin' ),
];
$errors = [
    'invalid_token' => __( 'That reset link is invalid or expired. Please request a new one.', 'matthewsorderplugin' ),
];
?>
<div class="mop-view mop-view--request-password-reset">
    <h2><?php esc_html_e( 'Reset your password', 'matthewsorderplugin' ); ?></h2>

    <?php if ( $msg_code && isset( $messages[ $msg_code ] ) ) : ?>
        <p class="mop-alert mop-alert--success"><?php echo esc_html( $messages[ $msg_code ] ); ?></p>
    <?php endif; ?>

    <?php if ( $error_code && isset( $errors[ $error_code ] ) ) : ?>
        <p class="mop-alert mop-alert--error"><?php echo esc_html( $errors[ $error_code ] ); ?></p>
    <?php endif; ?>

    <p><?php esc_html_e( 'Enter the email address on your account and we will send a link to set a new password.', 'matthewsorderplugin' ); ?></p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-form">
        <input type="hidden" name="action" value="mop_request_reset">
        <?php wp_nonce_field( 'mop_request_reset' ); ?>

        <p>
            <label for="mop-email"><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></label>
            <input id="mop-email" type="email" name="email" required autocomplete="email">
        </p>
        <p>
            <button type="submit"><?php esc_html_e( 'Send reset link', 'matthewsorderplugin' ); ?></button>
        </p>
    </form>
</div>
