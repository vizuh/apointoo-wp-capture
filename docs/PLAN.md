# Apointoo WordPress Capture Plugin — Plan

**Status:** plan · **Date:** 2026-05-30 · **Depends on:** `public-capture-contract-proposal.md` (must land first).
**Author trail:** drafted by multi-agent workflow `wp-plugin-plan-and-contract`, corrected against an adversarial litmus critic.
**Companion:** the public capture contract this plugin targets is `_references/public-capture-contract-proposal.md`.

## 1. Angle & litmus justification

This plugin is the **capture pillar's first "bring your own frontend" adapter.** Where Apointoo's booking-app
emits events from a frontend we control, this plugin lets an existing WordPress site — one we did not build,
running a form plugin we did not pick — emit the same neutral `track`/`identify` events into the SDK. Three
litmus reasons it earns its place:

1. **Capture.** Cheapest path to real attribution data flowing through the new contract: thousands of SMB lead
   sites already run WordPress + CF7/Elementor and need zero rebuild.
2. **Frontend-agnostic spine (A1).** It proves the contract is genuinely neutral by feeding it a non-booking
   source (a generic web form) — the plugin speaks `track lead_captured`, never `booking`.
3. **Attribution durability vs ITP.** A same-domain first-party REST proxy under `/wp-json`, plus a
   server-side capture path off the form's PHP hook, set the visitor cookie and read click identifiers in a
   same-domain, **server-set** context that is exempt from Safari ITP's 7-day client-cookie cap — strictly
   more durable than any client-only pixel a competitor ships **(only if the `vis_` cookie is server-set; see §3c).**

It is the adapter that converts "we have a neutral contract" into "we have data."

**Competitive gap (confirmed, with nuance).** Mainstream WP conversion/pixel plugins are client-side
pixels/tags; almost none do true server-side *offline* conversion upload, and the few that touch offline
(PixelYourSite Conversion Exporter, Conversios) are WooCommerce order-recovery, not lead→won/paid outcome
resolution. None are multi-tenant *agency* tools that resolve a lead's downstream outcome across many client
Google Ads accounts. The closest competitor is **PixelYourSite Conversion Exporter** (now with a 100-site
agency license + Data-Manager-compatible hashed feed). **The moat is not raw server-side upload mechanics —
it's the outcome-resolution layer + the multi-account agency control plane.**

## 2. THE HARD BOUNDARY

**This is a capture + wiring adapter, NOT a form product. It adapts to forms that already exist on the site;
it never creates, renders, styles, validates, or manages a form, a booking, or any submission UI.**

The plugin's entire job: read what an existing form already captured → normalize it → attach attribution +
consent → hash PII → forward server-side to the SDK capture contract. Nothing renders to the visitor except,
at most, a thin read-only status panel in wp-admin.

Permanent non-goals:
- **No form builder / fields / templates.** We bind to CF7/WPForms/Gravity/Elementor/Fluent; we do not ship a form.
- **No booking UI, calendar, slot picker, availability widget.** Booking is a downstream *outcome*, not rendered here.
- **No shortcodes/blocks/widgets that output visitor-facing markup.** Only a headless JS tracker is enqueued.
- **No CRM / entry storage / lead inbox.** We forward; we are not a system of record.
- **No client-side pixel suite / GTM replacement.** We may fire a dedup event; we are not a tag manager.
- **No consent banner / CMP.** We *read* the site's existing CMP; we never ask for consent.
- **No business logic on the lead** (scoring, routing, email, automation). Side-effect forwarding only.

If a requested feature would make the plugin usable *without* an existing form, it is out of scope.

## 3. Plugin architecture

Four cooperating subsystems, all thin.

### 3a. Settings page (wp-admin)
Settings API, single `apointoo_capture` option group. Credentials:
- **Publishable site key** (`apointoo_site_key`) — non-secret, embeddable, used by the enqueued tracker to
  identify the tenant.
- **Server secret** (`apointoo_server_secret`) — the tenant API token (ADR-019 `apiTokens`, SHA-256-hashed at
  rest in the SDK). Used **only** in PHP `wp_remote_post` headers, **never** emitted to any page or JS.

The server secret is **encrypted at rest** in `wp_options` (`pre_update_option_*` encrypt / `option_*`
decrypt, AES-256-CBC, key from `wp-config.php` salts). **Documented internally as defense-in-depth /
obfuscation, not secrecy** — the key sits on the same host. The real mitigation is **cheap rotation**: the
SDK supports ≤5 revocable per-tenant tokens, so the UI has a one-click "rotate key" affordance and tolerates
re-pasting a fresh token. Publishable key and server secret are kept distinct in UI and in every code path.

Also: SDK base URL (single allowlisted forward host, versioned), per-adapter enable toggles, read-only "last
forward status" line, and a **license key** field (distribution gate — see §8, kept separate from the secret).

### 3b. Two separate forward paths — the secret never sits behind a browser route (security review C1)

The single most important security boundary in this plugin: **a site visitor must never be able to reach
secret-scoped SDK calls.** The plugin therefore has *two physically separate* forward paths, and they use
*different keys*:

**Path A — visitor-facing `/wp-json` proxy (publishable key, telemetry only).**
`register_rest_route('apointoo/v1', '/capture/track')` — **`track` only, no `identify`.** A
**same-domain, same-IP, server-set** endpoint (the site must NOT CNAME it to a third party, or the ITP
exemption is lost). The browser tracker POSTs telemetry here; the proxy forwards via `wp_remote_post` to the
SDK's `/capture/track` **with the publishable key — never the server secret.**
- `permission_callback` returns `__return_true` but is **nonce-protected** (`X-WP-Nonce`/`wp_rest`) and
  **rate-limited per-IP** via transients. Never trusts a key from the browser.
- The proxy **rejects conversion-eligible event names** (`lead_captured`/`outcome`) at the edge — those are
  not the browser's to send (defense in depth; the SDK also enforces C2). Visitor traffic = telemetry only
  (`page_view`/`session_start`/`form_view`/`form_step`).

**Path B — server-side form hook (server secret, PII + conversion).** The PHP form-adapter callbacks (§4) run
**in-process on the server**, hold the server secret, and call the SDK's `/capture/track` (`lead_captured`)
and `/capture/identify` (hashed PII) directly via `wp_remote_post`. **No browser-reachable route exposes this
path.** This is where every load-bearing event (the conversion, the identity link, the PII) originates.

**Admin/config** routes: `permission_callback = current_user_can('manage_options')`.

Hardening (both paths): strict host allowlist (**exact scheme+host match** of the one SDK URL — not a
substring, or `sdk.vizuh.com.evil.com` slips through), `sanitize_callback`/`validate_callback` on every arg,
no blind cookie forwarding, `is_wp_error` + status/content-type checks on the upstream response, **fail
closed** with a clean `WP_Error` that never leaks the secret or raw upstream error. Tight outbound timeout
(≤3s, off the page-render critical path) because the *primary* capture path is Path B, not the proxy.

### 3c. Enqueued JS tracker (headless)
Small script (no visible DOM), enqueued site-wide:
- **Mint & persist identity.** Generate `vis_…` (visitor) and `ses_…` (session, 30-min sliding) per the SDK's
  neutral id scheme (B1). **The `vis_` cookie is set server-side via `Set-Cookie` from the `/wp-json` proxy on
  first contact** (not client `document.cookie`/`localStorage`) — this is what actually earns the ITP-durability
  claim in §1, since a server-set first-party cookie escapes Safari's 7-day client-script cap. The tracker
  reads the server-set `vis_` and only *mints* one as a fallback when the proxy hasn't yet responded.
- **Read & persist attribution.** Parse UTM params, `gclid`/`gbraid`/`wbraid`/`fbclid`/`msclkid` from the URL,
  `document.referrer`, landing page; persist first-touch + last-touch so attribution survives navigation.
  (A `gclid` via `url_passthrough` does NOT imply consent — see §5.)
- **Emit telemetry only** (`page_view`/`session_start`/`form_view`/`form_step`) to the Path-A proxy, stamping
  the current consent snapshot. **Never emits `lead_captured`/`outcome`** — the conversion is the server hook's
  job (Path B, C2). The lead's existence reaches the SDK from the PHP submit hook, not the browser.
- **Dedup correlation.** Write a generated submission UUID into a hidden field / read the form plugin's entry
  id, so the server-side `lead_captured` (Path B) and the browser's `form_step` telemetry (Path A) correlate.

The tracker is the *secondary/enrichment* channel; it carries no secret and never calls the SDK directly.

### 3d. How it reads consent
Both the tracker and the PHP capture path resolve marketing/`ad_user_data` consent (full gate in §5). JS uses
`wp_has_consent('marketing')` (WP Consent API) with CMP-native fallbacks and listens for
`wp_listen_for_consent_change`/`cmplz_status_change` to re-stamp; PHP reads `wp_has_consent('marketing')` (or
the `wp_consent_*` cookies) at forward time.

## 4. Form adapters

All adapter callbacks run on **Path B** (§3b) — in-process on the server, holding the server secret — so the
`lead_captured` + `identify` they emit are conversion-eligible/PII calls that never touch a browser route (C1/C2).

Each adapter binds **one PHP action** (server-side = source of truth: fires on non-AJAX/JS-disabled submits,
immune to ad-blockers, canonical field map) and optionally **one JS event** (enrichment/dedup). Every adapter
normalizes the plugin's native field container and emits exactly one neutral **`track` event with
`name:"lead_captured"`** — never a booking event — plus a companion **`identify`** carrying hashed PII.

| Form plugin | Primary: PHP hook (server-side) | Field container | Enrichment: JS event | Emits |
|---|---|---|---|---|
| **Contact Form 7** | `wpcf7_mail_sent` (clean submit; avoid `wpcf7_before_send_mail` — fires even on mail failure) | `WPCF7_Submission::get_instance()->get_posted_data()`; no entry id → own UUID | `wpcf7mailsent` on `div.wpcf7`; `e.detail.inputs[]`, `e.detail.contactFormId` | `track lead_captured` + `identify` |
| **WPForms** | `wpforms_process_complete` (`$fields,$entry,$form_data,$entry_id`, `10,4`) | `$fields` keyed by numeric id; labels via `$form_data`. `$entry_id` may be `0` (Lite) → don't key dedup on it alone | `wpformsAjaxSubmitSuccess` (AJAX only) | `track lead_captured` + `identify` |
| **Gravity Forms** | `gform_after_submission` (`$entry,$form`, `10,2`) | `$entry` keyed by numeric field id (`rgar`, sub-inputs `1.3`); names from `$form['fields']` | `gform_confirmation_loaded` (AJAX only) | `track lead_captured` + `identify` |
| **Elementor Pro Forms** | `elementor_pro/forms/new_record` (`$record,$handler`, `10,2`) | `$record->get('fields')` keyed by id; form via `$record->get_form_settings()` | `submit_success` on `document` (weak — not primary) | `track lead_captured` + `identify` |
| **Fluent Forms** | `fluentform/submission_inserted` (`$entryId,$formData,$form`, `20,3`) | `$formData` keyed by input name | `fluentform_submission_success` on the form jQuery object | `track lead_captured` + `identify` |

Notes: CF7/Fluent key by human-ish input **name**; Gravity/Elementor/WPForms key by **numeric id** and need a
label-resolution step against the form definition to emit stable `email`/`phone`/`name`. Adapters bind
**globally** and filter inside the callback; per-form scoping (`*_{$form_id}`) only when a tenant restricts
capture. Dedup uses entry id where reliable, else the generated UUID, correlated with the JS event.

Normalized `lead_captured` payload (neutral): `{ type:"track", name:"lead_captured", visitor_id:"vis_…",
session_id:"ses_…", source:"wp.plugin", occurred_at, attribution:{utm/click-ids/referrer/landing},
consent:{…}, properties:{form_plugin, form_id, normalized_non_pii_fields} }`, plus a companion `identify`
carrying `email_hash`/`phone_hash`.

## 5. Consent integration

Reading the site's existing CMP — we never present one. Single-purpose gate: **forwarding an ad identifier
(gclid/gbraid/wbraid/etc.) is itself the `ad_user_data`+`ad_personalization` action, so it may only happen
when marketing/ad consent resolves to granted at capture time.**

Resolution order (PHP at forward time, mirrored in JS for enrichment):
1. **WP Consent API** (preferred; normalizes all CMPs): `wp_has_consent('marketing')` — already combines region
   `consent_type` (`optin`/`optout`/`false`) and the visitor choice, so under `optin`, `not set` = **denied**.
2. **CMP-native fallback** when WP Consent API absent: Complianz `cmplz_has_consent('marketing')`; Cookiebot
   `Cookiebot.consent.marketing`; CookieYes/Borlabs equivalents.
3. **Direct cookie fallback:** `$_COOKIE['wp_consent_marketing']` (`allow`/`deny`) + `wp_consent_type`.

Payload carries **explicit, granular** consent (not an inference): `consent:{ ad_user_data, ad_personalization,
ad_storage, analytics_storage, consent_type, region, cmp_source, timestamp }`. Carrying both Google v2
signals (not a single boolean) future-proofs the 2026 Google Ads consent changes and maps onto Data Manager.

Hard rules:
- Marketing/`ad_user_data` ≠ granted → **strip all ad identifiers** from the forwarded payload. Lead may still
  be captured (analytics/first-party), but no click ID leaves the site.
- `gclid` via `url_passthrough` is **never** treated as consent.
- The SDK **re-validates** consent on ingestion (E1 outcome-time gate) and stores the snapshot as provenance,
  so a later withdrawal can retract the offline conversion via the GAds adjustment path.

## 6. PII rule

**Canonical: PII is hashed server-side, in PHP, before the request leaves WordPress (B5).** Raw
email/phone/name from the form hook are normalized then SHA-256'd in the plugin's PHP path; only hashes go on
the wire and into the ledger. Raw PII is discarded immediately after hashing and is **never** sent client-side.

Normalization (must match the SDK's one canonical spec so hashes collide):
- **Email:** lowercase + trim; for `gmail.com`/`googlemail.com` strip dots and `+suffix`. Then `sha256(hex)`.
- **Phone:** E.164 (`+`, country code, digits only). Then `sha256(hex)`.

The forwarded `identify` carries `email_hash`/`phone_hash` (and `external_id` if available); `lead_captured`
`properties` carry only non-PII fields. This gives Enhanced-Conversions-for-Leads matching without ever
transmitting raw identifiers.

Belt-and-suspenders: even though the plugin hashes, the SDK contract **also** hashes/rejects raw at ingestion
(B5 floor), so a misconfigured adapter cannot leak raw PII into the ledger.

## 7. Build order / milestones

**Hard dependency (blocks M1):** the public capture contract (`/capture/track` + `/capture/identify`,
publishable-key + server-secret auth, consent + hashed-PII required) must land first — it is the A1 spine
this plugin targets. The **H3 Google Data Manager feedback** PR must also land before outcome-based value is
realized end-to-end (the plugin captures the lead; H3 closes the loop to Google). The plugin can reach M2
against a stubbed/sandbox contract; real conversions require both.

- **M0 — Dogfood scaffold.** Settings page (encrypted secret + publishable key, rotation), `/wp-json/apointoo/v1/capture/*`
  proxy (nonce + rate-limit + host allowlist + fail-closed + **server-set `vis_` cookie**), headless tracker
  (read server `vis_`, mint `ses_`, persist attribution, stamp consent). Install on **Vizuh-managed WP client
  sites** pointed at the SDK **sandbox** tenant (sandbox = AI safety net; no demo/UAT tier). Verify events
  reach the ledger; no form adapter yet.
- **M1 — One form adapter end-to-end.** Highest-coverage adapter for the dogfood sites (likely **CF7** or
  **Elementor**). Server hook → normalize → PHP SHA-256 → `identify` + `track lead_captured` → proxy → SDK.
  Confirm `vis_`/`ses_` linkage, dedup, a clean lead in the ledger. **Requires the contract live.**
- **M2 — Consent gate.** WP Consent API + CMP fallbacks + cookie fallback; strip ad ids when consent ≠ granted;
  attach the granular snapshot; verify SDK re-validation + provenance. Test against Complianz and Cookiebot.
- **M3 — Multi-form + distribution.** Remaining adapters (WPForms, Gravity, Fluent, + the other of
  CF7/Elementor), each behind its enable toggle and label-resolution. Harden, version the forward host, stand
  up distribution (§8). End-to-end with **H3 feedback** landed so `lead_captured` → outcome → Google round-trips.

Small, reversible steps throughout (sandbox before prod; Hugo sole stakeholder).

## 8. Distribution & auth

**Refined 2026-05-30 (Hugo): just the plugin — a free capture-and-send connector.** It captures form leads and
forwards them to the Apointoo SDK; it is *not* the product. The **paid product is the Apointoo service (SDK +
dashboard)** — the plugin carries no pricing. Everything the client configures (tenant keys, what's captured,
conversion mappings, setup instructions) lives on the **Apointoo dashboard**; the plugin's settings page is
minimal — paste the credentials the dashboard issues, toggle adapters. It stays *standalone* (not a ClickTrail
add-on); ClickTrail is a separate generic free plugin.

**Distribution — two paths, decided by wordpress.org.**
1. **Try wordpress.org (free directory).** SaaS connectors are **explicitly permitted** — Guideline 6:
   third-party services are fine *if* they provide substantive functionality and the readme links the service's
   terms of use. So the risk is not "connectors are banned"; it is meeting the connector rules. **wp.org
   pre-submission checklist** (grounded in the Plugin Directory Guidelines, fetched 2026-05-30):
   - **Functional at submission (G16).** The plugin must actually capture-and-send. *This blocks submission
     until the real transport ships (M1+) — a stubbed connector cannot be submitted.*
   - **Full GPLv2-or-later + human-readable source (G1, G4).** Switch `LICENSE`; **no proprietary/obfuscated
     JS** — this kills the split-license idea: for wp.org the JS is GPL and readable too.
   - **readme.txt links the Apointoo terms-of-use + privacy policy (G6).** Required for a service connector.
   - **Explicit opt-in before contacting the SDK (G7).** Satisfied by credential entry (service registration);
     nothing phones home until the keys are set — keep + document that.
   - **Bundle the JS tracker locally (G8).** Never load executable code from the Apointoo domain; data POSTs
     are fine; non-service JS/CSS must be local.
   - **No plugin-side paywall (G5 — "no trialware").** All plugin functionality stays free; payment lives on
     the service, not behind a plugin gate.
   - **Security review** — sanitise/escape/nonce/capability checks (the WordPress-hardening clause above).
   - **If accepted:** repo → **public + GPLv2-or-later**, updates via the wp.org SVN trunk.
   - **If rejected / not pursued:** distribute **privately by ZIP**; repo **stays private + proprietary**.
2. Either way the plugin is **free** — clients pay for the Apointoo service, not the connector. *(This
   supersedes the earlier paid-plugin / Merchant-of-Record framing.)*

**License:** **proprietary** today (private-ZIP default). Switch to **GPLv2-or-later** only if/when wp.org
accepts it (which open-sources the repo). There is **no paid licensing layer** — the plugin is free, so no
license key gates updates.

**Update mechanism:** if on wp.org, the directory's auto-updates. If private ZIP, either hand the ZIP or a
version JSON + ZIP from a Vizuh update server via **Plugin Update Checker** (YahnisElsts). No payment gate.

**Credentials (issued by the dashboard, not invented in the plugin):**
- **Publishable site key** — client-side; resolves `tenantId` for the browser tracker; grants nothing alone.
- **Server secret (tenant API token)** — authenticates server-to-server SDK calls (`Authorization: Bearer`,
  SHA-256-hashed at the SDK per ADR-019/ADR-021). Server-side only.

**Auth posture to the SDK:** the server-side path authenticates **as the tenant** via the per-tenant revocable
API token; the publishable key is browser-safe and grants nothing alone. The SDK never trusts a key sent from
the browser (C1). Tokens are per-tenant and revocable, so a leaked at-rest secret is cheaply rotated. Tenant
offboarding = revoke the token in the dashboard.

**WordPress hardening — "protect against attackers / injections best we can" (Hugo).** The plugin treats the
WordPress surface as hostile: every input sanitised, every output escaped; the visitor `/wp-json` route is
nonce + per-IP rate-limited and **never carries the secret** (C1); conversion + PII calls are server-side only
(C2); the settings screen is `manage_options`-gated with nonces; ABSPATH guard on every file; no `eval` /
dynamic includes; prepared statements for any DB access; PII hashed in PHP and discarded (B5). The full threat
model and the open hardening items live in `capture-security-review.md` and repo issue #6.
