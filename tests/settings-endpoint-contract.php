<?php
/** Standalone settings check: php tests/settings-endpoint-contract.php */

define( 'ABSPATH', __DIR__ );

$GLOBALS['apointoo_test_settings'] = array(
	'sdk_url' => 'https://dash.apointoo.com/api/intake/saved/contact',
);

function get_option( $name, $default = array() ) {
	return 'apointoo_capture_settings' === $name ? $GLOBALS['apointoo_test_settings'] : $default;
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function esc_url_raw( $url, $protocols = null ) {
	if ( is_array( $protocols ) && 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
		return '';
	}
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function add_settings_error() {}

function __( $text ) {
	return $text;
}

require_once dirname( __DIR__ ) . '/includes/admin/class-settings.php';

use Apointoo\Capture\Admin\Settings;

$settings = new Settings();
$valid    = $settings->sanitize(
	array(
		'site_key' => 'tenant-key',
		'sdk_url'  => 'https://dash.apointoo.com/api/intake/example',
	)
);
assert( 'https://dash.apointoo.com/api/intake/example/contact' === $valid['sdk_url'] );

$invalid = $settings->sanitize(
	array(
		'site_key' => 'tenant-key',
		'sdk_url'  => 'https://example.com/internal',
	)
);
assert( $GLOBALS['apointoo_test_settings']['sdk_url'] === $invalid['sdk_url'] );

$insecure = $settings->sanitize(
	array(
		'site_key' => 'tenant-key',
		'sdk_url'  => 'http://dash.apointoo.com/api/intake/example',
	)
);
assert( $GLOBALS['apointoo_test_settings']['sdk_url'] === $insecure['sdk_url'] );

echo "settings endpoint contract: ok\n";
