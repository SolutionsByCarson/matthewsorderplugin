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
        $public_actions = [ 'mop_login', 'mop_logout', 'mop_request_reset', 'mop_reset_password', 'mop_save_account', 'mop_submit_order', 'mop_pick_account', 'mop_switch_customer' ];
        foreach ( $public_actions as $action ) {
            add_action( 'admin_post_' . $action,        [ __CLASS__, $action ] );
            add_action( 'admin_post_nopriv_' . $action, [ __CLASS__, $action ] );
        }

        $admin_actions = [
            'mop_save_user', 'mop_delete_user',
            'mop_save_customer', 'mop_delete_customer',
            'mop_attach_user_customer', 'mop_detach_user_customer',
            'mop_save_product', 'mop_delete_product',
            'mop_admin_download_ordimp', 'mop_orders_csv',
            'mop_customers_csv_export', 'mop_customers_csv_example',
            'mop_customers_import_preview', 'mop_customers_import_apply',
            'mop_products_csv_export', 'mop_products_csv_example',
            'mop_products_import_preview', 'mop_products_import_apply',
            'mop_products_wipe_seed',
            'mop_delete_order', 'mop_delete_all_orders',
        ];
        foreach ( $admin_actions as $action ) {
            add_action( 'admin_post_' . $action, [ __CLASS__, $action ] );
        }
    }

    /**
     * Login flow with multi-customer disambiguation.
     *
     * 1. Find every mop_users row that matches the submitted email AND the
     *    submitted password. Same email can exist on multiple user rows
     *    (different customers), and any one of them might have a unique
     *    password; we treat each candidate independently.
     * 2. For each surviving user, enumerate the customers they're attached
     *    to via the bridge. Build a flat list of (user, customer) pairs.
     * 3. If exactly one pair: log in directly. If multiple: stash the
     *    plaintext credentials in a short-lived transient and redirect to
     *    the account-picker view (?mop_view=pick-account&token=...). User
     *    picks; mop_pick_account verifies the selection and finishes login.
     *
     * Bad credentials, inactive users, and "valid creds but no attached
     * customer" all redirect back to login with mop_error=bad_credentials
     * — same message, no enumeration leak.
     */
    public static function mop_login() {
        self::verify( 'mop_login' );

        $email    = isset( $_POST['email'] )    ? sanitize_email( wp_unslash( $_POST['email'] ) )    : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] )          : '';

        if ( $email === '' || $password === '' ) {
            self::redirect_with( 'login', [ 'mop_error' => 'bad_credentials' ] );
        }

        $candidates = MOP_User::find_all_by_email( $email );
        $pairs      = [];
        foreach ( $candidates as $u ) {
            if ( empty( $u['is_active'] ) ) {
                continue;
            }
            if ( ! MOP_User::verify_password( $u, $password ) ) {
                continue;
            }
            $customers = MOP_UserCustomer::customers_for_user( (int) $u['id'] );
            foreach ( $customers as $c ) {
                $pairs[] = [ 'user' => $u, 'customer' => $c ];
            }
        }

        if ( empty( $pairs ) ) {
            self::redirect_with( 'login', [ 'mop_error' => 'bad_credentials' ] );
        }

        if ( count( $pairs ) === 1 ) {
            MOP_Auth::login( $pairs[0]['user'], $pairs[0]['customer'] );
            self::redirect_with( 'my-account' );
        }

        // Multiple matches — stash the verified credentials so the picker
        // doesn't need to re-prompt for the password. Transient is keyed
        // by a one-shot token written into the URL.
        $token = wp_generate_password( 24, false, false );
        set_transient(
            'mop_login_pending_' . $token,
            [
                'email'    => strtolower( $email ),
                'password' => $password,
                'created'  => time(),
            ],
            5 * MINUTE_IN_SECONDS
        );
        self::redirect_with( 'pick-account', [ 'token' => $token ] );
    }

    /**
     * Final step of multi-account login. The pick-account view posts the
     * chosen user_id + customer_id along with the one-shot token. We
     * re-verify the password (defense in depth — the form fields could be
     * tampered with) and that the user-customer bridge actually exists.
     */
    public static function mop_pick_account() {
        self::verify( 'mop_pick_account' );

        $token       = isset( $_POST['token'] )       ? sanitize_text_field( wp_unslash( $_POST['token'] ) )       : '';
        $user_id     = isset( $_POST['user_id'] )     ? (int) $_POST['user_id']     : 0;
        $customer_id = isset( $_POST['customer_id'] ) ? (int) $_POST['customer_id'] : 0;

        $pending = $token ? get_transient( 'mop_login_pending_' . $token ) : null;
        if ( ! $pending || ! is_array( $pending ) ) {
            self::redirect_with( 'login', [ 'mop_error' => 'session_expired' ] );
        }

        $user = MOP_User::find( $user_id );
        if ( ! $user || empty( $user['is_active'] ) ) {
            self::redirect_with( 'login', [ 'mop_error' => 'bad_credentials' ] );
        }
        if ( strtolower( (string) $user['email'] ) !== strtolower( (string) $pending['email'] ) ) {
            self::redirect_with( 'login', [ 'mop_error' => 'bad_credentials' ] );
        }
        if ( ! MOP_User::verify_password( $user, (string) $pending['password'] ) ) {
            self::redirect_with( 'login', [ 'mop_error' => 'bad_credentials' ] );
        }
        if ( ! MOP_UserCustomer::user_can_access_customer( (int) $user['id'], $customer_id ) ) {
            self::redirect_with( 'login', [ 'mop_error' => 'bad_credentials' ] );
        }

        delete_transient( 'mop_login_pending_' . $token );
        MOP_Auth::login( $user, MOP_Customer::find( $customer_id ) );
        self::redirect_with( 'my-account' );
    }

    /**
     * Mid-session customer switch — for users attached to multiple
     * customers who want to flip which account they're acting on.
     * MOP_Auth::switch_customer() rejects targets the user can't reach.
     */
    public static function mop_switch_customer() {
        self::verify( 'mop_switch_customer' );

        $current = MOP_Auth::current_user();
        if ( ! $current ) {
            self::redirect_with( 'login', [ 'mop_error' => 'not_logged_in' ] );
        }
        $customer_id = isset( $_POST['customer_id'] ) ? (int) $_POST['customer_id'] : 0;
        if ( ! MOP_Auth::switch_customer( $customer_id ) ) {
            self::redirect_with( 'my-account', [ 'mop_error' => 'switch_failed' ] );
        }
        self::redirect_with( 'my-account', [ 'mop_msg' => 'customer_switched' ] );
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

        $current  = MOP_Auth::current_user();
        $customer = MOP_Auth::current_customer();
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

        // Email is no longer globally unique, but it MUST remain unique
        // within the set of users attached to this customer (otherwise we
        // can't tell two accounts apart for invoicing). Block the change
        // if another user attached to the same customer already has it.
        if ( $customer ) {
            $siblings = MOP_UserCustomer::users_for_customer( (int) $customer['id'] );
            foreach ( $siblings as $sib ) {
                if ( (int) $sib['id'] === $id ) {
                    continue;
                }
                if ( strcasecmp( (string) $sib['email'], $email ) === 0 ) {
                    self::redirect_with( 'edit-account', [ 'mop_error' => 'email_in_use' ] );
                }
            }
        }

        // Split between user-owned fields and customer-owned fields.
        $user_data = [
            'email'              => $email,
            'contact_first_name' => self::post_str( 'contact_first_name', 50 ),
            'contact_last_name'  => self::post_str( 'contact_last_name', 50 ),
        ];
        $customer_data = [
            'company_name'  => self::post_str( 'company_name', 64 ),
            'bill_to_line1' => self::post_str( 'bill_to_line1', 100 ),
            'bill_to_line2' => self::post_str( 'bill_to_line2', 100 ),
            'bill_to_city'  => self::post_str( 'bill_to_city', 50 ),
            'bill_to_state' => strtoupper( self::post_str( 'bill_to_state', 2 ) ),
            'bill_to_zip'   => self::post_str( 'bill_to_zip', 10 ),
            'ship_to_line1' => self::post_str( 'ship_to_line1', 100 ),
            'ship_to_line2' => self::post_str( 'ship_to_line2', 100 ),
            'ship_to_city'  => self::post_str( 'ship_to_city', 50 ),
            'ship_to_state' => strtoupper( self::post_str( 'ship_to_state', 2 ) ),
            'ship_to_zip'   => self::post_str( 'ship_to_zip', 10 ),
        ];

        $changes = self::diff_account_fields( $current, $customer, $user_data, $customer_data );
        $updated_user     = MOP_User::update( $id, $user_data );
        $updated_customer = $customer ? MOP_Customer::update( (int) $customer['id'], $customer_data ) : null;

        if ( $updated_user && $changes ) {
            MOP_Email::account_change( $updated_user, $updated_customer, $changes );
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
    /**
     * Diff against BOTH the user's old values and the customer's old
     * values to produce the change-summary array for account_change
     * emails. Field labels are stable per column name regardless of which
     * entity they live on.
     */
    private static function diff_account_fields( $old_user, $old_customer, array $new_user, array $new_customer ) {
        $user_labels = [
            'email'              => __( 'Email', 'matthewsorderplugin' ),
            'contact_first_name' => __( 'First name', 'matthewsorderplugin' ),
            'contact_last_name'  => __( 'Last name', 'matthewsorderplugin' ),
        ];
        $customer_labels = [
            'company_name'  => __( 'Company', 'matthewsorderplugin' ),
            'bill_to_line1' => __( 'Billing address line 1', 'matthewsorderplugin' ),
            'bill_to_line2' => __( 'Billing address line 2', 'matthewsorderplugin' ),
            'bill_to_city'  => __( 'Billing city', 'matthewsorderplugin' ),
            'bill_to_state' => __( 'Billing state', 'matthewsorderplugin' ),
            'bill_to_zip'   => __( 'Billing ZIP', 'matthewsorderplugin' ),
            'ship_to_line1' => __( 'Shipping address line 1', 'matthewsorderplugin' ),
            'ship_to_line2' => __( 'Shipping address line 2', 'matthewsorderplugin' ),
            'ship_to_city'  => __( 'Shipping city', 'matthewsorderplugin' ),
            'ship_to_state' => __( 'Shipping state', 'matthewsorderplugin' ),
            'ship_to_zip'   => __( 'Shipping ZIP', 'matthewsorderplugin' ),
        ];

        $changes = [];
        $old_user_arr     = is_array( $old_user )     ? $old_user     : [];
        $old_customer_arr = is_array( $old_customer ) ? $old_customer : [];

        foreach ( $new_user as $key => $new_val ) {
            if ( ! isset( $user_labels[ $key ] ) ) {
                continue;
            }
            $old_val = isset( $old_user_arr[ $key ] ) ? (string) $old_user_arr[ $key ] : '';
            $new_val = (string) $new_val;
            if ( $old_val === $new_val ) {
                continue;
            }
            $changes[] = [ 'label' => $user_labels[ $key ], 'old' => $old_val, 'new' => $new_val ];
        }
        foreach ( $new_customer as $key => $new_val ) {
            if ( ! isset( $customer_labels[ $key ] ) ) {
                continue;
            }
            $old_val = isset( $old_customer_arr[ $key ] ) ? (string) $old_customer_arr[ $key ] : '';
            $new_val = (string) $new_val;
            if ( $old_val === $new_val ) {
                continue;
            }
            $changes[] = [ 'label' => $customer_labels[ $key ], 'old' => $old_val, 'new' => $new_val ];
        }
        return $changes;
    }

    /**
     * Customer order submit.
     *
     * Steps:
     *   1. Verify nonce + login.
     *   2. Pull cart lines from $_POST['mop_line'] — product_id + qty per line.
     *      Reject empty carts and invalid qtys.
     *   3. Save any account edits the user made on the order form — same
     *      validation rules as mop_save_account but we silently skip the
     *      account_change email (the order emails already include snapshots).
     *   4. Build a PO number via MOP_Order::next_po_number(), retrying up to
     *      5 times on the unique-key collision window.
     *   5. Snapshot user + product data into mop_orders + mop_order_lines.
     *   6. Generate ORDIMP.DAT via MOP_Ordimp::generate(), attach path.
     *   7. Send order_notification (user) + order_submission (admin with
     *      attachment).
     *   8. Redirect to ?mop_view=order-confirmation&order_id=NN.
     */
    public static function mop_submit_order() {
        self::verify( 'mop_submit_order' );

        $current  = MOP_Auth::current_user();
        $customer = MOP_Auth::current_customer();
        if ( ! $current ) {
            self::redirect_with( 'login', [ 'mop_error' => 'not_logged_in' ] );
        }
        if ( ! $customer ) {
            self::redirect_with( 'my-account', [ 'mop_error' => 'no_customer' ] );
        }

        $lines = self::collect_cart_lines( $_POST['mop_line'] ?? [] );
        if ( empty( $lines ) ) {
            self::redirect_with( 'create-order', [ 'mop_error' => 'empty_cart' ] );
        }

        $order_type = isset( $_POST['order_type'] ) ? sanitize_key( wp_unslash( $_POST['order_type'] ) ) : '';
        if ( ! in_array( $order_type, MOP_Order::ORDER_TYPES, true ) ) {
            self::redirect_with( 'create-order', [ 'mop_error' => 'invalid_order_type' ] );
        }

        $comments = isset( $_POST['comments'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comments'] ) ) : '';
        $comments = substr( $comments, 0, 1000 );

        // Persist any account edits the customer made on the form. The
        // editable fields fall into two groups now: user-side fields
        // (email, contact name) → mop_users; customer-side fields
        // (company, addresses) → mop_customers.
        $edit_result = self::apply_account_edits_from_post( $current, $customer );
        if ( is_wp_error( $edit_result ) ) {
            self::redirect_with( 'create-order', [ 'mop_error' => $edit_result->get_error_code() ] );
        }
        $user     = $edit_result['user']     ?? $current;
        $customer = $edit_result['customer'] ?? $customer;

        // Build the order header snapshot — user identity + customer details.
        $header = array_merge(
            MOP_Order::snapshot_from_user_and_customer( $user, $customer ),
            [
                'user_id'      => (int) $user['id'],
                'order_type'   => $order_type,
                'comments'     => $comments,
                'ordered_date' => current_time( 'Y-m-d' ),
                'ordered_time' => current_time( 'H:i:s' ),
            ]
        );

        // Assign a PO number with retry on the unlikely parallel collision.
        $order = null;
        for ( $attempt = 0; $attempt < 5 && ! $order; $attempt++ ) {
            $header['po_number'] = MOP_Order::next_po_number();
            $result = MOP_Order::create( $header, $lines );
            if ( $result && ! empty( $result['order'] ) ) {
                $order = $result['order'];
                $line_rows = $result['lines'];
            }
        }
        if ( ! $order ) {
            self::redirect_with( 'create-order', [ 'mop_error' => 'save_failed' ] );
        }

        $ordimp_path = MOP_Ordimp::generate( $order, $line_rows );
        if ( is_wp_error( $ordimp_path ) ) {
            self::redirect_with( 'create-order', [ 'mop_error' => 'ordimp_failed', 'order_id' => (int) $order['id'] ] );
        }
        MOP_Order::set_ordimp_path( (int) $order['id'], $ordimp_path );
        $order['ordimp_path'] = $ordimp_path;

        MOP_Email::order_notification( $user, $customer, $order, $line_rows );
        MOP_Email::order_submission(  $user, $customer, $order, $line_rows, $ordimp_path );

        self::redirect_with( 'order-confirmation', [ 'order_id' => (int) $order['id'] ] );
    }

    /**
     * Parse the $_POST['mop_line'] structure the create-order JS builds:
     *   mop_line[<product_id>][product_id] = <id>
     *   mop_line[<product_id>][qty]        = <qty>
     *
     * Returns an array of ready-to-insert line dicts (fmm_item_number,
     * description, UoM info, qty_selling, qty_base). Silently drops lines
     * pointing at unknown products or carrying non-positive qty.
     */
    private static function collect_cart_lines( $raw ) {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $lines = [];
        foreach ( $raw as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $product_id = isset( $entry['product_id'] ) ? (int) $entry['product_id'] : 0;
            $qty        = isset( $entry['qty'] ) ? (float) $entry['qty'] : 0;
            if ( $product_id <= 0 || $qty <= 0 ) {
                continue;
            }
            $product = MOP_Product::find( $product_id );
            if ( ! $product ) {
                continue;
            }

            $lines[] = [
                'product_id'        => $product_id,
                'fmm_item_number'   => $product['fmm_item_number'],
                'description'       => $product['description'],
                'category_snapshot' => $product['category'],
                'selling_uom'       => $product['selling_uom'],
                'base_uom'          => $product['base_uom'],
                'conversion_factor' => (float) $product['conversion_factor'],
                'qty_selling'       => $qty,
                'qty_base'          => MOP_Product::convert_to_base( $product, $qty ),
                'site_id'           => $product['site_id'] ?: MOP_Ordimp::DEFAULT_SITE_ID,
            ];
        }
        return $lines;
    }

    /**
     * Validate + apply the subset of $_POST that matches the editable
     * account fields from the create-order form. Re-uses the same
     * validation rules as mop_save_account (email required/valid/unique)
     * but intentionally does NOT fire the account_change email — order
     * emails already carry the snapshot, and we don't want customers
     * getting two notifications per order submit.
     *
     * Returns the updated user row on success, a WP_Error on validation
     * failure, or null if the form did not include any account fields at
     * all (defensive — shouldn't happen with the real template).
     */
    private static function apply_account_edits_from_post( $current, $customer ) {
        if ( ! isset( $_POST['email'] ) || ! is_array( $current ) ) {
            return [ 'user' => $current, 'customer' => $customer ];
        }

        $id    = (int) $current['id'];
        $email = sanitize_email( wp_unslash( $_POST['email'] ) );

        if ( $email === '' ) {
            return new WP_Error( 'email_required', 'Email is required.' );
        }
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'email_invalid', 'Email is not valid.' );
        }
        // Block email reuse only within siblings on the same customer.
        if ( $customer ) {
            foreach ( MOP_UserCustomer::users_for_customer( (int) $customer['id'] ) as $sib ) {
                if ( (int) $sib['id'] === $id ) {
                    continue;
                }
                if ( strcasecmp( (string) $sib['email'], $email ) === 0 ) {
                    return new WP_Error( 'email_in_use', 'Email is already in use.' );
                }
            }
        }

        $user_data = [
            'email'              => $email,
            'contact_first_name' => self::post_str( 'contact_first_name', 50 ),
            'contact_last_name'  => self::post_str( 'contact_last_name', 50 ),
        ];
        $updated_user = MOP_User::update( $id, $user_data );

        $updated_customer = $customer;
        if ( $customer ) {
            $customer_data = [
                'company_name'  => self::post_str( 'company_name', 64 ),
                'bill_to_line1' => self::post_str( 'bill_to_line1', 100 ),
                'bill_to_line2' => self::post_str( 'bill_to_line2', 100 ),
                'bill_to_city'  => self::post_str( 'bill_to_city', 50 ),
                'bill_to_state' => strtoupper( self::post_str( 'bill_to_state', 2 ) ),
                'bill_to_zip'   => self::post_str( 'bill_to_zip', 10 ),
                'ship_to_line1' => self::post_str( 'ship_to_line1', 100 ),
                'ship_to_line2' => self::post_str( 'ship_to_line2', 100 ),
                'ship_to_city'  => self::post_str( 'ship_to_city', 50 ),
                'ship_to_state' => strtoupper( self::post_str( 'ship_to_state', 2 ) ),
                'ship_to_zip'   => self::post_str( 'ship_to_zip', 10 ),
            ];
            $updated_customer = MOP_Customer::update( (int) $customer['id'], $customer_data );
        }

        return [ 'user' => $updated_user, 'customer' => $updated_customer ];
    }

    /**
     * Admin-side ORDIMP.dat download, capability-gated.
     * Request: admin-post.php?action=mop_admin_download_ordimp&order_id=NN&_wpnonce=...
     */
    public static function mop_admin_download_ordimp() {
        $order_id = isset( $_REQUEST['order_id'] ) ? (int) $_REQUEST['order_id'] : 0;
        check_admin_referer( 'mop_admin_download_ordimp_' . $order_id );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }

        $order = MOP_Order::find( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'matthewsorderplugin' ) );
        }
        self::stream_ordimp_file( $order );
    }

    /**
     * Admin CSV export of ALL orders — one row per line item (wide format).
     * Request: admin-post.php?action=mop_orders_csv&_wpnonce=...
     */
    public static function mop_orders_csv() {
        check_admin_referer( 'mop_orders_csv' );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }

        $filename = 'matthews-orders-' . gmdate( 'Ymd-His' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [
            'PO Number', 'Order Date', 'Order Time', 'Order Type', 'Customer ID',
            'Company', 'Contact', 'Email',
            'Ship Line 1', 'Ship Line 2', 'Ship City', 'Ship State', 'Ship ZIP',
            'Line #', 'FMM Item Number', 'Description', 'Category',
            'Qty (Selling)', 'Selling UoM', 'Qty (Base)', 'Base UoM',
            'Site ID', 'Comments',
        ] );

        $orders = MOP_Order::all_with_summary();
        foreach ( $orders as $order ) {
            $lines = MOP_Order::get_lines( (int) $order['id'] );
            if ( empty( $lines ) ) {
                $lines = [ [] ]; // emit one row even if lines missing
            }
            $contact = trim( ( $order['contact_first_name_snapshot'] ?? '' ) . ' ' . ( $order['contact_last_name_snapshot'] ?? '' ) );
            foreach ( $lines as $line ) {
                fputcsv( $out, [
                    $order['po_number'],
                    $order['ordered_date'],
                    $order['ordered_time'],
                    MOP_Order::order_type_label( $order['order_type'] ),
                    $order['customer_id_snapshot'],
                    $order['company_snapshot'],
                    $contact,
                    $order['email_snapshot'],
                    $order['ship_to_line1_snapshot'],
                    $order['ship_to_line2_snapshot'],
                    $order['ship_to_city_snapshot'],
                    $order['ship_to_state_snapshot'],
                    $order['ship_to_zip_snapshot'],
                    $line['line_number']       ?? '',
                    $line['fmm_item_number']   ?? '',
                    $line['description']       ?? '',
                    $line['category_snapshot'] ?? '',
                    $line['qty_selling']       ?? '',
                    $line['selling_uom']       ?? '',
                    $line['qty_base']          ?? '',
                    $line['base_uom']          ?? '',
                    $line['site_id']           ?? '',
                    $order['comments'],
                ] );
            }
        }
        fclose( $out );
        exit;
    }

    /**
     * Export every customer + their attached users to a single CSV
     * matching the layout MOP_Admin_Customers_Import::columns(). Up to
     * 5 user slots per customer; extras (if any) are silently dropped to
     * keep the file shape stable. Passwords are NEVER exported.
     */
    public static function mop_customers_csv_export() {
        check_admin_referer( 'mop_customers_csv_export' );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }

        $cols     = MOP_Admin_Customers_Import::columns();
        $max      = MOP_Admin_Customers_Import::MAX_USER_SLOTS;
        $filename = 'matthews-customers-' . gmdate( 'Ymd-His' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, $cols );
        foreach ( MOP_Customer::all() as $c ) {
            $row = [
                self::csv_safe( $c['customer_id'] ),
                self::csv_safe( $c['company_name'] ?? '' ),
                self::csv_safe( $c['phone']        ?? '' ),
                self::csv_safe( $c['bill_to_line1'] ?? '' ),
                self::csv_safe( $c['bill_to_line2'] ?? '' ),
                self::csv_safe( $c['bill_to_city']  ?? '' ),
                self::csv_safe( $c['bill_to_state'] ?? '' ),
                self::csv_safe( $c['bill_to_zip']   ?? '' ),
                self::csv_safe( $c['ship_to_line1'] ?? '' ),
                self::csv_safe( $c['ship_to_line2'] ?? '' ),
                self::csv_safe( $c['ship_to_city']  ?? '' ),
                self::csv_safe( $c['ship_to_state'] ?? '' ),
                self::csv_safe( $c['ship_to_zip']   ?? '' ),
            ];
            $users = MOP_UserCustomer::users_for_customer( (int) $c['id'] );
            for ( $i = 0; $i < $max; $i++ ) {
                $u = isset( $users[ $i ] ) ? $users[ $i ] : null;
                $row[] = self::csv_safe( $u ? (string) $u['email']              : '' );
                $row[] = self::csv_safe( $u ? (string) ( $u['contact_first_name'] ?? '' ) : '' );
                $row[] = self::csv_safe( $u ? (string) ( $u['contact_last_name']  ?? '' ) : '' );
                $row[] = ''; // password never exported
            }
            fputcsv( $out, $row );
        }
        fclose( $out );
        exit;
    }

    public static function mop_customers_csv_example() {
        check_admin_referer( 'mop_customers_csv_example' );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }
        $path = MOP_PLUGIN_DIR . 'data/customers-import-example.csv';
        if ( ! file_exists( $path ) ) {
            wp_die( esc_html__( 'Example file not found.', 'matthewsorderplugin' ) );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="customers-import-example.csv"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        readfile( $path );
        exit;
    }

    public static function mop_customers_import_preview() {
        self::verify_admin( 'mop_customers_import_preview' );

        if ( empty( $_FILES['csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) {
            self::redirect_admin( MOP_Admin_Customers::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => 'no_file' ] );
        }
        if ( ! empty( $_FILES['csv_file']['error'] ) ) {
            self::redirect_admin( MOP_Admin_Customers::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => 'upload_failed' ] );
        }
        $parsed = MOP_Admin_Customers_Import::parse_csv( $_FILES['csv_file']['tmp_name'] );
        if ( is_wp_error( $parsed ) ) {
            self::redirect_admin( MOP_Admin_Customers::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => $parsed->get_error_code() ] );
        }
        $token = wp_generate_password( 24, false, false );
        set_transient( 'mop_customers_import_' . $token, $parsed, HOUR_IN_SECONDS );
        self::redirect_admin( MOP_Admin_Customers::PAGE_SLUG, [ 'action' => 'import-preview', 'token' => $token ] );
    }

    public static function mop_customers_import_apply() {
        self::verify_admin( 'mop_customers_import_apply' );

        $token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $parsed = $token ? get_transient( 'mop_customers_import_' . $token ) : null;
        if ( ! $parsed || ! is_array( $parsed ) ) {
            self::redirect_admin( MOP_Admin_Customers::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => 'token_expired' ] );
        }
        $result = MOP_Admin_Customers_Import::apply( $parsed );
        delete_transient( 'mop_customers_import_' . $token );

        self::redirect_admin( MOP_Admin_Customers::PAGE_SLUG, [
            'mop_notice'     => 'customers_imported',
            'mop_c_added'    => (int) $result['customers_added'],
            'mop_c_updated'  => (int) $result['customers_updated'],
            'mop_u_added'    => (int) $result['users_added'],
            'mop_b_added'    => (int) $result['bridges_added'],
            'mop_errored'    => (int) $result['errored'],
        ] );
    }

    /**
     * Export products as CSV in the same column layout the client uses in
     * the source spreadsheet (Order Import Item List). Admin-only metadata
     * (category, sort_order, base_uom, conversion_factor, site_id) is NOT
     * in this export — those are owned by our admin, not the import file.
     * Use the Edit Product UI for category/sort, or the order log to see
     * derived values.
     * Request: admin-post.php?action=mop_products_csv_export&_wpnonce=...
     */
    public static function mop_products_csv_export() {
        check_admin_referer( 'mop_products_csv_export' );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }

        $filename = 'matthews-products-' . gmdate( 'Ymd-His' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, MOP_Product::csv_columns() );
        foreach ( MOP_Product::all() as $p ) {
            $row = [
                self::csv_safe( $p['fmm_item_number'] ),
                self::csv_safe( $p['description'] ),
                self::csv_safe( $p['web_description'] ?? '' ),
                self::csv_safe( $p['category']        ?? '' ),
                self::csv_safe( $p['uom_schedule']    ?? '' ),
                self::csv_safe( $p['selling_uom'] ),
                ! empty( $p['requires_vfd'] )           ? 'VFD/AOD REQUIRED' : '',
                self::csv_safe( $p['minimum_order_qty'] ?? '' ),
                self::sold_individually_csv_value( $p['sold_individually'] ?? null ),
            ];
            fputcsv( $out, $row );
        }
        fclose( $out );
        exit;
    }

    private static function sold_individually_csv_value( $val ) {
        if ( $val === null || $val === '' ) {
            return '';
        }
        return ( (int) $val === 1 ) ? 'YES' : 'NO';
    }

    /**
     * Serve the bundled products-import-example.csv from the plugin /data dir.
     * Request: admin-post.php?action=mop_products_csv_example&_wpnonce=...
     */
    public static function mop_products_csv_example() {
        check_admin_referer( 'mop_products_csv_example' );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }

        $path = MOP_PLUGIN_DIR . 'data/products-import-example.csv';
        if ( ! file_exists( $path ) ) {
            wp_die( esc_html__( 'Example file not found.', 'matthewsorderplugin' ) );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="products-import-example.csv"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        readfile( $path );
        exit;
    }

    /**
     * Step 1 of product import: parse the uploaded CSV, validate every row,
     * partition into create / update / errors, stash in a transient,
     * redirect to the preview screen so the admin can confirm before any
     * database writes happen.
     *
     * Wipe mode: if $_POST['wipe_mode'] === '1', the apply step will
     * truncate mop_products first. The flag is carried through to the
     * preview transient so the preview can warn appropriately.
     */
    public static function mop_products_import_preview() {
        self::verify_admin( 'mop_products_import_preview' );

        if ( empty( $_FILES['csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) {
            self::redirect_admin( MOP_Admin_Products::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => 'no_file' ] );
        }
        if ( ! empty( $_FILES['csv_file']['error'] ) ) {
            self::redirect_admin( MOP_Admin_Products::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => 'upload_failed' ] );
        }

        $wipe   = ! empty( $_POST['wipe_mode'] );
        $parsed = self::parse_products_csv( $_FILES['csv_file']['tmp_name'] );
        if ( is_wp_error( $parsed ) ) {
            self::redirect_admin( MOP_Admin_Products::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => $parsed->get_error_code() ] );
        }
        $parsed['wipe_mode'] = $wipe;

        $token = wp_generate_password( 24, false, false );
        set_transient( 'mop_products_import_' . $token, $parsed, HOUR_IN_SECONDS );

        self::redirect_admin( MOP_Admin_Products::PAGE_SLUG, [ 'action' => 'import-preview', 'token' => $token ] );
    }

    /**
     * Step 2 of product import: read the transient, optionally wipe the
     * products table first, apply creates + updates. Updates preserve the
     * admin-controlled columns (category, sort_order, site_id) — only the
     * CSV-source columns plus the derived base_uom + conversion_factor are
     * written.
     */
    public static function mop_products_import_apply() {
        self::verify_admin( 'mop_products_import_apply' );

        $token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $parsed = $token ? get_transient( 'mop_products_import_' . $token ) : null;
        if ( ! $parsed || ! is_array( $parsed ) ) {
            self::redirect_admin( MOP_Admin_Products::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => 'token_expired' ] );
        }

        $wiped = 0;
        if ( ! empty( $parsed['wipe_mode'] ) ) {
            global $wpdb;
            $wiped = (int) $wpdb->query( 'DELETE FROM ' . MOP_Product::table() );
        }

        $created = 0;
        foreach ( $parsed['create'] as $entry ) {
            if ( MOP_Product::create( $entry['data'] ) ) {
                $created++;
            }
        }
        $updated = 0;
        foreach ( $parsed['update'] as $entry ) {
            // After a wipe, the row no longer exists — fall through to create.
            if ( ! empty( $parsed['wipe_mode'] ) ) {
                if ( MOP_Product::create( $entry['incoming'] ) ) {
                    $created++;
                }
                continue;
            }
            if ( MOP_Product::update( (int) $entry['id'], $entry['data'] ) ) {
                $updated++;
            }
        }

        delete_transient( 'mop_products_import_' . $token );

        self::redirect_admin( MOP_Admin_Products::PAGE_SLUG, [
            'mop_notice'           => 'products_imported',
            'mop_import_added'     => (int) $created,
            'mop_import_updated'   => (int) $updated,
            'mop_import_errored'   => count( $parsed['errors'] ),
            'mop_import_normalized'=> count( $parsed['notices'] ?? [] ),
            'mop_import_wiped'     => $wiped,
        ] );
    }

    /**
     * One-shot teardown of the placeholder seed data. Matches rows whose
     * fmm_item_number starts with one of the known seed prefixes (LIN-,
     * SUN-, MFG-, SR-, SHO- — see includes/data/products-seed.php) so we
     * don't accidentally nuke real products that happen to share part of a
     * name. Order history is preserved (orders snapshot product fields at
     * submit time; deleting a product never touches mop_order_lines).
     */
    public static function mop_products_wipe_seed() {
        self::verify_admin( 'mop_products_wipe_seed' );

        global $wpdb;
        $table  = MOP_Product::table();
        $deleted = (int) $wpdb->query(
            "DELETE FROM {$table} WHERE
                fmm_item_number LIKE 'LIN-%' OR
                fmm_item_number LIKE 'SUN-%' OR
                fmm_item_number LIKE 'MFG-%' OR
                fmm_item_number LIKE 'SR-%'  OR
                fmm_item_number LIKE 'SHO-%'"
        );

        self::redirect_admin( MOP_Admin_Products::PAGE_SLUG, [
            'mop_notice'        => 'seed_wiped',
            'mop_seed_deleted'  => $deleted,
        ] );
    }

    /**
     * Parse the products CSV upload. Returns:
     *   [
     *     'create'  => [ ['row' => N, 'data' => [...] ], ... ],
     *     'update'  => [ ['row' => N, 'id' => X, 'existing' => [...], 'data' => [...partial...], 'incoming' => [...full...]], ... ],
     *     'errors'  => [ ['row' => N, 'fmm_item_number' => '...', 'reason' => '...' ], ... ],
     *     'notices' => [ ['row' => N, 'fmm_item_number' => '...', 'note' => '...' ], ... ],
     *   ]
     *
     * Validation, per FMM ORDIMP reference Section 5/6:
     *   - fmm_item_number + fmm_description + uom_schedule + selling_uom required
     *   - uom_schedule prefix must be POUND or EACH (FMM only accepts those)
     *   - selling_uom must parse to a positive conversion factor
     *   - description capped at 50 (FMM Record 200 pos 5 max len) with notice
     *
     * Normalization (silent, but logged as a notice):
     *   - selling_uom upper-cased ("bag-50" → "BAG-50")
     *   - fmm_item_number upper-cased + trimmed
     */
    private static function parse_products_csv( $tmp_path ) {
        $fh = fopen( $tmp_path, 'r' );
        if ( ! $fh ) {
            return new WP_Error( 'read_failed', 'Could not read uploaded file.' );
        }
        $header = fgetcsv( $fh );
        if ( ! $header ) {
            fclose( $fh );
            return new WP_Error( 'empty_csv', 'CSV is empty.' );
        }
        if ( isset( $header[0] ) ) {
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
        }
        $header = array_map( function ( $h ) {
            return strtolower( trim( (string) $h ) );
        }, $header );

        $known   = array_flip( MOP_Product::csv_columns() );
        $col_map = [];
        foreach ( $header as $idx => $name ) {
            if ( isset( $known[ $name ] ) ) {
                $col_map[ $idx ] = $name;
            }
        }
        foreach ( [ 'fmm_item_number', 'fmm_description', 'uom_schedule', 'selling_uom' ] as $required ) {
            if ( ! in_array( $required, $col_map, true ) ) {
                fclose( $fh );
                return new WP_Error( 'missing_' . $required, 'CSV is missing required column: ' . $required );
            }
        }

        $create  = [];
        $update  = [];
        $errors  = [];
        $notices = [];
        $seen    = [];

        $row_num = 1;
        while ( ( $raw = fgetcsv( $fh ) ) !== false ) {
            $row_num++;
            if ( count( array_filter( $raw, function ( $v ) { return trim( (string) $v ) !== ''; } ) ) === 0 ) {
                continue;
            }
            $row = [];
            foreach ( $col_map as $idx => $col ) {
                $row[ $col ] = isset( $raw[ $idx ] ) ? trim( (string) $raw[ $idx ] ) : '';
            }

            $fmm_raw          = $row['fmm_item_number'] ?? '';
            $fmm              = MOP_Product::normalize_item_number( $fmm_raw );
            $fmm_description  = $row['fmm_description'] ?? '';
            $uom_schedule_raw = $row['uom_schedule'] ?? '';
            $uom_schedule     = strtoupper( $uom_schedule_raw );
            $selling_raw      = $row['selling_uom'] ?? '';
            $selling_uom      = strtoupper( $selling_raw );

            if ( $fmm === '' ) {
                $errors[] = [ 'row' => $row_num, 'fmm_item_number' => '', 'reason' => 'fmm_item_number is required' ];
                continue;
            }
            if ( $fmm_description === '' ) {
                $errors[] = [ 'row' => $row_num, 'fmm_item_number' => $fmm, 'reason' => 'fmm_description is required' ];
                continue;
            }
            if ( $uom_schedule === '' ) {
                $errors[] = [ 'row' => $row_num, 'fmm_item_number' => $fmm, 'reason' => 'uom_schedule is required' ];
                continue;
            }
            if ( $selling_uom === '' ) {
                $errors[] = [ 'row' => $row_num, 'fmm_item_number' => $fmm, 'reason' => 'selling_uom is required' ];
                continue;
            }
            if ( isset( $seen[ $fmm ] ) ) {
                $errors[] = [ 'row' => $row_num, 'fmm_item_number' => $fmm, 'reason' => 'duplicate fmm_item_number earlier in file (row ' . $seen[ $fmm ] . ')' ];
                continue;
            }

            $base_uom = MOP_Product::derive_base_uom( $uom_schedule );
            if ( $base_uom === null ) {
                $errors[] = [ 'row' => $row_num, 'fmm_item_number' => $fmm, 'reason' => 'uom_schedule "' . $uom_schedule . '" must be POUND, EACH, POUND-N, or EACH-N (where N is numeric). FMM only accepts POUND or EACH as base UoMs.' ];
                continue;
            }
            $factor = MOP_Product::derive_conversion_factor( $selling_uom );
            if ( $factor === null ) {
                $errors[] = [ 'row' => $row_num, 'fmm_item_number' => $fmm, 'reason' => 'cannot derive a conversion factor from selling_uom "' . $selling_uom . '" (expected POUND, EACH, or PREFIX-NUMBER like BAG-50)' ];
                continue;
            }

            // Notices for silent normalizations / truncations.
            if ( $selling_raw !== $selling_uom && trim( $selling_raw ) !== '' ) {
                $notices[] = [ 'row' => $row_num, 'fmm_item_number' => $fmm, 'note' => 'selling_uom case-normalized: "' . $selling_raw . '" → "' . $selling_uom . '"' ];
            }
            if ( strlen( $fmm_description ) > 50 ) {
                $notices[] = [ 'row' => $row_num, 'fmm_item_number' => $fmm, 'note' => 'fmm_description truncated to 50 chars (FMM limit)' ];
                $fmm_description = substr( $fmm_description, 0, 50 );
            }

            $vfd               = MOP_Product::parse_bool( $row['vfd_required']      ?? '' );
            $sold_indiv        = MOP_Product::parse_bool( $row['sold_individually'] ?? '' );
            $minimum_order_qty = self::cap( $row['minimum_order']                   ?? '', 40 );
            $web_description   = self::cap( $row['web_description']                 ?? '', 100 );
            $category          = self::cap( $row['category']                        ?? '', 100 );

            // CSV-sourced fields. sort_order + site_id stay admin-only and
            // are not included here, so MOP_Product::update() leaves them
            // alone on existing rows.
            $data = [
                'fmm_item_number'   => $fmm,
                'description'       => $fmm_description,
                'web_description'   => $web_description !== '' ? $web_description : null,
                'uom_schedule'      => $uom_schedule,
                'selling_uom'       => $selling_uom,
                'base_uom'          => $base_uom,
                'conversion_factor' => $factor,
                'requires_vfd'      => ( $vfd === 1 ) ? 1 : 0,
                'minimum_order_qty' => $minimum_order_qty !== '' ? $minimum_order_qty : null,
                'sold_individually' => ( $sold_indiv === null ) ? null : (int) $sold_indiv,
            ];

            // Category handling: only push it into $data when the CSV row
            // actually provides a value. A blank/missing category column
            // means "preserve whatever's already there" on updates and
            // "null" on creates — which falls out naturally from leaving
            // the key off the array.
            if ( $category !== '' ) {
                $data['category'] = $category;
            }

            $existing = MOP_Product::find_by_item_number( $fmm );
            if ( $existing ) {
                $update[] = [
                    'row'      => $row_num,
                    'id'       => (int) $existing['id'],
                    'existing' => $existing,
                    'data'     => $data,
                    'incoming' => array_merge( [ 'site_id' => $existing['site_id'] ?: MOP_Ordimp::DEFAULT_SITE_ID ], $data ),
                ];
            } else {
                $create[] = [ 'row' => $row_num, 'data' => $data ];
            }

            $seen[ $fmm ] = $row_num;
        }
        fclose( $fh );

        if ( empty( $create ) && empty( $update ) && empty( $errors ) ) {
            return new WP_Error( 'no_rows', 'CSV had no data rows.' );
        }
        return [ 'create' => $create, 'update' => $update, 'errors' => $errors, 'notices' => $notices ];
    }

    private static function cap( $val, $max ) {
        $val = sanitize_text_field( (string) $val );
        return $max ? substr( $val, 0, $max ) : $val;
    }

    private static function parse_bool( $val ) {
        $v = strtolower( trim( (string) $val ) );
        if ( $v === '' ) {
            return true; // default
        }
        return in_array( $v, [ '1', 'yes', 'y', 'true', 't', 'on', 'active' ], true );
    }

    /**
     * Defang CSV-injection-prone leading characters when exporting cells
     * that came from user input. See OWASP "Formula Injection".
     */
    private static function csv_safe( $val ) {
        if ( $val === '' ) {
            return $val;
        }
        $first = $val[0];
        if ( in_array( $first, [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
            return "'" . $val;
        }
        return $val;
    }

    /**
     * Delete a single order (DB rows + ORDIMP file). Nonce-protected by
     * the order id so a stale link can't accidentally delete the wrong row.
     * Request: admin-post.php?action=mop_delete_order&order_id=NN&_wpnonce=...
     */
    public static function mop_delete_order() {
        $order_id = isset( $_REQUEST['order_id'] ) ? (int) $_REQUEST['order_id'] : 0;
        check_admin_referer( 'mop_delete_order_' . $order_id );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }
        $existed = MOP_Order::delete( $order_id );
        self::redirect_admin( MOP_Admin_Orders::PAGE_SLUG, [
            'mop_notice' => $existed ? 'order_deleted' : 'order_not_found',
        ] );
    }

    /**
     * Delete every order. Gated by a typed "DELETE" confirmation pulled
     * from a POST field — the form is rendered by
     * MOP_Admin_Orders::render_delete_all_confirm() and submits here.
     * Request: admin-post.php?action=mop_delete_all_orders (POST only)
     */
    public static function mop_delete_all_orders() {
        self::verify_admin( 'mop_delete_all_orders' );

        $confirm = isset( $_POST['confirm_text'] ) ? trim( (string) wp_unslash( $_POST['confirm_text'] ) ) : '';
        if ( strtoupper( $confirm ) !== 'DELETE' ) {
            self::redirect_admin( MOP_Admin_Orders::PAGE_SLUG, [
                'action'     => 'delete-all-confirm',
                'mop_error'  => 'confirm_text_mismatch',
            ] );
        }
        $count = MOP_Order::delete_all();
        self::redirect_admin( MOP_Admin_Orders::PAGE_SLUG, [
            'mop_notice'             => 'all_orders_deleted',
            'mop_orders_deleted_n'   => (int) $count,
        ] );
    }

    private static function stream_ordimp_file( array $order ) {
        $path = (string) ( $order['ordimp_path'] ?? '' );
        if ( $path === '' || ! file_exists( $path ) ) {
            wp_die( esc_html__( 'ORDIMP.dat file not found for this order.', 'matthewsorderplugin' ) );
        }
        $filename = 'ORDIMP-' . $order['po_number'] . '.dat';

        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        readfile( $path );
        exit;
    }

    /* -------------------------------------------------------------------- */
    /* Admin screens                                                        */
    /* -------------------------------------------------------------------- */

    public static function mop_save_user() {
        self::verify_admin( 'mop_save_user' );

        $id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $is_new     = $id === 0;

        $email      = isset( $_POST['email'] )    ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password   = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] )       : '';
        $send_creds = ! empty( $_POST['send_credentials'] );
        $is_active  = ! empty( $_POST['is_active'] ) ? 1 : 0;

        if ( $email === '' ) {
            self::redirect_admin( 'mop_users', array_filter( [ 'action' => $is_new ? 'new' : 'edit', 'id' => $id ?: null, 'mop_error' => 'email_required' ] ) );
        }
        if ( $password !== '' && strlen( $password ) < 8 ) {
            self::redirect_admin( 'mop_users', array_filter( [ 'action' => $is_new ? 'new' : 'edit', 'id' => $id ?: null, 'mop_error' => 'password_too_short' ] ) );
        }

        $data = [
            'email'              => $email,
            'contact_first_name' => self::post_str( 'contact_first_name', 50 ),
            'contact_last_name'  => self::post_str( 'contact_last_name', 50 ),
            'is_active'          => $is_active,
        ];
        if ( $password !== '' ) {
            $data['password'] = $password;
        }

        if ( $is_new ) {
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

    public static function mop_save_customer() {
        self::verify_admin( 'mop_save_customer' );

        $id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $is_new = $id === 0;

        $customer_id = isset( $_POST['customer_id'] ) ? trim( (string) wp_unslash( $_POST['customer_id'] ) ) : '';
        if ( $is_new && $customer_id === '' ) {
            self::redirect_admin( 'mop_customers', [ 'action' => 'new', 'mop_error' => 'customer_id_required' ] );
        }

        $data = [
            'company_name'  => self::post_str( 'company_name', 64 ),
            'phone'         => self::post_str( 'phone', 30 ),
            'bill_to_line1' => self::post_str( 'bill_to_line1', 100 ),
            'bill_to_line2' => self::post_str( 'bill_to_line2', 100 ),
            'bill_to_city'  => self::post_str( 'bill_to_city', 50 ),
            'bill_to_state' => strtoupper( self::post_str( 'bill_to_state', 2 ) ),
            'bill_to_zip'   => self::post_str( 'bill_to_zip', 10 ),
            'ship_to_line1' => self::post_str( 'ship_to_line1', 100 ),
            'ship_to_line2' => self::post_str( 'ship_to_line2', 100 ),
            'ship_to_city'  => self::post_str( 'ship_to_city', 50 ),
            'ship_to_state' => strtoupper( self::post_str( 'ship_to_state', 2 ) ),
            'ship_to_zip'   => self::post_str( 'ship_to_zip', 10 ),
            'is_active'     => ! empty( $_POST['is_active'] ) ? 1 : 0,
        ];

        if ( $is_new ) {
            if ( MOP_Customer::find_by_customer_id( $customer_id ) ) {
                self::redirect_admin( 'mop_customers', [ 'action' => 'new', 'mop_error' => 'customer_id_in_use' ] );
            }
            $data['customer_id'] = $customer_id;
            $customer = MOP_Customer::create( $data );
            $notice = 'customer_created';
        } else {
            $existing = MOP_Customer::find( $id );
            if ( ! $existing ) {
                self::redirect_admin( 'mop_customers', [ 'mop_error' => 'not_found' ] );
            }
            $customer = MOP_Customer::update( $id, $data );
            $notice = 'customer_saved';
        }

        $cust_id = $customer ? (int) $customer['id'] : (int) $id;
        self::redirect_admin( 'mop_customers', [ 'action' => 'edit', 'id' => $cust_id, 'mop_notice' => $notice ] );
    }

    public static function mop_delete_customer() {
        $id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
        check_admin_referer( 'mop_delete_customer_' . $id );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }
        if ( $id ) {
            MOP_Customer::delete( $id );
        }
        self::redirect_admin( 'mop_customers', [ 'mop_notice' => 'customer_deleted' ] );
    }

    /**
     * Bridge attach — either user→customer or customer→user direction.
     * The "return" POST field controls where we redirect ('user' goes
     * back to the user edit page, 'customer' to the customer edit page).
     */
    public static function mop_attach_user_customer() {
        self::verify_admin( 'mop_attach_user_customer' );

        $return    = isset( $_POST['return'] ) ? sanitize_key( $_POST['return'] ) : 'customer';
        $user_id   = isset( $_POST['user_id'] )   ? (int) $_POST['user_id']   : 0;
        $cust_id   = isset( $_POST['customer_id'] ) ? (int) $_POST['customer_id'] : 0;

        // From the customer edit screen, the user is looked up by email.
        if ( $return === 'customer' ) {
            $email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
            $u = $email ? MOP_User::find_by_email( $email ) : null;
            if ( ! $u ) {
                self::redirect_admin( 'mop_customers', [ 'action' => 'edit', 'id' => $cust_id, 'mop_error' => 'user_not_found' ] );
            }
            $user_id = (int) $u['id'];
        }
        // From the user edit screen, the customer is looked up by FMM customer_id.
        if ( $return === 'user' && empty( $cust_id ) ) {
            $cid_text = isset( $_POST['customer_id_text'] ) ? trim( (string) wp_unslash( $_POST['customer_id_text'] ) ) : '';
            $c = $cid_text ? MOP_Customer::find_by_customer_id( $cid_text ) : null;
            if ( ! $c ) {
                self::redirect_admin( 'mop_users', [ 'action' => 'edit', 'id' => $user_id, 'mop_error' => 'customer_not_found' ] );
            }
            $cust_id = (int) $c['id'];
        }

        MOP_UserCustomer::attach( $user_id, $cust_id );
        if ( $return === 'user' ) {
            self::redirect_admin( 'mop_users', [ 'action' => 'edit', 'id' => $user_id, 'mop_notice' => 'user_attached' ] );
        }
        self::redirect_admin( 'mop_customers', [ 'action' => 'edit', 'id' => $cust_id, 'mop_notice' => 'user_attached' ] );
    }

    public static function mop_detach_user_customer() {
        $user_id = isset( $_REQUEST['user_id'] )     ? (int) $_REQUEST['user_id']     : 0;
        $cust_id = isset( $_REQUEST['customer_id'] ) ? (int) $_REQUEST['customer_id'] : 0;
        $return  = isset( $_REQUEST['return'] )      ? sanitize_key( $_REQUEST['return'] ) : 'customer';
        check_admin_referer( 'mop_detach_user_customer_' . $user_id . '_' . $cust_id );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }
        MOP_UserCustomer::detach( $user_id, $cust_id );
        if ( $return === 'user' ) {
            self::redirect_admin( 'mop_users', [ 'action' => 'edit', 'id' => $user_id, 'mop_notice' => 'user_detached' ] );
        }
        self::redirect_admin( 'mop_customers', [ 'action' => 'edit', 'id' => $cust_id, 'mop_notice' => 'user_detached' ] );
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

        $sold = $_POST['sold_individually'] ?? '';
        if ( $sold === '' || $sold === '— Not set —' ) {
            $sold_value = null;
        } else {
            $sold_value = ( (string) $sold === '1' ) ? 1 : 0;
        }

        $data = [
            'fmm_item_number'   => $item_number,
            'description'       => $description,
            'web_description'   => self::post_str( 'web_description', 100 ) ?: null,
            'category'          => self::post_str( 'category', 100 ),
            'sort_order'        => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
            'uom_schedule'      => strtoupper( self::post_str( 'uom_schedule', 20 ) ) ?: null,
            'selling_uom'       => strtoupper( self::post_str( 'selling_uom', 20 ) ),
            'base_uom'          => in_array( ( $_POST['base_uom'] ?? '' ), [ 'POUND', 'EACH' ], true ) ? $_POST['base_uom'] : 'POUND',
            'conversion_factor' => isset( $_POST['conversion_factor'] ) ? (float) $_POST['conversion_factor'] : 1.0,
            'requires_vfd'      => ! empty( $_POST['requires_vfd'] ) ? 1 : 0,
            'minimum_order_qty' => self::post_str( 'minimum_order_qty', 40 ) ?: null,
            'sold_individually' => $sold_value,
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
