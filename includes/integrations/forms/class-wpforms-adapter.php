<?php
/**
 * WPForms adapter (M3 stub).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

use Apointoo\Capture\Capture\Attribution;
use Apointoo\Capture\Capture\Forward_Log;
use Apointoo\Capture\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures WPForms submissions.
 *
 * Free tier: attaches the first-party attribution to the entry as entry meta
 * (WPForms Pro — Lite has no entries, so it no-ops there). Paid forwarding to
 * Apointoo is deferred (contract-gated).
 */
class WPForms_Adapter extends Abstract_Form_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPForms' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'WPForms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_slug() {
		return 'wpforms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wpforms_process_complete', array( $this, 'on_process_complete' ), 10, 4 );

		// Hidden-field population via WPForms Dynamic Population (works on Lite).
		// Operators configure a hidden field with parameter name e.g. "apointoo_gclid";
		// WPForms calls wpforms_field_value_apointoo_gclid and we return the cookie value.
		foreach ( Attribution::keys() as $key ) {
			add_filter(
				'wpforms_field_value_' . Attribution::PREFIX . $key,
				array( $this, 'populate_field' ),
				10,
				3
			);
		}
	}

	/**
	 * Populate a WPForms hidden field from the attribution cookie.
	 *
	 * @param string $value     Current field value.
	 * @param array  $field     Field settings.
	 * @param array  $form_data Form definition.
	 * @return string
	 */
	public function populate_field( $value, $field, $form_data ) {
		$attribution = Attribution::from_cookie();
		$key         = str_replace( 'wpforms_field_value_', '', current_filter() );
		return isset( $attribution[ $key ] ) ? $attribution[ $key ] : $value;
	}

	/**
	 * Handle a completed WPForms submission.
	 *
	 * @param array      $fields    Sanitised fields (numeric-id keyed).
	 * @param array      $entry     Raw entry.
	 * @param array      $form_data Form definition (carries labels).
	 * @param int|string $entry_id  Entry id (may be 0 on WPForms Lite).
	 * @return void
	 */
	public function on_process_complete( $fields, $entry, $form_data, $entry_id ) {
		unset( $entry );
		$entry_id = absint( $entry_id );
		$form_id  = ( is_array( $form_data ) && isset( $form_data['id'] ) ) ? absint( $form_data['id'] ) : 0;

		// Entry meta path (WPForms Pro only — Lite has entry_id = 0).
		if ( $entry_id && function_exists( 'wpforms' ) ) {
			$wpforms = wpforms();
			$meta    = ( is_object( $wpforms ) && isset( $wpforms->entry_meta ) ) ? $wpforms->entry_meta : null;
			if ( is_object( $meta ) && method_exists( $meta, 'add' ) ) {
				foreach ( Attribution::from_cookie() as $key => $value ) {
					if ( '' !== $value ) {
						$meta->add(
							array(
								'entry_id' => $entry_id,
								'form_id'  => $form_id,
								'type'     => $key,
								'data'     => $value,
							),
							'entry_meta'
						);
					}
				}
			}
		}

		// Apointoo intake forward — runs on Lite and Pro.
		$this->maybe_forward_to_apointoo( $fields, $form_id );
	}

	/**
	 * Forward the submission to the Apointoo intake endpoint when credentials are set.
	 *
	 * Fire-and-forget (blocking=false) so the user's form response is never held up.
	 * Extracts name/email/phone/message from WPForms field objects by type + label hint.
	 *
	 * @param array $fields  Sanitised WPForms field array (numeric-id keyed).
	 * @param int   $form_id WPForms form id (for the forward log).
	 * @return void
	 */
	private function maybe_forward_to_apointoo( $fields, $form_id = 0 ) {
		$settings   = get_option( Settings::OPTION, array() );
		$site_key   = isset( $settings['site_key'] ) ? trim( (string) $settings['site_key'] ) : '';
		$intake_url = isset( $settings['sdk_url'] ) ? trim( (string) $settings['sdk_url'] ) : '';

		// Not configured yet — nothing to forward to. The Settings screen shows
		// the empty credential fields, so this needs no log entry.
		if ( '' === $site_key || '' === $intake_url ) {
			return;
		}

		$lead = array( 'name' => '', 'email' => '', 'phone' => '', 'message' => '' );

		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$value = isset( $field['value'] ) ? trim( (string) $field['value'] ) : '';
				if ( '' === $value ) {
					continue;
				}
				$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
				$label = strtolower( isset( $field['name'] ) ? (string) $field['name'] : $type );

				// Type-first, then shape/label fallbacks — a mislabeled or
				// plain-text email/phone field still gets recognised.
				if ( empty( $lead['email'] ) && ( 'email' === $type || is_email( $value ) ) ) {
					$lead['email'] = sanitize_email( $value );
				} elseif ( empty( $lead['name'] ) && ( 'name' === $type || false !== strpos( $label, 'name' ) || false !== strpos( $label, 'nome' ) ) ) {
					$lead['name'] = sanitize_text_field( $value );
				} elseif ( empty( $lead['phone'] ) && ( 'phone' === $type || false !== strpos( $label, 'phone' ) || false !== strpos( $label, 'tel' ) || preg_match( '/^[+\d][\d\s().\/-]{6,}$/', $value ) ) ) {
					$lead['phone'] = sanitize_text_field( $value );
				} elseif ( empty( $lead['message'] ) && ( 'textarea' === $type || false !== strpos( $label, 'message' ) || false !== strpos( $label, 'mensagem' ) ) ) {
					$lead['message'] = sanitize_textarea_field( $value );
				}
			}
		}

		// Drop empty strings so the intake schema doesn't fail optional-field checks.
		$lead = array_filter( $lead, fn( $v ) => '' !== $v );

		$attribution  = Attribution::to_intake_payload();
		$cookie       = Attribution::from_cookie();
		$has_identity = isset( $cookie['apointoo_visitor_id'] ) || isset( $cookie['apointoo_session_id'] );
		$have_email   = ! empty( $lead['email'] );
		$have_phone   = ! empty( $lead['phone'] );

		// The intake API requires at least one of email/phone — a submission with
		// neither would 400. Record why we skipped instead of failing silently.
		if ( ! $have_email && ! $have_phone ) {
			Forward_Log::record(
				array(
					'source'       => 'wpforms',
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

		$response = wp_remote_post(
			$intake_url,
			array(
				'headers'  => array(
					'Content-Type'          => 'application/json',
					'X-Apointoo-Tenant-Key' => $site_key,
				),
				'body'     => wp_json_encode(
					array(
						'lead'        => $lead,
						// Cast to object so an EMPTY attribution serialises as {}
						// not [] — the intake schema is z.record and rejects a
						// JSON array ("Expected record, received object"), which
						// silently 400'd every consent-gated lead with no tracking.
						'attribution' => (object) $attribution,
					)
				),
				'timeout'  => 8,
				'blocking' => true,
			)
		);

		$entry = array(
			'source'       => 'wpforms',
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
