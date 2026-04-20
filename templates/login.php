<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$error_code = isset( $_GET['mop_error'] ) ? sanitize_key( wp_unslash( $_GET['mop_error'] ) ) : '';
$msg_code   = isset( $_GET['mop_msg'] )   ? sanitize_key( wp_unslash( $_GET['mop_msg'] ) )   : '';

$errors = [
    'bad_credentials' => __( 'That email and password combination did not match.', 'matthewsorderplugin' ),
];
$messages = [
    'logged_out'       => __( 'You have been signed out.', 'matthewsorderplugin' ),
    'password_updated' => __( 'Your password has been updated. Please sign in.', 'matthewsorderplugin' ),
];

$reset_href = add_query_arg( 'mop_view', 'request-password-reset', MOP_Settings::get( 'shortcode_url' ) ?: '' );
?>
<div class="mop-view mop-view--login">
    <h2><?php esc_html_e( 'Sign in', 'matthewsorderplugin' ); ?></h2>

    <?php if ( $error_code && isset( $errors[ $error_code ] ) ) : ?>
        <p class="mop-alert mop-alert--error"><?php echo esc_html( $errors[ $error_code ] ); ?></p>
    <?php endif; ?>

    <?php if ( $msg_code && isset( $messages[ $msg_code ] ) ) : ?>
        <p class="mop-alert mop-alert--success"><?php echo esc_html( $messages[ $msg_code ] ); ?></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-form">
        <input type="hidden" name="action" value="mop_login">
        <?php wp_nonce_field( 'mop_login' ); ?>

        <p>
            <label for="mop-email"><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></label>
            <input id="mop-email" type="email" name="email" required autocomplete="email">
        </p>
        <p>
            <label for="mop-password"><?php esc_html_e( 'Password', 'matthewsorderplugin' ); ?></label>
            <input id="mop-password" type="password" name="password" required autocomplete="current-password">
        </p>
        <p>
            <button type="submit"><?php esc_html_e( 'Sign in', 'matthewsorderplugin' ); ?></button>
        </p>
    </form>

    <p><a href="<?php echo esc_url( $reset_href ); ?>"><?php esc_html_e( 'Forgot your password?', 'matthewsorderplugin' ); ?></a></p>
</div>
