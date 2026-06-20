<?php
/**
 * Ninja Forms adapter.
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
 * Captures Ninja Forms submissions.
 *
 * Free tier: attaches the first-party attribution to the submission as post meta
 * on the Ninja submission (`nf_sub`) record — visible in the submission detail
 * and exports, no owner config. The front-end tracker also fills hidden inputs
 * client-side (Ninja renders via JS, so the tracker's MutationObserver re-fills
 * the form when it mounts). Paid forwarding to Apointoo is deferred (contract-gated).
 */
class Ninja_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'Ninja_Forms' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Ninja Forms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_slug() {
		return 'ninja-forms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'ninja_forms_after_submission', array( $this, 'on_after_submission' ), 10, 1 );
	}

	/**
	 * Handle a Ninja Forms submission.
	 *
	 * @param array $form_data Ninja Forms submission payload.
	 * @return void
	 */
	public function on_after_submission( $form_data ) {
		if ( ! is_array( $form_data ) ) {
			return;
		}

		// Attribution meta — only when the Save action is active and produced a record.
		$sub_id = isset( $form_data['actions']['save']['sub_id'] )
			? absint( $form_data['actions']['save']['sub_id'] )
			: 0;
		if ( $sub_id ) {
			foreach ( Attribution::from_cookie() as $key => $value ) {
				if ( '' !== $value ) {
					update_post_meta( $sub_id, $key, $value );
				}
			}
		}

		// Intake forward — independent of sub_id so forms without the Save action still forward.
		$flat      = array();
		$form_id   = isset( $form_data['form_id'] ) ? absint( $form_data['form_id'] ) : 0;
		$nf_fields = isset( $form_data['fields'] ) ? (array) $form_data['fields'] : array();
		foreach ( $nf_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$value = isset( $field['value'] ) ? trim( (string) $field['value'] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$label = strtolower( (string) ( $field['label'] ?? '' ) );
			$type  = strtolower( (string) ( $field['type'] ?? '' ) );
			if ( $label ) {
				$flat[ $label ] = $value;
			}
			if ( $type && ! isset( $flat[ $type ] ) ) {
				$flat[ $type ] = $value;
			}
		}
		$this->maybe_forward_to_apointoo( $flat, $form_id );
	}

	/**
	 * @param array<string,string> $flat
	 * @param int                  $form_id
	 */
	private function maybe_forward_to_apointoo( array $flat, $form_id = 0 ) {
		$settings   = get_option( Settings::OPTION, array() );
		$site_key   = isset( $settings['site_key'] ) ? trim( (string) $settings['site_key'] ) : '';
		$intake_url = isset( $settings['sdk_url'] ) ? trim( (string) $settings['sdk_url'] ) : '';
		if ( '' === $site_key || '' === $intake_url ) {
			return;
		}
		$lead = array( 'name' => '', 'email' => '', 'phone' => '', 'message' => '' );
		foreach ( $flat as $k => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			if ( empty( $lead['email'] ) && $this->key_matches( $k, $this->email_hints ) ) {
				$lead['email'] = sanitize_email( $value );
			} elseif ( empty( $lead['name'] ) && $this->key_matches( $k, array( 'name', 'nome' ) ) ) {
				$lead['name'] = sanitize_text_field( $value );
			} elseif ( empty( $lead['phone'] ) && $this->key_matches( $k, $this->phone_hints ) ) {
				$lead['phone'] = sanitize_text_field( $value );
			} elseif ( empty( $lead['message'] ) && $this->key_matches( $k, array( 'message', 'mensagem' ) ) ) {
				$lead['message'] = sanitize_textarea_field( $value );
			}
		}
		$lead         = array_filter( $lead, fn( $v ) => '' !== $v );
		$attribution  = Attribution::to_intake_payload();
		$cookie       = Attribution::from_cookie();
		$has_identity = isset( $cookie['apointoo_visitor_id'] ) || isset( $cookie['apointoo_session_id'] );
		$have_email   = ! empty( $lead['email'] );
		$have_phone   = ! empty( $lead['phone'] );
		if ( ! $have_email && ! $have_phone ) {
			Forward_Log::record( array( 'source' => 'ninja-forms', 'form_id' => $form_id, 'ok' => false, 'code' => 0, 'wp_error' => 'SKIPPED: no email or phone', 'body' => '', 'have_email' => false, 'have_phone' => false, 'attr_count' => count( $attribution ), 'has_identity' => $has_identity ) );
			return;
		}
		$response = wp_remote_post( $intake_url, array( 'headers' => array( 'Content-Type' => 'application/json', 'X-Apointoo-Tenant-Key' => $site_key ), 'body' => wp_json_encode( array( 'lead' => $lead, 'attribution' => (object) $attribution ) ), 'timeout' => 8, 'blocking' => true ) );
		$entry = array( 'source' => 'ninja-forms', 'form_id' => $form_id, 'have_email' => $have_email, 'have_phone' => $have_phone, 'attr_count' => count( $attribution ), 'has_identity' => $has_identity );
		if ( is_wp_error( $response ) ) {
			$entry['ok'] = false; $entry['code'] = 0; $entry['wp_error'] = $response->get_error_message(); $entry['body'] = '';
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$entry['ok'] = ( $code >= 200 && $code < 300 ); $entry['code'] = $code; $entry['wp_error'] = ''; $entry['body'] = substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
		}
		Forward_Log::record( $entry );
	}
}
