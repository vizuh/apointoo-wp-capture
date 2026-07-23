# Apointoo Capture (WordPress)

Connects a WordPress site's **existing** forms to the Apointoo capture contract, so leads and their
attribution flow into the Apointoo dashboard intake — server-side and consent-aware.

> **Current connected path:** supported form adapters POST the raw contact fields required by the dashboard
> to its tenant-key-protected contact intake endpoint over TLS. The dashboard is the system of record and the
> single owner of Google Ads normalization, SHA-256 matching identifiers, conversion deduplication, and upload.

## The hard boundary

**This is a capture + wiring adapter, NOT a form product.** It adapts to forms that already exist on the
site (Contact Form 7, WPForms, Gravity, Elementor, Fluent); it never creates, renders, styles, validates, or
manages a form, a booking, or any submission UI.

Permanent non-goals: no form builder · no booking UI / calendar / slot picker · no visitor-facing
shortcodes/blocks/widgets · no CRM / lead inbox / entry storage · no pixel suite / GTM replacement ·
no consent banner (we *read* the site's CMP, never present one) · no business logic on the lead.

If a feature would make the plugin usable *without* an existing form, it is out of scope.

## What it does

1. **Capture** — a headless JS tracker mints `vis_`/`ses_` ids and persists attribution (UTM + click ids +
   referrer); the visitor cookie is **server-set** via a same-domain `/wp-json` proxy (survives Safari ITP).
2. **Wire** — per-form-plugin adapters read each form's server-side submit hook and forward one contact to
   the configured dashboard intake endpoint.
3. **Gate** — reads Consent Mode v2 (WP Consent API / Complianz / Cookiebot / CookieYes), strips ad
   identifiers unless marketing consent is granted, and sends the dashboard's canonical consent vector.
4. **Forward** — sends contact fields plus attribution server-to-server with `X-Apointoo-Tenant-Key`.
5. **Convert** — the dashboard chooses one Google click ID (`gclid` → `gbraid` → `wbraid`) and hashes only
   eligible email/E.164 phone identifiers when tenant policy and consent allow it.

## Build order

The live integration targets the dashboard **contact intake contract**:

```
WordPress form hook
  └─> dashboard contact intake (tenant key)
        └─> conversion ledger + Google Data Manager worker
```

Contract references:
- SDK attribution shape — `vizuh/apointoo-sdk/src/core/schemas.ts`
- Dashboard intake and Google upload behavior — `vizuh/apointoo-dashboard`

## Distribution

**A free, GPLv2-or-later capture-and-send connector.** The plugin is free; the paid product is the Apointoo
**service** (SDK + dashboard). Releases are developed on GitHub and published through the WordPress.org
Subversion repository.

Clients configure it with a tenant key and HTTPS intake URL issued by the **dashboard**; there is no paid
license key. Release mechanics: [`docs/RELEASING.md`](docs/RELEASING.md).

## Development

Conventions mirror `vizuh/click-trail-handler` (the house WordPress plugin): namespace `Apointoo\Capture`
(PSR-4 → `includes/`), classic WPCS file names (`class-*.php` / `interface-*.php`), a runtime autoloader, and
prefixes `apointoo_capture_` / `APOINTOO_CAPTURE_`.

```bash
composer install        # dev tooling (phpcs, WPCS, PHPCompatibilityWP)
composer run phpcs      # WordPress Coding Standards (currently clean)
composer run phpcbf      # auto-fix what phpcs can
composer run phpcompat  # PHP 8.0+ compatibility
composer run test       # standalone PHP + real-tracker contract checks
```

CI runs PHPCS, PHP compatibility, and contract checks on every push/PR (`.github/workflows/`).
No PHP runtime is required to develop the design — but the checks need PHP/Composer (or run them in a
`docker.io/library/composer:2` container, as CI does).

## Security & privacy

- Raw contact data is sent only from the server-side form hook to the configured HTTPS dashboard intake;
  it is never placed in browser telemetry or attribution cookies.
- Marketing consent is not inferred from the presence of a click ID. When denied or unavailable, the plugin
  strips ad IDs before forwarding and the dashboard enforces its tenant consent regime again.
- `marketingConsent` (email-list opt-in) is a separate form decision and is never inferred from CMP ad consent.
- Customer Match audiences are not created by this plugin or by conversion upload; they require a separate
  tenant-facing purpose, consent, eligibility, and deletion workflow.

---
© Vizuh OÜ. GPLv2-or-later — see [`LICENSE`](LICENSE).
