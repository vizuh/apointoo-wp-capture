<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes the
 * plugin's own options. Captured data lives in the Apointoo SDK ledger, not in
 * WordPress, so there is nothing else to purge here.
 *
 * @package Apointoo\Capture
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'apointoo_capture_settings' );
