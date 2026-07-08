<?php
/**
 * Elementor Pro Forms adapter (M3 stub).
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
 * Captures Elementor Pro form submissions.
 *
 * Free tier: adds the first-party attribution to the record as hidden fields so
 * it stores with the submission. Elementor's record API is less stable than the
 * others — calls are guarded and should be verified on a live site. Paid
 * forwarding to Apointoo is deferred (contract-gated).
 */
class Elementor_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Elementor Pro Forms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_slug() {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'elementor_pro/forms/new_record', array( $this, 'on_new_record' ), 10, 2 );
	}

	/**
	 * Handle an Elementor form record.
	 *
	 * @param object $record       Form record (has get('fields'), get_form_settings()).
	 * @param object $ajax_handler Ajax handler.
	 * @return void
	 */
	public function on_new_record( $record, $ajax_handler ) {
		unset( $ajax_handler );
		if ( ! is_object( $record ) || ! method_exists( $record, 'add_field' ) ) {
			return;
		}
		foreach ( Attribution::from_cookie() as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$record->add_field(
				array(
					'type'  => 'hidden',
					'id'    => $key,
					'value' => $value,
				)
			);
		}

		$flat     = array();
		$raw      = method_exists( $record, 'get' ) ? (array) $record->get( 'fields' ) : array();
		$form_id  = method_exists( $record, 'get_form_settings' ) ? (int) ( $record->get_form_settings( 'id' ) ?? 0 ) : 0;
		foreach ( $raw as $id => $field ) {
			$value = is_array( $field ) ? trim( (string) ( $field['value'] ?? '' ) ) : '';
			if ( '' === $value ) {
				continue;
			}
			$title = strtolower( is_array( $field ) ? (string) ( $field['title'] ?? $id ) : (string) $id );
			$type  = strtolower( is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '' );
			if ( $title ) {
				$flat[ $title ] = $value;
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
