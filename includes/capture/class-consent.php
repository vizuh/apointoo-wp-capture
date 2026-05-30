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
 * WP Consent API → CMP-native globals (handled client-side) → `wp_consent_*` cookies.
 * Forwarding an ad identifier is itself the `ad_user_data` action, so it is gated here.
 */
class Consent {

	/**
	 * Is marketing/ad consent granted for this request?
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
}
