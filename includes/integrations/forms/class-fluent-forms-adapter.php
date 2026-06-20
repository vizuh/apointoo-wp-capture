<?php
/**
 * Fluent Forms adapter (M3 stub).
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
 * Captures Fluent Forms submissions.
 *
 * Free tier: attaches the first-party attribution to the submission as submission
 * meta (no owner config). Paid forwarding to Apointoo is deferred (contract-gated).
 */
class Fluent_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'FLUENTFORM' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Fluent Forms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_slug() {
		return 'fluentforms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'fluentform/submission_inserted', array( $this, 'on_submission_inserted' ), 20, 3 );
	}

	/**
	 * Handle an inserted Fluent Forms submission.
	 *
	 * @param int    $entry_id  Submission id.
	 * @param array  $form_data Submitted data (input-name keyed).
	 * @param object $form      Form object.
	 * @return void
	 */
	public function on_submission_inserted( $entry_id, $form_data, $form ) {
		$entry_id = absint( $entry_id );
		if ( ! $entry_id || ! class_exists( '\FluentForm\App\Helpers\Helper' ) ) {
			return;
		}
		$form_id = ( is_object( $form ) && isset( $form->id ) ) ? absint( $form->id ) : 0;

		foreach ( Attribution::from_cookie() as $key => $value ) {
			if ( '' !== $value ) {
				\FluentForm\App\Helpers\Helper::setSubmissionMeta( $entry_id, $key, $value, $form_id );
			}
		}

		$flat = array();
		foreach ( (array) $form_data as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $k => $v ) {
					if ( '' !== (string) $v ) {
						$flat[ strtolower( (string) $k ) ] = (string) $v;
					}
				}
			} elseif ( '' !== (string) $value ) {
				$flat[ strtolower( (string) $key ) ] = (string) $value;
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
			Forward_Log::record( array( 'source' => 'fluentforms', 'form_id' => $form_id, 'ok' => false, 'code' => 0, 'wp_error' => 'SKIPPED: no email or phone', 'body' => '', 'have_email' => false, 'have_phone' => false, 'attr_count' => count( $attribution ), 'has_identity' => $has_identity ) );
			return;
		}
		$response = wp_remote_post( $intake_url, array( 'headers' => array( 'Content-Type' => 'application/json', 'X-Apointoo-Tenant-Key' => $site_key ), 'body' => wp_json_encode( array( 'lead' => $lead, 'attribution' => (object) $attribution ) ), 'timeout' => 8, 'blocking' => true ) );
		$entry = array( 'source' => 'fluentforms', 'form_id' => $form_id, 'have_email' => $have_email, 'have_phone' => $have_phone, 'attr_count' => count( $attribution ), 'has_identity' => $has_identity );
		if ( is_wp_error( $response ) ) {
			$entry['ok'] = false; $entry['code'] = 0; $entry['wp_error'] = $response->get_error_message(); $entry['body'] = '';
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$entry['ok'] = ( $code >= 200 && $code < 300 ); $entry['code'] = $code; $entry['wp_error'] = ''; $entry['body'] = substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
		}
		Forward_Log::record( $entry );
	}
}
