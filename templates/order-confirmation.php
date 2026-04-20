<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user = MOP_Auth::current_user();
if ( ! $user ) {
    return;
}

$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
$order    = $order_id ? MOP_Order::find( $order_id ) : null;

// Must be the owner — prevents sharing a confirmation URL across accounts.
if ( ! $order || (int) $order['user_id'] !== (int) $user['id'] ) {
    $base = MOP_Settings::get( 'shortcode_url' ) ?: '';
    echo '<div class="mop-view mop-view--order-confirmation">';
    echo '<p class="mop-alert mop-alert--error">' . esc_html__( 'Order not found.', 'matthewsorderplugin' ) . '</p>';
    echo '<p><a class="mop-btn mop-btn--link" href="' . esc_url( add_query_arg( 'mop_view', 'my-account', $base ) ) . '">' . esc_html__( 'Back to account', 'matthewsorderplugin' ) . '</a></p>';
    echo '</div>';
    return;
}

$lines        = MOP_Order::get_lines( (int) $order['id'] );
$base_url     = MOP_Settings::get( 'shortcode_url' ) ?: '';
$account_url  = add_query_arg( 'mop_view', 'my-account', $base_url );
$new_order_url = add_query_arg( 'mop_view', 'create-order', $base_url );

$ordered_when = ! empty( $order['created_at'] ) ? mysql2date( 'F j, Y g:i a', $order['created_at'] ) : '';
?>
<div class="mop-view mop-view--order-confirmation">

    <p class="mop-alert mop-alert--success">
        <?php esc_html_e( 'Your order has been submitted. A confirmation email is on its way.', 'matthewsorderplugin' ); ?>
    </p>

    <header class="mop-account-header">
        <div class="mop-account-header__main">
            <h2><?php esc_html_e( 'Order confirmation', 'matthewsorderplugin' ); ?></h2>
            <p class="mop-account-header__contact">
                <strong><?php echo esc_html( $order['po_number'] ); ?></strong>
                <?php if ( $ordered_when ) : ?>
                    <span class="mop-muted">·</span> <?php echo esc_html( $ordered_when ); ?>
                <?php endif; ?>
            </p>
        </div>
    </header>

    <section class="mop-order-section">
        <div class="mop-order-section__header">
            <h3><?php esc_html_e( 'Items', 'matthewsorderplugin' ); ?></h3>
            <p class="mop-order-section__hint">
                <?php
                printf(
                    esc_html__( 'Order type: %s', 'matthewsorderplugin' ),
                    esc_html( MOP_Order::order_type_label( $order['order_type'] ) )
                );
                ?>
            </p>
        </div>

        <table class="mop-cart-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Product', 'matthewsorderplugin' ); ?></th>
                    <th><?php esc_html_e( 'FMM #', 'matthewsorderplugin' ); ?></th>
                    <th><?php esc_html_e( 'UoM', 'matthewsorderplugin' ); ?></th>
                    <th class="mop-cart-col-qty"><?php esc_html_e( 'Qty', 'matthewsorderplugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $lines as $line ) :
                    $qty = rtrim( rtrim( number_format( (float) $line['qty_selling'], 4, '.', '' ), '0' ), '.' );
                    if ( $qty === '' ) { $qty = '0'; }
                    ?>
                    <tr>
                        <td>
                            <span class="mop-cart-row__desc"><?php echo esc_html( $line['description'] ); ?></span>
                            <?php if ( ! empty( $line['category_snapshot'] ) ) : ?>
                                <span class="mop-cart-row__category"><?php echo esc_html( $line['category_snapshot'] ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mop-cart-col-fmm"><?php echo esc_html( $line['fmm_item_number'] ); ?></td>
                        <td class="mop-cart-col-uom"><?php echo esc_html( $line['selling_uom'] ); ?></td>
                        <td class="mop-cart-col-qty"><?php echo esc_html( $qty ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if ( ! empty( $order['comments'] ) ) : ?>
        <section class="mop-order-section">
            <div class="mop-order-section__header">
                <h3><?php esc_html_e( 'Comments', 'matthewsorderplugin' ); ?></h3>
            </div>
            <p style="white-space:pre-wrap; margin:0;"><?php echo esc_html( $order['comments'] ); ?></p>
        </section>
    <?php endif; ?>

    <p class="mop-form-actions">
        <a class="mop-btn mop-btn--primary" href="<?php echo esc_url( $new_order_url ); ?>"><?php esc_html_e( 'Place another order', 'matthewsorderplugin' ); ?></a>
        <a class="mop-btn mop-btn--link" href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Back to account', 'matthewsorderplugin' ); ?></a>
    </p>
</div>
