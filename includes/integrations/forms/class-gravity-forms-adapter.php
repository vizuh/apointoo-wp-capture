<?php
/**
 * Gravity Forms adapter (M3 stub).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

use Apointoo\Capture\Capture\Attribution;

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
		// @todo Paid tier: build a Lead from the fields + forward to Apointoo.
	}
}
