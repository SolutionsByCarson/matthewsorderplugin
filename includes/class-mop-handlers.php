<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form POST handlers for the front-end auth flow.
 *
 * All handlers use admin-post.php (both nopriv + priv variants) so customers
 * who aren't logged into WordPress can submit them. Each form carries a nonce
 * and a redirect_to hidden input pointing back at the shortcode URL.
 *
 * Enumeration defense: request_reset always shows the same success message
 * whether or not the email matched a user, so attackers can't harvest emails.
 */
class MOP_Handlers {

    public static function init() {
        $public_actions = [ 'mop_login', 'mop_logout', 'mop_request_reset', 'mop_reset_password', 'mop_save_account' ];
        foreach ( $public_actions as $action ) {
            add_action( 'admin_post_' . $action,        [ __CLASS__, $action ] );
            add_action( 'admin_post_nopriv_' . $action, [ __CLASS__, $action ] );
        }

        $admin_actions = [ 'mop_save_user', 'mop_delete_user', 'mop_save_product', 'mop_delete_product' ];
        foreach ( $admin_actions as $action ) {
            add_action( 'admin_post_' . $action, [ __CLASS__, $action ] );
        }
    }

    public static function mop_login() {
        self::verify( 'mop_login' );

        $email    = isset( $_POST['email'] )    ? sanitize_email( wp_unslash( $_POST['email'] ) )    : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] )          : '';

        $user = $email ? MOP_User::find_by_email( $email ) : null;
        if ( ! $user || empty( $user['is_active'] ) || ! MOP_User::verify_password( $user, $password ) ) {
            self::redirect_with( 'login', [ 'mop_error' => 'bad_credentials' ] );
        }

        MOP_Auth::login( $user );
        self::redirect_with( 'my-account' );
    }

    public static function mop_logout() {
        self::verify( 'mop_logout' );
        MOP_Auth::logout();
        self::redirect_with( 'login', [ 'mop_msg' => 'logged_out' ] );
    }

    public static function mop_request_reset() {
        self::verify( 'mop_request_reset' );

        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $user  = $email ? MOP_User::find_by_email( $email ) : null;

        if ( $user && ! empty( $user['is_active'] ) ) {
            $token = MOP_User::issue_reset_token( (int) $user['id'] );
            $url   = self::reset_url( (int) $user['id'], $token );
            MOP_Email::password_reset( $user, $url );
        }

        self::redirect_with( 'request-password-reset', [ 'mop_msg' => 'reset_sent' ] );
    }

    public static function mop_reset_password() {
        self::verify( 'mop_reset_password' );

        $uid       = isset( $_POST['uid'] )       ? (int) $_POST['uid'] : 0;
        $token     = isset( $_POST['token'] )     ? (string) wp_unslash( $_POST['token'] )     : '';
        $password  = isset( $_POST['password'] )  ? (string) wp_unslash( $_POST['password'] )  : '';
        $password2 = isset( $_POST['password2'] ) ? (string) wp_unslash( $_POST['password2'] ) : '';

        if ( $password === '' || strlen( $password ) < 8 ) {
            self::redirect_with( 'update-password', [ 'uid' => $uid, 'token' => $token, 'mop_error' => 'weak_password' ] );
        }
        if ( $password !== $password2 ) {
            self::redirect_with( 'update-password', [ 'uid' => $uid, 'token' => $token, 'mop_error' => 'mismatch' ] );
        }

        $user = MOP_User::find_by_reset_token( $uid, $token );
        if ( ! $user ) {
            self::redirect_with( 'request-password-reset', [ 'mop_error' => 'invalid_token' ] );
        }

        MOP_User::update( (int) $user['id'], [ 'password' => $password ] );
        MOP_User::clear_reset_token( (int) $user['id'] );
        MOP_Session::delete_all_for_user( (int) $user['id'] ); // force re-login everywhere

        MOP_Email::password_update( $user );

        self::redirect_with( 'login', [ 'mop_msg' => 'password_updated' ] );
    }

    /**
     * Self-service account edit from the front-end "Edit account" view.
     *
     * The customer_id is intentionally NOT editable here (it's the FMM
     * Customer ID — changing it would break their order history). All
     * other identity/address fields are fair game.
     *
     * On success we compute a human-readable diff of what changed and
     * hand it to MOP_Email::account_change() so both the customer and
     * the site admin get a summary.
     */
    public static function mop_save_account() {
        self::verify( 'mop_save_account' );

        $current = MOP_Auth::current_user();
        if ( ! $current ) {
            self::redirect_with( 'login', [ 'mop_error' => 'not_logged_in' ] );
        }

        $id    = (int) $current['id'];
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

        if ( $email === '' ) {
            self::redirect_with( 'edit-account', [ 'mop_error' => 'email_required' ] );
        }
        if ( ! is_email( $email ) ) {
            self::redirect_with( 'edit-account', [ 'mop_error' => 'email_invalid' ] );
        }

        $existing_email = MOP_User::find_by_email( $email );
        if ( $existing_email && (int) $existing_email['id'] !== $id ) {
            self::redirect_with( 'edit-account', [ 'mop_error' => 'email_in_use' ] );
        }

        $data = [
            'email'              => $email,
            'company_name'       => self::post_str( 'company_name', 64 ),
            'contact_first_name' => self::post_str( 'contact_first_name', 50 ),
            'contact_last_name'  => self::post_str( 'contact_last_name', 50 ),
            'bill_to_line1'      => self::post_str( 'bill_to_line1', 100 ),
            'bill_to_line2'      => self::post_str( 'bill_to_line2', 100 ),
            'bill_to_city'       => self::post_str( 'bill_to_city', 50 ),
            'bill_to_state'      => strtoupper( self::post_str( 'bill_to_state', 2 ) ),
            'bill_to_zip'        => self::post_str( 'bill_to_zip', 10 ),
            'ship_to_line1'      => self::post_str( 'ship_to_line1', 100 ),
            'ship_to_line2'      => self::post_str( 'ship_to_line2', 100 ),
            'ship_to_city'       => self::post_str( 'ship_to_city', 50 ),
            'ship_to_state'      => strtoupper( self::post_str( 'ship_to_state', 2 ) ),
            'ship_to_zip'        => self::post_str( 'ship_to_zip', 10 ),
        ];

        $changes = self::diff_user_fields( $current, $data );
        $updated = MOP_User::update( $id, $data );

        if ( $updated && $changes ) {
            MOP_Email::account_change( $updated, $changes );
        }

        self::redirect_with( 'my-account', [ 'mop_msg' => 'account_updated' ] );
    }

    /**
     * Compare the logged-in user's stored row against the submitted form
     * values and return a list of field changes as:
     *   [ [ 'label' => 'Email', 'old' => '...', 'new' => '...' ], ... ]
     *
     * Only fields present in $new are considered. Empty-string / null are
     * treated as equivalent so "no value" → "no value" isn't a change.
     */
    private static function diff_user_fields( array $old, array $new ) {
        $labels = [
            'email'              => __( 'Email', 'matthewsorderplugin' ),
            'company_name'       => __( 'Company', 'matthewsorderplugin' ),
            'contact_first_name' => __( 'First name', 'matthewsorderplugin' ),
            'contact_last_name'  => __( 'Last name', 'matthewsorderplugin' ),
            'bill_to_line1'      => __( 'Billing address line 1', 'matthewsorderplugin' ),
            'bill_to_line2'      => __( 'Billing address line 2', 'matthewsorderplugin' ),
            'bill_to_city'       => __( 'Billing city', 'matthewsorderplugin' ),
            'bill_to_state'      => __( 'Billing state', 'matthewsorderplugin' ),
            'bill_to_zip'        => __( 'Billing ZIP', 'matthewsorderplugin' ),
            'ship_to_line1'      => __( 'Shipping address line 1', 'matthewsorderplugin' ),
            'ship_to_line2'      => __( 'Shipping address line 2', 'matthewsorderplugin' ),
            'ship_to_city'       => __( 'Shipping city', 'matthewsorderplugin' ),
            'ship_to_state'      => __( 'Shipping state', 'matthewsorderplugin' ),
            'ship_to_zip'        => __( 'Shipping ZIP', 'matthewsorderplugin' ),
        ];

        $changes = [];
        foreach ( $new as $key => $new_val ) {
            if ( ! isset( $labels[ $key ] ) ) {
                continue;
            }
            $old_val = isset( $old[ $key ] ) ? (string) $old[ $key ] : '';
            $new_val = (string) $new_val;
            if ( $old_val === $new_val ) {
                continue;
            }
            $changes[] = [
                'label' => $labels[ $key ],
                'old'   => $old_val,
                'new'   => $new_val,
            ];
        }
        return $changes;
    }

    /* -------------------------------------------------------------------- */
    /* Admin screens                                                        */
    /* -------------------------------------------------------------------- */

    public static function mop_save_user() {
        self::verify_admin( 'mop_save_user' );

        $id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $is_new     = $id === 0;

        $customer_id = isset( $_POST['customer_id'] ) ? trim( (string) wp_unslash( $_POST['customer_id'] ) ) : '';
        $email       = isset( $_POST['email'] )       ? sanitize_email( wp_unslash( $_POST['email'] ) )     : '';
        $password    = isset( $_POST['password'] )    ? (string) wp_unslash( $_POST['password'] )           : '';
        $send_creds  = ! empty( $_POST['send_credentials'] );
        $is_active   = ! empty( $_POST['is_active'] ) ? 1 : 0;

        // Validate.
        if ( $is_new && $customer_id === '' ) {
            self::redirect_admin( 'mop_users', [ 'action' => 'new', 'mop_error' => 'customer_id_required' ] );
        }
        if ( $email === '' ) {
            self::redirect_admin( 'mop_users', array_filter( [ 'action' => $is_new ? 'new' : 'edit', 'id' => $id ?: null, 'mop_error' => 'email_required' ] ) );
        }
        if ( $is_new && ( $password === '' || strlen( $password ) < 8 ) ) {
            self::redirect_admin( 'mop_users', [ 'action' => 'new', 'mop_error' => 'password_required' ] );
        }

        $data = [
            'email'              => $email,
            'company_name'       => self::post_str( 'company_name', 64 ),
            'contact_first_name' => self::post_str( 'contact_first_name', 50 ),
            'contact_last_name'  => self::post_str( 'contact_last_name', 50 ),
            'bill_to_line1'      => self::post_str( 'bill_to_line1', 100 ),
            'bill_to_line2'      => self::post_str( 'bill_to_line2', 100 ),
            'bill_to_city'       => self::post_str( 'bill_to_city', 50 ),
            'bill_to_state'      => strtoupper( self::post_str( 'bill_to_state', 2 ) ),
            'bill_to_zip'        => self::post_str( 'bill_to_zip', 10 ),
            'ship_to_line1'      => self::post_str( 'ship_to_line1', 100 ),
            'ship_to_line2'      => self::post_str( 'ship_to_line2', 100 ),
            'ship_to_city'       => self::post_str( 'ship_to_city', 50 ),
            'ship_to_state'      => strtoupper( self::post_str( 'ship_to_state', 2 ) ),
            'ship_to_zip'        => self::post_str( 'ship_to_zip', 10 ),
            'is_active'          => $is_active,
        ];
        if ( $password !== '' ) {
            $data['password'] = $password;
        }

        // Uniqueness checks.
        $existing_email = MOP_User::find_by_email( $email );
        if ( $existing_email && (int) $existing_email['id'] !== $id ) {
            self::redirect_admin( 'mop_users', array_filter( [ 'action' => $is_new ? 'new' : 'edit', 'id' => $id ?: null, 'mop_error' => 'email_in_use' ] ) );
        }

        if ( $is_new ) {
            $existing_cid = MOP_User::find_by_customer_id( $customer_id );
            if ( $existing_cid ) {
                self::redirect_admin( 'mop_users', [ 'action' => 'new', 'mop_error' => 'customer_id_in_use' ] );
            }
            $data['customer_id'] = substr( $customer_id, 0, 15 );
            $user = MOP_User::create( $data );
        } else {
            $existing = MOP_User::find( $id );
            if ( ! $existing ) {
                self::redirect_admin( 'mop_users', [ 'mop_error' => 'not_found' ] );
            }
            $user = MOP_User::update( $id, $data );
        }

        if ( $send_creds && $user && $password !== '' ) {
            MOP_Email::new_user( $user, $password, self::login_url() );
        }

        $key = $is_new
            ? ( $send_creds ? 'user_created_sent' : 'user_created' )
            : ( $send_creds && $password !== '' ? 'user_saved_sent' : 'user_saved' );
        self::redirect_admin( 'mop_users', [ 'mop_notice' => $key ] );
    }

    public static function mop_delete_user() {
        $id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
        check_admin_referer( 'mop_delete_user_' . $id );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }
        if ( $id ) {
            MOP_Session::delete_all_for_user( $id );
            MOP_User::delete( $id );
        }
        self::redirect_admin( 'mop_users', [ 'mop_notice' => 'user_deleted' ] );
    }

    public static function mop_save_product() {
        self::verify_admin( 'mop_save_product' );

        $id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $is_new = $id === 0;

        $item_number = MOP_Product::normalize_item_number( wp_unslash( $_POST['fmm_item_number'] ?? '' ) );
        $description = self::post_str( 'description', 50 );

        if ( $item_number === '' ) {
            self::redirect_admin( 'mop_products', array_filter( [ 'action' => $is_new ? 'new' : 'edit', 'id' => $id ?: null, 'mop_error' => 'item_number_required' ] ) );
        }
        if ( $description === '' ) {
            self::redirect_admin( 'mop_products', array_filter( [ 'action' => $is_new ? 'new' : 'edit', 'id' => $id ?: null, 'mop_error' => 'description_required' ] ) );
        }

        $data = [
            'fmm_item_number'   => $item_number,
            'description'       => $description,
            'category'          => self::post_str( 'category', 100 ),
            'sort_order'        => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
            'selling_uom'       => strtoupper( self::post_str( 'selling_uom', 20 ) ),
            'base_uom'          => in_array( ( $_POST['base_uom'] ?? '' ), [ 'POUND', 'EACH' ], true ) ? $_POST['base_uom'] : 'POUND',
            'conversion_factor' => isset( $_POST['conversion_factor'] ) ? (float) $_POST['conversion_factor'] : 1.0,
            'site_id'           => self::post_str( 'site_id', 10 ) ?: 'MATTHEWS',
        ];

        // Uniqueness: fmm_item_number is the business key.
        $existing = MOP_Product::find_by_item_number( $item_number );
        if ( $existing && (int) $existing['id'] !== $id ) {
            self::redirect_admin( 'mop_products', array_filter( [ 'action' => $is_new ? 'new' : 'edit', 'id' => $id ?: null, 'mop_error' => 'item_number_in_use' ] ) );
        }

        if ( $is_new ) {
            MOP_Product::create( $data );
            $notice = 'product_created';
        } else {
            $existing = MOP_Product::find( $id );
            if ( ! $existing ) {
                self::redirect_admin( 'mop_products', [ 'mop_error' => 'not_found' ] );
            }
            MOP_Product::update( $id, $data );
            $notice = 'product_saved';
        }
        self::redirect_admin( 'mop_products', [ 'mop_notice' => $notice ] );
    }

    public static function mop_delete_product() {
        $id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
        check_admin_referer( 'mop_delete_product_' . $id );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }
        if ( $id ) {
            MOP_Product::delete( $id );
        }
        self::redirect_admin( 'mop_products', [ 'mop_notice' => 'product_deleted' ] );
    }

    /* -------------------------------------------------------------------- */
    /* Helpers                                                              */
    /* -------------------------------------------------------------------- */

    private static function verify( $action ) {
        check_admin_referer( $action );
    }

    private static function verify_admin( $action ) {
        check_admin_referer( $action );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }
    }

    private static function post_str( $key, $max ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return '';
        }
        $val = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
        return $max ? substr( $val, 0, $max ) : $val;
    }

    private static function login_url() {
        $base = MOP_Settings::get( 'shortcode_url' );
        if ( ! $base ) {
            $base = home_url( '/' );
        }
        return add_query_arg( 'mop_view', 'login', $base );
    }

    private static function redirect_admin( $page, array $args = [] ) {
        $url = add_query_arg( array_merge( [ 'page' => $page ], $args ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    private static function reset_url( $uid, $raw_token ) {
        $base = MOP_Settings::get( 'shortcode_url' );
        if ( ! $base ) {
            $base = home_url( '/' );
        }
        return add_query_arg( [
            'mop_view' => 'update-password',
            'uid'      => (int) $uid,
            'token'    => $raw_token,
        ], $base );
    }

    private static function redirect_with( $view, array $extra = [] ) {
        $base = MOP_Settings::get( 'shortcode_url' );
        if ( ! $base ) {
            $base = wp_get_referer() ?: home_url( '/' );
        }
        $url = add_query_arg( array_merge( [ 'mop_view' => $view ], $extra ), $base );
        wp_safe_redirect( $url );
        exit;
    }
}
