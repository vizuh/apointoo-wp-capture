# Apointoo Capture (WordPress)

Connects a WordPress site's **existing** forms to the Apointoo capture contract, so leads and their
attribution flow into the Apointoo SDK — server-side, consent-aware, with PII hashed before it leaves the site.

> **Status: skeleton.** No functional code yet — by design. This plugin is *downstream* of the public
> capture contract, which does not exist yet. See [Build order](#build-order).

## The hard boundary

**This is a capture + wiring adapter, NOT a form product.** It adapts to forms that already exist on the
site (Contact Form 7, WPForms, Gravity, Elementor, Fluent); it never creates, renders, styles, validates, or
manages a form, a booking, or any submission UI.

Permanent non-goals: no form builder · no booking UI / calendar / slot picker · no visitor-facing
shortcodes/blocks/widgets · no CRM / lead inbox / entry storage · no pixel suite / GTM replacement ·
no consent banner (we *read* the site's CMP, never present one) · no business logic on the lead.

If a feature would make the plugin usable *without* an existing form, it is out of scope.

## What it does (once built)

1. **Capture** — a headless JS tracker mints `vis_`/`ses_` ids and persists attribution (UTM + click ids +
   referrer); the visitor cookie is **server-set** via a same-domain `/wp-json` proxy (survives Safari ITP).
2. **Wire** — per-form-plugin adapters read each form's server-side submit hook and emit one neutral
   `track lead_captured` + a companion `identify`.
3. **Gate** — reads Consent Mode v2 (WP Consent API / Complianz / Cookiebot / CookieYes), strips ad
   identifiers unless marketing consent is granted.
4. **Hash** — normalizes + SHA-256s email/phone in PHP before forwarding; raw PII never leaves the site.
5. **Forward** — the `/wp-json` proxy injects the tenant server secret server-side and posts to the SDK's
   `POST /capture/track` + `/capture/identify`.

## Build order

This plugin targets the Apointoo **public capture contract**, which must land first:

```
apointoo-sdk: ledger PR (in flight)
  └─> public capture contract  +  ADR-021 (capture API tokens)   ← designed, not yet built
        └─> H3 Google Data Manager feedback (blocked on Google access)
              └─> THIS PLUGIN:  M0 dogfood → M1 one adapter → M2 consent → M3 multi-form + distribute
```

Design source of truth (in `vizuh/apointoo-sdk`):
- Contract — `_references/public-capture-contract-proposal.md`
- Auth — `docs/decisions/adr-021-capture-api-tokens.md`
- This plugin's full plan — [`docs/PLAN.md`](docs/PLAN.md)

## Distribution

Self-hosted, **not** wordpress.org (proprietary, agency-distributed, calls a paid first-party SDK). Updates
via a Vizuh-controlled update server, gated behind a license key. Two distinct credentials, kept separate:
a **license key** (gates updates) and a **server secret** (tenant API token, authenticates SDK calls).

## Security & privacy

- **Threat model** (design review): `apointoo-sdk` design family → `_references/capture-security-review.md`.
  The load-bearing rule: **the publishable key is browser-extractable — assume the publishable path is fully
  hostile.** Two separate forward paths (§3b of the plan): a visitor proxy that carries only the *publishable*
  key + telemetry, and a server-side form hook that holds the *secret* and originates all PII/conversion calls.
  The secret never sits behind a browser route (C1); conversion-eligible events require the secret (C2).
- **DPIA:** [`docs/dpia.md`](docs/dpia.md). Dominant gaps: real **erasure** (crypto-shred, not just a
  tombstone) and PII scrubbing of free-form fields **must land before any real (non-sandbox) PII flows.**

## Open items

- Final license terms (proprietary vs GPL-compatible) — placeholder `LICENSE` is proprietary for now.
- Update-server / licensing mechanism (Plugin Update Checker vs custom).
- Build the deferred security mitigations before M1 real-PII: PII scrub on `properties`/`traits` (I2),
  erasure/crypto-shred path (I3), secret-leak anomaly detection (I4).

---
© Vizuh OÜ. Proprietary — see [`LICENSE`](LICENSE).
