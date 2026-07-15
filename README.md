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
- Historical design and current-contract corrections — [`docs/PLAN.md`](docs/PLAN.md)

## Distribution

**A free, GPLv2-or-later capture-and-send connector** (refined 2026-05-30). The plugin is free; the paid
product is the Apointoo **service** (SDK + dashboard). We're pursuing the **wordpress.org directory** (license
flipped to GPL to request access + an SVN repo):

- **wp.org accepts it** → also published on GitHub; updates via the directory.
- **wp.org rejects / not pursued** → distribute by **ZIP** to clients (still GPL).

Clients configure it with credentials issued by the **dashboard** (a publishable site key + a server secret);
there is no paid license key. Full model, hardening, and the grounded pre-submission checklist:
`docs/PLAN.md` §8 and [`docs/WP-ORG-SUBMISSION.md`](docs/WP-ORG-SUBMISSION.md).

## Development

Conventions mirror `vizuh/click-trail-handler` (the house WordPress plugin): namespace `Apointoo\Capture`
(PSR-4 → `includes/`), classic WPCS file names (`class-*.php` / `interface-*.php`), a runtime autoloader, and
prefixes `apointoo_capture_` / `APOINTOO_CAPTURE_`.

```bash
composer install        # dev tooling (phpcs, WPCS, phpcompatibility, phpunit)
composer run phpcs      # WordPress Coding Standards (currently clean)
composer run phpcbf      # auto-fix what phpcs can
composer run phpcompat  # PHP 8.0+ compatibility
```

CI runs phpcs + PHP-compatibility on every push/PR (`.github/workflows/`). The repo currently passes both.
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
- **DPIA:** [`docs/dpia.md`](docs/dpia.md). Dominant gaps: real **erasure** (crypto-shred, not just a
  tombstone) and PII scrubbing of free-form fields **must land before any real (non-sandbox) PII flows.**

## Open items

- Final license terms (proprietary vs GPL-compatible) — placeholder `LICENSE` is proprietary for now.
- Update-server / licensing mechanism (Plugin Update Checker vs custom).
- Build the deferred security mitigations before M1 real-PII: PII scrub on `properties`/`traits` (I2),
  erasure/crypto-shred path (I3), secret-leak anomaly detection (I4).

---
© Vizuh OÜ. Proprietary — see [`LICENSE`](LICENSE).
