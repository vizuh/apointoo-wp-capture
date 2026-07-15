/**
 * Apointoo Capture — front-end attribution tracker.
 *
 * Captures marketing attribution first-party, mints neutral vis_/ses_ ids, derives
 * a source/medium/channel plus a stable ft_channel/lt_channel bucket pair
 * (paid_search/paid_social/organic_search/organic_social/direct/referral/other),
 * fills the hidden fields the form adapters inject, and (optionally) decorates
 * outbound links so attribution survives a cross-domain hand-off. Nothing is sent
 * anywhere by this script — the data rides the form into the site owner's own
 * systems.
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
		'li_fat_id', 'sccid', 'epik', 'rdt_cid', 'dclid',
		'referrer', 'landing_page',
		'source', 'medium', 'channel', 'lt_channel',
		'ft_source', 'ft_medium', 'ft_campaign', 'ft_landing_page', 'ft_channel', 'ft_timestamp'
	];

	var CLICK_IDS = cfg.clickIds && cfg.clickIds.length ? cfg.clickIds : [
		'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid',
		'li_fat_id', 'sccid', 'epik', 'rdt_cid', 'dclid'
	];
	var UTMS = cfg.utms && cfg.utms.length ? cfg.utms : [
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'
	];
	var FIRST_TOUCH_KEYS = cfg.firstTouchKeys && cfg.firstTouchKeys.length ? cfg.firstTouchKeys : [
		'ft_source', 'ft_medium', 'ft_campaign', 'ft_landing_page', 'ft_channel'
	];

	var KEYS = unionKeys( cfg.keys && cfg.keys.length ? cfg.keys : ALL_KEYS );

	var CONSENT = cfg.consent || {};
	var CONSENT_REQUIRE = CONSENT.require || 'auto'; // 'auto' | 'always' | 'never'

	var DECORATION = cfg.decoration || {};
	var DECORATION_ENABLED = DECORATION.enabled !== false;
	var ALLOWED_DOMAINS = DECORATION.allowedDomains || [];

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
	var SOCIAL_CLICK_IDS = [ 'li_fat_id', 'twclid', 'rdt_cid', 'ttclid', 'epik', 'sccid' ];
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

	function writeCookie( name, value, days ) {
		var expires = '';
		if ( days ) {
			var date = new Date();
			date.setTime( date.getTime() + days * 86400000 );
			expires = ';expires=' + date.toUTCString();
		}
		var secure = window.location.protocol === 'https:' ? ';Secure' : '';
		document.cookie =
			name + '=' + encodeURIComponent( value ) + expires + ';path=/;SameSite=Lax' + secure;
	}

	function removeCookie( name ) {
		var secure = window.location.protocol === 'https:' ? ';Secure' : '';
		document.cookie =
			name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;Max-Age=0;path=/;SameSite=Lax' + secure;
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
		if ( qs.epik ) {
			return 'Pinterest Ads';
		}
		if ( qs.sccid ) {
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

	// Merge a captured signal into the stored record: last-touch overwrite for
	// utm/click/derived, first-touch-only for referrer/landing_page + ft_* fields.
	function mergeRecord( data, signal ) {
		var i;
		var lastTouchKeys = UTMS.concat( CLICK_IDS ).concat( [ 'source', 'medium', 'channel', 'lt_channel' ] );

		for ( i = 0; i < lastTouchKeys.length; i++ ) {
			var k = lastTouchKeys[ i ];
			if ( signal[ k ] ) {
				data[ k ] = signal[ k ];
			}
		}

		// Referrer + landing page — first touch only.
		if ( ! data.referrer && signal.referrer ) {
			data.referrer = signal.referrer;
		}
		if ( ! data.landing_page ) {
			data.landing_page = window.location.pathname;
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
			data.ft_landing_page = window.location.pathname;
			data.ft_timestamp = String( Date.now() );
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
				if ( adUserData === 'granted' || adStorage === 'granted' ) {
					resolved = true;
				} else if ( adUserData === 'denied' || adStorage === 'denied' ) {
					resolved = false;
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
			referrer: ( referrer || '' ).slice( 0, 512 )
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

	function fillForms( data ) {
		var forms = document.querySelectorAll( 'form' );
		for ( var f = 0; f < forms.length; f++ ) {
			var form = forms[ f ];
			if ( shouldSkipForm( form ) ) {
				continue;
			}
			for ( var i = 0; i < KEYS.length; i++ ) {
				var key = KEYS[ i ];
				var value = data[ key ] ? String( data[ key ] ) : '';
				if ( value === '' ) {
					continue;
				}
				var input = ensureHiddenInput( form, PREFIX + key );
				if ( input.value !== value ) {
					input.value = value;
				}
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
		decorationBound: false,
		observer: null,

		// Capture identity + signal; persist to the durable record. Used in the
		// immediate path (consent not required) and on promotion (consent granted).
		commit: function ( qs, referrer, promotedSignal ) {
			var data = loadDurable();
			applyIdentity( data );

			var signal = promotedSignal || captureSignal( qs, referrer );
			if ( signal ) {
				mergeRecord( data, signal );
			} else if ( ! data.landing_page ) {
				data.landing_page = window.location.pathname;
			}

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

			if ( ! liveSignal ) {
				var pending = readPending();
				if ( pending && pending.signal ) {
					promoted = pending.signal;
					if ( ! referrer && pending.referrer ) {
						referrer = pending.referrer;
					}
				}
			}

			var data = this.commit( qs, referrer, promoted );
			clearPending();
			this.fill( data );
			this.enableDecoration( data );
			this.observe( data );
		},

		deny: function () {
			clearDurable();
			clearPending();
			clearFilledFields();
		},

		enableDecoration: function ( dataRef ) {
			if ( ! DECORATION_ENABLED || this.decorationBound ) {
				return;
			}
			this.decorationBound = true;
			var handler = function ( evt ) {
				var node = evt.target;
				var a = node && node.closest ? node.closest( 'a' ) : null;
				if ( ! a ) {
					return;
				}
				var decorated = Decorator.decorate( a.getAttribute( 'href' ), Engine.currentData || dataRef );
				if ( decorated ) {
					a.href = decorated;
				}
			};
			document.addEventListener( 'mousedown', handler, true );
			document.addEventListener( 'touchstart', handler, { capture: true, passive: true } );
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
				if ( ! hasNew ) {
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
	// Late-grant listeners + short poll
	// =============================================================================

	function bindConsentListeners( onGrant, onDeny ) {
		// Cookiebot.
		window.addEventListener( 'CookiebotOnConsentReady', function () {
			var v = Consent.resolve();
			if ( v === true ) {
				onGrant();
			} else if ( v === false ) {
				onDeny();
			}
		} );

		// Generic consent-update events some CMPs / GTM setups dispatch.
		document.addEventListener( 'cmplz_status_change', function () {
			var v = Consent.resolve();
			if ( v === true ) {
				onGrant();
			} else if ( v === false ) {
				onDeny();
			}
		} );

		// Short poll: catch a late grant where no event is available (e.g. Google
		// Consent Mode update pushed to dataLayer without a custom event).
		var attempts = 0;
		var maxAttempts = 40; // ~20s at 500ms.
		var settled = false;
		// The poll only acts on an affirmative GRANT. A `false` here is ambiguous
		// (an undecided Cookiebot banner / GCM default both read as denied), and
		// nothing is persisted without a grant anyway, so we never auto-deny from the
		// poll — an explicit withdrawal comes through the CMP events below.
		var poll = setInterval( function () {
			attempts++;
			var v = Consent.resolve();
			if ( v === true ) {
				settled = true;
				clearInterval( poll );
				onGrant();
			} else if ( attempts >= maxAttempts ) {
				clearInterval( poll );
			}
		}, 500 );

		// Stop polling once settled (guard against races).
		return function () {
			if ( ! settled ) {
				clearInterval( poll );
			}
		};
	}

	// =============================================================================
	// Boot
	// =============================================================================

	function start() {
		if ( isBot() ) {
			return;
		}

		// Legacy path — capture + persist + fill + decorate immediately.
		if ( CONSENT_REQUIRE === 'never' ) {
			var data = Engine.commit( params(), document.referrer );
			Engine.fill( data );
			Engine.enableDecoration( data );
			Engine.observe( data );
			return;
		}

		// Two-phase path — buffer first, do not persist/fill/decorate until granted.
		var qs = params();
		var referrer = document.referrer;
		var signal = captureSignal( qs, referrer );
		if ( signal ) {
			bufferPending( signal, referrer );
		}

		// 'auto' with NO CMP present: nothing to wait on, capture now (non-breaking).
		if ( CONSENT_REQUIRE === 'auto' && ! Consent.detected() ) {
			Engine.promote();
			return;
		}

		// A CMP is present (or require === 'always'): only persist on an affirmative
		// grant. An initial `false`/unresolved (e.g. a banner still showing) is NOT
		// treated as a final deny — we wait for a real decision.
		if ( Consent.resolve() === true ) {
			Engine.promote();
			return;
		}

		bindConsentListeners(
			function () {
				Engine.promote();
			},
			function () {
				Engine.deny();
			}
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
