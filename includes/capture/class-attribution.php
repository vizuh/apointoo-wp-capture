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
 * carry the center's neutral `vis_`/`ses_` ids (A1/B1); nothing here is PII.
 */
class Attribution {

	const COOKIE = 'apointoo_capture';
	const PREFIX = 'apointoo_';

	/**
	 * The hidden-field keys the plugin manages (without the prefix).
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array(
			'visitor_id',
			'session_id',
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'gclid',
			'gbraid',
			'wbraid',
			'fbclid',
			'msclkid',
			'referrer',
			'landing_page',
		);
	}

	/**
	 * Read the first-party attribution cookie into a flat, prefixed field map.
	 *
	 * The cookie is written by the front-end tracker; it is untrusted, so it is
	 * JSON-decoded and every field is sanitised individually.
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
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$out[ self::PREFIX . $key ] = sanitize_text_field( (string) $data[ $key ] );
			}
		}

		return $out;
	}
}
