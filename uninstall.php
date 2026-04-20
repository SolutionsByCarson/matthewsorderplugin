<?php
/**
 * Uninstall handler.
 *
 * Intentionally does NOT drop the plugin's custom tables or delete the
 * wp-content/order/ upload tree. This is a proprietary data plugin — data
 * survives plugin removal. If the site owner truly wants a wipe, they
 * should run it manually through a maintenance script.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
