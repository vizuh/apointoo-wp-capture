<?php
/**
 * Plugin orchestrator.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture;

use Apointoo\Capture\Admin\Settings;
use Apointoo\Capture\Capture\Tracker;
use Apointoo\Capture\Integrations\Form_Integration_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the capture subsystems together on each request.
 */
class Plugin {

	/**
	 * Form-integration manager (discovers + activates form adapters).
	 *
	 * @var Form_Integration_Manager|null
	 */
	private $forms = null;

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function run() {
		// Free tier: capture attribution first-party + inject into forms.
		( new Tracker() )->register();

		$this->forms = new Form_Integration_Manager();
		$this->forms->init();

		// Brevo SMTP relay — no-op when credentials are absent (ADR-001).
		add_action( 'phpmailer_init', array( $this, 'configure_mailer' ), 20 );

		if ( is_admin() ) {
			( new Settings() )->register();
		}

		/**
		 * Fires once the capture plugin has booted its subsystems.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'apointoo_capture_loaded', $this );
	}

	/**
	 * Configure PHPMailer to use Brevo SMTP when credentials are saved.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer PHPMailer instance.
	 * @return void
	 */
	public function configure_mailer( $mailer ) {
		$s    = get_option( Settings::OPTION, array() );
		$user = isset( $s['smtp_user'] ) ? $s['smtp_user'] : '';
		$pass = isset( $s['smtp_pass'] ) ? $s['smtp_pass'] : '';
		if ( ! $user || ! $pass ) {
			return;
		}
		$mailer->isSMTP();
		$mailer->Host       = isset( $s['smtp_host'] ) && $s['smtp_host'] ? $s['smtp_host'] : 'smtp-relay.brevo.com';
		$mailer->Port       = isset( $s['smtp_port'] ) && $s['smtp_port'] ? (int) $s['smtp_port'] : 587;
		$mailer->SMTPAuth   = true;
		$mailer->Username   = $user;
		$mailer->Password   = $pass;
		$mailer->SMTPSecure = 'tls';
	}

	/**
	 * Accessor for the form-integration manager.
	 *
	 * @return Form_Integration_Manager|null
	 */
	public function forms() {
		return $this->forms;
	}
}
