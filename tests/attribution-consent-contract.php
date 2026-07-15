<?php
/** Standalone contract check: php tests/attribution-consent-contract.php */

define( 'ABSPATH', __DIR__ );
$GLOBALS['apointoo_test_consent'] = array( 'marketing' => false, 'statistics' => false );

function wp_has_consent( $type ) {
	return ! empty( $GLOBALS['apointoo_test_consent'][ $type ] );
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function wp_unslash( $value ) {
	return $value;
}
function apply_filters( $name, $value ) {
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/capture/class-consent.php';
require_once dirname( __DIR__ ) . '/includes/capture/class-attribution.php';

use Apointoo\Capture\Capture\Attribution;

$_COOKIE['apointoo_capture'] = json_encode(
	array(
		'gclid'      => 'gclid-1',
		'gbraid'     => 'gbraid-1',
		'utm_source' => 'google',
	)
);

$denied = Attribution::to_intake_payload();
assert( ! isset( $denied['gclid'] ) );
assert( ! isset( $denied['gbraid'] ) );
assert( 'google' === $denied['utmSource'] );
assert( 'denied' === $denied['consent']['adUserData'] );

$GLOBALS['apointoo_test_consent'] = array( 'marketing' => true, 'statistics' => true );
$granted                           = Attribution::to_intake_payload();
assert( 'gclid-1' === $granted['gclid'] );
assert( 'gbraid-1' === $granted['gbraid'] );
assert( 'granted' === $granted['consent']['adStorage'] );
assert( 'granted' === $granted['consent']['analyticsStorage'] );
assert( 'api' === $granted['consent']['source'] );

echo "attribution consent contract: ok\n";
