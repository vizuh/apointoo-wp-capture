# Multi-agent verified audit — apointoo-wp-capture (2026-07-22)

**Method:** ultracode multi-agent audit with WP-specific security lenses (escaping, sanitization, nonces, capability checks, `$wpdb->prepare`, ABSPATH, secret leakage) since this is a **public wp.org plugin**, plus correctness / wp.org-compliance / tests. Adversarial verification on every candidate finding; a completeness critic then re-read the full security surface for blind spots.

**Verdict:** the WP security **primitives are genuinely clean** — 0 confirmed vulnerabilities. The real exposure is a **security surface that is untested and CI-unenforced**, plus one lens (client-side consent) the finder didn't examine. Nothing here changed by this audit.

---

## Clean — verified adequate (left alone)
- **ABSPATH / direct-access guard on every PHP file**; `WP_UNINSTALL_PLUGIN` guard in `uninstall.php`.
- **No SQL anywhere** (no `$wpdb`, no raw queries) → the `$wpdb->prepare` lens is N/A.
- **No custom REST or AJAX routes** (`register_rest_route` / `wp_ajax_*` absent) → capture piggybacks each host form's own submit hook + nonce. Both real write surfaces are gated: Settings API via `options.php` (`register_setting` + nonce); `admin-post apointoo_send_test` with `check_admin_referer` + `current_user_can` (`class-settings.php:425-428`).
- No `eval`/`create_function`/`unserialize` of untrusted input; `tracker.js` uses `input.value=` / `a.href=` (URL-encoded), no `innerHTML`/`document.write`. Admin output consistently escaped. Cookies `SameSite=Lax` + `Secure` on https. Forward log stores booleans (`have_email`/`have_phone`), never raw PII or the site key.

## Gaps (critic-surfaced — verify before acting)

### G1 — untested, CI-unenforced security surface (strongest gap)
- CI (`.github/workflows/*.yml`) runs **lint only** (`phpcs`, `phpcompat`) — it never runs `composer run test`.
- The two files in `tests/` are **standalone `assert()` scripts, not PHPUnit** (`composer run test` → phpunit finds no TestCase), so they only run if someone manually types `php tests/…`.
- Zero automated coverage on the matching/security-critical paths: **`includes/capture/class-pii-hasher.php`** (email/phone normalization — its docblock says it "MUST stay byte-identical to the SDK's canonical spec so hashes collide"; this is the enhanced-conversions **money/matching** path), the 7 form-adapters' field classification (which field → email/phone/message), and `Attribution::from_cookie()` sanitization (macro rejection, 256-char cap, `is_scalar` guard, `class-attribution.php:217-256`).
- **Fix direction:** wire `composer run test` into CI; convert the assert scripts to real PHPUnit `TestCase`s; add a golden-vector test for `class-pii-hasher` against the SDK's canonical spec (a hash drift silently breaks Google matching).

### G2 — client-side consent (tracker.js `Consent`, ~L762-865) not examined
The **DMA-critical** gate: server-side `class-consent.php` only decides *forwarding*; the JS decides whether ad-IDs are *persisted at all*. It trusts CMP globals in priority order (Consent Mode dataLayer scan → Cookiebot → OneTrust `C0004` → Complianz → `wp_consent_marketing`) with two-phase buffer/promote + a denial-clears path. Subtle, untested. Deserves a dedicated review + tests.

### G3 — readme.txt drift (wp.org reviewer-facing)
`readme.txt` Installation is inaccurate vs shipped UI: step 2 references entering a "**server secret**" but that field was **removed** (`class-settings.php:222`); step 3 references an "adapter-enable UI" that doesn't exist (adapters auto-activate). Also verify `Tested up to: 7.0` is a released WP version at submission time (plugin-check flags tested-up-to beyond latest release).

### G4 — open question: is `X-Apointoo-Tenant-Key` a bearer secret? (doc-vs-code, low)
`class-settings.php:22` docblock claims the credential is "write-only in the UI — never echoed back," but `field_site_key()` (L214-217) **does** echo it (`esc_attr`). Standard for a `manage_options`-only publishable key → **not a hole**, but the key authorizes lead writes to the tenant. **Rule on it:** if it's a bearer secret, stop echoing it; if it's publishable, fix the stale docblock.

### G5 — intake host allowlist is single-layer (defense-in-depth, low)
The `dash.apointoo.com` host restriction is enforced only in `Settings::sanitize()` (L293-301). `Abstract_Form_Adapter::intake_send()` (L173) reads `sdk_url` straight from the option and POSTs the tenant-key header to whatever is stored, with no send-time re-check. `wp_safe_remote_post` blocks private-IP SSRF but not arbitrary public hosts. Only exploitable via a sanitize-bypass write (wp-cli, migration, another plugin). Add a re-check at send time.

_Downgraded (examined, not a vuln): the cross-domain decoration `getRegistrableDomain` correctly distinguishes `owner.com.br` vs `evil.com.br` (special-cases known SLDs) — no click-ID leak; residual risk is a hand-rolled public-suffix list causing false-negatives (attribution silently not carried), a maintenance smell._

## Roadmap
- **Wave A:** G1 — wire tests into CI + a PII-hasher golden-vector test (silent matching breakage is the real risk on a public plugin). Rule on G4.
- **Wave B:** G2 — review + test the tracker.js consent gate (DMA). G3 — fix readme drift before the next wp.org submission.
- **Wave C:** G5 send-time host re-check; public-suffix-list robustness.

_Companion docs: `apointoo-dashboard/docs/audit-2026-07-22-multiagent-review.md`, `apointoo-rwg/docs/…`._

## Remediation status — 2026-07-23

- G1 partially closed: the existing PHP contracts and real-tracker JS check now run in CI; adapter extraction
  and the legacy, currently unused PII hasher remain outside this release gate.
- G2 closed: mixed Consent Mode states are deny-first and covered by the real-tracker check.
- G3 closed: installation now matches the tenant-key + intake-URL UI and automatic adapter discovery.
- G4 closed: the settings contract now describes the tenant-scoped intake key truthfully.
- G5 closed: both live form forwarding and the admin test revalidate HTTPS + `dash.apointoo.com` immediately
  before sending the tenant key.
