<?php
/**
 * Kadence Blocks Forms adapter.
 *
 * Forwards submissions from BOTH Kadence form blocks to the Apointoo intake:
 *   - advanced (wp:kadence/advanced-form) → action kadence_blocks_advanced_form_submission
 *                                           ($form_args, $processed_fields, $post_id)
 *   - legacy   (wp:kadence/form)          → action kadence_blocks_form_submission
 *                                           ($form_args, $fields, $form_id, $post_id)
 *
 * Both pass a field array whose entries carry ['label','type','value'] (the
 * advanced form adds 'name'/'uniqueID'), so a single extractor reads them. The
 * Kadence "Telefone" field is a plain text field, not a tel input, so phone
 * detection is label-aware rather than type-only.
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
 * Captures Kadence Blocks form submissions.
 */
class Kadence_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'KADENCE_BLOCKS_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Kadence Blocks';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_slug() {
		return 'kadence';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'kadence_blocks_advanced_form_submission', array( $this, 'on_advanced_form_submission' ), 10, 3 );
		add_action( 'kadence_blocks_form_submission', array( $this, 'on_legacy_form_submission' ), 10, 4 );
	}

	/**
	 * Handle a completed Kadence Advanced Form submission.
	 *
	 * @param array      $form_args        Form definition/config (unused).
	 * @param array      $processed_fields List of fields, each ['label','type','value','name',...].
	 * @param int|string $post_id          The kadence_form CPT id.
	 * @return void
	 */
	public function on_advanced_form_submission( $form_args, $processed_fields, $post_id ) {
		unset( $form_args );
		$this->maybe_forward_to_apointoo( is_array( $processed_fields ) ? $processed_fields : array(), $post_id );
	}

	/**
	 * Handle a completed Kadence legacy Form submission.
	 *
	 * @param array      $form_args Form definition/config (unused).
	 * @param array      $fields    Field map keyed by field id, each ['type','label','value'].
	 * @param int|string $form_id   The form's unique id.
	 * @param int|string $post_id   The page id (unused).
	 * @return void
	 */
	public function on_legacy_form_submission( $form_args, $fields, $form_id, $post_id ) {
		unset( $form_args, $post_id );
		$this->maybe_forward_to_apointoo( is_array( $fields ) ? $fields : array(), $form_id );
	}

	/**
	 * Extract name/email/phone/message from a Kadence field array.
	 *
	 * Handles both block shapes (entries carry label+type+value) and array
	 * values (multi-select). Phone detection is label-aware because Kadence
	 * phone fields are plain text inputs.
	 *
	 * @param array $fields Kadence field entries.
	 * @return array<string, string> Non-empty lead fields only.
	 */
	private function extract_lead( $fields ) {
		$lead = array(
			'name'    => '',
			'email'   => '',
			'phone'   => '',
			'message' => '',
		);

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? strtolower( (string) $field['type'] ) : '';
			// Skip non-lead field types: 'hidden' carries injected attribution /
			// tracking, 'file' carries an upload path — neither is a contact value,
			// and letting them through could claim a lead slot (B5).
			if ( in_array( $type, array( 'hidden', 'file' ), true ) ) {
				continue;
			}

			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$hint = strtolower(
				( isset( $field['label'] ) ? (string) $field['label'] : '' ) . ' ' .
				( isset( $field['name'] ) ? (string) $field['name'] : '' )
			);

			// Type-first, then label/shape fallbacks — Kadence "Telefone" is a plain
			// text field, so phone is matched by label too. Needles avoid the bare
			// "tel" substring (collides with words like "hotel"); the numeric-shape
			// fallback is gated off textarea so a numeric message isn't grabbed.
			if ( '' === $lead['email'] && ( 'email' === $type || is_email( $value ) ) ) {
				$lead['email'] = sanitize_email( $value );
			} elseif ( '' === $lead['phone'] && 'textarea' !== $type && ( 'tel' === $type || $this->key_matches( $hint, array( 'phone', 'telefone', 'telef', 'telep', 'telem', 'contacto', 'whatsapp', 'mobile' ) ) || preg_match( '/^[+\d][\d\s().\/-]{6,}$/', $value ) ) ) {
				$lead['phone'] = sanitize_text_field( $value );
			} elseif ( '' === $lead['name'] && $this->key_matches( $hint, array( 'name', 'nome' ) ) ) {
				$lead['name'] = sanitize_text_field( $value );
			} elseif ( '' === $lead['message'] && ( 'textarea' === $type || $this->key_matches( $hint, array( 'message', 'mensagem', 'comment', 'coment' ) ) ) ) {
				$lead['message'] = sanitize_textarea_field( $value );
			}
		}

		return array_filter( $lead, fn( $v ) => '' !== $v );
	}

	/**
	 * Forward the submission to the Apointoo intake endpoint when credentials are set.
	 *
	 * Mirrors the other form adapters: blocking POST, logged to the Forward_Log
	 * ring buffer, skipped (with a reason) when no email/phone is present.
	 *
	 * @param array      $fields  Kadence field entries.
	 * @param int|string $form_id Form identifier (for the forward log).
	 * @return void
	 */
	private function maybe_forward_to_apointoo( $fields, $form_id = 0 ) {
		$settings   = get_option( Settings::OPTION, array() );
		$site_key   = isset( $settings['site_key'] ) ? trim( (string) $settings['site_key'] ) : '';
		$intake_url = isset( $settings['sdk_url'] ) ? trim( (string) $settings['sdk_url'] ) : '';

		// Not configured yet — nothing to forward to.
		if ( '' === $site_key || '' === $intake_url ) {
			return;
		}

		$lead = $this->extract_lead( $fields );

		$attribution  = Attribution::to_intake_payload();
		$cookie       = Attribution::from_cookie();
		$has_identity = isset( $cookie['apointoo_visitor_id'] ) || isset( $cookie['apointoo_session_id'] );
		$have_email   = ! empty( $lead['email'] );
		$have_phone   = ! empty( $lead['phone'] );

		// The intake API requires at least one of email/phone — record why we
		// skipped instead of failing silently.
		if ( ! $have_email && ! $have_phone ) {
			Forward_Log::record(
				array(
					'source'       => 'kadence',
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
						// JSON array.
						'attribution' => (object) $attribution,
					)
				),
				'timeout'  => 8,
				'blocking' => true,
			)
		);

		$entry = array(
			'source'       => 'kadence',
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
