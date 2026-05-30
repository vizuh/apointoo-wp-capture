<?php
/**
 * PII normalisation + hashing (B5).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalises an email/phone and returns an unsalted SHA-256 hex digest.
 *
 * Unsalted is required for Google enhanced-conversions matching; the normalisation
 * MUST stay byte-identical to the SDK's canonical spec so hashes collide.
 *
 * Security note: the resulting phone hash is brute-forceable (small keyspace) and
 * should be treated as quasi-reversible PII — see DPIA R5 / issue I5.
 */
class PII_Hasher {

	/**
	 * Hash a normalised email address.
	 *
	 * Lower-cases + trims; for gmail/googlemail strips dots and any `+suffix`.
	 *
	 * @param string $email Raw email.
	 * @return string SHA-256 hex digest.
	 */
	public static function email( $email ) {
		$email = strtolower( trim( (string) $email ) );

		$at = strrpos( $email, '@' );
		if ( false !== $at ) {
			$local  = substr( $email, 0, $at );
			$domain = substr( $email, $at + 1 );

			if ( 'gmail.com' === $domain || 'googlemail.com' === $domain ) {
				$plus = strpos( $local, '+' );
				if ( false !== $plus ) {
					$local = substr( $local, 0, $plus );
				}
				$local = str_replace( '.', '', $local );
				$email = $local . '@' . $domain;
			}
		}

		return hash( 'sha256', $email );
	}

	/**
	 * Hash a normalised phone number (E.164: leading `+`, digits only).
	 *
	 * @param string $phone Raw phone.
	 * @return string SHA-256 hex digest.
	 */
	public static function phone( $phone ) {
		$phone  = (string) $phone;
		$plus   = ( '' !== $phone && '+' === $phone[0] );
		$digits = preg_replace( '/\D+/', '', $phone );

		return hash( 'sha256', ( $plus ? '+' : '' ) . $digits );
	}

	/**
	 * Loose check that a value resembles a phone number.
	 *
	 * @param string $value Candidate value.
	 * @return bool
	 */
	public static function looks_like_phone( $value ) {
		return (bool) preg_match( '/^\+?[0-9 ().-]{7,20}$/', (string) $value );
	}
}
