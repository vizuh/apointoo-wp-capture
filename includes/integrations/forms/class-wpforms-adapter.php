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
		return function_exists( 'wpforms' ) || class_exists( 'WPForms\\WPForms' ) || class_exists( 'WPForms' );
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

		// Empty hidden fields let the consent-aware tracker populate per visitor.
		// Operators configure a hidden field with parameter name e.g. "apointoo_gclid";
		// WPForms renders the field, then the tracker fills it after consent.
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
	 * Keep server-rendered WPForms hidden fields empty for page-cache safety.
	 *
	 * @param string $value     Current field value.
	 * @param array  $field     Field settings.
	 * @param array  $form_data Form definition.
	 * @return string
	 */
	public function populate_field( $value, $field, $form_data ) {
		unset( $value, $field, $form_data );
		return '';
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
	 * Extract name/email/phone/message from WPForms field objects (type + label hint)
	 * and forward to the intake API.
	 *
	 * WPForms fields are objects keyed by numeric id, each with 'type', 'name', 'value'.
	 * Type-first matching catches mislabeled fields; label/shape fallbacks handle
	 * plain-text email/phone inputs.
	 *
	 * @param array $fields  Sanitised WPForms field array (numeric-id keyed).
	 * @param int   $form_id WPForms form id (for the Forward_Log).
	 * @return void
	 */
	private function maybe_forward_to_apointoo( $fields, $form_id = 0 ): void {
		if ( ! $this->is_intake_configured() ) {
			return;
		}

		$lead = array(
			'name'    => '',
			'email'   => '',
			'phone'   => '',
			'message' => '',
		);

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

		$this->intake_send( array_filter( $lead, fn( $v ) => '' !== $v ), $form_id );
	}
}
