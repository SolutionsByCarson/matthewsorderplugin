<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin → Matthews Orders → Users.
 *
 * Login accounts. Customer-identity fields (FMM number, company,
 * addresses) moved to mop_customers as of DB v0.6.0; users attach to
 * customers via the bridge.
 *
 * Bulk CSV import lives on the Customers admin (one CSV that handles
 * both entities + the bridge). The standalone Users CSV import has been
 * retired; admins do one-off user work here.
 */
class MOP_Admin_Users {

    const PAGE_SLUG = 'mop_users';

    public static function render() {
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this screen.', 'matthewsorderplugin' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        if ( $action === 'new' || $action === 'edit' ) {
            self::render_form();
            return;
        }
        self::render_list();
    }

    private static function render_list() {
        $users          = MOP_User::all();
        $new_url        = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
        $customers_url  = admin_url( 'admin.php?page=mop_customers' );
        ?>
        <div class="wrap">
            <?php echo MOP_Admin::back_to_dashboard_link(); ?>
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Users', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <?php self::render_notices(); ?>

            <?php MOP_Admin::render_table_filter( '.wp-list-table tbody tr', '', __( 'Filter by email, name, or attached customer…', 'matthewsorderplugin' ) ); ?>

            <p class="description">
                <?php
                printf(
                    /* translators: %s: link to customers admin */
                    esc_html__( 'Bulk-import users alongside customers from the %s.', 'matthewsorderplugin' ),
                    '<a href="' . esc_url( $customers_url ) . '">' . esc_html__( 'Customers screen', 'matthewsorderplugin' ) . '</a>'
                );
                ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Attached customers', 'matthewsorderplugin' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Active', 'matthewsorderplugin' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( 'Last login', 'matthewsorderplugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $users ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No users yet. Click "Add New" to create one.', 'matthewsorderplugin' ); ?></td></tr>
                <?php endif; ?>

                <?php foreach ( $users as $user ) :
                    $edit_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . (int) $user['id'] );
                    $delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=mop_delete_user&id=' . (int) $user['id'] ), 'mop_delete_user_' . (int) $user['id'] );
                    $attached   = MOP_UserCustomer::customers_for_user( (int) $user['id'] );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $user['email'] ); ?></strong>
                            <div class="row-actions">
                                <span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'matthewsorderplugin' ); ?></a> | </span>
                                <span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this user? Their attachments to customers will be removed. Customer records and order history are kept.', 'matthewsorderplugin' ) ); ?>');"><?php esc_html_e( 'Delete', 'matthewsorderplugin' ); ?></a></span>
                            </div>
                        </td>
                        <td><?php echo esc_html( MOP_User::full_name( $user ) ); ?></td>
                        <td>
                            <?php
                            if ( empty( $attached ) ) {
                                echo '<span class="description">' . esc_html__( '— none —', 'matthewsorderplugin' ) . '</span>';
                            } else {
                                $labels = array_map( function ( $c ) {
                                    return esc_html( MOP_Customer::display_name( $c ) );
                                }, $attached );
                                echo implode( ', ', $labels );
                            }
                            ?>
                        </td>
                        <td><?php echo $user['is_active'] ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-no-alt"></span>'; ?></td>
                        <td><?php echo esc_html( $user['last_login_at'] ? mysql2date( 'Y-m-d H:i', $user['last_login_at'] ) : '—' ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_form() {
        $id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $user   = $id ? MOP_User::find( $id ) : null;
        $is_new = ! $user;

        $v = function ( $key, $default = '' ) use ( $user ) {
            if ( $user && isset( $user[ $key ] ) && $user[ $key ] !== null ) {
                return $user[ $key ];
            }
            return $default;
        };

        $attached_customers = $user ? MOP_UserCustomer::customers_for_user( (int) $user['id'] ) : [];
        ?>
        <div class="wrap">
            <?php echo MOP_Admin::back_to_dashboard_link(); ?>
            <h1 class="wp-heading-inline"><?php echo $is_new ? esc_html__( 'Add User', 'matthewsorderplugin' ) : esc_html__( 'Edit User', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back to users', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <?php self::render_notices(); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mop_save_user">
                <input type="hidden" name="id"     value="<?php echo esc_attr( $id ); ?>">
                <?php wp_nonce_field( 'mop_save_user' ); ?>

                <h2><?php esc_html_e( 'Identity', 'matthewsorderplugin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="email"><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?> <span class="description">(required)</span></label></th>
                        <td>
                            <input name="email" id="email" type="email" class="regular-text" value="<?php echo esc_attr( $v( 'email' ) ); ?>" required>
                            <p class="description"><?php esc_html_e( 'Login identity. Not globally unique — the same email may sign in to multiple customer accounts.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="password"><?php esc_html_e( 'Password', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <input name="password" id="password" type="text" class="regular-text" autocomplete="off" value="">
                            <button type="button" class="button" onclick="document.getElementById('password').value=Math.random().toString(36).slice(2,10)+Math.random().toString(36).slice(2,10);"><?php esc_html_e( 'Generate', 'matthewsorderplugin' ); ?></button>
                            <p class="description">
                                <?php echo $is_new
                                    ? esc_html__( 'Leave blank to create a passwordless user — they\'ll need forgot-password to sign in.', 'matthewsorderplugin' )
                                    : esc_html__( 'Leave blank to keep current password. Min 8 characters when set.', 'matthewsorderplugin' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Send credentials email', 'matthewsorderplugin' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="send_credentials" value="1" <?php checked( $is_new ); ?>>
                                <?php esc_html_e( 'Email this user the login URL, their email, and the password above.', 'matthewsorderplugin' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Active', 'matthewsorderplugin' ); ?></th>
                        <td><label><input type="checkbox" name="is_active" value="1" <?php checked( $v( 'is_active', 1 ) ); ?>> <?php esc_html_e( 'Allow sign-in', 'matthewsorderplugin' ); ?></label></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Contact', 'matthewsorderplugin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="contact_first_name"><?php esc_html_e( 'First name', 'matthewsorderplugin' ); ?></label></th>
                        <td><input name="contact_first_name" id="contact_first_name" type="text" maxlength="50" class="regular-text" value="<?php echo esc_attr( $v( 'contact_first_name' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="contact_last_name"><?php esc_html_e( 'Last name', 'matthewsorderplugin' ); ?></label></th>
                        <td><input name="contact_last_name" id="contact_last_name" type="text" maxlength="50" class="regular-text" value="<?php echo esc_attr( $v( 'contact_last_name' ) ); ?>"></td>
                    </tr>
                </table>

                <?php submit_button( $is_new ? __( 'Create user', 'matthewsorderplugin' ) : __( 'Save user', 'matthewsorderplugin' ) ); ?>
            </form>

            <?php if ( ! $is_new ) : ?>
                <hr style="margin:2rem 0;">
                <h2><?php esc_html_e( 'Attached customers', 'matthewsorderplugin' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Which customer accounts this user can place orders against.', 'matthewsorderplugin' ); ?></p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:160px;"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Company', 'matthewsorderplugin' ); ?></th>
                            <th style="width:120px;"><?php esc_html_e( 'Default', 'matthewsorderplugin' ); ?></th>
                            <th style="width:100px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $attached_customers ) ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No customers attached. This user cannot place orders until at least one is attached.', 'matthewsorderplugin' ); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ( $attached_customers as $c ) :
                        $detach_url = wp_nonce_url(
                            add_query_arg( [
                                'action'      => 'mop_detach_user_customer',
                                'user_id'     => (int) $user['id'],
                                'customer_id' => (int) $c['id'],
                                'return'      => 'user',
                            ], admin_url( 'admin-post.php' ) ),
                            'mop_detach_user_customer_' . (int) $user['id'] . '_' . (int) $c['id']
                        );
                        $customer_edit = admin_url( 'admin.php?page=mop_customers&action=edit&id=' . (int) $c['id'] );
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $customer_edit ); ?>"><?php echo esc_html( $c['customer_id'] ); ?></a></td>
                            <td><?php echo esc_html( $c['company_name'] ?: '—' ); ?></td>
                            <td><?php echo ! empty( $c['is_default'] ) ? '<span class="dashicons dashicons-yes"></span>' : ''; ?></td>
                            <td><a href="<?php echo esc_url( $detach_url ); ?>" style="color:#a00;" onclick="return confirm('<?php echo esc_js( __( 'Detach this user from this customer? The customer record is kept.', 'matthewsorderplugin' ) ); ?>');"><?php esc_html_e( 'Detach', 'matthewsorderplugin' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h3><?php esc_html_e( 'Attach to a customer', 'matthewsorderplugin' ); ?></h3>
                <?php
                $attached_ids = array_map( fn( $c ) => (int) $c['id'], $attached_customers );
                $available    = array_filter(
                    MOP_Customer::all(),
                    fn( $c ) => ! in_array( (int) $c['id'], $attached_ids, true )
                );
                ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action"  value="mop_attach_user_customer">
                    <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                    <input type="hidden" name="return"  value="user">
                    <?php wp_nonce_field( 'mop_attach_user_customer' ); ?>
                    <input
                        name="customer_id_text"
                        type="text"
                        maxlength="15"
                        class="regular-text"
                        list="mop-customer-list-<?php echo (int) $user['id']; ?>"
                        placeholder="<?php esc_attr_e( 'Start typing a Customer ID or company name…', 'matthewsorderplugin' ); ?>"
                        autocomplete="off"
                        required>
                    <datalist id="mop-customer-list-<?php echo (int) $user['id']; ?>">
                        <?php foreach ( $available as $c ) :
                            $label = MOP_Customer::display_name( $c );
                            ?>
                            <option value="<?php echo esc_attr( $c['customer_id'] ); ?>" label="<?php echo esc_attr( $label ); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <button type="submit" class="button"><?php esc_html_e( 'Attach', 'matthewsorderplugin' ); ?></button>
                    <?php if ( empty( $available ) ) : ?>
                        <p class="description"><?php esc_html_e( 'This user is already attached to every customer in the system.', 'matthewsorderplugin' ); ?></p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_notices() {
        $notice = isset( $_GET['mop_notice'] ) ? sanitize_key( $_GET['mop_notice'] ) : '';
        $error  = isset( $_GET['mop_error'] )  ? sanitize_key( $_GET['mop_error'] )  : '';

        $notices = [
            'user_created'      => __( 'User created.', 'matthewsorderplugin' ),
            'user_created_sent' => __( 'User created and credentials email sent.', 'matthewsorderplugin' ),
            'user_saved'        => __( 'User saved.', 'matthewsorderplugin' ),
            'user_saved_sent'   => __( 'User saved and credentials email sent.', 'matthewsorderplugin' ),
            'user_deleted'      => __( 'User deleted.', 'matthewsorderplugin' ),
            'user_attached'     => __( 'User attached to customer.', 'matthewsorderplugin' ),
            'user_detached'     => __( 'User detached from customer.', 'matthewsorderplugin' ),
        ];
        $errors = [
            'email_required'    => __( 'Email is required.', 'matthewsorderplugin' ),
            'password_too_short'=> __( 'Password must be at least 8 characters.', 'matthewsorderplugin' ),
            'not_found'         => __( 'User not found.', 'matthewsorderplugin' ),
            'customer_not_found'=> __( 'No customer found with that ID.', 'matthewsorderplugin' ),
        ];
        if ( $notice && isset( $notices[ $notice ] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
        }
        if ( $error && isset( $errors[ $error ] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
        }
    }
}
