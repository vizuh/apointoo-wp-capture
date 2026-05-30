# WordPress.org submission — pre-flight checklist

Grounded in the **Detailed Plugin Guidelines**, **Plugin Developer FAQ**, and **Security handbook**
(reviewed 2026-05-30). Status legend: ✅ done · ⚠ do-before-submit · ⛔ hard blocker (auto-rejection).

## Verdict: NOT submittable yet — two ⛔ blockers

- ⛔ **Placeholders / not production-ready.** The SDK transport is a no-op stub, 4 of 5 form adapters are stubs,
  and the `/wp-json` proxy + settings screen aren't built. FAQ, verbatim: *"We do not accept placeholders or
  plugins that aren't ready to be used… production-ready: complete, without errors, without unnecessary logs,
  without development tools."* → Build the real capture-and-send path first. **This needs the SDK capture
  contract (plugin milestone M1).**
- ⛔ **Sandbox-only = trialware (G5).** *"Plugins that provide sandbox only access to APIs and services are…
  trial… and not permitted."* It must work against **production** Apointoo tenants, not just the sandbox.

**So wp.org submission is downstream of M1.** Everything below is the checklist for when the code is real.
Submitting now burns a 5–14 day review cycle on a guaranteed rejection.

## Naming & ownership — actionable NOW (independent of code)

- ✅ **Account: wp.org username `apointoo`, email `support@apointoo.com`.** The `@apointoo.com` domain matches
  the plugin brand exactly, proving ownership of "Apointoo" (G17) and avoiding the gmail
  trademark-infringement flag. `Contributors: apointoo` + the `Author: Apointoo` header are aligned to this.
  *(Verify the wp.org account is actually registered as `apointoo` with that email before submitting.)*
- ✅ **Brand-first name, not generic.** "Apointoo Capture" → slug `apointoo-capture` (derived from the
  `Plugin Name` header; **permanent once approved**; changeable once *before* review). Note: the wp.org slug is
  `apointoo-capture`, not the repo name `apointoo-wp-capture`.
- ✅ **Text domain matches the slug** (`apointoo-capture`).

## License & openness

- ⚠ **Switch `LICENSE` → GPLv2-or-later (G1).** Everything shipped (PHP, JS, CSS, images) must be
  GPL-compatible. This kills the split/proprietary-JS idea for the wp.org build.
- ✅ **Human-readable (G4).** phpcs-clean, no obfuscation. If the tracker JS is minified, ship the source or
  link it (FAQ allows minified *only* with source available).
- ✅ Repo goes **public** if accepted.

## Code & security — the top-3 rejection reasons

FAQ: the three most common rejections are **unescaped output, unsanitised input, form data without a nonce.**

- ⚠ **Settings screen (not built):** `current_user_can('manage_options')` + nonce on save + sanitise every
  field + escape every output.
- ⚠ **`/wp-json` proxy (not built):** nonce + `permission_callback` + sanitise body + escape; never carries the
  secret (C1); conversion/PII server-side only (C2).
- ✅ **Form adapters / PII:** input sanitised, PII hashed in PHP (B5). CF7 done; others stubbed.
- ⚠ **Escape late, on output** — audit every admin/display string.
- ✅ **No remote code (G8):** tracker JS bundled locally; only data POSTed to the SDK; no third-party CDN (fonts
  excepted); no iframes for admin pages.
- ✅ **No bundled core libs (G13).**

## SaaS-connector compliance (G6 — what makes us acceptable)

- ✅ **The Apointoo service provides substantive functionality** (ledger, offline-conversion upload,
  identity/outcome resolution) — not a fake license-check service, not a storefront, not "code moved out to
  fake a service." On the right side of G6.
- ⚠ **readme.txt must document the service + link its Terms of Use + privacy policy (G6/G7).** Add the Apointoo
  ToU + privacy URLs.
- ✅ **Consent to "phone home" (G7)** is granted by configuring the plugin (entering credentials = registration);
  nothing fires until configured — keep that, and document the data flow in the readme.
- ✅ **Not trialware (G5):** the plugin is fully free + functional; payment lives on the service.
- ✅ **Upsell discipline (G10/G11):** any "get Apointoo" prompt is opt-in, dismissible, contextual — no admin
  hijacking, no forced front-end credits.

## readme.txt

- ⚠ **Stable tag** = the released version (never "trunk").
- ⚠ **Tested up to** = a real current WP version (not a future one) — update from `6.7` at submission.
- ✅ **Requires PHP / Requires at least** present (8.0 / 6.4).
- ⚠ **License** → GPLv2-or-later (currently Proprietary).
- ⚠ **Service + ToU/privacy disclosure** (G6/G7).
- ✅ **≤12 tags** (5 shown).
- ⚠ **Changelog** (Keep-a-Changelog style; current + one major back).

## Packaging (FAQ / SVN)

- ✅ **`.distignore` excludes dev cruft** (vendor/, tests/, docs/, .github/, composer/phpcs) → keeps the ZIP
  <10 MB and free of development tools.
- ⚠ **Installable via "Upload Plugin"** — the root plugin file + readme.txt go in **trunk/ root** (not a
  subdirectory of trunk).
- ✅ **Increment version each release (G15).**

## Process

- Initial response ≤14 days; iterate by email; auto-rejected after 3 months of inactivity (re-submittable).
- **One plugin in review at a time.**
- Slug permanent once approved (changeable once before review).
- Whitelist `plugins@wordpress.org`; keep the account email human-monitored (no autoresponders).

---

**Bottom line:** the *strategy and structure are compliant* — a SaaS connector is explicitly allowed (G6). The
gate is **build the real, production-ready capture-and-send path (M1)**, then **submit from a Vizuh email**, as
**GPLv2-or-later**, with the **Apointoo ToU/privacy disclosed** in the readme. Do not submit a stub — it is an
automatic rejection.
