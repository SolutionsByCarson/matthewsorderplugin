<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user = MOP_Auth::current_user();
if ( ! $user ) {
    return;
}

$base       = MOP_Settings::get( 'shortcode_url' ) ?: '';
$order_href = add_query_arg( 'mop_view', 'create-order', $base );
$edit_href  = add_query_arg( 'mop_view', 'edit-account', $base );

$msg_code = isset( $_GET['mop_msg'] ) ? sanitize_key( wp_unslash( $_GET['mop_msg'] ) ) : '';
$messages = [
    'account_updated' => __( 'Your account details have been updated.', 'matthewsorderplugin' ),
];

$display_name = MOP_User::full_name( $user );
$company      = isset( $user['company_name'] ) ? (string) $user['company_name'] : '';

$format_address = function ( $prefix ) use ( $user ) {
    $line1 = trim( (string) ( $user[ $prefix . '_line1' ] ?? '' ) );
    $line2 = trim( (string) ( $user[ $prefix . '_line2' ] ?? '' ) );
    $city  = trim( (string) ( $user[ $prefix . '_city' ] ?? '' ) );
    $state = trim( (string) ( $user[ $prefix . '_state' ] ?? '' ) );
    $zip   = trim( (string) ( $user[ $prefix . '_zip' ] ?? '' ) );
    if ( ! $line1 && ! $city && ! $state && ! $zip ) {
        return null;
    }
    $city_line = trim( $city . ( $state ? ', ' . $state : '' ) . ( $zip ? ' ' . $zip : '' ) );
    return array_values( array_filter( [ $line1, $line2, $city_line ] ) );
};

$bill = $format_address( 'bill_to' );
$ship = $format_address( 'ship_to' );
?>
<div class="mop-view mop-view--my-account">

    <header class="mop-account-header">
        <h2><?php echo esc_html( $company ?: $display_name ); ?></h2>
        <?php if ( $company && $display_name && $company !== $display_name ) : ?>
            <p class="mop-account-header__contact"><?php echo esc_html( $display_name ); ?></p>
        <?php endif; ?>
        <p class="mop-account-header__id">
            <?php echo esc_html__( 'Customer ID:', 'matthewsorderplugin' ); ?>
            <strong><?php echo esc_html( $user['customer_id'] ); ?></strong>
        </p>
    </header>

    <?php if ( $msg_code && isset( $messages[ $msg_code ] ) ) : ?>
        <p class="mop-alert mop-alert--success"><?php echo esc_html( $messages[ $msg_code ] ); ?></p>
    <?php endif; ?>

    <div class="mop-cta-row">
        <a class="mop-btn mop-btn--primary mop-btn--large" href="<?php echo esc_url( $order_href ); ?>">
            <?php esc_html_e( 'Submit an Order', 'matthewsorderplugin' ); ?>
        </a>
    </div>

    <section class="mop-account-summary">
        <h3><?php esc_html_e( 'Account details', 'matthewsorderplugin' ); ?></h3>

        <div class="mop-summary-grid">
            <dl class="mop-summary">
                <dt><?php esc_html_e( 'Company', 'matthewsorderplugin' ); ?></dt>
                <dd><?php echo $company !== '' ? esc_html( $company ) : '<span class="mop-muted">' . esc_html__( '—', 'matthewsorderplugin' ) . '</span>'; ?></dd>

                <dt><?php esc_html_e( 'Contact', 'matthewsorderplugin' ); ?></dt>
                <dd><?php echo esc_html( $display_name ); ?></dd>

                <dt><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></dt>
                <dd><?php echo esc_html( $user['email'] ); ?></dd>
            </dl>

            <div class="mop-address-block">
                <h4><?php esc_html_e( 'Billing address', 'matthewsorderplugin' ); ?></h4>
                <?php if ( $bill ) : ?>
                    <address><?php echo nl2br( esc_html( implode( "\n", $bill ) ) ); ?></address>
                <?php else : ?>
                    <p class="mop-muted"><?php esc_html_e( 'No billing address on file.', 'matthewsorderplugin' ); ?></p>
                <?php endif; ?>
            </div>

            <div class="mop-address-block">
                <h4><?php esc_html_e( 'Shipping address', 'matthewsorderplugin' ); ?></h4>
                <?php if ( $ship ) : ?>
                    <address><?php echo nl2br( esc_html( implode( "\n", $ship ) ) ); ?></address>
                <?php else : ?>
                    <p class="mop-muted"><?php esc_html_e( 'No shipping address on file.', 'matthewsorderplugin' ); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <p class="mop-summary-actions">
            <a class="mop-btn mop-btn--secondary" href="<?php echo esc_url( $edit_href ); ?>">
                <?php esc_html_e( 'Edit account info', 'matthewsorderplugin' ); ?>
            </a>
        </p>
    </section>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-form mop-form--logout">
        <input type="hidden" name="action" value="mop_logout">
        <?php wp_nonce_field( 'mop_logout' ); ?>
        <button type="submit"><?php esc_html_e( 'Sign out', 'matthewsorderplugin' ); ?></button>
    </form>
</div>
