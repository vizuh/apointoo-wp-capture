<?php
/**
 * Gravity Forms adapter (M3 stub).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures Gravity Forms submissions server-side (Path B).
 *
 * Structure + hook are in place; field extraction (entry keyed by numeric field
 * id, names resolved from `$form['fields']`) is the M3 task.
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
		// @todo M3: walk $form['fields'] to map ids → email/phone, then
		// $this->capture( rgar( $form, 'id' ), $flat_fields ). See docs/PLAN.md §4.
		unset( $entry, $form );
	}
}
