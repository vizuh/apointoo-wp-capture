<?php
/**
 * Contact Form 7 adapter.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

use Apointoo\Capture\Admin\Settings;
use Apointoo\Capture\Capture\Attribution;
use Apointoo\Capture\Capture\Forward_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures Contact Form 7 submissions server-side (Path B).
 *
 * Binds `wpcf7_mail_sent` (the clean-submit signal — fires only after the mail
 * succeeds, unlike `wpcf7_before_send_mail` which fires on failure too).
 */
class CF7_Adapter extends Abstract_Form_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPCF7' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Contact Form 7';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_slug() {
		return 'contact-form-7';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Free tier: inject attribution as hidden fields (rides the form into the
		// site owner's own systems — no external call).
		add_filter( 'wpcf7_form_hidden_fields', array( $this, 'hidden_fields' ) );
		// Server-side fallback: backfill the hidden fields from the cookie when the
		// tracker could not fill them (JS off, or a cached page served stale markup),
		// so the email / feeds still carry attribution.
		add_filter( 'wpcf7_posted_data', array( $this, 'backfill_posted_data' ) );
		// Paid tier: capture the submission for forwarding to Apointoo (no-op until
		// credentials + the transport are wired).
		add_action( 'wpcf7_mail_sent', array( $this, 'on_mail_sent' ) );
	}

	/**
	 * Server-side fallback: backfill the attribution hidden fields from the cookie
	 * when the client-side tracker did not fill them. Only empty/missing fields are
	 * filled, so a real client value is never overwritten.
	 *
	 * @param array<string, mixed> $posted Posted CF7 data.
	 * @return array<string, mixed>
	 */
	public function backfill_posted_data( $posted ) {
		if ( ! is_array( $posted ) ) {
			return $posted;
		}

		foreach ( Attribution::from_cookie() as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$current = isset( $posted[ $name ] )
				? ( is_array( $posted[ $name ] ) ? implode( '', $posted[ $name ] ) : (string) $posted[ $name ] )
				: '';
			if ( '' === $current ) {
				$posted[ $name ] = $value;
			}
		}

		return $posted;
	}

	/**
	 * Add the attribution hidden fields to a Contact Form 7 form.
	 *
	 * Every managed key is emitted empty so page caches cannot copy one
	 * visitor's attribution into another visitor's form.
	 *
	 * @param array<string, string> $fields Existing hidden fields.
	 * @return array<string, string>
	 */
	public function hidden_fields( $fields ) {
		$fields = is_array( $fields ) ? $fields : array();

		foreach ( Attribution::keys() as $key ) {
			$name            = Attribution::PREFIX . $key;
			$fields[ $name ] = '';
		}

		return $fields;
	}

	/**
	 * Handle a successful Contact Form 7 submission.
	 *
	 * @param \WPCF7_ContactForm $contact_form The submitted form.
	 * @return void
	 */
	public function on_mail_sent( $contact_form ) {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}

		$submission = \WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$posted = $submission->get_posted_data();
		if ( ! is_array( $posted ) ) {
			return;
		}

		$fields = array();
		foreach ( $posted as $key => $value ) {
			$fields[ $key ] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}

		$form_id = method_exists( $contact_form, 'id' ) ? $contact_form->id() : 0;

		$this->maybe_forward_to_apointoo( $fields, $form_id );
	}

	/**
	 * Extract lead fields from CF7's flat posted-data map and forward to intake.
	 *
	 * CF7 default field names use "your-name" / "your-message" prefixes, so
	 * the name/message hints include those in addition to the generic set.
	 *
	 * @param array<string, string> $fields  Flat key→value map of posted CF7 fields.
	 * @param int                   $form_id CF7 form id (for the Forward_Log).
	 * @return void
	 */
	private function maybe_forward_to_apointoo( array $fields, $form_id = 0 ): void {
		if ( ! $this->is_intake_configured() ) {
			return;
		}

		$lead = array(
			'name'    => '',
			'email'   => '',
			'phone'   => '',
			'message' => '',
		);

		foreach ( $fields as $key => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$k = strtolower( (string) $key );
			if ( empty( $lead['email'] ) && $this->key_matches( $k, $this->email_hints ) ) {
				$lead['email'] = sanitize_email( $value );
			} elseif ( empty( $lead['name'] ) && $this->key_matches( $k, array( 'name', 'nome', 'your-name' ) ) ) {
				$lead['name'] = sanitize_text_field( $value );
			} elseif ( empty( $lead['phone'] ) && $this->key_matches( $k, $this->phone_hints ) ) {
				$lead['phone'] = sanitize_text_field( $value );
			} elseif ( empty( $lead['message'] ) && $this->key_matches( $k, array( 'message', 'mensagem', 'your-message' ) ) ) {
				$lead['message'] = sanitize_textarea_field( $value );
			}
		}

		$this->intake_send( array_filter( $lead, fn( $v ) => '' !== $v ), $form_id );
	}
}
