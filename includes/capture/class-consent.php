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
 * WP Consent API (only when a CMP has registered a consent type with it) ->
 * CMP-native consent cookies (CookieYes, Cookiebot, Complianz, OneTrust) ->
 * `wp_consent_*` cookies -> the filtered default (denied). Forwarding an ad
 * identifier is itself the `ad_user_data` action, so it is gated here for
 * server-side use.
 *
 * The client-side tracker resolves consent in the browser (CookieYes, Google
 * Consent Mode, Cookiebot, OneTrust, Complianz, or the `wp_consent_marketing`
 * cookie). It only needs to know the desired posture, which is single-sourced
 * through {@see Consent::client_config()} so class-tracker.php and the JS never
 * drift.
 */
class Consent {

	/**
	 * Is marketing/ad consent granted for this request? (server-side gate)
	 *
	 * @return bool
	 */
	public static function marketing_allowed() {
		$allowed = self::resolve( 'marketing' );
		if ( null !== $allowed ) {
			return $allowed;
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
	 * Is analytics/statistics consent granted for this request?
	 *
	 * @return bool
	 */
	public static function analytics_allowed() {
		$allowed = self::resolve( 'statistics' );
		if ( null !== $allowed ) {
			return $allowed;
		}

		/**
		 * Filter the default analytics consent decision when no CMP signal is present.
		 *
		 * @param bool $allowed Whether analytics is allowed by default.
		 */
		return (bool) apply_filters( 'apointoo_capture_default_analytics_consent', false );
	}

	/**
	 * Resolve one WP Consent API category ('marketing' | 'statistics').
	 *
	 * @param string $category Consent category.
	 * @return bool|null Null when no source answers.
	 */
	private static function resolve( $category ) {
		// The WP Consent API answers `true` for everything when no CMP has
		// registered a consent type ("no consent management"), so it is only
		// authoritative once a CMP has integrated with it.
		if ( function_exists( 'wp_has_consent' ) && self::consent_api_has_cmp() ) {
			return (bool) wp_has_consent( $category );
		}

		$cmp = self::from_cmp_cookies( $category );
		if ( null !== $cmp ) {
			return $cmp;
		}

		// The WP Consent API cookie, if a CMP set it without the shim.
		$cookie = self::cookie( 'wp_consent_' . $category );
		if ( null !== $cookie ) {
			return 'allow' === $cookie;
		}

		return null;
	}

	/**
	 * Has a CMP registered a consent type with the WP Consent API? Releases
	 * without wp_get_consent_type() are trusted as before.
	 *
	 * @return bool
	 */
	private static function consent_api_has_cmp() {
		if ( ! function_exists( 'wp_get_consent_type' ) ) {
			return true;
		}
		return (bool) wp_get_consent_type();
	}

	/**
	 * Read a CMP's own consent cookie for one category.
	 *
	 * @param string $category 'marketing' | 'statistics'.
	 * @return bool|null Null when no known CMP cookie holds an answer.
	 */
	private static function from_cmp_cookies( $category ) {
		$marketing = 'marketing' === $category;

		// CookieYes: "consentid:…,consent:yes,action:yes,…,analytics:yes,advertisement:yes".
		$cky = self::cookie( 'cookieyes-consent' );
		if ( null !== $cky ) {
			$map = array();
			foreach ( explode( ',', $cky ) as $pair ) {
				$parts = explode( ':', $pair, 2 );
				if ( 2 === count( $parts ) ) {
					$map[ $parts[0] ] = $parts[1];
				}
			}
			if ( isset( $map['action'] ) && 'yes' === $map['action'] ) {
				$key = $marketing ? 'advertisement' : 'analytics';
				return isset( $map[ $key ] ) && 'yes' === $map[ $key ];
			}
		}

		// Cookiebot: "-1" when consent is not required for the visitor's region,
		// else "{stamp:'…',necessary:true,…,statistics:true,marketing:true,…}".
		$cookiebot = self::cookie( 'CookieConsent' );
		if ( null !== $cookiebot ) {
			if ( '-1' === $cookiebot ) {
				return true;
			}
			$key = $marketing ? 'marketing' : 'statistics';
			if ( preg_match( '/' . $key . ':(true|false)/', $cookiebot, $m ) ) {
				return 'true' === $m[1];
			}
		}

		// Complianz: cmplz_marketing / cmplz_statistics = allow | deny.
		$cmplz = self::cookie( $marketing ? 'cmplz_marketing' : 'cmplz_statistics' );
		if ( null !== $cmplz ) {
			return 'allow' === $cmplz;
		}

		// OneTrust: OptanonConsent "…&groups=C0001:1,C0002:1,C0004:0&…".
		$onetrust = self::cookie( 'OptanonConsent' );
		if ( null !== $onetrust ) {
			$group = $marketing ? 'C0004' : 'C0002';
			if ( preg_match( '/(?:^|[,=])' . $group . ':([01])/', $onetrust, $m ) ) {
				return '1' === $m[1];
			}
		}

		return null;
	}

	/**
	 * Sanitised, URL-decoded cookie value, or null when absent.
	 *
	 * @param string $name Cookie name.
	 * @return string|null
	 */
	private static function cookie( $name ) {
		if ( ! isset( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
			return null;
		}
		return sanitize_text_field( rawurldecode( wp_unslash( $_COOKIE[ $name ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by sanitize_text_field.
	}

	/**
	 * Google Consent Mode v2 snapshot expected by Apointoo dashboard intake.
	 *
	 * @param bool|null $marketing_allowed Reuse an already-resolved marketing decision.
	 * @param bool|null $analytics_allowed Reuse an already-resolved analytics decision.
	 * @return array<string, string>
	 */
	public static function to_intake_payload( $marketing_allowed = null, $analytics_allowed = null ) {
		$marketing_allowed = is_bool( $marketing_allowed ) ? $marketing_allowed : self::marketing_allowed();
		$analytics_allowed = is_bool( $analytics_allowed ) ? $analytics_allowed : self::analytics_allowed();
		$ad_state          = $marketing_allowed ? 'granted' : 'denied';
		$analytics_state   = $analytics_allowed ? 'granted' : 'denied';

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
