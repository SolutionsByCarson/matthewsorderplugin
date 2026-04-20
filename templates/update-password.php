<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$uid   = isset( $_GET['uid'] )   ? (int) $_GET['uid'] : 0;
$token = isset( $_GET['token'] ) ? (string) wp_unslash( $_GET['token'] ) : '';

$error_code = isset( $_GET['mop_error'] ) ? sanitize_key( wp_unslash( $_GET['mop_error'] ) ) : '';
$errors = [
    'weak_password' => __( 'Password must be at least 8 characters.', 'matthewsorderplugin' ),
    'mismatch'      => __( 'Passwords did not match.', 'matthewsorderplugin' ),
];

$valid = $uid && $token && MOP_User::find_by_reset_token( $uid, $token );
?>
<div class="mop-view mop-view--update-password">
    <h2><?php esc_html_e( 'Choose a new password', 'matthewsorderplugin' ); ?></h2>

    <?php if ( ! $valid ) : ?>
        <p class="mop-alert mop-alert--error">
            <?php esc_html_e( 'That reset link is invalid or has expired.', 'matthewsorderplugin' ); ?>
        </p>
        <p>
            <a href="<?php echo esc_url( add_query_arg( 'mop_view', 'request-password-reset', MOP_Settings::get( 'shortcode_url' ) ?: '' ) ); ?>">
                <?php esc_html_e( 'Request a new reset link', 'matthewsorderplugin' ); ?>
            </a>
        </p>
        <?php return; ?>
    <?php endif; ?>

    <?php if ( $error_code && isset( $errors[ $error_code ] ) ) : ?>
        <p class="mop-alert mop-alert--error"><?php echo esc_html( $errors[ $error_code ] ); ?></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-form">
        <input type="hidden" name="action" value="mop_reset_password">
        <input type="hidden" name="uid"    value="<?php echo esc_attr( $uid ); ?>">
        <input type="hidden" name="token"  value="<?php echo esc_attr( $token ); ?>">
        <?php wp_nonce_field( 'mop_reset_password' ); ?>

        <p>
            <label for="mop-password"><?php esc_html_e( 'New password', 'matthewsorderplugin' ); ?></label>
            <input id="mop-password" type="password" name="password" required minlength="8" autocomplete="new-password">
        </p>
        <p>
            <label for="mop-password2"><?php esc_html_e( 'Confirm new password', 'matthewsorderplugin' ); ?></label>
            <input id="mop-password2" type="password" name="password2" required minlength="8" autocomplete="new-password">
        </p>
        <p>
            <button type="submit"><?php esc_html_e( 'Save new password', 'matthewsorderplugin' ); ?></button>
        </p>
    </form>
</div>
