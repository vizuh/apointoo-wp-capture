<?php
/**
 * Standalone contract check: php tests/intake-payload-contract.php
 *
 * WP Consent API present but no CMP registered with it (wp_get_consent_type()
 * returns ''), the live state on CookieYes sites: wp_has_consent() then answers
 * true for everything and must not be trusted.
 */

define( 'ABSPATH', __DIR__ );

function wp_has_consent( $type ) {
	return true;
}
function wp_get_consent_type() {
	return '';
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
require_once dirname( __DIR__ ) . '/includes/capture/class-tracker.php';

use Apointoo\Capture\Capture\Attribution;
use Apointoo\Capture\Capture\Consent;
use Apointoo\Capture\Capture\Tracker;

$record = array(
	'visitor_id'        => 'vis_1',
	'session_id'        => 'ses_1',
	'utm_source'        => 'facebook',
	'utm_medium'        => 'paid_social',
	'fbclid'            => 'F2',
	'ttclid'            => 'T2',
	'lt_landing_page'   => 'https://site.example/en/',
	'lt_timestamp'      => '1762300000000',
	'landing_page'      => '/',
	'ft_source'         => 'google',
	'ft_campaign'       => 'brand',
	'ft_landing_page'   => '/',
	'ft_timestamp'      => '1762200000000',
	'ft_gclid'          => 'G1',
	'fbp'               => 'fb.1.1.2',
	'ga_client_id'      => '111.222',
	'ga_session_id'     => '1762300000',
	'ga_session_number' => '7',
);
$_COOKIE['apointoo_capture'] = json_encode( $record );

// No CMP answer at all -> denied, despite wp_has_consent() returning true.
assert( false === Consent::marketing_allowed() );
assert( false === Consent::analytics_allowed() );

$denied = Attribution::to_intake_payload( 'https://site.example/en/contactos/?fbclid=F2' );
assert( ! isset( $denied['fbclid'] ) );
assert( ! isset( $denied['ft_gclid'] ) );
assert( ! isset( $denied['lastTouch']['fbclid'] ) );
assert( ! isset( $denied['browserIds'] ) );
assert( 'facebook' === $denied['lastTouch']['utmSource'] );
assert( 'https://site.example/en/contactos/' === $denied['pageUrl'], 'query string dropped without consent' );
assert( 'denied' === $denied['consent']['adUserData'] );
assert( 'denied' === $denied['consent']['analyticsStorage'] );

// CookieYes banner shown but not answered -> still denied.
$_COOKIE['cookieyes-consent'] = 'consentid:abc,consent:no,action:,necessary:yes,analytics:no,advertisement:no';
assert( false === Consent::marketing_allowed() );

// CookieYes: analytics accepted, advertising rejected.
$_COOKIE['cookieyes-consent'] = 'consentid:abc,consent:yes,action:yes,necessary:yes,analytics:yes,advertisement:no';
assert( false === Consent::marketing_allowed() );
assert( true === Consent::analytics_allowed() );
$analytics_only = Attribution::to_intake_payload();
assert( ! isset( $analytics_only['browserIds']['fbp'] ) );
assert( '111.222' === $analytics_only['browserIds']['ga_client_id'] );

// CookieYes: accept all.
$_COOKIE['cookieyes-consent'] = 'consentid:abc,consent:yes,action:yes,necessary:yes,analytics:yes,advertisement:yes';
$granted = Attribution::to_intake_payload( 'https://site.example/en/contactos/' );
assert( 'F2' === $granted['fbclid'] );
assert( 'F2' === $granted['lastTouch']['fbclid'] );
assert( 'T2' === $granted['lastTouch']['ttclid'], 'networks without a flat slot ride the nested touch' );
assert( ! isset( $granted['gclid'] ), 'stale first-touch gclid is not promoted to last touch' );
assert( 'G1' === $granted['ft_gclid'] );
assert( 'https://site.example/en/' === $granted['lastTouch']['landingPage'] );
assert( '2025-11-04T23:46:40+00:00' === $granted['lastTouch']['timestampIso'] );
assert( '/' === $granted['ft_landing'] );
assert( 'brand' === $granted['ft_campaign'] );
assert( 'fb.1.1.2' === $granted['browserIds']['fbp'] );
assert( '7' === $granted['browserIds']['ga_session_number'] );
assert( 'https://site.example/en/contactos/' === $granted['pageUrl'] );
assert( 'granted' === $granted['consent']['adStorage'] );

// No submission page (settings test send) -> last-touch landing page.
assert( 'https://site.example/en/' === Attribution::to_intake_payload()['pageUrl'] );

// Other CMP cookies.
unset( $_COOKIE['cookieyes-consent'] );
$_COOKIE['CookieConsent'] = "{stamp:'x',necessary:true,preferences:false,statistics:true,marketing:false,method:'explicit',ver:1}";
assert( false === Consent::marketing_allowed() );
assert( true === Consent::analytics_allowed() );
unset( $_COOKIE['CookieConsent'] );
$_COOKIE['OptanonConsent'] = 'isGpcEnabled=0&datestamp=x&groups=C0001%3A1%2CC0002%3A0%2CC0004%3A1&AwaitingReconsent=false';
assert( true === Consent::marketing_allowed() );
assert( false === Consent::analytics_allowed() );
unset( $_COOKIE['OptanonConsent'] );

// Cookie scope.
assert( '' === Tracker::cookie_domain_for( 'feathershouses.com', 'host' ) );
assert( 'feathershouses.com' === Tracker::cookie_domain_for( 'www.feathershouses.com', 'registrable' ) );
assert( 'example.co.uk' === Tracker::cookie_domain_for( 'shop.example.co.uk', 'registrable' ) );
assert( '' === Tracker::cookie_domain_for( 'feathershouses.wpengine.com', 'registrable' ) );
assert( '' === Tracker::cookie_domain_for( '127.0.0.1', 'registrable' ) );
assert( '' === Tracker::cookie_domain_for( 'localhost', 'registrable' ) );

// The script version string must follow the plugin header.
$main = file_get_contents( dirname( __DIR__ ) . '/apointoo-wp-capture.php' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
preg_match( "/define\( 'APOINTOO_CAPTURE_VERSION', '([^']+)' \)/", $main, $constant );
assert( $header[1] === $constant[1], 'APOINTOO_CAPTURE_VERSION must match the Version header' );

echo "intake payload contract: ok\n";
