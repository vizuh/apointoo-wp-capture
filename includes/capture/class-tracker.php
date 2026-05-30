<?php
/**
 * Front-end attribution tracker (asset loader).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the headless JS tracker that captures attribution first-party and
 * fills the hidden fields the form adapters inject. No data leaves the browser —
 * this is the free tier: the attribution rides the form into the owner's systems.
 */
class Tracker {

	/**
	 * Register front-end hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the tracker script and its config.
	 *
	 * @return void
	 */
	public function enqueue() {
		wp_register_script(
			'apointoo-capture-tracker',
			APOINTOO_CAPTURE_URL . 'assets/js/tracker.js',
			array(),
			APOINTOO_CAPTURE_VERSION,
			true
		);

		wp_add_inline_script(
			'apointoo-capture-tracker',
			'window.ApointooCaptureConfig=' . wp_json_encode(
				array(
					'cookie' => Attribution::COOKIE,
					'prefix' => Attribution::PREFIX,
					'keys'   => Attribution::keys(),
				)
			) . ';',
			'before'
		);

		wp_enqueue_script( 'apointoo-capture-tracker' );
	}
}
