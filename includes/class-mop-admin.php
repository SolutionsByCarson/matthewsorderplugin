<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress admin menu + screen routing.
 *
 * Editors and administrators can manage plugin data. Each submenu hosts a
 * WP_List_Table-backed screen, with CSV import/export where appropriate
 * (Users + Products) and ORDIMP download on the Orders screen.
 *
 * Phase 1 stub — screen callbacks render placeholders. Real list tables land
 * in Phase 3 once schema is finalized.
 */
class MOP_Admin {

    const CAPABILITY = 'edit_pages'; // Editors + administrators.

    public static function init() {
        add_action( 'admin_menu',           [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
    }

    /**
     * Enqueue admin scripts only on this plugin's screens. The shared
     * mop-admin-search.js powers the instant-filter inputs on all list
     * views without an AJAX round trip.
     */
    public static function enqueue_admin_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        if ( strpos( $page, 'mop_' ) !== 0 ) {
            return;
        }
        wp_enqueue_script(
            'mop-admin-search',
            MOP_PLUGIN_URL . 'assets/js/admin-search.js',
            [],
            MOP_VERSION,
            true
        );
        wp_add_inline_style( 'wp-admin', self::admin_inline_css() );
    }

    /**
     * Lightweight inline CSS shared across plugin admin screens — keeps
     * the back-to-dashboard pill and table-filter widget consistent
     * without spinning up a separate stylesheet.
     */
    private static function admin_inline_css() {
        // Inline a small chevron SVG (URL-encoded) so it travels with the
        // stylesheet — no separate asset request. #50575e matches the WP
        // admin form text color.
        $chevron = "data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2012%208%22%20fill%3D%22none%22%20stroke%3D%22%2350575e%22%20stroke-width%3D%221.6%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22M1%201.5l5%205%205-5%22%2F%3E%3C%2Fsvg%3E";
        return '
            .mop-breadcrumb {
                margin: .5rem 0 .25rem;
                font-size: 12px;
                line-height: 1.4;
            }
            .mop-breadcrumb__link {
                color: #50575e;
                text-decoration: none;
            }
            .mop-breadcrumb__link:hover,
            .mop-breadcrumb__link:focus {
                color: #2b2976;
                text-decoration: underline;
            }
            .mop-table-filter-wrap {
                display: flex; align-items: center; gap: .5rem;
                margin: 0 0 .75rem; padding: .5rem 0;
            }
            .mop-table-filter-wrap input.mop-table-filter {
                min-width: 320px;
            }
            .mop-table-filter__count {
                color: #50575e; font-size: 12.5px;
            }
            .mop-table-filter__clear {
                color: #2271b1; cursor: pointer;
                text-decoration: none; font-size: 12.5px;
                display: none;
            }
            .mop-table-filter__clear:hover { color: #135e96; }

            /* Replace the browser-native datalist chevron with a consistent
               custom one. Hides the wonky Chrome indicator and paints our
               own via background-image. */
            input[list] {
                background-image: url("' . $chevron . '") !important;
                background-repeat: no-repeat !important;
                background-position: right 8px center !important;
                background-size: 10px !important;
                padding-right: 26px !important;
                appearance: textfield;
                -webkit-appearance: textfield;
            }
            input[list]::-webkit-calendar-picker-indicator {
                opacity: 0;
                width: 24px;
                height: 100%;
                position: absolute;
                right: 0;
                cursor: pointer;
            }
            /* Anchor positioning so the invisible indicator sits over the
               custom arrow region. */
            input[list] {
                position: relative;
            }
        ';
    }

    public static function register_menu() {
        add_menu_page(
            __( 'Matthews Orders', 'matthewsorderplugin' ),
            __( 'Matthews Orders', 'matthewsorderplugin' ),
            self::CAPABILITY,
            'mop_dashboard',
            [ __CLASS__, 'render_dashboard' ],
            'dashicons-clipboard',
            30
        );

        add_submenu_page( 'mop_dashboard', __( 'Customers', 'matthewsorderplugin' ), __( 'Customers', 'matthewsorderplugin' ), self::CAPABILITY, 'mop_customers', [ __CLASS__, 'render_customers' ] );
        add_submenu_page( 'mop_dashboard', __( 'Users',     'matthewsorderplugin' ), __( 'Users',     'matthewsorderplugin' ), self::CAPABILITY, 'mop_users',     [ __CLASS__, 'render_users' ] );
        add_submenu_page( 'mop_dashboard', __( 'Products',  'matthewsorderplugin' ), __( 'Products',  'matthewsorderplugin' ), self::CAPABILITY, 'mop_products',  [ __CLASS__, 'render_products' ] );
        add_submenu_page( 'mop_dashboard', __( 'Orders',    'matthewsorderplugin' ), __( 'Orders',    'matthewsorderplugin' ), self::CAPABILITY, 'mop_orders',    [ __CLASS__, 'render_orders' ] );
        add_submenu_page( 'mop_dashboard', __( 'Settings',  'matthewsorderplugin' ), __( 'Settings',  'matthewsorderplugin' ), 'manage_options', 'mop_settings',  [ 'MOP_Settings', 'render_page' ] );
    }

    public static function render_customers() {
        MOP_Admin_Customers::render();
    }

    /**
     * Small breadcrumb-style link back to the plugin dashboard. Emits a
     * <p> wrapper so callers can drop it as a block above the page title;
     * placement convention is the first child of <div class="wrap">.
     */
    public static function back_to_dashboard_link() {
        $url = admin_url( 'admin.php?page=mop_dashboard' );
        return '<p class="mop-breadcrumb"><a href="' . esc_url( $url ) . '" class="mop-breadcrumb__link">'
            . esc_html__( '← Back to Dashboard', 'matthewsorderplugin' )
            . '</a></p>';
    }

    /**
     * Reusable instant-filter widget. Echo above the list table; the
     * shared admin-search.js auto-attaches via the .mop-table-filter
     * class. $target is the row selector to filter; $group is an
     * optional wrapper selector for multi-table screens (Products).
     */
    public static function render_table_filter( $target = '.wp-list-table tbody tr', $group = '', $placeholder = '' ) {
        if ( $placeholder === '' ) {
            $placeholder = __( 'Filter rows…', 'matthewsorderplugin' );
        }
        ?>
        <div class="mop-table-filter-wrap">
            <input
                type="search"
                class="mop-table-filter"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                data-target="<?php echo esc_attr( $target ); ?>"
                <?php if ( $group !== '' ) : ?>data-group="<?php echo esc_attr( $group ); ?>"<?php endif; ?>
                autocomplete="off"
                aria-label="<?php echo esc_attr( $placeholder ); ?>">
            <span class="mop-table-filter__count" aria-live="polite"></span>
            <a href="#" class="mop-table-filter__clear"><?php esc_html_e( 'Clear', 'matthewsorderplugin' ); ?></a>
        </div>
        <?php
    }

    public static function render_dashboard() {
        global $wpdb;
        $customer_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MOP_Customer::table() );
        $user_count     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MOP_User::table() );
        $product_count  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MOP_Product::table() );
        $order_count    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MOP_Order::table() );

        $cards = [
            [
                'slug'        => 'mop_customers',
                'icon'        => 'dashicons-groups',
                'title'       => __( 'Customers', 'matthewsorderplugin' ),
                'description' => __( 'Manage customer accounts — one row per FMM Customer Number. Bulk-import customers and their login users from a single CSV.', 'matthewsorderplugin' ),
                'count'       => $customer_count,
                'count_label' => _n( 'customer', 'customers', $customer_count, 'matthewsorderplugin' ),
            ],
            [
                'slug'        => 'mop_users',
                'icon'        => 'dashicons-admin-users',
                'title'       => __( 'Users', 'matthewsorderplugin' ),
                'description' => __( 'Manage login accounts. The same email can be attached to multiple customers; users pick which one to act for at sign-in.', 'matthewsorderplugin' ),
                'count'       => $user_count,
                'count_label' => _n( 'user', 'users', $user_count, 'matthewsorderplugin' ),
            ],
            [
                'slug'        => 'mop_products',
                'icon'        => 'dashicons-products',
                'title'       => __( 'Products', 'matthewsorderplugin' ),
                'description' => __( 'Item catalog customers see on the order form. Bulk-import from the client\'s Order Import Item List CSV.', 'matthewsorderplugin' ),
                'count'       => $product_count,
                'count_label' => _n( 'product', 'products', $product_count, 'matthewsorderplugin' ),
            ],
            [
                'slug'        => 'mop_orders',
                'icon'        => 'dashicons-clipboard',
                'title'       => __( 'Orders', 'matthewsorderplugin' ),
                'description' => __( 'Submitted web orders. Download the ORDIMP.dat for each order, export the full ledger as CSV, or remove orders that should not flow into FMM.', 'matthewsorderplugin' ),
                'count'       => $order_count,
                'count_label' => _n( 'order', 'orders', $order_count, 'matthewsorderplugin' ),
            ],
            [
                'slug'        => 'mop_settings',
                'icon'        => 'dashicons-admin-settings',
                'title'       => __( 'Settings', 'matthewsorderplugin' ),
                'description' => __( 'Shortcode page URL and admin email destination. Required for the front-end to know where to redirect and where to send admin notifications.', 'matthewsorderplugin' ),
                'count'       => null,
                'count_label' => '',
                'cap'         => 'manage_options',
            ],
        ];
        ?>
        <div class="wrap mop-dashboard">
            <h1><?php esc_html_e( 'Matthews Orders', 'matthewsorderplugin' ); ?></h1>
            <p class="description" style="max-width:780px;">
                <?php esc_html_e( 'Customer order submission for Matthews Feed and Grain. Web orders write a Feed Mill Manager-compatible ORDIMP.dat file on submit, which the admin team drops into the FMM import path.', 'matthewsorderplugin' ); ?>
            </p>

            <style>
                .mop-dashboard-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                    gap: 16px;
                    margin-top: 1.25rem;
                    max-width: 1100px;
                }
                .mop-card {
                    display: flex;
                    flex-direction: column;
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-radius: 4px;
                    padding: 1.25rem 1.25rem 1rem;
                    text-decoration: none;
                    color: inherit;
                    transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
                    min-height: 180px;
                }
                .mop-card:hover, .mop-card:focus {
                    border-color: #2b2976;
                    box-shadow: 0 2px 8px rgba(43, 41, 118, .12);
                    transform: translateY(-1px);
                }
                .mop-card__head {
                    display: flex;
                    align-items: center;
                    gap: .75rem;
                    margin-bottom: .5rem;
                }
                .mop-card__icon {
                    color: #2b2976;
                    font-size: 28px;
                    width: 28px;
                    height: 28px;
                }
                .mop-card__title {
                    font-size: 17px;
                    font-weight: 600;
                    margin: 0;
                    color: #1d2327;
                }
                .mop-card__count {
                    margin-left: auto;
                    background: #f0f0f4;
                    color: #2b2976;
                    font-weight: 600;
                    font-size: 12px;
                    padding: 2px 10px;
                    border-radius: 999px;
                    white-space: nowrap;
                }
                .mop-card__desc {
                    color: #50575e;
                    font-size: 13px;
                    line-height: 1.5;
                    margin: 0;
                    flex-grow: 1;
                }
                .mop-card__cta {
                    margin-top: .9rem;
                    font-size: 12.5px;
                    font-weight: 600;
                    color: #2b2976;
                }
                .mop-card__cta .dashicons {
                    font-size: 16px;
                    width: 16px;
                    height: 16px;
                    line-height: 1;
                    vertical-align: -3px;
                }
            </style>

            <div class="mop-dashboard-grid">
                <?php foreach ( $cards as $card ) :
                    $required_cap = $card['cap'] ?? self::CAPABILITY;
                    if ( ! current_user_can( $required_cap ) ) {
                        continue;
                    }
                    $url = admin_url( 'admin.php?page=' . $card['slug'] );
                    ?>
                    <a class="mop-card" href="<?php echo esc_url( $url ); ?>">
                        <div class="mop-card__head">
                            <span class="dashicons <?php echo esc_attr( $card['icon'] ); ?> mop-card__icon" aria-hidden="true"></span>
                            <h2 class="mop-card__title"><?php echo esc_html( $card['title'] ); ?></h2>
                            <?php if ( $card['count'] !== null ) : ?>
                                <span class="mop-card__count">
                                    <?php
                                    printf(
                                        /* translators: 1: count, 2: singular/plural label */
                                        esc_html__( '%1$d %2$s', 'matthewsorderplugin' ),
                                        (int) $card['count'],
                                        esc_html( $card['count_label'] )
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="mop-card__desc"><?php echo esc_html( $card['description'] ); ?></p>
                        <span class="mop-card__cta">
                            <?php
                            /* translators: %s: card title */
                            printf( esc_html__( 'Open %s', 'matthewsorderplugin' ), esc_html( $card['title'] ) );
                            ?>
                            <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public static function render_users() {
        MOP_Admin_Users::render();
    }

    public static function render_products() {
        MOP_Admin_Products::render();
    }

    public static function render_orders() {
        MOP_Admin_Orders::render();
    }
}
