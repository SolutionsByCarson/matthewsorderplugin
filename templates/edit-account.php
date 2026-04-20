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

$states = [
    'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
    'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC',
    'SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC',
];

$field = function ( $key ) use ( $user ) {
    return isset( $user[ $key ] ) ? (string) $user[ $key ] : '';
};
?>
<div class="mop-view mop-view--edit-account">

    <header class="mop-account-header">
        <h2><?php esc_html_e( 'Edit account', 'matthewsorderplugin' ); ?></h2>
        <p class="mop-account-header__id">
            <?php echo esc_html__( 'Customer ID:', 'matthewsorderplugin' ); ?>
            <strong><?php echo esc_html( $user['customer_id'] ); ?></strong>
        </p>
    </header>

    <?php if ( $error_code && isset( $errors[ $error_code ] ) ) : ?>
        <p class="mop-alert mop-alert--error"><?php echo esc_html( $errors[ $error_code ] ); ?></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-form mop-form--edit-account">
        <input type="hidden" name="action" value="mop_save_account">
        <?php wp_nonce_field( 'mop_save_account' ); ?>

        <fieldset class="mop-fieldset">
            <legend><?php esc_html_e( 'Contact', 'matthewsorderplugin' ); ?></legend>

            <p>
                <label for="mop-company_name"><?php esc_html_e( 'Company', 'matthewsorderplugin' ); ?></label>
                <input type="text" id="mop-company_name" name="company_name" maxlength="64" value="<?php echo esc_attr( $field( 'company_name' ) ); ?>">
            </p>

            <div class="mop-form-row">
                <p>
                    <label for="mop-contact_first_name"><?php esc_html_e( 'First name', 'matthewsorderplugin' ); ?></label>
                    <input type="text" id="mop-contact_first_name" name="contact_first_name" maxlength="50" value="<?php echo esc_attr( $field( 'contact_first_name' ) ); ?>">
                </p>
                <p>
                    <label for="mop-contact_last_name"><?php esc_html_e( 'Last name', 'matthewsorderplugin' ); ?></label>
                    <input type="text" id="mop-contact_last_name" name="contact_last_name" maxlength="50" value="<?php echo esc_attr( $field( 'contact_last_name' ) ); ?>">
                </p>
            </div>

            <p>
                <label for="mop-email"><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></label>
                <input type="email" id="mop-email" name="email" required maxlength="190" value="<?php echo esc_attr( $field( 'email' ) ); ?>">
            </p>
        </fieldset>

        <fieldset class="mop-fieldset">
            <legend><?php esc_html_e( 'Billing address', 'matthewsorderplugin' ); ?></legend>

            <p>
                <label for="mop-bill_to_line1"><?php esc_html_e( 'Address line 1', 'matthewsorderplugin' ); ?></label>
                <input type="text" id="mop-bill_to_line1" name="bill_to_line1" maxlength="100" value="<?php echo esc_attr( $field( 'bill_to_line1' ) ); ?>">
            </p>

            <p>
                <label for="mop-bill_to_line2"><?php esc_html_e( 'Address line 2', 'matthewsorderplugin' ); ?></label>
                <input type="text" id="mop-bill_to_line2" name="bill_to_line2" maxlength="100" value="<?php echo esc_attr( $field( 'bill_to_line2' ) ); ?>">
            </p>

            <div class="mop-form-row mop-form-row--city-state-zip">
                <p>
                    <label for="mop-bill_to_city"><?php esc_html_e( 'City', 'matthewsorderplugin' ); ?></label>
                    <input type="text" id="mop-bill_to_city" name="bill_to_city" maxlength="50" value="<?php echo esc_attr( $field( 'bill_to_city' ) ); ?>">
                </p>
                <p>
                    <label for="mop-bill_to_state"><?php esc_html_e( 'State', 'matthewsorderplugin' ); ?></label>
                    <select id="mop-bill_to_state" name="bill_to_state">
                        <option value=""><?php esc_html_e( '—', 'matthewsorderplugin' ); ?></option>
                        <?php foreach ( $states as $s ) : ?>
                            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $field( 'bill_to_state' ), $s ); ?>><?php echo esc_html( $s ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label for="mop-bill_to_zip"><?php esc_html_e( 'ZIP', 'matthewsorderplugin' ); ?></label>
                    <input type="text" id="mop-bill_to_zip" name="bill_to_zip" maxlength="10" value="<?php echo esc_attr( $field( 'bill_to_zip' ) ); ?>">
                </p>
            </div>
        </fieldset>

        <fieldset class="mop-fieldset">
            <legend><?php esc_html_e( 'Shipping address', 'matthewsorderplugin' ); ?></legend>

            <p>
                <label for="mop-ship_to_line1"><?php esc_html_e( 'Address line 1', 'matthewsorderplugin' ); ?></label>
                <input type="text" id="mop-ship_to_line1" name="ship_to_line1" maxlength="100" value="<?php echo esc_attr( $field( 'ship_to_line1' ) ); ?>">
            </p>

            <p>
                <label for="mop-ship_to_line2"><?php esc_html_e( 'Address line 2', 'matthewsorderplugin' ); ?></label>
                <input type="text" id="mop-ship_to_line2" name="ship_to_line2" maxlength="100" value="<?php echo esc_attr( $field( 'ship_to_line2' ) ); ?>">
            </p>

            <div class="mop-form-row mop-form-row--city-state-zip">
                <p>
                    <label for="mop-ship_to_city"><?php esc_html_e( 'City', 'matthewsorderplugin' ); ?></label>
                    <input type="text" id="mop-ship_to_city" name="ship_to_city" maxlength="50" value="<?php echo esc_attr( $field( 'ship_to_city' ) ); ?>">
                </p>
                <p>
                    <label for="mop-ship_to_state"><?php esc_html_e( 'State', 'matthewsorderplugin' ); ?></label>
                    <select id="mop-ship_to_state" name="ship_to_state">
                        <option value=""><?php esc_html_e( '—', 'matthewsorderplugin' ); ?></option>
                        <?php foreach ( $states as $s ) : ?>
                            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $field( 'ship_to_state' ), $s ); ?>><?php echo esc_html( $s ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label for="mop-ship_to_zip"><?php esc_html_e( 'ZIP', 'matthewsorderplugin' ); ?></label>
                    <input type="text" id="mop-ship_to_zip" name="ship_to_zip" maxlength="10" value="<?php echo esc_attr( $field( 'ship_to_zip' ) ); ?>">
                </p>
            </div>
        </fieldset>

        <p class="mop-form-actions">
            <button type="submit" class="mop-btn mop-btn--primary"><?php esc_html_e( 'Save changes', 'matthewsorderplugin' ); ?></button>
            <a class="mop-btn mop-btn--link" href="<?php echo esc_url( $my_account_url ); ?>"><?php esc_html_e( 'Cancel', 'matthewsorderplugin' ); ?></a>
        </p>
    </form>
</div>
