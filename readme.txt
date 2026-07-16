=== Apointoo Capture ===
Contributors: hugoc
Tags: attribution, conversion tracking, forms, consent, leads
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep attribution with existing WordPress forms and optionally send consent-aware leads to Apointoo.

== Description ==

Apointoo Capture keeps first-touch and last-touch attribution with the forms you
already use (Contact Form 7,
WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Ninja Forms, and Kadence Blocks) to
make campaign context available in the site owner's existing form records and email.

This local attribution capture works without an Apointoo account and makes no
external request. When an administrator deliberately configures an Apointoo
tenant key and HTTPS intake URL, the plugin also forwards form leads to the
Apointoo dashboard contact intake server-side and consent-aware.

It adapts to forms that already exist; it never creates, renders, or manages a
form, a booking, or any submission UI.

**This plugin connects to the Apointoo dashboard, an external service.** It sends
contact fields plus consent-aware attribution to your configured tenant endpoint.
The dashboard owns Google Ads normalization, hashing, deduplication, and upload.

Get your credentials and setup instructions from the Apointoo dashboard. Support: support@apointoo.com.

== External service ==

Apointoo Capture connects your site to the **Apointoo service**, operated by Vizuh OÜ, to
measure conversions from the forms you already use. The plugin is an interface to that service; the service
provides the conversion-measurement functionality.

**What is sent, and when.** Only after you enter your Apointoo credentials, and only on a form submission, the
plugin sends to *your configured Apointoo endpoint*: the contact fields required to operate the lead,
marketing attribution, and the visitor's consent state. Ad identifiers are only forwarded when marketing
consent is granted; UTMs and first-party journey context remain available. Google match identifiers are
normalized and hashed by the dashboard. Nothing is sent until you configure the plugin.

* Service: https://www.apointoo.com/
* Terms of Use: https://www.apointoo.com/en/terms
* Privacy Policy: https://www.apointoo.com/en/privacy

== Installation ==

1. Install and activate the plugin.
2. In Settings → Apointoo Capture, enter your publishable site key and server
   secret (from your Apointoo tenant).
3. Enable the adapters for the form plugins you use.

== Frequently Asked Questions ==

= Does it store personal data on my site? =

No lead inbox is created in WordPress. Contact fields are forwarded server-to-server
to the configured Apointoo tenant and stored under that tenant's retention policy.

= Does it respect consent? =

Yes. It reads your existing consent plugin (WP Consent API / Complianz /
Cookiebot) and only forwards ad identifiers when marketing consent is granted.

== Changelog ==

= 0.5.0 =
* Add stable ft_channel/lt_channel buckets and window.apointooTracking() for integrations that need the full captured attribution record.
* Consolidate all seven form adapters on one intake sender and attach the dashboard Consent Mode v2 vector while stripping ad IDs unless marketing consent is granted.
* Align the public privacy disclosure with the live dashboard intake and hashing path.

= 0.4.3 =
* Add Kadence Blocks support: both the Advanced Form and the legacy Form block now forward submissions to Apointoo. Lead fields are matched by label as well as type, so a plain-text "Telefone"/phone field is still captured.

= 0.4.2 =
* Packaging: the distributed build now excludes development tooling (spec-kit, AI assistant config, build/CI files) and wraps the plugin in a correctly-named folder, so installs resolve to the `apointoo-capture` slug and the text domain matches.
* Silence a false-positive nonce notice on the first-activation redirect (a read-only check of WordPress core's bulk-activation query var; no form data is processed).

= 0.4.1 =
* Fix WPForms hook not registering on WPForms Lite: `is_active()` now uses `function_exists('wpforms')` (matching the settings-screen detection) instead of `class_exists('WPForms')` which returns false on Lite.

= 0.4.0 =
* All form adapters now forward to Apointoo: Gravity Forms, Fluent Forms, Elementor Pro Forms, and Ninja Forms wired with the same intake-forward path as CF7 and WPForms. Ninja Forms forwarding is now independent of the Save action.


= 0.3.0 =
* WPForms forwarding is now observable: each submission's result (HTTP status, error body, whether email/phone and attribution were captured) is recorded to a "Recent forwards" table in Settings → Apointoo Capture.
* New "Send test lead" button posts a dummy lead to your intake endpoint and shows the exact response inline — confirms the key, endpoint, and tenant in one click.
* The forward is now a blocking request so failures are caught and logged instead of silently discarded; submissions with no email or phone are skipped with an explicit reason (the intake API requires at least one).
* Hardened lead-field extraction (type-first, with email-shape and phone-shape fallbacks) so a mislabeled field still maps correctly.
* Optional "Debug logging" toggle (or the APOINTOO_CAPTURE_DEBUG constant) mirrors each forward to the PHP error log.

= 0.2.0 =
* Capture engine rewrite (forms-bridge parity): expanded click-ID coverage (Google, Microsoft, Meta, TikTok, X, LinkedIn, Snapchat, Pinterest, Reddit, DV360) plus utm_id; unsubstituted ad-macro rejection; first-touch and last-touch; referrer-based source/medium/channel; bot filtering; cookie + sessionStorage + localStorage persistence.
* Consent: two-phase capture — attribution is buffered and only persisted/injected once marketing consent is granted (Google Consent Mode, Cookiebot, OneTrust, Complianz, or the WP Consent API), with a late-grant listener; denial clears it. Defaults to auto (gates when a consent platform is present, captures immediately when none is).
* Cross-domain link decoration: carries UTMs and click IDs across to an approved booking/checkout domain or subdomain (skipping tel/mailto/signed URLs), re-applied as the DOM changes.
* Forms: hidden-field fill across forms (re-applied to dynamically-added forms), a server-side fallback for Contact Form 7 when the browser could not fill the fields, and a new Ninja Forms adapter. A "Form integrations" panel in Settings shows which form plugins are detected and capturing.

= 0.1.0 =
* Initial scaffold: form-adapter framework (CF7 working; WPForms/Gravity/
  Elementor/Fluent stubbed), PII hashing, consent reader. Transport to the SDK
  is stubbed pending the public capture contract.
