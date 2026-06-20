<?php
/**
 * Shared form-adapter behaviour.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

use Apointoo\Capture\Capture\Lead;
use Apointoo\Capture\Capture\PII_Hasher;
use Apointoo\Capture\Capture\Transport_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for every form adapter.
 *
 * Subclasses bind one server-side submit hook and call {@see capture()} with the
 * form's native field container; the base normalises it to a neutral {@see Lead}
 * (PII hashed, B5) and hands it to the transport (Path B — server secret).
 */
abstract class Abstract_Form_Adapter implements Form_Adapter_Interface {

	/**
	 * Field-name hints used to locate an email in a submitted form.
	 *
	 * @var string[]
	 */
	protected $email_hints = array( 'email', 'e-mail', 'mail', 'your-email' );

	/**
	 * Field-name hints used to locate a phone number in a submitted form.
	 *
	 * @var string[]
	 */
	protected $phone_hints = array( 'phone', 'tel', 'telephone', 'mobile', 'cell', 'whatsapp', 'your-phone' );

	/**
	 * Outbound transport (the Apointoo SDK client).
	 *
	 * @var Transport_Interface
	 */
	protected $transport;

	/**
	 * Constructor.
	 *
	 * @param Transport_Interface $transport Outbound transport.
	 */
	public function __construct( Transport_Interface $transport ) {
		$this->transport = $transport;
	}

	/**
	 * Normalise a submitted form into a neutral lead and forward it.
	 *
	 * @param string|int           $form_id Form identifier (native to the plugin).
	 * @param array<string, mixed> $fields  Flat field map (name => value).
	 * @return void
	 */
	protected function capture( $form_id, array $fields ) {
		$identity = $this->extract_identity( $fields );

		$lead = new Lead(
			$this->get_platform_slug(),
			(string) $form_id,
			$identity['email_hash'],
			$identity['phone_hash'],
			$this->non_pii_fields( $fields )
		);

		/**
		 * Filter a normalised lead before it is forwarded to the SDK.
		 *
		 * @param Lead                   $lead    The normalised lead.
		 * @param string                 $slug    The platform slug.
		 * @param array<string, mixed>   $fields  The raw submitted fields.
		 */
		$lead = apply_filters( 'apointoo_capture_lead', $lead, $this->get_platform_slug(), $fields );

		// Path B (server secret): the conversion + identity originate here, never
		// from a browser route. Transport is a stub until the contract lands.
		$this->transport->capture_lead( $lead );
	}

	/**
	 * Hash the first email + phone found in the submitted fields (B5).
	 *
	 * Raw PII is hashed and discarded here; it never leaves this method.
	 *
	 * @param array<string, mixed> $fields Flat field map.
	 * @return array{email_hash: string|null, phone_hash: string|null}
	 */
	protected function extract_identity( array $fields ) {
		$email_hash = null;
		$phone_hash = null;

		foreach ( $fields as $key => $value ) {
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			$key   = strtolower( (string) $key );
			$value = (string) $value;

			if ( null === $email_hash && $this->key_matches( $key, $this->email_hints ) && is_email( $value ) ) {
				$email_hash = PII_Hasher::email( $value );
			}

			if ( null === $phone_hash && $this->key_matches( $key, $this->phone_hints ) && PII_Hasher::looks_like_phone( $value ) ) {
				$phone_hash = PII_Hasher::phone( $value );
			}
		}

		return array(
			'email_hash' => $email_hash,
			'phone_hash' => $phone_hash,
		);
	}

	/**
	 * Strip likely-PII values, leaving only non-PII telemetry for the lead.
	 *
	 * Conservative allow-by-shape: drops anything matching an email or phone, so
	 * raw identifiers never ride along in the neutral lead properties.
	 *
	 * @param array<string, mixed> $fields Flat field map.
	 * @return array<string, scalar>
	 */
	protected function non_pii_fields( array $fields ) {
		$out = array();
		foreach ( $fields as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$string = (string) $value;
			if ( is_email( $string ) || PII_Hasher::looks_like_phone( $string ) ) {
				continue;
			}
			$out[ sanitize_key( (string) $key ) ] = sanitize_text_field( $string );
		}

		return $out;
	}

	/**
	 * Does a field key contain any of the given hints?
	 *
	 * @param string   $key   Lower-cased field key.
	 * @param string[] $hints Substrings to look for.
	 * @return bool
	 */
	protected function key_matches( $key, array $hints ) {
		foreach ( $hints as $hint ) {
			if ( false !== strpos( $key, $hint ) ) {
				return true;
			}
		}

		return false;
	}
}
