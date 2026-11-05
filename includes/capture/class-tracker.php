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
			'cookie'           => Attribution::COOKIE,
			'prefix'           => Attribution::PREFIX,
			'keys'             => Attribution::keys(),
			'clickIds'         => Attribution::click_ids(),
			'utms'             => Attribution::utms(),
			'firstTouchKeys'   => Attribution::first_touch_keys(),
			'consent'          => Consent::client_config(),
			'decoration'       => array(
				'enabled'        => $this->decoration_enabled(),
				'allowedDomains' => $this->allowed_domains(),
			),
			'cookieDomain'     => $this->cookie_domain(),
			'ga4MeasurementId' => $this->ga4_measurement_id(),
			'whatsappEvents'   => $this->whatsapp_events_enabled(),
		);
	}

	/**
	 * Hosting / multi-tenant suffixes where a "registrable" domain is shared with
	 * strangers, so the attribution cookie must stay host-only.
	 *
	 * @var string[]
	 */
	private const PUBLIC_SUFFIXES = array(
		'wpengine.com',
		'wpenginepowered.com',
		'kinsta.cloud',
		'cloudwaysapps.com',
		'github.io',
		'herokuapp.com',
		'vercel.app',
		'netlify.app',
		'pages.dev',
		'azurewebsites.net',
		'myshopify.com',
		'wordpress.com',
		'wpcomstaging.com',
		'blogspot.com',
	);

	/**
	 * Cookie domain for the attribution cookie: '' (host-only, the default) or
	 * the site's registrable domain when the scope is 'registrable', so sibling
	 * subdomains running the plugin share one record.
	 *
	 * @return string
	 */
	private function cookie_domain() {
		$scope = get_option( 'apointoo_capture_cookie_scope', 'host' );

		/**
		 * Filter the attribution cookie scope.
		 *
		 * @param string $scope 'host' (default) | 'registrable'.
		 */
		$scope = apply_filters( 'apointoo_capture_cookie_scope', $scope );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );

		return self::cookie_domain_for( is_string( $host ) ? $host : '', is_string( $scope ) ? $scope : 'host' );
	}

	/**
	 * Resolve the cookie domain for a host + scope. '' means host-only: the
	 * scope is not 'registrable', the host is an IP or single label, or the
	 * registrable domain is a public / hosting suffix.
	 *
	 * @param string $host  Site host.
	 * @param string $scope 'host' | 'registrable'.
	 * @return string
	 */
	public static function cookie_domain_for( $host, $scope ) {
		$host = strtolower( trim( (string) $host, ". \t\n\r\0\x0B" ) );
		if ( 'registrable' !== $scope || '' === $host || false !== filter_var( $host, FILTER_VALIDATE_IP ) || false === strpos( $host, '.' ) ) {
			return '';
		}

		$parts = explode( '.', $host );
		$count = count( $parts );
		$sld   = array( 'co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ne', 'or', 'me' );
		if ( $count >= 3 && in_array( $parts[ $count - 2 ], $sld, true ) ) {
			$domain = implode( '.', array_slice( $parts, -3 ) );
		} else {
			$domain = implode( '.', array_slice( $parts, -2 ) );
		}

		$suffix_parts = explode( '.', $domain );
		if ( 2 === count( $suffix_parts ) && in_array( $suffix_parts[0], $sld, true ) ) {
			return ''; // e.g. host "co.uk" itself.
		}

		/**
		 * Filter the suffixes treated as public (never used as a cookie domain).
		 *
		 * @param string[] $suffixes Hosting / multi-tenant suffixes.
		 */
		$suffixes = (array) apply_filters( 'apointoo_capture_public_suffixes', self::PUBLIC_SUFFIXES );
		if ( in_array( $domain, $suffixes, true ) ) {
			return '';
		}

		return $domain;
	}

	/**
	 * GA4 measurement id whose _ga_<id> cookie supplies ga_session_id ('' = any).
	 *
	 * @return string
	 */
	private function ga4_measurement_id() {
		/**
		 * Filter the GA4 measurement id (G-XXXX) used to read the session cookie.
		 *
		 * @param string $id Default from the apointoo_capture_ga4_measurement_id option.
		 */
		$id = apply_filters( 'apointoo_capture_ga4_measurement_id', get_option( 'apointoo_capture_ga4_measurement_id', '' ) );
		$id = is_string( $id ) ? strtoupper( trim( $id ) ) : '';

		return 1 === preg_match( '/^G-[A-Z0-9]+$/', $id ) ? $id : '';
	}

	/**
	 * Whether WhatsApp clicks push contact_whatsapp_start (default true).
	 *
	 * @return bool
	 */
	private function whatsapp_events_enabled() {
		/**
		 * Filter whether WhatsApp clicks push contact_whatsapp_start to the dataLayer.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'apointoo_capture_whatsapp_events', true );
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
