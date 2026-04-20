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

$grouped = MOP_Product::all_grouped_by_category();
$product_count = 0;
foreach ( $grouped as $items ) {
    $product_count += count( $items );
}

$order_types = [
    'delivery' => __( 'Delivery', 'matthewsorderplugin' ),
    'pickup'   => __( 'Pick up', 'matthewsorderplugin' ),
    'dock'     => __( 'Dock order', 'matthewsorderplugin' ),
];

$display_name = MOP_User::full_name( $user );
$company      = isset( $user['company_name'] ) ? (string) $user['company_name'] : '';
?>
<div class="mop-view mop-view--create-order">

    <header class="mop-account-header">
        <div class="mop-account-header__main">
            <h2><?php esc_html_e( 'New order', 'matthewsorderplugin' ); ?></h2>
            <p class="mop-account-header__contact">
                <?php echo esc_html( $company ?: $display_name ); ?>
                <span class="mop-muted">·</span>
                <?php echo esc_html__( 'Customer ID:', 'matthewsorderplugin' ); ?>
                <strong><?php echo esc_html( $user['customer_id'] ); ?></strong>
            </p>
        </div>
        <a class="mop-btn mop-btn--link" href="<?php echo esc_url( $my_account_url ); ?>">
            <?php esc_html_e( '← Back to account', 'matthewsorderplugin' ); ?>
        </a>
    </header>

    <?php if ( $product_count === 0 ) : ?>
        <p class="mop-alert mop-alert--error">
            <?php esc_html_e( 'No products are currently available. Please contact us for assistance.', 'matthewsorderplugin' ); ?>
        </p>
    <?php else : ?>

        <section class="mop-order-section mop-order-section--products">
            <div class="mop-order-section__header">
                <h3><?php esc_html_e( 'Products', 'matthewsorderplugin' ); ?></h3>
                <p class="mop-order-section__hint">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: number of available products */
                        _n(
                            'Browse %d product or search by name, FMM #, or category.',
                            'Browse %d products or search by name, FMM #, or category.',
                            $product_count,
                            'matthewsorderplugin'
                        ),
                        $product_count
                    ) );
                    ?>
                </p>
            </div>

            <div class="mop-product-search">
                <label for="mop-product-search" class="screen-reader-text"><?php esc_html_e( 'Search products', 'matthewsorderplugin' ); ?></label>
                <input
                    type="search"
                    id="mop-product-search"
                    placeholder="<?php esc_attr_e( 'Search products, FMM #, or category…', 'matthewsorderplugin' ); ?>"
                    autocomplete="off"
                >
            </div>

            <div class="mop-product-catalog" id="mop-product-catalog">
                <?php foreach ( $grouped as $category => $items ) : ?>
                    <div class="mop-product-category" data-category="<?php echo esc_attr( $category ); ?>">
                        <h4 class="mop-product-category__heading"><?php echo esc_html( $category ); ?></h4>
                        <ul class="mop-product-list">
                            <?php foreach ( $items as $p ) : ?>
                                <li class="mop-product-item"
                                    data-id="<?php echo (int) $p['id']; ?>"
                                    data-fmm="<?php echo esc_attr( $p['fmm_item_number'] ); ?>"
                                    data-desc="<?php echo esc_attr( $p['description'] ); ?>"
                                    data-uom="<?php echo esc_attr( $p['selling_uom'] ); ?>"
                                    data-base="<?php echo esc_attr( $p['base_uom'] ); ?>"
                                    data-factor="<?php echo esc_attr( $p['conversion_factor'] ); ?>"
                                    data-category="<?php echo esc_attr( $category ); ?>">
                                    <button type="button" class="mop-product-item__btn js-mop-product-open">
                                        <span class="mop-product-item__desc"><?php echo esc_html( $p['description'] ); ?></span>
                                        <span class="mop-product-item__meta">
                                            <span class="mop-product-item__uom"><?php echo esc_html( $p['selling_uom'] ); ?></span>
                                            <span class="mop-product-item__fmm"><?php echo esc_html( $p['fmm_item_number'] ); ?></span>
                                        </span>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="mop-product-empty js-mop-no-results" hidden>
                <?php esc_html_e( 'No products match that search.', 'matthewsorderplugin' ); ?>
            </p>
        </section>

        <section class="mop-order-section mop-order-section--cart">
            <div class="mop-order-section__header">
                <h3>
                    <?php esc_html_e( 'Your products', 'matthewsorderplugin' ); ?>
                    <span class="mop-cart-count" id="mop-cart-count" aria-live="polite">0</span>
                </h3>
            </div>

            <table class="mop-cart-table" id="mop-cart-table">
                <thead>
                    <tr>
                        <th scope="col" class="mop-cart-col-desc"><?php esc_html_e( 'Product', 'matthewsorderplugin' ); ?></th>
                        <th scope="col" class="mop-cart-col-fmm"><?php esc_html_e( 'FMM #', 'matthewsorderplugin' ); ?></th>
                        <th scope="col" class="mop-cart-col-uom"><?php esc_html_e( 'UoM', 'matthewsorderplugin' ); ?></th>
                        <th scope="col" class="mop-cart-col-qty"><?php esc_html_e( 'Qty', 'matthewsorderplugin' ); ?></th>
                        <th scope="col" class="mop-cart-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'matthewsorderplugin' ); ?></span></th>
                    </tr>
                </thead>
                <tbody id="mop-cart-body">
                    <tr class="mop-cart-empty">
                        <td colspan="5"><?php esc_html_e( 'No products added yet. Select items above to build your order.', 'matthewsorderplugin' ); ?></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-form mop-form--create-order" id="mop-order-form">
            <input type="hidden" name="action" value="mop_submit_order">
            <?php wp_nonce_field( 'mop_submit_order' ); ?>
            <div id="mop-cart-hidden"></div>

            <section class="mop-order-section mop-order-section--details">
                <div class="mop-order-section__header">
                    <h3><?php esc_html_e( 'Order details', 'matthewsorderplugin' ); ?></h3>
                    <p class="mop-order-section__hint">
                        <?php esc_html_e( 'Verify your account info below. Any edits you make here will be saved to your account when you place the order.', 'matthewsorderplugin' ); ?>
                    </p>
                </div>

                <?php include MOP_PLUGIN_DIR . 'templates/partials/account-fields.php'; ?>

                <fieldset class="mop-fieldset">
                    <legend><?php esc_html_e( 'Order options', 'matthewsorderplugin' ); ?></legend>

                    <p>
                        <label for="mop-order-type"><?php esc_html_e( 'Type of order', 'matthewsorderplugin' ); ?></label>
                        <select id="mop-order-type" name="order_type" required>
                            <?php foreach ( $order_types as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label for="mop-order-comments"><?php esc_html_e( 'Comments', 'matthewsorderplugin' ); ?></label>
                        <textarea
                            id="mop-order-comments"
                            name="comments"
                            rows="4"
                            maxlength="1000"
                            placeholder="<?php esc_attr_e( 'Any notes for this order? (optional)', 'matthewsorderplugin' ); ?>"
                        ></textarea>
                    </p>
                </fieldset>
            </section>

            <?php
            $error_code = isset( $_GET['mop_error'] ) ? sanitize_key( wp_unslash( $_GET['mop_error'] ) ) : '';
            $errors     = [
                'empty_cart'         => __( 'Please add at least one product before placing the order.', 'matthewsorderplugin' ),
                'invalid_order_type' => __( 'Please choose an order type.', 'matthewsorderplugin' ),
                'email_required'     => __( 'Please enter an email address.', 'matthewsorderplugin' ),
                'email_invalid'      => __( 'That email address is not valid.', 'matthewsorderplugin' ),
                'email_in_use'       => __( 'That email address is already in use by another account.', 'matthewsorderplugin' ),
                'save_failed'        => __( 'We could not save your order. Please try again, and contact us if it happens again.', 'matthewsorderplugin' ),
                'ordimp_failed'      => __( 'Your order was saved, but the FMM import file could not be written. Please contact us — a team member will retry on our side.', 'matthewsorderplugin' ),
            ];
            if ( $error_code && isset( $errors[ $error_code ] ) ) :
                ?>
                <p class="mop-alert mop-alert--error"><?php echo esc_html( $errors[ $error_code ] ); ?></p>
            <?php endif; ?>

            <p class="mop-form-actions">
                <button type="submit" class="mop-btn mop-btn--primary mop-btn--large" id="mop-order-submit">
                    <?php esc_html_e( 'Place order', 'matthewsorderplugin' ); ?>
                </button>
                <a class="mop-btn mop-btn--link" href="<?php echo esc_url( $my_account_url ); ?>">
                    <?php esc_html_e( 'Cancel', 'matthewsorderplugin' ); ?>
                </a>
            </p>
        </form>

        <div class="mop-modal-overlay" id="mop-product-modal" hidden>
            <div class="mop-modal" role="dialog" aria-labelledby="mop-modal-title" aria-modal="true">
                <button type="button" class="mop-modal__close js-mop-modal-close" aria-label="<?php esc_attr_e( 'Close', 'matthewsorderplugin' ); ?>">&times;</button>
                <h3 id="mop-modal-title" class="mop-modal__title"></h3>
                <dl class="mop-modal__meta">
                    <div>
                        <dt><?php esc_html_e( 'Category', 'matthewsorderplugin' ); ?></dt>
                        <dd class="js-mop-modal-category"></dd>
                    </div>
                    <div>
                        <dt><?php esc_html_e( 'FMM item #', 'matthewsorderplugin' ); ?></dt>
                        <dd class="js-mop-modal-fmm"></dd>
                    </div>
                    <div>
                        <dt><?php esc_html_e( 'Selling UoM', 'matthewsorderplugin' ); ?></dt>
                        <dd class="js-mop-modal-uom"></dd>
                    </div>
                    <div>
                        <dt><?php esc_html_e( 'Base UoM', 'matthewsorderplugin' ); ?></dt>
                        <dd class="js-mop-modal-base"></dd>
                    </div>
                </dl>
                <p class="mop-modal__qty">
                    <label for="mop-modal-qty"><?php esc_html_e( 'Quantity', 'matthewsorderplugin' ); ?></label>
                    <input type="number" id="mop-modal-qty" min="1" step="1" value="1" inputmode="numeric">
                </p>
                <p class="mop-modal__total js-mop-modal-total" aria-live="polite"></p>
                <p class="mop-modal__actions">
                    <button type="button" class="mop-btn mop-btn--link js-mop-modal-close">
                        <?php esc_html_e( 'Cancel', 'matthewsorderplugin' ); ?>
                    </button>
                    <button type="button" class="mop-btn mop-btn--primary js-mop-modal-add">
                        <?php esc_html_e( 'Add to order', 'matthewsorderplugin' ); ?>
                    </button>
                </p>
            </div>
        </div>

    <?php endif; ?>

</div>
