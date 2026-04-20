<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings page + getter/setter for plugin configuration.
 *
 * Configurable values:
 *   shortcode_url — public URL of the page that hosts [matthews_order]; used for
 *                   building links in emails (reset password, etc.).
 *   admin_email   — destination for admin notifications (order submissions,
 *                   password changes, etc.).
 */
class MOP_Settings {

    const OPTION = 'mop_settings';

    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        register_setting( 'mop_settings_group', self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [ __CLASS__, 'sanitize' ],
            'default'           => [
                'shortcode_url' => '',
                'admin_email'   => get_option( 'admin_email' ),
            ],
        ] );

        add_settings_section( 'mop_main', __( 'General', 'matthewsorderplugin' ), '__return_false', 'mop_settings' );

        add_settings_field(
            'shortcode_url',
            __( 'Shortcode URL', 'matthewsorderplugin' ),
            [ __CLASS__, 'field_shortcode_url' ],
            'mop_settings',
            'mop_main'
        );

        add_settings_field(
            'admin_email',
            __( 'Admin Email', 'matthewsorderplugin' ),
            [ __CLASS__, 'field_admin_email' ],
            'mop_settings',
            'mop_main'
        );
    }

    public static function sanitize( $input ) {
        return [
            'shortcode_url' => isset( $input['shortcode_url'] ) ? esc_url_raw( trim( $input['shortcode_url'] ) ) : '',
            'admin_email'   => isset( $input['admin_email'] )   ? sanitize_email( trim( $input['admin_email'] ) ) : '',
        ];
    }

    public static function get( $key, $default = '' ) {
        $opts = get_option( self::OPTION, [] );
        return isset( $opts[ $key ] ) && $opts[ $key ] !== '' ? $opts[ $key ] : $default;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Matthews Order Plugin — Settings', 'matthewsorderplugin' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'mop_settings_group' );
                do_settings_sections( 'mop_settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function field_shortcode_url() {
        $val = self::get( 'shortcode_url' );
        printf(
            '<input type="url" class="regular-text" name="%s[shortcode_url]" value="%s" placeholder="https://example.com/order/" />',
            esc_attr( self::OPTION ),
            esc_attr( $val )
        );
        echo '<p class="description">' . esc_html__( 'Page URL where the [matthews_order] shortcode is placed.', 'matthewsorderplugin' ) . '</p>';
    }

    public static function field_admin_email() {
        $val = self::get( 'admin_email' );
        printf(
            '<input type="email" class="regular-text" name="%s[admin_email]" value="%s" />',
            esc_attr( self::OPTION ),
            esc_attr( $val )
        );
        echo '<p class="description">' . esc_html__( 'Destination for admin notifications and order submissions.', 'matthewsorderplugin' ) . '</p>';
    }
}
