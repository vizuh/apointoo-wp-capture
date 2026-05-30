=== Apointoo Capture ===
Contributors: vizuh
Tags: attribution, conversion tracking, forms, consent, leads
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: Proprietary
License URI: https://github.com/vizuh/apointoo-wp-capture/blob/main/LICENSE

Connect your existing WordPress forms to Apointoo — server-side, consent-aware lead capture. A capture/wiring adapter, not a form builder.

== Description ==

Apointoo Capture forwards leads from the forms you already use (Contact Form 7,
WPForms, Gravity Forms, Elementor Pro, Fluent Forms) to the Apointoo capture
contract — server-side, consent-aware, with email/phone hashed before they leave
your site.

It adapts to forms that already exist; it never creates, renders, or manages a
form, a booking, or any submission UI.

**This plugin connects to the Apointoo SDK, an external service.** It sends
hashed lead identifiers + attribution to your configured Apointoo tenant endpoint
so conversions can be measured in Google Ads. See the privacy section below.

== Installation ==

1. Install and activate the plugin.
2. In Settings → Apointoo Capture, enter your publishable site key and server
   secret (from your Apointoo tenant).
3. Enable the adapters for the form plugins you use.

== Frequently Asked Questions ==

= Does it store personal data on my site? =

No. Raw email/phone are hashed (SHA-256) in PHP and discarded; only hashes are
forwarded. Captured data lives in the Apointoo ledger, not in WordPress.

= Does it respect consent? =

Yes. It reads your existing consent plugin (WP Consent API / Complianz /
Cookiebot) and only forwards ad identifiers when marketing consent is granted.

== Changelog ==

= 0.1.0 =
* Initial scaffold: form-adapter framework (CF7 working; WPForms/Gravity/
  Elementor/Fluent stubbed), PII hashing, consent reader. Transport to the SDK
  is stubbed pending the public capture contract.

<!--
  ── INTERNAL NOTE ───────────────────────────────────────────────────────────
  Free capture-and-send connector (the paid product is the Apointoo service).
  Distribution decided by wordpress.org — see docs/PLAN.md §8:
    - If wp.org ACCEPTS it: repo goes public + GPLv2-or-later; this readme.txt
      becomes the live directory listing (switch the License line to GPL).
    - If wp.org REJECTS / not pursued: private ZIP to clients; repo stays private
      and proprietary; this readme.txt is kept for the update modal + docs only.
  wp.org may reject a plugin that mainly connects to an external service.

  Still to do before real (non-sandbox) PII:
    - External-service disclosure of the Apointoo SDK data flow (the DPIA documents it).
    - Build the real transport + the deferred security/privacy gates (issues I1–I4, DPIA R3/R4).
-->
