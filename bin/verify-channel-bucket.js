#!/usr/bin/env node
/**
 * Smoke-check for ft_channel/lt_channel bucketing + window.apointooTracking().
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

function runCase( { search = '', referrer = '', host = 'client-site.example' }, cookies = {} ) {
	const sandbox = {
		URL,
		URLSearchParams,
		console,
		navigator: { userAgent: 'node-verify', webdriver: false },
		document: {
			cookie: '',
			referrer,
			readyState: 'complete',
			addEventListener() {},
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
	};
	sandbox.window.crypto = undefined; // fall back to Math.random path
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
	};
	vm.createContext( sandbox );
	vm.runInContext( SCRIPT, sandbox, { filename: 'tracker.js' } );
	try {
		assert.strictEqual( inputs.apointoo_lt_channel && inputs.apointoo_lt_channel.value, 'paid_search' );
		assert.strictEqual( inputs.apointoo_ft_channel && inputs.apointoo_ft_channel.value, 'paid_search' );
		console.log( 'PASS', 'fillForms() injects apointoo_ft_channel / apointoo_lt_channel hidden inputs' );
	} catch ( e ) {
		failed++;
		console.error( 'FAIL', 'form injection', '-', e.message );
	}
}

if ( failed ) {
	console.error( failed + ' case(s) failed' );
	process.exit( 1 );
}
console.log( 'All channel-bucket cases passed.' );
