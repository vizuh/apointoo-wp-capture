<?php
/**
 * Form-adapter contract.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every supported form plugin (CF7, WPForms, …) implements this.
 */
interface Form_Adapter_Interface {

	/**
	 * Is the target form plugin installed and active?
	 *
	 * @return bool
	 */
	public function is_active();

	/**
	 * Human-readable platform name (e.g. "Contact Form 7").
	 *
	 * @return string
	 */
	public function get_platform_name();

	/**
	 * Neutral platform slug used as the lead source (e.g. "contact-form-7").
	 *
	 * @return string
	 */
	public function get_platform_slug();

	/**
	 * Register the WordPress hooks this adapter binds (server-side submit hook).
	 *
	 * @return void
	 */
	public function register_hooks();
}
