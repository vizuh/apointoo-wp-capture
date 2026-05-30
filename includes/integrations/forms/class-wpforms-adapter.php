<?php
/**
 * WPForms adapter (M3 stub).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

use Apointoo\Capture\Capture\Attribution;

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
		unset( $fields, $entry );
		$entry_id = absint( $entry_id );
		if ( ! $entry_id || ! function_exists( 'wpforms' ) ) {
			return; // No entry to attach to (e.g. WPForms Lite).
		}
		$wpforms = wpforms();
		$meta    = ( is_object( $wpforms ) && isset( $wpforms->entry_meta ) ) ? $wpforms->entry_meta : null;
		if ( ! is_object( $meta ) || ! method_exists( $meta, 'add' ) ) {
			return;
		}
		$form_id = ( is_array( $form_data ) && isset( $form_data['id'] ) ) ? absint( $form_data['id'] ) : 0;

		foreach ( Attribution::from_cookie() as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
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
		// @todo Paid tier: build a Lead from $fields + forward to Apointoo.
	}
}
