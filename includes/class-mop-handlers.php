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
        $public_actions = [ 'mop_login', 'mop_logout', 'mop_request_reset', 'mop_reset_password', 'mop_save_account', 'mop_submit_order' ];
        foreach ( $public_actions as $action ) {
            add_action( 'admin_post_' . $action,        [ __CLASS__, $action ] );
            add_action( 'admin_post_nopriv_' . $action, [ __CLASS__, $action ] );
        }

        $admin_actions = [
            'mop_save_user', 'mop_delete_user', 'mop_save_product', 'mop_delete_product',
            'mop_admin_download_ordimp', 'mop_orders_csv',
            'mop_users_csv_export', 'mop_users_csv_example',
            'mop_users_import_preview', 'mop_users_import_apply',
        ];
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

        $current = MOP_Auth::current_user();
        if ( ! $current ) {
            self::redirect_with( 'login', [ 'mop_error' => 'not_logged_in' ] );
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

        // Persist any account edits the customer made on the form.
        $updated_user = self::apply_account_edits_from_post( $current );
        if ( is_wp_error( $updated_user ) ) {
            self::redirect_with( 'create-order', [ 'mop_error' => $updated_user->get_error_code() ] );
        }
        $user = $updated_user ?: $current;

        // Build the order header snapshot.
        $header = array_merge(
            MOP_Order::snapshot_from_user( $user ),
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

        MOP_Email::order_notification( $user, $order, $line_rows );
        MOP_Email::order_submission(  $user, $order, $line_rows, $ordimp_path );

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
    private static function apply_account_edits_from_post( array $current ) {
        if ( ! isset( $_POST['email'] ) ) {
            return null;
        }

        $id    = (int) $current['id'];
        $email = sanitize_email( wp_unslash( $_POST['email'] ) );

        if ( $email === '' ) {
            return new WP_Error( 'email_required', 'Email is required.' );
        }
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'email_invalid', 'Email is not valid.' );
        }
        $existing_email = MOP_User::find_by_email( $email );
        if ( $existing_email && (int) $existing_email['id'] !== $id ) {
            return new WP_Error( 'email_in_use', 'Email is already in use.' );
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
        return MOP_User::update( $id, $data );
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
     * Export all users as CSV. Columns are MOP_User::csv_columns(), in order.
     * Password fields are intentionally never exported.
     * Request: admin-post.php?action=mop_users_csv_export&_wpnonce=...
     */
    public static function mop_users_csv_export() {
        check_admin_referer( 'mop_users_csv_export' );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }

        $columns  = MOP_User::csv_columns();
        $filename = 'matthews-users-' . gmdate( 'Ymd-His' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, $columns );
        foreach ( MOP_User::all() as $user ) {
            $row = [];
            foreach ( $columns as $col ) {
                if ( $col === 'password' ) {
                    $row[] = ''; // never expose hashes or plaintext via export
                    continue;
                }
                $val = isset( $user[ $col ] ) ? (string) $user[ $col ] : '';
                $row[] = self::csv_safe( $val );
            }
            fputcsv( $out, $row );
        }
        fclose( $out );
        exit;
    }

    /**
     * Stream the bundled users-import-example.csv from the plugin /data dir.
     * Served through admin-post so it gets the same capability gate as the
     * real export (no reason to hand non-admins a template either).
     * Request: admin-post.php?action=mop_users_csv_example&_wpnonce=...
     */
    public static function mop_users_csv_example() {
        check_admin_referer( 'mop_users_csv_example' );
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'Forbidden', 'matthewsorderplugin' ) );
        }

        $path = MOP_PLUGIN_DIR . 'data/users-import-example.csv';
        if ( ! file_exists( $path ) ) {
            wp_die( esc_html__( 'Example file not found.', 'matthewsorderplugin' ) );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="users-import-example.csv"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        readfile( $path );
        exit;
    }

    /**
     * Step 1 of import: receive uploaded CSV, validate every row, stash the
     * parsed result in a transient, redirect to the preview screen so the
     * admin can confirm before we touch the database.
     */
    public static function mop_users_import_preview() {
        self::verify_admin( 'mop_users_import_preview' );

        if ( empty( $_FILES['csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) {
            self::redirect_admin( MOP_Admin_Users::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => 'no_file' ] );
        }
        if ( ! empty( $_FILES['csv_file']['error'] ) ) {
            self::redirect_admin( MOP_Admin_Users::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => 'upload_failed' ] );
        }

        $parsed = self::parse_users_csv( $_FILES['csv_file']['tmp_name'] );
        if ( is_wp_error( $parsed ) ) {
            self::redirect_admin( MOP_Admin_Users::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => $parsed->get_error_code() ] );
        }

        $token = wp_generate_password( 24, false, false );
        set_transient( 'mop_users_import_' . $token, $parsed, HOUR_IN_SECONDS );

        self::redirect_admin( MOP_Admin_Users::PAGE_SLUG, [ 'action' => 'import-preview', 'token' => $token ] );
    }

    /**
     * Step 2 of import: pull the parsed result out of the transient, apply
     * inserts + updates by customer_id. Updates overwrite ALL of the rowed
     * columns by design — that's what the preview warned the admin about.
     */
    public static function mop_users_import_apply() {
        self::verify_admin( 'mop_users_import_apply' );

        $token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $parsed = $token ? get_transient( 'mop_users_import_' . $token ) : null;
        if ( ! $parsed || ! is_array( $parsed ) ) {
            self::redirect_admin( MOP_Admin_Users::PAGE_SLUG, [ 'action' => 'import', 'mop_error' => 'token_expired' ] );
        }

        $created = 0;
        foreach ( $parsed['create'] as $entry ) {
            if ( MOP_User::create( $entry['data'] ) ) {
                $created++;
            }
        }
        $updated = 0;
        foreach ( $parsed['update'] as $entry ) {
            if ( MOP_User::update( (int) $entry['id'], $entry['data'] ) ) {
                $updated++;
            }
        }

        delete_transient( 'mop_users_import_' . $token );

        self::redirect_admin( MOP_Admin_Users::PAGE_SLUG, [
            'mop_notice'        => 'users_imported',
            'mop_import_added'  => (int) $created,
            'mop_import_updated' => (int) $updated,
            'mop_import_errored' => count( $parsed['errors'] ),
        ] );
    }

    /**
     * Read a CSV upload, validate, return a structured plan:
     *   [
     *     'create' => [ ['data' => [...] ], ... ],
     *     'update' => [ ['id' => N, 'existing' => [...], 'data' => [...] ], ... ],
     *     'errors' => [ ['row' => N, 'customer_id' => '...', 'reason' => '...' ], ... ],
     *   ]
     *
     * Validation: required customer_id + email, valid email format, no
     * duplicate customer_id within the file, no email collision with a
     * different customer_id (existing or in-file). Unknown columns ignored;
     * missing optional columns treated as blank. is_active accepts
     * 1/0/yes/no/true/false (case-insensitive).
     */
    private static function parse_users_csv( $tmp_path ) {
        $fh = fopen( $tmp_path, 'r' );
        if ( ! $fh ) {
            return new WP_Error( 'read_failed', 'Could not read uploaded file.' );
        }

        $header = fgetcsv( $fh );
        if ( ! $header ) {
            fclose( $fh );
            return new WP_Error( 'empty_csv', 'CSV is empty.' );
        }

        // Strip UTF-8 BOM from the first cell, normalize header cells.
        if ( isset( $header[0] ) ) {
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
        }
        $header = array_map( function ( $h ) {
            return strtolower( trim( (string) $h ) );
        }, $header );

        $known   = array_flip( MOP_User::csv_columns() );
        $col_map = []; // header_index => canonical column name
        foreach ( $header as $idx => $name ) {
            if ( isset( $known[ $name ] ) ) {
                $col_map[ $idx ] = $name;
            }
        }
        if ( ! in_array( 'customer_id', $col_map, true ) ) {
            fclose( $fh );
            return new WP_Error( 'missing_customer_id', 'CSV must include a customer_id column.' );
        }
        if ( ! in_array( 'email', $col_map, true ) ) {
            fclose( $fh );
            return new WP_Error( 'missing_email', 'CSV must include an email column.' );
        }

        $create = [];
        $update = [];
        $errors = [];
        $seen_customer_ids = []; // dedupe within file
        $seen_emails       = []; // dedupe within file (lowercased)

        $row_num = 1; // header was row 1
        while ( ( $raw = fgetcsv( $fh ) ) !== false ) {
            $row_num++;
            // Skip totally blank lines.
            if ( count( array_filter( $raw, function ( $v ) { return trim( (string) $v ) !== ''; } ) ) === 0 ) {
                continue;
            }

            $row = [];
            foreach ( $col_map as $idx => $col ) {
                $row[ $col ] = isset( $raw[ $idx ] ) ? trim( (string) $raw[ $idx ] ) : '';
            }

            $customer_id = isset( $row['customer_id'] ) ? substr( $row['customer_id'], 0, 15 ) : '';
            $email       = isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '';
            $password    = isset( $row['password'] ) ? (string) $row['password'] : '';

            if ( $customer_id === '' ) {
                $errors[] = [ 'row' => $row_num, 'customer_id' => '', 'reason' => 'customer_id is required' ];
                continue;
            }
            if ( $email === '' ) {
                $errors[] = [ 'row' => $row_num, 'customer_id' => $customer_id, 'reason' => 'email is required' ];
                continue;
            }
            if ( ! is_email( $email ) ) {
                $errors[] = [ 'row' => $row_num, 'customer_id' => $customer_id, 'reason' => 'email is not valid' ];
                continue;
            }
            if ( $password !== '' && strlen( $password ) < 8 ) {
                $errors[] = [ 'row' => $row_num, 'customer_id' => $customer_id, 'reason' => 'password must be at least 8 characters' ];
                continue;
            }
            if ( isset( $seen_customer_ids[ $customer_id ] ) ) {
                $errors[] = [ 'row' => $row_num, 'customer_id' => $customer_id, 'reason' => 'duplicate customer_id earlier in file (row ' . $seen_customer_ids[ $customer_id ] . ')' ];
                continue;
            }
            $email_lc = strtolower( $email );
            if ( isset( $seen_emails[ $email_lc ] ) && $seen_emails[ $email_lc ]['customer_id'] !== $customer_id ) {
                $errors[] = [ 'row' => $row_num, 'customer_id' => $customer_id, 'reason' => 'email already used by another row in file (row ' . $seen_emails[ $email_lc ]['row'] . ')' ];
                continue;
            }

            // Email collision against the database (different customer_id).
            $email_owner = MOP_User::find_by_email( $email );
            if ( $email_owner && $email_owner['customer_id'] !== $customer_id ) {
                $errors[] = [ 'row' => $row_num, 'customer_id' => $customer_id, 'reason' => 'email is already used by another user (' . $email_owner['customer_id'] . ')' ];
                continue;
            }

            $data = [
                'customer_id'        => $customer_id,
                'email'              => $email,
                'company_name'       => self::cap( $row['company_name']       ?? '', 64 ),
                'contact_first_name' => self::cap( $row['contact_first_name'] ?? '', 50 ),
                'contact_last_name'  => self::cap( $row['contact_last_name']  ?? '', 50 ),
                'bill_to_line1'      => self::cap( $row['bill_to_line1']      ?? '', 100 ),
                'bill_to_line2'      => self::cap( $row['bill_to_line2']      ?? '', 100 ),
                'bill_to_city'       => self::cap( $row['bill_to_city']       ?? '', 50 ),
                'bill_to_state'      => strtoupper( self::cap( $row['bill_to_state'] ?? '', 2 ) ),
                'bill_to_zip'        => self::cap( $row['bill_to_zip']        ?? '', 10 ),
                'ship_to_line1'      => self::cap( $row['ship_to_line1']      ?? '', 100 ),
                'ship_to_line2'      => self::cap( $row['ship_to_line2']      ?? '', 100 ),
                'ship_to_city'       => self::cap( $row['ship_to_city']       ?? '', 50 ),
                'ship_to_state'      => strtoupper( self::cap( $row['ship_to_state'] ?? '', 2 ) ),
                'ship_to_zip'        => self::cap( $row['ship_to_zip']        ?? '', 10 ),
                'is_active'          => self::parse_bool( $row['is_active'] ?? '1' ) ? 1 : 0,
            ];

            // Hash plaintext password at parse time so the preview transient
            // never holds a plaintext copy. MOP_User::create()/update() pass
            // password_hash through verbatim when present.
            if ( $password !== '' ) {
                $data['password_hash'] = wp_hash_password( $password );
            }
            $set_password = $password !== '';

            $existing = MOP_User::find_by_customer_id( $customer_id );
            if ( $existing ) {
                // Don't re-set customer_id on update — it's the lookup key.
                $update_data = $data;
                unset( $update_data['customer_id'] );
                $update[] = [
                    'id'           => (int) $existing['id'],
                    'existing'     => $existing,
                    'data'         => $update_data,
                    'incoming'     => $data,
                    'set_password' => $set_password,
                ];
            } else {
                $create[] = [ 'data' => $data, 'set_password' => $set_password ];
            }

            $seen_customer_ids[ $customer_id ] = $row_num;
            $seen_emails[ $email_lc ]          = [ 'row' => $row_num, 'customer_id' => $customer_id ];
        }
        fclose( $fh );

        if ( empty( $create ) && empty( $update ) && empty( $errors ) ) {
            return new WP_Error( 'no_rows', 'CSV had no data rows.' );
        }

        return [ 'create' => $create, 'update' => $update, 'errors' => $errors ];
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
