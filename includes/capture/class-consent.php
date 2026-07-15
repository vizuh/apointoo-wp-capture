<?php
/**
 * Consent reader (E1).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the site's marketing/ad consent at forward time.
 *
 * We never present a banner — we read the tenant's existing CMP. Resolution order:
 * WP Consent API -> CMP-native globals (handled client-side) -> `wp_consent_*`
 * cookies. Forwarding an ad identifier is itself the `ad_user_data` action, so it
 * is gated here for server-side use.
 *
 * The client-side tracker resolves consent in the browser (Google Consent Mode,
 * Cookiebot, OneTrust, Complianz, or the `wp_consent_marketing` cookie). It only
 * needs to know the desired posture, which is single-sourced through
 * {@see Consent::client_config()} so class-tracker.php and the JS never drift.
 */
class Consent {

	/**
	 * Is marketing/ad consent granted for this request? (server-side gate)
	 *
	 * @return bool
	 */
	public static function marketing_allowed() {
		// Preferred: the WP Consent API normalises every supported CMP.
		if ( function_exists( 'wp_has_consent' ) ) {
			return (bool) wp_has_consent( 'marketing' );
		}

		// Fallback: the WP Consent API cookie, if a CMP set it without the shim.
		if ( isset( $_COOKIE['wp_consent_marketing'] ) ) {
			$value = sanitize_text_field( wp_unslash( $_COOKIE['wp_consent_marketing'] ) );
			return 'allow' === $value;
		}

		/**
		 * Filter the default consent decision when no CMP signal is present.
		 *
		 * Defaults to denied (opt-in posture). Return true only on documented
		 * legitimate-interest grounds.
		 *
		 * @param bool $allowed Whether marketing is allowed by default.
		 */
		return (bool) apply_filters( 'apointoo_capture_default_marketing_consent', false );
	}

	/**
	 * Google Consent Mode v2 snapshot expected by Apointoo dashboard intake.
	 *
	 * @param bool|null $marketing_allowed Reuse an already-resolved decision.
	 * @return array<string, string>
	 */
	public static function to_intake_payload( $marketing_allowed = null ) {
		$marketing_allowed = is_bool( $marketing_allowed ) ? $marketing_allowed : self::marketing_allowed();
		$ad_state          = $marketing_allowed ? 'granted' : 'denied';
		$analytics_state   = function_exists( 'wp_has_consent' ) && wp_has_consent( 'statistics' ) ? 'granted' : 'denied';

		return array(
			'adStorage'         => $ad_state,
			'analyticsStorage'  => $analytics_state,
			'adUserData'        => $ad_state,
			'adPersonalization' => $ad_state,
			'capturedAt'        => gmdate( 'c' ),
			'source'            => 'api',
		);
	}

	/**
	 * Client-side consent posture for ApointooCaptureConfig.
	 *
	 * The tracker auto-detects the active CMP; this only tells it whether to gate
	 * persistence on a marketing grant. `require` is one of 'auto' | 'always' |
	 * 'never'; `source` is always 'auto' (the JS picks the CMP it finds).
	 *
	 * @return array{require:string, source:string}
	 */
	public static function client_config() {
		$mode = get_option( 'apointoo_capture_consent_mode', 'auto' );

		/**
		 * Filter the client-side consent mode.
		 *
		 * @param string $mode One of 'auto' | 'always' | 'never'.
		 */
		$mode = apply_filters( 'apointoo_capture_consent_mode', $mode );

		$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : 'auto';
		if ( ! in_array( $mode, array( 'auto', 'always', 'never' ), true ) ) {
			$mode = 'auto';
		}

		return array(
			'require' => $mode,
			'source'  => 'auto',
		);
	}
}
