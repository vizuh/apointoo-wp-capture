<?php
/**
 * Forward log — a bounded, operator-visible record of the last N intake forwards.
 *
 * The forward to the Apointoo intake endpoint is otherwise invisible: a failed
 * POST (bad key, inactive tenant, schema rejection, network block) leaves no
 * trace, and the host has WP_DEBUG off. This keeps the last {@see MAX} attempts
 * in a single autoloaded-off option so the Settings screen can show exactly why
 * a lead did or didn't reach the dashboard — without a DB table.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Capture;

use Apointoo\Capture\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ring buffer of recent forward results.
 */
class Forward_Log {

	const OPTION = 'apointoo_capture_forward_log';
	const MAX    = 10;

	/**
	 * Append a forward result to the ring buffer (newest first, capped at MAX).
	 *
	 * Stored with autoload=false so it never rides every page load. Mirrors to
	 * the PHP error log when debug is on. Never store the site key value.
	 *
	 * @param array<string, mixed> $entry Partial entry; `ts` is injected here.
	 * @return void
	 */
	public static function record( array $entry ) {
		$entry['ts'] = time();

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX );
		update_option( self::OPTION, $log, false );

		if ( self::debug_on() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Apointoo Capture forward: ' . wp_json_encode( $entry ) );
		}
	}

	/**
	 * The recorded forwards, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Empty the buffer.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Whether to mirror records to the PHP error log.
	 *
	 * On via the `APOINTOO_CAPTURE_DEBUG` constant or the settings checkbox.
	 *
	 * @return bool
	 */
	public static function debug_on() {
		if ( defined( 'APOINTOO_CAPTURE_DEBUG' ) && APOINTOO_CAPTURE_DEBUG ) {
			return true;
		}
		$settings = get_option( Settings::OPTION, array() );
		return is_array( $settings ) && ! empty( $settings['debug'] );
	}
}
