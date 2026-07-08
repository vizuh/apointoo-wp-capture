<?php
/**
 * Fluent Forms adapter (M3 stub).
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

		$flat = array();
		foreach ( (array) $form_data as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $k => $v ) {
					if ( '' !== (string) $v ) {
						$flat[ strtolower( (string) $k ) ] = (string) $v;
					}
				}
			} elseif ( '' !== (string) $value ) {
				$flat[ strtolower( (string) $key ) ] = (string) $value;
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
