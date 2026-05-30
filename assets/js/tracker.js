/**
 * Apointoo Capture — front-end attribution tracker.
 *
 * Captures marketing attribution first-party, mints neutral vis_/ses_ ids, and
 * fills the hidden fields the form adapters inject — so the data rides the form
 * into the site owner's own systems. Nothing is sent anywhere by this script.
 */
( function () {
	'use strict';

	var cfg = window.ApointooCaptureConfig || {};
	var COOKIE = cfg.cookie || 'apointoo_capture';
	var PREFIX = cfg.prefix || 'apointoo_';
	var KEYS = cfg.keys || [];

	var CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' ];
	var UTMS = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ];
	var VISITOR_DAYS = 400;
	var SESSION_MINUTES = 30;

	function readCookie( name ) {
		var match = document.cookie.match( '(^|;)\\s*' + name + '\\s*=\\s*([^;]+)' );
		return match ? decodeURIComponent( match.pop() ) : '';
	}

	function writeCookie( name, value, days ) {
		var date = new Date();
		date.setTime( date.getTime() + days * 86400000 );
		document.cookie =
			name + '=' + encodeURIComponent( value ) +
			';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
	}

	function randomId( prefix ) {
		var bytes = new Uint8Array( 12 );
		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( bytes );
		} else {
			for ( var i = 0; i < bytes.length; i++ ) {
				bytes[ i ] = Math.floor( Math.random() * 256 );
			}
		}
		var hex = '';
		for ( var j = 0; j < bytes.length; j++ ) {
			hex += ( '0' + bytes[ j ].toString( 16 ) ).slice( -2 );
		}
		return prefix + '_' + hex;
	}

	function params() {
		var out = {};
		try {
			var sp = new URLSearchParams( window.location.search );
			sp.forEach( function ( value, key ) {
				out[ key.toLowerCase() ] = value;
			} );
		} catch ( e ) {
			out = {};
		}
		return out;
	}

	function load() {
		var raw = readCookie( COOKIE );
		if ( ! raw ) {
			return {};
		}
		try {
			var data = JSON.parse( raw );
			return data && typeof data === 'object' ? data : {};
		} catch ( e ) {
			return {};
		}
	}

	function build() {
		var data = load();
		var qs = params();
		var now = Date.now();

		// Visitor id — long-lived.
		if ( ! data.visitor_id ) {
			data.visitor_id = randomId( 'vis' );
		}

		// Session id — new when the previous one lapsed.
		if ( ! data.session_id || ! data._session_seen || ( now - data._session_seen ) > SESSION_MINUTES * 60000 ) {
			data.session_id = randomId( 'ses' );
		}
		data._session_seen = now;

		// Last-touch attribution — only overwrite when the URL actually carries it.
		var i;
		for ( i = 0; i < UTMS.length; i++ ) {
			if ( qs[ UTMS[ i ] ] ) {
				data[ UTMS[ i ] ] = qs[ UTMS[ i ] ];
			}
		}
		for ( i = 0; i < CLICK_IDS.length; i++ ) {
			if ( qs[ CLICK_IDS[ i ] ] ) {
				data[ CLICK_IDS[ i ] ] = qs[ CLICK_IDS[ i ] ];
			}
		}

		// Referrer + landing page — first touch only.
		if ( ! data.landing_page ) {
			data.landing_page = window.location.pathname;
		}
		if ( ! data.referrer && document.referrer && document.referrer.indexOf( window.location.host ) === -1 ) {
			data.referrer = document.referrer;
		}

		return data;
	}

	function fillFields( data ) {
		for ( var i = 0; i < KEYS.length; i++ ) {
			var key = KEYS[ i ];
			var value = data[ key ] ? String( data[ key ] ) : '';
			var inputs = document.querySelectorAll( 'input[name="' + PREFIX + key + '"]' );
			for ( var j = 0; j < inputs.length; j++ ) {
				inputs[ j ].value = value;
			}
		}
	}

	function run() {
		var data = build();
		writeCookie( COOKIE, JSON.stringify( data ), VISITOR_DAYS );
		fillFields( data );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
