<?php
/**
 * Gravity Forms adapter (M3 stub).
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
 * Captures Gravity Forms submissions.
 *
 * Free tier: attaches the first-party attribution to the entry as entry meta
 * (visible in the entry detail + exports — no owner config). Paid forwarding to
 * Apointoo is deferred (contract-gated).
 */
class Gravity_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'GFForms' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Gravity Forms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_slug() {
		return 'gravityforms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'gform_after_submission', array( $this, 'on_after_submission' ), 10, 2 );
	}

	/**
	 * Handle a Gravity Forms submission.
	 *
	 * @param array $entry Entry (numeric-field-id keyed).
	 * @param array $form  Form definition (carries field labels).
	 * @return void
	 */
	public function on_after_submission( $entry, $form ) {
		if ( ! function_exists( 'gform_add_meta' ) || ! is_array( $entry ) ) {
			return;
		}
		$entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
		if ( ! $entry_id ) {
			return;
		}
		$form_id = ( is_array( $form ) && isset( $form['id'] ) ) ? absint( $form['id'] ) : 0;

		foreach ( Attribution::from_cookie() as $key => $value ) {
			if ( '' !== $value ) {
				gform_add_meta( $entry_id, $key, $value, $form_id );
			}
		}

		$flat = array();
		if ( is_array( $form ) && ! empty( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				$value = isset( $entry[ (string) $field->id ] ) ? trim( (string) $entry[ (string) $field->id ] ) : '';
				if ( '' === $value ) {
					continue;
				}
				$label = strtolower( trim( (string) $field->label ) );
				$type  = strtolower( (string) $field->type );
				if ( $label ) {
					$flat[ $label ] = $value;
				}
				if ( $type && ! isset( $flat[ $type ] ) ) {
					$flat[ $type ] = $value;
				}
			}
		}
		$this->maybe_forward_to_apointoo( $flat, $form_id );
	}

		private function maybe_forward_to_apointoo( array $flat, $form_id = 0 ): void {
		if ( ! $this->is_intake_configured() ) {
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

		$this->intake_send( array_filter( $lead, fn( $v ) => '' !== $v ), $form_id );
	}
}
