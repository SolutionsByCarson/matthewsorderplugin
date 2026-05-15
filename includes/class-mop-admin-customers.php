<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin → Matthews Orders → Customers.
 *
 * One row per FMM Customer Number. Holds company name, billing/shipping
 * addresses, phone. Users (login accounts) attach via the
 * mop_user_customers bridge.
 *
 * Bulk CSV import / export of customers (with up to 5 user slots per row)
 * is provided here too. The standalone Users CSV import is retired.
 */
class MOP_Admin_Customers {

    const PAGE_SLUG = 'mop_customers';

    public static function render() {
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this screen.', 'matthewsorderplugin' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        if ( $action === 'new' || $action === 'edit' ) {
            self::render_form();
            return;
        }
        if ( $action === 'import' ) {
            self::render_import();
            return;
        }
        if ( $action === 'import-preview' ) {
            self::render_import_preview();
            return;
        }
        self::render_list();
    }

    private static function render_list() {
        $customers   = MOP_Customer::all();
        $new_url     = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
        $import_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=import' );
        $export_url  = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_customers_csv_export' ], admin_url( 'admin-post.php' ) ),
            'mop_customers_csv_export'
        );
        $example_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_customers_csv_example' ], admin_url( 'admin-post.php' ) ),
            'mop_customers_csv_example'
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Customers', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $new_url ); ?>"     class="page-title-action"><?php esc_html_e( 'Add New', 'matthewsorderplugin' ); ?></a>
            <a href="<?php echo esc_url( $import_url ); ?>"  class="page-title-action"><?php esc_html_e( 'Import CSV', 'matthewsorderplugin' ); ?></a>
            <a href="<?php echo esc_url( $export_url ); ?>"  class="page-title-action"><?php esc_html_e( 'Export CSV', 'matthewsorderplugin' ); ?></a>
            <a href="<?php echo esc_url( $example_url ); ?>" class="page-title-action"><?php esc_html_e( 'Download example CSV', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <?php self::render_notices(); ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:160px;"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Company', 'matthewsorderplugin' ); ?></th>
                        <th style="width:200px;"><?php esc_html_e( 'City / State', 'matthewsorderplugin' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Users', 'matthewsorderplugin' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Active', 'matthewsorderplugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $customers ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No customers yet. Click "Add New" to create one, or use Import CSV to bulk-load.', 'matthewsorderplugin' ); ?></td></tr>
                <?php endif; ?>

                <?php foreach ( $customers as $c ) :
                    $edit_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . (int) $c['id'] );
                    $delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=mop_delete_customer&id=' . (int) $c['id'] ), 'mop_delete_customer_' . (int) $c['id'] );
                    $user_count = count( MOP_UserCustomer::users_for_customer( (int) $c['id'] ) );
                    $city_state = trim( ( $c['bill_to_city'] ?? '' ) . ( ! empty( $c['bill_to_state'] ) ? ', ' . $c['bill_to_state'] : '' ), ', ' );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $c['customer_id'] ); ?></strong>
                            <div class="row-actions">
                                <span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'matthewsorderplugin' ); ?></a> | </span>
                                <span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this customer? Attached user bridges will be removed; users themselves are kept.', 'matthewsorderplugin' ) ); ?>');"><?php esc_html_e( 'Delete', 'matthewsorderplugin' ); ?></a></span>
                            </div>
                        </td>
                        <td><?php echo esc_html( $c['company_name'] ?: '—' ); ?></td>
                        <td><?php echo esc_html( $city_state ?: '—' ); ?></td>
                        <td><?php echo (int) $user_count; ?></td>
                        <td><?php echo $c['is_active'] ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-no-alt"></span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_form() {
        $id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $customer = $id ? MOP_Customer::find( $id ) : null;
        $is_new   = ! $customer;

        $v = function ( $key, $default = '' ) use ( $customer ) {
            if ( $customer && isset( $customer[ $key ] ) && $customer[ $key ] !== null ) {
                return $customer[ $key ];
            }
            return $default;
        };

        // Users attached to this customer
        $attached_users = $customer ? MOP_UserCustomer::users_for_customer( (int) $customer['id'] ) : [];
        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? esc_html__( 'Add Customer', 'matthewsorderplugin' ) : esc_html__( 'Edit Customer', 'matthewsorderplugin' ); ?></h1>

            <?php self::render_notices(); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mop_save_customer">
                <input type="hidden" name="id"     value="<?php echo esc_attr( $id ); ?>">
                <?php wp_nonce_field( 'mop_save_customer' ); ?>

                <h2><?php esc_html_e( 'Identity', 'matthewsorderplugin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="customer_id"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?> <span class="description">(required)</span></label></th>
                        <td>
                            <input name="customer_id" id="customer_id" type="text" maxlength="15" class="regular-text"
                                value="<?php echo esc_attr( $v( 'customer_id' ) ); ?>"
                                <?php echo $is_new ? 'required' : 'readonly'; ?>>
                            <p class="description"><?php esc_html_e( 'FMM Customer ID — must match FMM exactly. Max 15 chars. Cannot be changed after creation.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="company_name"><?php esc_html_e( 'Company name', 'matthewsorderplugin' ); ?></label></th>
                        <td><input name="company_name" id="company_name" type="text" maxlength="64" class="regular-text" value="<?php echo esc_attr( $v( 'company_name' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="phone"><?php esc_html_e( 'Phone', 'matthewsorderplugin' ); ?></label></th>
                        <td><input name="phone" id="phone" type="text" maxlength="30" class="regular-text" value="<?php echo esc_attr( $v( 'phone' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Active', 'matthewsorderplugin' ); ?></th>
                        <td><label><input type="checkbox" name="is_active" value="1" <?php checked( $v( 'is_active', 1 ) ); ?>> <?php esc_html_e( 'Customer is active', 'matthewsorderplugin' ); ?></label></td>
                    </tr>
                </table>

                <?php self::render_address_section( 'bill_to', __( 'Billing address', 'matthewsorderplugin' ), $v ); ?>
                <?php self::render_address_section( 'ship_to', __( 'Shipping address', 'matthewsorderplugin' ), $v ); ?>

                <?php submit_button( $is_new ? __( 'Create customer', 'matthewsorderplugin' ) : __( 'Save customer', 'matthewsorderplugin' ) ); ?>
            </form>

            <?php if ( ! $is_new ) : ?>
                <hr style="margin:2rem 0;">
                <h2><?php esc_html_e( 'Attached users', 'matthewsorderplugin' ); ?></h2>
                <p class="description"><?php esc_html_e( 'These login accounts can place orders billed to this customer.', 'matthewsorderplugin' ); ?></p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Name', 'matthewsorderplugin' ); ?></th>
                            <th style="width:140px;"><?php esc_html_e( 'Default', 'matthewsorderplugin' ); ?></th>
                            <th style="width:100px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $attached_users ) ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No users attached yet.', 'matthewsorderplugin' ); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ( $attached_users as $u ) :
                        $detach_url = wp_nonce_url(
                            add_query_arg( [
                                'action'      => 'mop_detach_user_customer',
                                'user_id'     => (int) $u['id'],
                                'customer_id' => (int) $customer['id'],
                                'return'      => 'customer',
                            ], admin_url( 'admin-post.php' ) ),
                            'mop_detach_user_customer_' . (int) $u['id'] . '_' . (int) $customer['id']
                        );
                        $user_edit = admin_url( 'admin.php?page=mop_users&action=edit&id=' . (int) $u['id'] );
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $user_edit ); ?>"><?php echo esc_html( $u['email'] ); ?></a></td>
                            <td><?php echo esc_html( MOP_User::full_name( $u ) ); ?></td>
                            <td><?php echo ! empty( $u['is_default'] ) ? '<span class="dashicons dashicons-yes"></span>' : ''; ?></td>
                            <td><a href="<?php echo esc_url( $detach_url ); ?>" style="color:#a00;" onclick="return confirm('<?php echo esc_js( __( 'Detach this user from this customer? The user account is kept.', 'matthewsorderplugin' ) ); ?>');"><?php esc_html_e( 'Detach', 'matthewsorderplugin' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h3><?php esc_html_e( 'Attach a user', 'matthewsorderplugin' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1rem;">
                    <input type="hidden" name="action"      value="mop_attach_user_customer">
                    <input type="hidden" name="customer_id" value="<?php echo (int) $customer['id']; ?>">
                    <input type="hidden" name="return"      value="customer">
                    <?php wp_nonce_field( 'mop_attach_user_customer' ); ?>
                    <input name="user_email" type="email" class="regular-text" placeholder="<?php esc_attr_e( 'user@example.com', 'matthewsorderplugin' ); ?>" required>
                    <button type="submit" class="button"><?php esc_html_e( 'Attach by email', 'matthewsorderplugin' ); ?></button>
                    <p class="description"><?php esc_html_e( 'Looks up an existing user by email. If multiple users share that address, the first match is attached — use Edit User to clean up.', 'matthewsorderplugin' ); ?></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_address_section( $prefix, $heading, $v ) {
        ?>
        <h2><?php echo esc_html( $heading ); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Line 1', 'matthewsorderplugin' ); ?></th>
                <td><input name="<?php echo esc_attr( $prefix . '_line1' ); ?>" type="text" maxlength="100" class="regular-text" value="<?php echo esc_attr( $v( $prefix . '_line1' ) ); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Line 2', 'matthewsorderplugin' ); ?></th>
                <td><input name="<?php echo esc_attr( $prefix . '_line2' ); ?>" type="text" maxlength="100" class="regular-text" value="<?php echo esc_attr( $v( $prefix . '_line2' ) ); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'City / State / ZIP', 'matthewsorderplugin' ); ?></th>
                <td>
                    <input name="<?php echo esc_attr( $prefix . '_city' ); ?>"  type="text" maxlength="50" placeholder="<?php esc_attr_e( 'City', 'matthewsorderplugin' ); ?>"  value="<?php echo esc_attr( $v( $prefix . '_city' ) ); ?>">
                    <input name="<?php echo esc_attr( $prefix . '_state' ); ?>" type="text" maxlength="2"  placeholder="<?php esc_attr_e( 'ST', 'matthewsorderplugin' ); ?>"    value="<?php echo esc_attr( $v( $prefix . '_state' ) ); ?>" style="width:60px;">
                    <input name="<?php echo esc_attr( $prefix . '_zip' ); ?>"   type="text" maxlength="10" placeholder="<?php esc_attr_e( 'ZIP', 'matthewsorderplugin' ); ?>"   value="<?php echo esc_attr( $v( $prefix . '_zip' ) ); ?>" style="width:100px;">
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * The CSV import + preview views are defined in MOP_Admin_Customers_Import
     * to keep this file focused on the CRUD UI.
     */
    private static function render_import() {
        MOP_Admin_Customers_Import::render_upload();
    }

    private static function render_import_preview() {
        MOP_Admin_Customers_Import::render_preview();
    }

    private static function render_notices() {
        $notice = isset( $_GET['mop_notice'] ) ? sanitize_key( $_GET['mop_notice'] ) : '';
        $error  = isset( $_GET['mop_error'] )  ? sanitize_key( $_GET['mop_error'] )  : '';
        $notices = [
            'customer_created'        => __( 'Customer created.', 'matthewsorderplugin' ),
            'customer_saved'          => __( 'Customer saved.', 'matthewsorderplugin' ),
            'customer_deleted'        => __( 'Customer deleted.', 'matthewsorderplugin' ),
            'user_attached'           => __( 'User attached to this customer.', 'matthewsorderplugin' ),
            'user_detached'           => __( 'User detached from this customer.', 'matthewsorderplugin' ),
        ];
        $errors = [
            'customer_id_required'    => __( 'Customer ID is required.', 'matthewsorderplugin' ),
            'customer_id_in_use'      => __( 'Another customer already has that Customer ID.', 'matthewsorderplugin' ),
            'not_found'               => __( 'Customer not found.', 'matthewsorderplugin' ),
            'user_not_found'          => __( 'No user found with that email.', 'matthewsorderplugin' ),
            'no_file'                 => __( 'Please choose a CSV file to upload.', 'matthewsorderplugin' ),
            'upload_failed'           => __( 'The file upload failed. Please try again.', 'matthewsorderplugin' ),
            'read_failed'             => __( 'Could not read the uploaded file.', 'matthewsorderplugin' ),
            'empty_csv'               => __( 'That CSV is empty.', 'matthewsorderplugin' ),
            'no_rows'                 => __( 'That CSV had no data rows.', 'matthewsorderplugin' ),
            'missing_customer_id'     => __( 'CSV is missing the required customer_id column.', 'matthewsorderplugin' ),
            'missing_company_name'    => __( 'CSV is missing the required company_name column.', 'matthewsorderplugin' ),
            'token_expired'           => __( 'That import session has expired. Please re-upload.', 'matthewsorderplugin' ),
        ];

        if ( $notice === 'customers_imported' ) {
            $c_added = isset( $_GET['mop_c_added'] )   ? (int) $_GET['mop_c_added']   : 0;
            $c_upd   = isset( $_GET['mop_c_updated'] ) ? (int) $_GET['mop_c_updated'] : 0;
            $u_added = isset( $_GET['mop_u_added'] )   ? (int) $_GET['mop_u_added']   : 0;
            $b_added = isset( $_GET['mop_b_added'] )   ? (int) $_GET['mop_b_added']   : 0;
            $errored = isset( $_GET['mop_errored'] )   ? (int) $_GET['mop_errored']   : 0;
            $msg = sprintf(
                __( 'Import complete: %1$d new customers, %2$d updated, %3$d new users, %4$d new bridges, %5$d skipped.', 'matthewsorderplugin' ),
                $c_added, $c_upd, $u_added, $b_added, $errored
            );
            echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
            return;
        }

        if ( $notice && isset( $notices[ $notice ] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
        }
        if ( $error && isset( $errors[ $error ] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
        }
    }
}
