<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin → Matthews Orders → Orders.
 *
 * Two views dispatched off ?action=:
 *   list (default) — all orders, Download ORDIMP link per row, CSV export
 *   detail         — one order's header + line items (read-only)
 *
 * Orders are intentionally NOT editable from the admin — the source of truth
 * for a placed order is the `mop_orders` row and its generated ORDIMP.dat
 * file; editing after submission would break the FMM round-trip guarantee.
 * If a correction is needed, customers re-submit (new PO number).
 *
 * CSV export: wide format, one row per line item. Lives in MOP_Handlers as
 * `mop_orders_csv` (admin-post.php) so the download stream doesn't fight
 * the WP admin screen output buffer.
 */
class MOP_Admin_Orders {

    const PAGE_SLUG = 'mop_orders';

    public static function render() {
        if ( ! current_user_can( MOP_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this screen.', 'matthewsorderplugin' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        if ( $action === 'view' ) {
            self::render_detail();
            return;
        }
        if ( $action === 'delete-all-confirm' ) {
            self::render_delete_all_confirm();
            return;
        }
        self::render_list();
    }

    private static function render_list() {
        $orders         = MOP_Order::all_with_summary();
        $csv_url        = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_orders_csv' ], admin_url( 'admin-post.php' ) ),
            'mop_orders_csv'
        );
        $delete_all_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=delete-all-confirm' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Orders', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $csv_url ); ?>" class="page-title-action">
                <?php esc_html_e( 'Export CSV', 'matthewsorderplugin' ); ?>
            </a>
            <?php if ( ! empty( $orders ) ) : ?>
                <a href="<?php echo esc_url( $delete_all_url ); ?>" class="page-title-action" style="color:#a00;">
                    <?php printf( esc_html__( 'Delete all orders (%d)', 'matthewsorderplugin' ), count( $orders ) ); ?>
                </a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php self::render_notices(); ?>

            <p class="description">
                <?php esc_html_e( 'Orders are created from the customer front-end. This view is read-only — corrections are made by the customer re-submitting.', 'matthewsorderplugin' ); ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:180px;"><?php esc_html_e( 'PO Number', 'matthewsorderplugin' ); ?></th>
                        <th style="width:140px;"><?php esc_html_e( 'Submitted', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'matthewsorderplugin' ); ?></th>
                        <th style="width:110px;"><?php esc_html_e( 'Type', 'matthewsorderplugin' ); ?></th>
                        <th style="width:70px; text-align:right;"><?php esc_html_e( 'Lines', 'matthewsorderplugin' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( 'ORDIMP.dat', 'matthewsorderplugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $orders ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No orders yet.', 'matthewsorderplugin' ); ?></td></tr>
                <?php endif; ?>

                <?php foreach ( $orders as $order ) :
                    $view_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=view&id=' . (int) $order['id'] );
                    $dl_url   = wp_nonce_url(
                        add_query_arg( [
                            'action'   => 'mop_admin_download_ordimp',
                            'order_id' => (int) $order['id'],
                        ], admin_url( 'admin-post.php' ) ),
                        'mop_admin_download_ordimp_' . (int) $order['id']
                    );
                    $delete_url = wp_nonce_url(
                        add_query_arg( [
                            'action'   => 'mop_delete_order',
                            'order_id' => (int) $order['id'],
                        ], admin_url( 'admin-post.php' ) ),
                        'mop_delete_order_' . (int) $order['id']
                    );
                    $has_file = ! empty( $order['ordimp_path'] ) && file_exists( $order['ordimp_path'] );
                    $contact  = trim( ( $order['contact_first_name_snapshot'] ?? '' ) . ' ' . ( $order['contact_last_name_snapshot'] ?? '' ) );
                    $delete_prompt = sprintf(
                        /* translators: %s: PO number */
                        __( "Delete order %s? This removes the order row, its line items, and the ORDIMP.dat file on disk. This cannot be undone.", 'matthewsorderplugin' ),
                        $order['po_number']
                    );
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $order['po_number'] ); ?></a></strong>
                            <div class="row-actions">
                                <span class="view"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'matthewsorderplugin' ); ?></a></span>
                                <?php if ( $has_file ) : ?>
                                    | <span class="download"><a href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Download', 'matthewsorderplugin' ); ?></a></span>
                                <?php endif; ?>
                                | <span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" style="color:#a00;" onclick="return confirm('<?php echo esc_js( $delete_prompt ); ?>');"><?php esc_html_e( 'Delete', 'matthewsorderplugin' ); ?></a></span>
                            </div>
                        </td>
                        <td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $order['created_at'] ) ); ?></td>
                        <td>
                            <strong><?php echo esc_html( $order['company_snapshot'] ?: $order['customer_id_snapshot'] ); ?></strong><br>
                            <span class="description"><?php echo esc_html( $contact ?: ( $order['email_snapshot'] ?? '' ) ); ?></span>
                        </td>
                        <td><?php echo esc_html( MOP_Order::order_type_label( $order['order_type'] ) ); ?></td>
                        <td style="text-align:right;"><?php echo (int) $order['line_count']; ?></td>
                        <td>
                            <?php if ( $has_file ) : ?>
                                <a class="button button-small" href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Download', 'matthewsorderplugin' ); ?></a>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e( 'Missing', 'matthewsorderplugin' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_detail() {
        $id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $order = $id ? MOP_Order::find( $id ) : null;
        if ( ! $order ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Order not found', 'matthewsorderplugin' ) . '</h1></div>';
            return;
        }

        $lines    = MOP_Order::get_lines( (int) $order['id'] );
        $list_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $dl_url   = wp_nonce_url(
            add_query_arg( [
                'action'   => 'mop_admin_download_ordimp',
                'order_id' => (int) $order['id'],
            ], admin_url( 'admin-post.php' ) ),
            'mop_admin_download_ordimp_' . (int) $order['id']
        );
        $has_file = ! empty( $order['ordimp_path'] ) && file_exists( $order['ordimp_path'] );

        $contact = trim( ( $order['contact_first_name_snapshot'] ?? '' ) . ' ' . ( $order['contact_last_name_snapshot'] ?? '' ) );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html( sprintf( __( 'Order %s', 'matthewsorderplugin' ), $order['po_number'] ) ); ?></h1>
            <?php if ( $has_file ) : ?>
                <a href="<?php echo esc_url( $dl_url ); ?>" class="page-title-action"><?php esc_html_e( 'Download ORDIMP.dat', 'matthewsorderplugin' ); ?></a>
            <?php endif; ?>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to orders', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <h2><?php esc_html_e( 'Header', 'matthewsorderplugin' ); ?></h2>
            <table class="widefat striped" style="max-width:900px;">
                <tbody>
                    <tr><th style="width:200px;"><?php esc_html_e( 'PO Number', 'matthewsorderplugin' ); ?></th><td><code><?php echo esc_html( $order['po_number'] ); ?></code></td></tr>
                    <tr><th><?php esc_html_e( 'Submitted', 'matthewsorderplugin' ); ?></th><td><?php echo esc_html( mysql2date( 'F j, Y g:i a', $order['created_at'] ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Order Type', 'matthewsorderplugin' ); ?></th><td><?php echo esc_html( MOP_Order::order_type_label( $order['order_type'] ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Customer ID', 'matthewsorderplugin' ); ?></th><td><?php echo esc_html( $order['customer_id_snapshot'] ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Company', 'matthewsorderplugin' ); ?></th><td><?php echo esc_html( $order['company_snapshot'] ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Contact', 'matthewsorderplugin' ); ?></th><td><?php echo esc_html( $contact ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Email', 'matthewsorderplugin' ); ?></th><td><?php echo esc_html( $order['email_snapshot'] ); ?></td></tr>
                    <tr>
                        <th><?php esc_html_e( 'Ship To', 'matthewsorderplugin' ); ?></th>
                        <td>
                            <?php
                            $csz = trim( ( $order['ship_to_city_snapshot'] ?? '' ) . ', ' . ( $order['ship_to_state_snapshot'] ?? '' ) . ' ' . ( $order['ship_to_zip_snapshot'] ?? '' ), ' ,' );
                            $parts = array_filter( [ $order['ship_to_line1_snapshot'], $order['ship_to_line2_snapshot'], $csz ] );
                            echo esc_html( implode( ' / ', $parts ) ?: '—' );
                            ?>
                        </td>
                    </tr>
                    <?php if ( ! empty( $order['comments'] ) ) : ?>
                    <tr><th><?php esc_html_e( 'Comments', 'matthewsorderplugin' ); ?></th><td style="white-space:pre-wrap;"><?php echo esc_html( $order['comments'] ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:1.5rem;"><?php esc_html_e( 'Lines', 'matthewsorderplugin' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th><?php esc_html_e( 'FMM Item', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'matthewsorderplugin' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Qty (Selling)', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Selling UoM', 'matthewsorderplugin' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Qty (Base)', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Base UoM', 'matthewsorderplugin' ); ?></th>
                        <th><?php esc_html_e( 'Site', 'matthewsorderplugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $lines as $line ) : ?>
                        <tr>
                            <td><?php echo (int) $line['line_number']; ?></td>
                            <td><code><?php echo esc_html( $line['fmm_item_number'] ); ?></code></td>
                            <td><?php echo esc_html( $line['description'] ); ?></td>
                            <td style="text-align:right;"><?php echo esc_html( self::format_num( $line['qty_selling'] ) ); ?></td>
                            <td><?php echo esc_html( $line['selling_uom'] ); ?></td>
                            <td style="text-align:right;"><?php echo esc_html( self::format_num( $line['qty_base'] ) ); ?></td>
                            <td><?php echo esc_html( $line['base_uom'] ); ?></td>
                            <td><?php echo esc_html( $line['site_id'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Confirmation gate for "Delete all orders". Admin must type DELETE
     * (case-insensitive) into the text input — anything else bounces back
     * here with an error notice. The actual deletion happens in
     * MOP_Handlers::mop_delete_all_orders().
     */
    private static function render_delete_all_confirm() {
        $orders   = MOP_Order::all_with_summary();
        $count    = count( $orders );
        $list_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $mismatch = isset( $_GET['mop_error'] ) && $_GET['mop_error'] === 'confirm_text_mismatch';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Delete all orders', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to orders', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <?php if ( $count === 0 ) : ?>
                <div class="notice notice-info"><p><?php esc_html_e( 'There are no orders to delete.', 'matthewsorderplugin' ); ?></p></div>
                <p><a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Back to orders', 'matthewsorderplugin' ); ?></a></p>
                <?php
                return;
            endif;
            ?>

            <?php if ( $mismatch ) : ?>
                <div class="notice notice-error"><p>
                    <?php esc_html_e( 'You must type DELETE exactly to confirm. Nothing was deleted.', 'matthewsorderplugin' ); ?>
                </p></div>
            <?php endif; ?>

            <div class="notice notice-error inline" style="padding:0.75rem 1rem;">
                <p>
                    <strong><?php
                        printf(
                            esc_html( _n(
                                'This will permanently delete %d order, all of its line items, and its ORDIMP.dat file.',
                                'This will permanently delete %d orders, all of their line items, and their ORDIMP.dat files.',
                                $count,
                                'matthewsorderplugin'
                            ) ),
                            (int) $count
                        );
                    ?></strong>
                    <?php esc_html_e( 'There is no undo. Customer accounts and product catalog are not affected.', 'matthewsorderplugin' ); ?>
                </p>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5rem;">
                <input type="hidden" name="action" value="mop_delete_all_orders">
                <?php wp_nonce_field( 'mop_delete_all_orders' ); ?>

                <p>
                    <label for="mop-confirm-delete-text"><strong><?php esc_html_e( 'Type DELETE to confirm', 'matthewsorderplugin' ); ?></strong></label><br>
                    <input
                        type="text"
                        id="mop-confirm-delete-text"
                        name="confirm_text"
                        class="regular-text"
                        autocomplete="off"
                        autocapitalize="characters"
                        placeholder="DELETE"
                        required
                        style="font-family:monospace; letter-spacing:0.1em;">
                </p>
                <p>
                    <button type="submit" class="button button-primary" style="background:#a00; border-color:#900;">
                        <?php printf( esc_html__( 'Permanently delete %d order(s)', 'matthewsorderplugin' ), (int) $count ); ?>
                    </button>
                    <a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Cancel', 'matthewsorderplugin' ); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    private static function render_notices() {
        $notice = isset( $_GET['mop_notice'] ) ? sanitize_key( $_GET['mop_notice'] ) : '';
        if ( $notice === 'order_deleted' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Order deleted.', 'matthewsorderplugin' ) . '</p></div>';
            return;
        }
        if ( $notice === 'order_not_found' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'That order could not be found — it may have already been deleted.', 'matthewsorderplugin' ) . '</p></div>';
            return;
        }
        if ( $notice === 'all_orders_deleted' ) {
            $n = isset( $_GET['mop_orders_deleted_n'] ) ? (int) $_GET['mop_orders_deleted_n'] : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
                sprintf( _n( '%d order deleted.', '%d orders deleted.', $n, 'matthewsorderplugin' ), $n )
            ) . '</p></div>';
        }
    }

    private static function format_num( $val ) {
        $out = rtrim( rtrim( number_format( (float) $val, 4, '.', '' ), '0' ), '.' );
        return $out === '' ? '0' : $out;
    }
}
