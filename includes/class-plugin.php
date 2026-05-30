<?php
/**
 * Plugin orchestrator.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture;

use Apointoo\Capture\Admin\Settings;
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
		$this->forms = new Form_Integration_Manager();
		$this->forms->init();

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
	 * Accessor for the form-integration manager.
	 *
	 * @return Form_Integration_Manager|null
	 */
	public function forms() {
		return $this->forms;
	}
}
