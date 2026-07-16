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
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_add_inline_script(
			'apointoo-capture-tracker',
			'window.ApointooCaptureConfig=' . wp_json_encode( $this->config() ) . ';',
			'before'
		);

		wp_enqueue_script( 'apointoo-capture-tracker' );
	}

	/**
	 * Build the ApointooCaptureConfig object consumed by tracker.js.
	 *
	 * Every list is single-sourced from Attribution so the JS and PHP contracts
	 * cannot drift. Consent is single-sourced from Consent::client_config().
	 *
	 * @return array<string, mixed>
	 */
	private function config() {
		return array(
			'cookie'         => Attribution::COOKIE,
			'prefix'         => Attribution::PREFIX,
			'keys'           => Attribution::keys(),
			'clickIds'       => Attribution::click_ids(),
			'utms'           => Attribution::utms(),
			'firstTouchKeys' => Attribution::first_touch_keys(),
			'consent'        => Consent::client_config(),
			'decoration'     => array(
				'enabled'        => $this->decoration_enabled(),
				'allowedDomains' => $this->allowed_domains(),
			),
		);
	}

	/**
	 * Whether link decoration is enabled (default true).
	 *
	 * @return bool
	 */
	private function decoration_enabled() {
		/**
		 * Filter whether outbound-link decoration is enabled.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'apointoo_capture_decoration_enabled', true );
	}

	/**
	 * The host strings decoration is allowed to append attribution to.
	 *
	 * @return string[]
	 */
	private function allowed_domains() {
		$domains = get_option( 'apointoo_capture_allowed_domains', array() );
		if ( ! is_array( $domains ) ) {
			$domains = array();
		}

		/**
		 * Filter the outbound hosts decoration may append attribution to.
		 *
		 * @param string[] $domains List of host strings.
		 */
		$domains = apply_filters( 'apointoo_capture_allowed_domains', $domains );

		$hosts = array();
		foreach ( (array) $domains as $domain ) {
			if ( ! is_scalar( $domain ) ) {
				continue;
			}
			$host = trim( (string) $domain );
			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}

		return array_values( $hosts );
	}
}
