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
        self::render_list();
    }

    private static function render_list() {
        $orders = MOP_Order::all_with_summary();
        $csv_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_orders_csv' ], admin_url( 'admin-post.php' ) ),
            'mop_orders_csv'
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Orders', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $csv_url ); ?>" class="page-title-action">
                <?php esc_html_e( 'Export CSV', 'matthewsorderplugin' ); ?>
            </a>
            <hr class="wp-header-end">

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
                    $has_file = ! empty( $order['ordimp_path'] ) && file_exists( $order['ordimp_path'] );
                    $contact  = trim( ( $order['contact_first_name_snapshot'] ?? '' ) . ' ' . ( $order['contact_last_name_snapshot'] ?? '' ) );
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $order['po_number'] ); ?></a></strong>
                            <div class="row-actions">
                                <span class="view"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'matthewsorderplugin' ); ?></a></span>
                                <?php if ( $has_file ) : ?>
                                    | <span class="download"><a href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Download', 'matthewsorderplugin' ); ?></a></span>
                                <?php endif; ?>
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

    private static function format_num( $val ) {
        $out = rtrim( rtrim( number_format( (float) $val, 4, '.', '' ), '0' ), '.' );
        return $out === '' ? '0' : $out;
    }
}
