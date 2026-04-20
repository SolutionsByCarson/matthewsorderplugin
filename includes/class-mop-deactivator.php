<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin deactivation handler.
 *
 * Intentionally a no-op — data, uploads, and settings all persist across
 * deactivation so the plugin can be re-enabled without data loss.
 */
class MOP_Deactivator {
    public static function deactivate() {
        // No-op by design.
    }
}
