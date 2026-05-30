<?php
/**
 * Elementor Pro Forms adapter (M3 stub).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures Elementor Pro form submissions server-side (Path B).
 *
 * Structure + hook are in place; flattening `$record->get('fields')` is the M3 task.
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
		// @todo M3: flatten $record->get('fields') (id/title/value) → $flat_fields,
		// resolve a form id, then $this->capture(). See docs/PLAN.md §4.
		unset( $record, $ajax_handler );
	}
}
