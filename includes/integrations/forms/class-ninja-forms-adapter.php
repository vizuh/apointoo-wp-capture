<?php
/**
 * Ninja Forms adapter.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

use Apointoo\Capture\Capture\Attribution;

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

		$sub_id = isset( $form_data['actions']['save']['sub_id'] )
			? absint( $form_data['actions']['save']['sub_id'] )
			: 0;
		if ( ! $sub_id ) {
			return;
		}

		foreach ( Attribution::from_cookie() as $key => $value ) {
			if ( '' !== $value ) {
				update_post_meta( $sub_id, $key, $value );
			}
		}

		// @todo Paid tier: build a Lead from $form_data['fields'] + forward to Apointoo.
	}
}
