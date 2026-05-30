<?php
/**
 * Fluent Forms adapter (M3 stub).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

use Apointoo\Capture\Capture\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures Fluent Forms submissions.
 *
 * Free tier: attaches the first-party attribution to the submission as submission
 * meta (no owner config). Paid forwarding to Apointoo is deferred (contract-gated).
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
		$entry_id = absint( $entry_id );
		if ( ! $entry_id || ! class_exists( '\FluentForm\App\Helpers\Helper' ) ) {
			return;
		}
		$form_id = ( is_object( $form ) && isset( $form->id ) ) ? absint( $form->id ) : 0;

		foreach ( Attribution::from_cookie() as $key => $value ) {
			if ( '' !== $value ) {
				\FluentForm\App\Helpers\Helper::setSubmissionMeta( $entry_id, $key, $value, $form_id );
			}
		}
		// @todo Paid tier: build a Lead from $form_data + forward to Apointoo.
	}
}
