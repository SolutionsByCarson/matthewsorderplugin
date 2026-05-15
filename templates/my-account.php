<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user     = MOP_Auth::current_user();
$customer = MOP_Auth::current_customer();
if ( ! $user ) {
    return;
}

$base       = MOP_Settings::get( 'shortcode_url' ) ?: '';
$order_href = add_query_arg( 'mop_view', 'create-order', $base );
$edit_href  = add_query_arg( 'mop_view', 'edit-account', $base );

$msg_code = isset( $_GET['mop_msg'] )   ? sanitize_key( wp_unslash( $_GET['mop_msg'] ) )   : '';
$err_code = isset( $_GET['mop_error'] ) ? sanitize_key( wp_unslash( $_GET['mop_error'] ) ) : '';

$messages = [
    'account_updated'    => __( 'Your account details have been updated.', 'matthewsorderplugin' ),
    'customer_switched'  => __( 'Customer switched.', 'matthewsorderplugin' ),
];
$errors_table = [
    'no_customer'   => __( 'Your account is not linked to a customer. Contact us to get this set up.', 'matthewsorderplugin' ),
    'switch_failed' => __( 'Could not switch to that customer. The account may have been detached.', 'matthewsorderplugin' ),
];

$display_name = MOP_User::full_name( $user );
$company      = $customer ? (string) ( $customer['company_name'] ?? '' ) : '';
$fmm_id       = $customer ? (string) ( $customer['customer_id']  ?? '' ) : '';

$format_address = function ( $prefix ) use ( $customer ) {
    if ( ! $customer ) {
        return null;
    }
    $line1 = trim( (string) ( $customer[ $prefix . '_line1' ] ?? '' ) );
    $line2 = trim( (string) ( $customer[ $prefix . '_line2' ] ?? '' ) );
    $city  = trim( (string) ( $customer[ $prefix . '_city' ] ?? '' ) );
    $state = trim( (string) ( $customer[ $prefix . '_state' ] ?? '' ) );
    $zip   = trim( (string) ( $customer[ $prefix . '_zip' ] ?? '' ) );
    if ( ! $line1 && ! $city && ! $state && ! $zip ) {
        return null;
    }
    $city_line = trim( $city . ( $state ? ', ' . $state : '' ) . ( $zip ? ' ' . $zip : '' ) );
    return array_values( array_filter( [ $line1, $line2, $city_line ] ) );
};

$bill = $format_address( 'bill_to' );
$ship = $format_address( 'ship_to' );

$all_customers = MOP_UserCustomer::customers_for_user( (int) $user['id'] );
$multi_account = count( $all_customers ) > 1;
?>
<div class="mop-view mop-view--my-account">

    <header class="mop-account-header">
        <div class="mop-account-header__main">
            <h2><?php echo esc_html( $company !== '' ? $company : $display_name ); ?></h2>
            <?php if ( $company !== '' && $display_name !== '' && $company !== $display_name ) : ?>
                <p class="mop-account-header__contact"><?php echo esc_html( $display_name ); ?></p>
            <?php endif; ?>
            <?php if ( $fmm_id !== '' ) : ?>
                <p class="mop-account-header__id">
                    <?php echo esc_html__( 'Customer ID:', 'matthewsorderplugin' ); ?>
                    <strong><?php echo esc_html( $fmm_id ); ?></strong>
                </p>
            <?php endif; ?>
        </div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-form mop-form--logout">
            <input type="hidden" name="action" value="mop_logout">
            <?php wp_nonce_field( 'mop_logout' ); ?>
            <button type="submit"><?php esc_html_e( 'Sign out', 'matthewsorderplugin' ); ?></button>
        </form>
    </header>

    <?php if ( $msg_code && isset( $messages[ $msg_code ] ) ) : ?>
        <p class="mop-alert mop-alert--success"><?php echo esc_html( $messages[ $msg_code ] ); ?></p>
    <?php endif; ?>
    <?php if ( $err_code && isset( $errors_table[ $err_code ] ) ) : ?>
        <p class="mop-alert mop-alert--error"><?php echo esc_html( $errors_table[ $err_code ] ); ?></p>
    <?php endif; ?>

    <?php if ( $multi_account ) : ?>
        <section class="mop-account-switcher">
            <h3><?php esc_html_e( 'Switch account', 'matthewsorderplugin' ); ?></h3>
            <p class="mop-muted"><?php esc_html_e( 'Your sign-in is linked to multiple customer accounts. Orders placed in this session will be billed to the active one.', 'matthewsorderplugin' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-account-switcher__form">
                <input type="hidden" name="action" value="mop_switch_customer">
                <?php wp_nonce_field( 'mop_switch_customer' ); ?>
                <select name="customer_id" onchange="this.form.submit()">
                    <?php foreach ( $all_customers as $c ) : ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php selected( $customer && (int) $customer['id'] === (int) $c['id'] ); ?>>
                            <?php echo esc_html( MOP_Customer::display_name( $c ) ); ?>
                            (<?php echo esc_html( $c['customer_id'] ); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript>
                    <button type="submit" class="mop-btn mop-btn--secondary"><?php esc_html_e( 'Switch', 'matthewsorderplugin' ); ?></button>
                </noscript>
            </form>
        </section>
    <?php endif; ?>

    <?php if ( $customer ) : ?>
        <div class="mop-cta-row">
            <a class="mop-btn mop-btn--primary mop-btn--large" href="<?php echo esc_url( $order_href ); ?>">
                <?php esc_html_e( 'Submit an Order', 'matthewsorderplugin' ); ?>
            </a>
        </div>
    <?php else : ?>
        <p class="mop-alert mop-alert--error">
            <?php esc_html_e( 'Your account is not linked to a customer yet. Please contact us to get this set up before placing an order.', 'matthewsorderplugin' ); ?>
        </p>
    <?php endif; ?>

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

</div>
