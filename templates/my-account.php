<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user = MOP_Auth::current_user();
if ( ! $user ) {
    return;
}

$base       = MOP_Settings::get( 'shortcode_url' ) ?: '';
$edit_href  = add_query_arg( 'mop_view', 'edit-account', $base );
$order_href = add_query_arg( 'mop_view', 'create-order', $base );
?>
<div class="mop-view mop-view--my-account">
    <h2><?php echo esc_html( sprintf( __( 'Welcome, %s', 'matthewsorderplugin' ), MOP_User::full_name( $user ) ) ); ?></h2>

    <dl class="mop-summary">
        <dt><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></dt>
        <dd><?php echo esc_html( $user['customer_id'] ); ?></dd>

        <dt><?php esc_html_e( 'Company', 'matthewsorderplugin' ); ?></dt>
        <dd><?php echo esc_html( $user['company_name'] ); ?></dd>

        <dt><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></dt>
        <dd><?php echo esc_html( $user['email'] ); ?></dd>
    </dl>

    <ul class="mop-actions">
        <li><a href="<?php echo esc_url( $order_href ); ?>"><?php esc_html_e( 'Place a new order', 'matthewsorderplugin' ); ?></a></li>
        <li><a href="<?php echo esc_url( $edit_href ); ?>"><?php esc_html_e( 'Edit account', 'matthewsorderplugin' ); ?></a></li>
    </ul>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-form mop-form--logout">
        <input type="hidden" name="action" value="mop_logout">
        <?php wp_nonce_field( 'mop_logout' ); ?>
        <button type="submit"><?php esc_html_e( 'Sign out', 'matthewsorderplugin' ); ?></button>
    </form>
</div>
