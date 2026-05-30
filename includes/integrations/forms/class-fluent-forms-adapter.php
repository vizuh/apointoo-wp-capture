<?php
/**
 * Fluent Forms adapter (M3 stub).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures Fluent Forms submissions server-side (Path B).
 *
 * Structure + hook are in place; reading `$form_data` (name-keyed) is the M3 task.
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
		// @todo M3: read $form_data (name-keyed) → $flat_fields, then
		// $this->capture( $form->id, $flat_fields ). See docs/PLAN.md §4.
		unset( $entry_id, $form_data, $form );
	}
}
