<?php
/**
 * Shared form-adapter behaviour.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

use Apointoo\Capture\Admin\Settings;
use Apointoo\Capture\Capture\Attribution;
use Apointoo\Capture\Capture\Forward_Log;
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
	 * True when site_key + sdk_url are both configured. Cheap early-exit for
	 * concrete adapters before they do field extraction.
	 *
	 * @return bool
	 */
	protected function is_intake_configured(): bool {
		$settings = get_option( Settings::OPTION, array() );
		return ! empty( $settings['site_key'] ) && ! empty( $settings['sdk_url'] );
	}

	/**
	 * POST a normalised $lead to the intake API and log the result.
	 *
	 * All seven concrete adapters share this path — only their field-extraction
	 * logic differs. Timeout is 5 s (was 8 s across all adapters) to halve the
	 * worst-case block on a form submission when the intake API is slow.
	 *
	 * @param array{name?:string,email?:string,phone?:string,message?:string} $lead    Normalised lead (empty strings already stripped).
	 * @param int|string                                                      $form_id Platform-native form id (for the Forward_Log).
	 * @return void
	 */
	protected function intake_send( array $lead, $form_id ): void {
		$settings   = get_option( Settings::OPTION, array() );
		$site_key   = isset( $settings['site_key'] ) ? trim( (string) $settings['site_key'] ) : '';
		$intake_url = isset( $settings['sdk_url'] ) ? trim( (string) $settings['sdk_url'] ) : '';

		if ( '' === $site_key || '' === $intake_url ) {
			return;
		}

		$attribution  = Attribution::to_intake_payload();
		$cookie       = Attribution::from_cookie();
		$has_identity = isset( $cookie['apointoo_visitor_id'] ) || isset( $cookie['apointoo_session_id'] );
		$have_email   = ! empty( $lead['email'] );
		$have_phone   = ! empty( $lead['phone'] );

		if ( ! $have_email && ! $have_phone ) {
			Forward_Log::record(
				array(
					'source'       => $this->get_platform_slug(),
					'form_id'      => $form_id,
					'ok'           => false,
					'code'         => 0,
					'wp_error'     => 'SKIPPED: no email or phone extracted from form',
					'body'         => '',
					'have_email'   => false,
					'have_phone'   => false,
					'attr_count'   => count( $attribution ),
					'has_identity' => $has_identity,
				)
			);
			return;
		}

		$response = wp_safe_remote_post(
			$intake_url,
			array(
				'headers'  => array(
					'Content-Type'          => 'application/json',
					'X-Apointoo-Tenant-Key' => $site_key,
				),
				'body'     => wp_json_encode(
					array(
						'lead'        => $lead,
						// Cast to object: empty attribution must serialise as {} not []
						// (z.record rejects arrays and silently 400s consent-gated leads).
						'attribution' => (object) $attribution,
					)
				),
				'timeout'  => 5,
				'blocking' => true,
			)
		);

		$entry = array(
			'source'       => $this->get_platform_slug(),
			'form_id'      => $form_id,
			'have_email'   => $have_email,
			'have_phone'   => $have_phone,
			'attr_count'   => count( $attribution ),
			'has_identity' => $has_identity,
		);

		if ( is_wp_error( $response ) ) {
			$entry['ok']       = false;
			$entry['code']     = 0;
			$entry['wp_error'] = $response->get_error_message();
			$entry['body']     = '';
		} else {
			$code              = (int) wp_remote_retrieve_response_code( $response );
			$entry['ok']       = ( $code >= 200 && $code < 300 );
			$entry['code']     = $code;
			$entry['wp_error'] = '';
			$entry['body']     = substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
		}

		Forward_Log::record( $entry );
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
