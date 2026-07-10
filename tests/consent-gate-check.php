<?php
/**
 * Standalone check: the intake forward strips attribution without marketing consent.
 *
 * Run with plain PHP (no WordPress, no PHPUnit): `php tests/consent-gate-check.php`.
 * Exits non-zero on failure. Covers the F05 consent gate in
 * Abstract_Form_Adapter::intake_send() — attribution (click ids, UTMs,
 * visitor/session ids) is only sent with a marketing grant; the lead contact
 * fields are always sent.
 *
 * ponytail: minimal WP-function stubs instead of a full phpunit/WP test harness;
 * upgrade to the configured phpunit.xml.dist tree when tests/bootstrap.php lands.
 *
 * @package Apointoo\Capture
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

// ── Minimal WP stubs (only what intake_send()'s path touches) ────────────────
$GLOBALS['__options'] = array(
	'apointoo_capture_settings' => array(
		'site_key' => 'pk_test',
		'sdk_url'  => 'https://example.test/intake',
	),
);
$GLOBALS['__posts'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function is_email( $value ) {
	return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}
function is_wp_error( $thing ) {
	return false;
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_remote_post( $url, $args ) {
	$GLOBALS['__posts'][] = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' );
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

// ── Plugin classes under test ────────────────────────────────────────────────
$base = dirname( __DIR__ );
require $base . '/includes/admin/class-settings.php';
require $base . '/includes/capture/class-attribution.php';
require $base . '/includes/capture/class-consent.php';
require $base . '/includes/capture/class-forward-log.php';
require $base . '/includes/integrations/forms/interface-form-adapter.php';
require $base . '/includes/integrations/forms/class-abstract-form-adapter.php';

// Minimal concrete adapter: expose the shared intake_send() chokepoint.
class Check_Adapter extends \Apointoo\Capture\Integrations\Forms\Abstract_Form_Adapter {
	public function __construct() {} // No transport needed for intake_send().
	public function is_active() {
		return true;
	}
	public function get_platform_name() {
		return 'Check';
	}
	public function get_platform_slug() {
		return 'check';
	}
	public function register_hooks() {}
	public function send( array $lead ) {
		$this->intake_send( $lead, 1 );
	}
}

function last_payload() {
	$post = end( $GLOBALS['__posts'] );
	return json_decode( $post['args']['body'], true );
}

// The visitor's first-party attribution cookie (written by the tracker).
$_COOKIE['apointoo_capture'] = json_encode( array(
	'visitor_id' => 'vis_1',
	'session_id' => 'ses_1',
	'gclid'      => 'g123',
	'fbclid'     => 'f123',
	'utm_source' => 'google',
) );

$adapter = new Check_Adapter();
$lead    = array( 'name' => 'Jane', 'email' => 'jane@example.com' );

// 1. Marketing consent granted (wp_consent_marketing cookie = allow) → attribution rides along.
$_COOKIE['wp_consent_marketing'] = 'allow';
$adapter->send( $lead );
$payload = last_payload();
assert( 'g123' === $payload['attribution']['gclid'] );
assert( 'vis_1' === $payload['attribution']['visitorId'] );
assert( 'jane@example.com' === $payload['lead']['email'] );

// 2. Marketing consent denied → attribution stripped, contact fields still sent.
$_COOKIE['wp_consent_marketing'] = 'deny';
$adapter->send( $lead );
$payload = last_payload();
assert( array() === $payload['attribution'] );
assert( 'jane@example.com' === $payload['lead']['email'] );
assert( 'Jane' === $payload['lead']['name'] );

// 3. No consent signal at all (no CMP) → default is deny: attribution stripped.
unset( $_COOKIE['wp_consent_marketing'] );
$adapter->send( $lead );
$payload = last_payload();
assert( array() === $payload['attribution'] );
assert( 'jane@example.com' === $payload['lead']['email'] );

// The empty attribution must serialise as {} not [] (intake z.record contract).
$raw = end( $GLOBALS['__posts'] )['args']['body'];
assert( false !== strpos( $raw, '"attribution":{}' ) );

echo "consent-gate-check: OK (3 scenarios, all assertions passed)\n";
