<?php
/**
 * Elementor Pro Forms adapter (M3 stub).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

use Apointoo\Capture\Capture\Attribution;

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
		// @todo Paid tier: build a Lead from $record fields + forward to Apointoo.
	}
}
