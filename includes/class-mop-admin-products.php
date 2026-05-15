<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin → Matthews Orders → Products.
 *
 * Mirrors the pattern of MOP_Admin_Users: list + form + row actions, POST
 * routed through MOP_Handlers. base_uom is a controlled select (POUND /
 * EACH) because FMM only accepts those two as Record 200 pos 8 values
 * matching its base unit. selling_uom is free-text per the live order
 * form conventions.
 */
class MOP_Admin_Products {

    const PAGE_SLUG = 'mop_products';

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
        $grouped     = MOP_Product::all_grouped_by_category();
        $new_url     = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
        $import_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=import' );
        $export_url  = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_products_csv_export' ], admin_url( 'admin-post.php' ) ),
            'mop_products_csv_export'
        );
        $example_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_products_csv_example' ], admin_url( 'admin-post.php' ) ),
            'mop_products_csv_example'
        );
        $wipe_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_products_wipe_seed' ], admin_url( 'admin-post.php' ) ),
            'mop_products_wipe_seed'
        );

        // Show the "Wipe seed" button only if at least one seed-shaped row exists.
        global $wpdb;
        $has_seed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . MOP_Product::table() . " WHERE
                fmm_item_number LIKE 'LIN-%' OR
                fmm_item_number LIKE 'SUN-%' OR
                fmm_item_number LIKE 'MFG-%' OR
                fmm_item_number LIKE 'SR-%'  OR
                fmm_item_number LIKE 'SHO-%'"
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Products', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'matthewsorderplugin' ); ?></a>
            <a href="<?php echo esc_url( $import_url ); ?>" class="page-title-action"><?php esc_html_e( 'Import CSV', 'matthewsorderplugin' ); ?></a>
            <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'matthewsorderplugin' ); ?></a>
            <a href="<?php echo esc_url( $example_url ); ?>" class="page-title-action"><?php esc_html_e( 'Download example CSV', 'matthewsorderplugin' ); ?></a>
            <?php if ( $has_seed > 0 ) : ?>
                <a href="<?php echo esc_url( $wipe_url ); ?>" class="page-title-action"
                   onclick="return confirm('<?php echo esc_js( sprintf( __( 'Delete %d placeholder seed products? Order history is preserved.', 'matthewsorderplugin' ), $has_seed ) ); ?>');">
                    <?php printf( esc_html__( 'Wipe placeholder seed (%d)', 'matthewsorderplugin' ), (int) $has_seed ); ?>
                </a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php self::render_notices(); ?>

            <?php if ( empty( $grouped ) ) : ?>
                <p><?php esc_html_e( 'No products yet. Click "Add New" to create one.', 'matthewsorderplugin' ); ?></p>
            <?php endif; ?>

            <?php foreach ( $grouped as $category => $products ) : ?>
                <h2><?php echo esc_html( $category ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:180px;"><?php esc_html_e( 'FMM item number', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'FMM description', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Web description', 'matthewsorderplugin' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( 'Selling UoM', 'matthewsorderplugin' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( 'Base UoM', 'matthewsorderplugin' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( 'Conversion', 'matthewsorderplugin' ); ?></th>
                            <th style="width:50px;" title="<?php esc_attr_e( 'VFD/AOD required', 'matthewsorderplugin' ); ?>">VFD</th>
                            <th style="width:80px;"><?php esc_html_e( 'Sort', 'matthewsorderplugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $products as $p ) :
                        $edit_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . (int) $p['id'] );
                        $delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=mop_delete_product&id=' . (int) $p['id'] ), 'mop_delete_product_' . (int) $p['id'] );
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $p['fmm_item_number'] ); ?></strong>
                                <div class="row-actions">
                                    <span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'matthewsorderplugin' ); ?></a> | </span>
                                    <span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this product?', 'matthewsorderplugin' ) ); ?>');"><?php esc_html_e( 'Delete', 'matthewsorderplugin' ); ?></a></span>
                                </div>
                            </td>
                            <td><?php echo esc_html( $p['description'] ); ?></td>
                            <td>
                                <?php
                                $web = (string) ( $p['web_description'] ?? '' );
                                if ( $web !== '' ) {
                                    echo esc_html( $web );
                                } else {
                                    echo '<span class="description">—</span>';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( $p['selling_uom'] ); ?></td>
                            <td><?php echo esc_html( $p['base_uom'] ); ?></td>
                            <td><?php echo esc_html( rtrim( rtrim( (string) $p['conversion_factor'], '0' ), '.' ) ); ?></td>
                            <td><?php echo ! empty( $p['requires_vfd'] ) ? '<span class="dashicons dashicons-yes" style="color:#a00;" title="VFD/AOD required"></span>' : ''; ?></td>
                            <td><?php echo esc_html( $p['sort_order'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function render_form() {
        $id      = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $product = $id ? MOP_Product::find( $id ) : null;
        $is_new  = ! $product;

        $v = function ( $key, $default = '' ) use ( $product ) {
            if ( $product && isset( $product[ $key ] ) && $product[ $key ] !== null ) {
                return $product[ $key ];
            }
            return $default;
        };
        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? esc_html__( 'Add Product', 'matthewsorderplugin' ) : esc_html__( 'Edit Product', 'matthewsorderplugin' ); ?></h1>

            <?php self::render_notices(); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mop_save_product">
                <input type="hidden" name="id"     value="<?php echo esc_attr( $id ); ?>">
                <?php wp_nonce_field( 'mop_save_product' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="fmm_item_number"><?php esc_html_e( 'FMM item number', 'matthewsorderplugin' ); ?> <span class="description">(required)</span></label></th>
                        <td>
                            <input name="fmm_item_number" id="fmm_item_number" type="text" maxlength="30" class="regular-text" value="<?php echo esc_attr( $v( 'fmm_item_number' ) ); ?>" required>
                            <p class="description"><?php esc_html_e( 'Must match the item number in FMM exactly. Stored in upper case. Max 30 chars.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="description"><?php esc_html_e( 'FMM description', 'matthewsorderplugin' ); ?> <span class="description">(required)</span></label></th>
                        <td>
                            <input name="description" id="description" type="text" maxlength="50" class="regular-text" value="<?php echo esc_attr( $v( 'description' ) ); ?>" required>
                            <p class="description"><?php esc_html_e( 'FMM canonical name. Written to the ORDIMP line description (Record 200 pos 5). Max 50 chars.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="web_description"><?php esc_html_e( 'Web description', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <input name="web_description" id="web_description" type="text" maxlength="100" class="regular-text" value="<?php echo esc_attr( $v( 'web_description' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Customer-facing name on the order form. Leave blank to use the FMM description above. Max 100 chars.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="category"><?php esc_html_e( 'Category', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <input name="category" id="category" type="text" maxlength="100" class="regular-text" value="<?php echo esc_attr( $v( 'category' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Free-text grouping shown on the order form (e.g. "Lindner Feed", "Sunglo Feed").', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sort_order"><?php esc_html_e( 'Sort order', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <input name="sort_order" id="sort_order" type="number" step="1" value="<?php echo esc_attr( $v( 'sort_order', 0 ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Lower = earlier. Controls both within-category order AND category order (category with the lowest minimum sort_order appears first).', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="uom_schedule"><?php esc_html_e( 'UoM schedule', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <input name="uom_schedule" id="uom_schedule" type="text" maxlength="20" class="regular-text" value="<?php echo esc_attr( $v( 'uom_schedule' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'FMM UoM schedule (e.g. POUND-4, EACH-4). The prefix before the dash is the base UoM. Stored verbatim for round-trip; ORDIMP only writes the prefix.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="selling_uom"><?php esc_html_e( 'Selling UoM', 'matthewsorderplugin' ); ?> <span class="description">(required)</span></label></th>
                        <td>
                            <input name="selling_uom" id="selling_uom" type="text" maxlength="20" class="regular-text" value="<?php echo esc_attr( $v( 'selling_uom' ) ); ?>" required>
                            <p class="description"><?php esc_html_e( 'What the customer orders in: BAG-50, POUND, EACH, PAIL-20, CASE, etc.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="base_uom"><?php esc_html_e( 'Base UoM', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <?php $base = $v( 'base_uom', 'POUND' ); ?>
                            <select name="base_uom" id="base_uom">
                                <option value="POUND" <?php selected( $base, 'POUND' ); ?>>POUND</option>
                                <option value="EACH"  <?php selected( $base, 'EACH' );  ?>>EACH</option>
                            </select>
                            <p class="description"><?php esc_html_e( 'What FMM expects in Record 200 pos 8. Use EACH for countable items (pails, bottles, tubes); POUND for feed sold by weight.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="conversion_factor"><?php esc_html_e( 'Conversion factor', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <input name="conversion_factor" id="conversion_factor" type="number" step="0.0001" min="0" value="<?php echo esc_attr( $v( 'conversion_factor', 1 ) ); ?>" required>
                            <p class="description"><?php esc_html_e( 'qty_selling × conversion_factor = qty_base. Example: BAG-50 → POUND = 50. POUND → POUND = 1. EACH → EACH = 1.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="site_id"><?php esc_html_e( 'Site ID', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <input name="site_id" id="site_id" type="text" maxlength="10" value="<?php echo esc_attr( $v( 'site_id', 'MATTHEWS' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'FMM Site ID — defaults to MATTHEWS.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'VFD/AOD required', 'matthewsorderplugin' ); ?></th>
                        <td><label><input type="checkbox" name="requires_vfd" value="1" <?php checked( (int) $v( 'requires_vfd', 0 ), 1 ); ?>> <?php esc_html_e( 'This item requires a Veterinary Feed Directive or AOD.', 'matthewsorderplugin' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="minimum_order_qty"><?php esc_html_e( 'Minimum order', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <input name="minimum_order_qty" id="minimum_order_qty" type="text" maxlength="40" class="regular-text" value="<?php echo esc_attr( $v( 'minimum_order_qty' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Free text shown on the order form. E.g. "1 TON MINIMUM", "NO MINIMUM". Leave blank if there\'s no minimum messaging to display.', 'matthewsorderplugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sold_individually"><?php esc_html_e( 'Sold individually', 'matthewsorderplugin' ); ?></label></th>
                        <td>
                            <?php $si = $v( 'sold_individually', '' ); ?>
                            <select name="sold_individually" id="sold_individually">
                                <option value=""  <?php selected( $si === '' || $si === null, true ); ?>><?php esc_html_e( '— Not set —', 'matthewsorderplugin' ); ?></option>
                                <option value="1" <?php selected( (string) $si, '1' ); ?>><?php esc_html_e( 'Yes', 'matthewsorderplugin' ); ?></option>
                                <option value="0" <?php selected( (string) $si, '0' ); ?>><?php esc_html_e( 'No',  'matthewsorderplugin' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button( $is_new ? __( 'Create product', 'matthewsorderplugin' ) : __( 'Save product', 'matthewsorderplugin' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Step 1 of import: upload form. The example-CSV link is right here so
     * the admin can grab the template, "Save As CSV" from Excel, and
     * re-upload. Includes a Wipe-and-replace toggle for the first real
     * import after the placeholder seed.
     */
    private static function render_import() {
        $list_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $example_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'mop_products_csv_example' ], admin_url( 'admin-post.php' ) ),
            'mop_products_csv_example'
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Import products from CSV', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to products', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <?php self::render_notices(); ?>

            <p>
                <?php esc_html_e( 'Save the client\'s Order Import Item List spreadsheet as CSV (Excel: File → Save As → CSV UTF-8) and upload it here. The first row must be the column headers.', 'matthewsorderplugin' ); ?>
                <a href="<?php echo esc_url( $example_url ); ?>"><?php esc_html_e( 'Download example CSV', 'matthewsorderplugin' ); ?></a>
            </p>

            <div class="notice notice-warning inline" style="padding:0.75rem 1rem;">
                <p>
                    <strong><?php esc_html_e( 'FMM item number is the unique key.', 'matthewsorderplugin' ); ?></strong>
                    <?php esc_html_e( 'If a row matches an existing product\'s FMM item number, the CSV-sourced columns will be overwritten. Admin-controlled fields (sort order, site ID) are kept as-is on updates. A blank category column preserves the existing category — only a non-empty value overwrites it.', 'matthewsorderplugin' ); ?>
                </p>
            </div>

            <h2><?php esc_html_e( 'Required columns', 'matthewsorderplugin' ); ?></h2>
            <ul style="list-style:disc; margin-left:1.5em;">
                <li><code>fmm_item_number</code> — <?php esc_html_e( 'unique key, upper-cased on save, max 30 chars', 'matthewsorderplugin' ); ?></li>
                <li><code>fmm_description</code> — <?php esc_html_e( 'canonical FMM name; max 50 chars; written to ORDIMP Record 200 pos 5', 'matthewsorderplugin' ); ?></li>
                <li><code>uom_schedule</code> — <?php esc_html_e( 'must resolve to POUND or EACH (FMM only accepts those). Examples: POUND-4, EACH-4', 'matthewsorderplugin' ); ?></li>
                <li><code>selling_uom</code> — <?php esc_html_e( 'POUND, EACH, or PREFIX-NUMBER like BAG-50 / PAIL-20. Conversion factor is derived from the trailing number.', 'matthewsorderplugin' ); ?></li>
            </ul>

            <h2><?php esc_html_e( 'Optional columns', 'matthewsorderplugin' ); ?></h2>
            <ul style="list-style:disc; margin-left:1.5em;">
                <li><code>web_description</code> — <?php esc_html_e( 'customer-facing display name; falls back to fmm_description on the order form', 'matthewsorderplugin' ); ?></li>
                <li><code>category</code> — <?php esc_html_e( 'free-text grouping shown on the order form (e.g. "Lindner Feed"). Blank = preserve existing on update / null on create.', 'matthewsorderplugin' ); ?></li>
                <li><code>vfd_required</code> — <?php esc_html_e( 'accepts "VFD/AOD REQUIRED", YES, 1, or blank', 'matthewsorderplugin' ); ?></li>
                <li><code>minimum_order</code> — <?php esc_html_e( 'free text like "1 TON MINIMUM" / "NO MINIMUM" / blank', 'matthewsorderplugin' ); ?></li>
                <li><code>sold_individually</code> — <?php esc_html_e( 'YES / NO / blank', 'matthewsorderplugin' ); ?></li>
            </ul>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:1.5rem;">
                <input type="hidden" name="action" value="mop_products_import_preview">
                <?php wp_nonce_field( 'mop_products_import_preview' ); ?>
                <p>
                    <input type="file" name="csv_file" accept=".csv,text/csv" required>
                </p>
                <p>
                    <label style="font-weight:600; color:#a00;">
                        <input type="checkbox" name="wipe_mode" value="1">
                        <?php esc_html_e( 'Wipe ALL existing products before applying (use only for the first real import — order history is preserved)', 'matthewsorderplugin' ); ?>
                    </label>
                </p>
                <?php submit_button( __( 'Continue to preview', 'matthewsorderplugin' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Step 2 of import: preview screen. Shows totals + per-row tables for
     * each bucket (create / update / errors / normalization notices) and a
     * confirmation form gated by a checkbox for updates and a second red
     * checkbox if wipe mode is on.
     */
    private static function render_import_preview() {
        $token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        $parsed = $token ? get_transient( 'mop_products_import_' . $token ) : null;
        $list_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $cancel_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=import' );

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

        $create_count  = count( $parsed['create'] );
        $update_count  = count( $parsed['update'] );
        $error_count   = count( $parsed['errors'] );
        $notice_count  = count( $parsed['notices'] ?? [] );
        $applyable     = $create_count + $update_count;
        $wipe          = ! empty( $parsed['wipe_mode'] );

        // In wipe mode, every parsed row will become an insert against an empty table.
        if ( $wipe ) {
            $applyable = $create_count + $update_count;
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Import preview — products', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to products', 'matthewsorderplugin' ); ?></a>
            <hr class="wp-header-end">

            <p style="font-size:1.1em;">
                <?php
                if ( $wipe ) {
                    echo esc_html__( 'WIPE MODE: the products table will be cleared before insert. ', 'matthewsorderplugin' );
                }
                printf(
                    esc_html__( '%1$d new products will be created, %2$d existing products will be overwritten, %3$d rows have errors and will be skipped.', 'matthewsorderplugin' ),
                    (int) $create_count, (int) $update_count, (int) $error_count
                );
                if ( $notice_count > 0 ) {
                    echo ' ' . esc_html( sprintf(
                        _n( '%d normalization notice.', '%d normalization notices.', $notice_count, 'matthewsorderplugin' ),
                        $notice_count
                    ) );
                }
                ?>
            </p>

            <?php if ( $wipe ) : ?>
                <div class="notice notice-error inline" style="padding:0.75rem 1rem;">
                    <p>
                        <strong><?php esc_html_e( 'Wipe mode is ON.', 'matthewsorderplugin' ); ?></strong>
                        <?php esc_html_e( 'All existing products will be deleted before this import is applied. Order history is preserved (orders snapshot product fields at submit time).', 'matthewsorderplugin' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( $update_count > 0 && ! $wipe ) : ?>
                <h2><?php esc_html_e( 'Will be updated', 'matthewsorderplugin' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Existing category, sort order, and site ID are preserved.', 'matthewsorderplugin' ); ?></p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:60px;"><?php esc_html_e( 'Row', 'matthewsorderplugin' ); ?></th>
                            <th style="width:170px;"><?php esc_html_e( 'FMM #', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'FMM description', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Web description', 'matthewsorderplugin' ); ?></th>
                            <th style="width:120px;"><?php esc_html_e( 'Selling UoM', 'matthewsorderplugin' ); ?></th>
                            <th style="width:100px;"><?php esc_html_e( 'Base UoM', 'matthewsorderplugin' ); ?></th>
                            <th style="width:100px;"><?php esc_html_e( 'Factor', 'matthewsorderplugin' ); ?></th>
                            <th style="width:60px;">VFD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $parsed['update'] as $row ) :
                            $web = (string) ( $row['data']['web_description'] ?? '' );
                            ?>
                            <tr>
                                <td><?php echo (int) $row['row']; ?></td>
                                <td><strong><?php echo esc_html( $row['data']['fmm_item_number'] ); ?></strong></td>
                                <td><?php echo esc_html( $row['data']['description'] ); ?></td>
                                <td><?php echo $web !== '' ? esc_html( $web ) : '<span class="description">—</span>'; ?></td>
                                <td><?php echo esc_html( $row['data']['selling_uom'] ); ?></td>
                                <td><?php echo esc_html( $row['data']['base_uom'] ); ?></td>
                                <td><?php echo esc_html( rtrim( rtrim( (string) $row['data']['conversion_factor'], '0' ), '.' ) ); ?></td>
                                <td><?php echo ! empty( $row['data']['requires_vfd'] ) ? '<span class="dashicons dashicons-yes" style="color:#a00;"></span>' : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $create_count > 0 || ( $wipe && $update_count > 0 ) ) :
                $insert_rows = $wipe
                    ? array_merge( $parsed['create'], array_map( function ( $r ) { return [ 'row' => $r['row'], 'data' => $r['incoming'] ]; }, $parsed['update'] ) )
                    : $parsed['create'];
                ?>
                <h2><?php $wipe ? esc_html_e( 'Will be inserted (post-wipe)', 'matthewsorderplugin' ) : esc_html_e( 'Will be created', 'matthewsorderplugin' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:60px;"><?php esc_html_e( 'Row', 'matthewsorderplugin' ); ?></th>
                            <th style="width:170px;"><?php esc_html_e( 'FMM #', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'FMM description', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Web description', 'matthewsorderplugin' ); ?></th>
                            <th style="width:120px;"><?php esc_html_e( 'Selling UoM', 'matthewsorderplugin' ); ?></th>
                            <th style="width:100px;"><?php esc_html_e( 'Base UoM', 'matthewsorderplugin' ); ?></th>
                            <th style="width:100px;"><?php esc_html_e( 'Factor', 'matthewsorderplugin' ); ?></th>
                            <th style="width:60px;">VFD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $insert_rows as $row ) :
                            $web = (string) ( $row['data']['web_description'] ?? '' );
                            ?>
                            <tr>
                                <td><?php echo (int) $row['row']; ?></td>
                                <td><strong><?php echo esc_html( $row['data']['fmm_item_number'] ); ?></strong></td>
                                <td><?php echo esc_html( $row['data']['description'] ); ?></td>
                                <td><?php echo $web !== '' ? esc_html( $web ) : '<span class="description">—</span>'; ?></td>
                                <td><?php echo esc_html( $row['data']['selling_uom'] ); ?></td>
                                <td><?php echo esc_html( $row['data']['base_uom'] ); ?></td>
                                <td><?php echo esc_html( rtrim( rtrim( (string) $row['data']['conversion_factor'], '0' ), '.' ) ); ?></td>
                                <td><?php echo ! empty( $row['data']['requires_vfd'] ) ? '<span class="dashicons dashicons-yes" style="color:#a00;"></span>' : ''; ?></td>
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
                            <th style="width:170px;"><?php esc_html_e( 'FMM #', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Reason', 'matthewsorderplugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $parsed['errors'] as $err ) : ?>
                            <tr>
                                <td><?php echo (int) $err['row']; ?></td>
                                <td><?php echo esc_html( $err['fmm_item_number'] ); ?></td>
                                <td><?php echo esc_html( $err['reason'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $notice_count > 0 ) : ?>
                <h2><?php esc_html_e( 'Normalizations applied', 'matthewsorderplugin' ); ?></h2>
                <p class="description"><?php esc_html_e( 'These rows will import, but values were silently adjusted on the way in. Update the source spreadsheet to silence them in future imports.', 'matthewsorderplugin' ); ?></p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:60px;"><?php esc_html_e( 'Row', 'matthewsorderplugin' ); ?></th>
                            <th style="width:170px;"><?php esc_html_e( 'FMM #', 'matthewsorderplugin' ); ?></th>
                            <th><?php esc_html_e( 'Note', 'matthewsorderplugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $parsed['notices'] as $n ) : ?>
                            <tr>
                                <td><?php echo (int) $n['row']; ?></td>
                                <td><?php echo esc_html( $n['fmm_item_number'] ); ?></td>
                                <td><?php echo esc_html( $n['note'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $applyable === 0 && ! $wipe ) : ?>
                <p style="margin-top:1.5rem;">
                    <a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Start over', 'matthewsorderplugin' ); ?></a>
                </p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5rem;">
                    <input type="hidden" name="action" value="mop_products_import_apply">
                    <input type="hidden" name="token"  value="<?php echo esc_attr( $token ); ?>">
                    <?php wp_nonce_field( 'mop_products_import_apply' ); ?>

                    <?php if ( $update_count > 0 && ! $wipe ) : ?>
                        <p>
                            <label style="font-weight:600;">
                                <input type="checkbox" name="confirm_overwrite" value="1" required>
                                <?php printf( esc_html__( 'I understand this will overwrite %d existing product(s).', 'matthewsorderplugin' ), (int) $update_count ); ?>
                            </label>
                        </p>
                    <?php endif; ?>

                    <?php if ( $wipe ) : ?>
                        <p>
                            <label style="font-weight:600; color:#a00;">
                                <input type="checkbox" name="confirm_wipe" value="1" required>
                                <?php esc_html_e( 'I understand the products table will be CLEARED before import.', 'matthewsorderplugin' ); ?>
                            </label>
                        </p>
                    <?php endif; ?>

                    <p>
                        <button type="submit" class="button button-primary">
                            <?php printf( esc_html__( 'Apply import (%d row(s))', 'matthewsorderplugin' ), (int) $applyable ); ?>
                        </button>
                        <a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'matthewsorderplugin' ); ?></a>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_notices() {
        $notice = isset( $_GET['mop_notice'] ) ? sanitize_key( $_GET['mop_notice'] ) : '';
        $error  = isset( $_GET['mop_error'] )  ? sanitize_key( $_GET['mop_error'] )  : '';

        $notices = [
            'product_created' => __( 'Product created.', 'matthewsorderplugin' ),
            'product_saved'   => __( 'Product saved.', 'matthewsorderplugin' ),
            'product_deleted' => __( 'Product deleted.', 'matthewsorderplugin' ),
        ];
        $errors = [
            'item_number_required'        => __( 'FMM item number is required.', 'matthewsorderplugin' ),
            'description_required'        => __( 'Description is required.', 'matthewsorderplugin' ),
            'item_number_in_use'          => __( 'Another product already has that FMM item number.', 'matthewsorderplugin' ),
            'not_found'                   => __( 'Product not found.', 'matthewsorderplugin' ),
            'no_file'                     => __( 'Please choose a CSV file to upload.', 'matthewsorderplugin' ),
            'upload_failed'               => __( 'The file upload failed. Please try again.', 'matthewsorderplugin' ),
            'read_failed'                 => __( 'Could not read the uploaded file.', 'matthewsorderplugin' ),
            'empty_csv'                   => __( 'That CSV is empty.', 'matthewsorderplugin' ),
            'no_rows'                     => __( 'That CSV had no data rows.', 'matthewsorderplugin' ),
            'missing_fmm_item_number'     => __( 'CSV is missing the required fmm_item_number column.', 'matthewsorderplugin' ),
            'missing_fmm_description'     => __( 'CSV is missing the required fmm_description column.', 'matthewsorderplugin' ),
            'missing_uom_schedule'        => __( 'CSV is missing the required uom_schedule column.', 'matthewsorderplugin' ),
            'missing_selling_uom'         => __( 'CSV is missing the required selling_uom column.', 'matthewsorderplugin' ),
            'token_expired'               => __( 'That import session has expired. Please re-upload.', 'matthewsorderplugin' ),
        ];

        if ( $notice === 'products_imported' ) {
            $added       = isset( $_GET['mop_import_added'] )      ? (int) $_GET['mop_import_added']      : 0;
            $updated     = isset( $_GET['mop_import_updated'] )    ? (int) $_GET['mop_import_updated']    : 0;
            $errored     = isset( $_GET['mop_import_errored'] )    ? (int) $_GET['mop_import_errored']    : 0;
            $normalized  = isset( $_GET['mop_import_normalized'] ) ? (int) $_GET['mop_import_normalized'] : 0;
            $wiped       = isset( $_GET['mop_import_wiped'] )      ? (int) $_GET['mop_import_wiped']      : 0;

            $msg = sprintf(
                __( 'Import complete: %1$d created, %2$d updated, %3$d skipped.', 'matthewsorderplugin' ),
                $added, $updated, $errored
            );
            if ( $wiped > 0 ) {
                $msg .= ' ' . sprintf(
                    __( '%d pre-existing products were wiped before insert.', 'matthewsorderplugin' ),
                    $wiped
                );
            }
            if ( $normalized > 0 ) {
                $msg .= ' ' . sprintf(
                    _n( '%d row was normalized.', '%d rows were normalized.', $normalized, 'matthewsorderplugin' ),
                    $normalized
                );
            }
            echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
            return;
        }

        if ( $notice === 'seed_wiped' ) {
            $deleted = isset( $_GET['mop_seed_deleted'] ) ? (int) $_GET['mop_seed_deleted'] : 0;
            echo '<div class="notice notice-success"><p>' . esc_html( sprintf(
                _n( '%d placeholder seed product deleted.', '%d placeholder seed products deleted.', $deleted, 'matthewsorderplugin' ),
                $deleted
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
