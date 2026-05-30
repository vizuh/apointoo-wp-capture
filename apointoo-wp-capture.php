<?php
/**
 * Plugin Name:       Apointoo Capture
 * Plugin URI:        https://apointoo.com
 * Description:       Connects existing WordPress forms to the Apointoo capture contract. A capture/wiring adapter — NOT a form builder.
 * Version:           0.1.0
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

define( 'APOINTOO_CAPTURE_VERSION', '0.1.0' );
define( 'APOINTOO_CAPTURE_DIR', plugin_dir_path( __FILE__ ) );
define( 'APOINTOO_CAPTURE_URL', plugin_dir_url( __FILE__ ) );
define( 'APOINTOO_CAPTURE_BASENAME', plugin_basename( __FILE__ ) );
define( 'APOINTOO_CAPTURE_MAIN_FILE', __FILE__ );

/*
 * ───────────────────────────────────────────────────────────────────────────
 * Structural scaffold. The capture/wiring layer (form adapters, consent reader,
 * PII hasher, the neutral lead value object) is in place; the SDK transport and
 * the visitor /wp-json proxy are STUBS until the public capture contract lands.
 *
 * Contract + auth design (vizuh/apointoo-sdk):
 *   _references/public-capture-contract-proposal.md
 *   docs/decisions/adr-021-capture-api-tokens.md
 * Tracked at: vizuh/apointoo-wp-capture#8 · vizuh/apointoo-sdk#116, #117, #118
 *
 * The hard boundary (docs/PLAN.md): capture/wiring adapter, NOT a form builder.
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
