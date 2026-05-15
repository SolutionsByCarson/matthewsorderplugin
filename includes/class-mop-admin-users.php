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
        $users      = MOP_User::all();
        $new_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
        $import_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=import' );
        $export_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_users_csv_export' ], admin_url( 'admin-post.php' ) ),
            'mop_users_csv_export'
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Users', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'matthewsorderplugin' ); ?></a>
            <a href="<?php echo esc_url( $import_url ); ?>" class="page-title-action"><?php esc_html_e( 'Import CSV', 'matthewsorderplugin' ); ?></a>
            <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'matthewsorderplugin' ); ?></a>
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

    /**
     * Step 1 of import: upload form. The example-CSV download link is right
     * here so admins can grab the template, fill it in, and re-upload.
     */
    private static function render_import() {
        $list_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $example_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_users_csv_example' ], admin_url( 'admin-post.php' ) ),
            'mop_users_csv_example'
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Import users from CSV', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to users', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <?php self::render_notices(); ?>

            <p>
                <?php esc_html_e( 'Upload a CSV using the column layout below. The first row must be the column headers.', 'matthewsorderplugin' ); ?>
                <a href="<?php echo esc_url( $example_url ); ?>"><?php esc_html_e( 'Download example CSV', 'matthewsorderplugin' ); ?></a>
            </p>

            <div class="notice notice-warning inline" style="padding:0.75rem 1rem;">
                <p>
                    <strong><?php esc_html_e( 'Customer ID is the unique key.', 'matthewsorderplugin' ); ?></strong>
                    <?php esc_html_e( 'If a row\'s customer_id matches an existing user, ALL of that user\'s fields will be overwritten with the values from the CSV. New customer_ids create new users. You will see a preview before any changes are saved.', 'matthewsorderplugin' ); ?>
                </p>
            </div>

            <h2><?php esc_html_e( 'Required columns', 'matthewsorderplugin' ); ?></h2>
            <ul style="list-style:disc; margin-left:1.5em;">
                <li><code>customer_id</code> — <?php esc_html_e( 'unique, max 15 chars (FMM Customer ID)', 'matthewsorderplugin' ); ?></li>
                <li><code>email</code> — <?php esc_html_e( 'must be a valid address; not in use by a different customer_id', 'matthewsorderplugin' ); ?></li>
            </ul>

            <h2><?php esc_html_e( 'Optional columns', 'matthewsorderplugin' ); ?></h2>
            <p style="font-family:monospace;">
                <?php
                $optional = array_diff( MOP_User::csv_columns(), [ 'customer_id', 'email' ] );
                echo esc_html( implode( ', ', $optional ) );
                ?>
            </p>
            <p class="description">
                <?php esc_html_e( 'is_active accepts 1/0, yes/no, or true/false.', 'matthewsorderplugin' ); ?>
                <?php esc_html_e( 'password is plaintext in the CSV; min 8 characters; leave blank to keep existing (or to defer setting one for new users — they\'ll have to use forgot-password).', 'matthewsorderplugin' ); ?>
            </p>

            <div class="notice notice-warning inline" style="padding:0.75rem 1rem;">
                <p>
                    <strong><?php esc_html_e( 'Treat any CSV that contains passwords as sensitive.', 'matthewsorderplugin' ); ?></strong>
                    <?php esc_html_e( 'Do not email it, do not commit it to source control, and delete the local copy after import. Passwords are hashed on upload and never written to disk in plaintext after that point — but they live on your machine in the CSV until you delete it.', 'matthewsorderplugin' ); ?>
                </p>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:1.5rem;">
                <input type="hidden" name="action" value="mop_users_import_preview">
                <?php wp_nonce_field( 'mop_users_import_preview' ); ?>
                <p>
                    <input type="file" name="csv_file" accept=".csv,text/csv" required>
                </p>
                <?php submit_button( __( 'Continue to preview', 'matthewsorderplugin' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Step 2 of import: preview screen. Shows totals + per-row tables of
     * what will happen, and forces the admin to tick a confirmation box
     * before the apply form will submit.
     */
    private static function render_import_preview() {
        $token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        $parsed = $token ? get_transient( 'mop_users_import_' . $token ) : null;
        $list_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $cancel_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=import' );

        if ( ! $parsed || ! is_array( $parsed ) ) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Import preview', 'matthewsorderplugin' ); ?></h1>
                <div class="notice notice-error"><p>
                    <?php esc_html_e( 'That import session has expired or could not be found. Please re-upload your CSV.', 'matthewsorderplugin' ); ?>
                </p></div>
                <p><a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Start over', 'matthewsorderplugin' ); ?></a></p>
            </div>
            <?php
            return;
        }

        $create_count = count( $parsed['create'] );
        $update_count = count( $parsed['update'] );
        $error_count  = count( $parsed['errors'] );
        $applyable    = $create_count + $update_count;
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Import preview', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to users', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <p style="font-size:1.1em;">
                <?php
                printf(
                    /* translators: %1$d: new users, %2$d: overwritten users, %3$d: error rows */
                    esc_html__( '%1$d new users will be created, %2$d existing users will be OVERWRITTEN, %3$d rows have errors and will be skipped.', 'matthewsorderplugin' ),
                    (int) $create_count,
                    (int) $update_count,
                    (int) $error_count
                );
                ?>
            </p>

            <?php if ( $update_count > 0 ) : ?>
                <div class="notice notice-warning inline" style="padding:0.75rem 1rem;">
                    <p><strong><?php
                        printf(
                            esc_html(
                                _n(
                                    '%d existing user will be overwritten.',
                                    '%d existing users will be overwritten.',
                                    $update_count,
                                    'matthewsorderplugin'
                                )
                            ),
                            (int) $update_count
                        );
                    ?></strong>
                    <?php esc_html_e( 'Their company, contact, email, addresses, and active flag will be replaced with the values from the CSV. Passwords and order history are preserved.', 'matthewsorderplugin' ); ?>
                    </p>
                </div>

                <h2><?php esc_html_e( 'Will be overwritten', 'matthewsorderplugin' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:140px;"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Existing company', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'New company', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Existing email', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'New email', 'matthewsorderplugin' ); ?></th>
                            <th style="width:130px;"><?php esc_html_e( 'Password', 'matthewsorderplugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $parsed['update'] as $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row['existing']['customer_id'] ); ?></strong></td>
                                <td><?php echo esc_html( $row['existing']['company_name'] ); ?></td>
                                <td><?php echo esc_html( $row['data']['company_name'] ); ?></td>
                                <td><?php echo esc_html( $row['existing']['email'] ); ?></td>
                                <td><?php echo esc_html( $row['data']['email'] ); ?></td>
                                <td>
                                    <?php if ( ! empty( $row['set_password'] ) ) : ?>
                                        <strong style="color:#a00;"><?php esc_html_e( 'Will be reset', 'matthewsorderplugin' ); ?></strong>
                                    <?php else : ?>
                                        <span class="description"><?php esc_html_e( 'Unchanged', 'matthewsorderplugin' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $create_count > 0 ) :
                $creates_without_password = 0;
                foreach ( $parsed['create'] as $row ) {
                    if ( empty( $row['set_password'] ) ) {
                        $creates_without_password++;
                    }
                }
                ?>
                <h2><?php esc_html_e( 'Will be created', 'matthewsorderplugin' ); ?></h2>
                <?php if ( $creates_without_password > 0 ) : ?>
                    <p class="description">
                        <?php
                        printf(
                            esc_html(
                                _n(
                                    '%d new user has no password in the CSV — they will not be able to sign in until you set one (Edit user) or they use the forgot-password flow.',
                                    '%d new users have no password in the CSV — they will not be able to sign in until you set one (Edit user) or they use the forgot-password flow.',
                                    $creates_without_password,
                                    'matthewsorderplugin'
                                )
                            ),
                            (int) $creates_without_password
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:140px;"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Company', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Active', 'matthewsorderplugin' ); ?></th>
                            <th style="width:130px;"><?php esc_html_e( 'Password', 'matthewsorderplugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $parsed['create'] as $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row['data']['customer_id'] ); ?></strong></td>
                                <td><?php echo esc_html( $row['data']['company_name'] ); ?></td>
                                <td><?php echo esc_html( $row['data']['email'] ); ?></td>
                                <td><?php echo (int) $row['data']['is_active'] ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-no-alt"></span>'; ?></td>
                                <td>
                                    <?php if ( ! empty( $row['set_password'] ) ) : ?>
                                        <span class="dashicons dashicons-yes" style="color:#46b450;"></span> <?php esc_html_e( 'Will be set', 'matthewsorderplugin' ); ?>
                                    <?php else : ?>
                                        <span class="description"><?php esc_html_e( 'None — must reset', 'matthewsorderplugin' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $error_count > 0 ) : ?>
                <h2><?php esc_html_e( 'Will be skipped (errors)', 'matthewsorderplugin' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:60px;"><?php esc_html_e( 'Row', 'matthewsorderplugin' ); ?></th>
                            <th style="width:140px;"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Reason', 'matthewsorderplugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $parsed['errors'] as $err ) : ?>
                            <tr>
                                <td><?php echo (int) $err['row']; ?></td>
                                <td><?php echo esc_html( $err['customer_id'] ); ?></td>
                                <td><?php echo esc_html( $err['reason'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $applyable === 0 ) : ?>
                <p style="margin-top:1.5rem;">
                    <a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Start over', 'matthewsorderplugin' ); ?></a>
                </p>
            <?php else : ?>
                <?php
                $password_resets = 0;
                foreach ( $parsed['update'] as $row ) {
                    if ( ! empty( $row['set_password'] ) ) {
                        $password_resets++;
                    }
                }
                ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5rem;">
                    <input type="hidden" name="action" value="mop_users_import_apply">
                    <input type="hidden" name="token"  value="<?php echo esc_attr( $token ); ?>">
                    <?php wp_nonce_field( 'mop_users_import_apply' ); ?>

                    <?php if ( $update_count > 0 ) : ?>
                        <p>
                            <label style="font-weight:600;">
                                <input type="checkbox" name="confirm_overwrite" value="1" required>
                                <?php
                                printf(
                                    esc_html__( 'I understand this will overwrite %d existing user(s).', 'matthewsorderplugin' ),
                                    (int) $update_count
                                );
                                ?>
                            </label>
                        </p>
                    <?php endif; ?>

                    <?php if ( $password_resets > 0 ) : ?>
                        <p>
                            <label style="font-weight:600; color:#a00;">
                                <input type="checkbox" name="confirm_password_reset" value="1" required>
                                <?php
                                printf(
                                    esc_html(
                                        _n(
                                            'I understand this will RESET the password for %d existing user.',
                                            'I understand this will RESET the password for %d existing users.',
                                            $password_resets,
                                            'matthewsorderplugin'
                                        )
                                    ),
                                    (int) $password_resets
                                );
                                ?>
                            </label>
                        </p>
                    <?php endif; ?>

                    <p>
                        <button type="submit" class="button button-primary">
                            <?php
                            printf(
                                esc_html__( 'Apply import (%d row(s))', 'matthewsorderplugin' ),
                                (int) $applyable
                            );
                            ?>
                        </button>
                        <a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'matthewsorderplugin' ); ?></a>
                    </p>
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
            'no_file'              => __( 'Please choose a CSV file to upload.', 'matthewsorderplugin' ),
            'upload_failed'        => __( 'The file upload failed. Please try again.', 'matthewsorderplugin' ),
            'read_failed'          => __( 'Could not read the uploaded file.', 'matthewsorderplugin' ),
            'empty_csv'            => __( 'That CSV is empty.', 'matthewsorderplugin' ),
            'no_rows'              => __( 'That CSV had no data rows.', 'matthewsorderplugin' ),
            'missing_customer_id'  => __( 'CSV is missing the required customer_id column.', 'matthewsorderplugin' ),
            'missing_email'        => __( 'CSV is missing the required email column.', 'matthewsorderplugin' ),
            'token_expired'        => __( 'That import session has expired. Please re-upload.', 'matthewsorderplugin' ),
        ];

        if ( $notice === 'users_imported' ) {
            $added   = isset( $_GET['mop_import_added'] )   ? (int) $_GET['mop_import_added']   : 0;
            $updated = isset( $_GET['mop_import_updated'] ) ? (int) $_GET['mop_import_updated'] : 0;
            $errored = isset( $_GET['mop_import_errored'] ) ? (int) $_GET['mop_import_errored'] : 0;
            echo '<div class="notice notice-success"><p>' . esc_html( sprintf(
                __( 'Import complete: %1$d created, %2$d updated, %3$d skipped.', 'matthewsorderplugin' ),
                $added, $updated, $errored
            ) ) . '</p></div>';
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
