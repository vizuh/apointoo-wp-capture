<?php
/**
 * Attribution field model + cookie reader.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The neutral marketing memory captured first-party and injected into the site's
 * forms as hidden fields. Field names are prefixed `apointoo_` so the site owner
 * can map them in their own CRM / email. Identity keys (`visitor_id`/`session_id`)
 * carry the center's neutral ids; nothing here is PII.
 *
 * This is the single source of truth for the shared capture contract: the field
 * key list, the click-id subset, the UTM subset and the first-touch subset. The
 * tracker (class-tracker.php) localises these lists into ApointooCaptureConfig so
 * the JS and PHP never drift apart.
 */
class Attribution {

	const COOKIE = 'apointoo_capture';
	const PREFIX = 'apointoo_';

	/**
	 * Click-id query parameters captured from inbound URLs.
	 *
	 * @var string[]
	 */
	private const CLICK_IDS = array(
		'gclid',
		'gbraid',
		'wbraid',
		'fbclid',
		'msclkid',
		'ttclid',
		'twclid',
		'li_fat_id',
		'sccid',
		'epik',
		'rdt_cid',
		'dclid',
		'pin_cid',
		'snap_cid',
		'mc_cid',
		'mc_eid',
	);

	/**
	 * Click ids also written once on the first touch as `ft_<id>`.
	 *
	 * @var string[]
	 */
	private const FIRST_TOUCH_CLICK_IDS = array(
		'gclid',
		'gbraid',
		'wbraid',
		'fbclid',
		'msclkid',
	);

	/**
	 * Ad-pixel browser ids (marketing consent class).
	 *
	 * @var string[]
	 */
	private const AD_BROWSER_IDS = array( 'fbc', 'fbp', 'ttp', 'li_gc' );

	/**
	 * GA4 browser ids (analytics consent class).
	 *
	 * @var string[]
	 */
	private const ANALYTICS_BROWSER_IDS = array( 'ga_client_id', 'ga_session_id', 'ga_session_number' );

	/**
	 * Cookie click-id key => intake (dashboard touch schema) key. Ids the
	 * dashboard has no slot for (pin_cid, snap_cid, mc_*) stay local to the form.
	 *
	 * @var array<string, string>
	 */
	private const INTAKE_CLICK_IDS = array(
		'gclid'     => 'gclid',
		'gbraid'    => 'gbraid',
		'wbraid'    => 'wbraid',
		'fbclid'    => 'fbclid',
		'msclkid'   => 'msclkid',
		'ttclid'    => 'ttclid',
		'twclid'    => 'twclid',
		'li_fat_id' => 'liFatId',
		'sccid'     => 'sccid',
		'epik'      => 'epik',
		'rdt_cid'   => 'rdtCid',
		'dclid'     => 'dclid',
	);

	/**
	 * Cookie UTM key => intake key.
	 *
	 * @var array<string, string>
	 */
	private const INTAKE_UTMS = array(
		'utm_source'   => 'utmSource',
		'utm_medium'   => 'utmMedium',
		'utm_campaign' => 'utmCampaign',
		'utm_term'     => 'utmTerm',
		'utm_content'  => 'utmContent',
		'utm_id'       => 'utmId',
	);

	/**
	 * UTM query parameters captured from inbound URLs.
	 *
	 * @var string[]
	 */
	private const UTMS = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_id',
	);

	/**
	 * First-touch fields persisted across the visitor's lifetime.
	 *
	 * @var string[]
	 */
	private const FIRST_TOUCH_KEYS = array(
		'ft_source',
		'ft_medium',
		'ft_campaign',
		'ft_landing_page',
		'ft_channel',
	);

	/**
	 * The hidden-field keys the plugin manages (without the prefix), in contract
	 * order: identity, utm, click_ids, context, derived, first_touch.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_merge(
			// identity.
			array(
				'visitor_id',
				'session_id',
			),
			// utm.
			self::UTMS,
			// click_ids.
			self::CLICK_IDS,
			// context.
			array(
				'referrer',
				'landing_page',
				'lt_landing_page',
				'lt_timestamp',
			),
			// derived.
			array(
				'source',
				'medium',
				'channel',
				'lt_channel',
			),
			// first_touch.
			array(
				'ft_source',
				'ft_medium',
				'ft_campaign',
				'ft_landing_page',
				'ft_channel',
				'ft_timestamp',
			),
			// first_touch click ids.
			array_map(
				static function ( $id ) {
					return 'ft_' . $id;
				},
				self::FIRST_TOUCH_CLICK_IDS
			),
			// browser ids.
			self::AD_BROWSER_IDS,
			self::ANALYTICS_BROWSER_IDS
		);
	}

	/**
	 * Click-id keys captured from inbound URLs.
	 *
	 * @return string[]
	 */
	public static function click_ids() {
		return self::CLICK_IDS;
	}

	/**
	 * UTM keys (the 6 utms from the contract).
	 *
	 * @return string[]
	 */
	public static function utms() {
		return self::UTMS;
	}

	/**
	 * First-touch keys promoted into hidden fields (the contract subset, without
	 * the timestamp which is bookkeeping only).
	 *
	 * @return string[]
	 */
	public static function first_touch_keys() {
		return self::FIRST_TOUCH_KEYS;
	}

	/**
	 * Build the attribution object expected by the Apointoo intake API.
	 *
	 * Reads the first-party cookie and emits the dashboard's intake shape:
	 * flat last-touch keys (click ids, UTMs, ids), a nested `lastTouch` that
	 * carries every click id the dashboard has a slot for, flat `ft_*` first-
	 * touch keys (the dashboard assembles `firstTouch` from them), and
	 * `browserIds`. Only set keys are sent, so the intake route never receives
	 * null-valued attribution fields.
	 *
	 * Google Consent Mode v2 is stamped on every connected submission. Ad click
	 * ids and ad-pixel browser ids are omitted unless marketing consent is
	 * granted, GA4 ids unless analytics consent is granted; UTMs and first-party
	 * journey context remain available for lead reporting.
	 *
	 * @param string $page_url Page the form was submitted from ('' = landing page).
	 * @return array<string, mixed>
	 */
	public static function to_intake_payload( $page_url = '' ) {
		$cookie            = self::from_cookie();
		$marketing_allowed = Consent::marketing_allowed();
		$analytics_allowed = Consent::analytics_allowed();
		$value             = static function ( $key ) use ( $cookie ) {
			return isset( $cookie[ self::PREFIX . $key ] ) ? $cookie[ self::PREFIX . $key ] : null;
		};

		$out = array();
		$set = static function ( array &$target, $key, $val ) {
			if ( null !== $val && '' !== $val ) {
				$target[ $key ] = $val;
			}
		};

		$set( $out, 'visitorId', $value( 'visitor_id' ) );
		$set( $out, 'sessionId', $value( 'session_id' ) );

		// Last touch — flat (legacy slots) and nested (every network).
		$last = array();
		$set( $last, 'timestampIso', self::iso_from_ms( $value( 'lt_timestamp' ) ) );
		$set( $last, 'landingPage', $value( 'lt_landing_page' ) );
		foreach ( self::INTAKE_UTMS as $cookie_key => $intake_key ) {
			$set( $out, $intake_key, $value( $cookie_key ) );
			$set( $last, $intake_key, $value( $cookie_key ) );
		}
		if ( $marketing_allowed ) {
			foreach ( self::INTAKE_CLICK_IDS as $cookie_key => $intake_key ) {
				$set( $out, $intake_key, $value( $cookie_key ) );
				$set( $last, $intake_key, $value( $cookie_key ) );
			}
		}
		if ( count( array_diff_key( $last, array( 'timestampIso' => true ) ) ) > 0 ) {
			$out['lastTouch'] = $last;
		}

		$set( $out, 'referrer', $value( 'referrer' ) );
		$set( $out, 'pageUrl', self::page_url( $page_url, $value, $marketing_allowed ) );

		// First touch — flat ft_* keys the dashboard assembles into firstTouch.
		$set( $out, 'ft_source', $value( 'ft_source' ) );
		$set( $out, 'ft_medium', $value( 'ft_medium' ) );
		$set( $out, 'ft_campaign', $value( 'ft_campaign' ) );
		$set( $out, 'ft_channel', $value( 'ft_channel' ) );
		$set( $out, 'ft_landing', $value( 'ft_landing_page' ) );
		$set( $out, 'ft_ts', self::iso_from_ms( $value( 'ft_timestamp' ) ) );
		if ( $marketing_allowed ) {
			foreach ( self::FIRST_TOUCH_CLICK_IDS as $id ) {
				$set( $out, 'ft_' . $id, $value( 'ft_' . $id ) );
			}
		}
		$set( $out, 'lt_channel', $value( 'lt_channel' ) );

		$browser_ids = array();
		if ( $marketing_allowed ) {
			foreach ( self::AD_BROWSER_IDS as $id ) {
				$set( $browser_ids, $id, $value( $id ) );
			}
		}
		if ( $analytics_allowed ) {
			foreach ( self::ANALYTICS_BROWSER_IDS as $id ) {
				$set( $browser_ids, $id, $value( $id ) );
			}
		}
		if ( $browser_ids ) {
			$out['browserIds'] = $browser_ids;
		}

		$out['consent'] = Consent::to_intake_payload( $marketing_allowed, $analytics_allowed );

		return $out;
	}

	/**
	 * The submission page URL, falling back to the last-touch landing page.
	 * Without marketing consent the query string is dropped, because the
	 * dashboard recovers click ids from it.
	 *
	 * @param string   $page_url          Submission page URL or ''.
	 * @param callable $value             Cookie value reader.
	 * @param bool     $marketing_allowed Marketing consent decision.
	 * @return string|null
	 */
	private static function page_url( $page_url, $value, $marketing_allowed ) {
		$url = is_string( $page_url ) && '' !== $page_url ? $page_url : ( $value( 'lt_landing_page' ) ?? $value( 'landing_page' ) );
		if ( null === $url || '' === $url ) {
			return null;
		}
		if ( ! $marketing_allowed ) {
			$url = preg_replace( '/[?#].*$/', '', (string) $url );
		}
		return substr( (string) $url, 0, 2048 );
	}

	/**
	 * Millisecond epoch string (as the tracker stores it) to ISO 8601, or null.
	 *
	 * @param string|null $ms Milliseconds since epoch.
	 * @return string|null
	 */
	private static function iso_from_ms( $ms ) {
		if ( null === $ms || ! ctype_digit( (string) $ms ) ) {
			return null;
		}
		return gmdate( 'c', (int) floor( (int) $ms / 1000 ) );
	}

	/**
	 * Read the first-party attribution cookie into a flat, prefixed field map.
	 *
	 * The cookie is written by the front-end tracker; it is untrusted, so it is
	 * JSON-decoded and every known field is sanitised individually. Unsubstituted
	 * ad-platform macros (e.g. `{{campaign.name}}`) are rejected, and values are
	 * capped to 256 characters.
	 *
	 * @return array<string, string> Map of `apointoo_<key>` => value (set keys only).
	 */
	public static function from_cookie() {
		$out = array();
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return $out;
		}

		// JSON blob; validated via json_decode + each field sanitised below.
		$raw  = wp_unslash( $_COOKIE[ self::COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return $out;
		}

		foreach ( self::keys() as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
				continue;
			}

			$clean = sanitize_text_field( (string) $data[ $key ] );
			if ( '' === $clean ) {
				continue;
			}

			// Reject unsubstituted ad-platform dynamic parameter macros, e.g.
			// Facebook {{campaign.name}}, {{adset.name}}, {{ad.name}} — these
			// appear literally in URLs when not served through the ad platform.
			if ( preg_match( '/^\{\{.+\}\}$/', $clean ) ) {
				continue;
			}

			// Cap to 256 characters to match the client-side sanitiser.
			if ( strlen( $clean ) > 256 ) {
				$clean = substr( $clean, 0, 256 );
			}

			$out[ self::PREFIX . $key ] = $clean;
		}

		return $out;
	}
}
