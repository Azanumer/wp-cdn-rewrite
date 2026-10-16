<?php
/**
 * Uninstall cleanup for WP CDN Rewrite.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wcdr_settings' );
