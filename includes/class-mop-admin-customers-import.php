<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Combined CSV import: one customer + up to 5 user slots per row.
 *
 * This is the ONE bulk path for customers + users + bridges since the
 * standalone users CSV import was retired in DB v0.6.0. The CSV column
 * layout mirrors the client's source spreadsheet so it can flow in with
 * minimal reshaping.
 *
 * Behavior:
 *   - Customer upsert keyed by `customer_id` (FMM #). Re-importing the
 *     same row updates the customer fields without creating dupes.
 *   - For each populated user_N_email slot: user upsert keyed by email
 *     (first match per email; same email may exist on multiple rows
 *     attached to different customers, which is intentional). Existing
 *     users keep their password unless user_N_password is provided.
 *   - Bridge upsert by (user_id, customer_id) — idempotent.
 *   - Additive only. Bridges, users, and customers are NEVER deleted by
 *     this import.
 *   - user_N_password is hashed at parse time; the preview transient
 *     never holds plaintext.
 */
class MOP_Admin_Customers_Import {

    const MAX_USER_SLOTS = 5;
    const TRANSIENT_TTL  = HOUR_IN_SECONDS;

    /**
     * Column names in the canonical order they should appear in the CSV.
     * Export emits these; the parser tolerates missing optional columns
     * and ignores unknown columns.
     */
    public static function columns() {
        $cols = [
            'customer_id', 'company_name', 'phone',
            'bill_to_line1', 'bill_to_line2', 'bill_to_city', 'bill_to_state', 'bill_to_zip',
            'ship_to_line1', 'ship_to_line2', 'ship_to_city', 'ship_to_state', 'ship_to_zip',
        ];
        for ( $i = 1; $i <= self::MAX_USER_SLOTS; $i++ ) {
            $cols[] = "user_{$i}_email";
            $cols[] = "user_{$i}_first_name";
            $cols[] = "user_{$i}_last_name";
            $cols[] = "user_{$i}_password";
        }
        return $cols;
    }

    public static function render_upload() {
        $list_url    = admin_url( 'admin.php?page=mop_customers' );
        $example_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_customers_csv_example' ], admin_url( 'admin-post.php' ) ),
            'mop_customers_csv_example'
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Import customers + users from CSV', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to customers', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <p>
                <?php
                printf(
                    /* translators: %d: max user slots */
                    esc_html__( 'One row per customer. Each row can carry up to %d users (login accounts) inline as indexed columns: user_1_email, user_1_first_name, user_1_last_name, user_1_password, then user_2_*, and so on.', 'matthewsorderplugin' ),
                    (int) self::MAX_USER_SLOTS
                );
                ?>
                <a href="<?php echo esc_url( $example_url ); ?>"><?php esc_html_e( 'Download example CSV', 'matthewsorderplugin' ); ?></a>
            </p>

            <div class="notice notice-warning inline" style="padding:0.75rem 1rem;">
                <p>
                    <strong><?php esc_html_e( 'Imports are additive.', 'matthewsorderplugin' ); ?></strong>
                    <?php esc_html_e( 'New customers/users/bridges are created; existing ones are updated in place. Re-importing a row with fewer user slots does NOT detach users — to revoke access, detach via the customer or user admin screen.', 'matthewsorderplugin' ); ?>
                </p>
            </div>

            <h2><?php esc_html_e( 'Required columns', 'matthewsorderplugin' ); ?></h2>
            <ul style="list-style:disc; margin-left:1.5em;">
                <li><code>customer_id</code> — <?php esc_html_e( 'FMM Customer Number; unique; max 15 chars', 'matthewsorderplugin' ); ?></li>
                <li><code>company_name</code> — <?php esc_html_e( 'used in the account picker and emails', 'matthewsorderplugin' ); ?></li>
            </ul>

            <h2><?php esc_html_e( 'Optional columns', 'matthewsorderplugin' ); ?></h2>
            <ul style="list-style:disc; margin-left:1.5em;">
                <li><code>phone</code></li>
                <li><code>bill_to_line1 / bill_to_line2 / bill_to_city / bill_to_state / bill_to_zip</code></li>
                <li><code>ship_to_line1 / ship_to_line2 / ship_to_city / ship_to_state / ship_to_zip</code></li>
                <li><code>user_N_email</code> — <?php esc_html_e( 'creates / attaches the user; required for that slot to do anything', 'matthewsorderplugin' ); ?></li>
                <li><code>user_N_first_name / user_N_last_name</code> — <?php esc_html_e( 'optional; emails fall back to the email address when names are blank', 'matthewsorderplugin' ); ?></li>
                <li><code>user_N_password</code> — <?php esc_html_e( 'optional plaintext; min 8 chars; hashed at parse time; never stored as plaintext after that. Existing users keep their password if this is blank.', 'matthewsorderplugin' ); ?></li>
            </ul>

            <div class="notice notice-warning inline" style="padding:0.75rem 1rem;">
                <p>
                    <strong><?php esc_html_e( 'Treat any CSV that contains passwords as sensitive.', 'matthewsorderplugin' ); ?></strong>
                    <?php esc_html_e( 'Do not email it, do not commit it to source control, and delete the local copy after import.', 'matthewsorderplugin' ); ?>
                </p>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:1.5rem;">
                <input type="hidden" name="action" value="mop_customers_import_preview">
                <?php wp_nonce_field( 'mop_customers_import_preview' ); ?>
                <p>
                    <input type="file" name="csv_file" accept=".csv,text/csv" required>
                </p>
                <?php submit_button( __( 'Continue to preview', 'matthewsorderplugin' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function render_preview() {
        $token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        $parsed = $token ? get_transient( 'mop_customers_import_' . $token ) : null;
        $list_url   = admin_url( 'admin.php?page=mop_customers' );
        $cancel_url = admin_url( 'admin.php?page=mop_customers&action=import' );

        if ( ! $parsed || ! is_array( $parsed ) ) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Import preview', 'matthewsorderplugin' ); ?></h1>
                <div class="notice notice-error"><p><?php esc_html_e( 'That import session has expired or could not be found. Please re-upload your CSV.', 'matthewsorderplugin' ); ?></p></div>
                <p><a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Start over', 'matthewsorderplugin' ); ?></a></p>
            </div>
            <?php
            return;
        }

        $c_create   = $parsed['customers_create'];
        $c_update   = $parsed['customers_update'];
        $u_create   = $parsed['users_create'];
        $bridges    = $parsed['bridges'];
        $errors     = $parsed['errors'];
        $applyable  = count( $c_create ) + count( $c_update ) + count( $u_create ) + count( $bridges );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Import preview', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to customers', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <p style="font-size:1.1em;">
                <?php
                printf(
                    esc_html__( '%1$d new customers, %2$d existing customers updated, %3$d new users, %4$d new (or existing) bridges, %5$d rows have errors and will be skipped.', 'matthewsorderplugin' ),
                    count( $c_create ), count( $c_update ), count( $u_create ), count( $bridges ), count( $errors )
                );
                ?>
            </p>

            <?php if ( count( $c_update ) > 0 ) : ?>
                <h2><?php esc_html_e( 'Will be updated — Customers', 'matthewsorderplugin' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th style="width:60px;"><?php esc_html_e( 'Row', 'matthewsorderplugin' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'New company', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'New city / state', 'matthewsorderplugin' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $c_update as $r ) : ?>
                        <tr>
                            <td><?php echo (int) $r['row']; ?></td>
                            <td><strong><?php echo esc_html( $r['data']['customer_id'] ); ?></strong></td>
                            <td><?php echo esc_html( $r['data']['company_name'] ); ?></td>
                            <td><?php echo esc_html( trim( ( $r['data']['bill_to_city'] ?? '' ) . ' ' . ( $r['data']['bill_to_state'] ?? '' ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( count( $c_create ) > 0 ) : ?>
                <h2><?php esc_html_e( 'Will be created — Customers', 'matthewsorderplugin' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th style="width:60px;"><?php esc_html_e( 'Row', 'matthewsorderplugin' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Company', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'City / state', 'matthewsorderplugin' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $c_create as $r ) : ?>
                        <tr>
                            <td><?php echo (int) $r['row']; ?></td>
                            <td><strong><?php echo esc_html( $r['data']['customer_id'] ); ?></strong></td>
                            <td><?php echo esc_html( $r['data']['company_name'] ); ?></td>
                            <td><?php echo esc_html( trim( ( $r['data']['bill_to_city'] ?? '' ) . ' ' . ( $r['data']['bill_to_state'] ?? '' ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( count( $u_create ) > 0 ) : ?>
                <h2><?php esc_html_e( 'Will be created — Users', 'matthewsorderplugin' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'matthewsorderplugin' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'Password', 'matthewsorderplugin' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $u_create as $u ) :
                        $name = trim( ( $u['data']['contact_first_name'] ?? '' ) . ' ' . ( $u['data']['contact_last_name'] ?? '' ) );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $u['data']['email'] ); ?></td>
                            <td><?php echo esc_html( $name ?: '—' ); ?></td>
                            <td>
                                <?php if ( ! empty( $u['set_password'] ) ) : ?>
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

            <?php if ( count( $bridges ) > 0 ) : ?>
                <h2><?php esc_html_e( 'Bridges to add', 'matthewsorderplugin' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Existing bridges remain in place; this list shows the (user, customer) attachments the import will ensure exist.', 'matthewsorderplugin' ); ?></p>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'User email', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'matthewsorderplugin' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $bridges as $b ) : ?>
                        <tr>
                            <td><?php echo esc_html( $b['user_email'] ); ?></td>
                            <td><strong><?php echo esc_html( $b['customer_id_text'] ); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( count( $errors ) > 0 ) : ?>
                <h2><?php esc_html_e( 'Will be skipped (errors)', 'matthewsorderplugin' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th style="width:60px;"><?php esc_html_e( 'Row', 'matthewsorderplugin' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Reason', 'matthewsorderplugin' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $errors as $err ) : ?>
                        <tr>
                            <td><?php echo (int) $err['row']; ?></td>
                            <td><?php echo esc_html( $err['customer_id_text'] ); ?></td>
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
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5rem;">
                    <input type="hidden" name="action" value="mop_customers_import_apply">
                    <input type="hidden" name="token"  value="<?php echo esc_attr( $token ); ?>">
                    <?php wp_nonce_field( 'mop_customers_import_apply' ); ?>

                    <?php if ( count( $c_update ) > 0 ) : ?>
                        <p>
                            <label style="font-weight:600;">
                                <input type="checkbox" name="confirm_overwrite" value="1" required>
                                <?php
                                printf(
                                    esc_html__( 'I understand this will overwrite %d existing customer(s).', 'matthewsorderplugin' ),
                                    count( $c_update )
                                );
                                ?>
                            </label>
                        </p>
                    <?php endif; ?>

                    <p>
                        <button type="submit" class="button button-primary">
                            <?php printf( esc_html__( 'Apply import (%d write(s))', 'matthewsorderplugin' ), $applyable ); ?>
                        </button>
                        <a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'matthewsorderplugin' ); ?></a>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Parse the uploaded CSV into a structured plan:
     *   [
     *     'customers_create' => [ ['row'=>N, 'data'=>[customer columns]], ... ],
     *     'customers_update' => [ ['row'=>N, 'id'=>X, 'data'=>[customer columns]], ... ],
     *     'users_create'     => [ ['row'=>N, 'data'=>[user columns + password_hash if set], 'set_password'=>bool], ... ],
     *     'bridges'          => [ ['row'=>N, 'user_email'=>..., 'customer_id_text'=>..., 'set_default'=>bool], ... ],
     *     'errors'           => [ ['row'=>N, 'customer_id_text'=>..., 'reason'=>...], ... ],
     *   ]
     *
     * Apply step resolves user_email + customer_id_text into actual ids
     * (since users may have been created in the same import). Bridge upsert
     * is idempotent so re-imports don't dupe.
     */
    public static function parse_csv( $tmp_path ) {
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

        $known   = array_flip( self::columns() );
        $col_map = [];
        foreach ( $header as $idx => $name ) {
            if ( isset( $known[ $name ] ) ) {
                $col_map[ $idx ] = $name;
            }
        }
        if ( ! in_array( 'customer_id', $col_map, true ) ) {
            fclose( $fh );
            return new WP_Error( 'missing_customer_id', 'CSV must include a customer_id column.' );
        }
        if ( ! in_array( 'company_name', $col_map, true ) ) {
            fclose( $fh );
            return new WP_Error( 'missing_company_name', 'CSV must include a company_name column.' );
        }

        $customers_create = [];
        $customers_update = [];
        $users_create     = [];
        $bridges          = [];
        $errors           = [];
        $seen_customers   = [];   // customer_id_text => row#
        $planned_users    = [];   // lower(email) => true (already queued for create in this run)

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

            $cid_text = isset( $row['customer_id'] ) ? trim( (string) $row['customer_id'] ) : '';
            if ( $cid_text === '' ) {
                $errors[] = [ 'row' => $row_num, 'customer_id_text' => '', 'reason' => 'customer_id is required' ];
                continue;
            }
            $cid_text = substr( $cid_text, 0, 15 );
            if ( isset( $seen_customers[ $cid_text ] ) ) {
                $errors[] = [ 'row' => $row_num, 'customer_id_text' => $cid_text, 'reason' => 'duplicate customer_id earlier in file (row ' . $seen_customers[ $cid_text ] . ')' ];
                continue;
            }
            $seen_customers[ $cid_text ] = $row_num;

            $customer_data = [
                'customer_id'   => $cid_text,
                'company_name'  => self::cap( $row['company_name']  ?? '', 64 ),
                'phone'         => self::cap( $row['phone']         ?? '', 30 ),
                'bill_to_line1' => self::cap( $row['bill_to_line1'] ?? '', 100 ),
                'bill_to_line2' => self::cap( $row['bill_to_line2'] ?? '', 100 ),
                'bill_to_city'  => self::cap( $row['bill_to_city']  ?? '', 50 ),
                'bill_to_state' => strtoupper( self::cap( $row['bill_to_state'] ?? '', 2 ) ),
                'bill_to_zip'   => self::cap( $row['bill_to_zip']   ?? '', 10 ),
                'ship_to_line1' => self::cap( $row['ship_to_line1'] ?? '', 100 ),
                'ship_to_line2' => self::cap( $row['ship_to_line2'] ?? '', 100 ),
                'ship_to_city'  => self::cap( $row['ship_to_city']  ?? '', 50 ),
                'ship_to_state' => strtoupper( self::cap( $row['ship_to_state'] ?? '', 2 ) ),
                'ship_to_zip'   => self::cap( $row['ship_to_zip']   ?? '', 10 ),
                'is_active'     => 1,
            ];

            $existing_customer = MOP_Customer::find_by_customer_id( $cid_text );
            if ( $existing_customer ) {
                $customers_update[] = [
                    'row' => $row_num, 'id' => (int) $existing_customer['id'], 'data' => $customer_data,
                ];
            } else {
                $customers_create[] = [ 'row' => $row_num, 'data' => $customer_data ];
            }

            // User slots
            for ( $i = 1; $i <= self::MAX_USER_SLOTS; $i++ ) {
                $email_raw = isset( $row["user_{$i}_email"] ) ? (string) $row["user_{$i}_email"] : '';
                $email     = sanitize_email( trim( $email_raw ) );
                if ( $email === '' ) {
                    continue;
                }
                if ( ! is_email( $email ) ) {
                    $errors[] = [ 'row' => $row_num, 'customer_id_text' => $cid_text, 'reason' => "user_{$i}_email is not a valid email: " . $email_raw ];
                    continue;
                }
                $password = isset( $row["user_{$i}_password"] ) ? (string) $row["user_{$i}_password"] : '';
                if ( $password !== '' && strlen( $password ) < 8 ) {
                    $errors[] = [ 'row' => $row_num, 'customer_id_text' => $cid_text, 'reason' => "user_{$i}_password must be at least 8 characters" ];
                    continue;
                }

                $email_lc = strtolower( $email );
                $existing_user = MOP_User::find_by_email( $email );

                if ( ! $existing_user && ! isset( $planned_users[ $email_lc ] ) ) {
                    $user_data = [
                        'email'              => $email_lc,
                        'contact_first_name' => self::cap( $row["user_{$i}_first_name"] ?? '', 50 ),
                        'contact_last_name'  => self::cap( $row["user_{$i}_last_name"]  ?? '', 50 ),
                        'is_active'          => 1,
                    ];
                    $set_password = $password !== '';
                    if ( $set_password ) {
                        $user_data['password_hash'] = wp_hash_password( $password );
                    }
                    $users_create[] = [ 'row' => $row_num, 'data' => $user_data, 'set_password' => $set_password ];
                    $planned_users[ $email_lc ] = true;
                }

                $bridges[] = [
                    'row'              => $row_num,
                    'user_email'       => $email_lc,
                    'customer_id_text' => $cid_text,
                ];
            }
        }
        fclose( $fh );

        if ( empty( $customers_create ) && empty( $customers_update ) && empty( $users_create ) && empty( $bridges ) && empty( $errors ) ) {
            return new WP_Error( 'no_rows', 'CSV had no data rows.' );
        }

        return [
            'customers_create' => $customers_create,
            'customers_update' => $customers_update,
            'users_create'     => $users_create,
            'bridges'          => $bridges,
            'errors'           => $errors,
        ];
    }

    /**
     * Apply the parsed plan: create/update customers, create users, then
     * resolve bridges by re-looking-up email + FMM customer_id. Done in
     * that order so user-resolving on the bridge step finds users we
     * just created.
     *
     * Returns counts: ['customers_added', 'customers_updated',
     * 'users_added', 'bridges_added', 'errored'].
     */
    public static function apply( array $plan ) {
        $c_added = 0;
        foreach ( $plan['customers_create'] as $r ) {
            if ( MOP_Customer::create( $r['data'] ) ) {
                $c_added++;
            }
        }
        $c_updated = 0;
        foreach ( $plan['customers_update'] as $r ) {
            // Don't allow customer_id to mutate (it's the lookup key).
            $upd = $r['data'];
            unset( $upd['customer_id'] );
            if ( MOP_Customer::update( (int) $r['id'], $upd ) ) {
                $c_updated++;
            }
        }
        $u_added = 0;
        foreach ( $plan['users_create'] as $r ) {
            if ( MOP_User::create( $r['data'] ) ) {
                $u_added++;
            }
        }
        $b_added = 0;
        foreach ( $plan['bridges'] as $b ) {
            $u = MOP_User::find_by_email( $b['user_email'] );
            $c = MOP_Customer::find_by_customer_id( $b['customer_id_text'] );
            if ( $u && $c ) {
                if ( MOP_UserCustomer::attach( (int) $u['id'], (int) $c['id'] ) ) {
                    $b_added++;
                }
            }
        }
        return [
            'customers_added'   => $c_added,
            'customers_updated' => $c_updated,
            'users_added'       => $u_added,
            'bridges_added'     => $b_added,
            'errored'           => count( $plan['errors'] ),
        ];
    }

    private static function cap( $val, $max ) {
        $val = sanitize_text_field( (string) $val );
        return $max ? substr( $val, 0, $max ) : $val;
    }
}
