<?php
/**
 * Neutral normalised lead.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object: a form submission normalised to the capture vocabulary.
 *
 * Carries hashed identifiers only — never raw email/phone (B5). This maps onto the
 * contract's `lead_captured` (+ paired `identify`) events; the exact wire shape is
 * fixed once the public capture contract lands.
 */
class Lead {

	/**
	 * Platform slug (lead source), e.g. "contact-form-7".
	 *
	 * @var string
	 */
	private $platform;

	/**
	 * Form identifier, native to the form plugin.
	 *
	 * @var string
	 */
	private $form_id;

	/**
	 * SHA-256 email hash, or null.
	 *
	 * @var string|null
	 */
	private $email_hash;

	/**
	 * SHA-256 phone hash, or null.
	 *
	 * @var string|null
	 */
	private $phone_hash;

	/**
	 * Non-PII telemetry fields.
	 *
	 * @var array<string, scalar>
	 */
	private $properties;

	/**
	 * Constructor.
	 *
	 * @param string                $platform   Platform slug.
	 * @param string                $form_id    Form identifier.
	 * @param string|null           $email_hash SHA-256 email hash, or null.
	 * @param string|null           $phone_hash SHA-256 phone hash, or null.
	 * @param array<string, scalar> $properties Non-PII telemetry fields.
	 */
	public function __construct( $platform, $form_id, $email_hash, $phone_hash, array $properties = array() ) {
		$this->platform   = (string) $platform;
		$this->form_id    = (string) $form_id;
		$this->email_hash = $email_hash;
		$this->phone_hash = $phone_hash;
		$this->properties = $properties;
	}

	/**
	 * Serialise for the transport / for diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'name'       => 'lead_captured',
			'source'     => 'wp.plugin',
			'platform'   => $this->platform,
			'form_id'    => $this->form_id,
			'email_hash' => $this->email_hash,
			'phone_hash' => $this->phone_hash,
			'properties' => $this->properties,
		);
	}

	/**
	 * Does this lead carry at least one hashed identifier?
	 *
	 * @return bool
	 */
	public function has_identity() {
		return null !== $this->email_hash || null !== $this->phone_hash;
	}
}
