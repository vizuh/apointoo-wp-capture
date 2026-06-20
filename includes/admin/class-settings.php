<?php
/**
 * Settings screen.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Admin;

use Apointoo\Capture\Capture\Attribution;
use Apointoo\Capture\Capture\Forward_Log;

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
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_post_apointoo_send_test', array( $this, 'handle_send_test' ) );
	}

	/**
	 * Redirect to settings page on first activation (ADR-001).
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! get_option( 'apointoo_capture_activation_redirect' ) ) {
			return;
		}
		delete_option( 'apointoo_capture_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check of WP core's bulk-activation query var; no form data is processed.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
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
			'sdk_url',
			__( 'Intake endpoint URL', 'apointoo-capture' ),
			array( $this, 'field_sdk_url' ),
			self::PAGE,
			'apointoo_capture_connection'
		);

		add_settings_field(
			'debug',
			__( 'Debug logging', 'apointoo-capture' ),
			array( $this, 'field_debug' ),
			self::PAGE,
			'apointoo_capture_connection'
		);

		add_settings_section(
			'apointoo_capture_smtp',
			__( 'Email (SMTP)', 'apointoo-capture' ),
			array( $this, 'section_smtp' ),
			self::PAGE
		);

		add_settings_field(
			'smtp_user',
			__( 'SMTP username', 'apointoo-capture' ),
			array( $this, 'field_smtp_user' ),
			self::PAGE,
			'apointoo_capture_smtp'
		);

		add_settings_field(
			'smtp_pass',
			__( 'SMTP password', 'apointoo-capture' ),
			array( $this, 'field_smtp_pass' ),
			self::PAGE,
			'apointoo_capture_smtp'
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
	 * SMTP section description.
	 *
	 * @return void
	 */
	public function section_smtp() {
		echo '<p>';
		echo esc_html__(
			'Optional. When credentials are saved the plugin takes over wp_mail() via Brevo SMTP — deactivate WP Mail SMTP if also installed.',
			'apointoo-capture'
		);
		echo '</p>';

		$saved = $this->get_settings();
		$host  = isset( $saved['smtp_host'] ) ? $saved['smtp_host'] : 'smtp-relay.brevo.com';
		$port  = isset( $saved['smtp_port'] ) ? $saved['smtp_port'] : '587';
		printf(
			'<details style="margin-bottom:1em"><summary style="cursor:pointer;color:#2271b1">%s</summary>
<table class="form-table" style="margin-top:.5em"><tbody>
<tr><th scope="row">%s</th><td><input type="text" class="regular-text" name="%s[smtp_host]" value="%s" /></td></tr>
<tr><th scope="row">%s</th><td><input type="number" class="small-text" name="%s[smtp_port]" value="%s" min="1" max="65535" /></td></tr>
</tbody></table></details>',
			esc_html__( 'Advanced', 'apointoo-capture' ),
			esc_html__( 'SMTP host', 'apointoo-capture' ),
			esc_attr( self::OPTION ),
			esc_attr( $host ),
			esc_html__( 'SMTP port', 'apointoo-capture' ),
			esc_attr( self::OPTION ),
			esc_attr( (string) $port )
		);
	}

	/**
	 * Render SMTP username field.
	 *
	 * @return void
	 */
	public function field_smtp_user() {
		$saved = $this->get_settings();
		$value = isset( $saved['smtp_user'] ) ? $saved['smtp_user'] : '';
		printf(
			'<input type="text" class="regular-text" name="%1$s[smtp_user]" value="%2$s" autocomplete="off" />',
			esc_attr( self::OPTION ),
			esc_attr( $value )
		);
	}

	/**
	 * Render SMTP password field (write-only).
	 *
	 * @return void
	 */
	public function field_smtp_pass() {
		$saved       = $this->get_settings();
		$has         = ! empty( $saved['smtp_pass'] );
		$placeholder = $has ? __( 'Saved — leave blank to keep current', 'apointoo-capture' ) : '';
		printf(
			'<input type="password" class="regular-text" name="%1$s[smtp_pass]" value="" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $placeholder )
		);
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
			'Kadence Blocks' => defined( 'KADENCE_BLOCKS_VERSION' ),
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
			'<input type="text" class="regular-text" name="%1$s[site_key]" value="%2$s" autocomplete="off" />
			<p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( $value ),
			esc_html__( 'Copy from Apointoo dashboard → tenant created screen, or Admin → Tenants → Rotate API key. Shown once at tenant creation.', 'apointoo-capture' )
		);
	}

	/**
	 * Placeholder — field removed, kept to avoid fatal on stale option data.
	 *
	 * @return void
	 */

	/**
	 * Render the intake endpoint URL field.
	 *
	 * @return void
	 */
	public function field_sdk_url() {
		$saved = $this->get_settings();
		$value = isset( $saved['sdk_url'] ) ? $saved['sdk_url'] : '';
		printf(
			'<input type="url" class="regular-text" name="%1$s[sdk_url]" value="%2$s" placeholder="https://dash.apointoo.com/api/intake/your-slug/contact" />
			<p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( $value ),
			esc_html__( 'Full intake URL from your Apointoo dashboard → Settings → Install & integration. Example: https://dash.apointoo.com/api/intake/feathers-houses/contact', 'apointoo-capture' )
		);
	}

	/**
	 * Render the debug-logging checkbox.
	 *
	 * @return void
	 */
	public function field_debug() {
		$saved = $this->get_settings();
		printf(
			'<label><input type="checkbox" name="%1$s[debug]" value="1" %2$s /> %3$s</label>
			<p class="description">%4$s</p>',
			esc_attr( self::OPTION ),
			checked( ! empty( $saved['debug'] ), true, false ),
			esc_html__( 'Mirror each forward to the PHP error log', 'apointoo-capture' ),
			esc_html__( 'The Recent forwards table below records the last 10 attempts regardless. Tick this to also write them to the server error log.', 'apointoo-capture' )
		);
	}

	/**
	 * Sanitise + persist.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, string>
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$saved = $this->get_settings();
		$out   = array();

		// Strip whitespace and any trailing colon that gets copied when the key
		// is pasted from a KEY: value env snippet.
		$out['site_key']  = isset( $input['site_key'] ) ? rtrim( sanitize_text_field( $input['site_key'] ), ': ' ) : '';
		// Append /contact if the URL ends at the tenant slug (no path segment).
		$raw_url = isset( $input['sdk_url'] ) ? esc_url_raw( trim( $input['sdk_url'] ) ) : '';
		if ( '' !== $raw_url ) {
			$path    = (string) ( wp_parse_url( $raw_url, PHP_URL_PATH ) ?: '' );
			if ( ! str_ends_with( $path, '/contact' ) ) {
				$raw_url = rtrim( $raw_url, '/' ) . '/contact';
			}
		}
		$out['sdk_url'] = $raw_url;
		$out['debug']     = empty( $input['debug'] ) ? 0 : 1;
		$out['smtp_host'] = isset( $input['smtp_host'] ) ? sanitize_text_field( $input['smtp_host'] ) : 'smtp-relay.brevo.com';
		$out['smtp_port'] = isset( $input['smtp_port'] ) ? absint( $input['smtp_port'] ) : 587;
		$out['smtp_user'] = isset( $input['smtp_user'] ) ? sanitize_email( $input['smtp_user'] ) : '';

		// smtp_pass is write-only: preserve stored value when field is left blank.
		$smtp_pass = isset( $input['smtp_pass'] ) ? trim( (string) $input['smtp_pass'] ) : '';
		$out['smtp_pass'] = '' === $smtp_pass ? ( isset( $saved['smtp_pass'] ) ? $saved['smtp_pass'] : '' ) : sanitize_text_field( $smtp_pass );

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
			<?php $this->render_diagnostics(); ?>
		</div>
		<?php
	}

	/**
	 * Diagnostics block: the last test result, a "Send test lead" button, and
	 * the Recent forwards table. Lives outside the Settings API form so the
	 * test can POST to admin-post.php.
	 *
	 * @return void
	 */
	private function render_diagnostics() {
		$saved      = $this->get_settings();
		$configured = ! empty( $saved['site_key'] ) && ! empty( $saved['sdk_url'] );

		echo '<hr style="margin:2em 0" />';
		echo '<h2>' . esc_html__( 'Diagnostics', 'apointoo-capture' ) . '</h2>';

		// Inline result of this user's last "Send test lead".
		$key    = 'apointoo_test_result_' . get_current_user_id();
		$result = get_transient( $key );
		if ( is_array( $result ) ) {
			delete_transient( $key );
			$ok = ! empty( $result['ok'] );
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' inline" style="padding:.5em 1em">';
			if ( '' !== (string) ( $result['wp_error'] ?? '' ) ) {
				echo '<p><strong>' . esc_html__( 'Network error:', 'apointoo-capture' ) . '</strong> ' . esc_html( $result['wp_error'] ) . '</p>';
			} else {
				echo '<p><strong>HTTP ' . esc_html( (string) ( $result['code'] ?? '' ) ) . '</strong></p>';
				if ( '' !== (string) ( $result['body'] ?? '' ) ) {
					echo '<pre style="white-space:pre-wrap;margin:0">' . esc_html( (string) $result['body'] ) . '</pre>';
				}
			}
			echo '</div>';
		}

		if ( $configured ) {
			printf(
				'<form action="%1$s" method="post" style="margin:1em 0">%2$s<input type="hidden" name="action" value="apointoo_send_test" />%3$s</form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'apointoo_send_test', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				get_submit_button( __( 'Send test lead', 'apointoo-capture' ), 'secondary', 'submit', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			echo '<p class="description">' . esc_html__( 'Posts a dummy lead to the intake endpoint and shows the exact response — the fastest way to confirm the key, endpoint, and tenant are healthy.', 'apointoo-capture' ) . '</p>';
		} else {
			echo '<p><em>' . esc_html__( 'Save a publishable site key and intake URL above to enable the test.', 'apointoo-capture' ) . '</em></p>';
		}

		echo '<h3>' . esc_html__( 'Recent forwards', 'apointoo-capture' ) . '</h3>';
		$rows = Forward_Log::all();
		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No forwards recorded yet. Submit a form or send a test lead.', 'apointoo-capture' ) . '</em></p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:820px"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'apointoo-capture' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'apointoo-capture' ) . '</th>';
		echo '<th>' . esc_html__( 'Form', 'apointoo-capture' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'apointoo-capture' ) . '</th>';
		echo '<th>' . esc_html__( 'Detail', 'apointoo-capture' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'apointoo-capture' ) . '</th>';
		echo '<th>' . esc_html__( 'Phone', 'apointoo-capture' ) . '</th>';
		echo '<th>' . esc_html__( 'Attrib', 'apointoo-capture' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$ok     = ! empty( $r['ok'] );
			$code   = isset( $r['code'] ) ? (int) $r['code'] : 0;
			$label  = ( $ok ? '✓ ' : '✗ ' ) . ( $code ? $code : '—' );
			$detail = '' !== (string) ( $r['wp_error'] ?? '' ) ? (string) $r['wp_error'] : (string) ( $r['body'] ?? '' );
			$attr   = isset( $r['attr_count'] ) ? (int) $r['attr_count'] : 0;
			$attrlabel = ( 0 === $attr && empty( $r['has_identity'] ) ) ? '0 (no cookie)' : (string) $attr;
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td style="color:%4$s">%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td><td>%9$s</td></tr>',
				esc_html( isset( $r['ts'] ) ? wp_date( 'Y-m-d H:i:s', (int) $r['ts'] ) : '' ),
				esc_html( (string) ( $r['source'] ?? '' ) ),
				esc_html( (string) ( $r['form_id'] ?? '' ) ),
				$ok ? '#008a20' : '#b32d2e',
				esc_html( $label ),
				esc_html( mb_substr( $detail, 0, 140 ) ),
				! empty( $r['have_email'] ) ? '✓' : '—',
				! empty( $r['have_phone'] ) ? '✓' : '—',
				esc_html( $attrlabel )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Handle the "Send test lead" admin-post action: one synchronous POST to the
	 * configured intake endpoint, the exact response stashed in a per-user
	 * transient for the Settings screen to render, then redirect back.
	 *
	 * @return void
	 */
	public function handle_send_test() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'apointoo-capture' ) );
		}
		check_admin_referer( 'apointoo_send_test' );

		$saved      = $this->get_settings();
		$site_key   = isset( $saved['site_key'] ) ? trim( (string) $saved['site_key'] ) : '';
		$intake_url = isset( $saved['sdk_url'] ) ? trim( (string) $saved['sdk_url'] ) : '';

		$result = array(
			'ok'       => false,
			'code'     => 0,
			'wp_error' => '',
			'body'     => '',
		);

		if ( '' === $site_key || '' === $intake_url ) {
			$result['wp_error'] = __( 'Site key or intake URL not set.', 'apointoo-capture' );
		} else {
			$attribution = Attribution::to_intake_payload();
			$lead        = array(
				'name'    => 'Apointoo Test',
				'email'   => 'test+' . time() . '@apointoo.com',
				'message' => 'Send-test from Apointoo Capture (WordPress).',
			);
			$response = wp_remote_post(
				$intake_url,
				array(
					'headers'  => array(
						'Content-Type'          => 'application/json',
						'X-Apointoo-Tenant-Key' => $site_key,
					),
					'body'     => wp_json_encode(
						array(
							'lead'        => $lead,
							// Object cast: empty attribution must serialise as {} not
							// [] or the intake z.record schema 400s. See the adapter.
							'attribution' => (object) $attribution,
						)
					),
					'timeout'  => 8,
					'blocking' => true,
				)
			);

			$entry = array(
				'source'       => 'test',
				'form_id'      => 0,
				'have_email'   => true,
				'have_phone'   => false,
				'attr_count'   => count( $attribution ),
				'has_identity' => false,
			);

			if ( is_wp_error( $response ) ) {
				$result['wp_error'] = $response->get_error_message();
				$entry['ok']        = false;
				$entry['code']      = 0;
				$entry['wp_error']  = $result['wp_error'];
				$entry['body']      = '';
			} else {
				$code            = (int) wp_remote_retrieve_response_code( $response );
				$body            = substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
				$result['ok']    = ( $code >= 200 && $code < 300 );
				$result['code']  = $code;
				$result['body']  = $body;
				$entry['ok']     = $result['ok'];
				$entry['code']   = $code;
				$entry['wp_error'] = '';
				$entry['body']   = $body;
			}
			Forward_Log::record( $entry );
		}

		set_transient( 'apointoo_test_result_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}
}
