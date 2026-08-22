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
		'visitor_id' => 'visitor-1',
		'session_id' => 'session-1',
		'gclid'      => 'gclid-1',
		'gbraid'     => 'gbraid-1',
		'utm_source' => 'google',
		'ft_channel' => 'organic_search',
		'lt_channel' => 'paid_search',
	)
);

$denied = Attribution::to_intake_payload();
assert( ! isset( $denied['gclid'] ) );
assert( ! isset( $denied['gbraid'] ) );
assert( 'visitor-1' === $denied['visitorId'] );
assert( 'session-1' === $denied['sessionId'] );
assert( 'google' === $denied['utmSource'] );
assert( 'organic_search' === $denied['ft_channel'] );
assert( 'paid_search' === $denied['lt_channel'] );
assert( 'denied' === $denied['consent']['adUserData'] );

$GLOBALS['apointoo_test_consent'] = array( 'marketing' => true, 'statistics' => true );
$granted                           = Attribution::to_intake_payload();
assert( 'gclid-1' === $granted['gclid'] );
assert( 'gbraid-1' === $granted['gbraid'] );
assert( 'granted' === $granted['consent']['adStorage'] );
assert( 'granted' === $granted['consent']['analyticsStorage'] );
assert( 'api' === $granted['consent']['source'] );

$tracker = file_get_contents( dirname( __DIR__ ) . '/assets/js/tracker.js' );
$cf7     = file_get_contents( dirname( __DIR__ ) . '/includes/integrations/forms/class-cf7-adapter.php' );
$wpforms = file_get_contents( dirname( __DIR__ ) . '/includes/integrations/forms/class-wpforms-adapter.php' );
assert( false === strpos( $tracker, "CONSENT_REQUIRE === 'auto' && ! Consent.detected()" ) );
assert( false !== strpos( $tracker, "if ( input ) {" ) );
assert( false === strpos( $cf7, '$attr   = Attribution::from_cookie();' ) );
assert( false === strpos( $wpforms, '$attribution = Attribution::from_cookie();' ) );

echo "attribution consent contract: ok\n";
