#!/usr/bin/env node
/**
 * Smoke-check for channel bucketing, consent gating, and window.apointooTracking().
 *
 * Loads the REAL assets/js/tracker.js (not a reimplementation) into a minimal
 * fake browser global, drives one page load per case, and asserts the bucket
 * window.apointooTracking() reports. No deps, no build step —
 * matches the plugin's own "no build step" JS.
 *
 * Usage: node bin/verify-channel-bucket.js
 */
'use strict';

const assert = require( 'assert' );
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const SCRIPT = fs.readFileSync( path.join( __dirname, '..', 'assets/js/tracker.js' ), 'utf8' );

let lastSandbox = null;

function runCase( { search = '', referrer = '', host = 'client-site.example', consent = null, requireConsent = 'never', win = {} }, cookies = {} ) {
	const listeners = {};
	const sandbox = {
		URL,
		URLSearchParams,
		setInterval: () => 1,
		clearInterval() {},
		console,
		navigator: { userAgent: 'node-verify', webdriver: false },
		document: {
			cookie: '',
			referrer,
			readyState: 'complete',
			addEventListener( type, fn ) {
				( listeners[ type ] = listeners[ type ] || [] ).push( fn );
			},
			querySelectorAll: () => [],
			body: {},
			documentElement: {},
		},
	};
	// document.cookie is a plain string in this stub (no real Set-Cookie parsing
	// needed — writeCookie()/readCookie() just round-trip through it here).
	Object.defineProperty( sandbox.document, 'cookie', {
		get: () => Object.keys( cookies ).map( ( k ) => k + '=' + encodeURIComponent( cookies[ k ] ) ).join( '; ' ),
		set: ( v ) => {
			const [ pair ] = String( v ).split( ';' );
			const eq = pair.indexOf( '=' );
			cookies[ pair.slice( 0, eq ) ] = decodeURIComponent( pair.slice( eq + 1 ) );
		},
	} );
	sandbox.window = {
		location: { href: 'https://' + host + '/' + search, search, hostname: host, protocol: 'https:', pathname: '/' },
		addEventListener() {},
		ApointooCaptureConfig: { consent: { require: requireConsent } },
		...win,
	};
	if ( consent ) {
		sandbox.window.ApointooCaptureConfig = { consent: { require: 'always' } };
		sandbox.window.dataLayer = [ [ 'consent', 'default', consent ] ];
	}
	sandbox.window.crypto = undefined; // fall back to Math.random path
	sandbox.listeners = listeners;
	lastSandbox = sandbox;
	vm.createContext( sandbox );
	vm.runInContext( SCRIPT, sandbox, { filename: 'tracker.js' } );
	return sandbox.window.apointooTracking();
}

const cases = [
	{ name: 'gclid -> paid_search', input: { search: '?gclid=abc' }, expect: 'paid_search' },
	{ name: 'li_fat_id -> paid_social', input: { search: '?li_fat_id=abc' }, expect: 'paid_social' },
	{ name: 'utm_medium=cpc&utm_source=facebook -> paid_social', input: { search: '?utm_medium=cpc&utm_source=facebook' }, expect: 'paid_social' },
	{ name: 'utm_medium=cpc&utm_source=bing -> paid_search', input: { search: '?utm_medium=cpc&utm_source=bing' }, expect: 'paid_search' },
	{ name: 'referrer google.com -> organic_search', input: { referrer: 'https://www.google.com/search?q=x' }, expect: 'organic_search' },
	{ name: 'referrer facebook.com -> organic_social', input: { referrer: 'https://www.facebook.com/' }, expect: 'organic_social' },
	{ name: 'referrer unrelated blog -> referral', input: { referrer: 'https://some-other-blog.example/post' }, expect: 'referral' },
	{ name: 'utm_campaign only, no source/medium/referrer -> other', input: { search: '?utm_campaign=spring' }, expect: 'other' },
];

let failed = 0;
for ( const c of cases ) {
	const data = runCase( c.input );
	const got = data.lt_channel;
	const gotFt = data.ft_channel;
	try {
		assert.strictEqual( got, c.expect, `lt_channel: expected ${ c.expect }, got ${ got }` );
		assert.strictEqual( gotFt, c.expect, `ft_channel (first visit, should match): expected ${ c.expect }, got ${ gotFt }` );
		console.log( 'PASS', c.name );
	} catch ( e ) {
		failed++;
		console.error( 'FAIL', c.name, '-', e.message );
	}
}

// window.apointooTracking() must never throw, even pre-init.
assert.doesNotThrow( () => runCase( {} ) );
console.log( 'PASS', 'apointooTracking() does not throw on a signal-less page load' );

{
	const pending = runCase( { search: '?gclid=abc', requireConsent: 'auto' } );
	assert.strictEqual( pending.gclid, undefined );
	console.log( 'PASS', 'auto consent without a CMP does not persist attribution' );
}

// Consent Mode v2 can expose mixed values. Any explicit denial must win so an
// ad identifier is never persisted when either relevant storage/use signal says no.
{
	const denied = runCase( {
		search: '?gclid=abc',
		consent: { ad_storage: 'granted', ad_user_data: 'denied' },
	} );
	const granted = runCase( {
		search: '?gclid=abc',
		consent: { ad_storage: 'granted', ad_user_data: 'granted' },
	} );
	try {
		assert.strictEqual( denied.gclid, undefined );
		assert.strictEqual( granted.gclid, 'abc' );
		console.log( 'PASS', 'Consent Mode mixed denial blocks persistence' );
	} catch ( e ) {
		failed++;
		console.error( 'FAIL', 'Consent Mode mixed denial', '-', e.message );
	}
}

// The core semantic under test: ft_channel is write-once, lt_channel refreshes
// every signal-bearing visit. Share one cookie jar across two page loads to
// prove the guard in mergeRecord() actually behaves that way (a single-visit
// case can't tell "frozen" apart from "just happened to match").
{
	const jar = {};
	const visit1 = runCase( { search: '?gclid=abc' }, jar );
	const visit2 = runCase( { referrer: 'https://www.google.com/search?q=x' }, jar );
	try {
		assert.strictEqual( visit1.ft_channel, 'paid_search' );
		assert.strictEqual( visit1.lt_channel, 'paid_search' );
		assert.strictEqual( visit2.lt_channel, 'organic_search', 'lt_channel must refresh on visit 2' );
		assert.strictEqual( visit2.ft_channel, 'paid_search', 'ft_channel must stay frozen from visit 1' );
		console.log( 'PASS', 'ft_channel write-once vs lt_channel refresh across two visits' );
	} catch ( e ) {
		failed++;
		console.error( 'FAIL', 'ft/lt two-visit divergence', '-', e.message );
	}
}

// Form injection: fillForms() must actually write apointoo_ft_channel /
// apointoo_lt_channel hidden inputs, same generic KEYS loop as every other field.
{
	const cookies = {};
	const inputs = {};
	function makeForm() {
		return {
			nodeType: 1,
			getAttribute: () => '',
			querySelector: ( sel ) => {
				const m = /name="([^"]+)"/.exec( sel );
				return m && inputs[ m[ 1 ] ] ? inputs[ m[ 1 ] ] : null;
			},
			appendChild: ( input ) => { inputs[ input.name ] = input; },
		};
	}
	const sandbox = {
		URL, URLSearchParams, console,
		navigator: { userAgent: 'node-verify', webdriver: false },
		document: {
			cookie: '',
			referrer: '',
			readyState: 'complete',
			addEventListener() {},
			querySelectorAll: () => [ makeForm() ],
			createElement: () => ( { type: '', name: '', value: '' } ),
			body: {},
			documentElement: {},
		},
	};
	Object.defineProperty( sandbox.document, 'cookie', {
		get: () => Object.keys( cookies ).map( ( k ) => k + '=' + encodeURIComponent( cookies[ k ] ) ).join( '; ' ),
		set: ( v ) => {
			const [ pair ] = String( v ).split( ';' );
			const eq = pair.indexOf( '=' );
			cookies[ pair.slice( 0, eq ) ] = decodeURIComponent( pair.slice( eq + 1 ) );
		},
	} );
	sandbox.window = {
		location: { href: 'https://client-site.example/?gclid=abc', search: '?gclid=abc', hostname: 'client-site.example', protocol: 'https:', pathname: '/' },
		addEventListener() {},
		ApointooCaptureConfig: { consent: { require: 'never' } },
	};
	vm.createContext( sandbox );
	vm.runInContext( SCRIPT, sandbox, { filename: 'tracker.js' } );
	try {
		assert.strictEqual( inputs.apointoo_lt_channel && inputs.apointoo_lt_channel.value, 'paid_search' );
		assert.strictEqual( inputs.apointoo_ft_channel && inputs.apointoo_ft_channel.value, 'paid_search' );
		console.log( 'PASS', 'fillForms() injects apointoo_ft_channel / apointoo_lt_channel hidden inputs' );
		Object.keys( cookies ).forEach( ( key ) => delete cookies[ key ] );
		sandbox.window.location.search = '';
		sandbox.window.location.href = 'https://client-site.example/';
		vm.runInContext( SCRIPT, sandbox, { filename: 'tracker.js' } );
		assert.strictEqual( inputs.apointoo_lt_channel.value, '' );
		assert.strictEqual( inputs.apointoo_ft_channel.value, '' );
		console.log( 'PASS', 'fillForms() clears cached hidden attribution without visitor data' );
	} catch ( e ) {
		failed++;
		console.error( 'FAIL', 'form injection', '-', e.message );
	}
}

function check( name, fn ) {
	try {
		fn();
		console.log( 'PASS', name );
	} catch ( e ) {
		failed++;
		console.error( 'FAIL', name, '-', e.message );
	}
}

// A new campaign touch replaces the whole last-touch campaign set: Google click,
// then a Meta click must not leave the stale gclid (or utm_term) behind. The
// first touch keeps the original Google click.
check( 'new campaign touch drops stale click ids and UTMs', () => {
	const jar = {};
	runCase( { search: '?gclid=G1&utm_source=google&utm_medium=cpc&utm_term=kw' }, jar );
	const v2 = runCase( { search: '?fbclid=F2&utm_source=facebook&utm_medium=paid_social' }, jar );
	assert.strictEqual( v2.gclid, undefined );
	assert.strictEqual( v2.utm_term, undefined );
	assert.strictEqual( v2.fbclid, 'F2' );
	assert.strictEqual( v2.channel, 'Facebook Ads' );
	assert.strictEqual( v2.ft_gclid, 'G1' );
	assert.strictEqual( v2.ft_fbclid, undefined );
} );

// An organic return visit is not a campaign touch: it refreshes the channel
// but keeps the stored ad click for conversion upload.
check( 'organic return visit keeps the stored click id', () => {
	const jar = {};
	runCase( { search: '?gclid=G1' }, jar );
	const v2 = runCase( { referrer: 'https://www.google.com/search?q=x' }, jar );
	assert.strictEqual( v2.gclid, 'G1' );
	assert.strictEqual( v2.lt_channel, 'organic_search' );
} );

check( 'pin_cid / snap_cid -> paid_social, mc_cid -> other (not organic via webmail)', () => {
	assert.strictEqual( runCase( { search: '?pin_cid=p' } ).lt_channel, 'paid_social' );
	assert.strictEqual( runCase( { search: '?snap_cid=s' } ).channel, 'Snapchat Ads' );
	const mc = runCase( { search: '?mc_cid=c&mc_eid=e', referrer: 'https://mail.google.com/' } );
	assert.strictEqual( mc.channel, 'Mailchimp' );
	assert.strictEqual( mc.lt_channel, 'other' );
	assert.strictEqual( mc.mc_eid, 'e' );
} );

check( 'browser ids: _fbp/_fbc/_ga and GS2 + GS1 session cookies', () => {
	const gs2 = runCase( { search: '?gclid=G' }, {
		_fbp: 'fb.1.1700000000000.123',
		_fbc: 'fb.1.1700000000000.abc',
		_ga: 'GA1.1.111222333.1700000000',
		_ga_V5GGFTXW7P: 'GS2.1.s1762300000$o7$g1$t1762300100$j0$l0$h0',
	} );
	assert.strictEqual( gs2.fbp, 'fb.1.1700000000000.123' );
	assert.strictEqual( gs2.fbc, 'fb.1.1700000000000.abc' );
	assert.strictEqual( gs2.ga_client_id, '111222333.1700000000' );
	assert.strictEqual( gs2.ga_session_id, '1762300000' );
	assert.strictEqual( gs2.ga_session_number, '7' );
	const gs1 = runCase( {}, { _ga_ABC: 'GS1.1.1700000001.3.1.1700000100.0.0.0' } );
	assert.strictEqual( gs1.ga_session_id, '1700000001' );
	assert.strictEqual( gs1.ga_session_number, '3' );
} );

check( 'synthesised fbc keeps its creation time across later visits', () => {
	const jar = {};
	const v1 = runCase( { search: '?fbclid=F9' }, jar );
	assert.match( v1.fbc, /^fb\.1\.\d+\.F9$/ );
	const v2 = runCase( { referrer: 'https://www.google.com/' }, jar );
	assert.strictEqual( v2.fbc, v1.fbc );
} );

// A visitor whose record was written by 0.5.x (first touch, no ft_<id>, no
// lt_timestamp) must not lose the stored Google click on the first new campaign.
check( '0.5.x record keeps its gclid as first touch on the next campaign touch', () => {
	const jar = { apointoo_capture: JSON.stringify( {
		visitor_id: 'vis_old', session_id: 'ses_old', _session_seen: 1,
		gclid: 'OLD', utm_source: 'google', ft_source: 'google', ft_channel: 'paid_search', ft_timestamp: '1700000000000',
	} ) };
	const v = runCase( { search: '?fbclid=NEW&utm_medium=paid_social&utm_source=facebook' }, jar );
	assert.strictEqual( v.gclid, undefined );
	assert.strictEqual( v.ft_gclid, 'OLD' );
	assert.strictEqual( v.fbclid, 'NEW' );
	assert.strictEqual( v.visitor_id, 'vis_old' );
} );

// CookieYes: an answered banner decides, even when a GCM default says denied.
check( 'CookieYes accept persists, unanswered banner does not', () => {
	const accepted = runCase(
		{ search: '?gclid=G', requireConsent: 'always', win: { dataLayer: [ [ 'consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied' } ] ] } },
		{ 'cookieyes-consent': 'consentid:x,consent:yes,action:yes,necessary:yes,analytics:yes,advertisement:yes' }
	);
	assert.strictEqual( accepted.gclid, 'G' );
	const pending = runCase(
		{ search: '?gclid=G', requireConsent: 'always' },
		{ 'cookieyes-consent': 'consentid:x,consent:no,action:,necessary:yes,advertisement:no' }
	);
	assert.strictEqual( pending.gclid, undefined );
} );

check( 'CookieYes withdrawal after a grant clears the record', () => {
	const jar = { 'cookieyes-consent': 'consentid:x,consent:yes,action:yes,advertisement:yes' };
	assert.strictEqual( runCase( { search: '?gclid=G', requireConsent: 'always' }, jar ).gclid, 'G' );
	jar[ 'cookieyes-consent' ] = 'consentid:x,consent:no,action:yes,advertisement:no';
	lastSandbox.listeners.cookieyes_consent_update.forEach( ( fn ) => fn() );
	assert.deepStrictEqual( { ...lastSandbox.window.apointooTracking() }, {} );
} );

// JoinChat-style widgets open WhatsApp through window.open with no href.
check( 'window.open to wa.me pushes contact_whatsapp_start once, without the message text', () => {
	const opened = [];
	const dataLayer = [];
	runCase( { search: '?gclid=G', win: { dataLayer, open: ( u ) => { opened.push( u ); return null; } } } );
	lastSandbox.window.open( 'https://wa.me/351912612654?text=Hi+there' );
	lastSandbox.window.open( 'https://wa.me/351912612654?text=Hi+there' );
	lastSandbox.window.open( 'https://example.com/' );
	const events = dataLayer.filter( ( e ) => e && e.event === 'contact_whatsapp_start' );
	assert.strictEqual( opened.length, 3, 'native window.open must still run' );
	assert.strictEqual( events.length, 1, 'deduped within 10s' );
	assert.strictEqual( events[ 0 ].phone, '351912612654' );
	assert.strictEqual( events[ 0 ].link_url, 'https://wa.me/351912612654' );
	assert.strictEqual( events[ 0 ].channel, 'Google Ads' );
} );

check( 'no WhatsApp event before consent', () => {
	const dataLayer = [];
	runCase( { requireConsent: 'always', win: { dataLayer, open: () => null } } );
	lastSandbox.window.open( 'https://api.whatsapp.com/send?phone=351912612654' );
	assert.strictEqual( dataLayer.filter( ( e ) => e && e.event === 'contact_whatsapp_start' ).length, 0 );
} );

if ( failed ) {
	console.error( failed + ' case(s) failed' );
	process.exit( 1 );
}
console.log( 'All channel-bucket cases passed.' );
