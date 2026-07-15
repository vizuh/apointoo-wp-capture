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
			),
			// derived.
			array(
				'source',
				'medium',
				'channel',
			),
			// first_touch.
			array(
				'ft_source',
				'ft_medium',
				'ft_campaign',
				'ft_landing_page',
				'ft_timestamp',
			)
		);
	}

	/**
	 * Click-id keys (the 12 click_ids from the contract).
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
	 * Reads the same first-party cookie and maps snake_case keys → camelCase,
	 * stripping the `apointoo_` prefix. Returns only set keys so the intake
	 * route never receives null-valued attribution fields.
	 *
	 * Google Consent Mode v2 is stamped on every connected submission. Ad click
	 * identifiers are omitted unless marketing consent is granted; UTMs and
	 * first-party journey context remain available for lead reporting.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_intake_payload() {
		$cookie            = self::from_cookie();
		$marketing_allowed = Consent::marketing_allowed();
		$ad_ids            = array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid', 'liFatId', 'sccid', 'epik', 'rdtCid', 'dclid' );
		$map               = array(
			'apointoo_gclid'        => 'gclid',
			'apointoo_gbraid'       => 'gbraid',
			'apointoo_wbraid'       => 'wbraid',
			'apointoo_fbclid'       => 'fbclid',
			'apointoo_msclkid'      => 'msclkid',
			'apointoo_ttclid'       => 'ttclid',
			'apointoo_twclid'       => 'twclid',
			'apointoo_li_fat_id'    => 'liFatId',
			'apointoo_sccid'        => 'sccid',
			'apointoo_epik'         => 'epik',
			'apointoo_rdt_cid'      => 'rdtCid',
			'apointoo_dclid'        => 'dclid',
			'apointoo_utm_source'   => 'utmSource',
			'apointoo_utm_medium'   => 'utmMedium',
			'apointoo_utm_campaign' => 'utmCampaign',
			'apointoo_utm_term'     => 'utmTerm',
			'apointoo_utm_content'  => 'utmContent',
			'apointoo_utm_id'       => 'utmId',
			'apointoo_referrer'     => 'referrer',
			'apointoo_landing_page' => 'pageUrl',
			'apointoo_ft_source'    => 'ft_source',
			'apointoo_ft_medium'    => 'ft_medium',
			'apointoo_ft_campaign'  => 'ft_campaign',
		);

		$out = array();
		foreach ( $map as $cookie_key => $intake_key ) {
			if ( ! $marketing_allowed && in_array( $intake_key, $ad_ids, true ) ) {
				continue;
			}
			if ( isset( $cookie[ $cookie_key ] ) ) {
				$out[ $intake_key ] = $cookie[ $cookie_key ];
			}
		}
		$out['consent'] = Consent::to_intake_payload( $marketing_allowed );

		return $out;
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
