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
	 * Every managed key is emitted (empty or cookie-filled) so the tracker can
	 * fill them client-side on the first visit; CF7 then submits them.
	 *
	 * @param array<string, string> $fields Existing hidden fields.
	 * @return array<string, string>
	 */
	public function hidden_fields( $fields ) {
		$fields = is_array( $fields ) ? $fields : array();
		$attr   = Attribution::from_cookie();

		foreach ( Attribution::keys() as $key ) {
			$name            = Attribution::PREFIX . $key;
			$fields[ $name ] = isset( $attr[ $name ] ) ? $attr[ $name ] : '';
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
	 * Forward the submission to the Apointoo intake endpoint when credentials are set.
	 *
	 * @param array<string, string> $fields  Flat key→value map of posted CF7 fields.
	 * @param int                   $form_id CF7 form id (for the forward log).
	 * @return void
	 */
	private function maybe_forward_to_apointoo( array $fields, $form_id = 0 ) {
		$settings   = get_option( Settings::OPTION, array() );
		$site_key   = isset( $settings['site_key'] ) ? trim( (string) $settings['site_key'] ) : '';
		$intake_url = isset( $settings['sdk_url'] ) ? trim( (string) $settings['sdk_url'] ) : '';

		if ( '' === $site_key || '' === $intake_url ) {
			return;
		}

		$lead = array( 'name' => '', 'email' => '', 'phone' => '', 'message' => '' );

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

		$lead = array_filter( $lead, fn( $v ) => '' !== $v );

		$attribution  = Attribution::to_intake_payload();
		$cookie       = Attribution::from_cookie();
		$has_identity = isset( $cookie['apointoo_visitor_id'] ) || isset( $cookie['apointoo_session_id'] );
		$have_email   = ! empty( $lead['email'] );
		$have_phone   = ! empty( $lead['phone'] );

		if ( ! $have_email && ! $have_phone ) {
			Forward_Log::record( array(
				'source'       => 'cf7',
				'form_id'      => $form_id,
				'ok'           => false,
				'code'         => 0,
				'wp_error'     => 'SKIPPED: no email or phone extracted from form',
				'body'         => '',
				'have_email'   => false,
				'have_phone'   => false,
				'attr_count'   => count( $attribution ),
				'has_identity' => $has_identity,
			) );
			return;
		}

		$response = wp_remote_post(
			$intake_url,
			array(
				'headers' => array(
					'Content-Type'          => 'application/json',
					'X-Apointoo-Tenant-Key' => $site_key,
				),
				'body'    => wp_json_encode( array(
					'lead'        => $lead,
					'attribution' => (object) $attribution,
				) ),
				'timeout' => 8,
				'blocking' => true,
			)
		);

		$entry = array(
			'source'       => 'cf7',
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
}
