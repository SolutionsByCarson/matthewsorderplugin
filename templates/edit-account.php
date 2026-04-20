<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user = MOP_Auth::current_user();
if ( ! $user ) {
    return;
}

$base           = MOP_Settings::get( 'shortcode_url' ) ?: '';
$my_account_url = add_query_arg( 'mop_view', 'my-account', $base );

$error_code = isset( $_GET['mop_error'] ) ? sanitize_key( wp_unslash( $_GET['mop_error'] ) ) : '';
$errors     = [
    'email_required' => __( 'Please enter an email address.', 'matthewsorderplugin' ),
    'email_invalid'  => __( 'That email address is not valid.', 'matthewsorderplugin' ),
    'email_in_use'   => __( 'That email address is already in use by another account.', 'matthewsorderplugin' ),
];
?>
<div class="mop-view mop-view--edit-account">

    <header class="mop-account-header">
        <div class="mop-account-header__main">
            <h2><?php esc_html_e( 'Edit account', 'matthewsorderplugin' ); ?></h2>
            <p class="mop-account-header__id">
                <?php echo esc_html__( 'Customer ID:', 'matthewsorderplugin' ); ?>
                <strong><?php echo esc_html( $user['customer_id'] ); ?></strong>
            </p>
        </div>
    </header>

    <?php if ( $error_code && isset( $errors[ $error_code ] ) ) : ?>
        <p class="mop-alert mop-alert--error"><?php echo esc_html( $errors[ $error_code ] ); ?></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-form mop-form--edit-account">
        <input type="hidden" name="action" value="mop_save_account">
        <?php wp_nonce_field( 'mop_save_account' ); ?>

        <?php include MOP_PLUGIN_DIR . 'templates/partials/account-fields.php'; ?>

        <p class="mop-form-actions">
            <button type="submit" class="mop-btn mop-btn--primary"><?php esc_html_e( 'Save changes', 'matthewsorderplugin' ); ?></button>
            <a class="mop-btn mop-btn--link" href="<?php echo esc_url( $my_account_url ); ?>"><?php esc_html_e( 'Cancel', 'matthewsorderplugin' ); ?></a>
        </p>
    </form>
</div>
