<?php
/**
 * Contact Form 7 adapter.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures Contact Form 7 submissions server-side (Path B).
 *
 * Binds `wpcf7_mail_sent` (the clean-submit signal — fires only after the mail
 * succeeds, unlike `wpcf7_before_send_mail` which fires on failure too).
 */
class CF7_Adapter extends Abstract_Form_Adapter {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPCF7' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Contact Form 7';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_platform_slug() {
		return 'contact-form-7';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wpcf7_mail_sent', array( $this, 'on_mail_sent' ) );
	}

	/**
	 * Handle a successful Contact Form 7 submission.
	 *
	 * @param \WPCF7_ContactForm $contact_form The submitted form.
	 * @return void
	 */
	public function on_mail_sent( $contact_form ) {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}

		$submission = \WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$posted = $submission->get_posted_data();
		if ( ! is_array( $posted ) ) {
			return;
		}

		$fields = array();
		foreach ( $posted as $key => $value ) {
			$fields[ $key ] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}

		$form_id = method_exists( $contact_form, 'id' ) ? $contact_form->id() : 0;

		$this->capture( $form_id, $fields );
	}
}
