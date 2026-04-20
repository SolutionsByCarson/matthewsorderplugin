<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin → Matthews Orders → Users.
 *
 * Three views dispatched off the ?action= query var:
 *   list (default) — table of all customers + "Add New" button + row actions
 *   new / edit     — single form; customer_id is read-only once set
 *
 * Save + delete go through MOP_Handlers (admin-post.php) for nonce + redirect
 * handling. On new-user save, if "email credentials" is checked the generated
 * (or admin-typed) password is emailed via MOP_Email::new_user().
 *
 * Notices are query-arg driven (?mop_notice= / mop_error=) because that
 * survives the post/redirect/get round-trip without transient plumbing.
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
        $users   = MOP_User::all();
        $new_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Users', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <?php self::render_notices(); ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:140px;"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Company', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Contact', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Active', 'matthewsorderplugin' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( 'Last login', 'matthewsorderplugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $users ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No users yet. Click "Add New" to create one.', 'matthewsorderplugin' ); ?></td></tr>
                <?php endif; ?>

                <?php foreach ( $users as $user ) :
                    $edit_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . (int) $user['id'] );
                    $delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=mop_delete_user&id=' . (int) $user['id'] ), 'mop_delete_user_' . (int) $user['id'] );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $user['customer_id'] ); ?></strong>
                            <div class="row-actions">
                                <span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'matthewsorderplugin' ); ?></a> | </span>
                                <span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this user? This cannot be undone.', 'matthewsorderplugin' ) ); ?>');"><?php esc_html_e( 'Delete', 'matthewsorderplugin' ); ?></a></span>
                            </div>
                        </td>
                        <td><?php echo esc_html( $user['company_name'] ); ?></td>
                        <td><?php echo esc_html( MOP_User::full_name( $user ) ); ?></td>
                        <td><?php echo esc_html( $user['email'] ); ?></td>
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
        if ( ! $is_new && ! $user ) {
            echo '<div class="wrap"><h1>Not found</h1></div>';
            return;
        }

        $v = function ( $key, $default = '' ) use ( $user ) {
            if ( $user && isset( $user[ $key ] ) && $user[ $key ] !== null ) {
                return $user[ $key ];
            }
            return $default;
        };
        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? esc_html__( 'Add User', 'matthewsorderplugin' ) : esc_html__( 'Edit User', 'matthewsorderplugin' ); ?></h1>

            <?php self::render_notices(); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mop_save_user">
                <input type="hidden" name="id"     value="<?php echo esc_attr( $id ); ?>">
                <?php wp_nonce_field( 'mop_save_user' ); ?>

                <h2><?php esc_html_e( 'Identity', 'matthewsorderplugin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="customer_id"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?> <span class="description">(required)</span></label></th>
                        <td>
                            <input name="customer_id" id="customer_id" type="text" maxlength="15" class="regular-text"
                                value="<?php echo esc_attr( $v( 'customer_id' ) ); ?>"
                                <?php echo $is_new ? 'required' : 'readonly'; ?>>
                            <p class="description"><?php esc_html_e( 'FMM Customer ID — must match FMM exactly (e.g. "HUSTON AMANDA"). Max 15 chars. Cannot be changed after creation.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="email"><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?> <span class="description">(required)</span></label></th>
                        <td><input name="email" id="email" type="email" class="regular-text" value="<?php echo esc_attr( $v( 'email' ) ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="password"><?php esc_html_e( 'Password', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <input name="password" id="password" type="text" class="regular-text" autocomplete="off"
                                value="" <?php echo $is_new ? 'required minlength="8"' : ''; ?>>
                            <button type="button" class="button" onclick="document.getElementById('password').value=Math.random().toString(36).slice(2,10)+Math.random().toString(36).slice(2,10);"><?php esc_html_e( 'Generate', 'matthewsorderplugin' ); ?></button>
                            <p class="description">
                                <?php echo $is_new
                                    ? esc_html__( 'Required. Minimum 8 characters.', 'matthewsorderplugin' )
                                    : esc_html__( 'Leave blank to keep current password.', 'matthewsorderplugin' ); ?>
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
                        <th><label for="company_name"><?php esc_html_e( 'Company name', 'matthewsorderplugin' ); ?></label></th>
                        <td><input name="company_name" id="company_name" type="text" maxlength="64" class="regular-text" value="<?php echo esc_attr( $v( 'company_name' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="contact_first_name"><?php esc_html_e( 'First name', 'matthewsorderplugin' ); ?></label></th>
                        <td><input name="contact_first_name" id="contact_first_name" type="text" maxlength="50" class="regular-text" value="<?php echo esc_attr( $v( 'contact_first_name' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="contact_last_name"><?php esc_html_e( 'Last name', 'matthewsorderplugin' ); ?></label></th>
                        <td><input name="contact_last_name" id="contact_last_name" type="text" maxlength="50" class="regular-text" value="<?php echo esc_attr( $v( 'contact_last_name' ) ); ?>"></td>
                    </tr>
                </table>

                <?php self::render_address_section( 'bill_to', __( 'Billing address', 'matthewsorderplugin' ), $v ); ?>
                <?php self::render_address_section( 'ship_to', __( 'Shipping address', 'matthewsorderplugin' ), $v ); ?>

                <?php submit_button( $is_new ? __( 'Create user', 'matthewsorderplugin' ) : __( 'Save user', 'matthewsorderplugin' ) ); ?>
            </form>
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

    private static function render_notices() {
        $notice = isset( $_GET['mop_notice'] ) ? sanitize_key( $_GET['mop_notice'] ) : '';
        $error  = isset( $_GET['mop_error'] )  ? sanitize_key( $_GET['mop_error'] )  : '';

        $notices = [
            'user_created'      => __( 'User created.', 'matthewsorderplugin' ),
            'user_created_sent' => __( 'User created and credentials email sent.', 'matthewsorderplugin' ),
            'user_saved'        => __( 'User saved.', 'matthewsorderplugin' ),
            'user_saved_sent'   => __( 'User saved and credentials email sent.', 'matthewsorderplugin' ),
            'user_deleted'      => __( 'User deleted.', 'matthewsorderplugin' ),
        ];
        $errors = [
            'customer_id_required' => __( 'Customer ID is required.', 'matthewsorderplugin' ),
            'email_required'       => __( 'Email is required.', 'matthewsorderplugin' ),
            'password_required'    => __( 'Password is required for new users (min 8 chars).', 'matthewsorderplugin' ),
            'email_in_use'         => __( 'Another user already has that email.', 'matthewsorderplugin' ),
            'customer_id_in_use'   => __( 'Another user already has that Customer ID.', 'matthewsorderplugin' ),
            'not_found'            => __( 'User not found.', 'matthewsorderplugin' ),
        ];
        if ( $notice && isset( $notices[ $notice ] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
        }
        if ( $error && isset( $errors[ $error ] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
        }
    }
}
