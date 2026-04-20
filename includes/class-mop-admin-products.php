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
        self::render_list();
    }

    private static function render_list() {
        $grouped = MOP_Product::all_grouped_by_category();
        $new_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Products', 'matthewsorderplugin' ); ?></h1>
            <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'matthewsorderplugin' ); ?></a>
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
                            <th><?php esc_html_e( 'Description', 'matthewsorderplugin' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( 'Selling UoM', 'matthewsorderplugin' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( 'Base UoM', 'matthewsorderplugin' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( 'Conversion', 'matthewsorderplugin' ); ?></th>
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
                            <td><?php echo esc_html( $p['selling_uom'] ); ?></td>
                            <td><?php echo esc_html( $p['base_uom'] ); ?></td>
                            <td><?php echo esc_html( rtrim( rtrim( (string) $p['conversion_factor'], '0' ), '.' ) ); ?></td>
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
                        <th><label for="description"><?php esc_html_e( 'Description', 'matthewsorderplugin' ); ?> <span class="description">(required)</span></label></th>
                        <td>
                            <input name="description" id="description" type="text" maxlength="50" class="regular-text" value="<?php echo esc_attr( $v( 'description' ) ); ?>" required>
                            <p class="description"><?php esc_html_e( 'Shown to customers and written to the ORDIMP line description. Max 50 chars.', 'matthewsorderplugin' ); ?></p>
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
                        <th><label for="selling_uom"><?php esc_html_e( 'Selling UoM', 'matthewsorderplugin' ); ?> <span class="description">(required)</span></label></th>
                        <td>
                            <input name="selling_uom" id="selling_uom" type="text" maxlength="20" class="regular-text" value="<?php echo esc_attr( $v( 'selling_uom' ) ); ?>" required>
                            <p class="description"><?php esc_html_e( 'What the customer orders in: BAG-50, POUND, EACH, QT, GAL, CASE, etc.', 'matthewsorderplugin' ); ?></p>
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
                </table>

                <?php submit_button( $is_new ? __( 'Create product', 'matthewsorderplugin' ) : __( 'Save product', 'matthewsorderplugin' ) ); ?>
            </form>
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
            'item_number_required' => __( 'FMM item number is required.', 'matthewsorderplugin' ),
            'description_required' => __( 'Description is required.', 'matthewsorderplugin' ),
            'item_number_in_use'   => __( 'Another product already has that FMM item number.', 'matthewsorderplugin' ),
            'not_found'            => __( 'Product not found.', 'matthewsorderplugin' ),
        ];
        if ( $notice && isset( $notices[ $notice ] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
        }
        if ( $error && isset( $errors[ $error ] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
        }
    }
}
