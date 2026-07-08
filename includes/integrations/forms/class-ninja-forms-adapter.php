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
