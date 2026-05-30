<?php
/**
 * WPForms adapter (M3 stub).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures WPForms submissions server-side (Path B).
 *
 * Structure + hook are in place; field extraction (numeric ids → labels via
 * `$form_data`) is the M3 task. The working reference adapter is CF7_Adapter.
 */
class WPForms_Adapter extends Abstract_Form_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPForms' );
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
		// @todo M3: resolve numeric field ids → email/phone via $form_data, then
		// $this->capture( $form_id, $flat_fields ). See docs/PLAN.md §4.
		unset( $fields, $entry, $form_data, $entry_id );
	}
}
