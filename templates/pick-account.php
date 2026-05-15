<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Account picker shown when an email + password combination matches more
 * than one (user, customer) pair. Pulls the candidates back out of the
 * pending-login transient that mop_login() stashed.
 */

$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
$pending = $token ? get_transient( 'mop_login_pending_' . $token ) : null;

$base      = MOP_Settings::get( 'shortcode_url' ) ?: '';
$login_url = add_query_arg( 'mop_view', 'login', $base );

if ( ! $pending || ! is_array( $pending ) ) {
    ?>
    <div class="mop-view mop-view--pick-account">
        <h2><?php esc_html_e( 'Sign-in expired', 'matthewsorderplugin' ); ?></h2>
        <p class="mop-alert mop-alert--error">
            <?php esc_html_e( 'That sign-in attempt has expired. Please sign in again.', 'matthewsorderplugin' ); ?>
        </p>
        <p><a class="mop-btn mop-btn--primary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Back to sign in', 'matthewsorderplugin' ); ?></a></p>
    </div>
    <?php
    return;
}

// Re-derive the candidate (user, customer) pairs from the verified email +
// password. Same logic as mop_login(), so a tampered URL can't sneak in a
// (user, customer) the credentials don't actually authorize.
$pairs = [];
foreach ( MOP_User::find_all_by_email( $pending['email'] ) as $u ) {
    if ( empty( $u['is_active'] ) ) {
        continue;
    }
    if ( ! MOP_User::verify_password( $u, (string) $pending['password'] ) ) {
        continue;
    }
    foreach ( MOP_UserCustomer::customers_for_user( (int) $u['id'] ) as $c ) {
        $pairs[] = [ 'user' => $u, 'customer' => $c ];
    }
}

if ( count( $pairs ) <= 1 ) {
    // Shouldn't happen — if only one survives, mop_login would've logged in directly.
    delete_transient( 'mop_login_pending_' . $token );
    ?>
    <div class="mop-view mop-view--pick-account">
        <p class="mop-alert mop-alert--error">
            <?php esc_html_e( 'We could not complete sign-in. Please try again.', 'matthewsorderplugin' ); ?>
        </p>
        <p><a class="mop-btn mop-btn--primary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Back to sign in', 'matthewsorderplugin' ); ?></a></p>
    </div>
    <?php
    return;
}
?>
<div class="mop-view mop-view--pick-account">
    <h2><?php esc_html_e( 'Choose an account', 'matthewsorderplugin' ); ?></h2>
    <p>
        <?php
        printf(
            esc_html__( 'Your sign-in unlocks %d accounts. Pick which one to use for this session — you can switch later from your account page.', 'matthewsorderplugin' ),
            count( $pairs )
        );
        ?>
    </p>

    <div class="mop-account-picker">
        <?php foreach ( $pairs as $pair ) :
            $user     = $pair['user'];
            $customer = $pair['customer'];
            $label    = MOP_Customer::display_name( $customer );
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mop-account-picker__form">
                <input type="hidden" name="action" value="mop_pick_account">
                <input type="hidden" name="token"  value="<?php echo esc_attr( $token ); ?>">
                <input type="hidden" name="user_id"     value="<?php echo (int) $user['id']; ?>">
                <input type="hidden" name="customer_id" value="<?php echo (int) $customer['id']; ?>">
                <?php wp_nonce_field( 'mop_pick_account' ); ?>
                <button type="submit" class="mop-btn mop-btn--secondary mop-btn--large mop-account-picker__btn">
                    <span class="mop-account-picker__name"><?php echo esc_html( $label ); ?></span>
                    <span class="mop-account-picker__meta">
                        <?php echo esc_html( $customer['customer_id'] ); ?>
                        <?php if ( strcasecmp( $user['email'], $pending['email'] ) === 0 && ! empty( $customer['ship_to_city'] ) ) : ?>
                            <span class="mop-muted"> · </span>
                            <?php echo esc_html( trim( ( $customer['ship_to_city'] ?? '' ) . ' ' . ( $customer['ship_to_state'] ?? '' ) ) ); ?>
                        <?php endif; ?>
                    </span>
                </button>
            </form>
        <?php endforeach; ?>
    </div>

    <p><a class="mop-btn mop-btn--link" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( '← Sign in with a different email', 'matthewsorderplugin' ); ?></a></p>
</div>
