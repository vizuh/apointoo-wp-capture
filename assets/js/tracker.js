/**
 * Apointoo Capture — front-end attribution tracker.
 *
 * Captures marketing attribution first-party, mints neutral vis_/ses_ ids, derives
 * a source/medium/channel plus a stable ft_channel/lt_channel bucket pair
 * (paid_search/paid_social/organic_search/organic_social/direct/referral/other),
 * reads ad-pixel / GA4 browser ids, fills the hidden fields the form adapters
 * inject, and (optionally) decorates outbound links so attribution survives a
 * cross-domain hand-off. With consent it also pushes `contact_whatsapp_start`
 * to the page's own dataLayer on WhatsApp clicks. Nothing is sent to any
 * external service by this script — the data rides the form into the site
 * owner's own systems.
 *
 * Public JS API: window.apointooTracking() — see below.
 *
 * Ported from ClickTrail's clicutcl-attribution.js into Apointoo's flat-key scheme
 * + two-phase consent model. Vanilla ES5-style IIFE; no build step, no dependencies.
 */
( function () {
	'use strict';

	var cfg = window.ApointooCaptureConfig || {};

	var COOKIE = cfg.cookie || 'apointoo_capture';
	var PREFIX = cfg.prefix || 'apointoo_';
	var PENDING_KEY = 'apointoo_pending';

	// The full key set the contract defines. Config may ship a subset; we union
	// the configured keys with our known set so a partial config still works.
	var ALL_KEYS = [
		'visitor_id', 'session_id',
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
		'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid',
		'li_fat_id', 'sccid', 'epik', 'rdt_cid', 'dclid', 'pin_cid', 'snap_cid', 'mc_cid', 'mc_eid',
		'referrer', 'landing_page', 'lt_landing_page', 'lt_timestamp',
		'source', 'medium', 'channel', 'lt_channel',
		'ft_source', 'ft_medium', 'ft_campaign', 'ft_landing_page', 'ft_channel', 'ft_timestamp',
		'ft_gclid', 'ft_gbraid', 'ft_wbraid', 'ft_fbclid', 'ft_msclkid',
		'fbc', 'fbp', 'ttp', 'li_gc', 'ga_client_id', 'ga_session_id', 'ga_session_number'
	];

	var CLICK_IDS = cfg.clickIds && cfg.clickIds.length ? cfg.clickIds : [
		'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid',
		'li_fat_id', 'sccid', 'epik', 'rdt_cid', 'dclid', 'pin_cid', 'snap_cid', 'mc_cid', 'mc_eid'
	];
	var UTMS = cfg.utms && cfg.utms.length ? cfg.utms : [
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'
	];
	var FIRST_TOUCH_KEYS = cfg.firstTouchKeys && cfg.firstTouchKeys.length ? cfg.firstTouchKeys : [
		'ft_source', 'ft_medium', 'ft_campaign', 'ft_landing_page', 'ft_channel'
	];
	// Click ids also kept on the first touch (ft_<id>), so a later campaign touch
	// can replace the last-touch set without losing the original ad click.
	var FIRST_TOUCH_CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' ];
	// Ad-pixel / analytics browser identifiers, refreshed on capture and on submit.
	var BROWSER_ID_KEYS = [ 'fbc', 'fbp', 'ttp', 'li_gc', 'ga_client_id', 'ga_session_id', 'ga_session_number' ];

	var KEYS = unionKeys( cfg.keys && cfg.keys.length ? cfg.keys : ALL_KEYS );

	var CONSENT = cfg.consent || {};
	var CONSENT_REQUIRE = CONSENT.require || 'auto'; // 'auto' | 'always' | 'never'

	var DECORATION = cfg.decoration || {};
	var DECORATION_ENABLED = DECORATION.enabled !== false;
	var ALLOWED_DOMAINS = DECORATION.allowedDomains || [];

	// Registrable cookie domain resolved server-side ('' = host-only cookie).
	var COOKIE_DOMAIN = typeof cfg.cookieDomain === 'string' ? cfg.cookieDomain : '';
	// Pins which _ga_<id> cookie supplies ga_session_id ('' = first valid one).
	var GA4_MEASUREMENT_ID = typeof cfg.ga4MeasurementId === 'string' ? cfg.ga4MeasurementId : '';
	var WHATSAPP_EVENTS = cfg.whatsappEvents !== false;
	var WHATSAPP_HOSTS = [ 'wa.me', 'whatsapp.com', 'api.whatsapp.com', 'web.whatsapp.com' ];
	var WHATSAPP_DEDUPE_MS = 10000;

	var VISITOR_DAYS = 400;
	var SESSION_MINUTES = 30;
	var MAX_LEN = 256;

	// Aliases: URL param name -> canonical click-id key.
	var CLICK_ID_ALIASES = {
		sc_click_id: 'sccid'
	};

	// --- classifier tables (ported subset; AI-assistant table dropped) ----------

	var SEARCH_RULES = [
		{ source: 'google', label: 'google' },
		{ source: 'bing', domain: 'bing.com' },
		{ source: 'yahoo', label: 'yahoo' },
		{ source: 'duckduckgo', domain: 'duckduckgo.com' },
		{ source: 'ecosia', domain: 'ecosia.org' },
		{ source: 'yandex', label: 'yandex' },
		{ source: 'baidu', domain: 'baidu.com' }
	];

	var SOCIAL_RULES = [
		{ source: 'facebook', domains: [ 'facebook.com', 'fb.com' ] },
		{ source: 'instagram', domains: [ 'instagram.com' ] },
		{ source: 'linkedin', domains: [ 'linkedin.com', 'lnkd.in' ] },
		{ source: 'twitter', domains: [ 'twitter.com', 't.co', 'x.com' ] },
		{ source: 'reddit', domains: [ 'reddit.com' ] },
		{ source: 'pinterest', domains: [ 'pinterest.com' ] },
		{ source: 'youtube', domains: [ 'youtube.com', 'youtu.be' ] },
		{ source: 'tiktok', domains: [ 'tiktok.com' ] }
	];

	var PAID_MEDIUMS = [ 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_social' ];

	// Click-id -> bucket split used only by resolveChannelBucket() (below).
	// ponytail: dclid (DV360) has no distinct "display" bucket in this taxonomy —
	// grouped under paid_search as the nearest fit; revisit if a client needs
	// display reported apart from search.
	var SEARCH_CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid', 'msclkid', 'dclid' ];
	var SOCIAL_CLICK_IDS = [ 'li_fat_id', 'twclid', 'rdt_cid', 'ttclid', 'epik', 'pin_cid', 'sccid', 'snap_cid' ];
	var EMAIL_CLICK_IDS = [ 'mc_cid', 'mc_eid' ];
	var SOCIAL_AD_SOURCES = [
		'facebook', 'meta', 'instagram', 'fb', 'ig', 'linkedin', 'twitter', 'x',
		'reddit', 'tiktok', 'pinterest', 'snapchat', 'snap'
	];

	// =============================================================================
	// Small utilities
	// =============================================================================

	function unionKeys( base ) {
		var seen = {};
		var out = [];
		var lists = [ base, ALL_KEYS ];
		for ( var l = 0; l < lists.length; l++ ) {
			var list = lists[ l ] || [];
			for ( var i = 0; i < list.length; i++ ) {
				var k = list[ i ];
				if ( k && ! seen[ k ] ) {
					seen[ k ] = true;
					out.push( k );
				}
			}
		}
		return out;
	}

	function sanitizeValue( value ) {
		if ( value === null || value === undefined ) {
			return '';
		}
		var s = String( value );
		// Strip control characters.
		s = s.replace( /[\u0000-\u001F\u007F]/g, ' ' );
		s = s.replace( /^\s+|\s+$/g, '' );
		if ( ! s ) {
			return '';
		}
		// Reject unsubstituted ad-platform macros, e.g. {{campaign.name}}.
		if ( /^\{\{.+\}\}$/.test( s ) ) {
			return '';
		}
		if ( s.length > MAX_LEN ) {
			s = s.slice( 0, MAX_LEN );
		}
		return s;
	}

	function normalizeHost( host ) {
		return String( host || '' )
			.replace( /^\s+|\s+$/g, '' )
			.toLowerCase()
			.replace( /\.+$/, '' )
			.replace( /^www\./, '' );
	}

	function parseUrl( raw, base ) {
		try {
			return new URL( raw, base || window.location.href );
		} catch ( e ) {
			return null;
		}
	}

	function hostMatchesDomain( host, domain ) {
		var h = normalizeHost( host );
		var d = normalizeHost( domain );
		if ( ! h || ! d ) {
			return false;
		}
		return h === d || h.slice( -( d.length + 1 ) ) === '.' + d;
	}

	function hostMatchesLabel( host, label ) {
		var h = normalizeHost( host );
		var lbl = String( label || '' ).toLowerCase();
		if ( ! h || ! lbl ) {
			return false;
		}
		// Label appears as a dotted segment, e.g. "google" in www.google.co.uk.
		return ( '.' + h + '.' ).indexOf( '.' + lbl + '.' ) !== -1;
	}

	function areRelatedHosts( a, b ) {
		var first = normalizeHost( a );
		var second = normalizeHost( b );
		if ( ! first || ! second ) {
			return false;
		}
		return first === second ||
			first.slice( -( second.length + 1 ) ) === '.' + second ||
			second.slice( -( first.length + 1 ) ) === '.' + first;
	}

	function getRegistrableDomain( host ) {
		var h = normalizeHost( host );
		var parts = h.split( '.' );
		if ( parts.length <= 2 ) {
			return h;
		}
		var knownSld = { co: 1, com: 1, net: 1, org: 1, gov: 1, edu: 1, ac: 1, ne: 1, or: 1, me: 1 };
		if ( parts.length >= 3 && knownSld[ parts[ parts.length - 2 ] ] ) {
			return parts.slice( -3 ).join( '.' );
		}
		return parts.slice( -2 ).join( '.' );
	}

	// =============================================================================
	// URL params + identity
	// =============================================================================

	function params() {
		var out = {};
		try {
			var sp = new URLSearchParams( window.location.search );
			sp.forEach( function ( value, key ) {
				var k = String( key || '' ).toLowerCase();
				var v = sanitizeValue( value );
				if ( k && v !== '' ) {
					out[ k ] = v;
				}
			} );
		} catch ( e ) {
			out = {};
		}
		return out;
	}

	function pickClickId( qs, key ) {
		if ( qs[ key ] ) {
			return qs[ key ];
		}
		// Reverse-alias lookup (e.g. sc_click_id -> sccid).
		for ( var alias in CLICK_ID_ALIASES ) {
			if ( CLICK_ID_ALIASES.hasOwnProperty( alias ) && CLICK_ID_ALIASES[ alias ] === key && qs[ alias ] ) {
				return qs[ alias ];
			}
		}
		return '';
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

	// =============================================================================
	// Bot detection
	// =============================================================================

	function isBot() {
		var ua = ( navigator.userAgent || '' ).toLowerCase();
		var bots = [
			'googlebot', 'bingbot', 'yandexbot', 'duckduckbot', 'baiduspider',
			'twitterbot', 'facebookexternalhit', 'rogerbot', 'linkedinbot',
			'embedly', 'quora link preview', 'showyoubot', 'outbrain',
			'pinterest/0.', 'slackbot', 'vkshare', 'w3c_validator', 'redditbot',
			'applebot', 'whatsapp', 'flipboard', 'tumblr', 'bitlybot',
			'skypeuripreview', 'nuzzel', 'discordbot', 'google page speed',
			'qwantify', 'pinterestbot', 'telegrambot', 'semrushbot', 'mj12bot',
			'ahrefsbot', 'dotbot'
		];
		for ( var i = 0; i < bots.length; i++ ) {
			if ( ua.indexOf( bots[ i ] ) !== -1 ) {
				return true;
			}
		}
		if ( navigator.webdriver ) {
			return true;
		}
		if ( window.callPhantom || window._phantom ) {
			return true;
		}
		return false;
	}

	// =============================================================================
	// Persistence — cookie + sessionStorage + localStorage mirror
	// =============================================================================

	function readCookie( name ) {
		var match = document.cookie.match( '(^|;)\\s*' + name + '\\s*=\\s*([^;]+)' );
		return match ? decodeURIComponent( match.pop() ) : '';
	}

	// Every cookie name -> first value (the most specific one the browser sent).
	function cookieMap() {
		var out = {};
		var parts = String( document.cookie || '' ).split( ';' );
		for ( var i = 0; i < parts.length; i++ ) {
			var eq = parts[ i ].indexOf( '=' );
			if ( eq < 1 ) {
				continue;
			}
			var name = parts[ i ].slice( 0, eq ).replace( /^\s+|\s+$/g, '' );
			if ( ! name || out.hasOwnProperty( name ) ) {
				continue;
			}
			try {
				out[ name ] = decodeURIComponent( parts[ i ].slice( eq + 1 ) );
			} catch ( e ) {
				out[ name ] = parts[ i ].slice( eq + 1 );
			}
		}
		return out;
	}

	function secureAttr() {
		return window.location.protocol === 'https:' ? ';Secure' : '';
	}

	// Resolved once: the configured registrable domain, only when it covers the
	// current host and the browser accepts a probe cookie on it; else host-only.
	var cookieDomainResolved = null;
	function cookieDomain() {
		if ( cookieDomainResolved !== null ) {
			return cookieDomainResolved;
		}
		cookieDomainResolved = '';
		var domain = String( COOKIE_DOMAIN || '' ).toLowerCase().replace( /^\.+|\.+$/g, '' );
		var host = String( window.location.hostname || '' ).toLowerCase();
		if ( ! domain || ! host || ( host !== domain && host.slice( -( domain.length + 1 ) ) !== '.' + domain ) ) {
			return cookieDomainResolved;
		}
		var probe = 'apointoo_scope_probe';
		document.cookie = probe + '=1;path=/;domain=' + domain + ';SameSite=Lax' + secureAttr();
		if ( readCookie( probe ) === '1' ) {
			cookieDomainResolved = domain;
		}
		document.cookie = probe + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;Max-Age=0;path=/;domain=' + domain + ';SameSite=Lax' + secureAttr();
		return cookieDomainResolved;
	}

	function writeCookie( name, value, days ) {
		var expires = '';
		if ( days ) {
			var date = new Date();
			date.setTime( date.getTime() + days * 86400000 );
			expires = ';expires=' + date.toUTCString();
		}
		var domain = cookieDomain();
		if ( domain ) {
			// A host-only twin would shadow the shared cookie on this host.
			document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;Max-Age=0;path=/;SameSite=Lax' + secureAttr();
		}
		document.cookie =
			name + '=' + encodeURIComponent( value ) + expires + ';path=/' +
			( domain ? ';domain=' + domain : '' ) + ';SameSite=Lax' + secureAttr();
	}

	function removeCookie( name ) {
		var expired = '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;Max-Age=0;path=/';
		document.cookie = name + expired + ';SameSite=Lax' + secureAttr();
		var domain = cookieDomain();
		if ( domain ) {
			document.cookie = name + expired + ';domain=' + domain + ';SameSite=Lax' + secureAttr();
		}
	}

	function safeParse( raw ) {
		try {
			var data = JSON.parse( raw );
			return data && typeof data === 'object' ? data : null;
		} catch ( e ) {
			return null;
		}
	}

	function lsGet( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function lsSet( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
	}

	function lsRemove( key ) {
		try {
			window.localStorage.removeItem( key );
		} catch ( e ) {}
	}

	function ssGet( key ) {
		try {
			return window.sessionStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function ssSet( key, value ) {
		try {
			window.sessionStorage.setItem( key, value );
		} catch ( e ) {}
	}

	function ssRemove( key ) {
		try {
			window.sessionStorage.removeItem( key );
		} catch ( e ) {}
	}

	// Read the durable record: cookie first, then localStorage, then sessionStorage.
	function loadDurable() {
		var cookieObj = readCookie( COOKIE ) ? safeParse( readCookie( COOKIE ) ) : null;
		if ( cookieObj ) {
			return cookieObj;
		}
		var lsObj = lsGet( COOKIE ) ? safeParse( lsGet( COOKIE ) ) : null;
		if ( lsObj ) {
			return lsObj;
		}
		var ssObj = ssGet( COOKIE ) ? safeParse( ssGet( COOKIE ) ) : null;
		if ( ssObj ) {
			return ssObj;
		}
		return {};
	}

	// Write the durable record: cookie + both storages (resilience against ITP).
	function saveDurable( data ) {
		var json = JSON.stringify( data );
		writeCookie( COOKIE, json, VISITOR_DAYS );
		lsSet( COOKIE, json );
		ssSet( COOKIE, json );
	}

	function clearDurable() {
		removeCookie( COOKIE );
		lsRemove( COOKIE );
		ssRemove( COOKIE );
	}

	// =============================================================================
	// Public API — the plugin's stable external contract
	// =============================================================================

	/**
	 * window.apointooTracking() — the plugin's stable public JS contract. Site-specific
	 * integration scripts should read attribution through this function rather than
	 * parsing the `apointoo_capture` cookie or hidden `apointoo_*` form fields
	 * directly: the field names below will not change without a major version bump.
	 *
	 * Safe to call at any time, including before the tracker has finished booting —
	 * returns `{}` rather than throwing. `{}` (or a record missing a given key) can
	 * mean either "no attribution signal captured yet" or "marketing consent not
	 * yet granted" under the two-phase consent model; it does not necessarily mean
	 * the visitor is untracked.
	 *
	 * Keys mirror the server-side contract (`Attribution::keys()`): identity
	 * (`visitor_id`, `session_id`), UTM params, click ids, `referrer`/`landing_page`,
	 * last-touch derived fields (`source`, `medium`, `channel`, `lt_channel`), and
	 * write-once first-touch fields (`ft_source`, `ft_medium`, `ft_campaign`,
	 * `ft_landing_page`, `ft_channel`, `ft_timestamp`). `channel`/`ft_channel`/
	 * `lt_channel` are distinct: `channel` is a granular platform label (e.g.
	 * "Google Ads"), while `ft_channel`/`lt_channel` are the coarser, stable bucket
	 * (`paid_search`, `paid_social`, `organic_search`, `organic_social`, `direct`,
	 * `referral`, `other`) — first-touch is captured once and never overwritten,
	 * last-touch refreshes on every visit that carries a new attribution signal.
	 *
	 * @return {Object<string, string>} Flat field map. Never null/undefined.
	 */
	window.apointooTracking = function () {
		return loadDurable();
	};

	// =============================================================================
	// Classifier — derive source / medium / channel
	// =============================================================================

	function classifyReferrer( host ) {
		var h = normalizeHost( host );
		if ( ! h ) {
			return null;
		}
		var i;
		for ( i = 0; i < SEARCH_RULES.length; i++ ) {
			var s = SEARCH_RULES[ i ];
			if ( ( s.domain && hostMatchesDomain( h, s.domain ) ) || ( s.label && hostMatchesLabel( h, s.label ) ) ) {
				return { source: s.source, medium: 'organic' };
			}
		}
		for ( i = 0; i < SOCIAL_RULES.length; i++ ) {
			var soc = SOCIAL_RULES[ i ];
			for ( var d = 0; d < soc.domains.length; d++ ) {
				if ( hostMatchesDomain( h, soc.domains[ d ] ) ) {
					return { source: soc.source, medium: 'social' };
				}
			}
		}
		return { source: h, medium: 'referral' };
	}

	function paidLabelFromSource( source, medium ) {
		var s = String( source || '' ).toLowerCase();
		if ( [ 'google', 'google ads', 'googleads', 'youtube', 'gdn' ].indexOf( s ) !== -1 ) {
			return 'Google Ads';
		}
		if ( [ 'bing', 'microsoft', 'msn' ].indexOf( s ) !== -1 ) {
			return 'Microsoft Ads';
		}
		if ( [ 'facebook', 'meta', 'instagram', 'fb', 'ig' ].indexOf( s ) !== -1 ) {
			return 'Facebook Ads';
		}
		if ( s === 'linkedin' ) {
			return 'LinkedIn Ads';
		}
		if ( [ 'twitter', 'x' ].indexOf( s ) !== -1 ) {
			return 'X Ads';
		}
		if ( s === 'reddit' ) {
			return 'Reddit Ads';
		}
		if ( s === 'tiktok' ) {
			return 'TikTok Ads';
		}
		if ( s === 'pinterest' ) {
			return 'Pinterest Ads';
		}
		if ( [ 'snapchat', 'snap' ].indexOf( s ) !== -1 ) {
			return 'Snapchat Ads';
		}
		return medium === 'paid_social' ? 'Paid Social' : 'Paid Search';
	}

	function resolveChannel( qs, referrer ) {
		// Paid click IDs — highest priority.
		if ( qs.gclid || qs.gbraid || qs.wbraid ) {
			return 'Google Ads';
		}
		if ( qs.msclkid ) {
			return 'Microsoft Ads';
		}
		if ( qs.li_fat_id ) {
			return 'LinkedIn Ads';
		}
		if ( qs.twclid ) {
			return 'X Ads';
		}
		if ( qs.rdt_cid ) {
			return 'Reddit Ads';
		}
		if ( qs.ttclid ) {
			return 'TikTok Ads';
		}
		if ( qs.epik || qs.pin_cid ) {
			return 'Pinterest Ads';
		}
		if ( qs.sccid || qs.snap_cid ) {
			return 'Snapchat Ads';
		}
		if ( qs.dclid ) {
			return 'Display & Video 360';
		}

		var med = String( qs.utm_medium || '' ).toLowerCase();

		// fbclid is Ads only when a paid medium is also present.
		if ( qs.fbclid && PAID_MEDIUMS.indexOf( med ) !== -1 ) {
			return 'Facebook Ads';
		}

		var src = String( qs.utm_source || '' ).toLowerCase();

		// Paid medium with no surviving click ID — classify by source before the
		// referrer block so a paid visit never falls through to organic.
		if ( PAID_MEDIUMS.indexOf( med ) !== -1 ) {
			return paidLabelFromSource( src, med );
		}

		// Mailchimp campaign clicks — before the referrer block, because webmail
		// referrers (mail.google.com) would otherwise read as organic search.
		if ( hasAny( qs, EMAIL_CLICK_IDS ) ) {
			return 'Mailchimp';
		}

		// Referrer-based organic classification.
		var refUrl = parseUrl( referrer );
		var refHost = refUrl ? normalizeHost( refUrl.hostname ) : '';
		if ( refHost ) {
			if ( hostMatchesLabel( refHost, 'google' ) ) {
				return 'Google Organic';
			}
			if ( hostMatchesDomain( refHost, 'bing.com' ) ) {
				return 'Bing Organic';
			}
			if ( hostMatchesLabel( refHost, 'yahoo' ) ) {
				return 'Yahoo';
			}
			if ( hostMatchesDomain( refHost, 'duckduckgo.com' ) ) {
				return 'DuckDuckGo';
			}
			if ( hostMatchesLabel( refHost, 'yandex' ) ) {
				return 'Yandex';
			}
			if ( hostMatchesDomain( refHost, 'facebook.com' ) || hostMatchesDomain( refHost, 'fb.com' ) ) {
				return 'Facebook Organic';
			}
			if ( hostMatchesDomain( refHost, 'instagram.com' ) ) {
				return 'Instagram Organic';
			}
			if ( hostMatchesDomain( refHost, 'linkedin.com' ) || hostMatchesDomain( refHost, 'lnkd.in' ) ) {
				return 'LinkedIn Organic';
			}
			if ( hostMatchesDomain( refHost, 'twitter.com' ) || hostMatchesDomain( refHost, 't.co' ) || hostMatchesDomain( refHost, 'x.com' ) ) {
				return 'X Organic';
			}
			if ( hostMatchesDomain( refHost, 'reddit.com' ) ) {
				return 'Reddit Organic';
			}
			if ( hostMatchesDomain( refHost, 'tiktok.com' ) ) {
				return 'TikTok Organic';
			}
			if ( hostMatchesDomain( refHost, 'pinterest.com' ) ) {
				return 'Pinterest Organic';
			}
		}

		// fbclid without a paid medium defaults to organic Facebook.
		if ( qs.fbclid ) {
			return 'Facebook Organic';
		}

		return 'Unknown';
	}

	// Coarse channel bucket — paid_search / paid_social / organic_search /
	// organic_social / direct / referral / other. Stable across ad-network naming
	// churn (unlike resolveChannel's platform labels above); this is what
	// ft_channel / lt_channel store. Same signal priority as resolveChannel: click
	// ids, then paid medium, then referrer. `extHost` must already be filtered to
	// an external host (or '') — pass externalReferrer()'s `.host`, never a raw
	// referrer, or same-site navigation misclassifies as 'referral'.
	function resolveChannelBucket( qs, extHost ) {
		var i;

		for ( i = 0; i < SEARCH_CLICK_IDS.length; i++ ) {
			if ( qs[ SEARCH_CLICK_IDS[ i ] ] ) {
				return 'paid_search';
			}
		}
		for ( i = 0; i < SOCIAL_CLICK_IDS.length; i++ ) {
			if ( qs[ SOCIAL_CLICK_IDS[ i ] ] ) {
				return 'paid_social';
			}
		}

		var med = String( qs.utm_medium || '' ).toLowerCase();

		// fbclid is Ads only when a paid medium is also present (mirrors resolveChannel).
		if ( qs.fbclid && PAID_MEDIUMS.indexOf( med ) !== -1 ) {
			return 'paid_social';
		}

		if ( PAID_MEDIUMS.indexOf( med ) !== -1 ) {
			var src = String( qs.utm_source || '' ).toLowerCase();
			return ( med === 'paid_social' || SOCIAL_AD_SOURCES.indexOf( src ) !== -1 ) ? 'paid_social' : 'paid_search';
		}

		if ( hasAny( qs, EMAIL_CLICK_IDS ) ) {
			return 'other';
		}

		if ( extHost ) {
			var classified = classifyReferrer( extHost );
			if ( classified.medium === 'organic' ) {
				return 'organic_search';
			}
			if ( classified.medium === 'social' ) {
				return 'organic_social';
			}
			return 'referral';
		}

		// fbclid without a paid medium defaults to organic Facebook (mirrors resolveChannel).
		if ( qs.fbclid ) {
			return 'organic_social';
		}

		for ( i = 0; i < UTMS.length; i++ ) {
			if ( qs[ UTMS[ i ] ] ) {
				return 'other';
			}
		}

		return 'direct';
	}

	// External referrer details (drop same-host referrers).
	function externalReferrer( raw ) {
		var url = parseUrl( raw, window.location.href );
		if ( ! url || ! /^https?:$/i.test( url.protocol ) ) {
			return null;
		}
		var refHost = normalizeHost( url.hostname );
		var curHost = normalizeHost( window.location.hostname );
		if ( ! refHost || ! curHost || areRelatedHosts( refHost, curHost ) ) {
			return null;
		}
		return { host: refHost, referrer: sanitizeValue( url.toString() ) };
	}

	// =============================================================================
	// Capture — build the flat record from URL + referrer + stored state
	// =============================================================================

	// Returns a flat object of captured signal keys (utm/click/source/medium/channel)
	// plus referrer + landing_page, or null when there is no qualifying signal beyond
	// identity. `qs` and `referrer` may come from the live page or a promoted buffer.
	function captureSignal( qs, referrer ) {
		var out = {};
		var i;
		var hasSignal = false;

		for ( i = 0; i < UTMS.length; i++ ) {
			var u = sanitizeValue( qs[ UTMS[ i ] ] );
			if ( u ) {
				out[ UTMS[ i ] ] = u;
				hasSignal = true;
			}
		}
		for ( i = 0; i < CLICK_IDS.length; i++ ) {
			var c = sanitizeValue( pickClickId( qs, CLICK_IDS[ i ] ) );
			if ( c ) {
				out[ CLICK_IDS[ i ] ] = c;
				hasSignal = true;
			}
		}

		var ext = externalReferrer( referrer );

		// Derive source/medium/channel.
		if ( hasSignal || ext ) {
			var derived = null;
			if ( hasSignal ) {
				// Prefer explicit utm source/medium, else classify the referrer.
				derived = {
					source: out.utm_source || ( ext ? classifyReferrer( ext.host ).source : '' ),
					medium: out.utm_medium || ( ext ? classifyReferrer( ext.host ).medium : '' )
				};
			} else if ( ext ) {
				derived = classifyReferrer( ext.host );
			}
			if ( derived && derived.source ) {
				out.source = sanitizeValue( derived.source );
			}
			if ( derived && derived.medium ) {
				out.medium = sanitizeValue( derived.medium );
			}
			out.channel = resolveChannel( qs, referrer );
			out.lt_channel = resolveChannelBucket( qs, ext ? ext.host : '' );
			hasSignal = true;
		}

		if ( ext ) {
			out.referrer = ext.referrer;
		}

		return hasSignal ? out : null;
	}

	function hasAny( obj, keys ) {
		for ( var i = 0; i < keys.length; i++ ) {
			if ( obj[ keys[ i ] ] ) {
				return true;
			}
		}
		return false;
	}

	// The page a touch landed on: origin + path, no query (the query's campaign
	// parameters are already captured as their own fields).
	function currentLandingUrl() {
		return sanitizeValue( String( window.location.href || '' ).split( /[?#]/ )[ 0 ] );
	}

	function pathOf( landingUrl ) {
		var url = parseUrl( landingUrl );
		return url ? url.pathname : window.location.pathname;
	}

	// Merge a captured signal into the stored record.
	//
	// A campaign touch (any UTM or click id) replaces the whole last-touch
	// UTM + click-id set, so a Meta click after a Google click never carries the
	// stale gclid into the lead (ClickTrail keeps the newest id per platform;
	// here the newest touch wins outright). A referrer-only touch (organic,
	// referral) refreshes source/medium/channel and keeps the stored click ids,
	// so an organic return visit still converts against the original ad click.
	// First touch — including its Google/Meta/Microsoft click ids — is written
	// once and never overwritten.
	function mergeRecord( data, signal, landingUrl ) {
		var i;
		var landing = landingUrl || currentLandingUrl();
		var campaignKeys = UTMS.concat( CLICK_IDS );
		var lastTouchKeys = campaignKeys.concat( [ 'source', 'medium', 'channel', 'lt_channel' ] );

		// A 0.5.x record has a first touch but never stored ft_<click id>; carry
		// its click ids into the first touch once, before the replace below
		// would drop them. (0.6.0+ records always carry lt_timestamp.)
		if ( data.ft_timestamp && ! data.lt_timestamp ) {
			for ( i = 0; i < FIRST_TOUCH_CLICK_IDS.length; i++ ) {
				var id = FIRST_TOUCH_CLICK_IDS[ i ];
				if ( data[ id ] && ! data[ 'ft_' + id ] ) {
					data[ 'ft_' + id ] = data[ id ];
				}
			}
		}

		if ( hasAny( signal, campaignKeys ) ) {
			for ( i = 0; i < campaignKeys.length; i++ ) {
				delete data[ campaignKeys[ i ] ];
			}
		}

		for ( i = 0; i < lastTouchKeys.length; i++ ) {
			var k = lastTouchKeys[ i ];
			if ( signal[ k ] ) {
				data[ k ] = signal[ k ];
			}
		}
		data.lt_landing_page = landing;
		data.lt_timestamp = String( Date.now() );

		// Referrer + landing page — first touch only.
		if ( ! data.referrer && signal.referrer ) {
			data.referrer = signal.referrer;
		}
		if ( ! data.landing_page ) {
			data.landing_page = pathOf( landing );
		}

		// First touch — set once, never overwrite.
		if ( ! data.ft_timestamp ) {
			if ( signal.source ) {
				data.ft_source = signal.source;
			}
			if ( signal.medium ) {
				data.ft_medium = signal.medium;
			}
			if ( signal.utm_campaign ) {
				data.ft_campaign = signal.utm_campaign;
			}
			if ( signal.lt_channel ) {
				data.ft_channel = signal.lt_channel;
			}
			for ( i = 0; i < FIRST_TOUCH_CLICK_IDS.length; i++ ) {
				if ( signal[ FIRST_TOUCH_CLICK_IDS[ i ] ] ) {
					data[ 'ft_' + FIRST_TOUCH_CLICK_IDS[ i ] ] = signal[ FIRST_TOUCH_CLICK_IDS[ i ] ];
				}
			}
			data.ft_landing_page = pathOf( landing );
			data.ft_timestamp = String( Date.now() );
		}
	}

	// =============================================================================
	// Browser identifiers — ad-pixel + GA4 cookies (ClickTrail BrowserIdentifiers)
	// =============================================================================

	// _ga = GA1.1.<random>.<timestamp> -> "<random>.<timestamp>".
	function parseGaClientId( value ) {
		var parts = String( value || '' ).split( '.' );
		if ( parts.length < 4 ) {
			return '';
		}
		var a = parts[ parts.length - 2 ];
		var b = parts[ parts.length - 1 ];
		return /^\d+$/.test( a ) && /^\d+$/.test( b ) ? a + '.' + b : '';
	}

	// _ga_<id> = GS2.1.s<session>$o<number>$g..$t.. (or legacy GS1.1.<session>.<number>.…).
	function parseGaSession( value ) {
		var out = {};
		var v = String( value || '' );
		var gs2 = /^GS2\.\d+\.(.*)$/.exec( v );
		if ( gs2 ) {
			var tokens = gs2[ 1 ].split( '$' );
			for ( var i = 0; i < tokens.length; i++ ) {
				var field = tokens[ i ].slice( 1 );
				if ( tokens[ i ].charAt( 0 ) === 's' && ! out.ga_session_id && /^\d{6,}$/.test( field ) ) {
					out.ga_session_id = field;
				} else if ( tokens[ i ].charAt( 0 ) === 'o' && ! out.ga_session_number && /^\d+$/.test( field ) ) {
					out.ga_session_number = field;
				}
			}
			return out;
		}
		if ( v.indexOf( 'GS1.' ) === 0 ) {
			var p = v.split( '.' );
			if ( /^\d{6,}$/.test( p[ 2 ] || '' ) ) {
				out.ga_session_id = p[ 2 ];
			}
			if ( /^\d+$/.test( p[ 3 ] || '' ) ) {
				out.ga_session_number = p[ 3 ];
			}
		}
		return out;
	}

	function collectBrowserIds( data ) {
		var c = cookieMap();
		var out = {};
		if ( c._fbp ) {
			out.fbp = c._fbp;
		}
		if ( c._fbc ) {
			out.fbc = c._fbc;
		} else if ( data.fbclid ) {
			// Meta's documented fbc format when the pixel has not written _fbc.
			// The creation time is when this fbclid was first seen, so an fbc
			// already built for the same fbclid is kept as-is.
			var suffix = '.' + data.fbclid;
			var stored = String( data.fbc || '' );
			out.fbc = stored.slice( -suffix.length ) === suffix ? stored : 'fb.1.' + String( Date.now() ) + suffix;
		}
		if ( c._ttp ) {
			out.ttp = c._ttp;
		}
		if ( c.li_gc ) {
			out.li_gc = c.li_gc;
		}
		var clientId = parseGaClientId( c._ga );
		if ( clientId ) {
			out.ga_client_id = clientId;
		}
		var session = {};
		if ( GA4_MEASUREMENT_ID ) {
			session = parseGaSession( c[ '_ga_' + GA4_MEASUREMENT_ID.replace( /^G-/i, '' ) ] );
		} else {
			for ( var name in c ) {
				if ( c.hasOwnProperty( name ) && name.indexOf( '_ga_' ) === 0 ) {
					session = parseGaSession( c[ name ] );
					if ( session.ga_session_id ) {
						break;
					}
				}
			}
		}
		if ( session.ga_session_id ) {
			out.ga_session_id = session.ga_session_id;
		}
		if ( session.ga_session_number ) {
			out.ga_session_number = session.ga_session_number;
		}
		return out;
	}

	function applyBrowserIds( data ) {
		var ids = collectBrowserIds( data );
		for ( var i = 0; i < BROWSER_ID_KEYS.length; i++ ) {
			var value = sanitizeValue( ids[ BROWSER_ID_KEYS[ i ] ] );
			if ( value ) {
				data[ BROWSER_ID_KEYS[ i ] ] = value;
			}
		}
	}

	// Mint / refresh identity on the record.
	function applyIdentity( data ) {
		var now = Date.now();
		if ( ! data.visitor_id ) {
			data.visitor_id = randomId( 'vis' );
		}
		if ( ! data.session_id || ! data._session_seen || ( now - data._session_seen ) > SESSION_MINUTES * 60000 ) {
			data.session_id = randomId( 'ses' );
		}
		data._session_seen = now;
	}

	// =============================================================================
	// Consent — auto-detect CMP, two-phase buffer/promote
	// =============================================================================

	var Consent = {
		// Returns true (granted), false (denied), or null (unresolved/unknown).
		resolve: function () {
			// CookieYes — an answered banner is the visitor's explicit decision, so
			// it is read before Consent Mode (a GCM default can stay 'denied' when
			// CookieYes is not set to push Consent Mode updates).
			var cky = this.fromCookieYes();
			if ( cky !== null ) {
				return cky;
			}
			// Google Consent Mode — dataLayer 'consent' updates.
			var gcm = this.fromGoogleConsentMode();
			if ( gcm !== null ) {
				return gcm;
			}
			// Cookiebot.
			if ( window.Cookiebot && window.Cookiebot.consent ) {
				return !! window.Cookiebot.consent.marketing;
			}
			// OneTrust.
			var ot = this.fromOneTrust();
			if ( ot !== null ) {
				return ot;
			}
			// Complianz.
			var cmplz = this.fromComplianz();
			if ( cmplz !== null ) {
				return cmplz;
			}
			// wp_consent_api cookie.
			var wpc = readCookie( 'wp_consent_marketing' );
			if ( wpc ) {
				return wpc === 'allow';
			}
			return null;
		},

		// Is any known CMP present on the page, regardless of its current decision?
		// Lets 'auto' distinguish "no CMP -> capture now" from "CMP present but the
		// banner is still pending -> wait for a grant".
		detected: function () {
			if ( window.Cookiebot && window.Cookiebot.consent ) {
				return true;
			}
			if ( typeof window.OptanonActiveGroups === 'string' || typeof window.OnetrustActiveGroups === 'string' ) {
				return true;
			}
			if ( typeof window.cmplz_has_consent === 'function' ) {
				return true;
			}
			if ( readCookie( 'wp_consent_marketing' ) ) {
				return true;
			}
			var dl = window.dataLayer;
			if ( dl && dl.length ) {
				for ( var d = 0; d < dl.length; d++ ) {
					if ( dl[ d ] && dl[ d ][ 0 ] === 'consent' ) {
						return true;
					}
				}
			}
			return false;
		},

		fromGoogleConsentMode: function () {
			// Scan dataLayer for the most recent consent 'update'/'default' call.
			var dl = window.dataLayer;
			if ( ! dl || ! dl.length ) {
				return null;
			}
			var resolved = null;
			for ( var i = 0; i < dl.length; i++ ) {
				var entry = dl[ i ];
				if ( ! entry || entry[ 0 ] !== 'consent' ) {
					continue;
				}
				var payload = entry[ 2 ];
				if ( ! payload || typeof payload !== 'object' ) {
					continue;
				}
				var adUserData = payload.ad_user_data;
				var adStorage = payload.ad_storage;
				if ( adUserData === 'denied' || adStorage === 'denied' ) {
					resolved = false;
				} else if ( adUserData === 'granted' || adStorage === 'granted' ) {
					resolved = true;
				}
			}
			return resolved;
		},

		fromOneTrust: function () {
			var groups = window.OptanonActiveGroups || window.OnetrustActiveGroups;
			if ( typeof groups !== 'string' || groups === '' ) {
				return null;
			}
			// C0004 = Targeting/Advertising cookies in OneTrust's default taxonomy.
			return groups.indexOf( 'C0004' ) !== -1;
		},

		fromComplianz: function () {
			if ( typeof window.cmplz_has_consent === 'function' ) {
				try {
					return !! window.cmplz_has_consent( 'marketing' );
				} catch ( e ) {
					return null;
				}
			}
			return null;
		},

		// cookieyes-consent = "consentid:…,consent:yes,action:yes,…,advertisement:yes".
		// Unresolved (null) until the visitor has acted on the banner.
		fromCookieYes: function () {
			var raw = readCookie( 'cookieyes-consent' );
			if ( ! raw ) {
				return null;
			}
			var map = {};
			var pairs = raw.split( ',' );
			for ( var i = 0; i < pairs.length; i++ ) {
				var c = pairs[ i ].indexOf( ':' );
				if ( c > 0 ) {
					map[ pairs[ i ].slice( 0, c ) ] = pairs[ i ].slice( c + 1 );
				}
			}
			if ( map.action !== 'yes' ) {
				return null;
			}
			return map.advertisement === 'yes';
		}
	};

	// Buffer captured signal to sessionStorage (first landing wins).
	function bufferPending( signal, referrer ) {
		if ( ssGet( PENDING_KEY ) ) {
			return;
		}
		ssSet( PENDING_KEY, JSON.stringify( {
			v: 1,
			savedAt: Date.now(),
			signal: signal,
			referrer: ( referrer || '' ).slice( 0, 512 ),
			landing: currentLandingUrl()
		} ) );
	}

	function readPending() {
		var raw = ssGet( PENDING_KEY );
		if ( ! raw ) {
			return null;
		}
		var parsed = safeParse( raw );
		if ( ! parsed || parsed.v !== 1 ) {
			return null;
		}
		return parsed;
	}

	function clearPending() {
		ssRemove( PENDING_KEY );
	}

	// =============================================================================
	// Form filling
	// =============================================================================

	// Forms we never touch: search forms, WP login/comment forms.
	function shouldSkipForm( form ) {
		if ( ! form || form.nodeType !== 1 ) {
			return true;
		}
		var role = ( form.getAttribute( 'role' ) || '' ).toLowerCase();
		if ( role === 'search' ) {
			return true;
		}
		var method = ( form.getAttribute( 'method' ) || '' ).toLowerCase();
		// GET search-style forms: skip when they look like a search box.
		if ( method === 'get' && form.querySelector( 'input[type="search"], input[name="s"]' ) ) {
			return true;
		}
		var id = ( form.getAttribute( 'id' ) || '' ).toLowerCase();
		if ( id === 'loginform' || id === 'commentform' ) {
			return true;
		}
		var action = ( form.getAttribute( 'action' ) || '' ).toLowerCase();
		if ( action.indexOf( 'wp-login.php' ) !== -1 || action.indexOf( 'wp-comments-post.php' ) !== -1 ) {
			return true;
		}
		return false;
	}

	function ensureHiddenInput( form, name ) {
		var input = form.querySelector( 'input[name="' + name + '"]' );
		if ( ! input ) {
			input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			form.appendChild( input );
		}
		return input;
	}

	function fillForm( form, data ) {
		for ( var i = 0; i < KEYS.length; i++ ) {
			var key = KEYS[ i ];
			var value = data[ key ] ? String( data[ key ] ) : '';
			var input = form.querySelector( 'input[name="' + PREFIX + key + '"]' );
			if ( value === '' ) {
				if ( input ) {
					input.value = '';
				}
				continue;
			}
			input = input || ensureHiddenInput( form, PREFIX + key );
			if ( input.value !== value ) {
				input.value = value;
			}
		}
	}

	function fillForms( data ) {
		var forms = document.querySelectorAll( 'form' );
		for ( var f = 0; f < forms.length; f++ ) {
			if ( ! shouldSkipForm( forms[ f ] ) ) {
				fillForm( forms[ f ], data );
			}
		}
	}

	function clearFilledFields() {
		var inputs = document.querySelectorAll( 'input[name^="' + PREFIX + '"]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			inputs[ i ].value = '';
		}
	}

	// =============================================================================
	// Cross-domain link decoration
	// =============================================================================

	var Decorator = {
		isSkippable: function ( href ) {
			if ( ! href ) {
				return true;
			}
			var h = String( href ).replace( /^\s+/, '' ).toLowerCase();
			return h.charAt( 0 ) === '#' ||
				h.indexOf( 'mailto:' ) === 0 ||
				h.indexOf( 'tel:' ) === 0 ||
				h.indexOf( 'javascript:' ) === 0;
		},

		isAllowed: function ( url ) {
			var host = normalizeHost( url.hostname );
			var i;
			for ( i = 0; i < ALLOWED_DOMAINS.length; i++ ) {
				var d = normalizeHost( ALLOWED_DOMAINS[ i ] );
				if ( d && ( host === d || host.slice( -( d.length + 1 ) ) === '.' + d ) ) {
					return true;
				}
			}
			// Same registrable domain (sibling/child subdomains).
			var currentHost = normalizeHost( window.location.hostname );
			if ( host.slice( -( currentHost.length + 1 ) ) === '.' + currentHost ) {
				return true;
			}
			return getRegistrableDomain( host ) === getRegistrableDomain( currentHost );
		},

		decorate: function ( rawHref, data ) {
			if ( this.isSkippable( rawHref ) ) {
				return null;
			}
			var url = parseUrl( rawHref );
			if ( ! url ) {
				return null;
			}
			// Outbound only.
			if ( url.origin === window.location.origin ) {
				return null;
			}
			if ( ! this.isAllowed( url ) ) {
				return null;
			}
			// Skip already-signed URLs.
			var signedKeys = [ 'x-amz-signature', 'signature', 'sig', 'token' ];
			for ( var s = 0; s < signedKeys.length; s++ ) {
				if ( url.searchParams.has( signedKeys[ s ] ) ) {
					return null;
				}
			}

			var changed = false;
			var keys = UTMS.concat( CLICK_IDS );
			for ( var i = 0; i < keys.length; i++ ) {
				var k = keys[ i ];
				var val = data[ k ];
				if ( val && ! url.searchParams.has( k ) ) {
					url.searchParams.set( k, val );
					changed = true;
				}
			}
			return changed ? url.toString() : null;
		}
	};

	// =============================================================================
	// Engine — orchestrates capture, consent, fill, decorate
	// =============================================================================

	var Engine = {
		granted: false,
		decorationBound: false,
		observer: null,

		// Capture identity + signal; persist to the durable record. Used in the
		// immediate path (consent not required) and on promotion (consent granted).
		commit: function ( qs, referrer, promotedSignal, landingUrl ) {
			var data = loadDurable();
			applyIdentity( data );

			var signal = promotedSignal || captureSignal( qs, referrer );
			if ( signal ) {
				mergeRecord( data, signal, landingUrl );
			} else if ( ! data.landing_page ) {
				data.landing_page = window.location.pathname;
			}
			applyBrowserIds( data );

			saveDurable( data );
			return data;
		},

		fill: function ( data ) {
			fillForms( data );
		},

		// Promote the buffered pending capture into the durable record + fill + decorate.
		promote: function () {
			var qs = params();
			var referrer = document.referrer;
			var liveSignal = captureSignal( qs, referrer );
			var promoted = liveSignal;
			var landing = '';

			if ( ! liveSignal ) {
				var pending = readPending();
				if ( pending && pending.signal ) {
					promoted = pending.signal;
					landing = pending.landing || '';
					if ( ! referrer && pending.referrer ) {
						referrer = pending.referrer;
					}
				}
			}

			var data = this.commit( qs, referrer, promoted, landing );
			clearPending();
			this.activate( data );
		},

		// Consent is settled as granted (or not required): fill + decorate + watch.
		activate: function ( data ) {
			this.granted = true;
			this.fill( data );
			this.enableDecoration();
			this.observe( data );
		},

		deny: function () {
			this.granted = false;
			clearDurable();
			clearPending();
			clearFilledFields();
		},

		// Decoration reads the live record at click time, so a later withdrawal
		// (record cleared) stops decoration without unbinding.
		enableDecoration: function () {
			if ( ! DECORATION_ENABLED || this.decorationBound ) {
				return;
			}
			this.decorationBound = true;
			var handler = function ( evt ) {
				if ( ! Engine.granted ) {
					return;
				}
				var node = evt.target;
				var a = node && node.closest ? node.closest( 'a' ) : null;
				if ( ! a ) {
					return;
				}
				var decorated = Decorator.decorate( a.getAttribute( 'href' ), loadDurable() );
				if ( decorated ) {
					a.href = decorated;
				}
			};
			document.addEventListener( 'mousedown', handler, true );
			document.addEventListener( 'touchstart', handler, { capture: true, passive: true } );
		},

		// Refresh browser ids (the pixel may have set _fbp/_ga after landing) and
		// re-fill the submitted form right before it is sent.
		onSubmit: function ( evt ) {
			if ( ! Engine.granted ) {
				return;
			}
			var form = evt.target;
			if ( ! form || form.tagName !== 'FORM' || shouldSkipForm( form ) ) {
				return;
			}
			var data = loadDurable();
			applyBrowserIds( data );
			saveDurable( data );
			Engine.currentData = data;
			fillForm( form, data );
		},

		// Re-run fill (and keep decoration current) when forms/inputs are added.
		observe: function ( data ) {
			this.currentData = data;
			if ( this.observer || typeof MutationObserver === 'undefined' ) {
				return;
			}
			var self = this;
			var timer;
			this.observer = new MutationObserver( function ( mutations ) {
				var hasNew = false;
				for ( var m = 0; m < mutations.length && ! hasNew; m++ ) {
					var nodes = mutations[ m ].addedNodes;
					if ( ! nodes ) {
						continue;
					}
					for ( var n = 0; n < nodes.length; n++ ) {
						var node = nodes[ n ];
						if ( node.nodeType !== 1 ) {
							continue;
						}
						if ( node.tagName === 'FORM' || node.tagName === 'INPUT' ||
							( node.querySelector && node.querySelector( 'form, input' ) ) ) {
							hasNew = true;
							break;
						}
					}
				}
				if ( ! hasNew || ! self.granted ) {
					return;
				}
				clearTimeout( timer );
				timer = setTimeout( function () {
					var fresh = loadDurable();
					self.currentData = fresh;
					self.fill( fresh );
				}, 100 );
			} );
			this.observer.observe( document.body || document.documentElement, { childList: true, subtree: true } );
		}
	};

	// =============================================================================
	// WhatsApp — contact_whatsapp_start (ClickTrail event name + params)
	// =============================================================================

	var WhatsApp = {
		seen: {},

		// wa.me / api|web.whatsapp.com / whatsapp:// -> { link_url, phone } or null.
		parse: function ( raw ) {
			var url = parseUrl( raw );
			if ( ! url ) {
				return null;
			}
			var host = normalizeHost( url.hostname );
			if ( url.protocol !== 'whatsapp:' && WHATSAPP_HOSTS.indexOf( host ) === -1 ) {
				return null;
			}
			var candidate = '';
			try {
				candidate = host === 'wa.me' ?
					decodeURIComponent( url.pathname.split( '/' )[ 1 ] || '' ) :
					( url.searchParams.get( 'phone' ) || '' );
			} catch ( e ) {
				candidate = '';
			}
			var phone = /^[+\d\s().-]+$/.test( candidate ) ? candidate.replace( /\D/g, '' ) : '';
			if ( phone.length < 7 || phone.length > 15 ) {
				phone = '';
			}
			// The pre-filled message can hold visitor-typed text: never forward it.
			url.searchParams.delete( 'text' );
			url.hash = '';
			return { link_url: url.toString(), phone: phone };
		},

		track: function ( raw, label ) {
			if ( ! Engine.granted ) {
				return;
			}
			var wa = this.parse( raw );
			if ( ! wa ) {
				return;
			}
			var now = Date.now();
			if ( this.seen[ wa.link_url ] && now - this.seen[ wa.link_url ] < WHATSAPP_DEDUPE_MS ) {
				return;
			}
			this.seen[ wa.link_url ] = now;

			var data = loadDurable();
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push( {
				event: 'contact_whatsapp_start',
				event_id: randomId( 'evt' ),
				contact_type: 'whatsapp',
				link_url: wa.link_url,
				phone: wa.phone,
				cta_label: sanitizeValue( label ).slice( 0, 100 ),
				apointoo_visitor_id: data.visitor_id || '',
				apointoo_session_id: data.session_id || '',
				source: data.source || '',
				medium: data.medium || '',
				campaign: data.utm_campaign || '',
				channel: data.channel || '',
				lt_channel: data.lt_channel || ''
			} );
		},

		// Anchors with a WhatsApp href, plus widgets that open WhatsApp through
		// window.open with no href at all (JoinChat, most floating chat buttons).
		bind: function () {
			document.addEventListener( 'click', function ( evt ) {
				var node = evt.target;
				var a = node && node.closest ? node.closest( 'a[href]' ) : null;
				if ( a ) {
					WhatsApp.track( a.getAttribute( 'href' ), a.textContent || '' );
				}
			}, true );

			var nativeOpen = window.open;
			if ( typeof nativeOpen === 'function' ) {
				window.open = function ( target ) {
					try {
						WhatsApp.track( target, '' );
					} catch ( e ) {}
					return nativeOpen.apply( this, arguments );
				};
			}
		}
	};

	// =============================================================================
	// Consent listeners + short poll
	// =============================================================================

	// CMP events stay bound for the whole page view, so a withdrawal after a
	// grant clears the record. The poll only runs while consent is unresolved.
	function bindConsentListeners( onGrant, onDeny, pollForGrant ) {
		var onChange = function () {
			var v = Consent.resolve();
			if ( v === true ) {
				onGrant();
			} else if ( v === false ) {
				onDeny();
			}
		};

		// Cookiebot.
		window.addEventListener( 'CookiebotOnConsentReady', onChange );
		// Complianz.
		document.addEventListener( 'cmplz_status_change', onChange );
		// CookieYes (accept, reject, and later changes from the revisit button).
		document.addEventListener( 'cookieyes_consent_update', onChange );

		if ( ! pollForGrant ) {
			return;
		}

		// Short poll: catch a late grant where no event is available (e.g. Google
		// Consent Mode update pushed to dataLayer without a custom event).
		// The poll only acts on an affirmative GRANT. A `false` here is ambiguous
		// (an undecided Cookiebot banner / GCM default both read as denied), and
		// nothing is persisted without a grant anyway, so we never auto-deny from the
		// poll — an explicit withdrawal comes through the CMP events above.
		var attempts = 0;
		var maxAttempts = 40; // ~20s at 500ms.
		var poll = setInterval( function () {
			attempts++;
			if ( Engine.granted || attempts >= maxAttempts ) {
				clearInterval( poll );
				return;
			}
			if ( Consent.resolve() === true ) {
				clearInterval( poll );
				onGrant();
			}
		}, 500 );
	}

	// =============================================================================
	// Boot
	// =============================================================================

	function start() {
		if ( isBot() ) {
			return;
		}

		document.addEventListener( 'submit', Engine.onSubmit, true );
		if ( WHATSAPP_EVENTS ) {
			WhatsApp.bind();
		}

		// Legacy path — capture + persist + fill + decorate immediately.
		if ( CONSENT_REQUIRE === 'never' ) {
			Engine.activate( Engine.commit( params(), document.referrer ) );
			return;
		}

		// Two-phase path — buffer first, do not persist/fill/decorate until granted.
		var qs = params();
		var referrer = document.referrer;
		var signal = captureSignal( qs, referrer );
		if ( signal ) {
			bufferPending( signal, referrer );
		}

		// Only persist on an affirmative grant. An initial `false`/unresolved
		// (e.g. no CMP or a banner still showing) is NOT
		// treated as a final deny — we wait for a real decision.
		var grantedNow = Consent.resolve() === true;
		if ( grantedNow ) {
			Engine.promote();
		}

		bindConsentListeners(
			function () {
				if ( ! Engine.granted ) {
					Engine.promote();
				}
			},
			function () {
				Engine.deny();
			},
			! grantedNow
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
