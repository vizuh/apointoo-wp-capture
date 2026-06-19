<?php
/**
 * Settings screen.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal options screen under Settings → Apointoo Capture.
 *
 * Stores the credentials the Apointoo dashboard issues (publishable site key +
 * server secret) and the SDK endpoint. The server secret is write-only in the UI
 * — it is never echoed back. Uses the Settings API, so nonce handling, capability
 * checks, and the save flow go through core `options.php`.
 */
class Settings {

	const OPTION = 'apointoo_capture_settings';
	const GROUP  = 'apointoo_capture';
	const PAGE   = 'apointoo-capture';
	const CAP    = 'manage_options';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the Settings → Apointoo Capture page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Apointoo Capture', 'apointoo-capture' ),
			__( 'Apointoo Capture', 'apointoo-capture' ),
			self::CAP,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the setting, section, and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'apointoo_capture_connection',
			__( 'Connection', 'apointoo-capture' ),
			array( $this, 'section_intro' ),
			self::PAGE
		);

		add_settings_field(
			'site_key',
			__( 'Publishable site key', 'apointoo-capture' ),
			array( $this, 'field_site_key' ),
			self::PAGE,
			'apointoo_capture_connection'
		);

		add_settings_field(
			'server_secret',
			__( 'Server secret', 'apointoo-capture' ),
			array( $this, 'field_server_secret' ),
			self::PAGE,
			'apointoo_capture_connection'
		);

		add_settings_field(
			'sdk_url',
			__( 'SDK endpoint URL', 'apointoo-capture' ),
			array( $this, 'field_sdk_url' ),
			self::PAGE,
			'apointoo_capture_connection'
		);

		add_settings_section(
			'apointoo_capture_forms',
			__( 'Form integrations', 'apointoo-capture' ),
			array( $this, 'section_forms' ),
			self::PAGE
		);
	}

	/**
	 * Section description.
	 *
	 * @return void
	 */
	public function section_intro() {
		echo '<p>';
		echo esc_html__(
			'Enter the credentials issued by your Apointoo dashboard. Nothing is sent until these are set.',
			'apointoo-capture'
		);
		echo '</p>';
	}

	/**
	 * Form-integration diagnostic.
	 *
	 * Lists the supported form plugins and whether each is active, so the owner can
	 * confirm attribution is wired. Catches the silent-capture failure mode: an
	 * "Not installed" plugin will not carry attribution on its submissions.
	 *
	 * @return void
	 */
	public function section_forms() {
		$detected = array(
			'Contact Form 7' => class_exists( 'WPCF7' ),
			'Gravity Forms'  => class_exists( 'GFForms' ),
			'WPForms'        => function_exists( 'wpforms' ) || class_exists( 'WPForms\\WPForms' ),
			'Fluent Forms'   => defined( 'FLUENTFORM' ) || function_exists( 'wpFluentForm' ),
			'Elementor'      => did_action( 'elementor/loaded' ) || class_exists( 'ElementorPro\\Plugin' ),
			'Ninja Forms'    => class_exists( 'Ninja_Forms' ),
		);

		echo '<p>';
		echo esc_html__(
			'Attribution rides supported forms as hidden fields (filled in the browser, with a server-side fallback) and is also stored with each submission. Active plugins below are wired automatically.',
			'apointoo-capture'
		);
		echo '</p>';

		echo '<table class="widefat striped" style="max-width:520px"><tbody>';
		$any = false;
		foreach ( $detected as $label => $active ) {
			$any = $any || $active;
			echo '<tr><td>' . esc_html( $label ) . '</td><td>';
			if ( $active ) {
				echo '<strong style="color:#008a20">' . esc_html__( 'Detected — capturing', 'apointoo-capture' ) . '</strong>';
			} else {
				echo '<span style="color:#646970">' . esc_html__( 'Not installed', 'apointoo-capture' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( ! $any ) {
			echo '<p><em>';
			echo esc_html__( 'No supported form plugin detected yet. Attribution is still captured and stored; it will ride a form once one of the plugins above is active.', 'apointoo-capture' );
			echo '</em></p>';
		}
	}

	/**
	 * Read the saved settings.
	 *
	 * @return array<string, string>
	 */
	private function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Render the publishable-key field.
	 *
	 * @return void
	 */
	public function field_site_key() {
		$saved = $this->get_settings();
		$value = isset( $saved['site_key'] ) ? $saved['site_key'] : '';
		printf(
			'<input type="text" class="regular-text" name="%1$s[site_key]" value="%2$s" autocomplete="off" />',
			esc_attr( self::OPTION ),
			esc_attr( $value )
		);
	}

	/**
	 * Render the server-secret field (write-only; never echoes the stored value).
	 *
	 * @return void
	 */
	public function field_server_secret() {
		$saved       = $this->get_settings();
		$has         = ! empty( $saved['server_secret'] );
		$placeholder = $has ? __( 'Saved — leave blank to keep current', 'apointoo-capture' ) : '';
		printf(
			'<input type="password" class="regular-text" name="%1$s[server_secret]" value="" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Render the SDK URL field.
	 *
	 * @return void
	 */
	public function field_sdk_url() {
		$saved = $this->get_settings();
		$value = isset( $saved['sdk_url'] ) ? $saved['sdk_url'] : '';
		printf(
			'<input type="url" class="regular-text" name="%1$s[sdk_url]" value="%2$s" placeholder="https://" />',
			esc_attr( self::OPTION ),
			esc_attr( $value )
		);
	}

	/**
	 * Sanitise + persist. The server secret is preserved when the field is blank.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, string>
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$saved = $this->get_settings();
		$out   = array();

		$out['site_key'] = isset( $input['site_key'] ) ? sanitize_text_field( $input['site_key'] ) : '';
		$out['sdk_url']  = isset( $input['sdk_url'] ) ? esc_url_raw( $input['sdk_url'] ) : '';

		$secret = isset( $input['server_secret'] ) ? trim( (string) $input['server_secret'] ) : '';
		if ( '' === $secret ) {
			$out['server_secret'] = isset( $saved['server_secret'] ) ? $saved['server_secret'] : '';
		} else {
			$out['server_secret'] = sanitize_text_field( $secret );
		}

		return $out;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Apointoo Capture', 'apointoo-capture' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
