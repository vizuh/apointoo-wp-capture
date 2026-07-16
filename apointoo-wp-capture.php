<?php
/**
 * Plugin Name:       Apointoo Capture
 * Description:       Connects existing WordPress forms to the Apointoo capture contract. A capture/wiring adapter — NOT a form builder.
 * Version:           0.5.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Apointoo
 * Author URI:        https://apointoo.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       apointoo-capture
 *
 * @package Apointoo\Capture
 *
 * Apointoo Capture is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2 or (at your
 * option) any later version, as published by the Free Software Foundation.
 *
 * It is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'APOINTOO_CAPTURE_VERSION', '0.5.0' );
define( 'APOINTOO_CAPTURE_DIR', plugin_dir_path( __FILE__ ) );
define( 'APOINTOO_CAPTURE_URL', plugin_dir_url( __FILE__ ) );
define( 'APOINTOO_CAPTURE_BASENAME', plugin_basename( __FILE__ ) );
define( 'APOINTOO_CAPTURE_MAIN_FILE', __FILE__ );

/*
 * ───────────────────────────────────────────────────────────────────────────
 * Live path: form adapters post contact fields plus consent-aware attribution
 * server-to-server to the configured Apointoo dashboard contact intake.
 * Google-specific normalization, hashing, deduplication, and upload stay in the
 * dashboard so WordPress is never a second conversion source.
 *
 * Hard boundary (docs/PLAN.md): capture/wiring adapter, NOT a form builder.
 * ───────────────────────────────────────────────────────────────────────────
 */

/**
 * Load the runtime autoloader (Composer in dev, the plugin autoloader otherwise).
 *
 * @return void
 */
function apointoo_capture_bootstrap() {
	$composer = APOINTOO_CAPTURE_DIR . 'vendor/autoload.php';
	if ( file_exists( $composer ) ) {
		require_once $composer;
	}

	require_once APOINTOO_CAPTURE_DIR . 'includes/class-autoloader.php';
	Apointoo\Capture\Autoloader::run();
}

apointoo_capture_bootstrap();

/**
 * Set first-run redirect flag on activation (ADR-001).
 */
register_activation_hook(
	__FILE__,
	function () {
		add_option( 'apointoo_capture_activation_redirect', true );
	}
);

/**
 * Initialise the plugin once WordPress is ready.
 *
 * Fails soft: a missing class shows an admin notice rather than fataling the site.
 *
 * @return void
 */
function apointoo_capture_init() {
	if ( ! class_exists( 'Apointoo\Capture\Plugin' ) ) {
		add_action(
			'admin_notices',
			function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p>';
				echo esc_html__(
					'Apointoo Capture could not start because a required class is missing. The plugin files may be incomplete.',
					'apointoo-capture'
				);
				echo '</p></div>';
			}
		);
		return;
	}

	$plugin = new Apointoo\Capture\Plugin();
	$plugin->run();
}
add_action( 'plugins_loaded', 'apointoo_capture_init', 20 );
