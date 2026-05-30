<?php
/**
 * Form-integration manager.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations;

use Apointoo\Capture\Capture\SDK_Transport;
use Apointoo\Capture\Capture\Transport_Interface;
use Apointoo\Capture\Integrations\Forms\Form_Adapter_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers, instantiates and activates the form adapters whose target plugin
 * is present. Mirrors the ClickTrail manager pattern.
 */
class Form_Integration_Manager {

	/**
	 * Instantiated adapters (active or not).
	 *
	 * @var Form_Adapter_Interface[]
	 */
	private $adapters = array();

	/**
	 * Outbound transport shared by every adapter.
	 *
	 * @var Transport_Interface
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param Transport_Interface|null $transport Optional transport (defaults to the SDK stub).
	 */
	public function __construct( ?Transport_Interface $transport = null ) {
		$this->transport = $transport ? $transport : new SDK_Transport();
	}

	/**
	 * Register adapters then bind hooks for the active ones.
	 *
	 * @return void
	 */
	public function init() {
		$this->register_adapters();

		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->is_active() ) {
				$adapter->register_hooks();
			}
		}
	}

	/**
	 * Instantiate the known adapters.
	 *
	 * To add a form plugin: drop a `class-{slug}-adapter.php` in `forms/`,
	 * extend `Abstract_Form_Adapter`, and add it to the map below.
	 *
	 * @return void
	 */
	private function register_adapters() {
		$classes = array(
			'Apointoo\Capture\Integrations\Forms\CF7_Adapter',
			'Apointoo\Capture\Integrations\Forms\WPForms_Adapter',
			'Apointoo\Capture\Integrations\Forms\Gravity_Forms_Adapter',
			'Apointoo\Capture\Integrations\Forms\Elementor_Forms_Adapter',
			'Apointoo\Capture\Integrations\Forms\Fluent_Forms_Adapter',
		);

		/**
		 * Filter the list of form-adapter classes the manager loads.
		 *
		 * @param string[] $classes Fully-qualified adapter class names.
		 */
		$classes = apply_filters( 'apointoo_capture_form_adapters', $classes );

		foreach ( $classes as $class_name ) {
			if ( class_exists( $class_name ) ) {
				$this->adapters[] = new $class_name( $this->transport );
			}
		}
	}

	/**
	 * Adapters whose target plugin is active this request.
	 *
	 * @return Form_Adapter_Interface[]
	 */
	public function get_active_adapters() {
		return array_values(
			array_filter(
				$this->adapters,
				static function ( Form_Adapter_Interface $adapter ) {
					return $adapter->is_active();
				}
			)
		);
	}
}
